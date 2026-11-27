<?php
/**
 * Integração da organização por equipes com o Inventário (pontos de extensão do sistema permanente,
 * admin/includes/inventario-integracao.php). Carregado só quando o módulo está presente e ligado.
 * Apagar a pasta do módulo remove tudo isto sem afetar o inventário, os lotes ou os exemplares.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/comum.php';

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;

/** Estilo do módulo nas páginas permanentes (uma vez por página, só quando algo do módulo aparece). */
function reorg_css_uma_vez(): string
{
    static $feito = false;
    if ($feito || !empty($GLOBALS['reorg_inventario_css'])) {
        return '';
    }
    $feito = true;
    return '<link rel="stylesheet" href="' . e(reorg_asset('reorg.css')) . '">';
}

/** Período ativo ligado a este inventário (ou null). */
function reorg_periodo_inventario(int $inventarioId): ?array
{
    return $inventarioId > 0 ? reorg()->periodoDoInventario($inventarioId) : null;
}

/** O inventário já teve organização por equipes (para manter o histórico acessível depois de desativar)? */
function reorg_inventario_teve_equipes(int $inventarioId): bool
{
    return (bool)db_query('SELECT 1 FROM reorg_periodos WHERE inventario_id = ? LIMIT 1', [$inventarioId])->fetchColumn();
}

// Abas do inventário: as das equipes só aparecem com as equipes ativas; o histórico continua acessível depois.
modulo_gancho_registrar('inventario.abas', function (array $inv): array {
    $id = (int)$inv['id'];
    $q = '?inventario=' . $id;
    $abas = [];
    if (reorg_periodo_inventario($id)) {
        $conectados = (int)db_query("SELECT COUNT(*) FROM reorg_sessoes WHERE status = 'ativa' AND periodo_id = ?", [reorg_periodo_inventario($id)['id']])->fetchColumn();
        $pend = (int)db_query("SELECT COUNT(*) FROM reorg_solicitacoes WHERE status = 'pendente' AND periodo_id = ?", [reorg_periodo_inventario($id)['id']])->fetchColumn()
            + (int)db_query("SELECT COUNT(*) FROM reorg_pendencias p JOIN reorg_lotes l ON l.id = p.lote_id JOIN cadastro_lotes c ON c.id = l.cadastro_lote_id WHERE c.inventario_id = ? AND p.status <> 'resolvida'", [$id])->fetchColumn();
        $abas['equipes'] = ['Equipes e participantes', 'users', reorg_url('admin/participantes.php' . $q), $conectados];
        $abas['pendencias'] = ['Pendências e solicitações', 'inbox', reorg_url('admin/solicitacoes.php' . $q), $pend];
        $abas['historico'] = ['Histórico', 'history', reorg_url('admin/historico.php' . $q)];
    } elseif (reorg_inventario_teve_equipes($id)) {
        $abas['historico'] = ['Histórico das equipes', 'history', reorg_url('admin/historico.php' . $q)];
    }
    return $abas;
});

