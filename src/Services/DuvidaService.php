<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Dúvidas registradas durante a conferência (migração 160): ISBN desconhecido, edição não
 * identificada, informações divergentes, possível associação incorreta, conservação...
 *
 *   * Registrar uma dúvida NÃO impede a equipe de continuar trabalhando.
 *   * Dúvida de exemplar entra no lote dele (relatório do lote). Dúvida que compromete a
 *     identificação física é exigida resolvida antes de confirmar a carimbagem e de encerrar o lote.
 *   * Dúvidas não são apagadas (gatilho): ficam com a resolução, quem resolveu e quando.
 */
final class DuvidaService
{
    use ConsultasPdo;

    public const TIPOS = [
        'isbn_desconhecido' => 'ISBN desconhecido',
        'edicao_nao_identificada' => 'Edição não identificada',
        'informacoes_divergentes' => 'Informações divergentes',
        'associacao_incorreta' => 'Possível associação incorreta',
        'conservacao' => 'Dúvida sobre conservação',
        'outro' => 'Outras observações',
    ];

    public function __construct(private PDO $pdo, private ?ExemplarService $exemplares = null)
    {
        $this->exemplares ??= new ExemplarService($pdo);
    }

    /**
     * Dados: tipo, descricao, compromete ('1'), livro_id e/ou exemplar_id (ou codigo BDP do exemplar).
     * @return int id da dúvida
     */
    public function registrar(array $dados, int $administradorId): int
    {
        $tipo = (string)($dados['tipo'] ?? '');
        if (!isset(self::TIPOS[$tipo])) {
            throw new AcervoException('Escolha o tipo da dúvida.');
        }
        $descricao = self::textoLimpo((string)($dados['descricao'] ?? ''), 1000);
        if ($tipo === 'outro' && $descricao === null) {
            throw new AcervoException('Descreva a dúvida.');
        }
        $compromete = !empty($dados['compromete']) && (string)$dados['compromete'] !== '0';
        return $this->transacao(function () use ($dados, $tipo, $descricao, $compromete, $administradorId) {
            $exemplar = null;
            if ((int)($dados['exemplar_id'] ?? 0) > 0) {
                $exemplar = $this->linha('SELECT id, livro_id, lote_id, codigo_individual FROM exemplares WHERE id = ?', [(int)$dados['exemplar_id']]);
            } elseif (trim((string)($dados['codigo'] ?? '')) !== '') {
                $exemplar = $this->linha('SELECT id, livro_id, lote_id, codigo_individual FROM exemplares WHERE codigo_individual = ?', [strtoupper(trim((string)$dados['codigo']))]);
                if (!$exemplar) {
                    throw new AcervoException('Nenhum exemplar tem o código ' . trim((string)$dados['codigo']) . '.');
                }
            }
            $livroId = $exemplar ? (int)$exemplar['livro_id'] : ((int)($dados['livro_id'] ?? 0) ?: null);
            if ($livroId === null) {
                throw new AcervoException('Informe a obra ou o exemplar da dúvida.');
            }
            if (!$exemplar && !$this->valor('SELECT 1 FROM livros WHERE id = ?', [$livroId])) {
                throw new AcervoException('Obra não encontrada.');
            }
            $loteId = $exemplar && $exemplar['lote_id'] !== null ? (int)$exemplar['lote_id'] : null;
            $this->executar('INSERT INTO conferencia_duvidas (livro_id, exemplar_id, lote_id, tipo, descricao, compromete_identificacao, registrada_por) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$livroId, $exemplar ? (int)$exemplar['id'] : null, $loteId, $tipo, $descricao, $compromete ? 1 : 0, $administradorId]);
            $id = (int)$this->pdo->lastInsertId();
            if ($loteId !== null) {
                $this->exemplares->eventoLote($loteId, (int)$exemplar['id'], 'duvida_registrada', 'Dúvida nº ' . $id . ' (' . self::TIPOS[$tipo] . ') sobre ' . $exemplar['codigo_individual']
                    . ($compromete ? ' · compromete a identificação física' : ''), $administradorId, ['duvida_id' => $id]);
            }
            $this->registrarAcao($administradorId, 'duvida.registrar', 'duvida', $id, ['tipo' => $tipo, 'livro_id' => $livroId, 'exemplar_id' => $exemplar['id'] ?? null, 'compromete' => $compromete]);
            return $id;
        });
    }

    public function resolver(int $id, string $resolucao, int $administradorId): void
    {
        $resolucao = self::textoLimpo($resolucao, 1000);
        if ($resolucao === null) {
            throw new AcervoException('Escreva como a dúvida foi resolvida.');
        }
        $this->transacao(function () use ($id, $resolucao, $administradorId) {
            $d = $this->linha('SELECT d.*, e.codigo_individual FROM conferencia_duvidas d LEFT JOIN exemplares e ON e.id = d.exemplar_id WHERE d.id = ? FOR UPDATE', [$id]);
            if (!$d) {
                throw new AcervoException('Dúvida não encontrada.');
            }
            if ($d['status'] !== 'aberta') {
                throw new AcervoException('Esta dúvida já foi resolvida.');
            }
            $this->executar("UPDATE conferencia_duvidas SET status = 'resolvida', resolvida_em = NOW(), resolvida_por = ?, resolucao = ? WHERE id = ?", [$administradorId, $resolucao, $id]);
            if ($d['lote_id'] !== null) {
                $this->exemplares->eventoLote((int)$d['lote_id'], $d['exemplar_id'] !== null ? (int)$d['exemplar_id'] : null, 'duvida_resolvida',
                    'Dúvida nº ' . $id . ' resolvida' . ($d['codigo_individual'] ? ' (' . $d['codigo_individual'] . ')' : '') . ' · ' . $resolucao, $administradorId, ['duvida_id' => $id]);
            }
            $this->registrarAcao($administradorId, 'duvida.resolver', 'duvida', $id, []);
        });
    }
}
