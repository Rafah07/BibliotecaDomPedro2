<?php
/** Área de trabalho de um lote: progresso, participantes, revisão do Grupo 2, pausa, ajuste e conclusão. */
require __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../../admin/includes/cdd.php';

use Biblioteca\Reorganizacao\Levantamento;
use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();
$lev = $r->levantamento();
$et = $r->etapas();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$lote = $r->lote($id);
if (!$lote) {
    http_response_code(404);
    admin_pagina_erro('Lote não encontrado', 'Confira o endereço ou volte à central da reorganização.');
    exit;
}
$aqui = reorg_url('admin/lote.php?id=' . $id);

/** Grava a CDD aprovada na classificação permanente (mesma regra da conferência do Inventário). */
function reorg_aplicar_cdd(array $cad, array $usuario): ?string
{
    if ($cad['repetido'] || !$cad['cdd']) {
        return null;
    }
    try {
        cdd_corrigir_na_conferencia((int)$cad['livro_id'], (string)$cad['cdd'], (string)($cad['cdd_fonte'] ?? ''), $usuario, 'reorganização do acervo');
        return null;
    } catch (Throwable $e) {
        return 'CDD do registro nº ' . $cad['numero'] . ' não gravada: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    reorg_admin_acao(function () use ($acao, $r, $lev, $et, $id, $adminId, $ids, $usuario) {
        switch ($acao) {
            case 'pausar': $r->pausarLote($id, $adminId, true); return 'Lote pausado. O que cada participante fez continua salvo.';
            case 'retomar': $r->pausarLote($id, $adminId, false); return 'Lote retomado.';
            case 'etapa': $r->pausarEtapa($id, (string)$_POST['etapa'], $adminId, ($_POST['pausar'] ?? '') === '1'); return 'Etapa atualizada.';
            case 'reabrir': $r->reabrirLote($id, $adminId, (string)($_POST['motivo'] ?? '')); return 'Lote reaberto.';
            case 'quantidade': $r->ajustarQuantidade($id, (int)$_POST['quantidade'], $adminId, (string)($_POST['justificativa'] ?? '')); return 'Quantidade final ajustada (o lote permanente acompanhou).';
            case 'alterar': $r->alterarLote($id, $_POST, $adminId); return 'Dados do lote salvos.';
            case 'admin_incluir': $r->autorizarAdmin($id, (int)$_POST['admin_id'], $adminId, true); return 'Administrador autorizado a revisar este lote.';
            case 'admin_remover': $r->autorizarAdmin($id, (int)$_POST['admin_id'], $adminId, false); return 'Autorização retirada.';
            case 'atribuir':
                foreach ((array)($_POST['sessoes'] ?? []) as $sid) {
                    $s = $r->sessao((int)$sid);
                    if ($s && $s['equipe']) {
                        $r->atribuir($id, (int)$sid, (string)$s['equipe'], $adminId);
                    }
                }
                return 'Participantes atribuídos e notificados.';
            case 'remover': $r->removerDoLote($id, (int)$_POST['sessao_id'], $adminId); return 'Participante retirado do lote. O que foi preenchido continua salvo.';
            case 'transferir': $r->transferirLote((int)$_POST['sessao_id'], $id, (int)$_POST['para'], $adminId); return 'Participante transferido.';
            case 'encaminhar_g2': $x = $lev->encaminhar($id, null, $adminId); return $x['exemplares'] . ' exemplar(es) encaminhados à revisão; ' . $x['restantes'] . ' continuam no levantamento.';
            case 'encaminhar_g4': $x = $et->encaminharG4($id, null, $adminId); return $x['exemplares'] . ' exemplar(es) encaminhados à conferência; ' . $x['restantes'] . ' ainda sem carimbo.';
            case 'aprovar_massa':
                $x = $lev->aprovarEmMassa($adminId, $id, $ids);
                $msg = $x['aprovados'] . ' registro(s) aprovado(s).';
                foreach ($x['pulados'] as $num => $motivo) {
                    $msg .= ' Nº ' . $num . ' não aprovado: ' . (is_array($motivo) ? implode(' ', $motivo) : $motivo);
                }
                return $msg;
            case 'cadastrar':
                $x = $lev->cadastrarAprovados($adminId, $id, $ids ?: null);
                $msg = count($x['cadastrados']) . ' registro(s) cadastrado(s): ' . array_sum(array_map(fn ($c) => count($c['codigos']), $x['cadastrados'])) . ' código(s) BDP gerado(s).';
                foreach ($x['cadastrados'] as $c) {
                    $msg .= ($aviso = reorg_aplicar_cdd($c, $usuario)) ? ' ' . $aviso : '';
                }
                foreach ($x['erros'] as $num => $erro) {
                    $msg .= ' Nº ' . $num . ': ' . $erro;
                }
                return $msg;
            case 'concluir':
                $x = $r->concluirLote($id, $adminId);
                return 'Lote concluído. ' . implode(' ', $x['avisos']);
            case 'pendencia': $r->registrarPendencia($id, (string)$_POST['tipo'], (string)($_POST['descricao'] ?? ''), 'grupo2', null, $adminId); return 'Pendência registrada.';
        }
        throw new Biblioteca\Services\AcervoException('Ação desconhecida.');
    }, 'Feito.', $aqui . (isset($_POST['volta']) ? '&' . http_build_query(['f' => $_POST['volta']]) . '#revisao' : ''));
}

