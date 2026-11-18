<?php
/**
 * Comparar com planilha original (migração 180).
 *
 * Compara a planilha original importada (registros_legados, imutáveis) com o acervo atual, nas duas
 * direções, a qualquer momento (inclusive durante um inventário em andamento).
 *
 * - Abrir, filtrar, atualizar, imprimir e exportar NÃO alteram nada. Nenhum registro é modificado,
 *   excluído, mesclado ou cadastrado automaticamente.
 * - Só gravam as ações confirmadas por quem tem inventario.gerenciar: decisões (confirmar
 *   correspondência, confirmar obra nova, não é duplicidade, marcar como resolvida, reabrir),
 *   "Associar registro a obra existente", "Corrigir a obra associada a um exemplar" (o código BDP
 *   continua o mesmo) e "Salvar resultado da comparação" (imutável).
 * - Uma planilha enviada "só para comparar" é lida na hora e fica só na sessão; nada é gravado.
 * Ver: painel.ver · Exportar: relatorios.exportar · Decidir e corrigir: inventario.gerenciar.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';
require_once __DIR__ . '/includes/backup.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ComparacaoPlanilhaService as Comparacao;
use Biblioteca\Services\InventarioService;

$usuario = fisico_iniciar();
@set_time_limit(120);
if (preg_match('/^(\d+)M$/i', (string)ini_get('memory_limit'), $mem) && (int)$mem[1] < 256) {
    @ini_set('memory_limit', '256M'); // comparação de milhares de registros na memória
}
$base = admin_url('inventario-comparacao');
$servico = new Comparacao(db());
$podeGerenciar = auth_pode('inventario.gerenciar');
$podeExportar = auth_pode('relatorios.exportar');

/** Parâmetros de origem da comparação (importações, inventário, fonte) e filtros da lista. */
$impSel = array_values(array_unique(array_filter(array_map('intval', isset($_GET['imp_sel']) && is_array($_GET['imp_sel']) ? $_GET['imp_sel'] : explode(',', admin_param($_GET, 'imp'))), fn ($v) => $v > 0)));
sort($impSel);
$fonteArquivo = admin_param($_GET, 'fonte') === 'arquivo' && isset($_SESSION['comparacao_arquivo']);
$filtros = [
    'direcao' => isset(Comparacao::DIRECOES[admin_param($_GET, 'direcao')]) ? admin_param($_GET, 'direcao') : 'planilha',
    'categoria' => isset(Comparacao::CATEGORIAS[admin_param($_GET, 'categoria')]) ? admin_param($_GET, 'categoria') : '',
    'atencao' => admin_param($_GET, 'atencao') === '1' ? '1' : '',
    'verificacao' => in_array(admin_param($_GET, 'verificacao'), ['verificada', 'nao_verificada'], true) ? admin_param($_GET, 'verificacao') : '',
    'origem' => in_array(admin_param($_GET, 'origem'), ['manual', 'planilha', 'inventario'], true) ? admin_param($_GET, 'origem') : '',
    'lote' => (int)admin_param($_GET, 'lote') > 0 ? (string)(int)admin_param($_GET, 'lote') : '',
    'vinculo' => in_array(admin_param($_GET, 'vinculo'), ['associado', 'sem_obra', 'sem_registro', 'inconsistente'], true) ? admin_param($_GET, 'vinculo') : '',
    'importacao' => (int)admin_param($_GET, 'importacao') > 0 ? (string)(int)admin_param($_GET, 'importacao') : '',
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
];
$porPagina = in_array((int)admin_param($_GET, 'por_pagina', '25'), [25, 50, 100], true) ? (int)admin_param($_GET, 'por_pagina', '25') : 25;
$pagina = max(1, (int)admin_param($_GET, 'pagina', '1'));
$salvaId = (int)admin_param($_GET, 'salva');
$verHistorico = admin_param($_GET, 'historico') === '1';
$imprimir = admin_param($_GET, 'imprimir') === '1';
$ver = preg_match('/^([roe])(\d{1,12})$/', admin_param($_GET, 'ver'), $mv) ? [['r' => 'registro', 'o' => 'obra', 'e' => 'exemplar'][$mv[1]], (int)$mv[2]] : null;

try {
    $inventarioAtivo = inventario_repo()->ativo();
    $inventarios = db_query('SELECT id, titulo, status FROM inventarios ORDER BY (status IN (\'em_andamento\', \'pausado\')) DESC, id DESC LIMIT 60')->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a comparação', fisico_erro_tecnico($e, 'comparação com a planilha') . ' Confira se a migração 180 foi aplicada.');
    exit;
}
$invParam = admin_param($_GET, 'inv');
$invId = $invParam === 'nenhum' ? null : ((int)$invParam > 0 ? (int)$invParam : ($inventarioAtivo ? (int)$inventarioAtivo['id'] : null));
$origemParams = array_filter(['imp' => $impSel ? implode(',', $impSel) : '', 'inv' => $invParam, 'fonte' => $fonteArquivo ? 'arquivo' : ''], fn ($v) => $v !== '');
$urlAtual = admin_url_filtros($base, array_merge($origemParams, $filtros, ['pagina' => $pagina > 1 ? $pagina : null, 'por_pagina' => $porPagina !== 25 ? $porPagina : null]));

/** Volta só para esta página. */
function comparacao_voltar(string $v, string $padrao): string
{
    return $v !== '' && str_starts_with($v, admin_url('inventario-comparacao')) && !preg_match('/[\r\n]/', $v) ? $v : $padrao;
}

