<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';

$exemplares = acervo_exemplares();
$resumo = acervo_resumo()['por_situacao'];
$setores = array_values(array_unique(array_column(demo_localizacoes(), 'setor')));
$pendente = fn (array $ex): bool => $ex['situacao'] === 'aguardando' || $ex['conferido_em'] === null;
$totalPendentes = count(array_filter($exemplares, $pendente));

$filtroSituacao = is_string($_GET['situacao'] ?? null) && isset(ACERVO_SITUACOES[$_GET['situacao']]) ? $_GET['situacao'] : '';

$kpis = [
    ['titulo' => 'Disponíveis', 'valor' => $resumo['disponivel'] ?? 0, 'icone' => 'circle-check', 'tom' => 'green', 'filtro' => 'situacao', 'valorFiltro' => 'disponivel'],
    ['titulo' => 'Emprestados', 'valor' => $resumo['emprestado'] ?? 0, 'icone' => 'book-open-check', 'tom' => 'indigo', 'filtro' => 'situacao', 'valorFiltro' => 'emprestado'],
    ['titulo' => 'Em manutenção', 'valor' => $resumo['manutencao'] ?? 0, 'icone' => 'wrench', 'tom' => 'amber', 'filtro' => 'situacao', 'valorFiltro' => 'manutencao'],
    ['titulo' => 'Identificação pendente', 'valor' => $totalPendentes, 'icone' => 'hourglass', 'tom' => 'teal', 'filtro' => 'pendente', 'valorFiltro' => 'sim'],
    ['titulo' => 'Extraviados ou baixados', 'valor' => ($resumo['extraviado'] ?? 0) + ($resumo['baixado'] ?? 0), 'icone' => 'package-x', 'tom' => 'red', 'filtro' => 'situacao', 'valorFiltro' => 'extraviado|baixado'],
];

admin_page_start('exemplares', 'Exemplares', 'Exemplares', 'Unidades físicas do acervo', ['assets/css/acervo.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Exemplares']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Gerenciamento de Exemplares</h1>
        <p>Controle as unidades físicas cadastradas na biblioteca.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('etiquetas')) ?>"><?= icon('scan-barcode') ?> Etiquetas</a>
        <button type="button" class="adm-button adm-button--primary" data-add-exemplares=""><?= icon('plus') ?> Cadastrar exemplar</button>
    </div>
</section>

<div class="adm-demo-banner" role="note">
    <?= icon('info') ?>
    <span><strong>Dados demonstrativos.</strong> <?= count($exemplares) ?> exemplares fictícios. O estado de conservação indica a condição física; a situação indica se o exemplar pode circular. Um exemplar em bom estado pode estar emprestado.</span>
</div>

