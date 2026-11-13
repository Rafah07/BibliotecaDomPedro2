<?php
/**
 * Confirmação da importação (etapas 5 e 6). Gravar no acervo exige a permissão importacao.executar;
 * cancelar uma importação que ainda não gravou nada basta importacao.revisar.
 *
 * POST acao=importar_todos id [confirmar_repeticao=1] → "Importar todos e revisar no inventário":
 *                      grava TODAS as linhas (menos as marcadas para ignorar) em UMA transação,
 *                      com os alertas ligados a cada livro; entram como Pendente no inventário em
 *                      andamento. Planilha repetida exige confirmar_repeticao=1 (aviso, não bloqueio).
 * POST acao=iniciar   id → bloqueia a importação para gravação e devolve o resumo (fluxo antigo, por lotes)
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
        case 'importar_todos':
            @set_time_limit(0);
            ignore_user_abort(true); // a transação termina mesmo se a pessoa fechar a página
            $rep = $repo->repeticoes($id);
            // A confirmação dada no envio do arquivo repetido vale aqui também.
            $confirmou = ($_POST['confirmar_repeticao'] ?? '') === '1' || $rep['confirmada_em'] !== null;
            if ($rep['repetida'] && !$confirmou && $imp['status'] !== 'concluida') {
                api_resposta(['ok' => false, 'repetida' => true, 'repeticao' => $rep,
                    'erro' => 'Esta planilha parece já ter sido importada. Confirme para importar de novo: nada será mesclado nem apagado.'], 409);
            }
            $r = $repo->importarTodos($id, $usuario['id'], $confirmou);
            registrar_acao('importacao.importar_todos', 'importacao', $id, $r + ['repeticao_confirmada' => $rep['repetida'] && $confirmou]);
            api_resposta(['ok' => true, 'concluida' => true, 'resultado' => $r,
                'inventario_url' => $r['inventario_id'] ? admin_url('inventario-detalhes') . '?id=' . (int)$r['inventario_id'] . '&filtro=sem_codigo' : null,
                'inventario_ativo' => $r['inventario_id'] !== null]);

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