// ---------------------------------------------------------------
// Ações (todas confirmadas explicitamente)
// ---------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    $voltar = comparacao_voltar(admin_param($_POST, 'voltar'), $base);
    try {
        switch ($acao) {
            case 'enviar_arquivo':
                fisico_exigir('inventario.conferir');
                $arq = $_FILES['planilha'] ?? null;
                if (!$arq || !is_array($arq) || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$arq['tmp_name'])) {
                    throw new AcervoException('Escolha uma planilha .xlsx ou .csv.');
                }
                try {
                    $linhas = Comparacao::lerArquivo((string)$arq['tmp_name'], (string)$arq['name']);
                } catch (RuntimeException $e) {
                    throw new AcervoException($e->getMessage());
                } finally {
                    @unlink((string)$arq['tmp_name']);
                }
                $_SESSION['comparacao_arquivo'] = ['nome' => mb_substr(basename((string)$arq['name']), 0, 120), 'linhas' => $linhas, 'enviado_em' => date('Y-m-d H:i:s')];
                admin_flash('sucesso', 'Planilha lida só para comparação.', count($linhas) . ' linha(s) comparada(s). Nada foi gravado no banco e o arquivo não ficou no servidor.');
                admin_redirecionar($base . '?fonte=arquivo');
            case 'limpar_arquivo':
                fisico_exigir('inventario.conferir');
                unset($_SESSION['comparacao_arquivo']);
                admin_redirecionar($base);
            case 'decidir':
                fisico_exigir('inventario.gerenciar');
                [$decisao, $categoria] = admin_param($_POST, 'decisao_cat') !== '' ? array_pad(explode('|', admin_param($_POST, 'decisao_cat'), 2), 2, '')
                    : [admin_param($_POST, 'decisao'), admin_param($_POST, 'categoria')];
                $servico->decidir(admin_param($_POST, 'alvo_tipo'), (int)admin_param($_POST, 'alvo_id'), $categoria, $decisao,
                    $usuario['id'], admin_param($_POST, 'observacao'), (int)admin_param($_POST, 'alvo2_id') ?: null);
                admin_flash('sucesso', Comparacao::DECISOES[$decisao] ?? 'Decisão registrada.', 'A decisão, quem decidiu e quando ficaram no histórico. Nenhum dado bibliográfico, exemplar ou registro da planilha foi alterado.');
                admin_redirecionar($voltar);
            case 'associar_registro':
                fisico_exigir('inventario.gerenciar');
                if (admin_param($_POST, 'fazer_backup') === '1') {
                    backup_antes_de($usuario, 'Antes de associar um registro da planilha a outra obra');
                }
                $destino = (int)admin_param($_POST, 'destino') ?: (int)admin_param($_POST, 'destino_numero');
                $servico->associarRegistro((int)admin_param($_POST, 'registro_id'), $destino, $usuario['id'], admin_param($_POST, 'confirmo') === '1', admin_param($_POST, 'observacao'), $invId);
                admin_flash('sucesso', 'Registro associado à obra nº ' . $destino . '.', 'Os dados originais da planilha não mudaram; a obra anterior ficou guardada no histórico do vínculo.');
                admin_redirecionar($voltar);
            case 'corrigir_exemplar':
                fisico_exigir('inventario.gerenciar');
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['exemplares'] ?? [])), fn ($v) => $v > 0)));
                if (!$ids) {
                    throw new AcervoException('Escolha pelo menos um exemplar.');
                }
                $destino = (int)admin_param($_POST, 'destino') ?: (int)admin_param($_POST, 'destino_numero');
                if (admin_param($_POST, 'fazer_backup') === '1') {
                    backup_antes_de($usuario, 'Antes de corrigir a obra de ' . count($ids) . ' exemplar(es)');
                }
                $codigos = [];
                foreach ($ids as $exId) {
                    $r = $servico->corrigirObraExemplar($exId, $destino, $usuario['id'], admin_param($_POST, 'confirmo') === '1', admin_param($_POST, 'motivo'), $invId);
                    $codigos[] = $r['exemplar']['codigo_individual'];
                }
                admin_flash('sucesso', count($codigos) === 1 ? 'Obra do exemplar ' . $codigos[0] . ' corrigida.' : 'Obra de ' . count($codigos) . ' exemplares corrigida.',
                    'Os códigos BDP (' . implode(', ', $codigos) . '), lotes, carimbo, localização, conservação, ocorrências, empréstimos e o histórico continuam os mesmos. Nenhum código novo foi gerado.');
                admin_redirecionar($voltar);
            case 'salvar':
                fisico_exigir('inventario.gerenciar');
                $resultado = $servico->analisar($impSel, $invId);
                $novo = $servico->salvar($resultado, $usuario['id'], admin_param($_POST, 'observacao'));
                admin_flash('sucesso', 'Resultado da comparação nº ' . $novo . ' salvo.', 'O resultado salvo não muda depois, mesmo que o acervo seja corrigido.');
                admin_redirecionar($base . '?salva=' . $novo);
        }
        throw new AcervoException('Ação desconhecida.');
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
        admin_redirecionar($voltar);
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado.', fisico_erro_tecnico($e, 'comparação com a planilha'));
        admin_redirecionar($voltar);
    }
}

// ---------------------------------------------------------------
// Dados (somente leitura)
// ---------------------------------------------------------------
$salva = null;
$historico = null;
try {
    $importacoes = $servico->importacoes();
    if ($salvaId) {
        $salva = $servico->salva($salvaId);
        if (!$salva) {
            http_response_code(404);
            admin_pagina_erro('Comparação não encontrada', 'Este resultado salvo não existe.');
            exit;
        }
        $resultado = ['itens' => $salva['itens'], 'indicadores' => $salva['indicadores'], 'importacoes' => $salva['importacoes'], 'fonte' => $salva['fonte'],
            'gerado_em' => $salva['criado_em'], 'inventario' => $salva['inventario_id'] ? ['id' => $salva['inventario_id'], 'titulo' => $salva['inventario_titulo'], 'status' => $salva['inventario_status']] : null];
    } elseif ($verHistorico) {
        $historico = $servico->salvas($pagina, 20);
        $resultado = null;
    } else {
        $resultado = $servico->analisar($impSel, $invId, $fonteArquivo ? $_SESSION['comparacao_arquivo']['linhas'] : null);
    }
    $detalhe = $ver && !$salva && !$verHistorico ? $servico->detalhe($ver[0], $ver[1]) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível calcular a comparação', fisico_erro_tecnico($e, 'comparação com a planilha') . ' Confira se a migração 180 foi aplicada.');
    exit;
}

if ($resultado) {
    $todos = $resultado['itens'];
    $filtrados = Comparacao::filtrar($todos, $filtros);
    // Exportação (sem gravar nada): completa ou só as inconsistências, com os filtros atuais.
    $formato = admin_param($_GET, 'exportar');
    if (in_array($formato, ['xlsx', 'pdf', 'csv'], true)) {
        fisico_exigir('relatorios.exportar');
        $soInconsistencias = admin_param($_GET, 'escopo') === 'inconsistencias';
        $lista = Comparacao::filtrar($todos, ['direcao' => ''] + ($soInconsistencias ? ['atencao' => '1'] : []) + array_diff_key($filtros, ['direcao' => 1]));
        if (admin_param($_GET, 'so_direcao') === '1') {
            $lista = array_values(array_filter($lista, fn ($i) => $i['direcao'] === $filtros['direcao']));
        }
        $ind = $resultado['indicadores'];
        $titulo = $soInconsistencias ? 'Comparação com a planilha — inconsistências' : 'Comparação com a planilha original';
        $fonteTexto = $resultado['fonte'] === 'arquivo' ? 'Planilha enviada só para comparação' : implode(', ', array_map(fn ($i) => ($i['nome_arquivo'] ?? $i['arquivo'] ?? '') . ' (' . admin_data_hora($i['importado_em'] ?? $i['criado_em'] ?? null, false) . ')', $resultado['importacoes']));
        fisico_enviar_tabela($formato, ($salva ? 'comparacao-salva-' . $salva['id'] : 'comparacao-planilha') . ($soInconsistencias ? '-inconsistencias' : '') . '-' . date('Y-m-d'), $titulo,
            Comparacao::COLUNAS_EXPORTACAO, [], Comparacao::linhasExportacao($lista),
            [$titulo, 'Comparado em ' . admin_data_hora($resultado['gerado_em']) . ($resultado['inventario'] ? ' · Inventário: ' . $resultado['inventario']['titulo'] : '') . ' · Fonte: ' . $fonteTexto],
            $usuario['nome'] ?? '',
            [['Registros da planilha', $ind['planilha']['registros']], ['Obras no acervo', $ind['acervo']['obras']], ['Exemplares', $ind['exemplares']['total']],
                ['Itens que exigem atenção', $ind['auditoria']['pendentes']], ['Itens resolvidos', $ind['auditoria']['resolvidos']]],
            array_filter(['Situação' => $soInconsistencias ? 'Somente inconsistências' : 'Completo', 'Categoria' => $filtros['categoria'] ? Comparacao::rotulo($filtros['categoria']) : '', 'Pesquisa' => $filtros['q']]));
    }
    $porPaginaEfetiva = $imprimir ? 3000 : $porPagina;
    $totalFiltrados = count($filtrados);
    $paginas = max(1, (int)ceil($totalFiltrados / $porPaginaEfetiva));
    $pagina = min($pagina, $paginas);
    $pagItens = array_slice($filtrados, ($pagina - 1) * $porPaginaEfetiva, $porPaginaEfetiva);
    $contagemDirecao = [];
    foreach (Comparacao::DIRECOES as $d => $_) {
        $contagemDirecao[$d] = count(array_filter($todos, fn ($i) => $i['direcao'] === $d));
    }
    $contagemCat = Comparacao::contarCategorias($todos, $filtros['direcao']);
    $itemVer = null;
    if ($ver) {
        foreach ($todos as $it) {
            if ($it['tipo'] === $ver[0] && $it['id'] === $ver[1]) {
                $itemVer = $it;
                break;
            }
        }
    }
}

