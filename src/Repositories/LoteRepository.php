<?php

namespace Biblioteca\Repositories;

use Biblioteca\Services\ExemplarService;
use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Consultas dos lotes (somente leitura, migração 160): Meus lotes, Histórico de Lotes, relatório
 * individual, produtividade e painel geral. Todos os números vêm dos registros reais:
 *   * cadastrados  = exemplares do lote (baixados não contam), por quem cadastrou (exemplares.cadastrado_por);
 *   * carimbados   = carimbo confirmado, por quem confirmou (exemplares.carimbado_por);
 *   * organizados  = com estante definitiva; quem organizou é quem guardou o exemplar PELA PRIMEIRA VEZ
 *                    (exemplares.organizado_por — uma movimentação depois não apaga essa informação).
 * Cada exemplar conta uma vez em cada indicador (requisições repetidas não somam de novo) e cadastros
 * cancelados nunca chegam a existir (a transação é desfeita). As datas das etapas vêm do lote e do
 * histórico de eventos (lote_eventos). Não há ranking: as tabelas são ordenadas pelo nome.
 */
final class LoteRepository
{
    use ConsultasPdo;

    private const CONTAGENS = "
        (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id AND e.situacao <> 'baixado') AS exemplares,
        (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id AND e.situacao <> 'baixado' AND e.carimbo_status = 'carimbado') AS carimbados,
        (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id AND e.situacao <> 'baixado' AND e.localizacao_id IS NOT NULL) AS organizados,
        (SELECT COUNT(*) FROM conferencia_duvidas d WHERE d.lote_id = lt.id AND d.status = 'aberta') AS duvidas_abertas,
        (SELECT GROUP_CONCAT(a.nome ORDER BY a.nome SEPARATOR ', ') FROM cadastro_lote_usuarios u JOIN administradores a ON a.id = u.administrador_id
          WHERE u.lote_id = lt.id AND u.removido_em IS NULL) AS participantes";

    public function __construct(private PDO $pdo)
    {
    }

    // =================================================================
    // Lotes
    // =================================================================

