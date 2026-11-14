<?php
/**
 * Relatório de Atividades: o trabalho feito pela equipe por dia, por pessoa e por tipo, calculado dos
 * registros reais (histórico dos exemplares, verificações das obras, ocorrências, reservas de códigos
 * e ações administrativas). Filtros: data/período, administrador, equipe e tipo de atividade.
 * Saídas: imprimir (A4), Excel (XLSX/CSV) e PDF (gerador próprio do sistema).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\PreparoRepository;

$usuario = fisico_iniciar('relatorios.ver');
$base = admin_url('relatorio-atividades');

$hoje = date('Y-m-d');
$data = fn (string $nome, string $padrao) => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, $nome)) && strtotime(admin_param($_GET, $nome)) ? admin_param($_GET, $nome) : $padrao;
$filtros = [
    'de' => $data('de', $hoje),
    'ate' => $data('ate', $hoje),
    'administrador' => (int)admin_param($_GET, 'administrador') > 0 ? (string)(int)admin_param($_GET, 'administrador') : '',
    'equipe' => mb_substr(admin_param($_GET, 'equipe'), 0, 80),
    'tipo' => isset(PreparoRepository::ATIVIDADES[admin_param($_GET, 'tipo')]) ? admin_param($_GET, 'tipo') : '',
];
if ($filtros['ate'] < $filtros['de']) {
    [$filtros['de'], $filtros['ate']] = [$filtros['ate'], $filtros['de']];
}
// Período longo demais pesa no banco: até 1 ano por consulta.
if (strtotime($filtros['ate']) - strtotime($filtros['de']) > 366 * 86400) {
    $filtros['de'] = date('Y-m-d', strtotime($filtros['ate'] . ' -366 days'));
}

try {
    $administradores = preparo_repo()->administradores();
    $equipes = preparo_repo()->equipes();
    $pessoas = null;
    if ($filtros['administrador'] !== '') {
        $pessoas = [(int)$filtros['administrador']];
    }
    if ($filtros['equipe'] !== '') {
        $daEquipe = array_map(fn ($a) => (int)$a['id'], array_filter($administradores, fn ($a) => (string)$a['equipe'] === $filtros['equipe']));
        $pessoas = $pessoas === null ? array_values($daEquipe) : array_values(array_intersect($pessoas, $daEquipe));
    }
    $dados = preparo_repo()->atividades($filtros['de'], $filtros['ate'], $pessoas);
    $detalhe = $filtros['tipo'] !== '' ? preparo_repo()->atividadesDetalhe($filtros['tipo'], $filtros['de'], $filtros['ate'], $pessoas, 1000) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível gerar o relatório', fisico_erro_tecnico($e, 'relatório de atividades') . ' Confira se a migração 150 foi aplicada.');
    exit;
}
$tipos = $filtros['tipo'] !== '' ? [$filtros['tipo'] => PreparoRepository::ATIVIDADES[$filtros['tipo']]] : PreparoRepository::ATIVIDADES;
$periodo = $filtros['de'] === $filtros['ate'] ? 'Relatório diário — ' . date('d/m/Y', strtotime($filtros['de'])) : 'Período de ' . date('d/m/Y', strtotime($filtros['de'])) . ' a ' . date('d/m/Y', strtotime($filtros['ate']));
$filtrosTexto = array_filter([
    'Administrador' => $filtros['administrador'] !== '' ? (current(array_filter($administradores, fn ($a) => (int)$a['id'] === (int)$filtros['administrador']))['nome'] ?? '') : '',
    'Equipe' => $filtros['equipe'],
    'Tipo' => $filtros['tipo'] !== '' ? PreparoRepository::ATIVIDADES[$filtros['tipo']] : '',
]);

// ---------- Exportar (Excel, CSV ou PDF) ----------
$formato = admin_param($_GET, 'exportar');
if (in_array($formato, ['xlsx', 'csv', 'pdf'], true)) {
    registrar_acao('relatorio.exportar', 'relatorio', null, ['relatorio' => 'atividades', 'formato' => $formato, 'periodo' => $periodo, 'filtros' => $filtrosTexto]);
    $linhas = [];
    foreach ($dados['por_dia'] as $dia => $n) {
        $linhas[] = ['rotulo' => date('d/m/Y', strtotime($dia))] + array_map(fn ($k) => (int)($n[$k] ?? 0), array_combine(array_keys($tipos), array_keys($tipos)));
    }
    $linhas[] = ['rotulo' => 'Total'] + array_map(fn ($k) => (int)$dados['totais'][$k], array_combine(array_keys($tipos), array_keys($tipos)));
    foreach ($dados['por_pessoa'] as $p) {
        $linhas[] = ['rotulo' => 'Por pessoa: ' . $p['nome'] . ($p['equipe'] ? ' (' . $p['equipe'] . ')' : '')] + array_map(fn ($k) => (int)($p[$k] ?? 0), array_combine(array_keys($tipos), array_keys($tipos)));
    }
    if ($detalhe) {
        foreach ($detalhe as $d) {
            $linhas[] = ['rotulo' => 'Detalhe ' . admin_data_hora($d['quando']) . ' · ' . ($d['pessoa'] ?? '') . ' · ' . $d['descricao']] + array_fill_keys(array_keys($tipos), '');
        }
    }
    fisico_enviar_tabela($formato, 'relatorio-atividades-' . $filtros['de'] . '-a-' . $filtros['ate'], 'Relatório de Atividades',
        ['rotulo' => 'Data / pessoa'] + $tipos, array_fill_keys(array_keys($tipos), 'numero'), $linhas,
        [etiquetas_biblioteca() . ' — Relatório de Atividades', $periodo . ' · gerado em ' . date('d/m/Y H:i') . ' por ' . $usuario['nome'],
            'Filtros: ' . ($filtrosTexto ? implode(' · ', array_map(fn ($k, $v) => "$k: $v", array_keys($filtrosTexto), $filtrosTexto)) : 'nenhum')],
        $usuario['nome'], array_map(fn ($k, $r) => [$r, (int)$dados['totais'][$k]], array_keys($tipos), $tipos), $filtrosTexto);
}

$filtrosLink = array_filter($filtros, fn ($v) => $v !== '');

admin_page_start('relatorio-atividades', 'Relatório de Atividades', 'Relatórios', 'Trabalho da equipe por dia, pessoa e tipo', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Relatórios', admin_url('relatorios')], ['Relatório de Atividades']]) ?>

<section class="adm-page-head adm-nao-imprimir">
    <div>
        <h1>Relatório de Atividades</h1>
        <p>Números calculados a partir dos registros reais do sistema: nada é digitado à mão. Escolha o dia ou o período e, se quiser, a pessoa, a equipe ou o tipo de atividade.</p>
    </div>
    <div class="adm-page-head__actions">
        <button class="adm-button adm-button--ghost" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir relatório</button>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'xlsx'])) ?>"><?= icon('file-down') ?> Exportar para Excel</a>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($base, $filtrosLink, ['exportar' => 'pdf'])) ?>"><?= icon('file-text') ?> PDF</a>
    </div>
</section>

<form class="adm-card adm-toolbar adm-toolbar--search adm-nao-imprimir" method="get" action="<?= e($base) ?>">
    <div class="adm-filters">
        <label class="adm-filter"><span>De</span><input class="adm-input" type="date" name="de" value="<?= e($filtros['de']) ?>" max="<?= e($hoje) ?>"></label>
        <label class="adm-filter"><span>Até</span><input class="adm-input" type="date" name="ate" value="<?= e($filtros['ate']) ?>" max="<?= e($hoje) ?>"></label>
        <label class="adm-filter"><span>Administrador</span><select class="adm-select" name="administrador"><option value="">Todos</option>
            <?php foreach ($administradores as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $filtros['administrador'] === (string)$a['id'] ? ' selected' : '' ?>><?= e($a['nome']) ?></option><?php endforeach; ?></select></label>
        <label class="adm-filter"><span>Equipe</span><select class="adm-select" name="equipe"><option value="">Todas</option>
            <?php foreach ($equipes as $eq): ?><option value="<?= e($eq) ?>"<?= $filtros['equipe'] === $eq ? ' selected' : '' ?>><?= e($eq) ?></option><?php endforeach; ?></select></label>
        <label class="adm-filter"><span>Tipo de atividade</span><select class="adm-select" name="tipo"><option value="">Todos</option>
            <?php foreach (PreparoRepository::ATIVIDADES as $k => $r): ?><option value="<?= e($k) ?>"<?= $filtros['tipo'] === $k ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
    </div>
    <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Gerar</button>
    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>">Hoje</a>
    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['de' => date('Y-m-d', strtotime('monday this week')), 'ate' => $hoje])) ?>">Esta semana</a>
    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['de' => date('Y-m-01'), 'ate' => $hoje])) ?>">Este mês</a>
</form>

<section class="adm-card adm-panel adm-relatorio-atividades" aria-labelledby="titulo-relatorio">
    <h2 id="titulo-relatorio"><?= e($periodo) ?></h2>
    <p class="adm-table__muted"><?= e(etiquetas_biblioteca()) ?> · gerado em <?= e(date('d/m/Y H:i')) ?> por <?= e($usuario['nome']) ?><?= $filtrosTexto ? ' · ' . e(implode(' · ', array_map(fn ($k, $v) => "$k: $v", array_keys($filtrosTexto), $filtrosTexto))) : '' ?></p>
    <?php if ($pessoas !== null && !$pessoas): ?><?= admin_aviso('aviso', 'Nenhuma conta nesta equipe.', 'Informe a equipe das contas em Meu perfil ou em Administradores.') ?><?php endif; ?>
    <dl class="adm-atividades-totais">
        <?php foreach ($tipos as $k => $r): ?>
            <div><dt><?= e($r) ?></dt><dd><?= fisico_numero((int)$dados['totais'][$k]) ?></dd></div>
        <?php endforeach; ?>
    </dl>

    <?php if (count($dados['por_dia']) > 1 || $filtros['de'] !== $filtros['ate']): ?>
        <h3>Por dia</h3>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--compacta adm-table--numeros">
                <thead><tr><th scope="col">Dia</th><?php foreach ($tipos as $r): ?><th scope="col"><?= e($r) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($dados['por_dia'] as $dia => $n): ?>
                    <tr><th scope="row"><?= e(date('d/m/Y', strtotime($dia))) ?></th><?php foreach ($tipos as $k => $r): ?><td><?= fisico_numero((int)($n[$k] ?? 0)) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                <?php if (!$dados['por_dia']): ?><tr><td colspan="<?= count($tipos) + 1 ?>">Nenhuma atividade no período.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3>Por pessoa</h3>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--compacta adm-table--numeros">
            <thead><tr><th scope="col">Pessoa</th><?php foreach ($tipos as $r): ?><th scope="col"><?= e($r) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($dados['por_pessoa'] as $p): ?>
                <tr><th scope="row"><?= e($p['nome']) ?><?= $p['equipe'] ? '<small class="adm-block-muted">' . e($p['equipe']) . '</small>' : '' ?></th><?php foreach ($tipos as $k => $r): ?><td><?= fisico_numero((int)($p[$k] ?? 0)) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if (!$dados['por_pessoa']): ?><tr><td colspan="<?= count($tipos) + 1 ?>">Nenhuma atividade no período.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($filtros['tipo'] !== ''): ?>
        <h3>Detalhes: <?= e(PreparoRepository::ATIVIDADES[$filtros['tipo']]) ?> (<?= fisico_numero(count($detalhe)) ?><?= count($detalhe) >= 1000 ? '+, mostrando 1.000' : '' ?>)</h3>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--compacta">
                <thead><tr><th scope="col">Quando</th><th scope="col">Pessoa</th><th scope="col">Registro</th></tr></thead>
                <tbody>
                <?php foreach ($detalhe as $d): ?><tr><td><?= e(admin_data_hora($d['quando'])) ?></td><td><?= e((string)$d['pessoa']) ?></td><td><?= e((string)$d['descricao']) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <details class="adm-config-detalhes adm-nao-imprimir">
        <summary>De onde vêm os números</summary>
        <ul>
            <li><strong>Obras verificadas</strong> e <strong>correções</strong>: histórico de verificação das obras.</li>
            <li><strong>Exemplares registrados</strong>: data de cadastro de cada exemplar. <strong>Códigos BDP gerados e atribuídos</strong>: códigos gerados pelo sistema que foram usados num exemplar (códigos digitados do carimbo não entram).</li>
            <li><strong>Carimbados</strong>, <strong>organizados</strong> (saíram da triagem para uma prateleira, ou já foram registrados nela) e <strong>movimentados</strong> (de uma prateleira para outra): histórico de cada exemplar.</li>
            <li><strong>Ocorrências</strong>: ocorrências registradas. <strong>Obras cadastradas manualmente</strong>: cadastros de obras feitos no painel.</li>
            <li>Ações desfeitas continuam contadas (elas aconteceram); a reversão aparece como uma nova ação.</li>
        </ul>
    </details>
</section>

<?php
admin_page_end(FISICO_JS);