/** Selo de uma categoria (riscado e com "resolvida" quando já foi decidida). */
function comparacao_badge(string $cat, bool $resolvida = false): string
{
    [$rotulo, $tom] = Comparacao::CATEGORIAS[$cat] ?? [$cat, 'blue'];
    return '<span class="adm-status adm-tone--' . ($resolvida ? 'muted' : $tom) . ' adm-comp-cat' . ($resolvida ? ' is-resolvida' : '') . '">' . ($resolvida ? icon('check') . ' ' : '') . e($rotulo) . ($resolvida ? ' <small>(resolvida)</small>' : '') . '</span>';
}

function comparacao_origem(?string $o): string
{
    return ['manual' => 'Cadastro manual', 'planilha' => 'Planilha', 'inventario' => 'Inventário'][$o ?? ''] ?? '—';
}

/** Endereço da ficha da obra (na conferência, se houver inventário em andamento) ou dos detalhes do livro. */
function comparacao_url_obra(int $livroId, ?array $inventarioAtivo, string $painel = ''): string
{
    if ($inventarioAtivo && $inventarioAtivo['status'] === 'em_andamento' && auth_pode('inventario.conferir')) {
        return admin_url('inventario-conferencia') . '?id=' . (int)$inventarioAtivo['id'] . '&obra=' . $livroId . ($painel ? '&painel=' . $painel : '') . '#obra';
    }
    return $painel === 'verificar' && auth_pode('livros.editar') ? admin_url('editar-livro') . '?id=' . $livroId : admin_url('detalhes-livro') . '?id=' . $livroId;
}

$filtrosLink = array_merge($origemParams, $filtros);
$titulo = $salva ? 'Comparação salva nº ' . (int)$salva['id'] : ($verHistorico ? 'Comparações anteriores' : 'Comparar com planilha original');
admin_page_start('inventario', $titulo, 'Inventário', 'Planilha original × acervo atual', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], $salva || $verHistorico ? ['Comparar com planilha original', $base] : ['Comparar com planilha original'], ...($salva ? [['Comparações anteriores', $base . '?historico=1'], ['Nº ' . (int)$salva['id']]] : ($verHistorico ? [['Comparações anteriores']] : []))]) ?>

<section class="adm-page-head adm-comp-head">
    <div>
        <h1><?= e($titulo) ?></h1>
        <?php if ($salva): ?>
            <p>Resultado salvo em <?= admin_data_hora($salva['criado_em']) ?> por <?= e($salva['administrador_nome'] ?? '—') ?>. Este resultado não muda, mesmo que o acervo seja corrigido depois.<?= $salva['observacao'] ? ' Observação: ' . e($salva['observacao']) : '' ?></p>
        <?php elseif ($verHistorico): ?>
            <p>Resultados salvos da comparação. Cada um guarda a data, o responsável, a fonte e os resultados daquele momento, e não é alterado.</p>
        <?php else: ?>
            <p>Somente consulta: abrir, filtrar, atualizar ou exportar não altera, exclui, mescla nem cadastra nada. Cada correção pede confirmação de quem tem permissão.</p>
        <?php endif; ?>
    </div>
    <div class="adm-page-head__actions adm-no-print">
        <?php if (!$salva && !$verHistorico): ?>
            <a class="adm-button adm-button--ghost" href="<?= e($urlAtual) ?>"><?= icon('refresh-cw') ?> Atualizar comparação</a>
            <?php if ($podeGerenciar && $resultado['fonte'] === 'importacoes'): ?>
                <form method="post" action="<?= e($base . ($origemParams ? '?' . http_build_query($origemParams) : '')) ?>" class="adm-inline-form" data-enviar-uma-vez>
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="voltar" value="<?= e($urlAtual) ?>">
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar resultado da comparação</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$verHistorico): ?><a class="adm-button adm-button--ghost" href="<?= e($base . '?historico=1') ?>"><?= icon('history') ?> Consultar comparações anteriores</a><?php endif; ?>
        <?php if ($resultado && $podeExportar): $exp = fn ($f, $esc) => admin_url_filtros($base, array_merge($salva ? ['salva' => $salva['id']] : $filtrosLink, ['exportar' => $f, 'escopo' => $esc])); ?>
            <details class="adm-menu-acoes adm-comp-exportar">
                <summary class="adm-button adm-button--ghost"><?= icon('download') ?> Exportar</summary>
                <span class="adm-menu-acoes__lista">
                    <a href="<?= e($exp('xlsx', 'tudo')) ?>"><?= icon('file-spreadsheet') ?> Excel — completo</a>
                    <a href="<?= e($exp('xlsx', 'inconsistencias')) ?>"><?= icon('file-spreadsheet') ?> Excel — só inconsistências</a>
                    <a href="<?= e($exp('pdf', 'tudo')) ?>"><?= icon('file-text') ?> PDF — completo</a>
                    <a href="<?= e($exp('pdf', 'inconsistencias')) ?>"><?= icon('file-text') ?> PDF — só inconsistências</a>
                    <a href="<?= e($exp('csv', 'tudo')) ?>"><?= icon('file-text') ?> CSV — completo</a>
                </span>
            </details>
        <?php endif; ?>
        <?php if ($resultado): ?>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros($base, array_merge($salva ? ['salva' => $salva['id']] : $filtrosLink, ['imprimir' => '1']))) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Versão para impressão</a>
            <?php if ($imprimir): ?><button class="adm-button adm-button--primary" type="button" data-imprimir><?= icon('printer') ?> Imprimir</button><?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if ($verHistorico): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-salvas">
        <div class="adm-panel__header"><div><h2 id="titulo-salvas">Comparações salvas</h2><p><?= fisico_numero($historico['total']) ?> resultado(s)</p></div></div>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards">
                <thead><tr><th scope="col">Nº</th><th scope="col">Data</th><th scope="col">Responsável</th><th scope="col">Fonte</th><th scope="col">Inventário</th><th scope="col">Itens</th><th scope="col">Exigiam atenção</th><th scope="col" class="adm-actions-col">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($historico['itens'] as $c): $imps = json_decode((string)$c['importacoes_json'], true) ?: []; ?>
                    <tr>
                        <td class="adm-td-title"><a class="adm-book-link" href="<?= e($base . '?salva=' . (int)$c['id']) ?>">Nº <?= (int)$c['id'] ?></a></td>
                        <td data-label="Data"><?= admin_data_hora($c['criado_em']) ?></td>
                        <td data-label="Responsável"><?= e($c['administrador_nome'] ?? '—') ?></td>
                        <td data-label="Fonte"><?= e(implode(', ', array_map(fn ($i) => (string)($i['arquivo'] ?? ''), $imps)) ?: '—') ?></td>
                        <td data-label="Inventário"><?= e($c['inventario_titulo'] ?? 'Nenhum') ?></td>
                        <td data-label="Itens"><?= fisico_numero((int)$c['total_itens']) ?></td>
                        <td data-label="Exigiam atenção"><?= fisico_numero((int)$c['total_atencao']) ?></td>
                        <td class="adm-actions-col"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?salva=' . (int)$c['id']) ?>"><?= icon('eye') ?> Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$historico['itens']): ?>
                    <tr><td colspan="8"><div class="adm-empty"><?= icon('history') ?><strong>Nenhuma comparação salva ainda</strong><span>Use "Salvar resultado da comparação" para guardar o resultado de um momento.</span></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= admin_paginacao($historico['total'], $historico['pagina'], 20, $base, ['historico' => '1'], [20]) ?>
    </section>
