<?php
$q = $_GET['q'] ?? '';
$filtro = $_GET['filtro'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo | Biblioteca</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-content">
        <section class="panel" style="margin-top: 24px;">
            <h1>Catálogo</h1>
            <?php if ($q): ?>
                <p>Resultados para: <strong><?= htmlspecialchars($q) ?></strong></p>
            <?php elseif ($filtro): ?>
                <p>Filtro ativo: <strong><?= htmlspecialchars($filtro) ?></strong></p>
            <?php else: ?>
                <p>Aqui ficará a listagem completa dos livros.</p>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
