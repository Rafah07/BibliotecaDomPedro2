<?php
/**
 * Testes das regras de importação das planilhas (sem banco e sem rede).
 *
 * Uso: php admin/tests/importacao-teste.php
 * Cobre leitura de CSV/XLSX (várias abas, acentos, Windows-1252), mapeamento, normalização,
 * validação de ISBN, campos obrigatórios, CDD fora do padrão, quantidades e duplicidades.
 * O fluxo com banco (gravação em lotes, permissões, importação repetida) é testado pelas APIs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Services\ExcelImportService;
use Biblioteca\Services\ImportValidationService;
use Biblioteca\Support\Isbn;
use Biblioteca\Support\Texto;

require __DIR__ . '/../../config/app.php'; // carrega as classes de src/ (Composer ou carregador simples)

$falhas = 0;
$total = 0;
function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? "  ok  " : "  FALHOU  ") . $nome . ($ok || $detalhe === '' ? '' : " — $detalhe") . "\n";
}
function codigos(array $problemas): array
{
    return array_column($problemas, 'codigo');
}

$v = new ImportValidationService();
$leitor = new ExcelImportService();
$tmp = sys_get_temp_dir() . '/bdp-importacao-teste-' . bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(function () use ($tmp) {
    array_map('unlink', glob($tmp . '/*'));
    rmdir($tmp);
});

/** Linha da planilha → valores normalizados e problemas, como a análise faz. */
$analisar = function (array $original, array $mapeamento) use ($v): array {
    $n = $v->normalizar($v->mapear($original, $mapeamento));
    return [$n, $v->validarLinha($n)];
};

echo "ISBN\n";
confere('ISBN-13 válido com hífens', Isbn::valido('978-85-2209-083-9'));
confere('ISBN-10 válido com X', Isbn::valido('0-8044-2957-X'));
confere('dígito verificador errado', !Isbn::valido('9788501001660'));
confere('ISBN-10 convertido para 13', Isbn::para13('080442957X') === '9780804429573');

echo "Texto\n";
confere('espaços extras removidos, acentos mantidos', Texto::limpar("  Memórias   Póstumas\u{200B} ") === 'Memórias Póstumas');
confere('sinal de negativo preservado', Texto::limpar('-2') === '-2');
confere('autor em ordem diferente tem a mesma chave', Texto::chaveAutor('ASSIS, Machado de') === Texto::chaveAutor('Machado de Assis'));
confere('artigo inicial ignorado na chave da obra', Texto::chaveObra('O Cortiço', 'Azevedo, Aluísio') === Texto::chaveObra('Cortiço', 'Aluísio Azevedo'));

echo "Mapeamento\n";
$cab = ['Localização na Estante', 'Cód. Autor', 'CDD', 'Sobrenome/Prenome do Autor', 'Título', 'Subtítulo', 'Assunto', 'ISBN', 'Quantidade', 'Nº'];
$map = $v->sugerirMapeamento($cab);
$esperado = ['Localização na Estante' => 'localizacao_antiga', 'Cód. Autor' => 'codigo_autor_antigo', 'CDD' => 'cdd_original',
    'Sobrenome/Prenome do Autor' => 'autor', 'Título' => 'titulo', 'Subtítulo' => 'subtitulo', 'Assunto' => 'assunto_original',
    'ISBN' => 'isbn', 'Quantidade' => 'quantidade_informada'];
confere('colunas padrão reconhecidas', array_intersect_key($map, $esperado) == $esperado, json_encode($map, JSON_UNESCAPED_UNICODE));
confere('mapeamento sem título é recusado', $v->validarMapeamento(['A' => 'autor']) !== []);
confere('coluna ignorada não entra nos dados', !in_array('Nº', array_keys($v->mapear(['Nº' => '9', 'Título' => 'X'], ['Nº' => 'ignorar', 'Título' => 'titulo'])), true)
    && !isset($v->mapear(['Nº' => '9'], ['Nº' => 'ignorar'])['ignorar']));
$extra = $v->mapear(['Título' => 'Duna', 'Doador' => 'Escola X'], ['Título' => 'titulo', 'Doador' => 'extra']);
confere('coluna extra preservada nas observações', str_contains($extra['observacoes'] ?? '', 'Doador: Escola X'));

