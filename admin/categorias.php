<?php
/**
 * Categorias do acervo: listagem real (tabela categorias), pesquisa, ordenação, paginação,
 * desativação e reativação. Cadastro e edição ficam em categoria.php.
 *
 * - Consultar: todos os perfis (painel.ver). Cadastrar, editar, desativar e reativar: categorias.gerenciar.
 * - Nenhuma categoria é excluída; uma categoria com livros ativos não pode ser desativada.
 * - Sem o MySQL (modo demonstrativo) a página mostra as categorias fictícias, só para consulta.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';
require_once __DIR__ . '/includes/categorias.php';

use Biblioteca\Services\AcervoException;

$usuario = auth_exigir('painel.ver');
$base = admin_url('categorias');
$podeGerenciar = auth_pode('categorias.gerenciar');
$mysql = categorias_mysql();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    auth_exigir('categorias.gerenciar');
    if (!$mysql) {
        admin_flash('aviso', 'Nada foi gravado.', 'No modo demonstrativo as categorias são fictícias e não podem ser alteradas.');
        admin_redirecionar($base);
    }
    $ativar = admin_param($_POST, 'acao') === 'reativar';
    try {
        $mudou = categorias_servico()->definirAtiva((int)admin_param($_POST, 'id'), $ativar, $usuario['id']);
        $mudou
            ? admin_flash('sucesso', $ativar ? 'Categoria reativada.' : 'Categoria desativada.', $ativar
                ? 'Ela volta a aparecer no cadastro de livros, nos filtros e no site público.'
                : 'Ela deixa de aparecer no cadastro de livros, nos filtros e no site público. Pode ser reativada depois.')
            : admin_flash('aviso', 'Nada foi alterado.', $ativar ? 'A categoria já estava ativa.' : 'A categoria já estava desativada.');
    } catch (AcervoException $e) {
        admin_flash('erro', 'A categoria não foi alterada.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'A categoria não foi alterada.', categorias_erro_tecnico($e, 'desativação de categoria'));
    }
    admin_redirecionar(admin_url_filtros($base, ['q' => admin_param($_POST, 'q'), 'situacao' => admin_param($_POST, 'situacao')]));
}

$aba = admin_param($_GET, 'aba') === 'antigas' && $mysql ? 'antigas' : 'atuais';
$filtros = categorias_filtros($_GET);
try {
    if ($mysql) {
        $resumo = categorias_servico()->resumo();
        $duplicidades = categorias_servico()->possiveisDuplicidades();
        if ($aba === 'antigas') {
            $antigas = categorias_servico()->antigas($filtros['q']);
        } else {
            $resultado = categorias_servico()->listar($filtros, max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
        }
    } else {
        $resultado = categorias_demo($filtros);
        $resumo = ['ativas' => $resultado['total'], 'inativas' => 0, 'em_uso' => count(array_filter($resultado['itens'], fn ($c) => $c['livros'] > 0)), 'sem_categoria' => 0];
        $duplicidades = [];
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar as categorias', categorias_erro_tecnico($e, 'listagem de categorias'));
    exit;
}
$num = fn (int $n) => number_format($n, 0, ',', '.');
$temFiltro = $filtros['q'] !== '' || $filtros['situacao'] !== 'ativas';

admin_page_start('categorias', 'Categorias', 'Categorias', 'Organização literária do acervo', ['assets/css/acervo.css?v=20261113', 'assets/css/emprestimos.css?v=20261113']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Categorias']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Categorias do Acervo</h1>
        <p>Gêneros e áreas usados para classificar os livros. Elas aparecem no cadastro de livros, nos filtros do painel e no catálogo do site.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeGerenciar && $mysql): ?><a class="adm-button adm-button--primary" href="<?= e(admin_url('categoria')) ?>"><?= icon('plus') ?> Cadastrar categoria</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if (!$mysql): ?>
    <div class="adm-demo-banner" role="note"><?= icon('info') ?>
        <span><strong>Modo demonstrativo.</strong> Estas categorias são fictícias e servem só para consulta. Com o banco de dados configurado, a bibliotecária cadastra, edita e desativa as categorias reais aqui.</span>
    </div>
<?php endif; ?>

<section class="adm-stats" aria-label="Resumo das categorias">
    <?php foreach ([
        ['Categorias ativas', $resumo['ativas'], 'Disponíveis no cadastro de livros', 'tags', 'blue', null],
        ['Em uso', $resumo['em_uso'], 'Com pelo menos um livro ativo', 'library-big', 'indigo', null],
        ['Desativadas', $resumo['inativas'], 'Não aparecem para novos livros', 'archive', 'muted', $mysql ? admin_url_filtros($base, ['situacao' => 'inativas']) : null],
        ['Livros sem categoria', $resumo['sem_categoria'], 'Livros ativos ainda sem categoria', 'book-x', $resumo['sem_categoria'] ? 'amber' : 'green', $mysql ? admin_url_filtros(admin_url('acervo'), ['categoria' => 'sem']) : null],
    ] as [$titulo, $valor, $desc, $ic, $tom, $href]): ?>
        <<?= $href ? 'a href="' . e($href) . '"' : 'article' ?> class="adm-card adm-stat adm-stat--compact<?= $href ? ' adm-stat--action' : '' ?>">
            <div class="adm-stat__top"><p class="adm-stat__title"><?= e($titulo) ?></p><span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($ic) ?></span></div>
            <p class="adm-stat__value"><?= $num((int)$valor) ?></p>
            <p class="adm-stat__desc"><?= e($desc) ?></p>
        </<?= $href ? 'a' : 'article' ?>>
    <?php endforeach; ?>
</section>

<?php if ($duplicidades): ?>
    <div class="adm-notice adm-notice--amber" role="status"><?= icon('copy') ?><div class="adm-notice__content">
        <strong>Possíveis categorias repetidas</strong>
        <p>Estes nomes ficam iguais quando se ignoram maiúsculas, acentos e pontuação. Nada foi unificado sozinho: se forem a mesma categoria, troque a categoria dos livros de uma delas (Editar livro) e depois desative a que sobrar.</p>
        <ul class="adm-dupe-list">
            <?php foreach ($duplicidades as $grupo): ?>
                <li><?= e(implode(' · ', array_map(fn ($c) => $c['nome'] . ($c['arquivado_em'] ? ' (desativada)' : ''), $grupo))) ?></li>
            <?php endforeach; ?>
        </ul>
    </div></div>
<?php endif; ?>

<?php if ($mysql): ?>
    <nav class="adm-tabs adm-tabs--links adm-tabs--solta" aria-label="Tipo de categoria">
        <a class="adm-tab<?= $aba === 'atuais' ? ' is-active' : '' ?>" href="<?= e($base) ?>"<?= $aba === 'atuais' ? ' aria-current="page"' : '' ?>><?= icon('tags') ?> Categorias cadastradas</a>
        <a class="adm-tab<?= $aba === 'antigas' ? ' is-active' : '' ?>" href="<?= e($base . '?aba=antigas') ?>"<?= $aba === 'antigas' ? ' aria-current="page"' : '' ?>><?= icon('history') ?> Categorias das planilhas antigas</a>
    </nav>
<?php endif; ?>

<?php if ($aba === 'antigas'): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-antigas">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-antigas">Categorias informadas nas planilhas antigas</h2>
            <p>Só para consulta. Este texto histórico não é alterado e não vira categoria sozinho. Use-o para decidir quais categorias cadastrar.</p>
        </div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <input type="hidden" name="aba" value="antigas">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar categoria antiga</span>
                <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Texto da categoria na planilha..." autocomplete="off"></label>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Pesquisar</button>
        </form>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards">
                <thead><tr><th scope="col">Categoria na planilha</th><th scope="col">Linhas importadas</th><th scope="col">Livros</th><th scope="col">Categoria cadastrada equivalente</th></tr></thead>
                <tbody>
                    <?php foreach ($antigas['itens'] as $a): ?>
                        <tr>
                            <td class="adm-td-title"><?= e($a['categoria_original']) ?></td>
                            <td data-label="Linhas importadas"><?= $num((int)$a['registros']) ?></td>
                            <td data-label="Livros"><?= $num((int)$a['livros']) ?></td>
                            <td data-label="Equivalente"><?= $a['equivalente'] ? e($a['equivalente']['nome']) . ($a['equivalente']['arquivado_em'] ? ' <small class="adm-table__muted">(desativada)</small>' : '') : '<span class="adm-table__muted">Nenhuma</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$antigas['itens']): ?>
                        <tr><td colspan="4"><div class="adm-empty"><?= icon('history') ?><strong>Nenhuma categoria antiga encontrada.</strong><span>As planilhas importadas não informaram categoria<?= $filtros['q'] !== '' ? ' com este texto' : '' ?>.</span></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($antigas['total'] > count($antigas['itens'])): ?><p class="adm-field__hint">Mostrando <?= count($antigas['itens']) ?> de <?= $num($antigas['total']) ?>. Refine a pesquisa para ver as demais.</p><?php endif; ?>
    </section>
<?php else: ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-lista-categorias">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-lista-categorias">Categorias cadastradas</h2>
            <p aria-live="polite"><?= $num($resultado['total']) ?> <?= $resultado['total'] === 1 ? 'categoria' : 'categorias' ?>. Um livro pode ter mais de uma categoria; a coluna Livros conta só os livros ativos.</p>
        </div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar categorias</span>
                <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Pesquisar categoria..." autocomplete="off"></label>
            <?php if ($mysql): ?>
                <div class="adm-filters">
                    <label class="adm-filter"><span>Situação</span>
                        <select class="adm-select<?= $filtros['situacao'] !== 'ativas' ? ' has-value' : '' ?>" name="situacao">
                            <option value="ativas">Ativas</option>
                            <option value="inativas"<?= $filtros['situacao'] === 'inativas' ? ' selected' : '' ?>>Desativadas</option>
                            <option value="todas"<?= $filtros['situacao'] === 'todas' ? ' selected' : '' ?>>Todas</option>
                        </select>
                    </label>
                </div>
            <?php endif; ?>
            <input type="hidden" name="ordem" value="<?= e($filtros['ordem']) ?>">
            <input type="hidden" name="dir" value="<?= e($filtros['dir']) ?>">
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
        </form>
        <div class="adm-table-wrap adm-table-wrap--flush" data-loading-area>
            <table class="adm-table adm-table--cards">
                <thead>
                    <tr>
                        <?= admin_th_ordenar('Nome da categoria', 'nome', $base, $filtros) ?>
                        <th scope="col">Descrição</th>
                        <?= admin_th_ordenar('Livros', 'livros', $base, $filtros, 'desc') ?>
                        <th scope="col">Situação</th>
                        <th scope="col" class="adm-actions-col">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultado['itens'] as $c): ?>
                        <?php $ativa = $c['arquivado_em'] === null; ?>
                        <tr>
                            <td class="adm-td-title">
                                <span class="adm-type"><span class="adm-icon-box adm-icon-box--xs adm-tone--blue"><?= icon('tags') ?></span>
                                <?php if ($mysql): ?><a class="adm-book-link" href="<?= e(admin_url('categoria') . '?id=' . $c['id']) ?>"><?= e($c['nome']) ?></a><?php else: ?><strong><?= e($c['nome']) ?></strong><?php endif; ?></span>
                            </td>
                            <td data-label="Descrição" class="adm-td-wrap adm-table__muted"><?= $c['descricao'] ? e($c['descricao']) : '—' ?></td>
                            <td data-label="Livros">
                                <?php if ($c['livros'] > 0): ?><a class="adm-count-link" href="<?= e(admin_url_filtros(admin_url('acervo'), ['categoria' => $c['id']])) ?>"><?= $num($c['livros']) ?></a>
                                <?php else: ?><span class="adm-table__muted">0</span><?php endif; ?>
                            </td>
                            <td data-label="Situação"><?= $ativa ? '<span class="adm-status adm-tone--green">Ativa</span>' : '<span class="adm-status adm-tone--muted">Desativada</span>' ?></td>
                            <td class="adm-actions-col">
                                <div class="adm-actions-inline">
                                    <a class="adm-icon-button adm-icon-button--sm" href="<?= e(admin_url_filtros(admin_url('acervo'), ['categoria' => $c['id']])) ?>" aria-label="Consultar livros de <?= e($c['nome']) ?>" title="Consultar livros da categoria"><?= icon('library-big') ?></a>
                                    <?php if ($podeGerenciar && $mysql): ?>
                                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('categoria') . '?id=' . $c['id']) ?>"><?= icon('pencil') ?> Editar</a>
                                        <?php if (!$ativa || $c['livros'] === 0): ?>
                                            <form method="post" action="<?= e($base) ?>" data-confirm="<?= $ativa ? 'Desativar esta categoria? Ela deixa de aparecer no cadastro de livros e no site.' : 'Reativar esta categoria?' ?>">
                                                <?= csrf_campo() ?>
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <input type="hidden" name="acao" value="<?= $ativa ? 'desativar' : 'reativar' ?>">
                                                <input type="hidden" name="q" value="<?= e($filtros['q']) ?>">
                                                <input type="hidden" name="situacao" value="<?= e($filtros['situacao']) ?>">
                                                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= $ativa ? icon('archive') . ' Desativar' : icon('archive-restore') . ' Reativar' ?></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="adm-table__muted" title="Troque a categoria dos livros antes de desativá-la."><?= icon('lock') ?> Em uso</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$resultado['itens']): ?>
                        <tr><td colspan="5"><div class="adm-empty"><?= icon('tags') ?>
                            <strong><?= $temFiltro ? 'Nenhuma categoria encontrada' : 'Nenhuma categoria cadastrada ainda' ?></strong>
                            <span><?= $temFiltro ? 'Tente outro termo ou mude a situação.' : 'Cadastre as categorias definidas pela biblioteca. A aba Categorias das planilhas antigas mostra as que vieram das planilhas.' ?></span>
                        </div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtros) ?>
    </section>
<?php endif; ?>

<?php
admin_page_end();
