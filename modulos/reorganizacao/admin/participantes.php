<?php
/** Participantes: sessões ativas (estado, atividade, lotes), atribuição, troca de equipe, encerramento forçado e sessões anteriores. */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $sid = (int)($_POST['sessao_id'] ?? 0);
    reorg_admin_acao(function () use ($acao, $r, $sid, $adminId) {
        switch ($acao) {
            case 'atribuir':
                $s = $r->sessao($sid);
                if (!$s || !$s['equipe']) {
                    throw new Biblioteca\Services\AcervoException('A pessoa ainda não escolheu a equipe.');
                }
                $r->atribuir((int)$_POST['lote_id'], $sid, (string)$s['equipe'], $adminId);
                return 'Lote atribuído (a pessoa recebe a notificação na hora).';
            case 'equipe': $r->transferirEquipe($sid, (string)$_POST['equipe'], $adminId); return 'Equipe alterada. Atribua um lote da nova equipe.';
            case 'encerrar': $r->encerrarForcado($sid, $adminId); return 'Sessão encerrada. O que foi preenchido continua salvo no lote.';
        }
        throw new Biblioteca\Services\AcervoException('Ação desconhecida.');
    }, 'Feito.');
}

$equipe = (string)($_GET['equipe'] ?? '');
$estado = (string)($_GET['estado'] ?? '');
if ($equipe !== '' && !in_array($equipe, Reorganizacao::EQUIPES_PARTICIPANTES, true)) {
    $equipe = '';
}
if ($estado !== '' && !isset(Reorganizacao::ESTADOS_CONEXAO[$estado])) {
    $estado = '';
}
$lista = $r->participantesAtivos($equipe, $estado);
$lotes = array_merge($r->lotes('em_andamento'), $r->lotes('pausado'));
$anteriores = $r->sessoesAnteriores(150);

reorg_admin_inicio('participantes', 'Participantes');
?>
<section class="adm-page-head">
    <div><h1>Participantes</h1>
        <p>Cada entrada é uma sessão temporária (identificador S-0000), sem conta, e-mail ou senha. Nomes iguais aparecem como sessões diferentes. A página atualiza sozinha a cada 20 segundos.</p></div>
</section>
<form class="adm-card adm-filters reorg-filtros-form" method="get" data-reorg-auto-refresh="20">
    <label class="adm-field"><span class="adm-field__label">Equipe</span><select class="adm-select" name="equipe" onchange="this.form.submit()"><option value="">Todas</option>
        <?php foreach (Reorganizacao::EQUIPES_PARTICIPANTES as $eq): ?><option value="<?= $eq ?>"<?= $equipe === $eq ? ' selected' : '' ?>><?= e(Reorganizacao::EQUIPES[$eq]) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Estado</span><select class="adm-select" name="estado" onchange="this.form.submit()"><option value="">Todos</option>
        <?php foreach (Reorganizacao::ESTADOS_CONEXAO as $k => [$rot]): if (in_array($k, ['desconectado', 'encerrada'], true)) continue; ?><option value="<?= $k ?>"<?= $estado === $k ? ' selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></label>
</form>

