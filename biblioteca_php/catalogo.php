<?php
/**
 * Catálogo público: pesquisa (com e sem acentos), filtros, ordenação e paginação no servidor.
 * Os filtros ficam na URL, então cada página pode ser compartilhada ou atualizada sem perder a busca.
 */
require_once __DIR__ . '/../includes/layout.php';

use Biblioteca\Services\CatalogoService;

$catalogo = catalogo();
$f = CatalogoService::filtros($_GET);
$categorias = $catalogo->categorias();
$resultado = $catalogo->buscarLivros($f);
$livros = $resultado['itens'];
$nomeAutor = $catalogo->nomeAutor($f['autor']);
$categoriaAtual = null;
foreach ($categorias as $c) {
    if ($c['id'] === $f['categoria']) {
        $categoriaAtual = $c['nome'];
    }
}
$filtrando = CatalogoService::temFiltros($f);

/** Endereço do catálogo mantendo os filtros atuais (a página volta para 1, a menos que seja informada). */
$link = static function (array $mudancas = []) use ($f): string {
    $params = array_merge($f, ['pagina' => 1], $mudancas);
    $params = array_filter($params, static fn ($v, $k) => !in_array($v, ['', null, 0], true)
        && !($k === 'campo' && $v === 'tudo') && !($k === 'ordem' && $v === 'titulo') && !($k === 'pagina' && $v === 1), ARRAY_FILTER_USE_BOTH);
    return url('biblioteca_php/catalogo.php') . ($params ? '?' . http_build_query($params) : '');
};

/** Números de página a mostrar: primeira, última e vizinhas da atual. */
$paginasVisiveis = static function (int $atual, int $total): array {
    $lista = array_unique(array_filter([1, $atual - 2, $atual - 1, $atual, $atual + 1, $atual + 2, $total], static fn ($p) => $p >= 1 && $p <= $total));
    sort($lista);
    return $lista;
};

