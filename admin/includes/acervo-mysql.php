<?php
/**
 * Leitura do acervo no MySQL, no mesmo formato dos dados demonstrativos (acervo-demo.php).
 * Assim as páginas de Gerenciamento do Acervo mostram os livros reais — inclusive os
 * importados das planilhas — sem duplicar a lógica de apresentação.
 *
 * Somente leitura: cadastros e alterações continuam nos fluxos próprios de cada módulo.
 */
require_once __DIR__ . '/db.php';

/** Carrega todas as tabelas usadas pelas páginas do acervo uma única vez por requisição. */
function acervo_mysql_dados(): array
{
    static $dados = null;
    if ($dados !== null) {
        return $dados;
    }

    $categorias = [];
    foreach (db_query('SELECT id, nome, descricao FROM categorias WHERE arquivado_em IS NULL ORDER BY nome')->fetchAll() as $c) {
        $categorias[(int)$c['id']] = ['id' => (int)$c['id'], 'nome' => $c['nome'], 'descricao' => (string)$c['descricao']];
    }

    $autores = [];
    foreach (db_query('SELECT id, nome FROM autores ORDER BY nome')->fetchAll() as $a) {
        $autores[(int)$a['id']] = ['id' => (int)$a['id'], 'nome' => $a['nome']];
    }

    $localizacoes = [];
    foreach (db_query('SELECT id, setor, estante, prateleira FROM localizacoes ORDER BY setor, estante, prateleira')->fetchAll() as $l) {
        $localizacoes[(int)$l['id']] = ['id' => (int)$l['id'], 'setor' => $l['setor'], 'estante' => $l['estante'], 'prateleira' => $l['prateleira']];
    }

    $autoresPorLivro = [];
    foreach (db_query('SELECT livro_id, autor_id FROM livro_autores ORDER BY livro_id, ordem, autor_id')->fetchAll() as $r) {
        $autoresPorLivro[(int)$r['livro_id']][] = (int)$r['autor_id'];
    }
    $categoriasPorLivro = [];
    foreach (db_query('SELECT livro_id, categoria_id FROM livro_categorias ORDER BY livro_id, principal DESC, categoria_id')->fetchAll() as $r) {
        if (isset($categorias[(int)$r['categoria_id']])) {
            $categoriasPorLivro[(int)$r['livro_id']][] = (int)$r['categoria_id'];
        }
    }

    $livros = [];
    $sql = 'SELECT id, titulo, subtitulo, isbn, editora, ano_publicacao, edicao, idioma, numero_paginas, sinopse, palavras_chave,
                   assunto, observacoes_internas, capa, status_validacao, origem_cadastro, criado_em
            FROM livros WHERE arquivado_em IS NULL ORDER BY titulo, id';
    foreach (db_query($sql)->fetchAll() as $l) {
        $id = (int)$l['id'];
        $livros[$id] = [
            'id' => $id,
            'titulo' => $l['titulo'],
            'subtitulo' => (string)$l['subtitulo'],
            'autores' => $autoresPorLivro[$id] ?? [],
            'categorias' => $categoriasPorLivro[$id] ?? [],
            'isbn' => (string)$l['isbn'],
            'editora' => (string)$l['editora'],
            'ano_publicacao' => $l['ano_publicacao'] !== null ? (int)$l['ano_publicacao'] : null,
            'edicao' => (string)$l['edicao'],
            'idioma' => $l['idioma'],
            'numero_paginas' => $l['numero_paginas'] !== null ? (int)$l['numero_paginas'] : null,
            'status_validacao' => $l['status_validacao'],
            'origem_cadastro' => $l['origem_cadastro'],
            'criado_em' => substr($l['criado_em'], 0, 10),
            'cor' => acervo_mysql_cor($id),
            'sinopse' => (string)$l['sinopse'],
            'palavras_chave' => (string)$l['palavras_chave'],
            'assunto' => (string)$l['assunto'],
            'observacoes_internas' => (string)$l['observacoes_internas'],
            'capa' => $l['capa'] ?: null,
            'arquivado' => false,
        ];
    }

    $exemplares = [];
    $sql = 'SELECT id, livro_id, codigo_individual, localizacao_id, estado_conservacao, situacao, criado_em, conferido_em, observacoes
            FROM exemplares ORDER BY id';
    foreach (db_query($sql)->fetchAll() as $e) {
        if (!isset($livros[(int)$e['livro_id']])) {
            continue;
        }
        $exemplares[(int)$e['id']] = [
            'id' => (int)$e['id'],
            'livro_id' => (int)$e['livro_id'],
            'codigo_individual' => $e['codigo_individual'],
            'localizacao_id' => $e['localizacao_id'] !== null ? (int)$e['localizacao_id'] : null,
            'estado_conservacao' => $e['estado_conservacao'],
            'situacao' => $e['situacao'],
            'criado_em' => substr($e['criado_em'], 0, 10),
            'conferido_em' => $e['conferido_em'] !== null ? substr($e['conferido_em'], 0, 10) : null,
            'observacoes' => (string)$e['observacoes'],
        ];
    }

    $movimentacoes = [];
    $sql = 'SELECT m.exemplar_id, m.tipo, m.descricao, m.criado_em, a.nome AS responsavel
            FROM movimentacoes m LEFT JOIN administradores a ON a.id = m.responsavel_id
            ORDER BY m.criado_em DESC, m.id DESC';
    foreach (db_query($sql)->fetchAll() as $m) {
        $movimentacoes[] = [
            'exemplar_id' => (int)$m['exemplar_id'],
            'tipo' => $m['tipo'],
            'data' => substr($m['criado_em'], 0, 10),
            'descricao' => (string)$m['descricao'],
            'responsavel' => $m['responsavel'] ?? 'Sistema',
        ];
    }

    $conferencias = [];
    $sql = 'SELECT c.livro_id, c.realizado_em, c.esperado, c.encontrados, c.observacao, a.nome AS responsavel
            FROM inventario_conferencias c LEFT JOIN administradores a ON a.id = c.responsavel_id';
    foreach (db_query($sql)->fetchAll() as $c) {
        $conferencias[] = [
            'livro_id' => (int)$c['livro_id'],
            'data' => substr($c['realizado_em'], 0, 10),
            'responsavel' => $c['responsavel'] ?? 'Equipe do inventário',
            'esperado' => (int)$c['esperado'],
            'encontrados' => (int)$c['encontrados'],
            'observacao' => (string)$c['observacao'],
        ];
    }

    return $dados = [
        'categorias' => $categorias,
        'autores' => $autores,
        'localizacoes' => $localizacoes,
        'livros' => $livros,
        'exemplares' => $exemplares,
        'registros_planilha' => acervo_mysql_registros_planilha(),
        'movimentacoes' => $movimentacoes,
        'conferencias' => $conferencias,
    ];
}