<?php else: ?>

<?php // ---------- Fonte da comparação ---------- ?>
<section class="adm-card adm-panel adm-comp-fonte" aria-labelledby="titulo-fonte">
    <p class="adm-comp-meta" id="titulo-fonte">
        <?= icon('file-spreadsheet') ?>
        <?php if ($resultado['fonte'] === 'arquivo' && !$salva): ?>
            <strong>Planilha enviada só para comparação:</strong> <?= e($_SESSION['comparacao_arquivo']['nome']) ?> (<?= fisico_numero(count($_SESSION['comparacao_arquivo']['linhas'])) ?> linha(s), lida em <?= admin_data_hora($_SESSION['comparacao_arquivo']['enviado_em']) ?>). Nada foi gravado.
        <?php else: ?>
            <strong>Planilha original:</strong>
            <?php foreach ($resultado['importacoes'] as $k => $imp): ?><?= $k ? ' · ' : '' ?><?= e($imp['nome_arquivo'] ?? $imp['arquivo'] ?? '') ?> <small class="adm-table__muted">(importada em <?= admin_data_hora($imp['importado_em'] ?? $imp['criado_em'] ?? null, false) ?>, <?= fisico_numero((int)$imp['registros']) ?> registros)</small><?php endforeach; ?>
            <?= !$resultado['importacoes'] ? '<span class="adm-table__muted">nenhuma importação com registros ainda</span>' : '' ?>
        <?php endif; ?>
        <span class="adm-comp-meta__sep">·</span> Comparado em <strong><?= admin_data_hora($resultado['gerado_em']) ?></strong>
        <span class="adm-comp-meta__sep">·</span> Inventário: <strong><?= $resultado['inventario'] ? e($resultado['inventario']['titulo']) . ' (' . e(InventarioService::STATUS[$resultado['inventario']['status']] ?? $resultado['inventario']['status']) . ')' : 'nenhum' ?></strong>
    </p>
    <?php if (!$salva && !$imprimir): ?>
    <details class="adm-comp-origem adm-no-print"<?= admin_param($_GET, 'origem_aberta') === '1' ? ' open' : '' ?>>
        <summary class="adm-link"><?= icon('sliders-horizontal') ?> Escolher arquivos importados e inventário</summary>
        <form method="get" action="<?= e($base) ?>" class="adm-comp-origem__form">
            <fieldset class="adm-comp-origem__arquivos"><legend>Arquivos importados</legend>
                <?php foreach ($importacoes as $imp): ?>
                    <label class="adm-check"><input type="checkbox" name="imp_sel[]" value="<?= (int)$imp['id'] ?>"<?= !$impSel || in_array((int)$imp['id'], $impSel, true) ? ' checked' : '' ?>>
                        <span><?= e($imp['nome_arquivo']) ?> <small class="adm-table__muted"><?= admin_data_hora($imp['importado_em'] ?? $imp['criado_em'], false) ?> · <?= fisico_numero((int)$imp['registros']) ?> registros</small></span></label>
                <?php endforeach; ?>
                <?php if (!$importacoes): ?><p class="adm-field__hint">Nenhuma importação deixou registros no acervo.</p><?php endif; ?>
            </fieldset>
            <label class="adm-field"><span class="adm-field__sublabel">Inventário usado nos "encontrados"</span>
                <select class="adm-select" name="inv">
                    <option value="nenhum"<?= $invId === null ? ' selected' : '' ?>>Nenhum</option>
                    <?php foreach ($inventarios as $iv): ?><option value="<?= (int)$iv['id'] ?>"<?= $invId === (int)$iv['id'] ? ' selected' : '' ?>><?= e($iv['titulo']) ?> — <?= e(InventarioService::STATUS[$iv['status']] ?? $iv['status']) ?></option><?php endforeach; ?>
                </select></label>
            <p class="adm-field__hint">A escolha limita os registros da planilha comparados. Uma obra que veio de outro arquivo continua sendo obra importada (não vira "obra nova").</p>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('refresh-cw') ?> Comparar</button>
        </form>
        <?php if (auth_pode('inventario.conferir')): ?>
        <form method="post" action="<?= e($base) ?>" enctype="multipart/form-data" class="adm-comp-origem__arquivo" data-enviar-uma-vez>
            <?= csrf_campo() ?><input type="hidden" name="acao" value="enviar_arquivo">
            <label class="adm-field"><span class="adm-field__sublabel">Ou compare com outra planilha (.xlsx ou .csv), só para consulta</span>
                <input class="adm-input" type="file" name="planilha" accept=".xlsx,.csv" required></label>
            <p class="adm-field__hint">O arquivo é lido na hora e descartado: nada é gravado e os dados originais importados não mudam.</p>
            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('upload') ?> Comparar este arquivo</button>
        </form>
        <?php if ($fonteArquivo): ?>
            <form method="post" action="<?= e($base) ?>" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="limpar_arquivo">
                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('x') ?> Voltar para a planilha original importada</button></form>
        <?php endif; ?>
        <?php endif; ?>
    </details>
    <?php endif; ?>
</section>

<?php // ---------- Indicadores ---------- ?>
<?php $ind = $resultado['indicadores']; $emAndamento = $resultado['inventario'] && in_array($resultado['inventario']['status'] ?? '', ['em_andamento', 'pausado', 'aberto'], true); ?>
<section class="adm-comp-indicadores" aria-label="Indicadores">
    <?php foreach ([
        ['Planilha', 'linhas', 'file-spreadsheet', [['Registros', $ind['planilha']['registros']], ['Associados a uma obra', $ind['planilha']['associados']], ['Sem correspondência', $ind['planilha']['sem_correspondencia']]]],
        ['Acervo', 'obras', 'library', [['Obras', $ind['acervo']['obras']], ['Importadas', $ind['acervo']['importadas']], ['Cadastradas manualmente', $ind['acervo']['manuais']], ['Verificadas', $ind['acervo']['verificadas']], ['Não verificadas', $ind['acervo']['nao_verificadas']]]],
        ['Exemplares', 'cópias físicas', 'book-copy', array_values(array_filter([['Exemplares', $ind['exemplares']['total']], $ind['exemplares']['encontrados_inventario'] !== null ? ['Encontrados no inventário atual', $ind['exemplares']['encontrados_inventario']] : null, ['Sem associação válida', $ind['exemplares']['sem_associacao']], ['Com possíveis problemas', $ind['exemplares']['com_problemas']]]))],
        ['Auditoria', 'itens da comparação', 'list-checks', [['Possíveis duplicidades (obras)', $ind['auditoria']['duplicidades']], ['Correspondências a confirmar', $ind['auditoria']['correspondencias_a_confirmar']], ['Divergências de quantidade (obras)', $ind['auditoria']['divergencias_quantidade']], ['Vínculos inconsistentes', $ind['auditoria']['vinculos_inconsistentes'] ?? 0], ['Resolvidos', $ind['auditoria']['resolvidos']], ['Pendentes', $ind['auditoria']['pendentes']]]],
        ['Progresso', 'obras da planilha', 'clipboard-check', [['Obras da planilha', $ind['progresso']['obras_planilha']], ['Verificadas', $ind['progresso']['verificadas']], ['Ainda a verificar', $ind['progresso']['a_verificar']], ['Com pelo menos um exemplar', $ind['progresso']['com_exemplar']], ['Obras novas', $ind['progresso']['obras_novas']], ['Exemplares registrados', $ind['progresso']['exemplares_registrados']]]],
    ] as [$grupo, $unidade, $icone, $linhas]): ?>
        <article class="adm-card adm-comp-ind">
            <h2><?= icon($icone) ?> <?= e($grupo) ?> <small>(<?= e($unidade) ?>)</small></h2>
            <dl><?php foreach ($linhas as [$rot, $val]): ?><div><dt><?= e($rot) ?></dt><dd><?= fisico_numero((int)$val) ?></dd></div><?php endforeach; ?></dl>
        </article>
    <?php endforeach; ?>
