<?php

namespace Biblioteca\Reorganizacao;

use Biblioteca\Services\AcervoException;

/** Outra pessoa salvou o registro antes (versão diferente): nada foi sobrescrito. */
final class RegistroConflitoException extends AcervoException
{
    public function __construct(string $mensagem, public readonly array $atual = [])
    {
        parent::__construct($mensagem);
    }
}
