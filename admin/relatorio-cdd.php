<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/cdd.php';
require_once __DIR__ . '/includes/exportar.php';

$usuario = cdd_pagina_iniciar('cdd.ver');
$data = fn (string $campo) => is_string($_GET[$campo] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$campo]) ? $_GET[$campo] : '';
$statusFiltro = [
    'pendente' => 'Pendente', 'sugerida' => 'Sugestão encontrada', 'em_revisao' => 'Em revisão', 'aprovada' => 'Aprovado',
    'conflito' => 'Conflito', 'precisa_revisao' => 'Precisa de revisão', 'sem_referencia' => 'Sem referências suficientes',
];
$filtros = [
    'de' => $data('de'),
    'ate' => $data('ate'),
    'categoria_id' => (int)($_GET['categoria'] ?? 0) ?: null,
    'status' => isset($statusFiltro[$_GET['status'] ?? '']) ? $_GET['status'] : '',
    'responsavel_id' => (int)($_GET['responsavel'] ?? 0) ?: null,
];
$rel = cdd_relatorio($filtros);
$categorias = acervo_categorias();
$responsaveis = cdd_responsaveis();

// ---------- Exportação ----------
$formato = $_GET['formato'] ?? '';
if (in_array($formato, ['csv', 'xlsx', 'estante'], true)) {
    auth_exigir('cdd.exportar');
    $sufixo = date('Y-m-d');
    if ($formato === 'estante') {
        // Ordem das estantes: somente CDDs aprovadas, ordenadas pela CDD e depois pelo autor.
        $linhas = array_filter($rel['linhas'], fn ($i) => $i['class']['cdd_aprovada'] !== null);
        usort($linhas, fn ($a, $b) => strcmp($a['class']['cdd_aprovada'], $b['class']['cdd_aprovada']) ?: strcoll($a['autor'], $b['autor']) ?: strcoll($a['titulo'], $b['titulo']));
        exportar_csv('ordem-estante-cdd-' . $sufixo, ['CDD aprovada', 'Autor', 'Título', 'Edição', 'Ano', 'ISBN', 'Aprovada em'], array_map(fn ($i) => [
            $i['class']['cdd_aprovada'], $i['autor'], $i['titulo'], $i['edicao'], $i['ano'], $i['isbn'], cdd_data_hora($i['class']['aprovado_em']),
        ], $linhas));
    }
    $cabecalho = ['ID', 'Título', 'Autor', 'ISBN', 'Editora', 'Ano', 'Edição', 'Categoria', 'CDD antiga', 'CDD proposta', 'CDD aprovada',
        'Situação', 'Precisa de revisão', 'Fonte', 'Link da fonte', 'Responsável pela sugestão', 'Aprovada por', 'Aprovada em', 'Última pesquisa'];
    $linhas = array_map(fn ($i) => [
        $i['id'], $i['titulo'], $i['autores'], $i['isbn'], $i['editora'], $i['ano'], $i['edicao'], $i['categoria'],
        $i['class']['cdd_original'], $i['class']['cdd_proposta'], $i['class']['cdd_aprovada'],
        CDD_STATUS[$i['class']['status']]['label'], $i['class']['precisa_revisao'] ? 'Sim' : 'Não', $i['class']['fonte_nome'], $i['class']['fonte_url'],
        $i['class']['sugerido_por'] ? cdd_usuario_nome((int)$i['class']['sugerido_por']) : '',
        $i['class']['aprovado_por'] ? cdd_usuario_nome((int)$i['class']['aprovado_por']) : '', cdd_data_hora($i['class']['aprovado_em']),
        match ($i['pesquisa']['resultado'] ?? null) {
            'com_cdd' => 'CDD encontrada', 'conflito' => 'Números diferentes', 'sem_cdd' => 'Registro sem CDD', 'sem_registro' => 'Nenhum registro', 'erro' => 'Falha na consulta', default => 'Não pesquisado',
        },
    ], $rel['linhas']);
    $formato === 'xlsx'
        ? exportar_xlsx('relatorio-cdd-' . $sufixo, 'Classificação CDD', $cabecalho, $linhas)
        : exportar_csv('relatorio-cdd-' . $sufixo, $cabecalho, $linhas);
}

