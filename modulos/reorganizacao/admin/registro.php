<?php
/**
 * Revisão de um registro pelo Grupo 2: corrigir (salvamento automático), conferir destaques,
 * vincular a uma obra existente e escolher campo a campo, aprovar e cadastrar, devolver ao Grupo 1,
 * pausar (sair sem decidir) ou descartar. Volta sempre para a mesma aba e o mesmo filtro do lote.
 */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Levantamento;
use Biblioteca\Services\AcervoException;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();
$lev = $r->levantamento();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$atual = $lev->registro($id);
if (!$atual) {
    http_response_code(404);
    admin_pagina_erro('Registro não encontrado', 'Confira o endereço ou volte ao lote.');
    exit;
}
// Volta: aba e filtro do lote de onde a pessoa veio (só endereços do próprio lote).
$loteBase = reorg_url('admin/lote.php?id=' . $atual['lote_id']);
$volta = (string)($_GET['volta'] ?? $_POST['volta'] ?? '');
if ($volta === '' || !str_starts_with($volta, $loteBase)) {
    $volta = $loteBase . '&aba=g2&f=pendentes';
}
$aqui = reorg_url('admin/registro.php?id=' . $id . '&volta=' . rawurlencode($volta));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $destino = in_array($acao, ['pausar', 'devolver', 'descartar', 'aprovar_cadastrar', 'cadastrar'], true) ? $volta : $aqui;
    reorg_admin_acao(function () use ($acao, $lev, $id, $adminId, $usuario, $atual) {
        $versao = (int)($_POST['versao'] ?? 0) ?: null;
        $nova = ($_POST['confirmar_nova'] ?? '') === '1';
        switch ($acao) {
            case 'aprovar':
                $lev->aprovar($adminId, $id, $nova, $versao);
                return 'Registro nº ' . $atual['numero'] . ' aprovado. Falta cadastrar para gerar os códigos BDP.';
            case 'aprovar_cadastrar':
                if ($atual['status'] !== 'aprovado') {
                    $lev->aprovar($adminId, $id, $nova, $versao);
                }
                try {
                    $c = $lev->cadastrar($adminId, $id, reorg_registrar_cdd($usuario));
                } catch (AcervoException $e) {
                    throw new AcervoException('O registro nº ' . $atual['numero'] . ' foi aprovado, mas o cadastro não foi feito: ' . $e->getMessage());
                }
                return 'Registro nº ' . $atual['numero'] . ' aprovado e cadastrado' . ($c['obra_nova'] ? ' como obra nova' : ' na obra existente') . '. Códigos BDP: ' . implode(', ', $c['codigos']) . '. Os exemplares já estão com o Grupo 3.';
            case 'cadastrar':
                $c = $lev->cadastrar($adminId, $id, reorg_registrar_cdd($usuario));
                return 'Registro nº ' . $atual['numero'] . ' cadastrado. Códigos BDP: ' . implode(', ', $c['codigos']) . '. Os exemplares já estão com o Grupo 3.';
            case 'devolver':
                $lev->devolver($adminId, $id, (string)($_POST['motivo'] ?? ''));
                return 'Registro nº ' . $atual['numero'] . ' devolvido ao Grupo 1 para correção (com notificação).';
            case 'descartar':
                $lev->descartar($id, null, $adminId, (string)($_POST['motivo'] ?? ''));
                return 'Registro nº ' . $atual['numero'] . ' descartado (a linha fica guardada no histórico).';
            case 'obra':
                $livro = (int)($_POST['livro_id'] ?? 0) ?: null;
                $lev->sugerirObra($id, $livro, null, $adminId);
                return $livro ? 'Registro vinculado à obra existente. Confira abaixo, campo a campo, quais informações ficam.' : 'Vínculo retirado: será cadastrado como obra nova.';
            case 'escolhas':
                $lev->salvarEscolhasObra($adminId, $id, (array)($_POST['escolha'] ?? []));
                return 'Escolhas campo a campo salvas.';
            case 'pausar':
                $lev->liberar($id, null, $adminId);
                return 'Revisão do registro nº ' . $atual['numero'] . ' pausada. Tudo o que foi alterado está salvo.';
        }
        throw new AcervoException('Ação desconhecida.');
    }, 'Feito.', $destino);
}

