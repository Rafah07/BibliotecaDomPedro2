<?php

namespace Biblioteca\Services;

use Biblioteca\Support\CodigoBdp;
use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Cadastro e manutenção dos exemplares físicos (uma unidade real de um livro).
 *
 * Regras:
 *   * Um exemplar só nasce por uma ação explícita de quem está com o livro em mãos
 *     (cadastro manual ou conferência do inventário). Nada aqui olha a quantidade das planilhas.
 *   * O código BDP-000000 vem da sequência exemplar_codigo_seq, com a linha bloqueada
 *     (SELECT ... FOR UPDATE): dois cadastros ao mesmo tempo recebem códigos diferentes, e o
 *     índice único do código é a segunda barreira. A sequência nunca volta, então o código de
 *     um exemplar baixado não é reaproveitado (migração 070).
 *   * Cada alteração grava uma linha em movimentacoes (histórico do exemplar) e uma ação
 *     administrativa, na mesma transação.
 *   * Exemplar emprestado (ou com empréstimo aberto) não muda de situação, não é baixado e não
 *     é marcado como extraviado: a devolução é registrada no módulo de empréstimos.
 *   * A situação "emprestado" só é definida pelo módulo de empréstimos.
 *   * Baixa é definitiva; não há exclusão de exemplares.
 *   * Preparo (migração 150): todo exemplar novo nasce "Aguardando carimbo" (código gerado pelo
 *     sistema) ou "Carimbado" (código digitado do carimbo do livro), entra no lote de cadastramento
 *     aberto de quem registrou (com a ordem dentro do lote) e, se ainda falta carimbo ou localização
 *     definitiva, fica "Aguardando liberação" até o preparo terminar (regras em Configurações).
 *   * Operação atual (definirOperacao): cada movimentação gravada leva o número da operação, para
 *     que "Desfazer ações recentes" saiba exatamente o que foi alterado.
 */
final class ExemplarService
{
    use ConsultasPdo;

    /**
     * Estados de conservação aceitos. "nao_avaliado" é o inicial (ninguém avaliou ainda); os demais
     * são avaliações feitas por uma pessoa (gravam conservacao_avaliada_em/por, migração 140).
     * "danificado" e "necessita_reparo" continuam aceitos para os dados antigos.
     */
    public const CONSERVACAO = ['nao_avaliado', 'excelente', 'bom', 'regular', 'ruim', 'danificado', 'necessita_reparo'];

    /** Opções oferecidas nos formulários (as antigas só aparecem quando já estão gravadas). */
    public const CONSERVACAO_OPCOES = ['nao_avaliado', 'excelente', 'bom', 'regular', 'ruim'];

    public const NAO_AVALIADO = 'nao_avaliado';

    /** Situações que podem ser escolhidas no cadastro e na alteração manual. */
    public const SITUACOES_MANUAIS = ['disponivel', 'aguardando', 'manutencao', 'danificado'];

    public const LIMITE_POR_CADASTRO = 20;

    private const CODIGO_MAXIMO = 999999;

    public const CARIMBO = ['aguardando' => 'Aguardando carimbo', 'carimbado' => 'Carimbado'];

    /**
     * Regras de liberação (Configurações → Preparo dos exemplares). O serviço sozinho não segura nada
     * (compatível com quem o usa direto, como scripts e testes antigos); o painel SEMPRE aplica a
     * regra configurada por exemplares_servico(), cujo padrão é só liberar o exemplar carimbado e
     * com estante definitiva.
     */
    private bool $emprestarSemCarimbo = true;
    private bool $emprestarSemOrganizacao = true;

    /** Operação em andamento (OperacaoService): gravada em cada movimentação. */
    private ?int $operacaoId = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function definirRegrasPreparo(bool $emprestarSemCarimbo, bool $emprestarSemOrganizacao): void
    {
        $this->emprestarSemCarimbo = $emprestarSemCarimbo;
        $this->emprestarSemOrganizacao = $emprestarSemOrganizacao;
    }

    public function definirOperacao(?int $id): void
    {
        $this->operacaoId = $id;
    }

    public function operacaoAtual(): ?int
    {
        return $this->operacaoId;
    }

