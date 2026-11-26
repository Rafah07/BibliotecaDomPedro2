-- =========================================================
-- 230 · Reorganização do acervo (módulo TEMPORÁRIO): ajustes do fluxo — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Só altera tabelas do próprio módulo (reorg_*). Nenhuma tabela permanente é alterada.
--
--   reorg_configuracoes          tempos das sessões temporárias editáveis em
--                                Reorganização do Acervo → Configurações (sobrepõem o config.php)
--   reorg_registros.escolhas_obra  decisão do Grupo 2, campo a campo, ao vincular exemplares a uma
--                                obra que já existe no acervo (JSON: campo → "obra" | "levantamento")
--   reorg_registros.isbn_situacao / cdd_situacao  opção “Informação encontrada / não encontrada”
--                                escolhida no formulário do Grupo 1 (para recuperar o formulário
--                                exatamente como estava). A ausência continua nos indicadores
--                                isbn_nao_encontrado e cdd_nao_encontrada, nunca escrita nos campos.
--
-- Remoção futura: estas colunas e a tabela saem junto com as demais tabelas reorg_* (README do módulo).
-- Aplicar com: php database/migrar.php (a cópia de segurança é feita antes).
-- =========================================================

CREATE TABLE reorg_configuracoes (
    chave           VARCHAR(60) NOT NULL PRIMARY KEY,
    valor           VARCHAR(255) NOT NULL,
    atualizado_por  BIGINT NULL,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reorg_config_admin FOREIGN KEY (atualizado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reorg_registros
    ADD COLUMN escolhas_obra MEDIUMTEXT NULL AFTER obra_nova,
    ADD COLUMN isbn_situacao ENUM('encontrado', 'nao_encontrado') NULL AFTER isbn_nao_encontrado,
    ADD COLUMN cdd_situacao  ENUM('encontrada', 'nao_encontrada') NULL AFTER cdd_nao_encontrada;

-- Registros já existentes: a opção escolhida é deduzida do que foi gravado.
UPDATE reorg_registros SET isbn_situacao = CASE WHEN isbn_nao_encontrado = 1 THEN 'nao_encontrado' WHEN isbn IS NOT NULL THEN 'encontrado' ELSE NULL END,
                           cdd_situacao  = CASE WHEN cdd_nao_encontrada = 1 THEN 'nao_encontrada' WHEN cdd IS NOT NULL THEN 'encontrada' ELSE NULL END;
