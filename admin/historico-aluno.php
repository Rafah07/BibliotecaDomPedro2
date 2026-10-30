<?php
/**
 * Histórico de circulação de um aluno: empréstimos ativos, anteriores, devoluções, atrasos e
 * renovações. O histórico continua disponível mesmo com o cadastro inativo (nada é apagado).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

emprestimos_iniciar();
$hoje = emprestimos_hoje();
$id = (int)admin_param($_GET, 'id', '0');
$filtros = emprestimos_filtros($_GET, 'todos');
$base = admin_url('historico-aluno');
try {
    $aluno = emprestimos_repo()->aluno($id);
    if ($aluno) {
        $resumo = emprestimos_repo()->resumoAluno($id, $hoje);
        $ativos = emprestimos_repo()->listar(['situacao' => 'ativos', 'aluno' => $id], $hoje, 1, 100);
        $lista = emprestimos_repo()->listar(['situacao' => $filtros['situacao'], 'aluno' => $id, 'q' => $filtros['q'], 'ordem' => 'retirada', 'dir' => 'desc'], $hoje, max(1, (int)admin_param($_GET, 'pagina', '1')), 25);
        $renovacoes = emprestimos_repo()->renovacoesDoAluno($id);
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o histórico', emprestimos_erro_tecnico($e, 'histórico do aluno'));
    exit;
}
if (!$aluno) {
    http_response_code(404);
    admin_pagina_erro('Aluno não encontrado', 'O cadastro solicitado não existe.', 'graduation-cap');
    exit;
}
$filtrosLista = ['id' => $id, 'situacao' => $filtros['situacao'], 'q' => $filtros['q']];
$resumoAbas = ['ativos' => $resumo['ativos'], 'atrasados' => $resumo['atrasados'], 'devolvidos' => $resumo['devolvidos'], 'todos' => $resumo['total']];

admin_page_start('emprestimos', 'Histórico do aluno', 'Empréstimos', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Histórico do aluno']]) ?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Histórico do aluno</p>
        <h1><?= e($aluno['nome']) ?></h1>
        <p><?= e(emprestimos_aluno_id($aluno)) ?><?= $aluno['turma'] ? ' · Turma ' . e($aluno['turma']) : '' ?>
            <?= (int)$aluno['ativo'] ? '' : ' · <span class="adm-status adm-tone--muted">Cadastro inativo (histórico preservado)</span>' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('emprestimos')) ?>"><?= icon('arrow-left') ?> Empréstimos</a>
        <?php if ((int)$aluno['ativo'] && auth_pode('emprestimos.registrar')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('novo-emprestimo') . '?aluno=' . $id) ?>"><?= icon('plus') ?> Novo empréstimo para este aluno</a>
        <?php endif; ?>
    </div>
</section>

<section class="adm-stats" aria-label="Resumo do aluno">
    <?php foreach ([
        ['Empréstimos ativos', $resumo['ativos'], $resumo['atrasados'] . ' atrasado(s)', 'book-open-check', $resumo['atrasados'] ? 'red' : 'indigo'],
        ['Devoluções', $resumo['devolvidos'], $resumo['devolvidos_com_atraso'] . ' com atraso', 'rotate-ccw', 'green'],
        ['Renovações', $resumo['renovacoes'], 'Somando todos os empréstimos', 'refresh-cw', 'teal'],
        ['Total de empréstimos', $resumo['total'], 'Desde o primeiro registro', 'history', 'blue'],
    ] as [$titulo, $valor, $desc, $ic, $tom]): ?>
        <article class="adm-card adm-stat adm-stat--compact">
            <div class="adm-stat__top"><p class="adm-stat__title"><?= e($titulo) ?></p><span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($ic) ?></span></div>
            <p class="adm-stat__value"><?= (int)$valor ?></p>
            <p class="adm-stat__desc"><?= e($desc) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-ativos">
    <div class="adm-panel__header"><div><h2 id="titulo-ativos">Empréstimos ativos</h2><p>Livros que estão com o aluno agora.</p></div></div>
    <?= emprestimos_tabela($ativos['itens'], $hoje, ['sem_aluno', 'sem_devolucao', 'com_atraso'], 'Nenhum empréstimo ativo') ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-historico">
    <?= emprestimos_abas($base, $filtrosLista, $resumoAbas) ?>
    <div class="adm-panel__header"><div><h2 id="titulo-historico">Histórico de empréstimos</h2><p><?= (int)$lista['total'] ?> registro(s)</p></div></div>
    <?= emprestimos_tabela($lista['itens'], $hoje, ['sem_aluno'], 'Nenhum empréstimo nesta situação') ?>
    <?= admin_paginacao($lista['total'], $lista['pagina'], 25, $base, $filtrosLista, [25]) ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-renovacoes">
    <div class="adm-panel__header"><div><h2 id="titulo-renovacoes">Renovações</h2><p>As 50 mais recentes.</p></div></div>
    <?php if (!$renovacoes): ?>
        <p class="adm-table__muted">Nenhuma renovação registrada.</p>
    <?php else: ?>
        <div class="adm-table-wrap adm-table-wrap--inset">
            <table class="adm-table adm-table--compact adm-table--cards">
                <thead><tr><th scope="col">Data</th><th scope="col">Livro</th><th scope="col">Exemplar</th><th scope="col">Prazo anterior</th><th scope="col">Novo prazo</th><th scope="col">Responsável</th></tr></thead>
                <tbody>
                    <?php foreach ($renovacoes as $r): ?>
                        <tr>
                            <td><a class="adm-link" href="<?= e(admin_url('emprestimo') . '?id=' . (int)$r['emprestimo_id']) ?>"><?= emprestimos_data($r['criado_em'], true) ?></a></td>
                            <td data-label="Livro"><?= e($r['titulo']) ?></td>
                            <td data-label="Exemplar" class="adm-mono"><?= e($r['codigo_individual']) ?></td>
                            <td data-label="Prazo anterior"><?= emprestimos_data($r['prevista_anterior']) ?></td>
                            <td data-label="Novo prazo"><strong><?= emprestimos_data($r['prevista_nova']) ?></strong></td>
                            <td data-label="Responsável"><?= e($r['responsavel'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
admin_page_end();
