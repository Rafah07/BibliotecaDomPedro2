<?php

namespace Biblioteca\Support;

use Biblioteca\Services\AcervoException;
use PDO;
use PDOException;
use Throwable;

/**
 * Atalhos de consulta usados pelos serviços e repositórios de exemplares, localizações,
 * inventário e etiquetas. Sempre com parâmetros preparados.
 * A classe que usa o trait precisa ter a propriedade $pdo.
 */
trait ConsultasPdo
{
    private function linha(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function todas(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function valor(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function executar(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Executa $fn numa transação. Se já houver uma transação aberta (um serviço chamando outro),
     * participa dela: quem abriu decide o commit. Deadlock e espera longa viram mensagem amigável.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transacao(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $resultado = $fn();
            $this->pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new AcervoException('Outra pessoa estava alterando este registro. Nada foi gravado; tente novamente.', 0, $e);
            }
            throw $e;
        }
    }

    /** Registro em acoes_administrativas, na mesma transação. Nunca inclui dados de alunos. */
    private function registrarAcao(?int $usuarioId, string $acao, string $entidade, ?int $entidadeId, array $detalhes = []): void
    {
        $this->executar('INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, ?, ?, ?, ?, ?)', [
            $usuarioId, $acao, $entidade, $entidadeId, $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
        ]);
    }

    /** Texto de uma linha, sem espaços repetidos, cortado no tamanho máximo. Vazio vira null. */
    private static function textoLimpo(?string $valor, int $maximo = 500): ?string
    {
        $valor = trim(preg_replace('/\s+/u', ' ', (string)$valor) ?? '');
        return $valor === '' ? null : mb_substr($valor, 0, $maximo);
    }

    private static function like(string $termo): string
    {
        return '%' . addcslashes($termo, '%_\\') . '%';
    }

    /** @return list<string> até 6 palavras da pesquisa */
    private static function termos(string $q): array
    {
        $termos = array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], fn ($t) => $t !== '' && mb_strlen($t) <= 100));
        return array_slice($termos, 0, 6);
    }
}
