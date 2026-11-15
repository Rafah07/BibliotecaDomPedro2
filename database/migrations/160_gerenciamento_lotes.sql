-- =========================================================
-- 160 · Gerenciamento de lotes e etapas de trabalho — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Estende os lotes de cadastramento da migração 150 (cadastro_lotes) para acompanhar o exemplar
-- físico do cadastro até a estante definitiva:
--
--   Em cadastro (aberto) → Lote fechado — Aguardando carimbagem (fechado) → Em carimbagem →
--   Aguardando organização nas estantes → Lote encerrado
--
-- 1. cadastro_lotes ganha: modo ('automatico' = lote pessoal criado sozinho, como na migração 150;
--    'gerenciado' = aberto por um administrador pela Conferência), capacidade (exemplares físicos),
--    forma de fechamento, datas e responsáveis de cada etapa. Os lotes existentes continuam como
--    estão (modo 'automatico', sem capacidade, mesmo status e mesmos exemplares).
-- 2. cadastro_lote_usuarios: quem pode cadastrar em cada lote (vários usuários por lote e o mesmo
--    usuário em vários lotes). Remover alguém não apaga a linha: grava quando e quem removeu.
--    Os lotes existentes recebem quem os abriu como participante.
-- 3. administradores.lote_ativo_id: o lote em que cada pessoa está trabalhando ("Meus lotes").
-- 4. exemplares.organizado_em/por/localizacao_id: quem colocou o exemplar na estante definitiva pela
--    primeira vez. Movimentações posteriores NÃO apagam essa informação.
-- 5. lote_eventos: histórico permanente de cada lote (criação, atribuições, cadastros, fechamento,
--    carimbagem, organização, encerramento, correções). Não pode ser alterado nem apagado; a chave
--    impede gravar a mesma operação duas vezes (clique duplo, reenvio).
-- 6. conferencia_duvidas: dúvidas da equipe sobre a obra ou o exemplar (ISBN desconhecido, edição...),
--    com resolução e responsável. Não são apagadas.
-- 7. formulario_rascunhos: rascunhos dos formulários interrompidos, por usuário, formulário e lote.
--    Um rascunho nunca cria exemplar, nunca ocupa vaga e nunca guarda código BDP.
--
-- Incremental e seguro: não apaga tabelas nem dados, não exclui lotes e não muda exemplares.
-- Aplicar com: php database/migrar.php (a cópia de segurança é feita antes).
-- =========================================================

-- ---------------------------------------------------------
-- 1. Lotes: modo, capacidade e etapas
-- ---------------------------------------------------------
ALTER TABLE cadastro_lotes
    MODIFY COLUMN status ENUM('aberto', 'fechado', 'em_carimbagem', 'aguardando_organizacao', 'encerrado') NOT NULL DEFAULT 'aberto',
    ADD COLUMN modo                     ENUM('automatico', 'gerenciado') NOT NULL DEFAULT 'automatico' AFTER numero,
    ADD COLUMN capacidade               INT UNSIGNED NULL AFTER modo,          -- máximo de exemplares físicos (NULL = sem limite, lotes antigos)
    ADD COLUMN fechamento_tipo          ENUM('automatico', 'antecipado') NULL AFTER fechado_por,
    ADD COLUMN carimbagem_iniciada_em   DATETIME NULL AFTER fechamento_tipo,
    ADD COLUMN carimbagem_iniciada_por  BIGINT NULL AFTER carimbagem_iniciada_em,
    ADD COLUMN carimbagem_concluida_em  DATETIME NULL AFTER carimbagem_iniciada_por,
    ADD COLUMN carimbagem_concluida_por BIGINT NULL AFTER carimbagem_concluida_em,
    ADD COLUMN encerrado_em             DATETIME NULL AFTER carimbagem_concluida_por,
    ADD COLUMN encerrado_por            BIGINT NULL AFTER encerrado_em,
    ADD KEY cadastro_lotes_status_idx (status, numero),
    ADD KEY cadastro_lotes_modo_idx (modo, status),
    ADD CONSTRAINT fk_lotes_carimbagem_iniciada_por FOREIGN KEY (carimbagem_iniciada_por) REFERENCES administradores (id),
    ADD CONSTRAINT fk_lotes_carimbagem_concluida_por FOREIGN KEY (carimbagem_concluida_por) REFERENCES administradores (id),
    ADD CONSTRAINT fk_lotes_encerrado_por FOREIGN KEY (encerrado_por) REFERENCES administradores (id);

