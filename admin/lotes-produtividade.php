<?php
/**
 * Relatório de Produtividade (migração 160) — parte do Histórico de Lotes. Só lotes.gerenciar.
 *
 * Painel geral da reorganização: lotes por etapa, exemplares cadastrados, carimbados e organizados,
 * trabalho de cada colaborador (cadastro, carimbo e organização contados separadamente) e
 * produtividade por dia, semana e mês. Filtros: período, lote e colaborador.
 *
 * Números calculados dos registros reais (LoteRepository): cada exemplar conta uma vez em cada
 * indicador, baixados não contam, e quem organizou é quem guardou o exemplar pela primeira vez
 * (uma movimentação posterior não apaga). Não é um ranking: as tabelas ficam em ordem alfabética.
 * O tempo das etapas é a duração de cada uma, não as horas trabalhadas por pessoa.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\ExemplarService;

$usuario = fisico_iniciar('lotes.gerenciar');
$base = admin_url('lotes-produtividade');

$filtros = [
    'de' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'de')) ? admin_param($_GET, 'de') : '',
    'ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'ate')) ? admin_param($_GET, 'ate') : '',
    'lote' => (int)admin_param($_GET, 'lote') > 0 ? (string)(int)admin_param($_GET, 'lote') : '',
    'colaborador' => (int)admin_param($_GET, 'colaborador') > 0 ? (string)(int)admin_param($_GET, 'colaborador') : '',
];
$periodo = in_array(admin_param($_GET, 'periodo'), ['dia', 'semana', 'mes'], true) ? admin_param($_GET, 'periodo') : 'dia';
const PROD_PERIODOS = ['dia' => 'Por dia', 'semana' => 'Por semana', 'mes' => 'Por mês'];

try {
    $painel = lotes_repo()->painel($filtros);
    $lotesFiltro = lotes_repo()->historico([], 1, 500)['itens'];
    $responsaveis = lotes_repo()->responsaveis();
    $lotesTabela = $filtros['lote'] !== '' ? array_values(array_filter($lotesFiltro, fn ($l) => (int)$l['id'] === (int)$filtros['lote'])) : $lotesFiltro;
    if ($filtros['de'] !== '' || $filtros['ate'] !== '') {
        $lotesTabela = array_values(array_filter($lotesTabela, fn ($l) => ($filtros['de'] === '' || substr($l['criado_em'], 0, 10) >= $filtros['de']) && ($filtros['ate'] === '' || substr($l['criado_em'], 0, 10) <= $filtros['ate'])));
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível montar o relatório', fisico_erro_tecnico($e, 'produtividade dos lotes') . ' Confira se a migração 160 foi aplicada.');
    exit;
}

/** "Lote 001" … com o tempo de cada etapa. */
function prod_linha_lote(array $l): array
{
    $t = LoteRepository::tempos($l);
    $dur = fn (string $k) => LoteRepository::duracaoTexto($t[$k]['segundos']) . ($t[$k]['em_andamento'] ? ' (em andamento)' : '');
    return [
        'lote' => LoteRepository::nome($l), 'status' => ExemplarService::rotuloStatusLote($l['status']), 'participantes' => (string)$l['participantes'],
        'exemplares' => (int)$l['exemplares'], 'carimbados' => (int)$l['carimbados'], 'organizados' => (int)$l['organizados'], 'duvidas' => (int)$l['duvidas_abertas'],
        'aberto' => admin_data_hora($l['criado_em']), 'fechado' => admin_data_hora($l['fechado_em']), 'carimbagem' => admin_data_hora($l['carimbagem_concluida_em']), 'encerrado' => admin_data_hora($l['encerrado_em']),
        't_cadastro' => $dur('cadastro'), 't_carimbagem' => $dur('carimbagem'), 't_organizacao' => $dur('organizacao'), 't_total' => $dur('total'),
    ];
}

$descFiltros = array_filter([
    'Período' => $filtros['de'] || $filtros['ate'] ? ($filtros['de'] ? date('d/m/Y', strtotime($filtros['de'])) : 'início') . ' a ' . ($filtros['ate'] ? date('d/m/Y', strtotime($filtros['ate'])) : 'hoje') : '',
    'Lote' => $filtros['lote'] !== '' ? LoteRepository::nome(array_values(array_filter($lotesFiltro, fn ($l) => (int)$l['id'] === (int)$filtros['lote']))[0] ?? 0) : '',
    'Colaborador' => $filtros['colaborador'] !== '' ? (string)(array_column($responsaveis, 'nome', 'id')[(int)$filtros['colaborador']] ?? '') : '',
]);

