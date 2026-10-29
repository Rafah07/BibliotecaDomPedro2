<?php
/**
 * Conexão com o MySQL (PDO), usada pelo site público e pelo painel.
 *
 * A configuração vem das variáveis de ambiente (ou do arquivo .env na raiz):
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD. Veja .env.example.
 *
 * - Sem configuração, db() devolve null e o sistema usa o modo demonstrativo.
 * - Com configuração mas sem conexão, db() lança DbIndisponivel com uma mensagem
 *   genérica; o detalhe técnico vai só para o log do servidor (error_log).
 * - Erros de SQL viram exceções (PDO::ERRMODE_EXCEPTION) e as consultas usam
 *   parâmetros preparados (db_query).
 */
require_once __DIR__ . '/app.php';

final class DbIndisponivel extends RuntimeException
{
}

/** Configuração completa do banco e dos módulos, montada a partir do ambiente. */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int)env('DB_PORT', '3306'),
        'database' => env('DB_NAME'),
        'username' => env('DB_USER'),
        'password' => env('DB_PASSWORD', ''),
        // Fontes bibliográficas consultadas pelo módulo de classificação CDD.
        'fontes_cdd' => [
            'contato' => env('CDD_CONTATO', ''),
            'intervalo_segundos' => (float)env('CDD_INTERVALO_SEGUNDOS', '1.5'),
            'loc' => env_bool('CDD_FONTE_LOC', true),
            'openlibrary' => env_bool('CDD_FONTE_OPENLIBRARY', true),
            'webdewey' => env_bool('CDD_FONTE_WEBDEWEY', false),
        ],
    ];
    if (env('STORAGE_IMPORTACOES') !== null) {
        $config['armazenamento_importacoes'] = env('STORAGE_IMPORTACOES');
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
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']);
    try {
        $pdo = new PDO($dsn, $c['username'], (string)$c['password'], [
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
