-- =========================================================
-- 150 · Preparo dos exemplares (carimbo, lotes, triagem), ocorrências, operações reversíveis,
--       mapa das estantes e registro de cópias de segurança — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- 1. Carimbo do código BDP (Planilha de Códigos BDP):
--      exemplares.carimbo_status  'aguardando' = o código já existe no banco, mas ainda não foi escrito
--                                 no carimbo do livro; 'carimbado' = a equipe confirmou o carimbo.
--      Os exemplares que JÁ existem ficam 'carimbado': até esta versão o sistema só salvava o exemplar
--      depois de o código ser escrito no carimbo (carimbado_em fica vazio = "antes do controle").
--      O código BDP continua reservado ao exemplar desde o cadastro e nunca é reaproveitado.
-- 2. Lotes de cadastramento (cadastro_lotes): "Lote 001 — 14/11/2026 — Equipe 1". Cada exemplar novo
--    recebe o lote e a ordem dentro do lote, que não mudam depois (a ordem da lista impressa
--    acompanha a ordem física dos livros separados). Exemplares antigos ficam sem lote.
-- 3. Liberação para empréstimo: exemplar novo que ainda aguarda carimbo ou organização fica
--    "Aguardando liberação" (situacao 'aguardando', liberacao_pendente = 1) conforme as regras em
--    Configurações. Nenhum exemplar existente muda de situação nesta migração.
-- 4. Ocorrências e conservação (exemplar_ocorrencias): problemas físicos de cada EXEMPLAR, com a
--    decisão de deixar ou não indisponível para empréstimo, resolução e histórico.
-- 5. Operações (operacoes): cada ação da equipe que altera exemplares (mover, movimentação em lote,
--    carimbo, ocorrência...) ganha um número; movimentacoes.operacao_id liga o histórico a ela.
--    Desfazer cria uma NOVA operação e nunca apaga a original.
-- 6. Mapa visual: posição e tamanho de cada estante no desenho (não muda nenhum exemplar).
-- 7. Administradores: equipe (relatórios) e última localização usada (sugestão no registro).
-- 8. backups: registro das cópias de segurança geradas (arquivo, tamanho, verificação, quem).
--
-- Incremental e seguro: não apaga tabelas nem dados e não muda a localização, a situação nem
-- os dados bibliográficos de nenhum registro existente.
-- Aplicar com: php database/migrar.php (faça antes a cópia de segurança).
-- =========================================================

