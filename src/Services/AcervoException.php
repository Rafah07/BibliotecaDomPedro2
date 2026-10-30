<?php

namespace Biblioteca\Services;

use RuntimeException;

/**
 * Operação do acervo físico (exemplares, localizações, inventário, etiquetas) recusada por
 * uma regra. A mensagem é escrita para a bibliotecária e pode ser mostrada na tela.
 */
class AcervoException extends RuntimeException
{
}
