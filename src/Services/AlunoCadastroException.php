<?php

namespace Biblioteca\Services;

use RuntimeException;

/**
 * Cadastro ou correção de aluno recusado. Leva os erros por campo (para mostrar ao lado de cada
 * campo do formulário) e, quando for o caso, as possíveis duplicidades que precisam de confirmação.
 */
final class AlunoCadastroException extends RuntimeException
{
    /**
     * @param array<string, string> $erros campo => mensagem ('geral' para o formulário todo)
     * @param list<array> $duplicidades outros cadastros com o mesmo nome ou e-mail
     * @param bool $pedeConfirmacao true quando basta marcar a confirmação e enviar de novo
     */
    public function __construct(
        string $mensagem,
        public readonly array $erros = [],
        public readonly array $duplicidades = [],
        public readonly bool $pedeConfirmacao = false,
    ) {
        parent::__construct($mensagem);
    }
}
