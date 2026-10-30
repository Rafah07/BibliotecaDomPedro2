<?php
/** Listagem das contas de acesso ao painel. Somente para quem tem a permissão usuarios.gerenciar. */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/administradores.php';

$usuario = administradores_iniciar();
$filtros = administradores_filtros($_GET);
$base = admin_url('administradores');
$resultado = administradores_repo()->listar($filtros, max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
$resumo = administradores_repo()->resumo();
$temFiltro = $filtros['q'] !== '' || $filtros['perfil'] !== '' || $filtros['status'] !== '';

admin_page_start('administradores', 'Administradores', 'Administradores', 'Contas de acesso ao painel', ['assets/css/acervo.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Administradores']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Administradores e bibliotecárias</h1>
        <p>Quem pode entrar no painel e o que cada pessoa pode fazer. Alunos não têm conta: o site público continua aberto, sem login.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--primary" href="<?= e(admin_url('administrador')) ?>"><?= icon('user-plus') ?> Nova conta</a>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats" aria-label="Resumo das contas">
    <?php foreach ([
        ['Contas ativas', $resumo['ativos'], 'de ' . $resumo['total'] . ' cadastradas', 'user-check', 'green'],
        ['Administradores ativos', $resumo['administradores'], 'Gerenciam as contas de acesso', 'shield-check', 'indigo'],
        ['Bibliotecárias ativas', $resumo['bibliotecarias'], 'Gerenciam o acervo', 'library-big', 'blue'],
        ['Contas inativas', $resumo['total'] - $resumo['ativos'], 'Sem acesso ao painel', 'user-x', 'amber'],
    ] as [$titulo, $valor, $desc, $ic, $tom]): ?>
        <article class="adm-card adm-stat adm-stat--compact">
            <div class="adm-stat__top">
                <p class="adm-stat__title"><?= e($titulo) ?></p>
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($ic) ?></span>
            </div>
            <p class="adm-stat__value"><?= (int)$valor ?></p>
            <p class="adm-stat__desc"><?= e($desc) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-contas">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-contas">Contas de acesso</h2>
            <p aria-live="polite"><?= (int)$resultado['total'] ?> <?= $resultado['total'] === 1 ? 'conta encontrada' : 'contas encontradas' ?></p>
        </div>
    </div>

    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
        <label class="adm-field-search adm-toolbar__q">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar contas</span>
            <input type="search" name="q" placeholder="Pesquisar por nome ou e-mail..." value="<?= e($filtros['q']) ?>" autocomplete="off">
        </label>
        <div class="adm-filters adm-filters--4">
            <label class="adm-filter">
                <span>Perfil</span>
                <select class="adm-select<?= $filtros['perfil'] !== '' ? ' has-value' : '' ?>" name="perfil">
                    <option value="">Todos</option>
                    <?php foreach (AUTH_PERFIS as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"<?= $filtros['perfil'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Situação</span>
                <select class="adm-select<?= $filtros['status'] !== '' ? ' has-value' : '' ?>" name="status">
                    <option value="">Todas</option>
                    <option value="ativo"<?= $filtros['status'] === 'ativo' ? ' selected' : '' ?>>Ativas</option>
                    <option value="inativo"<?= $filtros['status'] === 'inativo' ? ' selected' : '' ?>>Inativas</option>
                </select>
            </label>
        </div>
        <input type="hidden" name="ordem" value="<?= e($filtros['ordem']) ?>">
        <input type="hidden" name="dir" value="<?= e($filtros['dir']) ?>">
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
    </form>

    <div class="adm-table-wrap adm-table-wrap--flush" data-loading-area>
        <table class="adm-table adm-table--cards">
            <thead>
                <tr>
                    <?= admin_th_ordenar('Nome', 'nome', $base, $filtros) ?>
                    <?= admin_th_ordenar('E-mail', 'email', $base, $filtros) ?>
                    <th scope="col">Perfil</th>
                    <th scope="col">Situação</th>
                    <?= admin_th_ordenar('Cadastro', 'criado', $base, $filtros, 'desc') ?>
                    <?= admin_th_ordenar('Último acesso', 'acesso', $base, $filtros, 'desc') ?>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultado['itens'] as $a): ?>
                    <tr>
                        <td class="adm-td-title">
                            <a class="adm-book-link" href="<?= e(admin_url('administrador') . '?id=' . $a['id']) ?>"><?= e($a['nome']) ?></a>
                            <?php if ($a['id'] === $usuario['id']): ?><small>Você</small><?php endif; ?>
                        </td>
                        <td data-label="E-mail"><?= e($a['email']) ?></td>
                        <td data-label="Perfil"><?= administradores_badge_perfil($a['perfil']) ?></td>
                        <td data-label="Situação"><?= administradores_badge_status($a['ativo']) ?></td>
                        <td data-label="Cadastro" class="adm-table__muted"><?= admin_data_hora($a['criado_em'], false) ?></td>
                        <td data-label="Último acesso" class="adm-table__muted"><?= $a['ultimo_acesso'] ? admin_data_hora($a['ultimo_acesso']) : 'Nunca entrou' ?></td>
                        <td class="adm-actions-col">
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('administrador') . '?id=' . $a['id']) ?>"><?= icon('pencil') ?> Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$resultado['itens']): ?>
                    <tr><td colspan="7">
                        <div class="adm-empty">
                            <?= icon('users') ?>
                            <strong><?= $temFiltro ? 'Nenhuma conta encontrada' : 'Nenhuma conta cadastrada' ?></strong>
                            <span><?= $temFiltro ? 'Revise a pesquisa ou os filtros.' : 'Cadastre a primeira conta de acesso.' ?></span>
                            <?php if ($temFiltro): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a><?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtros) ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-perfis">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-perfis">O que cada perfil pode fazer</h2>
            <p>As permissões são conferidas pelo servidor em cada página e ação.</p>
        </div>
    </div>
    <div class="adm-table-wrap adm-table-wrap--inset">
        <table class="adm-table adm-table--compact">
            <tbody>
                <?php foreach (ADMINISTRADORES_PERFIS_DESCRICAO as $perfil => $descricao): ?>
                    <tr><th scope="row"><?= administradores_badge_perfil($perfil) ?></th><td><?= e($descricao) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_page_end();
