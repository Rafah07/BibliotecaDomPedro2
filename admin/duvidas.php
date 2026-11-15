<?php
/**
 * Dúvidas da conferência (migração 160): dúvidas sobre os dados de uma obra ou sobre o exemplar
 * físico (ISBN desconhecido, edição não identificada, informações divergentes, possível associação
 * incorreta, conservação, outras). Registrar uma dúvida não impede ninguém de continuar trabalhando.
 *
 * Dúvida de exemplar aparece no relatório do lote dele. Marcada como "compromete a identificação
 * física", precisa ser resolvida antes de confirmar a carimbagem e de encerrar o lote.
 * Registrar: inventario.conferir · Resolver: exemplares.gerenciar. Dúvidas não são apagadas.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\DuvidaService;

$usuario = fisico_iniciar();
$base = admin_url('duvidas');
$podeRegistrar = auth_pode('inventario.conferir');
$podeResolver = auth_pode('exemplares.gerenciar');

$filtros = [
    'status' => in_array(admin_param($_GET, 'status'), ['aberta', 'resolvida', 'todas'], true) ? admin_param($_GET, 'status') : 'aberta',
    'lote' => (int)admin_param($_GET, 'lote') > 0 ? (string)(int)admin_param($_GET, 'lote') : '',
    'livro' => (int)admin_param($_GET, 'livro') > 0 ? (string)(int)admin_param($_GET, 'livro') : '',
    'tipo' => isset(DuvidaService::TIPOS[admin_param($_GET, 'tipo')]) ? admin_param($_GET, 'tipo') : '',
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
];
$form = ['codigo' => admin_param($_GET, 'codigo'), 'livro_id' => $filtros['livro'], 'tipo' => '', 'descricao' => '', 'compromete' => ''];
$erro = null;

/** Volta só para páginas do painel (ex.: a Conferência). */
function duvidas_voltar(string $voltar, string $padrao): string
{
    return $voltar !== '' && str_starts_with($voltar, admin_base()) && !preg_match('/[\r\n]/', $voltar) ? $voltar : $padrao;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    $voltar = duvidas_voltar(admin_param($_POST, 'voltar'), admin_url_filtros($base, $filtros));
    try {
        if ($acao === 'registrar') {
            fisico_exigir('inventario.conferir');
            $form = ['codigo' => admin_param($_POST, 'codigo'), 'livro_id' => admin_param($_POST, 'livro_id'), 'tipo' => admin_param($_POST, 'tipo'),
                'descricao' => admin_param($_POST, 'descricao'), 'compromete' => admin_param($_POST, 'compromete')];
            $novo = duvidas_servico()->registrar(['codigo' => $form['codigo'], 'livro_id' => $form['livro_id'], 'tipo' => $form['tipo'], 'descricao' => $form['descricao'], 'compromete' => $form['compromete']], $usuario['id']);
            admin_flash('sucesso', 'Dúvida nº ' . $novo . ' registrada.', 'Ela fica nas pendências até alguém registrar a resolução. Pode continuar trabalhando normalmente.'
                . ($form['compromete'] === '1' ? ' Como compromete a identificação física, o lote só confirma a carimbagem e só é encerrado depois de resolvida.' : ''));
            admin_redirecionar($voltar);
        } elseif ($acao === 'resolver') {
            fisico_exigir('exemplares.gerenciar');
            duvidas_servico()->resolver((int)admin_param($_POST, 'id'), admin_param($_POST, 'resolucao'), $usuario['id']);
            admin_flash('sucesso', 'Dúvida resolvida.', 'A resolução, quem resolveu e quando ficaram registrados.');
            admin_redirecionar($voltar);
        }
        throw new AcervoException('Ação desconhecida.');
    } catch (AcervoException $e) {
        if ($acao === 'registrar') {
            $erro = $e->getMessage();
        } else {
            admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
            admin_redirecionar($voltar);
        }
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado.', fisico_erro_tecnico($e, 'dúvidas'));
        admin_redirecionar($voltar);
    }
}

