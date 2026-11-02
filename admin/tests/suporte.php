<?php
/**
 * Apoio comum aos testes da Etapa 7 (auditoria-seguranca-teste.php e fluxo-integracao-teste.php).
 *
 * - teste_banco_temporario(): cria um banco "bdp_teste_<prefixo>_XXXX" no servidor do .env,
 *   aplica as migrações SÓ nele e o apaga no final. O banco do .env (DB_NAME) nunca é usado.
 * - teste_servidor(): sobe o servidor PHP embutido (php -S) apontando para o banco temporário.
 * - TesteNavegador: cliente HTTP mínimo com cookies, para testar páginas como no navegador.
 *
 * Só funciona pela linha de comando (a pasta admin/tests/ também é bloqueada no .htaccess).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/app.php';

$GLOBALS['teste_falhas'] = 0;
$GLOBALS['teste_total'] = 0;

function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    $GLOBALS['teste_total']++;
    if (!$ok) {
        $GLOBALS['teste_falhas']++;
    }
    echo ($ok ? '  ok  ' : '  FALHOU  ') . $nome . ($ok || $detalhe === '' ? '' : " — $detalhe") . "\n";
}

function teste_resultado(): never
{
    $total = $GLOBALS['teste_total'];
    $falhas = $GLOBALS['teste_falhas'];
    echo "\n" . ($total - $falhas) . " de $total verificações passaram.\n";
    exit($falhas ? 1 : 0);
}

/**
 * Cria o banco temporário, aplica as migrações e devolve [PDO raiz, nome do banco, variáveis de ambiente do teste].
 * @param array<string, string> $envExtra variáveis extras (ex.: APP_ENV)
 */
function teste_banco_temporario(string $prefixo, array $envExtra = []): array
{
    $host = env('DB_HOST', '127.0.0.1');
    $porta = (int)env('DB_PORT', '3306');
    $usuario = getenv('TESTE_DB_USER') ?: env('DB_USER');
    $senha = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
    if (!$usuario) {
        echo "Testes não executados: configure DB_HOST/DB_USER no .env.\n";
        exit(0);
    }
    $banco = 'bdp_teste_' . $prefixo . '_' . bin2hex(random_bytes(4));
    $raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $GLOBALS['teste_bancos'][] = $banco;
    $GLOBALS['teste_raiz'] = $raiz;
    register_shutdown_function(function () use ($raiz) {
        foreach ($GLOBALS['teste_servidores'] ?? [] as $s) {
            if (is_resource($s)) {
                proc_terminate($s);
            }
        }
        foreach ($GLOBALS['teste_temporarios'] ?? [] as $f) {
            @unlink($f);
        }
        // Uma transação aberta (teste interrompido no meio) seguraria o DROP DATABASE para sempre.
        try {
            $pdo = db();
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
        }
        foreach (array_reverse($GLOBALS['teste_bancos'] ?? []) as $b) {
            $raiz->exec("DROP DATABASE IF EXISTS `$b`");
            echo "Banco temporário $b apagado.\n";
        }
    });
    echo "Banco temporário: $banco (o banco do .env não é usado)\n";
    $env = array_merge([
        'DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_NAME' => $banco, 'DB_USER' => $usuario, 'DB_PASSWORD' => $senha,
        'APP_BASE_PATH' => '/', 'APP_ENV' => 'production', 'APP_TIMEZONE' => 'America/Sao_Paulo',
    ], $envExtra);
    foreach ($env as $k => $v) {
        putenv("$k=$v");
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../database/migrar.php') . ' 2>&1', $saida, $codigo);
    if ($codigo !== 0) {
        fwrite(STDERR, "As migrações falharam:\n" . implode("\n", $saida) . "\n");
        exit(1);
    }
    if (db()->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
        fwrite(STDERR, "Conexão inesperada: o teste só roda no banco temporário.\n");
        exit(1);
    }
    return [$raiz, $banco, $env];
}

/** Sobe o servidor PHP embutido na raiz do projeto e devolve o endereço base ("http://127.0.0.1:PORTA/"). */
function teste_servidor(array $env): string
{
    $porta = 9100 + random_int(0, 800);
    $log = sys_get_temp_dir() . '/bdp-teste-servidor-' . $porta . '.log';
    $GLOBALS['teste_temporarios'][] = $log;
    $s = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, array_merge(getenv(), $env));
    $GLOBALS['teste_servidores'][] = $s;
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        if (@fsockopen('127.0.0.1', $porta)) {
            break;
        }
    }
    return "http://127.0.0.1:$porta/";
}