$c = $r->contadores($id);
$filtro = (string)($_GET['f'] ?? 'pendentes');
$filtros = ['pendentes' => 'Aguardando revisão', 'aprovados' => 'Aprovados (sem cadastro)', 'cadastrados' => 'Cadastrados', 'correcao' => 'Devolvidos', 'rascunhos' => 'Em levantamento',
    'sem_isbn' => 'Sem ISBN', 'sem_cdd' => 'Sem CDD', 'ruim' => 'Conservação ruim', 'descartados' => 'Descartados', 'todos' => 'Todos'];
if (!isset($filtros[$filtro])) {
    $filtro = 'pendentes';
}
$registros = $lev->registrosDoLote($id, $filtro);
$part = $r->participantesDoLote($id);
$adminsLote = $r->adminsDoLote($id);
$ativos = array_filter($r->participantesAtivos(), fn ($s) => $s['equipe'] !== null);
$jaNoLote = array_column($part['atuais'], 'sessao_id');
$outrosLotes = array_filter($r->lotes('em_andamento'), fn ($l) => (int)$l['id'] !== $id);
$pausadas = array_filter(explode(',', (string)$lote['etapas_pausadas']));
$impedimentos = $lote['status'] !== 'concluido' ? $r->impedimentosConclusao($id) : [];
$resumo = $lote['resumo_conclusao'] ? json_decode($lote['resumo_conclusao'], true) : null;
$pendencias = $r->pendencias('', $id);
$historico = $r->historicoLista(['lote_id' => $id], 40);
$previaG2 = $lev->previaEncaminhamento($id);
$previaG4 = $et->previaEncaminhamentoG4($id);
$souAdminDoLote = in_array($adminId, array_map('intval', array_column($adminsLote, 'id')), true);

reorg_admin_inicio('inicio', $lote['nome'], 'Lote da reorganização');
?>
<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow"><a href="<?= e(reorg_url('admin/')) ?>">Reorganização</a> · <?= e($lote['periodo_nome']) ?></p>
        <h1><?= e($lote['nome']) ?> <?= reorg_status_lote($lote) ?></h1>
        <p>Responsável: <strong><?= e($lote['responsavel_nome']) ?></strong> · Lote permanente nº <?= e(str_pad((string)$lote['cadastro_numero'], 3, '0', STR_PAD_LEFT)) ?> (<?= e(Biblioteca\Services\ExemplarService::rotuloStatusLote($lote['cadastro_status'])) ?>)
            <?= $lote['prazo'] ? ' · Prazo: ' . e(admin_data_hora($lote['prazo'], false)) : '' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button" href="<?= e(url('admin/planilha-bdp.php?aba=todos&lote=' . $lote['cadastro_lote_id'])) ?>"><?= icon('stamp') ?> Planilha BDP</a>
        <a class="adm-button" href="<?= e(reorg_url('admin/relatorios.php?lote=' . $id)) ?>"><?= icon('file-chart-column') ?> Relatório</a>
        <?php if ($lote['status'] === 'em_andamento'): ?>
            <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="pausar"><button class="adm-button" type="submit"><?= icon('pause') ?> Pausar lote</button></form>
        <?php elseif ($lote['status'] === 'pausado'): ?>
            <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="retomar"><button class="adm-button adm-button--primary" type="submit"><?= icon('play') ?> Retomar lote</button></form>
        <?php endif; ?>
    </div>
