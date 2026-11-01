<?php
/**
 * Central de relatórios no painel: permissões, filtros, gráficos, tabelas e exportações.
 *
 * - Só funciona com o banco configurado: no modo demonstrativo não existem números reais e os
 *   relatórios nunca mostram dados fictícios.
 * - As permissões são conferidas no servidor (seção, tabela e formato), não só na tela.
 * - Os arquivos exportados são gerados na hora e enviados direto ao navegador: nada fica
 *   gravado em pastas públicas. O XLSX passa por um temporário privado, apagado em seguida.
 * - Cada exportação é registrada em acoes_administrativas (relatorio.exportar), indicando
 *   quando contém dados pessoais de alunos. Os filtros registrados nunca têm nomes de alunos.
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php';         // acervo_breadcrumb
require_once __DIR__ . '/dashboard-dados.php'; // DASHBOARD_ACOES
require_once __DIR__ . '/acervo-fisico.php';   // etiquetas_biblioteca, inventario_repo

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\RelatorioRepository;
use Biblioteca\Services\RelatorioService;
use Biblioteca\Support\ExportadorTabela;
use Biblioteca\Support\PeriodoRelatorio;
use Biblioteca\Support\RegrasCirculacao;
use Biblioteca\Support\RelatorioPdf;

/** Permissão exigida para abrir cada seção. */
const RELATORIOS_PERMISSAO_SECAO = [
    'visao' => 'relatorios.ver',
    'acervo' => 'relatorios.ver',
    'exemplares' => 'relatorios.ver',
    'emprestimos' => 'relatorios.ver',
    'atrasos' => 'relatorios.ver',
    'alunos' => 'relatorios.alunos',
    'inventarios' => 'relatorios.ver',
    'cdd' => 'relatorios.ver',
    'atividades' => 'relatorios.atividades',
];

/** Período padrão de cada seção. */
const RELATORIOS_PERIODO_PADRAO = ['acervo' => 'todo', 'emprestimos' => 'ano', 'atrasos' => 'ano', 'atividades' => '30'];

const RELATORIOS_FORMATOS = ['csv' => 'CSV', 'xlsx' => 'Excel (XLSX)', 'pdf' => 'PDF'];

/** Cores das séries (validadas para daltonismo); "Atrasados" usa o vermelho de alerta, sempre com rótulo. */
const RELATORIOS_CORES = ['#2457C5', '#0EA5A4', '#D97706', '#7C3AED', '#DB2777', '#65A30D', '#64748B'];
const RELATORIOS_CORES_FIXAS = ['Atrasados' => '#DC2626', 'Outros' => '#94A3B8', 'Não informado' => '#CBD5E1'];

function relatorios_repo(): RelatorioRepository
{
    static $r = null;
    return $r ??= new RelatorioRepository(db());
}

function relatorios_servico(): RelatorioService
{
    static $s = null;
    return $s ??= new RelatorioService(relatorios_repo(), new InventarioRepository(db()), DASHBOARD_ACOES);
}

function relatorios_regras(): RegrasCirculacao
{
    static $r = null;
    return $r ??= RegrasCirculacao::doAmbiente('config_valor');
}

/** Pode ver nome, matrícula e turma de alunos nos relatórios (os mesmos perfis que já veem a circulação). */
function relatorios_pode_ver_pessoais(): bool
{
    return auth_pode('emprestimos.ver') && auth_pode('relatorios.alunos');
}

function relatorios_pode_secao(string $secao): bool
{
    return isset(RELATORIOS_PERMISSAO_SECAO[$secao]) && auth_pode(RELATORIOS_PERMISSAO_SECAO[$secao]);
}

/** Seções que o usuário pode abrir, na ordem do menu. */
function relatorios_secoes_visiveis(): array
{
    return array_filter(RelatorioService::SECOES, fn ($s, $k) => relatorios_pode_secao($k), ARRAY_FILTER_USE_BOTH);
}

/** Início da página: login, permissão e banco obrigatório. */
function relatorios_iniciar(): array
{
    $usuario = auth_exigir('relatorios.ver');
    if (db_modo() !== 'mysql') {
        admin_pagina_erro('Disponível só com o banco de dados', 'Os relatórios usam somente os dados reais do MySQL e nunca mostram números fictícios. Configure o banco para usar a central de relatórios.', 'chart-column');
        exit;
    }
    try {
        db();
    } catch (DbIndisponivel $e) {
        http_response_code(503);
        admin_pagina_erro('Banco de dados indisponível', $e->getMessage());
        exit;
    }
    return $usuario;
}

