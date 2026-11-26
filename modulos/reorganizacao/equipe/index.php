<?php
/**
 * Central de Equipes (participantes temporários). Fluxo: nome completo → instruções e escolha do
 * Grupo 1, 3 ou 4 → painel com os lotes atribuídos (aparecem sozinhos) → área de trabalho da equipe.
 */
require __DIR__ . '/../includes/equipe.php';

use Biblioteca\Reorganizacao\Levantamento;
use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;

reorg_equipe_cabecalhos();
reorg_equipe_sessao_php();
$base = reorg_url('equipe/');

if (!reorg_modulo_ativo()) {
    http_response_code(404);
    reorg_equipe_inicio('Indisponível');
    echo '<section class="adm-card adm-panel reorg-centro"><h1>Página indisponível</h1><p>A reorganização do acervo não está em andamento.</p></section>';
    reorg_equipe_fim();
    exit;
}
$r = reorg();
$periodo = $r->periodoAtivo();
if (!$periodo) {
    unset($_SESSION['reorg_token']);
    reorg_equipe_inicio('Reorganização encerrada');
    echo '<section class="adm-card adm-panel reorg-centro"><h1>Não há período de reorganização ativo</h1><p>O acesso da equipe só funciona durante o período definido pela biblioteca. Obrigado!</p>'
        . '<a class="adm-link" href="' . e(url('admin/login.php')) . '">Voltar</a></section>';
    reorg_equipe_fim();
    exit;
}

$sessao = reorg_equipe_sessao();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!reorg_equipe_csrf_valido()) {
        reorg_equipe_flash('erro', 'A página ficou aberta por muito tempo. Tente novamente.');
        reorg_equipe_redirecionar($base);
    }
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'entrar' && !$sessao) {
            $x = $r->entrar((string)($_POST['nome'] ?? ''), reorg_equipe_ip());
            session_regenerate_id(true);
            $_SESSION['reorg_token'] = $x['token'];
        } elseif ($acao === 'equipe' && $sessao) {
            $r->escolherEquipe($sessao, (string)($_POST['equipe'] ?? ''));
        }
    } catch (AcervoException $e) {
        reorg_equipe_flash('erro', $e->getMessage());
    }
    reorg_equipe_redirecionar($base);
}

// ------------------------------------------------------------------ identificação
if (!$sessao) {
    $motivo = (string)($_GET['encerrada'] ?? '');
    reorg_equipe_inicio('Identificação');
    ?>
    <section class="adm-card adm-panel reorg-centro">
        <h1>Equipe de organização</h1>
        <p><?= e($periodo['nome']) ?></p>
        <?= reorg_equipe_flash_html() ?>
        <?php if ($motivo !== ''): ?><div class="adm-notice adm-notice--amber"><?= icon('info') ?><div><strong>Sua sessão anterior terminou.</strong><p>Para continuar, informe o nome de novo. As permissões anteriores não são recuperadas: o administrador atribui os lotes novamente.</p></div></div><?php endif; ?>
        <form method="post" class="reorg-form">
            <input type="hidden" name="csrf" value="<?= e(reorg_equipe_csrf()) ?>"><input type="hidden" name="acao" value="entrar">
            <label class="adm-field"><span class="adm-field__label">Seu nome completo</span>
                <input class="adm-input reorg-input-grande" name="nome" required minlength="3" maxlength="100" autocomplete="name" autofocus></label>
            <p class="adm-field__hint">Não há senha nem cadastro. O nome identifica o seu trabalho no histórico da biblioteca.</p>
            <button class="adm-button adm-button--primary adm-button--block" type="submit"><?= icon('log-in') ?> Continuar</button>
        </form>
    </section>
    <?php
    reorg_equipe_fim();
    exit;
}

// ------------------------------------------------------------------ escolha da equipe
if ($sessao['equipe'] === null) {
    reorg_equipe_inicio('Escolha a equipe', $sessao);
    ?>
    <section class="adm-card adm-panel">
        <h1>Olá, <?= e($sessao['nome']) ?>!</h1>
        <?= reorg_equipe_flash_html() ?>
        <?php if ($periodo['instrucoes_gerais']): ?><div class="reorg-instrucoes"><?= nl2br(e($periodo['instrucoes_gerais'])) ?></div><?php endif; ?>
        <p>Escolha a equipe em que você vai trabalhar. Para mudar depois, será preciso pedir ao administrador.</p>
        <div class="reorg-cartoes">
            <?php foreach (['grupo1' => ['Grupo 1 — Levantamento', 'Preencher os dados de cada livro (título, autor, editora, ISBN, CDD) e o estado de conservação de cada exemplar.', 'book-plus'],
                'grupo3' => ['Grupo 3 — Carimbação', 'Escrever o código BDP no livro e marcar como carimbado na planilha do lote.', 'stamp'],
                'grupo4' => ['Grupo 4 — Conferência e organização', 'Digitar o código escrito no livro, comparar as informações e guardar na estante.', 'clipboard-check']] as $eq => [$titulo, $texto, $ic]): ?>
                <form method="post" class="reorg-cartao">
                    <input type="hidden" name="csrf" value="<?= e(reorg_equipe_csrf()) ?>"><input type="hidden" name="acao" value="equipe"><input type="hidden" name="equipe" value="<?= $eq ?>">
                    <span class="adm-icon-box adm-tone--blue"><?= icon($ic) ?></span><h2><?= e($titulo) ?></h2><p><?= e($texto) ?></p>
                    <?php if ($periodo['instrucoes_' . $eq]): ?><details><summary>Instruções desta equipe</summary><p><?= nl2br(e($periodo['instrucoes_' . $eq])) ?></p></details><?php endif; ?>
                    <button class="adm-button adm-button--primary" type="submit">Escolher</button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    reorg_equipe_fim();
    exit;
}

