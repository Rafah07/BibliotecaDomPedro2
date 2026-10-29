<?php
/**
 * Copia os dados DEMONSTRATIVOS do acervo (admin/includes/acervo-demo.php) para o MySQL,
 * para testar o painel com banco antes da importação real das planilhas.
 *
 * Uso: php database/seeds/popular-demo.php
 * Somente para um banco de TESTE: só roda com a tabela de livros vazia e fora de produção
 * (APP_ENV=production bloqueia), para nunca misturar dados fictícios com o acervo oficial.
 * Não cria nenhuma CDD: todas as classificações começam pendentes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../admin/includes/acervo-demo.php';

try {
    if (app_ambiente() === 'production') {
        throw new RuntimeException('Os dados fictícios não podem ser gravados em produção (APP_ENV=production).');
    }
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Banco não configurado. Copie .env.example para .env e preencha DB_NAME, DB_USER e DB_PASSWORD.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM livros')->fetchColumn() > 0) {
        throw new RuntimeException('A tabela de livros já tem registros. Nada foi alterado.');
    }
    db_transacao(function (PDO $pdo) {
        $ins = fn (string $sql, array $p) => $pdo->prepare($sql)->execute($p);
        foreach (demo_categorias() as $c) {
            $ins('INSERT INTO categorias (id, nome, descricao) VALUES (?, ?, ?)', [$c['id'], $c['nome'], $c['descricao']]);
        }
        foreach (demo_autores() as $a) {
            $ins('INSERT INTO autores (id, nome) VALUES (?, ?)', [$a['id'], $a['nome']]);
        }
        foreach (demo_localizacoes() as $l) {
            $ins('INSERT INTO localizacoes (id, setor, estante, prateleira) VALUES (?, ?, ?, ?)', [$l['id'], $l['setor'], $l['estante'], $l['prateleira']]);
        }
        foreach (demo_livros() as $l) {
            $isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', (string)$l['isbn'])) ?: null;
            $ins('INSERT INTO livros (id, titulo, subtitulo, isbn, editora, ano_publicacao, edicao, idioma, numero_paginas, sinopse, palavras_chave, observacoes_internas, status_validacao, origem_cadastro, criado_em)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $l['id'], $l['titulo'], $l['subtitulo'] ?: null, $isbn, $l['editora'] ?: null, $l['ano_publicacao'], $l['edicao'] ?: null, $l['idioma'],
                $l['numero_paginas'], $l['sinopse'] ?: null, $l['palavras_chave'] ?: null, $l['observacoes_internas'] ?: null, $l['status_validacao'], $l['origem_cadastro'], $l['criado_em'],
            ]);
            foreach ($l['autores'] as $ordem => $autor) {
                $ins('INSERT INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, ?)', [$l['id'], $autor, $ordem + 1]);
            }
            foreach ($l['categorias'] as $i => $cat) {
                $ins('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, ?)', [$l['id'], $cat, $i === 0 ? 1 : 0]);
            }
        }
        $maior = 0;
        foreach (demo_exemplares() as $e) {
            $ins('INSERT INTO exemplares (id, livro_id, codigo_individual, localizacao_id, estado_conservacao, situacao, criado_em, conferido_em, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $e['id'], $e['livro_id'], $e['codigo_individual'], $e['localizacao_id'], $e['estado_conservacao'], $e['situacao'], $e['criado_em'], $e['conferido_em'], $e['observacoes'] ?: null,
            ]);
            $maior = max($maior, (int)substr($e['codigo_individual'], 4));
        }
        $pdo->prepare('UPDATE exemplar_codigo_seq SET ultimo = ? WHERE id = 1')->execute([$maior]);
        // Uma linha de classificação pendente para cada livro (sem CDD).
        $pdo->exec('INSERT INTO classificacoes_cdd (livro_id) SELECT id FROM livros WHERE id NOT IN (SELECT livro_id FROM classificacoes_cdd)');
    });
    echo "Dados demonstrativos copiados: " . count(demo_livros()) . " livros, " . count(demo_exemplares()) . " exemplares.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
