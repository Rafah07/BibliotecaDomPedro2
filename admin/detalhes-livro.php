<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';

$livro = acervo_livro((int)($_GET['id'] ?? 0));

admin_page_start('acervo', $livro ? $livro['titulo'] : 'Livro não encontrado', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261027', 'assets/css/classificacao.css?v=20261027']);

echo acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Detalhes']]);

if (!$livro): ?>
    <section class="adm-card adm-wip">
        <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon('book-x') ?></span>
        <h1>Livro não encontrado</h1>
        <p>O cadastro solicitado não existe ou foi arquivado.</p>
        <div class="adm-wip__actions">
            <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('acervo')) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
        </div>
    </section>
    <?php
    admin_page_end();
    return;
endif;

$id = $livro['id'];
$exemplares = acervo_exemplares($id);
$movimentacoes = acervo_movimentacoes($id);
$conferencias = acervo_conferencias($id);
$situacoes = $livro['exemplares_por_situacao'];
$emprestados = $situacoes['emprestado'] ?? 0;
$manutencao = $situacoes['manutencao'] ?? 0;
$outras = $livro['total_exemplares'] - $livro['disponiveis'] - $emprestados - $manutencao;
$planilha = $livro['planilha'];
$precisaConferir = in_array($livro['status_validacao'], ['aguardando_conferencia', 'com_divergencia'], true);

$kpis = [
    ['titulo' => 'Exemplares identificados', 'valor' => $livro['total_exemplares'], 'icone' => 'book-copy', 'tom' => 'blue'],
    ['titulo' => 'Disponíveis', 'valor' => $livro['disponiveis'], 'icone' => 'circle-check', 'tom' => 'green'],
    ['titulo' => 'Emprestados', 'valor' => $emprestados, 'icone' => 'book-open-check', 'tom' => 'indigo'],
    ['titulo' => 'Em manutenção', 'valor' => $manutencao, 'icone' => 'wrench', 'tom' => 'amber'],
    ['titulo' => 'Outras situações', 'valor' => $outras, 'icone' => 'circle-alert', 'tom' => 'red', 'dica' => 'Extraviados, baixados ou aguardando identificação'],
];
$meta = [
    'Categoria' => implode(', ', $livro['categorias_nomes']),
    'ISBN' => $livro['isbn'],
    'Editora' => $livro['editora'],
    'Ano' => $livro['ano_publicacao'],
    'Edição' => $livro['edicao'],
    'Idioma' => $livro['idioma'],
    'Páginas' => $livro['numero_paginas'],
    'Origem' => ACERVO_ORIGENS[$livro['origem_cadastro']],
];
?>

<section class="adm-card adm-book-hero">
    <div class="adm-book-hero__cover"><?= acervo_capa($livro, 'lg') ?></div>
    <div class="adm-book-hero__body">
        <div class="adm-book-hero__tags">
            <?= acervo_badge_status($livro['status_validacao']) ?>
            <span class="adm-demo-tag">Dados demonstrativos</span>
        </div>
        <h1><?= htmlspecialchars($livro['titulo']) ?></h1>
        <?php if ($livro['subtitulo']): ?><p class="adm-book-hero__subtitle"><?= htmlspecialchars($livro['subtitulo']) ?></p><?php endif; ?>
        <p class="adm-book-hero__author">por <strong><?= htmlspecialchars(implode(', ', $livro['autores_nomes']) ?: 'Autor não informado') ?></strong></p>
        <dl class="adm-meta">
            <?php foreach ($meta as $rotulo => $valor): ?>
                <div>
                    <dt><?= $rotulo ?></dt>
                    <dd<?= $rotulo === 'ISBN' ? ' class="adm-mono"' : '' ?>><?= $valor !== null && $valor !== '' ? htmlspecialchars((string)$valor) : '<span class="adm-missing">Não informado</span>' ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <div class="adm-book-hero__actions">
            <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('editar-livro') . '?id=' . $id) ?>"><?= icon('pencil') ?> Editar livro</a>
            <button type="button" class="adm-button adm-button--ghost" data-add-exemplares="<?= $id ?>"><?= icon('plus') ?> Adicionar exemplares</button>
            <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('acervo')) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
        </div>
    </div>
