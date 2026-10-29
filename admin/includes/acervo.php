<?php
/**
 * Funções do módulo de acervo: consultas (hoje sobre os dados demonstrativos),
 * rótulos, validações e pequenos componentes visuais reutilizados pelas páginas.
 */
require_once __DIR__ . '/acervo-demo.php';
require_once __DIR__ . '/acervo-mysql.php';

const ACERVO_STATUS = [
    'validado' => ['label' => 'Validado', 'tom' => 'green'],
    'aguardando_conferencia' => ['label' => 'Aguardando conferência', 'tom' => 'amber'],
    'dados_incompletos' => ['label' => 'Dados incompletos', 'tom' => 'blue'],
    'com_divergencia' => ['label' => 'Com divergência', 'tom' => 'red'],
];

const ACERVO_ORIGENS = [
    'manual' => 'Cadastro manual',
    'planilha' => 'Importação de planilha',
    'inventario' => 'Inventário físico',
];

const ACERVO_SITUACOES = [
    'disponivel' => ['label' => 'Disponível', 'tom' => 'green'],
    'emprestado' => ['label' => 'Emprestado', 'tom' => 'indigo'],
    'manutencao' => ['label' => 'Em manutenção', 'tom' => 'amber'],
    'extraviado' => ['label' => 'Extraviado', 'tom' => 'red'],
    'baixado' => ['label' => 'Baixado', 'tom' => 'muted'],
    'aguardando' => ['label' => 'Aguardando identificação', 'tom' => 'teal'],
];

const ACERVO_CONSERVACAO = [
    'bom' => 'Bom',
    'regular' => 'Regular',
    'danificado' => 'Danificado',
    'necessita_reparo' => 'Necessita reparo',
];

const ACERVO_IDIOMAS = ['Português', 'Inglês', 'Espanhol', 'Francês', 'Outro'];

const ACERVO_MOVIMENTOS = [
    'cadastro' => ['label' => 'Cadastro', 'icone' => 'book-plus', 'tom' => 'blue'],
    'emprestimo' => ['label' => 'Empréstimo', 'icone' => 'book-open-check', 'tom' => 'indigo'],
    'devolucao' => ['label' => 'Devolução', 'icone' => 'rotate-ccw', 'tom' => 'green'],
    'manutencao' => ['label' => 'Manutenção', 'icone' => 'wrench', 'tom' => 'amber'],
    'localizacao' => ['label' => 'Localização', 'icone' => 'map-pin', 'tom' => 'teal'],
    'inventario' => ['label' => 'Inventário', 'icone' => 'clipboard-check', 'tom' => 'amber'],
    'extravio' => ['label' => 'Extravio', 'icone' => 'circle-alert', 'tom' => 'red'],
    'baixa' => ['label' => 'Baixa', 'icone' => 'package-x', 'tom' => 'red'],
];

// ---------------------------------------------------------------
// Consultas
// ---------------------------------------------------------------

/**
 * Tabelas do acervo: do MySQL quando o banco estiver configurado; senão, os dados demonstrativos.
 * Nomes aceitos: categorias, autores, localizacoes, livros, exemplares, registros_planilha,
 * movimentacoes, conferencias.
 */
function acervo_fonte(string $tabela): array
{
    if (acervo_usa_mysql()) {
        return acervo_mysql_dados()[$tabela];
    }
    return ('demo_' . $tabela)();
}

function acervo_usa_mysql(): bool
{
    static $mysql = null;
    if ($mysql === null) {
        try {
            $mysql = db_modo() === 'mysql' && db() instanceof PDO;
        } catch (DbIndisponivel $e) {
            $mysql = false;
        }
        if ($mysql && PHP_SAPI !== 'cli') {
            // Dados reais do acervo só para quem entrou no painel.
            require_once __DIR__ . '/auth.php';
            auth_exigir('painel.ver');
        }
    }
    return $mysql;
}

