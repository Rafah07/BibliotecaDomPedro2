<?php

namespace Biblioteca\Services;

use Biblioteca\Support\RegrasCirculacao;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

/**
 * Empréstimo, devolução e renovação de exemplares físicos.
 *
 * Regras:
 *   * Empresta-se sempre um EXEMPLAR (código BDP) com situação "disponível". Livros importados
 *     das planilhas sem exemplar identificado no inventário não podem ser emprestados, e nenhum
 *     exemplar é criado aqui.
 *   * Só alunos cadastrados e ativos (tabela alunos). Alunos não têm conta nem senha.
 *   * Cada operação roda numa transação com a linha do exemplar/empréstimo bloqueada
 *     (SELECT ... FOR UPDATE). Assim, dois pedidos ao mesmo tempo para o mesmo exemplar
 *     resultam em um único empréstimo; o índice único exemplar_em_aberto (migração 010) é a
 *     segunda barreira.
 *   * Empréstimo, movimentação do exemplar e registro de ação são gravados juntos: ou tudo,
 *     ou nada. Nada é apagado; a devolução apenas preenche data_devolucao.
 *   * Datas usam o fuso da escola (RegrasCirculacao::$fuso), não o do servidor.
 *   * Os registros de ação guardam só ids, o código do exemplar e o título do livro:
 *     nunca nome, matrícula ou e-mail do aluno.
 *   * O sistema ainda não tem reservas; quando existirem, a renovação deve consultá-las
 *     (ver renovar()).
 */
final class EmprestimoService
{
    public const MOTIVOS_INDISPONIVEL = [
        'emprestado' => 'Este exemplar já está emprestado.',
        'aguardando' => 'Este exemplar ainda aguarda a conferência física (inventário) e não foi liberado para empréstimo.',
        'manutencao' => 'Este exemplar está em manutenção.',
        'extraviado' => 'Este exemplar está registrado como extraviado.',
        'baixado' => 'Este exemplar foi baixado do acervo.',
        'danificado' => 'Este exemplar está registrado como danificado e foi retirado de circulação.',
    ];

    public function __construct(private PDO $pdo, private RegrasCirculacao $regras, private string $ip = 'cli')
    {
    }

    public function regras(): RegrasCirculacao
    {
        return $this->regras;
    }

    /** Data prevista padrão para um empréstimo feito hoje (Y-m-d). */
    public function prevista(): string
    {
        return $this->regras->agora()->modify('+' . $this->regras->prazoDias . ' days')->format('Y-m-d');
    }

    /** Nova data padrão de uma renovação: a partir de hoje ou do prazo atual, o que for maior. */
    public function previstaRenovacao(string $previstaAtual): string
    {
        $base = max($this->regras->hoje(), $previstaAtual);
        return (new DateTimeImmutable($base))->modify('+' . $this->regras->renovacaoDias . ' days')->format('Y-m-d');
    }

