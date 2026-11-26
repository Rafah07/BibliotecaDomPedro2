<?php
/** Endereço antigo: os relatórios e exportações agora ficam em Histórico (e no ambiente de cada lote). */
require __DIR__ . '/../includes/admin.php';
reorg_admin_usuario();
$lote = (int)($_GET['lote'] ?? 0);
$exportar = (string)($_GET['exportar'] ?? '');
$exportar = in_array($exportar, ['lotes', 'producao', 'exemplares'], true) ? $exportar : null;
$destino = $lote && !$exportar ? 'lote.php?id=' . $lote : 'historico.php?' . http_build_query(array_filter(['lote_id' => $lote ?: null, 'exportar' => $exportar]));
admin_redirecionar(reorg_url('admin/' . $destino));
