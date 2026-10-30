<?php
/** Autores com títulos no acervo; cada um abre o catálogo filtrado por autor. */
require_once __DIR__ . '/../includes/layout.php';

$q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$catalogo = catalogo();
$autores = $catalogo->autores($q);

page_start('Autores', 'autores', 'Autores com livros no acervo da Biblioteca Dom Pedro II.');
page_heading('Conheça quem escreve', 'Autores do acervo', 'Os autores com mais títulos aparecem primeiro. Pesquise com ou sem acentos.', 'feather');
?>
<?= catalogo_aviso() ?>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">Descubra novas vozes</p><h2><?= $q !== '' ? 'Autores encontrados' : 'Explore os autores' ?></h2></div>
        <form action="<?= e(url('biblioteca_php/autores.php')) ?>" method="get" class="mini-search" role="search"><?= icon('search') ?><label class="visually-hidden" for="busca-autor">Pesquisar autor</label><input id="busca-autor" type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar autor" maxlength="120"></form></div>
    <?php if (!$autores): ?>
        <div class="empty-state"><?= icon('user-round') ?><h3><?= $q !== '' ? 'Nenhum autor encontrado' : 'Nenhum autor para mostrar ainda' ?></h3><?php if ($q !== ''): ?><a href="<?= e(url('biblioteca_php/autores.php')) ?>" class="inline-link">Ver todos os autores</a><?php endif; ?></div>
    <?php else: ?>
    <div class="author-grid">
        <?php foreach ($autores as $a): ?>
        <article class="author-card"><div class="author-avatar <?= e($a['cor']) ?>" aria-hidden="true"><?= e(initial($a['nome'])) ?></div><div class="author-copy"><span class="author-tag"><?= $a['total'] === 1 ? '1 título no acervo' : $a['total'] . ' títulos no acervo' ?></span><h3><?= e($a['nome']) ?></h3><a href="<?= e(url('biblioteca_php/catalogo.php?autor=' . $a['id'])) ?>" class="inline-link">Ver livros<span class="visually-hidden"> de <?= e($a['nome']) ?></span> <?= icon('arrow-right') ?></a></div></article>
        <?php endforeach; ?>
    </div>
    <?php if ($q === '' && count($autores) >= 60): ?><p class="muted-text">Mostrando os 60 autores com mais títulos. Use a pesquisa para encontrar outros.</p><?php endif; ?>
    <?php endif; ?>
</section>
<?php page_end(); ?>