$reg = $lev->abrir($id, null, $adminId);
$somenteLeitura = $reg['somente_leitura'];
$categorias = reorg_categorias();
$dif = $lev->diferencas($reg);
$orig = $reg['dados_originais'] ? json_decode((string)$reg['dados_originais'], true) : null;
$revisavel = in_array($reg['status'], ['aguardando_revisao', 'aprovado'], true);
$problemas = $reg['status'] === 'aguardando_revisao' ? $lev->problemasAprovacao($reg) : [];
$duplicidades = $revisavel ? $lev->possiveisDuplicidades($reg) : [];
$comparacao = $lev->comparacaoObra($reg);
[$st, $tom] = Levantamento::STATUS[$reg['status']];
$rotulos = Levantamento::ROTULOS + ['isbn_nao_encontrado' => 'ISBN não encontrado', 'isbn_situacao' => 'ISBN (situação)', 'cdd' => 'CDD', 'cdd_nao_encontrada' => 'CDD não encontrada',
    'cdd_situacao' => 'CDD (situação)', 'cdd_fonte' => 'Fonte da CDD', 'quantidade' => 'Quantidade', 'observacoes' => 'Observações', 'estados' => 'Conservação'];
$vazios = array_values(array_filter(['subtitulo', 'ano_publicacao', 'edicao', 'idioma', 'categoria_id', 'assunto'], fn ($c) => empty($reg[$c])));
$ruins = count(array_filter($reg['estados'], fn ($v) => $v === 'ruim'));
$podeAprovar = $reg['status'] === 'aguardando_revisao' && !$somenteLeitura;
$precisaConfirmarNova = !$reg['livro_sugerido_id'] && $reg['isbn'];
$lote = $r->lote((int)$reg['lote_id']);

reorg_admin_inicio('lotes', 'Registro nº ' . $reg['numero'], [[$reg['lote_nome'], $loteBase], ['Grupo 2 — Revisão', $volta], ['Registro nº ' . $reg['numero'], null]]);
?>
<div data-reorg-registro-cfg data-modo="admin" data-somente-leitura="<?= $somenteLeitura ? '1' : '0' ?>" data-max="<?= (int)reorg_config('max_exemplares_registro') ?>" hidden></div>
<section class="adm-card reorg-lote-topo">
    <div class="reorg-lote-topo__id">
        <h1>Registro nº <?= (int)$reg['numero'] ?> <?= reorg_status_badge($st, $tom) ?></h1>
        <p><?= e($reg['lote_nome']) ?> · Levantamento: <?= e($reg['criado_nome'] ?? '—') ?> · Última alteração: <?= e(($reg['atualizado_nome'] ?? 'administração') . ' em ' . admin_data_hora($reg['atualizado_em'])) ?></p>
    </div>
    <div class="reorg-lote-topo__acoes">
        <span class="reorg-indicador" data-reorg-indicador data-estado="<?= $somenteLeitura ? 'leitura' : 'salvo' ?>" role="status" aria-live="polite"><?= $somenteLeitura ? 'Somente consulta' : 'Todas as alterações salvas' ?></span>
        <a class="adm-button adm-button--sm" href="<?= e($volta) ?>"><?= icon('arrow-left') ?> Voltar à lista</a>
    </div>
</section>

<?php if ($somenteLeitura && $reg['motivo_leitura']): ?><?= admin_aviso('aviso', $reg['motivo_leitura']) ?><?php endif; ?>
<?php if ($reg['correcao_motivo'] && $reg['status'] === 'correcao'): ?><?= admin_aviso('aviso', 'Devolvido ao Grupo 1 para correção', $reg['correcao_motivo']) ?><?php endif; ?>

<?php if ($revisavel): ?>
<section class="adm-card adm-panel reorg-destaques-painel" aria-label="Pontos de atenção">
    <h2>Pontos de atenção</h2>
    <ul class="reorg-destaques-lista">
        <?php foreach ($problemas as $p): ?><li class="is-erro"><?= icon('circle-x') ?> <?= e($p) ?></li><?php endforeach; ?>
        <?php if ((int)$reg['isbn_nao_encontrado'] && !$reg['isbn']): ?><li class="is-aviso"><?= icon('triangle-alert') ?> ISBN marcado como não encontrado no livro.</li><?php endif; ?>
        <?php if ((int)$reg['cdd_nao_encontrada'] && !$reg['cdd']): ?><li class="is-aviso"><?= icon('triangle-alert') ?> CDD marcada como não encontrada no livro.</li><?php endif; ?>
        <?php if ($ruins): ?><li class="is-erro"><?= icon('book-x') ?> <?= $ruins ?> exemplar(es) em conservação ruim.</li><?php endif; ?>
        <?php foreach ($duplicidades as $d): ?><li class="is-aviso"><?= icon('copy') ?> Possível duplicidade: <?= e($d) ?></li><?php endforeach; ?>
        <?php if ($comparacao && array_filter($comparacao['campos'], fn ($x) => $x['divergente'])): ?><li class="is-aviso"><?= icon('git-compare') ?> Há divergências entre o levantamento e a obra existente (veja a comparação abaixo).</li><?php endif; ?>
        <?php if ($dif): ?><li class="is-info"><?= icon('pencil') ?> Campos alterados desde o envio do Grupo 1: <?= e(implode(', ', array_map(fn ($c) => $rotulos[$c] ?? $c, array_keys($dif)))) ?>.</li><?php endif; ?>
        <?php if ($vazios): ?><li class="is-info"><?= icon('info') ?> Campos opcionais vazios: <?= e(implode(', ', array_map(fn ($c) => $rotulos[$c] ?? $c, $vazios))) ?>.</li><?php endif; ?>
        <?php if (!$problemas && !$duplicidades && !$ruins && !$dif && !((int)$reg['isbn_nao_encontrado'] || (int)$reg['cdd_nao_encontrada'])): ?><li class="is-ok"><?= icon('circle-check') ?> Nada a destacar: os campos obrigatórios estão completos e válidos.</li><?php endif; ?>
    </ul>
