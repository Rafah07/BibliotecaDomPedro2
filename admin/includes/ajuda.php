<?php
/**
 * Central de Ajuda do painel (Sistema → Configurações → Tutorial do Sistema).
 *
 * O conteúdo fica em arquivos versionados, sem banco de dados:
 *   admin/includes/ajuda/modulos.php          lista e ordem dos módulos (categorias)
 *   admin/includes/ajuda/tutoriais/*.php      um arquivo por módulo, com os tutoriais
 *   admin/assets/ajuda/*.png                  capturas de tela (somente dados fictícios)
 * O formato de cada tutorial está explicado em admin/includes/ajuda/LEIA-ME.md.
 *
 * Regras de segurança:
 *   * A leitura da ajuda não grava nada no banco e não executa nenhuma operação administrativa.
 *   * Todos os perfis autenticados leem todos os tutoriais (são instruções, não dados). Os links
 *     para páginas restritas só aparecem para quem tem a permissão; para os demais o tutorial
 *     avisa que a função não está disponível para o perfil. O servidor continua conferindo
 *     a permissão em cada página, como sempre.
 *   * O endereço de retorno ("voltar") só aceita páginas do próprio painel (ver ajuda_voltar()).
 */
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icones.php';

use Biblioteca\Support\Texto;

const AJUDA_DIR = __DIR__ . '/ajuda';
/** Pasta pública das capturas de tela, relativa a admin/. */
const AJUDA_IMAGENS = 'assets/ajuda/';

/** Módulos (categorias) na ordem de exibição, cada um com seus tutoriais. */
function ajuda_modulos(): array
{
    static $modulos = null;
    if ($modulos !== null) {
        return $modulos;
    }
    $modulos = [];
    foreach (require AJUDA_DIR . '/modulos.php' as $id => $modulo) {
        $arquivo = AJUDA_DIR . '/tutoriais/' . $modulo['arquivo'];
        $tutoriais = is_file($arquivo) ? require $arquivo : [];
        $modulo['id'] = $id;
        $modulo['tutoriais'] = [];
        foreach ($tutoriais as $t) {
            $t['modulo'] = $id;
            $modulo['tutoriais'][$t['id']] = $t;
        }
        $modulos[$id] = $modulo;
    }
    return $modulos;
}

/** Todos os tutoriais, indexados pelo id. */
function ajuda_tutoriais(): array
{
    static $todos = null;
    if ($todos === null) {
        $todos = [];
        foreach (ajuda_modulos() as $m) {
            $todos += $m['tutoriais'];
        }
    }
    return $todos;
}

function ajuda_tutorial(string $id): ?array
{
    return ajuda_tutoriais()[$id] ?? null;
}

/** Texto em minúsculas e sem acentos (a mesma regra na pesquisa e no conteúdo). */
function ajuda_normalizar(string $texto): string
{
    $texto = Texto::semAcento(mb_strtolower($texto));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $texto));
}

/**
 * Palavras da pesquisa, sem acentos e reduzidas ao singular simples
 * ("devoluções" → "devolucao", "etiquetas" → "etiqueta"), para "empréstimos" achar "empréstimo".
 * @return string[]
 */
