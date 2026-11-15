<?php
/**
 * Ações recentes e "Desfazer": cada ação da equipe com exemplares (mover, movimentação em lote,
 * carimbo, ocorrência, conferência...) e as correções de dados das obras, com quem e quando.
 *
 * Desfazer só quando é seguro (OperacaoService::analisar): o exemplar continua como a ação deixou,
 * sem empréstimo aberto e sem mudança posterior. A reversão é uma NOVA operação; o histórico
 * original fica. Quando não é seguro, a tela explica o motivo e orienta a correção manual.
 * Quem desfaz: a própria pessoa, ou quem gerencia o inventário (ações de outras pessoas).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\OperacaoService;

$usuario = fisico_iniciar('exemplares.gerenciar');
$base = admin_url('acoes-recentes');
$podeTodos = auth_pode('inventario.gerenciar');
$escopo = $podeTodos && admin_param($_GET, 'escopo') === 'todas' ? 'todas' : 'minhas';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    try {
        if ($acao === 'desfazer') {
            $opId = (int)admin_param($_POST, 'operacao_id');
            $op = operacoes_servico()->operacao($opId);
            if (!$op) {
                throw new AcervoException('Operação não encontrada.');
            }
            if ((int)$op['administrador_id'] !== (int)$usuario['id'] && !$podeTodos) {
                throw new AcervoException('Você só pode desfazer as suas próprias ações. Peça a quem gerencia o inventário.');
            }
            if (admin_param($_POST, 'confirmo') !== '1') {
                throw new AcervoException('Marque a confirmação para desfazer.');
            }
            $nova = operacoes_servico()->desfazer($opId, $usuario['id'], admin_param($_POST, 'motivo'), admin_param($_POST, 'chave'));
            admin_flash('sucesso', 'Operação nº ' . $opId . ' desfeita.', 'A reversão foi registrada como a operação nº ' . $nova . '. O histórico original continua guardado.');
            admin_redirecionar($base . '?operacao=' . $nova);
        }
        if ($acao === 'desfazer_correcao') {
            fisico_exigir('livros.editar');
            $campos = operacoes_servico()->desfazerCorrecaoLivro((int)admin_param($_POST, 'verificacao_id'), $usuario['id'], obra_verificacao_servico());
            admin_flash('sucesso', 'Correção desfeita.', 'Voltaram ao valor anterior: ' . implode(', ', $campos) . '. Confira a obra e, se for o caso, verifique os dados de novo.');
            admin_redirecionar($base);
        }
        throw new AcervoException('Ação desconhecida.');
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi desfeito.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi desfeito.', fisico_erro_tecnico($e, 'desfazer ação'));
    }
    admin_redirecionar($base . ((int)admin_param($_POST, 'operacao_id') ? '?operacao=' . (int)admin_param($_POST, 'operacao_id') : ''));
}

$detalheId = (int)admin_param($_GET, 'operacao');
try {
    $lista = operacoes_servico()->recentes($escopo === 'todas' ? null : $usuario['id'], 80);
    $detalhe = $detalheId ? operacoes_servico()->operacao($detalheId) : null;
    if ($detalhe && (int)$detalhe['administrador_id'] !== (int)$usuario['id'] && !$podeTodos) {
        $detalhe = null;
    }
    $itens = $detalhe ? operacoes_servico()->itens($detalheId) : [];
    $analise = $detalhe ? operacoes_servico()->analisar($detalheId) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar as ações recentes', fisico_erro_tecnico($e, 'ações recentes') . ' Confira se a migração 150 foi aplicada.');
    exit;
}

admin_page_start('acoes-recentes', 'Ações recentes', 'Exemplares', 'Consultar e desfazer ações com segurança', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], ['Ações recentes']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Ações recentes</h1>
        <p>Errou ao mover, carimbar, registrar uma ocorrência ou corrigir uma obra? Abra a ação e use “Desfazer”. Só é desfeito o que ainda está como a ação deixou; a reversão vira uma nova ação e nada é apagado do histórico.</p>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if ($detalhe): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-detalhe">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-detalhe">Operação nº <?= (int)$detalhe['id'] ?> — <?= e(OperacaoService::TIPOS[$detalhe['tipo']] ?? $detalhe['tipo']) ?></h2>
                <p><?= e($detalhe['descricao']) ?> · <?= e(admin_data_hora($detalhe['criado_em'])) ?> · <?= e((string)$detalhe['administrador_nome']) ?> · <?= fisico_numero((int)$detalhe['total']) ?> exemplar(es)</p>
            </div>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . ($escopo === 'todas' ? '?escopo=todas' : '')) ?>"><?= icon('arrow-left') ?> Todas as ações</a>
        </div>
        <?php if ($detalhe['desfeita_em']): ?>
            <?= admin_aviso('aviso', 'Esta operação já foi desfeita.', 'Em ' . admin_data_hora($detalhe['desfeita_em']) . ' por ' . $detalhe['desfeita_por_nome'] . ' (operação nº ' . (int)$detalhe['desfeita_por_operacao_id'] . ').') ?>
        <?php elseif ($analise['pode']): ?>
            <form method="post" action="<?= e($base) ?>" class="adm-desfazer" data-enviar-uma-vez>
                <?= csrf_campo() ?><input type="hidden" name="acao" value="desfazer"><input type="hidden" name="operacao_id" value="<?= (int)$detalhe['id'] ?>"><input type="hidden" name="chave" value="<?= e(OperacaoService::novaChave()) ?>">
                <p><strong>É seguro desfazer.</strong> Serão revertidas <?= count($analise['passos']) ?> mudança(s), na ordem inversa.</p>
                <label class="adm-field"><span class="adm-field__sublabel">Motivo <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="motivo" maxlength="200" placeholder="Ex.: estante errada"></label>
                <label class="adm-check-confirm"><input type="checkbox" name="confirmo" value="1" required> <span>Quero desfazer a operação nº <?= (int)$detalhe['id'] ?>.</span></label>
                <button class="adm-button adm-button--primary" type="submit"><?= icon('undo-2') ?> Desfazer esta operação</button>
            </form>
        <?php else: ?>
            <?= admin_aviso('aviso', 'Não é seguro desfazer automaticamente.', implode(' ', array_slice($analise['motivos'], 0, 8)) . (count($analise['motivos']) > 8 ? ' (e mais ' . (count($analise['motivos']) - 8) . ')' : '')) ?>
            <?php if ($analise['orientacao'] !== ''): ?><p class="adm-orientacao"><?= icon('lightbulb') ?> <strong>Como corrigir:</strong> <?= e($analise['orientacao']) ?></p><?php endif; ?>
        <?php endif; ?>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards adm-table--compacta">
                <thead><tr><th scope="col">Exemplar</th><th scope="col">O que mudou</th><th scope="col">Quando</th></tr></thead>
                <tbody>
                <?php foreach ($itens as $m): ?>
                    <tr><td data-label="Exemplar"><a class="adm-link adm-code" href="<?= e(admin_url('historico-exemplar') . '?id=' . (int)$m['exemplar_id']) ?>"><?= e($m['codigo_individual']) ?></a><small class="adm-block-muted"><?= e(mb_strimwidth($m['titulo'] ?? 'Sem título', 0, 60, '…')) ?></small></td>
                        <td data-label="O que mudou"><?= e($m['descricao']) ?></td><td data-label="Quando"><?= e(admin_data_hora($m['criado_em'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-lista">
    <div class="adm-panel__header">
        <div><h2 id="titulo-lista">Últimos <?= OperacaoService::LIMITE_DIAS ?> dias</h2><p><?= $escopo === 'todas' ? 'Ações de toda a equipe' : 'Somente as suas ações' ?></p></div>
        <?php if ($podeTodos): ?>
            <nav class="adm-tabs adm-tabs--links" aria-label="De quem">
                <a class="adm-tab<?= $escopo === 'minhas' ? ' is-active' : '' ?>" href="<?= e($base) ?>">Minhas</a>
                <a class="adm-tab<?= $escopo === 'todas' ? ' is-active' : '' ?>" href="<?= e($base . '?escopo=todas') ?>">Toda a equipe</a>
            </nav>
        <?php endif; ?>
    </div>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards adm-table--compacta">
            <thead><tr><th scope="col">Quando</th><th scope="col">Ação</th><th scope="col">Responsável</th><th scope="col">Exemplares</th><th scope="col">Situação</th><th scope="col"><span class="adm-visually-hidden">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $a): ?>
                <tr>
                    <td data-label="Quando"><?= e(admin_data_hora($a['criado_em'])) ?></td>
                    <td class="adm-td-title" data-label="Ação"><strong><?= e($a['origem'] === 'correcao' ? 'Correção de dados da obra' : (OperacaoService::TIPOS[$a['tipo']] ?? $a['tipo'])) ?></strong><small class="adm-block-muted"><?= e($a['descricao']) ?></small></td>
                    <td data-label="Responsável"><?= e((string)$a['administrador_nome']) ?></td>
                    <td data-label="Exemplares"><?= $a['origem'] === 'correcao' ? '—' : fisico_numero((int)$a['total']) ?></td>
                    <td data-label="Situação"><?= $a['desfeita_em'] ? '<span class="adm-status adm-tone--muted">Desfeita</span>' : ($a['tipo'] === 'desfazer' ? '<span class="adm-status adm-tone--blue">Reversão</span>' : '<span class="adm-status adm-tone--green">Registrada</span>') ?></td>
                    <td class="adm-td-actions">
                        <?php if ($a['origem'] === 'operacao'): ?>
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?operacao=' . (int)$a['id'] . ($escopo === 'todas' ? '&escopo=todas' : '')) ?>#titulo-detalhe"><?= icon('eye') ?> Ver<?= $a['desfeita_em'] || $a['tipo'] === 'desfazer' ? '' : ' / desfazer' ?></a>
                        <?php elseif (!$a['desfeita_em'] && auth_pode('livros.editar') && ((int)$a['administrador_id'] === (int)$usuario['id'] || $podeTodos)): ?>
                            <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Desfazer a correção dos dados desta obra? Os campos voltam ao valor anterior, se ninguém os mudou depois.">
                                <?= csrf_campo() ?><input type="hidden" name="acao" value="desfazer_correcao"><input type="hidden" name="verificacao_id" value="<?= (int)$a['id'] ?>">
                                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('undo-2') ?> Desfazer correção</button>
                            </form>
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$a['livro_id']) ?>"><?= icon('book-open') ?> Obra</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?><tr><td colspan="6"><div class="adm-empty"><?= icon('history') ?><strong>Nenhuma ação recente.</strong></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <details class="adm-config-detalhes">
        <summary>O que não é desfeito automaticamente</summary>
        <ul>
            <li><strong>Exemplar registrado na obra errada:</strong> o código BDP não é reaproveitado. Se ainda não foi carimbado, registre a baixa com o motivo “registrado na obra errada” e registre o exemplar na obra certa.</li>
            <li><strong>Resultado do inventário errado:</strong> na conferência, abra a obra e use “Voltar para pendente”.</li>
            <li><strong>Mudança seguida de outra (ex.: movido de novo, emprestado):</strong> corrija pela ação mais recente ou manualmente no exemplar.</li>
            <li><strong>Autores e categoria da obra:</strong> corrija em “Editar livro”; o histórico mostra o valor anterior.</li>
        </ul>
    </details>
</section>

<?php
admin_page_end(FISICO_JS);
