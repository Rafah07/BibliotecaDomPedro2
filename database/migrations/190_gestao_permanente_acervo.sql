-- =========================================================
-- 190 · Gestão permanente do acervo — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Prepara o sistema para depois da reorganização inicial, sem mudar nenhum dado existente:
--
--  1. livros.versao: número da versão do registro bibliográfico (controle de concorrência
--     otimista). Cada gravação dos dados da obra soma 1; quem tenta salvar a partir de uma versão
--     antiga recebe o aviso de conflito em vez de sobrescrever. Os exemplares continuam lendo
--     título, autor etc. da obra (exemplares.livro_id): não há cópia desses dados no exemplar.
--  2. obra_edicoes: quem está com o formulário de uma obra aberto agora (aviso discreto de edição
--     simultânea). Cada linha expira sozinha se o navegador parar de confirmar (fechado, sem
--     internet): nunca há bloqueio permanente e nada impede a consulta ou o registro de exemplares.
--  3. requisicoes_idempotentes: identificador único de cada envio de formulário de cadastro.
--     Gravado NA MESMA transação do cadastro: se o cadastro foi concluído, um novo envio do mesmo
--     formulário (clique duplo, conexão perdida, página recarregada) devolve o resultado anterior
--     em vez de criar outra cópia.
--  4. planilha_bdp_versoes: emissões oficiais da Planilha BDP de cada lote (Versão 01, 02...),
--     com a cópia do conteúdo apresentado naquele momento. Não são alteradas nem apagadas.
--  5. Central de Qualidade do Acervo: qualidade_analises (cada análise feita sob demanda),
--     qualidade_pendencias (resultado da última análise) e qualidade_decisoes (justificativas,
--     "não é duplicidade", "informação indisponível"... histórico que não é apagado).
--  6. obra_unificacoes: unificação de dois cadastros bibliográficos realmente equivalentes. Os
--     exemplares (mesmos códigos BDP) passam para a obra mantida; a outra fica arquivada e aponta
--     para a mantida (livros.unificado_em_id). Histórico completo e imutável.
--  7. aquisicoes: entrada de novos livros (compra, doação, transferência...) depois do inventário
--     inicial. exemplares.aquisicao_id / data_entrada ligam cada exemplar à sua entrada.
--  8. Baixa administrativa: tipo, responsável e observação da baixa (exemplares.baixa_*).
--  9. Capacidade estimada das prateleiras (localizacoes.capacidade_estimada, opcional).
-- 10. Conservação registrada na devolução (emprestimos.conservacao_*).
-- 11. Inventário periódico (inventarios.tipo): usa os exemplares já cadastrados, sem as linhas da
--     planilha original e sem gerar códigos BDP novos.
--
-- Incremental e seguro: não apaga nem recria tabelas, não muda códigos BDP, lotes, exemplares,
-- empréstimos nem dados bibliográficos. Aplicar com: C:\xampp\php\php.exe database/migrar.php
-- =========================================================

-- ---------------------------------------------------------
-- 1. Versão do registro bibliográfico
-- ---------------------------------------------------------
ALTER TABLE livros
    ADD COLUMN versao          INT UNSIGNED NOT NULL DEFAULT 1 AFTER atualizado_em,
    ADD COLUMN unificado_em_id BIGINT NULL AFTER versao,          -- obra que recebeu este cadastro numa unificação
    ADD KEY livros_unificado_idx (unificado_em_id),
    ADD CONSTRAINT fk_livros_unificado_em FOREIGN KEY (unificado_em_id) REFERENCES livros (id);

-- Pesquisas frequentes (editora e ano na Central de Qualidade e nas sugestões).
ALTER TABLE livros
    ADD KEY livros_editora_idx (editora);

