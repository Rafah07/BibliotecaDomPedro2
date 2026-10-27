-- =========================================================
-- 001 · Módulo de acervo — MySQL 8.0+
-- Biblioteca Dom Pedro II
--
-- Versão MySQL da estrutura da Etapa 2 (admin/database/acervo.sql, que era
-- PostgreSQL). A partir da Etapa 4, o MySQL é o banco principal do painel.
--
-- Regras mantidas:
--   * "livros" guarda a obra/edição; "exemplares" guarda cada unidade física.
--   * A quantidade das planilhas fica em "registros_legados" (migração 030,
--     módulo de importação) e NUNCA vira exemplar disponível automaticamente.
--   * Livros e categorias são arquivados (arquivado_em), não excluídos.
--   * Códigos BDP são únicos e gerados no servidor (gerar_exemplares).
--
-- Aplicar com: php admin/database/migrar.php
-- =========================================================

CREATE TABLE categorias (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(80)  NOT NULL,
    descricao    VARCHAR(255),
    arquivado_em DATETIME NULL,
    criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Nome único entre as categorias ativas (a collation já ignora maiúsculas).
    nome_ativo   VARCHAR(80) AS (IF(arquivado_em IS NULL, nome, NULL)) STORED,
    UNIQUE KEY categorias_nome_ativo_uk (nome_ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE autores (
    id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(150) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY autores_nome_uk (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE localizacoes (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    setor      VARCHAR(80) NOT NULL,
    estante    VARCHAR(80) NOT NULL,
    prateleira VARCHAR(80) NOT NULL,
    UNIQUE KEY localizacoes_uk (setor, estante, prateleira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE livros (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    titulo               VARCHAR(255) NOT NULL,
    subtitulo            VARCHAR(255),
    isbn                 VARCHAR(13),          -- somente dígitos (sem hífens)
    editora              VARCHAR(150),
    ano_publicacao       SMALLINT,             -- o limite do ano atual + 1 é validado no PHP
    edicao               VARCHAR(60),
    idioma               VARCHAR(30) NOT NULL DEFAULT 'Português',
    numero_paginas       INT,
    sinopse              TEXT,
    palavras_chave       VARCHAR(255),
    assunto              VARCHAR(255),         -- assunto principal, usado também na classificação
    observacoes_internas TEXT,
    capa                 VARCHAR(500),
    status_validacao     ENUM('validado', 'aguardando_conferencia', 'dados_incompletos', 'com_divergencia')
                         NOT NULL DEFAULT 'aguardando_conferencia',
    origem_cadastro      ENUM('manual', 'planilha', 'inventario') NOT NULL DEFAULT 'manual',
    arquivado_em         DATETIME NULL,
    criado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- O mesmo ISBN não pode aparecer em dois livros ativos. Títulos iguais são permitidos (edições diferentes).
    isbn_ativo           VARCHAR(13) AS (IF(arquivado_em IS NULL, isbn, NULL)) STORED,
    UNIQUE KEY livros_isbn_ativo_uk (isbn_ativo),
    KEY livros_titulo_idx (titulo),
    CONSTRAINT livros_titulo_ck CHECK (CHAR_LENGTH(TRIM(titulo)) > 0),
    CONSTRAINT livros_isbn_ck CHECK (isbn IS NULL OR REGEXP_LIKE(isbn, '^([0-9]{9}[0-9X]|[0-9]{13})$', 'c')),
    CONSTRAINT livros_ano_ck CHECK (ano_publicacao IS NULL OR ano_publicacao BETWEEN 1450 AND 2100),
    CONSTRAINT livros_paginas_ck CHECK (numero_paginas IS NULL OR numero_paginas BETWEEN 1 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE livro_autores (
    livro_id BIGINT NOT NULL,
    autor_id BIGINT NOT NULL,
    ordem    SMALLINT NOT NULL DEFAULT 1,
    PRIMARY KEY (livro_id, autor_id),
    CONSTRAINT fk_livro_autores_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_livro_autores_autor FOREIGN KEY (autor_id) REFERENCES autores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE livro_categorias (
    livro_id     BIGINT NOT NULL,
    categoria_id BIGINT NOT NULL,
    principal    BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (livro_id, categoria_id),
    CONSTRAINT fk_livro_categorias_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_livro_categorias_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Sequência dos códigos BDP-000001, BDP-000002... (MySQL não tem SEQUENCE).
CREATE TABLE exemplar_codigo_seq (
    id   TINYINT PRIMARY KEY,
    ultimo BIGINT NOT NULL
) ENGINE=InnoDB;
INSERT INTO exemplar_codigo_seq (id, ultimo) VALUES (1, 0);

CREATE TABLE exemplares (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id           BIGINT NOT NULL,
    codigo_individual  VARCHAR(20) NOT NULL,
    localizacao_id     BIGINT NULL,
    estado_conservacao ENUM('bom', 'regular', 'danificado', 'necessita_reparo') NOT NULL DEFAULT 'bom',
    situacao           ENUM('disponivel', 'emprestado', 'manutencao', 'extraviado', 'baixado', 'aguardando') NOT NULL DEFAULT 'aguardando',
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    conferido_em       DATETIME NULL,
    observacoes        TEXT,
    UNIQUE KEY exemplares_codigo_uk (codigo_individual),
    KEY exemplares_livro_idx (livro_id),
    KEY exemplares_situacao_idx (situacao),
    CONSTRAINT fk_exemplares_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_exemplares_localizacao FOREIGN KEY (localizacao_id) REFERENCES localizacoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Histórico individual de cada exemplar.
CREATE TABLE movimentacoes (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    exemplar_id    BIGINT NOT NULL,
    tipo           ENUM('cadastro', 'emprestimo', 'devolucao', 'manutencao', 'localizacao', 'inventario', 'extravio', 'baixa') NOT NULL,
    descricao      TEXT,
    responsavel_id BIGINT NULL,          -- administradores.id (migração 002)
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY movimentacoes_exemplar_idx (exemplar_id, criado_em),
    CONSTRAINT fk_movimentacoes_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Resultado das conferências físicas por título (módulo de inventário).
CREATE TABLE inventario_conferencias (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id       BIGINT NOT NULL,
    esperado       INT NOT NULL,
    encontrados    INT NOT NULL,
    observacao     TEXT,
    responsavel_id BIGINT NULL,
    realizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conferencias_livro FOREIGN KEY (livro_id) REFERENCES livros (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------
-- Geração segura de exemplares.
-- Chamada pelo PHP: CALL gerar_exemplares(?, ?, ?, ?, ?)
-- O bloqueio da linha da sequência garante códigos únicos mesmo
-- com vários funcionários cadastrando ao mesmo tempo.
-- ---------------------------------------------------------
CREATE PROCEDURE gerar_exemplares(
    IN p_livro_id BIGINT,
    IN p_quantidade INT,
    IN p_localizacao_id BIGINT,
    IN p_estado VARCHAR(20),
    IN p_situacao VARCHAR(20)
)
BEGIN
    DECLARE v_inicio BIGINT;
    DECLARE v_i INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_quantidade < 1 OR p_quantidade > 50 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantidade inválida de exemplares.';
    END IF;
    IF p_situacao NOT IN ('disponivel', 'manutencao', 'aguardando') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação inicial inválida.';
    END IF;

    START TRANSACTION;
    IF NOT EXISTS (SELECT 1 FROM livros WHERE id = p_livro_id AND arquivado_em IS NULL FOR UPDATE) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Livro não encontrado ou arquivado.';
    END IF;

    SELECT ultimo INTO v_inicio FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE;
    UPDATE exemplar_codigo_seq SET ultimo = ultimo + p_quantidade WHERE id = 1;

    WHILE v_i < p_quantidade DO
        SET v_i = v_i + 1;
        INSERT INTO exemplares (livro_id, codigo_individual, localizacao_id, estado_conservacao, situacao)
        VALUES (p_livro_id, CONCAT('BDP-', LPAD(v_inicio + v_i, 6, '0')), p_localizacao_id, COALESCE(p_estado, 'bom'), p_situacao);
        INSERT INTO movimentacoes (exemplar_id, tipo, descricao) VALUES (LAST_INSERT_ID(), 'cadastro', 'Exemplar registrado no acervo');
    END WHILE;
    COMMIT;

    SELECT * FROM exemplares WHERE livro_id = p_livro_id
       AND codigo_individual BETWEEN CONCAT('BDP-', LPAD(v_inicio + 1, 6, '0')) AND CONCAT('BDP-', LPAD(v_inicio + p_quantidade, 6, '0'));
END;

-- Impede mudar a situação de um exemplar emprestado fora do fluxo de devolução.
-- O PHP define SET @biblioteca_fluxo = 'devolucao' antes de registrar uma devolução.
CREATE TRIGGER exemplares_situacao_emprestado
BEFORE UPDATE ON exemplares
FOR EACH ROW
BEGIN
    IF OLD.situacao = 'emprestado' AND NEW.situacao <> 'emprestado'
       AND COALESCE(@biblioteca_fluxo, '') <> 'devolucao' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O exemplar possui empréstimo ativo. Registre a devolução primeiro.';
    END IF;
END;

-- Contagens usadas na listagem de livros (a migração 030 acrescenta a quantidade das planilhas).
CREATE VIEW livros_resumo AS
SELECT l.id,
       l.titulo,
       l.status_validacao,
       COUNT(e.id)                         AS total_exemplares,
       COALESCE(SUM(e.situacao = 'disponivel'), 0) AS disponiveis,
       COALESCE(SUM(e.situacao = 'emprestado'), 0) AS emprestados,
       COALESCE(SUM(e.situacao = 'manutencao'), 0) AS em_manutencao
FROM livros l
LEFT JOIN exemplares e ON e.livro_id = l.id
WHERE l.arquivado_em IS NULL
GROUP BY l.id, l.titulo, l.status_validacao;
