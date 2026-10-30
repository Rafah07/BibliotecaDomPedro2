<?php
/**
 * Gerenciamento dos livros pelo painel: listagem paginada no servidor, validação,
 * cadastro, edição e arquivamento.
 *
 * Com MySQL, tudo é gravado de verdade pelo LivroRepository (src/Repositories).
 * No modo demonstrativo (sem banco), a listagem filtra os livros fictícios e as
 * gravações continuam apenas simuladas.
 */
require_once __DIR__ . '/acervo.php';
require_once __DIR__ . '/cdd.php';
require_once __DIR__ . '/componentes.php';

use Biblioteca\Repositories\LivroRepository;
use Biblioteca\Support\Isbn;

const LIVROS_POR_PAGINA = [25, 50, 100];

const LIVROS_ORDENS = [
    'titulo' => 'Título',
    'autor' => 'Autor',
    'cadastro' => 'Data de cadastro',
];

function livros_repo(): LivroRepository
{
    static $r = null;
    return $r ??= new LivroRepository(db());
}

/** Os livros reais só são gravados com o banco configurado. */
function livros_persistem(): bool
{
    return acervo_usa_mysql();
}

/** Categorias ativas [id => nome], sem carregar o acervo inteiro. */
function livros_categorias_ativas(): array
{
    static $lista = null;
    if ($lista !== null) {
        return $lista;
    }
    $lista = [];
    if (livros_persistem()) {
        foreach (db_query('SELECT id, nome FROM categorias WHERE arquivado_em IS NULL ORDER BY nome')->fetchAll() as $c) {
            $lista[(int)$c['id']] = $c['nome'];
        }
    } else {
        foreach (acervo_fonte('categorias') as $c) {
            $lista[$c['id']] = $c['nome'];
        }
    }
    return $lista;
}

/** Filtros da listagem lidos de $_GET (somente valores conhecidos). */
function livros_filtros(array $get): array
{
    $ordem = admin_param($get, 'ordem', 'titulo');
    $dir = admin_param($get, 'dir');
    // O seletor "Ordenar por" envia "campo:direção"; os títulos da tabela enviam ordem e dir.
    if (preg_match('/^(\w+):(asc|desc)$/', admin_param($get, 'ordenacao'), $m)) {
        [, $ordem, $dir] = $m;
    }
    $porPagina = (int)admin_param($get, 'por_pagina', '25');
    $categoria = admin_param($get, 'categoria');
    return [
        'q' => mb_substr(admin_param($get, 'q'), 0, 200),
        'categoria' => $categoria === 'sem' ? 'sem' : ((int)$categoria > 0 ? (string)(int)$categoria : ''),
        'cdd' => in_array(admin_param($get, 'cdd'), ['aguardando', ...LivroRepository::STATUS_CDD], true) ? admin_param($get, 'cdd') : '',
        'situacao' => array_key_exists(admin_param($get, 'situacao'), ACERVO_STATUS) ? admin_param($get, 'situacao') : '',
        'origem' => array_key_exists(admin_param($get, 'origem'), ACERVO_ORIGENS) ? admin_param($get, 'origem') : '',
        'exemplares' => in_array(admin_param($get, 'exemplares'), ['com', 'sem'], true) ? admin_param($get, 'exemplares') : '',
        'arquivados' => admin_param($get, 'arquivados') === '1' ? '1' : '',
        'ordem' => array_key_exists($ordem, LIVROS_ORDENS) ? $ordem : 'titulo',
        'dir' => $dir === 'desc' ? 'desc' : 'asc',
        'por_pagina' => in_array($porPagina, LIVROS_POR_PAGINA, true) ? (string)$porPagina : '25',
    ];
}

/** Página de livros: MySQL (consulta paginada) ou modo demonstrativo (filtra em memória). */
function livros_listar(array $f, int $pagina): array
{
    $porPagina = (int)$f['por_pagina'];
    if (livros_persistem()) {
        return livros_repo()->listar($f, $pagina, $porPagina);
    }
    return livros_listar_demo($f, $pagina, $porPagina);
}

