<?php
/**
 * Confirmação da importação (etapas 5 e 6). Gravar no acervo exige a permissão importacao.executar;
 * cancelar uma importação que ainda não gravou nada basta importacao.revisar.
 *
 * POST acao=iniciar   id → bloqueia a importação para gravação e devolve o resumo
 * POST acao=lote      id → grava o próximo bloco no acervo (chamado até "concluida")
 * POST acao=cancelar  id → encerra a importação; linhas já gravadas permanecem
 *
 * Cada bloco roda em uma transação e marca as linhas como processadas na mesma
 * transação: se a conexão cair, basta continuar — nada é aplicado duas vezes.
 */
require_once __DIR__ . '/../../includes/importacao.php';

const CONFIRMAR_LOTE = 100;

$usuario = importacao_api('importacao.revisar', true);
$acao = is_string($_POST['acao'] ?? null) ? $_POST['acao'] : '';
$repo = importacao_repo();
$id = importacao_id_param();

try {
    $imp = $repo->buscar($id);
    if (!$imp) {
        api_resposta(['ok' => false, 'erro' => 'Importação não encontrada.'], 404);
    }
    $gravouAlgo = (int)$imp['total_importados'] + (int)$imp['total_vinculados'] > 0;
    if (($acao !== 'cancelar' || $gravouAlgo) && !auth_pode('importacao.executar')) {
        api_resposta(['ok' => false, 'erro' => 'Somente a bibliotecária ou um administrador pode confirmar a importação.'], 403);
    }
    switch ($acao) {
        case 'iniciar':
            $resumo = $repo->resumoConfirmacao($id);
            $repo->iniciarConfirmacao($id);
            registrar_acao('importacao.confirmar', 'importacao', $id, $resumo);
            api_resposta(['ok' => true, 'resumo' => $resumo]);

        case 'lote':
            @set_time_limit(120);
            $r = $repo->confirmarLote($id, $usuario['id'], CONFIRMAR_LOTE);
            $atual = $repo->buscar($id);
            if ($r['concluida']) {
                registrar_acao('importacao.concluir', 'importacao', $id, [
                    'status' => $atual['status'], 'criados' => (int)$atual['total_importados'],
                    'vinculados' => (int)$atual['total_vinculados'], 'falhas' => (int)$atual['total_falhas'],
                ]);
            }
            api_resposta([
                'ok' => true,
                'concluida' => $r['concluida'],
                'gravados' => $r['gravados'],
                'falhas' => $r['falhas'],
                'restantes' => $r['restantes'],
                'status' => $atual['status'],
                'totais' => [
                    'criados' => (int)$atual['total_importados'],
                    'vinculados' => (int)$atual['total_vinculados'],
                    'falhas' => (int)$atual['total_falhas'],
                ],
            ]);

        case 'cancelar':
            $repo->cancelar($id);
            registrar_acao('importacao.cancelar', 'importacao', $id, ['status_anterior' => $imp['status']]);
            api_resposta(['ok' => true]);

        default:
            api_resposta(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
    }
} catch (Throwable $e) {
    importacao_api_falha($e);
}
