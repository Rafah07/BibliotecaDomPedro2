<?php
/**
 * Devoluções: a bibliotecária digita o código BDP escrito na etiqueta do exemplar devolvido
 * (ex.: BDP-000001) e pressiona Enter. O sistema localiza o empréstimo ativo e abre a
 * confirmação. A busca do código é exata (maiúsculas/minúsculas e espaços nas pontas não
 * importam). Também pesquisa os empréstimos em aberto por aluno ou título e mostra as últimas
 * devoluções.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Support\CodigoBdp;

emprestimos_iniciar();
$hoje = emprestimos_hoje();
$q = mb_substr(admin_param($_GET, 'q'), 0, 100);
$base = admin_url('devolucoes');
$aviso = null;
try {
    $codigo = EmprestimoRepository::codigoExemplar($q);
    if (!$codigo && CodigoBdp::pareceCodigo($q)) {
        $aviso = CodigoBdp::mensagemInvalido($q);
    } elseif ($codigo && auth_pode('emprestimos.registrar')) {
        $ex = emprestimos_repo()->exemplarPorCodigo($codigo);
        if ($ex && $ex['emprestimo_aberto']) {
            admin_redirecionar(admin_url('devolver-emprestimo') . '?id=' . (int)$ex['emprestimo_aberto']);
        }
        $aviso = $ex ? 'O exemplar ' . $ex['codigo_individual'] . ' não tem empréstimo em aberto (situação: ' . mb_strtolower(emprestimos_situacao_exemplar($ex['situacao'])) . ').' : 'O código ' . $codigo . ' não existe. Confira se ele foi digitado exatamente como está na etiqueta.';
    }
    $abertos = $q !== '' && !$aviso ? emprestimos_repo()->listar(['situacao' => 'ativos', 'q' => $q], $hoje, 1, 50) : null;
    $recentes = emprestimos_repo()->listar(['situacao' => 'devolvidos', 'ordem' => 'devolucao', 'dir' => 'desc'], $hoje, 1, 10);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar as devoluções', emprestimos_erro_tecnico($e, 'devoluções'));
    exit;
}

admin_page_start('devolucoes', 'Devoluções', 'Devoluções', 'Circulação do acervo', ['assets/css/acervo.css?v=20261113', 'assets/css/emprestimos.css?v=20261113']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Devoluções']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Registrar devolução</h1>
        <p>Digite o código BDP escrito na etiqueta do livro (ex.: BDP-000123) e pressione Enter: o sistema localiza o empréstimo ativo e abre a confirmação. Também é possível pesquisar pelo aluno ou pelo título.</p>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-card adm-panel">
    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
        <label class="adm-field-search adm-toolbar__q">
            <?= icon('keyboard') ?>
            <span class="adm-visually-hidden">Código BDP do exemplar, aluno ou título</span>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Informe o código BDP (ex.: BDP-000123), aluno, matrícula ou título..." autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus>
        </label>
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar exemplar</span></button>
    </form>
    <?php if ($aviso): ?><?= admin_aviso('aviso', 'Nada para devolver com este código.', $aviso) ?><?php endif; ?>
    <?php if ($abertos !== null): ?>
        <h3 class="adm-subtitle"><?= icon('book-open-check') ?> Empréstimos em aberto encontrados</h3>
        <?= emprestimos_tabela($abertos['itens'], $hoje, ['sem_devolucao'], 'Nenhum empréstimo em aberto encontrado', 'Confira o código, o nome ou a matrícula.') ?>
    <?php endif; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-recentes">
    <div class="adm-panel__header">
        <div><h2 id="titulo-recentes">Últimas devoluções</h2><p>As 10 devoluções registradas mais recentemente.</p></div>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros(admin_url('emprestimos'), ['situacao' => 'devolvidos'])) ?>"><?= icon('history') ?> Ver todas</a>
    </div>
    <?= emprestimos_tabela($recentes['itens'], $hoje, [], 'Nenhuma devolução registrada') ?>
</section>

<?php
admin_page_end();
