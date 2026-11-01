<?php

namespace Biblioteca\Repositories;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\PeriodoRelatorio;
use PDO;

/**
 * Consultas dos relatórios administrativos. SOMENTE LEITURA: nenhuma consulta daqui grava,
 * altera ou apaga dados (nem cria tabelas temporárias).
 *
 * Regras de contagem (as mesmas do dashboard, que usa estes métodos para os números comuns):
 *   - Registro bibliográfico = linha de "livros" não arquivada. Nunca é exemplar físico.
 *   - Exemplar físico = linha de "exemplares" (código BDP) de um livro não arquivado.
 *     "No acervo" exclui baixados e extraviados.
 *   - Quantidade histórica = soma de registros_legados.quantidade_informada (planilha antiga):
 *     só referência, nunca somada aos exemplares.
 *   - Empréstimo ativo = data_devolucao vazia; atrasado = ativo com data_prevista anterior a
 *     "hoje" (data da escola, recebida como parâmetro). Cada empréstimo conta uma vez, mesmo
 *     renovado: as renovações ficam em emprestimo_renovacoes e são contadas à parte.
 *   - Empréstimos do período usam data_emprestimo; devoluções do período usam data_devolucao.
 *   - CDD aprovada = classificacoes_cdd.status 'aprovada'. A CDD antiga da planilha nunca
 *     conta como aprovada.
 *
 * Tudo com parâmetros preparados. LIMIT/OFFSET são inteiros calculados aqui.
 */
final class RelatorioRepository
{
    use ConsultasPdo;

    public const SITUACOES_EXEMPLAR = ['disponivel', 'emprestado', 'aguardando', 'manutencao', 'danificado', 'extraviado', 'baixado'];
    public const CONSERVACAO = ['bom', 'regular', 'danificado', 'necessita_reparo'];
    public const PENDENCIAS_LIVRO = ['com_isbn', 'sem_isbn', 'sem_autor', 'sem_editora', 'sem_ano', 'incompletos', 'sem_categoria', 'sem_exemplar', 'duplicidade'];
    public const STATUS_LIVRO = ['validado', 'aguardando_conferencia', 'dados_incompletos', 'com_divergencia'];
    public const SITUACOES_EMPRESTIMO = ['todos', 'ativos', 'atrasados', 'concluidos'];
    public const FILTROS_CDD = ['aprovada', 'pendente', 'sugerida', 'em_revisao', 'conflito', 'antiga', 'sem_aprovacao'];

    private const AUTORES = "(SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem, au.nome SEPARATOR ', ') FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id)";
    private const CATEGORIAS = "(SELECT GROUP_CONCAT(c.nome ORDER BY lc.principal DESC, c.nome SEPARATOR ', ') FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL WHERE lc.livro_id = l.id)";
    private const AUTOR_PRINCIPAL = '(SELECT la.autor_id FROM livro_autores la WHERE la.livro_id = %s.id ORDER BY la.ordem, la.autor_id LIMIT 1)';
    private const SEM_AUTOR = 'NOT EXISTS (SELECT 1 FROM livro_autores la WHERE la.livro_id = l.id)';
    private const SEM_EDITORA = "(l.editora IS NULL OR TRIM(l.editora) = '')";
    private const SEM_ISBN = "(l.isbn IS NULL OR l.isbn = '')";
    private const SEM_CATEGORIA = 'NOT EXISTS (SELECT 1 FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL WHERE lc.livro_id = l.id)';
    private const SEM_EXEMPLAR = "NOT EXISTS (SELECT 1 FROM exemplares e WHERE e.livro_id = l.id AND e.situacao NOT IN ('baixado', 'extraviado'))";

    public function __construct(private PDO $pdo)
    {
    }

    /** Condição "possível duplicidade": outro livro ativo com o mesmo título e o mesmo autor principal. */
    private static function duplicidade(): string
    {
        return 'EXISTS (SELECT 1 FROM livros l2 WHERE l2.titulo = l.titulo AND l2.id <> l.id AND l2.arquivado_em IS NULL AND '
            . sprintf(self::AUTOR_PRINCIPAL, 'l2') . ' <=> ' . sprintf(self::AUTOR_PRINCIPAL, 'l') . ')';
    }

    private static function incompleto(): string
    {
        return '(' . self::SEM_AUTOR . ' OR ' . self::SEM_EDITORA . ' OR l.ano_publicacao IS NULL)';
    }

    private static function limite(int $offset, int $limite): string
    {
        return ' LIMIT ' . max(1, min(5000, $limite)) . ' OFFSET ' . max(0, $offset);
    }

    // =================================================================
    // Acervo (registros bibliográficos)
    // =================================================================

