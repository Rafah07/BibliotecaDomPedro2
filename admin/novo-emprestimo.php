<?php
/**
 * Registrar empréstimo em três passos, sem JavaScript obrigatório:
 *   1. pesquisar e escolher o aluno (matrícula, nome ou e-mail);
 *   2. pesquisar e escolher um exemplar DISPONÍVEL (título, autor, ISBN ou código);
 *   3. conferir o resumo, o prazo e confirmar.
 * O aluno nunca é escolhido automaticamente, mesmo com um único resultado: nomes podem se repetir.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/emprestimos.php';

$usuario = emprestimos_iniciar('emprestimos.registrar');
$repo = emprestimos_repo();
$servico = emprestimos_servico();
$hoje = emprestimos_hoje();
$podePrazo = auth_pode('emprestimos.prazo');
$base = admin_url('novo-emprestimo');

$alunoId = (int)($_POST['aluno'] ?? $_GET['aluno'] ?? 0);
$exemplarId = (int)($_POST['exemplar'] ?? $_GET['exemplar'] ?? 0);
$qa = mb_substr(admin_param($_GET, 'qa'), 0, 100);
$qe = mb_substr(admin_param($_GET, 'qe'), 0, 100);
$prevista = $servico->prevista();
$observacao = '';
$erro = null;

try {
    $aluno = $alunoId > 0 ? $repo->aluno($alunoId) : null;
    $exemplar = $exemplarId > 0 ? $repo->exemplar($exemplarId) : null;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        $observacao = mb_substr(admin_param($_POST, 'observacao'), 0, 500);
        if ($podePrazo && admin_param($_POST, 'data_prevista') !== '') {
            $prevista = admin_param($_POST, 'data_prevista');
        }
        if (!$aluno || !$exemplar) {
            $erro = 'Escolha o aluno e o exemplar antes de confirmar.';
        } elseif (admin_param($_POST, 'confirmar') !== '1') {
            $erro = 'Marque a confirmação para registrar o empréstimo.';
        } else {
            try {
                $r = $servico->registrar($aluno['id'], $exemplar['id'], $prevista, $usuario['id'], $observacao);
                admin_flash('sucesso', 'Empréstimo registrado.', $r['titulo'] . ' (' . $r['codigo'] . ') emprestado a ' . $aluno['nome'] . '. Devolução prevista para ' . emprestimos_data($r['data_prevista']) . '.');
                admin_redirecionar(admin_url('emprestimo') . '?id=' . $r['id']);
            } catch (Biblioteca\Services\CirculacaoException $e) {
                $erro = $e->getMessage();
                $exemplar = $repo->exemplar($exemplar['id']);
                $aluno = $repo->aluno($aluno['id']);
            }
        }
    }

    $alunos = !$aluno && $qa !== '' ? $repo->pesquisarAlunos($qa) : null;
    $exemplares = $aluno && !$exemplar && $qe !== '' ? $repo->pesquisarExemplares($qe) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível registrar o empréstimo', emprestimos_erro_tecnico($e, 'novo empréstimo'));
    exit;
}

$aptoAluno = $aluno && (int)$aluno['ativo'] === 1;
$aptoExemplar = $exemplar && $exemplar['situacao'] === 'disponivel' && $exemplar['arquivado_em'] === null && !$exemplar['emprestimo_aberto'];
$passo = !$aluno ? 1 : (!$exemplar ? 2 : 3);
$estado = ['aluno' => $aluno['id'] ?? null, 'exemplar' => $exemplar['id'] ?? null];

admin_page_start('emprestimos', 'Novo empréstimo', 'Empréstimos', 'Circulação do acervo', ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Empréstimos', admin_url('emprestimos')], ['Novo empréstimo']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Novo empréstimo</h1>
        <p>Escolha o aluno, depois o exemplar físico, confira o resumo e confirme.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('emprestimos')) ?>"><?= icon('arrow-left') ?> Voltar aos empréstimos</a>
    </div>
</section>

<ol class="adm-passos" aria-label="Etapas">
    <?php foreach ([1 => 'Aluno', 2 => 'Exemplar', 3 => 'Confirmação'] as $n => $rotulo): ?>
        <li class="<?= $n < $passo ? 'is-done' : ($n === $passo ? 'is-current' : '') ?>"<?= $n === $passo ? ' aria-current="step"' : '' ?>><span><?= $n < $passo ? icon('check') : $n ?></span> <?= $rotulo ?></li>
    <?php endforeach; ?>
</ol>

<?php if ($erro): ?><?= admin_aviso('erro', 'O empréstimo não foi registrado.', $erro) ?><?php endif; ?>

<!-- Passo 1: aluno -->
<section class="adm-card adm-panel adm-form-section" aria-labelledby="titulo-aluno">
    <div class="adm-form-section__header">
        <span class="adm-step">1</span>
        <div>
            <h2 id="titulo-aluno">Aluno</h2>
            <p>Pesquise pela matrícula, pelo nome ou pelo e-mail institucional. Prefira a matrícula: nomes podem se repetir.</p>
        </div>
    </div>
    <?php if ($aluno): ?>
        <div class="adm-escolha<?= $aptoAluno ? '' : ' is-blocked' ?>">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $aptoAluno ? 'blue' : 'red' ?>"><?= icon('graduation-cap') ?></span>
            <div>
                <strong><?= e($aluno['nome']) ?></strong>
                <small><?= e(emprestimos_aluno_id($aluno)) ?><?= $aluno['turma'] ? ' · Turma ' . e($aluno['turma']) : '' ?></small>
                <?php if (!$aptoAluno): ?><span class="adm-status adm-tone--red">Cadastro inativo: não pode receber empréstimos</span><?php endif; ?>
            </div>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['exemplar' => $estado['exemplar']])) ?>"><?= icon('undo-2') ?> Trocar aluno</a>
        </div>
    <?php else: ?>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <?php if ($estado['exemplar']): ?><input type="hidden" name="exemplar" value="<?= (int)$estado['exemplar'] ?>"><?php endif; ?>
            <label class="adm-field-search adm-toolbar__q">
                <?= icon('search') ?>
                <span class="adm-visually-hidden">Pesquisar aluno</span>
                <input type="search" name="qa" value="<?= e($qa) ?>" placeholder="Matrícula, nome ou e-mail do aluno..." autocomplete="off" autofocus>
            </label>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        </form>
        <?php if ($alunos !== null): ?>
            <?php if (!$alunos['itens']): ?>
                <div class="adm-notice adm-notice--amber" role="status">
                    <?= icon('triangle-alert') ?>
                    <div><strong>Nenhum aluno encontrado.</strong>
                        <p>Confira a matrícula ou o nome. O empréstimo só pode ser feito para alunos cadastrados; o cadastro é feito pela importação de alunos<?= auth_pode('alunos.importar') ? ' (Gerenciamento → Importar Alunos)' : ', com a bibliotecária responsável' ?>.</p></div>
                </div>
            <?php else: ?>
                <?php if (count($alunos['itens']) > 1): ?>
                    <p class="adm-field__hint"><?= count($alunos['itens']) ?> alunos encontrados. Confira a matrícula e a turma antes de selecionar.</p>
                <?php endif; ?>
                <ul class="adm-resultados">
                    <?php foreach ($alunos['itens'] as $a): ?>
                        <li class="<?= (int)$a['ativo'] ? '' : 'is-blocked' ?>">
                            <div>
                                <strong><?= e($a['nome']) ?></strong>
                                <small><?= e(emprestimos_aluno_id($a)) ?><?= $a['turma'] ? ' · Turma ' . e($a['turma']) : '' ?></small>
                                <?php if ((int)$a['em_aberto'] > 0): ?><small><?= (int)$a['em_aberto'] ?> empréstimo(s) em aberto</small><?php endif; ?>
                            </div>
                            <?php if ((int)$a['ativo']): ?>
                                <a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url_filtros($base, ['aluno' => $a['id'], 'exemplar' => $estado['exemplar']])) ?>"><?= icon('check') ?> Selecionar</a>
                            <?php else: ?>
                                <span class="adm-status adm-tone--red">Cadastro inativo</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($alunos['mais']): ?><p class="adm-field__hint">Há mais resultados. Refine a pesquisa (use a matrícula, por exemplo).</p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<!-- Passo 2: exemplar -->
<section class="adm-card adm-panel adm-form-section<?= $passo < 2 ? ' is-pending' : '' ?>" aria-labelledby="titulo-exemplar">
    <div class="adm-form-section__header">
        <span class="adm-step">2</span>
        <div>
            <h2 id="titulo-exemplar">Livro e exemplar</h2>
            <p>Pesquise por título, autor, ISBN ou código do exemplar (BDP-000123). Só aparecem para escolha os exemplares físicos disponíveis.</p>
        </div>
    </div>
    <?php if ($exemplar): ?>
        <div class="adm-escolha<?= $aptoExemplar ? '' : ' is-blocked' ?>">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $aptoExemplar ? 'blue' : 'red' ?>"><?= icon('book-copy') ?></span>
            <div>
                <strong><?= e($exemplar['titulo']) ?></strong>
                <small><span class="adm-mono"><?= e($exemplar['codigo_individual']) ?></span><?= $exemplar['autores'] ? ' · ' . e($exemplar['autores']) : '' ?><?= $exemplar['setor'] ? ' · ' . e($exemplar['setor'] . ', estante ' . $exemplar['estante'] . ', prateleira ' . $exemplar['prateleira']) : '' ?></small>
                <?php if (!$aptoExemplar): ?>
                    <span class="adm-status adm-tone--red"><?= e($exemplar['arquivado_em'] !== null ? 'O livro está arquivado.' : (Biblioteca\Services\EmprestimoService::MOTIVOS_INDISPONIVEL[$exemplar['situacao']] ?? 'Exemplar indisponível.')) ?></span>
                <?php endif; ?>
            </div>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url_filtros($base, ['aluno' => $estado['aluno']])) ?>"><?= icon('undo-2') ?> Trocar exemplar</a>
        </div>
    <?php elseif ($aluno): ?>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <input type="hidden" name="aluno" value="<?= (int)$aluno['id'] ?>">
            <label class="adm-field-search adm-toolbar__q">
                <?= icon('search') ?>
                <span class="adm-visually-hidden">Pesquisar livro ou exemplar</span>
                <input type="search" name="qe" value="<?= e($qe) ?>" placeholder="Título, autor, ISBN ou código do exemplar..." autocomplete="off" autofocus>
            </label>
            <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        </form>
        <?php if ($exemplares !== null): ?>
            <?php if ($exemplares['disponiveis']): ?>
                <h3 class="adm-subtitle"><?= icon('circle-check') ?> Exemplares disponíveis</h3>
                <ul class="adm-resultados">
                    <?php foreach ($exemplares['disponiveis'] as $ex): ?>
                        <li>
                            <div>
                                <strong><?= e($ex['titulo']) ?></strong>
                                <small><span class="adm-mono"><?= e($ex['codigo_individual']) ?></span><?= $ex['autores'] ? ' · ' . e($ex['autores']) : '' ?><?= $ex['isbn'] ? ' · ISBN ' . e($ex['isbn']) : '' ?><?= $ex['setor'] ? ' · ' . e($ex['setor'] . ', estante ' . $ex['estante']) : '' ?></small>
                            </div>
                            <a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url_filtros($base, ['aluno' => $aluno['id'], 'exemplar' => $ex['id']])) ?>"><?= icon('check') ?> Selecionar</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($exemplares['indisponiveis']): ?>
                <h3 class="adm-subtitle"><?= icon('triangle-alert') ?> Encontrados, mas sem exemplar disponível</h3>
                <ul class="adm-resultados">
                    <?php foreach ($exemplares['indisponiveis'] as $l): ?>
                        <li class="is-blocked">
                            <div>
                                <strong><?= e($l['titulo']) ?></strong>
                                <small><?= $l['autores'] ? e($l['autores']) : 'Autor não informado' ?><?= $l['isbn'] ? ' · ISBN ' . e($l['isbn']) : '' ?></small>
                                <?php if ($l['total_exemplares'] === 0): ?>
                                    <small class="adm-text-warn">Nenhum exemplar físico identificado. <?= $l['origem_cadastro'] === 'planilha' ? 'Este livro veio das planilhas antigas e ainda não passou pelo inventário físico.' : 'Cadastre o exemplar no inventário antes de emprestar.' ?></small>
                                <?php else: ?>
                                    <small class="adm-text-warn">
                                        <?php if ($l['exemplar_procurado']): ?>
                                            <?= e($l['exemplar_procurado']['codigo_individual']) ?>: <?= e(Biblioteca\Services\EmprestimoService::MOTIVOS_INDISPONIVEL[$l['exemplar_procurado']['situacao']] ?? 'indisponível') ?>
                                        <?php else: ?>
                                            <?= e(implode(', ', array_map(fn ($s, $n) => $n . ' ' . mb_strtolower(emprestimos_situacao_exemplar($s)), array_keys($l['situacoes']), $l['situacoes']))) ?>. Nenhum disponível agora.
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!$exemplares['disponiveis'] && !$exemplares['indisponiveis']): ?>
                <div class="adm-notice adm-notice--amber" role="status">
                    <?= icon('triangle-alert') ?>
                    <div><strong>Nenhum livro ou exemplar encontrado.</strong><p>Confira o título, o ISBN ou o código do exemplar.</p></div>
                </div>
            <?php endif; ?>
            <?php if ($exemplares['mais']): ?><p class="adm-field__hint">Há mais livros com esse termo. Refine a pesquisa.</p><?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <p class="adm-table__muted">Escolha o aluno primeiro.</p>
    <?php endif; ?>
</section>

<!-- Passo 3: resumo e confirmação -->
<section class="adm-card adm-panel adm-form-section<?= $passo < 3 ? ' is-pending' : '' ?>" aria-labelledby="titulo-confirmar">
    <div class="adm-form-section__header">
        <span class="adm-step">3</span>
        <div>
            <h2 id="titulo-confirmar">Resumo e confirmação</h2>
            <p><?= e(emprestimos_regras_texto()) ?></p>
        </div>
    </div>
    <?php if ($passo === 3 && $aptoAluno && $aptoExemplar): ?>
        <form method="post" action="<?= e(admin_url_filtros($base, $estado)) ?>" class="adm-confirm-form" data-loading-form>
            <?= csrf_campo() ?>
            <input type="hidden" name="aluno" value="<?= (int)$aluno['id'] ?>">
            <input type="hidden" name="exemplar" value="<?= (int)$exemplar['id'] ?>">
            <dl class="adm-meta adm-meta--stack adm-meta--boxed">
                <div><dt>Aluno</dt><dd><?= e($aluno['nome']) ?><br><small class="adm-table__muted"><?= e(emprestimos_aluno_id($aluno)) ?></small></dd></div>
                <div><dt>Livro</dt><dd><?= e($exemplar['titulo']) ?></dd></div>
                <div><dt>Exemplar</dt><dd class="adm-mono"><?= e($exemplar['codigo_individual']) ?></dd></div>
                <div><dt>Data do empréstimo</dt><dd><?= e(emprestimos_regras()->agora()->format('d/m/Y H:i')) ?></dd></div>
                <div><dt>Responsável</dt><dd><?= e($usuario['nome']) ?></dd></div>
            </dl>
            <label class="adm-field">
                <span class="adm-field__label">Devolução prevista</span>
                <?php if ($podePrazo): ?>
                    <input class="adm-input" type="date" name="data_prevista" value="<?= e($prevista) ?>" min="<?= e($hoje) ?>" required>
                    <span class="adm-field__hint">Padrão: <?= e(emprestimos_data($servico->prevista())) ?>. Altere só quando necessário.</span>
                <?php else: ?>
                    <input class="adm-input" type="text" value="<?= e(emprestimos_data($prevista)) ?>" readonly>
                    <span class="adm-field__hint">Prazo padrão da biblioteca. Para outra data, peça à bibliotecária.</span>
                <?php endif; ?>
            </label>
            <label class="adm-field">
                <span class="adm-field__label">Observação <span class="adm-optional">(opcional)</span></span>
                <textarea class="adm-textarea" name="observacao" rows="2" maxlength="500" placeholder="Ex.: capa com marca de uso"><?= e($observacao) ?></textarea>
            </label>
            <label class="adm-check-confirm">
                <input type="checkbox" name="confirmar" value="1" required>
                <span>Conferi o aluno, o livro e o código do exemplar.</span>
            </label>
            <button type="submit" class="adm-button adm-button--primary adm-button--block"><?= icon('book-open-check') ?> Confirmar empréstimo</button>
        </form>
    <?php elseif ($passo === 3): ?>
        <?= admin_aviso('erro', 'Não é possível emprestar com a seleção atual.', !$aptoAluno ? 'O cadastro do aluno está inativo.' : 'O exemplar escolhido não está disponível. Escolha outro exemplar.') ?>
    <?php else: ?>
        <p class="adm-table__muted">O resumo aparece depois de escolher o aluno e o exemplar.</p>
    <?php endif; ?>
</section>

<?php
admin_page_end();
