-- =========================================================
-- 110 · Importar tudo e revisar no inventário — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- A importação das planilhas deixa de exigir correções antes de gravar: todas as
-- linhas entram no acervo e os problemas encontrados viram ALERTAS CADASTRAIS
-- (livro_pendencias), conferidos depois, com o livro em mãos, na página de Inventário.
-- O inventário passa a conferir também os registros que ainda não têm exemplar
-- (sem código BDP): ao ser encontrado, o livro recebe o código do carimbo.
--
-- Incremental e seguro:
--   * Não apaga nem recria tabelas e não altera dados bibliográficos existentes.
--   * livros.titulo e livros.idioma passam a aceitar NULL (informação ausente fica
--     ausente: o sistema não inventa título nem idioma).
--   * O ISBN deixa de ser único entre os livros ativos (a mesma obra pode ter vários
--     registros na planilha). O índice continua existindo, só que não é mais UNIQUE.
--     O código BDP continua ÚNICO entre os exemplares (exemplares_codigo_uk, migração 001).
--   * O mesmo arquivo pode ser importado de novo, mas o sistema avisa e pede
--     confirmação (importacoes.repete_importacao_id / hash_conteudo).
--   * inventario_itens aceita itens sem exemplar (registro da planilha ainda sem código
--     BDP), guarda o livro (livro_id) e a linha de origem, e o status "Não localizado"
--     (nao_localizado_em), que pode ser desfeito. Status da conferência de cada item:
--       Pendente       conferido_em e nao_localizado_em vazios
--       Encontrado     conferido_em preenchido
--       Não localizado nao_localizado_em preenchido (ou extravio registrado)
--     Os alertas cadastrais ficam à parte, em livro_pendencias.
--
-- Aplicar com: php database/migrar.php (faça antes a cópia de segurança).
-- =========================================================

-- ---------------------------------------------------------
-- Livros: campos opcionais aceitam NULL e o ISBN não é mais único
-- ---------------------------------------------------------
ALTER TABLE livros DROP CONSTRAINT livros_titulo_ck;

ALTER TABLE livros
    MODIFY COLUMN titulo VARCHAR(255) NULL,
    MODIFY COLUMN idioma VARCHAR(30) NULL DEFAULT 'Português',
    ADD CONSTRAINT livros_titulo_ck CHECK (titulo IS NULL OR CHAR_LENGTH(TRIM(titulo)) > 0);

ALTER TABLE livros ADD KEY livros_isbn_ativo_idx (isbn_ativo);
ALTER TABLE livros DROP INDEX livros_isbn_ativo_uk;

ALTER TABLE livros
    ADD COLUMN isbn_original VARCHAR(100) NULL AFTER isbn;   -- ISBN como veio (planilha ou conferência) quando não é um ISBN válido

