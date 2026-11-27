<?php
/**
 * Inventário → Lotes: os lotes deste inventário (migração 240), com o resumo consolidado.
 * Cada número conta exemplares físicos uma vez só (um exemplar pertence a um único lote).
 * O botão "Novo lote" abre o formulário único (lote-novo.php) já com este inventário.
 * Módulos opcionais acrescentam informações na linha de cada lote (gancho lote.linha).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

$usuario = fisico_iniciar();
$id = (int)admin_param($_GET, 'id');
try {
    $inv = inventario_repo()->buscar($id);
    if (!$inv) {
        http_response_code(404);
        admin_pagina_erro('Inventário não encontrado', 'Confira o endereço e tente novamente.', 'clipboard-check');
        exit;
    }
    $lotes = lotes_repo()->doInventario($id);
    $t = lotes_repo()->totaisInventario($id);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os lotes', fisico_erro_tecnico($e, 'lotes do inventário') . ' Confira se a migração 240 foi aplicada.');
    exit;
}
$podeCriar = auth_pode('lotes.gerenciar') && in_array($inv['status'], ['aberto', 'em_andamento', 'pausado'], true);
$acoes = $podeCriar ? '<a class="adm-button adm-button--primary" href="' . e(admin_url('lote-novo') . '?inventario=' . $id) . '">' . icon('plus') . ' Novo lote</a>' : '';

admin_page_start('inventario', 'Lotes: ' . $inv['titulo'], 'Inventário', 'Lotes do inventário', FISICO_CSS);
?>

<?= inventario_cabecalho_html($inv, 'lotes', 'Lotes deste inventário, do cadastro à estante definitiva. Nenhum lote é apagado.', $acoes) ?>
<?= admin_flash_html() ?>

<section class="adm-stats adm-stats--6" aria-label="Resumo dos lotes">
    <?= fisico_stat('Lotes', $t['lotes'], $t['encerrados'] . ' encerrado(s)', 'layers', 'blue') ?>
    <?= fisico_stat('Previstos nos lotes', $t['previstos'], 'Soma das quantidades previstas', 'clipboard-list', 'indigo') ?>
    <?= fisico_stat('Cadastrados com código BDP', $t['cadastrados'], 'Exemplares oficiais nos lotes', 'book-copy', 'blue') ?>
    <?= fisico_stat('Carimbados', $t['carimbados'], 'Código escrito no livro', 'stamp', 'teal') ?>
    <?= fisico_stat('Organizados', $t['organizados'], 'Na estante definitiva', 'map-pin', 'green') ?>
    <?= fisico_stat('Restantes', $t['restantes'], 'Previstos ainda não organizados', 'hourglass', 'amber') ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-lotes-inv">
    <div class="adm-panel__header"><div><h2 id="titulo-lotes-inv">Lotes</h2><p><?= fisico_numero(count($lotes)) ?> lote(s) neste inventário</p></div></div>
    <div class="adm-table-wrap">
        <table class="adm-table adm-table--cards adm-inv-lotes">
            <thead><tr><th scope="col">Lote</th><th scope="col">Responsável</th><th scope="col">Previstos</th><th scope="col">Cadastrados</th><th scope="col">Carimbados</th><th scope="col">Organizados</th><th scope="col">Situação</th><th scope="col"><span class="adm-visually-hidden">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($lotes as $l): $url = lote_url_trabalho($l); ?>
                <tr>
                    <td class="adm-td-title"><a class="adm-link" href="<?= e($url) ?>"><strong><?= e(lote_nome_completo($l)) ?></strong></a>
                        <?php if ($l['prazo']): ?><small class="adm-block-muted">Prazo: <?= e(admin_data_hora($l['prazo'], false)) ?></small><?php endif; ?>
                        <?= modulo_gancho_html('lote.linha', $l) ?></td>
                    <td data-label="Responsável" class="adm-td-sec"><?= e($l['responsavel_nome'] ?? '—') ?><?= $l['participantes'] ? '<small class="adm-block-muted">' . e($l['participantes']) . '</small>' : '' ?></td>
                    <td data-label="Previstos"><?= $l['capacidade'] !== null ? fisico_numero((int)$l['capacidade']) : '—' ?></td>
                    <td data-label="Cadastrados"><?= fisico_numero((int)$l['exemplares']) ?></td>
                    <td data-label="Carimbados"><?= fisico_numero((int)$l['carimbados']) ?></td>
                    <td data-label="Organizados"><?= fisico_numero((int)$l['organizados']) ?></td>
                    <td data-label="Situação"><?= lote_badge($l['status']) ?></td>
                    <td class="adm-table__actions adm-actions-col"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($url) ?>"><?= icon('arrow-right') ?> Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lotes): ?><tr><td colspan="8"><div class="adm-empty"><?= icon('layers') ?><strong>Nenhum lote neste inventário ainda.</strong>
                <span><?= $podeCriar ? 'Use “Novo lote” para começar.' : 'Os lotes aparecem aqui quando forem criados.' ?></span></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="adm-field__hint"><?= icon('info') ?> Lotes antigos, criados antes da ligação com o inventário, continuam no <a class="adm-link" href="<?= e(admin_url('lotes')) ?>">Histórico de Lotes</a>.</p>
</section>

<?php
admin_page_end(FISICO_JS);