    public function lote(int $id): ?array
    {
        return $this->linha('SELECT lt.*, ' . self::CONTAGENS . ',
                                    cr.nome AS criado_por_nome, fe.nome AS fechado_por_nome, ci.nome AS carimbagem_iniciada_por_nome,
                                    cc.nome AS carimbagem_concluida_por_nome, en.nome AS encerrado_por_nome
                             FROM cadastro_lotes lt
                             LEFT JOIN administradores cr ON cr.id = lt.responsavel_id LEFT JOIN administradores fe ON fe.id = lt.fechado_por
                             LEFT JOIN administradores ci ON ci.id = lt.carimbagem_iniciada_por LEFT JOIN administradores cc ON cc.id = lt.carimbagem_concluida_por
                             LEFT JOIN administradores en ON en.id = lt.encerrado_por
                             WHERE lt.id = ?', [$id]);
    }

    /** Lotes de que a pessoa participa e que ainda não foram encerrados (seção "Meus lotes"). */
    public function meusLotes(int $administradorId): array
    {
        return $this->todas('SELECT lt.*, ' . self::CONTAGENS . ' FROM cadastro_lotes lt
                             JOIN cadastro_lote_usuarios u ON u.lote_id = lt.id AND u.administrador_id = ? AND u.removido_em IS NULL
                             WHERE lt.status <> \'encerrado\' ORDER BY lt.status = \'aberto\' DESC, lt.numero', [$administradorId]);
    }

    public function loteAtivo(int $administradorId): ?array
    {
        $id = (int)$this->valor('SELECT lote_ativo_id FROM administradores WHERE id = ?', [$administradorId]);
        return $id ? $this->lote($id) : null;
    }

    /** Visão geral para administradores: lotes que ainda não foram encerrados. */
    public function lotesEmAndamento(int $limite = 50): array
    {
        return $this->todas('SELECT lt.*, ' . self::CONTAGENS . " FROM cadastro_lotes lt WHERE lt.status <> 'encerrado' ORDER BY lt.numero DESC LIMIT " . max(1, min(200, $limite)));
    }

    public function emUso(): bool
    {
        return (bool)$this->valor("SELECT 1 FROM cadastro_lotes WHERE modo = 'gerenciado' LIMIT 1");
    }

    /**
     * Histórico de Lotes. Filtros: numero, de, ate (abertura, AAAA-MM-DD), responsavel (participante ou
     * quem abriu), status.
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function historico(array $f, int $pagina = 1, int $porPagina = 30): array
    {
        [$where, $params] = $this->filtros($f);
        $total = (int)$this->valor("SELECT COUNT(*) FROM cadastro_lotes lt WHERE $where", $params);
        $porPagina = max(1, min(500, $porPagina));
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas('SELECT lt.*, ' . self::CONTAGENS . " FROM cadastro_lotes lt WHERE $where ORDER BY lt.numero DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtros(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ((int)($f['numero'] ?? 0) > 0) {
            $where[] = 'lt.numero = ?';
            $params[] = (int)$f['numero'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['de'] ?? ''))) {
            $where[] = 'lt.criado_em >= ?';
            $params[] = $f['de'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['ate'] ?? ''))) {
            $where[] = 'lt.criado_em < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $f['ate'];
        }
        if ((int)($f['responsavel'] ?? 0) > 0) {
            $where[] = '(lt.responsavel_id = ? OR EXISTS (SELECT 1 FROM cadastro_lote_usuarios u WHERE u.lote_id = lt.id AND u.administrador_id = ?))';
            array_push($params, (int)$f['responsavel'], (int)$f['responsavel']);
        }
        if (isset(ExemplarService::STATUS_LOTE[(string)($f['status'] ?? '')])) {
            $where[] = 'lt.status = ?';
            $params[] = (string)$f['status'];
        }
        return [implode(' AND ', $where), $params];
    }

    /** Participantes (atuais e removidos), com o que cada um fez no lote. */
    public function participantes(int $loteId): array
    {
        return $this->todas('SELECT u.*, a.nome, a.perfil, ap.nome AS atribuido_por_nome, rp.nome AS removido_por_nome
                             FROM cadastro_lote_usuarios u JOIN administradores a ON a.id = u.administrador_id
                             LEFT JOIN administradores ap ON ap.id = u.atribuido_por LEFT JOIN administradores rp ON rp.id = u.removido_por
                             WHERE u.lote_id = ? ORDER BY u.removido_em IS NOT NULL, a.nome', [$loteId]);
    }

    /** Contas ativas que podem ser atribuídas (cadastram exemplares). */
    public function usuariosAtribuiveis(): array
    {
        return $this->todas("SELECT id, nome, perfil FROM administradores WHERE ativo = 1 AND perfil <> 'consulta' ORDER BY nome");
    }

    /** Pessoas que já participaram de algum lote (filtro "Responsável"). */
    public function responsaveis(): array
    {
        return $this->todas('SELECT a.id, a.nome FROM administradores a WHERE a.id IN (SELECT administrador_id FROM cadastro_lote_usuarios UNION SELECT responsavel_id FROM cadastro_lotes WHERE responsavel_id IS NOT NULL) ORDER BY a.nome');
    }

    // =================================================================
    // Exemplares do lote
    // =================================================================

    /**
     * Exemplares na ordem de cadastro. $filtro: '' (todos) | carimbo (aguardando carimbo) |
     * organizacao (carimbados sem estante) | pendentes (sem carimbo ou sem estante).
     */
    public function exemplares(int $loteId, string $filtro = ''): array
    {
        $extra = match ($filtro) {
            'carimbo' => " AND e.carimbo_status <> 'carimbado'",
            'organizacao' => " AND e.carimbo_status = 'carimbado' AND e.localizacao_id IS NULL",
            'pendentes' => " AND (e.carimbo_status <> 'carimbado' OR e.localizacao_id IS NULL)",
            default => '',
        };
        return $this->todas("SELECT e.id, e.livro_id, e.codigo_individual, e.lote_ordem, e.criado_em, e.carimbo_status, e.carimbado_em, e.carimbo_observacao, e.situacao,
                                    e.localizacao_id, e.organizado_em, e.liberacao_pendente,
                                    l.titulo, l.subtitulo,
                                    (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                    lo.setor, lo.estante, lo.prateleira, lo.complemento,
                                    ca.nome AS cadastrado_por_nome, cb.nome AS carimbado_por_nome, og.nome AS organizado_por_nome,
                                    (SELECT COUNT(*) FROM conferencia_duvidas d WHERE d.exemplar_id = e.id AND d.status = 'aberta') AS duvidas_abertas,
                                    (SELECT COUNT(*) FROM exemplar_ocorrencias o WHERE o.exemplar_id = e.id AND o.status = 'aberta') AS ocorrencias_abertas
                             FROM exemplares e JOIN livros l ON l.id = e.livro_id
                             LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                             LEFT JOIN administradores ca ON ca.id = e.cadastrado_por
                             LEFT JOIN administradores cb ON cb.id = e.carimbado_por
                             LEFT JOIN administradores og ON og.id = e.organizado_por
                             WHERE e.lote_id = ? AND e.situacao <> 'baixado'$extra ORDER BY e.lote_ordem, e.id", [$loteId]);
    }

    /** Resumo do lote. @return array<string, int> */
    public function resumo(int $loteId): array
    {
        $r = $this->linha("SELECT COUNT(*) AS cadastrados,
                                  COALESCE(SUM(carimbo_status = 'carimbado'), 0) AS carimbados,
                                  COALESCE(SUM(localizacao_id IS NOT NULL), 0) AS organizados,
                                  COALESCE(SUM(carimbo_status <> 'carimbado' OR localizacao_id IS NULL), 0) AS pendentes,
                                  COALESCE(SUM(carimbo_status <> 'carimbado'), 0) AS aguardando_carimbo,
                                  COALESCE(SUM(carimbo_status = 'carimbado' AND localizacao_id IS NULL), 0) AS aguardando_organizacao,
                                  COALESCE(SUM(situacao = 'disponivel'), 0) AS disponiveis,
                                  COUNT(DISTINCT livro_id) AS obras
                           FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado'", [$loteId]) ?? [];
        $r['baixados'] = (int)$this->valor("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND situacao = 'baixado'", [$loteId]);
        $r['duvidas_abertas'] = (int)$this->valor("SELECT COUNT(*) FROM conferencia_duvidas WHERE lote_id = ? AND status = 'aberta'", [$loteId]);
        $r['duvidas_bloqueantes'] = (int)$this->valor("SELECT COUNT(*) FROM conferencia_duvidas d WHERE d.status = 'aberta' AND d.compromete_identificacao = 1
                                                         AND (d.lote_id = ? OR d.livro_id IN (SELECT livro_id FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado'))", [$loteId, $loteId]);
        $r['duvidas_total'] = (int)$this->valor('SELECT COUNT(*) FROM conferencia_duvidas WHERE lote_id = ?', [$loteId]);
        $r['ocorrencias_abertas'] = (int)$this->valor("SELECT COUNT(*) FROM exemplar_ocorrencias o JOIN exemplares e ON e.id = o.exemplar_id WHERE e.lote_id = ? AND o.status = 'aberta'", [$loteId]);
        $r['obras_verificadas'] = (int)$this->valor("SELECT COUNT(DISTINCT l.id) FROM livros l JOIN exemplares e ON e.livro_id = l.id WHERE e.lote_id = ? AND e.situacao <> 'baixado' AND l.dados_verificados_em IS NOT NULL", [$loteId]);
        $r['obras_corrigidas'] = (int)$this->valor("SELECT COUNT(DISTINCT v.livro_id) FROM livro_verificacoes v WHERE v.alteracoes IS NOT NULL AND v.livro_id IN (SELECT livro_id FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado')", [$loteId]);
        return array_map('intval', $r);
    }

    /** Dúvidas ligadas ao lote (exemplares dele). */
    public function duvidas(int $loteId, bool $soAbertas = false): array
    {
        return $this->todas('SELECT d.*, e.codigo_individual, l.titulo, r.nome AS registrada_por_nome, s.nome AS resolvida_por_nome FROM conferencia_duvidas d
                             LEFT JOIN exemplares e ON e.id = d.exemplar_id LEFT JOIN livros l ON l.id = d.livro_id
                             LEFT JOIN administradores r ON r.id = d.registrada_por LEFT JOIN administradores s ON s.id = d.resolvida_por
                             WHERE d.lote_id = ?' . ($soAbertas ? " AND d.status = 'aberta'" : '') . ' ORDER BY d.status = \'aberta\' DESC, d.id DESC', [$loteId]);
    }

    /**
     * Página "Dúvidas da conferência". Filtros: status (aberta|resolvida|todas), lote (id), tipo, livro (id), q (código ou título).
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listaDuvidas(array $f, int $pagina = 1, int $porPagina = 30): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = (string)($f['status'] ?? 'aberta');
        if (in_array($status, ['aberta', 'resolvida'], true)) {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }
        foreach (['lote' => 'd.lote_id', 'livro' => 'd.livro_id', 'exemplar' => 'd.exemplar_id'] as $k => $col) {
            if ((int)($f[$k] ?? 0) > 0) {
                $where[] = "$col = ?";
                $params[] = (int)$f[$k];
            }
        }
        if ((string)($f['tipo'] ?? '') !== '') {
            $where[] = 'd.tipo = ?';
            $params[] = (string)$f['tipo'];
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.codigo_individual = ? OR l.titulo LIKE ?)';
            array_push($params, strtoupper($q), self::like($q));
        }
        $w = implode(' AND ', $where);
        $base = "FROM conferencia_duvidas d LEFT JOIN exemplares e ON e.id = d.exemplar_id LEFT JOIN livros l ON l.id = d.livro_id LEFT JOIN cadastro_lotes lt ON lt.id = d.lote_id WHERE $w";
        $total = (int)$this->valor("SELECT COUNT(*) $base", $params);
        $porPagina = max(1, min(200, $porPagina));
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT d.*, e.codigo_individual, l.titulo, lt.numero AS lote_numero,
                                      (SELECT nome FROM administradores WHERE id = d.registrada_por) AS registrada_por_nome,
                                      (SELECT nome FROM administradores WHERE id = d.resolvida_por) AS resolvida_por_nome
                               $base ORDER BY d.status = 'aberta' DESC, d.compromete_identificacao DESC, d.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function contarDuvidasAbertas(): int
    {
        return (int)$this->valor("SELECT COUNT(*) FROM conferencia_duvidas WHERE status = 'aberta'");
    }

    /** Histórico de eventos do lote (mais antigo primeiro). */
    public function eventos(int $loteId, int $limite = 2000): array
    {
        return $this->todas('SELECT v.*, a.nome AS administrador_nome, e.codigo_individual FROM lote_eventos v LEFT JOIN administradores a ON a.id = v.administrador_id
                             LEFT JOIN exemplares e ON e.id = v.exemplar_id WHERE v.lote_id = ? ORDER BY v.criado_em, v.id LIMIT ' . max(1, min(10000, $limite)), [$loteId]);
    }

    // =================================================================
    // Produtividade
    // =================================================================

    /**
     * Trabalho de cada pessoa no lote: cadastrados, carimbados e organizados (contados separadamente:
     * quem cadastrou, quem carimbou e quem organizou podem ser pessoas diferentes).
     * @return list<array{id: int, nome: string, cadastrados: int, carimbados: int, organizados: int, obras_verificadas: int}>
     */
    public function produtividade(int $loteId): array
    {
        $linhas = $this->todas("SELECT adm, SUM(c) AS cadastrados, SUM(k) AS carimbados, SUM(o) AS organizados FROM (
                                    SELECT cadastrado_por AS adm, 1 AS c, 0 AS k, 0 AS o FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado'
                                    UNION ALL SELECT carimbado_por, 0, 1, 0 FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado' AND carimbo_status = 'carimbado'
                                    UNION ALL SELECT organizado_por, 0, 0, 1 FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado' AND organizado_por IS NOT NULL
                                ) x GROUP BY adm", [$loteId, $loteId, $loteId]);
        $verif = [];
        foreach ($this->todas("SELECT v.administrador_id AS adm, COUNT(DISTINCT v.livro_id) AS n FROM livro_verificacoes v
                               WHERE v.livro_id IN (SELECT livro_id FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado') GROUP BY v.administrador_id", [$loteId]) as $v) {
            $verif[(int)$v['adm']] = (int)$v['n'];
        }
        $porId = [];
        foreach ($linhas as $l) {
            $porId[(int)$l['adm']] = ['cadastrados' => (int)$l['cadastrados'], 'carimbados' => (int)$l['carimbados'], 'organizados' => (int)$l['organizados']];
        }
        foreach ($this->todas('SELECT administrador_id FROM cadastro_lote_usuarios WHERE lote_id = ?', [$loteId]) as $p) {
            $porId[(int)$p['administrador_id']] ??= ['cadastrados' => 0, 'carimbados' => 0, 'organizados' => 0];
        }
        return $this->comNomes($porId, $verif);
    }

    /**
     * Painel geral. Filtros: de, ate (AAAA-MM-DD), lote (id), colaborador (id).
     * @return array{status: array<string, int>, totais: array<string, int>, por_colaborador: list<array>, por_periodo: array<string, list<array>>}
     */
    public function painel(array $f): array
    {
        $status = array_fill_keys(array_keys(ExemplarService::STATUS_LOTE), 0);
        $whereLote = (int)($f['lote'] ?? 0) > 0 ? ' WHERE id = ' . (int)$f['lote'] : '';
        foreach ($this->todas("SELECT status, COUNT(*) AS n FROM cadastro_lotes$whereLote GROUP BY status") as $s) {
            $status[$s['status']] = (int)$s['n'];
        }
        $status['total'] = array_sum($status);

        $fontes = [
            'cadastrados' => ['e.criado_em', 'e.cadastrado_por', '1 = 1'],
            'carimbados' => ['e.carimbado_em', 'e.carimbado_por', "e.carimbo_status = 'carimbado'"],
            'organizados' => ['e.organizado_em', 'e.organizado_por', 'e.organizado_por IS NOT NULL'],
        ];
        $totais = [];
        $porPessoa = [];
        $periodos = ['dia' => '%Y-%m-%d', 'semana' => '%x-S%v', 'mes' => '%Y-%m'];
        $porPeriodo = array_fill_keys(array_keys($periodos), []);
        foreach ($fontes as $chave => [$data, $adm, $cond]) {
            [$w, $p] = $this->filtrosPainel($f, $data, $adm);
            $base = "FROM exemplares e WHERE e.lote_id IS NOT NULL AND e.situacao <> 'baixado' AND $cond AND $data IS NOT NULL$w";
            $totais[$chave] = (int)$this->valor("SELECT COUNT(*) $base", $p);
            foreach ($this->todas("SELECT $adm AS adm, COUNT(*) AS n $base GROUP BY $adm", $p) as $l) {
                $porPessoa[(int)$l['adm']][$chave] = (int)$l['n'];
            }
            foreach ($periodos as $per => $fmt) {
                foreach ($this->todas("SELECT DATE_FORMAT($data, '$fmt') AS periodo, COUNT(*) AS n $base GROUP BY periodo ORDER BY periodo DESC LIMIT 400", $p) as $l) {
                    $porPeriodo[$per][$l['periodo']][$chave] = (int)$l['n'];
                }
            }
        }
        foreach ($porPeriodo as &$linhas) {
            krsort($linhas);
            $linhas = array_map(fn ($k, $v) => ['periodo' => (string)$k] + $v + ['cadastrados' => 0, 'carimbados' => 0, 'organizados' => 0], array_keys($linhas), $linhas);
        }
        unset($linhas);
        foreach ($porPessoa as &$p) {
            $p += ['cadastrados' => 0, 'carimbados' => 0, 'organizados' => 0];
        }
        unset($p);
        return ['status' => $status, 'totais' => $totais, 'por_colaborador' => $this->comNomes($porPessoa, []), 'por_periodo' => $porPeriodo];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtrosPainel(array $f, string $data, string $adm): array
    {
        $w = '';
        $p = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['de'] ?? ''))) {
            $w .= " AND $data >= ?";
            $p[] = $f['de'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['ate'] ?? ''))) {
            $w .= " AND $data < DATE_ADD(?, INTERVAL 1 DAY)";
            $p[] = $f['ate'];
        }
        if ((int)($f['lote'] ?? 0) > 0) {
            $w .= ' AND e.lote_id = ?';
            $p[] = (int)$f['lote'];
        }
        if ((int)($f['colaborador'] ?? 0) > 0) {
            $w .= " AND $adm = ?";
            $p[] = (int)$f['colaborador'];
        }
        return [$w, $p];
    }

    /** @param array<int, array<string, int>> $porId */
    private function comNomes(array $porId, array $verif): array
    {
        if (!$porId) {
            return [];
        }
        $ids = array_filter(array_keys($porId));
        $nomes = $ids ? array_column($this->todas('SELECT id, nome FROM administradores WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'), 'nome', 'id') : [];
        $r = [];
        foreach ($porId as $id => $v) {
            $r[] = ['id' => (int)$id, 'nome' => $nomes[$id] ?? ($id ? 'Conta nº ' . $id : 'Sem registro'), 'obras_verificadas' => $verif[$id] ?? 0] + $v;
        }
        usort($r, fn ($a, $b) => strcoll($a['nome'], $b['nome']));
        return $r;
    }

    // =================================================================
    // Tempo das etapas
    // =================================================================

    /**
     * Duração das etapas (não são horas trabalhadas): abertura → fechamento, fechamento → carimbagem
     * concluída, carimbagem → encerramento, e o total. Etapa em andamento conta até agora.
     * @return array<string, array{segundos: ?int, em_andamento: bool}>
     */
    public static function tempos(array $lote, ?int $agora = null): array
    {
        $agora ??= time();
        $t = fn (?string $d) => $d ? strtotime($d) : null;
        $ab = $t($lote['criado_em'] ?? null);
        $fe = $t($lote['fechado_em'] ?? null);
        $ca = $t($lote['carimbagem_concluida_em'] ?? null);
        $en = $t($lote['encerrado_em'] ?? null);
        $dur = fn (?int $ini, ?int $fim) => $ini === null ? ['segundos' => null, 'em_andamento' => false]
            : ['segundos' => max(0, ($fim ?? $agora) - $ini), 'em_andamento' => $fim === null];
        return [
            'cadastro' => $dur($ab, $fe),
            'carimbagem' => $fe ? $dur($fe, $ca) : ['segundos' => null, 'em_andamento' => false],
            'organizacao' => $ca ? $dur($ca, $en) : ['segundos' => null, 'em_andamento' => false],
            'total' => $dur($ab, $en),
        ];
    }

    /** "2 d 3 h 15 min". */
    public static function duracaoTexto(?int $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }
        $d = intdiv($segundos, 86400);
        $h = intdiv($segundos % 86400, 3600);
        $m = intdiv($segundos % 3600, 60);
        $partes = array_filter([$d ? $d . ' d' : '', $h ? $h . ' h' : '', ($m || (!$d && !$h)) ? $m . ' min' : '']);
        return implode(' ', $partes);
    }

    /** "Lote 001". */
    public static function nome(array|int|string|null $lote): string
    {
        $n = is_array($lote) ? ($lote['numero'] ?? $lote['lote_numero'] ?? 0) : $lote;
        return 'Lote ' . ExemplarService::numeroLote($n);
    }
}
