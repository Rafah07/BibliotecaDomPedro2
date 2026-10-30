<?php
/**
 * Módulo de reclassificação CDD: regras, consultas e gravação.
 *
 * Funciona em dois modos (ver db.php):
 *   mysql — dados reais, gravados com transações e histórico imutável;
 *   demo  — livros fictícios do acervo; as alterações ficam só na sessão do navegador.
 *
 * Regras centrais:
 *   * Nenhuma CDD é inventada: só entram números informados por uma fonte ou por uma pessoa.
 *   * Nada é aprovado automaticamente. Aprovar, corrigir e rejeitar exigem a permissão cdd.aprovar.
 *   * A CDD original (planilha) nunca é alterada; a aprovada fica em campo próprio.
 *   * Toda alteração gera uma linha no histórico, que não pode ser editada nem apagada.
 */
require_once __DIR__ . '/acervo.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cdd-fontes.php';

const CDD_STATUS = [
    'pendente' => ['label' => 'Pendente', 'tom' => 'muted', 'descricao' => 'Ainda não foi analisado.'],
    'sugerida' => ['label' => 'Sugestão encontrada', 'tom' => 'blue', 'descricao' => 'Existe uma classificação candidata.'],
    'em_revisao' => ['label' => 'Em revisão', 'tom' => 'amber', 'descricao' => 'A classificação precisa ser examinada.'],
    'aprovada' => ['label' => 'Aprovado', 'tom' => 'green', 'descricao' => 'A bibliotecária validou a classificação.'],
    'conflito' => ['label' => 'Conflito', 'tom' => 'red', 'descricao' => 'Existem sugestões diferentes ou dados incompatíveis.'],
];

const CDD_HISTORICO_TIPOS = [
    'importacao' => ['label' => 'Importação da planilha', 'icone' => 'file-spreadsheet', 'tom' => 'teal'],
    'pesquisa' => ['label' => 'Pesquisa em fontes', 'icone' => 'search', 'tom' => 'blue'],
    'sugestao' => ['label' => 'Sugestão registrada', 'icone' => 'book-marked', 'tom' => 'blue'],
    'rascunho' => ['label' => 'Rascunho salvo', 'icone' => 'save', 'tom' => 'muted'],
    'envio_revisao' => ['label' => 'Enviado para revisão', 'icone' => 'send', 'tom' => 'amber'],
    'aprovacao' => ['label' => 'CDD aprovada', 'icone' => 'check-check', 'tom' => 'green'],
    'correcao' => ['label' => 'CDD corrigida e aprovada', 'icone' => 'pencil', 'tom' => 'green'],
    'rejeicao' => ['label' => 'Sugestão rejeitada', 'icone' => 'circle-x', 'tom' => 'red'],
    'nova_pesquisa' => ['label' => 'Nova pesquisa solicitada', 'icone' => 'refresh-cw', 'tom' => 'amber'],
    'observacao' => ['label' => 'Observação', 'icone' => 'message-square-text', 'tom' => 'indigo'],
    'conflito' => ['label' => 'Conflito identificado', 'icone' => 'git-compare-arrows', 'tom' => 'red'],
];

const CDD_COMPATIBILIDADE = [
    'exata' => ['label' => 'Mesma edição', 'tom' => 'green'],
    'provavel' => ['label' => 'Provável', 'tom' => 'blue'],
    'incerta' => ['label' => 'Incerta', 'tom' => 'amber'],
    'incompativel' => ['label' => 'Não corresponde', 'tom' => 'red'],
];

/** Classes principais da CDD (sumário de primeiro nível), usadas nos relatórios. */
const CDD_CLASSES = [
    '0' => 'Ciência da computação, informação e obras gerais',
    '1' => 'Filosofia e psicologia',
    '2' => 'Religião',
    '3' => 'Ciências sociais',
    '4' => 'Linguagem',
    '5' => 'Ciências naturais e matemática',
    '6' => 'Tecnologia (ciências aplicadas)',
    '7' => 'Artes e recreação',
    '8' => 'Literatura',
    '9' => 'História e geografia',
];

const CDD_SEM_RESULTADO = 'Nenhuma classificação confiável encontrada. Encaminhe o livro para análise manual.';

/** Erro de validação ou de regra, com mensagem segura para mostrar ao usuário. */
final class CddErro extends RuntimeException
{
    public function __construct(string $mensagem, public readonly array $campos = [], public readonly int $status = 422)
    {
        parent::__construct($mensagem);
    }
}

// ---------------------------------------------------------------
// Validação da CDD
// ---------------------------------------------------------------

/**
 * Valida o formato de uma CDD digitada.
 * Retorna ['valida', 'numero', 'erro', 'avisos', 'referencia' => descrição|null].
 * Formato correto não significa classe adequada: isso só a revisão decide.
 */
function cdd_validar(string $entrada): array
{
    $bruto = trim($entrada);
    $r = ['valida' => false, 'numero' => '', 'erro' => '', 'avisos' => [], 'referencia' => null];
    if ($bruto === '') {
        $r['erro'] = 'Informe a CDD.';
        return $r;
    }
    if (str_contains($bruto, ',')) {
        $r['erro'] = 'Use ponto para separar os decimais (ex.: 869.3), não vírgula.';
        return $r;
    }
    $numero = str_replace(['/', "'", '’', ' '], '', $bruto);
    if ($numero !== $bruto) {
        $r['avisos'][] = 'As marcas de segmentação (/ ou \') foram removidas.';
    }
    if (!preg_match('/^\d{3}(\.\d+)?$/', $numero)) {
        $r['erro'] = 'Formato inválido. Use três dígitos e, se houver, decimais depois do ponto (ex.: 869.3 ou 028.5).';
        return $r;
    }
    if (strlen($numero) > 50) {
        $r['erro'] = 'A CDD pode ter no máximo 50 caracteres.';
        return $r;
    }
    if (preg_match('/\.\d*0$/', $numero)) {
        $r['avisos'][] = 'Números da CDD não terminam em zero depois do ponto. Confira o número.';
    }
    $r['valida'] = true;
    $r['numero'] = $numero;

    $ref = cdd_referencia($numero);
    if ($ref === null) {
        $r['avisos'][] = 'Não há tabela de referência licenciada no sistema: apenas o formato foi verificado.';
    } elseif ($ref['exato']) {
        $r['referencia'] = $ref['descricao'];
    } else {
        $r['avisos'][] = $ref['descricao'] !== null
            ? 'Número não encontrado na tabela de referência. Classe mais próxima: ' . $ref['numero'] . ' — ' . $ref['descricao'] . '.'
            : 'Número não encontrado na tabela de referência.';
    }
    return $r;
}

/** Procura o número na tabela de referência licenciada. null = tabela vazia ou indisponível. */
function cdd_referencia(string $numero): ?array
{
    if (db_modo() !== 'mysql') {
        return null;
    }
    static $temDados = null;
    try {
        $temDados ??= (bool)db_query('SELECT 1 FROM cdd_tabela_referencia LIMIT 1')->fetchColumn();
        if (!$temDados) {
            return null;
        }
        $exato = db_query('SELECT numero, descricao FROM cdd_tabela_referencia WHERE numero = ?', [$numero])->fetch();
        if ($exato) {
            return ['exato' => true] + $exato;
        }
        // Classe mais longa que é prefixo do número (ex.: 869.3 para 869.34).
        $prox = db_query('SELECT numero, descricao FROM cdd_tabela_referencia WHERE ? LIKE CONCAT(numero, "%") ORDER BY CHAR_LENGTH(numero) DESC LIMIT 1', [$numero])->fetch();
        return ['exato' => false, 'numero' => $prox['numero'] ?? null, 'descricao' => $prox['descricao'] ?? null];
    } catch (Throwable $e) {
        return null;
    }
}

function cdd_formato_valido(?string $cdd): bool
{
    return $cdd !== null && preg_match('/^\d{3}(\.\d+)?$/', $cdd) === 1;
}

/** "8" para 869.3; null se não for CDD. */
function cdd_classe_principal(?string $cdd): ?string
{
    return cdd_formato_valido($cdd) ? $cdd[0] : null;
}

// ---------------------------------------------------------------
// Leitura
// ---------------------------------------------------------------

/** Linha de classificação vazia (livro ainda não analisado). */
function cdd_linha_padrao(int $livroId, ?string $cddOriginal = null): array
{
    return [
        'id' => null, 'livro_id' => $livroId, 'cdd_original' => $cddOriginal, 'cdd_proposta' => null, 'cdd_aprovada' => null,
        'ddc_edicao' => null, 'descricao' => null, 'assunto_principal' => null, 'fonte_nome' => null, 'fonte_url' => null,
        'data_consulta' => null, 'justificativa' => null, 'observacoes' => null, 'origem' => null, 'rascunho' => 0,
        'precisa_revisao' => 0, 'status' => 'pendente', 'consulta_id' => null, 'sugerido_por' => null, 'sugerido_em' => null,
        'aprovado_por' => null, 'aprovado_em' => null, 'aprovada_fonte' => null, 'versao' => 0, 'criado_em' => null, 'atualizado_em' => null,
    ];
}