</section>

<?php if (!$souAdminDoLote): ?>
    <?= admin_aviso('aviso', 'Você não é responsável nem autorizado neste lote', 'Pode acompanhar, mas a revisão, a aprovação e o cadastro ficam com o responsável e os administradores autorizados.') ?>
<?php endif; ?>

<section class="reorg-etapas" data-reorg-contadores="<?= $id ?>">
    <?php foreach ([
        ['Previstos', 'prevista', null, 'Quantidade física final do lote'],
        ['Registrados (G1)', 'registrados', 'prevista', 'Exemplares com formulário no levantamento'],
        ['Aguardando revisão', 'aguardando_revisao', null, 'Encaminhados pelo Grupo 1'],
        ['Aprovados', 'aprovados', 'registrados', 'Aprovados pelo Grupo 2'],
        ['Códigos BDP', 'codigos', 'prevista', 'Exemplares cadastrados com código'],
        ['Carimbados (G3)', 'carimbados', 'codigos', 'Código escrito no livro'],
        ['Conferidos (G4)', 'conferidos', 'carimbados', 'Código e informações conferidos'],
        ['Organizados', 'organizados', 'prevista', 'Guardados na estante'],
        ['Restantes', 'restantes', null, 'Ainda sem concluir todas as etapas'],
        ['Com problema', 'com_problema', null, 'Exemplares com pendência aberta'],
    ] as [$rot, $k, $de, $dica]): ?>
        <div class="reorg-etapa" title="<?= e($dica) ?>"><span><?= e($rot) ?></span><strong data-k="<?= $k ?>"><?= (int)$c[$k] ?></strong>
            <?php if ($de): ?><?= reorg_barra((int)$c[$k], (int)$c[$de]) ?><?php endif; ?></div>
    <?php endforeach; ?>
    <div class="reorg-etapa"><span>Conservação</span><small>Excelente <b data-k="excelente"><?= $c['excelente'] ?></b> · Regular <b data-k="regular"><?= $c['regular'] ?></b> · Ruim <b data-k="ruim"><?= $c['ruim'] ?></b></small></div>
</section>

