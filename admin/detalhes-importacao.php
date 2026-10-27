<?php
/**
 * Detalhes de uma importação: pré-visualização, revisão das linhas, confirmação e conclusão
 * (etapas 4, 5 e 6). Também é a página de consulta das importações anteriores.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/importacao.php';
require_once __DIR__ . '/includes/acervo.php';

use Biblioteca\Repositories\ImportacaoRepository;

if (db_modo() !== 'mysql') {
    admin_pagina_erro('Importação indisponível', 'Configure o banco MySQL para consultar as importações. No modo demonstrativo nenhuma planilha é gravada.', 'file-spreadsheet');
    exit;
}
try {
    db();
} catch (DbIndisponivel $e) {
    admin_pagina_erro('Banco de dados indisponível', $e->getMessage(), 'triangle-alert');
    exit;
}
auth_exigir('importacao.ver');

$repo = importacao_repo();
$imp = $repo->buscar((int)($_GET['id'] ?? 0));
if (!$imp) {
    http_response_code(404);
    admin_pagina_erro('Importação não encontrada', 'A importação solicitada não existe. Consulte o histórico de importações.', 'file-spreadsheet');
    exit;
}
$id = (int)$imp['id'];
$status = $imp['status'];
$editavel = in_array($status, ImportacaoRepository::STATUS_EDITAVEIS, true);
$podeRevisar = $editavel && auth_pode('importacao.revisar');
$podeExecutar = auth_pode('importacao.executar');

$filtro = is_string($_GET['filtro'] ?? null) && (array_key_exists($_GET['filtro'], ImportacaoRepository::FILTROS) || $_GET['filtro'] === 'problemas') ? $_GET['filtro'] : '';
$busca = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$porPagina = 50;
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$lista = $repo->itens($id, $filtro, $busca, $pagina, $porPagina);
$paginas = max(1, (int)ceil($lista['total'] / $porPagina));
$contagem = $repo->contagemFiltros($id);
$resumo = $repo->resumoConfirmacao($id);

$etapa = match ($status) {
    'enviada' => 2,
    'em_analise' => 3,
    'em_revisao' => $resumo['sem_decisao'] === 0 && $resumo['a_gravar'] > 0 ? 5 : 4,
    'importando' => 5,
    'parcial', 'concluida' => 6,
    default => 1,
};

$indicadores = [
    ['titulo' => 'Total de linhas', 'valor' => (int)$imp['total_linhas'], 'icone' => 'sheet', 'tom' => 'blue', 'filtro' => ''],
    ['titulo' => 'Registros válidos', 'valor' => (int)$imp['total_validos'], 'icone' => 'circle-check', 'tom' => 'green', 'filtro' => 'valido'],
    ['titulo' => 'Com avisos', 'valor' => (int)$imp['total_avisos'], 'icone' => 'triangle-alert', 'tom' => 'amber', 'filtro' => 'aviso'],
    ['titulo' => 'Com erros', 'valor' => (int)$imp['total_erros'], 'icone' => 'circle-alert', 'tom' => 'red', 'filtro' => 'erro'],
    ['titulo' => 'Possíveis duplicidades', 'valor' => (int)$imp['total_duplicidades'], 'icone' => 'copy', 'tom' => 'indigo', 'filtro' => 'duplicidade'],
    ['titulo' => 'Já cadastrados', 'valor' => (int)$imp['total_ja_cadastrados'], 'icone' => 'library-big', 'tom' => 'teal', 'filtro' => 'ja_cadastrado'],
];

$urlBase = admin_url('detalhes-importacao') . '?id=' . $id;
$url = function (array $params) use ($urlBase, $filtro, $busca): string {
    $p = array_filter(array_merge(['filtro' => $filtro, 'q' => $busca], $params), fn ($v) => $v !== '' && $v !== null && $v !== 1);
    return $urlBase . ($p ? '&' . http_build_query($p) : '') . '#revisao';
};
$relatorio = fn (string $tipo) => admin_base() . 'api/importacoes/relatorio.php?tipo=' . $tipo . '&id=' . $id;

$icones = [];
foreach (['plus', 'link', 'copy', 'hourglass', 'ban', 'undo-2', 'search', 'save'] as $nome) {
    $icones[$nome] = ADMIN_ICONS[$nome];
}
$cfgJs = ['api' => admin_base() . 'api/importacoes/', 'csrf' => csrf_token(), 'id' => $id, 'status' => $status, 'icones' => $icones];

/** Primeiro problema relevante da linha, para a coluna "Problema identificado". */
function detalhes_problema(array $item): string
{
    if ($item['resultado_importacao'] === 'falhou') {
        return '<span class="adm-imp-problem-text adm-imp-problem-text--erro">' . htmlspecialchars('Falha ao gravar: ' . $item['mensagem_processamento']) . '</span>';
    }
    $relevantes = array_values(array_filter($item['erros_json'] ?? [], fn ($p) => $p['nivel'] !== 'info'));
    if (!$relevantes) {
        return '<span class="adm-table__muted">—</span>';
    }
    $ordem = ['erro' => 0, 'duplicidade' => 1, 'aviso' => 2];
    usort($relevantes, fn ($a, $b) => $ordem[$a['nivel']] <=> $ordem[$b['nivel']]);
    $extra = count($relevantes) > 1 ? ' <span class="adm-pill adm-tone--muted">+' . (count($relevantes) - 1) . '</span>' : '';
    return '<span class="adm-imp-problem-text adm-imp-problem-text--' . $relevantes[0]['nivel'] . '">' . htmlspecialchars($relevantes[0]['mensagem']) . '</span>' . $extra;
}

