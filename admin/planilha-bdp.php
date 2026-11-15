<?php
/**
 * Planilha de Códigos BDP, área de triagem e lotes de cadastramento (migração 150).
 *
 * Fluxo: a equipe registra vários exemplares seguidos (Inventário ou Cadastrar exemplar) sem parar para
 * carimbar. Cada exemplar novo entra AUTOMATICAMENTE aqui — a lista é gerada do banco na hora, nunca de
 * um arquivo — com o lote e a ordem de cadastramento. Depois a equipe imprime a lista (ou exporta para
 * Excel), escreve os códigos nos carimbos seguindo a ordem física dos livros separados e confirma
 * "Marcar como carimbado".
 *
 * Abas: Aguardando carimbo · Área de triagem (aguardando organização) · Todos · Lotes.
 * Impressão: só o que está filtrado ou selecionado (nunca a biblioteca inteira por padrão).
 * Carimbo, conferência física, localização e verificação bibliográfica são informações separadas.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\PreparoRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\OperacaoService;

$usuario = fisico_iniciar();
$base = admin_url('planilha-bdp');
$podeGerenciar = auth_pode('exemplares.gerenciar');

/** Filtros da lista (GET, ou POST na impressão/exportação dos selecionados). */
function planilha_filtros(array $origem): array
{
    $op = fn (string $nome, array $validos, string $padrao) => in_array(admin_param($origem, $nome, $padrao), $validos, true) ? admin_param($origem, $nome, $padrao) : $padrao;
    $aba = $op('aba', ['carimbo', 'triagem', 'todos', 'lotes'], 'carimbo');
    $porPagina = (int)admin_param($origem, 'por_pagina', '50');
    return [
        'aba' => $aba,
        'carimbo' => $op('carimbo', array_keys(PreparoRepository::FILTRO_CARIMBO), $aba === 'carimbo' ? 'aguardando' : 'todos'),
        'organizacao' => $op('organizacao', array_keys(PreparoRepository::FILTRO_ORGANIZACAO), $aba === 'triagem' ? 'aguardando' : 'todos'),
        'encontrado' => $op('encontrado', array_keys(PreparoRepository::FILTRO_ENCONTRADO), ''),
        'lote' => (int)admin_param($origem, 'lote') > 0 ? (string)(int)admin_param($origem, 'lote') : '',
        'data' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($origem, 'data')) ? admin_param($origem, 'data') : '',
        'responsavel' => (int)admin_param($origem, 'responsavel') > 0 ? (string)(int)admin_param($origem, 'responsavel') : '',
        'q' => mb_substr(admin_param($origem, 'q'), 0, 100),
        'ordem' => $op('ordem', ['lote', 'codigo'], 'lote'),
        'por_pagina' => in_array($porPagina, [50, 100, 200], true) ? (string)$porPagina : '50',
    ];
}

/** Ids escolhidos nas caixas de seleção. */
function planilha_ids(array $post): array
{
    $ids = $post['ids'] ?? [];
    return array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn ($i) => $i > 0))), 0, 1000);
}

/** Linhas para impressão/exportação, já com os textos prontos. */
function planilha_linhas(array $filtros, int $limite): array
{
    $r = preparo_repo()->planilha($filtros, 1, $limite);
    return array_map(fn ($x) => [
        'lote' => PreparoRepository::rotuloLote($x),
        'ordem' => $x['lote_ordem'] !== null ? (int)$x['lote_ordem'] : '',
        'codigo' => $x['codigo_individual'],
        'titulo' => trim(($x['titulo'] ?? 'Sem título') . ($x['subtitulo'] ? ': ' . $x['subtitulo'] : '')),
        'autores' => (string)$x['autores'],
        'id' => (int)$x['id'],
        'cadastro' => admin_data_hora($x['criado_em']),
        'responsavel' => (string)$x['cadastrado_por_nome'],
        'carimbo' => ExemplarService::CARIMBO[$x['carimbo_status']] ?? $x['carimbo_status'],
        'localizacao' => fisico_local_ou_triagem($x),
        'observacoes' => (string)$x['carimbo_observacao'],
    ], $r['itens']) + ['_total' => $r['total']];
}