</section>

<?php if ($precisaConferir): ?>
    <section class="adm-notice adm-notice--amber adm-notice--action" aria-labelledby="aviso-conferencia">
        <?= icon('clipboard-check') ?>
        <div class="adm-notice__content">
            <strong id="aviso-conferencia">
                <?= $livro['status_validacao'] === 'com_divergencia'
                    ? 'A última conferência encontrou diferença entre o registro e o acervo físico.'
                    : 'Este registro ainda precisa passar pela conferência física do acervo.' ?>
            </strong>
            <dl class="adm-notice__numbers">
                <div><dt>Situação do cadastro</dt><dd><?= htmlspecialchars(ACERVO_STATUS[$livro['status_validacao']]['label']) ?></dd></div>
                <div><dt>Quantidade informada na planilha</dt><dd><?= $planilha ? (int)$planilha['quantidade_informada'] : '—' ?></dd></div>
                <div><dt>Exemplares identificados</dt><dd><?= $livro['total_exemplares'] ?></dd></div>
                <div><dt>Disponíveis para empréstimo</dt><dd><?= $livro['disponiveis'] ?></dd></div>
            </dl>
            <p>A quantidade da planilha serve apenas como referência e não gera exemplares disponíveis. Os exemplares são criados durante a conferência física.</p>
        </div>
        <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('inventario')) ?>"><?= icon('scan-barcode') ?> Conferir no inventário</a>
    </section>
<?php endif; ?>

<section class="adm-kpis" aria-label="Exemplares deste livro">
    <?php foreach ($kpis as $kpi): ?>
        <article class="adm-card adm-kpi"<?= isset($kpi['dica']) ? ' title="' . htmlspecialchars($kpi['dica']) . '"' : '' ?>>
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $kpi['tom'] ?>"><?= icon($kpi['icone']) ?></span>
            <span>
                <strong class="adm-kpi__value" data-kpi="<?= htmlspecialchars($kpi['titulo']) ?>"><?= $kpi['valor'] ?></strong>
                <span class="adm-kpi__title"><?= htmlspecialchars($kpi['titulo']) ?></span>
            </span>
        </article>
    <?php endforeach; ?>
</section>

<?php include __DIR__ . '/includes/secao-classificacao.php'; ?>