/** Situação da classificação CDD de cada livro (a CDD fica só em classificacoes_cdd, módulo da Etapa 4). */
function acervo_status_cdd(): array
{
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }
    $mapa = [];
    if (acervo_usa_mysql()) {
        $existe = (bool)db_query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'classificacoes_cdd'")->fetchColumn();
        if ($existe) {
            foreach (db_query('SELECT livro_id, status FROM classificacoes_cdd')->fetchAll() as $c) {
                $mapa[(int)$c['livro_id']] = $c['status'];
            }
        }
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        foreach ($_SESSION['cdd_demo']['classificacoes'] ?? [] as $livroId => $c) {
            $mapa[(int)$livroId] = $c['status'] ?? 'pendente';
        }
    }
    return $mapa;
}

/** Livros com autores, categorias e contagem de exemplares já calculados. */
function acervo_livros(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $autores = acervo_fonte('autores');
    $categorias = acervo_fonte('categorias');
    $planilha = [];
    foreach (acervo_fonte('registros_planilha') as $registro) {
        $planilha[$registro['livro_id']] = $registro;
    }
    $contagem = [];
    $conferidos = [];
    foreach (acervo_fonte('exemplares') as $ex) {
        $contagem[$ex['livro_id']][$ex['situacao']] = ($contagem[$ex['livro_id']][$ex['situacao']] ?? 0) + 1;
        if ($ex['conferido_em'] !== null) {
            $conferidos[$ex['livro_id']] = ($conferidos[$ex['livro_id']] ?? 0) + 1;
        }
    }
    $cdd = acervo_status_cdd();

    $cache = [];
    foreach (acervo_fonte('livros') as $id => $livro) {
        $porSituacao = $contagem[$id] ?? [];
        $livro['autores_nomes'] = array_map(fn ($a) => $autores[$a]['nome'] ?? '—', $livro['autores']);
        $livro['autor_principal'] = $livro['autores_nomes'][0] ?? 'Autor não informado';
        $livro['categorias_nomes'] = array_map(fn ($c) => $categorias[$c]['nome'] ?? '—', $livro['categorias']);
        $livro['categoria_principal'] = $livro['categorias_nomes'][0] ?? 'Sem categoria';
        $livro['exemplares_por_situacao'] = $porSituacao;
        $livro['total_exemplares'] = array_sum($porSituacao);
        $livro['disponiveis'] = $porSituacao['disponivel'] ?? 0;
        $livro['planilha'] = $planilha[$id] ?? null;
        // Indicadores separados para os livros vindos das planilhas antigas.
        $livro['exemplares_conferidos'] = $conferidos[$id] ?? 0;
        $livro['status_cdd'] = $cdd[$id] ?? 'pendente';
        $livro['aguarda_reclassificacao'] = $livro['status_cdd'] !== 'aprovada';
        $livro['aguarda_inventario'] = $livro['exemplares_conferidos'] === 0;
        $cache[$id] = $livro;
    }
    return $cache;
}

function acervo_livro(int $id): ?array
{
    return acervo_livros()[$id] ?? null;
}

/** Exemplares com o livro e a localização resolvidos. Filtra por livro quando informado. */
function acervo_exemplares(?int $livroId = null): array
{
    $livros = acervo_livros();
    $locais = acervo_fonte('localizacoes');
    $lista = [];
    foreach (acervo_fonte('exemplares') as $id => $ex) {
        if ($livroId !== null && $ex['livro_id'] !== $livroId) {
            continue;
        }
        $ex['livro'] = $livros[$ex['livro_id']];
        $ex['localizacao'] = $locais[$ex['localizacao_id']] ?? null;
        $lista[$id] = $ex;
    }
    return $lista;
}

function acervo_exemplar_por_codigo(string $codigo): ?array
{
    $codigo = strtoupper(trim($codigo));
    foreach (acervo_exemplares() as $ex) {
        if ($ex['codigo_individual'] === $codigo) {
            return $ex;
        }
    }
    return null;
}

