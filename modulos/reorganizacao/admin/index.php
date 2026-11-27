<?php
/** Central de Reorganização → Visão geral: painel resumido da operação (sem formulários grandes). */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();

$periodo = $r->periodoAtivo();
$participantes = $r->participantesAtivos();
$conectados = count(array_filter($participantes, fn ($p) => $p['estado'] !== 'interrompida'));
$disponiveis = array_values(array_filter($participantes, fn ($p) => $p['equipe'] !== null && !$p['lotes'] && $p['estado'] !== 'interrompida'));
$lotes = $periodo ? array_filter($r->lotes(), fn ($l) => (int)$l['periodo_id'] === (int)$periodo['id']) : [];
$porStatus = ['em_andamento' => 0, 'pausado' => 0, 'concluido' => 0];
foreach ($lotes as $l) {
    $porStatus[$l['status']]++;
}
$t = $r->totaisPeriodo();
$solicitacoes = $r->solicitacoes('pendente');
$pendencias = $r->pendencias('abertas');
$atividades = $r->historicoLista([], 12);
// O que precisa de atenção agora (por lote): revisão esperando, problemas, ajuda, etapas sem ninguém.
$atencao = [];
foreach ($lotes as $l) {
    if ($l['status'] === 'concluido') {
        continue;
    }
    $c = $l['contadores'];
    $url = reorg_url('admin/lote.php?id=' . $l['id']);
    if ($c['aguardando_revisao']) {
        $atencao[] = [$l['nome'], $c['aguardando_revisao'] . ' exemplar(es) aguardando a revisão do Grupo 2', $url . '&aba=g2', 'amber'];
    }
    if ($c['aprovados_sem_cadastro']) {
        $atencao[] = [$l['nome'], $c['aprovados_sem_cadastro'] . ' exemplar(es) aprovados ainda sem cadastro (sem código BDP)', $url . '&aba=g2&f=aprovados', 'blue'];
    }
    if ($c['pendencias_abertas']) {
        $atencao[] = [$l['nome'], $c['pendencias_abertas'] . ' pendência(s) aberta(s)', $url . '&aba=pendencias', 'red'];
    }
    foreach ($l['etapas'] as $eq => $n) {
        if ($eq !== 'grupo2' && empty($l['por_equipe'][$eq]) && $l['status'] === 'em_andamento') {
            $atencao[] = [$l['nome'], REORG_ETAPAS[$eq][1] . ': ' . $n . ' exemplar(es) esperando e ninguém atribuído', $url . '&aba=participantes', 'amber'];
        }
    }
}

reorg_admin_inicio('inicio', 'Reorganização do Acervo');
?>
<section class="adm-page-head reorg-head">
    <div>
        <h1>Visão geral</h1>
        <p><?= $periodo ? 'Período “' . e($periodo['nome']) . '” desde ' . e(admin_data_hora($periodo['inicio'], false)) . ($periodo['previsao_fim'] ? ' · previsão de término ' . e(admin_data_hora($periodo['previsao_fim'], false)) : '') . '.' : 'Nenhum período ativo. Inicie um período em Configurações para liberar a entrada das equipes.' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($periodo): ?>
            <a class="adm-button adm-button--primary" href="<?= e(reorg_url('admin/lote-novo.php')) ?>"><?= icon('plus') ?> Criar lote</a>
        <?php else: ?>
            <a class="adm-button adm-button--primary" href="<?= e(reorg_url('admin/configuracoes.php#periodo')) ?>"><?= icon('play') ?> Iniciar período</a>
        <?php endif; ?>
    </div>
</section>