</section>
<?php endif; ?>

<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Dados do levantamento <?= $revisavel && !$somenteLeitura ? '<small class="adm-table__muted">(as correções são salvas sozinhas)</small>' : '' ?></h2></div>
    <?php include __DIR__ . '/../includes/form-registro.php'; ?>
</section>

<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Obra do acervo</h2></div>
    <?php if ($comparacao): $o = $comparacao['obra']; ?>
        <p>Vinculado à obra existente <strong><?= e($o['titulo']) ?></strong> (nº <?= (int)$o['id'] ?>). Os exemplares entram nela, sem duplicar a obra.
            Escolha, campo a campo, qual informação fica no cadastro. Um campo vazio no levantamento nunca apaga o que a obra já tem.</p>
        <form method="post" class="reorg-comparacao"><?= csrf_campo() ?><input type="hidden" name="acao" value="escolhas"><input type="hidden" name="volta" value="<?= e($volta) ?>">
            <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
                <thead><tr><th>Campo</th><th>Na obra existente</th><th>No levantamento</th><th>Fica no cadastro</th></tr></thead>
                <tbody>
                <?php foreach ($comparacao['campos'] as $c => $x): $cat = $c === 'categoria_id' ? array_column($categorias, 'nome', 'id') : null; $mostra = fn ($v) => $v === null ? '—' : ($cat ? ($cat[$v] ?? $v) : $v); ?>
                    <tr class="<?= $x['divergente'] ? 'is-divergente' : '' ?>">
                        <th scope="row"><?= e($x['rotulo']) ?><?= $x['divergente'] ? ' ' . reorg_status_badge('diferente', 'amber') : '' ?></th>
                        <td><?= e((string)$mostra($x['obra'])) ?></td>
                        <td><?= $x['levantamento'] === null ? '<span class="reorg-vazio-campo">vazio</span>' : e((string)$mostra($x['levantamento'])) ?></td>
                        <td class="reorg-escolha-campo">
                            <label class="reorg-radio"><input type="radio" name="escolha[<?= $c ?>]" value="obra"<?= $x['escolha'] === 'obra' ? ' checked' : '' ?><?= $somenteLeitura || !$revisavel ? ' disabled' : '' ?>> Obra</label>
                            <label class="reorg-radio"><input type="radio" name="escolha[<?= $c ?>]" value="levantamento"<?= $x['escolha'] === 'levantamento' ? ' checked' : '' ?><?= $somenteLeitura || !$revisavel || $x['levantamento'] === null ? ' disabled' : '' ?>> Levantamento</label>
                        </td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($revisavel && !$somenteLeitura): ?><button class="adm-button adm-button--sm" type="submit" data-reorg-salvar-antes><?= icon('save') ?> Salvar escolhas</button><?php endif; ?>
        </form>
        <?php if ($revisavel && !$somenteLeitura): ?><form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="obra"><input type="hidden" name="livro_id" value=""><input type="hidden" name="volta" value="<?= e($volta) ?>"><button class="adm-button adm-button--sm" type="submit" data-reorg-salvar-antes><?= icon('book-plus') ?> Cadastrar como obra nova (retirar vínculo)</button></form><?php endif; ?>
    <?php else: ?>
        <p>Sem obra vinculada: na aprovação será cadastrada uma <strong>obra nova</strong>. Se o livro já existe no acervo, pesquise e vincule.</p>
    <?php endif; ?>
    <?php if ($revisavel && !$somenteLeitura): ?>
    <div class="reorg-pesquisa" data-reorg-pesquisa>
        <label class="adm-field"><span class="adm-field__label">Vincular a uma obra existente: pesquisar por título, autor, ISBN ou editora</span><input class="adm-input" type="search" data-reorg-q></label>
        <ul class="reorg-resultados" data-reorg-resultados></ul>
        <form method="post" hidden data-reorg-vincular><?= csrf_campo() ?><input type="hidden" name="acao" value="obra"><input type="hidden" name="livro_id"><input type="hidden" name="volta" value="<?= e($volta) ?>"></form>
    </div>
    <?php endif; ?>