/**
 * Todos os livros ativos com a classificação, os dados antigos e a última pesquisa.
 * Cada item: livro (id, titulo, subtitulo, autor, autores, isbn, editora, ano, edicao, assunto, sinopse,
 * categoria, categoria_id, capa, cor), 'class' (classificacoes_cdd), 'legado' (registros_legados|null),
 * 'pesquisa' (resultado da última pesquisa|null).
 */
function cdd_itens(bool $recarregar = false): array
{
    static $cache = null;
    if ($recarregar) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    return $cache = db_modo() === 'mysql' ? cdd_itens_mysql() : cdd_itens_demo();
}

function cdd_item(int $livroId): ?array
{
    return cdd_itens()[$livroId] ?? null;
}

function cdd_itens_mysql(): array
{
    $sql = "SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao, l.assunto, l.sinopse, l.palavras_chave, l.capa,
                (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                (SELECT c.id FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id WHERE lc.livro_id = l.id ORDER BY lc.principal DESC, c.id LIMIT 1) AS categoria_id,
                (SELECT c.nome FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id WHERE lc.livro_id = l.id ORDER BY lc.principal DESC, c.id LIMIT 1) AS categoria,
                cc.id AS c_id, cc.cdd_original AS c_cdd_original, cc.cdd_proposta AS c_cdd_proposta, cc.cdd_aprovada AS c_cdd_aprovada,
                cc.ddc_edicao AS c_ddc_edicao, cc.descricao AS c_descricao, cc.assunto_principal AS c_assunto_principal, cc.fonte_nome AS c_fonte_nome,
                cc.fonte_url AS c_fonte_url, cc.data_consulta AS c_data_consulta, cc.justificativa AS c_justificativa, cc.observacoes AS c_observacoes,
                cc.origem AS c_origem, cc.rascunho AS c_rascunho, cc.precisa_revisao AS c_precisa_revisao, cc.status AS c_status,
                cc.consulta_id AS c_consulta_id, cc.sugerido_por AS c_sugerido_por, cc.sugerido_em AS c_sugerido_em, cc.aprovado_por AS c_aprovado_por,
                cc.aprovado_em AS c_aprovado_em, cc.aprovada_fonte AS c_aprovada_fonte, cc.versao AS c_versao, cc.criado_em AS c_criado_em, cc.atualizado_em AS c_atualizado_em,
                p.resultado AS p_resultado, p.consultado_em AS p_consultado_em, p.total_registros AS p_total
            FROM livros l
            LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id
            LEFT JOIN cdd_pesquisas p ON p.id = (SELECT MAX(p2.id) FROM cdd_pesquisas p2 WHERE p2.livro_id = l.id)
            WHERE l.arquivado_em IS NULL
            ORDER BY l.titulo, l.id";
    $legados = cdd_legados_mysql();
    $itens = [];
    foreach (db_query($sql)->fetchAll() as $linha) {
        $id = (int)$linha['id'];
        $legado = $legados[$id] ?? null;
        $class = cdd_linha_padrao($id, $legado['cdd_original'] ?? null);
        if ($linha['c_id'] !== null) {
            foreach ($class as $campo => $_) {
                $class[$campo] = $linha['c_' . $campo] ?? null;
            }
            $class['livro_id'] = $id;
            $class['versao'] = (int)$class['versao'];
            $class['cdd_original'] ??= $legado['cdd_original'] ?? null;
        }
        $autores = (string)($linha['autores'] ?? '');
        $itens[$id] = [
            'id' => $id,
            'titulo' => $linha['titulo'],
            'subtitulo' => (string)$linha['subtitulo'],
            'autores' => $autores,
            'autor' => explode(', ', $autores)[0] ?: '',
            'isbn' => (string)$linha['isbn'],
            'editora' => (string)$linha['editora'],
            'ano' => $linha['ano_publicacao'] !== null ? (int)$linha['ano_publicacao'] : null,
            'edicao' => (string)$linha['edicao'],
            'assunto' => (string)($linha['assunto'] ?: ($legado['assunto_original'] ?? '')),
            'sinopse' => (string)$linha['sinopse'],
            'categoria' => (string)($linha['categoria'] ?? ''),
            'categoria_id' => $linha['categoria_id'] !== null ? (int)$linha['categoria_id'] : null,
            'capa' => $linha['capa'],
            'cor' => cdd_cor_capa($id),
            'class' => $class,
            'legado' => $legado,
            'pesquisa' => $linha['p_resultado'] !== null ? ['resultado' => $linha['p_resultado'], 'consultado_em' => $linha['p_consultado_em'], 'total' => (int)$linha['p_total']] : null,
        ];
    }
    return $itens;
}

/** Dados antigos mais recentes de cada livro (tabela registros_legados, criada pela importação). */
function cdd_legados_mysql(): array
{
    static $existe = null;
    $existe ??= (bool)db_query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'registros_legados'")->fetchColumn();
    if (!$existe) {
        return [];
    }
    $sql = 'SELECT r.livro_id, r.cdd_original, r.codigo_autor_antigo, r.localizacao_antiga, r.assunto_original, r.categoria_original, r.arquivo, r.linha, r.importado_em
            FROM registros_legados r
            WHERE r.livro_id IS NOT NULL
              AND r.id = (SELECT MAX(r2.id) FROM registros_legados r2 WHERE r2.livro_id = r.livro_id)';
    $legados = [];
    foreach (db_query($sql)->fetchAll() as $linha) {
        $legados[(int)$linha['livro_id']] = $linha;
    }
    return $legados;
}

function cdd_cor_capa(int $id): string
{
    $cores = ['#143477', '#6b3a2e', '#7a5c1e', '#a0522d', '#1f6fb2', '#3d3d3d', '#7b1e3a', '#b5651d', '#0f2a4a', '#8c6d3f', '#0e7c7b', '#5b3f8c'];
    return $cores[$id % count($cores)];
}

/** Dados antigos fictícios do modo demonstrativo. Não contêm CDD inventada. */
function cdd_legados_demo(): array
{
    // Valores propositalmente fora do padrão CDD, como acontece nas planilhas antigas.
    $extras = [
        2 => ['cdd_original' => 'LIT. BRAS.', 'codigo_autor_antigo' => null, 'localizacao_antiga' => 'Estante 3 (sala antiga)', 'categoria_original' => 'Literatura Nacional', 'assunto_original' => 'Romance'],
        5 => ['cdd_original' => null, 'codigo_autor_antigo' => null, 'localizacao_antiga' => 'Estante 3', 'categoria_original' => 'Literatura Nacional', 'assunto_original' => ''],
        7 => ['cdd_original' => 'ROM-EST', 'codigo_autor_antigo' => null, 'localizacao_antiga' => 'Armário 2', 'categoria_original' => 'Romance estrangeiro', 'assunto_original' => 'Segunda Guerra'],
        8 => ['cdd_original' => null, 'codigo_autor_antigo' => null, 'localizacao_antiga' => 'Infantojuvenil', 'categoria_original' => 'Juvenil', 'assunto_original' => 'Fantasia'],
    ];
    $legados = [];
    foreach (demo_registros_planilha() as $r) {
        $legados[$r['livro_id']] = ($extras[$r['livro_id']] ?? []) + [
            'livro_id' => $r['livro_id'], 'arquivo' => $r['arquivo'], 'linha' => $r['linha'], 'importado_em' => $r['importado_em'],
        ];
    }
    return $legados;
}

function &cdd_demo_store(): array
{
    auth_sessao();
    if (!isset($_SESSION['cdd_demo']) || !is_array($_SESSION['cdd_demo'])) {
        $_SESSION['cdd_demo'] = ['classificacoes' => [], 'historico' => [], 'pesquisas' => [], 'consultas' => [], 'lotes' => [], 'seq' => 0];
    }
    return $_SESSION['cdd_demo'];
}

function cdd_demo_id(): int
{
    $store = &cdd_demo_store();
    return ++$store['seq'];
}

function cdd_itens_demo(): array
{
    $store = &cdd_demo_store();
    $legados = cdd_legados_demo();
    $itens = [];
    foreach (acervo_livros() as $id => $l) {
        $legado = $legados[$id] ?? null;
        $class = $store['classificacoes'][$id] ?? cdd_linha_padrao($id, $legado['cdd_original'] ?? null);
        $ultima = null;
        foreach (array_reverse($store['pesquisas'], true) as $p) {
            if ($p['livro_id'] === $id) {
                $ultima = ['resultado' => $p['resultado'], 'consultado_em' => $p['consultado_em'], 'total' => $p['total_registros']];
                break;
            }
        }
        $itens[$id] = [
            'id' => $id,
            'titulo' => $l['titulo'],
            'subtitulo' => (string)$l['subtitulo'],
            'autores' => implode(', ', $l['autores_nomes']),
            'autor' => $l['autores_nomes'][0] ?? '',
            'isbn' => acervo_isbn_normalizar($l['isbn']),
            'editora' => (string)$l['editora'],
            'ano' => $l['ano_publicacao'],
            'edicao' => (string)$l['edicao'],
            'assunto' => (string)($legado['assunto_original'] ?? '') ?: (string)$l['palavras_chave'],
            'sinopse' => (string)$l['sinopse'],
            'categoria' => $l['categoria_principal'],
            'categoria_id' => $l['categorias'][0] ?? null,
            'capa' => $l['capa'],
            'cor' => $l['cor'],
            'class' => $class,
            'legado' => $legado,
            'pesquisa' => $ultima,
        ];
    }
    uasort($itens, fn ($a, $b) => strcoll($a['titulo'], $b['titulo']) ?: $a['id'] <=> $b['id']);
    return $itens;
}

/** Indicadores do painel, calculados no banco quando houver MySQL. */
function cdd_resumo(): array
{
    if (db_modo() === 'mysql') {
        $r = db_query("SELECT COUNT(*) AS total,
                SUM(COALESCE(c.status, 'pendente') = 'pendente') AS pendente,
                SUM(c.status = 'sugerida') AS sugerida,
                SUM(c.status = 'em_revisao') AS em_revisao,
                SUM(c.status = 'conflito') AS conflito,
                SUM(c.cdd_aprovada IS NOT NULL) AS aprovadas,
                SUM(c.precisa_revisao = 1) AS precisa_revisao
            FROM livros l LEFT JOIN classificacoes_cdd c ON c.livro_id = l.id
            WHERE l.arquivado_em IS NULL")->fetch();
        $r = array_map('intval', $r);
    } else {
        $r = ['total' => 0, 'pendente' => 0, 'sugerida' => 0, 'em_revisao' => 0, 'conflito' => 0, 'aprovadas' => 0, 'precisa_revisao' => 0];
        foreach (cdd_itens() as $item) {
            $c = $item['class'];
            $r['total']++;
            $r[$c['status']] = ($r[$c['status']] ?? 0) + 1;
            $r['aprovadas'] += $c['cdd_aprovada'] !== null ? 1 : 0;
            $r['precisa_revisao'] += $c['precisa_revisao'] ? 1 : 0;
        }
        unset($r['aprovada']);
    }
    $r['percentual'] = $r['total'] > 0 ? round($r['aprovadas'] / $r['total'] * 100, 1) : 0.0;
    return $r;
}

/** Nome de quem fez a ação. */
function cdd_usuario_nome(?int $id): string
{
    if (!$id) {
        return '—';
    }
    static $nomes = [];
    if (!isset($nomes[$id])) {
        if (db_modo() === 'mysql') {
            $nomes[$id] = (string)(db_query('SELECT nome FROM administradores WHERE id = ?', [$id])->fetchColumn() ?: 'Usuário #' . $id);
        } else {
            $nomes[$id] = 'Usuário #' . $id;
            foreach (AUTH_CONTAS_DEMO as $conta) {
                if ($conta['id'] === $id) {
                    $nomes[$id] = $conta['nome'];
                }
            }
        }
    }
    return $nomes[$id];
}

/** Lista de responsáveis que já atuaram no módulo (filtros de histórico e relatório). */
function cdd_responsaveis(): array
{
    if (db_modo() === 'mysql') {
        return db_query('SELECT DISTINCT a.id, a.nome FROM classificacoes_cdd_historico h JOIN administradores a ON a.id = h.responsavel_id ORDER BY a.nome')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $lista = [];
    foreach (AUTH_CONTAS_DEMO as $conta) {
        $lista[$conta['id']] = $conta['nome'];
    }
    return $lista;
}

/**
 * Histórico, do mais recente para o mais antigo.
 * Filtros: livro_id, tipo, responsavel_id, de (Y-m-d), ate (Y-m-d), limite.
 */
function cdd_historico(array $f = []): array
{
    $limite = max(1, min(2000, (int)($f['limite'] ?? 500)));
    if (db_modo() === 'mysql') {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['livro_id'])) {
            $where[] = 'h.livro_id = ?';
            $params[] = (int)$f['livro_id'];
        }
        if (!empty($f['tipo']) && isset(CDD_HISTORICO_TIPOS[$f['tipo']])) {
            $where[] = 'h.tipo = ?';
            $params[] = $f['tipo'];
        }
        if (!empty($f['responsavel_id'])) {
            $where[] = 'h.responsavel_id = ?';
            $params[] = (int)$f['responsavel_id'];
        }
        if (!empty($f['de'])) {
            $where[] = 'h.criado_em >= ?';
            $params[] = $f['de'] . ' 00:00:00';
        }
        if (!empty($f['ate'])) {
            $where[] = 'h.criado_em <= ?';
            $params[] = $f['ate'] . ' 23:59:59';
        }
        $sql = 'SELECT h.*, l.titulo, a.nome AS responsavel FROM classificacoes_cdd_historico h
                JOIN livros l ON l.id = h.livro_id
                LEFT JOIN administradores a ON a.id = h.responsavel_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY h.criado_em DESC, h.id DESC LIMIT ' . $limite;
        return db_query($sql, $params)->fetchAll();
    }
    $store = &cdd_demo_store();
    $itens = cdd_itens();
    $lista = [];
    foreach (array_reverse($store['historico']) as $h) {
        if ((!empty($f['livro_id']) && $h['livro_id'] !== (int)$f['livro_id'])
            || (!empty($f['tipo']) && $h['tipo'] !== $f['tipo'])
            || (!empty($f['responsavel_id']) && $h['responsavel_id'] !== (int)$f['responsavel_id'])
            || (!empty($f['de']) && substr($h['criado_em'], 0, 10) < $f['de'])
            || (!empty($f['ate']) && substr($h['criado_em'], 0, 10) > $f['ate'])) {
            continue;
        }
        $h['titulo'] = $itens[$h['livro_id']]['titulo'] ?? '—';
        $h['responsavel'] = cdd_usuario_nome($h['responsavel_id']);
        $lista[] = $h;
        if (count($lista) >= $limite) {
            break;
        }
    }
    return $lista;
}

/** Última pesquisa do livro com os registros encontrados. */
function cdd_ultima_pesquisa(int $livroId): ?array
{
    if (db_modo() === 'mysql') {
        $p = db_query('SELECT * FROM cdd_pesquisas WHERE livro_id = ? ORDER BY id DESC LIMIT 1', [$livroId])->fetch();
        if (!$p) {
            return null;
        }
        $p['fontes'] = json_decode($p['fontes'], true) ?: [];
        $p['termos'] = json_decode($p['termos'], true) ?: [];
        $p['consultas'] = db_query('SELECT * FROM cdd_consultas WHERE pesquisa_id = ? ORDER BY FIELD(compatibilidade, "exata", "provavel", "incerta", "incompativel"), id', [$p['id']])->fetchAll();
        foreach ($p['consultas'] as &$c) {
            $c['motivos'] = json_decode((string)$c['motivos'], true) ?: [];
        }
        return $p;
    }
    $store = &cdd_demo_store();
    foreach (array_reverse($store['pesquisas']) as $p) {
        if ($p['livro_id'] === $livroId) {
            $p['consultas'] = array_values(array_filter($store['consultas'], fn ($c) => $c['pesquisa_id'] === $p['id']));
            $ordem = array_flip(array_keys(CDD_COMPATIBILIDADE));
            usort($p['consultas'], fn ($a, $b) => $ordem[$a['compatibilidade']] <=> $ordem[$b['compatibilidade']]);
            return $p;
        }
    }
    return null;
}

function cdd_consulta(int $id): ?array
{
    if (db_modo() === 'mysql') {
        return db_query('SELECT * FROM cdd_consultas WHERE id = ?', [$id])->fetch() ?: null;
    }
    $store = &cdd_demo_store();
    foreach ($store['consultas'] as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return null;
}

/** Próximo livro a classificar, depois de $depoisDe (pendentes e que precisam de revisão primeiro). */
function cdd_proximo_livro(?int $depoisDe = null, array $status = ['pendente', 'conflito', 'sugerida']): ?int
{
    $ids = [];
    foreach (cdd_itens() as $id => $item) {
        if (in_array($item['class']['status'], $status, true) && !$item['class']['rascunho']) {
            $ids[] = $id;
        }
    }
    if (!$ids) {
        return null;
    }
    if ($depoisDe !== null) {
        $pos = array_search($depoisDe, $ids, true);
        if ($pos !== false) {
            return $ids[$pos + 1] ?? ($ids[0] !== $depoisDe ? $ids[0] : null);
        }
        // Livro atual não está na fila: segue a ordem da listagem.
        $todos = array_keys(cdd_itens());
        $posTodos = array_search($depoisDe, $todos, true);
        foreach ($ids as $id) {
            if (array_search($id, $todos, true) > $posTodos) {
                return $id;
            }
        }
    }
    return $ids[0];
}

// ---------------------------------------------------------------
// Gravação
// ---------------------------------------------------------------

const CDD_ACOES = [
    'sugerir' => 'cdd.sugerir',
    'rascunho' => 'cdd.sugerir',
    'enviar_revisao' => 'cdd.sugerir',
    'observacao' => 'cdd.sugerir',
    'aprovar' => 'cdd.aprovar',
    'corrigir' => 'cdd.aprovar',
    'rejeitar' => 'cdd.aprovar',
    'nova_pesquisa' => 'cdd.aprovar',
];

/**
 * Aplica uma ação à classificação do livro, com transação e histórico.
 * $dados: cdd, descricao, assunto_principal, fonte_nome, fonte_url, data_consulta, justificativa,
 *         observacoes, observacao, consulta_id, ddc_edicao, versao (controle de edição simultânea).
 * Retorna a linha de classificação atualizada.
 */
function cdd_executar(string $acao, int $livroId, array $dados, array $usuario): array
{
    if (!isset(CDD_ACOES[$acao])) {
        throw new CddErro('Ação desconhecida.', [], 400);
    }
    if (!in_array(CDD_ACOES[$acao], AUTH_PERMISSOES[$usuario['perfil']] ?? [], true)) {
        throw new CddErro($acao === 'aprovar' || $acao === 'corrigir'
            ? 'Somente a bibliotecária ou um administrador pode aprovar classificações.'
            : 'Você não tem permissão para esta ação.', [], 403);
    }
    $item = cdd_item($livroId);
    if (!$item) {
        throw new CddErro('Livro não encontrado ou arquivado.', [], 404);
    }
    $dados = cdd_dados_limpos($dados);

    if (db_modo() === 'mysql') {
        $linha = db_transacao(function (PDO $pdo) use ($acao, $livroId, $dados, $usuario, $item) {
            $atual = cdd_linha_bloqueada($livroId, $item);
            cdd_conferir_versao($atual, $dados);
            [$nova, $historicos] = cdd_aplicar($acao, $atual, $dados, $usuario);
            cdd_gravar_mysql($nova, $historicos, $usuario);
            return cdd_linha_mysql($livroId);
        });
        cdd_itens(true);
        return $linha;
    }

    $store = &cdd_demo_store();
    $atual = $store['classificacoes'][$livroId] ?? cdd_linha_padrao($livroId, $item['class']['cdd_original']);
    cdd_conferir_versao($atual, $dados);
    [$nova, $historicos] = cdd_aplicar($acao, $atual, $dados, $usuario);
    cdd_gravar_demo($nova, $historicos, $usuario);
    cdd_itens(true);
    return $store['classificacoes'][$livroId];
}

function cdd_dados_limpos(array $d): array
{
    $texto = fn ($v, int $max) => mb_substr(trim((string)$v), 0, $max);
    $limpos = [
        'cdd' => $texto($d['cdd'] ?? '', 60),
        'descricao' => $texto($d['descricao'] ?? '', 255),
        'assunto_principal' => $texto($d['assunto_principal'] ?? '', 255),
        'fonte_nome' => $texto($d['fonte_nome'] ?? '', 255),
        'fonte_url' => $texto($d['fonte_url'] ?? '', 2000),
        'data_consulta' => $texto($d['data_consulta'] ?? '', 10),
        'justificativa' => $texto($d['justificativa'] ?? '', 5000),
        'observacoes' => $texto($d['observacoes'] ?? '', 5000),
        'observacao' => $texto($d['observacao'] ?? '', 5000),
        'ddc_edicao' => $texto($d['ddc_edicao'] ?? '', 20),
        'consulta_id' => (int)($d['consulta_id'] ?? 0) ?: null,
        'versao' => isset($d['versao']) && $d['versao'] !== '' ? (int)$d['versao'] : null,
    ];
    if ($limpos['fonte_url'] !== '' && !preg_match('#^https?://#i', $limpos['fonte_url'])) {
        throw new CddErro('O link da fonte deve começar com http:// ou https://.', ['fonte_url' => 'Informe um endereço completo, começando com https://.']);
    }
    if ($limpos['fonte_url'] !== '' && !filter_var($limpos['fonte_url'], FILTER_VALIDATE_URL)) {
        throw new CddErro('O link da fonte não é um endereço válido.', ['fonte_url' => 'Endereço inválido.']);
    }
    if ($limpos['data_consulta'] !== '') {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $limpos['data_consulta']);
        if (!$data || $data->format('Y-m-d') !== $limpos['data_consulta'] || $data > new DateTimeImmutable('tomorrow')) {
            throw new CddErro('Data da consulta inválida.', ['data_consulta' => 'Informe uma data válida, que não esteja no futuro.']);
        }
    }
    return $limpos;
}

function cdd_conferir_versao(array $atual, array $dados): void
{
    if ($dados['versao'] !== null && (int)$atual['versao'] !== $dados['versao']) {
        throw new CddErro('Esta classificação foi alterada por outra pessoa enquanto você trabalhava. Recarregue a página para ver a versão atual.', [], 409);
    }
}

/**
 * Regras de cada ação (iguais nos dois modos).
 * Retorna [linha nova, lista de históricos]. Lança CddErro quando a regra não é atendida.
 */
function cdd_aplicar(string $acao, array $c, array $d, array $usuario): array
{
    $agora = date('Y-m-d H:i:s');
    $nova = $c;
    $hist = [];
    $base = fn (string $tipo, array $extra = []) => $extra + [
        'tipo' => $tipo, 'cdd_anterior' => null, 'cdd_nova' => null, 'status_anterior' => $c['status'], 'status_novo' => null,
        'fonte_nome' => null, 'fonte_url' => null, 'justificativa' => null, 'observacao' => null,
    ];
    $exigirCdd = function () use ($d): string {
        $v = cdd_validar($d['cdd']);
        if (!$v['valida']) {
            throw new CddErro($v['erro'], ['cdd' => $v['erro']]);
        }
        return $v['numero'];
    };
    $proposta = function (bool $rascunho) use (&$nova, $d, $exigirCdd, $usuario, $agora) {
        $nova['cdd_proposta'] = $exigirCdd();
        foreach (['descricao', 'assunto_principal', 'fonte_nome', 'fonte_url', 'justificativa', 'observacoes', 'ddc_edicao'] as $campo) {
            $nova[$campo] = $d[$campo] !== '' ? $d[$campo] : null;
        }
        $nova['data_consulta'] = $d['data_consulta'] ?: date('Y-m-d');
        $nova['consulta_id'] = $d['consulta_id'];
        $nova['origem'] = $d['consulta_id'] ? 'consulta' : 'manual';
        $nova['rascunho'] = $rascunho ? 1 : 0;
        $nova['precisa_revisao'] = 0;
        $nova['sugerido_por'] = $usuario['id'];
        $nova['sugerido_em'] = $agora;
    };
    $exigirFonte = function () use (&$nova) {
        if (!$nova['fonte_nome']) {
            throw new CddErro('Informe a fonte bibliográfica utilizada.', ['fonte_nome' => 'Informe de onde veio a classificação.']);
        }
    };
    // CDD anterior "válida": a aprovada, ou a da planilha.
    $anterior = $c['cdd_aprovada'] ?? $c['cdd_original'];

    switch ($acao) {
        case 'sugerir':
        case 'rascunho':
            $proposta($acao === 'rascunho');
            if ($acao === 'sugerir') {
                $exigirFonte();
            }
            $nova['status'] = 'sugerida';
            $hist[] = $base($acao === 'rascunho' ? 'rascunho' : 'sugestao', [
                'cdd_anterior' => $c['cdd_proposta'] ?? $anterior, 'cdd_nova' => $nova['cdd_proposta'],
                'fonte_nome' => $nova['fonte_nome'], 'fonte_url' => $nova['fonte_url'], 'justificativa' => $nova['justificativa'], 'observacao' => $d['observacoes'] ?: null,
            ]);
            break;

        case 'enviar_revisao':
            if ($d['cdd'] !== '') {
                $proposta(false);
            } elseif (!$c['cdd_proposta']) {
                throw new CddErro('Registre uma CDD proposta antes de enviar para revisão.', ['cdd' => 'Informe a CDD proposta.']);
            }
            $exigirFonte();
            $nova['status'] = 'em_revisao';
            $nova['rascunho'] = 0;
            $hist[] = $base('envio_revisao', [
                'cdd_anterior' => $anterior, 'cdd_nova' => $nova['cdd_proposta'],
                'fonte_nome' => $nova['fonte_nome'], 'fonte_url' => $nova['fonte_url'], 'justificativa' => $nova['justificativa'], 'observacao' => $d['observacao'] ?: null,
            ]);
            break;

        case 'aprovar':
        case 'corrigir':
            $numero = $d['cdd'] !== '' ? $exigirCdd() : $c['cdd_proposta'];
            if (!$numero) {
                throw new CddErro('Não há CDD proposta para aprovar.', ['cdd' => 'Informe a CDD.']);
            }
            $corrigida = $c['cdd_proposta'] !== null && $numero !== $c['cdd_proposta'];
            $tipo = ($acao === 'corrigir' || $corrigida) ? 'correcao' : 'aprovacao';
            foreach (['descricao', 'assunto_principal', 'fonte_nome', 'fonte_url', 'ddc_edicao'] as $campo) {
                if ($d[$campo] !== '') {
                    $nova[$campo] = $d[$campo];
                }
            }
            if ($d['justificativa'] !== '') {
                $nova['justificativa'] = $d['justificativa'];
            }
            if ($tipo === 'correcao' && $d['justificativa'] === '') {
                throw new CddErro('Explique a correção na justificativa.', ['justificativa' => 'Informe por que a CDD foi corrigida.']);
            }
            if ($c['cdd_proposta'] === null || $tipo === 'correcao') {
                // Classificação manual ou corrigida pela bibliotecária.
                $nova['origem'] = $d['consulta_id'] ? 'consulta' : 'manual';
                $nova['consulta_id'] = $d['consulta_id'] ?? $nova['consulta_id'];
                $nova['sugerido_por'] ??= $usuario['id'];
                $nova['sugerido_em'] ??= $agora;
                $nova['data_consulta'] = $d['data_consulta'] ?: ($nova['data_consulta'] ?? date('Y-m-d'));
            }
            $exigirFonte();
            $nova['cdd_proposta'] = $numero;
            $nova['cdd_aprovada'] = $numero;
            $nova['aprovada_fonte'] = $nova['fonte_nome'];
            $nova['aprovado_por'] = $usuario['id'];
            $nova['aprovado_em'] = $agora;
            $nova['status'] = 'aprovada';
            $nova['rascunho'] = 0;
            $nova['precisa_revisao'] = 0;
            $hist[] = $base($tipo, [
                'cdd_anterior' => $anterior, 'cdd_nova' => $numero, 'fonte_nome' => $nova['fonte_nome'], 'fonte_url' => $nova['fonte_url'],
                'justificativa' => $d['justificativa'] ?: null, 'observacao' => $d['observacao'] ?: null,
            ]);
            break;

        case 'rejeitar':
        case 'nova_pesquisa':
            if ($acao === 'rejeitar' && !$c['cdd_proposta']) {
                throw new CddErro('Não há sugestão para rejeitar.');
            }
            $motivo = $d['observacao'] ?: $d['justificativa'];
            if ($motivo === '') {
                throw new CddErro('Explique o motivo para orientar a próxima pesquisa.', ['observacao' => 'Informe o motivo.']);
            }
            $proposta = $c['cdd_proposta'];
            // A CDD aprovada anteriormente continua valendo até ser substituída.
            if ($c['cdd_aprovada'] !== null) {
                $nova['status'] = 'aprovada';
                $nova['cdd_proposta'] = $c['cdd_aprovada'];
                $nova['fonte_nome'] = $c['aprovada_fonte'];
            } else {
                $nova['status'] = 'pendente';
                $nova['cdd_proposta'] = null;
                $nova['consulta_id'] = null;
                $nova['origem'] = null;
            }
            $nova['rascunho'] = 0;
            $nova['precisa_revisao'] = 1;
            $hist[] = $base($acao, [
                'cdd_anterior' => $proposta, 'cdd_nova' => $nova['cdd_aprovada'], 'fonte_nome' => $c['fonte_nome'], 'fonte_url' => $c['fonte_url'], 'observacao' => $motivo,
            ]);
            break;

        case 'observacao':
            if ($d['observacao'] === '') {
                throw new CddErro('Escreva a observação.', ['observacao' => 'Escreva a observação.']);
            }
            $hist[] = $base('observacao', ['observacao' => $d['observacao'], 'cdd_nova' => $c['cdd_proposta'], 'status_novo' => $c['status']]);
            return [$c, $hist];
    }

    foreach ($hist as &$h) {
        $h['status_novo'] ??= $nova['status'];
    }
    unset($h);
    $nova['versao'] = (int)$c['versao'] + 1;
    return [$nova, $hist];
}

/** Linha atual bloqueada para atualização (SELECT ... FOR UPDATE); cria a linha se ainda não existir. */
function cdd_linha_bloqueada(int $livroId, array $item): array
{
    $linha = db_query('SELECT * FROM classificacoes_cdd WHERE livro_id = ? FOR UPDATE', [$livroId])->fetch();
    if (!$linha) {
        db_query('INSERT IGNORE INTO classificacoes_cdd (livro_id, cdd_original, versao) VALUES (?, ?, 0)', [$livroId, $item['legado']['cdd_original'] ?? null]);
        $linha = db_query('SELECT * FROM classificacoes_cdd WHERE livro_id = ? FOR UPDATE', [$livroId])->fetch();
    } elseif ($linha['cdd_original'] === null && !empty($item['legado']['cdd_original'])) {
        // A importação chegou depois: grava a CDD original uma única vez.
        db_query('UPDATE classificacoes_cdd SET cdd_original = ? WHERE id = ?', [$item['legado']['cdd_original'], $linha['id']]);
        $linha['cdd_original'] = $item['legado']['cdd_original'];
    }
    $linha['versao'] = (int)$linha['versao'];
    return $linha;
}

function cdd_linha_mysql(int $livroId): array
{
    $linha = db_query('SELECT * FROM classificacoes_cdd WHERE livro_id = ?', [$livroId])->fetch();
    $linha['versao'] = (int)$linha['versao'];
    return $linha;
}

const CDD_CAMPOS_GRAVAVEIS = ['cdd_proposta', 'cdd_aprovada', 'ddc_edicao', 'descricao', 'assunto_principal', 'fonte_nome', 'fonte_url', 'data_consulta',
    'justificativa', 'observacoes', 'origem', 'rascunho', 'precisa_revisao', 'status', 'consulta_id', 'sugerido_por', 'sugerido_em',
    'aprovado_por', 'aprovado_em', 'aprovada_fonte', 'versao'];

function cdd_gravar_mysql(array $nova, array $historicos, array $usuario): void
{
    $sets = implode(', ', array_map(fn ($c) => "$c = ?", CDD_CAMPOS_GRAVAVEIS));
    $valores = array_map(fn ($c) => $nova[$c], CDD_CAMPOS_GRAVAVEIS);
    $valores[] = $nova['id'];
    db_query("UPDATE classificacoes_cdd SET $sets WHERE id = ?", $valores);
    foreach ($historicos as $h) {
        db_query('INSERT INTO classificacoes_cdd_historico (livro_id, classificacao_id, tipo, cdd_anterior, cdd_nova, status_anterior, status_novo, fonte_nome, fonte_url, justificativa, observacao, responsavel_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $nova['livro_id'], $nova['id'], $h['tipo'], $h['cdd_anterior'], $h['cdd_nova'], $h['status_anterior'], $h['status_novo'],
            $h['fonte_nome'], $h['fonte_url'], $h['justificativa'], $h['observacao'], $usuario['id'],
        ]);
    }
}

