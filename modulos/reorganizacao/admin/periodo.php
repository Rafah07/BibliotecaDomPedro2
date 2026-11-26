<?php
/** Controle do período: iniciar, editar instruções e encerrar (com avisos quando falta algo). */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();
$periodo = $r->periodoAtivo();
$avisos = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'iniciar') {
        reorg_admin_acao(fn () => $r->iniciarPeriodo($_POST, $adminId) ? 'Período iniciado: o botão “Equipe de organização” já aparece na tela de login.' : '', 'Período iniciado.');
    } elseif ($acao === 'salvar' && $periodo) {
        reorg_admin_acao(fn () => $r->atualizarPeriodo((int)$periodo['id'], $_POST, $adminId), 'Configurações do período salvas.');
    } elseif ($acao === 'encerrar' && $periodo) {
        admin_exigir_csrf();
        try {
            $avisos = $r->encerrarPeriodo((int)$periodo['id'], $adminId, ($_POST['confirmar'] ?? '') === '1');
            if (!$avisos) {
                admin_flash('sucesso', 'Período encerrado', 'O botão sumiu da tela de login, as sessões abertas foram encerradas e todo o histórico continua guardado.');
                admin_redirecionar(reorg_url('admin/periodo.php'));
            }
        } catch (Biblioteca\Services\AcervoException $e) {
            admin_flash('erro', 'Não foi possível encerrar', $e->getMessage());
            admin_redirecionar(reorg_url('admin/periodo.php'));
        }
    }
}

$p = $periodo ?? ['nome' => '', 'inicio' => date('Y-m-d'), 'previsao_fim' => '', 'instrucoes_gerais' => '', 'instrucoes_grupo1' => '', 'instrucoes_grupo2' => '', 'instrucoes_grupo3' => '', 'instrucoes_grupo4' => ''];
reorg_admin_inicio('periodo', 'Período da reorganização');
?>
<section class="adm-page-head">
    <div>
        <h1><?= $periodo ? 'Período ativo' : 'Iniciar período de reorganização' ?></h1>
        <p>Com um período ativo, a tela de login mostra o botão discreto “Equipe de organização” e os participantes entram só com o nome. Ao encerrar, o botão some e as sessões abertas são encerradas; nada é apagado.</p>
    </div>
</section>

<?php if ($avisos): ?>
    <section class="adm-notice adm-notice--amber" role="alert"><?= icon('triangle-alert') ?>
        <div><strong>Ainda há trabalho em aberto neste período:</strong>
            <ul><?php foreach ($avisos as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
            <p>Se encerrar mesmo assim, nada é apagado: lotes, registros, pendências e histórico ficam guardados para consulta.</p>
            <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="encerrar"><input type="hidden" name="confirmar" value="1">
                <button class="adm-button adm-button--danger" type="submit"><?= icon('pause') ?> Encerrar mesmo assim</button>
                <a class="adm-button" href="<?= e(reorg_url('admin/periodo.php')) ?>">Cancelar</a></form>
        </div>
    </section>
<?php endif; ?>

<section class="adm-card adm-panel">
    <form method="post" class="reorg-form">
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="<?= $periodo ? 'salvar' : 'iniciar' ?>">
        <div class="adm-form-grid">
            <label class="adm-field"><span class="adm-field__label">Nome do período <span class="adm-required">*</span></span>
                <input class="adm-input" name="nome" required maxlength="120" value="<?= e($p['nome']) ?>" placeholder="Reorganização do acervo 2026"></label>
            <label class="adm-field"><span class="adm-field__label">Início</span><input class="adm-input" type="date" name="inicio" value="<?= e($p['inicio']) ?>"></label>
            <label class="adm-field"><span class="adm-field__label">Previsão de encerramento (opcional)</span><input class="adm-input" type="date" name="previsao_fim" value="<?= e((string)$p['previsao_fim']) ?>"></label>
            <label class="adm-field adm-field--full"><span class="adm-field__label">Instruções gerais (todas as equipes)</span><textarea class="adm-textarea" name="instrucoes_gerais" rows="3"><?= e((string)$p['instrucoes_gerais']) ?></textarea></label>
            <?php foreach (['grupo1' => 'Grupo 1 — Levantamento', 'grupo2' => 'Grupo 2 — Revisão (administração)', 'grupo3' => 'Grupo 3 — Carimbação', 'grupo4' => 'Grupo 4 — Conferência e organização'] as $g => $rot): ?>
                <label class="adm-field"><span class="adm-field__label">Instruções do <?= e($rot) ?></span><textarea class="adm-textarea" name="instrucoes_<?= $g ?>" rows="3"><?= e((string)$p['instrucoes_' . $g]) ?></textarea></label>
            <?php endforeach; ?>
        </div>
        <div class="adm-form-actions adm-form-actions--static">
            <button class="adm-button adm-button--primary" type="submit"><?= icon($periodo ? 'save' : 'play') ?> <?= $periodo ? 'Salvar alterações' : 'Ativar período' ?></button>
        </div>
    </form>
</section>

<?php if ($periodo && !$avisos): ?>
<section class="adm-card adm-panel">
    <h2>Encerrar o período</h2>
    <p>Antes de encerrar, o sistema confere se há lotes não concluídos, rascunhos, registros aguardando revisão, pendências ou solicitações e avisa.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="encerrar">
        <button class="adm-button adm-button--danger-text" type="submit"><?= icon('pause') ?> Desativar período</button></form>
</section>
<?php endif; ?>

<section class="adm-card adm-panel">
    <h2>Períodos</h2>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Nome</th><th>Início</th><th>Previsão</th><th>Situação</th><th>Criado por</th><th>Encerrado em</th></tr></thead>
        <tbody><?php foreach ($r->periodos() as $x): ?>
            <tr><td><?= e($x['nome']) ?></td><td><?= e(admin_data_hora($x['inicio'], false)) ?></td><td><?= e($x['previsao_fim'] ? admin_data_hora($x['previsao_fim'], false) : '—') ?></td>
                <td><?= (int)$x['ativo'] ? reorg_status_badge('Ativo', 'green') : reorg_status_badge('Encerrado', 'muted') ?></td><td><?= e($x['criado_por_nome']) ?></td><td><?= e($x['encerrado_em'] ? admin_data_hora($x['encerrado_em']) : '—') ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
<?php reorg_admin_fim();
