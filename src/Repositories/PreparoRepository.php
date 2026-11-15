<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Consultas do preparo dos exemplares (somente leitura, migração 150):
 *   * Planilha de Códigos BDP: exemplares registrados, na ordem do lote, com a situação do carimbo e
 *     a localização (ou "Aguardando organização"); sempre gerada do banco, nunca de um arquivo.
 *   * Lotes de cadastramento e seus integrantes.
 *   * Indicadores separados: encontrados, carimbo, organização, ocorrências e disponibilidade.
 *   * Movimentação em lote: exemplares filtrados para seleção.
 *   * Ocorrências e Conservação.
 *   * Relatório de Atividades (números calculados dos registros reais: histórico dos exemplares,
 *     verificações das obras, ocorrências e ações administrativas).
 */
final class PreparoRepository
{
    use ConsultasPdo;

    public const FILTRO_CARIMBO = ['aguardando' => 'Aguardando carimbo', 'carimbado' => 'Carimbados', 'todos' => 'Todos'];
    public const FILTRO_ORGANIZACAO = ['todos' => 'Todos', 'aguardando' => 'Aguardando organização (triagem)', 'organizado' => 'Com localização definitiva'];
    public const FILTRO_ENCONTRADO = ['' => 'Todos', 'sim' => 'Encontrados (conferidos)', 'nao' => 'Ainda não conferidos'];

    public const ATIVIDADES = [
        'obras_verificadas' => 'Obras verificadas',
        'exemplares_registrados' => 'Exemplares registrados',
        'codigos_gerados' => 'Códigos BDP gerados e atribuídos',
        'carimbados' => 'Exemplares marcados como carimbados',
        'organizados' => 'Exemplares organizados em estantes',
        'movimentados' => 'Exemplares movimentados',
        'ocorrencias' => 'Ocorrências registradas',
        'obras_manuais' => 'Obras cadastradas manualmente',
        'correcoes' => 'Correções realizadas',
    ];

    private const LOCAL_SQL = "LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id";

    public function __construct(private PDO $pdo)
    {
    }

    // =================================================================
    // Planilha de Códigos BDP / triagem
    // =================================================================

