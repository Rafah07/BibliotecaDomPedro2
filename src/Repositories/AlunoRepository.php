<?php

namespace Biblioteca\Repositories;

use Biblioteca\Services\AlunoImportService;
use Biblioteca\Support\Texto;
use PDO;

/**
 * Acesso à tabela alunos usado pela importação e pelo módulo Alunos do painel
 * (listagem, detalhes, cadastro e correção individual).
 * Alunos não têm conta, login nem senha: aqui só existe o cadastro usado nos empréstimos.
 * Nenhum aluno é excluído: a importação inclui ou atualiza campos de cadastro, e o painel
 * corrige um cadastro ou o inativa. O id interno nunca muda, então os empréstimos continuam
 * ligados ao mesmo aluno depois de qualquer correção.
 */
final class AlunoRepository
{
    private const COLUNAS = 'id, nome, matricula, turma, turno, email, ativo';
    private const LOTE_CONSULTA = 500;

    /** Ordenações aceitas na listagem (campo da tela => expressão SQL fixa). */
    public const ORDENS = ['nome' => 'a.nome', 'matricula' => 'a.matricula', 'turma' => 'a.turma', 'cadastro' => 'a.criado_em'];
    public const SITUACOES = ['ativos', 'inativos', 'todos'];
    public const CIRCULACAO = ['com_emprestimo', 'com_atraso', 'sem_emprestimo'];

    public function __construct(private PDO $pdo)
    {
    }

    public function total(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn();
    }

    /**
     * Cadastros com as matrículas informadas.
     * @param string[] $matriculas
     * @param bool $bloquear trava as linhas até o fim da transação (usado na gravação)
     * @return array<string, array> AlunoImportService::chaveMatricula => cadastro
     */
    public function porMatriculas(array $matriculas, bool $bloquear = false): array
    {
        $resultado = [];
        foreach ($this->consultarEmLotes('matricula', $matriculas, $bloquear) as $linha) {
            $resultado[AlunoImportService::chaveMatricula($linha['matricula'])] = $linha;
        }
        return $resultado;
    }

    /**
     * Cadastros com os mesmos nomes (a collation do banco ignora maiúsculas e acentos).
     * @return array<string, list<array>> Texto::chave(nome) => cadastros
     */
    public function porNomes(array $nomes): array
    {
        $resultado = [];
        foreach ($this->consultarEmLotes('nome', $nomes) as $linha) {
            $resultado[Texto::chave($linha['nome'])][] = $linha;
        }
        return $resultado;
    }

    /** @return array<string, list<array>> e-mail em minúsculas => cadastros */
    public function porEmails(array $emails): array
    {
        $resultado = [];
        foreach ($this->consultarEmLotes('email', $emails) as $linha) {
            $resultado[mb_strtolower((string)$linha['email'])][] = $linha;
        }
        return $resultado;
    }

