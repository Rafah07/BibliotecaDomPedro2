<?php

namespace Biblioteca\Services;

/**
 * O mesmo envio de formulário (mesma chave de operação, tabela requisicoes_idempotentes — migração 190)
 * já tinha sido concluído: clique duplo, página recarregada, conexão perdida antes da resposta.
 * Nada foi gravado de novo; $resultado traz o que a primeira gravação criou (ids e códigos BDP).
 */
final class OperacaoJaConcluidaException extends AcervoException
{
    public function __construct(public readonly array $resultado)
    {
        parent::__construct('Esta operação já tinha sido registrada antes. Nada foi gravado de novo.');
    }
}
