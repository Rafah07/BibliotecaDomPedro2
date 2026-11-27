<?php
/**
 * Inventário como ambiente central: abas internas do inventário, lotes do inventário (migração 240)
 * e pontos de extensão para módulos opcionais (pasta modulos/<nome>).
 *
 * Abas permanentes: Visão geral · Lotes · Conferência · Pendências · Configurações.
 * Um módulo opcional pode acrescentar ou trocar abas (ex.: a organização por equipes acrescenta
 * "Equipes e participantes" e "Histórico" e troca "Pendências" por "Pendências e solicitações")
 * registrando funções em modulos/<nome>/includes/ganchos.php. Sem a pasta do módulo, ou com ele
 * desligado, nada disso é carregado e o inventário funciona sozinho.
 *
 * Eventos usados (o módulo registra com modulo_gancho_registrar):
 *   inventario.abas       ($inv)                         → [chave => [rótulo, ícone, endereço, contador]] (acrescenta/troca abas)
 *   inventario.form       ($inv|null, $post)             → HTML extra no formulário do inventário
 *   inventario.salvo      ($invId, $post, $adminId, $novo) → aplica o que o HTML extra pediu (pode lançar AcervoException)
 *   inventario.visao      ($inv)                         → HTML extra na Visão geral
 *   inventario.avisos_conclusao ($inv)                   → list<string> com o que ainda está em aberto
 *   inventario.concluido  ($invId, $adminId)             → depois de concluir ou cancelar o inventário
 *   conferencia.painel    ($inv)                         → HTML extra na Conferência
 *   lote.form             ($inventarioId, $post)         → HTML extra no formulário único de lote
 *   lote.criado           ($loteId, $post, $adminId)     → na MESMA transação da criação do lote
 *   lote.url              ($lote)                        → endereço da área de trabalho do lote (string)
 *   lote.painel           ($lote)                        → HTML extra no topo da página do lote
 *   lote.linha            ($lote)                        → HTML extra na linha do lote (lista do inventário)
 */

// ---------------------------------------------------------------------
// Pontos de extensão dos módulos opcionais
// ---------------------------------------------------------------------

/** Carrega uma vez os ganchos dos módulos presentes e ligados. */
function modulos_ganchos_carregar(): void
{
    static $carregado = false;
    if ($carregado) {
        return;
    }
    $carregado = true;
    foreach (glob(__DIR__ . '/../../modulos/*/includes/ganchos.php') ?: [] as $arquivo) {
        $nome = basename(dirname($arquivo, 2));
        if (admin_modulo_ativo($nome)) {
            require_once $arquivo;
        }
    }
}

function modulo_gancho_registrar(string $evento, callable $fn): void
{
    $GLOBALS['bdp_ganchos'][$evento][] = $fn;
}

/** Chama os ganchos do evento e devolve os resultados (não nulos). Erros de regra sobem para a página. */
function modulo_gancho(string $evento, mixed ...$args): array
{
    modulos_ganchos_carregar();
    $saida = [];
    foreach ($GLOBALS['bdp_ganchos'][$evento] ?? [] as $fn) {
        $r = $fn(...$args);
        if ($r !== null) {
            $saida[] = $r;
        }
    }
    return $saida;
}

/** HTML dos ganchos do evento. Uma falha do módulo nunca derruba a página permanente. */
function modulo_gancho_html(string $evento, mixed ...$args): string
{
    try {
        return implode('', array_map('strval', modulo_gancho($evento, ...$args)));
    } catch (Throwable $e) {
        error_log('[biblioteca] Gancho ' . $evento . ': ' . $e->getMessage());
        return '';
    }
}

// ---------------------------------------------------------------------
// Abas internas do inventário
// ---------------------------------------------------------------------

/** Ordem das abas (as dos módulos entram nos lugares reservados). */
const INVENTARIO_ABAS_ORDEM = ['visao', 'lotes', 'equipes', 'conferencia', 'pendencias', 'historico', 'configuracoes'];

/** @return array<string, array{0: string, 1: string, 2: string, 3?: int}> */
function inventario_abas(array $inv): array
{
    $id = (int)$inv['id'];
    $abas = [
        'visao' => ['Visão geral', 'layout-dashboard', admin_url('inventario-detalhes') . '?id=' . $id],
        'lotes' => ['Lotes', 'layers', admin_url('inventario-lotes') . '?id=' . $id],
    ];
    if (in_array($inv['status'], ['em_andamento', 'pausado'], true)) {
        $abas['conferencia'] = ['Conferência', 'keyboard', admin_url('inventario-conferencia') . '?id=' . $id];
    }
    if ($inv['status'] !== 'aberto') {
        $abas['pendencias'] = ['Pendências', 'triangle-alert', admin_url('inventario-detalhes') . '?id=' . $id . '&filtro=pendentes#itens'];
    }
    if (!in_array($inv['status'], ['concluido', 'cancelado'], true) && auth_pode('inventario.gerenciar')) {
        $abas['configuracoes'] = ['Configurações', 'settings', admin_url('inventario-editar') . '?id=' . $id];
    }
    try {
        foreach (modulo_gancho('inventario.abas', $inv) as $extras) {
            foreach ((array)$extras as $chave => $aba) {
                $abas[$chave] = $aba;
            }
        }
    } catch (Throwable $e) {
        error_log('[biblioteca] Abas do inventário: ' . $e->getMessage());
    }
    $ordenadas = [];
    foreach (INVENTARIO_ABAS_ORDEM as $chave) {
        if (isset($abas[$chave])) {
            $ordenadas[$chave] = $abas[$chave];
        }
    }
    return $ordenadas + $abas;
}

