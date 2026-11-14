<?php
/**
 * "Gerar código BDP" no formulário "+ Registrar exemplar" do Inventário.
 * POST csrf, livro_id → {"ok": true, "codigo": "BDP-000123", "nova": true}
 *
 * O número é calculado e reservado no SERVIDOR (CodigoBdpService::reservar), com a sequência
 * bloqueada: cliques simultâneos recebem códigos diferentes e nenhum código é repetido ou
 * reaproveitado. A reserva é de quem clicou; o código só fica definitivo quando o exemplar é salvo.
 * Exige login, a permissão exemplares.gerenciar e o token CSRF.
 */
require_once __DIR__ . '/../../includes/menu.php';
require_once __DIR__ . '/../../includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

try {
    $usuario = auth_exigir('exemplares.gerenciar', true);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
    }
    if (!csrf_valido()) {
        api_resposta(['ok' => false, 'erro' => 'A página expirou. Atualize e tente de novo.'], 419);
    }
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => false, 'erro' => 'O gerador de código BDP precisa do banco de dados MySQL.'], 409);
    }
    $livroId = (int)($_POST['livro_id'] ?? 0) ?: null;
    if ($livroId !== null && !db_query('SELECT 1 FROM livros WHERE id = ? AND arquivado_em IS NULL', [$livroId])->fetchColumn()) {
        api_resposta(['ok' => false, 'erro' => 'Obra não encontrada.'], 404);
    }
    $r = codigo_bdp_servico()->reservar((int)$usuario['id'], $livroId);
    api_resposta(['ok' => true, 'codigo' => $r['codigo'], 'nova' => $r['nova']]);
} catch (AcervoException $e) {
    api_resposta(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Gerar código BDP: ' . $e->getMessage());
    api_resposta(['ok' => false, 'erro' => 'Não foi possível gerar o código agora. Tente de novo.'], 500);
}