$erro = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    // Botões de uma linha só ("Marcar como carimbado", "Salvar observação"): unico = "acao:id".
    if (preg_match('/^(carimbar|observacao):(\d+)$/', admin_param($_POST, 'unico'), $m)) {
        [$acao, $_POST['id']] = [$m[1], $m[2]];
    } else {
        unset($_POST['id']);
    }
    $voltar = admin_url_filtros($base, array_filter(planilha_filtros($_POST)));
    try {
        switch ($acao) {
            case 'carimbar':
                fisico_exigir('exemplares.gerenciar');
                $ids = admin_param($_POST, 'id') !== '' ? [(int)admin_param($_POST, 'id')] : planilha_ids($_POST);
                if (!$ids) {
                    throw new AcervoException('Selecione pelo menos um exemplar.');
                }
                $op = operacoes_servico()->realizar('carimbo', count($ids) === 1 ? 'Carimbo confirmado' : 'Carimbo confirmado em ' . count($ids) . ' exemplares', $usuario['id'],
                    fn () => exemplares_servico()->marcarCarimbado($ids, $usuario['id']), admin_param($_POST, 'chave'));
                $codigos = $op['resultado'];
                admin_flash($codigos ? 'sucesso' : 'aviso', $codigos ? (count($codigos) === 1 ? $codigos[0] . ' marcado como carimbado.' : count($codigos) . ' exemplares marcados como carimbados.') : 'Nada foi alterado.',
                    $codigos ? 'Operação nº ' . $op['id'] . '. Marcou por engano? Use "Desfazer" em Ações recentes.' : 'Os exemplares escolhidos já estavam carimbados.');
                admin_redirecionar($voltar);
            case 'observacao':
                fisico_exigir('exemplares.gerenciar');
                exemplares_servico()->observacaoCarimbo((int)admin_param($_POST, 'id'), admin_param($_POST, 'texto'), $usuario['id']);
                admin_flash('sucesso', 'Observação salva.');
                admin_redirecionar($voltar);
            case 'novo_lote':
                fisico_exigir('exemplares.gerenciar');
                if (lotes_repo()->emUso()) {
                    throw new AcervoException('Os lotes agora são abertos por um administrador, com capacidade e usuários, em “+ Abrir novo lote” (Conferência ou Histórico de Lotes). Escolha o seu em “Meus lotes”.');
                }
                $loteId = lotes_servico()->abrirNovo($usuario['id'], admin_param($_POST, 'equipe'), admin_param($_POST, 'descricao'));
                $lote = preparo_repo()->lote($loteId);
                admin_flash('sucesso', PreparoRepository::rotuloLote($lote) . ' aberto.', 'Os próximos exemplares que você registrar entram neste lote, na ordem do registro. Separe os livros dele juntos, na mesma ordem.');
                admin_redirecionar(admin_url_filtros($base, ['aba' => 'lotes']));
            case 'fechar_lote':
                fisico_exigir('exemplares.gerenciar');
                if (db_query('SELECT modo FROM cadastro_lotes WHERE id = ?', [(int)admin_param($_POST, 'lote_id')])->fetchColumn() === 'gerenciado') {
                    fisico_exigir('lotes.gerenciar');
                }
                lotes_servico()->fechar((int)admin_param($_POST, 'lote_id'), $usuario['id']);
                admin_flash('sucesso', 'Lote fechado.', 'O próximo exemplar registrado por quem abriu o lote vai para um lote novo.');
                admin_redirecionar(admin_url_filtros($base, ['aba' => 'lotes']));
            case 'imprimir':
            case 'exportar':
                // Selecionados manualmente (POST). Sem seleção: os filtros da tela.
                $filtrosSaida = planilha_filtros($_POST);
                $ids = planilha_ids($_POST);
                if ($ids) {
                    $filtrosSaida = ['carimbo' => 'todos', 'organizacao' => 'todos', 'ids' => $ids, 'ordem' => $filtrosSaida['ordem']];
                }
                $_GET = ['saida' => $acao, 'formato' => admin_param($_POST, 'formato', 'xlsx')] + $filtrosSaida;
                $saidaPost = $filtrosSaida;
                break;
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
        admin_redirecionar($voltar);
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado.', fisico_erro_tecnico($e, 'planilha de códigos BDP'));
        admin_redirecionar($voltar);
    }
}

$filtros = isset($saidaPost) ? $saidaPost + planilha_filtros([]) : planilha_filtros($_GET);
$saida = admin_param($_GET, 'saida');

