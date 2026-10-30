<?php
/**
 * Análise e revisão das linhas (etapas 3 e 4 da importação).
 *
 * POST acao=analisar       id, aba, linha_cabecalho, mapeamento (JSON) → prepara a análise
 * POST acao=lote           id → lê e valida o próximo bloco de linhas (chamado até "concluida")
 * GET  acao=item           item_id → dados originais, normalizados, problemas e sugestões
 * GET  acao=buscar_livros  q → livros do acervo para vincular
 * POST acao=corrigir       item_id, campos[...] → grava a correção (sem apagar o original) e revalida
 * POST acao=desfazer       item_id → volta aos valores da planilha
 * POST acao=decidir        item_id, decisao, livro_id?, linha? → criar | vincular | outro_exemplar | ignorar | revisar_depois | automatica
 * POST acao=decidir_lote   id, filtro, decisao → mesma decisão para um grupo (ex.: linhas idênticas)
 * Nada é gravado no acervo aqui.
 */
require_once __DIR__ . '/../../includes/importacao.php';

use Biblioteca\Services\ImportValidationService;

const VALIDAR_LOTE = 400;

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$acao = (string)($post ? ($_POST['acao'] ?? '') : ($_GET['acao'] ?? ''));
$somenteLeitura = in_array($acao, ['item', 'buscar_livros'], true);
$usuario = importacao_api($somenteLeitura ? 'importacao.ver' : 'importacao.revisar', !$somenteLeitura);

try {
    match ($acao) {
        'analisar' => validar_analisar(),
        'lote' => validar_lote(),
        'item' => validar_item(),
        'buscar_livros' => api_resposta(['ok' => true, 'livros' => array_values(importacao_repo()->pesquisarLivros((string)($_GET['q'] ?? '')))]),
        'corrigir' => validar_corrigir($usuario),
        'desfazer' => validar_resposta_item(importacao_repo()->desfazerCorrecoes(importacao_id_param('item_id'), $usuario['id']), 'importacao.desfazer_correcao'),
        'decidir' => validar_decidir($usuario),
        'decidir_lote' => validar_decidir_lote($usuario),
        default => api_resposta(['ok' => false, 'erro' => 'Ação desconhecida.'], 400),
    };
} catch (Throwable $e) {
    importacao_api_falha($e);
}

function validar_importacao(): array
{
    $imp = importacao_repo()->buscar(importacao_id_param());
    if (!$imp) {
        api_resposta(['ok' => false, 'erro' => 'Importação não encontrada.'], 404);
    }
    return $imp;
}

function validar_analisar(): never
{
    $imp = validar_importacao();
    $aba = is_string($_POST['aba'] ?? null) ? $_POST['aba'] : (string)$imp['aba'];
    if (!in_array($aba, array_column($imp['abas_json'] ?? [], 'nome'), true)) {
        api_resposta(['ok' => false, 'erro' => 'A aba selecionada não existe nesta planilha.'], 422);
    }
    $linhaCabecalho = max(1, min(50, (int)($_POST['linha_cabecalho'] ?? 1)));
    $mapeamento = json_decode(is_string($_POST['mapeamento'] ?? null) ? $_POST['mapeamento'] : '', true);
    if (!is_array($mapeamento)) {
        api_resposta(['ok' => false, 'erro' => 'Mapeamento inválido.'], 422);
    }
    $leitor = importacao_leitor();
    $cabecalhos = $leitor->cabecalhos(importacao_arquivo($imp), $imp['tipo_arquivo'], $imp['tipo_arquivo'] === 'csv' ? null : $aba, $linhaCabecalho);
    // Só valem cabeçalhos que existem de fato na planilha; os demais ficam como "ignorar".
    $limpo = [];
    foreach ($cabecalhos as $titulo) {
        $campo = $mapeamento[$titulo] ?? 'ignorar';
        $limpo[$titulo] = is_string($campo) ? $campo : 'ignorar';
    }
    $erros = importacao_validador()->validarMapeamento($limpo);
    if ($erros) {
        api_resposta(['ok' => false, 'erro' => implode(' ', $erros)], 422);
    }
    importacao_repo()->iniciarAnalise((int)$imp['id'], $aba, $linhaCabecalho, $cabecalhos, $limpo);
    registrar_acao('importacao.analisar', 'importacao', (int)$imp['id'], ['aba' => $aba, 'linha_cabecalho' => $linhaCabecalho, 'mapeamento' => $limpo]);
    api_resposta(['ok' => true, 'total_previsto' => validar_total_previsto($imp, $aba, $linhaCabecalho)]);
}

function validar_total_previsto(array $imp, string $aba, int $linhaCabecalho): int
{
    foreach ($imp['abas_json'] ?? [] as $a) {
        if ($a['nome'] === $aba) {
            return max(0, (int)$a['linhas'] - $linhaCabecalho);
        }
    }
    return 0;
}

