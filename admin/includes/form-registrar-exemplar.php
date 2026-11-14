<?php
/**
 * Campos do EXEMPLAR físico no Inventário ("+ Registrar exemplar" e "Cadastrar livro não listado").
 * Espera: $rx (valores: codigo_bdp, estado_conservacao, localizacao_id, observacao, situacao_inventario, vinculo, processo_bdp),
 *         $locais (localizações ativas), $rxLivroId (obra, para a reserva do código; 0 = obra nova),
 *         $rxRegistros (TODAS as linhas da planilha desta obra, InventarioRepository::registrosDaObra; [] = nenhuma),
 *         $rxOrigem ('registrar' ou 'nao_listado': para onde volta o "Gerar código BDP" sem JavaScript).
 * O formulário que inclui este arquivo cuida do <form>, do CSRF e dos botões de salvar.
 *
 * "Gerar código BDP" pede o código ao SERVIDOR (api/inventario/gerar-codigo.php), que reserva o
 * próximo número para quem clicou E para ESTE formulário (campo processo_bdp, novo a cada
 * formulário aberto). Outra aba ou "Registrar outra cópia" recebem sempre outro número.
 * Sem JavaScript, o botão envia o formulário (acao=gerar_codigo) e a página volta com o código.
 *
 * Linha da planilha: quando a obra tem linhas importadas, a equipe escolhe CONSCIENTEMENTE qual
 * linha este exemplar representa, ou "Exemplar adicional, sem linha correspondente". Nada vem
 * escolhido sozinho; linhas já ligadas a um exemplar aparecem desativadas, com o código.
 */
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\InventarioService;

