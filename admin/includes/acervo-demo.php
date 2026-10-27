<?php
/**
 * Dados DEMONSTRATIVOS do módulo de acervo (livros, exemplares e categorias).
 *
 * Nenhum registro aqui é real: os títulos e autores existem, mas as edições, ISBNs,
 * exemplares, localizações e movimentações foram inventados para testar as telas.
 *
 * Cada função corresponde a uma tabela do banco (ver admin/database/acervo.sql).
 * Na integração com PostgreSQL/Supabase, basta trocar o corpo da função por uma
 * consulta parametrizada, mantendo o mesmo formato de retorno.
 */

/** Tabela "categorias". */
function demo_categorias(): array
{
    return [
        1 => ['id' => 1, 'nome' => 'Literatura brasileira', 'descricao' => 'Obras de autores brasileiros, clássicas e contemporâneas.'],
        2 => ['id' => 2, 'nome' => 'Literatura estrangeira', 'descricao' => 'Obras traduzidas de autores de outros países.'],
        3 => ['id' => 3, 'nome' => 'Romance', 'descricao' => 'Narrativas longas de ficção.'],
        4 => ['id' => 4, 'nome' => 'Fantasia', 'descricao' => 'Histórias com elementos mágicos ou sobrenaturais.'],
        5 => ['id' => 5, 'nome' => 'Ficção científica', 'descricao' => 'Ficção baseada em ciência, tecnologia e futuros possíveis.'],
        6 => ['id' => 6, 'nome' => 'História', 'descricao' => 'Livros sobre fatos, períodos e processos históricos.'],
        7 => ['id' => 7, 'nome' => 'Geografia', 'descricao' => 'Atlas, mapas e estudos sobre territórios e populações.'],
        8 => ['id' => 8, 'nome' => 'Matemática', 'descricao' => 'Livros paradidáticos e de apoio ao ensino de Matemática.'],
        9 => ['id' => 9, 'nome' => 'Ciências', 'descricao' => 'Divulgação científica e apoio às Ciências da Natureza.'],
        10 => ['id' => 10, 'nome' => 'Biografia', 'descricao' => 'Relatos da vida de pessoas reais, incluindo diários e memórias.'],
        11 => ['id' => 11, 'nome' => 'Poesia', 'descricao' => 'Coletâneas e obras poéticas.'],
        12 => ['id' => 12, 'nome' => 'Infantil', 'descricao' => 'Obras indicadas para leitores iniciantes e crianças.'],
        13 => ['id' => 13, 'nome' => 'Outros', 'descricao' => 'Títulos que ainda não se encaixam nas demais categorias.'],
    ];
}

/** Tabela "autores". */
function demo_autores(): array
{
    return [
        1 => ['id' => 1, 'nome' => 'Machado de Assis'],
        2 => ['id' => 2, 'nome' => 'Antoine de Saint-Exupéry'],
        3 => ['id' => 3, 'nome' => 'Suzanne Collins'],
        4 => ['id' => 4, 'nome' => 'J. K. Rowling'],
        5 => ['id' => 5, 'nome' => 'Markus Zusak'],
        6 => ['id' => 6, 'nome' => 'Aluísio Azevedo'],
        7 => ['id' => 7, 'nome' => 'Graciliano Ramos'],
        8 => ['id' => 8, 'nome' => 'Frank Herbert'],
        9 => ['id' => 9, 'nome' => 'Stephen Hawking'],
        10 => ['id' => 10, 'nome' => 'Yuval Noah Harari'],
        11 => ['id' => 11, 'nome' => 'IBGE'],
        12 => ['id' => 12, 'nome' => 'Malba Tahan'],
        13 => ['id' => 13, 'nome' => 'Carlos Drummond de Andrade'],
        14 => ['id' => 14, 'nome' => 'Anne Frank'],
    ];
}

