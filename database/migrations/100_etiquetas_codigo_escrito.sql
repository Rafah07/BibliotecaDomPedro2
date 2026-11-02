-- =========================================================
-- 100 · Etiquetas só com código escrito (sem código de barras) — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Decisão da biblioteca: os exemplares são identificados SOMENTE pelo código escrito
-- (BDP-000001), digitado no sistema. Não há código de barras, QR Code, RFID ou NFC.
--
-- Incremental e seguro:
--   * Não cria, altera ou apaga exemplares, códigos BDP, empréstimos, históricos ou CDDs.
--   * Não recria tabelas. A coluna etiqueta_modelos.codigo_barras CONTINUA existindo
--     (para não quebrar bancos e backups antigos), mas o sistema não a usa mais: nenhuma
--     etiqueta desenha barras. Aqui ela só é zerada, para o cadastro refletir a decisão.
--   * Acrescenta modelos simples de etiqueta patrimonial de 50 × 20 mm
--     ("BIBLIOTECA DOM PEDRO II" + código em destaque) e um de lombada individual.
--     INSERT IGNORE: se já existir um modelo com o mesmo nome, ele é mantido como está.
--
-- Sem esta migração o sistema também funciona (as barras já não são desenhadas);
-- só os modelos novos de 50 × 20 mm não aparecem.
--
-- Depende de 070 (etiqueta_modelos).
-- =========================================================

UPDATE etiqueta_modelos SET codigo_barras = FALSE WHERE codigo_barras <> FALSE;

ALTER TABLE etiqueta_modelos ALTER COLUMN codigo_barras SET DEFAULT FALSE;

INSERT IGNORE INTO etiqueta_modelos (nome, tipo, pagina_largura, pagina_altura, etiqueta_largura, etiqueta_altura, margem_superior, margem_esquerda, espaco_horizontal, espaco_vertical, colunas, linhas, codigo_barras, mostrar_biblioteca, mostrar_titulo, mostrar_cdd, mostrar_local) VALUES
    ('Patrimonial A4 — 3 × 12 (50 × 20 mm, com espaço para recorte)', 'patrimonial', 210, 297, 50, 20, 12, 20, 10, 3, 3, 12, FALSE, TRUE, FALSE, FALSE, FALSE),
    ('Patrimonial individual — 50 × 20 mm', 'patrimonial', 50, 20, 50, 20, 0, 0, 0, 0, 1, 1, FALSE, TRUE, FALSE, FALSE, FALSE),
    ('Lombada individual — 30 × 20 mm', 'lombada', 30, 20, 30, 20, 0, 0, 0, 0, 1, 1, FALSE, FALSE, FALSE, TRUE, FALSE);
