<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Pdf;
use PDO;
use PDOException;

/**
 * Etiquetas dos exemplares: modelos (medidas em milímetros), distribuição nas folhas
 * e geração do PDF em tamanho real.
 *
 * Regras:
 *   * As etiquetas têm SOMENTE texto escrito. O código do exemplar (ex.: BDP-000001) é o
 *     elemento de maior destaque e é digitado no sistema; não há código de barras, QR Code
 *     ou qualquer outro código visual.
 *   * Só exemplares cadastrados recebem etiqueta (livro sem exemplar físico não tem código).
 *   * Cada exemplar aparece uma única vez por geração, na ordem escolhida.
 *   * A CDD impressa é sempre a APROVADA no módulo de classificação. Sem CDD aprovada, a
 *     etiqueta patrimonial sai sem CDD (nada é inventado) e a de lombada sai provisória,
 *     só com o código BDP. Código de autor (Cutter) não é impresso: o sistema ainda não tem
 *     um código de autor validado (o da planilha antiga é só referência).
 *   * A localização impressa é a confirmada do exemplar (nunca a localização antiga da planilha).
 *   * Nenhum dado de aluno, senha ou token entra nas etiquetas.
 *   * O mesmo cálculo de posições serve para a pré-visualização (HTML) e para o PDF.
 */
final class EtiquetaService
{
    use ConsultasPdo;

    private const MM_POR_PONTO = 25.4 / 72;
    public const CODIGO_LEGIVEL = 8.0; // pt: abaixo disso o código fica difícil de ler e digitar

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
     * Só há elementos de TEXTO (x, y = linha de base, tamanho em pt, fonte, alinhamento e papel):
     * nenhuma etiqueta desenha código de barras, QR Code ou outro código visual.
     *
     * Patrimonial (modelo simples):      Lombada:
     *     BIBLIOTECA DOM PEDRO II            869.3        ← CDD aprovada (se houver)
     *        BDP-000001  ← maior texto       LIT-E03      ← localização confirmada (opcional)
     *     (título/CDD/local, opcionais)      BDP-000001
     * Lombada sem CDD aprovada = etiqueta provisória só com o código BDP.
     * Papéis: biblioteca, codigo, titulo, detalhe, cdd, local.
     */
    public static function elementos(array $modelo, array $ex, string $biblioteca): array
    {
        $w = (float)$modelo['etiqueta_largura'];
        $h = (float)$modelo['etiqueta_altura'];
        $p = self::margemInterna($w, $h);
        $interna = $w - 2 * $p;
        $util = $h - 2 * $p;
        $codigo = (string)$ex['codigo_individual'];
        $cdd = !empty($modelo['mostrar_cdd']) ? self::cddAprovada($ex) : null;
        $local = !empty($modelo['mostrar_local']) ? self::localCurto($ex) : null;
        $local = $local !== null && $local !== '' ? $local : null;
        $limitar = fn (float $v, float $min, float $max) => max($min, min($max, $v));

        // Cada linha: [texto, tamanho, fonte, papel]. O código é sempre a maior.
        if (($modelo['tipo'] ?? '') === 'lombada') {
            $linhas = [];
            if ($cdd !== null) {
                $linhas[] = [$cdd, $limitar($h * 0.6, 7, 18), 'negrito', 'cdd'];
                if ($local !== null) {
                    $linhas[] = [$local, $limitar($h * 0.3, 5, 9), 'normal', 'local'];
                }
                $linhas[] = [$codigo, $limitar($h * 0.42, 6, 11), 'negrito', 'codigo'];
            } else {
                // Provisória: só o código BDP, grande e centralizado.
                $linhas[] = [$codigo, $limitar($h * 0.75, 6, 24), 'negrito', 'codigo'];
            }
        } else {
            $linhas = [];
            if (!empty($modelo['mostrar_biblioteca'])) {
                $linhas[] = [mb_strtoupper($biblioteca), $limitar($h * 0.36, 5, 9), 'negrito', 'biblioteca'];
            }
            $linhas[] = [$codigo, $limitar($h * 0.85, 8, 28), 'negrito', 'codigo'];
            $titulo = !empty($modelo['mostrar_titulo']) ? trim((string)($ex['titulo'] ?? '')) : '';
            if ($titulo !== '') {
                $linhas[] = [$titulo, $limitar($h * 0.22, 4.5, 7.5), 'normal', 'titulo'];
            }
            $detalhe = implode(' · ', array_filter([$cdd !== null ? 'CDD ' . $cdd : null, $local]));
            if ($detalhe !== '') {
                $linhas[] = [$detalhe, $limitar($h * 0.22, 4.5, 7.5), 'negrito', 'detalhe'];
            }
        }

        // Ajusta à largura (encolhe só o necessário) e à altura (primeiro diminui o código até
        // 8 pt; se ainda não couber, as linhas opcionais saem de baixo para cima).
        $linhas = array_map(fn ($l) => [$l[0], self::encolher($l[0], $interna, $l[1], $l[2], $l[3] === 'codigo' ? 6 : 4.5), $l[2], $l[3]], $linhas);
        $altura = fn (array $ls) => array_sum(array_map(fn ($l) => self::alturaLinha($l[1]), $ls));
        while ($altura($linhas) > $util) {
            $i = array_search('codigo', array_column($linhas, 3), true);
            if ($linhas[$i][1] > 8) {
                $linhas[$i][1] -= 0.5;
                continue;
            }
            $opcional = null;
            foreach ($linhas as $j => $l) {
                if (in_array($l[3], ['titulo', 'detalhe', 'local'], true)) {
                    $opcional = $j;
                }
            }
            if ($opcional === null) {
                break;
            }
            array_splice($linhas, $opcional, 1);
        }

        $el = [];
        $y = $p + max(0, ($util - $altura($linhas)) / 2);
        foreach ($linhas as [$texto, $tam, $fonte, $papel]) {
            $el[] = ['tipo' => 'texto', 'papel' => $papel, 'x' => $w / 2, 'y' => $y + $tam * self::MM_POR_PONTO * 0.95,
                'texto' => $papel === 'codigo' ? $texto : Pdf::caber($texto, $interna, $tam, $fonte), 'tamanho' => $tam, 'fonte' => $fonte, 'alinhamento' => 'centro'];
            $y += self::alturaLinha($tam);
        }
        return $el;
    }

