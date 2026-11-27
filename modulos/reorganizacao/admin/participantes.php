<?php
/**
 * Equipes e participantes: CONSULTA de quem está conectado (estado, atividade, lotes) e sessões anteriores.
 * Colocar alguém num lote é feito em dois lugares só: no quadro do lote (+ Adicionar pessoa) e no aviso
 * "pessoas aguardando lote" do inventário, cuja janela grava aqui (acao=atribuir_varios). Aqui ficam só
 * as ações que não existem em outro lugar: mudar a equipe e encerrar a sessão.
 */
require __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/quadro.php';

use Biblioteca\Reorganizacao\Reorganizacao;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $sid = (int)($_POST['sessao_id'] ?? 0);
    $voltar = reorg_voltar_seguro((string)($_POST['voltar'] ?? ''), (string)($_SERVER['REQUEST_URI'] ?? reorg_url('admin/participantes.php')));
    reorg_admin_acao(function () use ($acao, $r, $sid, $adminId) {
        switch ($acao) {
            case 'atribuir_varios':
                $n = 0;
                foreach ((array)($_POST['lote'] ?? []) as $sessao => $loteId) {
                    $s = $r->sessao((int)$sessao);
                    if ((int)$loteId > 0 && $s && $s['equipe'] && $s['status'] === 'ativa') {
                        $r->atribuir((int)$loteId, (int)$sessao, (string)$s['equipe'], $adminId);
                        $n++;
                    }
                }
                if (!$n) {
                    throw new Biblioteca\Services\AcervoException('Escolha o lote de ao menos uma pessoa.');
                }
                return $n . ' pessoa(s) colocada(s) no lote. A tela delas abre o lote na hora.';
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
    }, 'Feito.', $voltar);
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
$anteriores = $r->sessoesAnteriores(150);

$grupos = [
    'disponiveis' => ['Disponíveis (sem lote)', 'green', array_filter($lista, fn ($s) => $s['equipe'] !== null && !$s['lotes'] && $s['estado'] !== 'interrompida')],
    'trabalhando' => ['Trabalhando em lotes', 'blue', array_filter($lista, fn ($s) => $s['lotes'] && $s['estado'] !== 'interrompida')],
    'escolhendo' => ['Escolhendo a equipe', 'muted', array_filter($lista, fn ($s) => $s['equipe'] === null && $s['estado'] !== 'interrompida')],
    'interrompidas' => ['Conexão interrompida', 'amber', array_filter($lista, fn ($s) => $s['estado'] === 'interrompida')],
];
reorg_admin_inicio('participantes', 'Equipes e participantes');
?>
<section class="adm-page-head reorg-head">
    <div><h1>Equipes e participantes</h1>
        <p>Consulta de quem está conectado e onde está trabalhando. Para colocar alguém num lote, use o aviso “aguardando lote” acima ou o botão <strong>+ Adicionar pessoa</strong> no quadro do lote. Cada entrada é uma sessão temporária (S-0000), sem conta, e-mail ou senha. A página atualiza sozinha a cada 20 segundos.</p></div>
</section>
<form class="adm-card reorg-filtros-barra" method="get" data-reorg-auto-refresh="20" role="search" aria-label="Filtrar participantes">
    <label class="adm-field"><span class="adm-field__label">Equipe</span><select class="adm-select" name="equipe" data-reorg-auto-submit><option value="">Todas</option>
        <?php foreach (Reorganizacao::EQUIPES_PARTICIPANTES as $eq): ?><option value="<?= $eq ?>"<?= $equipe === $eq ? ' selected' : '' ?>><?= e(Reorganizacao::EQUIPES[$eq]) ?></option><?php endforeach; ?></select></label>
    <label class="adm-field"><span class="adm-field__label">Estado</span><select class="adm-select" name="estado" data-reorg-auto-submit><option value="">Todos</option>
        <?php foreach (Reorganizacao::ESTADOS_CONEXAO as $k => [$rot]): if (in_array($k, ['desconectado', 'encerrada'], true)) continue; ?><option value="<?= $k ?>"<?= $estado === $k ? ' selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></label>
    <div class="reorg-filtros-barra__acoes"><button class="adm-button" type="submit"><?= icon('search') ?> Filtrar</button>
        <?php if ($equipe || $estado): ?><a class="adm-link" href="<?= e(reorg_url('admin/participantes.php')) ?>">Limpar</a><?php endif; ?></div>
</form>

<section class="reorg-resumo-grade reorg-resumo-grade--4" aria-label="Resumo das equipes">
    <?php foreach (Reorganizacao::EQUIPES_PARTICIPANTES as $eq): $daEquipe = array_filter($lista, fn ($s) => $s['equipe'] === $eq && $s['estado'] !== 'interrompida'); ?>
        <div class="adm-card reorg-bloco"><h2><?= e(Reorganizacao::EQUIPES[$eq]) ?></h2>
            <dl class="reorg-numeros"><div><dt>Conectados</dt><dd><?= count($daEquipe) ?></dd></div><div><dt>Sem lote</dt><dd><?= count(array_filter($daEquipe, fn ($s) => !$s['lotes'])) ?></dd></div></dl></div>
    <?php endforeach; ?>
</section>

<?php foreach ($grupos as $k => [$titulo, $tom, $pessoas]): if (!$pessoas && in_array($k, ['escolhendo', 'interrompidas'], true)) continue; ?>
<section class="adm-card adm-panel">
    <div class="adm-panel__header"><h2><?= reorg_status_badge((string)count($pessoas), $tom) ?> <?= e($titulo) ?></h2></div>
    <?php if (!$pessoas): ?><p class="adm-table__muted">Ninguém neste grupo agora.</p><?php else: ?>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact reorg-tabela-pessoas">
        <thead><tr><th>Nome</th><th>Equipe</th><th>Estado</th><th>Atividade atual</th><th>Lotes</th><th>Produção</th><th><span class="adm-visually-hidden">Mais</span></th></tr></thead>
        <tbody>
        <?php foreach ($pessoas as $s): ?>
            <tr>
                <td><strong><?= e($s['nome']) ?></strong> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($s['id'])) ?></small>
                    <?php if ($s['mesmo_nome']): ?><br><span class="adm-status adm-tone--amber">nome repetido (+<?= (int)$s['mesmo_nome'] ?>)</span><?php endif; ?>
                    <?php if ($s['solicitacoes']): ?><br><a class="adm-status adm-tone--red" href="<?= e(reorg_url('admin/solicitacoes.php')) ?>"><?= (int)$s['solicitacoes'] ?> solicitação(ões)</a><?php endif; ?></td>
                <td><?= e($s['equipe'] ? Reorganizacao::EQUIPES[$s['equipe']] : 'Escolhendo') ?></td>
                <td><?= reorg_estado_badge($s['estado']) ?><br><small>últ. sinal <?= e(date('H:i:s', strtotime((string)$s['ultimo_sinal_em']))) ?></small></td>
                <td><small><?= e($s['atividade']) ?></small></td>
                <td><small><?= $s['lotes'] ? implode('<br>', array_map(fn ($l) => '<a href="' . e(reorg_url('admin/lote.php?id=' . $l['id'] . '&aba=participantes')) . '">' . e($l['nome']) . '</a>', $s['lotes'])) : '—' ?></small></td>
                <td><small><?= (int)$s['registros'] ?> reg. · <?= (int)$s['carimbos'] ?> carimb. · <?= (int)$s['conferencias'] ?> conf.</small></td>
                <td class="reorg-acoes">
                    <details class="reorg-mais"><summary class="adm-button adm-button--sm">Mais</summary>
                        <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="equipe"><input type="hidden" name="sessao_id" value="<?= (int)$s['id'] ?>">
                            <select class="adm-select adm-select--sm" name="equipe" aria-label="Nova equipe de <?= e($s['nome']) ?>"><?php foreach (Reorganizacao::EQUIPES_PARTICIPANTES as $eq): if ($eq === $s['equipe']) continue; ?><option value="<?= $eq ?>"><?= e(Reorganizacao::EQUIPES[$eq]) ?></option><?php endforeach; ?></select>
                            <button class="adm-button adm-button--sm" type="submit">Mudar equipe</button></form>
                        <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="encerrar"><input type="hidden" name="sessao_id" value="<?= (int)$s['id'] ?>">
                            <button class="adm-button adm-button--sm adm-button--danger-text" type="submit" data-reorg-confirmar="Encerrar a sessão de <?= e($s['nome']) ?>? O que foi feito continua salvo; a pessoa precisa entrar de novo e não recupera as permissões.">Encerrar sessão</button></form>
                        <a class="adm-link" href="<?= e(reorg_url('admin/historico.php?sessao_id=' . $s['id'])) ?>">Histórico</a>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<details class="adm-card adm-panel reorg-detalhes">
    <summary>Sessões anteriores (<?= count($anteriores) ?>)</summary>
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
</details>
<?php reorg_admin_fim();