    /**
     * Listagem paginada no servidor (nunca carrega todos os alunos).
     * Filtros: q (nome, matrícula ou e-mail; a collation do banco ignora acentos e maiúsculas),
     * turma, turno, situacao (ativos|inativos|todos), circulacao (com_emprestimo|com_atraso|sem_emprestimo),
     * ordem (ORDENS) e dir (asc|desc). $hoje (Y-m-d) define o que está atrasado.
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $f, string $hoje, int $pagina = 1, int $porPagina = 25): array
    {
        [$where, $params] = $this->filtros($f, $hoje);
        $porPagina = max(1, min(100, $porPagina));
        $total = (int)$this->valor("SELECT COUNT(*) FROM alunos a WHERE $where", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $ordem = self::ORDENS[$f['ordem'] ?? 'nome'] ?? 'a.nome';
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $itens = $this->todas("SELECT a.id, a.nome, a.matricula, a.turma, a.turno, a.email, a.ativo, a.criado_em, a.atualizado_em
                               FROM alunos a WHERE $where
                               ORDER BY $ordem IS NULL, $ordem $dir, a.nome, a.id
                               LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        // Empréstimos em aberto e atrasados só das linhas da página (consulta pelo índice aluno_id).
        $ids = array_map(fn ($a) => (int)$a['id'], $itens);
        $contagens = [];
        if ($ids) {
            $marcadores = implode(', ', array_fill(0, count($ids), '?'));
            foreach ($this->todas("SELECT aluno_id, COUNT(*) AS abertos, COALESCE(SUM(data_prevista < ?), 0) AS atrasados
                                   FROM emprestimos WHERE data_devolucao IS NULL AND aluno_id IN ($marcadores) GROUP BY aluno_id", array_merge([$hoje], $ids)) as $c) {
                $contagens[(int)$c['aluno_id']] = $c;
            }
        }
        foreach ($itens as &$a) {
            $a['id'] = (int)$a['id'];
            $a['abertos'] = (int)($contagens[$a['id']]['abertos'] ?? 0);
            $a['atrasados'] = (int)($contagens[$a['id']]['atrasados'] ?? 0);
        }
        unset($a);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** @return array{0: string, 1: list<mixed>} condição SQL e parâmetros */
    private function filtros(array $f, string $hoje): array
    {
        $where = ['1 = 1'];
        $params = [];
        $termos = array_slice(array_values(array_filter(preg_split('/\s+/u', trim((string)($f['q'] ?? ''))) ?: [], fn ($t) => $t !== '' && mb_strlen($t) <= 100)), 0, 6);
        foreach ($termos as $termo) {
            $like = '%' . addcslashes($termo, '%_\\') . '%';
            $where[] = '(a.nome LIKE ? OR a.matricula LIKE ? OR a.email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (($f['turma'] ?? '') !== '') {
            if ($f['turma'] === '-') {
                $where[] = "(a.turma IS NULL OR a.turma = '')";
            } else {
                $where[] = 'a.turma = ?';
                $params[] = (string)$f['turma'];
            }
        }
        if (isset(AlunoImportService::TURNOS[$f['turno'] ?? ''])) {
            $where[] = 'a.turno = ?';
            $params[] = $f['turno'];
        }
        $situacao = $f['situacao'] ?? 'ativos';
        if ($situacao === 'ativos') {
            $where[] = 'a.ativo = 1';
        } elseif ($situacao === 'inativos') {
            $where[] = 'a.ativo = 0';
        }
        switch ($f['circulacao'] ?? '') {
            case 'com_emprestimo':
                $where[] = 'EXISTS (SELECT 1 FROM emprestimos m WHERE m.aluno_id = a.id AND m.data_devolucao IS NULL)';
                break;
            case 'com_atraso':
                $where[] = 'EXISTS (SELECT 1 FROM emprestimos m WHERE m.aluno_id = a.id AND m.data_devolucao IS NULL AND m.data_prevista < ?)';
                $params[] = $hoje;
                break;
            case 'sem_emprestimo':
                $where[] = 'NOT EXISTS (SELECT 1 FROM emprestimos m WHERE m.aluno_id = a.id AND m.data_devolucao IS NULL)';
                break;
        }
        return [implode(' AND ', $where), $params];
    }

    /** Turmas existentes nos cadastros (para o filtro), em ordem. @return list<string> */
    public function turmas(): array
    {
        return array_map('strval', $this->pdo->query("SELECT DISTINCT turma FROM alunos WHERE turma IS NOT NULL AND turma <> '' ORDER BY turma")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Totais do topo da página Alunos. */
    public function resumo(string $hoje): array
    {
        $r = $this->pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(ativo = 1), 0) AS ativos, COALESCE(SUM(ativo = 0), 0) AS inativos FROM alunos')->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT aluno_id) AS com_emprestimo, COUNT(DISTINCT CASE WHEN data_prevista < ? THEN aluno_id END) AS com_atraso
                                     FROM emprestimos WHERE data_devolucao IS NULL');
        $stmt->execute([$hoje]);
        $r += $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map('intval', $r + ['total' => 0, 'ativos' => 0, 'inativos' => 0, 'com_emprestimo' => 0, 'com_atraso' => 0]);
    }

    /** Cadastro completo de um aluno (sem telefone nem observações, que o painel não usa), ou null. */
    public function buscar(int $id, bool $bloquear = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, matricula, turma, turno, email, ativo, criado_em, atualizado_em FROM alunos WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            return null;
        }
        $a['id'] = (int)$a['id'];
        return $a;
    }