$r = $rel['resumo'];
$kpis = [
    ['titulo' => 'Livros analisados', 'valor' => $r['analisados'], 'icone' => 'search', 'tom' => 'blue', 'dica' => 'Pesquisados ou com alguma ação registrada'],
    ['titulo' => 'CDDs aprovadas', 'valor' => $r['aprovadas'], 'icone' => 'check-check', 'tom' => 'green'],
    ['titulo' => 'CDDs pendentes', 'valor' => $r['pendentes'], 'icone' => 'hourglass', 'tom' => 'amber', 'dica' => 'Sem CDD aprovada'],
    ['titulo' => 'Classificações rejeitadas', 'valor' => $r['rejeitadas'], 'icone' => 'circle-x', 'tom' => 'red'],
    ['titulo' => 'Conflitos encontrados', 'valor' => $r['conflitos'], 'icone' => 'git-compare-arrows', 'tom' => 'red'],
    ['titulo' => 'Sem referências suficientes', 'valor' => $r['sem_referencia'], 'icone' => 'file-search', 'tom' => 'muted'],
];
$maxClasse = max(1, max($rel['por_classe']));
$semReferencia = array_filter($rel['linhas'], fn ($i) => in_array($i['pesquisa']['resultado'] ?? '', ['sem_registro', 'sem_cdd'], true) && $i['class']['cdd_aprovada'] === null);
$query = array_filter(['de' => $filtros['de'], 'ate' => $filtros['ate'], 'categoria' => $filtros['categoria_id'], 'status' => $filtros['status'], 'responsavel' => $filtros['responsavel_id']]);
$linkExport = fn (string $f) => admin_url('relatorio-cdd') . '?' . http_build_query($query + ['formato' => $f]);
$v = fn ($valor) => htmlspecialchars((string)$valor);

admin_page_start('classificacao', 'Relatórios CDD', 'Acervo', 'Reclassificação bibliográfica pela CDD', [
    'assets/css/acervo.css?v=20261027',
    'assets/css/classificacao.css?v=20261027',
]);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Classificação CDD', admin_url('classificacao')], ['Relatórios']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Relatórios da reclassificação</h1>
        <p>Acompanhe o andamento e exporte os dados para planilhas.</p>
    </div>
    <?php if (auth_pode('cdd.exportar')): ?>
        <div class="adm-page-head__actions">
            <a class="adm-button adm-button--ghost" href="<?= $v($linkExport('csv')) ?>"><?= icon('download') ?> CSV</a>
            <a class="adm-button adm-button--primary" href="<?= $v($linkExport('xlsx')) ?>"><?= icon('file-spreadsheet') ?> Excel</a>
        </div>
    <?php endif; ?>
</section>

<?= cdd_abas('relatorio-cdd') ?>
<?= cdd_aviso_modo() ?>