// ------------------------------------------------------------------ painel
$equipe = $sessao['equipe'];
$lotes = $r->lotesDaSessao((int)$sessao['id']);
$loteId = (int)($_GET['lote'] ?? 0);
$lote = null;
foreach ($lotes as $l) {
    if ((int)$l['id'] === $loteId) {
        $lote = $l;
    }
}
if (!$lote && count($lotes) === 1 && !isset($_GET['lotes'])) {
    $lote = $lotes[0];
}
$pausada = $sessao['situacao'] === 'pausada';
$etapaPausada = $lote && ($lote['status'] !== 'em_andamento' || in_array($equipe, explode(',', (string)$lote['etapas_pausadas']), true));

reorg_equipe_inicio($lote ? $lote['nome'] : 'Meus lotes', $sessao, [
    'equipe' => $equipe, 'lote' => $lote['id'] ?? '', 'base' => $base, 'pausada' => $pausada ? '1' : '0',
    'assinatura' => $r->estadoParticipante($sessao)['assinatura'],
]);
echo reorg_equipe_flash_html();

if ($pausada) {
    ?>
    <section class="adm-card adm-panel reorg-centro">
        <h1><?= icon('pause') ?> Atividade pausada</h1>
        <p>Tudo o que você fez está salvo. Quando voltar, clique em continuar (a sessão continua aberta enquanto esta página estiver aberta).</p>
        <button class="adm-button adm-button--primary" type="button" data-reorg-retomar><?= icon('play') ?> Continuar o trabalho</button>
    </section>
    <?php
    reorg_equipe_fim();
    exit;
}
?>
<div class="reorg-painel">
    <aside class="adm-card adm-panel reorg-lateral">
        <h2>Meus lotes</h2>
        <?php if (!$lotes): ?>
            <p class="adm-table__muted" data-reorg-sem-lote>Nenhum lote atribuído ainda. Assim que o administrador atribuir, ele aparece aqui sozinho.</p>
        <?php endif; ?>
        <ul class="reorg-lotes-lista">
            <?php foreach ($lotes as $l): $c = $r->contadores((int)$l['id']); ?>
                <li class="<?= $lote && (int)$lote['id'] === (int)$l['id'] ? 'is-ativo' : '' ?>"><a href="<?= e($base . '?lote=' . $l['id']) ?>"><strong><?= e($l['nome']) ?></strong>
                    <small><?= $l['status'] === 'pausado' ? 'Pausado · ' : '' ?><?= $equipe === 'grupo1' ? $c['registrados'] . ' de ' . $c['prevista'] . ' registrados' : ($equipe === 'grupo3' ? $c['carimbados'] . ' de ' . $c['codigos'] . ' carimbados' : $c['conferidos'] . ' de ' . $c['carimbados'] . ' conferidos') ?></small></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="reorg-pedidos">
            <button type="button" class="adm-button adm-button--sm" data-reorg-pedido="novo_lote"><?= icon('plus') ?> Pedir novo lote</button>
            <button type="button" class="adm-button adm-button--sm" data-reorg-pedido="ajuda"><?= icon('life-buoy') ?> Pedir ajuda</button>
            <button type="button" class="adm-button adm-button--sm" data-reorg-pedido="troca_equipe"><?= icon('arrow-right-left') ?> Mudar de equipe</button>
        </div>
        <?php if ($periodo['instrucoes_gerais'] || $periodo['instrucoes_' . $equipe]): ?>
            <details class="reorg-instrucoes-detalhe"><summary>Instruções</summary><?= nl2br(e(trim($periodo['instrucoes_gerais'] . "\n\n" . $periodo['instrucoes_' . $equipe]))) ?></details>
        <?php endif; ?>
    </aside>

    <section class="reorg-trabalho">
    <?php if (!$lote): ?>
        <div class="adm-card adm-panel reorg-centro"><h2>Escolha um lote</h2><p><?= $lotes ? 'Clique em um dos lotes ao lado.' : 'Aguarde a atribuição de um lote pelo administrador ou use “Pedir novo lote”.' ?></p></div>
    <?php else: $c = $r->contadores((int)$lote['id']); ?>
        <div class="adm-card adm-panel">
            <div class="reorg-lote-topo"><h1><?= e($lote['nome']) ?></h1>
                <?php if ($etapaPausada): ?><span class="adm-status adm-tone--amber">Pausado pelo administrador</span><?php endif; ?></div>
            <?php if ($lote['instrucoes']): ?><div class="reorg-instrucoes"><?= nl2br(e($lote['instrucoes'])) ?></div><?php endif; ?>
            <div class="reorg-progresso">
                <span>Registrados <b><?= $c['registrados'] ?>/<?= $c['prevista'] ?></b></span><span>Códigos <b><?= $c['codigos'] ?></b></span>
                <span>Carimbados <b><?= $c['carimbados'] ?></b></span><span>Conferidos <b><?= $c['conferidos'] ?></b></span><span>Organizados <b><?= $c['organizados'] ?></b></span>
            </div>
        </div>
        <?php
        if ($equipe === 'grupo1') {
            require __DIR__ . '/../includes/equipe-grupo1.php';
        } elseif ($equipe === 'grupo3') {
            require __DIR__ . '/../includes/equipe-grupo3.php';
        } else {
            require __DIR__ . '/../includes/equipe-grupo4.php';
        }
        ?>
    <?php endif; ?>
    </section>
