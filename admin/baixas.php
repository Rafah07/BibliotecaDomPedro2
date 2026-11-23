<?php
/**
 * Exemplares → Baixas do acervo (migração 190): consulta e relatório dos exemplares que deixaram o
 * acervo ativo (extravio confirmado, dano irreparável, descarte, doação, substituição...).
 *
 * A baixa é registrada na ficha do exemplar ("Dar baixa", permissão exemplares.baixar). O cadastro, o
 * código BDP (nunca reaproveitado) e o histórico continuam guardados. Esta página só consulta e exporta.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

$usuario = fisico_iniciar();
$base = admin_url('baixas');
$f = [
    'tipo' => array_key_exists(admin_param($_GET, 'tipo'), BAIXA_TIPOS) || admin_param($_GET, 'tipo') === 'sem_tipo' ? admin_param($_GET, 'tipo') : '',
    'de' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'de')) ? admin_param($_GET, 'de') : '',
    'ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'ate')) ? admin_param($_GET, 'ate') : '',
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
];
$w = ["e.situacao = 'baixado'"];
$p = [];
if ($f['tipo'] === 'sem_tipo') {
    $w[] = 'e.baixa_tipo IS NULL';
} elseif ($f['tipo'] !== '') {
    $w[] = 'e.baixa_tipo = ?';
    $p[] = $f['tipo'];
}
if ($f['de'] !== '') {
    $w[] = 'e.baixado_em >= ?';
    $p[] = $f['de'] . ' 00:00:00';
}
if ($f['ate'] !== '') {
    $w[] = 'e.baixado_em <= ?';
    $p[] = $f['ate'] . ' 23:59:59';
}
if ($f['q'] !== '') {
    $w[] = '(e.codigo_individual LIKE ? OR l.titulo LIKE ?)';
    $p[] = '%' . addcslashes($f['q'], '%_\\') . '%';
    $p[] = '%' . addcslashes($f['q'], '%_\\') . '%';
}
$where = ' WHERE ' . implode(' AND ', $w);
$sql = 'FROM exemplares e JOIN livros l ON l.id = e.livro_id LEFT JOIN administradores a ON a.id = e.baixado_por' . $where;
$colunas = 'e.id, e.codigo_individual, e.baixado_em, e.motivo_baixa, e.baixa_tipo, e.baixa_observacao, l.id AS livro_id, l.titulo, a.nome AS baixado_por_nome';

try {
    if (admin_param($_GET, 'exportar') === 'csv') {
        fisico_exigir('relatorios.exportar');
        $linhas = db_query("SELECT $colunas $sql ORDER BY e.baixado_em DESC LIMIT 20000", $p)->fetchAll();
        registrar_acao('baixas.exportar', 'exemplar', null, ['linhas' => count($linhas)]);
        fisico_enviar_tabela('csv', 'baixas-acervo-' . date('Y-m-d'), 'Baixas do acervo', ['codigo_individual' => 'Código BDP', 'titulo' => 'Obra', 'baixado_em' => 'Data da baixa',
            'tipo' => 'Motivo', 'motivo_baixa' => 'Descrição', 'baixa_observacao' => 'Observações', 'baixado_por_nome' => 'Responsável'], ['codigo_individual' => 'codigo'],
            array_map(fn ($l) => $l + ['tipo' => BAIXA_TIPOS[$l['baixa_tipo']] ?? 'Não classificado (baixa antiga)'], $linhas),
            [etiquetas_biblioteca() . ' — Baixas do acervo', 'Gerado em ' . date('d/m/Y H:i') . ' por ' . $usuario['nome']], $usuario['nome']);
    }
    $total = (int)db_query("SELECT COUNT(*) $sql", $p)->fetchColumn();
    $pagina = max(1, (int)admin_param($_GET, 'pagina', '1'));
    $itens = db_query("SELECT $colunas $sql ORDER BY e.baixado_em DESC, e.id DESC LIMIT 30 OFFSET " . (($pagina - 1) * 30), $p)->fetchAll();
    $porTipo = db_query("SELECT COALESCE(baixa_tipo, 'sem_tipo') AS tipo, COUNT(*) AS n FROM exemplares WHERE situacao = 'baixado' GROUP BY COALESCE(baixa_tipo, 'sem_tipo')")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir as baixas', fisico_erro_tecnico($e, 'baixas do acervo') . ' Confira se a migração 190 foi aplicada.');
    exit;
}

admin_page_start('baixas', 'Baixas do acervo', 'Exemplares', 'Exemplares que deixaram o acervo ativo', array_merge(FISICO_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], ['Baixas do acervo']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Baixas do acervo</h1>
        <p>Exemplares retirados do acervo ativo por decisão administrativa. O cadastro, o código BDP e o histórico de cada um continuam guardados, e o código nunca é reaproveitado.
            Para dar baixa, abra a ficha do exemplar (com a permissão adequada). Uma ausência no inventário não gera baixa automaticamente.</p>
    </div>
    <?php if (auth_pode('relatorios.exportar')): ?>
        <div class="adm-page-head__actions"><a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($base, array_filter($f, fn ($v) => $v !== ''), ['exportar' => 'csv'])) ?>"><?= icon('file-down') ?> Exportar (CSV)</a></div>
    <?php endif; ?>
</section>

<section class="adm-stats" aria-label="Baixas por motivo">
    <?php foreach (BAIXA_TIPOS as $t => $rot): ?>
        <?= fisico_stat($rot, (int)($porTipo[$t] ?? 0), 'Exemplares baixados', 'package-x', 'muted', admin_url_filtros($base, ['tipo' => $t])) ?>
    <?php endforeach; ?>
    <?php if (!empty($porTipo['sem_tipo'])): ?><?= fisico_stat('Baixas antigas', (int)$porTipo['sem_tipo'], 'Antes da classificação por motivo', 'history', 'muted', admin_url_filtros($base, ['tipo' => 'sem_tipo'])) ?><?php endif; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-baixas">
    <div class="adm-panel__header"><div><h2 id="titulo-baixas">Exemplares baixados</h2><p><?= fisico_numero($total) ?> com estes filtros.</p></div></div>
    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
        <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Código BDP ou título..."></label>
        <div class="adm-filters">
            <label class="adm-filter"><span>Motivo</span><select class="adm-select" name="tipo"><option value="">Todos</option><?php foreach (BAIXA_TIPOS as $v => $r): ?><option value="<?= $v ?>"<?= $f['tipo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?><option value="sem_tipo"<?= $f['tipo'] === 'sem_tipo' ? ' selected' : '' ?>>Baixas antigas</option></select></label>
            <label class="adm-filter"><span>De</span><input class="adm-input" type="date" name="de" value="<?= e($f['de']) ?>"></label>
            <label class="adm-filter"><span>Até</span><input class="adm-input" type="date" name="ate" value="<?= e($f['ate']) ?>"></label>
        </div>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
    </form>
    <?php if (!$itens): ?>
        <div class="adm-empty adm-empty--left"><?= icon('circle-check') ?><strong>Nenhuma baixa com estes filtros.</strong></div>
    <?php else: ?>
        <div class="adm-table-wrap">
            <table class="adm-table adm-table--compact">
                <thead><tr><th scope="col">Código BDP</th><th scope="col">Obra</th><th scope="col">Data</th><th scope="col">Motivo</th><th scope="col">Responsável</th></tr></thead>
                <tbody>
                <?php foreach ($itens as $x): ?>
                    <tr>
                        <td data-label="Código"><a class="adm-link adm-mono" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$x['id']) ?>"><?= e($x['codigo_individual']) ?></a></td>
                        <td data-label="Obra"><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$x['livro_id']) ?>"><?= e($x['titulo']) ?></a></td>
                        <td data-label="Data"><?= $x['baixado_em'] ? e(admin_data_hora($x['baixado_em'])) : '—' ?></td>
                        <td data-label="Motivo"><?= e(BAIXA_TIPOS[$x['baixa_tipo']] ?? 'Baixa antiga') ?><small class="adm-block-muted"><?= e((string)$x['motivo_baixa']) ?><?= $x['baixa_observacao'] ? ' · ' . e($x['baixa_observacao']) : '' ?></small></td>
                        <td data-label="Responsável"><?= e((string)($x['baixado_por_nome'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= admin_paginacao($total, $pagina, 30, $base, array_filter($f, fn ($v) => $v !== ''), [30]) ?>
    <?php endif; ?>
</section>

<?php
admin_page_end(FISICO_JS);
