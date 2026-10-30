<?php
/**
 * Exemplares físicos: listagem com pesquisa (código BDP, título, autor ou ISBN), filtros,
 * paginação no servidor e seleção para gerar etiquetas.
 * Sem banco configurado, mostra a versão demonstrativa (includes/demo/exemplares.php).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

if (!acervo_usa_mysql()) {
    require __DIR__ . '/includes/demo/exemplares.php';
    return;
}
fisico_iniciar();

$base = admin_url('exemplares');
$opcao = fn (string $nome, array $validos) => in_array(admin_param($_GET, $nome), $validos, true) ? admin_param($_GET, $nome) : '';
$porPagina = (int)admin_param($_GET, 'por_pagina', '25');
$filtros = [
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
    'situacao' => $opcao('situacao', ['todas', 'ativos', ...array_keys(ACERVO_SITUACOES)]) ?: 'ativos',
    'conservacao' => $opcao('conservacao', array_keys(ACERVO_CONSERVACAO)),
    'localizacao' => admin_param($_GET, 'localizacao') === 'sem' ? 'sem' : ((int)admin_param($_GET, 'localizacao') > 0 ? (string)(int)admin_param($_GET, 'localizacao') : ''),
    'conferencia' => $opcao('conferencia', ['nunca', 'conferido']),
    'etiqueta' => $opcao('etiqueta', ['sem', 'gerada']),
    'cdd' => $opcao('cdd', ['aprovada', 'pendente']),
    'livro' => (int)admin_param($_GET, 'livro') > 0 ? (string)(int)admin_param($_GET, 'livro') : '',
    'inventario' => (int)admin_param($_GET, 'inventario') > 0 ? (string)(int)admin_param($_GET, 'inventario') : '',
    'ordem' => $opcao('ordem', Biblioteca\Repositories\ExemplarRepository::ORDENS),
    'dir' => $opcao('dir', ['asc', 'desc']),
    'por_pagina' => in_array($porPagina, [25, 50, 100], true) ? (string)$porPagina : '25',
];
$consulta = $filtros;
if ($consulta['situacao'] === 'todas') {
    $consulta['situacao'] = '';
}
try {
    $resultado = exemplares_repo()->listar($consulta, max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
    $resumo = exemplares_repo()->resumo();
    $locais = localizacoes_servico()->ativas();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os exemplares', fisico_erro_tecnico($e, 'listagem de exemplares'));
    exit;
}
$temFiltro = $filtros['q'] !== '' || $filtros['conservacao'] !== '' || $filtros['localizacao'] !== '' || $filtros['conferencia'] !== ''
    || $filtros['etiqueta'] !== '' || $filtros['cdd'] !== '' || $filtros['livro'] !== '' || $filtros['inventario'] !== '' || $filtros['situacao'] !== 'ativos';
$podeEtiqueta = auth_pode('etiquetas.gerar');
$filtrosEtiqueta = array_filter(array_intersect_key($filtros, array_flip(['q', 'situacao', 'conservacao', 'localizacao', 'conferencia', 'etiqueta', 'cdd', 'livro', 'inventario'])));

admin_page_start('exemplares', 'Exemplares', 'Exemplares', 'Unidades físicas do acervo', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Exemplares']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Exemplares físicos</h1>
        <p>Cada exemplar é uma unidade real de um livro, com código próprio (BDP-000001). A quantidade das planilhas antigas não gera exemplares.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('localizacoes')) ?>"><?= icon('map-pin') ?> Localizações</a>
        <?php if ($podeEtiqueta): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('etiquetas')) ?>"><?= icon('printer') ?> Etiquetas</a><?php endif; ?>
        <?php if (auth_pode('exemplares.gerenciar')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('cadastrar-exemplar')) ?>"><?= icon('plus') ?> Cadastrar exemplar</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats adm-stats--6" aria-label="Resumo dos exemplares">
    <?= fisico_stat('Exemplares físicos', $resumo['ativos'], 'Cadastrados e não baixados', 'book-copy', 'blue', admin_url_filtros($base, [])) ?>
    <?= fisico_stat('Disponíveis', $resumo['disponiveis'], 'Podem ser emprestados', 'circle-check', 'green', admin_url_filtros($base, ['situacao' => 'disponivel'])) ?>
    <?= fisico_stat('Emprestados', $resumo['emprestados'], 'Com empréstimo ativo', 'book-open-check', 'indigo', admin_url_filtros($base, ['situacao' => 'emprestado'])) ?>
    <?= fisico_stat('Sem localização', $resumo['sem_local'], 'Localização ainda não confirmada', 'map-pin-off', 'amber', admin_url_filtros($base, ['localizacao' => 'sem'])) ?>
    <?= fisico_stat('Nunca conferidos', $resumo['nunca_conferidos'], 'Sem conferência de inventário', 'clipboard-check', 'teal', admin_url_filtros($base, ['conferencia' => 'nunca'])) ?>
    <?= fisico_stat('Livros sem exemplar', $resumo['livros_sem_exemplar'], 'Registros sem unidade física', 'book-x', 'red', admin_url_filtros(admin_url('acervo'), ['exemplares' => 'sem'])) ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-exemplares">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-exemplares">Exemplares</h2>
            <p aria-live="polite"><?= fisico_numero($resultado['total']) ?> <?= $resultado['total'] === 1 ? 'exemplar encontrado' : 'exemplares encontrados' ?><?= $temFiltro ? ' com os filtros aplicados' : '' ?></p>
        </div>
        <?php if ($podeEtiqueta && $resultado['total'] > 0): ?>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros(admin_url('etiquetas'), $filtrosEtiqueta + ['origem' => 'filtro'])) ?>"><?= icon('printer') ?> Etiquetas de todos os filtrados</a>
        <?php endif; ?>
    </div>

    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
        <label class="adm-field-search adm-toolbar__q">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar exemplares</span>
            <input type="search" name="q" placeholder="Código BDP, título, autor ou ISBN..." value="<?= e($filtros['q']) ?>" autocomplete="off" data-scanner>
        </label>
        <div class="adm-filters adm-filters--4">
            <label class="adm-filter"><span>Situação</span>
                <select class="adm-select" name="situacao">
                    <option value="ativos"<?= $filtros['situacao'] === 'ativos' ? ' selected' : '' ?>>Todas, exceto baixados</option>
                    <option value="todas"<?= $filtros['situacao'] === 'todas' ? ' selected' : '' ?>>Todas, inclusive baixados</option>
                    <?php foreach (ACERVO_SITUACOES as $valor => $s): ?>
                        <option value="<?= $valor ?>"<?= $filtros['situacao'] === $valor ? ' selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>Localização</span>
                <select class="adm-select" name="localizacao">
                    <option value="">Todas</option>
                    <option value="sem"<?= $filtros['localizacao'] === 'sem' ? ' selected' : '' ?>>Sem localização</option>
                    <?php foreach ($locais as $l): ?>
                        <option value="<?= (int)$l['id'] ?>"<?= $filtros['localizacao'] === (string)$l['id'] ? ' selected' : '' ?>><?= e(fisico_local($l)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>Conferência</span>
                <select class="adm-select" name="conferencia">
                    <option value="">Todas</option>
                    <option value="nunca"<?= $filtros['conferencia'] === 'nunca' ? ' selected' : '' ?>>Ainda não conferidos</option>
                    <option value="conferido"<?= $filtros['conferencia'] === 'conferido' ? ' selected' : '' ?>>Já conferidos</option>
                </select>
            </label>
            <label class="adm-filter"><span>Conservação</span>
                <select class="adm-select" name="conservacao">
                    <option value="">Todas</option>
                    <?php foreach (ACERVO_CONSERVACAO as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"<?= $filtros['conservacao'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>Etiqueta</span>
                <select class="adm-select" name="etiqueta">
                    <option value="">Todas</option>
                    <option value="sem"<?= $filtros['etiqueta'] === 'sem' ? ' selected' : '' ?>>Etiqueta ainda não gerada</option>
                    <option value="gerada"<?= $filtros['etiqueta'] === 'gerada' ? ' selected' : '' ?>>Etiqueta já gerada</option>
                </select>
            </label>
            <label class="adm-filter"><span>Classificação CDD</span>
                <select class="adm-select" name="cdd">
                    <option value="">Todas</option>
                    <option value="aprovada"<?= $filtros['cdd'] === 'aprovada' ? ' selected' : '' ?>>CDD aprovada</option>
                    <option value="pendente"<?= $filtros['cdd'] === 'pendente' ? ' selected' : '' ?>>CDD pendente</option>
                </select>
            </label>
        </div>
        <?php foreach (['livro', 'inventario', 'por_pagina'] as $oculto): ?>
            <?php if ($filtros[$oculto] !== ''): ?><input type="hidden" name="<?= $oculto ?>" value="<?= e($filtros[$oculto]) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
    </form>
    <?php if ($filtros['livro'] !== '' || $filtros['inventario'] !== ''): ?>
        <p class="adm-filtro-ativo"><?= icon('info') ?> Mostrando só os exemplares <?= $filtros['livro'] !== '' ? 'do livro nº ' . (int)$filtros['livro'] : 'cadastrados no inventário nº ' . (int)$filtros['inventario'] ?>. <a href="<?= e(admin_url_filtros($base, $filtros, ['livro' => null, 'inventario' => null, 'pagina' => null])) ?>">Ver todos</a></p>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url('etiquetas')) ?>" data-selecao>
        <?= csrf_campo() ?>
        <input type="hidden" name="origem" value="selecao">
        <?php if ($podeEtiqueta && $resultado['itens']): ?>
            <div class="adm-selecao-barra">
                <span data-selecao-total aria-live="polite">Nenhum exemplar selecionado</span>
                <button type="submit" class="adm-button adm-button--primary adm-button--sm" data-selecao-enviar disabled><?= icon('printer') ?> Gerar etiquetas dos selecionados</button>
            </div>
        <?php endif; ?>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards adm-table--exemplares">
                <thead>
                    <tr>
                        <?php if ($podeEtiqueta): ?><th scope="col" class="adm-check-col"><input type="checkbox" data-selecao-todos aria-label="Selecionar todos os exemplares desta página"></th><?php endif; ?>
                        <?= admin_th_ordenar('Código', 'codigo', $base, $filtros) ?>
                        <?= admin_th_ordenar('Livro', 'titulo', $base, $filtros) ?>
                        <?= admin_th_ordenar('Localização', 'local', $base, $filtros) ?>
                        <th scope="col">Conservação</th>
                        <th scope="col">Situação</th>
                        <?= admin_th_ordenar('Última conferência', 'conferencia', $base, $filtros, 'desc') ?>
                        <th scope="col" class="adm-actions-col">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultado['itens'] as $ex): ?>
                        <?php $url = admin_url('detalhes-exemplar') . '?id=' . (int)$ex['id']; ?>
                        <tr>
                            <?php if ($podeEtiqueta): ?>
                                <td class="adm-check-col"><?php if ($ex['situacao'] !== 'baixado'): ?><input type="checkbox" name="ids[]" value="<?= (int)$ex['id'] ?>" data-selecao-item aria-label="Selecionar <?= e($ex['codigo_individual']) ?>"><?php endif; ?></td>
                            <?php endif; ?>
                            <td class="adm-td-title"><a class="adm-code" href="<?= e($url) ?>"><?= e($ex['codigo_individual']) ?></a>
                                <?php if ($ex['etiqueta_gerada_em'] === null && $ex['situacao'] !== 'baixado'): ?><small class="adm-table__muted">sem etiqueta</small><?php endif; ?></td>
                            <td data-label="Livro" class="adm-td-wrap">
                                <a class="adm-book-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$ex['livro_id']) ?>"><?= e($ex['titulo']) ?></a>
                                <small class="adm-block-muted"><?= e($ex['autores'] ?: 'Autor não informado') ?><?= $ex['cdd_aprovada'] ? ' · CDD ' . e($ex['cdd_aprovada']) : '' ?></small>
                            </td>
                            <td data-label="Localização"><?= $ex['localizacao_id'] ? e(fisico_local($ex)) : '<span class="adm-missing">Sem localização</span>' ?></td>
                            <td data-label="Conservação"><?= acervo_badge_conservacao($ex['estado_conservacao']) ?></td>
                            <td data-label="Situação"><?= acervo_badge_situacao($ex['situacao']) ?></td>
                            <td data-label="Última conferência" class="adm-table__muted"><?= $ex['conferido_em'] ? admin_data_hora($ex['conferido_em'], false) : '<span class="adm-missing">Não conferido</span>' ?></td>
                            <td class="adm-actions-col"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($url) ?>"><?= icon('eye') ?> Ver</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$resultado['itens']): ?>
                        <tr><td colspan="8">
                            <div class="adm-empty">
                                <?= icon('book-copy') ?>
                                <?php if ($temFiltro): ?>
                                    <strong>Nenhum exemplar encontrado</strong>
                                    <span>Revise a pesquisa ou os filtros. Para ler um código, digite ou use o leitor de código de barras no campo de pesquisa.</span>
                                <?php else: ?>
                                    <strong>Nenhum exemplar físico cadastrado ainda</strong>
                                    <span>Os exemplares são cadastrados na página de cada livro ou durante a conferência do inventário, com o livro em mãos.</span>
                                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('inventario')) ?>"><?= icon('clipboard-check') ?> Abrir o inventário</a>
                                <?php endif; ?>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtros) ?>
</section>

<?php
admin_page_end(FISICO_JS);
