<?php
/**
 * Arquivar ou restaurar um livro, com confirmação.
 *
 * Livros nunca são excluídos fisicamente pelo painel: o histórico (planilhas importadas,
 * classificação CDD, empréstimos, inventário) depende deles. Arquivar tira o livro do acervo,
 * do catálogo público e das filas de trabalho, e pode ser desfeito.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/livros.php';

auth_exigir('livros.arquivar');
if (!livros_persistem()) {
    admin_pagina_erro('Disponível só com o banco de dados', 'No modo demonstrativo os livros são fictícios e o arquivamento é apenas simulado na listagem.', 'archive');
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$livro = livros_repo()->buscar($id);
if (!$livro) {
    http_response_code(404);
    admin_pagina_erro('Livro não encontrado', 'O cadastro solicitado não existe.', 'book-x');
    exit;
}
$arquivado = $livro['arquivado_em'] !== null;
$acao = $arquivado ? 'restaurar' : 'arquivar';
$erro = null;
$motivo = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $motivo = mb_substr(admin_param($_POST, 'motivo'), 0, 500);
    if (admin_param($_POST, 'acao') !== $acao) {
        $erro = $arquivado ? 'Este livro já está arquivado.' : 'Este livro já está ativo.';
    } elseif (mb_strlen($motivo) < 5) {
        $erro = 'Explique o motivo (pelo menos 5 caracteres). Ele fica registrado no histórico.';
    } elseif (admin_param($_POST, 'confirmar') !== '1') {
        $erro = 'Marque a confirmação para continuar.';
    } else {
        try {
            if ($acao === 'arquivar') {
                $vinculos = livros_repo()->arquivar($id);
                registrar_acao('livro.arquivar', 'livro', $id, ['titulo' => $livro['titulo'], 'motivo' => $motivo, 'vinculos' => $vinculos]);
                admin_flash('sucesso', 'Livro arquivado.', '"' . $livro['titulo'] . '" saiu do acervo e do catálogo público. Ele pode ser restaurado em Acervo → Ver arquivados.');
                admin_redirecionar(admin_url('acervo'));
            }
            livros_repo()->restaurar($id);
            registrar_acao('livro.restaurar', 'livro', $id, ['titulo' => $livro['titulo'], 'motivo' => $motivo]);
            admin_flash('sucesso', 'Livro restaurado.', '"' . $livro['titulo'] . '" voltou ao acervo.');
            admin_redirecionar(admin_url('detalhes-livro') . '?id=' . $id);
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                error_log('[biblioteca] Erro ao ' . $acao . ' livro ' . $id . ': ' . $e->getMessage());
                $erro = 'Não foi possível concluir a operação. Tente novamente.';
            } else {
                $erro = $e->getMessage();
            }
        }
    }
}

$v = livros_repo()->vinculos($id);
$bloqueios = [];
if (!$arquivado && $v['emprestimos_abertos'] > 0) {
    $bloqueios[] = $v['emprestimos_abertos'] . ' empréstimo(s) em aberto. Registre a devolução antes.';
}
if (!$arquivado && $v['exemplares_ativos'] > 0) {
    $bloqueios[] = $v['exemplares_ativos'] . ' exemplar(es) físico(s) em uso no acervo. Dê baixa nos exemplares antes de arquivar o cadastro.';
}
$itens = [
    ['Exemplares físicos', $v['exemplares_total'], $v['exemplares_ativos'] . ' em uso no acervo'],
    ['Empréstimos', $v['emprestimos_total'], $v['emprestimos_abertos'] . ' em aberto'],
    ['Registros de planilha (dados originais)', $v['registros_legados'], 'preservados'],
    ['Linhas de importação ligadas', $v['linhas_importacao'], 'preservadas'],
    ['Classificação CDD', $v['classificacao_cdd'], $v['historico_cdd'] . ' registro(s) no histórico'],
    ['Conferências de inventário', $v['conferencias'], 'preservadas'],
];

admin_page_start('acervo', $arquivado ? 'Restaurar livro' : 'Arquivar livro', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], [$arquivado ? 'Restaurar livro' : 'Arquivar livro']]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= $arquivado ? 'Restaurar livro arquivado' : 'Arquivar livro' ?></h1>
        <p><strong><?= e($livro['titulo']) ?></strong><?= $livro['autores'] ? ' · ' . e(implode(', ', $livro['autores'])) : '' ?><?= $livro['isbn'] ? ' · ISBN ' . e($livro['isbn']) : '' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e($arquivado ? admin_url_filtros(admin_url('acervo'), ['arquivados' => '1']) : admin_url('detalhes-livro') . '?id=' . $id) ?>"><?= icon('arrow-left') ?> Voltar</a>
    </div>
</section>

<?php if ($erro): ?><?= admin_aviso('erro', $erro) ?><?php endif; ?>

<div class="adm-form-layout">
    <div class="adm-form-layout__main">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header">
                <div>
                    <h2>O que está ligado a este livro</h2>
                    <p>Nada disso é apagado. <?= $arquivado ? 'Ao restaurar, o livro volta ao acervo com todo o histórico.' : 'Arquivar apenas retira o livro do acervo ativo e do catálogo público.' ?></p>
                </div>
            </div>
            <div class="adm-table-wrap adm-table-wrap--inset">
                <table class="adm-table adm-table--compact">
                    <tbody>
                        <?php foreach ($itens as [$rotulo, $total, $obs]): ?>
                            <tr><th scope="row"><?= e($rotulo) ?></th><td class="adm-num"><strong><?= (int)$total ?></strong></td><td class="adm-table__muted"><?= e($obs) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <aside class="adm-form-layout__side">
        <section class="adm-card adm-panel adm-form-section">
            <?php if ($bloqueios): ?>
                <div class="adm-notice adm-notice--red" role="alert">
                    <?= icon('ban') ?>
                    <div class="adm-notice__content">
                        <strong>Este livro não pode ser arquivado agora.</strong>
                        <ul><?php foreach ($bloqueios as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
                    </div>
                </div>
            <?php else: ?>
                <form method="post" class="adm-confirm-form">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="acao" value="<?= $acao ?>">
                    <label class="adm-field">
                        <span class="adm-field__label">Motivo <span class="adm-required" aria-hidden="true">*</span><span class="adm-visually-hidden">(obrigatório)</span></span>
                        <textarea class="adm-textarea" name="motivo" rows="3" maxlength="500" required placeholder="<?= $arquivado ? 'Ex.: arquivado por engano' : 'Ex.: cadastro duplicado do livro nº 123' ?>"><?= e($motivo) ?></textarea>
                        <span class="adm-field__hint">Fica registrado no histórico de ações, com seu nome e a data.</span>
                    </label>
                    <label class="adm-check-confirm">
                        <input type="checkbox" name="confirmar" value="1" required>
                        <span><?= $arquivado ? 'Confirmo que este livro deve voltar ao acervo.' : 'Confirmo que este livro deve sair do acervo ativo e do catálogo público.' ?></span>
                    </label>
                    <button type="submit" class="adm-button <?= $arquivado ? 'adm-button--primary' : 'adm-button--danger' ?> adm-button--block">
                        <?= icon($arquivado ? 'archive-restore' : 'archive') ?> <?= $arquivado ? 'Restaurar livro' : 'Arquivar livro' ?>
                    </button>
                </form>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php
admin_page_end();
