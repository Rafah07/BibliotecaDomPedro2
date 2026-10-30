<?php
require_once __DIR__ . '/../../config/app.php';

/**
 * Itens do menu administrativo.
 * 'pronta' => true indica uma página já desenvolvida; as demais abrem a tela "Em desenvolvimento".
 * Quando uma página for criada, basta preencher 'arquivo' e marcar 'pronta' => true.
 */
const ADMIN_MENU = [
    'Principal' => [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'arquivo' => 'index.php', 'pronta' => true],
        'acervo' => ['label' => 'Acervo', 'icon' => 'library-big', 'arquivo' => 'livros.php', 'pronta' => true],
        // Submenu Acervo → Classificação CDD (Etapa 4).
        'classificacao' => ['label' => 'Classificação CDD', 'icon' => 'list-tree', 'arquivo' => 'classificacao.php', 'pronta' => true, 'pai' => 'acervo'],
        'classificar-livro' => ['label' => 'Classificar livro', 'icon' => 'book-marked', 'arquivo' => 'classificar-livro.php', 'pronta' => true, 'oculto' => true],
        'revisao-cdd' => ['label' => 'Fila de aprovação CDD', 'icon' => 'list-checks', 'arquivo' => 'revisao-cdd.php', 'pronta' => true, 'oculto' => true],
        'historico-cdd' => ['label' => 'Histórico CDD', 'icon' => 'history', 'arquivo' => 'historico-cdd.php', 'pronta' => true, 'oculto' => true],
        'relatorio-cdd' => ['label' => 'Relatórios CDD', 'icon' => 'file-chart-column', 'arquivo' => 'relatorio-cdd.php', 'pronta' => true, 'oculto' => true],
        'exemplares' => ['label' => 'Exemplares', 'icon' => 'book-copy', 'arquivo' => 'exemplares.php', 'pronta' => true],
        'categorias' => ['label' => 'Categorias', 'icon' => 'tags', 'arquivo' => 'categorias.php', 'pronta' => true],
        // Páginas internas do acervo: não aparecem no menu, mas usam admin_url().
        'cadastrar-livro' => ['label' => 'Cadastrar livro', 'icon' => 'book-plus', 'arquivo' => 'cadastrar-livro.php', 'pronta' => true, 'oculto' => true],
        'editar-livro' => ['label' => 'Editar livro', 'icon' => 'pencil', 'arquivo' => 'editar-livro.php', 'pronta' => true, 'oculto' => true],
        'arquivar-livro' => ['label' => 'Arquivar livro', 'icon' => 'archive', 'arquivo' => 'arquivar-livro.php', 'pronta' => true, 'oculto' => true],
        'detalhes-livro' => ['label' => 'Detalhes do livro', 'icon' => 'book-open', 'arquivo' => 'detalhes-livro.php', 'pronta' => true, 'oculto' => true],
        'detalhes-exemplar' => ['label' => 'Detalhes do exemplar', 'icon' => 'book-copy', 'arquivo' => 'detalhes-exemplar.php', 'pronta' => true, 'oculto' => true],
        'inventario' => ['label' => 'Inventário', 'icon' => 'clipboard-check'],
    ],
    'Circulação' => [
        // Circulação: só para quem pode ver empréstimos (dados pessoais dos alunos).
        'emprestimos' => ['label' => 'Empréstimos', 'icon' => 'book-open-check', 'arquivo' => 'emprestimos.php', 'pronta' => true, 'permissao' => 'emprestimos.ver'],
        'novo-emprestimo' => ['label' => 'Novo empréstimo', 'icon' => 'plus', 'arquivo' => 'novo-emprestimo.php', 'pronta' => true, 'oculto' => true],
        'emprestimo' => ['label' => 'Detalhes do empréstimo', 'icon' => 'book-open-check', 'arquivo' => 'emprestimo.php', 'pronta' => true, 'oculto' => true],
        'devolver-emprestimo' => ['label' => 'Registrar devolução', 'icon' => 'rotate-ccw', 'arquivo' => 'devolver-emprestimo.php', 'pronta' => true, 'oculto' => true],
        'renovar-emprestimo' => ['label' => 'Renovar empréstimo', 'icon' => 'refresh-cw', 'arquivo' => 'renovar-emprestimo.php', 'pronta' => true, 'oculto' => true],
        'devolucoes' => ['label' => 'Devoluções', 'icon' => 'rotate-ccw', 'arquivo' => 'devolucoes.php', 'pronta' => true, 'permissao' => 'emprestimos.ver'],
        'atrasos' => ['label' => 'Atrasos', 'icon' => 'clock-alert', 'arquivo' => 'atrasos.php', 'pronta' => true, 'permissao' => 'emprestimos.ver'],
        'historico-aluno' => ['label' => 'Histórico do aluno', 'icon' => 'graduation-cap', 'arquivo' => 'historico-aluno.php', 'pronta' => true, 'oculto' => true],
        'historico-exemplar' => ['label' => 'Histórico do exemplar', 'icon' => 'history', 'arquivo' => 'historico-exemplar.php', 'pronta' => true, 'oculto' => true],
    ],
    'Gerenciamento' => [
        'importar' => ['label' => 'Importar Excel', 'icon' => 'file-spreadsheet', 'arquivo' => 'importacao.php', 'pronta' => true],
        'historico-importacoes' => ['label' => 'Histórico de importações', 'icon' => 'history', 'arquivo' => 'historico-importacoes.php', 'pronta' => true, 'oculto' => true],
        'detalhes-importacao' => ['label' => 'Detalhes da importação', 'icon' => 'file-spreadsheet', 'arquivo' => 'detalhes-importacao.php', 'pronta' => true, 'oculto' => true],
        'isbn-invalidos' => ['label' => 'ISBNs inválidos', 'icon' => 'barcode', 'arquivo' => 'isbn-invalidos.php', 'pronta' => true, 'oculto' => true],
        'etiquetas' => ['label' => 'Etiquetas', 'icon' => 'scan-barcode'],
        'alunos' => ['label' => 'Alunos', 'icon' => 'graduation-cap'],
        'importar-alunos' => ['label' => 'Importar Alunos', 'icon' => 'users', 'arquivo' => 'importar-alunos.php', 'pronta' => true, 'permissao' => 'alunos.importar'],
        'relatorios' => ['label' => 'Relatórios', 'icon' => 'chart-column'],
        'atividades' => ['label' => 'Atividades', 'icon' => 'history'],
    ],
    'Sistema' => [
        // 'permissao': o item só aparece para quem tem a permissão (a página confere de novo no servidor).
        'administradores' => ['label' => 'Administradores', 'icon' => 'users', 'arquivo' => 'administradores.php', 'pronta' => true, 'permissao' => 'usuarios.gerenciar'],
        'administrador' => ['label' => 'Conta de acesso', 'icon' => 'user-round', 'arquivo' => 'administrador.php', 'pronta' => true, 'oculto' => true],
        'configuracoes' => ['label' => 'Configurações', 'icon' => 'settings'],
        'perfil' => ['label' => 'Meu perfil', 'icon' => 'user-round', 'arquivo' => 'perfil.php', 'pronta' => true, 'oculto' => true],
        'site' => ['label' => 'Voltar ao site', 'icon' => 'house', 'url' => '', 'pronta' => true],
        'sair' => ['label' => 'Sair', 'icon' => 'log-out', 'acao' => 'logout'],
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
 * Caminho da pasta admin/ no navegador (ex.: "/admin/" ou "/BibliotecaDomPedro2/admin/").
 * Calculado a partir da raiz da aplicação (config/app.php), funciona também nas subpastas de admin/.
 */
function admin_base(): string
{
    return url('admin/');
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
    if ($item && isset($item['url'])) {
        return url($item['url']); // página fora do painel (ex.: site público)
    }
    if ($item && !empty($item['pronta'])) {
        return admin_base() . $item['arquivo'];
    }
    return admin_base() . 'em-desenvolvimento.php?pagina=' . rawurlencode($id);
}
