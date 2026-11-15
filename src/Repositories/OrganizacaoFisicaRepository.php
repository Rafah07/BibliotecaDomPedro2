<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Consultas da Organização Física (somente leitura): estrutura (setores, estantes, prateleiras),
 * números reais de exemplares por localização, exemplares de uma prateleira (paginados),
 * movimentações recentes e andamento por responsável/equipe.
 *
 * "Localização definida" = o exemplar está numa prateleira da estrutura (localizacoes.estante_id).
 * Exemplares em localizações avulsas (cadastradas antes da Organização Física) são contados à parte.
 * Exemplares baixados não entram nas contagens.
 */
final class OrganizacaoFisicaRepository
{
    use ConsultasPdo;

    public function __construct(private PDO $pdo)
    {
    }

    /** Números do topo da página. */
    public function resumo(): array
    {
        $r = $this->linha("SELECT (SELECT COUNT(*) FROM estantes) AS estantes,
                                  (SELECT COUNT(*) FROM estantes WHERE ativa = 1) AS estantes_ativas,
                                  (SELECT COUNT(*) FROM localizacoes WHERE estante_id IS NOT NULL) AS prateleiras,
                                  (SELECT COUNT(*) FROM localizacoes WHERE estante_id IS NOT NULL AND ativa = 1) AS prateleiras_ativas,
                                  (SELECT COUNT(*) FROM setores) AS setores,
                                  (SELECT COUNT(*) FROM localizacoes WHERE estante_id IS NULL) AS avulsas") ?? [];
        $e = $this->linha("SELECT COUNT(*) AS exemplares,
                                  COALESCE(SUM(lo.estante_id IS NOT NULL), 0) AS com_localizacao,
                                  COALESCE(SUM(e.localizacao_id IS NOT NULL AND lo.estante_id IS NULL), 0) AS em_avulsa,
                                  COALESCE(SUM(e.localizacao_id IS NULL), 0) AS sem_localizacao
                           FROM exemplares e LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                           WHERE e.situacao <> 'baixado'") ?? [];
        $r = array_map('intval', $r + $e);
        $r['sem_alocacao'] = $r['exemplares'] - $r['com_localizacao'];
        $r['pct_alocados'] = $r['exemplares'] > 0 ? (int)floor($r['com_localizacao'] / $r['exemplares'] * 100) : 0;
        return $r;
    }

    /** @return list<array> setores com a quantidade de estantes */
    public function setores(bool $soAtivos = false): array
    {
        return $this->todas('SELECT s.*, (SELECT COUNT(*) FROM estantes e WHERE e.setor_id = s.id) AS estantes FROM setores s'
            . ($soAtivos ? ' WHERE s.ativo = 1' : '') . ' ORDER BY s.ordem, s.nome');
    }

    /**
     * Estantes com setor, responsável, prateleiras e exemplares (números reais).
     * Filtros: q (identificação, nome, categoria, equipe), setor (id ou 'sem'), situacao (ativas|inativas|todas), responsavel (id).
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function estantes(array $f = [], int $pagina = 1, int $porPagina = 50): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (self::termos((string)($f['q'] ?? '')) as $t) {
            $like = self::like($t);
            $where[] = '(e.identificacao LIKE ? OR e.nome LIKE ? OR e.categoria LIKE ? OR e.equipe LIKE ? OR e.descricao LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $setor = (string)($f['setor'] ?? '');
        if ($setor === 'sem') {
            $where[] = 'e.setor_id IS NULL';
        } elseif ((int)$setor > 0) {
            $where[] = 'e.setor_id = ?';
            $params[] = (int)$setor;
        }
        $situacao = (string)($f['situacao'] ?? '');
        if ($situacao === '' || $situacao === 'ativas') {
            $where[] = 'e.ativa = 1';
        } elseif ($situacao === 'inativas') {
            $where[] = 'e.ativa = 0';
        }
        if ((int)($f['responsavel'] ?? 0) > 0) {
            $where[] = 'e.responsavel_id = ?';
            $params[] = (int)$f['responsavel'];
        }
        $w = implode(' AND ', $where);
        $porPagina = max(1, min(200, $porPagina));
        $total = (int)$this->valor("SELECT COUNT(*) FROM estantes e WHERE $w", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT e.*, s.nome AS setor_nome, s.ordem AS setor_ordem, a.nome AS responsavel_nome,
                                      COUNT(lo.id) AS prateleiras, COALESCE(SUM(lo.ativa = 1), 0) AS prateleiras_ativas,
                                      COALESCE(SUM(x.n), 0) AS exemplares
                               FROM estantes e
                               LEFT JOIN setores s ON s.id = e.setor_id
                               LEFT JOIN administradores a ON a.id = e.responsavel_id
                               LEFT JOIN localizacoes lo ON lo.estante_id = e.id
                               LEFT JOIN (SELECT localizacao_id, COUNT(*) AS n FROM exemplares WHERE situacao <> 'baixado' AND localizacao_id IS NOT NULL GROUP BY localizacao_id) x ON x.localizacao_id = lo.id
                               WHERE $w
                               GROUP BY e.id
                               ORDER BY s.ordem IS NULL, s.ordem, s.nome, e.ordem, e.identificacao, e.id
                               LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /**
     * Estantes de um setor para o mapa visual ($setor: id, ou 'sem' para as estantes sem setor),
     * com prateleiras e exemplares (números reais) e a posição salva (null = automática).
     * @return list<array>
     */
    public function mapa(string $setor): array
    {
        $w = $setor === 'sem' ? 'e.setor_id IS NULL' : 'e.setor_id = ?';
        return $this->todas("SELECT e.id, e.identificacao, e.nome, e.categoria, e.ativa, e.ordem, e.mapa_x, e.mapa_y, e.mapa_largura, e.mapa_altura,
                                    COUNT(lo.id) AS prateleiras, COALESCE(SUM(x.n), 0) AS exemplares
                             FROM estantes e
                             LEFT JOIN localizacoes lo ON lo.estante_id = e.id
                             LEFT JOIN (SELECT localizacao_id, COUNT(*) AS n FROM exemplares WHERE situacao <> 'baixado' AND localizacao_id IS NOT NULL GROUP BY localizacao_id) x ON x.localizacao_id = lo.id
                             WHERE $w
                             GROUP BY e.id
                             ORDER BY e.ordem, e.identificacao, e.id", $setor === 'sem' ? [] : [(int)$setor]);
    }

    /** Setores que têm estantes, mais "sem setor" se houver estantes sem setor. @return list<array{chave: string, nome: string, estantes: int}> */
    public function setoresDoMapa(): array
    {
        $lista = [];
        foreach ($this->todas('SELECT s.id, s.nome, COUNT(e.id) AS n FROM setores s JOIN estantes e ON e.setor_id = s.id GROUP BY s.id ORDER BY s.ordem, s.nome') as $s) {
            $lista[] = ['chave' => (string)$s['id'], 'nome' => $s['nome'], 'estantes' => (int)$s['n']];
        }
        $sem = (int)$this->valor('SELECT COUNT(*) FROM estantes WHERE setor_id IS NULL');
        if ($sem) {
            $lista[] = ['chave' => 'sem', 'nome' => $lista ? 'Sem setor' : 'Biblioteca', 'estantes' => $sem];
        }
        return $lista;
    }

    public function estante(int $id): ?array
    {
        $e = $this->linha('SELECT e.*, s.nome AS setor_nome, s.ativo AS setor_ativo, a.nome AS responsavel_nome
                           FROM estantes e LEFT JOIN setores s ON s.id = e.setor_id LEFT JOIN administradores a ON a.id = e.responsavel_id WHERE e.id = ?', [$id]);
        if (!$e) {
            return null;
        }
        $e['prateleiras'] = $this->todas("SELECT lo.*, COALESCE(x.n, 0) AS exemplares
                                          FROM localizacoes lo
                                          LEFT JOIN (SELECT localizacao_id, COUNT(*) AS n FROM exemplares WHERE situacao <> 'baixado' AND localizacao_id IS NOT NULL GROUP BY localizacao_id) x ON x.localizacao_id = lo.id
                                          WHERE lo.estante_id = ? ORDER BY lo.ordem, lo.id", [$id]);
        $e['exemplares'] = array_sum(array_map(fn ($p) => (int)$p['exemplares'], $e['prateleiras']));
        return $e;
    }

    /** Prateleira (localização) com a estante e o setor. */
    public function prateleira(int $id): ?array
    {
        return $this->linha("SELECT lo.*, e.identificacao AS estante_identificacao, e.nome AS estante_nome, e.categoria AS estante_categoria, e.ativa AS estante_ativa,
                                    s.nome AS setor_nome,
                                    (SELECT COUNT(*) FROM exemplares x WHERE x.localizacao_id = lo.id AND x.situacao <> 'baixado') AS exemplares
                             FROM localizacoes lo LEFT JOIN estantes e ON e.id = lo.estante_id LEFT JOIN setores s ON s.id = e.setor_id
                             WHERE lo.id = ?", [$id]);
    }

    /**
     * Exemplares de uma localização, com pesquisa e paginação (total real, sem limite escondido).
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function exemplaresDaLocalizacao(int $localId, string $q = '', int $pagina = 1, int $porPagina = 25): array
    {
        $where = ["e.localizacao_id = ?", "e.situacao <> 'baixado'"];
        $params = [$localId];
        foreach (self::termos($q) as $t) {
            $like = self::like($t);
            $where[] = '(e.codigo_individual LIKE ? OR l.titulo LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?))';
            array_push($params, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $porPagina = max(1, min(100, $porPagina));
        $total = (int)$this->valor("SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE $w", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT e.id, e.codigo_individual, e.situacao, e.estado_conservacao, e.conservacao_avaliada_em, e.livro_id,
                                      COALESCE(l.titulo, 'Sem título (a conferir)') AS titulo, l.dados_verificados_em,
                                      (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                      (SELECT MAX(m.criado_em) FROM movimentacoes m WHERE m.exemplar_id = e.id AND m.tipo = 'localizacao') AS movido_em
                               FROM exemplares e JOIN livros l ON l.id = e.livro_id
                               WHERE $w ORDER BY l.titulo IS NULL, l.titulo, e.codigo_individual
                               LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /**
     * Opções do seletor dependente (Setor → Estante → Prateleira): prateleiras ATIVAS de estantes
     * ATIVAS (e setores ativos), na ordem de exibição. $incluir: a localização atual do exemplar,
     * mesmo desativada ou avulsa, para não sumir do formulário.
     */
    public function opcoesLocal(?int $incluir = null): array
    {
        return $this->todas("SELECT lo.id, lo.prateleira, lo.descricao, lo.ativa, lo.estante_id, lo.setor, lo.estante, lo.complemento, lo.codigo,
                                    e.identificacao AS estante_identificacao, e.nome AS estante_nome, e.categoria AS estante_categoria, e.ativa AS estante_ativa,
                                    e.setor_id, s.nome AS setor_nome
                             FROM localizacoes lo
                             LEFT JOIN estantes e ON e.id = lo.estante_id
                             LEFT JOIN setores s ON s.id = e.setor_id
                             WHERE (lo.estante_id IS NOT NULL AND lo.ativa = 1 AND e.ativa = 1 AND (s.id IS NULL OR s.ativo = 1)) OR lo.id = ?
                             ORDER BY lo.estante_id IS NULL, s.ordem IS NULL, s.ordem, s.nome, e.ordem, e.identificacao, lo.ordem, lo.id", [$incluir ?? 0]);
    }

    /** Movimentações de localização (Mover exemplar, conferência, transferência), mais recentes primeiro. */
    public function movimentacoes(string $q = '', int $pagina = 1, int $porPagina = 20): array
    {
        $where = ["m.tipo = 'localizacao'"];
        $params = [];
        foreach (self::termos($q) as $t) {
            $like = self::like($t);
            $where[] = '(e.codigo_individual LIKE ? OR l.titulo LIKE ? OR m.descricao LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $total = (int)$this->valor("SELECT COUNT(*) FROM movimentacoes m JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id WHERE $w", $params);
        $porPagina = max(1, min(100, $porPagina));
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT m.id, m.criado_em, m.descricao, m.inventario_id, e.id AS exemplar_id, e.codigo_individual,
                                      COALESCE(l.titulo, 'Sem título (a conferir)') AS titulo, a.nome AS responsavel_nome
                               FROM movimentacoes m JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id
                               LEFT JOIN administradores a ON a.id = m.responsavel_id
                               WHERE $w ORDER BY m.criado_em DESC, m.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /**
     * Andamento por responsável/equipe: estantes, prateleiras e exemplares alocados nas estantes de
     * cada um. "Sem responsável" agrupa as estantes ainda não distribuídas.
     */
    public function andamentoEquipes(): array
    {
        return $this->todas("SELECT COALESCE(a.nome, '') AS responsavel_nome, COALESCE(e.equipe, '') AS equipe, e.responsavel_id,
                                    COUNT(DISTINCT e.id) AS estantes, COUNT(DISTINCT lo.id) AS prateleiras, COALESCE(SUM(x.n), 0) AS exemplares
                             FROM estantes e
                             LEFT JOIN administradores a ON a.id = e.responsavel_id
                             LEFT JOIN localizacoes lo ON lo.estante_id = e.id
                             LEFT JOIN (SELECT localizacao_id, COUNT(*) AS n FROM exemplares WHERE situacao <> 'baixado' AND localizacao_id IS NOT NULL GROUP BY localizacao_id) x ON x.localizacao_id = lo.id
                             WHERE e.ativa = 1
                             GROUP BY e.responsavel_id, a.nome, e.equipe
                             ORDER BY e.responsavel_id IS NULL AND e.equipe IS NULL, a.nome, e.equipe");
    }

    /** Quem organizou (moveu ou definiu a localização de) exemplares nos últimos $dias dias. */
    public function organizadosPorPessoa(int $dias = 7): array
    {
        return $this->todas("SELECT COALESCE(a.nome, 'Sem registro') AS nome, COUNT(*) AS movimentacoes, COUNT(DISTINCT m.exemplar_id) AS exemplares
                             FROM movimentacoes m LEFT JOIN administradores a ON a.id = m.responsavel_id
                             WHERE m.tipo = 'localizacao' AND m.criado_em >= NOW() - INTERVAL " . max(1, min(365, $dias)) . " DAY
                             GROUP BY m.responsavel_id, a.nome ORDER BY exemplares DESC LIMIT 50");
    }

    /** Pessoas com acesso ativo ao painel (para "Responsável"). Não traz e-mail nem dados sensíveis. */
    public function pessoasAtivas(): array
    {
        return $this->todas('SELECT id, nome, perfil FROM administradores WHERE ativo = 1 ORDER BY nome');
    }

    /** Para as etiquetas: estantes (com as prateleiras) de um setor, de uma estante ou todas as ativas. */
    public function paraEtiquetas(array $estanteIds = [], ?int $setorId = null, bool $incluirInativas = false): array
    {
        $where = [$incluirInativas ? '1 = 1' : 'e.ativa = 1'];
        $params = [];
        $ids = array_values(array_filter(array_map('intval', $estanteIds), fn ($i) => $i > 0));
        if ($ids) {
            $where[] = 'e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        if ($setorId) {
            $where[] = 'e.setor_id = ?';
            $params[] = $setorId;
        }
        $estantes = $this->todas('SELECT e.*, s.nome AS setor_nome, s.identificacao AS setor_identificacao FROM estantes e LEFT JOIN setores s ON s.id = e.setor_id
                                  WHERE ' . implode(' AND ', $where) . ' ORDER BY s.ordem IS NULL, s.ordem, s.nome, e.ordem, e.identificacao LIMIT 500', $params);
        if (!$estantes) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($estantes), '?'));
        $prats = $this->todas("SELECT * FROM localizacoes WHERE estante_id IN ($marcas)" . ($incluirInativas ? '' : ' AND ativa = 1') . ' ORDER BY ordem, id', array_column($estantes, 'id'));
        $por = [];
        foreach ($prats as $p) {
            $por[(int)$p['estante_id']][] = $p;
        }
        foreach ($estantes as &$e) {
            $e['prateleiras'] = $por[(int)$e['id']] ?? [];
        }
        unset($e);
        return $estantes;
    }
}
