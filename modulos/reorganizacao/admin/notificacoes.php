<?php
/** Notificações internas da reorganização para o administrador logado. */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reorg_admin_acao(fn () => $r->marcarLidasAdmin($adminId), 'Notificações marcadas como lidas.');
}
$lista = $r->notificacoesAdmin($adminId, false, 200);
reorg_admin_inicio('inicio', 'Notificações');
?>
<section class="adm-page-head"><div><h1>Notificações</h1><p>Entradas e saídas de participantes, pedidos, problemas informados, registros enviados para revisão, etapas concluídas e perdas de conexão.</p></div>
    <div class="adm-page-head__actions"><form method="post"><?= csrf_campo() ?><button class="adm-button" type="submit"><?= icon('check-check') ?> Marcar todas como lidas</button></form></div></section>
<section class="adm-card adm-panel">
    <?php if (!$lista): ?><p class="adm-table__muted">Nenhuma notificação.</p><?php endif; ?>
    <ul class="reorg-lista-notif">
        <?php foreach ($lista as $n): ?>
            <li class="<?= $n['lida_em'] ? '' : 'is-nova' ?>"><span><?= e($n['texto']) ?><?= $n['lote_id'] ? ' <a href="' . e(reorg_url('admin/lote.php?id=' . $n['lote_id'])) . '">abrir lote</a>' : '' ?></span><small><?= e(admin_data_hora($n['criado_em'])) ?></small></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php reorg_admin_fim();
