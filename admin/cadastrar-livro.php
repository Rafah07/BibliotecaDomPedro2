<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/livros.php';

$usuario = auth_exigir('livros.editar');
$modo = 'cadastrar';
$livroForm = null;
$erros = [];
$duplicados = [];
$valores = ['idioma' => 'Português', 'status_validacao' => 'aguardando_conferencia'];
$simulado = false;
$falha = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    [$dados, $erros] = livros_validar($_POST, true);
    $valores = $dados;
    if (!livros_persistem()) {
        // Modo demonstrativo: valida, mas não grava nada.
        $simulado = !$erros;
    } elseif (!$erros) {
        try {
            $dup = livros_repo()->duplicidades($dados);
            // ISBN ou título repetido não bloqueia (o ISBN não é único): mostra os parecidos e pede confirmação.
            $parecidos = $dup['titulo'];
            if ($dup['isbn'] && !in_array((int)$dup['isbn']['id'], array_map('intval', array_column($parecidos, 'id')), true)) {
                array_unshift($parecidos, $dup['isbn'] + ['editora' => null, 'ano_publicacao' => null, 'edicao' => null, 'autores' => 'mesmo ISBN']);
            }
            if ($parecidos && admin_param($_POST, 'confirmar_duplicidade') !== '1') {
                $duplicados = $parecidos;
            } else {
                $r = livros_cadastrar($dados, $usuario);
                admin_flash('sucesso', 'Livro cadastrado com sucesso.', '"' . $dados['titulo'] . '" foi incluído no acervo. Os exemplares físicos serão registrados no inventário.');
                if ($r['cdd']) {
                    admin_flash('aviso', 'CDD não registrada', $r['cdd']);
                }
                admin_redirecionar(admin_param($_POST, 'acao') === 'salvar_novo'
                    ? admin_url('cadastrar-livro')
                    : admin_url('detalhes-livro') . '?id=' . $r['id']);
            }
        } catch (PDOException $e) {
            error_log('[biblioteca] Erro ao cadastrar livro: ' . $e->getMessage());
            $falha = 'Não foi possível gravar o livro. Tente novamente; se o problema continuar, avise o responsável pelo sistema.';
        }
    }
}

admin_page_start('acervo', 'Cadastrar livro', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261114']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Cadastrar livro']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Cadastrar novo livro</h1>
        <p>Adicione um registro bibliográfico ao catálogo da biblioteca. Os exemplares físicos são registrados depois, no inventário.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('acervo')) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($falha): ?><?= admin_aviso('erro', 'O livro não foi salvo.', $falha) ?><?php endif; ?>

<?php if ($simulado): ?>
    <div class="adm-notice adm-notice--green" role="status">
        <?= icon('circle-check') ?>
        <div>
            <strong>Os dados estão corretos.</strong>
            <p>Simulação: o livro não foi gravado, pois o banco de dados ainda não está conectado.</p>
        </div>
    </div>
<?php endif; ?>

<p class="adm-form-legend">Campos com <span class="adm-required">*</span> são obrigatórios. Não preencha ISBN ou CDD sem ter certeza: campos vazios podem ser completados depois.</p>

<?php
include __DIR__ . '/includes/form-livro.php';
if (!livros_persistem()) {
    include __DIR__ . '/includes/modal-exemplares.php';
}
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261030']);