$exportar = admin_param($_GET, 'exportar');
if (in_array($exportar, ['xlsx', 'csv', 'pdf'], true)) {
    fisico_exigir('relatorios.exportar');
    $tabela = in_array(admin_param($_GET, 'tabela'), ['colaboradores', 'periodo', 'lotes'], true) ? admin_param($_GET, 'tabela') : 'colaboradores';
    registrar_acao('lotes.exportar_produtividade', 'lote', null, ['formato' => $exportar, 'tabela' => $tabela, 'filtros' => array_filter($filtros)]);
    $cab = [etiquetas_biblioteca() . ' — Relatório de Produtividade', 'Gerado em ' . date('d/m/Y H:i') . ' por ' . $usuario['nome'] . ($descFiltros ? ' · ' . implode(' · ', array_map(fn ($k, $v) => "$k: $v", array_keys($descFiltros), $descFiltros)) : '')];
    $ind = [['Lotes criados', $painel['status']['total']], ['Exemplares cadastrados', $painel['totais']['cadastrados']], ['Carimbados', $painel['totais']['carimbados']], ['Organizados', $painel['totais']['organizados']]];
    if ($tabela === 'lotes') {
        fisico_enviar_tabela($exportar, 'produtividade-lotes-' . date('Y-m-d-His'), 'Produtividade por lote', [
            'lote' => 'Lote', 'status' => 'Status', 'participantes' => 'Participantes', 'exemplares' => 'Cadastrados', 'carimbados' => 'Carimbados', 'organizados' => 'Organizados', 'duvidas' => 'Dúvidas abertas',
            'aberto' => 'Aberto em', 'fechado' => 'Fechado em', 'carimbagem' => 'Carimbagem concluída', 'encerrado' => 'Encerrado em',
            't_cadastro' => 'Abertura → fechamento', 't_carimbagem' => 'Fechamento → carimbagem', 't_organizacao' => 'Carimbagem → encerramento', 't_total' => 'Total',
        ], ['exemplares' => 'numero', 'carimbados' => 'numero', 'organizados' => 'numero', 'duvidas' => 'numero'], array_map('prod_linha_lote', $lotesTabela), $cab, $usuario['nome'], $ind, $descFiltros);
    }
    if ($tabela === 'periodo') {
        fisico_enviar_tabela($exportar, 'produtividade-' . $periodo . '-' . date('Y-m-d-His'), 'Produtividade ' . mb_strtolower(PROD_PERIODOS[$periodo]), [
            'periodo' => PROD_PERIODOS[$periodo] === 'Por dia' ? 'Dia' : (PROD_PERIODOS[$periodo] === 'Por semana' ? 'Semana' : 'Mês'), 'cadastrados' => 'Cadastrados', 'carimbados' => 'Carimbados', 'organizados' => 'Organizados',
        ], ['cadastrados' => 'numero', 'carimbados' => 'numero', 'organizados' => 'numero'], $painel['por_periodo'][$periodo], $cab, $usuario['nome'], $ind, $descFiltros);
    }
    $linhas = array_map(fn ($p) => ['nome' => $p['nome'], 'cadastrados' => $p['cadastrados'], 'carimbados' => $p['carimbados'], 'organizados' => $p['organizados']], $painel['por_colaborador']);
    $linhas[] = ['nome' => 'Total', 'cadastrados' => $painel['totais']['cadastrados'], 'carimbados' => $painel['totais']['carimbados'], 'organizados' => $painel['totais']['organizados']];
    fisico_enviar_tabela($exportar, 'produtividade-colaboradores-' . date('Y-m-d-His'), 'Produtividade por colaborador', [
        'nome' => 'Colaborador', 'cadastrados' => 'Cadastrados', 'carimbados' => 'Carimbados', 'organizados' => 'Organizados',
    ], ['cadastrados' => 'numero', 'carimbados' => 'numero', 'organizados' => 'numero'], $linhas, $cab, $usuario['nome'], $ind, $descFiltros);
}

$filtrosLink = array_filter($filtros, fn ($v) => $v !== '');
$st = $painel['status'];
admin_page_start('lotes-produtividade', 'Relatório de Produtividade', 'Inventário', 'Andamento da reorganização por lote e por colaborador', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], ['Histórico de Lotes', admin_url('lotes')], ['Relatório de Produtividade']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Relatório de Produtividade</h1>
        <p>Quantos exemplares foram cadastrados, carimbados e organizados, por quem e em qual período. Calculado dos registros reais; inclui os lotes encerrados.</p>
    </div>
    <div class="adm-page-head__actions adm-no-print">
        <button class="adm-button adm-button--ghost" type="button" data-imprimir><?= icon('printer') ?> Imprimir relatório</button>
        <?php if (auth_pode('relatorios.exportar')): ?>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'xlsx', 'tabela' => 'colaboradores'])) ?>"><?= icon('file-spreadsheet') ?> Exportar para Excel</a>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'pdf', 'tabela' => 'colaboradores'])) ?>"><?= icon('file-down') ?> Exportar para PDF</a>
        <?php endif; ?>
    </div>
