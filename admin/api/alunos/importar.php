<?php
/**
 * API da importação de alunos (admin/importar-alunos.php). Todas as ações exigem login,
 * a permissão alunos.importar e token CSRF. O arquivo vem junto em cada POST e não é guardado.
 *
 * POST acao=ler        arquivo, aba?, linha_cabecalho → abas, colunas, exemplos e mapeamento sugerido
 * POST acao=previa     arquivo, aba, linha_cabecalho, mapeamento (JSON), modo, importar_duplicidades
 *                      → resumo, linhas com situação e assinatura da prévia (nada é gravado)
 * POST acao=confirmar  os mesmos campos + assinatura (+ confirmo_atualizacao=1 quando houver atualizações)
 *                      → grava tudo numa transação e devolve o relatório
 * GET  acao=modelo     planilha CSV de exemplo com alunos fictícios
 */
require_once __DIR__ . '/../../includes/alunos-importacao.php';

use Biblioteca\Services\AlunoImportService;

$acao = is_string($_REQUEST['acao'] ?? null) ? $_REQUEST['acao'] : '';
alunos_importacao_api($acao !== 'modelo');
set_time_limit(180);

try {
    match ($acao) {
        'ler' => alunos_api_ler(),
        'previa' => alunos_api_previa(),
        'confirmar' => alunos_api_confirmar(),
        'modelo' => alunos_api_modelo(),
        default => api_resposta(['ok' => false, 'erro' => 'Ação desconhecida.'], 400),
    };
} catch (Throwable $e) {
    alunos_importacao_falha($e);
}

function alunos_api_ler(): never
{
    $arquivo = alunos_importacao_arquivo();
    $aba = is_string($_POST['aba'] ?? null) ? $_POST['aba'] : null;
    $linha = max(1, min(50, (int)($_POST['linha_cabecalho'] ?? 1)));
    $colunas = alunos_importacao_colunas($arquivo, $aba, $linha);
    if ($colunas['linhas'] > AlunoImportService::MAX_LINHAS) {
        $colunas['aviso'] = 'Esta aba tem mais de ' . number_format(AlunoImportService::MAX_LINHAS, 0, ',', '.') . ' linhas: divida a planilha antes de importar.';
    }
    api_resposta(['ok' => true, 'tipo' => $arquivo['tipo']] + $colunas);
}

function alunos_api_previa(): never
{
    $arquivo = alunos_importacao_arquivo();
    $params = alunos_importacao_parametros($_POST);
    $plano = alunos_importacao_analisar($arquivo, $params);
    api_resposta([
        'ok' => true,
        'modo' => $plano['modo'],
        'aba' => $plano['aba'],
        'resumo' => $plano['resumo'],
        'itens' => alunos_importacao_itens_previa($plano),
        'assinatura' => AlunoImportService::assinatura($plano, $arquivo['hash'], alunos_importacao_chave()),
    ]);
}

function alunos_api_confirmar(): never
{
    $arquivo = alunos_importacao_arquivo();
    $params = alunos_importacao_parametros($_POST);
    $assinatura = is_string($_POST['assinatura'] ?? null) ? $_POST['assinatura'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $assinatura)) {
        throw new AlunosImportacaoConflito('Gere a prévia antes de importar.');
    }
    $r = alunos_importacao_gravar($arquivo, $params, $assinatura, ($_POST['confirmo_atualizacao'] ?? '') === '1');
    $resumo = $r['plano']['resumo'];
    // Só contagens: nenhum nome, matrícula ou e-mail no registro de ações.
    registrar_acao('alunos.importar', 'alunos', null, [
        'modo' => $r['plano']['modo'],
        'linhas' => $resumo['total'],
        'incluidos' => $r['incluidos'],
        'atualizados' => $r['atualizados'],
        'nao_importados' => $resumo['ignorados'],
        'erros' => $resumo['erros'],
        'tipo_arquivo' => $arquivo['tipo'],
    ]);
    $naoImportados = [];
    foreach ($r['plano']['itens'] as $item) {
        if ($item['acao'] === 'nenhuma') {
            $naoImportados[] = ['linha' => $item['linha'], 'matricula' => $item['dados']['matricula'], 'situacao' => $item['situacao'], 'motivo' => alunos_importacao_motivo($item)];
        }
    }
    api_resposta([
        'ok' => true,
        'incluidos' => $r['incluidos'],
        'atualizados' => $r['atualizados'],
        'resumo' => $resumo,
        'nao_importados' => $naoImportados,
        'total_alunos' => alunos_repo()->total(),
    ]);
}

/** Modelo de planilha (CSV com ponto e vírgula, como o Excel em português salva). Só dados fictícios. */
function alunos_api_modelo(): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo-importacao-alunos.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo "Nome;Matrícula;Turma;Turno;E-mail institucional\r\n";
    echo "Aluno Fictício Exemplo;2026000001;1º A;Manhã;aluno.exemplo@escola.exemplo\r\n";
    echo "Aluna Fictícia Exemplo;2026000002;2º B;Tarde;aluna.exemplo@escola.exemplo\r\n";
    exit;
}
