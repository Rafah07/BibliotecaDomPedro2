<?php
/**
 * Layout compartilhado do site público (cabeçalho, menu lateral e fim da página).
 * Os endereços são gerados por url()/asset() (config/app.php), então as páginas
 * funcionam na raiz do domínio ou em uma subpasta, em qualquer nível de pasta.
 */
require_once __DIR__ . '/../config/app.php';

/**
 * Abre a página: <head>, menu lateral e barra superior com a pesquisa.
 * @param string $titulo título da aba ('' na página inicial)
 * @param string $ativo  id do item do menu lateral (ver includes/sidebar.php)
 */
function page_start(string $titulo, string $ativo): void
{
    $currentPage = $ativo;
    $searchValue = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';
    $classeMain = $ativo === 'inicio' ? 'main-content' : 'main-content page-content';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173676">
    <title><?= $titulo !== '' ? e($titulo) . ' | ' : '' ?>Biblioteca Dom Pedro II</title>
    <link rel="icon" href="<?= e(asset('img/emblema.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
</head>
<body>
<div class="app-shell sidebar-collapsed">
    <div class="sidebar-backdrop" hidden></div>
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="<?= $classeMain ?>">
        <header class="topbar">
            <form class="search-bar" action="<?= e(url('biblioteca_php/catalogo.php')) ?>" method="get" role="search">
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                <input type="search" name="q" value="<?= e($searchValue) ?>" placeholder="Pesquisar livros, autores ou gêneros..." aria-label="Pesquisar no catálogo">
            </form>
            <a class="sidebar-logo" href="<?= e(url()) ?>" aria-label="Página inicial"><img src="<?= e(asset('img/emblema.png')) ?>" alt="Emblema da escola"></a>
        </header>
    <?php
}

function page_end(): void
{
    ?>
    </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
    <?php
}

function page_heading(string $eyebrow, string $title, string $subtitle, string $icon = 'auto_stories'): void
{
    ?>
    <section class="page-heading">
        <div class="page-heading-icon"><span class="material-symbols-outlined" aria-hidden="true"><?= e($icon) ?></span></div>
        <div>
            <p class="page-eyebrow"><?= e($eyebrow) ?></p>
            <h1><?= e($title) ?></h1>
            <p class="page-subtitle"><?= e($subtitle) ?></p>
        </div>
    </section>
    <?php
}

/** Capa do livro: a imagem cadastrada ou a capa colorida com o título. */
function book_cover(array $livro): string
{
    if (!empty($livro['capa'])) {
        return '<div class="book-cover has-image"><img src="' . e(url($livro['capa'])) . '" alt="Capa de ' . e($livro['titulo']) . '" loading="lazy"></div>';
    }
    return '<div class="book-cover ' . e($livro['classe']) . '"><span>' . e($livro['titulo']) . '</span></div>';
}

/** Primeira letra de um nome (com acentos), para os avatares. */
function initial(string $nome): string
{
    return mb_strtoupper(mb_substr(trim($nome), 0, 1));
}