-- ---------------------------------------------------------
-- Pendências de dados de cada livro (alertas da importação e da revisão)
-- ---------------------------------------------------------
CREATE TABLE livro_pendencias (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id           BIGINT NOT NULL,
    importacao_id      BIGINT NULL,
    importacao_item_id BIGINT NULL,                -- linha da planilha que gerou o alerta
    tipo               ENUM('incompleto', 'inconsistencia', 'duplicidade', 'outro') NOT NULL,
    codigo             VARCHAR(40) NOT NULL,       -- ex.: sem_autor, isbn_invalido, mesmo_isbn
    campo              VARCHAR(40) NULL,
    mensagem           VARCHAR(500) NOT NULL,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvida_em       DATETIME NULL,
    resolvida_por      BIGINT NULL,
    resolucao          ENUM('corrigida', 'confirmada') NULL,   -- confirmada = a equipe conferiu e a informação realmente não existe / está certa
    KEY livro_pendencias_livro_idx (livro_id, resolvida_em),
    KEY livro_pendencias_tipo_idx (tipo, resolvida_em),
    CONSTRAINT fk_pendencias_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_pendencias_importacao FOREIGN KEY (importacao_id) REFERENCES importacoes (id),
    CONSTRAINT fk_pendencias_item FOREIGN KEY (importacao_item_id) REFERENCES importacao_itens (id),
    CONSTRAINT fk_pendencias_resolvida_por FOREIGN KEY (resolvida_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O alerta registrado não é apagado: ele só é marcado como resolvido.
CREATE TRIGGER livro_pendencias_sem_exclusao
BEFORE DELETE ON livro_pendencias
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As pendências não são excluídas: marque-as como resolvidas.';

-- ---------------------------------------------------------
-- Importações: repetir um arquivo é permitido, com aviso e confirmação
-- ---------------------------------------------------------
ALTER TABLE importacoes ADD KEY importacoes_arquivo_ativo_idx (arquivo_ativo);
ALTER TABLE importacoes DROP INDEX importacoes_arquivo_ativo_uk;

ALTER TABLE importacoes
    ADD COLUMN hash_conteudo           CHAR(40) NULL AFTER identificador_arquivo,  -- impressão digital das linhas lidas (mesmo conteúdo salvo em outro arquivo)
    ADD COLUMN repete_importacao_id    BIGINT NULL AFTER hash_conteudo,            -- importação anterior do mesmo arquivo/conteúdo
    ADD COLUMN repeticao_confirmada_em DATETIME NULL AFTER repete_importacao_id,
    ADD COLUMN repeticao_confirmada_por BIGINT NULL AFTER repeticao_confirmada_em,
    ADD KEY importacoes_hash_conteudo_idx (hash_conteudo),
    ADD CONSTRAINT fk_importacoes_repete FOREIGN KEY (repete_importacao_id) REFERENCES importacoes (id),
    ADD CONSTRAINT fk_importacoes_repeticao_por FOREIGN KEY (repeticao_confirmada_por) REFERENCES administradores (id);

-- Linhas iguais a linhas de importações anteriores (aviso de importação repetida).
ALTER TABLE importacao_itens ADD KEY importacao_itens_hash_global_idx (hash_linha);

-- ---------------------------------------------------------
-- Inventário: itens sem exemplar, livro e linha de origem, "Não localizado"
-- ---------------------------------------------------------
-- O gatilho que protege os itens é recriado para permitir uma única mudança nova:
-- um registro sem exemplar recebe o exemplar criado quando o livro é encontrado.
DROP TRIGGER IF EXISTS inventario_itens_alterar;

ALTER TABLE inventario_itens
    MODIFY COLUMN exemplar_id BIGINT NULL,
    ADD COLUMN livro_id            BIGINT NULL AFTER exemplar_id,
    ADD COLUMN importacao_item_id  BIGINT NULL AFTER livro_id,
    ADD COLUMN nao_localizado_em   DATETIME NULL AFTER decidido_em,
    ADD COLUMN nao_localizado_por  BIGINT NULL AFTER nao_localizado_em,
    ADD COLUMN nao_localizado_obs  VARCHAR(500) NULL AFTER nao_localizado_por,
    ADD UNIQUE KEY inventario_itens_origem_uk (inventario_id, importacao_item_id),
    ADD KEY inventario_itens_livro_idx (inventario_id, livro_id),
    ADD CONSTRAINT fk_inv_itens_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    ADD CONSTRAINT fk_inv_itens_import_item FOREIGN KEY (importacao_item_id) REFERENCES importacao_itens (id),
    ADD CONSTRAINT fk_inv_itens_nao_localizado_por FOREIGN KEY (nao_localizado_por) REFERENCES administradores (id);

UPDATE inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id SET i.livro_id = e.livro_id WHERE i.livro_id IS NULL;

CREATE TRIGGER inventario_itens_alterar
BEFORE UPDATE ON inventario_itens
FOR EACH ROW
BEGIN
    IF (SELECT status FROM inventarios WHERE id = OLD.inventario_id) NOT IN ('em_andamento', 'pausado') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este inventário já foi finalizado e não pode ser alterado.';
    END IF;
    IF NEW.inventario_id <> OLD.inventario_id
       OR (OLD.exemplar_id IS NOT NULL AND NOT (NEW.exemplar_id <=> OLD.exemplar_id))
       OR (OLD.livro_id IS NOT NULL AND NOT (NEW.livro_id <=> OLD.livro_id)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O exemplar de uma conferência não pode ser trocado.';
    END IF;
END;