    /**
     * Cadastra $quantidade exemplares físicos do livro, todos com os mesmos dados.
     * Dados: estado_conservacao, situacao, localizacao_id (ou null), observacoes.
     * @return list<array{id: int, codigo: string}>
     */
    public function cadastrar(int $livroId, array $dados, int $responsavelId, int $quantidade = 1, ?int $inventarioId = null): array
    {
        if ($quantidade < 1 || $quantidade > self::LIMITE_POR_CADASTRO) {
            throw new AcervoException('Informe de 1 a ' . self::LIMITE_POR_CADASTRO . ' exemplares por cadastro.');
        }
        $estado = (string)($dados['estado_conservacao'] ?? '') ?: self::NAO_AVALIADO; // sem avaliação: "Não avaliado"
        $situacao = (string)($dados['situacao'] ?? '');
        if (!in_array($estado, self::CONSERVACAO, true)) {
            throw new AcervoException('Escolha o estado de conservação do exemplar.');
        }
        if (!in_array($situacao, self::SITUACOES_MANUAIS, true)) {
            throw new AcervoException('Escolha a situação inicial do exemplar (disponível, aguardando, em manutenção ou danificado).');
        }
        $obs = self::textoLimpo($dados['observacoes'] ?? null);
        $localId = $this->localizacaoValida($dados['localizacao_id'] ?? null);
        $carimbo = self::carimboValido($dados['carimbo'] ?? null) ?? 'aguardando'; // código gerado agora: ainda não está no carimbo

        return $this->transacao(function () use ($livroId, $estado, $situacao, $obs, $localId, $responsavelId, $quantidade, $inventarioId, $carimbo) {
            $livro = $this->linha('SELECT id, titulo, arquivado_em FROM livros WHERE id = ? LOCK IN SHARE MODE', [$livroId]);
            if (!$livro) {
                throw new AcervoException('Livro não encontrado.');
            }
            if ($livro['arquivado_em'] !== null) {
                throw new AcervoException('Este livro está arquivado. Restaure o cadastro antes de registrar exemplares.');
            }
            // Bloqueia a sequência até o fim da transação: cadastros simultâneos esperam aqui.
            $ultimo = (int)$this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            if ($ultimo + $quantidade > self::CODIGO_MAXIMO) {
                throw new AcervoException('A numeração BDP chegou ao limite de ' . self::CODIGO_MAXIMO . ' exemplares.');
            }
            $this->executar('UPDATE exemplar_codigo_seq SET ultimo = ? WHERE id = 1', [$ultimo + $quantidade]);
            [$situacaoFinal, $pendente] = $this->situacaoInicial($situacao, $carimbo, $localId);
            [$loteId, $ordem] = $this->reservarOrdemNoLote($responsavelId, $quantidade);

            $criados = [];
            for ($i = 1; $i <= $quantidade; $i++) {
                $codigo = CodigoBdp::formatar($ultimo + $i);
                $id = $this->inserir($livroId, $codigo, $localId, $estado, $responsavelId, $situacaoFinal, $pendente, $obs, $inventarioId, $carimbo, $loteId, $ordem + $i);
                $this->movimentacao($id, 'cadastro', ($inventarioId ? 'Exemplar cadastrado durante a conferência do inventário nº ' . $inventarioId : 'Exemplar físico cadastrado')
                    . ' · ' . self::CARIMBO[$carimbo] . ($pendente ? ' · aguardando liberação para empréstimo' : ''),
                    $responsavelId, $inventarioId, ['estado' => $estado, 'situacao' => $situacaoFinal, 'localizacao_id' => $localId, 'carimbo' => $carimbo, 'lote_id' => $loteId]);
                $criados[] = ['id' => $id, 'codigo' => $codigo, 'carimbo' => $carimbo, 'situacao' => $situacaoFinal, 'lote_id' => $loteId, 'lote_ordem' => $ordem + $i];
            }
            $this->lembrarLocal($responsavelId, $localId);
            $this->registrarAcao($responsavelId, 'exemplar.cadastrar', 'livro', $livroId, [
                'titulo' => $livro['titulo'], 'codigos' => array_column($criados, 'codigo'), 'inventario_id' => $inventarioId,
            ]);
            return $criados;
        });
    }

