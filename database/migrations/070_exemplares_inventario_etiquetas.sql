-- =========================================================
-- 070 · Exemplares, localizações, inventário digital e etiquetas — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II (Etapa 3 do inventário físico)
--
-- Completa as estruturas criadas em 001 (exemplares, localizacoes, movimentacoes,
-- exemplar_codigo_seq) e 050 (inventarios) sem recriar tabelas e sem apagar dados:
--   * Nenhum exemplar é criado aqui. A tabela "exemplares" só recebe linhas quando
--     alguém cadastra uma unidade física (cadastro manual ou conferência do inventário).
--     A quantidade das planilhas (registros_legados) continua sendo só referência.
--   * Os códigos BDP existentes são preservados. A sequência passa a ser, no mínimo,
--     o maior código já usado, e nunca volta: um código baixado não é reaproveitado.
--   * Exemplares não são excluídos (a baixa mantém o código e o histórico), o código
--     não muda e um exemplar baixado continua baixado.
--   * Localizações ganham complemento, código curto e desativação. A localização antiga
--     da planilha continua em registros_legados.localizacao_antiga (histórica, não confirmada);
--     a localização física confirmada é exemplares.localizacao_id.
--   * "inventario_itens" registra a conferência de cada exemplar em cada inventário
--     (uma linha por exemplar e inventário: não há conferência duplicada).
--   * Inventários concluídos ou cancelados não podem mais ser alterados.
--   * "etiqueta_modelos" guarda os tamanhos das etiquetas em milímetros (editáveis no painel).
--
-- Depende de 001, 002, 010, 030, 050 e 060.
-- =========================================================

-- ---------------------------------------------------------
-- Localizações físicas
-- ---------------------------------------------------------
ALTER TABLE localizacoes
    ADD COLUMN complemento   VARCHAR(80)  NOT NULL DEFAULT '' AFTER prateleira,
    ADD COLUMN codigo        VARCHAR(30)  NULL AFTER complemento,      -- código curto impresso na etiqueta (ex.: LIT-E03-P02)
    ADD COLUMN descricao     VARCHAR(255) NULL AFTER codigo,
    ADD COLUMN ativa         BOOLEAN NOT NULL DEFAULT TRUE AFTER descricao,
    ADD COLUMN criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- A mesma combinação setor + estante + prateleira + complemento só pode existir uma vez
    -- (a collation ignora maiúsculas: "e03" e "E03" são a mesma estante).
    DROP INDEX localizacoes_uk,
    ADD UNIQUE KEY localizacoes_uk (setor, estante, prateleira, complemento),
    ADD UNIQUE KEY localizacoes_codigo_uk (codigo),
    ADD KEY localizacoes_ativa_idx (ativa, setor);

-- ---------------------------------------------------------
-- Exemplares
-- ---------------------------------------------------------
ALTER TABLE exemplares
    MODIFY COLUMN situacao ENUM('disponivel', 'emprestado', 'manutencao', 'extraviado', 'baixado', 'aguardando', 'danificado') NOT NULL DEFAULT 'aguardando',
    ADD COLUMN cadastrado_por      BIGINT NULL AFTER observacoes,     -- administradores.id
    ADD COLUMN inventario_id       BIGINT NULL AFTER cadastrado_por,  -- inventário em que o exemplar foi cadastrado (se houver)
    ADD COLUMN etiqueta_gerada_em  DATETIME NULL AFTER inventario_id,
    ADD COLUMN baixado_em          DATETIME NULL AFTER etiqueta_gerada_em,
    ADD COLUMN motivo_baixa        VARCHAR(255) NULL AFTER baixado_em,
    ADD COLUMN atualizado_em       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    ADD KEY exemplares_conferido_idx (conferido_em),
    ADD CONSTRAINT fk_exemplares_cadastrado_por FOREIGN KEY (cadastrado_por) REFERENCES administradores (id),
    ADD CONSTRAINT fk_exemplares_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id);

