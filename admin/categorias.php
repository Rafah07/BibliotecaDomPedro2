<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';

$categorias = acervo_categorias();
$emUso = count(array_filter($categorias, fn ($c) => $c['total_titulos'] > 0));

admin_page_start('categorias', 'Categorias', 'Categorias', 'Organização literária do acervo', ['assets/css/acervo.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Categorias']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Categorias do Acervo</h1>
        <p>Organize os gêneros e áreas usados para classificar os livros.</p>
    </div>
    <div class="adm-page-head__actions">
        <button type="button" class="adm-button adm-button--primary" data-categoria-nova><?= icon('plus') ?> Cadastrar categoria</button>
    </div>
</section>

<div class="adm-demo-banner" role="note">
    <?= icon('info') ?>
    <span><strong>Dados demonstrativos.</strong> <?= count($categorias) ?> categorias, <?= $emUso ?> com livros associados. Um livro pode ter mais de uma categoria, por isso a soma dos títulos é maior que o total do acervo. Cadastros, edições e arquivamentos são simulados.</span>
</div>

<section class="adm-card adm-panel" data-collection aria-labelledby="titulo-lista-categorias">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-lista-categorias">Categorias cadastradas</h2>
            <p data-count data-noun="categoria|categorias" aria-live="polite"><?= count($categorias) ?> categorias</p>
        </div>
        <label class="adm-field-search adm-field-search--compact">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar categorias</span>
            <input type="search" data-search placeholder="Pesquisar categoria..." autocomplete="off">
        </label>
    </div>

    <div class="adm-table-wrap">
        <table class="adm-table adm-table--cards">
            <thead>
                <tr>
                    <th scope="col"><button type="button" class="adm-th-sort" data-sort="nome">Nome da categoria <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col">Descrição</th>
                    <th scope="col" class="adm-num"><button type="button" class="adm-th-sort" data-sort="titulos">Títulos <?= icon('arrow-up-down') ?></button></th>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody data-rows>
                <?php foreach ($categorias as $cat): ?>
                    <tr data-row data-categoria-id="<?= $cat['id'] ?>"
                        data-search="<?= htmlspecialchars(acervo_texto_busca($cat['nome'], $cat['descricao'])) ?>"
                        data-sort-nome="<?= htmlspecialchars(acervo_texto_busca($cat['nome'])) ?>"
                        data-sort-titulos="<?= $cat['total_titulos'] ?>">
                        <td class="adm-td-title">
                            <span class="adm-type">
                                <span class="adm-icon-box adm-icon-box--xs adm-tone--blue"><?= icon('tags') ?></span>
                                <strong data-cat-nome><?= htmlspecialchars($cat['nome']) ?></strong>
                            </span>
                        </td>
                        <td data-label="Descrição" class="adm-td-wrap adm-table__muted" data-cat-descricao><?= htmlspecialchars($cat['descricao']) ?></td>
                        <td data-label="Títulos" class="adm-num">
                            <?php if ($cat['total_titulos'] > 0): ?>
                                <a class="adm-count-link" href="<?= htmlspecialchars(admin_url('acervo') . '?categoria=' . $cat['id']) ?>"><?= $cat['total_titulos'] ?></a>
                            <?php else: ?>
                                <span class="adm-table__muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="adm-actions-col">
                            <div class="adm-row-actions">
                                <button type="button" class="adm-icon-button adm-icon-button--sm" data-categoria-editar="<?= $cat['id'] ?>" aria-label="Editar <?= htmlspecialchars($cat['nome']) ?>" title="Editar categoria"><?= icon('pencil') ?></button>
                                <a class="adm-icon-button adm-icon-button--sm" href="<?= htmlspecialchars(admin_url('acervo') . '?categoria=' . $cat['id']) ?>" aria-label="Consultar livros de <?= htmlspecialchars($cat['nome']) ?>" title="Consultar livros relacionados"><?= icon('library-big') ?></a>
                                <button type="button" class="adm-icon-button adm-icon-button--sm adm-icon-button--danger" data-categoria-arquivar="<?= $cat['id'] ?>" aria-label="Arquivar <?= htmlspecialchars($cat['nome']) ?>" title="Arquivar categoria"><?= icon('archive') ?></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr data-empty hidden>
                    <td colspan="4">
                        <div class="adm-empty">
                            <?= icon('tags') ?>
                            <strong>Nenhuma categoria encontrada</strong>
                            <span>Tente outro termo ou cadastre uma nova categoria.</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<dialog class="adm-modal" id="modal-categoria" aria-labelledby="titulo-modal-categoria">
    <form class="adm-modal__box" method="dialog" data-categoria-form novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('tags') ?></span>
            <div><h2 id="titulo-modal-categoria" data-categoria-titulo>Cadastrar categoria</h2><p>As categorias aparecem no cadastro de livros e nos filtros.</p></div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <input type="hidden" name="id">
            <div class="adm-form-grid">
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Nome da categoria <span class="adm-required" aria-hidden="true">*</span></span>
                    <input class="adm-input" type="text" name="nome" maxlength="80" required placeholder="Ex.: Literatura africana">
                    <span class="adm-field__error" data-error-for="nome"></span>
                </label>
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Descrição</span>
                    <textarea class="adm-textarea" name="descricao" rows="3" maxlength="255" placeholder="Explique quais livros entram nesta categoria"></textarea>
                </label>
            </div>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--primary" data-submit><?= icon('check') ?> <span data-submit-label>Salvar categoria</span></button>
        </footer>
    </form>
</dialog>

<dialog class="adm-modal" id="modal-arquivar-categoria" aria-labelledby="titulo-modal-arq-cat">
    <form class="adm-modal__box" method="dialog" data-categoria-arquivar-form novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--amber"><?= icon('archive') ?></span>
            <div><h2 id="titulo-modal-arq-cat">Arquivar categoria</h2><p data-arq-cat-nome></p></div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <input type="hidden" name="id">
            <div data-arq-cat-uso>
                <div class="adm-notice adm-notice--amber">
                    <?= icon('triangle-alert') ?>
                    <div>
                        <strong>Esta categoria ainda está associada a <span data-arq-cat-total></span>.</strong>
                        <p>Antes de arquivá-la, escolha para qual categoria esses livros serão movidos.</p>
                    </div>
                </div>
                <label class="adm-field">
                    <span class="adm-field__label">Mover os livros para <span class="adm-required" aria-hidden="true">*</span></span>
                    <select class="adm-select" name="destino" required data-msg="Escolha para qual categoria os livros serão movidos." data-arq-cat-destino></select>
                    <span class="adm-field__error" data-error-for="destino"></span>
                </label>
            </div>
            <p data-arq-cat-livre>Esta categoria não possui livros associados. Ela deixará de aparecer nos formulários, mas poderá ser restaurada depois.</p>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--danger" data-submit><?= icon('archive') ?> <span data-submit-label>Arquivar categoria</span></button>
        </footer>
    </form>
</dialog>

<?php
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261027']);
