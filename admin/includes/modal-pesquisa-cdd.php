<?php
/** Modal "Pesquisar CDD": consulta livre às fontes, sem gravar nada. Usado no painel de classificação. */
$fontesModal = cdd_fontes_automaticas();
?>
<dialog class="adm-modal adm-modal--wide" id="modal-pesquisa-cdd" aria-labelledby="modal-pesquisa-cdd-titulo">
    <form class="adm-modal__box" method="dialog" data-pesquisa-livre novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('search') ?></span>
            <div>
                <h2 id="modal-pesquisa-cdd-titulo">Pesquisar CDD</h2>
                <p>Consulta as fontes bibliográficas. Nada é gravado: para registrar uma sugestão, abra o livro.</p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <div class="adm-form-grid">
                <label class="adm-field"><span class="adm-field__label">ISBN</span><input class="adm-input adm-mono" name="termos[isbn]" inputmode="numeric" maxlength="20" placeholder="978..."></label>
                <label class="adm-field"><span class="adm-field__label">Título</span><input class="adm-input" name="termos[titulo]" maxlength="200"></label>
                <label class="adm-field"><span class="adm-field__label">Autor</span><input class="adm-input" name="termos[autor]" maxlength="200"></label>
                <label class="adm-field"><span class="adm-field__label">Editora</span><input class="adm-input" name="termos[editora]" maxlength="200"></label>
                <label class="adm-field"><span class="adm-field__label">Ano</span><input class="adm-input" name="termos[ano]" inputmode="numeric" maxlength="4"></label>
                <label class="adm-field"><span class="adm-field__label">Edição</span><input class="adm-input" name="termos[edicao]" maxlength="100"></label>
                <label class="adm-field adm-field--full"><span class="adm-field__label">Assunto</span><input class="adm-input" name="termos[assunto]" maxlength="200" placeholder="Usado só quando não há ISBN nem título"></label>
            </div>
            <p class="adm-field__hint">Com ISBN, a busca é feita pelo ISBN. Sem ele, por título e autor; editora, ano e edição ajudam a conferir se o registro é a mesma edição.</p>
            <div class="adm-fontes-inline">
                <?php foreach ($fontesModal as $id => $f): ?>
                    <label><input type="checkbox" name="fontes[]" value="<?= $id ?>" checked> <?= htmlspecialchars($f['nome']) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="adm-cdd-results" data-resultados aria-live="polite"></div>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Fechar</button>
            <button type="submit" class="adm-button adm-button--primary"><?= icon('search') ?> Pesquisar</button>
        </footer>
    </form>
</dialog>
