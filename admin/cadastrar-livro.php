<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';

$modo = 'cadastrar';
$livroForm = null;
$erros = [];
$valores = ['idioma' => 'Português', 'status_validacao' => 'aguardando_conferencia'];
$simulado = false;

// Sem JavaScript, o formulário é enviado ao servidor, que valida os dados (nada é gravado nesta etapa).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $valores = array_map(fn ($x) => is_string($x) ? trim($x) : $x, $_POST);
    $valores['autores'] = array_values(array_filter((array)($_POST['autores'] ?? []), 'is_string'));
    $erros = acervo_validar_livro($valores);
    $simulado = !$erros;
}

admin_page_start('acervo', 'Cadastrar livro', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Cadastrar livro']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Cadastrar novo livro</h1>
        <p>Adicione uma nova obra ao catálogo da biblioteca.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('acervo')) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
    </div>
</section>

<?php if ($simulado): ?>
    <div class="adm-notice adm-notice--green" role="status">
        <?= icon('circle-check') ?>
        <div>
            <strong>Os dados estão corretos.</strong>
            <p>Simulação: o livro não foi gravado, pois o banco de dados ainda não está conectado.</p>
        </div>
    </div>
<?php endif; ?>

<p class="adm-form-legend">Campos com <span class="adm-required">*</span> são obrigatórios.</p>

<?php
include __DIR__ . '/includes/form-livro.php';
include __DIR__ . '/includes/modal-exemplares.php';
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261027']);
