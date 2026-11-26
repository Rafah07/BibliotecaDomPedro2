<?php

namespace Biblioteca\Reorganizacao;

use Biblioteca\Services\AcervoException;

/** A sessão temporária do participante não vale mais (saída, encerramento, inatividade ou fim do período). */
final class SessaoEncerradaException extends AcervoException
{
}