<section class="adm-card adm-panel">
    <h2>Sessões ativas <span class="adm-tabs__count"><?= count($lista) ?></span></h2>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Nome</th><th>Equipe</th><th>Estado</th><th>Atividade atual</th><th>Lotes</th><th>Produção</th><th>Últ. comunicação</th><th>Ações</th></tr></thead>
        <tbody>
        <?php if (!$lista): ?><tr><td colspan="8" class="adm-table__muted">Ninguém conectado com estes filtros.</td></tr><?php endif; ?>
        <?php foreach ($lista as $s): ?>
            <tr>
                <td><strong><?= e($s['nome']) ?></strong> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($s['id'])) ?></small>
                    <?php if ($s['mesmo_nome']): ?><br><span class="adm-status adm-tone--amber">mais <?= (int)$s['mesmo_nome'] ?> sessão(ões) com este nome</span><?php endif; ?>
                    <?php if ($s['solicitacoes']): ?><br><a class="adm-status adm-tone--red" href="<?= e(reorg_url('admin/solicitacoes.php')) ?>"><?= (int)$s['solicitacoes'] ?> solicitação(ões)</a><?php endif; ?></td>
                <td><?= e($s['equipe'] ? Reorganizacao::EQUIPES[$s['equipe']] : 'Escolhendo') ?></td>
                <td><?= reorg_estado_badge($s['estado']) ?></td>
                <td><small><?= e($s['atividade']) ?></small></td>
                <td><small><?= $s['lotes'] ? implode('<br>', array_map(fn ($l) => '<a href="' . e(reorg_url('admin/lote.php?id=' . $l['id'])) . '">' . e($l['nome']) . '</a>', $s['lotes'])) : '—' ?></small></td>
                <td><small><?= (int)$s['registros'] ?> reg. · <?= (int)$s['carimbos'] ?> carimb. · <?= (int)$s['conferencias'] ?> conf.</small></td>
                <td><small><?= e(admin_data_hora($s['ultimo_sinal_em'])) ?></small></td>
                <td class="reorg-acoes">
                    <?php if ($s['equipe'] && $lotes): ?>
                    <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="atribuir"><input type="hidden" name="sessao_id" value="<?= (int)$s['id'] ?>">
                        <select class="adm-select adm-select--sm" name="lote_id" aria-label="Lote"><?= reorg_opcoes($lotes, null) ?></select><button class="adm-button adm-button--sm" type="submit">Atribuir</button></form>
                    <?php endif; ?>
                    <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="equipe"><input type="hidden" name="sessao_id" value="<?= (int)$s['id'] ?>">
                        <select class="adm-select adm-select--sm" name="equipe" aria-label="Nova equipe"><?php foreach (Reorganizacao::EQUIPES_PARTICIPANTES as $eq): if ($eq === $s['equipe']) continue; ?><option value="<?= $eq ?>"><?= e(Reorganizacao::EQUIPES[$eq]) ?></option><?php endforeach; ?></select>
                        <button class="adm-button adm-button--sm" type="submit">Mudar equipe</button></form>
                    <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="encerrar"><input type="hidden" name="sessao_id" value="<?= (int)$s['id'] ?>">
                        <button class="adm-button adm-button--sm adm-button--danger-text" type="submit" data-reorg-confirmar="Encerrar a sessão de <?= e($s['nome']) ?>? O que foi feito continua salvo; a pessoa precisa entrar de novo e não recupera as permissões.">Encerrar sessão</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="adm-card adm-panel">
    <h2>Sessões anteriores</h2>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Nome</th><th>Equipe</th><th>Início</th><th>Fim</th><th>Motivo</th><th>Lotes</th><th>Produção</th><th></th></tr></thead>
        <tbody>
        <?php if (!$anteriores): ?><tr><td colspan="8" class="adm-table__muted">Nenhuma sessão encerrada.</td></tr><?php endif; ?>
        <?php foreach ($anteriores as $s): ?>
            <tr><td><?= e($s['nome']) ?> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($s['id'])) ?></small></td>
                <td><?= e($s['equipe'] ? Reorganizacao::EQUIPES[$s['equipe']] : '—') ?></td>
                <td><small><?= e(admin_data_hora($s['iniciada_em'])) ?></small></td><td><small><?= e(admin_data_hora($s['encerrada_em'])) ?></small></td>
                <td><small><?= e(Reorganizacao::motivoTexto($s['motivo_encerramento'])) ?><?= $s['encerrada_por_nome'] ? ' (' . e($s['encerrada_por_nome']) . ')' : '' ?></small></td>
                <td><small><?= e((string)$s['lotes'] ?: '—') ?></small></td>
                <td><small><?= (int)$s['registros'] ?> reg. · <?= (int)$s['carimbos'] ?> carimb. · <?= (int)$s['conferencias'] ?> conf.</small></td>
                <td><a class="adm-link" href="<?= e(reorg_url('admin/historico.php?sessao_id=' . $s['id'])) ?>">Histórico</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php reorg_admin_fim();
