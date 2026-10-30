<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\Isbn;
use PDO;

/**
 * Consultas da circulação (somente leitura): listagem paginada dos empréstimos, pesquisa de
 * alunos e de exemplares para um novo empréstimo e históricos por aluno e por exemplar.
 * As gravações ficam em Biblioteca\Services\EmprestimoService.
 *
 * "Hoje" é sempre recebido como parâmetro (data da escola, RegrasCirculacao::hoje()), para que
 * o atraso não dependa do fuso do servidor MySQL.
 */
final class EmprestimoRepository
{
    public const SITUACOES = ['ativos', 'atrasados', 'devolvidos', 'todos'];
    public const ORDENS = ['retirada', 'prevista', 'devolucao', 'aluno'];

    private const COLUNAS = 'm.id, m.exemplar_id, m.aluno_id, m.data_emprestimo, m.data_prevista, m.data_devolucao, m.renovacoes, m.observacoes,
                             e.codigo_individual, e.situacao AS situacao_exemplar, l.id AS livro_id, l.titulo,
                             a.nome AS aluno_nome, a.matricula, a.email, a.turma, a.ativo AS aluno_ativo,
                             ep.nome AS emprestado_por_nome, rp.nome AS recebido_por_nome,
                             GREATEST(0, DATEDIFF(COALESCE(DATE(m.data_devolucao), ?), m.data_prevista)) AS dias_atraso';

    private const JUNCOES = 'FROM emprestimos m
                             JOIN exemplares e ON e.id = m.exemplar_id
                             JOIN livros l ON l.id = e.livro_id
                             JOIN alunos a ON a.id = m.aluno_id';

    public function __construct(private PDO $pdo)
    {
    }

    // ---------------------------------------------------------------
    // Listagem
    // ---------------------------------------------------------------

