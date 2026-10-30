<?php
/**
 * Aplica as migrações MySQL em ordem (database/migrations/NNN_nome.sql).
 *
 * Uso:  php database/migrar.php           aplica as pendentes
 *       php database/migrar.php --status  lista o que já foi aplicado
 *
 * Cada arquivo roda uma única vez e fica registrado em "schema_migracoes".
 * As migrações só criam ou acrescentam estruturas: nenhuma apaga dados existentes.
 * Observação: no MySQL, comandos de estrutura (CREATE/ALTER) confirmam a transação
 * automaticamente; se um arquivo falhar no meio, corrija o problema e consulte
 * o estado do banco antes de rodar de novo.
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

foreach ($pendentes as $arquivo) {
    $nome = basename($arquivo);
    echo "Aplicando $nome... ";
    try {
        foreach (migracao_comandos(file_get_contents($arquivo)) as $sql) {
            $pdo->exec($sql);
        }
        $pdo->prepare('INSERT INTO schema_migracoes (arquivo) VALUES (?)')->execute([$nome]);
        echo "ok\n";
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