<section class="reorg-resumo-grade" aria-label="Resumo da operação">
    <div class="adm-card reorg-bloco">
        <h2>Pessoas</h2>
        <dl class="reorg-numeros">
            <div><dt>Conectadas</dt><dd><?= $conectados ?></dd></div>
            <div><dt>Disponíveis (sem lote)</dt><dd><?= count($disponiveis) ?></dd></div>
            <div><dt>Escolhendo equipe</dt><dd><?= count(array_filter($participantes, fn ($p) => $p['equipe'] === null)) ?></dd></div>
        </dl>
        <a class="adm-link" href="<?= e(reorg_url('admin/participantes.php')) ?>">Equipes e participantes</a>
    </div>
    <div class="adm-card reorg-bloco">
        <h2>Lotes</h2>
        <dl class="reorg-numeros">
            <div><dt>Em andamento</dt><dd><?= $porStatus['em_andamento'] ?></dd></div>
            <div><dt>Pausados</dt><dd><?= $porStatus['pausado'] ?></dd></div>
            <div><dt>Concluídos</dt><dd><?= $porStatus['concluido'] ?></dd></div>
        </dl>
        <a class="adm-link" href="<?= e(reorg_url('admin/lotes.php')) ?>">Ver todos os lotes</a>
    </div>
    <div class="adm-card reorg-bloco">
        <h2>Atenção</h2>
        <dl class="reorg-numeros">
            <div><dt>Solicitações pendentes</dt><dd class="<?= $solicitacoes ? 'is-alerta' : '' ?>"><?= count($solicitacoes) ?></dd></div>
            <div><dt>Problemas abertos</dt><dd class="<?= $pendencias ? 'is-alerta' : '' ?>"><?= count($pendencias) ?></dd></div>
        </dl>
        <a class="adm-link" href="<?= e(reorg_url('admin/solicitacoes.php')) ?>">Solicitações e pendências</a>
    </div>
</section>

<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Exemplares do período</h2><span class="adm-table__muted">Cada número conta livros físicos</span></div>
    <div class="reorg-funil">
        <?php foreach ([
            ['Previstos', $t['previstos'], null],
            ['Registrados (G1)', $t['registrados'], $t['previstos']],
            ['Revisados (G2)', $t['revisados'], $t['previstos']],
            ['Com código BDP', $t['codigos'], $t['previstos']],
            ['Carimbados (G3)', $t['carimbados'], $t['previstos']],
            ['Conferidos (G4)', $t['conferidos'], $t['previstos']],
            ['Organizados (G4)', $t['organizados'], $t['previstos']],
        ] as [$rot, $v, $de]): ?>
            <div class="reorg-funil__item"><span><?= e($rot) ?></span><strong><?= (int)$v ?></strong><?= $de !== null ? reorg_barra((int)$v, (int)$de) : '' ?></div>
        <?php endforeach; ?>
    </div>
</section>

<div class="reorg-duas">
    <section class="adm-card adm-panel">
        <div class="adm-panel__header"><h2>Precisa de atenção</h2></div>
        <?php if (!$atencao && !$solicitacoes): ?><p class="adm-table__muted">Nada pendente agora.</p><?php endif; ?>
        <ul class="reorg-atencao">
            <?php foreach (array_slice($solicitacoes, 0, 5) as $q): ?>
                <li><span class="adm-status adm-tone--red">Solicitação</span> <a href="<?= e(reorg_url('admin/solicitacoes.php')) ?>"><?= e($q['nome']) ?> — <?= e(['novo_lote' => 'pediu um novo lote', 'ajuda' => 'pediu ajuda', 'troca_equipe' => 'pediu mudança de equipe'][$q['tipo']]) ?></a></li>
            <?php endforeach; ?>
            <?php foreach (array_slice($atencao, 0, 12) as [$lote, $texto, $url, $tom]): ?>
                <li><span class="adm-status adm-tone--<?= $tom ?>"><?= e($lote) ?></span> <a href="<?= e($url) ?>"><?= e($texto) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="adm-card adm-panel">
        <div class="adm-panel__header"><h2>Atividades recentes</h2><a class="adm-link" href="<?= e(reorg_url('admin/historico.php')) ?>">Histórico</a></div>
        <?php if (!$atividades): ?><p class="adm-table__muted">Nenhuma atividade ainda.</p><?php endif; ?>
        <ul class="reorg-hist">
            <?php foreach ($atividades as $h): ?>
                <li><small><?= e(admin_data_hora($h['criado_em'])) ?></small> <?= e($h['descricao']) ?> <small>— <?= e($h['nome'] ? $h['nome'] . ' (' . Reorganizacao::rotuloSessao($h['sessao_id']) . ')' : ($h['admin_nome'] ?? 'sistema')) ?></small></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
<?php reorg_admin_fim();
