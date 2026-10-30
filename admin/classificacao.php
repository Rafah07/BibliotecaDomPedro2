<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/cdd.php';

$usuario = cdd_pagina_iniciar('cdd.ver');
$itens = cdd_itens();
$resumo = cdd_resumo();
$proximo = cdd_proximo_livro();
$lotes = cdd_lotes(5);
$fontes = cdd_fontes_automaticas();
$categorias = acervo_categorias();
$autores = array_values(array_unique(array_filter(array_map(fn ($i) => $i['autor'], $itens))));
sort($autores, SORT_LOCALE_STRING);
$podeSugerir = auth_pode('cdd.sugerir');
$podeLote = auth_pode('cdd.lote');

$filtroInicial = is_string($_GET['situacao'] ?? null) ? $_GET['situacao'] : '';
$situacoes = [
    'pendente' => 'Sem classificação',
    'sugerida' => 'Sugestão encontrada',
    'em_revisao' => 'Aguardando aprovação',
    'aprovada' => 'Aprovado',
    'precisa_revisao' => 'Precisa de revisão',
    'conflito' => 'Conflito de classificação',
];

$cards = [
    ['titulo' => 'Total de títulos', 'valor' => $resumo['total'], 'descricao' => 'Livros ativos no acervo', 'icone' => 'library-big', 'tom' => 'blue', 'filtro' => null],
    ['titulo' => 'Aguardando classificação', 'valor' => $resumo['pendente'], 'descricao' => 'Ainda não analisados', 'icone' => 'hourglass', 'tom' => 'muted', 'filtro' => 'pendente'],
    ['titulo' => 'Com sugestão disponível', 'valor' => $resumo['sugerida'], 'descricao' => 'Classificação candidata registrada', 'icone' => 'book-marked', 'tom' => 'blue', 'filtro' => 'sugerida'],
    ['titulo' => 'Aguardando revisão', 'valor' => $resumo['em_revisao'], 'descricao' => 'Na fila da bibliotecária', 'icone' => 'list-checks', 'tom' => 'amber', 'filtro' => 'em_revisao'],
    ['titulo' => 'CDDs aprovadas', 'valor' => $resumo['aprovadas'], 'descricao' => 'Validadas pela bibliotecária', 'icone' => 'check-check', 'tom' => 'green', 'filtro' => 'aprovada'],
    ['titulo' => 'Casos com conflito', 'valor' => $resumo['conflito'], 'descricao' => 'Fontes com números diferentes', 'icone' => 'git-compare-arrows', 'tom' => 'red', 'filtro' => 'conflito'],
];

admin_page_start('classificacao', 'Classificação CDD', 'Acervo', 'Reclassificação bibliográfica pela CDD', [
    'assets/css/acervo.css?v=20261027',
    'assets/css/classificacao.css?v=20261027',
]);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Classificação CDD']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Reclassificação do Acervo</h1>
        <p>Revise, organize e valide as classificações bibliográficas da biblioteca.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('relatorio-cdd')) ?>"><?= icon('download') ?> Exportar relatório</a>
        <?php if ($proximo): ?>
            <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . $proximo) ?>"><?= icon('skip-forward') ?> Classificar próximo livro</a>
        <?php endif; ?>
    </div>
</section>

<?= cdd_abas('classificacao') ?>
<?= cdd_aviso_modo() ?>

<section class="adm-stats adm-stats--6" aria-label="Resumo da reclassificação">
    <?php foreach ($cards as $card): ?>
        <?php $tag = $card['filtro'] ? 'button' : 'article'; ?>
        <<?= $tag ?> class="adm-card adm-stat adm-stat--compact<?= $tag === 'button' ? ' adm-stat--action' : '' ?>"
            <?= $tag === 'button' ? 'type="button" data-apply-filter="situacao" data-value="' . $card['filtro'] . '" title="Filtrar a tabela"' : '' ?>>
            <div class="adm-stat__top">
                <p class="adm-stat__title"><?= htmlspecialchars($card['titulo']) ?></p>
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $card['tom'] ?>"><?= icon($card['icone']) ?></span>
            </div>
            <p class="adm-stat__value"><?= number_format($card['valor'], 0, ',', '.') ?></p>
            <p class="adm-stat__desc"><?= htmlspecialchars($card['descricao']) ?></p>
        </<?= $tag ?>>
    <?php endforeach; ?>
</section>

