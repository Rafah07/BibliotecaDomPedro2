<?php
/** Barra superior. Espera $tituloHeader e $subtituloHeader definidos pelo layout. */
// Usuário logado quando houver; senão, o usuário demonstrativo das etapas anteriores.
$usuarioLogado = auth_usuario();
$usuario = $usuarioLogado ?? demo_usuario();
$alertasHeader = demo_alertas();
?>
<header class="adm-header">
    <button class="adm-icon-button adm-header__menu" type="button" data-mobile-open aria-controls="adm-sidebar" aria-label="Abrir menu">
        <?= icon('menu') ?>
    </button>

    <div class="adm-header__title">
        <p class="adm-header__heading"><?= htmlspecialchars($tituloHeader) ?></p>
        <p class="adm-header__subtitle"><?= htmlspecialchars($subtituloHeader) ?></p>
    </div>

    <div class="adm-header__actions">
        <form class="adm-search" role="search" data-demo-search>
            <?= icon('search', 'adm-search__icon') ?>
            <input type="search" name="q" placeholder="Pesquisar livros, alunos, exemplares..." aria-label="Pesquisa global (demonstrativa)" autocomplete="off">
            <kbd class="adm-search__kbd" aria-hidden="true">/</kbd>
        </form>

        <div class="adm-dropdown">
            <button class="adm-icon-button" type="button" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Notificações: <?= count($alertasHeader) ?> pendências">
                <?= icon('bell') ?>
                <span class="adm-badge-dot"><?= count($alertasHeader) ?></span>
            </button>
            <div class="adm-dropdown__menu adm-dropdown__menu--wide" role="menu">
                <div class="adm-dropdown__header">
                    <strong>Notificações</strong>
                    <span class="adm-demo-tag">Demonstrativo</span>
                </div>
                <?php foreach ($alertasHeader as $alerta): ?>
                    <a class="adm-notification" role="menuitem" href="<?= htmlspecialchars(admin_url($alerta['href'])) ?>">
                        <span class="adm-notification__icon adm-tone--<?= $alerta['gravidade'] === 'alta' ? 'red' : 'amber' ?>"><?= icon($alerta['icone']) ?></span>
                        <span>
                            <strong><?= htmlspecialchars($alerta['titulo']) ?></strong>
                            <small><?= htmlspecialchars($alerta['mensagem']) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="adm-dropdown">
            <button class="adm-profile" type="button" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Menu do perfil">
                <span class="adm-avatar"><?= htmlspecialchars($usuario['iniciais']) ?></span>
                <span class="adm-profile__text">
                    <strong><?= htmlspecialchars($usuario['nome']) ?></strong>
                    <small><?= htmlspecialchars($usuario['cargo']) ?></small>
                </span>
                <?= icon('chevron-down', 'adm-profile__chevron') ?>
            </button>
            <div class="adm-dropdown__menu" role="menu">
                <div class="adm-dropdown__header adm-dropdown__header--stack">
                    <strong><?= htmlspecialchars($usuario['nome']) ?></strong>
                    <small><?= $usuarioLogado ? htmlspecialchars(AUTH_PERFIS[$usuarioLogado['perfil']] ?? '') . (db_modo() === 'demo' ? ' · conta demonstrativa' : '') : 'Usuário demonstrativo' ?></small>
                </div>
                <a class="adm-dropdown__item" role="menuitem" href="<?= htmlspecialchars(admin_url('perfil')) ?>"><?= icon('user-round') ?> Meu perfil</a>
                <a class="adm-dropdown__item" role="menuitem" href="<?= htmlspecialchars(admin_url('configuracoes')) ?>"><?= icon('settings') ?> Configurações</a>
                <?php if ($usuarioLogado): ?>
                    <form method="post" action="<?= htmlspecialchars(admin_base() . 'logout.php') ?>">
                        <?= csrf_campo() ?>
                        <button class="adm-dropdown__item adm-dropdown__item--danger" role="menuitem" type="submit"><?= icon('log-out') ?> Sair</button>
                    </form>
                <?php else: ?>
                    <a class="adm-dropdown__item" role="menuitem" href="<?= htmlspecialchars(admin_base() . 'login.php') ?>"><?= icon('log-in') ?> Entrar</a>
                    <button class="adm-dropdown__item adm-dropdown__item--danger" role="menuitem" type="button" data-demo-logout>
                        <?= icon('log-out') ?> Sair <span class="adm-demo-tag">Demo</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
