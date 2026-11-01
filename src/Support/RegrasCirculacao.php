<?php

namespace Biblioteca\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Regras de empréstimo configuráveis: Sistema → Configurações no painel (Etapa 6) ou,
 * quando nada foi salvo lá, as variáveis EMPRESTIMO_* do .env. APP_TIMEZONE fica só no .env.
 * Quem chama passa o leitor das configurações: config_valor() (painel, depois .env).
 *
 * A escola ainda não aprovou um regulamento de circulação. Por isso os valores padrão
 * abaixo são PROVISÓRIOS, só para o sistema funcionar, e a tela mostra que são provisórios
 * enquanto não forem definidos no .env:
 *   EMPRESTIMO_PRAZO_DIAS          prazo padrão do empréstimo (provisório: 14 dias)
 *   EMPRESTIMO_RENOVACAO_DIAS      dias acrescentados em cada renovação (padrão: o mesmo prazo)
 *   EMPRESTIMO_MAX_RENOVACOES      limite de renovações (vazio: sem limite da escola; o banco aceita até 10)
 *   EMPRESTIMO_RENOVAR_ATRASADO    permite renovar empréstimo atrasado (padrão: true)
 *   EMPRESTIMO_PRAZO_MAXIMO_DIAS   maior prazo aceito ao escolher uma data manualmente (padrão: 365)
 *   EMPRESTIMO_LIMITE_ALUNO        máximo de empréstimos em aberto por aluno (vazio: sem limite)
 *   EMPRESTIMO_BLOQUEAR_ATRASO     impede novo empréstimo para aluno com livro atrasado (padrão: false)
 *   APP_TIMEZONE                   fuso horário da escola (padrão: America/Sao_Paulo)
 */
final class RegrasCirculacao
{
    /** Limite técnico do banco (emprestimos_renovacoes_ck, migração 010). */
    public const RENOVACOES_LIMITE_TECNICO = 10;

    public function __construct(
        public readonly int $prazoDias = 14,
        public readonly int $renovacaoDias = 14,
        public readonly ?int $maxRenovacoes = null,
        public readonly bool $renovarAtrasado = true,
        public readonly int $prazoMaximoDias = 365,
        public readonly string $fuso = 'America/Sao_Paulo',
        /** true quando o prazo veio do valor provisório (nada definido no .env). */
        public readonly bool $prazoProvisorio = true,
        /** Máximo de empréstimos em aberto por aluno (null: sem limite). */
        public readonly ?int $limiteAluno = null,
        /** Impede novo empréstimo para quem tem livro atrasado. */
        public readonly bool $bloquearComAtraso = false,
    ) {
    }

    /** @param callable(string): ?string $env leitor das configurações (config_valor() ou env()) */
    public static function doAmbiente(callable $env): self
    {
        $inteiro = function (string $chave, int $min, int $max) use ($env): ?int {
            $valor = $env($chave);
            if ($valor === null || !preg_match('/^\d+$/', trim($valor))) {
                return null;
            }
            return max($min, min($max, (int)$valor));
        };
        $prazo = $inteiro('EMPRESTIMO_PRAZO_DIAS', 1, 365);
        $maximo = $inteiro('EMPRESTIMO_PRAZO_MAXIMO_DIAS', 1, 730) ?? 365;
        $max = $inteiro('EMPRESTIMO_MAX_RENOVACOES', 0, self::RENOVACOES_LIMITE_TECNICO);
        $atrasado = $env('EMPRESTIMO_RENOVAR_ATRASADO');
        $bloquear = $env('EMPRESTIMO_BLOQUEAR_ATRASO');
        $fuso = (string)($env('APP_TIMEZONE') ?? 'America/Sao_Paulo');
        if (!in_array($fuso, DateTimeZone::listIdentifiers(), true)) {
            $fuso = 'America/Sao_Paulo';
        }
        return new self(
            prazoDias: min($prazo ?? 14, $maximo),
            renovacaoDias: min($inteiro('EMPRESTIMO_RENOVACAO_DIAS', 1, 365) ?? $prazo ?? 14, $maximo),
            maxRenovacoes: $max,
            renovarAtrasado: $atrasado === null ? true : filter_var($atrasado, FILTER_VALIDATE_BOOL),
            prazoMaximoDias: $maximo,
            fuso: $fuso,
            prazoProvisorio: $prazo === null,
            limiteAluno: $inteiro('EMPRESTIMO_LIMITE_ALUNO', 1, 20),
            bloquearComAtraso: $bloquear !== null && filter_var($bloquear, FILTER_VALIDATE_BOOL),
        );
    }

    public function agora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->fuso));
    }

    /** Data de hoje na escola (Y-m-d). */
    public function hoje(): string
    {
        return $this->agora()->format('Y-m-d');
    }

    /** Limite efetivo de renovações (o da escola ou o técnico). */
    public function limiteRenovacoes(): int
    {
        return $this->maxRenovacoes ?? self::RENOVACOES_LIMITE_TECNICO;
    }
}
