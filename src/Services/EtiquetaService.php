<?php

namespace Biblioteca\Services;

use Biblioteca\Support\Code128;
use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Pdf;
use PDO;
use PDOException;

/**
 * Etiquetas dos exemplares: modelos (medidas em milímetros), distribuição nas folhas
 * e geração do PDF em tamanho real.
 *
 * Regras:
 *   * Só exemplares cadastrados recebem etiqueta (livro sem exemplar físico não tem código).
 *   * Cada exemplar aparece uma única vez por geração, na ordem escolhida.
 *   * O código de barras (Code 128) representa só o código do exemplar, ex.: BDP-000001.
 *   * A CDD impressa é sempre a APROVADA no módulo de classificação. Sem CDD aprovada, a
 *     etiqueta patrimonial sai sem CDD (nada é inventado) e a de lombada pode ser pulada.
 *   * A localização impressa é a confirmada do exemplar (nunca a localização antiga da planilha).
 *   * Nenhum dado de aluno, senha ou token entra nas etiquetas.
 *   * O mesmo cálculo de posições serve para a pré-visualização (HTML) e para o PDF.
 */
final class EtiquetaService
{
    use ConsultasPdo;

    private const MM_POR_PONTO = 25.4 / 72;
    public const MODULO_MINIMO = 0.19; // mm: abaixo disso, impressoras comuns podem borrar as barras

    public function __construct(private PDO $pdo)
    {
    }

    // ---------------------------------------------------------------
    // Modelos
    // ---------------------------------------------------------------

    public function modelos(bool $soAtivos = true): array
    {
        return $this->todas('SELECT * FROM etiqueta_modelos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ativo DESC, tipo, nome');
    }

    public function modelo(int $id): ?array
    {
        return $this->linha('SELECT * FROM etiqueta_modelos WHERE id = ?', [$id]);
    }

    public function salvarModelo(array $dados, int $responsavelId, ?int $id = null): int
    {
        $d = self::validarModelo($dados);
        $colunas = array_keys($d);
        return $this->transacao(function () use ($d, $colunas, $responsavelId, $id) {
            try {
                if ($id === null) {
                    $this->executar('INSERT INTO etiqueta_modelos (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')', array_values($d));
                    $id = (int)$this->pdo->lastInsertId();
                } else {
                    if (!$this->valor('SELECT id FROM etiqueta_modelos WHERE id = ? FOR UPDATE', [$id])) {
                        throw new AcervoException('Modelo não encontrado.');
                    }
                    $this->executar('UPDATE etiqueta_modelos SET ' . implode(' = ?, ', $colunas) . ' = ? WHERE id = ?', [...array_values($d), $id]);
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new AcervoException('Já existe um modelo com este nome.');
                }
                throw $e;
            }
            $this->registrarAcao($responsavelId, 'etiqueta.modelo', 'etiqueta_modelo', $id, ['nome' => $d['nome']]);
            return $id;
        });
    }

