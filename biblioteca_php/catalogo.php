<?php
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/dados-exemplo.php';
$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$genero = isset($_GET['genero']) && is_string($_GET['genero']) ? trim($_GET['genero']) : '';
$livrosFiltrados = array_values(array_filter($livrosExemplo, static function ($livro) use ($q, $genero) {
    $combinaBusca = $q === '' || stripos($livro['titulo'] . ' ' . $livro['autor'] . ' ' . $livro['genero'], $q) !== false;
    $combinaGenero = $genero === '' || $livro['genero'] === $genero;
    return $combinaBusca && $combinaGenero;
}));
page_start('Catálogo', 'catalogo');
page_heading('Explore a biblioteca', 'Catálogo de livros', 'Encontre sua próxima leitura entre os títulos de exemplo do nosso acervo.', 'menu_book');
?>
<div class="info-strip"><span class="material-symbols-outlined">info</span> Protótipo visual: os livros exibidos são exemplos, não um registro do acervo real.</div>
<section class="panel catalog-toolbar">
    <form action="catalogo.php" method="get" class="catalog-filters">
        <label class="field field-grow"><span>Pesquisar no catálogo</span><div class="field-control with-icon"><span class="material-symbols-outlined">search</span><input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Título, autor ou gênero"></div></label>
        <label class="field"><span>Gênero</span><select name="genero"><option value="">Todos os gêneros</option><?php foreach ($generosExemplo as $g): ?><option value="<?= htmlspecialchars($g['nome']) ?>" <?= $genero === $g['nome'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nome']) ?></option><?php endforeach; ?></select></label>
        <button class="action-button" type="submit"><span class="material-symbols-outlined">tune</span> Filtrar</button>
    </form>
    <div class="filter-chips">
        <a class="chip <?= $genero === '' ? 'selected' : '' ?>" href="catalogo.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Todos</a>
        <?php foreach (array_slice($generosExemplo, 0, 6) as $g): ?>
            <a class="chip <?= $genero === $g['nome'] ? 'selected' : '' ?>" href="catalogo.php?genero=<?= rawurlencode($g['nome']) ?><?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>"><?= htmlspecialchars($g['nome']) ?></a>
        <?php endforeach; ?>
    </div>
</section>
<section class="panel catalog-results">
    <div class="section-topline"><div><p class="page-eyebrow">Sua estante</p><h2>Livros disponíveis para explorar</h2></div><span class="result-count"><?= count($livrosFiltrados) ?> de <?= count($livrosExemplo) ?> exemplos</span></div>
    <?php if (!$livrosFiltrados): ?>
        <div class="empty-state"><span class="material-symbols-outlined">search_off</span><h3>Nenhum livro encontrado</h3><p>Experimente pesquisar outro título, autor ou gênero.</p><a href="catalogo.php" class="inline-link">Limpar filtros →</a></div>
    <?php else: ?>
        <div class="books-grid catalog-books">
        <?php foreach ($livrosFiltrados as $livro): ?>
            <article class="book-card catalog-book">
                <div class="book-cover <?= htmlspecialchars($livro['classe']) ?>"><span><?= htmlspecialchars($livro['titulo']) ?></span></div>
                <span class="book-tag"><?= htmlspecialchars($livro['genero']) ?></span>
                <h3><?= htmlspecialchars($livro['titulo']) ?></h3>
                <p><?= htmlspecialchars($livro['autor']) ?></p>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php page_end(); ?>
