<?php
/** Central administrativa da reorganização: visão geral, criação de lotes e lotes por situação. */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_lote') {
    admin_exigir_csrf();
    try {
        $id = $r->criarLote($_POST, $adminId);
        admin_flash('sucesso', 'Lote criado', 'O lote permanente de cadastro foi aberto junto (mesma numeração de códigos BDP do sistema).');
        admin_redirecionar(reorg_url('admin/lote.php?id=' . $id));
    } catch (Biblioteca\Services\AcervoException $e) {
        admin_flash('erro', 'O lote não foi criado', $e->getMessage());
        admin_redirecionar(reorg_url('admin/?novo=1'));
    }
}

$periodo = $r->periodoAtivo();
$participantes = $r->participantesAtivos();
$porEquipe = ['grupo1' => [], 'grupo3' => [], 'grupo4' => [], '' => []];
foreach ($participantes as $p) {
    $porEquipe[$p['equipe'] ?? ''][] = $p;
}
$lotes = $r->lotes();
$porStatus = ['em_andamento' => [], 'pausado' => [], 'concluido' => []];
foreach ($lotes as $l) {
    $porStatus[$l['status']][] = $l;
}
$solicitacoes = $r->solicitacoes('pendente');
$pendencias = $r->pendencias('abertas');
$notificacoes = $r->notificacoesAdmin($adminId, false, 8);
$admins = reorg_administradores();
$novo = isset($_GET['novo']);

reorg_admin_inicio('inicio', 'Reorganização do Acervo');
?>
<section class="adm-page-head">
    <div>
        <h1>Reorganização do Acervo</h1>
        <p>Central do módulo temporário: período, lotes, equipes (Grupos 1, 3 e 4 entram só com o nome; o Grupo 2 é a administração), revisão e acompanhamento.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($periodo): ?>
            <a class="adm-button adm-button--primary" href="?novo=1#novo-lote"><?= icon('plus') ?> Criar lote</a>
        <?php else: ?>
            <a class="adm-button adm-button--primary" href="<?= e(reorg_url('admin/periodo.php')) ?>"><?= icon('play') ?> Iniciar período</a>
        <?php endif; ?>
    </div>
</section>

<section class="adm-kpis adm-kpis--6" aria-label="Resumo">
    <?php foreach ([
        ['Participantes conectados', count($participantes), 'users', 'blue', reorg_url('admin/participantes.php')],
        ['Lotes em andamento', count($porStatus['em_andamento']), 'layers', 'indigo', '#lotes-em_andamento'],
        ['Lotes pausados', count($porStatus['pausado']), 'pause', 'amber', '#lotes-pausado'],
        ['Lotes concluídos', count($porStatus['concluido']), 'circle-check', 'green', '#lotes-concluido'],
        ['Solicitações pendentes', count($solicitacoes), 'inbox', count($solicitacoes) ? 'red' : 'muted', reorg_url('admin/solicitacoes.php')],
        ['Pendências abertas', count($pendencias), 'triangle-alert', count($pendencias) ? 'amber' : 'muted', reorg_url('admin/pendencias.php')],
    ] as [$t, $v, $ic, $tom, $href]): ?>
        <a class="adm-card adm-kpi adm-kpi--action" href="<?= e($href) ?>">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= e($tom) ?>"><?= icon($ic) ?></span>
            <span><strong class="adm-kpi__value"><?= (int)$v ?></strong><span class="adm-kpi__title"><?= e($t) ?></span></span>
        </a>
    <?php endforeach; ?>
</section>

<div class="reorg-grade">
    <section class="adm-card adm-panel">
        <div class="adm-panel__header"><h2>Equipes agora</h2><a class="adm-link" href="<?= e(reorg_url('admin/participantes.php')) ?>">Gerenciar participantes</a></div>
        <div class="reorg-equipes">
            <?php foreach (['grupo1' => 'Grupo 1 — Levantamento', 'grupo3' => 'Grupo 3 — Carimbação', 'grupo4' => 'Grupo 4 — Conferência e organização', '' => 'Ainda escolhendo equipe'] as $eq => $rot): ?>
                <div class="reorg-equipe">
                    <h3><?= e($rot) ?> <span class="adm-tabs__count"><?= count($porEquipe[$eq]) ?></span></h3>
                    <?php if (!$porEquipe[$eq]): ?><p class="adm-table__muted">Ninguém conectado.</p><?php endif; ?>
                    <ul>
                        <?php foreach (array_slice($porEquipe[$eq], 0, 12) as $p): ?>
                            <li><strong><?= e($p['nome']) ?></strong> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['id'])) ?></small> <?= reorg_estado_badge($p['estado']) ?>
                                <?php if ($p['mesmo_nome']): ?><span class="adm-status adm-tone--amber" title="Há outra sessão ativa com o mesmo nome">nome repetido</span><?php endif; ?>
                                <br><small><?= e($p['atividade']) ?><?= $p['lotes'] ? ' · ' . e(implode(', ', array_column($p['lotes'], 'nome'))) : '' ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="adm-card adm-panel">
        <div class="adm-panel__header"><h2>Notificações recentes</h2><a class="adm-link" href="<?= e(reorg_url('admin/notificacoes.php')) ?>">Ver todas</a></div>
        <?php if (!$notificacoes): ?><p class="adm-table__muted">Nenhuma notificação.</p><?php endif; ?>
        <ul class="reorg-lista-notif">
            <?php foreach ($notificacoes as $n): ?>
                <li class="<?= $n['lida_em'] ? '' : 'is-nova' ?>"><span><?= e($n['texto']) ?></span><small><?= e(admin_data_hora($n['criado_em'])) ?></small></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<?php if ($periodo): ?>
