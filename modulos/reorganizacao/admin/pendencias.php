<?php
/** Central de pendências: filtro por lote, tipo e situação; análise e resolução administrativa. */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reorg_admin_acao(fn () => $r->atualizarPendencia((int)$_POST['id'], (string)$_POST['status'], $adminId, (string)($_POST['resolucao'] ?? '')), 'Pendência atualizada.');
}
$status = (string)($_GET['status'] ?? 'abertas');
$status = in_array($status, ['abertas', 'aberta', 'em_analise', 'resolvida', ''], true) ? $status : 'abertas';
$loteId = (int)($_GET['lote'] ?? 0);
$tipo = (string)($_GET['tipo'] ?? '');
$tipo = isset(Reorganizacao::TIPOS_PENDENCIA[$tipo]) ? $tipo : '';
$lista = $r->pendencias($status, $loteId, $tipo);
$lotes = $r->lotes();

reorg_admin_inicio('pendencias', 'Pendências');
?>
<section class="adm-page-head"><div><h1>Pendências</h1><p>Problemas informados pelas equipes ou pela administração. Exemplares com pendência aberta aparecem “com problema” e impedem a conclusão do lote.</p></div></section>
<form class="adm-card adm-filters" method="get">
    <label class="adm-field"><span class="adm-field__label">Situação</span><select class="adm-select" name="status" onchange="this.form.submit()">
        <?php foreach (['abertas' => 'Abertas e em análise', 'aberta' => 'Abertas', 'em_analise' => 'Em análise', 'resolvida' => 'Resolvidas', '' => 'Todas'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Lote</span><select class="adm-select" name="lote" onchange="this.form.submit()"><option value="0">Todos</option><?= reorg_opcoes($lotes, $loteId) ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Tipo</span><select class="adm-select" name="tipo" onchange="this.form.submit()"><option value="">Todos</option>
        <?php foreach (Reorganizacao::TIPOS_PENDENCIA as $k => $v): ?><option value="<?= $k ?>"<?= $tipo === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
</form>
<section class="adm-card adm-panel">
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Nº</th><th>Lote</th><th>Tipo</th><th>Descrição</th><th>Exemplar / registro</th><th>Informado por</th><th>Situação</th><th>Ação</th></tr></thead>
        <tbody>
        <?php if (!$lista): ?><tr><td colspan="8" class="adm-table__muted">Nenhuma pendência.</td></tr><?php endif; ?>
        <?php foreach ($lista as $p): ?>
            <tr><td><?= (int)$p['id'] ?></td>
                <td><a href="<?= e(reorg_url('admin/lote.php?id=' . $p['lote_id'])) ?>"><?= e($p['lote_nome']) ?></a></td>
                <td><?= e(Reorganizacao::TIPOS_PENDENCIA[$p['tipo']]) ?><br><small><?= e(Reorganizacao::EQUIPES[$p['grupo']] ?? $p['grupo']) ?></small></td>
                <td><?= e($p['descricao']) ?><?= $p['resolucao'] ? '<br><small><strong>Resolução:</strong> ' . e($p['resolucao']) . '</small>' : '' ?></td>
                <td class="adm-mono"><?= e($p['codigo_individual'] ?? '') ?><?= $p['registro_numero'] ? ' <a href="' . e(reorg_url('admin/registro.php?id=' . $p['registro_id'])) . '">reg. ' . (int)$p['registro_numero'] . '</a>' : '' ?></td>
                <td><small><?= e($p['nome'] ?? $p['admin_nome'] ?? '') ?><br><?= e(admin_data_hora($p['criado_em'])) ?></small></td>
                <td><?= reorg_status_badge(['aberta' => 'Aberta', 'em_analise' => 'Em análise', 'resolvida' => 'Resolvida'][$p['status']], ['aberta' => 'red', 'em_analise' => 'amber', 'resolvida' => 'green'][$p['status']]) ?>
                    <?= $p['resolvida_por_nome'] ? '<br><small>' . e($p['resolvida_por_nome']) . '</small>' : '' ?></td>
                <td><?php if ($p['status'] !== 'resolvida'): ?>
                    <form method="post" class="reorg-mini-form"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <input class="adm-input" name="resolucao" placeholder="Resolução" maxlength="1000">
                        <button class="adm-button adm-button--sm adm-button--primary" name="status" value="resolvida">Resolver</button>
                        <?php if ($p['status'] === 'aberta'): ?><button class="adm-button adm-button--sm" name="status" value="em_analise">Em análise</button><?php endif; ?>
                    </form>
                <?php else: ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="adm-button adm-button--sm" name="status" value="aberta">Reabrir</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php reorg_admin_fim();
