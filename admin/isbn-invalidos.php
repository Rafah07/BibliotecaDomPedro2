<?php
/**
 * Tratamento em lote dos ISBNs inválidos de uma importação.
 *
 * Mostra as linhas ainda não gravadas que têm erro de ISBN, explica o problema de cada ISBN
 * e sugere correções só quando há fundamentação confiável (Biblioteca\Support\IsbnDiagnostico).
 * Para cada linha, a bibliotecária escolhe:
 *   * importar sem ISBN (em lote, com confirmação) — só quando o ISBN é o único impedimento;
 *   * corrigir manualmente (o ISBN digitado precisa ser válido);
 *   * manter pendente (não selecionar: nada muda).
 * O ISBN original continua nos dados originais da importação, nos registros legados e
 * nas observações internas do livro. Nenhum exemplar, CDD ou livro existente é alterado.
 *
 * POST acao=preparar  itens[] → mostra a confirmação com os livros afetados
 * POST acao=importar  itens[], confirmado=1 → importa sem ISBN (importacao.executar)
 * POST acao=corrigir  item_id, isbn → grava a correção manual (importacao.revisar)
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/importacao.php';
require_once __DIR__ . '/includes/acervo.php';

use Biblioteca\Repositories\ImportacaoRepository;
use Biblioteca\Support\Isbn;

if (db_modo() !== 'mysql') {
    admin_pagina_erro('Importação indisponível', 'Configure o banco MySQL para consultar as importações. No modo demonstrativo nenhuma planilha é gravada.', 'file-spreadsheet');
    exit;
}
try {
    db();
} catch (DbIndisponivel $e) {
    admin_pagina_erro('Banco de dados indisponível', $e->getMessage(), 'triangle-alert');
    exit;
}
$usuario = auth_exigir('importacao.ver');

$repo = importacao_repo();
$imp = $repo->buscar(importacao_id_param());
if (!$imp) {
    http_response_code(404);
    admin_pagina_erro('Importação não encontrada', 'A importação solicitada não existe. Consulte o histórico de importações.', 'file-spreadsheet');
    exit;
}
$id = (int)$imp['id'];
$editavel = in_array($imp['status'], ImportacaoRepository::STATUS_EDITAVEIS, true);
$podeExecutar = $editavel && auth_pode('importacao.executar');
$podeCorrigir = $editavel && auth_pode('importacao.revisar');
$urlPagina = admin_url('isbn-invalidos') . '?id=' . $id;
$urlDetalhes = admin_url('detalhes-importacao') . '?id=' . $id;

/** Guarda a mensagem para depois do redirecionamento (POST → redireciona → GET). */
function isbn_lote_flash(array $flash): never
{
    global $urlPagina;
    $_SESSION['isbn_lote_flash'] = $flash;
    header('Location: ' . $urlPagina);
    exit;
}

/** Ids enviados pelo formulário (somente números). */
function isbn_lote_ids(): array
{
    $ids = $_POST['itens'] ?? [];
    return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_scalar')), fn ($v) => $v > 0))) : [];
}

