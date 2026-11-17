<?php
/**
 * Dados bibliográficos editáveis na conferência do Inventário, com os alertas cadastrais.
 * Espera: $lv (valores: titulo, subtitulo, autores (lista ou texto), isbn, editora, ano_publicacao,
 * edicao, idioma, assunto, categoria_id), $alertasLv (alertas abertos), $categoriasLista,
 * $editavelLv (pode editar o livro). Nenhum campo é obrigatório.
 * Opcional: $cddLv = ['numero' => CDD que vale hoje, 'tipo' => aprovada|proposta|antiga|null,
 * 'pode_aprovar' => bool, 'cdd' => valor digitado, 'cdd_fonte' => fonte digitada] mostra o campo CDD.
 * O formulário que inclui este arquivo cuida do <form>, do CSRF e dos botões.
 */
$lv = ($lv ?? []) + ['titulo' => '', 'subtitulo' => '', 'autores' => '', 'isbn' => '', 'isbn_original' => '', 'editora' => '', 'ano_publicacao' => '', 'edicao' => '', 'idioma' => '', 'assunto' => '', 'categoria_id' => ''];
$autoresTexto = is_array($lv['autores']) ? implode('; ', $lv['autores']) : (string)$lv['autores'];
$isbnTexto = (string)($lv['isbn'] ?: $lv['isbn_original']);
$alertasLv = $alertasLv ?? [];
$editavelLv = $editavelLv ?? false;
$ro = $editavelLv ? '' : ' readonly';
$tiposAlerta = ['incompleto' => ['Informação incompleta', 'amber'], 'inconsistencia' => ['Possível inconsistência', 'red'], 'duplicidade' => ['Possível duplicidade', 'indigo'], 'outro' => ['Observação', 'muted']];
?>
<?php if ($alertasLv): ?>
    <div class="adm-alertas-cadastro" role="note" aria-label="Alertas cadastrais">
        <p class="adm-alertas-cadastro__titulo"><?= icon('triangle-alert') ?> <strong><?= count($alertasLv) ?> alerta(s) cadastral(is)</strong> · não impedem marcar como encontrado</p>
        <ul>
            <?php foreach ($alertasLv as $a): [$rotA, $tomA] = $tiposAlerta[$a['tipo']] ?? $tiposAlerta['outro']; ?>
                <li><span class="adm-status adm-tone--<?= $tomA ?>"><?= e($rotA) ?></span> <?= e($a['mensagem']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<fieldset class="adm-fieldset adm-form-section">
    <legend class="adm-field__label">Informações do livro <span class="adm-optional">(todas opcionais: deixe vazio o que não souber)</span></legend>
    <?php if (!$editavelLv): ?><p class="adm-field__hint">Seu perfil não pode alterar os dados do livro. Peça a correção a quem edita o acervo.</p><?php endif; ?>
    <div class="adm-form-grid">
        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Título</span><input class="adm-input" name="titulo" maxlength="255" value="<?= e((string)$lv['titulo']) ?>" placeholder="Como está na capa ou na folha de rosto"<?= $ro ?>></label>
        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Subtítulo</span><input class="adm-input" name="subtitulo" maxlength="255" value="<?= e((string)$lv['subtitulo']) ?>"<?= $ro ?>></label>
        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Autor(es)</span><input class="adm-input" name="autores" maxlength="1500" value="<?= e($autoresTexto) ?>" placeholder="Separe vários autores com ponto e vírgula (;)"<?= $ro ?>></label>
        <label class="adm-field"><span class="adm-field__sublabel">ISBN</span><input class="adm-input" name="isbn" maxlength="100" value="<?= e($isbnTexto) ?>" inputmode="numeric" placeholder="Da contracapa ou da ficha catalográfica"<?= $ro ?>>
            <span class="adm-field__hint">Se não conferir, é guardado como foi digitado e fica com alerta.</span></label>
        <label class="adm-field"><span class="adm-field__sublabel">Editora</span><input class="adm-input" name="editora" maxlength="150" value="<?= e((string)$lv['editora']) ?>"<?= $ro ?>></label>
        <label class="adm-field"><span class="adm-field__sublabel">Ano</span><input class="adm-input" name="ano_publicacao" maxlength="4" value="<?= e((string)$lv['ano_publicacao']) ?>" inputmode="numeric" placeholder="Ex.: 1998"<?= $ro ?>></label>
        <label class="adm-field"><span class="adm-field__sublabel">Edição</span><input class="adm-input" name="edicao" maxlength="60" value="<?= e((string)$lv['edicao']) ?>"<?= $ro ?>></label>
        <label class="adm-field"><span class="adm-field__sublabel">Idioma</span><input class="adm-input" name="idioma" maxlength="30" value="<?= e((string)$lv['idioma']) ?>" placeholder="Português"<?= $ro ?>></label>
        <label class="adm-field"><span class="adm-field__sublabel">Gênero / categoria</span>
            <select class="adm-select" name="categoria_id"<?= $editavelLv ? '' : ' disabled' ?>>
                <option value="">Sem categoria</option>
                <?php foreach ($categoriasLista ?? [] as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"<?= (int)$lv['categoria_id'] === (int)$cat['id'] ? ' selected' : '' ?>><?= e($cat['nome']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Assunto</span><input class="adm-input" name="assunto" maxlength="255" value="<?= e((string)$lv['assunto']) ?>"<?= $ro ?>></label>
        <?php if (isset($cddLv) && is_array($cddLv)):
            $cddTipos = ['aprovada' => 'aprovada', 'proposta' => 'proposta, aguardando aprovação', 'antiga' => 'da planilha antiga, ainda não aprovada; digite-a para registrar'];
            // O campo vem com a CDD aprovada ou proposta; a antiga da planilha só aparece na dica
            // (pode nem estar no formato da CDD). Só o que for alterado é gravado (cdd_exibida).
            $cddExibida = in_array($cddLv['tipo'] ?? null, ['aprovada', 'proposta'], true) ? (string)$cddLv['numero'] : '';
            $cddValor = (string)($cddLv['cdd'] ?? $cddExibida);
            $cddPodeGravar = $editavelLv && auth_pode('cdd.sugerir'); ?>
            <?php if ($cddPodeGravar): ?><input type="hidden" name="cdd_exibida" value="<?= e($cddExibida) ?>"><?php endif; ?>
            <label class="adm-field"><span class="adm-field__sublabel">CDD</span><input class="adm-input adm-mono" name="cdd" maxlength="60" value="<?= e($cddValor) ?>" placeholder="Ex.: 869.3" autocomplete="off"<?= $cddPodeGravar ? '' : ' disabled' ?>>
                <span class="adm-field__hint"><?= !empty($cddLv['numero']) ? 'Atual: ' . e($cddLv['numero']) . ' (' . e($cddTipos[$cddLv['tipo']] ?? '') . '). ' : 'Ainda sem CDD. ' ?><?= !$cddPodeGravar ? 'Seu perfil não pode alterar a CDD.' : (!empty($cddLv['pode_aprovar']) ? 'Se mudar, a nova CDD fica aprovada, com histórico.' : 'Se mudar, vira sugestão para a bibliotecária aprovar.') ?></span></label>
            <label class="adm-field"><span class="adm-field__sublabel">Fonte da CDD <span class="adm-optional">opcional</span></span><input class="adm-input" name="cdd_fonte" maxlength="255" value="<?= e((string)($cddLv['cdd_fonte'] ?? '')) ?>" placeholder="Ex.: ficha catalográfica do livro"<?= $cddPodeGravar ? '' : ' disabled' ?>>
                <span class="adm-field__hint">Se ficar vazio: “Livro em mãos (conferência do inventário)”.</span></label>
        <?php endif; ?>
        <?php if ($editavelLv): ?>
            <input type="hidden" name="editar_livro" value="1">
            <?php if ($alertasLv): ?>
                <label class="adm-check-confirm adm-field--full"><input type="checkbox" name="confirmar_alertas" value="1"> <span>Conferi o livro em mãos: marcar os alertas restantes como conferidos (ex.: o livro realmente não traz autor ou ISBN).</span></label>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</fieldset>
