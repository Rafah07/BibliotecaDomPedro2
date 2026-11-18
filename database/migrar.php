<?php
/**
 * Aplica as migrações MySQL em ordem (database/migrations/NNN_nome.sql).
 *
 * Uso:  php database/migrar.php               aplica as pendentes (antes, faz uma cópia de segurança
 *                                             conferida em storage/backups — ou BACKUP_DIR no .env)
 *       php database/migrar.php --status      lista o que já foi aplicado
 *       php database/migrar.php --sem-backup  aplica sem a cópia automática (use só se já fez uma)
 *
 * Cada arquivo roda uma única vez e fica registrado em "schema_migracoes".
 * As migrações só criam ou acrescentam estruturas: nenhuma apaga dados existentes.
 * Observação: no MySQL, comandos de estrutura (CREATE/ALTER) confirmam a transação
 * automaticamente. Se um arquivo falhar no meio, basta corrigir o problema e rodar de novo:
 * tabelas, colunas, índices e gatilhos que ele já tinha criado são reconhecidos e pulados
 * (migracao_ja_existe). Colunas só são puladas quando TODAS as do comando já existem.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/database.php';

if (!db_configurado()) {
    fwrite(STDERR, "Banco não configurado. Copie .env.example para .env e preencha DB_NAME, DB_USER e DB_PASSWORD.\n");
    exit(1);
}
try {
    $pdo = db();
} catch (DbIndisponivel $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migracoes (
    arquivo VARCHAR(190) PRIMARY KEY,
    aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$aplicadas = $pdo->query('SELECT arquivo FROM schema_migracoes')->fetchAll(PDO::FETCH_COLUMN);
// Prefixo numérico define a ordem (001_, 002_ ... ou 0500_): comparação natural.
$arquivos = glob(__DIR__ . '/migrations/[0-9]*_*.sql');
usort($arquivos, fn ($a, $b) => strnatcmp(basename($a), basename($b)));

if (in_array('--status', $argv, true)) {
    foreach ($arquivos as $arquivo) {
        $nome = basename($arquivo);
        echo (in_array($nome, $aplicadas, true) ? '[x] ' : '[ ] ') . $nome . "\n";
    }
    exit(0);
}

$pendentes = array_filter($arquivos, fn ($a) => !in_array(basename($a), $aplicadas, true));
if (!$pendentes) {
    echo "Nenhuma migração pendente.\n";
    exit(0);
}

// Cópia de segurança automática antes de mudar a estrutura do banco. Se ela falhar, nada é aplicado.
if (!in_array('--sem-backup', $argv, true)) {
    echo "Fazendo a cópia de segurança antes das migrações... ";
    $pastaBackup = (env('BACKUP_DIR') !== null && trim((string)env('BACKUP_DIR')) !== '') ? rtrim(trim((string)env('BACKUP_DIR')), '/\\') : __DIR__ . '/../storage/backups';
    try {
        \Biblioteca\Services\BackupService::prepararPasta($pastaBackup);
        $arquivoBackup = $pastaBackup . DIRECTORY_SEPARATOR . 'biblioteca-dom-pedro-ii-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
        $fb = fopen($arquivoBackup, 'xb');
        if ($fb === false) {
            throw new RuntimeException('não foi possível criar o arquivo em ' . $pastaBackup);
        }
        $resumoBackup = (new \Biblioteca\Services\BackupService($pdo))->exportar($fb);
        fclose($fb);
        clearstatcache(true, $arquivoBackup);
        if (!\Biblioteca\Services\BackupService::terminaCompleto($arquivoBackup)) {
            @unlink($arquivoBackup);
            throw new RuntimeException('a cópia ficou incompleta');
        }
        // O registro na tabela "backups" só existe depois da migração 150.
        if ($pdo->query("SHOW TABLES LIKE 'backups'")->fetchColumn()) {
            $pdo->prepare("INSERT INTO backups (arquivo, origem, motivo, tamanho, sha256, tabelas, linhas, verificado) VALUES (?, 'migracao', ?, ?, ?, ?, ?, 1)")
                ->execute([basename($arquivoBackup), 'Antes de ' . count($pendentes) . ' migração(ões)', filesize($arquivoBackup), hash_file('sha256', $arquivoBackup), $resumoBackup['tabelas'], $resumoBackup['linhas']]);
        }
        echo "ok (" . basename($arquivoBackup) . ")\n";
    } catch (Throwable $e) {
        echo "ERRO\n";
        fwrite(STDERR, 'A cópia de segurança falhou (' . $e->getMessage() . "). Nenhuma migração foi aplicada.\n"
            . "Faça uma cópia pelo painel (Configurações → Manutenção) e rode de novo com --sem-backup.\n");
        exit(1);
    }
}

foreach ($pendentes as $arquivo) {
    $nome = basename($arquivo);
    echo "Aplicando $nome... ";
    try {
        $pulados = 0;
        foreach (migracao_comandos(file_get_contents($arquivo)) as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // Arquivo que parou no meio numa tentativa anterior: o que ele já criou é reconhecido
                // e pulado (só quando a estrutura existe por inteiro); qualquer outro erro interrompe.
                if (!migracao_ja_existe($pdo, $sql, (int)($e->errorInfo[1] ?? 0))) {
                    throw $e;
                }
                $pulados++;
            }
        }
        $pdo->prepare('INSERT INTO schema_migracoes (arquivo) VALUES (?)')->execute([$nome]);
        echo $pulados ? "ok ($pulados comando(s) já aplicado(s) antes, pulado(s))\n" : "ok\n";
    } catch (PDOException $e) {
        echo "ERRO\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

/**
 * Separa o arquivo em comandos. Blocos BEGIN ... END (procedures e triggers)
 * ficam inteiros, por isso não é preciso usar DELIMITER.
 */
function migracao_comandos(string $sql): array
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
 *   1359 gatilho já existe · 1826 chave estrangeira já existe
 */
function migracao_ja_existe(PDO $pdo, string $sql, int $codigo): bool
{
    $sql = preg_replace('/\s+/', ' ', trim($sql));
    if (in_array($codigo, [1050, 1061, 1359, 1826], true)) {
        return (bool)preg_match('/^(CREATE (TABLE|TRIGGER|(UNIQUE )?INDEX)|ALTER TABLE)\b/i', $sql);
    }
    if ($codigo !== 1060 || !preg_match('/^ALTER TABLE `?(\w+)`?/i', $sql, $t)) {
        return false;
    }
    preg_match_all('/\bADD (?:COLUMN )?(?!INDEX\b|KEY\b|UNIQUE\b|CONSTRAINT\b|FOREIGN\b|PRIMARY\b|FULLTEXT\b)`?(\w+)`?/i', $sql, $m);
    if (!$m[1] || preg_match('/\b(MODIFY|CHANGE|DROP|RENAME)\b/i', $sql)) {
        return false;
    }
    $existe = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach (array_unique($m[1]) as $coluna) {
        $existe->execute([$t[1], $coluna]);
        if (!(int)$existe->fetchColumn()) {
            return false; // falta uma coluna: não é só repetição, o erro precisa aparecer
        }
    }
    return true;
}
