<?php
/**
 * Área de trabalho do Grupo 1 (levantamento): um formulário por vez, na largura toda, em sequência.
 * Cabeçalho com o progresso do lote e o indicador de salvamento; barra inferior com
 * Anterior · Próximo · Confirmar e avançar · Pausar · Encaminhar. Os registros podem ser consultados
 * na lista recolhível sem sair do formulário atual.
 * Variáveis: $r, $sessao, $lote, $c, $base, $etapaPausada.
 */
use Biblioteca\Reorganizacao\Levantamento;

$lev = $r->levantamento();
$registros = $lev->registrosDoLote((int)$lote['id'], 'todos');
$loteUrl = $base . '?lote=' . $lote['id'];
$regId = (int)($_GET['reg'] ?? 0);
$reg = null;
$erroReg = null;
if ($regId) {
    try {
        $reg = $lev->abrir($regId, $sessao);
        if ((int)$reg['lote_id'] !== (int)$lote['id']) {
            $reg = null;
        }
    } catch (Biblioteca\Services\AcervoException $e) {
        $erroReg = $e->getMessage();
    }
}
$categorias = db_query('SELECT id, nome FROM categorias WHERE arquivado_em IS NULL ORDER BY nome')->fetchAll();
$editaveis = array_values(array_filter($registros, fn ($g) => in_array($g['status'], ['rascunho', 'correcao'], true)));
$capacidade = $c['registrados'] < $c['prevista'];

// Vizinhos na sequência (por número) e o próximo destino de "Confirmar e avançar".
$anterior = $proximo = null;
if ($reg) {
    $nums = array_column($registros, 'id');
    $i = array_search((int)$reg['id'], array_map('intval', $nums), true);
    $anterior = $i !== false && $i > 0 ? $registros[$i - 1] : null;
    $proximo = $i !== false && $i < count($registros) - 1 ? $registros[$i + 1] : null;
    $seguinte = null;
    foreach ($editaveis as $g) {
        if ((int)$g['numero'] > (int)$reg['numero'] && !($g['editando_ate'] && strtotime($g['editando_ate']) > time() && $g['editando_nome'] && $g['editando_nome'] !== $sessao['nome'])) {
            $seguinte = $g;
            break;
        }
    }
    $destinoAvancar = $seguinte ? $loteUrl . '&reg=' . $seguinte['id'] : ($capacidade ? 'novo' : $loteUrl);
    $exemplaresDeste = count($reg['exemplares']);
    $maxQuantidade = max(1, min((int)reorg_config('max_exemplares_registro'), $c['prevista'] - ($c['registrados'] - $exemplaresDeste)));
}
?>
<section class="adm-card reorg-g1-topo" data-reorg-g1-topo>
    <div class="reorg-g1-topo__lote">
        <h1><?= e($lote['nome']) ?></h1>
        <dl class="reorg-numeros reorg-numeros--linha">
            <div><dt>Prevista</dt><dd><?= $c['prevista'] ?></dd></div>
            <div><dt>Registrada</dt><dd><?= $c['registrados'] ?></dd></div>
            <div><dt>Restante</dt><dd><?= $c['aguardando_levantamento'] ?></dd></div>
        </dl>
        <?= reorg_barra($c['registrados'], $c['prevista'], $c['registrados'] . ' de ' . $c['prevista'] . ' exemplares registrados') ?>
    </div>
    <div class="reorg-g1-topo__salvo">
        <span class="reorg-indicador" data-reorg-indicador-form role="status" aria-live="polite" data-estado="salvo"><?= $reg && !$reg['somente_leitura'] ? 'Todas as alterações salvas' : ($reg ? 'Somente consulta' : 'Nenhum registro aberto') ?></span>
        <small data-reorg-ultimo><?= $reg ? 'Último salvamento confirmado: ' . e(date('H:i:s', strtotime((string)$reg['atualizado_em']))) : '' ?></small>
    </div>
</section>

<details class="adm-card reorg-g1-lista" data-reorg-lista-registros>
    <summary><?= icon('list') ?> Registros deste lote (<?= count($registros) ?>) <small>— consultar sem sair do formulário</small></summary>
    <ol class="reorg-registros" data-reorg-lista>
        <?php if (!$registros): ?><li class="adm-table__muted">Nenhum registro ainda.</li><?php endif; ?>
        <?php foreach ($registros as $g): [$st, $tom] = Levantamento::STATUS[$g['status']]; $ativo = $reg && (int)$reg['id'] === (int)$g['id']; ?>
            <li class="<?= $ativo ? 'is-ativo' : '' ?>"><a href="<?= e($loteUrl . '&reg=' . $g['id']) ?>" data-reorg-item data-reorg-navegar<?= $ativo ? ' aria-current="true"' : '' ?>>
                <strong>Nº <?= (int)$g['numero'] ?> · <?= e($g['titulo'] ?: '(sem título)') ?></strong>
                <small><?= (int)$g['quantidade'] ?> exemplar(es) · <span class="adm-status adm-tone--<?= $tom ?>"><?= e($st) ?></span><?= in_array($g['status'], ['rascunho', 'correcao'], true) ? ((int)$g['completo'] ? ' · completo' : ' · incompleto') : '' ?>
                    <?= $g['editando_ate'] && strtotime($g['editando_ate']) > time() && $g['editando_nome'] && !$ativo ? ' · em edição: ' . e($g['editando_nome']) : '' ?></small></a></li>
        <?php endforeach; ?>
    </ol>
</details>

<?php if ($erroReg): ?><div class="adm-notice adm-notice--red"><?= icon('circle-alert') ?><div><strong><?= e($erroReg) ?></strong></div></div><?php endif; ?>