// "Ativar organização por equipes" no formulário do inventário (criação e edição).
modulo_gancho_registrar('inventario.form', function (?array $inv, ?array $post): string {
    $id = (int)($inv['id'] ?? 0);
    $ativo = reorg()->periodoAtivo();
    $aqui = $ativo && $id && (int)$ativo['inventario_id'] === $id;
    $outro = $ativo && $ativo['inventario_id'] !== null && !$aqui;
    $antigo = $ativo && $ativo['inventario_id'] === null;
    $marcado = $post !== null ? !empty($post['equipes_ativas']) : $aqui;
    ob_start(); ?>
    <?= reorg_css_uma_vez() ?>
    <fieldset class="adm-field adm-field--full adm-escolha reorg-inv-equipes" id="equipes"<?= $outro ? ' disabled' : '' ?>>
        <legend class="adm-field__label">Organização por equipes <span class="adm-optional">(módulo temporário)</span></legend>
        <input type="hidden" name="equipes_form" value="1">
        <label class="adm-escolha__opcao"><input type="checkbox" name="equipes_ativas" value="1"<?= $marcado ? ' checked' : '' ?>>
            <span><strong>Ativar organização por equipes</strong> <small>mostra o botão “Equipe de organização” na tela de login. Participantes entram só com o nome (sem conta), o administrador atribui os lotes e o trabalho segue em quatro etapas: levantamento (G1), revisão e cadastro (G2), carimbação (G3) e conferência e organização (G4). Desligado, o inventário funciona normalmente.</small></span></label>
        <?php if ($outro): ?>
            <p class="adm-field__hint"><?= icon('info') ?> Já está ativa no inventário “<?= e((string)db_query('SELECT titulo FROM inventarios WHERE id = ?', [$ativo['inventario_id']])->fetchColumn()) ?>”. Só um inventário por vez usa as equipes.</p>
        <?php elseif ($antigo): ?>
            <p class="adm-field__hint"><?= icon('info') ?> Existe um período de equipes ativo ainda sem inventário (“<?= e($ativo['nome']) ?>”). Ao ativar aqui, ele e os lotes dele passam a pertencer a este inventário — nada é criado em dobro.</p>
        <?php endif; ?>
        <?php if ($aqui): ?>
            <label class="adm-check"><input type="checkbox" name="equipes_confirmar" value="1"> <span>Ao desmarcar: desativar mesmo que ainda haja trabalho das equipes em aberto (nada é apagado; o inventário continua).</span></label>
            <p class="adm-field__hint"><a class="adm-link" href="<?= e(reorg_url('admin/configuracoes.php?inventario=' . $id)) ?>"><?= icon('settings') ?> Instruções das equipes, tempos das sessões e exportações</a></p>
        <?php endif; ?>
    </fieldset>
    <?php return (string)ob_get_clean();
});

modulo_gancho_registrar('inventario.salvo', function (int $id, array $post, int $adminId, bool $novo): ?string {
    if (empty($post['equipes_form'])) {
        return null;
    }
    $r = reorg();
    $querAtivo = !empty($post['equipes_ativas']);
    $periodo = reorg_periodo_inventario($id);
    if ($querAtivo && !$periodo) {
        $x = $r->ativarEquipesNoInventario($id, [], $adminId);
        return $x['vinculado'] ? 'Organização por equipes ativada: o período que já estava ativo foi vinculado a este inventário, com os lotes dele.'
            : 'Organização por equipes ativada: o botão “Equipe de organização” já aparece na tela de login.';
    }
    if (!$querAtivo && $periodo) {
        $avisos = $r->encerrarPeriodo((int)$periodo['id'], $adminId, !empty($post['equipes_confirmar']));
        if ($avisos) {
            throw new AcervoException('A organização por equipes continua ativa porque ainda há trabalho em aberto: ' . implode(' ', $avisos)
                . ' Para desativar mesmo assim, marque a confirmação ao desmarcar a opção.');
        }
        return 'Organização por equipes desativada: o botão sumiu da tela de login e as sessões foram encerradas. O inventário, os lotes e o histórico continuam.';
    }
    return null;
});

