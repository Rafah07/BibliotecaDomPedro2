-- =========================================================
-- 090 · Configurações gerais do sistema (Etapa 6) — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Incremental: não altera nem apaga dados existentes.
--   * "configuracoes_sistema" guarda as configurações editadas no painel
--     (Sistema → Configurações). A chave é o MESMO nome da variável do .env
--     (ex.: EMPRESTIMO_PRAZO_DIAS). Começa VAZIA: enquanto uma chave não tiver
--     linha aqui, o sistema continua usando o valor do .env (ou o padrão), ou seja,
--     aplicar esta migração não muda nenhum comportamento.
--   * "configuracoes_historico" registra cada alteração (valor anterior, novo valor,
--     quem alterou e quando). É imutável: gatilhos impedem editar ou apagar linhas.
--
-- Depende de 002 (administradores).
-- =========================================================

CREATE TABLE configuracoes_sistema (
    chave          VARCHAR(60) PRIMARY KEY,
    valor          TEXT NULL,
    atualizado_por BIGINT NULL,              -- administradores.id
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_config_sistema_admin FOREIGN KEY (atualizado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuracoes_historico (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    chave          VARCHAR(60) NOT NULL,
    valor_anterior TEXT NULL,                -- NULL: usava o .env ou o padrão
    valor_novo     TEXT NULL,                -- NULL: voltou a usar o .env ou o padrão
    usuario_id     BIGINT NULL,
    ip             VARCHAR(45) NULL,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY config_historico_chave_idx (chave, criado_em),
    KEY config_historico_data_idx (criado_em),
    CONSTRAINT fk_config_historico_admin FOREIGN KEY (usuario_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER configuracoes_historico_imutavel
BEFORE UPDATE ON configuracoes_historico
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de configurações não pode ser alterado.';
END;

CREATE TRIGGER configuracoes_historico_sem_exclusao
BEFORE DELETE ON configuracoes_historico
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de configurações não pode ser excluído.';
END;