function detalhes_decisao(array $item): string
{
    if ($item['resultado_importacao']) {
        $r = IMPORTACAO_RESULTADOS[$item['resultado_importacao']];
        return '<span class="adm-status adm-status--soft adm-tone--' . $r['tom'] . '">' . htmlspecialchars($r['label']) . '</span>';
    }
    if (!$item['acao_importacao']) {
        return $item['status_validacao'] === 'erro'
            ? '<span class="adm-status adm-status--soft adm-tone--red">Corrigir ou ignorar</span>'
            : '<span class="adm-status adm-status--soft adm-tone--amber">Aguardando decisão</span>';
    }
    $a = IMPORTACAO_ACOES[$item['acao_importacao']];
    $bloqueada = $item['status_validacao'] === 'erro' && in_array($item['acao_importacao'], ['criar', 'vincular', 'outro_exemplar'], true);
    return '<span class="adm-status adm-status--soft adm-tone--' . ($bloqueada ? 'red' : $a['tom']) . '">' . htmlspecialchars($a['label']) . ($bloqueada ? ' (corrija o erro)' : '') . '</span>';
}

admin_page_start('importar', $imp['nome_arquivo'], 'Importação de Planilhas', 'Acervo · Planilhas antigas da biblioteca', ['assets/css/acervo.css?v=20261027', 'assets/css/importacao.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Importação', admin_url('importar')], ['Histórico', admin_url('historico-importacoes')], ['Importação nº ' . $id]]) ?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Importação nº <?= $id ?></p>
        <h1 class="adm-imp-title"><?= icon('file-spreadsheet') ?> <?= htmlspecialchars($imp['nome_arquivo']) ?></h1>
        <p>
            Enviada em <?= importacao_data($imp['criado_em']) ?> por <?= htmlspecialchars($imp['usuario_nome'] ?? '—') ?>
            · <?= importacao_tamanho((int)$imp['tamanho_bytes']) ?> · <?= strtoupper($imp['tipo_arquivo']) ?>
            <?= $imp['aba'] && $imp['tipo_arquivo'] === 'xlsx' ? ' · aba "' . htmlspecialchars($imp['aba']) . '"' : '' ?>
            <?= $imp['finalizado_em'] ? ' · finalizada em ' . importacao_data($imp['finalizado_em']) : '' ?>
        </p>
        <p class="adm-imp-status-line"><?= importacao_badge_status($status) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars($relatorio('inconsistencias')) ?>"><?= icon('file-down') ?> Baixar relatório de inconsistências</a>
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars($relatorio('completo')) ?>"><?= icon('download') ?> Relatório completo</a>
        <?php if (in_array($status, ['concluida', 'parcial', 'cancelada'], true) && ((int)$imp['total_importados'] + (int)$imp['total_vinculados']) > 0): ?>
            <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars($relatorio('importados')) ?>"><?= icon('download') ?> Registros importados</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($status !== 'cancelada' && $status !== 'falhou'): ?>
    <section class="adm-card adm-panel adm-imp-steps-card"><?= importacao_etapas($etapa) ?></section>