/** Histórico de movimentações, do mais recente para o mais antigo. */
function acervo_movimentacoes(?int $livroId = null, ?int $exemplarId = null): array
{
    $exemplares = acervo_exemplares($livroId);
    $lista = [];
    // No banco, o cadastro de cada exemplar já é uma linha de movimentacoes.
    foreach (acervo_usa_mysql() ? [] : $exemplares as $ex) {
        $lista[] = ['exemplar_id' => $ex['id'], 'tipo' => 'cadastro', 'data' => $ex['criado_em'], 'descricao' => 'Exemplar registrado no acervo', 'responsavel' => 'Ana Souza'];
    }
    foreach (acervo_fonte('movimentacoes') as $mov) {
        if (isset($exemplares[$mov['exemplar_id']])) {
            $lista[] = $mov;
        }
    }
    if ($exemplarId !== null) {
        $lista = array_values(array_filter($lista, fn ($m) => $m['exemplar_id'] === $exemplarId));
    }
    foreach ($lista as &$mov) {
        $mov['codigo'] = $exemplares[$mov['exemplar_id']]['codigo_individual'];
    }
    unset($mov);
    usort($lista, fn ($a, $b) => [$b['data'], $b['tipo'] === 'devolucao'] <=> [$a['data'], $a['tipo'] === 'devolucao']);
    return $lista;
}

/** Empréstimo ativo de um exemplar (último empréstimo sem devolução posterior). */
function acervo_emprestimo_ativo(array $exemplar): ?array
{
    if ($exemplar['situacao'] !== 'emprestado') {
        return null;
    }
    foreach (acervo_movimentacoes(null, $exemplar['id']) as $mov) {
        if ($mov['tipo'] === 'emprestimo') {
            return $mov;
        }
    }
    return null;
}

function acervo_conferencias(int $livroId): array
{
    $lista = array_values(array_filter(acervo_fonte('conferencias'), fn ($c) => $c['livro_id'] === $livroId));
    usort($lista, fn ($a, $b) => strcmp($b['data'], $a['data']));
    return $lista;
}

/** Categorias com a quantidade de títulos associados. */
function acervo_categorias(): array
{
    $contagem = [];
    foreach (acervo_livros() as $livro) {
        foreach ($livro['categorias'] as $cat) {
            $contagem[$cat] = ($contagem[$cat] ?? 0) + 1;
        }
    }
    $lista = [];
    foreach (acervo_fonte('categorias') as $id => $cat) {
        $cat['total_titulos'] = $contagem[$id] ?? 0;
        $lista[$id] = $cat;
    }
    return $lista;
}

/** Indicadores do topo da página de livros. */
function acervo_resumo(): array
{
    $livros = acervo_livros();
    $exemplares = acervo_fonte('exemplares');
    $porSituacao = array_count_values(array_column($exemplares, 'situacao'));
    return [
        'titulos' => count($livros),
        'exemplares' => count($exemplares),
        'disponiveis' => $porSituacao['disponivel'] ?? 0,
        'pendentes' => count(array_filter($livros, fn ($l) => $l['status_validacao'] !== 'validado')),
        'por_situacao' => $porSituacao,
    ];
}

/** Próximo código individual. Na versão real, deve vir de uma sequência do banco. */
function acervo_proximo_codigo(): string
{
    $maior = 0;
    foreach (acervo_fonte('exemplares') as $ex) {
        $maior = max($maior, (int)substr($ex['codigo_individual'], 4));
    }
    return sprintf('BDP-%06d', $maior + 1);
}

