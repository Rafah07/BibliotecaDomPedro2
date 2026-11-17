-- =========================================================
-- 170 · Linha da planilha = referência da OBRA (vários exemplares por linha) — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- A planilha original descreve OBRAS, não exemplares: uma linha (ex.: linha 125, Dom Casmurro) pode
-- corresponder a vários exemplares físicos (BDP-000101 a BDP-000105). A partir desta migração:
--
--   * a associação com a planilha pertence à OBRA (registros_legados.livro_id, que já existe);
--   * registrar um exemplar NÃO "consome" a linha: todos os exemplares da obra aproveitam a mesma
--     referência e não é preciso escolher a linha a cada cópia;
--   * o item da linha num inventário deixa de ser tratado como uma cópia física a conferir: quando
--     um exemplar da obra é encontrado, a linha fica "Obra localizada" (inventario_itens.obra_localizada_em).
--     Isso NÃO marca a linha como conferida (conferido_em continua vazio) nem como ausente.
--
-- O vínculo antigo registros_legados.exemplar_id (um exemplar por linha, migração 130) é PRESERVADO
-- como histórico: nada é apagado, nenhum código BDP muda e nenhuma obra é unida.
--
-- Incremental e seguro. Aplicar com: php database/migrar.php (a cópia de segurança é feita antes).
-- No XAMPP: C:\xampp\php\php.exe database/migrar.php
-- =========================================================

ALTER TABLE inventario_itens
    ADD COLUMN obra_localizada_em  DATETIME NULL AFTER nao_localizado_obs,   -- linha da planilha cuja obra teve exemplar encontrado neste inventário
    ADD COLUMN obra_localizada_por BIGINT NULL AFTER obra_localizada_em;

-- Inventários em andamento ou pausados: linhas da planilha cuja obra já teve exemplar encontrado
-- neste inventário passam a "Obra localizada" (inventários finalizados não são alterados).
UPDATE inventario_itens i
JOIN inventarios inv ON inv.id = i.inventario_id AND inv.status IN ('em_andamento', 'pausado')
JOIN (SELECT x.inventario_id, ex.livro_id, MIN(x.conferido_em) AS quando
        FROM inventario_itens x JOIN exemplares ex ON ex.id = x.exemplar_id
       WHERE x.conferido_em IS NOT NULL
       GROUP BY x.inventario_id, ex.livro_id) enc ON enc.inventario_id = i.inventario_id AND enc.livro_id = i.livro_id
   SET i.obra_localizada_em = enc.quando
 WHERE i.exemplar_id IS NULL AND i.conferido_em IS NULL AND i.obra_localizada_em IS NULL;
