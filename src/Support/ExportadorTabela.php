<?php

namespace Biblioteca\Support;

use RuntimeException;
use ZipArchive;

/**
 * Exportação de tabelas grandes em CSV e XLSX, linha a linha (sem guardar tudo na memória).
 *
 * - CSV: UTF-8 com BOM e separador ";" (o Excel em português abre com os acentos certos).
 * - XLSX: a planilha é escrita aos poucos num arquivo temporário privado (pasta temporária do
 *   sistema, fora do site) e compactada no fim; o temporário é apagado em seguida.
 * - Injeção de fórmulas: texto que começa com =, +, -, @, tabulação ou retorno ganha um
 *   apóstrofo na frente (CSV). No XLSX todo texto é gravado como texto ("inlineStr"), que o
 *   Excel nunca executa; mesmo assim o mesmo prefixo é aplicado, para o arquivo ser seguro
 *   também quando copiado e colado.
 * - Códigos (ISBN, código BDP, matrícula, CDD): colunas marcadas como "codigo" ficam sempre
 *   como texto. No XLSX isso basta. No CSV, um código só com dígitos é escrito como ="0123",
 *   o único jeito de o Excel não remover zeros à esquerda nem usar notação científica.
 *   O conteúdo desses códigos é conferido (só dígitos e X) antes de receber esse formato.
 * - Colunas "numero" saem como número de verdade no XLSX (somas funcionam).
 */
final class ExportadorTabela
{
    /** Linhas lidas do banco por vez (o chamador decide; aqui só é o valor sugerido). */
    public const LOTE = 1000;

    /**
     * @param array<string, string> $colunas chave => rótulo do cabeçalho
     * @param array<string, string> $tipos   chave => 'texto' | 'codigo' | 'numero'
     */
    public function __construct(private array $colunas, private array $tipos = [])
    {
    }

    /** Texto seguro para planilhas (sem fórmula no início). */
    public static function celulaSegura(mixed $valor): string
    {
        $valor = (string)($valor ?? '');
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
    }

    /** Nome de arquivo só com letras simples, números e hífens. */
    public static function nomeArquivo(string $nome): string
    {
        $nome = Texto::semAcento(mb_strtolower($nome));
        $nome = preg_replace('/[^a-z0-9]+/', '-', $nome) ?? 'relatorio';
        return trim(substr($nome, 0, 120), '-') ?: 'relatorio';
    }

    /**
     * Escreve o CSV num recurso aberto (php://output no download, php://memory nos testes).
     * @param iterable<array<string, mixed>> $linhas
     */
    public function escreverCsv($saida, iterable $linhas): int
    {
        fwrite($saida, "\xEF\xBB\xBF");
        fputcsv($saida, array_values($this->colunas), ';', '"', '');
        $n = 0;
        foreach ($linhas as $linha) {
            $celulas = [];
            foreach ($this->colunas as $chave => $_) {
                $celulas[] = $this->celulaCsv($chave, $linha[$chave] ?? '');
            }
            fputcsv($saida, $celulas, ';', '"', '');
            if (++$n % 500 === 0) {
                fflush($saida);
            }
        }
        return $n;
    }

    private function celulaCsv(string $chave, mixed $valor): string
    {
        $valor = (string)($valor ?? '');
        $tipo = $this->tipos[$chave] ?? 'texto';
        if ($tipo === 'numero' && preg_match('/^-?\d+([.,]\d+)?$/', $valor)) {
            return $valor;
        }
        if ($tipo === 'codigo' && preg_match('/^\d+X?$/i', $valor)) {
            return '="' . $valor . '"';
        }
        return self::celulaSegura($valor);
    }

    /**
     * Gera o XLSX num arquivo temporário e devolve o caminho (o chamador envia e apaga).
     * @param iterable<array<string, mixed>> $linhas
     * @return array{0: string, 1: int} caminho e quantidade de linhas
     */
    public function gerarXlsx(iterable $linhas, string $aba = 'Relatório', array $cabecalhoExtra = []): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('A extensão zip do PHP não está ativa.');
        }
        $folha = tempnam(sys_get_temp_dir(), 'bdprel');
        $f = fopen($folha, 'wb');
        $colunas = array_keys($this->colunas);
        $linhaAtual = 0;
        $escreverLinha = function (array $valores, ?int $estilo = null) use ($f, &$linhaAtual, $colunas): void {
            $linhaAtual++;
            $xml = '<row r="' . $linhaAtual . '">';
            foreach (array_values($valores) as $i => [$valor, $tipo]) {
                $ref = self::coluna($i) . $linhaAtual;
                $s = $estilo !== null ? ' s="' . $estilo . '"' : '';
                if ($tipo === 'numero' && preg_match('/^-?\d+(\.\d+)?$/', (string)$valor)) {
                    $xml .= '<c r="' . $ref . '"' . ($estilo !== null ? $s : ' s="2"') . '><v>' . $valor . '</v></c>';
                } else {
                    $texto = $tipo === 'codigo' ? (string)$valor : self::celulaSegura($valor);
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . self::xml($texto) . '</t></is></c>';
                }
            }
            fwrite($f, $xml . '</row>');
        };

        fwrite($f, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="' . (count($cabecalhoExtra) + 1) . '" topLeftCell="A' . (count($cabecalhoExtra) + 2) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>');
        foreach ($colunas as $i => $chave) {
            $largura = ($this->tipos[$chave] ?? '') === 'numero' ? 14 : (in_array($chave, ['titulo', 'livro', 'resumo', 'acao'], true) ? 48 : 22);
            fwrite($f, '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $largura . '" customWidth="1"/>');
        }
        fwrite($f, '</cols><sheetData>');
        // Linhas de identificação (biblioteca, relatório, período, filtros) antes do cabeçalho.
        foreach ($cabecalhoExtra as $texto) {
            $escreverLinha([[$texto, 'texto']], 3);
        }
        $escreverLinha(array_map(fn ($r) => [$r, 'texto'], array_values($this->colunas)), 1);
        $n = 0;
        foreach ($linhas as $linha) {
            $valores = [];
            foreach ($colunas as $chave) {
                $valores[] = [$linha[$chave] ?? '', $this->tipos[$chave] ?? 'texto'];
            }
            $escreverLinha($valores);
            $n++;
        }
        $filtro = '<autoFilter ref="A' . (count($cabecalhoExtra) + 1) . ':' . self::coluna(max(0, count($colunas) - 1)) . max(count($cabecalhoExtra) + 1, $linhaAtual) . '"/>';
        fwrite($f, '</sheetData>' . $filtro . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>');
        fclose($f);

        $arquivo = tempnam(sys_get_temp_dir(), 'bdpxlsx');
        $zip = new ZipArchive();
        if ($zip->open($arquivo, ZipArchive::OVERWRITE) !== true) {
            @unlink($folha);
            throw new RuntimeException('Não foi possível montar a planilha.');
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
        $nomeAba = self::xml(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $aba) ?: 'Relatório', 0, 31));
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $nomeAba . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $nomeAba . '\'!$A$' . (count($cabecalhoExtra) + 1) . ':$' . self::coluna(max(0, count($colunas) - 1)) . '$' . max(count($cabecalhoExtra) + 1, $linhaAtual) . '</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Estilos: 0 texto (@), 1 cabeçalho em negrito com fundo, 2 número, 3 identificação em itálico.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDE7FC"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            . '</cellXfs></styleSheet>');
        $zip->addFile($folha, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($folha);
        return [$arquivo, $n];
    }

    private static function xml(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '';
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function coluna(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }
}
