<?php
/**
 * Área das equipes de um lote do inventário (o lote é o lote permanente; esta é a extensão temporária),
 * com abas internas: Visão geral · Grupo 1 · Grupo 2 · Grupo 3 · Grupo 4 · Participantes · Pendências ·
 * Histórico · Relatório e Planilha BDP (página permanente do lote). Fica dentro das abas do inventário.
 * Cada ação volta para a mesma aba e o mesmo filtro.
 */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Levantamento;
use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;

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
    admin_pagina_erro('Lote não encontrado', 'Confira o endereço ou volte à lista de lotes da reorganização.');
    exit;
}

$aba = (string)($_GET['aba'] ?? $_POST['aba'] ?? 'visao');
if ($aba === 'relatorio') {
    admin_redirecionar(admin_url('lote') . '?id=' . (int)$lote['cadastro_lote_id']); // relatório e Planilha BDP: página permanente do lote
}
$aba = isset(REORG_ABAS_LOTE[$aba]) ? $aba : 'visao';
$filtro = (string)($_GET['f'] ?? $_POST['f'] ?? '');
$base = reorg_url('admin/lote.php?id=' . $id);
$urlAba = fn (string $a, string $f = '') => $base . '&aba=' . $a . ($f !== '' ? '&f=' . rawurlencode($f) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
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
                $n = 0;
                foreach ((array)($_POST['sessoes'] ?? []) as $sid) {
                    $s = $r->sessao((int)$sid);
                    if ($s && $s['equipe'] && $s['status'] === 'ativa') {
                        $r->atribuir($id, (int)$sid, (string)$s['equipe'], $adminId);
                        $n++;
                    }
                }
                if (!$n) {
                    throw new AcervoException('Marque ao menos uma pessoa para atribuir.');
                }
                return $n . ' participante(s) atribuído(s) e notificado(s).';
            case 'remover': $r->removerDoLote($id, (int)$_POST['sessao_id'], $adminId); return 'Participante retirado do lote. O que foi preenchido continua salvo.';
            case 'transferir': $r->transferirLote((int)$_POST['sessao_id'], $id, (int)$_POST['para'], $adminId); return 'Participante transferido para o outro lote.';
            case 'encaminhar_g2': $x = $lev->encaminhar($id, null, $adminId); return $x['exemplares'] . ' exemplar(es) encaminhados à revisão; ' . $x['restantes'] . ' continuam no levantamento.';
            case 'encaminhar_g4': $x = $et->encaminharG4($id, null, $adminId); return $x['exemplares'] . ' exemplar(es) encaminhados à conferência; ' . $x['restantes'] . ' ainda sem carimbo.';
            case 'aprovar_massa':
            case 'aprovar_cadastrar_massa':
                if (!$ids) {
                    throw new AcervoException('Marque os registros que deseja aprovar.');
                }
                $x = $lev->aprovarEmMassa($adminId, $id, $ids);
                $msg = $x['aprovados'] . ' registro(s) aprovado(s).';
                foreach ($x['pulados'] as $num => $motivo) {
                    $msg .= ' Nº ' . $num . ' não aprovado: ' . (is_array($motivo) ? implode(' ', $motivo) : $motivo);
                }
                if ($acao === 'aprovar_massa') {
                    return $msg;
                }
                // segue para o cadastro só dos que foram aprovados agora
                $y = $lev->cadastrarAprovados($adminId, $id, $ids, reorg_registrar_cdd($usuario));
                return $msg . ' ' . reorg_msg_cadastro($y);
            case 'cadastrar':
                return reorg_msg_cadastro($lev->cadastrarAprovados($adminId, $id, $ids ?: null, reorg_registrar_cdd($usuario)));
            case 'concluir':
                $x = $r->concluirLote($id, $adminId);
                return 'Lote concluído. ' . implode(' ', $x['avisos']);
            case 'pendencia':
                $r->registrarPendencia($id, (string)$_POST['tipo'], (string)($_POST['descricao'] ?? ''), (string)($_POST['grupo'] ?? 'grupo2'), null, $adminId);
                return 'Pendência registrada.';
            case 'pendencia_status':
                $r->atualizarPendencia((int)$_POST['pendencia_id'], (string)$_POST['status'], $adminId, (string)($_POST['resolucao'] ?? ''));
                return 'Pendência atualizada.';
        }
        throw new AcervoException('Ação desconhecida.');
    }, 'Feito.', $urlAba($aba, $filtro));
}

/** Mensagem do cadastro em lote (códigos BDP gerados e erros por registro). */
function reorg_msg_cadastro(array $x): string
{
    $msg = count($x['cadastrados']) . ' registro(s) cadastrado(s) no acervo: ' . array_sum(array_map(fn ($c) => count($c['codigos']), $x['cadastrados'])) . ' código(s) BDP gerado(s) e liberados ao Grupo 3.';
    foreach ($x['erros'] as $num => $erro) {
        $msg .= ' Nº ' . $num . ': ' . $erro;
    }
    return $msg;
}

