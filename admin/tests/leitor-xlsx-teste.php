<?php
/**
 * Testes do leitor próprio de .xlsx (sem PhpSpreadsheet, sem banco e sem rede).
 *
 * Uso: php admin/tests/leitor-xlsx-teste.php
 * As planilhas são fictícias e montadas pelo próprio teste (admin/tests/apoio/xlsx-ficticio.php).
 * Cada caso é lido duas vezes: com a extensão zip do PHP e com a leitura própria pela zlib
 * (o caso do XAMPP com "extension=zip" desligada). Se o PhpSpreadsheet estiver instalado,
 * confere também uma planilha gravada por ele.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Services\ExcelImportService;
use Biblioteca\Support\ArquivoZip;
use Biblioteca\Support\LeitorXlsx;

require __DIR__ . '/suporte.php';
require __DIR__ . '/apoio/xlsx-ficticio.php';

$tmp = sys_get_temp_dir() . '/bdp-leitor-xlsx-' . bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});

function erro_de(callable $f): ?Throwable
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

$modos = class_exists(ZipArchive::class) ? ['extensão zip' => true, 'zlib (sem extensão zip)' => false] : ['zlib (sem extensão zip)' => false];
if (!class_exists(ZipArchive::class)) {
    echo "  (extensão zip desligada: só a leitura pela zlib é testada)\n";
}

confere('leitura de .xlsx disponível sem PhpSpreadsheet', LeitorXlsx::disponivel() && ExcelImportService::xlsxDisponivel());

xlsx_ficticio("$tmp/acervo.xlsx", [
    'Instruções' => [1 => ['A' => 'Planilha fictícia']],
    'Catalogar' => [
        1 => ['A' => 'Título', 'B' => 'Autor', 'C' => 'ISBN', 'D' => 'CDD', 'E' => 'Localização', 'F' => 'Qtd', 'G' => 'Ativo', 'H' => 'Obs'],
        2 => ['A' => 'Memórias Fictícias de Ação', 'B' => 'SOBRENOME, Autor Fictício', 'C' => 9788535911237, 'D' => '0869.3', 'E' => ['formula' => 'CONCAT("L945v","1A")', 'resultado' => 'L945v1A'], 'F' => 2, 'G' => true, 'H' => ['inline' => "  com espaços  "]],
        3 => ['A' => ['rico' => ['Livro ', 'Fictício']], 'C' => '9788594452603', 'F' => 1.5, 'G' => false, 'H' => ['formula' => 'X1+1']],
        5 => ['B' => 'Linha 4 ficou vazia', 'AA' => 'longe', 'C' => 9.7885359e12],
        7 => ['A' => "Quebra_x000D_de linha", 'B' => ['formula' => '2*3', 'resultado' => 6]],
    ],
    'Oculta' => [1 => ['A' => 'aba escondida'], 3 => ['B' => 'x']],
], ['ativa' => 1, 'oculta' => ['Oculta']]);

foreach ($modos as $rotulo => $usarExtensao) {
    echo "\nLeitura pela $rotulo\n";
    $l = new LeitorXlsx("$tmp/acervo.xlsx", $usarExtensao);
    $abas = $l->abas();
    confere('abas na ordem da planilha, inclusive a oculta', array_column($abas, 'nome') === ['Instruções', 'Catalogar', 'Oculta'], json_encode($abas, JSON_UNESCAPED_UNICODE));
    confere('linhas e colunas contam até a última célula com valor', $abas[1]['linhas'] === 7 && $abas[1]['colunas'] === 27 && $abas[2]['linhas'] === 3 && $abas[2]['colunas'] === 2, json_encode($abas[1]));
    $linhas = $l->linhas('Catalogar', 1, 100, 60);
    confere('acentos preservados (texto compartilhado)', ($linhas[2][1] ?? '') === 'Memórias Fictícias de Ação');
    confere('ISBN numérico não vira notação científica', ($linhas[2][3] ?? '') === '9788535911237', $linhas[2][3] ?? '');
    confere('número salvo em notação científica volta inteiro', ($linhas[5][3] ?? '') === '9788535900000', $linhas[5][3] ?? '');
    confere('CDD com zero à esquerda (texto) preservada', ($linhas[2][4] ?? '') === '0869.3');
    confere('fórmula de texto vira o resultado salvo', ($linhas[2][5] ?? '') === 'L945v1A');
    confere('fórmula numérica vira o resultado salvo', ($linhas[7][2] ?? '') === '6');
    confere('fórmula sem resultado salvo fica como texto da fórmula', ($linhas[3][8] ?? '') === '=X1+1', $linhas[3][8] ?? '');
    confere('números inteiros e decimais', ($linhas[2][6] ?? '') === '2' && ($linhas[3][6] ?? '') === '1.5');
    confere('booleanos', ($linhas[2][7] ?? '') === 'VERDADEIRO' && ($linhas[3][7] ?? '') === 'FALSO');
    confere('texto em linha mantém os espaços', ($linhas[2][8] ?? '') === '  com espaços  ');
    confere('texto com formatação junta as partes e ignora a fonética', ($linhas[3][1] ?? '') === 'Livro Fictício', $linhas[3][1] ?? '');
    confere('_x000D_ vira quebra de linha', ($linhas[7][1] ?? '') === "Quebra\rde linha");
    confere('linha vazia não aparece e células vazias não aparecem', !isset($linhas[4]) && !isset($linhas[3][2]));
    confere('coluna AA (27) lida', ($linhas[5][27] ?? '') === 'longe');
    confere('limite de colunas respeitado', !isset($l->linhas('Catalogar', 5, 5, 26)[5][27]));
    $bloco = $l->linhas('Catalogar', 3, 5, 60);
    confere('bloco de linhas (3 a 5)', array_keys($bloco) === [3, 5], json_encode(array_keys($bloco)));
    confere('sem aba escolhida lê a aba ativa', ($l->linhas(null, 1, 1, 60)[1][1] ?? '') === 'Título');
    confere('aba oculta pode ser lida', ($l->linhas('Oculta', 1, 5, 60)[1][1] ?? '') === 'aba escondida');
    $e = erro_de(fn () => $l->linhas('Não existe', 1, 5, 60));
    confere('aba inexistente é recusada com mensagem clara', $e instanceof RuntimeException && str_contains($e->getMessage(), 'aba'));
}

echo "\nVariações de arquivo\n";
xlsx_ficticio("$tmp/sem-ref.xlsx", ['Alunos' => [1 => ['A' => 'Matrícula', 'B' => 'Nome'], 2 => ['A' => '0042', 'B' => 'Leo Fictício']]], ['sem_ref' => true]);
xlsx_ficticio("$tmp/armazenado.xlsx", ['A' => [1 => ['A' => 'sem compressão']]], ['armazenar' => true]);
foreach ($modos as $rotulo => $usarExtensao) {
    $sr = (new LeitorXlsx("$tmp/sem-ref.xlsx", $usarExtensao))->linhas(null, 1, 10, 60);
    confere("células sem referência (r) seguem a ordem — $rotulo", ($sr[2][1] ?? '') === '0042' && ($sr[2][2] ?? '') === 'Leo Fictício', json_encode($sr, JSON_UNESCAPED_UNICODE));
    $ar = (new LeitorXlsx("$tmp/armazenado.xlsx", $usarExtensao))->linhas(null, 1, 10, 60);
    confere("zip sem compressão — $rotulo", ($ar[1][1] ?? '') === 'sem compressão');
}

file_put_contents("$tmp/quebrado.xlsx", "PK\x03\x04 isto não é uma planilha");
foreach ($modos as $rotulo => $usarExtensao) {
    $e = erro_de(fn () => (new LeitorXlsx("$tmp/quebrado.xlsx", $usarExtensao))->abas());
    confere("arquivo corrompido dá erro amigável — $rotulo", $e instanceof RuntimeException && str_contains($e->getMessage(), '.xlsx'), $e ? get_class($e) . ': ' . $e->getMessage() : 'sem erro');
}
file_put_contents("$tmp/xml-ruim.xlsx", zip_ficticio(['xl/workbook.xml' => '<workbook><sheets><sheet name="A" sheetId="1"/></sheets></workbook>', 'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row><row r="2"><c r="A2"']));
$e = erro_de(fn () => (new LeitorXlsx("$tmp/xml-ruim.xlsx", false))->linhas(null, 1, 10, 60));
confere('XML danificado dá erro amigável', $e instanceof RuntimeException && str_contains($e->getMessage(), 'danificado'), $e ? get_class($e) . ': ' . $e->getMessage() : 'sem erro');
file_put_contents("$tmp/minimo.xlsx", zip_ficticio(['xl/workbook.xml' => '<workbook><sheets><sheet name="A" sheetId="1"/></sheets></workbook>', 'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row></sheetData></worksheet>']));
$sem = (new LeitorXlsx("$tmp/minimo.xlsx", false))->linhas(null, 1, 1, 60);
confere('sem relações nem textos compartilhados: usa sheet1.xml', ($sem[1][1] ?? '') === 'ok');

// Entidade externa (XXE) nunca é carregada.
file_put_contents("$tmp/segredo.txt", 'SEGREDO');
file_put_contents("$tmp/xxe.xlsx", zip_ficticio([
    'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="A" sheetId="1"/></sheets></workbook>',
    'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file://' . $tmp . '/segredo.txt">]><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row></sheetData></worksheet>',
]));
$x = null;
erro_de(function () use ($tmp, &$x) {
    $x = (new LeitorXlsx("$tmp/xxe.xlsx", false))->linhas(null, 1, 1, 60);
});
confere('entidade externa no XML não é lida', !str_contains(json_encode($x), 'SEGREDO'));

echo "\nExcelImportService (o que as telas usam)\n";
$s = new ExcelImportService();
confere('validarArquivo aceita o .xlsx', $s->validarArquivo("$tmp/acervo.xlsx", 'acervo.xlsx') === 'xlsx');
$abas = $s->abas("$tmp/acervo.xlsx", 'xlsx');
confere('abas com no máximo 60 colunas', $abas[1]['colunas'] === 27);
$cab = $s->cabecalhos("$tmp/acervo.xlsx", 'xlsx', 'Catalogar');
confere('cabeçalhos por letra', array_slice($cab, 0, 3, true) === ['A' => 'Título', 'B' => 'Autor', 'C' => 'ISBN'], json_encode($cab, JSON_UNESCAPED_UNICODE));
$lidas = $s->lerLinhas("$tmp/acervo.xlsx", 'xlsx', 'Catalogar', 2, 10, $cab);
confere('linhas por cabeçalho, colunas vazias como ""', ($lidas[2]['Título'] ?? null) === 'Memórias Fictícias de Ação' && ($lidas[3]['Autor'] ?? null) === '' && ($lidas[2]['ISBN'] ?? '') === '9788535911237');
confere('valor fora das colunas com cabeçalho é preservado', ($lidas[5]['Coluna AA'] ?? '') === 'longe', json_encode($lidas[5] ?? [], JSON_UNESCAPED_UNICODE));
confere('linha vazia omitida', !isset($lidas[4]) && !isset($lidas[6]));
$semCab = $s->lerLinhas("$tmp/acervo.xlsx", 'xlsx', 'Catalogar', 5, 1, null);
confere('sem cabeçalhos: todas as letras até a última coluna do bloco', count($semCab[5] ?? []) === 27 && $semCab[5]['A'] === '' && $semCab[5]['AA'] === 'longe');

xlsx_ficticio("$tmp/macro.xlsx", ['A' => [1 => ['A' => 'x']]], ['extra' => ['xl/vbaProject.bin' => 'macro']]);
$e = erro_de(fn () => $s->validarArquivo("$tmp/macro.xlsx", 'macro.xlsx'));
confere('planilha com macros é recusada', $e instanceof RuntimeException && str_contains($e->getMessage(), 'macros'));
$e = erro_de(fn () => $s->validarArquivo("$tmp/quebrado.xlsx", 'quebrado.xlsx'));
confere('.xlsx corrompido é recusado na validação', $e instanceof RuntimeException);
file_put_contents("$tmp/bomba.xlsx", zip_ficticio(['xl/workbook.xml' => '<workbook/>', 'xl/worksheets/sheet1.xml' => '<worksheet/>'], false, ['xl/worksheets/sheet1.xml' => 300 * 1024 * 1024]));
$e = erro_de(fn () => $s->validarArquivo("$tmp/bomba.xlsx", 'bomba.xlsx'));
confere('"zip bomb" é recusado pelo tamanho descompactado', $e instanceof RuntimeException && str_contains($e->getMessage(), 'grande demais'), $e ? $e->getMessage() : '');
@unlink("$tmp/bomba.xlsx");

echo "\nDesempenho\n";
$grandes = [1 => ['A' => 'Título', 'B' => 'Autor', 'C' => 'ISBN', 'D' => 'CDD', 'E' => 'Editora', 'F' => 'Ano']];
for ($i = 2; $i <= 6001; $i++) {
    $grandes[$i] = ['A' => "Livro Fictício $i", 'B' => "AUTOR, Fictício $i", 'C' => 9788500000000 + $i, 'D' => '869.' . ($i % 10), 'E' => 'Editora Fictícia', 'F' => 1990 + $i % 30];
}
xlsx_ficticio("$tmp/grande.xlsx", ['Catalogar' => $grandes]);
foreach ($modos as $rotulo => $usarExtensao) {
    $t = microtime(true);
    $l = new LeitorXlsx("$tmp/grande.xlsx", $usarExtensao);
    $info = $l->abas()[0];
    $total = 0;
    for ($inicio = 2; $inicio <= 6001; $inicio += 400) {
        $total += count($l->linhas('Catalogar', $inicio, $inicio + 399, 60));
    }
    $tempo = microtime(true) - $t;
    confere(sprintf('6.000 linhas em blocos de 400 — %s (%.2f s)', $rotulo, $tempo), $info['linhas'] === 6001 && $total === 6000 && $tempo < 30);
}

if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    echo "\nPlanilha gravada pelo PhpSpreadsheet\n";
    $p = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $a = $p->getActiveSheet()->setTitle('Acervo 2019');
    $a->fromArray([['Título', 'ISBN', 'CDD'], ['Dom Fictício', null, null]]);
    $a->setCellValue('B2', 9788535911237);
    $a->setCellValueExplicit('C2', '0869.3', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $a->setCellValue('D2', '="L945v"&"1A"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($p))->save("$tmp/phpspreadsheet.xlsx");
    foreach ($modos as $rotulo => $usarExtensao) {
        $l = (new LeitorXlsx("$tmp/phpspreadsheet.xlsx", $usarExtensao))->linhas('Acervo 2019', 1, 5, 60);
        confere("lida corretamente — $rotulo", ($l[2] ?? []) === [1 => 'Dom Fictício', 2 => '9788535911237', 3 => '0869.3', 4 => 'L945v1A'], json_encode($l, JSON_UNESCAPED_UNICODE));
    }
}

teste_resultado();
