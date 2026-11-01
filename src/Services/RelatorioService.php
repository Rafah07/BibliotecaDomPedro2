<?php

namespace Biblioteca\Services;

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\RelatorioRepository;
use Biblioteca\Support\PeriodoRelatorio;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;

/**
 * Central de relatórios: o que cada seção mostra (indicadores, gráficos e tabelas) e como as
 * linhas são formatadas. A mesma definição alimenta a tela, o PDF, o CSV e o XLSX, então os
 * números e os filtros são sempre os mesmos nos quatro lugares.
 *
 * Contexto ($ctx) recebido por todos os métodos:
 *   periodo   PeriodoRelatorio já validado
 *   hoje      data de hoje na escola (Y-m-d)
 *   f         filtros da seção (já limpos pela página)
 *   pessoais  true quando o usuário pode ver nome, matrícula e turma dos alunos
 *   ocultar   prefixos de ações administrativas que o perfil não pode ver
 *
 * Privacidade: e-mail e telefone de alunos nunca aparecem. Nome, matrícula e turma só entram
 * nas tabelas marcadas como pessoais (ou nas colunas pessoais) e só quando $ctx['pessoais'].
 * Não existe nenhum ranking de alunos.
 */
final class RelatorioService
{
    public const SECOES = [
        'visao' => ['titulo' => 'Visão geral', 'icone' => 'chart-column', 'descricao' => 'Resumo do acervo, da circulação, do inventário e da classificação.'],
        'acervo' => ['titulo' => 'Acervo', 'icone' => 'library-big', 'descricao' => 'Registros bibliográficos: dados completos, categorias, assuntos, autores e duplicidades.'],
        'exemplares' => ['titulo' => 'Exemplares físicos', 'icone' => 'book-copy', 'descricao' => 'Unidades com código BDP: situação, conservação e localização.'],
        'emprestimos' => ['titulo' => 'Empréstimos e devoluções', 'icone' => 'book-open-check', 'descricao' => 'Circulação por período, livros e categorias mais emprestados.'],
        'atrasos' => ['titulo' => 'Atrasos', 'icone' => 'clock-alert', 'descricao' => 'Empréstimos vencidos, faixas de atraso e devoluções após o prazo.'],
        'alunos' => ['titulo' => 'Alunos', 'icone' => 'graduation-cap', 'descricao' => 'Números agregados por turma e turno; listas nominais só com permissão.'],
        'inventarios' => ['titulo' => 'Inventários', 'icone' => 'clipboard-check', 'descricao' => 'Progresso das conferências, divergências e pendências.'],
        'cdd' => ['titulo' => 'Classificação CDD', 'icone' => 'list-tree', 'descricao' => 'Classificações aprovadas, pendentes e distribuição pelas classes principais.'],
        'atividades' => ['titulo' => 'Atividades administrativas', 'icone' => 'history', 'descricao' => 'Registro das ações feitas no painel (somente consulta).'],
    ];

    /** Seções em que o filtro de período se aplica (as demais mostram a situação atual). */
    public const SECOES_COM_PERIODO = ['acervo', 'emprestimos', 'atrasos', 'atividades'];

    public const CLASSES_CDD = [
        '0' => 'Computação, informação e obras gerais', '1' => 'Filosofia e psicologia', '2' => 'Religião', '3' => 'Ciências sociais',
        '4' => 'Linguagem', '5' => 'Ciências naturais e matemática', '6' => 'Tecnologia', '7' => 'Artes e recreação', '8' => 'Literatura', '9' => 'História e geografia',
    ];

    public const STATUS_CDD = [
        'pendente' => 'Aguardando classificação', 'sugerida' => 'Sugestão aguardando validação', 'em_revisao' => 'Aguardando revisão',
        'aprovada' => 'Aprovada', 'conflito' => 'Conflito entre fontes',
    ];

    public const STATUS_LIVRO = [
        'validado' => 'Validado', 'aguardando_conferencia' => 'Aguardando conferência', 'dados_incompletos' => 'Dados incompletos', 'com_divergencia' => 'Com divergência',
    ];

    public const PENDENCIAS_LIVRO = [
        'com_isbn' => 'Com ISBN', 'sem_isbn' => 'Sem ISBN', 'sem_autor' => 'Sem autor identificado', 'sem_editora' => 'Sem editora',
        'sem_ano' => 'Sem ano de publicação', 'incompletos' => 'Informações incompletas', 'sem_categoria' => 'Sem categoria',
        'sem_exemplar' => 'Sem exemplar físico cadastrado', 'duplicidade' => 'Possíveis duplicidades',
    ];

