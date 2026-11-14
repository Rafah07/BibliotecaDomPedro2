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
 *     digitação do mesmo código não cria nada: avisa e mostra a conferência anterior.
 *     Correções são registradas à parte, com motivo, por quem pode gerenciar o inventário.
 *   * Divergências (local, estado, situação) e ausências nunca são resolvidas sozinhas:
 *     exigem uma decisão explícita, e o inventário só é concluído depois de todas decididas.
 *     Exemplares não conferidos NÃO viram extraviados automaticamente.
 *   * Exemplares novos só são criados por uma ação explícita na tela de conferência.
 *   * Empréstimos ativos são respeitados: um exemplar emprestado não é considerado ausente
 *     e nada no inventário muda a situação dele.
 *   * Migração 110: o inventário também confere registros ainda SEM exemplar (linhas importadas
 *     das planilhas, sem código BDP). Status de cada item, independente dos alertas cadastrais:
 *       Pendente → Encontrado (conferido_em) | Não localizado (nao_localizado_em, por decisão
 *       explícita da equipe, depois de procurar na estante/setor — nunca automático).
 *     "Não localizado" pode ser desfeito (voltar a Pendente) ou corrigido ao encontrar o livro.
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
            $previstos = $this->executar("INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, previsto, situacao_esperada, localizacao_esperada_id, estado_esperado)
                                          SELECT ?, e.id, e.livro_id, 1, e.situacao, e.localizacao_id, e.estado_conservacao
                                          FROM exemplares e
                                          JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                                          LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                                          WHERE e.situacao NOT IN ('baixado', 'extraviado')$setorSql", $params);
            // Registros importados de planilha que ainda não têm exemplar: um item "Pendente" por linha
            // da planilha. Eles não têm localização cadastrada, por isso só entram no inventário do
            // acervo inteiro. Livros cadastrados à mão sem exemplar não entram (nada a procurar).
            if ($setorSql === '') {
                $previstos += $this->incluirRegistrosSemExemplar($id);
            }
            $this->registrarAcao($responsavelId, 'inventario.iniciar', 'inventario', $id, ['titulo' => $inv['titulo'], 'previstos' => $previstos]);
            return $previstos;
        }));
    }

    /** Inclui no inventário as linhas importadas cujo livro ainda não tem exemplar (registros a conferir). @return int incluídos */
    public function incluirRegistrosSemExemplar(int $id): int
    {
        $n = $this->executar("INSERT IGNORE INTO inventario_itens (inventario_id, exemplar_id, livro_id, importacao_item_id, previsto)
                              SELECT ?, NULL, r.livro_id, r.importacao_item_id, 1
                              FROM registros_legados r
                              JOIN livros l ON l.id = r.livro_id AND l.arquivado_em IS NULL
                              JOIN importacao_itens it ON it.id = r.importacao_item_id AND it.resultado_importacao <> 'vinculado'
                              WHERE NOT EXISTS (SELECT 1 FROM exemplares e WHERE e.livro_id = l.id)
                                AND NOT EXISTS (SELECT 1 FROM inventario_itens x WHERE x.inventario_id = ? AND x.importacao_item_id = r.importacao_item_id)", [$id, $id]);
        return $n;
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
                             SELECT ?, i.livro_id, SUM(i.previsto OR i.cadastrado_no_inventario), SUM(i.conferido_em IS NOT NULL),
                                    CONCAT(?, IF(SUM(i.cadastrado_no_inventario) > 0, CONCAT(' · ', SUM(i.cadastrado_no_inventario), ' exemplar(es) cadastrado(s) na conferência'), '')),
                                    ?
                             FROM inventario_itens i
                             WHERE i.inventario_id = ? GROUP BY i.livro_id",
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
                    $this->executar('INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, previsto, situacao_esperada, localizacao_esperada_id, estado_esperado) VALUES (?, ?, ?, 0, ?, ?, ?)',
                        [$inventarioId, $exemplarId, $ex['livro_id'], $ex['situacao'], $ex['localizacao_id'], $ex['estado_conservacao']]);
                    $item = ['id' => (int)$this->pdo->lastInsertId()];
                }
                $localAtual = $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null;
                $localEncontrado ??= $localAtual;
                $divLocal = $localAtual !== null && $localEncontrado !== null && $localEncontrado !== $localAtual;
                $divEstado = $estado !== $ex['estado_conservacao'];
                $divSituacao = in_array($ex['situacao'], ['emprestado', 'extraviado', 'baixado'], true) || $ex['emprestimo_aberto'] !== null;

                // Encontrado: se estava "Não localizado", a marcação é desfeita (fica no histórico do exemplar).
                $this->executar('UPDATE inventario_itens SET conferido_em = NOW(), conferido_por = ?, localizacao_encontrada_id = ?, estado_encontrado = ?, observacao = ?,
                                        divergencia_local = ?, divergencia_estado = ?, divergencia_situacao = ?, nao_localizado_em = NULL, nao_localizado_por = NULL, nao_localizado_obs = NULL WHERE id = ?',
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
            // Dois registros do mesmo código ao mesmo tempo: o índice único segura a segunda.
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
                $this->executar('INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, previsto, cadastrado_no_inventario, conferido_em, conferido_por,
                                                               localizacao_encontrada_id, estado_encontrado, observacao)
                                 VALUES (?, ?, ?, 0, 1, NOW(), ?, ?, ?, ?)', [$inventarioId, $c['id'], $livroId, $responsavelId, $ex['localizacao_id'], $ex['estado_conservacao'], $obs]);
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
            if ($item['exemplar_id'] === null) {
                // Registro ainda sem exemplar: só cabe "manter sem alteração" (procurar depois) ou "Não localizado".
                if ($item['conferido_em'] !== null || $decisao !== 'manter_sem_alteracao') {
                    throw new AcervoException('Este registro ainda não tem exemplar. Escolha "Manter sem alteração" ou marque-o como não localizado.');
                }
                $this->executar('UPDATE inventario_itens SET decisao = ?, decisao_observacao = ?, decidido_por = ?, decidido_em = NOW() WHERE id = ?', [$decisao, $obs, $responsavelId, $itemId]);
                $this->registrarAcao($responsavelId, 'inventario.decidir', 'inventario', $invId, ['item_id' => $itemId, 'decisao' => $decisao]);
                return;
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
                                  WHERE i.inventario_id = ? AND i.previsto = 1 AND i.conferido_em IS NULL AND i.decisao IS NULL AND i.nao_localizado_em IS NULL
                                    AND NOT EXISTS (SELECT 1 FROM emprestimos m WHERE m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL)",
                [$obs ?? 'Não localizados: mantidos sem alteração', $responsavelId, $inventarioId]);
            $this->registrarAcao($responsavelId, 'inventario.manter_ausentes', 'inventario', $inventarioId, ['itens' => $n]);
            return $n;
        });
    }

    /**
     * Marca como "Não localizado" os itens escolhidos, depois de a equipe terminar de procurar na
     * estante ou no setor. Só itens ainda pendentes; exemplares emprestados ficam de fora.
     * A situação do exemplar NÃO muda (não vira extraviado): é só o resultado da conferência,
     * e pode ser desfeito. @return int itens marcados
     */
    public function marcarNaoLocalizados(int $inventarioId, array $itemIds, string $observacao, int $responsavelId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn ($v) => $v > 0)));
        if (!$ids) {
            throw new AcervoException('Selecione pelo menos um livro para marcar como não localizado.');
        }
        $ids = array_slice($ids, 0, 2000);
        $obs = self::textoLimpo($observacao);
        return $this->transacao(function () use ($inventarioId, $ids, $obs, $responsavelId) {
            $inv = $this->bloquearInventario($inventarioId);
            $this->exigirStatus($inv, ['em_andamento', 'pausado'], 'alterado');
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $n = $this->executar("UPDATE inventario_itens i SET i.nao_localizado_em = NOW(), i.nao_localizado_por = ?, i.nao_localizado_obs = ?
                                  WHERE i.inventario_id = ? AND i.id IN ($marcas) AND i.conferido_em IS NULL AND i.nao_localizado_em IS NULL
                                    AND NOT EXISTS (SELECT 1 FROM emprestimos m WHERE m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL)",
                [$responsavelId, $obs, $inventarioId, ...$ids]);
            $this->registrarAcao($responsavelId, 'inventario.nao_localizados', 'inventario', $inventarioId, ['itens' => $n, 'observacao' => $obs]);
            return $n;
        });
    }

    /**
     * Corrige o resultado de um item: volta a "Pendente" para procurar ou conferir de novo.
     *   * "Não localizado" → Pendente: basta pedir (a marcação é desfeita).
     *   * "Encontrado" → Pendente: exige motivo e não pode ter decisão já aplicada ao cadastro.
     *     O exemplar criado na conferência continua existindo (códigos BDP não são apagados).
     */
    public function voltarPendente(int $itemId, int $responsavelId, string $motivo = ''): void
    {
        $motivo = self::textoLimpo($motivo);
        $this->transacao(function () use ($itemId, $responsavelId, $motivo) {
            $item = $this->bloquearItem($itemId); // também confere se o inventário está aberto
            if ($item['nao_localizado_em'] !== null) {
                $this->executar('UPDATE inventario_itens SET nao_localizado_em = NULL, nao_localizado_por = NULL, nao_localizado_obs = NULL WHERE id = ?', [$itemId]);
                $de = 'nao_localizado';
            } elseif ($item['conferido_em'] !== null) {
                if ($motivo === null) {
                    throw new AcervoException('Informe o motivo para desfazer o "Encontrado".');
                }
                if ($item['decisao'] !== null) {
                    throw new AcervoException('Este item já tem uma decisão registrada. Use "Corrigir esta conferência".');
                }
                $this->executar('UPDATE inventario_itens SET conferido_em = NULL, conferido_por = NULL, localizacao_encontrada_id = NULL, estado_encontrado = NULL,
                                        divergencia_local = 0, divergencia_estado = 0, divergencia_situacao = 0, observacao = ? WHERE id = ?',
                    [mb_substr('Encontrado desfeito: ' . $motivo, 0, 500), $itemId]);
                if ($item['exemplar_id'] !== null) {
                    $this->exemplares->movimentacao((int)$item['exemplar_id'], 'inventario', 'Conferência desfeita no inventário nº ' . $item['inventario_id'] . ': ' . $motivo,
                        $responsavelId, (int)$item['inventario_id']);
                }
                $de = 'encontrado';
            } else {
                throw new AcervoException('Este item já está pendente.');
            }
            $this->registrarAcao($responsavelId, 'inventario.voltar_pendente', 'inventario', (int)$item['inventario_id'], ['item_id' => $itemId, 'de' => $de, 'motivo' => $motivo]);
        });
    }

    /**
     * Exemplar de um livro que não estava na lista (ou outro exemplar de uma obra já cadastrada),
     * com o código BDP do carimbo (vazio = próximo código livre). Fica Encontrado na hora.
     * @return array{id: int, codigo: string, item_id: int}
     */
    public function cadastrarExemplarComCodigo(int $inventarioId, int $livroId, ?string $codigo, array $dados, int $responsavelId): array
    {
        return $this->transacao(function () use ($inventarioId, $livroId, $codigo, $dados, $responsavelId) {
            $this->inventarioParaConferencia($inventarioId);
            $c = $this->exemplares->cadastrarComCodigo($livroId, $codigo, $dados + ['situacao' => 'disponivel'], $responsavelId, $inventarioId);
            $ex = $this->linha('SELECT localizacao_id, estado_conservacao FROM exemplares WHERE id = ?', [$c['id']]);
            $this->executar('INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, previsto, cadastrado_no_inventario, conferido_em, conferido_por,
                                                           localizacao_encontrada_id, estado_encontrado, observacao)
                             VALUES (?, ?, ?, 0, 1, NOW(), ?, ?, ?, ?)', [$inventarioId, $c['id'], $livroId, $responsavelId, $ex['localizacao_id'], $ex['estado_conservacao'],
                self::textoLimpo($dados['observacoes'] ?? null)]);
            $itemId = (int)$this->pdo->lastInsertId();
            $this->executar('UPDATE exemplares SET conferido_em = NOW() WHERE id = ?', [$c['id']]);
            return $c + ['item_id' => $itemId];
        });
    }

    /**
     * Registro sem exemplar encontrado na estante: cria o exemplar com o código BDP do carimbo
     * (ou o próximo código livre, se o campo ficar vazio) e marca o item como Encontrado.
     * Clique repetido não cria outro exemplar: o item bloqueado já aparece como encontrado.
     * @return array{item: array, codigo: string, exemplar_id: int}
     */
    public function encontrarRegistro(int $itemId, array $dados, int $responsavelId): array
    {
        $estado = (string)($dados['estado_conservacao'] ?? 'bom');
        if (!in_array($estado, ExemplarService::CONSERVACAO, true)) {
            throw new AcervoException('Informe o estado de conservação encontrado.');
        }
        $obs = self::textoLimpo($dados['observacao'] ?? null);
        return $this->transacao(function () use ($itemId, $dados, $estado, $obs, $responsavelId) {
            $item = $this->bloquearItem($itemId);
            $invId = (int)$item['inventario_id'];
            $this->inventarioParaConferencia($invId);
            if ($item['conferido_em'] !== null) {
                throw $this->duplicada($itemId);
            }
            if ($item['exemplar_id'] !== null) {
                throw new AcervoException('Este item já tem exemplar. Confira-o pelo código BDP.');
            }
            $local = $this->exemplares->localizacaoValida($dados['localizacao_id'] ?? null);
            $criado = $this->exemplares->cadastrarComCodigo((int)$item['livro_id'], $dados['codigo'] ?? null, [
                'estado_conservacao' => $estado, 'situacao' => 'disponivel', 'localizacao_id' => $local, 'observacoes' => null,
            ], $responsavelId, $invId);
            $this->executar('UPDATE inventario_itens SET exemplar_id = ?, conferido_em = NOW(), conferido_por = ?, localizacao_encontrada_id = ?, estado_encontrado = ?, observacao = ?,
                                    estado_esperado = ?, nao_localizado_em = NULL, nao_localizado_por = NULL, nao_localizado_obs = NULL WHERE id = ?',
                [$criado['id'], $responsavelId, $local, $estado, $obs, $estado, $itemId]);
            $this->executar('UPDATE exemplares SET conferido_em = NOW() WHERE id = ?', [$criado['id']]);
            $this->exemplares->movimentacao($criado['id'], 'inventario', 'Encontrado no inventário nº ' . $invId . ' (registro da planilha sem código BDP)', $responsavelId, $invId,
                ['estado' => $estado, 'localizacao_id' => $local, 'item_id' => $itemId]);
            return ['item' => $this->repo->item($itemId), 'codigo' => $criado['codigo'], 'exemplar_id' => $criado['id']];
        });
    }

    /** Situação no inventário escolhida ao registrar um exemplar. */
    public const SITUACOES_REGISTRO = ['encontrado' => 'Encontrado', 'pendente' => 'Pendente'];

    /**
     * "+ Registrar exemplar": cadastra UM exemplar físico de uma obra JÁ VERIFICADA, com o código
     * BDP do carimbo (gerado por "Gerar código BDP" ou digitado), e o coloca neste inventário.
     *
     * Dados: codigo (obrigatório), estado_conservacao, localizacao_id, observacoes,
     *        situacao_inventario ('encontrado' ou 'pendente'),
     *        item_id (opcional): linha da planilha desta obra, ainda sem exemplar, que este
     *        exemplar representa. Assim o vínculo com a linha original é preservado e a linha
     *        deixa de ficar pendente. Sem item_id, é um exemplar a mais da obra.
     *
     * Nada é deduzido da quantidade da planilha e nenhuma obra é unida a outra.
     * @return array{id: int, codigo: string, item_id: int, situacao: string}
     */
    public function registrarExemplar(int $inventarioId, int $livroId, array $dados, int $responsavelId): array
    {
        $codigoTexto = trim((string)($dados['codigo'] ?? ''));
        if ($codigoTexto === '') {
            throw new AcervoException('Informe o código BDP do carimbo ou clique em "Gerar código BDP".');
        }
        $situacao = (string)($dados['situacao_inventario'] ?? 'encontrado');
        if (!isset(self::SITUACOES_REGISTRO[$situacao])) {
            throw new AcervoException('Escolha a situação do exemplar no inventário (Encontrado ou Pendente).');
        }
        $estado = (string)($dados['estado_conservacao'] ?? 'bom');
        if (!in_array($estado, ExemplarService::CONSERVACAO, true)) {
            throw new AcervoException('Escolha o estado de conservação do exemplar.');
        }
        $obs = self::textoLimpo($dados['observacoes'] ?? null);
        $itemId = (int)($dados['item_id'] ?? 0) ?: null;
        $encontrado = $situacao === 'encontrado';

        return $this->transacao(function () use ($inventarioId, $livroId, $codigoTexto, $estado, $obs, $itemId, $encontrado, $situacao, $responsavelId, $dados) {
            $this->inventarioParaConferencia($inventarioId);
            $livro = $this->linha('SELECT id, titulo, dados_verificados_em, arquivado_em FROM livros WHERE id = ? FOR UPDATE', [$livroId]);
            if (!$livro || $livro['arquivado_em'] !== null) {
                throw new AcervoException('Obra não encontrada ou arquivada.');
            }
            if ($livro['dados_verificados_em'] === null) {
                throw new AcervoException('Verifique os dados da obra antes de registrar exemplares: clique em "Verificar e corrigir dados" e depois em "Salvar e marcar dados como verificados".');
            }
            $item = null;
            if ($itemId !== null) {
                $item = $this->bloquearItem($itemId);
                if ((int)$item['inventario_id'] !== $inventarioId || (int)$item['livro_id'] !== $livroId) {
                    throw new AcervoException('Esta linha da planilha não pertence a esta obra neste inventário.');
                }
                if ($item['exemplar_id'] !== null || $item['conferido_em'] !== null) {
                    throw new AcervoException('Esta linha da planilha já está ligada a um exemplar. Escolha outra linha ou registre como exemplar a mais.');
                }
            }
            $local = $this->exemplares->localizacaoValida($dados['localizacao_id'] ?? null);
            $c = $this->exemplares->cadastrarComCodigo($livroId, $codigoTexto, [
                'estado_conservacao' => $estado, 'situacao' => $encontrado ? 'disponivel' : 'aguardando', 'localizacao_id' => $local, 'observacoes' => $obs,
            ], $responsavelId, $inventarioId);

            if ($item !== null) {
                // A linha da planilha passa a ser este exemplar (o vínculo importacao_item_id continua).
                $this->executar('UPDATE inventario_itens SET exemplar_id = ?, situacao_esperada = ?, estado_esperado = ?, localizacao_esperada_id = ?,
                                        conferido_em = ' . ($encontrado ? 'NOW()' : 'NULL') . ', conferido_por = ?, localizacao_encontrada_id = ?, estado_encontrado = ?, observacao = ?,
                                        nao_localizado_em = NULL, nao_localizado_por = NULL, nao_localizado_obs = NULL WHERE id = ?',
                    [$c['id'], $encontrado ? 'disponivel' : 'aguardando', $estado, $local, $encontrado ? $responsavelId : null, $encontrado ? $local : null, $encontrado ? $estado : null, $obs, $item['id']]);
                $novoItem = (int)$item['id'];
            } else {
                $this->executar('INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, previsto, cadastrado_no_inventario, situacao_esperada, localizacao_esperada_id, estado_esperado,
                                                               conferido_em, conferido_por, localizacao_encontrada_id, estado_encontrado, observacao)
                                 VALUES (?, ?, ?, 0, 1, ?, ?, ?, ' . ($encontrado ? 'NOW()' : 'NULL') . ', ?, ?, ?, ?)',
                    [$inventarioId, $c['id'], $livroId, $encontrado ? 'disponivel' : 'aguardando', $local, $estado, $encontrado ? $responsavelId : null, $encontrado ? $local : null, $encontrado ? $estado : null, $obs]);
                $novoItem = (int)$this->pdo->lastInsertId();
            }
            if ($encontrado) {
                $this->executar('UPDATE exemplares SET conferido_em = NOW() WHERE id = ?', [$c['id']]);
            }
            $linha = $item !== null ? $this->valor('SELECT linha FROM registros_legados WHERE importacao_item_id = ? LIMIT 1', [$item['importacao_item_id']]) : null;
            $this->exemplares->movimentacao($c['id'], 'inventario', 'Registrado no inventário nº ' . $inventarioId . ' como ' . self::SITUACOES_REGISTRO[$situacao]
                . ($item !== null ? ' (' . ($linha ? 'linha ' . (int)$linha . ' da planilha' : 'registro sem código') . ')' : ''), $responsavelId, $inventarioId,
                ['estado' => $estado, 'localizacao_id' => $local, 'item_id' => $novoItem]);
            $this->registrarAcao($responsavelId, 'inventario.registrar_exemplar', 'inventario', $inventarioId,
                ['livro_id' => $livroId, 'codigo' => $c['codigo'], 'situacao' => $situacao, 'item_planilha' => $item['id'] ?? null]);
            return $c + ['item_id' => $novoItem, 'situacao' => $situacao];
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
