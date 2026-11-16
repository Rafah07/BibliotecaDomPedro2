<?php
/**
 * Barra de navegação inferior do celular (até 900 px de largura; no computador fica oculta).
 *
 * Atalhos para as páginas mais usadas durante a organização física da biblioteca:
 * Conferência (do inventário em andamento), Meus lotes, Planilha BDP e Organização Física.
 * O último botão abre o menu completo (as demais páginas continuam lá, com as mesmas permissões).
 * Os itens só aparecem para quem pode abrir a página (mesmas regras do menu lateral).
 */
$paginaNav = $GLOBALS['admin_pagina_atual'] ?? '';
$inventarioAtivo = null;
if (db_modo() !== 'demo') {
    try {
        $inventarioAtivo = db_query("SELECT id FROM inventarios WHERE status = 'em_andamento' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $inventarioAtivo = null; // sem inventário (ou banco antigo): o atalho abre a lista de inventários
    }
}
$conferenciaUrl = $inventarioAtivo ? admin_url('inventario-conferencia') . '?id=' . (int)$inventarioAtivo : admin_url('inventario');
$pode = static function (string $id): bool {
    $item = admin_menu_item($id);
    return $item !== null && (!isset($item['permissao']) || auth_pode($item['permissao']));
};
$itensNav = [];
if ($pode('inventario')) {
    $itensNav[] = ['Conferência', 'clipboard-check', $conferenciaUrl, in_array($paginaNav, ['inventario', 'inventario-editar', 'inventario-detalhes', 'inventario-relatorio', 'duvidas'], true)];
}
if ($pode('lotes')) {
    $itensNav[] = ['Meus lotes', 'layers', $inventarioAtivo ? $conferenciaUrl . '#meus-lotes' : admin_url('lotes'), in_array($paginaNav, ['lotes', 'lote', 'lotes-produtividade'], true)];
}
if ($pode('planilha-bdp')) {
    $itensNav[] = ['Planilha BDP', 'stamp', admin_url('planilha-bdp'), $paginaNav === 'planilha-bdp'];
}
if ($pode('organizacao-fisica')) {
    $itensNav[] = ['Organização', 'columns-3', admin_url('organizacao-fisica'), in_array($paginaNav, ['organizacao-fisica', 'organizacao-mapa', 'organizacao-etiquetas', 'movimentacao-lote', 'localizacoes', 'localizacao'], true)];
}
?>
<nav class="adm-nav-movel" aria-label="Atalhos do celular">
    <ul>
        <?php foreach ($itensNav as [$rotulo, $icone, $href, $ativo]): ?>
            <li><a class="adm-nav-movel__item<?= $ativo ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
                <?= icon($icone) ?><span><?= e($rotulo) ?></span></a></li>
        <?php endforeach; ?>
        <li><button class="adm-nav-movel__item" type="button" data-mobile-open aria-controls="adm-sidebar" aria-expanded="false" aria-label="Abrir menu">
            <?= icon('menu') ?><span>Menu</span></button></li>
    </ul>
</nav>
