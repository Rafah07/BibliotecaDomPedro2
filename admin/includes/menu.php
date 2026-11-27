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
        // Gestão permanente do acervo (migração 190): qualidade dos dados e novas entradas.
        'qualidade' => ['label' => 'Qualidade do acervo', 'icon' => 'sparkles', 'arquivo' => 'qualidade.php', 'pronta' => true, 'pai' => 'acervo', 'permissao' => 'qualidade.ver'],
        'aquisicoes' => ['label' => 'Aquisições', 'icon' => 'inbox', 'arquivo' => 'aquisicoes.php', 'pronta' => true, 'pai' => 'acervo'],
        'classificar-livro' => ['label' => 'Classificar livro', 'icon' => 'book-marked', 'arquivo' => 'classificar-livro.php', 'pronta' => true, 'oculto' => true],
        'revisao-cdd' => ['label' => 'Fila de aprovação CDD', 'icon' => 'list-checks', 'arquivo' => 'revisao-cdd.php', 'pronta' => true, 'oculto' => true],
        'historico-cdd' => ['label' => 'Histórico CDD', 'icon' => 'history', 'arquivo' => 'historico-cdd.php', 'pronta' => true, 'oculto' => true],
        'relatorio-cdd' => ['label' => 'Relatórios CDD', 'icon' => 'file-chart-column', 'arquivo' => 'relatorio-cdd.php', 'pronta' => true, 'oculto' => true],
        'exemplares' => ['label' => 'Exemplares', 'icon' => 'book-copy', 'arquivo' => 'exemplares.php', 'pronta' => true],
        // Preparo dos exemplares (migração 150): carimbo, triagem, lotes, ocorrências e ações reversíveis.
        'planilha-bdp' => ['label' => 'Planilha de Códigos BDP', 'icon' => 'stamp', 'arquivo' => 'planilha-bdp.php', 'pronta' => true, 'pai' => 'exemplares'],
        'ocorrencias' => ['label' => 'Ocorrências e Conservação', 'icon' => 'triangle-alert', 'arquivo' => 'ocorrencias.php', 'pronta' => true, 'pai' => 'exemplares'],
        'acoes-recentes' => ['label' => 'Ações recentes (desfazer)', 'icon' => 'undo-2', 'arquivo' => 'acoes-recentes.php', 'pronta' => true, 'pai' => 'exemplares'],
        'baixas' => ['label' => 'Baixas do acervo', 'icon' => 'package-x', 'arquivo' => 'baixas.php', 'pronta' => true, 'pai' => 'exemplares'],
        'planilha-bdp-versoes' => ['label' => 'Versões da Planilha BDP', 'icon' => 'file-text', 'arquivo' => 'planilha-bdp-versoes.php', 'pronta' => true, 'oculto' => true],
        'cadastrar-exemplar' => ['label' => 'Cadastrar exemplar', 'icon' => 'book-plus', 'arquivo' => 'cadastrar-exemplar.php', 'pronta' => true, 'oculto' => true],
        // Organização Física (migração 140): setores, estantes, prateleiras, responsáveis e etiquetas.
        'organizacao-fisica' => ['label' => 'Organização Física', 'icon' => 'columns-3', 'arquivo' => 'organizacao-fisica.php', 'pronta' => true],
        'organizacao-etiquetas' => ['label' => 'Etiquetas das estantes', 'icon' => 'printer', 'arquivo' => 'organizacao-etiquetas.php', 'pronta' => true, 'oculto' => true],
        'movimentacao-lote' => ['label' => 'Movimentação em lote', 'icon' => 'move', 'arquivo' => 'movimentacao-lote.php', 'pronta' => true, 'pai' => 'organizacao-fisica'],
        'organizacao-mapa' => ['label' => 'Mapa das estantes', 'icon' => 'map', 'arquivo' => 'organizacao-mapa.php', 'pronta' => true, 'pai' => 'organizacao-fisica'],
        'localizacoes' => ['label' => 'Localizações anteriores', 'icon' => 'map-pin', 'arquivo' => 'localizacoes.php', 'pronta' => true, 'pai' => 'organizacao-fisica'],
        'localizacao' => ['label' => 'Localização', 'icon' => 'map-pin', 'arquivo' => 'localizacao.php', 'pronta' => true, 'oculto' => true],
        'categorias' => ['label' => 'Categorias', 'icon' => 'tags', 'arquivo' => 'categorias.php', 'pronta' => true],
        'categoria' => ['label' => 'Categoria', 'icon' => 'tags', 'arquivo' => 'categoria.php', 'pronta' => true, 'oculto' => true],
        // Páginas internas do acervo: não aparecem no menu, mas usam admin_url().
        'cadastrar-livro' => ['label' => 'Cadastrar livro', 'icon' => 'book-plus', 'arquivo' => 'cadastrar-livro.php', 'pronta' => true, 'oculto' => true],
        'editar-livro' => ['label' => 'Editar livro', 'icon' => 'pencil', 'arquivo' => 'editar-livro.php', 'pronta' => true, 'oculto' => true],
        'arquivar-livro' => ['label' => 'Arquivar livro', 'icon' => 'archive', 'arquivo' => 'arquivar-livro.php', 'pronta' => true, 'oculto' => true],
        'detalhes-livro' => ['label' => 'Detalhes do livro', 'icon' => 'book-open', 'arquivo' => 'detalhes-livro.php', 'pronta' => true, 'oculto' => true],
        'detalhes-exemplar' => ['label' => 'Detalhes do exemplar', 'icon' => 'book-copy', 'arquivo' => 'detalhes-exemplar.php', 'pronta' => true, 'oculto' => true],
        // Inventário digital (Etapa 3 do inventário físico).
        'inventario' => ['label' => 'Inventário', 'icon' => 'clipboard-check', 'arquivo' => 'inventario.php', 'pronta' => true],
        'inventario-editar' => ['label' => 'Inventário', 'icon' => 'clipboard-check', 'arquivo' => 'inventario-editar.php', 'pronta' => true, 'oculto' => true],
        'inventario-detalhes' => ['label' => 'Inventário', 'icon' => 'clipboard-check', 'arquivo' => 'inventario-detalhes.php', 'pronta' => true, 'oculto' => true],
        'inventario-conferencia' => ['label' => 'Conferência', 'icon' => 'keyboard', 'arquivo' => 'inventario-conferencia.php', 'pronta' => true, 'oculto' => true],
        // Gerenciamento de lotes e etapas de trabalho (migração 160).
        'lotes' => ['label' => 'Histórico de Lotes', 'icon' => 'layers', 'arquivo' => 'lotes.php', 'pronta' => true, 'pai' => 'inventario'],
        'lote' => ['label' => 'Relatório do lote', 'icon' => 'layers', 'arquivo' => 'lote.php', 'pronta' => true, 'oculto' => true],
        // Lotes por inventário (migração 240): formulário único de criação e aba "Lotes" do inventário.
        'lote-novo' => ['label' => 'Novo lote', 'icon' => 'plus', 'arquivo' => 'lote-novo.php', 'pronta' => true, 'oculto' => true],
        'inventario-lotes' => ['label' => 'Lotes do inventário', 'icon' => 'layers', 'arquivo' => 'inventario-lotes.php', 'pronta' => true, 'oculto' => true],
        // Equipes por link (migração 210): revisão dos pré-cadastros, aberta pela página do lote.
        'coleta' => ['label' => 'Revisão da coleta', 'icon' => 'list-checks', 'arquivo' => 'coleta.php', 'pronta' => true, 'oculto' => true],
        'lotes-produtividade' => ['label' => 'Relatório de Produtividade', 'icon' => 'chart-column', 'arquivo' => 'lotes-produtividade.php', 'pronta' => true, 'pai' => 'inventario', 'permissao' => 'lotes.gerenciar'],
        'duvidas' => ['label' => 'Dúvidas da conferência', 'icon' => 'circle-help', 'arquivo' => 'duvidas.php', 'pronta' => true, 'pai' => 'inventario'],
        'inventario-comparacao' => ['label' => 'Comparação com planilha', 'icon' => 'git-compare', 'arquivo' => 'inventario-comparacao.php', 'pronta' => true, 'pai' => 'inventario'],
        'inventario-relatorio' => ['label' => 'Relatório do inventário', 'icon' => 'file-chart-column', 'arquivo' => 'inventario-relatorio.php', 'pronta' => true, 'oculto' => true],
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
        'etiquetas' => ['label' => 'Etiquetas', 'icon' => 'tag', 'arquivo' => 'etiquetas.php', 'pronta' => true],
        'etiqueta-modelos' => ['label' => 'Modelos de etiqueta', 'icon' => 'ruler', 'arquivo' => 'etiqueta-modelos.php', 'pronta' => true, 'oculto' => true],
        // Alunos (Etapa 11): consulta, cadastro individual e correção. Dados pessoais: só com alunos.ver.
        'alunos' => ['label' => 'Alunos', 'icon' => 'graduation-cap', 'arquivo' => 'alunos.php', 'pronta' => true, 'permissao' => 'alunos.ver'],
        'aluno' => ['label' => 'Detalhes do aluno', 'icon' => 'graduation-cap', 'arquivo' => 'aluno.php', 'pronta' => true, 'oculto' => true],
        'aluno-editar' => ['label' => 'Cadastro do aluno', 'icon' => 'user-plus', 'arquivo' => 'aluno-editar.php', 'pronta' => true, 'oculto' => true],
        'importar-alunos' => ['label' => 'Importar Alunos', 'icon' => 'users', 'arquivo' => 'importar-alunos.php', 'pronta' => true, 'permissao' => 'alunos.importar'],
        // Central de relatórios (Etapa 5). "Atividades" abre a seção de atividades da mesma página.
        'relatorios' => ['label' => 'Relatórios', 'icon' => 'chart-column', 'arquivo' => 'relatorios.php', 'pronta' => true, 'permissao' => 'relatorios.ver'],
        'relatorio-circulacao' => ['label' => 'Circulação do acervo', 'icon' => 'book-open-check', 'arquivo' => 'relatorio-circulacao.php', 'pronta' => true, 'pai' => 'relatorios', 'permissao' => 'relatorios.ver'],
        'relatorio-atividades' => ['label' => 'Relatório de Atividades', 'icon' => 'activity', 'arquivo' => 'relatorio-atividades.php', 'pronta' => true, 'permissao' => 'relatorios.ver'],
        'atividades' => ['label' => 'Atividades', 'icon' => 'history', 'arquivo' => 'relatorios.php?secao=atividades', 'pronta' => true, 'permissao' => 'relatorios.atividades'],
    ],
    'Sistema' => [
        // 'permissao': o item só aparece para quem tem a permissão (a página confere de novo no servidor).
        'administradores' => ['label' => 'Administradores', 'icon' => 'users', 'arquivo' => 'administradores.php', 'pronta' => true, 'permissao' => 'usuarios.gerenciar'],
        'administrador' => ['label' => 'Administrador', 'icon' => 'user-round', 'arquivo' => 'administrador.php', 'pronta' => true, 'oculto' => true],
        'site-publico' => ['label' => 'Site público', 'icon' => 'globe', 'arquivo' => 'site-publico.php', 'pronta' => true, 'permissao' => 'site.editar'],
        'capa-livro' => ['label' => 'Capa do livro', 'icon' => 'image', 'arquivo' => 'capa-livro.php', 'pronta' => true, 'oculto' => true],
        // Configurações gerais e manutenção (Etapa 6). "Perfis e permissões" saiu na migração 200 (único perfil).
        'configuracoes' => ['label' => 'Configurações', 'icon' => 'settings', 'arquivo' => 'configuracoes.php', 'pronta' => true, 'permissao' => 'configuracoes.ver'],
        'manutencao' => ['label' => 'Manutenção e backups', 'icon' => 'wrench', 'arquivo' => 'manutencao.php', 'pronta' => true, 'pai' => 'configuracoes', 'permissao' => 'sistema.manutencao'],
        'diagnostico' => ['label' => 'Diagnóstico do sistema', 'icon' => 'activity', 'arquivo' => 'diagnostico.php', 'pronta' => true, 'pai' => 'configuracoes', 'permissao' => 'sistema.manutencao'],
        // Central de Ajuda (Etapa 10): agrupada em Configurações, mas aberta a todos os perfis (painel.ver),
        // inclusive a quem não pode ver as configurações. Também fica no menu do perfil (header.php).
        'ajuda' => ['label' => 'Tutorial do Sistema', 'icon' => 'circle-help', 'arquivo' => 'ajuda.php', 'pronta' => true, 'pai' => 'configuracoes'],
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
/**
 * Módulo opcional (pasta modulos/<nome>) presente e ligado? Cada módulo define, no bootstrap.php,
 * a função modulo_<nome>_ativo(). Sem a pasta (módulo removido), devolve false sem erro.
 */
function admin_modulo_ativo(string $nome): bool
{
    static $cache = [];
    if (isset($cache[$nome])) {
        return $cache[$nome];
    }
    $arquivo = __DIR__ . '/../../modulos/' . basename($nome) . '/bootstrap.php';
    if (!preg_match('/^[a-z0-9_]+$/', $nome) || !is_file($arquivo)) {
        return $cache[$nome] = false;
    }
    try {
        require_once $arquivo;
        $funcao = 'modulo_' . $nome . '_ativo';
        return $cache[$nome] = function_exists($funcao) && $funcao();
    } catch (Throwable $e) {
        error_log('[biblioteca] Módulo ' . $nome . ': ' . $e->getMessage());
        return $cache[$nome] = false;
    }
}

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
