<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/catalogo.php';

$texto = static fn (string $campo): string => isset($_GET[$campo]) && is_string($_GET[$campo]) ? trim($_GET[$campo]) : '';
$q = mb_substr($texto('q'), 0, 120);
$genero = mb_substr($texto('genero'), 0, 80);
$pagina = max(1, (int)$texto('pagina'));

$catalogo = catalogo();
$demonstracao = $catalogo->ehDemonstracao();
$generos = $catalogo->generos();
$resultado = $catalogo->buscarLivros($q, $genero, $pagina);
$livros = $resultado['itens'];

/** Endereço do catálogo mantendo os filtros atuais. */
$link = static function (array $mudancas) use ($q, $genero): string {
    $params = array_filter(array_merge(['q' => $q, 'genero' => $genero], $mudancas), static fn ($v) => $v !== '' && $v !== null && $v !== 1);
    return url('biblioteca_php/catalogo.php') . ($params ? '?' . http_build_query($params) : '');
};

page_start('Catálogo', 'catalogo');
page_heading('Explore a biblioteca', 'Catálogo de livros', 'Encontre sua próxima leitura pesquisando por título, autor, gênero ou ISBN.', 'menu_book');
?>
<?= catalogo_aviso() ?>
<section class="panel catalog-toolbar">
    <form action="<?= e(url('biblioteca_php/catalogo.php')) ?>" method="get" class="catalog-filters">
        <label class="field field-grow"><span>Pesquisar no catálogo</span><div class="field-control with-icon"><span class="material-symbols-outlined" aria-hidden="true">search</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Título, autor, gênero ou ISBN" maxlength="120"></div></label>
        <label class="field"><span>Gênero</span><select name="genero"><option value="">Todos os gêneros</option><?php foreach ($generos as $g): ?><option value="<?= e($g['nome']) ?>" <?= $genero === $g['nome'] ? 'selected' : '' ?>><?= e($g['nome']) ?></option><?php endforeach; ?></select></label>
        <button class="action-button" type="submit"><span class="material-symbols-outlined" aria-hidden="true">tune</span> Filtrar</button>
    </form>
    <?php if ($generos): ?>
    <div class="filter-chips">
        <a class="chip <?= $genero === '' ? 'selected' : '' ?>" href="<?= e($link(['genero' => ''])) ?>">Todos</a>
        <?php foreach (array_slice($generos, 0, 6) as $g): ?>
            <a class="chip <?= $genero === $g['nome'] ? 'selected' : '' ?>" href="<?= e($link(['genero' => $g['nome']])) ?>"><?= e($g['nome']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<section class="panel catalog-results">
    <div class="section-topline">
        <div><p class="page-eyebrow">Sua estante</p><h2><?= $demonstracao ? 'Livros de exemplo' : 'Livros do acervo' ?></h2></div>
        <span class="result-count"><?= $resultado['total'] ?> <?= $resultado['total'] === 1 ? 'livro' : 'livros' ?><?= $demonstracao ? ' de exemplo' : '' ?></span>
    </div>
    <?php if (!$livros): ?>
        <?php if ($q !== '' || $genero !== ''): ?>
            <div class="empty-state"><span class="material-symbols-outlined" aria-hidden="true">search_off</span><h3>Nenhum livro encontrado</h3><p>Experimente pesquisar outro título, autor ou gênero.</p><a href="<?= e(url('biblioteca_php/catalogo.php')) ?>" class="inline-link">Limpar filtros →</a></div>
        <?php else: ?>
            <div class="empty-state"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><h3>O catálogo ainda está vazio</h3><p>Os livros aparecerão aqui assim que forem cadastrados pela biblioteca.</p></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="books-grid catalog-books">
        <?php foreach ($livros as $livro): ?>
            <article class="book-card catalog-book">
                <?= book_cover($livro) ?>
                <?php if ($livro['genero'] !== ''): ?><span class="book-tag"><?= e($livro['genero']) ?></span><?php endif; ?>
                <h3><?= e($livro['titulo']) ?></h3>
                <p><?= e($livro['autor']) ?></p>
                <?php if ($livro['isbn'] !== ''): ?><p class="book-isbn">ISBN <?= e($livro['isbn']) ?></p><?php endif; ?>
                <?php if ($livro['disponibilidade']): ?>
                    <span class="availability availability--<?= e($livro['disponibilidade']['tom']) ?>"><?= e($livro['disponibilidade']['rotulo']) ?></span>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
        <?php if ($resultado['paginas'] > 1): ?>
            <nav class="pagination" aria-label="Páginas do catálogo">
                <?php if ($resultado['pagina'] > 1): ?><a class="inline-link" href="<?= e($link(['pagina' => $resultado['pagina'] - 1])) ?>">← Anterior</a><?php endif; ?>
                <span>Página <?= $resultado['pagina'] ?> de <?= $resultado['paginas'] ?></span>
                <?php if ($resultado['pagina'] < $resultado['paginas']): ?><a class="inline-link" href="<?= e($link(['pagina' => $resultado['pagina'] + 1])) ?>">Próxima →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php page_end(); ?>
