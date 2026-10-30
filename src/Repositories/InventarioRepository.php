<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Isbn;
use PDO;

/**
 * Consultas do inventário (somente leitura): lista de inventários, números da conferência,
 * itens por categoria, pesquisa de livros na tela de conferência e relatórios.
 * Nenhuma consulta daqui traz dados de alunos.
 */
final class InventarioRepository
{
    use ConsultasPdo;

    /** Categorias dos itens (abas da página do inventário e do relatório). */
    public const FILTROS = [
        'pendentes' => 'Pendências (decisão necessária)',
        'nao_conferidos' => 'Ainda não conferidos',
        'conferidos' => 'Conferidos',
        'local' => 'Localização diferente',
        'estado' => 'Estado divergente',
        'situacao' => 'Situação divergente',
        'cadastrados' => 'Cadastrados no inventário',
        'fora_lista' => 'Encontrados fora da lista prevista',
        'em_circulacao' => 'Emprestados (fora da estante)',
        'decididos' => 'Decisões registradas',
        'todos' => 'Todos os itens',
    ];

    private const ITEM_SQL = "SELECT i.*, e.codigo_individual, e.situacao, e.estado_conservacao, e.localizacao_id, e.livro_id,
                                     l.titulo, l.isbn,
                                     (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                     le.setor AS esperada_setor, le.estante AS esperada_estante, le.prateleira AS esperada_prateleira, le.complemento AS esperada_complemento,
                                     lf.setor AS encontrada_setor, lf.estante AS encontrada_estante, lf.prateleira AS encontrada_prateleira, lf.complemento AS encontrada_complemento,
                                     la2.setor AS atual_setor, la2.estante AS atual_estante, la2.prateleira AS atual_prateleira, la2.complemento AS atual_complemento,
                                     cp.nome AS conferido_por_nome, dp.nome AS decidido_por_nome,
                                     (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL LIMIT 1) AS emprestimo_aberto
                              FROM inventario_itens i
                              JOIN exemplares e ON e.id = i.exemplar_id
                              JOIN livros l ON l.id = e.livro_id
                              LEFT JOIN localizacoes le ON le.id = i.localizacao_esperada_id
                              LEFT JOIN localizacoes lf ON lf.id = i.localizacao_encontrada_id
                              LEFT JOIN localizacoes la2 ON la2.id = e.localizacao_id
                              LEFT JOIN administradores cp ON cp.id = i.conferido_por
                              LEFT JOIN administradores dp ON dp.id = i.decidido_por";

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{itens: list<array>, total: int, pagina: int, paginas: int} */
    public function listar(string $status = '', int $pagina = 1, int $porPagina = 25): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== '') {
            $where = 'v.status = ?';
            $params[] = $status;
        }
        $total = (int)$this->valor("SELECT COUNT(*) FROM inventarios v WHERE $where", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT v.*, r.nome AS responsavel_nome,
                                      (SELECT COUNT(*) FROM inventario_itens i WHERE i.inventario_id = v.id AND i.previsto = 1) AS previstos,
                                      (SELECT COUNT(*) FROM inventario_itens i WHERE i.inventario_id = v.id AND i.conferido_em IS NOT NULL) AS conferidos,
                                      (SELECT COUNT(*) FROM inventario_itens i WHERE i.inventario_id = v.id AND i.cadastrado_no_inventario = 1) AS cadastrados
                               FROM inventarios v LEFT JOIN administradores r ON r.id = v.responsavel_id
                               WHERE $where
                               ORDER BY FIELD(v.status, 'em_andamento', 'pausado', 'aberto', 'concluido', 'cancelado'), v.iniciado_em DESC, v.id DESC
                               LIMIT " . (int)$porPagina . ' OFFSET ' . (($pagina - 1) * (int)$porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function buscar(int $id): ?array
    {
        return $this->linha('SELECT v.*, r.nome AS responsavel_nome, a.nome AS aberto_por_nome, c.nome AS concluido_por_nome, x.nome AS cancelado_por_nome
                             FROM inventarios v
                             LEFT JOIN administradores r ON r.id = v.responsavel_id
                             LEFT JOIN administradores a ON a.id = v.aberto_por
                             LEFT JOIN administradores c ON c.id = v.concluido_por
                             LEFT JOIN administradores x ON x.id = v.cancelado_por
                             WHERE v.id = ?', [$id]);
    }

    /** Inventário em andamento ou pausado (só pode haver um). */
    public function ativo(): ?array
    {
        $id = $this->valor("SELECT id FROM inventarios WHERE status IN ('em_andamento', 'pausado') ORDER BY em_andamento_desde DESC, id DESC LIMIT 1");
        return $id ? $this->buscar((int)$id) : null;
    }

    /** Quantos exemplares entrariam na lista prevista agora (para inventários em preparação). */
    public function previstosEstimados(?string $setor): int
    {
        $sql = "SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id WHERE e.situacao NOT IN ('baixado', 'extraviado')";
        $params = [];
        if ($setor !== null && $setor !== '') {
            $sql .= ' AND lo.setor = ?';
            $params[] = $setor;
        }
        return (int)$this->valor($sql, $params);
    }

    /** Números da conferência. */
    public function resumo(int $id): array
    {
        $r = $this->linha("SELECT COUNT(*) AS itens,
                                  COALESCE(SUM(i.previsto), 0) AS previstos,
                                  COALESCE(SUM(i.conferido_em IS NOT NULL), 0) AS conferidos,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NOT NULL), 0) AS previstos_conferidos,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND m.id IS NULL), 0) AS nao_conferidos,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND m.id IS NOT NULL), 0) AS em_circulacao,
                                  COALESCE(SUM(i.divergencia_local), 0) AS local_diferente,
                                  COALESCE(SUM(i.divergencia_estado), 0) AS estado_divergente,
                                  COALESCE(SUM(i.divergencia_situacao), 0) AS situacao_divergente,
                                  COALESCE(SUM(i.cadastrado_no_inventario), 0) AS cadastrados,
                                  COALESCE(SUM(i.previsto = 0 AND i.cadastrado_no_inventario = 0 AND i.conferido_em IS NOT NULL), 0) AS fora_lista,
                                  COALESCE(SUM(i.conferido_em IS NOT NULL AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao)), 0) AS divergencias,
                                  COALESCE(SUM(i.conferido_em IS NOT NULL AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao) AND i.decisao IS NULL), 0) AS divergencias_pendentes,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND i.decisao IS NULL AND m.id IS NULL), 0) AS ausentes_pendentes,
                                  COALESCE(SUM(i.decisao = 'registrar_extravio'), 0) AS extravios,
                                  COALESCE(SUM(i.correcoes), 0) AS correcoes
                           FROM inventario_itens i
                           LEFT JOIN emprestimos m ON m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL
                           WHERE i.inventario_id = ?", [$id]) ?? [];
        return array_map('intval', $r);
    }

    /** Condição SQL de cada categoria (FILTROS). */
    private function condicao(string $filtro): string
    {
        $divergente = '(i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao)';
        $aberto = 'EXISTS (SELECT 1 FROM emprestimos m WHERE m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL)';
        return match ($filtro) {
            'pendentes' => "i.decisao IS NULL AND ((i.conferido_em IS NOT NULL AND $divergente) OR (i.previsto = 1 AND i.conferido_em IS NULL AND NOT $aberto))",
            'nao_conferidos' => "i.previsto = 1 AND i.conferido_em IS NULL AND NOT $aberto",
            'em_circulacao' => "i.previsto = 1 AND i.conferido_em IS NULL AND $aberto",
            'conferidos' => 'i.conferido_em IS NOT NULL',
            'local' => 'i.divergencia_local = 1',
            'estado' => 'i.divergencia_estado = 1',
            'situacao' => 'i.divergencia_situacao = 1',
            'cadastrados' => 'i.cadastrado_no_inventario = 1',
            'fora_lista' => 'i.previsto = 0 AND i.cadastrado_no_inventario = 0 AND i.conferido_em IS NOT NULL',
            'decididos' => 'i.decisao IS NOT NULL',
            default => '1 = 1',
        };
    }

    /**
     * Itens de uma categoria, com pesquisa por código, título ou autor.
     * $porPagina = 0 traz todos (relatórios).
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function itens(int $inventarioId, string $filtro, string $q = '', int $pagina = 1, int $porPagina = 25): array
    {
        $where = ['i.inventario_id = ?', $this->condicao($filtro)];
        $params = [$inventarioId];
        foreach (self::termos($q) as $termo) {
            $like = self::like($termo);
            $cond = 'e.codigo_individual LIKE ? OR l.titulo LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)';
            array_push($params, $like, $like, $like);
            if ($codigo = EmprestimoRepository::codigoExemplar($termo)) {
                $cond .= ' OR e.codigo_individual = ?';
                $params[] = $codigo;
            }
            $where[] = "($cond)";
        }
        $w = implode(' AND ', $where);
        $total = (int)$this->valor("SELECT COUNT(*) FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id JOIN livros l ON l.id = e.livro_id WHERE $w", $params);
        $limite = '';
        $paginas = 1;
        if ($porPagina > 0) {
            $paginas = max(1, (int)ceil($total / $porPagina));
            $pagina = max(1, min($pagina, $paginas));
            $limite = ' LIMIT ' . (int)$porPagina . ' OFFSET ' . (($pagina - 1) * (int)$porPagina);
        }
        $ordem = $filtro === 'conferidos' || $filtro === 'cadastrados' ? 'i.conferido_em DESC, i.id DESC' : 'l.titulo, e.codigo_individual';
        $itens = $this->todas(self::ITEM_SQL . " WHERE $w ORDER BY $ordem$limite", $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function item(int $itemId): ?array
    {
        return $this->linha(self::ITEM_SQL . ' WHERE i.id = ?', [$itemId]);
    }

    public function itemPorExemplar(int $inventarioId, int $exemplarId): ?array
    {
        return $this->linha(self::ITEM_SQL . ' WHERE i.inventario_id = ? AND i.exemplar_id = ?', [$inventarioId, $exemplarId]);
    }

    /** Últimas conferências registradas (mais recentes primeiro). */
    public function recentes(int $inventarioId, int $limite = 10): array
    {
        return $this->todas(self::ITEM_SQL . ' WHERE i.inventario_id = ? AND i.conferido_em IS NOT NULL ORDER BY i.conferido_em DESC, i.id DESC LIMIT ' . (int)$limite, [$inventarioId]);
    }

    /**
     * Pesquisa de livros na conferência, por título, autor ou ISBN. Cada livro vem com os
     * exemplares já cadastrados (e se já foram conferidos neste inventário), a quantidade
     * da planilha antiga e a localização antiga, só como referência.
     * @return array{livros: list<array>, mais: bool}
     */
    public function pesquisarLivros(int $inventarioId, string $q, int $limite = 12): array
    {
        $termos = self::termos($q);
        if (!$termos) {
            return ['livros' => [], 'mais' => false];
        }
        $where = ['l.arquivado_em IS NULL'];
        $params = [];
        foreach ($termos as $termo) {
            $like = self::like($termo);
            $cond = 'l.titulo LIKE ? OR l.subtitulo LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)';
            array_push($params, $like, $like, $like);
            $isbn = Isbn::normalizar($termo);
            if (strlen($isbn) >= 4 && preg_match('/^[\d\-.\sXx]+$/', $termo)) {
                $cond .= ' OR l.isbn LIKE ?';
                $params[] = '%' . $isbn . '%';
            }
            $where[] = "($cond)";
        }
        $livros = $this->todas('SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao,
                                       (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ", ") FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                       (SELECT SUM(r.quantidade_informada) FROM registros_legados r WHERE r.livro_id = l.id) AS quantidade_planilha,
                                       (SELECT r.localizacao_antiga FROM registros_legados r WHERE r.livro_id = l.id AND r.localizacao_antiga IS NOT NULL ORDER BY r.id DESC LIMIT 1) AS localizacao_antiga
                                FROM livros l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.titulo, l.id LIMIT ' . ($limite + 1), $params);
        $mais = count($livros) > $limite;
        $livros = array_slice($livros, 0, $limite);
        if (!$livros) {
            return ['livros' => [], 'mais' => false];
        }
        $ids = array_map('intval', array_column($livros, 'id'));
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $exemplares = $this->todas("SELECT e.id, e.livro_id, e.codigo_individual, e.situacao, e.estado_conservacao,
                                           lo.setor, lo.estante, lo.prateleira, lo.complemento, i.conferido_em
                                    FROM exemplares e
                                    LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                                    LEFT JOIN inventario_itens i ON i.exemplar_id = e.id AND i.inventario_id = ?
                                    WHERE e.livro_id IN ($marcas) ORDER BY e.codigo_individual", array_merge([$inventarioId], $ids));
        $porLivro = [];
        foreach ($exemplares as $ex) {
            $porLivro[(int)$ex['livro_id']][] = $ex;
        }
        foreach ($livros as &$l) {
            $l['exemplares'] = $porLivro[(int)$l['id']] ?? [];
        }
        unset($l);
        return ['livros' => $livros, 'mais' => $mais];
    }

    /** Livro escolhido para cadastrar exemplar na conferência (não arquivado). */
    public function livroParaCadastro(int $livroId): ?array
    {
        return $this->linha('SELECT l.id, l.titulo, l.isbn,
                                    (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ", ") FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                             FROM livros l WHERE l.id = ? AND l.arquivado_em IS NULL', [$livroId]);
    }

    /** Divergências encontradas e ainda sem decisão, em todos os inventários abertos (dashboard). */
    public function divergenciasPendentes(): int
    {
        return (int)$this->valor("SELECT COUNT(*) FROM inventario_itens i JOIN inventarios v ON v.id = i.inventario_id
                                  WHERE v.status IN ('em_andamento', 'pausado') AND i.decisao IS NULL AND i.conferido_em IS NOT NULL
                                    AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao)");
    }
}
