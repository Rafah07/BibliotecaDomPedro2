<?php
/** Endereço antigo: o período agora fica em Configurações. */
require __DIR__ . '/../includes/admin.php';
reorg_admin_usuario();
admin_redirecionar(reorg_url('admin/configuracoes.php#periodo'));