$c = $r->contadores($id);
$etapas = Reorganizacao::etapasEmAndamento($c);
$pausadas = array_filter(explode(',', (string)$lote['etapas_pausadas']));
$adminsLote = $r->adminsDoLote($id);
$souAdminDoLote = in_array($adminId, array_map('intval', array_column($adminsLote, 'id')), true);
$part = $r->participantesDoLote($id);
$porEquipe = ['grupo1' => [], 'grupo3' => [], 'grupo4' => []];
foreach ($part['atuais'] as $p) {
    $porEquipe[$p['equipe']][] = $p;
}
$pendAbertas = $c['pendencias_abertas'];

$nomeLote = 'Lote ' . str_pad((string)$lote['cadastro_numero'], 3, '0', STR_PAD_LEFT) . ' · ' . $lote['nome'];
reorg_admin_inicio('lotes', $nomeLote, [[$nomeLote, $aba === 'visao' ? null : $base], ...($aba === 'visao' ? [] : [[REORG_ABAS_LOTE[$aba][0], null]])], (int)$lote['inventario_id'] ?: null);
?>
<section class="adm-card reorg-lote-topo">
    <div class="reorg-lote-topo__id">
        <h1><?= e($nomeLote) ?> <?= reorg_status_lote($lote) ?></h1>
        <p>Responsável: <strong><?= e($lote['responsavel_nome']) ?></strong> · Criado em <?= e(admin_data_hora($lote['criado_em'], false)) ?>
            <?= $lote['prazo'] ? ' · Prazo: ' . e(admin_data_hora($lote['prazo'], false)) : '' ?>
            · Etapa do lote: <?= e(ExemplarService::rotuloStatusLote($lote['cadastro_status'])) ?></p>
    </div>
    <dl class="reorg-lote-topo__num">
        <div><dt>Prevista</dt><dd><?= $c['prevista'] ?></dd></div>
        <div><dt>Organizados</dt><dd><?= $c['organizados'] ?></dd></div>
        <div><dt>Restantes</dt><dd><?= $c['restantes'] ?></dd></div>
        <div class="reorg-lote-topo__barra"><dt>Progresso</dt><dd><?= $c['percentual'] ?>%<?= reorg_barra($c['organizados'], $c['prevista']) ?></dd></div>
    </dl>
    <div class="reorg-lote-topo__acoes">
        <?php if ($lote['status'] === 'em_andamento'): ?>
            <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="aba" value="<?= $aba ?>"><input type="hidden" name="acao" value="pausar"><button class="adm-button adm-button--sm" type="submit" data-reorg-confirmar="Pausar o lote inteiro? Os participantes ficam sem poder alterar até a retomada (nada se perde)."><?= icon('pause') ?> Pausar lote</button></form>
        <?php elseif ($lote['status'] === 'pausado'): ?>
            <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="aba" value="<?= $aba ?>"><input type="hidden" name="acao" value="retomar"><button class="adm-button adm-button--sm adm-button--primary" type="submit"><?= icon('play') ?> Retomar lote</button></form>
        <?php endif; ?>
        <a class="adm-button adm-button--sm" href="<?= e(reorg_url('admin/historico.php?lote_id=' . $id . '&exportar=csv')) ?>"><?= icon('download') ?> Exportar histórico</a>
    </div>
</section>

<?= reorg_abas_lote_html($lote, $aba) ?>

<?php if (!$souAdminDoLote && in_array($aba, ['g2', 'visao'], true)): ?>
    <?= admin_aviso('aviso', 'Você não é responsável nem autorizado neste lote', 'Pode acompanhar, mas a revisão, a aprovação e o cadastro ficam com o responsável e os administradores autorizados (aba Participantes).') ?>
<?php endif; ?>

<?php
// =====================================================================
// Visão geral do lote
// =====================================================================
if ($aba === 'visao'):
    $impedimentos = $lote['status'] !== 'concluido' ? $r->impedimentosConclusao($id) : [];
    $resumo = $lote['resumo_conclusao'] ? json_decode($lote['resumo_conclusao'], true) : null;
    $linhas = [
        'grupo1' => ['Registrados no levantamento', $c['registrados'], $c['prevista'], 'g1'],
        'grupo2' => ['Revisados e cadastrados (código BDP)', $c['codigos'], $c['prevista'], 'g2'],
        'grupo3' => ['Carimbados', $c['carimbados'], $c['prevista'], 'g3'],
        'grupo4' => ['Conferidos / organizados', $c['organizados'], $c['prevista'], 'g4'],
    ];
