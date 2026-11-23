<?php
/**
 * Relatórios → Circulação do acervo (migração 190): como a biblioteca está sendo usada.
 *
 * Empréstimos e devoluções no período, ativos e atrasados agora, obras e categorias mais procuradas,
 * obras com maior procura em relação ao número de cópias, obras com pouca circulação e exemplares
 * disponíveis. Usa só os dados reais dos empréstimos e NENHUM dado pessoal de aluno (só contagens),
 * por isso basta a permissão relatorios.ver. Filtros: período, categoria e obra.
 * Pouca circulação não significa que a obra é desnecessária: é apenas apoio à gestão do acervo.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';
require_once __DIR__ . '/includes/livros.php';

$usuario = fisico_iniciar('relatorios.ver');
$base = admin_url('relatorio-circulacao');
$hoje = date('Y-m-d');
$de = preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'de')) ? admin_param($_GET, 'de') : date('Y-m-d', strtotime('-12 months'));
$ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'ate')) ? admin_param($_GET, 'ate') : $hoje;
if ($de > $ate) {
    [$de, $ate] = [$ate, $de];
}
$categoria = (int)admin_param($_GET, 'categoria') ?: null;
$livroId = (int)admin_param($_GET, 'livro') ?: null;
$f = ['de' => $de, 'ate' => $ate, 'categoria' => $categoria ? (string)$categoria : '', 'livro' => $livroId ? (string)$livroId : ''];

// Filtro comum por obra/categoria (sobre a obra do exemplar).
$filtroObra = '';
$p = [];
if ($categoria) {
    $filtroObra .= ' AND EXISTS (SELECT 1 FROM livro_categorias lc WHERE lc.livro_id = e.livro_id AND lc.categoria_id = ?)';
    $p[] = $categoria;
}
if ($livroId) {
    $filtroObra .= ' AND e.livro_id = ?';
    $p[] = $livroId;
}
$inicio = $de . ' 00:00:00';
$fim = $ate . ' 23:59:59';

try {
    $q = fn (string $sql, array $params = []) => db_query($sql, $params);
    $tot = $q("SELECT
                  (SELECT COUNT(*) FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id WHERE m.data_emprestimo BETWEEN ? AND ?$filtroObra) AS emprestimos,
                  (SELECT COUNT(*) FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id WHERE m.data_devolucao BETWEEN ? AND ?$filtroObra) AS devolucoes,
                  (SELECT COUNT(*) FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id WHERE m.data_devolucao IS NULL$filtroObra) AS ativos,
                  (SELECT COUNT(*) FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id WHERE m.data_devolucao IS NULL AND m.data_prevista < CURDATE()$filtroObra) AS atrasados,
                  (SELECT COUNT(*) FROM exemplares e WHERE e.situacao = 'disponivel'$filtroObra) AS disponiveis",
        array_merge([$inicio, $fim], $p, [$inicio, $fim], $p, $p, $p, $p))->fetch();

    // Obras mais emprestadas no período, com cópias ativas e procura por cópia.
    $maisEmprestadas = $q("SELECT l.id, l.titulo, COUNT(m.id) AS emprestimos,
                                  (SELECT COUNT(*) FROM exemplares x WHERE x.livro_id = l.id AND x.situacao NOT IN ('baixado', 'extraviado')) AS copias,
                                  (SELECT COUNT(*) FROM exemplares x WHERE x.livro_id = l.id AND x.situacao = 'disponivel') AS disponiveis
                           FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id
                           WHERE m.data_emprestimo BETWEEN ? AND ?$filtroObra
                           GROUP BY l.id, l.titulo ORDER BY emprestimos DESC, l.titulo LIMIT 15", array_merge([$inicio, $fim], $p))->fetchAll();
    $procura = $maisEmprestadas;
    foreach ($procura as &$o) {
        $o['por_copia'] = (int)$o['copias'] > 0 ? round((int)$o['emprestimos'] / (int)$o['copias'], 1) : (float)$o['emprestimos'];
    }
    unset($o);
    usort($procura, fn ($a, $b) => $b['por_copia'] <=> $a['por_copia']);
    $procura = array_slice(array_filter($procura, fn ($o) => (int)$o['emprestimos'] >= 2), 0, 10);

    $categorias = $q("SELECT c.id, c.nome, COUNT(m.id) AS emprestimos FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id
                      JOIN livro_categorias lc ON lc.livro_id = e.livro_id AND lc.principal = 1 JOIN categorias c ON c.id = lc.categoria_id
                      WHERE m.data_emprestimo BETWEEN ? AND ?" . ($livroId ? ' AND e.livro_id = ?' : '') . "
                      GROUP BY c.id, c.nome ORDER BY emprestimos DESC LIMIT 10", array_merge([$inicio, $fim], $livroId ? [$livroId] : []))->fetchAll();

    // Pouca circulação: obras com exemplares disponíveis e no máximo 1 empréstimo no período.
    $poucas = $q("SELECT l.id, l.titulo, COUNT(DISTINCT e.id) AS copias,
                         (SELECT COUNT(*) FROM emprestimos m JOIN exemplares x ON x.id = m.exemplar_id WHERE x.livro_id = l.id AND m.data_emprestimo BETWEEN ? AND ?) AS emprestimos,
                         (SELECT MAX(m.data_emprestimo) FROM emprestimos m JOIN exemplares x ON x.id = m.exemplar_id WHERE x.livro_id = l.id) AS ultimo
                  FROM livros l JOIN exemplares e ON e.livro_id = l.id AND e.situacao = 'disponivel'
                  WHERE l.arquivado_em IS NULL$filtroObra
                  GROUP BY l.id, l.titulo HAVING emprestimos <= 1 ORDER BY emprestimos, ultimo IS NOT NULL, ultimo, l.titulo LIMIT 15", array_merge([$inicio, $fim], $p))->fetchAll();

    // Série mensal (empréstimos x devoluções) do período.
    $serie = $q("SELECT DATE_FORMAT(m.data_emprestimo, '%Y-%m') AS mes, COUNT(*) AS n FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id
                 WHERE m.data_emprestimo BETWEEN ? AND ?$filtroObra GROUP BY mes ORDER BY mes", array_merge([$inicio, $fim], $p))->fetchAll(PDO::FETCH_KEY_PAIR);
    $serieDev = $q("SELECT DATE_FORMAT(m.data_devolucao, '%Y-%m') AS mes, COUNT(*) AS n FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id
                    WHERE m.data_devolucao BETWEEN ? AND ?$filtroObra GROUP BY mes ORDER BY mes", array_merge([$inicio, $fim], $p))->fetchAll(PDO::FETCH_KEY_PAIR);
    $livroFiltro = $livroId ? $q('SELECT id, titulo FROM livros WHERE id = ?', [$livroId])->fetch() : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível montar o relatório de circulação', fisico_erro_tecnico($e, 'relatório de circulação'));
    exit;
}
$meses = array_unique(array_merge(array_keys($serie), array_keys($serieDev)));
sort($meses);
$maxSerie = max([1, ...array_values($serie), ...array_values($serieDev)]);

admin_page_start('relatorio-circulacao', 'Circulação do acervo', 'Relatórios', 'Uso da biblioteca', array_merge(FISICO_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Relatórios', admin_url('relatorios')], ['Circulação do acervo']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Circulação do acervo</h1>
        <p><?= e(date('d/m/Y', strtotime($de))) ?> a <?= e(date('d/m/Y', strtotime($ate))) ?><?= $livroFiltro ? ' · obra “' . e($livroFiltro['titulo']) . '”' : '' ?><?= $categoria ? ' · categoria “' . e(livros_categorias_ativas()[$categoria] ?? '') . '”' : '' ?>.
            Só contagens: nenhum nome de aluno aparece neste relatório.</p>
    </div>
</section>

<form class="adm-toolbar adm-card adm-panel" method="get" action="<?= e($base) ?>">
    <div class="adm-filters">
        <label class="adm-filter"><span>De</span><input class="adm-input" type="date" name="de" value="<?= e($de) ?>"></label>
        <label class="adm-filter"><span>Até</span><input class="adm-input" type="date" name="ate" value="<?= e($ate) ?>" max="<?= e($hoje) ?>"></label>
        <label class="adm-filter"><span>Categoria</span><select class="adm-select" name="categoria"><option value="">Todas</option><?php foreach (livros_categorias_ativas() as $cid => $cn): ?><option value="<?= (int)$cid ?>"<?= $categoria === (int)$cid ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></label>
        <?php if ($livroId): ?><input type="hidden" name="livro" value="<?= (int)$livroId ?>"><?php endif; ?>
    </div>
    <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Aplicar</button>
    <?php if ($livroId): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['de' => $de, 'ate' => $ate])) ?>">Todas as obras</a><?php endif; ?>
</form>

<section class="adm-stats" aria-label="Totais">
    <?= fisico_stat('Empréstimos no período', (int)$tot['emprestimos'], 'Retiradas registradas', 'book-open-check', 'blue') ?>
    <?= fisico_stat('Devoluções no período', (int)$tot['devolucoes'], 'Livros recebidos de volta', 'rotate-ccw', 'green') ?>
    <?= fisico_stat('Empréstimos ativos', (int)$tot['ativos'], 'Agora', 'book-copy', 'blue', auth_pode('emprestimos.ver') ? admin_url('emprestimos') : null) ?>
    <?= fisico_stat('Atrasados', (int)$tot['atrasados'], 'Agora', 'clock-alert', 'red', auth_pode('emprestimos.ver') ? admin_url('atrasos') : null) ?>
    <?= fisico_stat('Exemplares disponíveis', (int)$tot['disponiveis'], 'Prontos para emprestar agora', 'circle-check', 'green') ?>
</section>

<div class="adm-comparar">
    <section class="adm-card adm-panel" aria-labelledby="t-mais">
        <h2 class="adm-panel__title" id="t-mais"><?= icon('chart-column') ?> Obras mais emprestadas</h2>
        <?php if (!$maisEmprestadas): ?><p class="adm-field__hint">Nenhum empréstimo no período.</p><?php else: ?>
            <div class="adm-table-wrap"><table class="adm-table adm-table--compact">
                <thead><tr><th scope="col">Obra</th><th scope="col">Empréstimos</th><th scope="col">Cópias</th><th scope="col">Disponíveis</th></tr></thead>
                <tbody><?php foreach ($maisEmprestadas as $o): ?><tr>
                    <td data-label="Obra"><a class="adm-link" href="<?= e(admin_url_filtros($base, ['de' => $de, 'ate' => $ate, 'livro' => (int)$o['id']])) ?>"><?= e($o['titulo']) ?></a></td>
                    <td data-label="Empréstimos"><?= (int)$o['emprestimos'] ?></td><td data-label="Cópias"><?= (int)$o['copias'] ?></td><td data-label="Disponíveis"><?= (int)$o['disponiveis'] ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <section class="adm-card adm-panel" aria-labelledby="t-procura">
        <h2 class="adm-panel__title" id="t-procura"><?= icon('target') ?> Maior procura em relação às cópias</h2>
        <p class="adm-field__hint">Empréstimos por cópia no período. Valores altos podem indicar a necessidade de mais exemplares.</p>
        <?php if (!$procura): ?><p class="adm-field__hint">Sem dados suficientes no período.</p><?php else: ?>
            <ul class="adm-versao-diff"><?php foreach ($procura as $o): ?><li><strong><?= e($o['titulo']) ?></strong> — <?= e(number_format($o['por_copia'], 1, ',', '.')) ?> empréstimo(s) por cópia (<?= (int)$o['emprestimos'] ?> em <?= (int)$o['copias'] ?> cópia(s))</li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
    <section class="adm-card adm-panel" aria-labelledby="t-cat">
        <h2 class="adm-panel__title" id="t-cat"><?= icon('tags') ?> Categorias mais procuradas</h2>
        <?php if (!$categorias): ?><p class="adm-field__hint">Nenhum empréstimo de obra com categoria no período.</p><?php else: ?>
            <ul class="adm-versao-diff"><?php foreach ($categorias as $c): ?><li><a class="adm-link" href="<?= e(admin_url_filtros($base, ['de' => $de, 'ate' => $ate, 'categoria' => (int)$c['id']])) ?>"><?= e($c['nome']) ?></a> — <?= (int)$c['emprestimos'] ?> empréstimo(s)</li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
    <section class="adm-card adm-panel" aria-labelledby="t-poucas">
        <h2 class="adm-panel__title" id="t-poucas"><?= icon('hourglass') ?> Obras com pouca circulação</h2>
        <p class="adm-field__hint">No máximo 1 empréstimo no período, com exemplar disponível. Pouca circulação <strong>não</strong> significa que a obra é desnecessária: use apenas como apoio (divulgação, exposição, empréstimo a turmas).</p>
        <?php if (!$poucas): ?><p class="adm-field__hint">Nenhuma obra nesta situação.</p><?php else: ?>
            <ul class="adm-versao-diff"><?php foreach ($poucas as $o): ?><li><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$o['id']) ?>"><?= e($o['titulo']) ?></a> — <?= (int)$o['emprestimos'] ?> empréstimo(s); último: <?= $o['ultimo'] ? e(date('d/m/Y', strtotime($o['ultimo']))) : 'nunca' ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
</div>

<section class="adm-card adm-panel" aria-labelledby="t-serie">
    <h2 class="adm-panel__title" id="t-serie"><?= icon('activity') ?> Empréstimos e devoluções por mês</h2>
    <?php if (!$meses): ?><p class="adm-field__hint">Sem movimentação no período.</p><?php else: ?>
        <div class="adm-table-wrap"><table class="adm-table adm-table--compact">
            <thead><tr><th scope="col">Mês</th><th scope="col">Empréstimos</th><th scope="col">Devoluções</th></tr></thead>
            <tbody><?php foreach ($meses as $mes): ?><tr><td data-label="Mês"><?= e(substr($mes, 5, 2) . '/' . substr($mes, 0, 4)) ?></td>
                <td data-label="Empréstimos"><span class="adm-ocupacao"><span class="adm-ocupacao__barra"><span style="width:<?= (int)round(($serie[$mes] ?? 0) * 100 / $maxSerie) ?>%"></span></span> <?= (int)($serie[$mes] ?? 0) ?></span></td>
                <td data-label="Devoluções"><?= (int)($serieDev[$mes] ?? 0) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php
admin_page_end(FISICO_JS);
