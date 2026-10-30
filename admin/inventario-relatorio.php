<?php
/**
 * Relatório do inventário: números, itens por categoria e exportação em CSV e PDF.
 * Contém só dados do acervo e da equipe (quem conferiu). Nenhum dado de aluno.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';
require_once __DIR__ . '/includes/exportar.php';

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Support\Pdf;

fisico_iniciar();
$id = (int)admin_param($_GET, 'id');
$filtro = array_key_exists(admin_param($_GET, 'filtro'), InventarioRepository::FILTROS) ? admin_param($_GET, 'filtro') : 'todos';
$formato = admin_param($_GET, 'formato');
try {
    $inv = inventario_repo()->buscar($id);
    if (!$inv) {
        http_response_code(404);
        admin_pagina_erro('Inventário não encontrado', 'Confira o endereço e tente novamente.', 'clipboard-check');
        exit;
    }
    $resumo = inventario_repo()->resumo($id);
    $todos = in_array($formato, ['csv', 'pdf'], true);
    $itens = inventario_repo()->itens($id, $filtro, '', max(1, (int)admin_param($_GET, 'pagina', '1')), $todos ? 0 : 50);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível gerar o relatório', fisico_erro_tecnico($e, 'relatório do inventário'));
    exit;
}

$linhaItem = function (array $i): array {
    [$situacao] = inventario_item_situacao($i);
    return [
        $i['codigo_individual'], $i['titulo'], $i['autores'] ?? '', $i['isbn'] ?? '',
        (int)$i['previsto'] ? 'Sim' : 'Não', (int)$i['cadastrado_no_inventario'] ? 'Sim' : 'Não',
        $i['localizacao_esperada_id'] ? fisico_local($i, 'esperada_') : 'Sem localização',
        $i['conferido_em'] ? fisico_local($i, 'encontrada_') : '',
        ExemplarService::rotuloConservacao((string)($i['estado_esperado'] ?? $i['estado_conservacao'])),
        $i['estado_encontrado'] ? ExemplarService::rotuloConservacao($i['estado_encontrado']) : '',
        ExemplarService::rotuloSituacao($i['situacao']), $situacao, implode(' | ', inventario_divergencias($i)),
        $i['decisao'] ? InventarioService::DECISOES[$i['decisao']] : '', $i['decisao_observacao'] ?? '',
        $i['conferido_em'] ? admin_data_hora($i['conferido_em']) : '', $i['conferido_por_nome'] ?? '', $i['observacao'] ?? '',
    ];
};
$arquivo = 'inventario-' . $id . '-' . $filtro . '-' . date('Y-m-d');

if ($formato === 'csv') {
    registrar_acao('inventario.exportar', 'inventario', $id, ['formato' => 'csv', 'filtro' => $filtro, 'linhas' => $itens['total']]);
    exportar_csv($arquivo, ['Código', 'Título', 'Autores', 'ISBN', 'Previsto', 'Cadastrado no inventário', 'Localização esperada', 'Localização encontrada',
        'Estado esperado', 'Estado encontrado', 'Situação do exemplar', 'Resultado', 'Divergências', 'Decisão', 'Observação da decisão', 'Conferido em', 'Conferido por', 'Observação'],
        array_map($linhaItem, $itens['itens']));
}

if ($formato === 'pdf') {
    registrar_acao('inventario.exportar', 'inventario', $id, ['formato' => 'pdf', 'filtro' => $filtro, 'linhas' => $itens['total']]);
    $pdf = new Pdf('Relatório do inventário ' . $id);
    $margem = 15;
    $largura = 210 - 2 * $margem;
    $y = 0;
    $pagina = 0;
    $novaPagina = function () use ($pdf, &$y, &$pagina, $inv, $margem, $largura): void {
        $pdf->novaPagina(210, 297);
        $pagina++;
        $pdf->texto($margem, 12, etiquetas_biblioteca() . ' · Relatório do inventário nº ' . (int)$inv['id'], 8, 'normal', 'esquerda', 0.4);
        $pdf->texto($margem + $largura, 12, 'Página ' . $pagina, 8, 'normal', 'direita', 0.4);
        $y = 22;
    };
    $novaPagina();
    $pdf->texto($margem, $y, Pdf::caber($inv['titulo'], $largura, 15, 'negrito'), 15, 'negrito');
    $y += 7;
    $info = 'Situação: ' . InventarioService::STATUS[$inv['status']] . ' · Responsável: ' . ($inv['responsavel_nome'] ?? '—') . ' · ' . ($inv['setor'] ? 'Setor: ' . $inv['setor'] : 'Acervo inteiro')
        . ' · Início: ' . admin_data_hora($inv['data_inicio'] ?? $inv['iniciado_em'], false) . ($inv['concluido_em'] ? ' · Concluído: ' . admin_data_hora($inv['concluido_em']) : '');
    foreach (Pdf::quebrar($info, $largura, 9) as $l) {
        $pdf->texto($margem, $y, $l, 9);
        $y += 4.5;
    }
    $pdf->texto($margem, $y, 'Gerado em ' . date('d/m/Y H:i') . ' · Itens: ' . InventarioRepository::FILTROS[$filtro], 8, 'normal', 'esquerda', 0.4);
    $y += 8;
    $numeros = [
        'Previstos' => $resumo['previstos'], 'Conferidos' => $resumo['conferidos'], 'Não conferidos' => $resumo['nao_conferidos'],
        'Emprestados (fora da estante)' => $resumo['em_circulacao'], 'Localização diferente' => $resumo['local_diferente'], 'Estado divergente' => $resumo['estado_divergente'],
        'Situação divergente' => $resumo['situacao_divergente'], 'Cadastrados no inventário' => $resumo['cadastrados'], 'Fora da lista prevista' => $resumo['fora_lista'],
        'Pendências sem decisão' => $resumo['divergencias_pendentes'] + $resumo['ausentes_pendentes'], 'Registrados como extraviados' => $resumo['extravios'], 'Correções' => $resumo['correcoes'],
    ];
    $col = $largura / 3;
    $k = 0;
    foreach ($numeros as $rotulo => $valor) {
        $x = $margem + ($k % 3) * $col;
        $pdf->contorno($x, $y, $col - 2, 13, 0.2, 0.75);
        $pdf->texto($x + 2.5, $y + 5, $rotulo, 7.5, 'normal', 'esquerda', 0.35);
        $pdf->texto($x + 2.5, $y + 10.5, fisico_numero($valor), 11, 'negrito');
        if (++$k % 3 === 0) {
            $y += 15;
        }
    }
    $y += $k % 3 ? 19 : 4;

    $colunas = [['Código', 22], ['Livro', 62], ['Encontrado em', 38], ['Resultado', 28], ['Decisão', 30]];
    $cabecalho = function () use ($pdf, &$y, $colunas, $margem, $largura): void {
        $pdf->retangulo($margem, $y - 3.8, $largura, 5.6, 0.92);
        $x = $margem + 1;
        foreach ($colunas as [$t, $w]) {
            $pdf->texto($x, $y, $t, 7.5, 'negrito');
            $x += $w;
        }
        $y += 5;
    };
    $cabecalho();
    if (!$itens['itens']) {
        $pdf->texto($margem + 1, $y + 2, 'Nenhum exemplar nesta categoria.', 9, 'normal', 'esquerda', 0.4);
    }
    foreach ($itens['itens'] as $i) {
        [$resultado] = inventario_item_situacao($i);
        $divs = inventario_divergencias($i);
        $livro = Pdf::quebrar($i['titulo'] . ($i['autores'] ? ' — ' . $i['autores'] : ''), $colunas[1][1] - 2, 7.5);
        $livro = array_slice($livro, 0, 2);
        $extra = $divs ? array_slice(Pdf::quebrar(implode(' · ', $divs), $largura - $colunas[0][1] - 2, 6.5), 0, 2) : [];
        $altura = 4 * max(1, count($livro)) + 3.4 * count($extra) + 1.5;
        if ($y + $altura > 285) {
            $novaPagina();
            $cabecalho();
        }
        $x = $margem + 1;
        $pdf->texto($x, $y, $i['codigo_individual'], 7.5, 'negrito');
        $x += $colunas[0][1];
        foreach ($livro as $n => $l) {
            $pdf->texto($x, $y + 4 * $n, $l, 7.5);
        }
        $x += $colunas[1][1];
        $pdf->texto($x, $y, Pdf::caber($i['conferido_em'] ? fisico_local($i, 'encontrada_') : '—', $colunas[2][1] - 2, 7.5), 7.5);
        $x += $colunas[2][1];
        $pdf->texto($x, $y, Pdf::caber($resultado, $colunas[3][1] - 2, 7.5), 7.5);
        $x += $colunas[3][1];
        $pdf->texto($x, $y, Pdf::caber($i['decisao'] ? InventarioService::DECISOES[$i['decisao']] : '—', $colunas[4][1] - 1, 6.5), 6.5);
        $yy = $y + 4 * max(1, count($livro));
        foreach ($extra as $l) {
            $pdf->texto($margem + 1 + $colunas[0][1], $yy - 0.6, $l, 6.5, 'normal', 'esquerda', 0.35);
            $yy += 3.4;
        }
        $y += $altura;
        $pdf->linha($margem, $y - 3.6, $margem + $largura, $y - 3.6, 0.1, 0.85);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $arquivo . '.pdf"');
    header('Cache-Control: no-store');
    echo $pdf->gerar();
    exit;
}

$filtros = ['id' => $id, 'filtro' => $filtro];
admin_page_start('inventario', 'Relatório do inventário', 'Inventário', 'Conferência digital do acervo físico', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], [$inv['titulo'], admin_url('inventario-detalhes') . '?id=' . $id], ['Relatório']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Relatório do inventário</h1>
        <p><?= e($inv['titulo']) ?> · <?= fisico_badge_inventario($inv['status']) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url_filtros(admin_url('inventario-relatorio'), $filtros + ['formato' => 'csv'])) ?>"><?= icon('file-spreadsheet') ?> Exportar CSV</a>
        <a class="adm-button adm-button--primary" href="<?= e(admin_url_filtros(admin_url('inventario-relatorio'), $filtros + ['formato' => 'pdf'])) ?>"><?= icon('file-down') ?> Baixar PDF</a>
    </div>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-numeros">
    <h2 id="titulo-numeros" class="adm-panel__title">Resumo</h2>
    <dl class="adm-meta adm-meta--boxed adm-meta--grid">
        <div><dt>Previstos</dt><dd><?= fisico_numero($resumo['previstos']) ?></dd></div>
        <div><dt>Conferidos</dt><dd><?= fisico_numero($resumo['conferidos']) ?> <small class="adm-table__muted">(<?= fisico_numero($resumo['previstos_conferidos']) ?> previstos)</small></dd></div>
        <div><dt>Não conferidos</dt><dd><?= fisico_numero($resumo['nao_conferidos']) ?></dd></div>
        <div><dt>Emprestados (fora da estante)</dt><dd><?= fisico_numero($resumo['em_circulacao']) ?></dd></div>
        <div><dt>Localização diferente</dt><dd><?= fisico_numero($resumo['local_diferente']) ?></dd></div>
        <div><dt>Estado divergente</dt><dd><?= fisico_numero($resumo['estado_divergente']) ?></dd></div>
        <div><dt>Situação divergente</dt><dd><?= fisico_numero($resumo['situacao_divergente']) ?></dd></div>
        <div><dt>Cadastrados no inventário</dt><dd><?= fisico_numero($resumo['cadastrados']) ?></dd></div>
        <div><dt>Fora da lista prevista</dt><dd><?= fisico_numero($resumo['fora_lista']) ?></dd></div>
        <div><dt>Pendências sem decisão</dt><dd><?= fisico_numero($resumo['divergencias_pendentes'] + $resumo['ausentes_pendentes']) ?></dd></div>
        <div><dt>Registrados como extraviados</dt><dd><?= fisico_numero($resumo['extravios']) ?></dd></div>
        <div><dt>Correções</dt><dd><?= fisico_numero($resumo['correcoes']) ?></dd></div>
    </dl>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-itens">
    <div class="adm-panel__header">
        <div><h2 id="titulo-itens"><?= e(InventarioRepository::FILTROS[$filtro]) ?></h2><p><?= fisico_numero($itens['total']) ?> exemplar(es). A exportação traz todos os itens desta categoria.</p></div>
        <form method="get" action="<?= e(admin_url('inventario-relatorio')) ?>" class="adm-inline-form">
            <input type="hidden" name="id" value="<?= $id ?>">
            <label class="adm-filter"><span>Categoria</span>
                <select class="adm-select" name="filtro" data-auto-submit>
                    <?php foreach (InventarioRepository::FILTROS as $chave => $rotulo): ?><option value="<?= $chave ?>"<?= $filtro === $chave ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?>
                </select></label>
            <noscript><button class="adm-button adm-button--ghost adm-button--sm" type="submit">Filtrar</button></noscript>
        </form>
    </div>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards">
            <thead><tr><th scope="col">Código</th><th scope="col">Livro</th><th scope="col">Esperado</th><th scope="col">Encontrado</th><th scope="col">Resultado</th><th scope="col">Decisão</th></tr></thead>
            <tbody>
                <?php foreach ($itens['itens'] as $i): ?>
                    <?php [$rot, $tom] = inventario_item_situacao($i); ?>
                    <tr>
                        <td class="adm-td-title"><span class="adm-code"><?= e($i['codigo_individual']) ?></span></td>
                        <td data-label="Livro" class="adm-td-wrap"><?= e($i['titulo']) ?></td>
                        <td data-label="Esperado"><?= e($i['localizacao_esperada_id'] ? fisico_local($i, 'esperada_') : 'Sem localização') ?></td>
                        <td data-label="Encontrado"><?= $i['conferido_em'] ? e(fisico_local($i, 'encontrada_')) . '<small class="adm-block-muted">' . admin_data_hora($i['conferido_em']) . '</small>' : '<span class="adm-missing">Não conferido</span>' ?></td>
                        <td data-label="Resultado"><span class="adm-status adm-tone--<?= $tom ?>"><?= e($rot) ?></span><?php foreach (inventario_divergencias($i) as $d): ?><small class="adm-block-muted"><?= e($d) ?></small><?php endforeach; ?></td>
                        <td data-label="Decisão"><?= $i['decisao'] ? e(InventarioService::DECISOES[$i['decisao']]) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$itens['itens']): ?><tr><td colspan="6"><div class="adm-empty"><?= icon('list-checks') ?><strong>Nenhum exemplar nesta categoria.</strong></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= admin_paginacao($itens['total'], $itens['pagina'], 50, admin_url('inventario-relatorio'), $filtros, [50]) ?>
</section>

<?php
admin_page_end(FISICO_JS);