function cdd_gravar_demo(array $nova, array $historicos, array $usuario): void
{
    $store = &cdd_demo_store();
    $agora = date('Y-m-d H:i:s');
    $nova['id'] ??= $nova['livro_id'];
    $nova['criado_em'] ??= $agora;
    $nova['atualizado_em'] = $agora;
    $store['classificacoes'][$nova['livro_id']] = $nova;
    foreach ($historicos as $h) {
        $store['historico'][] = $h + ['id' => cdd_demo_id(), 'livro_id' => $nova['livro_id'], 'classificacao_id' => $nova['id'], 'responsavel_id' => $usuario['id'], 'criado_em' => $agora];
    }
}

// ---------------------------------------------------------------
// Pesquisa de um livro (individual ou em lote)
// ---------------------------------------------------------------

/** Termos de pesquisa a partir do cadastro do livro. */
function cdd_termos_livro(array $item): array
{
    return [
        'isbn' => $item['isbn'],
        'titulo' => $item['titulo'],
        'autor' => $item['autor'],
        'editora' => $item['editora'],
        'ano' => (string)($item['ano'] ?? ''),
        'edicao' => $item['edicao'],
        'assunto' => $item['assunto'],
    ];
}

/**
 * Pesquisa as fontes para um livro, grava a pesquisa e os registros e, quando os
 * registros compatíveis coincidem, registra uma SUGESTÃO (nunca uma aprovação).
 * Retorna ['pesquisa' => ..., 'classificacao' => ..., 'mensagem' => ...].
 */
