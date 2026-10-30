<?php

namespace Biblioteca\Services;

use Biblioteca\Repositories\CatalogoRepository;
use Throwable;

/**
 * Regras do catálogo público: de onde vêm os livros e como cada um é apresentado.
 *
 * Fontes possíveis (fonte()):
 *  - "acervo"        MySQL configurado e com livros: só dados reais.
 *  - "vazio"         MySQL configurado, mas sem livros: estado vazio (nada fictício).
 *  - "indisponivel"  MySQL configurado, mas fora do ar ou sem as tabelas: estado vazio com aviso.
 *  - "demonstracao"  MySQL não configurado: livros de exemplo, sempre identificados como tal.
 * Dados fictícios e dados reais nunca aparecem juntos.
 */
final class CatalogoService
{
    public const POR_PAGINA = 24;

    private const ICONES = [
        'ficção' => ['auto_stories', 'tone-blue'], 'romance' => ['favorite', 'tone-pink'],
        'fantasia' => ['auto_awesome', 'tone-purple'], 'aventura' => ['explore', 'tone-orange'],
        'história' => ['history_edu', 'tone-green'], 'clássicos' => ['menu_book', 'tone-navy'],
        'fábula' => ['local_library', 'tone-sky'], 'poesia' => ['edit_note', 'tone-pink'],
        'ciências' => ['science', 'tone-green'], 'biografia' => ['person', 'tone-orange'],
    ];
    private const TONS = ['tone-blue', 'tone-purple', 'tone-orange', 'tone-green', 'tone-pink', 'tone-navy', 'tone-sky'];

    private string $fonte;

    /**
     * @param array{livros: array, generos: array, autores: array} $exemplos dados de includes/dados-exemplo.php
     */
    public function __construct(private ?CatalogoRepository $repo, private array $exemplos, bool $bancoIndisponivel = false)
    {
        if ($bancoIndisponivel) {
            $this->fonte = 'indisponivel';
        } elseif ($repo === null) {
            $this->fonte = 'demonstracao';
        } else {
            $this->fonte = $this->tentar(fn () => $repo->totalLivros() > 0 ? 'acervo' : 'vazio', 'indisponivel');
        }
    }

    public function fonte(): string
    {
        return $this->fonte;
    }

    public function ehDemonstracao(): bool
    {
        return $this->fonte === 'demonstracao';
    }

