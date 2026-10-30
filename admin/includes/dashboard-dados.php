<?php
/**
 * Dados do dashboard. Com o MySQL configurado, todos os números vêm do banco
 * (mesmo que estejam zerados); sem banco, vêm de dados-demo.php e a tela avisa
 * que são fictícios. Os dois nunca são misturados.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dados-demo.php';

/** Descrição das ações registradas em acoes_administrativas (as demais usam o nome técnico). */
const DASHBOARD_ACOES = [
    'livro.cadastrar' => 'Livro cadastrado',
    'livro.editar' => 'Livro editado',
    'livro.complementar' => 'Livro complementado pela planilha',
    'livro.arquivar' => 'Livro arquivado',
    'livro.restaurar' => 'Livro restaurado',
    'usuario.cadastrar' => 'Conta de acesso criada',
    'usuario.editar' => 'Conta de acesso editada',
    'usuario.ativar' => 'Conta de acesso ativada',
    'usuario.desativar' => 'Conta de acesso desativada',
    'usuario.redefinir_senha' => 'Senha redefinida',
    'usuario.trocar_senha' => 'Senha trocada pelo próprio usuário',
    'emprestimo.registrar' => 'Empréstimo registrado',
    'emprestimo.renovar' => 'Empréstimo renovado',
    'devolucao.registrar' => 'Devolução registrada',
    'exemplar.cadastrar' => 'Exemplar físico cadastrado',
    'exemplar.editar' => 'Exemplar editado',
    'exemplar.transferir' => 'Exemplar transferido de localização',
    'exemplar.situacao' => 'Situação do exemplar alterada',
    'exemplar.extravio' => 'Exemplar registrado como extraviado',
    'exemplar.baixar' => 'Baixa de exemplar',
    'etiqueta.gerar' => 'Etiquetas geradas',
    'etiqueta.modelo' => 'Modelo de etiqueta salvo',
    'localizacao.cadastrar' => 'Localização cadastrada',
    'localizacao.editar' => 'Localização editada',
    'localizacao.ativar' => 'Localização reativada',
    'localizacao.desativar' => 'Localização desativada',
    'inventario.criar' => 'Inventário criado',
    'inventario.editar' => 'Inventário editado',
    'inventario.iniciar' => 'Conferência do inventário iniciada',
    'inventario.pausar' => 'Inventário pausado',
    'inventario.retomar' => 'Inventário retomado',
    'inventario.concluir' => 'Inventário concluído',
    'inventario.cancelar' => 'Inventário cancelado',
    'inventario.decidir' => 'Decisão de inventário registrada',
    'inventario.corrigir' => 'Conferência corrigida',
    'inventario.manter_ausentes' => 'Não localizados mantidos sem alteração',
    'inventario.exportar' => 'Relatório de inventário exportado',
];

const DASHBOARD_ENTIDADES = ['importacao' => 'importação', 'importacao_item' => 'linha da importação', 'importacao_mapeamento' => 'mapeamento', 'livro' => 'livro', 'administrador' => 'conta', 'emprestimo' => 'empréstimo',
    'exemplar' => 'exemplar', 'localizacao' => 'localização', 'inventario' => 'inventário', 'etiqueta_modelo' => 'modelo de etiqueta'];

const DASHBOARD_CORES = ['#143477', '#2457C5', '#2E73FF', '#7FA6F5', '#0EA5A4', '#CBD5E1'];

/** Data de hoje na escola (APP_TIMEZONE): o atraso não depende do fuso do servidor MySQL. */
function dashboard_hoje(): string
{
    return Biblioteca\Support\RegrasCirculacao::doAmbiente('env')->hoje();
}

function dashboard_dados(): array
{
    if (db_modo() === 'mysql') {
        return dashboard_dados_mysql();
    }
    return [
        'demo' => true,
        'indicadores' => demo_indicadores(),
        'secundarios' => demo_indicadores_secundarios(),
        'emprestimos' => demo_emprestimos_por_mes(),
        'categorias' => demo_acervo_por_categoria(),
        'atividades' => demo_atividades_recentes(),
        'alertas' => demo_alertas(),
        'inventario' => demo_inventario(),
        'emprestimos_recentes' => [],
    ];
}