function cdd_pesquisar_livro(int $livroId, array $usuario, ?array $termos = null, ?array $fontes = null, ?int $loteId = null): array
{
    $item = cdd_item($livroId);
    if (!$item) {
        throw new CddErro('Livro não encontrado ou arquivado.', [], 404);
    }
    $termos ??= cdd_termos_livro($item);
    $busca = cdd_pesquisar_fontes($termos, $fontes);
    $livroComparacao = ['isbn' => $item['isbn'], 'titulo' => $item['titulo'], 'autor' => $item['autor'], 'ano' => $item['ano'], 'editora' => $item['editora']];

    // Um registro com várias CDDs (ex.: completa e abreviada) vira uma consulta por número.
    $consultas = [];
    foreach ($busca['registros'] as $reg) {
        $compat = cdd_compatibilidade($livroComparacao, $reg);
        $cdds = array_values(array_filter($reg['cdds'], fn ($c) => $c['numero'] !== '' && !$c['abreviada'])) ?: [null];
        foreach ($cdds as $cdd) {
            $motivos = $compat['motivos'];
            if ($cdd && $reg['fonte'] === 'loc') {
                $motivos[] = $cdd['atribuida_por_lc'] ? 'CDD atribuída pela Library of Congress' : 'CDD atribuída por outra instituição';
            }
            if (!$cdd && $reg['cdds']) {
                $motivos[] = 'O registro traz um valor que não é CDD válida: ' . implode(', ', array_column($reg['cdds'], 'bruta'));
            }
            $consultas[] = [
                'fonte' => $reg['fonte'], 'fonte_nome' => $reg['fonte_nome'], 'registro_id' => mb_substr($reg['registro_id'], 0, 255),
                'registro_url' => $reg['registro_url'], 'titulo' => mb_substr($reg['titulo'], 0, 500), 'autor' => mb_substr($reg['autor'], 0, 500),
                'editora' => mb_substr($reg['editora'], 0, 255), 'ano' => mb_substr($reg['ano'], 0, 20), 'edicao' => mb_substr($reg['edicao'], 0, 100),
                'isbn' => mb_substr(implode(', ', $reg['isbns']), 0, 255),
                'cdd' => $cdd['numero'] ?? null, 'cdd_bruta' => $cdd['bruta'] ?? null, 'ddc_edicao' => $cdd['ddc_edicao'] ?? null,
                'lcc' => $reg['lcc'] !== '' ? mb_substr($reg['lcc'], 0, 100) : null,
                'compatibilidade' => $compat['nivel'], 'motivos' => $motivos,
            ];
        }
    }

    $confiaveis = array_filter($consultas, fn ($c) => $c['cdd'] !== null && in_array($c['compatibilidade'], ['exata', 'provavel'], true));
    $numeros = array_values(array_unique(array_column($confiaveis, 'cdd')));
    $erros = array_filter($busca['fontes'], fn ($f) => $f['status'] === 'erro');
    if (count($numeros) > 1) {
        $resultado = 'conflito';
    } elseif (count($numeros) === 1) {
        $resultado = 'com_cdd';
    } elseif ($consultas) {
        $resultado = 'sem_cdd';
    } elseif ($erros && count($erros) === count($busca['fontes'])) {
        $resultado = 'erro';
    } else {
        $resultado = 'sem_registro';
    }
    $mensagem = match ($resultado) {
        'com_cdd' => 'Sugestão encontrada: ' . $numeros[0] . '. Ela precisa ser validada antes de ser aprovada.',
        'conflito' => 'As fontes indicam números diferentes (' . implode(', ', $numeros) . '). Analise os registros e escolha manualmente.',
        'erro' => 'Não foi possível consultar as fontes agora. Tente novamente mais tarde.',
        default => CDD_SEM_RESULTADO,
    };

    $grava = function () use ($livroId, $item, $termos, $busca, $resultado, $consultas, $mensagem, $usuario, $loteId, $numeros, $confiaveis) {
        $pesquisa = [
            'livro_id' => $livroId, 'lote_id' => $loteId, 'termos' => $termos, 'fontes' => $busca['fontes'], 'resultado' => $resultado,
            'total_registros' => count($consultas), 'mensagem' => mb_substr($mensagem, 0, 255), 'consultado_por' => $usuario['id'], 'consultado_em' => date('Y-m-d H:i:s'),
        ];
        $pesquisa['id'] = cdd_inserir_pesquisa($pesquisa);
        $ids = [];
        foreach ($consultas as $i => $c) {
            $ids[$i] = cdd_inserir_consulta($c + ['pesquisa_id' => $pesquisa['id'], 'livro_id' => $livroId]);
        }
        $pesquisa['consultas'] = array_map(fn ($c, $i) => $c + ['id' => $ids[$i], 'pesquisa_id' => $pesquisa['id']], $consultas, array_keys($consultas));

        // Atualiza a situação somente de livros ainda não encaminhados (nunca mexe em revisão ou aprovação).
        $atual = db_modo() === 'mysql' ? cdd_linha_bloqueada($livroId, $item) : (cdd_demo_store()['classificacoes'][$livroId] ?? cdd_linha_padrao($livroId, $item['class']['cdd_original']));
        $podeAtualizar = in_array($atual['status'], ['pendente', 'conflito'], true)
            || ($atual['status'] === 'sugerida' && $atual['origem'] === 'consulta' && !$atual['rascunho']);
        $nova = null;
        $hist = [];
        if ($podeAtualizar && $resultado === 'com_cdd') {
            $primeiro = array_key_first($confiaveis);
            $fontesNomes = implode(' + ', array_unique(array_column($confiaveis, 'fonte_nome')));
            $nova = array_merge($atual, [
                'cdd_proposta' => $numeros[0], 'ddc_edicao' => $confiaveis[$primeiro]['ddc_edicao'], 'fonte_nome' => mb_substr($fontesNomes, 0, 255),
                'fonte_url' => $confiaveis[$primeiro]['registro_url'], 'data_consulta' => date('Y-m-d'), 'origem' => 'consulta', 'consulta_id' => $ids[$primeiro],
                'status' => 'sugerida', 'rascunho' => 0, 'sugerido_por' => $usuario['id'], 'sugerido_em' => date('Y-m-d H:i:s'),
            ]);
            $hist[] = ['tipo' => 'sugestao', 'cdd_anterior' => $atual['cdd_proposta'] ?? $atual['cdd_original'], 'cdd_nova' => $numeros[0], 'status_anterior' => $atual['status'], 'status_novo' => 'sugerida',
                'fonte_nome' => $nova['fonte_nome'], 'fonte_url' => $nova['fonte_url'], 'justificativa' => null,
                'observacao' => count($confiaveis) . ' registro(s) compatível(is) indicam o mesmo número. Sugestão automática: precisa de validação.'];
        } elseif ($podeAtualizar && $resultado === 'conflito' && $atual['status'] !== 'conflito') {
            $nova = array_merge($atual, ['status' => 'conflito', 'cdd_proposta' => null, 'consulta_id' => null, 'origem' => null]);
            $hist[] = ['tipo' => 'conflito', 'cdd_anterior' => $atual['cdd_proposta'], 'cdd_nova' => null, 'status_anterior' => $atual['status'], 'status_novo' => 'conflito',
                'fonte_nome' => mb_substr(implode(' + ', array_unique(array_column($confiaveis, 'fonte_nome'))), 0, 255), 'fonte_url' => null, 'justificativa' => null,
                'observacao' => 'Números diferentes encontrados: ' . implode(', ', $numeros) . '.'];
        }
        if ($nova) {
            $nova['versao'] = (int)$atual['versao'] + 1;
            db_modo() === 'mysql' ? cdd_gravar_mysql($nova, $hist, $usuario) : cdd_gravar_demo($nova, $hist, $usuario);
        }
        return $pesquisa;
    };

    $pesquisa = db_modo() === 'mysql' ? db_transacao(fn () => $grava()) : $grava();
    cdd_itens(true);
    return ['pesquisa' => $pesquisa, 'mensagem' => $mensagem, 'resultado' => $resultado];
}