</div>
<template data-reorg-tpl="novo_lote"><h2>Pedir novo lote</h2><p>O administrador recebe o pedido e decide. Nenhum lote é atribuído automaticamente.</p>
    <label class="adm-field"><span class="adm-field__label">Observação (opcional)</span><textarea class="adm-textarea" name="descricao" rows="2" maxlength="1000"></textarea></label></template>
<template data-reorg-tpl="ajuda"><h2>Pedir ajuda</h2>
    <label class="adm-field"><span class="adm-field__label">Tipo do problema</span><select class="adm-select" name="problema_tipo"><?php foreach (Reorganizacao::TIPOS_PROBLEMA as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Lote</span><select class="adm-select" name="lote_id"><option value="">—</option><?php foreach ($lotes as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $lote && (int)$lote['id'] === (int)$l['id'] ? ' selected' : '' ?>><?= e($l['nome']) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Descreva a dúvida ou dificuldade</span><textarea class="adm-textarea" name="descricao" rows="3" required maxlength="1000"></textarea></label></template>
<template data-reorg-tpl="troca_equipe"><h2>Mudar de equipe</h2><p>A mudança só acontece depois da aprovação do administrador.</p>
    <label class="adm-field"><span class="adm-field__label">Equipe desejada</span><select class="adm-select" name="equipe_desejada"><?php foreach (Reorganizacao::EQUIPES_PARTICIPANTES as $eq): if ($eq === $equipe) continue; ?><option value="<?= $eq ?>"><?= e(Reorganizacao::EQUIPES[$eq]) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Motivo (opcional)</span><textarea class="adm-textarea" name="descricao" rows="2" maxlength="1000"></textarea></label></template>
<template data-reorg-tpl="problema"><h2>Informar problema</h2>
    <label class="adm-field"><span class="adm-field__label">Tipo</span><select class="adm-select" name="tipo"><?php foreach (Reorganizacao::TIPOS_PENDENCIA as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Descrição</span><textarea class="adm-textarea" name="descricao" rows="3" maxlength="1000"></textarea></label></template>
<template data-reorg-tpl="ajuda-atalhos"><h2>Ajuda e atalhos do teclado</h2>
    <ul class="reorg-atalhos">
        <li><kbd>Enter</kbd> próximo campo · <kbd>Shift</kbd>+<kbd>Enter</kbd> campo anterior</li>
        <li><kbd>Ctrl</kbd>+<kbd>S</kbd> salvar agora (o salvamento também é automático)</li>
        <li><kbd>Alt</kbd>+<kbd>N</kbd> novo registro (Grupo 1)</li>
        <li><kbd>Alt</kbd>+<kbd>J</kbd> / <kbd>Alt</kbd>+<kbd>K</kbd> próximo / anterior da lista</li>
        <li><kbd>Alt</kbd>+<kbd>O</kbd> pesquisar obra no acervo (Grupo 1) · ir para o campo do código (Grupo 4)</li>
        <li><kbd>Alt</kbd>+<kbd>P</kbd> pausar e continuar depois · <kbd>Alt</kbd>+<kbd>H</kbd> esta ajuda</li>
    </ul>
    <p>O indicador no topo mostra: “Salvando alterações...”, “Todas as alterações salvas”, “Sem conexão — alterações ainda não sincronizadas”, “Erro ao salvar — tentar novamente” ou “Atividade pausada”.</p></template>
<?php reorg_equipe_fim();