<?php if ($resumo): ?>
<section class="adm-card adm-panel reorg-resumo">
    <h2><?= icon('circle-check') ?> Resumo da conclusão</h2>
    <dl class="adm-meta adm-meta--two">
        <div><dt>Quantidade prevista inicial</dt><dd><?= (int)$resumo['prevista_inicial'] ?></dd></div>
        <div><dt>Quantidade final</dt><dd><?= (int)$resumo['quantidade_final'] ?></dd></div>
        <div><dt>Obras cadastradas / reaproveitadas</dt><dd><?= (int)$resumo['obras_cadastradas'] ?> / <?= (int)$resumo['obras_reaproveitadas'] ?></dd></div>
        <div><dt>Exemplares e códigos BDP</dt><dd><?= (int)$resumo['codigos_gerados'] ?></dd></div>
        <div><dt>Carimbados · conferidos · organizados</dt><dd><?= (int)$resumo['carimbados'] ?> · <?= (int)$resumo['conferidos'] ?> · <?= (int)$resumo['organizados'] ?></dd></div>
        <div><dt>Conservação</dt><dd>Excelente <?= (int)$resumo['conservacao']['excelente'] ?> · Regular <?= (int)$resumo['conservacao']['regular'] ?> · Ruim <?= (int)$resumo['conservacao']['ruim'] ?></dd></div>
        <div><dt>Participantes</dt><dd><?= e(implode(', ', $resumo['participantes'])) ?></dd></div>
        <div><dt>Administradores</dt><dd><?= e(implode(', ', $resumo['administradores'])) ?></dd></div>
        <div><dt>Pendências resolvidas</dt><dd><?= (int)$resumo['pendencias_resolvidas'] ?></dd></div>
        <div><dt>Concluído em</dt><dd><?= e(admin_data_hora($resumo['concluido_em'])) ?></dd></div>
    </dl>
    <form method="post" class="reorg-linha"><?= csrf_campo() ?><input type="hidden" name="acao" value="reabrir">
        <input class="adm-input" name="motivo" placeholder="Motivo da reabertura" required maxlength="255"><button class="adm-button" type="submit"><?= icon('rotate-ccw') ?> Reabrir lote</button></form>
</section>
<?php endif; ?>

<div class="reorg-grade">
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Participantes</h2></div>
    <?php if (!$part['atuais']): ?><p class="adm-table__muted">Ninguém atribuído agora.</p><?php endif; ?>
    <ul class="reorg-pessoas">
        <?php foreach ($part['atuais'] as $p): ?>
            <li><div><strong><?= e($p['nome']) ?></strong> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['sessao_id'])) ?></small> · <?= e(Reorganizacao::EQUIPES[$p['equipe']]) ?>
                <?= reorg_estado_badge($r->estadoConexao(['status' => $p['sessao_status'], 'ultimo_sinal_em' => $p['ultimo_sinal_em'], 'situacao' => $p['situacao'], 'equipe' => $p['equipe_sessao'], 'motivo_encerramento' => $p['motivo_encerramento']])) ?></div>
                <div class="reorg-acoes">
                    <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="remover"><input type="hidden" name="sessao_id" value="<?= (int)$p['sessao_id'] ?>"><button class="adm-button adm-button--sm adm-button--danger-text" type="submit">Retirar</button></form>
                    <?php if ($outrosLotes): ?>
                    <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="transferir"><input type="hidden" name="sessao_id" value="<?= (int)$p['sessao_id'] ?>">
                        <select class="adm-select adm-select--sm" name="para" aria-label="Transferir para"><?= reorg_opcoes($outrosLotes, null) ?></select><button class="adm-button adm-button--sm" type="submit">Transferir</button></form>
                    <?php endif; ?>
                </div></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($lote['status'] !== 'concluido'): ?>
    <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="atribuir">
        <label class="adm-field"><span class="adm-field__label">Atribuir participantes conectados (cada um na própria equipe)</span>
            <select class="adm-select" name="sessoes[]" multiple size="5">
                <?php foreach ($ativos as $s): if (in_array($s['id'], $jaNoLote)) continue; ?>
                    <option value="<?= (int)$s['id'] ?>"><?= e($s['nome'] . ' · ' . Reorganizacao::rotuloSessao($s['id']) . ' · ' . Reorganizacao::EQUIPES[$s['equipe']] . ($s['lotes'] ? ' (' . count($s['lotes']) . ' lote(s))' : ' (livre)')) ?></option>
                <?php endforeach; ?>
            </select></label>
        <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('user-plus') ?> Atribuir</button>
    </form>
    <?php endif; ?>
    <?php if ($part['anteriores']): ?>
        <details><summary>Participantes anteriores (<?= count($part['anteriores']) ?>)</summary>
            <ul class="reorg-pessoas reorg-pessoas--antes"><?php foreach ($part['anteriores'] as $p): ?>
                <li><?= e($p['nome']) ?> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['sessao_id'])) ?></small> · <?= e(Reorganizacao::EQUIPES[$p['equipe']]) ?> · <?= e(admin_data_hora($p['atribuida_em'])) ?> → <?= e(admin_data_hora($p['encerrada_em'])) ?> (<?= e(str_replace('_', ' ', (string)$p['motivo_fim'])) ?>)</li>
            <?php endforeach; ?></ul></details>
    <?php endif; ?>
