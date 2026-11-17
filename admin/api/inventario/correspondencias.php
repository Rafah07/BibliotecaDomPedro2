<?php
/**
 * Obras parecidas com a nova obra que está sendo digitada em "+ Cadastrar nova obra" (conferência).
 * GET ?titulo=&autores=&isbn=&editora=&edicao=: devolve até 6 obras (mesmo ISBN, mesmo título e autor,
 * título parecido), com o motivo e se a editora/edição coincidem. Só leitura: nada é gravado nem unido.
 * Exige login e a permissão exemplares.gerenciar (a mesma do formulário).
 */
require_once __DIR__ . '/../../includes/menu.php';
require_once __DIR__ . '/../../includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

try {
    auth_exigir('exemplares.gerenciar', true);
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => true, 'obras' => []]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // não segura a sessão enquanto a equipe digita
    }
    $campos = [];
    foreach (['titulo' => 255, 'autores' => 1500, 'isbn' => 100, 'editora' => 150, 'edicao' => 60] as $c => $max) {
        $campos[$c] = mb_substr(trim((string)($_GET[$c] ?? '')), 0, $max);
    }
    try {
        $d = conferencia_cadastro()->dados($campos);
    } catch (AcervoException) {
        api_resposta(['ok' => true, 'obras' => []]);
    }
    $obras = array_map(fn ($o) => [
        'id' => (int)$o['id'], 'titulo' => $o['titulo'], 'autores' => $o['autores'], 'isbn' => $o['isbn'] ?: $o['isbn_original'],
        'editora' => $o['editora'], 'ano' => $o['ano_publicacao'] !== null ? (int)$o['ano_publicacao'] : null, 'edicao' => $o['edicao'],
        'exemplares' => (int)$o['exemplares'], 'motivo' => $o['motivo'], 'detalhes' => $o['detalhes'],
    ], conferencia_cadastro()->correspondencias($d, 6));
    api_resposta(['ok' => true, 'obras' => $obras]);
} catch (Throwable $e) {
    error_log('Obras parecidas (nova obra): ' . $e->getMessage());
    api_resposta(['ok' => false, 'erro' => 'Não foi possível conferir as obras parecidas agora. Ao salvar, o sistema confere de novo.'], 500);
}
