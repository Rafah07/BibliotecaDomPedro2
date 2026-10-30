<?php
/**
 * Listagem do acervo (registros bibliográficos), com pesquisa, filtros, ordenação e
 * paginação feitos no servidor: só a página atual é enviada ao navegador.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/livros.php';

$filtros = livros_filtros($_GET);
$paginaAtual = max(1, (int)admin_param($_GET, 'pagina', '1'));
try {
    $resultado = livros_listar($filtros, $paginaAtual);
    $resumo = livros_resumo();
    $categorias = livros_categorias_ativas();
} catch (DbIndisponivel $e) {
    http_response_code(503);
    admin_pagina_erro('Banco de dados indisponível', $e->getMessage());
    exit;
} catch (PDOException $e) {
    error_log('[biblioteca] Erro na listagem de livros: ' . $e->getMessage());
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar o acervo', 'Verifique se as migrações foram aplicadas (php database/migrar.php) e tente novamente.');
    exit;
}
$livros = $resultado['itens'];
$base = admin_url('acervo');
$arquivados = $filtros['arquivados'] === '1';
$podeEditar = auth_pode('livros.editar');
$podeArquivar = auth_pode('livros.arquivar');
$fmt = fn (int $n): string => number_format($n, 0, ',', '.');

$cards = [
    ['titulo' => 'Títulos cadastrados', 'valor' => $resumo['titulos'], 'descricao' => 'Registros bibliográficos ativos', 'icone' => 'library-big', 'tom' => 'blue'],
    ['titulo' => 'Exemplares físicos', 'valor' => $resumo['exemplares'], 'descricao' => 'Unidades com código BDP', 'icone' => 'book-copy', 'tom' => 'indigo'],
    ['titulo' => 'Exemplares disponíveis', 'valor' => $resumo['disponiveis'], 'descricao' => 'Prontos para empréstimo', 'icone' => 'circle-check', 'tom' => 'green'],
    ['titulo' => 'Aguardando CDD', 'valor' => $resumo['aguardando_cdd'], 'descricao' => 'Sem classificação aprovada', 'icone' => 'list-tree', 'tom' => 'amber', 'href' => admin_url_filtros($base, ['cdd' => 'aguardando'])],
];

admin_page_start('acervo', 'Livros', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261030', 'assets/css/classificacao.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], [$arquivados ? 'Livros arquivados' : 'Livros']]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= $arquivados ? 'Livros arquivados' : 'Gerenciamento do Acervo' ?></h1>
        <p><?= $arquivados
            ? 'Cadastros retirados do acervo. Eles continuam no banco, com todo o histórico, e podem ser restaurados.'
            : 'Consulte, cadastre e organize os registros bibliográficos da Biblioteca Dom Pedro II.' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($arquivados): ?>
            <a class="adm-button adm-button--ghost" href="<?= e($base) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
        <?php else: ?>
            <?php if (auth_pode('importacao.executar')): ?>
                <a class="adm-button adm-button--ghost" href="<?= e(admin_url('importar')) ?>"><?= icon('file-spreadsheet') ?> Importar planilha</a>
            <?php endif; ?>
            <?php if ($podeEditar): ?>
                <a class="adm-button adm-button--primary" href="<?= e(admin_url('cadastrar-livro')) ?>"><?= icon('plus') ?> Cadastrar livro</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if (!livros_persistem()): ?>
<div class="adm-demo-banner" role="note">
    <?= icon('info') ?>
    <span><strong>Dados demonstrativos.</strong> O banco MySQL não está configurado: os livros abaixo são fictícios e cadastros, edições e arquivamentos são apenas simulados.</span>
</div>
<?php endif; ?>

<?php if (!$arquivados): ?>
<section class="adm-stats" aria-label="Resumo do acervo">
    <?php foreach ($cards as $card): ?>
        <?php $tag = isset($card['href']) ? 'a' : 'article'; ?>
        <<?= $tag ?> class="adm-card adm-stat adm-stat--compact<?= $tag === 'a' ? ' adm-stat--action' : '' ?>"<?= $tag === 'a' ? ' href="' . e($card['href']) . '" title="Mostrar só os livros sem CDD aprovada"' : '' ?>>
            <div class="adm-stat__top">
                <p class="adm-stat__title"><?= e($card['titulo']) ?></p>
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $card['tom'] ?>"><?= icon($card['icone']) ?></span>
            </div>
            <p class="adm-stat__value"><?= $fmt($card['valor']) ?></p>
            <p class="adm-stat__desc"><?= e($card['descricao']) ?></p>
        </<?= $tag ?>>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-lista-livros">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-lista-livros"><?= $arquivados ? 'Cadastros arquivados' : 'Livros cadastrados' ?></h2>
            <p aria-live="polite"><?= $fmt($resultado['total']) ?> <?= $resultado['total'] === 1 ? 'título encontrado' : 'títulos encontrados' ?><?= livros_tem_filtros($filtros) ? ' com os filtros aplicados' : '' ?></p>
        </div>
        <?php if (!$arquivados && livros_persistem() && $podeArquivar): ?>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['arquivados' => '1'])) ?>"><?= icon('archive') ?> Ver arquivados</a>
        <?php endif; ?>
    </div>

    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
        <?php if ($arquivados): ?><input type="hidden" name="arquivados" value="1"><?php endif; ?>
        <label class="adm-field-search adm-toolbar__q">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar livros</span>
            <input type="search" name="q" placeholder="Pesquisar por título, autor, editora ou ISBN..." value="<?= e($filtros['q']) ?>" autocomplete="off">
        </label>
        <div class="adm-filters adm-filters--5">
            <label class="adm-filter">
                <span>Categoria</span>
                <select class="adm-select<?= $filtros['categoria'] !== '' ? ' has-value' : '' ?>" name="categoria">
                    <option value="">Todas</option>
                    <option value="sem"<?= $filtros['categoria'] === 'sem' ? ' selected' : '' ?>>Sem categoria</option>
                    <?php foreach ($categorias as $cid => $nome): ?>
                        <option value="<?= (int)$cid ?>"<?= $filtros['categoria'] === (string)$cid ? ' selected' : '' ?>><?= e($nome) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Situação da CDD</span>
                <select class="adm-select<?= $filtros['cdd'] !== '' ? ' has-value' : '' ?>" name="cdd">
                    <option value="">Todas</option>
                    <option value="aguardando"<?= $filtros['cdd'] === 'aguardando' ? ' selected' : '' ?>>Aguardando reclassificação (não aprovada)</option>
                    <?php foreach (CDD_STATUS as $valor => $s): ?>
                        <option value="<?= $valor ?>"<?= $filtros['cdd'] === $valor ? ' selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Situação do cadastro</span>
                <select class="adm-select<?= $filtros['situacao'] !== '' ? ' has-value' : '' ?>" name="situacao">
                    <option value="">Todas</option>
                    <?php foreach (ACERVO_STATUS as $valor => $s): ?>
                        <option value="<?= $valor ?>"<?= $filtros['situacao'] === $valor ? ' selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Origem do registro</span>
                <select class="adm-select<?= $filtros['origem'] !== '' ? ' has-value' : '' ?>" name="origem">
                    <option value="">Todas</option>
                    <?php foreach (ACERVO_ORIGENS as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"<?= $filtros['origem'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter">
                <span>Ordenar por</span>
                <select class="adm-select" name="ordenacao">
                    <?php
                    $opcoesOrdem = ['titulo:asc' => 'Título (A–Z)', 'titulo:desc' => 'Título (Z–A)', 'autor:asc' => 'Autor (A–Z)', 'autor:desc' => 'Autor (Z–A)', 'cadastro:desc' => 'Cadastrados recentemente', 'cadastro:asc' => 'Cadastrados há mais tempo'];
                    $ordemAtual = $filtros['ordem'] . ':' . $filtros['dir'];
                    foreach ($opcoesOrdem as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"<?= $ordemAtual === $valor ? ' selected' : '' ?>><?= $rotulo ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <input type="hidden" name="por_pagina" value="<?= e($filtros['por_pagina']) ?>">
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['arquivados' => $filtros['arquivados']])) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
    </form>

    <div class="adm-table-wrap adm-table-wrap--flush" data-loading-area>
        <table class="adm-table adm-table--cards adm-table--livros">
            <thead>
                <tr>
                    <?= admin_th_ordenar('Título', 'titulo', $base, $filtros) ?>
                    <?= admin_th_ordenar('Autor', 'autor', $base, $filtros) ?>
                    <th scope="col">ISBN</th>
                    <th scope="col">Editora / ano</th>
                    <th scope="col">CDD</th>
                    <th scope="col">Situação da CDD</th>
                    <th scope="col" class="adm-num" title="Exemplares físicos cadastrados (sem baixados e extraviados)">Exemplares</th>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($livros as $l): ?>
                    <?php $urlDetalhes = admin_url('detalhes-livro') . '?id=' . $l['id']; ?>
                    <tr>
                        <td class="adm-td-title">
                            <?php if ($arquivados): ?>
                                <strong><?= e($l['titulo']) ?></strong>
                            <?php else: ?>
                                <a class="adm-book-link" href="<?= e($urlDetalhes) ?>"><?= e($l['titulo']) ?></a>
                            <?php endif; ?>
                            <?php if ($l['subtitulo']): ?><small><?= e($l['subtitulo']) ?></small><?php endif; ?>
                            <?php if ($arquivados): ?><small>Arquivado em <?= admin_data_hora($l['arquivado_em'], false) ?></small><?php endif; ?>
                            <small class="adm-row-tags">
                                <?= acervo_badge_status($l['status_validacao']) ?>
                                <?php if ($l['origem_cadastro'] === 'planilha'): ?><span class="adm-chip">Planilha</span><?php endif; ?>
                                <?php if ($l['categorias']): ?><span class="adm-chip"><?= e($l['categorias']) ?></span><?php endif; ?>
                            </small>
                        </td>
                        <td data-label="Autor"><?= $l['autores'] ? e(str_replace('; ', ', ', $l['autores'])) : '<span class="adm-missing">Não informado</span>' ?></td>
                        <td data-label="ISBN" class="adm-mono"><?= $l['isbn'] ? e($l['isbn']) : '<span class="adm-missing">—</span>' ?></td>
                        <td data-label="Editora / ano"><?= $l['editora'] ? e($l['editora']) : '<span class="adm-missing">Editora não informada</span>' ?>
                            <small><?= $l['ano_publicacao'] ? (int)$l['ano_publicacao'] : 'Ano não informado' ?><?= $l['edicao'] ? ' · ' . e($l['edicao']) : '' ?></small></td>
                        <td data-label="CDD"><?= livros_cdd_html($l) ?></td>
                        <td data-label="Situação da CDD"><?= cdd_badge_status($l['cdd_status']) ?></td>
                        <td data-label="Exemplares" class="adm-num">
                            <strong><?= (int)$l['exemplares'] ?></strong>
                            <?php if ($l['exemplares'] > 0): ?><small class="adm-num__hint"><?= (int)$l['disponiveis'] ?> disp.</small><?php endif; ?>
                        </td>
                        <td class="adm-actions-col">
                            <div class="adm-row-actions">
                                <?php if ($arquivados): ?>
                                    <?php if ($podeArquivar): ?>
                                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('arquivar-livro') . '?id=' . $l['id'] . '&acao=restaurar') ?>"><?= icon('archive-restore') ?> Restaurar</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a class="adm-icon-button adm-icon-button--sm" href="<?= e($urlDetalhes) ?>" aria-label="Ver detalhes de <?= e($l['titulo']) ?>" title="Ver detalhes"><?= icon('eye') ?></a>
                                    <?php if ($podeEditar): ?>
                                        <a class="adm-icon-button adm-icon-button--sm" href="<?= e(admin_url('editar-livro') . '?id=' . $l['id']) ?>" aria-label="Editar <?= e($l['titulo']) ?>" title="Editar"><?= icon('pencil') ?></a>
                                    <?php endif; ?>
                                    <?php if ($podeArquivar && livros_persistem()): ?>
                                        <a class="adm-icon-button adm-icon-button--sm adm-icon-button--danger" href="<?= e(admin_url('arquivar-livro') . '?id=' . $l['id']) ?>" aria-label="Arquivar <?= e($l['titulo']) ?>" title="Arquivar"><?= icon('archive') ?></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$livros): ?>
                    <tr>
                        <td colspan="8">
                            <div class="adm-empty">
                                <?= icon($arquivados ? 'archive' : 'book-x') ?>
                                <?php if (livros_tem_filtros($filtros)): ?>
                                    <strong>Nenhum livro encontrado</strong>
                                    <span>Nenhum título corresponde à pesquisa<?= $filtros['q'] !== '' ? ' "' . e($filtros['q']) . '"' : '' ?> e aos filtros escolhidos. Confira a grafia ou remova algum filtro.</span>
                                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['arquivados' => $filtros['arquivados']])) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
                                <?php elseif ($arquivados): ?>
                                    <strong>Nenhum livro arquivado</strong>
                                    <span>Os cadastros arquivados aparecerão aqui.</span>
                                <?php else: ?>
                                    <strong>O acervo ainda está vazio</strong>
                                    <span>Cadastre o primeiro livro ou importe a planilha do acervo.</span>
                                    <?php if ($podeEditar): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url('cadastrar-livro')) ?>"><?= icon('plus') ?> Cadastrar livro</a><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtros, LIVROS_POR_PAGINA) ?>
</section>

<?php
admin_page_end();
