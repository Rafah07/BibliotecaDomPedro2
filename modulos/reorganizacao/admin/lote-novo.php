<?php
/** Endereço antigo de "Criar lote": todos os lotes são criados no formulário único do inventário. */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$inv = reorg_admin_inventario();
admin_redirecionar(admin_url('lote-novo') . ($inv ? '?inventario=' . (int)$inv['id'] : ''));
