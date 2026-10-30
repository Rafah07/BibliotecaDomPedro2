<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/dashboard-dados.php';

try {
    $dados = dashboard_dados();
} catch (DbIndisponivel $e) {
    http_response_code(503);
    admin_pagina_erro('Banco de dados indisponível', $e->getMessage());
    exit;
} catch (PDOException $e) {
    error_log('[biblioteca] Erro no dashboard: ' . $e->getMessage());
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o dashboard', 'Verifique se as migrações foram aplicadas (php database/migrar.php) e tente novamente.');
    exit;
}
$demo = $dados['demo'];
$indicadores = $dados['indicadores'];
$secundarios = $dados['secundarios'];
$atividades = $dados['atividades'];
$alertas = $dados['alertas'];
// A tabela de empréstimos recentes mostra nomes de alunos: só para quem pode ver a circulação.
$verEmprestimos = auth_pode('emprestimos.ver');
$recentes = $verEmprestimos ? $dados['emprestimos_recentes'] : [];
// Atalhos para as páginas que já funcionam, conforme o perfil de quem entrou.
$acoes = array_values(array_filter([
    auth_pode('emprestimos.registrar') ? ['titulo' => 'Novo empréstimo', 'descricao' => 'Emprestar um exemplar a um aluno cadastrado.', 'icone' => 'book-open-check', 'href' => 'novo-emprestimo'] : null,
    auth_pode('emprestimos.registrar') ? ['titulo' => 'Registrar devolução', 'descricao' => 'Ler o código do exemplar e confirmar a devolução.', 'icone' => 'rotate-ccw', 'href' => 'devolucoes'] : null,
    auth_pode('emprestimos.ver') ? ['titulo' => 'Consultar atrasos', 'descricao' => 'Empréstimos com o prazo vencido.', 'icone' => 'clock-alert', 'href' => 'atrasos'] : null,
    auth_pode('emprestimos.ver') ? ['titulo' => 'Histórico de empréstimos', 'descricao' => 'Ativos, devolvidos e histórico completo.', 'icone' => 'history', 'href' => 'emprestimos'] : null,
    auth_pode('livros.editar') ? ['titulo' => 'Cadastrar livro', 'descricao' => 'Adicionar uma nova obra ao acervo.', 'icone' => 'book-plus', 'href' => 'cadastrar-livro'] : null,
    ['titulo' => 'Consultar acervo', 'descricao' => 'Pesquisar livros por título, autor ou ISBN.', 'icone' => 'library-big', 'href' => 'acervo'],
    ['titulo' => 'Classificação CDD', 'descricao' => 'Pesquisar, sugerir e aprovar classificações.', 'icone' => 'list-tree', 'href' => 'classificacao'],
    auth_pode('importacao.ver') ? ['titulo' => 'Importações', 'descricao' => 'Revisar planilhas e pendências da importação.', 'icone' => 'file-spreadsheet', 'href' => 'historico-importacoes'] : null,
    auth_pode('usuarios.gerenciar') ? ['titulo' => 'Administradores', 'descricao' => 'Gerenciar as contas de acesso ao painel.', 'icone' => 'users', 'href' => 'administradores'] : null,
    ['titulo' => 'Meu perfil', 'descricao' => 'Ver seus dados e trocar a senha.', 'icone' => 'user-round', 'href' => 'perfil'],
]));
$inventario = $dados['inventario'];

function numero_br(float $valor, int $casas = 0): string
{
    return number_format($valor, $casas, ',', '.');
}

$tiposAtividade = [
    'emprestimo' => ['label' => 'Empréstimo', 'icone' => 'book-open-check', 'tom' => 'blue'],
    'devolucao' => ['label' => 'Devolução', 'icone' => 'rotate-ccw', 'tom' => 'green'],
    'cadastro' => ['label' => 'Cadastro', 'icone' => 'book-plus', 'tom' => 'indigo'],
    'inventario' => ['label' => 'Inventário', 'icone' => 'clipboard-check', 'tom' => 'amber'],
    'importacao' => ['label' => 'Importação', 'icone' => 'file-spreadsheet', 'tom' => 'teal'],
    'cdd' => ['label' => 'Classificação CDD', 'icone' => 'list-tree', 'tom' => 'indigo'],
    'livro' => ['label' => 'Acervo', 'icone' => 'library-big', 'tom' => 'blue'],
    'usuario' => ['label' => 'Contas de acesso', 'icone' => 'users', 'tom' => 'indigo'],
];
$situacoes = [
    'concluido' => ['label' => 'Concluído', 'tom' => 'green'],
    'andamento' => ['label' => 'Em andamento', 'tom' => 'amber'],
    'pendente' => ['label' => 'Pendente', 'tom' => 'red'],
];

