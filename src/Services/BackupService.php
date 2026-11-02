<?php

namespace Biblioteca\Services;

use PDO;

/**
 * Cópia de segurança completa do banco em um arquivo .sql (Sistema → Manutenção).
 *
 * O arquivo tem a estrutura das tabelas, todos os dados, as views, os procedimentos e os gatilhos,
 * no formato aceito pelo phpMyAdmin (Importar) e pelo cliente mysql do XAMPP:
 *   C:\xampp\mysql\bin\mysql.exe -u root -p banco_vazio < copia.sql
 *
 * - Só leitura: nada no banco é alterado (só variáveis da própria sessão, restauradas no fim).
 * - Todas as tabelas saem do mesmo instante (transação com fotografia consistente do InnoDB).
 * - Não apaga tabelas no destino (sem DROP TABLE): restaure sempre em um banco VAZIO.
 * - Contém dados pessoais de alunos e os hashes de senha das contas: guarde em local protegido.
 * - Os dados saem em blocos (consulta sem buffer), então funciona com acervos grandes sem
 *   carregar tudo na memória.
 */
final class BackupService
{
    private const LINHAS_POR_INSERT = 200;
    private const TIPOS_BINARIOS = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Escreve a cópia em $saida (php://output, arquivo...).
     * @param resource $saida
     * @return array{tabelas: int, linhas: int, views: int, rotinas: int, gatilhos: int}
     */
    public function exportar($saida): array
    {
        $resumo = ['tabelas' => 0, 'linhas' => 0, 'views' => 0, 'rotinas' => 0, 'gatilhos' => 0];
        $banco = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $fusoAnterior = (string)$this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $this->pdo->exec("SET time_zone = '+00:00'"); // colunas TIMESTAMP saem em UTC, como no mysqldump
        $versao = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
        // Fotografia única do banco (como mysqldump --single-transaction): um empréstimo registrado
        // durante a cópia não aparece "pela metade" (ex.: o empréstimo sem a mudança do exemplar).
        // Só leitura; nada é gravado nesta transação.
        $snapshot = !$this->pdo->inTransaction();
        if ($snapshot) {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        $w = fn (string $texto) => fwrite($saida, $texto);
        $w("-- Cópia de segurança — Biblioteca Dom Pedro II\n");
        $w('-- Gerada em ' . date('d/m/Y H:i:s') . ' · servidor ' . $this->limparComentario($versao) . "\n");
        $w("-- ATENÇÃO: contém dados pessoais de alunos e hashes de senha. Guarde em local protegido.\n");
        $w("-- Restaure em um banco VAZIO (phpMyAdmin → Importar, ou mysql.exe banco < arquivo.sql).\n\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

        try {
            $objetos = $this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            $tabelas = [];
            $views = [];
            foreach ($objetos as [$nome, $tipo]) {
                if ($tipo === 'VIEW') {
                    $views[] = $nome;
                } else {
                    $tabelas[] = $nome;
                }
            }
            sort($tabelas);
            sort($views);

            foreach ($tabelas as $tabela) {
                $criar = $this->pdo->query('SHOW CREATE TABLE ' . $this->id($tabela))->fetch(PDO::FETCH_NUM)[1];
                $w("-- Tabela $tabela\n" . $criar . ";\n\n");
                $resumo['linhas'] += $this->dados($tabela, $banco, $w);
                $resumo['tabelas']++;
            }

            foreach ($views as $view) {
                $criar = $this->pdo->query('SHOW CREATE VIEW ' . $this->id($view))->fetch(PDO::FETCH_NUM)[1];
                $w("-- View $view\n" . $this->semDefiner($criar) . ";\n\n");
                $resumo['views']++;
            }

            $rotinas = [];
            foreach (['PROCEDURE', 'FUNCTION'] as $tipo) {
                $stmt = $this->pdo->prepare("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = ? ORDER BY ROUTINE_NAME");
                $stmt->execute([$banco, $tipo]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nome) {
                    $linha = $this->pdo->query("SHOW CREATE $tipo " . $this->id($nome))->fetch(PDO::FETCH_ASSOC);
                    $rotinas[] = $this->semDefiner((string)$linha[$tipo === 'PROCEDURE' ? 'Create Procedure' : 'Create Function']);
                }
            }
            $stmt = $this->pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER, TRIGGER_NAME');
            $stmt->execute([$banco]);
            $gatilhos = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nome) {
                $linha = $this->pdo->query('SHOW CREATE TRIGGER ' . $this->id($nome))->fetch(PDO::FETCH_ASSOC);
                $gatilhos[] = $this->semDefiner((string)$linha['SQL Original Statement']);
            }
            if ($rotinas || $gatilhos) {
                // Os gatilhos vêm DEPOIS dos dados, para não rodarem durante a restauração.
                $w("-- Procedimentos e gatilhos\nDELIMITER ;;\n");
                foreach (array_merge($rotinas, $gatilhos) as $sql) {
                    $w($sql . ";;\n");
                }
                $w("DELIMITER ;\n\n");
            }
            $resumo['rotinas'] = count($rotinas);
            $resumo['gatilhos'] = count($gatilhos);

            $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- Fim da cópia de segurança.\n");
        } finally {
            if ($snapshot && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            $this->pdo->exec('SET time_zone = ' . $this->pdo->quote($fusoAnterior));
        }
        return $resumo;
    }

    /** INSERTs da tabela, em blocos. Devolve o número de linhas. */
    private function dados(string $tabela, string $banco, callable $w): int
    {
        $tipos = $this->pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $tipos->execute([$banco, $tabela]);
        $colunas = [];
        foreach ($tipos->fetchAll(PDO::FETCH_NUM) as [$coluna, $tipo]) {
            $colunas[$coluna] = in_array(strtolower($tipo), self::TIPOS_BINARIOS, true);
        }
        $lista = implode(', ', array_map([$this, 'id'], array_keys($colunas)));
        $cabecalho = 'INSERT INTO ' . $this->id($tabela) . ' (' . $lista . ') VALUES' . "\n";

        $buffer = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $total = 0;
        $bloco = [];
        try {
            $stmt = $this->pdo->query('SELECT ' . $lista . ' FROM ' . $this->id($tabela));
            while ($linha = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $valores = [];
                foreach ($linha as $coluna => $valor) {
                    $valores[] = $this->valor($valor, $colunas[$coluna] ?? false);
                }
                $bloco[] = '(' . implode(', ', $valores) . ')';
                $total++;
                if (count($bloco) >= self::LINHAS_POR_INSERT) {
                    $w($cabecalho . implode(",\n", $bloco) . ";\n");
                    $bloco = [];
                }
            }
            $stmt->closeCursor();
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffer);
        }
        if ($bloco) {
            $w($cabecalho . implode(",\n", $bloco) . ";\n");
        }
        if ($total > 0) {
            $w("\n");
        }
        return $total;
    }

    private function valor(mixed $valor, bool $binario): string
    {
        if ($valor === null) {
            return 'NULL';
        }
        if (is_int($valor)) {
            return (string)$valor;
        }
        if (is_float($valor)) {
            return is_finite($valor) ? var_export($valor, true) : 'NULL';
        }
        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }
        $valor = (string)$valor;
        if ($binario) {
            return $valor === '' ? "''" : '0x' . bin2hex($valor);
        }
        return $this->pdo->quote($valor);
    }

    private function id(string $nome): string
    {
        return '`' . str_replace('`', '``', $nome) . '`';
    }

    /** Remove DEFINER=`usuario`@`host`: no banco restaurado, o dono passa a ser quem importou. */
    private function semDefiner(string $sql): string
    {
        return preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql, 1) ?? $sql;
    }

    private function limparComentario(string $texto): string
    {
        return preg_replace('/[^\w .:+-]/u', '', $texto) ?? '';
    }
}
