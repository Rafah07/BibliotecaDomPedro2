<?php
/**
 * Central de Qualidade do Acervo (Acervo → Qualidade do acervo; migração 190).
 *
 *   ?aba=pendencias (padrão)   painel, filtros e lista paginada das pendências da última análise
 *   ?aba=padronizacao&tipo=autor|editora|categoria   grafias equivalentes, obras afetadas e aplicação
 *   ?aba=comparar&a=ID&b=ID    duas obras lado a lado: obras diferentes, corrigir, relacionar linhas ou unificar
 *
 * A análise roda só quando alguém clica em "Atualizar análise" (ou pela rotina database/analisar-qualidade.php):
 * abrir esta página apenas lê a tabela de pendências. Nada aqui bloqueia o cadastramento, e nenhuma
 * correção é aplicada sem confirmação.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';
require_once __DIR__ . '/includes/backup.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ComparacaoPlanilhaService;
use Biblioteca\Services\QualidadeAcervoService as Qualidade;

$usuario = fisico_iniciar('qualidade.ver');
$servico = new Qualidade(db());
$base = admin_url('qualidade');
$aba = in_array(admin_param($_GET, 'aba'), ['pendencias', 'padronizacao', 'comparar'], true) ? admin_param($_GET, 'aba') : 'pendencias';
$voltar = admin_param($_POST, 'voltar') !== '' && str_starts_with(admin_param($_POST, 'voltar'), $base) ? admin_param($_POST, 'voltar') : $base;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    try {
        switch ($acao) {
            case 'analisar':
                fisico_exigir('qualidade.resolver');
                @set_time_limit(300);
                $t = $servico->analisar((int)$usuario['id']);
                admin_flash('sucesso', 'Análise atualizada.', $t['critico'] . ' crítica(s), ' . $t['atencao'] . ' de atenção e ' . $t['informativo'] . ' informativa(s). ' . $t['novas'] . ' nova(s) e ' . $t['resolvidas'] . ' resolvida(s) desde a análise anterior. Nenhum dado do acervo foi alterado.');
                break;
            case 'decidir':
                fisico_exigir('qualidade.resolver');
                $servico->decidir(admin_param($_POST, 'chave'), admin_param($_POST, 'decisao'), admin_param($_POST, 'justificativa'), (int)$usuario['id']);
                admin_flash('sucesso', 'Decisão registrada.', 'A pendência não aparece mais como aberta. Se o registro for alterado depois, ela volta a ser avaliada na próxima análise.');
                break;
            case 'reabrir':
                fisico_exigir('qualidade.resolver');
                $servico->reabrir(admin_param($_POST, 'chave'), admin_param($_POST, 'motivo'), (int)$usuario['id']);
                admin_flash('sucesso', 'Pendência reaberta.');
                break;
            case 'padronizar':
                fisico_exigir('qualidade.resolver');
                if (admin_param($_POST, 'confirmo') !== '1') {
                    throw new AcervoException('Confira as obras afetadas e marque a confirmação.');
                }
                $tipo = admin_param($_POST, 'tipo');
                $sel = (array)($_POST['valores'] ?? []);
                $r = match ($tipo) {
                    'autor' => $servico->padronizarAutores($sel, admin_param($_POST, 'nome_final'), (int)$usuario['id']),
                    'editora' => $servico->padronizarEditoras($sel, admin_param($_POST, 'nome_final'), (int)$usuario['id']),
                    'categoria' => $servico->padronizarCategorias($sel, (int)admin_param($_POST, 'destino'), (int)$usuario['id']),
                    default => throw new AcervoException('Tipo de padronização desconhecido.'),
                };
                admin_flash('sucesso', 'Padronização aplicada: “' . $r['nome'] . '”.', $r['obras'] . ' obra(s) atualizada(s). A alteração ficou no histórico de cada obra (valor anterior, novo valor, responsável e data) e a verificação dos dados foi mantida.');
                break;
            case 'relacionar':
                fisico_exigir('inventario.gerenciar');
                $destino = (int)admin_param($_POST, 'destino');
                $ids = array_filter(array_map('intval', (array)($_POST['registros'] ?? [])));
                if (!$ids) {
                    throw new AcervoException('Escolha as linhas da planilha.');
                }
                $comparacao = new ComparacaoPlanilhaService(db());
                foreach ($ids as $rid) {
                    $comparacao->associarRegistro($rid, $destino, (int)$usuario['id'], admin_param($_POST, 'confirmo') === '1', 'Relacionado pela Central de Qualidade');
                }
                admin_flash('sucesso', count($ids) . ' linha(s) da planilha relacionada(s) à obra nº ' . $destino . '.', 'Os dados originais da planilha não mudaram; a obra anterior ficou guardada no histórico do vínculo.');
                break;
            case 'unificar':
                fisico_exigir('obras.unificar');
                if (admin_param($_POST, 'fazer_backup') === '1') {
                    backup_antes_de($usuario, 'Antes de unificar as obras nº ' . (int)admin_param($_POST, 'mantido') . ' e nº ' . (int)admin_param($_POST, 'unificado'));
                }
                $mantido = (int)admin_param($_POST, 'mantido');
                $r = $servico->unificar($mantido, (int)admin_param($_POST, 'unificado'), admin_param($_POST, 'motivo'), (int)$usuario['id'],
                    admin_param($_POST, 'confirmo') === '1', admin_param($_POST, 'mesma_edicao') === '1');
                admin_flash('sucesso', 'Cadastros unificados.', count($r['exemplares']) . ' exemplar(es) (' . (implode(', ', $r['exemplares']) ?: 'nenhum') . ') e ' . $r['registros'] . ' linha(s) da planilha passaram para a obra nº ' . $mantido . '. Os códigos BDP não mudaram; o outro cadastro foi arquivado com o histórico completo.');
                admin_redirecionar(admin_url('detalhes-livro') . '?id=' . $mantido);
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado.', fisico_erro_tecnico($e, 'central de qualidade (' . $acao . ')'));
    }
    admin_redirecionar($voltar);
}

$podeResolver = auth_pode('qualidade.resolver');
try {
    $ind = $servico->indicadores();
    if ($aba === 'pendencias') {
        $f = [
            'grupo' => array_key_exists(admin_param($_GET, 'grupo'), Qualidade::GRUPOS) ? admin_param($_GET, 'grupo') : '',
            'gravidade' => array_key_exists(admin_param($_GET, 'gravidade'), Qualidade::GRAVIDADES) ? admin_param($_GET, 'gravidade') : '',
            'tipo' => array_key_exists(admin_param($_GET, 'tipo'), Qualidade::TIPOS) ? admin_param($_GET, 'tipo') : '',
            'origem' => in_array(admin_param($_GET, 'origem'), ['planilha', 'manual', 'inventario', 'sistema'], true) ? admin_param($_GET, 'origem') : '',
            'situacao' => array_key_exists(admin_param($_GET, 'situacao', 'aberta'), Qualidade::SITUACOES) ? admin_param($_GET, 'situacao', 'aberta') : '',
            'responsavel' => (string)(int)admin_param($_GET, 'responsavel') ?: '',
            'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
        ];
        $lista = $servico->listar($f, max(1, (int)admin_param($_GET, 'pagina', '1')), 30);
        $responsaveis = db_query('SELECT DISTINCT a.id, a.nome FROM qualidade_pendencias p JOIN administradores a ON a.id = p.responsavel_id ORDER BY a.nome')->fetchAll();
    } elseif ($aba === 'padronizacao') {
        $tipoPad = in_array(admin_param($_GET, 'tipo'), ['autor', 'editora', 'categoria'], true) ? admin_param($_GET, 'tipo') : 'autor';
        $grupos = $servico->gruposVariantes($tipoPad);
        $grupoSel = (int)admin_param($_GET, 'grupo', '-1');
        $afetadas = isset($grupos[$grupoSel]) ? $servico->obrasAfetadas($tipoPad, $tipoPad === 'editora' ? array_column($grupos[$grupoSel], 'nome') : array_column($grupos[$grupoSel], 'id')) : [];
    } else {
        $fa = $servico->fichaComparacao((int)admin_param($_GET, 'a'));
        $fb = $servico->fichaComparacao((int)admin_param($_GET, 'b'));
        $chaveDup = $fa && $fb ? 'duplicidade:' . min((int)$fa['id'], (int)$fb['id']) . ':' . max((int)$fa['id'], (int)$fb['id']) : '';
        $pendDup = $chaveDup ? $servico->pendencia($chaveDup) : null;
        $difEdicao = $fa && $fb ? Qualidade::diferencasEdicao($fa, $fb) : [];
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a Central de Qualidade', fisico_erro_tecnico($e, 'central de qualidade') . ' Confira se a migração 190 foi aplicada.');
    exit;
}

/** Link da ação sugerida para cada pendência (resolve sem sair do fluxo da Central). */
function qualidade_link(array $p): ?string
{
    $id = $p['alvo_id'] !== null ? (int)$p['alvo_id'] : null;
    $id2 = $p['alvo2_id'] !== null ? (int)$p['alvo2_id'] : null;
    return match ($p['tipo']) {
        'obra_sem_autor', 'isbn_invalido', 'ano_contraditorio', 'obra_sem_categoria', 'obra_nao_verificada' => $id ? admin_url('editar-livro') . '?id=' . $id : null,
        'obra_sem_cdd' => $id ? admin_url('classificar-livro') . '?id=' . $id : null,
        'duplicidade' => admin_url('qualidade') . '?aba=comparar&a=' . $id . '&b=' . $id2,
        'autor_variacao' => admin_url('qualidade') . '?aba=padronizacao&tipo=autor',
        'editora_variacao' => admin_url('qualidade') . '?aba=padronizacao&tipo=editora',
        'categoria_variacao' => admin_url('qualidade') . '?aba=padronizacao&tipo=categoria',
        'registro_sem_obra' => admin_url('inventario-comparacao'),
        'exemplar_obra_invalida' => $id ? admin_url('detalhes-exemplar') . '?id=' . $id . '&acao=obra' : null,
        'localizacao_desativada' => $id ? admin_url('detalhes-exemplar') . '?id=' . $id . '&acao=localizacao' : null,
        'codigo_bdp_invalido', 'situacao_contraditoria', 'disponivel_bloqueado' => $id ? admin_url('detalhes-exemplar') . '?id=' . $id : null,
        'ocorrencia_antiga' => $id ? admin_url('ocorrencias') . '?exemplar=' . $id . '&status=aberta' : null,
        'lote_inconsistente' => $id ? admin_url('lote') . '?id=' . $id : null,
        'inventario_sem_decisao' => $id2 ? admin_url('inventario-detalhes') . '?id=' . $id2 : null,
        'aguardando_carimbo' => admin_url('planilha-bdp') . '?aba=carimbo' . ($id ? '&lote=' . $id : ''),
        'aguardando_organizacao' => admin_url('planilha-bdp') . '?aba=triagem' . ($id ? '&lote=' . $id : ''),
        'sequencia_bdp' => admin_url('manutencao') . '#titulo-diagnostico',
        default => null,
    };
}