$conferidos = $inventario['encontrados'] + $inventario['divergencias'];
$pct = fn (int $parte): float => $inventario['esperado'] > 0 ? $parte / $inventario['esperado'] * 100 : 0;

// Data atual em português (o JavaScript atualiza com a data do navegador).
$meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$hoje = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$dataExtenso = sprintf('%s, %d de %s de %d', $diasSemana[(int)$hoje->format('w')], (int)$hoje->format('j'), $meses[(int)$hoje->format('n') - 1], (int)$hoje->format('Y'));

$dadosGraficos = [
    'demo' => $demo,
    'emprestimos' => $dados['emprestimos'],
    'categorias' => $dados['categorias'],
];

admin_page_start('dashboard', 'Dashboard', 'Painel Administrativo', 'Visão geral da Biblioteca Dom Pedro II');
?>

<section class="adm-welcome">
    <div>
        <h1>Visão geral da biblioteca</h1>
        <p>Acompanhe o acervo, os empréstimos e as principais atividades da Biblioteca Dom Pedro II.</p>
    </div>
    <div class="adm-welcome__aside">
        <span class="adm-date"><?= icon('calendar') ?> <time data-today datetime="<?= $hoje->format('Y-m-d') ?>"><?= htmlspecialchars($dataExtenso) ?></time></span>
        <?php if (auth_pode('emprestimos.registrar') && !$demo): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('novo-emprestimo')) ?>"><?= icon('plus') ?> Novo empréstimo</a>
        <?php elseif (auth_pode('livros.editar')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('cadastrar-livro')) ?>"><?= icon('plus') ?> Cadastrar livro</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($demo): ?>
<div class="adm-demo-banner" role="note">
    <?= icon('info') ?>
    <span><strong>Dados demonstrativos.</strong> O banco MySQL não está configurado: todos os números, gráficos, atividades e alertas desta tela são fictícios.</span>
</div>
<?php endif; ?>

<section class="adm-section" aria-labelledby="titulo-indicadores">
    <h2 class="adm-visually-hidden" id="titulo-indicadores">Indicadores do acervo</h2>
    <div class="adm-stats">
        <?php foreach ($indicadores as $card): ?>
            <article class="adm-card adm-stat adm-stat--<?= htmlspecialchars($card['tom']) ?>">
                <div class="adm-stat__top">
                    <p class="adm-stat__title"><?= htmlspecialchars($card['titulo']) ?></p>
                    <span class="adm-icon-box adm-tone--<?= htmlspecialchars($card['tom']) ?>"><?= icon($card['icone']) ?></span>
                </div>
                <p class="adm-stat__value"><?= numero_br($card['valor']) ?></p>
                <p class="adm-stat__desc"><?= htmlspecialchars($card['descricao']) ?></p>
                <span class="adm-pill adm-tone--<?= htmlspecialchars($card['tom']) ?>"><?= htmlspecialchars($card['estado']) ?></span>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="adm-stats adm-stats--small">
        <?php foreach ($secundarios as $card): ?>
            <?php // Números da circulação sem link para quem não pode abrir as páginas de empréstimos.
            $link = !in_array($card['href'], ['emprestimos', 'atrasos', 'devolucoes'], true) || $verEmprestimos;
            [$pagina, $consulta] = explode('?', $card['href'], 2) + [1 => '']; // "pagina?filtro=valor" ?>
            <<?= $link ? 'a' : 'div' ?> class="adm-card adm-mini"<?= $link ? ' href="' . htmlspecialchars(admin_url($pagina) . ($consulta !== '' ? '?' . $consulta : '')) . '"' : '' ?>>
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= htmlspecialchars($card['tom']) ?>"><?= icon($card['icone']) ?></span>
                <span class="adm-mini__body">
                    <span class="adm-mini__value"><?= numero_br($card['valor']) ?></span>
                    <span class="adm-mini__title"><?= htmlspecialchars($card['titulo']) ?></span>
                </span>
                <?= $link ? icon('arrow-right', 'adm-mini__arrow') : '' ?>
            </<?= $link ? 'a' : 'div' ?>>
        <?php endforeach; ?>
    </div>
