<?php
/**
 * Cadastro manual de exemplar físico, a partir da página do livro (?livro=ID) ou pesquisando o livro.
 * O código BDP é gerado pelo servidor só depois da confirmação de que o exemplar está em mãos.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;

$usuario = fisico_iniciar('exemplares.gerenciar');
$livroId = (int)($_POST['livro_id'] ?? $_GET['livro'] ?? 0);
$q = mb_substr(admin_param($_GET, 'q'), 0, 100);
$erro = null;
$fx = [];
$base = admin_url('cadastrar-exemplar');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        $fx = fisico_dados_exemplar($_POST);
        try {
            if (admin_param($_POST, 'confirmar') !== '1') {
                throw new AcervoException('Marque a confirmação de que o exemplar está fisicamente com você.');
            }
            $quantidade = (int)$fx['quantidade'];
            $dados = $fx + ['localizacao_id' => null];
            $dados['localizacao_id'] = fisico_resolver_local($fx, $usuario['id']);
            $criados = exemplares_servico()->cadastrar($livroId, $dados, $usuario['id'], $quantidade);
            $codigos = array_column($criados, 'codigo');
            admin_flash('sucesso', count($criados) === 1 ? 'Exemplar cadastrado: ' . $codigos[0] . '.' : count($criados) . ' exemplares cadastrados: ' . implode(', ', $codigos) . '.',
                'Escreva ou cole a etiqueta com o código no livro. Os códigos não se repetem e não serão reaproveitados.');
            admin_redirecionar($base . '?livro=' . $livroId . '&criados=' . implode(',', array_column($criados, 'id')));
        } catch (AcervoException $e) {
            $erro = $e->getMessage();
        } catch (PDOException $e) {
            $erro = fisico_erro_tecnico($e, 'cadastro de exemplar');
        }
    }
    $livro = $livroId ? livros_cadastro_resumo($livroId) : null;
    $busca = !$livro && $q !== '' ? inventario_repo()->pesquisarLivros(0, $q) : null;
    $locais = localizacoes_servico()->ativas();
    $criadosIds = array_filter(array_map('intval', explode(',', admin_param($_GET, 'criados'))));
    $criados = $criadosIds ? exemplares_repo()->paraEtiquetas($criadosIds) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir o cadastro de exemplar', fisico_erro_tecnico($e, 'cadastro de exemplar'));
    exit;
}

/** Livro com autores, contagens e dados da planilha (só referência). */
function livros_cadastro_resumo(int $id): ?array
{
    $l = db_query('SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.arquivado_em,
                          (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ", ") FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                   FROM livros l WHERE l.id = ?', [$id])->fetch();
    if (!$l) {
        return null;
    }
    return $l + exemplares_repo()->contagemLivro($id) + ['planilha' => exemplares_repo()->dadosPlanilha($id)];
}

admin_page_start('exemplares', 'Cadastrar exemplar', 'Exemplares', 'Unidades físicas do acervo', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], ['Cadastrar exemplar']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Cadastrar exemplar físico</h1>
        <p>Registre uma unidade real do livro que está com você. O código BDP é gerado na confirmação.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($livro): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$livro['id']) ?>"><?= icon('arrow-left') ?> Voltar ao livro</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($erro): ?><?= admin_aviso('erro', 'O exemplar não foi cadastrado.', $erro) ?><?php endif; ?>