/**
 * Pendências da importação: linhas aguardando decisão + linhas com erro ainda não resolvidas
 * (mesma conta da página de importação, ImportacaoRepository::estatisticas).
 */
function dashboard_pendencias_importacao(): int
{
    return (int)db_query("SELECT COALESCE(SUM(it.processado_em IS NULL AND (it.acao_importacao IS NULL OR it.acao_importacao = 'revisar_depois') AND it.status_validacao <> 'erro'), 0)
                                + COALESCE(SUM((it.status_validacao = 'erro' AND it.processado_em IS NULL AND COALESCE(it.acao_importacao, '') <> 'ignorar')
                                               OR it.resultado_importacao = 'falhou'), 0)
                         FROM importacao_itens it JOIN importacoes i ON i.id = it.importacao_id
                         WHERE i.status NOT IN ('cancelada', 'falhou')")->fetchColumn();
}

/** Livros ativos sem classificação CDD aprovada (pendentes, sugeridos, em revisão ou em conflito). */
function dashboard_aguardando_cdd(): int
{
    return (int)db_query("SELECT COUNT(*) FROM livros l LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id
                         WHERE l.arquivado_em IS NULL AND (cc.status IS NULL OR cc.status <> 'aprovada')")->fetchColumn();
}

function dashboard_dados_mysql(): array
{
    $um = fn (string $sql): int => (int)db_query($sql)->fetchColumn();

    $titulos = $um('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL');
    // Exemplares de livros arquivados não entram nas contas do acervo ativo.
    $ex = db_query("SELECT COUNT(*) AS total,
                           COALESCE(SUM(e.situacao = 'disponivel'), 0) AS disponiveis,
                           COALESCE(SUM(e.situacao = 'emprestado'), 0) AS emprestados,
                           COALESCE(SUM(e.situacao = 'manutencao'), 0) AS manutencao,
                           COALESCE(SUM(e.situacao = 'aguardando'), 0) AS aguardando
                    FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                    WHERE e.situacao NOT IN ('baixado', 'extraviado')")->fetch();
    $ex = array_map('intval', $ex);
    $pct = fn (int $n): string => $ex['total'] > 0 ? round($n / $ex['total'] * 100) . '% dos exemplares' : 'Nenhum exemplar registrado';
    $hoje = dashboard_hoje();
    $circ = db_query('SELECT COALESCE(SUM(data_devolucao IS NULL), 0) AS ativos,
                             COALESCE(SUM(data_devolucao IS NULL AND data_prevista < ?), 0) AS atrasados,
                             COALESCE(SUM(data_devolucao IS NOT NULL), 0) AS devolvidos
                      FROM emprestimos', [$hoje])->fetch();
    [$ativos, $atrasados, $devolvidos] = [(int)$circ['ativos'], (int)$circ['atrasados'], (int)$circ['devolvidos']];
    $semConferencia = $um("SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL AND status_validacao <> 'validado'");
    $aguardandoCdd = dashboard_aguardando_cdd();
    $pendImportacao = dashboard_pendencias_importacao();
    $inventarios = $um("SELECT COUNT(*) FROM inventarios WHERE status IN ('em_andamento', 'pausado')");
    // Acervo físico (exemplares de livros ativos, sem baixados e extraviados).
    $semLocal = $um("SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL
                     WHERE e.localizacao_id IS NULL AND e.situacao NOT IN ('baixado', 'extraviado')");
    $livrosSemExemplar = $um("SELECT COUNT(*) FROM livros l WHERE l.arquivado_em IS NULL
                              AND NOT EXISTS (SELECT 1 FROM exemplares e WHERE e.livro_id = l.id AND e.situacao NOT IN ('baixado', 'extraviado'))");
    $divergenciasPendentes = $um("SELECT COUNT(*) FROM inventario_itens i JOIN inventarios v ON v.id = i.inventario_id
                                  WHERE v.status IN ('em_andamento', 'pausado') AND i.decisao IS NULL AND i.conferido_em IS NOT NULL
                                    AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao)");

    // Últimos empréstimos registrados (abertos ou devolvidos).
    $recentes = db_query("SELECT m.id, m.data_emprestimo, m.data_prevista, m.data_devolucao, e.codigo_individual, l.id AS livro_id, l.titulo, a.nome AS aluno, a.turma
                          FROM emprestimos m
                          JOIN exemplares e ON e.id = m.exemplar_id
                          JOIN livros l ON l.id = e.livro_id
                          JOIN alunos a ON a.id = m.aluno_id
                          ORDER BY m.data_emprestimo DESC, m.id DESC LIMIT 6")->fetchAll();

    // Empréstimos dos últimos 12 meses (meses sem empréstimo aparecem com zero).
    $porMes = [];
    foreach (db_query("SELECT DATE_FORMAT(data_emprestimo, '%Y-%m') AS mes, COUNT(*) AS total FROM emprestimos
                       WHERE data_emprestimo >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY mes")->fetchAll() as $r) {
        $porMes[$r['mes']] = (int)$r['total'];
    }
    $nomes = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    $meses = [];
    $inicio = new DateTimeImmutable('first day of this month');
    for ($i = 11; $i >= 0; $i--) {
        $d = $inicio->modify("-$i month");
        $meses[] = ['mes' => $nomes[(int)$d->format('n') - 1] . '/' . $d->format('y'), 'total' => $porMes[$d->format('Y-m')] ?? 0];
    }

    // Títulos por categoria: as 5 maiores e "Outros".
    $cats = db_query('SELECT c.nome, COUNT(DISTINCT l.id) AS total FROM categorias c
                      JOIN livro_categorias lc ON lc.categoria_id = c.id
                      JOIN livros l ON l.id = lc.livro_id AND l.arquivado_em IS NULL
                      WHERE c.arquivado_em IS NULL GROUP BY c.id, c.nome ORDER BY total DESC, c.nome')->fetchAll();
    $somaCats = array_sum(array_column($cats, 'total'));
    $categorias = [];
    foreach (array_slice($cats, 0, 5) as $i => $c) {
        $categorias[] = ['categoria' => $c['nome'], 'percentual' => (int)round($c['total'] / $somaCats * 100), 'cor' => DASHBOARD_CORES[$i]];
    }
    $outros = array_sum(array_column(array_slice($cats, 5), 'total'));
    if ($outros > 0) {
        $categorias[] = ['categoria' => 'Outros', 'percentual' => (int)round($outros / $somaCats * 100), 'cor' => DASHBOARD_CORES[5]];
    }

    // Últimas ações registradas pelos módulos (importação etc.).
    $atividades = [];
    foreach (db_query('SELECT a.acao, a.entidade, a.entidade_id, a.detalhes, a.criado_em, u.nome FROM acoes_administrativas a
                       LEFT JOIN administradores u ON u.id = a.usuario_id ORDER BY a.criado_em DESC, a.id DESC LIMIT 5')->fetchAll() as $a) {
        [$modulo, $verbo] = explode('.', $a['acao'], 2) + [1 => ''];
        $det = json_decode((string)$a['detalhes'], true) ?: [];
        $texto = DASHBOARD_ACOES[$a['acao']] ?? ucfirst(str_replace('_', ' ', $verbo)) . ($a['entidade'] ? ' · ' . (DASHBOARD_ENTIDADES[$a['entidade']] ?? str_replace('_', ' ', $a['entidade'])) . ' nº ' . (int)$a['entidade_id'] : '');
        if (is_string($det['titulo'] ?? null)) {
            $texto .= ': ' . $det['titulo'];
        } elseif (is_string($det['email'] ?? null)) {
            $texto .= ': ' . $det['email'];
        }
        $atividades[] = [
            'tipo' => $modulo,
            'descricao' => $texto,
            'responsavel' => $a['nome'] ?? '—',
            'data' => (new DateTimeImmutable($a['criado_em']))->format('d/m/Y H:i'),
            'situacao' => 'concluido',
        ];
    }

    $alertas = dashboard_alertas();

    return [
        'demo' => false,
        'indicadores' => [
            ['titulo' => 'Títulos cadastrados', 'valor' => $titulos, 'descricao' => 'Registros bibliográficos (obras e edições)', 'icone' => 'library-big', 'tom' => 'blue', 'estado' => $semConferencia . ' aguardando conferência'],
            ['titulo' => 'Exemplares físicos', 'valor' => $ex['total'], 'descricao' => 'Unidades identificadas com código BDP', 'icone' => 'book-copy', 'tom' => 'blue', 'estado' => $ex['total'] > 0 ? $ex['aguardando'] . ' aguardando liberação' : 'Identificados no inventário físico'],
            ['titulo' => 'Disponíveis', 'valor' => $ex['disponiveis'], 'descricao' => 'Exemplares disponíveis para empréstimo', 'icone' => 'circle-check', 'tom' => 'green', 'estado' => $pct($ex['disponiveis'])],
            ['titulo' => 'Emprestados', 'valor' => $ex['emprestados'], 'descricao' => 'Exemplares com empréstimos ativos', 'icone' => 'book-open-check', 'tom' => 'indigo', 'estado' => $pct($ex['emprestados'])],
        ],
        'secundarios' => array_values(array_filter([
            ['titulo' => 'Empréstimos ativos', 'valor' => $ativos, 'icone' => 'book-open-check', 'tom' => 'indigo', 'href' => 'emprestimos'],
            ['titulo' => 'Empréstimos atrasados', 'valor' => $atrasados, 'icone' => 'clock-alert', 'tom' => 'red', 'href' => 'atrasos'],
            ['titulo' => 'Devoluções realizadas', 'valor' => $devolvidos, 'icone' => 'rotate-ccw', 'tom' => 'green', 'href' => 'devolucoes'],
            ['titulo' => 'Aguardando reclassificação CDD', 'valor' => $aguardandoCdd, 'icone' => 'list-tree', 'tom' => 'amber', 'href' => 'classificacao'],
            ['titulo' => 'Pendências de importação', 'valor' => $pendImportacao, 'icone' => 'file-spreadsheet', 'tom' => 'amber', 'href' => 'historico-importacoes'],
            ['titulo' => 'Sem localização confirmada', 'valor' => $semLocal, 'icone' => 'map-pin-off', 'tom' => 'amber', 'href' => 'exemplares?localizacao=sem'],
            ['titulo' => 'Livros sem exemplar físico', 'valor' => $livrosSemExemplar, 'icone' => 'book-x', 'tom' => 'red', 'href' => 'acervo?exemplares=sem'],
            ['titulo' => 'Inventários em andamento', 'valor' => $inventarios, 'icone' => 'clipboard-check', 'tom' => 'blue', 'href' => 'inventario'],
            // Só aparece quando há divergência de inventário esperando decisão.
            $divergenciasPendentes > 0 ? ['titulo' => 'Divergências de inventário pendentes', 'valor' => $divergenciasPendentes, 'icone' => 'triangle-alert', 'tom' => 'red', 'href' => 'inventario'] : null,
        ])),
        'emprestimos_recentes' => $recentes,
        'emprestimos' => ['meses12' => $meses],
        'categorias' => $categorias,
        'atividades' => $atividades,
        'alertas' => $alertas,
        // Com um inventário em andamento ou pausado, os números são os dele (lista prevista congelada no início).
        // Sem inventário ativo, o esperado é o total de exemplares e os conferidos são os que já passaram por uma conferência.
        'inventario' => (function () use ($um, $ex) {
            $ativo = db_query("SELECT id, titulo FROM inventarios WHERE status IN ('em_andamento', 'pausado') ORDER BY id DESC LIMIT 1")->fetch();
            if ($ativo) {
                $r = (new Biblioteca\Repositories\InventarioRepository(db()))->resumo((int)$ativo['id']);
                $comDivergencia = $r['divergencias'];
                return ['esperado' => $r['previstos'], 'encontrados' => max(0, $r['previstos_conferidos'] - min($comDivergencia, $r['previstos_conferidos'])),
                        'pendentes' => $r['nao_conferidos'], 'divergencias' => min($comDivergencia, $r['previstos_conferidos']), 'titulo' => $ativo['titulo'], 'id' => (int)$ativo['id']];
            }
            $conferidos = $um("SELECT COUNT(*) FROM exemplares WHERE conferido_em IS NOT NULL AND situacao NOT IN ('baixado', 'extraviado')");
            return ['esperado' => $ex['total'], 'encontrados' => $conferidos, 'pendentes' => $ex['total'] - $conferidos, 'divergencias' => 0];
        })(),
    ];
}

/** Pendências da seção "Atenção necessária" e do sino de notificações do cabeçalho. */
function dashboard_alertas(): array
{
    if (db_modo() !== 'mysql') {
        return demo_alertas();
    }
    static $alertas = null;
    if ($alertas !== null) {
        return $alertas;
    }
    $alertas = [];
    try {
        $c = db_query("SELECT
                (SELECT COUNT(*) FROM emprestimos WHERE data_devolucao IS NULL AND data_prevista < ?) AS atrasados,
                (SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL AND status_validacao <> 'validado') AS sem_conferencia,
                (SELECT COUNT(*) FROM exemplares WHERE situacao = 'manutencao') AS manutencao,
                (SELECT COUNT(*) FROM inventarios WHERE status IN ('em_andamento', 'pausado')) AS inventarios,
                (SELECT COUNT(*) FROM inventario_itens i JOIN inventarios v ON v.id = i.inventario_id
                  WHERE v.status IN ('em_andamento', 'pausado') AND i.decisao IS NULL AND i.conferido_em IS NOT NULL
                    AND (i.divergencia_local OR i.divergencia_estado OR i.divergencia_situacao)) AS divergencias", [dashboard_hoje()])->fetch();
        $c['importacao'] = dashboard_pendencias_importacao();
    } catch (Throwable $e) {
        error_log('[biblioteca] Falha ao carregar alertas: ' . $e->getMessage());
        return $alertas;
    }
    // Quem não vê a circulação (perfil consulta) não recebe o alerta: a página de atrasos é bloqueada para ele.
    if ($c['atrasados'] > 0 && (!function_exists('auth_pode') || auth_pode('emprestimos.ver'))) {
        $alertas[] = ['titulo' => 'Empréstimos atrasados', 'mensagem' => "{$c['atrasados']} empréstimo(s) com o prazo de devolução vencido.", 'botao' => 'Ver atrasos', 'href' => 'atrasos', 'icone' => 'clock-alert', 'gravidade' => 'alta'];
    }
    if ($c['importacao'] > 0) {
        $alertas[] = ['titulo' => 'Importação com pendências', 'mensagem' => "{$c['importacao']} linha(s) de planilha aguardam decisão ou correção.", 'botao' => 'Revisar importações', 'href' => 'historico-importacoes', 'icone' => 'file-spreadsheet', 'gravidade' => 'media'];
    }
    if ($c['inventarios'] > 0) {
        $alertas[] = ['titulo' => 'Inventário em andamento', 'mensagem' => "{$c['inventarios']} rodada(s) de conferência física em aberto.", 'botao' => 'Abrir inventário', 'href' => 'inventario', 'icone' => 'clipboard-check', 'gravidade' => 'media'];
    }
    if ($c['divergencias'] > 0) {
        $alertas[] = ['titulo' => 'Divergências de inventário', 'mensagem' => "{$c['divergencias']} divergência(s) encontrada(s) na conferência aguardam decisão.", 'botao' => 'Decidir pendências', 'href' => 'inventario', 'icone' => 'triangle-alert', 'gravidade' => 'alta'];
    }
    if ($c['sem_conferencia'] > 0) {
        $alertas[] = ['titulo' => 'Títulos aguardando conferência', 'mensagem' => "{$c['sem_conferencia']} título(s) ainda não passaram pela conferência física.", 'botao' => 'Consultar livros', 'href' => 'acervo', 'icone' => 'clipboard-check', 'gravidade' => 'media'];
    }
    if ($c['manutencao'] > 0) {
        $alertas[] = ['titulo' => 'Exemplares em manutenção', 'mensagem' => "{$c['manutencao']} exemplar(es) indisponível(is) por manutenção.", 'botao' => 'Consultar exemplares', 'href' => 'exemplares', 'icone' => 'wrench', 'gravidade' => 'media'];
    }
    return $alertas;
}
