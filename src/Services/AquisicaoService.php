<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Novas aquisições do acervo (migração 190): compra, doação, transferência ou outra origem.
 *
 *  - Independente da planilha original e de inventário: a escola registra a entrada quando recebe os
 *    livros e, em seguida, registra os exemplares (um ou vários) ligados a ela, com código BDP gerado pelo
 *    servidor, lote de processamento (quando há lotes em uso), carimbagem e localização como sempre.
 *  - O registro da aquisição nunca é apagado (gatilho). A quantidade recebida é referência: os exemplares
 *    contam um a um, quando registrados.
 */
final class AquisicaoService
{
    use ConsultasPdo;

    public const TIPOS = ['compra', 'doacao', 'transferencia', 'outro'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return int id da aquisição */
    public function registrar(array $d, int $administradorId): int
    {
        $tipo = (string)($d['tipo'] ?? '');
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new AcervoException('Escolha a origem da aquisição (compra, doação, transferência ou outra).');
        }
        $origem = self::textoLimpo($d['origem'] ?? null, 150);
        if ($origem === null) {
            throw new AcervoException('Informe quem forneceu os livros (fornecedor, doador, escola de origem...).');
        }
        $data = (string)($d['data_recebimento'] ?? '');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$dt || $dt->format('Y-m-d') !== $data || $data > date('Y-m-d')) {
            throw new AcervoException('Informe a data de recebimento (não pode estar no futuro).');
        }
        $qtd = (int)($d['quantidade_recebida'] ?? 0);
        if ($qtd < 1 || $qtd > 100000) {
            throw new AcervoException('Informe quantos livros foram recebidos.');
        }
        return $this->transacao(function () use ($tipo, $origem, $data, $qtd, $d, $administradorId) {
            $this->executar('INSERT INTO aquisicoes (tipo, origem, data_recebimento, quantidade_recebida, documento, observacoes, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $tipo, $origem, $data, $qtd, self::textoLimpo($d['documento'] ?? null, 150), self::textoLimpo($d['observacoes'] ?? null, 1000), $administradorId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->registrarAcao($administradorId, 'aquisicao.registrar', 'aquisicao', $id, ['tipo' => $tipo, 'origem' => $origem, 'quantidade' => $qtd]);
            return $id;
        });
    }

    public function buscar(int $id): ?array
    {
        $a = $this->linha("SELECT q.*, ad.nome AS administrador_nome,
                                  (SELECT COUNT(*) FROM exemplares e WHERE e.aquisicao_id = q.id) AS registrados,
                                  (SELECT COUNT(*) FROM exemplares e WHERE e.aquisicao_id = q.id AND e.carimbo_status = 'aguardando') AS aguardando_carimbo,
                                  (SELECT COUNT(*) FROM exemplares e WHERE e.aquisicao_id = q.id AND e.localizacao_id IS NULL AND e.situacao <> 'baixado') AS sem_local,
                                  (SELECT COUNT(*) FROM exemplares e WHERE e.aquisicao_id = q.id AND e.situacao = 'disponivel') AS disponiveis
                           FROM aquisicoes q LEFT JOIN administradores ad ON ad.id = q.administrador_id WHERE q.id = ?", [$id]);
        return $a ?: null;
    }

    /** Exemplares registrados na aquisição (com a obra e a situação de preparo). */
    public function exemplares(int $id): array
    {
        return $this->todas("SELECT e.id, e.codigo_individual, e.situacao, e.carimbo_status, e.localizacao_id, e.lote_id, e.lote_ordem, l.id AS livro_id, l.titulo
                             FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE e.aquisicao_id = ? ORDER BY e.id", [$id]);
    }

    /** Lista paginada (filtros: tipo, de, ate, q). */
    public function listar(array $f, int $pagina = 1, int $porPagina = 25): array
    {
        $w = [];
        $p = [];
        if (in_array($f['tipo'] ?? '', self::TIPOS, true)) {
            $w[] = 'q.tipo = ?';
            $p[] = $f['tipo'];
        }
        if (!empty($f['de'])) {
            $w[] = 'q.data_recebimento >= ?';
            $p[] = $f['de'];
        }
        if (!empty($f['ate'])) {
            $w[] = 'q.data_recebimento <= ?';
            $p[] = $f['ate'];
        }
        if (trim((string)($f['q'] ?? '')) !== '') {
            $w[] = '(q.origem LIKE ? OR q.documento LIKE ?)';
            $p[] = self::like(trim($f['q']));
            $p[] = self::like(trim($f['q']));
        }
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $total = (int)$this->valor('SELECT COUNT(*) FROM aquisicoes q' . $where, $p);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas('SELECT q.*, ad.nome AS administrador_nome, (SELECT COUNT(*) FROM exemplares e WHERE e.aquisicao_id = q.id) AS registrados
                               FROM aquisicoes q LEFT JOIN administradores ad ON ad.id = q.administrador_id' . $where . '
                               ORDER BY q.data_recebimento DESC, q.id DESC LIMIT ' . (int)$porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina), $p);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** Totais por origem num período (relatório "Aquisições e baixas"). */
    public function resumo(?string $de = null, ?string $ate = null): array
    {
        $w = '';
        $p = [];
        if ($de && $ate) {
            $w = ' WHERE q.data_recebimento BETWEEN ? AND ?';
            $p = [$de, $ate];
        }
        return $this->todas('SELECT q.tipo, COUNT(*) AS aquisicoes, SUM(q.quantidade_recebida) AS recebidos,
                                    (SELECT COUNT(*) FROM exemplares e JOIN aquisicoes q2 ON q2.id = e.aquisicao_id WHERE q2.tipo = q.tipo' . ($de && $ate ? ' AND q2.data_recebimento BETWEEN ? AND ?' : '') . ') AS registrados
                             FROM aquisicoes q' . $w . ' GROUP BY q.tipo ORDER BY q.tipo', $de && $ate ? [$de, $ate, $de, $ate] : []);
    }
}
