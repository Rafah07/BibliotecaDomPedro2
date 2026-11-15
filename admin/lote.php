<?php
/**
 * Relatório e etapas de um lote (migração 160). Abre qualquer lote, inclusive os encerrados.
 *
 * Mostra: informações gerais (responsáveis, capacidade, datas de cada etapa), resumo, exemplares
 * (código BDP, título, autor, quem cadastrou, carimbo, localização, organização), dúvidas,
 * produtividade (só lotes.gerenciar), participantes e o histórico permanente de eventos.
 *
 * Ações conforme a etapa (todas conferidas de novo no servidor, em LoteService):
 *   Em cadastro            → "Fechar lote antecipadamente" (lotes.gerenciar)
 *   Fechado / Em carimbagem → marcar exemplares como carimbados (um ou vários, com aviso) e
 *                             "Confirmar carimbagem do lote" (só com todos carimbados)
 *   Aguardando organização → escolher estante e prateleira e guardar um ou vários exemplares
 *                             (sugere a última prateleira usada) → "Encerrar lote" (lotes.gerenciar)
 *   Sempre                 → planilha BDP (imprimir/Excel), relatório (imprimir/Excel/PDF),
 *                             correção administrativa com justificativa (lotes.gerenciar)
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\DuvidaService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\OperacaoService;

$usuario = fisico_iniciar();
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$base = admin_url('lote') . '?id=' . $id;
$podeGerenciar = auth_pode('lotes.gerenciar');
$podeExemplares = auth_pode('exemplares.gerenciar');

try {
    $lote = lotes_repo()->lote($id);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir o lote', fisico_erro_tecnico($e, 'lote') . ' Confira se a migração 160 foi aplicada.');
    exit;
}
if (!$lote) {
    http_response_code(404);
    admin_pagina_erro('Lote não encontrado', 'Confira o endereço ou volte ao Histórico de Lotes.', 'layers');
    exit;
}
$nome = LoteRepository::nome($lote);

/** Ids escolhidos nas caixas de seleção. */
function lote_ids(array $post): array
{
    $ids = $post['ids'] ?? [];
    return array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn ($i) => $i > 0))), 0, 500);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    if (preg_match('/^carimbar:(\d+)$/', admin_param($_POST, 'unico'), $m)) {
        [$acao, $_POST['ids']] = ['carimbar', [$m[1]]];
        $_POST['confirmo'] = '1';
    }
    $ancora = ['carimbar' => '#carimbagem', 'confirmar_carimbagem' => '#carimbagem', 'organizar' => '#organizacao', 'atribuir' => '#participantes', 'remover' => '#participantes', 'corrigir' => '#correcao'][$acao] ?? '';
    try {
        switch ($acao) {
            case 'fechar':
                fisico_exigir('lotes.gerenciar');
                lotes_servico()->fecharAntecipado($id, $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', $nome . ' fechado — aguardando carimbagem.', 'Nenhum exemplar novo entra no lote. Os registros existentes continuam podendo ser corrigidos. A planilha BDP já está disponível.');
                break;
            case 'atribuir':
                fisico_exigir('lotes.gerenciar');
                $n = lotes_servico()->atribuir($id, is_array($_POST['usuarios'] ?? null) ? $_POST['usuarios'] : [], $usuario['id']);
                admin_flash($n ? 'sucesso' : 'aviso', $n ? $n . ' usuário(s) incluído(s) no lote.' : 'Nada foi alterado.', $n ? '' : 'Os usuários escolhidos já participavam do lote.');
                break;
            case 'remover':
                fisico_exigir('lotes.gerenciar');
                lotes_servico()->remover($id, (int)admin_param($_POST, 'usuario_id'), $usuario['id']);
                admin_flash('sucesso', 'Usuário removido do lote.', 'O que ele já registrou continua no lote, com o nome dele.');
                break;
            case 'carimbar':
                fisico_exigir('exemplares.gerenciar');
                $ids = lote_ids($_POST);
                if (!$ids) {
                    throw new AcervoException('Selecione pelo menos um exemplar.');
                }
                if (count($ids) > 1 && admin_param($_POST, 'confirmo') !== '1') {
                    throw new AcervoException('Para confirmar vários exemplares de uma vez, marque “Conferi o código escrito em cada livro selecionado”.');
                }
                if (!in_array($lote['status'], ['fechado', 'em_carimbagem'], true)) {
                    throw new AcervoException($lote['status'] === 'aberto' ? 'O lote ainda está em cadastro: a carimbagem começa depois de fechado.' : 'A carimbagem deste lote já foi confirmada.');
                }
                $doLote = array_map('intval', array_column(db_query('SELECT id FROM exemplares WHERE lote_id = ? AND id IN (' . implode(',', $ids) . ')', [$id])->fetchAll(), 'id'));
                if (count($doLote) !== count($ids)) {
                    throw new AcervoException('Algum exemplar selecionado não pertence a este lote.');
                }
                $op = operacoes_servico()->realizar('carimbo', count($ids) === 1 ? 'Carimbo confirmado (' . $nome . ')' : 'Carimbo confirmado em ' . count($ids) . ' exemplares (' . $nome . ')', $usuario['id'],
                    fn () => exemplares_servico()->marcarCarimbado($ids, $usuario['id']), admin_param($_POST, 'chave') ?: null);
                $codigos = $op['resultado'];
                admin_flash($codigos ? 'sucesso' : 'aviso', $codigos ? (count($codigos) === 1 ? $codigos[0] . ' marcado como carimbado.' : count($codigos) . ' exemplares marcados como carimbados.') : 'Nada foi alterado.',
                    $codigos ? 'Registrado em seu nome, com data e hora. Marcou por engano? Use “Desfazer” em Ações recentes antes de confirmar a carimbagem do lote.' : 'Os exemplares escolhidos já estavam carimbados.');
                break;
            case 'confirmar_carimbagem':
                fisico_exigir('exemplares.gerenciar');
                lotes_servico()->confirmarCarimbagem($id, $usuario['id'], admin_param($_POST, 'chave') ?: null);
                admin_flash('sucesso', 'Carimbagem do ' . $nome . ' confirmada.', 'O lote agora está “Aguardando organização nas estantes”. Escolha a estante e a prateleira de cada exemplar.');
                $ancora = '#organizacao';
                break;
            case 'organizar':
                fisico_exigir('exemplares.gerenciar');
                $local = (int)admin_param($_POST, 'localizacao_id');
                if (!$local) {
                    throw new AcervoException('Escolha a estante e a prateleira.');
                }
                $ids = lote_ids($_POST);
                $op = operacoes_servico()->realizar('movimentacao_lote', 'Organização do ' . $nome . ': ' . count($ids) . ' exemplar(es) para ' . fisico_local_id($local), $usuario['id'],
                    fn () => lotes_servico()->organizar($id, $ids, $local, $usuario['id']), admin_param($_POST, 'chave') ?: null);
                $n = (int)$op['resultado'];
                admin_flash($n ? 'sucesso' : 'aviso', $n ? $n . ' exemplar(es) guardado(s) em ' . fisico_local_id($local) . '.' : 'Nada foi alterado.',
                    $n ? 'A localização é de cada exemplar. Quem organizou e quando ficaram registrados.' : 'Os exemplares escolhidos já estavam nesta prateleira.');
                break;
            case 'encerrar':
                fisico_exigir('lotes.gerenciar');
                lotes_servico()->encerrar($id, $usuario['id'], admin_param($_POST, 'chave') ?: null);
                admin_flash('sucesso', $nome . ' encerrado.', 'Todos os exemplares estão carimbados e nas estantes. O lote, a planilha BDP e todo o histórico continuam disponíveis para consulta e reimpressão.');
                break;
            case 'corrigir':
                fisico_exigir('lotes.gerenciar');
                lotes_servico()->corrigir($id, ['justificativa' => admin_param($_POST, 'justificativa'), 'descricao' => admin_param($_POST, 'descricao'), 'capacidade' => admin_param($_POST, 'capacidade')], $usuario['id']);
                admin_flash('sucesso', 'Correção registrada no histórico do lote.', 'A justificativa, quem corrigiu e quando ficam guardados. O encerramento original não muda.');
                break;
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado.', fisico_erro_tecnico($e, 'lote'));
    }
    admin_redirecionar($base . $ancora);
}

// ---------- Planilha BDP do lote: a mesma Planilha de Códigos BDP, gerada do banco ----------
$saida = admin_param($_GET, 'saida');
if ($saida === 'planilha' || $saida === 'planilha_excel') {
    $f = ['carimbo' => 'todos', 'organizacao' => 'todos', 'lote' => (string)$id, 'ordem' => 'lote'];
    admin_redirecionar(admin_url_filtros(admin_url('planilha-bdp'), $f + ($saida === 'planilha' ? ['saida' => 'imprimir'] : ['saida' => 'exportar', 'formato' => 'xlsx'])));
}

try {
    $resumo = lotes_repo()->resumo($id);
    $exemplares = lotes_repo()->exemplares($id);
    $duvidas = lotes_repo()->duvidas($id);
    $participantes = lotes_repo()->participantes($id);
    $eventos = lotes_repo()->eventos($id);
    $produtividade = $podeGerenciar ? lotes_repo()->produtividade($id) : [];
    $tempos = LoteRepository::tempos($lote);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível montar o relatório', fisico_erro_tecnico($e, 'relatório do lote'));
    exit;
}

/** Linhas da tabela de exemplares, prontas para impressão/exportação. */
function lote_linhas(array $exemplares): array
{
    return array_map(fn ($x) => [
        'ordem' => (int)$x['lote_ordem'], 'codigo' => $x['codigo_individual'], 'titulo' => trim(($x['titulo'] ?? 'Sem título') . ($x['subtitulo'] ? ': ' . $x['subtitulo'] : '')),
        'autores' => (string)$x['autores'], 'id' => (int)$x['id'], 'cadastrado_por' => (string)$x['cadastrado_por_nome'], 'cadastrado_em' => admin_data_hora($x['criado_em']),
        'carimbo' => ExemplarService::CARIMBO[$x['carimbo_status']] ?? $x['carimbo_status'], 'carimbado_por' => (string)$x['carimbado_por_nome'],
        'localizacao' => fisico_local_ou_triagem($x), 'organizacao' => $x['localizacao_id'] !== null ? 'Organizado' : 'Aguardando organização',
        'organizado_por' => (string)$x['organizado_por_nome'], 'situacao' => ExemplarService::rotuloSituacao($x['situacao']),
    ], $exemplares);
}

$exportar = admin_param($_GET, 'exportar');
if (in_array($exportar, ['xlsx', 'csv', 'pdf'], true)) {
    fisico_exigir('relatorios.exportar');
    registrar_acao('lote.exportar_relatorio', 'lote', $id, ['formato' => $exportar]);
    fisico_enviar_tabela($exportar, 'relatorio-lote-' . ExemplarService::numeroLote($lote['numero']) . '-' . date('Y-m-d-His'), 'Relatório do ' . $nome, [
        'ordem' => 'Ordem', 'codigo' => 'Código BDP', 'titulo' => 'Título', 'autores' => 'Autor', 'id' => 'ID interno', 'cadastrado_por' => 'Cadastrado por', 'cadastrado_em' => 'Cadastrado em',
        'carimbo' => 'Carimbagem', 'carimbado_por' => 'Carimbado por', 'localizacao' => 'Localização definitiva', 'organizacao' => 'Organização', 'organizado_por' => 'Organizado por', 'situacao' => 'Situação',
    ], ['codigo' => 'codigo', 'ordem' => 'numero', 'id' => 'numero'], lote_linhas($exemplares),
        [etiquetas_biblioteca() . ' — Relatório do ' . $nome, 'Status: ' . ExemplarService::rotuloStatusLote($lote['status']) . ' · responsáveis: ' . ($lote['participantes'] ?: '—') . ' · gerado em ' . date('d/m/Y H:i')],
        $usuario['nome'],
        [['Total cadastrado', $resumo['cadastrados']], ['Total carimbado', $resumo['carimbados']], ['Total organizado', $resumo['organizados']], ['Total pendente', $resumo['pendentes']], ['Dúvidas abertas', $resumo['duvidas_abertas']], ['Ocorrências abertas', $resumo['ocorrencias_abertas']]],
        ['Lote' => $nome, 'Status' => ExemplarService::rotuloStatusLote($lote['status']), 'Aberto em' => admin_data_hora($lote['criado_em'])]);
}

$st = $lote['status'];
$pendCarimbo = array_values(array_filter($exemplares, fn ($x) => $x['carimbo_status'] !== 'carimbado'));
$pendOrganizacao = array_values(array_filter($exemplares, fn ($x) => $x['carimbo_status'] === 'carimbado' && $x['localizacao_id'] === null));
$sugestao = fisico_sugestao_local($usuario['id']);
$chave = OperacaoService::novaChave();
$imprimir = $saida === 'relatorio';
$atribuiveis = $podeGerenciar ? lotes_repo()->usuariosAtribuiveis() : [];
$participaIds = array_map('intval', array_column(array_filter($participantes, fn ($p) => $p['removido_em'] === null), 'administrador_id'));

admin_page_start('lote', $nome, 'Inventário', 'Relatório do lote', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], ['Histórico de Lotes', admin_url('lotes')], [$nome]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($nome) ?> <?= lote_badge($st) ?></h1>
        <p><?= $lote['descricao'] ? e($lote['descricao']) . ' · ' : '' ?>do cadastro à estante definitiva. O lote e a planilha BDP nunca são apagados.</p>
    </div>
    <div class="adm-page-head__actions adm-no-print">
        <a class="adm-button adm-button--ghost" href="<?= e($base . '&saida=planilha') ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Imprimir planilha</a>
        <a class="adm-button adm-button--ghost" href="<?= e($base . '&saida=planilha_excel') ?>"><?= icon('file-spreadsheet') ?> Exportar para Excel</a>
        <button class="adm-button adm-button--ghost" type="button" data-imprimir><?= icon('printer') ?> Imprimir relatório</button>
        <?php if (auth_pode('relatorios.exportar')): ?>
            <a class="adm-button adm-button--ghost" href="<?= e($base . '&exportar=xlsx') ?>"><?= icon('file-down') ?> Relatório em Excel</a>
            <a class="adm-button adm-button--ghost" href="<?= e($base . '&exportar=pdf') ?>"><?= icon('file-down') ?> Relatório em PDF</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<?= lote_etapas_html($lote) ?>

<div class="adm-lote-grid">
<section class="adm-card adm-panel" aria-labelledby="titulo-info">
    <h2 id="titulo-info" class="adm-panel__title"><?= icon('layers') ?> Informações gerais</h2>
    <dl class="adm-meta adm-meta--grid">
        <div><dt>Número do lote</dt><dd><?= e($nome) ?></dd></div>
        <div><dt>Responsáveis</dt><dd><?= $lote['participantes'] ? e($lote['participantes']) : '—' ?></dd></div>
        <div><dt>Capacidade configurada</dt><dd><?= $lote['capacidade'] !== null ? fisico_numero((int)$lote['capacidade']) . ' exemplar(es)' : 'Sem limite (lote anterior ao gerenciamento)' ?></dd></div>
        <div><dt>Criado em</dt><dd><?= e(admin_data_hora($lote['criado_em'])) ?><?= $lote['criado_por_nome'] ? ' por ' . e($lote['criado_por_nome']) : '' ?></dd></div>
        <div><dt>Fechado para novos cadastros</dt><dd><?= $lote['fechado_em'] ? e(admin_data_hora($lote['fechado_em'])) . ($lote['fechamento_tipo'] === 'antecipado' ? ' (antecipadamente' . ($lote['fechado_por_nome'] ? ' por ' . e($lote['fechado_por_nome']) : '') . ')' : ($lote['fechamento_tipo'] === 'automatico' ? ' (automaticamente, ao completar)' : '')) : '—' ?></dd></div>
        <div><dt>Carimbagem iniciada</dt><dd><?= $lote['carimbagem_iniciada_em'] ? e(admin_data_hora($lote['carimbagem_iniciada_em'])) . ($lote['carimbagem_iniciada_por_nome'] ? ' por ' . e($lote['carimbagem_iniciada_por_nome']) : '') : '—' ?></dd></div>
        <div><dt>Carimbagem concluída</dt><dd><?= $lote['carimbagem_concluida_em'] ? e(admin_data_hora($lote['carimbagem_concluida_em'])) . ($lote['carimbagem_concluida_por_nome'] ? ' por ' . e($lote['carimbagem_concluida_por_nome']) : '') : '—' ?></dd></div>
        <div><dt>Encerramento definitivo</dt><dd><?= $lote['encerrado_em'] ? e(admin_data_hora($lote['encerrado_em'])) . ($lote['encerrado_por_nome'] ? ' por ' . e($lote['encerrado_por_nome']) : '') : '—' ?></dd></div>
    </dl>
    <?= lote_progresso($lote) ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-resumo">
    <h2 id="titulo-resumo" class="adm-panel__title"><?= icon('list-checks') ?> Resumo</h2>
    <div class="adm-stats adm-stats--lote">
        <?= fisico_stat('Total cadastrado', $resumo['cadastrados'], fisico_numero($resumo['obras']) . ' obra(s)', 'book-copy', 'blue') ?>
        <?= fisico_stat('Total carimbado', $resumo['carimbados'], 'Código escrito no livro', 'stamp', 'teal') ?>
        <?= fisico_stat('Total organizado', $resumo['organizados'], 'Na estante definitiva', 'map-pin', 'green') ?>
        <?= fisico_stat('Total pendente', $resumo['pendentes'], 'Sem carimbo ou sem estante', 'hourglass', 'amber') ?>
        <?= fisico_stat('Dúvidas abertas', $resumo['duvidas_abertas'], $resumo['duvidas_bloqueantes'] ? $resumo['duvidas_bloqueantes'] . ' impedem confirmar a etapa' : 'Nenhuma bloqueia a etapa', 'circle-help', $resumo['duvidas_bloqueantes'] ? 'red' : 'amber', admin_url('duvidas') . '?lote=' . $id) ?>
        <?= fisico_stat('Ocorrências abertas', $resumo['ocorrencias_abertas'], 'Problemas físicos', 'triangle-alert', 'red', admin_url('ocorrencias')) ?>
    </div>
    <p class="adm-field__hint"><?= icon('info') ?> Disponíveis para empréstimo agora: <strong><?= fisico_numero($resumo['disponiveis']) ?></strong>. Um exemplar deste lote só fica disponível depois de carimbado e guardado na estante, e se nenhuma ocorrência bloquear.</p>
</section>
</div>

<?php // ---------- Etapa atual ---------- ?>
<?php if ($st === 'aberto'): ?>
<section class="adm-card adm-panel adm-no-print" aria-labelledby="titulo-etapa">
    <h2 id="titulo-etapa" class="adm-panel__title"><?= icon('square-check') ?> Etapa atual: cadastro</h2>
    <p>Os usuários do lote escolhem “<?= e($nome) ?>” em <strong>Meus lotes</strong> (página de Conferência) e cada exemplar registrado entra aqui automaticamente. Ao completar <?= fisico_numero((int)$lote['capacidade']) ?> exemplar(es), o lote fecha sozinho.</p>
    <?php if ($podeGerenciar): ?>
        <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Fechar o <?= e($nome) ?> agora, com <?= (int)$lote['total'] ?> exemplar(es)? Nenhum exemplar novo poderá entrar nele. Os registros existentes continuam podendo ser corrigidos.">
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="fechar">
            <input class="adm-input adm-input--sm" name="motivo" maxlength="255" placeholder="Motivo (opcional)" aria-label="Motivo do fechamento antecipado">
            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('lock') ?> Fechar lote antecipadamente</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (in_array($st, ['fechado', 'em_carimbagem'], true)): ?>
<section class="adm-card adm-panel adm-no-print" aria-labelledby="titulo-carimbagem" id="carimbagem">
    <div class="adm-panel__header"><div><h2 id="titulo-carimbagem"><?= icon('stamp') ?> Etapa atual: carimbagem</h2>
        <p>Imprima a planilha, escreva cada código BDP no carimbo do livro da mesma linha (mesma ordem) e confirme aqui. <?= count($pendCarimbo) ?> de <?= count($exemplares) ?> exemplar(es) ainda aguardam o carimbo.</p></div>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&saida=planilha') ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Imprimir planilha</a></div>
    <?php if ($pendCarimbo && $podeExemplares): ?>
        <form method="post" action="<?= e($base) ?>" data-enviar-uma-vez data-confirm="Confirmar o carimbo dos exemplares selecionados? Confira se cada código foi escrito no livro certo.">
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="carimbar"><input type="hidden" name="chave" value="<?= e($chave) ?>">
            <div class="adm-table-wrap">
                <table class="adm-table adm-table--cards">
                    <thead><tr><th scope="col"><label class="adm-check"><input type="checkbox" data-marcar-todos="ids[]"> <span class="adm-visually-hidden">Selecionar todos</span></label></th><th scope="col">Ordem</th><th scope="col">Código BDP</th><th scope="col">Obra</th><th scope="col">Cadastro</th><th scope="col"><span class="adm-visually-hidden">Ação</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($pendCarimbo as $x): ?>
                        <tr>
                            <td class="adm-check-col"><label class="adm-check"><input type="checkbox" name="ids[]" value="<?= (int)$x['id'] ?>"> <span class="adm-visually-hidden">Selecionar <?= e($x['codigo_individual']) ?></span></label></td>
                            <td data-label="Ordem"><strong><?= (int)$x['lote_ordem'] ?></strong></td>
                            <td data-label="Código BDP"><span class="adm-code"><?= e($x['codigo_individual']) ?></span><?= (int)$x['duvidas_abertas'] ? ' <span class="adm-status adm-tone--amber">' . icon('circle-help') . ' dúvida</span>' : '' ?></td>
                            <td class="adm-td-title"><?= e($x['titulo'] ?? 'Sem título') ?><small class="adm-block-muted"><?= e($x['autores'] ?: 'Autor não informado') ?></small></td>
                            <td data-label="Cadastro" class="adm-td-sec"><?= e((string)$x['cadastrado_por_nome']) ?><small class="adm-block-muted"><?= e(admin_data_hora($x['criado_em'])) ?></small></td>
                            <td class="adm-table__actions adm-actions-col"><button class="adm-button adm-button--ghost adm-button--sm" type="submit" name="unico" value="carimbar:<?= (int)$x['id'] ?>" formnovalidate><?= icon('check') ?> Carimbado</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <label class="adm-check adm-aviso-seguranca"><input type="checkbox" name="confirmo" value="1"> <span><strong>Conferi o código escrito em cada livro selecionado.</strong> Obrigatório para confirmar vários de uma vez.</span></label>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check-check') ?> Marcar selecionados como carimbados</button>
        </form>
    <?php elseif (!$pendCarimbo): ?>
        <?= admin_aviso('sucesso', 'Todos os exemplares estão carimbados.', 'Confirme a carimbagem do lote para passar à organização nas estantes.') ?>
    <?php endif; ?>
    <?php if ($podeExemplares): ?>
        <form method="post" action="<?= e($base) ?>" class="adm-form-actions adm-form-actions--static" data-enviar-uma-vez data-confirm="Confirmar a carimbagem do <?= e($nome) ?>? O lote passa para “Aguardando organização nas estantes”.">
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="confirmar_carimbagem"><input type="hidden" name="chave" value="<?= e(OperacaoService::novaChave()) ?>">
            <button class="adm-button adm-button--primary" type="submit"<?= $pendCarimbo || $resumo['duvidas_bloqueantes'] ? ' aria-describedby="carimbagem-pendente"' : '' ?>><?= icon('stamp') ?> Confirmar carimbagem do lote</button>
            <?php if ($pendCarimbo): ?><p class="adm-field__hint" id="carimbagem-pendente">Só conclui a etapa quando todos estiverem carimbados. Pendentes: <?= e(implode(', ', array_slice(array_column($pendCarimbo, 'codigo_individual'), 0, 20))) ?><?= count($pendCarimbo) > 20 ? ' e mais ' . (count($pendCarimbo) - 20) : '' ?>.</p>
            <?php elseif ($resumo['duvidas_bloqueantes']): ?><p class="adm-field__hint" id="carimbagem-pendente">Há <?= $resumo['duvidas_bloqueantes'] ?> dúvida(s) que comprometem a identificação física em aberto. Resolva-as antes de confirmar.</p><?php endif; ?>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($st === 'aguardando_organizacao'): ?>
<section class="adm-card adm-panel adm-no-print" aria-labelledby="titulo-organizacao" id="organizacao">
    <div class="adm-panel__header"><div><h2 id="titulo-organizacao"><?= icon('map-pin') ?> Etapa atual: organização nas estantes</h2>
        <p>Escolha a estante e a prateleira, selecione os exemplares que vão para esse lugar e confirme. <?= count($pendOrganizacao) ?> exemplar(es) ainda sem estante definitiva.</p></div></div>
    <?php if ($pendOrganizacao && $podeExemplares): ?>
        <form method="post" action="<?= e($base) ?>" data-enviar-uma-vez>
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="organizar"><input type="hidden" name="chave" value="<?= e($chave) ?>">
            <fieldset class="adm-field"><legend class="adm-field__label">Estante e prateleira</legend>
                <?= fisico_campo_local('localizacao_id', $sugestao['id'], 'Escolha a prateleira', 'org-lote', array_values(array_filter(organizacao_repo()->opcoesLocal($sugestao['id']), fn ($o) => $o['estante_id'] !== null))) ?>
                <?= fisico_sugestao_html($sugestao, $sugestao['id']) ?></fieldset>
            <div class="adm-table-wrap">
                <table class="adm-table adm-table--cards">
                    <thead><tr><th scope="col"><label class="adm-check"><input type="checkbox" data-marcar-todos="ids[]"> <span class="adm-visually-hidden">Selecionar todos</span></label></th><th scope="col">Ordem</th><th scope="col">Código BDP</th><th scope="col">Obra</th><th scope="col">Carimbado por</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendOrganizacao as $x): ?>
                        <tr>
                            <td class="adm-check-col"><label class="adm-check"><input type="checkbox" name="ids[]" value="<?= (int)$x['id'] ?>"> <span class="adm-visually-hidden">Selecionar <?= e($x['codigo_individual']) ?></span></label></td>
                            <td data-label="Ordem"><?= (int)$x['lote_ordem'] ?></td>
                            <td data-label="Código BDP"><span class="adm-code"><?= e($x['codigo_individual']) ?></span></td>
                            <td class="adm-td-title"><?= e($x['titulo'] ?? 'Sem título') ?><small class="adm-block-muted"><?= e($x['autores'] ?: 'Autor não informado') ?></small></td>
                            <td data-label="Carimbado por" class="adm-td-sec"><?= e((string)$x['carimbado_por_nome']) ?><small class="adm-block-muted"><?= e(admin_data_hora($x['carimbado_em'])) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('map-pin') ?> Guardar selecionados nesta prateleira</button>
        </form>
    <?php elseif (!$pendOrganizacao): ?>
        <?= admin_aviso('sucesso', 'Todos os exemplares estão carimbados e guardados nas estantes.', $podeGerenciar ? 'Confira e encerre o lote.' : 'Um administrador pode encerrar o lote.') ?>
    <?php endif; ?>
    <?php if ($podeGerenciar): ?>
        <form method="post" action="<?= e($base) ?>" class="adm-form-actions adm-form-actions--static" data-enviar-uma-vez data-confirm="Encerrar o <?= e($nome) ?>? Ele continua no Histórico de Lotes, com a planilha e todo o histórico, mas não recebe novos exemplares.">
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="encerrar"><input type="hidden" name="chave" value="<?= e(OperacaoService::novaChave()) ?>">
            <?php if (!$pendOrganizacao && !$pendCarimbo && !$resumo['duvidas_bloqueantes']): ?>
                <button class="adm-button adm-button--primary" type="submit"><?= icon('archive') ?> Encerrar lote</button>
            <?php else: ?>
                <p class="adm-field__hint">O botão “Encerrar lote” aparece quando todos os exemplares estiverem carimbados e guardados<?= $resumo['duvidas_bloqueantes'] ? ' e as dúvidas que comprometem a identificação forem resolvidas' : '' ?>.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($st === 'encerrado'): ?>
    <?= admin_aviso('sucesso', $nome . ' encerrado em ' . admin_data_hora($lote['encerrado_em']) . ($lote['encerrado_por_nome'] ? ' por ' . $lote['encerrado_por_nome'] : '') . '.', 'Os exemplares, os códigos BDP e todo o histórico ficam preservados. Para mudar um exemplar de lugar, use “Mover exemplar”: a movimentação não apaga quem o organizou originalmente.') ?>
