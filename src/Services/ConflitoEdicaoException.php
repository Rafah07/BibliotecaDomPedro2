<?php

namespace Biblioteca\Services;

/**
 * A obra foi gravada por outra pessoa enquanto o formulário estava aberto (controle de concorrência
 * otimista, livros.versao — migração 190). Nada foi gravado: a página mostra a comparação entre o que
 * foi carregado, o que está salvo agora e o que a pessoa digitou, sem descartar o que ela digitou.
 */
final class ConflitoEdicaoException extends AcervoException
{
    /**
     * @param array<string, mixed> $atual dados salvos agora (mesmo formato de LivroRepository::instantaneo)
     */
    public function __construct(public readonly array $atual, public readonly int $versaoAtual, public readonly ?string $alteradoPor = null, public readonly ?string $alteradoEm = null)
    {
        parent::__construct('Esta obra foi atualizada por outro usuário enquanto você estava editando. Nada foi gravado: compare as informações abaixo antes de salvar.');
    }
}
