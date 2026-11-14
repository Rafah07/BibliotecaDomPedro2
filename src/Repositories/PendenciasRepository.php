<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Pendências de conferência e indicadores do Inventário (migração 140). Somente leitura.
 *
 * Cada tipo de pendência tem uma contagem REAL (COUNT sem limite) e uma lista paginada: nada
 * fica escondido porque uma consulta atingiu um limite. As ações de cada item abrem os painéis
 * que já existem na conferência (verificar dados, registrar exemplar, mover exemplar...).
 *
 * Quantidade histórica × encontrada: a planilha pode ter várias linhas da mesma obra (inclusive
 * repetidas). As quantidades NUNCA são somadas às cegas:
 *   * 1 linha com quantidade                → quantidade confiável;
 *   * várias linhas (ou linhas sem número)  → só uma FAIXA possível: mínimo = maior quantidade de
 *                                             uma linha; máximo = soma das quantidades + 1 por linha
 *                                             sem quantidade. Fica marcado como "não confiável";
 *   * nenhuma linha informa quantidade      → "não informada".
 * A diferença é uma divergência A INVESTIGAR, nunca "livros perdidos".
 */
final class PendenciasRepository
{
    use ConsultasPdo;

    public const TIPOS = [
        'nao_verificadas' => ['Obras com dados não verificados', 'circle-help', 'amber'],
        'linhas_sem_vinculo' => ['Registros importados sem exemplar', 'file-spreadsheet', 'muted'],
        'sem_localizacao' => ['Exemplares sem localização definida', 'map-pin-off', 'amber'],
        'conservacao' => ['Conservação não avaliada', 'list-checks', 'indigo'],
        'quantidade' => ['Divergências de quantidade', 'git-compare-arrows', 'red'],
        'duplicadas' => ['Possíveis obras duplicadas', 'copy', 'red'],
        'alertas' => ['Obras com alertas cadastrais', 'triangle-alert', 'amber'],
        'sem_bdp' => ['Exemplares sem código BDP válido', 'tag', 'red'],
    ];

    /** Agregado das linhas da planilha por obra (para a comparação de quantidades). */
    private const AGREGADO_PLANILHA = 'SELECT r.livro_id, COUNT(*) AS linhas, COUNT(r.quantidade_informada) AS linhas_qtd,
                                              MAX(r.quantidade_informada) AS qmax, COALESCE(SUM(r.quantidade_informada), 0) AS qsoma
                                       FROM registros_legados r GROUP BY r.livro_id';

    private const SEM_TITULO = "'Sem título (a conferir)'";

    public function __construct(private PDO $pdo)
    {
    }

    // ---------------------------------------------------------------
    // Quantidade histórica × encontrada
    // ---------------------------------------------------------------