-- Preserva os códigos já usados: a sequência nunca fica abaixo do maior código existente.
INSERT IGNORE INTO exemplar_codigo_seq (id, ultimo) VALUES (1, 0);
UPDATE exemplar_codigo_seq
   SET ultimo = GREATEST(ultimo, COALESCE((SELECT MAX(CAST(SUBSTRING(codigo_individual, 5) AS UNSIGNED))
                                           FROM exemplares WHERE codigo_individual REGEXP '^BDP-[0-9]{6}$'), 0))
 WHERE id = 1;

-- Todo código novo segue o padrão BDP-000000. Se alguém gravar um código diretamente
-- (scripts antigos, dados demonstrativos), a sequência avança junto para não repeti-lo.
CREATE TRIGGER exemplares_codigo_novo
BEFORE INSERT ON exemplares
FOR EACH ROW
BEGIN
    IF NEW.codigo_individual IS NULL OR NOT (NEW.codigo_individual COLLATE utf8mb4_bin REGEXP '^BDP-[0-9]{6}$') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O código do exemplar deve seguir o padrão BDP-000000.';
    END IF;
    UPDATE exemplar_codigo_seq SET ultimo = GREATEST(ultimo, CAST(SUBSTRING(NEW.codigo_individual, 5) AS UNSIGNED)) WHERE id = 1;
END;

-- O código e o livro de um exemplar nunca mudam, e a baixa é definitiva.
CREATE TRIGGER exemplares_preservar_identidade
BEFORE UPDATE ON exemplares
FOR EACH ROW
BEGIN
    IF NEW.codigo_individual <> OLD.codigo_individual THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O código de um exemplar não pode ser alterado.';
    END IF;
    IF NEW.livro_id <> OLD.livro_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O livro de um exemplar não pode ser trocado. Dê baixa e cadastre um novo exemplar.';
    END IF;
    IF OLD.situacao = 'baixado' AND NEW.situacao <> 'baixado' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Exemplares baixados não voltam ao acervo. O código não é reaproveitado.';
    END IF;
END;

-- Exemplares não são excluídos: use a baixa (o histórico e o código ficam preservados).
CREATE TRIGGER exemplares_sem_exclusao
BEFORE DELETE ON exemplares
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Exemplares não podem ser excluídos. Registre a baixa.';

-- ---------------------------------------------------------
-- Histórico dos exemplares
-- ---------------------------------------------------------
ALTER TABLE movimentacoes
    MODIFY COLUMN tipo ENUM('cadastro', 'emprestimo', 'devolucao', 'manutencao', 'localizacao', 'inventario', 'extravio', 'baixa', 'renovacao', 'conservacao', 'situacao') NOT NULL,
    ADD COLUMN inventario_id BIGINT NULL AFTER emprestimo_id,
    ADD COLUMN detalhes      JSON NULL AFTER descricao,          -- valores anteriores e novos (ids, estados)
    ADD CONSTRAINT fk_movimentacoes_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id);

CREATE TRIGGER movimentacoes_sem_exclusao
BEFORE DELETE ON movimentacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico dos exemplares não pode ser excluído.';

-- ---------------------------------------------------------
-- Inventários
-- 'aberto' é exibido como "Em preparação".
-- ---------------------------------------------------------
ALTER TABLE inventarios
    MODIFY COLUMN status ENUM('aberto', 'em_andamento', 'pausado', 'concluido', 'cancelado') NOT NULL DEFAULT 'aberto',
    ADD COLUMN data_inicio          DATE NULL AFTER descricao,          -- data de início informada
    ADD COLUMN responsavel_id       BIGINT NULL AFTER aberto_por,      -- administradores.id
    ADD COLUMN setor                VARCHAR(80) NULL AFTER responsavel_id, -- vazio = acervo inteiro
    ADD COLUMN em_andamento_desde   DATETIME NULL AFTER iniciado_em,   -- quando a conferência começou (lista de previstos)
    ADD COLUMN concluido_por        BIGINT NULL AFTER concluido_em,
    ADD COLUMN cancelado_em         DATETIME NULL AFTER concluido_por,
    ADD COLUMN cancelado_por        BIGINT NULL AFTER cancelado_em,
    ADD COLUMN motivo_cancelamento  VARCHAR(500) NULL AFTER cancelado_por,
    ADD COLUMN resumo_final         JSON NULL AFTER motivo_cancelamento, -- números congelados na conclusão
    ADD CONSTRAINT fk_inventarios_responsavel FOREIGN KEY (responsavel_id) REFERENCES administradores (id),
    ADD CONSTRAINT fk_inventarios_concluido_por FOREIGN KEY (concluido_por) REFERENCES administradores (id),
    ADD CONSTRAINT fk_inventarios_cancelado_por FOREIGN KEY (cancelado_por) REFERENCES administradores (id);

