<?php
/** Área de trabalho do Grupo 3 (carimbação): planilha interativa do lote. Sem redigitar códigos. */
$et = $r->etapas();
$filtro = (string)($_GET['f'] ?? 'pendentes');
$filtro = in_array($filtro, ['pendentes', 'carimbados', 'problemas', 'todos'], true) ? $filtro : 'pendentes';
$planilha = $et->planilhaCarimbacao((int)$lote['id'], $filtro);
$previa = $et->previaEncaminhamentoG4((int)$lote['id']);
?>
<div class="adm-card adm-panel">
    <div class="reorg-linha">
        <nav class="adm-tabs adm-tabs--links">
            <?php foreach (['pendentes' => 'Pendentes', 'carimbados' => 'Carimbados', 'problemas' => 'Com problema', 'todos' => 'Todos'] as $k => $rot): ?>
                <a class="adm-tab<?= $filtro === $k ? ' is-active' : '' ?>" href="<?= e($base . '?lote=' . $lote['id'] . '&f=' . $k) ?>"><?= e($rot) ?></a>
            <?php endforeach; ?>
        </nav>
        <button type="button" class="adm-button" data-reorg-encaminhar="g4" <?= $etapaPausada || !$previa['exemplares'] ? 'disabled' : '' ?>><?= icon('send') ?> Encaminhar carimbados à conferência (<?= $previa['exemplares'] ?>)</button>
    </div>
    <p class="adm-field__hint">Escreva no livro o código BDP mostrado e clique em “Carimbado”. Use “Estou com este” para avisar os colegas que o livro está com você.</p>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact reorg-planilha" data-reorg-lista>
        <thead><tr><th>Ordem</th><th>Código BDP</th><th>Título · autor</th><th>Editora · edição</th><th>Situação</th><th>Ações</th></tr></thead>
        <tbody>
        <?php if (!$planilha): ?><tr><td colspan="6" class="adm-table__muted">Nada neste filtro<?= $filtro === 'pendentes' ? ' — aguardando novos códigos BDP do Grupo 2' : '' ?>.</td></tr><?php endif; ?>
        <?php foreach ($planilha as $x): $comOutro = $x['reservado_nome'] && (int)$x['reservado_sessao_id'] !== (int)$sessao['id']; $comigo = $x['reservado_nome'] && !$comOutro; ?>
            <tr data-reorg-item data-id="<?= (int)$x['id'] ?>" class="<?= $comigo ? 'is-ativo' : '' ?>">
                <td><?= (int)$x['lote_ordem'] ?></td>
                <td class="adm-mono reorg-codigo"><?= e($x['codigo_individual']) ?></td>
                <td><?= e($x['titulo']) ?><small><?= e((string)$x['autores']) ?></small></td><td><small><?= e(trim(($x['editora'] ?? '') . ($x['edicao'] ? ' · ' . $x['edicao'] : ''))) ?></small></td>
                <td><?php if ($x['carimbado_em']): ?><span class="adm-status adm-tone--green">Carimbado</span><br><small><?= e($x['carimbado_nome']) ?></small>
                    <?php elseif ($comOutro): ?><span class="adm-status adm-tone--amber">Com <?= e($x['reservado_nome']) ?></span>
                    <?php elseif ($comigo): ?><span class="adm-status adm-tone--blue">Com você</span>
                    <?php else: ?><span class="adm-status adm-tone--muted">Pendente</span><?php endif; ?>
                    <?= (int)$x['problema_aberto'] ? '<br><span class="adm-status adm-tone--red">com problema</span>' : '' ?></td>
                <td class="reorg-acoes">
                    <?php if (!$x['carimbado_em'] && !$etapaPausada): ?>
                        <?php if (!$comigo && !$comOutro): ?><button type="button" class="adm-button adm-button--sm" data-reorg-reservar="<?= (int)$x['id'] ?>">Estou com este</button><?php endif; ?>
                        <?php if (!$comOutro): ?><button type="button" class="adm-button adm-button--sm adm-button--primary" data-reorg-carimbar="<?= (int)$x['id'] ?>" data-codigo="<?= e($x['codigo_individual']) ?>"><?= icon('stamp') ?> Carimbado</button><?php endif; ?>
                    <?php endif; ?>
                    <button type="button" class="adm-button adm-button--sm" data-reorg-problema data-exemplar="<?= (int)$x['id'] ?>" title="Informar problema"><?= icon('flag') ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