// Visão geral do inventário: só os números TEMPORÁRIOS das equipes (os oficiais vêm dos lotes permanentes).
modulo_gancho_registrar('inventario.visao', function (array $inv): string {
    $id = (int)$inv['id'];
    $periodo = reorg_periodo_inventario($id);
    $r = reorg();
    if (!$periodo && !$r->lotesDoInventario($id)) {
        return '';
    }
    $t = $r->totaisInventario($id);
    $conectados = $periodo ? count(array_filter($r->participantesAtivos(), fn ($p) => $p['estado'] !== 'interrompida')) : 0;
    ob_start(); ?>
    <?= reorg_css_uma_vez() ?>
    <section class="adm-card adm-panel reorg-inv-visao" aria-labelledby="titulo-equipes-visao">
        <div class="adm-panel__header"><div><h2 id="titulo-equipes-visao"><?= icon('users') ?> Organização por equipes <?= $periodo ? reorg_status_badge('Ativa', 'green') : reorg_status_badge('Desativada', 'muted') ?></h2>
            <p>Controle temporário das equipes. Registros do Grupo 1 só viram exemplares oficiais (com código BDP) depois da revisão e do cadastro pelo Grupo 2.</p></div>
            <?php if ($periodo): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(reorg_url('admin/participantes.php?inventario=' . $id)) ?>"><?= icon('arrow-right') ?> Equipes e participantes</a><?php endif; ?></div>
        <dl class="adm-inv-funil">
            <?php if ($periodo): ?><div><dt>Participantes conectados</dt><dd><?= $conectados ?></dd></div><?php endif; ?>
            <div><dt>Em levantamento (G1)</dt><dd><?= $t['em_levantamento'] ?></dd></div>
            <div><dt>Aguardando revisão ou cadastro (G2)</dt><dd><?= $t['aguardando_revisao'] + $t['aprovados_sem_cadastro'] ?></dd></div>
            <div><dt>Conferidos pelo G4</dt><dd><?= $t['conferidos'] ?></dd></div>
            <div><dt>Pendências abertas</dt><dd class="<?= $t['pendencias_abertas'] ? 'is-alerta' : '' ?>"><?= $t['pendencias_abertas'] ?></dd></div>
            <div><dt>Solicitações</dt><dd class="<?= $t['solicitacoes'] ? 'is-alerta' : '' ?>"><?= $t['solicitacoes'] ?></dd></div>
        </dl>
    </section>
    <?php return (string)ob_get_clean();
});

modulo_gancho_registrar('inventario.avisos_conclusao', fn (array $inv): array => reorg()->avisosInventario((int)$inv['id']));

// Concluir ou cancelar o inventário encerra as equipes dele (as sessões terminam; nada é apagado).
modulo_gancho_registrar('inventario.concluido', function (int $id, int $adminId): ?string {
    $periodo = reorg_periodo_inventario($id);
    if (!$periodo) {
        return null;
    }
    reorg()->encerrarPeriodo((int)$periodo['id'], $adminId, true);
    return 'A organização por equipes deste inventário foi encerrada junto (o histórico continua disponível).';
});

