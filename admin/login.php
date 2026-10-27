<?php
require_once __DIR__ . '/includes/icones.php';
require_once __DIR__ . '/includes/menu.php';
require_once __DIR__ . '/includes/auth.php';

/** Só aceita voltar para páginas dentro de admin/ (evita redirecionamento para outro site). */
function login_destino(string $voltar): string
{
    $base = admin_base();
    if ($voltar !== '' && str_starts_with($voltar, $base) && !str_contains($voltar, '//') && !str_contains($voltar, '\\')) {
        return $voltar;
    }
    return admin_url('classificacao');
}

$voltar = is_string($_POST['voltar'] ?? $_GET['voltar'] ?? null) ? ($_POST['voltar'] ?? $_GET['voltar']) : '';
$erro = null;
$email = '';
try {
    if (auth_usuario()) {
        header('Location: ' . login_destino($voltar));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        if (!csrf_valido()) {
            $erro = 'A página ficou aberta por muito tempo. Tente entrar novamente.';
        } else {
            $erro = auth_login($email, is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '');
            if ($erro === null) {
                header('Location: ' . login_destino($voltar));
                exit;
            }
        }
    }
} catch (DbIndisponivel $e) {
    $erro = $e->getMessage();
} catch (Throwable $e) {
    error_log('[biblioteca] Erro no login: ' . $e->getMessage());
    $erro = 'Não foi possível entrar agora. Tente novamente em instantes.';
}
$demo = db_modo() === 'demo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#143477">
    <meta name="robots" content="noindex">
    <title>Entrar | Painel — Biblioteca Dom Pedro II</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(admin_asset('assets/css/admin.css?v=20261028')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(admin_asset('assets/css/acervo.css?v=20261027')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(admin_asset('assets/css/classificacao.css?v=20261027')) ?>">
</head>
<body class="adm-body adm-login">
<main class="adm-login__box">
    <div class="adm-login__brand">
        <span class="adm-brand__logo"><?= icon('library-big') ?></span>
        <div>
            <strong>Biblioteca Dom Pedro II</strong>
            <small>Painel administrativo</small>
        </div>
    </div>
    <section class="adm-card adm-panel">
        <h1>Entrar no painel</h1>
        <p class="adm-login__lead">Use sua conta de administrador para acessar a classificação do acervo.</p>
        <?php if ($erro): ?>
            <div class="adm-notice adm-notice--red" role="alert"><?= icon('circle-alert') ?><div><strong><?= htmlspecialchars($erro) ?></strong></div></div>
        <?php endif; ?>
        <form method="post" class="adm-login__form" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="voltar" value="<?= htmlspecialchars($voltar) ?>">
            <label class="adm-field">
                <span class="adm-field__label">E-mail</span>
                <input class="adm-input" type="email" name="email" required autocomplete="username" value="<?= htmlspecialchars($email) ?>" autofocus>
            </label>
            <label class="adm-field">
                <span class="adm-field__label">Senha</span>
                <input class="adm-input" type="password" name="senha" required autocomplete="current-password">
            </label>
            <button type="submit" class="adm-button adm-button--primary adm-button--block"><?= icon('log-in') ?> Entrar</button>
        </form>
        <?php if ($demo): ?>
            <div class="adm-notice adm-notice--blue adm-notice--compact">
                <?= icon('info') ?>
                <div>
                    <strong>Modo demonstrativo (banco MySQL não configurado).</strong>
                    <p>Contas de teste, senha <code>demo</code>: <code>bibliotecaria@demo</code> (pode aprovar) e <code>auxiliar@demo</code> (só sugere).</p>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <a class="adm-link" href="<?= htmlspecialchars(admin_url('dashboard')) ?>"><?= icon('arrow-left') ?> Voltar ao painel</a>
</main>
</body>
</html>
