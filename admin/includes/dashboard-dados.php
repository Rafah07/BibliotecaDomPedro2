<?php
/**
 * Dados do dashboard. Com o MySQL configurado, todos os números vêm do banco
 * (mesmo que estejam zerados); sem banco, vêm de dados-demo.php e a tela avisa
 * que são fictícios. Os dois nunca são misturados.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dados-demo.php';

const DASHBOARD_CORES = ['#143477', '#2457C5', '#2E73FF', '#7FA6F5', '#0EA5A4', '#CBD5E1'];

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
    ];
}

function dashboard_dados_mysql(): array
{
    $um = fn (string $sql): int => (int)db_query($sql)->fetchColumn();

    $titulos = $um('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL');
    $ex = db_query("SELECT COUNT(*) AS total,
                           COALESCE(SUM(situacao = 'disponivel'), 0) AS disponiveis,
                           COALESCE(SUM(situacao = 'emprestado'), 0) AS emprestados,
                           COALESCE(SUM(situacao = 'manutencao'), 0) AS manutencao,
                           COALESCE(SUM(situacao = 'aguardando'), 0) AS aguardando
                    FROM exemplares WHERE situacao NOT IN ('baixado', 'extraviado')")->fetch();
    $ex = array_map('intval', $ex);
    $pct = fn (int $n): string => $ex['total'] > 0 ? round($n / $ex['total'] * 100) . '% dos exemplares' : 'Nenhum exemplar registrado';
    $atrasados = $um('SELECT COUNT(*) FROM emprestimos WHERE data_devolucao IS NULL AND data_prevista < CURDATE()');
    $hoje = $um('SELECT COUNT(*) FROM emprestimos WHERE data_devolucao IS NULL AND data_prevista = CURDATE()');
    $semConferencia = $um("SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL AND status_validacao <> 'validado'");

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
    foreach (db_query('SELECT a.acao, a.entidade, a.entidade_id, a.criado_em, u.nome FROM acoes_administrativas a
                       LEFT JOIN administradores u ON u.id = a.usuario_id ORDER BY a.criado_em DESC, a.id DESC LIMIT 5')->fetchAll() as $a) {
        [$modulo, $verbo] = explode('.', $a['acao'], 2) + [1 => ''];
        $atividades[] = [
            'tipo' => $modulo,
            'descricao' => ucfirst(str_replace('_', ' ', $verbo)) . ($a['entidade'] ? ' · ' . $a['entidade'] . ' nº ' . (int)$a['entidade_id'] : ''),
            'responsavel' => $a['nome'] ?? '—',
            'data' => (new DateTimeImmutable($a['criado_em']))->format('d/m/Y H:i'),
            'situacao' => 'concluido',
        ];
    }

    $alertas = dashboard_alertas();

    return [
        'demo' => false,
        'indicadores' => [
            ['titulo' => 'Total de títulos', 'valor' => $titulos, 'descricao' => 'Obras cadastradas no acervo', 'icone' => 'library-big', 'tom' => 'blue', 'estado' => $semConferencia . ' aguardando conferência'],
            ['titulo' => 'Total de exemplares', 'valor' => $ex['total'], 'descricao' => 'Unidades físicas registradas', 'icone' => 'book-copy', 'tom' => 'blue', 'estado' => $ex['aguardando'] . ' aguardando conferência'],
            ['titulo' => 'Disponíveis', 'valor' => $ex['disponiveis'], 'descricao' => 'Exemplares disponíveis para empréstimo', 'icone' => 'circle-check', 'tom' => 'green', 'estado' => $pct($ex['disponiveis'])],
            ['titulo' => 'Emprestados', 'valor' => $ex['emprestados'], 'descricao' => 'Exemplares com empréstimos ativos', 'icone' => 'book-open-check', 'tom' => 'indigo', 'estado' => $pct($ex['emprestados'])],
        ],
        'secundarios' => [
            ['titulo' => 'Empréstimos atrasados', 'valor' => $atrasados, 'icone' => 'clock-alert', 'tom' => 'red', 'href' => 'atrasos'],
            ['titulo' => 'Em manutenção', 'valor' => $ex['manutencao'], 'icone' => 'wrench', 'tom' => 'amber', 'href' => 'exemplares'],
            ['titulo' => 'Títulos sem conferência física', 'valor' => $semConferencia, 'icone' => 'clipboard-check', 'tom' => 'amber', 'href' => 'inventario'],
            ['titulo' => 'Devoluções previstas para hoje', 'valor' => $hoje, 'icone' => 'calendar-clock', 'tom' => 'blue', 'href' => 'devolucoes'],
        ],
        'emprestimos' => ['meses12' => $meses],
        'categorias' => $categorias,
        'atividades' => $atividades,
        'alertas' => $alertas,
        // Até o módulo de inventário existir, o esperado é o total de exemplares e todos estão pendentes,
        // exceto os que já foram conferidos (conferido_em preenchido).
        'inventario' => (function () use ($um, $ex) {
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
                (SELECT COUNT(*) FROM emprestimos WHERE data_devolucao IS NULL AND data_prevista < CURDATE()) AS atrasados,
                (SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL AND status_validacao <> 'validado') AS sem_conferencia,
                (SELECT COUNT(*) FROM exemplares WHERE situacao = 'manutencao') AS manutencao")->fetch();
    } catch (Throwable $e) {
        error_log('[biblioteca] Falha ao carregar alertas: ' . $e->getMessage());
        return $alertas;
    }
    if ($c['atrasados'] > 0) {
        $alertas[] = ['titulo' => 'Empréstimos atrasados', 'mensagem' => "{$c['atrasados']} empréstimo(s) com o prazo de devolução vencido.", 'botao' => 'Ver atrasos', 'href' => 'atrasos', 'icone' => 'clock-alert', 'gravidade' => 'alta'];
    }
    if ($c['sem_conferencia'] > 0) {
        $alertas[] = ['titulo' => 'Títulos aguardando conferência', 'mensagem' => "{$c['sem_conferencia']} título(s) ainda não passaram pela conferência física.", 'botao' => 'Consultar livros', 'href' => 'acervo', 'icone' => 'clipboard-check', 'gravidade' => 'media'];
    }
    if ($c['manutencao'] > 0) {
        $alertas[] = ['titulo' => 'Exemplares em manutenção', 'mensagem' => "{$c['manutencao']} exemplar(es) indisponível(is) por manutenção.", 'botao' => 'Consultar exemplares', 'href' => 'exemplares', 'icone' => 'wrench', 'gravidade' => 'media'];
    }
    return $alertas;
}
