<?php
/**
 * Histórico de Lotes (migração 160): todos os lotes, inclusive os encerrados — nenhum lote é apagado.
 *
 * Lotes novos: formulário único em lote-novo.php (todo lote pertence a um inventário, migração 240).
 * Também recebe as ações da seção "Meus lotes" da Conferência:
 *   * acao=abrir  compatibilidade com envios antigos (capacidade, usuários, observação, inventario_id
 *                 opcional). O número é gerado no servidor (LoteService::criar).
 *   * acao=ativo  "Trabalhar neste lote": o lote ativo de quem registra (só participantes de lotes em cadastro).
 * Filtros: número, período de abertura, responsável e status. Exportação para Excel (XLSX/CSV) e PDF.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\OperacaoService;

$usuario = fisico_iniciar();
$base = admin_url('lotes');
$podeGerenciar = auth_pode('lotes.gerenciar');

/** Volta só para páginas do painel (ex.: a Conferência de onde veio). */
function lotes_voltar(string $voltar, string $padrao): string
{
    return $voltar !== '' && str_starts_with($voltar, admin_base()) && !preg_match('/[\r\n]/', $voltar) ? $voltar : $padrao;
}

$formAbrir = null;
$erroAbrir = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    $voltar = lotes_voltar(admin_param($_POST, 'voltar'), $base);
    try {
        switch ($acao) {
            case 'abrir':
                fisico_exigir('lotes.gerenciar');
                $formAbrir = $_POST;
                $id = lotes_servico()->criar([
                    'capacidade' => admin_param($_POST, 'capacidade'),
                    'usuarios' => is_array($_POST['usuarios'] ?? null) ? $_POST['usuarios'] : [],
                    'descricao' => admin_param($_POST, 'descricao'),
                    'inventario_id' => admin_param($_POST, 'inventario_id'),
                ], $usuario['id'], admin_param($_POST, 'chave'));
                $lote = lotes_repo()->lote($id);
                admin_flash('sucesso', LoteRepository::nome($lote) . ' aberto — capacidade de ' . (int)$lote['capacidade'] . ' exemplar(es).',
                    'Usuários autorizados: ' . ($lote['participantes'] ?: '—') . '. Cada um escolhe o lote em “Meus lotes” e os exemplares registrados entram nele automaticamente.');
                admin_redirecionar($voltar);
            case 'ativo':
                $loteId = (int)admin_param($_POST, 'lote_id') ?: null;
                lotes_servico()->escolherAtivo($loteId, $usuario['id']);
                if ($loteId) {
                    $lote = lotes_repo()->lote($loteId);
                    admin_flash('sucesso', 'Você está trabalhando no ' . LoteRepository::nome($lote) . '.', 'Cada exemplar que você registrar entra neste lote. Restam ' . max(0, (int)$lote['capacidade'] - (int)$lote['total']) . ' vaga(s).');
                } else {
                    admin_flash('aviso', 'Nenhum lote ativo.', 'Escolha um lote antes de registrar exemplares.');
                }
                admin_redirecionar($voltar);
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        if ($acao === 'abrir') {
            $erroAbrir = $e->getMessage();
        } else {
            admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
            admin_redirecionar($voltar);
        }
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado.', fisico_erro_tecnico($e, 'lotes'));
        admin_redirecionar($voltar);
    }
}

if (admin_param($_GET, 'novo') === '1') {
    admin_redirecionar(admin_url('lote-novo')); // formulário único de lote
}

$filtros = [
    'numero' => (int)admin_param($_GET, 'numero') > 0 ? (string)(int)admin_param($_GET, 'numero') : '',
    'de' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'de')) ? admin_param($_GET, 'de') : '',
    'ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'ate')) ? admin_param($_GET, 'ate') : '',
    'responsavel' => (int)admin_param($_GET, 'responsavel') > 0 ? (string)(int)admin_param($_GET, 'responsavel') : '',
    'status' => isset(ExemplarService::STATUS_LOTE[admin_param($_GET, 'status')]) ? admin_param($_GET, 'status') : '',
];
$porPagina = in_array((int)admin_param($_GET, 'por_pagina'), [30, 60, 120], true) ? (int)admin_param($_GET, 'por_pagina') : 30;

/** Linha do histórico pronta para a exportação. */
function lotes_linha_exportacao(array $l): array
{
    return [
        'lote' => LoteRepository::nome($l), 'aberto' => admin_data_hora($l['criado_em']), 'capacidade' => $l['capacidade'] !== null ? (int)$l['capacidade'] : 'sem limite',
        'exemplares' => (int)$l['exemplares'], 'participantes' => (string)$l['participantes'], 'status' => ExemplarService::rotuloStatusLote($l['status']),
        'carimbados' => (int)$l['carimbados'], 'organizados' => (int)$l['organizados'], 'encerrado' => $l['encerrado_em'] ? admin_data_hora($l['encerrado_em']) : '',
        'observacao' => (string)$l['descricao'],
    ];
}

