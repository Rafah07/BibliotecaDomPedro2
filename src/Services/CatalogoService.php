<?php

namespace Biblioteca\Services;

use Biblioteca\Repositories\CatalogoRepository;
use Throwable;

/**
 * Regras do catálogo público: de onde vêm os livros, quais filtros são aceitos e como
 * cada livro e sua disponibilidade são apresentados.
 *
 * Fontes possíveis (fonte()):
 *  - "acervo"          MySQL configurado e com livros ativos.
 *  - "vazio"           MySQL configurado, mas nenhum livro ativo: estado vazio.
 *  - "indisponivel"    MySQL fora do ar ou sem as tabelas: estado vazio com aviso.
 *  - "nao_configurado" sem banco configurado (.env): estado vazio com aviso.
 * O site público nunca mostra livros fictícios.
 *
 * Disponibilidade (ver disponibilidade()):
 *  - sem exemplares identificados: o livro está no catálogo, mas nenhuma unidade física foi
 *    cadastrada ainda (a quantidade das planilhas antigas NÃO conta);
 *  - com exemplares e pelo menos um "disponivel": mostra quantos estão disponíveis;
 *  - com exemplares, mas nenhum disponível (emprestados, em preparo ou manutenção).
 */
final class CatalogoService
{
    public const POR_PAGINA = 24;

    public const CAMPOS_BUSCA = [
        'tudo' => 'Todos os campos',
        'titulo' => 'Título',
        'autor' => 'Autor',
        'isbn' => 'ISBN',
        'assunto' => 'Assunto ou categoria',
    ];

    public const ORDENS = [
        'titulo' => 'Título (A–Z)',
        'titulo_desc' => 'Título (Z–A)',
        'autor' => 'Autor (A–Z)',
        'ano_desc' => 'Ano de publicação (mais novos)',
        'ano_asc' => 'Ano de publicação (mais antigos)',
        'recentes' => 'Adicionados recentemente',
    ];

    public const DISPONIBILIDADES = [
        '' => 'Todos os livros do catálogo',
        'disponivel' => 'Com exemplar disponível agora',
        'identificado' => 'Com exemplares físicos identificados',
    ];

    private const ICONES = [
        'ficção' => ['sparkles', 'tone-blue'], 'romance' => ['heart', 'tone-pink'],
        'fantasia' => ['sparkles', 'tone-purple'], 'aventura' => ['compass', 'tone-orange'],
        'história' => ['landmark', 'tone-green'], 'clássicos' => ['book-open', 'tone-navy'],
        'poesia' => ['feather', 'tone-pink'], 'ciências' => ['flask-conical', 'tone-green'],
        'biografia' => ['user-round', 'tone-orange'],
    ];
    private const TONS = ['tone-blue', 'tone-purple', 'tone-orange', 'tone-green', 'tone-pink', 'tone-navy', 'tone-sky'];

    private string $fonte;
    private ?array $categoriasCache = null;

    public function __construct(private ?CatalogoRepository $repo, bool $bancoIndisponivel = false)
    {
        if ($bancoIndisponivel) {
            $this->fonte = 'indisponivel';
        } elseif ($repo === null) {
            $this->fonte = 'nao_configurado';
        } else {
            $this->fonte = 'acervo';
            $this->fonte = $this->tentar(fn () => $repo->totalLivros() > 0 ? 'acervo' : 'vazio', 'indisponivel');
        }
    }

    public function fonte(): string
    {
        return $this->fonte;
    }

    public function disponivel(): bool
    {
        return $this->fonte === 'acervo';
    }

    /**
     * Lê e valida os filtros da URL. Valores inválidos são ignorados (nunca chegam ao SQL).
     * @return array{q: string, campo: string, categoria: int, autor: int, ano_de: ?int, ano_ate: ?int, disponibilidade: string, ordem: string, pagina: int}
     */
    public static function filtros(array $get): array
    {
        $texto = static fn (string $campo, int $max): string => isset($get[$campo]) && is_string($get[$campo]) ? mb_substr(trim($get[$campo]), 0, $max) : '';
        $inteiro = static function (string $campo, int $min, int $max) use ($texto): ?int {
            $v = $texto($campo, 10);
            return preg_match('/^\d+$/', $v) && (int)$v >= $min && (int)$v <= $max ? (int)$v : null;
        };
        $campo = $texto('campo', 20);
        $ordem = $texto('ordem', 20);
        $disp = $texto('disponibilidade', 20);
        $anoDe = $inteiro('ano_de', 1000, 2100);
        $anoAte = $inteiro('ano_ate', 1000, 2100);
        if ($anoDe !== null && $anoAte !== null && $anoDe > $anoAte) {
            [$anoDe, $anoAte] = [$anoAte, $anoDe];
        }
        $q = preg_replace('/[\x00-\x1F\x7F]/u', '', $texto('q', 120)) ?? '';
        return [
            'q' => $q,
            'campo' => isset(self::CAMPOS_BUSCA[$campo]) ? $campo : 'tudo',
            'categoria' => $inteiro('categoria', 1, PHP_INT_MAX) ?? 0,
            'autor' => $inteiro('autor', 1, PHP_INT_MAX) ?? 0,
            'ano_de' => $anoDe,
            'ano_ate' => $anoAte,
            'disponibilidade' => isset(self::DISPONIBILIDADES[$disp]) ? $disp : '',
            'ordem' => isset(self::ORDENS[$ordem]) ? $ordem : 'titulo',
            'pagina' => $inteiro('pagina', 1, 100000) ?? 1,
        ];
    }