/**
 * Lê e valida os filtros da seção. Valores inválidos são descartados (nunca chegam ao SQL
 * como texto livre: as listas fechadas são conferidas aqui e no repositório).
 */
function relatorios_filtros(string $secao, array $g): array
{
    $txt = fn (string $k, int $max = 100) => mb_substr(admin_param($g, $k), 0, $max);
    $lista = fn (string $k, array $permitidos) => in_array(admin_param($g, $k), $permitidos, true) ? admin_param($g, $k) : '';
    $id = fn (string $k) => max(0, (int)admin_param($g, $k));
    return match ($secao) {
        'acervo' => [
            'titulo' => $txt('titulo'), 'autor' => $txt('autor'),
            'categoria' => admin_param($g, 'categoria') === 'sem' ? 'sem' : ($id('categoria') ?: ''),
            'ano' => admin_param($g, 'ano') === 'sem' ? 'sem' : ($id('ano') ?: ''),
            'status' => $lista('status', RelatorioRepository::STATUS_LIVRO),
            'pendencia' => $lista('pendencia', RelatorioRepository::PENDENCIAS_LIVRO),
        ],
        'exemplares' => [
            'codigo' => $txt('codigo', 30), 'q' => $txt('q'),
            'situacao' => $lista('situacao', [...RelatorioRepository::SITUACOES_EXEMPLAR, 'no_acervo']),
            'localizacao' => admin_param($g, 'localizacao') === 'sem' ? 'sem' : ($id('localizacao') ?: ''),
            'conservacao' => $lista('conservacao', RelatorioRepository::CONSERVACAO),
        ],
        'emprestimos' => [
            'situacao' => $lista('situacao', RelatorioRepository::SITUACOES_EMPRESTIMO) ?: 'todos',
            'livro' => $id('livro') ?: '', 'titulo' => $txt('titulo'),
            'categoria' => $id('categoria') ?: '',
            // Turma só para quem pode ver dados de alunos (e só turmas que existem).
            'turma' => auth_pode('relatorios.alunos') && in_array(admin_param($g, 'turma'), relatorios_repo()->turmas(), true) ? admin_param($g, 'turma') : '',
        ],
        'atrasos' => ['faixa' => $lista('faixa', array_keys(RelatorioService::FAIXAS_ATRASO))],
        'alunos' => ['turma' => in_array(admin_param($g, 'turma'), relatorios_repo()->turmas(), true) ? admin_param($g, 'turma') : ''],
        'inventarios' => ['status' => $lista('status', ['realizados', 'aberto', 'em_andamento', 'concluido', 'cancelado']), 'inventario' => $id('inventario') ?: ''],
        'cdd' => ['status' => $lista('status', RelatorioRepository::FILTROS_CDD), 'classe' => preg_match('/^[0-9]$/', admin_param($g, 'classe')) ? admin_param($g, 'classe') : '', 'q' => $txt('q')],
        'atividades' => ['modulo' => $lista('modulo', array_keys(relatorios_modulos_atividade())), 'usuario' => $id('usuario') ?: ''],
        default => [],
    };
}

/** Tipos de atividade que o perfil pode consultar (contas de acesso só para quem gerencia contas). */
function relatorios_modulos_atividade(): array
{
    $m = RelatorioService::MODULOS_ATIVIDADE;
    if (!auth_pode('usuarios.gerenciar')) {
        unset($m['usuario']);
    }
    return $m;
}

/** Contexto completo usado pelo serviço. */
function relatorios_contexto(string $secao, array $g): array
{
    $periodo = PeriodoRelatorio::deParametros($g, relatorios_regras()->fuso, RELATORIOS_PERIODO_PADRAO[$secao] ?? 'ano');
    return [
        'secao' => $secao,
        'periodo' => $periodo,
        'hoje' => relatorios_regras()->hoje(),
        'f' => relatorios_filtros($secao, $g),
        'pessoais' => relatorios_pode_ver_pessoais(),
        'ocultar' => auth_pode('usuarios.gerenciar') ? [] : ['usuario'],
    ];
}