/** Tabela "localizacoes". */
function demo_localizacoes(): array
{
    return [
        1 => ['id' => 1, 'setor' => 'Literatura', 'estante' => 'Estante A', 'prateleira' => 'Prateleira 1'],
        2 => ['id' => 2, 'setor' => 'Literatura', 'estante' => 'Estante A', 'prateleira' => 'Prateleira 2'],
        3 => ['id' => 3, 'setor' => 'Literatura', 'estante' => 'Estante B', 'prateleira' => 'Prateleira 1'],
        4 => ['id' => 4, 'setor' => 'Infantojuvenil', 'estante' => 'Estante C', 'prateleira' => 'Prateleira 1'],
        5 => ['id' => 5, 'setor' => 'Infantojuvenil', 'estante' => 'Estante C', 'prateleira' => 'Prateleira 2'],
        6 => ['id' => 6, 'setor' => 'Ciências e Humanas', 'estante' => 'Estante D', 'prateleira' => 'Prateleira 1'],
        7 => ['id' => 7, 'setor' => 'Ciências e Humanas', 'estante' => 'Estante D', 'prateleira' => 'Prateleira 2'],
        8 => ['id' => 8, 'setor' => 'Manutenção', 'estante' => 'Bancada', 'prateleira' => 'Caixa 1'],
        9 => ['id' => 9, 'setor' => 'Triagem', 'estante' => 'Mesa de triagem', 'prateleira' => 'Caixa 2'],
    ];
}

/**
 * Tabela "livros" (com os vínculos de livro_autores e livro_categorias embutidos).
 * status_validacao: validado | aguardando_conferencia | dados_incompletos | com_divergencia
 * origem_cadastro: manual | planilha | inventario
 * capa: caminho da imagem ou null (sem imagem, o painel desenha uma capa padrão com o título).
 */