</section>

<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Grupo 2 e etapas</h2></div>
    <ul class="reorg-pessoas">
        <?php foreach ($adminsLote as $a): ?>
            <li><div><strong><?= e($a['nome']) ?></strong> <?= (int)$a['responsavel'] ? reorg_status_badge('Responsável', 'blue') : reorg_status_badge('Autorizado', 'indigo') ?></div>
                <?php if (!(int)$a['responsavel']): ?><form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="admin_remover"><input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>"><button class="adm-button adm-button--sm adm-button--danger-text" type="submit">Retirar</button></form><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php $outrosAdmins = array_filter(reorg_administradores(), fn ($a) => !in_array($a['id'], array_column($adminsLote, 'id'))); ?>
    <?php if ($outrosAdmins): ?>
    <form method="post" class="reorg-linha"><?= csrf_campo() ?><input type="hidden" name="acao" value="admin_incluir">
        <select class="adm-select adm-select--sm" name="admin_id" aria-label="Administrador"><?= reorg_opcoes($outrosAdmins, null) ?></select>
        <button class="adm-button adm-button--sm" type="submit">Autorizar a revisar</button></form>
    <?php endif; ?>
    <h3>Etapas</h3>
    <ul class="reorg-pessoas">
        <?php foreach (Reorganizacao::EQUIPES as $eq => $rot): $pausada = in_array($eq, $pausadas, true); ?>
            <li><div><?= e($rot) ?> <?= $pausada ? reorg_status_badge('Pausada', 'amber') : reorg_status_badge('Liberada', 'green') ?></div>
                <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="etapa"><input type="hidden" name="etapa" value="<?= $eq ?>"><input type="hidden" name="pausar" value="<?= $pausada ? '0' : '1' ?>">
                    <button class="adm-button adm-button--sm" type="submit"><?= $pausada ? 'Liberar' : 'Pausar' ?></button></form></li>
        <?php endforeach; ?>
    </ul>
    <div class="reorg-linha">
        <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="encaminhar_g2">
            <button class="adm-button adm-button--sm" type="submit" <?= $previaG2['exemplares'] ? '' : 'disabled' ?> data-reorg-confirmar="<?= e($previaG2['exemplares'] . ' exemplar(es) completos irão para a revisão; ' . $previaG2['restantes'] . ' continuam no levantamento. Confirmar?') ?>">Encaminhar completos do G1 (<?= $previaG2['exemplares'] ?>)</button></form>
        <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="encaminhar_g4">
            <button class="adm-button adm-button--sm" type="submit" <?= $previaG4['exemplares'] ? '' : 'disabled' ?> data-reorg-confirmar="<?= e($previaG4['exemplares'] . ' exemplar(es) carimbados irão para a conferência; ' . $previaG4['restantes'] . ' continuam sem carimbo. Confirmar?') ?>">Encaminhar carimbados ao G4 (<?= $previaG4['exemplares'] ?>)</button></form>
    </div>
</section>
</div>