$exportar = admin_param($_GET, 'exportar');
if (in_array($exportar, ['xlsx', 'csv', 'pdf'], true)) {
    fisico_exigir('relatorios.exportar');
    $todos = lotes_repo()->historico($filtros, 1, 500)['itens'];
    registrar_acao('lotes.exportar_historico', 'lote', null, ['formato' => $exportar, 'linhas' => count($todos)]);
    fisico_enviar_tabela($exportar, 'historico-de-lotes-' . date('Y-m-d-His'), 'Histórico de Lotes', [
        'lote' => 'Lote', 'aberto' => 'Aberto em', 'capacidade' => 'Capacidade', 'exemplares' => 'Exemplares', 'participantes' => 'Usuários participantes',
        'status' => 'Status', 'carimbados' => 'Carimbados', 'organizados' => 'Organizados', 'encerrado' => 'Encerrado em', 'observacao' => 'Observação',
    ], ['exemplares' => 'numero', 'carimbados' => 'numero', 'organizados' => 'numero'], array_map('lotes_linha_exportacao', $todos),
        [etiquetas_biblioteca() . ' — Histórico de Lotes', 'Gerado em ' . date('d/m/Y H:i') . ' por ' . $usuario['nome']], $usuario['nome'], [], array_filter($filtros));
}

try {
    $lista = lotes_repo()->historico($filtros, max(1, (int)admin_param($_GET, 'pagina', '1')), $porPagina);
    $responsaveis = lotes_repo()->responsaveis();
    
    $contagem = lotes_repo()->painel([])['status'];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os lotes', fisico_erro_tecnico($e, 'histórico de lotes') . ' Confira se a migração 160 foi aplicada.');
    exit;
}
$filtrosLink = array_filter($filtros + ['por_pagina' => $porPagina !== 30 ? (string)$porPagina : ''], fn ($v) => $v !== '');

admin_page_start('lotes', 'Histórico de Lotes', 'Inventário', 'Lotes de cadastramento e etapas de trabalho', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], ['Histórico de Lotes']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Histórico de Lotes</h1>
        <p>Todos os lotes, do cadastro à estante definitiva. Nenhum lote é apagado: abra qualquer um, inclusive os encerrados, para ver o relatório e a planilha original de códigos BDP.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeGerenciar): ?><a class="adm-button adm-button--primary" href="<?= e(admin_url('lote-novo')) ?>"><?= icon('plus') ?> Abrir novo lote</a>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('lotes-produtividade')) ?>"><?= icon('chart-column') ?> Relatório de Produtividade</a><?php endif; ?>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('duvidas')) ?>"><?= icon('circle-help') ?> Dúvidas da conferência</a>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats adm-stats--lotes" aria-label="Lotes por status">
    <?= fisico_stat('Total de lotes', $contagem['total'], 'Criados até hoje', 'layers', 'blue', $base) ?>
    <?php foreach (ExemplarService::STATUS_LOTE as $st => $rot): ?>
        <?= fisico_stat($rot, $contagem[$st] ?? 0, $st === 'encerrado' ? 'Concluídos e preservados' : 'Lotes nesta etapa', ['aberto' => 'square-check', 'fechado' => 'lock', 'em_carimbagem' => 'stamp', 'aguardando_organizacao' => 'map-pin', 'encerrado' => 'archive'][$st], LOTE_TONS[$st], admin_url_filtros($base, ['status' => $st])) ?>
    <?php endforeach; ?>
</section>

