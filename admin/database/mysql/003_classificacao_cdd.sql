-- =========================================================
-- 003 · Reclassificação bibliográfica (CDD) — Etapa 4
--
-- Princípios:
--   * A CDD é sempre texto (VARCHAR): zeros à esquerda e pontos são preservados.
--   * Os dados antigos da planilha (CDD, código de autor, localização, categoria)
--     ficam em importacao_registros e não podem ser alterados depois de gravados.
--   * Nenhuma classificação é aprovada automaticamente: aprovada só por usuário
--     com permissão (verificado no PHP) e sempre com registro no histórico.
--   * O histórico não pode ser alterado nem excluído (triggers abaixo).
--   * Os códigos BDP dos exemplares não dependem da CDD e nunca mudam com ela.
-- =========================================================

-- ---------------------------------------------------------
-- Dados antigos da planilha (somente acrescenta colunas; nada é apagado)
-- ---------------------------------------------------------
ALTER TABLE importacao_registros
    ADD COLUMN cdd_original          VARCHAR(50)  NULL AFTER quantidade_informada,
    ADD COLUMN codigo_autor_original VARCHAR(50)  NULL AFTER cdd_original,
    ADD COLUMN localizacao_original  VARCHAR(100) NULL AFTER codigo_autor_original,
    ADD COLUMN categoria_original    VARCHAR(150) NULL AFTER localizacao_original,
    ADD COLUMN fonte_importacao      VARCHAR(255) NULL AFTER categoria_original;

CREATE TRIGGER importacao_preservar_dados_antigos
BEFORE UPDATE ON importacao_registros
FOR EACH ROW
BEGIN
    IF (OLD.cdd_original IS NOT NULL AND NOT (OLD.cdd_original <=> NEW.cdd_original))
       OR (OLD.codigo_autor_original IS NOT NULL AND NOT (OLD.codigo_autor_original <=> NEW.codigo_autor_original))
       OR (OLD.localizacao_original IS NOT NULL AND NOT (OLD.localizacao_original <=> NEW.localizacao_original))
       OR (OLD.categoria_original IS NOT NULL AND NOT (OLD.categoria_original <=> NEW.categoria_original))
       OR (OLD.fonte_importacao IS NOT NULL AND NOT (OLD.fonte_importacao <=> NEW.fonte_importacao))
       OR NOT (OLD.dados_originais <=> NEW.dados_originais) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os dados originais da planilha não podem ser alterados.';
    END IF;
END;

CREATE TRIGGER importacao_impedir_exclusao
BEFORE DELETE ON importacao_registros
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registros de importação não podem ser excluídos.';

