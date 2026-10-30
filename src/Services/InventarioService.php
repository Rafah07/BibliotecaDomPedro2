<?php

namespace Biblioteca\Services;

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Support\ConsultasPdo;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Inventário digital: rodadas de conferência física do acervo.
 *
 * Situações e transições:
 *   aberto (em preparação) → em_andamento | cancelado
 *   em_andamento           → pausado | concluido | cancelado
 *   pausado                → em_andamento | concluido | cancelado
 *   concluido, cancelado   → nada (o banco também recusa alterações: migração 070)
 *
 * Regras:
 *   * Só um inventário fica em andamento (ou pausado) por vez.
 *   * Ao começar, a lista de exemplares previstos é congelada em inventario_itens
 *     (exemplares não baixados nem extraviados, do setor escolhido ou do acervo inteiro).
 *   * Cada exemplar é conferido uma única vez por inventário (índice único). Uma segunda
 *     leitura do mesmo código não cria nada: avisa e mostra a conferência anterior.
 *     Correções são registradas à parte, com motivo, por quem pode gerenciar o inventário.
 *   * Divergências (local, estado, situação) e ausências nunca são resolvidas sozinhas:
 *     exigem uma decisão explícita, e o inventário só é concluído depois de todas decididas.
 *     Exemplares não conferidos NÃO viram extraviados automaticamente.
 *   * Exemplares novos só são criados por uma ação explícita na tela de conferência.
 *   * Empréstimos ativos são respeitados: um exemplar emprestado não é considerado ausente
 *     e nada no inventário muda a situação dele.
 */
final class InventarioService
{
    use ConsultasPdo;

    public const STATUS = [
        'aberto' => 'Em preparação',
        'em_andamento' => 'Em andamento',
        'pausado' => 'Pausado',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];

    public const TRANSICOES = [
        'aberto' => ['em_andamento', 'cancelado'],
        'em_andamento' => ['pausado', 'concluido', 'cancelado'],
        'pausado' => ['em_andamento', 'concluido', 'cancelado'],
        'concluido' => [],
        'cancelado' => [],
    ];

    public const DECISOES = [
        'atualizar_cadastro' => 'Atualizar o cadastro com o que foi encontrado',
        'manter_cadastro' => 'Manter o cadastro como está',
        'registrar_extravio' => 'Registrar como extraviado',
        'manter_sem_alteracao' => 'Manter sem alteração (procurar depois)',
    ];

    public function __construct(private PDO $pdo, private ExemplarService $exemplares, private InventarioRepository $repo)
    {
    }

    // ---------------------------------------------------------------
    // Cadastro e situação do inventário
    // ---------------------------------------------------------------

    public function criar(array $dados, int $responsavelId): int
    {
        $d = $this->validar($dados, true);
        return $this->transacao(function () use ($d, $responsavelId) {
            $this->executar("INSERT INTO inventarios (titulo, descricao, data_inicio, status, aberto_por, responsavel_id, setor) VALUES (?, ?, ?, 'aberto', ?, ?, ?)",
                [$d['titulo'], $d['descricao'], $d['data_inicio'], $responsavelId, $d['responsavel_id'], $d['setor']]);
            $id = (int)$this->pdo->lastInsertId();
            $this->registrarAcao($responsavelId, 'inventario.criar', 'inventario', $id, ['titulo' => $d['titulo'], 'setor' => $d['setor']]);
            return $id;
        });
    }

