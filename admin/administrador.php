<?php
/**
 * Cadastro e edição de uma conta de acesso (?id= para editar).
 * Ações (POST com CSRF): salvar (dados e situação) e senha (redefinir a senha).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/administradores.php';

$usuario = administradores_iniciar();
$id = (int)($_GET['id'] ?? 0) ?: null;
$conta = $id ? administradores_repo()->buscar($id) : null;
if ($id && !$conta) {
    http_response_code(404);
    admin_pagina_erro('Conta não encontrada', 'A conta solicitada não existe.', 'user-x');
    exit;
}
$propria = $conta && $conta['id'] === $usuario['id'];
$valores = $conta ?? ['nome' => '', 'email' => '', 'perfil' => 'bibliotecaria', 'ativo' => true];
$erros = [];
$errosSenha = null;
$falha = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    try {
        if ($acao === 'senha' && $conta) {
            $nova = (string)($_POST['senha'] ?? '');
            $errosSenha = auth_senha_problema($nova, (string)($_POST['senha_confirmacao'] ?? ''));
            if (!$errosSenha) {
                $hash = password_hash($nova, PASSWORD_DEFAULT);
                administradores_repo()->definirSenha($conta['id'], $hash);
                if ($propria) {
                    auth_renovar_marca_senha($hash);
                }
                registrar_acao('usuario.redefinir_senha', 'administrador', $conta['id'], ['email' => $conta['email']]);
                admin_flash('sucesso', 'Senha redefinida.', $propria
                    ? 'Sua nova senha já está valendo.'
                    : 'Informe a nova senha a ' . $conta['nome'] . ' de forma segura (pessoalmente, não por e-mail ou grupo). As sessões abertas com a senha antiga foram encerradas.');
                admin_redirecionar(admin_url('administrador') . '?id=' . $conta['id']);
            }
        } elseif ($acao === 'salvar') {
            $post = $_POST;
            if ($propria) {
                // Ninguém muda o próprio perfil nem desativa a própria conta (evita ficar sem acesso por engano).
                $post['perfil'] = $conta['perfil'];
                $post['ativo'] = '1';
            }
            [$dados, $erros] = administradores_validar($post, $conta['id'] ?? null);
            $valores = $dados + $valores;
            if (!$erros) {
                $cargo = AUTH_PERFIS[$dados['perfil']];
                if ($conta) {
                    $alteracoes = administradores_repo()->atualizar($conta['id'], $dados['nome'], $dados['email'], $dados['perfil'], $cargo, $dados['ativo']);
                    if ($alteracoes) {
                        $detalhes = [];
                        foreach ($alteracoes as $campo => [$antes, $depois]) {
                            $detalhes[$campo] = ['antes' => $antes, 'depois' => $depois];
                        }
                        registrar_acao(isset($alteracoes['ativo']) ? ($dados['ativo'] ? 'usuario.ativar' : 'usuario.desativar') : 'usuario.editar', 'administrador', $conta['id'], $detalhes);
                        admin_flash('sucesso', 'Conta atualizada.', isset($alteracoes['ativo']) && !$dados['ativo'] ? $dados['nome'] . ' não consegue mais entrar no painel.' : 'As alterações já estão valendo.');
                    } else {
                        admin_flash('aviso', 'Nenhuma alteração', 'Os dados enviados são iguais aos que já estavam cadastrados.');
                    }
                    admin_redirecionar(admin_url('administrador') . '?id=' . $conta['id']);
                }
                $novoId = administradores_repo()->criar($dados['nome'], $dados['email'], $dados['perfil'], $cargo, password_hash((string)$_POST['senha'], PASSWORD_DEFAULT));
                if (!$dados['ativo']) {
                    administradores_repo()->atualizar($novoId, $dados['nome'], $dados['email'], $dados['perfil'], $cargo, false);
                }
                registrar_acao('usuario.cadastrar', 'administrador', $novoId, ['email' => $dados['email'], 'perfil' => $dados['perfil']]);
                admin_flash('sucesso', 'Conta cadastrada.', $dados['nome'] . ' já pode entrar no painel com o e-mail ' . $dados['email'] . ' e a senha inicial. Peça que troque a senha em "Meu perfil".');
                admin_redirecionar(admin_url('administradores'));
            }
        }
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) {
            error_log('[biblioteca] Erro ao salvar conta: ' . $e->getMessage());
            $falha = 'Não foi possível salvar. Tente novamente.';
        } else {
            $falha = $e->getMessage();
        }
    }
}

$v = fn (string $campo) => e((string)($valores[$campo] ?? ''));
$erro = fn (string $campo) => isset($erros[$campo]) ? '<span class="adm-field__error">' . e($erros[$campo]) . '</span>' : '';
$inv = fn (string $campo) => isset($erros[$campo]) ? ' is-invalid' : '';
$titulo = $conta ? 'Editar conta' : 'Nova conta de acesso';

admin_page_start('administradores', $titulo, 'Administradores', 'Contas de acesso ao painel', ['assets/css/acervo.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Administradores', admin_url('administradores')], [$titulo]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($conta ? $conta['nome'] : $titulo) ?></h1>
        <p><?= $conta
            ? 'Cadastrada em ' . admin_data_hora($conta['criado_em'], false) . ' · último acesso: ' . ($conta['ultimo_acesso'] ? admin_data_hora($conta['ultimo_acesso']) : 'nunca entrou')
            : 'A pessoa entra no painel com o e-mail e a senha inicial definidos aqui.' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('administradores')) ?>"><?= icon('arrow-left') ?> Voltar à lista</a>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($falha): ?><?= admin_aviso('erro', 'Não foi possível salvar.', $falha) ?><?php endif; ?>
<?php if ($erros): ?><?= admin_aviso('erro', 'Verifique os campos destacados.') ?><?php endif; ?>

<div class="adm-form-layout">
    <div class="adm-form-layout__main">
        <form class="adm-card adm-panel adm-form-section" method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="salvar">
            <div class="adm-form-section__header">
                <span class="adm-step">1</span>
                <div>
                    <h2>Dados da conta</h2>
                    <p>O e-mail é usado para entrar no painel e não pode se repetir.</p>
                </div>
            </div>
            <div class="adm-form-grid">
                <label class="adm-field adm-field--full<?= $inv('nome') ?>">
                    <span class="adm-field__label">Nome completo <span class="adm-required" aria-hidden="true">*</span></span>
                    <input class="adm-input" type="text" name="nome" maxlength="150" required value="<?= $v('nome') ?>" autocomplete="off">
                    <?= $erro('nome') ?>
                </label>
                <label class="adm-field adm-field--full<?= $inv('email') ?>">
                    <span class="adm-field__label">E-mail <span class="adm-required" aria-hidden="true">*</span></span>
                    <input class="adm-input" type="email" name="email" maxlength="190" required value="<?= $v('email') ?>" autocomplete="off">
                    <?= $erro('email') ?>
                </label>
                <label class="adm-field adm-field--full<?= $inv('perfil') ?>">
                    <span class="adm-field__label">Perfil de acesso <span class="adm-required" aria-hidden="true">*</span></span>
                    <select class="adm-select" name="perfil"<?= $propria ? ' disabled' : '' ?> data-perfil-select>
                        <?php foreach (AUTH_PERFIS as $valor => $rotulo): ?>
                            <option value="<?= $valor ?>"<?= ($valores['perfil'] ?? '') === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="adm-field__hint"><?= $propria ? 'Você não pode alterar o seu próprio perfil. Peça a outro administrador.' : e(ADMINISTRADORES_PERFIS_DESCRICAO[$valores['perfil']] ?? '') ?></span>
                    <?= $erro('perfil') ?>
                </label>

                <?php if (!$conta): ?>
                    <label class="adm-field<?= isset($erros['senha']) ? ' is-invalid' : '' ?>">
                        <span class="adm-field__label">Senha inicial <span class="adm-required" aria-hidden="true">*</span></span>
                        <input class="adm-input" type="password" name="senha" minlength="<?= AUTH_SENHA_MINIMA ?>" maxlength="72" required autocomplete="new-password">
                        <span class="adm-field__hint">Pelo menos <?= AUTH_SENHA_MINIMA ?> caracteres, com letras e números.</span>
                        <?= $erro('senha') ?>
                    </label>
                    <label class="adm-field">
                        <span class="adm-field__label">Confirmação da senha <span class="adm-required" aria-hidden="true">*</span></span>
                        <input class="adm-input" type="password" name="senha_confirmacao" maxlength="72" required autocomplete="new-password">
                    </label>
                <?php endif; ?>

                <div class="adm-field adm-field--full">
                    <input type="hidden" name="ativo" value="0">
                    <label class="adm-check-confirm">
                        <input type="checkbox" name="ativo" value="1"<?= !empty($valores['ativo']) ? ' checked' : '' ?><?= $propria ? ' disabled' : '' ?>>
                        <span><strong>Conta ativa.</strong> Desmarque para bloquear o acesso sem apagar a conta nem o histórico de ações.</span>
                    </label>
                    <?php if ($propria): ?><input type="hidden" name="ativo" value="1"><span class="adm-field__hint">Você não pode desativar a sua própria conta.</span><?php endif; ?>
                </div>
            </div>
            <div class="adm-form-actions adm-form-actions--static">
                <a class="adm-button adm-button--ghost" href="<?= e(admin_url('administradores')) ?>">Cancelar</a>
                <button type="submit" class="adm-button adm-button--primary"><?= icon('check') ?> <?= $conta ? 'Salvar alterações' : 'Cadastrar conta' ?></button>
            </div>
        </form>
    </div>

    <aside class="adm-form-layout__side">
        <?php if ($conta): ?>
            <form class="adm-card adm-panel adm-form-section" method="post" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="senha">
                <div class="adm-form-section__header">
                    <span class="adm-step">2</span>
                    <div>
                        <h2>Redefinir senha</h2>
                        <p>A senha atual nunca é exibida. Ao redefinir, as sessões abertas com a senha antiga são encerradas.</p>
                    </div>
                </div>
                <?php if ($errosSenha): ?><?= admin_aviso('erro', $errosSenha) ?><?php endif; ?>
                <label class="adm-field">
                    <span class="adm-field__label">Nova senha</span>
                    <input class="adm-input" type="password" name="senha" minlength="<?= AUTH_SENHA_MINIMA ?>" maxlength="72" required autocomplete="new-password">
                    <span class="adm-field__hint">Pelo menos <?= AUTH_SENHA_MINIMA ?> caracteres, com letras e números.</span>
                </label>
                <label class="adm-field">
                    <span class="adm-field__label">Confirmação</span>
                    <input class="adm-input" type="password" name="senha_confirmacao" maxlength="72" required autocomplete="new-password">
                </label>
                <button type="submit" class="adm-button adm-button--ghost adm-button--block"><?= icon('key-round') ?> Redefinir senha</button>
            </form>
        <?php endif; ?>

        <section class="adm-card adm-panel adm-form-section">
            <div class="adm-form-section__header">
                <div>
                    <h2>Perfis de acesso</h2>
                    <p>Use o perfil com o mínimo de acesso necessário para a função.</p>
                </div>
            </div>
            <ul class="adm-perfis-lista">
                <?php foreach (ADMINISTRADORES_PERFIS_DESCRICAO as $perfil => $descricao): ?>
                    <li><strong><?= e(AUTH_PERFIS[$perfil]) ?></strong><?= e($descricao) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </aside>
</div>

<?php
admin_page_end();
