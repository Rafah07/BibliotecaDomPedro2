-- =========================================================
-- 050 · Inventários físicos — MySQL 8.0+
-- Biblioteca Dom Pedro II (Etapa 3.5: estrutura preparada para a Etapa 5)
--
-- "inventarios" registra cada rodada de conferência (quem abriu, quando, situação).
-- O resultado por título continua em "inventario_conferencias" (migração 001),
-- que ganha a referência à rodada. Nenhum dado existente é alterado: conferências
-- antigas ficam com inventario_id vazio.
--
-- O inventário é o único caminho para que a quantidade antiga das planilhas
-- (registros_legados) vire exemplares físicos com código BDP.
-- =========================================================

CREATE TABLE inventarios (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    titulo        VARCHAR(150) NOT NULL,
    descricao     TEXT,
    status        ENUM('aberto', 'em_andamento', 'concluido', 'cancelado') NOT NULL DEFAULT 'aberto',
    aberto_por    BIGINT NULL,             -- administradores.id
    iniciado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em  DATETIME NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY inventarios_status_idx (status, iniciado_em),
    CONSTRAINT fk_inventarios_aberto_por FOREIGN KEY (aberto_por) REFERENCES administradores (id),
    CONSTRAINT inventarios_datas_ck CHECK (concluido_em IS NULL OR concluido_em >= iniciado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE inventario_conferencias
    ADD COLUMN inventario_id BIGINT NULL AFTER id,
    ADD KEY inventario_conferencias_inventario_idx (inventario_id),
    ADD CONSTRAINT fk_conferencias_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id);