function demo_livros(): array
{
    $livros = [
        ['id' => 1, 'titulo' => 'Dom Casmurro', 'subtitulo' => '', 'autores' => [1], 'categorias' => [1, 3],
            'isbn' => '978-85-9445-260-3', 'editora' => 'Editora Aurora', 'ano_publicacao' => 2019, 'edicao' => '1ª edição',
            'idioma' => 'Português', 'numero_paginas' => 256, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-03-02', 'cor' => '#143477',
            'sinopse' => 'Bentinho, já maduro, relembra a juventude, o amor por Capitu e a dúvida que atravessa toda a sua vida.',
            'palavras_chave' => 'realismo, ciúme, clássico', 'observacoes_internas' => 'Leitura indicada para o 2º ano do Ensino Médio.'],
        ['id' => 2, 'titulo' => 'Dom Casmurro', 'subtitulo' => '', 'autores' => [1], 'categorias' => [1, 3],
            'isbn' => '', 'editora' => 'Editora Horizonte', 'ano_publicacao' => 1998, 'edicao' => 'Série Clássicos Escolares',
            'idioma' => 'Português', 'numero_paginas' => null, 'status_validacao' => 'aguardando_conferencia', 'origem_cadastro' => 'planilha',
            'criado_em' => '2026-10-20', 'cor' => '#4a5f8c',
            'sinopse' => '', 'palavras_chave' => '', 'observacoes_internas' => 'Registro trazido da planilha antiga. Edição diferente do cadastro nº 1.'],
        ['id' => 3, 'titulo' => 'Memórias Póstumas de Brás Cubas', 'subtitulo' => '', 'autores' => [1], 'categorias' => [1, 3],
            'isbn' => '978-85-3251-815-6', 'editora' => 'Editora Aurora', 'ano_publicacao' => 2020, 'edicao' => '2ª edição',
            'idioma' => 'Português', 'numero_paginas' => 288, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-03-02', 'cor' => '#6b3a2e',
            'sinopse' => 'Um defunto autor narra a própria vida com ironia, a partir do túmulo.',
            'palavras_chave' => 'realismo, ironia, clássico', 'observacoes_internas' => ''],
        ['id' => 4, 'titulo' => 'O Cortiço', 'subtitulo' => '', 'autores' => [6], 'categorias' => [1, 3],
            'isbn' => '', 'editora' => '', 'ano_publicacao' => null, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => null, 'status_validacao' => 'dados_incompletos', 'origem_cadastro' => 'inventario',
            'criado_em' => '2026-09-18', 'cor' => '#7a5c1e',
            'sinopse' => '', 'palavras_chave' => 'naturalismo', 'observacoes_internas' => 'Exemplar encontrado na conferência sem folha de rosto. Falta confirmar editora e ano.'],
        ['id' => 5, 'titulo' => 'Vidas Secas', 'subtitulo' => '', 'autores' => [7], 'categorias' => [1],
            'isbn' => '978-85-2209-083-9', 'editora' => 'Editora Sertão', 'ano_publicacao' => 2013, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 176, 'status_validacao' => 'aguardando_conferencia', 'origem_cadastro' => 'planilha',
            'criado_em' => '2026-10-20', 'cor' => '#a0522d',
            'sinopse' => 'A jornada de uma família de retirantes pelo sertão nordestino.',
            'palavras_chave' => 'regionalismo, modernismo', 'observacoes_internas' => ''],
        ['id' => 6, 'titulo' => 'O Pequeno Príncipe', 'subtitulo' => '', 'autores' => [2], 'categorias' => [2, 12],
            'isbn' => '978-85-0100-166-5', 'editora' => 'Editora Estrela', 'ano_publicacao' => 2018, 'edicao' => '3ª edição',
            'idioma' => 'Português', 'numero_paginas' => 96, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-02-14', 'cor' => '#1f6fb2',
            'sinopse' => 'Um piloto perdido no deserto conhece um pequeno príncipe vindo de outro planeta.',
            'palavras_chave' => 'amizade, infância, clássico', 'observacoes_internas' => ''],
        ['id' => 7, 'titulo' => 'A Menina que Roubava Livros', 'subtitulo' => '', 'autores' => [5], 'categorias' => [2, 3],
            'isbn' => '978-85-6971-318-0', 'editora' => 'Editora Página', 'ano_publicacao' => 2017, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 480, 'status_validacao' => 'com_divergencia', 'origem_cadastro' => 'planilha',
            'criado_em' => '2026-04-10', 'cor' => '#3d3d3d',
            'sinopse' => 'Na Alemanha nazista, uma menina encontra nos livros um refúgio, narrada pela Morte.',
            'palavras_chave' => 'segunda guerra, leitura', 'observacoes_internas' => 'A planilha informa 4 exemplares; a última conferência encontrou 3.'],
        ['id' => 8, 'titulo' => 'Harry Potter e a Pedra Filosofal', 'subtitulo' => '', 'autores' => [4], 'categorias' => [2, 4],
            'isbn' => '978-85-7536-091-0', 'editora' => 'Editora Encanto', 'ano_publicacao' => 2017, 'edicao' => '1ª edição',
            'idioma' => 'Português', 'numero_paginas' => 264, 'status_validacao' => 'validado', 'origem_cadastro' => 'planilha',
            'criado_em' => '2026-04-10', 'cor' => '#7b1e3a',
            'sinopse' => 'Harry descobre, aos onze anos, que é um bruxo e é convidado a estudar em Hogwarts.',
            'palavras_chave' => 'magia, escola, aventura', 'observacoes_internas' => 'Título muito procurado. Avaliar compra de novos exemplares.'],
        ['id' => 9, 'titulo' => 'Jogos Vorazes', 'subtitulo' => '', 'autores' => [3], 'categorias' => [2, 5],
            'isbn' => '978-85-3593-909-5', 'editora' => 'Editora Arena', 'ano_publicacao' => 2016, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 400, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-10-26', 'cor' => '#b5651d',
            'sinopse' => 'Katniss se oferece para participar de um torneio televisionado em que só um pode sobreviver.',
            'palavras_chave' => 'distopia, aventura', 'observacoes_internas' => ''],
        ['id' => 10, 'titulo' => 'Duna', 'subtitulo' => '', 'autores' => [8], 'categorias' => [2, 5],
            'isbn' => '978-85-2409-603-7', 'editora' => 'Editora Areia', 'ano_publicacao' => 2017, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 680, 'status_validacao' => 'aguardando_conferencia', 'origem_cadastro' => 'inventario',
            'criado_em' => '2026-10-22', 'cor' => '#c28a2c',
            'sinopse' => 'Paul Atreides chega ao planeta desértico Arrakis, centro de disputas pelo recurso mais valioso do universo.',
            'palavras_chave' => 'deserto, política, clássico', 'observacoes_internas' => 'Dois exemplares chegaram por doação e aguardam etiqueta.'],
        ['id' => 11, 'titulo' => 'Uma Breve História do Tempo', 'subtitulo' => 'Do Big Bang aos buracos negros', 'autores' => [9], 'categorias' => [9],
            'isbn' => '978-85-2540-824-2', 'editora' => 'Editora Cosmos', 'ano_publicacao' => 2015, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 256, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-05-05', 'cor' => '#0f2a4a',
            'sinopse' => 'Uma introdução acessível às grandes perguntas sobre o universo.',
            'palavras_chave' => 'física, universo', 'observacoes_internas' => ''],
        ['id' => 12, 'titulo' => 'Sapiens', 'subtitulo' => 'Uma breve história da humanidade', 'autores' => [10], 'categorias' => [6],
            'isbn' => '978-85-0516-281-2', 'editora' => '', 'ano_publicacao' => null, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => null, 'status_validacao' => 'dados_incompletos', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-10-24', 'cor' => '#8c6d3f',
            'sinopse' => '', 'palavras_chave' => '', 'observacoes_internas' => 'Cadastrado a partir de uma lista de compras. Ainda não chegou à biblioteca.'],
        ['id' => 13, 'titulo' => 'Atlas Geográfico Escolar', 'subtitulo' => '', 'autores' => [11], 'categorias' => [7],
            'isbn' => '978-85-2509-482-7', 'editora' => 'Editora Mapa', 'ano_publicacao' => 2018, 'edicao' => '8ª edição',
            'idioma' => 'Português', 'numero_paginas' => 220, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-02-20', 'cor' => '#0e7c7b',
            'sinopse' => 'Mapas físicos, políticos e temáticos do Brasil e do mundo.',
            'palavras_chave' => 'mapas, cartografia', 'observacoes_internas' => 'Uso preferencial em sala de aula.'],
        ['id' => 14, 'titulo' => 'O Homem que Calculava', 'subtitulo' => '', 'autores' => [12], 'categorias' => [8],
            'isbn' => '978-85-6301-993-6', 'editora' => 'Editora Número', 'ano_publicacao' => 2014, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 304, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-03-15', 'cor' => '#5b3f8c',
            'sinopse' => 'As aventuras de Beremiz Samir, que resolve problemas com raciocínio matemático.',
            'palavras_chave' => 'matemática, problemas', 'observacoes_internas' => ''],
        ['id' => 15, 'titulo' => 'A Rosa do Povo', 'subtitulo' => '', 'autores' => [13], 'categorias' => [11, 1],
            'isbn' => '978-85-3591-234-0', 'editora' => 'Editora Verso', 'ano_publicacao' => 2012, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 208, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-03-15', 'cor' => '#b03a48',
            'sinopse' => 'Poemas escritos durante a Segunda Guerra Mundial.',
            'palavras_chave' => 'poesia, modernismo', 'observacoes_internas' => ''],
        ['id' => 16, 'titulo' => 'O Diário de Anne Frank', 'subtitulo' => '', 'autores' => [14], 'categorias' => [10],
            'isbn' => '978-85-0110-347-5', 'editora' => 'Editora Memória', 'ano_publicacao' => 2015, 'edicao' => '',
            'idioma' => 'Português', 'numero_paginas' => 352, 'status_validacao' => 'validado', 'origem_cadastro' => 'manual',
            'criado_em' => '2026-02-14', 'cor' => '#4b5563',
            'sinopse' => 'O diário de uma adolescente judia escondida com a família durante a ocupação nazista.',
            'palavras_chave' => 'segunda guerra, diário', 'observacoes_internas' => ''],
    ];
    $porId = [];
    foreach ($livros as $livro) {
        $livro['capa'] = null;
        $livro['arquivado'] = false;
        $porId[$livro['id']] = $livro;
    }
    return $porId;
}

