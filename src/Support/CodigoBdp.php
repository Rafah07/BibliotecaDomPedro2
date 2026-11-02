<?php

namespace Biblioteca\Support;

/**
 * Código escrito dos exemplares físicos: BDP-000001, BDP-000002...
 *
 * A identificação é feita só pela digitação do código impresso na etiqueta (sem código de
 * barras, QR Code, RFID ou NFC). Por isso a leitura é EXATA:
 *   * maiúsculas e minúsculas são equivalentes ("bdp-000001" = "BDP-000001");
 *   * espaços antes e depois são ignorados;
 *   * o hífen pode faltar ou ser trocado por espaço ("BDP000001", "BDP 000001");
 *   * os 6 números são obrigatórios. "BDP-12" NÃO vira BDP-000012: um dígito esquecido
 *     poderia apontar para outro exemplar que existe, então o sistema pede o código completo.
 * Não há correspondência aproximada: ou o código é exatamente o de um exemplar, ou nada é escolhido.
 */
final class CodigoBdp
{
    public const PREFIXO = 'BDP-';
    public const DIGITOS = 6;
    public const EXEMPLO = 'BDP-000001';

    /** Código no formato oficial (BDP-000001) ou null quando o texto não é um código completo. */
    public static function normalizar(string $texto): ?string
    {
        if (preg_match('/^BDP\s*-?\s*(\d{' . self::DIGITOS . '})$/i', self::limpar($texto), $m)) {
            return self::PREFIXO . $m[1];
        }
        return null;
    }

    /**
     * O texto começa como um código BDP ("BDP", "bdp-12", "BDP-0000123"), mesmo que incompleto
     * ou errado. Serve para avisar "código incompleto" em vez de pesquisar o texto como título.
     */
    public static function pareceCodigo(string $texto): bool
    {
        return (bool)preg_match('/^BDP\s*-?\s*\d*$/i', self::limpar($texto));
    }

    /** Mensagem para um texto que parece código, mas não está completo. */
    public static function mensagemInvalido(string $texto): string
    {
        return 'O código "' . mb_substr(self::limpar($texto), 0, 30) . '" está incompleto ou com números a mais. '
            . 'Digite o código completo, com as letras BDP e os ' . self::DIGITOS . ' números, como está escrito na etiqueta (ex.: ' . self::EXEMPLO . ').';
    }

    /** Monta o código a partir do número da sequência (1 → BDP-000001). */
    public static function formatar(int $numero): string
    {
        return sprintf(self::PREFIXO . '%0' . self::DIGITOS . 'd', $numero);
    }

    /** Remove espaços (inclusive os "invisíveis" de texto colado) antes e depois. */
    private static function limpar(string $texto): string
    {
        return trim(preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $texto) ?? $texto);
    }
}
