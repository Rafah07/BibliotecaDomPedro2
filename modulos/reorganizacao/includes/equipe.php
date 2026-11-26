<?php
/**
 * Base da Central de Equipes (participantes temporários, sem conta).
 *
 * Sessão PHP própria (BDPREORG), separada do painel: guarda só o identificador aleatório da sessão
 * temporária (o banco guarda o hash) e o token CSRF. O nome não é credencial: entrar de novo, mesmo
 * com o mesmo nome, cria outra sessão sem as permissões anteriores. Nada do painel, de alunos ou de
 * empréstimos é carregado aqui.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/comum.php';
require_once __DIR__ . '/../../../admin/includes/icones.php';

use Biblioteca\Reorganizacao\Reorganizacao;

function reorg_equipe_cabecalhos(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

function reorg_equipe_sessao_php(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        $_SESSION ??= [];
    } else {
        session_name('BDPREORG');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => app_https(), 'httponly' => true, 'samesite' => 'Strict']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_start();
    }
    $_SESSION['reorg_csrf'] ??= bin2hex(random_bytes(32));
}

function reorg_equipe_csrf(): string
{
    reorg_equipe_sessao_php();
    return $_SESSION['reorg_csrf'];
}

function reorg_equipe_csrf_valido(): bool
{
    $enviado = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($enviado) && $enviado !== '' && hash_equals(reorg_equipe_csrf(), $enviado);
}

/** Sessão temporária ativa deste navegador, ou null. */
function reorg_equipe_sessao(): ?array
{
    reorg_equipe_sessao_php();
    $token = (string)($_SESSION['reorg_token'] ?? '');
    if ($token === '') {
        return null;
    }
    $s = reorg()->sessaoPorToken($token);
    if (!$s) {
        unset($_SESSION['reorg_token']);
        return null;
    }
    return $s;
}

function reorg_equipe_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function reorg_equipe_inicio(string $titulo, ?array $sessao = null, array $dados = []): void
{
    $atributos = '';
    foreach ($dados + ['api' => reorg_url('equipe/api.php'), 'csrf' => reorg_equipe_csrf(), 'intervalo' => (int)reorg_config('intervalo_sinal')] as $k => $v) {
        $atributos .= ' data-' . $k . '="' . e((string)$v) . '"';
    }
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#123d73"><title>' . e($titulo) . ' — Equipe de organização</title>'
        . '<link rel="stylesheet" href="' . e(url('admin/assets/css/admin.css')) . '"><link rel="stylesheet" href="' . e(url('admin/assets/css/acervo.css')) . '"><link rel="stylesheet" href="' . e(url('admin/assets/css/emprestimos.css')) . '">'
        . '<link rel="stylesheet" href="' . e(reorg_asset('reorg.css')) . '"></head><body class="adm-body reorg-equipe-body"' . ($sessao ? ' data-reorg-equipe' : '') . $atributos . '>'
        . '<header class="reorg-topo"><div class="reorg-topo__in"><div><strong>Biblioteca Dom Pedro II · Reorganização do acervo</strong>';
    if ($sessao) {
        echo '<small>' . e($sessao['nome']) . ' · sessão ' . e(Reorganizacao::rotuloSessao($sessao['id'])) . ($sessao['equipe'] ? ' · ' . e(Reorganizacao::EQUIPES[$sessao['equipe']]) : '') . '</small>';
    }
    echo '</div>';
    if ($sessao) {
        echo '<div class="reorg-topo__acoes"><span class="reorg-indicador" data-reorg-indicador>Conectado</span>'
            . '<button type="button" class="adm-button adm-button--sm" data-reorg-ajuda title="Atalhos e ajuda (Alt+H)">' . icon('circle-help') . ' Ajuda</button>'
            . '<button type="button" class="adm-button adm-button--sm" data-reorg-pausar title="Pausar e continuar depois (Alt+P)">' . icon('pause') . ' Pausar</button>'
            . '<button type="button" class="adm-button adm-button--sm adm-button--danger-text" data-reorg-sair>' . icon('log-out') . ' Sair</button></div>';
    }
    echo '</div></header><main class="reorg-main">';
    echo '<div class="reorg-notifs" data-reorg-notifs aria-live="polite"></div>';
}

function reorg_equipe_fim(): void
{
    echo '</main>';
    echo '<div class="reorg-modal" data-reorg-modal hidden><div class="reorg-modal__caixa" role="dialog" aria-modal="true"><div data-reorg-modal-conteudo></div>'
        . '<button type="button" class="adm-button adm-button--sm reorg-modal__fechar" data-reorg-fechar>Fechar</button></div></div>';
    echo '<script src="' . e(reorg_asset('registro.js')) . '"></script><script src="' . e(reorg_asset('equipe.js')) . '"></script></body></html>';
}

function reorg_equipe_redirecionar(string $destino): never
{
    header('Location: ' . $destino, true, 303);
    exit;
}

function reorg_equipe_flash(string $tipo, string $texto): void
{
    $_SESSION['reorg_flash'] = [$tipo, $texto];
}

function reorg_equipe_flash_html(): string
{
    $f = $_SESSION['reorg_flash'] ?? null;
    unset($_SESSION['reorg_flash']);
    if (!$f) {
        return '';
    }
    return '<div class="adm-notice adm-notice--' . ($f[0] === 'erro' ? 'red' : 'green') . '" role="status">' . icon($f[0] === 'erro' ? 'circle-alert' : 'circle-check') . '<div><strong>' . e($f[1]) . '</strong></div></div>';
}
