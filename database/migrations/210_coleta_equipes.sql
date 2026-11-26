-- =========================================================
-- 210 · Equipes da organização inicial por links (sem login) — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Usado na reorganização inicial do acervo. O administrador abre um lote (com capacidade) e gera
-- links para as equipes que não têm conta no painel:
--
--   Equipe de coleta (link "coleta")      → digita o nome e faz o PRÉ-CADASTRO de cada livro do
--                                            lote (título, autor, editora, ano, ISBN, CDD, quantidade).
--                                            A quantidade ocupa vagas do lote na hora.
--   Equipe de revisão (administradores)   → revisa no painel e aprova: a obra e os exemplares são
--                                            criados com os códigos BDP dentro do lote.
--   Equipe de carimbagem (link "carimbagem") → vê a Planilha BDP do lote, marca cada exemplar como
--                                            carimbado e conclui a carimbagem do lote.
--
-- 1. coleta_links: um link por equipe, com token aleatório (só o hash SHA-256 é guardado), validade
--    definida pelo administrador e revogação. Expirado ou revogado, o link para de funcionar.
-- 2. coleta_itens: os pré-cadastros. Nunca entram no acervo sozinhos: só a aprovação de um
--    administrador cria a obra e os exemplares. Recusados ficam guardados com o motivo.
-- 3. coleta_pausas: "Pausar trabalho" em qualquer etapa (coleta, revisão, carimbagem), com quem
--    pausou, o motivo e onde parou. Retomar grava quem e quando. Nada é apagado.
-- 4. coleta_carimbos: quem marcou cada exemplar como carimbado pelo link (o nome digitado), além do
--    registro normal do exemplar.
--
-- Só acrescenta tabelas: não altera lotes, exemplares, obras nem códigos BDP.
-- Aplicar com: php database/migrar.php (a cópia de segurança é feita antes).
-- =========================================================

CREATE TABLE coleta_links (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id       BIGINT NOT NULL,
    tipo          ENUM('coleta', 'carimbagem') NOT NULL,
    equipe        VARCHAR(80) NOT NULL,                  -- ex.: "Equipe 1"
    token_hash    CHAR(64) NOT NULL,                     -- SHA-256 do token; o token só aparece ao criar o link
    expira_em     DATETIME NOT NULL,
    criado_por    BIGINT NOT NULL,                       -- administrador responsável pelo que for feito pelo link
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revogado_em   DATETIME NULL,
    revogado_por  BIGINT NULL,
    ultimo_uso_em DATETIME NULL,
    UNIQUE KEY coleta_links_token_uk (token_hash),
    KEY coleta_links_lote_idx (lote_id, tipo),
    CONSTRAINT fk_coleta_links_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_coleta_links_criado_por FOREIGN KEY (criado_por) REFERENCES administradores (id),
    CONSTRAINT fk_coleta_links_revogado_por FOREIGN KEY (revogado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coleta_itens (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id            BIGINT NOT NULL,
    link_id            BIGINT NOT NULL,
    numero             INT UNSIGNED NOT NULL,             -- nº do livro no lote (o mesmo do marcador colocado no livro)
    coletado_por       VARCHAR(100) NOT NULL,             -- nome digitado por quem fez o pré-cadastro
    titulo             VARCHAR(255) NOT NULL,
    subtitulo          VARCHAR(255) NULL,
    autor              VARCHAR(500) NULL,                 -- "SOBRENOME, Nome"; vários separados por ";"
    sem_autor          TINYINT(1) NOT NULL DEFAULT 0,
    editora            VARCHAR(150) NULL,
    sem_editora        TINYINT(1) NOT NULL DEFAULT 0,
    ano                SMALLINT UNSIGNED NULL,
    sem_ano            TINYINT(1) NOT NULL DEFAULT 0,
    tem_isbn           TINYINT(1) NOT NULL DEFAULT 0,
    isbn               VARCHAR(20) NULL,
    tem_cdd            TINYINT(1) NOT NULL DEFAULT 0,
    cdd                VARCHAR(30) NULL,
    edicao             VARCHAR(60) NULL,
    assunto            VARCHAR(255) NULL,
    cod_autor          VARCHAR(30) NULL,
    quantidade         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    observacoes        VARCHAR(500) NULL,
    status             ENUM('pendente', 'aprovado', 'recusado') NOT NULL DEFAULT 'pendente',
    motivo_recusa      VARCHAR(255) NULL,
    livro_id           BIGINT NULL,                       -- obra criada ou escolhida na aprovação
    revisado_por       BIGINT NULL,
    revisado_em        DATETIME NULL,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY coleta_itens_numero_uk (lote_id, numero),
    KEY coleta_itens_status_idx (lote_id, status),
    KEY coleta_itens_isbn_idx (isbn),
    CONSTRAINT fk_coleta_itens_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_coleta_itens_link FOREIGN KEY (link_id) REFERENCES coleta_links (id),
    CONSTRAINT fk_coleta_itens_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_coleta_itens_revisado_por FOREIGN KEY (revisado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coleta_pausas (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id           BIGINT NOT NULL,
    etapa             ENUM('coleta', 'revisao', 'carimbagem') NOT NULL,
    pausado_por       VARCHAR(100) NOT NULL,              -- nome (equipe pelo link) ou nome do administrador
    administrador_id  BIGINT NULL,                        -- quando pausado pelo painel
    link_id           BIGINT NULL,                        -- quando pausado pelo link
    motivo            VARCHAR(255) NULL,
    onde_parou        VARCHAR(255) NOT NULL,              -- resumo gravado no momento da pausa
    pausado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    retomado_em       DATETIME NULL,
    retomado_por      VARCHAR(100) NULL,
    KEY coleta_pausas_lote_idx (lote_id, etapa, retomado_em),
    CONSTRAINT fk_coleta_pausas_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_coleta_pausas_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT fk_coleta_pausas_link FOREIGN KEY (link_id) REFERENCES coleta_links (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coleta_carimbos (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    link_id      BIGINT NOT NULL,
    exemplar_id  BIGINT NOT NULL,
    nome         VARCHAR(100) NOT NULL,
    criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY coleta_carimbos_exemplar_idx (exemplar_id),
    CONSTRAINT fk_coleta_carimbos_link FOREIGN KEY (link_id) REFERENCES coleta_links (id),
    CONSTRAINT fk_coleta_carimbos_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