    public const SITUACOES_EMPRESTIMO = ['todos' => 'Todos', 'ativos' => 'Ativos', 'atrasados' => 'Atrasados', 'concluidos' => 'Concluídos (devolvidos)'];
    public const FAIXAS_ATRASO = ['1_7' => '1 a 7 dias', '8_15' => '8 a 15 dias', '16' => 'Mais de 15 dias'];
    public const TURNOS = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite', 'integral' => 'Integral', 'nao_informado' => 'Não informado'];
    public const STATUS_INVENTARIO = ['aberto' => 'Em preparação', 'em_andamento' => 'Em andamento', 'pausado' => 'Pausado', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'];

    public const MODULOS_ATIVIDADE = [
        'livro' => 'Acervo (livros)', 'exemplar' => 'Exemplares', 'localizacao' => 'Localizações', 'emprestimo' => 'Empréstimos e renovações',
        'devolucao' => 'Devoluções', 'inventario' => 'Inventários', 'etiqueta' => 'Etiquetas', 'importacao' => 'Importações', 'aluno' => 'Alunos',
        'relatorio' => 'Exportações de relatórios', 'site' => 'Site público', 'usuario' => 'Contas de acesso',
    ];

    /** Chaves de detalhes que podem aparecer no resumo das atividades (nada de e-mail, IP ou valores internos). */
    private const DETALHES_PERMITIDOS = ['titulo' => '', 'codigo' => 'código', 'formato' => 'formato', 'filtro' => 'filtro', 'relatorio' => 'relatório',
        'linhas' => 'linhas', 'quantidade' => 'quantidade', 'previstos' => 'previstos', 'itens' => 'itens', 'decisao' => 'decisão', 'nome' => 'nome', 'setor' => 'setor', 'motivo' => 'motivo'];

    /** Linhas máximas: PDF (legibilidade) e planilhas (memória/tempo). */
    public const LIMITE_PDF = 2000;
    public const LIMITE_PLANILHA = 100000;

    public function __construct(
        private RelatorioRepository $repo,
        private InventarioRepository $inventarios,
        /** @var array<string, string> rótulos das ações (DASHBOARD_ACOES) */
        private array $rotulosAcoes = [],
    ) {
    }

    // =================================================================
    // Tabelas
    // =================================================================

    /**
     * Definição das tabelas. Cada uma: secao, titulo, colunas (chave => rótulo), tipos
     * (texto | codigo | numero), pessoal (a tabela inteira tem dados de alunos) e
     * colunas_pessoais (acrescentadas só para quem pode ver dados pessoais).
     */
    public static function tabelas(): array
    {
        return [
            'livros' => ['secao' => 'acervo', 'titulo' => 'Registros bibliográficos', 'colunas' => ['id' => 'ID', 'titulo' => 'Título', 'autores' => 'Autor(es)', 'categorias' => 'Categoria',
                'editora' => 'Editora', 'ano' => 'Ano', 'isbn' => 'ISBN', 'exemplares' => 'Exemplares físicos', 'historica' => 'Quantidade na planilha (histórica)', 'status' => 'Situação do registro'],
                'tipos' => ['id' => 'numero', 'isbn' => 'codigo', 'exemplares' => 'numero', 'ano' => 'codigo']],
            'duplicidades' => ['secao' => 'acervo', 'titulo' => 'Possíveis duplicidades (mesmo título e mesmo autor principal)', 'colunas' => ['titulo' => 'Título', 'autores' => 'Autor(es)',
                'registros' => 'Registros', 'ids' => 'IDs dos registros', 'editoras' => 'Editoras', 'anos' => 'Anos'], 'tipos' => ['registros' => 'numero']],
            'categorias' => ['secao' => 'acervo', 'titulo' => 'Registros por categoria', 'colunas' => ['rotulo' => 'Categoria', 'valor' => 'Registros'], 'tipos' => ['valor' => 'numero']],
            'assuntos' => ['secao' => 'acervo', 'titulo' => 'Registros por assunto', 'colunas' => ['rotulo' => 'Assunto', 'valor' => 'Registros'], 'tipos' => ['valor' => 'numero']],
            'autores' => ['secao' => 'acervo', 'titulo' => 'Autores com mais obras cadastradas', 'colunas' => ['rotulo' => 'Autor', 'valor' => 'Obras (registros)'], 'tipos' => ['valor' => 'numero']],
            'cadastros' => ['secao' => 'acervo', 'titulo' => 'Registros cadastrados no sistema ao longo do tempo', 'colunas' => ['rotulo' => 'Período', 'valor' => 'Registros cadastrados'], 'tipos' => ['valor' => 'numero']],

            'exemplares' => ['secao' => 'exemplares', 'titulo' => 'Exemplares físicos', 'colunas' => ['codigo' => 'Código BDP', 'titulo' => 'Título', 'autores' => 'Autor(es)', 'situacao' => 'Situação',
                'conservacao' => 'Conservação', 'localizacao' => 'Localização', 'conferido' => 'Última conferência', 'cadastrado' => 'Cadastrado em'], 'tipos' => ['codigo' => 'codigo']],
            'localizacoes' => ['secao' => 'exemplares', 'titulo' => 'Exemplares por estante, setor e prateleira', 'colunas' => ['localizacao' => 'Localização', 'codigo' => 'Código da localização',
                'total' => 'Exemplares', 'disponiveis' => 'Disponíveis', 'emprestados' => 'Emprestados', 'outros' => 'Outras situações'],
                'tipos' => ['codigo' => 'codigo', 'total' => 'numero', 'disponiveis' => 'numero', 'emprestados' => 'numero', 'outros' => 'numero']],

            'emprestimos' => ['secao' => 'emprestimos', 'titulo' => 'Empréstimos do período (pela data de retirada)', 'colunas' => ['id' => 'Nº', 'retirada' => 'Retirada', 'prevista' => 'Devolução prevista',
                'devolucao' => 'Devolvido em', 'situacao' => 'Situação', 'renovacoes' => 'Renovações', 'dias_atraso' => 'Dias de atraso', 'codigo' => 'Exemplar', 'titulo' => 'Título'],
                'colunas_pessoais' => ['aluno' => 'Aluno', 'matricula' => 'Matrícula', 'turma' => 'Turma'],
                'tipos' => ['id' => 'numero', 'renovacoes' => 'numero', 'dias_atraso' => 'numero', 'codigo' => 'codigo', 'matricula' => 'codigo']],
            'mais_emprestados' => ['secao' => 'emprestimos', 'titulo' => 'Livros mais emprestados no período', 'colunas' => ['rotulo' => 'Título', 'autores' => 'Autor(es)', 'valor' => 'Empréstimos', 'exemplares' => 'Exemplares diferentes'],
                'tipos' => ['valor' => 'numero', 'exemplares' => 'numero']],
            'emprestimos_categorias' => ['secao' => 'emprestimos', 'titulo' => 'Empréstimos do período por categoria', 'colunas' => ['rotulo' => 'Categoria', 'valor' => 'Empréstimos'], 'tipos' => ['valor' => 'numero']],
            'circulacao' => ['secao' => 'emprestimos', 'titulo' => 'Evolução da circulação', 'colunas' => ['rotulo' => 'Período', 'emprestimos' => 'Empréstimos (retiradas)', 'devolucoes' => 'Devoluções'],
                'tipos' => ['emprestimos' => 'numero', 'devolucoes' => 'numero']],

            'atrasos' => ['secao' => 'atrasos', 'titulo' => 'Empréstimos atrasados (situação atual)', 'colunas' => ['id' => 'Nº', 'titulo' => 'Título', 'codigo' => 'Exemplar', 'retirada' => 'Retirada',
                'prevista' => 'Devolução prevista', 'dias_atraso' => 'Dias de atraso', 'renovacoes' => 'Renovações'],
                'colunas_pessoais' => ['aluno' => 'Aluno', 'matricula' => 'Matrícula', 'turma' => 'Turma'],
                'tipos' => ['id' => 'numero', 'dias_atraso' => 'numero', 'renovacoes' => 'numero', 'codigo' => 'codigo', 'matricula' => 'codigo']],
            'faixas_atraso' => ['secao' => 'atrasos', 'titulo' => 'Atrasos por faixa de dias', 'colunas' => ['rotulo' => 'Faixa', 'valor' => 'Empréstimos'], 'tipos' => ['valor' => 'numero']],
            'devolvidos_atraso' => ['secao' => 'atrasos', 'titulo' => 'Devoluções feitas após o prazo (pela data da devolução)', 'colunas' => ['id' => 'Nº', 'titulo' => 'Título', 'codigo' => 'Exemplar',
                'retirada' => 'Retirada', 'prevista' => 'Devolução prevista', 'devolucao' => 'Devolvido em', 'dias_atraso' => 'Dias de atraso'],
                'tipos' => ['id' => 'numero', 'dias_atraso' => 'numero', 'codigo' => 'codigo']],

            'turmas' => ['secao' => 'alunos', 'titulo' => 'Alunos por turma', 'colunas' => ['rotulo' => 'Turma', 'valor' => 'Alunos', 'ativos' => 'Ativos', 'inativos' => 'Inativos', 'com_emprestimo' => 'Com empréstimo ativo'],
                'tipos' => ['valor' => 'numero', 'ativos' => 'numero', 'inativos' => 'numero', 'com_emprestimo' => 'numero']],
            'turnos' => ['secao' => 'alunos', 'titulo' => 'Alunos por turno', 'colunas' => ['rotulo' => 'Turno', 'valor' => 'Alunos'], 'tipos' => ['valor' => 'numero']],
            'alunos_emprestimo' => ['secao' => 'alunos', 'titulo' => 'Alunos com empréstimos ativos (ordem alfabética)', 'pessoal' => true, 'colunas' => ['aluno' => 'Aluno', 'matricula' => 'Matrícula',
                'turma' => 'Turma', 'turno' => 'Turno', 'ativos' => 'Empréstimos ativos', 'atrasados' => 'Atrasados', 'proxima' => 'Próxima devolução prevista'],
                'tipos' => ['matricula' => 'codigo', 'ativos' => 'numero', 'atrasados' => 'numero']],

            'inventarios' => ['secao' => 'inventarios', 'titulo' => 'Inventários', 'colunas' => ['id' => 'Nº', 'titulo' => 'Inventário', 'status' => 'Situação', 'setor' => 'Abrangência',
                'responsavel' => 'Responsável', 'inicio' => 'Início', 'conclusao' => 'Conclusão', 'progresso' => 'Progresso', 'previstos' => 'Previstos', 'conferidos' => 'Previstos conferidos',
                'nao_conferidos' => 'Não conferidos', 'em_circulacao' => 'Emprestados (fora da estante)', 'local' => 'Divergências de localização', 'estado' => 'Divergências de conservação',
                'situacao_div' => 'Divergências de situação', 'cadastrados' => 'Cadastrados no inventário', 'pendencias' => 'Pendências de decisão'],
                'tipos' => ['id' => 'numero', 'previstos' => 'numero', 'conferidos' => 'numero', 'nao_conferidos' => 'numero', 'em_circulacao' => 'numero', 'local' => 'numero', 'estado' => 'numero',
                    'situacao_div' => 'numero', 'cadastrados' => 'numero', 'pendencias' => 'numero']],

            'cdd' => ['secao' => 'cdd', 'titulo' => 'Classificação CDD dos registros', 'colunas' => ['id' => 'ID', 'titulo' => 'Título', 'autores' => 'Autor(es)', 'antiga' => 'CDD antiga (planilha, não validada)',
                'proposta' => 'CDD proposta', 'aprovada' => 'CDD aprovada', 'situacao' => 'Situação', 'aprovada_em' => 'Aprovada em'],
                'tipos' => ['id' => 'numero', 'antiga' => 'codigo', 'proposta' => 'codigo', 'aprovada' => 'codigo']],
            'classes_cdd' => ['secao' => 'cdd', 'titulo' => 'Registros com CDD aprovada por classe principal', 'colunas' => ['classe' => 'Classe', 'rotulo' => 'Descrição', 'valor' => 'Registros'],
                'tipos' => ['classe' => 'codigo', 'valor' => 'numero']],

            'atividades' => ['secao' => 'atividades', 'titulo' => 'Atividades administrativas', 'colunas' => ['data' => 'Data e hora', 'responsavel' => 'Responsável', 'acao' => 'Ação',
                'referencia' => 'Registro', 'resumo' => 'Resumo']],
        ];
    }

    public static function tabela(string $chave): array
    {
        $t = self::tabelas()[$chave] ?? null;
        if ($t === null) {
            throw new InvalidArgumentException('Relatório desconhecido.');
        }
        return $t;
    }

    /** Colunas visíveis para este contexto (as pessoais só com permissão). */
    public static function colunas(string $chave, array $ctx): array
    {
        $t = self::tabela($chave);
        return $t['colunas'] + (!empty($ctx['pessoais']) ? ($t['colunas_pessoais'] ?? []) : []);
    }

    /** A exportação desta tabela, neste contexto, contém dados pessoais de alunos? */
    public static function temDadosPessoais(string $chave, array $ctx): bool
    {
        $t = self::tabela($chave);
        return !empty($t['pessoal']) || (!empty($ctx['pessoais']) && !empty($t['colunas_pessoais']));
    }

    public function contar(string $chave, array $ctx): int
    {
        $f = $ctx['f'] ?? [];
        $p = $ctx['periodo'];
        return match ($chave) {
            'livros' => $this->repo->contarLivros($f + ['periodo' => $this->periodoAcervo($ctx)]),
            'duplicidades' => $this->repo->contarGruposDuplicidade(),
            'categorias' => count($this->repo->livrosPorCategoria()),
            'assuntos' => $this->repo->contarAssuntos() + count($this->repo->livrosPorAssunto(0)),
            'autores' => $this->repo->contarAutores(),
            'cadastros' => count($this->serieCadastros($ctx)),
            'exemplares' => $this->repo->contarExemplares($f),
            'localizacoes' => $this->repo->contarLocalizacoesComExemplares(),
            'emprestimos' => $this->repo->contarEmprestimos($p, $ctx['hoje'], $f),
            'mais_emprestados' => $this->repo->contarLivrosEmprestados($p, $ctx['hoje'], $f),
            'emprestimos_categorias' => count($this->repo->emprestimosPorCategoria($p, $ctx['hoje'], $f)),
            'circulacao' => count($this->serieCirculacao($ctx)),
            'atrasos' => $this->repo->contarAtrasados($ctx['hoje'], (string)($f['faixa'] ?? '')),
            'faixas_atraso' => 3,
            'devolvidos_atraso' => $this->repo->contarDevolvidosComAtraso($p),
            'turmas' => $this->repo->contarTurmas(),
            'turnos' => count($this->repo->alunosPorTurno()),
            'alunos_emprestimo' => empty($ctx['pessoais']) ? 0 : $this->repo->contarAlunosComEmprestimo((string)($f['turma'] ?? '')),
            'inventarios' => $this->repo->contarInventarios((string)($f['status'] ?? '')),
            'cdd' => $this->repo->contarCdd($f),
            'classes_cdd' => 10,
            'atividades' => $this->repo->contarAtividades($this->filtrosAtividades($ctx)),
            default => throw new InvalidArgumentException('Relatório desconhecido.'),
        };
    }

    /** Linhas formatadas (texto pronto para tela e arquivos). Chaves com "_" são links internos. */
    public function linhas(string $chave, array $ctx, int $offset = 0, int $limite = 25): array
    {
        $f = $ctx['f'] ?? [];
        $p = $ctx['periodo'];
        $hoje = $ctx['hoje'];
        $pessoais = !empty($ctx['pessoais']);
        $fatia = fn (array $todas) => array_slice($todas, $offset, $limite);
        return match ($chave) {
            'livros' => array_map([$this, 'linhaLivro'], $this->repo->livros($f + ['periodo' => $this->periodoAcervo($ctx)], $offset, $limite)),
            'duplicidades' => array_map(fn ($g) => ['titulo' => $g['titulo'], 'autores' => self::ni($g['autores']), 'registros' => (int)$g['registros'], 'ids' => $g['ids'],
                'editoras' => $g['editoras'], 'anos' => $g['anos'], '_livro_id' => (int)$g['livro_id']], $this->repo->gruposDuplicidade($offset, $limite)),
            'categorias' => $fatia($this->repo->livrosPorCategoria()),
            'assuntos' => $this->linhasAssuntos($offset, $limite),
            'autores' => $this->repo->autores($offset, $limite),
            'cadastros' => $fatia($this->serieCadastros($ctx)),
            'exemplares' => array_map(fn ($e) => [
                'codigo' => $e['codigo_individual'], 'titulo' => $e['titulo'], 'autores' => self::ni($e['autores']), 'situacao' => ExemplarService::rotuloSituacao($e['situacao']),
                'conservacao' => ExemplarService::rotuloConservacao($e['estado_conservacao']), 'localizacao' => self::local($e), 'conferido' => self::data($e['conferido_em'], true, 'Nunca conferido'),
                'cadastrado' => self::data($e['criado_em']), '_exemplar_id' => (int)$e['id'], '_livro_id' => (int)$e['livro_id'],
            ], $this->repo->exemplares($f, $offset, $limite)),
            'localizacoes' => array_map(fn ($l) => [
                'localizacao' => $l['id'] === null ? 'Sem localização definida' : self::local($l) . ((int)$l['ativa'] ? '' : ' (desativada)'), 'codigo' => (string)($l['codigo'] ?? ''),
                'total' => (int)$l['total'], 'disponiveis' => (int)$l['disponiveis'], 'emprestados' => (int)$l['emprestados'], 'outros' => (int)$l['outros'],
                '_localizacao_id' => $l['id'] === null ? null : (int)$l['id'],
            ], $this->repo->exemplaresPorLocalizacao($offset, $limite)),
            'emprestimos' => array_map(fn ($m) => [
                'id' => (int)$m['id'], 'retirada' => self::data($m['data_emprestimo'], true), 'prevista' => self::data($m['data_prevista']),
                'devolucao' => self::data($m['data_devolucao'], true, '—'), 'situacao' => self::situacaoEmprestimo($m, $hoje), 'renovacoes' => (int)$m['renovacoes'],
                'dias_atraso' => (int)$m['dias_atraso'], 'codigo' => $m['codigo_individual'], 'titulo' => $m['titulo'],
                '_emprestimo_id' => (int)$m['id'], '_livro_id' => (int)$m['livro_id'], '_exemplar_id' => (int)$m['exemplar_id'],
            ] + ($pessoais ? ['aluno' => $m['aluno_nome'], 'matricula' => $m['matricula'], 'turma' => self::ni($m['turma']), '_aluno_id' => (int)$m['aluno_id']] : []),
                $this->repo->emprestimos($p, $hoje, $f, $offset, $limite, $pessoais)),
            'mais_emprestados' => array_map(fn ($l) => ['rotulo' => $l['rotulo'], 'autores' => self::ni($l['autores']), 'valor' => $l['valor'], 'exemplares' => $l['exemplares'], '_livro_id' => (int)$l['livro_id']],
                $this->repo->livrosMaisEmprestados($p, $hoje, $f, $offset, $limite)),
            'emprestimos_categorias' => $fatia($this->repo->emprestimosPorCategoria($p, $hoje, $f)),
            'circulacao' => $fatia($this->serieCirculacao($ctx)),
            'atrasos' => array_map(fn ($m) => [
                'id' => (int)$m['id'], 'titulo' => $m['titulo'], 'codigo' => $m['codigo_individual'], 'retirada' => self::data($m['data_emprestimo'], true),
                'prevista' => self::data($m['data_prevista']), 'dias_atraso' => (int)$m['dias_atraso'], 'renovacoes' => (int)$m['renovacoes'],
                '_emprestimo_id' => (int)$m['id'], '_livro_id' => (int)$m['livro_id'], '_exemplar_id' => (int)$m['exemplar_id'],
            ] + ($pessoais ? ['aluno' => $m['aluno_nome'], 'matricula' => $m['matricula'], 'turma' => self::ni($m['turma']), '_aluno_id' => (int)$m['aluno_id']] : []),
                $this->repo->atrasados($hoje, (string)($f['faixa'] ?? ''), $offset, $limite, $pessoais)),
            'faixas_atraso' => $fatia($this->faixas($hoje)),
            'devolvidos_atraso' => array_map(fn ($m) => [
                'id' => (int)$m['id'], 'titulo' => $m['titulo'], 'codigo' => $m['codigo_individual'], 'retirada' => self::data($m['data_emprestimo'], true),
                'prevista' => self::data($m['data_prevista']), 'devolucao' => self::data($m['data_devolucao'], true), 'dias_atraso' => (int)$m['dias_atraso'],
                '_emprestimo_id' => (int)$m['id'], '_livro_id' => (int)$m['livro_id'], '_exemplar_id' => (int)$m['exemplar_id'],
            ], $this->repo->devolvidosComAtraso($p, $offset, $limite)),
            'turmas' => $this->repo->alunosPorTurma($offset, $limite),
            'turnos' => $fatia($this->turnos()),
            'alunos_emprestimo' => !$pessoais ? [] : array_map(fn ($a) => [
                'aluno' => $a['nome'] . ((int)$a['ativo'] ? '' : ' (inativo)'), 'matricula' => $a['matricula'], 'turma' => self::ni($a['turma']),
                'turno' => self::TURNOS[$a['turno'] ?? 'nao_informado'] ?? 'Não informado', 'ativos' => (int)$a['ativos'], 'atrasados' => (int)$a['atrasados'],
                'proxima' => self::data($a['proxima_devolucao']), '_aluno_id' => (int)$a['aluno_id'],
            ], $this->repo->alunosComEmprestimo($hoje, (string)($f['turma'] ?? ''), $offset, $limite)),
            'inventarios' => array_map([$this, 'linhaInventario'], $this->repo->inventarios((string)($f['status'] ?? ''), $offset, $limite)),
            'cdd' => array_map(fn ($c) => [
                'id' => (int)$c['id'], 'titulo' => $c['titulo'], 'autores' => self::ni($c['autores']), 'antiga' => (string)($c['cdd_antiga'] ?? ''),
                'proposta' => (string)($c['cdd_proposta'] ?? ''), 'aprovada' => ($c['status'] ?? '') === 'aprovada' ? (string)$c['cdd_aprovada'] : '',
                'situacao' => self::STATUS_CDD[$c['status'] ?? 'pendente'] . ((int)($c['precisa_revisao'] ?? 0) && ($c['status'] ?? '') !== 'aprovada' ? ' (precisa de revisão)' : ''),
                'aprovada_em' => ($c['status'] ?? '') === 'aprovada' ? self::data($c['aprovado_em'], true) : '', '_livro_id' => (int)$c['id'],
            ], $this->repo->livrosCdd($f, $offset, $limite)),
            'classes_cdd' => $fatia($this->classesCdd()),
            'atividades' => array_map([$this, 'linhaAtividade'], $this->repo->atividades($this->filtrosAtividades($ctx), $offset, $limite)),
            default => throw new InvalidArgumentException('Relatório desconhecido.'),
        };
    }

    /**
     * Todas as linhas, em lotes (para CSV, XLSX e PDF sem estourar a memória).
     * @return Generator<array<string, mixed>>
     */
    public function iterar(string $chave, array $ctx, int $maximo = self::LIMITE_PLANILHA, int $lote = 1000): Generator
    {
        $n = 0;
        for ($offset = 0; $n < $maximo; $offset += $lote) {
            $linhas = $this->linhas($chave, $ctx, $offset, min($lote, $maximo - $n));
            foreach ($linhas as $l) {
                yield $l;
                $n++;
            }
            if (count($linhas) < $lote) {
                return;
            }
        }
    }

    // =================================================================
    // Indicadores e gráficos de cada seção
    // =================================================================

    /** @return list<array{titulo: string, valor: int|string, descricao: string, icone: string, tom: string, filtro?: array}> */
    public function indicadores(string $secao, array $ctx): array
    {
        $hoje = $ctx['hoje'];
        $p = $ctx['periodo'];
        $f = $ctx['f'] ?? [];
        $pct = fn (int $n, int $t) => $t > 0 ? number_format($n / $t * 100, 1, ',', '.') . '%' : '0%';
        switch ($secao) {
            case 'visao':
                $a = $this->repo->resumoAcervo();
                $e = $this->repo->resumoExemplares();
                $c = $this->repo->resumoCirculacao($hoje);
                $cdd = $this->repo->resumoCdd();
                $inv = $this->repo->resumoInventarios();
                return [
                    self::card('Registros bibliográficos', $a['total'], 'Obras e edições cadastradas (não são exemplares)', 'library-big', 'blue'),
                    self::card('Exemplares físicos no acervo', $e['no_acervo'], 'Com código BDP, sem baixados e extraviados', 'book-copy', 'blue'),
                    self::card('Disponíveis', $e['disponivel'], $pct($e['disponivel'], $e['no_acervo']) . ' dos exemplares no acervo', 'circle-check', 'green'),
                    self::card('Empréstimos ativos', $c['ativos'], 'Ainda não devolvidos (agora)', 'book-open-check', 'indigo'),
                    self::card('Empréstimos atrasados', $c['atrasados'], 'Ativos com o prazo vencido (agora)', 'clock-alert', 'red'),
                    self::card('Registros sem exemplar físico', $a['sem_exemplar'], 'Ainda não identificados fisicamente', 'book-x', 'amber'),
                    self::card('CDD aprovada', $cdd['aprovadas'], $pct($cdd['aprovadas'], $cdd['total']) . ' dos registros', 'check-check', 'green'),
                    self::card('Inventários em andamento', $inv['em_andamento'], $inv['concluido'] . ' concluído(s) ao todo', 'clipboard-check', 'blue'),
                ];
            case 'acervo':
                $a = $this->repo->resumoAcervo();
                $cards = [
                    self::card('Registros bibliográficos', $a['total'], $a['arquivados'] . ' arquivado(s) fora da conta', 'library-big', 'blue'),
                    self::card('Com ISBN', $a['com_isbn'], $pct($a['com_isbn'], $a['total']) . ' dos registros', 'barcode', 'green', ['pendencia' => 'com_isbn']),
                    self::card('Sem ISBN', $a['sem_isbn'], 'Comum em edições antigas', 'barcode', 'amber', ['pendencia' => 'sem_isbn']),
                    self::card('Sem autor identificado', $a['sem_autor'], 'Nenhum autor vinculado', 'user-x', 'amber', ['pendencia' => 'sem_autor']),
                    self::card('Sem editora', $a['sem_editora'], 'Editora não informada', 'book-x', 'amber', ['pendencia' => 'sem_editora']),
                    self::card('Sem ano de publicação', $a['sem_ano'], 'Ano não informado', 'calendar', 'amber', ['pendencia' => 'sem_ano']),
                    self::card('Informações incompletas', $a['incompletos'], 'Falta autor, editora ou ano', 'triangle-alert', 'red', ['pendencia' => 'incompletos']),
                    self::card('Possíveis duplicidades', $a['duplicidade'], 'Registros com mesmo título e autor', 'copy', 'red', ['pendencia' => 'duplicidade']),
                    self::card('Sem exemplar físico', $a['sem_exemplar'], 'Registros ainda sem código BDP', 'book-x', 'red', ['pendencia' => 'sem_exemplar']),
                    self::card('Quantidade histórica das planilhas', $a['quantidade_historica'], 'Só referência: não confirmada fisicamente', 'file-spreadsheet', 'muted'),
                ];
                if ($p->limitado()) {
                    $cards[] = self::card('Cadastrados no sistema no período', $this->repo->contarLivros(['periodo' => $p]), 'Data de cadastro, não de aquisição', 'book-plus', 'indigo');
                }
                return $cards;
            case 'exemplares':
                $e = $this->repo->resumoExemplares();
                return [
                    self::card('Exemplares cadastrados', $e['cadastrados'], 'Todos os códigos BDP (inclui baixados e extraviados)', 'book-copy', 'blue'),
                    self::card('No acervo', $e['no_acervo'], 'Sem baixados e extraviados', 'library-big', 'blue', ['situacao' => 'no_acervo']),
                    self::card('Disponíveis', $e['disponivel'], $pct($e['disponivel'], $e['no_acervo']) . ' do acervo físico', 'circle-check', 'green', ['situacao' => 'disponivel']),
                    self::card('Emprestados', $e['emprestado'], $pct($e['emprestado'], $e['no_acervo']) . ' do acervo físico', 'book-open-check', 'indigo', ['situacao' => 'emprestado']),
                    self::card('Aguardando liberação', $e['aguardando'], 'Ainda não emprestam', 'hourglass', 'muted', ['situacao' => 'aguardando']),
                    self::card('Em manutenção', $e['manutencao'], 'Fora de circulação', 'wrench', 'amber', ['situacao' => 'manutencao']),
                    self::card('Danificados', $e['danificado'], 'Fora de circulação', 'triangle-alert', 'red', ['situacao' => 'danificado']),
                    self::card('Extraviados', $e['extraviado'], 'Registrados por decisão', 'package-x', 'red', ['situacao' => 'extraviado']),
                    self::card('Baixados', $e['baixado'], 'Retirados do acervo', 'archive', 'muted', ['situacao' => 'baixado']),
                    self::card('Sem localização confirmada', $e['sem_localizacao'], 'No acervo, sem estante definida', 'map-pin-off', 'amber', ['localizacao' => 'sem']),
                ];
            case 'emprestimos':
                $c = $this->repo->resumoCirculacao($hoje, $f);
                $r = $this->repo->resumoCirculacaoPeriodo($p, $f);
                return [
                    self::card('Empréstimos registrados', $c['total'], 'Desde o início (cada empréstimo conta uma vez)', 'history', 'blue'),
                    self::card('Ativos agora', $c['ativos'], 'Ainda não devolvidos', 'book-open-check', 'indigo', ['situacao' => 'ativos']),
                    self::card('Atrasados agora', $c['atrasados'], 'Ativos com prazo vencido', 'clock-alert', 'red', ['situacao' => 'atrasados']),
                    self::card('Concluídos', $c['concluidos'], 'Já devolvidos (desde o início)', 'circle-check', 'green', ['situacao' => 'concluidos']),
                    self::card('Empréstimos no período', $r['emprestimos'], 'Pela data de retirada', 'book-open-check', 'blue'),
                    self::card('Devoluções no período', $r['devolucoes'], 'Pela data da devolução', 'rotate-ccw', 'green'),
                    self::card('Renovações no período', $r['renovacoes'], 'Pela data da renovação', 'refresh-cw', 'teal'),
                    self::card('Devolvidos após o prazo', $r['devolvidos_com_atraso'], 'Devoluções do período', 'clock-alert', 'amber'),
                ];
            case 'atrasos':
                $a = $this->repo->resumoAtrasos($hoje);
                $d = $this->repo->resumoDevolvidosComAtraso($p);
                return [
                    self::card('Empréstimos atrasados', $a['atrasados'], 'Situação atual', 'clock-alert', 'red', ['faixa' => '']),
                    self::card('Dias de atraso somados', $a['dias_total'], 'Maior atraso: ' . $a['maior_atraso'] . ' dia(s)', 'calendar-clock', 'red'),
                    self::card('1 a 7 dias', $a['faixa_1_7'], 'Atrasos recentes', 'clock', 'amber', ['faixa' => '1_7']),
                    self::card('8 a 15 dias', $a['faixa_8_15'], 'Atrasos médios', 'clock', 'amber', ['faixa' => '8_15']),
                    self::card('Mais de 15 dias', $a['faixa_16'], 'Atrasos longos', 'clock-alert', 'red', ['faixa' => '16']),
                    self::card('Devolvidos após o prazo no período', $d['total'], 'Média de ' . number_format($d['media'], 1, ',', '.') . ' dia(s) de atraso', 'rotate-ccw', 'muted'),
                ];
            case 'alunos':
                $al = $this->repo->resumoAlunos($hoje);
                return [
                    self::card('Alunos cadastrados', $al['total'], $al['total'] === 0 ? 'A planilha de alunos ainda não foi importada' : 'Ativos e inativos', 'graduation-cap', 'blue'),
                    self::card('Ativos', $al['ativos'], 'Podem fazer empréstimos', 'user-check', 'green'),
                    self::card('Inativos', $al['inativos'], 'Cadastro desativado', 'user-x', 'muted'),
                    self::card('Com empréstimo ativo', $al['com_emprestimo_ativo'], 'Alunos diferentes', 'book-open-check', 'indigo'),
                    self::card('Com atraso', $al['com_atraso'], 'Alunos diferentes', 'clock-alert', 'red'),
                    self::card('Sem turma informada', $al['sem_turma'], 'Cadastro incompleto', 'triangle-alert', 'amber'),
                ];
            case 'inventarios':
                $i = $this->repo->resumoInventarios();
                return [
                    self::card('Inventários realizados', $i['total'] - $i['cancelado'], 'Sem contar os cancelados', 'clipboard-check', 'blue', ['status' => 'realizados']),
                    self::card('Em andamento', $i['em_andamento'], $i['pausado'] ? $i['pausado'] . ' pausado(s)' : 'Conferência aberta', 'play', 'amber', ['status' => 'em_andamento']),
                    self::card('Concluídos', $i['concluido'], 'Com decisões registradas', 'check-check', 'green', ['status' => 'concluido']),
                    self::card('Em preparação', $i['aberto'], 'Ainda não iniciados', 'hourglass', 'muted', ['status' => 'aberto']),
                    self::card('Cancelados', $i['cancelado'], 'Com motivo registrado', 'ban', 'muted', ['status' => 'cancelado']),
                ];
            case 'cdd':
                $c = $this->repo->resumoCdd();
                return [
                    self::card('CDD aprovada', $c['aprovadas'], $pct($c['aprovadas'], $c['total']) . ' dos registros', 'check-check', 'green', ['status' => 'aprovada']),
                    self::card('Sem aprovação', $c['sem_aprovacao'], 'Aguardando reclassificação (como no dashboard)', 'list-tree', 'amber', ['status' => 'sem_aprovacao']),
                    self::card('Aguardando classificação', $c['pendentes'], 'Ainda não analisados', 'hourglass', 'muted', ['status' => 'pendente']),
                    self::card('Pendentes de validação', $c['sugeridas'], 'Sugestão encontrada, falta aprovar', 'book-marked', 'blue', ['status' => 'sugerida']),
                    self::card('Aguardando revisão', $c['em_revisao'] + $c['precisa_revisao'], 'Em revisão ou devolvidos para revisão', 'refresh-cw', 'amber', ['status' => 'em_revisao']),
                    self::card('Conflitos', $c['conflitos'], 'Fontes com números diferentes', 'git-compare-arrows', 'red', ['status' => 'conflito']),
                    self::card('Com CDD antiga', $c['com_cdd_antiga'], 'Número da planilha, não aprovado', 'file-spreadsheet', 'muted', ['status' => 'antiga']),
                ];
            case 'atividades':
                $ff = $this->filtrosAtividades($ctx);
                $mods = $this->repo->atividadesPorModulo($ff);
                $cards = [self::card('Ações no período', array_sum($mods), $p->rotulo(), 'history', 'blue')];
                foreach (array_slice($mods, 0, 5, true) as $m => $v) {
                    $cards[] = self::card(self::MODULOS_ATIVIDADE[$m] ?? ucfirst($m), $v, 'Ações registradas', 'list', 'indigo', ['modulo' => $m]);
                }
                return $cards;
        }
        return [];
    }

    /**
     * Gráficos de cada seção. Tipos: barras (horizontais), colunas (ao longo do tempo, com
     * uma ou duas séries), rosca (partes de um todo). Cada um traz a tabela de dados.
     * @return list<array{id: string, titulo: string, tipo: string, descricao: string, series: list<string>, dados: list<array>}>
     */
    public function graficos(string $secao, array $ctx): array
    {
        $hoje = $ctx['hoje'];
        $p = $ctx['periodo'];
        $f = $ctx['f'] ?? [];
        $g = [];
        switch ($secao) {
            case 'visao':
                $e = $this->repo->resumoExemplares();
                $g[] = self::grafico('situacao_exemplares', 'Exemplares por situação', 'rosca', 'Exemplares físicos cadastrados (códigos BDP)', self::situacoes($e));
                $c = $this->repo->resumoCirculacao($hoje);
                $g[] = self::grafico('ativos_atrasados', 'Empréstimos ativos e atrasados', 'rosca', 'Situação atual dos empréstimos em aberto',
                    [['rotulo' => 'No prazo', 'valor' => $c['ativos'] - $c['atrasados']], ['rotulo' => 'Atrasados', 'valor' => $c['atrasados']]]);
                $g[] = self::grafico('classes_cdd', 'Registros por classe CDD aprovada', 'barras', 'Somente classificações aprovadas e válidas', array_map(fn ($c) => ['rotulo' => $c['classe'] . ' — ' . $c['rotulo'], 'valor' => $c['valor']], $this->classesCdd()));
                break;
            case 'acervo':
                $cats = $this->repo->livrosPorCategoria();
                $g[] = self::grafico('categorias', 'Registros por categoria', 'barras', 'Um registro em duas categorias conta nas duas. "Não informado" = sem categoria.', self::top($cats, 10));
                $serie = $this->serieCadastros($ctx);
                $g[] = self::grafico('cadastros', 'Registros cadastrados no sistema ao longo do tempo', 'colunas', 'Data de cadastro no sistema (importação ou cadastro manual), não a data de aquisição do livro. ' . $p->rotulo(), $serie);
                $g[] = self::grafico('assuntos', 'Registros por assunto', 'barras', 'Os 10 assuntos mais frequentes', self::top($this->repo->livrosPorAssunto(10), 11, false));
                $g[] = self::grafico('autores', 'Autores com mais obras cadastradas', 'barras', 'Registros bibliográficos por autor', $this->repo->autores(0, 10));
                break;
            case 'exemplares':
                $e = $this->repo->resumoExemplares();
                $g[] = self::grafico('situacao', 'Exemplares por situação', 'rosca', 'Todos os exemplares cadastrados', self::situacoes($e));
                $cons = $this->repo->exemplaresPorConservacao();
                $g[] = self::grafico('conservacao', 'Estado de conservação', 'barras', 'Exemplares no acervo (sem baixados e extraviados)',
                    array_map(fn ($k) => ['rotulo' => ExemplarService::rotuloConservacao($k), 'valor' => $cons[$k] ?? 0], RelatorioRepository::CONSERVACAO));
                $g[] = self::grafico('setores', 'Exemplares por setor', 'barras', 'Exemplares no acervo', self::top($this->repo->exemplaresPorSetor(), 12));
                break;
            case 'emprestimos':
                $serie = $this->serieCirculacao($ctx);
                $g[] = self::grafico('circulacao', 'Empréstimos e devoluções', 'colunas', 'Empréstimos pela data de retirada e devoluções pela data da devolução. ' . $p->rotulo(), $serie, ['emprestimos' => 'Empréstimos', 'devolucoes' => 'Devoluções']);
                $c = $this->repo->resumoCirculacao($hoje, $f);
                $g[] = self::grafico('ativos_atrasados', 'Empréstimos ativos e atrasados', 'rosca', 'Situação atual dos empréstimos em aberto',
                    [['rotulo' => 'No prazo', 'valor' => $c['ativos'] - $c['atrasados']], ['rotulo' => 'Atrasados', 'valor' => $c['atrasados']]]);
                $g[] = self::grafico('mais_emprestados', 'Livros mais emprestados', 'barras', 'Empréstimos do período (cada empréstimo conta uma vez, mesmo renovado)', $this->repo->livrosMaisEmprestados($p, $hoje, $f, 0, 10));
                $g[] = self::grafico('categorias', 'Categorias com mais empréstimos', 'barras', 'Empréstimos do período', self::top($this->repo->emprestimosPorCategoria($p, $hoje, $f), 10));
                break;
            case 'atrasos':
                $g[] = self::grafico('faixas', 'Atrasos por faixa de dias', 'barras', 'Empréstimos atrasados agora', $this->faixas($hoje));
                $meses = [];
                foreach ($this->repo->atrasosPorMesVencimento($hoje) as $mes => $v) {
                    $meses[] = ['rotulo' => self::mesAno($mes), 'valor' => $v];
                }
                $g[] = self::grafico('vencimento', 'Atrasos pelo mês de vencimento', 'colunas', 'Mês em que venceu o prazo dos empréstimos ainda em aberto', $meses);
                break;
            case 'alunos':
                $g[] = self::grafico('turmas', 'Alunos por turma', 'barras', 'Somente números (sem nomes)', self::top($this->repo->alunosPorTurma(0, 200), 20));
                $g[] = self::grafico('turnos', 'Alunos por turno', 'rosca', 'Somente números (sem nomes)', $this->turnos());
                break;
            case 'inventarios':
                $id = (int)($f['inventario'] ?? 0) ?: $this->repo->inventarioPadrao();
                if ($id) {
                    $inv = $this->inventarios->buscar($id);
                    $r = $this->inventarios->resumo($id);
                    if ($inv) {
                        $nome = 'Inventário nº ' . $id . ' — ' . $inv['titulo'];
                        $g[] = self::grafico('progresso', 'Progresso da conferência', 'rosca', $nome . '. ' . self::progresso($r['previstos_conferidos'], $r['previstos']), [
                            ['rotulo' => 'Conferidos', 'valor' => $r['previstos_conferidos']], ['rotulo' => 'Emprestados (fora da estante)', 'valor' => $r['em_circulacao']],
                            ['rotulo' => 'Ainda não conferidos', 'valor' => $r['nao_conferidos']],
                        ]);
                        $g[] = self::grafico('divergencias', 'Divergências identificadas', 'barras', $nome, [
                            ['rotulo' => 'Localização diferente', 'valor' => $r['local_diferente']], ['rotulo' => 'Conservação divergente', 'valor' => $r['estado_divergente']],
                            ['rotulo' => 'Situação divergente', 'valor' => $r['situacao_divergente']], ['rotulo' => 'Cadastrados durante o inventário', 'valor' => $r['cadastrados']],
                            ['rotulo' => 'Encontrados fora da lista prevista', 'valor' => $r['fora_lista']], ['rotulo' => 'Pendências sem decisão', 'valor' => $r['divergencias_pendentes'] + $r['ausentes_pendentes']],
                        ]);
                    }
                }
                $e = $this->repo->resumoExemplares();
                $g[] = self::grafico('situacao', 'Exemplares por situação', 'rosca', 'Todos os exemplares cadastrados (situação atual)', self::situacoes($e));
                break;
            case 'cdd':
                $c = $this->repo->resumoCdd();
                $g[] = self::grafico('status', 'Situação da classificação', 'rosca', 'Registros bibliográficos ativos', [
                    ['rotulo' => 'Aprovada', 'valor' => $c['aprovadas']], ['rotulo' => 'Aguardando classificação', 'valor' => $c['pendentes']],
                    ['rotulo' => 'Pendente de validação', 'valor' => $c['sugeridas']], ['rotulo' => 'Em revisão', 'valor' => $c['em_revisao']], ['rotulo' => 'Conflito', 'valor' => $c['conflitos']],
                ]);
                $g[] = self::grafico('classes', 'Distribuição pelas classes principais', 'barras', 'Somente CDDs aprovadas com formato válido (a CDD antiga da planilha não entra)',
                    array_map(fn ($c) => ['rotulo' => $c['classe'] . ' — ' . $c['rotulo'], 'valor' => $c['valor']], $this->classesCdd()));
                break;
            case 'atividades':
                $mods = [];
                foreach ($this->repo->atividadesPorModulo($this->filtrosAtividades($ctx)) as $m => $v) {
                    $mods[] = ['rotulo' => self::MODULOS_ATIVIDADE[$m] ?? ucfirst($m), 'valor' => $v];
                }
                $g[] = self::grafico('modulos', 'Ações por tipo', 'barras', $p->rotulo(), $mods);
                break;
        }
        return $g;
    }

    /** Tabelas mostradas em cada seção (a primeira é a principal, com filtros e paginação). */
    public static function tabelasDaSecao(string $secao, array $ctx = []): array
    {
        $lista = [];
        foreach (self::tabelas() as $chave => $t) {
            if ($t['secao'] === $secao && (empty($t['pessoal']) || !empty($ctx['pessoais']))) {
                $lista[] = $chave;
            }
        }
        return $lista;
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    /** Período do acervo: só filtra quando escolhido (o padrão do acervo é "todo o período"). */
    private function periodoAcervo(array $ctx): ?PeriodoRelatorio
    {
        return $ctx['periodo']->limitado() ? $ctx['periodo'] : null;
    }

    private function filtrosAtividades(array $ctx): array
    {
        $f = $ctx['f'] ?? [];
        $modulo = (string)($f['modulo'] ?? '');
        $modulos = match ($modulo) {
            '' => [],
            'emprestimo' => ['emprestimo'],
            default => [$modulo],
        };
        return ['periodo' => $ctx['periodo'], 'modulos' => $modulos, 'usuario' => (int)($f['usuario'] ?? 0), 'ocultar' => (array)($ctx['ocultar'] ?? [])];
    }

    private function serieCadastros(array $ctx): array
    {
        $p = $ctx['periodo'];
        $primeiro = $this->repo->primeiroCadastroLivro();
        if ($primeiro === null) {
            return [];
        }
        $agr = $p->agrupamento($primeiro);
        $mapa = $this->repo->livrosCadastradosPorPeriodo($p, $agr);
        return array_map(fn ($b) => ['rotulo' => $b['rotulo'], 'valor' => $mapa[$b['chave']] ?? 0], $p->baldes($agr, $primeiro, $ctx['hoje']));
    }

    private function serieCirculacao(array $ctx): array
    {
        $p = $ctx['periodo'];
        $primeiro = $this->repo->primeiroEmprestimo();
        if (!$p->limitado() && $primeiro === null) {
            return [];
        }
        $agr = $p->agrupamento($primeiro);
        $s = $this->repo->serieCirculacao($p, $agr, $ctx['f'] ?? []);
        return array_map(fn ($b) => ['rotulo' => $b['rotulo'], 'emprestimos' => $s['emprestimos'][$b['chave']] ?? 0, 'devolucoes' => $s['devolucoes'][$b['chave']] ?? 0],
            $p->baldes($agr, $primeiro, $ctx['hoje']));
    }

    private function faixas(string $hoje): array
    {
        $a = $this->repo->resumoAtrasos($hoje);
        return [['rotulo' => '1 a 7 dias', 'valor' => $a['faixa_1_7']], ['rotulo' => '8 a 15 dias', 'valor' => $a['faixa_8_15']], ['rotulo' => 'Mais de 15 dias', 'valor' => $a['faixa_16']]];
    }

    private function turnos(): array
    {
        $mapa = $this->repo->alunosPorTurno();
        $saida = [];
        foreach (self::TURNOS as $k => $r) {
            if (($mapa[$k] ?? 0) > 0) {
                $saida[] = ['rotulo' => $r, 'valor' => $mapa[$k]];
            }
        }
        return $saida;
    }

    private function classesCdd(): array
    {
        $saida = [];
        foreach ($this->repo->cddPorClasse() as $classe => $v) {
            $saida[] = ['classe' => $classe . '00', 'rotulo' => self::CLASSES_CDD[$classe], 'valor' => $v];
        }
        return $saida;
    }

    private function linhasAssuntos(int $offset, int $limite): array
    {
        $total = $this->repo->contarAssuntos();
        $linhas = $this->repo->assuntos($offset, $limite);
        // "Não informado" fica na última posição da lista.
        if ($offset + $limite > $total) {
            $sem = array_values(array_filter($this->repo->livrosPorAssunto(0), fn ($l) => $l['rotulo'] === 'Não informado'));
            if ($sem && $offset <= $total) {
                $linhas[] = $sem[0];
            }
        }
        return $linhas;
    }

    private function linhaLivro(array $l): array
    {
        return [
            'id' => (int)$l['id'], 'titulo' => $l['titulo'] . ($l['subtitulo'] ? ': ' . $l['subtitulo'] : ''), 'autores' => self::ni($l['autores']),
            'categorias' => self::ni($l['categorias']), 'editora' => self::ni($l['editora']), 'ano' => self::ni($l['ano_publicacao']), 'isbn' => self::ni($l['isbn']),
            'exemplares' => (int)$l['exemplares'], 'historica' => $l['quantidade_historica'] === null ? 'Não informado' : (string)(int)$l['quantidade_historica'],
            'status' => self::STATUS_LIVRO[$l['status_validacao']] ?? $l['status_validacao'], '_livro_id' => (int)$l['id'],
        ];
    }

    private function linhaInventario(array $i): array
    {
        return [
            'id' => (int)$i['id'], 'titulo' => $i['titulo'], 'status' => self::STATUS_INVENTARIO[$i['status']] ?? $i['status'], 'setor' => $i['setor'] ? 'Setor ' . $i['setor'] : 'Acervo inteiro',
            'responsavel' => self::ni($i['responsavel_nome']), 'inicio' => self::data($i['data_inicio'] ?? $i['iniciado_em']), 'conclusao' => self::data($i['concluido_em'], true, '—'),
            'progresso' => $i['status'] === 'aberto' ? 'Conferência não iniciada' : self::progresso((int)$i['previstos_conferidos'], (int)$i['previstos']),
            'previstos' => (int)$i['previstos'], 'conferidos' => (int)$i['previstos_conferidos'], 'nao_conferidos' => (int)$i['nao_conferidos'], 'em_circulacao' => (int)$i['em_circulacao'],
            'local' => (int)$i['local_diferente'], 'estado' => (int)$i['estado_divergente'], 'situacao_div' => (int)$i['situacao_divergente'], 'cadastrados' => (int)$i['cadastrados'],
            'pendencias' => (int)$i['pendencias'], '_inventario_id' => (int)$i['id'], '_percentual' => (int)$i['previstos'] > 0 ? round($i['previstos_conferidos'] / $i['previstos'] * 100, 1) : 0,
        ];
    }

    private function linhaAtividade(array $a): array
    {
        [$modulo] = explode('.', $a['acao'], 2) + [1 => ''];
        $det = json_decode((string)$a['detalhes'], true);
        $det = is_array($det) ? $det : [];
        $partes = [];
        foreach (self::DETALHES_PERMITIDOS as $chave => $rotulo) {
            $v = $det[$chave] ?? null;
            if (is_scalar($v) && (string)$v !== '') {
                $texto = mb_substr((string)$v, 0, 120);
                $partes[] = $rotulo === '' ? $texto : $rotulo . ': ' . $texto;
            }
        }
        return [
            'data' => self::data($a['criado_em'], true), 'responsavel' => self::ni($a['usuario_nome'], 'Sistema'),
            'acao' => $this->rotulosAcoes[$a['acao']] ?? ucfirst(str_replace(['.', '_'], [' · ', ' '], $a['acao'])),
            'referencia' => $a['entidade'] ? ucfirst(str_replace('_', ' ', $a['entidade'])) . ($a['entidade_id'] ? ' nº ' . (int)$a['entidade_id'] : '') : '—',
            'resumo' => $partes ? implode(' · ', $partes) : '—', '_modulo' => $modulo,
        ];
    }

    public static function progresso(int $conferidos, int $previstos): string
    {
        if ($previstos <= 0) {
            return 'Nenhum exemplar previsto';
        }
        $pct = $conferidos / $previstos * 100;
        return number_format($conferidos, 0, ',', '.') . ' de ' . number_format($previstos, 0, ',', '.') . ' exemplares conferidos — ' . number_format($pct, $pct == floor($pct) ? 0 : 1, ',', '.') . '%';
    }

    private static function situacoes(array $e): array
    {
        return array_values(array_filter(array_map(fn ($s) => ['rotulo' => ExemplarService::rotuloSituacao($s), 'valor' => $e[$s] ?? 0], RelatorioRepository::SITUACOES_EXEMPLAR), fn ($s) => $s['valor'] > 0));
    }

    /** Os N maiores e "Outros" (o "Não informado" fica separado no fim quando $manterNaoInformado). */
    private static function top(array $linhas, int $n, bool $manterNaoInformado = true): array
    {
        $ni = array_values(array_filter($linhas, fn ($l) => $l['rotulo'] === 'Não informado'));
        $resto = array_values(array_filter($linhas, fn ($l) => $l['rotulo'] !== 'Não informado'));
        $saida = array_map(fn ($l) => ['rotulo' => (string)$l['rotulo'], 'valor' => (int)$l['valor']], array_slice($resto, 0, $n));
        $outros = array_sum(array_map(fn ($l) => (int)$l['valor'], array_slice($resto, $n)));
        if ($outros > 0) {
            $saida[] = ['rotulo' => 'Outros', 'valor' => $outros];
        }
        if ($ni) {
            $saida[] = ['rotulo' => 'Não informado', 'valor' => (int)$ni[0]['valor']];
        }
        return $saida;
    }

    private static function grafico(string $id, string $titulo, string $tipo, string $descricao, array $dados, array $series = ['valor' => 'Quantidade']): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'tipo' => $tipo, 'descricao' => $descricao, 'series' => $series, 'dados' => array_values($dados)];
    }

