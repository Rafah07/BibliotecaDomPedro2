<?php
/**
 * Ocorrências e Conservação: problemas físicos dos EXEMPLARES (capa danificada, páginas faltando,
 * livro molhado...). Registrar, editar observações, resolver e consultar o histórico.
 *
 * "Deixar exemplar indisponível para empréstimo?" (Sim/Não) é decidido por quem pode gerenciar
 * exemplares. A situação do exemplar (a mesma do catálogo e dos empréstimos) muda na mesma
 * transação: OcorrenciaService. Ao resolver, o exemplar só é liberado se não houver outro motivo
 * de bloqueio (outra ocorrência, empréstimo aberto ou preparo pendente).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\OcorrenciaService;
use Biblioteca\Services\OperacaoService;
use Biblioteca\Support\CodigoBdp;

$usuario = fisico_iniciar();
$base = admin_url('ocorrencias');
$podeGerenciar = auth_pode('exemplares.gerenciar');

$filtros = [
    'status' => in_array(admin_param($_GET, 'status'), ['aberta', 'resolvida', 'cancelada', 'todas'], true) ? admin_param($_GET, 'status') : 'aberta',
    'bloqueio' => in_array(admin_param($_GET, 'bloqueio'), ['0', '1'], true) ? admin_param($_GET, 'bloqueio') : '',
    'tipo' => isset(OcorrenciaService::TIPOS[admin_param($_GET, 'tipo')]) ? admin_param($_GET, 'tipo') : '',
    'q' => mb_substr(admin_param($_GET, 'q'), 0, 100),
    'exemplar' => (int)admin_param($_GET, 'exemplar') > 0 ? (string)(int)admin_param($_GET, 'exemplar') : '',
];
$formNova = ['codigo' => admin_param($_GET, 'codigo'), 'tipo' => '', 'descricao' => '', 'bloquear' => ''];
$erroNova = null;

/** Endereço de volta permitido: só páginas do painel (ex.: a conferência do inventário). */
function ocorrencia_voltar(string $voltar, string $padrao): string
{
    return $voltar !== '' && str_starts_with($voltar, admin_base()) && !str_contains($voltar, "\n") ? $voltar : $padrao;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    fisico_exigir('exemplares.gerenciar');
    $acao = admin_param($_POST, 'acao');
    $voltar = ocorrencia_voltar(admin_param($_POST, 'voltar'), admin_url_filtros($base, $filtros));
    try {
        switch ($acao) {
            case 'registrar':
                $formNova = ['codigo' => admin_param($_POST, 'codigo'), 'tipo' => admin_param($_POST, 'tipo'), 'descricao' => admin_param($_POST, 'descricao'), 'bloquear' => admin_param($_POST, 'bloquear')];
                $codigo = EmprestimoRepository::codigoExemplar($formNova['codigo']);
                if (!$codigo) {
                    throw new AcervoException(CodigoBdp::pareceCodigo($formNova['codigo']) ? CodigoBdp::mensagemInvalido($formNova['codigo']) : 'Digite o código BDP do exemplar (ex.: BDP-000123).');
                }
                $ex = exemplares_repo()->porCodigo($codigo);
                if (!$ex) {
                    throw new AcervoException('Nenhum exemplar tem o código ' . $codigo . '.');
                }
                $inv = (int)admin_param($_POST, 'inventario_id') ?: null;
                $op = operacoes_servico()->realizar('ocorrencia', 'Ocorrência registrada em ' . $codigo, $usuario['id'],
                    fn () => ocorrencias_servico()->registrar((int)$ex['id'], $formNova, $usuario['id'], $inv), admin_param($_POST, 'chave'));
                $r = $op['resultado'];
                admin_flash('sucesso', 'Ocorrência registrada em ' . $r['codigo'] . '.', $r['bloqueou'] ? 'O exemplar ficou indisponível para empréstimo (Danificado).'
                    : ($formNova['bloquear'] === '1' ? ($r['emprestado'] ? 'O exemplar está emprestado: ao ser devolvido, ficará indisponível.' : 'O exemplar está "' . \Biblioteca\Services\ExemplarService::rotuloSituacao($r['situacao']) . '" e continua assim.') : 'O exemplar continua disponível para empréstimo.'));
                admin_redirecionar($voltar);
            case 'editar':
                ocorrencias_servico()->editarDescricao((int)admin_param($_POST, 'id'), admin_param($_POST, 'descricao'), $usuario['id']);
                admin_flash('sucesso', 'Observações atualizadas.');
                admin_redirecionar($voltar);
            case 'resolver':
                $id = (int)admin_param($_POST, 'id');
                $op = operacoes_servico()->realizar('ocorrencia_resolvida', 'Ocorrência nº ' . $id . ' resolvida', $usuario['id'],
                    fn () => ocorrencias_servico()->resolver($id, admin_param($_POST, 'resolucao'), admin_param($_POST, 'liberar') === '1', $usuario['id']), admin_param($_POST, 'chave'));
                $r = $op['resultado'];
                admin_flash($r['motivo'] && admin_param($_POST, 'liberar') === '1' ? 'aviso' : 'sucesso', 'Ocorrência resolvida.',
                    $r['liberado'] ? 'O exemplar voltou a ficar disponível para empréstimo.' : ($r['motivo'] ?? 'A disponibilidade do exemplar não mudou.'));
                admin_redirecionar($voltar);
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        if ($acao === 'registrar') {
            $erroNova = $e->getMessage();
        } else {
            admin_flash('erro', 'Nada foi alterado.', $e->getMessage());
            admin_redirecionar($voltar);
        }
    } catch (PDOException $e) {
        $msg = fisico_erro_tecnico($e, 'ocorrências');
        if ($acao === 'registrar') {
            $erroNova = $msg;
        } else {
            admin_flash('erro', 'Nada foi alterado.', $msg);
            admin_redirecionar($voltar);
        }
    }
}

try {
    $lista = preparo_repo()->ocorrencias(['status' => $filtros['status'] === 'todas' ? '' : $filtros['status']] + $filtros, max(1, (int)admin_param($_GET, 'pagina', '1')), 30);
    $indicadores = preparo_repo()->indicadores();
    $exemplarFiltro = $filtros['exemplar'] !== '' ? exemplares_repo()->buscar((int)$filtros['exemplar']) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar as ocorrências', fisico_erro_tecnico($e, 'ocorrências') . ' Confira se a migração 150 foi aplicada.');
    exit;
}
if ($exemplarFiltro && $formNova['codigo'] === '') {
    $formNova['codigo'] = $exemplarFiltro['codigo_individual'];
}
$voltarGet = ocorrencia_voltar(admin_param($_GET, 'voltar'), '');
$inventarioGet = (int)admin_param($_GET, 'inventario') ?: 0;
$editar = (int)admin_param($_GET, 'editar');
$resolver = (int)admin_param($_GET, 'resolver');
$chave = OperacaoService::novaChave();
$filtrosLink = array_filter($filtros, fn ($v) => $v !== '');

admin_page_start('ocorrencias', 'Ocorrências e Conservação', 'Exemplares', 'Problemas físicos dos exemplares', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], ['Ocorrências e Conservação']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Ocorrências e Conservação</h1>
        <p>Registre os problemas físicos de cada exemplar (a ocorrência é do livro em mãos, não da obra). Decida se ele continua emprestável e marque como resolvida quando o problema for tratado.</p>
    </div>
    <?php if ($voltarGet !== ''): ?><div class="adm-page-head__actions"><a class="adm-button adm-button--ghost" href="<?= e($voltarGet) ?>"><?= icon('arrow-left') ?> Voltar para a conferência</a></div><?php endif; ?>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats" aria-label="Resumo">
    <?= fisico_stat('Exemplares com ocorrências', $indicadores['com_ocorrencias'], 'Com ocorrência aberta', 'triangle-alert', 'red', admin_url_filtros($base, ['status' => 'aberta'])) ?>
    <?= fisico_stat('Bloqueando empréstimo', (int)db_query("SELECT COUNT(*) FROM exemplar_ocorrencias WHERE status = 'aberta' AND bloqueia_emprestimo = 1")->fetchColumn(), 'Ocorrências abertas que deixam indisponível', 'ban', 'amber', admin_url_filtros($base, ['status' => 'aberta', 'bloqueio' => '1'])) ?>
    <?= fisico_stat('Indisponíveis para empréstimo', $indicadores['indisponiveis'], 'Por qualquer motivo', 'circle-x', 'muted') ?>
</section>

<div class="adm-org-colunas">
    <?php if ($podeGerenciar): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-nova" id="nova">
        <h2 id="titulo-nova" class="adm-panel__title"><?= icon('plus') ?> Registrar ocorrência</h2>
        <?php if ($erroNova): ?><?= admin_aviso('erro', 'A ocorrência não foi registrada.', $erroNova) ?><?php endif; ?>
        <form method="post" action="<?= e($base) ?>" data-enviar-uma-vez>
            <?= csrf_campo() ?><input type="hidden" name="acao" value="registrar"><input type="hidden" name="chave" value="<?= e($chave) ?>">
            <input type="hidden" name="voltar" value="<?= e($voltarGet) ?>"><input type="hidden" name="inventario_id" value="<?= $inventarioGet ?: '' ?>">
            <label class="adm-field"><span class="adm-field__sublabel">Código BDP do exemplar *</span><input class="adm-input adm-input--code" name="codigo" required maxlength="30" value="<?= e($formNova['codigo']) ?>" placeholder="BDP-000123" autocomplete="off" spellcheck="false"></label>
            <label class="adm-field"><span class="adm-field__sublabel">Problema *</span><?= fisico_select_ocorrencia('tipo', $formNova['tipo'], 'oc-tipo') ?></label>
            <label class="adm-field"><span class="adm-field__sublabel">Observações</span><textarea class="adm-input" name="descricao" rows="2" maxlength="1000" placeholder="Ex.: faltam as páginas 45 a 52"><?= e($formNova['descricao']) ?></textarea></label>
            <?= fisico_campo_bloqueio('oc', $formNova['bloquear']) ?>
            <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Registrar ocorrência</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="adm-card adm-panel" aria-labelledby="titulo-lista">
        <div class="adm-panel__header"><div><h2 id="titulo-lista"><?= $exemplarFiltro ? 'Ocorrências de ' . e($exemplarFiltro['codigo_individual']) : 'Ocorrências' ?></h2><p aria-live="polite"><?= fisico_numero($lista['total']) ?> ocorrência(s)</p></div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar</span><input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Código BDP ou título..."></label>
            <div class="adm-filters">
                <label class="adm-filter"><span>Situação</span><select class="adm-select" name="status">
                    <?php foreach (['aberta' => 'Abertas', 'resolvida' => 'Resolvidas', 'cancelada' => 'Canceladas', 'todas' => 'Todas'] as $v => $r): ?><option value="<?= $v ?>"<?= $filtros['status'] === $v ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></label>
                <label class="adm-filter"><span>Empréstimo</span><select class="adm-select" name="bloqueio"><option value="">Todos</option><option value="1"<?= $filtros['bloqueio'] === '1' ? ' selected' : '' ?>>Deixa indisponível</option><option value="0"<?= $filtros['bloqueio'] === '0' ? ' selected' : '' ?>>Empréstimo permitido</option></select></label>
                <label class="adm-filter"><span>Problema</span><select class="adm-select" name="tipo"><option value="">Todos</option>
                    <?php foreach (OcorrenciaService::TIPOS as $v => $r): ?><option value="<?= $v ?>"<?= $filtros['tipo'] === $v ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            </div>
            <?php if ($filtros['exemplar'] !== ''): ?><input type="hidden" name="exemplar" value="<?= e($filtros['exemplar']) ?>"><?php endif; ?>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> Filtrar</button>
        </form>
        <ul class="adm-ocorrencias">
            <?php foreach ($lista['itens'] as $o): ?>
                <li class="adm-ocorrencia is-<?= e($o['status']) ?>">
                    <div class="adm-ocorrencia__topo">
                        <strong><?= e(OcorrenciaService::TIPOS[$o['tipo']] ?? $o['tipo']) ?></strong>
                        <span class="adm-status adm-tone--<?= ['aberta' => 'red', 'resolvida' => 'green', 'cancelada' => 'muted'][$o['status']] ?>"><?= e(OcorrenciaService::STATUS[$o['status']]) ?></span>
                        <?= (int)$o['bloqueia_emprestimo'] ? '<span class="adm-status adm-tone--amber">' . icon('ban') . ' Indisponível para empréstimo</span>' : '<span class="adm-status adm-tone--blue">Empréstimo permitido</span>' ?>
                    </div>
                    <p><a class="adm-link adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$o['exemplar_id']) ?>"><?= e($o['codigo_individual']) ?></a> · <?= e($o['titulo'] ?? 'Sem título') ?> · situação atual: <?= acervo_badge_situacao($o['situacao']) ?></p>
                    <?php if ($o['descricao']): ?><p class="adm-ocorrencia__desc"><?= e($o['descricao']) ?></p><?php endif; ?>
                    <p class="adm-table__muted">Nº <?= (int)$o['id'] ?> · registrada em <?= e(admin_data_hora($o['criado_em'])) ?> por <?= e((string)$o['registrada_por_nome']) ?>
                        <?= $o['resolvida_em'] ? ' · ' . ($o['status'] === 'cancelada' ? 'cancelada' : 'resolvida') . ' em ' . e(admin_data_hora($o['resolvida_em'])) . ' por ' . e((string)$o['resolvida_por_nome']) : '' ?></p>
                    <?php if ($o['resolucao']): ?><p class="adm-ocorrencia__desc"><strong>O que foi feito:</strong> <?= e($o['resolucao']) ?></p><?php endif; ?>
                    <?php if ($podeGerenciar && $o['status'] === 'aberta'): ?>
                        <div class="adm-ocorrencia__acoes">
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['editar' => (int)$o['id']])) ?>#oc-<?= (int)$o['id'] ?>"><?= icon('pencil') ?> Editar observações</a>
                            <a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url_filtros($base, $filtrosLink, ['resolver' => (int)$o['id']])) ?>#oc-<?= (int)$o['id'] ?>"><?= icon('check') ?> Marcar como resolvida</a>
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('historico-exemplar') . '?id=' . (int)$o['exemplar_id']) ?>"><?= icon('history') ?> Histórico</a>
                        </div>
                        <?php if ($editar === (int)$o['id']): ?>
                            <form method="post" action="<?= e($base) ?>" class="adm-ocorrencia__form" id="oc-<?= (int)$o['id'] ?>" data-enviar-uma-vez>
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="editar"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <label class="adm-field"><span class="adm-field__sublabel">Observações</span><textarea class="adm-input" name="descricao" rows="2" maxlength="1000"><?= e((string)$o['descricao']) ?></textarea></label>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Salvar</button>
                            </form>
                        <?php elseif ($resolver === (int)$o['id']): ?>
                            <form method="post" action="<?= e($base) ?>" class="adm-ocorrencia__form" id="oc-<?= (int)$o['id'] ?>" data-enviar-uma-vez>
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="resolver"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="chave" value="<?= e($chave) ?>">
                                <label class="adm-field"><span class="adm-field__sublabel">O que foi feito? *</span><input class="adm-input" name="resolucao" required maxlength="1000" placeholder="Ex.: capa colada e reforçada"></label>
                                <fieldset class="adm-field adm-escolha"><legend class="adm-field__sublabel">Disponibilidade do exemplar</legend>
                                    <label class="adm-escolha__opcao"><input type="radio" name="liberar" value="1"<?= (int)$o['bloqueia_emprestimo'] ? ' checked' : '' ?>> <span><strong>Liberar para empréstimo</strong> <small>só acontece se não houver outro motivo de bloqueio</small></span></label>
                                    <label class="adm-escolha__opcao"><input type="radio" name="liberar" value="0"<?= (int)$o['bloqueia_emprestimo'] ? '' : ' checked' ?>> <span><strong>Manter a situação atual</strong></span></label>
                                </fieldset>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('check') ?> Confirmar resolução</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if (!$lista['itens']): ?><li><div class="adm-empty"><?= icon('circle-check') ?><strong>Nenhuma ocorrência com estes filtros.</strong></div></li><?php endif; ?>
        </ul>
        <?= admin_paginacao($lista['total'], $lista['pagina'], 30, $base, $filtrosLink, [30]) ?>
    </section>
</div>

<?php
admin_page_end(FISICO_JS);
