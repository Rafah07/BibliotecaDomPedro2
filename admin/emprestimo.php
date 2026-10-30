<?php
/** Detalhes de um empréstimo: dados, renovações e movimentações do exemplar ligadas a ele. */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

emprestimos_iniciar();
$hoje = emprestimos_hoje();
$id = (int)admin_param($_GET, 'id', '0');
try {
    $m = emprestimos_repo()->buscar($id, $hoje);
    $renovacoes = $m ? emprestimos_repo()->renovacoes($id) : [];
    $movimentos = $m ? emprestimos_repo()->movimentacoes((int)$m['exemplar_id'], $id) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o empréstimo', emprestimos_erro_tecnico($e, 'detalhes do empréstimo'));
    exit;
}
if (!$m) {
    http_response_code(404);
    admin_pagina_erro('Empréstimo não encontrado', 'O empréstimo solicitado não existe.', 'book-x');
    exit;
}
$aberto = $m['data_devolucao'] === null;
$impedimento = emprestimos_servico()->impedimentoRenovacao($m);

admin_page_start('emprestimos', 'Empréstimo nº ' . $id, 'Empréstimos', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Empréstimo nº ' . $id]]) ?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Empréstimo nº <?= $id ?></p>
        <h1><?= e($m['titulo']) ?></h1>
        <p><span class="adm-mono"><?= e($m['codigo_individual']) ?></span> · <?= emprestimos_badge($m, $hoje) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('emprestimos')) ?>"><?= icon('arrow-left') ?> Voltar</a>
        <?php if ($aberto && auth_pode('emprestimos.registrar')): ?>
            <?php if (!$impedimento): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('renovar-emprestimo') . '?id=' . $id) ?>"><?= icon('refresh-cw') ?> Renovar</a><?php endif; ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('devolver-emprestimo') . '?id=' . $id) ?>"><?= icon('rotate-ccw') ?> Registrar devolução</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<div class="adm-form-layout">
    <div class="adm-form-layout__main">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><div><h2>Dados do empréstimo</h2></div></div>
            <?= emprestimos_resumo_html($m, $hoje) ?>
            <?php if ($m['observacoes']): ?>
                <h3 class="adm-subtitle"><?= icon('message-square-text') ?> Observações</h3>
                <p class="adm-prose"><?= nl2br(e($m['observacoes'])) ?></p>
            <?php endif; ?>
        </section>

        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><div><h2>Renovações</h2><p><?= $renovacoes ? count($renovacoes) . ' renovação(ões) registrada(s).' : 'Nenhuma renovação.' ?></p></div></div>
            <?php if ($renovacoes): ?>
                <div class="adm-table-wrap adm-table-wrap--inset">
                    <table class="adm-table adm-table--compact">
                        <thead><tr><th scope="col">Data</th><th scope="col">Prazo anterior</th><th scope="col">Novo prazo</th><th scope="col">Responsável</th><th scope="col">Observação</th></tr></thead>
                        <tbody>
                            <?php foreach ($renovacoes as $r): ?>
                                <tr><td><?= emprestimos_data($r['criado_em'], true) ?></td><td><?= emprestimos_data($r['prevista_anterior']) ?></td><td><strong><?= emprestimos_data($r['prevista_nova']) ?></strong></td><td><?= e($r['responsavel'] ?? '—') ?></td><td class="adm-table__muted"><?= e($r['observacao'] ?? '') ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="adm-form-layout__side">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><div><h2>Movimentações</h2><p>Registro do exemplar ligado a este empréstimo.</p></div></div>
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
            <div class="adm-form-actions adm-form-actions--static">
                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('historico-exemplar') . '?id=' . (int)$m['exemplar_id']) ?>"><?= icon('history') ?> Histórico do exemplar</a>
                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('historico-aluno') . '?id=' . (int)$m['aluno_id']) ?>"><?= icon('graduation-cap') ?> Histórico do aluno</a>
            </div>
        </section>
        <?php if ($aberto && $impedimento): ?>
            <?= admin_aviso('aviso', 'Renovação indisponível', $impedimento) ?>
        <?php endif; ?>
    </aside>
</div>

<?php
admin_page_end();
