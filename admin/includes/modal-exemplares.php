<?php
/**
 * Modal "Adicionar exemplares": cadastra um ou mais exemplares físicos de um livro.
 * Opcional: $livroModal (array) para deixar o livro já selecionado.
 *
 * Na versão demonstrativa, os códigos são gerados no navegador e NÃO são gravados.
 * Na versão real, a geração deve ocorrer no servidor, dentro de uma transação
 * (ver a função gerar_exemplares em admin/database/acervo.sql).
 */
$livroModal = $livroModal ?? null;
$livrosModal = acervo_livros();
uasort($livrosModal, fn ($a, $b) => strcoll(acervo_texto_busca($a['titulo']), acervo_texto_busca($b['titulo'])) ?: $a['id'] <=> $b['id']);
$setores = array_values(array_unique(array_column(acervo_fonte('localizacoes'), 'setor')));
?>
<dialog class="adm-modal" id="modal-exemplares" aria-labelledby="modal-exemplares-titulo">
    <form class="adm-modal__box" method="dialog" data-exemplares-form novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('book-copy') ?></span>
            <div>
                <h2 id="modal-exemplares-titulo">Adicionar exemplares</h2>
                <p>Cada unidade física recebe um código próprio e único.</p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>

        <div class="adm-modal__body" data-step="form">
            <div class="adm-form-grid">
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Livro relacionado <span class="adm-required" aria-hidden="true">*</span></span>
                    <select class="adm-select" name="livro_id" required data-livro-select>
                        <option value="">Selecione o cadastro bibliográfico</option>
                        <?php foreach ($livrosModal as $l): ?>
                            <option value="<?= $l['id'] ?>"<?= $livroModal && $livroModal['id'] === $l['id'] ? ' selected' : '' ?>>
                                <?= htmlspecialchars($l['titulo'] . ' — ' . $l['autor_principal'] . ($l['editora'] ? ' (' . $l['editora'] . ($l['ano_publicacao'] ? ', ' . $l['ano_publicacao'] : '') . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="adm-field__error" data-error-for="livro_id"></span>
                </label>

                <label class="adm-field">
                    <span class="adm-field__label">Quantidade de exemplares <span class="adm-required" aria-hidden="true">*</span></span>
                    <input class="adm-input" type="number" name="quantidade" min="1" max="50" value="1" required inputmode="numeric">
                    <span class="adm-field__hint">De 1 a 50 unidades por vez.</span>
                    <span class="adm-field__error" data-error-for="quantidade"></span>
                </label>

                <div class="adm-field">
                    <span class="adm-field__label">Código do exemplar</span>
                    <output class="adm-code-preview" data-code-preview>—</output>
                    <span class="adm-field__hint">Gerado automaticamente. Não pode se repetir.</span>
                </div>

                <fieldset class="adm-fieldset adm-field--full">
                    <legend class="adm-field__label">Localização <span class="adm-required" aria-hidden="true">*</span></legend>
                    <div class="adm-form-grid adm-form-grid--3">
                        <label class="adm-field">
                            <span class="adm-field__sublabel">Setor</span>
                            <select class="adm-select" name="setor" required data-local="setor">
                                <option value="">Selecione</option>
                                <?php foreach ($setores as $setor): ?>
                                    <option value="<?= htmlspecialchars($setor) ?>"><?= htmlspecialchars($setor) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field__sublabel">Estante</span>
                            <select class="adm-select" name="estante" required data-local="estante" disabled><option value="">Selecione o setor</option></select>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field__sublabel">Prateleira</span>
                            <select class="adm-select" name="prateleira" required data-local="prateleira" disabled><option value="">Selecione a estante</option></select>
                        </label>
                    </div>
                    <span class="adm-field__error" data-error-for="localizacao"></span>
                </fieldset>

                <label class="adm-field">
                    <span class="adm-field__label">Estado de conservação <span class="adm-required" aria-hidden="true">*</span></span>
                    <select class="adm-select" name="estado_conservacao" required>
                        <?php foreach (ACERVO_CONSERVACAO as $valor => $rotulo): ?>
                            <option value="<?= $valor ?>"><?= htmlspecialchars($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="adm-field">
                    <span class="adm-field__label">Situação <span class="adm-required" aria-hidden="true">*</span></span>
                    <select class="adm-select" name="situacao" required>
                        <option value="disponivel">Disponível</option>
                        <option value="manutencao">Em manutenção</option>
                        <option value="aguardando">Aguardando identificação ou validação</option>
                    </select>
                    <span class="adm-field__hint">Use "Aguardando" se o exemplar ainda não estiver pronto para circular.</span>
                </label>

                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Observações</span>
                    <textarea class="adm-textarea" name="observacoes" rows="2" maxlength="500" placeholder="Ex.: doação recebida em outubro, falta etiqueta"></textarea>
                </label>
            </div>
        </div>

        <div class="adm-modal__body" data-step="result" hidden>
            <div class="adm-notice adm-notice--blue">
                <?= icon('info') ?>
                <div>
                    <strong>Simulação concluída</strong>
                    <p>Os códigos abaixo foram gerados apenas para demonstração e <strong>não foram gravados</strong>. Na versão final, eles serão criados pelo servidor.</p>
                </div>
            </div>
            <p class="adm-result-title" data-result-title></p>
            <ul class="adm-code-list" data-result-codes></ul>
        </div>

        <footer class="adm-modal__footer" data-step="form">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--primary" data-submit>
                <?= icon('check') ?> <span data-submit-label>Salvar exemplar</span>
            </button>
        </footer>
        <footer class="adm-modal__footer" data-step="result" hidden>
            <button type="button" class="adm-button adm-button--ghost" data-exemplares-again>Adicionar mais</button>
            <button type="button" class="adm-button adm-button--primary" data-modal-close>Concluir</button>
        </footer>
    </form>
</dialog>
