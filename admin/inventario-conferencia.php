<?php
/**
 * Conferência do inventário em andamento.
 *
 * - O campo de leitura aceita o leitor USB de código de barras (que "digita" o código e
 *   aperta Enter) ou a digitação do código BDP. Texto que não é código pesquisa livros
 *   por título, autor ou ISBN.
 * - Código conferido de novo: aviso com os dados anteriores; nada é gravado de novo.
 *   A correção é uma ação separada (inventario.gerenciar), com motivo.
 * - Código desconhecido não cria nada. Exemplar novo só com o botão "Cadastrar exemplar"
 *   e a confirmação de que o livro está em mãos.
 * - "Modo rápido" confere na leitura, com o estado já cadastrado e a localização em conferência.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ConferenciaDuplicadaException;

$usuario = fisico_iniciar('inventario.conferir');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$base = admin_url('inventario-conferencia') . '?id=' . $id;
$podeGerenciar = auth_pode('inventario.gerenciar');
$podeCadastrar = auth_pode('exemplares.gerenciar');
$sessao = $_SESSION['inventario_conferencia'][$id] ?? ['local' => null, 'rapido' => false];
$erro = null;
$fx = [];

/** Mensagem curta do resultado de uma conferência. */
function conferencia_mensagem(array $item): array
{
    $divs = inventario_divergencias($item);
    if (!$divs) {
        return ['sucesso', $item['codigo_individual'] . ' conferido.', $item['titulo']];
    }
    return ['aviso', $item['codigo_individual'] . ' conferido com divergência.', implode(' · ', $divs) . ($item['decisao'] ? ' · Cadastro atualizado.' : ' · A decisão fica pendente no inventário.')];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    try {
        switch ($acao) {
            case 'local':
                $local = (int)admin_param($_POST, 'local_conferencia') ?: null;
                $_SESSION['inventario_conferencia'][$id] = ['local' => $local, 'rapido' => admin_param($_POST, 'rapido') === '1'];
                admin_flash('sucesso', 'Preferências da conferência salvas.', $local ? 'A localização escolhida vem preenchida em cada leitura.' : 'Nenhuma localização em conferência.');
                admin_redirecionar($base);
            case 'ler':
                $texto = trim(mb_substr(admin_param($_POST, 'leitura'), 0, 100));
                if ($texto === '') {
                    admin_redirecionar($base);
                }
                if (!EmprestimoRepository::codigoExemplar($texto)) {
                    admin_redirecionar($base . '&q=' . rawurlencode($texto));
                }
                $ex = exemplares_repo()->porCodigo($texto);
                if ($ex && $sessao['rapido']) {
                    $item = inventario_servico()->conferir($id, (int)$ex['id'], ['estado_conservacao' => $ex['estado_conservacao'], 'localizacao_id' => $sessao['local']], $usuario['id'], false);
                    admin_flash(...conferencia_mensagem($item));
                    admin_redirecionar($base);
                }
                admin_redirecionar($base . '&codigo=' . rawurlencode($texto));
            case 'conferir':
                $item = inventario_servico()->conferir($id, (int)admin_param($_POST, 'exemplar_id'), [
                    'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                    'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                    'observacao' => admin_param($_POST, 'observacao'),
                    'aplicar' => admin_param($_POST, 'aplicar') === '1',
                ], $usuario['id'], $podeGerenciar);
                admin_flash(...conferencia_mensagem($item));
                admin_redirecionar($base);
            case 'corrigir':
                fisico_exigir('inventario.gerenciar');
                $item = inventario_servico()->corrigir((int)admin_param($_POST, 'item_id'), [
                    'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                    'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                    'observacao' => admin_param($_POST, 'observacao'),
                ], $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Conferência de ' . $item['codigo_individual'] . ' corrigida.', 'A correção ficou registrada no histórico, com o motivo.');
                admin_redirecionar($base);
            case 'cadastrar':
                fisico_exigir('exemplares.gerenciar');
                $fx = fisico_dados_exemplar($_POST);
                if (admin_param($_POST, 'confirmar') !== '1') {
                    throw new AcervoException('Marque a confirmação de que o exemplar está fisicamente com você.');
                }
                $dados = $fx;
                $dados['localizacao_id'] = fisico_resolver_local($fx, $usuario['id']);
                $criados = inventario_servico()->cadastrarExemplar($id, (int)admin_param($_POST, 'livro_id'), $dados, $usuario['id'], (int)$fx['quantidade']);
                admin_flash('sucesso', count($criados) === 1 ? 'Exemplar cadastrado e conferido: ' . $criados[0]['codigo'] . '.' : count($criados) . ' exemplares cadastrados e conferidos: ' . implode(', ', array_column($criados, 'codigo')) . '.',
                    'Identifique o livro com o código (etiqueta). As etiquetas podem ser impressas depois em Etiquetas.');
                admin_redirecionar($base . '&criados=' . implode(',', array_column($criados, 'id')));
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (ConferenciaDuplicadaException $e) {
        admin_flash('aviso', 'Leitura repetida: nada foi gravado de novo.', $e->getMessage());
        admin_redirecionar($base . '&codigo=' . rawurlencode((string)($e->item['codigo_individual'] ?? '')));
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    } catch (PDOException $e) {
        $erro = fisico_erro_tecnico($e, 'conferência do inventário');
    }
}

try {
    $inv = inventario_repo()->buscar($id);
    if (!$inv) {
        http_response_code(404);
        admin_pagina_erro('Inventário não encontrado', 'Confira o endereço e tente novamente.', 'clipboard-check');
        exit;
    }
    $codigoLido = mb_substr(admin_param($_GET, 'codigo'), 0, 30);
    $q = mb_substr(admin_param($_GET, 'q'), 0, 100);
    $livroId = (int)($_POST['livro_id'] ?? $_GET['livro'] ?? 0);
    $exemplar = $codigoLido !== '' ? exemplares_repo()->porCodigo($codigoLido) : null;
    $itemAnterior = $exemplar ? inventario_repo()->itemPorExemplar($id, (int)$exemplar['id']) : null;
    $busca = $q !== '' ? inventario_repo()->pesquisarLivros($id, $q) : null;
    $livroCadastro = $livroId && $podeCadastrar ? inventario_repo()->livroParaCadastro($livroId) : null;
    $resumo = inventario_repo()->resumo($id);
    $recentes = inventario_repo()->recentes($id, 8);
    $locais = localizacoes_servico()->ativas();
    $criadosIds = array_filter(array_map('intval', explode(',', admin_param($_GET, 'criados'))));
    $criados = $criadosIds ? exemplares_repo()->paraEtiquetas($criadosIds) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a conferência', fisico_erro_tecnico($e, 'conferência do inventário'));
    exit;
}
$emAndamento = $inv['status'] === 'em_andamento';
$localSessao = $sessao['local'];

admin_page_start('inventario', 'Conferência: ' . $inv['titulo'], 'Inventário', 'Conferência digital do acervo físico', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], [$inv['titulo'], admin_url('inventario-detalhes') . '?id=' . $id], ['Conferência']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Conferência <?= fisico_badge_inventario($inv['status']) ?></h1>
        <p><?= e($inv['titulo']) ?> · <?= fisico_numero($resumo['previstos_conferidos']) ?> de <?= fisico_numero($resumo['previstos']) ?> previstos conferidos · <?= fisico_numero($resumo['divergencias']) ?> divergência(s) · <?= fisico_numero($resumo['cadastrados']) ?> cadastrado(s) agora</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('inventario-detalhes') . '?id=' . $id) ?>"><?= icon('list-checks') ?> Ver itens e pendências</a>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($erro): ?><?= admin_aviso('erro', 'Nada foi gravado.', $erro) ?><?php endif; ?>

<?php if (!$emAndamento): ?>
    <?= admin_aviso('info', 'Este inventário não está em andamento.', $inv['status'] === 'pausado' ? 'Ele está pausado. Retome-o na página do inventário para continuar a conferência.' : 'A conferência só é possível com o inventário em andamento.') ?>
<?php else: ?>

<?php if ($criados): ?>
    <section class="adm-card adm-panel adm-criados" aria-label="Exemplares cadastrados agora">
        <ul class="adm-code-list">
            <?php foreach ($criados as $c): ?><li><a class="adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$c['id']) ?>"><?= e($c['codigo_individual']) ?></a></li><?php endforeach; ?>
        </ul>
        <?php if (auth_pode('etiquetas.gerar')): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('etiquetas') . '?ids=' . implode(',', array_column($criados, 'id'))) ?>"><?= icon('printer') ?> Imprimir etiqueta(s)</a><?php endif; ?>
    </section>
<?php endif; ?>

<div class="adm-conferencia">
    <div class="adm-conferencia__main">
        <section class="adm-card adm-panel adm-leitura" aria-labelledby="titulo-leitura">
            <form method="post" action="<?= e($base) ?>" class="adm-leitura__form" autocomplete="off">
                <?= csrf_campo() ?>
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="ler">
                <label for="leitura" id="titulo-leitura" class="adm-leitura__label"><?= icon('scan-barcode') ?> Leia o código de barras ou digite o código BDP</label>
                <div class="adm-leitura__linha">
                    <input class="adm-input adm-leitura__campo" type="text" id="leitura" name="leitura" maxlength="100" placeholder="BDP-000001, título, autor ou ISBN" autofocus data-scanner aria-describedby="leitura-dica">
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('search') ?> Ler</button>
                </div>
                <p class="adm-field__hint" id="leitura-dica">
                    <?= $sessao['rapido'] ? '<strong>Modo rápido ligado:</strong> cada código lido é conferido na hora, com o estado já cadastrado e a localização em conferência.' : 'Depois da leitura, confira o estado e a localização e confirme.' ?>
                    O campo volta a ficar pronto para a próxima leitura.
                </p>
            </form>
        </section>

        <?php if ($codigoLido !== ''): ?>
            <section class="adm-card adm-panel" aria-labelledby="titulo-lido">
                <?php if (!$exemplar): ?>
                    <?= admin_aviso('erro', 'Código ' . (EmprestimoRepository::codigoExemplar($codigoLido) ?? $codigoLido) . ' não encontrado.', 'Nenhum exemplar tem este código. Nada foi criado. Se o livro está em mãos e não tem código, pesquise pelo título e use "Cadastrar exemplar".') ?>
                <?php elseif ($itemAnterior && $itemAnterior['conferido_em'] !== null): ?>
                    <div class="adm-panel__header"><div><h2 id="titulo-lido"><span class="adm-code"><?= e($exemplar['codigo_individual']) ?></span> já conferido neste inventário</h2>
                        <p><?= e($exemplar['titulo']) ?></p></div></div>
                    <?= admin_aviso('aviso', 'Leitura repetida: nada foi gravado de novo.', 'Conferido em ' . admin_data_hora($itemAnterior['conferido_em']) . ($itemAnterior['conferido_por_nome'] ? ' por ' . $itemAnterior['conferido_por_nome'] : '') . '.') ?>
                    <dl class="adm-meta adm-meta--boxed adm-meta--grid">
                        <div><dt>Localização encontrada</dt><dd><?= e(fisico_local($itemAnterior, 'encontrada_')) ?></dd></div>
                        <div><dt>Estado encontrado</dt><dd><?= e(ACERVO_CONSERVACAO[$itemAnterior['estado_encontrado']] ?? '—') ?></dd></div>
                        <div><dt>Observação</dt><dd><?= $itemAnterior['observacao'] ? e($itemAnterior['observacao']) : '—' ?></dd></div>
                        <div><dt>Situação</dt><dd><?= e(inventario_item_situacao($itemAnterior)[0]) ?></dd></div>
                    </dl>
                    <?php if ($podeGerenciar): ?>
                        <details class="adm-acao">
                            <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('pencil') ?> Corrigir esta conferência</summary>
                            <form method="post" action="<?= e($base) ?>" class="adm-acao__form">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="corrigir"><input type="hidden" name="item_id" value="<?= (int)$itemAnterior['id'] ?>">
                                <div class="adm-form-grid">
                                    <label class="adm-field"><span class="adm-field__sublabel">Estado encontrado</span><?= fisico_select_conservacao('estado_conservacao', (string)$itemAnterior['estado_encontrado']) ?></label>
                                    <label class="adm-field"><span class="adm-field__sublabel">Localização encontrada</span><?= fisico_select_local('localizacao_id', $itemAnterior['localizacao_encontrada_id'] !== null ? (int)$itemAnterior['localizacao_encontrada_id'] : null, 'Manter a registrada', '', $locais) ?></label>
                                    <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Observação</span><input class="adm-input" name="observacao" maxlength="500" value="<?= e((string)$itemAnterior['observacao']) ?>"></label>
                                    <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Motivo da correção *</span><input class="adm-input" name="motivo" maxlength="500" required></label>
                                </div>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Salvar correção</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <p class="adm-field__hint">Se a conferência anterior estiver errada, peça a correção a quem gerencia o inventário.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="adm-panel__header"><div>
                        <h2 id="titulo-lido"><span class="adm-code"><?= e($exemplar['codigo_individual']) ?></span> · <?= e($exemplar['titulo']) ?></h2>
                        <p><?= e($exemplar['autores'] ?: 'Autor não informado') ?> · cadastrado em <?= e(fisico_local($exemplar)) ?> · <?= acervo_badge_situacao($exemplar['situacao']) ?><?= !$itemAnterior ? ' · <strong>fora da lista prevista</strong>' : '' ?></p>
                    </div></div>
                    <?php if ($exemplar['situacao'] === 'baixado'): ?>
                        <?= admin_aviso('aviso', 'Este exemplar foi baixado.', 'A conferência fica registrada como divergência de situação. Exemplares baixados não voltam ao acervo.') ?>
                    <?php elseif ($exemplar['emprestimo_aberto']): ?>
                        <?= admin_aviso('aviso', 'Este exemplar consta como emprestado.', 'A conferência registra a divergência. O inventário não encerra empréstimos: registre a devolução em Empréstimos, se for o caso.') ?>
                    <?php elseif ($exemplar['situacao'] === 'extraviado'): ?>
                        <?= admin_aviso('aviso', 'Este exemplar estava registrado como extraviado.', 'A conferência registra a divergência; a reativação depende da decisão no inventário.') ?>
                    <?php endif; ?>
                    <form method="post" action="<?= e($base) ?>" class="adm-form-section" data-loading-form>
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="conferir"><input type="hidden" name="exemplar_id" value="<?= (int)$exemplar['id'] ?>">
                        <div class="adm-form-grid">
                            <label class="adm-field"><span class="adm-field__label">Estado encontrado *</span><?= fisico_select_conservacao('estado_conservacao', $exemplar['estado_conservacao']) ?></label>
                            <label class="adm-field"><span class="adm-field__label">Localização encontrada</span>
                                <?= fisico_select_local('localizacao_id', $localSessao ?? ($exemplar['localizacao_id'] !== null ? (int)$exemplar['localizacao_id'] : null), $exemplar['localizacao_id'] ? 'A mesma do cadastro' : 'Não informar agora', '', $locais) ?>
                                <span class="adm-field__hint"><?= $exemplar['localizacao_id'] ? 'Se for diferente do cadastro, fica registrada como divergência.' : 'O exemplar não tem localização: a escolhida passa a ser a confirmada.' ?></span></label>
                            <label class="adm-field adm-field--full"><span class="adm-field__label">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: capa solta, estava fora de ordem"></label>
                            <?php if ($podeGerenciar): ?>
                                <label class="adm-check-confirm adm-field--full"><input type="checkbox" name="aplicar" value="1"> <span>Se houver diferença de estado ou localização, já atualizar o cadastro deste exemplar (decisão registrada agora).</span></label>
                            <?php endif; ?>
                        </div>
                        <button class="adm-button adm-button--primary" type="submit" autofocus><?= icon('square-check') ?> Confirmar conferência</button>
                        <a class="adm-button adm-button--ghost" href="<?= e($base) ?>">Cancelar</a>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($busca !== null): ?>
            <section class="adm-card adm-panel" aria-labelledby="titulo-busca">
                <div class="adm-panel__header"><div><h2 id="titulo-busca">Livros encontrados para “<?= e($q) ?>”</h2>
                    <p>Escolha o exemplar que está em mãos. Se ele ainda não tem código, cadastre um exemplar novo.</p></div></div>
                <?php if (!$busca['livros']): ?>
                    <div class="adm-empty adm-empty--left"><?= icon('book-x') ?><strong>Nenhum livro encontrado.</strong><span>Tente outra palavra do título, o sobrenome do autor ou o ISBN. Livros sem registro bibliográfico precisam ser cadastrados primeiro em Acervo.</span></div>
                <?php else: ?>
                    <ul class="adm-resultados adm-resultados--livros">
                        <?php foreach ($busca['livros'] as $l): ?>
                            <li>
                                <div>
                                    <strong><?= e($l['titulo']) ?></strong>
                                    <small><?= e($l['autores'] ?: 'Autor não informado') ?><?= $l['isbn'] ? ' · ISBN ' . e($l['isbn']) : '' ?><?= $l['edicao'] ? ' · ' . e($l['edicao']) : '' ?></small>
                                    <small class="adm-table__muted">Planilha: <?= $l['quantidade_planilha'] !== null ? (int)$l['quantidade_planilha'] : '—' ?> (só referência) · Físicos cadastrados: <?= count($l['exemplares']) ?><?= $l['localizacao_antiga'] ? ' · Local antigo: ' . e($l['localizacao_antiga']) : '' ?></small>
                                    <?php if ($l['exemplares']): ?>
                                        <div class="adm-exemplares-chips">
                                            <?php foreach ($l['exemplares'] as $ex): ?>
                                                <?php if ($ex['conferido_em']): ?>
                                                    <span class="adm-chip adm-chip--ok" title="Já conferido neste inventário"><?= icon('check') ?> <?= e($ex['codigo_individual']) ?></span>
                                                <?php else: ?>
                                                    <a class="adm-chip" href="<?= e($base . '&codigo=' . rawurlencode($ex['codigo_individual'])) ?>"><?= e($ex['codigo_individual']) ?> · <?= e(Biblioteca\Services\ExemplarService::rotuloSituacao($ex['situacao'])) ?></a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($podeCadastrar): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&livro=' . (int)$l['id'] . '&q=' . rawurlencode($q)) ?>#cadastro"><?= icon('plus') ?> Cadastrar exemplar</a><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($busca['mais']): ?><p class="adm-field__hint">Há mais resultados. Refine a pesquisa.</p><?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($livroCadastro): ?>
            <section class="adm-card adm-panel" id="cadastro" aria-labelledby="titulo-cadastro">
                <div class="adm-panel__header"><div><h2 id="titulo-cadastro">Cadastrar exemplar novo · <?= e($livroCadastro['titulo']) ?></h2>
                    <p>Use só para o livro que está em mãos e ainda não tem código. O exemplar é cadastrado e já fica conferido neste inventário. A quantidade da planilha não é usada.</p></div></div>
                <form method="post" action="<?= e($base) ?>" class="adm-form-section" data-loading-form>
                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="cadastrar"><input type="hidden" name="livro_id" value="<?= (int)$livroCadastro['id'] ?>">
                    <?php $fx += ['localizacao_id' => (string)($localSessao ?? '')]; $livroForm = $livroCadastro; include __DIR__ . '/includes/form-exemplar.php'; ?>
                    <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required> <span>Estou com o(s) exemplar(es) em mãos e quero gerar o(s) código(s) BDP.</span></label>
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('book-plus') ?> Cadastrar e conferir</button>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <aside class="adm-conferencia__side">
        <section class="adm-card adm-panel adm-form-section" aria-labelledby="titulo-preferencias">
            <h2 id="titulo-preferencias" class="adm-panel__title">Localização em conferência</h2>
            <form method="post" action="<?= e($base) ?>">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="local">
                <label class="adm-field"><span class="adm-field__sublabel">Estante ou prateleira que está sendo conferida</span>
                    <?= fisico_select_local('local_conferencia', $localSessao, 'Nenhuma (escolher em cada leitura)', 'local-conferencia', $locais) ?></label>
                <label class="adm-check-confirm"><input type="checkbox" name="rapido" value="1"<?= $sessao['rapido'] ? ' checked' : '' ?>> <span>Modo rápido: conferir na leitura, sem abrir o formulário</span></label>
                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('save') ?> Aplicar</button>
            </form>
            <?php if (!$locais): ?><p class="adm-field__hint">Nenhuma localização cadastrada. <a class="adm-link" href="<?= e(admin_url('localizacoes')) ?>">Cadastrar localizações</a></p><?php endif; ?>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-recentes">
            <h2 id="titulo-recentes" class="adm-panel__title">Últimas conferências</h2>
            <?php if (!$recentes): ?>
                <p class="adm-field__hint">Nenhuma conferência registrada ainda.</p>
            <?php else: ?>
                <ol class="adm-recentes">
                    <?php foreach ($recentes as $r): ?>
                        <?php [$rot, $tom] = inventario_item_situacao($r); ?>
                        <li><a class="adm-code" href="<?= e($base . '&codigo=' . rawurlencode($r['codigo_individual'])) ?>"><?= e($r['codigo_individual']) ?></a>
                            <span class="adm-status adm-tone--<?= $tom ?>"><?= e($rot) ?></span>
                            <small class="adm-block-muted"><?= e(mb_strimwidth($r['titulo'], 0, 60, '…')) ?> · <?= admin_data_hora($r['conferido_em']) ?></small></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </aside>
</div>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
