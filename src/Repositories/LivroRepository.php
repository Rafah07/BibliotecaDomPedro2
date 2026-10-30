<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\Isbn;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Gerenciamento dos registros bibliográficos (tabela livros) pelo painel.
 *
 * Regras:
 *   * Um livro é um registro bibliográfico; os exemplares físicos ficam em "exemplares"
 *     e nunca são criados aqui.
 *   * Livros não são excluídos: são arquivados (arquivado_em) e podem ser restaurados.
 *     O arquivamento é recusado enquanto houver exemplares em uso ou empréstimos abertos.
 *   * Os dados originais das planilhas (registros_legados, importacao_itens) não são
 *     tocados: editar um livro importado muda só o cadastro atual.
 *   * A pesquisa usa a collation do banco (utf8mb4_unicode_ci), que já ignora acentos
 *     e maiúsculas: "acao" encontra "Ação" no MySQL e no MariaDB do XAMPP, sem intl.
 */
final class LivroRepository
{
    public const ORDENS = ['titulo', 'autor', 'cadastro'];
    public const STATUS_CDD = ['pendente', 'sugerida', 'em_revisao', 'aprovada', 'conflito'];

    public function __construct(private PDO $pdo)
    {
    }

    // ---------------------------------------------------------------
    // Listagem
    // ---------------------------------------------------------------

