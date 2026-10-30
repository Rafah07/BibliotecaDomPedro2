<?php
/**
 * Localizações físicas (setor, estante, prateleira, complemento): listagem, pesquisa,
 * ativação e desativação. As localizações antigas das planilhas aparecem numa aba
 * separada, só para consulta (nunca são convertidas sozinhas).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

$usuario = fisico_iniciar();
$base = admin_url('localizacoes');
$podeGerenciar = auth_pode('localizacoes.gerenciar');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    fisico_exigir('localizacoes.gerenciar');
    $id = (int)admin_param($_POST, 'id');
    $ativar = admin_param($_POST, 'acao') === 'ativar';
    try {
        localizacoes_servico()->definirAtiva($id, $ativar, $usuario['id']);
        admin_flash('sucesso', $ativar ? 'Localização reativada.' : 'Localização desativada.',
            $ativar ? 'Ela volta a aparecer na escolha de localização dos exemplares.' : 'Os exemplares que já estão nela continuam lá. Ela só deixa de aparecer para novos exemplares.');
    } catch (AcervoException $e) {
        admin_flash('erro', 'A localização não foi alterada.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'A localização não foi alterada.', fisico_erro_tecnico($e, 'ativação de localização'));
    }
    admin_redirecionar(admin_url_filtros($base, ['aba' => admin_param($_POST, 'aba'), 'q' => admin_param($_POST, 'q'), 'situacao' => admin_param($_POST, 'situacao')]));
}

$aba = admin_param($_GET, 'aba') === 'antigas' ? 'antigas' : 'atuais';
$porPagina = (int)admin_param($_GET, 'por_pagina', '25');
$filtros = [
    'aba' => $aba === 'antigas' ? 'antigas' : '',
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
    'setor' => mb_substr(admin_param($_GET, 'setor'), 0, 80),
    'situacao' => in_array(admin_param($_GET, 'situacao'), ['inativas', 'todas'], true) ? admin_param($_GET, 'situacao') : '',
    'por_pagina' => in_array($porPagina, [25, 50, 100], true) ? (string)$porPagina : '25',
];
try {
    $resumo = localizacoes_servico()->resumo();
    $setores = localizacoes_servico()->setores();
    if ($aba === 'antigas') {
        $antigas = localizacoes_servico()->antigas($filtros['q']);
    } else {
        $resultado = localizacoes_servico()->listar(['q' => $filtros['q'], 'setor' => $filtros['setor'], 'situacao' => $filtros['situacao'] ?: 'ativas'],
            max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar as localizações', fisico_erro_tecnico($e, 'listagem de localizações'));
    exit;
}

admin_page_start('localizacoes', 'Localizações', 'Exemplares', 'Localizações físicas', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], ['Localizações']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Localizações físicas</h1>
        <p>Setor, estante, prateleira e complemento onde cada exemplar fica. Nenhum exemplar é distribuído automaticamente: a organização é definida pela biblioteca.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeGerenciar): ?><a class="adm-button adm-button--primary" href="<?= e(admin_url('localizacao')) ?>"><?= icon('plus') ?> Nova localização</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats" aria-label="Resumo das localizações">
    <?= fisico_stat('Localizações ativas', $resumo['ativas'], 'Disponíveis para os exemplares', 'map-pin', 'blue') ?>
    <?= fisico_stat('Setores', $resumo['setores'], 'Setores com localização ativa', 'layers', 'teal') ?>
    <?= fisico_stat('Desativadas', $resumo['inativas'], 'Não recebem novos exemplares', 'archive', 'muted', admin_url_filtros($base, ['situacao' => 'inativas'])) ?>
    <?= fisico_stat('Exemplares sem localização', $resumo['sem_local'], 'Localização ainda não confirmada', 'map-pin-off', 'amber', admin_url_filtros(admin_url('exemplares'), ['localizacao' => 'sem'])) ?>
</section>

<nav class="adm-tabs adm-tabs--links adm-tabs--solta" aria-label="Tipo de localização">
    <a class="adm-tab<?= $aba === 'atuais' ? ' is-active' : '' ?>" href="<?= e($base) ?>"<?= $aba === 'atuais' ? ' aria-current="page"' : '' ?>><?= icon('map-pin') ?> Localizações confirmadas</a>
    <a class="adm-tab<?= $aba === 'antigas' ? ' is-active' : '' ?>" href="<?= e($base . '?aba=antigas') ?>"<?= $aba === 'antigas' ? ' aria-current="page"' : '' ?>><?= icon('history') ?> Localizações antigas (planilhas)</a>
</nav>

<?php if ($aba === 'antigas'): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-antigas">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-antigas">Localizações informadas nas planilhas antigas</h2>
                <p>Só para consulta. Este texto histórico não é alterado e não vira localização confirmada sozinho. Confirme a localização de cada exemplar quando ele for conferido.</p>
            </div>
        </div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <input type="hidden" name="aba" value="antigas">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar localização antiga</span>
                <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Texto da localização antiga..." autocomplete="off"></label>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Pesquisar</button>
        </form>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards">
                <thead><tr><th scope="col">Localização antiga (texto da planilha)</th><th scope="col">Linhas importadas</th><th scope="col">Livros</th></tr></thead>
                <tbody>
                    <?php foreach ($antigas['itens'] as $a): ?>
                        <tr>
                            <td class="adm-td-title"><?= e($a['localizacao_antiga']) ?></td>
                            <td data-label="Linhas importadas"><?= fisico_numero((int)$a['registros']) ?></td>
                            <td data-label="Livros"><?= fisico_numero((int)$a['livros']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$antigas['itens']): ?>
                        <tr><td colspan="3"><div class="adm-empty"><?= icon('history') ?><strong>Nenhuma localização antiga encontrada.</strong><span>As planilhas importadas não informaram localização<?= $filtros['q'] !== '' ? ' com este texto' : '' ?>.</span></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($antigas['total'] > count($antigas['itens'])): ?><p class="adm-field__hint">Mostrando <?= count($antigas['itens']) ?> de <?= fisico_numero($antigas['total']) ?>. Refine a pesquisa para ver as demais.</p><?php endif; ?>
    </section>
<?php else: ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-locais">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-locais">Localizações</h2>
                <p aria-live="polite"><?= fisico_numero($resultado['total']) ?> <?= $resultado['total'] === 1 ? 'localização' : 'localizações' ?></p>
            </div>
        </div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar localizações</span>
                <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Setor, estante, prateleira ou código..." autocomplete="off"></label>
            <div class="adm-filters">
                <label class="adm-filter"><span>Setor</span>
                    <select class="adm-select" name="setor">
                        <option value="">Todos</option>
                        <?php foreach ($setores as $s): ?><option value="<?= e($s) ?>"<?= $filtros['setor'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="adm-filter"><span>Situação</span>
                    <select class="adm-select" name="situacao">
                        <option value="">Ativas</option>
                        <option value="inativas"<?= $filtros['situacao'] === 'inativas' ? ' selected' : '' ?>>Desativadas</option>
                        <option value="todas"<?= $filtros['situacao'] === 'todas' ? ' selected' : '' ?>>Todas</option>
                    </select>
                </label>
            </div>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
        </form>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards">
                <thead>
                    <tr><th scope="col">Localização</th><th scope="col">Código</th><th scope="col">Exemplares</th><th scope="col">Situação</th><th scope="col" class="adm-actions-col">Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($resultado['itens'] as $l): ?>
                        <tr>
                            <td class="adm-td-title"><?= e(fisico_local($l)) ?><?php if ($l['descricao']): ?><small class="adm-block-muted"><?= e($l['descricao']) ?></small><?php endif; ?></td>
                            <td data-label="Código" class="adm-mono"><?= $l['codigo'] ? e($l['codigo']) : '—' ?></td>
                            <td data-label="Exemplares"><a class="adm-link" href="<?= e(admin_url_filtros(admin_url('exemplares'), ['localizacao' => (int)$l['id']])) ?>"><?= fisico_numero((int)$l['exemplares']) ?></a></td>
                            <td data-label="Situação"><?= (int)$l['ativa'] ? '<span class="adm-status adm-tone--green">Ativa</span>' : '<span class="adm-status adm-tone--muted">Desativada</span>' ?></td>
                            <td class="adm-actions-col">
                                <?php if ($podeGerenciar): ?>
                                    <div class="adm-actions-inline">
                                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('localizacao') . '?id=' . (int)$l['id']) ?>"><?= icon('pencil') ?> Editar</a>
                                        <form method="post" data-confirm="<?= (int)$l['ativa'] ? 'Desativar esta localização? Os exemplares que estão nela continuam lá.' : 'Reativar esta localização?' ?>">
                                            <?= csrf_campo() ?>
                                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                            <input type="hidden" name="acao" value="<?= (int)$l['ativa'] ? 'desativar' : 'ativar' ?>">
                                            <input type="hidden" name="q" value="<?= e($filtros['q']) ?>">
                                            <input type="hidden" name="situacao" value="<?= e($filtros['situacao']) ?>">
                                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= (int)$l['ativa'] ? icon('archive') . ' Desativar' : icon('archive-restore') . ' Reativar' ?></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$resultado['itens']): ?>
                        <tr><td colspan="5"><div class="adm-empty"><?= icon('map-pin') ?>
                            <strong><?= $filtros['q'] !== '' || $filtros['setor'] !== '' || $filtros['situacao'] !== '' ? 'Nenhuma localização encontrada' : 'Nenhuma localização cadastrada ainda' ?></strong>
                            <span>Cadastre as localizações conforme a organização física definida pela biblioteca. Também é possível informar uma nova localização ao cadastrar ou conferir um exemplar.</span>
                        </div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtros) ?>
    </section>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
