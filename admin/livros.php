<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';

$livros = acervo_livros();
$resumo = acervo_resumo();
$categorias = acervo_categorias();
$autores = acervo_fonte('autores');
$codigosPorLivro = [];
foreach (acervo_fonte('exemplares') as $ex) {
    $codigosPorLivro[$ex['livro_id']][] = $ex['codigo_individual'];
}

// Filtros que podem chegar pelo endereço (ex.: link "Consultar livros" da página de categorias).
$filtroInicial = [
    'q' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
    'categoria' => (string)(int)($_GET['categoria'] ?? 0),
    'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
];
$sel = fn (string $campo, string $valor): string => $filtroInicial[$campo] === $valor ? ' selected' : '';

$cards = [
    ['chave' => 'titulos', 'titulo' => 'Total de títulos', 'valor' => $resumo['titulos'], 'descricao' => 'Obras e edições cadastradas', 'icone' => 'library-big', 'tom' => 'blue'],
    ['chave' => 'exemplares', 'titulo' => 'Total de exemplares', 'valor' => $resumo['exemplares'], 'descricao' => 'Unidades físicas registradas', 'icone' => 'book-copy', 'tom' => 'indigo'],
    ['chave' => 'disponiveis', 'titulo' => 'Exemplares disponíveis', 'valor' => $resumo['disponiveis'], 'descricao' => 'Prontos para empréstimo', 'icone' => 'circle-check', 'tom' => 'green'],
    ['chave' => 'pendentes', 'titulo' => 'Cadastros pendentes', 'valor' => $resumo['pendentes'], 'descricao' => 'Precisam de verificação ou complementação', 'icone' => 'clipboard-check', 'tom' => 'amber', 'filtro' => 'pendente'],
];

admin_page_start('acervo', 'Livros', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Livros']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Gerenciamento do Acervo</h1>
        <p>Consulte, cadastre e organize os livros da Biblioteca Dom Pedro II.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('importar')) ?>"><?= icon('file-spreadsheet') ?> Importar planilha</a>
        <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('cadastrar-livro')) ?>"><?= icon('plus') ?> Cadastrar livro</a>
    </div>
</section>

<div class="adm-demo-banner" role="note">
    <?= icon('info') ?>
    <?php if (acervo_usa_mysql()): ?>
        <span><strong>Livros do banco de dados.</strong> Os livros importados das planilhas aparecem aqui com as pendências de reclassificação e de inventário. Arquivamentos e inclusões feitos nesta tela ainda são simulados e não são gravados.</span>
    <?php else: ?>
        <span><strong>Dados demonstrativos.</strong> Os livros e exemplares abaixo são fictícios. Arquivamentos e inclusões feitos nesta tela são apenas simulados e não são gravados.</span>
    <?php endif; ?>
</div>