    private static function card(string $titulo, int|string $valor, string $descricao, string $icone, string $tom, ?array $filtro = null): array
    {
        return ['titulo' => $titulo, 'valor' => $valor, 'descricao' => $descricao, 'icone' => $icone, 'tom' => $tom] + ($filtro !== null ? ['filtro' => $filtro] : []);
    }

    private static function situacaoEmprestimo(array $m, string $hoje): string
    {
        if ($m['data_devolucao'] !== null) {
            return substr((string)$m['data_devolucao'], 0, 10) > $m['data_prevista'] ? 'Devolvido com atraso' : 'Devolvido';
        }
        return $m['data_prevista'] < $hoje ? 'Atrasado' : 'Ativo';
    }

    private static function local(array $l): string
    {
        return ExemplarService::localizacaoTexto(($l['setor'] ?? null) === null ? null : $l);
    }

    /** "Não informado" no lugar de valores vazios. */
    public static function ni(mixed $v, string $vazio = 'Não informado'): string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? $vazio : $v;
    }

    public static function data(?string $v, bool $hora = false, string $vazio = 'Não informado'): string
    {
        if ($v === null || $v === '') {
            return $vazio;
        }
        $d = date_create($v);
        return $d ? $d->format($hora && strlen($v) > 10 ? 'd/m/Y H:i' : 'd/m/Y') : $v;
    }

    private static function mesAno(string $ym): string
    {
        $meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $d = DateTimeImmutable::createFromFormat('!Y-m', $ym);
        return $d ? $meses[(int)$d->format('n') - 1] . '/' . $d->format('y') : $ym;
    }
}
