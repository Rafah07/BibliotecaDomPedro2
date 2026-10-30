<?php
/**
 * Componentes comuns das páginas do painel: mensagens de retorno (flash),
 * endereços com filtros, paginação no servidor e cabeçalhos de ordenação.
 * Usado pelas listagens de livros e de administradores.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icones.php';

/** Guarda uma mensagem para a próxima página (padrão Post/Redirect/Get). $tipo: sucesso | erro | aviso */
function admin_flash(string $tipo, string $titulo, string $mensagem = ''): void
{
    auth_sessao();
    $_SESSION['flash'][] = ['tipo' => $tipo, 'titulo' => $titulo, 'mensagem' => $mensagem];
}

/** Mostra (e descarta) as mensagens guardadas. */
function admin_flash_html(): string
{
    auth_sessao();
    $lista = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $html = '';
    foreach ($lista as $f) {
        $html .= admin_aviso($f['tipo'], $f['titulo'], $f['mensagem']);
    }
    return $html;
}

/** Caixa de aviso no padrão visual do painel. */
function admin_aviso(string $tipo, string $titulo, string $mensagem = ''): string
{
    [$tom, $icone, $papel] = match ($tipo) {
        'sucesso' => ['green', 'circle-check', 'status'],
        'erro' => ['red', 'circle-alert', 'alert'],
        default => ['amber', 'triangle-alert', 'status'],
    };
    return '<div class="adm-notice adm-notice--' . $tom . '" role="' . $papel . '">' . icon($icone)
        . '<div><strong>' . e($titulo) . '</strong>' . ($mensagem !== '' ? '<p>' . e($mensagem) . '</p>' : '') . '</div></div>';
}

/** Redireciona e encerra (depois de um POST bem-sucedido). */
function admin_redirecionar(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Valor de texto vindo de $_GET/$_POST (ignora arrays e corta espaços). */
function admin_param(array $origem, string $nome, string $padrao = ''): string
{
    $v = $origem[$nome] ?? $padrao;
    return is_string($v) ? trim($v) : $padrao;
}

/** Recusa POST sem token CSRF válido, com uma página de erro amigável. */
function admin_exigir_csrf(): void
{
    if (!csrf_valido()) {
        http_response_code(419);
        admin_pagina_erro('Formulário expirado', 'A página ficou aberta por muito tempo ou foi enviada de outro lugar. Volte, recarregue a página e tente novamente.', 'clock-alert');
        exit;
    }
}

/** Endereço da página atual com os filtros mesclados ($params null remove o parâmetro). */
function admin_url_filtros(string $base, array $atuais, array $mudancas = []): string
{
    $params = array_filter(array_merge($atuais, $mudancas), fn ($v) => $v !== null && $v !== '' && $v !== false);
    return $base . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Cabeçalho de coluna ordenável (link). $ordem/$direcao são os valores atuais.
 * O primeiro clique ordena em $direcaoPadrao; o seguinte inverte.
 */
function admin_th_ordenar(string $rotulo, string $campo, string $base, array $filtros, string $direcaoPadrao = 'asc'): string
{
    $ativo = ($filtros['ordem'] ?? '') === $campo;
    $dir = $ativo ? (($filtros['dir'] ?? 'asc') === 'asc' ? 'desc' : 'asc') : $direcaoPadrao;
    $url = admin_url_filtros($base, $filtros, ['ordem' => $campo, 'dir' => $dir, 'pagina' => null]);
    $aria = $ativo ? ((($filtros['dir'] ?? 'asc') === 'asc') ? 'ascending' : 'descending') : 'none';
    $icone = $ativo ? ((($filtros['dir'] ?? 'asc') === 'asc') ? 'arrow-up' : 'arrow-down') : 'arrow-up-down';
    return '<th scope="col" aria-sort="' . $aria . '"><a class="adm-th-sort' . ($ativo ? ' is-active' : '') . '" href="' . e($url) . '">'
        . e($rotulo) . ' ' . icon($icone) . '</a></th>';
}

/** Paginação feita no servidor: total de resultados, tamanho da página e links. */
function admin_paginacao(int $total, int $pagina, int $porPagina, string $base, array $filtros, array $tamanhos = [25, 50, 100]): string
{
    $paginas = max(1, (int)ceil($total / $porPagina));
    $inicio = $total ? ($pagina - 1) * $porPagina + 1 : 0;
    $fim = min($total, $pagina * $porPagina);
    $fmt = fn (int $n) => number_format($n, 0, ',', '.');

    $html = '<div class="adm-pagination">';
    $html .= '<form class="adm-pagination__size" method="get" action="' . e($base) . '">';
    foreach ($filtros as $nome => $valor) {
        if ($nome !== 'por_pagina' && $nome !== 'pagina' && $valor !== '' && $valor !== null) {
            $html .= '<input type="hidden" name="' . e($nome) . '" value="' . e($valor) . '">';
        }
    }
    $html .= '<label>Mostrar <select class="adm-select adm-select--sm" name="por_pagina" data-auto-submit>';
    foreach ($tamanhos as $t) {
        $html .= '<option value="' . $t . '"' . ($t === $porPagina ? ' selected' : '') . '>' . $t . '</option>';
    }
    $html .= '</select> por página</label><noscript><button class="adm-button adm-button--ghost adm-button--sm" type="submit">Aplicar</button></noscript></form>';
    $html .= '<p class="adm-pagination__info">' . ($total ? 'Mostrando ' . $fmt($inicio) . '–' . $fmt($fim) . ' de ' . $fmt($total) : 'Nenhum resultado') . '</p>';

    $html .= '<nav class="adm-pagination__pages" aria-label="Páginas">';
    $link = function (int $p, string $rotulo, string $aria = '', bool $desabilitado = false, bool $atual = false) use ($base, $filtros): string {
        if ($desabilitado) {
            return '<span class="adm-page is-disabled" aria-disabled="true"' . ($aria ? ' aria-label="' . e($aria) . '"' : '') . '>' . $rotulo . '</span>';
        }
        $url = admin_url_filtros($base, $filtros, ['pagina' => $p > 1 ? $p : null]);
        return '<a class="adm-page' . ($atual ? ' is-active' : '') . '" href="' . e($url) . '"' . ($atual ? ' aria-current="page"' : '') . ($aria ? ' aria-label="' . e($aria) . '"' : '') . '>' . $rotulo . '</a>';
    };
    $html .= $link($pagina - 1, icon('chevron-left'), 'Página anterior', $pagina <= 1);
    $mostrar = array_unique(array_filter([1, $pagina - 2, $pagina - 1, $pagina, $pagina + 1, $pagina + 2, $paginas], fn ($p) => $p >= 1 && $p <= $paginas));
    sort($mostrar);
    $anterior = 0;
    foreach ($mostrar as $p) {
        if ($p - $anterior > 1) {
            $html .= '<span class="adm-page adm-page--gap" aria-hidden="true">…</span>';
        }
        $html .= $link($p, (string)$p, 'Página ' . $p, false, $p === $pagina);
        $anterior = $p;
    }
    $html .= $link($pagina + 1, icon('chevron-right'), 'Próxima página', $pagina >= $paginas);
    return $html . '</nav></div>';
}

/** Data e hora do banco (Y-m-d H:i:s) no formato brasileiro. */
function admin_data_hora(?string $valor, bool $hora = true): string
{
    if (!$valor) {
        return '—';
    }
    $d = date_create($valor);
    return $d ? $d->format($hora ? 'd/m/Y H:i' : 'd/m/Y') : $valor;
}