/** Parâmetros da URL atual (período + filtros preenchidos), para links e exportações. */
function relatorios_parametros(array $ctx): array
{
    $p = ['secao' => $ctx['secao']];
    if (in_array($ctx['secao'], RelatorioService::SECOES_COM_PERIODO, true)) {
        $p += $ctx['periodo']->parametros();
    }
    foreach ($ctx['f'] as $k => $v) {
        if ($v !== '' && $v !== null && !($k === 'situacao' && $v === 'todos' && $ctx['secao'] === 'emprestimos')) {
            $p[$k] = $v;
        }
    }
    return $p;
}

/** Filtros em texto (PDF, cabeçalho da planilha e registro da exportação). Nunca inclui nomes de alunos. */
function relatorios_filtros_texto(array $ctx): array
{
    $f = $ctx['f'];
    $saida = [];
    $nomeCategoria = function ($id) {
        foreach (relatorios_repo()->categorias() as $c) {
            if ((int)$c['id'] === (int)$id) {
                return $c['nome'];
            }
        }
        return '#' . (int)$id;
    };
    $rotulos = [
        'titulo' => 'Título', 'autor' => 'Autor', 'codigo' => 'Código', 'q' => 'Pesquisa', 'turma' => 'Turma',
    ];
    foreach ($f as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $saida[match ($k) {
            'categoria' => 'Categoria', 'ano' => 'Ano de publicação', 'status' => 'Situação', 'pendencia' => 'Pendência',
            'situacao' => 'Situação', 'localizacao' => 'Localização', 'conservacao' => 'Conservação', 'livro' => 'Livro',
            'faixa' => 'Faixa de atraso', 'inventario' => 'Inventário', 'classe' => 'Classe CDD', 'modulo' => 'Tipo de ação', 'usuario' => 'Responsável',
            default => $rotulos[$k] ?? $k,
        }] = match ($k) {
            'categoria' => $v === 'sem' ? 'Não informado' : $nomeCategoria($v),
            'ano' => $v === 'sem' ? 'Não informado' : (string)$v,
            'status' => $ctx['secao'] === 'cdd' ? (['antiga' => 'Com CDD antiga', 'sem_aprovacao' => 'Sem aprovação'][$v] ?? RelatorioService::STATUS_CDD[$v] ?? $v)
                : ($ctx['secao'] === 'inventarios' ? (['realizados' => 'Realizados'][$v] ?? RelatorioService::STATUS_INVENTARIO[$v] ?? $v) : (RelatorioService::STATUS_LIVRO[$v] ?? $v)),
            'pendencia' => RelatorioService::PENDENCIAS_LIVRO[$v] ?? $v,
            'situacao' => $ctx['secao'] === 'emprestimos' ? (RelatorioService::SITUACOES_EMPRESTIMO[$v] ?? $v) : ($v === 'no_acervo' ? 'No acervo' : \Biblioteca\Services\ExemplarService::rotuloSituacao($v)),
            'conservacao' => \Biblioteca\Services\ExemplarService::rotuloConservacao($v),
            'localizacao' => $v === 'sem' ? 'Sem localização definida' : 'Localização nº ' . (int)$v,
            'livro' => 'Registro nº ' . (int)$v,
            'faixa' => RelatorioService::FAIXAS_ATRASO[$v] ?? $v,
            'inventario' => 'nº ' . (int)$v,
            'classe' => $v . '00 — ' . RelatorioService::CLASSES_CDD[$v],
            'modulo' => RelatorioService::MODULOS_ATIVIDADE[$v] ?? $v,
            'usuario' => 'Conta nº ' . (int)$v,
            default => (string)$v,
        };
    }
    if ($ctx['secao'] === 'emprestimos' && ($f['situacao'] ?? 'todos') === 'todos') {
        unset($saida['Situação']);
    }
    return $saida;
}

// =================================================================
// Exportação
// =================================================================

/**
 * Gera e envia o arquivo. Confere permissão da seção, da exportação e dos dados pessoais;
 * registra a exportação; nunca grava o arquivo em pasta pública.
 */