function ajuda_termos(string $consulta): array
{
    $ignorar = ['a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'um', 'uma', 'no', 'na', 'nos', 'nas', 'para', 'por', 'com', 'como', 'que', 'se', 'eu', 'meu', 'minha', 'ao', 'aos'];
    $termos = [];
    foreach (explode(' ', ajuda_normalizar($consulta)) as $p) {
        if ($p === '' || in_array($p, $ignorar, true)) {
            continue;
        }
        $termos[] = ajuda_singular($p);
    }
    return array_values(array_unique(array_slice($termos, 0, 8)));
}

function ajuda_singular(string $p): string
{
    if (strlen($p) <= 3) {
        return $p;
    }
    foreach (['coes' => 'cao', 'soes' => 'sao', 'oes' => 'ao', 'aes' => 'ao', 'ais' => 'al', 'eis' => 'el', 'res' => 'r', 'zes' => 'z'] as $fim => $troca) {
        if (str_ends_with($p, $fim) && strlen($p) > strlen($fim) + 2) {
            return substr($p, 0, -strlen($fim)) . $troca;
        }
    }
    return str_ends_with($p, 's') ? substr($p, 0, -1) : $p;
}

/** Todo o texto de um tutorial, para a pesquisa no conteúdo. */
function ajuda_texto_completo(array $t): string
{
    $partes = [$t['objetivo'] ?? '', $t['exemplo'] ?? ''];
    foreach (['quando', 'requisitos', 'resultado', 'cuidados'] as $lista) {
        $partes = array_merge($partes, $t[$lista] ?? []);
    }
    foreach ($t['passos'] ?? [] as $p) {
        $partes[] = is_array($p) ? implode(' ', array_filter([$p['titulo'] ?? '', $p['texto'] ?? '', $p['dica'] ?? '', $p['atencao'] ?? ''], 'is_string')) : $p;
        foreach ($p['lista'] ?? [] as $item) {
            $partes[] = $item;
        }
    }
    foreach ($t['problemas'] ?? [] as $pr) {
        $partes = array_merge($partes, $pr);
    }
    // Remove a marcação dos links ([[pagina:x|texto]]), mantendo o texto visível.
    return preg_replace('/\[\[[a-z]+:[a-z0-9.\-]+\|([^\]]*)\]\]/', '$1', implode(' ', $partes));
}

/**
 * Pesquisa local por título, módulo, palavras-chave, resumo e conteúdo, com e sem acentos.
 * Todas as palavras precisam aparecer em algum lugar do tutorial; o título pesa mais.
 * @return array<int, array{tutorial: array, pontos: int}>
 */
function ajuda_pesquisar(string $consulta): array
{
    $termos = ajuda_termos($consulta);
    if (!$termos) {
        return [];
    }
    $modulos = ajuda_modulos();
    $resultados = [];
    foreach (ajuda_tutoriais() as $t) {
        $campos = [
            'titulo' => [' ' . ajuda_normalizar($t['titulo']), 12],
            'palavras' => [' ' . ajuda_normalizar(implode(' ', $t['palavras'] ?? [])), 8],
            'modulo' => [' ' . ajuda_normalizar($modulos[$t['modulo']]['nome'] . ' ' . implode(' ', $modulos[$t['modulo']]['palavras'] ?? [])), 4],
            'resumo' => [' ' . ajuda_normalizar($t['resumo'] ?? ''), 4],
            'conteudo' => [' ' . ajuda_normalizar(ajuda_texto_completo($t)), 1],
        ];
        $pontos = 0;
        foreach ($termos as $termo) {
            $achou = 0;
            foreach ($campos as [$texto, $peso]) {
                // Início de palavra: "bdp" acha "bdp 000001", "emprest" acha "emprestimo".
                if (str_contains($texto, ' ' . $termo)) {
                    $achou += $peso;
                }
            }
            if ($achou === 0) {
                continue 2; // falta uma das palavras: o tutorial não entra
            }
            $pontos += $achou;
        }
        $resultados[] = ['tutorial' => $t, 'pontos' => $pontos];
    }
    usort($resultados, fn ($a, $b) => [$b['pontos'], $a['tutorial']['titulo']] <=> [$a['pontos'], $b['tutorial']['titulo']]);
    return $resultados;
}

// ---------------------------------------------------------------
// Endereços e retorno à página de origem
// ---------------------------------------------------------------

/**
 * Páginas do painel que podem ser destino do "voltar": os arquivos do ADMIN_MENU.
 * @return array<string, string> arquivo => rótulo
 */
function ajuda_paginas_painel(): array
{
    static $paginas = null;
    if ($paginas === null) {
        $paginas = [];
        foreach (ADMIN_MENU as $itens) {
            foreach ($itens as $item) {
                if (!empty($item['arquivo'])) {
                    $arquivo = explode('?', $item['arquivo'], 2)[0];
                    $paginas[$arquivo] ??= $item['label'];
                }
            }
        }
        unset($paginas['ajuda.php']);
    }
    return $paginas;
}

/**
 * Valida o endereço de retorno. Só aceita "arquivo.php" ou "arquivo.php?consulta" de uma página
 * conhecida do painel: nada de esquema, domínio, barras ou caminhos. Devolve [arquivo+consulta, rótulo] ou null.
 */
function ajuda_voltar(?string $voltar): ?array
{
    if (!is_string($voltar) || $voltar === '' || strlen($voltar) > 300) {
        return null;
    }
    if (!preg_match('/^([a-z0-9-]+\.php)(\?[A-Za-z0-9_\-.=&%+,]*)?$/', $voltar, $m)) {
        return null;
    }
    $paginas = ajuda_paginas_painel();
    if (!isset($paginas[$m[1]])) {
        return null;
    }
    return [$voltar, $paginas[$m[1]]];
}

/** Endereço da ajuda com os parâmetros indicados, mantendo o "voltar" válido da visita atual. */
function ajuda_url(array $params = [], ?string $ancora = null): string
{
    $voltar = ajuda_voltar($_GET['voltar'] ?? null);
    if ($voltar && !array_key_exists('voltar', $params)) {
        $params['voltar'] = $voltar[0];
    }
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
    return admin_url('ajuda') . ($params ? '?' . http_build_query($params) : '') . ($ancora ? '#' . $ancora : '');
}

/** Página atual (arquivo e consulta) para usar como "voltar" a partir de um botão de ajuda. */
function ajuda_pagina_atual(): string
{
    $arquivo = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $consulta = (string)($_SERVER['QUERY_STRING'] ?? '');
    $voltar = $arquivo . ($consulta !== '' ? '?' . $consulta : '');
    return ajuda_voltar($voltar) ? $voltar : (ajuda_voltar($arquivo) ? $arquivo : '');
}

/** Tutorial principal de uma página do painel (campo 'paginas' dos tutoriais). */
function ajuda_tutorial_da_pagina(string $arquivo): ?array
{
    foreach (ajuda_tutoriais() as $t) {
        if (in_array($arquivo, $t['paginas'] ?? [], true)) {
            return $t;
        }
    }
    return null;
}

/**
 * Botão discreto "Como usar esta página?", que abre o tutorial correspondente à página atual.
 * Vazio quando a página não tem tutorial ou quando já estamos na Central de Ajuda.
 */
function ajuda_link_contextual(string $classe = ''): string
{
    if (PHP_SAPI === 'cli' && !isset($_SERVER['SCRIPT_NAME'])) {
        return '';
    }
    $arquivo = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($arquivo === 'ajuda.php' || !auth_usuario()) {
        return '';
    }
    try {
        $t = ajuda_tutorial_da_pagina($arquivo);
    } catch (Throwable $e) {
        error_log('[biblioteca] Ajuda contextual indisponível: ' . get_class($e));
        return '';
    }
    if (!$t) {
        return '';
    }
    $url = ajuda_url(['t' => $t['id'], 'voltar' => ajuda_pagina_atual()]);
    return '<a class="adm-help-link' . ($classe !== '' ? ' ' . e($classe) : '') . '" href="' . e($url) . '" title="' . e($t['titulo']) . '">'
        . icon('circle-help') . '<span>Como usar esta página?</span></a>';
}

// ---------------------------------------------------------------
// Formatação do conteúdo
// ---------------------------------------------------------------

/**
 * Converte a marcação simples do conteúdo em HTML seguro. O texto é escapado antes:
 *   **negrito**   `código ou valor digitado`   [[pagina:id-do-menu|texto]]   [[tutorial:id|texto]]
 * Links para páginas usam só ids do ADMIN_MENU; sem a permissão do item, vira texto simples.
 */
function ajuda_formatar(string $texto): string
{
    $html = e($texto);
    $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
    $html = preg_replace('/`(.+?)`/u', '<code>$1</code>', $html);
    return preg_replace_callback('/\[\[(pagina|tutorial):([a-z0-9\-]+)\|([^\]]+)\]\]/u', function (array $m): string {
        [, $tipo, $id, $rotulo] = $m;
        if ($tipo === 'tutorial') {
            $t = ajuda_tutorial($id);
            return $t ? '<a class="adm-link" href="' . e(ajuda_url(['t' => $id])) . '">' . $rotulo . '</a>' : $rotulo;
        }
        $item = admin_menu_item($id);
        if (!$item || empty($item['pronta']) || isset($item['acao']) || isset($item['url'])) {
            return '<strong>' . $rotulo . '</strong>';
        }
        $permissao = $item['permissao'] ?? (AJUDA_PERMISSAO_PAGINAS[$id] ?? 'painel.ver');
        if (!auth_pode($permissao)) {
            return '<strong>' . $rotulo . '</strong>';
        }
        return '<a class="adm-link" href="' . e(admin_url($id)) . '">' . $rotulo . '</a>';
    }, $html);
}

/**
 * Permissão exigida pelas páginas que não declaram 'permissao' no ADMIN_MENU
 * (a página confere no servidor; aqui serve só para não mostrar link a quem não pode abrir).
 */
const AJUDA_PERMISSAO_PAGINAS = [
    'cadastrar-livro' => 'livros.editar',
    'cadastrar-exemplar' => 'exemplares.gerenciar',
    'novo-emprestimo' => 'emprestimos.registrar',
    'importar' => 'importacao.ver',
    'historico-importacoes' => 'importacao.ver',
    'etiquetas' => 'etiquetas.gerar',
    'etiqueta-modelos' => 'etiquetas.configurar',
    'classificacao' => 'cdd.ver',
    'revisao-cdd' => 'cdd.ver',
    'historico-cdd' => 'cdd.ver',
    'relatorio-cdd' => 'cdd.exportar',
    'editar-livro' => 'livros.editar',
    'arquivar-livro' => 'livros.arquivar',
    'inventario-editar' => 'inventario.gerenciar',
    'inventario-conferencia' => 'inventario.conferir',
    'localizacao' => 'localizacoes.gerenciar',
    'devolver-emprestimo' => 'emprestimos.registrar',
    'renovar-emprestimo' => 'emprestimos.registrar',
    'capa-livro' => 'livros.editar',
];

/** Nome legível de uma permissão (usa as descrições da tela Perfis e permissões). */
function ajuda_permissao_nome(string $permissao): string
{
    foreach (AUTH_PERMISSOES_DESCRICOES as $grupo) {
        if (isset($grupo[$permissao])) {
            return $grupo[$permissao];
        }
    }
    return $permissao;
}

/** Perfis que têm a permissão (rótulos). */
function ajuda_perfis_com(string $permissao): array
{
    $perfis = [];
    foreach (AUTH_PERMISSOES as $perfil => $lista) {
        if (in_array($permissao, $lista, true)) {
            $perfis[] = AUTH_PERFIS[$perfil];
        }
    }
    return $perfis;
}

/** Quantos tutoriais existem ao todo. */
function ajuda_total(): int
{
    return count(ajuda_tutoriais());
}
