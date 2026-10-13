<?php
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/dados-exemplo.php';
$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$autoresFiltrados = array_values(array_filter($autoresExemplo, static fn($a) => $q === '' || stripos($a['nome'] . ' ' . $a['tag'], $q) !== false));
page_start('Autores', 'autores');
page_heading('Conheça quem escreve', 'Autores e suas histórias', 'Grandes nomes da literatura e algumas de suas obras mais conhecidas.', 'edit_note');
?>
<section class="panel authors-spotlight">
    <div class="spotlight-symbol"><span class="material-symbols-outlined">history_edu</span></div>
    <div><p class="page-eyebrow">Autor em destaque</p><h2>Machado de Assis</h2><p>Um dos principais escritores brasileiros, conhecido por obras como <em>Dom Casmurro</em> e <em>Memórias Póstumas de Brás Cubas</em>.</p><a class="inline-link" href="catalogo.php?q=Machado+de+Assis">Ver livros do autor →</a></div>
</section>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">Descubra novas vozes</p><h2>Explore os autores</h2></div><form action="autores.php" method="get" class="mini-search"><span class="material-symbols-outlined">search</span><input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Pesquisar autor" aria-label="Pesquisar autor"></form></div>
    <?php if (!$autoresFiltrados): ?>
        <div class="empty-state"><span class="material-symbols-outlined">person_search</span><h3>Nenhum autor encontrado</h3><a href="autores.php" class="inline-link">Ver todos →</a></div>
    <?php else: ?>
    <div class="author-grid">
        <?php foreach ($autoresFiltrados as $a): ?>
        <article class="author-card"><div class="author-avatar <?= htmlspecialchars($a['cor']) ?>"><?= htmlspecialchars(mb_substr_fallback($a['nome'])) ?></div><div class="author-copy"><span class="author-tag"><?= htmlspecialchars($a['tag']) ?></span><h3><?= htmlspecialchars($a['nome']) ?></h3><p><?= htmlspecialchars($a['desc']) ?></p><a href="catalogo.php?q=<?= rawurlencode($a['nome']) ?>" class="inline-link">Ver livros <span aria-hidden="true">→</span></a></div></article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<p class="prototype-footnote">Autores listados apenas para demonstrar como a página poderá ficar.</p>
<?php page_end(); ?>
<?php
function mb_substr_fallback(string $name): string {
    // Primeira letra dos nomes de exemplo usa caracteres ASCII.
    return strtoupper(substr($name, 0, 1));
}
?>