-- ---------------------------------------------------------
-- Classificação atual de cada livro (uma linha por livro)
-- ---------------------------------------------------------
CREATE TABLE classificacoes_cdd (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id          BIGINT NOT NULL,
    cdd_original      VARCHAR(50),             -- cópia da planilha no momento da criação; nunca muda
    cdd_proposta      VARCHAR(50),
    cdd_aprovada      VARCHAR(50),
    ddc_edicao        VARCHAR(20),             -- edição da CDD informada pela fonte (ex.: 23)
    descricao         VARCHAR(255),            -- descrição da classe
    assunto_principal VARCHAR(255),
    fonte_nome        VARCHAR(255),
    fonte_url         TEXT,
    data_consulta     DATE,
    justificativa     TEXT,
    observacoes       TEXT,
    origem            ENUM('consulta', 'manual', 'ia') NULL,   -- de onde veio a proposta atual
    rascunho          BOOLEAN NOT NULL DEFAULT FALSE,
    precisa_revisao   BOOLEAN NOT NULL DEFAULT FALSE,           -- rejeitada ou nova pesquisa solicitada
    status            ENUM('pendente', 'sugerida', 'em_revisao', 'aprovada', 'conflito') NOT NULL DEFAULT 'pendente',
    consulta_id       BIGINT NULL,             -- registro bibliográfico escolhido (cdd_consultas)
    sugerido_por      BIGINT NULL,
    sugerido_em       DATETIME NULL,
    aprovado_por      BIGINT NULL,
    aprovado_em       DATETIME NULL,
    aprovada_fonte    VARCHAR(255),            -- fonte da CDD aprovada (a proposta pode mudar depois)
    versao            INT NOT NULL DEFAULT 1,  -- controle de edição simultânea
    criado_em         DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY classificacoes_cdd_livro_uk (livro_id),
    KEY classificacoes_cdd_status_idx (status),
    KEY classificacoes_cdd_aprovada_idx (cdd_aprovada),
    CONSTRAINT classificacoes_cdd_proposta_ck CHECK (cdd_proposta IS NULL OR REGEXP_LIKE(cdd_proposta, '^[0-9]{3}(\\.[0-9]+)?$')),
    CONSTRAINT classificacoes_cdd_aprovada_ck CHECK (cdd_aprovada IS NULL OR REGEXP_LIKE(cdd_aprovada, '^[0-9]{3}(\\.[0-9]+)?$')),
    CONSTRAINT classificacoes_cdd_aprovacao_ck CHECK (status <> 'aprovada' OR (cdd_aprovada IS NOT NULL AND aprovado_por IS NOT NULL AND aprovado_em IS NOT NULL)),
    CONSTRAINT fk_classificacao_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_classificacao_sugerido_por FOREIGN KEY (sugerido_por) REFERENCES administradores (id),
    CONSTRAINT fk_classificacao_aprovado_por FOREIGN KEY (aprovado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TRIGGER classificacoes_cdd_preservar_original
BEFORE UPDATE ON classificacoes_cdd
FOR EACH ROW
BEGIN
    IF OLD.cdd_original IS NOT NULL AND NOT (OLD.cdd_original <=> NEW.cdd_original) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A CDD original não pode ser alterada.';
    END IF;
END;

CREATE TRIGGER classificacoes_cdd_impedir_exclusao
BEFORE DELETE ON classificacoes_cdd
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Classificações não podem ser excluídas.';

-- ---------------------------------------------------------
-- Histórico de alterações (somente inclusão)
-- ---------------------------------------------------------
CREATE TABLE classificacoes_cdd_historico (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id         BIGINT NOT NULL,
    classificacao_id BIGINT NOT NULL,
    tipo             ENUM('importacao', 'pesquisa', 'sugestao', 'rascunho', 'envio_revisao', 'aprovacao',
                          'correcao', 'rejeicao', 'nova_pesquisa', 'observacao', 'conflito') NOT NULL,
    cdd_anterior     VARCHAR(50),
    cdd_nova         VARCHAR(50),
    status_anterior  VARCHAR(20),
    status_novo      VARCHAR(20),
    fonte_nome       VARCHAR(255),
    fonte_url        TEXT,
    justificativa    TEXT,
    observacao       TEXT,
    responsavel_id   BIGINT NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY cdd_historico_livro_idx (livro_id, criado_em),
    KEY cdd_historico_tipo_idx (tipo, criado_em),
    KEY cdd_historico_responsavel_idx (responsavel_id, criado_em),
    CONSTRAINT fk_cdd_historico_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_cdd_historico_classificacao FOREIGN KEY (classificacao_id) REFERENCES classificacoes_cdd (id),
    CONSTRAINT fk_cdd_historico_responsavel FOREIGN KEY (responsavel_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TRIGGER cdd_historico_impedir_alteracao
BEFORE UPDATE ON classificacoes_cdd_historico
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de classificação não pode ser alterado.';

CREATE TRIGGER cdd_historico_impedir_exclusao
BEFORE DELETE ON classificacoes_cdd_historico
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de classificação não pode ser excluído.';

-- ---------------------------------------------------------
-- Lotes de pesquisa
-- ---------------------------------------------------------
CREATE TABLE cdd_lotes (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(150) NOT NULL,
    status       ENUM('pendente', 'processando', 'concluido', 'cancelado') NOT NULL DEFAULT 'pendente',
    fontes       JSON NOT NULL,           -- fontes escolhidas para o lote
    total        INT NOT NULL DEFAULT 0,
    criado_por   BIGINT NULL,
    criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em DATETIME NULL,
    CONSTRAINT fk_cdd_lotes_criado_por FOREIGN KEY (criado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------
-- Pesquisas em fontes bibliográficas e registros encontrados
-- ---------------------------------------------------------
CREATE TABLE cdd_pesquisas (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id        BIGINT NOT NULL,
    lote_id         BIGINT NULL,
    termos          JSON NOT NULL,            -- ISBN, título, autor... usados na consulta
    fontes          JSON NOT NULL,            -- fontes consultadas e o resultado de cada uma
    resultado       ENUM('com_cdd', 'sem_cdd', 'sem_registro', 'conflito', 'erro') NOT NULL,
    total_registros INT NOT NULL DEFAULT 0,
    mensagem        VARCHAR(255),
    consultado_por  BIGINT NULL,
    consultado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY cdd_pesquisas_livro_idx (livro_id, consultado_em),
    KEY cdd_pesquisas_lote_idx (lote_id),
    CONSTRAINT fk_cdd_pesquisas_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_cdd_pesquisas_lote FOREIGN KEY (lote_id) REFERENCES cdd_lotes (id),
    CONSTRAINT fk_cdd_pesquisas_por FOREIGN KEY (consultado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cdd_consultas (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    pesquisa_id     BIGINT NOT NULL,
    livro_id        BIGINT NOT NULL,
    fonte           VARCHAR(40) NOT NULL,     -- identificador da fonte (ex.: loc, openlibrary)
    fonte_nome      VARCHAR(255) NOT NULL,
    registro_id     VARCHAR(255),
    registro_url    TEXT,
    titulo          VARCHAR(500),
    autor           VARCHAR(500),
    editora         VARCHAR(255),
    ano             VARCHAR(20),
    edicao          VARCHAR(100),
    isbn            VARCHAR(255),
    cdd             VARCHAR(50) NULL,         -- CDD normalizada (sem barras de segmentação)
    cdd_bruta       VARCHAR(100) NULL,        -- como veio da fonte
    ddc_edicao      VARCHAR(20) NULL,
    lcc             VARCHAR(100) NULL,        -- Library of Congress Classification: NÃO é CDD, só referência
    compatibilidade ENUM('exata', 'provavel', 'incerta', 'incompativel') NOT NULL,
    motivos         JSON,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY cdd_consultas_livro_idx (livro_id),
    KEY cdd_consultas_pesquisa_idx (pesquisa_id),
    CONSTRAINT fk_cdd_consultas_pesquisa FOREIGN KEY (pesquisa_id) REFERENCES cdd_pesquisas (id),
    CONSTRAINT fk_cdd_consultas_livro FOREIGN KEY (livro_id) REFERENCES livros (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE classificacoes_cdd
    ADD CONSTRAINT fk_classificacao_consulta FOREIGN KEY (consulta_id) REFERENCES cdd_consultas (id);

CREATE TABLE cdd_lote_itens (
    lote_id      BIGINT NOT NULL,
    livro_id     BIGINT NOT NULL,
    status       ENUM('pendente', 'concluido', 'erro') NOT NULL DEFAULT 'pendente',
    pesquisa_id  BIGINT NULL,
    mensagem     VARCHAR(255),
    processado_em DATETIME NULL,
    PRIMARY KEY (lote_id, livro_id),
    CONSTRAINT fk_cdd_lote_itens_lote FOREIGN KEY (lote_id) REFERENCES cdd_lotes (id),
    CONSTRAINT fk_cdd_lote_itens_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_cdd_lote_itens_pesquisa FOREIGN KEY (pesquisa_id) REFERENCES cdd_pesquisas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------
-- Tabela de referência da CDD (vazia por padrão).
-- Só deve ser preenchida com dados licenciados (ex.: exportação autorizada
-- do WebDewey). Quando vazia, o painel valida apenas o formato.
-- ---------------------------------------------------------
CREATE TABLE cdd_tabela_referencia (
    numero        VARCHAR(50) PRIMARY KEY,
    descricao     VARCHAR(255) NOT NULL,
    ddc_edicao    VARCHAR(20),
    fonte_licenca VARCHAR(255) NOT NULL,
    importado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------
-- Uma linha de classificação para cada livro já existente,
-- copiando a CDD da importação mais recente (quando houver).
-- Livros cadastrados depois ganham a linha na primeira ação do módulo.
-- ---------------------------------------------------------
INSERT INTO classificacoes_cdd (livro_id, cdd_original)
SELECT l.id,
       (SELECT r.cdd_original FROM importacao_registros r
         WHERE r.livro_id = l.id AND r.cdd_original IS NOT NULL
         ORDER BY r.importado_em DESC, r.id DESC LIMIT 1)
FROM livros l
WHERE NOT EXISTS (SELECT 1 FROM classificacoes_cdd c WHERE c.livro_id = l.id);

-- ---------------------------------------------------------
-- Base para organizar estantes e gerar futuras etiquetas.
-- Só entram CDDs aprovadas. O código BDP é do exemplar e não muda.
-- ---------------------------------------------------------
CREATE VIEW vw_estante_cdd AS
SELECT e.codigo_individual,
       e.situacao,
       l.id AS livro_id,
       l.titulo,
       (SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id
         WHERE la.livro_id = l.id ORDER BY la.ordem LIMIT 1) AS autor_principal,
       c.cdd_aprovada,
       c.aprovado_em
FROM exemplares e
JOIN livros l ON l.id = e.livro_id
JOIN classificacoes_cdd c ON c.livro_id = l.id
WHERE c.cdd_aprovada IS NOT NULL
  AND l.arquivado_em IS NULL
  AND e.situacao <> 'baixado';
