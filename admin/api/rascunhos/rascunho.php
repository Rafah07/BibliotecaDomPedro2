<?php
/**
 * Rascunhos dos formulários interrompidos (migração 160).
 *   GET  formulario, referencia               → {"ok": true, "rascunho": {campos, atualizado_em, lote_numero, lote_status} | null}
 *   POST csrf, formulario, referencia, campos → salva (campos[nome] = valor). {"ok": true, "salvo_em": "..."}
 *   POST csrf, formulario, referencia, descartar=1 → apaga o rascunho
 *
 * O rascunho é só de quem está logado (o id vem da sessão). Nunca guarda código BDP nem tokens
 * (RascunhoService::limpar) e nunca cria exemplar nem ocupa vaga do lote.
 */
require_once __DIR__ . '/../../includes/menu.php';
require_once __DIR__ . '/../../includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

try {
    $usuario = auth_exigir('painel.ver', true);
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => false, 'erro' => 'Os rascunhos precisam do banco de dados MySQL.'], 409);
    }
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $origem = $metodo === 'POST' ? $_POST : $_GET;
    $formulario = (string)($origem['formulario'] ?? '');
    $referencia = (string)($origem['referencia'] ?? '');
    if ($metodo === 'GET') {
        $r = rascunhos_servico()->obter((int)$usuario['id'], $formulario, $referencia);
        api_resposta(['ok' => true, 'rascunho' => $r ? [
            'campos' => $r['campos'], 'atualizado_em' => admin_data_hora($r['atualizado_em']),
            'lote' => $r['lote_numero'] !== null ? 'Lote ' . str_pad((string)$r['lote_numero'], 3, '0', STR_PAD_LEFT) : null,
            'lote_aberto' => $r['lote_status'] === null || $r['lote_status'] === 'aberto',
        ] : null]);
    }
    if ($metodo !== 'POST') {
        api_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
    }
    if (!csrf_valido()) {
        api_resposta(['ok' => false, 'erro' => 'A página expirou. Atualize e tente de novo.'], 419);
    }
    if (!empty($_POST['descartar'])) {
        rascunhos_servico()->descartar((int)$usuario['id'], $formulario, $referencia);
        api_resposta(['ok' => true]);
    }
    $campos = is_array($_POST['campos'] ?? null) ? $_POST['campos'] : [];
    $quando = rascunhos_servico()->salvar((int)$usuario['id'], $formulario, $referencia, $campos);
    api_resposta(['ok' => true, 'salvo_em' => $quando !== '' ? admin_data_hora($quando) : null]);
} catch (AcervoException $e) {
    api_resposta(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Rascunho: ' . get_class($e) . ' ' . $e->getMessage());
    api_resposta(['ok' => false, 'erro' => 'Não foi possível salvar o rascunho agora.'], 500);
}
