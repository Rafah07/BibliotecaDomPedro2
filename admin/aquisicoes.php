<?php
/**
 * Acervo → Aquisições (migração 190): entrada de novos livros recebidos por compra, doação,
 * transferência ou outra origem, sem depender da planilha original nem de inventário em andamento.
 *
 *   lista + "Registrar aquisição"      origem, data, quantidade, documento e observações
 *   ?id=ID                              exemplares já registrados da aquisição, preparo (carimbo,
 *                                       estante, liberação) e "Registrar exemplares desta aquisição"
 * Os exemplares são registrados pela tela "Registrar vários exemplares" (obra existente ou nova),
 * com códigos BDP gerados pelo servidor e lote de processamento quando há lotes em uso.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\AquisicaoService;

$usuario = fisico_iniciar();
$servico = new AquisicaoService(db());
$base = admin_url('aquisicoes');
$erro = null;
$form = ['tipo' => 'doacao', 'origem' => '', 'data_recebimento' => date('Y-m-d'), 'quantidade_recebida' => '', 'documento' => '', 'observacoes' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    fisico_exigir('aquisicoes.registrar');
    foreach ($form as $k => $v) {
        $form[$k] = mb_substr(admin_param($_POST, $k), 0, 1000);
    }
    try {
        $id = $servico->registrar($form, (int)$usuario['id']);
        admin_flash('sucesso', 'Aquisição nº ' . $id . ' registrada.', 'Agora registre os exemplares recebidos: escolha a obra (ou cadastre uma nova) e informe quantas cópias. Cada uma recebe o próprio código BDP.');
        admin_redirecionar($base . '?id=' . $id);
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    } catch (PDOException $e) {
        $erro = fisico_erro_tecnico($e, 'registro de aquisição');
    }
}

try {
    $aquisicao = (int)admin_param($_GET, 'id') ? $servico->buscar((int)admin_param($_GET, 'id')) : null;
    $exemplares = $aquisicao ? $servico->exemplares((int)$aquisicao['id']) : [];
    $f = [
        'tipo' => in_array(admin_param($_GET, 'tipo'), AquisicaoService::TIPOS, true) ? admin_param($_GET, 'tipo') : '',
        'de' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'de')) ? admin_param($_GET, 'de') : '',
        'ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($_GET, 'ate')) ? admin_param($_GET, 'ate') : '',
        'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
    ];
    $lista = $aquisicao ? null : $servico->listar($f, max(1, (int)admin_param($_GET, 'pagina', '1')), 25);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir as aquisições', fisico_erro_tecnico($e, 'aquisições') . ' Confira se a migração 190 foi aplicada.');
    exit;
}
$podeRegistrar = auth_pode('aquisicoes.registrar');

admin_page_start('aquisicoes', $aquisicao ? 'Aquisição nº ' . (int)$aquisicao['id'] : 'Aquisições', 'Acervo', 'Novas entradas no acervo', array_merge(FISICO_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Aquisições', $aquisicao ? $base : null], ...($aquisicao ? [['Nº ' . (int)$aquisicao['id']]] : [])]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= $aquisicao ? 'Aquisição nº ' . (int)$aquisicao['id'] : 'Aquisições' ?></h1>
        <p><?= $aquisicao ? e(AQUISICAO_TIPOS[$aquisicao['tipo']] ?? $aquisicao['tipo']) . ' · ' . e($aquisicao['origem']) . ' · recebida em ' . e(date('d/m/Y', strtotime($aquisicao['data_recebimento'])))
            : 'Livros recebidos depois do inventário inicial (compra, doação, transferência). Não é preciso abrir um inventário para registrá-los.' ?></p>
    </div>
    <?php if ($aquisicao): ?><div class="adm-page-head__actions"><a class="adm-button adm-button--ghost" href="<?= e($base) ?>"><?= icon('arrow-left') ?> Todas as aquisições</a></div><?php endif; ?>
</section>

<?= admin_flash_html() ?>

<?php if ($aquisicao): ?>
    <section class="adm-stats" aria-label="Situação da aquisição">
        <?= fisico_stat('Recebidos', (int)$aquisicao['quantidade_recebida'], 'Informado no registro da aquisição', 'inbox', 'blue') ?>
        <?= fisico_stat('Exemplares registrados', (int)$aquisicao['registrados'], 'Com código BDP próprio', 'book-copy', 'green') ?>
        <?= fisico_stat('Aguardando carimbo', (int)$aquisicao['aguardando_carimbo'], 'Ver na Planilha BDP', 'stamp', 'amber', admin_url('planilha-bdp') . '?aba=carimbo') ?>
        <?= fisico_stat('Sem estante definitiva', (int)$aquisicao['sem_local'], 'Área de triagem', 'map-pin-off', 'amber', admin_url('planilha-bdp') . '?aba=triagem') ?>
        <?= fisico_stat('Disponíveis para empréstimo', (int)$aquisicao['disponiveis'], 'Carimbados e organizados', 'circle-check', 'green') ?>
    </section>
    <div class="adm-columns">
        <div class="adm-columns__main">
            <section class="adm-card adm-panel" aria-labelledby="titulo-ex-aq">
                <div class="adm-panel__header"><div><h2 id="titulo-ex-aq">Exemplares desta aquisição</h2>
                    <p><?= (int)$aquisicao['registrados'] ?> de <?= (int)$aquisicao['quantidade_recebida'] ?> registrado(s).<?= (int)$aquisicao['registrados'] < (int)$aquisicao['quantidade_recebida'] ? ' Faltam ' . ((int)$aquisicao['quantidade_recebida'] - (int)$aquisicao['registrados']) . '.' : '' ?></p></div>
                    <?php if ($podeRegistrar && auth_pode('exemplares.gerenciar')): ?>
                        <div class="adm-actions-inline">
                            <a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url('cadastrar-exemplar') . '?modo=varios&aquisicao=' . (int)$aquisicao['id']) ?>"><?= icon('book-plus') ?> Registrar exemplares (obra existente)</a>
                            <?php if (auth_pode('livros.editar')): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('cadastrar-livro') . '?aquisicao=' . (int)$aquisicao['id']) ?>"><?= icon('plus') ?> Cadastrar obra nova</a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!$exemplares): ?>
                    <div class="adm-empty adm-empty--left"><?= icon('book-copy') ?><strong>Nenhum exemplar registrado ainda.</strong><span>Escolha a obra de cada livro recebido e registre as cópias. Livros de edições diferentes vão para obras diferentes.</span></div>
                <?php else: ?>
                    <div class="adm-table-wrap">
                        <table class="adm-table adm-table--compact">
                            <thead><tr><th scope="col">Código BDP</th><th scope="col">Obra</th><th scope="col">Carimbo</th><th scope="col">Localização</th><th scope="col">Situação</th></tr></thead>
                            <tbody>
                            <?php foreach ($exemplares as $x): ?>
                                <tr>
                                    <td data-label="Código"><a class="adm-link adm-mono" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$x['id']) ?>"><?= e($x['codigo_individual']) ?></a></td>
                                    <td data-label="Obra"><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$x['livro_id']) ?>"><?= e($x['titulo']) ?></a></td>
                                    <td data-label="Carimbo"><?= fisico_badge_carimbo($x['carimbo_status']) ?></td>
                                    <td data-label="Localização"><?= $x['localizacao_id'] ? 'Na estante' : '<span class="adm-missing">Aguardando organização</span>' ?></td>
                                    <td data-label="Situação"><?= acervo_badge_situacao($x['situacao']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <aside class="adm-columns__side">
            <section class="adm-card adm-panel">
                <h2 class="adm-panel__title">Dados da entrada</h2>
                <dl class="adm-meta adm-meta--stack">
                    <div><dt>Origem</dt><dd><?= e(AQUISICAO_TIPOS[$aquisicao['tipo']] ?? $aquisicao['tipo']) ?> · <?= e($aquisicao['origem']) ?></dd></div>
                    <div><dt>Recebida em</dt><dd><?= e(date('d/m/Y', strtotime($aquisicao['data_recebimento']))) ?></dd></div>
                    <div><dt>Documento</dt><dd><?= $aquisicao['documento'] ? e($aquisicao['documento']) : '<span class="adm-missing">Não informado</span>' ?></dd></div>
                    <div><dt>Registrada por</dt><dd><?= e((string)($aquisicao['administrador_nome'] ?? '—')) ?> em <?= e(admin_data_hora($aquisicao['criado_em'])) ?></dd></div>
                    <?php if ($aquisicao['observacoes']): ?><div><dt>Observações</dt><dd><?= e($aquisicao['observacoes']) ?></dd></div><?php endif; ?>
                </dl>
                <p class="adm-side-note"><?= icon('info') ?> Depois de registrados: carimbe (Planilha BDP), organize nas estantes e os exemplares ficam disponíveis para empréstimo quando estiverem aptos.</p>
            </section>
        </aside>
    </div>
<?php else: ?>
    <div class="adm-org-colunas">
        <?php if ($podeRegistrar): ?>
            <section class="adm-card adm-panel" aria-labelledby="titulo-nova-aq">
                <h2 id="titulo-nova-aq" class="adm-panel__title"><?= icon('plus') ?> Registrar aquisição</h2>
                <?php if ($erro): ?><?= admin_aviso('erro', 'A aquisição não foi registrada. Suas informações foram preservadas.', $erro) ?><?php endif; ?>
                <form class="adm-form" method="post" action="<?= e($base) ?>" data-enviar-uma-vez data-protegido="nova-aquisicao">
                    <?= csrf_campo() ?>
                    <label class="adm-field"><span class="adm-field__sublabel">Origem *</span><select class="adm-select" name="tipo" required><?php foreach (AQUISICAO_TIPOS as $v => $r): ?><option value="<?= $v ?>"<?= $form['tipo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                    <label class="adm-field"><span class="adm-field__sublabel">Fornecedor, doador ou instituição *</span><input class="adm-input" name="origem" required maxlength="150" value="<?= e($form['origem']) ?>" placeholder="Ex.: Doação da família Souza"></label>
                    <label class="adm-field"><span class="adm-field__sublabel">Data de recebimento *</span><input class="adm-input" type="date" name="data_recebimento" required max="<?= date('Y-m-d') ?>" value="<?= e($form['data_recebimento']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__sublabel">Quantidade recebida *</span><input class="adm-input" type="number" name="quantidade_recebida" min="1" required inputmode="numeric" value="<?= e($form['quantidade_recebida']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__sublabel">Documento <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="documento" maxlength="150" value="<?= e($form['documento']) ?>" placeholder="Nota fiscal, termo de doação, ofício..."></label>
                    <label class="adm-field"><span class="adm-field__sublabel">Observações <span class="adm-optional">(opcional)</span></span><textarea class="adm-textarea" name="observacoes" rows="2" maxlength="1000"><?= e($form['observacoes']) ?></textarea></label>
                    <div class="adm-form__acoes"><button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Registrar aquisição</button></div>
                </form>
            </section>
        <?php endif; ?>
        <section class="adm-card adm-panel" aria-labelledby="titulo-lista-aq">
            <div class="adm-panel__header"><div><h2 id="titulo-lista-aq">Histórico de entradas</h2><p><?= fisico_numero($lista['total']) ?> aquisição(ões)</p></div></div>
            <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
                <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Fornecedor, doador ou documento..."></label>
                <div class="adm-filters">
                    <label class="adm-filter"><span>Origem</span><select class="adm-select" name="tipo"><option value="">Todas</option><?php foreach (AQUISICAO_TIPOS as $v => $r): ?><option value="<?= $v ?>"<?= $f['tipo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                    <label class="adm-filter"><span>De</span><input class="adm-input" type="date" name="de" value="<?= e($f['de']) ?>"></label>
                    <label class="adm-filter"><span>Até</span><input class="adm-input" type="date" name="ate" value="<?= e($f['ate']) ?>"></label>
                </div>
                <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
            </form>
            <?php if (!$lista['itens']): ?>
                <div class="adm-empty adm-empty--left"><?= icon('inbox') ?><strong>Nenhuma aquisição registrada.</strong><span>Quando a escola receber livros novos, registre a entrada aqui.</span></div>
            <?php else: ?>
                <ul class="adm-gestao-lista">
                    <?php foreach ($lista['itens'] as $a): ?>
                        <li class="adm-gestao-item">
                            <span class="adm-gestao-item__icone adm-tone--blue"><?= icon('inbox') ?></span>
                            <div class="adm-gestao-item__corpo"><strong>Nº <?= (int)$a['id'] ?> · <?= e(AQUISICAO_TIPOS[$a['tipo']] ?? $a['tipo']) ?> · <?= e($a['origem']) ?></strong>
                                <p><?= e(date('d/m/Y', strtotime($a['data_recebimento']))) ?> · <?= (int)$a['quantidade_recebida'] ?> recebido(s) · <?= (int)$a['registrados'] ?> exemplar(es) registrado(s)<?= $a['documento'] ? ' · ' . e($a['documento']) : '' ?></p></div>
                            <div class="adm-gestao-item__acoes"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?id=' . (int)$a['id']) ?>"><?= icon('eye') ?> Abrir</a></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?= admin_paginacao($lista['total'], $lista['pagina'], 25, $base, array_filter($f, fn ($v) => $v !== ''), [25]) ?>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/gestao.js?v=20261118']));
