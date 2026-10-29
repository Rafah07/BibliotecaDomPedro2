<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/catalogo.php';

$q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$catalogo = catalogo();
$demonstracao = $catalogo->ehDemonstracao();
$autores = $catalogo->autores($q);
$catalogoDoAutor = static fn (string $nome): string => url('biblioteca_php/catalogo.php?q=' . rawurlencode($nome));

page_start('Autores', 'autores');
page_heading('Conheça quem escreve', 'Autores e suas histórias', 'Grandes nomes da literatura e algumas de suas obras mais conhecidas.', 'edit_note');
?>
<?= catalogo_aviso() ?>
<?php if ($demonstracao): ?>
<section class="panel authors-spotlight">
    <div class="spotlight-symbol"><span class="material-symbols-outlined" aria-hidden="true">history_edu</span></div>
    <div><p class="page-eyebrow">Autor em destaque</p><h2>Machado de Assis</h2><p>Um dos principais escritores brasileiros, conhecido por obras como <em>Dom Casmurro</em> e <em>Memórias Póstumas de Brás Cubas</em>.</p><a class="inline-link" href="<?= e($catalogoDoAutor('Machado de Assis')) ?>">Ver livros do autor →</a></div>
</section>
<?php endif; ?>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">Descubra novas vozes</p><h2>Explore os autores</h2></div><form action="<?= e(url('biblioteca_php/autores.php')) ?>" method="get" class="mini-search" role="search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar autor" aria-label="Pesquisar autor" maxlength="120"></form></div>
    <?php if (!$autores): ?>
        <div class="empty-state"><span class="material-symbols-outlined" aria-hidden="true">person_search</span><h3><?= $q !== '' ? 'Nenhum autor encontrado' : 'Nenhum autor cadastrado ainda' ?></h3><?php if ($q !== ''): ?><a href="<?= e(url('biblioteca_php/autores.php')) ?>" class="inline-link">Ver todos →</a><?php endif; ?></div>
    <?php else: ?>
    <div class="author-grid">
        <?php foreach ($autores as $a): ?>
        <article class="author-card"><div class="author-avatar <?= e($a['cor']) ?>"><?= e(initial($a['nome'])) ?></div><div class="author-copy"><span class="author-tag"><?= e($a['tag']) ?></span><h3><?= e($a['nome']) ?></h3><?php if ($a['desc'] !== ''): ?><p><?= e($a['desc']) ?></p><?php endif; ?><a href="<?= e($catalogoDoAutor($a['nome'])) ?>" class="inline-link">Ver livros <span aria-hidden="true">→</span></a></div></article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php if ($demonstracao): ?><p class="prototype-footnote">Autores listados apenas para demonstrar como a página poderá ficar.</p><?php endif; ?>
<?php page_end(); ?>
