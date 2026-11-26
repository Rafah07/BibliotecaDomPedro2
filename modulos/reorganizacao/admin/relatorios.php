<?php
/** Relatórios da reorganização: todos os lotes, um lote em detalhe, produção por participante (CSV). */
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/csv.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$r = reorg();
$loteId = (int)($_GET['lote'] ?? 0);
$lote = $loteId ? $r->lote($loteId) : null;
$exportar = (string)($_GET['exportar'] ?? '');

$exemplaresLote = fn (int $id) => db_query("SELECT g.numero, e.codigo_individual, COALESCE(lv.titulo, g.titulo) AS titulo, g.autores, g.editora, g.isbn, g.cdd, x.estado_conservacao, g.status,
        g.criado_nome, x.carimbado_nome, x.carimbado_em, x.conferido_nome, x.conferido_em, x.organizado_nome, x.organizado_em, x.problema_aberto, CONCAT_WS(' · ', lc.setor, lc.estante, lc.prateleira) AS local
    FROM reorg_registro_exemplares x JOIN reorg_registros g ON g.id = x.registro_id LEFT JOIN exemplares e ON e.id = x.exemplar_id LEFT JOIN livros lv ON lv.id = e.livro_id
    LEFT JOIN localizacoes lc ON lc.id = e.localizacao_id WHERE x.lote_id = ? AND x.removido_em IS NULL AND g.status <> 'descartado' ORDER BY g.numero, x.ordem", [$id])->fetchAll();
$producao = fn (?int $id) => db_query("SELECT s.id, s.nome, s.equipe,
        (SELECT COUNT(*) FROM reorg_registros g WHERE g.criado_sessao_id = s.id AND g.status <> 'descartado'" . ($id ? ' AND g.lote_id = ' . $id : '') . ") AS registros,
        (SELECT COALESCE(SUM(g.quantidade), 0) FROM reorg_registros g WHERE g.criado_sessao_id = s.id AND g.status <> 'descartado'" . ($id ? ' AND g.lote_id = ' . $id : '') . ") AS exemplares,
        (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.carimbado_sessao_id = s.id" . ($id ? ' AND x.lote_id = ' . $id : '') . ") AS carimbos,
        (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.conferido_sessao_id = s.id" . ($id ? ' AND x.lote_id = ' . $id : '') . ") AS conferencias,
        (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.organizado_sessao_id = s.id" . ($id ? ' AND x.lote_id = ' . $id : '') . ") AS organizacoes
    FROM reorg_sessoes s" . ($id ? ' WHERE EXISTS (SELECT 1 FROM reorg_atribuicoes a WHERE a.sessao_id = s.id AND a.lote_id = ' . $id . ')' : '') . ' ORDER BY s.nome, s.id')->fetchAll();

if ($exportar === 'lotes') {
    reorg_csv('reorganizacao-lotes-' . date('Y-m-d') . '.csv', ['Lote', 'Situação', 'Responsável', 'Prevista inicial', 'Quantidade final', 'Registrados', 'Aprovados', 'Códigos BDP', 'Carimbados', 'Conferidos', 'Organizados', 'Excelente', 'Regular', 'Ruim', 'Pendências abertas'],
        array_map(fn ($l) => [$l['nome'], $l['status'], $l['responsavel_nome'], $l['contadores']['inicial'], $l['contadores']['prevista'], $l['contadores']['registrados'], $l['contadores']['aprovados'], $l['contadores']['codigos'],
            $l['contadores']['carimbados'], $l['contadores']['conferidos'], $l['contadores']['organizados'], $l['contadores']['excelente'], $l['contadores']['regular'], $l['contadores']['ruim'], $l['contadores']['pendencias_abertas']], $r->lotes()));
}
if ($exportar === 'exemplares' && $lote) {
    reorg_csv('reorganizacao-' . $lote['nome'] . '-exemplares.csv', ['Registro', 'Código BDP', 'Título', 'Autores', 'Editora', 'ISBN', 'CDD', 'Conservação', 'Situação do registro', 'Levantamento', 'Carimbado por', 'Carimbado em', 'Conferido por', 'Conferido em', 'Organizado por', 'Organizado em', 'Com problema', 'Localização'],
        array_map(fn ($x) => [$x['numero'], $x['codigo_individual'], $x['titulo'], $x['autores'], $x['editora'], $x['isbn'], $x['cdd'], Reorganizacao::CONSERVACAO[$x['estado_conservacao']] ?? '', $x['status'], $x['criado_nome'],
            $x['carimbado_nome'], $x['carimbado_em'], $x['conferido_nome'], $x['conferido_em'], $x['organizado_nome'], $x['organizado_em'], $x['problema_aberto'] ? 'sim' : '', $x['local']], $exemplaresLote($loteId)));
}
if ($exportar === 'producao') {
    reorg_csv('reorganizacao-producao-' . date('Y-m-d') . '.csv', ['Sessão', 'Nome informado', 'Equipe', 'Registros', 'Exemplares levantados', 'Carimbados', 'Conferidos', 'Organizados'],
        array_map(fn ($p) => [Reorganizacao::rotuloSessao($p['id']), $p['nome'], $p['equipe'] ? Reorganizacao::EQUIPES[$p['equipe']] : '', $p['registros'], $p['exemplares'], $p['carimbos'], $p['conferencias'], $p['organizacoes']], $producao($lote ? $loteId : null)));
}

$lotes = $r->lotes();
reorg_admin_inicio('relatorios', 'Relatórios');
?>
<section class="adm-page-head"><div><h1>Relatórios<?= $lote ? ' · ' . e($lote['nome']) : '' ?></h1><p>Progresso por lote e por etapa, conservação e produção por sessão (nome informado). Os exemplares e códigos BDP também aparecem nos relatórios permanentes do sistema.</p></div>
    <div class="adm-page-head__actions">
        <a class="adm-button" href="?exportar=lotes"><?= icon('download') ?> Lotes (CSV)</a>
        <a class="adm-button" href="?<?= e(http_build_query(array_filter(['lote' => $loteId ?: null, 'exportar' => 'producao']))) ?>"><?= icon('download') ?> Produção (CSV)</a>
        <?php if ($lote): ?><a class="adm-button" href="?<?= e(http_build_query(['lote' => $loteId, 'exportar' => 'exemplares'])) ?>"><?= icon('download') ?> Exemplares do lote (CSV)</a><?php endif; ?>
    </div></section>

<?php if ($lote): $res = $r->resumo($loteId); $c = $r->contadores($loteId); ?>
<section class="adm-card adm-panel">
    <h2>Resumo do lote <?= reorg_status_lote($lote) ?></h2>
    <dl class="adm-meta adm-meta--two">
        <div><dt>Prevista inicial → final</dt><dd><?= $res['prevista_inicial'] ?> → <?= $res['quantidade_final'] ?></dd></div>
        <div><dt>Obras novas / reaproveitadas</dt><dd><?= $res['obras_cadastradas'] ?> / <?= $res['obras_reaproveitadas'] ?></dd></div>
        <div><dt>Códigos BDP</dt><dd><?= $res['codigos_gerados'] ?></dd></div>
        <div><dt>Carimbados · conferidos · organizados</dt><dd><?= $res['carimbados'] ?> · <?= $res['conferidos'] ?> · <?= $res['organizados'] ?></dd></div>
        <div><dt>Conservação</dt><dd>Excelente <?= $c['excelente'] ?> · Regular <?= $c['regular'] ?> · Ruim <?= $c['ruim'] ?></dd></div>
        <div><dt>Sem estante cadastrada</dt><dd><?= $res['sem_estante'] ?></dd></div>
    </dl>
    <p><a class="adm-link" href="<?= e(reorg_url('admin/lote.php?id=' . $loteId)) ?>">Abrir o lote</a> · <a class="adm-link" href="?">Todos os lotes</a></p>
</section>
<?php endif; ?>

<?php if (!$lote): ?>
<section class="adm-card adm-panel">
    <h2>Lotes</h2>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Lote</th><th>Situação</th><th>Final</th><th>Registrados</th><th>Códigos</th><th>Carimbados</th><th>Conferidos</th><th>Organizados</th><th>Exc./Reg./Ruim</th><th></th></tr></thead>
        <tbody><?php foreach ($lotes as $l): $c = $l['contadores']; ?>
            <tr><td><?= e($l['nome']) ?></td><td><?= reorg_status_lote($l) ?></td><td><?= $c['prevista'] ?></td><td><?= $c['registrados'] ?></td><td><?= $c['codigos'] ?></td><td><?= $c['carimbados'] ?></td><td><?= $c['conferidos'] ?></td><td><?= $c['organizados'] ?></td>
                <td><?= $c['excelente'] ?>/<?= $c['regular'] ?>/<?= $c['ruim'] ?></td><td><a class="adm-link" href="?lote=<?= (int)$l['id'] ?>">Detalhar</a></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
<?php endif; ?>

<section class="adm-card adm-panel">
    <h2>Produção por sessão</h2>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Sessão</th><th>Nome informado</th><th>Equipe</th><th>Registros</th><th>Exemplares levantados</th><th>Carimbados</th><th>Conferidos</th><th>Organizados</th></tr></thead>
        <tbody><?php foreach ($producao($lote ? $loteId : null) as $p): ?>
            <tr><td class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['id'])) ?></td><td><?= e($p['nome']) ?></td><td><?= e($p['equipe'] ? Reorganizacao::EQUIPES[$p['equipe']] : '—') ?></td>
                <td><?= (int)$p['registros'] ?></td><td><?= (int)$p['exemplares'] ?></td><td><?= (int)$p['carimbos'] ?></td><td><?= (int)$p['conferencias'] ?></td><td><?= (int)$p['organizacoes'] ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
<?php reorg_admin_fim();