<section class="adm-card adm-panel" data-tabs>
    <div class="adm-tabs" role="tablist" aria-label="Informações do livro">
        <button type="button" role="tab" id="aba-informacoes" aria-controls="painel-informacoes" aria-selected="true" data-tab="informacoes"><?= icon('file-text') ?> Informações</button>
        <button type="button" role="tab" id="aba-exemplares" aria-controls="painel-exemplares" aria-selected="false" tabindex="-1" data-tab="exemplares"><?= icon('book-copy') ?> Exemplares <span class="adm-tabs__count" data-exemplares-count><?= count($exemplares) ?></span></button>
        <button type="button" role="tab" id="aba-movimentacoes" aria-controls="painel-movimentacoes" aria-selected="false" tabindex="-1" data-tab="movimentacoes"><?= icon('history') ?> Movimentações</button>
        <button type="button" role="tab" id="aba-inventario" aria-controls="painel-inventario" aria-selected="false" tabindex="-1" data-tab="inventario"><?= icon('clipboard-check') ?> Inventário</button>
    </div>

    <div class="adm-tabpanel" role="tabpanel" id="painel-informacoes" aria-labelledby="aba-informacoes">
        <div class="adm-info-grid">
            <div>
                <h3 class="adm-subtitle">Sinopse</h3>
                <?php if ($livro['sinopse']): ?>
                    <p class="adm-prose"><?= nl2br(htmlspecialchars($livro['sinopse'])) ?></p>
                <?php else: ?>
                    <p class="adm-missing">Sinopse não cadastrada.</p>
                <?php endif; ?>

                <h3 class="adm-subtitle">Palavras-chave</h3>
                <?php $palavras = array_filter(array_map('trim', explode(',', $livro['palavras_chave']))); ?>
                <?php if ($palavras): ?>
                    <ul class="adm-chips"><?php foreach ($palavras as $p): ?><li class="adm-chip"><?= htmlspecialchars($p) ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p class="adm-missing">Nenhuma palavra-chave.</p>
                <?php endif; ?>
            </div>
            <div>
                <div class="adm-internal-note">
                    <h3 class="adm-subtitle"><?= icon('shield-alert') ?> Observações internas</h3>
                    <p><?= $livro['observacoes_internas'] ? htmlspecialchars($livro['observacoes_internas']) : '<span class="adm-missing">Sem observações.</span>' ?></p>
                    <small>Visível apenas para administradores.</small>
                </div>
                <dl class="adm-meta adm-meta--stack">
                    <div><dt>Cadastrado em</dt><dd><?= acervo_data($livro['criado_em']) ?></dd></div>
                    <div><dt>Todas as categorias</dt><dd><?= htmlspecialchars(implode(', ', $livro['categorias_nomes'])) ?></dd></div>
                    <div><dt>Código interno</dt><dd class="adm-mono">LIV-<?= sprintf('%05d', $id) ?></dd></div>
                </dl>
            </div>
        </div>
    </div>

    <div class="adm-tabpanel" role="tabpanel" id="painel-exemplares" aria-labelledby="aba-exemplares" hidden>
        <div class="adm-tabpanel__header">
            <p>Cada linha é uma unidade física com código próprio.</p>
            <button type="button" class="adm-button adm-button--primary adm-button--sm" data-add-exemplares="<?= $id ?>"><?= icon('plus') ?> Adicionar exemplares</button>
        </div>
        <div class="adm-table-wrap adm-table-wrap--inset">
            <table class="adm-table adm-table--cards">
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Localização</th>
                        <th scope="col">Conservação</th>
                        <th scope="col">Situação</th>
                        <th scope="col">Última conferência</th>
                        <th scope="col" class="adm-actions-col">Ações</th>
                    </tr>
                </thead>
                <tbody data-exemplares-rows data-livro-id="<?= $id ?>">
                    <?php foreach ($exemplares as $ex): ?>
                        <?php $url = admin_url('detalhes-exemplar') . '?codigo=' . $ex['codigo_individual']; ?>
                        <tr>
                            <td class="adm-td-title"><a class="adm-code" href="<?= htmlspecialchars($url) ?>"><?= $ex['codigo_individual'] ?></a></td>
                            <td data-label="Localização"><?= htmlspecialchars(acervo_local_texto($ex['localizacao'])) ?></td>
                            <td data-label="Conservação"><?= acervo_badge_conservacao($ex['estado_conservacao']) ?></td>
                            <td data-label="Situação"><?= acervo_badge_situacao($ex['situacao']) ?></td>
                            <td data-label="Última conferência" class="adm-table__muted"><?= $ex['conferido_em'] ? acervo_data($ex['conferido_em']) : '<span class="adm-missing">Não conferido</span>' ?></td>
                            <td class="adm-actions-col"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars($url) ?>"><?= icon('eye') ?> Ver</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr data-empty<?= $exemplares ? ' hidden' : '' ?>>
                        <td colspan="6">
                            <div class="adm-empty">
                                <?= icon('book-copy') ?>
                                <strong>Nenhum exemplar identificado</strong>
                                <span><?= $planilha ? 'A planilha informa ' . (int)$planilha['quantidade_informada'] . ' unidades, mas nenhuma foi conferida fisicamente.' : 'Cadastre as unidades físicas para que o livro possa ser emprestado.' ?></span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="adm-tabpanel" role="tabpanel" id="painel-movimentacoes" aria-labelledby="aba-movimentacoes" hidden>
        <?php if (!$movimentacoes): ?>
            <div class="adm-empty">
                <?= icon('history') ?>
                <strong>Sem movimentações</strong>
                <span>Empréstimos, devoluções e mudanças dos exemplares aparecerão aqui.</span>
            </div>
        <?php else: ?>
            <ol class="adm-timeline">
                <?php foreach ($movimentacoes as $mov): ?>
                    <?php $tipo = ACERVO_MOVIMENTOS[$mov['tipo']]; ?>
                    <li>
                        <span class="adm-icon-box adm-icon-box--xs adm-tone--<?= $tipo['tom'] ?>"><?= icon($tipo['icone']) ?></span>
                        <div>
                            <p><strong><?= $tipo['label'] ?></strong> · <a class="adm-code" href="<?= htmlspecialchars(admin_url('detalhes-exemplar') . '?codigo=' . $mov['codigo']) ?>"><?= $mov['codigo'] ?></a></p>
                            <p class="adm-timeline__desc"><?= htmlspecialchars($mov['descricao']) ?></p>
                            <small><?= acervo_data($mov['data']) ?> · <?= htmlspecialchars($mov['responsavel']) ?></small>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>

    <div class="adm-tabpanel" role="tabpanel" id="painel-inventario" aria-labelledby="aba-inventario" hidden>
        <div class="adm-info-grid">
            <div>
                <h3 class="adm-subtitle">Últimas conferências físicas</h3>
                <?php if (!$conferencias): ?>
                    <div class="adm-empty adm-empty--left">
                        <?= icon('clipboard-check') ?>
                        <strong>Ainda não conferido</strong>
                        <span>Nenhuma conferência física foi registrada para este título.</span>
                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars(admin_url('inventario')) ?>"><?= icon('scan-barcode') ?> Conferir no inventário</a>
                    </div>
                <?php else: ?>
                    <ul class="adm-checks">
                        <?php foreach ($conferencias as $c): ?>
                            <?php $ok = $c['encontrados'] === $c['esperado']; ?>
                            <li class="adm-check<?= $ok ? '' : ' adm-check--warn' ?>">
                                <div class="adm-check__top">
                                    <strong><?= acervo_data($c['data']) ?></strong>
                                    <span class="adm-status adm-tone--<?= $ok ? 'green' : 'amber' ?>"><?= $ok ? 'Quantidade confere' : 'Divergência' ?></span>
                                </div>
                                <p>Esperados: <strong><?= $c['esperado'] ?></strong> · Encontrados: <strong><?= $c['encontrados'] ?></strong></p>
                                <?php if ($c['observacao']): ?><p class="adm-table__muted"><?= htmlspecialchars($c['observacao']) ?></p><?php endif; ?>
                                <small><?= htmlspecialchars($c['responsavel']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="adm-subtitle">Registro da planilha</h3>
                <?php if ($planilha): ?>
                    <dl class="adm-meta adm-meta--stack adm-meta--boxed">
                        <div><dt>Arquivo</dt><dd><?= htmlspecialchars($planilha['arquivo']) ?> (linha <?= (int)$planilha['linha'] ?>)</dd></div>
                        <div><dt>Importado em</dt><dd><?= acervo_data($planilha['importado_em']) ?></dd></div>
                        <div><dt>Quantidade informada</dt><dd><?= (int)$planilha['quantidade_informada'] ?></dd></div>
                        <div><dt>Exemplares identificados</dt><dd><?= $livro['total_exemplares'] ?></dd></div>
                    </dl>
                    <p class="adm-side-note"><?= icon('info') ?> A quantidade da planilha é guardada separadamente e não se transforma em exemplares disponíveis.</p>
                <?php else: ?>
                    <p class="adm-missing">Este livro não veio de uma planilha importada.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php
$livroModal = $livro;
include __DIR__ . '/includes/modal-exemplares.php';
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261027']);
