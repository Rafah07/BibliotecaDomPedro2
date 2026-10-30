<?php

namespace Biblioteca\Support;

use InvalidArgumentException;

/**
 * Código de barras Code 128 (lido por qualquer leitor USB comum, que "digita" o código e Enter).
 *
 * Usa o conjunto B para letras e o conjunto C para a sequência final de dígitos, o que deixa
 * "BDP-000001" mais curto (e mais fácil de ler em etiquetas pequenas). O código representa só
 * o identificador do exemplar: nunca senhas, tokens ou dados pessoais.
 *
 * modulos() devolve as larguras alternadas barra/espaço (começando por barra), em módulos.
 * A "zona de silêncio" (margem branca de 10 módulos de cada lado) é responsabilidade de quem desenha.
 */
final class Code128
{
    public const ZONA_SILENCIO = 10;

    /** Larguras (barra, espaço, barra, ...) de cada símbolo 0–106. 103/104/105 = início A/B/C, 106 = fim. */
    private const PADROES = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const INICIO_B = 104;
    private const INICIO_C = 105;
    private const PARA_C = 99;
    private const FIM = 106;

    /** Valores dos símbolos (sem a verificação e o fim). @return list<int> */
    public static function simbolos(string $texto): array
    {
        if ($texto === '' || !preg_match('/^[\x20-\x7E]+$/', $texto)) {
            throw new InvalidArgumentException('O código de barras aceita só letras, números e símbolos ASCII.');
        }
        // Sequência final de dígitos com tamanho par >= 4 vai para o conjunto C.
        preg_match('/\d*$/', $texto, $m);
        $digitos = strlen($m[0]) - strlen($m[0]) % 2;
        if ($digitos < 4) {
            $digitos = 0;
        }
        $prefixo = substr($texto, 0, strlen($texto) - $digitos);
        $sufixo = $digitos ? substr($texto, -$digitos) : '';

        $valores = [];
        if ($prefixo === '') {
            $valores[] = self::INICIO_C;
        } else {
            $valores[] = self::INICIO_B;
            foreach (str_split($prefixo) as $c) {
                $valores[] = ord($c) - 32;
            }
            if ($sufixo !== '') {
                $valores[] = self::PARA_C;
            }
        }
        foreach (str_split($sufixo, 2) as $par) {
            if ($par !== '') {
                $valores[] = (int)$par;
            }
        }
        return $valores;
    }

    /** Larguras das barras e espaços, já com verificação e fim. @return list<int> */
    public static function modulos(string $texto): array
    {
        $valores = self::simbolos($texto);
        $soma = $valores[0];
        foreach ($valores as $i => $v) {
            if ($i > 0) {
                $soma += $v * $i;
            }
        }
        $valores[] = $soma % 103;
        $valores[] = self::FIM;
        $larguras = [];
        foreach ($valores as $v) {
            foreach (str_split(self::PADROES[$v]) as $l) {
                $larguras[] = (int)$l;
            }
        }
        return $larguras;
    }

    /** Total de módulos do código (sem as zonas de silêncio). */
    public static function largura(string $texto): int
    {
        return array_sum(self::modulos($texto));
    }

    /**
     * Retângulos das barras: [x, largura] em módulos, a partir do início do código.
     * @return list<array{0: int, 1: int}>
     */
    public static function barras(string $texto): array
    {
        $x = 0;
        $barras = [];
        foreach (self::modulos($texto) as $i => $l) {
            if ($i % 2 === 0) {
                $barras[] = [$x, $l];
            }
            $x += $l;
        }
        return $barras;
    }

    /** SVG com o código (sem texto), na escala de 1 unidade = 1 módulo. */
    public static function svg(string $texto, string $rotulo = ''): string
    {
        $total = self::largura($texto) + 2 * self::ZONA_SILENCIO;
        $rects = '';
        foreach (self::barras($texto) as [$x, $l]) {
            $rects .= '<rect x="' . ($x + self::ZONA_SILENCIO) . '" y="0" width="' . $l . '" height="40"/>';
        }
        $aria = htmlspecialchars($rotulo !== '' ? $rotulo : 'Código de barras ' . $texto, ENT_QUOTES, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' 40" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="' . $aria . '">'
            . '<rect width="' . $total . '" height="40" fill="#fff"/><g fill="#000">' . $rects . '</g></svg>';
    }
}
