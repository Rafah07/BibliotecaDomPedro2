<?php

namespace Biblioteca\Support;

/** ISBN-10 e ISBN-13 (mesmas regras do formulário de livros). */
final class Isbn
{
    /** Remove espaços, hífens e pontos. Mantém o "X" final do ISBN-10. */
    public static function normalizar(?string $isbn): string
    {
        return strtoupper(preg_replace('/[^0-9Xx]/', '', (string)$isbn));
    }

    public static function valido(?string $isbn): bool
    {
        $isbn = self::normalizar($isbn);
        if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
            $soma = 0;
            for ($i = 0; $i < 10; $i++) {
                $soma += ($isbn[$i] === 'X' ? 10 : (int)$isbn[$i]) * (10 - $i);
            }
            return $soma % 11 === 0;
        }
        if (preg_match('/^\d{13}$/', $isbn)) {
            $soma = 0;
            for ($i = 0; $i < 12; $i++) {
                $soma += (int)$isbn[$i] * ($i % 2 === 0 ? 1 : 3);
            }
            return (10 - $soma % 10) % 10 === (int)$isbn[12];
        }
        return false;
    }

    /** O texto tem cara de ISBN (usado para perceber colunas trocadas). */
    public static function pareceIsbn(?string $texto): bool
    {
        if ($texto === null) {
            return false;
        }
        $limpo = self::normalizar($texto);
        return (strlen($limpo) === 10 || strlen($limpo) === 13)
            && preg_match('/^[\d\-\s.Xx]+$/', $texto)
            && self::valido($limpo);
    }

    /** ISBN-10 → ISBN-13 (978), para comparar edições cadastradas com formatos diferentes. */
    public static function para13(string $isbn): string
    {
        $isbn = self::normalizar($isbn);
        if (strlen($isbn) !== 10) {
            return $isbn;
        }
        $base = '978' . substr($isbn, 0, 9);
        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $soma += (int)$base[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return $base . ((10 - $soma % 10) % 10);
    }
}