function cdd_inserir_pesquisa(array $p): int
{
    if (db_modo() === 'mysql') {
        db_query('INSERT INTO cdd_pesquisas (livro_id, lote_id, termos, fontes, resultado, total_registros, mensagem, consultado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $p['livro_id'], $p['lote_id'], json_encode($p['termos'], JSON_UNESCAPED_UNICODE), json_encode($p['fontes'], JSON_UNESCAPED_UNICODE),
            $p['resultado'], $p['total_registros'], $p['mensagem'], $p['consultado_por'],
        ]);
        return (int)db()->lastInsertId();
    }
    $store = &cdd_demo_store();
    $p['id'] = cdd_demo_id();
    $store['pesquisas'][] = $p;
    return $p['id'];
}

function cdd_inserir_consulta(array $c): int
{
    if (db_modo() === 'mysql') {
        db_query('INSERT INTO cdd_consultas (pesquisa_id, livro_id, fonte, fonte_nome, registro_id, registro_url, titulo, autor, editora, ano, edicao, isbn, cdd, cdd_bruta, ddc_edicao, lcc, compatibilidade, motivos)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $c['pesquisa_id'], $c['livro_id'], $c['fonte'], $c['fonte_nome'], $c['registro_id'], $c['registro_url'], $c['titulo'], $c['autor'], $c['editora'],
            $c['ano'], $c['edicao'], $c['isbn'], $c['cdd'], $c['cdd_bruta'], $c['ddc_edicao'], $c['lcc'], $c['compatibilidade'], json_encode($c['motivos'], JSON_UNESCAPED_UNICODE),
        ]);
        return (int)db()->lastInsertId();
    }
    $store = &cdd_demo_store();
    $c['id'] = cdd_demo_id();
    $store['consultas'][] = $c;
    // A sessão guarda só as consultas mais recentes, para não crescer sem limite.
    if (count($store['consultas']) > 400) {
        $store['consultas'] = array_slice($store['consultas'], -400);
    }
    return $c['id'];
}