/** Mesmos filtros da consulta SQL, aplicados aos livros fictícios. */
function livros_listar_demo(array $f, int $pagina, int $porPagina): array
{
    if (!empty($f['arquivados'])) {
        return ['itens' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
    }
    $termos = array_filter(preg_split('/\s+/u', acervo_texto_busca($f['q'])) ?: []);
    $lista = array_filter(acervo_livros(), function ($l) use ($f, $termos) {
        $texto = acervo_texto_busca($l['titulo'], $l['subtitulo'], implode(' ', $l['autores_nomes']), $l['editora'], acervo_isbn_normalizar($l['isbn']));
        foreach ($termos as $t) {
            if (!str_contains($texto, $t)) {
                return false;
            }
        }
        $cdd = $l['status_cdd'];
        return ($f['categoria'] === '' || ($f['categoria'] === 'sem' ? !$l['categorias'] : in_array((int)$f['categoria'], $l['categorias'], true)))
            && ($f['cdd'] === '' || ($f['cdd'] === 'aguardando' ? $cdd !== 'aprovada' : $cdd === $f['cdd']))
            && ($f['situacao'] === '' || $l['status_validacao'] === $f['situacao'])
            && ($f['origem'] === '' || $l['origem_cadastro'] === $f['origem'])
            && (($f['exemplares'] ?? '') === '' || (($f['exemplares'] === 'com') === ($l['total_exemplares'] > 0)));
    });
    $chave = match ($f['ordem']) {
        'autor' => fn ($l) => acervo_texto_busca($l['autores_nomes'][0] ?? '~'),
        'cadastro' => fn ($l) => $l['criado_em'] . sprintf('%06d', $l['id']),
        default => fn ($l) => acervo_texto_busca($l['titulo']),
    };
    uasort($lista, fn ($a, $b) => $f['dir'] === 'desc' ? $chave($b) <=> $chave($a) : $chave($a) <=> $chave($b));
    $total = count($lista);
    $paginas = max(1, (int)ceil($total / $porPagina));
    $pagina = max(1, min($pagina, $paginas));
    $itens = array_map(fn ($l) => [
        'id' => $l['id'], 'titulo' => $l['titulo'], 'subtitulo' => $l['subtitulo'], 'isbn' => $l['isbn'], 'editora' => $l['editora'],
        'ano_publicacao' => $l['ano_publicacao'], 'edicao' => $l['edicao'], 'assunto' => $l['assunto'] ?? '', 'status_validacao' => $l['status_validacao'],
        'origem_cadastro' => $l['origem_cadastro'], 'criado_em' => $l['criado_em'], 'arquivado_em' => null,
        'autores' => implode('; ', $l['autores_nomes']), 'categorias' => implode(', ', $l['categorias_nomes']),
        'exemplares' => $l['total_exemplares'], 'disponiveis' => $l['disponiveis'],
        'cdd_antiga' => $l['planilha']['cdd_original'] ?? null, 'cdd_status' => $l['status_cdd'], 'cdd_aprovada' => null, 'cdd_proposta' => null,
    ], array_slice(array_values($lista), ($pagina - 1) * $porPagina, $porPagina));
    return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
}

/**
 * Lê e valida o formulário de livro.
 * Retorna [dados limpos, erros por campo]. Nada é inventado: campos vazios continuam vazios.
 */
function livros_validar(array $post, bool $cadastro): array
{
    $texto = fn (string $campo, int $max) => mb_substr(admin_param($post, $campo), 0, $max);
    $autores = [];
    foreach ((array)($post['autores'] ?? []) as $nome) {
        if (is_string($nome) && trim($nome) !== '') {
            $autores[] = trim(preg_replace('/\s+/u', ' ', $nome));
        }
    }
    $dados = [
        'titulo' => trim(preg_replace('/\s+/u', ' ', admin_param($post, 'titulo'))),
        'subtitulo' => $texto('subtitulo', 255),
        'autores' => $autores,
        'isbn' => admin_param($post, 'isbn'),
        'editora' => $texto('editora', 150),
        'ano_publicacao' => admin_param($post, 'ano_publicacao'),
        'edicao' => $texto('edicao', 60),
        'idioma' => $texto('idioma', 30) ?: 'Português',
        'assunto' => $texto('assunto', 255),
        'categoria' => (int)admin_param($post, 'categoria') ?: null,
        'numero_paginas' => admin_param($post, 'numero_paginas'),
        'palavras_chave' => $texto('palavras_chave', 255),
        'sinopse' => $texto('sinopse', 3000),
        'observacoes_internas' => $texto('observacoes_internas', 1000),
        'status_validacao' => admin_param($post, 'status_validacao', 'aguardando_conferencia'),
        'cdd' => $cadastro ? admin_param($post, 'cdd') : '',
        'cdd_fonte' => $cadastro ? $texto('cdd_fonte', 255) : '',
    ];

    $erros = [];
    if ($dados['titulo'] === '') {
        $erros['titulo'] = 'Informe o título do livro.';
    } elseif (mb_strlen($dados['titulo']) > 255) {
        $erros['titulo'] = 'O título pode ter no máximo 255 caracteres.';
    }
    if (count($autores) > 10) {
        $erros['autores'] = 'Informe no máximo 10 autores.';
    }
    foreach ($autores as $nome) {
        if (mb_strlen($nome) > 150) {
            $erros['autores'] = 'Cada nome de autor pode ter no máximo 150 caracteres.';
        }
    }
    if ($dados['isbn'] !== '') {
        $isbn = Isbn::normalizar($dados['isbn']);
        if (!preg_match('/^[\d\-\s.Xx]+$/', $dados['isbn']) || !Isbn::valido($isbn)) {
            $len = strlen(preg_replace('/\D/', '', $dados['isbn']));
            $erros['isbn'] = $len === 10 || $len === 13 || strlen($isbn) === 10
                ? 'O dígito de controle não confere. Verifique se o ISBN foi digitado corretamente.'
                : 'ISBN inválido: use 10 ou 13 dígitos (com ou sem hífens).';
        } else {
            $dados['isbn'] = $isbn;
        }
    } else {
        $dados['isbn'] = null;
    }
    $anoMax = (int)date('Y') + 1;
    if ($dados['ano_publicacao'] !== '' && (!ctype_digit($dados['ano_publicacao']) || (int)$dados['ano_publicacao'] < 1450 || (int)$dados['ano_publicacao'] > $anoMax)) {
        $erros['ano_publicacao'] = "Informe um ano entre 1450 e $anoMax.";
    }
    if ($dados['numero_paginas'] !== '' && (!ctype_digit($dados['numero_paginas']) || (int)$dados['numero_paginas'] < 1 || (int)$dados['numero_paginas'] > 10000)) {
        $erros['numero_paginas'] = 'Informe um número de páginas entre 1 e 10.000.';
    }
    if ($dados['categoria'] !== null && !isset(livros_categorias_ativas()[$dados['categoria']])) {
        $erros['categoria'] = 'Categoria inválida. Escolha uma da lista.';
    }
    if (!array_key_exists($dados['status_validacao'], ACERVO_STATUS)) {
        $erros['status_validacao'] = 'Selecione a situação do cadastro.';
    }
    if ($dados['cdd'] !== '') {
        $v = cdd_validar($dados['cdd']);
        if (!$v['valida']) {
            $erros['cdd'] = $v['erro'];
        } else {
            $dados['cdd'] = $v['numero'];
        }
    }
    return [$dados, $erros];
}

/**
 * Cadastra o livro e, se a CDD foi informada, registra-a como sugestão
 * (nunca como aprovada: a aprovação continua na fila da Classificação CDD).
 * @return array{id: int, cdd: ?string} cdd = aviso quando a CDD não pôde ser registrada
 */
function livros_cadastrar(array $dados, array $usuario): array
{
    $id = livros_repo()->criar($dados);
    registrar_acao('livro.cadastrar', 'livro', $id, ['titulo' => $dados['titulo'], 'isbn' => $dados['isbn']]);
    $avisoCdd = null;
    if ($dados['cdd'] !== '') {
        if (!auth_pode('cdd.sugerir')) {
            $avisoCdd = 'Seu perfil não pode registrar classificações: a CDD informada não foi gravada.';
        } else {
            try {
                cdd_itens(true);
                cdd_executar('sugerir', $id, [
                    'cdd' => $dados['cdd'],
                    'fonte_nome' => $dados['cdd_fonte'] !== '' ? $dados['cdd_fonte'] : 'Informada no cadastro manual do livro',
                    'justificativa' => 'CDD informada por ' . $usuario['nome'] . ' ao cadastrar o livro.',
                ], $usuario);
            } catch (Throwable $e) {
                error_log('[biblioteca] CDD do cadastro não gravada: ' . $e->getMessage());
                $avisoCdd = 'O livro foi salvo, mas a CDD não pôde ser registrada' . ($e instanceof CddErro ? ': ' . $e->getMessage() : '.') . ' Registre-a pela Classificação CDD.';
            }
        }
    }
    return ['id' => $id, 'cdd' => $avisoCdd];
}

/** Nomes legíveis dos campos, para o registro das alterações. */
const LIVROS_CAMPOS = [
    'titulo' => 'Título', 'subtitulo' => 'Subtítulo', 'isbn' => 'ISBN', 'editora' => 'Editora', 'ano_publicacao' => 'Ano',
    'edicao' => 'Edição', 'idioma' => 'Idioma', 'numero_paginas' => 'Páginas', 'sinopse' => 'Sinopse', 'palavras_chave' => 'Palavras-chave',
    'assunto' => 'Assunto', 'observacoes_internas' => 'Observações internas', 'status_validacao' => 'Situação do cadastro',
    'autores' => 'Autores', 'categoria' => 'Categoria',
];

/** Situação da CDD na listagem: número aprovado, proposta ou CDD antiga da planilha. */
function livros_cdd_html(array $l): string
{
    if (!empty($l['cdd_aprovada'])) {
        return cdd_numero($l['cdd_aprovada']);
    }
    $html = '';
    if (!empty($l['cdd_proposta'])) {
        $html .= cdd_numero($l['cdd_proposta']) . ' <small class="adm-table__muted">proposta</small>';
    } elseif (!empty($l['cdd_antiga'])) {
        $html .= cdd_numero($l['cdd_antiga']) . ' <small class="adm-table__muted">antiga</small>';
    } else {
        $html .= '<span class="adm-missing">Sem CDD</span>';
    }
    return $html;
}

/** Há algum filtro aplicado além da ordenação? */
function livros_tem_filtros(array $f): bool
{
    return $f['q'] !== '' || $f['categoria'] !== '' || $f['cdd'] !== '' || $f['situacao'] !== '' || $f['origem'] !== '';
}

/** Números do topo da listagem (consultas diretas, sem carregar o acervo). */
function livros_resumo(): array
{
    if (!livros_persistem()) {
        $r = acervo_resumo();
        $cdd = count(array_filter(acervo_livros(), fn ($l) => $l['status_cdd'] !== 'aprovada'));
        return ['titulos' => $r['titulos'], 'exemplares' => $r['exemplares'], 'disponiveis' => $r['disponiveis'], 'aguardando_cdd' => $cdd];
    }
    $l = db_query("SELECT COUNT(*) AS titulos,
                          COALESCE(SUM(cc.status IS NULL OR cc.status <> 'aprovada'), 0) AS aguardando_cdd
                   FROM livros l LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id WHERE l.arquivado_em IS NULL")->fetch();
    $e = db_query("SELECT COUNT(*) AS exemplares, COALESCE(SUM(e.situacao = 'disponivel'), 0) AS disponiveis
                   FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                   WHERE e.situacao NOT IN ('baixado', 'extraviado')")->fetch();
    return array_map('intval', $l + $e);
}
