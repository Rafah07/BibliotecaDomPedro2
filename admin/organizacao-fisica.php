<?php
/**
 * Organização Física da biblioteca (migração 140).
 *
 * Configura a estrutura REAL da biblioteca, construída do zero pela equipe:
 *   * setores ou salas (opcionais), estantes e prateleiras, com nomes, identificações, categoria,
 *     ordem de exibição e situação (ativa/desativada);
 *   * "+ Adicionar estante" (já cria as prateleiras) e "Criar várias estantes" (identificações
 *     sequenciais, sem repetir no mesmo setor);
 *   * prateleiras: adicionar, renomear, mudar a ordem, desativar, ver os exemplares, transferir
 *     os exemplares e excluir (só vazia e nunca usada em inventário);
 *   * responsável por estante (administrador existente e/ou nome de equipe, só organização);
 *   * "Mover exemplar" pelo código BDP, com histórico; etiquetas das estantes (A4).
 *
 * Os números vêm do banco (exemplares não baixados). A localização antiga das planilhas nunca é
 * usada. Nada exige "finalizar" uma estante. Ver: OrganizacaoFisicaService / OrganizacaoFisicaRepository.
 *
 * Endereços: ?estante=ID (prateleiras), &prateleira=ID (exemplares, &eq= &pagina=), &painel=estante|lote|mover|setor|
 * editar-estante|responsavel|prateleiras, &codigo= (Mover exemplar), ?q=&setor=&situacao= (estantes), &mq=&mpagina= (movimentações).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\OrganizacaoFisicaService;

$usuario = fisico_iniciar();
$base = admin_url('organizacao-fisica');
$podeGerenciar = auth_pode('localizacoes.gerenciar');
$podeMover = auth_pode('exemplares.gerenciar');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    $voltar = admin_param($_POST, 'voltar');
    $voltar = str_starts_with($voltar, $base) ? $voltar : $base;
    $estanteId = (int)admin_param($_POST, 'estante_id');
    $org = organizacao_servico();
    $uid = (int)$usuario['id'];
    $painelErro = null;
    try {
        if ($acao === 'mover_exemplar') {
            fisico_exigir('exemplares.gerenciar');
        } else {
            fisico_exigir('localizacoes.gerenciar');
        }
        switch ($acao) {
            case 'estante_criar':
                $painelErro = 'estante';
                $novo = $org->criarEstante($_POST, $uid);
                $n = (int)admin_param($_POST, 'prateleiras');
                admin_flash('sucesso', 'Estante cadastrada.', $n ? $n . ' prateleira(s) criada(s). Ela já pode ser escolhida no Inventário.' : 'Adicione as prateleiras quando quiser.');
                admin_redirecionar($base . '?estante=' . $novo);
            case 'estantes_lote':
                $painelErro = 'lote';
                $criadas = $org->criarEstantesEmLote($_POST, $uid);
                admin_flash('sucesso', count($criadas) . ' estante(s) criada(s).', 'Identificações: ' . implode(', ', array_column($criadas, 'identificacao')) . '. Você pode editar o nome, a categoria e as prateleiras de cada uma.');
                admin_redirecionar($base);
            case 'estante_editar':
                $painelErro = 'editar-estante';
                $org->editarEstante($estanteId, $_POST, $uid);
                admin_flash('sucesso', 'Estante atualizada.', 'Os exemplares continuam nas mesmas prateleiras.');
                break;
            case 'estante_ativa':
                $ativa = admin_param($_POST, 'ativa') === '1';
                $org->definirEstanteAtiva($estanteId, $ativa, $uid);
                admin_flash('sucesso', $ativa ? 'Estante reativada.' : 'Estante desativada.', $ativa ? 'Ela volta a aparecer na escolha de localização.' : 'Os exemplares que estão nela continuam lá; ela só deixa de aparecer para novos registros.');
                break;
            case 'estante_excluir':
                $org->excluirEstante($estanteId, $uid);
                admin_flash('sucesso', 'Estante excluída.', 'Ela não tinha exemplares nem histórico de inventário.');
                admin_redirecionar($base);
            case 'estante_responsavel':
                $painelErro = 'responsavel';
                $org->definirResponsavel($estanteId, (int)admin_param($_POST, 'responsavel_id') ?: null, admin_param($_POST, 'equipe'), $uid);
                admin_flash('sucesso', 'Responsável atualizado.', 'É só uma ferramenta de organização: qualquer pessoa autorizada continua podendo registrar livros nesta estante.');
                break;
            case 'prateleiras_adicionar':
                $painelErro = 'prateleiras';
                $ids = $org->adicionarPrateleiras($estanteId, (int)admin_param($_POST, 'quantidade', '1'), admin_param($_POST, 'identificacao'), $uid);
                admin_flash('sucesso', count($ids) . ' prateleira(s) adicionada(s).');
                break;
            case 'prateleira_editar':
                $org->editarPrateleira((int)admin_param($_POST, 'prateleira_id'), admin_param($_POST, 'identificacao'), admin_param($_POST, 'descricao'), $uid);
                admin_flash('sucesso', 'Prateleira atualizada.', 'Os exemplares dela continuam no mesmo lugar e passam a mostrar o novo nome.');
                break;
            case 'prateleira_ordem':
                $org->moverPrateleira((int)admin_param($_POST, 'prateleira_id'), admin_param($_POST, 'direcao') === 'subir' ? -1 : 1, $uid);
                break;
            case 'prateleira_ativa':
                $ativa = admin_param($_POST, 'ativa') === '1';
                $org->definirPrateleiraAtiva((int)admin_param($_POST, 'prateleira_id'), $ativa, $uid);
                admin_flash('sucesso', $ativa ? 'Prateleira reativada.' : 'Prateleira desativada.', $ativa ? '' : 'Os exemplares dela continuam lá; ela só deixa de aparecer para novos registros.');
                break;
            case 'prateleira_excluir':
                $org->excluirPrateleira((int)admin_param($_POST, 'prateleira_id'), $uid);
                admin_flash('sucesso', 'Prateleira excluída.');
                break;
            case 'prateleira_transferir':
                $op = operacoes_servico()->realizar('transferencia_prateleira', 'Transferência da prateleira ' . fisico_local_id((int)admin_param($_POST, 'prateleira_id')) . ' para ' . fisico_local_id((int)admin_param($_POST, 'destino_id')), $uid,
                    fn () => $org->transferirExemplares((int)admin_param($_POST, 'prateleira_id'), (int)admin_param($_POST, 'destino_id'), $uid, admin_param($_POST, 'motivo')));
                admin_flash('sucesso', fisico_numero((int)$op['resultado']) . ' exemplar(es) transferido(s).', 'Operação nº ' . $op['id'] . '. Cada movimentação ficou no histórico do exemplar. Errou? Use “Desfazer” em Ações recentes.');
                break;
            case 'setor_salvar':
                $painelErro = 'setor';
                $sid = (int)admin_param($_POST, 'setor_id') ?: null;
                $org->salvarSetor($_POST, $uid, $sid);
                admin_flash('sucesso', $sid ? 'Setor atualizado.' : 'Setor cadastrado.', $sid ? '' : 'Agora ele pode ser escolhido ao criar ou editar uma estante.');
                break;
            case 'setor_ativo':
                $ativo = admin_param($_POST, 'ativo') === '1';
                $org->definirSetorAtivo((int)admin_param($_POST, 'setor_id'), $ativo, $uid);
                admin_flash('sucesso', $ativo ? 'Setor reativado.' : 'Setor desativado.');
                break;
            case 'setor_excluir':
                $org->excluirSetor((int)admin_param($_POST, 'setor_id'), $uid);
                admin_flash('sucesso', 'Setor excluído.');
                break;
            case 'mover_exemplar':
                $painelErro = 'mover';
                $r = operacoes_servico()->realizar('mover', 'Exemplar ' . admin_param($_POST, 'codigo') . ' movido na Organização Física', $uid,
                    fn () => $org->moverExemplar(admin_param($_POST, 'codigo'), (int)admin_param($_POST, 'destino_id'), $uid, admin_param($_POST, 'motivo')))['resultado'];
                $destino = organizacao_repo()->prateleira($r['para']);
                admin_flash('sucesso', 'Exemplar ' . $r['codigo'] . ' movido.', 'Nova localização: ' . ($destino ? fisico_rotulo_estante($destino + ['nome' => $destino['estante_nome']], false) . ' · ' . fisico_rotulo_prateleira($destino) : '') . '. O histórico do exemplar registrou a localização anterior e a nova.');
                break;
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi gravado.', $e->getMessage());
        if ($painelErro !== null) {
            $_SESSION['organizacao_form'] = ['painel' => $painelErro, 'valores' => array_diff_key($_POST, ['csrf' => 1])];
            $partes = explode('?', $voltar, 2);
            parse_str($partes[1] ?? '', $qs);
            $voltar = admin_url_filtros($partes[0], $qs, ['painel' => $painelErro]);
        }
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi gravado.', fisico_erro_tecnico($e, 'organização física'));
    }
    admin_redirecionar($voltar);
}

// ---------------------------------------------------------------------
$formAnterior = $_SESSION['organizacao_form'] ?? null;
unset($_SESSION['organizacao_form']);
$painel = admin_param($_GET, 'painel');
$valores = fn (string $p) => $formAnterior !== null && $formAnterior['painel'] === $p ? $formAnterior['valores'] : [];
$filtros = [
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
    'setor' => preg_match('/^(sem|\d+)$/', admin_param($_GET, 'setor')) ? admin_param($_GET, 'setor') : '',
    'situacao' => in_array(admin_param($_GET, 'situacao'), ['inativas', 'todas'], true) ? admin_param($_GET, 'situacao') : '',
    'responsavel' => (string)((int)admin_param($_GET, 'responsavel') ?: ''),
];
$estanteId = (int)admin_param($_GET, 'estante');
$prateleiraId = (int)admin_param($_GET, 'prateleira');
try {
    $repo = organizacao_repo();
    $resumo = $repo->resumo();
    $setores = $repo->setores();
    $estante = $estanteId ? $repo->estante($estanteId) : null;
    $prateleira = $prateleiraId ? $repo->prateleira($prateleiraId) : null;
    if ($prateleira && $prateleira['estante_id'] !== null && !$estante) {
        $estante = $repo->estante((int)$prateleira['estante_id']);
    }
    $exemplaresPrat = $prateleira ? $repo->exemplaresDaLocalizacao($prateleiraId, mb_substr(admin_param($_GET, 'eq'), 0, 100), max(1, (int)admin_param($_GET, 'pagina', '1')), 25) : null;
    $lista = $estante ? null : $repo->estantes($filtros, max(1, (int)admin_param($_GET, 'pagina', '1')), 50);
    $andamento = $estante ? [] : $repo->andamentoEquipes();
    $organizados = $estante ? [] : $repo->organizadosPorPessoa(7);
    $mq = mb_substr(admin_param($_GET, 'mq'), 0, 100);
    $movs = $estante ? null : $repo->movimentacoes($mq, max(1, (int)admin_param($_GET, 'mpagina', '1')), 15);
    $pessoas = $podeGerenciar ? $repo->pessoasAtivas() : [];
    $opcoesLocal = $repo->opcoesLocal();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a Organização Física', fisico_erro_tecnico($e, 'organização física') . ' Se acabou de atualizar o sistema, rode a migração 140 (php database/migrar.php).');
    exit;
}
if ($estanteId && !$estante) {
    http_response_code(404);
    admin_pagina_erro('Estante não encontrada', 'Ela pode ter sido excluída. Volte para a Organização Física.', 'map-pin-off');
    exit;
}
$aqui = $estante ? $base . '?estante=' . (int)$estante['id'] . ($prateleira ? '&prateleira=' . (int)$prateleira['id'] : '') : admin_url_filtros($base, $filtros);
$codigoMover = mb_substr(admin_param($_GET, 'codigo'), 0, 30);
$abrir = fn (string $p) => $painel === $p ? ' open data-painel-auto' : '';
$campoSetor = function (string $nome, $atual) use ($setores): string {
    $h = '<select class="adm-select" name="' . e($nome) . '"><option value="">Sem setor</option>';
    foreach ($setores as $s) {
        $h .= '<option value="' . (int)$s['id'] . '"' . ((string)$atual === (string)$s['id'] ? ' selected' : '') . '>' . e($s['nome']) . (!(int)$s['ativo'] ? ' (desativado)' : '') . '</option>';
    }
    return $h . '</select>';
};

admin_page_start('organizacao-fisica', 'Organização Física', 'Organização Física', 'Estantes, prateleiras e localização dos exemplares', FISICO_CSS);
?>

<?= acervo_breadcrumb(array_values(array_filter([['Administração', admin_url('dashboard')], ['Organização Física', $estante ? $base : null], $estante ? [fisico_rotulo_estante($estante, false), $prateleira ? $base . '?estante=' . (int)$estante['id'] : null] : null, $prateleira ? [fisico_rotulo_prateleira($prateleira)] : null]))) ?>

<section class="adm-page-head">
    <div>
        <h1>Organização Física</h1>
        <p>Monte aqui a estrutura real da biblioteca: setores (se houver), estantes e prateleiras. A equipe escolhe a nova localização de cada exemplar no Inventário. Não é preciso “finalizar” estantes e as localizações antigas das planilhas não são usadas.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeGerenciar): ?>
            <a class="adm-button adm-button--primary" href="<?= e($base . '?painel=estante') ?>" data-painel-abrir="painel-estante"><?= icon('plus') ?> Adicionar estante</a>
            <a class="adm-button adm-button--ghost" href="<?= e($base . '?painel=lote') ?>" data-painel-abrir="painel-lote"><?= icon('layers') ?> Criar várias estantes</a>
        <?php endif; ?>
        <?php if ($podeMover): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($aqui, [], ['painel' => 'mover'])) ?>" data-painel-abrir="painel-mover"><?= icon('arrow-right-left') ?> Mover exemplar</a><?php endif; ?>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('organizacao-mapa')) ?>"><?= icon('map') ?> Mapa das estantes</a>
        <?php if ($podeMover): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('movimentacao-lote')) ?>"><?= icon('move') ?> Movimentação em lote</a><?php endif; ?>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('organizacao-etiquetas') . ($estante ? '?estante=' . (int)$estante['id'] : '')) ?>"><?= icon('printer') ?> Etiquetas das estantes</a>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats" aria-label="Resumo da organização física">
    <?= fisico_stat('Estantes cadastradas', $resumo['estantes'], fisico_numero($resumo['estantes_ativas']) . ' ativa(s)', 'columns-3', 'blue') ?>
    <?= fisico_stat('Prateleiras', $resumo['prateleiras'], fisico_numero($resumo['prateleiras_ativas']) . ' ativa(s)', 'layers', 'teal') ?>
    <?= fisico_stat('Exemplares com localização definida', $resumo['com_localizacao'], $resumo['pct_alocados'] . '% dos ' . fisico_numero($resumo['exemplares']) . ' exemplares', 'map-pin', 'green') ?>
    <?= fisico_stat('Exemplares sem localização', $resumo['sem_alocacao'], $resumo['em_avulsa'] ? fisico_numero($resumo['em_avulsa']) . ' ainda numa localização anterior' : 'Ainda não colocados numa prateleira', 'map-pin-off', 'amber') ?>
</section>
<div class="adm-progress adm-progress--lg adm-org__progresso" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $resumo['pct_alocados'] ?>" aria-label="Exemplares com localização definida"><div class="adm-progress__bar adm-progress__bar--ok" style="width: <?= $resumo['pct_alocados'] ?>%"></div></div>
<p class="adm-field__hint"><?= icon('info') ?> O andamento é medido pelos exemplares registrados e colocados nas prateleiras. Livros podem mudar de estante a qualquer momento (use <strong>Mover exemplar</strong>).</p>
<?php if (!$estante): ?>
    <h2 class="adm-org__subtitulo">Conferência física × organização definitiva</h2>
    <?= fisico_indicadores_preparo_html(preparo_repo()->indicadores(), admin_url('planilha-bdp')) ?>
<?php endif; ?>

<?php if ($estante): ?>
    <?php $inativa = !(int)$estante['ativa']; ?>
    <section class="adm-card adm-panel adm-org-estante" aria-labelledby="titulo-estante">
        <div class="adm-panel__header">
            <div>
                <span class="adm-org-estante__id"><?= e($estante['identificacao']) ?></span>
                <h2 id="titulo-estante"><?= e(fisico_rotulo_estante($estante)) ?> <?= $inativa ? '<span class="adm-status adm-tone--muted">Desativada</span>' : '' ?></h2>
                <p><?= count($estante['prateleiras']) ?> prateleira(s) · <strong><?= fisico_numero($estante['exemplares']) ?></strong> exemplar(es) nesta estante
                    <?= $estante['descricao'] ? ' · ' . e($estante['descricao']) : '' ?></p>
                <p class="adm-table__muted"><?= icon('users') ?> Responsável: <?= $estante['responsavel_nome'] || $estante['equipe'] ? e(implode(' · ', array_filter([$estante['responsavel_nome'], $estante['equipe']]))) : 'ninguém definido' ?>
                    <?= $estante['responsavel_definido_em'] ? ' (desde ' . e(admin_data_hora($estante['responsavel_definido_em'], false)) . ')' : '' ?></p>
            </div>
            <div class="adm-actions-inline">
                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('arrow-left') ?> Todas as estantes</a>
                <?php if ($podeGerenciar): ?>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($aqui . '&painel=editar-estante') ?>" data-painel-abrir="painel-editar-estante"><?= icon('pencil') ?> Editar estante</a>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($aqui . '&painel=responsavel') ?>" data-painel-abrir="painel-responsavel"><?= icon('user-check') ?> Definir responsável</a>
                    <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($aqui . '&painel=prateleiras') ?>" data-painel-abrir="painel-prateleiras"><?= icon('plus') ?> Adicionar prateleiras</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards">
                <thead><tr><th scope="col">Prateleira</th><th scope="col">Exemplares</th><th scope="col">Situação</th><th scope="col" class="adm-actions-col">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($estante['prateleiras'] as $i => $p): $pid = (int)$p['id']; $vazia = (int)$p['exemplares'] === 0; ?>
                    <tr class="<?= $prateleira && (int)$prateleira['id'] === $pid ? 'is-selecionada' : '' ?>">
                        <td class="adm-td-title"><a class="adm-link" href="<?= e($base . '?estante=' . (int)$estante['id'] . '&prateleira=' . $pid) ?>#exemplares-prateleira"><?= e(fisico_rotulo_estante($estante, false)) ?> — <?= e(fisico_rotulo_prateleira($p)) ?></a></td>
                        <td data-label="Exemplares"><strong><?= fisico_numero((int)$p['exemplares']) ?></strong> exemplar(es)</td>
                        <td data-label="Situação"><?= (int)$p['ativa'] ? '<span class="adm-status adm-tone--green">Ativa</span>' : '<span class="adm-status adm-tone--muted">Desativada</span>' ?></td>
                        <td class="adm-actions-col">
                            <div class="adm-actions-inline">
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?estante=' . (int)$estante['id'] . '&prateleira=' . $pid) ?>#exemplares-prateleira"><?= icon('eye') ?> Ver exemplares</a>
                                <?php if ($podeGerenciar): ?>
                                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form">
                                        <?= csrf_campo() ?><input type="hidden" name="acao" value="prateleira_ordem"><input type="hidden" name="prateleira_id" value="<?= $pid ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit" name="direcao" value="subir"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Subir <?= e(fisico_rotulo_prateleira($p)) ?>" title="Subir"><?= icon('arrow-up') ?></button>
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit" name="direcao" value="descer"<?= $i === count($estante['prateleiras']) - 1 ? ' disabled' : '' ?> aria-label="Descer <?= e(fisico_rotulo_prateleira($p)) ?>" title="Descer"><?= icon('arrow-down') ?></button>
                                    </form>
                                    <details class="adm-acao">
                                        <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('pencil') ?> Renomear</summary>
                                        <form method="post" action="<?= e($base) ?>" class="adm-acao__form">
                                            <?= csrf_campo() ?><input type="hidden" name="acao" value="prateleira_editar"><input type="hidden" name="prateleira_id" value="<?= $pid ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                                            <label class="adm-field"><span class="adm-field__sublabel">Identificação *</span><input class="adm-input adm-input--sm" name="identificacao" maxlength="20" required value="<?= e($p['prateleira']) ?>"></label>
                                            <label class="adm-field"><span class="adm-field__sublabel">Descrição <span class="adm-optional">(opcional)</span></span><input class="adm-input adm-input--sm" name="descricao" maxlength="255" value="<?= e((string)$p['descricao']) ?>" placeholder="Ex.: Poesia brasileira"></label>
                                            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Salvar</button>
                                        </form>
                                    </details>
                                    <?php if (!$vazia): ?>
                                        <details class="adm-acao">
                                            <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('arrow-right-left') ?> Transferir exemplares</summary>
                                            <form method="post" action="<?= e($base) ?>" class="adm-acao__form" data-confirm="Transferir todos os <?= (int)$p['exemplares'] ?> exemplares desta prateleira?">
                                                <?= csrf_campo() ?><input type="hidden" name="acao" value="prateleira_transferir"><input type="hidden" name="prateleira_id" value="<?= $pid ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                                                <?= fisico_campo_local('destino_id', null, 'Escolha a prateleira de destino', 'destino-' . $pid, $opcoesLocal) ?>
                                                <label class="adm-field"><span class="adm-field__sublabel">Motivo <span class="adm-optional">(opcional)</span></span><input class="adm-input adm-input--sm" name="motivo" maxlength="300" placeholder="Ex.: prateleira retirada"></label>
                                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('arrow-right-left') ?> Transferir todos</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="<?= (int)$p['ativa'] ? 'Desativar esta prateleira? Os exemplares dela continuam lá.' : 'Reativar esta prateleira?' ?>">
                                        <?= csrf_campo() ?><input type="hidden" name="acao" value="prateleira_ativa"><input type="hidden" name="prateleira_id" value="<?= $pid ?>"><input type="hidden" name="ativa" value="<?= (int)$p['ativa'] ? '0' : '1' ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= (int)$p['ativa'] ? icon('archive') . ' Desativar' : icon('archive-restore') . ' Reativar' ?></button>
                                    </form>
                                    <?php if ($vazia): ?>
                                        <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Excluir esta prateleira vazia? Esta ação não pode ser desfeita.">
                                            <?= csrf_campo() ?><input type="hidden" name="acao" value="prateleira_excluir"><input type="hidden" name="prateleira_id" value="<?= $pid ?>"><input type="hidden" name="voltar" value="<?= e($base . '?estante=' . (int)$estante['id']) ?>">
                                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('trash-2') ?> Excluir</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$estante['prateleiras']): ?>
                    <tr><td colspan="4"><div class="adm-empty"><?= icon('layers') ?><strong>Esta estante ainda não tem prateleiras.</strong><span>Use “Adicionar prateleiras”.</span></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($podeGerenciar): ?>
            <div class="adm-actions-inline adm-org-estante__rodape">
                <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="<?= $inativa ? 'Reativar esta estante?' : 'Desativar esta estante? Os exemplares continuam nela; ela só deixa de aparecer para novos registros.' ?>">
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="estante_ativa"><input type="hidden" name="estante_id" value="<?= (int)$estante['id'] ?>"><input type="hidden" name="ativa" value="<?= $inativa ? '1' : '0' ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                    <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= $inativa ? icon('archive-restore') . ' Reativar estante' : icon('archive') . ' Desativar estante' ?></button>
                </form>
                <?php if ((int)$estante['exemplares'] === 0): ?>
                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Excluir esta estante e as suas prateleiras vazias? Esta ação não pode ser desfeita.">
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="estante_excluir"><input type="hidden" name="estante_id" value="<?= (int)$estante['id'] ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('trash-2') ?> Excluir estante</button>
                    </form>
                <?php else: ?>
                    <span class="adm-field__hint">Para excluir, transfira antes os exemplares das prateleiras (ou apenas desative a estante).</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($prateleira && $exemplaresPrat !== null): ?>
        <section class="adm-card adm-panel" id="exemplares-prateleira" aria-labelledby="titulo-exemplares-prateleira">
            <div class="adm-panel__header"><div>
                <h2 id="titulo-exemplares-prateleira"><?= e(fisico_rotulo_estante($estante, false)) ?> — <?= e(fisico_rotulo_prateleira($prateleira)) ?></h2>
                <p aria-live="polite"><?= fisico_numero($exemplaresPrat['total']) ?> exemplar(es) nesta prateleira</p>
            </div></div>
            <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>#exemplares-prateleira">
                <input type="hidden" name="estante" value="<?= (int)$estante['id'] ?>"><input type="hidden" name="prateleira" value="<?= (int)$prateleira['id'] ?>">
                <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar nesta prateleira</span>
                    <input type="search" name="eq" value="<?= e(admin_param($_GET, 'eq')) ?>" placeholder="Código BDP, título ou autor..." autocomplete="off"></label>
                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Pesquisar</button>
            </form>
            <div class="adm-table-wrap adm-table-wrap--flush">
                <table class="adm-table adm-table--cards">
                    <thead><tr><th scope="col">Código</th><th scope="col">Obra</th><th scope="col">Conservação</th><th scope="col">Situação</th><th scope="col" class="adm-actions-col">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($exemplaresPrat['itens'] as $x): ?>
                        <tr>
                            <td class="adm-mono"><a class="adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$x['id']) ?>"><?= e($x['codigo_individual']) ?></a></td>
                            <td data-label="Obra" class="adm-td-title"><?= e($x['titulo']) ?><small class="adm-block-muted"><?= e($x['autores'] ?: 'Autor não informado') ?></small></td>
                            <td data-label="Conservação"><?= e(ACERVO_CONSERVACAO[$x['estado_conservacao']] ?? $x['estado_conservacao']) ?><?= $x['conservacao_avaliada_em'] === null ? ' <span class="adm-status adm-tone--amber">a avaliar</span>' : '' ?></td>
                            <td data-label="Situação"><?= acervo_badge_situacao($x['situacao']) ?></td>
                            <td class="adm-actions-col"><div class="adm-actions-inline">
                                <?php if ($podeMover): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($aqui, [], ['painel' => 'mover', 'codigo' => $x['codigo_individual']])) ?>"><?= icon('arrow-right-left') ?> Mover</a><?php endif; ?>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('historico-exemplar') . '?id=' . (int)$x['id']) ?>"><?= icon('history') ?> Histórico</a>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$exemplaresPrat['itens']): ?>
                        <tr><td colspan="5"><div class="adm-empty"><?= icon('book-copy') ?><strong>Nenhum exemplar nesta prateleira<?= admin_param($_GET, 'eq') !== '' ? ' com esta pesquisa' : '' ?>.</strong><span>Os exemplares aparecem aqui quando a equipe escolhe esta prateleira no Inventário ou usa “Mover exemplar”.</span></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= admin_paginacao($exemplaresPrat['total'], $exemplaresPrat['pagina'], 25, $base, ['estante' => (int)$estante['id'], 'prateleira' => (int)$prateleira['id'], 'eq' => admin_param($_GET, 'eq')], [25]) ?>
        </section>
    <?php endif; ?>

<?php else: ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-estantes">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-estantes">Estantes</h2>
            <p aria-live="polite"><?= fisico_numero($lista['total']) ?> estante(s)<?= $filtros['situacao'] === '' ? ' ativa(s)' : '' ?>. Clique numa estante para ver as prateleiras e os exemplares.</p>
        </div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar estantes</span>
                <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Identificação, nome, categoria ou equipe..." autocomplete="off"></label>
            <div class="adm-filters">
                <?php if ($setores): ?>
                    <label class="adm-filter"><span>Setor</span><select class="adm-select" name="setor">
                        <option value="">Todos</option><option value="sem"<?= $filtros['setor'] === 'sem' ? ' selected' : '' ?>>Sem setor</option>
                        <?php foreach ($setores as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $filtros['setor'] === (string)$s['id'] ? ' selected' : '' ?>><?= e($s['nome']) ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="adm-filter"><span>Situação</span><select class="adm-select" name="situacao">
                    <option value="">Ativas</option><option value="inativas"<?= $filtros['situacao'] === 'inativas' ? ' selected' : '' ?>>Desativadas</option><option value="todas"<?= $filtros['situacao'] === 'todas' ? ' selected' : '' ?>>Todas</option>
                </select></label>
            </div>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
        </form>
        <?php if (!$lista['itens']): ?>
            <div class="adm-empty"><?= icon('columns-3') ?>
                <strong><?= array_filter($filtros) ? 'Nenhuma estante encontrada com estes filtros.' : 'Nenhuma estante cadastrada ainda.' ?></strong>
                <span><?= array_filter($filtros) ? 'Mude a pesquisa ou limpe os filtros.' : ($podeGerenciar ? 'Cadastre a estrutura física da biblioteca para começar: uma estante por vez ou várias de uma só vez (ex.: 10 estantes com 5 prateleiras cada).' : 'Peça a quem gerencia as localizações para cadastrar as estantes.') ?></span>
                <?php if ($podeGerenciar && !array_filter($filtros)): ?><span class="adm-empty__acoes">
                    <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '?painel=estante') ?>" data-painel-abrir="painel-estante"><?= icon('plus') ?> Adicionar estante</a>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?painel=lote') ?>" data-painel-abrir="painel-lote"><?= icon('layers') ?> Criar várias estantes</a></span><?php endif; ?></div>
        <?php else: ?>
            <ul class="adm-org-grade">
                <?php foreach ($lista['itens'] as $es): $pct = $resumo['com_localizacao'] > 0 ? (int)round((int)$es['exemplares'] / max(1, $resumo['com_localizacao']) * 100) : 0; ?>
                    <li class="adm-org-cartao<?= (int)$es['ativa'] ? '' : ' is-inativa' ?>">
                        <a class="adm-org-cartao__link" href="<?= e($base . '?estante=' . (int)$es['id']) ?>">
                            <span class="adm-org-cartao__id" aria-hidden="true"><?= e($es['identificacao']) ?></span>
                            <span class="adm-org-cartao__texto">
                                <strong><?= e($es['nome']) ?><?= $es['categoria'] ? ' — ' . e($es['categoria']) : '' ?></strong>
                                <small><?= $es['setor_nome'] ? e($es['setor_nome']) . ' · ' : '' ?><?= (int)$es['prateleiras'] ?> prateleira(s)<?= !(int)$es['ativa'] ? ' · desativada' : '' ?></small>
                            </span>
                        </a>
                        <p class="adm-org-cartao__numero"><strong><?= fisico_numero((int)$es['exemplares']) ?></strong> exemplar(es)</p>
                        <p class="adm-org-cartao__resp"><?= icon('users') ?> <?= $es['responsavel_nome'] || $es['equipe'] ? e(implode(' · ', array_filter([$es['responsavel_nome'], $es['equipe']]))) : '<span class="adm-table__muted">Sem responsável</span>' ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?= admin_paginacao($lista['total'], $lista['pagina'], 50, $base, $filtros, [50]) ?>
        <?php endif; ?>
    </section>

    <div class="adm-org-colunas">
        <section class="adm-card adm-panel" aria-labelledby="titulo-andamento">
            <h2 id="titulo-andamento" class="adm-panel__title"><?= icon('users') ?> Andamento por responsável ou equipe</h2>
            <?php if (!$andamento): ?>
                <p class="adm-field__hint">Cadastre estantes e defina responsáveis para acompanhar quem está organizando cada uma.</p>
            <?php else: ?>
                <div class="adm-table-wrap adm-table-wrap--inset">
                <table class="adm-table adm-table--compacta">
                    <thead><tr><th scope="col">Responsável / equipe</th><th scope="col">Estantes</th><th scope="col">Prateleiras</th><th scope="col">Exemplares alocados</th></tr></thead>
                    <tbody>
                    <?php foreach ($andamento as $a): ?>
                        <tr>
                            <td><?= $a['responsavel_nome'] !== '' || $a['equipe'] !== '' ? e(implode(' · ', array_filter([$a['responsavel_nome'], $a['equipe']]))) : '<span class="adm-table__muted">Sem responsável</span>' ?>
                                <?php if ($a['responsavel_id']): ?><a class="adm-link adm-table__muted" href="<?= e($base . '?responsavel=' . (int)$a['responsavel_id']) ?>">ver estantes</a><?php endif; ?></td>
                            <td><?= fisico_numero((int)$a['estantes']) ?></td><td><?= fisico_numero((int)$a['prateleiras']) ?></td><td><strong><?= fisico_numero((int)$a['exemplares']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <h3 class="adm-org__subtitulo">Organizados nos últimos 7 dias</h3>
            <?php if (!$organizados): ?>
                <p class="adm-field__hint">Nenhum exemplar recebeu localização nos últimos 7 dias.</p>
            <?php else: ?>
                <ul class="adm-org-pessoas"><?php foreach ($organizados as $o): ?><li><span><?= e($o['nome']) ?></span> <strong><?= fisico_numero((int)$o['exemplares']) ?></strong> exemplar(es)</li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-setores">
            <div class="adm-panel__header"><div>
                <h2 id="titulo-setores" class="adm-panel__title"><?= icon('landmark') ?> Setores ou salas <span class="adm-optional">(opcional)</span></h2>
                <p>Use só se a biblioteca tiver mais de uma sala ou setor. Sem setores, as estantes ficam todas juntas.</p>
            </div>
            <?php if ($podeGerenciar): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?painel=setor') ?>" data-painel-abrir="painel-setor"><?= icon('plus') ?> Novo setor</a><?php endif; ?>
            </div>
            <?php if (!$setores): ?>
                <p class="adm-field__hint">Nenhum setor cadastrado.</p>
            <?php else: ?>
                <ul class="adm-org-setores">
                    <?php foreach ($setores as $s): ?>
                        <li>
                            <span><strong><?= e($s['nome']) ?></strong><?= $s['identificacao'] ? ' <span class="adm-code">' . e($s['identificacao']) . '</span>' : '' ?> · <?= (int)$s['estantes'] ?> estante(s)<?= !(int)$s['ativo'] ? ' · <span class="adm-status adm-tone--muted">Desativado</span>' : '' ?></span>
                            <?php if ($podeGerenciar): ?>
                                <span class="adm-actions-inline">
                                    <details class="adm-acao">
                                        <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('pencil') ?> Editar</summary>
                                        <form method="post" action="<?= e($base) ?>" class="adm-acao__form">
                                            <?= csrf_campo() ?><input type="hidden" name="acao" value="setor_salvar"><input type="hidden" name="setor_id" value="<?= (int)$s['id'] ?>">
                                            <label class="adm-field"><span class="adm-field__sublabel">Nome *</span><input class="adm-input adm-input--sm" name="nome" maxlength="80" required value="<?= e($s['nome']) ?>"></label>
                                            <label class="adm-field"><span class="adm-field__sublabel">Identificação</span><input class="adm-input adm-input--sm" name="identificacao" maxlength="20" value="<?= e((string)$s['identificacao']) ?>"></label>
                                            <label class="adm-field"><span class="adm-field__sublabel">Ordem de exibição</span><input class="adm-input adm-input--sm" type="number" min="0" name="ordem" value="<?= (int)$s['ordem'] ?>"></label>
                                            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Salvar</button>
                                        </form>
                                    </details>
                                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form">
                                        <?= csrf_campo() ?><input type="hidden" name="acao" value="setor_ativo"><input type="hidden" name="setor_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="ativo" value="<?= (int)$s['ativo'] ? '0' : '1' ?>">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= (int)$s['ativo'] ? 'Desativar' : 'Reativar' ?></button>
                                    </form>
                                    <?php if (!(int)$s['estantes']): ?>
                                        <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Excluir este setor sem estantes?">
                                            <?= csrf_campo() ?><input type="hidden" name="acao" value="setor_excluir"><input type="hidden" name="setor_id" value="<?= (int)$s['id'] ?>">
                                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('trash-2') ?> Excluir</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($resumo['avulsas'] > 0): ?>
                <p class="adm-field__hint"><?= icon('history') ?> Há <?= fisico_numero($resumo['avulsas']) ?> localização(ões) cadastrada(s) antes da Organização Física. Elas continuam valendo para os exemplares que estão nelas: <a class="adm-link" href="<?= e(admin_url('localizacoes')) ?>">ver localizações anteriores</a>.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="adm-card adm-panel" aria-labelledby="titulo-movimentacoes">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-movimentacoes" class="adm-panel__title"><?= icon('history') ?> Histórico de movimentações</h2>
            <p>Cada mudança de localização: exemplar, localização anterior → nova, quem fez e quando. <?= fisico_numero($movs['total']) ?> registro(s).</p>
        </div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>#titulo-movimentacoes">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar movimentações</span>
                <input type="search" name="mq" value="<?= e($mq) ?>" placeholder="Código BDP, título ou estante..." autocomplete="off"></label>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Pesquisar</button>
        </form>
        <?php if (!$movs['itens']): ?>
            <p class="adm-field__hint">Nenhuma movimentação registrada<?= $mq !== '' ? ' com esta pesquisa' : '' ?>.</p>
        <?php else: ?>
            <ol class="adm-org-movs">
                <?php foreach ($movs['itens'] as $m): ?>
                    <li><a class="adm-code" href="<?= e(admin_url('historico-exemplar') . '?id=' . (int)$m['exemplar_id']) ?>"><?= e($m['codigo_individual']) ?></a>
                        <span><?= e(mb_strimwidth($m['titulo'], 0, 70, '…')) ?></span>
                        <small class="adm-block-muted"><?= e($m['descricao']) ?></small>
                        <small class="adm-block-muted"><?= admin_data_hora($m['criado_em']) ?><?= $m['responsavel_nome'] ? ' · ' . e($m['responsavel_nome']) : '' ?></small></li>
                <?php endforeach; ?>
            </ol>
            <?php if ($movs['paginas'] > 1): ?>
                <nav class="adm-busca-conf__paginas" aria-label="Páginas das movimentações">
                    <?php if ($movs['pagina'] > 1): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtros, ['mq' => $mq, 'mpagina' => $movs['pagina'] - 1])) ?>#titulo-movimentacoes"><?= icon('chevron-left') ?> Mais recentes</a><?php endif; ?>
                    <span class="adm-table__muted">Página <?= $movs['pagina'] ?> de <?= $movs['paginas'] ?></span>
                    <?php if ($movs['pagina'] < $movs['paginas']): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtros, ['mq' => $mq, 'mpagina' => $movs['pagina'] + 1])) ?>#titulo-movimentacoes">Mais antigas <?= icon('chevron-right') ?></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php /* ===================== Painéis (formulários) ===================== */ ?>