    /** Edita os dados. O setor só muda em preparação (a lista de previstos depende dele). */
    public function editar(int $id, array $dados, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $dados, $responsavelId) {
            $inv = $this->bloquearInventario($id);
            $this->exigirStatus($inv, ['aberto', 'em_andamento', 'pausado'], 'editado');
            $d = $this->validar($dados, $inv['status'] === 'aberto');
            $setor = $inv['status'] === 'aberto' ? $d['setor'] : $inv['setor'];
            $this->executar('UPDATE inventarios SET titulo = ?, descricao = ?, data_inicio = ?, responsavel_id = ?, setor = ? WHERE id = ?',
                [$d['titulo'], $d['descricao'], $d['data_inicio'], $d['responsavel_id'], $setor, $id]);
            $this->registrarAcao($responsavelId, 'inventario.editar', 'inventario', $id, ['titulo' => $d['titulo']]);
        });
    }

    /** Começa a conferência e congela a lista de exemplares previstos. @return int quantidade prevista */
    public function iniciar(int $id, int $responsavelId): int
    {
        return $this->comTrava(fn () => $this->transacao(function () use ($id, $responsavelId) {
            $inv = $this->bloquearInventario($id);
            $this->exigirTransicao($inv, 'em_andamento');
            $this->exigirNenhumOutroAtivo($id);
            $this->executar("UPDATE inventarios SET status = 'em_andamento', em_andamento_desde = NOW() WHERE id = ?", [$id]);
            $params = [$id];
            $setorSql = '';
            if ($inv['setor'] !== null && $inv['setor'] !== '') {
                $setorSql = ' AND lo.setor = ?';
                $params[] = $inv['setor'];
            }
            $previstos = $this->executar("INSERT INTO inventario_itens (inventario_id, exemplar_id, previsto, situacao_esperada, localizacao_esperada_id, estado_esperado)
                                          SELECT ?, e.id, 1, e.situacao, e.localizacao_id, e.estado_conservacao
                                          FROM exemplares e
                                          JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                                          LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                                          WHERE e.situacao NOT IN ('baixado', 'extraviado')$setorSql", $params);
            $this->registrarAcao($responsavelId, 'inventario.iniciar', 'inventario', $id, ['titulo' => $inv['titulo'], 'previstos' => $previstos]);
            return $previstos;
        }));
    }

    public function pausar(int $id, int $responsavelId): void
    {
        $this->mudarStatus($id, 'pausado', $responsavelId, 'inventario.pausar');
    }

    public function retomar(int $id, int $responsavelId): void
    {
        $this->comTrava(function () use ($id, $responsavelId) {
            $this->exigirNenhumOutroAtivo($id);
            $this->mudarStatus($id, 'em_andamento', $responsavelId, 'inventario.retomar');
        });
    }

    /** Pendências que impedem a conclusão. @return array{divergencias: int, ausentes: int} */
    public function pendencias(int $id): array
    {
        $r = $this->repo->resumo($id);
        return ['divergencias' => $r['divergencias_pendentes'], 'ausentes' => $r['ausentes_pendentes']];
    }

    /**
     * Conclui o inventário depois que todas as divergências e ausências foram decididas.
     * Grava o resumo congelado e o resultado por título em inventario_conferencias.
     */
    public function concluir(int $id, int $responsavelId): array
    {
        return $this->transacao(function () use ($id, $responsavelId) {
            $inv = $this->bloquearInventario($id);
            $this->exigirTransicao($inv, 'concluido');
            $p = $this->pendencias($id);
            if ($p['divergencias'] > 0 || $p['ausentes'] > 0) {
                $partes = [];
                if ($p['divergencias'] > 0) {
                    $partes[] = $p['divergencias'] . ' divergência(s) sem decisão';
                }
                if ($p['ausentes'] > 0) {
                    $partes[] = $p['ausentes'] . ' exemplar(es) previsto(s) ainda não conferido(s) e sem decisão';
                }
                throw new AcervoException('Ainda não é possível concluir: ' . implode(' e ', $partes) . '. Trate as pendências na aba "Pendências".');
            }
            $resumo = $this->repo->resumo($id);
            $this->executar("INSERT INTO inventario_conferencias (inventario_id, livro_id, esperado, encontrados, observacao, responsavel_id)
                             SELECT ?, e.livro_id, SUM(i.previsto OR i.cadastrado_no_inventario), SUM(i.conferido_em IS NOT NULL),
                                    CONCAT(?, IF(SUM(i.cadastrado_no_inventario) > 0, CONCAT(' · ', SUM(i.cadastrado_no_inventario), ' exemplar(es) cadastrado(s) na conferência'), '')),
                                    ?
                             FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id
                             WHERE i.inventario_id = ? GROUP BY e.livro_id",
                [$id, 'Inventário nº ' . $id . ': ' . mb_substr($inv['titulo'], 0, 120), $responsavelId, $id]);
            $this->executar("UPDATE inventarios SET status = 'concluido', concluido_em = NOW(), concluido_por = ?, resumo_final = ? WHERE id = ?",
                [$responsavelId, json_encode($resumo), $id]);
            $this->registrarAcao($responsavelId, 'inventario.concluir', 'inventario', $id, ['titulo' => $inv['titulo']] + $resumo);
            return $resumo;
        });
    }

    public function cancelar(int $id, int $responsavelId, string $motivo): void
    {
        $motivo = self::textoLimpo($motivo);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo do cancelamento.');
        }
        $this->transacao(function () use ($id, $responsavelId, $motivo) {
            $inv = $this->bloquearInventario($id);
            $this->exigirTransicao($inv, 'cancelado');
            $this->executar("UPDATE inventarios SET status = 'cancelado', cancelado_em = NOW(), cancelado_por = ?, motivo_cancelamento = ? WHERE id = ?",
                [$responsavelId, $motivo, $id]);
            $this->registrarAcao($responsavelId, 'inventario.cancelar', 'inventario', $id, ['titulo' => $inv['titulo'], 'motivo' => $motivo]);
        });
    }

    // ---------------------------------------------------------------
    // Conferência
    // ---------------------------------------------------------------

    /**
     * Registra a conferência de um exemplar já cadastrado.
     * Dados: estado_conservacao (obrigatório), localizacao_id (vazio = não informado), observacao,
     * aplicar (atualizar o cadastro na hora, se houver divergência de local/estado; só com $podeDecidir).
     * @return array item conferido (com as divergências encontradas)
     */
    public function conferir(int $inventarioId, int $exemplarId, array $dados, int $responsavelId, bool $podeDecidir = false): array
    {
        $estado = (string)($dados['estado_conservacao'] ?? '');
        if (!in_array($estado, ExemplarService::CONSERVACAO, true)) {
            throw new AcervoException('Informe o estado de conservação encontrado.');
        }
        $obs = self::textoLimpo($dados['observacao'] ?? null);
        $aplicar = !empty($dados['aplicar']) && $podeDecidir;

        try {
            return $this->transacao(function () use ($inventarioId, $exemplarId, $estado, $obs, $aplicar, $responsavelId, $dados) {
                $inv = $this->inventarioParaConferencia($inventarioId);
                $ex = $this->exemplares->bloquear($exemplarId);
                $localEncontrado = $this->exemplares->localizacaoValida($dados['localizacao_id'] ?? null);
                $item = $this->linha('SELECT * FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ? FOR UPDATE', [$inventarioId, $exemplarId]);
                if ($item && $item['conferido_em'] !== null) {
                    throw $this->duplicada((int)$item['id']);
                }
                if (!$item) {
                    // Encontrado, mas não estava na lista prevista (outro setor, cadastrado depois do início etc.).
                    $this->executar('INSERT INTO inventario_itens (inventario_id, exemplar_id, previsto, situacao_esperada, localizacao_esperada_id, estado_esperado) VALUES (?, ?, 0, ?, ?, ?)',
                        [$inventarioId, $exemplarId, $ex['situacao'], $ex['localizacao_id'], $ex['estado_conservacao']]);
                    $item = ['id' => (int)$this->pdo->lastInsertId()];
                }
                $localAtual = $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null;
                $localEncontrado ??= $localAtual;
                $divLocal = $localAtual !== null && $localEncontrado !== null && $localEncontrado !== $localAtual;
                $divEstado = $estado !== $ex['estado_conservacao'];
                $divSituacao = in_array($ex['situacao'], ['emprestado', 'extraviado', 'baixado'], true) || $ex['emprestimo_aberto'] !== null;

                $this->executar('UPDATE inventario_itens SET conferido_em = NOW(), conferido_por = ?, localizacao_encontrada_id = ?, estado_encontrado = ?, observacao = ?,
                                        divergencia_local = ?, divergencia_estado = ?, divergencia_situacao = ? WHERE id = ?',
                    [$responsavelId, $localEncontrado, $estado, $obs, (int)$divLocal, (int)$divEstado, (int)$divSituacao, $item['id']]);

                // Exemplar ainda sem localização: a localização encontrada passa a ser a confirmada.
                if ($localAtual === null && $localEncontrado !== null && $ex['situacao'] !== 'baixado') {
                    $this->exemplares->transferir($exemplarId, $localEncontrado, $responsavelId, 'localização confirmada na conferência', $inventarioId);
                }
                if ($aplicar && ($divLocal || $divEstado) && !$divSituacao) {
                    $this->aplicarEncontrado($exemplarId, $divLocal ? $localEncontrado : null, $divEstado ? $estado : null, $responsavelId, $inventarioId);
                    $this->executar("UPDATE inventario_itens SET decisao = 'atualizar_cadastro', decidido_por = ?, decidido_em = NOW(), decisao_observacao = ? WHERE id = ?",
                        [$responsavelId, 'Atualizado no momento da conferência', $item['id']]);
                }
                $this->executar('UPDATE exemplares SET conferido_em = NOW() WHERE id = ?', [$exemplarId]);
                $avisos = array_filter([$divLocal ? 'localização diferente' : null, $divEstado ? 'estado diferente' : null, $divSituacao ? 'situação ' . ExemplarService::rotuloSituacao($ex['emprestimo_aberto'] !== null ? 'emprestado' : $ex['situacao']) : null]);
                $this->exemplares->movimentacao($exemplarId, 'inventario', 'Conferido no inventário nº ' . $inventarioId . ($avisos ? ' · divergência: ' . implode(', ', $avisos) : ''),
                    $responsavelId, $inventarioId, ['estado' => $estado, 'localizacao_id' => $localEncontrado]);
                return $this->repo->item((int)$item['id']);
            });
        } catch (PDOException $e) {
            // Duas leituras ao mesmo tempo: o índice único segura a segunda.
            if ($e->getCode() === '23000') {
                $item = $this->repo->itemPorExemplar($inventarioId, $exemplarId);
                if ($item) {
                    throw $this->duplicada((int)$item['id']);
                }
            }
            throw $e;
        }
    }

    /**
     * Cadastra exemplar(es) novo(s) durante a conferência (ação explícita) e já os marca como conferidos.
     * @return list<array{id: int, codigo: string}>
     */
    public function cadastrarExemplar(int $inventarioId, int $livroId, array $dados, int $responsavelId, int $quantidade = 1): array
    {
        return $this->transacao(function () use ($inventarioId, $livroId, $dados, $responsavelId, $quantidade) {
            $this->inventarioParaConferencia($inventarioId);
            $criados = $this->exemplares->cadastrar($livroId, $dados, $responsavelId, $quantidade, $inventarioId);
            $obs = self::textoLimpo($dados['observacoes'] ?? null);
            foreach ($criados as $c) {
                $ex = $this->linha('SELECT localizacao_id, estado_conservacao, situacao FROM exemplares WHERE id = ?', [$c['id']]);
                $this->executar('INSERT INTO inventario_itens (inventario_id, exemplar_id, previsto, cadastrado_no_inventario, conferido_em, conferido_por,
                                                               localizacao_encontrada_id, estado_encontrado, observacao)
                                 VALUES (?, ?, 0, 1, NOW(), ?, ?, ?, ?)', [$inventarioId, $c['id'], $responsavelId, $ex['localizacao_id'], $ex['estado_conservacao'], $obs]);
                $this->executar('UPDATE exemplares SET conferido_em = NOW() WHERE id = ?', [$c['id']]);
            }
            return $criados;
        });
    }

    /** Correção autorizada de uma conferência já registrada (fica no histórico, com o motivo). */
    public function corrigir(int $itemId, array $dados, int $responsavelId, string $motivo): array
    {
        $motivo = self::textoLimpo($motivo);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo da correção.');
        }
        $estado = (string)($dados['estado_conservacao'] ?? '');
        if (!in_array($estado, ExemplarService::CONSERVACAO, true)) {
            throw new AcervoException('Informe o estado de conservação encontrado.');
        }
        $obs = self::textoLimpo($dados['observacao'] ?? null);
        return $this->transacao(function () use ($itemId, $estado, $obs, $motivo, $responsavelId, $dados) {
            $item = $this->bloquearItem($itemId);
            if ($item['conferido_em'] === null) {
                throw new AcervoException('Este exemplar ainda não foi conferido; registre a conferência normalmente.');
            }
            $ex = $this->exemplares->bloquear((int)$item['exemplar_id']);
            $local = $this->exemplares->localizacaoValida($dados['localizacao_id'] ?? null) ?? ($item['localizacao_encontrada_id'] !== null ? (int)$item['localizacao_encontrada_id'] : null);
            $localAtual = $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null;
            $divLocal = $localAtual !== null && $local !== null && $local !== $localAtual;
            $divEstado = $estado !== $ex['estado_conservacao'];
            $antes = ['localizacao_id' => $item['localizacao_encontrada_id'], 'estado' => $item['estado_encontrado'], 'observacao' => $item['observacao'], 'decisao' => $item['decisao']];
            // Se a correção muda o que foi encontrado, a decisão anterior deixa de valer.
            $mudou = (string)$item['estado_encontrado'] !== $estado || (string)$item['localizacao_encontrada_id'] !== (string)$local;
            $this->executar('UPDATE inventario_itens SET localizacao_encontrada_id = ?, estado_encontrado = ?, observacao = ?, divergencia_local = ?, divergencia_estado = ?,
                                    decisao = IF(?, NULL, decisao), decidido_por = IF(?, NULL, decidido_por), decidido_em = IF(?, NULL, decidido_em),
                                    decisao_observacao = IF(?, NULL, decisao_observacao), correcoes = correcoes + 1 WHERE id = ?',
                [$local, $estado, $obs, (int)$divLocal, (int)$divEstado, (int)$mudou, (int)$mudou, (int)$mudou, (int)$mudou, $itemId]);
            $depois = ['localizacao_id' => $local, 'estado' => $estado, 'observacao' => $obs];
            $this->exemplares->movimentacao((int)$item['exemplar_id'], 'inventario', 'Correção da conferência do inventário nº ' . $item['inventario_id'] . ' · ' . $motivo,
                $responsavelId, (int)$item['inventario_id'], ['antes' => $antes, 'depois' => $depois]);
            $this->registrarAcao($responsavelId, 'inventario.corrigir', 'inventario', (int)$item['inventario_id'], ['codigo' => $ex['codigo_individual'], 'motivo' => $motivo, 'antes' => $antes, 'depois' => $depois]);
            return $this->repo->item($itemId);
        });
    }

    /** Decisão administrativa sobre uma divergência ou ausência. */
    public function decidir(int $itemId, string $decisao, string $observacao, int $responsavelId): void
    {
        if (!isset(self::DECISOES[$decisao])) {
            throw new AcervoException('Escolha uma decisão.');
        }
        $obs = self::textoLimpo($observacao);
        $this->transacao(function () use ($itemId, $decisao, $obs, $responsavelId) {
            $item = $this->bloquearItem($itemId);
            $invId = (int)$item['inventario_id'];
            if ($item['decisao'] !== null) {
                throw new AcervoException('Esta pendência já foi decidida (' . self::DECISOES[$item['decisao']] . ').');
            }
            $ex = $this->exemplares->bloquear((int)$item['exemplar_id']);
            $conferido = $item['conferido_em'] !== null;
            $divergente = (int)$item['divergencia_local'] || (int)$item['divergencia_estado'] || (int)$item['divergencia_situacao'];

            if ($conferido && $divergente) {
                if (!in_array($decisao, ['atualizar_cadastro', 'manter_cadastro'], true)) {
                    throw new AcervoException('Para um exemplar encontrado com divergência, escolha atualizar ou manter o cadastro.');
                }
                if ($decisao === 'atualizar_cadastro') {
                    if ((int)$item['divergencia_situacao']) {
                        if ($ex['situacao'] === 'emprestado' || $ex['emprestimo_aberto'] !== null) {
                            throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' tem empréstimo ativo. Registre a devolução no módulo de empréstimos; o inventário não altera empréstimos.');
                        }
                        if ($ex['situacao'] === 'baixado') {
                            throw new AcervoException('Exemplares baixados não voltam ao acervo. Escolha "Manter o cadastro" e registre uma observação.');
                        }
                        if ($ex['situacao'] === 'extraviado') {
                            $this->exemplares->alterarSituacao((int)$ex['id'], 'disponivel', $responsavelId, 'reencontrado no inventário nº ' . $invId, $invId);
                        }
                    }
                    $this->aplicarEncontrado((int)$ex['id'], (int)$item['divergencia_local'] ? (int)$item['localizacao_encontrada_id'] : null,
                        (int)$item['divergencia_estado'] ? $item['estado_encontrado'] : null, $responsavelId, $invId);
                }
            } elseif (!$conferido && (int)$item['previsto']) {
                if (!in_array($decisao, ['registrar_extravio', 'manter_sem_alteracao'], true)) {
                    throw new AcervoException('Para um exemplar não localizado, escolha registrar como extraviado ou manter sem alteração.');
                }
                if ($ex['emprestimo_aberto'] !== null) {
                    throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' está emprestado: não precisa estar na estante e não pode ser marcado como extraviado.');
                }
                if ($decisao === 'registrar_extravio') {
                    $this->exemplares->registrarExtravio((int)$ex['id'], $responsavelId, 'não localizado no inventário nº ' . $invId . ($obs ? ' · ' . $obs : ''), $invId);
                }
            } else {
                throw new AcervoException('Não há divergência nem ausência para decidir neste item.');
            }
            $this->executar('UPDATE inventario_itens SET decisao = ?, decisao_observacao = ?, decidido_por = ?, decidido_em = NOW() WHERE id = ?',
                [$decisao, $obs, $responsavelId, $itemId]);
            $this->registrarAcao($responsavelId, 'inventario.decidir', 'inventario', $invId, ['codigo' => $ex['codigo_individual'], 'decisao' => $decisao]);
        });
    }

    /** Decide de uma vez que os previstos não localizados ficam sem alteração. @return int itens decididos */
    public function manterAusentes(int $inventarioId, string $observacao, int $responsavelId): int
    {
        $obs = self::textoLimpo($observacao);
        return $this->transacao(function () use ($inventarioId, $obs, $responsavelId) {
            $inv = $this->bloquearInventario($inventarioId);
            $this->exigirStatus($inv, ['em_andamento', 'pausado'], 'alterado');
            $n = $this->executar("UPDATE inventario_itens i
                                  SET i.decisao = 'manter_sem_alteracao', i.decisao_observacao = ?, i.decidido_por = ?, i.decidido_em = NOW()
                                  WHERE i.inventario_id = ? AND i.previsto = 1 AND i.conferido_em IS NULL AND i.decisao IS NULL
                                    AND NOT EXISTS (SELECT 1 FROM emprestimos m WHERE m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL)",
                [$obs ?? 'Não localizados: mantidos sem alteração', $responsavelId, $inventarioId]);
            $this->registrarAcao($responsavelId, 'inventario.manter_ausentes', 'inventario', $inventarioId, ['itens' => $n]);
            return $n;
        });
    }

    // ---------------------------------------------------------------

    private function aplicarEncontrado(int $exemplarId, ?int $local, ?string $estado, int $responsavelId, int $inventarioId): void
    {
        if ($local !== null) {
            $this->exemplares->transferir($exemplarId, $local, $responsavelId, 'encontrado em outra localização no inventário nº ' . $inventarioId, $inventarioId);
        }
        if ($estado !== null) {
            $this->exemplares->alterarConservacao($exemplarId, $estado, $responsavelId, $inventarioId);
        }
    }

    private function duplicada(int $itemId): ConferenciaDuplicadaException
    {
        $item = $this->repo->item($itemId);
        $quando = $item && $item['conferido_em'] ? (new DateTimeImmutable($item['conferido_em']))->format('d/m/Y \à\s H:i') : '';
        return new ConferenciaDuplicadaException('O exemplar ' . ($item['codigo_individual'] ?? '') . ' já foi conferido neste inventário'
            . ($quando ? ' em ' . $quando : '') . ($item['conferido_por_nome'] ?? null ? ' por ' . $item['conferido_por_nome'] : '') . '. Nada foi gravado de novo.', $item ?? []);
    }

    /** Inventário bloqueado para leitura e em andamento (conferências não podem ocorrer em outra situação). */
    private function inventarioParaConferencia(int $id): array
    {
        $inv = $this->linha('SELECT * FROM inventarios WHERE id = ? LOCK IN SHARE MODE', [$id]);
        if (!$inv) {
            throw new AcervoException('Inventário não encontrado.');
        }
        if ($inv['status'] !== 'em_andamento') {
            throw new AcervoException($inv['status'] === 'pausado'
                ? 'O inventário está pausado. Retome-o para continuar a conferência.'
                : 'O inventário não está em andamento (' . self::STATUS[$inv['status']] . '). Não é possível registrar conferências.');
        }
        return $inv;
    }

    private function bloquearInventario(int $id): array
    {
        $inv = $this->linha('SELECT * FROM inventarios WHERE id = ? FOR UPDATE', [$id]);
        if (!$inv) {
            throw new AcervoException('Inventário não encontrado.');
        }
        return $inv;
    }

    private function bloquearItem(int $itemId): array
    {
        $item = $this->linha('SELECT i.*, v.status AS inventario_status FROM inventario_itens i JOIN inventarios v ON v.id = i.inventario_id WHERE i.id = ? FOR UPDATE', [$itemId]);
        if (!$item) {
            throw new AcervoException('Conferência não encontrada.');
        }
        $this->exigirStatus(['status' => $item['inventario_status']], ['em_andamento', 'pausado'], 'alterado');
        return $item;
    }

    private function exigirStatus(array $inv, array $permitidos, string $acao): void
    {
        if (!in_array($inv['status'], $permitidos, true)) {
            throw new AcervoException('Um inventário ' . mb_strtolower(self::STATUS[$inv['status']]) . ' não pode ser ' . $acao . '.');
        }
    }

    private function exigirTransicao(array $inv, string $para): void
    {
        if (!in_array($para, self::TRANSICOES[$inv['status']], true)) {
            throw new AcervoException('Não é possível passar de "' . self::STATUS[$inv['status']] . '" para "' . self::STATUS[$para] . '".');
        }
    }

    private function mudarStatus(int $id, string $para, int $responsavelId, string $acao): void
    {
        $this->transacao(function () use ($id, $para, $responsavelId, $acao) {
            $inv = $this->bloquearInventario($id);
            $this->exigirTransicao($inv, $para);
            $this->executar('UPDATE inventarios SET status = ? WHERE id = ?', [$para, $id]);
            $this->registrarAcao($responsavelId, $acao, 'inventario', $id, ['titulo' => $inv['titulo']]);
        });
    }

    private function exigirNenhumOutroAtivo(int $id): void
    {
        $outro = $this->linha("SELECT id, titulo FROM inventarios WHERE status IN ('em_andamento', 'pausado') AND id <> ? LIMIT 1", [$id]);
        if ($outro) {
            throw new AcervoException('Já existe um inventário em andamento ou pausado ("' . $outro['titulo'] . '"). Conclua ou cancele esse inventário antes de começar outro.');
        }
    }

    /** Trava nomeada do MySQL: dois inventários não começam ao mesmo tempo. */
    private function comTrava(callable $fn): mixed
    {
        if ((int)$this->valor("SELECT GET_LOCK('bdp_inventario_ativo', 10)") !== 1) {
            throw new AcervoException('Outra pessoa está alterando os inventários agora. Tente novamente em alguns segundos.');
        }
        try {
            return $fn();
        } finally {
            $this->valor("SELECT RELEASE_LOCK('bdp_inventario_ativo')");
        }
    }

    /** @return array{titulo: string, descricao: ?string, data_inicio: string, responsavel_id: int, setor: ?string} */
    private function validar(array $dados, bool $comSetor): array
    {
        $titulo = self::textoLimpo($dados['titulo'] ?? null, 150);
        if ($titulo === null || mb_strlen($titulo) < 3) {
            throw new AcervoException('Informe o nome do inventário (ex.: Inventário Geral — Biblioteca Dom Pedro II).');
        }
        $data = (string)($dados['data_inicio'] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$d || $d->format('Y-m-d') !== $data || $d->format('Y') < 2000 || $d->format('Y') > 2100) {
            throw new AcervoException('Informe uma data de início válida.');
        }
        $resp = (int)($dados['responsavel_id'] ?? 0);
        if (!$resp || !$this->valor('SELECT id FROM administradores WHERE id = ? AND ativo = 1', [$resp])) {
            throw new AcervoException('Escolha o responsável pelo inventário (uma conta ativa do painel).');
        }
        $setor = $comSetor ? self::textoLimpo($dados['setor'] ?? null, 80) : null;
        if ($setor !== null && !$this->valor('SELECT 1 FROM localizacoes WHERE setor = ? LIMIT 1', [$setor])) {
            throw new AcervoException('Setor não encontrado. Cadastre o setor em Localizações ou deixe em branco para conferir o acervo inteiro.');
        }
        $descricao = trim((string)($dados['descricao'] ?? ''));
        return ['titulo' => $titulo, 'descricao' => $descricao === '' ? null : mb_substr($descricao, 0, 2000), 'data_inicio' => $data, 'responsavel_id' => $resp, 'setor' => $setor];
    }
}
