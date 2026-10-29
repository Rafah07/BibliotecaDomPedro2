<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/catalogo.php';

$catalogo = catalogo();
$demonstracao = $catalogo->ehDemonstracao();
$generos = $catalogo->generos();

page_start('Gêneros', 'generos');
page_heading('Encontre seu estilo', 'Gêneros literários', 'Descubra histórias do seu jeito e explore diferentes tipos de leitura.', 'category');
?>
<?= catalogo_aviso() ?>
<section class="feature-banner genre-feature">
    <div><span class="feature-kicker">UM UNIVERSO DE LEITURAS</span><h2>Qual será sua próxima aventura?</h2><p>De mundos mágicos aos grandes clássicos: existe um livro para cada momento.</p><a class="light-link" href="<?= e(url('biblioteca_php/catalogo.php')) ?>">Explorar o catálogo <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div>
    <div class="feature-art" aria-hidden="true"><span class="material-symbols-outlined">auto_stories</span></div>
</section>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">Explore por categoria</p><h2>Todos os gêneros</h2></div><span class="result-count"><?= count($generos) ?> <?= count($generos) === 1 ? 'categoria' : 'categorias' ?><?= $demonstracao ? ' de exemplo' : '' ?></span></div>
    <?php if (!$generos): ?>
        <div class="empty-state"><span class="material-symbols-outlined" aria-hidden="true">category</span><h3>Nenhum gênero cadastrado ainda</h3><p>As categorias aparecerão aqui quando o acervo for organizado.</p></div>
    <?php else: ?>
    <div class="genre-grid">
        <?php foreach ($generos as $g): ?>
        <a class="genre-card <?= e($g['cor']) ?>" href="<?= e(url('biblioteca_php/catalogo.php?genero=' . rawurlencode($g['nome']))) ?>">
            <span class="genre-icon material-symbols-outlined" aria-hidden="true"><?= e($g['icon']) ?></span>
            <h3><?= e($g['nome']) ?></h3>
            <?php if ($g['desc'] !== ''): ?><p><?= e($g['desc']) ?></p><?php endif; ?>
            <div class="genre-footer"><span><?= (int)$g['total'] ?> <?= (int)$g['total'] === 1 ? 'livro' : 'livros' ?><?= $demonstracao ? ' de exemplo' : '' ?></span><span class="material-symbols-outlined" aria-hidden="true">arrow_outward</span></div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($demonstracao): ?><p class="prototype-footnote">* Quantidades calculadas a partir dos livros de exemplo.</p><?php endif; ?>
</section>
<?php page_end(); ?>
