<?php
/**
 * Etiquetas dos exemplares: escolha do modelo, pré-visualização em tamanho real e PDF.
 *
 * Os exemplares chegam por:
 *   - ?ids=1,2,3 (página do exemplar, do livro ou do cadastro);
 *   - POST ids[] com CSRF (seleção na lista de exemplares), convertido em ?ids=;
 *   - ?origem=filtro e os filtros da lista de exemplares (todos os filtrados, até o limite).
 * Livros sem exemplar físico não aparecem: sem exemplar não há código.
 * Gerar o PDF (POST com CSRF) registra a data da etiqueta em cada exemplar.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\EtiquetaService;

const ETIQUETAS_LIMITE = 600;

$usuario = fisico_iniciar('etiquetas.gerar');
$base = admin_url('etiquetas');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$origem = $metodo === 'POST' ? $_POST : $_GET;

if ($metodo === 'POST' && admin_param($_POST, 'origem') === 'selecao') {
    admin_exigir_csrf();
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))))), 0, ETIQUETAS_LIMITE);
    if (!$ids) {
        admin_flash('aviso', 'Nenhum exemplar selecionado.', 'Marque os exemplares na lista antes de gerar as etiquetas.');
        admin_redirecionar(admin_url('exemplares'));
    }
    admin_redirecionar($base . '?ids=' . implode(',', $ids));
}

/** Ids a partir de "1,2,3" ou de ids[]. */
function etiquetas_ids(mixed $valor): array
{
    $lista = is_array($valor) ? $valor : explode(',', (string)$valor);
    return array_slice(array_values(array_unique(array_filter(array_map('intval', $lista), fn ($i) => $i > 0))), 0, ETIQUETAS_LIMITE);
}

$ids = etiquetas_ids($origem['ids'] ?? '');
$limitado = false;
try {
    if (!$ids && admin_param($origem, 'origem') === 'filtro') {
        $f = [];
        foreach (['q', 'situacao', 'conservacao', 'localizacao', 'conferencia', 'etiqueta', 'cdd', 'livro', 'inventario'] as $c) {
            $f[$c] = mb_substr(admin_param($origem, $c), 0, 100);
        }
        if ($f['situacao'] === 'todas' || $f['situacao'] === '') {
            $f['situacao'] = 'ativos';
        }
        $lista = exemplares_repo()->listar($f, 1, 500);
        $ids = array_map('intval', array_column($lista['itens'], 'id'));
        $limitado = $lista['total'] > count($ids);
    }
    $exemplares = $ids ? exemplares_repo()->paraEtiquetas($ids) : [];
    $modelos = etiquetas_servico()->modelos();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível preparar as etiquetas', fisico_erro_tecnico($e, 'etiquetas'));
    exit;
}

$modeloId = (int)admin_param($origem, 'modelo');
$modelo = null;
foreach ($modelos as $m) {
    if ((int)$m['id'] === $modeloId) {
        $modelo = $m;
    }
}
// Sem escolha: a primeira folha A4 patrimonial (várias etiquetas por folha); depois, o primeiro modelo ativo.
$modelo ??= array_values(array_filter($modelos, fn ($m) => $m['tipo'] === 'patrimonial' && (int)$m['colunas'] * (int)$m['linhas'] > 1))[0] ?? $modelos[0] ?? null;
$porFolha = $modelo ? (int)$modelo['colunas'] * (int)$modelo['linhas'] : 1;
$pular = max(0, min((int)admin_param($origem, 'pular'), $porFolha - 1));
$guias = admin_param($origem, 'guias') === '1';
$semCddPadrao = $modelo && $modelo['tipo'] === 'lombada' && (int)$modelo['mostrar_cdd'] ? '1' : '0';
$pularSemCdd = admin_param($origem, 'sem_cdd', $metodo === 'GET' && !isset($_GET['modelo']) ? $semCddPadrao : '0') === '1';
$ignorados = 0;
if ($pularSemCdd) {
    $antes = count($exemplares);
    $exemplares = array_values(array_filter($exemplares, fn ($ex) => $ex['cdd_aprovada'] !== null));
    $ignorados = $antes - count($exemplares);
}
$baixadosOuInexistentes = count($ids) - count($exemplares) - $ignorados;

