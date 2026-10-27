<?php
/** Tela provisória para as páginas do painel que ainda serão desenvolvidas. */
require_once __DIR__ . '/includes/layout.php';

$pagina = isset($_GET['pagina']) && is_string($_GET['pagina']) ? $_GET['pagina'] : '';
$item = admin_menu_item($pagina);
if (!$item || !empty($item['pronta']) || !empty($item['demo'])) {
    $pagina = '';
    $item = ['label' => 'Página não encontrada', 'icon' => 'construction'];
}

admin_page_start($pagina, $item['label'], $item['label'], 'Painel Administrativo · Biblioteca Dom Pedro II');
?>

<section class="adm-card adm-wip">
    <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon('construction') ?></span>
    <span class="adm-pill adm-tone--amber">Em desenvolvimento</span>
    <h1><?= htmlspecialchars($item['label']) ?></h1>
    <?php if ($pagina === ''): ?>
        <p>O endereço acessado não corresponde a nenhuma página do painel.</p>
    <?php else: ?>
        <p>Esta área será implementada nas próximas etapas do sistema. Por enquanto, nenhum dado é consultado ou alterado aqui.</p>
    <?php endif; ?>
    <div class="adm-wip__actions">
        <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('dashboard')) ?>"><?= icon('arrow-left') ?> Voltar ao dashboard</a>
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('site')) ?>"><?= icon('house') ?> Ir para o site</a>
    </div>
</section>

<?php
admin_page_end();