<?php endif; ?>

<?php if (in_array($status, ['enviada', 'em_analise'], true)): ?>
    <div class="adm-notice adm-notice--amber adm-notice--action" role="note">
        <?= icon('hourglass') ?>
        <div class="adm-notice__content">
            <strong><?= $status === 'enviada' ? 'O mapeamento das colunas ainda não foi feito.' : 'A análise desta planilha não terminou.' ?></strong>
            <p>Continue a importação para ver a pré-visualização das linhas.</p>
        </div>
        <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('importar') . '?id=' . $id . '#nova-importacao') ?>">Continuar <?= icon('arrow-right') ?></a>
    </div>
<?php elseif ($status === 'cancelada'): ?>
    <div class="adm-notice adm-notice--amber" role="note">
        <?= icon('ban') ?>
        <div class="adm-notice__content">
            <strong>Importação cancelada<?= $imp['finalizado_em'] ? ' em ' . importacao_data($imp['finalizado_em']) : '' ?>.</strong>
            <p><?= ((int)$imp['total_importados'] + (int)$imp['total_vinculados']) > 0
                ? 'As linhas gravadas antes do cancelamento continuam no acervo. O histórico abaixo fica guardado para consulta.'
                : 'Nenhum registro foi gravado no acervo. O histórico abaixo fica guardado para consulta.' ?></p>
        </div>
    </div>
<?php elseif (in_array($status, ['concluida', 'parcial'], true)): ?>
    <div class="adm-notice adm-notice--<?= $status === 'concluida' ? 'green' : 'amber' ?> adm-notice--action" role="status">
        <?= icon($status === 'concluida' ? 'circle-check' : 'triangle-alert') ?>
        <div class="adm-notice__content">
            <strong><?= $status === 'concluida' ? 'Importação concluída.' : 'Importação concluída com pendências.' ?></strong>
            <dl class="adm-notice__numbers">
                <div><dt>Livros criados</dt><dd><?= number_format((int)$imp['total_importados'], 0, ',', '.') ?></dd></div>
                <div><dt>Vinculados a livros existentes</dt><dd><?= number_format((int)$imp['total_vinculados'], 0, ',', '.') ?></dd></div>
                <div><dt>Ignorados</dt><dd><?= number_format((int)$imp['total_ignorados'], 0, ',', '.') ?></dd></div>
                <div><dt>Pendentes ou com falha</dt><dd><?= number_format($resumo['sem_decisao'] + $resumo['revisar_depois'] + $resumo['com_erro'] + $resumo['falharam'], 0, ',', '.') ?></dd></div>
            </dl>
            <p>Os livros importados ficam <strong>aguardando reclassificação CDD</strong> e <strong>aguardando inventário físico</strong>. Nenhum exemplar ou código BDP foi criado: eles nascem na conferência física.<?= $status === 'parcial' ? ' As linhas pendentes podem ser revisadas e importadas depois, nesta mesma página.' : '' ?></p>
        </div>
        <a class="adm-button adm-button--primary" href="<?= htmlspecialchars($url(['filtro' => 'importado', 'pagina' => null])) ?>"><?= icon('list') ?> Consultar registros importados</a>
    </div>
<?php endif; ?>

