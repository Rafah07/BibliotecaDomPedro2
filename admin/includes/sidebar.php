<?php
/** Menu lateral administrativo. Espera $paginaAtual (id do menu) definido pelo layout. */
$paginaAtual = $paginaAtual ?? 'dashboard';
?>
<aside class="adm-sidebar" id="adm-sidebar" aria-label="Menu administrativo">
    <div class="adm-sidebar__top">
        <a class="adm-brand" href="<?= htmlspecialchars(admin_url('dashboard')) ?>" aria-label="Biblioteca Dom Pedro II — Dashboard">
            <span class="adm-brand__logo"><?= icon('library-big') ?></span>
            <span class="adm-brand__text">
                <strong>Biblioteca</strong>
                <small>Dom Pedro II</small>
            </span>
        </a>
        <button class="adm-sidebar__toggle" type="button" data-sidebar-toggle aria-controls="adm-sidebar" aria-expanded="false" aria-label="Expandir menu">
            <?= icon('panel-left-open', 'adm-sidebar__toggle-open') ?>
            <?= icon('panel-left-close', 'adm-sidebar__toggle-close') ?>
        </button>
        <button class="adm-sidebar__close" type="button" data-mobile-close aria-label="Fechar menu">
            <?= icon('x') ?>
        </button>
    </div>

    <nav class="adm-nav" aria-label="Navegação administrativa">
        <?php foreach (ADMIN_MENU as $grupo => $itens): ?>
            <div class="adm-nav__group">
                <p class="adm-nav__title"><span><?= htmlspecialchars($grupo) ?></span></p>
                <ul>
                    <?php foreach ($itens as $id => $item): ?>
                        <?php if (!empty($item['oculto']) || (isset($item['permissao']) && !auth_pode($item['permissao']))) continue; ?>
                        <?php $ativo = $paginaAtual === $id; ?>
                        <li<?= isset($item['pai']) ? ' class="adm-nav__sub"' : '' ?>>
                            <?php if (($item['acao'] ?? '') === 'logout'): ?>
                                <form method="post" action="<?= htmlspecialchars(admin_base() . 'logout.php') ?>">
                                    <?= csrf_campo() ?>
                                    <button type="submit" class="adm-nav__link" data-tooltip="<?= htmlspecialchars($item['label']) ?>">
                                        <?= icon($item['icon'], 'adm-nav__icon') ?>
                                        <span class="adm-nav__label"><?= htmlspecialchars($item['label']) ?></span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(admin_url($id)) ?>"
                                   class="adm-nav__link<?= $ativo ? ' is-active' : '' ?>"
                                   data-tooltip="<?= htmlspecialchars($item['label']) ?>"
                                   <?= $ativo ? 'aria-current="page"' : '' ?>>
                                    <?= icon($item['icon'], 'adm-nav__icon') ?>
                                    <span class="adm-nav__label"><?= htmlspecialchars($item['label']) ?></span>
                                    <?php if (empty($item['pronta'])): ?>
                                        <span class="adm-nav__soon" title="Em desenvolvimento">Em breve</span>
                                    <?php endif; ?>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
<div class="adm-backdrop" data-mobile-close hidden></div>
