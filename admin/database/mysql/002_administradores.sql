-- =========================================================
-- 002 · Administradores, permissões e tentativas de acesso
--
-- Perfis:
--   administrador  acesso total, inclusive aprovar classificações
--   bibliotecaria  aprova e corrige classificações
--   auxiliar       pesquisa, registra sugestões e envia para revisão
--   consulta       somente leitura
-- As permissões de cada perfil ficam em admin/includes/auth.php (AUTH_PERMISSOES).
--
-- Crie o primeiro usuário com: php admin/database/criar-admin.php
-- =========================================================

CREATE TABLE administradores (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome           VARCHAR(150) NOT NULL,
    email          VARCHAR(190) NOT NULL,
    senha_hash     VARCHAR(255) NOT NULL,          -- password_hash() do PHP
    perfil         ENUM('administrador', 'bibliotecaria', 'auxiliar', 'consulta') NOT NULL DEFAULT 'auxiliar',
    cargo          VARCHAR(100),
    ativo          BOOLEAN NOT NULL DEFAULT TRUE,
    ultimo_acesso  DATETIME NULL,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY administradores_email_uk (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Usado para limitar tentativas de login (5 falhas em 15 minutos bloqueiam o e-mail/IP).
CREATE TABLE login_tentativas (
    id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    email     VARCHAR(190) NOT NULL,
    ip        VARCHAR(45) NOT NULL,
    sucesso   BOOLEAN NOT NULL DEFAULT FALSE,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY login_tentativas_email_idx (email, criado_em),
    KEY login_tentativas_ip_idx (ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Liga os responsáveis do acervo aos administradores.
ALTER TABLE movimentacoes
    ADD CONSTRAINT fk_movimentacoes_responsavel FOREIGN KEY (responsavel_id) REFERENCES administradores (id);
ALTER TABLE inventario_conferencias
    ADD CONSTRAINT fk_conferencias_responsavel FOREIGN KEY (responsavel_id) REFERENCES administradores (id);