-- ---------------------------------------------------------
-- 2. Lotes de cadastramento
-- ---------------------------------------------------------
CREATE TABLE cadastro_lotes (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    numero         INT UNSIGNED NOT NULL,                 -- 1, 2, 3... exibido como "Lote 001"
    equipe         VARCHAR(80) NULL,
    descricao      VARCHAR(255) NULL,
    responsavel_id BIGINT NULL,                           -- quem abriu o lote (os exemplares entram no lote aberto de quem registra)
    status         ENUM('aberto', 'fechado') NOT NULL DEFAULT 'aberto',
    total          INT NOT NULL DEFAULT 0,                -- última ordem usada dentro do lote
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fechado_em     DATETIME NULL,
    fechado_por    BIGINT NULL,
    UNIQUE KEY cadastro_lotes_numero_uk (numero),
    KEY cadastro_lotes_responsavel_idx (responsavel_id, status),
    CONSTRAINT fk_lotes_responsavel FOREIGN KEY (responsavel_id) REFERENCES administradores (id),
    CONSTRAINT fk_lotes_fechado_por FOREIGN KEY (fechado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER cadastro_lotes_sem_exclusao
BEFORE DELETE ON cadastro_lotes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lotes de cadastramento não são apagados.';

-- ---------------------------------------------------------
-- 1 e 3. Carimbo, lote e liberação dos exemplares
-- ---------------------------------------------------------
-- Os exemplares existentes recebem 'carimbado' (padrão temporário); depois o padrão vira 'aguardando'.
ALTER TABLE exemplares
    ADD COLUMN carimbo_status     ENUM('aguardando', 'carimbado') NOT NULL DEFAULT 'carimbado' AFTER codigo_individual,
    ADD COLUMN carimbado_em       DATETIME NULL AFTER carimbo_status,
    ADD COLUMN carimbado_por      BIGINT NULL AFTER carimbado_em,
    ADD COLUMN carimbo_observacao VARCHAR(255) NULL AFTER carimbado_por,
    ADD COLUMN lote_id            BIGINT NULL AFTER carimbo_observacao,
    ADD COLUMN lote_ordem         INT NULL AFTER lote_id,
    ADD COLUMN liberacao_pendente BOOLEAN NOT NULL DEFAULT FALSE AFTER situacao;

ALTER TABLE exemplares
    ALTER COLUMN carimbo_status SET DEFAULT 'aguardando',
    ADD UNIQUE KEY exemplares_lote_ordem_uk (lote_id, lote_ordem),
    ADD KEY exemplares_carimbo_idx (carimbo_status, criado_em),
    ADD KEY exemplares_criado_idx (criado_em),
    ADD KEY exemplares_cadastrado_por_idx (cadastrado_por, criado_em),
    ADD CONSTRAINT fk_exemplares_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    ADD CONSTRAINT fk_exemplares_carimbado_por FOREIGN KEY (carimbado_por) REFERENCES administradores (id);

-- O código e o livro de um exemplar nunca mudam, a baixa é definitiva e o lote/ordem não mudam
-- depois de atribuídos (a lista impressa precisa continuar batendo com a ordem física).
DROP TRIGGER IF EXISTS exemplares_preservar_identidade;

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
    IF (OLD.lote_id IS NOT NULL AND NOT (NEW.lote_id <=> OLD.lote_id)) OR (OLD.lote_ordem IS NOT NULL AND NOT (NEW.lote_ordem <=> OLD.lote_ordem)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O lote e a ordem de cadastramento de um exemplar não mudam.';
    END IF;
END;

-- ---------------------------------------------------------
-- 5. Operações (desfazer com segurança)
-- ---------------------------------------------------------
CREATE TABLE operacoes (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    tipo                 VARCHAR(40) NOT NULL,          -- mover | movimentacao_lote | carimbo | ocorrencia | ocorrencia_resolvida | conferencia | desfazer ...
    descricao            VARCHAR(500) NOT NULL,
    total                INT NOT NULL DEFAULT 0,        -- exemplares afetados
    detalhes             JSON NULL,
    chave                CHAR(32) NULL,                 -- token do formulário: a mesma operação não é gravada duas vezes
    administrador_id     BIGINT NULL,
    criado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    desfaz_operacao_id   BIGINT NULL,                   -- esta operação desfez aquela
    desfeita_em          DATETIME NULL,
    desfeita_por         BIGINT NULL,
    desfeita_por_operacao_id BIGINT NULL,
    UNIQUE KEY operacoes_chave_uk (chave),
    KEY operacoes_criado_idx (criado_em),
    KEY operacoes_admin_idx (administrador_id, criado_em),
    KEY operacoes_tipo_idx (tipo, criado_em),
    CONSTRAINT fk_operacoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT fk_operacoes_desfeita_por FOREIGN KEY (desfeita_por) REFERENCES administradores (id),
    CONSTRAINT fk_operacoes_desfaz FOREIGN KEY (desfaz_operacao_id) REFERENCES operacoes (id),
    CONSTRAINT fk_operacoes_desfeita_op FOREIGN KEY (desfeita_por_operacao_id) REFERENCES operacoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER operacoes_sem_exclusao
BEFORE DELETE ON operacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O registro de operações não é apagado.';

ALTER TABLE movimentacoes
    MODIFY COLUMN tipo ENUM('cadastro', 'emprestimo', 'devolucao', 'manutencao', 'localizacao', 'inventario', 'extravio', 'baixa', 'renovacao', 'conservacao', 'situacao', 'carimbo', 'ocorrencia') NOT NULL,
    ADD COLUMN operacao_id BIGINT NULL AFTER inventario_id,
    ADD KEY movimentacoes_operacao_idx (operacao_id),
    ADD KEY movimentacoes_responsavel_idx (responsavel_id, criado_em),
    ADD CONSTRAINT fk_movimentacoes_operacao FOREIGN KEY (operacao_id) REFERENCES operacoes (id);

-- ---------------------------------------------------------
-- 4. Ocorrências e conservação
-- ---------------------------------------------------------
CREATE TABLE exemplar_ocorrencias (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    exemplar_id          BIGINT NOT NULL,
    tipo                 ENUM('capa_danificada', 'paginas_rasgadas', 'paginas_faltando', 'encadernacao_danificada', 'molhado', 'restauracao', 'outro') NOT NULL,
    descricao            VARCHAR(1000) NULL,
    bloqueia_emprestimo  BOOLEAN NOT NULL DEFAULT FALSE,   -- "Deixar exemplar indisponível para empréstimo?"
    status               ENUM('aberta', 'resolvida', 'cancelada') NOT NULL DEFAULT 'aberta',
    situacao_anterior    VARCHAR(20) NULL,                -- situação do exemplar quando a ocorrência o bloqueou
    inventario_id        BIGINT NULL,
    operacao_id          BIGINT NULL,
    registrada_por       BIGINT NULL,
    criado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    resolvida_em         DATETIME NULL,
    resolvida_por        BIGINT NULL,
    resolucao            VARCHAR(1000) NULL,
    KEY exemplar_ocorrencias_exemplar_idx (exemplar_id, status),
    KEY exemplar_ocorrencias_status_idx (status, criado_em),
    CONSTRAINT fk_ocorrencias_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_ocorrencias_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id),
    CONSTRAINT fk_ocorrencias_operacao FOREIGN KEY (operacao_id) REFERENCES operacoes (id),
    CONSTRAINT fk_ocorrencias_registrada_por FOREIGN KEY (registrada_por) REFERENCES administradores (id),
    CONSTRAINT fk_ocorrencias_resolvida_por FOREIGN KEY (resolvida_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER exemplar_ocorrencias_sem_exclusao
BEFORE DELETE ON exemplar_ocorrencias
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ocorrências não são apagadas. Marque como resolvida.';

-- ---------------------------------------------------------
-- 6. Mapa visual das estantes (só desenho: não muda exemplares)
-- ---------------------------------------------------------
ALTER TABLE estantes
    ADD COLUMN mapa_x       INT NULL AFTER ordem,
    ADD COLUMN mapa_y       INT NULL AFTER mapa_x,
    ADD COLUMN mapa_largura INT NULL AFTER mapa_y,
    ADD COLUMN mapa_altura  INT NULL AFTER mapa_largura;

-- ---------------------------------------------------------
-- 7. Equipe e última localização usada por administrador
-- ---------------------------------------------------------
ALTER TABLE administradores
    ADD COLUMN equipe                VARCHAR(80) NULL,
    ADD COLUMN ultima_localizacao_id BIGINT NULL,
    ADD KEY administradores_equipe_idx (equipe),
    ADD CONSTRAINT fk_administradores_ultima_local FOREIGN KEY (ultima_localizacao_id) REFERENCES localizacoes (id);

-- ---------------------------------------------------------
-- 8. Cópias de segurança geradas
-- ---------------------------------------------------------
CREATE TABLE backups (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    arquivo          VARCHAR(190) NOT NULL,
    origem           ENUM('servidor', 'download', 'automatico', 'migracao') NOT NULL,
    motivo           VARCHAR(255) NULL,
    tamanho          BIGINT NULL,
    sha256           CHAR(64) NULL,
    tabelas          INT NULL,
    linhas           BIGINT NULL,
    verificado       BOOLEAN NOT NULL DEFAULT FALSE,   -- arquivo conferido depois de gravado (tamanho, final e soma)
    administrador_id BIGINT NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY backups_criado_idx (criado_em),
    CONSTRAINT fk_backups_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
