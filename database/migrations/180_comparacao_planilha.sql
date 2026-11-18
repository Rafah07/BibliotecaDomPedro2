-- =========================================================
-- 180 · Comparar com planilha original — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- A comparação entre a planilha original (registros_legados / importacao_itens) e o acervo
-- atual é calculada na hora e é só de CONSULTA: abrir a comparação não grava nada.
-- Esta migração guarda apenas o que a equipe DECIDE ou SALVA:
--
-- 1. comparacao_resolucoes: decisões explícitas sobre um item da comparação ("confirmar
--    correspondência", "confirmar obra nova", "não é duplicidade", "marcar como resolvida",
--    "reabrir"). Histórico que não é alterado nem apagado: vale a decisão mais recente.
--
-- 2. comparacoes: resultados salvos ("Salvar resultado da comparação"), com data, responsável,
--    fonte (importações escolhidas), inventário usado, indicadores e itens. Imutáveis.
--
-- 3. exemplar_obra_correcoes: "Corrigir a obra associada a um exemplar". O exemplar continua o
--    MESMO (mesmo id, mesmo código BDP, lote, carimbo, localização, conservação, ocorrências,
--    empréstimos e histórico); só a obra (exemplares.livro_id) muda, com confirmação, motivo,
--    responsável e data. Nenhum código BDP novo é gerado.
--    Para isso, o gatilho exemplares_preservar_identidade (migração 150) passa a aceitar a troca
--    de obra SOMENTE quando a própria correção controlada a pede (@bdp_corrigir_obra_exemplar =
--    id do exemplar, definida pelo sistema na mesma transação). Qualquer outra troca continua
--    recusada. O código, a baixa e o lote/ordem continuam protegidos como antes.
--    Do mesmo jeito, nos inventários EM ANDAMENTO ou PAUSADOS o item desse exemplar acompanha a
--    obra nova; inventários concluídos ou cancelados não mudam (são histórico).
--
-- Incremental e seguro: não apaga nem recria tabelas, não muda dados bibliográficos, não cria
-- nem altera exemplares, registros da planilha ou inventários.
-- Aplicar com: php database/migrar.php (faça antes a cópia de segurança).
-- =========================================================

