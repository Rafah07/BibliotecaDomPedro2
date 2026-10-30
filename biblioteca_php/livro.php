<?php
/**
 * Página pública de um livro: dados bibliográficos, CDD aprovada e disponibilidade física.
 * Nunca mostra quem está com o livro, datas de empréstimo, códigos dos exemplares ou dados internos.
 */
require_once __DIR__ . '/../includes/layout.php';

$catalogo = catalogo();
$id = isset($_GET['id']) && is_string($_GET['id']) && preg_match('/^\d{1,18}$/', $_GET['id']) ? (int)$_GET['id'] : 0;
$livro = $catalogo->livro($id);

if (!$livro) {
    http_response_code($catalogo->fonte() === 'indisponivel' ? 503 : 404);
    page_start('Livro não encontrado', 'catalogo');
    ?>
    <?= catalogo_aviso() ?>
    <section class="panel empty-state spaced-panel">
        <?= icon('book-x') ?>
        <h1 class="empty-title">Livro não encontrado</h1>
        <p>O livro procurado não existe no catálogo ou não está mais disponível para consulta.</p>
        <a class="action-button" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><?= icon('library-big') ?> Voltar ao catálogo</a>
    </section>
    <?php
    page_end();
    exit;
}

$relacionados = $catalogo->relacionados($livro);
$ex = $livro['exemplares'];
$disp = $livro['disponibilidade'];
$campos = array_filter([
    'Editora' => $livro['editora'],
    'Ano de publicação' => $livro['ano'] !== null ? (string)$livro['ano'] : '',
    'Edição' => $livro['edicao'],
    'ISBN' => $livro['isbn'],
    'Idioma' => $livro['idioma'],
    'Número de páginas' => $livro['paginas'] !== null ? (string)$livro['paginas'] : '',
], static fn ($v) => $v !== '');

page_start($livro['titulo'], 'catalogo', mb_substr($livro['titulo'] . ($livro['autor'] !== '' ? ', de ' . $livro['autor'] : '') . ' — Biblioteca Dom Pedro II', 0, 160));
?>
<nav class="breadcrumb" aria-label="Você está em">
    <a href="<?= e(url()) ?>">Início</a> <span aria-hidden="true">/</span>
    <a href="<?= e(url('biblioteca_php/catalogo.php')) ?>">Catálogo</a> <span aria-hidden="true">/</span>
    <span aria-current="page"><?= e($livro['titulo']) ?></span>
</nav>