<section class="adm-kpis" aria-label="Exemplares por situação">
    <?php foreach ($kpis as $kpi): ?>
        <button type="button" class="adm-card adm-kpi adm-kpi--action" data-apply-filter="<?= $kpi['filtro'] ?>" data-value="<?= $kpi['valorFiltro'] ?>">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $kpi['tom'] ?>"><?= icon($kpi['icone']) ?></span>
            <span>
                <strong class="adm-kpi__value"><?= $kpi['valor'] ?></strong>
                <span class="adm-kpi__title"><?= htmlspecialchars($kpi['titulo']) ?></span>
            </span>
        </button>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" data-collection aria-labelledby="titulo-lista-exemplares">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-lista-exemplares">Exemplares cadastrados</h2>
            <p data-count data-noun="exemplar|exemplares" aria-live="polite"><?= count($exemplares) ?> exemplares</p>
        </div>
    </div>

    <div class="adm-toolbar">
        <label class="adm-field-search">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar exemplares</span>
            <input type="search" data-search placeholder="Pesquisar por código, título, autor ou ISBN..." autocomplete="off">
        </label>
        <div class="adm-filters adm-filters--4">
            <label class="adm-filter">
                <span>Situação</span>
                <select class="adm-select" data-filter="situacao">
                    <option value="">Todas</option>
                    <?php foreach (ACERVO_SITUACOES as $valor => $s): ?>
                        <option value="<?= $valor ?>"<?= $filtroSituacao === $valor ? ' selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                    <?php endforeach; ?>
                    <option value="extraviado|baixado" hidden>Extraviados ou baixados</option>
                </select>
            </label>
            <label class="adm-filter">
                <span>Localização</span>
                <select class="adm-select" data-filter="setor">
                    <option value="">Todas</option>
                    <?php foreach ($setores as $setor): ?>
                        <option value="<?= htmlspecialchars(acervo_texto_busca($setor)) ?>"><?= htmlspecialchars($setor) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Estado de conservação</span>
                <select class="adm-select" data-filter="conservacao">
                    <option value="">Todos</option>
                    <?php foreach (ACERVO_CONSERVACAO as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Identificação pendente</span>
                <select class="adm-select" data-filter="pendente">
                    <option value="">Todos</option>
                    <option value="sim">Somente pendentes</option>
                    <option value="nao">Somente conferidos</option>
                </select>
            </label>
        </div>
        <button type="button" class="adm-button adm-button--ghost adm-button--sm adm-filters-toggle" data-filters-toggle aria-expanded="false"><?= icon('list') ?> Filtros <span class="adm-filters-toggle__count" data-filters-count hidden></span></button>
        <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-clear-filters><?= icon('filter-x') ?> Limpar filtros</button>
    </div>

    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards">
            <thead>
                <tr>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="codigo">Código individual <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="livro">Livro <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="local">Localização <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col">Conservação</th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="situacao">Situação <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="conferencia">Última conferência <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody data-rows>
                <?php foreach ($exemplares as $ex): ?>
                    <?php
                    $livro = $ex['livro'];
                    $url = admin_url('detalhes-exemplar') . '?codigo=' . $ex['codigo_individual'];
                    ?>
                    <tr data-row
                        data-search="<?= htmlspecialchars(acervo_texto_busca($ex['codigo_individual'], substr($ex['codigo_individual'], 4), $livro['titulo'], implode(' ', $livro['autores_nomes']), $livro['isbn'], acervo_isbn_normalizar($livro['isbn']))) ?>"
                        data-situacao="<?= $ex['situacao'] ?>"
                        data-setor="<?= htmlspecialchars(acervo_texto_busca($ex['localizacao']['setor'] ?? '')) ?>"
                        data-conservacao="<?= $ex['estado_conservacao'] ?>"
                        data-pendente="<?= $pendente($ex) ? 'sim' : 'nao' ?>"
                        data-sort-codigo="<?= $ex['codigo_individual'] ?>"
                        data-sort-livro="<?= htmlspecialchars(acervo_texto_busca($livro['titulo'])) ?>"
                        data-sort-local="<?= htmlspecialchars(acervo_texto_busca(acervo_local_texto($ex['localizacao']))) ?>"
                        data-sort-situacao="<?= array_search($ex['situacao'], array_keys(ACERVO_SITUACOES), true) ?>"
                        data-sort-conferencia="<?= $ex['conferido_em'] ?? '0000' ?>">
                        <td class="adm-td-title"><a class="adm-code" href="<?= htmlspecialchars($url) ?>"><?= $ex['codigo_individual'] ?></a></td>
                        <td data-label="Livro">
                            <a class="adm-book-link" href="<?= htmlspecialchars(admin_url('detalhes-livro') . '?id=' . $livro['id']) ?>"><?= htmlspecialchars($livro['titulo']) ?></a>
                            <small class="adm-block-muted"><?= htmlspecialchars($livro['autor_principal']) ?></small>
                        </td>
                        <td data-label="Localização">
                            <?= htmlspecialchars($ex['localizacao']['setor'] ?? 'Sem localização') ?>
                            <small class="adm-block-muted"><?= htmlspecialchars(($ex['localizacao']['estante'] ?? '') . ' · ' . ($ex['localizacao']['prateleira'] ?? '')) ?></small>
                        </td>
                        <td data-label="Conservação"><?= acervo_badge_conservacao($ex['estado_conservacao']) ?></td>
                        <td data-label="Situação"><?= acervo_badge_situacao($ex['situacao']) ?></td>
                        <td data-label="Última conferência" class="adm-table__muted"><?= $ex['conferido_em'] ? acervo_data($ex['conferido_em']) : '<span class="adm-missing">Não conferido</span>' ?></td>
                        <td class="adm-actions-col">
                            <div class="adm-row-actions">
                                <a class="adm-icon-button adm-icon-button--sm" href="<?= htmlspecialchars($url) ?>" aria-label="Ver detalhes de <?= $ex['codigo_individual'] ?>" title="Ver detalhes"><?= icon('eye') ?></a>
                                <div class="adm-rowmenu">
                                    <button type="button" class="adm-icon-button adm-icon-button--sm" data-rowmenu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Mais ações para <?= $ex['codigo_individual'] ?>"><?= icon('ellipsis-vertical') ?></button>
                                    <div class="adm-rowmenu__list" role="menu" hidden>
                                        <a role="menuitem" class="adm-dropdown__item" href="<?= htmlspecialchars($url) ?>"><?= icon('eye') ?> Ver detalhes</a>
                                        <a role="menuitem" class="adm-dropdown__item" href="<?= htmlspecialchars($url) ?>&amp;acao=localizacao"><?= icon('map-pin') ?> Alterar localização</a>
                                        <a role="menuitem" class="adm-dropdown__item" href="<?= htmlspecialchars(admin_url('detalhes-livro') . '?id=' . $livro['id']) ?>"><?= icon('book-open') ?> Ver livro</a>
                                        <a role="menuitem" class="adm-dropdown__item" href="<?= htmlspecialchars(admin_url('etiquetas')) ?>"><?= icon('printer') ?> Imprimir etiqueta <span class="adm-demo-tag">Em breve</span></a>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr data-empty hidden>
                    <td colspan="7">
                        <div class="adm-empty">
                            <?= icon('book-x') ?>
                            <strong>Nenhum exemplar encontrado</strong>
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

<?php
include __DIR__ . '/includes/modal-exemplares.php';
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261027']);
