-- =========================================================
-- 220 · Módulo TEMPORÁRIO de reorganização do acervo — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Usado só durante a reorganização física do acervo. Todo o código fica em modulos/reorganizacao/
-- (documentação em modulos/reorganizacao/README.md, com as instruções de desativação e remoção).
--
-- Todas as tabelas começam com "reorg_" e só APONTAM para as tabelas permanentes (livros,
-- exemplares, cadastro_lotes, administradores, localizacoes). Nenhuma tabela permanente aponta para
-- elas: apagar as tabelas reorg_* no futuro (depois de backup e autorização) não apaga obras,
-- exemplares, códigos BDP, lotes nem planilhas BDP. Nenhuma chave estrangeira usa ON DELETE CASCADE.
--
--   reorg_periodos            período de reorganização (nome, datas, instruções gerais e por equipe)
--   reorg_sessoes             sessões temporárias dos participantes (só nome + identificador seguro)
--   reorg_lotes               lote da reorganização, ligado a um lote gerenciado permanente
--                             (cadastro_lotes), onde os exemplares e os códigos BDP são criados
--   reorg_lote_admins         administradores autorizados a revisar (Grupo 2) além do responsável
--   reorg_atribuicoes         participante ↔ lote ↔ equipe (histórico: nunca apagado)
--   reorg_registros           formulários do Grupo 1 (uma obra/edição, com a quantidade de exemplares)
--   reorg_registro_exemplares cada exemplar físico do registro e a etapa em que ele está
--   reorg_pendencias          Central de Pendências
--   reorg_solicitacoes        novo lote, ajuda e troca de equipe
--   reorg_notificacoes        notificações internas (participantes e administradores)
--   reorg_historico           histórico de atividades (imutável)
--
-- Também desliga os links de equipe da migração 210 (COLETA_LINKS_ATIVOS = false): a reorganização
-- passa a usar a Central de Equipes, sem links por lote ou equipe. Os pré-cadastros e o histórico
-- dos links continuam guardados; a opção pode ser religada em Sistema → Configurações.
--
-- Só acrescenta tabelas: nenhuma tabela ou dado existente é alterado (além da configuração acima).
-- Aplicar com: php database/migrar.php (a cópia de segurança é feita antes).
-- =========================================================