<div class="adm-cdd-top">
    <section class="adm-card adm-panel adm-cdd-progress" aria-labelledby="titulo-progresso">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-progresso">Progresso da reclassificação</h2>
                <p>Títulos com CDD aprovada pela bibliotecária</p>
            </div>
            <strong class="adm-cdd-progress__pct"><?= number_format($resumo['percentual'], 1, ',', '.') ?>%</strong>
        </div>
        <div class="adm-progress adm-progress--lg" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $resumo['total'] ?>" aria-valuenow="<?= $resumo['aprovadas'] ?>"
             aria-valuetext="<?= $resumo['aprovadas'] ?> de <?= $resumo['total'] ?> títulos com CDD aprovada">
            <span class="adm-progress__bar adm-progress__bar--ok" style="width: <?= $resumo['percentual'] ?>%"></span>
        </div>
        <p class="adm-inventory__caption">
            <?= number_format($resumo['aprovadas'], 0, ',', '.') ?> de <?= number_format($resumo['total'], 0, ',', '.') ?> títulos aprovados
            <?= db_modo() === 'mysql' ? '(calculado a partir dos registros do MySQL).' : '(dados demonstrativos desta sessão).' ?>
            <?php if ($resumo['precisa_revisao']): ?> <?= $resumo['precisa_revisao'] ?> precisam de nova revisão.<?php endif; ?>
        </p>
    </section>

    <section class="adm-card adm-panel" aria-labelledby="titulo-acoes">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-acoes">Ações rápidas</h2>
                <p>Analise um livro e avance direto para o próximo</p>
            </div>
        </div>
        <div class="adm-quick adm-quick--cdd">
            <?php if ($proximo): ?>
                <a class="adm-quick__item" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . $proximo) ?>">
                    <span class="adm-icon-box adm-tone--blue"><?= icon('skip-forward') ?></span>
                    <span><strong>Classificar próximo livro</strong><small>Abre o primeiro pendente da fila</small></span>
                </a>
            <?php else: ?>
                <span class="adm-quick__item is-disabled">
                    <span class="adm-icon-box adm-tone--green"><?= icon('check-check') ?></span>
                    <span><strong>Fila vazia</strong><small>Nenhum livro aguardando classificação</small></span>
                </span>
            <?php endif; ?>
            <button type="button" class="adm-quick__item" data-modal-open="modal-pesquisa-cdd">
                <span class="adm-icon-box adm-tone--blue"><?= icon('search') ?></span>
                <span><strong>Pesquisar CDD</strong><small>Consultar fontes por ISBN, título ou autor</small></span>
            </button>
            <button type="button" class="adm-quick__item" data-apply-filter="situacao" data-value="pendente">
                <span class="adm-icon-box adm-tone--blue"><?= icon('hourglass') ?></span>
                <span><strong>Ver pendências</strong><small>Livros ainda sem classificação</small></span>
            </button>
            <a class="adm-quick__item" href="<?= htmlspecialchars(admin_url('revisao-cdd')) ?>">
                <span class="adm-icon-box adm-tone--blue"><?= icon('list-checks') ?></span>
                <span><strong>Revisar sugestões</strong><small><?= $resumo['em_revisao'] ?> aguardando aprovação</small></span>
            </a>
            <a class="adm-quick__item" href="<?= htmlspecialchars(admin_url('relatorio-cdd')) ?>">
                <span class="adm-icon-box adm-tone--blue"><?= icon('download') ?></span>
                <span><strong>Exportar relatório</strong><small>CSV ou Excel, com filtros</small></span>
            </a>
        </div>
    </section>
</div>