<?php endif; ?>

<?php // ---------- Exemplares ---------- ?>
<section class="adm-card adm-panel" aria-labelledby="titulo-exemplares">
    <div class="adm-panel__header"><div><h2 id="titulo-exemplares"><?= icon('book-copy') ?> Exemplares</h2><p><?= fisico_numero(count($exemplares)) ?> exemplar(es) na ordem de cadastro<?= $resumo['baixados'] ? ' · ' . $resumo['baixados'] . ' baixado(s) não aparecem' : '' ?></p></div></div>
    <div class="adm-table-wrap">
        <table class="adm-table adm-table--cards">
            <thead><tr><th scope="col">Ordem</th><th scope="col">Código BDP</th><th scope="col">Título</th><th scope="col">Autor</th><th scope="col">Cadastrado por</th><th scope="col">Carimbagem</th><th scope="col">Localização definitiva</th><th scope="col">Organização</th></tr></thead>
            <tbody>
            <?php foreach ($exemplares as $x): ?>
                <tr>
                    <td data-label="Ordem"><?= (int)$x['lote_ordem'] ?></td>
                    <td data-label="Código BDP"><a class="adm-link adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$x['id']) ?>"><?= e($x['codigo_individual']) ?></a>
                        <?php if ((int)$x['duvidas_abertas']): ?><span class="adm-status adm-tone--amber"><?= icon('circle-help') ?> <?= (int)$x['duvidas_abertas'] ?> dúvida(s)</span><?php endif; ?>
                        <?php if ((int)$x['ocorrencias_abertas']): ?><span class="adm-status adm-tone--red"><?= icon('triangle-alert') ?> ocorrência</span><?php endif; ?></td>
                    <td class="adm-td-title"><?= e($x['titulo'] ?? 'Sem título') ?></td>
                    <td data-label="Autor"><?= e($x['autores'] ?: '—') ?></td>
                    <td data-label="Cadastrado por" class="adm-td-sec"><?= e((string)$x['cadastrado_por_nome']) ?><small class="adm-block-muted"><?= e(admin_data_hora($x['criado_em'])) ?></small></td>
                    <td data-label="Carimbagem"><?= fisico_badge_carimbo($x['carimbo_status']) ?><?= $x['carimbado_por_nome'] ? '<small class="adm-block-muted">' . e($x['carimbado_por_nome']) . ' · ' . e(admin_data_hora($x['carimbado_em'])) . '</small>' : '' ?></td>
                    <td data-label="Localização definitiva"><?= e(fisico_local_ou_triagem($x)) ?></td>
                    <td data-label="Organização"><?= $x['localizacao_id'] !== null ? '<span class="adm-status adm-tone--green">Organizado</span>' : '<span class="adm-status adm-tone--amber">Aguardando organização</span>' ?>
                        <?= $x['organizado_por_nome'] ? '<small class="adm-block-muted">' . e($x['organizado_por_nome']) . ' · ' . e(admin_data_hora($x['organizado_em'])) . '</small>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$exemplares): ?><tr><td colspan="8"><div class="adm-empty"><?= icon('book-copy') ?><strong>Nenhum exemplar neste lote ainda.</strong></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php // ---------- Produtividade ---------- ?>