<?php if ($criados): ?>
    <section class="adm-card adm-panel adm-criados" aria-labelledby="titulo-criados">
        <div class="adm-panel__header"><div><h2 id="titulo-criados">Exemplares cadastrados agora</h2></div></div>
        <ul class="adm-code-list">
            <?php foreach ($criados as $c): ?>
                <li><a class="adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$c['id']) ?>"><?= e($c['codigo_individual']) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php if (auth_pode('etiquetas.gerar')): ?>
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('etiquetas') . '?ids=' . implode(',', array_column($criados, 'id'))) ?>"><?= icon('printer') ?> Imprimir etiqueta<?= count($criados) > 1 ? 's' : '' ?></a>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!$livro): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-busca">
        <div class="adm-panel__header"><div><h2 id="titulo-busca">1. Escolha o livro</h2><p>Pesquise pelo título, autor ou ISBN do registro bibliográfico.</p></div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar livro</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Título, autor ou ISBN..." autofocus autocomplete="off"></label>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Pesquisar</button>
        </form>
        <?php if ($busca !== null): ?>
            <?php if (!$busca['livros']): ?>
                <div class="adm-empty adm-empty--left"><?= icon('book-x') ?><strong>Nenhum livro encontrado.</strong><span>Se o livro ainda não tem registro bibliográfico, cadastre-o primeiro em Acervo.</span></div>
            <?php else: ?>
                <ul class="adm-resultados">
                    <?php foreach ($busca['livros'] as $l): ?>
                        <li>
                            <div><strong><?= e($l['titulo']) ?></strong><small><?= e($l['autores'] ?: 'Autor não informado') ?><?= $l['isbn'] ? ' · ISBN ' . e($l['isbn']) : '' ?> · <?= count($l['exemplares']) ?> exemplar(es) físico(s)</small></div>
                            <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '?livro=' . (int)$l['id']) ?>"><?= icon('plus') ?> Escolher</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($busca['mais']): ?><p class="adm-field__hint">Há mais resultados. Refine a pesquisa.</p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php else: ?>
    <div class="adm-form-layout">
        <div class="adm-form-layout__main">
            <form method="post" class="adm-card adm-panel adm-form-section" data-loading-form>
                <?= csrf_campo() ?>
                <input type="hidden" name="livro_id" value="<?= (int)$livro['id'] ?>">
                <div class="adm-form-section__header"><span class="adm-step">2</span><div><h2>Dados do exemplar</h2><p>O exemplar fica ligado a este livro e recebe um código próprio.</p></div></div>
                <?php if ($livro['arquivado_em'] !== null): ?>
                    <?= admin_aviso('erro', 'Este livro está arquivado.', 'Restaure o cadastro antes de registrar exemplares.') ?>
                <?php else: ?>
                    <?php $livroForm = $livro; include __DIR__ . '/includes/form-exemplar.php'; ?>
                    <label class="adm-check-confirm">
                        <input type="checkbox" name="confirmar" value="1" required>
                        <span>Estou com o(s) exemplar(es) físico(s) deste livro em mãos e quero gerar o(s) código(s) BDP.</span>
                    </label>
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('check') ?> Confirmar cadastro</button>
                <?php endif; ?>
            </form>
        </div>
        <aside class="adm-form-layout__side">
            <section class="adm-card adm-panel adm-form-section">
                <div class="adm-form-section__header"><span class="adm-step">1</span><div><h2>Livro</h2></div></div>
                <dl class="adm-meta adm-meta--stack adm-meta--boxed">
                    <div><dt>Título</dt><dd><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$livro['id']) ?>"><?= e($livro['titulo']) ?></a></dd></div>
                    <div><dt>Autor</dt><dd><?= e($livro['autores'] ?: 'Não informado') ?></dd></div>
                    <div><dt>ISBN (identifica a edição)</dt><dd class="adm-mono"><?= $livro['isbn'] ? e($livro['isbn']) : '—' ?></dd></div>
                    <div><dt>Quantidade na planilha antiga</dt><dd><?= $livro['planilha']['quantidade'] ?? '—' ?> <small class="adm-table__muted">(só referência)</small></dd></div>
                    <div><dt>Exemplares físicos cadastrados</dt><dd><?= (int)$livro['ativos'] ?></dd></div>
                    <div><dt>Disponíveis para empréstimo</dt><dd><?= (int)$livro['disponiveis'] ?></dd></div>
                    <?php if ($livro['planilha']['localizacoes']): ?><div><dt>Localização antiga (planilha)</dt><dd><?= e($livro['planilha']['localizacoes']) ?> <small class="adm-table__muted">(histórica)</small></dd></div><?php endif; ?>
                </dl>
                <a class="adm-link" href="<?= e($base) ?>"><?= icon('search') ?> Escolher outro livro</a>
            </section>
        </aside>
    </div>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
