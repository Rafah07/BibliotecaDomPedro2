<?php
/**
 * Endereço antigo da Central de Reorganização. A organização por equipes fica dentro do Inventário:
 * abre a Visão geral do inventário das equipes ativas (ou a lista de inventários).
 */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$inv = reorg_admin_inventario();
admin_redirecionar($inv ? admin_url('inventario-detalhes') . '?id=' . (int)$inv['id'] : admin_url('inventario'));