// ---------------------------------------------------------------
// Lotes
// ---------------------------------------------------------------

function cdd_lote_criar(array $livroIds, array $fontes, array $usuario): array
{
    $itens = cdd_itens();
    $ids = array_values(array_unique(array_filter(array_map('intval', $livroIds), fn ($id) => isset($itens[$id]))));
    if (!$ids) {
        throw new CddErro('Selecione ao menos um livro.');
    }
    if (count($ids) > 500) {
        throw new CddErro('Um lote pode ter no máximo 500 livros. Divida a seleção.');
    }
    $fontes = array_values(array_intersect($fontes, array_keys(cdd_fontes_automaticas())));
    if (!$fontes) {
        throw new CddErro('Selecione ao menos uma fonte habilitada.');
    }
    $nome = 'Lote de ' . date('d/m/Y H:i') . ' (' . count($ids) . ' livros)';
    if (db_modo() === 'mysql') {
        return db_transacao(function () use ($ids, $fontes, $usuario, $nome) {
            db_query('INSERT INTO cdd_lotes (nome, status, fontes, total, criado_por) VALUES (?, "pendente", ?, ?, ?)', [$nome, json_encode($fontes), count($ids), $usuario['id']]);
            $loteId = (int)db()->lastInsertId();
            $stmt = db()->prepare('INSERT INTO cdd_lote_itens (lote_id, livro_id) VALUES (?, ?)');
            foreach ($ids as $id) {
                $stmt->execute([$loteId, $id]);
            }
            return cdd_lote($loteId);
        });
    }
    $store = &cdd_demo_store();
    $id = cdd_demo_id();
    $store['lotes'][$id] = [
        'id' => $id, 'nome' => $nome, 'status' => 'pendente', 'fontes' => $fontes, 'total' => count($ids), 'criado_por' => $usuario['id'],
        'criado_em' => date('Y-m-d H:i:s'), 'concluido_em' => null,
        'itens' => array_map(fn ($livro) => ['livro_id' => $livro, 'status' => 'pendente', 'pesquisa_id' => null, 'mensagem' => null, 'resultado' => null], $ids),
    ];
    return cdd_lote($id);
}

