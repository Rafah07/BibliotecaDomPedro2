<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';

$codigo = is_string($_GET['codigo'] ?? null) ? $_GET['codigo'] : '';
$ex = acervo_exemplar_por_codigo($codigo);

admin_page_start('exemplares', $ex ? $ex['codigo_individual'] : 'Exemplar não encontrado', 'Exemplares', 'Unidades físicas do acervo', ['assets/css/acervo.css?v=20261027']);

echo acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Exemplares', admin_url('exemplares')], ['Detalhes']]);

if (!$ex): ?>
    <section class="adm-card adm-wip">
        <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon('book-x') ?></span>
        <h1>Exemplar não encontrado</h1>
        <p>Nenhum exemplar com o código <strong><?= htmlspecialchars($codigo ?: '(vazio)') ?></strong> foi encontrado.</p>
        <div class="adm-wip__actions">
            <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('exemplares')) ?>"><?= icon('arrow-left') ?> Voltar aos exemplares</a>
        </div>
    </section>
    <?php
    admin_page_end();
    return;
endif;

$livro = $ex['livro'];
$emprestimo = acervo_emprestimo_ativo($ex);
$historico = acervo_movimentacoes($livro['id'], $ex['id']);
$bloqueado = $ex['situacao'] === 'emprestado';
$encerrado = in_array($ex['situacao'], ['baixado', 'extraviado'], true);
$setores = array_values(array_unique(array_column(demo_localizacoes(), 'setor')));

// Prévia ilustrativa de código de barras (não é um padrão legível por leitores).
$barras = '';
$x = 0;
foreach (str_split(md5($ex['codigo_individual']) . md5(strrev($ex['codigo_individual']))) as $i => $hex) {
    $largura = 1 + hexdec($hex) % 3;
    if ($i % 2 === 0) {
        $barras .= '<rect x="' . $x . '" y="0" width="' . $largura . '" height="56"/>';
    }
    $x += $largura + 1;
}
?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Exemplar</p>
        <h1 class="adm-mono"><?= $ex['codigo_individual'] ?></h1>
        <p><a class="adm-link" href="<?= htmlspecialchars(admin_url('detalhes-livro') . '?id=' . $livro['id']) ?>"><?= htmlspecialchars($livro['titulo']) ?> <?= icon('arrow-right') ?></a></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('exemplares')) ?>"><?= icon('arrow-left') ?> Voltar aos exemplares</a>
    </div>
</section>

<?php if ($emprestimo): ?>
    <div class="adm-notice adm-notice--indigo" role="note">
        <?= icon('book-open-check') ?>
        <div>
            <strong>Este exemplar está emprestado.</strong>
            <p>Empréstimo registrado em <?= acervo_data($emprestimo['data']) ?><?= isset($emprestimo['prazo']) ? ', com devolução prevista para ' . acervo_data($emprestimo['prazo']) : '' ?>. A situação só poderá ser alterada depois que a devolução for registrada.</p>
        </div>
    </div>
<?php endif; ?>

