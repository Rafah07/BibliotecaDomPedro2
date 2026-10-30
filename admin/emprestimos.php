<?php
/** Empréstimos: ativos, atrasados, devolvidos e histórico completo, com pesquisa, período e paginação no servidor. */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

emprestimos_iniciar();
$hoje = emprestimos_hoje();
$filtros = emprestimos_filtros($_GET);
$base = admin_url('emprestimos');
try {
    $resultado = emprestimos_repo()->listar($filtros, $hoje, max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
    $resumo = emprestimos_repo()->resumo($hoje);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os empréstimos', emprestimos_erro_tecnico($e, 'listagem de empréstimos'));
    exit;
}
$temFiltro = $filtros['q'] !== '' || $filtros['de'] !== '' || $filtros['ate'] !== '';

admin_page_start('emprestimos', 'Empréstimos', 'Empréstimos', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Empréstimos</h1>
        <p>Empréstimos de exemplares físicos para os alunos cadastrados. Nenhum registro é apagado: a devolução fica no histórico.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('atrasos')) ?>"><?= icon('clock-alert') ?> Atrasos</a>
        <?php if (auth_pode('emprestimos.registrar')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('novo-emprestimo')) ?>"><?= icon('plus') ?> Novo empréstimo</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats" aria-label="Resumo dos empréstimos">
    <?php foreach ([
        ['Empréstimos ativos', $resumo['ativos'], $resumo['vencem_hoje'] . ' vencem hoje', 'book-open-check', 'indigo', 'ativos'],
        ['Atrasados', $resumo['atrasados'], 'Prazo vencido, sem devolução', 'clock-alert', 'red', 'atrasados'],
        ['Devoluções realizadas', $resumo['devolvidos'], $resumo['devolvidos_mes'] . ' neste mês', 'rotate-ccw', 'green', 'devolvidos'],
        ['Total registrado', $resumo['todos'], 'Histórico completo', 'history', 'blue', 'todos'],
    ] as [$titulo, $valor, $desc, $ic, $tom, $sit]): ?>
        <a class="adm-card adm-stat adm-stat--compact adm-stat--action" href="<?= e(admin_url_filtros($base, ['situacao' => $sit])) ?>">
            <div class="adm-stat__top">
                <p class="adm-stat__title"><?= e($titulo) ?></p>
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($ic) ?></span>
            </div>
            <p class="adm-stat__value"><?= number_format($valor, 0, ',', '.') ?></p>
            <p class="adm-stat__desc"><?= e($desc) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-emprestimos">
    <?= emprestimos_abas($base, $filtros, $resumo) ?>
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-emprestimos"><?= e(EMPRESTIMOS_SITUACOES[$filtros['situacao']]['label']) ?></h2>
            <p aria-live="polite"><?= number_format($resultado['total'], 0, ',', '.') ?> <?= $resultado['total'] === 1 ? 'empréstimo encontrado' : 'empréstimos encontrados' ?></p>
        </div>
    </div>

    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
        <input type="hidden" name="situacao" value="<?= e($filtros['situacao']) ?>">
        <label class="adm-field-search adm-toolbar__q">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar empréstimos</span>
            <input type="search" name="q" placeholder="Aluno, matrícula, e-mail, título ou código do exemplar..." value="<?= e($filtros['q']) ?>" autocomplete="off">
        </label>
        <div class="adm-filters adm-filters--4">
            <label class="adm-filter">
                <span>Retirada a partir de</span>
                <input class="adm-input" type="date" name="de" value="<?= e($filtros['de']) ?>">
            </label>
            <label class="adm-filter">
                <span>Retirada até</span>
                <input class="adm-input" type="date" name="ate" value="<?= e($filtros['ate']) ?>">
            </label>
        </div>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['situacao' => $filtros['situacao']])) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
    </form>

    <?= emprestimos_tabela($resultado['itens'], $hoje, [],
        $temFiltro ? 'Nenhum empréstimo encontrado' : 'Nenhum empréstimo nesta situação',
        $temFiltro ? 'Revise a pesquisa ou o período.' : ($resumo['todos'] === 0 ? 'Os empréstimos aparecem aqui assim que forem registrados.' : '')) ?>

    <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtros) ?>
</section>

<p class="adm-panel__footnote"><?= icon('info') ?> <?= e(emprestimos_regras_texto()) ?></p>

<?php
admin_page_end();
