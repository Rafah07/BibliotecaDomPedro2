<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;
use PDOException;

/**
 * Localizações físicas: setor, estante, prateleira e complemento.
 *
 * Regras:
 *   * A mesma combinação (setor + estante + prateleira + complemento) não se repete;
 *     maiúsculas e minúsculas contam como iguais. O código curto também é único.
 *   * Localizações não são excluídas: são desativadas. Uma localização desativada não
 *     recebe novos exemplares, mas os que já estão nela continuam lá.
 *   * Nenhuma distribuição automática (por CDD ou outra regra): a organização física
 *     será definida com a bibliotecária.
 *   * A localização antiga das planilhas (registros_legados.localizacao_antiga) é só
 *     histórica: é listada aqui para consulta e nunca é alterada nem convertida sozinha.
 */
final class LocalizacaoService
{
    use ConsultasPdo;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Filtros: q (setor, estante, prateleira, complemento ou código), setor, situacao (ativas|inativas|todas).
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 25): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (self::termos((string)($f['q'] ?? '')) as $termo) {
            $like = self::like($termo);
            $where[] = '(l.setor LIKE ? OR l.estante LIKE ? OR l.prateleira LIKE ? OR l.complemento LIKE ? OR l.codigo LIKE ? OR l.descricao LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if (($f['setor'] ?? '') !== '') {
            $where[] = 'l.setor = ?';
            $params[] = $f['setor'];
        }
        if (!empty($f['avulsas'])) {
            // Página "Localizações": só as avulsas (as prateleiras ficam na Organização Física).
            $where[] = 'l.estante_id IS NULL';
        }
        $situacao = $f['situacao'] ?? 'ativas';
        if ($situacao === 'ativas') {
            $where[] = 'l.ativa = 1';
        } elseif ($situacao === 'inativas') {
            $where[] = 'l.ativa = 0';
        }
        $w = implode(' AND ', $where);
        $porPagina = max(1, min(100, $porPagina));
        $total = (int)$this->valor("SELECT COUNT(*) FROM localizacoes l WHERE $w", $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT l.*,
                                      (SELECT COUNT(*) FROM exemplares e WHERE e.localizacao_id = l.id AND e.situacao <> 'baixado') AS exemplares
                               FROM localizacoes l WHERE $w
                               ORDER BY l.setor, l.estante, l.prateleira, l.complemento, l.id
                               LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $params);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function buscar(int $id): ?array
    {
        return $this->linha("SELECT l.*, (SELECT COUNT(*) FROM exemplares e WHERE e.localizacao_id = l.id AND e.situacao <> 'baixado') AS exemplares
                             FROM localizacoes l WHERE l.id = ?", [$id]);
    }

    /** Localizações ativas para os seletores, ordenadas. A atual ($incluir) aparece mesmo desativada. */
    public function ativas(?int $incluir = null): array
    {
        // Prateleiras da Organização Física primeiro (na ordem de exibição), depois as localizações avulsas.
        return $this->todas('SELECT l.id, l.setor, l.estante, l.prateleira, l.complemento, l.codigo, l.ativa, l.estante_id, l.descricao,
                                    e.nome AS estante_nome, e.categoria AS estante_categoria, e.identificacao AS estante_identificacao, s.nome AS setor_nome
                             FROM localizacoes l LEFT JOIN estantes e ON e.id = l.estante_id LEFT JOIN setores s ON s.id = e.setor_id
                             WHERE (l.ativa = 1 AND (l.estante_id IS NULL OR (e.ativa = 1 AND (s.id IS NULL OR s.ativo = 1)))) OR l.id = ?
                             ORDER BY l.estante_id IS NULL, s.ordem IS NULL, s.ordem, s.nome, e.ordem, e.identificacao, l.ordem,
                                      l.setor, l.estante, l.prateleira, l.complemento, l.id', [$incluir ?? 0]);
    }

    /** @return list<string> setores distintos (ativos) */
    public function setores(): array
    {
        return array_column($this->todas("SELECT DISTINCT setor FROM localizacoes WHERE ativa = 1 AND setor <> '' ORDER BY setor"), 'setor');
    }

    /** Totais do topo da página. */
    public function resumo(): array
    {
        $r = $this->linha("SELECT COUNT(*) AS total, COALESCE(SUM(ativa = 1), 0) AS ativas, COALESCE(SUM(ativa = 0), 0) AS inativas,
                                  COUNT(DISTINCT CASE WHEN ativa = 1 AND setor <> '' THEN setor END) AS setores,
                                  COALESCE(SUM(estante_id IS NULL AND ativa = 1), 0) AS avulsas FROM localizacoes") ?? [];
        $r['sem_local'] = (int)$this->valor("SELECT COUNT(*) FROM exemplares WHERE localizacao_id IS NULL AND situacao <> 'baixado'");
        return array_map('intval', $r);
    }

    /**
     * Localizações antigas das planilhas (texto livre, histórico), com a quantidade de registros.
     * @return array{itens: list<array>, total: int}
     */
    public function antigas(string $q = '', int $limite = 200): array
    {
        $where = "localizacao_antiga IS NOT NULL AND TRIM(localizacao_antiga) <> ''";
        $params = [];
        foreach (self::termos($q) as $termo) {
            $where .= ' AND localizacao_antiga LIKE ?';
            $params[] = self::like($termo);
        }
        $total = (int)$this->valor("SELECT COUNT(DISTINCT localizacao_antiga) FROM registros_legados WHERE $where", $params);
        $itens = $this->todas("SELECT localizacao_antiga, COUNT(*) AS registros, COUNT(DISTINCT livro_id) AS livros
                               FROM registros_legados WHERE $where GROUP BY localizacao_antiga ORDER BY localizacao_antiga LIMIT " . (int)$limite, $params);
        return ['itens' => $itens, 'total' => $total];
    }

    /** Cria (sem $id) ou edita uma localização. @return int id */
    public function salvar(array $dados, int $responsavelId, ?int $id = null): int
    {
        $d = $this->validar($dados);
        return $this->transacao(function () use ($d, $responsavelId, $id) {
            try {
                if ($id === null) {
                    $this->executar('INSERT INTO localizacoes (setor, estante, prateleira, complemento, codigo, descricao) VALUES (?, ?, ?, ?, ?, ?)',
                        [$d['setor'], $d['estante'], $d['prateleira'], $d['complemento'], $d['codigo'], $d['descricao']]);
                    $id = (int)$this->pdo->lastInsertId();
                    $this->registrarAcao($responsavelId, 'localizacao.cadastrar', 'localizacao', $id, $d);
                    return $id;
                }
                $antes = $this->linha('SELECT setor, estante, prateleira, complemento, codigo, descricao, estante_id FROM localizacoes WHERE id = ? FOR UPDATE', [$id]);
                if (!$antes) {
                    throw new AcervoException('Localização não encontrada.');
                }
                if ($antes['estante_id'] !== null) {
                    throw new AcervoException('Esta localização é uma prateleira de estante. Altere-a em Organização Física.');
                }
                unset($antes['estante_id']);
                $this->executar('UPDATE localizacoes SET setor = ?, estante = ?, prateleira = ?, complemento = ?, codigo = ?, descricao = ? WHERE id = ?',
                    [$d['setor'], $d['estante'], $d['prateleira'], $d['complemento'], $d['codigo'], $d['descricao'], $id]);
                $this->registrarAcao($responsavelId, 'localizacao.editar', 'localizacao', $id, ['antes' => $antes, 'depois' => $d]);
                return $id;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new AcervoException(str_contains($e->getMessage(), 'codigo')
                        ? 'Já existe uma localização com o código "' . $d['codigo'] . '".'
                        : 'Esta localização já está cadastrada (mesmo setor, estante, prateleira e complemento).');
                }
                throw $e;
            }
        });
    }

    /**
     * Localização informada num cadastro (setor, estante, prateleira, complemento): usa a já
     * existente com os mesmos dados ou cadastra uma nova. @return int id
     */
    public function encontrarOuCriar(array $dados, int $responsavelId): int
    {
        $d = $this->validar($dados);
        $existente = $this->linha('SELECT id, ativa FROM localizacoes WHERE estante_id IS NULL AND setor = ? AND estante = ? AND prateleira = ? AND complemento = ?',
            [$d['setor'], $d['estante'], $d['prateleira'], $d['complemento']]);
        if ($existente) {
            if (!(int)$existente['ativa']) {
                throw new AcervoException('Esta localização existe, mas está desativada. Reative-a em Localizações para usá-la.');
            }
            return (int)$existente['id'];
        }
        return $this->salvar($d, $responsavelId);
    }

    public function definirAtiva(int $id, bool $ativa, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $ativa, $responsavelId) {
            $l = $this->linha('SELECT id, ativa FROM localizacoes WHERE id = ? FOR UPDATE', [$id]);
            if (!$l) {
                throw new AcervoException('Localização não encontrada.');
            }
            if ((bool)$l['ativa'] === $ativa) {
                return;
            }
            $this->executar('UPDATE localizacoes SET ativa = ? WHERE id = ?', [$ativa ? 1 : 0, $id]);
            $this->registrarAcao($responsavelId, $ativa ? 'localizacao.ativar' : 'localizacao.desativar', 'localizacao', $id);
        });
    }

    /** Validação no servidor. @return array{setor: string, estante: string, prateleira: string, complemento: string, codigo: ?string, descricao: ?string} */
    public function validar(array $dados): array
    {
        $campo = fn (string $nome, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($dados[$nome] ?? '')) ?? ''), 0, $max);
        $d = [
            'setor' => $campo('setor', 80),
            'estante' => mb_strtoupper($campo('estante', 80)),
            'prateleira' => mb_strtoupper($campo('prateleira', 80)),
            'complemento' => $campo('complemento', 80),
            'codigo' => mb_strtoupper($campo('codigo', 30)) ?: null,
            'descricao' => $campo('descricao', 255) ?: null,
        ];
        if ($d['setor'] === '') {
            throw new AcervoException('Informe o setor ou seção (ex.: Literatura).');
        }
        if ($d['codigo'] !== null && !preg_match('/^[A-Z0-9][A-Z0-9.\-]*$/', $d['codigo'])) {
            throw new AcervoException('O código curto aceita letras sem acento, números, ponto e hífen (ex.: LIT-E03-P02).');
        }
        return $d;
    }
}