    /**
     * Página da listagem de livros.
     * Filtros: q (título, subtítulo, autor, editora ou ISBN), categoria (id), cdd (status ou
     * "aguardando" = ainda não aprovada), situacao (status_validacao), origem, arquivados (bool),
     * ordem (titulo|autor|cadastro), dir (asc|desc).
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 25): array
    {
        [$where, $params] = $this->filtros($f);
        $porPagina = max(1, min(100, $porPagina));

        $total = $this->pdo->prepare("SELECT COUNT(*) FROM livros l LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id WHERE $where");
        $total->execute($params);
        $total = (int)$total->fetchColumn();
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));

        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $ordem = match ($f['ordem'] ?? 'titulo') {
            // Livros sem autor ficam no fim em qualquer direção.
            'autor' => "(primeiro_autor IS NULL), primeiro_autor $dir, l.titulo, l.id",
            'cadastro' => "l.criado_em $dir, l.id $dir",
            default => "l.titulo $dir, l.id $dir",
        };
        $autorSql = ($f['ordem'] ?? '') === 'autor'
            ? ', (SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id ORDER BY la.ordem, a.id LIMIT 1) AS primeiro_autor'
            : '';

        // 1º passo: só os ids da página (ordenação barata); 2º passo: os detalhes desses ids.
        // LIMIT/OFFSET são inteiros calculados aqui (nunca vêm do usuário sem conversão).
        $sql = "SELECT l.id $autorSql FROM livros l LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id
                WHERE $where ORDER BY $ordem LIMIT " . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return ['itens' => $this->resumos($ids), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtros(array $f): array
    {
        $where = [!empty($f['arquivados']) ? 'l.arquivado_em IS NOT NULL' : 'l.arquivado_em IS NULL'];
        $params = [];

        // Cada palavra precisa aparecer em algum campo (título, subtítulo, editora, autor ou ISBN).
        $termos = array_slice(preg_split('/\s+/u', trim((string)($f['q'] ?? ''))) ?: [], 0, 6);
        foreach ($termos as $termo) {
            if ($termo === '' || mb_strlen($termo) > 100) {
                continue;
            }
            $like = '%' . addcslashes($termo, '%_\\') . '%';
            $isbn = Isbn::normalizar($termo);
            $cond = 'l.titulo LIKE ? OR l.subtitulo LIKE ? OR l.editora LIKE ?
                     OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id AND a.nome LIKE ?)';
            array_push($params, $like, $like, $like, $like);
            if (strlen($isbn) >= 4 && preg_match('/^[\d\-.\sXx]+$/', $termo)) {
                $cond .= ' OR l.isbn LIKE ?';
                $params[] = '%' . $isbn . '%';
            }
            $where[] = "($cond)";
        }

        $categoria = (int)($f['categoria'] ?? 0);
        if ($categoria > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM livro_categorias lc WHERE lc.livro_id = l.id AND lc.categoria_id = ?)';
            $params[] = $categoria;
        } elseif (($f['categoria'] ?? '') === 'sem') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL WHERE lc.livro_id = l.id)';
        }

        $cdd = (string)($f['cdd'] ?? '');
        if ($cdd === 'aguardando') {
            $where[] = "(cc.status IS NULL OR cc.status <> 'aprovada')";
        } elseif ($cdd === 'pendente') {
            $where[] = "(cc.status IS NULL OR cc.status = 'pendente')";
        } elseif (in_array($cdd, self::STATUS_CDD, true)) {
            $where[] = 'cc.status = ?';
            $params[] = $cdd;
        }

        $situacao = (string)($f['situacao'] ?? '');
        if (in_array($situacao, ['validado', 'aguardando_conferencia', 'dados_incompletos', 'com_divergencia'], true)) {
            $where[] = 'l.status_validacao = ?';
            $params[] = $situacao;
        }
        $origem = (string)($f['origem'] ?? '');
        if (in_array($origem, ['manual', 'planilha', 'inventario'], true)) {
            $where[] = 'l.origem_cadastro = ?';
            $params[] = $origem;
        }
        return [implode(' AND ', $where), $params];
    }

    /** Dados exibidos na tabela para os ids informados, na mesma ordem. */
    private function resumos(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao, l.assunto, l.status_validacao,
                       l.origem_cadastro, l.criado_em, l.arquivado_em,
                       (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem, a.id SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                       (SELECT GROUP_CONCAT(c.nome ORDER BY lc.principal DESC, c.nome SEPARATOR ', ') FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL WHERE lc.livro_id = l.id) AS categorias,
                       (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao NOT IN ('baixado', 'extraviado')) AS exemplares,
                       (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao = 'disponivel') AS disponiveis,
                       (SELECT r.cdd_original FROM registros_legados r WHERE r.livro_id = l.id ORDER BY r.id DESC LIMIT 1) AS cdd_antiga,
                       cc.status AS cdd_status, cc.cdd_aprovada, cc.cdd_proposta
                FROM livros l LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id
                WHERE l.id IN ($marcas)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        $porId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $l['id'] = (int)$l['id'];
            $l['exemplares'] = (int)$l['exemplares'];
            $l['disponiveis'] = (int)$l['disponiveis'];
            $l['cdd_status'] = $l['cdd_status'] ?? 'pendente';
            $porId[$l['id']] = $l;
        }
        return array_values(array_filter(array_map(fn ($id) => $porId[$id] ?? null, $ids)));
    }

    // ---------------------------------------------------------------
    // Um livro
    // ---------------------------------------------------------------

    /** Cadastro completo (inclusive arquivado), com autores e categorias, ou null. */
    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM livros WHERE id = ?');
        $stmt->execute([$id]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$l) {
            return null;
        }
        $l['id'] = (int)$l['id'];
        $stmt = $this->pdo->prepare('SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = ? ORDER BY la.ordem, a.id');
        $stmt->execute([$id]);
        $l['autores'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $this->pdo->prepare('SELECT lc.categoria_id FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id
                                     WHERE lc.livro_id = ? AND c.arquivado_em IS NULL ORDER BY lc.principal DESC, c.nome');
        $stmt->execute([$id]);
        $l['categorias'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $l;
    }

    /**
     * Tudo o que depende do livro. Usado para decidir se ele pode ser arquivado
     * e para mostrar à equipe o que existe antes de confirmar.
     */
    public function vinculos(int $id): array
    {
        $um = function (string $sql) use ($id): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_fill(0, substr_count($sql, '?'), $id));
            return (int)$stmt->fetchColumn();
        };
        return [
            'exemplares_ativos' => $um("SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND situacao NOT IN ('baixado', 'extraviado')"),
            'exemplares_total' => $um('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?'),
            'emprestimos_abertos' => $um('SELECT COUNT(*) FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id WHERE e.livro_id = ? AND m.data_devolucao IS NULL'),
            'emprestimos_total' => $um('SELECT COUNT(*) FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id WHERE e.livro_id = ?'),
            'registros_legados' => $um('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ?'),
            'linhas_importacao' => $um('SELECT COUNT(*) FROM importacao_itens WHERE livro_resultante_id = ? OR livro_vinculado_id = ?'),
            'classificacao_cdd' => $um('SELECT COUNT(*) FROM classificacoes_cdd WHERE livro_id = ?'),
            'historico_cdd' => $um('SELECT COUNT(*) FROM classificacoes_cdd_historico WHERE livro_id = ?'),
            'conferencias' => $um('SELECT COUNT(*) FROM inventario_conferencias WHERE livro_id = ?'),
        ];
    }

