<?php

namespace Biblioteca\Repositories;

use RuntimeException;

/** Uma linha que não pôde ser gravada no acervo por um motivo explicável ao usuário. */
final class ItemNaoGravado extends RuntimeException
{
}