    /** Outro aluno (diferente de $exceto) com esta matrícula; a comparação ignora maiúsculas. */
    public function outroComMatricula(string $matricula, ?int $exceto = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM alunos WHERE matricula = ? AND id <> ? LIMIT 1');
        $stmt->execute([$matricula, $exceto ?? 0]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Outros alunos com o mesmo nome (sem diferenciar acentos e maiúsculas) ou o mesmo e-mail. @return list<array> */
    public function possiveisDuplicidades(?string $nome, ?string $email, ?int $exceto = null): array
    {
        $ou = [];
        $params = [$exceto ?? 0];
        if ($nome !== null && $nome !== '') {
            $ou[] = 'nome = ?';
            $params[] = $nome;
        }
        if ($email !== null && $email !== '') {
            $ou[] = 'email = ?';
            $params[] = $email;
        }
        if (!$ou) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM alunos WHERE id <> ? AND (' . implode(' OR ', $ou) . ') ORDER BY nome LIMIT 10');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Grava a correção de um cadastro (o id nunca muda). Campos fora da lista são ignorados. */
    public function corrigir(int $id, array $dados): void
    {
        $permitidos = ['nome', 'matricula', 'turma', 'turno', 'email'];
        $sets = [];
        $valores = [];
        foreach ($permitidos as $campo) {
            if (array_key_exists($campo, $dados)) {
                $sets[] = "$campo = ?";
                $valores[] = $dados[$campo];
            }
        }
        if (!$sets) {
            return;
        }
        $valores[] = $id;
        $this->pdo->prepare('UPDATE alunos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($valores);
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE alunos SET ativo = ? WHERE id = ?')->execute([$ativo ? 1 : 0, $id]);
    }

    /**
     * Alterações registradas deste aluno no painel (quem, quando, o quê), mais recentes primeiro.
     * Os detalhes guardam só os nomes dos campos alterados (e a matrícula anterior, para rastrear a identidade).
     */
    public function alteracoes(int $id, int $limite = 50): array
    {
        $stmt = $this->pdo->prepare("SELECT a.acao, a.detalhes, a.criado_em, u.nome AS responsavel
                                     FROM acoes_administrativas a LEFT JOIN administradores u ON u.id = a.usuario_id
                                     WHERE a.entidade = 'aluno' AND a.entidade_id = ? ORDER BY a.criado_em DESC, a.id DESC LIMIT " . max(1, min(200, $limite)));
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Inclui um aluno (sem conta de acesso). Devolve o id. */
    public function incluir(array $dados): int
    {
        $this->pdo->prepare('INSERT INTO alunos (nome, matricula, turma, turno, email) VALUES (?, ?, ?, ?, ?)')
            ->execute([$dados['nome'], $dados['matricula'], $dados['turma'], $dados['turno'], $dados['email']]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atualiza só os campos informados (nome, turma, turno, email). A matrícula nunca muda.
     * @param array<string, array{de: ?string, para: string}> $alteracoes
     */
    public function atualizar(int $id, array $alteracoes): void
    {
        $permitidos = ['nome', 'turma', 'turno', 'email'];
        $sets = [];
        $valores = [];
        foreach ($alteracoes as $campo => $mudanca) {
            if (in_array($campo, $permitidos, true)) {
                $sets[] = "$campo = ?";
                $valores[] = $mudanca['para'];
            }
        }
        if (!$sets) {
            return;
        }
        $valores[] = $id;
        $this->pdo->prepare('UPDATE alunos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($valores);
    }

    private function valor(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function todas(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array> */
    private function consultarEmLotes(string $coluna, array $valores, bool $bloquear = false): array
    {
        $valores = array_values(array_unique(array_filter($valores, fn ($v) => is_string($v) && $v !== '')));
        $linhas = [];
        foreach (array_chunk($valores, self::LOTE_CONSULTA) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));
            // $coluna vem sempre do próprio código (matricula, nome ou email); os valores vão como parâmetros.
            $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . " FROM alunos WHERE $coluna IN ($marcadores)" . ($bloquear ? ' FOR UPDATE' : ''));
            $stmt->execute($lote);
            array_push($linhas, ...$stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $linhas;
    }
}