<?php if ($erroAbrir !== null): ?><?= admin_aviso('erro', 'O lote não foi aberto.', $erroAbrir) ?><?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-historico">
    <div class="adm-panel__header"><div><h2 id="titulo-historico">Lotes</h2><p aria-live="polite"><?= fisico_numero($lista['total']) ?> lote(s)</p></div>
        <?php if (auth_pode('relatorios.exportar')): ?><div class="adm-actions-inline">
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'xlsx'])) ?>"><?= icon('file-spreadsheet') ?> Exportar para Excel</a>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'pdf'])) ?>"><?= icon('file-down') ?> PDF</a>
        </div><?php endif; ?></div>
    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
        <div class="adm-filters">
            <label class="adm-filter"><span>Número</span><input class="adm-input adm-input--sm" type="number" min="1" name="numero" value="<?= e($filtros['numero']) ?>" placeholder="Ex.: 1"></label>
            <label class="adm-filter"><span>Aberto de</span><input class="adm-input adm-input--sm" type="date" name="de" value="<?= e($filtros['de']) ?>"></label>
            <label class="adm-filter"><span>até</span><input class="adm-input adm-input--sm" type="date" name="ate" value="<?= e($filtros['ate']) ?>"></label>
            <label class="adm-filter"><span>Responsável</span><select class="adm-select" name="responsavel"><option value="">Todos</option>
                <?php foreach ($responsaveis as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $filtros['responsavel'] === (string)$r['id'] ? ' selected' : '' ?>><?= e($r['nome']) ?></option><?php endforeach; ?></select></label>
            <label class="adm-filter"><span>Status</span><select class="adm-select" name="status"><option value="">Todos</option>
                <?php foreach (ExemplarService::STATUS_LOTE as $v => $r): ?><option value="<?= e($v) ?>"<?= $filtros['status'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
        </div>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
        <?php if (array_filter($filtros)): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>">Limpar</a><?php endif; ?>
    </form>
    <div class="adm-table-wrap">
        <table class="adm-table adm-table--cards">
            <thead><tr><th scope="col">Lote</th><th scope="col">Aberto em</th><th scope="col">Capacidade</th><th scope="col">Exemplares</th><th scope="col">Usuários participantes</th>
                <th scope="col">Status</th><th scope="col">Carimbados</th><th scope="col">Organizados</th><th scope="col">Encerrado em</th><th scope="col"><span class="adm-visually-hidden">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($lista['itens'] as $l): ?>
                <tr>
                    <td class="adm-td-title"><a class="adm-link" href="<?= e(lote_url_trabalho($l)) ?>"><strong><?= e(lote_nome_completo($l)) ?></strong></a>
                        <?php if ($l['descricao']): ?><small class="adm-block-muted"><?= e($l['descricao']) ?></small><?php endif; ?>
                        <?php if ($l['modo'] === 'automatico'): ?><small class="adm-block-muted">Lote automático (antes do gerenciamento)</small><?php endif; ?>
                        <?php if ($l['capacidade'] !== null): $pctLote = (int)$l['capacidade'] > 0 ? min(100, (int)round((int)$l['exemplares'] * 100 / (int)$l['capacidade'])) : 0; ?>
                            <span class="adm-lote-mini adm-so-celular"><span class="adm-lote-mini__barra" aria-hidden="true"><span style="width: <?= $pctLote ?>%"></span></span>
                                <span><?= fisico_numero((int)$l['exemplares']) ?> de <?= fisico_numero((int)$l['capacidade']) ?> exemplares</span></span>
                        <?php endif; ?></td>
                    <td data-label="Aberto em" class="adm-td-sec"><?= e(admin_data_hora($l['criado_em'])) ?></td>
                    <td data-label="Capacidade" class="adm-td-sec"><?= $l['capacidade'] !== null ? fisico_numero((int)$l['capacidade']) : '<span class="adm-table__muted">sem limite</span>' ?></td>
                    <td data-label="Exemplares" class="adm-td-sec"><?= fisico_numero((int)$l['exemplares']) ?></td>
                    <td data-label="Usuários participantes" class="adm-td-sec"><?= $l['participantes'] ? e($l['participantes']) : '—' ?></td>
                    <td data-label="Status"><?= lote_badge($l['status']) ?><?= (int)$l['duvidas_abertas'] ? '<small class="adm-block-muted">' . (int)$l['duvidas_abertas'] . ' dúvida(s) aberta(s)</small>' : '' ?></td>
                    <td data-label="Carimbados" class="adm-td-sec"><?= fisico_numero((int)$l['carimbados']) ?></td>
                    <td data-label="Organizados" class="adm-td-sec"><?= fisico_numero((int)$l['organizados']) ?></td>
                    <td data-label="Encerrado em" class="adm-td-sec"><?= $l['encerrado_em'] ? e(admin_data_hora($l['encerrado_em'])) : '—' ?></td>
                    <td class="adm-table__actions adm-actions-col"><div class="adm-actions-inline">
                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('lote') . '?id=' . (int)$l['id']) ?>"><?= icon('eye') ?> Relatório</a>
                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('lote') . '?' . http_build_query(['id' => (int)$l['id'], 'saida' => 'planilha'])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Planilha BDP</a>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lista['itens']): ?><tr><td colspan="10"><div class="adm-empty"><?= icon('layers') ?><strong>Nenhum lote<?= array_filter($filtros) ? ' com estes filtros' : ' ainda' ?>.</strong>
                <span><?= $podeGerenciar ? 'Use “Abrir novo lote” para começar.' : 'Um administrador abre os lotes pela página de Conferência.' ?></span></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= admin_paginacao($lista['total'], $lista['pagina'], $porPagina, $base, $filtrosLink, [30, 60, 120]) ?>
</section>

<?php
admin_page_end(FISICO_JS);
