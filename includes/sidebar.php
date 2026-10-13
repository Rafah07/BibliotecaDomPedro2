<?php
// $base aponta da página atual até a raiz do projeto.
$base = $base ?? '../';
$currentPage = $currentPage ?? 'inicio';
$navItems = [
    ['id' => 'inicio', 'label' => 'Início', 'icon' => 'home', 'href' => 'Index/Index.php'],
    ['id' => 'catalogo', 'label' => 'Catálogo', 'icon' => 'menu_book', 'href' => 'biblioteca_php/catalogo.php'],
    ['id' => 'generos', 'label' => 'Gêneros', 'icon' => 'category', 'href' => 'biblioteca_php/generos.php'],
    ['id' => 'autores', 'label' => 'Autores', 'icon' => 'edit_note', 'href' => 'biblioteca_php/autores.php'],
    ['id' => 'sobre', 'label' => 'Sobre', 'icon' => 'info', 'href' => 'biblioteca_php/sobre.php'],
];
$footerItems = [
    ['id' => 'horarios', 'label' => 'Horários', 'icon' => 'schedule', 'href' => 'biblioteca_php/horarios.php'],
    ['id' => 'contato', 'label' => 'Contato', 'icon' => 'mail', 'href' => 'biblioteca_php/contato.php'],
];
?>
<button class="mobile-menu-button" type="button" aria-label="Abrir menu" aria-expanded="false">
    <span class="material-symbols-outlined">menu</span>
</button>
<aside class="sidebar" id="menu-lateral" aria-label="Menu lateral">
    <div class="library-brand">
        <a href="<?= htmlspecialchars($base) ?>Index/Index.php" class="book-logo" aria-label="Biblioteca Dom Pedro II — Início">
            <span class="material-symbols-outlined">menu_book</span>
        </a>
        <div class="brand-name"><strong>Biblioteca</strong><span>Dom Pedro II</span></div>
    </div>
    <button class="sidebar-toggle" type="button" aria-label="Expandir menu" aria-expanded="false" title="Expandir ou recolher menu">
        <span class="material-symbols-outlined">chevron_right</span>
    </button>
    <div class="sidebar-divider"></div>
    <nav class="sidebar-nav" aria-label="Navegação principal">
        <?php foreach ($navItems as $item): ?>
            <a href="<?= htmlspecialchars($base . $item['href']) ?>" class="<?= $currentPage === $item['id'] ? 'active' : '' ?>" title="<?= htmlspecialchars($item['label']) ?>" <?= $currentPage === $item['id'] ? 'aria-current="page"' : '' ?>>
                <span class="material-symbols-outlined nav-icon" aria-hidden="true"><?= htmlspecialchars($item['icon']) ?></span>
                <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
        <div class="sidebar-divider"></div>
        <?php foreach ($footerItems as $item): ?>
            <a href="<?= htmlspecialchars($base . $item['href']) ?>" class="<?= $currentPage === $item['id'] ? 'active' : '' ?>" title="<?= htmlspecialchars($item['label']) ?>" <?= $currentPage === $item['id'] ? 'aria-current="page"' : '' ?>>
                <span class="material-symbols-outlined nav-icon" aria-hidden="true"><?= htmlspecialchars($item['icon']) ?></span>
                <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</aside>
