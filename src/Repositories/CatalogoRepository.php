<?php

namespace Biblioteca\Repositories;

use PDO;

/**
 * Consultas do catálogo público no MySQL (somente leitura).
 *
 * Só devolve campos públicos: título, autores, categoria, ISBN, capa e disponibilidade.
 * Observações internas, CDD em revisão e dados das planilhas nunca saem daqui.
 * A disponibilidade vem dos exemplares físicos registrados, nunca da quantidade
 * informada nas planilhas antigas (registros_legados).
 */
final class CatalogoRepository
{
    /** Situações que representam um exemplar físico conferido e em uso no acervo. */
    private const SITUACOES_NO_ACERVO = "'disponivel', 'emprestado', 'manutencao'";

    public function __construct(private PDO $pdo)
    {
    }

    public function totalLivros(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL')->fetchColumn();
    }

    /**
     * Livros ativos, filtrados por texto (título, autor, categoria ou ISBN) e por categoria.
     * @return array{itens: list<array>, total: int}
     */
    public function buscar(string $busca, string $categoria, int $limite, int $deslocamento): array
    {
        [$where, $params] = $this->filtros($busca, $categoria);

        $total = $this->pdo->prepare("SELECT COUNT(*) FROM livros l WHERE $where");
        $total->execute($params);

        $sql = $this->selectLivros() . " WHERE $where ORDER BY l.titulo, l.id LIMIT :limite OFFSET :deslocamento";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':deslocamento', $deslocamento, PDO::PARAM_INT);
        $stmt->execute();

        return ['itens' => $stmt->fetchAll(), 'total' => (int)$total->fetchColumn()];
    }

    /** Últimos livros cadastrados (destaques da página inicial). */
    public function recentes(int $limite): array
    {
        $stmt = $this->pdo->prepare($this->selectLivros() . ' WHERE l.arquivado_em IS NULL ORDER BY l.criado_em DESC, l.id DESC LIMIT :limite');
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Categorias ativas com o número de títulos ativos em cada uma. */
    public function categorias(): array
    {
        return $this->pdo->query(
            'SELECT c.nome, c.descricao, COUNT(DISTINCT l.id) AS total
             FROM categorias c
             LEFT JOIN livro_categorias lc ON lc.categoria_id = c.id
             LEFT JOIN livros l ON l.id = lc.livro_id AND l.arquivado_em IS NULL
             WHERE c.arquivado_em IS NULL
             GROUP BY c.id, c.nome, c.descricao
             ORDER BY c.nome'
        )->fetchAll();
    }

    /** Autores com pelo menos um título ativo, dos que têm mais títulos para os que têm menos. */
    public function autores(string $busca, int $limite): array
    {
        $sql = 'SELECT a.nome, COUNT(DISTINCT l.id) AS total
                FROM autores a
                JOIN livro_autores la ON la.autor_id = a.id
                JOIN livros l ON l.id = la.livro_id AND l.arquivado_em IS NULL';
        $params = [];
        if ($busca !== '') {
            $sql .= ' WHERE a.nome LIKE :busca';
            $params[':busca'] = '%' . self::escaparLike($busca) . '%';
        }
        $sql .= ' GROUP BY a.id, a.nome ORDER BY total DESC, a.nome LIMIT :limite';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function selectLivros(): string
    {
        $situacoes = self::SITUACOES_NO_ACERVO;
        return "SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.capa,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem, a.nome SEPARATOR ', ')
                       FROM livro_autores la JOIN autores a ON a.id = la.autor_id
                      WHERE la.livro_id = l.id) AS autores,
                    (SELECT c.nome FROM livro_categorias lc
                       JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL
                      WHERE lc.livro_id = l.id ORDER BY lc.principal DESC, c.nome LIMIT 1) AS categoria,
                    (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao IN ($situacoes)) AS exemplares,
                    (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao = 'disponivel') AS disponiveis
                FROM livros l";
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function filtros(string $busca, string $categoria): array
    {
        $where = ['l.arquivado_em IS NULL'];
        $params = [];
        if ($busca !== '') {
            // Com prepares nativos, cada marcador precisa de um nome próprio.
            $termo = '%' . self::escaparLike($busca) . '%';
            $where[] = '(l.titulo LIKE :b1 OR l.subtitulo LIKE :b2 OR l.isbn LIKE :b3
                        OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id AND a.nome LIKE :b4)
                        OR EXISTS (SELECT 1 FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id WHERE lc.livro_id = l.id AND c.nome LIKE :b5))';
            $isbn = preg_replace('/[^0-9Xx]/', '', $busca);
            $params += [':b1' => $termo, ':b2' => $termo, ':b3' => $isbn !== '' ? '%' . $isbn . '%' : $termo, ':b4' => $termo, ':b5' => $termo];
        }
        if ($categoria !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id
                                WHERE lc.livro_id = l.id AND c.arquivado_em IS NULL AND c.nome = :categoria)';
            $params[':categoria'] = $categoria;
        }
        return [implode(' AND ', $where), $params];
    }

    private static function escaparLike(string $texto): string
    {
        return addcslashes($texto, '\\%_');
    }
}
