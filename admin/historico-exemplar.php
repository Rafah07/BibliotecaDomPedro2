<?php
/** Histórico de um exemplar físico: situação atual, empréstimos anteriores e movimentações. Aceita ?id= ou ?codigo=. */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

emprestimos_iniciar();
$hoje = emprestimos_hoje();
$base = admin_url('historico-exemplar');
try {
    $ex = admin_param($_GET, 'codigo') !== ''
        ? emprestimos_repo()->exemplarPorCodigo(mb_substr(admin_param($_GET, 'codigo'), 0, 30))
        : emprestimos_repo()->exemplar((int)admin_param($_GET, 'id', '0'));
    if ($ex) {
        $pagina = max(1, (int)admin_param($_GET, 'pagina', '1'));
        $lista = emprestimos_repo()->listar(['situacao' => 'todos', 'exemplar' => $ex['id'], 'ordem' => 'retirada', 'dir' => 'desc'], $hoje, $pagina, 25);
        $resumo = emprestimos_repo()->resumoExemplar((int)$ex['id'], $hoje);
        $movimentos = emprestimos_repo()->movimentacoes((int)$ex['id']);
        $aberto = $ex['emprestimo_aberto'] ? emprestimos_repo()->buscar((int)$ex['emprestimo_aberto'], $hoje) : null;
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o histórico', emprestimos_erro_tecnico($e, 'histórico do exemplar'));
    exit;
}
if (!$ex) {
    http_response_code(404);
    admin_pagina_erro('Exemplar não encontrado', 'Nenhum exemplar físico com esse código ou número.', 'book-x');
    exit;
}
$tom = ACERVO_SITUACOES[$ex['situacao']]['tom'] ?? 'blue';

admin_page_start('emprestimos', 'Histórico do exemplar ' . $ex['codigo_individual'], 'Empréstimos', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Histórico do exemplar']]) ?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Histórico do exemplar</p>
        <h1 class="adm-mono"><?= e($ex['codigo_individual']) ?></h1>
        <p><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$ex['livro_id']) ?>"><?= e($ex['titulo']) ?></a><?= $ex['autores'] ? ' · ' . e($ex['autores']) : '' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('emprestimos')) ?>"><?= icon('arrow-left') ?> Empréstimos</a>
        <?php if ($aberto && auth_pode('emprestimos.registrar')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('devolver-emprestimo') . '?id=' . (int)$aberto['id']) ?>"><?= icon('rotate-ccw') ?> Registrar devolução</a>
        <?php elseif ($ex['situacao'] === 'disponivel' && $ex['arquivado_em'] === null && auth_pode('emprestimos.registrar')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('novo-emprestimo') . '?exemplar=' . (int)$ex['id']) ?>"><?= icon('plus') ?> Emprestar este exemplar</a>
        <?php endif; ?>
    </div>
</section>

<section class="adm-stats" aria-label="Resumo do exemplar">
    <article class="adm-card adm-stat adm-stat--compact">
        <div class="adm-stat__top"><p class="adm-stat__title">Situação atual</p><span class="adm-icon-box adm-icon-box--sm adm-tone--<?= e($tom) ?>"><?= icon('book-copy') ?></span></div>
        <p class="adm-stat__value adm-stat__value--texto"><?= e(emprestimos_situacao_exemplar($ex['situacao'])) ?></p>
        <p class="adm-stat__desc"><?= $aberto ? 'Com ' . e($aberto['aluno_nome']) . ' até ' . emprestimos_data($aberto['data_prevista']) : ($ex['conferido_em'] ? 'Conferido em ' . emprestimos_data($ex['conferido_em']) : 'Ainda não conferido no inventário') ?></p>
    </article>
    <?php foreach ([
        ['Empréstimos', $resumo['total'], $resumo['devolvidos'] . ' devolvido(s)', 'book-open-check', 'indigo'],
        ['Com atraso', $resumo['com_atraso'], 'Devolvidos ou em aberto após o prazo', 'clock-alert', 'red'],
        ['Renovações', $resumo['renovacoes'], 'Somando todos os empréstimos', 'refresh-cw', 'teal'],
    ] as [$titulo, $valor, $desc, $ic, $t]): ?>
        <article class="adm-card adm-stat adm-stat--compact">
            <div class="adm-stat__top"><p class="adm-stat__title"><?= e($titulo) ?></p><span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $t ?>"><?= icon($ic) ?></span></div>
            <p class="adm-stat__value"><?= (int)$valor ?></p>
            <p class="adm-stat__desc"><?= e($desc) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<div class="adm-form-layout">
    <div class="adm-form-layout__main">
        <section class="adm-card adm-panel" aria-labelledby="titulo-emprestimos-exemplar">
            <div class="adm-panel__header"><div><h2 id="titulo-emprestimos-exemplar">Empréstimos deste exemplar</h2><p>Retiradas e devoluções, da mais recente para a mais antiga.</p></div></div>
            <?= emprestimos_tabela($lista['itens'], $hoje, [], 'Este exemplar ainda não foi emprestado') ?>
            <?= admin_paginacao($lista['total'], $lista['pagina'], 25, $base, ['id' => (int)$ex['id']], [25]) ?>
        </section>
    </div>
    <aside class="adm-form-layout__side">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><div><h2>Movimentações</h2><p>As 200 mais recentes.</p></div></div>
            <?php if (!$movimentos): ?><p class="adm-table__muted">Nenhuma movimentação registrada.</p><?php endif; ?>
            <ol class="adm-timeline">
                <?php foreach ($movimentos as $mov): ?>
                    <?php $tipo = EMPRESTIMOS_MOVIMENTOS[$mov['tipo']] ?? ['label' => $mov['tipo'], 'icone' => 'info', 'tom' => 'blue']; ?>
                    <li>
                        <span class="adm-icon-box adm-icon-box--xs adm-tone--<?= $tipo['tom'] ?>"><?= icon($tipo['icone']) ?></span>
                        <div>
                            <p><strong><?= e($tipo['label']) ?></strong></p>
                            <p class="adm-timeline__desc"><?= e($mov['descricao']) ?></p>
                            <small><?= emprestimos_data($mov['criado_em'], true) ?> · <?= e($mov['responsavel'] ?? 'Sistema') ?></small>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </aside>
</div>

<?php
admin_page_end();