<div class="adm-columns">
    <div class="adm-columns__main">
        <section class="adm-card adm-panel" aria-labelledby="titulo-dados-exemplar">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-dados-exemplar">Informações do exemplar</h2>
                    <p>Dados da unidade física. Os dados da obra ficam no cadastro do livro.</p>
                </div>
                <span class="adm-demo-tag">Demonstrativo</span>
            </div>
            <div class="adm-copy-head">
                <?= acervo_capa($livro, 'md') ?>
                <dl class="adm-meta adm-meta--two">
                    <div><dt>Código</dt><dd class="adm-mono"><?= $ex['codigo_individual'] ?></dd></div>
                    <div><dt>Livro relacionado</dt><dd><a class="adm-book-link" href="<?= htmlspecialchars(admin_url('detalhes-livro') . '?id=' . $livro['id']) ?>"><?= htmlspecialchars($livro['titulo']) ?></a></dd></div>
                    <div><dt>Autor</dt><dd><?= htmlspecialchars(implode(', ', $livro['autores_nomes'])) ?></dd></div>
                    <div><dt>Localização</dt><dd data-field="localizacao"><?= htmlspecialchars(acervo_local_texto($ex['localizacao'])) ?></dd></div>
                    <div><dt>Estado de conservação</dt><dd data-field="conservacao"><?= acervo_badge_conservacao($ex['estado_conservacao']) ?></dd></div>
                    <div><dt>Situação atual</dt><dd data-field="situacao"><?= acervo_badge_situacao($ex['situacao']) ?></dd></div>
                    <div><dt>Data de cadastro</dt><dd><?= acervo_data($ex['criado_em']) ?></dd></div>
                    <div><dt>Última conferência</dt><dd><?= $ex['conferido_em'] ? acervo_data($ex['conferido_em']) : '<span class="adm-missing">Ainda não conferido</span>' ?></dd></div>
                    <div class="adm-meta__full"><dt>Observações</dt><dd data-field="observacoes"><?= $ex['observacoes'] ? htmlspecialchars($ex['observacoes']) : '<span class="adm-missing">Sem observações.</span>' ?></dd></div>
                </dl>
            </div>
        </section>

        <section class="adm-card adm-panel" id="historico" aria-labelledby="titulo-historico" tabindex="-1">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-historico">Histórico do exemplar</h2>
                    <p>Movimentações registradas para esta unidade.</p>
                </div>
            </div>
            <ol class="adm-timeline" data-timeline>
                <?php foreach ($historico as $mov): ?>
                    <?php $tipo = ACERVO_MOVIMENTOS[$mov['tipo']]; ?>
                    <li>
                        <span class="adm-icon-box adm-icon-box--xs adm-tone--<?= $tipo['tom'] ?>"><?= icon($tipo['icone']) ?></span>
                        <div>
                            <p><strong><?= $tipo['label'] ?></strong></p>
                            <p class="adm-timeline__desc"><?= htmlspecialchars($mov['descricao']) ?><?= isset($mov['prazo']) ? ' · devolução prevista para ' . acervo_data($mov['prazo']) : '' ?></p>
                            <small><?= acervo_data($mov['data']) ?> · <?= htmlspecialchars($mov['responsavel']) ?></small>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>

    <aside class="adm-columns__side">
        <section class="adm-card adm-panel" aria-labelledby="titulo-acoes-exemplar">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-acoes-exemplar">Ações</h2>
                    <p>Alterações simuladas nesta versão.</p>
                </div>
            </div>
            <div class="adm-action-list">
                <button type="button" class="adm-action" data-modal-open="modal-editar-exemplar"><?= icon('pencil') ?><span><strong>Editar dados</strong><small>Conservação, situação e observações</small></span></button>
                <button type="button" class="adm-action" data-modal-open="modal-localizacao"><?= icon('map-pin') ?><span><strong>Alterar localização</strong><small>Setor, estante e prateleira</small></span></button>
                <button type="button" class="adm-action" data-modal-open="modal-manutencao"<?= $bloqueado || $encerrado ? ' data-blocked="' . ($bloqueado ? 'emprestado' : 'encerrado') . '"' : '' ?>><?= icon('wrench') ?><span><strong>Registrar manutenção</strong><small>Enviar para reparo</small></span></button>
                <a class="adm-action" href="#historico" data-scroll-to="historico"><?= icon('history') ?><span><strong>Consultar histórico</strong><small><?= count($historico) ?> registros</small></span></a>
                <a class="adm-action" href="<?= htmlspecialchars(admin_url('etiquetas')) ?>"><?= icon('printer') ?><span><strong>Imprimir etiqueta</strong><small>Disponível na etapa de etiquetas</small></span></a>
                <button type="button" class="adm-action adm-action--danger" data-modal-open="modal-baixa"<?= $bloqueado || $ex['situacao'] === 'baixado' ? ' data-blocked="' . ($bloqueado ? 'emprestado' : 'baixado') . '"' : '' ?>><?= icon('package-x') ?><span><strong>Solicitar baixa</strong><small>Retirar do acervo ativo</small></span></button>
            </div>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-codigo-barras">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-codigo-barras">Código de barras</h2>
                    <p>Espaço reservado para a etiqueta.</p>
                </div>
                <span class="adm-demo-tag">Prévia</span>
            </div>
            <figure class="adm-barcode">
                <svg viewBox="0 0 <?= $x ?> 56" preserveAspectRatio="none" role="img" aria-label="Prévia ilustrativa do código de barras de <?= $ex['codigo_individual'] ?>"><?= $barras ?></svg>
                <figcaption class="adm-mono"><?= $ex['codigo_individual'] ?></figcaption>
            </figure>
            <p class="adm-side-note"><?= icon('info') ?> Ilustração apenas. O código de barras real será gerado no módulo de etiquetas.</p>
        </section>
    </aside>
</div>

