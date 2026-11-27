<?php
/**
 * Formulário bibliográfico do registro (Grupo 1 e revisão do Grupo 2). Os mesmos campos da revisão
 * administrativa do Inventário (“Verificar e corrigir dados”, admin/includes/form-conferencia-livro.php):
 * título, subtítulo, autores, editora, ISBN, CDD e fonte, ano, edição, idioma, categoria e assunto —
 * mais a quantidade de exemplares e o estado de conservação de cada um.
 *
 * ISBN e CDD: “Informação encontrada / não encontrada”. A ausência fica só no indicador
 * (isbn_situacao/cdd_situacao), nunca escrita no campo oficial. Sem idioma pré-preenchido.
 *
 * Espera: $reg (registro de Levantamento::registro/abrir), $somenteLeitura (bool), $categorias (lista id/nome).
 * Opcional: $maxQuantidade (limite de exemplares que este registro ainda pode ter no lote).
 */
use Biblioteca\Reorganizacao\Reorganizacao;

$f = $reg;
$ro = $somenteLeitura ? ' disabled' : '';
$rid = (int)$reg['id'];
$qtd = max(1, (int)($f['quantidade'] ?? 1));
$estados = $f['estados'] ?? [];
$maxQtd = (int)($maxQuantidade ?? reorg_config('max_exemplares_registro'));
$isbnSit = (string)($f['isbn_situacao'] ?? '') ?: ((int)($f['isbn_nao_encontrado'] ?? 0) ? 'nao_encontrado' : (!empty($f['isbn']) ? 'encontrado' : ''));
$cddSit = (string)($f['cdd_situacao'] ?? '') ?: ((int)($f['cdd_nao_encontrada'] ?? 0) ? 'nao_encontrada' : (!empty($f['cdd']) ? 'encontrada' : ''));
$sel = fn (string $a, string $b) => $a === $b ? ' checked' : '';
?>
<form class="reorg-registro" data-reorg-form data-id="<?= $rid ?>" data-versao="<?= (int)$reg['versao'] ?>" novalidate autocomplete="off">
    <fieldset class="reorg-bloco-form">
        <legend>Identificação da obra</legend>
        <div class="reorg-campos reorg-campos--2">
            <label class="adm-field reorg-campos__inteiro" data-campo="titulo"><span class="adm-field__label">Título <span class="adm-required">*</span></span>
                <input class="adm-input" name="titulo" maxlength="255" value="<?= e($f['titulo'] ?? '') ?>"<?= $ro ?>><span class="adm-field__error"></span></label>
            <label class="adm-field reorg-campos__inteiro"><span class="adm-field__label">Subtítulo <span class="adm-optional">opcional</span></span>
                <input class="adm-input" name="subtitulo" maxlength="255" value="<?= e($f['subtitulo'] ?? '') ?>"<?= $ro ?>></label>
            <label class="adm-field" data-campo="autores"><span class="adm-field__label">Autor(es) <span class="adm-required">*</span> <span class="adm-optional">separe com ponto e vírgula (;)</span></span>
                <input class="adm-input" name="autores" maxlength="1500" value="<?= e($f['autores'] ?? '') ?>" placeholder="SOBRENOME, Nome; SOBRENOME, Nome"<?= $ro ?>><span class="adm-field__error"></span></label>
            <label class="adm-field" data-campo="editora"><span class="adm-field__label">Editora <span class="adm-required">*</span></span>
                <input class="adm-input" name="editora" maxlength="150" value="<?= e($f['editora'] ?? '') ?>"<?= $ro ?>><span class="adm-field__error"></span></label>
        </div>
    </fieldset>

    <fieldset class="reorg-bloco-form">
        <legend>ISBN e classificação</legend>
        <div class="reorg-campos reorg-campos--2">
            <div class="adm-field" data-campo="isbn" data-situacao="isbn">
                <span class="adm-field__label" id="r-isbn-rot-<?= $rid ?>">ISBN <span class="adm-required">*</span></span>
                <div class="reorg-situacao" role="radiogroup" aria-labelledby="r-isbn-rot-<?= $rid ?>">
                    <label class="reorg-radio"><input type="radio" name="isbn_situacao" value="encontrado"<?= $sel($isbnSit, 'encontrado') . $ro ?>> Informação encontrada</label>
                    <label class="reorg-radio"><input type="radio" name="isbn_situacao" value="nao_encontrado"<?= $sel($isbnSit, 'nao_encontrado') . $ro ?>> Informação não encontrada</label>
                </div>
                <input class="adm-input adm-mono" name="isbn" maxlength="30" inputmode="numeric" value="<?= e($f['isbn'] ?? '') ?>" placeholder="ISBN-10 ou ISBN-13 (978...)" aria-labelledby="r-isbn-rot-<?= $rid ?>"<?= $isbnSit === 'nao_encontrado' ? ' disabled' : $ro ?>>
                <span class="adm-field__error"></span></div>
            <div class="adm-field" data-campo="cdd" data-situacao="cdd">
                <span class="adm-field__label" id="r-cdd-rot-<?= $rid ?>">CDD <span class="adm-required">*</span></span>
                <div class="reorg-situacao" role="radiogroup" aria-labelledby="r-cdd-rot-<?= $rid ?>">
                    <label class="reorg-radio"><input type="radio" name="cdd_situacao" value="encontrada"<?= $sel($cddSit, 'encontrada') . $ro ?>> Informação encontrada</label>
                    <label class="reorg-radio"><input type="radio" name="cdd_situacao" value="nao_encontrada"<?= $sel($cddSit, 'nao_encontrada') . $ro ?>> Informação não encontrada</label>
                </div>
                <input class="adm-input adm-mono" name="cdd" maxlength="30" value="<?= e($f['cdd'] ?? '') ?>" placeholder="Ex.: 869.3" aria-labelledby="r-cdd-rot-<?= $rid ?>"<?= $cddSit === 'nao_encontrada' ? ' disabled' : $ro ?>>
                <span class="adm-field__error"></span></div>
            <label class="adm-field"><span class="adm-field__label">Fonte da CDD <span class="adm-optional">opcional</span></span>
                <input class="adm-input" name="cdd_fonte" maxlength="255" value="<?= e($f['cdd_fonte'] ?? '') ?>" placeholder="Ficha catalográfica do livro"<?= $ro ?>></label>
            <label class="adm-field" data-campo="categoria_id"><span class="adm-field__label">Categoria <span class="adm-optional">opcional</span></span>
                <select class="adm-select" name="categoria_id"<?= $ro ?>><option value="">—</option><?= reorg_opcoes($categorias, $f['categoria_id'] ?? '') ?></select><span class="adm-field__error"></span></label>
        </div>
    </fieldset>

    <fieldset class="reorg-bloco-form">
        <legend>Publicação <span class="adm-optional">opcional</span></legend>
        <div class="reorg-campos reorg-campos--4">
            <label class="adm-field" data-campo="ano_publicacao"><span class="adm-field__label">Ano</span>
                <input class="adm-input" name="ano_publicacao" maxlength="4" inputmode="numeric" value="<?= e($f['ano_publicacao'] ?? '') ?>"<?= $ro ?>><span class="adm-field__error"></span></label>
            <label class="adm-field"><span class="adm-field__label">Edição</span>
                <input class="adm-input" name="edicao" maxlength="60" value="<?= e($f['edicao'] ?? '') ?>"<?= $ro ?>></label>
            <label class="adm-field"><span class="adm-field__label">Idioma</span>
                <input class="adm-input" name="idioma" maxlength="30" value="<?= e($f['idioma'] ?? '') ?>" list="reorg-idiomas"<?= $ro ?>></label>
            <label class="adm-field"><span class="adm-field__label">Assunto</span>
                <input class="adm-input" name="assunto" maxlength="255" value="<?= e($f['assunto'] ?? '') ?>"<?= $ro ?>></label>
        </div>
    </fieldset>

    <fieldset class="reorg-bloco-form reorg-exemplares" data-campo="estados">
        <legend>Exemplares físicos desta obra</legend>
        <div class="reorg-linha">
            <label class="adm-field reorg-qtd" data-campo="quantidade"><span class="adm-field__label">Quantidade <span class="adm-required">*</span></span>
                <input class="adm-input" type="number" name="quantidade" min="1" max="<?= max(1, $maxQtd) ?>" value="<?= $qtd ?>" data-reorg-qtd<?= $ro ?>><span class="adm-field__error"></span></label>
            <div class="adm-field"><span class="adm-field__label">Aplicar a todos</span>
                <div class="reorg-situacao">
                    <?php foreach (Reorganizacao::CONSERVACAO as $k => $v): ?>
                        <button class="adm-button adm-button--sm" type="button" data-reorg-aplicar="<?= $k ?>" title="<?= e($v) ?>"<?= $ro ?>><?= e(explode(' — ', $v)[0]) ?></button>
                    <?php endforeach; ?>
                </div></div>
        </div>
        <p class="adm-field__hint">Os dados da obra valem para todas as cópias; cada exemplar tem o próprio estado de conservação. <?= e(implode(' · ', Reorganizacao::CONSERVACAO)) ?>.<?= $maxQtd < (int)reorg_config('max_exemplares_registro') ? ' Neste lote, este registro pode ter no máximo ' . $maxQtd . ' exemplar(es).' : '' ?></p>
        <ol class="reorg-estados" data-reorg-estados>
            <?php for ($i = 1; $i <= $qtd; $i++): ?>
                <li data-ordem="<?= $i ?>"><span>Exemplar <?= $i ?><?= !empty($reg['exemplares'][$i - 1]['codigo_individual']) ? ' · <b class="adm-mono">' . e($reg['exemplares'][$i - 1]['codigo_individual']) . '</b>' : '' ?></span>
                    <?php foreach (Reorganizacao::CONSERVACAO as $k => $v): ?>
                        <label class="reorg-radio" title="<?= e($v) ?>"><input type="radio" name="estados[<?= $i ?>]" value="<?= $k ?>"<?= ($estados[$i] ?? null) === $k ? ' checked' : '' ?><?= $ro ?>> <?= e(explode(' — ', $v)[0]) ?></label>
                    <?php endforeach; ?></li>
            <?php endfor; ?>
        </ol>
        <span class="adm-field__error"></span>
    </fieldset>
    <label class="adm-field"><span class="adm-field__label">Observações <span class="adm-optional">opcional</span></span>
        <textarea class="adm-textarea" name="observacoes" rows="2" maxlength="1000"<?= $ro ?>><?= e($f['observacoes'] ?? '') ?></textarea></label>
    <datalist id="reorg-idiomas"><option value="Português"><option value="Inglês"><option value="Espanhol"><option value="Francês"><option value="Italiano"><option value="Alemão"></datalist>
    <div class="reorg-avisos" data-reorg-avisos hidden></div>
</form>