</section>

<?php if ($orig && $dif): ?>
<details class="adm-card adm-panel reorg-detalhes"><summary><?= icon('history') ?> Informações originais do Grupo 1 (<?= count($dif) ?> campo(s) alterado(s))</summary>
    <table class="adm-table adm-table--compact"><thead><tr><th>Campo</th><th>Enviado pelo Grupo 1</th><th>Atual</th></tr></thead><tbody>
    <?php foreach ($dif as $campo => [$de, $para]): ?><tr><td><?= e($rotulos[$campo] ?? $campo) ?></td><td><?= e((string)($de ?? '—')) ?></td><td><strong><?= e((string)($para ?? '—')) ?></strong></td></tr><?php endforeach; ?>
    </tbody></table></details>
<?php endif; ?>

<section class="adm-card adm-panel reorg-decisao">
    <h2>Decisão</h2>
    <?php if ($reg['status'] === 'cadastrado'): ?>
        <p>Cadastrado<?= $reg['livro_id'] ? ' na obra <a href="' . e(url('admin/detalhes-livro.php?id=' . $reg['livro_id'])) . '">nº ' . (int)$reg['livro_id'] . '</a>' : '' ?>. Códigos BDP: <span class="adm-mono"><?= e(implode(', ', array_filter(array_column($reg['exemplares'], 'codigo_individual')))) ?></span></p>
    <?php elseif ($revisavel && !$somenteLeitura): ?>
        <form method="post" class="reorg-decisao__principal"><?= csrf_campo() ?><input type="hidden" name="volta" value="<?= e($volta) ?>"><input type="hidden" name="versao" value="<?= (int)$reg['versao'] ?>" data-reorg-versao>
            <?php if ($podeAprovar && $precisaConfirmarNova): ?><label class="adm-check"><input type="checkbox" name="confirmar_nova" value="1"> Confirmo que é uma obra nova (mesmo que já exista uma obra com este ISBN)</label><?php endif; ?>
            <div class="reorg-barra-acoes">
                <button class="adm-button adm-button--primary" type="submit" name="acao" value="aprovar_cadastrar" data-reorg-salvar-antes data-reorg-confirmar="<?= e('Aprovar e cadastrar no acervo? Serão gerados ' . (int)$reg['quantidade'] . ' código(s) BDP e os exemplares vão para o Grupo 3.') ?>"><?= icon('check-check') ?> <?= $reg['status'] === 'aprovado' ? 'Cadastrar e liberar ao Grupo 3' : 'Aprovar e cadastrar' ?></button>
                <?php if ($podeAprovar): ?><button class="adm-button" type="submit" name="acao" value="aprovar" data-reorg-salvar-antes><?= icon('check') ?> Só aprovar</button><?php endif; ?>
                <button class="adm-button" type="submit" name="acao" value="pausar" data-reorg-salvar-antes><?= icon('pause') ?> Pausar e voltar à lista</button>
            </div>
            <?php if ($podeAprovar && $problemas): ?><p class="adm-field__hint">Corrija os pontos marcados em vermelho antes de aprovar.</p><?php endif; ?>
        </form>
        <div class="reorg-duas">
            <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="devolver"><input type="hidden" name="volta" value="<?= e($volta) ?>">
                <label class="adm-field"><span class="adm-field__label">Solicitar correção ao Grupo 1</span><textarea class="adm-textarea" name="motivo" rows="2" required maxlength="500" placeholder="O que precisa ser conferido no livro"></textarea></label>
                <button class="adm-button adm-button--sm" type="submit" data-reorg-salvar-antes><?= icon('rotate-ccw') ?> Devolver ao Grupo 1</button></form>
            <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="descartar"><input type="hidden" name="volta" value="<?= e($volta) ?>">
                <label class="adm-field"><span class="adm-field__label">Descartar registro</span><input class="adm-input" name="motivo" placeholder="Motivo (registro duplicado, engano...)" maxlength="500"></label>
                <button class="adm-button adm-button--sm adm-button--danger-text" type="submit" data-reorg-confirmar="Descartar este registro? Ele deixa de contar no lote, mas fica guardado.">Descartar</button></form>
        </div>
    <?php else: ?>
        <p class="adm-table__muted"><?= $reg['status'] === 'descartado' ? 'Registro descartado.' : 'Este registro ainda está com o Grupo 1 (' . e($st) . '). A revisão fica disponível depois do encaminhamento.' ?></p>
    <?php endif; ?>
</section>
<script src="<?= e(reorg_asset('registro.js')) ?>" defer></script>
<?php reorg_admin_fim();
