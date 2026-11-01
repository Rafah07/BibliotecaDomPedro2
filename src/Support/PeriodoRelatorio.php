<?php

namespace Biblioteca\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Período escolhido nos relatórios (filtro de datas).
 *
 * - As datas são sempre do fuso da escola (APP_TIMEZONE), nunca do servidor MySQL.
 * - O fim é inclusivo para quem lê ("até 31/10") e vira um limite exclusivo no SQL
 *   (< 01/11), para que os registros do último dia, com hora, entrem na conta.
 * - A data inicial nunca pode ser posterior à final: o período é recusado com uma
 *   mensagem e o padrão é usado no lugar (nada é invertido silenciosamente).
 * - O período personalizado tem limite de anos (MAX_DIAS) para evitar consultas enormes.
 */
final class PeriodoRelatorio
{
    public const PRESETS = [
        '7' => 'Últimos 7 dias',
        '30' => 'Últimos 30 dias',
        '90' => 'Últimos 90 dias',
        'ano' => 'Ano atual',
        'todo' => 'Todo o período',
        'personalizado' => 'Período personalizado',
    ];

    /** Maior período personalizado aceito (cerca de 10 anos). */
    public const MAX_DIAS = 3700;

    /** Data mais antiga aceita num período personalizado. */
    public const MENOR_DATA = '1990-01-01';

    private function __construct(
        public readonly string $preset,
        /** Y-m-d ou null quando é "todo o período". */
        public readonly ?string $inicio,
        /** Y-m-d (inclusivo) ou null quando é "todo o período". */
        public readonly ?string $fim,
        public readonly ?string $erro = null,
    ) {
    }

    /**
     * Lê preset/de/ate de um array (normalmente $_GET).
     * $padrao: preset usado quando nada (ou algo inválido) foi escolhido.
     */
    public static function deParametros(array $p, string $fuso, string $padrao = 'ano', ?DateTimeImmutable $agora = null): self
    {
        $tz = new DateTimeZone(in_array($fuso, DateTimeZone::listIdentifiers(), true) ? $fuso : 'America/Sao_Paulo');
        $hoje = ($agora ?? new DateTimeImmutable('now', $tz))->setTimezone($tz)->setTime(0, 0);
        $preset = is_string($p['periodo'] ?? null) ? $p['periodo'] : '';
        $de = is_string($p['de'] ?? null) ? trim($p['de']) : '';
        $ate = is_string($p['ate'] ?? null) ? trim($p['ate']) : '';
        if ($preset === '' && ($de !== '' || $ate !== '')) {
            $preset = 'personalizado';
        }
        if (!isset(self::PRESETS[$preset])) {
            $preset = $padrao;
        }
        if ($preset !== 'personalizado') {
            return self::doPreset($preset, $hoje);
        }

        $dInicio = self::data($de, $tz);
        $dFim = self::data($ate, $tz);
        $erro = null;
        if ($de !== '' && !$dInicio || $ate !== '' && !$dFim) {
            $erro = 'Informe datas válidas no formato dia/mês/ano.';
        } elseif (!$dInicio || !$dFim) {
            $erro = 'Para um período personalizado, informe a data inicial e a data final.';
        } elseif ($dInicio > $dFim) {
            $erro = 'A data inicial não pode ser posterior à data final.';
        } elseif ($dInicio->format('Y-m-d') < self::MENOR_DATA) {
            $erro = 'A data inicial é anterior a ' . (new DateTimeImmutable(self::MENOR_DATA))->format('d/m/Y') . '.';
        } elseif ($dFim > $hoje->modify('+1 year')) {
            $erro = 'A data final está muito no futuro.';
        } elseif ((int)$dInicio->diff($dFim)->days > self::MAX_DIAS) {
            $erro = 'O período personalizado pode ter no máximo 10 anos.';
        }
        if ($erro !== null) {
            $base = self::doPreset($padrao === 'personalizado' ? 'ano' : $padrao, $hoje);
            return new self($base->preset, $base->inicio, $base->fim, $erro);
        }
        return new self('personalizado', $dInicio->format('Y-m-d'), $dFim->format('Y-m-d'));
    }

    private static function doPreset(string $preset, DateTimeImmutable $hoje): self
    {
        return match ($preset) {
            '7', '30', '90' => new self($preset, $hoje->modify('-' . ((int)$preset - 1) . ' days')->format('Y-m-d'), $hoje->format('Y-m-d')),
            'todo' => new self('todo', null, null),
            default => new self('ano', $hoje->format('Y') . '-01-01', $hoje->format('Y-m-d')),
        };
    }

    private static function data(string $valor, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return new DateTimeImmutable($valor . ' 00:00:00', $tz);
    }

    public function limitado(): bool
    {
        return $this->inicio !== null;
    }

