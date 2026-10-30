<?php
/**
 * Modelos de etiqueta: medidas da página e da etiqueta, margens e espaços em milímetros,
 * e o que cada etiqueta mostra. Modelos não são excluídos: são desativados.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

$usuario = fisico_iniciar('etiquetas.configurar');
$base = admin_url('etiqueta-modelos');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0) ?: null;
$editando = $id !== null || admin_param($_GET, 'novo') === '1' || admin_param($_POST, 'acao') === 'salvar';
$erro = null;
$campos = ['nome', 'tipo', 'pagina_largura', 'pagina_altura', 'etiqueta_largura', 'etiqueta_altura', 'margem_superior', 'margem_esquerda', 'espaco_horizontal', 'espaco_vertical', 'colunas', 'linhas'];
$opcoes = ['codigo_barras' => 'Código de barras', 'mostrar_biblioteca' => 'Nome da biblioteca', 'mostrar_titulo' => 'Título abreviado', 'mostrar_cdd' => 'CDD aprovada', 'mostrar_local' => 'Localização confirmada'];
$dados = ['nome' => '', 'tipo' => 'patrimonial', 'pagina_largura' => '210', 'pagina_altura' => '297', 'etiqueta_largura' => '63.5', 'etiqueta_altura' => '25.4',
    'margem_superior' => '10', 'margem_esquerda' => '7', 'espaco_horizontal' => '2.5', 'espaco_vertical' => '0', 'colunas' => '3', 'linhas' => '11',
    'codigo_barras' => 1, 'mostrar_biblioteca' => 1, 'mostrar_titulo' => 1, 'mostrar_cdd' => 1, 'mostrar_local' => 1];

try {
    if ($id) {
        $atual = etiquetas_servico()->modelo($id);
        if (!$atual) {
            http_response_code(404);
            admin_pagina_erro('Modelo não encontrado', 'Confira o endereço e tente novamente.', 'ruler');
            exit;
        }
        foreach ($dados as $c => $_) {
            $dados[$c] = is_numeric($atual[$c]) && !in_array($c, array_keys($opcoes), true) ? (string)(float)$atual[$c] : $atual[$c];
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        $acao = admin_param($_POST, 'acao');
        if ($acao === 'ativar' || $acao === 'desativar') {
            etiquetas_servico()->definirAtivo((int)$id, $acao === 'ativar', $usuario['id']);
            admin_flash('sucesso', $acao === 'ativar' ? 'Modelo reativado.' : 'Modelo desativado.');
            admin_redirecionar($base);
        }
        foreach ($campos as $c) {
            $dados[$c] = mb_substr(admin_param($_POST, $c), 0, 100);
        }
        foreach ($opcoes as $c => $_) {
            $dados[$c] = admin_param($_POST, $c) === '1' ? 1 : 0;
        }
        try {
            etiquetas_servico()->salvarModelo($dados, $usuario['id'], $id);
            admin_flash('sucesso', 'Modelo salvo.', 'Gere uma folha de teste com o contorno das etiquetas antes de imprimir em lote.');
            admin_redirecionar($base);
        } catch (AcervoException $e) {
            $erro = $e->getMessage();
        }
    }
    $modelos = etiquetas_servico()->modelos(false);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir os modelos', fisico_erro_tecnico($e, 'modelos de etiqueta'));
    exit;
}
$mm = fn ($v) => str_replace('.', ',', (string)(float)$v);

admin_page_start('etiquetas', 'Modelos de etiqueta', 'Etiquetas', 'Impressão das etiquetas dos exemplares', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Etiquetas', admin_url('etiquetas')], ['Modelos']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Modelos de etiqueta</h1>
        <p>Medidas em milímetros. Confira as medidas na embalagem da folha de etiquetas (por exemplo, Pimaco ou equivalentes) e ajuste as margens depois de um teste.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('etiquetas')) ?>"><?= icon('arrow-left') ?> Etiquetas</a>
        <?php if (!$editando): ?><a class="adm-button adm-button--primary" href="<?= e($base . '?novo=1') ?>"><?= icon('plus') ?> Novo modelo</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($erro): ?><?= admin_aviso('erro', 'O modelo não foi salvo.', $erro) ?><?php endif; ?>

<?php if ($editando): ?>
    <form method="post" action="<?= e($base) ?>" class="adm-card adm-panel adm-form-section" data-loading-form>
        <?= csrf_campo() ?><input type="hidden" name="acao" value="salvar">
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
        <h2 class="adm-panel__title"><?= $id ? 'Editar modelo' : 'Novo modelo' ?></h2>
        <div class="adm-form-grid adm-form-grid--4">
            <label class="adm-field adm-field--full"><span class="adm-field__label">Nome *</span><input class="adm-input" name="nome" maxlength="100" required value="<?= e((string)$dados['nome']) ?>" placeholder="Patrimonial A4 — 3 × 10"></label>
            <label class="adm-field"><span class="adm-field__label">Tipo</span>
                <select class="adm-select" name="tipo"><option value="patrimonial"<?= $dados['tipo'] === 'patrimonial' ? ' selected' : '' ?>>Patrimonial (código e barras)</option><option value="lombada"<?= $dados['tipo'] === 'lombada' ? ' selected' : '' ?>>Lombada (CDD em destaque)</option></select></label>
            <?php
            $numeros = [
                'pagina_largura' => 'Largura da página', 'pagina_altura' => 'Altura da página', 'etiqueta_largura' => 'Largura da etiqueta', 'etiqueta_altura' => 'Altura da etiqueta',
                'margem_superior' => 'Margem superior', 'margem_esquerda' => 'Margem esquerda', 'espaco_horizontal' => 'Espaço entre colunas', 'espaco_vertical' => 'Espaço entre linhas',
            ];
            foreach ($numeros as $c => $rotulo): ?>
                <label class="adm-field"><span class="adm-field__label"><?= e($rotulo) ?> (mm)</span><input class="adm-input" name="<?= $c ?>" inputmode="decimal" required value="<?= e($mm($dados[$c])) ?>" pattern="[0-9]+([.,][0-9]{1,2})?"></label>
            <?php endforeach; ?>
            <label class="adm-field"><span class="adm-field__label">Colunas</span><input class="adm-input" type="number" name="colunas" min="1" max="20" required value="<?= (int)$dados['colunas'] ?>"></label>
            <label class="adm-field"><span class="adm-field__label">Linhas</span><input class="adm-input" type="number" name="linhas" min="1" max="40" required value="<?= (int)$dados['linhas'] ?>"></label>
        </div>
        <fieldset class="adm-fieldset">
            <legend>O que aparece na etiqueta</legend>
            <p class="adm-field__hint">O código do exemplar sempre aparece. Dados de alunos, senhas e tokens nunca entram na etiqueta.</p>
            <?php foreach ($opcoes as $c => $rotulo): ?>
                <label class="adm-check-confirm"><input type="checkbox" name="<?= $c ?>" value="1"<?= (int)$dados[$c] ? ' checked' : '' ?>> <span><?= e($rotulo) ?></span></label>
            <?php endforeach; ?>
        </fieldset>
        <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar modelo</button>
        <a class="adm-button adm-button--ghost" href="<?= e($base) ?>">Cancelar</a>
    </form>
<?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-modelos">
    <h2 id="titulo-modelos" class="adm-panel__title">Modelos cadastrados</h2>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards">
            <thead><tr><th scope="col">Modelo</th><th scope="col">Tipo</th><th scope="col">Etiqueta</th><th scope="col">Folha</th><th scope="col">Situação</th><th scope="col" class="adm-actions-col">Ações</th></tr></thead>
            <tbody>
                <?php foreach ($modelos as $m): ?>
                    <tr>
                        <td class="adm-td-title"><?= e($m['nome']) ?></td>
                        <td data-label="Tipo"><?= $m['tipo'] === 'lombada' ? 'Lombada' : 'Patrimonial' ?></td>
                        <td data-label="Etiqueta"><?= $mm($m['etiqueta_largura']) ?> × <?= $mm($m['etiqueta_altura']) ?> mm</td>
                        <td data-label="Folha"><?= (int)$m['colunas'] ?> × <?= (int)$m['linhas'] ?> em <?= $mm($m['pagina_largura']) ?> × <?= $mm($m['pagina_altura']) ?> mm</td>
                        <td data-label="Situação"><?= (int)$m['ativo'] ? '<span class="adm-status adm-tone--green">Ativo</span>' : '<span class="adm-status adm-tone--muted">Desativado</span>' ?></td>
                        <td class="adm-actions-col"><div class="adm-actions-inline">
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?id=' . (int)$m['id']) ?>"><?= icon('pencil') ?> Editar</a>
                            <form method="post" action="<?= e($base) ?>" data-confirm="<?= (int)$m['ativo'] ? 'Desativar este modelo?' : 'Reativar este modelo?' ?>">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="acao" value="<?= (int)$m['ativo'] ? 'desativar' : 'ativar' ?>">
                                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= (int)$m['ativo'] ? icon('archive') . ' Desativar' : icon('archive-restore') . ' Reativar' ?></button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_page_end(FISICO_JS);
