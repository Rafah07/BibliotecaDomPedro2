<?php
/**
 * Aviso de edição simultânea das obras (migração 190).
 *   POST csrf, livro_id, acao=renovar → {"ok": true, "aviso": "Esta obra está sendo editada por ..." | null, "versao": 7}
 *   POST csrf, livro_id, acao=sair    → retira a marca (formulário fechado). Também aceito via navigator.sendBeacon.
 *
 * É só um aviso: nunca bloqueia a consulta nem a gravação. A marca expira sozinha
 * (ObraEdicaoService::EXPIRA_SEGUNDOS) quando o navegador para de confirmar.
 */
require_once __DIR__ . '/../../includes/menu.php';
require_once __DIR__ . '/../../includes/acervo-fisico.php';

use Biblioteca\Services\ObraEdicaoService;

try {
    $usuario = auth_exigir('livros.editar', true);
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => true, 'aviso' => null]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
    }
    if (!csrf_valido()) {
        api_resposta(['ok' => false, 'erro' => 'A página expirou. Atualize e tente de novo.'], 419);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $livroId = (int)($_POST['livro_id'] ?? 0);
    $servico = new ObraEdicaoService(db());
    if (($_POST['acao'] ?? '') === 'sair') {
        $servico->sair($livroId, (int)$usuario['id']);
        api_resposta(['ok' => true]);
    }
    $versao = (int)db_query('SELECT versao FROM livros WHERE id = ?', [$livroId])->fetchColumn();
    if (!$servico->renovar($livroId, (int)$usuario['id'])) {
        $servico->entrar($livroId, (int)$usuario['id'], $versao);
    }
    api_resposta(['ok' => true, 'aviso' => ObraEdicaoService::aviso($servico->outros($livroId, (int)$usuario['id']), true), 'versao' => $versao]);
} catch (Throwable $e) {
    Biblioteca\Support\RegistroErros::registrar($e, 'aviso de edição simultânea');
    api_resposta(['ok' => false, 'erro' => 'Não foi possível confirmar a edição agora.'], 500);
}
