<?php
/** API JSON da parte administrativa (salvamento automático da revisão, pesquisa, contadores, notificações). */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\RegistroConflitoException;
use Biblioteca\Services\AcervoException;

$usuario = auth_exigir('painel.ver', true);
reorg_admin_exigir_modulo(true);
$adminId = (int)$usuario['id'];
$r = reorg();
$lev = $r->levantamento();
$acao = (string)($_GET['acao'] ?? $_POST['acao'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valido()) {
    api_resposta(['ok' => false, 'erro' => 'A página ficou aberta por muito tempo. Recarregue e tente de novo.'], 419);
}

try {
    switch ($acao) {
        case 'contadores':
            $id = (int)($_GET['lote'] ?? 0);
            api_resposta(['ok' => true, 'contadores' => $r->lote($id) ? $r->contadores($id) : null, 'nao_lidas' => $r->naoLidasAdmin($adminId)]);
        case 'notificacoes':
            api_resposta(['ok' => true, 'nao_lidas' => $r->naoLidasAdmin($adminId), 'ultimas' => $r->notificacoesAdmin($adminId, true, 5)]);
        case 'pesquisar':
            api_resposta(['ok' => true, 'obras' => $lev->pesquisarObras((string)($_GET['q'] ?? ''))]);
        case 'salvar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                break;
            }
            $x = $lev->salvarRevisao($adminId, (int)$_POST['id'], $_POST, (int)($_POST['versao'] ?? 0));
            api_resposta(['ok' => true] + $x);
        case 'manter':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                break;
            }
            db_query('UPDATE reorg_registros SET editando_ate = NOW() + INTERVAL ? SECOND WHERE id = ? AND editando_admin_id = ?', [(int)reorg_config('reserva_edicao'), (int)$_POST['id'], $adminId]);
            api_resposta(['ok' => true, 'agora' => date('Y-m-d H:i:s')]);
        case 'liberar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                break;
            }
            $lev->liberar((int)$_POST['id'], null, $adminId);
            api_resposta(['ok' => true]);
    }
    api_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
} catch (RegistroConflitoException $e) {
    api_resposta(['ok' => false, 'conflito' => true, 'erro' => $e->getMessage(), 'atual' => array_intersect_key($e->atual, array_flip([...Biblioteca\Reorganizacao\Levantamento::CAMPOS, 'versao', 'estados', 'atualizado_nome', 'atualizado_em']))], 409);
} catch (AcervoException $e) {
    api_resposta(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[reorganizacao] API administrativa: ' . $e->getMessage());
    api_resposta(['ok' => false, 'erro' => 'Erro ao salvar. Tente novamente.'], 500);
}