<section class="adm-kpis adm-kpis--6" aria-label="Resultado da análise">
    <?php foreach ($indicadores as $k): ?>
        <a class="adm-card adm-kpi adm-kpi--action<?= $filtro === $k['filtro'] ? ' is-active' : '' ?>" href="<?= htmlspecialchars($url(['filtro' => $k['filtro'], 'pagina' => null])) ?>">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $k['tom'] ?>"><?= icon($k['icone']) ?></span>
            <span>
                <strong class="adm-kpi__value"><?= number_format($k['valor'], 0, ',', '.') ?></strong>
                <span class="adm-kpi__title"><?= htmlspecialchars($k['titulo']) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" id="revisao" data-revisao aria-labelledby="titulo-revisao">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-revisao"><?= $editavel ? 'Revisão dos registros' : 'Registros da planilha' ?></h2>
            <p><?= $editavel
                ? 'Clique em uma linha para ver os dados originais e normalizados, corrigir valores ou decidir o que fazer com ela.'
                : 'Clique em uma linha para consultar os dados originais, os problemas encontrados e o resultado.' ?></p>
        </div>
    </div>

    <nav class="adm-tabs adm-imp-tabs" aria-label="Filtrar por situação">
        <?php foreach (ImportacaoRepository::FILTROS as $valor => $rotulo): ?>
            <?php if ($valor !== '' && empty($contagem[$valor]) && $filtro !== $valor) continue; ?>
            <a href="<?= htmlspecialchars($url(['filtro' => $valor, 'pagina' => null])) ?>"<?= $filtro === $valor ? ' aria-current="page"' : '' ?>>
                <?= htmlspecialchars($rotulo) ?> <span class="adm-tabs__count"><?= number_format($contagem[$valor] ?? 0, 0, ',', '.') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="adm-toolbar adm-imp-toolbar" method="get" action="<?= htmlspecialchars(admin_url('detalhes-importacao')) ?>#revisao" role="search">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php if ($filtro !== ''): ?><input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>"><?php endif; ?>
        <label class="adm-field-search">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar linhas</span>
            <input type="search" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="Pesquisar por título, autor, ISBN ou número da linha..." autocomplete="off">
        </label>
        <button type="submit" class="adm-button adm-button--ghost adm-button--sm"><?= icon('search') ?> Pesquisar</button>
        <?php if ($busca !== '' || $filtro !== ''): ?>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars($urlBase . '#revisao') ?>"><?= icon('filter-x') ?> Limpar filtros</a>
        <?php endif; ?>
    </form>

    <?php if ($podeRevisar): ?>
        <?php
        $lote = [];
        $identicas = (int)$repo->contagemFiltros($id)['duplicidade'];
        if ($identicas) {
            $lote[] = ['filtro' => 'linha_identica', 'decisao' => 'ignorar', 'rotulo' => 'Ignorar linhas idênticas', 'icone' => 'ban', 'confirmar' => 'Ignorar todas as linhas idênticas a uma linha anterior que ainda não tiveram decisão? Você pode desfazer linha a linha.'];
            $lote[] = ['filtro' => 'linha_identica', 'decisao' => 'outro_exemplar', 'rotulo' => 'Linhas idênticas = outros exemplares', 'icone' => 'copy', 'confirmar' => 'Registrar cada linha idêntica como outro exemplar da primeira ocorrência? Use quando a planilha tiver uma linha por exemplar.'];
        }
        if ($contagem['ja_cadastrado']) {
            $lote[] = ['filtro' => 'ja_cadastrado', 'decisao' => 'vincular', 'rotulo' => 'Vincular as já cadastradas por ISBN', 'icone' => 'link', 'confirmar' => 'Vincular ao livro existente todas as linhas cujo ISBN já está no acervo (e que ainda não tiveram decisão)? Os cadastros existentes não serão alterados.'];
        }
        if ($contagem['erro']) {
            $lote[] = ['filtro' => 'erro', 'decisao' => 'ignorar', 'rotulo' => 'Ignorar linhas com erro', 'icone' => 'ban', 'confirmar' => 'Ignorar todas as linhas com erro que ainda não tiveram decisão? Elas continuarão no histórico e no relatório.'];
        }
        ?>
        <?php if ($lote): ?>
            <div class="adm-imp-bulk">
                <span><?= icon('layers') ?> Decisões em lote:</span>
                <?php foreach ($lote as $l): ?>
                    <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-decidir-lote="<?= $l['decisao'] ?>" data-filtro="<?= $l['filtro'] ?>" data-confirmar="<?= htmlspecialchars($l['confirmar']) ?>" data-bloquear-durante><?= icon($l['icone']) ?> <?= htmlspecialchars($l['rotulo']) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$lista['itens']): ?>
        <div class="adm-empty">
            <span class="adm-icon-box adm-icon-box--lg adm-tone--blue"><?= icon('inbox') ?></span>
            <strong><?= (int)$imp['total_linhas'] === 0 ? 'Nenhuma linha analisada ainda.' : 'Nenhuma linha encontrada com estes filtros.' ?></strong>
        </div>
    <?php else: ?>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards adm-imp-table">
                <thead>
                    <tr>
                        <th scope="col">Linha</th>
                        <th scope="col">Título</th>
                        <th scope="col">Autor</th>
                        <th scope="col">ISBN</th>
                        <th scope="col">CDD original</th>
                        <th scope="col">Situação</th>
                        <th scope="col">Problema identificado</th>
                        <th scope="col" class="adm-actions-col">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista['itens'] as $item): ?>
                        <?php $n = $item['dados_normalizados_json']; ?>
                        <tr data-item="<?= (int)$item['id'] ?>" tabindex="0" class="adm-imp-row adm-imp-row--<?= $item['status_validacao'] ?>">
                            <td data-label="Linha"><span class="adm-mono">#<?= (int)$item['numero_linha'] ?></span></td>
                            <td data-label="Título" class="adm-td-wrap">
                                <strong><?= $n['titulo'] !== null ? htmlspecialchars($n['titulo']) : '<span class="adm-missing">Sem título</span>' ?></strong>
                                <?php if ($item['dados_corrigidos_json']): ?><span class="adm-pill adm-tone--blue">corrigido</span><?php endif; ?>
                                <?php if ($item['livro_resultante_id']): ?>
                                    <a class="adm-block-muted adm-link" href="<?= htmlspecialchars(admin_url('detalhes-livro') . '?id=' . (int)$item['livro_resultante_id']) ?>">Livro nº <?= (int)$item['livro_resultante_id'] ?> no acervo</a>
                                <?php endif; ?>
                            </td>
                            <td data-label="Autor"><?= $n['autor'] !== null ? htmlspecialchars($n['autor']) : '<span class="adm-missing">—</span>' ?></td>
                            <td data-label="ISBN" class="adm-mono"><?= $n['isbn_informado'] !== null ? htmlspecialchars($n['isbn_informado']) : '<span class="adm-missing">—</span>' ?></td>
                            <td data-label="CDD original" class="adm-mono"><?= $n['cdd_original'] !== null ? htmlspecialchars($n['cdd_original']) : '<span class="adm-missing">—</span>' ?></td>
                            <td data-label="Situação"><?= importacao_badge_situacao($item['status_validacao']) ?></td>
                            <td data-label="Problema" class="adm-td-wrap"><?= detalhes_problema($item) ?></td>
                            <td data-label="Ações" class="adm-actions-col">
                                <div class="adm-imp-actions">
                                    <?= detalhes_decisao($item) ?>
                                    <button type="button" class="adm-button adm-button--ghost adm-button--sm"><?= icon($podeRevisar ? 'pencil' : 'eye') ?> <?= $podeRevisar ? 'Revisar' : 'Ver' ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="adm-pagination">
            <p class="adm-pagination__info">
                Mostrando <?= number_format(($pagina - 1) * $porPagina + 1, 0, ',', '.') ?>–<?= number_format(min($pagina * $porPagina, $lista['total']), 0, ',', '.') ?>
                de <?= number_format($lista['total'], 0, ',', '.') ?> linhas
            </p>
            <?php if ($paginas > 1): ?>
                <nav class="adm-imp-pages" aria-label="Páginas">
                    <?php if ($pagina > 1): ?><a href="<?= htmlspecialchars($url(['pagina' => $pagina - 1])) ?>" aria-label="Página anterior"><?= icon('chevron-left') ?></a><?php endif; ?>
                    <?php for ($p = max(1, $pagina - 2); $p <= min($paginas, $pagina + 2); $p++): ?>
                        <a href="<?= htmlspecialchars($url(['pagina' => $p])) ?>"<?= $p === $pagina ? ' aria-current="page"' : '' ?>><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($pagina < $paginas): ?><a href="<?= htmlspecialchars($url(['pagina' => $pagina + 1])) ?>" aria-label="Próxima página"><?= icon('chevron-right') ?></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($editavel || $status === 'importando'): ?>