// Conferência: registros do Grupo 1 esperando a revisão, com o lote de origem.
modulo_gancho_registrar('conferencia.painel', function (array $inv): string {
    $id = (int)$inv['id'];
    $regs = db_query("SELECT r.id, r.numero, r.titulo, r.autores, r.quantidade, r.status, l.id AS lote_id, l.nome AS lote_nome, c.numero AS lote_numero
                      FROM reorg_registros r JOIN reorg_lotes l ON l.id = r.lote_id JOIN cadastro_lotes c ON c.id = l.cadastro_lote_id
                      WHERE c.inventario_id = ? AND r.status IN ('aguardando_revisao', 'aprovado') ORDER BY r.status = 'aprovado' DESC, r.id LIMIT 200", [$id])->fetchAll();
    if (!$regs) {
        return '';
    }
    $porLote = [];
    foreach ($regs as $g) {
        $porLote[$g['lote_id']] ??= ['nome' => 'Lote ' . str_pad((string)$g['lote_numero'], 3, '0', STR_PAD_LEFT) . ' · ' . $g['lote_nome'], 'n' => 0];
        $porLote[$g['lote_id']]['n'] += (int)$g['quantidade'];
    }
    ob_start(); ?>
    <?= reorg_css_uma_vez() ?>
    <details class="adm-card adm-panel reorg-conf-g1" open>
        <summary><?= icon('users') ?> <strong>Levantamento das equipes aguardando revisão</strong> — <?= array_sum(array_column($porLote, 'n')) ?> exemplar(es) em <?= count($porLote) ?> lote(s). Ainda não são exemplares oficiais: revise e cadastre para gerar os códigos BDP.</summary>
        <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
            <thead><tr><th>Lote de origem</th><th>Registro</th><th>Título / autor</th><th class="reorg-num">Qtd.</th><th>Situação</th></tr></thead>
            <tbody><?php foreach (array_slice($regs, 0, 12) as $g): ?>
                <tr><td><a class="adm-link" href="<?= e(reorg_url('admin/lote.php?id=' . $g['lote_id'] . '&aba=g2')) ?>"><?= e($porLote[$g['lote_id']]['nome']) ?></a></td>
                    <td><a class="adm-link" href="<?= e(reorg_url('admin/registro.php?id=' . $g['id'])) ?>">Nº <?= (int)$g['numero'] ?></a></td>
                    <td><?= e($g['titulo'] ?: '(sem título)') ?><br><small><?= e($g['autores'] ?: '—') ?></small></td>
                    <td class="reorg-num"><?= (int)$g['quantidade'] ?></td>
                    <td><?= $g['status'] === 'aprovado' ? reorg_status_badge('Aprovado, falta cadastrar', 'blue') : reorg_status_badge('Aguardando revisão', 'amber') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if (count($regs) > 12): ?><p class="adm-field__hint">Mostrando 12 de <?= count($regs) ?>. Abra o lote (aba Grupo 2) para ver todos.</p><?php endif; ?>
    </details>
    <?php return (string)ob_get_clean();
});

// Aviso no topo do inventário: pessoas das equipes que entraram e ainda esperam um lote (janela para escolher).
modulo_gancho_registrar('inventario.topo', function (array $inv): string {
    static $feito = false;
    $periodo = reorg_periodo_inventario((int)$inv['id']);
    if ($feito || !$periodo) {
        return '';
    }
    $feito = true;
    require_once __DIR__ . '/quadro.php';
    $r = reorg();
    $esperando = array_values(array_filter($r->participantesAtivos(), fn ($s) => $s['equipe'] !== null && !$s['lotes'] && $s['estado'] !== 'interrompida'));
    if (!$esperando) {
        return '';
    }
    $doInv = $r->lotesDoInventario((int)$inv['id']);
    $lotes = array_values(array_filter($r->lotes(), fn ($l) => $l['status'] !== 'concluido' && in_array((int)$l['id'], $doInv, true)));
    $n = count($esperando);
    $voltar = reorg_voltar_seguro((string)($_SERVER['REQUEST_URI'] ?? ''), admin_url('inventario-detalhes') . '?id=' . (int)$inv['id']);
    ob_start(); ?>
    <?= reorg_css_uma_vez() ?>
    <div class="reorg-aguardando adm-no-print" role="status">
        <span class="reorg-aguardando__icone"><?= icon('hourglass') ?></span>
        <div><strong><?= $n ?> pessoa<?= $n === 1 ? '' : 's' ?> aguardando lote</strong>
            <span><?= e(implode(', ', array_map(fn ($s) => $s['nome'] . ' (' . REORG_QUADRO_COLUNAS[$s['equipe']][1] . ')', array_slice($esperando, 0, 4)))) ?><?= $n > 4 ? ' e mais ' . ($n - 4) : '' ?></span></div>
        <button type="button" class="adm-button adm-button--primary adm-button--sm" data-reorg-dialogo="reorg-janela-aguardando"><?= icon('user-plus') ?> Escolher lote</button>
    </div>
    <?= reorg_janela_inicio('reorg-janela-aguardando', 'Pessoas aguardando lote', 'Escolha o lote de cada pessoa. Quem ficar em “Deixar esperando” continua na tela de espera.') ?>
        <form method="post" action="<?= e(reorg_url('admin/participantes.php')) ?>" class="adm-modal__body"><?= csrf_campo() ?>
            <input type="hidden" name="acao" value="atribuir_varios"><input type="hidden" name="voltar" value="<?= e($voltar) ?>">
            <?php if (!$lotes): ?>
                <div class="reorg-janela__vazio"><?= icon('layers') ?><strong>Nenhum lote aberto neste inventário.</strong>
                    <span>Crie um lote primeiro; no formulário já dá para marcar estas pessoas.</span>
                    <a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url('lote-novo') . '?inventario=' . (int)$inv['id']) ?>"><?= icon('plus') ?> Novo lote</a></div>
            <?php else: ?>
            <ul class="reorg-espera-lista">
                <?php foreach ($esperando as $s): [$sigla, $etapa, $ic] = REORG_QUADRO_COLUNAS[$s['equipe']]; ?>
                    <li><span class="reorg-espera-lista__eq" title="<?= e($etapa) ?>"><?= icon($ic) ?></span>
                        <div class="reorg-espera-lista__id"><strong><?= e($s['nome']) ?></strong><small><span class="adm-mono"><?= e(Reorganizacao::rotuloSessao($s['id'])) ?></span> · <?= e($sigla . ' ' . $etapa) ?><?= $s['mesmo_nome'] ? ' · nome repetido' : '' ?></small></div>
                        <select class="adm-select adm-select--sm" name="lote[<?= (int)$s['id'] ?>]" aria-label="Lote para <?= e($s['nome']) ?>">
                            <option value="">Deixar esperando</option><?= reorg_opcoes($lotes, null) ?></select></li>
                <?php endforeach; ?>
            </ul>
            <div class="adm-modal__footer reorg-janela__rodape"><button type="button" class="adm-button adm-button--ghost" data-reorg-fechar>Cancelar</button><button class="adm-button adm-button--primary" type="submit"><?= icon('check') ?> Atribuir</button></div>
            <?php endif; ?>
        </form>
    <?= reorg_janela_fim() ?>
    <?= reorg_quadro_js() ?>
    <?php return (string)ob_get_clean();
});

