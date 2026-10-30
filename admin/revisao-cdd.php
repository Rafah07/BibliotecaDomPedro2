<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/cdd.php';

$usuario = cdd_pagina_iniciar('cdd.ver');
$podeAprovar = auth_pode('cdd.aprovar');
$mostrar = ($_GET['mostrar'] ?? '') === 'sugeridas' ? 'sugeridas' : 'revisao';
$statusFila = $mostrar === 'sugeridas' ? ['sugerida'] : ['em_revisao'];

$fila = array_filter(cdd_itens(), fn ($i) => in_array($i['class']['status'], $statusFila, true));
// Mais antigos primeiro: quem espera há mais tempo é revisado antes.
uasort($fila, fn ($a, $b) => strcmp((string)$a['class']['atualizado_em'], (string)$b['class']['atualizado_em']) ?: $a['id'] <=> $b['id']);
$resumo = cdd_resumo();
$v = fn ($valor) => htmlspecialchars((string)$valor);

admin_page_start('classificacao', 'Fila de aprovação CDD', 'Acervo', 'Reclassificação bibliográfica pela CDD', [
    'assets/css/acervo.css?v=20261027',
    'assets/css/classificacao.css?v=20261027',
]);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Classificação CDD', admin_url('classificacao')], ['Fila de aprovação']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Fila de aprovação</h1>
        <p>Confira cada sugestão com o livro e a fonte antes de aprovar. Nada é aplicado sem a sua validação.</p>
    </div>
</section>

<?= cdd_abas('revisao-cdd') ?>
<?= cdd_aviso_modo() ?>

<div class="adm-segmented adm-segmented--links" role="group" aria-label="O que mostrar">
    <a href="<?= htmlspecialchars(admin_url('revisao-cdd')) ?>" class="<?= $mostrar === 'revisao' ? 'is-active' : '' ?>"<?= $mostrar === 'revisao' ? ' aria-current="page"' : '' ?>>Aguardando aprovação <span class="adm-tabs__count"><?= $resumo['em_revisao'] ?></span></a>
    <a href="<?= htmlspecialchars(admin_url('revisao-cdd') . '?mostrar=sugeridas') ?>" class="<?= $mostrar === 'sugeridas' ? 'is-active' : '' ?>"<?= $mostrar === 'sugeridas' ? ' aria-current="page"' : '' ?>>Sugestões ainda não enviadas <span class="adm-tabs__count"><?= $resumo['sugerida'] ?></span></a>
</div>

<?php if (!$podeAprovar): ?>
    <div class="adm-notice adm-notice--blue adm-notice--compact"><?= icon('lock') ?><div><strong>Você pode consultar a fila, mas apenas a bibliotecária ou um administrador pode aprovar, corrigir ou rejeitar.</strong></div></div>
<?php endif; ?>

<?php if (!$fila): ?>
    <section class="adm-card adm-panel">
        <div class="adm-empty">
            <?= icon('check-check') ?>
            <strong><?= $mostrar === 'revisao' ? 'Nenhuma classificação aguardando aprovação' : 'Nenhuma sugestão pendente de envio' ?></strong>
            <span>Quando alguém enviar uma sugestão para revisão, ela aparecerá aqui.</span>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars(admin_url('classificacao')) ?>"><?= icon('arrow-left') ?> Voltar ao painel</a>
        </div>
    </section>
