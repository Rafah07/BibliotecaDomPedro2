-- =========================================================
-- 130 · Conferência contínua do Inventário — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- 1. Reserva do código BDP ligada ao FORMULÁRIO (processo de cadastro):
--    Antes, "Gerar código BDP" devolvia a reserva ainda não usada da MESMA PESSOA por até
--    12 horas, mesmo em outra aba ou em outro livro: duas abas podiam mostrar o mesmo código
--    e ele podia acabar escrito em dois carimbos. Agora cada formulário tem um identificador
--    próprio (exemplar_codigo_reservas.processo) e só o mesmo formulário recebe a mesma reserva.
--    Reservas não usadas podem ser canceladas (cancelada_em). Nenhum número volta para a
--    sequência: código cancelado, expirado ou de exemplar baixado nunca é entregue de novo.
--
-- 2. Vínculo PERMANENTE entre a linha da planilha e o exemplar físico:
--    registros_legados.exemplar_id (único). Antes o vínculo só existia dentro de cada
--    inventário, e um inventário novo deixava de fora TODAS as linhas de uma obra assim que
--    UM exemplar dela era cadastrado. Agora cada linha é conferida individualmente.
--    O preenchimento inicial copia os vínculos já registrados nos inventários anteriores.
--
-- 3. "Vincular a uma obra já verificada": a linha da planilha pode passar para outra obra
--    (registros_legados.livro_id), guardando a obra original (livro_original_id). Todo vínculo
--    e toda correção ficam em registro_vinculos (histórico que não é apagado nem alterado).
--    Para isso, um item de inventário AINDA SEM EXEMPLAR pode mudar de obra nos inventários
--    em andamento (o gatilho inventario_itens_alterar foi ajustado). Itens com exemplar
--    continuam sem poder trocar de livro.
--
-- 4. "Estante atual": inventario_estantes guarda a finalização (e a reabertura) da
--    conferência de cada estante/prateleira em cada inventário.
--
-- Incremental e seguro: não apaga nem recria tabelas, não muda dados bibliográficos, não
-- cria exemplares e não altera inventários concluídos.
-- Aplicar com: php database/migrar.php (faça antes a cópia de segurança).
-- =========================================================

-- ---------------------------------------------------------
-- 1. Reservas do código BDP por formulário
-- ---------------------------------------------------------
ALTER TABLE exemplar_codigo_reservas
    ADD COLUMN processo      CHAR(32) NULL AFTER livro_id,          -- identificador do formulário que pediu o código
    ADD COLUMN cancelada_em  DATETIME NULL AFTER exemplar_id,       -- reserva cancelada (o código não é usado nem reaproveitado)
    ADD COLUMN cancelada_por BIGINT NULL AFTER cancelada_em,
    ADD KEY exemplar_codigo_reservas_processo_idx (processo, reservado_por),
    ADD CONSTRAINT fk_reservas_cancelada_por FOREIGN KEY (cancelada_por) REFERENCES administradores (id);

-- ---------------------------------------------------------
-- 2 e 3. Vínculos das linhas da planilha
-- ---------------------------------------------------------
ALTER TABLE registros_legados
    ADD COLUMN exemplar_id        BIGINT NULL AFTER livro_id,       -- exemplar físico que esta linha representa (NULL = ainda sem vínculo)
    ADD COLUMN vinculado_em       DATETIME NULL AFTER exemplar_id,
    ADD COLUMN vinculado_por      BIGINT NULL AFTER vinculado_em,
    ADD COLUMN livro_original_id  BIGINT NULL AFTER vinculado_por,  -- obra criada na importação, quando a linha foi vinculada a outra obra
    ADD UNIQUE KEY registros_legados_exemplar_uk (exemplar_id),
    ADD KEY registros_legados_original_idx (livro_original_id),
    ADD CONSTRAINT fk_legados_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    ADD CONSTRAINT fk_legados_vinculado_por FOREIGN KEY (vinculado_por) REFERENCES administradores (id),
    ADD CONSTRAINT fk_legados_livro_original FOREIGN KEY (livro_original_id) REFERENCES livros (id);

-- Os VALORES ORIGINAIS da planilha continuam imutáveis (como na migração 030). Só podem mudar
-- as colunas de vínculo: a obra atual (livro_id, com a original guardada em livro_original_id)
-- e o exemplar físico ligado à linha.
DROP TRIGGER IF EXISTS registros_legados_imutavel;

