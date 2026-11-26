<?php
/** Central de Reorganização → Lotes → Criar lote (página própria, em três seções). */
require __DIR__ . '/../includes/admin.php';

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;

reorg_admin_exigir_modulo();
$usuario = reorg_admin_usuario();
$adminId = (int)$usuario['id'];
$r = reorg();
$periodo = $r->periodoAtivo();
$erro = null;
$v = ['nome' => '', 'quantidade' => '', 'responsavel_id' => $adminId, 'prazo' => '', 'observacoes' => '', 'instrucoes' => '', 'admins' => [], 'grupo1' => [], 'grupo3' => [], 'grupo4' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_exigir_csrf();
    $v = array_merge($v, array_intersect_key($_POST, $v));
    try {
        $id = $r->criarLote($_POST, $adminId);
        $sem = array_filter(['grupo1' => 'Grupo 1', 'grupo3' => 'Grupo 3', 'grupo4' => 'Grupo 4'], fn ($eq) => empty($_POST[$eq]), ARRAY_FILTER_USE_KEY);
        admin_flash('sucesso', 'Lote criado', 'Os participantes escolhidos foram avisados.' . ($sem ? ' Ainda sem participantes: ' . implode(', ', $sem) . ' (atribua na aba Participantes do lote).' : ''));
        admin_redirecionar(reorg_url('admin/lote.php?id=' . $id));
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    }
}

$admins = reorg_administradores();
$participantes = array_filter($r->participantesAtivos(), fn ($p) => $p['equipe'] !== null && $p['estado'] !== 'interrompida');
$porEquipe = ['grupo1' => [], 'grupo3' => [], 'grupo4' => []];
foreach ($participantes as $p) {
    $porEquipe[$p['equipe']][] = $p;
}
$marcado = fn (string $campo, int $id) => in_array((string)$id, array_map('strval', (array)$v[$campo]), true) ? ' checked' : '';

reorg_admin_inicio('lotes', 'Criar lote', [['Criar lote', null]]);
?>
<section class="adm-page-head reorg-head">
    <div><h1>Criar lote</h1><p>Preencha as informações, distribua os participantes (pode completar depois) e confira o resumo antes de criar.</p></div>
</section>

<?php if (!$periodo): ?>
    <?= admin_aviso('aviso', 'Nenhum período ativo', 'Inicie o período de reorganização em Configurações antes de criar lotes.') ?>