?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Progresso por etapa</h2><span class="adm-table__muted">Exemplares físicos · uma etapa pode ser pausada sem pausar o lote</span></div>
    <ul class="reorg-etapas-lista">
        <?php foreach ($linhas as $eq => [$rot, $feito, $total, $abaEq]): $pausada = in_array($eq, $pausadas, true); ?>
            <li>
                <a class="reorg-etapas-lista__nome" href="<?= e($urlAba($abaEq)) ?>"><strong><?= e(Reorganizacao::EQUIPES[$eq]) ?></strong><small><?= e($rot) ?></small></a>
                <div class="reorg-etapas-lista__barra"><span><?= (int)$feito ?> de <?= (int)$total ?><?= !empty($etapas[$eq]) ? ' · ' . (int)$etapas[$eq] . ' esperando' : '' ?></span><?= reorg_barra((int)$feito, (int)$total) ?></div>
                <div><?= $lote['status'] === 'concluido' ? reorg_status_badge('Concluída', 'green') : ($pausada ? reorg_status_badge('Pausada', 'amber') : reorg_status_badge('Liberada', 'green')) ?></div>
                <?php if ($lote['status'] !== 'concluido'): ?><form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="aba" value="visao"><input type="hidden" name="acao" value="etapa"><input type="hidden" name="etapa" value="<?= $eq ?>"><input type="hidden" name="pausar" value="<?= $pausada ? '0' : '1' ?>">
                    <button class="adm-button adm-button--sm" type="submit"><?= $pausada ? icon('play') . ' Liberar' : icon('pause') . ' Pausar' ?></button></form><?php else: ?><span></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<div class="reorg-duas">
    <section class="adm-card adm-panel">
        <div class="adm-panel__header"><h2>Precisa de atenção</h2></div>
        <?php
        $at = [];
        if ($c['aguardando_revisao']) { $at[] = ['amber', $c['aguardando_revisao'] . ' exemplar(es) aguardando revisão', $urlAba('g2', 'pendentes')]; }
        if ($c['aprovados_sem_cadastro']) { $at[] = ['blue', $c['aprovados_sem_cadastro'] . ' exemplar(es) aprovados sem cadastro (sem código BDP)', $urlAba('g2', 'aprovados')]; }
        if ($c['carimbados_nao_encaminhados']) { $at[] = ['blue', $c['carimbados_nao_encaminhados'] . ' carimbado(s) ainda não encaminhados ao Grupo 4', $urlAba('g3')]; }
        if ($pendAbertas) { $at[] = ['red', $pendAbertas . ' pendência(s) aberta(s)', $urlAba('pendencias')]; }
        if ($c['ajuda_pendente']) { $at[] = ['red', $c['ajuda_pendente'] . ' pedido(s) de ajuda', reorg_url('admin/solicitacoes.php?tipo=ajuda')]; }
        foreach (['grupo1', 'grupo3', 'grupo4'] as $eq) {
            if (!empty($etapas[$eq]) && !$porEquipe[$eq] && $lote['status'] === 'em_andamento') {
                $at[] = ['amber', Reorganizacao::EQUIPES[$eq] . ': ' . $etapas[$eq] . ' exemplar(es) esperando e ninguém atribuído', $urlAba('participantes')];
            }
        }
        ?>
        <?php if (!$at): ?><p class="adm-table__muted">Nada pendente neste lote agora.</p><?php endif; ?>
        <ul class="reorg-atencao"><?php foreach ($at as [$tom, $txt, $url]): ?><li><span class="adm-status adm-tone--<?= $tom ?>">!</span> <a href="<?= e($url) ?>"><?= e($txt) ?></a></li><?php endforeach; ?></ul>
        <h3>Conservação registrada</h3>
        <p>Excelente <strong><?= $c['excelente'] ?></strong> · Regular <strong><?= $c['regular'] ?></strong> · Ruim <strong><?= $c['ruim'] ?></strong></p>
    </section>
    <section class="adm-card adm-panel">
        <div class="adm-panel__header"><h2>Concluir lote</h2></div>
        <?php if ($lote['status'] === 'concluido'): ?>
            <p>Lote concluído em <?= e(admin_data_hora($lote['concluido_em'])) ?>.</p>
        <?php elseif ($impedimentos): ?>
            <p>A conclusão fica disponível quando todos os exemplares do lote passarem por todas as etapas. Falta:</p>
            <ul><?php foreach ($impedimentos as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
            <p>Todas as etapas foram concluídas para os <?= $c['prevista'] ?> exemplares.</p>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="aba" value="visao"><input type="hidden" name="acao" value="concluir"><button class="adm-button adm-button--primary" type="submit"><?= icon('circle-check') ?> Concluir lote</button></form>
        <?php endif; ?>
    </section>
</div>

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
    <form method="post" class="reorg-linha"><?= csrf_campo() ?><input type="hidden" name="aba" value="visao"><input type="hidden" name="acao" value="reabrir">
        <input class="adm-input" name="motivo" placeholder="Motivo da reabertura" required maxlength="255" aria-label="Motivo da reabertura"><button class="adm-button" type="submit"><?= icon('rotate-ccw') ?> Reabrir lote</button></form>
</section>
<?php endif; ?>

<details class="adm-card adm-panel reorg-detalhes">
    <summary><?= icon('pencil') ?> Editar dados do lote e ajustar a quantidade final</summary>
    <div class="reorg-duas">
        <div class="reorg-form">
            <h3>Dados do lote</h3>
            <p>Nome, prazo, instruções (os participantes veem) e observações ficam no lote do inventário.</p>
            <a class="adm-button adm-button--sm" href="<?= e(admin_url('lote') . '?id=' . (int)$lote['cadastro_lote_id']) ?>#titulo-info"><?= icon('pencil') ?> Editar no relatório do lote</a>
        </div>
        <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="aba" value="visao"><input type="hidden" name="acao" value="quantidade">
            <h3>Quantidade final</h3>
            <label class="adm-field"><span class="adm-field__label">Nova quantidade (prevista inicial: <?= (int)$lote['quantidade_inicial'] ?>)</span><input class="adm-input" type="number" name="quantidade" min="1" value="<?= (int)$lote['quantidade_prevista'] ?>"></label>
            <label class="adm-field"><span class="adm-field__label">Justificativa</span><input class="adm-input" name="justificativa" required maxlength="255" placeholder="Ex.: havia mais 2 livros atrás da estante"></label>
            <button class="adm-button adm-button--sm" type="submit">Ajustar quantidade</button>
        </form>
    </div>
</details>

<?php
// =====================================================================
// Grupo 1 — Levantamento
// =====================================================================
elseif ($aba === 'g1'):
    $f = in_array($filtro, ['rascunhos', 'correcao', 'pendentes', 'todos'], true) ? $filtro : 'rascunhos';
    $regs = $lev->registrosDoLote($id, $f);
    $previaG2 = $lev->previaEncaminhamento($id);
?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Levantamento</h2><?= in_array('grupo1', $pausadas, true) ? reorg_status_badge('Etapa pausada', 'amber') : '' ?></div>
    <dl class="reorg-numeros reorg-numeros--linha">
        <div><dt>Prevista</dt><dd><?= $c['prevista'] ?></dd></div>
        <div><dt>Registrados</dt><dd><?= $c['registrados'] ?></dd></div>
        <div><dt>Ainda sem registro</dt><dd><?= $c['aguardando_levantamento'] ?></dd></div>
        <div><dt>Em levantamento</dt><dd><?= $c['em_levantamento'] ?></dd></div>
        <div><dt>Participantes</dt><dd><?= count($porEquipe['grupo1']) ?></dd></div>
    </dl>
    <?= reorg_barra($c['registrados'], $c['prevista']) ?>
    <div class="reorg-linha">
        <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="aba" value="g1"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="acao" value="encaminhar_g2">
            <button class="adm-button adm-button--sm adm-button--primary" type="submit" <?= $previaG2['exemplares'] ? '' : 'disabled' ?> data-reorg-confirmar="<?= e($previaG2['exemplares'] . ' exemplar(es) completos serão encaminhados à revisão; ' . $previaG2['restantes'] . ' continuam no levantamento. Confirmar?') ?>"><?= icon('send') ?> Encaminhar completos à revisão (<?= $previaG2['exemplares'] ?>)</button></form>
        <span class="adm-field__hint">Só vão os registros completos e salvos. Os incompletos continuam com o Grupo 1.</span>
    </div>
</section>
<section class="adm-card adm-panel">
    <nav class="adm-tabs adm-tabs--links reorg-filtros" aria-label="Filtro do levantamento">
        <?php foreach (['rascunhos' => 'Em levantamento', 'correcao' => 'Devolvidos para correção', 'pendentes' => 'Já encaminhados', 'todos' => 'Todos'] as $k => $rot): ?><a class="adm-tab<?= $f === $k ? ' is-active' : '' ?>" href="<?= e($urlAba('g1', $k)) ?>"><?= e($rot) ?></a><?php endforeach; ?>
    </nav>
    <?= reorg_tabela_registros($regs, $urlAba('g1', $f), false) ?>
</section>

<?php
// =====================================================================
// Grupo 2 — Revisão
// =====================================================================
elseif ($aba === 'g2'):
    $filtros = ['pendentes' => 'Aguardando revisão', 'aprovados' => 'Aprovados (sem cadastro)', 'cadastrados' => 'Cadastrados', 'correcao' => 'Devolvidos ao G1',
        'sem_isbn' => 'ISBN não encontrado', 'sem_cdd' => 'CDD não encontrada', 'ruim' => 'Conservação ruim', 'rascunhos' => 'Ainda no G1', 'descartados' => 'Descartados', 'todos' => 'Todos'];
    $f = isset($filtros[$filtro]) ? $filtro : 'pendentes';
    $regs = $lev->registrosDoLote($id, $f);
?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Revisão e cadastramento</h2><?= in_array('grupo2', $pausadas, true) ? reorg_status_badge('Etapa pausada', 'amber') : '' ?></div>
    <dl class="reorg-numeros reorg-numeros--linha">
        <div><dt>Aguardando revisão</dt><dd><?= $c['aguardando_revisao'] ?></dd></div>
        <div><dt>Aprovados sem cadastro</dt><dd><?= $c['aprovados_sem_cadastro'] ?></dd></div>
        <div><dt>Com código BDP</dt><dd><?= $c['codigos'] ?></dd></div>
        <div><dt>Devolvidos ao G1</dt><dd><?= (int)($c['registros_por_status']['correcao'] ?? 0) ?> reg.</dd></div>
    </dl>
    <p class="adm-field__hint">Abra um registro para revisar campo a campo, comparar com a obra existente e aprovar. Os botões abaixo processam só os registros marcados que estão completos e válidos; os demais são listados com o motivo.</p>
</section>
<section class="adm-card adm-panel">
    <nav class="adm-tabs adm-tabs--links reorg-filtros" aria-label="Filtro da revisão">
        <?php foreach ($filtros as $k => $rot): ?><a class="adm-tab<?= $f === $k ? ' is-active' : '' ?>" href="<?= e($urlAba('g2', $k)) ?>"><?= e($rot) ?></a><?php endforeach; ?>
    </nav>
    <form method="post" data-reorg-selecao>
        <?= csrf_campo() ?><input type="hidden" name="aba" value="g2"><input type="hidden" name="f" value="<?= e($f) ?>">
        <?= reorg_tabela_registros($regs, $urlAba('g2', $f), true) ?>
        <?php if ($souAdminDoLote && $regs): ?>
        <div class="reorg-barra-acoes">
            <?php if (in_array($f, ['pendentes', 'sem_isbn', 'sem_cdd', 'ruim', 'todos'], true)): ?>
                <button class="adm-button adm-button--primary adm-button--sm" type="submit" name="acao" value="aprovar_cadastrar_massa" data-reorg-confirmar="Aprovar os registros marcados e cadastrá-los no acervo (gera os códigos BDP e libera ao Grupo 3)?"><?= icon('check-check') ?> Aprovar e cadastrar marcados</button>
                <button class="adm-button adm-button--sm" type="submit" name="acao" value="aprovar_massa"><?= icon('check') ?> Só aprovar marcados</button>
            <?php endif; ?>
            <?php if (in_array($f, ['aprovados', 'todos'], true) && $c['aprovados_sem_cadastro']): ?>
                <button class="adm-button adm-button--primary adm-button--sm" type="submit" name="acao" value="cadastrar" data-reorg-confirmar="Cadastrar no acervo os aprovados marcados (ou todos os aprovados, se nenhum estiver marcado)? Os códigos BDP usam a numeração única do sistema e o Grupo 3 recebe os exemplares."><?= icon('stamp') ?> Cadastrar e liberar ao Grupo 3</button>
            <?php endif; ?>
            <span class="adm-field__hint" data-reorg-marcados>Nenhum marcado.</span>
        </div>
        <?php endif; ?>
    </form>
</section>

<?php
// =====================================================================
// Grupo 3 — Carimbação
// =====================================================================
elseif ($aba === 'g3'):
    $f = in_array($filtro, ['pendentes', 'carimbados', 'problemas', 'todos'], true) ? $filtro : 'pendentes';
    $lista = $et->planilhaCarimbacao($id, $f);
    $previaG4 = $et->previaEncaminhamentoG4($id);
?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Carimbação</h2><?= in_array('grupo3', $pausadas, true) ? reorg_status_badge('Etapa pausada', 'amber') : '' ?>
        <a class="adm-link" href="<?= e(url('admin/planilha-bdp.php?aba=todos&lote=' . $lote['cadastro_lote_id'])) ?>"><?= icon('external-link') ?> Planilha BDP do sistema</a></div>
    <dl class="reorg-numeros reorg-numeros--linha">
        <div><dt>Com código BDP</dt><dd><?= $c['codigos'] ?></dd></div>
        <div><dt>Aguardando carimbo</dt><dd><?= $c['aguardando_carimbacao'] ?></dd></div>
        <div><dt>Carimbados</dt><dd><?= $c['carimbados'] ?></dd></div>
        <div><dt>Ainda não encaminhados ao G4</dt><dd><?= $c['carimbados_nao_encaminhados'] ?></dd></div>
    </dl>
    <div class="reorg-linha">
        <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="aba" value="g3"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="acao" value="encaminhar_g4">
            <button class="adm-button adm-button--sm adm-button--primary" type="submit" <?= $previaG4['exemplares'] ? '' : 'disabled' ?> data-reorg-confirmar="<?= e($previaG4['exemplares'] . ' exemplar(es) carimbados serão encaminhados à conferência; ' . $previaG4['restantes'] . ' continuam sem carimbo. Confirmar?') ?>"><?= icon('send') ?> Encaminhar carimbados ao Grupo 4 (<?= $previaG4['exemplares'] ?>)</button></form>
    </div>
</section>
<section class="adm-card adm-panel">
    <nav class="adm-tabs adm-tabs--links reorg-filtros" aria-label="Filtro da carimbação">
        <?php foreach (['pendentes' => 'Aguardando carimbo', 'carimbados' => 'Carimbados', 'problemas' => 'Com problema', 'todos' => 'Todos'] as $k => $rot): ?><a class="adm-tab<?= $f === $k ? ' is-active' : '' ?>" href="<?= e($urlAba('g3', $k)) ?>"><?= e($rot) ?></a><?php endforeach; ?>
    </nav>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table reorg-planilha">
        <thead><tr><th>Código BDP</th><th>Título</th><th>Autor</th><th>Editora</th><th>Edição</th><th>Conservação</th><th>Situação</th></tr></thead>
        <tbody>
        <?php if (!$lista): ?><tr><td colspan="7" class="adm-table__muted">Nenhum exemplar neste filtro.</td></tr><?php endif; ?>
        <?php foreach ($lista as $x): ?>
            <tr><td class="reorg-codigo"><?= e($x['codigo_individual']) ?></td><td><?= e($x['titulo']) ?></td><td><?= e($x['autores'] ?? '') ?></td><td><?= e($x['editora'] ?? '') ?></td><td><?= e($x['edicao'] ?? '') ?></td>
                <td><?= reorg_conservacao_badge($x['estado_conservacao']) ?></td>
                <td><?php if ($x['carimbado_em']): ?><?= reorg_status_badge('Carimbado', 'green') ?> <small><?= e($x['carimbado_nome']) ?></small><?= $x['encaminhado_g4_em'] ? ' ' . reorg_status_badge('No G4', 'blue') : '' ?>
                    <?php elseif ($x['reservado_nome']): ?><?= reorg_status_badge('Com ' . $x['reservado_nome'], 'indigo') ?><?php else: ?><?= reorg_status_badge('Aguardando', 'muted') ?><?php endif; ?>
                    <?= (int)$x['problema_aberto'] ? reorg_status_badge('Problema', 'red') : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<?php
// =====================================================================
// Grupo 4 — Conferência e organização
// =====================================================================
elseif ($aba === 'g4'):
    $f = in_array($filtro, ['aguardando', 'conferidos', 'organizados', 'todos'], true) ? $filtro : 'aguardando';
    $lista = $et->exemplaresG4($id, $f);
?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Conferência e organização</h2><?= in_array('grupo4', $pausadas, true) ? reorg_status_badge('Etapa pausada', 'amber') : '' ?></div>
    <dl class="reorg-numeros reorg-numeros--linha">
        <div><dt>Aguardando conferência</dt><dd><?= $c['aguardando_conferencia'] ?></dd></div>
        <div><dt>Conferidos</dt><dd><?= $c['conferidos'] ?></dd></div>
        <div><dt>Organizados</dt><dd><?= $c['organizados'] ?></dd></div>
        <div><dt>Com problema</dt><dd><?= $c['com_problema'] ?></dd></div>
    </dl>
    <p class="adm-field__hint">O Grupo 4 digita o código BDP escrito no livro, confere as informações e guarda o exemplar. A localização e as regras de empréstimo continuam as do sistema.</p>
</section>
<section class="adm-card adm-panel">
    <nav class="adm-tabs adm-tabs--links reorg-filtros" aria-label="Filtro da conferência">
        <?php foreach (['aguardando' => 'Aguardando conferência', 'conferidos' => 'Conferidos (falta guardar)', 'organizados' => 'Organizados', 'todos' => 'Todos'] as $k => $rot): ?><a class="adm-tab<?= $f === $k ? ' is-active' : '' ?>" href="<?= e($urlAba('g4', $k)) ?>"><?= e($rot) ?></a><?php endforeach; ?>
    </nav>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table reorg-planilha">
        <thead><tr><th>Código BDP</th><th>Título</th><th>Editora · edição</th><th>Conservação</th><th>Conferência</th><th>Organização</th></tr></thead>
        <tbody>
        <?php if (!$lista): ?><tr><td colspan="6" class="adm-table__muted">Nenhum exemplar neste filtro.</td></tr><?php endif; ?>
        <?php foreach ($lista as $x): ?>
            <tr><td class="reorg-codigo"><?= e($x['codigo_individual']) ?></td><td><?= e($x['titulo']) ?></td><td><?= e(trim(($x['editora'] ?? '') . ($x['edicao'] ? ' · ' . $x['edicao'] : ''), ' ·')) ?></td>
                <td><?= reorg_conservacao_badge($x['estado_conservacao']) ?></td>
                <td><?= $x['conferido_em'] ? reorg_status_badge('Conferido', 'green') . ' <small>' . e($x['conferido_nome']) . '</small>' : reorg_status_badge('Aguardando', 'muted') ?><?= (int)$x['problema_aberto'] ? ' ' . reorg_status_badge('Problema', 'red') : '' ?></td>
                <td><?= $x['organizado_em'] ? reorg_status_badge('Guardado', 'green') . ' <small>' . e($x['organizado_nome'] . ($x['local_rotulo'] ? ' · ' . $x['local_rotulo'] : '')) . '</small>' : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<?php
// =====================================================================
// Participantes
// =====================================================================
elseif ($aba === 'participantes'):
    require_once __DIR__ . '/../includes/quadro.php';
    $ativos = array_filter($r->participantesAtivos(), fn ($s) => $s['equipe'] !== null && $s['estado'] !== 'interrompida');
    $jaNoLote = array_map('intval', array_column($part['atuais'], 'sessao_id'));
    $outrosLotes = array_filter($r->lotes('em_andamento'), fn ($l) => (int)$l['id'] !== $id);
    $outrosAdmins = array_filter(reorg_administradores(), fn ($a) => !in_array((int)$a['id'], array_map('intval', array_column($adminsLote, 'id')), true));
    $aberto = $lote['status'] !== 'concluido';
?>
<section class="reorg-quadro-topo">
    <div><h2>Quem trabalha neste lote</h2>
        <p>Cada coluna é uma etapa. Use <strong>+ Adicionar pessoa</strong> para colocar alguém. A pessoa precisa ter entrado pelo botão “Equipe de organização” na tela de login e escolhido a equipe.</p></div>
</section>
<div class="reorg-quadro" role="list">
    <?php foreach (REORG_QUADRO_COLUNAS as $eq => [$sigla, $etapa]):
        $pessoas = $eq === 'grupo2' ? $adminsLote : $porEquipe[$eq];
        $livres = $eq === 'grupo2' ? [] : array_filter($ativos, fn ($s) => $s['equipe'] === $eq && !in_array((int)$s['id'], $jaNoLote, true)); ?>
    <section class="reorg-quadro__col" role="listitem" aria-label="<?= e($etapa) ?>">
        <?= reorg_quadro_cabecalho($eq, count($pessoas)) ?>
        <?= reorg_quadro_progresso($eq, $c) ?>
        <ul class="reorg-quadro__pessoas">
            <?php if (!$pessoas): ?><li class="reorg-quadro__vazio">Ninguém nesta etapa ainda.</li><?php endif; ?>
            <?php if ($eq === 'grupo2'): foreach ($adminsLote as $a): ?>
                <li class="reorg-cartao"><div class="reorg-cartao__id"><strong><?= e($a['nome']) ?></strong>
                    <small><?= (int)$a['responsavel'] ? 'Responsável pelo lote' : 'Usuário do painel autorizado' ?></small></div>
                    <?php if (!(int)$a['responsavel'] && $aberto): ?>
                    <form method="post" class="reorg-cartao__acoes"><?= csrf_campo() ?><input type="hidden" name="aba" value="participantes"><input type="hidden" name="acao" value="admin_remover"><input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                        <button class="reorg-cartao__x" type="submit" title="Retirar <?= e($a['nome']) ?>" aria-label="Retirar <?= e($a['nome']) ?>" data-reorg-confirmar="Retirar <?= e($a['nome']) ?> da revisão deste lote?"><?= icon('x') ?></button></form>
                    <?php endif; ?></li>
            <?php endforeach; else: foreach ($porEquipe[$eq] as $p): ?>
                <li class="reorg-cartao"><div class="reorg-cartao__id"><strong><?= e($p['nome']) ?></strong>
                    <small><span class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['sessao_id'])) ?></span> · <?= reorg_estado_badge($r->estadoConexao(['status' => $p['sessao_status'], 'ultimo_sinal_em' => $p['ultimo_sinal_em'], 'situacao' => $p['situacao'], 'equipe' => $p['equipe_sessao'], 'motivo_encerramento' => $p['motivo_encerramento']])) ?></small></div>
                    <?php if ($aberto): ?>
                    <details class="reorg-cartao__menu"><summary aria-label="Opções de <?= e($p['nome']) ?>" title="Opções"><?= icon('ellipsis') ?></summary>
                        <div class="reorg-cartao__pop">
                            <?php if ($outrosLotes): ?>
                            <form method="post"><?= csrf_campo() ?><input type="hidden" name="aba" value="participantes"><input type="hidden" name="acao" value="transferir"><input type="hidden" name="sessao_id" value="<?= (int)$p['sessao_id'] ?>">
                                <label class="adm-field__sublabel" for="transf-<?= (int)$p['sessao_id'] ?>">Passar para outro lote</label>
                                <div class="reorg-linha"><select class="adm-select adm-select--sm" id="transf-<?= (int)$p['sessao_id'] ?>" name="para"><?= reorg_opcoes($outrosLotes, null) ?></select><button class="adm-button adm-button--sm" type="submit">Passar</button></div></form>
                            <?php endif; ?>
                            <form method="post"><?= csrf_campo() ?><input type="hidden" name="aba" value="participantes"><input type="hidden" name="acao" value="remover"><input type="hidden" name="sessao_id" value="<?= (int)$p['sessao_id'] ?>">
                                <button class="adm-button adm-button--sm adm-button--danger-text" type="submit" data-reorg-confirmar="Retirar <?= e($p['nome']) ?> deste lote? O que foi preenchido continua salvo."><?= icon('user-minus') ?> Retirar do lote</button></form>
                        </div></details>
                    <?php endif; ?></li>
            <?php endforeach; endif; ?>
        </ul>
        <?php if ($aberto): ?>
        <button type="button" class="reorg-quadro__add" data-reorg-dialogo="add-<?= $eq ?>"><?= icon('plus') ?> Adicionar pessoa</button>
        <?= reorg_janela_inicio('add-' . $eq, 'Adicionar pessoa · ' . $etapa, e($nomeLote)) ?>
            <form method="post" class="adm-modal__body"><?= csrf_campo() ?><input type="hidden" name="aba" value="participantes">
            <?php if ($eq === 'grupo2'): ?>
                <input type="hidden" name="acao" value="admin_incluir">
                <?php if ($outrosAdmins): ?>
                    <p>A revisão é feita por usuários do painel. Escolha quem pode revisar e cadastrar este lote.</p>
                    <label class="adm-field"><span class="adm-field__label">Usuário do painel</span><select class="adm-select" name="admin_id"><?= reorg_opcoes($outrosAdmins, null) ?></select></label>
                    <div class="adm-modal__footer reorg-janela__rodape"><button type="button" class="adm-button adm-button--ghost" data-reorg-fechar>Cancelar</button><button class="adm-button adm-button--primary" type="submit"><?= icon('user-plus') ?> Adicionar</button></div>
                <?php else: ?>
                    <p class="reorg-vazio">Todos os usuários do painel já revisam este lote.</p>
                <?php endif; ?>
            <?php else: ?>
                <input type="hidden" name="acao" value="atribuir">
                <?php if ($livres): ?>
                    <p>Pessoas conectadas na equipe de <?= e(mb_strtolower($etapa)) ?>. Marque quem vai trabalhar neste lote; a tela delas abre o lote na hora.</p>
                    <ul class="reorg-escolhas reorg-escolhas--janela">
                        <?php foreach ($livres as $s): ?>
                            <li><label class="reorg-escolha"><input type="checkbox" name="sessoes[]" value="<?= (int)$s['id'] ?>">
                                <span><strong><?= e($s['nome']) ?></strong> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($s['id'])) ?></small><?= $s['mesmo_nome'] ? ' <span class="adm-status adm-tone--amber">nome repetido</span>' : '' ?>
                                    <small class="reorg-escolha__estado"><?= reorg_quadro_situacao($s) ?></small></span></label></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="adm-modal__footer reorg-janela__rodape"><button type="button" class="adm-button adm-button--ghost" data-reorg-fechar>Cancelar</button><button class="adm-button adm-button--primary" type="submit"><?= icon('user-plus') ?> Adicionar marcados</button></div>
                <?php else: ?>
                    <div class="reorg-janela__vazio"><?= icon('user-search') ?><strong>Ninguém da equipe de <?= e(mb_strtolower($etapa)) ?> disponível agora.</strong>
                        <span>Peça para a pessoa abrir a tela de login do painel, tocar em “Equipe de organização”, digitar o nome e escolher <?= e($sigla . ' — ' . $etapa) ?>. Depois volte aqui.</span></div>
                <?php endif; ?>
            <?php endif; ?>
            </form>
        <?= reorg_janela_fim() ?>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>