<?php else: ?>
    <p class="adm-queue-count" aria-live="polite" data-fila-contagem><?= count($fila) ?> <?= count($fila) === 1 ? 'livro' : 'livros' ?> na fila</p>
    <div class="adm-queue" data-fila>
        <?php foreach ($fila as $item): ?>
            <?php $c = $item['class']; $anterior = $c['cdd_aprovada'] ?? $c['cdd_original']; ?>
            <article class="adm-card adm-queue__item" data-fila-item data-livro-id="<?= $item['id'] ?>" data-versao="<?= (int)$c['versao'] ?>" data-cdd="<?= $v($c['cdd_proposta']) ?>" data-titulo="<?= $v($item['titulo']) ?>">
                <div class="adm-queue__book">
                    <?= acervo_capa(['titulo' => $item['titulo'], 'capa' => $item['capa'], 'cor' => $item['cor'], 'autor_principal' => $item['autor']], 'md') ?>
                    <div>
                        <h2><a class="adm-book-link" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . $item['id']) ?>"><?= $v($item['titulo']) ?></a></h2>
                        <?php if ($item['subtitulo']): ?><p class="adm-table__muted"><?= $v($item['subtitulo']) ?></p><?php endif; ?>
                        <p><?= $v($item['autores'] ?: 'Autor não informado') ?></p>
                        <p class="adm-table__muted"><?= $v(implode(' · ', array_filter([$item['editora'], $item['ano'], $item['edicao'], $item['isbn'] ? 'ISBN ' . $item['isbn'] : null]))) ?: 'Dados de publicação não informados' ?></p>
                        <?php if ($item['assunto']): ?><p class="adm-table__muted">Assunto: <?= $v($item['assunto']) ?></p><?php endif; ?>
                    </div>
                </div>
                <div class="adm-queue__cdd">
                    <div class="adm-compare">
                        <div><span>CDD anterior</span><?= cdd_numero($anterior, 'Sem CDD') ?></div>
                        <?= icon('arrow-right') ?>
                        <div><span>CDD proposta</span><strong class="adm-cdd adm-cdd--lg"><?= $v($c['cdd_proposta']) ?></strong></div>
                    </div>
                    <dl class="adm-meta adm-meta--stack">
                        <div><dt>Descrição</dt><dd><?= $c['descricao'] ? $v($c['descricao']) : '<span class="adm-missing">Não informada</span>' ?></dd></div>
                        <div><dt>Fonte</dt><dd><?= $v($c['fonte_nome'] ?? '—') ?><?php if ($c['fonte_url']): ?> · <a class="adm-link" href="<?= $v($c['fonte_url']) ?>" target="_blank" rel="noopener noreferrer">abrir registro <?= icon('external-link') ?></a><?php endif; ?><?= $c['data_consulta'] ? '<br><small>Consultada em ' . cdd_data_hora($c['data_consulta']) . '</small>' : '' ?></dd></div>
                        <div><dt>Responsável pela sugestão</dt><dd><?= $v(cdd_usuario_nome((int)$c['sugerido_por'])) ?><?= $c['origem'] === 'consulta' ? ' <span class="adm-chip">a partir de pesquisa</span>' : ($c['origem'] === 'manual' ? ' <span class="adm-chip">manual</span>' : '') ?></dd></div>
                        <?php if ($c['justificativa']): ?><div><dt>Justificativa</dt><dd><?= nl2br($v($c['justificativa'])) ?></dd></div><?php endif; ?>
                        <?php if ($c['observacoes']): ?><div><dt>Observações</dt><dd><?= nl2br($v($c['observacoes'])) ?></dd></div><?php endif; ?>
                    </dl>
                </div>
                <div class="adm-queue__actions">
                    <?php if ($podeAprovar): ?>
                        <button type="button" class="adm-button adm-button--success adm-button--sm" data-revisao="aprovar"><?= icon('check-check') ?> Aprovar</button>
                        <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-revisao="corrigir"><?= icon('pencil') ?> Corrigir</button>
                        <button type="button" class="adm-button adm-button--ghost adm-button--sm adm-button--danger-text" data-revisao="rejeitar"><?= icon('circle-x') ?> Rejeitar</button>
                        <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-revisao="nova_pesquisa"><?= icon('refresh-cw') ?> Solicitar nova pesquisa</button>
                    <?php endif; ?>
                    <?php if (auth_pode('cdd.sugerir')): ?>
                        <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-revisao="observacao"><?= icon('message-square-text') ?> Adicionar observação</button>
                    <?php endif; ?>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . $item['id']) ?>"><?= icon('eye') ?> Revisão completa</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
echo cdd_json_script(['pagina' => 'revisao']);
admin_page_end(['assets/js/acervo.js?v=20261027', 'assets/js/classificacao.js?v=20261027']);
