<?php
/**
 * Sugestões da pesquisa da conferência do Inventário (autocompletar).
 * GET ?id=<inventário>&q=<texto>: devolve códigos BDP e livros parecidos com o que está sendo digitado.
 * Só leitura; exige login e a permissão inventario.conferir.
 */
require_once __DIR__ . '/../../includes/menu.php';
require_once __DIR__ . '/../../includes/acervo-fisico.php';

try {
    auth_exigir('inventario.conferir', true);
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => true, 'sugestoes' => []]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // não segura a sessão enquanto a equipe digita
    }
    $id = (int)($_GET['id'] ?? 0);
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    api_resposta(['ok' => true, 'sugestoes' => inventario_repo()->sugestoes($id, $q, 8)]);
} catch (Throwable $e) {
    error_log('Sugestões do inventário: ' . $e->getMessage());
    api_resposta(['ok' => false, 'erro' => 'Não foi possível buscar sugestões agora. A pesquisa pelo botão continua funcionando.'], 500);
}