<?= reorg_quadro_js() ?>
<?php if ($part['anteriores']): ?>
<details class="adm-card adm-panel reorg-detalhes"><summary>Participantes anteriores (<?= count($part['anteriores']) ?>)</summary>
    <ul class="reorg-pessoas reorg-pessoas--antes"><?php foreach ($part['anteriores'] as $p): ?>
        <li><?= e($p['nome']) ?> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['sessao_id'])) ?></small> · <?= e(Reorganizacao::EQUIPES[$p['equipe']]) ?> · <?= e(admin_data_hora($p['atribuida_em'])) ?> até <?= e(admin_data_hora($p['encerrada_em'])) ?> (<?= e(str_replace('_', ' ', (string)$p['motivo_fim'])) ?>)</li>
    <?php endforeach; ?></ul></details>
<?php endif; ?>

<?php
// =====================================================================
// Pendências
// =====================================================================
elseif ($aba === 'pendencias'):
    $f = in_array($filtro, ['abertas', 'resolvida', 'todas'], true) ? $filtro : 'abertas';
    $lista = $r->pendencias($f === 'todas' ? '' : $f, $id);
?>
<section class="adm-card adm-panel">
    <nav class="adm-tabs adm-tabs--links reorg-filtros" aria-label="Filtro das pendências">
        <?php foreach (['abertas' => 'Abertas e em análise', 'resolvida' => 'Resolvidas', 'todas' => 'Todas'] as $k => $rot): ?><a class="adm-tab<?= $f === $k ? ' is-active' : '' ?>" href="<?= e($urlAba('pendencias', $k)) ?>"><?= e($rot) ?></a><?php endforeach; ?>
    </nav>
    <?= reorg_tabela_pendencias($lista, false, ['aba' => 'pendencias', 'f' => $f]) ?>