/**
 * Tabela "exemplares".
 * estado_conservacao: bom | regular | danificado | necessita_reparo
 * situacao: disponivel | emprestado | manutencao | extraviado | baixado | aguardando
 */
function demo_exemplares(): array
{
    // [livro_id, localizacao_id, conservação, situação, criado_em, conferido_em, observações]
    $linhas = [
        [1, 1, 'bom', 'disponivel', '2026-03-02', '2026-09-15', ''],
        [1, 1, 'bom', 'disponivel', '2026-03-02', '2026-09-15', ''],
        [1, 1, 'regular', 'emprestado', '2026-03-02', '2026-09-15', 'Capa com pequenos desgastes.'],
        [3, 1, 'bom', 'disponivel', '2026-03-02', '2026-09-15', ''],
        [3, 1, 'bom', 'emprestado', '2026-03-02', '2026-09-15', ''],
        [4, 2, 'regular', 'disponivel', '2026-09-18', '2026-09-18', 'Sem folha de rosto.'],
        [6, 4, 'bom', 'disponivel', '2026-02-14', '2026-09-16', ''],
        [6, 4, 'bom', 'disponivel', '2026-02-14', '2026-09-16', ''],
        [6, 4, 'regular', 'disponivel', '2026-02-14', '2026-09-16', ''],
        [6, 4, 'bom', 'emprestado', '2026-02-14', '2026-09-16', ''],
        [7, 3, 'bom', 'disponivel', '2026-04-10', '2026-09-15', ''],
        [7, 3, 'regular', 'disponivel', '2026-04-10', '2026-09-15', ''],
        [7, 3, 'bom', 'extraviado', '2026-04-10', '2026-06-02', 'Não localizado na conferência de setembro.'],
        [8, 5, 'bom', 'disponivel', '2026-04-10', '2026-09-16', ''],
        [8, 5, 'bom', 'disponivel', '2026-04-10', '2026-09-16', ''],
        [8, 5, 'bom', 'emprestado', '2026-04-10', '2026-09-16', ''],
        [8, 5, 'regular', 'emprestado', '2026-04-10', '2026-09-16', ''],
        [8, 8, 'necessita_reparo', 'manutencao', '2026-04-10', '2026-09-16', 'Lombada descolando. Aguardando reparo.'],
        [9, 5, 'bom', 'disponivel', '2026-10-26', null, ''],
        [9, 5, 'bom', 'emprestado', '2026-10-26', null, ''],
        [9, 5, 'bom', 'emprestado', '2026-10-26', null, ''],
        [10, 9, 'bom', 'aguardando', '2026-10-22', null, 'Doação. Falta colar etiqueta.'],
        [10, 9, 'regular', 'aguardando', '2026-10-22', null, 'Doação. Falta colar etiqueta.'],
        [11, 8, 'necessita_reparo', 'manutencao', '2026-05-05', '2026-09-17', 'Páginas soltas no capítulo 4.'],
        [13, 6, 'bom', 'disponivel', '2026-02-20', '2026-09-17', ''],
        [13, 6, 'regular', 'disponivel', '2026-02-20', '2026-09-17', ''],
        [14, 7, 'bom', 'disponivel', '2026-03-15', '2026-09-17', ''],
        [14, 7, 'bom', 'disponivel', '2026-03-15', '2026-09-17', ''],
        [14, 7, 'regular', 'emprestado', '2026-03-15', '2026-09-17', ''],
        [15, 2, 'bom', 'disponivel', '2026-03-15', '2026-09-15', ''],
        [15, 2, 'bom', 'disponivel', '2026-03-15', '2026-09-15', ''],
        [16, 7, 'bom', 'disponivel', '2026-02-14', '2026-09-17', ''],
        [16, 7, 'danificado', 'baixado', '2026-02-14', '2026-06-03', 'Danificado por umidade. Baixa aprovada em junho.'],
    ];
    $exemplares = [];
    foreach ($linhas as $i => [$livroId, $localId, $estado, $situacao, $criado, $conferido, $obs]) {
        $id = $i + 1;
        $exemplares[$id] = [
            'id' => $id,
            'livro_id' => $livroId,
            'codigo_individual' => sprintf('BDP-%06d', $id),
            'localizacao_id' => $localId,
            'estado_conservacao' => $estado,
            'situacao' => $situacao,
            'criado_em' => $criado,
            'conferido_em' => $conferido,
            'observacoes' => $obs,
        ];
    }
    return $exemplares;
}