if ($metodo === 'POST' && admin_param($_POST, 'acao') === 'pdf') {
    admin_exigir_csrf();
    if (!$modelo || !$exemplares) {
        admin_flash('erro', 'O PDF não foi gerado.', !$modelo ? 'Escolha um modelo de etiqueta ativo.' : 'Nenhum exemplar válido para imprimir.');
        admin_redirecionar($base . ($ids ? '?ids=' . implode(',', $ids) : ''));
    }
    try {
        $pdf = EtiquetaService::pdf($modelo, $exemplares, $pular, etiquetas_biblioteca(), $guias);
        exemplares_servico()->marcarEtiquetas(array_map('intval', array_column($exemplares, 'id')), $usuario['id'], $modelo['nome']);
    } catch (Throwable $e) {
        admin_flash('erro', 'O PDF não foi gerado.', fisico_erro_tecnico($e, 'PDF de etiquetas'));
        admin_redirecionar($base . '?ids=' . implode(',', $ids));
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="etiquetas-' . date('Y-m-d-His') . '.pdf"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$paginas = $modelo && $exemplares ? EtiquetaService::paginar($modelo, $exemplares, $pular) : [];
$previa = array_slice($paginas, 0, 2);
$biblioteca = etiquetas_biblioteca();
$parametros = ['ids' => implode(',', $ids), 'modelo' => $modelo['id'] ?? '', 'pular' => $pular ?: '', 'guias' => $guias ? '1' : '', 'sem_cdd' => $pularSemCdd ? '1' : '0'];

admin_page_start('etiquetas', 'Etiquetas', 'Etiquetas', 'Impressão das etiquetas dos exemplares', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], ['Etiquetas']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Etiquetas</h1>
        <p>Etiqueta com o código do exemplar e código de barras. A CDD só aparece quando aprovada, e a localização só quando confirmada. Nenhum dado pessoal é impresso.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if (auth_pode('etiquetas.configurar')): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('etiqueta-modelos')) ?>"><?= icon('ruler') ?> Modelos e medidas</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if (!$ids): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-escolha">
        <div class="adm-panel__header"><div><h2 id="titulo-escolha">Escolha os exemplares</h2>
            <p>As etiquetas são geradas só para exemplares físicos já cadastrados.</p></div></div>
        <div class="adm-atalhos">
            <a class="adm-button adm-button--primary" href="<?= e(admin_url_filtros($base, ['origem' => 'filtro', 'etiqueta' => 'sem'])) ?>"><?= icon('printer') ?> Exemplares com etiqueta ainda não gerada</a>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('exemplares')) ?>"><?= icon('list') ?> Selecionar na lista de exemplares</a>
        </div>
        <p class="adm-field__hint">Também é possível imprimir a etiqueta pela página de cada exemplar ou livro, e logo depois de cadastrar exemplares.</p>
    </section>
<?php elseif (!$modelos): ?>
    <?= admin_aviso('erro', 'Nenhum modelo de etiqueta ativo.', 'Cadastre ou reative um modelo em "Modelos e medidas".') ?>
