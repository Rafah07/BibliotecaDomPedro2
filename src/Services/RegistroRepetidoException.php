<?php

namespace Biblioteca\Services;

/**
 * O mesmo formulário de registro de exemplar foi enviado de novo (clique duplo, atualização da
 * página, requisição repetida). Nada é gravado outra vez; a página mostra o exemplar já registrado.
 */
final class RegistroRepetidoException extends AcervoException
{
    public function __construct(string $mensagem, public readonly int $exemplarId, public readonly string $codigo)
    {
        parent::__construct($mensagem);
    }
}