echo "Validação\n";
$linha = fn (array $dados) => $analisar(array_combine($cab, array_pad($dados, count($cab), '')), $map);
[$n, $p] = $linha(['A-01-03', 'A848d', '869.3', 'ASSIS, Machado de', 'Dom Casmurro', '', 'Literatura brasileira', '9788594452603', '5']);
confere('linha completa é válida', ImportValidationService::situacao($p) === 'valido', json_encode(codigos($p)));
confere('CDD, código do autor e localização antigos preservados', $n['cdd_original'] === '869.3' && $n['codigo_autor_antigo'] === 'A848d' && $n['localizacao_antiga'] === 'A-01-03');
[, $p] = $linha(['E-01-01', '', '', 'Drummond de Andrade, Carlos', 'A Rosa do Povo']);
confere('sem ISBN e sem CDD não é erro', ImportValidationService::situacao($p) !== 'erro', json_encode(codigos($p)));
[$n, $p] = $linha(['B-02-01', '', 'LIT. BRAS.', 'Ramos, Graciliano', 'Vidas Secas']);
confere('CDD fora do padrão só informa e é mantida', ImportValidationService::situacao($p) === 'valido' && $n['cdd_original'] === 'LIT. BRAS.' && in_array('cdd_fora_padrao', codigos($p), true));
[, $p] = $linha(['C-01-02', '', 'abc']);
confere('linha sem título, autor e ISBN é erro', in_array('dados_insuficientes', codigos($p), true) && ImportValidationService::situacao($p) === 'erro');
[, $p] = $linha(['', '', '', 'Saint-Exupéry, Antoine de', 'O Pequeno Príncipe', '', '', '9788501001660']);
confere('ISBN inválido é erro', in_array('isbn_invalido', codigos($p), true));
[$n, $p] = $linha(['', '', '', 'Azevedo, Aluísio', 'O Cortiço', '', '', '', '-2']);
confere('quantidade negativa é sinalizada', in_array('quantidade_negativa', codigos($p), true), json_encode(codigos($p)));
[, $p] = $linha(['', '', '', 'Azevedo, Aluísio', 'O Cortiço', '', '', '', 'dois']);
confere('quantidade em texto é sinalizada', in_array('quantidade_invalida', codigos($p), true), json_encode(codigos($p)));
[, $p] = $linha(['', '', '', '9788535911237', 'Cortiço']);
confere('ISBN na coluna do autor indica colunas trocadas', in_array('valores_trocados', codigos($p), true), json_encode(codigos($p)));
[$n] = $linha(['', '', '', 'Ramos, Graciliano', '  Vidas   Secas ']);
confere('normalização limpa espaços e guarda o que foi limpo', $n['titulo'] === 'Vidas Secas' && in_array('titulo', $n['_limpezas'], true));
$corrigido = $v->aplicarCorrecoes(['titulo' => 'Dom Casmuro'], ['titulo' => 'Dom Casmurro']);
confere('correção aplicada sobre o valor bruto sem apagá-lo', ($corrigido['titulo'] ?? null) === 'Dom Casmurro');

echo "Duplicidades\n";
$linhas = [
    2 => ['A-01-03', 'A848d', '869.3', 'ASSIS, Machado de', 'Dom Casmurro', '', '', '9788594452603', '5'],
    3 => ['B-02-01', 'R175v', '869.3', 'RAMOS, Graciliano', 'Vidas Secas', '', '', '978-85-2209-083-9', '3'],
    4 => ['B-02-01', 'R175v', '869.3', 'RAMOS, Graciliano', 'Vidas Secas', '', '', '978-85-2209-083-9', '3'],
    5 => ['D-01-01', '', '', 'Collins, Suzanne', 'Jogos Vorazes', '', '', '9788579800245', '1'],
    6 => ['D-01-01', '', '', 'Collins, Suzanne', 'Jogos Vorazes', 'Em chamas', '', '9788579800245', '1'],
    7 => ['', '', '', 'Machado de Assis', 'Dom Casmurro'],
    8 => ['', '', '', 'Drummond de Andrade, Carlos', 'A Rosa do Povo'],
];
$itens = [];
foreach ($linhas as $num => $dados) {
    [$n] = $linha($dados);
    $itens[] = ['id' => $num, 'linha' => $num] + $v->chaves($n);
}
$acervo = ['isbn' => ['9788579800245' => 1], 'obra' => [Texto::chaveObra('A Rosa do Povo', 'Carlos Drummond de Andrade') => [2]], 'isbn_livro' => [1 => '9788579800245', 2 => null]];
$d = $v->analisarDuplicidades($itens, $acervo);
confere('linha idêntica', in_array('linha_identica', codigos($d[4]['problemas']), true) && $d[4]['linha_relacionada'] === 3);
confere('linha original avisa que tem cópias', in_array('possui_copias', codigos($d[3]['problemas']), true));
confere('mesmo ISBN na planilha', in_array('mesmo_isbn', codigos($d[6]['problemas']), true));
confere('título e autor semelhantes', in_array('obra_semelhante', codigos($d[7]['problemas']), true));
confere('já cadastrado pelo ISBN', $d[5]['ja_cadastrado'] && $d[5]['livro_sugerido_id'] === 1);
confere('já cadastrado por título e autor', $d[8]['ja_cadastrado'] && $d[8]['livro_sugerido_id'] === 2);
confere('nada é removido: todas as linhas continuam no resultado', count($d) === count($linhas));

