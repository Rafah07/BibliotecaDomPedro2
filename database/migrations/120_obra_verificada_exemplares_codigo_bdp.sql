-- =========================================================
-- 120 · Obra × exemplar no Inventário — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Separa duas coisas que antes eram conferidas juntas:
--   * OBRA (tabela livros): os dados bibliográficos. Passam a ter um status de verificação
--     próprio, "Dados não verificados" ou "Dados verificados", que só muda quando alguém da
--     equipe confirma explicitamente que conferiu as informações (livros.dados_verificados_em).
--     Cada verificação e cada alteração depois da verificação ficam em livro_verificacoes.
--   * EXEMPLAR (tabela exemplares): o livro físico, com o código BDP único e a situação no
--     inventário (Pendente / Encontrado / Não localizado, em inventario_itens).
--
-- Gerador de código BDP:
--   * Continua usando a sequência que já existe (exemplar_codigo_seq, migração 001/070) e o
--     formato que já está nos carimbos e etiquetas: BDP-000001 (6 números). Nenhum código
--     existente muda.
--   * "Gerar código BDP" RESERVA o próximo número para quem clicou (exemplar_codigo_reservas),
--     com a linha da sequência bloqueada: dois cliques ao mesmo tempo recebem números diferentes.
--     A sequência só avança; um código reservado e não usado, ou de um exemplar baixado, nunca
--     é entregue de novo. O código só passa a ser definitivo quando o exemplar é salvo.
--   * O índice único exemplares_codigo_uk continua sendo a barreira final contra repetição.
--
-- Incremental e seguro:
--   * Não apaga nem recria tabelas e não altera dados bibliográficos, exemplares ou inventários.
--   * Nenhuma obra começa como verificada: até hoje não existia uma confirmação explícita
--     (status_validacao era calculado sozinho a partir dos alertas). A equipe verifica cada obra
--     na conferência do Inventário.
--   * Registros importados sem exemplar continuam como estão (nenhum exemplar é criado e
--     nenhuma obra é unida a outra).
--
-- Aplicar com: php database/migrar.php (faça antes a cópia de segurança).
-- =========================================================

-- ---------------------------------------------------------
-- Verificação dos dados da obra
-- ---------------------------------------------------------
ALTER TABLE livros
    ADD COLUMN dados_verificados_em  DATETIME NULL AFTER status_validacao,   -- NULL = "Dados não verificados"
    ADD COLUMN dados_verificados_por BIGINT NULL AFTER dados_verificados_em,
    ADD KEY livros_verificados_idx (dados_verificados_em),
    ADD CONSTRAINT fk_livros_verificados_por FOREIGN KEY (dados_verificados_por) REFERENCES administradores (id);

-- Histórico: verificações, alterações feitas depois da verificação e verificações retiradas.
CREATE TABLE livro_verificacoes (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_id         BIGINT NOT NULL,
    acao             ENUM('verificado', 'alterado', 'verificacao_retirada') NOT NULL,
    status_anterior  ENUM('nao_verificado', 'verificado') NOT NULL,
    status_novo      ENUM('nao_verificado', 'verificado') NOT NULL,
    alteracoes       JSON NULL,                 -- {"campo": {"antes": ..., "depois": ...}}
    origem           VARCHAR(30) NOT NULL DEFAULT 'inventario',   -- inventario | editar_livro | nao_listado
    inventario_id    BIGINT NULL,
    administrador_id BIGINT NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY livro_verificacoes_livro_idx (livro_id, criado_em),
    CONSTRAINT fk_verificacoes_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_verificacoes_inventario FOREIGN KEY (inventario_id) REFERENCES inventarios (id),
    CONSTRAINT fk_verificacoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER livro_verificacoes_sem_exclusao
BEFORE DELETE ON livro_verificacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de verificação das obras não é apagado.';

CREATE TRIGGER livro_verificacoes_sem_alteracao
BEFORE UPDATE ON livro_verificacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de verificação das obras não é alterado.';

-- ---------------------------------------------------------
-- Reservas do gerador de código BDP
-- ---------------------------------------------------------
CREATE TABLE exemplar_codigo_reservas (
    codigo         VARCHAR(20) NOT NULL PRIMARY KEY,   -- BDP-000123 (mesmo formato de exemplares.codigo_individual)
    numero         INT UNSIGNED NOT NULL,
    reservado_por  BIGINT NULL,
    reservado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    livro_id       BIGINT NULL,                        -- obra para a qual o código foi gerado (só referência)
    usado_em       DATETIME NULL,                      -- preenchido quando o exemplar é salvo com este código
    exemplar_id    BIGINT NULL,
    UNIQUE KEY exemplar_codigo_reservas_numero_uk (numero),
    KEY exemplar_codigo_reservas_admin_idx (reservado_por, usado_em),
    CONSTRAINT fk_reservas_admin FOREIGN KEY (reservado_por) REFERENCES administradores (id),
    CONSTRAINT fk_reservas_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_reservas_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT exemplar_codigo_reservas_ck CHECK (codigo COLLATE utf8mb4_bin REGEXP '^BDP-[0-9]{6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma reserva não é apagada (o número continua "gasto" mesmo que o exemplar não seja salvo).
CREATE TRIGGER exemplar_codigo_reservas_sem_exclusao
BEFORE DELETE ON exemplar_codigo_reservas
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reservas de código BDP não são apagadas: o código não é reaproveitado.';

-- A sequência nunca fica abaixo do maior código existente (mesma regra da migração 070).
INSERT IGNORE INTO exemplar_codigo_seq (id, ultimo) VALUES (1, 0);
UPDATE exemplar_codigo_seq
   SET ultimo = GREATEST(ultimo, COALESCE((SELECT MAX(CAST(SUBSTRING(codigo_individual, 5) AS UNSIGNED))
                                           FROM exemplares WHERE codigo_individual REGEXP '^BDP-[0-9]{6}$'), 0))
 WHERE id = 1;