/**
 * Tabela "importacao_registros": quantidades informadas nas planilhas antigas.
 * Fica separada dos exemplares: a quantidade da planilha NÃO gera exemplares disponíveis.
 */
function demo_registros_planilha(): array
{
    return [
        ['livro_id' => 2, 'arquivo' => 'acervo_antigo_2019.xlsx', 'linha' => 112, 'quantidade_informada' => 5, 'importado_em' => '2026-10-20'],
        ['livro_id' => 5, 'arquivo' => 'acervo_antigo_2019.xlsx', 'linha' => 287, 'quantidade_informada' => 3, 'importado_em' => '2026-10-20'],
        ['livro_id' => 7, 'arquivo' => 'acervo_2024.xlsx', 'linha' => 41, 'quantidade_informada' => 4, 'importado_em' => '2026-04-10'],
        ['livro_id' => 8, 'arquivo' => 'acervo_2024.xlsx', 'linha' => 58, 'quantidade_informada' => 5, 'importado_em' => '2026-04-10'],
    ];
}

/**
 * Tabela "movimentacoes" (histórico por exemplar).
 * tipo: cadastro | emprestimo | devolucao | manutencao | localizacao | inventario | baixa | extravio
 * O cadastro de cada exemplar é acrescentado automaticamente em acervo_movimentacoes().
 */