$confirmacao = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = is_string($_POST['acao'] ?? null) ? $_POST['acao'] : '';
    if (!csrf_valido()) {
        isbn_lote_flash(['tipo' => 'erro', 'mensagem' => 'A página ficou aberta por muito tempo. Confira a seleção e tente novamente.']);
    }
    try {
        if ($acao === 'preparar' || $acao === 'importar') {
            if (!$podeExecutar) {
                isbn_lote_flash(['tipo' => 'erro', 'mensagem' => $editavel
                    ? 'Somente a bibliotecária ou um administrador pode importar registros.'
                    : 'Esta importação não está aberta para revisão.']);
            }
            $ids = isbn_lote_ids();
            if (!$ids) {
                isbn_lote_flash(['tipo' => 'erro', 'mensagem' => 'Selecione pelo menos um registro.']);
            }
            if ($acao === 'preparar' || ($_POST['confirmado'] ?? '') !== '1') {
                $confirmacao = $ids; // mostra a lista de livros afetados antes de gravar
            } else {
                $r = $repo->importarSemIsbn($id, $ids, $usuario['id'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));
                registrar_acao('importacao.isbn_lote', 'importacao', $id, [
                    'selecionados' => count($ids), 'concluidos' => count($r['concluidos']),
                    'pendentes' => count($r['pendentes']), 'ja_importados' => count($r['ja_importados']),
                    'linhas_concluidas' => array_column($r['concluidos'], 'linha'),
                ]);
                isbn_lote_flash(['tipo' => 'lote', 'selecionados' => count($ids)] + $r);
            }
        } elseif ($acao === 'corrigir') {
            if (!$podeCorrigir) {
                isbn_lote_flash(['tipo' => 'erro', 'mensagem' => 'Seu perfil não pode corrigir registros desta importação.']);
            }
            $itemId = importacao_id_param('item_id');
            $novo = is_string($_POST['isbn'] ?? null) ? trim($_POST['isbn']) : '';
            $item = $repo->item($itemId);
            if (!$item || (int)$item['importacao_id'] !== $id) {
                isbn_lote_flash(['tipo' => 'erro', 'mensagem' => 'Registro não encontrado nesta importação.']);
            }
            if (!Isbn::valido($novo)) {
                isbn_lote_flash(['tipo' => 'erro', 'mensagem' => 'Linha ' . $item['numero_linha'] . ': o ISBN digitado ("' . mb_substr($novo, 0, 30) . '") também é inválido. Confira no livro físico; nada foi alterado.']);
            }
            $anterior = $item['dados_normalizados_json']['isbn_informado'] ?? null;
            $depois = $repo->corrigir($itemId, ['isbn' => $novo], $usuario['id']);
            registrar_acao('importacao.corrigir', 'importacao_item', $itemId, [
                'campos' => ['isbn'], 'isbn_anterior' => $anterior, 'isbn_novo' => Isbn::normalizar($novo), 'origem' => 'tratamento de ISBNs em lote',
            ]);
            $situacao = IMPORTACAO_SITUACOES[$depois['status_validacao']]['label'] ?? $depois['status_validacao'];
            isbn_lote_flash(['tipo' => 'corrigido', 'linha' => (int)$depois['numero_linha'], 'isbn' => Isbn::normalizar($novo),
                'situacao' => $depois['status_validacao'], 'situacao_label' => $situacao, 'item_id' => (int)$depois['id']]);
        } else {
            isbn_lote_flash(['tipo' => 'erro', 'mensagem' => 'Ação desconhecida.']);
        }
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) {
            error_log('[biblioteca] Erro no tratamento de ISBNs: ' . $e);
            isbn_lote_flash(['tipo' => 'erro', 'mensagem' => 'Não foi possível concluir a operação. Nada foi gravado. Tente novamente.']);
        }
        isbn_lote_flash(['tipo' => 'erro', 'mensagem' => $e->getMessage()]);
    }
}

auth_sessao();
$flash = $_SESSION['isbn_lote_flash'] ?? null;
unset($_SESSION['isbn_lote_flash']);

$pendencias = $repo->pendenciasIsbn($id);
$importados = $repo->importadosSemIsbn($id);
$elegiveis = array_values(array_filter($pendencias, fn ($i) => $i['avaliacao']['elegivel']));
$porId = array_column($pendencias, null, 'id');
$fmt = fn (int $n) => number_format($n, 0, ',', '.');
$livroUrl = fn (int $livroId) => admin_url('detalhes-livro') . '?id=' . $livroId;
$revisarUrl = fn (array $item) => $urlDetalhes . '&filtro=isbn_invalido&abrir=' . (int)$item['id'] . '#revisao';

