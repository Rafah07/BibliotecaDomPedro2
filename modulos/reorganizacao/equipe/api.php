<?php
/**
 * API JSON da Central de Equipes. Toda chamada exige a sessão temporária ativa deste navegador e o
 * token CSRF (cabeçalho X-CSRF-Token). As permissões (lote atribuído, equipe, etapa aberta) são
 * conferidas no servidor pelos serviços do módulo.
 */
require __DIR__ . '/../includes/equipe.php';

use Biblioteca\Reorganizacao\Levantamento;
use Biblioteca\Reorganizacao\RegistroConflitoException;
use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Reorganizacao\SessaoEncerradaException;
use Biblioteca\Services\AcervoException;

reorg_equipe_cabecalhos();

function reorg_json(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!reorg_modulo_ativo()) {
    reorg_json(['ok' => false, 'erro' => 'Indisponível.'], 404);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reorg_json(['ok' => false, 'erro' => 'Use POST.'], 405);
}
reorg_equipe_sessao_php();
if (!reorg_equipe_csrf_valido()) {
    reorg_json(['ok' => false, 'erro' => 'A página ficou aberta por muito tempo. Recarregue e tente de novo.'], 419);
}
$sessao = reorg_equipe_sessao();
if (!$sessao) {
    reorg_json(['ok' => false, 'encerrada' => true, 'erro' => 'Sua sessão foi encerrada. Entre novamente para continuar.'], 401);
}

$r = reorg();
$lev = $r->levantamento();
$et = $r->etapas();
$p = $_POST;
$int = fn (string $c) => (int)($p[$c] ?? 0);
$sim = fn (string $c) => in_array((string)($p[$c] ?? ''), ['1', 'true', 'on'], true);

