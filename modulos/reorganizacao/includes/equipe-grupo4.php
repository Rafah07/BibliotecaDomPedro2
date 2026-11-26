<?php
/** Área de trabalho do Grupo 4 (conferência e organização). O código BDP é digitado à mão e validado no servidor. */
$et = $r->etapas();
$prateleiras = $et->prateleiras();
$aguardando = $et->aguardandoOrganizacao((int)$lote['id']);
?>
<div class="reorg-g4">
    <div class="adm-card adm-panel">
        <h2>Conferir código BDP</h2>
        <form class="reorg-linha" data-reorg-conferir novalidate>
            <label class="adm-field reorg-codigo-campo"><span class="adm-field__label">Código escrito no livro</span>
                <input class="adm-input reorg-input-grande adm-mono" name="codigo" placeholder="BDP-000123" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20" <?= $etapaPausada ? 'disabled' : 'autofocus' ?>></label>
            <button class="adm-button adm-button--primary" type="submit" <?= $etapaPausada ? 'disabled' : '' ?>><?= icon('search') ?> Verificar</button>
        </form>
        <div class="reorg-resultado" data-reorg-resultado hidden></div>
        <template data-reorg-tpl="confirmar">
            <div class="reorg-checks">
                <label class="adm-check"><input type="checkbox" name="codigo_ok" value="1"> Código BDP conferido</label>
                <label class="adm-check"><input type="checkbox" name="info_ok" value="1"> Informações do exemplar conferidas</label>
                <label class="adm-check"><input type="checkbox" name="organizado" value="1"> Exemplar organizado na estante</label>
                <?php if ($prateleiras): ?>
                <label class="adm-field"><span class="adm-field__label">Prateleira (opcional)</span>
                    <select class="adm-select" name="localizacao_id"><option value="">— sem prateleira cadastrada —</option><?= reorg_opcoes($prateleiras, null, 'id', 'rotulo') ?></select></label>
                <?php endif; ?>
            </div>
        </template>
        <span hidden data-reorg-tem-prateleiras="<?= $prateleiras ? '1' : '0' ?>"></span>
    </div>
    <div class="adm-card adm-panel">
        <h2>Conferidos aguardando organização <span class="adm-tabs__count"><?= count($aguardando) ?></span></h2>
        <?php if (!$aguardando): ?><p class="adm-table__muted">Nenhum.</p><?php endif; ?>
        <ul class="reorg-registros" data-reorg-lista>
            <?php foreach ($aguardando as $x): ?>
                <li data-reorg-item><div><strong class="adm-mono"><?= e($x['codigo_individual']) ?></strong> · <?= e($x['titulo']) ?><br><small>Conferido por <?= e($x['conferido_nome']) ?></small></div>
                    <div class="reorg-acoes">
                        <?php if ($prateleiras): ?><select class="adm-select adm-select--sm" data-reorg-prateleira="<?= (int)$x['id'] ?>" aria-label="Prateleira"><option value="">— sem prateleira —</option><?= reorg_opcoes($prateleiras, null, 'id', 'rotulo') ?></select><?php endif; ?>
                        <button type="button" class="adm-button adm-button--sm adm-button--primary" data-reorg-organizar="<?= (int)$x['id'] ?>" <?= $etapaPausada ? 'disabled' : '' ?>>Organizado na estante</button></div></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