</section>
<p class="adm-field__hint adm-comp-nota"><?= icon('info') ?> Cada grupo usa a própria unidade (registros, obras ou exemplares) e as categorias podem se sobrepor: um mesmo item pode ter mais de uma. Uma linha da planilha descreve a obra e pode valer para várias cópias; ela não conta como exemplar.<?= $emAndamento ? ' O inventário está em andamento: obra sem exemplar ou exemplar ainda não encontrado não significa perda.' : '' ?> Não há percentual sobre as linhas da planilha.</p>

<?php // ---------- Detalhe lado a lado ---------- ?>
<?php if ($detalhe && $itemVer): $obraD = $detalhe['obra']; $regD = $detalhe['registro']; $voltarItem = admin_url_filtros($base, array_merge($filtrosLink, ['ver' => $itemVer['chave'], 'pagina' => $pagina > 1 ? $pagina : null])); ?>
<section class="adm-card adm-panel adm-comp-detalhe" id="detalhe" aria-labelledby="titulo-detalhe">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-detalhe"><?= e($itemVer['titulo']) ?></h2>
            <p><?= e(Comparacao::DIRECOES[$itemVer['direcao']]) ?> · <?= $itemVer['arquivo'] ? e($itemVer['arquivo']) . ', linha ' . (int)$itemVer['linha'] : ($itemVer['linhas'] ? fisico_numero(count($itemVer['linhas'])) . ' registro(s) da planilha' : 'Sem registro na planilha') ?><?= $obraD ? ' · Obra nº ' . (int)$obraD['id'] . ' (' . e(comparacao_origem($obraD['origem_cadastro'])) . ')' : '' ?></p>
        </div>
        <a class="adm-button adm-button--ghost adm-button--sm adm-no-print" href="<?= e(admin_url_filtros($base, array_merge($filtrosLink, ['pagina' => $pagina > 1 ? $pagina : null]))) ?>"><?= icon('x') ?> Fechar</a>
    </div>
    <p class="adm-comp-cats"><?php foreach ($itemVer['categorias'] as $c): ?><?= comparacao_badge($c, in_array($c, $itemVer['resolvidas'], true)) ?> <?php endforeach; ?></p>
    <?php if ($itemVer['detalhes']): ?><ul class="adm-comp-detalhes"><?php foreach ($itemVer['detalhes'] as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul><?php endif; ?>

    <table class="adm-table adm-table--compact adm-comparar adm-comp-lado">
        <thead><tr><th scope="col">Campo</th><th scope="col">Planilha original</th><th scope="col">Cadastro atual</th></tr></thead>
        <tbody>
        <?php foreach ($detalhe['campos'] as $c): ?>
            <tr<?= $c['alterado'] ? ' class="is-alterado"' : '' ?>><th scope="row"><?= e($c['rotulo']) ?></th>
                <td data-label="Planilha original"><?= $c['planilha'] !== null && $c['planilha'] !== '' ? e((string)$c['planilha']) : '<span class="adm-table__muted">—</span>' ?></td>
                <td data-label="Cadastro atual"><?= $c['atual'] !== null && $c['atual'] !== '' ? e((string)$c['atual']) : '<span class="adm-table__muted">—</span>' ?><?= $c['alterado'] ? ' <small class="adm-table__muted">(corrigido)</small>' : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-field__hint">As diferenças destacadas são correções feitas depois da importação, não erros. Os dados originais da planilha não mudam.<?= $obraD && $obraD['dados_verificados_em'] ? ' Dados verificados em ' . admin_data_hora($obraD['dados_verificados_em']) . ($obraD['verificado_por_nome'] ? ' por ' . e($obraD['verificado_por_nome']) : '') . '.' : '' ?></p>

    <?php if ($detalhe['linhas'] && count($detalhe['linhas']) > 1): ?>
        <details class="adm-comp-linhas"><summary class="adm-link"><?= icon('list') ?> <?= fisico_numero(count($detalhe['linhas'])) ?> registros da planilha nesta obra</summary>
            <ul><?php foreach ($detalhe['linhas'] as $l): ?><li><a class="adm-link" href="<?= e(admin_url_filtros($base, array_merge($origemParams, ['direcao' => 'planilha', 'ver' => 'r' . (int)$l['id']]))) ?>#detalhe"><?= e($l['arquivo']) ?>, linha <?= (int)$l['linha'] ?></a><?= $l['quantidade_informada'] !== null ? ' · quantidade informada: ' . (int)$l['quantidade_informada'] : '' ?><?= $l['livro_original_id'] ? ' · vinculada (vinha da obra nº ' . (int)$l['livro_original_id'] . ')' : '' ?></li><?php endforeach; ?></ul>
        </details>
    <?php endif; ?>

    <div class="adm-comp-acoes adm-no-print">
        <?php if ($obraD): ?>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(comparacao_url_obra((int)$obraD['id'], $inventarioAtivo)) ?>"><?= icon('book-open') ?> Consultar obra</a>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(comparacao_url_obra((int)$obraD['id'], $inventarioAtivo, 'verificar')) ?>"><?= icon('pencil') ?> Verificar e corrigir dados</a>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('exemplares') . '?livro=' . (int)$obraD['id']) ?>"><?= icon('book-copy') ?> Consultar exemplares (<?= fisico_numero((int)$obraD['exemplares']) ?>)</a>
        <?php endif; ?>
        <?php if ($itemVer['tipo'] === 'exemplar'): ?>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$itemVer['id']) ?>"><?= icon('book-copy') ?> Consultar exemplar <?= e($itemVer['codigo']) ?></a>
        <?php endif; ?>
        <?php if (in_array('sem_correspondencia', $itemVer['categorias'], true) && auth_pode('inventario.conferir')): ?>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($inventarioAtivo && $inventarioAtivo['status'] === 'em_andamento' ? admin_url('inventario-conferencia') . '?id=' . (int)$inventarioAtivo['id'] . '&nova_obra=1&titulo=' . rawurlencode((string)$itemVer['titulo']) . '#nova-obra' : admin_url('cadastrar-livro')) ?>"><?= icon('book-plus') ?> Cadastrar obra faltante</a>
        <?php endif; ?>
        <?php if (auth_pode('inventario.conferir')): ?>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('duvidas') . '?' . http_build_query(array_filter(['livro' => $obraD['id'] ?? null, 'codigo' => $itemVer['codigo'], 'voltar' => $voltarItem]))) ?>#nova"><?= icon('circle-help') ?> Registrar dúvida</a>
        <?php endif; ?>
    </div>

    <?php if ($itemVer['candidatos']): ?>
        <h3 class="adm-comp-subtitulo"><?= icon('git-compare') ?> <?= in_array('possivel_duplicidade', $itemVer['categorias'], true) ? 'Obras parecidas (analisar duplicidades)' : 'Possíveis correspondências' ?></h3>
        <ul class="adm-comp-candidatos">
            <?php foreach ($itemVer['candidatos'] as $cd): ?>
                <li>
                    <div><strong><?= $cd['livro_id'] ? 'Obra nº ' . (int)$cd['livro_id'] . ' · ' : '' ?><?= e($cd['titulo'] ?? 'Sem título') ?></strong>
                        <?php if (!empty($cd['linha'])): ?><small class="adm-table__muted"> — <?= e($cd['arquivo']) ?>, linha <?= (int)$cd['linha'] ?></small><?php endif; ?>
                        <small class="adm-block-muted"><?= e(implode(', ', $cd['motivos'])) ?><?= $cd['diferencas'] ? ' · Atenção: ' . e(implode(', ', $cd['diferencas'])) . ' (edições diferentes podem ser obras separadas)' : '' ?></small></div>
                    <span class="adm-comp-candidatos__acoes adm-no-print">
                        <?php if ($cd['livro_id']): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, array_merge($origemParams, ['direcao' => 'acervo', 'ver' => 'o' . (int)$cd['livro_id']]))) ?>#detalhe"><?= icon('git-compare') ?> Comparar</a><?php endif; ?>
                        <?php if ($podeGerenciar && !empty($cd['duplicidade'])): ?>
                            <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-enviar-uma-vez><?= csrf_campo() ?>
                                <input type="hidden" name="acao" value="decidir"><input type="hidden" name="voltar" value="<?= e($voltarItem) ?>">
                                <input type="hidden" name="alvo_tipo" value="obra"><input type="hidden" name="alvo_id" value="<?= (int)$itemVer['id'] ?>"><input type="hidden" name="alvo2_id" value="<?= (int)$cd['livro_id'] ?>">
                                <input type="hidden" name="categoria" value="possivel_duplicidade"><input type="hidden" name="decisao" value="nao_duplicidade">
                                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('check') ?> Não é duplicidade</button></form>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (in_array('possivel_duplicidade', $itemVer['categorias'], true)): ?><p class="adm-field__hint">Nada é unido automaticamente. Se forem a mesma obra e a mesma edição, use "Associar registro" ou "Corrigir a obra associada" nos exemplares, com confirmação.</p><?php endif; ?>
    <?php endif; ?>

    <?php if ($podeGerenciar): $atencaoPend = array_values(array_filter($itemVer['categorias'], fn ($c) => Comparacao::CATEGORIAS[$c][2] && !in_array($c, $itemVer['resolvidas'], true))); ?>
    <div class="adm-comp-forms adm-no-print">
        <?php // Decisões ?>
        <?php if ($atencaoPend || $itemVer['resolvidas'] || in_array('obra_nova', $itemVer['categorias'], true)): ?>
        <form method="post" action="<?= e($base) ?>" class="adm-form adm-comp-form" data-enviar-uma-vez>
            <h3 class="adm-comp-subtitulo"><?= icon('list-checks') ?> Registrar decisão</h3>
            <?= csrf_campo() ?><input type="hidden" name="acao" value="decidir"><input type="hidden" name="voltar" value="<?= e($voltarItem) ?>">
            <input type="hidden" name="alvo_tipo" value="<?= e($itemVer['tipo']) ?>"><input type="hidden" name="alvo_id" value="<?= (int)$itemVer['id'] ?>">
            <label class="adm-field"><span class="adm-field__sublabel">Decisão</span><select class="adm-select" name="decisao_cat" required>
                <?php foreach ($atencaoPend as $c): ?>
                    <?php if ($c === 'possivel_correspondencia'): ?>
                        <option value="confirmar_correspondencia|<?= e($c) ?>">Confirmar correspondência (manter como está)</option>
                        <?php if ($itemVer['tipo'] === 'obra'): ?><option value="confirmar_obra_nova|<?= e($c) ?>">Confirmar obra nova (não estava na planilha)</option><?php endif; ?>
                    <?php endif; ?>
                    <option value="resolvida|<?= e($c) ?>">Marcar como resolvida: <?= e(Comparacao::rotulo($c)) ?></option>
                <?php endforeach; ?>
                <?php if (in_array('obra_nova', $itemVer['categorias'], true) && !in_array('obra_nova', $itemVer['resolvidas'], true)): ?><option value="confirmar_obra_nova|obra_nova">Confirmar obra nova</option><?php endif; ?>
                <?php foreach ($itemVer['resolvidas'] as $c): ?><option value="reaberta|<?= e($c) ?>">Reabrir: <?= e(Comparacao::rotulo($c)) ?></option><?php endforeach; ?>
            </select></label>
            <label class="adm-field"><span class="adm-field__sublabel">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: conferido com o livro em mãos"></label>
            <p class="adm-field__hint">A decisão só fica registrada (com seu nome e a data). Não altera a obra, os exemplares nem a planilha.</p>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Registrar decisão</button>
        </form>
        <?php endif; ?>

        <?php // Associar registro a obra existente ?>
        <?php if ($itemVer['tipo'] === 'registro' && $regD && $regD['exemplar_id'] === null): $verificadas = array_values(array_filter($itemVer['candidatos'], fn ($c) => !empty($c['verificada']))); ?>
        <form method="post" action="<?= e($base) ?>" class="adm-form adm-comp-form" data-enviar-uma-vez>
            <h3 class="adm-comp-subtitulo"><?= icon('link') ?> Associar registro a obra existente</h3>
            <?= csrf_campo() ?><input type="hidden" name="acao" value="associar_registro"><input type="hidden" name="voltar" value="<?= e($voltarItem) ?>"><input type="hidden" name="registro_id" value="<?= (int)$regD['id'] ?>">
            <?php foreach ($verificadas as $k => $cd): ?>
                <label class="adm-check"><input type="radio" name="destino" value="<?= (int)$cd['livro_id'] ?>"<?= $k === 0 ? ' checked' : '' ?>> <span>Obra nº <?= (int)$cd['livro_id'] ?> · <?= e($cd['titulo'] ?? '') ?></span></label>
            <?php endforeach; ?>
            <label class="adm-field"><span class="adm-field__sublabel"><?= $verificadas ? 'Ou informe o número de outra obra verificada' : 'Número da obra verificada' ?></span><input class="adm-input adm-input--sm" name="destino_numero" inputmode="numeric" pattern="[0-9]*" maxlength="12"<?= $verificadas ? '' : ' required' ?>></label>
            <label class="adm-field"><span class="adm-field__sublabel">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500"></label>
            <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required> <span>Confirmo que esta linha descreve a MESMA obra e a MESMA edição. Os dados originais não mudam e a obra anterior fica no histórico.</span></label>
            <?php if (auth_pode('sistema.manutencao')): ?><label class="adm-check"><input type="checkbox" name="fazer_backup" value="1"<?= backup_recente() ? '' : ' checked' ?>> <span>Fazer uma cópia de segurança antes</span></label><?php endif; ?>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('link') ?> Associar registro</button>
        </form>
        <?php endif; ?>

        <?php // Corrigir a obra associada a um exemplar ?>
        <?php
        $exemplaresCorrigir = [];
        if ($itemVer['tipo'] === 'exemplar' && ($itemVer['situacao_exemplar'] ?? '') !== 'baixado') {
            $exemplaresCorrigir = [['id' => $itemVer['id'], 'codigo_individual' => $itemVer['codigo'], 'situacao' => $itemVer['situacao_exemplar'] ?? '']];
        } elseif ($itemVer['tipo'] === 'obra' && $obraD && (int)$obraD['exemplares'] > 0 && $itemVer['candidatos']) {
            $exemplaresCorrigir = db_query("SELECT id, codigo_individual, situacao FROM exemplares WHERE livro_id = ? AND situacao <> 'baixado' ORDER BY codigo_individual LIMIT 100", [(int)$obraD['id']])->fetchAll();
        }
        $destinosEx = array_values(array_filter($itemVer['candidatos'], fn ($c) => !empty($c['livro_id']) && (int)$c['livro_id'] !== (int)($obraD['id'] ?? 0)));
        ?>
        <?php if ($exemplaresCorrigir && ($destinosEx || $itemVer['tipo'] === 'exemplar')): ?>
        <form method="post" action="<?= e($base) ?>" class="adm-form adm-comp-form" data-enviar-uma-vez>
            <h3 class="adm-comp-subtitulo"><?= icon('book-copy') ?> Corrigir a obra associada <?= count($exemplaresCorrigir) === 1 ? 'ao exemplar' : 'aos exemplares' ?></h3>
            <?= csrf_campo() ?><input type="hidden" name="acao" value="corrigir_exemplar"><input type="hidden" name="voltar" value="<?= e($voltarItem) ?>">
            <fieldset><legend class="adm-field__sublabel">Exemplar(es)</legend>
                <?php foreach ($exemplaresCorrigir as $ex): ?>
                    <label class="adm-check"><input type="checkbox" name="exemplares[]" value="<?= (int)$ex['id'] ?>"<?= count($exemplaresCorrigir) === 1 ? ' checked' : '' ?><?= $ex['situacao'] === 'emprestado' ? ' disabled' : '' ?>> <span><strong class="adm-code"><?= e($ex['codigo_individual']) ?></strong><?= $ex['situacao'] === 'emprestado' ? ' <small class="adm-table__muted">(emprestado: registre a devolução antes)</small>' : '' ?></span></label>
                <?php endforeach; ?></fieldset>
            <?php foreach ($destinosEx as $k => $cd): ?>
                <label class="adm-check"><input type="radio" name="destino" value="<?= (int)$cd['livro_id'] ?>"<?= $k === 0 ? ' checked' : '' ?>> <span>Para a obra nº <?= (int)$cd['livro_id'] ?> · <?= e($cd['titulo'] ?? '') ?></span></label>
            <?php endforeach; ?>
            <label class="adm-field"><span class="adm-field__sublabel"><?= $destinosEx ? 'Ou informe o número de outra obra' : 'Número da obra correta' ?></span><input class="adm-input adm-input--sm" name="destino_numero" inputmode="numeric" pattern="[0-9]*" maxlength="12"<?= $destinosEx ? '' : ' required' ?>></label>
            <label class="adm-field"><span class="adm-field__sublabel">Motivo *</span><input class="adm-input" name="motivo" maxlength="500" required placeholder="Ex.: o livro em mãos é da 2ª edição, cadastrada na obra nº 15"></label>
            <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required> <span>Confirmo que o exemplar físico é da obra escolhida. O código BDP, o lote, o carimbo, a localização, a conservação, as ocorrências, os empréstimos e o histórico continuam os mesmos. Nenhum código novo é gerado.</span></label>
            <?= backup_aviso_html(count($exemplaresCorrigir) >= 10) ?>
            <?php if (auth_pode('sistema.manutencao')): ?><label class="adm-check"><input type="checkbox" name="fazer_backup" value="1"<?= backup_recente() ? '' : ' checked' ?>> <span>Fazer uma cópia de segurança antes (recomendado para correções em massa)</span></label><?php endif; ?>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('book-copy') ?> Corrigir obra do exemplar</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($detalhe['historico']): ?>
        <details class="adm-comp-historico"><summary class="adm-link"><?= icon('history') ?> Histórico (<?= count($detalhe['historico']) ?>)</summary>
            <ul class="adm-timeline adm-timeline--compact">
                <?php foreach ($detalhe['historico'] as $h): ?>
                    <li><p><strong><?= e($h['texto']) ?></strong></p>
                        <?php if ($h['alteracoes']): ?><p class="adm-table__muted"><?php foreach ($h['alteracoes'] as $campo => $mud): ?><?= e((string)$campo) ?>: “<?= e(is_array($mud) ? (string)json_encode($mud['antes'] ?? null, JSON_UNESCAPED_UNICODE) : (string)$mud) ?>” → “<?= e(is_array($mud) ? (string)json_encode($mud['depois'] ?? null, JSON_UNESCAPED_UNICODE) : '') ?>” · <?php endforeach; ?></p><?php endif; ?>
                        <small class="adm-table__muted"><?= admin_data_hora($h['quando']) ?><?= $h['quem'] ? ' · ' . e($h['quem']) : '' ?></small></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</section>
