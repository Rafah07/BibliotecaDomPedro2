-- =========================================================
-- 250 · Organização por equipes (módulo TEMPORÁRIO de reorganização) ligada ao inventário
-- MySQL 8.0+ / MariaDB 10.4+ — Biblioteca Dom Pedro II
--
-- O período das equipes passa a pertencer a um inventário ("Ativar organização por equipes" na
-- configuração do inventário). Os lotes das equipes deixam de ser lotes à parte: cada reorg_lotes é
-- só a extensão temporária (participantes, etapas, sessões) de um lote permanente (cadastro_lotes).
--
--   reorg_periodos.inventario_id  inventário das equipes (NULL = período antigo, anterior a esta migração;
--                                 ao ativar as equipes num inventário, o período ativo antigo é vinculado
--                                 a ele, sem criar outro)
--
-- Também copia nome, prazo, instruções e observações dos lotes das equipes para o lote permanente
-- (migração 240), quando ele ainda não tem: assim esses dados continuam no acervo depois que o módulo
-- for removido. Nada é apagado. Remoção futura: a coluna sai junto com reorg_periodos (README do módulo).
-- Aplicar com: php database/migrar.php (a cópia de segurança é feita antes).
-- =========================================================

ALTER TABLE reorg_periodos
    ADD COLUMN inventario_id BIGINT NULL AFTER nome,
    ADD KEY reorg_periodos_inventario_idx (inventario_id, ativo),
    ADD CONSTRAINT fk_reorg_periodos_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id);

UPDATE cadastro_lotes c JOIN reorg_lotes l ON l.cadastro_lote_id = c.id
   SET c.nome = COALESCE(c.nome, l.nome),
       c.prazo = COALESCE(c.prazo, l.prazo),
       c.instrucoes = COALESCE(c.instrucoes, l.instrucoes),
       c.observacoes = COALESCE(c.observacoes, l.observacoes);