/** Lê o próximo bloco. Seguro para repetir: um bloco já gravado é ignorado. */
function validar_lote(): never
{
    $imp = validar_importacao();
    $id = (int)$imp['id'];
    $totalPrevisto = validar_total_previsto($imp, (string)$imp['aba'], (int)$imp['linha_cabecalho']);
    if ($imp['status'] !== 'em_analise') {
        api_resposta(['ok' => true, 'concluida' => $imp['status'] === 'em_revisao', 'analisadas' => $totalPrevisto, 'total_previsto' => $totalPrevisto, 'status' => $imp['status']]);
    }
    @set_time_limit(120);
    $inicio = (int)$imp['linhas_analisadas'] + 1;
    $ultimaLinhaAba = (int)$imp['linha_cabecalho'] + $totalPrevisto;
    $repo = importacao_repo();

    if ($inicio <= $ultimaLinhaAba) {
        $leitor = importacao_leitor();
        $caminho = importacao_arquivo($imp);
        $aba = $imp['tipo_arquivo'] === 'csv' ? null : $imp['aba'];
        $cabecalhos = $leitor->cabecalhos($caminho, $imp['tipo_arquivo'], $aba, (int)$imp['linha_cabecalho']);
        $linhas = $leitor->lerLinhas($caminho, $imp['tipo_arquivo'], $aba, $inicio, VALIDAR_LOTE, $cabecalhos);
        $fim = min($inicio + VALIDAR_LOTE - 1, $ultimaLinhaAba);
        $repo->inserirLinhas($id, $linhas, $imp['mapeamento_json'] ?? [], $fim);
        $inicio = $fim + 1;
    }
    $concluida = $inicio > $ultimaLinhaAba;
    if ($concluida) {
        $repo->concluirAnalise($id);
        $final = $repo->buscar($id);
        registrar_acao('importacao.validar', 'importacao', $id, [
            'linhas' => (int)$final['total_linhas'], 'validos' => (int)$final['total_validos'], 'avisos' => (int)$final['total_avisos'],
            'erros' => (int)$final['total_erros'], 'duplicidades' => (int)$final['total_duplicidades'],
        ]);
    }
    api_resposta([
        'ok' => true,
        'concluida' => $concluida,
        'analisadas' => min($totalPrevisto, max(0, $inicio - 1 - (int)$imp['linha_cabecalho'])),
        'total_previsto' => $totalPrevisto,
        'url' => $concluida ? admin_url('detalhes-importacao') . '?id=' . $id : null,
    ]);
}

function validar_item(): never
{
    $item = importacao_repo()->item(importacao_id_param('item_id'));
    if (!$item) {
        api_resposta(['ok' => false, 'erro' => 'Linha não encontrada.'], 404);
    }
    validar_resposta_item($item);
}

function validar_corrigir(array $usuario): never
{
    $campos = $_POST['campos'] ?? null;
    if (!is_array($campos)) {
        api_resposta(['ok' => false, 'erro' => 'Nada para corrigir.'], 422);
    }
    $correcoes = [];
    foreach (ImportValidationService::CAMPOS_CORRIGIVEIS as $campo) {
        if (array_key_exists($campo, $campos) && is_string($campos[$campo])) {
            $correcoes[$campo] = $campos[$campo];
        }
    }
    $item = importacao_repo()->corrigir(importacao_id_param('item_id'), $correcoes, $usuario['id']);
    validar_resposta_item($item, 'importacao.corrigir', ['campos' => array_keys($correcoes)]);
}

function validar_decidir(array $usuario): never
{
    $decisao = is_string($_POST['decisao'] ?? null) ? $_POST['decisao'] : '';
    $livroId = (int)($_POST['livro_id'] ?? 0) ?: null;
    $linha = (int)($_POST['linha'] ?? 0) ?: null;
    $item = importacao_repo()->decidir(importacao_id_param('item_id'), $decisao, $livroId, $linha, $usuario['id']);
    validar_resposta_item($item, 'importacao.decidir', ['decisao' => $decisao, 'livro_id' => $livroId, 'linha' => $linha]);
}

function validar_decidir_lote(array $usuario): never
{
    $imp = validar_importacao();
    $filtro = is_string($_POST['filtro'] ?? null) ? $_POST['filtro'] : '';
    $decisao = is_string($_POST['decisao'] ?? null) ? $_POST['decisao'] : '';
    $n = importacao_repo()->decidirEmLote((int)$imp['id'], $filtro, $decisao, $usuario['id']);
    registrar_acao('importacao.decidir_lote', 'importacao', (int)$imp['id'], ['filtro' => $filtro, 'decisao' => $decisao, 'linhas' => $n]);
    api_resposta(['ok' => true, 'alteradas' => $n]);
}

