<?php
/**
 * Detalhes de um inventário: números, mudanças de situação (iniciar, pausar, retomar,
 * concluir, cancelar), itens por categoria e decisões sobre divergências e ausências.
 * Nenhum exemplar vira extraviado sem uma decisão registrada aqui.
 *
 * Status de cada item: Pendente (ainda não conferido), Encontrado e Não localizado. Nada vira
 * "Não localizado" sozinho: em "Finalizar estante ou setor" a equipe escolhe a estante, o setor
 * ou o local antigo da planilha, vê os itens ainda pendentes e marca os que confirmou não estarem
 * lá (com confirmação). A marcação pode ser desfeita ("Voltar para pendente").
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\InventarioService;

$usuario = fisico_iniciar();
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$url = admin_url('inventario-detalhes') . '?id=' . $id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    fisico_exigir('inventario.gerenciar');
    $acao = admin_param($_POST, 'acao');
    $volta = admin_url_filtros(admin_url('inventario-detalhes'), ['id' => $id, 'filtro' => admin_param($_POST, 'filtro'), 'q' => admin_param($_POST, 'q'), 'pagina' => admin_param($_POST, 'pagina')]);
    $s = inventario_servico();
    try {
        switch ($acao) {
            case 'iniciar':
                $n = $s->iniciar($id, $usuario['id']);
                admin_flash('sucesso', 'Conferência iniciada.', fisico_numero($n) . ' item(ns) entraram na lista prevista, todos como Pendente (exemplares e registros importados ainda sem código BDP).');
                break;
            case 'pausar':
                $s->pausar($id, $usuario['id']);
                admin_flash('sucesso', 'Inventário pausado.', 'Nenhuma conferência pode ser registrada até ele ser retomado.');
                break;
            case 'retomar':
                $s->retomar($id, $usuario['id']);
                admin_flash('sucesso', 'Inventário retomado.');
                break;
            case 'concluir':
                if (admin_param($_POST, 'confirmar') !== '1') {
                    throw new AcervoException('Marque a confirmação para concluir. Depois de concluído, o inventário não pode mais ser alterado.');
                }
                $s->concluir($id, $usuario['id']);
                admin_flash('sucesso', 'Inventário concluído.', 'O resultado foi registrado e o inventário ficou bloqueado para alterações.');
                break;
            case 'cancelar':
                $s->cancelar($id, $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Inventário cancelado.', 'As conferências já registradas continuam no histórico. Nada foi alterado nos exemplares.');
                break;
            case 'decidir':
                $s->decidir((int)admin_param($_POST, 'item_id'), admin_param($_POST, 'decisao'), admin_param($_POST, 'observacao'), $usuario['id']);
                admin_flash('sucesso', 'Decisão registrada.', InventarioService::DECISOES[admin_param($_POST, 'decisao')] ?? '');
                break;
            case 'manter_ausentes':
                if (admin_param($_POST, 'confirmar') !== '1') {
                    throw new AcervoException('Marque a confirmação para manter os não localizados sem alteração.');
                }
                $n = $s->manterAusentes($id, admin_param($_POST, 'observacao'), $usuario['id']);
                admin_flash('sucesso', fisico_numero($n) . ' exemplar(es) não localizado(s) mantido(s) sem alteração.', 'Nenhum exemplar foi marcado como extraviado.');
                break;
            case 'marcar_nao_localizados':
                if (admin_param($_POST, 'confirmar') !== '1') {
                    throw new AcervoException('Confirme que os livros selecionados foram procurados e não estão no local.');
                }
                $sel = $_POST['itens'] ?? [];
                $n = $s->marcarNaoLocalizados($id, is_array($sel) ? $sel : [], admin_param($_POST, 'observacao'), $usuario['id']);
                admin_flash('sucesso', fisico_numero($n) . ' item(ns) marcado(s) como não localizado(s).', 'A situação dos exemplares não mudou. Se algum livro aparecer, pesquise-o na conferência e marque como encontrado.');
                $volta = admin_url_filtros(admin_url('inventario-detalhes'), ['id' => $id, 'fechar' => admin_param($_POST, 'fechar')]) . '#fechar';
                break;
            case 'voltar_pendente':
                $s->voltarPendente((int)admin_param($_POST, 'item_id'), $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'O item voltou para Pendente.');
                break;
            case 'corrigir':
                $s->corrigir((int)admin_param($_POST, 'item_id'), [
                    'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                    'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                    'observacao' => admin_param($_POST, 'observacao'),
                ], $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Conferência corrigida.', 'A correção ficou registrada no histórico do exemplar, com o motivo.');
                break;
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado.', fisico_erro_tecnico($e, 'inventário'));
    }
    admin_redirecionar($volta);
}

$filtro = array_key_exists(admin_param($_GET, 'filtro'), InventarioRepository::FILTROS) ? admin_param($_GET, 'filtro') : '';
$q = mb_substr(admin_param($_GET, 'q'), 0, 100);
try {
    $inv = inventario_repo()->buscar($id);
    if (!$inv) {
        http_response_code(404);
        admin_pagina_erro('Inventário não encontrado', 'Confira o endereço e tente novamente.', 'clipboard-check');
        exit;
    }
    // Depois da conclusão os itens ficam bloqueados (gatilhos), então os números continuam iguais ao resumo final.
    $resumo = inventario_repo()->resumo($id);
    $emCurso = in_array($inv['status'], ['em_andamento', 'pausado'], true);
    $filtro = $filtro ?: ($inv['status'] === 'aberto' ? 'todos' : ($emCurso && $resumo['a_conferir'] > 0 ? 'a_conferir'
        : (($resumo['divergencias_pendentes'] + $resumo['ausentes_pendentes']) > 0 && $emCurso ? 'pendentes' : 'encontrados')));
    $itens = inventario_repo()->itens($id, $filtro, $q, max(1, (int)admin_param($_GET, 'pagina', '1')), 25);
    $estimados = $inv['status'] === 'aberto' ? inventario_repo()->previstosEstimados($inv['setor']) : null;
    $locais = localizacoes_servico()->ativas();
    // Finalizar estante ou setor: lista os itens ainda pendentes do local escolhido.
    $fechar = mb_substr(admin_param($_GET, 'fechar'), 0, 200);
    $fecharLocal = [];
    if (str_starts_with($fechar, 'local:')) {
        $fecharLocal = ['localizacao_id' => (int)substr($fechar, 6)];
    } elseif (str_starts_with($fechar, 'setor:')) {
        $fecharLocal = ['setor' => substr($fechar, 6)];
    } elseif (str_starts_with($fechar, 'antiga:')) {
        $fecharLocal = ['antiga' => substr($fechar, 7)];
    }
    $fecharItens = $fecharLocal && $emCurso ? inventario_repo()->itens($id, 'a_conferir', '', 1, 500, $fecharLocal) : null;
    $setores = $emCurso ? localizacoes_servico()->setores() : [];
    $locaisAntigos = $emCurso ? inventario_repo()->locaisAntigosPendentes($id) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o inventário', fisico_erro_tecnico($e, 'detalhes do inventário'));
    exit;
}
$filtros = ['id' => $id, 'filtro' => $filtro, 'q' => $q];
$aberto = in_array($inv['status'], ['em_andamento', 'pausado'], true);
$pode = auth_pode('inventario.gerenciar');
$podeEditarLivro = $inv['status'] === 'em_andamento' && auth_pode('inventario.conferir') && auth_pode('livros.editar');
$transicoes = InventarioService::TRANSICOES[$inv['status']];
$pendentes = $resumo['divergencias_pendentes'] + $resumo['ausentes_pendentes'];
$contagens = [
    'a_conferir' => $resumo['a_conferir'], 'encontrados' => $resumo['encontrados'], 'nao_localizados' => $resumo['nao_localizados'],
    'com_alertas' => $resumo['com_alertas'], 'sem_codigo' => $resumo['sem_codigo'],
    'pendentes' => $pendentes, 'nao_conferidos' => $resumo['nao_conferidos'], 'conferidos' => $resumo['conferidos'], 'local' => $resumo['local_diferente'],
    'estado' => $resumo['estado_divergente'], 'situacao' => $resumo['situacao_divergente'], 'cadastrados' => $resumo['cadastrados'], 'fora_lista' => $resumo['fora_lista'],
    'em_circulacao' => $resumo['em_circulacao'], 'todos' => $resumo['itens'],
];
$campoVolta = fn () => '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="filtro" value="' . e($filtro) . '"><input type="hidden" name="q" value="' . e($q) . '"><input type="hidden" name="pagina" value="' . (int)$itens['pagina'] . '">';

admin_page_start('inventario', 'Inventário: ' . $inv['titulo'], 'Inventário', 'Conferência digital do acervo físico', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], [$inv['titulo']]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($inv['titulo']) ?> <?= fisico_badge_inventario($inv['status']) ?></h1>
        <p>Nº <?= (int)$inv['id'] ?> · Responsável: <?= e($inv['responsavel_nome'] ?? '—') ?> · <?= $inv['setor'] ? 'Setor: ' . e($inv['setor']) : 'Acervo inteiro' ?> · Início em <?= admin_data_hora($inv['data_inicio'] ?? $inv['iniciado_em'], false) ?>
            <?php if ($inv['concluido_em']): ?> · Concluído em <?= admin_data_hora($inv['concluido_em']) ?><?= $inv['concluido_por_nome'] ? ' por ' . e($inv['concluido_por_nome']) : '' ?><?php endif; ?>
            <?php if ($inv['cancelado_em']): ?> · Cancelado em <?= admin_data_hora($inv['cancelado_em']) ?><?= $inv['cancelado_por_nome'] ? ' por ' . e($inv['cancelado_por_nome']) : '' ?><?php endif; ?></p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($inv['status'] === 'em_andamento' && auth_pode('inventario.conferir')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('inventario-conferencia') . '?id=' . $id) ?>"><?= icon('clipboard-check') ?> Conferir e corrigir livros</a>
        <?php endif; ?>
        <?php if ($inv['status'] !== 'aberto'): ?>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('inventario-relatorio') . '?id=' . $id) ?>"><?= icon('file-chart-column') ?> Relatório</a>
        <?php endif; ?>
        <?php if ($pode && !in_array($inv['status'], ['concluido', 'cancelado'], true)): ?>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('inventario-editar') . '?id=' . $id) ?>"><?= icon('pencil') ?> Editar dados</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if ($inv['descricao']): ?><p class="adm-filtro-ativo"><?= icon('message-square-text') ?> <?= nl2br(e($inv['descricao'])) ?></p><?php endif; ?>
<?php if ($inv['status'] === 'cancelado' && $inv['motivo_cancelamento']): ?><?= admin_aviso('info', 'Motivo do cancelamento', $inv['motivo_cancelamento']) ?><?php endif; ?>
<?php if (in_array($inv['status'], ['concluido', 'cancelado'], true)): ?>
    <?= admin_aviso('info', 'Inventário finalizado e bloqueado.', 'Os dados abaixo não podem mais ser alterados. Use o relatório para consultar ou exportar o resultado.') ?>
<?php endif; ?>

<?php if ($inv['status'] === 'aberto'): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-preparacao">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-preparacao">Em preparação</h2>
            <p>Ao iniciar, <?= fisico_numero($estimados) ?> exemplar(es) <?= $inv['setor'] ? 'do setor ' . e($inv['setor']) : 'do acervo' ?> (exceto baixados e extraviados) entram na lista prevista. Exemplares cadastrados depois também podem ser conferidos.</p>
        </div></div>
    </section>
<?php else: ?>
    <?php $det = fn (string $f) => admin_url_filtros(admin_url('inventario-detalhes'), ['id' => $id, 'filtro' => $f]); ?>
    <section class="adm-card adm-panel adm-progresso-inv" aria-label="Progresso do inventário">
        <div class="adm-progresso-inv__topo">
            <strong><?= (int)$resumo['percentual'] ?>% concluído</strong>
            <span><?= fisico_numero($resumo['encontrados'] + $resumo['nao_localizados']) ?> de <?= fisico_numero($resumo['itens']) ?> itens com resultado · <?= fisico_numero($resumo['divergencias']) ?> divergência(s) · <?= fisico_numero($resumo['cadastrados']) ?> cadastrado(s) na conferência</span>
        </div>
        <div class="adm-progress adm-progress--lg" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$resumo['percentual'] ?>" aria-label="Percentual concluído"><div class="adm-progress__bar adm-progress__bar--ok" style="width: <?= (int)$resumo['percentual'] ?>%"></div></div>
        <div class="adm-stats adm-stats--6">
            <?= fisico_stat('Total', $resumo['itens'], 'Itens deste inventário', 'list-checks', 'blue', $det('todos')) ?>
            <?= fisico_stat('Encontrados', $resumo['encontrados'], fisico_numero($resumo['previstos_conferidos']) . ' dos previstos', 'square-check', 'green', $det('encontrados')) ?>
            <?= fisico_stat('Pendentes', $resumo['a_conferir'], fisico_numero($resumo['em_circulacao']) . ' emprestado(s) à parte', 'hourglass', 'amber', $det('a_conferir')) ?>
            <?= fisico_stat('Não localizados', $resumo['nao_localizados'], 'Procurados e não achados', 'book-x', 'red', $det('nao_localizados')) ?>
            <?= fisico_stat('Com alertas cadastrais', $resumo['com_alertas'], 'Dados a completar ou conferir', 'triangle-alert', 'indigo', $det('com_alertas')) ?>
            <?= fisico_stat('Sem código BDP', $resumo['sem_codigo'], 'Registros importados a conferir', 'tag', 'teal', $det('sem_codigo')) ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($emCurso && $pode): ?>
    <section class="adm-card adm-panel" id="fechar" aria-labelledby="titulo-fechar">
        <div class="adm-panel__header"><div><h2 id="titulo-fechar">Finalizar estante ou setor</h2>
            <p>Terminou de conferir uma estante ou setor? Escolha o local para ver os livros que continuam pendentes ali. Marque como não localizados só os que você confirmou que não estão lá. Nada muda sem a sua confirmação, e a marcação pode ser desfeita.</p></div></div>
        <form method="get" action="<?= e(admin_url('inventario-detalhes')) ?>#fechar" class="adm-inline-form">
            <input type="hidden" name="id" value="<?= $id ?>">
            <label class="adm-field"><span class="adm-field__sublabel">Local conferido</span>
                <select class="adm-select" name="fechar" required>
                    <option value="">Escolha a estante, o setor ou o local antigo...</option>
                    <?php if ($locais): ?><optgroup label="Estantes e prateleiras cadastradas">
                        <?php foreach ($locais as $lo): ?><option value="local:<?= (int)$lo['id'] ?>"<?= $fechar === 'local:' . $lo['id'] ? ' selected' : '' ?>><?= e(Biblioteca\Services\ExemplarService::localizacaoTexto($lo)) ?></option><?php endforeach; ?>
                    </optgroup><?php endif; ?>
                    <?php if ($setores): ?><optgroup label="Setores inteiros">
                        <?php foreach ($setores as $st): ?><option value="setor:<?= e($st) ?>"<?= $fechar === 'setor:' . $st ? ' selected' : '' ?>>Setor <?= e($st) ?></option><?php endforeach; ?>
                    </optgroup><?php endif; ?>
                    <?php if ($locaisAntigos): ?><optgroup label="Localização antiga (da planilha)">
                        <?php foreach ($locaisAntigos as $ant => $n): ?><option value="antiga:<?= e((string)$ant) ?>"<?= $fechar === 'antiga:' . $ant ? ' selected' : '' ?>><?= e((string)$ant) ?> (<?= (int)$n ?> pendente(s))</option><?php endforeach; ?>
                    </optgroup><?php endif; ?>
                </select></label>
            <button class="adm-button adm-button--ghost" type="submit"><?= icon('list-checks') ?> Ver pendentes deste local</button>
        </form>
        <?php if ($fecharItens !== null): ?>
            <?php if (!$fecharItens['itens']): ?>
                <?= admin_aviso('sucesso', 'Nenhum livro pendente neste local.', 'Todos os itens deste local já foram encontrados ou marcados.') ?>
            <?php else: ?>
                <form method="post" class="adm-form-section" data-selecao data-confirm="Marcar os livros selecionados como não localizados? A situação dos exemplares não muda e a marcação pode ser desfeita depois.">
                    <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="marcar_nao_localizados"><input type="hidden" name="fechar" value="<?= e($fechar) ?>">
                    <div class="adm-selecao-barra"><label><input type="checkbox" data-selecao-todos> Selecionar todos</label><span data-selecao-total></span><span><?= fisico_numero($fecharItens['total']) ?> pendente(s) neste local<?= $fecharItens['total'] > 500 ? ' (mostrando 500)' : '' ?></span></div>
                    <div class="adm-fechar-estante__lista">
                        <table class="adm-table">
                            <thead><tr><th class="adm-check-col" scope="col"><span class="adm-visually-hidden">Selecionar</span></th><th scope="col">Livro</th><th scope="col">Código / origem</th></tr></thead>
                            <tbody>
                                <?php foreach ($fecharItens['itens'] as $fi): ?>
                                    <tr>
                                        <td class="adm-check-col"><input type="checkbox" name="itens[]" value="<?= (int)$fi['id'] ?>" data-selecao-item aria-label="Selecionar <?= e($fi['titulo']) ?>"<?= $fi['emprestimo_aberto'] ? ' disabled title="Emprestado: fora da estante"' : '' ?>></td>
                                        <td><strong><?= e($fi['titulo']) ?></strong><small class="adm-block-muted"><?= e($fi['autores'] ?: 'Autor não informado') ?></small></td>
                                        <td><?= $fi['codigo_individual'] ? '<span class="adm-code">' . e($fi['codigo_individual']) . '</span>' : 'Sem código' ?><?= $fi['origem_linha'] ? '<small class="adm-block-muted">Linha ' . (int)$fi['origem_linha'] . ' da planilha' . ($fi['localizacao_antiga'] ? ' · ' . e($fi['localizacao_antiga']) : '') . '</small>' : '' ?><?= $fi['emprestimo_aberto'] ? '<small class="adm-block-muted">Emprestado</small>' : '' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <label class="adm-field"><span class="adm-field__sublabel">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: estante E03 conferida inteira em 13/11"></label>
                    <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required> <span>Procurei estes livros no local e confirmo que não estão lá.</span></label>
                    <button class="adm-button adm-button--primary" type="submit" data-selecao-enviar><?= icon('book-x') ?> Marcar selecionados como não localizados</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($pode && $transicoes): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-situacao">
        <div class="adm-panel__header"><div><h2 id="titulo-situacao">Situação do inventário</h2>
            <?php if ($aberto): ?><p><?= $pendentes ? fisico_numero($pendentes) . ' pendência(s) precisam de decisão antes da conclusão.' : 'Sem pendências: o inventário pode ser concluído.' ?></p><?php endif; ?>
        </div></div>
        <div class="adm-acoes-inventario">
            <?php if (in_array('em_andamento', $transicoes, true)): ?>
                <form method="post" data-confirm="<?= $inv['status'] === 'aberto' ? 'Iniciar a conferência? A lista de exemplares previstos será definida agora.' : 'Retomar a conferência?' ?>">
                    <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="<?= $inv['status'] === 'aberto' ? 'iniciar' : 'retomar' ?>">
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('play') ?> <?= $inv['status'] === 'aberto' ? 'Iniciar conferência' : 'Retomar conferência' ?></button>
                </form>
            <?php endif; ?>
            <?php if (in_array('pausado', $transicoes, true)): ?>
                <form method="post" data-confirm="Pausar o inventário? Ninguém poderá registrar conferências até ele ser retomado.">
                    <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="pausar">
                    <button class="adm-button adm-button--ghost" type="submit"><?= icon('pause') ?> Pausar</button>
                </form>
            <?php endif; ?>
            <?php if (in_array('concluido', $transicoes, true)): ?>
                <details class="adm-acao">
                    <summary class="adm-button adm-button--ghost"><?= icon('flag') ?> Concluir inventário</summary>
                    <form method="post" class="adm-acao__form">
                        <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="concluir">
                        <p>A conclusão grava o resultado e bloqueia o inventário. Só é possível sem pendências.</p>
                        <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required> <span>Confirmo que a conferência terminou.</span></label>
                        <button class="adm-button adm-button--primary" type="submit"<?= $pendentes ? ' disabled' : '' ?>><?= icon('flag') ?> Concluir</button>
                        <?php if ($pendentes): ?><p class="adm-field__hint">Trate as pendências primeiro (aba "Decisões necessárias").</p><?php endif; ?>
                    </form>
                </details>
            <?php endif; ?>
            <?php if (in_array('cancelado', $transicoes, true)): ?>
                <details class="adm-acao">
                    <summary class="adm-button adm-button--ghost adm-button--danger-text"><?= icon('ban') ?> Cancelar inventário</summary>
                    <form method="post" class="adm-acao__form" data-confirm="Cancelar este inventário? Ele ficará bloqueado e não poderá ser retomado.">
                        <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="cancelar">
                        <label class="adm-field"><span class="adm-field__label">Motivo <span class="adm-required" aria-hidden="true">*</span></span>
                            <input class="adm-input" name="motivo" maxlength="500" required></label>
                        <button class="adm-button adm-button--danger" type="submit"><?= icon('ban') ?> Cancelar inventário</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($inv['status'] !== 'aberto'): ?>
<section class="adm-card adm-panel" aria-labelledby="titulo-itens">
    <nav class="adm-tabs adm-tabs--links adm-tabs--scroll" aria-label="Categorias dos exemplares">
        <?php foreach (InventarioRepository::FILTROS as $chave => $rotulo): ?>
            <?php if ($chave === 'pendentes' && !$aberto) { continue; } ?>
            <a href="<?= e(admin_url_filtros(admin_url('inventario-detalhes'), ['id' => $id, 'filtro' => $chave, 'q' => $q])) ?>"<?= $filtro === $chave ? ' aria-current="page"' : '' ?>>
                <?= e($rotulo) ?><?php if (isset($contagens[$chave])): ?> <span class="adm-tabs__count"><?= fisico_numero($contagens[$chave]) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <h2 id="titulo-itens" class="adm-visually-hidden"><?= e(InventarioRepository::FILTROS[$filtro]) ?></h2>

    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e(admin_url('inventario-detalhes')) ?>">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="filtro" value="<?= e($filtro) ?>">
        <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar nesta categoria</span>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Código BDP, título, autor ou ISBN..." autocomplete="off" data-campo-codigo></label>
        <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Pesquisar</button>
    </form>

    <?php if ($filtro === 'pendentes' && $pode && $resumo['ausentes_pendentes'] > 0): ?>
        <details class="adm-acao adm-acao--lote">
            <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('check-check') ?> Manter todos os <?= fisico_numero($resumo['ausentes_pendentes']) ?> não localizados sem alteração</summary>
            <form method="post" class="adm-acao__form">
                <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="manter_ausentes">
                <p>Os exemplares continuam com a situação atual (nenhum vira extraviado). A decisão fica registrada para cada um.</p>
                <label class="adm-field"><span class="adm-field__label">Observação</span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: procurar novamente no próximo inventário"></label>
                <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required> <span>Confirmo manter os não localizados sem alteração.</span></label>
                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Registrar decisão</button>
            </form>
        </details>
    <?php endif; ?>

    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards adm-table--inventario">
            <thead>
                <tr><th scope="col">Exemplar ou registro</th><th scope="col">Cadastro</th><th scope="col">Encontrado</th><th scope="col">Situação</th><?php if ($aberto && $pode): ?><th scope="col" class="adm-actions-col">Decisão</th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($itens['itens'] as $it): ?>
                    <?php
                    [$rotulo, $tom] = inventario_item_situacao($it);
                    $divs = inventario_divergencias($it);
                    $podeDecidirDiv = $it['conferido_em'] !== null && $divs && $it['decisao'] === null;
                    $podeDecidirAus = $it['conferido_em'] === null && (int)$it['previsto'] && !$it['emprestimo_aberto'] && $it['decisao'] === null && $it['nao_localizado_em'] === null;
                    $semExemplar = $it['exemplar_id'] === null;
                    ?>
                    <tr>
                        <td class="adm-td-title"><?php if ($it['exemplar_id'] !== null): ?><a class="adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$it['exemplar_id']) ?>"><?= e($it['codigo_individual']) ?></a>
                            <?php else: ?><a class="adm-link" href="<?= e(admin_url('inventario-conferencia') . '?id=' . $id . '&item=' . (int)$it['id']) ?>#registro"><?= icon('file-spreadsheet') ?> <?= $it['origem_linha'] ? 'Linha ' . (int)$it['origem_linha'] . ' da planilha' : 'Sem código BDP' ?></a><?php endif; ?>
                            <small class="adm-block-muted adm-td-livro"><strong><?= e($it['titulo']) ?></strong> · <?= e($it['autores'] ?: 'Autor não informado') ?><?= (int)$it['alertas'] ? ' · <span class="adm-status adm-tone--indigo">' . (int)$it['alertas'] . ' alerta(s)</span>' : '' ?></small>
                            <?php if ($podeEditarLivro): ?><a class="adm-link adm-block-muted" href="<?= e(admin_url('inventario-conferencia') . '?id=' . $id . '&corrigir=' . (int)$it['livro_id']) ?>#corrigir-livro"><?= icon('pencil') ?> Corrigir dados do livro</a><?php endif; ?>
                            <?php if ($it['localizacao_antiga']): ?><small class="adm-table__muted">Local antigo: <?= e($it['localizacao_antiga']) ?></small><?php endif; ?>
                            <?php if (!(int)$it['previsto']): ?><small class="adm-table__muted"><?= (int)$it['cadastrado_no_inventario'] ? 'cadastrado na conferência' : 'fora da lista prevista' ?></small><?php endif; ?></td>
                        <td data-label="Cadastro"><?php if ($it['exemplar_id'] !== null): ?><?= e(fisico_local($it, 'atual_')) ?><small class="adm-block-muted"><?= e(ACERVO_CONSERVACAO[$it['estado_conservacao']] ?? $it['estado_conservacao']) ?> · <?= e(Biblioteca\Services\ExemplarService::rotuloSituacao($it['situacao'])) ?></small><?php else: ?><span class="adm-missing">Ainda sem exemplar</span><?php endif; ?></td>
                        <td data-label="Encontrado">
                            <?php if ($it['conferido_em']): ?>
                                <?= e(fisico_local($it, 'encontrada_')) ?><small class="adm-block-muted"><?= e(ACERVO_CONSERVACAO[$it['estado_encontrado']] ?? '') ?> · <?= admin_data_hora($it['conferido_em']) ?><?= $it['conferido_por_nome'] ? ' · ' . e($it['conferido_por_nome']) : '' ?></small>
                                <?php if ($it['observacao']): ?><small class="adm-block-muted"><?= icon('message-square-text') ?> <?= e($it['observacao']) ?></small><?php endif; ?>
                                <?php if ((int)$it['correcoes']): ?><small class="adm-block-muted">Corrigido <?= (int)$it['correcoes'] ?> vez(es)</small><?php endif; ?>
                            <?php elseif ($it['nao_localizado_em']): ?><span class="adm-missing">Não localizado</span><small class="adm-block-muted"><?= admin_data_hora($it['nao_localizado_em']) ?><?= $it['nao_localizado_por_nome'] ? ' · ' . e($it['nao_localizado_por_nome']) : '' ?><?= $it['nao_localizado_obs'] ? ' · ' . e($it['nao_localizado_obs']) : '' ?></small>
                            <?php else: ?><span class="adm-missing">Pendente</span><?php endif; ?>
                        </td>
                        <td data-label="Situação">
                            <span class="adm-status adm-tone--<?= $tom ?>"><?= e($rotulo) ?></span>
                            <?php foreach ($divs as $d): ?><small class="adm-block-muted adm-divergencia"><?= icon('triangle-alert') ?> <?= e($d) ?></small><?php endforeach; ?>
                            <?php if ($it['decisao']): ?><small class="adm-block-muted"><?= icon('check') ?> <?= e(InventarioService::DECISOES[$it['decisao']]) ?><?= $it['decidido_por_nome'] ? ' · ' . e($it['decidido_por_nome']) : '' ?><?= $it['decisao_observacao'] ? ' · ' . e($it['decisao_observacao']) : '' ?></small><?php endif; ?>
                        </td>
                        <?php if ($aberto && $pode): ?>
                            <td class="adm-actions-col adm-td-decisao">
                                <?php if ($podeDecidirDiv || $podeDecidirAus): ?>
                                    <form method="post" class="adm-decisao">
                                        <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="decidir"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                        <label class="adm-visually-hidden" for="decisao-<?= (int)$it['id'] ?>">Decisão para <?= e($it['codigo_individual'] ?? $it['titulo']) ?></label>
                                        <select class="adm-select adm-select--sm" name="decisao" id="decisao-<?= (int)$it['id'] ?>" required>
                                            <option value="">Escolha...</option>
                                            <?php foreach ($podeDecidirDiv ? ['atualizar_cadastro', 'manter_cadastro'] : ($semExemplar ? ['manter_sem_alteracao'] : ['manter_sem_alteracao', 'registrar_extravio']) as $dec): ?>
                                                <?php if ($dec === 'atualizar_cadastro' && (int)$it['divergencia_situacao'] && ($it['emprestimo_aberto'] || in_array($it['situacao'], ['emprestado', 'baixado'], true))) { continue; } ?>
                                                <option value="<?= $dec ?>"><?= e(InventarioService::DECISOES[$dec]) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input class="adm-input adm-input--sm" name="observacao" maxlength="500" placeholder="Observação (opcional)" aria-label="Observação da decisão">
                                        <button class="adm-button adm-button--primary adm-button--sm" type="submit"<?= $podeDecidirAus ? '' : '' ?>><?= icon('check') ?> Registrar</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($it['nao_localizado_em'] !== null && $it['decisao'] === null): ?>
                                    <form method="post" class="adm-inline-form">
                                        <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="voltar_pendente"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('rotate-ccw') ?> Voltar para pendente</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($it['conferido_em'] !== null && $it['decisao'] === null): ?>
                                    <details class="adm-acao adm-acao--mini">
                                        <summary class="adm-link"><?= icon('rotate-ccw') ?> Desfazer “Encontrado”</summary>
                                        <form method="post" class="adm-acao__form" data-confirm="Voltar este item para Pendente? O exemplar e o código BDP continuam cadastrados.">
                                            <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="voltar_pendente"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                            <label class="adm-field"><span class="adm-field__sublabel">Motivo *</span><input class="adm-input" name="motivo" maxlength="500" required placeholder="Ex.: marcado no livro errado"></label>
                                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('rotate-ccw') ?> Voltar para pendente</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($it['conferido_em'] !== null): ?>
                                    <details class="adm-acao adm-acao--mini">
                                        <summary class="adm-link"><?= icon('pencil') ?> Corrigir conferência</summary>
                                        <form method="post" class="adm-acao__form">
                                            <?= csrf_campo() ?><?= $campoVolta() ?><input type="hidden" name="acao" value="corrigir"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                            <label class="adm-field"><span class="adm-field__sublabel">Estado encontrado</span><?= fisico_select_conservacao('estado_conservacao', (string)$it['estado_encontrado']) ?></label>
                                            <label class="adm-field"><span class="adm-field__sublabel">Localização encontrada</span><?= fisico_select_local('localizacao_id', $it['localizacao_encontrada_id'] !== null ? (int)$it['localizacao_encontrada_id'] : null, 'Manter a registrada', '', $locais) ?></label>
                                            <label class="adm-field"><span class="adm-field__sublabel">Observação</span><input class="adm-input" name="observacao" maxlength="500" value="<?= e((string)$it['observacao']) ?>"></label>
                                            <label class="adm-field"><span class="adm-field__sublabel">Motivo da correção *</span><input class="adm-input" name="motivo" maxlength="500" required></label>
                                            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Salvar correção</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$itens['itens']): ?>
                    <tr><td colspan="5"><div class="adm-empty"><?= icon('list-checks') ?>
                        <strong><?= $q !== '' ? 'Nenhum item encontrado nesta categoria' : ($filtro === 'pendentes' ? 'Nenhuma decisão necessária' : 'Nenhum item nesta categoria') ?></strong>
                        <span><?= $filtro === 'pendentes' ? 'Todas as divergências e ausências já têm decisão.' : 'Os exemplares aparecem aqui conforme a conferência avança.' ?></span>
                    </div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= admin_paginacao($itens['total'], $itens['pagina'], 25, admin_url('inventario-detalhes'), $filtros, [25]) ?>
</section>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
