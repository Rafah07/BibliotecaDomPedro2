<?php
/**
 * Registrar devolução: mostra os dados do empréstimo, pede confirmação e grava a data real,
 * quem recebeu, a movimentação do exemplar e a ação. O empréstimo continua no histórico.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

$usuario = emprestimos_iniciar('emprestimos.registrar');
$hoje = emprestimos_hoje();
$podePrazo = auth_pode('emprestimos.prazo');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$erro = null;
$observacao = '';
$quando = emprestimos_regras()->agora()->format('Y-m-d\TH:i');
$manutencao = false;

try {
    $m = emprestimos_repo()->buscar($id, $hoje);
    if ($m && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        $observacao = mb_substr(admin_param($_POST, 'observacao'), 0, 500);
        $manutencao = admin_param($_POST, 'manutencao') === '1';
        $data = null;
        if ($podePrazo && admin_param($_POST, 'quando') !== '') {
            $quando = admin_param($_POST, 'quando');
            $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $quando);
            $data = $d ? $d->format('Y-m-d H:i:s') : 'invalida';
            // A data informada é a do minuto atual: usa a hora exata do servidor.
            if ($d && $d->format('Y-m-d H:i') === emprestimos_regras()->agora()->format('Y-m-d H:i')) {
                $data = null;
            }
        }
        if (admin_param($_POST, 'confirmar') !== '1') {
            $erro = 'Marque a confirmação para registrar a devolução.';
        } else {
            try {
                $r = emprestimos_servico()->devolver($id, $usuario['id'], $data, $manutencao, $observacao);
                $msg = $r['titulo'] . ' (' . $r['codigo'] . ') ' . ($r['situacao_exemplar'] === 'disponivel' ? 'já está disponível para novo empréstimo.' : ($r['situacao_exemplar'] === 'manutencao' ? 'foi enviado para manutenção.' : 'continua como ' . mb_strtolower(emprestimos_situacao_exemplar($r['situacao_exemplar'])) . '.'));
                if ($r['dias_atraso'] > 0) {
                    $msg .= ' Devolvido com ' . $r['dias_atraso'] . ' dia(s) de atraso.';
                }
                admin_flash('sucesso', 'Devolução registrada.', $msg);
                admin_redirecionar(admin_url('emprestimo') . '?id=' . $id);
            } catch (Biblioteca\Services\CirculacaoException $e) {
                $erro = $e->getMessage();
                $m = emprestimos_repo()->buscar($id, $hoje);
            }
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível registrar a devolução', emprestimos_erro_tecnico($e, 'devolução'));
    exit;
}
if (!$m) {
    http_response_code(404);
    admin_pagina_erro('Empréstimo não encontrado', 'O empréstimo solicitado não existe.', 'book-x');
    exit;
}

admin_page_start('devolucoes', 'Registrar devolução', 'Devoluções', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Registrar devolução']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Registrar devolução</h1>
        <p>Empréstimo nº <?= $id ?> · <?= e($m['titulo']) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('emprestimo') . '?id=' . $id) ?>"><?= icon('arrow-left') ?> Voltar</a>
    </div>
</section>

<?php if ($erro): ?><?= admin_aviso('erro', 'A devolução não foi registrada.', $erro) ?><?php endif; ?>

<div class="adm-form-layout">
    <div class="adm-form-layout__main">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><span class="adm-step">1</span><div><h2>Confira o empréstimo</h2><p>Compare o código do exemplar com a etiqueta do livro devolvido.</p></div></div>
            <?= emprestimos_resumo_html($m, $hoje) ?>
        </section>
    </div>
    <aside class="adm-form-layout__side">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header"><span class="adm-step">2</span><div><h2>Confirmar devolução</h2></div></div>
            <?php if ($m['data_devolucao'] !== null): ?>
                <?= admin_aviso('sucesso', 'Este empréstimo já foi devolvido.', 'Devolvido em ' . emprestimos_data($m['data_devolucao'], true) . ($m['recebido_por_nome'] ? ', recebido por ' . $m['recebido_por_nome'] : '') . '. Não é possível devolver de novo.') ?>
            <?php else: ?>
                <form method="post" class="adm-confirm-form" data-loading-form>
                    <?= csrf_campo() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <label class="adm-field">
                        <span class="adm-field__label">Data e hora da devolução</span>
                        <?php if ($podePrazo): ?>
                            <input class="adm-input" type="datetime-local" name="quando" value="<?= e($quando) ?>" max="<?= e(emprestimos_regras()->agora()->format('Y-m-d\TH:i')) ?>" required>
                            <span class="adm-field__hint">Agora, por padrão. Altere só se o livro voltou antes e o registro está sendo feito depois.</span>
                        <?php else: ?>
                            <input class="adm-input" type="text" value="Agora (<?= e(emprestimos_regras()->agora()->format('d/m/Y H:i')) ?>)" readonly>
                        <?php endif; ?>
                    </label>
                    <label class="adm-check-confirm">
                        <input type="checkbox" name="manutencao" value="1"<?= $manutencao ? ' checked' : '' ?>>
                        <span><strong>O exemplar precisa de reparo.</strong> Ele vai para "Em manutenção" em vez de ficar disponível.</span>
                    </label>
                    <label class="adm-field">
                        <span class="adm-field__label">Observação <span class="adm-optional">(opcional)</span></span>
                        <textarea class="adm-textarea" name="observacao" rows="2" maxlength="500" placeholder="Ex.: página rasgada"><?= e($observacao) ?></textarea>
                    </label>
                    <label class="adm-check-confirm">
                        <input type="checkbox" name="confirmar" value="1" required>
                        <span>Recebi o exemplar <strong class="adm-mono"><?= e($m['codigo_individual']) ?></strong>.</span>
                    </label>
                    <p class="adm-field__hint">Responsável pelo recebimento: <?= e($usuario['nome']) ?>.</p>
                    <button type="submit" class="adm-button adm-button--primary adm-button--block"><?= icon('rotate-ccw') ?> Registrar devolução</button>
                </form>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php
admin_page_end();
