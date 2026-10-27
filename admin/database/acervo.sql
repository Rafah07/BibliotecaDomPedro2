-- =========================================================
-- Módulo de acervo — estrutura para PostgreSQL / Supabase
-- SUBSTITUÍDO na Etapa 4: o banco principal agora é MySQL (admin/database/mysql/).
-- Biblioteca Dom Pedro II · Etapa 2 (preparação, ainda não conectada)
--
-- Cada tabela corresponde a uma função de admin/includes/acervo-demo.php.
-- Regras importantes:
--   * "livros" guarda a obra/edição; "exemplares" guarda cada unidade física.
--   * A quantidade informada em planilhas fica em "importacao_registros" e
--     NUNCA vira exemplar disponível automaticamente.
--   * Livros e categorias são arquivados (arquivado_em), não excluídos.
--   * Códigos de exemplar são únicos e gerados no servidor (gerar_exemplares).
-- =========================================================

create table categorias (
    id          bigint generated always as identity primary key,
    nome        varchar(80)  not null,
    descricao   varchar(255),
    arquivado_em timestamptz,
    criado_em   timestamptz not null default now()
);
-- Nome único entre as categorias ativas, sem diferenciar maiúsculas.
create unique index categorias_nome_ativo_uk on categorias (lower(nome)) where arquivado_em is null;

create table autores (
    id        bigint generated always as identity primary key,
    nome      varchar(150) not null,
    criado_em timestamptz not null default now()
);
create unique index autores_nome_uk on autores (lower(nome));

create table localizacoes (
    id         bigint generated always as identity primary key,
    setor      varchar(80) not null,
    estante    varchar(80) not null,
    prateleira varchar(80) not null,
    unique (setor, estante, prateleira)
);

create table livros (
    id                   bigint generated always as identity primary key,
    titulo               varchar(255) not null check (length(trim(titulo)) > 0),
    subtitulo            varchar(255),
    isbn                 varchar(13) check (isbn ~ '^(\d{9}[\dX]|\d{13})$'), -- somente dígitos (sem hífens)
    editora              varchar(150),
    ano_publicacao       smallint check (ano_publicacao between 1450 and 2100), -- o limite do ano atual + 1 é validado no PHP
    edicao               varchar(60),
    idioma               varchar(30) not null default 'Português',
    numero_paginas       integer check (numero_paginas between 1 and 10000),
    sinopse              text,
    palavras_chave       varchar(255),
    observacoes_internas text,          -- visível apenas para administradores (ver políticas RLS)
    capa                 text,          -- caminho no Supabase Storage
    status_validacao     varchar(30) not null default 'aguardando_conferencia'
        check (status_validacao in ('validado', 'aguardando_conferencia', 'dados_incompletos', 'com_divergencia')),
    origem_cadastro      varchar(20) not null default 'manual'
        check (origem_cadastro in ('manual', 'planilha', 'inventario')),
    arquivado_em         timestamptz,
    criado_em            timestamptz not null default now(),
    atualizado_em        timestamptz not null default now()
);
-- O mesmo ISBN não pode aparecer em dois livros ativos. Títulos iguais são permitidos (edições diferentes).
create unique index livros_isbn_ativo_uk on livros (isbn) where isbn is not null and arquivado_em is null;
create index livros_titulo_idx on livros (lower(titulo));

create table livro_autores (
    livro_id bigint not null references livros (id),
    autor_id bigint not null references autores (id),
    ordem    smallint not null default 1,
    primary key (livro_id, autor_id)
);

create table livro_categorias (
    livro_id     bigint not null references livros (id),
    categoria_id bigint not null references categorias (id),
    principal    boolean not null default false,
    primary key (livro_id, categoria_id)
);

-- Sequência dos códigos BDP-000001, BDP-000002...
create sequence exemplar_codigo_seq;

create table exemplares (
    id                 bigint generated always as identity primary key,
    livro_id           bigint not null references livros (id),
    codigo_individual  varchar(20) not null unique,
    localizacao_id     bigint references localizacoes (id),
    estado_conservacao varchar(20) not null default 'bom'
        check (estado_conservacao in ('bom', 'regular', 'danificado', 'necessita_reparo')),
    situacao           varchar(20) not null default 'aguardando'
        check (situacao in ('disponivel', 'emprestado', 'manutencao', 'extraviado', 'baixado', 'aguardando')),
    criado_em          timestamptz not null default now(),
    conferido_em       timestamptz,
    observacoes        text
);
create index exemplares_livro_idx on exemplares (livro_id);
create index exemplares_situacao_idx on exemplares (situacao);

