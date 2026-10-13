<?php
/** Layout compartilhado das páginas internas. */
function page_start(string $title, string $active): void {
    $base = '../';
    $currentPage = $active;
    $searchValue = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173676">
    <title><?= htmlspecialchars($title) ?> | Biblioteca Dom Pedro II</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=20261013">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
</head>
<body>
<div class="app-shell sidebar-collapsed">
    <div class="sidebar-backdrop" hidden></div>
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content page-content">
        <header class="topbar">
            <form class="search-bar" action="catalogo.php" method="get">
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                <input type="search" name="q" value="<?= htmlspecialchars($searchValue) ?>" placeholder="Pesquisar livros, autores ou gêneros..." aria-label="Pesquisar no catálogo">
            </form>
            <a class="sidebar-logo" href="../Index/Index.php" aria-label="Página inicial"><img src="../assets/img/emblema.png" alt="Emblema da escola"></a>
        </header>
    <?php
}
function page_end(): void {
    ?>
    </main>
</div>
<script src="../assets/js/app.js?v=20261013"></script>
</body>
</html>
    <?php
}
function page_heading(string $eyebrow, string $title, string $subtitle, string $icon = 'auto_stories'): void {
    ?>
    <section class="page-heading">
        <div class="page-heading-icon"><span class="material-symbols-outlined" aria-hidden="true"><?= htmlspecialchars($icon) ?></span></div>
        <div>
            <p class="page-eyebrow"><?= htmlspecialchars($eyebrow) ?></p>
            <h1><?= htmlspecialchars($title) ?></h1>
            <p class="page-subtitle"><?= htmlspecialchars($subtitle) ?></p>
        </div>
    </section>
    <?php
}
