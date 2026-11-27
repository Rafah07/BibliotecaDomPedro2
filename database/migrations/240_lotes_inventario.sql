-- =========================================================
-- 240 · Lotes de inventário — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- O lote gerenciado (cadastro_lotes, migrações 150/160) passa a ser o ÚNICO lote do inventário:
--
--   cadastro_lotes.inventario_id  inventário a que o lote pertence (NULL = lote antigo, sem inventário)
--   cadastro_lotes.nome           nome ou identificação (ex.: "Estante A — literatura"); o número continua
--                                 sendo a identificação oficial ("Lote 001")
--   cadastro_lotes.prazo          prazo opcional
--   cadastro_lotes.instrucoes     instruções para quem trabalha no lote
--   cadastro_lotes.observacoes    observações internas
--
-- Permanente: não depende de nenhum módulo opcional. Só acrescenta colunas (NULL); nenhum lote,
-- exemplar ou código BDP existente muda. Aplicar com: php database/migrar.php (cópia de segurança antes).
-- =========================================================

ALTER TABLE cadastro_lotes
    ADD COLUMN inventario_id BIGINT NULL AFTER modo,
    ADD COLUMN nome          VARCHAR(120) NULL AFTER inventario_id,
    ADD COLUMN prazo         DATE NULL AFTER capacidade,
    ADD COLUMN instrucoes    TEXT NULL AFTER descricao,
    ADD COLUMN observacoes   TEXT NULL AFTER instrucoes,
    ADD KEY cadastro_lotes_inventario_idx (inventario_id, status),
    ADD CONSTRAINT fk_cadastro_lotes_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id);
