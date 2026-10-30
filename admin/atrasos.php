<?php
/**
 * Empréstimos atrasados: em aberto e com a data prevista anterior a hoje (data da escola,
 * APP_TIMEZONE). O atraso é calculado na consulta, nunca gravado. Não gera multa nem envia
 * avisos para fora do sistema.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

emprestimos_iniciar();
$hoje = emprestimos_hoje();
$filtros = emprestimos_filtros($_GET, 'atrasados');
$filtros['situacao'] = 'atrasados';
$base = admin_url('atrasos');
try {
    $resultado = emprestimos_repo()->listar($filtros, $hoje, max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
    $resumo = emprestimos_repo()->resumo($hoje);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os atrasos', emprestimos_erro_tecnico($e, 'atrasos'));
    exit;
}

admin_page_start('atrasos', 'Atrasos', 'Atrasos', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Atrasos']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Empréstimos atrasados</h1>
        <p>Empréstimos em aberto cuja devolução estava prevista até ontem (<?= e(emprestimos_data(date('Y-m-d', strtotime($hoje . ' -1 day')))) ?>, horário de <?= e(str_replace('_', ' ', emprestimos_regras()->fuso)) ?>). O sistema não cobra multas nem envia avisos.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('emprestimos')) ?>"><?= icon('book-open-check') ?> Todos os empréstimos</a>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats adm-stats--2" aria-label="Resumo dos atrasos">
    <article class="adm-card adm-stat adm-stat--compact">
        <div class="adm-stat__top"><p class="adm-stat__title">Atrasados</p><span class="adm-icon-box adm-icon-box--sm adm-tone--red"><?= icon('clock-alert') ?></span></div>
        <p class="adm-stat__value"><?= number_format($resumo['atrasados'], 0, ',', '.') ?></p>
        <p class="adm-stat__desc">de <?= number_format($resumo['ativos'], 0, ',', '.') ?> empréstimos ativos</p>
    </article>
    <article class="adm-card adm-stat adm-stat--compact">
        <div class="adm-stat__top"><p class="adm-stat__title">Vencem hoje</p><span class="adm-icon-box adm-icon-box--sm adm-tone--amber"><?= icon('calendar-clock') ?></span></div>
        <p class="adm-stat__value"><?= number_format($resumo['vencem_hoje'], 0, ',', '.') ?></p>
        <p class="adm-stat__desc">Ainda no prazo</p>
    </article>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-atrasos">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-atrasos">Atrasados, do mais antigo para o mais recente</h2>
            <p aria-live="polite"><?= number_format($resultado['total'], 0, ',', '.') ?> <?= $resultado['total'] === 1 ? 'empréstimo' : 'empréstimos' ?></p>
        </div>
    </div>
    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
        <label class="adm-field-search adm-toolbar__q">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar atrasos</span>
            <input type="search" name="q" placeholder="Aluno, matrícula, e-mail, título ou código do exemplar..." value="<?= e($filtros['q']) ?>" autocomplete="off">
        </label>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar</a>
    </form>
    <?= emprestimos_tabela($resultado['itens'], $hoje, ['sem_devolucao', 'com_atraso'], $filtros['q'] !== '' ? 'Nenhum atraso encontrado' : 'Nenhum empréstimo atrasado', $filtros['q'] !== '' ? 'Revise a pesquisa.' : 'Todos os empréstimos em aberto estão no prazo.') ?>
    <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, array_diff_key($filtros, ['situacao' => 1])) ?>
</section>

<?php
admin_page_end();