$tons = ['critico' => 'red', 'atencao' => 'amber', 'informativo' => 'blue'];
$icones = ['bibliografico' => 'book-open', 'exemplar' => 'book-copy', 'padronizacao' => 'list-checks', 'duplicidade' => 'copy'];

admin_page_start('qualidade', 'Qualidade do acervo', 'Acervo', 'Central de Qualidade do Acervo', array_merge(FISICO_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Qualidade do acervo']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Central de Qualidade do Acervo</h1>
        <p>Inconsistências dos dados atuais, para resolver aos poucos. Nada aqui bloqueia o cadastramento e nada é corrigido sem a sua confirmação.
            <?= $ind['ultima'] ? 'Última análise: ' . e(admin_data_hora($ind['ultima']['concluida_em'])) . ($ind['ultima']['administrador_nome'] ? ' por ' . e($ind['ultima']['administrador_nome']) : '') . '.' : 'Nenhuma análise feita ainda.' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeResolver): ?>
            <form method="post" data-loading-form>
                <?= csrf_campo() ?><input type="hidden" name="acao" value="analisar"><input type="hidden" name="voltar" value="<?= e($base) ?>">
                <button class="adm-button adm-button--primary" type="submit"><?= icon('refresh-cw') ?> Atualizar análise</button>
            </form>
        <?php endif; ?>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('inventario-comparacao')) ?>"><?= icon('git-compare') ?> Comparar com a planilha original</a>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats" aria-label="Indicadores da qualidade">
    <?= fisico_stat('Pendências críticas', $ind['criticas'] ?? 0, 'Integridade dos dados ou funcionamento', 'circle-alert', 'red', admin_url_filtros($base, ['gravidade' => 'critico'])) ?>
    <?= fisico_stat('Possíveis duplicidades', $ind['duplicidades'] ?? 0, 'Obras que podem ser o mesmo cadastro', 'copy', 'amber', admin_url_filtros($base, ['tipo' => 'duplicidade'])) ?>
    <?= fisico_stat('Informações incompletas', $ind['incompletas'] ?? 0, 'Autor, categoria, CDD ou ISBN', 'file-search', 'blue', admin_url_filtros($base, ['grupo' => 'bibliografico'])) ?>
    <?= fisico_stat('Inconsistências bibliográficas', $ind['inconsistencias'] ?? 0, 'Grafias, ISBN inválido, ano impossível', 'list-checks', 'amber', admin_url_filtros($base, ['grupo' => 'padronizacao'])) ?>
    <?= fisico_stat('Problemas de associação', $ind['associacao'] ?? 0, 'Exemplar, linha da planilha ou lote', 'link', 'red', admin_url_filtros($base, ['grupo' => 'exemplar'])) ?>
    <?= fisico_stat('Aguardando análise', $ind['abertas'] ?? 0, ($ind['justificadas'] ?? 0) . ' justificada(s) · ' . ($ind['resolvidas'] ?? 0) . ' resolvida(s)', 'inbox', 'muted', admin_url_filtros($base, ['situacao' => 'aberta'])) ?>
