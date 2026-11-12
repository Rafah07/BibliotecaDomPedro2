<?php
/**
 * Módulos (categorias) da Central de Ajuda, na ordem em que aparecem.
 * 'arquivo' aponta para tutoriais/<arquivo>, que devolve a lista de tutoriais do módulo.
 * 'palavras' ajudam a pesquisa a encontrar o módulo por outros nomes.
 * Veja LEIA-ME.md para o formato completo.
 */
return [
    'primeiros-passos' => [
        'nome' => 'Primeiros passos',
        'icone' => 'compass',
        'descricao' => 'Entrar no painel, usar o menu, entender mensagens, botões e permissões.',
        'arquivo' => '01-primeiros-passos.php',
        'palavras' => ['inicio', 'comecar', 'login', 'menu', 'navegacao', 'basico'],
    ],
    'dashboard' => [
        'nome' => 'Dashboard',
        'icone' => 'layout-dashboard',
        'descricao' => 'O que significa cada número, gráfico, pendência e atalho da página inicial do painel.',
        'arquivo' => '02-dashboard.php',
        'palavras' => ['painel inicial', 'visao geral', 'indicadores', 'graficos'],
    ],
    'livros' => [
        'nome' => 'Cadastro e gerenciamento de livros',
        'icone' => 'library-big',
        'descricao' => 'Pesquisar, cadastrar, editar, corrigir e arquivar os registros bibliográficos do acervo.',
        'arquivo' => '03-livros.php',
        'palavras' => ['acervo', 'livro', 'titulo', 'obra', 'registro bibliografico', 'isbn'],
    ],
    'exemplares' => [
        'nome' => 'Exemplares físicos',
        'icone' => 'book-copy',
        'descricao' => 'Cadastrar cada volume real, entender o código BDP, a localização e a disponibilidade.',
        'arquivo' => '04-exemplares.php',
        'palavras' => ['exemplar', 'volume', 'unidade', 'bdp', 'codigo', 'patrimonio', 'localizacao'],
    ],
    'emprestimos' => [
        'nome' => 'Empréstimos',
        'icone' => 'book-open-check',
        'descricao' => 'Registrar um empréstimo do começo ao fim e resolver as situações que impedem o registro.',
        'arquivo' => '05-emprestimos.php',
        'palavras' => ['emprestar', 'circulacao', 'retirada', 'aluno'],
    ],
    'devolucoes' => [
        'nome' => 'Devoluções e renovações',
        'icone' => 'rotate-ccw',
        'descricao' => 'Registrar devoluções, renovar prazos e acompanhar os empréstimos atrasados.',
        'arquivo' => '06-devolucoes-renovacoes.php',
        'palavras' => ['devolver', 'renovar', 'atraso', 'prazo', 'vencido'],
    ],
    'alunos' => [
        'nome' => 'Alunos',
        'icone' => 'graduation-cap',
        'descricao' => 'Consultar, cadastrar, corrigir e inativar alunos, ver o histórico e proteger os dados pessoais.',
        'arquivo' => '07-alunos.php',
        'palavras' => ['aluno', 'estudante', 'matricula', 'email', 'privacidade', 'lgpd'],
    ],
    'importacao' => [
        'nome' => 'Importação de dados',
        'icone' => 'file-spreadsheet',
        'descricao' => 'Preparar planilhas CSV e XLSX, importar o acervo e os alunos e revisar pendências.',
        'arquivo' => '08-importacao.php',
        'palavras' => ['importar', 'planilha', 'excel', 'csv', 'xlsx'],
    ],
    'inventario' => [
        'nome' => 'Inventário físico',
        'icone' => 'clipboard-check',
        'descricao' => 'Criar uma conferência, digitar os códigos BDP, tratar divergências e concluir.',
        'arquivo' => '09-inventario.php',
        'palavras' => ['inventario', 'conferencia', 'conferir', 'estante', 'divergencia'],
    ],
    'etiquetas' => [
        'nome' => 'Etiquetas',
        'icone' => 'tag',
        'descricao' => 'Gerar, imprimir e colar as etiquetas escritas com o código BDP.',
        'arquivo' => '10-etiquetas.php',
        'palavras' => ['etiqueta', 'imprimir', 'impressao', 'lombada', 'a4'],
    ],
    'cdd' => [
        'nome' => 'Classificação CDD',
        'icone' => 'list-tree',
        'descricao' => 'Pesquisar, sugerir, revisar e aprovar a Classificação Decimal de Dewey dos livros.',
        'arquivo' => '11-cdd.php',
        'palavras' => ['cdd', 'dewey', 'classificacao', 'reclassificacao'],
    ],
    'relatorios' => [
        'nome' => 'Relatórios',
        'icone' => 'chart-column',
        'descricao' => 'Escolher a seção, filtrar por período, interpretar os números e exportar CSV, XLSX ou PDF.',
        'arquivo' => '12-relatorios.php',
        'palavras' => ['relatorio', 'estatistica', 'exportar', 'pdf', 'csv', 'xlsx'],
    ],
    'administradores' => [
        'nome' => 'Administradores e permissões',
        'icone' => 'shield-check',
        'descricao' => 'Perfis de acesso, contas do painel, senhas e o que cada perfil pode fazer.',
        'arquivo' => '13-administradores.php',
        'palavras' => ['perfil', 'permissao', 'conta', 'usuario', 'senha', 'administrador'],
    ],
    'configuracoes' => [
        'nome' => 'Configurações',
        'icone' => 'settings',
        'descricao' => 'Prazos, renovações, limites, segurança, site em manutenção e textos institucionais.',
        'arquivo' => '14-configuracoes.php',
        'palavras' => ['configuracao', 'regras', 'prazo', 'parametros', 'site publico'],
    ],
    'backup' => [
        'nome' => 'Backup e manutenção',
        'icone' => 'save',
        'descricao' => 'Gerar e guardar a cópia de segurança, ler o diagnóstico e saber quando chamar o técnico.',
        'arquivo' => '15-backup-manutencao.php',
        'palavras' => ['backup', 'copia de seguranca', 'restauracao', 'restaurar', 'diagnostico', 'manutencao'],
    ],
    'problemas' => [
        'nome' => 'Solução de problemas',
        'icone' => 'life-buoy',
        'descricao' => 'Perguntas frequentes com passos seguros para resolver as dúvidas mais comuns.',
        'arquivo' => '16-solucao-problemas.php',
        'palavras' => ['problema', 'erro', 'duvida', 'faq', 'perguntas frequentes', 'nao funciona'],
    ],
];
