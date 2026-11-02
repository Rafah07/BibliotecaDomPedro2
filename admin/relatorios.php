<?php
/**
 * Central de relatórios administrativos (Etapa 5).
 *
 * Uma página com seções (acervo, exemplares, empréstimos, atrasos, alunos, inventários, CDD e
 * atividades). Cada seção tem indicadores, gráficos, tabelas paginadas e exportação em CSV,
 * XLSX e PDF com os mesmos filtros. Tudo é leitura: esta página não altera nenhum dado
 * (a única gravação é o registro da exportação em acoes_administrativas).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/relatorios.php';

use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\RelatorioService;
use Biblioteca\Support\PeriodoRelatorio;

$usuario = relatorios_iniciar();
$secao = admin_param($_GET, 'secao', 'visao');
if (!isset(RelatorioService::SECOES[$secao])) {
    $secao = 'visao';
}
if (!relatorios_pode_secao($secao)) {
    http_response_code(403);
    admin_pagina_erro('Acesso não permitido', 'Seu perfil não tem permissão para este relatório. Fale com a bibliotecária responsável.', 'lock');
    exit;
}

$ctx = relatorios_contexto($secao, $_GET);
$params = relatorios_parametros($ctx);
$servico = relatorios_servico();
$usaPeriodo = in_array($secao, RelatorioService::SECOES_COM_PERIODO, true);

// ---------- Exportação ----------
$formato = admin_param($_GET, 'formato');
if ($formato !== '') {
    if ($ctx['periodo']->erro !== null) {
        http_response_code(422);
        admin_pagina_erro('Período inválido', $ctx['periodo']->erro . ' Corrija o período antes de exportar.', 'calendar');
        exit;
    }
    try {
        relatorios_exportar(admin_param($_GET, 'tabela'), $formato, $ctx, $usuario);
    } catch (PDOException $e) {
        http_response_code(500);
        admin_pagina_erro('Não foi possível gerar o arquivo', fisico_erro_tecnico($e, 'exportação de relatório'));
        exit;
    }
}

// ---------- Dados da tela ----------
$tabelas = RelatorioService::tabelasDaSecao($secao, $ctx);
$ver = in_array(admin_param($_GET, 'ver'), $tabelas, true) ? admin_param($_GET, 'ver') : ($tabelas[0] ?? null);
$porPagina = in_array((int)admin_param($_GET, 'por_pagina'), [25, 50, 100], true) ? (int)admin_param($_GET, 'por_pagina') : 25;
$pagina = max(1, (int)admin_param($_GET, 'pagina', '1'));
try {
    $cards = $servico->indicadores($secao, $ctx);
    $graficos = $servico->graficos($secao, $ctx);
    $blocos = [];
    foreach ($tabelas as $t) {
        $total = $servico->contar($t, $ctx);
        $principal = $t === $ver;
        $paginas = max(1, (int)ceil($total / ($principal ? $porPagina : 10)));
        $pag = $principal ? min($pagina, $paginas) : 1;
        $blocos[$t] = [
            'total' => $total,
            'pagina' => $pag,
            'linhas' => $servico->linhas($t, $ctx, ($pag - 1) * ($principal ? $porPagina : 10), $principal ? $porPagina : 10),
            'principal' => $principal,
        ];
    }
    $repo = relatorios_repo();
    $opcoes = match ($secao) {
        'acervo' => ['categorias' => $repo->categorias(), 'anos' => $repo->anosPublicacao()],
        'exemplares' => ['localizacoes' => $repo->localizacoes()],
        'emprestimos' => ['categorias' => $repo->categorias(), 'turmas' => auth_pode('relatorios.alunos') ? $repo->turmas() : []],
        'alunos' => ['turmas' => $repo->turmas()],
        'inventarios' => ['inventarios' => $repo->inventarios('', 0, 100), 'padrao' => $repo->inventarioPadrao()],
        'atividades' => ['responsaveis' => $repo->responsaveisAtividades()],
        default => [],
    };
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os relatórios', fisico_erro_tecnico($e, 'central de relatórios'));
    exit;
}

$f = $ctx['f'];
$base = admin_url('relatorios');
$sel = fn ($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$secoes = relatorios_secoes_visiveis();
$info = RelatorioService::SECOES[$secao];

/** Observações sobre como os números são calculados, por seção. */
$notas = [
    'visao' => 'Números do momento atual, calculados com as mesmas regras do dashboard. Registros bibliográficos e exemplares físicos são contados separadamente.',
    'acervo' => 'Registros bibliográficos são as obras e edições cadastradas; não são exemplares. A quantidade das planilhas antigas aparece só como referência histórica e nunca é somada aos exemplares físicos. O filtro de período usa a data de cadastro no sistema, que não é a data de aquisição do livro.',
    'exemplares' => 'Somente exemplares realmente cadastrados (com código BDP). Registros bibliográficos e quantidades das planilhas não entram nestas contas. Números da situação atual.',
    'emprestimos' => 'Cada empréstimo conta uma vez, mesmo que tenha sido renovado. Empréstimos do período são contados pela data de retirada; devoluções, pela data da devolução; renovações, pela data da renovação. "Ativos" e "atrasados" mostram a situação de agora (como no dashboard). Datas no fuso da escola (' . str_replace('_', ' ', relatorios_regras()->fuso) . ').',
    'atrasos' => 'Atraso = empréstimo em aberto com a devolução prevista antes de hoje (' . RelatorioService::data($ctx['hoje']) . '). O sistema não calcula multas. O período vale para o histórico de devoluções feitas após o prazo.',
    'alunos' => 'Números agregados por turma e turno. Listas com nomes só aparecem para perfis autorizados e não formam rankings. Alunos não têm conta de acesso ao sistema.',
    'inventarios' => 'Progresso = exemplares previstos já conferidos ÷ exemplares previstos no início da conferência. Exemplar não conferido não é considerado extraviado: o extravio só existe por decisão registrada no inventário.',
    'cdd' => 'Somente classificações com situação "aprovada" contam como aprovadas. A CDD antiga das planilhas aparece separada e nunca é tratada como aprovada. Esta página não altera classificações.',
    'atividades' => 'Registro das ações feitas no painel, somente para consulta: nada aqui pode ser alterado ou apagado. Senhas, e-mails e endereços IP não são exibidos.',
];