</section>
<details class="adm-card adm-panel reorg-detalhes"><summary><?= icon('plus') ?> Registrar pendência neste lote</summary>
    <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="aba" value="pendencias"><input type="hidden" name="acao" value="pendencia">
        <div class="reorg-campos reorg-campos--3">
            <label class="adm-field"><span class="adm-field__label">Tipo</span><select class="adm-select" name="tipo"><?php foreach (Reorganizacao::TIPOS_PENDENCIA as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label class="adm-field"><span class="adm-field__label">Etapa</span><select class="adm-select" name="grupo"><?php foreach (Reorganizacao::EQUIPES as $k => $v): ?><option value="<?= $k ?>"<?= $k === 'grupo2' ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label class="adm-field"><span class="adm-field__label">Descrição</span><input class="adm-input" name="descricao" maxlength="1000"></label>
        </div>
        <button class="adm-button adm-button--sm" type="submit">Registrar pendência</button></form>
</details>

<?php
// =====================================================================
// Histórico
// =====================================================================
else:
    $hist = $r->historicoLista(['lote_id' => $id], 300);
?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2>Histórico do lote</h2><a class="adm-link" href="<?= e(reorg_url('admin/historico.php?lote_id=' . $id)) ?>">Filtrar no histórico geral</a></div>
    <?php if (!$hist): ?><p class="adm-table__muted">Nenhuma atividade ainda.</p><?php endif; ?>
    <ul class="reorg-hist">
        <?php foreach ($hist as $h): ?>
            <li><small><?= e(admin_data_hora($h['criado_em'])) ?></small> <?= e($h['descricao']) ?> <small>— <?= e($h['nome'] ? $h['nome'] . ' (' . Reorganizacao::rotuloSessao($h['sessao_id']) . ')' : ($h['admin_nome'] ?? 'sistema')) ?></small></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php reorg_admin_fim();