    /** Início para o SQL (>=), com hora. */
    public function sqlInicio(): ?string
    {
        return $this->inicio === null ? null : $this->inicio . ' 00:00:00';
    }

    /** Dia seguinte ao fim, para o SQL (<). */
    public function sqlFimExclusivo(): ?string
    {
        return $this->fim === null ? null : (new DateTimeImmutable($this->fim))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    /**
     * Condição SQL "coluna dentro do período" e os parâmetros. Sem período, devolve "1 = 1".
     * @return array{0: string, 1: list<string>}
     */
    public function condicao(string $coluna): array
    {
        if (!$this->limitado()) {
            return ['1 = 1', []];
        }
        return ["($coluna >= ? AND $coluna < ?)", [$this->sqlInicio(), $this->sqlFimExclusivo()]];
    }

    /** Quantidade de dias do período (null quando é todo o período). */
    public function dias(): ?int
    {
        if (!$this->limitado()) {
            return null;
        }
        return (int)(new DateTimeImmutable($this->inicio))->diff(new DateTimeImmutable($this->fim))->days + 1;
    }

    /** "dia" para períodos curtos, "mes" para até ~3 anos, "ano" acima disso (gráficos ao longo do tempo). */
    public function agrupamento(?string $primeiraData = null): string
    {
        $dias = $this->dias();
        if ($dias === null && $primeiraData !== null) {
            $dias = (int)(new DateTimeImmutable(substr($primeiraData, 0, 10)))->diff(new DateTimeImmutable())->days + 1;
        }
        if ($dias === null) {
            return 'ano';
        }
        return $dias <= 62 ? 'dia' : ($dias <= 1100 ? 'mes' : 'ano');
    }

    public function rotulo(): string
    {
        if (!$this->limitado()) {
            return 'Todo o período';
        }
        $br = fn (string $d) => (new DateTimeImmutable($d))->format('d/m/Y');
        $intervalo = $br($this->inicio) . ' a ' . $br($this->fim);
        return $this->preset === 'personalizado' ? $intervalo : self::PRESETS[$this->preset] . ' (' . $intervalo . ')';
    }

    /** Parâmetros para montar links que mantêm o período. */
    public function parametros(): array
    {
        return $this->preset === 'personalizado'
            ? ['periodo' => 'personalizado', 'de' => $this->inicio, 'ate' => $this->fim]
            : ['periodo' => $this->preset];
    }

    /** Trecho do nome de arquivo ("2026-01-01-a-2026-10-31" ou "todo-o-periodo"). */
    public function sufixoArquivo(): string
    {
        return $this->limitado() ? $this->inicio . '-a-' . $this->fim : 'todo-o-periodo';
    }

    /**
     * Lista de baldes (dias, meses ou anos) do período, na ordem, para que os gráficos
     * mostrem também os intervalos sem registros (com zero).
     * @return list<array{chave: string, rotulo: string}>
     */
    public function baldes(string $agrupamento, ?string $primeiraData = null, ?string $hoje = null): array
    {
        $inicio = $this->inicio ?? ($primeiraData ? substr($primeiraData, 0, 10) : null);
        $fim = $this->fim ?? ($hoje ?? date('Y-m-d'));
        if ($inicio === null || $inicio > $fim) {
            return [];
        }
        $meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $d = new DateTimeImmutable($inicio);
        $ultimo = new DateTimeImmutable($fim);
        $lista = [];
        if ($agrupamento === 'dia') {
            for (; $d <= $ultimo && count($lista) < 400; $d = $d->modify('+1 day')) {
                $lista[] = ['chave' => $d->format('Y-m-d'), 'rotulo' => $d->format('d/m')];
            }
        } elseif ($agrupamento === 'mes') {
            $d = $d->modify('first day of this month');
            for (; $d <= $ultimo && count($lista) < 400; $d = $d->modify('+1 month')) {
                $lista[] = ['chave' => $d->format('Y-m'), 'rotulo' => $meses[(int)$d->format('n') - 1] . '/' . $d->format('y')];
            }
        } else {
            for ($a = (int)$d->format('Y'); $a <= (int)$ultimo->format('Y') && count($lista) < 200; $a++) {
                $lista[] = ['chave' => (string)$a, 'rotulo' => (string)$a];
            }
        }
        return $lista;
    }

    /** Expressão SQL que agrupa uma coluna de data no formato das chaves de baldes(). */
    public static function sqlBalde(string $coluna, string $agrupamento): string
    {
        return match ($agrupamento) {
            'dia' => "DATE_FORMAT($coluna, '%Y-%m-%d')",
            'mes' => "DATE_FORMAT($coluna, '%Y-%m')",
            default => "DATE_FORMAT($coluna, '%Y')",
        };
    }
}
