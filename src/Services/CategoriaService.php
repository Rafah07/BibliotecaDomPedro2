<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Texto;
use PDO;
use PDOException;

/**
 * Categorias de livros (Acervo → Categorias), gravadas na tabela "categorias" (migração 001).
 *
 * Regras:
 *   * O nome não se repete. A comparação ignora maiúsculas, acentos, espaços e pontuação
 *     (Texto::chave): "Ficção Científica", "ficcao cientifica" e "Ficção-científica" são a mesma
 *     categoria. O banco reforça a regra para as ativas (índice único categorias_nome_ativo_uk,
 *     com collation que ignora maiúsculas e acentos).
 *   * Categorias não são excluídas: são desativadas (arquivado_em). Uma categoria desativada
 *     some dos formulários de livros, dos filtros e do site público, mas o vínculo dos livros
 *     (livro_categorias) é preservado e volta a aparecer quando ela é reativada.
 *   * Uma categoria com livros ativos não pode ser desativada: os livros ficariam sem categoria
 *     sem ninguém decidir. A bibliotecária troca a categoria dos livros (Editar livro) antes.
 *     Nenhum livro é alterado por esta classe.
 *   * Renomear ou corrigir a descrição muda o texto em todo o sistema (livros, filtros, site),
 *     porque os livros guardam só o id da categoria.
 *   * As categorias escritas nas planilhas antigas (registros_legados.categoria_original) são
 *     histórico: aparecem para consulta e nunca viram categoria nem mudam livros sozinhas.
 *   * Cada gravação fica em acoes_administrativas, na mesma transação.
 */
final class CategoriaService
{
    use ConsultasPdo;

    public const NOME_MAX = 80;
    public const DESCRICAO_MAX = 255;
    public const ORDENS = ['nome' => 'c.nome', 'livros' => 'livros', 'cadastro' => 'c.criado_em'];