// Formulário único de lote: quem trabalha em cada etapa (mesmo visual do quadro do lote), quando as equipes
// estão ativas no inventário escolhido.
modulo_gancho_registrar('lote.form', function (int $inventarioId, ?array $post): string {
    $periodo = reorg()->periodoAtivo();
    if (!$periodo || !$periodo['inventario_id']) {
        return '';
    }
    require_once __DIR__ . '/quadro.php';
    $invEquipes = (int)$periodo['inventario_id'];
    $disponiveis = array_values(array_filter(reorg()->participantesAtivos(), fn ($p) => $p['equipe'] !== null && $p['estado'] !== 'interrompida'));
    $sel = fn (string $eq) => array_map('intval', (array)(($post ?? [])[$eq] ?? []));
    ob_start(); ?>
    <?= reorg_css_uma_vez() ?>
    <fieldset class="adm-field adm-field--full reorg-lote-equipes" data-equipes-inventario="<?= $invEquipes ?>"<?= $inventarioId !== $invEquipes ? ' hidden disabled' : '' ?>>
        <legend class="adm-field__label"><?= icon('users') ?> Participantes das equipes <span class="adm-optional">(quem trabalha em cada etapa; dá para mudar depois no quadro do lote)</span></legend>
        <input type="hidden" name="equipes_lote" value="1">
        <div class="reorg-quadro reorg-quadro--form">
            <?php foreach (REORG_QUADRO_COLUNAS as $eq => [$sigla, $etapa]):
                $lista = array_filter($disponiveis, fn ($p) => $p['equipe'] === $eq); ?>
            <div class="reorg-quadro__col">
                <?= reorg_quadro_cabecalho($eq, $eq === 'grupo2' ? null : count($lista)) ?>
                <span class="adm-visually-hidden"><?= e($sigla . ' — ' . ['grupo1' => 'Levantamento', 'grupo2' => 'Revisão administrativa', 'grupo3' => 'Carimbação', 'grupo4' => 'Conferência e organização'][$eq]) ?></span>
                <?php if ($eq === 'grupo2'): ?>
                    <p class="reorg-quadro__nota"><?= icon('info') ?> O responsável e os usuários do painel marcados acima fazem a revisão e o cadastro.</p>
                <?php elseif (!$lista): ?>
                    <p class="reorg-quadro__vazio">Ninguém desta equipe conectado agora. Dá para adicionar depois, no quadro do lote.</p>
                <?php else: ?>
                    <ul class="reorg-escolhas">
                    <?php foreach ($lista as $p): ?>
                        <li><label class="reorg-escolha"><input type="checkbox" name="<?= $eq ?>[]" value="<?= (int)$p['id'] ?>"<?= in_array((int)$p['id'], $sel($eq), true) ? ' checked' : '' ?>>
                            <span><strong><?= e($p['nome']) ?></strong> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['id'])) ?></small>
                                <small class="reorg-escolha__estado"><?= reorg_quadro_situacao($p) ?></small></span></label></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <script>
    (function () {
        var sel = document.querySelector('[data-lote-inventario]'), fs = document.querySelector('[data-equipes-inventario]');
        if (!sel || !fs) { return; }
        var ver = function () { var on = sel.value === fs.getAttribute('data-equipes-inventario'); fs.hidden = !on; fs.disabled = !on; };
        sel.addEventListener('change', ver); ver();
    })();
    </script>
    <?php return (string)ob_get_clean();
});