try {
    $lista = lotes_repo()->listaDuvidas(['status' => $filtros['status'] === 'todas' ? '' : $filtros['status']] + $filtros, max(1, (int)admin_param($_GET, 'pagina', '1')), 30);
    $livroFiltro = $filtros['livro'] !== '' ? db_query('SELECT id, titulo FROM livros WHERE id = ?', [(int)$filtros['livro']])->fetch() : null;
    $loteFiltro = $filtros['lote'] !== '' ? lotes_repo()->lote((int)$filtros['lote']) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar as dúvidas', fisico_erro_tecnico($e, 'dúvidas') . ' Confira se a migração 160 foi aplicada.');
    exit;
}
$voltarGet = duvidas_voltar(admin_param($_GET, 'voltar'), '');
$resolver = (int)admin_param($_GET, 'resolver');
$filtrosLink = array_filter($filtros, fn ($v) => $v !== '');

admin_page_start('duvidas', 'Dúvidas da conferência', 'Inventário', 'Dúvidas sobre obras e exemplares', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], ['Dúvidas da conferência']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Dúvidas da conferência<?= $loteFiltro ? ' · ' . e(LoteRepository::nome($loteFiltro)) : '' ?></h1>
        <p>Registre o que não deu para resolver na hora — ISBN desconhecido, edição não identificada, informações divergentes... — e continue trabalhando. Depois, registre a resolução.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($voltarGet !== ''): ?><a class="adm-button adm-button--ghost" href="<?= e($voltarGet) ?>"><?= icon('arrow-left') ?> Voltar para a conferência</a><?php endif; ?>
        <?php if ($loteFiltro): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('lote') . '?id=' . (int)$loteFiltro['id']) ?>"><?= icon('layers') ?> Relatório do lote</a><?php endif; ?>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('lotes')) ?>"><?= icon('layers') ?> Histórico de Lotes</a>
    </div>
</section>

<?= admin_flash_html() ?>