/** Lote com itens, resultados e agrupamentos (coincidentes, conflitos, sem correspondência). */
function cdd_lote(int $id): ?array
{
    if (db_modo() === 'mysql') {
        $lote = db_query('SELECT * FROM cdd_lotes WHERE id = ?', [$id])->fetch();
        if (!$lote) {
            return null;
        }
        $lote['fontes'] = json_decode($lote['fontes'], true) ?: [];
        $lote['itens'] = db_query('SELECT i.livro_id, i.status, i.pesquisa_id, i.mensagem, p.resultado FROM cdd_lote_itens i LEFT JOIN cdd_pesquisas p ON p.id = i.pesquisa_id WHERE i.lote_id = ? ORDER BY i.livro_id', [$id])->fetchAll();
    } else {
        $lote = cdd_demo_store()['lotes'][$id] ?? null;
        if (!$lote) {
            return null;
        }
    }
    $itens = cdd_itens();
    $grupos = ['com_cdd' => [], 'conflito' => [], 'sem_cdd' => [], 'sem_registro' => [], 'erro' => []];
    $processados = 0;
    foreach ($lote['itens'] as &$it) {
        $it['livro_id'] = (int)$it['livro_id'];
        $livro = $itens[$it['livro_id']] ?? null;
        $it['titulo'] = $livro['titulo'] ?? '—';
        $it['autor'] = $livro['autor'] ?? '';
        $it['status_cdd'] = $livro['class']['status'] ?? 'pendente';
        $it['cdd_proposta'] = $livro['class']['cdd_proposta'] ?? null;
        if ($it['status'] !== 'pendente') {
            $processados++;
            $grupos[$it['resultado'] ?? 'erro'][] = $it['livro_id'];
        }
    }
    unset($it);
    // Sugestões coincidentes: livros diferentes com a mesma CDD proposta (cada edição continua sendo aprovada individualmente).
    $coincidentes = [];
    foreach ($grupos['com_cdd'] as $livroId) {
        $numero = $itens[$livroId]['class']['cdd_proposta'] ?? null;
        if ($numero) {
            $coincidentes[$numero][] = $livroId;
        }
    }
    ksort($coincidentes, SORT_STRING);
    $lote['processados'] = $processados;
    $lote['grupos'] = $grupos;
    $lote['coincidentes'] = $coincidentes;
    $lote['percentual'] = $lote['total'] > 0 ? round($processados / $lote['total'] * 100) : 0;
    return $lote;
}

function cdd_lotes(int $limite = 10): array
{
    if (db_modo() === 'mysql') {
        $ids = db_query('SELECT id FROM cdd_lotes ORDER BY id DESC LIMIT ' . max(1, $limite))->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $ids = array_slice(array_reverse(array_keys(cdd_demo_store()['lotes'])), 0, $limite);
    }
    return array_values(array_filter(array_map(fn ($id) => cdd_lote((int)$id), $ids)));
}

/** Processa o próximo item pendente do lote. Chamado repetidamente pelo navegador. */
function cdd_lote_processar(int $loteId, array $usuario): array
{
    $lote = cdd_lote($loteId);
    if (!$lote) {
        throw new CddErro('Lote não encontrado.', [], 404);
    }
    if ($lote['status'] === 'cancelado') {
        throw new CddErro('Este lote foi cancelado.');
    }
    $proximo = null;
    if (db_modo() === 'mysql') {
        // Reserva o item para que duas abas não pesquisem o mesmo livro ao mesmo tempo.
        $proximo = db_transacao(function () use ($loteId) {
            $livro = db_query('SELECT livro_id FROM cdd_lote_itens WHERE lote_id = ? AND status = "pendente" ORDER BY livro_id LIMIT 1 FOR UPDATE SKIP LOCKED', [$loteId])->fetchColumn();
            if ($livro !== false) {
                db_query('UPDATE cdd_lotes SET status = "processando" WHERE id = ? AND status = "pendente"', [$loteId]);
            }
            return $livro === false ? null : (int)$livro;
        });
    } else {
        foreach ($lote['itens'] as $it) {
            if ($it['status'] === 'pendente') {
                $proximo = $it['livro_id'];
                break;
            }
        }
    }

    if ($proximo !== null) {
        try {
            $r = cdd_pesquisar_livro($proximo, $usuario, null, $lote['fontes'], $loteId);
            cdd_lote_marcar($loteId, $proximo, $r['resultado'] === 'erro' ? 'erro' : 'concluido', $r['pesquisa']['id'], $r['mensagem'], $r['resultado']);
        } catch (Throwable $e) {
            error_log('[biblioteca] Falha no lote ' . $loteId . ': ' . $e->getMessage());
            cdd_lote_marcar($loteId, $proximo, 'erro', null, 'Não foi possível pesquisar este livro.', 'erro');
        }
    }

    $lote = cdd_lote($loteId);
    if ($lote['processados'] >= $lote['total'] && $lote['status'] !== 'concluido') {
        if (db_modo() === 'mysql') {
            db_query('UPDATE cdd_lotes SET status = "concluido", concluido_em = NOW() WHERE id = ?', [$loteId]);
        } else {
            $store = &cdd_demo_store();
            $store['lotes'][$loteId]['status'] = 'concluido';
            $store['lotes'][$loteId]['concluido_em'] = date('Y-m-d H:i:s');
        }
        $lote = cdd_lote($loteId);
    }
    return $lote;
}

function cdd_lote_marcar(int $loteId, int $livroId, string $status, ?int $pesquisaId, string $mensagem, string $resultado): void
{
    if (db_modo() === 'mysql') {
        db_query('UPDATE cdd_lote_itens SET status = ?, pesquisa_id = ?, mensagem = ?, processado_em = NOW() WHERE lote_id = ? AND livro_id = ?', [$status, $pesquisaId, mb_substr($mensagem, 0, 255), $loteId, $livroId]);
        return;
    }
    $store = &cdd_demo_store();
    foreach ($store['lotes'][$loteId]['itens'] as &$it) {
        if ($it['livro_id'] === $livroId) {
            $it = ['livro_id' => $livroId, 'status' => $status, 'pesquisa_id' => $pesquisaId, 'mensagem' => $mensagem, 'resultado' => $resultado];
        }
    }
    unset($it);
    if ($store['lotes'][$loteId]['status'] === 'pendente') {
        $store['lotes'][$loteId]['status'] = 'processando';
    }
}

/** Volta os itens com falha de consulta para a fila do lote. */
function cdd_lote_repetir_falhas(int $loteId): void
{
    if (db_modo() === 'mysql') {
        db_transacao(function () use ($loteId) {
            $n = db_query('UPDATE cdd_lote_itens SET status = "pendente", mensagem = NULL WHERE lote_id = ? AND status = "erro"', [$loteId])->rowCount();
            if ($n) {
                db_query('UPDATE cdd_lotes SET status = "processando", concluido_em = NULL WHERE id = ? AND status <> "cancelado"', [$loteId]);
            }
        });
        return;
    }
    $store = &cdd_demo_store();
    if (!isset($store['lotes'][$loteId]) || $store['lotes'][$loteId]['status'] === 'cancelado') {
        return;
    }
    foreach ($store['lotes'][$loteId]['itens'] as &$it) {
        if ($it['status'] === 'erro') {
            $it = ['livro_id' => $it['livro_id'], 'status' => 'pendente', 'pesquisa_id' => null, 'mensagem' => null, 'resultado' => null];
            $store['lotes'][$loteId]['status'] = 'processando';
            $store['lotes'][$loteId]['concluido_em'] = null;
        }
    }
    unset($it);
}

