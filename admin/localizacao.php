<?php
/** Cadastro e edição de uma localização física (exige localizacoes.gerenciar). */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

$usuario = fisico_iniciar('localizacoes.gerenciar');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0) ?: null;
$erro = null;
try {
    $atual = $id ? localizacoes_servico()->buscar($id) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a localização', fisico_erro_tecnico($e, 'localização'));
    exit;
}
if ($id && !$atual) {
    http_response_code(404);
    admin_pagina_erro('Localização não encontrada', 'Ela pode ter sido informada errado no endereço.', 'map-pin-off');
    exit;
}
$campos = ['setor', 'estante', 'prateleira', 'complemento', 'codigo', 'descricao'];
$dados = [];
foreach ($campos as $c) {
    $dados[$c] = (string)($atual[$c] ?? '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    foreach ($campos as $c) {
        $dados[$c] = mb_substr(admin_param($_POST, $c), 0, 255);
    }
    try {
        $salvo = localizacoes_servico()->salvar($dados, $usuario['id'], $id);
        admin_flash('sucesso', $id ? 'Localização atualizada.' : 'Localização cadastrada.', $id ? 'Os exemplares que estão nela passam a mostrar o novo texto.' : 'Ela já pode ser escolhida para os exemplares.');
        admin_redirecionar(admin_url('localizacoes'));
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    } catch (PDOException $e) {
        $erro = fisico_erro_tecnico($e, 'cadastro de localização');
    }
}

$titulo = $id ? 'Editar localização' : 'Nova localização';
admin_page_start('localizacoes', $titulo, 'Exemplares', 'Localizações físicas', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Localizações', admin_url('localizacoes')], [$titulo]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($titulo) ?></h1>
        <p>A mesma combinação de setor, estante, prateleira e complemento não pode ser cadastrada duas vezes.</p>
    </div>
    <div class="adm-page-head__actions"><a class="adm-button adm-button--ghost" href="<?= e(admin_url('localizacoes')) ?>"><?= icon('arrow-left') ?> Voltar</a></div>
</section>

<?php if ($erro): ?><?= admin_aviso('erro', 'A localização não foi salva.', $erro) ?><?php endif; ?>

<form method="post" class="adm-card adm-panel adm-form-section" data-loading-form>
    <?= csrf_campo() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
    <div class="adm-form-grid adm-form-grid--4">
        <label class="adm-field"><span class="adm-field__label">Setor ou seção <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input" name="setor" maxlength="80" required value="<?= e($dados['setor']) ?>" placeholder="Literatura" autofocus></label>
        <label class="adm-field"><span class="adm-field__label">Estante</span>
            <input class="adm-input" name="estante" maxlength="80" value="<?= e($dados['estante']) ?>" placeholder="E03"></label>
        <label class="adm-field"><span class="adm-field__label">Prateleira</span>
            <input class="adm-input" name="prateleira" maxlength="80" value="<?= e($dados['prateleira']) ?>" placeholder="P02"></label>
        <label class="adm-field"><span class="adm-field__label">Complemento <span class="adm-optional">(opcional)</span></span>
            <input class="adm-input" name="complemento" maxlength="80" value="<?= e($dados['complemento']) ?>" placeholder="Lado esquerdo"></label>
        <label class="adm-field"><span class="adm-field__label">Código curto <span class="adm-optional">(opcional)</span></span>
            <input class="adm-input adm-mono" name="codigo" maxlength="30" value="<?= e($dados['codigo']) ?>" placeholder="LIT-E03-P02" pattern="[A-Za-z0-9][A-Za-z0-9.\-]*">
            <span class="adm-field__hint">Letras sem acento, números, ponto e hífen. Não se repete.</span></label>
        <label class="adm-field adm-field--full"><span class="adm-field__label">Descrição <span class="adm-optional">(opcional)</span></span>
            <input class="adm-input" name="descricao" maxlength="255" value="<?= e($dados['descricao']) ?>" placeholder="Ex.: perto da janela"></label>
    </div>
    <?php if ($atual && (int)$atual['exemplares'] > 0): ?>
        <?= admin_aviso('info', 'Há ' . (int)$atual['exemplares'] . ' exemplar(es) nesta localização.', 'Ao editar o texto, todos eles passam a mostrar a localização corrigida. Para mudar um exemplar de lugar, use "Transferir" na página do exemplar.') ?>
    <?php endif; ?>
    <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar localização</button>
</form>

<?php
admin_page_end(FISICO_JS);