/** Navegador mínimo com cookies. ir() devolve [status, corpo, location, content-type, content-disposition, cabeçalhos]. */
final class TesteNavegador
{
    public array $cookies = [];
    public array $setCookies = [];

    public function __construct(private string $base)
    {
    }

    public function ir(string $pagina, ?array $post = null, array $cabecalhos = []): array
    {
        $extra = '';
        foreach ($cabecalhos as $k => $v) {
            $extra .= "$k: $v\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: " . http_build_query($this->cookies, '', '; ') . "\r\n" . $extra,
            'content' => $post === null ? '' : http_build_query($post),
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 120,
        ]]);
        $corpo = (string)@file_get_contents($this->base . $pagina, false, $ctx);
        $status = 0;
        $local = $tipo = $disp = '';
        $todos = $http_response_header ?? [];
        foreach ($todos as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int)$m[1];
            } elseif (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) {
                $this->setCookies[] = $h;
                if ($m[2] === '' || $m[2] === 'deleted') {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            } elseif (preg_match('/^Location: (.*)$/i', $h, $m)) {
                $local = trim($m[1]);
            } elseif (preg_match('/^Content-Type: (.*)$/i', $h, $m)) {
                $tipo = trim($m[1]);
            } elseif (preg_match('/^Content-Disposition: (.*)$/i', $h, $m)) {
                $disp = trim($m[1]);
            }
        }
        return [$status, $corpo, $local, $tipo, $disp, $todos];
    }

    /** POST multipart/form-data com arquivos: $arquivos = ['campo' => [nome, conteúdo, tipo]]. */
    public function enviar(string $pagina, array $campos, array $arquivos, array $cabecalhos = []): array
    {
        $limite = '----bdp' . bin2hex(random_bytes(8));
        $corpo = '';
        foreach ($campos as $k => $v) {
            $corpo .= "--$limite\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        foreach ($arquivos as $k => [$nome, $conteudo, $tipo]) {
            $corpo .= "--$limite\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"$nome\"\r\nContent-Type: $tipo\r\n\r\n$conteudo\r\n";
        }
        $corpo .= "--$limite--\r\n";
        $extra = '';
        foreach ($cabecalhos as $k => $v) {
            $extra .= "$k: $v\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: multipart/form-data; boundary=$limite\r\nCookie: " . http_build_query($this->cookies, '', '; ') . "\r\n" . $extra,
            'content' => $corpo, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 120,
        ]]);
        $resposta = (string)@file_get_contents($this->base . $pagina, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        return [$status, $resposta];
    }

    public function csrf(string $pagina): string
    {
        return preg_match('/name="csrf" value="([a-f0-9]+)"/', $this->ir($pagina)[1], $m) ? $m[1] : '';
    }

    /** Entra no painel e devolve o status do dashboard depois do login (200 = entrou). */
    public function entrar(string $email, string $senha): int
    {
        $t = $this->csrf('admin/login.php');
        $this->ir('admin/login.php', ['csrf' => $t, 'email' => $email, 'senha' => $senha]);
        return $this->ir('admin/index.php')[0];
    }

    public function sair(): void
    {
        $t = $this->csrf('admin/perfil.php');
        $this->ir('admin/logout.php', ['csrf' => $t]);
    }
}

/** Página sem mensagens técnicas do PHP (avisos, erros fatais, caminhos de arquivos, SQL). */
function teste_sem_erro_tecnico(string $html): bool
{
    return !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|Stack trace|SQLSTATE|PDOException|\/home\/|C:\\\\xampp)/', $html);
}