function demo_movimentacoes(): array
{
    return [
        ['exemplar_id' => 3, 'tipo' => 'emprestimo', 'data' => '2026-10-21', 'descricao' => 'Empréstimo para estudante do 2º ano B', 'responsavel' => 'Ana Souza', 'prazo' => '2026-11-04'],
        ['exemplar_id' => 1, 'tipo' => 'devolucao', 'data' => '2026-10-27', 'descricao' => 'Devolvido em bom estado', 'responsavel' => 'Ana Souza'],
        ['exemplar_id' => 1, 'tipo' => 'emprestimo', 'data' => '2026-10-13', 'descricao' => 'Empréstimo para estudante do 2º ano A', 'responsavel' => 'Ana Souza'],
        ['exemplar_id' => 5, 'tipo' => 'emprestimo', 'data' => '2026-10-15', 'descricao' => 'Empréstimo para estudante do 3º ano A', 'responsavel' => 'Ana Souza', 'prazo' => '2026-10-29'],
        ['exemplar_id' => 6, 'tipo' => 'inventario', 'data' => '2026-09-18', 'descricao' => 'Encontrado na conferência física sem cadastro prévio', 'responsavel' => 'Equipe do inventário'],
        ['exemplar_id' => 10, 'tipo' => 'emprestimo', 'data' => '2026-10-27', 'descricao' => 'Empréstimo para estudante do 6º ano C', 'responsavel' => 'Ana Souza', 'prazo' => '2026-11-10'],
        ['exemplar_id' => 7, 'tipo' => 'devolucao', 'data' => '2026-10-20', 'descricao' => 'Devolvido em bom estado', 'responsavel' => 'Ana Souza'],
        ['exemplar_id' => 7, 'tipo' => 'emprestimo', 'data' => '2026-10-06', 'descricao' => 'Empréstimo para estudante do 7º ano A', 'responsavel' => 'Ana Souza'],
        ['exemplar_id' => 13, 'tipo' => 'extravio', 'data' => '2026-09-15', 'descricao' => 'Não localizado na conferência física', 'responsavel' => 'Equipe do inventário'],
        ['exemplar_id' => 16, 'tipo' => 'emprestimo', 'data' => '2026-10-20', 'descricao' => 'Empréstimo para estudante do 8º ano B', 'responsavel' => 'Ana Souza', 'prazo' => '2026-11-03'],
        ['exemplar_id' => 17, 'tipo' => 'emprestimo', 'data' => '2026-10-09', 'descricao' => 'Empréstimo para estudante do 9º ano A', 'responsavel' => 'Ana Souza', 'prazo' => '2026-10-23'],
        ['exemplar_id' => 18, 'tipo' => 'manutencao', 'data' => '2026-10-01', 'descricao' => 'Enviado para reparo da lombada', 'responsavel' => 'Ana Souza'],
        ['exemplar_id' => 18, 'tipo' => 'localizacao', 'data' => '2026-10-01', 'descricao' => 'Movido de Infantojuvenil / Estante C para Manutenção / Bancada', 'responsavel' => 'Ana Souza'],
        ['exemplar_id' => 20, 'tipo' => 'emprestimo', 'data' => '2026-10-26', 'descricao' => 'Empréstimo para estudante do 9º ano B', 'responsavel' => 'Ana Souza', 'prazo' => '2026-11-09'],
        ['exemplar_id' => 21, 'tipo' => 'emprestimo', 'data' => '2026-10-26', 'descricao' => 'Empréstimo para estudante do 1º ano A', 'responsavel' => 'Ana Souza', 'prazo' => '2026-11-09'],
        ['exemplar_id' => 24, 'tipo' => 'manutencao', 'data' => '2026-09-20', 'descricao' => 'Enviado para reparo das páginas soltas', 'responsavel' => 'Ana Souza'],
        ['exemplar_id' => 29, 'tipo' => 'emprestimo', 'data' => '2026-10-22', 'descricao' => 'Empréstimo para estudante do 8º ano A', 'responsavel' => 'Ana Souza', 'prazo' => '2026-11-05'],
        ['exemplar_id' => 33, 'tipo' => 'baixa', 'data' => '2026-06-03', 'descricao' => 'Baixa por dano causado por umidade', 'responsavel' => 'Ana Souza'],
    ];
}

