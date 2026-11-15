<?php
/**
 * Movimentação em lote: muda a localização de vários exemplares numa única operação.
 *
 * Passos: 1. pesquisar/filtrar (origem, lote, triagem...) e selecionar; 2. ver os selecionados e
 * escolher a estante e a prateleira de destino; 3. revisar o total e o destino; 4. confirmar.
 * A gravação é UMA transação (OperacaoService): ou todos os exemplares mudam, ou nenhum. Cada
 * exemplar ganha uma linha no histórico (código, localização anterior, nova, responsável, data e o
 * número da operação). A chave do formulário impede gravar duas vezes (clique duplo/reenvio).
 * "Desfazer" fica em Ações recentes, enquanto for seguro.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';
require_once __DIR__ . '/includes/backup.php';

use Biblioteca\Repositories\PreparoRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\OperacaoService;

$usuario = fisico_iniciar('exemplares.gerenciar');
$base = admin_url('movimentacao-lote');
const MOV_LOTE_MAXIMO = 500;

$origens = organizacao_repo()->opcoesLocal(null);
$porPagina = (int)admin_param($_GET, 'por_pagina', '100');
$filtros = [
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
    'origem' => preg_match('/^(triagem|e\d+|p\d+)$/', admin_param($_GET, 'origem')) ? admin_param($_GET, 'origem') : '',
    'lote' => (int)admin_param($_GET, 'lote') > 0 ? (string)(int)admin_param($_GET, 'lote') : '',
    'carimbo' => in_array(admin_param($_GET, 'carimbo'), ['aguardando', 'carimbado'], true) ? admin_param($_GET, 'carimbo') : 'todos',
    'por_pagina' => in_array($porPagina, [50, 100, 200], true) ? (string)$porPagina : '100',
];

/** Filtros da origem no formato do PreparoRepository. */
function mov_consulta(array $f): array
{
    $c = ['carimbo' => $f['carimbo'], 'organizacao' => 'todos', 'lote' => $f['lote'], 'q' => $f['q']];
    if ($f['origem'] === 'triagem') {
        $c['organizacao'] = 'aguardando';
    } elseif (str_starts_with($f['origem'], 'e')) {
        $c['estante'] = (int)substr($f['origem'], 1);
    } elseif (str_starts_with($f['origem'], 'p')) {
        $c['localizacao'] = (int)substr($f['origem'], 1);
    }
    return $c;
}

$etapa = 'selecao';
$selecionados = [];
$destino = null;
$motivo = '';
$erro = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    $postFiltros = array_intersect_key($_POST, $filtros);
    $filtros = array_merge($filtros, array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 100) : '', $postFiltros));
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []), fn ($i) => $i > 0)));
    if (admin_param($_POST, 'todos') === '1') {
        $ids = preparo_repo()->idsPlanilha(mov_consulta($filtros), MOV_LOTE_MAXIMO + 1);
    }
    $destino = (int)admin_param($_POST, 'destino_id') ?: null;
    $motivo = mb_substr(admin_param($_POST, 'motivo'), 0, 200);
    try {
        if (!$ids) {
            throw new AcervoException('Selecione pelo menos um exemplar.');
        }
        if (count($ids) > MOV_LOTE_MAXIMO) {
            throw new AcervoException('Selecione no máximo ' . MOV_LOTE_MAXIMO . ' exemplares por movimentação. Filtre por prateleira ou lote e faça em partes.');
        }
        $selecionados = preparo_repo()->planilha(['carimbo' => 'todos', 'organizacao' => 'todos', 'ids' => $ids], 1, MOV_LOTE_MAXIMO)['itens'];
        if (!$selecionados) {
            throw new AcervoException('Nenhum dos exemplares escolhidos pode ser movido (baixados não mudam de localização).');
        }
        if ($acao === 'previa') {
            $etapa = 'destino';
        } elseif ($acao === 'revisar' || $acao === 'confirmar') {
            if ($destino === null) {
                $etapa = 'destino';
                throw new AcervoException('Escolha a estante e a prateleira de destino.');
            }
            exemplares_servico()->localizacaoValida($destino);
            $etapa = 'revisar';
            if ($acao === 'confirmar') {
                if (admin_param($_POST, 'confirmo') !== '1') {
                    throw new AcervoException('Marque a confirmação para mover os exemplares.');
                }
                if (admin_param($_POST, 'fazer_backup') === '1') {
                    backup_antes_de($usuario, 'Antes da movimentação em lote de ' . count($selecionados) . ' exemplares');
                }
                $idsMover = array_map(fn ($x) => (int)$x['id'], $selecionados);
                $destinoTexto = fisico_local_id($destino);
                $op = operacoes_servico()->realizar('movimentacao_lote', 'Movimentação em lote: ' . count($idsMover) . ' exemplar(es) para ' . $destinoTexto, $usuario['id'],
                    function () use ($idsMover, $destino, $usuario, $motivo) {
                        $movidos = 0;
                        sort($idsMover);
                        foreach ($idsMover as $exId) {
                            $movidos += exemplares_servico()->transferir($exId, $destino, $usuario['id'], 'movimentação em lote' . ($motivo !== '' ? ': ' . $motivo : '')) ? 1 : 0;
                        }
                        return $movidos;
                    }, admin_param($_POST, 'chave'), ['destino' => $destino, 'selecionados' => count($idsMover)]);
                $jaEstavam = count($idsMover) - (int)$op['resultado'];
                admin_flash('sucesso', fisico_numero((int)$op['resultado']) . ' exemplar(es) movido(s) para ' . $destinoTexto . '.',
                    'Operação nº ' . $op['id'] . '. O histórico de cada exemplar registrou a mudança.' . ($jaEstavam ? ' ' . $jaEstavam . ' já estavam nesse destino e não mudaram.' : '')
                    . ' Errou? Use "Desfazer" em Ações recentes.');
                admin_redirecionar(admin_url('acoes-recentes') . '?operacao=' . $op['id']);
            }
        }
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
        if (!$selecionados) {
            $etapa = 'selecao';
        }
    } catch (PDOException $e) {
        $erro = fisico_erro_tecnico($e, 'movimentação em lote');
    }
}

