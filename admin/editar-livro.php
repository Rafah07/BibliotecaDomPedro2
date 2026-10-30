<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/livros.php';

$usuario = auth_exigir('livros.editar');
$id = (int)($_GET['id'] ?? 0);
$modo = 'editar';
$erros = [];
$simulado = false;
$falha = null;
$versao = null;
$livroForm = null;
$valores = [];

if (livros_persistem()) {
    $registro = livros_repo()->buscar($id);
    if ($registro && $registro['arquivado_em'] === null) {
        $contagem = db_query("SELECT COUNT(*) AS total, COALESCE(SUM(situacao = 'disponivel'), 0) AS disponiveis
                              FROM exemplares WHERE livro_id = ? AND situacao NOT IN ('baixado', 'extraviado')", [$id])->fetch();
        $livroForm = [
            'id' => $id,
            'titulo' => $registro['titulo'],
            'autor_principal' => $registro['autores'][0] ?? 'Autor não informado',
            'origem_cadastro' => $registro['origem_cadastro'],
            'criado_em' => substr($registro['criado_em'], 0, 10),
            'total_exemplares' => (int)$contagem['total'],
            'disponiveis' => (int)$contagem['disponiveis'],
            'cor' => acervo_mysql_cor($id),
        ];
        $valores = $registro;
        $valores['categoria'] = $registro['categorias'][0] ?? '';
        $versao = $registro['atualizado_em'];
    }
} else {
    $livroForm = acervo_livro($id);
    if ($livroForm) {
        $valores = $livroForm;
        $valores['autores'] = $livroForm['autores_nomes'];
        $valores['categoria'] = $livroForm['categorias'][0] ?? '';
    }
}

if ($livroForm && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    [$dados, $erros] = livros_validar($_POST, false);
    $valores = $dados + $valores;
    if (!livros_persistem()) {
        $simulado = !$erros;
    } elseif (!$erros) {
        try {
            $dup = livros_repo()->duplicidades($dados, $id);
            if ($dup['isbn']) {
                $erros['isbn'] = 'Este ISBN já está cadastrado no livro "' . $dup['isbn']['titulo'] . '" (nº ' . (int)$dup['isbn']['id'] . ').';
            } else {
                $alteracoes = livros_repo()->atualizar($id, $dados, admin_param($_POST, 'versao'));
                if ($alteracoes) {
                    $detalhes = [];
                    foreach ($alteracoes as $campo => [$antes, $depois]) {
                        $detalhes[$campo] = ['antes' => $antes, 'depois' => $depois];
                    }
                    registrar_acao('livro.editar', 'livro', $id, $detalhes);
                    $nomes = array_map(fn ($c) => LIVROS_CAMPOS[$c] ?? $c, array_keys($alteracoes));
                    admin_flash('sucesso', 'Alterações salvas.', 'Campos alterados: ' . implode(', ', $nomes) . '.');
                } else {
                    admin_flash('aviso', 'Nenhuma alteração', 'Os dados enviados são iguais aos que já estavam cadastrados.');
                }
                admin_redirecionar(admin_url('detalhes-livro') . '?id=' . $id);
            }
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                error_log('[biblioteca] Erro ao editar livro ' . $id . ': ' . $e->getMessage());
                $falha = ($e->errorInfo[1] ?? 0) === 1062
                    ? 'Já existe um livro ativo com este ISBN.'
                    : 'Não foi possível gravar as alterações. Tente novamente; se o problema continuar, avise o responsável pelo sistema.';
            } else {
                $falha = $e->getMessage();
            }
        }
    }
}

admin_page_start('acervo', 'Editar livro', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Editar livro']]) ?>

<?php if (!$livroForm): ?>
    <section class="adm-card adm-wip">
        <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon('book-x') ?></span>
        <h1>Livro não encontrado</h1>
        <p>O cadastro solicitado não existe ou foi arquivado.</p>
        <div class="adm-wip__actions">
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('acervo')) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
        </div>
    </section>
<?php else: ?>
    <section class="adm-page-head">
        <div>
            <h1>Editar informações</h1>
            <p><?= e($livroForm['titulo']) ?> · <?= e($livroForm['autor_principal']) ?></p>
        </div>
        <div class="adm-page-head__actions">
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('detalhes-livro') . '?id=' . $livroForm['id']) ?>"><?= icon('eye') ?> Ver detalhes</a>
        </div>
    </section>

    <?php if ($falha): ?><?= admin_aviso('erro', 'As alterações não foram salvas.', $falha) ?><?php endif; ?>

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
admin_page_end(['assets/js/acervo.js?v=20261030']);