admin_page_start('importar', 'ISBNs inválidos · Importação nº ' . $id, 'Importação de Planilhas', 'Acervo · Planilhas antigas da biblioteca', ['assets/css/acervo.css?v=20261027', 'assets/css/importacao.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Importação', admin_url('importar')], ['Histórico', admin_url('historico-importacoes')], ['Importação nº ' . $id, $urlDetalhes], ['ISBNs inválidos']]) ?>

<section class="adm-page-head">
    <div>
        <p class="adm-eyebrow">Importação nº <?= $id ?> · <?= htmlspecialchars($imp['nome_arquivo']) ?></p>
        <h1 class="adm-imp-title"><?= icon('barcode') ?> Tratar ISBNs inválidos em lote</h1>
        <p>Registros que ainda não entraram no acervo por causa do ISBN. Escolha quais importar sem o ISBN, corrija um ISBN conferido no livro ou deixe o registro pendente.</p>
        <p class="adm-imp-status-line"><?= importacao_badge_status($imp['status']) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars($urlDetalhes . '#revisao') ?>"><?= icon('arrow-left') ?> Voltar para a importação</a>
    </div>
</section>

<?php if ($flash && $flash['tipo'] === 'erro'): ?>
    <div class="adm-notice adm-notice--red" role="alert"><?= icon('circle-alert') ?><div class="adm-notice__content"><strong>Nada foi alterado.</strong><p><?= htmlspecialchars($flash['mensagem']) ?></p></div></div>
<?php elseif ($flash && $flash['tipo'] === 'corrigido'): ?>
    <div class="adm-notice adm-notice--<?= $flash['situacao'] === 'erro' ? 'amber' : 'green' ?> adm-notice--action" role="status">
        <?= icon('circle-check') ?>
        <div class="adm-notice__content">
            <strong>Linha <?= $flash['linha'] ?>: ISBN corrigido para <?= htmlspecialchars($flash['isbn']) ?>.</strong>
            <p>Situação agora: <?= htmlspecialchars($flash['situacao_label']) ?>.
                <?= in_array($flash['situacao'], ['valido', 'aviso'], true)
                    ? 'A linha foi aprovada e será gravada ao clicar em "Importar registros aprovados" na página da importação.'
                    : 'A linha ainda precisa de revisão na página da importação.' ?>
                O ISBN original da planilha continua guardado.</p>
        </div>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars($urlDetalhes . '&abrir=' . $flash['item_id'] . '#revisao') ?>"><?= icon('eye') ?> Ver a linha</a>
    </div>
<?php elseif ($flash && $flash['tipo'] === 'lote'): ?>
    <section class="adm-notice adm-notice--<?= $flash['pendentes'] ? 'amber' : 'green' ?>" role="status" aria-labelledby="titulo-resultado">
        <?= icon($flash['pendentes'] ? 'triangle-alert' : 'circle-check') ?>
        <div class="adm-notice__content">
            <strong id="titulo-resultado">Importação sem ISBN concluída.</strong>
            <dl class="adm-notice__numbers">
                <div><dt>Processados</dt><dd><?= $fmt($flash['selecionados']) ?></dd></div>
                <div><dt>Concluídos (livros criados)</dt><dd><?= $fmt(count($flash['concluidos'])) ?></dd></div>
                <div><dt>Ainda pendentes</dt><dd><?= $fmt(count($flash['pendentes'])) ?></dd></div>
                <?php if ($flash['ja_importados']): ?><div><dt>Já estavam no acervo (sem alteração)</dt><dd><?= $fmt(count($flash['ja_importados'])) ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($flash['concluidos']): ?>
                <details class="adm-isbn-details">
                    <summary>Livros criados (<?= count($flash['concluidos']) ?>)</summary>
                    <ul><?php foreach ($flash['concluidos'] as $c): ?>
                        <li>Linha <?= $c['linha'] ?> · <a class="adm-link" href="<?= htmlspecialchars($livroUrl($c['livro_id'])) ?>"><?= htmlspecialchars($c['titulo'] ?? 'Sem título') ?></a> · ISBN original guardado: <span class="adm-mono"><?= htmlspecialchars($c['isbn_original'] ?? '—') ?></span></li>
                    <?php endforeach; ?></ul>
                </details>
            <?php endif; ?>
            <?php if ($flash['pendentes']): ?>
                <details class="adm-isbn-details" open>
                    <summary>Continuam pendentes (<?= count($flash['pendentes']) ?>)</summary>
                    <ul><?php foreach ($flash['pendentes'] as $p): ?>
                        <li><?= $p['linha'] ? 'Linha ' . $p['linha'] . ' · ' . htmlspecialchars($p['titulo'] ?? 'Sem título') : 'Registro nº ' . $p['item_id'] ?>: <?= htmlspecialchars(implode(' ', $p['motivos'])) ?></li>
                    <?php endforeach; ?></ul>
                </details>
            <?php endif; ?>
            <p>Os livros foram criados sem ISBN, com situação "Aguardando conferência". Nenhum exemplar ou CDD foi criado.</p>
        </div>
    </section>
