<?php
/**
 * Gera planilhas .xlsx fictícias para os testes, sem PhpSpreadsheet e sem a extensão zip
 * (o .zip é montado à mão com a zlib). Só para testes: nunca use com dados reais.
 *
 * $abas = ['Nome da aba' => [linha => [coluna ('A'...) => valor]], ...]
 * Valores: string → texto compartilhado; int/float → número; bool → booleano;
 * ['formula' => 'A1&B1', 'resultado' => 'texto'] → fórmula com resultado salvo;
 * ['inline' => 'texto'] → texto em linha; ['rico' => ['parte 1', 'parte 2']] → texto com formatação.
 * Opções: 'ativa' => índice da aba ativa; 'oculta' => [nomes]; 'sem_ref' => true grava linhas/células sem "r";
 * 'armazenar' => true grava sem compressão; 'extra' => [caminho interno => conteúdo].
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function xlsx_ficticio(string $destino, array $abas, array $opcoes = []): void
{
    $esc = fn ($t) => htmlspecialchars((string)$t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $textos = [];
    $indiceTexto = function (string $t) use (&$textos): int {
        if (!array_key_exists($t, $textos)) {
            $textos[$t] = count($textos);
        }
        return $textos[$t];
    };
    $semRef = !empty($opcoes['sem_ref']);
    $arquivos = [];
    $listaAbas = '';
    $rels = '';
    $tipos = '';
    $n = 0;
    foreach ($abas as $nome => $linhas) {
        $n++;
        ksort($linhas);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>';
        foreach ($linhas as $numero => $celulas) {
            uksort($celulas, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
            $xml .= $semRef ? '<row>' : '<row r="' . $numero . '">';
            foreach ($celulas as $col => $valor) {
                $r = $semRef ? '' : ' r="' . $col . $numero . '"';
                if (is_array($valor) && isset($valor['formula'])) {
                    $res = $valor['resultado'] ?? null;
                    $xml .= $res === null
                        ? "<c$r><f>" . $esc($valor['formula']) . '</f></c>'
                        : (is_string($res) ? "<c$r t=\"str\"><f>" . $esc($valor['formula']) . '</f><v>' . $esc($res) . '</v></c>'
                            : "<c$r><f>" . $esc($valor['formula']) . '</f><v>' . $res . '</v></c>');
                } elseif (is_array($valor) && isset($valor['inline'])) {
                    $xml .= "<c$r t=\"inlineStr\"><is><t xml:space=\"preserve\">" . $esc($valor['inline']) . '</t></is></c>';
                } elseif (is_array($valor) && isset($valor['rico'])) {
                    $xml .= "<c$r t=\"s\"><v>" . $indiceTexto("\0rico\0" . json_encode($valor['rico'])) . '</v></c>';
                } elseif (is_bool($valor)) {
                    $xml .= "<c$r t=\"b\"><v>" . ($valor ? 1 : 0) . '</v></c>';
                } elseif (is_int($valor) || is_float($valor)) {
                    $xml .= "<c$r><v>" . (is_float($valor) ? var_export($valor, true) : $valor) . '</v></c>';
                } elseif ($valor === null) {
                    $xml .= "<c$r/>";
                } else {
                    $xml .= "<c$r t=\"s\"><v>" . $indiceTexto((string)$valor) . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $arquivos["xl/worksheets/sheet$n.xml"] = $xml;
        $estado = in_array($nome, $opcoes['oculta'] ?? [], true) ? ' state="hidden"' : '';
        $listaAbas .= '<sheet name="' . $esc($nome) . '" sheetId="' . $n . '"' . $estado . ' r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $tipos .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $si = '';
    foreach (array_keys($textos) as $t) {
        $t = (string)$t;
        if (str_starts_with($t, "\0rico\0")) {
            $si .= '<si>';
            foreach (json_decode(substr($t, 6), true) as $parte) {
                $si .= '<r><rPr><b/></rPr><t xml:space="preserve">' . $esc($parte) . '</t></r>';
            }
            $si .= '<rPh sb="0" eb="1"><t>フリガナ</t></rPh></si>';
        } else {
            $si .= '<si><t xml:space="preserve">' . $esc($t) . '</t></si>';
        }
    }
    $arquivos['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($textos) . '" uniqueCount="' . count($textos) . '">' . $si . '</sst>';
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $ativa = (int)($opcoes['ativa'] ?? 0);
    $arquivos['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView activeTab="' . $ativa . '"/></bookViews><sheets>' . $listaAbas . '</sheets></workbook>';
    $arquivos['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    $arquivos['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $arquivos['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $tipos . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';
    foreach ($opcoes['extra'] ?? [] as $caminho => $conteudo) {
        $arquivos[$caminho] = $conteudo;
    }
    file_put_contents($destino, zip_ficticio($arquivos, !empty($opcoes['armazenar'])));
}

/** Monta um .zip simples (deflate ou sem compressão) só com a zlib. $tamanhoDeclarado simula um "zip bomb". */
function zip_ficticio(array $arquivos, bool $armazenar = false, array $tamanhoDeclarado = []): string
{
    $dados = '';
    $diretorio = '';
    foreach ($arquivos as $nome => $conteudo) {
        $metodo = $armazenar ? 0 : 8;
        $compactado = $armazenar ? $conteudo : gzdeflate($conteudo, 6);
        $crc = crc32($conteudo);
        $local = strlen($dados);
        $comum = pack('vvvvvVVV', 20, 0x0800, $metodo, 0, 0x21, $crc, strlen($compactado), $tamanhoDeclarado[$nome] ?? strlen($conteudo)) . pack('v', strlen($nome));
        $dados .= "PK\x03\x04" . $comum . pack('v', 0) . $nome . $compactado;
        $diretorio .= "PK\x01\x02" . pack('v', 20) . $comum . pack('vvvvVV', 0, 0, 0, 0, 0, $local) . $nome;
    }
    return $dados . $diretorio . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($arquivos), count($arquivos), strlen($diretorio), strlen($dados), 0);
}
