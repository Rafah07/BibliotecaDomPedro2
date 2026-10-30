-- =========================================================
-- 060 · Circulação: renovações e proteção do histórico — MySQL 8.0+ / MariaDB 10.4+
-- Biblioteca Dom Pedro II
--
-- Completa a estrutura de empréstimos criada na migração 010 (alunos, emprestimos)
-- sem recriar nada e sem alterar dados existentes:
--   * "emprestimo_renovacoes" guarda cada renovação (prazo anterior, novo prazo, quem e quando).
--   * "movimentacoes.tipo" ganha o valor 'renovacao' (os valores antigos continuam iguais).
--   * Um empréstimo devolvido não muda mais (não pode ser devolvido duas vezes nem renovado),
--     e o aluno, o exemplar e a data de retirada de um empréstimo nunca mudam.
--   * Índice para listar os empréstimos pela data de retirada.
--
-- As regras de prazo e de renovação ficam no .env (EMPRESTIMO_*), não no banco.
-- Depende de 001, 002 e 010.
-- =========================================================

CREATE TABLE emprestimo_renovacoes (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    emprestimo_id     BIGINT NOT NULL,
    prevista_anterior DATE NOT NULL,
    prevista_nova     DATE NOT NULL,
    renovado_por      BIGINT NULL,          -- administradores.id
    observacao        VARCHAR(500),
    criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY emprestimo_renovacoes_emprestimo_idx (emprestimo_id, criado_em),
    CONSTRAINT fk_renovacoes_emprestimo FOREIGN KEY (emprestimo_id) REFERENCES emprestimos (id),
    CONSTRAINT fk_renovacoes_renovado_por FOREIGN KEY (renovado_por) REFERENCES administradores (id),
    CONSTRAINT emprestimo_renovacoes_datas_ck CHECK (prevista_nova > prevista_anterior)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O histórico de renovações não é alterado nem apagado.
CREATE TRIGGER emprestimo_renovacoes_imutavel
BEFORE UPDATE ON emprestimo_renovacoes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de renovações não pode ser alterado.';
END;

CREATE TRIGGER emprestimo_renovacoes_sem_exclusao
BEFORE DELETE ON emprestimo_renovacoes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de renovações não pode ser excluído.';
END;

-- Empréstimo devolvido fica como está; os dados da retirada nunca mudam.
CREATE TRIGGER emprestimos_preservar_historico
BEFORE UPDATE ON emprestimos
FOR EACH ROW
BEGIN
    IF OLD.data_devolucao IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este empréstimo já foi devolvido e não pode ser alterado.';
    END IF;
    IF NEW.exemplar_id <> OLD.exemplar_id OR NEW.aluno_id <> OLD.aluno_id OR NEW.data_emprestimo <> OLD.data_emprestimo THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O aluno, o exemplar e a data de retirada de um empréstimo não podem ser alterados.';
    END IF;
END;

ALTER TABLE movimentacoes
    MODIFY COLUMN tipo ENUM('cadastro', 'emprestimo', 'devolucao', 'manutencao', 'localizacao', 'inventario', 'extravio', 'baixa', 'renovacao') NOT NULL;

ALTER TABLE emprestimos
    ADD KEY emprestimos_data_idx (data_emprestimo);