    /** @return array{itens: list<array>, total: int, pagina: int, paginas: int} */
    public function buscarLivros(string $busca, string $genero, int $pagina = 1): array
    {
        $pagina = max(1, $pagina);
        if ($this->fonte === 'demonstracao') {
            $itens = array_values(array_filter($this->exemplos['livros'], static function ($l) use ($busca, $genero) {
                $combinaBusca = $busca === '' || stripos($l['titulo'] . ' ' . $l['autor'] . ' ' . $l['genero'], $busca) !== false;
                return $combinaBusca && ($genero === '' || $l['genero'] === $genero);
            }));
            return ['itens' => array_map([$this, 'livroExemplo'], $itens), 'total' => count($itens), 'pagina' => 1, 'paginas' => 1];
        }
        if ($this->fonte !== 'acervo') {
            return ['itens' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
        }
        $resultado = $this->tentar(
            fn () => $this->repo->buscar($busca, $genero, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA),
            ['itens' => [], 'total' => 0]
        );
        return [
            'itens' => array_map([$this, 'livroAcervo'], $resultado['itens']),
            'total' => $resultado['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int)ceil($resultado['total'] / self::POR_PAGINA)),
        ];
    }

    /** Livros para a página inicial. */
    public function destaques(int $quantidade = 4): array
    {
        return match ($this->fonte) {
            'demonstracao' => array_map([$this, 'livroExemplo'], array_slice($this->exemplos['livros'], 0, $quantidade)),
            'acervo' => array_map([$this, 'livroAcervo'], $this->tentar(fn () => $this->repo->recentes($quantidade), [])),
            default => [],
        };
    }

    /** Gêneros (categorias) com ícone, cor e quantidade de títulos. */
    public function generos(): array
    {
        if ($this->fonte === 'demonstracao') {
            return array_map(fn ($g) => $g + [
                'total' => count(array_filter($this->exemplos['livros'], static fn ($l) => $l['genero'] === $g['nome'])),
            ], $this->exemplos['generos']);
        }
        if ($this->fonte !== 'acervo') {
            return [];
        }
        $generos = [];
        foreach ($this->tentar(fn () => $this->repo->categorias(), []) as $i => $c) {
            [$icone, $cor] = self::ICONES[mb_strtolower($c['nome'])] ?? ['category', self::TONS[$i % count(self::TONS)]];
            $generos[] = [
                'nome' => $c['nome'],
                'desc' => (string)$c['descricao'],
                'icon' => $icone,
                'cor' => $cor,
                'total' => (int)$c['total'],
            ];
        }
        return $generos;
    }

    /** Autores com a quantidade de títulos no acervo. */
    public function autores(string $busca = '', int $limite = 60): array
    {
        if ($this->fonte === 'demonstracao') {
            $lista = array_filter($this->exemplos['autores'], static fn ($a) => $busca === '' || stripos($a['nome'] . ' ' . $a['tag'], $busca) !== false);
            return array_map(fn ($a) => $a + [
                'total' => count(array_filter($this->exemplos['livros'], static fn ($l) => $l['autor'] === $a['nome'])),
            ], array_values($lista));
        }
        if ($this->fonte !== 'acervo') {
            return [];
        }
        $autores = [];
        foreach ($this->tentar(fn () => $this->repo->autores($busca, $limite), []) as $i => $a) {
            $autores[] = [
                'nome' => $a['nome'],
                'tag' => (int)$a['total'] === 1 ? '1 título no acervo' : $a['total'] . ' títulos no acervo',
                'desc' => '',
                'cor' => self::TONS[$i % count(self::TONS)],
                'total' => (int)$a['total'],
            ];
        }
        return $autores;
    }

    private function livroExemplo(array $l): array
    {
        return [
            'titulo' => $l['titulo'],
            'autor' => $l['autor'],
            'genero' => $l['genero'],
            'isbn' => '',
            'capa' => null,
            'classe' => $l['classe'],
            'disponibilidade' => null, // exemplos não têm exemplares
        ];
    }

    private function livroAcervo(array $l): array
    {
        $exemplares = (int)$l['exemplares'];
        $disponiveis = (int)$l['disponiveis'];
        $disponibilidade = match (true) {
            $exemplares === 0 => ['rotulo' => 'Aguardando conferência', 'tom' => 'muted'],
            $disponiveis === 0 => ['rotulo' => 'Indisponível no momento', 'tom' => 'busy'],
            default => ['rotulo' => $disponiveis === 1 ? '1 disponível' : $disponiveis . ' disponíveis', 'tom' => 'ok'],
        };
        $capa = (string)$l['capa'];
        return [
            'titulo' => $l['titulo'],
            'autor' => (string)($l['autores'] ?? '') ?: 'Autor não informado',
            'genero' => (string)($l['categoria'] ?? ''),
            'isbn' => (string)$l['isbn'],
            // Só endereços http(s) ou caminhos do próprio site.
            'capa' => preg_match('#^(https?://|/|[a-z0-9_-]+/)#i', $capa) ? $capa : null,
            'classe' => 'cover-' . ((int)$l['id'] % 12 + 1),
            'disponibilidade' => $disponibilidade,
        ];
    }

    /** Executa uma consulta; em caso de erro, registra no log e devolve o valor padrão. */
    private function tentar(callable $consulta, mixed $padrao): mixed
    {
        try {
            return $consulta();
        } catch (Throwable $e) {
            error_log('[biblioteca] Falha no catálogo público: ' . $e->getMessage());
            if ($this->fonte ?? null) {
                $this->fonte = 'indisponivel';
            }
            return $padrao;
        }
    }
}