// ---------- Exportar para Excel (XLSX; CSV opcional) ----------
if ($saida === 'exportar') {
    $formato = in_array(admin_param($_GET, 'formato'), ['xlsx', 'csv'], true) ? admin_param($_GET, 'formato') : 'xlsx';
    try {
        $linhas = planilha_linhas($filtros, 20000);
    } catch (PDOException $e) {
        admin_pagina_erro('Não foi possível exportar', fisico_erro_tecnico($e, 'exportação da planilha BDP'));
        exit;
    }
    unset($linhas['_total']);
    registrar_acao('planilha_bdp.exportar', 'exemplar', null, ['formato' => $formato, 'linhas' => count($linhas), 'filtros' => array_filter($filtros, fn ($v) => !is_array($v))]);
    fisico_enviar_tabela($formato, 'planilha-codigos-bdp-' . date('Y-m-d-His'), 'Planilha de Códigos BDP', [
        'lote' => 'Lote', 'ordem' => 'Ordem no lote', 'codigo' => 'Código BDP', 'titulo' => 'Título', 'autores' => 'Autor', 'id' => 'ID interno do exemplar',
        'cadastro' => 'Cadastrado em', 'responsavel' => 'Responsável', 'carimbo' => 'Situação do carimbo', 'localizacao' => 'Localização', 'observacoes' => 'Observações',
    ], ['codigo' => 'codigo', 'ordem' => 'numero', 'id' => 'numero'], $linhas,
        [etiquetas_biblioteca() . ' — Planilha de Códigos BDP', 'Gerada em ' . date('d/m/Y H:i') . ' por ' . $usuario['nome'], count($linhas) . ' exemplar(es), na ordem de cadastramento'], $usuario['nome']);
}

