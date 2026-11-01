<?php

namespace Biblioteca\Support;

/**
 * Relatório administrativo em PDF (A4), montado sobre o gerador próprio Pdf
 * (o mesmo das etiquetas e do relatório do inventário: nada para instalar no XAMPP).
 *
 * Cada página tem cabeçalho (biblioteca e título) e rodapé (data de geração, aviso de
 * documento interno e "Página X de Y"). A primeira página traz o período e os filtros.
 * Tabelas quebram de página repetindo o cabeçalho das colunas; textos longos são cortados
 * em até duas linhas por célula. Gráficos são barras horizontais com os valores escritos.
 */
final class RelatorioPdf
{
    private Pdf $pdf;
    private float $largura;
    private float $altura;
    private float $y = 0;
    private const MARGEM = 14;
    private const RODAPE = 14;
    /** Cores das barras (azul do painel). */
    private const COR = [0.141, 0.341, 0.773];

    public function __construct(
        private string $biblioteca,
        private string $titulo,
        private string $periodo,
        /** @var array<string, string> rótulo => valor */
        private array $filtros = [],
        private string $geradoPor = '',
        bool $paisagem = false,
        private ?string $geradoEm = null,
    ) {
        $this->pdf = new Pdf($titulo);
        [$this->largura, $this->altura] = $paisagem ? [297, 210] : [210, 297];
        $this->geradoEm ??= date('d/m/Y H:i');
        $this->novaPagina(true);
    }

    private function util(): float
    {
        return $this->largura - 2 * self::MARGEM;
    }

    private function novaPagina(bool $primeira = false): void
    {
        $this->pdf->novaPagina($this->largura, $this->altura);
        $m = self::MARGEM;
        $this->pdf->texto($m, 10, Pdf::caber($this->biblioteca . ' · ' . $this->titulo, $this->util() - 30, 8), 8, 'normal', 'esquerda', 0.4);
        $this->pdf->linha($m, 12, $m + $this->util(), 12, 0.2, 0.8);
        $this->y = 19;
        if (!$primeira) {
            return;
        }
        $this->pdf->texto($m, $this->y + 2, Pdf::caber($this->biblioteca, $this->util(), 11, 'negrito'), 11, 'negrito', 'esquerda', 0.25);
        $this->y += 9;
        foreach (array_slice(Pdf::quebrar($this->titulo, $this->util(), 16, 'negrito'), 0, 2) as $l) {
            $this->pdf->texto($m, $this->y, $l, 16, 'negrito');
            $this->y += 7;
        }
        $info = ['Período' => $this->periodo, 'Gerado em' => $this->geradoEm] + ($this->geradoPor !== '' ? ['Gerado por' => $this->geradoPor] : []);
        foreach ($info as $r => $v) {
            $this->pdf->texto($m, $this->y, $r . ':', 8.5, 'negrito', 'esquerda', 0.3);
            $this->pdf->texto($m + 24, $this->y, Pdf::caber($v, $this->util() - 24, 8.5), 8.5, 'normal', 'esquerda', 0.2);
            $this->y += 4.6;
        }
        $filtros = $this->filtros ? implode(' · ', array_map(fn ($r, $v) => $r . ': ' . $v, array_keys($this->filtros), $this->filtros)) : 'Nenhum filtro (todos os registros)';
        $linhas = array_slice(Pdf::quebrar($filtros, $this->util() - 24, 8.5), 0, 4);
        foreach ($linhas as $i => $l) {
            if ($i === 0) {
                $this->pdf->texto($m, $this->y, 'Filtros:', 8.5, 'negrito', 'esquerda', 0.3);
            }
            $this->pdf->texto($m + 24, $this->y, $l, 8.5, 'normal', 'esquerda', 0.2);
            $this->y += 4.6;
        }
        $this->y += 3;
    }

    /** Garante espaço na página (em mm); senão abre outra. */
    private function espaco(float $mm): void
    {
        if ($this->y + $mm > $this->altura - self::RODAPE - 4) {
            $this->novaPagina();
        }
    }