<!-- Modais (todas as alterações são simuladas) -->
<dialog class="adm-modal" id="modal-editar-exemplar" aria-labelledby="titulo-modal-editar">
    <form class="adm-modal__box" method="dialog" data-simulate="editar-exemplar" novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('pencil') ?></span>
            <div><h2 id="titulo-modal-editar">Editar dados do exemplar</h2><p><?= $ex['codigo_individual'] ?> · <?= htmlspecialchars($livro['titulo']) ?></p></div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <div class="adm-form-grid">
                <label class="adm-field">
                    <span class="adm-field__label">Estado de conservação</span>
                    <select class="adm-select" name="estado_conservacao">
                        <?php foreach (ACERVO_CONSERVACAO as $valor => $rotulo): ?>
                            <option value="<?= $valor ?>"<?= $ex['estado_conservacao'] === $valor ? ' selected' : '' ?>><?= $rotulo ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="adm-field">
                    <span class="adm-field__label">Situação</span>
                    <select class="adm-select" name="situacao"<?= $bloqueado || $encerrado ? ' disabled' : '' ?>>
                        <?php foreach (ACERVO_SITUACOES as $valor => $s): ?>
                            <?php if (in_array($valor, ['emprestado', 'baixado'], true) && $ex['situacao'] !== $valor) continue; ?>
                            <option value="<?= $valor ?>"<?= $ex['situacao'] === $valor ? ' selected' : '' ?>><?= $s['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="adm-field__hint">
                        <?php if ($bloqueado): ?>
                            Bloqueado: há um empréstimo ativo. Registre a devolução primeiro.
                        <?php elseif ($encerrado): ?>
                            Exemplares baixados ou extraviados só mudam de situação por revisão do inventário.
                        <?php else: ?>
                            Empréstimos e baixas têm fluxos próprios e não são alterados por aqui.
                        <?php endif; ?>
                    </span>
                </label>
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Observações</span>
                    <textarea class="adm-textarea" name="observacoes" rows="3" maxlength="500"><?= htmlspecialchars($ex['observacoes']) ?></textarea>
                </label>
            </div>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--primary" data-submit><?= icon('check') ?> <span data-submit-label>Salvar alterações</span></button>
        </footer>
    </form>
</dialog>

<dialog class="adm-modal" id="modal-localizacao" aria-labelledby="titulo-modal-local">
    <form class="adm-modal__box" method="dialog" data-simulate="localizacao" novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--teal"><?= icon('map-pin') ?></span>
            <div><h2 id="titulo-modal-local">Alterar localização</h2><p>Atual: <?= htmlspecialchars(acervo_local_texto($ex['localizacao'])) ?></p></div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
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
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--primary" data-submit><?= icon('check') ?> <span data-submit-label>Salvar localização</span></button>
        </footer>
    </form>
</dialog>

<dialog class="adm-modal" id="modal-manutencao" aria-labelledby="titulo-modal-manutencao">
    <form class="adm-modal__box" method="dialog" data-simulate="manutencao" novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--amber"><?= icon('wrench') ?></span>
            <div><h2 id="titulo-modal-manutencao">Registrar manutenção</h2><p>O exemplar ficará indisponível até o retorno.</p></div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <div class="adm-form-grid">
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Motivo <span class="adm-required" aria-hidden="true">*</span></span>
                    <select class="adm-select" name="motivo" required>
                        <option value="">Selecione</option>
                        <option>Capa ou lombada danificada</option>
                        <option>Páginas soltas ou rasgadas</option>
                        <option>Limpeza ou higienização</option>
                        <option>Outro</option>
                    </select>
                    <span class="adm-field__error" data-error-for="motivo"></span>
                </label>
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Detalhes</span>
                    <textarea class="adm-textarea" name="detalhes" rows="2" maxlength="500"></textarea>
                </label>
            </div>
            <p class="adm-side-note"><?= icon('info') ?> As regras completas de manutenção serão integradas em uma próxima etapa.</p>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--primary" data-submit><?= icon('check') ?> <span data-submit-label>Registrar</span></button>
        </footer>
    </form>
</dialog>

<dialog class="adm-modal" id="modal-baixa" aria-labelledby="titulo-modal-baixa">
    <form class="adm-modal__box" method="dialog" data-simulate="baixa" novalidate>
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--red"><?= icon('package-x') ?></span>
            <div><h2 id="titulo-modal-baixa">Solicitar baixa</h2><p>A baixa retira o exemplar do acervo ativo, mas mantém o histórico.</p></div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <div class="adm-form-grid">
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Motivo da baixa <span class="adm-required" aria-hidden="true">*</span></span>
                    <select class="adm-select" name="motivo" required>
                        <option value="">Selecione</option>
                        <option>Danificado sem possibilidade de reparo</option>
                        <option>Extraviado</option>
                        <option>Conteúdo desatualizado</option>
                        <option>Outro</option>
                    </select>
                    <span class="adm-field__error" data-error-for="motivo"></span>
                </label>
                <label class="adm-field adm-field--full">
                    <span class="adm-field__label">Justificativa <span class="adm-required" aria-hidden="true">*</span></span>
                    <textarea class="adm-textarea" name="justificativa" rows="3" maxlength="500" required placeholder="Descreva o que aconteceu"></textarea>
                    <span class="adm-field__error" data-error-for="justificativa"></span>
                </label>
            </div>
            <p class="adm-side-note"><?= icon('info') ?> A solicitação precisará de aprovação. O fluxo completo de baixa será desenvolvido em uma próxima etapa.</p>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Cancelar</button>
            <button type="submit" class="adm-button adm-button--danger" data-submit><?= icon('package-x') ?> <span data-submit-label>Solicitar baixa</span></button>
        </footer>
    </form>
</dialog>

<?php
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261027']);