<section class="adm-card adm-panel">
    <form class="adm-toolbar adm-toolbar--form" method="get">
        <div class="adm-filters adm-filters--5">
            <label class="adm-filter"><span>De</span><input class="adm-input" type="date" name="de" value="<?= $v($filtros['de']) ?>"></label>
            <label class="adm-filter"><span>Até</span><input class="adm-input" type="date" name="ate" value="<?= $v($filtros['ate']) ?>"></label>
            <label class="adm-filter"><span>Categoria</span>
                <select class="adm-select" name="categoria">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"<?= $filtros['categoria_id'] === (int)$cat['id'] ? ' selected' : '' ?>><?= $v($cat['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>Situação</span>
                <select class="adm-select" name="status">
                    <option value="">Todas</option>
                    <?php foreach ($statusFiltro as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"<?= $filtros['status'] === $valor ? ' selected' : '' ?>><?= $rotulo ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>Responsável</span>
                <select class="adm-select" name="responsavel">
                    <option value="">Todos</option>
                    <?php foreach ($responsaveis as $rid => $nome): ?>
                        <option value="<?= (int)$rid ?>"<?= $filtros['responsavel_id'] === (int)$rid ? ' selected' : '' ?>><?= $v($nome) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Aplicar</button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars(admin_url('relatorio-cdd')) ?>"><?= icon('filter-x') ?> Limpar</a>
    </form>
    <p class="adm-panel__footnote">O período considera a última alteração da classificação de cada livro. <?= count($rel['linhas']) ?> de <?= count(cdd_itens()) ?> títulos no filtro atual.</p>
</section>

<section class="adm-kpis adm-kpis--6" aria-label="Indicadores do relatório">
    <?php foreach ($kpis as $kpi): ?>
        <article class="adm-card adm-kpi"<?= isset($kpi['dica']) ? ' title="' . $v($kpi['dica']) . '"' : '' ?>>
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $kpi['tom'] ?>"><?= icon($kpi['icone']) ?></span>
            <span>
                <strong class="adm-kpi__value"><?= number_format($kpi['valor'], 0, ',', '.') ?></strong>
                <span class="adm-kpi__title"><?= $kpi['titulo'] ?></span>
            </span>
        </article>
    <?php endforeach; ?>
</section>

<div class="adm-columns adm-columns--even">
    <section class="adm-card adm-panel" aria-labelledby="titulo-classes">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-classes">Quantidade por classe principal</h2>
                <p>Somente CDDs aprovadas</p>
            </div>
        </div>
        <ul class="adm-class-bars">
            <?php foreach ($rel['por_classe'] as $classe => $qtd): ?>
                <li>
                    <span class="adm-class-bars__label"><strong class="adm-mono"><?= $classe ?>00</strong> <?= $v(CDD_CLASSES[$classe]) ?></span>
                    <span class="adm-class-bars__track" aria-hidden="true"><span style="width: <?= round($qtd / $maxClasse * 100, 1) ?>%"></span></span>
                    <span class="adm-class-bars__value"><?= $qtd ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="adm-card adm-panel" aria-labelledby="titulo-sem-ref">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-sem-ref">Livros sem referências bibliográficas suficientes</h2>
                <p>Pesquisados sem CDD confiável: encaminhe para análise manual</p>
            </div>
        </div>
        <?php if (!$semReferencia): ?>
            <p class="adm-missing">Nenhum livro nesta situação.</p>
        <?php else: ?>
            <ul class="adm-simple-list">
                <?php foreach (array_slice($semReferencia, 0, 15) as $i): ?>
                    <li>
                        <a class="adm-book-link" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . $i['id']) ?>"><?= $v($i['titulo']) ?></a>
                        <small><?= $v($i['autor'] ?: 'Autor não informado') ?> · <?= ($i['pesquisa']['resultado'] ?? '') === 'sem_cdd' ? 'registro encontrado sem CDD' : 'nenhum registro nas fontes' ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($semReferencia) > 15): ?><p class="adm-table__muted">E mais <?= count($semReferencia) - 15 ?>. Use o filtro "Sem referências suficientes" e exporte a lista.</p><?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php if (auth_pode('cdd.exportar')): ?>
<section class="adm-card adm-panel" aria-labelledby="titulo-exportar">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-exportar">Exportar</h2>
            <p>Os arquivos usam os filtros aplicados acima.</p>
        </div>
    </div>
    <div class="adm-quick adm-quick--cdd">
        <a class="adm-quick__item" href="<?= $v($linkExport('csv')) ?>"><span class="adm-icon-box adm-tone--blue"><?= icon('file-text') ?></span><span><strong>Relatório em CSV</strong><small>Separado por ponto e vírgula, abre no Excel</small></span></a>
        <a class="adm-quick__item" href="<?= $v($linkExport('xlsx')) ?>"><span class="adm-icon-box adm-tone--green"><?= icon('file-spreadsheet') ?></span><span><strong>Relatório em Excel</strong><small>Arquivo .xlsx com as CDDs como texto</small></span></a>
        <a class="adm-quick__item" href="<?= $v($linkExport('estante')) ?>"><span class="adm-icon-box adm-tone--teal"><?= icon('library-big') ?></span><span><strong>Ordem de estante</strong><small>CDDs aprovadas em ordem, base para etiquetas futuras</small></span></a>
    </div>
</section>
<?php endif; ?>

<?php
admin_page_end();
