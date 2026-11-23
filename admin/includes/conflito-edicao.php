<?php
/**
 * Comparação mostrada quando outra pessoa salvou a obra enquanto o formulário estava aberto
 * (controle de concorrência otimista, migração 190). Incluído DENTRO do formulário "Editar dados da obra".
 * Espera: $conflito = ['linhas' => [campo => [rotulo, base, atual, meu, estado]], 'alterado_por', 'alterado_em', 'livro_id'].
 *
 * Nada do que a pessoa digitou é descartado: o formulário abaixo já vem com as alterações dela
 * mescladas às do outro colaborador. Para cada campo alterado pelos dois, ela escolhe qual valor fica.
 */
$estadoRotulo = [
    'so_meu' => ['Só você alterou', 'blue'],
    'so_outro' => ['Alterado pelo outro colaborador', 'amber'],
    'conflito' => ['Os dois alteraram', 'red'],
    'igual' => ['Mesmo valor', 'green'],
];
$conflitantes = array_filter($conflito['linhas'], fn ($l) => $l['estado'] === 'conflito');
?>
<section class="adm-notice adm-notice--amber adm-conflito" role="alert" aria-labelledby="titulo-conflito" id="conflito">
    <?= icon('triangle-alert') ?>
    <div class="adm-notice__content">
        <strong id="titulo-conflito">Esta obra foi atualizada por outro usuário enquanto você estava editando.</strong>
        <p>Nada foi gravado ainda.<?= $conflito['alterado_por'] ? ' Última gravação: ' . e($conflito['alterado_por']) . ($conflito['alterado_em'] ? ' em ' . e(admin_data_hora($conflito['alterado_em'])) : '') . '.' : '' ?>
            O formulário abaixo já reúne <strong>as suas alterações</strong> e <strong>as alterações salvas pelo outro colaborador</strong>.
            <?= $conflitantes ? 'Escolha o valor dos campos que os dois alteraram e salve de novo.' : 'Não há campos alterados pelos dois: confira e salve de novo.' ?></p>
        <div class="adm-table-wrap">
            <table class="adm-table adm-table--compact adm-conflito__tabela">
                <thead><tr><th scope="col">Campo</th><th scope="col">Quando você abriu</th><th scope="col">Salvo agora</th><th scope="col">Sua alteração</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                <?php foreach ($conflito['linhas'] as $campo => $l): [$rot, $tom] = $estadoRotulo[$l['estado']]; ?>
                    <tr class="is-<?= e($l['estado']) ?>">
                        <th scope="row" data-label="Campo"><?= e($l['rotulo']) ?></th>
                        <td data-label="Quando você abriu"><?= $l['base'] !== '' ? e(mb_strimwidth($l['base'], 0, 120, '…')) : '<span class="adm-missing">vazio</span>' ?></td>
                        <td data-label="Salvo agora"><?= $l['atual'] !== '' ? e(mb_strimwidth($l['atual'], 0, 120, '…')) : '<span class="adm-missing">vazio</span>' ?></td>
                        <td data-label="Sua alteração"><?= $l['meu'] !== '' ? e(mb_strimwidth($l['meu'], 0, 120, '…')) : '<span class="adm-missing">vazio</span>' ?></td>
                        <td data-label="Situação">
                            <span class="adm-status adm-tone--<?= $tom ?>"><?= e($rot) ?></span>
                            <?php if ($l['estado'] === 'conflito'): ?>
                                <span class="adm-conflito__escolha">
                                    <label><input type="radio" name="resolver[<?= e($campo) ?>]" value="meu" checked> manter o meu</label>
                                    <label><input type="radio" name="resolver[<?= e($campo) ?>]" value="salvo"> usar o salvo</label>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="adm-field__hint">“Usar o salvo” troca apenas aquele campo pelo valor gravado pelo outro colaborador. Os demais campos ficam como estão no formulário.</p>
        <div class="adm-actions-inline">
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('editar-livro') . '?id=' . (int)$conflito['livro_id']) ?>"><?= icon('refresh-cw') ?> Descartar minhas alterações e abrir a versão atual</a>
        </div>
    </div>
</section>
