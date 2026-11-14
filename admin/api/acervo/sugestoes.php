<?php
/**
 * Sugestões da pesquisa do topo do painel (autocompletar), com os dados do acervo:
 * livros por título, autor, ISBN ou editora e códigos BDP dos exemplares.
 * GET ?q=<texto>. Só leitura; exige login. No modo demonstrativo (sem MySQL) não sugere nada.
 */
require_once __DIR__ . '/../../includes/menu.php';
require_once __DIR__ . '/../../includes/acervo-fisico.php';

try {
    auth_exigir('painel.ver', true);
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => true, 'sugestoes' => []]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    // Inventário 0: não há itens, então só os dados do acervo entram nas sugestões.
    api_resposta(['ok' => true, 'sugestoes' => inventario_repo()->sugestoes(0, $q, 8)]);
} catch (Throwable $e) {
    error_log('Sugestões do acervo: ' . $e->getMessage());
    api_resposta(['ok' => false, 'erro' => 'Não foi possível buscar sugestões agora.'], 500);
}
