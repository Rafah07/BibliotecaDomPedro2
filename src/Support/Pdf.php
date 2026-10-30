<?php

namespace Biblioteca\Support;

/**
 * Gerador de PDF mínimo, sem bibliotecas externas (funciona no XAMPP sem instalar nada).
 *
 * Suporta o que as etiquetas e os relatórios precisam: páginas de qualquer tamanho em
 * milímetros, retângulos preenchidos (barras), linhas e texto nas fontes padrão Helvetica
 * e Helvetica-Bold (codificação WinAnsi, que cobre os acentos do português).
 * As coordenadas são em milímetros a partir do canto superior esquerdo da página.
 *
 * Para imprimir em tamanho real, a impressão deve ser feita em "Tamanho real" / 100%,
 * sem "Ajustar à página".
 */
final class Pdf
{
    private const PONTOS_POR_MM = 72 / 25.4;

    /** Larguras (milésimos do corpo) dos caracteres 32–255 em WinAnsi, das métricas AFM da Adobe. */
    private const LARGURAS = [
        'Helvetica' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,0,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],
        'Helvetica-Bold' => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,0,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],
    ];

    private const FONTES = ['normal' => ['F1', 'Helvetica'], 'negrito' => ['F2', 'Helvetica-Bold']];

    /** @var list<array{0: float, 1: float, 2: string}> largura, altura (pt) e conteúdo de cada página */
    private array $paginas = [];
    private int $atual = -1;
    private string $titulo;

    public function __construct(string $titulo = 'Documento')
    {
        $this->titulo = $titulo;
    }

    public function novaPagina(float $larguraMm, float $alturaMm): void
    {
        $this->paginas[] = [$larguraMm * self::PONTOS_POR_MM, $alturaMm * self::PONTOS_POR_MM, ''];
        $this->atual = count($this->paginas) - 1;
    }

    public function totalPaginas(): int
    {
        return count($this->paginas);
    }

    /** Retângulo preenchido (preto, ou cinza de 0 = preto a 1 = branco). */
    public function retangulo(float $x, float $y, float $largura, float $altura, float $cinza = 0.0): void
    {
        $this->escrever(sprintf('%.3F g %.3F %.3F %.3F %.3F re f', $cinza, $this->pt($x), $this->ptY($y + $altura), $this->pt($largura), $this->pt($altura)));
    }

    /** Contorno de retângulo (guia de corte), com espessura em mm. */
    public function contorno(float $x, float $y, float $largura, float $altura, float $espessura = 0.1, float $cinza = 0.6): void
    {
        $this->escrever(sprintf('%.3F G %.3F w %.3F %.3F %.3F %.3F re S', $cinza, $this->pt($espessura), $this->pt($x), $this->ptY($y + $altura), $this->pt($largura), $this->pt($altura)));
    }

    public function linha(float $x1, float $y1, float $x2, float $y2, float $espessura = 0.2, float $cinza = 0.0): void
    {
        $this->escrever(sprintf('%.3F G %.3F w %.3F %.3F m %.3F %.3F l S', $cinza, $this->pt($espessura), $this->pt($x1), $this->ptY($y1), $this->pt($x2), $this->ptY($y2)));
    }

    /**
     * Texto em uma linha. $y é a linha de base. $alinhamento: esquerda | centro | direita
     * ($x é a borda esquerda, o centro ou a borda direita).
     */
    public function texto(float $x, float $y, string $texto, float $tamanhoPt = 9, string $fonte = 'normal', string $alinhamento = 'esquerda', float $cinza = 0.0): void
    {
        $largura = $this->larguraTexto($texto, $tamanhoPt, $fonte);
        $x = match ($alinhamento) {
            'centro' => $x - $largura / 2,
            'direita' => $x - $largura,
            default => $x,
        };
        [$nome] = self::FONTES[$fonte] ?? self::FONTES['normal'];
        $this->escrever(sprintf('BT %.3F g /%s %.2F Tf %.3F %.3F Td (%s) Tj ET', $cinza, $nome, $tamanhoPt, $this->pt($x), $this->ptY($y), self::escapar(self::winAnsi($texto))));
    }

    /** Largura do texto em milímetros. */
    public function larguraTexto(string $texto, float $tamanhoPt, string $fonte = 'normal'): float
    {
        return self::medir($texto, $tamanhoPt, $fonte);
    }

    public static function medir(string $texto, float $tamanhoPt, string $fonte = 'normal'): float
    {
        $tabela = self::LARGURAS[(self::FONTES[$fonte] ?? self::FONTES['normal'])[1]];
        $soma = 0;
        foreach (str_split(self::winAnsi($texto)) as $c) {
            $o = ord($c);
            $soma += $o >= 32 ? ($tabela[$o - 32] ?: 556) : 0;
        }
        return $soma / 1000 * $tamanhoPt / self::PONTOS_POR_MM;
    }

    /** Corta o texto (com reticências) para caber na largura em mm. */
    public static function caber(string $texto, float $larguraMm, float $tamanhoPt, string $fonte = 'normal'): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
        if (self::medir($texto, $tamanhoPt, $fonte) <= $larguraMm) {
            return $texto;
        }
        $letras = mb_str_split($texto);
        while ($letras && self::medir(rtrim(implode('', $letras)) . '…', $tamanhoPt, $fonte) > $larguraMm) {
            array_pop($letras);
        }
        return $letras ? rtrim(implode('', $letras)) . '…' : '';
    }

    /** Quebra o texto em linhas que cabem na largura (para relatórios). @return list<string> */
    public static function quebrar(string $texto, float $larguraMm, float $tamanhoPt, string $fonte = 'normal'): array
    {
        $linhas = [];
        $atual = '';
        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palavra) {
            $teste = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if ($atual !== '' && self::medir($teste, $tamanhoPt, $fonte) > $larguraMm) {
                $linhas[] = $atual;
                $atual = $palavra;
            } else {
                $atual = $teste;
            }
        }
        if ($atual !== '') {
            $linhas[] = $atual;
        }
        return array_map(fn ($l) => self::caber($l, $larguraMm, $tamanhoPt, $fonte), $linhas);
    }

    /** Conteúdo do arquivo PDF. */
    public function gerar(): string
    {
        if (!$this->paginas) {
            $this->novaPagina(210, 297);
        }
        $objetos = [];
        $proximo = 6;
        // 1 catálogo, 2 árvore de páginas, 3 e 4 fontes, 5 informações; depois páginas e conteúdos.
        $objetos[1] = '';
        $objetos[2] = '';
        $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objetos[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objetos[5] = '<< /Producer (Biblioteca Dom Pedro II) /Title (' . self::escapar(self::winAnsi($this->titulo)) . ') /CreationDate (D:' . date('YmdHis') . ') >>';
        $kids = [];
        foreach ($this->paginas as [$w, $h, $conteudo]) {
            $comprimir = function_exists('gzcompress');
            $dados = $comprimir ? gzcompress($conteudo) : $conteudo;
            $idConteudo = $proximo++;
            $objetos[$idConteudo] = '<< /Length ' . strlen($dados) . ($comprimir ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $dados . "\nendstream";
            $idPagina = $proximo++;
            $objetos[$idPagina] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3F %.3F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $w, $h, $idConteudo);
            $kids[] = $idPagina . ' 0 R';
        }
        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R /ViewerPreferences << /PrintScaling /None >> >>';
        $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

        $saida = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $posicoes = [];
        ksort($objetos);
        foreach ($objetos as $id => $corpo) {
            $posicoes[$id] = strlen($saida);
            $saida .= $id . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        $xref = strlen($saida);
        $saida .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
        foreach ($posicoes as $p) {
            $saida .= sprintf("%010d 00000 n \n", $p);
        }
        return $saida . 'trailer << /Size ' . (count($objetos) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    // ---------------------------------------------------------------

    private function escrever(string $comando): void
    {
        if ($this->atual < 0) {
            $this->novaPagina(210, 297);
        }
        $this->paginas[$this->atual][2] .= $comando . "\n";
    }

    private function pt(float $mm): float
    {
        return $mm * self::PONTOS_POR_MM;
    }

    /** O PDF mede a altura de baixo para cima. */
    private function ptY(float $mm): float
    {
        return $this->paginas[$this->atual][1] - $mm * self::PONTOS_POR_MM;
    }

    /** UTF-8 para Windows-1252 (caracteres fora da tabela viram "?"). */
    private static function winAnsi(string $texto): string
    {
        $texto = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $texto) ?? '';
        $convertido = @mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
        return is_string($convertido) ? $convertido : preg_replace('/[^\x20-\x7E]/', '?', $texto);
    }

    private static function escapar(string $texto): string
    {
        return strtr($texto, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }
}
