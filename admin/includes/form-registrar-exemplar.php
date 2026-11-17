<?php
/**
 * Campos do EXEMPLAR físico no Inventário ("+ Registrar exemplar" e "Cadastrar livro não listado").
 * Espera: $rx (valores: codigo_bdp, estado_conservacao, localizacao_id, observacao, situacao_inventario, vinculo, processo_bdp),
 *         $rxSugestao (opcional: fisico_sugestao_local(), ou só o id da localização sugerida), $rxLivroId (obra, para a reserva do código; 0 = obra nova),
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
 *
 * Localização definitiva: campos dependentes Setor → Estante → Prateleira (Organização Física).
 * A localização antiga da planilha nunca é usada. Conservação: começa em "Não avaliado" e não é
 * copiada de um exemplar para outro.
 */
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\InventarioService;

$rx = ($rx ?? []) + ['codigo_bdp' => '', 'estado_conservacao' => 'nao_avaliado', 'localizacao_id' => '', 'observacao' => '', 'situacao_inventario' => 'encontrado', 'vinculo' => '', 'processo_bdp' => ''];
$rxRegistros = $rxRegistros ?? [];
$rxLivroId = (int)($rxLivroId ?? 0);
$rxOrigem = $rxOrigem ?? 'registrar';
$rxId = 'rx-' . $rxOrigem;
$rxProcesso = CodigoBdpService::processoValido((string)$rx['processo_bdp']) ?? CodigoBdpService::novoProcesso();
$rxLivres = array_values(array_filter($rxRegistros, fn ($r) => $r['exemplar_id'] === null));
$rxVinculo = (string)$rx['vinculo'];
$rxSugestao = isset($rxSugestao) && is_array($rxSugestao) ? $rxSugestao
    : ['id' => isset($rxSugestao) && $rxSugestao ? (int)$rxSugestao : null, 'origem' => 'estante atual / última utilizada', 'aviso' => null];