    /** Contagem real de livros ativos ligados à categoria (principal ou não). */
    private const LIVROS = '(SELECT COUNT(DISTINCT l.id) FROM livro_categorias lc JOIN livros l ON l.id = lc.livro_id AND l.arquivado_em IS NULL WHERE lc.categoria_id = c.id)';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Filtros: q (nome ou descrição; a collation ignora acentos e maiúsculas), situacao (ativas|inativas|todas),
     * ordem (ORDENS), dir (asc|desc).
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 25): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (self::termos((string)($f['q'] ?? '')) as $termo) {
            $like = self::like($termo);
            $where[] = '(c.nome LIKE ? OR c.descricao LIKE ?)';
            array_push($params, $like, $like);
        }
        $situacao = $f['situacao'] ?? 'ativas';
        if ($situacao === 'ativas') {
            $where[] = 'c.arquivado_em IS NULL';
        } elseif ($situacao === 'inativas') {
            $where[] = 'c.arquivado_em IS NOT NULL';
        }
        $w = implode(' AND ', $where);
        $porPagina = max(1, min(100, $porPagina));
        $total = (int)$this->valor("SELECT COUNT(*) FROM categorias c WHERE $w", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $ordem = self::ORDENS[$f['ordem'] ?? 'nome'] ?? 'c.nome';
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $itens = $this->todas('SELECT c.id, c.nome, c.descricao, c.arquivado_em, c.criado_em, ' . self::LIVROS . " AS livros
                               FROM categorias c WHERE $w ORDER BY $ordem $dir, c.nome, c.id
                               LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        foreach ($itens as &$c) {
            $c['id'] = (int)$c['id'];
            $c['livros'] = (int)$c['livros'];
        }
        unset($c);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function buscar(int $id): ?array
    {
        $c = $this->linha('SELECT c.id, c.nome, c.descricao, c.arquivado_em, c.criado_em, ' . self::LIVROS . ' AS livros FROM categorias c WHERE c.id = ?', [$id]);
        if ($c) {
            $c['id'] = (int)$c['id'];
            $c['livros'] = (int)$c['livros'];
        }
        return $c;
    }

    /** Totais do topo da página. */
    public function resumo(): array
    {
        $r = $this->linha('SELECT COALESCE(SUM(c.arquivado_em IS NULL), 0) AS ativas, COALESCE(SUM(c.arquivado_em IS NOT NULL), 0) AS inativas,
                                  COALESCE(SUM(c.arquivado_em IS NULL AND EXISTS (SELECT 1 FROM livro_categorias lc JOIN livros l ON l.id = lc.livro_id AND l.arquivado_em IS NULL WHERE lc.categoria_id = c.id)), 0) AS em_uso
                           FROM categorias c') ?? [];
        $r['sem_categoria'] = (int)$this->valor('SELECT COUNT(*) FROM livros l WHERE l.arquivado_em IS NULL AND NOT EXISTS
                                                 (SELECT 1 FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL WHERE lc.livro_id = l.id)');
        return array_map('intval', $r + ['ativas' => 0, 'inativas' => 0, 'em_uso' => 0]);
    }

    /** Cadastra (sem $id) ou edita uma categoria. @return int id */
    public function salvar(array $dados, int $responsavelId, ?int $id = null): int
    {
        $d = $this->validar($dados);
        return $this->transacao(function () use ($d, $responsavelId, $id) {
            $antes = null;
            if ($id !== null) {
                $antes = $this->linha('SELECT id, nome, descricao, arquivado_em FROM categorias WHERE id = ? FOR UPDATE', [$id]);
                if (!$antes) {
                    throw new AcervoException('Categoria não encontrada.');
                }
            }
            $this->conferirDuplicidade($d['nome'], $id);
            try {
                if ($id === null) {
                    $this->executar('INSERT INTO categorias (nome, descricao) VALUES (?, ?)', [$d['nome'], $d['descricao']]);
                    $id = (int)$this->pdo->lastInsertId();
                    $this->registrarAcao($responsavelId, 'categoria.cadastrar', 'categoria', $id, ['nome' => $d['nome']]);
                    return $id;
                }
                $campos = [];
                if ($antes['nome'] !== $d['nome']) {
                    $campos[] = 'nome';
                }
                if ((string)$antes['descricao'] !== (string)$d['descricao']) {
                    $campos[] = 'descrição';
                }
                if (!$campos) {
                    return $id;
                }
                $this->executar('UPDATE categorias SET nome = ?, descricao = ? WHERE id = ?', [$d['nome'], $d['descricao'], $id]);
                $det = ['nome' => $d['nome'], 'campos' => implode(', ', $campos)];
                if (in_array('nome', $campos, true)) {
                    $det['nome_anterior'] = $antes['nome'];
                }
                $this->registrarAcao($responsavelId, 'categoria.editar', 'categoria', $id, $det);
                return $id;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new AcervoException('Já existe uma categoria ativa com o nome "' . $d['nome'] . '".');
                }
                throw $e;
            }
        });
    }

    /**
     * Desativa ou reativa. Desativar é recusado enquanto houver livros ativos na categoria.
     * Devolve false quando ela já estava na situação pedida.
     */
    public function definirAtiva(int $id, bool $ativa, int $responsavelId): bool
    {
        return $this->transacao(function () use ($id, $ativa, $responsavelId) {
            $c = $this->linha('SELECT id, nome, arquivado_em FROM categorias WHERE id = ? FOR UPDATE', [$id]);
            if (!$c) {
                throw new AcervoException('Categoria não encontrada.');
            }
            if (($c['arquivado_em'] === null) === $ativa) {
                return false;
            }
            if (!$ativa) {
                $livros = (int)$this->valor('SELECT COUNT(DISTINCT l.id) FROM livro_categorias lc JOIN livros l ON l.id = lc.livro_id AND l.arquivado_em IS NULL WHERE lc.categoria_id = ?', [$id]);
                if ($livros > 0) {
                    throw new AcervoException('A categoria "' . $c['nome'] . '" está ligada a ' . $livros . ' livro(s) ativo(s) e não pode ser desativada. '
                        . 'Se ela não deve mais ser usada, troque a categoria desses livros em Editar livro e depois desative-a.');
                }
                $this->executar('UPDATE categorias SET arquivado_em = NOW() WHERE id = ?', [$id]);
            } else {
                $this->conferirDuplicidade($c['nome'], $id);
                try {
                    $this->executar('UPDATE categorias SET arquivado_em = NULL WHERE id = ?', [$id]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new AcervoException('Já existe uma categoria ativa com o nome "' . $c['nome'] . '". Renomeie uma delas antes de reativar.');
                    }
                    throw $e;
                }
            }
            $this->registrarAcao($responsavelId, $ativa ? 'categoria.reativar' : 'categoria.desativar', 'categoria', $id, ['nome' => $c['nome']]);
            return true;
        });
    }

    /**
     * Limpeza e validação do nome e da descrição.
     * @return array{nome: string, descricao: ?string}
     */
    public function validar(array $dados): array
    {
        $nome = self::limpar((string)($dados['nome'] ?? ''));
        $descricao = self::limpar((string)($dados['descricao'] ?? ''));
        if ($nome === '') {
            throw new AcervoException('Informe o nome da categoria.');
        }
        if (mb_strlen($nome) < 2) {
            throw new AcervoException('O nome da categoria precisa ter pelo menos 2 caracteres.');
        }
        if (mb_strlen($nome) > self::NOME_MAX) {
            throw new AcervoException('O nome da categoria pode ter no máximo ' . self::NOME_MAX . ' caracteres.');
        }
        if (!preg_match('/\p{L}/u', $nome)) {
            throw new AcervoException('O nome da categoria precisa ter letras.');
        }
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N} ,.;:()\/&+\'’ªº°-]*$/u', $nome)) {
            throw new AcervoException('O nome da categoria aceita letras, números, espaços e os sinais , . ; : ( ) / & + \' - (sem < > " ou outros símbolos).');
        }
        if (mb_strlen($descricao) > self::DESCRICAO_MAX) {
            throw new AcervoException('A descrição pode ter no máximo ' . self::DESCRICAO_MAX . ' caracteres.');
        }
        return ['nome' => $nome, 'descricao' => $descricao === '' ? null : $descricao];
    }

    /** Recusa um nome que já existe (ativa ou desativada) em outra categoria, comparando sem acentos, maiúsculas e pontuação. */
    private function conferirDuplicidade(string $nome, ?int $exceto): void
    {
        $chave = Texto::chave($nome);
        foreach ($this->todas('SELECT id, nome, arquivado_em FROM categorias WHERE id <> ?', [$exceto ?? 0]) as $c) {
            if (Texto::chave($c['nome']) !== $chave) {
                continue;
            }
            if ($c['arquivado_em'] === null) {
                throw new AcervoException('Já existe a categoria "' . $c['nome'] . '". Letras maiúsculas, acentos e pontuação não diferenciam categorias.');
            }
            throw new AcervoException('Já existe a categoria "' . $c['nome'] . '", que está desativada. Reative-a na lista de categorias (filtro Desativadas) em vez de cadastrar outra.');
        }
    }

    /**
     * Grupos de categorias com nomes equivalentes (mesma chave sem acentos/pontuação), vindos de
     * cadastros antigos. Só para avisar: nada é unificado sozinho. @return list<list<array>>
     */
    public function possiveisDuplicidades(): array
    {
        $grupos = [];
        foreach ($this->todas('SELECT id, nome, arquivado_em FROM categorias ORDER BY nome, id') as $c) {
            $grupos[Texto::chave($c['nome'])][] = $c;
        }
        return array_values(array_filter($grupos, fn ($g) => count($g) > 1));
    }

    /**
     * Categorias escritas nas planilhas antigas (texto histórico), com o número de linhas e de
     * livros e a categoria cadastrada equivalente, quando houver. Somente consulta.
     * @return array{itens: list<array>, total: int}
     */
    public function antigas(string $q = '', int $limite = 200): array
    {
        $where = "categoria_original IS NOT NULL AND TRIM(categoria_original) <> ''";
        $params = [];
        foreach (self::termos($q) as $termo) {
            $where .= ' AND categoria_original LIKE ?';
            $params[] = self::like($termo);
        }
        $total = (int)$this->valor("SELECT COUNT(DISTINCT categoria_original) FROM registros_legados WHERE $where", $params);
        $itens = $this->todas("SELECT categoria_original, COUNT(*) AS registros, COUNT(DISTINCT livro_id) AS livros
                               FROM registros_legados WHERE $where GROUP BY categoria_original ORDER BY categoria_original LIMIT " . (int)$limite, $params);
        $cadastradas = [];
        foreach ($this->todas('SELECT id, nome, arquivado_em FROM categorias') as $c) {
            $cadastradas[Texto::chave($c['nome'])] ??= $c;
        }
        foreach ($itens as &$i) {
            $i['equivalente'] = $cadastradas[Texto::chave($i['categoria_original'])] ?? null;
        }
        unset($i);
        return ['itens' => $itens, 'total' => $total];
    }

    /** Alterações registradas desta categoria. */
    public function historico(int $id, int $limite = 30): array
    {
        return $this->todas("SELECT a.acao, a.detalhes, a.criado_em, u.nome AS responsavel FROM acoes_administrativas a
                             LEFT JOIN administradores u ON u.id = a.usuario_id
                             WHERE a.entidade = 'categoria' AND a.entidade_id = ? ORDER BY a.criado_em DESC, a.id DESC LIMIT " . max(1, min(100, $limite)), [$id]);
    }

    /** Texto em uma linha: sem caracteres de controle nem espaços repetidos. */
    private static function limpar(string $valor): string
    {
        if (!mb_check_encoding($valor, 'UTF-8')) {
            $valor = mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
        }
        $valor = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $valor) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $valor) ?? '');
    }
}
