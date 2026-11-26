<?php
/** Solicitações dos participantes: novo lote, ajuda e troca de equipe. */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reorg_admin_acao(function () use ($r, $adminId) {
        $r->responderSolicitacao((int)$_POST['id'], (string)$_POST['decisao'], $adminId, (string)($_POST['resposta'] ?? ''),
            ['lote_id' => (int)($_POST['lote_id'] ?? 0), 'admin_id' => (int)($_POST['admin_id'] ?? 0)]);
        return 'Solicitação respondida (a pessoa é notificada).';
    }, 'Feito.');
}

$status = (string)($_GET['status'] ?? 'pendente');
$tipo = (string)($_GET['tipo'] ?? '');
$validosStatus = ['pendente' => 'Pendentes', 'aprovada' => 'Aprovadas', 'recusada' => 'Recusadas', 'respondida' => 'Respondidas', 'resolvida' => 'Resolvidas', 'encaminhada' => 'Encaminhadas', '' => 'Todas'];
$validosTipo = ['' => 'Todos os tipos', 'novo_lote' => 'Novo lote', 'ajuda' => 'Ajuda', 'troca_equipe' => 'Troca de equipe'];
$status = isset($validosStatus[$status]) ? $status : 'pendente';
$tipo = isset($validosTipo[$tipo]) ? $tipo : '';
$lista = $r->solicitacoes($status, $tipo);
$lotes = $r->lotes('em_andamento');
$admins = reorg_administradores();

reorg_admin_inicio('solicitacoes', 'Solicitações');
?>
<section class="adm-page-head"><div><h1>Solicitações</h1><p>Pedidos de novo lote, de ajuda e de alteração de equipe. Nenhum lote é atribuído automaticamente: a decisão é sempre da administração.</p></div></section>
<nav class="adm-tabs adm-tabs--links adm-tabs--solta">
    <?php foreach ($validosStatus as $k => $rot): ?><a class="adm-tab<?= $status === $k ? ' is-active' : '' ?>" href="?<?= e(http_build_query(['status' => $k, 'tipo' => $tipo])) ?>"><?= e($rot) ?></a><?php endforeach; ?>
    <?php foreach ($validosTipo as $k => $rot): ?><a class="adm-tab<?= $tipo === $k ? ' is-active' : '' ?>" href="?<?= e(http_build_query(['status' => $status, 'tipo' => $k])) ?>"><?= e($rot) ?></a><?php endforeach; ?>
</nav>
<?php if (!$lista): ?><section class="adm-card adm-empty"><p>Nenhuma solicitação.</p></section><?php endif; ?>
<?php foreach ($lista as $q): $ativa = $q['sessao_status'] === 'ativa'; ?>
<article class="adm-card adm-panel reorg-sol">
    <header><strong><?= e(['novo_lote' => 'Novo lote', 'ajuda' => 'Ajuda', 'troca_equipe' => 'Troca de equipe'][$q['tipo']]) ?></strong>
        · <?= e($q['nome']) ?> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($q['sessao_id'])) ?></small>
        <?= $q['equipe_atual'] ? ' · ' . e(Reorganizacao::EQUIPES[$q['equipe_atual']]) : '' ?>
        <?= $q['equipe_desejada'] ? ' → <strong>' . e(Reorganizacao::EQUIPES[$q['equipe_desejada']]) . '</strong>' : '' ?>
        <?= $q['lote_nome'] ? ' · ' . e($q['lote_nome']) : '' ?>
        · <small><?= e(admin_data_hora($q['criado_em'])) ?></small>
        <?= reorg_status_badge($q['status'], $q['status'] === 'pendente' ? 'red' : 'muted') ?>
        <?= !$ativa ? reorg_status_badge('sessão encerrada', 'muted') : '' ?></header>
    <?php if ($q['problema_tipo'] || $q['etapa']): ?><p><small><?= e(implode(' · ', array_filter([$q['etapa'], $q['problema_tipo']]))) ?></small></p><?php endif; ?>
    <?php if ($q['descricao']): ?><p><?= nl2br(e($q['descricao'])) ?></p><?php endif; ?>
    <?php if ($q['resposta']): ?><p class="reorg-resposta"><strong>Resposta<?= $q['respondida_por_nome'] ? ' de ' . e($q['respondida_por_nome']) : '' ?>:</strong> <?= e($q['resposta']) ?></p><?php endif; ?>
    <?php if (in_array($q['status'], ['pendente', 'encaminhada'], true)): ?>
    <form method="post" class="reorg-linha"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
        <input class="adm-input" name="resposta" placeholder="Resposta ou observação (opcional)" maxlength="1000">
        <?php if ($q['tipo'] === 'novo_lote'): ?>
            <?php if ($ativa && $lotes): ?><select class="adm-select adm-select--sm" name="lote_id" aria-label="Lote"><?= reorg_opcoes($lotes, null) ?></select>
                <button class="adm-button adm-button--sm adm-button--primary" name="decisao" value="atribuir">Atribuir este lote</button><?php endif; ?>
            <button class="adm-button adm-button--sm" name="decisao" value="aprovar">Aprovar (atribuo depois)</button>
            <a class="adm-button adm-button--sm" href="<?= e(reorg_url('admin/?novo=1#novo-lote')) ?>">Criar lote</a>
            <button class="adm-button adm-button--sm" name="decisao" value="pendente">Manter pendente</button>
            <button class="adm-button adm-button--sm adm-button--danger-text" name="decisao" value="recusar">Recusar</button>
        <?php elseif ($q['tipo'] === 'troca_equipe'): ?>
            <?php if ($ativa): ?><button class="adm-button adm-button--sm adm-button--primary" name="decisao" value="aprovar">Aprovar troca</button><?php endif; ?>
            <button class="adm-button adm-button--sm adm-button--danger-text" name="decisao" value="recusar">Recusar</button>
        <?php else: ?>
            <button class="adm-button adm-button--sm adm-button--primary" name="decisao" value="responder">Responder</button>
            <button class="adm-button adm-button--sm" name="decisao" value="resolver">Marcar resolvido</button>
            <select class="adm-select adm-select--sm" name="admin_id" aria-label="Encaminhar para"><?= reorg_opcoes(array_filter($admins, fn ($a) => (int)$a['id'] !== $adminId), null) ?></select>
            <button class="adm-button adm-button--sm" name="decisao" value="encaminhar">Encaminhar</button>
        <?php endif; ?>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
<?php reorg_admin_fim();