function relatorios_exportar(string $tabela, string $formato, array $ctx, array $usuario): never
{
    $def = RelatorioService::tabelas()[$tabela] ?? null;
    if ($def === null || $def['secao'] !== $ctx['secao'] || !isset(RELATORIOS_FORMATOS[$formato])) {
        http_response_code(404);
        admin_pagina_erro('Relatório não encontrado', 'Confira o endereço e tente novamente.', 'file-search');
        exit;
    }
    if (!auth_pode('relatorios.exportar')) {
        http_response_code(403);
        admin_pagina_erro('Exportação não permitida', 'Seu perfil pode consultar os relatórios, mas não exportá-los.', 'lock');
        exit;
    }
    $pessoais = RelatorioService::temDadosPessoais($tabela, $ctx);
    if (!empty($def['pessoal']) && empty($ctx['pessoais'])) {
        http_response_code(403);
        admin_pagina_erro('Acesso não permitido', 'Este relatório contém dados pessoais de alunos e seu perfil não pode consultá-lo.', 'lock');
        exit;
    }
    if ($pessoais && !auth_pode('relatorios.exportar_pessoais')) {
        // Quem não pode exportar dados pessoais recebe o arquivo sem as colunas dos alunos.
        if (!empty($def['pessoal'])) {
            http_response_code(403);
            admin_pagina_erro('Exportação não permitida', 'Este relatório contém dados pessoais de alunos. Somente a administração e a bibliotecária podem exportá-lo.', 'lock');
            exit;
        }
        $ctx['pessoais'] = false;
        $pessoais = false;
    }

    $servico = relatorios_servico();
    $colunas = RelatorioService::colunas($tabela, $ctx);
    $tipos = array_intersect_key($def['tipos'] ?? [], $colunas);
    $secao = RelatorioService::SECOES[$ctx['secao']]['titulo'];
    $usaPeriodo = in_array($ctx['secao'], RelatorioService::SECOES_COM_PERIODO, true);
    $periodo = $usaPeriodo ? $ctx['periodo']->rotulo() : 'Situação em ' . date('d/m/Y H:i');
    $filtros = relatorios_filtros_texto($ctx);
    $nome = ExportadorTabela::nomeArquivo('relatorio-' . $ctx['secao'] . '-' . $tabela . '-' . ($usaPeriodo ? $ctx['periodo']->sufixoArquivo() : date('Y-m-d')));
    $total = $servico->contar($tabela, $ctx);

    registrar_acao('relatorio.exportar', 'relatorio', null, [
        'relatorio' => $tabela, 'formato' => $formato, 'linhas' => $total, 'dados_pessoais' => $pessoais,
        'periodo' => $usaPeriodo ? $ctx['periodo']->rotulo() : null, 'filtros' => $filtros,
    ]);

    @set_time_limit(300);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    if ($formato === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '.csv"');
        $out = fopen('php://output', 'w');
        (new ExportadorTabela($colunas, $tipos))->escreverCsv($out, $servico->iterar($tabela, $ctx));
        fclose($out);
        exit;
    }

    if ($formato === 'xlsx') {
        $cabecalho = [etiquetas_biblioteca() . ' — ' . $def['titulo'], 'Período: ' . $periodo . ' · Gerado em ' . date('d/m/Y H:i') . ' por ' . $usuario['nome'],
            'Filtros: ' . ($filtros ? implode(' · ', array_map(fn ($k, $v) => "$k: $v", array_keys($filtros), $filtros)) : 'nenhum')];
        try {
            [$arquivo] = (new ExportadorTabela($colunas, $tipos))->gerarXlsx($servico->iterar($tabela, $ctx), $secao, $cabecalho);
        } catch (RuntimeException $e) {
            error_log('[biblioteca] XLSX indisponível: ' . $e->getMessage());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nome . '.csv"');
            $out = fopen('php://output', 'w');
            (new ExportadorTabela($colunas, $tipos))->escreverCsv($out, $servico->iterar($tabela, $ctx));
            fclose($out);
            exit;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nome . '.xlsx"');
        header('Content-Length: ' . filesize($arquivo));
        readfile($arquivo);
        @unlink($arquivo);
        exit;
    }

    // PDF: indicadores e gráficos da seção (quando a tabela é a principal) e a tabela.
    $pdf = new RelatorioPdf(etiquetas_biblioteca(), $secao . ' — ' . $def['titulo'], $periodo, $filtros, $usuario['nome'], count($colunas) > 7);
    $principal = (RelatorioService::tabelasDaSecao($ctx['secao'], $ctx)[0] ?? '') === $tabela;
    if ($principal) {
        $pdf->secao('Indicadores');
        $pdf->indicadores(array_map(fn ($c) => [$c['titulo'], $c['valor'], $c['descricao']], $servico->indicadores($ctx['secao'], $ctx)));
        foreach ($servico->graficos($ctx['secao'], $ctx) as $g) {
            $serie = $g['tipo'] === 'colunas' && count($g['series']) > 1
                ? array_map(fn ($d) => ['rotulo' => $d['rotulo'], 'valor' => (int)$d[array_key_first($g['series'])]], $g['dados'])
                : $g['dados'];
            if ($g['tipo'] === 'colunas') {
                $serie = array_slice($serie, -24); // períodos longos: as últimas 24 barras (a tabela tem tudo)
            }
            $pdf->grafico($g['titulo'] . (count($g['series']) > 1 ? ' (' . reset($g['series']) . ')' : ''), $serie, 24);
        }
    }
    $pdf->secao($def['titulo']);
    $larguras = [];
    foreach ($colunas as $k => $rot) {
        $tipo = $tipos[$k] ?? 'texto';
        $larguras[$k] = [$rot, $tipo === 'numero' ? 0.9 : (in_array($k, ['titulo', 'rotulo', 'resumo', 'acao', 'localizacao', 'aluno'], true) ? 3 : ($tipo === 'codigo' ? 1.2 : 1.5)), $tipo === 'numero' ? 'direita' : 'esquerda'];
    }
    $pdf->tabela($larguras, $servico->iterar($tabela, $ctx, RelatorioService::LIMITE_PDF), RelatorioService::LIMITE_PDF, $total);
    if ($pessoais) {
        $pdf->paragrafo('Contém dados pessoais de alunos: guarde e descarte este documento com cuidado. Não publique nem compartilhe fora da equipe da biblioteca.', 8, 0.3);
    }
    $conteudo = $pdf->gerar();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nome . '.pdf"');
    header('Content-Length: ' . strlen($conteudo));
    echo $conteudo;
    exit;
}

