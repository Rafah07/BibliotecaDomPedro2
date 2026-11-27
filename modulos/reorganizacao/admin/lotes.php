<?php
/** Endereço antigo da lista de lotes das equipes: os lotes ficam na aba "Lotes" do inventário. */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$inv = reorg_admin_inventario();
admin_redirecionar($inv ? admin_url('inventario-lotes') . '?id=' . (int)$inv['id'] : admin_url('inventario'));
