<?php
/** Endereço antigo: as pendências agora ficam em “Solicitações e pendências”. */
require __DIR__ . '/../includes/admin.php';
reorg_admin_usuario();
admin_redirecionar(reorg_url('admin/solicitacoes.php?' . http_build_query(array_filter(['ver' => 'pendencias', 'lote' => (int)($_GET['lote'] ?? 0) ?: null, 'tipo' => $_GET['tipo'] ?? null]))));