-- Inventário concluído ou cancelado fica como está.
CREATE TRIGGER inventarios_finalizado
BEFORE UPDATE ON inventarios
FOR EACH ROW
BEGIN
    IF OLD.status IN ('concluido', 'cancelado') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este inventário já foi finalizado e não pode ser alterado.';
    END IF;
END;

CREATE TRIGGER inventarios_sem_exclusao
BEFORE DELETE ON inventarios
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inventários não podem ser excluídos. Use o cancelamento.';

-- Conferência de cada exemplar em cada inventário.
CREATE TABLE inventario_itens (
    id                        BIGINT AUTO_INCREMENT PRIMARY KEY,
    inventario_id             BIGINT NOT NULL,
    exemplar_id               BIGINT NOT NULL,
    previsto                  BOOLEAN NOT NULL DEFAULT FALSE,  -- estava na lista quando a conferência começou
    cadastrado_no_inventario  BOOLEAN NOT NULL DEFAULT FALSE,  -- exemplar criado durante esta conferência
    situacao_esperada         VARCHAR(20) NULL,                -- situação no início da conferência
    localizacao_esperada_id   BIGINT NULL,
    estado_esperado           VARCHAR(20) NULL,
    conferido_em              DATETIME NULL,
    conferido_por             BIGINT NULL,
    localizacao_encontrada_id BIGINT NULL,
    estado_encontrado         ENUM('bom', 'regular', 'danificado', 'necessita_reparo') NULL,
    observacao                VARCHAR(500) NULL,
    divergencia_local         BOOLEAN NOT NULL DEFAULT FALSE,
    divergencia_estado        BOOLEAN NOT NULL DEFAULT FALSE,
    divergencia_situacao      BOOLEAN NOT NULL DEFAULT FALSE,  -- ex.: encontrado na estante, mas registrado como emprestado ou extraviado
    -- Decisão administrativa para divergências e ausências (nada é decidido automaticamente).
    decisao                   ENUM('atualizar_cadastro', 'manter_cadastro', 'registrar_extravio', 'manter_sem_alteracao') NULL,
    decisao_observacao        VARCHAR(500) NULL,
    decidido_por              BIGINT NULL,
    decidido_em               DATETIME NULL,
    correcoes                 SMALLINT NOT NULL DEFAULT 0,     -- correções autorizadas da conferência
    criado_em                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY inventario_itens_exemplar_uk (inventario_id, exemplar_id),
    KEY inventario_itens_conferido_idx (inventario_id, conferido_em),
    KEY inventario_itens_exemplar_idx (exemplar_id),
    CONSTRAINT fk_inv_itens_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id),
    CONSTRAINT fk_inv_itens_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_inv_itens_loc_esperada FOREIGN KEY (localizacao_esperada_id) REFERENCES localizacoes (id),
    CONSTRAINT fk_inv_itens_loc_encontrada FOREIGN KEY (localizacao_encontrada_id) REFERENCES localizacoes (id),
    CONSTRAINT fk_inv_itens_conferido_por FOREIGN KEY (conferido_por) REFERENCES administradores (id),
    CONSTRAINT fk_inv_itens_decidido_por FOREIGN KEY (decidido_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Só um inventário em andamento ou pausado aceita conferências e decisões.
CREATE TRIGGER inventario_itens_inserir
BEFORE INSERT ON inventario_itens
FOR EACH ROW
BEGIN
    IF (SELECT status FROM inventarios WHERE id = NEW.inventario_id) NOT IN ('em_andamento', 'pausado') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O inventário não está em andamento.';
    END IF;
END;

CREATE TRIGGER inventario_itens_alterar
BEFORE UPDATE ON inventario_itens
FOR EACH ROW
BEGIN
    IF (SELECT status FROM inventarios WHERE id = OLD.inventario_id) NOT IN ('em_andamento', 'pausado') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este inventário já foi finalizado e não pode ser alterado.';
    END IF;
    IF NEW.inventario_id <> OLD.inventario_id OR NEW.exemplar_id <> OLD.exemplar_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O exemplar de uma conferência não pode ser trocado.';
    END IF;
END;

CREATE TRIGGER inventario_itens_sem_exclusao
BEFORE DELETE ON inventario_itens
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conferências do inventário não podem ser excluídas.';

-- ---------------------------------------------------------
-- Modelos de etiqueta (medidas em milímetros)
-- ---------------------------------------------------------
CREATE TABLE etiqueta_modelos (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome               VARCHAR(100) NOT NULL,
    tipo               ENUM('patrimonial', 'lombada') NOT NULL DEFAULT 'patrimonial',
    pagina_largura     DECIMAL(6,2) NOT NULL,
    pagina_altura      DECIMAL(6,2) NOT NULL,
    etiqueta_largura   DECIMAL(6,2) NOT NULL,
    etiqueta_altura    DECIMAL(6,2) NOT NULL,
    margem_superior    DECIMAL(6,2) NOT NULL DEFAULT 0,
    margem_esquerda    DECIMAL(6,2) NOT NULL DEFAULT 0,
    espaco_horizontal  DECIMAL(6,2) NOT NULL DEFAULT 0,  -- entre colunas
    espaco_vertical    DECIMAL(6,2) NOT NULL DEFAULT 0,  -- entre linhas
    colunas            SMALLINT NOT NULL DEFAULT 1,
    linhas             SMALLINT NOT NULL DEFAULT 1,
    codigo_barras      BOOLEAN NOT NULL DEFAULT TRUE,
    mostrar_biblioteca BOOLEAN NOT NULL DEFAULT TRUE,
    mostrar_titulo     BOOLEAN NOT NULL DEFAULT TRUE,
    mostrar_cdd        BOOLEAN NOT NULL DEFAULT TRUE,
    mostrar_local      BOOLEAN NOT NULL DEFAULT TRUE,
    ativo              BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY etiqueta_modelos_nome_uk (nome),
    CONSTRAINT etiqueta_modelos_medidas_ck CHECK (pagina_largura > 0 AND pagina_altura > 0 AND etiqueta_largura > 0 AND etiqueta_altura > 0
        AND margem_superior >= 0 AND margem_esquerda >= 0 AND espaco_horizontal >= 0 AND espaco_vertical >= 0
        AND colunas BETWEEN 1 AND 20 AND linhas BETWEEN 1 AND 40)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelos iniciais. Confira as medidas na embalagem da folha e faça um teste em papel comum.
INSERT INTO etiqueta_modelos (nome, tipo, pagina_largura, pagina_altura, etiqueta_largura, etiqueta_altura, margem_superior, margem_esquerda, espaco_horizontal, espaco_vertical, colunas, linhas, codigo_barras, mostrar_biblioteca, mostrar_titulo, mostrar_cdd, mostrar_local) VALUES
    ('Patrimonial A4 — 3 × 11 (63,5 × 25,4 mm)', 'patrimonial', 210, 297, 63.5, 25.4, 8.8, 7.2, 2.5, 0, 3, 11, TRUE, TRUE, TRUE, TRUE, TRUE),
    ('Patrimonial A4 — 2 × 7 (99,1 × 38,1 mm)', 'patrimonial', 210, 297, 99.1, 38.1, 15.1, 4.6, 2.5, 0, 2, 7, TRUE, TRUE, TRUE, TRUE, TRUE),
    ('Lombada A4 — 5 × 13 (38,1 × 21,2 mm)', 'lombada', 210, 297, 38.1, 21.2, 10.7, 5.7, 2.0, 0, 5, 13, FALSE, FALSE, FALSE, TRUE, TRUE),
    ('Individual — 50 × 25 mm (impressora de etiquetas)', 'patrimonial', 50, 25, 50, 25, 0, 0, 0, 0, 1, 1, TRUE, TRUE, TRUE, TRUE, FALSE);