// Mesmo lote: com as equipes ativas no inventário do lote, ele ganha a área das equipes (mesma transação).
modulo_gancho_registrar('lote.criado', function (int $loteId, array $post, int $adminId): ?int {
    if (empty($post['equipes_lote'])) {
        return null;
    }
    $inv = (int)db_query('SELECT inventario_id FROM cadastro_lotes WHERE id = ?', [$loteId])->fetchColumn();
    if (!reorg_periodo_inventario($inv)) {
        return null;
    }
    $dados = ['responsavel_id' => (int)($post['responsavel_id'] ?? 0)];
    foreach (Reorganizacao::EQUIPES_PARTICIPANTES as $eq) {
        $dados[$eq] = array_map('intval', (array)($post[$eq] ?? []));
    }
    return reorg()->ativarEquipesNoLote($loteId, $dados, $adminId);
});

modulo_gancho_registrar('lote.url', function (array $lote): ?string {
    $id = db_query('SELECT id FROM reorg_lotes WHERE cadastro_lote_id = ?', [(int)$lote['id']])->fetchColumn();
    return $id ? reorg_url('admin/lote.php?id=' . (int)$id) : null;
});

modulo_gancho_registrar('lote.alterado', function (int $loteId, int $adminId): void {
    reorg()->sincronizarDoPermanente($loteId);
});

modulo_gancho_registrar('lote.linha', function (array $lote): string {
    $rl = reorg()->loteDoCadastro((int)$lote['id']);
    if (!$rl) {
        return '';
    }
    $c = reorg()->contadores((int)$rl['id']);
    return '<small class="adm-block-muted reorg-lote-linha">' . icon('users') . ' Equipes · ' . e(['em_andamento' => 'em andamento', 'pausado' => 'pausado', 'concluido' => 'concluído'][$rl['status']] ?? $rl['status'])
        . ' · G1 ' . $c['registrados'] . '/' . $c['prevista'] . ' · G4 ' . $c['organizados'] . '</small>';
});

// Página permanente do lote: abas das equipes (o relatório e a Planilha BDP continuam aqui).
modulo_gancho_registrar('lote.painel', function (array $lote): string {
    $rl = reorg()->loteDoCadastro((int)$lote['id']);
    if (!$rl) {
        return '';
    }
    require_once __DIR__ . '/lote-abas.php';
    return reorg_css_uma_vez() . reorg_abas_lote_html($rl, 'relatorio');
});