try {
    switch ((string)($p['acao'] ?? '')) {
        case 'sinal':
            $estado = $r->sinal($sessao, $sim('trabalhando'), $int('registro') ?: null);
            reorg_json(['ok' => true, 'agora' => date('Y-m-d H:i:s')] + $estado);
        case 'notificacoes_lidas':
            $r->marcarNotificacoesLidas($sessao, (array)($p['ids'] ?? []));
            reorg_json(['ok' => true]);
        case 'novo':
            $reg = $lev->novoRegistro($sessao, $int('lote'));
            reorg_json(['ok' => true, 'id' => (int)$reg['id'], 'url' => reorg_url('equipe/?lote=' . $reg['lote_id'] . '&reg=' . $reg['id'])]);
        case 'salvar':
            $x = $lev->salvar($sessao, $int('id'), $p, $int('versao'));
            reorg_json(['ok' => true] + $x);
        case 'liberar':
            $lev->liberar($int('id'), $sessao);
            reorg_json(['ok' => true]);
        case 'pesquisar':
            if ($sessao['equipe'] !== 'grupo1') {
                throw new AcervoException('A pesquisa de obras é do Grupo 1.');
            }
            reorg_json(['ok' => true, 'obras' => array_map(fn ($o) => array_intersect_key($o, array_flip(['id', 'titulo', 'subtitulo', 'autores', 'isbn', 'editora', 'ano_publicacao', 'edicao', 'idioma', 'assunto', 'categoria_id', 'cdd', 'exemplares'])),
                $lev->pesquisarObras((string)($p['q'] ?? '')))]);
        case 'sugerir':
            $lev->sugerirObra($int('id'), $int('livro_id') ?: null, $sessao);
            reorg_json(['ok' => true]);
        case 'descartar':
            $lev->descartar($int('id'), $sessao, null, (string)($p['motivo'] ?? ''));
            reorg_json(['ok' => true]);
        case 'previa_g2':
            reorg_json(['ok' => true] + $lev->previaEncaminhamento(reorg_equipe_lote($r, $sessao, $int('lote'), 'grupo1')));
        case 'encaminhar_g2':
            reorg_json(['ok' => true] + $lev->encaminhar($int('lote'), $sessao));
        case 'reservar':
            $et->reservar($sessao, $int('id'), (string)$sessao['equipe']);
            reorg_json(['ok' => true]);
        case 'liberar_reserva':
            $et->liberarReserva($sessao, $int('id'));
            reorg_json(['ok' => true]);
        case 'carimbar':
            reorg_json(['ok' => true, 'codigo' => $et->marcarCarimbado($sessao, $int('id'))]);
        case 'previa_g4':
            reorg_json(['ok' => true] + $et->previaEncaminhamentoG4(reorg_equipe_lote($r, $sessao, $int('lote'), 'grupo3')));
        case 'encaminhar_g4':
            reorg_json(['ok' => true] + $et->encaminharG4($int('lote'), $sessao));
        case 'conferir':
            $x = $et->conferirCodigo($sessao, $int('lote'), (string)($p['codigo'] ?? ''));
            if (isset($x['exemplar'])) {
                $x['exemplar']['conservacao'] = Reorganizacao::CONSERVACAO[$x['exemplar']['estado_conservacao']] ?? '';
            }
            reorg_json(['ok' => true] + $x);
        case 'confirmar':
            reorg_json(['ok' => true] + $et->confirmarConferencia($sessao, $int('id'), $sim('codigo_ok'), $sim('info_ok'), $sim('organizado'), $int('localizacao_id') ?: null));
        case 'organizar':
            reorg_json(['ok' => true, 'codigo' => $et->organizar($sessao, $int('id'), $int('localizacao_id') ?: null)]);
        case 'problema':
            $grupo = (string)$sessao['equipe'];
            $id = $r->registrarPendencia($int('lote'), (string)($p['tipo'] ?? ''), (string)($p['descricao'] ?? ''), $grupo, $sessao, null,
                ['registro_id' => $int('registro'), 'registro_exemplar_id' => $int('exemplar')]);
            reorg_json(['ok' => true, 'id' => $id]);
        case 'solicitar':
            $id = $r->solicitar($sessao, (string)($p['tipo'] ?? ''), $p);
            reorg_json(['ok' => true, 'id' => $id]);
        case 'pausar':
            reorg_json(['ok' => true, 'resumo' => $r->pausarParticipante($sessao)]);
        case 'retomar':
            $r->retomarParticipante($sessao);
            reorg_json(['ok' => true]);
        case 'sair':
            $r->sair($sessao);
            unset($_SESSION['reorg_token']);
            session_regenerate_id(true);
            reorg_json(['ok' => true, 'url' => reorg_url('equipe/')]);
    }
    reorg_json(['ok' => false, 'erro' => 'Ação inválida.'], 400);
} catch (SessaoEncerradaException $e) {
    unset($_SESSION['reorg_token']);
    reorg_json(['ok' => false, 'encerrada' => true, 'erro' => $e->getMessage()], 401);
} catch (RegistroConflitoException $e) {
    reorg_json(['ok' => false, 'conflito' => true, 'erro' => $e->getMessage(),
        'atual' => array_intersect_key($e->atual, array_flip([...Levantamento::CAMPOS, 'versao', 'estados', 'atualizado_nome', 'atualizado_em']))], 409);
} catch (AcervoException $e) {
    reorg_json(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[reorganizacao] API da equipe: ' . $e->getMessage());
    reorg_json(['ok' => false, 'erro' => 'Erro ao salvar — tente novamente.'], 500);
}

/** Lote atribuído à sessão nesta equipe (para as prévias, que só leem). */
function reorg_equipe_lote(Reorganizacao $r, array $sessao, int $loteId, string $equipe): int
{
    foreach ($r->lotesDaSessao((int)$sessao['id']) as $l) {
        if ((int)$l['id'] === $loteId && $l['equipe'] === $equipe) {
            return $loteId;
        }
    }
    throw new AcervoException('Este lote não está atribuído a você nesta equipe.');
}