CREATE TABLE comparacao_resolucoes (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    alvo_tipo        ENUM('registro', 'obra', 'exemplar') NOT NULL,   -- registros_legados | livros | exemplares
    alvo_id          BIGINT NOT NULL,
    alvo2_id         BIGINT NULL,                                     -- a outra obra, em "não é duplicidade"
    categoria        VARCHAR(40) NOT NULL,                            -- categoria da comparação a que a decisão se refere
    decisao          ENUM('confirmar_correspondencia', 'confirmar_obra_nova', 'nao_duplicidade', 'resolvida', 'reaberta') NOT NULL,
    observacao       VARCHAR(500) NULL,
    administrador_id BIGINT NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY comparacao_resolucoes_alvo_idx (alvo_tipo, alvo_id, categoria, id),
    KEY comparacao_resolucoes_criado_idx (criado_em),
    CONSTRAINT fk_comp_resolucoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER comparacao_resolucoes_sem_exclusao
BEFORE DELETE ON comparacao_resolucoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As decisões da comparação com a planilha não são apagadas.';

CREATE TRIGGER comparacao_resolucoes_sem_alteracao
BEFORE UPDATE ON comparacao_resolucoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As decisões da comparação com a planilha não são alteradas. Registre uma nova decisão.';

CREATE TABLE comparacoes (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    inventario_id    BIGINT NULL,                     -- inventário usado (encontrados no inventário atual)
    fonte            VARCHAR(30) NOT NULL DEFAULT 'importacoes',
    importacoes_json JSON NOT NULL,                   -- [{id, arquivo, importado_em, registros}]
    indicadores_json JSON NOT NULL,
    itens_json       LONGTEXT NOT NULL,               -- itens da comparação (JSON compacto)
    total_itens      INT NOT NULL DEFAULT 0,
    total_atencao    INT NOT NULL DEFAULT 0,
    observacao       VARCHAR(500) NULL,
    administrador_id BIGINT NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY comparacoes_criado_idx (criado_em),
    CONSTRAINT fk_comparacoes_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id),
    CONSTRAINT fk_comparacoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER comparacoes_sem_exclusao
BEFORE DELETE ON comparacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os resultados salvos da comparação não são apagados.';

CREATE TRIGGER comparacoes_sem_alteracao
BEFORE UPDATE ON comparacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os resultados salvos da comparação não são alterados. Salve uma nova comparação.';

CREATE TABLE exemplar_obra_correcoes (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    exemplar_id       BIGINT NOT NULL,
    codigo_individual VARCHAR(20) NOT NULL,           -- o mesmo código antes e depois (conferência)
    livro_anterior_id BIGINT NOT NULL,
    livro_novo_id     BIGINT NOT NULL,
    motivo            VARCHAR(500) NOT NULL,
    inventario_id     BIGINT NULL,
    administrador_id  BIGINT NULL,
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY exemplar_obra_correcoes_exemplar_idx (exemplar_id, criado_em),
    KEY exemplar_obra_correcoes_anterior_idx (livro_anterior_id),
    KEY exemplar_obra_correcoes_novo_idx (livro_novo_id),
    CONSTRAINT fk_obra_correcoes_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_obra_correcoes_anterior FOREIGN KEY (livro_anterior_id) REFERENCES livros (id),
    CONSTRAINT fk_obra_correcoes_novo FOREIGN KEY (livro_novo_id) REFERENCES livros (id),
    CONSTRAINT fk_obra_correcoes_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id),
    CONSTRAINT fk_obra_correcoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER exemplar_obra_correcoes_sem_exclusao
BEFORE DELETE ON exemplar_obra_correcoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de correção da obra dos exemplares não é apagado.';

CREATE TRIGGER exemplar_obra_correcoes_sem_alteracao
BEFORE UPDATE ON exemplar_obra_correcoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de correção da obra dos exemplares não é alterado.';

-- O código e o lote/ordem de um exemplar nunca mudam e a baixa é definitiva (como na migração 150).
-- A obra só muda pela correção controlada (@bdp_corrigir_obra_exemplar = id deste exemplar).
DROP TRIGGER IF EXISTS exemplares_preservar_identidade;

CREATE TRIGGER exemplares_preservar_identidade
BEFORE UPDATE ON exemplares
FOR EACH ROW
BEGIN
    IF NEW.codigo_individual <> OLD.codigo_individual THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O código de um exemplar não pode ser alterado.';
    END IF;
    IF NEW.livro_id <> OLD.livro_id AND NOT (@bdp_corrigir_obra_exemplar <=> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O livro de um exemplar não pode ser trocado. Use "Corrigir a obra associada" na comparação com a planilha.';
    END IF;
    IF OLD.situacao = 'baixado' AND NEW.situacao <> 'baixado' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Exemplares baixados não voltam ao acervo. O código não é reaproveitado.';
    END IF;
    IF (OLD.lote_id IS NOT NULL AND NOT (NEW.lote_id <=> OLD.lote_id)) OR (OLD.lote_ordem IS NOT NULL AND NOT (NEW.lote_ordem <=> OLD.lote_ordem)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O lote e a ordem de cadastramento de um exemplar não mudam.';
    END IF;
END;

-- Igual à migração 130, mais: o item de um exemplar acompanha a correção controlada da obra dele.
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
       OR (OLD.livro_id IS NOT NULL AND NOT (NEW.livro_id <=> OLD.livro_id) AND OLD.exemplar_id IS NOT NULL
           AND NOT (@bdp_corrigir_obra_exemplar <=> OLD.exemplar_id)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O exemplar de uma conferência não pode ser trocado.';
    END IF;
END;

-- Histórico do exemplar: a correção da obra aparece como movimentação própria ("Obra corrigida").
ALTER TABLE movimentacoes
    MODIFY COLUMN tipo ENUM('cadastro', 'emprestimo', 'devolucao', 'manutencao', 'localizacao', 'inventario', 'extravio', 'baixa', 'renovacao', 'conservacao', 'situacao', 'carimbo', 'ocorrencia', 'obra') NOT NULL;
