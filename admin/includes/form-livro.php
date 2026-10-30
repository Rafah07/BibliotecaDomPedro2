<?php
/**
 * Formulário de livro, usado pelo cadastro e pela edição.
 * Espera: $modo ('cadastrar' | 'editar'), $livroForm (array|null), $erros (array), $valores (array).
 */
$editando = $modo === 'editar';
$v = fn (string $campo, $padrao = '') => htmlspecialchars((string)($valores[$campo] ?? $padrao));
$erro = fn (string $campo): string => isset($erros[$campo])
    ? '<span class="adm-field__error" data-error-for="' . $campo . '">' . htmlspecialchars($erros[$campo]) . '</span>'
    : '<span class="adm-field__error" data-error-for="' . $campo . '"></span>';
$invalido = fn (string $campo): string => isset($erros[$campo]) ? ' is-invalid' : '';
$autoresForm = $valores['autores'] ?? [];
$statusAtual = $valores['status_validacao'] ?? 'aguardando_conferencia';
$opcoesStatus = [
    'validado' => 'Os dados bibliográficos foram conferidos com o livro em mãos.',
    'aguardando_conferencia' => 'O cadastro existe, mas o livro físico ainda não foi conferido.',
    'dados_incompletos' => 'Faltam informações importantes, como editora, ano ou ISBN.',
];
if ($editando) {
    $opcoesStatus['com_divergencia'] = 'Há diferença entre o cadastro e o que foi encontrado fisicamente.';
}
$capaLivro = $livroForm ?? ['titulo' => $valores['titulo'] ?? '', 'autor_principal' => $autoresForm[0] ?? '', 'cor' => '#143477'];
if (($capaLivro['titulo'] ?? '') === '') {
    $capaLivro['titulo'] = 'Título do livro';
}
?>
<form class="adm-book-form" method="post" novalidate data-livro-form data-modo="<?= $modo ?>"<?= $editando ? ' data-livro-id="' . (int)$livroForm['id'] . '"' : '' ?>>
    <?php if ($erros): ?>
        <div class="adm-notice adm-notice--red" role="alert" data-form-errors>
            <?= icon('circle-alert') ?>
            <div>
                <strong>Não foi possível salvar as informações.</strong>
                <p>Verifique os campos destacados e tente novamente.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="adm-notice adm-notice--red" role="alert" data-form-errors hidden>
            <?= icon('circle-alert') ?>
            <div>
                <strong>Não foi possível salvar as informações.</strong>
                <p>Verifique os campos destacados e tente novamente.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="adm-form-layout">
        <div class="adm-form-layout__main">
            <section class="adm-card adm-panel adm-form-section" aria-labelledby="secao-principal">
                <div class="adm-form-section__header">
                    <span class="adm-step">1</span>
                    <div>
                        <h2 id="secao-principal">Informações principais</h2>
                        <p>Dados que identificam a obra e a edição.</p>
                    </div>
                </div>
                <div class="adm-form-grid">
                    <label class="adm-field adm-field--full<?= $invalido('titulo') ?>">
                        <span class="adm-field__label">Título do livro <span class="adm-required" aria-hidden="true">*</span><span class="adm-visually-hidden">(obrigatório)</span></span>
                        <input class="adm-input" type="text" name="titulo" required maxlength="255" placeholder="Digite o título da obra" value="<?= $v('titulo') ?>" data-cover-title autocomplete="off">
                        <?= $erro('titulo') ?>
                    </label>

                    <label class="adm-field adm-field--full">
                        <span class="adm-field__label">Subtítulo <span class="adm-optional">opcional</span></span>
                        <input class="adm-input" type="text" name="subtitulo" maxlength="255" value="<?= $v('subtitulo') ?>">
                    </label>

                    <div class="adm-field adm-field--full">
                        <span class="adm-field__label" id="rotulo-autores">Autor</span>
                        <div class="adm-combobox" data-autores>
                            <div class="adm-chips-input">
                                <ul class="adm-chips" data-chips aria-label="Autores selecionados">
                                    <?php foreach ($autoresForm as $nomeAutor): ?>
                                        <li class="adm-chip adm-chip--removable">
                                            <?= htmlspecialchars($nomeAutor) ?>
                                            <input type="hidden" name="autores[]" value="<?= htmlspecialchars($nomeAutor) ?>">
                                            <button type="button" data-chip-remove aria-label="Remover <?= htmlspecialchars($nomeAutor) ?>"><?= icon('x') ?></button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <input type="text" class="adm-chips-input__field" placeholder="Pesquise um autor cadastrado ou digite um novo nome"
                                       role="combobox" aria-labelledby="rotulo-autores" aria-autocomplete="list" aria-expanded="false" aria-controls="lista-autores" autocomplete="off" data-autor-input>
                            </div>
                            <ul class="adm-combobox__list" id="lista-autores" role="listbox" hidden></ul>
                        </div>
                        <span class="adm-field__hint">Pressione Enter para adicionar. É possível informar mais de um autor.</span>
                    </div>

                    <label class="adm-field<?= $invalido('isbn') ?>">
                        <span class="adm-field__label">ISBN <span class="adm-optional">opcional</span></span>
                        <input class="adm-input adm-mono" type="text" name="isbn" maxlength="20" inputmode="numeric" placeholder="978-85-0000-000-0" value="<?= $v('isbn') ?>" data-isbn>
                        <span class="adm-field__hint" data-isbn-hint>Aceita ISBN-10 ou ISBN-13, com ou sem hífens.</span>
                        <?= $erro('isbn') ?>
                    </label>

                    <label class="adm-field">
                        <span class="adm-field__label">Editora <span class="adm-optional">opcional</span></span>
                        <input class="adm-input" type="text" name="editora" maxlength="150" value="<?= $v('editora') ?>">
                    </label>

                    <label class="adm-field<?= $invalido('ano_publicacao') ?>">
                        <span class="adm-field__label">Ano de publicação</span>
                        <input class="adm-input" type="number" name="ano_publicacao" min="1450" max="<?= (int)date('Y') + 1 ?>" step="1" inputmode="numeric" placeholder="Ex.: 2019" value="<?= $v('ano_publicacao') ?>">
                        <?= $erro('ano_publicacao') ?>
                    </label>

                    <label class="adm-field">
                        <span class="adm-field__label">Edição <span class="adm-optional">opcional</span></span>
                        <input class="adm-input" type="text" name="edicao" maxlength="60" placeholder="Ex.: 2ª edição" value="<?= $v('edicao') ?>">
                    </label>

                    <label class="adm-field">
                        <span class="adm-field__label">Idioma</span>
                        <select class="adm-select" name="idioma">
                            <?php foreach (ACERVO_IDIOMAS as $idioma): ?>
                                <option<?= ($valores['idioma'] ?? 'Português') === $idioma ? ' selected' : '' ?>><?= $idioma ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="adm-field<?= $invalido('categoria') ?>">
                        <span class="adm-field__label">Categoria <span class="adm-required" aria-hidden="true">*</span><span class="adm-visually-hidden">(obrigatório)</span></span>
                        <select class="adm-select" name="categoria" required>
                            <option value="">Selecione uma categoria</option>
                            <?php foreach (acervo_categorias() as $cat): ?>
                                <option value="<?= $cat['id'] ?>"<?= (int)($valores['categoria'] ?? 0) === $cat['id'] ? ' selected' : '' ?>><?= htmlspecialchars($cat['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $erro('categoria') ?>
                    </label>
                </div>
                <div class="adm-notice adm-notice--blue adm-notice--compact" data-edition-hint hidden>
                    <?= icon('info') ?>
                    <p data-edition-hint-text></p>
                </div>
            </section>

            <section class="adm-card adm-panel adm-form-section" aria-labelledby="secao-complementar">
                <div class="adm-form-section__header">
                    <span class="adm-step">2</span>
                    <div>
                        <h2 id="secao-complementar">Informações complementares</h2>
                        <p>Ajudam na pesquisa e na recomendação de leituras.</p>
                    </div>
                </div>
                <div class="adm-form-grid">
                    <label class="adm-field<?= $invalido('numero_paginas') ?>">
                        <span class="adm-field__label">Número de páginas</span>
                        <input class="adm-input" type="number" name="numero_paginas" min="1" max="10000" inputmode="numeric" value="<?= $v('numero_paginas') ?>">
                        <?= $erro('numero_paginas') ?>
                    </label>
                    <label class="adm-field">
                        <span class="adm-field__label">Palavras-chave</span>
                        <input class="adm-input" type="text" name="palavras_chave" maxlength="255" placeholder="Separe por vírgulas: amizade, aventura" value="<?= $v('palavras_chave') ?>">
                    </label>
                    <label class="adm-field adm-field--full">
                        <span class="adm-field__label">Sinopse</span>
                        <textarea class="adm-textarea" name="sinopse" rows="5" maxlength="3000" placeholder="Resumo da obra, sem revelar o final" data-counter><?= $v('sinopse') ?></textarea>
                        <span class="adm-field__hint"><span data-counter-value>0</span>/3000 caracteres</span>
                    </label>
                    <label class="adm-field adm-field--full">
                        <span class="adm-field__label"><?= icon('shield-alert', 'adm-field__icon') ?> Observações internas</span>
                        <textarea class="adm-textarea adm-textarea--internal" name="observacoes_internas" rows="3" maxlength="1000" placeholder="Anotações para a equipe da biblioteca"><?= $v('observacoes_internas') ?></textarea>
                        <span class="adm-field__hint">Visível apenas para os administradores. Não aparece no catálogo público.</span>
                    </label>
                </div>
            </section>
        </div>

        <aside class="adm-form-layout__side">
            <section class="adm-card adm-panel adm-form-section" aria-labelledby="secao-capa">
                <div class="adm-form-section__header">
                    <span class="adm-step">3</span>
                    <div>
                        <h2 id="secao-capa">Capa do livro</h2>
                        <p>JPG, PNG ou WebP, até 2 MB.</p>
                    </div>
                </div>
                <div class="adm-upload" data-upload>
                    <div class="adm-upload__preview" data-upload-preview>
                        <?= acervo_capa($capaLivro, 'lg') ?>
                    </div>
                    <p class="adm-upload__status" data-upload-status>Sem imagem: será usada a capa padrão com o título.</p>
                    <input type="file" name="capa" accept="image/jpeg,image/png,image/webp" class="adm-visually-hidden" id="campo-capa" data-upload-input>
                    <div class="adm-upload__actions">
                        <label class="adm-button adm-button--ghost adm-button--sm" for="campo-capa"><?= icon('image') ?> Selecionar imagem</label>
                        <button type="button" class="adm-button adm-button--ghost adm-button--sm adm-button--danger-text" data-upload-remove disabled><?= icon('trash-2') ?> Remover imagem</button>
                    </div>
                    <span class="adm-field__error" data-error-for="capa"></span>
                </div>
            </section>

            <section class="adm-card adm-panel adm-form-section" aria-labelledby="secao-situacao">
                <div class="adm-form-section__header">
                    <span class="adm-step">4</span>
                    <div>
                        <h2 id="secao-situacao"><?= $editando ? 'Situação do cadastro' : 'Situação inicial' ?></h2>
                        <p>Indica se os dados já foram verificados.</p>
                    </div>
                </div>
                <div class="adm-radio-cards" role="radiogroup" aria-labelledby="secao-situacao">
                    <?php foreach ($opcoesStatus as $valor => $explicacao): ?>
                        <label class="adm-radio-card">
                            <input type="radio" name="status_validacao" value="<?= $valor ?>"<?= $statusAtual === $valor ? ' checked' : '' ?>>
                            <span>
                                <?= acervo_badge_status($valor) ?>
                                <small><?= htmlspecialchars($explicacao) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="adm-side-note"><?= icon('info') ?> Cadastrar o livro não o torna disponível para empréstimo. A disponibilidade depende dos exemplares físicos cadastrados.</p>
            </section>

            <?php if ($editando): ?>
                <section class="adm-card adm-panel adm-form-section" aria-labelledby="secao-registro">
                    <div class="adm-form-section__header">
                        <div>
                            <h2 id="secao-registro">Registro</h2>
                            <p>Informações que não são alteradas por aqui.</p>
                        </div>
                    </div>
                    <dl class="adm-meta adm-meta--stack">
                        <div><dt>Origem</dt><dd><?= htmlspecialchars(ACERVO_ORIGENS[$livroForm['origem_cadastro']]) ?></dd></div>
                        <div><dt>Cadastrado em</dt><dd><?= acervo_data($livroForm['criado_em']) ?></dd></div>
                        <div><dt>Exemplares identificados</dt><dd><?= $livroForm['total_exemplares'] ?> (<?= $livroForm['disponiveis'] ?> disponíveis)</dd></div>
                    </dl>
                </section>
            <?php endif; ?>
        </aside>
    </div>

    <div class="adm-form-actions">
        <p class="adm-form-actions__note"><?= icon('info') ?> Versão demonstrativa: o salvamento é apenas simulado.</p>
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars($editando ? admin_url('detalhes-livro') . '?id=' . (int)$livroForm['id'] : admin_url('acervo')) ?>">Cancelar</a>
        <?php if ($editando): ?>
            <button type="submit" class="adm-button adm-button--primary" name="acao" value="salvar" data-submit><?= icon('check') ?> <span data-submit-label>Salvar alterações</span></button>
        <?php else: ?>
            <button type="submit" class="adm-button adm-button--ghost" name="acao" value="salvar" data-submit><?= icon('check') ?> <span data-submit-label>Salvar livro</span></button>
            <button type="submit" class="adm-button adm-button--primary" name="acao" value="salvar_exemplares" data-submit><?= icon('book-copy') ?> <span data-submit-label>Salvar e adicionar exemplares</span></button>
        <?php endif; ?>
    </div>
</form>