<section class="adm-card adm-panel adm-imp-confirm" id="confirmar" aria-labelledby="titulo-confirmar">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-confirmar">Resumo da importação</h2>
            <p>Confira o que será gravado antes de confirmar. Os cadastros já existentes no acervo não são alterados.</p>
        </div>
    </div>

    <ul class="adm-imp-resume">
        <li><strong><?= number_format((int)$imp['total_linhas'], 0, ',', '.') ?></strong> linhas analisadas</li>
        <li><strong><?= number_format((int)$imp['total_validos'], 0, ',', '.') ?></strong> registros válidos</li>
        <li><strong><?= number_format((int)$imp['total_avisos'], 0, ',', '.') ?></strong> com avisos</li>
        <li><strong><?= number_format((int)$imp['total_duplicidades'], 0, ',', '.') ?></strong> possíveis duplicidades</li>
        <li><strong><?= number_format((int)$imp['total_erros'], 0, ',', '.') ?></strong> registros com erros</li>
        <li><strong><?= number_format((int)$imp['total_ignorados'], 0, ',', '.') ?></strong> registros ignorados</li>
    </ul>

    <div class="adm-imp-plan">
        <div class="adm-imp-plan__col adm-imp-plan__col--ok">
            <h3><?= icon('circle-check') ?> Será gravado no acervo</h3>
            <dl>
                <div><dt>Novos livros (cadastro bibliográfico)</dt><dd><?= number_format($resumo['criar'], 0, ',', '.') ?></dd></div>
                <div><dt>Vinculados a livros já existentes</dt><dd><?= number_format($resumo['vincular'], 0, ',', '.') ?></dd></div>
                <div><dt>Registrados como outro exemplar</dt><dd><?= number_format($resumo['outro_exemplar'], 0, ',', '.') ?></dd></div>
            </dl>
            <p>Para cada linha gravada, os dados antigos (CDD, código do autor, localização, assunto e quantidade) ficam guardados para a reclassificação e o inventário.</p>
        </div>
        <div class="adm-imp-plan__col">
            <h3><?= icon('ban') ?> Não será gravado agora</h3>
            <dl>
                <div><dt>Aguardando decisão</dt><dd><?= number_format($resumo['sem_decisao'], 0, ',', '.') ?></dd></div>
                <div><dt>Marcados para revisar depois</dt><dd><?= number_format($resumo['revisar_depois'], 0, ',', '.') ?></dd></div>
                <div><dt>Com erro não corrigido</dt><dd><?= number_format($resumo['com_erro'], 0, ',', '.') ?></dd></div>
                <div><dt>Ignorados</dt><dd><?= number_format($resumo['ignorar'], 0, ',', '.') ?></dd></div>
                <?php if ($resumo['falharam']): ?><div><dt>Falharam na gravação anterior</dt><dd><?= number_format($resumo['falharam'], 0, ',', '.') ?></dd></div><?php endif; ?>
            </dl>
            <p>Nenhum exemplar nem código BDP é criado: a quantidade informada é só referência para o inventário físico.</p>
        </div>
    </div>

    <div class="adm-imp-progress adm-imp-progress--inline" data-confirmacao-progresso data-total="<?= $resumo['a_gravar'] + ($status === 'importando' ? $resumo['ja_gravadas'] : 0) ?>" data-feitos="<?= $status === 'importando' ? $resumo['ja_gravadas'] : 0 ?>" hidden role="status" aria-live="polite">
        <strong>Gravando no acervo…</strong>
        <div class="adm-progress adm-progress--lg"><div class="adm-progress__bar adm-progress__bar--ok" data-confirmacao-barra style="width: 0%"></div></div>
        <small data-confirmacao-texto>Preparando.</small>
        <p class="adm-field__error" data-confirmacao-erro role="alert"></p>
        <button type="button" class="adm-button adm-button--primary adm-button--sm" data-confirmacao-retomar hidden><?= icon('refresh-cw') ?> Continuar gravação</button>
    </div>

    <?php if ($editavel): ?>
        <div class="adm-form-actions adm-form-actions--split">
            <a class="adm-button adm-button--ghost" href="#revisao"><?= icon('arrow-left') ?> Voltar para revisão</a>
            <div class="adm-imp-confirm__buttons">
                <?php if (auth_pode('importacao.revisar') && (((int)$imp['total_importados'] + (int)$imp['total_vinculados']) === 0 || $podeExecutar)): ?>
                    <button type="button" class="adm-button adm-button--ghost adm-button--danger-text" data-modal-open="modal-cancelar" data-bloquear-durante><?= icon('x') ?> Cancelar importação</button>
                <?php endif; ?>
                <?php if ($podeExecutar): ?>
                    <button type="button" class="adm-button adm-button--primary" data-modal-open="modal-confirmar" data-bloquear-durante<?= $resumo['a_gravar'] === 0 ? ' disabled title="Nenhuma linha aprovada para gravar"' : '' ?>><?= icon('check') ?> Importar registros aprovados</button>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$podeExecutar): ?>
            <p class="adm-form-actions__note"><?= icon('lock') ?> Somente a bibliotecária ou um administrador pode confirmar a importação definitiva.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Detalhes de uma linha -->
