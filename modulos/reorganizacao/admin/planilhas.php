<?php
/** Planilhas BDP dos lotes: usa a Planilha de Códigos BDP permanente (nenhuma numeração paralela). */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$lotes = reorg()->lotes();
reorg_admin_inicio('planilhas', 'Planilhas BDP dos lotes');
?>
<section class="adm-page-head"><div><h1>Planilhas BDP dos lotes</h1><p>Cada lote da reorganização tem um lote permanente de cadastro. Os códigos BDP são gerados pela sequência única do sistema e aparecem na Planilha de Códigos BDP, com versões, impressão e exportação.</p></div></section>
<section class="adm-card adm-panel">
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Lote da reorganização</th><th>Lote permanente</th><th>Códigos gerados</th><th>Carimbados</th><th></th></tr></thead>
        <tbody>
        <?php if (!$lotes): ?><tr><td colspan="5" class="adm-table__muted">Nenhum lote criado.</td></tr><?php endif; ?>
        <?php foreach ($lotes as $l): ?>
            <tr><td><a href="<?= e(reorg_url('admin/lote.php?id=' . $l['id'])) ?>"><?= e($l['nome']) ?></a></td><td>Lote <?= e(str_pad((string)$l['cadastro_numero'], 3, '0', STR_PAD_LEFT)) ?></td>
                <td><?= $l['contadores']['codigos'] ?></td><td><?= $l['contadores']['carimbados'] ?></td>
                <td class="reorg-acoes"><a class="adm-button adm-button--sm" href="<?= e(url('admin/planilha-bdp.php?aba=todos&lote=' . $l['cadastro_lote_id'])) ?>"><?= icon('stamp') ?> Planilha BDP</a>
                    <a class="adm-button adm-button--sm" href="<?= e(url('admin/planilha-bdp-versoes.php?lote=' . $l['cadastro_lote_id'])) ?>">Versões</a>
                    <a class="adm-button adm-button--sm" href="<?= e(url('admin/lote.php?id=' . $l['cadastro_lote_id'])) ?>">Relatório do lote permanente</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php reorg_admin_fim();