<?php else: ?>
    <?php if ($limitado): ?><?= admin_aviso('aviso', 'Muitos exemplares filtrados.', 'Só os primeiros 500 entram nesta geração. Refine os filtros para gerar o restante em outro lote.') ?><?php endif; ?>
    <?php if ($baixadosOuInexistentes > 0): ?><?= admin_aviso('aviso', $baixadosOuInexistentes . ' exemplar(es) ficaram fora.', 'Exemplares baixados ou inexistentes não recebem etiqueta.') ?><?php endif; ?>
    <?php if ($ignorados > 0): ?><?= admin_aviso('aviso', $ignorados . ' exemplar(es) sem CDD aprovada ficaram fora.', 'A etiqueta de lombada depende da CDD aprovada. Desmarque a opção abaixo para imprimir mesmo assim (sem CDD).') ?><?php endif; ?>

    <div class="adm-etiquetas">
        <form method="get" action="<?= e($base) ?>" class="adm-card adm-panel adm-form-section adm-etiquetas__opcoes" aria-labelledby="titulo-opcoes">
            <h2 id="titulo-opcoes" class="adm-panel__title">Opções</h2>
            <input type="hidden" name="ids" value="<?= e(implode(',', $ids)) ?>">
            <label class="adm-field"><span class="adm-field__label">Modelo</span>
                <select class="adm-select" name="modelo" data-auto-submit>
                    <?php foreach ($modelos as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === (int)$modelo['id'] ? ' selected' : '' ?>><?= e($m['nome']) ?></option><?php endforeach; ?>
                </select>
                <span class="adm-field__hint"><?= $modelo['tipo'] === 'lombada' ? 'Lombada' : 'Patrimonial' ?> · <?= (int)$modelo['colunas'] ?> × <?= (int)$modelo['linhas'] ?> por folha · etiqueta de <?= e(str_replace('.', ',', (string)(float)$modelo['etiqueta_largura'])) ?> × <?= e(str_replace('.', ',', (string)(float)$modelo['etiqueta_altura'])) ?> mm</span></label>
            <label class="adm-field"><span class="adm-field__label">Pular posições na primeira folha</span>
                <input class="adm-input" type="number" name="pular" min="0" max="<?= $porFolha - 1 ?>" value="<?= $pular ?>" inputmode="numeric">
                <span class="adm-field__hint">Para reaproveitar uma folha já usada em parte (contando da esquerda para a direita, de cima para baixo).</span></label>
            <label class="adm-check-confirm"><input type="checkbox" name="guias" value="1"<?= $guias ? ' checked' : '' ?>> <span>Desenhar o contorno das etiquetas (para teste em papel comum)</span></label>
            <input type="hidden" name="sem_cdd" value="0">
            <label class="adm-check-confirm"><input type="checkbox" name="sem_cdd" value="1"<?= $pularSemCdd ? ' checked' : '' ?>> <span>Deixar de fora exemplares sem CDD aprovada</span></label>
            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('refresh-cw') ?> Atualizar pré-visualização</button>
        </form>

        <section class="adm-card adm-panel adm-etiquetas__resumo" aria-labelledby="titulo-gerar">
            <h2 id="titulo-gerar" class="adm-panel__title">Gerar</h2>
            <p><strong><?= fisico_numero(count($exemplares)) ?></strong> etiqueta(s) em <strong><?= count($paginas) ?></strong> folha(s). Cada exemplar aparece uma vez.</p>
            <?php foreach (EtiquetaService::avisos($modelo) as $aviso): ?><?= admin_aviso('aviso', 'Atenção ao código de barras', $aviso) ?><?php endforeach; ?>
            <?php if ($exemplares): ?>
                <form method="post" action="<?= e($base) ?>" target="_blank">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="acao" value="pdf">
                    <?php foreach ($parametros as $nome => $valor): ?><input type="hidden" name="<?= e($nome) ?>" value="<?= e((string)$valor) ?>"><?php endforeach; ?>
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('file-down') ?> Gerar PDF em tamanho real</button>
                </form>
                <p class="adm-field__hint">Ao imprimir, escolha <strong>Tamanho real</strong> (ou escala 100%) e desative "Ajustar à página". Faça um teste em papel comum antes de usar a folha de etiquetas.</p>
            <?php endif; ?>
            <details class="adm-lista-etiquetas">
                <summary>Ver os <?= count($exemplares) ?> exemplares</summary>
                <ol>
                    <?php foreach ($exemplares as $ex): ?>
                        <li><span class="adm-code"><?= e($ex['codigo_individual']) ?></span> <?= e(mb_strimwidth($ex['titulo'], 0, 60, '…')) ?><?= $ex['etiqueta_gerada_em'] ? ' <small class="adm-table__muted">(etiqueta já gerada em ' . admin_data_hora($ex['etiqueta_gerada_em'], false) . ')</small>' : '' ?></li>
                    <?php endforeach; ?>
                </ol>
            </details>
        </section>
    </div>

    <?php if ($previa): ?>
        <section class="adm-card adm-panel" aria-labelledby="titulo-previa">
            <div class="adm-panel__header"><div><h2 id="titulo-previa">Pré-visualização em tamanho real</h2>
                <p><?= count($paginas) > 2 ? 'Mostrando as 2 primeiras de ' . count($paginas) . ' folhas.' : 'Todas as folhas.' ?> Use o zoom do navegador em 100% para ver o tamanho real.</p></div>
                <label class="adm-filter"><span>Zoom da prévia</span>
                    <select class="adm-select adm-select--sm" data-previa-zoom>
                        <option value="1">100% (tamanho real)</option><option value="0.75">75%</option><option value="0.5">50%</option>
                    </select></label>
            </div>
            <div class="adm-previa" data-previa>
                <?php foreach ($previa as $n => $pagina): ?>
                    <div class="adm-previa__folha" style="width: <?= (float)$modelo['pagina_largura'] ?>mm; height: <?= (float)$modelo['pagina_altura'] ?>mm" aria-label="Folha <?= $n + 1 ?>">
                        <?php foreach ($pagina as $slot): ?>
                            <div class="adm-previa__etiqueta<?= $guias ? ' is-guia' : '' ?>" style="left: <?= round($slot['x'], 2) ?>mm; top: <?= round($slot['y'], 2) ?>mm; width: <?= (float)$modelo['etiqueta_largura'] ?>mm; height: <?= (float)$modelo['etiqueta_altura'] ?>mm">
                                <?= EtiquetaService::svg($modelo, $slot['exemplar'], $biblioteca) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
