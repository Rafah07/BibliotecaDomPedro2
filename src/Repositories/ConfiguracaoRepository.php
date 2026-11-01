<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\ConfiguracaoSistema;
use PDO;

/**
 * Configurações gerais salvas no painel (tabelas configuracoes_sistema e configuracoes_historico,
 * migração 090). Cada alteração fica no histórico, que não pode ser editado nem apagado.
 */
final class ConfiguracaoRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, array{valor: string, atualizado_em: string, atualizado_por_nome: ?string}> */
    public function salvas(): array
    {
        $linhas = [];
        $stmt = $this->pdo->query('SELECT c.chave, c.valor, c.atualizado_em, a.nome AS atualizado_por_nome
                                   FROM configuracoes_sistema c LEFT JOIN administradores a ON a.id = c.atualizado_por');
        foreach ($stmt as $l) {
            if (isset(ConfiguracaoSistema::CAMPOS[$l['chave']]) && $l['valor'] !== null && $l['valor'] !== '') {
                $linhas[$l['chave']] = $l;
            }
        }
        return $linhas;
    }

    /**
     * Grava os valores informados ('' remove o valor do painel) e devolve as chaves alteradas.
     * Tudo numa transação: ou todas as alterações e o histórico são gravados, ou nenhuma.
     * @param array<string, string> $valores
     * @return list<string>
     */
    public function salvar(array $valores, ?int $usuarioId, string $ip = 'cli'): array
    {
        $alteradas = [];
        $this->pdo->beginTransaction();
        try {
            $atuais = [];
            foreach ($this->pdo->query('SELECT chave, valor FROM configuracoes_sistema FOR UPDATE') as $l) {
                $atuais[$l['chave']] = $l['valor'] === '' ? null : $l['valor'];
            }
            $gravar = $this->pdo->prepare('INSERT INTO configuracoes_sistema (chave, valor, atualizado_por) VALUES (:chave, :valor, :usuario)
                                           ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por)');
            $remover = $this->pdo->prepare('DELETE FROM configuracoes_sistema WHERE chave = ?');
            $historico = $this->pdo->prepare('INSERT INTO configuracoes_historico (chave, valor_anterior, valor_novo, usuario_id, ip) VALUES (?, ?, ?, ?, ?)');
            foreach ($valores as $chave => $valor) {
                if (!isset(ConfiguracaoSistema::CAMPOS[$chave])) {
                    continue;
                }
                $novo = $valor === '' ? null : $valor;
                $anterior = $atuais[$chave] ?? null;
                if ($novo === $anterior) {
                    continue;
                }
                if ($novo === null) {
                    $remover->execute([$chave]);
                } else {
                    $gravar->execute([':chave' => $chave, ':valor' => $novo, ':usuario' => $usuarioId]);
                }
                $historico->execute([$chave, $anterior, $novo, $usuarioId, substr($ip, 0, 45)]);
                $alteradas[] = $chave;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $alteradas;
    }

    /** Últimas alterações (mais recentes primeiro). */
    public function historico(int $limite = 30): array
    {
        $stmt = $this->pdo->prepare('SELECT h.chave, h.valor_anterior, h.valor_novo, h.criado_em, a.nome AS usuario_nome
                                     FROM configuracoes_historico h LEFT JOIN administradores a ON a.id = h.usuario_id
                                     ORDER BY h.id DESC LIMIT ' . max(1, min(200, $limite)));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
