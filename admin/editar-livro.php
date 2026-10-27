<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo.php';

$livroForm = acervo_livro((int)($_GET['id'] ?? 0));
$modo = 'editar';
$erros = [];
$simulado = false;

if ($livroForm) {
    $valores = $livroForm;
    $valores['autores'] = $livroForm['autores_nomes'];
    $valores['categoria'] = $livroForm['categorias'][0] ?? '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $valores = array_map(fn ($x) => is_string($x) ? trim($x) : $x, $_POST) + $valores;
        $valores['autores'] = array_values(array_filter((array)($_POST['autores'] ?? []), 'is_string'));
        $erros = acervo_validar_livro($valores);
        $simulado = !$erros;
    }
}

admin_page_start('acervo', 'Editar livro', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Editar livro']]) ?>

<?php if (!$livroForm): ?>
    <section class="adm-card adm-wip">
        <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon('book-x') ?></span>
        <h1>Livro não encontrado</h1>
        <p>O cadastro solicitado não existe ou foi arquivado.</p>
        <div class="adm-wip__actions">
            <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('acervo')) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
        </div>
    </section>
<?php else: ?>
    <section class="adm-page-head">
        <div>
            <h1>Editar informações</h1>
            <p><?= htmlspecialchars($livroForm['titulo']) ?> · <?= htmlspecialchars($livroForm['autor_principal']) ?></p>
        </div>
        <div class="adm-page-head__actions">
            <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('detalhes-livro') . '?id=' . $livroForm['id']) ?>"><?= icon('eye') ?> Ver detalhes</a>
        </div>
    </section>

    <?php if ($simulado): ?>
        <div class="adm-notice adm-notice--green" role="status">
            <?= icon('circle-check') ?>
            <div>
                <strong>Os dados estão corretos.</strong>
                <p>Simulação: as alterações não foram gravadas, pois o banco de dados ainda não está conectado.</p>
            </div>
        </div>
    <?php endif; ?>

    <p class="adm-form-legend">Campos com <span class="adm-required">*</span> são obrigatórios. Alterações no título, no ISBN ou na situação pedem confirmação.</p>

    <?php include __DIR__ . '/includes/form-livro.php'; ?>
<?php endif; ?>

<?php
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261027']);
