<?php

namespace Biblioteca\Services;

/** O exemplar já foi conferido neste inventário. $item traz a conferência anterior para consulta. */
final class ConferenciaDuplicadaException extends AcervoException
{
    public function __construct(string $mensagem, public readonly array $item)
    {
        parent::__construct($mensagem);
    }
}
