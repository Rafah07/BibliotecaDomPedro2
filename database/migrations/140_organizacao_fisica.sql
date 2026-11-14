-- =========================================================
-- 140 · Organização Física da biblioteca e conservação avaliada — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- 1. Estrutura física configurável (página "Organização Física"):
--      setores   (opcional) salas ou setores da biblioteca;
--      estantes  identificação curta (A, B, E01...), nome, categoria/finalidade, ordem, situação,
--                responsável (administrador existente) e/ou nome de equipe (só organização: não dá acesso);
--      prateleiras: continuam sendo linhas de "localizacoes" (a tabela que os exemplares já usam em
--                exemplares.localizacao_id). Uma prateleira é uma localização com estante_id preenchido.
--    Nada é criado sozinho: nenhuma estante, prateleira ou setor é inventado pela migração.
--    As localizações já cadastradas continuam valendo como "localizações avulsas" (sem estante),
--    com a mesma regra de não repetir setor + estante + prateleira + complemento.
--
-- 2. Localização antiga das planilhas (registros_legados.localizacao_antiga): continua só histórica.
--    Nenhum exemplar é colocado em estante pela migração.
--
-- 3. Estado de conservação: novas opções "Não avaliado", "Excelente" e "Ruim" (as antigas
--    "Danificado" e "Necessita reparo" continuam aceitas para os dados existentes). Exemplares
--    novos nascem "Não avaliado". conservacao_avaliada_em/por registram a avaliação feita por uma
--    pessoa; o preenchimento inicial usa só as mudanças de conservação já registradas no histórico.
--
-- Incremental e seguro: não apaga tabelas nem dados, não muda dados bibliográficos e não muda a
-- localização nem o estado de nenhum exemplar.
-- Aplicar com: php database/migrar.php (faça antes a cópia de segurança).
-- =========================================================

-- ---------------------------------------------------------
-- 1. Setores (opcionais)
-- ---------------------------------------------------------
CREATE TABLE setores (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(80) NOT NULL,
    identificacao VARCHAR(20) NULL,                    -- código curto opcional (ex.: S1, INF)
    descricao     VARCHAR(255) NULL,
    ordem         INT NOT NULL DEFAULT 0,
    ativo         BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY setores_nome_uk (nome),
    UNIQUE KEY setores_identificacao_uk (identificacao),
    KEY setores_ordem_idx (ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 1. Estantes
-- ---------------------------------------------------------
CREATE TABLE estantes (
    id                      BIGINT AUTO_INCREMENT PRIMARY KEY,
    setor_id                BIGINT NULL,
    setor_chave             BIGINT AS (COALESCE(setor_id, 0)) STORED,  -- "sem setor" também conta para não repetir
    identificacao           VARCHAR(20) NOT NULL,          -- A, B, C, E01...
    nome                    VARCHAR(80) NOT NULL,          -- Estante A
    descricao               VARCHAR(255) NULL,
    categoria               VARCHAR(120) NULL,             -- finalidade opcional (ex.: Literatura brasileira)
    ordem                   INT NOT NULL DEFAULT 0,
    ativa                   BOOLEAN NOT NULL DEFAULT TRUE,
    responsavel_id          BIGINT NULL,                   -- administrador existente (só organização)
    equipe                  VARCHAR(80) NULL,              -- nome de equipe, sem acesso ao sistema
    responsavel_definido_em DATETIME NULL,
    criado_por              BIGINT NULL,
    criado_em               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- A identificação e o nome não se repetem no mesmo setor (maiúsculas e minúsculas contam como iguais).
    UNIQUE KEY estantes_identificacao_uk (setor_chave, identificacao),
    UNIQUE KEY estantes_nome_uk (setor_chave, nome),
    KEY estantes_ordem_idx (ativa, ordem),
    KEY estantes_responsavel_idx (responsavel_id),
    CONSTRAINT fk_estantes_setor FOREIGN KEY (setor_id) REFERENCES setores (id),
    CONSTRAINT fk_estantes_responsavel FOREIGN KEY (responsavel_id) REFERENCES administradores (id),
    CONSTRAINT fk_estantes_criado_por FOREIGN KEY (criado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 1. Prateleiras = localizações ligadas a uma estante
-- ---------------------------------------------------------
ALTER TABLE localizacoes
    ADD COLUMN estante_id BIGINT NULL AFTER id,
    ADD COLUMN ordem      INT NOT NULL DEFAULT 0 AFTER complemento;

-- A regra antiga (setor + estante + prateleira + complemento únicos) continua valendo para as
-- localizações avulsas. Nas prateleiras de uma estante, a identificação é única DENTRO da estante
-- (Prateleira 1 pode existir na Estante A e na Estante B).
ALTER TABLE localizacoes
    ADD COLUMN chave_avulsa VARCHAR(330) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        AS (CASE WHEN estante_id IS NULL THEN CONCAT_WS('|', setor, estante, prateleira, complemento) END) STORED,
    DROP INDEX localizacoes_uk,
    ADD UNIQUE KEY localizacoes_avulsa_uk (chave_avulsa),
    ADD UNIQUE KEY localizacoes_prateleira_uk (estante_id, prateleira),
    ADD KEY localizacoes_estante_ordem_idx (estante_id, ordem),
    ADD CONSTRAINT fk_localizacoes_estante FOREIGN KEY (estante_id) REFERENCES estantes (id);

-- ---------------------------------------------------------
-- 3. Conservação avaliada
-- ---------------------------------------------------------
ALTER TABLE exemplares
    MODIFY COLUMN estado_conservacao ENUM('nao_avaliado', 'excelente', 'bom', 'regular', 'ruim', 'danificado', 'necessita_reparo') NOT NULL DEFAULT 'nao_avaliado',
    ADD COLUMN conservacao_avaliada_em  DATETIME NULL AFTER estado_conservacao,
    ADD COLUMN conservacao_avaliada_por BIGINT NULL AFTER conservacao_avaliada_em,
    ADD KEY exemplares_conservacao_idx (estado_conservacao, conservacao_avaliada_em),
    ADD KEY exemplares_local_situacao_idx (localizacao_id, situacao),
    ADD CONSTRAINT fk_exemplares_conservacao_por FOREIGN KEY (conservacao_avaliada_por) REFERENCES administradores (id);

-- Só mudanças de conservação já registradas por alguém contam como avaliação.
-- O estado "Bom" que vinha marcado por padrão NÃO conta como avaliado.
UPDATE exemplares e
JOIN (SELECT m.exemplar_id, MAX(m.criado_em) AS quando
        FROM movimentacoes m WHERE m.tipo = 'conservacao' GROUP BY m.exemplar_id) x ON x.exemplar_id = e.id
   SET e.conservacao_avaliada_em = x.quando
 WHERE e.conservacao_avaliada_em IS NULL;

ALTER TABLE inventario_itens
    MODIFY COLUMN estado_encontrado ENUM('nao_avaliado', 'excelente', 'bom', 'regular', 'ruim', 'danificado', 'necessita_reparo') NULL;

-- Movimentações recentes (Mover exemplar e histórico da Organização Física).
ALTER TABLE movimentacoes
    ADD KEY movimentacoes_tipo_data_idx (tipo, criado_em);
