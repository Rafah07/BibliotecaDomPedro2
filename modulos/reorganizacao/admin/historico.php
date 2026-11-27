<?php
/**
 * Histórico completo e imutável da reorganização, com filtros (lote, participante, administrador,
 * grupo, tipo de ação, período) e exportações CSV: histórico filtrado, lotes, exemplares de um lote
 * e produção por sessão.
 */
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/csv.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$r = reorg();
$data = fn (string $c) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$c] ?? '')) ? $_GET[$c] : '';
$f = [
    'lote_id' => (int)($_GET['lote_id'] ?? 0) ?: null,
    'sessao_id' => (int)($_GET['sessao_id'] ?? 0) ?: null,
    'administrador_id' => (int)($_GET['administrador_id'] ?? 0) ?: null,
    'grupo' => isset(Reorganizacao::EQUIPES[$_GET['grupo'] ?? '']) ? $_GET['grupo'] : '',
    'acao' => preg_match('/^[a-z0-9_]{1,60}$/', (string)($_GET['acao'] ?? '')) ? $_GET['acao'] : '',
    'participante' => mb_substr(trim((string)($_GET['participante'] ?? '')), 0, 100),
    'de' => $data('de'),
    'ate' => $data('ate'),
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
];
$exportar = (string)($_GET['exportar'] ?? '');