// =================================================================
// Componentes visuais
// =================================================================

function relatorios_numero(int|float|string $n): string
{
    return is_numeric($n) ? number_format((float)$n, 0, ',', '.') : (string)$n;
}

/** Cartões de indicadores; os que têm filtro viram link para a tabela filtrada. */
function relatorios_cards(array $cards, array $params): string
{
    $html = '<div class="rel-cards">';
    foreach ($cards as $c) {
        $href = isset($c['filtro']) ? admin_url('relatorios') . '?' . http_build_query(array_filter(array_merge($params, $c['filtro'], ['pagina' => null]), fn ($v) => $v !== null && $v !== '')) . '#tabela' : null;
        $tag = $href ? 'a' : 'article';
        $html .= '<' . $tag . ' class="adm-card rel-card' . ($href ? ' rel-card--link' : '') . '"' . ($href ? ' href="' . e($href) . '"' : '') . '>'
            . '<span class="adm-icon-box adm-icon-box--sm adm-tone--' . e($c['tom']) . '">' . icon($c['icone']) . '</span>'
            . '<span class="rel-card__body"><span class="rel-card__title">' . e($c['titulo']) . '</span>'
            . '<span class="rel-card__value">' . e(relatorios_numero($c['valor'])) . '</span>'
            . '<span class="rel-card__desc">' . e($c['descricao']) . '</span></span></' . $tag . '>';
    }
    return $html . '</div>';
}

function relatorios_cor(string $rotulo, int $i): string
{
    return RELATORIOS_CORES_FIXAS[$rotulo] ?? RELATORIOS_CORES[$i % count(RELATORIOS_CORES)];
}

