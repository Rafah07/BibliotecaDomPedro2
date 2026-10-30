<?php
/**
 * Campos do cadastro de exemplar físico (página do livro e conferência do inventário).
 * Espera: $fx (valores atuais), $locais (localizações ativas), $livroForm (livro escolhido).
 * O formulário que inclui este arquivo cuida do <form>, do CSRF e do botão.
 */
$fx += ['quantidade' => '1', 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => '', 'nova_setor' => '', 'nova_estante' => '', 'nova_prateleira' => '', 'nova_complemento' => '', 'observacoes' => ''];
$podeNovaLocal = auth_pode('localizacoes.gerenciar');
?>
<div class="adm-form-grid">
    <label class="adm-field">
        <span class="adm-field__label">Estado de conservação <span class="adm-required" aria-hidden="true">*</span></span>
        <?= fisico_select_conservacao('estado_conservacao', $fx['estado_conservacao']) ?>
    </label>
    <label class="adm-field">
        <span class="adm-field__label">Situação inicial <span class="adm-required" aria-hidden="true">*</span></span>
        <?= fisico_select_situacao('situacao', $fx['situacao']) ?>
        <span class="adm-field__hint">Só "Disponível" aparece para empréstimo.</span>
    </label>
    <label class="adm-field adm-field--full">
        <span class="adm-field__label">Localização física</span>
        <?= fisico_select_local('localizacao_id', (int)$fx['localizacao_id'] ?: null, 'Ainda sem localização definida', '', $locais) ?>
        <span class="adm-field__hint">Escolha onde o exemplar está (ou ficará). Só este exemplar recebe a localização.</span>
    </label>
    <?php if ($podeNovaLocal): ?>
        <details class="adm-field--full adm-nova-local"<?= $fx['nova_setor'] !== '' ? ' open' : '' ?>>
            <summary><?= icon('plus') ?> Informar uma localização que ainda não está na lista</summary>
            <div class="adm-form-grid adm-form-grid--4">
                <label class="adm-field"><span class="adm-field__sublabel">Setor ou seção</span><input class="adm-input" name="nova_setor" maxlength="80" value="<?= e($fx['nova_setor']) ?>" placeholder="Literatura"></label>
                <label class="adm-field"><span class="adm-field__sublabel">Estante</span><input class="adm-input" name="nova_estante" maxlength="80" value="<?= e($fx['nova_estante']) ?>" placeholder="E03"></label>
                <label class="adm-field"><span class="adm-field__sublabel">Prateleira</span><input class="adm-input" name="nova_prateleira" maxlength="80" value="<?= e($fx['nova_prateleira']) ?>" placeholder="P02"></label>
                <label class="adm-field"><span class="adm-field__sublabel">Complemento</span><input class="adm-input" name="nova_complemento" maxlength="80" value="<?= e($fx['nova_complemento']) ?>" placeholder="opcional"></label>
            </div>
            <span class="adm-field__hint">Preenchido o setor, a localização é cadastrada (ou reaproveitada, se já existir) e usada no lugar da escolhida acima.</span>
        </details>
    <?php endif; ?>
    <label class="adm-field">
        <span class="adm-field__label">Quantidade de exemplares em mãos</span>
        <input class="adm-input" type="number" name="quantidade" min="1" max="<?= Biblioteca\Services\ExemplarService::LIMITE_POR_CADASTRO ?>" value="<?= e($fx['quantidade']) ?>" inputmode="numeric" required>
        <span class="adm-field__hint">Cada unidade recebe um código próprio. Informe só as que estão fisicamente com você.</span>
    </label>
    <label class="adm-field adm-field--full">
        <span class="adm-field__label">Observações <span class="adm-optional">(opcional)</span></span>
        <textarea class="adm-textarea" name="observacoes" rows="2" maxlength="500" placeholder="Ex.: doação recebida em 2026, capa com fita adesiva"><?= e($fx['observacoes']) ?></textarea>
    </label>
</div>