/** Tabela "inventario_conferencias": resultado das conferências físicas por título. */
function demo_conferencias(): array
{
    return [
        ['livro_id' => 1, 'data' => '2026-09-15', 'responsavel' => 'Equipe do inventário', 'esperado' => 3, 'encontrados' => 3, 'observacao' => 'Todos os exemplares localizados na Estante A.'],
        ['livro_id' => 3, 'data' => '2026-09-15', 'responsavel' => 'Equipe do inventário', 'esperado' => 2, 'encontrados' => 2, 'observacao' => ''],
        ['livro_id' => 4, 'data' => '2026-09-18', 'responsavel' => 'Equipe do inventário', 'esperado' => 0, 'encontrados' => 1, 'observacao' => 'Exemplar sem cadastro encontrado na Estante A.'],
        ['livro_id' => 6, 'data' => '2026-09-16', 'responsavel' => 'Equipe do inventário', 'esperado' => 4, 'encontrados' => 4, 'observacao' => ''],
        ['livro_id' => 7, 'data' => '2026-06-02', 'responsavel' => 'Equipe do inventário', 'esperado' => 4, 'encontrados' => 4, 'observacao' => 'Primeira conferência após a importação da planilha.'],
        ['livro_id' => 7, 'data' => '2026-09-15', 'responsavel' => 'Equipe do inventário', 'esperado' => 4, 'encontrados' => 3, 'observacao' => 'Um exemplar não foi localizado.'],
        ['livro_id' => 8, 'data' => '2026-09-16', 'responsavel' => 'Equipe do inventário', 'esperado' => 5, 'encontrados' => 5, 'observacao' => ''],
        ['livro_id' => 11, 'data' => '2026-09-17', 'responsavel' => 'Equipe do inventário', 'esperado' => 1, 'encontrados' => 1, 'observacao' => ''],
        ['livro_id' => 13, 'data' => '2026-09-17', 'responsavel' => 'Equipe do inventário', 'esperado' => 2, 'encontrados' => 2, 'observacao' => ''],
        ['livro_id' => 14, 'data' => '2026-09-17', 'responsavel' => 'Equipe do inventário', 'esperado' => 3, 'encontrados' => 3, 'observacao' => ''],
        ['livro_id' => 15, 'data' => '2026-09-15', 'responsavel' => 'Equipe do inventário', 'esperado' => 2, 'encontrados' => 2, 'observacao' => ''],
        ['livro_id' => 16, 'data' => '2026-09-17', 'responsavel' => 'Equipe do inventário', 'esperado' => 1, 'encontrados' => 1, 'observacao' => 'Exemplar baixado não entra na contagem.'],
    ];
}
