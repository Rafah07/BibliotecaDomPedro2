<?php
/**
 * Envio e inspeção das planilhas (etapas 1 e 2 da importação).
 *
 * POST acao=enviar             arquivo (.xlsx/.csv) → guarda na área privada e devolve abas e colunas
 * POST acao=inspecionar        id, aba, linha_cabecalho, mapeamento_id? → colunas, exemplos e mapeamento sugerido
 * POST acao=salvar_mapeamento  nome, mapeamento (JSON) → salva para as próximas importações
 * Nada é gravado no acervo aqui.
 */
require_once __DIR__ . '/../../includes/importacao.php';

$usuario = importacao_api('importacao.revisar', true);
$acao = is_string($_POST['acao'] ?? null) ? $_POST['acao'] : '';

try {
    match ($acao) {
        'enviar' => upload_enviar($usuario),
        'inspecionar' => upload_inspecionar(),
        'salvar_mapeamento' => upload_salvar_mapeamento($usuario),
        default => api_resposta(['ok' => false, 'erro' => 'Ação desconhecida.'], 400),
    };
} catch (Throwable $e) {
    importacao_api_falha($e);
}

function upload_enviar(array $usuario): never
{
    $arquivo = $_FILES['arquivo'] ?? null;
    if (!is_array($arquivo) || is_array($arquivo['error'] ?? null)) {
        api_resposta(['ok' => false, 'erro' => 'Selecione um arquivo.'], 400);
    }
    $mensagensUpload = [
        UPLOAD_ERR_INI_SIZE => 'O arquivo é maior do que o servidor permite. Divida a planilha em partes menores.',
        UPLOAD_ERR_FORM_SIZE => 'O arquivo é maior do que o permitido.',
        UPLOAD_ERR_PARTIAL => 'O envio foi interrompido. Verifique a conexão e tente novamente.',
        UPLOAD_ERR_NO_FILE => 'Selecione um arquivo.',
    ];
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        api_resposta(['ok' => false, 'erro' => $mensagensUpload[$arquivo['error']] ?? 'Não foi possível receber o arquivo.'], 400);
    }
    if (!is_uploaded_file($arquivo['tmp_name'])) {
        api_resposta(['ok' => false, 'erro' => 'Arquivo inválido.'], 400);
    }
    $nomeOriginal = mb_substr(basename(str_replace('\\', '/', (string)$arquivo['name'])), 0, 255);
    $leitor = importacao_leitor();
    $tipo = $leitor->validarArquivo($arquivo['tmp_name'], $nomeOriginal);

    $hash = hash_file('sha256', $arquivo['tmp_name']);
    $repo = importacao_repo();
    if ($existente = $repo->buscarPorArquivo($hash)) {
        api_resposta([
            'ok' => false,
            'erro' => 'Este arquivo já foi enviado em ' . importacao_data($existente['criado_em']) . ' (importação nº ' . $existente['id'] . ', ' . mb_strtolower(IMPORTACAO_STATUS[$existente['status']]['label']) . '). Abra a importação existente em vez de repetir o envio.',
            'importacao_existente' => (int)$existente['id'],
            'url' => admin_url('detalhes-importacao') . '?id=' . $existente['id'],
        ], 409);
    }

    // Nome aleatório, sem extensão executável, em pasta fora de admin/.
    $armazenado = bin2hex(random_bytes(16)) . '.' . $tipo . '.upload';
    $destino = importacao_pasta() . DIRECTORY_SEPARATOR . $armazenado;
    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Não foi possível guardar o arquivo enviado.');
    }
    @chmod($destino, 0640);

    try {
        $abas = $leitor->abas($destino, $tipo);
        $abaInicial = null;
        foreach ($abas as $aba) {
            if ($aba['linhas'] > 1) {
                $abaInicial = $aba['nome'];
                break;
            }
        }
        $abaInicial ??= $abas[0]['nome'] ?? 'CSV';
        $id = $repo->criar([
            'nome_arquivo' => $nomeOriginal,
            'identificador_arquivo' => $hash,
            'arquivo_armazenado' => $armazenado,
            'tipo_arquivo' => $tipo,
            'tamanho_bytes' => (int)filesize($destino),
            'abas' => $abas,
            'aba' => $abaInicial,
            'usuario_id' => $usuario['id'],
            'total_linhas' => 0,
        ]);
    } catch (PDOException $e) {
        @unlink($destino);
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            api_resposta(['ok' => false, 'erro' => 'Este arquivo já foi enviado por outra pessoa agora há pouco.'], 409);
        }
        throw $e;
    } catch (Throwable $e) {
        @unlink($destino);
        if ($e instanceof \PhpOffice\PhpSpreadsheet\Exception || $e instanceof \PhpOffice\PhpSpreadsheet\Reader\Exception) {
            api_resposta(['ok' => false, 'erro' => 'Não foi possível ler a planilha. Abra-a no Excel, salve novamente e tente outra vez.'], 422);
        }
        throw $e;
    }
    registrar_acao('importacao.enviar', 'importacao', $id, ['arquivo' => $nomeOriginal, 'tamanho' => (int)filesize($destino), 'tipo' => $tipo]);

    $imp = $repo->buscar($id);
    api_resposta(['ok' => true, 'importacao' => upload_resumo($imp)] + upload_colunas($imp, $abaInicial, 1, null));
}