<?php endif; ?>

<section class="adm-kpis" aria-label="Resumo dos ISBNs inválidos">
    <?php foreach ([
        ['Pendentes com ISBN inválido', count($pendencias), 'barcode', 'red'],
        ['Podem ser importados sem ISBN', count($elegiveis), 'circle-check', 'green'],
        ['Precisam de revisão individual', count($pendencias) - count($elegiveis), 'pencil', 'amber'],
        ['Já importados sem ISBN', count($importados), 'book-plus', 'blue'],
    ] as [$titulo, $valor, $icone, $tom]): ?>
        <div class="adm-card adm-kpi">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($icone) ?></span>
            <span>
                <strong class="adm-kpi__value"><?= $fmt($valor) ?></strong>
                <span class="adm-kpi__title"><?= $titulo ?></span>
            </span>
        </div>
    <?php endforeach; ?>
</section>

<div class="adm-notice adm-notice--blue" role="note">
    <?= icon('shield-check') ?>
    <div class="adm-notice__content">
        <strong>Como funciona</strong>
        <p>Só entram no lote os registros em que o ISBN é o <em>único</em> problema. Registros com outros erros ou com possível duplicidade ficam para a revisão individual.
            O ISBN da planilha nunca é apagado: ele continua nos dados originais da importação, no registro da planilha antiga e nas observações internas do livro, para a conferência física.
            Os dígitos não são alterados automaticamente: as sugestões aparecem só quando há base confiável e só são usadas se você aplicar.
            Nenhum exemplar, código BDP ou classificação CDD é criado, e os livros já cadastrados não mudam.</p>
    </div>
</div>

