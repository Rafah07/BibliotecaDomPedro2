<?php
/**
 * Conexão com o MySQL (PDO).
 *
 * A configuração vem de admin/config/database.php ou das variáveis de ambiente BDP_DB_*.
 * Sem configuração, db() devolve null e os módulos usam o modo demonstrativo.
 * Com configuração mas sem conexão, db() lança DbIndisponivel: o painel mostra um
 * aviso em vez de cair silenciosamente nos dados fictícios.
 */

final class DbIndisponivel extends RuntimeException
{
}

/** Configuração completa (banco + fontes), ou [] quando não existe. */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $arquivo = __DIR__ . '/../config/database.php';
    $config = is_file($arquivo) ? (array)require $arquivo : [];
    $env = [
        'host' => getenv('BDP_DB_HOST'),
        'port' => getenv('BDP_DB_PORT'),
        'database' => getenv('BDP_DB_NAME'),
        'username' => getenv('BDP_DB_USER'),
        'password' => getenv('BDP_DB_PASS'),
    ];
    foreach ($env as $chave => $valor) {
        if ($valor !== false && $valor !== '') {
            $config[$chave] = $valor;
        }
    }
    return $config;
}

function db_configurado(): bool
{
    $c = app_config();
    return !empty($c['database']) && !empty($c['username']);
}

/** PDO conectado, ou null no modo demonstrativo. */
function db(): ?PDO
{
    static $pdo = null;
    if ($pdo !== null || !db_configurado()) {
        return $pdo;
    }
    $c = app_config();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? '127.0.0.1', (int)($c['port'] ?? 3306), $c['database']);
    try {
        $pdo = new PDO($dsn, $c['username'], (string)($c['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (PDOException $e) {
        error_log('[biblioteca] Falha ao conectar ao MySQL: ' . $e->getMessage());
        throw new DbIndisponivel('Não foi possível conectar ao banco de dados. Verifique a configuração e tente novamente.');
    }
    return $pdo;
}

/** 'mysql' quando o banco está configurado; 'demo' caso contrário. */
function db_modo(): string
{
    return db_configurado() ? 'mysql' : 'demo';
}

/**
 * Executa $fn dentro de uma transação. Desfaz tudo se ocorrer qualquer erro.
 * @template T
 * @param callable(PDO): T $fn
 * @return T
 */
function db_transacao(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $resultado = $fn($pdo);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Atalho para consultas preparadas. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
