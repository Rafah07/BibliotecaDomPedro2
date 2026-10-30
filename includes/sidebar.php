<?php
/** Menu lateral do site público. Espera $currentPage (id do item ativo), definido por page_start(). */
$currentPage = $currentPage ?? 'inicio';
$navItems = [
    ['id' => 'inicio', 'label' => 'Início', 'icon' => 'home', 'href' => ''],
    ['id' => 'catalogo', 'label' => 'Catálogo', 'icon' => 'menu_book', 'href' => 'biblioteca_php/catalogo.php'],
    ['id' => 'generos', 'label' => 'Gêneros', 'icon' => 'category', 'href' => 'biblioteca_php/generos.php'],
    ['id' => 'autores', 'label' => 'Autores', 'icon' => 'edit_note', 'href' => 'biblioteca_php/autores.php'],
    ['id' => 'sobre', 'label' => 'Sobre', 'icon' => 'info', 'href' => 'biblioteca_php/sobre.php'],
];
$footerItems = [
    ['id' => 'horarios', 'label' => 'Horários', 'icon' => 'schedule', 'href' => 'biblioteca_php/horarios.php'],
    ['id' => 'contato', 'label' => 'Contato', 'icon' => 'mail', 'href' => 'biblioteca_php/contato.php'],
];
$navLink = static function (array $item) use ($currentPage): void {
    $ativo = $currentPage === $item['id'];
    ?>
    <a href="<?= e(url($item['href'])) ?>" class="<?= $ativo ? 'active' : '' ?>" title="<?= e($item['label']) ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
        <span class="material-symbols-outlined nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span>
        <span class="nav-label"><?= e($item['label']) ?></span>
    </a>
    <?php
};
?>
<button class="mobile-menu-button" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="menu-lateral">
    <span class="material-symbols-outlined">menu</span>
</button>
<aside class="sidebar" id="menu-lateral" aria-label="Menu lateral">
    <div class="library-brand">
        <a href="<?= e(url()) ?>" class="book-logo" aria-label="Biblioteca Dom Pedro II — Início">
            <span class="material-symbols-outlined">menu_book</span>
        </a>
        <div class="brand-name"><strong>Biblioteca</strong><span>Dom Pedro II</span></div>
    </div>
    <button class="sidebar-toggle" type="button" aria-label="Expandir menu" aria-expanded="false" aria-controls="menu-lateral" title="Expandir ou recolher menu">
        <span class="material-symbols-outlined">chevron_right</span>
    </button>
    <div class="sidebar-divider"></div>
    <nav class="sidebar-nav" aria-label="Navegação principal">
        <?php foreach ($navItems as $item) { $navLink($item); } ?>
    </nav>
    <div class="sidebar-bottom">
        <div class="sidebar-divider"></div>
        <?php foreach ($footerItems as $item) { $navLink($item); } ?>
    </div>
</aside>