    /**
     * Filtros: carimbo, organizacao, encontrado, lote (id), data (AAAA-MM-DD do cadastro), responsavel (id),
     * q (código, título ou autor), ids (seleção manual), ordem ('lote' = ordem de cadastramento | 'codigo').
     * Baixados nunca aparecem.
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function planilha(array $f, int $pagina = 1, int $porPagina = 50): array
    {
        [$where, $params] = $this->filtrosPlanilha($f);
        $total = (int)$this->valor("SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE $where", $params);
        $limite = '';
        $paginas = 1;
        if ($porPagina > 0) {
            $porPagina = min(1000, $porPagina);
            $paginas = max(1, (int)ceil($total / $porPagina));
            $pagina = max(1, min($pagina, $paginas));
            $limite = ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        }
        $ordem = ($f['ordem'] ?? 'lote') === 'codigo' ? 'e.codigo_individual' : '(e.lote_id IS NULL), lt.numero, e.lote_ordem, e.id';
        $itens = $this->todas("SELECT e.id, e.livro_id, e.codigo_individual, e.carimbo_status, e.carimbado_em, e.carimbo_observacao, e.lote_id, e.lote_ordem, e.criado_em,
                                      e.situacao, e.liberacao_pendente, e.localizacao_id, e.conferido_em, e.estado_conservacao,
                                      l.titulo, l.subtitulo,
                                      (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                      lo.setor, lo.estante, lo.prateleira, lo.complemento,
                                      lt.numero AS lote_numero, lt.equipe AS lote_equipe, lt.criado_em AS lote_criado_em,
                                      a.nome AS cadastrado_por_nome, c.nome AS carimbado_por_nome,
                                      (SELECT COUNT(*) FROM exemplar_ocorrencias o WHERE o.exemplar_id = e.id AND o.status = 'aberta') AS ocorrencias_abertas
                               FROM exemplares e JOIN livros l ON l.id = e.livro_id " . self::LOCAL_SQL . "
                               LEFT JOIN cadastro_lotes lt ON lt.id = e.lote_id
                               LEFT JOIN administradores a ON a.id = e.cadastrado_por
                               LEFT JOIN administradores c ON c.id = e.carimbado_por
                               WHERE $where ORDER BY $ordem$limite", $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** Ids dos exemplares que atendem aos filtros (no máximo $limite), na ordem da planilha. */
    public function idsPlanilha(array $f, int $limite = 1000): array
    {
        [$where, $params] = $this->filtrosPlanilha($f);
        return array_map('intval', array_column($this->todas("SELECT e.id FROM exemplares e JOIN livros l ON l.id = e.livro_id LEFT JOIN cadastro_lotes lt ON lt.id = e.lote_id
                                                              WHERE $where ORDER BY (e.lote_id IS NULL), lt.numero, e.lote_ordem, e.id LIMIT " . max(1, min(5000, $limite)), $params), 'id'));
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtrosPlanilha(array $f): array
    {
        $where = ["e.situacao <> 'baixado'"];
        $params = [];
        $carimbo = (string)($f['carimbo'] ?? 'aguardando');
        if (in_array($carimbo, ['aguardando', 'carimbado'], true)) {
            $where[] = 'e.carimbo_status = ?';
            $params[] = $carimbo;
        }
        $org = (string)($f['organizacao'] ?? 'todos');
        if ($org === 'aguardando') {
            $where[] = "e.localizacao_id IS NULL AND e.situacao <> 'extraviado'";
        } elseif ($org === 'organizado') {
            $where[] = 'e.localizacao_id IS NOT NULL';
        }
        $enc = (string)($f['encontrado'] ?? '');
        if ($enc === 'sim') {
            $where[] = 'e.conferido_em IS NOT NULL';
        } elseif ($enc === 'nao') {
            $where[] = 'e.conferido_em IS NULL';
        }
        if ((int)($f['lote'] ?? 0) > 0) {
            $where[] = 'e.lote_id = ?';
            $params[] = (int)$f['lote'];
        }
        $data = (string)($f['data'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $where[] = 'e.criado_em >= ? AND e.criado_em < DATE_ADD(?, INTERVAL 1 DAY)';
            array_push($params, $data, $data);
        }
        if ((int)($f['responsavel'] ?? 0) > 0) {
            $where[] = 'e.cadastrado_por = ?';
            $params[] = (int)$f['responsavel'];
        }
        if ((int)($f['localizacao'] ?? 0) > 0) {
            $where[] = 'e.localizacao_id = ?';
            $params[] = (int)$f['localizacao'];
        } elseif ((int)($f['estante'] ?? 0) > 0) {
            $where[] = 'e.localizacao_id IN (SELECT id FROM localizacoes WHERE estante_id = ?)';
            $params[] = (int)$f['estante'];
        }
        if (!empty($f['ids']) && is_array($f['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $f['ids']), fn ($i) => $i > 0)));
            $ids = array_slice($ids, 0, 5000);
            $where[] = $ids ? 'e.id IN (' . implode(',', $ids) . ')' : '1 = 0';
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $codigo = EmprestimoRepository::codigoExemplar($q);
            if ($codigo) {
                $where[] = 'e.codigo_individual = ?';
                $params[] = $codigo;
            } else {
                foreach (self::termos($q) as $t) {
                    $where[] = '(l.titulo LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?))';
                    array_push($params, self::like($t), self::like($t));
                }
            }
        }
        return [implode(' AND ', $where), $params];
    }

    /** Pessoas que registraram exemplares (filtro "Responsável"). */
    public function responsaveis(): array
    {
        return $this->todas('SELECT a.id, a.nome, a.equipe FROM administradores a WHERE a.id IN (SELECT DISTINCT cadastrado_por FROM exemplares WHERE cadastrado_por IS NOT NULL) ORDER BY a.nome');
    }

    /** Todas as contas (filtro do Relatório de Atividades). */
    public function administradores(): array
    {
        return $this->todas('SELECT id, nome, equipe FROM administradores ORDER BY nome');
    }

    /** Equipes informadas nas contas e nos lotes. */
    public function equipes(): array
    {
        return array_column($this->todas("SELECT DISTINCT equipe FROM (SELECT equipe FROM administradores WHERE equipe IS NOT NULL AND equipe <> ''
                                                                        UNION SELECT equipe FROM cadastro_lotes WHERE equipe IS NOT NULL AND equipe <> '') x ORDER BY equipe"), 'equipe');
    }

    // =================================================================
    // Lotes
    // =================================================================

    public function lotes(int $limite = 30, bool $soComPendencia = false): array
    {
        return $this->todas('SELECT lt.*, a.nome AS responsavel_nome,
                                    (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id AND e.situacao <> \'baixado\') AS exemplares,
                                    (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id AND e.situacao <> \'baixado\' AND e.carimbo_status = \'aguardando\') AS aguardando_carimbo,
                                    (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id AND e.situacao NOT IN (\'baixado\', \'extraviado\') AND e.localizacao_id IS NULL) AS aguardando_organizacao
                             FROM cadastro_lotes lt LEFT JOIN administradores a ON a.id = lt.responsavel_id'
            . ($soComPendencia ? " WHERE EXISTS (SELECT 1 FROM exemplares e WHERE e.lote_id = lt.id AND e.situacao <> 'baixado' AND (e.carimbo_status = 'aguardando' OR e.localizacao_id IS NULL))" : '')
            . ' ORDER BY lt.numero DESC LIMIT ' . max(1, min(500, $limite)));
    }

    public function lote(int $id): ?array
    {
        return $this->linha('SELECT lt.*, a.nome AS responsavel_nome FROM cadastro_lotes lt LEFT JOIN administradores a ON a.id = lt.responsavel_id WHERE lt.id = ?', [$id]);
    }

    public function loteAberto(int $administradorId): ?array
    {
        return $this->linha("SELECT lt.*, (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id) AS exemplares FROM cadastro_lotes lt
                             WHERE lt.responsavel_id = ? AND lt.status = 'aberto' ORDER BY lt.id DESC LIMIT 1", [$administradorId]);
    }

    /** "Lote 001 — 14/11/2026 — Equipe 1". */
    public static function rotuloLote(?array $lote): string
    {
        if (!$lote || ($lote['numero'] ?? $lote['lote_numero'] ?? null) === null) {
            return 'Sem lote';
        }
        $numero = (int)($lote['numero'] ?? $lote['lote_numero']);
        $data = (string)($lote['criado_em'] ?? $lote['lote_criado_em'] ?? '');
        $equipe = (string)($lote['equipe'] ?? $lote['lote_equipe'] ?? '');
        return 'Lote ' . str_pad((string)$numero, 3, '0', STR_PAD_LEFT) . ($data !== '' ? ' — ' . date('d/m/Y', strtotime($data)) : '') . ($equipe !== '' ? ' — ' . $equipe : '');
    }

    // =================================================================
    // Indicadores (seção 11: conferência física × organização definitiva)
    // =================================================================

    /** @return array<string, int> */
    public function indicadores(?int $loteId = null): array
    {
        $where = "e.situacao <> 'baixado'" . ($loteId ? ' AND e.lote_id = ' . (int)$loteId : '');
        $r = $this->linha("SELECT COUNT(*) AS total,
                                  COALESCE(SUM(e.conferido_em IS NOT NULL), 0) AS encontrados,
                                  COALESCE(SUM(e.carimbo_status = 'aguardando'), 0) AS aguardando_carimbo,
                                  COALESCE(SUM(e.carimbo_status = 'carimbado'), 0) AS carimbados,
                                  COALESCE(SUM(e.localizacao_id IS NULL AND e.situacao <> 'extraviado'), 0) AS aguardando_organizacao,
                                  COALESCE(SUM(e.localizacao_id IS NOT NULL), 0) AS com_localizacao,
                                  COALESCE(SUM(e.situacao IN ('aguardando', 'manutencao', 'danificado')), 0) AS indisponiveis,
                                  COALESCE(SUM(e.liberacao_pendente = 1), 0) AS aguardando_liberacao
                           FROM exemplares e WHERE $where") ?? [];
        $r['com_ocorrencias'] = (int)$this->valor("SELECT COUNT(DISTINCT o.exemplar_id) FROM exemplar_ocorrencias o JOIN exemplares e ON e.id = o.exemplar_id WHERE o.status = 'aberta' AND $where");
        return array_map('intval', $r);
    }

    // =================================================================
    // Ocorrências
    // =================================================================

    /** Filtros: status (aberta|resolvida|cancelada|todas), bloqueio ('1'|'0'), tipo, q (código/título). */
    public function ocorrencias(array $f, int $pagina = 1, int $porPagina = 30): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = (string)($f['status'] ?? 'aberta');
        if (in_array($status, ['aberta', 'resolvida', 'cancelada'], true)) {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if (in_array((string)($f['bloqueio'] ?? ''), ['0', '1'], true)) {
            $where[] = 'o.bloqueia_emprestimo = ?';
            $params[] = (int)$f['bloqueio'];
        }
        if ((string)($f['tipo'] ?? '') !== '') {
            $where[] = 'o.tipo = ?';
            $params[] = (string)$f['tipo'];
        }
        if ((int)($f['exemplar'] ?? 0) > 0) {
            $where[] = 'o.exemplar_id = ?';
            $params[] = (int)$f['exemplar'];
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $codigo = EmprestimoRepository::codigoExemplar($q);
            if ($codigo) {
                $where[] = 'e.codigo_individual = ?';
                $params[] = $codigo;
            } else {
                $where[] = 'l.titulo LIKE ?';
                $params[] = self::like($q);
            }
        }
        $w = implode(' AND ', $where);
        $base = "FROM exemplar_ocorrencias o JOIN exemplares e ON e.id = o.exemplar_id JOIN livros l ON l.id = e.livro_id WHERE $w";
        $total = (int)$this->valor("SELECT COUNT(*) $base", $params);
        $porPagina = max(1, min(200, $porPagina));
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT o.*, e.codigo_individual, e.situacao, l.titulo,
                                      (SELECT nome FROM administradores WHERE id = o.registrada_por) AS registrada_por_nome,
                                      (SELECT nome FROM administradores WHERE id = o.resolvida_por) AS resolvida_por_nome
                               $base ORDER BY o.status = 'aberta' DESC, o.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function ocorrenciasDoExemplar(int $exemplarId): array
    {
        return $this->todas('SELECT o.*, r.nome AS registrada_por_nome, s.nome AS resolvida_por_nome FROM exemplar_ocorrencias o
                             LEFT JOIN administradores r ON r.id = o.registrada_por LEFT JOIN administradores s ON s.id = o.resolvida_por
                             WHERE o.exemplar_id = ? ORDER BY o.id DESC', [$exemplarId]);
    }

    public function ocorrencia(int $id): ?array
    {
        return $this->linha('SELECT o.*, e.codigo_individual, e.situacao, l.titulo FROM exemplar_ocorrencias o JOIN exemplares e ON e.id = o.exemplar_id JOIN livros l ON l.id = e.livro_id WHERE o.id = ?', [$id]);
    }

    /** Contagem de ocorrências abertas por exemplar, para listas. @param list<int> $ids @return array<int, int> */
    public function ocorrenciasAbertas(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $r = [];
        foreach ($this->todas("SELECT exemplar_id, COUNT(*) AS n FROM exemplar_ocorrencias WHERE status = 'aberta' AND exemplar_id IN (" . implode(',', $ids) . ') GROUP BY exemplar_id') as $x) {
            $r[(int)$x['exemplar_id']] = (int)$x['n'];
        }
        return $r;
    }

    // =================================================================
    // Relatório de Atividades
    // =================================================================

    /**
     * Números do período [$de, $ate] (datas AAAA-MM-DD), por tipo de atividade. $administradores
     * limita a pessoas (filtro Administrador ou Equipe); null = todos.
     * @param list<int>|null $administradores
     * @return array{totais: array<string, int>, por_dia: array<string, array<string, int>>, por_pessoa: array<int, array<string, int|string>>}
     */
    public function atividades(string $de, string $ate, ?array $administradores = null): array
    {
        $ini = $de . ' 00:00:00';
        $fim = date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00';
        $totais = array_fill_keys(array_keys(self::ATIVIDADES), 0);
        $porDia = [];
        $porPessoa = [];
        foreach ($this->consultasAtividades() as $tipo => [$sql, $colData, $colAdmin]) {
            $where = " AND $colData >= ? AND $colData < ?";
            $params = [$ini, $fim];
            if ($administradores !== null) {
                if (!$administradores) {
                    continue;
                }
                $where .= " AND $colAdmin IN (" . implode(',', array_map('intval', $administradores)) . ')';
            }
            $linhas = $this->todas("SELECT DATE($colData) AS dia, $colAdmin AS adm, COUNT(*) AS n $sql$where GROUP BY DATE($colData), $colAdmin", $params);
            foreach ($linhas as $l) {
                $n = (int)$l['n'];
                $totais[$tipo] += $n;
                $porDia[$l['dia']][$tipo] = ($porDia[$l['dia']][$tipo] ?? 0) + $n;
                $adm = (int)$l['adm'];
                $porPessoa[$adm][$tipo] = ($porPessoa[$adm][$tipo] ?? 0) + $n;
            }
        }
        krsort($porDia);
        if ($porPessoa) {
            $nomes = [];
            foreach ($this->todas('SELECT id, nome, equipe FROM administradores WHERE id IN (' . implode(',', array_map('intval', array_keys($porPessoa))) . ')') as $a) {
                $nomes[(int)$a['id']] = $a;
            }
            foreach ($porPessoa as $id => &$p) {
                $p['nome'] = $nomes[$id]['nome'] ?? ($id ? 'Conta nº ' . $id : 'Sistema');
                $p['equipe'] = $nomes[$id]['equipe'] ?? '';
            }
            unset($p);
            uasort($porPessoa, fn ($a, $b) => strcmp((string)$a['nome'], (string)$b['nome']));
        }
        return ['totais' => $totais, 'por_dia' => $porDia, 'por_pessoa' => $porPessoa];
    }

    /**
     * Lista detalhada de um tipo de atividade (para conferência), no máximo $limite linhas.
     * @param list<int>|null $administradores
     */
    public function atividadesDetalhe(string $tipo, string $de, string $ate, ?array $administradores = null, int $limite = 1000): array
    {
        $consultas = $this->consultasAtividades();
        if (!isset($consultas[$tipo]) || ($administradores !== null && !$administradores)) {
            return [];
        }
        [$sql, $colData, $colAdmin, $colDesc] = $consultas[$tipo];
        $where = " AND $colData >= ? AND $colData < ?" . ($administradores !== null ? " AND $colAdmin IN (" . implode(',', array_map('intval', $administradores)) . ')' : '');
        return $this->todas("SELECT $colData AS quando, $colAdmin AS adm, (SELECT nome FROM administradores WHERE id = $colAdmin) AS pessoa, $colDesc AS descricao
                             $sql$where ORDER BY $colData DESC LIMIT " . max(1, min(5000, $limite)),
            [$de . ' 00:00:00', date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00']);
    }

    /**
     * Fonte de cada número: [FROM ... WHERE ..., coluna da data, coluna da pessoa, descrição].
     * "Organizados" = exemplar que saiu da área de triagem para uma prateleira (ou já foi registrado
     * nela); "Movimentados" = mudança entre duas localizações.
     */
    private function consultasAtividades(): array
    {
        $de = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.detalhes, '$.de')), 'null')";
        $para = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.detalhes, '$.para')), 'null')";
        $local = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.detalhes, '$.localizacao_id')), 'null')";
        $exemplar = "(SELECT CONCAT(e.codigo_individual, ' · ', COALESCE(l.titulo, 'Sem título')) FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE e.id = m.exemplar_id)";
        return [
            'obras_verificadas' => ["FROM livro_verificacoes v WHERE v.acao = 'verificado'", 'v.criado_em', 'v.administrador_id',
                "(SELECT COALESCE(titulo, 'Sem título') FROM livros WHERE id = v.livro_id)"],
            'exemplares_registrados' => ['FROM exemplares x WHERE 1 = 1', 'x.criado_em', 'x.cadastrado_por',
                "CONCAT(x.codigo_individual, ' · ', (SELECT COALESCE(titulo, 'Sem título') FROM livros WHERE id = x.livro_id))"],
            'codigos_gerados' => ['FROM exemplar_codigo_reservas r WHERE r.usado_em IS NOT NULL', 'r.usado_em', 'r.reservado_por', "CONCAT(r.codigo, ' (gerado pelo sistema e atribuído)')"],
            'carimbados' => ["FROM movimentacoes m WHERE m.tipo = 'carimbo' AND $para = 'carimbado'", 'm.criado_em', 'm.responsavel_id', $exemplar],
            'organizados' => ["FROM movimentacoes m WHERE ((m.tipo = 'localizacao' AND $de = 'null' AND $para <> 'null') OR (m.tipo = 'cadastro' AND $local <> 'null'))",
                'm.criado_em', 'm.responsavel_id', $exemplar],
            'movimentados' => ["FROM movimentacoes m WHERE m.tipo = 'localizacao' AND $de <> 'null'", 'm.criado_em', 'm.responsavel_id', $exemplar],
            'ocorrencias' => ['FROM exemplar_ocorrencias oc WHERE 1 = 1', 'oc.criado_em', 'oc.registrada_por',
                "CONCAT((SELECT codigo_individual FROM exemplares WHERE id = oc.exemplar_id), ' · ', oc.tipo, IF(oc.bloqueia_emprestimo, ' (indisponível)', ''))"],
            'obras_manuais' => ["FROM acoes_administrativas aa WHERE aa.acao = 'livro.cadastrar'", 'aa.criado_em', 'aa.usuario_id',
                "COALESCE((SELECT titulo FROM livros WHERE id = aa.entidade_id), CONCAT('Obra nº ', aa.entidade_id))"],
            'correcoes' => ["FROM livro_verificacoes v WHERE v.alteracoes IS NOT NULL", 'v.criado_em', 'v.administrador_id',
                "CONCAT((SELECT COALESCE(titulo, 'Sem título') FROM livros WHERE id = v.livro_id), IF(v.origem = 'desfazer', ' (correção desfeita)', ''))"],
        ];
    }
}
