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

/** "development" (padrão) ou "production". */
function app_ambiente(): string
{
    return env('APP_ENV', 'development') === 'production' ? 'production' : 'development';
}

// Em produção, erros vão só para o log do servidor (nunca para a tela, onde poderiam expor caminhos ou SQL).
if (app_ambiente() === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
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
