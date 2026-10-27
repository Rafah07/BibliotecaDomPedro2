<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/cdd.php';

$usuario = cdd_pagina_iniciar('cdd.ver');
$data = fn (string $campo) => is_string($_GET[$campo] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$campo]) ? $_GET[$campo] : '';
$filtros = [
    'livro_id' => (int)($_GET['livro'] ?? 0) ?: null,
    'tipo' => isset(CDD_HISTORICO_TIPOS[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '',
    'responsavel_id' => (int)($_GET['responsavel'] ?? 0) ?: null,
    'de' => $data('de'),
    'ate' => $data('ate'),
    'limite' => 500,
];
$historico = cdd_historico($filtros);
$livro = $filtros['livro_id'] ? cdd_item($filtros['livro_id']) : null;
$responsaveis = cdd_responsaveis();
$v = fn ($valor) => htmlspecialchars((string)$valor);

admin_page_start('classificacao', 'Histórico CDD', 'Acervo', 'Reclassificação bibliográfica pela CDD', [
    'assets/css/acervo.css?v=20261027',
    'assets/css/classificacao.css?v=20261027',
]);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Classificação CDD', admin_url('classificacao')], ['Histórico']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Histórico de classificação</h1>
        <p>Todas as sugestões, envios, aprovações, correções e rejeições, com responsável e fonte.</p>
    </div>
</section>

<?= cdd_abas('historico-cdd') ?>
<?= cdd_aviso_modo() ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-historico">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-historico"><?= $livro ? 'Histórico de “' . $v($livro['titulo']) . '”' : 'Alterações registradas' ?></h2>
            <p><?= count($historico) ?> registro(s)<?= count($historico) >= $filtros['limite'] ? ' (mostrando os ' . $filtros['limite'] . ' mais recentes)' : '' ?>. O histórico não pode ser alterado nem excluído.</p>
        </div>
    </div>
    <form class="adm-toolbar adm-toolbar--form" method="get">
        <?php if ($livro): ?><input type="hidden" name="livro" value="<?= (int)$livro['id'] ?>"><?php endif; ?>
        <div class="adm-filters adm-filters--4">
            <label class="adm-filter"><span>Tipo</span>
                <select class="adm-select" name="tipo">
                    <option value="">Todos</option>
                    <?php foreach (CDD_HISTORICO_TIPOS as $tipo => $info): ?>
                        <option value="<?= $tipo ?>"<?= $filtros['tipo'] === $tipo ? ' selected' : '' ?>><?= $info['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>Responsável</span>
                <select class="adm-select" name="responsavel">
                    <option value="">Todos</option>
                    <?php foreach ($responsaveis as $rid => $nome): ?>
                        <option value="<?= (int)$rid ?>"<?= $filtros['responsavel_id'] === (int)$rid ? ' selected' : '' ?>><?= $v($nome) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>De</span><input class="adm-input" type="date" name="de" value="<?= $v($filtros['de']) ?>"></label>
            <label class="adm-filter"><span>Até</span><input class="adm-input" type="date" name="ate" value="<?= $v($filtros['ate']) ?>"></label>
        </div>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars(admin_url('historico-cdd')) ?>"><?= icon('filter-x') ?> Limpar</a>
    </form>

    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards">
            <thead>
                <tr>
                    <th scope="col">Data</th>
                    <th scope="col">Livro</th>
                    <th scope="col">Alteração</th>
                    <th scope="col">CDD anterior → nova</th>
                    <th scope="col">Situação</th>
                    <th scope="col">Fonte</th>
                    <th scope="col">Responsável</th>
                    <th scope="col">Justificativa / observação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historico as $h): ?>
                    <?php $tipo = CDD_HISTORICO_TIPOS[$h['tipo']]; ?>
                    <tr>
                        <td class="adm-td-title adm-nowrap"><?= cdd_data_hora($h['criado_em']) ?></td>
                        <td data-label="Livro"><a class="adm-book-link" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . (int)$h['livro_id']) ?>"><?= $v($h['titulo']) ?></a></td>
                        <td data-label="Alteração"><span class="adm-hist-type"><span class="adm-icon-box adm-icon-box--xs adm-tone--<?= $tipo['tom'] ?>"><?= icon($tipo['icone']) ?></span><?= $tipo['label'] ?></span></td>
                        <td data-label="CDD" class="adm-mono adm-nowrap"><?= cdd_numero($h['cdd_anterior']) ?> → <?= cdd_numero($h['cdd_nova']) ?></td>
                        <td data-label="Situação"><?= $h['status_novo'] && isset(CDD_STATUS[$h['status_novo']]) ? cdd_badge_status($h['status_novo']) : '—' ?></td>
                        <td data-label="Fonte" class="adm-td-wrap"><?= $h['fonte_nome'] ? $v($h['fonte_nome']) : '—' ?><?php if ($h['fonte_url']): ?> <a class="adm-link" href="<?= $v($h['fonte_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Abrir fonte"><?= icon('external-link') ?></a><?php endif; ?></td>
                        <td data-label="Responsável"><?= $v($h['responsavel'] ?? '—') ?></td>
                        <td data-label="Justificativa" class="adm-td-wrap adm-table__muted"><?= $v(trim(($h['justificativa'] ?? '') . (($h['justificativa'] ?? '') && ($h['observacao'] ?? '') ? ' — ' : '') . ($h['observacao'] ?? ''))) ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$historico): ?>
                    <tr><td colspan="8"><div class="adm-empty"><?= icon('history') ?><strong>Nenhum registro encontrado</strong><span>As alterações de classificação aparecerão aqui.</span></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_page_end();
