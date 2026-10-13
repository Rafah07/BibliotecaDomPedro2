<?php
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/dados-exemplo.php';
page_start('Gêneros', 'generos');
page_heading('Encontre seu estilo', 'Gêneros literários', 'Descubra histórias do seu jeito e explore diferentes tipos de leitura.', 'category');
?>
<section class="feature-banner genre-feature">
    <div><span class="feature-kicker">UM UNIVERSO DE LEITURAS</span><h2>Qual será sua próxima aventura?</h2><p>De mundos mágicos aos grandes clássicos: existe um livro para cada momento.</p><a class="light-link" href="catalogo.php">Explorar o catálogo <span class="material-symbols-outlined">arrow_forward</span></a></div>
    <div class="feature-art" aria-hidden="true"><span class="material-symbols-outlined">auto_stories</span></div>
</section>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">Explore por categoria</p><h2>Todos os gêneros</h2></div><span class="result-count"><?= count($generosExemplo) ?> categorias de exemplo</span></div>
    <div class="genre-grid">
        <?php foreach ($generosExemplo as $g): ?>
        <?php $qt = count(array_filter($livrosExemplo, static fn($l) => $l['genero'] === $g['nome'])); ?>
        <a class="genre-card <?= htmlspecialchars($g['cor']) ?>" href="catalogo.php?genero=<?= rawurlencode($g['nome']) ?>">
            <span class="genre-icon material-symbols-outlined"><?= htmlspecialchars($g['icon']) ?></span>
            <h3><?= htmlspecialchars($g['nome']) ?></h3>
            <p><?= htmlspecialchars($g['desc']) ?></p>
            <div class="genre-footer"><span><?= $qt ?> <?= $qt === 1 ? 'livro' : 'livros' ?> de exemplo</span><span class="material-symbols-outlined">arrow_outward</span></div>
        </a>
        <?php endforeach; ?>
    </div>
    <p class="prototype-footnote">* Quantidades calculadas a partir dos livros ilustrativos deste protótipo.</p>
</section>
<?php page_end(); ?>
