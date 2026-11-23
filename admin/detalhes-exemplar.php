<?php
/**
 * Detalhes de um exemplar físico: dados, localização confirmada (e a antiga da planilha, só como
 * referência), histórico, conferências de inventário e ações (conservação, transferência,
 * situação, extravio e baixa). Aceita ?id= ou ?codigo= (ex.: BDP-000001, digitado pela bibliotecária).
 *
 * Ficha individual completa (migração 190): obra e dados bibliográficos atuais (lidos da obra), entrada
 * no acervo e aquisição, lote, quem cadastrou, carimbagem, localização atual e histórico de localizações,
 * conservação, ocorrências, resumo dos empréstimos, inventários e situação atual. Baixa administrativa
 * com motivo padronizado e "Corrigir a obra deste exemplar" (só esta cópia, mesmo código BDP).
 * Sem banco configurado, mostra a versão demonstrativa (includes/demo/detalhes-exemplar.php).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ComparacaoPlanilhaService;

if (!acervo_usa_mysql()) {
    require __DIR__ . '/includes/demo/detalhes-exemplar.php';
    return;
}
$usuario = fisico_iniciar();

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$erro = null;
try {
    if (!$id && admin_param($_GET, 'codigo') !== '') {
        $ex = exemplares_repo()->porCodigo(admin_param($_GET, 'codigo'));
        $id = $ex ? (int)$ex['id'] : 0;
    }
    $url = admin_url('detalhes-exemplar') . '?id=' . $id;

    if ($id && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        $acao = admin_param($_POST, 'acao');
        $servico = exemplares_servico();
        try {
            switch ($acao) {
                case 'editar':
                    fisico_exigir('exemplares.gerenciar');
                    $servico->editar($id, ['estado_conservacao' => admin_param($_POST, 'estado_conservacao'), 'observacoes' => admin_param($_POST, 'observacoes')], $usuario['id']);
                    admin_flash('sucesso', 'Dados do exemplar atualizados.');
                    break;
                case 'transferir':
                    fisico_exigir('exemplares.gerenciar');
                    // Capacidade estimada (migração 190): só avisa, não impede.
                    $destinoLocal = (int)admin_param($_POST, 'localizacao_id') ?: null;
                    $avisoCap = $destinoLocal && (int)(exemplares_repo()->buscar($id)['localizacao_id'] ?? 0) !== $destinoLocal ? organizacao_servico()->avisoCapacidade($destinoLocal, 1) : null;
                    $op = operacoes_servico()->realizar('localizacao', 'Localização alterada no exemplar nº ' . $id, $usuario['id'],
                        fn () => $servico->transferir($id, (int)admin_param($_POST, 'localizacao_id') ?: null, $usuario['id'], admin_param($_POST, 'motivo')));
                    admin_flash('sucesso', 'Localização atualizada.', 'Só este exemplar mudou de lugar; os outros exemplares do livro continuam onde estão.' . ($op['total'] ? ' Errou? Use “Desfazer” em Ações recentes.' : ''));
                    if ($avisoCap) {
                        admin_flash('aviso', 'Prateleira possivelmente lotada.', $avisoCap);
                    }
                    break;
                case 'carimbar':
                    fisico_exigir('exemplares.gerenciar');
                    operacoes_servico()->realizar('carimbo', 'Carimbo confirmado no exemplar nº ' . $id, $usuario['id'],
                        fn () => $servico->marcarCarimbado([$id], $usuario['id'], admin_param($_POST, 'observacao')));
                    admin_flash('sucesso', 'Exemplar marcado como carimbado.', 'Ele saiu da lista “Aguardando carimbo” da Planilha de Códigos BDP.');
                    break;
                case 'situacao':
                    fisico_exigir('exemplares.gerenciar');
                    $nova = admin_param($_POST, 'situacao');
                    $atual = exemplares_repo()->buscar($id);
                    if ($atual && $atual['situacao'] === 'extraviado') {
                        fisico_exigir('exemplares.baixar'); // devolver um extraviado ao acervo é decisão de quem registra extravios
                    }
                    $servico->alterarSituacao($id, $nova, $usuario['id'], admin_param($_POST, 'motivo'));
                    admin_flash('sucesso', 'Situação atualizada para "' . (ACERVO_SITUACOES[$nova]['label'] ?? $nova) . '".');
                    break;
                case 'extravio':
                    fisico_exigir('exemplares.baixar');
                    if (admin_param($_POST, 'confirmar') !== '1') {
                        throw new AcervoException('Marque a confirmação para registrar o extravio.');
                    }
                    $servico->registrarExtravio($id, $usuario['id'], admin_param($_POST, 'motivo'));
                    admin_flash('sucesso', 'Extravio registrado.', 'Se o exemplar for encontrado, mude a situação para "Disponível".');
                    break;
                case 'baixa':
                    fisico_exigir('exemplares.baixar');
                    if (admin_param($_POST, 'confirmar') !== '1') {
                        throw new AcervoException('Marque a confirmação para dar baixa.');
                    }
                    $tipoBaixa = admin_param($_POST, 'baixa_tipo');
                    if (!isset(BAIXA_TIPOS[$tipoBaixa])) {
                        throw new AcervoException('Escolha o motivo da baixa.');
                    }
                    $justificativa = trim(admin_param($_POST, 'justificativa'));
                    $servico->baixar($id, $usuario['id'], BAIXA_TIPOS[$tipoBaixa] . ($justificativa !== '' ? ': ' . $justificativa : ''), $tipoBaixa, admin_param($_POST, 'observacao'));
                    admin_flash('sucesso', 'Baixa registrada.', 'O exemplar saiu do acervo ativo e da disponibilidade para empréstimo. O cadastro, o código e o histórico continuam guardados e o código não será reaproveitado.');
                    break;
                case 'corrigir_obra':
                    // Esta cópia é de outra edição: só ela muda de obra (mesmo código BDP, lote, carimbo, histórico).
                    fisico_exigir('exemplares.gerenciar');
                    fisico_exigir('livros.editar');
                    $destino = (int)admin_param($_POST, 'destino');
                    $r = (new ComparacaoPlanilhaService(db()))->corrigirObraExemplar($id, $destino, $usuario['id'], admin_param($_POST, 'confirmo') === '1', admin_param($_POST, 'motivo'));
                    admin_flash('sucesso', 'Obra do exemplar ' . $r['exemplar']['codigo_individual'] . ' corrigida.', 'Só esta cópia passou para a obra nº ' . $destino . '. O código BDP, o lote, o carimbo, a localização, a conservação, as ocorrências, os empréstimos e o histórico continuam os mesmos.');
                    break;
                default:
                    throw new AcervoException('Ação desconhecida.');
            }
            admin_redirecionar($url);
        } catch (AcervoException $e) {
            $erro = $e->getMessage();
        } catch (PDOException $e) {
            $erro = fisico_erro_tecnico($e, 'exemplar ' . $acao);
        }
    }
    $ex = $id ? exemplares_repo()->buscar($id) : null;
    if ($ex) {
        $historico = exemplares_repo()->historico($id);
        $conferencias = exemplares_repo()->conferencias($id);
        $planilha = exemplares_repo()->dadosPlanilha((int)$ex['livro_id']);
        $locais = localizacoes_servico()->ativas($ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null);
        $ocorrencias = preparo_repo()->ocorrenciasDoExemplar($id);
        $loteEx = $ex['lote_id'] !== null ? preparo_repo()->lote((int)$ex['lote_id']) : null;
        // Ciclo de vida (migração 190): entrada, aquisição, baixa, localizações e empréstimos (só números).
        $vida = db_query('SELECT e.data_entrada, e.aquisicao_id, e.baixa_tipo, e.baixa_observacao, e.organizado_em, bp.nome AS baixado_por_nome,
                                 aq.tipo AS aquisicao_tipo, aq.origem AS aquisicao_origem, aq.data_recebimento AS aquisicao_data, aq.documento AS aquisicao_documento,
                                 (SELECT COUNT(*) FROM emprestimos m WHERE m.exemplar_id = e.id) AS emprestimos_total,
                                 (SELECT MAX(m.data_emprestimo) FROM emprestimos m WHERE m.exemplar_id = e.id) AS ultimo_emprestimo,
                                 (SELECT COUNT(*) FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL AND m.data_prevista < CURDATE()) AS emprestimo_atrasado
                          FROM exemplares e LEFT JOIN administradores bp ON bp.id = e.baixado_por LEFT JOIN aquisicoes aq ON aq.id = e.aquisicao_id
                          WHERE e.id = ?', [$id])->fetch() ?: [];
        $locaisHistorico = array_values(array_filter($historico, fn ($m) => $m['tipo'] === 'localizacao'));
        $correcoesObra = db_query('SELECT c.*, la.titulo AS anterior_titulo, ln.titulo AS nova_titulo, a.nome AS administrador_nome FROM exemplar_obra_correcoes c
                                   JOIN livros la ON la.id = c.livro_anterior_id JOIN livros ln ON ln.id = c.livro_novo_id LEFT JOIN administradores a ON a.id = c.administrador_id
                                   WHERE c.exemplar_id = ? ORDER BY c.id DESC', [$id])->fetchAll();
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o exemplar', fisico_erro_tecnico($e, 'detalhes do exemplar'));
    exit;
}
if (!$ex) {
    http_response_code(404);
    admin_pagina_erro('Exemplar não encontrado', 'Nenhum exemplar com este código foi cadastrado. Livros sem exemplar físico não têm código BDP: cadastre o exemplar na página do livro ou na conferência do inventário.', 'book-x');
    exit;
}

$emprestado = $ex['situacao'] === 'emprestado' || $ex['emprestimo_aberto'] !== null;
$baixado = $ex['situacao'] === 'baixado';
$podeGerenciar = auth_pode('exemplares.gerenciar');
$podeBaixar = auth_pode('exemplares.baixar');
$tipos = ACERVO_MOVIMENTOS;

admin_page_start('exemplares', $ex['codigo_individual'], 'Exemplares', 'Unidades físicas do acervo', array_merge(FISICO_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], [$ex['codigo_individual']]]) ?>

<form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e(admin_url('detalhes-exemplar')) ?>" role="search">
    <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Abrir a ficha de outro exemplar pelo código BDP</span>
        <input type="search" name="codigo" placeholder="Abrir outra ficha pelo código BDP (ex.: BDP-000123)" autocomplete="off" spellcheck="false"></label>
    <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('arrow-right') ?> Abrir ficha</button>
</form>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Ficha do exemplar</p>
        <h1 class="adm-mono"><?= e($ex['codigo_individual']) ?></h1>
        <p><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$ex['livro_id']) ?>"><?= e($ex['titulo']) ?> <?= icon('arrow-right') ?></a></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('exemplares')) ?>"><?= icon('arrow-left') ?> Exemplares</a>
        <?php if (auth_pode('emprestimos.ver')): ?>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('historico-exemplar') . '?id=' . $id) ?>"><?= icon('history') ?> Empréstimos deste exemplar</a>
        <?php endif; ?>
        <?php if (auth_pode('etiquetas.gerar') && !$baixado): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('etiquetas') . '?ids=' . $id) ?>"><?= icon('printer') ?> Imprimir etiqueta</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($erro): ?><?= admin_aviso('erro', 'Nada foi alterado.', $erro) ?><?php endif; ?>

<?php if ($emprestado): ?>
    <div class="adm-notice adm-notice--indigo" role="note"><?= icon('book-open-check') ?><div><strong>Este exemplar está emprestado.</strong><p>A situação, a baixa e o extravio ficam bloqueados até a devolução ser registrada no módulo de empréstimos.</p></div></div>
<?php elseif ($baixado): ?>
    <div class="adm-notice adm-notice--red" role="note"><?= icon('package-x') ?><div><strong>Exemplar baixado em <?= admin_data_hora($ex['baixado_em']) ?><?= !empty($vida['baixado_por_nome']) ? ' por ' . e($vida['baixado_por_nome']) : '' ?>.</strong><p><?= e($ex['motivo_baixa'] ?? '') ?><?= !empty($vida['baixa_observacao']) ? ' · ' . e($vida['baixa_observacao']) : '' ?> O cadastro, o histórico e o código continuam guardados; o código não será reaproveitado.</p></div></div>
<?php endif; ?>

<div class="adm-columns">
    <div class="adm-columns__main">
        <section class="adm-card adm-panel" aria-labelledby="titulo-dados">
            <div class="adm-panel__header"><div><h2 id="titulo-dados">Informações do exemplar</h2><p>Dados desta unidade física. Os dados da obra ficam no cadastro do livro.</p></div></div>
            <dl class="adm-meta adm-meta--two">
                <div><dt>Código</dt><dd class="adm-mono"><?= e($ex['codigo_individual']) ?></dd></div>
                <div><dt>Identificador interno</dt><dd>nº <?= (int)$ex['id'] ?></dd></div>
                <div><dt>Livro</dt><dd><a class="adm-book-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$ex['livro_id']) ?>"><?= e($ex['titulo']) ?></a></dd></div>
                <div><dt>Autor</dt><dd><?= e($ex['autores'] ?: 'Não informado') ?></dd></div>
                <div><dt>ISBN da edição</dt><dd class="adm-mono"><?= $ex['isbn'] ? e($ex['isbn']) : '<span class="adm-missing">Não informado</span>' ?></dd></div>
                <div><dt>Classificação CDD</dt><dd><?= $ex['cdd_aprovada'] ? '<span class="adm-mono">' . e($ex['cdd_aprovada']) . '</span> (aprovada)' : '<span class="adm-missing">Pendente — a reclassificação é feita no módulo CDD</span>' ?></dd></div>
                <div><dt>Situação</dt><dd><?= acervo_badge_situacao($ex['situacao']) ?><?= (int)$ex['liberacao_pendente'] ? ' <small class="adm-table__muted">aguardando liberação (carimbo ou estante definitiva)</small>' : '' ?></dd></div>
                <div><dt>Preparo físico</dt><dd><?= fisico_selos_preparo($ex, count(array_filter($ocorrencias, fn ($o) => $o['status'] === 'aberta'))) ?></dd></div>
                <div><dt>Carimbo</dt><dd><?= fisico_badge_carimbo($ex['carimbo_status']) ?><?= $ex['carimbado_em'] ? ' em ' . admin_data_hora($ex['carimbado_em']) : '' ?><?= $ex['carimbo_observacao'] ? ' · ' . e($ex['carimbo_observacao']) : '' ?></dd></div>
                <div><dt>Lote de cadastramento</dt><dd><?= $loteEx ? '<a class="adm-link" href="' . e(admin_url('planilha-bdp') . '?' . http_build_query(['aba' => 'todos', 'lote' => (int)$loteEx['id']])) . '">' . e(Biblioteca\Repositories\PreparoRepository::rotuloLote($loteEx)) . '</a> · nº ' . (int)$ex['lote_ordem'] . ' na ordem de registro' : '<span class="adm-missing">Sem lote (cadastro anterior)</span>' ?></dd></div>
                <div><dt>Estado de conservação</dt><dd><?= acervo_badge_conservacao($ex['estado_conservacao']) ?></dd></div>
                <div><dt>Localização física confirmada</dt><dd><?= $ex['localizacao_id'] ? e(fisico_local($ex)) . ($ex['local_codigo'] ? ' <span class="adm-mono">(' . e($ex['local_codigo']) . ')</span>' : '') : '<span class="adm-missing">Aguardando organização (área de triagem)</span>' ?></dd></div>
                <div><dt>Localização antiga (planilha)</dt><dd><?= $planilha['localizacoes'] ? e($planilha['localizacoes']) . ' <small class="adm-table__muted">— histórica, não confirmada</small>' : '<span class="adm-missing">Não informada</span>' ?></dd></div>
                <div><dt>Cadastrado em</dt><dd><?= admin_data_hora($ex['criado_em']) ?><?= $ex['cadastrado_por_nome'] ? ' por ' . e($ex['cadastrado_por_nome']) : '' ?></dd></div>
                <div><dt>Entrada no acervo</dt><dd><?= !empty($vida['data_entrada']) ? e(date('d/m/Y', strtotime($vida['data_entrada']))) : e(date('d/m/Y', strtotime($ex['criado_em']))) . ' <small class="adm-table__muted">(data do cadastro)</small>' ?></dd></div>
                <div><dt>Origem da aquisição</dt><dd><?= !empty($vida['aquisicao_id']) ? '<a class="adm-link" href="' . e(admin_url('aquisicoes') . '?id=' . (int)$vida['aquisicao_id']) . '">' . e((AQUISICAO_TIPOS[$vida['aquisicao_tipo']] ?? $vida['aquisicao_tipo']) . ' · ' . $vida['aquisicao_origem']) . '</a> · ' . e(date('d/m/Y', strtotime($vida['aquisicao_data']))) . ($vida['aquisicao_documento'] ? ' · ' . e($vida['aquisicao_documento']) : '') : '<span class="adm-missing">Acervo existente (inventário inicial)</span>' ?></dd></div>
                <div><dt>Empréstimos</dt><dd><?= (int)($vida['emprestimos_total'] ?? 0) ?> no total<?= !empty($vida['ultimo_emprestimo']) ? ' · último em ' . admin_data_hora($vida['ultimo_emprestimo']) : '' ?><?= !empty($vida['emprestimo_atrasado']) ? ' · <span class="adm-status adm-tone--red">em atraso</span>' : '' ?></dd></div>
                <div><dt>Origem do cadastro</dt><dd><?= $ex['inventario_id'] ? 'Conferência do <a class="adm-link" href="' . e(admin_url('inventario-detalhes') . '?id=' . (int)$ex['inventario_id']) . '">inventário nº ' . (int)$ex['inventario_id'] . '</a>' : 'Cadastro manual' ?></dd></div>
                <div><dt>Última conferência</dt><dd><?= $ex['conferido_em'] ? admin_data_hora($ex['conferido_em']) : '<span class="adm-missing">Ainda não conferido</span>' ?></dd></div>
                <div><dt>Etiqueta</dt><dd><?= $ex['etiqueta_gerada_em'] ? 'Gerada em ' . admin_data_hora($ex['etiqueta_gerada_em']) : '<span class="adm-missing">Ainda não gerada</span>' ?></dd></div>
                <div class="adm-meta__full"><dt>Observações</dt><dd><?= $ex['observacoes'] ? e($ex['observacoes']) : '<span class="adm-missing">Sem observações.</span>' ?></dd></div>
            </dl>
        </section>

        <section class="adm-card adm-panel" id="ocorrencias" aria-labelledby="titulo-ocorrencias">
            <div class="adm-panel__header"><div><h2 id="titulo-ocorrencias">Ocorrências e conservação</h2><p>Problemas físicos registrados neste exemplar. Nada é apagado: as resolvidas continuam no histórico.</p></div>
                <?php if ($podeGerenciar && !$baixado): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('ocorrencias') . '?' . http_build_query(['codigo' => $ex['codigo_individual'], 'voltar' => $url])) ?>#nova"><?= icon('triangle-alert') ?> Registrar ocorrência</a><?php endif; ?></div>
            <?php if (!$ocorrencias): ?>
                <p class="adm-field__hint">Nenhuma ocorrência registrada.</p>
            <?php else: ?>
                <ul class="adm-ocorrencias">
                    <?php foreach ($ocorrencias as $o): ?>
                        <li class="adm-ocorrencia is-<?= e($o['status']) ?>">
                            <div class="adm-ocorrencia__topo">
                                <strong><?= e(Biblioteca\Services\OcorrenciaService::TIPOS[$o['tipo']] ?? $o['tipo']) ?></strong>
                                <span class="adm-status adm-tone--<?= $o['status'] === 'aberta' ? 'amber' : ($o['status'] === 'resolvida' ? 'green' : 'muted') ?>"><?= e(Biblioteca\Services\OcorrenciaService::STATUS[$o['status']] ?? $o['status']) ?></span>
                            </div>
                            <?php if ($o['descricao']): ?><p class="adm-ocorrencia__desc"><?= e($o['descricao']) ?></p><?php endif; ?>
                            <p class="adm-table__muted"><?= (int)$o['bloqueia_emprestimo'] ? 'Deixou o exemplar indisponível para empréstimo' : 'Empréstimos permitidos' ?> · <?= admin_data_hora($o['criado_em']) ?><?= $o['registrada_por_nome'] ? ' por ' . e($o['registrada_por_nome']) : '' ?>
                                <?= $o['resolvida_em'] ? ' · resolvida em ' . admin_data_hora($o['resolvida_em']) . ($o['resolvida_por_nome'] ? ' por ' . e($o['resolvida_por_nome']) : '') : '' ?></p>
                            <?php if ($o['resolucao']): ?><p class="adm-ocorrencia__desc"><strong>O que foi feito:</strong> <?= e($o['resolucao']) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="adm-link" href="<?= e(admin_url('ocorrencias') . '?' . http_build_query(['q' => $ex['codigo_individual'], 'status' => 'todas'])) ?>"><?= icon('list') ?> Consultar, editar ou resolver em Ocorrências e Conservação</a>
            <?php endif; ?>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-locais">
            <div class="adm-panel__header"><div><h2 id="titulo-locais">Histórico de localizações</h2><p>Onde este exemplar esteve, da mudança mais recente para a mais antiga.<?= !empty($vida['organizado_em']) ? ' Organizado na estante definitiva pela primeira vez em ' . e(admin_data_hora($vida['organizado_em'])) . '.' : '' ?></p></div></div>
            <?php if (!$locaisHistorico): ?>
                <p class="adm-field__hint">Nenhuma mudança de localização registrada.</p>
            <?php else: ?>
                <ol class="adm-linha-tempo">
                    <?php foreach ($locaisHistorico as $m): ?><li><time><?= admin_data_hora($m['criado_em']) ?> · <?= e($m['responsavel'] ?? 'Sistema') ?></time><?= e($m['descricao']) ?></li><?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ($correcoesObra): ?>
                <h3 class="adm-subtitle">Correções da obra associada</h3>
                <ol class="adm-linha-tempo">
                    <?php foreach ($correcoesObra as $c): ?><li><time><?= admin_data_hora($c['criado_em']) ?> · <?= e($c['administrador_nome'] ?? 'Sistema') ?></time>De “<?= e($c['anterior_titulo']) ?>” para “<?= e($c['nova_titulo']) ?>” · <?= e($c['motivo']) ?></li><?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="adm-card adm-panel" id="historico" aria-labelledby="titulo-historico">
            <div class="adm-panel__header"><div><h2 id="titulo-historico">Histórico de movimentações</h2><p>Cadastro, localização, conservação, situação, conferências, empréstimos e baixa. Nada é apagado.</p></div></div>
            <?php if (!$historico): ?>
                <div class="adm-empty adm-empty--left"><?= icon('history') ?><strong>Sem movimentações registradas.</strong></div>
            <?php else: ?>
                <ol class="adm-timeline">
                    <?php foreach ($historico as $mov): ?>
                        <?php $tipo = $tipos[$mov['tipo']] ?? ['label' => $mov['tipo'], 'icone' => 'info', 'tom' => 'blue']; ?>
                        <li>
                            <span class="adm-icon-box adm-icon-box--xs adm-tone--<?= $tipo['tom'] ?>"><?= icon($tipo['icone']) ?></span>
                            <div>
                                <p><strong><?= e($tipo['label']) ?></strong></p>
                                <p class="adm-timeline__desc"><?= e($mov['descricao']) ?></p>
                                <small><?= admin_data_hora($mov['criado_em']) ?> · <?= e($mov['responsavel'] ?? 'Sistema') ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-conferencias">
            <div class="adm-panel__header"><div><h2 id="titulo-conferencias">Conferências de inventário</h2></div></div>
            <?php if (!$conferencias): ?>
                <p class="adm-missing">Este exemplar ainda não passou por nenhum inventário.</p>
            <?php else: ?>
                <ul class="adm-checks">
                    <?php foreach ($conferencias as $c): ?>
                        <li class="adm-check">
                            <div class="adm-check__top">
                                <a class="adm-link" href="<?= e(admin_url('inventario-detalhes') . '?id=' . (int)$c['inventario_id']) ?>"><strong><?= e($c['inventario_titulo']) ?></strong></a>
                                <?= fisico_badge_inventario($c['inventario_status']) ?>
                            </div>
                            <p><?= $c['conferido_em'] ? 'Conferido em ' . admin_data_hora($c['conferido_em']) . ($c['conferido_por_nome'] ? ' por ' . e($c['conferido_por_nome']) : '') : 'Previsto, ainda não conferido' ?>
                                <?= (int)$c['cadastrado_no_inventario'] ? ' · cadastrado nesta conferência' : '' ?><?= (int)$c['correcoes'] ? ' · ' . (int)$c['correcoes'] . ' correção(ões)' : '' ?></p>
                            <?php if ($c['decisao']): ?><small>Decisão: <?= e(Biblioteca\Services\InventarioService::DECISOES[$c['decisao']]) ?></small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <aside class="adm-columns__side">
        <section class="adm-card adm-panel" aria-labelledby="titulo-codigo">
            <div class="adm-panel__header"><div><h2 id="titulo-codigo">Código do exemplar</h2><p>É o código escrito na etiqueta. Para encontrar este exemplar, digite-o em Empréstimos, Devoluções ou Inventário.</p></div></div>
            <p class="adm-codigo-escrito"><?= e($ex['codigo_individual']) ?></p>
        </section>

        <?php if ($podeGerenciar && !$baixado): ?>
            <section class="adm-card adm-panel adm-acoes-exemplar" aria-labelledby="titulo-acoes">
                <div class="adm-panel__header"><div><h2 id="titulo-acoes">Ações</h2><p>Cada alteração fica registrada no histórico.</p></div></div>

                <?php if ($ex['carimbo_status'] === 'aguardando'): ?>
                    <details class="adm-acao" open>
                        <summary><?= icon('stamp') ?> Marcar como carimbado</summary>
                        <form method="post" data-enviar-uma-vez>
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="carimbar">
                            <p class="adm-field__hint">Confira se o carimbo do livro mostra exatamente <strong class="adm-mono"><?= e($ex['codigo_individual']) ?></strong>.</p>
                            <label class="adm-field"><span class="adm-field__label">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="300" placeholder="Ex.: carimbo na folha de rosto"></label>
                            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Confirmar carimbo</button>
                        </form>
                    </details>
                <?php endif; ?>

                <details class="adm-acao"<?= admin_param($_GET, 'acao') === 'localizacao' ? ' open' : '' ?>>
                    <summary><?= icon('map-pin') ?> Transferir de localização</summary>
                    <form method="post" data-loading-form>
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="transferir">
                        <fieldset class="adm-field"><legend class="adm-field__label">Nova localização (estante e prateleira)</legend><?= fisico_campo_local('localizacao_id', $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null, 'Aguardando organização (área de triagem)', 'transferir-local') ?></fieldset>
                        <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="motivo" maxlength="300" placeholder="Ex.: reorganização da estante"></label>
                        
                        <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Salvar localização</button>
                    </form>
                </details>

                <?php if (auth_pode('livros.editar')): ?>
                <details class="adm-acao"<?= admin_param($_GET, 'acao') === 'obra' ? ' open' : '' ?>>
                    <summary><?= icon('git-compare-arrows') ?> Corrigir a obra deste exemplar</summary>
                    <?php if ($emprestado): ?>
                        <p class="adm-field__hint">Bloqueado: registre a devolução antes de corrigir a obra.</p>
                    <?php else: ?>
                        <form method="post" data-loading-form data-confirm="Mudar só este exemplar para a outra obra? O código BDP continua o mesmo.">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="corrigir_obra">
                            <p class="adm-field__hint">Use quando ESTA cópia é de outra edição ou obra. Os outros exemplares de “<?= e($ex['titulo']) ?>” não mudam. Para corrigir os dados de todas as cópias, use “Editar dados da obra”.</p>
                            <label class="adm-field"><span class="adm-field__label">Número da obra correta <span class="adm-required" aria-hidden="true">*</span></span><input class="adm-input" type="number" name="destino" min="1" required inputmode="numeric" placeholder="Nº da obra (aparece na página da obra)"></label>
                            <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-required" aria-hidden="true">*</span></span><input class="adm-input" name="motivo" maxlength="300" required placeholder="Ex.: esta cópia é a 2ª edição (ISBN diferente)"></label>
                            <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required><span>Confirmo que este exemplar físico é da obra informada.</span></label>
                            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Corrigir só este exemplar</button>
                        </form>
                    <?php endif; ?>
                </details>
                <?php endif; ?>

                <details class="adm-acao">
                    <summary><?= icon('pencil') ?> Conservação e observações</summary>
                    <form method="post" data-loading-form>
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="editar">
                        <label class="adm-field"><span class="adm-field__label">Estado de conservação</span><?= fisico_select_conservacao('estado_conservacao', $ex['estado_conservacao']) ?></label>
                        <label class="adm-field"><span class="adm-field__label">Observações</span><textarea class="adm-textarea" name="observacoes" rows="3" maxlength="500"><?= e($ex['observacoes'] ?? '') ?></textarea></label>
                        <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Salvar</button>
                    </form>
                </details>

                <details class="adm-acao">
                    <summary><?= icon('refresh-cw') ?> Alterar situação</summary>
                    <?php if ($emprestado): ?>
                        <p class="adm-field__hint">Bloqueado: há um empréstimo ativo. Registre a devolução primeiro.</p>
                    <?php elseif ($ex['situacao'] === 'extraviado' && !$podeBaixar): ?>
                        <p class="adm-field__hint">Exemplar extraviado: só a bibliotecária ou o administrador pode devolvê-lo ao acervo.</p>
                    <?php else: ?>
                        <form method="post" data-loading-form>
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="situacao">
                            <label class="adm-field"><span class="adm-field__label">Nova situação</span><?= fisico_select_situacao('situacao', in_array($ex['situacao'], Biblioteca\Services\ExemplarService::SITUACOES_MANUAIS, true) ? $ex['situacao'] : 'disponivel') ?></label>
                            <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="motivo" maxlength="300"></label>
                            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Salvar situação</button>
                        </form>
                        <p class="adm-field__hint">"Emprestado" só é definido pelo módulo de empréstimos.</p>
                    <?php endif; ?>
                </details>

                <?php if ($podeBaixar): ?>
                    <details class="adm-acao adm-acao--perigo">
                        <summary><?= icon('circle-alert') ?> Registrar extravio</summary>
                        <?php if ($emprestado || $ex['situacao'] === 'extraviado'): ?>
                            <p class="adm-field__hint"><?= $emprestado ? 'Bloqueado: há um empréstimo ativo.' : 'Este exemplar já está registrado como extraviado.' ?></p>
                        <?php else: ?>
                            <form method="post" data-loading-form data-confirm="Registrar o exemplar <?= e($ex['codigo_individual']) ?> como extraviado?">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="extravio">
                                <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-required" aria-hidden="true">*</span></span><input class="adm-input" name="motivo" maxlength="300" required placeholder="Ex.: não localizado após busca em todas as estantes"></label>
                                <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required><span>Confirmo que o exemplar não foi encontrado.</span></label>
                                <button class="adm-button adm-button--danger adm-button--sm" type="submit"><?= icon('circle-alert') ?> Registrar extravio</button>
                            </form>
                        <?php endif; ?>
                    </details>

                    <details class="adm-acao adm-acao--perigo">
                        <summary><?= icon('package-x') ?> Dar baixa</summary>
                        <?php if ($emprestado): ?>
                            <p class="adm-field__hint">Bloqueado: um exemplar emprestado não pode ser baixado.</p>
                        <?php else: ?>
                            <form method="post" data-loading-form data-confirm="Dar baixa definitiva no exemplar <?= e($ex['codigo_individual']) ?>? Esta ação não pode ser desfeita.">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="baixa">
                                <label class="adm-field"><span class="adm-field__label">Motivo da baixa <span class="adm-required" aria-hidden="true">*</span></span>
                                    <select class="adm-select" name="baixa_tipo" required>
                                        <option value="">Selecione</option>
                                        <?php foreach (BAIXA_TIPOS as $bv => $br): ?><option value="<?= e($bv) ?>"<?= $ex['situacao'] === 'extraviado' && $bv === 'extravio' ? ' selected' : '' ?>><?= e($br) ?></option><?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="adm-field"><span class="adm-field__label">Justificativa / autorização <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="justificativa" maxlength="150" placeholder="Ex.: autorizado pela direção em 18/11/2026"></label>
                                <label class="adm-field"><span class="adm-field__label">Observações <span class="adm-optional">(opcional)</span></span><textarea class="adm-textarea" name="observacao" rows="2" maxlength="1000"></textarea></label>
                                <p class="adm-field__hint">Uma ausência temporária no inventário não é motivo de baixa: use “Registrar extravio” e aguarde.</p>
                                <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required><span>Entendo que a baixa é definitiva. O código <strong class="adm-mono"><?= e($ex['codigo_individual']) ?></strong> não será reaproveitado.</span></label>
                                <button class="adm-button adm-button--danger adm-button--sm" type="submit"><?= icon('package-x') ?> Dar baixa</button>
                            </form>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php
admin_page_end(FISICO_JS);
