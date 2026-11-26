<?php
/**
 * Formulário bibliográfico do registro (Grupo 1 e revisão do Grupo 2). Os mesmos campos da revisão
 * administrativa do Inventário (“Verificar e corrigir dados”): título, subtítulo, autores, ISBN,
 * editora, ano, edição, idioma, categoria, assunto, CDD e fonte da CDD — mais a quantidade de
 * exemplares e o estado de conservação de cada um.
 *
 * Espera: $reg (registro de Levantamento::registro/abrir), $somenteLeitura (bool), $categorias (lista id/nome).
 */
use Biblioteca\Reorganizacao\Reorganizacao;

$f = $reg;
$ro = $somenteLeitura ? ' disabled' : '';
$chk = fn (string $c) => (int)($f[$c] ?? 0) ? ' checked' : '';
$qtd = max(1, (int)($f['quantidade'] ?? 1));
$estados = $f['estados'] ?? [];
?>
<form class="reorg-registro" data-reorg-form data-id="<?= (int)$reg['id'] ?>" data-versao="<?= (int)$reg['versao'] ?>" novalidate autocomplete="off">
    <div class="reorg-registro__grade">
        <label class="adm-field adm-field--full" data-campo="titulo"><span class="adm-field__label">Título <span class="adm-required">*</span></span>
            <input class="adm-input" name="titulo" maxlength="255" value="<?= e($f['titulo'] ?? '') ?>"<?= $ro ?>><span class="adm-field__error"></span></label>
        <label class="adm-field adm-field--full"><span class="adm-field__label">Subtítulo <span class="adm-optional">opcional</span></span>
            <input class="adm-input" name="subtitulo" maxlength="255" value="<?= e($f['subtitulo'] ?? '') ?>"<?= $ro ?>></label>
        <div class="adm-field adm-field--full" data-campo="autores"><label class="adm-field__label" for="r-autores-<?= (int)$reg['id'] ?>">Autor(es) <span class="adm-required">*</span> <span class="adm-optional">separe com ponto e vírgula (;)</span></label>
            <input class="adm-input" id="r-autores-<?= (int)$reg['id'] ?>" name="autores" maxlength="1500" value="<?= e($f['autores'] ?? '') ?>" placeholder="SOBRENOME, Nome; SOBRENOME, Nome"<?= $ro ?>>
            <label class="adm-check"><input type="checkbox" name="autor_nao_indicado" value="1" data-limpa="autores"<?= $chk('autor_nao_indicado') . $ro ?>> Autor não indicado no livro</label><span class="adm-field__error"></span></div>
        <div class="adm-field" data-campo="editora"><label class="adm-field__label" for="r-editora-<?= (int)$reg['id'] ?>">Editora <span class="adm-required">*</span></label>
            <input class="adm-input" id="r-editora-<?= (int)$reg['id'] ?>" name="editora" maxlength="150" value="<?= e($f['editora'] ?? '') ?>"<?= $ro ?>>
            <label class="adm-check"><input type="checkbox" name="editora_nao_indicada" value="1" data-limpa="editora"<?= $chk('editora_nao_indicada') . $ro ?>> Editora não indicada no livro</label><span class="adm-field__error"></span></div>
        <div class="adm-field" data-campo="isbn"><label class="adm-field__label" for="r-isbn-<?= (int)$reg['id'] ?>">ISBN <span class="adm-required">*</span></label>
            <input class="adm-input adm-mono" id="r-isbn-<?= (int)$reg['id'] ?>" name="isbn" maxlength="30" inputmode="numeric" value="<?= e($f['isbn'] ?? '') ?>" placeholder="978..."<?= $ro ?>>
            <label class="adm-check"><input type="checkbox" name="isbn_nao_encontrado" value="1" data-limpa="isbn"<?= $chk('isbn_nao_encontrado') . $ro ?>> ISBN não encontrado</label><span class="adm-field__error"></span></div>
        <div class="adm-field" data-campo="cdd"><label class="adm-field__label" for="r-cdd-<?= (int)$reg['id'] ?>">CDD <span class="adm-required">*</span></label>
            <input class="adm-input adm-mono" id="r-cdd-<?= (int)$reg['id'] ?>" name="cdd" maxlength="30" value="<?= e($f['cdd'] ?? '') ?>" placeholder="869.3"<?= $ro ?>>
            <label class="adm-check"><input type="checkbox" name="cdd_nao_encontrada" value="1" data-limpa="cdd"<?= $chk('cdd_nao_encontrada') . $ro ?>> CDD não encontrada</label><span class="adm-field__error"></span></div>
        <label class="adm-field"><span class="adm-field__label">Fonte da CDD <span class="adm-optional">opcional</span></span>
            <input class="adm-input" name="cdd_fonte" maxlength="255" value="<?= e($f['cdd_fonte'] ?? '') ?>" placeholder="Ficha catalográfica do livro"<?= $ro ?>></label>
        <label class="adm-field" data-campo="ano_publicacao"><span class="adm-field__label">Ano <span class="adm-optional">opcional</span></span>
            <input class="adm-input" name="ano_publicacao" maxlength="4" inputmode="numeric" value="<?= e($f['ano_publicacao'] ?? '') ?>"<?= $ro ?>><span class="adm-field__error"></span></label>
        <label class="adm-field"><span class="adm-field__label">Edição <span class="adm-optional">opcional</span></span>
            <input class="adm-input" name="edicao" maxlength="60" value="<?= e($f['edicao'] ?? '') ?>"<?= $ro ?>></label>
        <label class="adm-field"><span class="adm-field__label">Idioma</span>
            <input class="adm-input" name="idioma" maxlength="30" value="<?= e($f['idioma'] ?? 'Português') ?>" list="reorg-idiomas"<?= $ro ?>></label>
        <label class="adm-field" data-campo="categoria_id"><span class="adm-field__label">Categoria <span class="adm-optional">opcional</span></span>
            <select class="adm-select" name="categoria_id"<?= $ro ?>><option value="">—</option><?= reorg_opcoes($categorias, $f['categoria_id'] ?? '') ?></select><span class="adm-field__error"></span></label>
        <label class="adm-field"><span class="adm-field__label">Assunto <span class="adm-optional">opcional</span></span>
            <input class="adm-input" name="assunto" maxlength="255" value="<?= e($f['assunto'] ?? '') ?>"<?= $ro ?>></label>
    </div>

    <fieldset class="reorg-exemplares" data-campo="estados">
        <legend class="adm-field__label">Exemplares físicos desta obra</legend>
        <div class="reorg-linha">
            <label class="adm-field reorg-qtd" data-campo="quantidade"><span class="adm-field__label">Quantidade <span class="adm-required">*</span></span>
                <input class="adm-input" type="number" name="quantidade" min="1" max="<?= (int)reorg_config('max_exemplares_registro') ?>" value="<?= $qtd ?>" data-reorg-qtd<?= $ro ?>><span class="adm-field__error"></span></label>
            <label class="adm-field"><span class="adm-field__label">Aplicar a todos</span>
                <select class="adm-select" name="aplicar_todos" data-reorg-aplicar<?= $ro ?>><option value="">—</option><?php foreach (Reorganizacao::CONSERVACAO as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
        </div>
        <p class="adm-field__hint">Os dados bibliográficos valem para todas as cópias; cada exemplar tem o próprio estado de conservação. <?= e(implode(' · ', Reorganizacao::CONSERVACAO)) ?>.</p>
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
    <label class="adm-field adm-field--full"><span class="adm-field__label">Observações <span class="adm-optional">opcional</span></span>
        <textarea class="adm-textarea" name="observacoes" rows="2" maxlength="1000"<?= $ro ?>><?= e($f['observacoes'] ?? '') ?></textarea></label>
    <datalist id="reorg-idiomas"><option value="Português"><option value="Inglês"><option value="Espanhol"><option value="Francês"><option value="Italiano"><option value="Alemão"></datalist>
    <div class="reorg-avisos" data-reorg-avisos hidden></div>
</form>
