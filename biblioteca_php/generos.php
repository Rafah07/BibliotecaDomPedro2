<?php
/** Categorias do acervo com a quantidade de títulos; cada uma abre o catálogo filtrado. */
require_once __DIR__ . '/../includes/layout.php';

$catalogo = catalogo();
$categorias = $catalogo->categorias();

page_start('Categorias', 'generos', 'Categorias do acervo da Biblioteca Dom Pedro II.');
page_heading('Encontre seu estilo', 'Categorias do acervo', 'Escolha uma categoria para ver os livros dela no catálogo.', 'tags');
?>
<?= catalogo_aviso() ?>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">Explore por categoria</p><h2>Todas as categorias</h2></div><span class="result-count"><?= count($categorias) ?> <?= count($categorias) === 1 ? 'categoria' : 'categorias' ?></span></div>
    <?php if (!$categorias): ?>
        <div class="empty-state"><?= icon('tags') ?><h3>Nenhuma categoria para mostrar ainda</h3><p>As categorias aparecerão aqui quando os livros forem organizados pela biblioteca.</p></div>
    <?php else: ?>
    <div class="genre-grid">
        <?php foreach ($categorias as $g): ?>
        <a class="genre-card <?= e($g['cor']) ?>" href="<?= e(url('biblioteca_php/catalogo.php?categoria=' . $g['id'])) ?>">
            <span class="genre-icon"><?= icon($g['icon']) ?></span>
            <h3><?= e($g['nome']) ?></h3>
            <?php if ($g['desc'] !== ''): ?><p><?= e($g['desc']) ?></p><?php endif; ?>
            <div class="genre-footer"><span><?= $g['total'] ?> <?= $g['total'] === 1 ? 'livro' : 'livros' ?></span><?= icon('arrow-right') ?></div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php page_end(); ?>
