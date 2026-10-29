-- =========================================================
-- 030 · Importação das planilhas antigas — Etapa 3
--
-- Fluxo: enviar arquivo → mapear colunas → validar → revisar → confirmar.
-- Princípios:
--   * Nada entra no acervo antes da confirmação. Até lá, as linhas ficam em
--     "importacao_itens", com os valores originais exatamente como vieram.
--   * Correções da revisão ficam em uma coluna separada (dados_corrigidos_json):
--     o original da planilha nunca é sobrescrito.
--   * Os dados antigos (CDD, código de autor, localização, assunto, quantidade)
--     ficam em "registros_legados" e servem de comparação para a reclassificação
--     CDD (Etapa 4) e para o inventário físico (Etapa 5).
--   * A importação NÃO cria exemplares nem códigos BDP. A quantidade informada é
--     só uma referência; os exemplares nascem na conferência física.
--   * O mesmo arquivo não pode ser importado duas vezes por engano, e cada linha
--     só pode ser aplicada uma vez (processado_em).
-- =========================================================

-- ---------------------------------------------------------
-- Registro das ações administrativas (genérico, usado por todos os módulos)
-- ---------------------------------------------------------
CREATE TABLE acoes_administrativas (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  BIGINT NULL,
    acao        VARCHAR(60)  NOT NULL,       -- ex.: importacao.enviar, importacao.confirmar
    entidade    VARCHAR(60)  NULL,           -- ex.: importacao, livro
    entidade_id BIGINT NULL,
    detalhes    JSON NULL,
    ip          VARCHAR(45) NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY acoes_entidade_idx (entidade, entidade_id),
    KEY acoes_usuario_idx (usuario_id, criado_em),
    CONSTRAINT fk_acoes_usuario FOREIGN KEY (usuario_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Importações (um arquivo enviado = uma importação)
-- ---------------------------------------------------------
CREATE TABLE importacoes (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome_arquivo          VARCHAR(255) NOT NULL,            -- nome original, só para exibição
    identificador_arquivo VARCHAR(64) NOT NULL,             -- SHA-256 do conteúdo (VARCHAR: o MariaDB 10.5+ recusa CHAR na coluna gerada abaixo)
    arquivo_armazenado    VARCHAR(80) NOT NULL,             -- nome aleatório na área privada
    tipo_arquivo          ENUM('xlsx', 'csv') NOT NULL,
    tamanho_bytes         BIGINT NOT NULL,
    abas_json             JSON NULL,                        -- [{nome, linhas, colunas}]
    aba                   VARCHAR(120) NULL,                -- aba escolhida
    linha_cabecalho       INT NOT NULL DEFAULT 1,
    colunas_json          JSON NULL,                        -- cabeçalhos encontrados
    mapeamento_json       JSON NULL,                        -- coluna → campo
    usuario_id            BIGINT NULL,
    status                ENUM('enviada', 'em_analise', 'em_revisao', 'importando', 'concluida', 'parcial', 'cancelada', 'falhou')
                          NOT NULL DEFAULT 'enviada',
    total_linhas          INT NOT NULL DEFAULT 0,
    linhas_analisadas     INT NOT NULL DEFAULT 0,           -- progresso da análise em lotes
    total_validos         INT NOT NULL DEFAULT 0,
    total_avisos          INT NOT NULL DEFAULT 0,
    total_erros           INT NOT NULL DEFAULT 0,
    total_duplicidades    INT NOT NULL DEFAULT 0,
    total_ja_cadastrados  INT NOT NULL DEFAULT 0,
    total_ignorados       INT NOT NULL DEFAULT 0,
    total_importados      INT NOT NULL DEFAULT 0,           -- livros novos criados
    total_vinculados      INT NOT NULL DEFAULT 0,           -- linhas ligadas a livros já existentes
    total_falhas          INT NOT NULL DEFAULT 0,           -- linhas que falharam na confirmação
    mensagem_erro         VARCHAR(500) NULL,
    criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    iniciado_em           DATETIME NULL,                    -- início da confirmação
    finalizado_em         DATETIME NULL,
    -- Impede importar o mesmo arquivo duas vezes. Só libera o arquivo uma importação
    -- cancelada (ou com falha) que não chegou a gravar nenhuma linha no acervo.
    arquivo_ativo         VARCHAR(64) AS (IF(status IN ('cancelada', 'falhou') AND total_importados + total_vinculados = 0, NULL, identificador_arquivo)) STORED,
    UNIQUE KEY importacoes_arquivo_ativo_uk (arquivo_ativo),
    KEY importacoes_status_idx (status),
    KEY importacoes_criado_idx (criado_em),
    CONSTRAINT fk_importacoes_usuario FOREIGN KEY (usuario_id) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Linhas de cada importação
-- ---------------------------------------------------------
CREATE TABLE importacao_itens (
    id                      BIGINT AUTO_INCREMENT PRIMARY KEY,
    importacao_id           BIGINT NOT NULL,
    numero_linha            INT NOT NULL,                   -- linha original na planilha
    dados_originais_json    JSON NOT NULL,                  -- {cabeçalho: valor}, exatamente como veio
    dados_normalizados_json JSON NOT NULL,                  -- campos do sistema já limpos
    dados_corrigidos_json   JSON NULL,                      -- correções feitas na revisão
    status_validacao        ENUM('valido', 'aviso', 'erro', 'duplicidade') NOT NULL DEFAULT 'valido',
    erros_json              JSON NULL,                      -- [{nivel, codigo, campo, mensagem, linha_relacionada, livro_id}]
    ja_cadastrado           BOOLEAN NOT NULL DEFAULT FALSE, -- parece existir no acervo
    livro_sugerido_id       BIGINT NULL,                    -- livro existente parecido (sugestão)
    linha_relacionada       INT NULL,                       -- primeira linha parecida na mesma planilha
    acao_importacao         ENUM('criar', 'vincular', 'outro_exemplar', 'ignorar', 'revisar_depois') NULL,
    acao_automatica         BOOLEAN NOT NULL DEFAULT TRUE,  -- FALSE quando um usuário decidiu
    livro_vinculado_id      BIGINT NULL,                    -- escolhido pelo usuário (vincular / outro exemplar)
    item_referencia_id      BIGINT NULL,                    -- "outro exemplar" da linha indicada
    hash_linha              CHAR(40) NOT NULL,              -- registros idênticos
    chave_isbn              VARCHAR(13) NULL,
    chave_obra              VARCHAR(255) NULL,              -- título + autor normalizados
    revisado_por            BIGINT NULL,
    revisado_em             DATETIME NULL,
    livro_resultante_id     BIGINT NULL,                    -- livro criado ou vinculado na confirmação
    resultado_importacao    ENUM('criado', 'vinculado', 'outro_exemplar', 'falhou') NULL,
    mensagem_processamento  VARCHAR(500) NULL,
    processado_em           DATETIME NULL,                  -- preenchido uma única vez
    UNIQUE KEY importacao_itens_linha_uk (importacao_id, numero_linha),
    KEY importacao_itens_status_idx (importacao_id, status_validacao),
    KEY importacao_itens_isbn_idx (importacao_id, chave_isbn),
    KEY importacao_itens_obra_idx (importacao_id, chave_obra),
    KEY importacao_itens_hash_idx (importacao_id, hash_linha),
    KEY importacao_itens_pendentes_idx (importacao_id, processado_em, acao_importacao),
    CONSTRAINT fk_itens_importacao FOREIGN KEY (importacao_id) REFERENCES importacoes (id),
    CONSTRAINT fk_itens_livro_sugerido FOREIGN KEY (livro_sugerido_id) REFERENCES livros (id),
    CONSTRAINT fk_itens_livro_vinculado FOREIGN KEY (livro_vinculado_id) REFERENCES livros (id),
    CONSTRAINT fk_itens_livro_resultante FOREIGN KEY (livro_resultante_id) REFERENCES livros (id),
    CONSTRAINT fk_itens_referencia FOREIGN KEY (item_referencia_id) REFERENCES importacao_itens (id),
    CONSTRAINT fk_itens_revisado_por FOREIGN KEY (revisado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma linha já aplicada ao acervo não pode voltar a ser pendente nem trocar de livro.
CREATE TRIGGER importacao_itens_processado
BEFORE UPDATE ON importacao_itens
FOR EACH ROW
BEGIN
    IF OLD.processado_em IS NOT NULL AND OLD.resultado_importacao <> 'falhou' AND (
        NEW.processado_em IS NULL OR NOT (OLD.livro_resultante_id <=> NEW.livro_resultante_id)
        OR NOT (OLD.dados_originais_json <=> NEW.dados_originais_json)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta linha já foi importada e não pode ser alterada.';
    END IF;
    IF NOT (OLD.dados_originais_json <=> NEW.dados_originais_json) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os dados originais da planilha não podem ser alterados.';
    END IF;
END;

-- ---------------------------------------------------------
-- Mapeamentos de colunas salvos para reaproveitar
-- ---------------------------------------------------------
CREATE TABLE importacao_mapeamentos (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(120) NOT NULL,
    mapeamento_json JSON NOT NULL,                 -- {cabeçalho normalizado: campo}
    criado_por      BIGINT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY importacao_mapeamentos_nome_uk (nome),
    CONSTRAINT fk_mapeamentos_usuario FOREIGN KEY (criado_por) REFERENCES administradores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Dados antigos das planilhas, preservados para comparação
-- (lidos pela reclassificação CDD e pelo inventário físico)
-- ---------------------------------------------------------
CREATE TABLE registros_legados (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    importacao_id       BIGINT NOT NULL,
    importacao_item_id  BIGINT NOT NULL,
    livro_id            BIGINT NOT NULL,
    arquivo             VARCHAR(255) NOT NULL,      -- nome da planilha de origem
    linha               INT NOT NULL,               -- linha original
    titulo_original     VARCHAR(500) NULL,
    autor_original      VARCHAR(500) NULL,
    codigo_autor_antigo VARCHAR(50) NULL,
    localizacao_antiga  VARCHAR(100) NULL,
    cdd_original        VARCHAR(50) NULL,           -- texto: zeros e pontos preservados; não é validada
    assunto_original    VARCHAR(500) NULL,
    categoria_original  VARCHAR(255) NULL,
    quantidade_informada INT NULL,                  -- NULL = a planilha não informou
    representa_exemplar BOOLEAN NOT NULL DEFAULT FALSE, -- a linha descreve outra unidade física do mesmo livro
    observacoes         TEXT NULL,
    dados_originais     JSON NOT NULL,              -- cópia da linha como veio
    importado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY registros_legados_item_uk (importacao_item_id),
    KEY registros_legados_livro_idx (livro_id),
    KEY registros_legados_cdd_idx (cdd_original),
    KEY registros_legados_local_idx (localizacao_antiga),
    CONSTRAINT registros_legados_qtd_ck CHECK (quantidade_informada IS NULL OR quantidade_informada >= 0),
    CONSTRAINT fk_legados_importacao FOREIGN KEY (importacao_id) REFERENCES importacoes (id),
    CONSTRAINT fk_legados_item FOREIGN KEY (importacao_item_id) REFERENCES importacao_itens (id),
    CONSTRAINT fk_legados_livro FOREIGN KEY (livro_id) REFERENCES livros (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Os valores antigos não podem ser alterados nem apagados depois de importados.
CREATE TRIGGER registros_legados_imutavel
BEFORE UPDATE ON registros_legados
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os registros legados não podem ser alterados.';

CREATE TRIGGER registros_legados_sem_exclusao
BEFORE DELETE ON registros_legados
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os registros legados não podem ser excluídos.';

-- Contagens da listagem de livros, agora com a quantidade informada nas planilhas.
CREATE OR REPLACE VIEW livros_resumo AS
SELECT l.id,
       l.titulo,
       l.status_validacao,
       COUNT(e.id)                                 AS total_exemplares,
       COALESCE(SUM(e.situacao = 'disponivel'), 0) AS disponiveis,
       COALESCE(SUM(e.situacao = 'emprestado'), 0) AS emprestados,
       COALESCE(SUM(e.situacao = 'manutencao'), 0) AS em_manutencao,
       (SELECT SUM(r.quantidade_informada) FROM registros_legados r WHERE r.livro_id = l.id) AS quantidade_planilha
FROM livros l
LEFT JOIN exemplares e ON e.livro_id = l.id
WHERE l.arquivado_em IS NULL
GROUP BY l.id, l.titulo, l.status_validacao;