function upload_inspecionar(): never
{
    $repo = importacao_repo();
    $imp = $repo->buscar(importacao_id_param());
    if (!$imp) {
        api_resposta(['ok' => false, 'erro' => 'Importação não encontrada.'], 404);
    }
    if (!in_array($imp['status'], ['enviada', 'em_analise', 'em_revisao'], true)) {
        api_resposta(['ok' => false, 'erro' => 'Esta importação não pode mais ter o mapeamento alterado.'], 409);
    }
    $aba = is_string($_POST['aba'] ?? null) ? $_POST['aba'] : (string)$imp['aba'];
    if (!in_array($aba, array_column($imp['abas_json'] ?? [], 'nome'), true)) {
        api_resposta(['ok' => false, 'erro' => 'A aba selecionada não existe nesta planilha.'], 422);
    }
    $linha = max(1, min(50, (int)($_POST['linha_cabecalho'] ?? 1)));
    $mapeamentoId = (int)($_POST['mapeamento_id'] ?? 0);
    $salvo = $mapeamentoId ? $repo->mapeamentoSalvo($mapeamentoId) : null;
    api_resposta(['ok' => true, 'importacao' => upload_resumo($imp)] + upload_colunas($imp, $aba, $linha, $salvo));
}

function upload_salvar_mapeamento(array $usuario): never
{
    $nome = trim(is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '');
    $mapeamento = json_decode(is_string($_POST['mapeamento'] ?? null) ? $_POST['mapeamento'] : '', true);
    if ($nome === '' || mb_strlen($nome) > 120) {
        api_resposta(['ok' => false, 'erro' => 'Dê um nome ao mapeamento (até 120 caracteres).'], 422);
    }
    if (!is_array($mapeamento) || !$mapeamento) {
        api_resposta(['ok' => false, 'erro' => 'Mapeamento inválido.'], 422);
    }
    foreach ($mapeamento as $campo) {
        if (!is_string($campo) || ($campo !== 'ignorar' && !isset(\Biblioteca\Services\ImportValidationService::CAMPOS[$campo]))) {
            api_resposta(['ok' => false, 'erro' => 'Mapeamento inválido.'], 422);
        }
    }
    $id = importacao_repo()->salvarMapeamento($nome, $mapeamento, $usuario['id']);
    registrar_acao('importacao.salvar_mapeamento', 'importacao_mapeamento', $id, ['nome' => $nome]);
    api_resposta(['ok' => true, 'id' => $id, 'mapeamentos' => importacao_repo()->mapeamentosSalvos()]);
}

function upload_resumo(array $imp): array
{
    return [
        'id' => (int)$imp['id'],
        'nome' => $imp['nome_arquivo'],
        'tamanho' => importacao_tamanho((int)$imp['tamanho_bytes']),
        'tipo' => $imp['tipo_arquivo'],
        'abas' => $imp['abas_json'] ?? [],
        'aba' => $imp['aba'],
        'status' => $imp['status'],
    ];
}

/** Colunas da aba com exemplos de valores e o campo sugerido para cada uma. */
function upload_colunas(array $imp, string $aba, int $linhaCabecalho, ?array $salvo): array
{
    $leitor = importacao_leitor();
    $caminho = importacao_arquivo($imp);
    $cabecalhos = $leitor->cabecalhos($caminho, $imp['tipo_arquivo'], $imp['tipo_arquivo'] === 'csv' ? null : $aba, $linhaCabecalho);
    if (!$cabecalhos) {
        api_resposta(['ok' => false, 'erro' => 'Não encontramos cabeçalhos na linha ' . $linhaCabecalho . ' da aba "' . $aba . '". Escolha outra aba ou outra linha de cabeçalho.'], 422);
    }
    $amostra = $leitor->lerLinhas($caminho, $imp['tipo_arquivo'], $imp['tipo_arquivo'] === 'csv' ? null : $aba, $linhaCabecalho + 1, 8, $cabecalhos);
    $repo = importacao_repo();
    // Mapeamento já usado nesta importação (reanálise) tem prioridade sobre a sugestão automática.
    $anterior = $imp['mapeamento_json'] && $imp['aba'] === $aba && (int)$imp['linha_cabecalho'] === $linhaCabecalho ? $imp['mapeamento_json'] : null;
    $sugestao = importacao_validador()->sugerirMapeamento(array_values($cabecalhos), $salvo ?? []);
    if ($anterior && !$salvo) {
        $sugestao = array_merge($sugestao, array_intersect_key($anterior, $sugestao));
    }
    $colunas = [];
    foreach ($cabecalhos as $letra => $titulo) {
        $exemplos = [];
        foreach ($amostra as $linha) {
            $v = trim((string)($linha[$titulo] ?? ''));
            if ($v !== '' && count($exemplos) < 3) {
                $exemplos[] = mb_strimwidth($v, 0, 60, '…');
            }
        }
        $colunas[] = ['letra' => $letra, 'cabecalho' => $titulo, 'exemplos' => $exemplos, 'campo' => $sugestao[$titulo]];
    }
    $infoAba = null;
    foreach ($imp['abas_json'] ?? [] as $a) {
        if ($a['nome'] === $aba) {
            $infoAba = $a;
        }
    }
    return [
        'aba' => $aba,
        'linha_cabecalho' => $linhaCabecalho,
        'linhas' => max(0, (int)($infoAba['linhas'] ?? 0) - $linhaCabecalho),
        'colunas' => $colunas,
        'mapeamentos' => $repo->mapeamentosSalvos(),
    ];
}