/** Gráfico em HTML/SVG puro (sem bibliotecas externas: funciona sem internet e na impressão). */
function relatorios_grafico(array $g): string
{
    $series = $g['series'];
    $soma = 0;
    foreach ($g['dados'] as $d) {
        foreach ($series as $k => $_) {
            $soma += (int)($d[$k] ?? 0);
        }
    }
    $id = 'graf-' . preg_replace('/[^a-z0-9_]/', '', $g['id']);
    $html = '<figure class="adm-card rel-chart rel-chart--' . e($g['tipo']) . '" aria-labelledby="' . $id . '-t">';
    $html .= '<figcaption><h3 id="' . $id . '-t">' . e($g['titulo']) . '</h3><p>' . e($g['descricao']) . '</p></figcaption>';
    if ($soma === 0) {
        $html .= '<div class="adm-empty rel-chart__empty">' . icon('chart-column') . '<strong>Sem registros para exibir</strong><span>Não há dados para este gráfico com o período e os filtros escolhidos.</span></div>';
        return $html . '</figure>';
    }

    if ($g['tipo'] === 'rosca') {
        $html .= relatorios_rosca($g['dados'], $soma);
    } elseif ($g['tipo'] === 'colunas') {
        $html .= relatorios_colunas($g['dados'], $series);
    } else {
        $html .= relatorios_barras($g['dados']);
    }

    // Os mesmos dados em tabela (acessível e conferível).
    $html .= '<details class="rel-chart__data"><summary>' . icon('list') . ' Ver dados em tabela</summary><div class="adm-table-wrap"><table class="adm-table rel-mini-table"><thead><tr><th scope="col">Item</th>';
    foreach ($series as $rot) {
        $html .= '<th scope="col" class="adm-num">' . e($rot) . '</th>';
    }
    if ($g['tipo'] !== 'colunas') {
        $html .= '<th scope="col" class="adm-num">%</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($g['dados'] as $d) {
        $html .= '<tr><td>' . e($d['rotulo']) . '</td>';
        foreach ($series as $k => $_) {
            $html .= '<td class="adm-num">' . relatorios_numero((int)($d[$k] ?? 0)) . '</td>';
        }
        if ($g['tipo'] !== 'colunas') {
            $html .= '<td class="adm-num">' . number_format((int)$d['valor'] / $soma * 100, 1, ',', '.') . '%</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody>' . ($g['tipo'] !== 'barras' || count($g['dados']) > 1 ? '<tfoot><tr><th scope="row">Total</th>' . implode('', array_map(fn ($k) => '<td class="adm-num"><strong>' . relatorios_numero(array_sum(array_map(fn ($d) => (int)($d[$k] ?? 0), $g['dados']))) . '</strong></td>', array_keys($series))) . ($g['tipo'] !== 'colunas' ? '<td></td>' : '') . '</tr></tfoot>' : '') . '</table></div></details>';
    return $html . '</figure>';
}

function relatorios_barras(array $dados): string
{
    $max = max(1, ...array_map(fn ($d) => (int)$d['valor'], $dados));
    $html = '<ul class="rel-bars" role="list">';
    foreach ($dados as $d) {
        $pct = (int)$d['valor'] / $max * 100;
        $html .= '<li title="' . e($d['rotulo'] . ': ' . relatorios_numero($d['valor'])) . '"><span class="rel-bars__label">' . e($d['rotulo']) . '</span>'
            . '<span class="rel-bars__track"><span class="rel-bars__fill" style="width:' . number_format(max($pct, (int)$d['valor'] > 0 ? 1.5 : 0), 2, '.', '') . '%"></span></span>'
            . '<span class="rel-bars__value">' . relatorios_numero($d['valor']) . '</span></li>';
    }
    return $html . '</ul>';
}

/** Colunas ao longo do tempo (uma ou duas séries, lado a lado). */
function relatorios_colunas(array $dados, array $series): string
{
    $chaves = array_keys($series);
    $max = 1;
    foreach ($dados as $d) {
        foreach ($chaves as $k) {
            $max = max($max, (int)($d[$k] ?? 0));
        }
    }
    $n = count($dados);
    $mostrarValores = $n <= 16;
    $passo = $n > 24 ? (int)ceil($n / 12) : 1; // rótulos espaçados quando há muitas colunas
    $html = '';
    if (count($series) > 1) {
        $html .= '<ul class="rel-legend" role="list">';
        foreach ($series as $k => $rot) {
            $i = array_search($k, $chaves, true);
            $html .= '<li><span class="rel-legend__dot" style="background:' . RELATORIOS_CORES[$i] . '"></span>' . e($rot) . ' <strong>' . relatorios_numero(array_sum(array_map(fn ($d) => (int)($d[$k] ?? 0), $dados))) . '</strong></li>';
        }
        $html .= '</ul>';
    }
    $html .= '<div class="rel-columns-wrap"><div class="rel-columns" style="--rel-n:' . $n . '">';
    foreach ($dados as $i => $d) {
        $titulo = $d['rotulo'] . ': ' . implode(' · ', array_map(fn ($k) => $series[$k] . ' ' . relatorios_numero((int)($d[$k] ?? 0)), $chaves));
        $html .= '<div class="rel-columns__group" title="' . e($titulo) . '"><div class="rel-columns__bars">';
        foreach ($chaves as $j => $k) {
            $v = (int)($d[$k] ?? 0);
            $h = $v / $max * 100;
            $html .= '<span class="rel-columns__bar" style="height:' . number_format(max($h, $v > 0 ? 2 : 0), 2, '.', '') . '%;background:' . RELATORIOS_CORES[$j] . '">'
                . ($mostrarValores && $v > 0 ? '<span class="rel-columns__value">' . relatorios_numero($v) . '</span>' : '') . '</span>';
        }
        $html .= '</div><span class="rel-columns__label"' . ($i % $passo !== 0 ? ' aria-hidden="true" data-oculto' : '') . '>' . e($d['rotulo']) . '</span></div>';
    }
    return $html . '</div></div>';
}

/** Rosca (partes de um todo) com legenda, valores e percentuais. */
function relatorios_rosca(array $dados, int $soma): string
{
    $r = 15.9155; // circunferência 100
    $svg = '<svg viewBox="0 0 42 42" class="rel-donut" role="img" aria-label="' . e(implode('; ', array_map(fn ($d) => $d['rotulo'] . ' ' . $d['valor'], $dados))) . '">'
        . '<circle cx="21" cy="21" r="' . $r . '" fill="none" stroke="#EEF2F7" stroke-width="6"/>';
    $acumulado = 0;
    $legenda = '<ul class="rel-legend rel-legend--stack" role="list">';
    foreach ($dados as $i => $d) {
        $v = (int)$d['valor'];
        $pct = $v / $soma * 100;
        $cor = relatorios_cor($d['rotulo'], $i);
        if ($v > 0) {
            // 0,6 de folga entre as fatias (separação visual).
            $tam = max(0.01, $pct - (count($dados) > 1 ? 0.6 : 0));
            $svg .= '<circle cx="21" cy="21" r="' . $r . '" fill="none" stroke="' . $cor . '" stroke-width="6" stroke-dasharray="' . number_format($tam, 3, '.', '') . ' ' . number_format(100 - $tam, 3, '.', '')
                . '" stroke-dashoffset="' . number_format(25 - $acumulado, 3, '.', '') . '"><title>' . e($d['rotulo'] . ': ' . relatorios_numero($v)) . '</title></circle>';
        }
        $acumulado += $pct;
        $legenda .= '<li><span class="rel-legend__dot" style="background:' . $cor . '"></span><span class="rel-legend__label">' . e($d['rotulo']) . '</span>'
            . '<strong>' . relatorios_numero($v) . '</strong><small>' . number_format($pct, 1, ',', '.') . '%</small></li>';
    }
    $svg .= '<text x="21" y="20.5" text-anchor="middle" class="rel-donut__total">' . e(relatorios_numero($soma)) . '</text><text x="21" y="25.5" text-anchor="middle" class="rel-donut__caption">total</text></svg>';
    return '<div class="rel-donut-wrap">' . $svg . $legenda . '</ul></div>';
}

/** Tabela de uma seção, com links internos (livro, exemplar, empréstimo, aluno, inventário). */
function relatorios_tabela_html(string $tabela, array $colunas, array $linhas, array $tipos): string
{
    $html = '<div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table rel-table"><thead><tr>';
    foreach ($colunas as $k => $rot) {
        $html .= '<th scope="col"' . (($tipos[$k] ?? '') === 'numero' ? ' class="adm-num"' : '') . '>' . e($rot) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($linhas as $l) {
        $html .= '<tr>';
        foreach ($colunas as $k => $rot) {
            $v = $l[$k] ?? '';
            $texto = is_int($v) ? relatorios_numero($v) : e((string)$v);
            $texto = ($texto === '' ? '<span class="adm-table__muted">—</span>' : ($v === 'Não informado' ? '<span class="adm-table__muted">Não informado</span>' : $texto));
            $link = match (true) {
                in_array($k, ['titulo', 'rotulo'], true) && !empty($l['_livro_id']) && $tabela !== 'inventarios' => admin_url('detalhes-livro') . '?id=' . (int)$l['_livro_id'],
                $k === 'codigo' && !empty($l['_exemplar_id']) => admin_url('detalhes-exemplar') . '?id=' . (int)$l['_exemplar_id'],
                $k === 'id' && !empty($l['_emprestimo_id']) && auth_pode('emprestimos.ver') => admin_url('emprestimo') . '?id=' . (int)$l['_emprestimo_id'],
                $k === 'aluno' && !empty($l['_aluno_id']) && auth_pode('emprestimos.ver') => admin_url('historico-aluno') . '?id=' . (int)$l['_aluno_id'],
                in_array($k, ['titulo', 'id'], true) && !empty($l['_inventario_id']) => admin_url('inventario-detalhes') . '?id=' . (int)$l['_inventario_id'],
                $k === 'localizacao' && !empty($l['_localizacao_id']) => admin_url('exemplares') . '?localizacao=' . (int)$l['_localizacao_id'],
                default => null,
            };
            if ($k === 'progresso' && isset($l['_percentual'])) {
                $texto = '<span class="rel-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . e((string)$l['_percentual']) . '"><span style="width:' . min(100, (float)$l['_percentual']) . '%"></span></span><small>' . $texto . '</small>';
            }
            $classe = ($tipos[$k] ?? '') === 'numero' ? ' class="adm-num"' : (($tipos[$k] ?? '') === 'codigo' ? ' class="adm-mono"' : '');
            $html .= '<td data-label="' . e($rot) . '"' . $classe . '>' . ($link ? '<a class="adm-link" href="' . e($link) . '">' . $texto . '</a>' : $texto) . '</td>';
        }
        $html .= '</tr>';
    }
    if (!$linhas) {
        $html .= '<tr data-empty><td colspan="' . count($colunas) . '"><div class="adm-empty">' . icon('inbox') . '<strong>Nenhum registro encontrado</strong><span>Não há informações para os filtros escolhidos. Ajuste o período ou limpe os filtros.</span></div></td></tr>';
    }
    return $html . '</tbody></table></div>';
}

/** Botões de exportação de uma tabela (só para quem pode exportar). */
function relatorios_botoes_exportar(string $tabela, array $params, array $ctx): string
{
    if (!auth_pode('relatorios.exportar')) {
        return '';
    }
    $pessoais = RelatorioService::temDadosPessoais($tabela, $ctx);
    if ($pessoais && !auth_pode('relatorios.exportar_pessoais') && !empty(RelatorioService::tabela($tabela)['pessoal'])) {
        return '<span class="rel-export__note">' . icon('lock') . ' Exportação restrita (dados pessoais)</span>';
    }
    $html = '<div class="rel-export" role="group" aria-label="Exportar">';
    foreach (RELATORIOS_FORMATOS as $f => $rot) {
        $url = admin_url('relatorios') . '?' . http_build_query(array_merge($params, ['tabela' => $tabela, 'formato' => $f, 'pagina' => null]));
        $html .= '<a class="adm-button adm-button--ghost adm-button--sm" href="' . e($url) . '" rel="nofollow">' . icon($f === 'pdf' ? 'file-text' : ($f === 'xlsx' ? 'sheet' : 'file-down')) . ' ' . e($rot) . '</a>';
    }
    if ($pessoais && !auth_pode('relatorios.exportar_pessoais')) {
        $html .= '<span class="rel-export__note">Os arquivos saem sem nomes e matrículas.</span>';
    } elseif ($pessoais) {
        $html .= '<span class="rel-export__note rel-export__note--warn">' . icon('shield-alert') . ' Contém dados pessoais: a exportação fica registrada.</span>';
    }
    return $html . '</div>';
}