<?php if ($podeLote): ?>
<section class="adm-card adm-panel adm-batch" aria-labelledby="titulo-lotes" data-lotes<?= $lotes ? '' : ' hidden' ?>>
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-lotes">Pesquisas em lote</h2>
            <p>As pesquisas continuam enquanto você usa o restante do painel nesta página. Se sair, o lote pode ser retomado.</p>
        </div>
    </div>
    <ul class="adm-batch__list" data-lotes-lista>
        <?php foreach ($lotes as $lote): ?>
            <li data-lote-id="<?= (int)$lote['id'] ?>" data-lote-status="<?= htmlspecialchars($lote['status']) ?>"></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="adm-card adm-panel" data-collection data-cdd-tabela aria-labelledby="titulo-tabela">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-tabela">Livros do acervo</h2>
            <p data-count data-noun="título|títulos" aria-live="polite"><?= count($itens) ?> títulos</p>
        </div>
    </div>

    <div class="adm-toolbar">
        <label class="adm-field-search">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar livros</span>
            <input type="search" data-search placeholder="Pesquisar por título, autor ou ISBN..." autocomplete="off">
        </label>
        <div class="adm-filters adm-filters--3">
            <label class="adm-filter">
                <span>Situação</span>
                <select class="adm-select" data-filter="situacao">
                    <option value="">Todas</option>
                    <?php foreach ($situacoes as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"<?= $filtroInicial === $valor ? ' selected' : '' ?>><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Categoria</span>
                <select class="adm-select" data-filter="categoria">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Autor</span>
                <select class="adm-select" data-filter="autor">
                    <option value="">Todos</option>
                    <?php foreach ($autores as $autor): ?>
                        <option value="<?= htmlspecialchars(acervo_texto_busca($autor)) ?>"><?= htmlspecialchars($autor) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="button" class="adm-button adm-button--ghost adm-button--sm adm-filters-toggle" data-filters-toggle aria-expanded="false"><?= icon('list') ?> Filtros <span class="adm-filters-toggle__count" data-filters-count hidden></span></button>
        <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-clear-filters><?= icon('filter-x') ?> Limpar filtros</button>
    </div>

    <?php if ($podeSugerir): ?>
        <div class="adm-selection-bar" data-selection-bar hidden>
            <strong data-selection-count>0 selecionados</strong>
            <div class="adm-selection-bar__actions">
                <?php if ($podeLote): ?>
                    <button type="button" class="adm-button adm-button--primary adm-button--sm" data-lote-iniciar><?= icon('layers') ?> Pesquisar referências</button>
                <?php endif; ?>
                <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-enviar-lote><?= icon('send') ?> Enviar sugestões para aprovação</button>
                <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-selection-clear><?= icon('x') ?> Limpar seleção</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards adm-table--cdd">
            <thead>
                <tr>
                    <?php if ($podeSugerir): ?>
                        <th scope="col" class="adm-check-col"><input type="checkbox" class="adm-checkbox" data-select-page aria-label="Selecionar os livros desta página"></th>
                    <?php endif; ?>
                    <th scope="col">Capa</th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="titulo">Título <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="autor">Autor <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col">ISBN</th>
                    <th scope="col">CDD antiga</th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="cdd">Nova CDD <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="status">Situação <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col">Fonte</th>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody data-rows>
                <?php foreach ($itens as $item): ?>
                    <?php
                    $c = $item['class'];
                    $situacao = $c['status'] . ($c['precisa_revisao'] ? '|precisa_revisao' : '');
                    $novaCdd = $c['cdd_aprovada'] ?? $c['cdd_proposta'];
                    $url = admin_url('classificar-livro') . '?id=' . $item['id'];
                    ?>
                    <tr data-row data-id="<?= $item['id'] ?>"
                        data-search="<?= htmlspecialchars(acervo_texto_busca($item['titulo'], $item['subtitulo'], $item['autores'], $item['isbn'])) ?>"
                        data-situacao="<?= $situacao ?>"
                        data-categoria="<?= (int)$item['categoria_id'] ?>"
                        data-autor="<?= htmlspecialchars(acervo_texto_busca($item['autor'])) ?>"
                        data-sort-titulo="<?= htmlspecialchars(acervo_texto_busca($item['titulo'])) ?>"
                        data-sort-autor="<?= htmlspecialchars(acervo_texto_busca($item['autor'])) ?>"
                        data-sort-cdd="<?= htmlspecialchars($novaCdd ?? 'zzz') ?>"
                        data-sort-status="<?= array_search($c['status'], array_keys(CDD_STATUS), true) ?>">
                        <?php if ($podeSugerir): ?>
                            <td class="adm-check-col"><input type="checkbox" class="adm-checkbox" data-select-row value="<?= $item['id'] ?>" aria-label="Selecionar <?= htmlspecialchars($item['titulo']) ?>"></td>
                        <?php endif; ?>
                        <td class="adm-td-cover"><a href="<?= htmlspecialchars($url) ?>" tabindex="-1" aria-hidden="true"><?= acervo_capa(['titulo' => $item['titulo'], 'capa' => $item['capa'], 'cor' => $item['cor'], 'autor_principal' => $item['autor']]) ?></a></td>
                        <td class="adm-td-title">
                            <a class="adm-book-link" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($item['titulo']) ?></a>
                            <small><?= htmlspecialchars(trim(($item['editora'] ?: 'Editora não informada') . ($item['ano'] ? ' · ' . $item['ano'] : '') . ($item['edicao'] ? ' · ' . $item['edicao'] : ''))) ?></small>
                        </td>
                        <td data-label="Autor"><?= $item['autores'] !== '' ? htmlspecialchars($item['autores']) : '<span class="adm-missing">Não informado</span>' ?></td>
                        <td data-label="ISBN" class="adm-table__muted adm-mono"><?= $item['isbn'] !== '' ? htmlspecialchars($item['isbn']) : '<span class="adm-missing">Sem ISBN</span>' ?></td>
                        <td data-label="CDD antiga"><?= cdd_numero($c['cdd_original'], 'Sem CDD') ?></td>
                        <td data-label="Nova CDD" data-cell="cdd">
                            <?= cdd_numero($novaCdd, 'Pendente') ?>
                            <?php if ($c['cdd_aprovada'] !== null && $c['cdd_proposta'] !== null && $c['cdd_proposta'] !== $c['cdd_aprovada']): ?>
                                <small class="adm-num__hint">em revisão: <?= htmlspecialchars($c['cdd_proposta']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Situação" data-cell="status">
                            <?= cdd_badge_status($c['status'], (bool)$c['rascunho']) ?>
                            <?php if ($c['precisa_revisao']): ?><span class="adm-chip adm-chip--warn">Precisa de revisão</span><?php endif; ?>
                        </td>
                        <td data-label="Fonte" class="adm-table__muted adm-td-wrap" data-cell="fonte">
                            <?php if ($c['fonte_nome']): ?>
                                <?= htmlspecialchars($c['fonte_nome']) ?>
                            <?php elseif (in_array($item['pesquisa']['resultado'] ?? '', ['sem_registro', 'sem_cdd'], true)): ?>
                                <span class="adm-missing">Sem referência encontrada</span>
                            <?php else: ?>
                                <span class="adm-missing">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="adm-actions-col">
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars($url) ?>"><?= icon($c['status'] === 'aprovada' ? 'eye' : 'book-marked') ?> <?= $c['status'] === 'aprovada' ? 'Ver' : 'Classificar' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr data-empty hidden>
                    <td colspan="<?= $podeSugerir ? 10 : 9 ?>">
                        <div class="adm-empty">
                            <?= icon('book-x') ?>
                            <strong>Nenhum livro encontrado</strong>
                            <span>Revise a pesquisa ou os filtros selecionados.</span>
                            <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-clear-filters><?= icon('filter-x') ?> Limpar filtros</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php include __DIR__ . '/includes/paginacao.php'; ?>
</section>

<?php if ($podeLote): ?>
<dialog class="adm-modal" id="modal-lote" aria-labelledby="modal-lote-titulo">
    <form class="adm-modal__box" method="dialog" data-lote-form novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('layers') ?></span>
            <div>
                <h2 id="modal-lote-titulo">Pesquisar referências em lote</h2>
                <p data-lote-resumo>Livros selecionados serão pesquisados um de cada vez.</p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <fieldset class="adm-fieldset">
                <legend class="adm-form-legend">Fontes consultadas</legend>
                <?php foreach ($fontes as $id => $fonte): ?>
                    <label class="adm-check-confirm">
                        <input type="checkbox" name="fontes[]" value="<?= $id ?>" checked>
                        <span><strong><?= htmlspecialchars($fonte['nome']) ?></strong><br><small><?= htmlspecialchars($fonte['descricao']) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if (!$fontes): ?>
                    <p class="adm-missing">Nenhuma fonte automática habilitada na configuração.</p>
                <?php endif; ?>
            </fieldset>
            <ul class="adm-side-list">
                <li><?= icon('info') ?> Cada consulta respeita um intervalo mínimo entre pedidos à mesma fonte, e as respostas ficam em cache por 7 dias.</li>
                <li><?= icon('shield-check') ?> Nada é aprovado automaticamente. Quando as fontes coincidem, o livro recebe uma <strong>sugestão</strong>, que ainda precisa ser revisada.</li>
                <li><?= icon('git-compare-arrows') ?> Títulos parecidos não bastam: cada edição é conferida por ISBN, autor, ano e editora.</li>
            </ul>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--primary"<?= $fontes ? '' : ' disabled' ?>><?= icon('search') ?> Iniciar pesquisa</button>
        </footer>
    </form>
</dialog>
<?php endif; ?>

<?php
include __DIR__ . '/includes/modal-pesquisa-cdd.php';
echo cdd_json_script(['pagina' => 'painel']);
admin_page_end(['assets/js/acervo.js?v=20261027', 'assets/js/classificacao.js?v=20261027']);
