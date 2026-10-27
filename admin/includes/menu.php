<?php
/**
 * Itens do menu administrativo.
 * 'pronta' => true indica uma página já desenvolvida; as demais abrem a tela "Em desenvolvimento".
 * Quando uma página for criada, basta preencher 'arquivo' e marcar 'pronta' => true.
 */
const ADMIN_MENU = [
    'Principal' => [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'arquivo' => 'index.php', 'pronta' => true],
        'acervo' => ['label' => 'Acervo', 'icon' => 'library-big'],
        'exemplares' => ['label' => 'Exemplares', 'icon' => 'book-copy'],
        'inventario' => ['label' => 'Inventário', 'icon' => 'clipboard-check'],
    ],
    'Circulação' => [
        'emprestimos' => ['label' => 'Empréstimos', 'icon' => 'book-open-check'],
        'devolucoes' => ['label' => 'Devoluções', 'icon' => 'rotate-ccw'],
        'atrasos' => ['label' => 'Atrasos', 'icon' => 'clock-alert'],
    ],
    'Gerenciamento' => [
        'importar' => ['label' => 'Importar Excel', 'icon' => 'file-spreadsheet'],
        'etiquetas' => ['label' => 'Etiquetas', 'icon' => 'scan-barcode'],
        'alunos' => ['label' => 'Alunos', 'icon' => 'graduation-cap'],
        'relatorios' => ['label' => 'Relatórios', 'icon' => 'chart-column'],
        'atividades' => ['label' => 'Atividades', 'icon' => 'history'],
    ],
    'Sistema' => [
        'configuracoes' => ['label' => 'Configurações', 'icon' => 'settings'],
        'perfil' => ['label' => 'Meu perfil', 'icon' => 'user-round', 'oculto' => true],
        'site' => ['label' => 'Voltar ao site', 'icon' => 'house', 'arquivo' => '../Index/Index.php', 'pronta' => true],
        'sair' => ['label' => 'Sair', 'icon' => 'log-out', 'demo' => 'logout'],
    ],
];

/** Procura um item do menu pelo id. */
function admin_menu_item(string $id): ?array
{
    foreach (ADMIN_MENU as $itens) {
        if (isset($itens[$id])) {
            return $itens[$id];
        }
    }
    return null;
}

/** Endereço de uma página administrativa a partir do id do menu. */
function admin_url(string $id): string
{
    $item = admin_menu_item($id);
    if ($item && !empty($item['pronta'])) {
        return $item['arquivo'];
    }
    return 'em-desenvolvimento.php?pagina=' . rawurlencode($id);
}