<article class="book-detail">
    <div class="book-detail-cover">
        <?= book_cover($livro, 'book-cover--large') ?>
    </div>
    <div class="book-detail-main">
        <?php if ($livro['categorias']): ?>
            <div class="book-detail-tags">
                <?php foreach ($livro['categorias'] as $c): ?><a class="book-tag" href="<?= e(url('biblioteca_php/catalogo.php?categoria=' . (int)$c['id'])) ?>"><?= e($c['nome']) ?></a><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <h1><?= e($livro['titulo']) ?></h1>
        <?php if ($livro['subtitulo'] !== ''): ?><p class="book-subtitle"><?= e($livro['subtitulo']) ?></p><?php endif; ?>
        <p class="book-detail-authors">
            <?php if ($livro['autores']): ?>
                <?php foreach ($livro['autores'] as $i => $a): ?><?= $i ? ', ' : '' ?><a href="<?= e(url('biblioteca_php/catalogo.php?autor=' . (int)$a['id'])) ?>"><?= e($a['nome']) ?></a><?php endforeach; ?>
            <?php else: ?>
                Autor não informado
            <?php endif; ?>
        </p>

        <section class="availability-box availability-box--<?= e($disp['tom']) ?>" aria-labelledby="titulo-disponibilidade">
            <h2 id="titulo-disponibilidade"><?= icon($disp['tom'] === 'ok' ? 'circle-check' : ($disp['tom'] === 'busy' ? 'hourglass' : 'info')) ?> Disponibilidade</h2>
            <?php if ($disp['estado'] === 'sem_exemplar'): ?>
                <p><strong>Livro cadastrado no catálogo, mas os exemplares físicos ainda não foram identificados.</strong></p>
                <p>A biblioteca ainda está conferindo o acervo. Pergunte à equipe se o livro pode ser encontrado na estante.</p>
            <?php elseif ($disp['estado'] === 'disponivel'): ?>
                <p><strong><?= $ex['disponiveis'] === 1 ? '1 exemplar disponível' : $ex['disponiveis'] . ' exemplares disponíveis' ?></strong> de <?= $ex['total'] ?> <?= $ex['total'] === 1 ? 'exemplar identificado' : 'exemplares identificados' ?>.</p>
                <p>Para pegar emprestado, procure a equipe no balcão da biblioteca.</p>
            <?php else: ?>
                <p><strong>Nenhum exemplar disponível no momento.</strong> <?= $ex['total'] === 1 ? 'O único exemplar identificado está emprestado ou em preparo/manutenção.' : 'Os ' . $ex['total'] . ' exemplares identificados estão emprestados ou em preparo/manutenção.' ?></p>
                <p>Volte a consultar mais tarde ou pergunte à equipe da biblioteca.</p>
            <?php endif; ?>
            <?php if ($ex['total'] > 0 && $disp['estado'] !== 'sem_exemplar'): ?>
                <ul class="availability-counts">
                    <li><span>Disponíveis</span><strong><?= $ex['disponiveis'] ?></strong></li>
                    <li><span>Emprestados</span><strong><?= $ex['emprestados'] ?></strong></li>
                    <?php if ($ex['outros'] > 0): ?><li><span>Em preparo ou manutenção</span><strong><?= $ex['outros'] ?></strong></li><?php endif; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($livro['sinopse'] !== ''): ?>
            <section class="book-section" aria-labelledby="titulo-sinopse">
                <h2 id="titulo-sinopse">Sinopse</h2>
                <div class="rich-text"><?= site_texto_html($livro['sinopse']) ?></div>
            </section>
        <?php endif; ?>

        <section class="book-section" aria-labelledby="titulo-ficha">
            <h2 id="titulo-ficha">Informações bibliográficas</h2>
            <dl class="book-facts">
                <?php foreach ($campos as $rotulo => $valor): ?>
                    <div><dt><?= e($rotulo) ?></dt><dd><?= e($valor) ?></dd></div>
                <?php endforeach; ?>
                <?php if ($livro['assunto'] !== ''): ?><div><dt>Assunto</dt><dd><?= e($livro['assunto']) ?></dd></div><?php endif; ?>
                <?php if ($livro['cdd']): ?>
                    <div><dt>Classificação (CDD)</dt><dd><span class="mono"><?= e($livro['cdd']['cdd_aprovada']) ?></span><?= !empty($livro['cdd']['descricao']) ? ' — ' . e($livro['cdd']['descricao']) : '' ?></dd></div>
                <?php endif; ?>
                <?php if ($livro['palavras_chave']): ?>
                    <div class="book-facts-wide"><dt>Palavras-chave</dt><dd class="keyword-list">
                        <?php foreach ($livro['palavras_chave'] as $p): ?><a class="chip" href="<?= e(url('biblioteca_php/catalogo.php?' . http_build_query(['q' => $p, 'campo' => 'assunto']))) ?>"><?= e($p) ?></a><?php endforeach; ?>
                    </dd></div>
                <?php endif; ?>
            </dl>
            <?php if (count($campos) < 3): ?><p class="muted-text">Algumas informações deste livro ainda não foram cadastradas pela biblioteca.</p><?php endif; ?>
        </section>

        <a class="inline-link" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><?= icon('arrow-left') ?> Voltar ao catálogo</a>
    </div>
</article>

<?php foreach (['autor' => 'Mais livros do mesmo autor', 'categoria' => 'Da mesma categoria'] as $tipo => $tituloSecao): ?>
    <?php if ($relacionados[$tipo]): ?>
        <section class="panel spaced-panel" aria-labelledby="titulo-rel-<?= $tipo ?>">
            <div class="section-topline"><div><p class="page-eyebrow">Você também pode gostar</p><h2 id="titulo-rel-<?= $tipo ?>"><?= e($tituloSecao) ?></h2></div></div>
            <div class="books-grid catalog-books">
                <?php foreach ($relacionados[$tipo] as $r): ?><?= book_card($r) ?><?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endforeach; ?>
<?php page_end(); ?>
