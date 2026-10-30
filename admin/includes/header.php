<?php
/** Barra superior. Espera $tituloHeader e $subtituloHeader definidos pelo layout. */
// O layout só é exibido para usuários autenticados (ver admin/includes/layout.php).
$usuario = auth_usuario();
$alertasHeader = dashboard_alertas();
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
        <form class="adm-search" role="search" method="get" action="<?= htmlspecialchars(admin_url('acervo')) ?>">
            <?= icon('search', 'adm-search__icon') ?>
            <input type="search" name="q" placeholder="Pesquisar livros, autores ou ISBN..." aria-label="Pesquisar no acervo" autocomplete="off">
            <kbd class="adm-search__kbd" aria-hidden="true">/</kbd>
        </form>

        <div class="adm-dropdown">
            <button class="adm-icon-button" type="button" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Notificações: <?= count($alertasHeader) ?> pendências">
                <?= icon('bell') ?>
                <?php if ($alertasHeader): ?><span class="adm-badge-dot"><?= count($alertasHeader) ?></span><?php endif; ?>
            </button>
            <div class="adm-dropdown__menu adm-dropdown__menu--wide" role="menu">
                <div class="adm-dropdown__header">
                    <strong>Notificações</strong>
                    <?php if (db_modo() === 'demo'): ?><span class="adm-demo-tag">Demonstrativo</span><?php endif; ?>
                </div>
                <?php if (!$alertasHeader): ?><p class="adm-dropdown__empty">Nenhuma pendência no momento.</p><?php endif; ?>
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
                    <small><?= htmlspecialchars(AUTH_PERFIS[$usuario['perfil']] ?? '') . (db_modo() === 'demo' ? ' · conta demonstrativa' : '') ?></small>
                </div>
                <a class="adm-dropdown__item" role="menuitem" href="<?= htmlspecialchars(admin_url('perfil')) ?>"><?= icon('user-round') ?> Meu perfil</a>
                <?php if (auth_pode('usuarios.gerenciar')): ?>
                    <a class="adm-dropdown__item" role="menuitem" href="<?= e(admin_url('administradores')) ?>"><?= icon('users') ?> Administradores</a>
                <?php endif; ?>
                <form method="post" action="<?= htmlspecialchars(admin_base() . 'logout.php') ?>">
                    <?= csrf_campo() ?>
                    <button class="adm-dropdown__item adm-dropdown__item--danger" role="menuitem" type="submit"><?= icon('log-out') ?> Sair</button>
                </form>
            </div>
        </div>
    </div>
</header>
