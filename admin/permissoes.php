<?php
/**
 * Sistema → Perfis e permissões: o que cada perfil pode fazer no painel (AUTH_PERMISSOES).
 *
 * Somente leitura. As permissões ficam no código (admin/includes/auth.php) e são conferidas no
 * servidor em todas as páginas; mudar o perfil de uma pessoa é feito em Administradores.
 * Todos os perfis podem abrir esta página para saber o que a própria conta permite.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/configuracoes.php';

$usuario = auth_exigir('painel.ver');
$contas = null;
if (auth_pode('usuarios.gerenciar') && db_modo() === 'mysql') {
    try {
        $contas = [];
        foreach (db_query('SELECT perfil, COUNT(*) AS total FROM administradores WHERE ativo = 1 GROUP BY perfil') as $l) {
            $contas[$l['perfil']] = (int)$l['total'];
        }
    } catch (Throwable $e) {
        $contas = null;
        error_log('[biblioteca] Contagem de contas por perfil indisponível: ' . get_class($e));
    }
}

admin_page_start('permissoes', 'Perfis e permissões', 'Perfis e permissões', 'O que cada perfil pode fazer no painel', CONFIG_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Configurações', auth_pode('configuracoes.ver') ? admin_url('configuracoes') : null], ['Perfis e permissões']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Perfis e permissões</h1>
        <p>Cada conta do painel tem um perfil. O perfil define o que a pessoa pode ver e fazer, e o sistema confere isso no servidor em todas as páginas. Seu perfil: <strong><?= e(AUTH_PERFIS[$usuario['perfil']] ?? $usuario['perfil']) ?></strong>.</p>
    </div>
    <?php if (auth_pode('usuarios.gerenciar')): ?>
        <div class="adm-page-head__actions">
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('administradores')) ?>"><?= icon('users') ?> Gerenciar contas</a>
        </div>
    <?php endif; ?>
</section>

<section class="adm-config-perfis" aria-label="Perfis">
    <?php foreach (AUTH_PERFIS as $perfil => $nome): ?>
        <article class="adm-card adm-config-perfil<?= $perfil === $usuario['perfil'] ? ' is-atual' : '' ?>">
            <h2><?= e($nome) ?><?= $perfil === $usuario['perfil'] ? ' <span class="adm-status adm-tone--blue">seu perfil</span>' : '' ?></h2>
            <p><?= count(AUTH_PERMISSOES[$perfil] ?? []) ?> permissões<?= $contas !== null ? ' · ' . ($contas[$perfil] ?? 0) . ' conta(s) ativa(s)' : '' ?></p>
        </article>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-matriz">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-matriz">Permissões por perfil</h2>
            <p>As permissões são fixas no sistema, para que nenhum ajuste por engano exponha dados pessoais dos alunos. Para dar mais ou menos acesso a uma pessoa, mude o perfil da conta dela.</p>
        </div>
    </div>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-config-matriz">
            <thead>
                <tr>
                    <th scope="col">Permissão</th>
                    <?php foreach (AUTH_PERFIS as $perfil => $nome): ?>
                        <th scope="col" class="adm-config-matriz__perfil<?= $perfil === $usuario['perfil'] ? ' is-atual' : '' ?>"><?= e($nome) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <?php foreach (AUTH_PERMISSOES_DESCRICOES as $grupo => $permissoes): ?>
                <tbody>
                    <tr class="adm-config-matriz__grupo"><th scope="rowgroup" colspan="<?= count(AUTH_PERFIS) + 1 ?>"><?= e($grupo) ?></th></tr>
                    <?php foreach ($permissoes as $permissao => $descricao): ?>
                        <tr>
                            <th scope="row"><?= e($descricao) ?> <code><?= e($permissao) ?></code></th>
                            <?php foreach (AUTH_PERFIS as $perfil => $nome): ?>
                                <?php $tem = in_array($permissao, AUTH_PERMISSOES[$perfil] ?? [], true); ?>
                                <td class="adm-config-matriz__celula<?= $perfil === $usuario['perfil'] ? ' is-atual' : '' ?>">
                                    <?= $tem ? '<span class="adm-config-sim" title="Permitido">' . icon('check') . '<span class="adm-visually-hidden">' . e($nome) . ': permitido</span></span>'
                                            : '<span class="adm-config-nao" title="Não permitido">—<span class="adm-visually-hidden">' . e($nome) . ': não permitido</span></span>' ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>
    </div>
</section>

<?php
admin_page_end();