/**
 * Dados das planilhas antigas por livro (tabela registros_legados, criada pela importação).
 * Um livro pode ter várias linhas: a quantidade é somada e os demais dados vêm da linha mais recente.
 * A quantidade é só referência — não vira exemplar.
 */
function acervo_mysql_registros_planilha(): array
{
    $existe = (bool)db_query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'registros_legados'")->fetchColumn();
    if (!$existe) {
        return [];
    }
    $sql = 'SELECT r.livro_id, r.arquivo, r.linha, r.importado_em, r.cdd_original, r.codigo_autor_antigo, r.localizacao_antiga,
                   r.assunto_original, r.importacao_id, t.quantidade_informada, t.total_linhas
            FROM registros_legados r
            JOIN (SELECT livro_id, MAX(id) AS ultimo, SUM(quantidade_informada) AS quantidade_informada, COUNT(*) AS total_linhas
                  FROM registros_legados WHERE livro_id IS NOT NULL GROUP BY livro_id) t ON t.ultimo = r.id';
    $lista = [];
    foreach (db_query($sql)->fetchAll() as $r) {
        $lista[] = [
            'livro_id' => (int)$r['livro_id'],
            'arquivo' => $r['arquivo'],
            'linha' => (int)$r['linha'],
            'importado_em' => substr($r['importado_em'], 0, 10),
            'quantidade_informada' => $r['quantidade_informada'] !== null ? (int)$r['quantidade_informada'] : null,
            'total_linhas' => (int)$r['total_linhas'],
            'importacao_id' => $r['importacao_id'] !== null ? (int)$r['importacao_id'] : null,
            'cdd_original' => $r['cdd_original'],
            'codigo_autor_antigo' => $r['codigo_autor_antigo'],
            'localizacao_antiga' => $r['localizacao_antiga'],
            'assunto_original' => $r['assunto_original'],
        ];
    }
    return $lista;
}

function acervo_mysql_cor(int $id): string
{
    $cores = ['#143477', '#6b3a2e', '#7a5c1e', '#a0522d', '#1f6fb2', '#3d3d3d', '#7b1e3a', '#b5651d', '#0f2a4a', '#8c6d3f', '#0e7c7b', '#5b3f8c'];
    return $cores[$id % count($cores)];
}
