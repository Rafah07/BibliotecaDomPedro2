<?php

namespace Biblioteca\Repositories;

use PDO;

/**
 * Textos institucionais do site público (tabela site_configuracoes, migração 080).
 * Uma linha por campo; campos sem linha (ou vazios) aparecem como "aguardando confirmação".
 */
final class SiteConfiguracaoRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, string> chave => valor */
    public function todos(): array
    {
        $valores = [];
        foreach ($this->pdo->query('SELECT chave, valor FROM site_configuracoes') as $linha) {
            $valores[$linha['chave']] = (string)$linha['valor'];
        }
        return $valores;
    }

    /**
     * Grava os valores informados e devolve as chaves que mudaram.
     * @param array<string, string> $valores
     * @return list<string>
     */
    public function salvar(array $valores, ?int $usuarioId): array
    {
        $atuais = $this->todos();
        $alteradas = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO site_configuracoes (chave, valor, atualizado_por) VALUES (:chave, :valor, :usuario)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por)'
        );
        $this->pdo->beginTransaction();
        try {
            foreach ($valores as $chave => $valor) {
                if (($atuais[$chave] ?? '') === $valor) {
                    continue;
                }
                $stmt->execute([':chave' => $chave, ':valor' => $valor, ':usuario' => $usuarioId]);
                $alteradas[] = $chave;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $alteradas;
    }
}
