<?php
/**
 * Área de trabalho do Grupo 3 (carimbação): planilha do lote na largura toda, com o código BDP em
 * destaque. Sem redigitar códigos: o código vem do cadastro e o participante só marca “Carimbado”.
 * Variáveis: $r, $sessao, $lote, $c, $base, $etapaPausada.
 */
$et = $r->etapas();
$filtro = (string)($_GET['f'] ?? 'pendentes');
$filtro = in_array($filtro, ['pendentes', 'carimbados', 'problemas', 'todos'], true) ? $filtro : 'pendentes';
$planilha = $et->planilhaCarimbacao((int)$lote['id'], $filtro);
$previa = $et->previaEncaminhamentoG4((int)$lote['id']);
$busca = trim((string)($_GET['q'] ?? ''));
if ($busca !== '') {
    $b = mb_strtolower($busca);
    $planilha = array_values(array_filter($planilha, fn ($x) => str_contains(mb_strtolower($x['codigo_individual'] . ' ' . $x['titulo'] . ' ' . ($x['autores'] ?? '') . ' ' . ($x['editora'] ?? '')), $b)));
}
?>
<section class="adm-card reorg-g1-topo">
    <div class="reorg-g1-topo__lote">
        <h1><?= e($lote['nome']) ?> · Carimbação</h1>
        <dl class="reorg-numeros reorg-numeros--linha">
            <div><dt>Com código BDP</dt><dd><?= $c['codigos'] ?></dd></div>
            <div><dt>Aguardando carimbo</dt><dd><?= $c['aguardando_carimbacao'] ?></dd></div>
            <div><dt>Carimbados</dt><dd><?= $c['carimbados'] ?></dd></div>
            <div><dt>Prevista no lote</dt><dd><?= $c['prevista'] ?></dd></div>
        </dl>
        <?= reorg_barra($c['carimbados'], $c['prevista'], $c['carimbados'] . ' de ' . $c['prevista'] . ' carimbados') ?>
    </div>
    <div class="reorg-g1-topo__salvo">
        <button type="button" class="adm-button adm-button--primary" data-reorg-encaminhar="g4" <?= $etapaPausada || !$previa['exemplares'] ? 'disabled' : '' ?>><?= icon('send') ?> Encaminhar carimbados à conferência (<?= $previa['exemplares'] ?>)</button>
    </div>
</section>
<section class="adm-card adm-panel">
    <div class="reorg-filtros-linha">
        <nav class="adm-tabs adm-tabs--links" aria-label="Filtro da planilha">
            <?php foreach (['pendentes' => 'Aguardando carimbo', 'carimbados' => 'Carimbados', 'problemas' => 'Com problema', 'todos' => 'Todos'] as $k => $rot): ?>
                <a class="adm-tab<?= $filtro === $k ? ' is-active' : '' ?>" href="<?= e($base . '?lote=' . $lote['id'] . '&f=' . $k) ?>"<?= $filtro === $k ? ' aria-current="page"' : '' ?>><?= e($rot) ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="get" class="reorg-busca" role="search"><input type="hidden" name="lote" value="<?= (int)$lote['id'] ?>"><input type="hidden" name="f" value="<?= e($filtro) ?>">
            <input class="adm-input" type="search" name="q" value="<?= e($busca) ?>" placeholder="Código, título, autor ou editora" aria-label="Procurar na planilha"><button class="adm-button adm-button--sm" type="submit"><?= icon('search') ?></button></form>
    </div>
    <p class="adm-field__hint">Escreva no livro o código BDP mostrado e clique em “Carimbado”. “Estou com este” avisa os colegas que o livro está com você.</p>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table reorg-planilha" data-reorg-lista>
        <thead><tr><th>Código BDP</th><th>Título</th><th>Autor</th><th>Editora</th><th>Edição</th><th>Conservação</th><th>Situação</th><th>Ações</th></tr></thead>
        <tbody>
        <?php if (!$planilha): ?><tr><td colspan="8" class="adm-table__muted">Nada neste filtro<?= $filtro === 'pendentes' && $busca === '' ? ' — aguardando novos códigos BDP do Grupo 2' : '' ?>.</td></tr><?php endif; ?>
        <?php foreach ($planilha as $x): $comOutro = $x['reservado_nome'] && (int)$x['reservado_sessao_id'] !== (int)$sessao['id']; $comigo = $x['reservado_nome'] && !$comOutro; ?>
            <tr data-reorg-item data-id="<?= (int)$x['id'] ?>" class="<?= $comigo ? 'is-ativo' : '' ?>">
                <td class="reorg-codigo"><?= e($x['codigo_individual']) ?></td>
                <td><?= e($x['titulo']) ?></td>
                <td><?= e((string)$x['autores']) ?></td>
                <td><?= e((string)$x['editora']) ?></td>
                <td><?= e((string)$x['edicao']) ?></td>
                <td><?= reorg_conservacao_badge($x['estado_conservacao']) ?></td>
                <td><?php if ($x['carimbado_em']): ?><span class="adm-status adm-tone--green">Carimbado</span><br><small><?= e($x['carimbado_nome']) ?></small>
                    <?php elseif ($comOutro): ?><span class="adm-status adm-tone--amber">Com <?= e($x['reservado_nome']) ?></span>
                    <?php elseif ($comigo): ?><span class="adm-status adm-tone--blue">Com você</span>
                    <?php else: ?><span class="adm-status adm-tone--muted">Aguardando</span><?php endif; ?>
                    <?= (int)$x['problema_aberto'] ? '<br><span class="adm-status adm-tone--red">com problema</span>' : '' ?></td>
                <td class="reorg-acoes">
                    <?php if (!$x['carimbado_em'] && !$etapaPausada): ?>
                        <?php if (!$comigo && !$comOutro): ?><button type="button" class="adm-button adm-button--sm" data-reorg-reservar="<?= (int)$x['id'] ?>">Estou com este</button><?php endif; ?>
                        <?php if (!$comOutro): ?><button type="button" class="adm-button adm-button--sm adm-button--primary" data-reorg-carimbar="<?= (int)$x['id'] ?>" data-codigo="<?= e($x['codigo_individual']) ?>"><?= icon('stamp') ?> Carimbado</button><?php endif; ?>
                    <?php endif; ?>
                    <button type="button" class="adm-button adm-button--sm" data-reorg-problema data-exemplar="<?= (int)$x['id'] ?>" title="Informar problema" aria-label="Informar problema com <?= e($x['codigo_individual']) ?>"><?= icon('flag') ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