echo "Leitura de arquivos\n";
$csv = "Título;Autor;ISBN;Quantidade;CDD\r\nEnsaio sobre a cegueira;Saramago, José;;1;869\r\n\r\nAção e reação;Silva, João;;3;\r\n";
file_put_contents("$tmp/acervo.csv", mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));
$tipo = $leitor->validarArquivo("$tmp/acervo.csv", 'acervo.csv');
$cabCsv = $leitor->cabecalhos("$tmp/acervo.csv", $tipo, null);
$lidas = $leitor->lerLinhas("$tmp/acervo.csv", $tipo, null, 2, 100, $cabCsv);
confere('CSV: cabeçalho lido', array_values($cabCsv) === ['Título', 'Autor', 'ISBN', 'Quantidade', 'CDD'], json_encode($cabCsv, JSON_UNESCAPED_UNICODE));
confere('CSV: Windows-1252 convertido e linha vazia ignorada', count($lidas) === 2 && in_array('Ação e reação', array_column($lidas, 'Título'), true), json_encode($lidas, JSON_UNESCAPED_UNICODE));

file_put_contents("$tmp/falso.xlsx", 'isto não é uma planilha');
$recusado = false;
try {
    $leitor->validarArquivo("$tmp/falso.xlsx", 'falso.xlsx');
} catch (Throwable $e) {
    $recusado = true;
}
confere('arquivo falso com extensão .xlsx é recusado', $recusado);
$recusado = false;
try {
    $leitor->validarArquivo("$tmp/acervo.csv", 'acervo.exe');
} catch (Throwable $e) {
    $recusado = true;
}
confere('extensão não permitida é recusada', $recusado);

if (ExcelImportService::xlsxDisponivel()) {
    $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $planilha->getActiveSheet()->setTitle('Instruções')->setCellValue('A1', 'Planilha antiga');
    $aba = $planilha->createSheet()->setTitle('Acervo 2019');
    $aba->fromArray([['Título', 'Sobrenome/Prenome do Autor', 'ISBN', 'CDD'], ['Memórias Póstumas de Brás Cubas', 'ASSIS, Machado de', '', '']], null, 'A1');
    $aba->setCellValueExplicit('C3', '9788594452603', 's');
    $aba->setCellValue('A3', 'Dom Casmurro');
    $aba->setCellValue('C2', 9788535911237);
    $aba->setCellValueExplicit('D2', '0869.3', 's');
    $aba->setCellValue('E1', 'Localização na (Estante)');
    $aba->setCellValue('E2', '="L945v"&"1A"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($planilha))->save("$tmp/acervo.xlsx");
    $tipo = $leitor->validarArquivo("$tmp/acervo.xlsx", 'acervo.xlsx');
    $abas = array_column($leitor->abas("$tmp/acervo.xlsx", $tipo), 'nome');
    confere('XLSX: várias abas listadas', $abas === ['Instruções', 'Acervo 2019'], json_encode($abas, JSON_UNESCAPED_UNICODE));
    $cabX = $leitor->cabecalhos("$tmp/acervo.xlsx", $tipo, 'Acervo 2019');
    $lidas = $leitor->lerLinhas("$tmp/acervo.xlsx", $tipo, 'Acervo 2019', 2, 100, $cabX);
    $primeira = $lidas[2] ?? [];
    confere('XLSX: aba escolhida lida com acentos', ($primeira['Título'] ?? '') === 'Memórias Póstumas de Brás Cubas', json_encode($lidas, JSON_UNESCAPED_UNICODE));
    confere('XLSX: ISBN numérico não vira notação científica', ($primeira['ISBN'] ?? '') === '9788535911237', (string)($primeira['ISBN'] ?? ''));
    confere('XLSX: CDD com zero à esquerda preservada', ($primeira['CDD'] ?? '') === '0869.3');
    confere('XLSX: fórmula vira o resultado salvo, não o texto da fórmula', ($primeira['Localização na (Estante)'] ?? '') === 'L945v1A', (string)($primeira['Localização na (Estante)'] ?? ''));
} else {
    echo "  (XLSX não testado: rode composer install)\n";
}

echo "\n" . ($falhas ? "$falhas de $total testes falharam.\n" : "Todos os $total testes passaram.\n");
exit($falhas ? 1 : 0);
