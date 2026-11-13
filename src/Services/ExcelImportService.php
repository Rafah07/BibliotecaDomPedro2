<?php

namespace Biblioteca\Services;

use Biblioteca\Support\LeitorXlsx;
use RuntimeException;

/**
 * Leitura das planilhas enviadas (.xlsx e .csv), sem bibliotecas externas.
 * O .xlsx é lido pelo {@see LeitorXlsx} (funciona no XAMPP sem "composer install").
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

    /** Última planilha .xlsx aberta (as etapas da importação leem o mesmo arquivo várias vezes). */
    private ?LeitorXlsx $leitor = null;
    private string $chaveLeitor = '';

    public static function xlsxDisponivel(): bool
    {
        return LeitorXlsx::disponivel();
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
                throw new RuntimeException('Este PHP não consegue ler .xlsx (faltam as extensões xmlreader e zip/zlib). Enquanto isso, salve a planilha como CSV.');
            }
            $leitor = new LeitorXlsx($caminho);
            $total = 0;
            $temWorkbook = false;
            foreach ($leitor->entradas() as $nome => $tamanhoDescompactado) {
                $total += $tamanhoDescompactado;
                if ($nome === 'xl/workbook.xml') {
                    $temWorkbook = true;
                }
                // Macros e objetos incorporados não são aceitos.
                if (preg_match('#(vbaProject\.bin|/embeddings/|\.\.)#i', $nome)) {
                    throw new RuntimeException('A planilha contém macros ou arquivos incorporados. Salve uma cópia simples (.xlsx sem macros) e envie novamente.');
                }
            }
            if (!$temWorkbook) {
                throw new RuntimeException('O arquivo não parece ser uma planilha do Excel (.xlsx) válida.');
            }
            if ($total > self::DESCOMPACTADO_MAXIMO) {
                throw new RuntimeException('A planilha é grande demais para ser processada. Divida-a em partes menores.');
            }
            $leitor->nomesDasAbas(); // confere se a planilha abre de verdade
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
        return array_map(fn ($a) => ['colunas' => min($a['colunas'], self::MAX_COLUNAS)] + $a, $this->leitorXlsx($caminho)->abas());
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
            $celulas = $this->leitorXlsx($caminho)->linhas($aba, $inicio, $fim, self::MAX_COLUNAS);
            $ultimaColuna = 0;
            foreach ($celulas as $valores) {
                $ultimaColuna = max($ultimaColuna, max(array_keys($valores)));
            }
            foreach ($celulas as $numero => $valores) {
                $linha = [];
                for ($c = 1; $c <= $ultimaColuna; $c++) {
                    $linha[self::letra($c)] = $valores[$c] ?? '';
                }
                $resultado[$numero] = $linha;
            }
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

    private function leitorXlsx(string $caminho): LeitorXlsx
    {
        if (!self::xlsxDisponivel()) {
            throw new RuntimeException('Este PHP não consegue ler .xlsx (faltam as extensões xmlreader e zip/zlib). Salve a planilha como CSV.');
        }
        clearstatcache(true, $caminho);
        $chave = $caminho . '|' . @filesize($caminho) . '|' . @filemtime($caminho);
        if ($this->leitor === null || $this->chaveLeitor !== $chave) {
            $this->leitor = new LeitorXlsx($caminho);
            $this->chaveLeitor = $chave;
        }
        return $this->leitor;
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