<?php elseif ($ver && !$salva): ?>
    <?= admin_aviso('aviso', 'Item não encontrado nesta comparação.', 'Ele pode ter sido corrigido. Atualize a comparação.') ?>
<?php endif; ?>

<?php // ---------- Lista ---------- ?>
<section class="adm-card adm-panel adm-comp-lista" aria-labelledby="titulo-lista">
    <nav class="adm-tabs adm-tabs--links adm-no-print" aria-label="Direção da comparação">
        <?php foreach (Comparacao::DIRECOES as $d => $rot): $ativo = $filtros['direcao'] === $d; ?>
            <a class="adm-tab<?= $ativo ? ' is-active' : '' ?>" href="<?= e(admin_url_filtros($base, array_merge($salva ? ['salva' => $salva['id']] : $origemParams, ['direcao' => $d]))) ?>"<?= $ativo ? ' aria-current="page"' : '' ?>><?= e($rot) ?> <small>(<?= fisico_numero($contagemDirecao[$d]) ?>)</small></a>
        <?php endforeach; ?>
    </nav>
    <h2 id="titulo-lista" class="adm-visually-hidden"><?= e(Comparacao::DIRECOES[$filtros['direcao']]) ?></h2>
    <p class="adm-comp-chips adm-no-print">
        <?php foreach ($contagemCat as $cat => $n): if (!$n) { continue; } $ativo = $filtros['categoria'] === $cat; ?>
            <a class="adm-comp-chip adm-tone--<?= Comparacao::CATEGORIAS[$cat][1] ?><?= $ativo ? ' is-active' : '' ?>" href="<?= e(admin_url_filtros($base, array_merge($salva ? ['salva' => $salva['id']] : $origemParams, ['direcao' => $filtros['direcao'], 'categoria' => $ativo ? null : $cat]))) ?>"<?= $ativo ? ' aria-current="true"' : '' ?>><?= e(Comparacao::rotulo($cat)) ?> <strong><?= fisico_numero($n) ?></strong></a>
        <?php endforeach; ?>
    </p>
    <?php if (!$imprimir): ?>
    <form class="adm-toolbar adm-toolbar--search adm-no-print" method="get" action="<?= e($base) ?>">
        <?php foreach (($salva ? ['salva' => $salva['id']] : $origemParams) + ['direcao' => $filtros['direcao']] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
        <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span><input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Título, autor, ISBN, código BDP, nº da linha ou arquivo"></label>
        <div class="adm-filters">
            <label class="adm-filter"><span>Categoria</span><select class="adm-select" name="categoria"><option value="">Todas</option>
                <?php foreach (Comparacao::CATEGORIAS as $v => [$r]): ?><option value="<?= e($v) ?>"<?= $filtros['categoria'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            <label class="adm-filter"><span>Verificação</span><select class="adm-select" name="verificacao"><option value="">Todas</option><option value="verificada"<?= $filtros['verificacao'] === 'verificada' ? ' selected' : '' ?>>Dados verificados</option><option value="nao_verificada"<?= $filtros['verificacao'] === 'nao_verificada' ? ' selected' : '' ?>>Não verificados</option></select></label>
            <label class="adm-filter"><span>Origem</span><select class="adm-select" name="origem"><option value="">Todas</option><?php foreach (['planilha' => 'Planilha', 'manual' => 'Cadastro manual', 'inventario' => 'Inventário'] as $v => $r): ?><option value="<?= $v ?>"<?= $filtros['origem'] === $v ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></label>
            <label class="adm-filter"><span>Vínculo</span><select class="adm-select" name="vinculo"><option value="">Todos</option><?php foreach (['associado' => 'Associado', 'sem_obra' => 'Sem obra', 'sem_registro' => 'Sem registro na planilha', 'inconsistente' => 'Inconsistente'] as $v => $r): ?><option value="<?= $v ?>"<?= $filtros['vinculo'] === $v ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></label>
            <?php if (count($importacoes) > 1): ?><label class="adm-filter"><span>Arquivo</span><select class="adm-select" name="importacao"><option value="">Todos</option><?php foreach ($importacoes as $imp): ?><option value="<?= (int)$imp['id'] ?>"<?= $filtros['importacao'] === (string)$imp['id'] ? ' selected' : '' ?>><?= e(mb_strimwidth($imp['nome_arquivo'], 0, 40, '…')) ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <label class="adm-filter adm-filter--lote"><span>Lote nº</span><input class="adm-input adm-input--sm" name="lote" value="<?= e($filtros['lote']) ?>" inputmode="numeric" pattern="[0-9]*" maxlength="8"></label>
            <label class="adm-check adm-filter--check"><input type="checkbox" name="atencao" value="1"<?= $filtros['atencao'] ? ' checked' : '' ?>> <span>Só o que exige atenção</span></label>
        </div>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
    </form>
    <?php endif; ?>
    <p class="adm-field__hint" aria-live="polite"><?= fisico_numero($totalFiltrados) ?> item(ns)<?= $filtros['direcao'] === 'planilha' ? ' (registros da planilha)' : ($filtros['direcao'] === 'acervo' ? ' (obras)' : ' (exemplares)') ?></p>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards adm-comp-tabela">
            <thead><tr><th scope="col"><?= $filtros['direcao'] === 'planilha' ? 'Registro da planilha' : ($filtros['direcao'] === 'acervo' ? 'Obra' : 'Exemplar') ?></th><th scope="col">Planilha</th><th scope="col">Acervo</th><th scope="col">Exemplares</th><th scope="col">Situação</th><th scope="col" class="adm-actions-col adm-no-print">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($pagItens as $it): $urlVer = admin_url_filtros($base, array_merge($filtrosLink, ['ver' => $it['chave'], 'pagina' => $pagina > 1 ? $pagina : null])) . '#detalhe'; ?>
                <tr<?= $itemVer && $itemVer['chave'] === $it['chave'] ? ' class="is-selected"' : '' ?>>
                    <td class="adm-td-title"><?php if (!$salva): ?><a class="adm-book-link" href="<?= e($urlVer) ?>"><?= e($it['titulo']) ?></a><?php else: ?><strong><?= e($it['titulo']) ?></strong><?php endif; ?>
                        <small class="adm-block-muted"><?= e(implode(' · ', array_filter([$it['autor'], $it['isbn'] ? 'ISBN ' . $it['isbn'] : null]))) ?></small></td>
                    <td data-label="Planilha"><?= $it['arquivo'] ? e($it['arquivo']) . ' · linha ' . (int)$it['linha'] . ($it['quantidade'] !== null ? ' <small class="adm-table__muted">(qtd. ' . (int)$it['quantidade'] . ')</small>' : '') : ($it['linhas'] ? fisico_numero(count($it['linhas'])) . ' registro(s) <small class="adm-table__muted">(linha ' . e(implode(', ', array_slice($it['linhas'], 0, 4))) . (count($it['linhas']) > 4 ? '…' : '') . ')</small>' : '<span class="adm-table__muted">—</span>') ?></td>
                    <td data-label="Acervo"><?= $it['livro_id'] ? 'Obra nº ' . (int)$it['livro_id'] . ($it['tipo'] === 'registro' && $it['livro_titulo'] && Comparacao::diferente('titulo', $it['livro_titulo'], $it['titulo']) ? ' · ' . e($it['livro_titulo']) : '') . '<small class="adm-block-muted">' . e(comparacao_origem($it['origem'])) . ' · ' . ($it['verificada'] ? 'dados verificados' : 'não verificada') . '</small>' : '<span class="adm-table__muted">Sem obra</span>' ?></td>
                    <td data-label="Exemplares"><?= $it['codigo'] ? '<span class="adm-code">' . e($it['codigo']) . '</span>' : fisico_numero((int)$it['exemplares']) . ($it['encontrados'] !== null ? ' <small class="adm-table__muted">· ' . fisico_numero((int)$it['encontrados']) . ' encontrado(s)</small>' : '') ?></td>
                    <td data-label="Situação"><span class="adm-comp-cats"><?php foreach ($it['categorias'] as $c): ?><?= comparacao_badge($c, in_array($c, $it['resolvidas'], true)) ?> <?php endforeach; ?></span>
                        <?php if ($it['detalhes'] && $imprimir || ($salva && $it['detalhes'])): ?><small class="adm-block-muted"><?= e(implode(' ', $it['detalhes'])) ?></small><?php endif; ?></td>
                    <td class="adm-actions-col adm-no-print"><?php if (!$salva): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($urlVer) ?>"><?= icon('git-compare') ?> Comparar</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pagItens): ?>
                <tr><td colspan="6"><div class="adm-empty"><?= icon('circle-check') ?><strong>Nenhum item com estes filtros</strong><span>Limpe a pesquisa ou escolha outra categoria.</span></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$imprimir): ?><?= admin_paginacao($totalFiltrados, $pagina, $porPagina, $base, array_merge($salva ? ['salva' => (string)$salva['id']] : $origemParams, array_filter($filtros, fn ($v) => $v !== ''))) ?><?php endif; ?>
</section>
<?php endif; ?>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/comparacao.js?v=20261118']));
