<?php
/**
 * Aplica as migrações MySQL em ordem (database/migrations/NNN_nome.sql).
 *
 * Uso:  php database/migrar.php               aplica as pendentes (antes, faz uma cópia de segurança
 *                                             conferida em storage/backups — ou BACKUP_DIR no .env)
 *       php database/migrar.php --status      lista o que já foi aplicado (e tentativas interrompidas)
 *       php database/migrar.php --verificar   só confere: conexão, versão, permissões e a estrutura real
 *                                             das pendentes (detecta migração parcialmente aplicada).
 *                                             Não muda nada.
 *       php database/migrar.php --sem-backup  aplica sem a cópia automática (use só se já fez uma)
 *
 * No XAMPP: C:\xampp\php\php.exe database/migrar.php
 *
 * Segurança:
 *  - Antes de começar: confere a conexão e as permissões do usuário do banco. Sem conexão ou sem
 *    permissão necessária, nada é feito e a mensagem diz o que falta.
 *  - Cada arquivo roda uma única vez e só é registrado em "schema_migracoes" quando TODOS os comandos
 *    terminam. Uma falha nunca marca a migração como concluída.
 *  - Cada tentativa e cada comando concluído ficam registrados em "schema_migracoes_execucoes" e
 *    "schema_migracoes_passos" (histórico, nunca apagado) e em storage/logs/migracoes.log.
 *  - No MySQL/MariaDB, comandos de estrutura (CREATE/ALTER/DROP) confirmam a transação
 *    automaticamente: uma migração interrompida pode ter mudado parte das tabelas. Ao rodar de novo,
 *    a estrutura REAL é conferida antes: tabelas, colunas, índices e gatilhos que o arquivo já tinha
 *    criado são reconhecidos e pulados (só quando existem por inteiro), e comandos de DADOS que já
 *    tinham terminado na tentativa interrompida não são repetidos.
 *  - "MySQL server has gone away" (2006) / conexão perdida (2013): a tentativa para, NÃO é marcada
 *    como concluída, e a mensagem explica como retomar. Nada é reparado ou apagado automaticamente.
 *  - As migrações só criam ou acrescentam estruturas: nenhuma apaga dados existentes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/database.php';

use Biblioteca\Support\Migracoes;

$argv = $argv ?? [];
$log = function (string $texto): void {
    $pasta = __DIR__ . '/../storage/logs';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0700, true);
        @file_put_contents($pasta . '/.htaccess', "Require all denied\n");
    }
    @file_put_contents($pasta . '/migracoes.log', date('Y-m-d H:i:s') . ' ' . $texto . "\n", FILE_APPEND | LOCK_EX);
};

if (!db_configurado()) {
    fwrite(STDERR, "Banco não configurado. Copie .env.example para .env e preencha DB_NAME, DB_USER e DB_PASSWORD.\n");
    exit(1);
}
try {
    $pdo = db();
    $pdo->query('SELECT 1')->fetchColumn(); // conexão realmente respondendo
} catch (Throwable $e) {
    fwrite(STDERR, "Não foi possível conectar ao banco de dados: " . ($e instanceof DbIndisponivel ? $e->getMessage() : 'o servidor não respondeu') . "\n"
        . "Confira se o MySQL/MariaDB está ligado no painel do XAMPP e os dados do .env. Nada foi alterado.\n");
    $log('ERRO conexão: ' . get_class($e));
    exit(1);
}
Migracoes::prepararSessaoLonga($pdo);

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migracoes (
    arquivo VARCHAR(190) PRIMARY KEY,
    aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$aplicadas = $pdo->query('SELECT arquivo FROM schema_migracoes')->fetchAll(PDO::FETCH_COLUMN);
// Prefixo numérico define a ordem (001_, 002_ ... ou 0500_): comparação natural.
$arquivos = glob(__DIR__ . '/migrations/[0-9]*_*.sql');
usort($arquivos, fn ($a, $b) => strnatcmp(basename($a), basename($b)));
$pendentes = array_values(array_filter($arquivos, fn ($a) => !in_array(basename($a), $aplicadas, true)));

// Tentativas anteriores que não terminaram (processo fechado, conexão perdida, erro).
$interrompidas = [];
$temHistorico = (bool)$pdo->query("SHOW TABLES LIKE 'schema_migracoes_execucoes'")->fetchColumn();
foreach (!$temHistorico ? [] : $pdo->query("SELECT e.* FROM schema_migracoes_execucoes e
                      JOIN (SELECT arquivo, MAX(id) AS id FROM schema_migracoes_execucoes GROUP BY arquivo) u ON u.id = e.id
                      WHERE e.situacao <> 'concluida'")->fetchAll(PDO::FETCH_ASSOC) as $ex) {
    if (!in_array($ex['arquivo'], $aplicadas, true)) {
        $interrompidas[$ex['arquivo']] = $ex;
    }
}

if (in_array('--status', $argv, true)) {
    foreach ($arquivos as $arquivo) {
        $nome = basename($arquivo);
        echo (in_array($nome, $aplicadas, true) ? '[x] ' : '[ ] ') . $nome
            . (isset($interrompidas[$nome]) ? '  (tentativa de ' . $interrompidas[$nome]['iniciado_em'] . ' não terminou: ' . $interrompidas[$nome]['comandos_executados'] . ' de ' . $interrompidas[$nome]['comandos_total'] . ' comandos)' : '') . "\n";
    }
    exit(0);
}

if (in_array('--verificar', $argv, true)) {
    $versao = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    echo 'Conexão com o banco: normal (' . (stripos($versao, 'mariadb') !== false ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*$/', '', $versao) . ").\n";
    $priv = Migracoes::privilegios($pdo);
    echo 'Permissões: ' . ($priv['verificado'] ? ($priv['faltando'] ? 'FALTANDO — ' : 'ok — ') : 'não confirmadas — ') . $priv['detalhe'] . "\n";
    echo 'Migrações pendentes: ' . count($pendentes) . "\n";
    foreach ($pendentes as $arquivo) {
        $nome = basename($arquivo);
        $situacao = Migracoes::situacaoPendente($pdo, (string)file_get_contents($arquivo));
        echo '  ' . $nome . ': ' . ['nao_iniciada' => 'não iniciada', 'parcial' => 'PARCIALMENTE APLICADA (será retomada com segurança)', 'estrutura_completa' => 'estrutura já criada, falta concluir'][$situacao]
            . (isset($interrompidas[$nome]) ? ' · tentativa interrompida em ' . $interrompidas[$nome]['iniciado_em'] : '') . "\n";
    }
    exit(0);
}

if (!$pendentes) {
    echo "Nenhuma migração pendente.\n";
    exit(0);
}

// Permissões necessárias: sem elas a migração pararia no meio (com parte da estrutura já criada).
$priv = Migracoes::privilegios($pdo);
if ($priv['verificado'] && $priv['faltando']) {
    fwrite(STDERR, 'O usuário do banco não tem permissões necessárias para as migrações: ' . implode(', ', $priv['faltando']) . ".\n"
        . "Nada foi alterado. Conceda as permissões (phpMyAdmin → Contas de usuário) ou use o usuário administrador do banco.\n");
    $log('ERRO permissões faltando: ' . implode(', ', $priv['faltando']));
    exit(1);
}
if (!$priv['verificado']) {
    echo "Aviso: não foi possível confirmar as permissões do usuário do banco ({$priv['detalhe']}). Continuando.\n";
}

// Histórico das tentativas (criado só quando há o que aplicar; --status e --verificar não mudam nada)
// Histórico das tentativas (inclusive as que falharam) e dos comandos concluídos em cada uma.
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migracoes_execucoes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    arquivo VARCHAR(190) NOT NULL,
    hash_arquivo CHAR(40) NOT NULL,
    situacao ENUM('em_andamento', 'concluida', 'falhou', 'interrompida') NOT NULL DEFAULT 'em_andamento',
    comandos_total INT NOT NULL DEFAULT 0,
    comandos_executados INT NOT NULL DEFAULT 0,
    comandos_pulados INT NOT NULL DEFAULT 0,
    erro VARCHAR(1000) NULL,
    backup_arquivo VARCHAR(190) NULL,
    iniciado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em DATETIME NULL,
    KEY schema_migracoes_execucoes_arquivo_idx (arquivo, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migracoes_passos (
    execucao_id BIGINT NOT NULL,
    indice INT NOT NULL,
    hash_comando CHAR(40) NOT NULL,
    resultado ENUM('executado', 'pulado') NOT NULL,
    concluido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (execucao_id, indice),
    KEY schema_migracoes_passos_hash_idx (hash_comando)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Retomada: mostra o que já existe no banco real antes de continuar.
foreach ($pendentes as $arquivo) {
    $nome = basename($arquivo);
    $situacao = Migracoes::situacaoPendente($pdo, (string)file_get_contents($arquivo));
    if ($situacao !== 'nao_iniciada' || isset($interrompidas[$nome])) {
        echo "Atenção: $nome foi iniciada antes e não terminou"
            . ($situacao === 'parcial' ? ' (parte da estrutura já existe no banco)' : ($situacao === 'estrutura_completa' ? ' (a estrutura já existe; faltam comandos)' : ''))
            . ". A estrutura real será conferida e o que já existe será pulado.\n";
    }
}

// Cópia de segurança automática antes de mudar a estrutura do banco. Se ela falhar, nada é aplicado.
$arquivoBackup = null;
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
        try {
            $resumoBackup = (new \Biblioteca\Services\BackupService($pdo))->exportar($fb);
        } finally {
            fclose($fb);
        }
        clearstatcache(true, $arquivoBackup);
        if (!\Biblioteca\Services\BackupService::terminaCompleto($arquivoBackup)) {
            throw new RuntimeException('a cópia ficou incompleta');
        }
        // O registro na tabela "backups" só existe depois da migração 150.
        if ($pdo->query("SHOW TABLES LIKE 'backups'")->fetchColumn()) {
            $pdo->prepare("INSERT INTO backups (arquivo, origem, motivo, tamanho, sha256, tabelas, linhas, verificado) VALUES (?, 'migracao', ?, ?, ?, ?, ?, 1)")
                ->execute([basename($arquivoBackup), 'Antes de ' . count($pendentes) . ' migração(ões)', filesize($arquivoBackup), hash_file('sha256', $arquivoBackup), $resumoBackup['tabelas'], $resumoBackup['linhas']]);
        }
        echo "ok (" . basename($arquivoBackup) . ")\n";
        $log('Backup antes das migrações: ' . basename($arquivoBackup));
    } catch (Throwable $e) {
        if ($arquivoBackup && is_file($arquivoBackup)) {
            @unlink($arquivoBackup); // arquivo incompleto não pode parecer uma cópia válida
        }
        echo "ERRO\n";
        $motivo = Migracoes::conexaoPerdida($e)
            ? 'a conexão com o banco caiu durante a cópia ("MySQL server has gone away"). Confira se o MariaDB continua ligado no XAMPP e o arquivo mysql_error.log; se a cópia é muito grande, aumente max_allowed_packet no my.ini'
            : $e->getMessage();
        fwrite(STDERR, 'A cópia de segurança falhou (' . $motivo . "). Nenhuma migração foi aplicada.\n"
            . "Faça uma cópia pelo painel (Configurações → Manutenção) e rode de novo com --sem-backup.\n");
        $log('ERRO backup: ' . \Biblioteca\Support\RegistroErros::limpar($e->getMessage()));
        exit(1);
    }
}

$registrarExecucao = function (string $sql, array $p) use ($pdo, $log) {
    try {
        $pdo->prepare($sql)->execute($p);
    } catch (Throwable $e) {
        $log('Aviso: histórico da execução não gravado (' . get_class($e) . ')');
    }
};

foreach ($pendentes as $arquivo) {
    $nome = basename($arquivo);
    $conteudo = (string)file_get_contents($arquivo);
    $comandos = Migracoes::comandos($conteudo);
    echo "Aplicando $nome... ";
    $log("Início $nome (" . count($comandos) . ' comandos)');

    // Comandos de DADOS que já terminaram numa tentativa interrompida deste mesmo arquivo: não repetir.
    $dadosConcluidos = [];
    $passos = $pdo->prepare("SELECT p.hash_comando FROM schema_migracoes_passos p JOIN schema_migracoes_execucoes e ON e.id = p.execucao_id
                             WHERE e.arquivo = ? AND e.situacao <> 'concluida' AND p.resultado = 'executado'");
    $passos->execute([$nome]);
    foreach ($passos->fetchAll(PDO::FETCH_COLUMN) as $h) {
        $dadosConcluidos[$h] = true;
    }

    $pdo->prepare('INSERT INTO schema_migracoes_execucoes (arquivo, hash_arquivo, comandos_total, backup_arquivo) VALUES (?, ?, ?, ?)')
        ->execute([$nome, sha1($conteudo), count($comandos), $arquivoBackup ? basename($arquivoBackup) : null]);
    $execucaoId = (int)$pdo->lastInsertId();
    $pulados = 0;
    $executados = 0;
    $indice = 0;
    try {
        foreach ($comandos as $indice => $sql) {
            $hash = sha1(preg_replace('/\s+/', ' ', $sql));
            if (Migracoes::ehDados($sql) && isset($dadosConcluidos[$hash])) {
                $pulados++;
                $registrarExecucao('INSERT INTO schema_migracoes_passos (execucao_id, indice, hash_comando, resultado) VALUES (?, ?, ?, ?)', [$execucaoId, $indice, $hash, 'pulado']);
                continue;
            }
            try {
                $pdo->exec($sql);
                $executados++;
                $registrarExecucao('INSERT INTO schema_migracoes_passos (execucao_id, indice, hash_comando, resultado) VALUES (?, ?, ?, ?)', [$execucaoId, $indice, $hash, 'executado']);
            } catch (PDOException $e) {
                if (Migracoes::conexaoPerdida($e)) {
                    throw $e;
                }
                // Arquivo que parou no meio numa tentativa anterior: o que ele já criou é reconhecido
                // e pulado (só quando a estrutura existe por inteiro); qualquer outro erro interrompe.
                if (!Migracoes::jaExiste($pdo, $sql, (int)($e->errorInfo[1] ?? 0))) {
                    throw $e;
                }
                $pulados++;
                $registrarExecucao('INSERT INTO schema_migracoes_passos (execucao_id, indice, hash_comando, resultado) VALUES (?, ?, ?, ?)', [$execucaoId, $indice, $hash, 'pulado']);
            }
        }
        $pdo->prepare('INSERT INTO schema_migracoes (arquivo) VALUES (?)')->execute([$nome]);
        $registrarExecucao("UPDATE schema_migracoes_execucoes SET situacao = 'concluida', comandos_executados = ?, comandos_pulados = ?, concluido_em = NOW() WHERE id = ?", [$executados, $pulados, $execucaoId]);
        $log("Concluída $nome ($executados executado(s), $pulados pulado(s))");
        echo $pulados ? "ok ($pulados comando(s) já aplicado(s) antes, pulado(s))\n" : "ok\n";
    } catch (PDOException $e) {
        echo "ERRO\n";
        $perdida = Migracoes::conexaoPerdida($e);
        $resumoErro = \Biblioteca\Support\RegistroErros::limpar($e->getMessage());
        $log("ERRO $nome no comando " . ($indice + 1) . ' de ' . count($comandos) . ': ' . $resumoErro);
        if ($perdida) {
            // Sem conexão não dá para gravar o histórico agora: reconecta só para registrar a interrupção.
            try {
                $c = app_config();
                $novo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']), $c['username'], (string)$c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $novo->prepare("UPDATE schema_migracoes_execucoes SET situacao = 'interrompida', comandos_executados = ?, comandos_pulados = ?, erro = ? WHERE id = ?")
                    ->execute([$executados, $pulados, mb_substr('Conexão perdida no comando ' . ($indice + 1) . ': ' . $resumoErro, 0, 1000), $execucaoId]);
            } catch (Throwable) {
            }
            fwrite(STDERR, 'A conexão com o banco foi interrompida no comando ' . ($indice + 1) . ' de ' . count($comandos) . " ($nome).\n"
                . "A migração NÃO foi marcada como concluída. O que já foi criado continua no banco (CREATE/ALTER não são desfeitos).\n"
                . "Confira se o MariaDB continua ligado no XAMPP (e o arquivo mysql\\data\\mysql_error.log) e rode o comando de novo:\n"
                . "a estrutura real será conferida e só o que falta será aplicado. Para conferir antes: php database/migrar.php --verificar\n");
        } else {
            $registrarExecucao("UPDATE schema_migracoes_execucoes SET situacao = 'falhou', comandos_executados = ?, comandos_pulados = ?, erro = ? WHERE id = ?",
                [$executados, $pulados, mb_substr('Comando ' . ($indice + 1) . ': ' . ($e->errorInfo[1] ?? '') . ' ' . $resumoErro, 0, 1000), $execucaoId]);
            fwrite(STDERR, $e->getMessage() . "\n");
            fwrite(STDERR, "(comando " . ($indice + 1) . ' de ' . count($comandos) . " de $nome; a migração NÃO foi marcada como concluída)\n");
        }
        exit(1);
    }
}

/** Mantidas para compatibilidade com scripts antigos. */
function migracao_comandos(string $sql): array
{
    return Migracoes::comandos($sql);
}

function migracao_ja_existe(PDO $pdo, string $sql, int $codigo): bool
{
    return Migracoes::jaExiste($pdo, $sql, $codigo);
}