    public function resumoAcervo(): array
    {
        $r = $this->linha('SELECT COUNT(*) AS total,
                                  COALESCE(SUM(NOT ' . self::SEM_ISBN . '), 0) AS com_isbn,
                                  COALESCE(SUM(' . self::SEM_ISBN . '), 0) AS sem_isbn,
                                  COALESCE(SUM(' . self::SEM_AUTOR . '), 0) AS sem_autor,
                                  COALESCE(SUM(' . self::SEM_EDITORA . '), 0) AS sem_editora,
                                  COALESCE(SUM(l.ano_publicacao IS NULL), 0) AS sem_ano,
                                  COALESCE(SUM(' . self::incompleto() . '), 0) AS incompletos,
                                  COALESCE(SUM(' . self::SEM_CATEGORIA . '), 0) AS sem_categoria,
                                  COALESCE(SUM(' . self::SEM_EXEMPLAR . '), 0) AS sem_exemplar,
                                  COALESCE(SUM(' . self::duplicidade() . "), 0) AS duplicidade,
                                  COALESCE(SUM(l.status_validacao = 'validado'), 0) AS validados
                           FROM livros l WHERE l.arquivado_em IS NULL") ?? [];
        $r = array_map('intval', $r);
        $r['arquivados'] = (int)$this->valor('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NOT NULL');
        $h = $this->linha('SELECT COALESCE(SUM(r.quantidade_informada), 0) AS quantidade, COUNT(DISTINCT CASE WHEN r.quantidade_informada IS NOT NULL THEN r.livro_id END) AS livros
                           FROM registros_legados r JOIN livros l ON l.id = r.livro_id AND l.arquivado_em IS NULL') ?? [];
        $r['quantidade_historica'] = (int)($h['quantidade'] ?? 0);
        $r['livros_com_quantidade_historica'] = (int)($h['livros'] ?? 0);
        return $r;
    }

    /**
     * Filtros: titulo, autor, categoria (id | 'sem'), ano (int), status (status_validacao),
     * pendencia (PENDENCIAS_LIVRO), periodo (PeriodoRelatorio sobre a data de cadastro no sistema).
     * @return array{0: string, 1: list<mixed>}
     */
    private function filtrosLivros(array $f): array
    {
        $w = ['l.arquivado_em IS NULL'];
        $p = [];
        foreach (self::termos((string)($f['titulo'] ?? '')) as $t) {
            $w[] = '(l.titulo LIKE ? OR l.subtitulo LIKE ? OR l.isbn = ?)';
            array_push($p, self::like($t), self::like($t), preg_replace('/[^0-9X]/i', '', $t));
        }
        foreach (self::termos((string)($f['autor'] ?? '')) as $t) {
            $w[] = 'EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?)';
            $p[] = self::like($t);
        }
        if (($f['categoria'] ?? '') === 'sem') {
            $w[] = self::SEM_CATEGORIA;
        } elseif ((int)($f['categoria'] ?? 0) > 0) {
            $w[] = 'EXISTS (SELECT 1 FROM livro_categorias lc WHERE lc.livro_id = l.id AND lc.categoria_id = ?)';
            $p[] = (int)$f['categoria'];
        }
        if (($f['ano'] ?? '') === 'sem') {
            $w[] = 'l.ano_publicacao IS NULL';
        } elseif ((int)($f['ano'] ?? 0) > 0) {
            $w[] = 'l.ano_publicacao = ?';
            $p[] = (int)$f['ano'];
        }
        if (in_array($f['status'] ?? '', self::STATUS_LIVRO, true)) {
            $w[] = 'l.status_validacao = ?';
            $p[] = $f['status'];
        }
        $w[] = match ($f['pendencia'] ?? '') {
            'com_isbn' => 'NOT ' . self::SEM_ISBN,
            'sem_isbn' => self::SEM_ISBN,
            'sem_autor' => self::SEM_AUTOR,
            'sem_editora' => self::SEM_EDITORA,
            'sem_ano' => 'l.ano_publicacao IS NULL',
            'incompletos' => self::incompleto(),
            'sem_categoria' => self::SEM_CATEGORIA,
            'sem_exemplar' => self::SEM_EXEMPLAR,
            'duplicidade' => self::duplicidade(),
            default => '1 = 1',
        };
        if (($f['periodo'] ?? null) instanceof PeriodoRelatorio) {
            [$c, $cp] = $f['periodo']->condicao('l.criado_em');
            $w[] = $c;
            array_push($p, ...$cp);
        }
        return [implode(' AND ', $w), $p];
    }

    public function contarLivros(array $f): int
    {
        [$w, $p] = $this->filtrosLivros($f);
        return (int)$this->valor("SELECT COUNT(*) FROM livros l WHERE $w", $p);
    }

    public function livros(array $f, int $offset = 0, int $limite = 25): array
    {
        [$w, $p] = $this->filtrosLivros($f);
        $ordem = ($f['pendencia'] ?? '') === 'duplicidade' ? 'l.titulo, l.id' : 'l.titulo, l.id';
        return $this->todas('SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao, l.assunto, l.status_validacao, l.origem_cadastro, l.criado_em,
                                    ' . self::AUTORES . ' AS autores, ' . self::CATEGORIAS . " AS categorias,
                                    (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao NOT IN ('baixado', 'extraviado')) AS exemplares,
                                    (SELECT SUM(r.quantidade_informada) FROM registros_legados r WHERE r.livro_id = l.id) AS quantidade_historica
                             FROM livros l WHERE $w ORDER BY $ordem" . self::limite($offset, $limite), $p);
    }

    /** Grupos de possíveis duplicidades (mesmo título e mesmo autor principal). */
    public function contarGruposDuplicidade(): int
    {
        return (int)$this->valor('SELECT COUNT(*) FROM (SELECT 1 FROM livros l WHERE l.arquivado_em IS NULL
                                   GROUP BY l.titulo, ' . sprintf(self::AUTOR_PRINCIPAL, 'l') . ' HAVING COUNT(*) > 1) g');
    }

    public function gruposDuplicidade(int $offset = 0, int $limite = 25): array
    {
        return $this->todas('SELECT MIN(l.titulo) AS titulo, MIN(' . self::AUTORES . ') AS autores, COUNT(*) AS registros,
                                    GROUP_CONCAT(l.id ORDER BY l.id SEPARATOR \', \') AS ids,
                                    GROUP_CONCAT(DISTINCT COALESCE(l.editora, \'Não informado\') ORDER BY l.editora SEPARATOR \' | \') AS editoras,
                                    GROUP_CONCAT(DISTINCT COALESCE(l.ano_publicacao, \'Não informado\') ORDER BY l.ano_publicacao SEPARATOR \' | \') AS anos,
                                    MIN(l.id) AS livro_id
                             FROM livros l WHERE l.arquivado_em IS NULL
                             GROUP BY l.titulo, ' . sprintf(self::AUTOR_PRINCIPAL, 'l') . '
                             HAVING COUNT(*) > 1 ORDER BY registros DESC, titulo' . self::limite($offset, $limite));
    }

    /** Livros ativos por categoria (um livro em duas categorias conta nas duas; "Não informado" = sem categoria). */
    public function livrosPorCategoria(): array
    {
        $linhas = $this->todas('SELECT c.id, c.nome AS rotulo, COUNT(DISTINCT l.id) AS valor FROM categorias c
                                JOIN livro_categorias lc ON lc.categoria_id = c.id
                                JOIN livros l ON l.id = lc.livro_id AND l.arquivado_em IS NULL
                                WHERE c.arquivado_em IS NULL GROUP BY c.id, c.nome ORDER BY valor DESC, c.nome');
        $sem = (int)$this->valor('SELECT COUNT(*) FROM livros l WHERE l.arquivado_em IS NULL AND ' . self::SEM_CATEGORIA);
        if ($sem > 0) {
            $linhas[] = ['id' => 'sem', 'rotulo' => 'Não informado', 'valor' => $sem];
        }
        return self::inteiros($linhas);
    }

    public function livrosPorAssunto(int $limite = 15): array
    {
        $linhas = $this->todas("SELECT TRIM(l.assunto) AS rotulo, COUNT(*) AS valor FROM livros l
                                WHERE l.arquivado_em IS NULL AND l.assunto IS NOT NULL AND TRIM(l.assunto) <> ''
                                GROUP BY TRIM(l.assunto) ORDER BY valor DESC, rotulo LIMIT " . (int)$limite);
        $sem = (int)$this->valor("SELECT COUNT(*) FROM livros l WHERE l.arquivado_em IS NULL AND (l.assunto IS NULL OR TRIM(l.assunto) = '')");
        if ($sem > 0) {
            $linhas[] = ['rotulo' => 'Não informado', 'valor' => $sem];
        }
        return self::inteiros($linhas);
    }

    public function contarAssuntos(): int
    {
        return (int)$this->valor("SELECT COUNT(DISTINCT TRIM(assunto)) FROM livros WHERE arquivado_em IS NULL AND assunto IS NOT NULL AND TRIM(assunto) <> ''");
    }

    public function assuntos(int $offset, int $limite): array
    {
        return self::inteiros($this->todas("SELECT TRIM(l.assunto) AS rotulo, COUNT(*) AS valor FROM livros l
                                            WHERE l.arquivado_em IS NULL AND l.assunto IS NOT NULL AND TRIM(l.assunto) <> ''
                                            GROUP BY TRIM(l.assunto) ORDER BY valor DESC, rotulo" . self::limite($offset, $limite)));
    }

    public function contarAutores(): int
    {
        return (int)$this->valor('SELECT COUNT(DISTINCT la.autor_id) FROM livro_autores la JOIN livros l ON l.id = la.livro_id AND l.arquivado_em IS NULL');
    }

    /** Autores com mais obras cadastradas (registros bibliográficos, não exemplares). */
    public function autores(int $offset = 0, int $limite = 15): array
    {
        return self::inteiros($this->todas('SELECT au.nome AS rotulo, COUNT(DISTINCT l.id) AS valor FROM autores au
                                            JOIN livro_autores la ON la.autor_id = au.id
                                            JOIN livros l ON l.id = la.livro_id AND l.arquivado_em IS NULL
                                            GROUP BY au.id, au.nome ORDER BY valor DESC, au.nome' . self::limite($offset, $limite)));
    }

    /** Registros cadastrados no sistema ao longo do tempo (data de cadastro, NÃO de aquisição). */
    public function livrosCadastradosPorPeriodo(PeriodoRelatorio $periodo, string $agrupamento): array
    {
        [$c, $p] = $periodo->condicao('l.criado_em');
        $balde = PeriodoRelatorio::sqlBalde('l.criado_em', $agrupamento);
        return $this->mapa("SELECT $balde AS chave, COUNT(*) AS valor FROM livros l WHERE l.arquivado_em IS NULL AND $c GROUP BY chave", $p);
    }

    public function primeiroCadastroLivro(): ?string
    {
        $v = $this->valor('SELECT MIN(criado_em) FROM livros WHERE arquivado_em IS NULL');
        return $v ? (string)$v : null;
    }

    /** Anos de publicação existentes (para o filtro). */
    public function anosPublicacao(): array
    {
        return array_map('intval', array_column($this->todas('SELECT DISTINCT ano_publicacao AS a FROM livros WHERE arquivado_em IS NULL AND ano_publicacao IS NOT NULL ORDER BY a DESC LIMIT 400'), 'a'));
    }

    public function categorias(): array
    {
        return $this->todas('SELECT id, nome FROM categorias WHERE arquivado_em IS NULL ORDER BY nome');
    }

    // =================================================================
    // Exemplares físicos
    // =================================================================

    /** Contagens por situação dos exemplares de livros não arquivados (iguais às do dashboard). */
    public function resumoExemplares(): array
    {
        $partes = ['COUNT(*) AS cadastrados'];
        foreach (self::SITUACOES_EXEMPLAR as $s) {
            $partes[] = "COALESCE(SUM(e.situacao = '$s'), 0) AS $s";
        }
        $r = $this->linha('SELECT ' . implode(', ', $partes) . ",
                                  COALESCE(SUM(e.situacao NOT IN ('baixado', 'extraviado')), 0) AS no_acervo,
                                  COALESCE(SUM(e.localizacao_id IS NULL AND e.situacao NOT IN ('baixado', 'extraviado')), 0) AS sem_localizacao,
                                  COALESCE(SUM(e.conferido_em IS NULL AND e.situacao NOT IN ('baixado', 'extraviado')), 0) AS nunca_conferidos,
                                  COALESCE(SUM(e.conferido_em IS NOT NULL AND e.situacao NOT IN ('baixado', 'extraviado')), 0) AS conferidos
                           FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL") ?? [];
        return array_map('intval', $r);
    }

    public function exemplaresPorConservacao(): array
    {
        return $this->mapa("SELECT e.estado_conservacao AS chave, COUNT(*) AS valor FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                            WHERE e.situacao NOT IN ('baixado', 'extraviado') GROUP BY e.estado_conservacao");
    }

    /** Exemplares no acervo por setor (sem localização = "Sem localização definida"). */
    public function exemplaresPorSetor(): array
    {
        return self::inteiros($this->todas("SELECT COALESCE(lo.setor, 'Sem localização definida') AS rotulo, COUNT(*) AS valor
                                            FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                                            LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                                            WHERE e.situacao NOT IN ('baixado', 'extraviado')
                                            GROUP BY rotulo ORDER BY (lo.setor IS NULL), valor DESC, rotulo"));
    }

    public function contarLocalizacoesComExemplares(): int
    {
        return (int)$this->valor("SELECT COUNT(*) FROM (SELECT 1 FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                                  WHERE e.situacao NOT IN ('baixado', 'extraviado') GROUP BY e.localizacao_id) g");
    }

    /** Exemplares no acervo por estante/prateleira. */
    public function exemplaresPorLocalizacao(int $offset = 0, int $limite = 25): array
    {
        return $this->todas("SELECT lo.id, lo.setor, lo.estante, lo.prateleira, lo.complemento, lo.codigo, lo.ativa,
                                    COUNT(*) AS total,
                                    COALESCE(SUM(e.situacao = 'disponivel'), 0) AS disponiveis,
                                    COALESCE(SUM(e.situacao = 'emprestado'), 0) AS emprestados,
                                    COALESCE(SUM(e.situacao NOT IN ('disponivel', 'emprestado')), 0) AS outros
                             FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                             LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                             WHERE e.situacao NOT IN ('baixado', 'extraviado')
                             GROUP BY lo.id, lo.setor, lo.estante, lo.prateleira, lo.complemento, lo.codigo, lo.ativa
                             ORDER BY (lo.id IS NULL), lo.setor, lo.estante, lo.prateleira, lo.complemento" . self::limite($offset, $limite));
    }

    public function localizacoes(): array
    {
        return $this->todas('SELECT id, setor, estante, prateleira, complemento, codigo, ativa FROM localizacoes ORDER BY ativa DESC, setor, estante, prateleira, complemento');
    }

    /**
     * Filtros: codigo, q (título ou autor), situacao, localizacao (id | 'sem'), conservacao.
     * @return array{0: string, 1: list<mixed>}
     */
    private function filtrosExemplares(array $f): array
    {
        $w = ['l.arquivado_em IS NULL'];
        $p = [];
        $codigo = trim((string)($f['codigo'] ?? ''));
        if ($codigo !== '') {
            if ($normal = EmprestimoRepository::codigoExemplar($codigo)) {
                $w[] = 'e.codigo_individual = ?';
                $p[] = $normal;
            } else {
                $w[] = 'e.codigo_individual LIKE ?';
                $p[] = self::like($codigo);
            }
        }
        foreach (self::termos((string)($f['q'] ?? '')) as $t) {
            $w[] = '(l.titulo LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?))';
            array_push($p, self::like($t), self::like($t));
        }
        if (in_array($f['situacao'] ?? '', self::SITUACOES_EXEMPLAR, true)) {
            $w[] = 'e.situacao = ?';
            $p[] = $f['situacao'];
        } elseif (($f['situacao'] ?? '') === 'no_acervo') {
            $w[] = "e.situacao NOT IN ('baixado', 'extraviado')";
        }
        if (($f['localizacao'] ?? '') === 'sem') {
            $w[] = "e.localizacao_id IS NULL AND e.situacao NOT IN ('baixado', 'extraviado')";
        } elseif ((int)($f['localizacao'] ?? 0) > 0) {
            $w[] = 'e.localizacao_id = ?';
            $p[] = (int)$f['localizacao'];
        }
        if (in_array($f['conservacao'] ?? '', self::CONSERVACAO, true)) {
            $w[] = 'e.estado_conservacao = ?';
            $p[] = $f['conservacao'];
        }
        return [implode(' AND ', $w), $p];
    }

    public function contarExemplares(array $f): int
    {
        [$w, $p] = $this->filtrosExemplares($f);
        return (int)$this->valor("SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE $w", $p);
    }

    public function exemplares(array $f, int $offset = 0, int $limite = 25): array
    {
        [$w, $p] = $this->filtrosExemplares($f);
        return $this->todas('SELECT e.id, e.codigo_individual, e.situacao, e.estado_conservacao, e.criado_em, e.conferido_em, e.livro_id,
                                    l.titulo, l.isbn, ' . self::AUTORES . " AS autores,
                                    lo.setor, lo.estante, lo.prateleira, lo.complemento
                             FROM exemplares e JOIN livros l ON l.id = e.livro_id
                             LEFT JOIN localizacoes lo ON lo.id = e.localizacao_id
                             WHERE $w ORDER BY e.codigo_individual" . self::limite($offset, $limite), $p);
    }

    // =================================================================
    // Empréstimos e devoluções
    // =================================================================

    /**
     * Filtros comuns: livro (id), titulo (pesquisa), categoria (id), turma (texto exato).
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function filtrosCirculacao(array $f): array
    {
        $w = [];
        $p = [];
        if ((int)($f['livro'] ?? 0) > 0) {
            $w[] = 'e.livro_id = ?';
            $p[] = (int)$f['livro'];
        }
        foreach (self::termos((string)($f['titulo'] ?? '')) as $t) {
            $w[] = '(l.titulo LIKE ? OR e.codigo_individual LIKE ?)';
            array_push($p, self::like($t), self::like($t));
        }
        if ((int)($f['categoria'] ?? 0) > 0) {
            $w[] = 'EXISTS (SELECT 1 FROM livro_categorias lc WHERE lc.livro_id = e.livro_id AND lc.categoria_id = ?)';
            $p[] = (int)$f['categoria'];
        }
        if (is_string($f['turma'] ?? null) && $f['turma'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM alunos a WHERE a.id = m.aluno_id AND a.turma = ?)';
            $p[] = $f['turma'];
        }
        return [$w, $p];
    }

    private const JUNCAO_CIRCULACAO = ' FROM emprestimos m JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id ';

    /**
     * Situação atual (não depende do período): ativos, atrasados e total — os mesmos números do
     * dashboard quando não há filtros.
     */
    public function resumoCirculacao(string $hoje, array $f = []): array
    {
        [$w, $p] = $this->filtrosCirculacao($f);
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $r = $this->linha('SELECT COUNT(*) AS total,
                                  COALESCE(SUM(m.data_devolucao IS NULL), 0) AS ativos,
                                  COALESCE(SUM(m.data_devolucao IS NULL AND m.data_prevista < ?), 0) AS atrasados,
                                  COALESCE(SUM(m.data_devolucao IS NULL AND m.data_prevista = ?), 0) AS vencem_hoje,
                                  COALESCE(SUM(m.data_devolucao IS NOT NULL), 0) AS concluidos,
                                  COALESCE(SUM(m.renovacoes), 0) AS renovacoes' . self::JUNCAO_CIRCULACAO . $where, array_merge([$hoje, $hoje], $p)) ?? [];
        return array_map('intval', $r);
    }

    /** Números do período: empréstimos (pela retirada), devoluções (pela devolução) e renovações (pela data da renovação). */
    public function resumoCirculacaoPeriodo(PeriodoRelatorio $periodo, array $f = []): array
    {
        [$w, $p] = $this->filtrosCirculacao($f);
        $extra = $w ? ' AND ' . implode(' AND ', $w) : '';
        [$cR, $pR] = $periodo->condicao('m.data_emprestimo');
        [$cD, $pD] = $periodo->condicao('m.data_devolucao');
        $ret = $this->linha('SELECT COUNT(*) AS emprestimos, COALESCE(SUM(m.data_devolucao IS NOT NULL), 0) AS concluidos,
                                    COUNT(DISTINCT e.livro_id) AS livros' . self::JUNCAO_CIRCULACAO . "WHERE $cR$extra", array_merge($pR, $p)) ?? [];
        $dev = $this->linha('SELECT COUNT(*) AS devolucoes, COALESCE(SUM(DATE(m.data_devolucao) > m.data_prevista), 0) AS devolvidos_com_atraso'
            . self::JUNCAO_CIRCULACAO . "WHERE m.data_devolucao IS NOT NULL AND $cD$extra", array_merge($pD, $p)) ?? [];
        [$cRen, $pRen] = $periodo->condicao('r.criado_em');
        $ren = (int)$this->valor('SELECT COUNT(*) FROM emprestimo_renovacoes r JOIN emprestimos m ON m.id = r.emprestimo_id JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id'
            . " WHERE $cRen$extra", array_merge($pRen, $p));
        return [
            'emprestimos' => (int)($ret['emprestimos'] ?? 0),
            'concluidos' => (int)($ret['concluidos'] ?? 0),
            'livros' => (int)($ret['livros'] ?? 0),
            'devolucoes' => (int)($dev['devolucoes'] ?? 0),
            'devolvidos_com_atraso' => (int)($dev['devolvidos_com_atraso'] ?? 0),
            'renovacoes' => $ren,
        ];
    }

    /** @return array{0: string, 1: list<mixed>} WHERE da lista de empréstimos (período pela retirada + situação). */
    private function whereEmprestimos(PeriodoRelatorio $periodo, string $hoje, array $f): array
    {
        [$w, $p] = $this->filtrosCirculacao($f);
        [$c, $cp] = $periodo->condicao('m.data_emprestimo');
        array_unshift($w, $c);
        $p = array_merge($cp, $p);
        switch ($f['situacao'] ?? 'todos') {
            case 'ativos':
                $w[] = 'm.data_devolucao IS NULL';
                break;
            case 'atrasados':
                $w[] = 'm.data_devolucao IS NULL AND m.data_prevista < ?';
                $p[] = $hoje;
                break;
            case 'concluidos':
                $w[] = 'm.data_devolucao IS NOT NULL';
                break;
        }
        return [implode(' AND ', $w), $p];
    }

    public function contarEmprestimos(PeriodoRelatorio $periodo, string $hoje, array $f): int
    {
        [$w, $p] = $this->whereEmprestimos($periodo, $hoje, $f);
        return (int)$this->valor('SELECT COUNT(*)' . self::JUNCAO_CIRCULACAO . "WHERE $w", $p);
    }

    /** Lista de empréstimos. Dados do aluno só quando $comAluno (quem tem permissão). E-mail e telefone nunca. */
    public function emprestimos(PeriodoRelatorio $periodo, string $hoje, array $f, int $offset = 0, int $limite = 25, bool $comAluno = false): array
    {
        [$w, $p] = $this->whereEmprestimos($periodo, $hoje, $f);
        $aluno = $comAluno ? ', a.nome AS aluno_nome, a.matricula, a.turma' : '';
        $juncaoAluno = $comAluno ? ' JOIN alunos a ON a.id = m.aluno_id' : '';
        return $this->todas("SELECT m.id, m.data_emprestimo, m.data_prevista, m.data_devolucao, m.renovacoes, m.aluno_id,
                                    e.id AS exemplar_id, e.codigo_individual, l.id AS livro_id, l.titulo,
                                    GREATEST(0, DATEDIFF(COALESCE(DATE(m.data_devolucao), ?), m.data_prevista)) AS dias_atraso $aluno"
            . self::JUNCAO_CIRCULACAO . $juncaoAluno . " WHERE $w ORDER BY m.data_emprestimo DESC, m.id DESC" . self::limite($offset, $limite), array_merge([$hoje], $p));
    }

    /** Empréstimos (pela retirada) e devoluções (pela devolução) em cada dia/mês/ano do período. */
    public function serieCirculacao(PeriodoRelatorio $periodo, string $agrupamento, array $f = []): array
    {
        [$w, $p] = $this->filtrosCirculacao($f);
        $extra = $w ? ' AND ' . implode(' AND ', $w) : '';
        [$cR, $pR] = $periodo->condicao('m.data_emprestimo');
        [$cD, $pD] = $periodo->condicao('m.data_devolucao');
        $bR = PeriodoRelatorio::sqlBalde('m.data_emprestimo', $agrupamento);
        $bD = PeriodoRelatorio::sqlBalde('m.data_devolucao', $agrupamento);
        return [
            'emprestimos' => $this->mapa("SELECT $bR AS chave, COUNT(*) AS valor" . self::JUNCAO_CIRCULACAO . "WHERE $cR$extra GROUP BY chave", array_merge($pR, $p)),
            'devolucoes' => $this->mapa("SELECT $bD AS chave, COUNT(*) AS valor" . self::JUNCAO_CIRCULACAO . "WHERE m.data_devolucao IS NOT NULL AND $cD$extra GROUP BY chave", array_merge($pD, $p)),
        ];
    }

    public function primeiroEmprestimo(): ?string
    {
        $v = $this->valor('SELECT MIN(data_emprestimo) FROM emprestimos');
        return $v ? (string)$v : null;
    }

    public function contarLivrosEmprestados(PeriodoRelatorio $periodo, string $hoje, array $f): int
    {
        [$w, $p] = $this->whereEmprestimos($periodo, $hoje, $f);
        return (int)$this->valor('SELECT COUNT(DISTINCT e.livro_id)' . self::JUNCAO_CIRCULACAO . "WHERE $w", $p);
    }

    /** Livros mais emprestados no período (cada empréstimo conta uma vez, renovado ou não). */
    public function livrosMaisEmprestados(PeriodoRelatorio $periodo, string $hoje, array $f, int $offset = 0, int $limite = 10): array
    {
        [$w, $p] = $this->whereEmprestimos($periodo, $hoje, $f);
        return self::inteiros($this->todas('SELECT l.id AS livro_id, l.titulo AS rotulo, ' . self::AUTORES . ' AS autores, COUNT(*) AS valor,
                                                   COUNT(DISTINCT e.id) AS exemplares'
            . self::JUNCAO_CIRCULACAO . "WHERE $w GROUP BY l.id, l.titulo ORDER BY valor DESC, l.titulo" . self::limite($offset, $limite), $p), ['valor', 'exemplares']);
    }

    /** Empréstimos do período por categoria do livro ("Não informado" = livro sem categoria). */
    public function emprestimosPorCategoria(PeriodoRelatorio $periodo, string $hoje, array $f): array
    {
        [$w, $p] = $this->whereEmprestimos($periodo, $hoje, $f);
        $linhas = $this->todas('SELECT c.nome AS rotulo, COUNT(DISTINCT m.id) AS valor' . self::JUNCAO_CIRCULACAO . '
                                JOIN livro_categorias lc ON lc.livro_id = l.id JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL
                                WHERE ' . $w . ' GROUP BY c.id, c.nome ORDER BY valor DESC, c.nome', $p);
        $sem = (int)$this->valor('SELECT COUNT(*)' . self::JUNCAO_CIRCULACAO . 'WHERE ' . $w . ' AND ' . self::SEM_CATEGORIA, $p);
        if ($sem > 0) {
            $linhas[] = ['rotulo' => 'Não informado', 'valor' => $sem];
        }
        return self::inteiros($linhas);
    }

    /** Turmas existentes (filtro de turma), só nomes de turma. */
    public function turmas(): array
    {
        return array_column($this->todas("SELECT DISTINCT turma FROM alunos WHERE turma IS NOT NULL AND turma <> '' ORDER BY turma"), 'turma');
    }

    // =================================================================
    // Atrasos
    // =================================================================

    public function resumoAtrasos(string $hoje): array
    {
        $r = $this->linha('SELECT COUNT(*) AS atrasados,
                                  COALESCE(SUM(DATEDIFF(?, m.data_prevista)), 0) AS dias_total,
                                  COALESCE(MAX(DATEDIFF(?, m.data_prevista)), 0) AS maior_atraso,
                                  COALESCE(SUM(DATEDIFF(?, m.data_prevista) BETWEEN 1 AND 7), 0) AS faixa_1_7,
                                  COALESCE(SUM(DATEDIFF(?, m.data_prevista) BETWEEN 8 AND 15), 0) AS faixa_8_15,
                                  COALESCE(SUM(DATEDIFF(?, m.data_prevista) > 15), 0) AS faixa_16
                           FROM emprestimos m WHERE m.data_devolucao IS NULL AND m.data_prevista < ?', [$hoje, $hoje, $hoje, $hoje, $hoje, $hoje]) ?? [];
        return array_map('intval', $r);
    }

    /** Atrasos atuais pelo mês em que venceram. */
    public function atrasosPorMesVencimento(string $hoje): array
    {
        return $this->mapa("SELECT DATE_FORMAT(m.data_prevista, '%Y-%m') AS chave, COUNT(*) AS valor FROM emprestimos m
                            WHERE m.data_devolucao IS NULL AND m.data_prevista < ? GROUP BY chave ORDER BY chave", [$hoje]);
    }

    /** Faixa de atraso: '1_7' | '8_15' | '16' | '' (todos). */
    private static function condicaoFaixa(string $faixa): string
    {
        return match ($faixa) {
            '1_7' => ' AND DATEDIFF(?, m.data_prevista) BETWEEN 1 AND 7',
            '8_15' => ' AND DATEDIFF(?, m.data_prevista) BETWEEN 8 AND 15',
            '16' => ' AND DATEDIFF(?, m.data_prevista) > 15',
            default => '',
        };
    }

    public function contarAtrasados(string $hoje, string $faixa = ''): int
    {
        $c = self::condicaoFaixa($faixa);
        return (int)$this->valor('SELECT COUNT(*) FROM emprestimos m WHERE m.data_devolucao IS NULL AND m.data_prevista < ?' . $c, $c ? [$hoje, $hoje] : [$hoje]);
    }

    /** Atrasos atuais. Nome, matrícula e turma do aluno só com $comAluno (permissão conferida antes). */
    public function atrasados(string $hoje, string $faixa = '', int $offset = 0, int $limite = 25, bool $comAluno = false): array
    {
        $c = self::condicaoFaixa($faixa);
        $aluno = $comAluno ? ', a.nome AS aluno_nome, a.matricula, a.turma' : '';
        $juncaoAluno = $comAluno ? ' JOIN alunos a ON a.id = m.aluno_id' : '';
        return $this->todas("SELECT m.id, m.data_emprestimo, m.data_prevista, m.renovacoes, m.aluno_id, e.id AS exemplar_id, e.codigo_individual,
                                    l.id AS livro_id, l.titulo, DATEDIFF(?, m.data_prevista) AS dias_atraso $aluno"
            . self::JUNCAO_CIRCULACAO . $juncaoAluno . ' WHERE m.data_devolucao IS NULL AND m.data_prevista < ?' . $c
            . ' ORDER BY m.data_prevista, m.id' . self::limite($offset, $limite), $c ? [$hoje, $hoje, $hoje] : [$hoje, $hoje]);
    }

    /** Devoluções feitas depois do prazo, no período (pela data da devolução). Sem dados de alunos. */
    public function resumoDevolvidosComAtraso(PeriodoRelatorio $periodo): array
    {
        [$c, $p] = $periodo->condicao('m.data_devolucao');
        $r = $this->linha("SELECT COUNT(*) AS total, COALESCE(SUM(DATEDIFF(DATE(m.data_devolucao), m.data_prevista)), 0) AS dias_total,
                                  COALESCE(ROUND(AVG(DATEDIFF(DATE(m.data_devolucao), m.data_prevista)), 1), 0) AS media
                           FROM emprestimos m WHERE m.data_devolucao IS NOT NULL AND DATE(m.data_devolucao) > m.data_prevista AND $c", $p) ?? [];
        return ['total' => (int)($r['total'] ?? 0), 'dias_total' => (int)($r['dias_total'] ?? 0), 'media' => (float)($r['media'] ?? 0)];
    }

    public function contarDevolvidosComAtraso(PeriodoRelatorio $periodo): int
    {
        return $this->resumoDevolvidosComAtraso($periodo)['total'];
    }

    public function devolvidosComAtraso(PeriodoRelatorio $periodo, int $offset = 0, int $limite = 25): array
    {
        [$c, $p] = $periodo->condicao('m.data_devolucao');
        return $this->todas('SELECT m.id, m.data_emprestimo, m.data_prevista, m.data_devolucao, m.renovacoes, e.id AS exemplar_id, e.codigo_individual,
                                    l.id AS livro_id, l.titulo, DATEDIFF(DATE(m.data_devolucao), m.data_prevista) AS dias_atraso'
            . self::JUNCAO_CIRCULACAO . "WHERE m.data_devolucao IS NOT NULL AND DATE(m.data_devolucao) > m.data_prevista AND $c
                             ORDER BY m.data_devolucao DESC, m.id DESC" . self::limite($offset, $limite), $p);
    }

    // =================================================================
    // Alunos (números agregados; listas nominais só com permissão)
    // =================================================================

    public function resumoAlunos(string $hoje): array
    {
        $r = $this->linha("SELECT COUNT(*) AS total, COALESCE(SUM(ativo = 1), 0) AS ativos, COALESCE(SUM(ativo = 0), 0) AS inativos,
                                  COALESCE(SUM(turma IS NULL OR turma = ''), 0) AS sem_turma, COALESCE(SUM(turno IS NULL), 0) AS sem_turno
                           FROM alunos") ?? [];
        $r = array_map('intval', $r);
        $c = $this->linha('SELECT COUNT(DISTINCT m.aluno_id) AS com_emprestimo, COUNT(DISTINCT CASE WHEN m.data_prevista < ? THEN m.aluno_id END) AS com_atraso
                           FROM emprestimos m WHERE m.data_devolucao IS NULL', [$hoje]) ?? [];
        $r['com_emprestimo_ativo'] = (int)($c['com_emprestimo'] ?? 0);
        $r['com_atraso'] = (int)($c['com_atraso'] ?? 0);
        return $r;
    }

    /** Por turma: total, ativos, inativos e quantos têm empréstimo ativo (sem nomes). */
    public function alunosPorTurma(int $offset = 0, int $limite = 200): array
    {
        return self::inteiros($this->todas("SELECT COALESCE(NULLIF(a.turma, ''), 'Não informado') AS rotulo, COUNT(*) AS valor,
                                                   COALESCE(SUM(a.ativo = 1), 0) AS ativos, COALESCE(SUM(a.ativo = 0), 0) AS inativos,
                                                   COALESCE(SUM(EXISTS (SELECT 1 FROM emprestimos m WHERE m.aluno_id = a.id AND m.data_devolucao IS NULL)), 0) AS com_emprestimo
                                            FROM alunos a GROUP BY rotulo ORDER BY (rotulo = 'Não informado'), rotulo" . self::limite($offset, $limite)), ['valor', 'ativos', 'inativos', 'com_emprestimo']);
    }

    public function contarTurmas(): int
    {
        return (int)$this->valor("SELECT COUNT(DISTINCT COALESCE(NULLIF(turma, ''), '')) FROM alunos");
    }

    public function alunosPorTurno(): array
    {
        return $this->mapa("SELECT COALESCE(turno, 'nao_informado') AS chave, COUNT(*) AS valor FROM alunos GROUP BY chave");
    }

    public function contarAlunosComEmprestimo(string $turma = ''): int
    {
        $p = [];
        $c = '';
        if ($turma !== '') {
            $c = ' AND a.turma = ?';
            $p[] = $turma;
        }
        return (int)$this->valor("SELECT COUNT(DISTINCT m.aluno_id) FROM emprestimos m JOIN alunos a ON a.id = m.aluno_id WHERE m.data_devolucao IS NULL$c", $p);
    }

    /**
     * Alunos com empréstimo ativo, em ordem alfabética (não é ranking). Só para quem pode ver
     * dados pessoais: a permissão é conferida antes de chamar. Sem e-mail e telefone.
     */
    public function alunosComEmprestimo(string $hoje, string $turma = '', int $offset = 0, int $limite = 25): array
    {
        $p = [$hoje];
        $c = '';
        if ($turma !== '') {
            $c = ' AND a.turma = ?';
            $p[] = $turma;
        }
        return $this->todas("SELECT a.id AS aluno_id, a.nome, a.matricula, a.turma, a.turno, a.ativo,
                                    COUNT(*) AS ativos, COALESCE(SUM(m.data_prevista < ?), 0) AS atrasados, MIN(m.data_prevista) AS proxima_devolucao
                             FROM emprestimos m JOIN alunos a ON a.id = m.aluno_id
                             WHERE m.data_devolucao IS NULL$c
                             GROUP BY a.id, a.nome, a.matricula, a.turma, a.turno, a.ativo
                             ORDER BY a.nome, a.id" . self::limite($offset, $limite), $p);
    }

    // =================================================================
    // Inventários
    // =================================================================

    public function resumoInventarios(): array
    {
        $r = $this->linha("SELECT COUNT(*) AS total,
                                  COALESCE(SUM(status = 'aberto'), 0) AS aberto,
                                  COALESCE(SUM(status IN ('em_andamento', 'pausado')), 0) AS em_andamento,
                                  COALESCE(SUM(status = 'pausado'), 0) AS pausado,
                                  COALESCE(SUM(status = 'concluido'), 0) AS concluido,
                                  COALESCE(SUM(status = 'cancelado'), 0) AS cancelado
                           FROM inventarios") ?? [];
        return array_map('intval', $r);
    }

    public function contarInventarios(string $status = ''): int
    {
        [$w, $p] = self::whereInventario($status);
        return (int)$this->valor("SELECT COUNT(*) FROM inventarios v WHERE $w", $p);
    }

    private static function whereInventario(string $status): array
    {
        return match ($status) {
            'aberto', 'concluido', 'cancelado' => ['v.status = ?', [$status]],
            'em_andamento' => ["v.status IN ('em_andamento', 'pausado')", []],
            'realizados' => ["v.status <> 'cancelado'", []],
            default => ['1 = 1', []],
        };
    }

    /**
     * Inventários com os números da conferência numa só consulta (mesmas regras de
     * InventarioRepository::resumo: previstos, conferidos dos previstos, não conferidos fora
     * de circulação, emprestados, divergências, cadastrados durante o inventário).
     */
    public function inventarios(string $status = '', int $offset = 0, int $limite = 25): array
    {
        [$w, $p] = self::whereInventario($status);
        return $this->todas("SELECT v.id, v.titulo, v.status, v.setor, v.data_inicio, v.iniciado_em, v.em_andamento_desde, v.concluido_em, r.nome AS responsavel_nome,
                                    COALESCE(SUM(i.previsto), 0) AS previstos,
                                    COALESCE(SUM(i.conferido_em IS NOT NULL), 0) AS conferidos,
                                    COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NOT NULL), 0) AS previstos_conferidos,
                                    COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND m.id IS NULL), 0) AS nao_conferidos,
                                    COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND m.id IS NOT NULL), 0) AS em_circulacao,
                                    COALESCE(SUM(i.divergencia_local), 0) AS local_diferente,
                                    COALESCE(SUM(i.divergencia_estado), 0) AS estado_divergente,
                                    COALESCE(SUM(i.divergencia_situacao), 0) AS situacao_divergente,
                                    COALESCE(SUM(i.cadastrado_no_inventario), 0) AS cadastrados,
                                    COALESCE(SUM(i.conferido_em IS NOT NULL AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao) AND i.decisao IS NULL), 0)
                                      + COALESCE(SUM(i.previsto = 1 AND i.conferido_em IS NULL AND i.decisao IS NULL AND m.id IS NULL), 0) AS pendencias
                             FROM inventarios v
                             LEFT JOIN administradores r ON r.id = v.responsavel_id
                             LEFT JOIN inventario_itens i ON i.inventario_id = v.id
                             LEFT JOIN emprestimos m ON m.exemplar_id = i.exemplar_id AND m.data_devolucao IS NULL
                             WHERE $w
                             GROUP BY v.id, v.titulo, v.status, v.setor, v.data_inicio, v.iniciado_em, v.em_andamento_desde, v.concluido_em, r.nome
                             ORDER BY FIELD(v.status, 'em_andamento', 'pausado', 'aberto', 'concluido', 'cancelado'), v.iniciado_em DESC, v.id DESC" . self::limite($offset, $limite), $p);
    }

    /** Inventário mostrado por padrão: o ativo; senão o último concluído; senão o mais recente. */
    public function inventarioPadrao(): ?int
    {
        $id = $this->valor("SELECT id FROM inventarios ORDER BY FIELD(status, 'em_andamento', 'pausado', 'concluido', 'aberto', 'cancelado'), iniciado_em DESC, id DESC LIMIT 1");
        return $id ? (int)$id : null;
    }

    // =================================================================
    // Classificação CDD
    // =================================================================

    private const CDD_ANTIGA = "(NULLIF(TRIM(c.cdd_original), '') IS NOT NULL OR EXISTS (SELECT 1 FROM registros_legados rl WHERE rl.livro_id = l.id AND NULLIF(TRIM(rl.cdd_original), '') IS NOT NULL))";

    public function resumoCdd(): array
    {
        $r = $this->linha("SELECT COUNT(*) AS total,
                                  COALESCE(SUM(c.status = 'aprovada'), 0) AS aprovadas,
                                  COALESCE(SUM(c.status IS NULL OR c.status = 'pendente'), 0) AS pendentes,
                                  COALESCE(SUM(c.status = 'sugerida'), 0) AS sugeridas,
                                  COALESCE(SUM(c.status = 'em_revisao'), 0) AS em_revisao,
                                  COALESCE(SUM(c.status = 'conflito'), 0) AS conflitos,
                                  COALESCE(SUM(c.precisa_revisao = 1 AND c.status <> 'aprovada'), 0) AS precisa_revisao,
                                  COALESCE(SUM((c.status IS NULL OR c.status <> 'aprovada') AND " . self::CDD_ANTIGA . "), 0) AS com_cdd_antiga,
                                  COALESCE(SUM(c.cdd_aprovada IS NOT NULL AND c.status <> 'aprovada'), 0) AS aprovada_em_reanalise
                           FROM livros l LEFT JOIN classificacoes_cdd c ON c.livro_id = l.id
                           WHERE l.arquivado_em IS NULL") ?? [];
        $r = array_map('intval', $r);
        // Aguardando reclassificação = tudo que não está aprovado (mesma conta do dashboard).
        $r['sem_aprovacao'] = $r['total'] - $r['aprovadas'];
        return $r;
    }

    /** Livros com CDD aprovada (formato válido) por classe principal (0 a 9). */
    public function cddPorClasse(): array
    {
        $mapa = $this->mapa("SELECT LEFT(c.cdd_aprovada, 1) AS chave, COUNT(*) AS valor FROM classificacoes_cdd c
                             JOIN livros l ON l.id = c.livro_id AND l.arquivado_em IS NULL
                             WHERE c.status = 'aprovada' AND c.cdd_aprovada REGEXP '^[0-9]{3}(\\\\.[0-9]+)?$' GROUP BY chave");
        $saida = [];
        for ($i = 0; $i <= 9; $i++) {
            $saida[(string)$i] = $mapa[(string)$i] ?? 0;
        }
        return $saida;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtrosCdd(array $f): array
    {
        $w = ['l.arquivado_em IS NULL'];
        $p = [];
        $w[] = match ($f['status'] ?? '') {
            'aprovada' => "c.status = 'aprovada'",
            'pendente' => "(c.status IS NULL OR c.status = 'pendente')",
            'sugerida' => "c.status = 'sugerida'",
            'em_revisao' => "(c.status = 'em_revisao' OR (c.precisa_revisao = 1 AND c.status <> 'aprovada'))",
            'conflito' => "c.status = 'conflito'",
            'antiga' => "(c.status IS NULL OR c.status <> 'aprovada') AND " . self::CDD_ANTIGA,
            'sem_aprovacao' => "(c.status IS NULL OR c.status <> 'aprovada')",
            default => '1 = 1',
        };
        if (preg_match('/^[0-9]$/', (string)($f['classe'] ?? ''))) {
            $w[] = "c.status = 'aprovada' AND c.cdd_aprovada LIKE ?";
            $p[] = $f['classe'] . '%';
        }
        foreach (self::termos((string)($f['q'] ?? '')) as $t) {
            $w[] = '(l.titulo LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id AND au.nome LIKE ?))';
            array_push($p, self::like($t), self::like($t));
        }
        return [implode(' AND ', $w), $p];
    }

    public function contarCdd(array $f): int
    {
        [$w, $p] = $this->filtrosCdd($f);
        return (int)$this->valor("SELECT COUNT(*) FROM livros l LEFT JOIN classificacoes_cdd c ON c.livro_id = l.id WHERE $w", $p);
    }

    public function livrosCdd(array $f, int $offset = 0, int $limite = 25): array
    {
        [$w, $p] = $this->filtrosCdd($f);
        return $this->todas('SELECT l.id, l.titulo, ' . self::AUTORES . " AS autores, c.status, c.precisa_revisao, c.cdd_proposta, c.cdd_aprovada, c.aprovado_em,
                                    COALESCE(NULLIF(TRIM(c.cdd_original), ''), (SELECT rl.cdd_original FROM registros_legados rl WHERE rl.livro_id = l.id AND NULLIF(TRIM(rl.cdd_original), '') IS NOT NULL ORDER BY rl.id LIMIT 1)) AS cdd_antiga
                             FROM livros l LEFT JOIN classificacoes_cdd c ON c.livro_id = l.id
                             WHERE $w ORDER BY l.titulo, l.id" . self::limite($offset, $limite), $p);
    }

    // =================================================================
    // Atividades administrativas (acoes_administrativas, só leitura)
    // =================================================================

    /**
     * Filtros: periodo, modulo (prefixo da ação, ex.: "livro"), usuario (id), ocultar (lista de
     * prefixos que o perfil não pode ver, ex.: ["usuario"]).
     * @return array{0: string, 1: list<mixed>}
     */
    private function filtrosAtividades(array $f): array
    {
        $w = [];
        $p = [];
        if (($f['periodo'] ?? null) instanceof PeriodoRelatorio) {
            [$c, $cp] = $f['periodo']->condicao('a.criado_em');
            $w[] = $c;
            array_push($p, ...$cp);
        }
        $modulos = (array)($f['modulos'] ?? []);
        if ($modulos) {
            $w[] = '(' . implode(' OR ', array_fill(0, count($modulos), 'a.acao LIKE ?')) . ')';
            foreach ($modulos as $m) {
                $p[] = addcslashes((string)$m, '%_\\') . '.%';
            }
        }
        foreach ((array)($f['ocultar'] ?? []) as $o) {
            $w[] = 'a.acao NOT LIKE ?';
            $p[] = addcslashes((string)$o, '%_\\') . '.%';
        }
        if ((int)($f['usuario'] ?? 0) > 0) {
            $w[] = 'a.usuario_id = ?';
            $p[] = (int)$f['usuario'];
        }
        return [$w ? implode(' AND ', $w) : '1 = 1', $p];
    }

    public function contarAtividades(array $f): int
    {
        [$w, $p] = $this->filtrosAtividades($f);
        return (int)$this->valor("SELECT COUNT(*) FROM acoes_administrativas a WHERE $w", $p);
    }

    public function atividades(array $f, int $offset = 0, int $limite = 25): array
    {
        [$w, $p] = $this->filtrosAtividades($f);
        return $this->todas("SELECT a.id, a.acao, a.entidade, a.entidade_id, a.detalhes, a.criado_em, u.nome AS usuario_nome
                             FROM acoes_administrativas a LEFT JOIN administradores u ON u.id = a.usuario_id
                             WHERE $w ORDER BY a.criado_em DESC, a.id DESC" . self::limite($offset, $limite), $p);
    }

    /** Ações por módulo (prefixo antes do ponto). */
    public function atividadesPorModulo(array $f): array
    {
        [$w, $p] = $this->filtrosAtividades($f);
        return $this->mapa("SELECT SUBSTRING_INDEX(a.acao, '.', 1) AS chave, COUNT(*) AS valor FROM acoes_administrativas a WHERE $w GROUP BY chave ORDER BY valor DESC", $p);
    }

    /** Contas que já registraram ações (filtro por responsável): só id e nome. */
    public function responsaveisAtividades(): array
    {
        return $this->todas('SELECT DISTINCT u.id, u.nome FROM administradores u JOIN acoes_administrativas a ON a.usuario_id = u.id ORDER BY u.nome');
    }

    // =================================================================

    /** @return array<string, int> chave => valor */
    private function mapa(string $sql, array $params = []): array
    {
        $saida = [];
        foreach ($this->todas($sql, $params) as $r) {
            $saida[(string)$r['chave']] = (int)$r['valor'];
        }
        return $saida;
    }

    private static function inteiros(array $linhas, array $campos = ['valor']): array
    {
        foreach ($linhas as &$l) {
            foreach ($campos as $c) {
                $l[$c] = (int)$l[$c];
            }
        }
        return $linhas;
    }
}