    /**
     * Cadastra UM exemplar com o código BDP já carimbado no livro (conferência do inventário).
     * Código vazio = próximo número livre da sequência. O código precisa estar completo
     * (BDP-000000) e não pode pertencer a outro exemplar, mesmo baixado: a checagem dá uma
     * mensagem clara, e o índice único do código continua sendo a última barreira.
     * O gatilho exemplares_codigo_novo avança a sequência quando o número digitado é maior.
     * @return array{id: int, codigo: string}
     */
    public function cadastrarComCodigo(int $livroId, ?string $codigo, array $dados, int $responsavelId, ?int $inventarioId = null): array
    {
        $texto = trim((string)$codigo);
        if ($texto === '') {
            return $this->cadastrar($livroId, $dados, $responsavelId, 1, $inventarioId)[0];
        }
        $normal = CodigoBdp::normalizar($texto);
        if ($normal === null) {
            throw new AcervoException(CodigoBdp::mensagemInvalido($texto));
        }
        $estado = (string)($dados['estado_conservacao'] ?? '') ?: self::NAO_AVALIADO;
        $situacao = (string)($dados['situacao'] ?? 'disponivel');
        if (!in_array($estado, self::CONSERVACAO, true)) {
            throw new AcervoException('Escolha o estado de conservação do exemplar.');
        }
        if (!in_array($situacao, self::SITUACOES_MANUAIS, true)) {
            throw new AcervoException('Escolha a situação inicial do exemplar.');
        }
        $obs = self::textoLimpo($dados['observacoes'] ?? null);
        $localId = $this->localizacaoValida($dados['localizacao_id'] ?? null);
        $processo = isset($dados['processo_bdp']) ? (string)$dados['processo_bdp'] : null;
        $carimboPedido = self::carimboValido($dados['carimbo'] ?? null);

        return $this->transacao(function () use ($livroId, $normal, $estado, $situacao, $obs, $localId, $responsavelId, $inventarioId, $processo, $carimboPedido) {
            $livro = $this->linha('SELECT id, titulo, arquivado_em FROM livros WHERE id = ? LOCK IN SHARE MODE', [$livroId]);
            if (!$livro) {
                throw new AcervoException('Livro não encontrado.');
            }
            if ($livro['arquivado_em'] !== null) {
                throw new AcervoException('Este livro está arquivado. Restaure o cadastro antes de registrar exemplares.');
            }
            // Mesma ordem de travas do cadastro automático (sequência primeiro): evita impasse.
            $this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            $existente = $this->linha('SELECT e.id, e.situacao, l.titulo FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE e.codigo_individual = ?', [$normal]);
            if ($existente) {
                throw new AcervoException('O código ' . $normal . ' já pertence a outro exemplar ("'
                    . ($existente['titulo'] ?? 'Sem título') . '"' . ($existente['situacao'] === 'baixado' ? ', já baixado' : '')
                    . '). Confira o carimbo: cada código BDP é único e não é reaproveitado.');
            }
            // Código gerado ("Gerar código BDP"): só para quem gerou, no formulário que gerou, e não cancelado
            // (CodigoBdpService::motivoRecusa). A linha da reserva fica bloqueada até o fim da transação.
            $reserva = $this->linha('SELECT r.*, a.nome FROM exemplar_codigo_reservas r LEFT JOIN administradores a ON a.id = r.reservado_por WHERE r.codigo = ? AND r.usado_em IS NULL FOR UPDATE', [$normal]);
            if ($reserva && ($motivo = CodigoBdpService::motivoRecusa($reserva, $responsavelId, $processo)) !== null) {
                throw new AcervoException($motivo);
            }
            // Código gerado pelo sistema ("Gerar código BDP"): ainda não está no carimbo. Código digitado sem
            // reserva: veio do carimbo que o livro já tem. O formulário pode dizer explicitamente qual é o caso.
            $carimbo = $carimboPedido ?? ($reserva ? 'aguardando' : 'carimbado');
            [$situacaoFinal, $pendente] = $this->situacaoInicial($situacao, $carimbo, $localId);
            [$loteId, $ordem] = $this->reservarOrdemNoLote($responsavelId, 1);
            try {
                $id = $this->inserir($livroId, $normal, $localId, $estado, $responsavelId, $situacaoFinal, $pendente, $obs, $inventarioId, $carimbo, $loteId, $ordem + 1);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new AcervoException('O código ' . $normal . ' acabou de ser usado em outro exemplar. Confira o carimbo.');
                }
                throw $e;
            }
            $this->executar('UPDATE exemplar_codigo_reservas SET usado_em = NOW(), exemplar_id = ? WHERE codigo = ? AND usado_em IS NULL', [$id, $normal]);
            $this->movimentacao($id, 'cadastro', ($inventarioId ? 'Exemplar cadastrado na conferência do inventário nº ' . $inventarioId : 'Exemplar físico cadastrado')
                . ' com o código ' . $normal . ' · ' . self::CARIMBO[$carimbo] . ($pendente ? ' · aguardando liberação para empréstimo' : ''), $responsavelId, $inventarioId,
                ['estado' => $estado, 'situacao' => $situacaoFinal, 'localizacao_id' => $localId, 'codigo_informado' => !$reserva, 'carimbo' => $carimbo, 'lote_id' => $loteId]);
            $this->lembrarLocal($responsavelId, $localId);
            $this->registrarAcao($responsavelId, 'exemplar.cadastrar', 'livro', $livroId, [
                'titulo' => $livro['titulo'], 'codigos' => [$normal], 'inventario_id' => $inventarioId, 'codigo_informado' => true,
            ]);
            return ['id' => $id, 'codigo' => $normal, 'carimbo' => $carimbo, 'situacao' => $situacaoFinal, 'lote_id' => $loteId, 'lote_ordem' => $ordem + 1];
        });
    }

    /** Altera o estado de conservação e as observações. */
    public function editar(int $id, array $dados, int $responsavelId): void
    {
        $estado = (string)($dados['estado_conservacao'] ?? '');
        if (!in_array($estado, self::CONSERVACAO, true)) {
            throw new AcervoException('Escolha o estado de conservação do exemplar.');
        }
        $obs = self::textoLimpo($dados['observacoes'] ?? null);
        $this->transacao(function () use ($id, $estado, $obs, $responsavelId) {
            $ex = $this->bloquear($id);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Exemplares baixados não podem ser alterados.');
            }
            if ($ex['estado_conservacao'] === $estado && (string)$ex['observacoes'] === (string)$obs) {
                return;
            }
            $this->executar('UPDATE exemplares SET estado_conservacao = ?, observacoes = ? WHERE id = ?', [$estado, $obs, $id]);
            if ($ex['estado_conservacao'] !== $estado) {
                $this->marcarAvaliacao($id, $estado, $responsavelId);
            }
            if ($ex['estado_conservacao'] !== $estado) {
                $this->movimentacao($id, 'conservacao', 'Estado de conservação: ' . self::rotuloConservacao($ex['estado_conservacao']) . ' → ' . self::rotuloConservacao($estado),
                    $responsavelId, null, ['de' => $ex['estado_conservacao'], 'para' => $estado]);
            }
            $this->registrarAcao($responsavelId, 'exemplar.editar', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'estado' => $estado]);
        });
    }

    /** Só o estado de conservação (usado nas decisões do inventário). */
    public function alterarConservacao(int $id, string $estado, int $responsavelId, ?int $inventarioId = null): void
    {
        if (!in_array($estado, self::CONSERVACAO, true)) {
            throw new AcervoException('Estado de conservação inválido.');
        }
        $this->transacao(function () use ($id, $estado, $responsavelId, $inventarioId) {
            $ex = $this->bloquear($id);
            if ($ex['situacao'] === 'baixado') {
                return;
            }
            if ($ex['estado_conservacao'] === $estado) {
                // Mesma classificação, mas avaliada agora por uma pessoa: fica registrada a avaliação.
                if ($estado !== self::NAO_AVALIADO && $ex['conservacao_avaliada_em'] === null) {
                    $this->marcarAvaliacao($id, $estado, $responsavelId);
                    $this->movimentacao($id, 'conservacao', 'Conservação avaliada: ' . self::rotuloConservacao($estado), $responsavelId, $inventarioId, ['de' => $estado, 'para' => $estado]);
                }
                return;
            }
            $this->executar('UPDATE exemplares SET estado_conservacao = ? WHERE id = ?', [$estado, $id]);
            $this->marcarAvaliacao($id, $estado, $responsavelId);
            $this->movimentacao($id, 'conservacao', 'Estado de conservação: ' . self::rotuloConservacao($ex['estado_conservacao']) . ' → ' . self::rotuloConservacao($estado),
                $responsavelId, $inventarioId, ['de' => $ex['estado_conservacao'], 'para' => $estado]);
        });
    }

    /**
     * Transfere o exemplar para outra localização (ou deixa sem localização, com null).
     * Só este exemplar muda: os outros exemplares do livro continuam onde estão.
     */
    public function transferir(int $id, ?int $localizacaoId, int $responsavelId, string $motivo = '', ?int $inventarioId = null): bool
    {
        $localId = $this->localizacaoValida($localizacaoId);
        $motivo = self::textoLimpo($motivo);
        return $this->transacao(function () use ($id, $localId, $responsavelId, $motivo, $inventarioId) {
            $ex = $this->bloquear($id);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Exemplares baixados não mudam de localização.');
            }
            $anterior = $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null;
            if ($anterior === $localId) {
                return false;
            }
            $this->executar('UPDATE exemplares SET localizacao_id = ? WHERE id = ?', [$localId, $id]);
            $texto = ($anterior === null ? 'Organizado: ' : ($localId === null ? 'Voltou para a área de triagem: ' : 'Localização: '))
                . $this->localTexto($anterior) . ' → ' . $this->localTexto($localId) . ($motivo ? ' · ' . $motivo : '');
            $this->movimentacao($id, 'localizacao', $texto, $responsavelId, $inventarioId, ['de' => $anterior, 'para' => $localId]);
            $this->registrarAcao($responsavelId, 'exemplar.transferir', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'de' => $anterior, 'para' => $localId, 'operacao_id' => $this->operacaoId]);
            $this->lembrarLocal($responsavelId, $localId);
            if ($localId !== null) {
                $this->concluirPreparo($id, $responsavelId, $inventarioId);
            }
            return true;
        });
    }

    /**
     * "Marcar como carimbado": a equipe escreveu o código BDP no carimbo do livro. Exemplares já
     * carimbados ou baixados são ignorados. Se o preparo terminou, o exemplar é liberado.
     * @param list<int> $ids
     * @return list<string> códigos marcados agora
     */
    public function marcarCarimbado(array $ids, int $responsavelId, string $observacao = ''): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            throw new AcervoException('Selecione pelo menos um exemplar.');
        }
        if (count($ids) > 1000) {
            throw new AcervoException('Marque no máximo 1.000 exemplares de cada vez.');
        }
        $obs = self::textoLimpo($observacao, 255);
        return $this->transacao(function () use ($ids, $responsavelId, $obs) {
            $marcados = [];
            sort($ids); // mesma ordem de travas em operações simultâneas
            foreach ($ids as $id) {
                $ex = $this->bloquear($id);
                if ($ex['carimbo_status'] === 'carimbado' || $ex['situacao'] === 'baixado') {
                    continue;
                }
                $this->executar("UPDATE exemplares SET carimbo_status = 'carimbado', carimbado_em = NOW(), carimbado_por = ?" . ($obs !== null ? ', carimbo_observacao = ?' : '') . ' WHERE id = ?',
                    $obs !== null ? [$responsavelId, $obs, $id] : [$responsavelId, $id]);
                $this->movimentacao($id, 'carimbo', 'Carimbo confirmado: o código ' . $ex['codigo_individual'] . ' foi escrito no livro', $responsavelId, null, ['de' => 'aguardando', 'para' => 'carimbado']);
                $this->concluirPreparo($id, $responsavelId, null);
                $marcados[] = $ex['codigo_individual'];
            }
            if ($marcados) {
                $this->registrarAcao($responsavelId, 'exemplar.carimbo', 'exemplar', count($marcados) === 1 ? $ids[0] : null, ['total' => count($marcados), 'codigos' => array_slice($marcados, 0, 200), 'operacao_id' => $this->operacaoId]);
            }
            return $marcados;
        });
    }

    /** Volta para "Aguardando carimbo" (marcado por engano). Usado pelo "Desfazer". */
    public function voltarAguardandoCarimbo(int $id, int $responsavelId, string $motivo = ''): void
    {
        $this->transacao(function () use ($id, $responsavelId, $motivo) {
            $ex = $this->bloquear($id);
            if ($ex['carimbo_status'] !== 'carimbado') {
                return;
            }
            $this->executar("UPDATE exemplares SET carimbo_status = 'aguardando', carimbado_em = NULL, carimbado_por = NULL WHERE id = ?", [$id]);
            $this->movimentacao($id, 'carimbo', 'Voltou para Aguardando carimbo' . ($motivo !== '' ? ' · ' . $motivo : ''), $responsavelId, null, ['de' => 'carimbado', 'para' => 'aguardando']);
        });
    }

    /** Observação da Planilha de Códigos BDP (ex.: "carimbo borrado, refazer"). */
    public function observacaoCarimbo(int $id, string $texto, int $responsavelId): void
    {
        $obs = self::textoLimpo($texto, 255);
        $this->transacao(function () use ($id, $obs, $responsavelId) {
            $ex = $this->bloquear($id);
            $this->executar('UPDATE exemplares SET carimbo_observacao = ? WHERE id = ?', [$obs, $id]);
            $this->registrarAcao($responsavelId, 'exemplar.observacao_carimbo', 'exemplar', $id, ['codigo' => $ex['codigo_individual']]);
        });
    }

    /**
     * Preparo concluído? (carimbado e com localização definitiva, conforme as regras). Se sim e o
     * exemplar estava retido só pelo preparo, ele é liberado para empréstimo. Uma ocorrência que
     * bloqueia o empréstimo continua valendo: a liberação fica para a resolução da ocorrência.
     * Dentro de uma transação, com o exemplar já bloqueado.
     */
    public function concluirPreparo(int $id, int $responsavelId, ?int $inventarioId): bool
    {
        $ex = $this->linha('SELECT id, situacao, liberacao_pendente, carimbo_status, localizacao_id FROM exemplares WHERE id = ? FOR UPDATE', [$id]);
        if (!$ex || !(int)$ex['liberacao_pendente'] || !$this->preparoCompleto($ex)) {
            return false;
        }
        $bloqueio = (int)$this->valor("SELECT COUNT(*) FROM exemplar_ocorrencias WHERE exemplar_id = ? AND status = 'aberta' AND bloqueia_emprestimo = 1", [$id]);
        if ($ex['situacao'] !== 'aguardando' || $bloqueio > 0) {
            $this->executar('UPDATE exemplares SET liberacao_pendente = 0 WHERE id = ?', [$id]);
            return false;
        }
        $this->executar("UPDATE exemplares SET situacao = 'disponivel', liberacao_pendente = 0 WHERE id = ?", [$id]);
        $this->movimentacao($id, 'situacao', 'Situação: Aguardando liberação → Disponível · preparo concluído (carimbo e localização)', $responsavelId, $inventarioId,
            ['de' => 'aguardando', 'para' => 'disponivel', 'liberacao' => true]);
        return true;
    }

    /** Volta a reter o exemplar pelo preparo (usado só pelo "Desfazer" de uma liberação automática). */
    public function voltarAguardandoLiberacao(int $id, int $responsavelId, string $motivo): void
    {
        $this->transacao(function () use ($id, $responsavelId, $motivo) {
            $ex = $this->bloquear($id);
            $this->recusarSeEmprestado($ex);
            if ($ex['situacao'] !== 'disponivel') {
                return;
            }
            $this->executar("UPDATE exemplares SET situacao = 'aguardando', liberacao_pendente = 1 WHERE id = ?", [$id]);
            $this->movimentacao($id, 'situacao', 'Situação: Disponível → Aguardando liberação · ' . $motivo, $responsavelId, null, ['de' => 'disponivel', 'para' => 'aguardando', 'liberacao' => true]);
        });
    }

    /** O preparo (carimbo e localização) está completo, segundo as regras de liberação? */
    public function preparoCompleto(array $ex): bool
    {
        return ($this->emprestarSemCarimbo || $ex['carimbo_status'] === 'carimbado')
            && ($this->emprestarSemOrganizacao || $ex['localizacao_id'] !== null);
    }

    /**
     * Muda a situação para disponível, aguardando, em manutenção ou danificado.
     * Exemplar extraviado volta ao acervo por aqui (quando reencontrado); baixado, nunca.
     */
    public function alterarSituacao(int $id, string $nova, int $responsavelId, string $motivo = '', ?int $inventarioId = null): void
    {
        if (!in_array($nova, self::SITUACOES_MANUAIS, true)) {
            throw new AcervoException('Situação inválida. Empréstimos, baixas e extravios têm fluxos próprios.');
        }
        $motivo = self::textoLimpo($motivo);
        $this->transacao(function () use ($id, $nova, $responsavelId, $motivo, $inventarioId) {
            $ex = $this->bloquear($id);
            $this->recusarSeEmprestado($ex);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Exemplares baixados não voltam ao acervo.');
            }
            if ($ex['situacao'] === $nova) {
                return;
            }
            // Escolha manual da situação substitui a retenção automática do preparo.
            $this->executar('UPDATE exemplares SET situacao = ?, liberacao_pendente = 0 WHERE id = ?', [$nova, $id]);
            $tipo = $nova === 'manutencao' ? 'manutencao' : 'situacao';
            $texto = 'Situação: ' . self::rotuloSituacao($ex['situacao']) . ' → ' . self::rotuloSituacao($nova) . ($motivo ? ' · ' . $motivo : '');
            $this->movimentacao($id, $tipo, $texto, $responsavelId, $inventarioId, ['de' => $ex['situacao'], 'para' => $nova]);
            $this->registrarAcao($responsavelId, 'exemplar.situacao', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'de' => $ex['situacao'], 'para' => $nova]);
        });
    }

    /** Registra o extravio (exemplar não encontrado). Pode ser revertido com alterarSituacao(). */
    public function registrarExtravio(int $id, int $responsavelId, string $motivo, ?int $inventarioId = null): void
    {
        $motivo = self::textoLimpo($motivo);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo do extravio.');
        }
        $this->transacao(function () use ($id, $responsavelId, $motivo, $inventarioId) {
            $ex = $this->bloquear($id);
            $this->recusarSeEmprestado($ex);
            if (in_array($ex['situacao'], ['baixado', 'extraviado'], true)) {
                throw new AcervoException('Este exemplar já está ' . ($ex['situacao'] === 'baixado' ? 'baixado.' : 'registrado como extraviado.'));
            }
            $this->executar("UPDATE exemplares SET situacao = 'extraviado' WHERE id = ?", [$id]);
            $this->movimentacao($id, 'extravio', 'Registrado como extraviado · ' . $motivo, $responsavelId, $inventarioId, ['de' => $ex['situacao']]);
            $this->registrarAcao($responsavelId, 'exemplar.extravio', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'inventario_id' => $inventarioId]);
        });
    }

    /** Baixa definitiva: o exemplar sai do acervo, mas o código e o histórico ficam. */
    public function baixar(int $id, int $responsavelId, string $motivo): void
    {
        $motivo = self::textoLimpo($motivo, 255);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo da baixa.');
        }
        $this->transacao(function () use ($id, $responsavelId, $motivo) {
            $ex = $this->bloquear($id);
            $this->recusarSeEmprestado($ex);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Este exemplar já foi baixado.');
            }
            $this->executar("UPDATE exemplares SET situacao = 'baixado', baixado_em = NOW(), motivo_baixa = ? WHERE id = ?", [$motivo, $id]);
            $this->movimentacao($id, 'baixa', 'Baixa do acervo · ' . $motivo, $responsavelId, null, ['de' => $ex['situacao']]);
            $this->registrarAcao($responsavelId, 'exemplar.baixar', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'motivo' => $motivo]);
        });
    }

    /** Marca a data em que as etiquetas destes exemplares foram geradas. */
    public function marcarEtiquetas(array $ids, int $responsavelId, string $modelo): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            return;
        }
        $this->transacao(function () use ($ids, $responsavelId, $modelo) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $this->executar("UPDATE exemplares SET etiqueta_gerada_em = NOW() WHERE id IN ($marcas)", $ids);
            $codigos = $this->todas("SELECT codigo_individual FROM exemplares WHERE id IN ($marcas) ORDER BY codigo_individual", $ids);
            $this->registrarAcao($responsavelId, 'etiqueta.gerar', 'exemplar', count($ids) === 1 ? $ids[0] : null, [
                'modelo' => $modelo, 'total' => count($ids), 'codigos' => array_slice(array_column($codigos, 'codigo_individual'), 0, 200),
            ]);
        });
    }

    // ---------------------------------------------------------------

    /** Exemplar com a linha bloqueada até o fim da transação. */
    public function bloquear(int $id): array
    {
        $ex = $this->linha('SELECT e.*, (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL LIMIT 1) AS emprestimo_aberto
                            FROM exemplares e WHERE e.id = ? FOR UPDATE', [$id]);
        if (!$ex) {
            throw new AcervoException('Exemplar não encontrado.');
        }
        return $ex;
    }

    private function recusarSeEmprestado(array $ex): void
    {
        if ($ex['situacao'] === 'emprestado' || $ex['emprestimo_aberto'] !== null) {
            throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' possui empréstimo ativo. Registre a devolução no módulo de empréstimos primeiro.');
        }
    }

    /** 'aguardando' | 'carimbado' | null (não informado). */
    public static function carimboValido(mixed $valor): ?string
    {
        $valor = (string)($valor ?? '');
        return isset(self::CARIMBO[$valor]) ? $valor : null;
    }

    /**
     * Situação gravada no cadastro. Quem escolhe "Disponível" para um exemplar ainda sem carimbo ou
     * sem localização definitiva (e as regras não permitem emprestar assim) recebe "Aguardando
     * liberação", com liberacao_pendente = 1: a liberação acontece sozinha ao concluir o preparo.
     * @return array{0: string, 1: bool}
     */
    private function situacaoInicial(string $situacao, string $carimbo, ?int $localId): array
    {
        if ($situacao !== 'disponivel' || $this->preparoCompleto(['carimbo_status' => $carimbo, 'localizacao_id' => $localId])) {
            return [$situacao, false];
        }
        return ['aguardando', true];
    }

    private function inserir(int $livroId, string $codigo, ?int $localId, string $estado, int $responsavelId, string $situacao, bool $pendente, ?string $obs, ?int $inventarioId, string $carimbo, ?int $loteId, ?int $ordem): int
    {
        $this->executar('INSERT INTO exemplares (livro_id, codigo_individual, carimbo_status, carimbado_em, carimbado_por, lote_id, lote_ordem, localizacao_id, estado_conservacao,
                                                 conservacao_avaliada_em, conservacao_avaliada_por, situacao, liberacao_pendente, observacoes, cadastrado_por, inventario_id)
                         VALUES (?, ?, ?, ' . ($carimbo === 'carimbado' ? 'NOW(), ?' : 'NULL, NULL') . ', ?, ?, ?, ?, ' . ($estado === self::NAO_AVALIADO ? 'NULL, NULL' : 'NOW(), ?') . ', ?, ?, ?, ?, ?)',
            array_merge([$livroId, $codigo, $carimbo], $carimbo === 'carimbado' ? [$responsavelId] : [], [$loteId, $ordem, $localId, $estado],
                $estado === self::NAO_AVALIADO ? [] : [$responsavelId], [$situacao, $pendente ? 1 : 0, $obs, $responsavelId, $inventarioId]));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Lote de cadastramento aberto de quem registra (cria "Lote NNN" se não houver) e reserva
     * $quantidade posições seguidas nele. Chamado com a sequência BDP já travada: dois cadastros
     * simultâneos recebem ordens diferentes e o número de um lote novo não se repete.
     * @return array{0: int, 1: int} id do lote e a última ordem usada antes desta reserva
     */
    private function reservarOrdemNoLote(int $responsavelId, int $quantidade): array
    {
        $lote = $this->linha("SELECT id, total FROM cadastro_lotes WHERE responsavel_id = ? AND status = 'aberto' ORDER BY id DESC LIMIT 1 FOR UPDATE", [$responsavelId]);
        if (!$lote) {
            $numero = (int)$this->valor('SELECT COALESCE(MAX(numero), 0) + 1 FROM cadastro_lotes');
            $equipe = $this->valor('SELECT equipe FROM administradores WHERE id = ?', [$responsavelId]);
            $this->executar('INSERT INTO cadastro_lotes (numero, equipe, responsavel_id) VALUES (?, ?, ?)', [$numero, $equipe ?: null, $responsavelId]);
            $lote = ['id' => (int)$this->pdo->lastInsertId(), 'total' => 0];
        }
        $this->executar('UPDATE cadastro_lotes SET total = total + ? WHERE id = ?', [$quantidade, $lote['id']]);
        return [(int)$lote['id'], (int)$lote['total']];
    }

    /** Guarda a última prateleira usada por quem registrou ou moveu (sugestão do próximo registro). */
    private function lembrarLocal(int $responsavelId, ?int $localId): void
    {
        if ($localId !== null) {
            $this->executar('UPDATE administradores SET ultima_localizacao_id = ? WHERE id = ?', [$localId, $responsavelId]);
        }
    }

    /** Confere se a localização existe e está ativa (a prateleira, a estante e o setor dela). Vazio = sem localização. */
    public function localizacaoValida(mixed $id): ?int
    {
        if ($id === null || $id === '' || (int)$id === 0) {
            return null;
        }
        $local = $this->linha('SELECT l.id, l.ativa, l.estante_id, e.ativa AS estante_ativa, s.ativo AS setor_ativo
                               FROM localizacoes l LEFT JOIN estantes e ON e.id = l.estante_id LEFT JOIN setores s ON s.id = e.setor_id WHERE l.id = ?', [(int)$id]);
        if (!$local) {
            throw new AcervoException('Localização não encontrada.');
        }
        if (!(int)$local['ativa']) {
            throw new AcervoException('Esta prateleira (localização) está desativada. Escolha outra ou reative-a em Organização Física.');
        }
        if ($local['estante_id'] !== null && (!(int)$local['estante_ativa'] || ($local['setor_ativo'] !== null && !(int)$local['setor_ativo']))) {
            throw new AcervoException('Esta estante (ou o setor dela) está desativada. Escolha outra prateleira ou reative-a em Organização Física.');
        }
        return (int)$local['id'];
    }

    /** Registra quem avaliou a conservação e quando ("Não avaliado" apaga a avaliação). Dentro de uma transação. */
    private function marcarAvaliacao(int $id, string $estado, int $responsavelId): void
    {
        if ($estado === self::NAO_AVALIADO) {
            $this->executar('UPDATE exemplares SET conservacao_avaliada_em = NULL, conservacao_avaliada_por = NULL WHERE id = ?', [$id]);
        } else {
            $this->executar('UPDATE exemplares SET conservacao_avaliada_em = NOW(), conservacao_avaliada_por = ? WHERE id = ?', [$responsavelId, $id]);
        }
    }

    public function movimentacao(int $exemplarId, string $tipo, string $descricao, ?int $responsavelId, ?int $inventarioId = null, array $detalhes = []): void
    {
        $this->executar('INSERT INTO movimentacoes (exemplar_id, inventario_id, operacao_id, tipo, descricao, detalhes, responsavel_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $exemplarId, $inventarioId, $this->operacaoId, $tipo, mb_substr($descricao, 0, 1000), $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null, $responsavelId,
        ]);
    }

    private function localTexto(?int $id): string
    {
        if ($id === null) {
            return 'sem localização';
        }
        $l = $this->linha('SELECT setor, estante, prateleira, complemento FROM localizacoes WHERE id = ?', [$id]);
        return $l ? self::localizacaoTexto($l) : 'localização nº ' . $id;
    }

    /** "Literatura · Estante E03 · Prateleira P02 · Complemento". */
    public static function localizacaoTexto(?array $l): string
    {
        if (!$l || ($l['setor'] ?? null) === null) {
            return 'Sem localização';
        }
        // Prateleiras da Organização Física sem setor guardam setor vazio.
        $partes = $l['setor'] !== '' ? [$l['setor']] : [];
        if (($l['estante'] ?? '') !== '') {
            $partes[] = 'Estante ' . $l['estante'];
        }
        if (($l['prateleira'] ?? '') !== '') {
            $partes[] = 'Prateleira ' . $l['prateleira'];
        }
        if (($l['complemento'] ?? '') !== '') {
            $partes[] = $l['complemento'];
        }
        return implode(' · ', $partes);
    }

    public static function rotuloSituacao(string $s): string
    {
        return [
            'disponivel' => 'Disponível', 'emprestado' => 'Emprestado', 'manutencao' => 'Em manutenção', 'extraviado' => 'Extraviado',
            'baixado' => 'Baixado', 'aguardando' => 'Aguardando liberação', 'danificado' => 'Danificado',
        ][$s] ?? $s;
    }

    public static function rotuloConservacao(string $e): string
    {
        return ['nao_avaliado' => 'Não avaliado', 'excelente' => 'Excelente', 'bom' => 'Bom', 'regular' => 'Regular', 'ruim' => 'Ruim',
            'danificado' => 'Danificado', 'necessita_reparo' => 'Necessita reparo'][$e] ?? $e;
    }
}
