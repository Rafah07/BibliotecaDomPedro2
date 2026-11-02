<?php
/**
 * Menu lateral do site público. Espera $currentPage (id do item ativo), definido por page_start().
 * Computador: barra compacta só com ícones, que se expande pelo botão "Expandir menu".
 * Celular: painel lateral aberto pelo botão "Menu" da barra superior (includes/layout.php).
 */
$currentPage = $currentPage ?? 'inicio';
$navItems = [
    ['id' => 'inicio', 'label' => 'Início', 'icon' => 'house', 'href' => ''],
    ['id' => 'catalogo', 'label' => 'Catálogo', 'icon' => 'library-big', 'href' => 'biblioteca_php/catalogo.php'],
    ['id' => 'generos', 'label' => 'Categorias', 'icon' => 'tags', 'href' => 'biblioteca_php/generos.php'],
    ['id' => 'autores', 'label' => 'Autores', 'icon' => 'feather', 'href' => 'biblioteca_php/autores.php'],
];
$footerItems = [
    ['id' => 'sobre', 'label' => 'Sobre a biblioteca', 'icon' => 'info', 'href' => 'biblioteca_php/sobre.php'],
    ['id' => 'regras', 'label' => 'Regras e funcionamento', 'icon' => 'file-text', 'href' => 'biblioteca_php/regras.php'],
];
$navLink = static function (array $item) use ($currentPage): void {
    $ativo = $currentPage === $item['id'];
    ?>
    <a href="<?= e(url($item['href'])) ?>" class="<?= $ativo ? 'active' : '' ?>" data-tooltip="<?= e($item['label']) ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
        <span class="nav-icon"><?= icon($item['icon']) ?></span>
        <span class="nav-label"><?= e($item['label']) ?></span>
    </a>
    <?php
};
?>
<aside class="sidebar" id="menu-lateral" aria-label="Menu do site">
    <div class="library-brand">
        <a href="<?= e(url()) ?>" class="book-logo" aria-label="Biblioteca Dom Pedro II — Início"><?= icon('book-open') ?></a>
        <div class="brand-name"><strong>Biblioteca</strong><span>Dom Pedro II</span></div>
        <button class="sidebar-close" type="button" aria-label="Fechar menu"><?= icon('x') ?></button>
    </div>
    <button class="sidebar-toggle" type="button" aria-label="Expandir menu" aria-expanded="false" aria-controls="menu-lateral" title="Expandir ou recolher menu">
        <?= icon('chevron-right') ?>
    </button>
    <nav class="sidebar-nav" aria-label="Navegação principal">
        <p class="sidebar-section">Acervo</p>
        <?php foreach ($navItems as $item) { $navLink($item); } ?>
        <div class="sidebar-divider" role="presentation"></div>
        <p class="sidebar-section">A biblioteca</p>
        <?php foreach ($footerItems as $item) { $navLink($item); } ?>
    </nav>
</aside>