CREATE TABLE reorg_periodos (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome               VARCHAR(120) NOT NULL,
    inicio             DATE NOT NULL,
    previsao_fim       DATE NULL,
    instrucoes_gerais  TEXT NULL,
    instrucoes_grupo1  TEXT NULL,
    instrucoes_grupo2  TEXT NULL,
    instrucoes_grupo3  TEXT NULL,
    instrucoes_grupo4  TEXT NULL,
    ativo              TINYINT(1) NOT NULL DEFAULT 1,
    criado_por         BIGINT NOT NULL,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    encerrado_em       DATETIME NULL,
    encerrado_por      BIGINT NULL,
    KEY reorg_periodos_ativo_idx (ativo),
    CONSTRAINT fk_reorg_periodos_criado FOREIGN KEY (criado_por) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_periodos_encerrado FOREIGN KEY (encerrado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_sessoes (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    periodo_id          BIGINT NOT NULL,
    token_hash          CHAR(64) NOT NULL,                 -- SHA-256 do identificador guardado no navegador
    nome                VARCHAR(100) NOT NULL,             -- nome informado (não é prova de identidade)
    nome_chave          VARCHAR(100) NOT NULL,             -- nome sem acentos/maiúsculas, para avisar nomes repetidos
    equipe              ENUM('grupo1', 'grupo3', 'grupo4') NULL,
    status              ENUM('ativa', 'encerrada') NOT NULL DEFAULT 'ativa',
    situacao            ENUM('disponivel', 'trabalhando', 'pausada') NOT NULL DEFAULT 'disponivel',
    iniciada_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_sinal_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    encerrada_em        DATETIME NULL,
    motivo_encerramento ENUM('saida', 'forcado', 'inatividade', 'periodo_encerrado') NULL,
    encerrada_por       BIGINT NULL,
    ip                  VARCHAR(45) NULL,
    UNIQUE KEY reorg_sessoes_token_uk (token_hash),
    KEY reorg_sessoes_status_idx (status, ultimo_sinal_em),
    KEY reorg_sessoes_nome_idx (nome_chave, status),
    KEY reorg_sessoes_ip_idx (ip, iniciada_em),
    CONSTRAINT fk_reorg_sessoes_periodo FOREIGN KEY (periodo_id) REFERENCES reorg_periodos (id),
    CONSTRAINT fk_reorg_sessoes_encerrada FOREIGN KEY (encerrada_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_lotes (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    periodo_id            BIGINT NOT NULL,
    cadastro_lote_id      BIGINT NOT NULL,                 -- lote gerenciado permanente (códigos BDP, Planilha BDP)
    nome                  VARCHAR(120) NOT NULL,
    quantidade_inicial    INT UNSIGNED NOT NULL,           -- prevista na criação (não muda)
    quantidade_prevista   INT UNSIGNED NOT NULL,           -- escopo atual (ajustável, com histórico)
    responsavel_id        BIGINT NOT NULL,
    observacoes           TEXT NULL,
    instrucoes            TEXT NULL,
    prazo                 DATE NULL,
    status                ENUM('em_andamento', 'pausado', 'concluido') NOT NULL DEFAULT 'em_andamento',
    etapas_pausadas       SET('grupo1', 'grupo2', 'grupo3', 'grupo4') NOT NULL DEFAULT '',
    resumo_conclusao      MEDIUMTEXT NULL,                 -- JSON gravado na conclusão
    criado_por            BIGINT NOT NULL,
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em          DATETIME NULL,
    concluido_por         BIGINT NULL,
    UNIQUE KEY reorg_lotes_cadastro_uk (cadastro_lote_id),
    KEY reorg_lotes_status_idx (periodo_id, status),
    CONSTRAINT fk_reorg_lotes_periodo FOREIGN KEY (periodo_id) REFERENCES reorg_periodos (id),
    CONSTRAINT fk_reorg_lotes_cadastro FOREIGN KEY (cadastro_lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_reorg_lotes_responsavel FOREIGN KEY (responsavel_id) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_lotes_criado FOREIGN KEY (criado_por) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_lotes_concluido FOREIGN KEY (concluido_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_lote_admins (
    lote_id           BIGINT NOT NULL,
    administrador_id  BIGINT NOT NULL,
    incluido_por      BIGINT NOT NULL,
    incluido_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removido_em       DATETIME NULL,
    PRIMARY KEY (lote_id, administrador_id),
    CONSTRAINT fk_reorg_lote_admins_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id),
    CONSTRAINT fk_reorg_lote_admins_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_lote_admins_incluido FOREIGN KEY (incluido_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_atribuicoes (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id        BIGINT NOT NULL,
    sessao_id      BIGINT NOT NULL,
    equipe         ENUM('grupo1', 'grupo3', 'grupo4') NOT NULL,
    atribuida_por  BIGINT NOT NULL,
    atribuida_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    encerrada_em   DATETIME NULL,                          -- NULL = atribuição ativa
    encerrada_por  BIGINT NULL,
    motivo_fim     ENUM('removido', 'transferido', 'sessao_encerrada', 'troca_equipe', 'lote_concluido') NULL,
    KEY reorg_atribuicoes_sessao_idx (sessao_id, encerrada_em),
    KEY reorg_atribuicoes_lote_idx (lote_id, equipe, encerrada_em),
    CONSTRAINT fk_reorg_atrib_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id),
    CONSTRAINT fk_reorg_atrib_sessao FOREIGN KEY (sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_atrib_por FOREIGN KEY (atribuida_por) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_atrib_fim FOREIGN KEY (encerrada_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_registros (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id              BIGINT NOT NULL,
    numero               INT UNSIGNED NOT NULL,            -- nº do registro dentro do lote
    status               ENUM('rascunho', 'aguardando_revisao', 'correcao', 'aprovado', 'cadastrado', 'descartado') NOT NULL DEFAULT 'rascunho',
    completo             TINYINT(1) NOT NULL DEFAULT 0,    -- campos obrigatórios do Grupo 1 preenchidos e válidos
    -- Dados bibliográficos: os mesmos campos da revisão administrativa (Inventário → Verificar e corrigir dados)
    titulo               VARCHAR(255) NULL,
    subtitulo            VARCHAR(255) NULL,
    autores              VARCHAR(1500) NULL,               -- vários separados por ";"
    autor_nao_indicado   TINYINT(1) NOT NULL DEFAULT 0,
    editora              VARCHAR(150) NULL,
    editora_nao_indicada TINYINT(1) NOT NULL DEFAULT 0,
    isbn                 VARCHAR(20) NULL,                 -- só dígitos (e X) quando encontrado
    isbn_nao_encontrado  TINYINT(1) NOT NULL DEFAULT 0,    -- indicador separado: nunca "Não encontrado" no campo
    cdd                  VARCHAR(30) NULL,
    cdd_nao_encontrada   TINYINT(1) NOT NULL DEFAULT 0,
    cdd_fonte            VARCHAR(255) NULL,
    ano_publicacao       VARCHAR(4) NULL,
    edicao               VARCHAR(60) NULL,
    idioma               VARCHAR(30) NULL,
    categoria_id         BIGINT NULL,
    assunto              VARCHAR(255) NULL,
    quantidade           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    observacoes          VARCHAR(1000) NULL,
    livro_sugerido_id    BIGINT NULL,                      -- obra do acervo escolhida na pesquisa do Grupo 1
    livro_id             BIGINT NULL,                      -- obra definitiva (criada ou vinculada na aprovação)
    obra_nova            TINYINT(1) NULL,                  -- 1 = obra criada pela aprovação; 0 = obra existente reaproveitada
    dados_originais      MEDIUMTEXT NULL,                  -- JSON do que o Grupo 1 enviou (primeiro encaminhamento)
    correcao_motivo      VARCHAR(500) NULL,
    criado_sessao_id     BIGINT NULL,
    criado_nome          VARCHAR(100) NULL,
    atualizado_sessao_id BIGINT NULL,
    atualizado_nome      VARCHAR(100) NULL,
    atualizado_admin_id  BIGINT NULL,
    editando_sessao_id   BIGINT NULL,                      -- reserva temporária de edição
    editando_admin_id    BIGINT NULL,
    editando_ate         DATETIME NULL,
    enviado_em           DATETIME NULL,
    revisado_por         BIGINT NULL,
    revisado_em          DATETIME NULL,
    aprovado_por         BIGINT NULL,
    aprovado_em          DATETIME NULL,
    cadastrado_por       BIGINT NULL,
    cadastrado_em        DATETIME NULL,
    versao               INT UNSIGNED NOT NULL DEFAULT 1,  -- controle de sobrescrita
    criado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY reorg_registros_numero_uk (lote_id, numero),
    KEY reorg_registros_status_idx (lote_id, status),
    KEY reorg_registros_isbn_idx (isbn),
    CONSTRAINT fk_reorg_reg_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id),
    CONSTRAINT fk_reorg_reg_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
    CONSTRAINT fk_reorg_reg_sugerido FOREIGN KEY (livro_sugerido_id) REFERENCES livros (id),
    CONSTRAINT fk_reorg_reg_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_reorg_reg_criado_sessao FOREIGN KEY (criado_sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_reg_atual_sessao FOREIGN KEY (atualizado_sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_reg_atual_admin FOREIGN KEY (atualizado_admin_id) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_reg_edit_sessao FOREIGN KEY (editando_sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_reg_edit_admin FOREIGN KEY (editando_admin_id) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_reg_revisado FOREIGN KEY (revisado_por) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_reg_aprovado FOREIGN KEY (aprovado_por) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_reg_cadastrado FOREIGN KEY (cadastrado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_registro_exemplares (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    registro_id           BIGINT NOT NULL,
    lote_id               BIGINT NOT NULL,
    ordem                 SMALLINT UNSIGNED NOT NULL,      -- 1..quantidade dentro do registro
    estado_conservacao    ENUM('excelente', 'regular', 'ruim') NULL,
    observacao            VARCHAR(255) NULL,
    removido_em           DATETIME NULL,                   -- quantidade reduzida antes do cadastro (nunca apagado)
    exemplar_id           BIGINT NULL,                     -- exemplar permanente criado no cadastro (código BDP)
    reservado_sessao_id   BIGINT NULL,                     -- Grupo 3/4: quem está com o livro agora
    reservado_ate         DATETIME NULL,
    carimbado_em          DATETIME NULL,
    carimbado_sessao_id   BIGINT NULL,
    carimbado_nome        VARCHAR(100) NULL,
    encaminhado_g4_em     DATETIME NULL,
    conferido_em          DATETIME NULL,
    conferido_sessao_id   BIGINT NULL,
    conferido_nome        VARCHAR(100) NULL,
    organizado_em         DATETIME NULL,
    organizado_sessao_id  BIGINT NULL,
    organizado_nome       VARCHAR(100) NULL,
    localizacao_id        BIGINT NULL,
    problema_aberto       TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY reorg_reg_ex_ordem_uk (registro_id, ordem),
    UNIQUE KEY reorg_reg_ex_exemplar_uk (exemplar_id),
    KEY reorg_reg_ex_lote_idx (lote_id, carimbado_em, conferido_em),
    CONSTRAINT fk_reorg_rex_registro FOREIGN KEY (registro_id) REFERENCES reorg_registros (id),
    CONSTRAINT fk_reorg_rex_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id),
    CONSTRAINT fk_reorg_rex_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_reorg_rex_reservado FOREIGN KEY (reservado_sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_rex_carimbado FOREIGN KEY (carimbado_sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_rex_conferido FOREIGN KEY (conferido_sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_rex_organizado FOREIGN KEY (organizado_sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_rex_local FOREIGN KEY (localizacao_id) REFERENCES localizacoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_pendencias (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id               BIGINT NOT NULL,
    registro_id           BIGINT NULL,
    registro_exemplar_id  BIGINT NULL,
    tipo                  ENUM('isbn_nao_encontrado', 'cdd_nao_encontrada', 'dados_incompletos', 'divergencia_obras', 'exemplar_danificado',
                               'livro_nao_localizado', 'codigo_incorreto', 'codigo_livro_errado', 'codigo_ja_conferido', 'organizacao', 'outro') NOT NULL,
    grupo                 ENUM('grupo1', 'grupo2', 'grupo3', 'grupo4') NOT NULL,
    sessao_id             BIGINT NULL,
    nome                  VARCHAR(100) NULL,
    administrador_id      BIGINT NULL,
    descricao             VARCHAR(1000) NOT NULL,
    status                ENUM('aberta', 'em_analise', 'resolvida') NOT NULL DEFAULT 'aberta',
    resolucao             VARCHAR(1000) NULL,
    resolvida_por         BIGINT NULL,
    resolvida_em          DATETIME NULL,
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY reorg_pend_status_idx (status, criado_em),
    KEY reorg_pend_lote_idx (lote_id, status),
    CONSTRAINT fk_reorg_pend_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id),
    CONSTRAINT fk_reorg_pend_registro FOREIGN KEY (registro_id) REFERENCES reorg_registros (id),
    CONSTRAINT fk_reorg_pend_rex FOREIGN KEY (registro_exemplar_id) REFERENCES reorg_registro_exemplares (id),
    CONSTRAINT fk_reorg_pend_sessao FOREIGN KEY (sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_pend_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_pend_resolvida FOREIGN KEY (resolvida_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_solicitacoes (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    periodo_id        BIGINT NOT NULL,
    tipo              ENUM('novo_lote', 'ajuda', 'troca_equipe') NOT NULL,
    sessao_id         BIGINT NOT NULL,
    nome              VARCHAR(100) NOT NULL,
    equipe_atual      ENUM('grupo1', 'grupo3', 'grupo4') NULL,
    equipe_desejada   ENUM('grupo1', 'grupo3', 'grupo4') NULL,
    lote_id           BIGINT NULL,
    etapa             VARCHAR(80) NULL,
    problema_tipo     VARCHAR(80) NULL,
    descricao         VARCHAR(1000) NULL,
    status            ENUM('pendente', 'aprovada', 'recusada', 'respondida', 'resolvida', 'encaminhada') NOT NULL DEFAULT 'pendente',
    resposta          VARCHAR(1000) NULL,
    respondida_por    BIGINT NULL,
    respondida_em     DATETIME NULL,
    encaminhada_para  BIGINT NULL,
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY reorg_solic_status_idx (status, tipo, criado_em),
    KEY reorg_solic_sessao_idx (sessao_id, status),
    CONSTRAINT fk_reorg_solic_periodo FOREIGN KEY (periodo_id) REFERENCES reorg_periodos (id),
    CONSTRAINT fk_reorg_solic_sessao FOREIGN KEY (sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_solic_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id),
    CONSTRAINT fk_reorg_solic_respondida FOREIGN KEY (respondida_por) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_solic_encaminhada FOREIGN KEY (encaminhada_para) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_notificacoes (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    sessao_id         BIGINT NULL,                         -- para um participante
    administrador_id  BIGINT NULL,                         -- para um administrador (NULL nos dois = todos os administradores)
    tipo              VARCHAR(40) NOT NULL,
    texto             VARCHAR(500) NOT NULL,
    lote_id           BIGINT NULL,
    chave             VARCHAR(120) NULL,                   -- evita repetir a mesma notificação
    lida_em           DATETIME NULL,
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY reorg_notif_sessao_idx (sessao_id, lida_em),
    KEY reorg_notif_admin_idx (administrador_id, lida_em, criado_em),
    KEY reorg_notif_chave_idx (chave, criado_em),
    CONSTRAINT fk_reorg_notif_sessao FOREIGN KEY (sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_notif_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT fk_reorg_notif_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorg_historico (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    periodo_id        BIGINT NULL,
    lote_id           BIGINT NULL,
    sessao_id         BIGINT NULL,
    nome              VARCHAR(100) NULL,                   -- nome informado pelo participante (autodeclarado)
    administrador_id  BIGINT NULL,
    acao              VARCHAR(60) NOT NULL,
    descricao         VARCHAR(500) NOT NULL,
    detalhes          MEDIUMTEXT NULL,                     -- JSON
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY reorg_hist_lote_idx (lote_id, criado_em),
    KEY reorg_hist_sessao_idx (sessao_id, criado_em),
    KEY reorg_hist_acao_idx (acao, criado_em),
    CONSTRAINT fk_reorg_hist_periodo FOREIGN KEY (periodo_id) REFERENCES reorg_periodos (id),
    CONSTRAINT fk_reorg_hist_lote FOREIGN KEY (lote_id) REFERENCES reorg_lotes (id),
    CONSTRAINT fk_reorg_hist_sessao FOREIGN KEY (sessao_id) REFERENCES reorg_sessoes (id),
    CONSTRAINT fk_reorg_hist_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER reorg_historico_sem_alteracao
BEFORE UPDATE ON reorg_historico
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico da reorganização não é alterado.';

CREATE TRIGGER reorg_historico_sem_exclusao
BEFORE DELETE ON reorg_historico
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico da reorganização não é apagado.';

-- Links de equipe da migração 210 desligados (a reorganização usa a Central de Equipes).
INSERT INTO configuracoes_sistema (chave, valor) VALUES ('COLETA_LINKS_ATIVOS', 'false')
    ON DUPLICATE KEY UPDATE valor = 'false';