-- Quantidades informadas nas planilhas antigas (referência para o inventário).
create table importacao_registros (
    id                   bigint generated always as identity primary key,
    livro_id             bigint references livros (id),
    arquivo              varchar(255) not null,
    linha                integer not null,
    quantidade_informada integer not null check (quantidade_informada >= 0),
    dados_originais      jsonb,         -- linha original da planilha, sem alterações
    importado_em         timestamptz not null default now()
);

-- Histórico individual de cada exemplar.
create table movimentacoes (
    id           bigint generated always as identity primary key,
    exemplar_id  bigint not null references exemplares (id),
    tipo         varchar(20) not null
        check (tipo in ('cadastro', 'emprestimo', 'devolucao', 'manutencao', 'localizacao', 'inventario', 'extravio', 'baixa')),
    descricao    text,
    responsavel_id uuid,                -- auth.users (Supabase) quando a autenticação existir
    criado_em    timestamptz not null default now()
);
create index movimentacoes_exemplar_idx on movimentacoes (exemplar_id, criado_em desc);

-- Resultado das conferências físicas por título (módulo de inventário).
create table inventario_conferencias (
    id           bigint generated always as identity primary key,
    livro_id     bigint not null references livros (id),
    esperado     integer not null,
    encontrados  integer not null,
    observacao   text,
    responsavel_id uuid,
    realizado_em timestamptz not null default now()
);

-- ---------------------------------------------------------
-- Geração segura de exemplares
-- Chamada pelo PHP com consulta parametrizada, por exemplo:
--   select * from gerar_exemplares($1, $2, $3, $4, $5);
-- A sequência garante códigos únicos mesmo com vários funcionários
-- cadastrando ao mesmo tempo; a função roda em uma única transação.
-- ---------------------------------------------------------
create or replace function gerar_exemplares(
    p_livro_id bigint,
    p_quantidade integer,
    p_localizacao_id bigint,
    p_estado varchar default 'bom',
    p_situacao varchar default 'aguardando'
) returns setof exemplares
language plpgsql as $$
begin
    if p_quantidade < 1 or p_quantidade > 50 then
        raise exception 'Quantidade inválida: %', p_quantidade;
    end if;
    if p_situacao not in ('disponivel', 'manutencao', 'aguardando') then
        raise exception 'Situação inicial inválida: %', p_situacao;
    end if;
    perform 1 from livros where id = p_livro_id and arquivado_em is null for update;
    if not found then
        raise exception 'Livro % não encontrado ou arquivado', p_livro_id;
    end if;

    return query
    with novos as (
        insert into exemplares (livro_id, codigo_individual, localizacao_id, estado_conservacao, situacao)
        select p_livro_id, 'BDP-' || lpad(nextval('exemplar_codigo_seq')::text, 6, '0'), p_localizacao_id, p_estado, p_situacao
        from generate_series(1, p_quantidade)
        returning *
    ), historico as (
        insert into movimentacoes (exemplar_id, tipo, descricao)
        select id, 'cadastro', 'Exemplar registrado no acervo' from novos
    )
    select * from novos;
end;
$$;

-- Impede mudar a situação de um exemplar emprestado fora do fluxo de devolução.
create or replace function bloquear_situacao_emprestado() returns trigger
language plpgsql as $$
begin
    if old.situacao = 'emprestado' and new.situacao <> 'emprestado'
       and coalesce(current_setting('biblioteca.fluxo', true), '') <> 'devolucao' then
        raise exception 'O exemplar % possui empréstimo ativo. Registre a devolução primeiro.', old.codigo_individual;
    end if;
    return new;
end;
$$;
create trigger exemplares_situacao_emprestado
    before update of situacao on exemplares
    for each row execute function bloquear_situacao_emprestado();

-- Contagens usadas na listagem de livros.
create or replace view livros_resumo as
select l.id,
       l.titulo,
       l.status_validacao,
       count(e.id)                                            as total_exemplares,
       count(e.id) filter (where e.situacao = 'disponivel')   as disponiveis,
       count(e.id) filter (where e.situacao = 'emprestado')   as emprestados,
       count(e.id) filter (where e.situacao = 'manutencao')   as em_manutencao,
       (select sum(quantidade_informada) from importacao_registros r where r.livro_id = l.id) as quantidade_planilha
from livros l
left join exemplares e on e.livro_id = l.id
where l.arquivado_em is null
group by l.id;
