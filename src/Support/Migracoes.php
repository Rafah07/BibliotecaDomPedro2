<?php

namespace Biblioteca\Support;

use PDO;
use PDOException;
use Throwable;

/**
 * Apoio ao database/migrar.php e ao Diagnóstico do sistema.
 *
 *  - comandos(): separa um arquivo .sql em comandos (blocos BEGIN ... END ficam inteiros).
 *  - jaExiste(): um comando recusado só falhou porque o que ele cria já existe (retomada).
 *  - estrutura(): o que cada arquivo cria (tabelas, colunas, gatilhos, procedimentos) e se já existe
 *    no banco REAL — é assim que uma migração interrompida no meio é reconhecida antes da retomada.
 *  - privilegios(): permissões do usuário do banco necessárias para aplicar migrações.
 *
 * Tudo aqui só CONSULTA a estrutura: nada é apagado, reparado ou alterado.
 *
 * Observação importante: no MySQL e no MariaDB, CREATE/ALTER/DROP confirmam a transação
 * automaticamente (commit implícito). Uma migração interrompida pode ter mudado parte das tabelas;
 * por isso a retomada nunca presume que "nada aconteceu" e confere o banco antes.
 */
final class Migracoes
{
    /** Permissões usadas pelas migrações (CREATE/ALTER/INDEX/DROP de gatilhos e índices, gatilhos, rotinas, views). */
    public const PRIVILEGIOS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'INDEX', 'DROP', 'REFERENCES', 'TRIGGER', 'CREATE ROUTINE', 'CREATE VIEW'];

    /** Erros de conexão perdida durante o comando: o resultado do comando é DESCONHECIDO. */
    public const ERROS_CONEXAO = [2006, 2013, 2055];

    /**
     * Separa o arquivo em comandos. Blocos BEGIN ... END (procedures e triggers)
     * ficam inteiros, por isso não é preciso usar DELIMITER.
     * @return list<string>
     */
    public static function comandos(string $sql): array
    {
        $comandos = [];
        $atual = '';
        $nivel = 0;
        $tokens = preg_split("/('(?:[^'\\\\]|\\\\.|'')*'|--[^\\n]*|;|\\bBEGIN\\b|\\bEND\\b(?!\\s+(?:IF|WHILE|LOOP|REPEAT|CASE)\\b))/i", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                continue; // comentário
            }
            $maiusculo = strtoupper($token);
            if ($maiusculo === 'BEGIN') {
                $nivel++;
            } elseif ($maiusculo === 'END' && $nivel > 0) {
                $nivel--;
            }
            if ($token === ';' && $nivel === 0) {
                if (trim($atual) !== '') {
                    $comandos[] = trim($atual);
                }
                $atual = '';
                continue;
            }
            $atual .= $token;
        }
        if (trim($atual) !== '') {
            $comandos[] = trim($atual);
        }
        return $comandos;
    }

    /**
     * Diz se um comando recusado só falhou porque o que ele cria já existe (aplicação anterior
     * interrompida no meio). Nunca apaga nem altera nada: apenas consulta a estrutura.
     *   1050 tabela já existe · 1060 coluna já existe · 1061 índice já existe
     *   1359 gatilho já existe · 1826 chave estrangeira já existe · 1304 procedimento já existe
     */
    public static function jaExiste(PDO $pdo, string $sql, int $codigo): bool
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (in_array($codigo, [1050, 1061, 1359, 1826, 1304], true)) {
            return (bool)preg_match('/^(CREATE (TABLE|TRIGGER|PROCEDURE|(UNIQUE )?INDEX)|ALTER TABLE)\b/i', $sql);
        }
        if ($codigo !== 1060 || !preg_match('/^ALTER TABLE `?(\w+)`?/i', $sql, $t)) {
            return false;
        }
        $colunas = self::colunasAdicionadas($sql);
        if (!$colunas || preg_match('/\b(MODIFY|CHANGE|DROP|RENAME)\b/i', $sql)) {
            return false;
        }
        foreach ($colunas as $coluna) {
            if (!self::colunaExiste($pdo, $t[1], $coluna)) {
                return false; // falta uma coluna: não é só repetição, o erro precisa aparecer
            }
        }
        return true;
    }

    /** O comando só lê/escreve dados (sem mudar estrutura)? */
    public static function ehDados(string $sql): bool
    {
        return (bool)preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql);
    }

    /** @return list<string> */
    public static function colunasAdicionadas(string $sql): array
    {
        preg_match_all('/\bADD (?:COLUMN )?(?!INDEX\b|KEY\b|UNIQUE\b|CONSTRAINT\b|FOREIGN\b|PRIMARY\b|FULLTEXT\b)`?(\w+)`?/i', $sql, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * O que o arquivo cria e se já existe no banco real.
     * @return list<array{tipo: string, nome: string, existe: bool}>
     */
    public static function estrutura(PDO $pdo, string $conteudo): array
    {
        $itens = [];
        foreach (self::comandos($conteudo) as $sql) {
            $s = preg_replace('/\s+/', ' ', $sql);
            if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/i', $s, $m)) {
                $itens[] = ['tipo' => 'tabela', 'nome' => $m[1], 'existe' => self::tabelaExiste($pdo, $m[1])];
            } elseif (preg_match('/^CREATE (?:OR REPLACE )?VIEW `?(\w+)`?/i', $s, $m)) {
                $itens[] = ['tipo' => 'view', 'nome' => $m[1], 'existe' => self::tabelaExiste($pdo, $m[1])];
            } elseif (preg_match('/^CREATE TRIGGER `?(\w+)`?/i', $s, $m)) {
                $itens[] = ['tipo' => 'gatilho', 'nome' => $m[1], 'existe' => self::existe($pdo, 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$m[1]])];
            } elseif (preg_match('/^CREATE PROCEDURE `?(\w+)`?/i', $s, $m)) {
                $itens[] = ['tipo' => 'procedimento', 'nome' => $m[1], 'existe' => self::existe($pdo, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?", [$m[1]])];
            } elseif (preg_match('/^ALTER TABLE `?(\w+)`?/i', $s, $m)) {
                foreach (self::colunasAdicionadas($s) as $coluna) {
                    $itens[] = ['tipo' => 'coluna', 'nome' => $m[1] . '.' . $coluna, 'existe' => self::colunaExiste($pdo, $m[1], $coluna)];
                }
            }
        }
        return $itens;
    }

    /**
     * Situação de um arquivo ainda não registrado: 'nao_iniciada' (nada dele existe),
     * 'parcial' (parte da estrutura já existe: aplicação interrompida) ou 'estrutura_completa'
     * (tudo existe, mas não foi registrado — por exemplo, parou num comando de dados).
     */
    public static function situacaoPendente(PDO $pdo, string $conteudo): string
    {
        $itens = self::estrutura($pdo, $conteudo);
        // Gatilhos recriados com DROP ... IF EXISTS existem antes (versões antigas): não contam.
        $novos = array_filter($itens, fn ($i) => $i['tipo'] !== 'gatilho' && $i['tipo'] !== 'view');
        if (!$novos) {
            return 'nao_iniciada';
        }
        $existentes = count(array_filter($novos, fn ($i) => $i['existe']));
        return $existentes === 0 ? 'nao_iniciada' : ($existentes === count($novos) ? 'estrutura_completa' : 'parcial');
    }

    /**
     * Permissões do usuário atual no banco atual.
     * @return array{verificado: bool, faltando: list<string>, detalhe: string}
     */
    public static function privilegios(PDO $pdo): array
    {
        try {
            $banco = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $linhas = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return ['verificado' => false, 'faltando' => [], 'detalhe' => 'Não foi possível consultar as permissões do usuário do banco.'];
        }
        $tem = [];
        $usaPapel = false;
        foreach ($linhas as $g) {
            if (!preg_match('/^GRANT (.+?) ON (\S+) TO /i', (string)$g, $m)) {
                if (preg_match('/^GRANT `?\w+`?(@`?[^`\s]*`?)? TO/i', (string)$g)) {
                    $usaPapel = true; // papel (role): as permissões vêm dele
                }
                continue;
            }
            $alvo = $m[2];
            if ($alvo !== '*.*' && !self::alvoCobre($alvo, $banco)) {
                continue;
            }
            foreach (array_map('trim', explode(',', strtoupper($m[1]))) as $p) {
                $p = preg_replace('/\s*\(.*$/', '', $p);
                if ($p === 'ALL PRIVILEGES' || $p === 'ALL') {
                    $tem = array_merge($tem, self::PRIVILEGIOS);
                } else {
                    $tem[] = $p;
                }
            }
        }
        $faltando = array_values(array_diff(self::PRIVILEGIOS, $tem));
        if ($faltando && $usaPapel) {
            return ['verificado' => false, 'faltando' => $faltando, 'detalhe' => 'O usuário recebe permissões por papel (role); não foi possível confirmar todas.'];
        }
        return ['verificado' => true, 'faltando' => $faltando, 'detalhe' => $faltando ? 'Faltam: ' . implode(', ', $faltando) . '.' : 'Todas as permissões necessárias estão presentes.'];
    }

    /** "`biblioteca\_dom\_pedro`.*" cobre o banco atual? (curingas do GRANT: _ e %, \_ literal). */
    private static function alvoCobre(string $alvo, string $banco): bool
    {
        if (!preg_match('/^`?([^`]+)`?\.\*$/', $alvo, $m)) {
            return false; // permissão em uma tabela só: não vale para migrações
        }
        $padrao = '';
        $txt = $m[1];
        for ($i = 0, $n = strlen($txt); $i < $n; $i++) {
            $c = $txt[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $padrao .= preg_quote($txt[++$i], '/');
            } elseif ($c === '_') {
                $padrao .= '.';
            } elseif ($c === '%') {
                $padrao .= '.*';
            } else {
                $padrao .= preg_quote($c, '/');
            }
        }
        return (bool)preg_match('/^' . $padrao . '$/i', $banco);
    }

    public static function tabelaExiste(PDO $pdo, string $tabela): bool
    {
        return self::existe($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabela]);
    }

    public static function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool
    {
        return self::existe($pdo, 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$tabela, $coluna]);
    }

    private static function existe(PDO $pdo, string $sql, array $p): bool
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Erro de conexão perdida ("MySQL server has gone away", "Lost connection")? */
    public static function conexaoPerdida(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $codigo = (int)($e->errorInfo[1] ?? 0);
        return in_array($codigo, self::ERROS_CONEXAO, true) || (bool)preg_match('/gone away|Lost connection|Error while sending/i', $e->getMessage());
    }

    /** Aumenta os tempos de espera da sessão (operações longas: migração e cópia de segurança). */
    public static function prepararSessaoLonga(PDO $pdo): void
    {
        foreach (['SET SESSION wait_timeout = 28800', 'SET SESSION net_read_timeout = 600', 'SET SESSION net_write_timeout = 600'] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable) {
                // Sem permissão para mudar a sessão: segue com os valores do servidor.
            }
        }
    }
}