    /** Linhas das planilhas que deram origem ao livro (somente leitura). */
    public function origemImportacao(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT r.id, r.importacao_id, r.arquivo, r.linha, r.titulo_original, r.autor_original, r.cdd_original,
                                            r.codigo_autor_antigo, r.localizacao_antiga, r.assunto_original, r.categoria_original,
                                            r.quantidade_informada, r.representa_exemplar, r.dados_originais, r.importado_em
                                     FROM registros_legados r WHERE r.livro_id = ? ORDER BY r.id');
        $stmt->execute([$id]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($linhas as &$r) {
            $r['dados_originais'] = json_decode((string)$r['dados_originais'], true) ?: [];
        }
        return $linhas;
    }

    /**
     * Possíveis duplicidades de um cadastro. "isbn" impede salvar; "titulo" é só um aviso
     * (títulos iguais podem ser edições diferentes).
     * @return array{isbn: ?array, titulo: list<array>}
     */
    public function duplicidades(array $dados, ?int $ignorarId = null): array
    {
        $isbn = null;
        if (!empty($dados['isbn'])) {
            $stmt = $this->pdo->prepare('SELECT id, titulo FROM livros WHERE isbn_ativo IN (?, ?) AND id <> ? LIMIT 1');
            $stmt->execute([$dados['isbn'], Isbn::para13($dados['isbn']), $ignorarId ?? 0]);
            $isbn = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            // ISBN-13 cadastrado agora x ISBN-10 já existente.
            if (!$isbn && strlen($dados['isbn']) === 13 && str_starts_with($dados['isbn'], '978')) {
                $stmt = $this->pdo->prepare('SELECT id, isbn, titulo FROM livros WHERE arquivado_em IS NULL AND CHAR_LENGTH(isbn) = 10 AND id <> ?');
                $stmt->execute([$ignorarId ?? 0]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    if (Isbn::para13($l['isbn']) === $dados['isbn']) {
                        $isbn = $l;
                        break;
                    }
                }
            }
        }
        $stmt = $this->pdo->prepare("SELECT l.id, l.titulo, l.editora, l.ano_publicacao, l.edicao,
                                            (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                                     FROM livros l WHERE l.arquivado_em IS NULL AND l.titulo = ? AND l.id <> ? ORDER BY l.id LIMIT 5");
        $stmt->execute([trim((string)($dados['titulo'] ?? '')), $ignorarId ?? 0]);
        return ['isbn' => $isbn, 'titulo' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    // ---------------------------------------------------------------
    // Gravação
    // ---------------------------------------------------------------

    /**
     * Cadastra um livro manual. $dados já validados (ver acervo_validar_livro):
     * titulo, subtitulo, autores[], isbn (só dígitos ou null), editora, ano_publicacao, edicao,
     * idioma, assunto, categoria (id ou null), numero_paginas, sinopse, palavras_chave,
     * observacoes_internas, status_validacao.
     */
    public function criar(array $dados): int
    {
        return $this->transacao(function () use ($dados) {
            $this->pdo->prepare(
                "INSERT INTO livros (titulo, subtitulo, isbn, editora, ano_publicacao, edicao, idioma, numero_paginas, sinopse,
                                     palavras_chave, assunto, observacoes_internas, status_validacao, origem_cadastro)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual')"
            )->execute($this->valores($dados));
            $id = (int)$this->pdo->lastInsertId();
            $this->gravarAutores($id, $dados['autores'] ?? []);
            $this->gravarCategoria($id, $dados['categoria'] ?? null);
            return $id;
        });
    }

    /**
     * Atualiza um livro ativo. $versao é o atualizado_em lido pelo formulário: se outra
     * pessoa salvou nesse meio-tempo, nada é gravado.
     * @return array<string, array{0: mixed, 1: mixed}> campos alterados [antes, depois]
     */
    public function atualizar(int $id, array $dados, ?string $versao = null): array
    {
        return $this->transacao(function () use ($id, $dados, $versao) {
            $stmt = $this->pdo->prepare('SELECT * FROM livros WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual || $atual['arquivado_em'] !== null) {
                throw new RuntimeException('Livro não encontrado ou arquivado.');
            }
            if ($versao !== null && $versao !== '' && $versao !== $atual['atualizado_em']) {
                throw new RuntimeException('Este livro foi alterado por outra pessoa enquanto você editava. Recarregue a página para ver a versão atual.');
            }
            $antes = $this->buscar($id);

            $campos = ['titulo', 'subtitulo', 'isbn', 'editora', 'ano_publicacao', 'edicao', 'idioma', 'numero_paginas', 'sinopse',
                       'palavras_chave', 'assunto', 'observacoes_internas', 'status_validacao'];
            $valores = $this->valores($dados);
            $this->pdo->prepare('UPDATE livros SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $campos)) . ' WHERE id = ?')
                ->execute([...$valores, $id]);

            $alteracoes = [];
            foreach ($campos as $i => $campo) {
                if ((string)($atual[$campo] ?? '') !== (string)($valores[$i] ?? '')) {
                    $alteracoes[$campo] = [$atual[$campo], $valores[$i]];
                }
            }
            $autores = $this->limparAutores($dados['autores'] ?? []);
            if ($autores !== $antes['autores']) {
                $this->pdo->prepare('DELETE FROM livro_autores WHERE livro_id = ?')->execute([$id]);
                $this->gravarAutores($id, $autores);
                $alteracoes['autores'] = [implode('; ', $antes['autores']), implode('; ', $autores)];
            }
            $categoria = $dados['categoria'] ?? null;
            if ((int)($antes['categorias'][0] ?? 0) !== (int)$categoria) {
                $this->gravarCategoria($id, $categoria);
                $alteracoes['categoria'] = [$antes['categorias'][0] ?? null, $categoria];
            }
            if ($alteracoes) {
                // Garante nova versão mesmo quando só autores/categoria mudaram.
                $this->pdo->prepare('UPDATE livros SET atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
            }
            return $alteracoes;
        });
    }

    /**
     * Arquiva um livro (some do acervo, do catálogo público e das filas, mas fica no banco).
     * Recusa quando há exemplares em uso ou empréstimos abertos.
     */
    public function arquivar(int $id): array
    {
        return $this->transacao(function () use ($id) {
            $stmt = $this->pdo->prepare('SELECT id, arquivado_em FROM livros WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $livro = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$livro) {
                throw new RuntimeException('Livro não encontrado.');
            }
            if ($livro['arquivado_em'] !== null) {
                throw new RuntimeException('Este livro já está arquivado.');
            }
            $v = $this->vinculos($id);
            if ($v['emprestimos_abertos'] > 0) {
                throw new RuntimeException('Há empréstimo em aberto para um exemplar deste livro. Registre a devolução antes de arquivar.');
            }
            if ($v['exemplares_ativos'] > 0) {
                throw new RuntimeException('Este livro tem exemplares físicos em uso no acervo. Dê baixa nos exemplares antes de arquivar o cadastro.');
            }
            $this->pdo->prepare('UPDATE livros SET arquivado_em = NOW() WHERE id = ?')->execute([$id]);
            return $v;
        });
    }

    /** Desfaz o arquivamento. Recusa se outro livro ativo já usa o mesmo ISBN. */
    public function restaurar(int $id): void
    {
        $this->transacao(function () use ($id) {
            $stmt = $this->pdo->prepare('SELECT id, isbn, arquivado_em FROM livros WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $livro = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$livro || $livro['arquivado_em'] === null) {
                throw new RuntimeException('Este livro não está arquivado.');
            }
            try {
                $this->pdo->prepare('UPDATE livros SET arquivado_em = NULL WHERE id = ?')->execute([$id]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new RuntimeException('Outro livro ativo já usa o ISBN ' . $livro['isbn'] . '. Corrija o ISBN de um dos cadastros antes de restaurar.');
                }
                throw $e;
            }
        });
    }

    // ---------------------------------------------------------------
    // Apoio
    // ---------------------------------------------------------------

    private function valores(array $d): array
    {
        $texto = fn ($v) => ($v === null || trim((string)$v) === '') ? null : trim((string)$v);
        $inteiro = fn ($v) => ($v === null || $v === '') ? null : (int)$v;
        return [
            trim((string)$d['titulo']),
            $texto($d['subtitulo'] ?? null),
            $texto($d['isbn'] ?? null),
            $texto($d['editora'] ?? null),
            $inteiro($d['ano_publicacao'] ?? null),
            $texto($d['edicao'] ?? null),
            $texto($d['idioma'] ?? null) ?? 'Português',
            $inteiro($d['numero_paginas'] ?? null),
            $texto($d['sinopse'] ?? null),
            $texto($d['palavras_chave'] ?? null),
            $texto($d['assunto'] ?? null),
            $texto($d['observacoes_internas'] ?? null),
            $d['status_validacao'] ?? 'aguardando_conferencia',
        ];
    }

    /** Nomes sem repetição (ignorando maiúsculas e espaços extras), no máximo 10. */
    private function limparAutores(array $autores): array
    {
        $lista = [];
        foreach ($autores as $nome) {
            $nome = trim(preg_replace('/\s+/u', ' ', (string)$nome));
            if ($nome !== '' && !in_array(mb_strtolower($nome), array_map('mb_strtolower', $lista), true)) {
                $lista[] = mb_substr($nome, 0, 150);
            }
        }
        return array_slice($lista, 0, 10);
    }

    private function gravarAutores(int $livroId, array $autores): void
    {
        // Mesmo comando da importação: reaproveita o autor quando o nome já existe.
        $autor = $this->pdo->prepare('INSERT INTO autores (nome) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        $vinculo = $this->pdo->prepare('INSERT IGNORE INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, ?)');
        foreach ($this->limparAutores($autores) as $ordem => $nome) {
            $autor->execute([$nome]);
            $vinculo->execute([$livroId, (int)$this->pdo->lastInsertId(), $ordem + 1]);
        }
    }

    /** Troca a categoria principal (as demais categorias do livro são mantidas). */
    private function gravarCategoria(int $livroId, $categoriaId): void
    {
        $this->pdo->prepare('DELETE FROM livro_categorias WHERE livro_id = ? AND principal = TRUE')->execute([$livroId]);
        if ($categoriaId) {
            $this->pdo->prepare('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, TRUE)
                                 ON DUPLICATE KEY UPDATE principal = TRUE')->execute([$livroId, (int)$categoriaId]);
        }
    }

    private function transacao(callable $fn): mixed
    {
        $propria = !$this->pdo->inTransaction();
        if ($propria) {
            $this->pdo->beginTransaction();
        }
        try {
            $r = $fn();
            if ($propria) {
                $this->pdo->commit();
            }
            return $r;
        } catch (\Throwable $e) {
            if ($propria && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