<dialog class="adm-modal adm-modal--wide adm-imp-modal" id="modal-linha" aria-labelledby="modal-linha-titulo">
    <div class="adm-modal__box">
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('sheet') ?></span>
            <div>
                <h2 id="modal-linha-titulo" data-linha-titulo>Linha</h2>
                <p data-linha-subtitulo></p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body" data-linha-corpo></div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Fechar</button>
        </footer>
    </div>
</dialog>

<?php if ($editavel && $podeExecutar): ?>
<dialog class="adm-modal" id="modal-confirmar" aria-labelledby="modal-confirmar-titulo">
    <div class="adm-modal__box">
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--green"><?= icon('check') ?></span>
            <div>
                <h2 id="modal-confirmar-titulo">Importar registros aprovados?</h2>
                <p>A gravação é feita em blocos, com transações. Se a conexão cair, nada é gravado duas vezes.</p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <ul class="adm-imp-confirm-list">
                <li><?= icon('book-plus') ?> <span><strong><?= number_format($resumo['criar'], 0, ',', '.') ?></strong> novos livros serão cadastrados, com situação "Aguardando conferência".</span></li>
                <li><?= icon('link') ?> <span><strong><?= number_format($resumo['vincular'], 0, ',', '.') ?></strong> linhas serão vinculadas a livros existentes, sem alterar esses cadastros.</span></li>
                <li><?= icon('copy') ?> <span><strong><?= number_format($resumo['outro_exemplar'], 0, ',', '.') ?></strong> linhas serão registradas como outro exemplar de um livro.</span></li>
                <li><?= icon('ban') ?> <span><strong><?= number_format($resumo['sem_decisao'] + $resumo['revisar_depois'] + $resumo['com_erro'] + $resumo['ignorar'], 0, ',', '.') ?></strong> linhas ficarão de fora (ignoradas, com erro ou sem decisão).</span></li>
            </ul>
            <p class="adm-field__hint">Nenhum exemplar, código BDP ou nova CDD será criado. Os dados antigos ficam preservados para a reclassificação e o inventário.</p>
            <p class="adm-field__error" data-erro-confirmar role="alert"></p>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Voltar para revisão</button>
            <button type="button" class="adm-button adm-button--primary" data-confirmar-importacao><?= icon('check') ?> Importar <?= number_format($resumo['a_gravar'], 0, ',', '.') ?> registros</button>
        </footer>
    </div>
