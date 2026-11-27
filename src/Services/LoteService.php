<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Lotes de cadastramento e etapas de trabalho (migrações 150 e 160).
 *
 * O lote acompanha o exemplar físico do cadastro até a estante definitiva:
 *
 *   Em cadastro → Lote fechado — Aguardando carimbagem → Em carimbagem →
 *   Aguardando organização nas estantes → Lote encerrado
 *
 *   * criar(): o administrador abre o lote pela Conferência, com capacidade (exemplares físicos) e
 *     usuários responsáveis pelo cadastro. O número é gerado aqui, com a mesma trava da sequência BDP
 *     (dois lotes abertos ao mesmo tempo não repetem o número; o índice único é a segunda barreira).
 *   * escolherAtivo(): cada participante escolhe em "Meus lotes" o lote em que vai trabalhar. O
 *     registro do exemplar (ExemplarService) ocupa a vaga e fecha o lote quando fica cheio.
 *   * fecharAntecipado(): o administrador fecha antes de completar. Fechar só impede novos exemplares.
 *   * confirmarCarimbagem(): só quando TODOS os exemplares do lote estão carimbados e nenhuma dúvida
 *     que comprometa a identificação física está aberta.
 *   * organizar(): exemplares carimbados vão para uma prateleira da Organização Física (vários de uma vez).
 *   * encerrar(): só com todos carimbados e guardados. O lote nunca é apagado (gatilho) e o
 *     encerramento original não muda; correções posteriores ficam em lote_eventos com justificativa.
 *
 * Toda mudança grava lote_eventos (histórico permanente) e acoes_administrativas na mesma transação.
 */
final class LoteService
{
    use ConsultasPdo;

    public const CAPACIDADE_MAXIMA = 500;

    public function __construct(private PDO $pdo, private ?ExemplarService $exemplares = null)
    {
        $this->exemplares ??= new ExemplarService($pdo);
    }

    // =================================================================
    // Abertura e participantes
    // =================================================================

    /**
     * "+ Abrir novo lote" (formulário único: admin/lote-novo.php). Dados: capacidade (1..500), usuarios
     * (ids), descricao (nome ou observação) e, desde a migração 240, inventario_id (inventário a que o
     * lote pertence), nome, prazo (AAAA-MM-DD), instrucoes e observacoes — todos opcionais aqui; o
     * formulário exige o inventário. $chave (token do formulário) evita abrir dois lotes com um clique
     * duplo: devolve o mesmo lote.
     */
    public function criar(array $dados, int $administradorId, ?string $chave = null): int
    {
        $capacidade = (int)($dados['capacidade'] ?? 0);
        if ($capacidade < 1 || $capacidade > self::CAPACIDADE_MAXIMA) {
            throw new AcervoException('Informe a quantidade máxima de exemplares do lote (de 1 a ' . self::CAPACIDADE_MAXIMA . ').');
        }
        $usuarios = self::idsValidos($dados['usuarios'] ?? []);
        if (!$usuarios) {
            throw new AcervoException('Escolha pelo menos um usuário responsável pelo cadastro neste lote.');
        }
        $descricao = self::textoLimpo((string)($dados['descricao'] ?? ''), 255);
        $extras = self::dadosExtras($dados);
        $chave = $chave !== null && preg_match('/^[a-f0-9]{32}$/', $chave) ? 'abrir:' . $chave : null;

        return $this->transacao(function () use ($capacidade, $usuarios, $descricao, $extras, $administradorId, $chave) {
            $this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            if ($chave !== null && ($repetido = $this->valor('SELECT lote_id FROM lote_eventos WHERE chave = ?', [$chave]))) {
                return (int)$repetido; // mesmo formulário enviado de novo
            }
            $this->conferirUsuarios($usuarios);
            if ($extras['inventario_id'] !== null) {
                $this->conferirInventario($extras['inventario_id']);
            }
            $numero = (int)$this->valor('SELECT COALESCE(MAX(numero), 0) + 1 FROM cadastro_lotes');
            $this->executar("INSERT INTO cadastro_lotes (numero, modo, capacidade, descricao, responsavel_id) VALUES (?, 'gerenciado', ?, ?, ?)",
                [$numero, $capacidade, $descricao, $administradorId]);
            $id = (int)$this->pdo->lastInsertId();
            if (array_filter($extras, fn ($v) => $v !== null)) {
                // Colunas da migração 240 (gravadas à parte: sem elas, o lote continua sendo aberto normalmente).
                $this->executar('UPDATE cadastro_lotes SET inventario_id = ?, nome = ?, prazo = ?, instrucoes = ?, observacoes = ? WHERE id = ?',
                    [$extras['inventario_id'], $extras['nome'], $extras['prazo'], $extras['instrucoes'], $extras['observacoes'], $id]);
            }
            $nome = 'Lote ' . ExemplarService::numeroLote($numero);
            $this->exemplares->eventoLote($id, null, 'lote_aberto', $nome . ' aberto · capacidade de ' . $capacidade . ' exemplar(es)' . ($extras['nome'] ? ' · ' . $extras['nome'] : '') . ($descricao ? ' · ' . $descricao : ''),
                $administradorId, ['capacidade' => $capacidade, 'usuarios' => $usuarios, 'inventario_id' => $extras['inventario_id']], $chave);
            foreach ($usuarios as $u) {
                $this->incluirParticipante($id, $nome, $u, $administradorId);
            }
            $this->registrarAcao($administradorId, 'lote.abrir', 'lote', $id, ['numero' => $numero, 'capacidade' => $capacidade, 'usuarios' => $usuarios]);
            return $id;
        });
    }

