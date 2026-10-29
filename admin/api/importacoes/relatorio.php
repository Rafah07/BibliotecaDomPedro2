<?php
/**
 * Downloads da importação.
 *
 * GET tipo=inconsistencias&id=  CSV com o número da linha e cada problema encontrado
 * GET tipo=completo&id=         CSV com todas as linhas, situação, decisão e resultado
 * GET tipo=importados&id=       CSV com as linhas gravadas e o livro correspondente
 * GET tipo=modelo               planilha-modelo (.xlsx; .csv se o PhpSpreadsheet não estiver instalado)
 *
 * Os CSV usam ";" e UTF-8 com BOM para abrir corretamente no Excel em português.
 */
require_once __DIR__ . '/../../includes/importacao.php';

use Biblioteca\Services\ExcelImportService;

$tipo = is_string($_GET['tipo'] ?? null) ? $_GET['tipo'] : '';

if ($tipo === 'modelo') {
    auth_sessao();
    if (!auth_usuario()) {
        http_response_code(401);
        exit('Entre no painel para baixar o modelo.');
    }
    relatorio_modelo();
}

importacao_api('importacao.ver');
$repo = importacao_repo();
$imp = $repo->buscar(importacao_id_param());
if (!$imp || !in_array($tipo, ['inconsistencias', 'completo', 'importados'], true)) {
    http_response_code(404);
    exit('Relatório não encontrado.');
}
registrar_acao('importacao.relatorio', 'importacao', (int)$imp['id'], ['tipo' => $tipo]);

$base = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($imp['nome_arquivo'], PATHINFO_FILENAME)) ?: 'planilha';
$nome = sprintf('importacao-%d-%s-%s.csv', $imp['id'], $tipo, $base);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$saida = fopen('php://output', 'w');
fwrite($saida, "\xEF\xBB\xBF");
$csv = function (array $linha) use ($saida) {
    // Evita que o Excel interprete valores como fórmulas (=, +, -, @).
    fputcsv($saida, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $linha), ';', '"', '');
};

$problemasTexto = function (array $item, bool $incluirInfo): string {
    $partes = [];
    foreach ($item['erros_json'] ?? [] as $p) {
        if ($p['nivel'] !== 'info' || $incluirInfo) {
            $partes[] = $p['mensagem'];
        }
    }
    return implode(' | ', $partes);
};