    public function definirAtivo(int $id, bool $ativo, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $ativo, $responsavelId) {
            if ($this->executar('UPDATE etiqueta_modelos SET ativo = ? WHERE id = ?', [$ativo ? 1 : 0, $id]) > 0) {
                $this->registrarAcao($responsavelId, $ativo ? 'etiqueta.modelo_ativar' : 'etiqueta.modelo_desativar', 'etiqueta_modelo', $id);
            }
        });
    }

    /** Validação das medidas (em mm). As etiquetas precisam caber na página. */
    public static function validarModelo(array $dados): array
    {
        $nome = trim(preg_replace('/\s+/u', ' ', (string)($dados['nome'] ?? '')) ?? '');
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 100) {
            throw new AcervoException('Informe o nome do modelo (3 a 100 caracteres).');
        }
        $tipo = ($dados['tipo'] ?? '') === 'lombada' ? 'lombada' : 'patrimonial';
        $num = function (string $campo, string $rotulo, float $min, float $max) use ($dados): float {
            $v = str_replace(',', '.', trim((string)($dados[$campo] ?? '')));
            if ($v === '' || !is_numeric($v) || (float)$v < $min || (float)$v > $max) {
                throw new AcervoException("$rotulo: informe um valor entre " . str_replace('.', ',', (string)$min) . ' e ' . str_replace('.', ',', (string)$max) . ' mm.');
            }
            return round((float)$v, 2);
        };
        $int = function (string $campo, string $rotulo, int $min, int $max) use ($dados): int {
            $v = trim((string)($dados[$campo] ?? ''));
            if (!preg_match('/^\d+$/', $v) || (int)$v < $min || (int)$v > $max) {
                throw new AcervoException("$rotulo: informe um número inteiro de $min a $max.");
            }
            return (int)$v;
        };
        $d = [
            'nome' => $nome,
            'tipo' => $tipo,
            'pagina_largura' => $num('pagina_largura', 'Largura da página', 20, 600),
            'pagina_altura' => $num('pagina_altura', 'Altura da página', 10, 900),
            'etiqueta_largura' => $num('etiqueta_largura', 'Largura da etiqueta', 15, 600),
            'etiqueta_altura' => $num('etiqueta_altura', 'Altura da etiqueta', 8, 900),
            'margem_superior' => $num('margem_superior', 'Margem superior', 0, 200),
            'margem_esquerda' => $num('margem_esquerda', 'Margem esquerda', 0, 200),
            'espaco_horizontal' => $num('espaco_horizontal', 'Espaço entre colunas', 0, 100),
            'espaco_vertical' => $num('espaco_vertical', 'Espaço entre linhas', 0, 100),
            'colunas' => $int('colunas', 'Colunas', 1, 20),
            'linhas' => $int('linhas', 'Linhas', 1, 40),
            'codigo_barras' => !empty($dados['codigo_barras']) ? 1 : 0,
            'mostrar_biblioteca' => !empty($dados['mostrar_biblioteca']) ? 1 : 0,
            'mostrar_titulo' => !empty($dados['mostrar_titulo']) ? 1 : 0,
            'mostrar_cdd' => !empty($dados['mostrar_cdd']) ? 1 : 0,
            'mostrar_local' => !empty($dados['mostrar_local']) ? 1 : 0,
        ];
        $largura = $d['margem_esquerda'] + $d['colunas'] * $d['etiqueta_largura'] + ($d['colunas'] - 1) * $d['espaco_horizontal'];
        $altura = $d['margem_superior'] + $d['linhas'] * $d['etiqueta_altura'] + ($d['linhas'] - 1) * $d['espaco_vertical'];
        if ($largura > $d['pagina_largura'] + 0.05) {
            throw new AcervoException('As colunas não cabem na largura da página: ' . self::mm($largura) . ' mm de ' . self::mm($d['pagina_largura']) . ' mm. Reduza a margem, o espaço ou a largura das etiquetas.');
        }
        if ($altura > $d['pagina_altura'] + 0.05) {
            throw new AcervoException('As linhas não cabem na altura da página: ' . self::mm($altura) . ' mm de ' . self::mm($d['pagina_altura']) . ' mm. Reduza a margem, o espaço ou a altura das etiquetas.');
        }
        return $d;
    }

    // ---------------------------------------------------------------
    // Distribuição e desenho
    // ---------------------------------------------------------------

    /**
     * Distribui os exemplares nas páginas, pulando as $pular primeiras posições da primeira
     * folha (para reaproveitar uma folha já usada em parte).
     * @return list<list<array{x: float, y: float, exemplar: array}>>
     */
    public static function paginar(array $modelo, array $exemplares, int $pular = 0): array
    {
        $porFolha = (int)$modelo['colunas'] * (int)$modelo['linhas'];
        $pular = max(0, min($pular, $porFolha - 1));
        $paginas = [];
        $posicao = $pular;
        foreach (array_values($exemplares) as $ex) {
            $pagina = intdiv($posicao, $porFolha);
            $slot = $posicao % $porFolha;
            $col = $slot % (int)$modelo['colunas'];
            $lin = intdiv($slot, (int)$modelo['colunas']);
            $paginas[$pagina][] = [
                'x' => (float)$modelo['margem_esquerda'] + $col * ((float)$modelo['etiqueta_largura'] + (float)$modelo['espaco_horizontal']),
                'y' => (float)$modelo['margem_superior'] + $lin * ((float)$modelo['etiqueta_altura'] + (float)$modelo['espaco_vertical']),
                'exemplar' => $ex,
            ];
            $posicao++;
        }
        return array_values($paginas);
    }

    /**
     * Elementos de uma etiqueta, com posições em mm relativas ao canto superior esquerdo dela.
     * Tipos: texto (x, y = linha de base, tamanho em pt, fonte, alinhamento) e barras (x, y, largura, altura, modulo).
     */
    public static function elementos(array $modelo, array $ex, string $biblioteca): array
    {
        $w = (float)$modelo['etiqueta_largura'];
        $h = (float)$modelo['etiqueta_altura'];
        $p = max(1.2, min(2.5, min($w, $h) * 0.08));
        $interna = $w - 2 * $p;
        $codigo = (string)$ex['codigo_individual'];
        $cdd = $modelo['mostrar_cdd'] ? ($ex['cdd_aprovada'] ?? null) : null;
        $local = $modelo['mostrar_local'] ? self::localCurto($ex) : null;
        $alt = fn (float $pt) => $pt * self::MM_POR_PONTO;
        $limitar = fn (float $v, float $min, float $max) => max($min, min($max, $v));
        $el = [];

        if ($modelo['tipo'] === 'lombada') {
            $linhas = [];
            if ($modelo['mostrar_biblioteca']) {
                $linhas[] = ['BDP', $limitar($h * 0.2, 4, 6), 'normal'];
            }
            if ($cdd !== null) {
                $linhas[] = [$cdd, $limitar($h * 0.5, 7, 16), 'negrito'];
            }
            if ($local !== null && $local !== '') {
                $linhas[] = [$local, $limitar($h * 0.26, 5, 9), 'normal'];
            }
            $linhas[] = [$codigo, $limitar($h * 0.28, 5, 9), 'negrito'];
            $alturaBarras = $modelo['codigo_barras'] ? $limitar($h * 0.3, 3, 12) : 0;
            $total = array_sum(array_map(fn ($l) => $alt($l[1]) * 1.15, $linhas)) + ($alturaBarras ? $alturaBarras + 0.8 : 0);
            $y = max($p * 0.5, ($h - $total) / 2);
            foreach ($linhas as [$texto, $tam, $fonte]) {
                $tam = self::encolher($texto, $interna, $tam, $fonte);
                $el[] = ['tipo' => 'texto', 'x' => $w / 2, 'y' => $y + $alt($tam) * 0.85, 'texto' => Pdf::caber($texto, $interna, $tam, $fonte), 'tamanho' => $tam, 'fonte' => $fonte, 'alinhamento' => 'centro'];
                $y += $alt($tam) * 1.15;
            }
            if ($alturaBarras) {
                $el[] = self::barras($codigo, $p, $y + 0.4, $interna, $alturaBarras);
            }
            return $el;
        }

        // Patrimonial: biblioteca e CDD no alto, barras no meio, código e título embaixo.
        $tamTopo = $limitar($h * 0.22, 4.5, 8);
        $tamCodigo = $limitar($h * 0.34, 6, 12);
        $tamRodape = $limitar($h * 0.2, 4.5, 7.5);
        $topo = $p;
        if ($modelo['mostrar_biblioteca'] || $cdd !== null) {
            $baseTopo = $p + $alt($tamTopo) * 0.85;
            $larguraCdd = 0;
            if ($cdd !== null) {
                $textoCdd = 'CDD ' . $cdd;
                $larguraCdd = Pdf::medir($textoCdd, $tamTopo, 'negrito');
                $el[] = ['tipo' => 'texto', 'x' => $w - $p, 'y' => $baseTopo, 'texto' => $textoCdd, 'tamanho' => $tamTopo, 'fonte' => 'negrito', 'alinhamento' => 'direita'];
            }
            if ($modelo['mostrar_biblioteca']) {
                $el[] = ['tipo' => 'texto', 'x' => $p, 'y' => $baseTopo, 'texto' => Pdf::caber(mb_strtoupper($biblioteca), $interna - $larguraCdd - ($larguraCdd ? 1.5 : 0), $tamTopo, 'negrito'), 'tamanho' => $tamTopo, 'fonte' => 'negrito', 'alinhamento' => 'esquerda'];
            }
            $topo = $p + $alt($tamTopo) * 1.25;
        }
        $titulo = $modelo['mostrar_titulo'] ? trim((string)$ex['titulo']) : '';
        $temRodape = $titulo !== '' || ($local !== null && $local !== '');
        $base = $h - $p;
        if ($temRodape) {
            $larguraLocal = 0;
            if ($local !== null && $local !== '') {
                $larguraLocal = Pdf::medir($local, $tamRodape, 'negrito');
                $el[] = ['tipo' => 'texto', 'x' => $w - $p, 'y' => $base - $alt($tamRodape) * 0.15, 'texto' => $local, 'tamanho' => $tamRodape, 'fonte' => 'negrito', 'alinhamento' => 'direita'];
            }
            if ($titulo !== '') {
                $el[] = ['tipo' => 'texto', 'x' => $p, 'y' => $base - $alt($tamRodape) * 0.15, 'texto' => Pdf::caber($titulo, $interna - $larguraLocal - ($larguraLocal ? 1.5 : 0), $tamRodape), 'tamanho' => $tamRodape, 'fonte' => 'normal', 'alinhamento' => 'esquerda'];
            }
            $base -= $alt($tamRodape) * 1.2;
        }
        $el[] = ['tipo' => 'texto', 'x' => $w / 2, 'y' => $base - $alt($tamCodigo) * 0.15, 'texto' => $codigo, 'tamanho' => self::encolher($codigo, $interna, $tamCodigo, 'negrito'), 'fonte' => 'negrito', 'alinhamento' => 'centro'];
        $base -= $alt($tamCodigo) * 1.05;
        if ($modelo['codigo_barras']) {
            $alturaBarras = $base - $topo - 0.4;
            if ($alturaBarras >= 3) {
                $el[] = self::barras($codigo, $p, $topo + 0.2, $interna, min($alturaBarras, 18));
            }
        }
        return $el;
    }

    /** Avisos sobre o modelo (barras finas demais, etiqueta pequena). */
    public static function avisos(array $modelo): array
    {
        $avisos = [];
        if ($modelo['codigo_barras']) {
            $w = (float)$modelo['etiqueta_largura'] - 2 * max(1.2, min(2.5, min((float)$modelo['etiqueta_largura'], (float)$modelo['etiqueta_altura']) * 0.08));
            $modulo = $w / (Code128::largura('BDP-000000') + 2 * Code128::ZONA_SILENCIO);
            if ($modulo < self::MODULO_MINIMO) {
                $avisos[] = 'A etiqueta é estreita para o código de barras (barras de ' . self::mm($modulo) . ' mm). Faça um teste de leitura antes de imprimir em lote.';
            }
        }
        return $avisos;
    }

    /** Monta o PDF com todas as páginas. $guias desenha o contorno das etiquetas (para teste em papel comum). */
    public static function pdf(array $modelo, array $exemplares, int $pular, string $biblioteca, bool $guias = false): string
    {
        $pdf = new Pdf('Etiquetas — ' . $biblioteca);
        foreach (self::paginar($modelo, $exemplares, $pular) as $pagina) {
            $pdf->novaPagina((float)$modelo['pagina_largura'], (float)$modelo['pagina_altura']);
            foreach ($pagina as $slot) {
                if ($guias) {
                    $pdf->contorno($slot['x'], $slot['y'], (float)$modelo['etiqueta_largura'], (float)$modelo['etiqueta_altura'], 0.1, 0.75);
                }
                foreach (self::elementos($modelo, $slot['exemplar'], $biblioteca) as $e) {
                    if ($e['tipo'] === 'barras') {
                        foreach (Code128::barras($e['codigo']) as [$bx, $bl]) {
                            $pdf->retangulo($slot['x'] + $e['x'] + ($bx + Code128::ZONA_SILENCIO) * $e['modulo'], $slot['y'] + $e['y'], $bl * $e['modulo'], $e['altura']);
                        }
                    } else {
                        $pdf->texto($slot['x'] + $e['x'], $slot['y'] + $e['y'], $e['texto'], $e['tamanho'], $e['fonte'], $e['alinhamento']);
                    }
                }
            }
        }
        return $pdf->gerar();
    }

    /** Código curto da localização para a etiqueta: código cadastrado ou "Estante · Prateleira". */
    public static function localCurto(array $ex): ?string
    {
        if (($ex['localizacao_id'] ?? null) === null) {
            return null;
        }
        if (($ex['local_codigo'] ?? '') !== '' && $ex['local_codigo'] !== null) {
            return $ex['local_codigo'];
        }
        $partes = array_filter([(string)($ex['estante'] ?? ''), (string)($ex['prateleira'] ?? '')], fn ($v) => $v !== '');
        return $partes ? implode(' · ', $partes) : (string)$ex['setor'];
    }

    private static function barras(string $codigo, float $x, float $y, float $largura, float $altura): array
    {
        $modulos = Code128::largura($codigo) + 2 * Code128::ZONA_SILENCIO;
        $modulo = min(0.5, $largura / $modulos);
        return ['tipo' => 'barras', 'codigo' => $codigo, 'x' => $x + ($largura - $modulos * $modulo) / 2, 'y' => $y, 'largura' => $modulos * $modulo, 'altura' => $altura, 'modulo' => $modulo];
    }

    /** Diminui a fonte (até 4,5 pt) para o texto caber. */
    private static function encolher(string $texto, float $largura, float $tamanho, string $fonte): float
    {
        while ($tamanho > 4.5 && Pdf::medir($texto, $tamanho, $fonte) > $largura) {
            $tamanho -= 0.5;
        }
        return $tamanho;
    }

    private static function mm(float $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
    }
}
