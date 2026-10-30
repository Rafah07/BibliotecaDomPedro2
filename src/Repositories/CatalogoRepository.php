<?php

namespace Biblioteca\Repositories;

use PDO;

/**
 * Consultas do catálogo público no MySQL (somente leitura: nada aqui grava no banco).
 *
 * Só devolve campos bibliográficos públicos. Observações internas, situação de validação,
 * CDD antiga ou em revisão, dados das planilhas (registros_legados), códigos dos exemplares,
 * empréstimos e alunos nunca saem daqui.
 *
 * Disponibilidade: vem só da tabela "exemplares" (unidades físicas com código BDP), nunca da
 * quantidade informada nas planilhas antigas. Exemplares baixados ou extraviados não contam.
 *
 * Busca sem acentos: as comparações usam a collation utf8mb4_unicode_ci, que ignora acentos e
 * maiúsculas ("principe" encontra "Príncipe", "coracao" encontra "Coração"). Funciona no
 * MySQL e no MariaDB do XAMPP sem depender da extensão intl do PHP.
 */
final class CatalogoRepository
{
    /** Exemplares que existem fisicamente no acervo (identificados). */
    private const IDENTIFICADO = "e.situacao NOT IN ('baixado', 'extraviado')";

    public const ORDENS = [
        'titulo' => 'l.titulo ASC, l.id ASC',
        'titulo_desc' => 'l.titulo DESC, l.id DESC',
        'autor' => 'primeiro_autor IS NULL, primeiro_autor ASC, l.titulo ASC, l.id ASC',
        'ano_desc' => 'l.ano_publicacao IS NULL, l.ano_publicacao DESC, l.titulo ASC, l.id ASC',
        'ano_asc' => 'l.ano_publicacao IS NULL, l.ano_publicacao ASC, l.titulo ASC, l.id ASC',
        'recentes' => 'l.criado_em DESC, l.id DESC',
    ];

    private int $marcador = 0;

    public function __construct(private PDO $pdo)
    {
    }

    public function totalLivros(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL')->fetchColumn();
    }

    /** Números gerais do acervo para a página inicial. */
    public function estatisticas(): array
    {
        $identificado = self::IDENTIFICADO;
        return $this->pdo->query(
            "SELECT (SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL) AS titulos,
                    (SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL WHERE $identificado) AS exemplares,
                    (SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL WHERE e.situacao = 'disponivel') AS disponiveis"
        )->fetch();
    }

