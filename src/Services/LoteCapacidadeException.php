<?php

namespace Biblioteca\Services;

/**
 * O lote ativo não tem vagas para todos os exemplares pedidos. Nada foi gravado.
 * $restam: vagas que ainda existem (0 = lote completo).
 */
final class LoteCapacidadeException extends AcervoException
{
    public function __construct(string $mensagem, public readonly int $loteId, public readonly int $restam)
    {
        parent::__construct($mensagem);
    }
}