<section class="adm-card adm-panel" id="revisao">
    <div class="adm-panel__header"><h2>Revisão do Grupo 2</h2></div>
    <nav class="adm-tabs adm-tabs--links reorg-filtros">
        <?php foreach ($filtros as $k => $rot): ?><a class="adm-tab<?= $filtro === $k ? ' is-active' : '' ?>" href="<?= e($aqui . '&f=' . $k) ?>#revisao"><?= e($rot) ?></a><?php endforeach; ?>
    </nav>
    <form method="post" data-reorg-selecao>
        <?= csrf_campo() ?><input type="hidden" name="volta" value="<?= e($filtro) ?>">
        <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
            <thead><tr><th><input type="checkbox" data-reorg-todos aria-label="Selecionar todos"></th><th>Nº</th><th>Título / autor</th><th>Editora · edição</th><th>ISBN</th><th>CDD</th><th>Qtd.</th><th>Conservação</th><th>Situação</th><th>Por</th></tr></thead>
            <tbody>
            <?php if (!$registros): ?><tr><td colspan="10" class="adm-table__muted">Nenhum registro neste filtro.</td></tr><?php endif; ?>
            <?php foreach ($registros as $g): [$st, $tom] = Levantamento::STATUS[$g['status']]; ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= (int)$g['id'] ?>" aria-label="Selecionar registro <?= (int)$g['numero'] ?>"></td>
                    <td><a href="<?= e(reorg_url('admin/registro.php?id=' . $g['id'])) ?>" class="adm-table__strong"><?= (int)$g['numero'] ?></a></td>
                    <td><a href="<?= e(reorg_url('admin/registro.php?id=' . $g['id'])) ?>"><?= e($g['titulo'] ?: '(sem título)') ?></a><br><small><?= e($g['autores'] ?: '—') ?></small><?= $g['livro_sugerido_id'] ? ' <span class="adm-status adm-tone--indigo">obra existente</span>' : '' ?></td>
                    <td><?= e(($g['editora'] ?: '—') . ($g['edicao'] ? ' · ' . $g['edicao'] : '')) ?></td>
                    <td class="adm-mono"><?= $g['isbn'] ? e($g['isbn']) : ((int)$g['isbn_nao_encontrado'] ? '<span class="adm-status adm-tone--amber">não encontrado</span>' : '—') ?></td>
                    <td class="adm-mono"><?= $g['cdd'] ? e($g['cdd']) : ((int)$g['cdd_nao_encontrada'] ? '<span class="adm-status adm-tone--amber">não encontrada</span>' : '—') ?></td>
                    <td><?= (int)$g['quantidade'] ?></td>
                    <td><small><?= e(implode(', ', array_map(fn ($v) => explode(' — ', Reorganizacao::CONSERVACAO[$v] ?? '?')[0], array_filter(explode(',', (string)$g['estados']))))) ?></small></td>
                    <td><?= reorg_status_badge($st, $tom) ?><?= !(int)$g['completo'] && in_array($g['status'], ['rascunho', 'correcao'], true) ? ' <small>incompleto</small>' : '' ?>
                        <?= $g['editando_ate'] && strtotime($g['editando_ate']) > time() ? '<br><small>Em edição: ' . e($g['editando_nome'] ?? $g['editando_admin_nome'] ?? '') . '</small>' : '' ?></td>
                    <td><small><?= e($g['criado_nome'] ?? '—') ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($souAdminDoLote): ?>
        <div class="reorg-linha">
            <button class="adm-button adm-button--primary adm-button--sm" type="submit" name="acao" value="aprovar_massa"><?= icon('check-check') ?> Aprovar selecionados</button>
            <button class="adm-button adm-button--sm" type="submit" name="acao" value="cadastrar" data-reorg-confirmar="Gerar os códigos BDP dos aprovados selecionados (ou de todos os aprovados, se nenhum estiver marcado)? Os códigos usam a numeração única do sistema."><?= icon('stamp') ?> Cadastrar e gerar códigos BDP</button>
            <span class="adm-field__hint">Aprovados sem cadastro: <?= $c['aprovados_sem_cadastro'] ?>. Sem seleção, “Cadastrar” processa todos os aprovados.</span>
        </div>
        <?php endif; ?>
    </form>
</section>