<div class="adm-org-colunas">
    <?php if ($podeRegistrar): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-nova" id="nova">
        <h2 id="titulo-nova" class="adm-panel__title"><?= icon('plus') ?> Registrar dúvida</h2>
        <?php if ($erro): ?><?= admin_aviso('erro', 'A dúvida não foi registrada.', $erro) ?><?php endif; ?>
        <form class="adm-form" method="post" action="<?= e($base) ?>" data-enviar-uma-vez>
            <?= csrf_campo() ?><input type="hidden" name="acao" value="registrar"><input type="hidden" name="voltar" value="<?= e($voltarGet) ?>">
            <?php if ($livroFiltro && $form['codigo'] === ''): ?>
                <input type="hidden" name="livro_id" value="<?= (int)$livroFiltro['id'] ?>">
                <p class="adm-field__hint">Obra: <strong><?= e($livroFiltro['titulo'] ?? 'Sem título') ?></strong>. Para uma dúvida sobre um exemplar físico, informe também o código BDP.</p>
            <?php endif; ?>
            <label class="adm-field"><span class="adm-field__sublabel">Código BDP do exemplar <?= $livroFiltro ? '<span class="adm-optional">(opcional)</span>' : '*' ?></span>
                <input class="adm-input adm-input--code" name="codigo" maxlength="30" value="<?= e($form['codigo']) ?>" placeholder="BDP-000123" autocomplete="off" spellcheck="false"<?= $livroFiltro ? '' : ' required' ?>></label>
            <label class="adm-field"><span class="adm-field__sublabel">Tipo de dúvida *</span><select class="adm-select" name="tipo" required><option value="">Escolha</option>
                <?php foreach (DuvidaService::TIPOS as $v => $r): ?><option value="<?= e($v) ?>"<?= $form['tipo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            <label class="adm-field"><span class="adm-field__sublabel">Descrição</span><textarea class="adm-input" name="descricao" rows="2" maxlength="1000" placeholder="Ex.: a capa diz 2ª edição, a folha de rosto diz 3ª"><?= e($form['descricao']) ?></textarea></label>
            <label class="adm-check"><input type="checkbox" name="compromete" value="1"<?= $form['compromete'] === '1' ? ' checked' : '' ?>> <span><strong>Compromete a identificação física do exemplar</strong> <small>ex.: pode ser outro livro. O lote só confirma a carimbagem e só é encerrado depois de resolvida.</small></span></label>
            <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Registrar dúvida</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="adm-card adm-panel" aria-labelledby="titulo-lista">
        <div class="adm-panel__header"><div><h2 id="titulo-lista">Dúvidas</h2><p aria-live="polite"><?= fisico_numero($lista['total']) ?> dúvida(s)</p></div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span><input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Código BDP ou título..."></label>
            <div class="adm-filters">
                <label class="adm-filter"><span>Situação</span><select class="adm-select" name="status">
                    <?php foreach (['aberta' => 'Abertas', 'resolvida' => 'Resolvidas', 'todas' => 'Todas'] as $v => $r): ?><option value="<?= $v ?>"<?= $filtros['status'] === $v ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Tipo</span><select class="adm-select" name="tipo"><option value="">Todos</option>
                    <?php foreach (DuvidaService::TIPOS as $v => $r): ?><option value="<?= e($v) ?>"<?= $filtros['tipo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            </div>
            <?php foreach (['lote', 'livro'] as $k): ?><?php if ($filtros[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($filtros[$k]) ?>"><?php endif; ?><?php endforeach; ?>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
        </form>
        <ul class="adm-ocorrencias">
            <?php foreach ($lista['itens'] as $d): ?>
                <li class="adm-ocorrencia is-<?= $d['status'] === 'aberta' ? 'aberta' : 'resolvida' ?>" id="duvida-<?= (int)$d['id'] ?>">
                    <div class="adm-ocorrencia__topo"><strong><?= e(DuvidaService::TIPOS[$d['tipo']] ?? $d['tipo']) ?></strong>
                        <span class="adm-status adm-tone--<?= $d['status'] === 'aberta' ? 'amber' : 'green' ?>"><?= $d['status'] === 'aberta' ? 'Aberta' : 'Resolvida' ?></span>
                        <?= (int)$d['compromete_identificacao'] ? '<span class="adm-status adm-tone--red">' . icon('ban') . ' Compromete a identificação física</span>' : '' ?>
                        <?= $d['lote_numero'] !== null ? '<a class="adm-status adm-tone--blue" href="' . e(admin_url('lote') . '?id=' . (int)$d['lote_id']) . '">' . e(LoteRepository::nome($d['lote_numero'])) . '</a>' : '' ?></div>
                    <p><?= $d['codigo_individual'] ? '<a class="adm-link adm-code" href="' . e(admin_url('detalhes-exemplar') . '?id=' . (int)$d['exemplar_id']) . '">' . e($d['codigo_individual']) . '</a> · ' : 'Obra · ' ?>
                        <a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$d['livro_id']) ?>"><?= e($d['titulo'] ?? 'Sem título') ?></a></p>
                    <?php if ($d['descricao']): ?><p class="adm-ocorrencia__desc"><?= e($d['descricao']) ?></p><?php endif; ?>
                    <p class="adm-table__muted">Nº <?= (int)$d['id'] ?> · registrada em <?= e(admin_data_hora($d['criado_em'])) ?> por <?= e((string)$d['registrada_por_nome']) ?>
                        <?= $d['resolvida_em'] ? ' · resolvida em ' . e(admin_data_hora($d['resolvida_em'])) . ' por ' . e((string)$d['resolvida_por_nome']) : '' ?></p>
                    <?php if ($d['resolucao']): ?><p class="adm-ocorrencia__desc"><strong>Resolução:</strong> <?= e($d['resolucao']) ?></p><?php endif; ?>
                    <?php if ($podeResolver && $d['status'] === 'aberta'): ?>
                        <?php if ($resolver === (int)$d['id']): ?>
                            <form method="post" action="<?= e($base) ?>" class="adm-ocorrencia__form" data-enviar-uma-vez>
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="resolver"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <input type="hidden" name="voltar" value="<?= e(admin_url_filtros($base, $filtrosLink)) ?>">
                                <label class="adm-field"><span class="adm-field__sublabel">Como foi resolvida? *</span><input class="adm-input" name="resolucao" required maxlength="1000" placeholder="Ex.: ISBN encontrado na ficha catalográfica e corrigido na obra"></label>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Registrar resolução</button>
                            </form>
                        <?php else: ?>
                            <div class="adm-ocorrencia__acoes"><a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['resolver' => (int)$d['id']])) ?>#duvida-<?= (int)$d['id'] ?>"><?= icon('check') ?> Registrar resolução</a></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if (!$lista['itens']): ?><li><div class="adm-empty"><?= icon('circle-check') ?><strong>Nenhuma dúvida com estes filtros.</strong></div></li><?php endif; ?>
        </ul>
        <?= admin_paginacao($lista['total'], $lista['pagina'], 30, $base, $filtrosLink, [30]) ?>
    </section>
</div>

<?php
admin_page_end(FISICO_JS);