    /**
     * Filtros: q (aluno, matrícula, e-mail, título ou código do exemplar), situacao (ativos|atrasados|
     * devolvidos|todos), de/ate (data da retirada, Y-m-d), aluno (id), exemplar (id), ordem, dir.
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $f, string $hoje, int $pagina = 1, int $porPagina = 25): array
    {
        [$where, $params] = $this->filtros($f, $hoje);
        $porPagina = max(1, min(100, $porPagina));
        $total = (int)$this->valor('SELECT COUNT(*) ' . self::JUNCOES . " WHERE $where", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));

        $situacao = $f['situacao'] ?? 'ativos';
        $padrao = match ($situacao) {
            'ativos', 'atrasados' => 'prevista',
            'devolvidos' => 'devolucao',
            default => 'retirada',
        };
        $ordem = in_array($f['ordem'] ?? '', self::ORDENS, true) ? $f['ordem'] : $padrao;
        // Sem direção escolhida: prazo e aluno em ordem crescente (o mais atrasado primeiro); datas, da mais recente.
        $dir = match ($f['dir'] ?? '') {
            'asc' => 'ASC',
            'desc' => 'DESC',
            default => in_array($ordem, ['prevista', 'aluno'], true) ? 'ASC' : 'DESC',
        };
        $ordemSql = match ($ordem) {
            'prevista' => "m.data_prevista $dir, m.id $dir",
            'devolucao' => "(m.data_devolucao IS NULL), m.data_devolucao $dir, m.id $dir",
            'aluno' => "a.nome $dir, m.id DESC",
            default => "m.data_emprestimo $dir, m.id $dir",
        };
        // LIMIT/OFFSET são inteiros calculados aqui.
        $sql = 'SELECT ' . self::COLUNAS . ' ' . self::JUNCOES . '
                LEFT JOIN administradores ep ON ep.id = m.emprestado_por
                LEFT JOIN administradores rp ON rp.id = m.recebido_por
                WHERE ' . $where . ' ORDER BY ' . $ordemSql . ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        return ['itens' => $this->todas($sql, array_merge([$hoje], $params)), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtros(array $f, string $hoje): array
    {
        $where = ['1 = 1'];
        $params = [];
        switch ($f['situacao'] ?? 'ativos') {
            case 'ativos':
                $where[] = 'm.data_devolucao IS NULL';
                break;
            case 'atrasados':
                $where[] = 'm.data_devolucao IS NULL AND m.data_prevista < ?';
                $params[] = $hoje;
                break;
            case 'devolvidos':
                $where[] = 'm.data_devolucao IS NOT NULL';
                break;
        }
        foreach ($this->termos((string)($f['q'] ?? '')) as $termo) {
            $like = $this->like($termo);
            $cond = 'a.nome LIKE ? OR a.matricula LIKE ? OR a.email LIKE ? OR l.titulo LIKE ? OR e.codigo_individual LIKE ?';
            array_push($params, $like, $like, $like, $like, $like);
            if ($codigo = self::codigoExemplar($termo)) {
                $cond .= ' OR e.codigo_individual = ?';
                $params[] = $codigo;
            }
            $where[] = "($cond)";
        }
        foreach (['de' => '>=', 'ate' => '<'] as $campo => $op) {
            $data = (string)($f[$campo] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                $where[] = "m.data_emprestimo $op ?";
                $params[] = $campo === 'ate' ? date('Y-m-d', strtotime($data . ' +1 day')) : $data;
            }
        }
        if ((int)($f['aluno'] ?? 0) > 0) {
            $where[] = 'm.aluno_id = ?';
            $params[] = (int)$f['aluno'];
        }
        if ((int)($f['exemplar'] ?? 0) > 0) {
            $where[] = 'm.exemplar_id = ?';
            $params[] = (int)$f['exemplar'];
        }
        return [implode(' AND ', $where), $params];
    }

    /** Totais das abas e do dashboard. */
    public function resumo(string $hoje): array
    {
        $r = $this->linha("SELECT COALESCE(SUM(data_devolucao IS NULL), 0) AS ativos,
                                  COALESCE(SUM(data_devolucao IS NULL AND data_prevista < ?), 0) AS atrasados,
                                  COALESCE(SUM(data_devolucao IS NULL AND data_prevista = ?), 0) AS vencem_hoje,
                                  COALESCE(SUM(data_devolucao IS NOT NULL), 0) AS devolvidos,
                                  COALESCE(SUM(data_devolucao >= ?), 0) AS devolvidos_mes,
                                  COUNT(*) AS todos
                           FROM emprestimos", [$hoje, $hoje, substr($hoje, 0, 8) . '01']) ?? [];
        return array_map('intval', $r);
    }

    /** Empréstimo com aluno, exemplar, livro e responsáveis. */
    public function buscar(int $id, string $hoje): ?array
    {
        return $this->linha('SELECT ' . self::COLUNAS . ', l.isbn, e.estado_conservacao ' . self::JUNCOES . '
                             LEFT JOIN administradores ep ON ep.id = m.emprestado_por
                             LEFT JOIN administradores rp ON rp.id = m.recebido_por
                             WHERE m.id = ?', [$hoje, $id]);
    }

    /** @return list<array> renovações do empréstimo, da mais antiga para a mais recente */
    public function renovacoes(int $emprestimoId): array
    {
        return $this->todas('SELECT r.prevista_anterior, r.prevista_nova, r.observacao, r.criado_em, ad.nome AS responsavel
                             FROM emprestimo_renovacoes r LEFT JOIN administradores ad ON ad.id = r.renovado_por
                             WHERE r.emprestimo_id = ? ORDER BY r.criado_em, r.id', [$emprestimoId]);
    }

    /** @return list<array> movimentações de um exemplar (ou só as de um empréstimo), da mais recente para a mais antiga */
    public function movimentacoes(int $exemplarId, ?int $emprestimoId = null): array
    {
        $sql = 'SELECT mv.tipo, mv.descricao, mv.emprestimo_id, mv.criado_em, ad.nome AS responsavel
                FROM movimentacoes mv LEFT JOIN administradores ad ON ad.id = mv.responsavel_id WHERE mv.exemplar_id = ?';
        $params = [$exemplarId];
        if ($emprestimoId !== null) {
            $sql .= ' AND mv.emprestimo_id = ?';
            $params[] = $emprestimoId;
        }
        return $this->todas($sql . ' ORDER BY mv.criado_em DESC, mv.id DESC LIMIT 200', $params);
    }

    // ---------------------------------------------------------------
    // Alunos
    // ---------------------------------------------------------------

    /**
     * Alunos cadastrados por matrícula, nome ou e-mail. Nunca escolhe um aluno sozinho:
     * a tela mostra todos os resultados (com turma e matrícula) para a bibliotecária selecionar.
     * Matrícula ou e-mail exatos aparecem primeiro.
     * @return array{itens: list<array>, mais: bool}
     */
    public function pesquisarAlunos(string $q, int $limite = 20): array
    {
        $termos = $this->termos($q);
        if (!$termos) {
            return ['itens' => [], 'mais' => false];
        }
        $where = [];
        $params = [];
        foreach ($termos as $termo) {
            $like = $this->like($termo);
            $where[] = '(nome LIKE ? OR matricula LIKE ? OR email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $exato = trim($q);
        $sql = 'SELECT id, nome, matricula, turma, turno, email, ativo,
                       (SELECT COUNT(*) FROM emprestimos m WHERE m.aluno_id = alunos.id AND m.data_devolucao IS NULL) AS em_aberto
                FROM alunos WHERE ' . implode(' AND ', $where) . '
                ORDER BY (matricula = ? OR email = ?) DESC, ativo DESC, nome, matricula LIMIT ' . ($limite + 1);
        $itens = $this->todas($sql, array_merge($params, [$exato, $exato]));
        return ['itens' => array_slice($itens, 0, $limite), 'mais' => count($itens) > $limite];
    }

    public function aluno(int $id): ?array
    {
        return $this->linha('SELECT id, nome, matricula, turma, turno, email, ativo, criado_em FROM alunos WHERE id = ?', [$id]);
    }

    /** Números do histórico de um aluno. */
    public function resumoAluno(int $alunoId, string $hoje): array
    {
        $r = $this->linha('SELECT COUNT(*) AS total,
                                  COALESCE(SUM(m.data_devolucao IS NULL), 0) AS ativos,
                                  COALESCE(SUM(m.data_devolucao IS NULL AND m.data_prevista < ?), 0) AS atrasados,
                                  COALESCE(SUM(m.data_devolucao IS NOT NULL), 0) AS devolvidos,
                                  COALESCE(SUM(m.data_devolucao IS NOT NULL AND DATE(m.data_devolucao) > m.data_prevista), 0) AS devolvidos_com_atraso,
                                  COALESCE(SUM(m.renovacoes), 0) AS renovacoes
                           FROM emprestimos m WHERE m.aluno_id = ?', [$hoje, $alunoId]) ?? [];
        return array_map('intval', $r);
    }

    /** @return list<array> renovações de todos os empréstimos do aluno, das mais recentes para as mais antigas */
    public function renovacoesDoAluno(int $alunoId, int $limite = 50): array
    {
        return $this->todas('SELECT r.emprestimo_id, r.prevista_anterior, r.prevista_nova, r.criado_em, ad.nome AS responsavel,
                                    e.codigo_individual, l.titulo
                             FROM emprestimo_renovacoes r
                             JOIN emprestimos m ON m.id = r.emprestimo_id
                             JOIN exemplares e ON e.id = m.exemplar_id
                             JOIN livros l ON l.id = e.livro_id
                             LEFT JOIN administradores ad ON ad.id = r.renovado_por
                             WHERE m.aluno_id = ? ORDER BY r.criado_em DESC, r.id DESC LIMIT ' . (int)$limite, [$alunoId]);
    }

    // ---------------------------------------------------------------
    // Exemplares
    // ---------------------------------------------------------------

    /**
     * Pesquisa para um novo empréstimo, por título, autor, ISBN ou código do exemplar.
     * Devolve os exemplares DISPONÍVEIS e, separadamente, os livros encontrados que não podem
     * ser emprestados agora (sem exemplar físico identificado ou com todos indisponíveis),
     * com as quantidades por situação, para a tela explicar o motivo.
     * @return array{disponiveis: list<array>, indisponiveis: list<array>, mais: bool}
     */
    public function pesquisarExemplares(string $q, int $limite = 30): array
    {
        $termos = $this->termos($q);
        if (!$termos) {
            return ['disponiveis' => [], 'indisponiveis' => [], 'mais' => false];
        }
        $where = ['l.arquivado_em IS NULL'];
        $params = [];
        $codigo = count($termos) === 1 ? self::codigoExemplar($termos[0]) : null;
        if ($codigo) {
            // Código do exemplar: busca exata (o leitor de código de barras digita o código inteiro).
            $where[] = 'EXISTS (SELECT 1 FROM exemplares x WHERE x.livro_id = l.id AND x.codigo_individual = ?)';
            $params[] = $codigo;
        } else {
            foreach ($termos as $termo) {
                $like = $this->like($termo);
                $cond = 'l.titulo LIKE ? OR l.subtitulo LIKE ?
                         OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)';
                array_push($params, $like, $like, $like);
                $isbn = Isbn::normalizar($termo);
                if (strlen($isbn) >= 4 && preg_match('/^[\d\-.\sXx]+$/', $termo)) {
                    $cond .= ' OR l.isbn LIKE ?';
                    $params[] = '%' . $isbn . '%';
                }
                $where[] = "($cond)";
            }
        }
        $livros = $this->todas('SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.origem_cadastro,
                                       (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ", ") FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                                FROM livros l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.titulo, l.id LIMIT ' . ($limite + 1), $params);
        $mais = count($livros) > $limite;
        $livros = array_slice($livros, 0, $limite);
        if (!$livros) {
            return ['disponiveis' => [], 'indisponiveis' => [], 'mais' => false];
        }
        $porId = array_column($livros, null, 'id');
        $marcadores = implode(', ', array_fill(0, count($porId), '?'));
        $sql = "SELECT e.id, e.livro_id, e.codigo_individual, e.situacao, e.estado_conservacao, lo.setor, lo.estante, lo.prateleira
                FROM exemplares e LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                WHERE e.livro_id IN ($marcadores)" . ($codigo ? ' AND e.codigo_individual = ?' : '') . ' ORDER BY e.codigo_individual';
        $exemplares = $this->todas($sql, $codigo ? array_merge(array_keys($porId), [$codigo]) : array_keys($porId));

        $disponiveis = [];
        $contagem = [];
        foreach ($exemplares as $ex) {
            $contagem[$ex['livro_id']][$ex['situacao']] = ($contagem[$ex['livro_id']][$ex['situacao']] ?? 0) + 1;
            if ($ex['situacao'] === 'disponivel') {
                $disponiveis[] = $ex + ['titulo' => $porId[$ex['livro_id']]['titulo'], 'autores' => $porId[$ex['livro_id']]['autores'], 'isbn' => $porId[$ex['livro_id']]['isbn']];
            }
        }
        $indisponiveis = [];
        foreach ($porId as $id => $livro) {
            $situacoes = $contagem[$id] ?? [];
            if (empty($situacoes['disponivel'])) {
                $indisponiveis[] = $livro + ['situacoes' => $situacoes, 'total_exemplares' => array_sum($situacoes),
                    'exemplar_procurado' => $codigo ? ($exemplares[0] ?? null) : null];
            }
        }
        return ['disponiveis' => $disponiveis, 'indisponiveis' => $indisponiveis, 'mais' => $mais];
    }

    /** Exemplar com o livro, os autores e o empréstimo em aberto (se houver). */
    public function exemplar(int $id): ?array
    {
        $ex = $this->linha('SELECT e.id, e.livro_id, e.codigo_individual, e.situacao, e.estado_conservacao, e.conferido_em, e.criado_em,
                                   l.titulo, l.isbn, l.arquivado_em,
                                   (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ", ") FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                   lo.setor, lo.estante, lo.prateleira,
                                   (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL) AS emprestimo_aberto
                            FROM exemplares e JOIN livros l ON l.id = e.livro_id LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                            WHERE e.id = ?', [$id]);
        return $ex;
    }

    public function exemplarPorCodigo(string $codigo): ?array
    {
        $codigo = self::codigoExemplar($codigo) ?? mb_strtoupper(trim($codigo));
        $id = $this->valor('SELECT id FROM exemplares WHERE codigo_individual = ?', [$codigo]);
        return $id ? $this->exemplar((int)$id) : null;
    }

    /** Resumo do histórico de um exemplar. */
    public function resumoExemplar(int $exemplarId, string $hoje): array
    {
        $r = $this->linha('SELECT COUNT(*) AS total, COALESCE(SUM(data_devolucao IS NOT NULL), 0) AS devolvidos,
                                  COALESCE(SUM(DATE(COALESCE(data_devolucao, ?)) > data_prevista), 0) AS com_atraso,
                                  COALESCE(SUM(renovacoes), 0) AS renovacoes
                           FROM emprestimos WHERE exemplar_id = ?', [$hoje, $exemplarId]) ?? [];
        return array_map('intval', $r);
    }

    // ---------------------------------------------------------------

    /**
     * Normaliza um código de exemplar digitado ("bdp-12", "BDP000012", "BDP 12") para BDP-000012.
     * Retorna null quando o texto não parece um código.
     */
    public static function codigoExemplar(string $texto): ?string
    {
        if (preg_match('/^BDP[\s\-]?(\d{1,6})$/i', trim($texto), $m)) {
            return 'BDP-' . str_pad($m[1], 6, '0', STR_PAD_LEFT);
        }
        return null;
    }

    /** @return list<string> até 6 palavras da pesquisa */
    private function termos(string $q): array
    {
        $termos = array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], fn ($t) => $t !== '' && mb_strlen($t) <= 100));
        return array_slice($termos, 0, 6);
    }

    private function like(string $termo): string
    {
        return '%' . addcslashes($termo, '%_\\') . '%';
    }

    private function linha(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function todas(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function valor(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
