<?php
/**
 * Cadastro (sem id) e edição (com id) de uma categoria de livros. Exige categorias.gerenciar e o MySQL.
 * O nome não pode repetir outra categoria (sem diferenciar maiúsculas, acentos e pontuação).
 * Renomear muda o texto em todo o sistema: os livros guardam só o id da categoria.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/categorias.php';

use Biblioteca\Services\AcervoException;

$usuario = auth_exigir('categorias.gerenciar');
if (!categorias_mysql()) {
    admin_pagina_erro('Disponível só com o banco de dados', 'No modo demonstrativo as categorias são fictícias e nada é gravado. Configure o banco de dados para cadastrar e editar categorias.', 'tags');
    exit;
}
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0) ?: null;
$erro = null;
try {
    $atual = $id ? categorias_servico()->buscar($id) : null;
    $historico = $atual ? categorias_servico()->historico($id) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a categoria', categorias_erro_tecnico($e, 'categoria'));
    exit;
}
if ($id && !$atual) {
    http_response_code(404);
    admin_pagina_erro('Categoria não encontrada', 'Ela pode ter sido informada errado no endereço.', 'tags');
    exit;
}
$dados = ['nome' => (string)($atual['nome'] ?? ''), 'descricao' => (string)($atual['descricao'] ?? '')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $dados = ['nome' => mb_substr(admin_param($_POST, 'nome'), 0, 200), 'descricao' => mb_substr(admin_param($_POST, 'descricao'), 0, 400)];
    try {
        categorias_servico()->salvar($dados, $usuario['id'], $id);
        admin_flash('sucesso', $id ? 'Categoria atualizada.' : 'Categoria cadastrada.', $id
            ? 'O novo texto já aparece nos livros, nos filtros e no site público.'
            : 'Ela já pode ser escolhida no cadastro e na edição de livros.');
        admin_redirecionar(admin_url('categorias'));
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    } catch (PDOException $e) {
        $erro = categorias_erro_tecnico($e, 'cadastro de categoria');
    }
}

$titulo = $id ? 'Editar categoria' : 'Cadastrar categoria';
$rotulos = ['categoria.cadastrar' => 'Cadastrada', 'categoria.editar' => 'Editada', 'categoria.desativar' => 'Desativada', 'categoria.reativar' => 'Reativada'];
admin_page_start('categorias', $titulo, 'Categorias', 'Organização literária do acervo', ['assets/css/acervo.css?v=20261102e9', 'assets/css/emprestimos.css?v=20261102e9']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Categorias', admin_url('categorias')], [$titulo]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($titulo) ?></h1>
        <p>Não é possível cadastrar duas categorias com o mesmo nome: maiúsculas, acentos e pontuação não diferenciam (“Ficção” e “ficcao” são a mesma).</p>
    </div>
    <div class="adm-page-head__actions"><a class="adm-button adm-button--ghost" href="<?= e(admin_url('categorias')) ?>"><?= icon('arrow-left') ?> Voltar</a></div>
</section>

<?php if ($erro): ?><?= admin_aviso('erro', 'A categoria não foi salva.', $erro) ?><?php endif; ?>
<?php if ($atual && $atual['arquivado_em']): ?><?= admin_aviso('aviso', 'Esta categoria está desativada.', 'Ela não aparece no cadastro de livros nem no site. Para usá-la de novo, reative-a na lista de categorias.') ?><?php endif; ?>

<form method="post" class="adm-card adm-panel adm-form-section" data-loading-form>
    <?= csrf_campo() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
    <div class="adm-form-grid">
        <label class="adm-field adm-field--full"><span class="adm-field__label">Nome da categoria <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input" type="text" name="nome" maxlength="80" required value="<?= e($dados['nome']) ?>" placeholder="Ex.: Literatura africana" autofocus autocomplete="off">
            <span class="adm-field__hint">Até 80 caracteres: letras, números, espaços e os sinais , . ; : ( ) / &amp; + ' -</span></label>
        <label class="adm-field adm-field--full"><span class="adm-field__label">Descrição <span class="adm-optional">(opcional)</span></span>
            <textarea class="adm-textarea" name="descricao" rows="3" maxlength="255" placeholder="Explique quais livros entram nesta categoria"><?= e($dados['descricao']) ?></textarea></label>
    </div>
    <?php if ($atual && $atual['livros'] > 0): ?>
        <?= admin_aviso('info', 'Esta categoria está em ' . $atual['livros'] . ' livro(s) ativo(s).', 'Ao renomear, todos eles passam a mostrar o novo nome, no painel e no site. Nenhum livro é alterado.') ?>
    <?php endif; ?>
    <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar categoria</button>
</form>

<?php if ($historico): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-historico-cat">
        <div class="adm-panel__header"><div><h2 id="titulo-historico-cat">Histórico da categoria</h2><p>Quem cadastrou, editou, desativou ou reativou.</p></div></div>
        <div class="adm-table-wrap adm-table-wrap--inset">
            <table class="adm-table adm-table--compact adm-table--cards">
                <thead><tr><th scope="col">Data</th><th scope="col">Ação</th><th scope="col">Detalhes</th><th scope="col">Responsável</th></tr></thead>
                <tbody>
                    <?php foreach ($historico as $h): ?>
                        <?php $det = json_decode((string)$h['detalhes'], true) ?: []; ?>
                        <tr>
                            <td><?= admin_data_hora($h['criado_em']) ?></td>
                            <td data-label="Ação"><?= e($rotulos[$h['acao']] ?? $h['acao']) ?></td>
                            <td data-label="Detalhes" class="adm-td-wrap"><?= e(implode(' · ', array_filter([
                                !empty($det['campos']) ? 'Campos: ' . $det['campos'] : '',
                                !empty($det['nome_anterior']) ? 'Nome anterior: ' . $det['nome_anterior'] : '',
                            ])) ?: '—') ?></td>
                            <td data-label="Responsável"><?= e($h['responsavel'] ?? 'Sistema') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php
admin_page_end();
