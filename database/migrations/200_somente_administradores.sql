-- =========================================================
-- 200 · Um único perfil de acesso: Administrador — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- O painel deixa de diferenciar perfis (Administrador, Bibliotecária, Auxiliar de biblioteca,
-- Somente consulta): toda conta ativa passa a ter acesso total. As permissões continuam sendo
-- conferidas no servidor (admin/includes/auth.php), mas todas valem para todas as contas.
--
-- 1. Toda conta existente passa a ter o perfil "administrador". A coluna "cargo" (a função da
--    pessoa, como "Bibliotecária") é mantida como estava: serve só para identificação.
-- 2. O padrão da coluna perfil passa a ser "administrador". Os outros valores do ENUM continuam
--    aceitos pelo banco (nada é apagado), mas o painel não grava mais nenhum deles.
--
-- Não apaga contas, senhas nem o histórico de ações. Pode ser executada de novo sem efeito.
-- =========================================================

UPDATE administradores SET perfil = 'administrador' WHERE perfil <> 'administrador';

ALTER TABLE administradores
    MODIFY perfil ENUM('administrador', 'bibliotecaria', 'auxiliar', 'consulta') NOT NULL DEFAULT 'administrador';
