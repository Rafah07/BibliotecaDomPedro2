<?php
/**
 * Campos do EXEMPLAR físico no Inventário ("+ Registrar exemplar" e "Cadastrar livro não listado").
 * Espera: $rx (valores: codigo_bdp, estado_conservacao, localizacao_id, observacao, situacao_inventario, item_id),
 *         $locais (localizações ativas), $rxLivroId (obra, para a reserva do código; 0 = obra nova),
 *         $rxRegistros (linhas da planilha desta obra ainda sem exemplar; [] = nenhuma),
 *         $rxOrigem ('registrar' ou 'nao_listado': para onde volta o "Gerar código BDP" sem JavaScript).
 * O formulário que inclui este arquivo cuida do <form>, do CSRF e dos botões de salvar.
 *
 * O botão "Gerar código BDP" pede o código ao SERVIDOR (api/inventario/gerar-codigo.php), que
 * reserva o próximo número da sequência para quem clicou. Sem JavaScript, o botão envia o
 * formulário (acao=gerar_codigo) e a página volta com o código preenchido.
 */
$rx = ($rx ?? []) + ['codigo_bdp' => '', 'estado_conservacao' => 'bom', 'localizacao_id' => '', 'observacao' => '', 'situacao_inventario' => 'encontrado', 'item_id' => null];
$rxRegistros = $rxRegistros ?? [];
$rxLivroId = (int)($rxLivroId ?? 0);
$rxOrigem = $rxOrigem ?? 'registrar';
$rxId = 'rx-' . $rxOrigem;
$rxItem = $rx['item_id'] ?? null;
if ($rxItem === null && $rxRegistros) {
    $rxItem = (string)$rxRegistros[0]['id']; // sugestão: a primeira linha da planilha ainda pendente
}
?>
<fieldset class="adm-fieldset adm-form-section">
    <legend class="adm-field__label">Exemplar físico</legend>
    <div class="adm-form-grid">
        <div class="adm-field adm-field--full adm-codigo-bdp" data-codigo-bdp>
            <label class="adm-field__label" for="<?= $rxId ?>-codigo">Código BDP <span class="adm-required" aria-hidden="true">*</span></label>
            <div class="adm-codigo-bdp__linha">
                <input class="adm-input adm-input--code" id="<?= $rxId ?>-codigo" name="codigo_bdp" maxlength="30" value="<?= e((string)$rx['codigo_bdp']) ?>" placeholder="BDP-000123"
                       autocapitalize="characters" spellcheck="false" autocomplete="off" aria-describedby="<?= $rxId ?>-codigo-dica <?= $rxId ?>-codigo-status" data-codigo-campo>
                <button class="adm-button adm-button--ghost" type="submit" name="acao" value="gerar_codigo" formnovalidate
                        data-gerar-codigo="<?= e(admin_asset('api/inventario/gerar-codigo.php')) ?>" data-livro="<?= $rxLivroId ?>"><?= icon('sparkles') ?> Gerar código BDP</button>
            </div>
            <input type="hidden" name="origem_form" value="<?= e($rxOrigem) ?>">
            <p class="adm-field__hint" id="<?= $rxId ?>-codigo-dica">Livro já carimbado? Digite o código do carimbo. Sem carimbo? Clique em <strong>Gerar código BDP</strong>: o sistema reserva o próximo número só para você. Escreva-o no carimbo do livro e depois salve. O código fica definitivo ao salvar e nunca é repetido.</p>
            <p class="adm-codigo-bdp__status" id="<?= $rxId ?>-codigo-status" role="status" aria-live="polite" data-codigo-status><?= !empty($rx['codigo_gerado']) ? 'Código reservado para você: <strong>' . e((string)$rx['codigo_bdp']) . '</strong>. Escreva-o no carimbo e salve.' : '' ?></p>
        </div>
        <fieldset class="adm-field adm-field--full adm-escolha">
            <legend class="adm-field__sublabel">Situação no inventário</legend>
            <?php foreach (Biblioteca\Services\InventarioService::SITUACOES_REGISTRO as $valor => $rotulo): ?>
                <label class="adm-escolha__opcao"><input type="radio" name="situacao_inventario" value="<?= $valor ?>"<?= $rx['situacao_inventario'] === $valor ? ' checked' : '' ?>>
                    <span><strong><?= e($rotulo) ?></strong> <small><?= $valor === 'encontrado' ? 'o livro está em mãos agora' : 'registrar agora e conferir depois' ?></small></span></label>
            <?php endforeach; ?>
        </fieldset>
        <label class="adm-field"><span class="adm-field__sublabel">Estado de conservação</span><?= fisico_select_conservacao('estado_conservacao', (string)$rx['estado_conservacao']) ?></label>
        <label class="adm-field"><span class="adm-field__sublabel">Localização física (setor, estante, prateleira)</span><?= fisico_select_local('localizacao_id', (string)$rx['localizacao_id'] !== '' ? (int)$rx['localizacao_id'] : null, 'Não informar agora', '', $locais) ?></label>
        <?php if ($rxRegistros): ?>
            <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Este exemplar corresponde a qual linha da planilha?</span>
                <select class="adm-select" name="item_id">
                    <?php foreach ($rxRegistros as $reg): ?>
                        <option value="<?= (int)$reg['id'] ?>"<?= (string)$rxItem === (string)$reg['id'] ? ' selected' : '' ?>><?= $reg['origem_linha'] ? 'Linha ' . (int)$reg['origem_linha'] . ' da planilha' : 'Registro importado sem código' ?><?= $reg['localizacao_antiga'] ? ' · local antigo ' . e($reg['localizacao_antiga']) : '' ?><?= $reg['nao_localizado_em'] ? ' · estava como não localizado' : '' ?></option>
                    <?php endforeach; ?>
                    <option value=""<?= (string)$rxItem === '' ? ' selected' : '' ?>>Nenhuma: é um exemplar a mais desta obra</option>
                </select>
                <span class="adm-field__hint">A linha escolhida deixa de ficar “sem exemplar” e continua ligada à planilha original. Nada é unido automaticamente.</span></label>
        <?php endif; ?>
        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Observações <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" value="<?= e((string)$rx['observacao']) ?>" placeholder="Ex.: capa solta, dedicatória na folha de rosto"></label>
    </div>
</fieldset>
