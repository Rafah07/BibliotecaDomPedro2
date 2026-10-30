<?php
/**
 * Renovar um empréstimo em aberto, conforme as regras configuradas (EMPRESTIMO_* no .env).
 * Outra data além da padrão só para quem tem a permissão emprestimos.prazo.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

$usuario = emprestimos_iniciar('emprestimos.registrar');
$hoje = emprestimos_hoje();
$servico = emprestimos_servico();
$podePrazo = auth_pode('emprestimos.prazo');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$erro = null;
$observacao = '';

try {
    $m = emprestimos_repo()->buscar($id, $hoje);
    $nova = $m ? $servico->previstaRenovacao($m['data_prevista']) : '';
    if ($m && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        $observacao = mb_substr(admin_param($_POST, 'observacao'), 0, 500);
        if ($podePrazo && admin_param($_POST, 'data_prevista') !== '') {
            $nova = admin_param($_POST, 'data_prevista');
        }
        if (admin_param($_POST, 'confirmar') !== '1') {
            $erro = 'Marque a confirmação para renovar.';
        } else {
            try {
                $r = $servico->renovar($id, $nova, $usuario['id'], $observacao);
                admin_flash('sucesso', 'Empréstimo renovado.', 'Nova devolução prevista para ' . emprestimos_data($r['prevista_nova']) . ' (renovação nº ' . $r['renovacoes'] . ').');
                admin_redirecionar(admin_url('emprestimo') . '?id=' . $id);
            } catch (Biblioteca\Services\CirculacaoException $e) {
                $erro = $e->getMessage();
                $m = emprestimos_repo()->buscar($id, $hoje);
            }
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível renovar', emprestimos_erro_tecnico($e, 'renovação'));
    exit;
}
if (!$m) {
    http_response_code(404);
    admin_pagina_erro('Empréstimo não encontrado', 'O empréstimo solicitado não existe.', 'book-x');
    exit;
}
$impedimento = $servico->impedimentoRenovacao($m);
$limite = emprestimos_regras()->maxRenovacoes;

admin_page_start('emprestimos', 'Renovar empréstimo', 'Empréstimos', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Renovar empréstimo']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Renovar empréstimo</h1>
        <p>Empréstimo nº <?= $id ?> · <?= e($m['titulo']) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('emprestimo') . '?id=' . $id) ?>"><?= icon('arrow-left') ?> Voltar</a>
    </div>
</section>

<?php if ($erro): ?><?= admin_aviso('erro', 'O empréstimo não foi renovado.', $erro) ?><?php endif; ?>

<div class="adm-form-layout">
    <div class="adm-form-layout__main">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><span class="adm-step">1</span><div><h2>Empréstimo atual</h2></div></div>
            <?= emprestimos_resumo_html($m, $hoje) ?>
        </section>
    </div>
    <aside class="adm-form-layout__side">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><span class="adm-step">2</span><div><h2>Nova data de devolução</h2><p><?= e(emprestimos_regras_texto()) ?></p></div></div>
            <?php if ($impedimento): ?>
                <?= admin_aviso('erro', 'Este empréstimo não pode ser renovado.', $impedimento) ?>
            <?php else: ?>
                <form method="post" class="adm-confirm-form" data-loading-form>
                    <?= csrf_campo() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <p>Renovação nº <?= (int)$m['renovacoes'] + 1 ?><?= $limite !== null ? ' de no máximo ' . $limite : '' ?>.</p>
                    <label class="adm-field">
                        <span class="adm-field__label">Nova devolução prevista</span>
                        <?php if ($podePrazo): ?>
                            <input class="adm-input" type="date" name="data_prevista" value="<?= e($nova) ?>" min="<?= e(max($hoje, date('Y-m-d', strtotime($m['data_prevista'] . ' +1 day')))) ?>" required>
                            <span class="adm-field__hint">Padrão: <?= e(emprestimos_data($servico->previstaRenovacao($m['data_prevista']))) ?>.</span>
                        <?php else: ?>
                            <input class="adm-input" type="text" value="<?= e(emprestimos_data($nova)) ?>" readonly>
                        <?php endif; ?>
                    </label>
                    <label class="adm-field">
                        <span class="adm-field__label">Observação <span class="adm-optional">(opcional)</span></span>
                        <textarea class="adm-textarea" name="observacao" rows="2" maxlength="500"><?= e($observacao) ?></textarea>
                    </label>
                    <label class="adm-check-confirm">
                        <input type="checkbox" name="confirmar" value="1" required>
                        <span>Confirmo a renovação até a data indicada.</span>
                    </label>
                    <button type="submit" class="adm-button adm-button--primary adm-button--block"><?= icon('refresh-cw') ?> Renovar empréstimo</button>
                </form>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php
admin_page_end();