$rx = ($rx ?? []) + ['codigo_bdp' => '', 'estado_conservacao' => 'bom', 'localizacao_id' => '', 'observacao' => '', 'situacao_inventario' => 'encontrado', 'vinculo' => '', 'processo_bdp' => ''];
$rxRegistros = $rxRegistros ?? [];
$rxLivroId = (int)($rxLivroId ?? 0);
$rxOrigem = $rxOrigem ?? 'registrar';
$rxId = 'rx-' . $rxOrigem;
$rxProcesso = CodigoBdpService::processoValido((string)$rx['processo_bdp']) ?? CodigoBdpService::novoProcesso();
$rxLivres = array_values(array_filter($rxRegistros, fn ($r) => $r['exemplar_id'] === null));
$rxVinculo = (string)$rx['vinculo'];
?>
<fieldset class="adm-fieldset adm-form-section">
    <legend class="adm-field__label">Exemplar físico</legend>
    <input type="hidden" name="processo_bdp" value="<?= e($rxProcesso) ?>">
    <input type="hidden" name="origem_form" value="<?= e($rxOrigem) ?>">
    <div class="adm-form-grid">
        <div class="adm-field adm-field--full adm-codigo-bdp" data-codigo-bdp>
            <label class="adm-field__label" for="<?= $rxId ?>-codigo">Código BDP <span class="adm-required" aria-hidden="true">*</span></label>
            <div class="adm-codigo-bdp__linha">
                <input class="adm-input adm-input--code" id="<?= $rxId ?>-codigo" name="codigo_bdp" maxlength="30" value="<?= e((string)$rx['codigo_bdp']) ?>" placeholder="BDP-000123"
                       autocapitalize="characters" spellcheck="false" autocomplete="off" aria-describedby="<?= $rxId ?>-codigo-dica <?= $rxId ?>-codigo-status" data-codigo-campo>
                <button class="adm-button adm-button--ghost" type="submit" name="acao" value="gerar_codigo" formnovalidate data-atalho="gerar"
                        data-gerar-codigo="<?= e(admin_asset('api/inventario/gerar-codigo.php')) ?>" data-livro="<?= $rxLivroId ?>"><?= icon('sparkles') ?> Gerar código BDP</button>
            </div>
            <p class="adm-field__hint" id="<?= $rxId ?>-codigo-dica">Livro já carimbado? Digite o código do carimbo. Sem carimbo? Clique em <strong>Gerar código BDP</strong>: o servidor reserva o próximo número só para este formulário. Escreva-o no carimbo e depois salve. O código só fica definitivo ao salvar e nunca é repetido.</p>
            <p class="adm-codigo-bdp__status" id="<?= $rxId ?>-codigo-status" role="status" aria-live="polite" data-codigo-status><?= !empty($rx['codigo_gerado']) ? 'Código reservado para este formulário (ainda não atribuído): <strong>' . e((string)$rx['codigo_bdp']) . '</strong>. Escreva-o no carimbo e salve.' : '' ?></p>
        </div>
        <?php if ($rxRegistros): ?>
            <fieldset class="adm-field adm-field--full adm-escolha adm-linhas-planilha" data-escolha-linha>
                <legend class="adm-field__sublabel">Este exemplar corresponde a qual linha da planilha? <span class="adm-required" aria-hidden="true">*</span></legend>
                <p class="adm-field__hint"><?= count($rxLivres) ?> linha(s) ainda sem exemplar. Compare com o livro em mãos e escolha. Nada é escolhido automaticamente.</p>
                <?php foreach ($rxRegistros as $reg): $livre = $reg['exemplar_id'] === null; $valor = 'r:' . (int)$reg['id']; ?>
                    <label class="adm-escolha__opcao adm-linha-planilha<?= $livre ? '' : ' is-ligada' ?>">
                        <input type="radio" name="vinculo" value="<?= e($valor) ?>"<?= $livre ? '' : ' disabled' ?><?= $livre && $rxVinculo === $valor ? ' checked' : '' ?> required>
                        <span>
                            <strong>Linha <?= (int)$reg['linha'] ?></strong> <small class="adm-table__muted">“<?= e($reg['arquivo']) ?>”</small>
                            <?php if ($livre): ?>
                                <span class="adm-status adm-tone--<?= $reg['nao_localizado_em'] ? 'red' : 'amber' ?>"><?= $reg['nao_localizado_em'] ? 'Sem exemplar · não localizada' : 'Sem exemplar' ?></span>
                            <?php else: ?>
                                <span class="adm-status adm-tone--green">Ligada ao <?= e((string)$reg['codigo_individual']) ?></span>
                            <?php endif; ?>
                            <small class="adm-block-muted">
                                <?= e(implode(' · ', array_filter([
                                    $reg['titulo_original'] ? 'Título: ' . mb_strimwidth($reg['titulo_original'], 0, 70, '…') : null,
                                    $reg['autor_original'] ? 'Autor: ' . mb_strimwidth($reg['autor_original'], 0, 50, '…') : null,
                                    $reg['localizacao_antiga'] ? 'Local antigo: ' . $reg['localizacao_antiga'] : null,
                                    $reg['quantidade_informada'] !== null ? 'Qtd. na planilha: ' . (int)$reg['quantidade_informada'] : null,
                                ]))) ?>
                                <?php foreach ($reg['extras'] as $k => $v): ?> · <?= e($k) ?>: <?= e($v) ?><?php endforeach; ?>
                            </small>
                        </span>
                    </label>
                <?php endforeach; ?>
                <label class="adm-escolha__opcao adm-linha-planilha adm-linha-planilha--adicional">
                    <input type="radio" name="vinculo" value="<?= InventarioService::VINCULO_ADICIONAL ?>"<?= $rxVinculo === InventarioService::VINCULO_ADICIONAL || !$rxLivres ? ' checked' : '' ?> required>
                    <span><strong>Exemplar adicional, sem linha correspondente</strong>
                        <small class="adm-block-muted">Uma cópia física além das que aparecem na planilha. As linhas continuam “sem exemplar”.</small></span>
                </label>
            </fieldset>
        <?php endif; ?>
        <fieldset class="adm-field adm-field--full adm-escolha">
            <legend class="adm-field__sublabel">Situação no inventário</legend>
            <?php foreach (InventarioService::SITUACOES_REGISTRO as $valor => $rotulo): ?>
                <label class="adm-escolha__opcao"><input type="radio" name="situacao_inventario" value="<?= $valor ?>"<?= $rx['situacao_inventario'] === $valor ? ' checked' : '' ?>>
                    <span><strong><?= e($rotulo) ?></strong> <small><?= $valor === 'encontrado' ? 'o livro está em mãos agora' : 'registrar agora e conferir depois' ?></small></span></label>
            <?php endforeach; ?>
        </fieldset>
        <label class="adm-field"><span class="adm-field__sublabel">Estado de conservação</span><?= fisico_select_conservacao('estado_conservacao', (string)$rx['estado_conservacao']) ?></label>
        <label class="adm-field"><span class="adm-field__sublabel">Localização física (setor, estante, prateleira)</span><?= fisico_select_local('localizacao_id', (string)$rx['localizacao_id'] !== '' ? (int)$rx['localizacao_id'] : null, 'Não informar agora', '', $locais) ?></label>
        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Observações deste exemplar <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" value="<?= e((string)$rx['observacao']) ?>" placeholder="Ex.: capa solta, dedicatória na folha de rosto"></label>
    </div>
</fieldset>
