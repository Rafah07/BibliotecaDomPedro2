<?php

namespace Biblioteca\Reorganizacao;

use Biblioteca\Services\AcervoException;
use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Texto;

/**
 * Apoio comum às classes do módulo: consultas preparadas (ConsultasPdo do sistema), histórico,
 * notificações e conferências de acesso. A classe que usa o trait precisa de $pdo e $config.
 */
trait Apoio
{
    use ConsultasPdo;

    public const EQUIPES = [
        'grupo1' => 'Grupo 1 — Levantamento de dados',
        'grupo2' => 'Grupo 2 — Revisão e cadastramento',
        'grupo3' => 'Grupo 3 — Carimbação e identificação',
        'grupo4' => 'Grupo 4 — Conferência e organização nas estantes',
    ];

    public const EQUIPES_PARTICIPANTES = ['grupo1', 'grupo3', 'grupo4'];

    public const CONSERVACAO = [
        'excelente' => 'Excelente — bem conservado, sem danos relevantes',
        'regular' => 'Regular — sinais de desgaste, mas pode ser usado normalmente',
        'ruim' => 'Ruim — danos significativos ou precisa de reparos',
    ];

    /** Linha do histórico (imutável). Nunca grava senha nem dados de alunos. */
    private function historico(string $acao, string $descricao, ?int $loteId = null, ?array $sessao = null, ?int $adminId = null, array $detalhes = []): void
    {
        $periodo = $loteId ? $this->valor('SELECT periodo_id FROM reorg_lotes WHERE id = ?', [$loteId]) : ($sessao['periodo_id'] ?? $this->valor('SELECT id FROM reorg_periodos ORDER BY ativo DESC, id DESC LIMIT 1'));
        $this->executar('INSERT INTO reorg_historico (periodo_id, lote_id, sessao_id, nome, administrador_id, acao, descricao, detalhes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $periodo ?: null, $loteId, $sessao['id'] ?? null, $sessao['nome'] ?? null, $adminId, $acao, mb_substr($descricao, 0, 500),
            $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** Notificação para um participante. $chave evita repetir a mesma notificação em pouco tempo. */
    private function notificarSessao(int $sessaoId, string $tipo, string $texto, ?int $loteId = null, ?string $chave = null): void
    {
        if ($chave !== null && $this->repetida($chave)) {
            return;
        }
        $this->executar('INSERT INTO reorg_notificacoes (sessao_id, tipo, texto, lote_id, chave) VALUES (?, ?, ?, ?, ?)', [$sessaoId, $tipo, mb_substr($texto, 0, 500), $loteId, $chave]);
    }

    /** Notificação para administradores: um específico, ou todos os ativos ($adminId = null). */
    private function notificarAdmins(string $tipo, string $texto, ?int $loteId = null, ?int $adminId = null, ?string $chave = null): void
    {
        if ($chave !== null && $this->repetida($chave)) {
            return;
        }
        $ids = $adminId !== null ? [$adminId] : array_map('intval', array_column($this->todas('SELECT id FROM administradores WHERE ativo = 1'), 'id'));
        foreach ($ids as $id) {
            $this->executar('INSERT INTO reorg_notificacoes (administrador_id, tipo, texto, lote_id, chave) VALUES (?, ?, ?, ?, ?)', [$id, $tipo, mb_substr($texto, 0, 500), $loteId, $chave]);
        }
    }

    private function repetida(string $chave): bool
    {
        return (bool)$this->valor('SELECT 1 FROM reorg_notificacoes WHERE chave = ? AND criado_em > NOW() - INTERVAL ? MINUTE LIMIT 1',
            [$chave, (int)$this->config['intervalo_notificacao_repetida']]);
    }

    /** Lote com a linha bloqueada até o fim da transação. */
    private function bloquearLote(int $loteId): array
    {
        $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ? FOR UPDATE', [$loteId]);
        if (!$lote) {
            throw new AcervoException('Lote não encontrado.');
        }
        return $lote;
    }

    /**
     * O participante tem atribuição ATIVA neste lote, nesta equipe, e o lote/etapa aceitam trabalho?
     * Conferido no servidor em toda operação dos participantes.
     */
    private function exigirAcesso(array $sessao, int $loteId, string $equipe, bool $paraAlterar = true): array
    {
        $this->exigirSessaoAtiva($sessao);
        $ok = $this->valor('SELECT 1 FROM reorg_atribuicoes WHERE lote_id = ? AND sessao_id = ? AND equipe = ? AND encerrada_em IS NULL', [$loteId, (int)$sessao['id'], $equipe]);
        if (!$ok) {
            throw new AcervoException('Este lote não está atribuído a você nesta equipe. Peça a atribuição ao administrador.');
        }
        $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$loteId]);
        if ($paraAlterar) {
            $this->exigirEtapaAberta($lote, $equipe);
        }
        return $lote;
    }

    private function exigirEtapaAberta(array $lote, string $equipe): void
    {
        if ($lote['status'] === 'concluido') {
            throw new AcervoException(self::nomeLote($lote) . ' já foi concluído.');
        }
        if ($lote['status'] === 'pausado') {
            throw new AcervoException(self::nomeLote($lote) . ' está pausado pelo administrador. Aguarde a retomada.');
        }
        if (in_array($equipe, explode(',', (string)$lote['etapas_pausadas']), true)) {
            throw new AcervoException('A etapa “' . self::EQUIPES[$equipe] . '” deste lote está pausada pelo administrador.');
        }
    }

    private function exigirSessaoAtiva(array $sessao): void
    {
        $s = $this->linha('SELECT status FROM reorg_sessoes WHERE id = ?', [(int)$sessao['id']]);
        if (!$s || $s['status'] !== 'ativa') {
            throw new SessaoEncerradaException('Sua sessão foi encerrada. Entre novamente pela página de acesso.');
        }
    }

    /** Administrador pode revisar (Grupo 2) este lote: responsável ou autorizado. */
    private function adminDoLote(array $lote, int $adminId): bool
    {
        return (int)$lote['responsavel_id'] === $adminId
            || (bool)$this->valor('SELECT 1 FROM reorg_lote_admins WHERE lote_id = ? AND administrador_id = ? AND removido_em IS NULL', [(int)$lote['id'], $adminId]);
    }

    private function exigirAdminDoLote(array $lote, int $adminId): void
    {
        if (!$this->adminDoLote($lote, $adminId)) {
            throw new AcervoException('Só o administrador responsável pelo ' . self::nomeLote($lote) . ' ou um administrador autorizado nele faz a revisão (Grupo 2).');
        }
    }

    public static function nomeLote(array $lote): string
    {
        return (string)$lote['nome'];
    }

    /** Nome sem acentos, minúsculo e sem espaços repetidos (nomes iguais escritos de jeitos diferentes). */
    public static function chaveNome(string $nome): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', mb_strtolower(Texto::semAcento($nome)))), 0, 100);
    }

    /** "S-0012": identificador curto da sessão, para diferenciar pessoas com o mesmo nome. */
    public static function rotuloSessao(int|string $id): string
    {
        return 'S-' . str_pad((string)(int)$id, 4, '0', STR_PAD_LEFT);
    }
}
