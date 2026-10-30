<?php

namespace Biblioteca\Support;

/**
 * Explica por que um ISBN informado na planilha é inválido e, só quando há
 * fundamentação confiável, sugere uma correção.
 *
 * Regra principal: nunca "conserta" dígitos para o número passar na validação.
 * Recalcular o dígito verificador ou trocar um dígito qualquer pode gerar um ISBN
 * formalmente válido de OUTRA edição. Por isso as sugestões se limitam a casos em
 * que todos os dígitos que identificam a edição continuam os mesmos:
 *   * ISBN-10 antigo com "978" na frente, sem recalcular o dígito final
 *     → os 10 últimos dígitos formam um ISBN-10 válido (mesma edição);
 *   * prefixo diferente de 978/979 (ex.: 977, 988) e o número passa com 978
 *     → os 10 dígitos da edição não mudam, mas a sugestão pede conferência no livro;
 *   * letra "O" no lugar do zero ou "I"/"l" no lugar do um;
 *   * dois ISBNs válidos na mesma célula.
 * Uma sugestão nunca é aplicada sozinha: a bibliotecária decide.
 */
final class IsbnDiagnostico
{
    public const TIPOS = [
        'digito_verificador' => 'Dígito verificador inválido',
        'comprimento' => 'Quantidade de dígitos incorreta',
        'caracteres' => 'Caracteres inadequados',
        'prefixo' => 'Prefixo inválido',
        'notacao_cientifica' => 'Número cortado pelo Excel',
        'varios_isbns' => 'Mais de um número na célula',
    ];

    /**
     * @return array{tipo: string, rotulo: string, descricao: string, digitos: int,
     *               sugestoes: array<int, array{isbn: string, confianca: string, motivo: string}>}
     */
    public static function analisar(string $informado): array
    {
        $texto = trim($informado);
        $digitos = Isbn::normalizar($texto);
        $qtd = strlen(preg_replace('/\D/', '', $texto));
        $r = fn (string $tipo, string $descricao, array $sugestoes = []) => [
            'tipo' => $tipo, 'rotulo' => self::TIPOS[$tipo], 'descricao' => $descricao, 'digitos' => $qtd, 'sugestoes' => $sugestoes,
        ];

        // Excel: 9,78857E+12 — os últimos dígitos se perderam e não podem ser recuperados.
        if (preg_match('/^\d[.,]\d+\s*E\s*\+?\s*\d+$/i', $texto)) {
            return $r('notacao_cientifica', 'O Excel gravou o número em notação científica e os últimos dígitos se perderam. Consulte o ISBN no livro.');
        }

        // Dois ou mais números na mesma célula (ex.: "85-08-04045-8 / 978-85-08-04045-2").
        $partes = array_values(array_filter(array_map('trim', preg_split('/\s*(?:[\/;,|]|\s(?:e|ou)\s)\s*/iu', $texto)), fn ($p) => $p !== ''));
        if (count($partes) > 1) {
            $validas = array_values(array_filter($partes, [Isbn::class, 'valido']));
            $edicoes = array_unique(array_map(fn ($p) => Isbn::para13($p), $validas));
            $sugestoes = [];
            if ($validas && count($validas) === count($partes)) {
                $mesma = count($edicoes) === 1;
                foreach ($mesma ? [$validas[0]] : $validas as $p) {
                    $sugestoes[] = ['isbn' => Isbn::normalizar($p), 'confianca' => $mesma ? 'alta' : 'media',
                        'motivo' => $mesma ? 'Os números da célula são o mesmo ISBN em formatos diferentes (10 e 13 dígitos).' : 'ISBN válido encontrado na célula. Confira no livro qual deles é o desta edição.'];
                }
            }
            return $r('varios_isbns', 'A célula tem ' . count($partes) . ' números. O cadastro aceita um só ISBN por livro.', $sugestoes);
        }

        // Letras no meio do número (o "X" só vale como último caractere do ISBN-10).
        $semSeparadores = preg_replace('/[\s.\-]/', '', $texto);
        if (!preg_match('/^\d*[\dXx]?$/', $semSeparadores) || preg_match('/[Xx]./', $semSeparadores)) {
            $trocado = strtr($semSeparadores, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']);
            $sugestoes = [];
            if ($trocado !== $semSeparadores && preg_match('/^\d{9}[\dX]$|^\d{13}$/i', $trocado) && Isbn::valido($trocado)) {
                $sugestoes[] = ['isbn' => strtoupper($trocado), 'confianca' => 'alta', 'motivo' => 'Letra "O" no lugar do zero ou "I"/"l" no lugar do um. Nenhum dígito foi alterado.'];
            }
            return $r('caracteres', 'O valor tem letras ou símbolos que não fazem parte de um ISBN.', $sugestoes);
        }

        if (strlen($digitos) === 13) {
            $prefixo = substr($digitos, 0, 3);
            if ($prefixo !== '978' && $prefixo !== '979') {
                $sugestoes = [];
                if (Isbn::valido('978' . substr($digitos, 3))) {
                    $sugestoes[] = ['isbn' => '978' . substr($digitos, 3), 'confianca' => 'media',
                        'motivo' => 'Com o prefixo 978 o número fica válido sem mudar os 10 dígitos da edição. Provável erro de digitação no prefixo: confira no livro.'];
                }
                return $r('prefixo', 'Começa com ' . $prefixo . '. Um ISBN-13 começa com 978 ou 979.', $sugestoes);
            }
            if ($prefixo === '978' && Isbn::valido(substr($digitos, 3))) {
                return $r('digito_verificador', 'Os 13 dígitos não fecham a conta, mas os 10 últimos formam um ISBN-10 válido: o "978" foi colocado na frente do ISBN antigo sem recalcular o dígito final.', [
                    ['isbn' => substr($digitos, 3), 'confianca' => 'alta', 'motivo' => 'É o ISBN-10 que já estava na planilha, sem o "978". Mesmo livro, mesma edição.'],
                ]);
            }
            return $r('digito_verificador', 'Tem 13 dígitos, mas o último (dígito verificador) não confere. Algum dígito foi digitado errado.');
        }
        if (strlen($digitos) === 10) {
            return $r('digito_verificador', 'Tem 10 dígitos, mas o último (dígito verificador) não confere. Algum dígito foi digitado errado.');
        }
        return $r('comprimento', 'Tem ' . strlen($digitos) . ' dígito' . (strlen($digitos) === 1 ? '' : 's') . '. Um ISBN tem 10 ou 13.');
    }
}
