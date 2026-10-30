<?php

namespace Biblioteca\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * Leitura das planilhas enviadas (.xlsx com PhpSpreadsheet e .csv nativo).
 *
 * Os valores são devolvidos como texto, exatamente como estão nas células
 * (números inteiros grandes, como ISBN, não viram notação científica).
 * A leitura é feita por blocos de linhas, para suportar arquivos grandes.
 */
final class ExcelImportService
{
    public const TAMANHO_MAXIMO = 20 * 1024 * 1024;        // 20 MB
    private const DESCOMPACTADO_MAXIMO = 250 * 1024 * 1024; // proteção contra "zip bomb"
    private const MAX_COLUNAS = 60;

    public static function xlsxDisponivel(): bool
    {
        return class_exists(Xlsx::class);
    }

    /**
     * Confere se o arquivo enviado é mesmo uma planilha aceita. Devolve o tipo ('xlsx' | 'csv').
     * @throws RuntimeException com mensagem segura para mostrar ao usuário
     */
    public function validarArquivo(string $caminho, string $nomeOriginal): string
    {
        $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        if (!in_array($extensao, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('Formato não aceito. Envie um arquivo .xlsx ou .csv.');
        }
        $tamanho = filesize($caminho);
        if ($tamanho === false || $tamanho === 0) {
            throw new RuntimeException('O arquivo está vazio.');
        }
        if ($tamanho > self::TAMANHO_MAXIMO) {
            throw new RuntimeException('O arquivo passa de 20 MB. Divida a planilha em partes menores.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: '';

        if ($extensao === 'xlsx') {
            if (!in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'], true)) {
                throw new RuntimeException('O arquivo não parece ser uma planilha do Excel (.xlsx) válida.');
            }
            if (!self::xlsxDisponivel()) {
                throw new RuntimeException('A leitura de .xlsx precisa da biblioteca PhpSpreadsheet (rode "composer install"). Enquanto isso, salve a planilha como CSV.');
            }
            $zip = new ZipArchive();
            if ($zip->open($caminho, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('O arquivo .xlsx está corrompido ou não pôde ser aberto.');
            }
            $total = 0;
            $temWorkbook = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                $total += $info['size'];
                $nome = $info['name'];
                if ($nome === 'xl/workbook.xml') {
                    $temWorkbook = true;
                }
                // Macros e objetos incorporados não são aceitos.
                if (preg_match('#(vbaProject\.bin|/embeddings/|\.\.)#i', $nome)) {
                    $zip->close();
                    throw new RuntimeException('A planilha contém macros ou arquivos incorporados. Salve uma cópia simples (.xlsx sem macros) e envie novamente.');
                }
            }
            $zip->close();
            if (!$temWorkbook) {
                throw new RuntimeException('O arquivo não parece ser uma planilha do Excel (.xlsx) válida.');
            }
            if ($total > self::DESCOMPACTADO_MAXIMO) {
                throw new RuntimeException('A planilha é grande demais para ser processada. Divida-a em partes menores.');
            }
            return 'xlsx';
        }

        if (!preg_match('#^(text/|application/(csv|vnd\.ms-excel|octet-stream))#', $mime)) {
            throw new RuntimeException('O arquivo não parece ser um CSV de texto.');
        }
        $amostra = (string)file_get_contents($caminho, false, null, 0, 8192);
        if (str_contains($amostra, "\0")) {
            throw new RuntimeException('O arquivo CSV contém dados binários e não pode ser lido.');
        }
        return 'csv';
    }

    /**
     * Abas disponíveis com a quantidade de linhas e colunas.
     * @return array<int, array{nome: string, linhas: int, colunas: int}>
     */
    public function abas(string $caminho, string $tipo): array
    {
        if ($tipo === 'csv') {
            $csv = $this->abrirCsv($caminho);
            $linhas = 0;
            $colunas = 0;
            foreach ($this->iterarCsv($csv) as $valores) {
                $linhas++;
                $colunas = max($colunas, count($valores));
            }
            return [['nome' => 'CSV', 'linhas' => $linhas, 'colunas' => min($colunas, self::MAX_COLUNAS)]];
        }
        $reader = $this->leitorXlsx();
        $abas = [];
        foreach ($reader->listWorksheetInfo($caminho) as $info) {
            $abas[] = [
                'nome' => (string)$info['worksheetName'],
                'linhas' => (int)$info['totalRows'],
                'colunas' => min((int)$info['totalColumns'], self::MAX_COLUNAS),
            ];
        }
        return $abas;
    }

    /**
     * Cabeçalhos da linha indicada. Colunas sem título recebem "Coluna X".
     * @return array<string, string> letra da coluna => cabeçalho (sempre único)
     */
    public function cabecalhos(string $caminho, string $tipo, ?string $aba, int $linhaCabecalho = 1): array
    {
        $linha = $this->lerLinhas($caminho, $tipo, $aba, $linhaCabecalho, 1, null)[$linhaCabecalho] ?? [];
        $cabecalhos = [];
        $usados = [];
        foreach ($linha as $letra => $valor) {
            $titulo = trim(preg_replace('/\s+/u', ' ', (string)$valor));
            if ($titulo === '') {
                $titulo = 'Coluna ' . $letra;
            }
            $base = $titulo;
            $n = 2;
            while (isset($usados[mb_strtolower($titulo)])) {
                $titulo = $base . ' (' . $n++ . ')';
            }
            $usados[mb_strtolower($titulo)] = true;
            $cabecalhos[$letra] = $titulo;
        }
        // Remove colunas vazias no fim (sem cabeçalho).
        while ($cabecalhos && str_starts_with(end($cabecalhos), 'Coluna ') && preg_match('/^Coluna [A-Z]+$/', end($cabecalhos))) {
            array_pop($cabecalhos);
        }
        return $cabecalhos;
    }

    /**
     * Lê um bloco de linhas.
     * @param array<string, string>|null $cabecalhos quando informado, devolve [cabeçalho => valor]; senão [letra => valor]
     * @return array<int, array<string, string>> número da linha original => valores (linhas totalmente vazias são omitidas)
     */
    public function lerLinhas(string $caminho, string $tipo, ?string $aba, int $inicio, int $quantidade, ?array $cabecalhos): array
    {
        $fim = $inicio + $quantidade - 1;
        $resultado = [];

        if ($tipo === 'csv') {
            $csv = $this->abrirCsv($caminho);
            $numero = 0;
            foreach ($this->iterarCsv($csv) as $valores) {
                $numero++;
                if ($numero < $inicio) {
                    continue;
                }
                if ($numero > $fim) {
                    break;
                }
                $linha = [];
                foreach (array_slice($valores, 0, self::MAX_COLUNAS) as $i => $valor) {
                    $linha[self::letra($i + 1)] = $valor;
                }
                $resultado[$numero] = $linha;
            }
        } else {
            $reader = $this->leitorXlsx();
            if ($aba !== null) {
                $reader->setLoadSheetsOnly([$aba]);
            }
            $reader->setReadFilter(new FiltroDeLinhas($inicio, $fim, self::MAX_COLUNAS));
            $planilha = $reader->load($caminho);
            $folha = $aba !== null ? $planilha->getSheetByName($aba) : $planilha->getActiveSheet();
            if ($folha === null) {
                throw new RuntimeException('A aba selecionada não foi encontrada na planilha.');
            }
            $ultimaColuna = min(Coordinate::columnIndexFromString($folha->getHighestDataColumn()), self::MAX_COLUNAS);
            $ultimaLinha = min($folha->getHighestDataRow(), $fim);
            for ($numero = $inicio; $numero <= $ultimaLinha; $numero++) {
                $linha = [];
                for ($c = 1; $c <= $ultimaColuna; $c++) {
                    $letra = Coordinate::stringFromColumnIndex($c);
                    $celula = $folha->getCell($letra . $numero);
                    $linha[$letra] = $this->textoDaCelula($celula->getValue());
                }
                $resultado[$numero] = $linha;
            }
            $planilha->disconnectWorksheets();
            unset($planilha);
        }

        foreach ($resultado as $numero => $linha) {
            if (implode('', array_map('trim', $linha)) === '') {
                unset($resultado[$numero]);
                continue;
            }
            if ($cabecalhos !== null) {
                $porCabecalho = [];
                foreach ($cabecalhos as $letra => $titulo) {
                    $porCabecalho[$titulo] = $linha[$letra] ?? '';
                }
                // Valores fora das colunas com cabeçalho também são preservados.
                foreach ($linha as $letra => $valor) {
                    if (!isset($cabecalhos[$letra]) && trim($valor) !== '') {
                        $porCabecalho['Coluna ' . $letra] = $valor;
                    }
                }
                $resultado[$numero] = $porCabecalho;
            }
        }
        return $resultado;
    }

    /** Valor de célula como texto, sem perder dígitos de números inteiros grandes. */
    private function textoDaCelula(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if ($valor instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            return $valor->getPlainText();
        }
        if (is_bool($valor)) {
            return $valor ? 'VERDADEIRO' : 'FALSO';
        }
        if (is_float($valor)) {
            if (floor($valor) === $valor && abs($valor) < 1e15) {
                return sprintf('%.0f', $valor);
            }
            return rtrim(rtrim(sprintf('%.10F', $valor), '0'), '.');
        }
        $texto = (string)$valor;
        // Fórmulas não são executadas: guardamos o texto da fórmula como veio.
        return $texto;
    }

    /** Letra da coluna (1 → A, 27 → AA), sem depender do PhpSpreadsheet. */
    public static function letra(int $indice): string
    {
        $letra = '';
        while ($indice > 0) {
            $resto = ($indice - 1) % 26;
            $letra = chr(65 + $resto) . $letra;
            $indice = intdiv($indice - 1, 26);
        }
        return $letra;
    }

    private function leitorXlsx(): Xlsx
    {
        if (!self::xlsxDisponivel()) {
            throw new RuntimeException('A leitura de .xlsx precisa da biblioteca PhpSpreadsheet (rode "composer install").');
        }
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        return $reader;
    }

    // ---------------------------------------------------------------
    // CSV
    // ---------------------------------------------------------------

    /** @return array{caminho: string, delimitador: string, converter: bool} */
    private function abrirCsv(string $caminho): array
    {
        $amostra = (string)file_get_contents($caminho, false, null, 0, 65536);
        $semBom = str_starts_with($amostra, "\xEF\xBB\xBF") ? substr($amostra, 3) : $amostra;
        // Planilhas antigas salvas no Excel em português costumam vir em Windows-1252.
        $converter = !mb_check_encoding($this->cortarUtf8($semBom), 'UTF-8');
        $primeiraLinha = strtok($semBom, "\n") ?: '';
        $contagem = [];
        foreach ([';', ',', "\t", '|'] as $d) {
            $contagem[$d] = substr_count($primeiraLinha, $d);
        }
        arsort($contagem);
        $delimitador = (string)array_key_first($contagem);
        if ($contagem[$delimitador] === 0) {
            $delimitador = ';';
        }
        return ['caminho' => $caminho, 'delimitador' => $delimitador, 'converter' => $converter];
    }

    /** Linhas do CSV (inclusive vazias, para manter a numeração original). */
    private function iterarCsv(array $csv): \Generator
    {
        $arquivo = fopen($csv['caminho'], 'r');
        if ($arquivo === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo CSV.');
        }
        try {
            $primeira = true;
            while (($valores = fgetcsv($arquivo, null, $csv['delimitador'], '"', '')) !== false) {
                if ($valores === [null]) {
                    $valores = [''];
                }
                $valores = array_map(function ($v) use ($csv) {
                    $v = (string)$v;
                    return $csv['converter'] ? mb_convert_encoding($v, 'UTF-8', 'Windows-1252') : $v;
                }, $valores);
                if ($primeira) {
                    $valores[0] = preg_replace('/^\xEF\xBB\xBF/', '', $valores[0]);
                    $primeira = false;
                }
                yield $valores;
            }
        } finally {
            fclose($arquivo);
        }
    }

    /** Evita acusar encoding errado quando a amostra corta um caractere UTF-8 no meio. */
    private function cortarUtf8(string $texto): string
    {
        return preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $texto);
    }
}
