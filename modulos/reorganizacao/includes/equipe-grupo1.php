<?php
/** Área de trabalho do Grupo 1 (levantamento). Variáveis: $r, $sessao, $lote, $c, $base, $etapaPausada. */
use Biblioteca\Reorganizacao\Levantamento;
use Biblioteca\Reorganizacao\Reorganizacao;

$lev = $r->levantamento();
$registros = $lev->registrosDoLote((int)$lote['id'], 'todos');
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
?>
<div class="reorg-g1">
    <div class="adm-card adm-panel reorg-g1__lista">
        <div class="reorg-linha">
            <button type="button" class="adm-button adm-button--primary" data-reorg-novo <?= $etapaPausada || $c['registrados'] >= $c['prevista'] ? 'disabled' : '' ?>><?= icon('plus') ?> Novo registro</button>
            <button type="button" class="adm-button" data-reorg-encaminhar="g2" <?= $etapaPausada ? 'disabled' : '' ?>><?= icon('send') ?> Encaminhar para revisão</button>
        </div>
        <p class="adm-field__hint"><?= $c['aguardando_levantamento'] ?> exemplar(es) ainda sem registro neste lote. Registros completos podem ser encaminhados parcialmente; os demais continuam aqui.</p>
        <ol class="reorg-registros" data-reorg-lista>
            <?php if (!$registros): ?><li class="adm-table__muted">Nenhum registro ainda.</li><?php endif; ?>
            <?php foreach ($registros as $g): [$st, $tom] = Levantamento::STATUS[$g['status']]; $ativo = $reg && (int)$reg['id'] === (int)$g['id']; ?>
                <li class="<?= $ativo ? 'is-ativo' : '' ?>"><a href="<?= e($base . '?lote=' . $lote['id'] . '&reg=' . $g['id']) ?>" data-reorg-item>
                    <strong>Nº <?= (int)$g['numero'] ?> · <?= e($g['titulo'] ?: '(sem título)') ?></strong>
                    <small><?= (int)$g['quantidade'] ?> exemplar(es) · <span class="adm-status adm-tone--<?= $tom ?>"><?= e($st) ?></span><?= in_array($g['status'], ['rascunho', 'correcao'], true) ? ((int)$g['completo'] ? ' · completo' : ' · incompleto') : '' ?>
                        <?= $g['editando_ate'] && strtotime($g['editando_ate']) > time() && $g['editando_nome'] && !$ativo ? ' · em edição: ' . e($g['editando_nome']) : '' ?></small></a></li>
            <?php endforeach; ?>
        </ol>
    </div>
    <div class="adm-card adm-panel reorg-g1__form">
        <?php if ($erroReg): ?><div class="adm-notice adm-notice--red"><?= icon('circle-alert') ?><div><strong><?= e($erroReg) ?></strong></div></div><?php endif; ?>
        <?php if (!$reg): ?>
            <div class="reorg-centro"><p>Clique em “Novo registro” para começar um livro, ou abra um registro da lista.</p></div>
        <?php else: $somenteLeitura = $reg['somente_leitura']; [$st, $tom] = Levantamento::STATUS[$reg['status']]; ?>
            <div class="reorg-lote-topo"><h2>Registro nº <?= (int)$reg['numero'] ?> <?= reorg_status_badge($st, $tom) ?></h2>
                <div class="reorg-acoes">
                    <?php if (!$somenteLeitura): ?><button type="button" class="adm-button adm-button--sm" data-reorg-abrir-pesquisa><?= icon('search') ?> Pesquisar obra</button><?php endif; ?>
                    <button type="button" class="adm-button adm-button--sm" data-reorg-problema data-registro="<?= (int)$reg['id'] ?>"><?= icon('flag') ?> Informar problema</button>
                    <?php if (!$somenteLeitura): ?><button type="button" class="adm-button adm-button--sm adm-button--danger-text" data-reorg-descartar="<?= (int)$reg['id'] ?>">Descartar</button><?php endif; ?>
                </div></div>
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
        <?php endif; ?>
    </div>
</div>
<?php if ($reg): ?><span hidden data-reorg-registro="<?= (int)$reg['id'] ?>" data-somente-leitura="<?= $reg['somente_leitura'] ? '1' : '0' ?>" data-max="<?= (int)reorg_config('max_exemplares_registro') ?>"></span><?php endif; ?>