</dialog>
<?php endif; ?>

<?php if ($editavel): ?>
<dialog class="adm-modal" id="modal-cancelar" aria-labelledby="modal-cancelar-titulo">
    <div class="adm-modal__box">
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--red"><?= icon('triangle-alert') ?></span>
            <div>
                <h2 id="modal-cancelar-titulo">Cancelar esta importação?</h2>
                <p>Nenhuma outra linha será gravada. O histórico e o relatório continuam disponíveis.</p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <div class="adm-modal__body">
            <p><?= ((int)$imp['total_importados'] + (int)$imp['total_vinculados']) > 0
                ? 'As linhas que já foram gravadas no acervo permanecem lá. Para importar o restante depois, não cancele: use "Revisar depois".'
                : 'Nada desta planilha foi gravado no acervo. Depois de cancelar, o mesmo arquivo poderá ser enviado de novo.' ?></p>
            <p class="adm-field__error" data-erro-cancelar role="alert"></p>
        </div>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Voltar</button>
            <button type="button" class="adm-button adm-button--danger" data-cancelar-importacao><?= icon('x') ?> Cancelar importação</button>
        </footer>
    </div>
</dialog>
<?php endif; ?>

<script type="application/json" id="revisao-config"><?= json_encode($cfgJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
admin_page_end(['assets/js/importacao.js?v=20261027']);
