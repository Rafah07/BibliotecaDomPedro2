<?php
/**
 * Solicitações e pendências (uma página só): pedidos dos participantes (novo lote, ajuda, troca de
 * equipe) e problemas registrados nos lotes, com filtros por tipo, situação e lote.
 */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();

$ver = in_array($_GET['ver'] ?? $_POST['ver'] ?? '', ['solicitacoes', 'pendencias'], true) ? ($_GET['ver'] ?? $_POST['ver']) : 'tudo';
$validosStatus = ['abertas' => 'Em aberto', 'resolvidas' => 'Respondidas e resolvidas', 'todas' => 'Todas'];
$status = isset($validosStatus[$_GET['status'] ?? $_POST['status_f'] ?? '']) ? ($_GET['status'] ?? $_POST['status_f']) : 'abertas';
$tiposSol = ['novo_lote' => 'Pedido de novo lote', 'ajuda' => 'Pedido de ajuda', 'troca_equipe' => 'Mudança de equipe'];
$tipo = (string)($_GET['tipo'] ?? $_POST['tipo_f'] ?? '');
$tipo = isset($tiposSol[$tipo]) || isset(Reorganizacao::TIPOS_PENDENCIA[$tipo]) ? $tipo : '';
$loteId = (int)($_GET['lote'] ?? $_POST['lote_f'] ?? 0);
$aqui = reorg_url('admin/solicitacoes.php?' . http_build_query(array_filter(['ver' => $ver !== 'tudo' ? $ver : null, 'status' => $status !== 'abertas' ? $status : null, 'tipo' => $tipo ?: null, 'lote' => $loteId ?: null])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reorg_admin_acao(function () use ($r, $adminId) {
        if (($_POST['acao'] ?? '') === 'pendencia_status') {
            $r->atualizarPendencia((int)$_POST['pendencia_id'], (string)$_POST['status'], $adminId, (string)($_POST['resolucao'] ?? ''));
            return 'Pendência atualizada.';
        }
        if (($_POST['acao'] ?? '') === 'solicitacao') {
            $r->responderSolicitacao((int)$_POST['id'], (string)$_POST['decisao'], $adminId, (string)($_POST['resposta'] ?? ''),
                ['lote_id' => (int)($_POST['lote_id'] ?? 0), 'admin_id' => (int)($_POST['admin_id'] ?? 0)]);
            return 'Solicitação respondida (a pessoa é notificada).';
        }
        throw new AcervoException('Ação desconhecida.');
    }, 'Feito.', $aqui);
}

$mostrarSol = $ver !== 'pendencias' && ($tipo === '' || isset($tiposSol[$tipo])) && !$loteId;
$mostrarPend = $ver !== 'solicitacoes' && ($tipo === '' || isset(Reorganizacao::TIPOS_PENDENCIA[$tipo]));
$solicitacoes = [];
if ($mostrarSol) {
    $solicitacoes = $status === 'abertas' ? array_merge($r->solicitacoes('pendente', $tipo), $r->solicitacoes('encaminhada', $tipo)) : $r->solicitacoes('', $tipo);
    if ($status === 'resolvidas') {
        $solicitacoes = array_values(array_filter($solicitacoes, fn ($q) => !in_array($q['status'], ['pendente', 'encaminhada'], true)));
    }
}
$pendencias = $mostrarPend ? $r->pendencias(['abertas' => 'abertas', 'resolvidas' => 'resolvida', 'todas' => ''][$status], $loteId, isset(Reorganizacao::TIPOS_PENDENCIA[$tipo]) ? $tipo : '') : [];
$lotesAtivos = $r->lotes('em_andamento');
$todosLotes = $r->lotes();
$admins = reorg_administradores();
$ocultos = ['status_f' => $status, 'tipo_f' => $tipo, 'lote_f' => $loteId ?: '', 'ver' => $ver];

reorg_admin_inicio('solicitacoes', 'Solicitações e pendências');
?>
<section class="adm-page-head reorg-head"><div><h1>Solicitações e pendências</h1><p>Pedidos dos participantes e problemas registrados nos lotes, num lugar só. Nenhum lote é atribuído automaticamente: a decisão é sempre da administração. Exemplares com pendência aberta impedem a conclusão do lote.</p></div></section>
<?php $invPend = $GLOBALS['reorg_inventario'] ?? null; ?>
<?php if ($invPend && $invPend['status'] !== 'aberto'): $resInv = inventario_repo()->resumo((int)$invPend['id']); $nInv = $resInv['divergencias_pendentes'] + $resInv['ausentes_pendentes']; ?>
    <p class="adm-filtro-ativo"><?= icon('clipboard-check') ?> Pendências da conferência do inventário (divergências e ausências sem decisão): <strong><?= (int)$nInv ?></strong>.
        <a class="adm-link" href="<?= e(admin_url('inventario-detalhes') . '?id=' . (int)$invPend['id'] . '&filtro=pendentes#itens') ?>">Abrir e decidir</a></p>
<?php endif; ?>

<nav class="adm-tabs adm-tabs--links adm-tabs--solta" aria-label="O que mostrar">
    <?php foreach (['tudo' => 'Tudo', 'solicitacoes' => 'Solicitações', 'pendencias' => 'Pendências'] as $k => $rot): ?>
        <a class="adm-tab<?= $ver === $k ? ' is-active' : '' ?>" href="?<?= e(http_build_query(array_filter(['ver' => $k !== 'tudo' ? $k : null, 'status' => $status !== 'abertas' ? $status : null]))) ?>"<?= $ver === $k ? ' aria-current="page"' : '' ?>><?= e($rot) ?></a>
    <?php endforeach; ?>
</nav>
<form class="adm-card reorg-filtros-barra" method="get" role="search" aria-label="Filtrar solicitações e pendências">
    <?php if ($ver !== 'tudo'): ?><input type="hidden" name="ver" value="<?= e($ver) ?>"><?php endif; ?>
    <label class="adm-field"><span class="adm-field__label">Situação</span><select class="adm-select" name="status" data-reorg-auto-submit>
        <?php foreach ($validosStatus as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Tipo</span><select class="adm-select" name="tipo" data-reorg-auto-submit><option value="">Todos</option>
        <?php if ($ver !== 'pendencias'): ?><optgroup label="Solicitações"><?php foreach ($tiposSol as $k => $v): ?><option value="<?= $k ?>"<?= $tipo === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        <?php if ($ver !== 'solicitacoes'): ?><optgroup label="Pendências"><?php foreach (Reorganizacao::TIPOS_PENDENCIA as $k => $v): ?><option value="<?= $k ?>"<?= $tipo === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></optgroup><?php endif; ?></select></label>
    <?php if ($ver !== 'solicitacoes'): ?><label class="adm-field"><span class="adm-field__label">Lote (pendências)</span><select class="adm-select" name="lote" data-reorg-auto-submit><option value="0">Todos</option><?= reorg_opcoes($todosLotes, $loteId) ?></select></label><?php endif; ?>
    <div class="reorg-filtros-barra__acoes"><button class="adm-button" type="submit"><?= icon('search') ?> Filtrar</button>
        <?php if ($tipo || $loteId || $status !== 'abertas'): ?><a class="adm-link" href="?<?= e(http_build_query(array_filter(['ver' => $ver !== 'tudo' ? $ver : null]))) ?>">Limpar</a><?php endif; ?></div>
</form>

<?php if ($mostrarSol): ?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Solicitações <span class="adm-tabs__count"><?= count($solicitacoes) ?></span></h2></div>
    <?php if (!$solicitacoes): ?><p class="adm-table__muted">Nenhuma solicitação com estes filtros.</p><?php endif; ?>
    <?php foreach ($solicitacoes as $q): $ativa = $q['sessao_status'] === 'ativa'; ?>
    <article class="reorg-sol">
        <header><strong><?= e($tiposSol[$q['tipo']] ?? $q['tipo']) ?></strong>
            · <?= e($q['nome']) ?> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($q['sessao_id'])) ?></small>
            <?= $q['equipe_atual'] ? ' · ' . e(Reorganizacao::EQUIPES[$q['equipe_atual']]) : '' ?>
            <?= $q['equipe_desejada'] ? ' → <strong>' . e(Reorganizacao::EQUIPES[$q['equipe_desejada']]) . '</strong>' : '' ?>
            <?= $q['lote_nome'] ? ' · ' . e($q['lote_nome']) : '' ?>
            · <small><?= e(admin_data_hora($q['criado_em'])) ?></small>
            <?= reorg_status_badge($q['status'], in_array($q['status'], ['pendente', 'encaminhada'], true) ? 'red' : 'muted') ?>
            <?= !$ativa ? reorg_status_badge('sessão encerrada', 'muted') : '' ?></header>
        <?php if ($q['problema_tipo'] || $q['etapa']): ?><p><small><?= e(implode(' · ', array_filter([$q['etapa'], Reorganizacao::TIPOS_PROBLEMA[$q['problema_tipo']] ?? $q['problema_tipo']]))) ?></small></p><?php endif; ?>
        <?php if ($q['descricao']): ?><p><?= nl2br(e($q['descricao'])) ?></p><?php endif; ?>
        <?php if ($q['resposta']): ?><p class="reorg-resposta"><strong>Resposta<?= $q['respondida_por_nome'] ? ' de ' . e($q['respondida_por_nome']) : '' ?>:</strong> <?= e($q['resposta']) ?></p><?php endif; ?>
        <?php if (in_array($q['status'], ['pendente', 'encaminhada'], true)): ?>
        <form method="post" class="reorg-linha"><?= csrf_campo() ?><input type="hidden" name="acao" value="solicitacao"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <?php foreach ($ocultos as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
            <input class="adm-input" name="resposta" placeholder="Resposta ou observação (opcional)" maxlength="1000" aria-label="Resposta">
            <?php if ($q['tipo'] === 'novo_lote'): ?>
                <?php if ($ativa && $lotesAtivos): ?><select class="adm-select adm-select--sm" name="lote_id" aria-label="Lote"><?= reorg_opcoes($lotesAtivos, null) ?></select>
                    <button class="adm-button adm-button--sm adm-button--primary" name="decisao" value="atribuir">Atribuir este lote</button><?php endif; ?>
                <button class="adm-button adm-button--sm" name="decisao" value="aprovar">Aprovar (atribuo depois)</button>
                <a class="adm-button adm-button--sm" href="<?= e(admin_url('lote-novo') . ($invPend ? '?inventario=' . (int)$invPend['id'] : '')) ?>">Criar lote</a>
                <button class="adm-button adm-button--sm adm-button--danger-text" name="decisao" value="recusar">Recusar</button>
            <?php elseif ($q['tipo'] === 'troca_equipe'): ?>
                <?php if ($ativa): ?><button class="adm-button adm-button--sm adm-button--primary" name="decisao" value="aprovar">Aprovar troca</button><?php endif; ?>
                <button class="adm-button adm-button--sm adm-button--danger-text" name="decisao" value="recusar">Recusar</button>
            <?php else: ?>
                <button class="adm-button adm-button--sm adm-button--primary" name="decisao" value="responder">Responder</button>
                <button class="adm-button adm-button--sm" name="decisao" value="resolver">Marcar resolvido</button>
                <select class="adm-select adm-select--sm" name="admin_id" aria-label="Encaminhar para"><?= reorg_opcoes(array_filter($admins, fn ($a) => (int)$a['id'] !== $adminId), null) ?></select>
                <button class="adm-button adm-button--sm" name="decisao" value="encaminhar">Encaminhar</button>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($mostrarPend): ?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Pendências <span class="adm-tabs__count"><?= count($pendencias) ?></span></h2></div>
    <?= reorg_tabela_pendencias($pendencias, true, $ocultos) ?>
</section>
<?php endif; ?>
<?php reorg_admin_fim();
