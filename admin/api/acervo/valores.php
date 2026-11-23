<?php
/**
 * Valores já cadastrados para padronizar o catálogo (migração 190): autores, editoras e categorias.
 *   GET ?campo=autor|editora|categoria&q=<texto> → {"ok": true, "valores": [{"valor": "Machado de Assis", "obras": 12}, ...]}
 *
 * Só SUGERE: o formulário continua aceitando um valor novo, e nada é alterado automaticamente.
 * Pesquisa sem diferenciar acentos e maiúsculas; no máximo 10 valores, os mais usados primeiro.
 */
require_once __DIR__ . '/../../includes/menu.php';
require_once __DIR__ . '/../../includes/acervo-fisico.php';

try {
    auth_exigir('painel.ver', true);
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => true, 'valores' => []]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $campo = (string)($_GET['campo'] ?? '');
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
    if (mb_strlen($q) < 2) {
        api_resposta(['ok' => true, 'valores' => []]);
    }
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql = match ($campo) {
        'autor' => 'SELECT a.nome AS valor, COUNT(la.livro_id) AS obras FROM autores a LEFT JOIN livro_autores la ON la.autor_id = a.id
                    WHERE a.nome LIKE ? GROUP BY a.id, a.nome ORDER BY obras DESC, a.nome LIMIT 10',
        'editora' => 'SELECT editora AS valor, COUNT(*) AS obras FROM livros WHERE arquivado_em IS NULL AND editora LIKE ?
                      GROUP BY editora ORDER BY obras DESC, editora LIMIT 10',
        'categoria' => 'SELECT c.nome AS valor, COUNT(lc.livro_id) AS obras FROM categorias c LEFT JOIN livro_categorias lc ON lc.categoria_id = c.id
                        WHERE c.arquivado_em IS NULL AND c.nome LIKE ? GROUP BY c.id, c.nome ORDER BY obras DESC, c.nome LIMIT 10',
        default => null,
    };
    if ($sql === null) {
        api_resposta(['ok' => false, 'erro' => 'Campo desconhecido.'], 422);
    }
    $valores = array_map(fn ($r) => ['valor' => $r['valor'], 'obras' => (int)$r['obras']], db_query($sql, [$like])->fetchAll());
    api_resposta(['ok' => true, 'valores' => $valores]);
} catch (Throwable $e) {
    Biblioteca\Support\RegistroErros::registrar($e, 'sugestões de padronização');
    api_resposta(['ok' => false, 'erro' => 'Não foi possível buscar sugestões agora.'], 500);
}