    /** Valida uma data prevista escolhida (Y-m-d). Retorna a mensagem do problema ou null. */
    public function problemaPrevista(string $data, ?string $depoisDe = null): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$d || $d->format('Y-m-d') !== $data) {
            return 'Informe uma data de devolução válida.';
        }
        $hoje = $this->regras->hoje();
        if ($data < $hoje) {
            return 'A data prevista de devolução não pode estar no passado.';
        }
        if ($depoisDe !== null && $data <= $depoisDe) {
            return 'A nova data precisa ser posterior ao prazo atual (' . (new DateTimeImmutable($depoisDe))->format('d/m/Y') . ').';
        }
        $limite = (new DateTimeImmutable($hoje))->modify('+' . $this->regras->prazoMaximoDias . ' days')->format('Y-m-d');
        if ($data > $limite) {
            return 'O prazo máximo aceito é de ' . $this->regras->prazoMaximoDias . ' dias (até ' . (new DateTimeImmutable($limite))->format('d/m/Y') . ').';
        }
        return null;
    }

    /**
     * Registra o empréstimo de um exemplar disponível a um aluno ativo.
     * @return array{id: int, codigo: string, titulo: string, data_prevista: string}
     */
    public function registrar(int $alunoId, int $exemplarId, string $prevista, int $responsavelId, string $observacao = ''): array
    {
        if ($problema = $this->problemaPrevista($prevista)) {
            throw new CirculacaoException($problema);
        }
        return $this->transacao(function () use ($alunoId, $exemplarId, $prevista, $responsavelId, $observacao) {
            $aluno = $this->linha('SELECT id, ativo FROM alunos WHERE id = ? LOCK IN SHARE MODE', [$alunoId]);
            if (!$aluno) {
                throw new CirculacaoException('Aluno não encontrado. Os empréstimos só podem ser feitos para alunos cadastrados (página Importar Alunos).');
            }
            if (!(int)$aluno['ativo']) {
                throw new CirculacaoException('O cadastro deste aluno está inativo. Empréstimos só podem ser feitos para alunos ativos.');
            }

            // Bloqueia o exemplar até o fim da transação: um segundo pedido simultâneo espera aqui
            // e, quando continuar, já encontra o exemplar emprestado.
            $ex = $this->linha('SELECT e.id, e.codigo_individual, e.situacao, l.titulo, l.arquivado_em
                                FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE e.id = ? FOR UPDATE', [$exemplarId]);
            if (!$ex) {
                throw new CirculacaoException('Exemplar não encontrado.');
            }
            if ($ex['arquivado_em'] !== null) {
                throw new CirculacaoException('O livro deste exemplar está arquivado.');
            }
            if ($ex['situacao'] !== 'disponivel') {
                throw new CirculacaoException(self::MOTIVOS_INDISPONIVEL[$ex['situacao']] ?? 'Este exemplar não está disponível para empréstimo.');
            }
            if ($this->linha('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL FOR UPDATE', [$exemplarId])) {
                throw new CirculacaoException(self::MOTIVOS_INDISPONIVEL['emprestado']);
            }

            $agora = $this->regras->agora()->format('Y-m-d H:i:s');
            $obs = $this->texto($observacao);
            try {
                $this->executar('INSERT INTO emprestimos (exemplar_id, aluno_id, data_emprestimo, data_prevista, emprestado_por, observacoes, criado_em)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)', [$exemplarId, $alunoId, $agora, $prevista, $responsavelId, $obs, $agora]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new CirculacaoException(self::MOTIVOS_INDISPONIVEL['emprestado']);
                }
                throw $e;
            }
            $id = (int)$this->pdo->lastInsertId();
            $mudou = $this->executar("UPDATE exemplares SET situacao = 'emprestado' WHERE id = ? AND situacao = 'disponivel'", [$exemplarId]);
            if ($mudou !== 1) {
                throw new CirculacaoException('Este exemplar não está mais disponível. Atualize a página.');
            }
            $this->movimentacao($exemplarId, $id, 'emprestimo', 'Empréstimo nº ' . $id . ' · devolução prevista para ' . $this->dataBr($prevista), $responsavelId, $agora);
            $this->acao($responsavelId, 'emprestimo.registrar', $id, ['codigo' => $ex['codigo_individual'], 'titulo' => $ex['titulo'], 'data_prevista' => $prevista]);
            return ['id' => $id, 'codigo' => $ex['codigo_individual'], 'titulo' => $ex['titulo'], 'data_prevista' => $prevista];
        });
    }

    /**
     * Registra a devolução. $quando (Y-m-d H:i:s) permite informar a hora real em que o livro
     * voltou, quando o registro é feito depois; padrão: agora.
     * $manutencao manda o exemplar para manutenção em vez de liberá-lo.
     * @return array{id: int, codigo: string, titulo: string, dias_atraso: int, situacao_exemplar: string}
     */
    public function devolver(int $emprestimoId, int $responsavelId, ?string $quando = null, bool $manutencao = false, string $observacao = ''): array
    {
        return $this->transacao(function () use ($emprestimoId, $responsavelId, $quando, $manutencao, $observacao) {
            $m = $this->linha('SELECT m.id, m.exemplar_id, m.data_emprestimo, m.data_prevista, m.data_devolucao, m.observacoes,
                                      e.codigo_individual, e.situacao, l.titulo
                               FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id
                               WHERE m.id = ? FOR UPDATE', [$emprestimoId]);
            if (!$m) {
                throw new CirculacaoException('Empréstimo não encontrado.');
            }
            if ($m['data_devolucao'] !== null) {
                throw new CirculacaoException('Este empréstimo já foi devolvido em ' . $this->dataBr($m['data_devolucao'], true) . '.');
            }
            $agora = $this->regras->agora()->format('Y-m-d H:i:s');
            $quando ??= $agora;
            $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $quando);
            if (!$d || $d->format('Y-m-d H:i:s') !== $quando) {
                throw new CirculacaoException('Informe uma data de devolução válida.');
            }
            if ($quando > $agora) {
                throw new CirculacaoException('A data da devolução não pode estar no futuro.');
            }
            if ($quando < $m['data_emprestimo']) {
                throw new CirculacaoException('A data da devolução não pode ser anterior à retirada (' . $this->dataBr($m['data_emprestimo'], true) . ').');
            }

            $obs = $this->texto($observacao);
            $mudou = $this->executar('UPDATE emprestimos SET data_devolucao = ?, recebido_por = ?, observacoes = ?
                                      WHERE id = ? AND data_devolucao IS NULL', [
                $quando, $responsavelId,
                $obs === null ? $m['observacoes'] : trim(($m['observacoes'] ? $m['observacoes'] . "\n" : '') . 'Devolução: ' . $obs),
                $emprestimoId,
            ]);
            if ($mudou !== 1) {
                throw new CirculacaoException('Este empréstimo já foi devolvido.');
            }

            // Libera o exemplar (ou o envia para manutenção). O gatilho do banco só permite tirar
            // um exemplar da situação "emprestado" dentro deste fluxo.
            $situacao = $m['situacao'];
            if ($situacao === 'emprestado') {
                $situacao = $manutencao ? 'manutencao' : 'disponivel';
                $this->pdo->exec("SET @biblioteca_fluxo = 'devolucao'");
                try {
                    $this->executar("UPDATE exemplares SET situacao = ? WHERE id = ? AND situacao = 'emprestado'", [$situacao, $m['exemplar_id']]);
                } finally {
                    $this->pdo->exec('SET @biblioteca_fluxo = NULL');
                }
            }
            $atraso = max(0, (int)(new DateTimeImmutable($m['data_prevista']))->diff(new DateTimeImmutable(substr($quando, 0, 10)))->format('%r%a'));
            $texto = 'Devolução do empréstimo nº ' . $emprestimoId . ($atraso > 0 ? ' · ' . $atraso . ' dia(s) de atraso' : ' · no prazo');
            $this->movimentacao((int)$m['exemplar_id'], $emprestimoId, 'devolucao', $texto, $responsavelId, $agora);
            if ($situacao === 'manutencao' && $manutencao) {
                $this->movimentacao((int)$m['exemplar_id'], $emprestimoId, 'manutencao', 'Enviado para manutenção na devolução' . ($obs ? ': ' . $obs : ''), $responsavelId, $agora);
            }
            $this->acao($responsavelId, 'devolucao.registrar', $emprestimoId, ['codigo' => $m['codigo_individual'], 'titulo' => $m['titulo'], 'dias_atraso' => $atraso, 'situacao_exemplar' => $situacao]);
            return ['id' => $emprestimoId, 'codigo' => $m['codigo_individual'], 'titulo' => $m['titulo'], 'dias_atraso' => $atraso, 'situacao_exemplar' => $situacao];
        });
    }

    /**
     * Motivo pelo qual o empréstimo não pode ser renovado, ou null.
     * @param array{data_devolucao: ?string, renovacoes: int|string, data_prevista: string} $m
     */
    public function impedimentoRenovacao(array $m): ?string
    {
        if ($m['data_devolucao'] !== null) {
            return 'Empréstimos devolvidos não podem ser renovados.';
        }
        if ((int)$m['renovacoes'] >= $this->regras->limiteRenovacoes()) {
            return $this->regras->maxRenovacoes !== null
                ? 'Este empréstimo já atingiu o limite de ' . $this->regras->maxRenovacoes . ' renovação(ões) definido pela biblioteca.'
                : 'Este empréstimo já atingiu o limite técnico de ' . RegrasCirculacao::RENOVACOES_LIMITE_TECNICO . ' renovações.';
        }
        if (!$this->regras->renovarAtrasado && $m['data_prevista'] < $this->regras->hoje()) {
            return 'Empréstimos atrasados não podem ser renovados pela regra configurada. Registre a devolução.';
        }
        // Reservas: o sistema ainda não tem esse controle. Quando existir, verificar aqui
        // se há reserva para o livro antes de permitir a renovação.
        return null;
    }

    /**
     * Renova um empréstimo em aberto até $novaPrevista (Y-m-d).
     * @return array{id: int, codigo: string, titulo: string, prevista_anterior: string, prevista_nova: string, renovacoes: int}
     */
    public function renovar(int $emprestimoId, string $novaPrevista, int $responsavelId, string $observacao = ''): array
    {
        return $this->transacao(function () use ($emprestimoId, $novaPrevista, $responsavelId, $observacao) {
            $m = $this->linha('SELECT m.id, m.exemplar_id, m.data_prevista, m.data_devolucao, m.renovacoes, e.codigo_individual, l.titulo
                               FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id
                               WHERE m.id = ? FOR UPDATE', [$emprestimoId]);
            if (!$m) {
                throw new CirculacaoException('Empréstimo não encontrado.');
            }
            if ($motivo = $this->impedimentoRenovacao($m)) {
                throw new CirculacaoException($motivo);
            }
            if ($problema = $this->problemaPrevista($novaPrevista, $m['data_prevista'])) {
                throw new CirculacaoException($problema);
            }
            $agora = $this->regras->agora()->format('Y-m-d H:i:s');
            $obs = $this->texto($observacao);
            $this->executar('INSERT INTO emprestimo_renovacoes (emprestimo_id, prevista_anterior, prevista_nova, renovado_por, observacao, criado_em)
                             VALUES (?, ?, ?, ?, ?, ?)', [$emprestimoId, $m['data_prevista'], $novaPrevista, $responsavelId, $obs, $agora]);
            $mudou = $this->executar('UPDATE emprestimos SET data_prevista = ?, renovacoes = renovacoes + 1
                                      WHERE id = ? AND data_devolucao IS NULL AND renovacoes = ?', [$novaPrevista, $emprestimoId, (int)$m['renovacoes']]);
            if ($mudou !== 1) {
                throw new CirculacaoException('O empréstimo foi alterado por outra pessoa. Atualize a página.');
            }
            $numero = (int)$m['renovacoes'] + 1;
            $this->movimentacao((int)$m['exemplar_id'], $emprestimoId, 'renovacao', 'Renovação nº ' . $numero . ' do empréstimo nº ' . $emprestimoId . ': de ' . $this->dataBr($m['data_prevista']) . ' para ' . $this->dataBr($novaPrevista), $responsavelId, $agora);
            $this->acao($responsavelId, 'emprestimo.renovar', $emprestimoId, ['codigo' => $m['codigo_individual'], 'titulo' => $m['titulo'], 'de' => $m['data_prevista'], 'para' => $novaPrevista, 'renovacao' => $numero]);
            return ['id' => $emprestimoId, 'codigo' => $m['codigo_individual'], 'titulo' => $m['titulo'], 'prevista_anterior' => $m['data_prevista'], 'prevista_nova' => $novaPrevista, 'renovacoes' => $numero];
        });
    }

    // ---------------------------------------------------------------

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transacao(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $resultado = $fn();
            $this->pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Espera longa por bloqueio ou deadlock: nada foi gravado, basta tentar de novo.
            if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new CirculacaoException('Outra operação estava usando este registro. Nada foi gravado; tente novamente.', 0, $e);
            }
            throw $e;
        }
    }

    private function linha(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha ?: null;
    }

    private function executar(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private function movimentacao(int $exemplarId, int $emprestimoId, string $tipo, string $descricao, int $responsavelId, string $quando): void
    {
        $this->executar('INSERT INTO movimentacoes (exemplar_id, emprestimo_id, tipo, descricao, responsavel_id, criado_em) VALUES (?, ?, ?, ?, ?, ?)',
            [$exemplarId, $emprestimoId, $tipo, $descricao, $responsavelId, $quando]);
    }

    /** Registro da ação, na mesma transação. Sem dados pessoais do aluno. */
    private function acao(int $responsavelId, string $acao, int $emprestimoId, array $detalhes): void
    {
        $this->executar('INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $responsavelId, $acao, 'emprestimo', $emprestimoId, json_encode($detalhes, JSON_UNESCAPED_UNICODE), substr($this->ip, 0, 45),
            $this->regras->agora()->format('Y-m-d H:i:s'),
        ]);
    }

    private function texto(string $valor): ?string
    {
        $valor = trim(preg_replace('/\s+/u', ' ', $valor) ?? '');
        return $valor === '' ? null : mb_substr($valor, 0, 500);
    }

    private function dataBr(string $data, bool $hora = false): string
    {
        return (new DateTimeImmutable($data))->format($hora ? 'd/m/Y H:i' : 'd/m/Y');
    }
}
