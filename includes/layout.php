<?php
/**
 * Layout compartilhado do site público (menu lateral, barra superior, rodapé e componentes).
 * Os endereços são gerados por url()/asset() (config/app.php), então as páginas
 * funcionam na raiz do domínio ou em uma subpasta, em qualquer nível de pasta.
 *
 * O site público não carrega nada de servidores externos: os ícones são SVG embutidos
 * (admin/includes/icones.php) e as fontes são as do próprio sistema.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../admin/includes/icones.php';
require_once __DIR__ . '/catalogo.php';

/** Cabeçalhos de segurança das páginas públicas. */
function site_cabecalhos(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    // Só recursos do próprio site; nenhum script ou imagem de terceiros.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}

/**
 * Abre a página: <head>, menu lateral e barra superior com a pesquisa.
 * @param string $titulo    título da aba ('' na página inicial)
 * @param string $ativo     id do item do menu lateral (ver includes/sidebar.php)
 * @param string $descricao resumo da página para buscadores e compartilhamento
 */
function page_start(string $titulo, string $ativo, string $descricao = ''): void
{
    site_cabecalhos();
    $currentPage = $ativo;
    $searchValue = $ativo === 'catalogo' && isset($_GET['q']) && is_string($_GET['q']) ? mb_substr($_GET['q'], 0, 120) : '';
    $classeMain = $ativo === 'inicio' ? 'main-content' : 'main-content page-content';
    $descricao = $descricao !== '' ? $descricao : 'Catálogo on-line e informações da Biblioteca Dom Pedro II.';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173676">
    <meta name="description" content="<?= e($descricao) ?>">
    <title><?= $titulo !== '' ? e($titulo) . ' | ' : '' ?>Biblioteca Dom Pedro II</title>
    <link rel="icon" href="<?= e(asset('img/emblema-pequeno.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<div class="app-shell sidebar-collapsed">
    <div class="sidebar-backdrop" hidden></div>
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="<?= $classeMain ?>">
        <header class="topbar">
            <form class="search-bar" action="<?= e(url('biblioteca_php/catalogo.php')) ?>" method="get" role="search">
                <?= icon('search') ?>
                <label class="visually-hidden" for="busca-topo">Pesquisar no catálogo</label>
                <input id="busca-topo" type="search" name="q" value="<?= e($searchValue) ?>" placeholder="Pesquisar livros, autores ou assuntos..." maxlength="120" autocomplete="off">
                <button class="search-submit" type="submit">Buscar</button>
            </form>
            <a class="sidebar-logo" href="<?= e(url()) ?>" aria-label="Biblioteca Dom Pedro II — página inicial"><img src="<?= e(asset('img/emblema-pequeno.png')) ?>" alt="Emblema da escola" width="110" height="73"></a>
        </header>
        <main id="conteudo" tabindex="-1">
    <?php
}

function page_end(): void
{
    $site = site_conteudo();
    $escola = $site->valor('escola_nome');
    $creditos = $site->valor('creditos');
    ?>
        </main>
        <footer class="site-footer">
            <div class="footer-brand">
                <img src="<?= e(asset('img/emblema-pequeno.png')) ?>" alt="" width="72" height="48" loading="lazy">
                <div>
                    <strong>Biblioteca Dom Pedro II</strong>
                    <?php if ($escola !== ''): ?><span><?= e($escola) ?></span><?php endif; ?>
                </div>
            </div>
            <nav class="footer-links" aria-label="Links úteis">
                <a href="<?= e(url()) ?>">Início</a>
                <a href="<?= e(url('biblioteca_php/catalogo.php')) ?>">Catálogo</a>
                <a href="<?= e(url('biblioteca_php/sobre.php')) ?>">Sobre a biblioteca</a>
                <a href="<?= e(url('biblioteca_php/regras.php')) ?>">Regras e funcionamento</a>
            </nav>
            <div class="footer-info">
                <?php if ($site->preenchido('email')): ?><p><?= icon('mail') ?> <a href="mailto:<?= e($site->valor('email')) ?>"><?= e($site->valor('email')) ?></a></p><?php endif; ?>
                <?php if ($site->preenchido('telefone')): ?><p><?= icon('phone') ?> <?= e($site->valor('telefone')) ?></p><?php endif; ?>
                <?php if ($site->preenchido('endereco')): ?><p><?= icon('map-pin') ?> <?= e($site->valor('endereco')) ?></p><?php endif; ?>
                <?php if ($creditos !== ''): ?><p class="footer-credits"><?= e($creditos) ?></p><?php endif; ?>
                <p class="footer-staff"><a href="<?= e(url('admin/login.php')) ?>" rel="nofollow"><?= icon('lock') ?> Acesso da equipe</a></p>
            </div>
        </footer>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
    <?php
}

function page_heading(string $eyebrow, string $title, string $subtitle, string $icon = 'book-open'): void
{
    ?>
    <section class="page-heading">
        <div class="page-heading-icon"><?= icon($icon) ?></div>
        <div>
            <p class="page-eyebrow"><?= e($eyebrow) ?></p>
            <h1><?= e($title) ?></h1>
            <?php if ($subtitle !== ''): ?><p class="page-subtitle"><?= e($subtitle) ?></p><?php endif; ?>
        </div>
    </section>
    <?php
}

/** Endereço da página de um livro. */
function livro_url(int $id): string
{
    return url('biblioteca_php/livro.php?id=' . $id);
}

/** Capa do livro: a imagem cadastrada ou uma capa padrão desenhada com o título (nunca uma imagem aleatória). */
function book_cover(array $livro, string $extra = ''): string
{
    $classe = trim('book-cover ' . $extra);
    if (!empty($livro['capa'])) {
        return '<div class="' . e($classe) . ' has-image"><img src="' . e(url($livro['capa'])) . '" alt="Capa de ' . e($livro['titulo']) . '" loading="lazy" decoding="async"></div>';
    }
    return '<div class="' . e($classe . ' ' . $livro['classe']) . '" aria-hidden="true"><span class="cover-title">' . e($livro['titulo']) . '</span>'
        . ($livro['autor'] !== '' ? '<span class="cover-author">' . e($livro['autor']) . '</span>' : '') . '</div>';
}

/** Selo de disponibilidade do livro. */
function availability_badge(array $disponibilidade): string
{
    $icone = ['ok' => 'circle-check', 'busy' => 'hourglass', 'muted' => 'info'][$disponibilidade['tom']] ?? 'info';
    return '<span class="availability availability--' . e($disponibilidade['tom']) . '">' . icon($icone) . ' ' . e($disponibilidade['rotulo']) . '</span>';
}

/** Cartão de livro usado na página inicial, no catálogo e nos relacionados. */
function book_card(array $livro): string
{
    $meta = array_filter([$livro['editora'] ?? '', $livro['ano'] ? (string)$livro['ano'] : '']);
    ob_start();
    ?>
    <article class="book-card catalog-book">
        <a class="book-card-link" href="<?= e(livro_url($livro['id'])) ?>" tabindex="-1" aria-hidden="true"><?= book_cover($livro) ?></a>
        <div class="book-card-body">
            <?php if ($livro['categoria'] !== ''): ?><span class="book-tag"><?= e($livro['categoria']) ?></span><?php endif; ?>
            <h3><a href="<?= e(livro_url($livro['id'])) ?>"><?= e($livro['titulo']) ?></a></h3>
            <p class="book-author"><?= $livro['autor'] !== '' ? e($livro['autor']) : 'Autor não informado' ?></p>
            <?php if ($meta): ?><p class="book-meta"><?= e(implode(' · ', $meta)) ?></p><?php endif; ?>
            <?= availability_badge($livro['disponibilidade']) ?>
            <a class="details-link" href="<?= e(livro_url($livro['id'])) ?>">Ver detalhes<span class="visually-hidden"> de <?= e($livro['titulo']) ?></span> <?= icon('arrow-right') ?></a>
        </div>
    </article>
    <?php
    return (string)ob_get_clean();
}

/** Primeira letra de um nome (com acentos), para os avatares. */
function initial(string $nome): string
{
    return mb_strtoupper(mb_substr(trim($nome), 0, 1));
}
