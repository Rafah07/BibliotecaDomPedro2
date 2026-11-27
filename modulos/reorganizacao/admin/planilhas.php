<?php
/** Endereço antigo: a Planilha BDP de cada lote fica na aba “Grupo 3 — Carimbação” do lote. */
require __DIR__ . '/../includes/admin.php';
reorg_admin_usuario();
admin_redirecionar(reorg_url('admin/lotes.php'));