    /**
     * Página de livros ativos conforme os filtros.
     * @param array{q: string, campo: string, categoria: int, autor: int, ano_de: ?int, ano_ate: ?int, disponibilidade: string, ordem: string} $f
     * @return array{itens: list<array>, total: int}
     */
    public function buscar(array $f, int $limite, int $deslocamento): array
    {
        $this->marcador = 0;
        [$where, $params] = $this->filtros($f);

        $total = $this->pdo->prepare("SELECT COUNT(*) FROM livros l WHERE $where");
        $total->execute($params);
        $quantidade = (int)$total->fetchColumn();
        if ($quantidade === 0 || $deslocamento >= $quantidade) {
            return ['itens' => [], 'total' => $quantidade];
        }

        $ordem = self::ORDENS[$f['ordem']] ?? self::ORDENS['titulo'];
        $sql = $this->selectLivros() . " WHERE $where ORDER BY $ordem LIMIT :limite OFFSET :deslocamento";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':deslocamento', $deslocamento, PDO::PARAM_INT);
        $stmt->execute();
        return ['itens' => $stmt->fetchAll(), 'total' => $quantidade];
    }

    /** Últimos livros cadastrados (página inicial). */
    public function recentes(int $limite): array
    {
        $stmt = $this->pdo->prepare($this->selectLivros() . ' WHERE l.arquivado_em IS NULL ORDER BY l.criado_em DESC, l.id DESC LIMIT :limite');
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Um livro ativo com tudo o que a página de detalhes pode mostrar, ou null se não existir
     * ou estiver arquivado.
     */
    public function livro(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao, l.idioma,
                    l.numero_paginas, l.sinopse, l.palavras_chave, l.assunto, l.capa, l.criado_em
               FROM livros l WHERE l.id = ? AND l.arquivado_em IS NULL'
        );
        $stmt->execute([$id]);
        $livro = $stmt->fetch();
        if (!$livro) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT a.id, a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = ? ORDER BY la.ordem, a.nome');
        $stmt->execute([$id]);
        $livro['autores'] = $stmt->fetchAll();

        $stmt = $this->pdo->prepare('SELECT c.id, c.nome FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id
                                      WHERE lc.livro_id = ? AND c.arquivado_em IS NULL ORDER BY lc.principal DESC, c.nome');
        $stmt->execute([$id]);
        $livro['categorias'] = $stmt->fetchAll();

        // Somente a CDD aprovada por um responsável. A CDD antiga das planilhas e as propostas não aparecem.
        $stmt = $this->pdo->prepare('SELECT cdd_aprovada, descricao FROM classificacoes_cdd
                                      WHERE livro_id = ? AND cdd_aprovada IS NOT NULL AND aprovado_em IS NOT NULL');
        $stmt->execute([$id]);
        $livro['cdd'] = $stmt->fetch() ?: null;

        $stmt = $this->pdo->prepare('SELECT e.situacao, COUNT(*) AS total FROM exemplares e WHERE e.livro_id = ? AND ' . self::IDENTIFICADO . ' GROUP BY e.situacao');
        $stmt->execute([$id]);
        $livro['situacoes'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

        return $livro;
    }

    /** Outros livros ativos de algum dos autores informados. */
    public function doMesmoAutor(int $livroId, array $autorIds, int $limite): array
    {
        return $this->relacionados($livroId, $autorIds, 'livro_autores', 'autor_id', $limite);
    }

    /** Outros livros ativos de alguma das categorias informadas. */
    public function daMesmaCategoria(int $livroId, array $categoriaIds, int $limite): array
    {
        return $this->relacionados($livroId, $categoriaIds, 'livro_categorias', 'categoria_id', $limite);
    }

    /** Categorias ativas com o número de títulos ativos em cada uma. */
    public function categorias(): array
    {
        return $this->pdo->query(
            'SELECT c.id, c.nome, c.descricao, COUNT(DISTINCT l.id) AS total
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
        $sql = 'SELECT a.id, a.nome, COUNT(DISTINCT l.id) AS total
                FROM autores a
                JOIN livro_autores la ON la.autor_id = a.id
                JOIN livros l ON l.id = la.livro_id AND l.arquivado_em IS NULL';
        $params = [];
        if ($busca !== '') {
            $sql .= ' WHERE a.nome LIKE :busca COLLATE utf8mb4_unicode_ci';
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

    /** Nome de um autor que tenha algum livro ativo (para mostrar o filtro aplicado). */
    public function nomeAutor(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT a.nome FROM autores a WHERE a.id = ?
                                       AND EXISTS (SELECT 1 FROM livro_autores la JOIN livros l ON l.id = la.livro_id AND l.arquivado_em IS NULL WHERE la.autor_id = a.id)');
        $stmt->execute([$id]);
        $nome = $stmt->fetchColumn();
        return $nome === false ? null : (string)$nome;
    }

    private function relacionados(int $livroId, array $ids, string $tabela, string $coluna, int $limite): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $sql = $this->selectLivros() . " WHERE l.arquivado_em IS NULL AND l.id <> ?
                 AND EXISTS (SELECT 1 FROM $tabela r WHERE r.livro_id = l.id AND r.$coluna IN ($marcas))
               ORDER BY l.titulo, l.id LIMIT " . max(1, min(24, $limite));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$livroId], $ids));
        return $stmt->fetchAll();
    }

    private function selectLivros(): string
    {
        $identificado = self::IDENTIFICADO;
        return "SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.capa, l.editora, l.ano_publicacao, l.edicao, l.assunto,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem, a.nome SEPARATOR ', ')
                       FROM livro_autores la JOIN autores a ON a.id = la.autor_id
                      WHERE la.livro_id = l.id) AS autores,
                    (SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id
                      WHERE la.livro_id = l.id ORDER BY la.ordem, a.nome LIMIT 1) AS primeiro_autor,
                    (SELECT c.nome FROM livro_categorias lc
                       JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL
                      WHERE lc.livro_id = l.id ORDER BY lc.principal DESC, c.nome LIMIT 1) AS categoria,
                    (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND $identificado) AS exemplares,
                    (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao = 'disponivel') AS disponiveis
                FROM livros l";
    }

    /** @return array{0: string, 1: array<string, string|int>} */
    private function filtros(array $f): array
    {
        $where = ['l.arquivado_em IS NULL'];
        $params = [];

        // Cada palavra pesquisada precisa aparecer em algum dos campos escolhidos (em qualquer ordem).
        foreach (self::palavras($f['q']) as $palavra) {
            $termo = '%' . self::escaparLike($palavra) . '%';
            $digitos = preg_replace('/[^0-9Xx]/', '', $palavra);
            $ou = [];
            $campo = $f['campo'];
            if ($campo === 'tudo' || $campo === 'titulo') {
                $ou[] = 'l.titulo LIKE ' . $this->param($params, $termo, true);
                $ou[] = 'l.subtitulo LIKE ' . $this->param($params, $termo, true);
            }
            if ($campo === 'tudo' || $campo === 'autor') {
                $ou[] = 'EXISTS (SELECT 1 FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id AND a.nome LIKE ' . $this->param($params, $termo, true) . ')';
            }
            if (($campo === 'tudo' || $campo === 'isbn') && strlen($digitos) >= 3) {
                $ou[] = 'l.isbn LIKE ' . $this->param($params, '%' . strtoupper($digitos) . '%');
            }
            if ($campo === 'tudo' || $campo === 'assunto') {
                $ou[] = 'l.assunto LIKE ' . $this->param($params, $termo, true);
                $ou[] = 'l.palavras_chave LIKE ' . $this->param($params, $termo, true);
                $ou[] = 'EXISTS (SELECT 1 FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id
                                  WHERE lc.livro_id = l.id AND c.arquivado_em IS NULL AND c.nome LIKE ' . $this->param($params, $termo, true) . ')';
            }
            // ISBN pesquisado com poucos dígitos: nenhuma condição possível, nada é encontrado.
            $where[] = $ou ? '(' . implode(' OR ', $ou) . ')' : '1 = 0';
        }
        if ($f['categoria'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id
                                WHERE lc.livro_id = l.id AND c.arquivado_em IS NULL AND c.id = ' . $this->param($params, $f['categoria']) . ')';
        }
        if ($f['autor'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM livro_autores la WHERE la.livro_id = l.id AND la.autor_id = ' . $this->param($params, $f['autor']) . ')';
        }
        if ($f['ano_de'] !== null) {
            $where[] = 'l.ano_publicacao >= ' . $this->param($params, $f['ano_de']);
        }
        if ($f['ano_ate'] !== null) {
            $where[] = 'l.ano_publicacao <= ' . $this->param($params, $f['ano_ate']);
        }
        if ($f['disponibilidade'] === 'disponivel') {
            $where[] = "EXISTS (SELECT 1 FROM exemplares e WHERE e.livro_id = l.id AND e.situacao = 'disponivel')";
        } elseif ($f['disponibilidade'] === 'identificado') {
            $where[] = 'EXISTS (SELECT 1 FROM exemplares e WHERE e.livro_id = l.id AND ' . self::IDENTIFICADO . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    /** Cria um marcador com nome próprio (prepares nativos não aceitam nomes repetidos). */
    private function param(array &$params, string|int $valor, bool $semAcento = false): string
    {
        $nome = ':p' . (++$this->marcador);
        $params[$nome] = $valor;
        return $semAcento ? "$nome COLLATE utf8mb4_unicode_ci" : $nome;
    }

    /** Palavras da pesquisa (no máximo 8), sem pontuação solta. */
    public static function palavras(string $busca): array
    {
        $palavras = preg_split('/\s+/u', trim($busca), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $palavras = array_filter(array_map(static fn ($p) => trim($p, " \t\"'`´“”‘’.,;:!?()[]{}"), $palavras), static fn ($p) => $p !== '');
        return array_slice(array_values(array_unique($palavras)), 0, 8);
    }

    private static function escaparLike(string $texto): string
    {
        return addcslashes($texto, '\\%_');
    }
}
