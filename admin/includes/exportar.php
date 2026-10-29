<?php
/**
 * Exportação de tabelas em CSV (compatível com Excel em português) e XLSX.
 * O XLSX é montado sem bibliotecas externas (planilha simples, texto como texto,
 * para que CDDs como "028.5" não percam o zero nem o ponto).
 */

/** Envia um CSV com separador ";" e BOM UTF-8 (o Excel brasileiro abre direto). */
function exportar_csv(string $arquivo, array $cabecalho, array $linhas): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $arquivo . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $cabecalho, ';', '"', '');
    foreach ($linhas as $linha) {
        fputcsv($out, array_map('exportar_celula_segura', $linha), ';', '"', '');
    }
    fclose($out);
    exit;
}

/** Evita injeção de fórmulas ao abrir no Excel (=, +, -, @ no início da célula). */
function exportar_celula_segura($valor): string
{
    $valor = (string)($valor ?? '');
    return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
}

/** Envia um .xlsx. Sem a extensão zip do PHP, envia CSV no lugar. */
function exportar_xlsx(string $arquivo, string $aba, array $cabecalho, array $linhas): never
{
    if (!class_exists('ZipArchive')) {
        exportar_csv($arquivo, $cabecalho, $linhas);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bdpxlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    // Texto em célula "inlineStr" nunca é interpretado como fórmula, então não precisa de prefixo.
    $esc = fn ($v) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)($v ?? '')), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $coluna = function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    };

    $linhasXml = '';
    foreach (array_merge([$cabecalho], $linhas) as $r => $linha) {
        $linhasXml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($linha) as $c => $valor) {
            $ref = $coluna($c) . ($r + 1);
            $estilo = $r === 0 ? ' s="1"' : '';
            $linhasXml .= '<c r="' . $ref . '" t="inlineStr"' . $estilo . '><is><t xml:space="preserve">' . $esc($valor) . '</t></is></c>';
        }
        $linhasXml .= '</row>';
    }
    $largura = '';
    foreach ($cabecalho as $c => $_) {
        $largura .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="22" customWidth="1"/>';
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $esc(mb_substr($aba, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/></cellXfs>'
        . '</styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $largura . '</cols><sheetData>' . $linhasXml . '</sheetData></worksheet>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $arquivo . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}