<?php if ($podeGerenciar): ?>
    <?php $v = $valores('estante'); ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-estante" aria-labelledby="painel-estante-titulo"<?= $abrir('estante') ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
            <header class="adm-modal__header"><div><h2 id="painel-estante-titulo"><?= icon('plus') ?> Adicionar estante</h2><p>As prateleiras são criadas junto (Prateleira 1, 2, 3...). Tudo pode ser editado depois.</p></div>
                <a class="adm-modal__close" href="<?= e($base) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a></header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="estante_criar"><input type="hidden" name="voltar" value="<?= e($base) ?>">
                <div class="adm-form-grid">
                    <label class="adm-field"><span class="adm-field__label">Identificação curta *</span><input class="adm-input" name="identificacao" maxlength="20" required value="<?= e($v['identificacao'] ?? '') ?>" placeholder="A, B, C ou E01" autocapitalize="characters"><span class="adm-field__hint">Aparece nas etiquetas. Não se repete no mesmo setor.</span></label>
                    <label class="adm-field"><span class="adm-field__label">Nome da estante</span><input class="adm-input" name="nome" maxlength="80" value="<?= e($v['nome'] ?? '') ?>" placeholder="Estante A (padrão)"></label>
                    <label class="adm-field"><span class="adm-field__label">Categoria ou finalidade <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="categoria" maxlength="120" value="<?= e($v['categoria'] ?? '') ?>" placeholder="Ex.: Literatura brasileira"></label>
                    <label class="adm-field"><span class="adm-field__label">Quantidade de prateleiras</span><input class="adm-input" type="number" name="prateleiras" min="0" max="<?= OrganizacaoFisicaService::MAX_PRATELEIRAS ?>" value="<?= e($v['prateleiras'] ?? '5') ?>" required></label>
                    <?php if ($setores): ?><label class="adm-field"><span class="adm-field__label">Setor ou sala</span><?= $campoSetor('setor_id', $v['setor_id'] ?? '') ?></label><?php endif; ?>
                    <label class="adm-field"><span class="adm-field__label">Ordem de exibição <span class="adm-optional">(opcional)</span></span><input class="adm-input" type="number" name="ordem" min="0" value="<?= e($v['ordem'] ?? '') ?>" placeholder="Depois das outras"></label>
                    <label class="adm-field adm-field--full"><span class="adm-field__label">Descrição <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="descricao" maxlength="255" value="<?= e($v['descricao'] ?? '') ?>" placeholder="Ex.: perto da janela"></label>
                </div>
            </div>
            <footer class="adm-modal__footer"><a class="adm-button adm-button--ghost" href="<?= e($base) ?>" data-painel-fechar>Cancelar</a><button class="adm-button adm-button--primary" type="submit" data-enviar-uma-vez><?= icon('save') ?> Salvar estante</button></footer>
        </form>
    </dialog>

    <?php $v = $valores('lote'); ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-lote" aria-labelledby="painel-lote-titulo"<?= $abrir('lote') ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
            <header class="adm-modal__header"><div><h2 id="painel-lote-titulo"><?= icon('layers') ?> Criar várias estantes</h2><p>Gera uma estrutura inicial com identificações em sequência. Identificações que já existem no setor são puladas.</p></div>
                <a class="adm-modal__close" href="<?= e($base) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a></header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="estantes_lote"><input type="hidden" name="voltar" value="<?= e($base) ?>">
                <div class="adm-form-grid">
                    <label class="adm-field"><span class="adm-field__label">Quantas estantes *</span><input class="adm-input" type="number" name="quantidade" min="1" max="<?= OrganizacaoFisicaService::MAX_ESTANTES_LOTE ?>" required value="<?= e($v['quantidade'] ?? '10') ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Prateleiras em cada uma</span><input class="adm-input" type="number" name="prateleiras" min="0" max="<?= OrganizacaoFisicaService::MAX_PRATELEIRAS ?>" required value="<?= e($v['prateleiras'] ?? '5') ?>"></label>
                    <fieldset class="adm-field adm-field--full adm-escolha"><legend class="adm-field__sublabel">Identificações</legend>
                        <label class="adm-escolha__opcao"><input type="radio" name="padrao" value="letras"<?= ($v['padrao'] ?? 'letras') === 'letras' ? ' checked' : '' ?>> <span><strong>Letras</strong> <small>A, B, C... (depois de Z: AA, AB...)</small></span></label>
                        <label class="adm-escolha__opcao"><input type="radio" name="padrao" value="numeros"<?= ($v['padrao'] ?? '') === 'numeros' ? ' checked' : '' ?>> <span><strong>Números com prefixo</strong> <small>E01, E02, E03... (prefixo abaixo)</small></span></label>
                    </fieldset>
                    <label class="adm-field"><span class="adm-field__label">Prefixo <span class="adm-optional">(só para números)</span></span><input class="adm-input" name="prefixo" maxlength="10" value="<?= e($v['prefixo'] ?? 'E') ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Começar em <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="inicio" maxlength="3" value="<?= e($v['inicio'] ?? '') ?>" placeholder="A ou 1"></label>
                    <?php if ($setores): ?><label class="adm-field"><span class="adm-field__label">Setor ou sala</span><?= $campoSetor('setor_id', $v['setor_id'] ?? '') ?></label><?php endif; ?>
                    <label class="adm-field"><span class="adm-field__label">Categoria <span class="adm-optional">(opcional, para todas)</span></span><input class="adm-input" name="categoria" maxlength="120" value="<?= e($v['categoria'] ?? '') ?>"></label>
                </div>
                <p class="adm-field__hint">Exemplo: 10 estantes com 5 prateleiras cria Estante A a Estante J, cada uma com Prateleira 1 a 5. Nenhum livro é colocado nelas: a equipe escolhe a localização de cada exemplar no Inventário.</p>
            </div>
            <footer class="adm-modal__footer"><a class="adm-button adm-button--ghost" href="<?= e($base) ?>" data-painel-fechar>Cancelar</a><button class="adm-button adm-button--primary" type="submit" data-enviar-uma-vez><?= icon('layers') ?> Criar estantes</button></footer>
        </form>
    </dialog>

    <?php $v = $valores('setor'); ?>
    <dialog class="adm-modal adm-painel-lateral adm-painel-lateral--estreito" id="painel-setor" aria-labelledby="painel-setor-titulo"<?= $abrir('setor') ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
            <header class="adm-modal__header"><div><h2 id="painel-setor-titulo"><?= icon('landmark') ?> Novo setor ou sala</h2></div><a class="adm-modal__close" href="<?= e($base) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a></header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="setor_salvar">
                <label class="adm-field"><span class="adm-field__label">Nome *</span><input class="adm-input" name="nome" maxlength="80" required value="<?= e($v['nome'] ?? '') ?>" placeholder="Ex.: Sala de leitura"></label>
                <label class="adm-field"><span class="adm-field__label">Identificação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="identificacao" maxlength="20" value="<?= e($v['identificacao'] ?? '') ?>" placeholder="Ex.: S1"></label>
                <label class="adm-field"><span class="adm-field__label">Descrição <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="descricao" maxlength="255" value="<?= e($v['descricao'] ?? '') ?>"></label>
            </div>
            <footer class="adm-modal__footer"><a class="adm-button adm-button--ghost" href="<?= e($base) ?>" data-painel-fechar>Cancelar</a><button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar setor</button></footer>
        </form>
    </dialog>

    <?php if ($estante): ?>
        <?php $v = $valores('editar-estante') ?: $estante; ?>
        <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-editar-estante" aria-labelledby="painel-editar-titulo"<?= $abrir('editar-estante') ?>>
            <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
                <header class="adm-modal__header"><div><h2 id="painel-editar-titulo"><?= icon('pencil') ?> Editar estante</h2><p>Os exemplares continuam nas mesmas prateleiras.</p></div><a class="adm-modal__close" href="<?= e($aqui) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a></header>
                <div class="adm-modal__body">
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="estante_editar"><input type="hidden" name="estante_id" value="<?= (int)$estante['id'] ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                    <div class="adm-form-grid">
                        <label class="adm-field"><span class="adm-field__label">Identificação curta *</span><input class="adm-input" name="identificacao" maxlength="20" required value="<?= e((string)$v['identificacao']) ?>"></label>
                        <label class="adm-field"><span class="adm-field__label">Nome da estante *</span><input class="adm-input" name="nome" maxlength="80" required value="<?= e((string)$v['nome']) ?>"></label>
                        <label class="adm-field"><span class="adm-field__label">Categoria ou finalidade</span><input class="adm-input" name="categoria" maxlength="120" value="<?= e((string)($v['categoria'] ?? '')) ?>"></label>
                        <?php if ($setores || $estante['setor_id']): ?><label class="adm-field"><span class="adm-field__label">Setor ou sala</span><?= $campoSetor('setor_id', $v['setor_id'] ?? '') ?></label><?php endif; ?>
                        <label class="adm-field"><span class="adm-field__label">Ordem de exibição</span><input class="adm-input" type="number" name="ordem" min="0" value="<?= e((string)($v['ordem'] ?? '')) ?>"></label>
                        <label class="adm-field adm-field--full"><span class="adm-field__label">Descrição</span><input class="adm-input" name="descricao" maxlength="255" value="<?= e((string)($v['descricao'] ?? '')) ?>"></label>
                    </div>
                </div>
                <footer class="adm-modal__footer"><a class="adm-button adm-button--ghost" href="<?= e($aqui) ?>" data-painel-fechar>Cancelar</a><button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar alterações</button></footer>
            </form>
        </dialog>

        <?php $v = $valores('responsavel') ?: ['responsavel_id' => $estante['responsavel_id'], 'equipe' => $estante['equipe']]; ?>
        <dialog class="adm-modal adm-painel-lateral adm-painel-lateral--estreito" id="painel-responsavel" aria-labelledby="painel-responsavel-titulo"<?= $abrir('responsavel') ?>>
            <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
                <header class="adm-modal__header"><div><h2 id="painel-responsavel-titulo"><?= icon('user-check') ?> Responsável pela <?= e($estante['nome']) ?></h2><p>Só para organizar o trabalho. Não bloqueia a edição por outras pessoas e não cria contas.</p></div><a class="adm-modal__close" href="<?= e($aqui) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a></header>
                <div class="adm-modal__body">
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="estante_responsavel"><input type="hidden" name="estante_id" value="<?= (int)$estante['id'] ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                    <label class="adm-field"><span class="adm-field__label">Pessoa do painel</span>
                        <select class="adm-select" name="responsavel_id"><option value="">Ninguém</option>
                            <?php foreach ($pessoas as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= (string)($v['responsavel_id'] ?? '') === (string)$pp['id'] ? ' selected' : '' ?>><?= e($pp['nome']) ?></option><?php endforeach; ?>
                        </select></label>
                    <label class="adm-field"><span class="adm-field__label">Nome da equipe <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="equipe" maxlength="80" value="<?= e((string)($v['equipe'] ?? '')) ?>" placeholder="Ex.: Equipe 1 (alunos do 8º ano)"><span class="adm-field__hint">Os alunos não precisam de conta: o nome da equipe é só uma identificação.</span></label>
                </div>
                <footer class="adm-modal__footer"><a class="adm-button adm-button--ghost" href="<?= e($aqui) ?>" data-painel-fechar>Cancelar</a><button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar responsável</button></footer>
            </form>
        </dialog>

        <?php $v = $valores('prateleiras'); ?>
        <dialog class="adm-modal adm-painel-lateral adm-painel-lateral--estreito" id="painel-prateleiras" aria-labelledby="painel-prateleiras-titulo"<?= $abrir('prateleiras') ?>>
            <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
                <header class="adm-modal__header"><div><h2 id="painel-prateleiras-titulo"><?= icon('plus') ?> Adicionar prateleiras</h2><p><?= e(fisico_rotulo_estante($estante)) ?></p></div><a class="adm-modal__close" href="<?= e($aqui) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a></header>
                <div class="adm-modal__body">
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="prateleiras_adicionar"><input type="hidden" name="estante_id" value="<?= (int)$estante['id'] ?>"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                    <label class="adm-field"><span class="adm-field__label">Quantidade</span><input class="adm-input" type="number" name="quantidade" min="1" max="<?= OrganizacaoFisicaService::MAX_PRATELEIRAS ?>" value="<?= e($v['quantidade'] ?? '1') ?>"><span class="adm-field__hint">Continua a numeração (se a última é a 5, a próxima é a 6).</span></label>
                    <label class="adm-field"><span class="adm-field__label">Ou um nome específico <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="identificacao" maxlength="20" value="<?= e($v['identificacao'] ?? '') ?>" placeholder="Ex.: Superior"></label>
                </div>
                <footer class="adm-modal__footer"><a class="adm-button adm-button--ghost" href="<?= e($aqui) ?>" data-painel-fechar>Cancelar</a><button class="adm-button adm-button--primary" type="submit" data-enviar-uma-vez><?= icon('plus') ?> Adicionar</button></footer>
            </form>
        </dialog>
    <?php endif; ?>
<?php endif; ?>

<?php if ($podeMover): ?>
    <?php $v = $valores('mover') + ['codigo' => $codigoMover, 'destino_id' => '']; ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-mover" aria-labelledby="painel-mover-titulo"<?= $abrir('mover') ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
            <header class="adm-modal__header"><div><h2 id="painel-mover-titulo"><?= icon('arrow-right-left') ?> Mover exemplar</h2><p>Muda só a localização do exemplar. Os dados da obra não mudam. Fica no histórico: localização anterior, nova, quem moveu e quando.</p></div>
                <a class="adm-modal__close" href="<?= e($aqui) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a></header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="mover_exemplar"><input type="hidden" name="voltar" value="<?= e($aqui) ?>">
                <label class="adm-field"><span class="adm-field__label">Código BDP do exemplar *</span><input class="adm-input adm-input--code" name="codigo" maxlength="30" required value="<?= e((string)$v['codigo']) ?>" placeholder="BDP-000123" autocapitalize="characters" spellcheck="false" autocomplete="off" data-campo-codigo></label>
                <fieldset class="adm-fieldset"><legend class="adm-field__label">Nova localização *</legend>
                    <?= fisico_campo_local('destino_id', (int)$v['destino_id'] ?: null, 'Escolha a prateleira', 'mover-destino', $opcoesLocal) ?></fieldset>
                <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="motivo" maxlength="300" value="<?= e((string)($v['motivo'] ?? '')) ?>" placeholder="Ex.: reorganização da estante B"></label>
            </div>
            <footer class="adm-modal__footer"><a class="adm-button adm-button--ghost" href="<?= e($aqui) ?>" data-painel-fechar>Cancelar</a><button class="adm-button adm-button--primary" type="submit" data-enviar-uma-vez><?= icon('arrow-right-left') ?> Confirmar movimentação</button></footer>
        </form>
    </dialog>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
