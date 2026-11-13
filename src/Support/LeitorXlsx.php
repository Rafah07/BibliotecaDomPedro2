<?php

namespace Biblioteca\Support;

use RuntimeException;
use XMLReader;

/**
 * Leitor próprio de planilhas .xlsx, sem bibliotecas externas.
 *
 * Abre o .xlsx (que é um .zip) com {@see ArquivoZip} e percorre o XML das abas com
 * o XMLReader, linha a linha, sem montar a planilha inteira na memória. Devolve os
 * valores como texto, do jeito que estão salvos nas células: fórmulas não são
 * executadas (vale o resultado que o Excel guardou) e números inteiros grandes,
 * como ISBN, não viram notação científica.
 */
final class LeitorXlsx
{
    private const NS_REL = ['http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'http://purl.oclc.org/ooxml/officeDocument/relationships'];

    /** @var array<int, array{nome: string, caminho: string}>|null */
    private ?array $abas = null;
    private int $abaAtiva = 0;
    private ?string $caminhoTextos = null;
    /** @var array<int, string>|null */
    private ?array $textos = null;
    private bool $errosAnteriores = false;

    public function __construct(private string $caminho, private bool $usarExtensaoZip = true)
    {
    }

    /** Leitura do .xlsx disponível neste PHP (precisa do XMLReader e da extensão zip ou da zlib). */
    public static function disponivel(): bool
    {
        return class_exists(XMLReader::class) && (class_exists(\ZipArchive::class) || function_exists('gzinflate'));
    }

    /** @return array<string, int> arquivos internos do .xlsx => tamanho descompactado */
    public function entradas(): array
    {
        $zip = $this->zip();
        try {
            return $zip->entradas();
        } finally {
            $zip->fechar();
        }
    }

    /** @return list<string> nomes das abas, na ordem da planilha */
    public function nomesDasAbas(): array
    {
        return array_column($this->mapaDeAbas(), 'nome');
    }

    /**
     * Abas com a última linha e a última coluna que têm algum valor.
     * @return list<array{nome: string, linhas: int, colunas: int}>
     */
    public function abas(): array
    {
        $resultado = [];
        foreach ($this->mapaDeAbas() as $aba) {
            $linhas = 0;
            $colunas = 0;
            $this->percorrer($aba['caminho'], 1, PHP_INT_MAX, PHP_INT_MAX, function (int $numero, int $coluna) use (&$linhas, &$colunas) {
                $linhas = max($linhas, $numero);
                $colunas = max($colunas, $coluna);
            });
            $resultado[] = ['nome' => $aba['nome'], 'linhas' => $linhas, 'colunas' => $colunas];
        }
        return $resultado;
    }

    /**
     * Valores das linhas $inicio..$fim de uma aba (null = a aba ativa da planilha).
     * Só aparecem as células com valor; colunas além de $maxColunas são ignoradas.
     * @return array<int, array<int, string>> número da linha => [número da coluna (1 = A) => valor]
     */
    public function linhas(?string $aba, int $inicio, int $fim, int $maxColunas): array
    {
        $mapa = $this->mapaDeAbas();
        if ($aba === null) {
            $alvo = $mapa[$this->abaAtiva] ?? $mapa[0];
        } else {
            $alvo = null;
            foreach ($mapa as $item) {
                if ($item['nome'] === $aba) {
                    $alvo = $item;
                    break;
                }
            }
            if ($alvo === null) {
                throw new RuntimeException('A aba selecionada não foi encontrada na planilha.');
            }
        }
        $resultado = [];
        $this->percorrer($alvo['caminho'], $inicio, $fim, $maxColunas, function (int $numero, int $coluna, string $valor) use (&$resultado) {
            $resultado[$numero][$coluna] = $valor;
        });
        ksort($resultado);
        return $resultado;
    }

    // ---------------------------------------------------------------
    // Estrutura da planilha
    // ---------------------------------------------------------------

    private function zip(): ArquivoZip
    {
        return new ArquivoZip($this->caminho, $this->usarExtensaoZip);
    }

    /** @return array<int, array{nome: string, caminho: string}> */
    private function mapaDeAbas(): array
    {
        if ($this->abas !== null) {
            return $this->abas;
        }
        $zip = $this->zip();
        try {
            $workbook = $zip->ler('xl/workbook.xml');
            if ($workbook === null) {
                throw new RuntimeException('O arquivo não parece ser uma planilha do Excel (.xlsx) válida.');
            }
            $alvos = [];
            $rels = $zip->ler('xl/_rels/workbook.xml.rels');
            if ($rels !== null) {
                $r = $this->xml($rels);
                while ($r->read()) {
                    if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship' && $r->getAttribute('TargetMode') !== 'External') {
                        $tipo = (string)$r->getAttribute('Type');
                        $alvo = self::resolverCaminho((string)$r->getAttribute('Target'));
                        $alvos[(string)$r->getAttribute('Id')] = ['tipo' => substr($tipo, (int)strrpos($tipo, '/') + 1), 'caminho' => $alvo];
                        if (str_ends_with($tipo, '/sharedStrings')) {
                            $this->caminhoTextos = $alvo;
                        }
                    }
                }
                $this->fecharXml($r);
            }
            $this->caminhoTextos ??= $zip->existe('xl/sharedStrings.xml') ? 'xl/sharedStrings.xml' : null;

            $abas = [];
            $r = $this->xml($workbook);
            $posicao = 0;
            while ($r->read()) {
                if ($r->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($r->localName === 'workbookView' && $r->getAttribute('activeTab') !== null) {
                    $this->abaAtiva = max(0, (int)$r->getAttribute('activeTab'));
                } elseif ($r->localName === 'sheet') {
                    $posicao++;
                    $id = self::atributoRelacao($r);
                    $alvo = $id !== null ? ($alvos[$id] ?? null) : null;
                    if ($alvo !== null && $alvo['tipo'] !== 'worksheet') {
                        continue; // abas só de gráfico ou de macros não têm células
                    }
                    $caminho = $alvo['caminho'] ?? 'xl/worksheets/sheet' . $posicao . '.xml';
                    if (!$zip->existe($caminho)) {
                        continue;
                    }
                    $abas[] = ['nome' => (string)$r->getAttribute('name'), 'caminho' => $caminho];
                }
            }
            $this->fecharXml($r);
        } finally {
            $zip->fechar();
        }
        if (!$abas) {
            throw new RuntimeException('A planilha não tem nenhuma aba com dados.');
        }
        if (!isset($abas[$this->abaAtiva])) {
            $this->abaAtiva = 0;
        }
        return $this->abas = $abas;
    }

    /** Textos compartilhados (o Excel guarda cada texto uma vez e as células apontam para ele). */
    private function textos(ArquivoZip $zip): array
    {
        if ($this->textos !== null) {
            return $this->textos;
        }
        $this->textos = [];
        $xml = $this->caminhoTextos !== null ? $zip->ler($this->caminhoTextos) : null;
        if ($xml === null || $xml === '') {
            return $this->textos;
        }
        $r = $this->xml($xml);
        $atual = null;
        $fonetica = 0;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') {
                    $atual = '';
                    if ($r->isEmptyElement) {
                        $this->textos[] = '';
                        $atual = null;
                    }
                } elseif ($r->localName === 'rPh' && !$r->isEmptyElement) {
                    $fonetica++;
                } elseif ($r->localName === 't' && $atual !== null && $fonetica === 0 && !$r->isEmptyElement) {
                    $atual .= $r->readString();
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 'rPh') {
                    $fonetica = max(0, $fonetica - 1);
                } elseif ($r->localName === 'si' && $atual !== null) {
                    $this->textos[] = self::decodificar($atual);
                    $atual = null;
                }
            }
        }
        $this->fecharXml($r);
        return $this->textos;
    }

    // ---------------------------------------------------------------
    // Células
    // ---------------------------------------------------------------

    /**
     * Chama $aoLer(linha, coluna, valor) para cada célula com valor entre as linhas $inicio e $fim.
     * @param callable(int, int, string): void $aoLer
     */
    private function percorrer(string $caminhoAba, int $inicio, int $fim, int $maxColunas, callable $aoLer): void
    {
        $zip = $this->zip();
        try {
            $textos = $this->textos($zip);
            $xml = $zip->ler($caminhoAba);
        } finally {
            $zip->fechar();
        }
        if ($xml === null || $xml === '') {
            return;
        }
        $r = $this->xml($xml);
        unset($xml);

        $linha = 0;
        $coluna = 0;
        $dentroDaCelula = false;
        $tipo = '';
        $valor = null;
        $formula = null;
        $textoEmLinha = null;
        $fonetica = 0;

        $continuar = $r->read();
        while ($continuar) {
            $no = $r->nodeType;
            $nome = $r->localName;
            if ($no === XMLReader::ELEMENT) {
                if ($nome === 'row') {
                    $ref = $r->getAttribute('r');
                    $linha = $ref !== null && ctype_digit($ref) ? (int)$ref : $linha + 1;
                    $coluna = 0;
                    if ($linha > $fim) {
                        break;
                    }
                    if ($linha < $inicio || $r->isEmptyElement) {
                        $continuar = $r->next(); // pula a linha inteira sem ler as células
                        continue;
                    }
                } elseif ($nome === 'c') {
                    $ref = $r->getAttribute('r');
                    $coluna = $ref !== null && preg_match('/^\$?([A-Za-z]{1,3})/', $ref, $m) ? self::indiceDaColuna($m[1]) : $coluna + 1;
                    $tipo = (string)$r->getAttribute('t');
                    $valor = null;
                    $formula = null;
                    $textoEmLinha = null;
                    $fonetica = 0;
                    $dentroDaCelula = !$r->isEmptyElement;
                } elseif ($dentroDaCelula && !$r->isEmptyElement) {
                    if ($nome === 'v') {
                        $valor = $r->readString();
                    } elseif ($nome === 'f') {
                        $formula = $r->readString();
                    } elseif ($nome === 'is') {
                        $textoEmLinha = '';
                    } elseif ($nome === 'rPh') {
                        $fonetica++;
                    } elseif ($nome === 't' && $textoEmLinha !== null && $fonetica === 0) {
                        $textoEmLinha .= $r->readString();
                    }
                }
            } elseif ($no === XMLReader::END_ELEMENT) {
                if ($nome === 'rPh') {
                    $fonetica = max(0, $fonetica - 1);
                } elseif ($nome === 'c' && $dentroDaCelula) {
                    $dentroDaCelula = false;
                    if ($linha >= $inicio && $linha <= $fim && $coluna >= 1 && $coluna <= $maxColunas) {
                        $texto = self::textoDaCelula($tipo, $valor, $formula, $textoEmLinha, $textos);
                        if ($texto !== '') {
                            $aoLer($linha, $coluna, $texto);
                        }
                    }
                } elseif ($nome === 'sheetData') {
                    break;
                }
            }
            $continuar = $r->read();
        }
        $this->fecharXml($r);
    }

    /** @param array<int, string> $textos */
    private static function textoDaCelula(string $tipo, ?string $valor, ?string $formula, ?string $textoEmLinha, array $textos): string
    {
        switch ($tipo) {
            case 's':
                return $valor !== null && ctype_digit(trim($valor)) ? ($textos[(int)$valor] ?? '') : '';
            case 'inlineStr':
                return $textoEmLinha !== null ? self::decodificar($textoEmLinha) : (string)$valor;
            case 'b':
                return $valor === null || $valor === '' ? '' : (trim($valor) === '1' ? 'VERDADEIRO' : 'FALSO');
            case 'str':
            case 'e':
            case 'd':
                return $valor ?? ($formula !== null && $formula !== '' ? '=' . $formula : '');
        }
        if ($valor === null || $valor === '') {
            // Fórmula sem resultado salvo: guardamos o texto da fórmula como veio.
            return $formula !== null && $formula !== '' ? '=' . $formula : '';
        }
        $valor = trim($valor);
        if (preg_match('/^-?\d+$/', $valor)) {
            return $valor;
        }
        if (is_numeric($valor)) {
            $numero = (float)$valor;
            if (floor($numero) === $numero && abs($numero) < 1e15) {
                return sprintf('%.0f', $numero);
            }
            return rtrim(rtrim(sprintf('%.10F', $numero), '0'), '.');
        }
        return $valor;
    }

    // ---------------------------------------------------------------
    // Auxiliares
    // ---------------------------------------------------------------

    private function xml(string $conteudo): XMLReader
    {
        // Sem LIBXML_NOENT/DTDLOAD: entidades externas nunca são carregadas.
        $this->errosAnteriores = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $r = new XMLReader();
        if (!@$r->XML($conteudo, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            libxml_use_internal_errors($this->errosAnteriores);
            throw new RuntimeException('Não foi possível ler a planilha. Abra-a no Excel, salve novamente e tente outra vez.');
        }
        return $r;
    }

    /** Fecha o XML e transforma erros de leitura (arquivo truncado ou alterado) em mensagem amigável. */
    private function fecharXml(XMLReader $r): void
    {
        $r->close();
        $erros = array_filter(libxml_get_errors(), fn ($e) => $e->level >= LIBXML_ERR_ERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($this->errosAnteriores);
        if ($erros) {
            throw new RuntimeException('Não foi possível ler a planilha (o arquivo parece danificado). Abra-a no Excel, salve novamente e tente outra vez.');
        }
    }

    private static function atributoRelacao(XMLReader $r): ?string
    {
        foreach (self::NS_REL as $ns) {
            $id = $r->getAttributeNs('id', $ns);
            if ($id !== null) {
                return $id;
            }
        }
        return null;
    }

    /** Caminho do alvo de uma relação de xl/workbook.xml (relativo a xl/ ou absoluto). */
    private static function resolverCaminho(string $alvo): string
    {
        $alvo = str_replace('\\', '/', $alvo);
        $caminho = str_starts_with($alvo, '/') ? ltrim($alvo, '/') : 'xl/' . $alvo;
        $partes = [];
        foreach (explode('/', $caminho) as $parte) {
            if ($parte === '..') {
                array_pop($partes);
            } elseif ($parte !== '' && $parte !== '.') {
                $partes[] = $parte;
            }
        }
        return implode('/', $partes);
    }

    /** "A" → 1, "AA" → 27. */
    public static function indiceDaColuna(string $letras): int
    {
        $indice = 0;
        foreach (str_split(strtoupper($letras)) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }
        return $indice;
    }

    /** Caracteres especiais que o Excel grava como _xHHHH_ (ex.: _x000D_ = quebra de linha). */
    private static function decodificar(string $texto): string
    {
        if (!str_contains($texto, '_x')) {
            return $texto;
        }
        return preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', fn ($m) => mb_chr((int)hexdec($m[1]), 'UTF-8') ?: '', $texto);
    }
}
