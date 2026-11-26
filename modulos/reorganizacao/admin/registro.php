<?php
/** Revisão de um registro pelo Grupo 2: corrigir (salvamento automático), comparar, vincular obra, aprovar, devolver, cadastrar. */
require __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../../admin/includes/cdd.php';

use Biblioteca\Reorganizacao\Levantamento;
use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();
$lev = $r->levantamento();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$atual = $lev->registro($id);
if (!$atual) {
    http_response_code(404);
    admin_pagina_erro('Registro não encontrado', 'Confira o endereço ou volte ao lote.');
    exit;
}
$aqui = reorg_url('admin/registro.php?id=' . $id);
$loteUrl = reorg_url('admin/lote.php?id=' . $atual['lote_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    reorg_admin_acao(function () use ($acao, $lev, $id, $adminId, $usuario, $atual) {
        switch ($acao) {
            case 'aprovar': $lev->aprovar($adminId, $id, ($_POST['confirmar_nova'] ?? '') === '1'); return 'Registro nº ' . $atual['numero'] . ' aprovado.';
            case 'devolver': $lev->devolver($adminId, $id, (string)($_POST['motivo'] ?? '')); return 'Registro devolvido ao Grupo 1 para correção (com notificação).';
            case 'descartar': $lev->descartar($id, null, $adminId, (string)($_POST['motivo'] ?? '')); return 'Registro descartado (a linha fica guardada no histórico).';
            case 'obra': $lev->sugerirObra($id, (int)($_POST['livro_id'] ?? 0) ?: null, null, $adminId); return ((int)($_POST['livro_id'] ?? 0)) ? 'Registro vinculado à obra existente.' : 'Vínculo retirado: será cadastrado como obra nova.';
            case 'cadastrar':
                $c = $lev->cadastrar($adminId, $id);
                $msg = 'Cadastrado: ' . implode(', ', $c['codigos']) . ($c['obra_nova'] ? ' (obra nova)' : ' (obra existente)') . '.';
                if (!$c['repetido'] && $c['cdd']) {
                    try {
                        cdd_corrigir_na_conferencia((int)$c['livro_id'], (string)$c['cdd'], (string)($c['cdd_fonte'] ?? ''), $usuario, 'reorganização do acervo');
                    } catch (Throwable $e) {
                        $msg .= ' A CDD não foi gravada: ' . $e->getMessage();
                    }
                }
                return $msg;
        }
        throw new Biblioteca\Services\AcervoException('Ação desconhecida.');
    }, 'Feito.', $aqui);
}

$reg = $lev->abrir($id, null, $adminId);
$somenteLeitura = $reg['somente_leitura'];
$categorias = db_query('SELECT id, nome FROM categorias WHERE arquivado_em IS NULL ORDER BY nome')->fetchAll();
$dif = $lev->diferencas($reg);
$orig = $reg['dados_originais'] ? json_decode((string)$reg['dados_originais'], true) : null;
$problemas = in_array($reg['status'], ['aguardando_revisao'], true) ? $lev->problemasAprovacao($reg) : [];
[$st, $tom] = Levantamento::STATUS[$reg['status']];
$rotulos = ['titulo' => 'Título', 'subtitulo' => 'Subtítulo', 'autores' => 'Autores', 'autor_nao_indicado' => 'Autor não indicado', 'editora' => 'Editora', 'editora_nao_indicada' => 'Editora não indicada',
    'isbn' => 'ISBN', 'isbn_nao_encontrado' => 'ISBN não encontrado', 'cdd' => 'CDD', 'cdd_nao_encontrada' => 'CDD não encontrada', 'cdd_fonte' => 'Fonte da CDD', 'ano_publicacao' => 'Ano',
    'edicao' => 'Edição', 'idioma' => 'Idioma', 'categoria_id' => 'Categoria', 'assunto' => 'Assunto', 'quantidade' => 'Quantidade', 'observacoes' => 'Observações', 'estados' => 'Conservação'];

reorg_admin_inicio('inicio', 'Registro nº ' . $reg['numero'], $reg['lote_nome']);
?>
<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow"><a href="<?= e($loteUrl) ?>#revisao"><?= icon('arrow-left') ?> <?= e($reg['lote_nome']) ?></a></p>
        <h1>Registro nº <?= (int)$reg['numero'] ?> <?= reorg_status_badge($st, $tom) ?></h1>
        <p>Levantamento: <?= e($reg['criado_nome'] ?? '—') ?> · Última alteração: <?= e(($reg['atualizado_nome'] ?? 'administração') . ' em ' . admin_data_hora($reg['atualizado_em'])) ?></p>
    </div>
    <div class="adm-page-head__actions"><span class="reorg-indicador" data-reorg-indicador><?= $somenteLeitura ? 'Somente consulta' : 'Todas as alterações salvas' ?></span></div>
</section>

