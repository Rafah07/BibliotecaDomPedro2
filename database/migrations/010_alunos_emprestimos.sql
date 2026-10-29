-- =========================================================
-- 010 · Alunos e empréstimos — MySQL 8.0+
-- Biblioteca Dom Pedro II (Etapa 3.5: estrutura preparada, telas nas próximas etapas)
--
-- Regras:
--   * Alunos NÃO têm conta nem senha: continuam usando o site público sem login.
--     A tabela guarda apenas o cadastro usado pela biblioteca nos empréstimos.
--   * O empréstimo é sempre de um EXEMPLAR físico (código BDP), nunca de um livro
--     ou de uma quantidade de planilha.
--   * Um exemplar só pode ter um empréstimo em aberto (índice único em
--     exemplar_em_aberto). Atrasos são calculados por data_prevista, não gravados.
--   * Empréstimos não são excluídos: a devolução preenche data_devolucao.
--
-- Depende de 001 (exemplares) e 002 (administradores).
-- =========================================================

CREATE TABLE alunos (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(150) NOT NULL,
    matricula     VARCHAR(40)  NOT NULL,
    turma         VARCHAR(40),
    turno         ENUM('manha', 'tarde', 'noite', 'integral') NULL,
    email         VARCHAR(190),
    telefone      VARCHAR(30),
    ativo         BOOLEAN NOT NULL DEFAULT TRUE,
    observacoes   TEXT,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY alunos_matricula_uk (matricula),
    KEY alunos_nome_idx (nome),
    CONSTRAINT alunos_nome_ck CHECK (CHAR_LENGTH(TRIM(nome)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE emprestimos (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    exemplar_id        BIGINT NOT NULL,
    aluno_id           BIGINT NOT NULL,
    data_emprestimo    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_prevista      DATE NOT NULL,
    data_devolucao     DATETIME NULL,
    renovacoes         TINYINT NOT NULL DEFAULT 0,
    emprestado_por     BIGINT NULL,          -- administradores.id
    recebido_por       BIGINT NULL,          -- administradores.id (na devolução)
    observacoes        TEXT,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Preenchido só enquanto o empréstimo está aberto: garante um empréstimo ativo por exemplar.
    exemplar_em_aberto BIGINT AS (IF(data_devolucao IS NULL, exemplar_id, NULL)) STORED,
    UNIQUE KEY emprestimos_exemplar_aberto_uk (exemplar_em_aberto),
    KEY emprestimos_aluno_idx (aluno_id, data_emprestimo),
    KEY emprestimos_prevista_idx (data_devolucao, data_prevista),
    CONSTRAINT fk_emprestimos_exemplar FOREIGN KEY (exemplar_id) REFERENCES exemplares (id),
    CONSTRAINT fk_emprestimos_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id),
    CONSTRAINT fk_emprestimos_emprestado_por FOREIGN KEY (emprestado_por) REFERENCES administradores (id),
    CONSTRAINT fk_emprestimos_recebido_por FOREIGN KEY (recebido_por) REFERENCES administradores (id),
    CONSTRAINT emprestimos_datas_ck CHECK (data_devolucao IS NULL OR data_devolucao >= data_emprestimo),
    CONSTRAINT emprestimos_renovacoes_ck CHECK (renovacoes BETWEEN 0 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Empréstimos não podem ser apagados (histórico de circulação).
CREATE TRIGGER emprestimos_sem_exclusao
BEFORE DELETE ON emprestimos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Empréstimos não podem ser excluídos. Registre a devolução.';
END;

-- Liga o histórico de cada exemplar ao empréstimo que o originou (coluna opcional).
ALTER TABLE movimentacoes
    ADD COLUMN emprestimo_id BIGINT NULL AFTER exemplar_id,
    ADD CONSTRAINT fk_movimentacoes_emprestimo FOREIGN KEY (emprestimo_id) REFERENCES emprestimos (id);