CREATE TRIGGER registros_legados_imutavel
BEFORE UPDATE ON registros_legados
FOR EACH ROW
BEGIN
    IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.importacao_id <=> OLD.importacao_id) OR NOT (NEW.importacao_item_id <=> OLD.importacao_item_id)
       OR NOT (NEW.arquivo <=> OLD.arquivo) OR NOT (NEW.linha <=> OLD.linha)
       OR NOT (NEW.titulo_original <=> OLD.titulo_original) OR NOT (NEW.autor_original <=> OLD.autor_original)
       OR NOT (NEW.codigo_autor_antigo <=> OLD.codigo_autor_antigo) OR NOT (NEW.localizacao_antiga <=> OLD.localizacao_antiga)
       OR NOT (NEW.cdd_original <=> OLD.cdd_original) OR NOT (NEW.assunto_original <=> OLD.assunto_original)
       OR NOT (NEW.categoria_original <=> OLD.categoria_original) OR NOT (NEW.quantidade_informada <=> OLD.quantidade_informada)
       OR NOT (NEW.representa_exemplar <=> OLD.representa_exemplar) OR NOT (NEW.observacoes <=> OLD.observacoes)
       OR NOT (CAST(NEW.dados_originais AS CHAR) <=> CAST(OLD.dados_originais AS CHAR)) OR NOT (NEW.importado_em <=> OLD.importado_em) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os registros legados não podem ser alterados.';
    END IF;
    IF NOT (NEW.livro_id <=> OLD.livro_id) AND NEW.livro_original_id IS NULL AND NOT (NEW.livro_id <=> OLD.livro_original_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A obra original de um registro legado precisa ficar guardada (livro_original_id).';
    END IF;
END;

CREATE TABLE registro_vinculos (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    registro_id        BIGINT NOT NULL,                 -- registros_legados.id
    acao               ENUM('vincular_exemplar', 'desfazer_exemplar', 'vincular_obra', 'desfazer_obra') NOT NULL,
    livro_anterior_id  BIGINT NULL,
    livro_novo_id      BIGINT NULL,
    exemplar_id        BIGINT NULL,
    inventario_id      BIGINT NULL,
    observacao         VARCHAR(500) NULL,
    administrador_id   BIGINT NULL,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY registro_vinculos_registro_idx (registro_id, criado_em),
    KEY registro_vinculos_livro_idx (livro_novo_id),
    CONSTRAINT fk_vinculos_registro FOREIGN KEY (registro_id) REFERENCES registros_legados (id),
    CONSTRAINT fk_vinculos_livro_anterior FOREIGN KEY (livro_anterior_id) REFERENCES livros (id),
    CONSTRAINT fk_vinculos_livro_novo FOREIGN KEY (livro_novo_id) REFERENCES livros (id),
    CONSTRAINT fk_vinculos_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_vinculos_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id),
    CONSTRAINT fk_vinculos_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER registro_vinculos_sem_exclusao
BEFORE DELETE ON registro_vinculos
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de vínculos da planilha não é apagado.';

CREATE TRIGGER registro_vinculos_sem_alteracao
BEFORE UPDATE ON registro_vinculos
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de vínculos da planilha não é alterado.';

-- Vínculos já feitos nos inventários (linha da planilha que virou exemplar): passam a ser permanentes.
-- Se, por algum motivo, o mesmo exemplar aparecer ligado a duas linhas, só a primeira é copiada (IGNORE).
UPDATE IGNORE registros_legados r
JOIN (SELECT i.importacao_item_id, MIN(i.exemplar_id) AS exemplar_id
        FROM inventario_itens i
       WHERE i.importacao_item_id IS NOT NULL AND i.exemplar_id IS NOT NULL
       GROUP BY i.importacao_item_id) v ON v.importacao_item_id = r.importacao_item_id
JOIN exemplares e ON e.id = v.exemplar_id AND e.livro_id = r.livro_id
   SET r.exemplar_id = v.exemplar_id, r.vinculado_em = e.criado_em
 WHERE r.exemplar_id IS NULL;

-- Um item de inventário ainda SEM exemplar (linha da planilha) pode mudar de obra enquanto o
-- inventário está aberto ("Vincular a uma obra já verificada"). O resto continua igual à migração 110.
DROP TRIGGER IF EXISTS inventario_itens_alterar;

CREATE TRIGGER inventario_itens_alterar
BEFORE UPDATE ON inventario_itens
FOR EACH ROW
BEGIN
    IF (SELECT status FROM inventarios WHERE id = OLD.inventario_id) NOT IN ('em_andamento', 'pausado') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este inventário já foi finalizado e não pode ser alterado.';
    END IF;
    IF NEW.inventario_id <> OLD.inventario_id
       OR (OLD.exemplar_id IS NOT NULL AND NOT (NEW.exemplar_id <=> OLD.exemplar_id))
       OR (OLD.livro_id IS NOT NULL AND NOT (NEW.livro_id <=> OLD.livro_id) AND OLD.exemplar_id IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O exemplar de uma conferência não pode ser trocado.';
    END IF;
END;

-- ---------------------------------------------------------
-- 4. Conferência por estante
-- ---------------------------------------------------------
CREATE TABLE inventario_estantes (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    inventario_id   BIGINT NOT NULL,
    localizacao_id  BIGINT NOT NULL,
    status          ENUM('aberta', 'finalizada') NOT NULL DEFAULT 'aberta',
    finalizada_em   DATETIME NULL,
    finalizada_por  BIGINT NULL,
    nao_localizados INT NOT NULL DEFAULT 0,      -- quantos itens foram confirmados como não localizados na finalização
    reaberta_em     DATETIME NULL,
    reaberta_por    BIGINT NULL,
    observacao      VARCHAR(500) NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY inventario_estantes_uk (inventario_id, localizacao_id),
    CONSTRAINT fk_estantes_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id),
    CONSTRAINT fk_estantes_local FOREIGN KEY (localizacao_id) REFERENCES localizacoes (id),
    CONSTRAINT fk_estantes_finalizada_por FOREIGN KEY (finalizada_por) REFERENCES administradores (id),
    CONSTRAINT fk_estantes_reaberta_por FOREIGN KEY (reaberta_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices usados pelo painel "Estante atual" (itens esperados e encontrados em cada localização).
ALTER TABLE inventario_itens
    ADD KEY inventario_itens_esperada_idx (inventario_id, localizacao_esperada_id),
    ADD KEY inventario_itens_encontrada_idx (inventario_id, localizacao_encontrada_id);