$resultado = null;
if ($etapa === 'selecao') {
    try {
        $resultado = preparo_repo()->planilha(mov_consulta($filtros) + ['ordem' => 'lote'], max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
        $lotes = preparo_repo()->lotes(200);
    } catch (PDOException $e) {
        http_response_code(500);
        admin_pagina_erro('Não foi possível carregar os exemplares', fisico_erro_tecnico($e, 'movimentação em lote') . ' Confira se a migração 150 foi aplicada.');
        exit;
    }
}
$chave = admin_param($_POST, 'chave') !== '' && OperacaoService::chaveValida(admin_param($_POST, 'chave')) ? admin_param($_POST, 'chave') : OperacaoService::novaChave();
$filtrosLink = array_filter($filtros, fn ($v) => $v !== '' && $v !== 'todos');
$podeBackup = auth_pode('sistema.manutencao');

/** Campos ocultos que carregam a seleção e os filtros de um passo para o outro. */
$ocultos = function () use ($selecionados, $filtros, $chave): string {
    $h = csrf_campo() . '<input type="hidden" name="chave" value="' . e($chave) . '">';
    foreach ($selecionados as $x) {
        $h .= '<input type="hidden" name="ids[]" value="' . (int)$x['id'] . '">';
    }
    foreach ($filtros as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    }
    return $h;
};

admin_page_start('movimentacao-lote', 'Movimentação em lote', 'Organização Física', 'Mover vários exemplares de uma vez', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Organização Física', admin_url('organizacao-fisica')], ['Movimentação em lote']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Movimentação em lote</h1>
        <p>Selecione vários exemplares e mude a localização de todos numa única operação: ou todos mudam, ou nenhum. Use também para tirar um lote inteiro da área de triagem e colocá-lo na estante.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('acoes-recentes')) ?>"><?= icon('undo-2') ?> Ações recentes</a>
    </div>
</section>

<ol class="adm-passos" aria-label="Passos">
    <?php foreach (['selecao' => 'Selecionar exemplares', 'destino' => 'Escolher o destino', 'revisar' => 'Revisar e confirmar'] as $k => $r): ?>
        <li class="<?= $etapa === $k ? 'is-atual' : '' ?>"<?= $etapa === $k ? ' aria-current="step"' : '' ?>><?= e($r) ?></li>
    <?php endforeach; ?>
</ol>

<?= admin_flash_html() ?>
<?php if ($erro): ?><?= admin_aviso('erro', 'Nada foi movido.', $erro) ?><?php endif; ?>

<?php if ($etapa === 'selecao'): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-selecao">
        <div class="adm-panel__header"><div><h2 id="titulo-selecao">1. Pesquise e selecione</h2><p aria-live="polite"><?= fisico_numero($resultado['total']) ?> exemplar(es) com estes filtros</p></div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span>
                <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Código BDP, título ou autor..." autocomplete="off"></label>
            <div class="adm-filters">
                <label class="adm-filter"><span>Onde estão agora</span><select class="adm-select" name="origem">
                    <option value="">Qualquer localização</option>
                    <option value="triagem"<?= $filtros['origem'] === 'triagem' ? ' selected' : '' ?>>Área de triagem (aguardando organização)</option>
                    <?php $estantesVistas = []; foreach ($origens as $o): if ($o['estante_id'] === null) { continue; } $eid = (int)$o['estante_id']; ?>
                        <?php if (!isset($estantesVistas[$eid])): $estantesVistas[$eid] = true; ?>
                            <option value="e<?= $eid ?>"<?= $filtros['origem'] === 'e' . $eid ? ' selected' : '' ?>><?= e(fisico_rotulo_estante($o, false)) ?> (estante inteira)</option>
                        <?php endif; ?>
                        <option value="p<?= (int)$o['id'] ?>"<?= $filtros['origem'] === 'p' . (int)$o['id'] ? ' selected' : '' ?>>&nbsp;&nbsp;<?= e(fisico_rotulo_estante($o, false) . ' · ' . fisico_rotulo_prateleira($o)) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="adm-filter"><span>Lote</span><select class="adm-select" name="lote"><option value="">Todos</option>
                    <?php foreach ($lotes as $lt): ?><option value="<?= (int)$lt['id'] ?>"<?= $filtros['lote'] === (string)$lt['id'] ? ' selected' : '' ?>><?= e(PreparoRepository::rotuloLote($lt)) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Carimbo</span><select class="adm-select" name="carimbo">
                    <?php foreach (PreparoRepository::FILTRO_CARIMBO as $v => $r): ?><option value="<?= e($v) ?>"<?= $filtros['carimbo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            </div>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Filtrar</span></button>
        </form>
        <form method="post" action="<?= e($base) ?>" data-selecao>
            <?= csrf_campo() ?><input type="hidden" name="acao" value="previa">
            <?php foreach ($filtros as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
            <div class="adm-selecao-barra">
                <span data-selecao-total aria-live="polite">Nenhum exemplar selecionado</span>
                <button type="submit" class="adm-button adm-button--primary adm-button--sm" data-selecao-enviar disabled><?= icon('arrow-right') ?> Continuar com os selecionados</button>
                <?php if ($resultado['total'] > 0 && $resultado['total'] <= MOV_LOTE_MAXIMO): ?>
                    <button type="submit" name="todos" value="1" class="adm-button adm-button--ghost adm-button--sm"><?= icon('check-check') ?> Continuar com todos os <?= fisico_numero($resultado['total']) ?> do filtro</button>
                <?php elseif ($resultado['total'] > MOV_LOTE_MAXIMO): ?>
                    <span class="adm-field__hint">Mais de <?= MOV_LOTE_MAXIMO ?> resultados: filtre por prateleira ou lote para mover em partes.</span>
                <?php endif; ?>
            </div>
            <div class="adm-table-wrap adm-table-wrap--flush">
                <table class="adm-table adm-table--cards adm-table--compacta">
                    <thead><tr><th scope="col" class="adm-check-col"><input type="checkbox" data-selecao-todos aria-label="Selecionar todos desta página"></th><th scope="col">Código BDP</th><th scope="col">Obra</th><th scope="col">Localização atual</th><th scope="col">Lote</th><th scope="col">Carimbo</th></tr></thead>
                    <tbody>
                    <?php foreach ($resultado['itens'] as $x): ?>
                        <tr>
                            <td class="adm-check-col"><input type="checkbox" name="ids[]" value="<?= (int)$x['id'] ?>" data-selecao-item aria-label="Selecionar <?= e($x['codigo_individual']) ?>"></td>
                            <td data-label="Código BDP"><span class="adm-code"><?= e($x['codigo_individual']) ?></span></td>
                            <td class="adm-td-title" data-label="Obra"><strong><?= e($x['titulo'] ?? 'Sem título') ?></strong><small class="adm-block-muted"><?= e($x['autores'] ?: 'Autor não informado') ?></small></td>
                            <td data-label="Localização atual"><?= e(fisico_local_ou_triagem($x)) ?></td>
                            <td data-label="Lote"><?= $x['lote_numero'] !== null ? e(str_pad((string)$x['lote_numero'], 3, '0', STR_PAD_LEFT)) . ' · nº ' . (int)$x['lote_ordem'] : '—' ?></td>
                            <td data-label="Carimbo"><?= fisico_badge_carimbo($x['carimbo_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$resultado['itens']): ?><tr><td colspan="6"><div class="adm-empty"><?= icon('search') ?><strong>Nenhum exemplar encontrado.</strong><span>Mude os filtros.</span></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtrosLink, [50, 100, 200]) ?>
    </section>
<?php else: ?>
    <?php
    $origemResumo = [];
    foreach ($selecionados as $x) {
        $t = fisico_local_ou_triagem($x);
        $origemResumo[$t] = ($origemResumo[$t] ?? 0) + 1;
    }
    ?>
    <div class="adm-org-colunas">
        <section class="adm-card adm-panel" aria-labelledby="titulo-destino">
            <?php if ($etapa === 'destino'): ?>
                <h2 id="titulo-destino" class="adm-panel__title"><?= icon('map-pin') ?> 2. Para onde vão os <?= fisico_numero(count($selecionados)) ?> exemplares?</h2>
                <form method="post" action="<?= e($base) ?>" data-enviar-uma-vez>
                    <?= $ocultos() ?><input type="hidden" name="acao" value="revisar">
                    <fieldset class="adm-field adm-escolha"><legend class="adm-field__sublabel">Nova estante e prateleira</legend>
                        <?= fisico_campo_local('destino_id', $destino, 'Escolha a prateleira de destino', 'mov-destino') ?></fieldset>
                    <label class="adm-field"><span class="adm-field__sublabel">Motivo <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="motivo" maxlength="200" value="<?= e($motivo) ?>" placeholder="Ex.: reorganização da estante C"></label>
                    <div class="adm-form-actions">
                        <a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($base, $filtrosLink)) ?>"><?= icon('arrow-left') ?> Voltar à seleção</a>
                        <button class="adm-button adm-button--primary" type="submit"><?= icon('arrow-right') ?> Revisar movimentação</button>
                    </div>
                </form>
            <?php else: ?>
                <h2 id="titulo-destino" class="adm-panel__title"><?= icon('check-check') ?> 3. Confirme a movimentação</h2>
                <dl class="adm-mov-resumo">
                    <div><dt>Exemplares que serão movimentados</dt><dd><strong><?= fisico_numero(count($selecionados)) ?></strong></dd></div>
                    <div><dt>Destino</dt><dd><strong><?= e(fisico_local_id($destino)) ?></strong></dd></div>
                    <?php if ($motivo !== ''): ?><div><dt>Motivo</dt><dd><?= e($motivo) ?></dd></div><?php endif; ?>
                </dl>
                <form method="post" action="<?= e($base) ?>" data-enviar-uma-vez>
                    <?= $ocultos() ?><input type="hidden" name="acao" value="confirmar"><input type="hidden" name="destino_id" value="<?= (int)$destino ?>"><input type="hidden" name="motivo" value="<?= e($motivo) ?>">
                    <?= backup_aviso_html(count($selecionados) >= 30) ?>
                    <?php if ($podeBackup && count($selecionados) >= 30): ?>
                        <label class="adm-check-confirm"><input type="checkbox" name="fazer_backup" value="1"<?= backup_recente() ? '' : ' checked' ?>> <span>Fazer uma cópia de segurança no servidor antes de mover (recomendado para alterações em massa).</span></label>
                    <?php endif; ?>
                    <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required> <span>Conferi: os <?= fisico_numero(count($selecionados)) ?> exemplares vão para <?= e(fisico_local_id($destino)) ?>.</span></label>
                    <div class="adm-form-actions">
                        <button class="adm-button adm-button--ghost" type="submit" name="acao" value="previa" formnovalidate><?= icon('arrow-left') ?> Trocar o destino</button>
                        <button class="adm-button adm-button--primary" type="submit"><?= icon('move') ?> Confirmar movimentação</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
        <section class="adm-card adm-panel" aria-labelledby="titulo-selecionados">
            <h2 id="titulo-selecionados" class="adm-panel__title"><?= icon('list') ?> Exemplares selecionados (<?= fisico_numero(count($selecionados)) ?>)</h2>
            <p class="adm-field__hint">Onde estão agora: <?= e(implode(' · ', array_map(fn ($k, $v) => $k . ' (' . $v . ')', array_keys($origemResumo), $origemResumo))) ?></p>
            <div class="adm-table-wrap adm-table-wrap--flush adm-mov-lista">
                <table class="adm-table adm-table--compacta">
                    <thead><tr><th scope="col">Código BDP</th><th scope="col">Obra</th><th scope="col">Localização atual</th></tr></thead>
                    <tbody>
                    <?php foreach ($selecionados as $x): ?>
                        <tr><td><span class="adm-code"><?= e($x['codigo_individual']) ?></span></td><td><?= e(mb_strimwidth($x['titulo'] ?? 'Sem título', 0, 70, '…')) ?></td><td><?= e(fisico_local_ou_triagem($x)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
