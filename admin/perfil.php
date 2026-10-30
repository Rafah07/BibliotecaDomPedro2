<?php
/** Meu perfil: dados da própria conta e troca de senha (exige a senha atual). */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/administradores.php';

$usuario = auth_exigir('painel.ver');
$mysql = db_modo() === 'mysql';
$conta = $mysql ? administradores_repo()->buscar($usuario['id']) : null;
$erro = null;

if ($mysql && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $hashAtual = administradores_repo()->senhaHash($usuario['id']);
    $nova = (string)($_POST['senha'] ?? '');
    if (!$hashAtual || !password_verify((string)($_POST['senha_atual'] ?? ''), $hashAtual)) {
        $erro = 'A senha atual está incorreta.';
    } elseif (password_verify($nova, $hashAtual)) {
        $erro = 'A nova senha precisa ser diferente da atual.';
    } else {
        $erro = auth_senha_problema($nova, (string)($_POST['senha_confirmacao'] ?? ''));
    }
    if (!$erro) {
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        administradores_repo()->definirSenha($usuario['id'], $hash);
        auth_renovar_marca_senha($hash);
        registrar_acao('usuario.trocar_senha', 'administrador', $usuario['id']);
        admin_flash('sucesso', 'Senha alterada.', 'Use a nova senha no próximo acesso. Outras sessões abertas com a senha antiga foram encerradas.');
        admin_redirecionar(admin_url('perfil'));
    }
}

admin_page_start('perfil', 'Meu perfil', 'Meu perfil', 'Sua conta de acesso ao painel', ['assets/css/acervo.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Meu perfil']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Meu perfil</h1>
        <p>Seus dados de acesso. Para mudar nome, e-mail ou perfil, fale com um administrador.</p>
    </div>
</section>

<?= admin_flash_html() ?>

<div class="adm-form-layout">
    <div class="adm-form-layout__main">
        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header">
                <span class="adm-avatar"><?= e($usuario['iniciais']) ?></span>
                <div>
                    <h2><?= e($usuario['nome']) ?></h2>
                    <p><?= e($usuario['email'] ?? '') ?></p>
                </div>
            </div>
            <dl class="adm-meta adm-meta--stack">
                <div><dt>Perfil</dt><dd><?= administradores_badge_perfil($usuario['perfil']) ?></dd></div>
                <div><dt>Permissões</dt><dd><?= e(ADMINISTRADORES_PERFIS_DESCRICAO[$usuario['perfil']] ?? '') ?></dd></div>
                <?php if ($conta): ?>
                    <div><dt>Conta criada em</dt><dd><?= admin_data_hora($conta['criado_em'], false) ?></dd></div>
                    <div><dt>Último acesso registrado</dt><dd><?= admin_data_hora($conta['ultimo_acesso']) ?></dd></div>
                <?php else: ?>
                    <div><dt>Conta</dt><dd>Demonstrativa (sem banco de dados)</dd></div>
                <?php endif; ?>
            </dl>
        </section>
    </div>

    <aside class="adm-form-layout__side">
        <?php if ($mysql): ?>
            <form class="adm-card adm-panel adm-form-section" method="post" novalidate>
                <?= csrf_campo() ?>
                <div class="adm-form-section__header">
                    <div>
                        <h2>Trocar minha senha</h2>
                        <p>Pelo menos <?= AUTH_SENHA_MINIMA ?> caracteres, com letras e números.</p>
                    </div>
                </div>
                <?php if ($erro): ?><?= admin_aviso('erro', $erro) ?><?php endif; ?>
                <label class="adm-field">
                    <span class="adm-field__label">Senha atual</span>
                    <input class="adm-input" type="password" name="senha_atual" required autocomplete="current-password">
                </label>
                <label class="adm-field">
                    <span class="adm-field__label">Nova senha</span>
                    <input class="adm-input" type="password" name="senha" minlength="<?= AUTH_SENHA_MINIMA ?>" maxlength="72" required autocomplete="new-password">
                </label>
                <label class="adm-field">
                    <span class="adm-field__label">Confirmação da nova senha</span>
                    <input class="adm-input" type="password" name="senha_confirmacao" maxlength="72" required autocomplete="new-password">
                </label>
                <button type="submit" class="adm-button adm-button--primary adm-button--block"><?= icon('key-round') ?> Trocar senha</button>
            </form>
        <?php endif; ?>
    </aside>
</div>

<?php
admin_page_end();