/** Dados mínimos usados pelo JavaScript (pesquisa de duplicados, autores, códigos). */
function acervo_dados_js(): array
{
    return [
        'livros' => array_values(array_map(fn ($l) => [
            'id' => $l['id'],
            'titulo' => $l['titulo'],
            'subtitulo' => $l['subtitulo'],
            'autores' => $l['autores_nomes'],
            'isbn' => acervo_isbn_normalizar($l['isbn']),
            'editora' => $l['editora'],
            'edicao' => $l['edicao'],
            'ano' => $l['ano_publicacao'],
            'categoria' => $l['categoria_principal'],
            'exemplares' => $l['total_exemplares'],
            'emprestados' => $l['exemplares_por_situacao']['emprestado'] ?? 0,
            'status' => ACERVO_STATUS[$l['status_validacao']]['label'],
            'cor' => $l['cor'],
        ], acervo_livros())),
        'autores' => array_values(array_column(acervo_fonte('autores'), 'nome')),
        'categorias' => array_values(array_map(fn ($c) => ['id' => $c['id'], 'nome' => $c['nome'], 'titulos' => $c['total_titulos']], acervo_categorias())),
        'localizacoes' => array_values(acervo_fonte('localizacoes')),
        'situacoes' => ACERVO_SITUACOES,
        'conservacao' => ACERVO_CONSERVACAO,
        'codigos' => array_values(array_column(acervo_fonte('exemplares'), 'codigo_individual')),
        'proximoCodigo' => acervo_proximo_codigo(),
        'urls' => [
            'detalhesLivro' => admin_url('detalhes-livro'),
            'detalhesExemplar' => admin_url('detalhes-exemplar'),
            'livros' => admin_url('acervo'),
            'cadastrarLivro' => admin_url('cadastrar-livro'),
        ],
    ];
}

function acervo_json_script(): string
{
    return '<script type="application/json" id="acervo-data">'
        . json_encode(acervo_dados_js(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';
}

// ---------------------------------------------------------------
// Validação (mesmas regras do JavaScript, para uso no servidor)
// ---------------------------------------------------------------

/** Remove espaços, hífens e pontos. Mantém o "X" final do ISBN-10. */
function acervo_isbn_normalizar(?string $isbn): string
{
    return strtoupper(preg_replace('/[^0-9Xx]/', '', (string)$isbn));
}

/** Confere o dígito de controle de ISBN-10 ou ISBN-13. */
function acervo_isbn_valido(string $isbn): bool
{
    $isbn = acervo_isbn_normalizar($isbn);
    if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $digito = $isbn[$i] === 'X' ? 10 : (int)$isbn[$i];
            $soma += $digito * (10 - $i);
        }
        return $soma % 11 === 0;
    }
    if (preg_match('/^\d{13}$/', $isbn)) {
        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $soma += (int)$isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - $soma % 10) % 10 === (int)$isbn[12];
    }
    return false;
}

/** Valida os dados do formulário de livro. Retorna [campo => mensagem]. */
function acervo_validar_livro(array $dados): array
{
    $erros = [];
    $titulo = trim((string)($dados['titulo'] ?? ''));
    if ($titulo === '') {
        $erros['titulo'] = 'Informe o título do livro.';
    } elseif (mb_strlen($titulo) > 255) {
        $erros['titulo'] = 'O título pode ter no máximo 255 caracteres.';
    }
    $isbn = trim((string)($dados['isbn'] ?? ''));
    if ($isbn !== '' && !acervo_isbn_valido($isbn)) {
        $erros['isbn'] = 'ISBN inválido. Confira os números: use 10 ou 13 dígitos.';
    }
    $ano = trim((string)($dados['ano_publicacao'] ?? ''));
    $anoAtual = (int)date('Y');
    if ($ano !== '' && (!ctype_digit($ano) || (int)$ano < 1450 || (int)$ano > $anoAtual + 1)) {
        $erros['ano_publicacao'] = "Informe um ano entre 1450 e " . ($anoAtual + 1) . '.';
    }
    $paginas = trim((string)($dados['numero_paginas'] ?? ''));
    if ($paginas !== '' && (!ctype_digit($paginas) || (int)$paginas < 1 || (int)$paginas > 10000)) {
        $erros['numero_paginas'] = 'Informe um número de páginas válido.';
    }
    if (!isset(acervo_fonte('categorias')[(int)($dados['categoria'] ?? 0)])) {
        $erros['categoria'] = 'Selecione uma categoria.';
    }
    if (!array_key_exists((string)($dados['status_validacao'] ?? ''), ACERVO_STATUS)) {
        $erros['status_validacao'] = 'Selecione a situação do cadastro.';
    }
    return $erros;
}

// ---------------------------------------------------------------
// Formatação e componentes visuais
// ---------------------------------------------------------------

function acervo_data(?string $data): string
{
    if (!$data) {
        return '—';
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $data);
    return $d ? $d->format('d/m/Y') : $data;
}

