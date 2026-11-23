<?php
/**
 * Versões oficiais da Planilha BDP de um lote (migração 190).
 *   ?lote=ID                       versões emitidas, situação (atual ou desatualizada) e "Emitir versão"
 *   ?versao=ID                     ver / reimprimir exatamente o que foi emitido (com aviso se há versão mais nova)
 *   ?versao=ID&imprimir=1          folha A4 para impressão (lote, versão, data, quantidade e páginas)
 *   ?versao=ID&comparar=ID|atual   diferenças entre duas versões, ou entre a versão e o lote agora
 *
 * Emitir é a única ação que grava: confirmar carimbo, consultar ou reimprimir não cria versão nova.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\PlanilhaBdpVersaoService as Versoes;

$usuario = fisico_iniciar();
$servico = new Versoes(db());
$base = admin_url('planilha-bdp-versoes');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        fisico_exigir('exemplares.gerenciar');
        $loteId = (int)admin_param($_POST, 'lote_id');
        try {
            $r = $servico->emitir($loteId, (int)$usuario['id'], admin_param($_POST, 'motivo'));
            admin_flash('sucesso', $r['nova'] ? Versoes::rotulo($r['versao']['versao']) . ' emitida.' : 'Nada mudou desde a ' . Versoes::rotulo($r['versao']['versao']) . '.',
                $r['nova'] ? 'A versão ficou registrada com a data, o responsável e a cópia exata das linhas. Os códigos BDP não mudaram.' : 'Nenhuma versão nova foi criada: reimprima a versão atual.');
            admin_redirecionar($base . '?versao=' . (int)$r['versao']['id'] . '&imprimir=1');
        } catch (AcervoException $e) {
            admin_flash('erro', 'A versão não foi emitida.', $e->getMessage());
            admin_redirecionar($base . '?lote=' . $loteId);
        }
    }

    $versao = (int)admin_param($_GET, 'versao') ? $servico->versao((int)admin_param($_GET, 'versao')) : null;
    $loteId = $versao ? (int)$versao['lote_id'] : (int)admin_param($_GET, 'lote');
    $lote = $loteId ? lotes_repo()->lote($loteId) : null;
    if (!$lote) {
        http_response_code(404);
        admin_pagina_erro('Lote não encontrado', 'Escolha o lote no Histórico de Lotes ou na Planilha de Códigos BDP.', 'layers');
        exit;
    }
    $situacao = $servico->situacao($loteId);
    $versoes = $servico->versoes($loteId);
    $ultimaId = $situacao['ultima']['id'] ?? null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir as versões da planilha', fisico_erro_tecnico($e, 'versões da planilha BDP') . ' Confira se a migração 190 foi aplicada.');
    exit;
}

// Situação de uma versão específica: é a última? a última ainda vale?
$estadoVersao = function (array $v) use ($ultimaId, $situacao): array {
    if ((int)$v['id'] !== (int)$ultimaId) {
        return ['Substituída por versão mais recente', 'muted', true];
    }
    return $situacao['desatualizada'] ? ['Desatualizada — o lote mudou depois da emissão', 'amber', true] : ['Versão atual', 'green', false];
};

// ---------- Folha de impressão ----------
if ($versao && admin_param($_GET, 'imprimir') === '1') {
    [$rotEstado, , $desatualizada] = $estadoVersao($versao);
    registrar_acao('planilha_bdp.imprimir_versao', 'lote', $loteId, ['versao' => (int)$versao['versao']]);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    $nomeLote = LoteRepository::nome($lote);
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($nomeLote) ?> — Planilha BDP — <?= e(Versoes::rotulo($versao['versao'])) ?></title>
<style>
    @page { size: A4 portrait; margin: 12mm 10mm 14mm; @bottom-right { content: "Página " counter(page) " de " counter(pages); font: 7.5pt Arial, sans-serif; color: #555; } @bottom-left { content: "<?= e($nomeLote . ' · ' . Versoes::rotulo($versao['versao'])) ?>"; font: 7.5pt Arial, sans-serif; color: #555; } }
    * { box-sizing: border-box; }
    body { margin: 0; font: 9pt/1.3 Arial, Helvetica, sans-serif; color: #111; background: #fff; }
    .barra { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 10px 14px; background: #eef2fb; border-bottom: 1px solid #c9d3ea; font-size: 10pt; }
    .barra button, .barra a { font: inherit; padding: 6px 12px; border: 1px solid #2457c5; border-radius: 6px; background: #2457c5; color: #fff; text-decoration: none; cursor: pointer; }
    .barra a { background: #fff; color: #2457c5; }
    .folha { padding: 8mm 10mm; max-width: 210mm; margin: 0 auto; }
    h1 { font-size: 13pt; margin: 0 0 2px; }
    .meta { margin: 0 0 6px; color: #333; font-size: 8.5pt; }
    .alerta { margin: 6px 0; padding: 6px 8px; border: 2px solid #b45309; background: #fffbeb; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #999; padding: 2.5px 4px; vertical-align: top; text-align: left; overflow-wrap: anywhere; }
    th { background: #f6f6f6; font-size: 7.5pt; }
    thead { display: table-header-group; }
    td.ordem { text-align: center; font-weight: bold; }
    td.codigo { font: bold 10.5pt "Courier New", monospace; white-space: nowrap; }
    td.marcar { text-align: center; font-size: 12pt; }
    .pequeno { font-size: 7.5pt; color: #444; }
    tr { break-inside: avoid; }
    @media print { .barra { display: none; } .folha { padding: 0; max-width: none; } }
</style>
</head>
<body>
<div class="barra"><button type="button" onclick="window.print()">Imprimir</button> <a href="<?= e($base . '?lote=' . $loteId) ?>">Voltar às versões do lote</a>
    <span><?= e($rotEstado) ?></span></div>
<div class="folha">
    <h1>Planilha BDP — <?= e($nomeLote) ?> — <?= e(Versoes::rotulo($versao['versao'])) ?></h1>
    <p class="meta"><?= e(etiquetas_biblioteca()) ?> · Emitida em <?= e(date('d/m/Y H:i', strtotime($versao['emitida_em']))) ?><?= $versao['emitida_por_nome'] ? ' por ' . e($versao['emitida_por_nome']) : '' ?> · <?= (int)$versao['total'] ?> exemplar(es)
        · Reimpressa em <?= e(date('d/m/Y H:i')) ?><?= $versao['motivo'] ? ' · Motivo: ' . e($versao['motivo']) : '' ?></p>
    <?php if ($desatualizada): ?>
        <p class="alerta">ATENÇÃO: esta versão está desatualizada. Existe uma versão mais recente da planilha BDP deste lote (ou o lote mudou depois da emissão). Confira no sistema antes de carimbar.</p>
    <?php endif; ?>
    <table>
        <colgroup><col style="width:7%"><col style="width:15%"><col style="width:36%"><col style="width:25%"><col style="width:8%"><col style="width:9%"></colgroup>
        <thead><tr><th>Ordem</th><th>Código BDP</th><th>Título</th><th>Autor</th><th>Carimbado</th><th>Obs.</th></tr></thead>
        <tbody>
        <?php foreach ($versao['linhas'] as $l): ?>
            <tr>
                <td class="ordem"><?= (int)$l['ordem'] ?></td>
                <td class="codigo"><?= e($l['codigo']) ?></td>
                <td><?= e(mb_strimwidth((string)$l['titulo'], 0, 120, '…')) ?><?= ($l['edicao'] ?? '') !== '' ? '<div class="pequeno">' . e($l['edicao']) . '</div>' : '' ?></td>
                <td><?= e(mb_strimwidth((string)$l['autores'], 0, 70, '…')) ?></td>
                <td class="marcar">☐</td>
                <td></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="pequeno">Escreva cada código no carimbo do livro da MESMA linha (mesma ordem). Os códigos BDP são definitivos e não mudam entre versões.</p>
</div>
</body>
</html>
<?php
    exit;
}

$comparar = $versao ? admin_param($_GET, 'comparar') : '';
$diferencas = null;
$comRotulo = '';
if ($versao && $comparar !== '') {
    if ($comparar === 'atual') {
        $diferencas = Versoes::diferencas($versao['linhas'], $servico->conteudoAtual($loteId));
        $comRotulo = 'o lote agora';
    } elseif (($outra = $servico->versao((int)$comparar)) && (int)$outra['lote_id'] === $loteId) {
        [$antes, $depois] = (int)$outra['versao'] < (int)$versao['versao'] ? [$outra, $versao] : [$versao, $outra];
        $diferencas = Versoes::diferencas($antes['linhas'], $depois['linhas']);
        $comRotulo = Versoes::rotulo($antes['versao']) . ' → ' . Versoes::rotulo($depois['versao']);
    }
}

admin_page_start('planilha-bdp', 'Versões da Planilha BDP', 'Exemplares', 'Planilha BDP do lote', array_merge(FISICO_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Planilha de Códigos BDP', admin_url('planilha-bdp') . '?aba=lotes'], [LoteRepository::nome($lote), admin_url('lote') . '?id=' . $loteId], ['Versões da planilha']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Planilha BDP — <?= e(LoteRepository::nome($lote)) ?></h1>
        <p>Cada emissão oficial fica registrada com a cópia exata das linhas. Reimprimir uma versão antiga mostra o que foi impresso naquela data.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('lote') . '?id=' . $loteId) ?>"><?= icon('layers') ?> Relatório do lote</a>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('planilha-bdp') . '?aba=todos&lote=' . $loteId) ?>"><?= icon('stamp') ?> Planilha (lista atual)</a>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if ($situacao['desatualizada']): ?>
    <section class="adm-notice adm-notice--amber" role="alert">
        <?= icon('triangle-alert') ?>
        <div class="adm-notice__content">
            <strong>A <?= e(Versoes::rotulo($situacao['ultima']['versao'])) ?> emitida está desatualizada.</strong>
            <p>Depois da emissão mudou algo que aparece na lista usada na carimbagem.<?= array_filter($situacao['diferencas'], fn ($d) => $d['fisico']) ? ' <strong>Há mudança que afeta a identificação física</strong> (ordem, código ou obra de um exemplar): avise a equipe de carimbagem.' : ' As mudanças são só de texto (título ou autor): a ordem e os códigos continuam valendo.' ?></p>
            <ul class="adm-versao-diff">
                <?php foreach (array_slice($situacao['diferencas'], 0, 12) as $d): ?><li class="is-<?= e($d['tipo']) ?>"><?= e($d['texto']) ?></li><?php endforeach; ?>
                <?php if (count($situacao['diferencas']) > 12): ?><li>… e mais <?= count($situacao['diferencas']) - 12 ?> alteração(ões).</li><?php endif; ?>
            </ul>
        </div>
    </section>
<?php endif; ?>

<?php if ($versao): [$rotEstado, $tomEstado, $vDesatualizada] = $estadoVersao($versao); ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-versao">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-versao"><?= e(Versoes::rotulo($versao['versao'])) ?> <span class="adm-status adm-tone--<?= $tomEstado ?>"><?= e($rotEstado) ?></span></h2>
            <p>Emitida em <?= e(admin_data_hora($versao['emitida_em'])) ?><?= $versao['emitida_por_nome'] ? ' por ' . e($versao['emitida_por_nome']) : '' ?> · <?= (int)$versao['total'] ?> exemplar(es)<?= $versao['motivo'] ? ' · ' . e($versao['motivo']) : '' ?></p>
        </div>
            <div class="adm-actions-inline">
                <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '?versao=' . (int)$versao['id'] . '&imprimir=1') ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Reimprimir esta versão</a>
                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?versao=' . (int)$versao['id'] . '&comparar=atual') ?>"><?= icon('git-compare') ?> Comparar com o lote agora</a>
            </div></div>
        <?php if ($vDesatualizada): ?><p class="adm-field__hint"><?= icon('info') ?> Existe uma versão mais recente da planilha BDP deste lote (ou o lote mudou depois desta emissão). Esta é a cópia histórica, como foi impressa.</p><?php endif; ?>
        <?php if ($diferencas !== null): ?>
            <h3 class="adm-subtitle">Diferenças: <?= e($comRotulo) ?></h3>
            <?php if (!$diferencas): ?><p class="adm-field__hint">Nenhuma diferença relevante para a carimbagem.</p><?php else: ?>
                <ul class="adm-versao-diff"><?php foreach ($diferencas as $d): ?><li class="is-<?= e($d['tipo']) ?>"><?= e($d['texto']) ?><?= $d['fisico'] ? ' <strong>(afeta a identificação física)</strong>' : '' ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        <?php endif; ?>
        <div class="adm-table-wrap">
            <table class="adm-table adm-table--compact">
                <thead><tr><th scope="col">Ordem</th><th scope="col">Código BDP</th><th scope="col">Título (como emitido)</th><th scope="col">Autor</th></tr></thead>
                <tbody><?php foreach ($versao['linhas'] as $l): ?><tr><td data-label="Ordem"><?= (int)$l['ordem'] ?></td><td data-label="Código" class="adm-mono"><?= e($l['codigo']) ?></td><td data-label="Título"><?= e($l['titulo']) ?></td><td data-label="Autor"><?= e($l['autores']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-versoes">
    <div class="adm-panel__header"><div><h2 id="titulo-versoes">Versões emitidas</h2><p><?= $versoes ? count($versoes) . ' versão(ões). A mais recente aparece primeiro.' : 'Nenhuma versão oficial emitida ainda.' ?></p></div></div>
    <?php if (auth_pode('exemplares.gerenciar')): ?>
        <form method="post" class="adm-form-inline" data-enviar-uma-vez>
            <?= csrf_campo() ?><input type="hidden" name="lote_id" value="<?= $loteId ?>">
            <?php if ($situacao['ultima']): ?>
                <label class="adm-field"><span class="adm-field__sublabel">Motivo da atualização <span class="adm-optional">opcional (sem motivo, as diferenças são registradas)</span></span><input class="adm-input" name="motivo" maxlength="300" placeholder="Ex.: correção do autor de Dom Casmurro"></label>
            <?php endif; ?>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('printer') ?> <?= !$situacao['ultima'] ? 'Emitir Versão 01 e imprimir' : ($situacao['desatualizada'] ? 'Emitir versão atualizada e imprimir' : 'Imprimir versão atual') ?></button>
            <p class="adm-field__hint"><?= $situacao['ultima'] && !$situacao['desatualizada'] ? 'Nada mudou desde a última emissão: nenhuma versão nova será criada.' : 'O número da versão é definido pelo servidor.' ?></p>
        </form>
    <?php endif; ?>
    <?php if ($versoes): ?>
        <ul class="adm-gestao-lista">
            <?php foreach ($versoes as $v): [$rot, $tom] = $estadoVersao($v); ?>
                <li class="adm-gestao-item">
                    <span class="adm-gestao-item__icone adm-tone--<?= $tom ?>"><?= icon('file-text') ?></span>
                    <div class="adm-gestao-item__corpo">
                        <strong><?= e(Versoes::rotulo($v['versao'])) ?></strong> <span class="adm-status adm-tone--<?= $tom ?>"><?= e($rot) ?></span>
                        <p><?= e(admin_data_hora($v['emitida_em'])) ?><?= $v['emitida_por_nome'] ? ' · ' . e($v['emitida_por_nome']) : '' ?> · <?= (int)$v['total'] ?> exemplar(es)<?= $v['motivo'] ? ' · ' . e($v['motivo']) : '' ?></p>
                    </div>
                    <div class="adm-gestao-item__acoes">
                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?versao=' . (int)$v['id']) ?>"><?= icon('eye') ?> Ver</a>
                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?versao=' . (int)$v['id'] . '&imprimir=1') ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Reimprimir</a>
                        <?php if ((int)$v['versao'] > 1): $anterior = array_values(array_filter($versoes, fn ($x) => (int)$x['versao'] === (int)$v['versao'] - 1))[0] ?? null; ?>
                            <?php if ($anterior): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?versao=' . (int)$v['id'] . '&comparar=' . (int)$anterior['id']) ?>"><?= icon('git-compare') ?> Comparar com a anterior</a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php
admin_page_end(FISICO_JS);