$titulo = $f['q'] !== '' ? 'Resultados para "' . $f['q'] . '"' : 'Catálogo';
page_start($titulo, 'catalogo', 'Pesquise os livros da Biblioteca Dom Pedro II por título, autor, ISBN ou assunto.');
page_heading('Explore a biblioteca', 'Catálogo de livros', 'Pesquise por título, autor, ISBN, assunto ou categoria. A busca funciona com ou sem acentos.', 'library-big');
?>
<?= catalogo_aviso() ?>
<section class="panel catalog-toolbar" aria-label="Pesquisa e filtros">
    <form action="<?= e(url('biblioteca_php/catalogo.php')) ?>" method="get" class="catalog-form" data-loading-form>
        <div class="catalog-search-row">
            <label class="field field-grow" for="q"><span>Pesquisar</span>
                <div class="field-control with-icon"><?= icon('search') ?><input id="q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Ex.: pequeno principe, Machado de Assis, 9788535902778" maxlength="120"></div>
            </label>
            <label class="field" for="campo"><span>Pesquisar em</span>
                <select id="campo" name="campo">
                    <?php foreach (CatalogoService::CAMPOS_BUSCA as $valor => $rotulo): ?>
                        <option value="<?= e($valor) ?>"<?= $f['campo'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="action-button" type="submit"><?= icon('search') ?> Pesquisar</button>
        </div>
        <details class="catalog-more"<?= $filtrando && ($f['categoria'] || $f['ano_de'] !== null || $f['ano_ate'] !== null || $f['disponibilidade'] !== '') ? ' open' : '' ?>>
            <summary><?= icon('sliders-horizontal') ?> Filtros e ordenação</summary>
            <div class="catalog-filter-grid">
                <label class="field" for="categoria"><span>Categoria</span>
                    <select id="categoria" name="categoria">
                        <option value="">Todas as categorias</option>
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= $c['id'] ?>"<?= $f['categoria'] === $c['id'] ? ' selected' : '' ?>><?= e($c['nome']) ?> (<?= $c['total'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <fieldset class="field year-range">
                    <legend>Ano de publicação</legend>
                    <div>
                        <label class="visually-hidden" for="ano_de">De (ano)</label>
                        <input id="ano_de" type="number" name="ano_de" inputmode="numeric" min="1000" max="2100" placeholder="De" value="<?= e((string)($f['ano_de'] ?? '')) ?>">
                        <span aria-hidden="true">–</span>
                        <label class="visually-hidden" for="ano_ate">Até (ano)</label>
                        <input id="ano_ate" type="number" name="ano_ate" inputmode="numeric" min="1000" max="2100" placeholder="Até" value="<?= e((string)($f['ano_ate'] ?? '')) ?>">
                    </div>
                </fieldset>
                <label class="field" for="disponibilidade"><span>Disponibilidade física</span>
                    <select id="disponibilidade" name="disponibilidade">
                        <?php foreach (CatalogoService::DISPONIBILIDADES as $valor => $rotulo): ?>
                            <option value="<?= e($valor) ?>"<?= $f['disponibilidade'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field" for="ordem"><span>Ordenar por</span>
                    <select id="ordem" name="ordem">
                        <?php foreach (CatalogoService::ORDENS as $valor => $rotulo): ?>
                            <option value="<?= e($valor) ?>"<?= $f['ordem'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php if ($f['autor'] && $nomeAutor !== null): ?><input type="hidden" name="autor" value="<?= (int)$f['autor'] ?>"><?php endif; ?>
            <div class="catalog-filter-actions">
                <button class="action-button" type="submit"><?= icon('check') ?> Aplicar filtros</button>
                <?php if ($filtrando || $f['ordem'] !== 'titulo'): ?><a class="ghost-button" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><?= icon('filter-x') ?> Limpar filtros</a><?php endif; ?>
            </div>
        </details>
    </form>

    <?php if ($filtrando): ?>
        <div class="active-filters" aria-label="Filtros aplicados">
            <span>Filtros:</span>
            <?php if ($f['q'] !== ''): ?><a class="chip selected" href="<?= e($link(['q' => '', 'campo' => 'tudo'])) ?>">"<?= e($f['q']) ?>"<?= $f['campo'] !== 'tudo' ? ' em ' . e(mb_strtolower(CatalogoService::CAMPOS_BUSCA[$f['campo']])) : '' ?> <?= icon('x') ?><span class="visually-hidden"> remover</span></a><?php endif; ?>
            <?php if ($categoriaAtual !== null): ?><a class="chip selected" href="<?= e($link(['categoria' => 0])) ?>"><?= e($categoriaAtual) ?> <?= icon('x') ?><span class="visually-hidden"> remover</span></a><?php endif; ?>
            <?php if ($nomeAutor !== null): ?><a class="chip selected" href="<?= e($link(['autor' => 0])) ?>">Autor: <?= e($nomeAutor) ?> <?= icon('x') ?><span class="visually-hidden"> remover</span></a><?php endif; ?>
            <?php if ($f['ano_de'] !== null || $f['ano_ate'] !== null): ?><a class="chip selected" href="<?= e($link(['ano_de' => null, 'ano_ate' => null])) ?>">Ano: <?= e(($f['ano_de'] ?? '…') . '–' . ($f['ano_ate'] ?? '…')) ?> <?= icon('x') ?><span class="visually-hidden"> remover</span></a><?php endif; ?>
            <?php if ($f['disponibilidade'] !== ''): ?><a class="chip selected" href="<?= e($link(['disponibilidade' => ''])) ?>"><?= e(CatalogoService::DISPONIBILIDADES[$f['disponibilidade']]) ?> <?= icon('x') ?><span class="visually-hidden"> remover</span></a><?php endif; ?>
            <a class="inline-link" href="<?= e(url('biblioteca_php/catalogo.php')) ?>">Limpar tudo</a>
        </div>
    <?php endif; ?>
</section>

<section class="panel catalog-results" aria-labelledby="titulo-resultados" aria-live="polite">
    <div class="section-topline">
        <div><p class="page-eyebrow"><?= e(CatalogoService::ORDENS[$f['ordem']]) ?></p><h2 id="titulo-resultados"><?= $filtrando ? 'Resultados da pesquisa' : 'Livros do acervo' ?></h2></div>
        <span class="result-count"><?= number_format($resultado['total'], 0, ',', '.') ?> <?= $resultado['total'] === 1 ? 'livro' : 'livros' ?><?php if ($resultado['paginas'] > 1): ?> · página <?= $resultado['pagina'] ?> de <?= $resultado['paginas'] ?><?php endif; ?></span>
    </div>
    <?php if (!$livros): ?>
        <?php if ($filtrando && $catalogo->disponivel()): ?>
            <div class="empty-state"><?= icon('search') ?><h3>Nenhum livro encontrado</h3>
                <p>Confira a grafia, use menos palavras ou pesquise em "Todos os campos".<?= $f['disponibilidade'] !== '' ? ' Lembre-se de que o filtro de disponibilidade mostra só livros com exemplares físicos já identificados.' : '' ?></p>
                <a href="<?= e(url('biblioteca_php/catalogo.php')) ?>" class="ghost-button"><?= icon('filter-x') ?> Limpar filtros</a></div>
        <?php elseif ($catalogo->disponivel()): ?>
            <div class="empty-state"><?= icon('inbox') ?><h3>Nenhum livro nesta página</h3><a class="inline-link" href="<?= e($link()) ?>">Voltar ao início do catálogo</a></div>
        <?php else: ?>
            <div class="empty-state"><?= icon('inbox') ?><h3>O catálogo ainda não está disponível</h3><p>Os livros aparecerão aqui assim que forem cadastrados pela biblioteca.</p></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="books-grid catalog-books">
            <?php foreach ($livros as $livro): ?><?= book_card($livro) ?><?php endforeach; ?>
        </div>
        <?php if ($resultado['paginas'] > 1): ?>
            <nav class="pagination" aria-label="Páginas do catálogo">
                <?php if ($resultado['pagina'] > 1): ?>
                    <a class="page-link" href="<?= e($link(['pagina' => $resultado['pagina'] - 1])) ?>" rel="prev"><?= icon('chevron-left') ?> Anterior</a>
                <?php endif; ?>
                <?php $anterior = 0; foreach ($paginasVisiveis($resultado['pagina'], $resultado['paginas']) as $p): ?>
                    <?php if ($p - $anterior > 1): ?><span class="page-gap" aria-hidden="true">…</span><?php endif; ?>
                    <?php if ($p === $resultado['pagina']): ?>
                        <span class="page-link current" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= e($link(['pagina' => $p])) ?>" aria-label="Página <?= $p ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php $anterior = $p; endforeach; ?>
                <?php if ($resultado['pagina'] < $resultado['paginas']): ?>
                    <a class="page-link" href="<?= e($link(['pagina' => $resultado['pagina'] + 1])) ?>" rel="next">Próxima <?= icon('chevron-right') ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<p class="catalog-note"><?= icon('info') ?> A disponibilidade considera somente os exemplares físicos já identificados pela biblioteca. Para pegar um livro emprestado, procure a equipe no balcão.</p>
<?php page_end(); ?>