</section>

<section class="adm-charts" aria-label="Gráficos administrativos">
    <article class="adm-card adm-panel">
        <div class="adm-panel__header">
            <div>
                <h2>Movimentação de empréstimos</h2>
                <p>Quantidade de empréstimos realizados por mês</p>
            </div>
            <div class="adm-segmented" role="group" aria-label="Período do gráfico">
                <button type="button" class="is-active" data-period="6" aria-pressed="true">Últimos 6 meses</button>
                <button type="button" data-period="12" aria-pressed="false">Últimos 12 meses</button>
            </div>
        </div>
        <div class="adm-chart adm-chart--bars is-loading" data-chart="emprestimos">
            <canvas id="grafico-emprestimos" role="img" aria-label="Gráfico de barras com a quantidade de empréstimos por mês<?= $demo ? ' (dados demonstrativos)' : '' ?>"></canvas>
            <div class="adm-chart__loading" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <p class="adm-chart__fallback" hidden>Não foi possível carregar o gráfico. Verifique a conexão com a internet.</p>
        </div>
        <p class="adm-panel__footnote" data-chart-summary>Total no período: <strong><?= numero_br(array_sum(array_column(array_slice($dados['emprestimos']['meses12'], -6), 'total'))) ?></strong> empréstimos<?= $demo ? ' · dados demonstrativos' : '' ?></p>
    </article>

    <article class="adm-card adm-panel">
        <div class="adm-panel__header">
            <div>
                <h2>Acervo por categoria</h2>
                <p>Distribuição dos títulos por gênero</p>
            </div>
        </div>
        <div class="adm-donut">
            <div class="adm-chart adm-chart--donut is-loading" data-chart="categorias">
                <canvas id="grafico-categorias" role="img" aria-label="Gráfico de rosca com a distribuição do acervo por categoria<?= $demo ? ' (dados demonstrativos)' : '' ?>"></canvas>
                <div class="adm-chart__loading adm-chart__loading--ring" aria-hidden="true"></div>
                <p class="adm-chart__fallback" hidden>Gráfico indisponível.</p>
            </div>
            <ul class="adm-legend">
                <?php if (!$dadosGraficos['categorias']): ?><li>Nenhum título com categoria ainda.</li><?php endif; ?>
                <?php foreach ($dadosGraficos['categorias'] as $cat): ?>
                    <li>
                        <span class="adm-legend__swatch" style="background: <?= htmlspecialchars($cat['cor']) ?>"></span>
                        <span class="adm-legend__label"><?= htmlspecialchars($cat['categoria']) ?></span>
                        <strong><?= (int)$cat['percentual'] ?>%</strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </article>
</section>

