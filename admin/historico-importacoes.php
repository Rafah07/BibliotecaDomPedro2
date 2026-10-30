<?php
/** Histórico de importações: todas as planilhas enviadas, inclusive as canceladas. */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/importacao.php';
require_once __DIR__ . '/includes/acervo.php';

if (db_modo() !== 'mysql') {
    admin_pagina_erro('Importação indisponível', 'Configure o banco MySQL para consultar o histórico de importações.', 'history');
    exit;
}
try {
    db();
} catch (DbIndisponivel $e) {
    admin_pagina_erro('Banco de dados indisponível', $e->getMessage(), 'triangle-alert');
    exit;
}
auth_exigir('importacao.ver');

$filtroStatus = is_string($_GET['status'] ?? null) && isset(IMPORTACAO_STATUS[$_GET['status']]) ? $_GET['status'] : '';
$importacoes = importacao_repo()->listar(['status' => $filtroStatus, 'limite' => 500]);
$relatorio = fn (int $id, string $tipo) => admin_base() . 'api/importacoes/relatorio.php?tipo=' . $tipo . '&id=' . $id;
$detalhes = fn (int $id, string $filtro = '') => admin_url('detalhes-importacao') . '?id=' . $id . ($filtro ? '&filtro=' . $filtro : '') . ($filtro ? '#revisao' : '');

admin_page_start('importar', 'Histórico de importações', 'Importação de Planilhas', 'Acervo · Planilhas antigas da biblioteca', ['assets/css/acervo.css?v=20261027', 'assets/css/importacao.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Importação', admin_url('importar')], ['Histórico']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Histórico de importações</h1>
        <p>Todas as planilhas enviadas ficam registradas, inclusive as canceladas, com os dados originais de cada linha.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('importar') . '#nova-importacao') ?>"><?= icon('file-up') ?> Nova importação</a>
    </div>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-historico">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-historico">Importações</h2>
            <p><?= count($importacoes) ?> <?= count($importacoes) === 1 ? 'importação' : 'importações' ?><?= $filtroStatus ? ' com a situação "' . htmlspecialchars(IMPORTACAO_STATUS[$filtroStatus]['label']) . '"' : '' ?></p>
        </div>
        <form method="get" class="adm-imp-filter">
            <label class="adm-filter">
                <span>Situação</span>
                <select class="adm-select adm-select--sm" name="status" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php foreach (IMPORTACAO_STATUS as $valor => $s): ?>
                        <option value="<?= $valor ?>"<?= $filtroStatus === $valor ? ' selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button class="adm-button adm-button--ghost adm-button--sm" type="submit">Filtrar</button></noscript>
        </form>
    </div>

    <?php if (!$importacoes): ?>
        <div class="adm-empty">
            <span class="adm-icon-box adm-icon-box--lg adm-tone--blue"><?= icon('history') ?></span>
            <strong><?= $filtroStatus ? 'Nenhuma importação com esta situação.' : 'Nenhuma planilha foi enviada ainda.' ?></strong>
            <p>As importações aparecem aqui assim que um arquivo é enviado.</p>
        </div>
    <?php else: ?>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards">
                <thead>
                    <tr>
                        <th scope="col">Nome do arquivo</th>
                        <th scope="col">Data</th>
                        <th scope="col">Responsável</th>
                        <th scope="col" class="adm-num">Linhas</th>
                        <th scope="col" class="adm-num">Importados</th>
                        <th scope="col" class="adm-num">Erros</th>
                        <th scope="col">Situação</th>
                        <th scope="col" class="adm-actions-col">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($importacoes as $imp): ?>
                        <?php $id = (int)$imp['id']; $gravados = (int)$imp['total_importados'] + (int)$imp['total_vinculados']; ?>
                        <tr>
                            <td data-label="Arquivo" class="adm-td-wrap">
                                <a class="adm-book-link" href="<?= htmlspecialchars($detalhes($id)) ?>"><strong><?= htmlspecialchars($imp['nome_arquivo']) ?></strong></a>
                                <span class="adm-block-muted">Nº <?= $id ?> · <?= importacao_tamanho((int)$imp['tamanho_bytes']) ?> · <?= strtoupper($imp['tipo_arquivo']) ?></span>
                            </td>
                            <td data-label="Data"><?= importacao_data($imp['criado_em']) ?></td>
                            <td data-label="Responsável"><?= htmlspecialchars($imp['usuario_nome'] ?? '—') ?></td>
                            <td data-label="Linhas" class="adm-num"><?= number_format((int)$imp['total_linhas'], 0, ',', '.') ?></td>
                            <td data-label="Importados" class="adm-num" title="<?= (int)$imp['total_importados'] ?> livros criados e <?= (int)$imp['total_vinculados'] ?> linhas vinculadas a livros existentes"><?= number_format($gravados, 0, ',', '.') ?></td>
                            <td data-label="Erros" class="adm-num"><?= number_format((int)$imp['total_erros'] + (int)$imp['total_falhas'], 0, ',', '.') ?></td>
                            <td data-label="Situação"><?= importacao_badge_status($imp['status']) ?></td>
                            <td data-label="Ações" class="adm-actions-col">
                                <div class="adm-row-actions adm-imp-row-actions">
                                    <a class="adm-icon-button adm-icon-button--sm" href="<?= htmlspecialchars($detalhes($id)) ?>" data-tooltip="Consultar dados" aria-label="Consultar dados"><?= icon('eye') ?></a>
                                    <a class="adm-icon-button adm-icon-button--sm" href="<?= htmlspecialchars($relatorio($id, 'completo')) ?>" data-tooltip="Baixar relatório" aria-label="Baixar relatório"><?= icon('download') ?></a>
                                    <a class="adm-icon-button adm-icon-button--sm" href="<?= htmlspecialchars($detalhes($id, 'problemas')) ?>" data-tooltip="Ver inconsistências" aria-label="Ver inconsistências"><?= icon('triangle-alert') ?></a>
                                    <?php if ($gravados > 0): ?>
                                        <a class="adm-icon-button adm-icon-button--sm" href="<?= htmlspecialchars($detalhes($id, 'importado')) ?>" data-tooltip="Consultar registros importados" aria-label="Consultar registros importados"><?= icon('list-checks') ?></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
admin_page_end();
