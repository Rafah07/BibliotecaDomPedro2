<?php
/**
 * API JSON do módulo de classificação CDD.
 * Todas as chamadas são POST, exigem login e token CSRF (cabeçalho X-CSRF-Token).
 */
require_once __DIR__ . '/../includes/menu.php';
require_once __DIR__ . '/../includes/cdd.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
}

try {
    $usuario = auth_exigir('cdd.ver', true);
    if (!csrf_valido()) {
        api_resposta(['ok' => false, 'erro' => 'A sessão de segurança expirou. Recarregue a página e tente novamente.'], 419);
    }
    // Libera a sessão para outras abas enquanto a pesquisa externa acontece
    // (no modo demonstrativo a sessão guarda os dados e precisa continuar aberta).
    if (db_modo() === 'mysql' && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $acao = (string)($_POST['acao'] ?? '');
    $livroId = (int)($_POST['livro_id'] ?? 0);
    $exigir = function (string $permissao) {
        if (!auth_pode($permissao)) {
            throw new CddErro('Você não tem permissão para esta ação.', [], 403);
        }
    };
    $lista = fn (string $campo) => array_values(array_filter(array_map('strval', (array)($_POST[$campo] ?? [])), 'strlen'));

    switch ($acao) {
        case 'validar':
            api_resposta(['ok' => true, 'validacao' => cdd_validar((string)($_POST['cdd'] ?? ''))]);

        case 'executar':
            $tipo = (string)($_POST['tipo'] ?? '');
            $linha = cdd_executar($tipo, $livroId, $_POST, $usuario);
            $proximo = !empty($_POST['proximo']) ? cdd_proximo_livro($livroId) : null;
            api_resposta([
                'ok' => true,
                'mensagem' => cdd_mensagem_acao($tipo),
                'classificacao' => cdd_linha_publica($linha),
                'proximo_url' => $proximo ? admin_url('classificar-livro') . '?id=' . $proximo : null,
            ]);

        case 'pesquisar':
            $exigir('cdd.sugerir');
            $termos = isset($_POST['termos']) && is_array($_POST['termos']) ? $_POST['termos'] : null;
            $r = cdd_pesquisar_livro($livroId, $usuario, $termos, $lista('fontes') ?: null);
            $item = cdd_item($livroId);
            api_resposta(['ok' => true, 'mensagem' => $r['mensagem'], 'resultado' => $r['resultado'], 'pesquisa' => cdd_pesquisa_publica($r['pesquisa']), 'classificacao' => cdd_linha_publica($item['class'])]);

        case 'pesquisa_livre':
            // Pesquisa sem livro vinculado: só mostra o que as fontes informam, nada é gravado.
            $termos = cdd_termos_limpos(is_array($_POST['termos'] ?? null) ? $_POST['termos'] : []);
            if (implode('', $termos) === '') {
                throw new CddErro('Preencha ao menos um campo da pesquisa.');
            }
            $busca = cdd_pesquisar_fontes($termos, $lista('fontes') ?: null);
            $registros = [];
            foreach ($busca['registros'] as $reg) {
                $compat = cdd_compatibilidade(['isbn' => $termos['isbn'], 'titulo' => $termos['titulo'], 'autor' => $termos['autor'], 'ano' => $termos['ano'], 'editora' => $termos['editora']], $reg);
                $registros[] = [
                    'fonte_nome' => $reg['fonte_nome'], 'registro_url' => $reg['registro_url'], 'titulo' => $reg['titulo'], 'autor' => $reg['autor'],
                    'editora' => $reg['editora'], 'ano' => $reg['ano'], 'edicao' => $reg['edicao'], 'isbn' => implode(', ', array_slice($reg['isbns'], 0, 4)),
                    'cdds' => array_values(array_filter(array_map(fn ($c) => $c['numero'] !== '' ? ['numero' => $c['numero'], 'ddc_edicao' => $c['ddc_edicao'], 'abreviada' => $c['abreviada']] : null, $reg['cdds']))),
                    'lcc' => $reg['lcc'], 'compatibilidade' => $compat['nivel'], 'motivos' => $compat['motivos'],
                ];
            }
            $temCdd = array_filter($registros, fn ($r) => $r['cdds']);
            api_resposta(['ok' => true, 'fontes' => $busca['fontes'], 'registros' => $registros, 'mensagem' => $temCdd ? null : CDD_SEM_RESULTADO]);

        case 'lote_criar':
            $exigir('cdd.lote');
            $lote = cdd_lote_criar($lista('livros'), $lista('fontes'), $usuario);
            api_resposta(['ok' => true, 'lote' => cdd_lote_publico($lote)]);

        case 'lote_processar':
            $exigir('cdd.lote');
            api_resposta(['ok' => true, 'lote' => cdd_lote_publico(cdd_lote_processar((int)($_POST['lote_id'] ?? 0), $usuario))]);

        case 'lote_status':
            $lote = cdd_lote((int)($_POST['lote_id'] ?? 0));
            if (!$lote) {
                throw new CddErro('Lote não encontrado.', [], 404);
            }
            api_resposta(['ok' => true, 'lote' => cdd_lote_publico($lote)]);

        case 'lote_repetir':
            $exigir('cdd.lote');
            cdd_lote_repetir_falhas((int)($_POST['lote_id'] ?? 0));
            api_resposta(['ok' => true, 'lote' => cdd_lote_publico(cdd_lote((int)($_POST['lote_id'] ?? 0)))]);

        case 'lote_cancelar':
            $exigir('cdd.lote');
            cdd_lote_cancelar((int)($_POST['lote_id'] ?? 0));
            api_resposta(['ok' => true, 'lote' => cdd_lote_publico(cdd_lote((int)($_POST['lote_id'] ?? 0)))]);

        case 'enviar_revisao_lote':
            // Encaminha várias sugestões para aprovação. Cada livro é gravado e auditado individualmente.
            $exigir('cdd.sugerir');
            $enviados = 0;
            $ignorados = [];
            foreach (array_slice(array_map('intval', $lista('livros')), 0, 500) as $id) {
                $item = cdd_item($id);
                if (!$item || $item['class']['status'] !== 'sugerida' || !$item['class']['cdd_proposta'] || !$item['class']['fonte_nome']) {
                    $ignorados[] = $id;
                    continue;
                }
                cdd_executar('enviar_revisao', $id, ['observacao' => 'Encaminhado a partir da revisão em lote.', 'versao' => $item['class']['versao']], $usuario);
                $enviados++;
            }
            api_resposta(['ok' => true, 'enviados' => $enviados, 'ignorados' => count($ignorados),
                'mensagem' => $enviados . ' livro(s) enviados para aprovação' . ($ignorados ? '; ' . count($ignorados) . ' ignorado(s) por não terem sugestão com fonte.' : '.')]);

        default:
            throw new CddErro('Ação desconhecida.', [], 400);
    }
} catch (CddErro $e) {
    api_resposta(['ok' => false, 'erro' => $e->getMessage(), 'campos' => $e->campos], $e->status);
} catch (DbIndisponivel $e) {
    api_resposta(['ok' => false, 'erro' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('[biblioteca] Erro na API CDD: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    api_resposta(['ok' => false, 'erro' => 'Não foi possível concluir a operação. Nenhuma alteração foi gravada.'], 500);
}

function cdd_mensagem_acao(string $tipo): string
{
    return match ($tipo) {
        'sugerir' => 'Sugestão registrada. Ela ainda precisa ser enviada para revisão.',
        'rascunho' => 'Rascunho salvo.',
        'enviar_revisao' => 'Classificação enviada para revisão da bibliotecária.',
        'aprovar' => 'CDD aprovada e registrada no histórico.',
        'corrigir' => 'CDD corrigida, aprovada e registrada no histórico.',
        'rejeitar' => 'Sugestão rejeitada. O livro voltou para a fila de pesquisa.',
        'nova_pesquisa' => 'Nova pesquisa solicitada.',
        'observacao' => 'Observação registrada.',
        default => 'Alteração registrada.',
    };
}

/** Campos da classificação devolvidos ao navegador. */
function cdd_linha_publica(array $c): array
{
    return [
        'status' => $c['status'],
        'status_label' => CDD_STATUS[$c['status']]['label'] ?? $c['status'],
        'status_tom' => CDD_STATUS[$c['status']]['tom'] ?? 'muted',
        'cdd_proposta' => $c['cdd_proposta'],
        'cdd_aprovada' => $c['cdd_aprovada'],
        'fonte_nome' => $c['fonte_nome'],
        'rascunho' => (bool)$c['rascunho'],
        'precisa_revisao' => (bool)$c['precisa_revisao'],
        'versao' => (int)$c['versao'],
    ];
}

function cdd_pesquisa_publica(array $p): array
{
    return [
        'id' => $p['id'],
        'resultado' => $p['resultado'],
        'mensagem' => $p['mensagem'],
        'fontes' => $p['fontes'],
        'consultas' => array_map(fn ($c) => [
            'id' => $c['id'], 'fonte_nome' => $c['fonte_nome'], 'registro_url' => $c['registro_url'], 'titulo' => $c['titulo'], 'autor' => $c['autor'],
            'editora' => $c['editora'], 'ano' => $c['ano'], 'edicao' => $c['edicao'], 'isbn' => $c['isbn'], 'cdd' => $c['cdd'], 'ddc_edicao' => $c['ddc_edicao'],
            'lcc' => $c['lcc'], 'compatibilidade' => $c['compatibilidade'], 'compat_label' => CDD_COMPATIBILIDADE[$c['compatibilidade']]['label'],
            'compat_tom' => CDD_COMPATIBILIDADE[$c['compatibilidade']]['tom'], 'motivos' => $c['motivos'],
        ], $p['consultas'] ?? []),
    ];
}

function cdd_lote_publico(?array $lote): ?array
{
    if (!$lote) {
        return null;
    }
    return [
        'id' => (int)$lote['id'], 'nome' => $lote['nome'], 'status' => $lote['status'], 'total' => (int)$lote['total'],
        'processados' => $lote['processados'], 'percentual' => $lote['percentual'],
        'grupos' => array_map('count', $lote['grupos']), 'coincidentes' => $lote['coincidentes'],
        'itens' => array_map(fn ($it) => [
            'livro_id' => $it['livro_id'], 'titulo' => $it['titulo'], 'autor' => $it['autor'], 'status' => $it['status'], 'resultado' => $it['resultado'],
            'mensagem' => $it['mensagem'], 'status_cdd' => $it['status_cdd'], 'status_cdd_label' => CDD_STATUS[$it['status_cdd']]['label'] ?? '', 'cdd_proposta' => $it['cdd_proposta'],
        ], $lote['itens']),
    ];
}