/** Resposta padrão com a linha completa (usada pelo modal de revisão). */
function validar_resposta_item(array $item, ?string $acaoLog = null, array $detalhes = []): never
{
    $repo = importacao_repo();
    if ($acaoLog) {
        registrar_acao($acaoLog, 'importacao', (int)$item['importacao_id'], ['linha' => (int)$item['numero_linha']] + $detalhes);
    }
    $imp = $repo->buscar((int)$item['importacao_id']);
    $livros = $repo->livrosResumo([$item['livro_sugerido_id'], $item['livro_vinculado_id'], $item['livro_resultante_id']]);
    $relacionada = $item['linha_relacionada'] ? $repo->itemPorLinha((int)$item['importacao_id'], (int)$item['linha_relacionada']) : null;
    $campos = [];
    foreach (ImportValidationService::CAMPOS as $campo => $def) {
        $campos[$campo] = $def['label'];
    }
    $mapeamento = $imp['mapeamento_json'] ?? [];
    $originais = [];
    foreach ($item['dados_originais_json'] as $cabecalho => $valor) {
        $campo = $mapeamento[$cabecalho] ?? 'ignorar';
        $originais[] = ['coluna' => $cabecalho, 'valor' => $valor, 'campo' => $campo === 'ignorar' ? 'Ignorada' : ($campos[$campo] ?? $campo)];
    }
    $n = $item['dados_normalizados_json'];
    $editavel = in_array($imp['status'], \Biblioteca\Repositories\ImportacaoRepository::STATUS_EDITAVEIS, true)
        && ($item['processado_em'] === null || $item['resultado_importacao'] === 'falhou');
    api_resposta([
        'ok' => true,
        'item' => [
            'id' => (int)$item['id'],
            'linha' => (int)$item['numero_linha'],
            'situacao' => $item['status_validacao'],
            'situacao_label' => IMPORTACAO_SITUACOES[$item['status_validacao']]['label'],
            'problemas' => $item['erros_json'] ?? [],
            'originais' => $originais,
            'normalizados' => [
                'titulo' => $n['titulo'], 'subtitulo' => $n['subtitulo'], 'autor' => $n['autor'],
                'isbn' => $n['isbn_informado'] !== null ? $n['isbn'] : null, 'editora' => $n['editora'],
                'ano_publicacao' => $n['ano_publicacao'] ?? $n['ano_informado'], 'edicao' => $n['edicao'], 'idioma' => $n['idioma'],
                'cdd_original' => $n['cdd_original'], 'codigo_autor_antigo' => $n['codigo_autor_antigo'],
                'localizacao_antiga' => $n['localizacao_antiga'], 'assunto_original' => $n['assunto_original'],
                'categoria_original' => $n['categoria_original'],
                'quantidade_informada' => $n['quantidade_informada'] ?? $n['quantidade_texto'], 'observacoes' => $n['observacoes'],
            ],
            'editaveis' => [
                'titulo' => $n['titulo'], 'subtitulo' => $n['subtitulo'], 'autor' => $n['autor'],
                'isbn' => $n['isbn_informado'], 'editora' => $n['editora'],
                'ano_publicacao' => $n['ano_informado'], 'edicao' => $n['edicao'], 'quantidade_informada' => $n['quantidade_texto'],
            ],
            'corrigidos' => $item['dados_corrigidos_json'] ?? [],
            'rotulos' => $campos,
            'acao' => $item['acao_importacao'],
            'acao_label' => $item['acao_importacao'] ? IMPORTACAO_ACOES[$item['acao_importacao']]['label'] : null,
            'acao_automatica' => (bool)$item['acao_automatica'],
            'ja_cadastrado' => (bool)$item['ja_cadastrado'],
            'livro_sugerido' => $item['livro_sugerido_id'] ? ($livros[(int)$item['livro_sugerido_id']] ?? null) : null,
            'livro_vinculado' => $item['livro_vinculado_id'] ? ($livros[(int)$item['livro_vinculado_id']] ?? null) : null,
            'linha_relacionada' => $item['linha_relacionada'] ? (int)$item['linha_relacionada'] : null,
            'linha_relacionada_titulo' => $relacionada['dados_normalizados_json']['titulo'] ?? null,
            'referencia_linha' => $item['referencia_linha'] ?? null,
            'resultado' => $item['resultado_importacao'],
            'resultado_label' => $item['resultado_importacao'] ? IMPORTACAO_RESULTADOS[$item['resultado_importacao']]['label'] : null,
            'mensagem_processamento' => $item['mensagem_processamento'],
            'livro_resultante' => $item['livro_resultante_id'] ? ($livros[(int)$item['livro_resultante_id']] ?? null) : null,
            'livro_url' => $item['livro_resultante_id'] ? admin_url('detalhes-livro') . '?id=' . (int)$item['livro_resultante_id'] : null,
            'revisado_por' => $item['revisado_por_nome'] ?? null,
            'revisado_em' => $item['revisado_em'] ? importacao_data($item['revisado_em']) : null,
            'editavel' => $editavel && auth_pode('importacao.revisar'),
        ],
        'resumo' => $repo->resumoConfirmacao((int)$item['importacao_id']),
        'contagem' => $repo->contagemFiltros((int)$item['importacao_id']),
    ]);
}
