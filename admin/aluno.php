<?php
/**
 * Detalhes de um aluno: dados cadastrais, empréstimos ativos e atrasados, devoluções anteriores
 * e histórico de alterações do cadastro. Inativar/reativar (POST) exige alunos.inativar.
 * A circulação vem das tabelas reais (emprestimos, emprestimo_renovacoes): nada é copiado.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/alunos.php';

use Biblioteca\Services\AlunoCadastroException;

$usuario = alunos_iniciar('alunos.ver');
$id = (int)admin_param($_GET, 'id', (string)($_POST['id'] ?? '0'));
$url = admin_url('aluno') . '?id=' . $id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    auth_exigir('alunos.inativar');
    $ativar = admin_param($_POST, 'acao') === 'reativar';
    try {
        $mudou = alunos_cadastro_servico()->definirAtivo($id, $ativar, $usuario['id'], admin_param($_POST, 'motivo'));
        if ($mudou) {
            admin_flash('sucesso', $ativar ? 'Cadastro reativado.' : 'Cadastro inativado.', $ativar
                ? 'O aluno volta a poder fazer empréstimos.'
                : 'O aluno não poderá fazer novos empréstimos. O histórico e os empréstimos em aberto continuam: eles podem ser devolvidos normalmente.');
        } else {
            admin_flash('aviso', 'Nada foi alterado.', $ativar ? 'O cadastro já estava ativo.' : 'O cadastro já estava inativo.');
        }
    } catch (AlunoCadastroException $e) {
        admin_flash('erro', 'O cadastro não foi alterado.', reset($e->erros) ?: $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'O cadastro não foi alterado.', alunos_erro_tecnico($e, 'inativação de aluno'));
    }
    admin_redirecionar($url);
}

$hoje = emprestimos_hoje();
$verCirculacao = auth_pode('emprestimos.ver');
try {
    $aluno = alunos_repo()->buscar($id);
    if ($aluno) {
        $alteracoes = alunos_repo()->alteracoes($id);
        if ($verCirculacao) {
            $resumo = emprestimos_repo()->resumoAluno($id, $hoje);
            $ativos = emprestimos_repo()->listar(['situacao' => 'ativos', 'aluno' => $id], $hoje, 1, 100);
            $atrasados = emprestimos_repo()->listar(['situacao' => 'atrasados', 'aluno' => $id], $hoje, 1, 100);
            $devolvidos = emprestimos_repo()->listar(['situacao' => 'devolvidos', 'aluno' => $id, 'ordem' => 'retirada', 'dir' => 'desc'], $hoje, 1, 10);
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o aluno', alunos_erro_tecnico($e, 'detalhes do aluno'));
    exit;
}
if (!$aluno) {
    http_response_code(404);
    admin_pagina_erro('Aluno não encontrado', 'O cadastro solicitado não existe. Volte à lista de alunos e pesquise pela matrícula.', 'graduation-cap');
    exit;
}
$ativo = (bool)$aluno['ativo'];

admin_page_start('alunos', $aluno['nome'], 'Alunos', 'Cadastro dos alunos da biblioteca', ALUNOS_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Alunos', admin_url('alunos')], ['Detalhes do aluno']]) ?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Detalhes do aluno</p>
        <h1><?= e($aluno['nome']) ?></h1>
        <p>Matrícula <span class="adm-mono"><?= e($aluno['matricula']) ?></span><?= $aluno['turma'] ? ' · Turma ' . e($aluno['turma']) : '' ?> · <?= alunos_badge_situacao($ativo) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('alunos')) ?>"><?= icon('arrow-left') ?> Alunos</a>
        <?php if (auth_pode('alunos.editar')): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('aluno-editar') . '?id=' . $id) ?>"><?= icon('pencil') ?> Editar cadastro</a><?php endif; ?>
        <?php if ($ativo && auth_pode('emprestimos.registrar')): ?><a class="adm-button adm-button--primary" href="<?= e(admin_url('novo-emprestimo') . '?aluno=' . $id) ?>"><?= icon('plus') ?> Novo empréstimo</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if (!$ativo): ?>
    <?= admin_aviso('aviso', 'Cadastro inativo.', 'Este aluno não pode fazer novos empréstimos. O histórico continua disponível e os empréstimos em aberto podem ser devolvidos.') ?>
<?php endif; ?>

<div class="adm-columns">
    <div class="adm-columns__main">
    <section class="adm-card adm-panel" aria-labelledby="titulo-dados">
        <div class="adm-panel__header"><div><h2 id="titulo-dados">Dados cadastrais</h2><p>Só os dados que a biblioteca usa nos empréstimos.</p></div></div>
        <dl class="adm-meta adm-meta--two">
            <div><dt>Nome</dt><dd><?= e($aluno['nome']) ?></dd></div>
            <div><dt>Matrícula</dt><dd class="adm-mono"><?= e($aluno['matricula']) ?></dd></div>
            <div><dt>E-mail institucional</dt><dd><?= $aluno['email'] ? e($aluno['email']) : '—' ?></dd></div>
            <div><dt>Turma</dt><dd><?= $aluno['turma'] ? e($aluno['turma']) : '—' ?></dd></div>
            <div><dt>Turno</dt><dd><?= e(alunos_turno($aluno['turno'])) ?></dd></div>
            <div><dt>Situação cadastral</dt><dd><?= alunos_badge_situacao($ativo) ?></dd></div>
            <div><dt>Cadastrado em</dt><dd><?= admin_data_hora($aluno['criado_em']) ?></dd></div>
            <div><dt>Última atualização</dt><dd><?= admin_data_hora($aluno['atualizado_em']) ?></dd></div>
        </dl>
    </section>
    </div>

    <aside class="adm-columns__side">
    <?php if (auth_pode('alunos.inativar')): ?>
        <section class="adm-card adm-panel" aria-labelledby="titulo-situacao">
            <div class="adm-panel__header"><div><h2 id="titulo-situacao"><?= $ativo ? 'Inativar cadastro' : 'Reativar cadastro' ?></h2>
                <p><?= $ativo ? 'Use quando o aluno deixar a escola. Nada é excluído: o histórico de empréstimos fica preservado.' : 'O aluno volta a poder fazer empréstimos.' ?></p></div></div>
            <form method="post" action="<?= e(admin_url('aluno')) ?>" class="adm-form-section" data-confirm="<?= $ativo ? 'Inativar este cadastro? O aluno não poderá fazer novos empréstimos.' : 'Reativar este cadastro?' ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="acao" value="<?= $ativo ? 'inativar' : 'reativar' ?>">
                <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-optional">(opcional)</span></span>
                    <input class="adm-input" type="text" name="motivo" maxlength="200" placeholder="<?= $ativo ? 'Ex.: transferido de escola' : 'Ex.: voltou a estudar na escola' ?>"></label>
                <?php if ($ativo && $verCirculacao && $resumo['ativos'] > 0): ?>
                    <p class="adm-field__hint">Este aluno está com <?= (int)$resumo['ativos'] ?> livro(s). Os empréstimos continuam abertos e podem ser devolvidos depois da inativação.</p>
                <?php endif; ?>
                <button class="adm-button <?= $ativo ? 'adm-button--danger' : 'adm-button--primary' ?>" type="submit"><?= $ativo ? icon('user-x') . ' Inativar cadastro' : icon('user-check') . ' Reativar cadastro' ?></button>
            </form>
        </section>
    <?php else: ?>
        <section class="adm-card adm-panel"><p class="adm-table__muted">Inativar ou reativar um cadastro é feito pela bibliotecária ou pelo administrador.</p></section>
    <?php endif; ?>
    </aside>
</div>

<?php if ($verCirculacao): ?>
    <section class="adm-stats" aria-label="Resumo dos empréstimos do aluno">
        <?php foreach ([
            ['Empréstimos ativos', $resumo['ativos'], 'Livros com o aluno agora', 'book-open-check', 'indigo'],
            ['Atrasados', $resumo['atrasados'], 'Devolução prevista já passou', 'clock-alert', $resumo['atrasados'] ? 'red' : 'green'],
            ['Devoluções', $resumo['devolvidos'], $resumo['devolvidos_com_atraso'] . ' com atraso', 'rotate-ccw', 'green'],
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
        <?= emprestimos_tabela($ativos['itens'], $hoje, ['sem_aluno', 'sem_devolucao'], 'Nenhum empréstimo ativo') ?>
    </section>

    <section class="adm-card adm-panel" aria-labelledby="titulo-atrasados">
        <div class="adm-panel__header"><div><h2 id="titulo-atrasados">Empréstimos atrasados</h2><p>A devolução prevista já passou.</p></div></div>
        <?= emprestimos_tabela($atrasados['itens'], $hoje, ['sem_aluno', 'sem_devolucao', 'com_atraso'], 'Nenhum empréstimo atrasado') ?>
    </section>

    <section class="adm-card adm-panel" aria-labelledby="titulo-devolvidos">
        <div class="adm-panel__header">
            <div><h2 id="titulo-devolvidos">Devoluções anteriores</h2><p><?= (int)$devolvidos['total'] > 10 ? 'As 10 mais recentes de ' . (int)$devolvidos['total'] . '.' : (int)$devolvidos['total'] . ' devolução(ões).' ?></p></div>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('historico-aluno') . '?id=' . $id . '&situacao=todos') ?>"><?= icon('history') ?> Histórico completo de empréstimos</a>
        </div>
        <?= emprestimos_tabela($devolvidos['itens'], $hoje, ['sem_aluno'], 'Nenhuma devolução registrada') ?>
    </section>
<?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-alteracoes">
    <div class="adm-panel__header"><div><h2 id="titulo-alteracoes">Histórico de alterações do cadastro</h2><p>Cadastros, correções, inativações e reativações feitos pelo painel. Atualizações por planilha aparecem em Atividades, como importação.</p></div></div>
    <?php if (!$alteracoes): ?>
        <p class="adm-table__muted">Nenhuma alteração registrada pelo painel.</p>
    <?php else: ?>
        <div class="adm-table-wrap adm-table-wrap--inset">
            <table class="adm-table adm-table--compact adm-table--cards">
                <thead><tr><th scope="col">Data</th><th scope="col">Ação</th><th scope="col">Detalhes</th><th scope="col">Responsável</th></tr></thead>
                <tbody>
                    <?php foreach ($alteracoes as $alt): ?>
                        <?php [$rotulo, , $tom] = ALUNOS_ACOES[$alt['acao']] ?? [$alt['acao'], 'history', 'muted'];
                        $det = json_decode((string)$alt['detalhes'], true) ?: [];
                        $partes = [];
                        if (!empty($det['campos'])) { $partes[] = 'Campos alterados: ' . $det['campos']; }
                        if (!empty($det['matricula_anterior'])) { $partes[] = 'Matrícula anterior: ' . $det['matricula_anterior']; }
                        if (!empty($det['motivo'])) { $partes[] = 'Motivo: ' . $det['motivo']; }
                        if (!empty($det['origem'])) { $partes[] = ucfirst($det['origem']); } ?>
                        <tr>
                            <td><?= admin_data_hora($alt['criado_em']) ?></td>
                            <td data-label="Ação"><span class="adm-status adm-tone--<?= e($tom) ?>"><?= e($rotulo) ?></span></td>
                            <td data-label="Detalhes" class="adm-td-wrap"><?= $partes ? e(implode(' · ', $partes)) : '—' ?></td>
                            <td data-label="Responsável"><?= e($alt['responsavel'] ?? 'Sistema') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
admin_page_end();