<section class="adm-stats" aria-label="Resumo do acervo">
    <?php foreach ($cards as $card): ?>
        <?php $tag = isset($card['filtro']) ? 'button' : 'article'; ?>
        <<?= $tag ?> class="adm-card adm-stat adm-stat--compact<?= $tag === 'button' ? ' adm-stat--action' : '' ?>"
            <?= $tag === 'button' ? 'type="button" data-apply-filter="status" data-value="' . $card['filtro'] . '" title="Mostrar somente os cadastros pendentes"' : '' ?>>
            <div class="adm-stat__top">
                <p class="adm-stat__title"><?= htmlspecialchars($card['titulo']) ?></p>
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $card['tom'] ?>"><?= icon($card['icone']) ?></span>
            </div>
            <p class="adm-stat__value" data-stat="<?= $card['chave'] ?>"><?= number_format($card['valor'], 0, ',', '.') ?></p>
            <p class="adm-stat__desc"><?= htmlspecialchars($card['descricao']) ?></p>
        </<?= $tag ?>>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" data-collection aria-labelledby="titulo-lista-livros">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-lista-livros">Livros cadastrados</h2>
            <p data-count data-noun="título|títulos" aria-live="polite"><?= count($livros) ?> títulos</p>
        </div>
    </div>

    <div class="adm-toolbar">
        <label class="adm-field-search">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar livros</span>
            <input type="search" data-search placeholder="Pesquisar por título, autor, ISBN ou código..." value="<?= htmlspecialchars($filtroInicial['q']) ?>" autocomplete="off">
        </label>
        <div class="adm-filters">
            <label class="adm-filter">
                <span>Categoria</span>
                <select class="adm-select" data-filter="categoria">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= $cat['id'] ?>"<?= $sel('categoria', (string)$cat['id']) ?>><?= htmlspecialchars($cat['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Autor</span>
                <select class="adm-select" data-filter="autor">
                    <option value="">Todos</option>
                    <?php
                    $autoresOrdenados = $autores;
                    uasort($autoresOrdenados, fn ($a, $b) => strcoll($a['nome'], $b['nome']));
                    foreach ($autoresOrdenados as $autor): ?>
                        <option value="<?= $autor['id'] ?>"><?= htmlspecialchars($autor['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Situação do cadastro</span>
                <select class="adm-select" data-filter="status">
                    <option value="">Todas</option>
                    <option value="pendente"<?= $sel('status', 'pendente') ?>>Todos os pendentes</option>
                    <?php foreach (ACERVO_STATUS as $valor => $status): ?>
                        <option value="<?= $valor ?>"<?= $sel('status', $valor) ?>><?= htmlspecialchars($status['label']) ?></option>
                    <?php endforeach; ?>
                    <option value="reclassificacao"<?= $sel('status', 'reclassificacao') ?>>Aguardando reclassificação CDD</option>
                    <option value="inventario"<?= $sel('status', 'inventario') ?>>Aguardando inventário físico</option>
                </select>
            </label>
            <label class="adm-filter">
                <span>Disponibilidade</span>
                <select class="adm-select" data-filter="disponibilidade">
                    <option value="">Todas</option>
                    <option value="com">Com exemplares disponíveis</option>
                    <option value="sem">Sem exemplares disponíveis</option>
                    <option value="nenhum">Sem exemplares identificados</option>
                </select>
            </label>
            <label class="adm-filter">
                <span>Origem do registro</span>
                <select class="adm-select" data-filter="origem">
                    <option value="">Todas</option>
                    <?php foreach (ACERVO_ORIGENS as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Ordenação</span>
                <select class="adm-select" data-sort-select>
                    <option value="titulo:asc">Título (A–Z)</option>
                    <option value="titulo:desc">Título (Z–A)</option>
                    <option value="autor:asc">Autor (A–Z)</option>
                    <option value="criado:desc">Cadastrados recentemente</option>
                    <option value="exemplares:desc">Mais exemplares</option>
                    <option value="disponiveis:desc">Mais disponíveis</option>
                </select>
            </label>
        </div>
        <button type="button" class="adm-button adm-button--ghost adm-button--sm adm-filters-toggle" data-filters-toggle aria-expanded="false"><?= icon('list') ?> Filtros <span class="adm-filters-toggle__count" data-filters-count hidden></span></button>
        <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-clear-filters><?= icon('filter-x') ?> Limpar filtros</button>
    </div>

    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards adm-table--books">
            <thead>
                <tr>
                    <th scope="col">Capa</th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="titulo">Título <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="autor">Autor <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="categoria">Categoria <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col">ISBN</th>
                    <th scope="col" class="adm-num"><button type="button" class="adm-th-sort" data-sort="exemplares">Exemplares <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col" class="adm-num"><button type="button" class="adm-th-sort" data-sort="disponiveis">Disponíveis <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="status">Cadastro <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody data-rows>
                <?php foreach ($livros as $livro): ?>
                    <?php
                    $id = $livro['id'];
                    $codigos = $codigosPorLivro[$id] ?? [];
                    $disponibilidade = $livro['total_exemplares'] === 0 ? 'nenhum|sem' : ($livro['disponiveis'] > 0 ? 'com' : 'sem');
                    $status = $livro['status_validacao'] . ($livro['status_validacao'] !== 'validado' ? '|pendente' : '')
                        . ($livro['planilha'] && $livro['aguarda_reclassificacao'] ? '|reclassificacao' : '')
                        . ($livro['planilha'] && $livro['aguarda_inventario'] ? '|inventario' : '');
                    $urlDetalhes = admin_url('detalhes-livro') . '?id=' . $id;
                    ?>
                    <tr data-row
                        data-id="<?= $id ?>"
                        data-search="<?= htmlspecialchars(acervo_texto_busca($livro['titulo'], $livro['subtitulo'], implode(' ', $livro['autores_nomes']), $livro['isbn'], acervo_isbn_normalizar($livro['isbn']), implode(' ', $codigos), $livro['editora'])) ?>"
                        data-categoria="<?= implode('|', $livro['categorias']) ?>"
                        data-autor="<?= implode('|', $livro['autores']) ?>"
                        data-status="<?= $status ?>"
                        data-disponibilidade="<?= $disponibilidade ?>"
                        data-origem="<?= $livro['origem_cadastro'] ?>"
                        data-sort-titulo="<?= htmlspecialchars(acervo_texto_busca($livro['titulo'])) ?>"
                        data-sort-autor="<?= htmlspecialchars(acervo_texto_busca($livro['autor_principal'])) ?>"
                        data-sort-categoria="<?= htmlspecialchars(acervo_texto_busca($livro['categoria_principal'])) ?>"
                        data-sort-exemplares="<?= $livro['total_exemplares'] ?>"
                        data-sort-disponiveis="<?= $livro['disponiveis'] ?>"
                        data-sort-status="<?= array_search($livro['status_validacao'], array_keys(ACERVO_STATUS), true) ?>"
                        data-sort-criado="<?= $livro['criado_em'] . sprintf('%04d', $id) ?>">
                        <td class="adm-td-cover"><a href="<?= htmlspecialchars($urlDetalhes) ?>" tabindex="-1" aria-hidden="true"><?= acervo_capa($livro) ?></a></td>
                        <td class="adm-td-title">
                            <a class="adm-book-link" href="<?= htmlspecialchars($urlDetalhes) ?>"><?= htmlspecialchars($livro['titulo']) ?></a>
                            <small><?= htmlspecialchars(trim(($livro['editora'] ?: 'Editora não informada') . ($livro['ano_publicacao'] ? ' · ' . $livro['ano_publicacao'] : '') . ($livro['edicao'] ? ' · ' . $livro['edicao'] : ''))) ?></small>
                        </td>
                        <td data-label="Autor"><?= htmlspecialchars(implode(', ', $livro['autores_nomes'])) ?></td>
                        <td data-label="Categoria"><span class="adm-chip"><?= htmlspecialchars($livro['categoria_principal']) ?></span></td>
                        <td data-label="ISBN" class="adm-table__muted adm-mono"><?= $livro['isbn'] ? htmlspecialchars($livro['isbn']) : '<span class="adm-missing">Não informado</span>' ?></td>
                        <td data-label="Exemplares" class="adm-num">
                            <?= $livro['total_exemplares'] ?>
                            <?php if ($livro['planilha'] && $livro['planilha']['quantidade_informada'] !== null && $livro['planilha']['quantidade_informada'] !== $livro['total_exemplares']): ?>
                                <small class="adm-num__hint" title="Quantidade informada na planilha importada">planilha: <?= (int)$livro['planilha']['quantidade_informada'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Disponíveis" class="adm-num"><strong class="<?= $livro['disponiveis'] > 0 ? 'adm-text-green' : 'adm-table__muted' ?>"><?= $livro['disponiveis'] ?></strong></td>
                        <td data-label="Cadastro"><?= acervo_badge_status($livro['status_validacao']) ?><?= acervo_badges_importacao($livro) ?></td>
                        <td class="adm-actions-col">
                            <div class="adm-row-actions">
                                <a class="adm-icon-button adm-icon-button--sm" href="<?= htmlspecialchars($urlDetalhes) ?>" aria-label="Ver detalhes de <?= htmlspecialchars($livro['titulo']) ?>" title="Ver detalhes"><?= icon('eye') ?></a>
                                <div class="adm-rowmenu">
                                    <button type="button" class="adm-icon-button adm-icon-button--sm" data-rowmenu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Mais ações para <?= htmlspecialchars($livro['titulo']) ?>"><?= icon('ellipsis-vertical') ?></button>
                                    <div class="adm-rowmenu__list" role="menu" hidden>
                                        <a role="menuitem" class="adm-dropdown__item" href="<?= htmlspecialchars($urlDetalhes) ?>"><?= icon('eye') ?> Ver detalhes</a>
                                        <a role="menuitem" class="adm-dropdown__item" href="<?= htmlspecialchars(admin_url('editar-livro') . '?id=' . $id) ?>"><?= icon('pencil') ?> Editar informações</a>
                                        <a role="menuitem" class="adm-dropdown__item" href="<?= htmlspecialchars($urlDetalhes) ?>#exemplares"><?= icon('book-copy') ?> Gerenciar exemplares</a>
                                        <button role="menuitem" type="button" class="adm-dropdown__item" data-add-exemplares="<?= $id ?>"><?= icon('plus') ?> Adicionar exemplar</button>
                                        <button role="menuitem" type="button" class="adm-dropdown__item adm-dropdown__item--danger" data-archive-livro="<?= $id ?>"><?= icon('archive') ?> Arquivar cadastro</button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr data-empty hidden>
                    <td colspan="9">
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

<?php
include __DIR__ . '/includes/modal-exemplares.php';
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261027']);