<section class="adm-card adm-panel" id="novo-lote">
    <details<?= $novo ? ' open' : '' ?>>
        <summary class="reorg-summary"><?= icon('plus') ?> Criar lote</summary>
        <form method="post" class="reorg-form">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="criar_lote">
            <div class="adm-form-grid">
                <label class="adm-field"><span class="adm-field__label">Nome ou identificação <span class="adm-required">*</span></span>
                    <input class="adm-input" name="nome" required maxlength="120" placeholder="Lote 001 — Estante A"></label>
                <label class="adm-field"><span class="adm-field__label">Quantidade prevista de exemplares físicos <span class="adm-required">*</span></span>
                    <input class="adm-input" type="number" name="quantidade" min="1" max="<?= (int)reorg_config('max_exemplares_lote') ?>" required>
                    <span class="adm-field__hint">Livros físicos (cópias), não obras. Pode ser ajustada depois com justificativa.</span></label>
                <label class="adm-field"><span class="adm-field__label">Administrador responsável (Grupo 2)</span>
                    <select class="adm-select" name="responsavel_id"><?= reorg_opcoes($admins, $adminId) ?></select></label>
                <label class="adm-field"><span class="adm-field__label">Prazo (opcional)</span><input class="adm-input" type="date" name="prazo"></label>
                <label class="adm-field"><span class="adm-field__label">Outros administradores autorizados a revisar</span>
                    <select class="adm-select" name="admins[]" multiple size="3"><?= reorg_opcoes($admins, null) ?></select></label>
                <?php foreach (['grupo1' => 'Grupo 1 (levantamento)', 'grupo3' => 'Grupo 3 (carimbação)', 'grupo4' => 'Grupo 4 (conferência)'] as $eq => $rot): ?>
                    <label class="adm-field"><span class="adm-field__label">Participantes do <?= e($rot) ?></span>
                        <select class="adm-select" name="<?= $eq ?>[]" multiple size="3">
                            <?php foreach ($porEquipe[$eq] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['nome'] . ' · ' . Reorganizacao::rotuloSessao($p['id'])) ?></option><?php endforeach; ?>
                        </select>
                        <?php if (!$porEquipe[$eq]): ?><span class="adm-field__hint">Ninguém conectado nesta equipe agora — atribua depois.</span><?php endif; ?></label>
                <?php endforeach; ?>
                <label class="adm-field adm-field--full"><span class="adm-field__label">Instruções específicas do lote (aparecem para os participantes)</span><textarea class="adm-textarea" name="instrucoes" rows="2"></textarea></label>
                <label class="adm-field adm-field--full"><span class="adm-field__label">Observações internas</span><textarea class="adm-textarea" name="observacoes" rows="2"></textarea></label>
            </div>
            <p class="adm-field__hint">Os participantes só veem os lotes atribuídos a eles. A atribuição é sempre manual.</p>
            <button class="adm-button adm-button--primary" type="submit"><?= icon('plus') ?> Criar lote</button>
        </form>
    </details>
</section>
<?php endif; ?>

<?php foreach (['em_andamento' => 'Lotes em andamento', 'pausado' => 'Lotes pausados', 'concluido' => 'Lotes concluídos'] as $st => $titulo): ?>
<section class="adm-card adm-panel" id="lotes-<?= $st ?>">
    <div class="adm-panel__header"><h2><?= e($titulo) ?> <span class="adm-tabs__count"><?= count($porStatus[$st]) ?></span></h2></div>
    <?php if (!$porStatus[$st]): ?>
        <p class="adm-table__muted">Nenhum lote.</p>
    <?php else: ?>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--compact">
            <thead><tr><th>Lote</th><th>Responsável</th><th>Previstos</th><th>Levantamento</th><th>Revisão</th><th>Códigos</th><th>Carimbados</th><th>Conferidos</th><th>Organizados</th><th>Pend.</th><th>Equipe</th></tr></thead>
            <tbody>
            <?php foreach ($porStatus[$st] as $l): $c = $l['contadores']; ?>
                <tr>
                    <td><a class="adm-table__strong" href="<?= e(reorg_url('admin/lote.php?id=' . $l['id'])) ?>"><?= e($l['nome']) ?></a><br><?= reorg_status_lote($l) ?></td>
                    <td><?= e($l['responsavel_nome']) ?></td>
                    <td><?= $c['prevista'] ?></td>
                    <td><?= $c['registrados'] ?>/<?= $c['prevista'] ?><?= reorg_barra($c['registrados'], $c['prevista']) ?></td>
                    <td><?= $c['aguardando_revisao'] ? '<span class="adm-status adm-tone--amber">' . $c['aguardando_revisao'] . ' aguardando</span>' : $c['aprovados'] . ' aprov.' ?></td>
                    <td><?= $c['codigos'] ?></td>
                    <td><?= $c['carimbados'] ?></td>
                    <td><?= $c['conferidos'] ?></td>
                    <td><?= $c['organizados'] ?>/<?= $c['prevista'] ?><?= reorg_barra($c['organizados'], $c['prevista']) ?></td>
                    <td><?= $c['pendencias_abertas'] ? '<span class="adm-status adm-tone--amber">' . $c['pendencias_abertas'] . '</span>' : '0' ?></td>
                    <td><?= (int)$l['participantes'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php reorg_admin_fim();
