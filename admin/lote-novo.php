<?php
/**
 * Formulário ÚNICO de criação de lotes de inventário (migração 240). Todos os botões "Abrir novo lote"
 * (Conferência, Lotes do inventário, Histórico de Lotes) chegam aqui.
 *
 * Campos: inventário, nome, quantidade prevista (capacidade, por exemplar físico), responsável, prazo
 * (opcional), instruções, observações e usuários do painel que registram no lote. O número do lote é
 * gerado no servidor (LoteService::criar) e o token do formulário evita abrir dois lotes.
 *
 * Módulos opcionais acrescentam campos (gancho lote.form) e completam o lote na MESMA transação
 * (gancho lote.criado). Ex.: com a organização por equipes ativa no inventário escolhido, aparecem os
 * participantes do Grupo 1, 3 e 4, e o lote ganha a área das equipes — sem criar um segundo lote.
 * Depois de criado, abre a área de trabalho do lote dentro do inventário.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\LoteService;
use Biblioteca\Services\OperacaoService;

$usuario = fisico_iniciar('lotes.gerenciar');
$inventarios = inventarios_para_lote();
$form = [
    'inventario_id' => (string)((int)admin_param($_GET, 'inventario') ?: inventario_padrao_lote()),
    'nome' => '', 'capacidade' => '30', 'responsavel_id' => (string)$usuario['id'], 'prazo' => '', 'instrucoes' => '', 'observacoes' => '', 'usuarios' => [],
];
$erro = null;

/** Cria o lote e o que os módulos acrescentam numa transação só (com nova tentativa se dois abrirem juntos). */
function lote_novo_criar(array $post, int $adminId): int
{
    $usuarios = array_values(array_unique(array_filter(array_map('intval', array_merge([(int)($post['responsavel_id'] ?? 0)], is_array($post['usuarios'] ?? null) ? $post['usuarios'] : [])))));
    $dados = [
        'capacidade' => admin_param($post, 'capacidade'), 'usuarios' => $usuarios, 'inventario_id' => admin_param($post, 'inventario_id'),
        'nome' => admin_param($post, 'nome'), 'prazo' => admin_param($post, 'prazo'), 'instrucoes' => (string)($post['instrucoes'] ?? ''), 'observacoes' => (string)($post['observacoes'] ?? ''),
    ];
    if ((int)$dados['inventario_id'] <= 0) {
        throw new AcervoException('Escolha o inventário a que o lote pertence.');
    }
    if (trim((string)$dados['nome']) === '') {
        throw new AcervoException('Informe o nome ou a identificação do lote (ex.: Estante A — literatura).');
    }
    $pdo = db();
    for ($tentativa = 1; ; $tentativa++) {
        $pdo->beginTransaction();
        try {
            $id = lotes_servico()->criar($dados, $adminId, admin_param($post, 'chave'));
            modulo_gancho('lote.criado', $id, $post, $adminId);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $disputa = $e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1062, 1213], true);
            if (!$disputa || $tentativa >= 5) {
                throw $e;
            }
            usleep(random_int(20000, 120000));
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    foreach (['inventario_id', 'nome', 'capacidade', 'responsavel_id', 'prazo'] as $c) {
        $form[$c] = admin_param($_POST, $c);
    }
    $form['instrucoes'] = (string)($_POST['instrucoes'] ?? '');
    $form['observacoes'] = (string)($_POST['observacoes'] ?? '');
    $form['usuarios'] = array_map('intval', is_array($_POST['usuarios'] ?? null) ? $_POST['usuarios'] : []);
    try {
        $id = lote_novo_criar($_POST, (int)$usuario['id']);
        $lote = lotes_repo()->lote($id);
        admin_flash('sucesso', lote_nome_completo($lote) . ' criado — ' . (int)$lote['capacidade'] . ' exemplar(es) previsto(s).',
            'Usuários do lote: ' . ($lote['participantes'] ?: '—') . '. Cada exemplar registrado no lote entra nele automaticamente.');
        admin_redirecionar(lote_url_trabalho($lote));
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    } catch (PDOException $e) {
        $erro = fisico_erro_tecnico($e, 'criação de lote') . ' Confira se a migração 240 foi aplicada.';
    }
}

$atribuiveis = lotes_repo()->usuariosAtribuiveis();
$proximoNumero = (int)db_query('SELECT COALESCE(MAX(numero), 0) + 1 FROM cadastro_lotes')->fetchColumn();
$invAtual = null;
foreach ($inventarios as $i) {
    if ((string)$i['id'] === (string)$form['inventario_id']) {
        $invAtual = inventario_repo()->buscar((int)$i['id']);
    }
}

admin_page_start('inventario', 'Novo lote', 'Inventário', 'Lotes do inventário', FISICO_CSS);
?>

