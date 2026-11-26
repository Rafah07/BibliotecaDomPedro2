<?php
/** Histórico completo e imutável da reorganização (com exportação CSV). */
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/csv.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$r = reorg();
$f = [
    'lote_id' => (int)($_GET['lote_id'] ?? 0) ?: null,
    'sessao_id' => (int)($_GET['sessao_id'] ?? 0) ?: null,
    'acao' => preg_match('/^[a-z0-9_]{1,60}$/', (string)($_GET['acao'] ?? '')) ? $_GET['acao'] : '',
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
];
if (($_GET['exportar'] ?? '') === 'csv') {
    $linhas = array_map(fn ($h) => [$h['id'], $h['criado_em'], $h['lote_nome'] ?? '', $h['acao'], $h['descricao'], $h['nome'] ?? '', $h['sessao_id'] ? Reorganizacao::rotuloSessao($h['sessao_id']) : '', $h['admin_nome'] ?? '', $h['detalhes'] ?? ''],
        $r->historicoLista($f, 100000));
    reorg_csv('historico-reorganizacao-' . date('Y-m-d') . '.csv', ['Nº', 'Data e hora', 'Lote', 'Ação', 'Descrição', 'Participante (nome informado)', 'Sessão', 'Administrador', 'Detalhes (JSON)'], $linhas);
}
$lista = $r->historicoLista($f, 500);
$acoes = db_query('SELECT DISTINCT acao FROM reorg_historico ORDER BY acao')->fetchAll(PDO::FETCH_COLUMN);

reorg_admin_inicio('historico', 'Histórico');
?>
<section class="adm-page-head"><div><h1>Histórico</h1><p>Registro permanente de tudo o que aconteceu (não pode ser alterado nem apagado). Os nomes dos participantes são os informados na entrada, sem verificação de identidade.</p></div>
    <div class="adm-page-head__actions"><a class="adm-button" href="?<?= e(http_build_query(array_filter($f) + ['exportar' => 'csv'])) ?>"><?= icon('download') ?> Exportar CSV</a></div></section>
<form class="adm-card adm-filters" method="get">
    <label class="adm-field"><span class="adm-field__label">Lote</span><select class="adm-select" name="lote_id"><option value="">Todos</option><?= reorg_opcoes($r->lotes(), $f['lote_id']) ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Ação</span><select class="adm-select" name="acao"><option value="">Todas</option><?php foreach ($acoes as $a): ?><option<?= $f['acao'] === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Pesquisar</span><input class="adm-input" name="q" value="<?= e($f['q']) ?>" placeholder="Nome, código BDP, descrição"></label>
    <?php if ($f['sessao_id']): ?><input type="hidden" name="sessao_id" value="<?= (int)$f['sessao_id'] ?>"><?php endif; ?>
    <button class="adm-button" type="submit"><?= icon('search') ?> Filtrar</button>
</form>
<section class="adm-card adm-panel">
    <?= $f['sessao_id'] ? '<p>Mostrando a sessão <strong>' . e(Reorganizacao::rotuloSessao($f['sessao_id'])) . '</strong>. <a href="?">Ver tudo</a></p>' : '' ?>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Data e hora</th><th>Lote</th><th>Ação</th><th>Descrição</th><th>Quem</th></tr></thead>
        <tbody>
        <?php if (!$lista): ?><tr><td colspan="5" class="adm-table__muted">Nada encontrado.</td></tr><?php endif; ?>
        <?php foreach ($lista as $h): ?>
            <tr><td><small><?= e(admin_data_hora($h['criado_em'])) ?></small></td><td><small><?= e($h['lote_nome'] ?? '—') ?></small></td><td><small class="adm-mono"><?= e($h['acao']) ?></small></td>
                <td><?= e($h['descricao']) ?></td>
                <td><small><?= $h['nome'] ? e($h['nome']) . ' <a class="adm-mono" href="?sessao_id=' . (int)$h['sessao_id'] . '">' . e(Reorganizacao::rotuloSessao($h['sessao_id'])) . '</a>' : '' ?><?= $h['admin_nome'] ? ($h['nome'] ? ' · ' : '') . e($h['admin_nome']) : '' ?></small></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (count($lista) === 500): ?><p class="adm-field__hint">Mostrando os 500 mais recentes. Use os filtros ou exporte o CSV para ver tudo.</p><?php endif; ?>
</section>
<?php reorg_admin_fim();