<div class="reorg-grade">
<section class="adm-card adm-panel">
    <h2>Ajustar quantidade final</h2>
    <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="quantidade">
        <div class="adm-form-grid">
            <label class="adm-field"><span class="adm-field__label">Nova quantidade (prevista inicial: <?= (int)$lote['quantidade_inicial'] ?>)</span><input class="adm-input" type="number" name="quantidade" min="1" value="<?= (int)$lote['quantidade_prevista'] ?>"></label>
            <label class="adm-field"><span class="adm-field__label">Justificativa</span><input class="adm-input" name="justificativa" required maxlength="255" placeholder="Ex.: havia mais 2 livros atrás da estante"></label>
        </div>
        <button class="adm-button adm-button--sm" type="submit">Ajustar</button></form>
    <h2>Dados do lote</h2>
    <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="alterar">
        <div class="adm-form-grid">
            <label class="adm-field"><span class="adm-field__label">Nome</span><input class="adm-input" name="nome" value="<?= e($lote['nome']) ?>" maxlength="120"></label>
            <label class="adm-field"><span class="adm-field__label">Prazo</span><input class="adm-input" type="date" name="prazo" value="<?= e((string)$lote['prazo']) ?>"></label>
            <label class="adm-field adm-field--full"><span class="adm-field__label">Instruções do lote</span><textarea class="adm-textarea" name="instrucoes" rows="2"><?= e((string)$lote['instrucoes']) ?></textarea></label>
            <label class="adm-field adm-field--full"><span class="adm-field__label">Observações internas</span><textarea class="adm-textarea" name="observacoes" rows="2"><?= e((string)$lote['observacoes']) ?></textarea></label>
        </div>
        <button class="adm-button adm-button--sm" type="submit">Salvar</button></form>
</section>

<section class="adm-card adm-panel">
    <h2>Concluir lote</h2>
    <?php if ($lote['status'] === 'concluido'): ?>
        <p>Lote concluído em <?= e(admin_data_hora($lote['concluido_em'])) ?>.</p>
    <?php elseif ($impedimentos): ?>
        <p>A conclusão só é possível quando todos os exemplares do escopo final passaram por todas as etapas. Falta:</p>
        <ul><?php foreach ($impedimentos as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
    <?php else: ?>
        <p>Todas as etapas foram concluídas para os <?= $c['prevista'] ?> exemplares.</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="concluir"><button class="adm-button adm-button--primary" type="submit"><?= icon('circle-check') ?> Concluir lote</button></form>
    <?php endif; ?>
    <h2>Pendências do lote</h2>
    <?php if (!$pendencias): ?><p class="adm-table__muted">Nenhuma pendência.</p><?php endif; ?>
    <ul class="reorg-lista-notif">
        <?php foreach (array_slice($pendencias, 0, 12) as $p): ?>
            <li class="<?= $p['status'] !== 'resolvida' ? 'is-nova' : '' ?>"><span><strong><?= e(Reorganizacao::TIPOS_PENDENCIA[$p['tipo']]) ?></strong> · <?= e($p['descricao']) ?><?= $p['codigo_individual'] ? ' · ' . e($p['codigo_individual']) : '' ?></span>
                <small><?= e($p['status']) ?> · <?= e($p['nome'] ?? $p['admin_nome'] ?? '') ?></small></li>
        <?php endforeach; ?>
    </ul>
    <a class="adm-link" href="<?= e(reorg_url('admin/pendencias.php?lote=' . $id)) ?>">Central de pendências</a>
    <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="pendencia">
        <div class="adm-form-grid">
            <select class="adm-select" name="tipo" aria-label="Tipo"><?php foreach (Reorganizacao::TIPOS_PENDENCIA as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
            <input class="adm-input" name="descricao" placeholder="Descrição" maxlength="1000">
        </div>
        <button class="adm-button adm-button--sm" type="submit">Registrar pendência</button></form>
</section>
</div>

<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Histórico do lote</h2><a class="adm-link" href="<?= e(reorg_url('admin/historico.php?lote_id=' . $id)) ?>">Histórico completo</a></div>
    <ul class="reorg-hist">
        <?php foreach ($historico as $h): ?>
            <li><small><?= e(admin_data_hora($h['criado_em'])) ?></small> <?= e($h['descricao']) ?> <small>— <?= e($h['nome'] ? $h['nome'] . ' (' . Reorganizacao::rotuloSessao($h['sessao_id']) . ')' : ($h['admin_nome'] ?? 'sistema')) ?></small></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php reorg_admin_fim();