<?php if ($somenteLeitura && $reg['motivo_leitura']): ?><?= admin_aviso('aviso', $reg['motivo_leitura']) ?><?php endif; ?>
<?php if ($reg['correcao_motivo'] && $reg['status'] === 'correcao'): ?><?= admin_aviso('aviso', 'Devolvido para correção', $reg['correcao_motivo']) ?><?php endif; ?>
<?php if ($problemas): ?><div class="adm-notice adm-notice--amber"><?= icon('triangle-alert') ?><div><strong>Antes de aprovar:</strong><ul><?php foreach ($problemas as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>

<div class="reorg-revisao">
    <section class="adm-card adm-panel">
        <h2>Dados (correção administrativa)</h2>
        <?php include __DIR__ . '/../includes/form-registro.php'; ?>
    </section>
    <aside>
        <section class="adm-card adm-panel">
            <h2>Obra do acervo</h2>
            <?php if ($reg['livro_sugerido_id']): ?>
                <p>Vinculado à obra existente <strong><?= e($reg['livro_sugerido_titulo']) ?></strong> (nº <?= (int)$reg['livro_sugerido_id'] ?>)<?= $reg['livro_sugerido_isbn'] ? ' · ISBN ' . e($reg['livro_sugerido_isbn']) : '' ?><?= $reg['livro_sugerido_editora'] ? ' · ' . e($reg['livro_sugerido_editora']) : '' ?><?= $reg['livro_sugerido_edicao'] ? ' · ' . e($reg['livro_sugerido_edicao']) : '' ?>.
                    Os exemplares entram nela (sem duplicar a obra).</p>
                <?php if (!$somenteLeitura): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="obra"><input type="hidden" name="livro_id" value=""><button class="adm-button adm-button--sm" type="submit">Retirar vínculo (cadastrar como obra nova)</button></form><?php endif; ?>
            <?php else: ?>
                <p>Sem obra vinculada: será cadastrada uma <strong>obra nova</strong>.</p>
            <?php endif; ?>
            <?php if (!$somenteLeitura): ?>
            <div class="reorg-pesquisa" data-reorg-pesquisa data-acao-vincular="<?= e($aqui) ?>">
                <label class="adm-field"><span class="adm-field__label">Pesquisar obra (título, autor, ISBN, editora)</span><input class="adm-input" type="search" data-reorg-q></label>
                <ul class="reorg-resultados" data-reorg-resultados></ul>
                <form method="post" hidden data-reorg-vincular><?= csrf_campo() ?><input type="hidden" name="acao" value="obra"><input type="hidden" name="livro_id"></form>
            </div>
            <?php endif; ?>
        </section>
        <section class="adm-card adm-panel">
            <h2>Informações originais do Grupo 1</h2>
            <?php if (!$orig): ?><p class="adm-table__muted">Ainda não encaminhado (sem original guardado).</p>
            <?php elseif (!$dif): ?><p>Sem diferenças em relação ao que o Grupo 1 enviou.</p>
            <?php else: ?>
                <table class="adm-table adm-table--compact"><thead><tr><th>Campo</th><th>Original</th><th>Atual</th></tr></thead><tbody>
                <?php foreach ($dif as $campo => [$de, $para]): ?><tr><td><?= e($rotulos[$campo] ?? $campo) ?></td><td><?= e((string)($de ?? '—')) ?></td><td><strong><?= e((string)($para ?? '—')) ?></strong></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </section>
        <section class="adm-card adm-panel">
            <h2>Decisão</h2>
            <?php if ($reg['status'] === 'aguardando_revisao'): ?>
                <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="aprovar">
                    <?php if (!$reg['livro_sugerido_id'] && $reg['isbn']): ?><label class="adm-check"><input type="checkbox" name="confirmar_nova" value="1"> Confirmo que é uma obra nova (mesmo se já houver uma com este ISBN)</label><?php endif; ?>
                    <button class="adm-button adm-button--primary" type="submit" data-reorg-salvar-antes><?= icon('check') ?> Aprovar</button></form>
            <?php endif; ?>
            <?php if ($reg['status'] === 'aprovado'): ?>
                <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="cadastrar">
                    <button class="adm-button adm-button--primary" type="submit" data-reorg-confirmar="Cadastrar e gerar <?= (int)$reg['quantidade'] ?> código(s) BDP na numeração única do sistema?"><?= icon('stamp') ?> Cadastrar e gerar códigos BDP</button></form>
            <?php endif; ?>
            <?php if ($reg['status'] === 'cadastrado'): ?>
                <p>Cadastrado<?= $reg['livro_id'] ? ' na obra <a href="' . e(url('admin/detalhes-livro.php?id=' . $reg['livro_id'])) . '">nº ' . (int)$reg['livro_id'] . '</a>' : '' ?>. Códigos: <span class="adm-mono"><?= e(implode(', ', array_filter(array_column($reg['exemplares'], 'codigo_individual')))) ?></span></p>
            <?php endif; ?>
            <?php if (in_array($reg['status'], ['aguardando_revisao', 'aprovado'], true)): ?>
                <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="devolver">
                    <label class="adm-field"><span class="adm-field__label">Devolver ao Grupo 1 para correção</span><textarea class="adm-textarea" name="motivo" rows="2" required maxlength="500" placeholder="O que precisa ser conferido no livro"></textarea></label>
                    <button class="adm-button adm-button--sm" type="submit"><?= icon('rotate-ccw') ?> Devolver</button></form>
            <?php endif; ?>
            <?php if (!in_array($reg['status'], ['cadastrado', 'descartado'], true)): ?>
                <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="descartar">
                    <input class="adm-input" name="motivo" placeholder="Motivo do descarte (registro duplicado, engano...)" maxlength="500">
                    <button class="adm-button adm-button--sm adm-button--danger-text" type="submit" data-reorg-confirmar="Descartar este registro? Ele deixa de contar no lote, mas fica guardado.">Descartar registro</button></form>
            <?php endif; ?>
        </section>
    </aside>
</div>
<script>window.REORG_REGISTRO = {modo: 'admin', id: <?= (int)$reg['id'] ?>, somenteLeitura: <?= $somenteLeitura ? 'true' : 'false' ?>, max: <?= (int)reorg_config('max_exemplares_registro') ?>};</script>
<script src="<?= e(reorg_asset('registro.js')) ?>" defer></script>
<?php reorg_admin_fim();
