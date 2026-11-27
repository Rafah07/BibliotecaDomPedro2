<?php
/**
 * Área de trabalho do Grupo 4 (conferência e organização). O código BDP é digitado à mão no campo
 * grande e validado no servidor (sem câmera e sem leitor de código de barras). A localização e as
 * regras de empréstimo continuam as do sistema permanente.
 * Variáveis: $r, $sessao, $lote, $c, $base, $etapaPausada.
 */
$et = $r->etapas();
$prateleiras = $et->prateleiras();
$aguardando = $et->aguardandoOrganizacao((int)$lote['id']);
?>
<section class="adm-card reorg-g1-topo">
    <div class="reorg-g1-topo__lote">
        <h1><?= e($lote['nome']) ?> · Conferência e organização</h1>
        <dl class="reorg-numeros reorg-numeros--linha">
            <div><dt>Aguardando conferência</dt><dd><?= $c['aguardando_conferencia'] ?></dd></div>
            <div><dt>Conferidos</dt><dd><?= $c['conferidos'] ?></dd></div>
            <div><dt>Organizados</dt><dd><?= $c['organizados'] ?></dd></div>
            <div><dt>Prevista no lote</dt><dd><?= $c['prevista'] ?></dd></div>
        </dl>
        <?= reorg_barra($c['organizados'], $c['prevista'], $c['organizados'] . ' de ' . $c['prevista'] . ' organizados') ?>
    </div>
</section>
<div class="reorg-g4">
    <section class="adm-card adm-panel reorg-g4__conferir">
        <h2>Digite o código BDP escrito no livro</h2>
        <form class="reorg-g4__form" data-reorg-conferir novalidate>
            <label class="adm-field reorg-codigo-campo"><span class="adm-sr-only">Código BDP</span>
                <input class="adm-input reorg-input-codigo adm-mono" name="codigo" placeholder="BDP-000123" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20" inputmode="text" <?= $etapaPausada ? 'disabled' : 'autofocus' ?>></label>
            <button class="adm-button adm-button--primary reorg-g4__botao" type="submit" <?= $etapaPausada ? 'disabled' : '' ?>><?= icon('search') ?> Verificar</button>
        </form>
        <p class="adm-field__hint">Digite exatamente o que está escrito no livro e tecle Enter. O sistema confere se o código existe, se é deste lote e se já foi carimbado.</p>
        <div class="reorg-resultado" data-reorg-resultado hidden aria-live="polite"></div>
        <template data-reorg-tpl="confirmar">
            <div class="reorg-checks">
                <label class="adm-check"><input type="checkbox" name="codigo_ok" value="1"> O código escrito no livro é este</label>
                <label class="adm-check"><input type="checkbox" name="info_ok" value="1"> Título, autor, editora e edição conferem com o livro</label>
                <label class="adm-check"><input type="checkbox" name="organizado" value="1"> Já guardei o exemplar na estante</label>
                <?php if ($prateleiras): ?>
                <label class="adm-field"><span class="adm-field__label">Prateleira (opcional)</span>
                    <select class="adm-select" name="localizacao_id"><option value="">— sem prateleira cadastrada —</option><?= reorg_opcoes($prateleiras, null, 'id', 'rotulo') ?></select></label>
                <?php endif; ?>
            </div>
        </template>
    </section>
    <section class="adm-card adm-panel">
        <h2>Conferidos aguardando a estante <span class="adm-tabs__count"><?= count($aguardando) ?></span></h2>
        <?php if (!$aguardando): ?><p class="adm-table__muted">Nenhum.</p><?php endif; ?>
        <ul class="reorg-registros" data-reorg-lista>
            <?php foreach ($aguardando as $x): ?>
                <li data-reorg-item><div><strong class="reorg-codigo"><?= e($x['codigo_individual']) ?></strong> · <?= e($x['titulo']) ?><br><small>Conferido por <?= e($x['conferido_nome']) ?></small></div>
                    <div class="reorg-acoes">
                        <?php if ($prateleiras): ?><select class="adm-select adm-select--sm" data-reorg-prateleira="<?= (int)$x['id'] ?>" aria-label="Prateleira de <?= e($x['codigo_individual']) ?>"><option value="">— sem prateleira —</option><?= reorg_opcoes($prateleiras, null, 'id', 'rotulo') ?></select><?php endif; ?>
                        <button type="button" class="adm-button adm-button--sm adm-button--primary" data-reorg-organizar="<?= (int)$x['id'] ?>" <?= $etapaPausada ? 'disabled' : '' ?>>Guardado na estante</button></div></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
