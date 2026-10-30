<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Isbn;
use PDO;

/**
 * Consultas dos exemplares físicos (somente leitura): listagem com filtros e paginação no
 * servidor, detalhes, histórico e dados das etiquetas. As gravações ficam em ExemplarService.
 *
 * A CDD usada nas etiquetas é só a APROVADA (classificacoes_cdd.cdd_aprovada). A CDD antiga
 * da planilha nunca é tratada como definitiva.
 */
final class ExemplarRepository
{
    use ConsultasPdo;

    public const ORDENS = ['codigo', 'titulo', 'local', 'cadastro', 'conferencia'];

    private const COLUNAS = "e.id, e.livro_id, e.codigo_individual, e.situacao, e.estado_conservacao, e.localizacao_id, e.criado_em, e.conferido_em,
                             e.observacoes, e.etiqueta_gerada_em, e.inventario_id, e.baixado_em, e.motivo_baixa,
                             l.titulo, l.subtitulo, l.isbn, l.arquivado_em AS livro_arquivado_em,
                             (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                             lo.setor, lo.estante, lo.prateleira, lo.complemento, lo.codigo AS local_codigo, lo.ativa AS local_ativa,
                             cc.status AS cdd_status, IF(cc.status = 'aprovada', cc.cdd_aprovada, NULL) AS cdd_aprovada,
                             (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL LIMIT 1) AS emprestimo_aberto";

    private const JUNCOES = 'FROM exemplares e
                             JOIN livros l ON l.id = e.livro_id
                             LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                             LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Filtros: q (código BDP, título, autor ou ISBN), situacao (ou "ativos" = não baixados), conservacao,
     * localizacao (id ou "sem"), setor, conferencia (nunca|conferido), etiqueta (sem|gerada),
     * cdd (aprovada|pendente), livro (id), inventario (id: cadastrados naquele inventário),
     * ids (lista), ordem, dir.
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 25): array
    {
        [$where, $params] = $this->filtros($f);
        $total = (int)$this->valor('SELECT COUNT(*) ' . self::JUNCOES . " WHERE $where", $params);
        $limite = '';
        $paginas = 1;
        if ($porPagina > 0) {
            $porPagina = min(500, $porPagina);
            $paginas = max(1, (int)ceil($total / $porPagina));
            $pagina = max(1, min($pagina, $paginas));
            $limite = ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        }
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $ordem = match ($f['ordem'] ?? 'codigo') {
            'titulo' => "l.titulo $dir, e.codigo_individual",
            'local' => "(lo.id IS NULL), lo.setor $dir, lo.estante $dir, lo.prateleira $dir, e.codigo_individual",
            'cadastro' => "e.criado_em $dir, e.id $dir",
            'conferencia' => "(e.conferido_em IS NULL), e.conferido_em $dir, e.codigo_individual",
            default => "e.codigo_individual $dir",
        };
        $itens = $this->todas('SELECT ' . self::COLUNAS . ' ' . self::JUNCOES . " WHERE $where ORDER BY $ordem$limite", $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtros(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        $termos = self::termos((string)($f['q'] ?? ''));
        $codigo = count($termos) === 1 ? EmprestimoRepository::codigoExemplar($termos[0]) : null;
        if ($codigo) {
            $where[] = 'e.codigo_individual = ?';
            $params[] = $codigo;
        } else {
            foreach ($termos as $termo) {
                $like = self::like($termo);
                $cond = 'e.codigo_individual LIKE ? OR l.titulo LIKE ? OR l.subtitulo LIKE ?
                         OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)';
                array_push($params, $like, $like, $like, $like);
                $isbn = Isbn::normalizar($termo);
                if (strlen($isbn) >= 4 && preg_match('/^[\d\-.\sXx]+$/', $termo)) {
                    $cond .= ' OR l.isbn LIKE ?';
                    $params[] = '%' . $isbn . '%';
                }
                $where[] = "($cond)";
            }
        }
        $situacao = (string)($f['situacao'] ?? '');
        if ($situacao === 'ativos') {
            $where[] = "e.situacao <> 'baixado'";
        } elseif (in_array($situacao, ['disponivel', 'emprestado', 'manutencao', 'extraviado', 'baixado', 'aguardando', 'danificado'], true)) {
            $where[] = 'e.situacao = ?';
            $params[] = $situacao;
        }
        if (in_array($f['conservacao'] ?? '', ['bom', 'regular', 'danificado', 'necessita_reparo'], true)) {
            $where[] = 'e.estado_conservacao = ?';
            $params[] = $f['conservacao'];
        }
        $local = (string)($f['localizacao'] ?? '');
        if ($local === 'sem') {
            $where[] = 'e.localizacao_id IS NULL';
        } elseif ((int)$local > 0) {
            $where[] = 'e.localizacao_id = ?';
            $params[] = (int)$local;
        }
        if (($f['setor'] ?? '') !== '') {
            $where[] = 'lo.setor = ?';
            $params[] = $f['setor'];
        }
        if (($f['conferencia'] ?? '') === 'nunca') {
            $where[] = 'e.conferido_em IS NULL';
        } elseif (($f['conferencia'] ?? '') === 'conferido') {
            $where[] = 'e.conferido_em IS NOT NULL';
        }
        if (($f['etiqueta'] ?? '') === 'sem') {
            $where[] = 'e.etiqueta_gerada_em IS NULL';
        } elseif (($f['etiqueta'] ?? '') === 'gerada') {
            $where[] = 'e.etiqueta_gerada_em IS NOT NULL';
        }
        if (($f['cdd'] ?? '') === 'aprovada') {
            $where[] = "cc.status = 'aprovada'";
        } elseif (($f['cdd'] ?? '') === 'pendente') {
            $where[] = "(cc.status IS NULL OR cc.status <> 'aprovada')";
        }
        if ((int)($f['livro'] ?? 0) > 0) {
            $where[] = 'e.livro_id = ?';
            $params[] = (int)$f['livro'];
        }
        if ((int)($f['inventario'] ?? 0) > 0) {
            $where[] = 'e.inventario_id = ?';
            $params[] = (int)$f['inventario'];
        }
        if (isset($f['ids']) && is_array($f['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $f['ids']), fn ($i) => $i > 0)));
            $where[] = $ids ? 'e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '1 = 0';
            array_push($params, ...$ids);
        }
        return [implode(' AND ', $where), $params];
    }

    /** Totais do topo das páginas e do dashboard (exemplares de livros arquivados ficam fora). */
    public function resumo(): array
    {
        $r = $this->linha("SELECT COUNT(*) AS total,
                                  COALESCE(SUM(e.situacao <> 'baixado'), 0) AS ativos,
                                  COALESCE(SUM(e.situacao = 'disponivel'), 0) AS disponiveis,
                                  COALESCE(SUM(e.situacao = 'emprestado'), 0) AS emprestados,
                                  COALESCE(SUM(e.situacao = 'manutencao'), 0) AS manutencao,
                                  COALESCE(SUM(e.situacao = 'danificado'), 0) AS danificados,
                                  COALESCE(SUM(e.situacao = 'aguardando'), 0) AS aguardando,
                                  COALESCE(SUM(e.situacao = 'extraviado'), 0) AS extraviados,
                                  COALESCE(SUM(e.situacao = 'baixado'), 0) AS baixados,
                                  COALESCE(SUM(e.situacao NOT IN ('baixado', 'extraviado') AND e.localizacao_id IS NULL), 0) AS sem_local,
                                  COALESCE(SUM(e.situacao NOT IN ('baixado', 'extraviado') AND e.conferido_em IS NULL), 0) AS nunca_conferidos,
                                  COALESCE(SUM(e.situacao NOT IN ('baixado', 'extraviado') AND e.conferido_em IS NOT NULL), 0) AS conferidos,
                                  COALESCE(SUM(e.situacao NOT IN ('baixado', 'extraviado') AND e.etiqueta_gerada_em IS NULL), 0) AS sem_etiqueta
                           FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL") ?? [];
        $r['livros_sem_exemplar'] = (int)$this->valor("SELECT COUNT(*) FROM livros l WHERE l.arquivado_em IS NULL
                                                        AND NOT EXISTS (SELECT 1 FROM exemplares e WHERE e.livro_id = l.id AND e.situacao <> 'baixado')");
        $r['livros_com_exemplar'] = (int)$this->valor("SELECT COUNT(DISTINCT e.livro_id) FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL WHERE e.situacao <> 'baixado'");
        return array_map('intval', $r);
    }

    public function buscar(int $id): ?array
    {
        return $this->linha('SELECT ' . self::COLUNAS . ', cp.nome AS cadastrado_por_nome, v.titulo AS inventario_titulo ' . self::JUNCOES . '
                             LEFT JOIN administradores cp ON cp.id = e.cadastrado_por
                             LEFT JOIN inventarios v ON v.id = e.inventario_id
                             WHERE e.id = ?', [$id]);
    }

    public function porCodigo(string $texto): ?array
    {
        $codigo = EmprestimoRepository::codigoExemplar($texto);
        if (!$codigo) {
            return null;
        }
        $id = $this->valor('SELECT id FROM exemplares WHERE codigo_individual = ?', [$codigo]);
        return $id ? $this->buscar((int)$id) : null;
    }

    /** Histórico completo do exemplar, do mais recente para o mais antigo. */
    public function historico(int $id, int $limite = 300): array
    {
        return $this->todas('SELECT mv.tipo, mv.descricao, mv.emprestimo_id, mv.inventario_id, mv.criado_em, ad.nome AS responsavel
                             FROM movimentacoes mv LEFT JOIN administradores ad ON ad.id = mv.responsavel_id
                             WHERE mv.exemplar_id = ? ORDER BY mv.criado_em DESC, mv.id DESC LIMIT ' . (int)$limite, [$id]);
    }

    /** Conferências deste exemplar em cada inventário. */
    public function conferencias(int $id): array
    {
        return $this->todas('SELECT i.*, v.titulo AS inventario_titulo, v.status AS inventario_status, cp.nome AS conferido_por_nome
                             FROM inventario_itens i JOIN inventarios v ON v.id = i.inventario_id
                             LEFT JOIN administradores cp ON cp.id = i.conferido_por
                             WHERE i.exemplar_id = ? ORDER BY v.id DESC', [$id]);
    }

    /** Localizações e quantidade das planilhas antigas do livro (referência histórica, não confirmada). */
    public function dadosPlanilha(int $livroId): array
    {
        $r = $this->linha('SELECT SUM(quantidade_informada) AS quantidade, COUNT(*) AS linhas,
                                  GROUP_CONCAT(DISTINCT NULLIF(TRIM(localizacao_antiga), "") ORDER BY localizacao_antiga SEPARATOR " | ") AS localizacoes,
                                  GROUP_CONCAT(DISTINCT NULLIF(TRIM(cdd_original), "") SEPARATOR " | ") AS cdd_antiga
                           FROM registros_legados WHERE livro_id = ?', [$livroId]) ?? [];
        return [
            'quantidade' => $r['quantidade'] !== null ? (int)$r['quantidade'] : null,
            'linhas' => (int)($r['linhas'] ?? 0),
            'localizacoes' => $r['localizacoes'] ?? null,
            'cdd_antiga' => $r['cdd_antiga'] ?? null,
        ];
    }

    /** Números de exemplares de um livro (para a página do livro). */
    public function contagemLivro(int $livroId): array
    {
        $r = $this->linha("SELECT COUNT(*) AS total, COALESCE(SUM(situacao <> 'baixado'), 0) AS ativos,
                                  COALESCE(SUM(situacao = 'disponivel'), 0) AS disponiveis
                           FROM exemplares WHERE livro_id = ?", [$livroId]) ?? [];
        return array_map('intval', $r);
    }

    /**
     * Exemplares para etiquetas, na ordem pedida e sem repetição. Baixados ficam fora.
     * @param list<int> $ids
     */
    public function paraEtiquetas(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $porId = [];
        foreach (array_chunk($ids, 500) as $parte) {
            $marcas = implode(',', array_fill(0, count($parte), '?'));
            foreach ($this->todas('SELECT ' . self::COLUNAS . ' ' . self::JUNCOES . " WHERE e.id IN ($marcas) AND e.situacao <> 'baixado'", $parte) as $ex) {
                $porId[(int)$ex['id']] = $ex;
            }
        }
        return array_values(array_filter(array_map(fn ($id) => $porId[$id] ?? null, $ids)));
    }
}