</section>

<nav class="adm-tabs adm-tabs--pagina" aria-label="Seções da Central de Qualidade">
    <?php foreach (['pendencias' => ['list-checks', 'Pendências'], 'padronizacao' => ['sparkles', 'Padronização'], 'comparar' => ['git-compare', 'Comparar obras']] as $a => [$ic, $rot]): ?>
        <a class="adm-tab<?= $aba === $a ? ' is-active' : '' ?>" href="<?= e($base . '?aba=' . $a) ?>"<?= $aba === $a ? ' aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($rot) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($aba === 'pendencias'): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-pendencias">
        <div class="adm-panel__header"><div><h2 id="titulo-pendencias">Pendências</h2><p aria-live="polite"><?= fisico_numero($lista['total']) ?> encontrada(s) com estes filtros.
            Pendências normais de trabalho (aguardando carimbo ou organização) aparecem como <strong>Informativo</strong>.</p></div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Título, código BDP, autor..."></label>
            <div class="adm-filters">
                <label class="adm-filter"><span>Situação</span><select class="adm-select" name="situacao"><option value="">Todas</option><?php foreach (Qualidade::SITUACOES as $v => $r): ?><option value="<?= $v ?>"<?= $f['situacao'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Gravidade</span><select class="adm-select" name="gravidade"><option value="">Todas</option><?php foreach (Qualidade::GRAVIDADES as $v => $r): ?><option value="<?= $v ?>"<?= $f['gravidade'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Área</span><select class="adm-select" name="grupo"><option value="">Todas</option><?php foreach (Qualidade::GRUPOS as $v => $r): ?><option value="<?= $v ?>"<?= $f['grupo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Tipo</span><select class="adm-select" name="tipo"><option value="">Todos</option><?php foreach (Qualidade::TIPOS as $v => $t): ?><option value="<?= $v ?>"<?= $f['tipo'] === $v ? ' selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Origem</span><select class="adm-select" name="origem"><option value="">Todas</option><?php foreach (['planilha' => 'Planilha importada', 'manual' => 'Cadastro manual', 'inventario' => 'Inventário', 'sistema' => 'Verificação do sistema'] as $v => $r): ?><option value="<?= $v ?>"<?= $f['origem'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                <?php if ($responsaveis): ?><label class="adm-filter"><span>Responsável</span><select class="adm-select" name="responsavel"><option value="">Todos</option><?php foreach ($responsaveis as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $f['responsavel'] === (string)$r['id'] ? ' selected' : '' ?>><?= e($r['nome']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
            </div>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
        </form>
        <?php if (!$ind['ultima']): ?>
            <div class="adm-empty adm-empty--left"><?= icon('sparkles') ?><strong>Nenhuma análise foi feita ainda.</strong><span><?= $podeResolver ? 'Clique em “Atualizar análise” para identificar as pendências do acervo.' : 'Peça à bibliotecária para atualizar a análise.' ?></span></div>
        <?php elseif (!$lista['itens']): ?>
            <div class="adm-empty adm-empty--left"><?= icon('circle-check') ?><strong>Nenhuma pendência com estes filtros.</strong></div>
        <?php else: ?>
            <ul class="adm-gestao-lista">
                <?php foreach ($lista['itens'] as $p): $link = qualidade_link($p); $justificar = (int)admin_param($_GET, 'justificar') === (int)$p['id']; ?>
                    <li class="adm-gestao-item is-<?= e($p['gravidade']) ?> is-<?= e($p['situacao']) ?>" id="p<?= (int)$p['id'] ?>">
                        <span class="adm-gestao-item__icone adm-tone--<?= $tons[$p['gravidade']] ?>"><?= icon($icones[$p['grupo']] ?? 'info') ?></span>
                        <div class="adm-gestao-item__corpo">
                            <strong><?= e(Qualidade::TIPOS[$p['tipo']][0] ?? $p['tipo']) ?></strong>
                            <span class="adm-status adm-tone--<?= $tons[$p['gravidade']] ?>"><?= e(Qualidade::GRAVIDADES[$p['gravidade']]) ?></span>
                            <?php if ($p['situacao'] !== 'aberta'): ?><span class="adm-status adm-tone--<?= $p['situacao'] === 'resolvida' ? 'green' : 'muted' ?>"><?= e(Qualidade::SITUACOES[$p['situacao']]) ?></span><?php endif; ?>
                            <p><?= e($p['descricao']) ?></p>
                            <?php if ($p['situacao'] === 'justificada' && $p['justificativa']): ?><p><?= icon('message-square-text') ?> <?= e(Qualidade::DECISOES[$p['decisao']] ?? '') ?>: <?= e($p['justificativa']) ?></p><?php endif; ?>
                            <p class="adm-table__muted">Identificada em <?= e(admin_data_hora($p['primeira_vez_em'])) ?><?= $p['responsavel_nome'] ? ' · cadastro de ' . e($p['responsavel_nome']) : '' ?><?= $p['resolvida_em'] ? ' · resolvida em ' . e(admin_data_hora($p['resolvida_em'])) : '' ?></p>
                        </div>
                        <div class="adm-gestao-item__acoes">
                            <?php if ($link && $p['situacao'] !== 'resolvida'): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($link) ?>"><?= icon('arrow-right') ?> <?= e(Qualidade::acao($p['tipo'])) ?></a><?php endif; ?>
                            <?php if ($podeResolver && $p['situacao'] === 'aberta'): ?>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, array_filter($f, fn ($v) => $v !== ''), ['justificar' => (int)$p['id'], 'pagina' => $lista['pagina']])) ?>#p<?= (int)$p['id'] ?>"><?= icon('message-square-text') ?> Justificar</a>
                            <?php elseif ($podeResolver && $p['situacao'] === 'justificada'): ?>
                                <form method="post" data-enviar-uma-vez><?= csrf_campo() ?><input type="hidden" name="acao" value="reabrir"><input type="hidden" name="chave" value="<?= e($p['chave']) ?>"><input type="hidden" name="voltar" value="<?= e(admin_url_filtros($base, array_filter($f, fn ($v) => $v !== ''))) ?>">
                                    <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('undo-2') ?> Reabrir</button></form>
                            <?php endif; ?>
                        </div>
                        <?php if ($justificar && $podeResolver): ?>
                            <form method="post" class="adm-gestao-justificar" data-enviar-uma-vez>
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="decidir"><input type="hidden" name="chave" value="<?= e($p['chave']) ?>">
                                <input type="hidden" name="voltar" value="<?= e(admin_url_filtros($base, array_filter($f, fn ($v) => $v !== ''), ['pagina' => $lista['pagina']])) ?>">
                                <label class="adm-field"><span class="adm-field__sublabel">Decisão</span><select class="adm-select" name="decisao">
                                    <?php foreach (Qualidade::DECISOES as $dv => $dr): if ($dv === 'reaberta' || ($dv === 'nao_duplicidade' && $p['grupo'] !== 'duplicidade' && $p['grupo'] !== 'padronizacao')) { continue; } ?>
                                        <option value="<?= $dv ?>"<?= ($p['grupo'] === 'duplicidade' && $dv === 'nao_duplicidade') || ($p['grupo'] === 'bibliografico' && $dv === 'indisponivel') ? ' selected' : '' ?>><?= e($dr) ?></option>
                                    <?php endforeach; ?></select></label>
                                <label class="adm-field"><span class="adm-field__sublabel">Justificativa *</span><input class="adm-input" name="justificativa" required maxlength="1000" placeholder="Ex.: livro de 1958, não há ISBN impresso"></label>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Registrar</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?= admin_paginacao($lista['total'], $lista['pagina'], 30, $base, array_filter($f, fn ($v) => $v !== ''), [30]) ?>
        <?php endif; ?>
    </section>

<?php elseif ($aba === 'padronizacao'): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-pad">
        <div class="adm-panel__header"><div><h2 id="titulo-pad">Padronização</h2>
            <p>Grafias diferentes do mesmo valor (maiúsculas, acentos, pontuação, “SOBRENOME, Nome”, “Editora”/“Ltda”, abreviações). Nomes apenas parecidos não são sugeridos: pessoas diferentes nunca são unidas por semelhança.</p></div></div>
        <nav class="adm-tabs adm-tabs--sub" aria-label="Tipo">
            <?php foreach (['autor' => 'Autores', 'editora' => 'Editoras', 'categoria' => 'Categorias'] as $t => $r): ?>
                <a class="adm-tab<?= $tipoPad === $t ? ' is-active' : '' ?>" href="<?= e($base . '?aba=padronizacao&tipo=' . $t) ?>"><?= e($r) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if (!$grupos): ?>
            <div class="adm-empty adm-empty--left"><?= icon('circle-check') ?><strong>Nenhuma variação encontrada.</strong></div>
        <?php else: ?>
            <ul class="adm-gestao-lista">
                <?php foreach ($grupos as $gi => $g): ?>
                    <li class="adm-gestao-item is-atencao" id="g<?= $gi ?>">
                        <span class="adm-gestao-item__icone adm-tone--amber"><?= icon('sparkles') ?></span>
                        <div class="adm-gestao-item__corpo">
                            <strong><?= e(implode(' · ', array_column($g, 'nome'))) ?></strong>
                            <p><?= array_sum(array_column($g, 'obras')) ?> obra(s) no total.</p>
                        </div>
                        <div class="adm-gestao-item__acoes"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?aba=padronizacao&tipo=' . $tipoPad . '&grupo=' . $gi . '#g' . $gi) ?>"><?= icon('eye') ?> Comparar registros</a></div>
                        <?php if ($grupoSel === $gi && $podeResolver): ?>
                            <form method="post" class="adm-gestao-justificar" data-confirm="Aplicar a padronização às obras listadas?">
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="padronizar"><input type="hidden" name="tipo" value="<?= e($tipoPad) ?>">
                                <input type="hidden" name="voltar" value="<?= e($base . '?aba=padronizacao&tipo=' . $tipoPad) ?>">
                                <fieldset class="adm-field"><legend class="adm-field__sublabel">Grafias que serão padronizadas</legend>
                                    <?php foreach ($g as $v): ?><label class="adm-check"><input type="checkbox" name="valores[]" value="<?= e($tipoPad === 'editora' ? $v['nome'] : (string)$v['id']) ?>" checked> <?= e($v['nome']) ?> <small>(<?= (int)$v['obras'] ?>)</small></label><?php endforeach; ?></fieldset>
                                <?php if ($tipoPad === 'categoria'): ?>
                                    <label class="adm-field"><span class="adm-field__sublabel">Categoria que fica</span><select class="adm-select" name="destino"><?php foreach ($g as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['nome']) ?></option><?php endforeach; ?></select></label>
                                <?php else: ?>
                                    <label class="adm-field"><span class="adm-field__sublabel">Nome padronizado *</span><input class="adm-input" name="nome_final" required maxlength="150" value="<?= e($g[0]['nome']) ?>"></label>
                                <?php endif; ?>
                                <div class="adm-field adm-field--full">
                                    <span class="adm-field__sublabel">Obras afetadas (<?= count($afetadas) ?><?= count($afetadas) >= 200 ? '+' : '' ?>)</span>
                                    <ul class="adm-versao-diff"><?php foreach (array_slice($afetadas, 0, 30) as $o): ?><li><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$o['id']) ?>" target="_blank" rel="noopener"><?= e($o['titulo']) ?></a> — hoje: “<?= e($o['valor']) ?>”</li><?php endforeach; ?></ul>
                                </div>
                                <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required><span>Conferi as obras afetadas: as grafias acima são a mesma <?= $tipoPad === 'autor' ? 'pessoa' : 'informação' ?>.</span></label>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Aplicar padronização</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

<?php else: ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-comparar">
        <div class="adm-panel__header"><div><h2 id="titulo-comparar">Comparar obras</h2><p>Informe os números das duas obras (aparecem na página de cada obra) ou abra uma possível duplicidade na lista de pendências.</p></div></div>
        <form class="adm-comparar-form" method="get" action="<?= e($base) ?>">
            <input type="hidden" name="aba" value="comparar">
            <label class="adm-filter"><span>Obra A (nº)</span><input class="adm-input" type="number" name="a" min="1" value="<?= e(admin_param($_GET, 'a')) ?>"></label>
            <label class="adm-filter"><span>Obra B (nº)</span><input class="adm-input" type="number" name="b" min="1" value="<?= e(admin_param($_GET, 'b')) ?>"></label>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('git-compare') ?> Comparar</button>
        </form>
        <?php if ($fa && $fb): ?>
            <?php $linhas = ['Título' => 'titulo', 'Subtítulo' => 'subtitulo', 'Autor(es)' => 'autores', 'ISBN' => 'isbn', 'Editora' => 'editora', 'Ano' => 'ano_publicacao', 'Edição' => 'edicao',
                'Idioma' => 'idioma', 'Categoria' => 'categorias_nomes', 'CDD aprovada' => 'cdd', 'Origem' => 'origem_cadastro', 'Situação' => 'arquivado_em']; ?>
            <?php if ($difEdicao): ?><?= admin_aviso('aviso', 'Podem ser edições diferentes.', 'As obras têm ' . implode(', ', $difEdicao) . ' diferentes. Exemplares iguais de edições diferentes NÃO são cadastros duplicados: confira antes de unificar.') ?><?php endif; ?>
            <div class="adm-table-wrap">
                <table class="adm-table adm-table--compact adm-comparar__tabela">
                    <thead><tr><th scope="col">Campo</th><th scope="col"><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$fa['id']) ?>">Obra A · nº <?= (int)$fa['id'] ?></a></th><th scope="col"><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$fb['id']) ?>">Obra B · nº <?= (int)$fb['id'] ?></a></th></tr></thead>
                    <tbody>
                    <?php foreach ($linhas as $rot => $c): $va = $c === 'arquivado_em' ? ($fa[$c] ? 'Arquivada' : 'Ativa') : (string)($fa[$c] ?? ''); $vb = $c === 'arquivado_em' ? ($fb[$c] ? 'Arquivada' : 'Ativa') : (string)($fb[$c] ?? ''); $dif = $va !== $vb; ?>
                        <tr><th scope="row"><?= e($rot) ?></th><td class="<?= $dif ? 'is-diferente' : '' ?>"><?= $va !== '' ? e($va) : '<span class="adm-missing">—</span>' ?></td><td class="<?= $dif ? 'is-diferente' : '' ?>"><?= $vb !== '' ? e($vb) : '<span class="adm-missing">—</span>' ?></td></tr>
                    <?php endforeach; ?>
                        <tr><th scope="row">Exemplares</th><td><?= count($fa['exemplares']) ?>: <?= e(implode(', ', array_slice(array_column($fa['exemplares'], 'codigo_individual'), 0, 12))) ?></td><td><?= count($fb['exemplares']) ?>: <?= e(implode(', ', array_slice(array_column($fb['exemplares'], 'codigo_individual'), 0, 12))) ?></td></tr>
                        <tr><th scope="row">Planilha original</th><td><?= count($fa['registros']) ?> linha(s)<?= $fa['registros'] ? ': ' . e($fa['registros'][0]['arquivo']) . ' · linha ' . (int)$fa['registros'][0]['linha'] : '' ?></td><td><?= count($fb['registros']) ?> linha(s)<?= $fb['registros'] ? ': ' . e($fb['registros'][0]['arquivo']) . ' · linha ' . (int)$fb['registros'][0]['linha'] : '' ?></td></tr>
                        <tr><th scope="row">Histórico</th><td><?= (int)$fa['historico'] ?> registro(s) de alteração</td><td><?= (int)$fb['historico'] ?> registro(s) de alteração</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="adm-actions-inline">
                <?php if (auth_pode('livros.editar')): ?>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('editar-livro') . '?id=' . (int)$fa['id']) ?>"><?= icon('pencil') ?> Corrigir dados da obra A</a>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('editar-livro') . '?id=' . (int)$fb['id']) ?>"><?= icon('pencil') ?> Corrigir dados da obra B</a>
                <?php endif; ?>
            </div>

            <div class="adm-comparar">
                <?php if ($podeResolver && $pendDup && $pendDup['situacao'] === 'aberta'): ?>
                    <form method="post" class="adm-card adm-panel adm-form" data-enviar-uma-vez>
                        <h3 class="adm-panel__title"><?= icon('ban') ?> São obras diferentes</h3>
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="decidir"><input type="hidden" name="decisao" value="nao_duplicidade"><input type="hidden" name="chave" value="<?= e($chaveDup) ?>">
                        <input type="hidden" name="voltar" value="<?= e($base . '?aba=pendencias&tipo=duplicidade') ?>">
                        <label class="adm-field"><span class="adm-field__sublabel">Por quê? *</span><input class="adm-input" name="justificativa" required maxlength="1000" placeholder="Ex.: edições diferentes (1ª e 3ª edição)"></label>
                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('check') ?> Confirmar que são diferentes</button>
                    </form>
                <?php endif; ?>
                <?php if (auth_pode('inventario.gerenciar') && ($fb['registros'] || $fa['registros']) && !$fa['arquivado_em'] && !$fb['arquivado_em']): ?>
                    <form method="post" class="adm-card adm-panel adm-form" data-enviar-uma-vez>
                        <h3 class="adm-panel__title"><?= icon('file-spreadsheet') ?> Relacionar registros importados</h3>
                        <?= csrf_campo() ?><input type="hidden" name="acao" value="relacionar"><input type="hidden" name="voltar" value="<?= e($base . '?aba=comparar&a=' . (int)$fa['id'] . '&b=' . (int)$fb['id']) ?>">
                        <p class="adm-field__hint">Move só as linhas da planilha escolhidas para a obra correta, sem unificar os cadastros.</p>
                        <?php foreach ([[$fa, $fb], [$fb, $fa]] as [$de, $para]): foreach ($de['registros'] as $r): ?>
                            <label class="adm-check"><input type="checkbox" name="registros[]" value="<?= (int)$r['id'] ?>" data-destino="<?= (int)$para['id'] ?>"> Linha <?= (int)$r['linha'] ?> (<?= e($r['arquivo']) ?>) da obra nº <?= (int)$de['id'] ?> → obra nº <?= (int)$para['id'] ?></label>
                        <?php endforeach; endforeach; ?>
                        <label class="adm-field"><span class="adm-field__sublabel">Obra de destino</span><select class="adm-select" name="destino"><option value="<?= (int)$fa['id'] ?>">Obra A · nº <?= (int)$fa['id'] ?></option><option value="<?= (int)$fb['id'] ?>">Obra B · nº <?= (int)$fb['id'] ?></option></select></label>
                        <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required><span>As linhas escolhidas descrevem a obra de destino.</span></label>
                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('link') ?> Relacionar</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (auth_pode('obras.unificar') && !$fa['arquivado_em'] && !$fb['arquivado_em']): ?>
                <form method="post" class="adm-card adm-panel adm-form" data-confirm="Unificar os dois cadastros? Os exemplares e códigos BDP são preservados." aria-labelledby="titulo-unificar">
                    <h3 class="adm-panel__title" id="titulo-unificar"><?= icon('git-compare-arrows') ?> Unificar cadastros (só quando forem realmente a mesma obra e edição)</h3>
                    <?= csrf_campo() ?><input type="hidden" name="acao" value="unificar"><input type="hidden" name="voltar" value="<?= e($base . '?aba=comparar&a=' . (int)$fa['id'] . '&b=' . (int)$fb['id']) ?>">
                    <fieldset class="adm-escolha"><legend class="adm-field__sublabel">Qual cadastro fica?</legend>
                        <label class="adm-escolha__opcao"><input type="radio" name="mantido" value="<?= (int)$fa['id'] ?>" checked data-outro="<?= (int)$fb['id'] ?>"> <span><strong>Obra A (nº <?= (int)$fa['id'] ?>)</strong> <small>a obra B é arquivada; <?= count($fb['exemplares']) ?> exemplar(es) e <?= count($fb['registros']) ?> linha(s) passam para A</small></span></label>
                        <label class="adm-escolha__opcao"><input type="radio" name="mantido" value="<?= (int)$fb['id'] ?>" data-outro="<?= (int)$fa['id'] ?>"> <span><strong>Obra B (nº <?= (int)$fb['id'] ?>)</strong> <small>a obra A é arquivada; <?= count($fa['exemplares']) ?> exemplar(es) e <?= count($fa['registros']) ?> linha(s) passam para B</small></span></label>
                    </fieldset>
                    <input type="hidden" name="unificado" value="<?= (int)$fb['id'] ?>" data-unificado>
                    <label class="adm-field"><span class="adm-field__sublabel">Motivo *</span><input class="adm-input" name="motivo" required maxlength="500" placeholder="Ex.: mesmo livro cadastrado duas vezes (manual e planilha)"></label>
                    <?php if ($difEdicao): ?><label class="adm-check-confirm"><input type="checkbox" name="mesma_edicao" value="1"><span>Conferi com o livro em mãos: apesar de <?= e(implode(', ', $difEdicao)) ?> diferentes, é a <strong>mesma edição</strong>.</span></label><?php endif; ?>
                    <?php if (auth_pode('sistema.manutencao')): ?><label class="adm-check"><input type="checkbox" name="fazer_backup" value="1" checked> Fazer cópia de segurança antes</label><?php endif; ?>
                    <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required><span>Conferi os vínculos acima. Nenhum exemplar é excluído; o cadastro arquivado guarda o histórico e aponta para o que fica.</span></label>
                    <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Unificar</button>
                </form>
            <?php endif; ?>
        <?php elseif (admin_param($_GET, 'a') !== ''): ?>
            <?= admin_aviso('aviso', 'Obra não encontrada.', 'Confira os números informados.') ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/gestao.js?v=20261118']));