    /** A etiqueta de lombada deste exemplar sai provisória (sem CDD aprovada, só com o código BDP)? */
    public static function lombadaProvisoria(array $modelo, array $ex): bool
    {
        return ($modelo['tipo'] ?? '') === 'lombada' && (empty($modelo['mostrar_cdd']) || self::cddAprovada($ex) === null);
    }

    /** Avisos sobre o modelo: código pequeno demais para ler com facilidade. */
    public static function avisos(array $modelo): array
    {
        $codigo = array_values(array_filter(self::elementos($modelo, ['codigo_individual' => 'BDP-000000', 'titulo' => 'Título de exemplo'], 'Biblioteca'), fn ($e) => $e['papel'] === 'codigo'));
        $tam = $codigo[0]['tamanho'] ?? 0;
        if ($tam < self::CODIGO_LEGIVEL) {
            return ['O código BDP sai com ' . self::mm($tam) . ' pt nesta etiqueta. Para ler e digitar sem erro, prefira etiquetas maiores (a partir de 30 × 15 mm) ou desmarque as informações extras.'];
        }
        return [];
    }

    /** Monta o PDF com todas as páginas. $guias desenha o contorno das etiquetas (linhas de recorte). */
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
                    $pdf->texto($slot['x'] + $e['x'], $slot['y'] + $e['y'], $e['texto'], $e['tamanho'], $e['fonte'], $e['alinhamento']);
                }
            }
        }
        return $pdf->gerar();
    }

    /**
     * Uma etiqueta em SVG, com as mesmas posições do PDF (unidade = mm), para a pré-visualização.
     * Fundo branco e texto preto. Os textos vêm escapados; o SVG não carrega nada externo.
     */
    public static function svg(array $modelo, array $ex, string $biblioteca): string
    {
        $w = (float)$modelo['etiqueta_largura'];
        $h = (float)$modelo['etiqueta_altura'];
        $esc = fn (string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ancora = ['esquerda' => 'start', 'centro' => 'middle', 'direita' => 'end'];
        $corpo = '<rect width="' . $w . '" height="' . $h . '" fill="#fff"/>';
        foreach (self::elementos($modelo, $ex, $biblioteca) as $e) {
            $corpo .= '<text x="' . round($e['x'], 3) . '" y="' . round($e['y'], 3) . '" font-size="' . round($e['tamanho'] * self::MM_POR_PONTO, 3) . '" text-anchor="' . $ancora[$e['alinhamento']] . '"'
                . ($e['fonte'] === 'negrito' ? ' font-weight="700"' : '') . '>' . $esc($e['texto']) . '</text>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" class="adm-etiqueta__svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . 'mm" height="' . $h . 'mm" role="img" aria-label="' . $esc('Etiqueta ' . $ex['codigo_individual']) . '"'
            . ' font-family="Helvetica, Arial, sans-serif" fill="#000">' . $corpo . '</svg>';
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

    /** CDD aprovada do exemplar (a CDD antiga da planilha nunca entra). */
    private static function cddAprovada(array $ex): ?string
    {
        $cdd = trim((string)($ex['cdd_aprovada'] ?? ''));
        return $cdd !== '' ? $cdd : null;
    }

    /** Margem interna (mm) entre a borda da etiqueta e o texto, para o recorte não comer letras. */
    private static function margemInterna(float $w, float $h): float
    {
        return max(1.2, min(2.5, min($w, $h) * 0.08));
    }

    /** Altura ocupada por uma linha de texto (mm), com o espaço entre linhas. */
    private static function alturaLinha(float $tamanhoPt): float
    {
        return $tamanhoPt * self::MM_POR_PONTO * 1.2;
    }

    /** Diminui a fonte (até $minimo pt) para o texto caber na largura. */
    private static function encolher(string $texto, float $largura, float $tamanho, string $fonte, float $minimo = 4.5): float
    {
        while ($tamanho > $minimo && Pdf::medir($texto, $tamanho, $fonte) > $largura) {
            $tamanho -= 0.5;
        }
        return $tamanho;
    }

    private static function mm(float $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
    }
}
