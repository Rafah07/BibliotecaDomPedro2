<?php
/**
 * Itens do menu administrativo.
 * 'pronta' => true indica uma página já desenvolvida; as demais abrem a tela "Em desenvolvimento".
 * Quando uma página for criada, basta preencher 'arquivo' e marcar 'pronta' => true.
 */
const ADMIN_MENU = [
    'Principal' => [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'arquivo' => 'index.php', 'pronta' => true],
        'acervo' => ['label' => 'Acervo', 'icon' => 'library-big', 'arquivo' => 'livros.php', 'pronta' => true],
        'exemplares' => ['label' => 'Exemplares', 'icon' => 'book-copy', 'arquivo' => 'exemplares.php', 'pronta' => true],
        'categorias' => ['label' => 'Categorias', 'icon' => 'tags', 'arquivo' => 'categorias.php', 'pronta' => true],
        // Páginas internas do acervo: não aparecem no menu, mas usam admin_url().
        'cadastrar-livro' => ['label' => 'Cadastrar livro', 'icon' => 'book-plus', 'arquivo' => 'cadastrar-livro.php', 'pronta' => true, 'oculto' => true],
        'editar-livro' => ['label' => 'Editar livro', 'icon' => 'pencil', 'arquivo' => 'editar-livro.php', 'pronta' => true, 'oculto' => true],
        'detalhes-livro' => ['label' => 'Detalhes do livro', 'icon' => 'book-open', 'arquivo' => 'detalhes-livro.php', 'pronta' => true, 'oculto' => true],
        'detalhes-exemplar' => ['label' => 'Detalhes do exemplar', 'icon' => 'book-copy', 'arquivo' => 'detalhes-exemplar.php', 'pronta' => true, 'oculto' => true],
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

/**
 * Caminho da pasta admin/ no servidor (ex.: "/admin/" ou "/BibliotecaDomPedro2/admin/").
 * Evita que CSS, JS e links quebrem quando a página é aberta como ".../admin" sem a barra final.
 */
function admin_base(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'));
    return rtrim($dir, '/') . '/';
}

/** Endereço de um arquivo dentro de admin/ (CSS, JS, páginas). URLs externas ficam como estão. */
function admin_asset(string $path): string
{
    return preg_match('#^https?://#', $path) ? $path : admin_base() . $path;
}

/** Endereço de uma página administrativa a partir do id do menu. */
function admin_url(string $id): string
{
    $item = admin_menu_item($id);
    if ($item && !empty($item['pronta'])) {
        return admin_base() . $item['arquivo'];
    }
    return admin_base() . 'em-desenvolvimento.php?pagina=' . rawurlencode($id);
}