<?php if ($podeGerenciar): ?>
<section class="adm-card adm-panel" aria-labelledby="titulo-produtividade">
    <h2 id="titulo-produtividade" class="adm-panel__title"><?= icon('chart-column') ?> Relatório de Produtividade do lote</h2>
    <p class="adm-field__hint">Calculado dos registros reais. Quem cadastrou, quem carimbou e quem organizou são contados separadamente; cada exemplar conta uma vez. Não é um ranking.</p>
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th scope="col">Colaborador</th><th scope="col" class="adm-num">Cadastrados</th><th scope="col" class="adm-num">Carimbados</th><th scope="col" class="adm-num">Organizados</th><th scope="col" class="adm-num">Obras verificadas ou corrigidas</th></tr></thead>
            <tbody>
            <?php $tot = ['cadastrados' => 0, 'carimbados' => 0, 'organizados' => 0]; ?>
            <?php foreach ($produtividade as $p): ?>
                <?php foreach ($tot as $k => $v) { $tot[$k] += $p[$k]; } ?>
                <tr><td><?= e($p['nome']) ?></td><td class="adm-num"><?= fisico_numero($p['cadastrados']) ?></td><td class="adm-num"><?= fisico_numero($p['carimbados']) ?></td><td class="adm-num"><?= fisico_numero($p['organizados']) ?></td><td class="adm-num"><?= fisico_numero($p['obras_verificadas']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th scope="row">Total</th><td class="adm-num"><strong><?= fisico_numero($tot['cadastrados']) ?></strong></td><td class="adm-num"><strong><?= fisico_numero($tot['carimbados']) ?></strong></td><td class="adm-num"><strong><?= fisico_numero($tot['organizados']) ?></strong></td>
                <td class="adm-num"><?= fisico_numero($resumo['obras_verificadas']) ?> verificada(s) · <?= fisico_numero($resumo['obras_corrigidas']) ?> corrigida(s)</td></tr></tfoot>
        </table>
    </div>
    <h3 class="adm-subtitulo">Tempo de cada etapa</h3>
    <dl class="adm-meta adm-meta--grid">
        <div><dt>Abertura até fechamento do cadastro</dt><dd><?= e(LoteRepository::duracaoTexto($tempos['cadastro']['segundos'])) ?><?= $tempos['cadastro']['em_andamento'] ? ' (em andamento)' : '' ?></dd></div>
        <div><dt>Fechamento até conclusão da carimbagem</dt><dd><?= e(LoteRepository::duracaoTexto($tempos['carimbagem']['segundos'])) ?><?= $tempos['carimbagem']['em_andamento'] ? ' (em andamento)' : '' ?></dd></div>
        <div><dt>Carimbagem até organização definitiva</dt><dd><?= e(LoteRepository::duracaoTexto($tempos['organizacao']['segundos'])) ?><?= $tempos['organizacao']['em_andamento'] ? ' (em andamento)' : '' ?></dd></div>
        <div><dt>Total entre abertura e encerramento</dt><dd><?= e(LoteRepository::duracaoTexto($tempos['total']['segundos'])) ?><?= $tempos['total']['em_andamento'] ? ' (em andamento)' : '' ?></dd></div>
    </dl>
    <p class="adm-field__hint">Esses períodos mostram a duração das etapas, não as horas efetivamente trabalhadas por cada pessoa.</p>
</section>
<?php endif; ?>

<?php // ---------- Dúvidas ---------- ?>
<section class="adm-card adm-panel" aria-labelledby="titulo-duvidas">
    <div class="adm-panel__header"><div><h2 id="titulo-duvidas"><?= icon('circle-help') ?> Dúvidas e ocorrências</h2><p><?= count($duvidas) ?> dúvida(s) registrada(s) sobre exemplares deste lote · <?= $resumo['ocorrencias_abertas'] ?> ocorrência(s) física(s) aberta(s)</p></div>
        <a class="adm-button adm-button--ghost adm-button--sm adm-no-print" href="<?= e(admin_url('duvidas') . '?lote=' . $id) ?>"><?= icon('circle-help') ?> Abrir dúvidas do lote</a></div>
    <?php if ($duvidas): ?>
        <ul class="adm-ocorrencias">
            <?php foreach ($duvidas as $d): ?>
                <li class="adm-ocorrencia is-<?= $d['status'] === 'aberta' ? 'aberta' : 'resolvida' ?>">
                    <div class="adm-ocorrencia__topo"><strong><?= e(DuvidaService::TIPOS[$d['tipo']] ?? $d['tipo']) ?></strong>
                        <span class="adm-status adm-tone--<?= $d['status'] === 'aberta' ? 'amber' : 'green' ?>"><?= $d['status'] === 'aberta' ? 'Aberta' : 'Resolvida' ?></span>
                        <?= (int)$d['compromete_identificacao'] ? '<span class="adm-status adm-tone--red">Compromete a identificação física</span>' : '' ?></div>
                    <p><?= $d['codigo_individual'] ? '<span class="adm-code">' . e($d['codigo_individual']) . '</span> · ' : '' ?><?= e($d['titulo'] ?? 'Sem título') ?></p>
                    <?php if ($d['descricao']): ?><p class="adm-ocorrencia__desc"><?= e($d['descricao']) ?></p><?php endif; ?>
                    <p class="adm-table__muted">Nº <?= (int)$d['id'] ?> · <?= e(admin_data_hora($d['criado_em'])) ?> por <?= e((string)$d['registrada_por_nome']) ?><?= $d['resolvida_em'] ? ' · resolvida em ' . e(admin_data_hora($d['resolvida_em'])) . ' por ' . e((string)$d['resolvida_por_nome']) : '' ?></p>
                    <?php if ($d['resolucao']): ?><p class="adm-ocorrencia__desc"><strong>Resolução:</strong> <?= e($d['resolucao']) ?></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="adm-field__hint">Nenhuma dúvida registrada sobre os exemplares deste lote.</p>
    <?php endif; ?>
</section>

<?php // ---------- Participantes ---------- ?>
<section class="adm-card adm-panel" aria-labelledby="titulo-participantes" id="participantes">
    <h2 id="titulo-participantes" class="adm-panel__title"><?= icon('users') ?> Usuários do lote</h2>
    <ul class="adm-lista-simples">
        <?php foreach ($participantes as $p): ?>
            <li><strong><?= e($p['nome']) ?></strong> <span class="adm-table__muted">· atribuído em <?= e(admin_data_hora($p['atribuido_em'])) ?><?= $p['atribuido_por_nome'] ? ' por ' . e($p['atribuido_por_nome']) : '' ?>
                <?= $p['removido_em'] ? ' · removido em ' . e(admin_data_hora($p['removido_em'])) . ($p['removido_por_nome'] ? ' por ' . e($p['removido_por_nome']) : '') : '' ?></span>
                <?php if ($podeGerenciar && $p['removido_em'] === null && $st !== 'encerrado'): ?>
                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form adm-no-print" data-confirm="Remover <?= e($p['nome']) ?> do <?= e($nome) ?>? O que já foi registrado continua no lote.">
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="remover"><input type="hidden" name="usuario_id" value="<?= (int)$p['administrador_id'] ?>">
                        <button class="adm-link-button" type="submit">Remover do lote</button>
                    </form>
                <?php endif; ?></li>
        <?php endforeach; ?>
        <?php if (!$participantes): ?><li class="adm-table__muted">Nenhum usuário atribuído.</li><?php endif; ?>
    </ul>
    <?php if ($podeGerenciar && $st !== 'encerrado'): ?>
        <?php $fora = array_values(array_filter($atribuiveis, fn ($a) => !in_array((int)$a['id'], $participaIds, true))); ?>
        <?php if ($fora): ?>
            <form method="post" action="<?= e($base) ?>" class="adm-no-print" data-enviar-uma-vez>
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="atribuir">
                <fieldset class="adm-field"><legend class="adm-field__sublabel">Atribuir usuários</legend><div class="adm-lote-usuarios">
                    <?php foreach ($fora as $a): ?><label class="adm-check"><input type="checkbox" name="usuarios[]" value="<?= (int)$a['id'] ?>"> <span><?= e($a['nome']) ?></span></label><?php endforeach; ?>
                </div></fieldset>
                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('user-plus') ?> Atribuir ao lote</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php // ---------- Correção administrativa ---------- ?>
<?php if ($podeGerenciar): ?>
<details class="adm-card adm-panel adm-no-print" id="correcao">
    <summary class="adm-panel__title"><?= icon('pencil') ?> Correção administrativa</summary>
    <p class="adm-field__hint">Fica no histórico com a justificativa, quem corrigiu e quando. Não apaga o fechamento nem o encerramento original. Para corrigir um exemplar (dados, carimbo, localização), use a página do exemplar.</p>
    <form method="post" action="<?= e($base) ?>" data-enviar-uma-vez>
        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="corrigir">
        <div class="adm-form-grid">
            <label class="adm-field"><span class="adm-field__sublabel">Nome ou observação</span><input class="adm-input" name="descricao" maxlength="255" value="<?= e((string)$lote['descricao']) ?>"></label>
            <?php if ($st === 'aberto'): ?><label class="adm-field"><span class="adm-field__sublabel">Capacidade</span><input class="adm-input" type="number" name="capacidade" min="<?= max(1, (int)$lote['total']) ?>" max="500" value="<?= e((string)$lote['capacidade']) ?>"></label><?php endif; ?>
            <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Justificativa *</span><input class="adm-input" name="justificativa" required minlength="5" maxlength="500" placeholder="Ex.: capacidade aumentada porque a estante tem mais livros do que o previsto"></label>
        </div>
        <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Registrar correção</button>
    </form>
</details>
<?php endif; ?>

<?php // ---------- Histórico ---------- ?>
<details class="adm-card adm-panel" id="historico"<?= $imprimir ? ' open' : '' ?>>
    <summary class="adm-panel__title"><?= icon('history') ?> Histórico do lote (<?= count($eventos) ?> registro(s))</summary>
    <ol class="adm-recentes adm-lote-eventos">
        <?php foreach ($eventos as $ev): ?>
            <li><span class="adm-table__muted"><?= e(admin_data_hora($ev['criado_em'])) ?></span> · <?= e($ev['descricao']) ?><?= $ev['administrador_nome'] ? ' <small class="adm-table__muted">— ' . e($ev['administrador_nome']) . '</small>' : '' ?></li>
        <?php endforeach; ?>
    </ol>
</details>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/lotes.js?v=20261115']));