$rxCarimbo = in_array($rx['carimbo'] ?? '', ['aguardando', 'carimbado'], true) ? $rx['carimbo'] : '';
$rxLocal = (string)$rx['localizacao_id'] !== '' ? (int)$rx['localizacao_id'] : null;
?>
<fieldset class="adm-fieldset adm-form-section adm-secoes">
    <legend class="adm-secoes__legenda">Exemplar físico</legend>
    <input type="hidden" name="processo_bdp" value="<?= e($rxProcesso) ?>">
    <input type="hidden" name="origem_form" value="<?= e($rxOrigem) ?>">

    <section class="adm-secao" aria-labelledby="<?= $rxId ?>-s1">
        <h3 class="adm-secao__titulo" id="<?= $rxId ?>-s1"><span class="adm-secao__num" aria-hidden="true">1</span> Identificação do exemplar</h3>
        <div class="adm-field adm-codigo-bdp" data-codigo-bdp>
            <label class="adm-field__label" for="<?= $rxId ?>-codigo">Código BDP <span class="adm-required" aria-hidden="true">*</span></label>
            <div class="adm-codigo-bdp__linha">
                <input class="adm-input adm-input--code" id="<?= $rxId ?>-codigo" name="codigo_bdp" maxlength="30" value="<?= e((string)$rx['codigo_bdp']) ?>" placeholder="BDP-000123"
                       autocapitalize="characters" spellcheck="false" autocomplete="off" aria-describedby="<?= $rxId ?>-codigo-dica <?= $rxId ?>-codigo-status" data-codigo-campo>
                <button class="adm-button adm-button--ghost adm-codigo-bdp__gerar" type="submit" name="acao" value="gerar_codigo" formnovalidate data-atalho="gerar"
                        data-gerar-codigo="<?= e(admin_asset('api/inventario/gerar-codigo.php')) ?>" data-livro="<?= $rxLivroId ?>"><?= icon('sparkles') ?> Gerar código BDP</button>
            </div>
            <p class="adm-codigo-bdp__status" id="<?= $rxId ?>-codigo-status" role="status" aria-live="polite" data-codigo-status><?= !empty($rx['codigo_gerado']) ? 'Código reservado para este formulário (ainda não atribuído): <strong>' . e((string)$rx['codigo_bdp']) . '</strong>. Ele fica definitivo ao salvar.' : '' ?></p>
            <p class="adm-field__hint" id="<?= $rxId ?>-codigo-dica">Já carimbado? Digite o código do carimbo. Ainda sem carimbo? <strong>Gerar código BDP</strong> reserva o próximo número só para este formulário: dá para carimbar agora ou depois, por lote, na Planilha de Códigos BDP. O código nunca se repete.</p>
        </div>
        <?php if ($rxRegistros): ?>
            <fieldset class="adm-escolha adm-escolha--lista adm-linhas-planilha" data-escolha-linha>
                <legend class="adm-field__sublabel">Este exemplar corresponde a qual linha da planilha? <span class="adm-required" aria-hidden="true">*</span></legend>
                <p class="adm-field__hint"><?= count($rxLivres) ?> linha(s) ainda sem exemplar. Compare com o livro em mãos e escolha. Nada é escolhido automaticamente.</p>
                <?php foreach ($rxRegistros as $reg): $livre = $reg['exemplar_id'] === null; $valor = 'r:' . (int)$reg['id']; ?>
                    <label class="adm-escolha__opcao adm-linha-planilha<?= $livre ? '' : ' is-ligada' ?>">
                        <input type="radio" name="vinculo" value="<?= e($valor) ?>"<?= $livre ? '' : ' disabled' ?><?= $livre && $rxVinculo === $valor ? ' checked' : '' ?> required>
                        <span class="adm-linha-planilha__corpo">
                            <span class="adm-linha-planilha__topo">
                                <strong class="adm-linha-planilha__titulo"><?= e($reg['titulo_original'] ? mb_strimwidth($reg['titulo_original'], 0, 90, '…') : 'Linha sem título') ?></strong>
                                <?php if ($livre): ?>
                                    <span class="adm-status adm-tone--<?= $reg['nao_localizado_em'] ? 'red' : 'amber' ?>"><?= $reg['nao_localizado_em'] ? 'Sem exemplar · não localizada' : 'Sem exemplar' ?></span>
                                <?php else: ?>
                                    <span class="adm-status adm-tone--green">Ligada ao <?= e((string)$reg['codigo_individual']) ?></span>
                                <?php endif; ?>
                            </span>
                            <small class="adm-linha-planilha__origem">Linha <?= (int)$reg['linha'] ?> · <?= e($reg['arquivo']) ?></small>
                            <small class="adm-block-muted">
                                <?= e(implode(' · ', array_filter([
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
                    <span class="adm-linha-planilha__corpo"><strong>Exemplar adicional, sem linha correspondente</strong>
                        <small class="adm-block-muted">Uma cópia física além das que aparecem na planilha. As linhas continuam “sem exemplar”.</small></span>
                </label>
            </fieldset>
        <?php else: ?>
            <p class="adm-field__hint adm-registro-direto"><?= icon('info') ?> Esta obra não tem linhas da planilha importada (por exemplo, foi cadastrada na conferência): o exemplar é registrado direto como cópia física da obra.</p>
        <?php endif; ?>
    </section>

    <section class="adm-secao" aria-labelledby="<?= $rxId ?>-s2">
        <h3 class="adm-secao__titulo" id="<?= $rxId ?>-s2"><span class="adm-secao__num" aria-hidden="true">2</span> Conferência física</h3>
        <fieldset class="adm-escolha">
            <legend class="adm-field__sublabel">Situação no inventário</legend>
            <?php foreach (InventarioService::SITUACOES_REGISTRO as $valor => $rotulo): ?>
                <label class="adm-escolha__opcao"><input type="radio" name="situacao_inventario" value="<?= $valor ?>"<?= $rx['situacao_inventario'] === $valor ? ' checked' : '' ?>>
                    <span><strong><?= e($rotulo) ?></strong> <small><?= $valor === 'encontrado' ? 'o livro está em mãos agora' : 'registrar agora e conferir depois' ?></small></span></label>
            <?php endforeach; ?>
        </fieldset>
    </section>

    <section class="adm-secao" aria-labelledby="<?= $rxId ?>-s3">
        <h3 class="adm-secao__titulo" id="<?= $rxId ?>-s3"><span class="adm-secao__num" aria-hidden="true">3</span> Carimbagem</h3>
        <fieldset class="adm-escolha">
            <legend class="adm-visually-hidden">Carimbo</legend>
            <label class="adm-escolha__opcao"><input type="radio" name="carimbo" value="aguardando"<?= $rxCarimbo !== 'carimbado' ? ' checked' : '' ?>>
                <span><strong>Carimbar depois</strong> <small>fica “Aguardando carimbo” na Planilha de Códigos BDP</small></span></label>
            <label class="adm-escolha__opcao"><input type="radio" name="carimbo" value="carimbado"<?= $rxCarimbo === 'carimbado' ? ' checked' : '' ?>>
                <span><strong>O código já está no carimbo</strong> <small>o livro já foi carimbado com este código</small></span></label>
        </fieldset>
    </section>

    <section class="adm-secao" aria-labelledby="<?= $rxId ?>-s4">
        <h3 class="adm-secao__titulo" id="<?= $rxId ?>-s4"><span class="adm-secao__num" aria-hidden="true">4</span> Organização física</h3>
        <fieldset class="adm-fieldset">
            <legend class="adm-field__sublabel">Localização definitiva (setor, estante, prateleira)</legend>
            <?= fisico_campo_local('localizacao_id', $rxLocal, 'Aguardando organização (área de triagem)', $rxId . '-local') ?>
            <?= fisico_sugestao_html($rxSugestao, $rxLocal) ?>
            <p class="adm-field__hint">Ainda sem estante? Deixe em “Aguardando organização”: o exemplar fica na área de triagem e pode ser organizado depois, inclusive em lote.</p>
        </fieldset>
    </section>

    <section class="adm-secao" aria-labelledby="<?= $rxId ?>-s5">
        <h3 class="adm-secao__titulo" id="<?= $rxId ?>-s5"><span class="adm-secao__num" aria-hidden="true">5</span> Informações complementares</h3>
        <div class="adm-form-grid">
            <label class="adm-field"><span class="adm-field__sublabel">Estado de conservação</span><?= fisico_select_conservacao('estado_conservacao', (string)$rx['estado_conservacao']) ?>
                <span class="adm-field__hint">“Não avaliado” pode ficar para depois.</span></label>
            <label class="adm-field"><span class="adm-field__sublabel">Observações deste exemplar <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" value="<?= e((string)$rx['observacao']) ?>" placeholder="Ex.: capa solta, dedicatória"></label>
        </div>
    </section>
</fieldset>
