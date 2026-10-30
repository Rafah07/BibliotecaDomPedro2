<?php
/**
 * Detalhes de um exemplar físico: dados, localização confirmada (e a antiga da planilha, só como
 * referência), histórico, conferências de inventário e ações (conservação, transferência,
 * situação, extravio e baixa). Aceita ?id= ou ?codigo= (ex.: BDP-000001, lido pelo leitor).
 * Sem banco configurado, mostra a versão demonstrativa (includes/demo/detalhes-exemplar.php).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Support\Code128;

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
                    $servico->transferir($id, (int)admin_param($_POST, 'localizacao_id') ?: null, $usuario['id'], admin_param($_POST, 'motivo'));
                    admin_flash('sucesso', 'Localização atualizada.', 'Só este exemplar mudou de lugar; os outros exemplares do livro continuam onde estão.');
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
                    $servico->baixar($id, $usuario['id'], admin_param($_POST, 'motivo'));
                    admin_flash('sucesso', 'Baixa registrada.', 'O exemplar saiu do acervo ativo. O código e o histórico continuam guardados e o código não será reaproveitado.');
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

admin_page_start('exemplares', $ex['codigo_individual'], 'Exemplares', 'Unidades físicas do acervo', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], [$ex['codigo_individual']]]) ?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Exemplar físico</p>
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
    <div class="adm-notice adm-notice--red" role="note"><?= icon('package-x') ?><div><strong>Exemplar baixado em <?= admin_data_hora($ex['baixado_em']) ?>.</strong><p><?= e($ex['motivo_baixa'] ?? '') ?> O código continua reservado e não será reaproveitado.</p></div></div>
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
                <div><dt>Situação</dt><dd><?= acervo_badge_situacao($ex['situacao']) ?></dd></div>
                <div><dt>Estado de conservação</dt><dd><?= acervo_badge_conservacao($ex['estado_conservacao']) ?></dd></div>
                <div><dt>Localização física confirmada</dt><dd><?= $ex['localizacao_id'] ? e(fisico_local($ex)) . ($ex['local_codigo'] ? ' <span class="adm-mono">(' . e($ex['local_codigo']) . ')</span>' : '') : '<span class="adm-missing">Ainda não definida</span>' ?></dd></div>
                <div><dt>Localização antiga (planilha)</dt><dd><?= $planilha['localizacoes'] ? e($planilha['localizacoes']) . ' <small class="adm-table__muted">— histórica, não confirmada</small>' : '<span class="adm-missing">Não informada</span>' ?></dd></div>
                <div><dt>Cadastrado em</dt><dd><?= admin_data_hora($ex['criado_em']) ?><?= $ex['cadastrado_por_nome'] ? ' por ' . e($ex['cadastrado_por_nome']) : '' ?></dd></div>
                <div><dt>Origem do cadastro</dt><dd><?= $ex['inventario_id'] ? 'Conferência do <a class="adm-link" href="' . e(admin_url('inventario-detalhes') . '?id=' . (int)$ex['inventario_id']) . '">inventário nº ' . (int)$ex['inventario_id'] . '</a>' : 'Cadastro manual' ?></dd></div>
                <div><dt>Última conferência</dt><dd><?= $ex['conferido_em'] ? admin_data_hora($ex['conferido_em']) : '<span class="adm-missing">Ainda não conferido</span>' ?></dd></div>
                <div><dt>Etiqueta</dt><dd><?= $ex['etiqueta_gerada_em'] ? 'Gerada em ' . admin_data_hora($ex['etiqueta_gerada_em']) : '<span class="adm-missing">Ainda não gerada</span>' ?></dd></div>
                <div class="adm-meta__full"><dt>Observações</dt><dd><?= $ex['observacoes'] ? e($ex['observacoes']) : '<span class="adm-missing">Sem observações.</span>' ?></dd></div>
            </dl>
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
            <div class="adm-panel__header"><div><h2 id="titulo-codigo">Código de barras</h2><p>Code 128 com o código do exemplar. Lido por leitores USB comuns.</p></div></div>
            <figure class="adm-barcode adm-barcode--real"><?= Code128::svg($ex['codigo_individual']) ?><figcaption class="adm-mono"><?= e($ex['codigo_individual']) ?></figcaption></figure>
        </section>

        <?php if ($podeGerenciar && !$baixado): ?>
            <section class="adm-card adm-panel adm-acoes-exemplar" aria-labelledby="titulo-acoes">
                <div class="adm-panel__header"><div><h2 id="titulo-acoes">Ações</h2><p>Cada alteração fica registrada no histórico.</p></div></div>

                <details class="adm-acao"<?= admin_param($_GET, 'acao') === 'localizacao' ? ' open' : '' ?>>
                    <summary><?= icon('map-pin') ?> Transferir de localização</summary>
                    <form method="post" data-loading-form>
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="transferir">
                        <label class="adm-field"><span class="adm-field__label">Nova localização</span><?= fisico_select_local('localizacao_id', $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null, 'Sem localização', '', $locais) ?></label>
                        <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="motivo" maxlength="300" placeholder="Ex.: reorganização da estante"></label>
                        <?php if (!$locais): ?><p class="adm-field__hint">Nenhuma localização cadastrada. <a href="<?= e(admin_url('localizacoes')) ?>">Cadastrar localizações</a>.</p><?php endif; ?>
                        <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Salvar localização</button>
                    </form>
                </details>

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
                                    <select class="adm-select" name="motivo" required>
                                        <option value="">Selecione</option>
                                        <?php foreach (['Danificado sem possibilidade de reparo', 'Extraviado e não recuperado', 'Conteúdo desatualizado', 'Doado ou transferido para outra instituição', 'Cadastro feito por engano'] as $m): ?>
                                            <option><?= e($m) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
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