    /** true quando algum filtro (além da ordenação e da página) está aplicado. */
    public static function temFiltros(array $f): bool
    {
        return $f['q'] !== '' || $f['categoria'] || $f['autor'] || $f['ano_de'] !== null || $f['ano_ate'] !== null || $f['disponibilidade'] !== '';
    }

    /** @return array{itens: list<array>, total: int, pagina: int, paginas: int} */
    public function buscarLivros(array $f): array
    {
        $vazio = ['itens' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
        if ($this->fonte !== 'acervo') {
            return $vazio;
        }
        $pagina = max(1, $f['pagina']);
        $resultado = $this->tentar(fn () => $this->repo->buscar($f, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA), null);
        if ($resultado === null) {
            return $vazio;
        }
        $paginas = max(1, (int)ceil($resultado['total'] / self::POR_PAGINA));
        if ($pagina > $paginas && $resultado['total'] > 0) {
            // Página além do fim (link antigo ou digitado): mostra a última.
            $pagina = $paginas;
            $resultado = $this->tentar(fn () => $this->repo->buscar($f, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA), $resultado);
        }
        return [
            'itens' => array_map([$this, 'livroResumo'], $resultado['itens']),
            'total' => $resultado['total'],
            'pagina' => $pagina,
            'paginas' => $paginas,
        ];
    }

    /** Livros adicionados recentemente (página inicial). */
    public function recentes(int $quantidade = 8): array
    {
        if ($this->fonte !== 'acervo') {
            return [];
        }
        return array_map([$this, 'livroResumo'], $this->tentar(fn () => $this->repo->recentes($quantidade), []));
    }

    /** Números gerais para a página inicial, ou null se não houver acervo. */
    public function estatisticas(): ?array
    {
        if ($this->fonte !== 'acervo') {
            return null;
        }
        $e = $this->tentar(fn () => $this->repo->estatisticas(), null);
        return $e ? array_map('intval', $e) : null;
    }

    /**
     * Livro para a página de detalhes, ou null se não existir, estiver arquivado ou o catálogo
     * estiver indisponível.
     */
    public function livro(int $id): ?array
    {
        if ($this->fonte !== 'acervo' || $id < 1) {
            return null;
        }
        $l = $this->tentar(fn () => $this->repo->livro($id), null);
        if (!$l) {
            return null;
        }
        $situacoes = $l['situacoes'];
        $total = array_sum($situacoes);
        $disponiveis = $situacoes['disponivel'] ?? 0;
        $emprestados = $situacoes['emprestado'] ?? 0;
        return [
            'id' => (int)$l['id'],
            'titulo' => $l['titulo'],
            'subtitulo' => (string)$l['subtitulo'],
            'autores' => $l['autores'],
            'autor' => implode(', ', array_column($l['autores'], 'nome')),
            'categorias' => $l['categorias'],
            'isbn' => (string)$l['isbn'],
            'editora' => (string)$l['editora'],
            'ano' => $l['ano_publicacao'] !== null ? (int)$l['ano_publicacao'] : null,
            'edicao' => (string)$l['edicao'],
            'idioma' => (string)$l['idioma'],
            'paginas' => $l['numero_paginas'] !== null ? (int)$l['numero_paginas'] : null,
            'sinopse' => trim((string)$l['sinopse']),
            'assunto' => trim((string)$l['assunto']),
            'palavras_chave' => array_values(array_filter(array_map('trim', preg_split('/[;,]/', (string)$l['palavras_chave']) ?: []))),
            'cdd' => $l['cdd'],
            'capa' => self::capaSegura((string)$l['capa']),
            'classe' => self::classeCapa((int)$l['id']),
            'disponibilidade' => self::disponibilidade($total, $disponiveis),
            'exemplares' => ['total' => $total, 'disponiveis' => $disponiveis, 'emprestados' => $emprestados, 'outros' => $total - $disponiveis - $emprestados],
        ];
    }

    /** Outros livros do mesmo autor e da mesma categoria (somente relações reais do cadastro). */
    public function relacionados(array $livro, int $limite = 4): array
    {
        $autor = $this->tentar(fn () => $this->repo->doMesmoAutor($livro['id'], array_column($livro['autores'], 'id'), $limite), []);
        $ja = array_merge([$livro['id']], array_map(static fn ($l) => (int)$l['id'], $autor));
        $categoria = array_values(array_filter(
            $this->tentar(fn () => $this->repo->daMesmaCategoria($livro['id'], array_column($livro['categorias'], 'id'), $limite + count($autor)), []),
            static fn ($l) => !in_array((int)$l['id'], $ja, true)
        ));
        return [
            'autor' => array_map([$this, 'livroResumo'], $autor),
            'categoria' => array_map([$this, 'livroResumo'], array_slice($categoria, 0, $limite)),
        ];
    }

    /** Categorias com ícone, cor e quantidade de títulos (somente as que têm livros ativos). */
    public function categorias(): array
    {
        if ($this->fonte !== 'acervo') {
            return [];
        }
        if ($this->categoriasCache !== null) {
            return $this->categoriasCache;
        }
        $categorias = [];
        foreach ($this->tentar(fn () => $this->repo->categorias(), []) as $i => $c) {
            if ((int)$c['total'] === 0) {
                continue;
            }
            [$icone, $cor] = self::ICONES[mb_strtolower($c['nome'])] ?? ['tag', self::TONS[$i % count(self::TONS)]];
            $categorias[] = ['id' => (int)$c['id'], 'nome' => $c['nome'], 'desc' => (string)$c['descricao'], 'icon' => $icone, 'cor' => $cor, 'total' => (int)$c['total']];
        }
        return $this->categoriasCache = $categorias;
    }

    /** Autores com a quantidade de títulos no acervo. */
    public function autores(string $busca = '', int $limite = 60): array
    {
        if ($this->fonte !== 'acervo') {
            return [];
        }
        $autores = [];
        foreach ($this->tentar(fn () => $this->repo->autores($busca, $limite), []) as $i => $a) {
            $autores[] = ['id' => (int)$a['id'], 'nome' => $a['nome'], 'total' => (int)$a['total'], 'cor' => self::TONS[$i % count(self::TONS)]];
        }
        return $autores;
    }

    public function nomeAutor(int $id): ?string
    {
        return $id > 0 && $this->fonte === 'acervo' ? $this->tentar(fn () => $this->repo->nomeAutor($id), null) : null;
    }

    /** Rótulo e tom da disponibilidade (os três casos descritos no topo da classe). */
    public static function disponibilidade(int $exemplares, int $disponiveis): array
    {
        return match (true) {
            $exemplares === 0 => ['estado' => 'sem_exemplar', 'rotulo' => 'Exemplares ainda não identificados', 'tom' => 'muted'],
            $disponiveis === 0 => ['estado' => 'indisponivel', 'rotulo' => 'Nenhum disponível no momento', 'tom' => 'busy'],
            default => ['estado' => 'disponivel', 'rotulo' => $disponiveis === 1 ? '1 disponível' : $disponiveis . ' disponíveis', 'tom' => 'ok'],
        };
    }

    /**
     * Endereço da capa, se for seguro exibi-la: somente imagens do próprio site
     * (ex.: "uploads/capas/livro-12-ab12cd.jpg"). Endereços externos são ignorados para que
     * o navegador dos alunos não envie dados a outros serviços.
     */
    public static function capaSegura(string $capa): ?string
    {
        $capa = trim($capa);
        if ($capa === '' || str_contains($capa, '..') || str_contains($capa, '\\')) {
            return null;
        }
        return preg_match('#^/?(uploads/capas|assets/capas)/[A-Za-z0-9._-]+\.(jpe?g|png|webp)$#i', $capa) ? ltrim($capa, '/') : null;
    }

    private static function classeCapa(int $id): string
    {
        return 'cover-' . ($id % 12 + 1);
    }

    private function livroResumo(array $l): array
    {
        return [
            'id' => (int)$l['id'],
            'titulo' => $l['titulo'],
            'subtitulo' => (string)$l['subtitulo'],
            'autor' => (string)($l['autores'] ?? ''),
            'categoria' => (string)($l['categoria'] ?? ''),
            'assunto' => trim((string)($l['assunto'] ?? '')),
            'isbn' => (string)$l['isbn'],
            'editora' => (string)($l['editora'] ?? ''),
            'ano' => $l['ano_publicacao'] !== null ? (int)$l['ano_publicacao'] : null,
            'edicao' => (string)($l['edicao'] ?? ''),
            'capa' => self::capaSegura((string)$l['capa']),
            'classe' => self::classeCapa((int)$l['id']),
            'disponibilidade' => self::disponibilidade((int)$l['exemplares'], (int)$l['disponiveis']),
        ];
    }

    /** Executa uma consulta; em caso de erro, registra no log (nunca na tela) e devolve o padrão. */
    private function tentar(callable $consulta, mixed $padrao): mixed
    {
        try {
            return $consulta();
        } catch (Throwable $e) {
            error_log('[biblioteca] Falha no catálogo público: ' . $e->getMessage());
            $this->fonte = 'indisponivel';
            return $padrao;
        }
    }
}