<?php if ($confirmacao !== null): ?>
    <?php
    $afetados = array_values(array_filter(array_map(fn ($i) => $porId[$i] ?? null, $confirmacao), fn ($i) => $i && $i['avaliacao']['elegivel']));
    $foraDoLote = array_values(array_filter(array_map(fn ($i) => $porId[$i] ?? null, $confirmacao), fn ($i) => $i && !$i['avaliacao']['elegivel']));
    $naoEncontrados = count($confirmacao) - count($afetados) - count($foraDoLote);
    ?>
    <section class="adm-card adm-panel adm-isbn-confirm" id="confirmar" aria-labelledby="titulo-confirmar">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-confirmar"><?= icon('triangle-alert') ?> Confirmar: importar <?= $fmt(count($afetados)) ?> registro<?= count($afetados) === 1 ? '' : 's' ?> sem ISBN?</h2>
                <p>Cada registro abaixo vira um livro novo no acervo, <strong>sem ISBN</strong>, ligado à linha original da planilha. Confira a lista antes de confirmar.</p>
            </div>
        </div>
        <?php if ($afetados): ?>
            <div class="adm-table-wrap adm-table-wrap--flush">
                <table class="adm-table adm-table--cards adm-isbn-table">
                    <thead><tr><th scope="col">Linha</th><th scope="col">Título</th><th scope="col">Autor</th><th scope="col">ISBN na planilha (fica guardado)</th></tr></thead>
                    <tbody>
                        <?php foreach ($afetados as $item): $n = $item['dados_normalizados_json']; ?>
                            <tr>
                                <td data-label="Linha"><span class="adm-mono">#<?= (int)$item['numero_linha'] ?></span></td>
                                <td data-label="Título" class="adm-td-wrap"><strong><?= htmlspecialchars($n['titulo'] ?? '—') ?></strong></td>
                                <td data-label="Autor" class="adm-td-wrap"><?= htmlspecialchars($n['autor'] ?? '—') ?></td>
                                <td data-label="ISBN na planilha" class="adm-mono"><?= htmlspecialchars($item['isbn_original'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($foraDoLote || $naoEncontrados): ?>
            <p class="adm-isbn-confirm__fora"><?= icon('info') ?> <?= $fmt(count($foraDoLote) + $naoEncontrados) ?> registro(s) selecionado(s) não serão importados e continuarão pendentes:
                <?= htmlspecialchars(implode(', ', array_map(fn ($i) => 'linha ' . $i['numero_linha'], $foraDoLote))) ?><?= $naoEncontrados ? ($foraDoLote ? '; ' : '') . $naoEncontrados . ' já tratado(s) ou inexistente(s)' : '' ?>.</p>
        <?php endif; ?>
        <form method="post" action="<?= htmlspecialchars($urlPagina) ?>" class="adm-form-actions adm-form-actions--split">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="importar">
            <input type="hidden" name="confirmado" value="1">
            <?php foreach ($afetados as $item): ?><input type="hidden" name="itens[]" value="<?= (int)$item['id'] ?>"><?php endforeach; ?>
            <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars($urlPagina) ?>"><?= icon('arrow-left') ?> Voltar sem importar</a>
            <button type="submit" class="adm-button adm-button--primary" data-bloquear-envio<?= $afetados ? '' : ' disabled' ?>><?= icon('check') ?> Confirmar e importar <?= $fmt(count($afetados)) ?> sem ISBN</button>
        </form>
    </section>
<?php endif; ?>

<section class="adm-card adm-panel" id="pendentes" aria-labelledby="titulo-pendentes" data-isbn-lote>
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-pendentes">Registros pendentes com ISBN inválido</h2>
            <p>Marque os registros que podem entrar no acervo sem ISBN. Os que não forem marcados continuam pendentes, sem nenhuma alteração.</p>
        </div>
    </div>

    <?php if (!$pendencias): ?>
        <div class="adm-empty">
            <span class="adm-icon-box adm-icon-box--lg adm-tone--green"><?= icon('circle-check') ?></span>
            <strong>Nenhum registro pendente com ISBN inválido nesta importação.</strong>
        </div>
    <?php else: ?>
        <?php if (!$editavel): ?>
            <div class="adm-notice adm-notice--amber" role="note"><?= icon('lock') ?><div class="adm-notice__content"><p>Esta importação não está aberta para revisão (<?= htmlspecialchars(IMPORTACAO_STATUS[$imp['status']]['label'] ?? $imp['status']) ?>). Os registros podem ser consultados, mas não alterados.</p></div></div>
        <?php elseif (!$podeExecutar): ?>
            <p class="adm-form-actions__note"><?= icon('lock') ?> Somente a bibliotecária ou um administrador pode importar registros sem ISBN.<?= $podeCorrigir ? ' Você pode corrigir ISBNs conferidos no livro.' : '' ?></p>
        <?php endif; ?>

        <form id="form-lote" method="post" action="<?= htmlspecialchars($urlPagina) ?>#confirmar">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="preparar">
        </form>
        <div class="adm-isbn-bar">
            <?php if ($podeExecutar): ?>
                <label class="adm-isbn-bar__all"><input type="checkbox" class="adm-checkbox" data-selecionar-todos<?= $elegiveis ? '' : ' disabled' ?>> Selecionar todos os registros elegíveis (<?= count($elegiveis) ?>)</label>
                <strong data-contador aria-live="polite">0 selecionados</strong>
            <?php endif; ?>
            <div class="adm-selection-bar__actions">
                <?php if ($podeExecutar): ?>
                    <button type="submit" form="form-lote" class="adm-button adm-button--primary adm-button--sm" data-importar-selecionados disabled><?= icon('book-plus') ?> Importar selecionados sem ISBN</button>
                <?php endif; ?>
                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars($urlDetalhes . '&filtro=isbn_invalido#revisao') ?>"><?= icon('pencil') ?> Revisar individualmente</a>
            </div>
        </div>

        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards adm-isbn-table">
                <thead>
                    <tr>
                        <?php if ($podeExecutar): ?><th scope="col" class="adm-check-col"><span class="adm-visually-hidden">Selecionar</span></th><?php endif; ?>
                        <th scope="col">Linha</th>
                        <th scope="col">Livro</th>
                        <th scope="col">ISBN na planilha</th>
                        <th scope="col">Problema do ISBN</th>
                        <th scope="col">Pode importar sem ISBN?</th>
                        <th scope="col" class="adm-actions-col">Corrigir manualmente</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendencias as $item): ?>
                        <?php
                        $n = $item['dados_normalizados_json'];
                        $d = $item['diagnostico'];
                        $ok = $item['avaliacao']['elegivel'];
                        $informado = $n['isbn_informado'] ?? $item['isbn_original'];
                        ?>
                        <tr class="adm-isbn-row<?= $ok ? '' : ' adm-isbn-row--bloqueado' ?>" data-linha-isbn>
                            <?php if ($podeExecutar): ?>
                                <td class="adm-check-col">
                                    <input type="checkbox" class="adm-checkbox" form="form-lote" name="itens[]" value="<?= (int)$item['id'] ?>"
                                        <?= $ok ? 'data-elegivel' : 'disabled title="Precisa de revisão individual"' ?>
                                        aria-label="Selecionar linha <?= (int)$item['numero_linha'] ?>: <?= htmlspecialchars($n['titulo'] ?? 'sem título') ?>">
                                </td>
                            <?php endif; ?>
                            <td data-label="Linha"><span class="adm-mono">#<?= (int)$item['numero_linha'] ?></span></td>
                            <td data-label="Livro" class="adm-td-wrap">
                                <strong><?= $n['titulo'] !== null ? htmlspecialchars($n['titulo']) : '<span class="adm-missing">Sem título</span>' ?></strong>
                                <small class="adm-block-muted"><?= $n['autor'] !== null ? htmlspecialchars($n['autor']) : 'Autor não informado' ?><?= $n['editora'] ? ' · ' . htmlspecialchars($n['editora']) : '' ?><?= $n['ano_publicacao'] ? ' · ' . (int)$n['ano_publicacao'] : '' ?></small>
                            </td>
                            <td data-label="ISBN na planilha" class="adm-mono">
                                <?= htmlspecialchars($item['isbn_original'] ?? '—') ?>
                                <?php if ($informado !== $item['isbn_original']): ?><small class="adm-block-muted">corrigido para: <?= htmlspecialchars((string)$informado) ?></small><?php endif; ?>
                                <small class="adm-block-muted"><?= $d['digitos'] ?> dígito<?= $d['digitos'] === 1 ? '' : 's' ?></small>
                            </td>
                            <td data-label="Problema do ISBN" class="adm-td-wrap">
                                <span class="adm-status adm-status--soft adm-tone--red"><?= htmlspecialchars($d['rotulo']) ?></span>
                                <small class="adm-block-muted"><?= htmlspecialchars($d['descricao']) ?></small>
                                <?php foreach ($d['sugestoes'] as $s): ?>
                                    <div class="adm-isbn-sugestao adm-isbn-sugestao--<?= $s['confianca'] ?>">
                                        <span><?= icon('info') ?> Sugestão<?= $s['confianca'] === 'alta' ? '' : ' (confira no livro)' ?>: <strong class="adm-mono"><?= htmlspecialchars($s['isbn']) ?></strong></span>
                                        <small><?= htmlspecialchars($s['motivo']) ?></small>
                                        <?php if ($podeCorrigir): ?>
                                            <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-usar-sugestao="<?= htmlspecialchars($s['isbn']) ?>">Usar no campo de correção</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td data-label="Importar sem ISBN?" class="adm-td-wrap">
                                <?php if ($ok): ?>
                                    <span class="adm-status adm-status--soft adm-tone--green"><?= icon('circle-check') ?> Sim, o ISBN é o único problema</span>
                                <?php else: ?>
                                    <span class="adm-status adm-status--soft adm-tone--amber"><?= icon('pencil') ?> Revisão individual</span>
                                    <ul class="adm-isbn-motivos"><?php foreach ($item['avaliacao']['motivos'] as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>
                                <?php if ($item['revisado_em']): ?><small class="adm-block-muted">Última revisão: <?= htmlspecialchars($item['revisado_por_nome'] ?? '—') ?>, <?= importacao_data($item['revisado_em']) ?></small><?php endif; ?>
                            </td>
                            <td data-label="Corrigir manualmente" class="adm-actions-col">
                                <?php if ($podeCorrigir): ?>
                                    <form method="post" action="<?= htmlspecialchars($urlPagina) ?>" class="adm-isbn-corrigir">
                                        <?= csrf_campo() ?>
                                        <input type="hidden" name="acao" value="corrigir">
                                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                        <label class="adm-visually-hidden" for="isbn-<?= (int)$item['id'] ?>">ISBN conferido no livro (linha <?= (int)$item['numero_linha'] ?>)</label>
                                        <input class="adm-input adm-mono" id="isbn-<?= (int)$item['id'] ?>" name="isbn" inputmode="numeric" maxlength="20" placeholder="ISBN do livro" required data-campo-isbn>
                                        <button type="submit" class="adm-button adm-button--ghost adm-button--sm" data-bloquear-envio><?= icon('save') ?> Salvar ISBN</button>
                                    </form>
                                <?php endif; ?>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars($revisarUrl($item)) ?>"><?= icon('eye') ?> Revisar linha</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($importados): ?>
<section class="adm-card adm-panel" id="importados" aria-labelledby="titulo-importados">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-importados">Importados sem ISBN</h2>
            <p>Livros que entraram no acervo sem ISBN. O valor da planilha fica aqui para a conferência física; corrija o ISBN no cadastro do livro depois de conferir.</p>
        </div>
    </div>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards adm-isbn-table">
            <thead><tr><th scope="col">Linha</th><th scope="col">Livro no acervo</th><th scope="col">ISBN na planilha</th><th scope="col">Importado por</th><th scope="col">Quando</th></tr></thead>
            <tbody>
                <?php foreach ($importados as $item): ?>
                    <tr>
                        <td data-label="Linha"><span class="adm-mono">#<?= (int)$item['numero_linha'] ?></span></td>
                        <td data-label="Livro" class="adm-td-wrap"><a class="adm-link" href="<?= htmlspecialchars($livroUrl((int)$item['livro_resultante_id'])) ?>"><?= htmlspecialchars($item['livro_resultante_titulo'] ?? 'Livro nº ' . (int)$item['livro_resultante_id']) ?></a></td>
                        <td data-label="ISBN na planilha" class="adm-mono"><?= htmlspecialchars($item['isbn_original']) ?></td>
                        <td data-label="Importado por"><?= htmlspecialchars($item['revisado_por_nome'] ?? '—') ?></td>
                        <td data-label="Quando"><?= importacao_data($item['processado_em']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php
admin_page_end(['assets/js/isbn-lote.js?v=20261030']);