function cdd_lote_cancelar(int $loteId): void
{
    if (db_modo() === 'mysql') {
        db_query('UPDATE cdd_lotes SET status = "cancelado", concluido_em = NOW() WHERE id = ? AND status IN ("pendente", "processando")', [$loteId]);
        return;
    }
    $store = &cdd_demo_store();
    if (isset($store['lotes'][$loteId])) {
        $store['lotes'][$loteId]['status'] = 'cancelado';
    }
}

// ---------------------------------------------------------------
// Relatórios
// ---------------------------------------------------------------

/**
 * Indicadores e linhas do relatório. Filtros: de, ate (Y-m-d, sobre a última alteração),
 * categoria_id, status, responsavel_id (quem sugeriu ou aprovou).
 */
function cdd_relatorio(array $f = []): array
{
    $linhas = [];
    foreach (cdd_itens() as $item) {
        $c = $item['class'];
        if (!empty($f['categoria_id']) && (int)$item['categoria_id'] !== (int)$f['categoria_id']) {
            continue;
        }
        if (!empty($f['status'])) {
            $ok = match ($f['status']) {
                'precisa_revisao' => (bool)$c['precisa_revisao'],
                'sem_referencia' => in_array($item['pesquisa']['resultado'] ?? '', ['sem_registro', 'sem_cdd'], true) && $c['cdd_aprovada'] === null,
                default => $c['status'] === $f['status'],
            };
            if (!$ok) {
                continue;
            }
        }
        if (!empty($f['responsavel_id']) && !in_array((int)$f['responsavel_id'], [(int)$c['sugerido_por'], (int)$c['aprovado_por']], true)) {
            continue;
        }
        $data = substr((string)($c['atualizado_em'] ?? ''), 0, 10);
        if ((!empty($f['de']) || !empty($f['ate'])) && $data === '') {
            continue;
        }
        if ((!empty($f['de']) && $data < $f['de']) || (!empty($f['ate']) && $data > $f['ate'])) {
            continue;
        }
        $linhas[] = $item;
    }

    $hist = cdd_historico(['de' => $f['de'] ?? null, 'ate' => $f['ate'] ?? null, 'responsavel_id' => $f['responsavel_id'] ?? null, 'limite' => 2000]);
    $idsLinhas = array_flip(array_column($linhas, 'id'));
    $rejeitadas = count(array_filter($hist, fn ($h) => $h['tipo'] === 'rejeicao' && isset($idsLinhas[(int)$h['livro_id']])));
    $conflitosHist = array_unique(array_map(fn ($h) => (int)$h['livro_id'], array_filter($hist, fn ($h) => $h['tipo'] === 'conflito' && isset($idsLinhas[(int)$h['livro_id']]))));

    $porClasse = array_fill_keys(array_keys(CDD_CLASSES), 0);
    $resumo = ['total' => count($linhas), 'analisados' => 0, 'aprovadas' => 0, 'pendentes' => 0, 'rejeitadas' => $rejeitadas, 'conflitos' => 0, 'sem_referencia' => 0];
    foreach ($linhas as $item) {
        $c = $item['class'];
        $analisado = $c['status'] !== 'pendente' || $item['pesquisa'] !== null || $c['precisa_revisao'];
        $resumo['analisados'] += $analisado ? 1 : 0;
        $resumo['aprovadas'] += $c['cdd_aprovada'] !== null ? 1 : 0;
        $resumo['pendentes'] += $c['cdd_aprovada'] === null ? 1 : 0;
        $resumo['conflitos'] += ($c['status'] === 'conflito' || in_array($item['id'], $conflitosHist, true)) ? 1 : 0;
        $resumo['sem_referencia'] += in_array($item['pesquisa']['resultado'] ?? '', ['sem_registro', 'sem_cdd'], true) && $c['cdd_aprovada'] === null ? 1 : 0;
        if (($classe = cdd_classe_principal($c['cdd_aprovada'])) !== null) {
            $porClasse[$classe]++;
        }
    }
    return ['resumo' => $resumo, 'por_classe' => $porClasse, 'linhas' => $linhas];
}

// ---------------------------------------------------------------
// Componentes visuais
// ---------------------------------------------------------------

function cdd_badge_status(string $status, bool $rascunho = false): string
{
    $s = CDD_STATUS[$status] ?? CDD_STATUS['pendente'];
    $html = '<span class="adm-status adm-tone--' . $s['tom'] . '" title="' . htmlspecialchars($s['descricao']) . '">' . htmlspecialchars($s['label']) . '</span>';
    if ($rascunho) {
        $html .= ' <span class="adm-chip adm-chip--draft">Rascunho</span>';
    }
    return $html;
}

function cdd_badge_compat(string $nivel): string
{
    $s = CDD_COMPATIBILIDADE[$nivel] ?? CDD_COMPATIBILIDADE['incerta'];
    return '<span class="adm-status adm-status--soft adm-tone--' . $s['tom'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

/** CDD em destaque, ou traço. */
function cdd_numero(?string $cdd, string $vazio = '—'): string
{
    if ($cdd === null || $cdd === '') {
        return '<span class="adm-missing">' . htmlspecialchars($vazio) . '</span>';
    }
    $classe = cdd_formato_valido($cdd) ? 'adm-cdd' : 'adm-cdd adm-cdd--legacy';
    $titulo = cdd_formato_valido($cdd) ? '' : ' title="Valor fora do formato da CDD"';
    return '<span class="' . $classe . '"' . $titulo . '>' . htmlspecialchars($cdd) . '</span>';
}

function cdd_data_hora(?string $data): string
{
    if (!$data) {
        return '—';
    }
    $d = date_create($data);
    return $d ? $d->format(strlen($data) > 10 ? 'd/m/Y H:i' : 'd/m/Y') : $data;
}

/** Abas internas do módulo. */
function cdd_abas(string $atual): string
{
    $abas = [
        'classificacao' => ['Painel', 'list-tree'],
        'revisao-cdd' => ['Fila de aprovação', 'list-checks'],
        'historico-cdd' => ['Histórico', 'history'],
        'relatorio-cdd' => ['Relatórios', 'file-chart-column'],
    ];
    $html = '<nav class="adm-module-tabs" aria-label="Seções da classificação CDD">';
    foreach ($abas as $id => [$rotulo, $icone]) {
        $ativo = $id === $atual;
        $html .= '<a href="' . htmlspecialchars(admin_url($id)) . '" class="adm-module-tabs__item' . ($ativo ? ' is-active' : '') . '"' . ($ativo ? ' aria-current="page"' : '') . '>' . icon($icone) . '<span>' . $rotulo . '</span></a>';
    }
    return $html . '</nav>';
}

/** Aviso do modo demonstrativo. */
function cdd_aviso_modo(): string
{
    if (db_modo() === 'mysql') {
        return '';
    }
    return '<div class="adm-demo-banner" role="note">' . icon('info') . '<span><strong>Modo demonstrativo.</strong> O banco MySQL ainda não foi configurado: os livros são fictícios e as classificações ficam guardadas só nesta sessão do navegador. As pesquisas consultam as fontes reais.</span></div>';
}

/** Início comum das páginas do módulo: exige login e trata banco indisponível. */
function cdd_pagina_iniciar(string $permissao = 'cdd.ver'): array
{
    try {
        $usuario = auth_exigir($permissao);
        cdd_itens(); // carrega cedo para mostrar erro amigável se o banco falhar
        return $usuario;
    } catch (DbIndisponivel $e) {
        http_response_code(503);
        admin_pagina_erro('Banco de dados indisponível', $e->getMessage(), 'circle-alert');
        exit;
    } catch (PDOException $e) {
        error_log('[biblioteca] Erro no módulo CDD: ' . $e->getMessage());
        http_response_code(500);
        $msg = str_contains($e->getMessage(), "doesn't exist")
            ? 'As tabelas da classificação ainda não existem. Rode "php database/migrar.php" e recarregue a página.'
            : 'Ocorreu um erro ao consultar o banco de dados. Tente novamente.';
        admin_pagina_erro('Não foi possível carregar a classificação', $msg);
        exit;
    }
}

/** Configuração usada por classificacao.js (token CSRF, endereços e permissões). */
function cdd_json_script(array $extra = []): string
{
    $dados = $extra + [
        'api' => admin_base() . 'api/cdd.php',
        'csrf' => csrf_token(),
        'pode' => ['sugerir' => auth_pode('cdd.sugerir'), 'aprovar' => auth_pode('cdd.aprovar'), 'lote' => auth_pode('cdd.lote')],
        'urls' => [
            'classificar' => admin_url('classificar-livro'),
            'revisao' => admin_url('revisao-cdd'),
            'painel' => admin_url('classificacao'),
        ],
        'semResultado' => CDD_SEM_RESULTADO,
        'status' => array_map(fn ($s) => ['label' => $s['label'], 'tom' => $s['tom']], CDD_STATUS),
    ];
    return '<script type="application/json" id="cdd-data">'
        . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES)
        . '</script>';
}