    /**
     * Comparação da obra: quantidade da planilha (confiável, faixa ou não informada), exemplares
     * cadastrados, encontrados no inventário e a diferença.
     */
    public function quantidadeObra(int $livroId, ?int $inventarioId): array
    {
        $p = $this->linha('SELECT COUNT(*) AS linhas, COUNT(quantidade_informada) AS linhas_qtd, MAX(quantidade_informada) AS qmax,
                                  COALESCE(SUM(quantidade_informada), 0) AS qsoma, COUNT(DISTINCT arquivo) AS arquivos
                           FROM registros_legados WHERE livro_id = ?', [$livroId]) ?? [];
        $cadastrados = (int)$this->valor("SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND situacao <> 'baixado'", [$livroId]);
        $encontrados = $inventarioId
            ? (int)$this->valor("SELECT COUNT(*) FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id
                                 WHERE i.inventario_id = ? AND i.livro_id = ? AND i.conferido_em IS NOT NULL AND e.situacao <> 'baixado'", [$inventarioId, $livroId])
            : (int)$this->valor("SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND situacao <> 'baixado' AND conferido_em IS NOT NULL", [$livroId]);
        return self::compararQuantidade((int)($p['linhas'] ?? 0), (int)($p['linhas_qtd'] ?? 0), $p['qmax'] !== null ? (int)$p['qmax'] : null, (int)($p['qsoma'] ?? 0), $encontrados)
            + ['cadastrados' => $cadastrados, 'encontrados' => $encontrados, 'arquivos' => (int)($p['arquivos'] ?? 0)];
    }

    /**
     * Regra da comparação (também usada nos testes).
     * @return array{situacao: string, linhas: int, linhas_qtd: int, historica: ?int, minimo: ?int, maximo: ?int, diferenca: ?int, mensagem: string}
     *   situacao: sem_planilha | nao_informada | confiavel | faixa
     */
    public static function compararQuantidade(int $linhas, int $linhasQtd, ?int $qmax, int $qsoma, int $encontrados): array
    {
        $r = ['linhas' => $linhas, 'linhas_qtd' => $linhasQtd, 'historica' => null, 'minimo' => null, 'maximo' => null, 'diferenca' => null, 'divergente' => false];
        if ($linhas === 0) {
            return $r + ['situacao' => 'sem_planilha', 'mensagem' => 'Esta obra não veio de planilha: não há quantidade histórica para comparar.'];
        }
        if ($linhasQtd === 0 || $qmax === null) {
            return $r + ['situacao' => 'nao_informada', 'mensagem' => 'A planilha não informou a quantidade (' . $linhas . ' linha(s) importada(s)). Não é possível calcular uma diferença confiável.'];
        }
        if ($linhas === 1) {
            $r['historica'] = $r['minimo'] = $r['maximo'] = $qmax;
            $r['diferenca'] = $qmax - $encontrados;
            $r['divergente'] = $r['diferenca'] !== 0;
            return $r + ['situacao' => 'confiavel', 'mensagem' => $r['diferenca'] === 0 ? 'A quantidade encontrada é igual à informada na planilha.'
                : ($r['diferenca'] > 0 ? 'Diferença de ' . $r['diferenca'] . ' exemplar(es) a investigar (podem estar emprestados, em outra estante ou ainda não registrados).'
                    : 'Foram encontrados ' . (-$r['diferenca']) . ' exemplar(es) a mais do que a planilha informava.')];
        }
        // Várias linhas: podem ser repetições da mesma obra ou unidades diferentes.
        $r['minimo'] = $qmax;
        $r['maximo'] = $qsoma + ($linhas - $linhasQtd);
        if ($encontrados < $r['minimo']) {
            $r['diferenca'] = $r['minimo'] - $encontrados;
            $r['divergente'] = true;
            $msg = 'Faltam pelo menos ' . $r['diferenca'] . ' exemplar(es) para chegar ao menor valor possível. Investigue antes de concluir.';
        } elseif ($encontrados > $r['maximo']) {
            $r['diferenca'] = $r['maximo'] - $encontrados;
            $r['divergente'] = true;
            $msg = 'Foram encontrados mais exemplares do que a planilha poderia indicar.';
        } else {
            $r['diferenca'] = 0;
            $msg = 'A quantidade encontrada está dentro da faixa possível.';
        }
        return $r + ['situacao' => 'faixa', 'mensagem' => 'A planilha tem ' . $linhas . ' linhas desta obra (podem ser repetições): a quantidade histórica não é confiável. ' . $msg];
    }

    // ---------------------------------------------------------------
    // Pendências
    // ---------------------------------------------------------------

    /** Contagem real de cada tipo de pendência. */
    public function contagens(?int $inventarioId): array
    {
        $c = [];
        foreach (array_keys(self::TIPOS) as $tipo) {
            [$from, $where, $params] = $this->consulta($tipo, $inventarioId);
            $c[$tipo] = (int)$this->valor("SELECT COUNT(*) $from WHERE $where", $params);
        }
        return $c;
    }

    /**
     * Itens de um tipo de pendência, com pesquisa e paginação.
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(string $tipo, ?int $inventarioId, string $q = '', int $pagina = 1, int $porPagina = 25): array
    {
        if (!isset(self::TIPOS[$tipo])) {
            $tipo = 'nao_verificadas';
        }
        [$from, $where, $params] = $this->consulta($tipo, $inventarioId);
        foreach (self::termos($q) as $t) {
            $like = self::like($t);
            $where .= ' AND (l.titulo LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)'
                . (in_array($tipo, ['sem_localizacao', 'conservacao', 'sem_bdp'], true) ? ' OR e.codigo_individual LIKE ?' : '')
                . ($tipo === 'linhas_sem_vinculo' ? ' OR r.titulo_original LIKE ? OR r.arquivo LIKE ?' : '') . ')';
            array_push($params, $like, $like);
            if (in_array($tipo, ['sem_localizacao', 'conservacao', 'sem_bdp'], true)) {
                $params[] = $like;
            }
            if ($tipo === 'linhas_sem_vinculo') {
                array_push($params, $like, $like);
            }
        }
        $porPagina = max(1, min(100, $porPagina));
        $total = (int)$this->valor("SELECT COUNT(*) $from WHERE $where", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $autores = '(SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR \', \') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores';
        $titulo = 'COALESCE(l.titulo, ' . self::SEM_TITULO . ') AS titulo';
        [$colunas, $ordem] = match ($tipo) {
            'linhas_sem_vinculo' => ["r.id, r.linha, r.arquivo, r.titulo_original, r.autor_original, r.quantidade_informada, l.id AS livro_id, $titulo, l.dados_verificados_em, $autores", 'l.titulo IS NULL, l.titulo, r.arquivo, r.linha'],
            'sem_localizacao', 'conservacao', 'sem_bdp' => ["e.id, e.codigo_individual, e.estado_conservacao, e.conservacao_avaliada_em, e.situacao, e.localizacao_id, lo.setor, lo.estante, lo.prateleira, lo.complemento, lo.estante_id,
                                                             l.id AS livro_id, $titulo, l.dados_verificados_em, $autores", 'l.titulo IS NULL, l.titulo, e.codigo_individual'],
            'quantidade' => ["l.id, l.id AS livro_id, $titulo, l.dados_verificados_em, $autores, q.linhas, q.linhas_qtd, q.qmax, q.qsoma, COALESCE(enc.n, 0) AS encontrados", 'l.titulo IS NULL, l.titulo, l.id'],
            'duplicadas', 'alertas' => ["l.id, l.id AS livro_id, $titulo, l.dados_verificados_em, $autores,
                                         (SELECT GROUP_CONCAT(p.mensagem ORDER BY p.id SEPARATOR ' · ') FROM livro_pendencias p WHERE p.livro_id = l.id AND p.resolvida_em IS NULL" . ($tipo === 'duplicadas' ? " AND p.tipo = 'duplicidade'" : " AND p.tipo <> 'duplicidade'") . ") AS mensagens", 'l.titulo IS NULL, l.titulo, l.id'],
            default => ["l.id, l.id AS livro_id, $titulo, l.isbn, l.isbn_original, l.editora, l.ano_publicacao, $autores,
                         (SELECT COUNT(*) FROM registros_legados rr WHERE rr.livro_id = l.id AND rr.exemplar_id IS NULL) AS linhas_sem_vinculo,
                         (SELECT COUNT(*) FROM exemplares ee WHERE ee.livro_id = l.id AND ee.situacao <> 'baixado') AS exemplares", 'l.titulo IS NULL, l.titulo, l.id'],
        };
        $itens = $this->todas("SELECT $colunas $from WHERE $where ORDER BY $ordem LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        if ($tipo === 'quantidade') {
            foreach ($itens as &$i) {
                $i['comparacao'] = self::compararQuantidade((int)$i['linhas'], (int)$i['linhas_qtd'], $i['qmax'] !== null ? (int)$i['qmax'] : null, (int)$i['qsoma'], (int)$i['encontrados']);
            }
            unset($i);
        }
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'tipo' => $tipo];
    }

    /** FROM, WHERE e parâmetros de cada tipo (a mesma consulta conta e lista: os números batem). */
    private function consulta(string $tipo, ?int $inventarioId): array
    {
        $ativa = 'l.arquivado_em IS NULL';
        return match ($tipo) {
            'nao_verificadas' => ['FROM livros l', "$ativa AND l.dados_verificados_em IS NULL", []],
            'linhas_sem_vinculo' => ['FROM registros_legados r JOIN livros l ON l.id = r.livro_id', "$ativa AND r.exemplar_id IS NULL", []],
            'sem_localizacao' => ['FROM exemplares e JOIN livros l ON l.id = e.livro_id LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id',
                "e.situacao <> 'baixado' AND (e.localizacao_id IS NULL OR lo.estante_id IS NULL)", []],
            'conservacao' => ['FROM exemplares e JOIN livros l ON l.id = e.livro_id LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id',
                "e.situacao <> 'baixado' AND (e.estado_conservacao = 'nao_avaliado' OR e.conservacao_avaliada_em IS NULL)", []],
            'sem_bdp' => ['FROM exemplares e JOIN livros l ON l.id = e.livro_id LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id',
                "e.situacao <> 'baixado' AND (e.codigo_individual IS NULL OR e.codigo_individual NOT REGEXP '^BDP-[0-9]{6}$')", []],
            'duplicadas' => ['FROM livros l', "$ativa AND EXISTS (SELECT 1 FROM livro_pendencias p WHERE p.livro_id = l.id AND p.resolvida_em IS NULL AND p.tipo = 'duplicidade')", []],
            'alertas' => ['FROM livros l', "$ativa AND EXISTS (SELECT 1 FROM livro_pendencias p WHERE p.livro_id = l.id AND p.resolvida_em IS NULL AND p.tipo <> 'duplicidade')", []],
            // Só obras já VERIFICADAS (em que a equipe já trabalhou): antes disso, toda obra ainda
            // não conferida apareceria como divergente. Encontrados = neste inventário (ou, sem
            // inventário, exemplares já conferidos alguma vez).
            'quantidade' => $this->consultaQuantidade($inventarioId),
            default => ['FROM livros l', '1 = 0', []],
        };
    }

    private function consultaQuantidade(?int $inventarioId): array
    {
        $enc = $inventarioId
            ? "(SELECT i.livro_id, COUNT(*) AS n FROM inventario_itens i JOIN exemplares x ON x.id = i.exemplar_id
                WHERE i.inventario_id = " . (int)$inventarioId . " AND i.conferido_em IS NOT NULL AND x.situacao <> 'baixado' GROUP BY i.livro_id)"
            : "(SELECT livro_id, COUNT(*) AS n FROM exemplares WHERE situacao <> 'baixado' AND conferido_em IS NOT NULL GROUP BY livro_id)";
        $from = 'FROM livros l JOIN (' . self::AGREGADO_PLANILHA . ") q ON q.livro_id = l.id LEFT JOIN $enc enc ON enc.livro_id = l.id";
        $n = 'COALESCE(enc.n, 0)';
        $where = "l.arquivado_em IS NULL AND l.dados_verificados_em IS NOT NULL AND q.linhas_qtd > 0
                  AND ((q.linhas = 1 AND q.qmax <> $n)
                       OR (q.linhas > 1 AND ($n < q.qmax OR $n > q.qsoma + (q.linhas - q.linhas_qtd))))";
        return [$from, $where, []];
    }

    // ---------------------------------------------------------------
    // Indicadores (obras, exemplares, importação e organização física, sempre separados)
    // ---------------------------------------------------------------

    public function indicadoresGerais(): array
    {
        $o = $this->linha('SELECT COUNT(*) AS obras, COALESCE(SUM(dados_verificados_em IS NOT NULL), 0) AS obras_verificadas FROM livros WHERE arquivado_em IS NULL') ?? [];
        $e = $this->linha("SELECT COUNT(*) AS exemplares,
                                  COALESCE(SUM(lo.estante_id IS NOT NULL), 0) AS com_localizacao,
                                  COALESCE(SUM(lo.estante_id IS NULL), 0) AS sem_localizacao,
                                  COALESCE(SUM(e.estado_conservacao = 'nao_avaliado' OR e.conservacao_avaliada_em IS NULL), 0) AS conservacao_pendente,
                                  COALESCE(SUM(e.codigo_individual NOT REGEXP '^BDP-[0-9]{6}$'), 0) AS sem_bdp
                           FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                           LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                           WHERE e.situacao <> 'baixado'") ?? [];
        $r = $this->linha('SELECT COUNT(*) AS registros, COALESCE(SUM(r.exemplar_id IS NOT NULL), 0) AS registros_vinculados
                           FROM registros_legados r JOIN livros l ON l.id = r.livro_id AND l.arquivado_em IS NULL') ?? [];
        $f = $this->linha('SELECT (SELECT COUNT(*) FROM estantes WHERE ativa = 1) AS estantes, (SELECT COUNT(*) FROM localizacoes lo JOIN estantes es ON es.id = lo.estante_id WHERE lo.ativa = 1 AND es.ativa = 1) AS prateleiras') ?? [];
        $x = array_map('intval', $o + $e + $r + $f);
        $x['obras_nao_verificadas'] = $x['obras'] - $x['obras_verificadas'];
        $x['registros_sem_vinculo'] = $x['registros'] - $x['registros_vinculados'];
        $x['pct_alocados'] = $x['exemplares'] > 0 ? (int)floor($x['com_localizacao'] / $x['exemplares'] * 100) : 0;
        return $x;
    }

    /** Exemplares alocados por estante (as que têm mais exemplares primeiro). */
    public function alocadosPorEstante(int $limite = 8): array
    {
        return $this->todas("SELECT es.id, es.identificacao, es.nome, es.categoria, COUNT(e.id) AS exemplares
                             FROM estantes es
                             LEFT JOIN localizacoes lo ON lo.estante_id = es.id
                             LEFT JOIN exemplares e ON e.localizacao_id = lo.id AND e.situacao <> 'baixado'
                             WHERE es.ativa = 1 GROUP BY es.id ORDER BY exemplares DESC, es.ordem, es.identificacao LIMIT " . max(1, $limite));
    }
}
