<?php
/**
 * Dados DEMONSTRATIVOS do painel administrativo.
 *
 * Nenhum número aqui é real. Cada função representa uma consulta futura ao banco
 * (Supabase/MySQL): basta trocar o corpo da função mantendo o mesmo formato de retorno.
 */

/** Usuário exibido no cabeçalho enquanto não há autenticação real. */
function demo_usuario(): array
{
    return ['nome' => 'Ana Souza', 'cargo' => 'Administrador', 'iniciais' => 'AS'];
}

/** Indicadores principais (primeira linha de cards). */
function demo_indicadores(): array
{
    return [
        ['titulo' => 'Total de títulos', 'valor' => 1248, 'descricao' => 'Obras cadastradas no acervo', 'icone' => 'library-big', 'tom' => 'blue', 'estado' => '+12 neste mês'],
        ['titulo' => 'Total de exemplares', 'valor' => 2536, 'descricao' => 'Unidades físicas registradas', 'icone' => 'book-copy', 'tom' => 'blue', 'estado' => '+34 neste mês'],
        ['titulo' => 'Disponíveis', 'valor' => 1987, 'descricao' => 'Exemplares disponíveis para empréstimo', 'icone' => 'circle-check', 'tom' => 'green', 'estado' => '78% do acervo'],
        ['titulo' => 'Emprestados', 'valor' => 428, 'descricao' => 'Exemplares com empréstimos ativos', 'icone' => 'book-open-check', 'tom' => 'indigo', 'estado' => '17% do acervo'],
    ];
}

/** Indicadores secundários (segunda linha, cards menores). */
function demo_indicadores_secundarios(): array
{
    return [
        ['titulo' => 'Empréstimos atrasados', 'valor' => 18, 'icone' => 'clock-alert', 'tom' => 'red', 'href' => 'atrasos'],
        ['titulo' => 'Em manutenção', 'valor' => 23, 'icone' => 'wrench', 'tom' => 'amber', 'href' => 'exemplares'],
        ['titulo' => 'Pendências do inventário', 'valor' => 80, 'icone' => 'clipboard-check', 'tom' => 'amber', 'href' => 'inventario'],
        ['titulo' => 'Devoluções previstas para hoje', 'valor' => 12, 'icone' => 'calendar-clock', 'tom' => 'blue', 'href' => 'devolucoes'],
    ];
}

/** Empréstimos por mês, para o gráfico de movimentação (6 e 12 meses). */
function demo_emprestimos_por_mes(): array
{
    return [
        'meses12' => [
            ['mes' => 'Nov/25', 'total' => 64],
            ['mes' => 'Dez/25', 'total' => 38],
            ['mes' => 'Jan/26', 'total' => 22],
            ['mes' => 'Fev/26', 'total' => 71],
            ['mes' => 'Mar/26', 'total' => 104],
            ['mes' => 'Abr/26', 'total' => 118],
            ['mes' => 'Mai/26', 'total' => 85],
            ['mes' => 'Jun/26', 'total' => 112],
            ['mes' => 'Jul/26', 'total' => 96],
            ['mes' => 'Ago/26', 'total' => 143],
            ['mes' => 'Set/26', 'total' => 158],
            ['mes' => 'Out/26', 'total' => 174],
        ],
    ];
}

/** Distribuição do acervo por categoria (percentuais). */
function demo_acervo_por_categoria(): array
{
    return [
        ['categoria' => 'Literatura', 'percentual' => 35, 'cor' => '#143477'],
        ['categoria' => 'Romance', 'percentual' => 20, 'cor' => '#2457C5'],
        ['categoria' => 'Fantasia', 'percentual' => 18, 'cor' => '#2E73FF'],
        ['categoria' => 'Ciências', 'percentual' => 12, 'cor' => '#7FA6F5'],
        ['categoria' => 'História', 'percentual' => 10, 'cor' => '#0EA5A4'],
        ['categoria' => 'Outros', 'percentual' => 5, 'cor' => '#CBD5E1'],
    ];
}

/** Últimas movimentações. tipo: emprestimo | devolucao | cadastro | inventario | importacao */
function demo_atividades_recentes(): array
{
    return [
        ['tipo' => 'emprestimo', 'descricao' => 'O Pequeno Príncipe', 'responsavel' => 'Administrador', 'data' => 'Hoje', 'situacao' => 'concluido'],
        ['tipo' => 'devolucao', 'descricao' => 'Dom Casmurro', 'responsavel' => 'Administrador', 'data' => 'Hoje', 'situacao' => 'concluido'],
        ['tipo' => 'cadastro', 'descricao' => 'Jogos Vorazes', 'responsavel' => 'Administrador', 'data' => 'Ontem', 'situacao' => 'concluido'],
        ['tipo' => 'inventario', 'descricao' => 'Estante A', 'responsavel' => 'Administrador', 'data' => 'Ontem', 'situacao' => 'andamento'],
        ['tipo' => 'importacao', 'descricao' => 'acervo_2026.xlsx', 'responsavel' => 'Administrador', 'data' => '20/10/2026', 'situacao' => 'concluido'],
    ];
}

/** Alertas da seção "Atenção necessária". gravidade: alta | media */
function demo_alertas(): array
{
    return [
        ['titulo' => 'Empréstimos atrasados', 'mensagem' => '18 empréstimos estão com o prazo de devolução vencido.', 'botao' => 'Ver atrasos', 'href' => 'atrasos', 'icone' => 'clock-alert', 'gravidade' => 'alta'],
        ['titulo' => 'Pendências do inventário', 'mensagem' => '80 registros precisam de verificação ou conferência.', 'botao' => 'Abrir inventário', 'href' => 'inventario', 'icone' => 'clipboard-check', 'gravidade' => 'media'],
        ['titulo' => 'Exemplares em manutenção', 'mensagem' => '23 exemplares estão indisponíveis por necessidade de manutenção.', 'botao' => 'Consultar exemplares', 'href' => 'exemplares', 'icone' => 'wrench', 'gravidade' => 'media'],
    ];
}

/** Ações do "Acesso rápido". href usa os ids do menu (admin_url). */
function demo_acoes_rapidas(): array
{
    return [
        ['titulo' => 'Novo empréstimo', 'descricao' => 'Registrar a retirada de um exemplar.', 'icone' => 'book-open-check', 'href' => 'emprestimos'],
        ['titulo' => 'Registrar devolução', 'descricao' => 'Dar baixa em um exemplar devolvido.', 'icone' => 'rotate-ccw', 'href' => 'devolucoes'],
        ['titulo' => 'Cadastrar livro', 'descricao' => 'Adicionar uma nova obra ao acervo.', 'icone' => 'book-plus', 'href' => 'cadastrar-livro'],
        ['titulo' => 'Iniciar inventário', 'descricao' => 'Conferir os exemplares físicos da biblioteca.', 'icone' => 'clipboard-check', 'href' => 'inventario'],
        ['titulo' => 'Importar planilha', 'descricao' => 'Trazer dados antigos de um arquivo Excel.', 'icone' => 'file-spreadsheet', 'href' => 'importar'],
        ['titulo' => 'Gerar relatório', 'descricao' => 'Resumo do acervo e da circulação.', 'icone' => 'file-chart-column', 'href' => 'relatorios'],
    ];
}

/** Progresso da conferência física do acervo. */
function demo_inventario(): array
{
    return ['esperado' => 2536, 'encontrados' => 1850, 'pendentes' => 606, 'divergencias' => 80];
}