admin_page_start($secao === 'atividades' ? 'atividades' : 'relatorios', 'Relatórios — ' . $info['titulo'], 'Relatórios', 'Estatísticas e exportação de dados', [
    'assets/css/acervo.css?v=20261102', 'assets/css/relatorios.css?v=20261101',
]);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Relatórios', $base], [$info['titulo']]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($info['titulo']) ?></h1>
        <p><?= e($info['descricao']) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <button type="button" class="adm-button adm-button--ghost" data-imprimir><?= icon('printer') ?> Imprimir tela</button>
    </div>
</section>

<nav class="rel-nav" aria-label="Seções dos relatórios">
    <?php foreach ($secoes as $k => $s): ?>
        <a href="<?= e($base . ($k === 'visao' ? '' : '?secao=' . $k)) ?>"<?= $k === $secao ? ' aria-current="page"' : '' ?>><?= icon($s['icone']) ?> <span><?= e($s['titulo']) ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if ($ctx['periodo']->erro !== null): ?>
    <?= admin_aviso('erro', 'Período não aplicado', $ctx['periodo']->erro . ' Mostrando: ' . $ctx['periodo']->rotulo() . '.') ?>
<?php endif; ?>

<?php if ($secao !== 'visao'): ?>
<form class="adm-card rel-filters" method="get" action="<?= e($base) ?>" data-loading-form aria-label="Filtros">
    <input type="hidden" name="secao" value="<?= e($secao) ?>">
    <?php if ($usaPeriodo): ?>
        <div class="rel-filters__group rel-periodo" data-periodo>
            <label class="adm-field"><span class="adm-field__label">Período</span>
                <select class="adm-select" name="periodo" data-periodo-select>
                    <?php foreach (PeriodoRelatorio::PRESETS as $k => $r): ?>
                        <option value="<?= e($k) ?>"<?= $sel($ctx['periodo']->preset, $k) ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-field" data-periodo-datas><span class="adm-field__label">De</span>
                <input class="adm-input" type="date" name="de" value="<?= e($ctx['periodo']->preset === 'personalizado' ? (string)$ctx['periodo']->inicio : (string)admin_param($_GET, 'de')) ?>" min="<?= PeriodoRelatorio::MENOR_DATA ?>">
            </label>
            <label class="adm-field" data-periodo-datas><span class="adm-field__label">Até</span>
                <input class="adm-input" type="date" name="ate" value="<?= e($ctx['periodo']->preset === 'personalizado' ? (string)$ctx['periodo']->fim : (string)admin_param($_GET, 'ate')) ?>" min="<?= PeriodoRelatorio::MENOR_DATA ?>">
            </label>
        </div>
    <?php endif; ?>

    <div class="rel-filters__group">
        <?php if ($secao === 'acervo'): ?>
            <label class="adm-field"><span class="adm-field__label">Título ou ISBN</span><input class="adm-input" type="search" name="titulo" value="<?= e($f['titulo']) ?>" autocomplete="off"></label>
            <label class="adm-field"><span class="adm-field__label">Autor</span><input class="adm-input" type="search" name="autor" value="<?= e($f['autor']) ?>" autocomplete="off"></label>
            <label class="adm-field"><span class="adm-field__label">Categoria</span>
                <select class="adm-select" name="categoria"><option value="">Todas</option><option value="sem"<?= $sel($f['categoria'], 'sem') ?>>Não informado</option>
                    <?php foreach ($opcoes['categorias'] as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $sel($f['categoria'], $c['id']) ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Ano de publicação</span>
                <select class="adm-select" name="ano"><option value="">Todos</option><option value="sem"<?= $sel($f['ano'], 'sem') ?>>Não informado</option>
                    <?php foreach ($opcoes['anos'] as $a): ?><option value="<?= $a ?>"<?= $sel($f['ano'], $a) ?>><?= $a ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Situação do registro</span>
                <select class="adm-select" name="status"><option value="">Todas</option>
                    <?php foreach (RelatorioService::STATUS_LIVRO as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['status'], $k) ?>><?= e($r) ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Dados</span>
                <select class="adm-select" name="pendencia"><option value="">Todos os registros</option>
                    <?php foreach (RelatorioService::PENDENCIAS_LIVRO as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['pendencia'], $k) ?>><?= e($r) ?></option><?php endforeach; ?>
                </select></label>
        <?php elseif ($secao === 'exemplares'): ?>
            <label class="adm-field"><span class="adm-field__label">Código BDP</span><input class="adm-input adm-mono" type="search" name="codigo" value="<?= e($f['codigo']) ?>" placeholder="BDP-000123" autocomplete="off"></label>
            <label class="adm-field"><span class="adm-field__label">Título ou autor</span><input class="adm-input" type="search" name="q" value="<?= e($f['q']) ?>" autocomplete="off"></label>
            <label class="adm-field"><span class="adm-field__label">Situação</span>
                <select class="adm-select" name="situacao"><option value="">Todas</option><option value="no_acervo"<?= $sel($f['situacao'], 'no_acervo') ?>>No acervo (sem baixados e extraviados)</option>
                    <?php foreach (\Biblioteca\Repositories\RelatorioRepository::SITUACOES_EXEMPLAR as $s): ?><option value="<?= $s ?>"<?= $sel($f['situacao'], $s) ?>><?= e(ExemplarService::rotuloSituacao($s)) ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Localização</span>
                <select class="adm-select" name="localizacao"><option value="">Todas</option><option value="sem"<?= $sel($f['localizacao'], 'sem') ?>>Sem localização definida</option>
                    <?php foreach ($opcoes['localizacoes'] as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $sel($f['localizacao'], $l['id']) ?>><?= e(ExemplarService::localizacaoTexto($l) . ((int)$l['ativa'] ? '' : ' (desativada)')) ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Conservação</span>
                <select class="adm-select" name="conservacao"><option value="">Todas</option>
                    <?php foreach (\Biblioteca\Repositories\RelatorioRepository::CONSERVACAO as $c): ?><option value="<?= $c ?>"<?= $sel($f['conservacao'], $c) ?>><?= e(ExemplarService::rotuloConservacao($c)) ?></option><?php endforeach; ?>
                </select></label>
        <?php elseif ($secao === 'emprestimos'): ?>
            <label class="adm-field"><span class="adm-field__label">Situação</span>
                <select class="adm-select" name="situacao"><?php foreach (RelatorioService::SITUACOES_EMPRESTIMO as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['situacao'], $k) ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            <label class="adm-field"><span class="adm-field__label">Livro (título ou código)</span><input class="adm-input" type="search" name="titulo" value="<?= e($f['titulo']) ?>" autocomplete="off"></label>
            <?php if ($f['livro']): ?><input type="hidden" name="livro" value="<?= (int)$f['livro'] ?>"><?php endif; ?>
            <label class="adm-field"><span class="adm-field__label">Categoria</span>
                <select class="adm-select" name="categoria"><option value="">Todas</option>
                    <?php foreach ($opcoes['categorias'] as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $sel($f['categoria'], $c['id']) ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
                </select></label>
            <?php if ($opcoes['turmas']): ?>
                <label class="adm-field"><span class="adm-field__label">Turma</span>
                    <select class="adm-select" name="turma"><option value="">Todas</option>
                        <?php foreach ($opcoes['turmas'] as $t): ?><option value="<?= e($t) ?>"<?= $sel($f['turma'], $t) ?>><?= e($t) ?></option><?php endforeach; ?>
                    </select></label>
            <?php endif; ?>
        <?php elseif ($secao === 'atrasos'): ?>
            <label class="adm-field"><span class="adm-field__label">Faixa de atraso</span>
                <select class="adm-select" name="faixa"><option value="">Todas</option>
                    <?php foreach (RelatorioService::FAIXAS_ATRASO as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['faixa'], $k) ?>><?= e($r) ?></option><?php endforeach; ?>
                </select></label>
        <?php elseif ($secao === 'alunos'): ?>
            <label class="adm-field"><span class="adm-field__label">Turma</span>
                <select class="adm-select" name="turma"><option value="">Todas</option>
                    <?php foreach ($opcoes['turmas'] as $t): ?><option value="<?= e($t) ?>"<?= $sel($f['turma'], $t) ?>><?= e($t) ?></option><?php endforeach; ?>
                </select></label>
        <?php elseif ($secao === 'inventarios'): ?>
            <label class="adm-field"><span class="adm-field__label">Situação</span>
                <select class="adm-select" name="status"><option value="">Todos</option><option value="realizados"<?= $sel($f['status'], 'realizados') ?>>Realizados (sem cancelados)</option>
                    <?php foreach (['em_andamento' => 'Em andamento ou pausados', 'concluido' => 'Concluídos', 'aberto' => 'Em preparação', 'cancelado' => 'Cancelados'] as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['status'], $k) ?>><?= e($r) ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Inventário dos gráficos</span>
                <select class="adm-select" name="inventario"><option value="">Automático (o mais recente)</option>
                    <?php foreach ($opcoes['inventarios'] as $i): ?><option value="<?= (int)$i['id'] ?>"<?= $sel($f['inventario'], $i['id']) ?>>Nº <?= (int)$i['id'] ?> — <?= e($i['titulo']) ?></option><?php endforeach; ?>
                </select></label>
        <?php elseif ($secao === 'cdd'): ?>
            <label class="adm-field"><span class="adm-field__label">Título ou autor</span><input class="adm-input" type="search" name="q" value="<?= e($f['q']) ?>" autocomplete="off"></label>
            <label class="adm-field"><span class="adm-field__label">Situação</span>
                <select class="adm-select" name="status"><option value="">Todas</option>
                    <?php foreach (['aprovada' => 'Aprovada', 'sem_aprovacao' => 'Sem aprovação', 'pendente' => 'Aguardando classificação', 'sugerida' => 'Pendente de validação', 'em_revisao' => 'Aguardando revisão', 'conflito' => 'Conflito', 'antiga' => 'Com CDD antiga (não aprovada)'] as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['status'], $k) ?>><?= e($r) ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Classe principal (aprovadas)</span>
                <select class="adm-select" name="classe"><option value="">Todas</option>
                    <?php foreach (RelatorioService::CLASSES_CDD as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['classe'], $k) ?>><?= $k ?>00 — <?= e($r) ?></option><?php endforeach; ?>
                </select></label>
        <?php elseif ($secao === 'atividades'): ?>
            <label class="adm-field"><span class="adm-field__label">Tipo de ação</span>
                <select class="adm-select" name="modulo"><option value="">Todas</option>
                    <?php foreach (relatorios_modulos_atividade() as $k => $r): ?><option value="<?= $k ?>"<?= $sel($f['modulo'], $k) ?>><?= e($r) ?></option><?php endforeach; ?>
                </select></label>
            <label class="adm-field"><span class="adm-field__label">Responsável</span>
                <select class="adm-select" name="usuario"><option value="">Todos</option>
                    <?php foreach ($opcoes['responsaveis'] as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $sel($f['usuario'], $r['id']) ?>><?= e($r['nome']) ?></option><?php endforeach; ?>
                </select></label>
        <?php endif; ?>
    </div>
    <div class="rel-filters__actions">
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Aplicar filtros</button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?secao=' . $secao) ?>"><?= icon('filter-x') ?> Limpar</a>
    </div>
    <p class="rel-filters__resumo"><?= icon('calendar') ?> <?= $usaPeriodo ? 'Período: <strong>' . e($ctx['periodo']->rotulo()) . '</strong>' : 'Situação atual (' . e(date('d/m/Y H:i')) . ')' ?>
        <?php $ft = relatorios_filtros_texto($ctx); if ($ft): ?> · Filtros: <?= e(implode(' · ', array_map(fn ($k, $v) => "$k: $v", array_keys($ft), $ft))) ?><?php endif; ?></p>
</form>
<?php endif; ?>

<div class="adm-notice adm-notice--blue rel-nota" role="note"><?= icon('info') ?><div><strong>Como os números são calculados</strong><p><?= e($notas[$secao]) ?></p></div></div>

<section aria-label="Indicadores"><?= relatorios_cards($cards, $params) ?></section>

<?php if ($graficos): ?>
<section class="rel-charts" aria-label="Gráficos">
    <?php foreach ($graficos as $g): ?><?= relatorios_grafico($g) ?><?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($secao === 'visao'): ?>
<section class="rel-atalhos" aria-label="Relatórios disponíveis">
    <?php foreach ($secoes as $k => $s): if ($k === 'visao') continue; ?>
        <a class="adm-card rel-atalho" href="<?= e($base . '?secao=' . $k) ?>">
            <span class="adm-icon-box adm-tone--blue"><?= icon($s['icone']) ?></span>
            <span><strong><?= e($s['titulo']) ?></strong><small><?= e($s['descricao']) ?></small></span>
            <?= icon('arrow-right', 'rel-atalho__seta') ?>
        </a>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($secao === 'alunos' && !relatorios_pode_ver_pessoais()): ?>
    <?= admin_aviso('aviso', 'Listas nominais ocultas', 'Seu perfil vê somente números agregados de alunos. Nomes, matrículas e históricos individuais ficam disponíveis apenas para perfis autorizados.') ?>
<?php endif; ?>
<?php if (in_array($secao, ['emprestimos', 'atrasos'], true) && !$ctx['pessoais']): ?>
    <p class="rel-privacidade"><?= icon('shield-check') ?> As tabelas abaixo não mostram dados de alunos para o seu perfil.</p>
<?php endif; ?>
<?php if ($secao === 'inventarios'): ?>
    <p class="rel-privacidade"><?= icon('file-chart-column') ?> Para a lista de exemplares de um inventário (conferidos, divergências, pendências), abra o inventário e use o <strong>relatório do inventário</strong>, que já exporta em CSV e PDF.</p>
<?php endif; ?>
<?php if ($secao === 'cdd'): ?>
    <p class="rel-privacidade"><?= icon('list-tree') ?> Mais detalhes da reclassificação (fontes, responsáveis, ordem de estante) estão em <a class="adm-link" href="<?= e(admin_url('relatorio-cdd')) ?>">Relatórios CDD</a> e no <a class="adm-link" href="<?= e(admin_url('historico-cdd')) ?>">Histórico CDD</a>.</p>
<?php endif; ?>

<?php foreach ($blocos as $t => $b): $def = RelatorioService::tabela($t); $colunas = RelatorioService::colunas($t, $ctx); ?>
<section class="adm-card adm-panel rel-panel<?= $b['principal'] ? ' rel-panel--principal' : '' ?>" aria-labelledby="tab-<?= e($t) ?>"<?= $b['principal'] ? ' id="tabela"' : '' ?>>
    <div class="adm-panel__header">
        <div>
            <h2 id="tab-<?= e($t) ?>"><?= e($def['titulo']) ?></h2>
            <p><?= relatorios_numero($b['total']) ?> <?= $b['total'] === 1 ? 'linha' : 'linhas' ?><?= !$b['principal'] && $b['total'] > 10 ? ' · mostrando as 10 primeiras' : '' ?></p>
        </div>
        <?= relatorios_botoes_exportar($t, $params, $ctx) ?>
    </div>
    <?= relatorios_tabela_html($t, $colunas, $b['linhas'], $def['tipos'] ?? []) ?>
    <?php if ($b['principal']): ?>
        <?= admin_paginacao($b['total'], $b['pagina'], $porPagina, $base, $params + ($t !== ($tabelas[0] ?? '') ? ['ver' => $t] : []) + ['por_pagina' => $porPagina]) ?>
    <?php elseif ($b['total'] > 10): ?>
        <p class="rel-ver-todos"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $params, ['ver' => $t])) ?>#tabela"><?= icon('list') ?> Ver todas as linhas com paginação</a></p>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<?php
admin_page_end(['assets/js/relatorios.js?v=20261101']);