    /**
     * Altera nome, prazo, instruções e observações do lote (migração 240). O número, a capacidade e o
     * inventário não mudam aqui. Fica no histórico do lote.
     */
    public function alterarDados(int $loteId, array $dados, int $administradorId): void
    {
        $extras = self::dadosExtras($dados);
        $this->transacao(function () use ($loteId, $extras, $administradorId) {
            $lote = $this->linha('SELECT id, numero FROM cadastro_lotes WHERE id = ? FOR UPDATE', [$loteId]);
            if (!$lote) {
                throw new AcervoException('Lote não encontrado.');
            }
            $this->executar('UPDATE cadastro_lotes SET nome = ?, prazo = ?, instrucoes = ?, observacoes = ? WHERE id = ?',
                [$extras['nome'], $extras['prazo'], $extras['instrucoes'], $extras['observacoes'], $loteId]);
            $this->exemplares->eventoLote($loteId, null, 'correcao', 'Lote ' . ExemplarService::numeroLote($lote['numero']) . ': dados do lote alterados'
                . ($extras['nome'] ? ' · ' . $extras['nome'] : ''), $administradorId, ['nome' => $extras['nome'], 'prazo' => $extras['prazo']]);
        });
    }

    /** Campos da migração 240, limpos e validados. @return array{inventario_id: ?int, nome: ?string, prazo: ?string, instrucoes: ?string, observacoes: ?string} */
    public static function dadosExtras(array $dados): array
    {
        $prazo = trim((string)($dados['prazo'] ?? ''));
        if ($prazo !== '' && !(($dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $prazo)) && $dt->format('Y-m-d') === $prazo)) {
            throw new AcervoException('Prazo inválido. Use uma data válida.');
        }
        $longo = function (mixed $v): ?string {
            $v = trim((string)$v);
            return $v === '' ? null : mb_substr($v, 0, 5000);
        };
        $inv = (int)($dados['inventario_id'] ?? 0);
        return [
            'inventario_id' => $inv > 0 ? $inv : null,
            'nome' => self::textoLimpo((string)($dados['nome'] ?? ''), 120),
            'prazo' => $prazo !== '' ? $prazo : null,
            'instrucoes' => $longo($dados['instrucoes'] ?? null),
            'observacoes' => $longo($dados['observacoes'] ?? null),
        ];
    }

    /** O inventário existe e ainda aceita lotes (não concluído nem cancelado)? */
    private function conferirInventario(int $inventarioId): void
    {
        $inv = $this->linha('SELECT id, status FROM inventarios WHERE id = ?', [$inventarioId]);
        if (!$inv) {
            throw new AcervoException('Escolha um inventário existente para o lote.');
        }
        if (in_array($inv['status'], ['concluido', 'cancelado'], true)) {
            throw new AcervoException('Este inventário já foi ' . ($inv['status'] === 'concluido' ? 'concluído' : 'cancelado') . ' e não recebe novos lotes.');
        }
    }

    /** Inclui usuários no lote (ou devolve quem tinha sido removido). Devolve quantos entraram. */
    public function atribuir(int $loteId, array $usuarios, int $administradorId): int
    {
        $usuarios = self::idsValidos($usuarios);
        if (!$usuarios) {
            throw new AcervoException('Escolha pelo menos um usuário.');
        }
        return $this->transacao(function () use ($loteId, $usuarios, $administradorId) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] === 'encerrado') {
                throw new AcervoException('Este lote está encerrado. Não é possível incluir participantes.');
            }
            $this->conferirUsuarios($usuarios);
            $n = 0;
            foreach ($usuarios as $u) {
                $n += $this->incluirParticipante($loteId, self::nome($lote), $u, $administradorId) ? 1 : 0;
            }
            return $n;
        });
    }

    /** Remove alguém do lote (a linha fica, com quando e quem removeu). O que a pessoa já registrou continua no lote. */
    public function remover(int $loteId, int $usuarioId, int $administradorId): void
    {
        $this->transacao(function () use ($loteId, $usuarioId, $administradorId) {
            $lote = $this->bloquearLote($loteId);
            $n = $this->executar('UPDATE cadastro_lote_usuarios SET removido_em = NOW(), removido_por = ? WHERE lote_id = ? AND administrador_id = ? AND removido_em IS NULL',
                [$administradorId, $loteId, $usuarioId]);
            if (!$n) {
                throw new AcervoException('Este usuário não participa do lote.');
            }
            $this->executar('UPDATE administradores SET lote_ativo_id = NULL WHERE id = ? AND lote_ativo_id = ?', [$usuarioId, $loteId]);
            $nomeUsuario = (string)$this->valor('SELECT nome FROM administradores WHERE id = ?', [$usuarioId]);
            $this->exemplares->eventoLote($loteId, null, 'usuario_removido', $nomeUsuario . ' removido do ' . self::nome($lote), $administradorId, ['usuario_id' => $usuarioId]);
            $this->registrarAcao($administradorId, 'lote.remover_usuario', 'lote', $loteId, ['usuario_id' => $usuarioId]);
        });
    }

    /** "Trabalhar neste lote" (Meus lotes). null = nenhum lote ativo. */
    public function escolherAtivo(?int $loteId, int $administradorId): void
    {
        $this->transacao(function () use ($loteId, $administradorId) {
            if ($loteId === null) {
                $this->executar('UPDATE administradores SET lote_ativo_id = NULL WHERE id = ?', [$administradorId]);
                return;
            }
            $lote = $this->bloquearLote($loteId);
            if ($lote['modo'] !== 'gerenciado') {
                throw new AcervoException('Escolha um lote aberto pela Conferência.');
            }
            if (!$this->participa($loteId, $administradorId)) {
                throw new AcervoException('Você não está atribuído ao ' . self::nome($lote) . '.');
            }
            if ($lote['status'] !== 'aberto') {
                throw new AcervoException(self::nome($lote) . ' não recebe novos exemplares (' . ExemplarService::rotuloStatusLote($lote['status']) . ').');
            }
            $this->executar('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?', [$loteId, $administradorId]);
            $this->registrarAcao($administradorId, 'lote.ativo', 'lote', $loteId, ['numero' => (int)$lote['numero']]);
        });
    }

    // =================================================================
    // Fechamento
    // =================================================================

    /** Fechamento antecipado pelo administrador (mesmo sem atingir a capacidade). */
    public function fecharAntecipado(int $loteId, int $administradorId, string $motivo = ''): void
    {
        $motivo = self::textoLimpo($motivo, 255);
        $this->transacao(function () use ($loteId, $administradorId, $motivo) {
            $this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE'); // espera cadastros em andamento
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] !== 'aberto') {
                throw new AcervoException(self::nome($lote) . ' já está fechado (' . ExemplarService::rotuloStatusLote($lote['status']) . ').');
            }
            $this->executar("UPDATE cadastro_lotes SET status = 'fechado', fechado_em = NOW(), fechado_por = ?, fechamento_tipo = 'antecipado' WHERE id = ?", [$administradorId, $loteId]);
            $this->exemplares->eventoLote($loteId, null, 'lote_fechado', self::nome($lote) . ' fechado antecipadamente com ' . (int)$lote['total'] . ' exemplar(es)' . ($motivo ? ' · ' . $motivo : '') . ' — aguardando carimbagem',
                $administradorId, ['tipo' => 'antecipado', 'total' => (int)$lote['total'], 'motivo' => $motivo]);
            $this->registrarAcao($administradorId, 'lote.fechar', 'lote', $loteId, ['numero' => (int)$lote['numero'], 'antecipado' => true]);
        });
    }

    /** Compatível com a migração 150: fecha o lote (antecipadamente, se for gerenciado). */
    public function fechar(int $loteId, int $administradorId): void
    {
        $lote = $this->linha('SELECT status FROM cadastro_lotes WHERE id = ?', [$loteId]);
        if (!$lote) {
            throw new AcervoException('Lote não encontrado.');
        }
        if ($lote['status'] === 'aberto') {
            $this->fecharAntecipado($loteId, $administradorId);
        }
    }

    // =================================================================
    // Carimbagem
    // =================================================================

    /**
     * "Confirmar carimbagem do lote": só com todos os exemplares carimbados. Se faltar algum, nada muda
     * e a mensagem diz quais são. O lote passa para "Aguardando organização nas estantes".
     */
    public function confirmarCarimbagem(int $loteId, int $administradorId, ?string $chave = null): void
    {
        $this->transacao(function () use ($loteId, $administradorId, $chave) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] === 'aberto') {
                throw new AcervoException(self::nome($lote) . ' ainda está em cadastro. Feche o lote antes de confirmar a carimbagem.');
            }
            if (!in_array($lote['status'], ['fechado', 'em_carimbagem'], true)) {
                throw new AcervoException('A carimbagem do ' . self::nome($lote) . ' já foi confirmada.');
            }
            $pendentes = array_column($this->todas("SELECT codigo_individual FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado' AND carimbo_status <> 'carimbado' ORDER BY lote_ordem FOR UPDATE", [$loteId]), 'codigo_individual');
            if ($pendentes) {
                throw new AcervoException(count($pendentes) . ' exemplar(es) ainda sem carimbo confirmado: ' . self::lista($pendentes) . '. Confirme cada um antes de concluir a etapa.');
            }
            $this->exigirSemDuvidasBloqueantes($loteId, 'confirmar a carimbagem');
            $this->executar("UPDATE cadastro_lotes SET status = 'aguardando_organizacao', carimbagem_concluida_em = NOW(), carimbagem_concluida_por = ?,
                                    carimbagem_iniciada_em = COALESCE(carimbagem_iniciada_em, NOW()), carimbagem_iniciada_por = COALESCE(carimbagem_iniciada_por, ?) WHERE id = ?",
                [$administradorId, $administradorId, $loteId]);
            $this->exemplares->eventoLote($loteId, null, 'carimbagem_confirmada', self::nome($lote) . ': carimbagem confirmada — aguardando organização nas estantes', $administradorId,
                [], $chave !== null && preg_match('/^[a-f0-9]{32}$/', $chave) ? 'carimbagem:' . $chave : null);
            $this->registrarAcao($administradorId, 'lote.carimbagem', 'lote', $loteId, ['numero' => (int)$lote['numero']]);
        });
    }

    // =================================================================
    // Organização nas estantes
    // =================================================================

    /**
     * Guarda os exemplares escolhidos (do lote, já carimbados) na prateleira indicada. A localização é
     * do EXEMPLAR. Quem organizou pela primeira vez fica gravado no exemplar. Devolve quantos mudaram.
     * @param list<int> $ids
     */
    public function organizar(int $loteId, array $ids, int $localizacaoId, int $administradorId): int
    {
        $ids = self::idsValidos($ids);
        if (!$ids) {
            throw new AcervoException('Selecione pelo menos um exemplar.');
        }
        if (count($ids) > 500) {
            throw new AcervoException('Organize no máximo 500 exemplares de cada vez.');
        }
        return $this->transacao(function () use ($loteId, $ids, $localizacaoId, $administradorId) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] !== 'aguardando_organizacao') {
                throw new AcervoException($lote['status'] === 'encerrado'
                    ? self::nome($lote) . ' está encerrado. Para mudar um exemplar de lugar, use “Mover exemplar” (fica no histórico).'
                    : 'Os exemplares do ' . self::nome($lote) . ' só vão para as estantes depois de confirmada a carimbagem do lote.');
            }
            $local = $this->linha('SELECT l.id, l.estante_id FROM localizacoes l WHERE l.id = ?', [$localizacaoId]);
            if (!$local || $local['estante_id'] === null) {
                throw new AcervoException('Escolha a estante e a prateleira cadastradas na Organização Física.');
            }
            $this->exemplares->localizacaoValida($localizacaoId); // ativa?
            $n = 0;
            sort($ids);
            foreach ($ids as $id) {
                $ex = $this->exemplares->bloquear($id);
                if ((int)$ex['lote_id'] !== $loteId) {
                    throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' não pertence ao ' . self::nome($lote) . '.');
                }
                if ($ex['situacao'] === 'baixado') {
                    continue;
                }
                if ($ex['carimbo_status'] !== 'carimbado') {
                    throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' ainda não está carimbado.');
                }
                if ($this->exemplares->transferir($id, $localizacaoId, $administradorId, 'organização do ' . self::nome($lote))) {
                    $n++;
                } elseif ($ex['organizado_em'] === null) {
                    // Já estava nesta prateleira: confirma a localização definitiva.
                    $this->executar('UPDATE exemplares SET organizado_em = NOW(), organizado_por = ?, organizado_localizacao_id = ? WHERE id = ?', [$administradorId, $localizacaoId, $id]);
                    $this->exemplares->eventoLote($loteId, $id, 'exemplar_organizado', $ex['codigo_individual'] . ' confirmado na localização atual', $administradorId, ['localizacao_id' => $localizacaoId]);
                    $n++;
                }
            }
            $this->executar('UPDATE administradores SET ultima_localizacao_id = ? WHERE id = ?', [$localizacaoId, $administradorId]);
            return $n;
        });
    }

    // =================================================================
    // Encerramento e correções
    // =================================================================

    /** "Encerrar lote": todos os exemplares carimbados e guardados nas estantes. */
    public function encerrar(int $loteId, int $administradorId, ?string $chave = null): void
    {
        $this->transacao(function () use ($loteId, $administradorId, $chave) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] === 'encerrado') {
                throw new AcervoException(self::nome($lote) . ' já está encerrado.');
            }
            if ($lote['status'] !== 'aguardando_organizacao') {
                throw new AcervoException('Só é possível encerrar o lote depois de confirmar a carimbagem e organizar os exemplares nas estantes.');
            }
            $pend = $this->todas("SELECT codigo_individual, carimbo_status, localizacao_id FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado'
                                  AND (carimbo_status <> 'carimbado' OR localizacao_id IS NULL) ORDER BY lote_ordem FOR UPDATE", [$loteId]);
            if ($pend) {
                throw new AcervoException(count($pend) . ' exemplar(es) ainda sem carimbo ou sem estante definitiva: ' . self::lista(array_column($pend, 'codigo_individual')) . '.');
            }
            $this->exigirSemDuvidasBloqueantes($loteId, 'encerrar o lote');
            $this->executar("UPDATE cadastro_lotes SET status = 'encerrado', encerrado_em = NOW(), encerrado_por = ? WHERE id = ?", [$administradorId, $loteId]);
            $this->executar('UPDATE administradores SET lote_ativo_id = NULL WHERE lote_ativo_id = ?', [$loteId]);
            $this->exemplares->eventoLote($loteId, null, 'lote_encerrado', self::nome($lote) . ' encerrado', $administradorId, [],
                $chave !== null && preg_match('/^[a-f0-9]{32}$/', $chave) ? 'encerrar:' . $chave : null);
            $this->registrarAcao($administradorId, 'lote.encerrar', 'lote', $loteId, ['numero' => (int)$lote['numero']]);
        });
    }

    /**
     * Correção administrativa: muda a observação ou a capacidade (só em cadastro, nunca abaixo do que já
     * foi registrado) ou só registra uma justificativa. Nunca apaga o encerramento original.
     */
    public function corrigir(int $loteId, array $dados, int $administradorId): void
    {
        $justificativa = self::textoLimpo((string)($dados['justificativa'] ?? ''), 500);
        if ($justificativa === null || mb_strlen($justificativa) < 5) {
            throw new AcervoException('Escreva a justificativa da correção.');
        }
        $this->transacao(function () use ($loteId, $dados, $administradorId, $justificativa) {
            $this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            $lote = $this->bloquearLote($loteId);
            $mudancas = [];
            if (array_key_exists('descricao', $dados)) {
                $nova = self::textoLimpo((string)$dados['descricao'], 255);
                if ($nova !== $lote['descricao']) {
                    $this->executar('UPDATE cadastro_lotes SET descricao = ? WHERE id = ?', [$nova, $loteId]);
                    $mudancas['descricao'] = ['de' => $lote['descricao'], 'para' => $nova];
                }
            }
            if (isset($dados['capacidade']) && $dados['capacidade'] !== '' && (int)$dados['capacidade'] !== (int)$lote['capacidade']) {
                $cap = (int)$dados['capacidade'];
                if ($lote['status'] !== 'aberto') {
                    throw new AcervoException('A capacidade só pode mudar enquanto o lote está em cadastro.');
                }
                if ($cap < max(1, (int)$lote['total']) || $cap > self::CAPACIDADE_MAXIMA) {
                    throw new AcervoException('A capacidade precisa ficar entre ' . max(1, (int)$lote['total']) . ' (já registrados) e ' . self::CAPACIDADE_MAXIMA . '.');
                }
                $this->executar('UPDATE cadastro_lotes SET capacidade = ? WHERE id = ?', [$cap, $loteId]);
                $mudancas['capacidade'] = ['de' => $lote['capacidade'] !== null ? (int)$lote['capacidade'] : null, 'para' => $cap];
                if ($cap === (int)$lote['total']) {
                    $this->executar("UPDATE cadastro_lotes SET status = 'fechado', fechado_em = NOW(), fechado_por = ?, fechamento_tipo = 'automatico' WHERE id = ?", [$administradorId, $loteId]);
                    $this->exemplares->eventoLote($loteId, null, 'lote_fechado', self::nome($lote) . ' completo após a correção da capacidade — aguardando carimbagem', $administradorId, ['tipo' => 'automatico']);
                }
            }
            $texto = self::nome($lote) . ': correção administrativa · ' . $justificativa;
            if (isset($mudancas['capacidade'])) {
                $texto .= ' · capacidade ' . ($mudancas['capacidade']['de'] ?? 'sem limite') . ' → ' . $mudancas['capacidade']['para'];
            }
            if (isset($mudancas['descricao'])) {
                $texto .= ' · observação alterada';
            }
            $this->exemplares->eventoLote($loteId, null, 'correcao', $texto, $administradorId, ['justificativa' => $justificativa, 'mudancas' => $mudancas]);
            $this->registrarAcao($administradorId, 'lote.correcao', 'lote', $loteId, ['justificativa' => $justificativa, 'mudancas' => $mudancas]);
        });
    }

    // =================================================================
    // Compatibilidade (migração 150): lote pessoal automático
    // =================================================================

    /** Fecha o lote automático aberto de quem pediu (se houver) e abre outro. Devolve o id do novo lote. */
    public function abrirNovo(int $administradorId, string $equipe = '', string $descricao = ''): int
    {
        $equipe = self::textoLimpo($equipe, 80);
        $descricao = self::textoLimpo($descricao, 255);
        return $this->transacao(function () use ($administradorId, $equipe, $descricao) {
            $this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            $aberto = $this->linha("SELECT id, numero, total FROM cadastro_lotes WHERE responsavel_id = ? AND status = 'aberto' AND modo = 'automatico' ORDER BY id DESC LIMIT 1 FOR UPDATE", [$administradorId]);
            if ($equipe !== null) {
                $this->executar('UPDATE administradores SET equipe = ? WHERE id = ?', [$equipe, $administradorId]);
            }
            if ($aberto && (int)$aberto['total'] === 0) {
                $this->executar('UPDATE cadastro_lotes SET equipe = ?, descricao = ? WHERE id = ?', [$equipe, $descricao, $aberto['id']]);
                return (int)$aberto['id'];
            }
            if ($aberto) {
                $this->executar("UPDATE cadastro_lotes SET status = 'fechado', fechado_em = NOW(), fechado_por = ?, fechamento_tipo = 'antecipado' WHERE id = ?", [$administradorId, $aberto['id']]);
                $this->exemplares->eventoLote((int)$aberto['id'], null, 'lote_fechado', 'Lote ' . ExemplarService::numeroLote($aberto['numero']) . ' fechado ao começar um novo lote', $administradorId, ['tipo' => 'antecipado']);
            }
            $numero = (int)$this->valor('SELECT COALESCE(MAX(numero), 0) + 1 FROM cadastro_lotes');
            $this->executar('INSERT INTO cadastro_lotes (numero, equipe, descricao, responsavel_id) VALUES (?, ?, ?, ?)', [$numero, $equipe, $descricao, $administradorId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->executar('INSERT IGNORE INTO cadastro_lote_usuarios (lote_id, administrador_id, atribuido_por) VALUES (?, ?, ?)', [$id, $administradorId, $administradorId]);
            $this->exemplares->eventoLote($id, null, 'lote_aberto', 'Lote ' . ExemplarService::numeroLote($numero) . ' aberto' . ($equipe ? ' · ' . $equipe : ''), $administradorId);
            $this->registrarAcao($administradorId, 'lote.abrir', 'lote', $id, ['numero' => $numero, 'equipe' => $equipe, 'fechado' => $aberto['id'] ?? null]);
            return $id;
        });
    }

    /** Equipe de uma conta (usada nos lotes automáticos e no filtro "Equipe" do Relatório de Atividades). */
    public function definirEquipe(int $administradorId, string $equipe, int $responsavelId): void
    {
        $equipe = self::textoLimpo($equipe, 80);
        $this->transacao(function () use ($administradorId, $equipe, $responsavelId) {
            if (!$this->linha('SELECT id FROM administradores WHERE id = ? FOR UPDATE', [$administradorId])) {
                throw new AcervoException('Conta não encontrada.');
            }
            $this->executar('UPDATE administradores SET equipe = ? WHERE id = ?', [$equipe, $administradorId]);
            $this->registrarAcao($responsavelId, 'usuario.equipe', 'usuario', $administradorId, ['equipe' => $equipe]);
        });
    }

    // =================================================================
    // Apoio
    // =================================================================

    public function participa(int $loteId, int $administradorId): bool
    {
        return (bool)$this->valor('SELECT 1 FROM cadastro_lote_usuarios WHERE lote_id = ? AND administrador_id = ? AND removido_em IS NULL', [$loteId, $administradorId]);
    }

    private function bloquearLote(int $loteId): array
    {
        $lote = $this->linha('SELECT * FROM cadastro_lotes WHERE id = ? FOR UPDATE', [$loteId]);
        if (!$lote) {
            throw new AcervoException('Lote não encontrado.');
        }
        return $lote;
    }

    private function incluirParticipante(int $loteId, string $nomeLote, int $usuarioId, int $administradorId): bool
    {
        $atual = $this->linha('SELECT removido_em FROM cadastro_lote_usuarios WHERE lote_id = ? AND administrador_id = ? FOR UPDATE', [$loteId, $usuarioId]);
        if ($atual && $atual['removido_em'] === null) {
            return false;
        }
        if ($atual) {
            $this->executar('UPDATE cadastro_lote_usuarios SET removido_em = NULL, removido_por = NULL, atribuido_em = NOW(), atribuido_por = ? WHERE lote_id = ? AND administrador_id = ?', [$administradorId, $loteId, $usuarioId]);
        } else {
            $this->executar('INSERT INTO cadastro_lote_usuarios (lote_id, administrador_id, atribuido_por) VALUES (?, ?, ?)', [$loteId, $usuarioId, $administradorId]);
        }
        $nome = (string)$this->valor('SELECT nome FROM administradores WHERE id = ?', [$usuarioId]);
        $this->exemplares->eventoLote($loteId, null, 'usuario_atribuido', $nome . ' atribuído ao ' . $nomeLote, $administradorId, ['usuario_id' => $usuarioId]);
        return true;
    }

    /** @param list<int> $usuarios */
    private function conferirUsuarios(array $usuarios): void
    {
        $ativos = array_map('intval', array_column($this->todas('SELECT id FROM administradores WHERE ativo = 1 AND id IN (' . implode(',', $usuarios) . ')'), 'id'));
        if (count($ativos) !== count($usuarios)) {
            throw new AcervoException('Um dos usuários escolhidos não existe ou está desativado.');
        }
    }

    private function exigirSemDuvidasBloqueantes(int $loteId, string $acao): void
    {
        $duvidas = $this->todas("SELECT d.id, e.codigo_individual FROM conferencia_duvidas d LEFT JOIN exemplares e ON e.id = d.exemplar_id
                                 WHERE d.status = 'aberta' AND d.compromete_identificacao = 1
                                   AND (d.lote_id = ? OR d.exemplar_id IN (SELECT id FROM exemplares WHERE lote_id = ?)
                                        OR d.livro_id IN (SELECT livro_id FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado'))", [$loteId, $loteId, $loteId]);
        if ($duvidas) {
            $quais = array_map(fn ($d) => 'nº ' . $d['id'] . ($d['codigo_individual'] ? ' (' . $d['codigo_individual'] . ')' : ''), $duvidas);
            throw new AcervoException('Há ' . count($duvidas) . ' dúvida(s) que comprometem a identificação física em aberto: ' . self::lista($quais) . '. Resolva-as antes de ' . $acao . '.');
        }
    }

    /** @return list<int> */
    private static function idsValidos(mixed $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids]), fn ($i) => $i > 0)));
    }

    private static function nome(array $lote): string
    {
        return 'Lote ' . ExemplarService::numeroLote($lote['numero']);
    }

    private static function lista(array $itens, int $max = 15): string
    {
        return implode(', ', array_slice($itens, 0, $max)) . (count($itens) > $max ? ' e mais ' . (count($itens) - $max) : '');
    }
}