-- O número do lote não muda, o encerramento original nunca é apagado e um lote encerrado não volta
-- para etapas anteriores (correções posteriores ficam registradas em lote_eventos).
CREATE TRIGGER cadastro_lotes_preservar
BEFORE UPDATE ON cadastro_lotes
FOR EACH ROW
BEGIN
    IF NEW.numero <> OLD.numero THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O número de um lote não muda.';
    END IF;
    IF OLD.encerrado_em IS NOT NULL AND NOT (NEW.encerrado_em <=> OLD.encerrado_em AND NEW.encerrado_por <=> OLD.encerrado_por) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O encerramento de um lote não é apagado nem alterado.';
    END IF;
    IF OLD.status = 'encerrado' AND NEW.status <> 'encerrado' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um lote encerrado não volta para etapas anteriores. Registre uma correção administrativa.';
    END IF;
END;

-- ---------------------------------------------------------
-- 2. Participantes de cada lote
-- ---------------------------------------------------------
CREATE TABLE cadastro_lote_usuarios (
    lote_id          BIGINT NOT NULL,
    administrador_id BIGINT NOT NULL,
    atribuido_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atribuido_por    BIGINT NULL,
    removido_em      DATETIME NULL,                     -- NULL = participa agora
    removido_por     BIGINT NULL,
    PRIMARY KEY (lote_id, administrador_id),
    KEY cadastro_lote_usuarios_admin_idx (administrador_id, removido_em),
    CONSTRAINT fk_lote_usuarios_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_lote_usuarios_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT fk_lote_usuarios_atribuido_por FOREIGN KEY (atribuido_por) REFERENCES administradores (id),
    CONSTRAINT fk_lote_usuarios_removido_por FOREIGN KEY (removido_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER cadastro_lote_usuarios_sem_exclusao
BEFORE DELETE ON cadastro_lote_usuarios
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A participação em um lote não é apagada. Use "Remover do lote".';

INSERT INTO cadastro_lote_usuarios (lote_id, administrador_id, atribuido_em, atribuido_por)
SELECT id, responsavel_id, criado_em, responsavel_id FROM cadastro_lotes WHERE responsavel_id IS NOT NULL;

-- ---------------------------------------------------------
-- 3. Lote ativo de cada pessoa
-- ---------------------------------------------------------
ALTER TABLE administradores
    ADD COLUMN lote_ativo_id BIGINT NULL,
    ADD CONSTRAINT fk_administradores_lote_ativo FOREIGN KEY (lote_ativo_id) REFERENCES cadastro_lotes (id);

-- ---------------------------------------------------------
-- 4. Organização original de cada exemplar
-- ---------------------------------------------------------
ALTER TABLE exemplares
    ADD COLUMN organizado_em            DATETIME NULL AFTER lote_ordem,
    ADD COLUMN organizado_por           BIGINT NULL AFTER organizado_em,
    ADD COLUMN organizado_localizacao_id BIGINT NULL AFTER organizado_por,
    ADD KEY exemplares_organizado_idx (organizado_por, organizado_em),
    ADD KEY exemplares_lote_idx (lote_id, carimbo_status),
    ADD CONSTRAINT fk_exemplares_organizado_por FOREIGN KEY (organizado_por) REFERENCES administradores (id),
    ADD CONSTRAINT fk_exemplares_organizado_local FOREIGN KEY (organizado_localizacao_id) REFERENCES localizacoes (id);

-- ---------------------------------------------------------
-- 5. Histórico permanente dos lotes
-- ---------------------------------------------------------
CREATE TABLE lote_eventos (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id          BIGINT NOT NULL,
    exemplar_id      BIGINT NULL,
    tipo             VARCHAR(40) NOT NULL,      -- lote_aberto | usuario_atribuido | usuario_removido | lote_ativo | exemplar_cadastrado | lote_fechado |
                                                -- carimbagem_iniciada | exemplar_carimbado | carimbo_desfeito | carimbagem_confirmada | exemplar_organizado |
                                                -- lote_encerrado | correcao | duvida_registrada | duvida_resolvida
    descricao        VARCHAR(500) NOT NULL,
    detalhes         JSON NULL,
    chave            VARCHAR(80) NULL,          -- mesma operação não é gravada duas vezes
    administrador_id BIGINT NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY lote_eventos_chave_uk (chave),
    KEY lote_eventos_lote_idx (lote_id, criado_em),
    KEY lote_eventos_tipo_idx (tipo, criado_em),
    KEY lote_eventos_admin_idx (administrador_id, criado_em),
    KEY lote_eventos_exemplar_idx (exemplar_id),
    CONSTRAINT fk_lote_eventos_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_lote_eventos_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_lote_eventos_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER lote_eventos_sem_exclusao
BEFORE DELETE ON lote_eventos
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico dos lotes não é apagado.';

CREATE TRIGGER lote_eventos_sem_alteracao
BEFORE UPDATE ON lote_eventos
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico dos lotes não é alterado. Registre uma correção.';

-- Lotes existentes: o histórico começa com a abertura (e o fechamento, quando houve).
INSERT INTO lote_eventos (lote_id, tipo, descricao, administrador_id, criado_em)
SELECT id, 'lote_aberto', CONCAT('Lote ', LPAD(numero, 3, '0'), ' aberto (antes do gerenciamento de lotes)'), responsavel_id, criado_em FROM cadastro_lotes;
INSERT INTO lote_eventos (lote_id, tipo, descricao, administrador_id, criado_em)
SELECT id, 'lote_fechado', CONCAT('Lote ', LPAD(numero, 3, '0'), ' fechado (antes do gerenciamento de lotes)'), fechado_por, fechado_em FROM cadastro_lotes WHERE fechado_em IS NOT NULL;

-- ---------------------------------------------------------
-- 6. Dúvidas da conferência
-- ---------------------------------------------------------
CREATE TABLE conferencia_duvidas (
    id                       BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id                 BIGINT NULL,
    exemplar_id              BIGINT NULL,
    lote_id                  BIGINT NULL,          -- lote do exemplar (aparece no relatório do lote)
    tipo                     ENUM('isbn_desconhecido', 'edicao_nao_identificada', 'informacoes_divergentes', 'associacao_incorreta', 'conservacao', 'outro') NOT NULL,
    descricao                VARCHAR(1000) NULL,
    compromete_identificacao BOOLEAN NOT NULL DEFAULT FALSE,   -- exige resolução antes de confirmar a etapa do lote
    status                   ENUM('aberta', 'resolvida') NOT NULL DEFAULT 'aberta',
    registrada_por           BIGINT NULL,
    criado_em                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvida_em             DATETIME NULL,
    resolvida_por            BIGINT NULL,
    resolucao                VARCHAR(1000) NULL,
    KEY conferencia_duvidas_status_idx (status, criado_em),
    KEY conferencia_duvidas_lote_idx (lote_id, status),
    KEY conferencia_duvidas_livro_idx (livro_id, status),
    KEY conferencia_duvidas_exemplar_idx (exemplar_id, status),
    CONSTRAINT fk_duvidas_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_duvidas_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_duvidas_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_duvidas_registrada_por FOREIGN KEY (registrada_por) REFERENCES administradores (id),
    CONSTRAINT fk_duvidas_resolvida_por FOREIGN KEY (resolvida_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER conferencia_duvidas_sem_exclusao
BEFORE DELETE ON conferencia_duvidas
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dúvidas não são apagadas. Registre a resolução.';

-- ---------------------------------------------------------
-- 7. Rascunhos de formulários interrompidos
-- ---------------------------------------------------------
CREATE TABLE formulario_rascunhos (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    administrador_id BIGINT NOT NULL,
    formulario       VARCHAR(40) NOT NULL,      -- verificar_obra | registrar_exemplar | nao_listado | cadastrar_exemplar | editar_livro
    referencia       VARCHAR(64) NOT NULL,      -- obra/inventário do formulário (ex.: "inv3-obra15")
    lote_id          BIGINT NULL,               -- lote ativo quando o rascunho foi salvo
    dados            MEDIUMTEXT NOT NULL,       -- JSON com os campos digitados (sem código BDP, sem tokens)
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY formulario_rascunhos_uk (administrador_id, formulario, referencia),
    KEY formulario_rascunhos_atualizado_idx (atualizado_em),
    CONSTRAINT fk_rascunhos_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT fk_rascunhos_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