    public function secao(string $texto): void
    {
        $this->espaco(14);
        $this->y += 2;
        $this->pdf->texto(self::MARGEM, $this->y, Pdf::caber($texto, $this->util(), 11.5, 'negrito'), 11.5, 'negrito');
        $this->y += 2;
        $this->pdf->linha(self::MARGEM, $this->y, self::MARGEM + $this->util(), $this->y, 0.3, 0.75);
        $this->y += 5;
    }

    public function paragrafo(string $texto, float $tamanho = 8.5, float $cinza = 0.3): void
    {
        foreach (Pdf::quebrar($texto, $this->util(), $tamanho) as $l) {
            $this->espaco(5);
            $this->pdf->texto(self::MARGEM, $this->y, $l, $tamanho, 'normal', 'esquerda', $cinza);
            $this->y += $tamanho * 0.5;
        }
        $this->y += 1.5;
    }

    /** Caixas com número em destaque. @param list<array{0: string, 1: int|string, 2?: string}> $itens rótulo, valor, observação */
    public function indicadores(array $itens, int $porLinha = 4): void
    {
        if (!$itens) {
            return;
        }
        $col = $this->util() / $porLinha;
        foreach (array_chunk($itens, $porLinha) as $linha) {
            $this->espaco(17);
            foreach ($linha as $k => $item) {
                $x = self::MARGEM + $k * $col;
                $this->pdf->contorno($x, $this->y, $col - 2, 15, 0.2, 0.78);
                $this->pdf->texto($x + 2.5, $this->y + 4.6, Pdf::caber((string)$item[0], $col - 7, 7.2), 7.2, 'normal', 'esquerda', 0.35);
                $valor = is_int($item[1]) ? number_format($item[1], 0, ',', '.') : (string)$item[1];
                $this->pdf->texto($x + 2.5, $this->y + 10.4, Pdf::caber($valor, $col - 7, 11.5, 'negrito'), 11.5, 'negrito');
                if (($item[2] ?? '') !== '') {
                    $this->pdf->texto($x + 2.5, $this->y + 13.5, Pdf::caber((string)$item[2], $col - 7, 6.2), 6.2, 'normal', 'esquerda', 0.45);
                }
            }
            $this->y += 17;
        }
        $this->y += 2;
    }

    /** Gráfico de barras horizontais com os valores. @param list<array{rotulo: string, valor: int}> $serie */
    public function grafico(string $titulo, array $serie, int $maxItens = 12): void
    {
        $serie = array_slice($serie, 0, $maxItens);
        $this->espaco(12);
        $this->pdf->texto(self::MARGEM, $this->y, Pdf::caber($titulo, $this->util(), 9.5, 'negrito'), 9.5, 'negrito', 'esquerda', 0.15);
        $this->y += 5;
        $max = max([0, ...array_map(fn ($s) => (int)$s['valor'], $serie)]);
        if ($max === 0) {
            $this->pdf->texto(self::MARGEM, $this->y + 1, 'Sem registros para este gráfico no período e filtros escolhidos.', 8.5, 'normal', 'esquerda', 0.45);
            $this->y += 8;
            return;
        }
        $rotuloW = min(70, $this->util() * 0.38);
        $barraW = $this->util() - $rotuloW - 22;
        foreach ($serie as $s) {
            $this->espaco(6);
            $this->pdf->texto(self::MARGEM, $this->y + 3, Pdf::caber((string)$s['rotulo'], $rotuloW - 2, 7.8), 7.8, 'normal', 'esquerda', 0.2);
            $w = max(0.6, $barraW * (int)$s['valor'] / $max);
            $this->pdf->retangulo(self::MARGEM + $rotuloW, $this->y, $barraW, 4, 0.95);
            if ((int)$s['valor'] > 0) {
                $this->pdf->retanguloCor(self::MARGEM + $rotuloW, $this->y, $w, 4, ...self::COR);
            }
            $this->pdf->texto(self::MARGEM + $rotuloW + $barraW + 2, $this->y + 3.1, number_format((int)$s['valor'], 0, ',', '.'), 7.8, 'negrito');
            $this->y += 5.6;
        }
        $this->y += 3;
    }