<?php if ($invAtual): ?>
<?= inventario_cabecalho_html($invAtual, 'lotes', 'Novo lote deste inventário. O número é gerado automaticamente; os exemplares registrados no lote entram nele e no inventário.') ?>
<?php else: ?>
<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], ['Novo lote']]) ?>
<section class="adm-page-head"><div><h1>Novo lote</h1><p>Todo lote pertence a um inventário.</p></div></section>
<?php endif; ?>

<?php if ($erro): ?><?= admin_aviso('erro', 'O lote não foi criado.', $erro) ?><?php endif; ?>

<?php if (!$inventarios): ?>
    <?= admin_aviso('aviso', 'Nenhum inventário recebe lotes agora.', 'Crie um inventário (ou retome um pausado) para abrir lotes.') ?>
    <p><a class="adm-button adm-button--primary" href="<?= e(admin_url('inventario-editar')) ?>"><?= icon('plus') ?> Novo inventário</a></p>
<?php else: ?>
<form method="post" class="adm-card adm-panel adm-form-section adm-lote-novo" data-enviar-uma-vez>
    <?= csrf_campo() ?><input type="hidden" name="chave" value="<?= e(OperacaoService::novaChave()) ?>">
    <div class="adm-form-grid adm-form-grid--3">
        <label class="adm-field"><span class="adm-field__label">Inventário <span class="adm-required" aria-hidden="true">*</span></span>
            <select class="adm-select" name="inventario_id" required data-lote-inventario>
                <?php foreach ($inventarios as $i): ?>
                    <option value="<?= (int)$i['id'] ?>"<?= (string)$i['id'] === (string)$form['inventario_id'] ? ' selected' : '' ?>><?= e($i['titulo']) ?> — <?= e(Biblioteca\Services\InventarioService::STATUS[$i['status']]) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="adm-field"><span class="adm-field__label">Nome do lote <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input" name="nome" maxlength="120" required value="<?= e($form['nome']) ?>" placeholder="Ex.: Estante A — literatura"></label>
        <div class="adm-field"><span class="adm-field__label">Número</span>
            <p class="adm-lote-numero">Lote <?= e(str_pad((string)$proximoNumero, 3, '0', STR_PAD_LEFT)) ?> <small class="adm-table__muted">(gerado ao criar)</small></p></div>
        <label class="adm-field"><span class="adm-field__label">Quantidade prevista de exemplares <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input" type="number" inputmode="numeric" name="capacidade" min="1" max="<?= LoteService::CAPACIDADE_MAXIMA ?>" required value="<?= e((string)$form['capacidade']) ?>">
            <span class="adm-field__hint">Contada por exemplar físico. Ao completar, o lote fecha para novos cadastros.</span></label>
        <label class="adm-field"><span class="adm-field__label">Responsável <span class="adm-required" aria-hidden="true">*</span></span>
            <select class="adm-select" name="responsavel_id" required>
                <?php foreach ($atribuiveis as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (string)$a['id'] === (string)$form['responsavel_id'] ? ' selected' : '' ?>><?= e($a['nome']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="adm-field"><span class="adm-field__label">Prazo <span class="adm-optional">(opcional)</span></span>
            <input class="adm-input" type="date" name="prazo" value="<?= e((string)$form['prazo']) ?>"></label>
        <label class="adm-field adm-field--span2"><span class="adm-field__label">Instruções <span class="adm-optional">(quem trabalha no lote vê)</span></span>
            <textarea class="adm-textarea" name="instrucoes" rows="3" maxlength="5000" placeholder="Ex.: começar pela prateleira de cima"><?= e($form['instrucoes']) ?></textarea></label>
        <label class="adm-field"><span class="adm-field__label">Observações internas <span class="adm-optional">(opcional)</span></span>
            <textarea class="adm-textarea" name="observacoes" rows="3" maxlength="5000"><?= e($form['observacoes']) ?></textarea></label>
        <fieldset class="adm-field adm-field--full"><legend class="adm-field__label">Outros usuários do painel que registram no lote <span class="adm-optional">(o responsável já entra)</span></legend>
            <div class="adm-lote-usuarios">
                <?php foreach ($atribuiveis as $a): ?>
                    <label class="adm-check"><input type="checkbox" name="usuarios[]" value="<?= (int)$a['id'] ?>"<?= in_array((int)$a['id'], $form['usuarios'], true) ? ' checked' : '' ?>> <span><?= e($a['nome']) ?></span></label>
                <?php endforeach; ?>
            </div></fieldset>
    </div>
    <?= modulo_gancho_html('lote.form', (int)$form['inventario_id'], $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null) ?>
    <div class="adm-form-actions adm-form-actions--static">
        <button class="adm-button adm-button--primary" type="submit"><?= icon('plus') ?> Criar lote</button>
        <a class="adm-button adm-button--ghost" href="<?= e($invAtual ? admin_url('inventario-lotes') . '?id=' . (int)$invAtual['id'] : admin_url('lotes')) ?>">Cancelar</a>
    </div>
</form>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
