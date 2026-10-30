<?php
/**
 * Inventário digital: lista dos inventários (em andamento primeiro) e acesso à conferência.
 * Só um inventário pode estar em andamento ou pausado de cada vez.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\InventarioService;

fisico_iniciar();
$base = admin_url('inventario');
$status = array_key_exists(admin_param($_GET, 'status'), InventarioService::STATUS) ? admin_param($_GET, 'status') : '';
$filtros = ['status' => $status];
try {
    $resultado = inventario_repo()->listar($status, max(1, (int)admin_param($_GET, 'pagina', '1')), 25);
    $ativo = inventario_repo()->ativo();
    $resumoAtivo = $ativo ? inventario_repo()->resumo((int)$ativo['id']) : null;
    $exemplares = exemplares_repo()->resumo();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os inventários', fisico_erro_tecnico($e, 'listagem de inventários'));
    exit;
}
$podeGerenciar = auth_pode('inventario.gerenciar');

admin_page_start('inventario', 'Inventário', 'Inventário', 'Conferência digital do acervo físico', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Inventário digital</h1>
        <p>Confira os exemplares físicos com o leitor de código de barras ou digitando o código. Ausências e divergências nunca alteram o cadastro sozinhas: cada uma pede uma decisão.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeGerenciar): ?><a class="adm-button adm-button--primary" href="<?= e(admin_url('inventario-editar')) ?>"><?= icon('plus') ?> Novo inventário</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if ($ativo): ?>
    <section class="adm-card adm-panel adm-inventario-ativo" aria-labelledby="titulo-ativo">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-ativo"><?= e($ativo['titulo']) ?> <?= fisico_badge_inventario($ativo['status']) ?></h2>
                <p>Responsável: <?= e($ativo['responsavel_nome'] ?? '—') ?> · <?= $ativo['setor'] ? 'Setor: ' . e($ativo['setor']) : 'Acervo inteiro' ?> · Início em <?= admin_data_hora($ativo['data_inicio'], false) ?></p>
            </div>
            <div class="adm-page-head__actions">
                <?php if ($ativo['status'] === 'em_andamento' && auth_pode('inventario.conferir')): ?>
                    <a class="adm-button adm-button--primary" href="<?= e(admin_url('inventario-conferencia') . '?id=' . (int)$ativo['id']) ?>"><?= icon('scan-barcode') ?> Continuar a conferência</a>
                <?php endif; ?>
                <a class="adm-button adm-button--ghost" href="<?= e(admin_url('inventario-detalhes') . '?id=' . (int)$ativo['id']) ?>"><?= icon('eye') ?> Ver detalhes</a>
            </div>
        </div>
        <?php $pct = $resumoAtivo['previstos'] ? (int)floor($resumoAtivo['previstos_conferidos'] * 100 / $resumoAtivo['previstos']) : 0; ?>
        <div class="adm-progresso" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Previstos conferidos">
            <span style="width: <?= $pct ?>%"></span>
        </div>
        <p class="adm-field__hint"><?= fisico_numero($resumoAtivo['previstos_conferidos']) ?> de <?= fisico_numero($resumoAtivo['previstos']) ?> exemplares previstos conferidos (<?= $pct ?>%) · <?= fisico_numero($resumoAtivo['divergencias_pendentes']) ?> divergência(s) pendente(s) · <?= fisico_numero($resumoAtivo['cadastrados']) ?> cadastrado(s) na conferência</p>
    </section>
<?php elseif ($exemplares['ativos'] === 0): ?>
    <?= admin_aviso('info', 'Ainda não há exemplares físicos cadastrados.', 'O inventário confere exemplares. Você pode começar um inventário mesmo assim: durante a conferência, cada livro encontrado pode ganhar seus exemplares, com confirmação explícita.') ?>
<?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-inventarios">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-inventarios">Inventários</h2>
            <p><?= fisico_numero($resultado['total']) ?> <?= $resultado['total'] === 1 ? 'inventário' : 'inventários' ?></p>
        </div>
        <form method="get" action="<?= e($base) ?>" class="adm-inline-form">
            <label class="adm-filter"><span>Situação</span>
                <select class="adm-select" name="status" data-auto-submit>
                    <option value="">Todas</option>
                    <?php foreach (InventarioService::STATUS as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"<?= $status === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button class="adm-button adm-button--ghost adm-button--sm" type="submit">Filtrar</button></noscript>
        </form>
    </div>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards">
            <thead>
                <tr><th scope="col">Inventário</th><th scope="col">Situação</th><th scope="col">Responsável</th><th scope="col">Início</th><th scope="col">Previstos</th><th scope="col">Conferidos</th><th scope="col" class="adm-actions-col">Ações</th></tr>
            </thead>
            <tbody>
                <?php foreach ($resultado['itens'] as $inv): ?>
                    <?php $url = admin_url('inventario-detalhes') . '?id=' . (int)$inv['id']; ?>
                    <tr>
                        <td class="adm-td-title"><a class="adm-book-link" href="<?= e($url) ?>"><?= e($inv['titulo']) ?></a><small class="adm-block-muted">Nº <?= (int)$inv['id'] ?> · <?= $inv['setor'] ? 'Setor ' . e($inv['setor']) : 'Acervo inteiro' ?></small></td>
                        <td data-label="Situação"><?= fisico_badge_inventario($inv['status']) ?></td>
                        <td data-label="Responsável"><?= e($inv['responsavel_nome'] ?? '—') ?></td>
                        <td data-label="Início"><?= admin_data_hora($inv['data_inicio'] ?? $inv['iniciado_em'], false) ?></td>
                        <td data-label="Previstos"><?= $inv['status'] === 'aberto' ? '<span class="adm-table__muted">definidos ao iniciar</span>' : fisico_numero((int)$inv['previstos']) ?></td>
                        <td data-label="Conferidos"><?= fisico_numero((int)$inv['conferidos']) ?><?= (int)$inv['cadastrados'] ? ' <small class="adm-table__muted">(' . (int)$inv['cadastrados'] . ' cadastrados)</small>' : '' ?></td>
                        <td class="adm-actions-col"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($url) ?>"><?= icon('eye') ?> Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$resultado['itens']): ?>
                    <tr><td colspan="7"><div class="adm-empty"><?= icon('clipboard-check') ?>
                        <strong><?= $status ? 'Nenhum inventário nesta situação' : 'Nenhum inventário criado ainda' ?></strong>
                        <span>Crie um inventário, revise os dados e clique em "Iniciar conferência" quando a equipe estiver pronta.</span>
                        <?php if ($podeGerenciar && !$status): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url('inventario-editar')) ?>"><?= icon('plus') ?> Novo inventário</a><?php endif; ?>
                    </div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= admin_paginacao($resultado['total'], $resultado['pagina'], 25, $base, $filtros, [25]) ?>
</section>

<?php
admin_page_end(FISICO_JS);
