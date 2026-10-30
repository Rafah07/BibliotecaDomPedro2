<?php
/**
 * Seção "Classificação Bibliográfica" da página de detalhes do livro.
 * Lê os mesmos registros do módulo de classificação CDD (sem duplicar dados).
 * Espera $livro (array do acervo).
 */
require_once __DIR__ . '/cdd.php';

try {
    $cddItem = cdd_item((int)$livro['id']);
    $cddHistorico = $cddItem ? cdd_historico(['livro_id' => (int)$livro['id'], 'limite' => 4]) : [];
    $cddErro = null;
} catch (Throwable $e) {
    error_log('[biblioteca] Seção de classificação: ' . $e->getMessage());
    $cddItem = null;
    $cddHistorico = [];
    $cddErro = 'Não foi possível carregar a classificação agora.';
}
$cddC = $cddItem['class'] ?? null;
?>
<section class="adm-card adm-panel adm-cdd-section" aria-labelledby="titulo-classificacao-bib">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-classificacao-bib"><?= icon('list-tree') ?> Classificação Bibliográfica</h2>
            <p>Classificação Decimal de Dewey (CDD), validada pela bibliotecária.</p>
        </div>
        <a class="adm-button adm-button--primary adm-button--sm" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . (int)$livro['id']) ?>"><?= icon('book-marked') ?> Revisar classificação</a>
    </div>
    <?php if ($cddErro): ?>
        <p class="adm-missing"><?= htmlspecialchars($cddErro) ?></p>
    <?php elseif ($cddC): ?>
        <div class="adm-cdd-section__grid">
            <div>
                <?php if ($cddC['cdd_aprovada']): ?>
                    <p class="adm-cdd-big"><?= htmlspecialchars($cddC['cdd_aprovada']) ?></p>
                <?php else: ?>
                    <p class="adm-cdd-pending"><?= icon('hourglass') ?> Classificação pendente de revisão.</p>
                <?php endif; ?>
                <p><?= cdd_badge_status($cddC['status'], (bool)$cddC['rascunho']) ?></p>
            </div>
            <dl class="adm-meta adm-meta--stack">
                <div><dt>Situação de validação</dt><dd><?= htmlspecialchars(CDD_STATUS[$cddC['status']]['descricao']) ?><?= $cddC['cdd_proposta'] && $cddC['cdd_proposta'] !== $cddC['cdd_aprovada'] ? ' Proposta atual: <span class="adm-mono">' . htmlspecialchars($cddC['cdd_proposta']) . '</span>.' : '' ?></dd></div>
                <div><dt>Fonte</dt><dd><?= htmlspecialchars($cddC['aprovada_fonte'] ?? $cddC['fonte_nome'] ?? '—') ?></dd></div>
                <div><dt>Data de aprovação</dt><dd><?= cdd_data_hora($cddC['aprovado_em']) ?></dd></div>
                <div><dt>CDD da planilha antiga</dt><dd><?= cdd_numero($cddC['cdd_original'], 'Não informada') ?></dd></div>
            </dl>
            <div>
                <h3 class="adm-subtitle"><?= icon('history') ?> Histórico</h3>
                <?php if (!$cddHistorico): ?>
                    <p class="adm-missing">Nenhuma alteração registrada.</p>
                <?php else: ?>
                    <ul class="adm-simple-list">
                        <?php foreach ($cddHistorico as $h): ?>
                            <li><strong><?= CDD_HISTORICO_TIPOS[$h['tipo']]['label'] ?></strong><?= $h['cdd_nova'] ? ' · <span class="adm-mono">' . htmlspecialchars($h['cdd_nova']) . '</span>' : '' ?><small><?= cdd_data_hora($h['criado_em']) ?> · <?= htmlspecialchars($h['responsavel'] ?? '—') ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="adm-link" href="<?= htmlspecialchars(admin_url('historico-cdd') . '?livro=' . (int)$livro['id']) ?>">Histórico completo <?= icon('arrow-right') ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
