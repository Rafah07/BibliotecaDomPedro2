<?php
$livros = [
    ['titulo' => 'Jogos Vorazes', 'autor' => 'Suzanne Collins', 'classe' => 'cover-1'],
    ['titulo' => 'O Pequeno Príncipe', 'autor' => 'Antoine de Saint-Exupéry', 'classe' => 'cover-2'],
    ['titulo' => 'Harry Potter e a Pedra Filosofal', 'autor' => 'J. K. Rowling', 'classe' => 'cover-3'],
    ['titulo' => 'A Menina que Roubava Livros', 'autor' => 'Markus Zusak', 'classe' => 'cover-4'],
];

$generos = [
    ['nome' => 'Ficção', 'valor' => 32],
    ['nome' => 'Romance', 'valor' => 24],
    ['nome' => 'Fantasia', 'valor' => 20],
    ['nome' => 'Aventura', 'valor' => 14],
    ['nome' => 'História', 'valor' => 8],
    ['nome' => 'Outros', 'valor' => 2],
];

$autores = [
    ['nome' => 'Machado de Assis', 'buscas' => 28],
    ['nome' => 'J. K. Rowling', 'buscas' => 24],
    ['nome' => 'Suzanne Collins', 'buscas' => 18],
    ['nome' => 'Monteiro Lobato', 'buscas' => 16],
    ['nome' => 'Markus Zusak', 'buscas' => 14],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biblioteca Escolar</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <form class="search-bar" action="catalogo.php" method="get">
                <span class="search-icon">⌕</span>
                <input type="search" name="q" placeholder="Pesquisar livros, autores ou gêneros..." aria-label="Pesquisar">
            </form>

            <div class="brand-mark" aria-label="Logo da escola">
                <span>ESCOLA</span>
            </div>
        </header>

        <section class="hero">
            <div class="hero-overlay">
                <p class="eyebrow">Biblioteca escolar</p>
                <h1>Descubra<br>novos mundos</h1>
                <p>Leia, aprenda e se inspire na biblioteca da nossa escola.</p>
                <a class="primary-button" href="catalogo.php?filtro=novidades">Ver novidades →</a>
            </div>
            <div class="hero-quote">Ler também<br>transforma<br>realidades.</div>
            <div class="book-stack" aria-hidden="true">
                <span></span><span></span><span></span><span></span>
            </div>
        </section>

        <section class="dashboard-grid">
            <section class="panel recommendations-panel">
                <div class="panel-header">
                    <div>
                        <span class="section-icon">☆</span>
                        <h2>Recomendações da Biblioteca</h2>
                    </div>
                    <a href="catalogo.php">Ver todos →</a>
                </div>

                <div class="filter-tabs" role="tablist">
                    <button class="tab active" type="button">Destaques</button>
                    <button class="tab" type="button">Mais emprestados</button>
                    <button class="tab" type="button">Novidades</button>
                    <button class="tab" type="button">Escolha dos bibliotecários</button>
                </div>

                <div class="books-grid">
                    <?php foreach ($livros as $livro): ?>
                        <article class="book-card">
                            <div class="book-cover <?= htmlspecialchars($livro['classe']) ?>">
                                <span><?= htmlspecialchars($livro['titulo']) ?></span>
                            </div>
                            <h3><?= htmlspecialchars($livro['titulo']) ?></h3>
                            <p><?= htmlspecialchars($livro['autor']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="right-column">
                <section class="panel genres-panel">
                    <div class="panel-header compact">
                        <div>
                            <span class="section-icon">▥</span>
                            <h2>Gêneros mais procurados</h2>
                        </div>
                        <a href="generos.php">Ver todos →</a>
                    </div>

                    <div class="bars">
                        <?php foreach ($generos as $genero): ?>
                            <div class="bar-row">
                                <span><?= htmlspecialchars($genero['nome']) ?></span>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width: <?= (int)$genero['valor'] * 3 ?>%"></div>
                                </div>
                                <strong><?= (int)$genero['valor'] ?>%</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel authors-panel">
                    <div class="panel-header compact">
                        <div>
                            <span class="section-icon">●●</span>
                            <h2>Autores mais procurados</h2>
                        </div>
                        <a href="autores.php">Ver todos →</a>
                    </div>

                    <ol class="authors-list">
                        <?php foreach ($autores as $indice => $autor): ?>
                            <li>
                                <span class="ranking"><?= $indice + 1 ?></span>
                                <span class="avatar"><?= mb_strtoupper(mb_substr($autor['nome'], 0, 1)) ?></span>
                                <strong><?= htmlspecialchars($autor['nome']) ?></strong>
                                <span class="search-count"><?= (int)$autor['buscas'] ?> buscas</span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            </div>
        </section>
    </main>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