if ($exportar === 'csv') {
    $linhas = array_map(fn ($h) => [$h['id'], $h['criado_em'], $h['lote_nome'] ?? '', $h['acao'], $h['descricao'], $h['nome'] ?? '', $h['sessao_id'] ? Reorganizacao::rotuloSessao($h['sessao_id']) : '', $h['admin_nome'] ?? '', $h['detalhes'] ?? ''],
        $r->historicoLista($f, 100000));
    reorg_csv('historico-reorganizacao-' . date('Y-m-d') . '.csv', ['Nº', 'Data e hora', 'Lote', 'Ação', 'Descrição', 'Participante (nome informado)', 'Sessão', 'Administrador', 'Detalhes (JSON)'], $linhas);
}
if ($exportar === 'lotes') {
    reorg_csv('reorganizacao-lotes-' . date('Y-m-d') . '.csv', ['Lote', 'Situação', 'Responsável', 'Prevista inicial', 'Quantidade final', 'Registrados', 'Aprovados', 'Códigos BDP', 'Carimbados', 'Conferidos', 'Organizados', 'Excelente', 'Regular', 'Ruim', 'Pendências abertas'],
        array_map(fn ($l) => [$l['nome'], $l['status'], $l['responsavel_nome'], $l['contadores']['inicial'], $l['contadores']['prevista'], $l['contadores']['registrados'], $l['contadores']['aprovados'], $l['contadores']['codigos'],
            $l['contadores']['carimbados'], $l['contadores']['conferidos'], $l['contadores']['organizados'], $l['contadores']['excelente'], $l['contadores']['regular'], $l['contadores']['ruim'], $l['contadores']['pendencias_abertas']], $r->lotes()));
}
if ($exportar === 'exemplares' && $f['lote_id'] && ($lote = $r->lote((int)$f['lote_id']))) {
    $linhas = db_query("SELECT g.numero, e.codigo_individual, COALESCE(lv.titulo, g.titulo) AS titulo, g.autores, g.editora, g.isbn, g.cdd, x.estado_conservacao, g.status,
            g.criado_nome, x.carimbado_nome, x.carimbado_em, x.conferido_nome, x.conferido_em, x.organizado_nome, x.organizado_em, x.problema_aberto, CONCAT_WS(' · ', lc.setor, lc.estante, lc.prateleira) AS local
        FROM reorg_registro_exemplares x JOIN reorg_registros g ON g.id = x.registro_id LEFT JOIN exemplares e ON e.id = x.exemplar_id LEFT JOIN livros lv ON lv.id = e.livro_id
        LEFT JOIN localizacoes lc ON lc.id = e.localizacao_id WHERE x.lote_id = ? AND x.removido_em IS NULL AND g.status <> 'descartado' ORDER BY g.numero, x.ordem", [$lote['id']])->fetchAll();
    reorg_csv('reorganizacao-' . $lote['nome'] . '-exemplares.csv', ['Registro', 'Código BDP', 'Título', 'Autores', 'Editora', 'ISBN', 'CDD', 'Conservação', 'Situação do registro', 'Levantamento', 'Carimbado por', 'Carimbado em', 'Conferido por', 'Conferido em', 'Organizado por', 'Organizado em', 'Com problema', 'Localização'],
        array_map(fn ($x) => [$x['numero'], $x['codigo_individual'], $x['titulo'], $x['autores'], $x['editora'], $x['isbn'], $x['cdd'], Reorganizacao::CONSERVACAO[$x['estado_conservacao']] ?? '', $x['status'], $x['criado_nome'],
            $x['carimbado_nome'], $x['carimbado_em'], $x['conferido_nome'], $x['conferido_em'], $x['organizado_nome'], $x['organizado_em'], $x['problema_aberto'] ? 'sim' : '', $x['local']], $linhas));
}
if ($exportar === 'producao') {
    $lid = (int)$f['lote_id'];
    $filtroLote = fn (string $col) => $lid ? " AND $col = $lid" : '';
    $linhas = db_query("SELECT s.id, s.nome, s.equipe,
            (SELECT COUNT(*) FROM reorg_registros g WHERE g.criado_sessao_id = s.id AND g.status <> 'descartado'" . $filtroLote('g.lote_id') . ") AS registros,
            (SELECT COALESCE(SUM(g.quantidade), 0) FROM reorg_registros g WHERE g.criado_sessao_id = s.id AND g.status <> 'descartado'" . $filtroLote('g.lote_id') . ") AS exemplares,
            (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.carimbado_sessao_id = s.id" . $filtroLote('x.lote_id') . ") AS carimbos,
            (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.conferido_sessao_id = s.id" . $filtroLote('x.lote_id') . ") AS conferencias,
            (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.organizado_sessao_id = s.id" . $filtroLote('x.lote_id') . ") AS organizacoes
        FROM reorg_sessoes s" . ($lid ? ' WHERE EXISTS (SELECT 1 FROM reorg_atribuicoes a WHERE a.sessao_id = s.id AND a.lote_id = ' . $lid . ')' : '') . ' ORDER BY s.nome, s.id')->fetchAll();
    reorg_csv('reorganizacao-producao-' . date('Y-m-d') . '.csv', ['Sessão', 'Nome informado', 'Equipe', 'Registros', 'Exemplares levantados', 'Carimbados', 'Conferidos', 'Organizados'],
        array_map(fn ($p) => [Reorganizacao::rotuloSessao($p['id']), $p['nome'], $p['equipe'] ? Reorganizacao::EQUIPES[$p['equipe']] : '', $p['registros'], $p['exemplares'], $p['carimbos'], $p['conferencias'], $p['organizacoes']], $linhas));
}

$lista = $r->historicoLista($f, 500);
$acoes = db_query('SELECT DISTINCT acao FROM reorg_historico ORDER BY acao')->fetchAll(PDO::FETCH_COLUMN);
$filtrosAtivos = array_filter($f);
$lotes = $r->lotes();

reorg_admin_inicio('historico', 'Histórico');
?>
<section class="adm-page-head reorg-head"><div><h1>Histórico</h1><p>Registro permanente de tudo o que aconteceu (não pode ser alterado nem apagado). Os nomes dos participantes são os informados na entrada, sem verificação de identidade.</p></div>
    <div class="adm-page-head__actions"><a class="adm-button" href="?<?= e(http_build_query($filtrosAtivos + ['exportar' => 'csv'])) ?>"><?= icon('download') ?> Exportar o que está filtrado (CSV)</a></div></section>

<form class="adm-card reorg-filtros-barra reorg-filtros-barra--grade" method="get" role="search" aria-label="Filtrar histórico">
    <label class="adm-field"><span class="adm-field__label">Lote</span><select class="adm-select" name="lote_id"><option value="">Todos</option><?= reorg_opcoes($lotes, $f['lote_id']) ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Participante</span><input class="adm-input" name="participante" value="<?= e($f['participante']) ?>" placeholder="Nome informado"></label>
    <label class="adm-field"><span class="adm-field__label">Administrador</span><select class="adm-select" name="administrador_id"><option value="">Todos</option><?= reorg_opcoes(reorg_administradores(), $f['administrador_id']) ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Grupo</span><select class="adm-select" name="grupo"><option value="">Todos</option><?php foreach (Reorganizacao::EQUIPES as $k => $v): ?><option value="<?= $k ?>"<?= $f['grupo'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Tipo de ação</span><select class="adm-select" name="acao"><option value="">Todas</option><?php foreach ($acoes as $a): ?><option value="<?= e($a) ?>"<?= $f['acao'] === $a ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $a)) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">De</span><input class="adm-input" type="date" name="de" value="<?= e($f['de']) ?>"></label>
    <label class="adm-field"><span class="adm-field__label">Até</span><input class="adm-input" type="date" name="ate" value="<?= e($f['ate']) ?>"></label>
    <label class="adm-field"><span class="adm-field__label">Pesquisar</span><input class="adm-input" name="q" value="<?= e($f['q']) ?>" placeholder="Código BDP, descrição"></label>
    <?php if ($f['sessao_id']): ?><input type="hidden" name="sessao_id" value="<?= (int)$f['sessao_id'] ?>"><?php endif; ?>
    <div class="reorg-filtros-barra__acoes"><button class="adm-button adm-button--primary" type="submit"><?= icon('search') ?> Filtrar</button>
        <?php if ($filtrosAtivos): ?><a class="adm-link" href="<?= e(reorg_url('admin/historico.php')) ?>">Limpar</a><?php endif; ?></div>
</form>

<section class="adm-card adm-panel">
    <?= $f['sessao_id'] ? '<p>Mostrando a sessão <strong>' . e(Reorganizacao::rotuloSessao($f['sessao_id'])) . '</strong>. <a href="?">Ver tudo</a></p>' : '' ?>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Data e hora</th><th>Lote</th><th>Ação</th><th>Descrição</th><th>Quem</th></tr></thead>
        <tbody>
        <?php if (!$lista): ?><tr><td colspan="5" class="adm-table__muted">Nada encontrado.</td></tr><?php endif; ?>
        <?php foreach ($lista as $h): ?>
            <tr><td><small><?= e(admin_data_hora($h['criado_em'])) ?></small></td>
                <td><small><?= $h['lote_id'] ? '<a href="' . e(reorg_url('admin/lote.php?id=' . $h['lote_id'])) . '">' . e($h['lote_nome'] ?? '') . '</a>' : '—' ?></small></td>
                <td><small><?= e(str_replace('_', ' ', $h['acao'])) ?></small></td>
                <td><?= e($h['descricao']) ?></td>
                <td><small><?= $h['nome'] ? e($h['nome']) . ' <a class="adm-mono" href="?sessao_id=' . (int)$h['sessao_id'] . '">' . e(Reorganizacao::rotuloSessao($h['sessao_id'])) . '</a>' : '' ?><?= $h['admin_nome'] ? ($h['nome'] ? ' · ' : '') . e($h['admin_nome']) : '' ?></small></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (count($lista) === 500): ?><p class="adm-field__hint">Mostrando os 500 mais recentes. Use os filtros ou exporte o CSV para ver tudo.</p><?php endif; ?>
</section>

<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Outras exportações</h2></div>
    <div class="reorg-linha">
        <a class="adm-button adm-button--sm" href="?exportar=lotes"><?= icon('download') ?> Lotes e progresso (CSV)</a>
        <a class="adm-button adm-button--sm" href="?<?= e(http_build_query(array_filter(['exportar' => 'producao', 'lote_id' => $f['lote_id']]))) ?>"><?= icon('download') ?> Produção por sessão<?= $f['lote_id'] ? ' do lote' : '' ?> (CSV)</a>
        <?php if ($f['lote_id']): ?><a class="adm-button adm-button--sm" href="?<?= e(http_build_query(['exportar' => 'exemplares', 'lote_id' => $f['lote_id']])) ?>"><?= icon('download') ?> Exemplares do lote (CSV)</a>
        <?php else: ?><span class="adm-field__hint">Escolha um lote no filtro para exportar os exemplares dele.</span><?php endif; ?>
    </div>
</section>
<?php reorg_admin_fim();