    /**
     * Tabela. $colunas: chave => [rótulo, peso da largura, 'esquerda'|'direita'].
     * Para no limite de linhas e avisa quantas ficaram de fora.
     * @param iterable<array<string, mixed>> $linhas
     */
    public function tabela(array $colunas, iterable $linhas, int $limite = 2000, int $total = -1, string $vazio = 'Nenhum registro encontrado com os filtros escolhidos.'): int
    {
        $soma = array_sum(array_map(fn ($c) => (float)$c[1], $colunas)) ?: 1;
        $larguras = array_map(fn ($c) => $this->util() * (float)$c[1] / $soma, $colunas);
        $tam = 7.2;
        $cabecalho = function () use ($colunas, $larguras): void {
            $this->espaco(12);
            $this->pdf->retangulo(self::MARGEM, $this->y - 3.9, $this->util(), 5.8, 0.9);
            $x = self::MARGEM;
            foreach ($colunas as $k => [$rot, , $alin]) {
                $w = $larguras[$k];
                $texto = Pdf::caber($rot, $w - 2, 7.2, 'negrito');
                $alin === 'direita'
                    ? $this->pdf->texto($x + $w - 1, $this->y, $texto, 7.2, 'negrito', 'direita')
                    : $this->pdf->texto($x + 1, $this->y, $texto, 7.2, 'negrito');
                $x += $w;
            }
            $this->y += 5.2;
        };
        $cabecalho();
        $n = 0;
        foreach ($linhas as $linha) {
            if ($n >= $limite) {
                break;
            }
            $celulas = [];
            $alturaLinhas = 1;
            foreach ($colunas as $k => [, , $alin]) {
                $texto = trim((string)($linha[$k] ?? ''));
                $partes = $alin === 'direita' ? [Pdf::caber($texto, $larguras[$k] - 2, $tam)] : array_slice(Pdf::quebrar($texto, $larguras[$k] - 2, $tam), 0, 2);
                $celulas[$k] = $partes ?: [''];
                $alturaLinhas = max($alturaLinhas, count($celulas[$k]));
            }
            $h = 1.6 + 3.3 * $alturaLinhas;
            if ($this->y + $h > $this->altura - self::RODAPE - 4) {
                $this->novaPagina();
                $cabecalho();
            }
            if ($n % 2 === 1) {
                $this->pdf->retangulo(self::MARGEM, $this->y - 3.3, $this->util(), $h, 0.97);
            }
            $x = self::MARGEM;
            foreach ($colunas as $k => [, , $alin]) {
                foreach ($celulas[$k] as $i => $parte) {
                    $alin === 'direita'
                        ? $this->pdf->texto($x + $larguras[$k] - 1, $this->y + $i * 3.3, $parte, $tam, 'normal', 'direita', 0.1)
                        : $this->pdf->texto($x + 1, $this->y + $i * 3.3, $parte, $tam, 'normal', 'esquerda', 0.1);
                }
                $x += $larguras[$k];
            }
            $this->y += $h;
            $n++;
        }
        if ($n === 0) {
            $this->pdf->texto(self::MARGEM + 1, $this->y + 1, $vazio, 8.5, 'normal', 'esquerda', 0.45);
            $this->y += 7;
        } elseif ($total > $n) {
            $this->y += 2;
            $this->paragrafo('Mostrando ' . number_format($n, 0, ',', '.') . ' de ' . number_format($total, 0, ',', '.') . ' registros. Para a lista completa, exporte em CSV ou XLSX.', 8, 0.35);
        }
        $this->y += 3;
        return $n;
    }

    /** Conteúdo do PDF, com o rodapé "Página X de Y" em todas as páginas. */
    public function gerar(): string
    {
        $total = $this->pdf->totalPaginas();
        for ($p = 1; $p <= $total; $p++) {
            $this->pdf->irParaPagina($p);
            $yr = $this->altura - 8;
            $this->pdf->linha(self::MARGEM, $yr - 4, self::MARGEM + $this->util(), $yr - 4, 0.2, 0.8);
            $this->pdf->texto(self::MARGEM, $yr, Pdf::caber('Gerado em ' . $this->geradoEm . ' · Documento interno da ' . $this->biblioteca . ' — não publicar', $this->util() - 30, 7), 7, 'normal', 'esquerda', 0.4);
            $this->pdf->texto(self::MARGEM + $this->util(), $yr, 'Página ' . $p . ' de ' . $total, 7, 'normal', 'direita', 0.4);
        }
        return $this->pdf->gerar();
    }
}
