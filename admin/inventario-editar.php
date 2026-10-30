<?php
/** Criação e edição dos dados de um inventário (exige inventario.gerenciar). */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

$usuario = fisico_iniciar('inventario.gerenciar');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0) ?: null;
$erro = null;
try {
    $inv = $id ? inventario_repo()->buscar($id) : null;
    $responsaveis = db_query('SELECT id, nome, perfil FROM administradores WHERE ativo = 1 ORDER BY nome')->fetchAll();
    $setores = localizacoes_servico()->setores();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir o inventário', fisico_erro_tecnico($e, 'edição de inventário'));
    exit;
}
if ($id && !$inv) {
    http_response_code(404);
    admin_pagina_erro('Inventário não encontrado', 'Confira o endereço e tente novamente.', 'clipboard-check');
    exit;
}
if ($inv && in_array($inv['status'], ['concluido', 'cancelado'], true)) {
    admin_flash('erro', 'Este inventário está finalizado.', 'Inventários concluídos ou cancelados ficam bloqueados para edição.');
    admin_redirecionar(admin_url('inventario-detalhes') . '?id=' . (int)$id);
}
$dados = [
    'titulo' => (string)($inv['titulo'] ?? ''),
    'data_inicio' => (string)($inv['data_inicio'] ?? date('Y-m-d')),
    'responsavel_id' => (string)($inv['responsavel_id'] ?? $usuario['id']),
    'setor' => (string)($inv['setor'] ?? ''),
    'descricao' => (string)($inv['descricao'] ?? ''),
];
$setorEditavel = !$inv || $inv['status'] === 'aberto';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    foreach (array_keys($dados) as $c) {
        $dados[$c] = admin_param($_POST, $c);
    }
    try {
        if ($id) {
            inventario_servico()->editar($id, $dados, $usuario['id']);
            admin_flash('sucesso', 'Inventário atualizado.');
        } else {
            $id = inventario_servico()->criar($dados, $usuario['id']);
            admin_flash('sucesso', 'Inventário criado em preparação.', 'Revise os dados e clique em "Iniciar conferência" quando a equipe estiver pronta.');
        }
        admin_redirecionar(admin_url('inventario-detalhes') . '?id=' . $id);
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    } catch (PDOException $e) {
        $erro = fisico_erro_tecnico($e, 'cadastro de inventário');
    }
}

$titulo = $inv ? 'Editar inventário' : 'Novo inventário';
admin_page_start('inventario', $titulo, 'Inventário', 'Conferência digital do acervo físico', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], [$titulo]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($titulo) ?></h1>
        <p>O inventário começa em preparação. A lista de exemplares previstos é congelada só quando a conferência é iniciada.</p>
    </div>
    <div class="adm-page-head__actions"><a class="adm-button adm-button--ghost" href="<?= e($inv ? admin_url('inventario-detalhes') . '?id=' . (int)$inv['id'] : admin_url('inventario')) ?>"><?= icon('arrow-left') ?> Voltar</a></div>
</section>

<?php if ($erro): ?><?= admin_aviso('erro', 'O inventário não foi salvo.', $erro) ?><?php endif; ?>

<form method="post" class="adm-card adm-panel adm-form-section" data-loading-form>
    <?= csrf_campo() ?>
    <?php if ($inv): ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><?php endif; ?>
    <div class="adm-form-grid">
        <label class="adm-field adm-field--full"><span class="adm-field__label">Nome do inventário <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input" name="titulo" maxlength="150" minlength="3" required value="<?= e($dados['titulo']) ?>" placeholder="Inventário Geral — Biblioteca Dom Pedro II" autofocus></label>
        <label class="adm-field"><span class="adm-field__label">Data de início <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input" type="date" name="data_inicio" required value="<?= e($dados['data_inicio']) ?>"></label>
        <label class="adm-field"><span class="adm-field__label">Responsável <span class="adm-required" aria-hidden="true">*</span></span>
            <select class="adm-select" name="responsavel_id" required>
                <?php foreach ($responsaveis as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"<?= (string)$r['id'] === $dados['responsavel_id'] ? ' selected' : '' ?>><?= e($r['nome']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="adm-field adm-field--full"><span class="adm-field__label">Setor conferido</span>
            <select class="adm-select" name="setor"<?= $setorEditavel ? '' : ' disabled' ?>>
                <option value="">Acervo inteiro</option>
                <?php foreach ($setores as $s): ?><option value="<?= e($s) ?>"<?= $dados['setor'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
            <span class="adm-field__hint"><?= $setorEditavel
                ? 'Com um setor, só os exemplares com localização nesse setor entram na lista prevista. Exemplares sem localização entram apenas no inventário do acervo inteiro.'
                : 'O setor não muda depois que a conferência começou (a lista prevista já foi definida).' ?></span></label>
        <label class="adm-field adm-field--full"><span class="adm-field__label">Observações <span class="adm-optional">(opcional)</span></span>
            <textarea class="adm-textarea" name="descricao" rows="3" maxlength="2000" placeholder="Ex.: equipe, turnos combinados, cuidados"><?= e($dados['descricao']) ?></textarea></label>
    </div>
    <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> <?= $inv ? 'Salvar alterações' : 'Criar inventário' ?></button>
</form>

<?php
admin_page_end(FISICO_JS);