<div class="adm-columns">
    <div class="adm-columns__main">
        <?php if ($verEmprestimos): ?>
        <article class="adm-card adm-panel" aria-labelledby="titulo-emprestimos-recentes">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-emprestimos-recentes">Empréstimos recentes</h2>
                    <p>Últimos empréstimos registrados<?= $demo ? ' (o modo demonstrativo não tem empréstimos)' : '' ?></p>
                </div>
            </div>
            <div class="adm-table-wrap" tabindex="0" aria-label="Tabela de empréstimos recentes">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th scope="col">Livro</th>
                            <th scope="col">Exemplar</th>
                            <th scope="col">Aluno</th>
                            <th scope="col">Emprestado em</th>
                            <th scope="col">Devolução</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentes): ?>
                            <tr><td colspan="5">
                                <div class="adm-empty">
                                    <?= icon('book-open-check') ?>
                                    <strong>Nenhum empréstimo registrado</strong>
                                    <span>Os empréstimos aparecerão aqui assim que forem registrados em Circulação → Empréstimos.</span>
                                </div>
                            </td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentes as $r): ?>
                            <?php
                            $atrasado = $r['data_devolucao'] === null && $r['data_prevista'] < dashboard_hoje();
                            [$rotulo, $tom] = $r['data_devolucao'] !== null ? ['Devolvido em ' . admin_data_hora($r['data_devolucao'], false), 'green']
                                : ($atrasado ? ['Atrasado desde ' . admin_data_hora($r['data_prevista'], false), 'red'] : ['Previsto para ' . admin_data_hora($r['data_prevista'], false), 'blue']);
                            ?>
                            <tr>
                                <td class="adm-table__strong"><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$r['livro_id']) ?>"><?= e($r['titulo']) ?></a></td>
                                <td class="adm-mono"><a class="adm-link" href="<?= e(admin_url('emprestimo') . '?id=' . (int)$r['id']) ?>"><?= e($r['codigo_individual']) ?></a></td>
                                <td><?= e($r['aluno']) ?><?= $r['turma'] ? ' <small class="adm-table__muted">' . e($r['turma']) . '</small>' : '' ?></td>
                                <td class="adm-table__muted"><?= admin_data_hora($r['data_emprestimo']) ?></td>
                                <td><span class="adm-status adm-tone--<?= $tom ?>"><?= e($rotulo) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <?php endif; ?>

        <article class="adm-card adm-panel">
            <div class="adm-panel__header">
                <div>
                    <h2>Atividades recentes</h2>
                    <p>Últimas movimentações realizadas na biblioteca</p>
                </div>
            </div>
            <div class="adm-table-wrap" tabindex="0" aria-label="Tabela de atividades recentes (role horizontalmente em telas pequenas)">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th scope="col">Tipo</th>
                            <th scope="col">Livro ou descrição</th>
                            <th scope="col">Responsável</th>
                            <th scope="col">Data</th>
                            <th scope="col">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$atividades): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="adm-empty">
                                        <?= icon('inbox') ?>
                                        <strong>Nenhuma atividade registrada</strong>
                                        <span>As movimentações aparecerão aqui assim que forem realizadas.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($atividades as $atividade): ?>
                            <?php
                            $tipo = $tiposAtividade[$atividade['tipo']] ?? ['label' => ucfirst($atividade['tipo']), 'icone' => 'info', 'tom' => 'blue'];
                            $situacao = $situacoes[$atividade['situacao']] ?? ['label' => $atividade['situacao'], 'tom' => 'blue'];
                            ?>
                            <tr>
                                <td>
                                    <span class="adm-type">
                                        <span class="adm-icon-box adm-icon-box--xs adm-tone--<?= $tipo['tom'] ?>"><?= icon($tipo['icone']) ?></span>
                                        <?= htmlspecialchars($tipo['label']) ?>
                                    </span>
                                </td>
                                <td class="adm-table__strong"><?= htmlspecialchars($atividade['descricao']) ?></td>
                                <td><?= htmlspecialchars($atividade['responsavel']) ?></td>
                                <td class="adm-table__muted"><?= htmlspecialchars($atividade['data']) ?></td>
                                <td><span class="adm-status adm-tone--<?= $situacao['tom'] ?>"><?= htmlspecialchars($situacao['label']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <section class="adm-card adm-panel" aria-labelledby="titulo-acesso-rapido">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-acesso-rapido">Acesso rápido</h2>
                    <p>Atalhos para as tarefas mais comuns</p>
                </div>
            </div>
            <div class="adm-quick">
                <?php foreach ($acoes as $acao): ?>
                    <a class="adm-quick__item" href="<?= htmlspecialchars(admin_url($acao['href'])) ?>">
                        <span class="adm-icon-box adm-tone--blue"><?= icon($acao['icone']) ?></span>
                        <span>
                            <strong><?= htmlspecialchars($acao['titulo']) ?></strong>
                            <small><?= htmlspecialchars($acao['descricao']) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <aside class="adm-columns__side">
        <section class="adm-card adm-panel" aria-labelledby="titulo-atencao">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-atencao">Atenção necessária</h2>
                    <p>Pendências para os responsáveis pela biblioteca</p>
                </div>
                <?php if ($demo): ?><span class="adm-demo-tag">Exemplo</span><?php endif; ?>
            </div>
            <?php if (!$alertas): ?>
                <div class="adm-empty">
                    <?= icon('circle-check') ?>
                    <strong>Tudo em dia</strong>
                    <span>Nenhuma pendência no momento.</span>
                </div>
            <?php endif; ?>
            <ul class="adm-alerts">
                <?php foreach ($alertas as $alerta): ?>
                    <?php $tom = $alerta['gravidade'] === 'alta' ? 'red' : 'amber'; ?>
                    <li class="adm-alert adm-alert--<?= $tom ?>">
                        <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($alerta['icone']) ?></span>
                        <div class="adm-alert__body">
                            <strong><?= htmlspecialchars($alerta['titulo']) ?></strong>
                            <p><?= htmlspecialchars($alerta['mensagem']) ?></p>
                            <a class="adm-link" href="<?= htmlspecialchars(admin_url($alerta['href'])) ?>"><?= htmlspecialchars($alerta['botao']) ?> <?= icon('arrow-right') ?></a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="adm-card adm-panel adm-inventory" aria-labelledby="titulo-inventario">
            <div class="adm-panel__header">
                <div>
                    <h2 id="titulo-inventario">Inventário do acervo</h2>
                    <p>Acompanhamento da conferência física dos livros</p>
                </div>
            </div>

            <div class="adm-inventory__progress">
                <div class="adm-inventory__label">
                    <span>Exemplares já conferidos</span>
                    <strong><?= numero_br($pct($conferidos), 1) ?>%</strong>
                </div>
                <div class="adm-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int)$inventario['esperado'] ?>" aria-valuenow="<?= (int)$conferidos ?>" aria-valuetext="<?= numero_br($conferidos) ?> de <?= numero_br($inventario['esperado']) ?> exemplares conferidos">
                    <span class="adm-progress__bar adm-progress__bar--ok" style="width: <?= round($pct($inventario['encontrados']), 2) ?>%"></span>
                    <span class="adm-progress__bar adm-progress__bar--warn" style="width: <?= round($pct($inventario['divergencias']), 2) ?>%"></span>
                </div>
                <p class="adm-inventory__caption"><?= numero_br($conferidos) ?> de <?= numero_br($inventario['esperado']) ?> exemplares esperados já passaram pela conferência física (encontrados + divergências).</p>
            </div>

            <dl class="adm-inventory__stats">
                <div><dt>Total esperado</dt><dd><?= numero_br($inventario['esperado']) ?></dd></div>
                <div><dt><span class="adm-dot adm-dot--ok"></span>Encontrados</dt><dd><?= numero_br($inventario['encontrados']) ?></dd></div>
                <div><dt><span class="adm-dot adm-dot--muted"></span>Pendentes de conferência</dt><dd><?= numero_br($inventario['pendentes']) ?></dd></div>
                <div><dt><span class="adm-dot adm-dot--warn"></span>Divergências</dt><dd><?= numero_br($inventario['divergencias']) ?></dd></div>
            </dl>

            <p class="adm-inventory__message"><?= icon('info') ?> <?= $dados['demo'] ? 'Continue a conferência física para validar os exemplares existentes na biblioteca.' : (isset($inventario['titulo']) ? 'Inventário em andamento: ' . htmlspecialchars($inventario['titulo']) . '.' : 'Nenhum inventário em andamento. Os números mostram os exemplares que já passaram por alguma conferência.') ?></p>
            <a class="adm-button adm-button--primary adm-button--block" href="<?= htmlspecialchars(admin_url('inventario')) ?>"><?= icon('scan-barcode') ?> Continuar inventário</a>
        </section>
    </aside>
</div>

<script type="application/json" id="dashboard-data"><?= json_encode($dadosGraficos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
admin_page_end([
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
    'assets/js/dashboard.js?v=20261029',
]);