<?php if (!$reg): ?>
    <section class="adm-card adm-panel reorg-centro reorg-g1-inicio">
        <?php if ($editaveis): ?>
            <h2>Continuar o levantamento</h2>
            <p>Há <?= count($editaveis) ?> registro(s) em andamento neste lote.</p>
            <a class="adm-button adm-button--primary" href="<?= e($loteUrl . '&reg=' . $editaveis[0]['id']) ?>"><?= icon('arrow-right') ?> Continuar pelo registro nº <?= (int)$editaveis[0]['numero'] ?></a>
        <?php else: ?>
            <h2><?= $capacidade ? 'Comece o primeiro livro' : 'Todos os exemplares previstos já foram registrados' ?></h2>
        <?php endif; ?>
        <?php if ($capacidade): ?><button type="button" class="adm-button<?= $editaveis ? '' : ' adm-button--primary' ?>" data-reorg-novo <?= $etapaPausada ? 'disabled' : '' ?>><?= icon('plus') ?> Novo registro</button><?php endif; ?>
        <button type="button" class="adm-button" data-reorg-encaminhar="g2" <?= $etapaPausada ? 'disabled' : '' ?>><?= icon('send') ?> Encaminhar completos para a revisão</button>
    </section>
<?php else: $somenteLeitura = $reg['somente_leitura'] || $etapaPausada; [$st, $tom] = Levantamento::STATUS[$reg['status']]; ?>
    <section class="adm-card adm-panel reorg-g1-form">
        <div class="reorg-g1-form__cab">
            <h2>Registro nº <?= (int)$reg['numero'] ?> <?= reorg_status_badge($st, $tom) ?></h2>
            <div class="reorg-acoes">
                <?php if (!$somenteLeitura): ?><button type="button" class="adm-button adm-button--sm" data-reorg-abrir-pesquisa><?= icon('search') ?> Pesquisar obra no acervo</button><?php endif; ?>
                <button type="button" class="adm-button adm-button--sm" data-reorg-problema data-registro="<?= (int)$reg['id'] ?>"><?= icon('flag') ?> Informar problema</button>
                <?php if (!$somenteLeitura): ?><button type="button" class="adm-button adm-button--sm adm-button--danger-text" data-reorg-descartar="<?= (int)$reg['id'] ?>">Descartar</button><?php endif; ?>
            </div>
        </div>
        <?php if ($reg['motivo_leitura']): ?><div class="adm-notice adm-notice--amber"><?= icon('info') ?><div><strong><?= e($reg['motivo_leitura']) ?></strong></div></div><?php endif; ?>
        <?php if ($reg['status'] === 'correcao' && $reg['correcao_motivo']): ?><div class="adm-notice adm-notice--red"><?= icon('rotate-ccw') ?><div><strong>Devolvido para correção:</strong> <?= e($reg['correcao_motivo']) ?></div></div><?php endif; ?>
        <?php if ($reg['livro_sugerido_id']): ?>
            <div class="adm-notice adm-notice--blue"><?= icon('book-open') ?><div><strong>Obra do acervo escolhida:</strong> <?= e($reg['livro_sugerido_titulo']) ?><?= $reg['livro_sugerido_isbn'] ? ' · ISBN ' . e($reg['livro_sugerido_isbn']) : '' ?>.
                A revisão confirma se é a mesma edição. <?php if (!$somenteLeitura): ?><button type="button" class="adm-button adm-button--sm" data-reorg-sugerir="0">Retirar</button><?php endif; ?></div></div>
        <?php endif; ?>
        <div class="reorg-pesquisa" data-reorg-pesquisa hidden>
            <label class="adm-field"><span class="adm-field__label">Pesquisar obra existente (título, autor, ISBN ou editora)</span><input class="adm-input" type="search" data-reorg-q></label>
            <ul class="reorg-resultados" data-reorg-resultados></ul>
        </div>
        <?php include __DIR__ . '/form-registro.php'; ?>
    </section>
    <div class="reorg-barra-inferior" role="toolbar" aria-label="Navegação do levantamento">
        <a class="adm-button<?= $anterior ? '' : ' is-desativado' ?>" <?= $anterior ? 'href="' . e($loteUrl . '&reg=' . $anterior['id']) . '" data-reorg-navegar' : 'aria-disabled="true"' ?>><?= icon('chevron-left') ?> Anterior</a>
        <a class="adm-button<?= $proximo ? '' : ' is-desativado' ?>" <?= $proximo ? 'href="' . e($loteUrl . '&reg=' . $proximo['id']) . '" data-reorg-navegar' : 'aria-disabled="true"' ?>>Próximo <?= icon('chevron-right') ?></a>
        <?php if (!$somenteLeitura): ?>
            <button type="button" class="adm-button adm-button--primary" data-reorg-avancar="<?= e($destinoAvancar) ?>"><?= icon('check') ?> Confirmar e avançar</button>
        <?php endif; ?>
        <span class="reorg-barra-inferior__espaco"></span>
        <button type="button" class="adm-button" data-reorg-pausar-barra><?= icon('pause') ?> Pausar</button>
        <button type="button" class="adm-button" data-reorg-encaminhar="g2" <?= $etapaPausada ? 'disabled' : '' ?>><?= icon('send') ?> Encaminhar</button>
    </div>
    <span hidden data-reorg-registro="<?= (int)$reg['id'] ?>" data-somente-leitura="<?= $somenteLeitura ? '1' : '0' ?>" data-max="<?= (int)$maxQuantidade ?>"></span>
<?php endif; ?>