if ($tipo === 'inconsistencias') {
    $csv(['Linha', 'Situação', 'Nível', 'Campo', 'Problema identificado', 'Título', 'Autor', 'ISBN informado']);
    $repo->percorrerItens((int)$imp['id'], true, function (array $item) use ($csv) {
        $n = $item['dados_normalizados_json'];
        $problemas = array_filter($item['erros_json'] ?? [], fn ($p) => $p['nivel'] !== 'info');
        if ($item['resultado_importacao'] === 'falhou') {
            $problemas[] = ['nivel' => 'erro', 'campo' => null, 'mensagem' => 'Falha ao gravar: ' . $item['mensagem_processamento']];
        }
        foreach ($problemas as $p) {
            $csv([
                $item['numero_linha'], IMPORTACAO_SITUACOES[$item['status_validacao']]['label'], ucfirst($p['nivel']),
                $p['campo'] ? (\Biblioteca\Services\ImportValidationService::CAMPOS[$p['campo']]['label'] ?? $p['campo']) : '',
                $p['mensagem'], $n['titulo'] ?? '', $n['autor'] ?? '', $n['isbn_informado'] ?? '',
            ]);
        }
    });
} elseif ($tipo === 'completo') {
    $csv(['Linha', 'Título', 'Subtítulo', 'Autor', 'ISBN', 'Editora', 'Ano', 'CDD original', 'Cód. autor antigo', 'Localização antiga', 'Assunto original',
        'Quantidade informada', 'Situação', 'Problemas', 'Decisão', 'Resultado', 'Livro no acervo', 'Corrigida na revisão']);
    $repo->percorrerItens((int)$imp['id'], false, function (array $item) use ($csv, $problemasTexto) {
        $n = $item['dados_normalizados_json'];
        $csv([
            $item['numero_linha'], $n['titulo'] ?? '', $n['subtitulo'] ?? '', $n['autor'] ?? '', $n['isbn_informado'] ?? '', $n['editora'] ?? '',
            $n['ano_informado'] ?? '', $n['cdd_original'] ?? '', $n['codigo_autor_antigo'] ?? '', $n['localizacao_antiga'] ?? '', $n['assunto_original'] ?? '',
            $n['quantidade_texto'] ?? '', IMPORTACAO_SITUACOES[$item['status_validacao']]['label'], $problemasTexto($item, true),
            $item['acao_importacao'] ? IMPORTACAO_ACOES[$item['acao_importacao']]['label'] : 'Aguardando decisão',
            $item['resultado_importacao'] ? IMPORTACAO_RESULTADOS[$item['resultado_importacao']]['label'] . ($item['mensagem_processamento'] ? ': ' . $item['mensagem_processamento'] : '') : '',
            $item['livro_resultante_id'] ? ('nº ' . $item['livro_resultante_id'] . ' — ' . $item['livro_resultante_titulo']) : '',
            $item['dados_corrigidos_json'] ? 'Sim' : 'Não',
        ]);
    });
} else {
    $csv(['Linha', 'Resultado', 'Livro nº', 'Título no acervo', 'Título na planilha', 'CDD original', 'Localização antiga', 'Quantidade informada']);
    $repo->percorrerItens((int)$imp['id'], false, function (array $item) use ($csv) {
        if (!in_array($item['resultado_importacao'], ['criado', 'vinculado', 'outro_exemplar'], true)) {
            return;
        }
        $n = $item['dados_normalizados_json'];
        $csv([
            $item['numero_linha'], IMPORTACAO_RESULTADOS[$item['resultado_importacao']]['label'], $item['livro_resultante_id'],
            $item['livro_resultante_titulo'], $n['titulo'] ?? '', $n['cdd_original'] ?? '', $n['localizacao_antiga'] ?? '', $n['quantidade_informada'] ?? '',
        ]);
    });
}
fclose($saida);
exit;

/** Planilha-modelo com as colunas usadas pela biblioteca. */
function relatorio_modelo(): never
{
    $cabecalhos = ['Localização na Estante', 'Cód. Autor', 'CDD', 'Sobrenome/Prenome do Autor', 'Título', 'Subtítulo', 'Assunto', 'ISBN', 'Editora', 'Ano', 'Edição', 'Quantidade', 'Observações'];
    $exemplo = ['A-01-03', 'A848d', '869.3', 'ASSIS, Machado de', 'Dom Casmurro', '', 'Literatura brasileira', '9788594452603', 'Editora Aurora', '2019', '1ª edição', '5', 'Exemplo: apague esta linha'];
    if (ExcelImportService::xlsxDisponivel()) {
        $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $folha = $planilha->getActiveSheet();
        $folha->setTitle('Acervo');
        $folha->fromArray([$cabecalhos], null, 'A1');
        $folha->fromArray([$exemplo], null, 'A2', true);
        // ISBN e CDD como texto, para não perder zeros nem virar notação científica.
        $folha->getStyle('B:C')->getNumberFormat()->setFormatCode('@');
        $folha->getStyle('H:H')->getNumberFormat()->setFormatCode('@');
        $folha->setCellValueExplicit('H2', $exemplo[7], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $folha->setCellValueExplicit('C2', $exemplo[2], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $folha->getStyle('A1:M1')->getFont()->setBold(true);
        $folha->getStyle('A1:M1')->getFill()->setFillType('solid')->getStartColor()->setRGB('E8EEFB');
        foreach (range('A', 'M') as $col) {
            $folha->getColumnDimension($col)->setAutoSize(true);
        }
        $folha->freezePane('A2');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modelo-importacao-acervo.xlsx"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($planilha))->save('php://output');
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo-importacao-acervo.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, $cabecalhos, ';', '"', '');
    fputcsv($saida, $exemplo, ';', '"', '');
    exit;
}
