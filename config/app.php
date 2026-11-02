<?php
/**
 * Inicialização comum do site público e do painel.
 *
 * - Lê o arquivo .env da raiz (variáveis reais do servidor têm prioridade).
 * - Registra o carregamento automático das classes de src/ (namespace Biblioteca\).
 * - Gera endereços a partir da raiz da aplicação, para que o sistema funcione tanto
 *   na raiz do domínio (http://escola.exemplo/) quanto em uma subpasta
 *   (http://localhost/BibliotecaDomPedro2/).
 *
 * Uso: require_once __DIR__ . '/../config/app.php'; (sempre com caminho baseado em __DIR__)
 */

const APP_RAIZ = __DIR__ . '/..';

/** Valor de uma variável de ambiente (servidor primeiro, depois o arquivo .env). */
function env(string $chave, ?string $padrao = null): ?string
{
    static $arquivo = null;
    if ($arquivo === null) {
        $arquivo = [];
        $caminho = APP_RAIZ . '/.env';
        if (is_file($caminho) && is_readable($caminho)) {
            foreach (file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
                $linha = trim($linha);
                if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
                    continue;
                }
                [$nome, $valor] = array_map('trim', explode('=', $linha, 2));
                if (preg_match('/^([\'"])(.*)\1$/', $valor, $m)) {
                    $valor = $m[2];
                }
                $arquivo[$nome] = $valor;
            }
        }
    }
    $valor = getenv($chave);
    if ($valor === false || $valor === '') {
        $valor = $arquivo[$chave] ?? null;
    }
    return ($valor === null || $valor === '') ? $padrao : $valor;
}

function env_bool(string $chave, bool $padrao): bool
{
    $valor = env($chave);
    return $valor === null ? $padrao : filter_var($valor, FILTER_VALIDATE_BOOL);
}

/**
 * O site está sendo acessado por HTTPS? Detecta a conexão direta (Apache/IIS com certificado) e
 * aceita APP_HTTPS=true quando um proxy ou painel da hospedagem termina o HTTPS antes do PHP.
 * Cabeçalhos como X-Forwarded-Proto não são usados: qualquer visitante poderia enviá-los.
 */
function app_https(): bool
{
    if (env_bool('APP_HTTPS', false)) {
        return true;
    }
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** "development" (padrão) ou "production". */
function app_ambiente(): string
{
    return env('APP_ENV', 'development') === 'production' ? 'production' : 'development';
}

// Fuso horário da escola: datas de empréstimo, prazos e atrasos (e o fuso das conexões MySQL, em db()).
date_default_timezone_set(in_array(env('APP_TIMEZONE', ''), DateTimeZone::listIdentifiers(), true) ? env('APP_TIMEZONE') : 'America/Sao_Paulo');

// Em produção, erros vão só para o log do servidor (nunca para a tela, onde poderiam expor caminhos ou SQL).
if (app_ambiente() === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    if (PHP_SAPI !== 'cli') {
        // Erro inesperado: o detalhe vai para o log; o visitante vê só uma página genérica (sem caminhos nem SQL).
        set_exception_handler(function (Throwable $e): void {
            error_log('[biblioteca] Erro não tratado: ' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . ' — ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
                header('Cache-Control: no-store');
            }
            echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Erro | Biblioteca Dom Pedro II</title><body style="font-family:system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem">'
                . '<h1>Não foi possível abrir esta página</h1><p>Ocorreu um erro inesperado. Tente novamente em alguns instantes. '
                . 'Se o problema continuar, avise a equipe da biblioteca.</p></body></html>';
        });
        // HTTPS em produção: o navegador passa a usar sempre HTTPS neste domínio (HSTS).
        if (app_https() && !headers_sent()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}

// Carregamento das classes: Composer quando instalado; senão, um carregador simples para src/.
(function () {
    if (is_file(APP_RAIZ . '/vendor/autoload.php')) {
        require_once APP_RAIZ . '/vendor/autoload.php';
        return;
    }
    spl_autoload_register(function (string $classe) {
        if (str_starts_with($classe, 'Biblioteca\\')) {
            $arquivo = APP_RAIZ . '/src/' . str_replace('\\', '/', substr($classe, 11)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });
})();

/**
 * Caminho da raiz da aplicação no navegador, sempre com barra no fim ("/" ou "/BibliotecaDomPedro2/").
 * Pode ser fixado com APP_BASE_PATH no .env; caso contrário, é descoberto pelo script em execução.
 */
function app_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $fixo = env('APP_BASE_PATH');
    if ($fixo !== null) {
        return $base = '/' . trim($fixo, '/') . (trim($fixo, '/') === '' ? '' : '/');
    }
    $base = '/';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $arquivo = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $raiz = realpath(APP_RAIZ);
    if ($script !== '' && $arquivo && $raiz && str_starts_with($arquivo, $raiz . DIRECTORY_SEPARATOR)) {
        // Ex.: script "/BibliotecaDomPedro2/biblioteca_php/catalogo.php", arquivo relativo "biblioteca_php/catalogo.php".
        $relativo = str_replace('\\', '/', substr($arquivo, strlen($raiz) + 1));
        if (str_ends_with($script, '/' . $relativo)) {
            $base = rtrim(substr($script, 0, -strlen($relativo)), '/') . '/';
        }
    }
    return $base;
}

/** Endereço de uma página ou arquivo a partir da raiz: url('biblioteca_php/catalogo.php'). */
function url(string $caminho = ''): string
{
    if (preg_match('#^(https?:)?//#', $caminho)) {
        return $caminho;
    }
    return app_base() . ltrim($caminho, '/');
}

/** Endereço de um arquivo em assets/, com versão para evitar cache antigo: asset('css/style.css'). */
function asset(string $caminho): string
{
    $relativo = 'assets/' . ltrim($caminho, '/');
    $arquivo = APP_RAIZ . '/' . $relativo;
    $versao = is_file($arquivo) ? '?v=' . filemtime($arquivo) : '';
    return url($relativo) . $versao;
}

/** Atalho para escapar texto exibido no HTML. */
function e(mixed $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/database.php';
