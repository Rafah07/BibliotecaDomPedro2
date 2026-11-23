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
        // Cópias grandes: tempos de espera maiores na sessão evitam "MySQL server has gone away" (2006)
        // enquanto o arquivo é gravado em blocos.
        \Biblioteca\Support\Migracoes::prepararSessaoLonga($this->pdo);
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

    /**
     * Gera a cópia num arquivo da pasta PROTEGIDA do servidor ($pasta, fora do acesso público) e
     * CONFERE o arquivo depois de gravado: existe, não está vazio, termina com a linha de fim da
     * cópia e tem a soma SHA-256 calculada. Só então registra na tabela "backups" (quem, quando,
     * motivo). Se algo falhar, o arquivo incompleto é apagado e nada é registrado como sucesso.
     * Senhas nunca aparecem em texto: o banco só guarda os hashes (password_hash).
     * @return array{id: int, arquivo: string, caminho: string, tamanho: int, sha256: string, tabelas: int, linhas: int}
     */
    public function salvarNoServidor(string $pasta, ?int $administradorId, string $origem = 'servidor', string $motivo = ''): array
    {
        if (!in_array($origem, ['servidor', 'automatico', 'migracao'], true)) {
            throw new \InvalidArgumentException('Origem de backup inválida.');
        }
        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException('A cópia de segurança precisa ser feita fora de uma transação.');
        }
        self::prepararPasta($pasta);
        $arquivo = 'biblioteca-dom-pedro-ii-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
        $caminho = rtrim($pasta, '/\\') . DIRECTORY_SEPARATOR . $arquivo;
        $f = @fopen($caminho, 'xb');
        if ($f === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo da cópia na pasta de backups (permissão de escrita?).');
        }
        try {
            $resumo = $this->exportar($f);
        } catch (\Throwable $e) {
            fclose($f);
            @unlink($caminho);
            throw $e;
        }
        fclose($f);
        clearstatcache(true, $caminho);
        $tamanho = (int)@filesize($caminho);
        if ($tamanho <= 0 || !self::terminaCompleto($caminho)) {
            @unlink($caminho);
            throw new \RuntimeException('A cópia foi gerada incompleta e foi descartada. Tente de novo.');
        }
        $sha = (string)hash_file('sha256', $caminho);
        @chmod($caminho, 0600);
        $stmt = $this->pdo->prepare('INSERT INTO backups (arquivo, origem, motivo, tamanho, sha256, tabelas, linhas, verificado, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $stmt->execute([$arquivo, $origem, mb_substr($motivo, 0, 255) ?: null, $tamanho, $sha, $resumo['tabelas'], $resumo['linhas'], $administradorId]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'arquivo' => $arquivo, 'caminho' => $caminho, 'tamanho' => $tamanho, 'sha256' => $sha,
            'tabelas' => $resumo['tabelas'], 'linhas' => $resumo['linhas']];
    }

    /** Registra uma cópia baixada pelo navegador (não dá para conferir o arquivo no computador de quem baixou). */
    public function registrarDownload(string $arquivo, array $resumo, ?int $administradorId): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO backups (arquivo, origem, tabelas, linhas, verificado, administrador_id) VALUES (?, 'download', ?, ?, 0, ?)");
        $stmt->execute([$arquivo, $resumo['tabelas'] ?? null, $resumo['linhas'] ?? null, $administradorId]);
    }

    /** Última cópia registrada (qualquer origem) ou null. */
    public function ultima(): ?array
    {
        $r = $this->pdo->query('SELECT b.*, a.nome AS administrador_nome FROM backups b LEFT JOIN administradores a ON a.id = b.administrador_id ORDER BY b.criado_em DESC, b.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Cópias registradas, das mais novas para as mais antigas. */
    public function listar(int $limite = 20): array
    {
        return $this->pdo->query('SELECT b.*, a.nome AS administrador_nome FROM backups b LEFT JOIN administradores a ON a.id = b.administrador_id ORDER BY b.id DESC LIMIT ' . max(1, min(200, $limite)))->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pasta de backups: criada se faltar, com .htaccess "Require all denied" e index.html vazio. */
    public static function prepararPasta(string $pasta): void
    {
        if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar a pasta de backups.');
        }
        $ht = $pasta . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Cópias de segurança: nunca podem ser baixadas diretamente pelo navegador.\nRequire all denied\n");
        }
        $idx = $pasta . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($idx)) {
            @file_put_contents($idx, '');
        }
    }

    /**
     * Teste de recuperação (migração 190): restaura a cópia num banco SEPARADO e temporário
     * ("<banco>_verifica_xxxxxx"), confere tabelas e linhas com o que a cópia registrou e apaga o banco
     * temporário. O banco da biblioteca nunca é usado como destino. Se o usuário do banco não puder
     * criar outro banco (comum em hospedagem compartilhada), devolve ok = null com a explicação.
     * O resultado fica gravado na linha da cópia (backups.restauracao_*).
     * @param array{host: string, port: int, database: string, username: string, password: string} $config
     * @return array{ok: ?bool, detalhe: string, tabelas?: int, linhas?: int}
     */
    public function testarRestauracao(int $backupId, string $pasta, array $config): array
    {
        $b = $this->pdo->prepare('SELECT * FROM backups WHERE id = ?');
        $b->execute([$backupId]);
        $copia = $b->fetch(PDO::FETCH_ASSOC);
        if (!$copia || $copia['origem'] === 'download') {
            return ['ok' => false, 'detalhe' => 'Cópia não encontrada no servidor (cópias baixadas pelo navegador ficam só no computador de quem baixou).'];
        }
        $caminho = rtrim($pasta, '/\\') . DIRECTORY_SEPARATOR . basename((string)$copia['arquivo']);
        if (!is_file($caminho)) {
            return $this->gravarTeste($backupId, false, 'O arquivo não está mais na pasta de backups.');
        }
        if ($copia['sha256'] && !hash_equals((string)$copia['sha256'], (string)hash_file('sha256', $caminho))) {
            return $this->gravarTeste($backupId, false, 'O arquivo foi alterado ou corrompido depois de gerado (a soma SHA-256 não confere).');
        }
        $temp = substr(preg_replace('/[^A-Za-z0-9_]/', '', (string)$config['database']), 0, 40) . '_verifica_' . bin2hex(random_bytes(3));
        if (strcasecmp($temp, (string)$config['database']) === 0) {
            return ['ok' => false, 'detalhe' => 'Recusado: o destino seria o banco da biblioteca.'];
        }
        try {
            $raiz = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']), $config['username'], (string)$config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $raiz->exec('CREATE DATABASE `' . $temp . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\PDOException $e) {
            return $this->gravarTeste($backupId, null, 'Não foi possível testar automaticamente: o usuário do banco não pode criar um banco separado para o teste. Faça o teste manual descrito em docs/backup-recuperacao.md.');
        }
        try {
            $destino = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $temp), $config['username'], (string)$config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $comandos = self::importar($destino, $caminho);
            $tabelas = 0;
            $linhas = 0;
            foreach ($destino->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
                $tabelas++;
                $linhas += (int)$destino->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
            }
            $confere = ($copia['tabelas'] === null || (int)$copia['tabelas'] === $tabelas) && ($copia['linhas'] === null || (int)$copia['linhas'] === $linhas);
            $detalhe = $confere
                ? 'Restaurada num banco separado: ' . $tabelas . ' tabelas e ' . number_format($linhas, 0, ',', '.') . ' linhas, iguais às registradas na cópia (' . $comandos . ' comandos).'
                : 'Restaurada, mas os números não conferem: ' . $tabelas . ' tabelas/' . $linhas . ' linhas restauradas; a cópia registrou ' . $copia['tabelas'] . '/' . $copia['linhas'] . '.';
            return $this->gravarTeste($backupId, $confere, $detalhe) + ['tabelas' => $tabelas, 'linhas' => $linhas];
        } catch (\Throwable $e) {
            return $this->gravarTeste($backupId, false, 'A restauração de teste falhou: ' . \Biblioteca\Support\RegistroErros::limpar($e->getMessage()));
        } finally {
            $destino = null;
            try {
                $raiz->exec('DROP DATABASE IF EXISTS `' . $temp . '`'); // só o banco temporário criado acima
            } catch (\Throwable) {
            }
        }
    }

    private function gravarTeste(int $backupId, ?bool $ok, string $detalhe): array
    {
        try {
            $this->pdo->prepare('UPDATE backups SET restauracao_testada_em = NOW(), restauracao_ok = ?, restauracao_detalhe = ? WHERE id = ?')
                ->execute([$ok === null ? null : (int)$ok, mb_substr($detalhe, 0, 500), $backupId]);
        } catch (\Throwable) {
            // Antes da migração 190 o resultado só aparece na tela.
        }
        return ['ok' => $ok, 'detalhe' => $detalhe];
    }

    /**
     * Executa um arquivo gerado por exportar() (comando a comando, respeitando "DELIMITER ;;").
     * Usado SÓ no teste de restauração, num banco temporário.
     * @return int comandos executados
     */
    public static function importar(PDO $destino, string $caminho): int
    {
        $f = fopen($caminho, 'rb');
        if (!$f) {
            throw new \RuntimeException('Não foi possível ler a cópia.');
        }
        $delimitador = ';';
        $buffer = '';
        $n = 0;
        try {
            while (($linha = fgets($f)) !== false) {
                $t = rtrim($linha, "\r\n");
                if ($buffer === '' && (trim($t) === '' || str_starts_with($t, '--'))) {
                    continue;
                }
                if ($buffer === '' && preg_match('/^DELIMITER\s+(\S+)\s*$/i', $t, $m)) {
                    $delimitador = $m[1];
                    continue;
                }
                $buffer .= $linha;
                if (str_ends_with(rtrim($t), $delimitador)) {
                    $sql = substr(rtrim($buffer), 0, -strlen($delimitador));
                    if (trim($sql) !== '') {
                        $destino->exec($sql);
                        $n++;
                    }
                    $buffer = '';
                }
            }
        } finally {
            fclose($f);
        }
        return $n;
    }

    /** O arquivo termina com "-- Fim da cópia de segurança." (a cópia não parou no meio). */
    public static function terminaCompleto(string $caminho): bool
    {
        $f = @fopen($caminho, 'rb');
        if (!$f) {
            return false;
        }
        fseek($f, -200, SEEK_END);
        $fim = (string)fread($f, 200);
        fclose($f);
        return str_contains($fim, '-- Fim da cópia de segurança.') && !str_contains($fim, '-- ERRO');
    }

    /** INSERTs da tabela, em blocos. Devolve o número de linhas. */
    private function dados(string $tabela, string $banco, callable $w): int
    {
        // Colunas geradas (ex.: estantes.setor_chave, localizacoes.chave_avulsa) são recalculadas pelo banco:
        // não entram no INSERT (gravá-las daria erro na restauração).
        $tipos = $this->pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                                      AND EXTRA NOT LIKE '%STORED GENERATED%' AND EXTRA NOT LIKE '%VIRTUAL GENERATED%' ORDER BY ORDINAL_POSITION");
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