</section>

<form class="adm-card adm-panel adm-toolbar adm-toolbar--search adm-no-print" method="get" action="<?= e($base) ?>">
    <div class="adm-filters">
        <label class="adm-filter"><span>De</span><input class="adm-input adm-input--sm" type="date" name="de" value="<?= e($filtros['de']) ?>"></label>
        <label class="adm-filter"><span>Até</span><input class="adm-input adm-input--sm" type="date" name="ate" value="<?= e($filtros['ate']) ?>"></label>
        <label class="adm-filter"><span>Lote</span><select class="adm-select" name="lote"><option value="">Todos</option>
            <?php foreach ($lotesFiltro as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $filtros['lote'] === (string)$l['id'] ? ' selected' : '' ?>><?= e(LoteRepository::nome($l)) ?></option><?php endforeach; ?></select></label>
        <label class="adm-filter"><span>Colaborador</span><select class="adm-select" name="colaborador"><option value="">Todos</option>
            <?php foreach ($responsaveis as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $filtros['colaborador'] === (string)$r['id'] ? ' selected' : '' ?>><?= e($r['nome']) ?></option><?php endforeach; ?></select></label>
    </div>
    <input type="hidden" name="periodo" value="<?= e($periodo) ?>">
    <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
    <?php if ($filtrosLink): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>">Limpar</a><?php endif; ?>
</form>
<?php if ($descFiltros): ?><p class="adm-field__hint"><?= e(implode(' · ', array_map(fn ($k, $v) => "$k: $v", array_keys($descFiltros), $descFiltros))) ?></p><?php endif; ?>

<section class="adm-stats adm-stats--lotes" aria-label="Lotes por etapa">
    <?= fisico_stat('Lotes criados', $st['total'], 'Todos os lotes', 'layers', 'blue', admin_url('lotes')) ?>
    <?= fisico_stat('Lotes em cadastro', $st['aberto'], 'Recebendo exemplares', 'square-check', 'blue', admin_url('lotes') . '?status=aberto') ?>
    <?= fisico_stat('Aguardando carimbagem', $st['fechado'], 'Fechados', 'lock', 'amber', admin_url('lotes') . '?status=fechado') ?>
    <?= fisico_stat('Em carimbagem', $st['em_carimbagem'], 'Carimbagem começou', 'stamp', 'indigo', admin_url('lotes') . '?status=em_carimbagem') ?>
    <?= fisico_stat('Aguardando organização', $st['aguardando_organizacao'], 'Carimbagem confirmada', 'map-pin', 'teal', admin_url('lotes') . '?status=aguardando_organizacao') ?>
    <?= fisico_stat('Lotes encerrados', $st['encerrado'], 'Concluídos e preservados', 'archive', 'green', admin_url('lotes') . '?status=encerrado') ?>
</section>
<section class="adm-stats" aria-label="Exemplares">
    <?= fisico_stat('Exemplares cadastrados', $painel['totais']['cadastrados'], 'Nos lotes, no período', 'book-copy', 'blue') ?>
    <?= fisico_stat('Exemplares carimbados', $painel['totais']['carimbados'], 'Carimbo confirmado no período', 'stamp', 'teal') ?>
    <?= fisico_stat('Exemplares organizados', $painel['totais']['organizados'], 'Guardados na estante no período', 'map-pin', 'green') ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-colab">
    <div class="adm-panel__header"><div><h2 id="titulo-colab"><?= icon('users') ?> Produtividade por colaborador</h2>
        <p>Quem cadastrou, quem carimbou e quem organizou cada exemplar, contados separadamente. Ordem alfabética — não é um ranking.</p></div></div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th scope="col">Colaborador</th><th scope="col" class="adm-num">Cadastrados</th><th scope="col" class="adm-num">Carimbados</th><th scope="col" class="adm-num">Organizados</th></tr></thead>
            <tbody>
            <?php foreach ($painel['por_colaborador'] as $p): ?>
                <tr><td><?= e($p['nome']) ?></td><td class="adm-num"><?= fisico_numero($p['cadastrados']) ?></td><td class="adm-num"><?= fisico_numero($p['carimbados']) ?></td><td class="adm-num"><?= fisico_numero($p['organizados']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$painel['por_colaborador']): ?><tr><td colspan="4"><div class="adm-empty"><?= icon('users') ?><strong>Nenhum trabalho registrado com estes filtros.</strong></div></td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><th scope="row">Total</th><td class="adm-num"><strong><?= fisico_numero($painel['totais']['cadastrados']) ?></strong></td><td class="adm-num"><strong><?= fisico_numero($painel['totais']['carimbados']) ?></strong></td><td class="adm-num"><strong><?= fisico_numero($painel['totais']['organizados']) ?></strong></td></tr></tfoot>
        </table>
    </div>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-periodo">
    <div class="adm-panel__header"><div><h2 id="titulo-periodo"><?= icon('calendar') ?> Produtividade <?= e(mb_strtolower(PROD_PERIODOS[$periodo])) ?></h2></div>
        <div class="adm-actions-inline adm-no-print">
            <?php foreach (PROD_PERIODOS as $p => $rot): ?><a class="adm-button adm-button--<?= $p === $periodo ? 'primary' : 'ghost' ?> adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['periodo' => $p])) ?>#titulo-periodo"><?= e($rot) ?></a><?php endforeach; ?>
            <?php if (auth_pode('relatorios.exportar')): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['periodo' => $periodo, 'exportar' => 'xlsx', 'tabela' => 'periodo'])) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?>
        </div></div>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th scope="col"><?= $periodo === 'dia' ? 'Dia' : ($periodo === 'semana' ? 'Semana' : 'Mês') ?></th><th scope="col" class="adm-num">Cadastrados</th><th scope="col" class="adm-num">Carimbados</th><th scope="col" class="adm-num">Organizados</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($painel['por_periodo'][$periodo], 0, 120) as $l): ?>
                <tr><td><?= e($periodo === 'dia' ? date('d/m/Y', strtotime($l['periodo'])) : ($periodo === 'mes' ? date('m/Y', strtotime($l['periodo'] . '-01')) : str_replace('-S', ' · semana ', $l['periodo']))) ?></td>
                    <td class="adm-num"><?= fisico_numero($l['cadastrados']) ?></td><td class="adm-num"><?= fisico_numero($l['carimbados']) ?></td><td class="adm-num"><?= fisico_numero($l['organizados']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$painel['por_periodo'][$periodo]): ?><tr><td colspan="4"><div class="adm-empty"><?= icon('calendar') ?><strong>Nada registrado no período.</strong></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-lotes">
    <div class="adm-panel__header"><div><h2 id="titulo-lotes"><?= icon('layers') ?> Indicadores por lote</h2><p>Datas de cada etapa e o tempo decorrido entre elas (duração das etapas, não horas trabalhadas).</p></div>
        <?php if (auth_pode('relatorios.exportar')): ?><div class="adm-actions-inline adm-no-print"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'xlsx', 'tabela' => 'lotes'])) ?>"><?= icon('file-spreadsheet') ?> Excel</a>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'pdf', 'tabela' => 'lotes'])) ?>"><?= icon('file-down') ?> PDF</a></div><?php endif; ?></div>
    <div class="adm-table-wrap">
        <table class="adm-table adm-table--responsive">
            <thead><tr><th scope="col">Lote</th><th scope="col">Status</th><th scope="col" class="adm-num">Cadastrados</th><th scope="col" class="adm-num">Carimbados</th><th scope="col" class="adm-num">Organizados</th>
                <th scope="col">Abertura → fechamento</th><th scope="col">Fechamento → carimbagem</th><th scope="col">Carimbagem → encerramento</th><th scope="col">Total</th></tr></thead>
            <tbody>
            <?php foreach ($lotesTabela as $l): $x = prod_linha_lote($l); ?>
                <tr><td data-label="Lote"><a class="adm-link" href="<?= e(admin_url('lote') . '?id=' . (int)$l['id']) ?>"><strong><?= e($x['lote']) ?></strong></a><small class="adm-block-muted"><?= e($x['participantes'] ?: '—') ?></small></td>
                    <td data-label="Status"><?= lote_badge($l['status']) ?></td>
                    <td data-label="Cadastrados" class="adm-num"><?= fisico_numero($x['exemplares']) ?></td><td data-label="Carimbados" class="adm-num"><?= fisico_numero($x['carimbados']) ?></td><td data-label="Organizados" class="adm-num"><?= fisico_numero($x['organizados']) ?></td>
                    <td data-label="Abertura → fechamento"><?= e($x['t_cadastro']) ?></td><td data-label="Fechamento → carimbagem"><?= e($x['t_carimbagem']) ?></td><td data-label="Carimbagem → encerramento"><?= e($x['t_organizacao']) ?></td><td data-label="Total"><?= e($x['t_total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$lotesTabela): ?><tr><td colspan="9"><div class="adm-empty"><?= icon('layers') ?><strong>Nenhum lote com estes filtros.</strong></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/lotes.js?v=20261115']));
