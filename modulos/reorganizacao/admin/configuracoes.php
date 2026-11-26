<?php
/** Configurações do módulo (somente leitura: valores do config.php/.env) e situação das integrações. */
require __DIR__ . '/../includes/admin.php';

reorg_admin_exigir_modulo();
reorg_admin_usuario();
$cfg = reorg_config();
$coletaAtiva = config_bool('COLETA_LINKS_ATIVOS', false);
reorg_admin_inicio('configuracoes', 'Configurações do módulo');
?>
<section class="adm-page-head"><div><h1>Configurações do módulo</h1><p>Os valores ficam em <code>modulos/reorganizacao/config.php</code> (alguns podem ser trocados no <code>.env</code>). A documentação completa está em <code>modulos/reorganizacao/README.md</code>.</p></div></section>
<section class="adm-card adm-panel">
    <dl class="adm-meta adm-meta--two">
        <div><dt>Módulo ligado</dt><dd><?= $cfg['ativo'] ? 'Sim' : 'Não' ?> <small>(REORGANIZACAO_MODULO_ATIVO)</small></dd></div>
        <div><dt>Comunicação periódica do navegador</dt><dd>a cada <?= (int)$cfg['intervalo_sinal'] ?> s <small>(REORGANIZACAO_INTERVALO_SINAL)</small></dd></div>
        <div><dt>Tolerância antes de desconectar</dt><dd><?= (int)$cfg['tolerancia_desconexao'] ?> s <small>(REORGANIZACAO_TOLERANCIA)</small></dd></div>
        <div><dt>Reserva de edição de um registro/exemplar</dt><dd><?= (int)$cfg['reserva_edicao'] ?> s (renovada enquanto a pessoa está na tela)</dd></div>
        <div><dt>Máximo de exemplares por registro</dt><dd><?= (int)$cfg['max_exemplares_registro'] ?></dd></div>
        <div><dt>Máximo de exemplares por lote</dt><dd><?= (int)$cfg['max_exemplares_lote'] ?></dd></div>
        <div><dt>Entradas por rede por minuto</dt><dd><?= (int)$cfg['limite_entradas_minuto'] ?></dd></div>
    </dl>
</section>
<section class="adm-card adm-panel">
    <h2>Integrações com o sistema permanente</h2>
    <ul>
        <li>Botão discreto na tela de login (só com período ativo): <code>admin/login.php</code> inclui <code>modulos/reorganizacao/includes/login-botao.php</code> se o arquivo existir.</li>
        <li>Item “Reorganização do Acervo” no menu do painel: some quando o módulo está desligado ou removido.</li>
        <li>Lotes, códigos BDP e Planilha BDP: os do sistema (lote gerenciado permanente para cada lote da reorganização).</li>
        <li>Disponibilidade para empréstimo: regra permanente — exemplar de lote gerenciado só fica disponível depois de carimbado e com estante.</li>
        <li>Links de coleta por equipe (migração 210): <?= $coletaAtiva ? '<strong>ativos</strong>' : 'desativados pela migração 220 (nada foi apagado; podem ser religados em Configurações → COLETA_LINKS_ATIVOS)' ?>.</li>
    </ul>
</section>
<?php reorg_admin_fim();
