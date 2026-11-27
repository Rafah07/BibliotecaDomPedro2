<?php
/**
 * Configurações das equipes (aba Configurações do inventário): instruções gerais e por equipe, tempos
 * das sessões temporárias, exportação do histórico e encerramento. A ativação fica na configuração do
 * inventário ("Ativar organização por equipes"): todo período pertence a um inventário.
 */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();
$periodo = $r->periodoAtivo();
$avisos = [];
$aqui = reorg_url('admin/configuracoes.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'salvar' && $periodo) {
        reorg_admin_acao(fn () => $r->atualizarPeriodo((int)$periodo['id'], $_POST, $adminId), 'Período e instruções salvos.', $aqui . '#periodo');
    } elseif ($acao === 'tempos') {
        reorg_admin_acao(fn () => $r->salvarConfiguracoes($_POST, $adminId), 'Tempos das sessões salvos. Valem para as próximas comunicações dos navegadores.', $aqui . '#tempos');
    } elseif ($acao === 'encerrar' && $periodo) {
        admin_exigir_csrf();
        try {
            $avisos = $r->encerrarPeriodo((int)$periodo['id'], $adminId, ($_POST['confirmar'] ?? '') === '1');
            if (!$avisos) {
                admin_flash('sucesso', 'Período encerrado', 'O botão sumiu da tela de login, as sessões abertas foram encerradas e todo o histórico continua guardado.');
                admin_redirecionar($aqui);
            }
        } catch (AcervoException $e) {
            admin_flash('erro', 'Não foi possível encerrar', $e->getMessage());
            admin_redirecionar($aqui . '#periodo');
        }
    }
}

$cfg = reorg_config();
$coletaAtiva = config_bool('COLETA_LINKS_ATIVOS', false);
$p = $periodo ?? ['nome' => '', 'inicio' => date('Y-m-d'), 'previsao_fim' => '', 'instrucoes_gerais' => '', 'instrucoes_grupo1' => '', 'instrucoes_grupo2' => '', 'instrucoes_grupo3' => '', 'instrucoes_grupo4' => ''];
reorg_admin_inicio('configuracoes', 'Configurações das equipes', [], $periodo['inventario_id'] ?? null);
$invCfg = $GLOBALS['reorg_inventario'] ?? null;
?>
<section class="adm-page-head reorg-head"><div><h1>Configurações das equipes</h1><p>Instruções que os participantes leem, tempos das sessões temporárias, exportações e orientações para encerrar.
    <?php if ($invCfg): ?><a class="adm-link" href="<?= e(admin_url('inventario-editar') . '?id=' . (int)$invCfg['id']) ?>">Dados do inventário e ativação das equipes</a><?php endif; ?></p></div></section>

<nav class="reorg-sumario adm-card" aria-label="Seções desta página">
    <a href="#periodo">Ativação e período</a><a href="#instrucoes">Instruções</a><a href="#tempos">Tempos das sessões</a><a href="#exportar">Exportar</a><a href="#encerrar">Encerramento</a><a href="#modulo">Módulo e integrações</a>
</nav>

