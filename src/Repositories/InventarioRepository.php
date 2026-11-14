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
        'a_conferir' => 'Pendentes',
        'encontrados' => 'Encontrados',
        'nao_localizados' => 'Não localizados',
        'com_alertas' => 'Com alertas cadastrais',
        'sem_codigo' => 'Sem código BDP',
        'pendentes' => 'Decisões necessárias',
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

    /** Itens: com exemplar (código BDP) ou registro ainda sem exemplar (linha da planilha a conferir). */
    private const ITEM_SQL = "SELECT i.*, e.codigo_individual, e.situacao, e.estado_conservacao, e.localizacao_id,
                                     COALESCE(l.titulo, '" . self::SEM_TITULO . "') AS titulo, l.titulo AS titulo_cadastrado, l.isbn, l.isbn_original, l.dados_verificados_em,
                                     (SELECT COUNT(*) FROM livro_pendencias p WHERE p.livro_id = l.id AND p.resolvida_em IS NULL) AS alertas,
                                     r.arquivo AS origem_arquivo, r.linha AS origem_linha, r.localizacao_antiga, r.quantidade_informada, r.cdd_original,
                                     np.nome AS nao_localizado_por_nome,
                                     (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                     le.setor AS esperada_setor, le.estante AS esperada_estante, le.prateleira AS esperada_prateleira, le.complemento AS esperada_complemento,
                                     lf.setor AS encontrada_setor, lf.estante AS encontrada_estante, lf.prateleira AS encontrada_prateleira, lf.complemento AS encontrada_complemento,
                                     la2.setor AS atual_setor, la2.estante AS atual_estante, la2.prateleira AS atual_prateleira, la2.complemento AS atual_complemento,
                                     cp.nome AS conferido_por_nome, dp.nome AS decidido_por_nome,
                                     (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL LIMIT 1) AS emprestimo_aberto
                              FROM inventario_itens i
                              LEFT JOIN exemplares e ON e.id = i.exemplar_id
                              JOIN livros l ON l.id = i.livro_id
                              LEFT JOIN registros_legados r ON r.importacao_item_id = i.importacao_item_id
                              LEFT JOIN administradores np ON np.id = i.nao_localizado_por
                              LEFT JOIN localizacoes le ON le.id = i.localizacao_esperada_id
                              LEFT JOIN localizacoes lf ON lf.id = i.localizacao_encontrada_id
                              LEFT JOIN localizacoes la2 ON la2.id = e.localizacao_id
                              LEFT JOIN administradores cp ON cp.id = i.conferido_por
                              LEFT JOIN administradores dp ON dp.id = i.decidido_por";

    /** Texto exibido para livros sem título informado (o banco guarda NULL; nada é inventado). */
    public const SEM_TITULO = 'Sem título (a conferir)';

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
                                  COALESCE(SUM(i.conferido_em IS NULL AND i.nao_localizado_em IS NULL AND COALESCE(i.decisao, '') <> 'registrar_extravio'), 0) AS a_conferir,
                                  COALESCE(SUM(i.conferido_em IS NULL AND (i.nao_localizado_em IS NOT NULL OR i.decisao = 'registrar_extravio')), 0) AS nao_localizados,
                                  COALESCE(SUM(i.exemplar_id IS NULL), 0) AS sem_codigo,
                                  COALESCE(SUM(EXISTS (SELECT 1 FROM livro_pendencias p WHERE p.livro_id = i.livro_id AND p.resolvida_em IS NULL)), 0) AS com_alertas,
                                  COALESCE(SUM(i.previsto), 0) AS previstos,
                                  COALESCE(SUM(i.conferido_em IS NOT NULL), 0) AS conferidos,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NOT NULL), 0) AS previstos_conferidos,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND i.nao_localizado_em IS NULL AND m.id IS NULL), 0) AS nao_conferidos,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND m.id IS NOT NULL), 0) AS em_circulacao,
                                  COALESCE(SUM(i.divergencia_local), 0) AS local_diferente,
                                  COALESCE(SUM(i.divergencia_estado), 0) AS estado_divergente,
                                  COALESCE(SUM(i.divergencia_situacao), 0) AS situacao_divergente,
                                  COALESCE(SUM(i.cadastrado_no_inventario), 0) AS cadastrados,
                                  COALESCE(SUM(i.previsto = 0 AND i.cadastrado_no_inventario = 0 AND i.conferido_em IS NOT NULL), 0) AS fora_lista,
                                  COALESCE(SUM(i.conferido_em IS NOT NULL AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao)), 0) AS divergencias,
                                  COALESCE(SUM(i.conferido_em IS NOT NULL AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao) AND i.decisao IS NULL), 0) AS divergencias_pendentes,
                                  COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND i.decisao IS NULL AND i.nao_localizado_em IS NULL AND m.id IS NULL), 0) AS ausentes_pendentes,
                                  COALESCE(SUM(i.decisao = 'registrar_extravio'), 0) AS extravios,
                                  COALESCE(SUM(i.correcoes), 0) AS correcoes
                           FROM inventario_itens i
                           LEFT JOIN emprestimos m ON m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL
                           WHERE i.inventario_id = ?", [$id]) ?? [];
        $r = array_map('intval', $r);
        // Obras (livros) deste inventário e quantas já têm os dados verificados (status da obra, não do exemplar).
        $obras = $this->linha('SELECT COUNT(*) AS obras, COALESCE(SUM(l.dados_verificados_em IS NOT NULL), 0) AS obras_verificadas
                               FROM livros l WHERE l.id IN (SELECT DISTINCT i.livro_id FROM inventario_itens i WHERE i.inventario_id = ?)', [$id]) ?? [];
        $r['obras'] = (int)($obras['obras'] ?? 0);
        $r['obras_verificadas'] = (int)($obras['obras_verificadas'] ?? 0);
        $r['encontrados'] = $r['conferidos'] ?? 0;
        // Progresso: itens com resultado (encontrado ou não localizado) sobre o total.
        $r['percentual'] = !empty($r['itens']) ? (int)floor((($r['encontrados'] + $r['nao_localizados']) / $r['itens']) * 100) : 0;
        return $r;
    }

    /** Condição SQL de cada categoria (FILTROS). */
    private function condicao(string $filtro): string
    {
        $divergente = '(i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao)';
        $aberto = 'EXISTS (SELECT 1 FROM emprestimos m WHERE m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL)';
        return match ($filtro) {
            'a_conferir' => "i.conferido_em IS NULL AND i.nao_localizado_em IS NULL AND COALESCE(i.decisao, '') <> 'registrar_extravio'",
            'encontrados' => 'i.conferido_em IS NOT NULL',
            'nao_localizados' => "i.conferido_em IS NULL AND (i.nao_localizado_em IS NOT NULL OR i.decisao = 'registrar_extravio')",
            'com_alertas' => 'EXISTS (SELECT 1 FROM livro_pendencias p WHERE p.livro_id = i.livro_id AND p.resolvida_em IS NULL)',
            'sem_codigo' => 'i.exemplar_id IS NULL',
            'pendentes' => "i.decisao IS NULL AND ((i.conferido_em IS NOT NULL AND $divergente) OR (i.previsto = 1 AND i.conferido_em IS NULL AND i.nao_localizado_em IS NULL AND NOT $aberto))",
            'nao_conferidos' => "i.previsto = 1 AND i.conferido_em IS NULL AND i.nao_localizado_em IS NULL AND NOT $aberto",
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
    public function itens(int $inventarioId, string $filtro, string $q = '', int $pagina = 1, int $porPagina = 25, array $local = []): array
    {
        $where = ['i.inventario_id = ?', $this->condicao($filtro)];
        $params = [$inventarioId];
        // Conferência por estante/setor: localização cadastrada (ou esperada) ou localização antiga da planilha.
        if (!empty($local['localizacao_id'])) {
            $where[] = 'COALESCE(e.localizacao_id, i.localizacao_esperada_id) = ?';
            $params[] = (int)$local['localizacao_id'];
        } elseif (!empty($local['setor'])) {
            $where[] = 'EXISTS (SELECT 1 FROM localizacoes lx WHERE lx.id = COALESCE(e.localizacao_id, i.localizacao_esperada_id) AND lx.setor = ?)';
            $params[] = (string)$local['setor'];
        } elseif (isset($local['antiga']) && $local['antiga'] !== '') {
            $where[] = 'r.localizacao_antiga = ?';
            $params[] = (string)$local['antiga'];
        }
        foreach (self::termos($q) as $termo) {
            $like = self::like($termo);
            // Antes o ISBN entrava sempre: uma palavra sem números virava "ISBN LIKE '%%'" e a pesquisa
            // devolvia quase todos os itens. Agora o ISBN só é comparado quando o termo parece um ISBN.
            $cond = 'e.codigo_individual LIKE ? OR l.titulo LIKE ? OR l.subtitulo LIKE ? OR l.editora LIKE ? OR l.isbn_original LIKE ? OR r.localizacao_antiga LIKE ?
                     OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
            $isbn = Isbn::normalizar($termo);
            if (strlen($isbn) >= 4 && preg_match('/^[\d\-.\sXx]+$/', $termo)) {
                $cond .= ' OR l.isbn LIKE ?';
                $params[] = '%' . $isbn . '%';
            }
            if ($codigo = EmprestimoRepository::codigoExemplar($termo)) {
                $cond .= ' OR e.codigo_individual = ?';
                $params[] = $codigo;
            }
            $where[] = "($cond)";
        }
        $w = implode(' AND ', $where);
        $total = (int)$this->valor("SELECT COUNT(*) FROM inventario_itens i LEFT JOIN exemplares e ON e.id = i.exemplar_id JOIN livros l ON l.id = i.livro_id
                                    LEFT JOIN registros_legados r ON r.importacao_item_id = i.importacao_item_id WHERE $w", $params);
        $limite = '';
        $paginas = 1;
        if ($porPagina > 0) {
            $paginas = max(1, (int)ceil($total / $porPagina));
            $pagina = max(1, min($pagina, $paginas));
            $limite = ' LIMIT ' . (int)$porPagina . ' OFFSET ' . (($pagina - 1) * (int)$porPagina);
        }
        $ordem = in_array($filtro, ['conferidos', 'encontrados', 'cadastrados'], true) ? 'i.conferido_em DESC, i.id DESC' : 'l.titulo IS NULL, l.titulo, e.codigo_individual, i.id';
        $itens = $this->todas(self::ITEM_SQL . " WHERE $w ORDER BY $ordem$limite", $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function item(int $itemId): ?array
    {
        return $this->linha(self::ITEM_SQL . ' WHERE i.id = ?', [$itemId]);
    }

    /** Itens (exemplares e registros da planilha) de um livro neste inventário: usado ao corrigir os dados do livro. */
    public function itensDoLivro(int $inventarioId, int $livroId, int $limite = 50): array
    {
        return $this->todas(self::ITEM_SQL . ' WHERE i.inventario_id = ? AND i.livro_id = ? ORDER BY i.conferido_em IS NOT NULL, e.codigo_individual IS NULL, e.codigo_individual, i.id LIMIT ' . max(1, $limite), [$inventarioId, $livroId]);
    }

    /** Localizações antigas (texto da planilha) dos itens ainda pendentes: para conferir por estante. */
    public function locaisAntigosPendentes(int $inventarioId): array
    {
        return array_column($this->todas("SELECT r.localizacao_antiga, COUNT(*) AS n FROM inventario_itens i
                                          JOIN registros_legados r ON r.importacao_item_id = i.importacao_item_id
                                          WHERE i.inventario_id = ? AND i.conferido_em IS NULL AND i.nao_localizado_em IS NULL AND r.localizacao_antiga IS NOT NULL
                                          GROUP BY r.localizacao_antiga ORDER BY r.localizacao_antiga LIMIT 500", [$inventarioId]), 'n', 'localizacao_antiga');
    }

    /** Alertas cadastrais abertos de um livro (dados incompletos, inconsistências, duplicidades). */
    public function alertasLivro(int $livroId): array
    {
        return $this->todas('SELECT * FROM livro_pendencias WHERE livro_id = ? AND resolvida_em IS NULL ORDER BY FIELD(tipo, \'incompleto\', \'inconsistencia\', \'duplicidade\', \'outro\'), id', [$livroId]);
    }

    /** Registro do livro para a tela de conferência (dados bibliográficos editáveis + origem). */
    public function livroConferencia(int $livroId): ?array
    {
        $l = $this->linha('SELECT l.*, (SELECT lc.categoria_id FROM livro_categorias lc WHERE lc.livro_id = l.id ORDER BY lc.principal DESC, lc.categoria_id LIMIT 1) AS categoria_id,
                                  (SELECT c.nome FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id WHERE lc.livro_id = l.id ORDER BY lc.principal DESC, lc.categoria_id LIMIT 1) AS categoria_nome,
                                  vp.nome AS verificado_por_nome
                           FROM livros l LEFT JOIN administradores vp ON vp.id = l.dados_verificados_por WHERE l.id = ? AND l.arquivado_em IS NULL', [$livroId]);
        if (!$l) {
            return null;
        }
        $l['autores'] = array_column($this->todas('SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = ? ORDER BY la.ordem, a.id', [$livroId]), 'nome');
        $l['legados'] = $this->todas('SELECT arquivo, linha, localizacao_antiga, cdd_original, quantidade_informada, categoria_original, assunto_original, dados_originais
                                      FROM registros_legados WHERE livro_id = ? ORDER BY id LIMIT 20', [$livroId]);
        foreach ($l['legados'] as &$r) {
            $r['dados_originais'] = json_decode((string)$r['dados_originais'], true) ?: [];
        }
        unset($r);
        return $l;
    }

    /**
     * Exemplares físicos de uma obra, com a situação de cada um NESTE inventário
     * (Encontrado, Não localizado ou Pendente) e a linha da planilha a que foi ligado, se houver.
     */
    public function exemplaresDaObra(int $inventarioId, int $livroId): array
    {
        return $this->todas("SELECT e.id, e.codigo_individual, e.situacao, e.estado_conservacao, e.observacoes, e.criado_em,
                                    lo.setor, lo.estante, lo.prateleira, lo.complemento,
                                    i.id AS item_id, i.conferido_em, i.nao_localizado_em, i.decisao, i.previsto, i.cadastrado_no_inventario,
                                    r.linha AS origem_linha, r.arquivo AS origem_arquivo,
                                    (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL LIMIT 1) AS emprestimo_aberto
                             FROM exemplares e
                             LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                             LEFT JOIN inventario_itens i ON i.exemplar_id = e.id AND i.inventario_id = ?
                             LEFT JOIN registros_legados r ON r.importacao_item_id = i.importacao_item_id
                             WHERE e.livro_id = ? ORDER BY e.situacao = 'baixado', e.codigo_individual", [$inventarioId, $livroId]);
    }

    /**
     * Registros importados da obra que ainda NÃO são exemplares (linhas da planilha sem código BDP)
     * neste inventário. Servem para pesquisar e verificar; não contam como exemplares encontrados.
     */
    public function registrosSemExemplar(int $inventarioId, int $livroId): array
    {
        return $this->todas('SELECT i.id, i.conferido_em, i.nao_localizado_em, i.nao_localizado_obs, i.decisao, r.arquivo AS origem_arquivo, r.linha AS origem_linha,
                                    r.localizacao_antiga, r.quantidade_informada, np.nome AS nao_localizado_por_nome
                             FROM inventario_itens i
                             LEFT JOIN registros_legados r ON r.importacao_item_id = i.importacao_item_id
                             LEFT JOIN administradores np ON np.id = i.nao_localizado_por
                             WHERE i.inventario_id = ? AND i.livro_id = ? AND i.exemplar_id IS NULL
                             ORDER BY r.linha IS NULL, r.linha, i.id', [$inventarioId, $livroId]);
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
            // Título, subtítulo, autor, editora, assunto, código BDP de um exemplar e local antigo da planilha.
            $cond = 'l.titulo LIKE ? OR l.subtitulo LIKE ? OR l.editora LIKE ? OR l.assunto LIKE ?
                     OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)
                     OR EXISTS (SELECT 1 FROM exemplares ex WHERE ex.livro_id = l.id AND ex.codigo_individual LIKE ?)
                     OR EXISTS (SELECT 1 FROM registros_legados rl WHERE rl.livro_id = l.id AND rl.localizacao_antiga LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
            $isbn = Isbn::normalizar($termo);
            if (strlen($isbn) >= 4 && preg_match('/^[\d\-.\sXx]+$/', $termo)) {
                $cond .= ' OR l.isbn LIKE ? OR l.isbn_original LIKE ?';
                array_push($params, '%' . $isbn . '%', $like);
            }
            $where[] = "($cond)";
        }
        $livros = $this->todas('SELECT l.id, COALESCE(l.titulo, \'' . self::SEM_TITULO . '\') AS titulo, l.subtitulo, l.isbn, l.isbn_original, l.editora, l.ano_publicacao, l.edicao, l.dados_verificados_em,
                                       (SELECT COUNT(*) FROM livro_pendencias p WHERE p.livro_id = l.id AND p.resolvida_em IS NULL) AS alertas,
                                       (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ", ") FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                       (SELECT SUM(r.quantidade_informada) FROM registros_legados r WHERE r.livro_id = l.id) AS quantidade_planilha,
                                       (SELECT r.localizacao_antiga FROM registros_legados r WHERE r.livro_id = l.id AND r.localizacao_antiga IS NOT NULL ORDER BY r.id DESC LIMIT 1) AS localizacao_antiga
                                FROM livros l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.titulo IS NULL, l.titulo NOT LIKE ?, l.titulo, l.id LIMIT ' . ($limite + 1), array_merge($params, [addcslashes(trim($q), '%_\\') . '%']));
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
        // Registros ainda sem exemplar neste inventário (linhas da planilha a conferir).
        $registros = $this->todas("SELECT i.id, i.livro_id, i.conferido_em, i.nao_localizado_em, r.arquivo, r.linha, r.localizacao_antiga
                                   FROM inventario_itens i LEFT JOIN registros_legados r ON r.importacao_item_id = i.importacao_item_id
                                   WHERE i.inventario_id = ? AND i.exemplar_id IS NULL AND i.livro_id IN ($marcas) ORDER BY i.id", array_merge([$inventarioId], $ids));
        $regPorLivro = [];
        foreach ($registros as $reg) {
            $regPorLivro[(int)$reg['livro_id']][] = $reg;
        }
        foreach ($livros as &$l) {
            $l['exemplares'] = $porLivro[(int)$l['id']] ?? [];
            $l['registros'] = $regPorLivro[(int)$l['id']] ?? [];
        }
        unset($l);
        return ['livros' => $livros, 'mais' => $mais];
    }

    /**
     * Sugestões enquanto a equipe digita na conferência: códigos BDP de exemplares e livros
     * (título, autor, ISBN, editora). Só leitura: nada é escolhido sem o clique de quem confere.
     *
     * @return list<array<string, mixed>>
     */
    public function sugestoes(int $inventarioId, string $q, int $limite = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $saida = [];
        $comPrefixo = (bool)preg_match('/^BDP/i', $q);
        // Códigos BDP: "BDP-0001", "bdp 12" ou só os números ("000123", "123").
        if (preg_match('/^(?:BDP\s*-?\s*)?(\d{0,6})$/i', $q, $m) && ($comPrefixo || $m[1] !== '')) {
            $numeros = $m[1];
            $padrao = $comPrefixo ? 'BDP-' . $numeros . '%' : '%' . $numeros . '%';
            $codigos = $this->todas("SELECT e.codigo_individual, e.livro_id, COALESCE(l.titulo, '" . self::SEM_TITULO . "') AS titulo, i.conferido_em,
                                            (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                                     FROM exemplares e JOIN livros l ON l.id = e.livro_id
                                     LEFT JOIN inventario_itens i ON i.exemplar_id = e.id AND i.inventario_id = ?
                                     WHERE e.codigo_individual LIKE ? ORDER BY e.codigo_individual LIMIT " . max(1, $limite),
                [$inventarioId, $padrao]);
            foreach ($codigos as $c) {
                $saida[] = ['tipo' => 'exemplar', 'codigo' => $c['codigo_individual'], 'livro_id' => (int)$c['livro_id'], 'titulo' => $c['titulo'],
                    'detalhe' => ($c['autores'] ?: 'Autor não informado') . ($c['conferido_em'] !== null ? ' · já encontrado' : ''), 'conferido' => $c['conferido_em'] !== null];
            }
        }
        if (count($saida) < $limite && !$comPrefixo) {
            $busca = $this->pesquisarLivros($inventarioId, $q, $limite - count($saida));
            foreach ($busca['livros'] as $l) {
                $pend = count(array_filter($l['registros'], fn ($r) => $r['conferido_em'] === null && $r['nao_localizado_em'] === null));
                $partes = [$l['autores'] ?: 'Autor não informado'];
                if ($l['isbn'] || $l['isbn_original']) {
                    $partes[] = 'ISBN ' . ($l['isbn'] ?: $l['isbn_original']);
                }
                if ($l['editora']) {
                    $partes[] = $l['editora'];
                }
                $partes[] = count($l['exemplares']) . ' exemplar(es)' . ($pend ? ', ' . $pend . ' linha(s) da planilha pendente(s)' : '');
                $partes[] = $l['dados_verificados_em'] !== null ? 'Dados verificados' : 'Dados não verificados';
                $saida[] = ['tipo' => 'livro', 'livro_id' => (int)$l['id'], 'titulo' => $l['titulo'], 'detalhe' => implode(' · ', $partes)];
            }
        }
        return array_slice($saida, 0, $limite);
    }

    /** Livro escolhido para cadastrar exemplar na conferência (não arquivado). */
    public function livroParaCadastro(int $livroId): ?array
    {
        return $this->linha('SELECT l.id, COALESCE(l.titulo, \'' . self::SEM_TITULO . '\') AS titulo, l.isbn,
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
