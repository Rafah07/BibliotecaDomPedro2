<?php
/** Layout compartilhado do painel administrativo. */
require_once __DIR__ . '/icones.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/dados-demo.php';

/** @param string[] $estilos folhas de estilo extras (relativas a admin/), carregadas após admin.css */
function admin_page_start(string $paginaAtual, string $tituloPagina, string $tituloHeader, string $subtituloHeader, array $estilos = []): void
{
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#143477">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars($tituloPagina) ?> | Painel — Biblioteca Dom Pedro II</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(admin_asset('assets/css/admin.css?v=20261028')) ?>">
    <?php foreach ($estilos as $estilo): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(admin_asset($estilo)) ?>">
    <?php endforeach; ?>
    <script>
        // Aplica o estado salvo do menu antes da pintura para evitar "pulo" visual.
        try { if (localStorage.getItem('adm-sidebar') === 'expanded') document.documentElement.classList.add('adm-sidebar-expanded'); } catch (e) {}
    </script>
</head>
<body class="adm-body">
<a class="adm-skip-link" href="#conteudo">Pular para o conteúdo</a>
<div class="adm-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="adm-main">
        <?php include __DIR__ . '/header.php'; ?>
        <main class="adm-content" id="conteudo">
    <?php
}

/** @param string[] $scripts scripts extras (relativos a admin/), carregados após admin.js */
function admin_page_end(array $scripts = []): void
{
    ?>
        </main>
        <footer class="adm-footer">
            Biblioteca Dom Pedro II · Painel administrativo (protótipo — dados demonstrativos)
        </footer>
    </div>
</div>
<div class="adm-toasts" aria-live="polite" aria-atomic="false"></div>
<div class="adm-tooltip" role="tooltip" hidden></div>
<script src="<?= htmlspecialchars(admin_asset('assets/js/admin.js?v=20261028')) ?>"></script>
<?php foreach ($scripts as $script): ?>
<script src="<?= htmlspecialchars(admin_asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}