-- ---------------------------------------------------------
-- 2. Edição em andamento (aviso de edição simultânea)
-- ---------------------------------------------------------
CREATE TABLE obra_edicoes (
    livro_id         BIGINT NOT NULL,
    administrador_id BIGINT NOT NULL,
    versao_aberta    INT UNSIGNED NOT NULL,                 -- versão da obra quando o formulário foi aberto
    iniciado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    renovado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- última confirmação do navegador
    PRIMARY KEY (livro_id, administrador_id),
    KEY obra_edicoes_renovado_idx (renovado_em),
    CONSTRAINT fk_obra_edicoes_livro FOREIGN KEY (livro_id) REFERENCES livros (id),
    CONSTRAINT fk_obra_edicoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3. Envios únicos de formulário (idempotência)
-- ---------------------------------------------------------
CREATE TABLE requisicoes_idempotentes (
    chave            CHAR(32) NOT NULL PRIMARY KEY,         -- gerado pelo servidor ao abrir o formulário
    administrador_id BIGINT NULL,
    operacao         VARCHAR(40) NOT NULL,                  -- exemplares_quantidade | ...
    resultado        MEDIUMTEXT NULL,                       -- JSON do que foi gravado (ids e códigos BDP)
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY requisicoes_idempotentes_admin_idx (administrador_id, criado_em),
    CONSTRAINT fk_requisicoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 4. Versões emitidas da Planilha BDP de cada lote
-- ---------------------------------------------------------
CREATE TABLE planilha_bdp_versoes (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id     BIGINT NOT NULL,
    versao      INT UNSIGNED NOT NULL,                      -- 1, 2, 3... exibido como "Versão 01"
    emitida_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    emitida_por BIGINT NULL,
    motivo      VARCHAR(500) NULL,
    total       INT NOT NULL DEFAULT 0,
    assinatura  CHAR(64) NOT NULL,                          -- SHA-256 do conteúdo relevante para a carimbagem
    conteudo    MEDIUMTEXT NOT NULL,                        -- JSON: linhas exatamente como foram apresentadas
    UNIQUE KEY planilha_bdp_versoes_uk (lote_id, versao),
    KEY planilha_bdp_versoes_emitida_idx (emitida_em),
    CONSTRAINT fk_planilha_versoes_lote FOREIGN KEY (lote_id) REFERENCES cadastro_lotes (id),
    CONSTRAINT fk_planilha_versoes_admin FOREIGN KEY (emitida_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER planilha_bdp_versoes_sem_alteracao
BEFORE UPDATE ON planilha_bdp_versoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versões emitidas da Planilha BDP não são alteradas. Emita uma nova versão.';

CREATE TRIGGER planilha_bdp_versoes_sem_exclusao
BEFORE DELETE ON planilha_bdp_versoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versões emitidas da Planilha BDP não são apagadas.';

-- ---------------------------------------------------------
-- 5. Central de Qualidade do Acervo
-- ---------------------------------------------------------
CREATE TABLE qualidade_analises (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    iniciada_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluida_em     DATETIME NULL,
    administrador_id BIGINT NULL,
    totais           TEXT NULL,                             -- JSON {critico, atencao, informativo, novas, resolvidas}
    KEY qualidade_analises_data_idx (iniciada_em),
    CONSTRAINT fk_qualidade_analises_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qualidade_pendencias (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    chave           VARCHAR(120) NOT NULL,                  -- tipo + alvo(s): identifica a mesma situação entre análises
    tipo            VARCHAR(40) NOT NULL,
    gravidade       ENUM('critico', 'atencao', 'informativo') NOT NULL,
    grupo           ENUM('bibliografico', 'exemplar', 'padronizacao', 'duplicidade') NOT NULL,
    alvo_tipo       ENUM('obra', 'exemplar', 'autor', 'editora', 'categoria', 'lote', 'localizacao') NOT NULL,
    alvo_id         BIGINT NULL,
    alvo2_id        BIGINT NULL,
    descricao       VARCHAR(500) NOT NULL,
    detalhes        TEXT NULL,                              -- JSON com os dados usados na identificação
    origem          VARCHAR(20) NULL,                       -- planilha | manual | inventario | sistema
    responsavel_id  BIGINT NULL,                            -- quem cadastrou o registro (quando conhecido)
    assinatura      CHAR(40) NOT NULL,                      -- SHA-1 dos dados do registro (reavaliação depois de uma decisão)
    situacao        ENUM('aberta', 'justificada', 'resolvida') NOT NULL DEFAULT 'aberta',
    primeira_vez_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultima_vez_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvida_em    DATETIME NULL,
    analise_id      BIGINT NULL,
    UNIQUE KEY qualidade_pendencias_chave_uk (chave),
    KEY qualidade_pendencias_filtro_idx (situacao, gravidade, grupo),
    KEY qualidade_pendencias_tipo_idx (tipo, situacao),
    KEY qualidade_pendencias_alvo_idx (alvo_tipo, alvo_id),
    CONSTRAINT fk_qualidade_pendencias_resp FOREIGN KEY (responsavel_id) REFERENCES administradores (id),
    CONSTRAINT fk_qualidade_pendencias_analise FOREIGN KEY (analise_id) REFERENCES qualidade_analises (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qualidade_decisoes (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    chave            VARCHAR(120) NOT NULL,
    decisao          ENUM('justificada', 'indisponivel', 'nao_duplicidade', 'reaberta') NOT NULL,
    justificativa    VARCHAR(1000) NOT NULL,
    assinatura       CHAR(40) NOT NULL,                     -- dados do registro no momento da decisão
    administrador_id BIGINT NULL,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY qualidade_decisoes_chave_idx (chave, id),
    CONSTRAINT fk_qualidade_decisoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER qualidade_decisoes_sem_alteracao
BEFORE UPDATE ON qualidade_decisoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As decisões da Central de Qualidade não são alteradas. Registre uma nova decisão.';

CREATE TRIGGER qualidade_decisoes_sem_exclusao
BEFORE DELETE ON qualidade_decisoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As decisões da Central de Qualidade não são apagadas.';

-- ---------------------------------------------------------
-- 6. Unificação de cadastros bibliográficos
-- ---------------------------------------------------------
CREATE TABLE obra_unificacoes (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    livro_mantido_id  BIGINT NOT NULL,
    livro_unificado_id BIGINT NOT NULL,                     -- cadastro arquivado e apontado para o mantido
    exemplares        TEXT NULL,                            -- JSON [{id, codigo}] transferidos
    registros         TEXT NULL,                            -- JSON [ids de registros_legados] transferidos
    dados_unificado   TEXT NOT NULL,                        -- JSON: cópia dos dados bibliográficos do cadastro unificado
    motivo            VARCHAR(500) NOT NULL,
    administrador_id  BIGINT NULL,
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY obra_unificacoes_mantido_idx (livro_mantido_id),
    KEY obra_unificacoes_unificado_idx (livro_unificado_id),
    CONSTRAINT fk_unificacoes_mantido FOREIGN KEY (livro_mantido_id) REFERENCES livros (id),
    CONSTRAINT fk_unificacoes_unificado FOREIGN KEY (livro_unificado_id) REFERENCES livros (id),
    CONSTRAINT fk_unificacoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER obra_unificacoes_sem_alteracao
BEFORE UPDATE ON obra_unificacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de unificações não é alterado.';

CREATE TRIGGER obra_unificacoes_sem_exclusao
BEFORE DELETE ON obra_unificacoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de unificações não é apagado.';

-- ---------------------------------------------------------
-- 7. Aquisições (novas entradas no acervo)
-- ---------------------------------------------------------
CREATE TABLE aquisicoes (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    tipo                ENUM('compra', 'doacao', 'transferencia', 'outro') NOT NULL,
    origem              VARCHAR(150) NOT NULL,              -- fornecedor, doador, escola de origem...
    data_recebimento    DATE NOT NULL,
    quantidade_recebida INT UNSIGNED NOT NULL,              -- volumes recebidos (referência; os exemplares são registrados um a um)
    documento           VARCHAR(150) NULL,                  -- nota fiscal, termo de doação, ofício...
    observacoes         VARCHAR(1000) NULL,
    administrador_id    BIGINT NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY aquisicoes_data_idx (data_recebimento),
    KEY aquisicoes_tipo_idx (tipo, data_recebimento),
    CONSTRAINT fk_aquisicoes_admin FOREIGN KEY (administrador_id) REFERENCES administradores (id),
    CONSTRAINT aquisicoes_quantidade_ck CHECK (quantidade_recebida BETWEEN 1 AND 100000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER aquisicoes_sem_exclusao
BEFORE DELETE ON aquisicoes
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O registro de aquisições não é apagado.';

-- ---------------------------------------------------------
-- 7 e 8. Exemplares: entrada no acervo e baixa administrativa
-- ---------------------------------------------------------
ALTER TABLE exemplares
    ADD COLUMN aquisicao_id     BIGINT NULL AFTER inventario_id,
    ADD COLUMN data_entrada     DATE NULL AFTER aquisicao_id,          -- vazio = data do cadastro
    ADD COLUMN baixa_tipo       ENUM('extravio', 'dano', 'descarte', 'doacao', 'substituicao', 'outro') NULL AFTER motivo_baixa,
    ADD COLUMN baixado_por      BIGINT NULL AFTER baixa_tipo,
    ADD COLUMN baixa_observacao VARCHAR(1000) NULL AFTER baixado_por,
    ADD KEY exemplares_aquisicao_idx (aquisicao_id),
    ADD KEY exemplares_baixa_idx (situacao, baixado_em),
    ADD CONSTRAINT fk_exemplares_aquisicao FOREIGN KEY (aquisicao_id) REFERENCES aquisicoes (id),
    ADD CONSTRAINT fk_exemplares_baixado_por FOREIGN KEY (baixado_por) REFERENCES administradores (id);

-- ---------------------------------------------------------
-- 9. Capacidade estimada das prateleiras (opcional)
-- ---------------------------------------------------------
ALTER TABLE localizacoes
    ADD COLUMN capacidade_estimada INT UNSIGNED NULL AFTER ordem;

-- ---------------------------------------------------------
-- 10. Conservação na devolução
-- ---------------------------------------------------------
ALTER TABLE emprestimos
    ADD COLUMN conservacao_anterior  VARCHAR(20) NULL AFTER observacoes,   -- estado registrado antes da devolução
    ADD COLUMN conservacao_devolucao VARCHAR(20) NULL AFTER conservacao_anterior;

-- ---------------------------------------------------------
-- 11. Inventário periódico
-- ---------------------------------------------------------
ALTER TABLE inventarios
    ADD COLUMN tipo ENUM('inicial', 'periodico') NOT NULL DEFAULT 'inicial' AFTER titulo;

-- Consultas de circulação por exemplar e período.
ALTER TABLE emprestimos
    ADD KEY emprestimos_exemplar_data_idx (exemplar_id, data_emprestimo);

-- ---------------------------------------------------------
-- 12. Teste de restauração das cópias de segurança
--     (restauradas num banco SEPARADO e temporário, nunca no banco da biblioteca)
-- ---------------------------------------------------------
ALTER TABLE backups
    ADD COLUMN restauracao_testada_em DATETIME NULL,
    ADD COLUMN restauracao_ok         BOOLEAN NULL,
    ADD COLUMN restauracao_detalhe    VARCHAR(500) NULL;
