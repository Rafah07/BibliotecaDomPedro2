<?php
/** Central de Reorganização → Lotes: todos os lotes temporários, com filtros e acesso ao ambiente de cada um. */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$r = reorg();

$status = in_array($_GET['status'] ?? '', ['em_andamento', 'pausado', 'concluido'], true) ? $_GET['status'] : '';
$responsavel = (int)($_GET['responsavel'] ?? 0);
$etapa = isset(REORG_ETAPAS[$_GET['etapa'] ?? '']) ? $_GET['etapa'] : '';
$lotes = $r->lotes($status, $responsavel, $etapa);
$admins = reorg_administradores();
$periodo = $r->periodoAtivo();

reorg_admin_inicio('lotes', 'Lotes da reorganização');
?>
<section class="adm-page-head reorg-head">
    <div><h1>Lotes</h1><p>Cada lote reúne os exemplares físicos de um trecho do acervo. Um lote pode ter exemplares em várias etapas ao mesmo tempo.</p></div>
    <div class="adm-page-head__actions">
        <?php if ($periodo): ?><a class="adm-button adm-button--primary" href="<?= e(reorg_url('admin/lote-novo.php')) ?>"><?= icon('plus') ?> Criar novo lote</a><?php endif; ?>
    </div>
</section>

<form class="adm-card reorg-filtros-barra" method="get" role="search" aria-label="Filtrar lotes">
    <label class="adm-field"><span class="adm-field__label">Situação</span>
        <select class="adm-select" name="status"><option value="">Todas</option><?php foreach (['em_andamento' => 'Em andamento', 'pausado' => 'Pausado', 'concluido' => 'Concluído'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Administrador responsável</span>
        <select class="adm-select" name="responsavel"><option value="">Todos</option><?= reorg_opcoes($admins, $responsavel ?: '') ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Etapa com trabalho esperando</span>
        <select class="adm-select" name="etapa"><option value="">Todas</option><?php foreach (REORG_ETAPAS as $k => [$g, $rot]): ?><option value="<?= $k ?>"<?= $etapa === $k ? ' selected' : '' ?>><?= e($g . ' — ' . $rot) ?></option><?php endforeach; ?></select></label>
    <div class="reorg-filtros-barra__acoes"><button class="adm-button" type="submit"><?= icon('filter') ?> Filtrar</button>
        <?php if ($status || $responsavel || $etapa): ?><a class="adm-link" href="<?= e(reorg_url('admin/lotes.php')) ?>">Limpar</a><?php endif; ?></div>
</form>

<section class="adm-card adm-panel">
    <?php if (!$lotes): ?>
        <p class="adm-table__muted"><?= $status || $responsavel || $etapa ? 'Nenhum lote com estes filtros.' : 'Nenhum lote criado ainda.' ?></p>
    <?php else: ?>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table reorg-tabela-lotes">
            <thead><tr><th>Lote</th><th>Responsável</th><th class="reorg-num">Previstos</th><th>Progresso (organizados)</th><th>Etapas com trabalho</th><th>Participantes</th><th class="reorg-num">Pend.</th><th>Situação</th><th><span class="adm-sr-only">Ação</span></th></tr></thead>
            <tbody>
            <?php foreach ($lotes as $l): $c = $l['contadores']; $url = reorg_url('admin/lote.php?id=' . $l['id']); ?>
                <tr>
                    <td><a class="adm-table__strong" href="<?= e($url) ?>"><?= e($l['nome']) ?></a><br><small class="adm-table__muted">Criado em <?= e(admin_data_hora($l['criado_em'], false)) ?></small></td>
                    <td><?= e($l['responsavel_nome']) ?></td>
                    <td class="reorg-num"><?= $c['prevista'] ?></td>
                    <td class="reorg-progresso-celula"><span><?= $c['organizados'] ?>/<?= $c['prevista'] ?> · <?= $c['percentual'] ?>%</span><?= reorg_barra($c['organizados'], $c['prevista']) ?></td>
                    <td><?= reorg_etapas_html($l['etapas']) ?></td>
                    <td><small><?php $pe = []; foreach (['grupo1' => 'G1', 'grupo3' => 'G3', 'grupo4' => 'G4'] as $eq => $g) { $pe[] = $g . ': ' . (int)($l['por_equipe'][$eq] ?? 0); } echo e(implode(' · ', $pe)); ?></small></td>
                    <td class="reorg-num"><?= $c['pendencias_abertas'] ? '<span class="adm-status adm-tone--amber">' . $c['pendencias_abertas'] . '</span>' : '0' ?></td>
                    <td><?= reorg_status_lote($l) ?></td>
                    <td><a class="adm-button adm-button--sm" href="<?= e($url) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php reorg_admin_fim();