/** Barra de abas do inventário. $ativa: chave da aba atual. */
function inventario_abas_html(array $inv, string $ativa): string
{
    $h = '<nav class="adm-tabs adm-tabs--links adm-tabs--solta adm-inv-abas adm-no-print" aria-label="Áreas do inventário">';
    foreach (inventario_abas($inv) as $chave => $aba) {
        [$rotulo, $icone, $url] = $aba;
        $cont = (int)($aba[3] ?? 0);
        $atual = $chave === $ativa;
        $h .= '<a class="adm-tab' . ($atual ? ' is-active' : '') . '" href="' . e($url) . '"' . ($atual ? ' aria-current="page"' : '') . '>' . icon($icone) . ' ' . e($rotulo)
            . ($cont ? ' <span class="adm-tabs__count">' . $cont . '</span>' : '') . '</a>';
    }
    return $h . '</nav>';
}

/** Cabeçalho curto das páginas internas do inventário (título, situação e abas). */
function inventario_cabecalho_html(array $inv, string $ativa, string $subtitulo = '', string $acoes = ''): string
{
    $h = acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], [$inv['titulo'], admin_url('inventario-detalhes') . '?id=' . (int)$inv['id']],
        [inventario_abas($inv)[$ativa][0] ?? '']]);
    $h .= '<section class="adm-page-head adm-page-head--inv"><div><h1>' . e($inv['titulo']) . ' ' . fisico_badge_inventario($inv['status']) . '</h1>'
        . ($subtitulo !== '' ? '<p>' . $subtitulo . '</p>' : '') . '</div>' . ($acoes !== '' ? '<div class="adm-page-head__actions">' . $acoes . '</div>' : '') . '</section>';
    return $h . inventario_abas_html($inv, $ativa);
}

// ---------------------------------------------------------------------
// Lotes do inventário
// ---------------------------------------------------------------------

/** Inventários que ainda recebem lotes (em preparação, em andamento ou pausados). */
function inventarios_para_lote(): array
{
    return db_query("SELECT id, titulo, status FROM inventarios WHERE status IN ('aberto', 'em_andamento', 'pausado') ORDER BY FIELD(status, 'em_andamento', 'pausado', 'aberto'), id DESC")->fetchAll();
}

/** Inventário padrão para um lote novo: o em andamento (ou pausado), senão o mais recente em preparação. */
function inventario_padrao_lote(): int
{
    return (int)(inventarios_para_lote()[0]['id'] ?? 0);
}

/** Área de trabalho do lote: a página permanente ou a que um módulo indicar (ex.: lote com equipes). */
function lote_url_trabalho(array $lote): string
{
    try {
        foreach (modulo_gancho('lote.url', $lote) as $url) {
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
    } catch (Throwable $e) {
        error_log('[biblioteca] Endereço do lote: ' . $e->getMessage());
    }
    return admin_url('lote') . '?id=' . (int)$lote['id'];
}

/** Nome de exibição: "Lote 001 · Estante A" (o número continua sendo a identificação oficial). */
function lote_nome_completo(array $lote): string
{
    $n = Biblioteca\Repositories\LoteRepository::nome($lote);
    return !empty($lote['nome']) ? $n . ' · ' . $lote['nome'] : $n;
}

/**
 * O que ainda está em aberto antes de concluir o inventário (aviso; as regras que bloqueiam continuam
 * no InventarioService): lotes não encerrados, exemplares dos lotes sem carimbo ou sem estante e o que
 * os módulos informarem. @return list<string>
 */
function inventario_avisos_conclusao(array $inv): array
{
    $a = [];
    try {
        $t = lotes_repo()->totaisInventario((int)$inv['id']);
        if ($t['em_andamento']) {
            $a[] = $t['em_andamento'] . ' lote(s) deste inventário ainda não encerrado(s).';
        }
        if ($t['cadastrados'] > $t['carimbados']) {
            $a[] = ($t['cadastrados'] - $t['carimbados']) . ' exemplar(es) dos lotes ainda sem carimbo.';
        }
        if ($t['cadastrados'] > $t['organizados']) {
            $a[] = ($t['cadastrados'] - $t['organizados']) . ' exemplar(es) dos lotes ainda sem estante definitiva.';
        }
    } catch (PDOException $e) {
        // migração 240 ainda não aplicada: sem lotes por inventário
    }
    try {
        foreach (modulo_gancho('inventario.avisos_conclusao', $inv) as $lista) {
            array_push($a, ...array_values((array)$lista));
        }
    } catch (Throwable $e) {
        error_log('[biblioteca] Avisos de conclusão: ' . $e->getMessage());
    }
    return $a;
}