// ---------- Imprimir lista (A4, econômica, com espaço para marcar o carimbo) ----------
if ($saida === 'imprimir') {
    try {
        $linhas = planilha_linhas($filtros, 3000);
    } catch (PDOException $e) {
        admin_pagina_erro('Não foi possível montar a lista', fisico_erro_tecnico($e, 'impressão da planilha BDP'));
        exit;
    }
    $total = $linhas['_total'];
    unset($linhas['_total']);
    registrar_acao('planilha_bdp.imprimir', 'exemplar', null, ['linhas' => count($linhas), 'filtros' => array_filter($filtros, fn ($v) => !is_array($v))]);
    $grupos = [];
    foreach ($linhas as $l) {
        $grupos[$l['lote']][] = $l;
    }
    $descFiltros = array_filter([
        !empty($filtros['ids']) ? count($filtros['ids']) . ' exemplar(es) selecionado(s)' : null,
        ($filtros['carimbo'] ?? 'todos') !== 'todos' && empty($filtros['ids']) ? PreparoRepository::FILTRO_CARIMBO[$filtros['carimbo']] : null,
        ($filtros['organizacao'] ?? 'todos') !== 'todos' && empty($filtros['ids']) ? PreparoRepository::FILTRO_ORGANIZACAO[$filtros['organizacao']] : null,
        ($filtros['lote'] ?? '') !== '' ? PreparoRepository::rotuloLote(preparo_repo()->lote((int)$filtros['lote'])) : null,
        ($filtros['data'] ?? '') !== '' ? 'Cadastrados em ' . date('d/m/Y', strtotime($filtros['data'])) : null,
        ($filtros['q'] ?? '') !== '' ? 'Pesquisa: ' . $filtros['q'] : null,
    ]);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Planilha de Códigos BDP — <?= e(etiquetas_biblioteca()) ?></title>
<style>
    @page { size: A4 portrait; margin: 10mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font: 9pt/1.3 Arial, Helvetica, sans-serif; color: #111; background: #fff; }
    .barra { display: flex; gap: 8px; align-items: center; padding: 10px 14px; background: #eef2fb; border-bottom: 1px solid #c9d3ea; font-size: 10pt; }
    .barra button, .barra a { font: inherit; padding: 6px 12px; border: 1px solid #2457c5; border-radius: 6px; background: #2457c5; color: #fff; text-decoration: none; cursor: pointer; }
    .barra a { background: #fff; color: #2457c5; }
    .folha { padding: 8mm 10mm; max-width: 210mm; margin: 0 auto; }
    h1 { font-size: 13pt; margin: 0 0 2px; }
    .meta { margin: 0 0 6px; color: #444; font-size: 8pt; }
    h2 { font-size: 10.5pt; margin: 10px 0 4px; padding: 3px 6px; background: #f1f1f1; border: 1px solid #ccc; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #999; padding: 2.5px 4px; vertical-align: top; text-align: left; overflow-wrap: anywhere; }
    th { background: #f6f6f6; font-size: 7.5pt; }
    td.ordem { text-align: center; font-weight: bold; }
    td.codigo { font: bold 10.5pt "Courier New", monospace; white-space: nowrap; }
    td.marcar { text-align: center; font-size: 12pt; }
    td.obs { height: 16px; }
    .pequeno { font-size: 7.5pt; color: #444; }
    .dica { margin: 8px 0 0; font-size: 7.5pt; color: #444; }
    .lote { break-inside: auto; }
    .lote + .lote { break-before: page; }
    tr { break-inside: avoid; }
    @media print { .barra { display: none; } .folha { padding: 0; max-width: none; } }
</style>
</head>
<body>
<div class="barra"><button type="button" onclick="window.print()">Imprimir</button> <a href="<?= e(admin_url_filtros($base, array_filter($filtros, fn ($v) => !is_array($v)))) ?>">Voltar para a planilha</a>
    <span><?= count($linhas) ?> exemplar(es)<?= $total > count($linhas) ? ' (mostrando os primeiros ' . count($linhas) . ' de ' . $total . '; filtre por lote ou data)' : '' ?></span></div>
<div class="folha">
    <h1>Planilha de Códigos BDP — <?= e(etiquetas_biblioteca()) ?></h1>
    <p class="meta">Impressa em <?= e(date('d/m/Y H:i')) ?> por <?= e($usuario['nome']) ?><?= $descFiltros ? ' · ' . e(implode(' · ', $descFiltros)) : '' ?>. Escreva cada código no carimbo do livro da MESMA linha (mesmo lote e mesma ordem) e marque ☐ ao terminar.</p>
    <?php if (!$linhas): ?><p>Nenhum exemplar com estes filtros.</p><?php endif; ?>
    <?php foreach ($grupos as $lote => $itens): ?>
        <section class="lote">
            <h2><?= e($lote) ?> · <?= count($itens) ?> exemplar(es)</h2>
            <table>
                <colgroup><col style="width:7%"><col style="width:14%"><col style="width:27%"><col style="width:16%"><col style="width:14%"><col style="width:7%"><col style="width:15%"></colgroup>
                <thead><tr><th>Ordem</th><th>Código BDP</th><th>Título</th><th>Autor</th><th>Localização</th><th>Carimbado</th><th>Observações</th></tr></thead>
                <tbody>
                <?php foreach ($itens as $l): ?>
                    <tr>
                        <td class="ordem"><?= e((string)$l['ordem']) ?></td>
                        <td class="codigo"><?= e($l['codigo']) ?><div class="pequeno">ID <?= (int)$l['id'] ?></div></td>
                        <td><?= e(mb_strimwidth($l['titulo'], 0, 110, '…')) ?><div class="pequeno"><?= e($l['cadastro']) ?> · <?= e($l['responsavel']) ?></div></td>
                        <td><?= e(mb_strimwidth($l['autores'], 0, 60, '…')) ?></td>
                        <td class="pequeno"><?= e($l['localizacao']) ?></td>
                        <td class="marcar"><?= $l['carimbo'] === 'Carimbado' ? '☑' : '☐' ?></td>
                        <td class="obs pequeno"><?= e($l['observacoes']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
    <p class="dica">Depois de carimbar, confirme no sistema: Planilha de Códigos BDP → selecione os exemplares → “Marcar selecionados como carimbados”. Os códigos já são definitivos: nunca reescreva um código em outro livro.</p>
</div>
</body>
</html>
<?php
    exit;
}

// ---------- Tela ----------
try {
    $consulta = $filtros;
    $resultado = $filtros['aba'] === 'lotes' ? null : preparo_repo()->planilha($consulta, max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
    $indicadores = preparo_repo()->indicadores();
    $lotes = preparo_repo()->lotes($filtros['aba'] === 'lotes' ? 100 : 200);
    $loteAberto = preparo_repo()->loteAberto($usuario['id']);
    $responsaveis = preparo_repo()->responsaveis();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar a Planilha de Códigos BDP', fisico_erro_tecnico($e, 'planilha de códigos BDP') . ' Confira se a migração 150 foi aplicada (php database/migrar.php).');
    exit;
}
$chave = OperacaoService::novaChave();
$filtrosLink = array_filter($filtros, fn ($v) => $v !== '' && !is_array($v));
$temFiltro = $filtros['lote'] !== '' || $filtros['data'] !== '' || $filtros['responsavel'] !== '' || $filtros['q'] !== '' || $filtros['encontrado'] !== '';

admin_page_start('planilha-bdp', 'Planilha de Códigos BDP', 'Exemplares', 'Códigos BDP para carimbar, triagem e lotes', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], ['Planilha de Códigos BDP']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Planilha de Códigos BDP</h1>
        <p>Cada exemplar registrado aparece aqui sozinho, com o lote e a ordem de cadastramento. Imprima a lista, escreva os códigos nos carimbos seguindo a ordem dos livros separados e confirme o carimbo. O código já fica reservado ao exemplar desde o registro e nunca é reaproveitado.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('movimentacao-lote')) ?>"><?= icon('move') ?> Movimentação em lote</a>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('acoes-recentes')) ?>"><?= icon('undo-2') ?> Ações recentes</a>
    </div>
</section>

<?= admin_flash_html() ?>

<?= fisico_indicadores_preparo_html($indicadores, $base) ?>

<nav class="adm-tabs adm-tabs--links adm-tabs--solta" aria-label="Listas">
    <?php foreach (['carimbo' => ['stamp', 'Aguardando carimbo', $indicadores['aguardando_carimbo']], 'triagem' => ['inbox', 'Área de triagem', $indicadores['aguardando_organizacao']], 'todos' => ['list', 'Todos os exemplares', null], 'lotes' => ['layers', 'Lotes', count($lotes)]] as $a => [$ic, $rot, $n]): ?>
        <a class="adm-tab<?= $filtros['aba'] === $a ? ' is-active' : '' ?>" href="<?= e(admin_url_filtros($base, ['aba' => $a])) ?>"<?= $filtros['aba'] === $a ? ' aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($rot) ?><?= $n !== null ? ' <span class="adm-tab__count">' . fisico_numero($n) . '</span>' : '' ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($filtros['aba'] === 'lotes'): ?>
    <div class="adm-org-colunas">
        <section class="adm-card adm-panel" aria-labelledby="titulo-lote-atual">
            <h2 id="titulo-lote-atual" class="adm-panel__title"><?= icon('layers') ?> Seu lote atual</h2>
            <?php $loteAtivoG = lotes_repo()->loteAtivo($usuario['id']); $lotesEmUso = lotes_repo()->emUso(); ?>
            <?php if ($lotesEmUso): ?>
                <?php if ($loteAtivoG): ?>
                    <p><a class="adm-link" href="<?= e(admin_url('lote') . '?id=' . (int)$loteAtivoG['id']) ?>"><strong><?= e(Biblioteca\Repositories\LoteRepository::nome($loteAtivoG)) ?></strong></a> <?= lote_badge($loteAtivoG['status']) ?></p>
                    <?= lote_progresso($loteAtivoG) ?>
                <?php else: ?>
                    <p class="adm-field__hint">Você não tem lote ativo. Escolha um em “Meus lotes”, na página de Conferência do inventário.</p>
                <?php endif; ?>
                <p class="adm-field__hint">Os lotes são abertos por um administrador, com capacidade e usuários responsáveis, e acompanham cada exemplar até a estante definitiva.</p>
                <p><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('lotes')) ?>"><?= icon('history') ?> Histórico de Lotes</a></p>
            <?php elseif ($loteAberto): ?>
                <p><strong><?= e(PreparoRepository::rotuloLote($loteAberto)) ?></strong> · <?= fisico_numero((int)$loteAberto['exemplares']) ?> exemplar(es)</p>
                <p class="adm-field__hint">Os exemplares que você registrar entram neste lote, na ordem do registro.</p>
            <?php else: ?>
                <p class="adm-field__hint">Você ainda não tem lote aberto. O primeiro exemplar que você registrar abre um lote automaticamente, ou abra um agora com o nome da equipe.</p>
            <?php endif; ?>
            <?php if ($podeGerenciar && !$lotesEmUso): ?>
                <form method="post" action="<?= e($base) ?>" class="adm-form-grid" data-enviar-uma-vez>
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="novo_lote">
                    <label class="adm-field"><span class="adm-field__sublabel">Equipe <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="equipe" maxlength="80" placeholder="Ex.: Equipe 1" value="<?= e((string)($loteAberto['equipe'] ?? '')) ?>"></label>
                    <label class="adm-field"><span class="adm-field__sublabel">Descrição <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="descricao" maxlength="255" placeholder="Ex.: caixas da sala 2"></label>
                    <div class="adm-field adm-field--full"><button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('plus') ?> <?= $loteAberto && (int)$loteAberto['exemplares'] > 0 ? 'Fechar este lote e começar outro' : 'Abrir lote' ?></button></div>
                </form>
            <?php endif; ?>
            <details class="adm-config-detalhes">
                <summary>Como trabalhar com lotes</summary>
                <ol>
                    <li>Registre os exemplares normalmente; não é preciso parar para carimbar.</li>
                    <li>Coloque cada livro registrado na área de triagem, <strong>em pilha separada por lote e na mesma ordem do registro</strong>.</li>
                    <li>Imprima o lote. A coluna “Ordem” diz qual livro da pilha recebe cada código.</li>
                    <li>Carimbe na ordem e confirme “Marcar como carimbado”. Livros com o mesmo título: confira pela ordem e pelo ID, nunca só pelo título.</li>
                </ol>
            </details>
        </section>
        <section class="adm-card adm-panel" aria-labelledby="titulo-lotes">
            <h2 id="titulo-lotes" class="adm-panel__title"><?= icon('list-ordered') ?> Lotes de cadastramento</h2>
            <div class="adm-table-wrap adm-table-wrap--flush">
                <table class="adm-table adm-table--cards adm-table--compacta">
                    <thead><tr><th scope="col">Lote</th><th scope="col">Exemplares</th><th scope="col">Aguardando carimbo</th><th scope="col">Aguardando organização</th><th scope="col">Situação</th><th scope="col"><span class="adm-visually-hidden">Ações</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($lotes as $lt): ?>
                        <tr>
                            <td class="adm-td-title"><strong><?= e(PreparoRepository::rotuloLote($lt)) ?></strong><small class="adm-block-muted"><?= e((string)$lt['responsavel_nome']) ?><?= $lt['descricao'] ? ' · ' . e($lt['descricao']) : '' ?></small></td>
                            <td data-label="Exemplares"><?= fisico_numero((int)$lt['exemplares']) ?></td>
                            <td data-label="Aguardando carimbo"><?= fisico_numero((int)$lt['aguardando_carimbo']) ?></td>
                            <td data-label="Aguardando organização"><?= fisico_numero((int)$lt['aguardando_organizacao']) ?></td>
                            <td data-label="Situação"><?= lote_badge($lt['status']) ?></td>
                            <td class="adm-td-actions">
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['aba' => 'todos', 'lote' => (int)$lt['id']])) ?>"><?= icon('eye') ?> Integrantes</a>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['saida' => 'imprimir', 'carimbo' => 'todos', 'lote' => (int)$lt['id']])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Reimprimir lote</a>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('lote') . '?id=' . (int)$lt['id']) ?>"><?= icon('layers') ?> Relatório</a>
                                <?php if ($podeGerenciar && $lt['status'] === 'aberto' && ($lt['modo'] ?? 'automatico') === 'automatico'): ?>
                                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Fechar o <?= e(PreparoRepository::rotuloLote($lt)) ?>? Os próximos registros de quem o abriu vão para um lote novo.">
                                        <?= csrf_campo() ?><input type="hidden" name="acao" value="fechar_lote"><input type="hidden" name="lote_id" value="<?= (int)$lt['id'] ?>">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('archive') ?> Fechar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$lotes): ?><tr><td colspan="6"><div class="adm-empty"><?= icon('layers') ?><strong>Nenhum lote ainda.</strong><span>O primeiro exemplar registrado abre o primeiro lote.</span></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
<?php else: ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-planilha">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-planilha"><?= $filtros['aba'] === 'triagem' ? 'Área de triagem — aguardando organização' : ($filtros['aba'] === 'carimbo' ? 'Exemplares aguardando carimbo' : 'Exemplares registrados') ?></h2>
                <p aria-live="polite"><?= fisico_numero($resultado['total']) ?> exemplar(es)<?= $filtros['aba'] === 'triagem' ? ' sem estante definitiva. Organize por lote com a Movimentação em lote.' : '' ?></p>
            </div>
        </div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <input type="hidden" name="aba" value="<?= e($filtros['aba']) ?>">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span>
                <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Código BDP, título ou autor..." autocomplete="off"></label>
            <div class="adm-filters">
                <label class="adm-filter"><span>Carimbo</span><select class="adm-select" name="carimbo">
                    <?php foreach (PreparoRepository::FILTRO_CARIMBO as $v => $r): ?><option value="<?= e($v) ?>"<?= $filtros['carimbo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Organização</span><select class="adm-select" name="organizacao">
                    <?php foreach (PreparoRepository::FILTRO_ORGANIZACAO as $v => $r): ?><option value="<?= e($v) ?>"<?= $filtros['organizacao'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Conferência física</span><select class="adm-select" name="encontrado">
                    <?php foreach (PreparoRepository::FILTRO_ENCONTRADO as $v => $r): ?><option value="<?= e($v) ?>"<?= $filtros['encontrado'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Lote</span><select class="adm-select" name="lote"><option value="">Todos</option>
                    <?php foreach ($lotes as $lt): ?><option value="<?= (int)$lt['id'] ?>"<?= $filtros['lote'] === (string)$lt['id'] ? ' selected' : '' ?>><?= e(PreparoRepository::rotuloLote($lt)) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Cadastrados em</span><input class="adm-input" type="date" name="data" value="<?= e($filtros['data']) ?>"></label>
                <label class="adm-filter"><span>Responsável</span><select class="adm-select" name="responsavel"><option value="">Todos</option>
                    <?php foreach ($responsaveis as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $filtros['responsavel'] === (string)$r['id'] ? ' selected' : '' ?>><?= e($r['nome']) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Ordem</span><select class="adm-select" name="ordem"><option value="lote">Lote e ordem de cadastramento</option><option value="codigo"<?= $filtros['ordem'] === 'codigo' ? ' selected' : '' ?>>Código BDP</option></select></label>
            </div>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Filtrar</span></button>
            <?php if ($temFiltro): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['aba' => $filtros['aba']])) ?>"><?= icon('filter-x') ?> Limpar filtros</a><?php endif; ?>
        </form>

        <div class="adm-preparo-saidas">
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['saida' => 'imprimir'])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Imprimir lista (filtro atual)</a>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['saida' => 'exportar', 'formato' => 'xlsx'])) ?>"><?= icon('file-down') ?> Exportar para Excel</a>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['saida' => 'exportar', 'formato' => 'csv'])) ?>"><?= icon('file-text') ?> CSV</a>
            <span class="adm-field__hint">A impressão e a exportação usam só o que está filtrado (ou os selecionados abaixo), nunca a biblioteca inteira.</span>
        </div>

        <form method="post" action="<?= e($base) ?>" data-selecao>
            <?= csrf_campo() ?><input type="hidden" name="chave" value="<?= e($chave) ?>">
            <?php foreach ($filtrosLink as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
            <div class="adm-selecao-barra">
                <span data-selecao-total aria-live="polite">Nenhum exemplar selecionado</span>
                <?php if ($podeGerenciar): ?><button type="submit" name="acao" value="carimbar" class="adm-button adm-button--primary adm-button--sm" data-selecao-enviar disabled><?= icon('stamp') ?> Marcar selecionados como carimbados</button><?php endif; ?>
                <button type="submit" name="acao" value="imprimir" formtarget="_blank" formnovalidate class="adm-button adm-button--ghost adm-button--sm" data-selecao-enviar disabled><?= icon('printer') ?> Imprimir selecionados</button>
                <button type="submit" name="acao" value="exportar" formnovalidate class="adm-button adm-button--ghost adm-button--sm" data-selecao-enviar disabled><?= icon('file-down') ?> Exportar selecionados</button>
            </div>
            <div class="adm-table-wrap adm-table-wrap--flush">
                <table class="adm-table adm-table--cards adm-table--compacta adm-planilha-bdp">
                    <thead><tr>
                        <th scope="col" class="adm-check-col"><input type="checkbox" data-selecao-todos aria-label="Selecionar todos desta página"></th>
                        <th scope="col">Lote · ordem</th><th scope="col">Código BDP</th><th scope="col">Obra</th><th scope="col">Cadastro</th>
                        <th scope="col">Carimbo</th><th scope="col">Localização</th><th scope="col">Observações</th><th scope="col"><span class="adm-visually-hidden">Ações</span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($resultado['itens'] as $x): ?>
                        <tr>
                            <td class="adm-check-col"><input type="checkbox" name="ids[]" value="<?= (int)$x['id'] ?>" data-selecao-item aria-label="Selecionar <?= e($x['codigo_individual']) ?>"></td>
                            <td data-label="Lote · ordem"><?= $x['lote_numero'] !== null ? '<strong>' . e(str_pad((string)$x['lote_numero'], 3, '0', STR_PAD_LEFT)) . '</strong> · nº ' . (int)$x['lote_ordem'] : '<span class="adm-table__muted">Sem lote</span>' ?></td>
                            <td data-label="Código BDP"><a class="adm-link adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$x['id']) ?>"><?= e($x['codigo_individual']) ?></a><small class="adm-block-muted">ID <?= (int)$x['id'] ?></small></td>
                            <td class="adm-td-title" data-label="Obra"><strong><?= e($x['titulo'] ?? 'Sem título') ?></strong><small class="adm-block-muted"><?= e($x['autores'] ?: 'Autor não informado') ?></small></td>
                            <td data-label="Cadastro"><?= e(admin_data_hora($x['criado_em'])) ?><small class="adm-block-muted"><?= e((string)$x['cadastrado_por_nome']) ?></small></td>
                            <td data-label="Carimbo"><?= fisico_badge_carimbo($x['carimbo_status']) ?><?= $x['carimbado_em'] ? '<small class="adm-block-muted">' . e(admin_data_hora($x['carimbado_em'])) . ($x['carimbado_por_nome'] ? ' · ' . e($x['carimbado_por_nome']) : '') . '</small>' : '' ?></td>
                            <td data-label="Localização"><?= $x['localizacao_id'] === null ? '<span class="adm-status adm-tone--amber">' . icon('inbox') . ' Aguardando organização</span>' : e(fisico_local($x)) ?>
                                <?= (int)$x['ocorrencias_abertas'] ? '<a class="adm-status adm-tone--red" href="' . e(admin_url('ocorrencias') . '?exemplar=' . (int)$x['id']) . '">' . icon('triangle-alert') . ' ocorrência</a>' : '' ?></td>
                            <td data-label="Observações"><?= e((string)$x['carimbo_observacao']) ?></td>
                            <td class="adm-td-actions">
                                <?php if ($podeGerenciar && $x['carimbo_status'] === 'aguardando'): ?>
                                    <button type="submit" name="unico" value="carimbar:<?= (int)$x['id'] ?>" class="adm-button adm-button--ghost adm-button--sm"><?= icon('stamp') ?> Marcar como carimbado</button>
                                <?php endif; ?>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('historico-exemplar') . '?id=' . (int)$x['id']) ?>"><?= icon('history') ?> Histórico</a>
                                <?php if ($podeGerenciar): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['obs' => (int)$x['id']])) ?>#obs-<?= (int)$x['id'] ?>"><?= icon('pencil') ?> Observação</a><?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($podeGerenciar && (int)admin_param($_GET, 'obs') === (int)$x['id']): ?>
                            <tr id="obs-<?= (int)$x['id'] ?>"><td colspan="9"><label class="adm-field"><span class="adm-field__sublabel">Observação de <?= e($x['codigo_individual']) ?></span>
                                <input class="adm-input" name="texto" maxlength="255" value="<?= e((string)$x['carimbo_observacao']) ?>" placeholder="Ex.: carimbo borrado, refazer"></label>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit" name="unico" value="observacao:<?= (int)$x['id'] ?>"><?= icon('save') ?> Salvar observação</button></td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$resultado['itens']): ?>
                        <tr><td colspan="9"><div class="adm-empty"><?= icon('stamp') ?><strong><?= $filtros['aba'] === 'carimbo' ? 'Nenhum exemplar aguardando carimbo.' : 'Nenhum exemplar com estes filtros.' ?></strong><span>Os exemplares aparecem aqui assim que são registrados.</span></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtrosLink, [50, 100, 200]) ?>
    </section>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
