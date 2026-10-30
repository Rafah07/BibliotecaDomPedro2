<?php

namespace Biblioteca\Support;

/**
 * Limpeza e comparação de textos vindos das planilhas.
 * A limpeza preserva acentos e caracteres especiais; só a "chave" de comparação os remove.
 */
final class Texto
{
    /**
     * Remove espaços extras, caracteres invisíveis e aspas/pontuação soltas nas pontas.
     * Devolve null quando não sobra conteúdo.
     */
    public static function limpar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = (string)$valor;
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        // Caracteres de controle, BOM e espaços de largura zero.
        $texto = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200D}\x{FEFF}]/u', '', $texto);
        $texto = str_replace(["\u{00A0}", "\t", "\r", "\n"], ' ', $texto);
        $texto = preg_replace('/\s+/u', ' ', $texto);
        $texto = trim($texto, " \"'`´“”‘’;,|_*");
        $texto = trim($texto);
        // Traços soltos nas pontas ("- Dom Casmurro -"), sem mexer em hífens internos nem em sinais de números.
        $texto = trim(preg_replace('/^[-–—.\s]+(?=\p{L})|(?<=\p{L}|\d|\))\s+[-–—]+$/u', '', $texto));
        return $texto === '' ? null : $texto;
    }

    /** Letras latinas acentuadas e suas versões sem acento (não depende das extensões intl ou iconv). */
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'š' => 's', 'ž' => 'z',
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ā' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ō' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ū' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y', 'Š' => 'S', 'Ž' => 'Z', 'Æ' => 'AE', 'Œ' => 'OE',
    ];

    /**
     * Remove os acentos. A tabela acima cobre o português e as línguas latinas comuns em
     * qualquer instalação (o iconv do Windows/XAMPP troca "í" por "?" e a extensão intl
     * costuma estar desligada); o intl, se existir, só cuida de letras fora da tabela.
     */
    public static function semAcento(string $texto): string
    {
        $texto = strtr($texto, self::ACENTOS);
        if (class_exists(\Normalizer::class) && preg_match('/[^\x00-\x7F]/', $texto)) {
            $decomposto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
            if ($decomposto !== false) {
                $texto = preg_replace('/\p{Mn}+/u', '', $decomposto) ?? $texto;
            }
        }
        return $texto;
    }

    /** Minúsculas, sem acentos e só com letras/números separados por um espaço. Usada para comparar. */
    public static function chave(?string $texto): string
    {
        if ($texto === null || $texto === '') {
            return '';
        }
        $texto = self::semAcento(mb_strtolower($texto, 'UTF-8'));
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);
        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    /**
     * Chave do autor independente da ordem dos nomes:
     * "ASSIS, Machado de" e "Machado de Assis" geram a mesma chave.
     */
    public static function chaveAutor(?string $autor): string
    {
        $palavras = array_filter(explode(' ', self::chave($autor)), fn ($p) => strlen($p) > 2 || ctype_digit($p));
        sort($palavras);
        return implode(' ', $palavras);
    }

    /** Chave da obra (título + autor) usada para encontrar registros semelhantes. */
    public static function chaveObra(?string $titulo, ?string $autor): ?string
    {
        $t = self::chave($titulo);
        if ($t === '') {
            return null;
        }
        // Artigos iniciais não mudam a obra ("O Cortiço" = "Cortiço").
        $t = preg_replace('/^(o|a|os|as|um|uma|the) /', '', $t);
        $chave = $t . '|' . self::chaveAutor($autor);
        return mb_substr($chave, 0, 255);
    }

    /** Converte para inteiro quando o texto é um número inteiro ("5", "5,0", "05"). Senão, null. */
    public static function inteiro(?string $texto): ?int
    {
        if ($texto === null) {
            return null;
        }
        $t = str_replace(' ', '', $texto);
        if (preg_match('/^[+-]?\d+$/', $t)) {
            return (int)$t;
        }
        if (preg_match('/^([+-]?\d+)[.,]0+$/', $t, $m)) {
            return (int)$m[1];
        }
        return null;
    }
}
