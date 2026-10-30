-- =========================================================
-- 080 · Site público (Etapa 4 do site) — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Incremental: não altera nem apaga dados existentes.
--   * "site_configuracoes" guarda os textos institucionais do site público
--     (apresentação, horários, contato, regras...). Começa VAZIA: nada é inventado.
--     Enquanto um campo estiver vazio, o site mostra que a informação aguarda
--     confirmação da escola. Editado no painel em Sistema → Site público.
--   * Índices para o catálogo público (milhares de livros):
--       - livros por data de cadastro (seção "Adicionados recentemente" e ordenação);
--       - livros por ano de publicação (filtro e ordenação por ano);
--       - exemplares por livro e situação (contagem de disponíveis em cada página).
--
-- Depende de 001 e 002.
-- =========================================================

CREATE TABLE site_configuracoes (
    chave          VARCHAR(60) PRIMARY KEY,
    valor          TEXT NULL,
    atualizado_por BIGINT NULL,              -- administradores.id
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_site_config_admin FOREIGN KEY (atualizado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE livros
    ADD KEY livros_publico_recentes_idx (arquivado_em, criado_em),
    ADD KEY livros_ano_idx (ano_publicacao);

ALTER TABLE exemplares
    ADD KEY exemplares_livro_situacao_idx (livro_id, situacao);