<?php else: ?>
<?php if ($erro): ?><?= admin_aviso('erro', 'O lote não foi criado', $erro) ?><?php endif; ?>
<form method="post" class="reorg-novo-lote" data-reorg-novo-lote>
    <?= csrf_campo() ?>
    <section class="adm-card adm-panel reorg-secao">
        <h2><span class="reorg-secao__num">1</span> Informações do lote</h2>
        <div class="reorg-campos reorg-campos--3">
            <label class="adm-field reorg-campos__2"><span class="adm-field__label">Nome ou identificação <span class="adm-required">*</span></span>
                <input class="adm-input" name="nome" required maxlength="120" placeholder="Ex.: Lote 001 — Estante A" value="<?= e((string)$v['nome']) ?>" data-resumo="nome"></label>
            <label class="adm-field"><span class="adm-field__label">Exemplares físicos previstos <span class="adm-required">*</span></span>
                <input class="adm-input" type="number" name="quantidade" min="1" max="<?= (int)reorg_config('max_exemplares_lote') ?>" required value="<?= e((string)$v['quantidade']) ?>" data-resumo="quantidade">
                <span class="adm-field__hint">Livros físicos (cópias), não obras.</span></label>
            <label class="adm-field"><span class="adm-field__label">Administrador responsável <span class="adm-required">*</span></span>
                <select class="adm-select" name="responsavel_id" data-resumo="responsavel"><?= reorg_opcoes($admins, $v['responsavel_id']) ?></select></label>
            <label class="adm-field"><span class="adm-field__label">Prazo previsto <span class="adm-optional">opcional</span></span>
                <input class="adm-input" type="date" name="prazo" value="<?= e((string)$v['prazo']) ?>"></label>
            <span></span>
            <label class="adm-field reorg-campos__meia"><span class="adm-field__label">Instruções específicas <span class="adm-optional">aparecem para os participantes do lote</span></span>
                <textarea class="adm-textarea" name="instrucoes" rows="3" maxlength="5000"><?= e((string)$v['instrucoes']) ?></textarea></label>
            <label class="adm-field reorg-campos__meia"><span class="adm-field__label">Observações <span class="adm-optional">internas, só administradores</span></span>
                <textarea class="adm-textarea" name="observacoes" rows="3" maxlength="5000" data-resumo="observacoes"><?= e((string)$v['observacoes']) ?></textarea></label>
        </div>
    </section>

    <section class="adm-card adm-panel reorg-secao">
        <h2><span class="reorg-secao__num">2</span> Distribuição dos participantes</h2>
        <p class="adm-field__hint">Só aparecem pessoas conectadas agora, em cada equipe escolhida por elas. O lote pode ser criado com equipes vazias: complete depois na aba Participantes do lote.</p>
        <div class="reorg-distribuicao">
            <?php foreach (['grupo1' => 'Grupo 1 — Levantamento de dados', 'grupo2' => 'Grupo 2 — Revisão e cadastramento', 'grupo3' => 'Grupo 3 — Carimbação', 'grupo4' => 'Grupo 4 — Conferência e organização'] as $eq => $titulo): ?>
                <fieldset class="reorg-grupo" data-grupo="<?= $eq ?>">
                    <legend><?= e($titulo) ?> <span class="adm-tabs__count" data-contagem>0</span></legend>
                    <?php if ($eq === 'grupo2'): ?>
                        <p class="reorg-grupo__fixo"><?= icon('user-round') ?> Responsável: <strong data-resumo-responsavel><?= e((string)(array_column($admins, 'nome', 'id')[$v['responsavel_id']] ?? '')) ?></strong></p>
                        <p class="adm-field__hint">Outros administradores autorizados a revisar (opcional):</p>
                        <ul class="reorg-escolhas">
                            <?php foreach ($admins as $a): ?>
                                <li><label class="reorg-escolha"><input type="checkbox" name="admins[]" value="<?= (int)$a['id'] ?>"<?= $marcado('admins', (int)$a['id']) ?> data-nome="<?= e($a['nome']) ?>"> <span><strong><?= e($a['nome']) ?></strong></span></label></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif (!$porEquipe[$eq]): ?>
                        <p class="reorg-vazio">Ninguém conectado nesta equipe agora.</p>
                    <?php else: ?>
                        <ul class="reorg-escolhas">
                            <?php foreach ($porEquipe[$eq] as $p): $livre = !$p['lotes']; ?>
                                <li><label class="reorg-escolha">
                                    <input type="checkbox" name="<?= $eq ?>[]" value="<?= (int)$p['id'] ?>"<?= $marcado($eq, (int)$p['id']) ?> data-nome="<?= e($p['nome']) ?>">
                                    <span><strong><?= e($p['nome']) ?></strong> <small class="adm-mono"><?= e(Reorganizacao::rotuloSessao($p['id'])) ?></small><?= $p['mesmo_nome'] ? ' <span class="adm-status adm-tone--amber">nome repetido</span>' : '' ?>
                                        <small class="reorg-escolha__estado"><?= $livre ? '<span class="reorg-ponto is-livre"></span> Disponível' : '<span class="reorg-ponto"></span> Já trabalha em: ' . e(implode(', ', array_column($p['lotes'], 'nome'))) ?> · <?= e(Reorganizacao::ESTADOS_CONEXAO[$p['estado']][0] ?? '') ?></small></span>
                                </label></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="adm-card adm-panel reorg-secao" aria-live="polite">
        <h2><span class="reorg-secao__num">3</span> Resumo</h2>
        <dl class="reorg-resumo-lote" data-reorg-resumo>
            <div><dt>Identificação</dt><dd data-r="nome"><?= e((string)$v['nome']) ?: '—' ?></dd></div>
            <div><dt>Quantidade prevista</dt><dd data-r="quantidade"><?= e((string)$v['quantidade']) ?: '—' ?></dd></div>
            <div><dt>Responsável</dt><dd data-r="responsavel"><?= e((string)(array_column($admins, 'nome', 'id')[$v['responsavel_id']] ?? '')) ?></dd></div>
            <div><dt>Grupo 1</dt><dd data-r="grupo1">—</dd></div>
            <div><dt>Grupo 2 (além do responsável)</dt><dd data-r="grupo2">—</dd></div>
            <div><dt>Grupo 3</dt><dd data-r="grupo3">—</dd></div>
            <div><dt>Grupo 4</dt><dd data-r="grupo4">—</dd></div>
            <div><dt>Observações</dt><dd data-r="observacoes"><?= e((string)$v['observacoes']) ?: '—' ?></dd></div>
        </dl>
        <p class="reorg-sem-equipe" data-r="sem" hidden></p>
        <div class="reorg-botoes">
            <a class="adm-button" href="<?= e(reorg_url('admin/lotes.php')) ?>">Cancelar</a>
            <button class="adm-button adm-button--primary" type="submit"><?= icon('plus') ?> Criar lote</button>
        </div>
    </section>
</form>
<?php endif; ?>
<?php reorg_admin_fim();