function acervo_local_texto(?array $local, bool $curto = false): string
{
    if (!$local) {
        return 'Sem localização';
    }
    return $curto
        ? $local['setor'] . ' · ' . str_replace('Estante ', 'Est. ', $local['estante'])
        : $local['setor'] . ' / ' . $local['estante'] . ' / ' . $local['prateleira'];
}

function acervo_badge_status(string $status): string
{
    $s = ACERVO_STATUS[$status] ?? ['label' => $status, 'tom' => 'blue'];
    return '<span class="adm-status adm-tone--' . $s['tom'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

/**
 * Pendências dos livros importados de planilha, em indicadores separados:
 * a reclassificação CDD (Etapa 4) e a conferência física dos exemplares (inventário).
 */
function acervo_badges_importacao(array $livro): string
{
    if (!$livro['planilha']) {
        return '';
    }
    $html = '';
    if ($livro['aguarda_reclassificacao']) {
        $html .= '<span class="adm-status adm-status--soft adm-tone--indigo">Aguardando reclassificação CDD</span>';
    }
    if ($livro['aguarda_inventario']) {
        $html .= '<span class="adm-status adm-status--soft adm-tone--teal">Aguardando inventário físico</span>';
    }
    return $html === '' ? '' : '<span class="adm-badge-stack">' . $html . '</span>';
}

function acervo_badge_situacao(string $situacao): string
{
    $s = ACERVO_SITUACOES[$situacao] ?? ['label' => $situacao, 'tom' => 'blue'];
    return '<span class="adm-status adm-status--soft adm-tone--' . $s['tom'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function acervo_badge_conservacao(string $estado): string
{
    $alerta = in_array($estado, ['danificado', 'necessita_reparo'], true);
    return '<span class="adm-condition' . ($alerta ? ' adm-condition--alert' : '') . '">'
        . htmlspecialchars(ACERVO_CONSERVACAO[$estado] ?? $estado) . '</span>';
}

/**
 * Capa do livro. Sem imagem cadastrada, desenha uma capa padrão com título e autor.
 * $tamanho: sm (tabela) | md | lg (detalhes)
 */
function acervo_capa(array $livro, string $tamanho = 'sm'): string
{
    $classe = 'adm-cover adm-cover--' . $tamanho;
    if (!empty($livro['capa'])) {
        return '<span class="' . $classe . '"><img src="' . htmlspecialchars($livro['capa']) . '" alt="Capa de ' . htmlspecialchars($livro['titulo']) . '" loading="lazy"></span>';
    }
    $autor = $livro['autor_principal'] ?? '';
    return '<span class="' . $classe . ' adm-cover--placeholder" style="--cover: ' . htmlspecialchars($livro['cor'] ?? '#143477') . '" role="img" aria-label="Capa padrão de ' . htmlspecialchars($livro['titulo']) . '">'
        . '<span class="adm-cover__title">' . htmlspecialchars($livro['titulo']) . '</span>'
        . ($tamanho !== 'sm' ? '<span class="adm-cover__author">' . htmlspecialchars($autor) . '</span>' : '')
        . '</span>';
}

/** @param array<int, array{0: string, 1?: string}> $itens [rótulo, id do menu opcional] */
function acervo_breadcrumb(array $itens): string
{
    $html = '<nav class="adm-breadcrumb" aria-label="Você está em"><ol>';
    $ultimo = count($itens) - 1;
    foreach ($itens as $i => $item) {
        [$rotulo, $destino] = $item + [1 => null];
        if ($i === $ultimo || $destino === null) {
            $html .= '<li' . ($i === $ultimo ? ' aria-current="page"' : '') . '>' . htmlspecialchars($rotulo) . '</li>';
        } else {
            $html .= '<li><a href="' . htmlspecialchars($destino) . '">' . htmlspecialchars($rotulo) . '</a></li>';
        }
    }
    return $html . '</ol></nav>';
}

/** Texto em minúsculas e sem acentos, usado na pesquisa da tabela. */
function acervo_texto_busca(string ...$partes): string
{
    return \Biblioteca\Support\Texto::semAcento(mb_strtolower(implode(' ', $partes)));
}