<?php if ($avisos): ?>
    <section class="adm-notice adm-notice--amber" role="alert"><?= icon('triangle-alert') ?>
        <div><strong>Ainda há trabalho em aberto neste período:</strong>
            <ul><?php foreach ($avisos as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
            <p>Se encerrar mesmo assim, nada é apagado: lotes, registros, pendências e histórico ficam guardados para consulta.</p>
            <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="encerrar"><input type="hidden" name="confirmar" value="1">
                <button class="adm-button adm-button--danger" type="submit"><?= icon('pause') ?> Encerrar mesmo assim</button>
                <a class="adm-button" href="<?= e($aqui) ?>">Cancelar</a></form>
        </div>
    </section>
<?php endif; ?>

<?php if (!$periodo): ?>
    <section class="adm-card adm-panel" id="periodo">
        <div class="adm-panel__header"><h2>Ativação</h2><?= reorg_status_badge('Desativada', 'muted') ?></div>
        <p>A organização por equipes é ativada no inventário: abra o inventário → <strong>Configurações</strong> → “Ativar organização por equipes”. Só um inventário por vez usa as equipes.</p>
        <a class="adm-button adm-button--primary" href="<?= e(admin_url('inventario')) ?>"><?= icon('clipboard-check') ?> Ir para os inventários</a>
    </section>
<?php endif; ?>
<form method="post" class="reorg-form"<?= $periodo ? '' : ' hidden' ?>>
    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="salvar">
    <section class="adm-card adm-panel" id="periodo">
        <div class="adm-panel__header"><h2>Período das equipes</h2><?= $periodo ? reorg_status_badge('Ativo', 'green') : reorg_status_badge('Parado', 'muted') ?></div>
        <p>Com as equipes ativas, a tela de login mostra o botão discreto “Equipe de organização” e os participantes entram só com o nome.<?= $periodo && $periodo['inventario_titulo'] ? ' Inventário: <strong>' . e($periodo['inventario_titulo']) . '</strong>.' : '' ?></p>
        <div class="reorg-campos reorg-campos--3">
            <label class="adm-field"><span class="adm-field__label">Nome do período <span class="adm-required">*</span></span>
                <input class="adm-input" name="nome" required maxlength="120" value="<?= e($p['nome']) ?>" placeholder="Reorganização do acervo 2026"></label>
            <label class="adm-field"><span class="adm-field__label">Início</span><input class="adm-input" type="date" name="inicio" value="<?= e($p['inicio']) ?>"></label>
            <label class="adm-field"><span class="adm-field__label">Previsão de encerramento <span class="adm-optional">opcional</span></span><input class="adm-input" type="date" name="previsao_fim" value="<?= e((string)$p['previsao_fim']) ?>"></label>
        </div>
    </section>
    <section class="adm-card adm-panel" id="instrucoes">
        <h2>Instruções</h2>
        <p class="adm-field__hint">As instruções gerais aparecem logo depois do nome; as da equipe, na escolha da equipe e no botão “Instruções” do painel do participante.</p>
        <label class="adm-field"><span class="adm-field__label">Instruções gerais (todas as equipes)</span><textarea class="adm-textarea" name="instrucoes_gerais" rows="4" maxlength="5000"><?= e((string)$p['instrucoes_gerais']) ?></textarea></label>
        <div class="reorg-campos reorg-campos--2">
            <?php foreach (['grupo1' => 'Grupo 1 — Levantamento', 'grupo2' => 'Grupo 2 — Revisão (administração)', 'grupo3' => 'Grupo 3 — Carimbação', 'grupo4' => 'Grupo 4 — Conferência e organização'] as $g => $rot): ?>
                <label class="adm-field"><span class="adm-field__label"><?= e($rot) ?></span><textarea class="adm-textarea" name="instrucoes_<?= $g ?>" rows="3" maxlength="5000"><?= e((string)$p['instrucoes_' . $g]) ?></textarea></label>
            <?php endforeach; ?>
        </div>
        <div class="reorg-botoes"><button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar período e instruções</button></div>
    </section>
</form>

<section class="adm-card adm-panel" id="tempos">
    <h2>Tempos das sessões temporárias</h2>
    <form method="post" class="reorg-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="tempos">
        <div class="reorg-campos reorg-campos--3">
            <?php foreach (Reorganizacao::CONFIG_EDITAVEL as $chave => [$rot, $min, $max]): ?>
                <label class="adm-field"><span class="adm-field__label"><?= e($rot) ?></span>
                    <input class="adm-input" type="number" name="<?= $chave ?>" min="<?= $min ?>" max="<?= $max ?>" value="<?= (int)$cfg[$chave] ?>">
                    <span class="adm-field__hint">Em segundos, de <?= $min ?> a <?= $max ?>.</span></label>
            <?php endforeach; ?>
        </div>
        <p class="adm-field__hint">O tempo sem comunicação precisa ser pelo menos o dobro da comunicação periódica, mais 10 segundos. Limites fixos (config.php): <?= (int)$cfg['max_exemplares_registro'] ?> exemplares por registro, <?= (int)$cfg['max_exemplares_lote'] ?> por lote, <?= (int)$cfg['limite_entradas_minuto'] ?> entradas por rede por minuto.</p>
        <button class="adm-button" type="submit"><?= icon('save') ?> Salvar tempos</button>
    </form>
</section>

<section class="adm-card adm-panel" id="exportar">
    <h2>Exportar</h2>
    <div class="reorg-linha">
        <a class="adm-button adm-button--sm" href="<?= e(reorg_url('admin/historico.php?exportar=csv')) ?>"><?= icon('download') ?> Histórico completo (CSV)</a>
        <a class="adm-button adm-button--sm" href="<?= e(reorg_url('admin/historico.php?exportar=lotes')) ?>"><?= icon('download') ?> Lotes e progresso (CSV)</a>
        <a class="adm-button adm-button--sm" href="<?= e(reorg_url('admin/historico.php?exportar=producao')) ?>"><?= icon('download') ?> Produção por sessão (CSV)</a>
    </div>
    <p class="adm-field__hint">Para os exemplares de um lote, filtre o lote em Histórico. Os exemplares e códigos BDP também estão nos relatórios permanentes do sistema.</p>
</section>

<section class="adm-card adm-panel" id="encerrar">
    <h2>Encerramento</h2>
    <ol class="reorg-orientacoes">
        <li>Confira na aba <a href="<?= e(reorg_url('admin/lotes.php')) ?>">Lotes</a> do inventário se todos estão concluídos e em <a href="<?= e(reorg_url('admin/solicitacoes.php')) ?>">Solicitações e pendências</a> se nada ficou em aberto.</li>
        <li>Exporte o histórico e os lotes (acima) para guardar uma cópia.</li>
        <li>Desative as equipes (aqui ou na configuração do inventário): o botão some da tela de login e as sessões abertas são encerradas. Nada é apagado e o inventário continua funcionando.</li>
        <li>Para retirar o módulo de vez, siga o passo a passo do <code>modulos/reorganizacao/README.md</code> (os livros, exemplares e códigos BDP continuam no acervo).</li>
    </ol>
    <?php if ($periodo && !$avisos): ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="encerrar">
        <button class="adm-button adm-button--danger-text" type="submit" data-reorg-confirmar="Encerrar o período “<?= e($periodo['nome']) ?>”? O sistema avisa se ainda houver trabalho em aberto."><?= icon('pause') ?> Encerrar período</button></form>
    <?php endif; ?>
    <details class="reorg-detalhes"><summary>Períodos anteriores</summary>
        <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
            <thead><tr><th>Nome</th><th>Início</th><th>Previsão</th><th>Situação</th><th>Criado por</th><th>Encerrado em</th></tr></thead>
            <tbody><?php foreach ($r->periodos() as $x): ?>
                <tr><td><?= e($x['nome']) ?></td><td><?= e(admin_data_hora($x['inicio'], false)) ?></td><td><?= e($x['previsao_fim'] ? admin_data_hora($x['previsao_fim'], false) : '—') ?></td>
                    <td><?= (int)$x['ativo'] ? reorg_status_badge('Ativo', 'green') : reorg_status_badge('Encerrado', 'muted') ?></td><td><?= e($x['criado_por_nome']) ?></td><td><?= e($x['encerrado_em'] ? admin_data_hora($x['encerrado_em']) : '—') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div></details>
</section>

<section class="adm-card adm-panel" id="modulo">
    <h2>Módulo e integrações</h2>
    <ul>
        <li>Módulo ligado: <strong><?= $cfg['ativo'] ? 'sim' : 'não' ?></strong> (<code>REORGANIZACAO_MODULO_ATIVO</code> no <code>.env</code> ou <code>modulos/reorganizacao/config.php</code>). Desligado, o menu e o botão da tela de login somem e o resto do sistema segue normal.</li>
        <li>Navegação: dentro do Inventário (abas Equipes e participantes, Pendências e solicitações, Histórico), sem item próprio no menu.</li>
        <li>Lotes, códigos BDP e Planilha BDP: os do sistema. Cada lote é o lote do inventário; as equipes só acrescentam participantes e etapas a ele.</li>
        <li>Inventário: cada cadastro do Grupo 2 entra no inventário do lote como encontrado (mecanismo permanente da conferência).</li>
        <li>Grupo 2: o cadastro usa a conferência permanente (obra verificada e validada, CDD na mesma transação).</li>
        <li>Disponibilidade para empréstimo: regra permanente — exemplar de lote gerenciado só fica disponível depois de carimbado e com estante.</li>
        <li>Links de coleta por equipe (migração 210): <?= $coletaAtiva ? '<strong>ativos</strong>' : 'desligados e escondidos da tela de lotes (os dados antigos continuam guardados; podem ser religados em Configurações gerais → COLETA_LINKS_ATIVOS)' ?>.</li>
    </ul>
</section>
<?php reorg_admin_fim();
