<?php
/**
 * Alunos: consulta dos cadastros com pesquisa (nome, matrícula, e-mail; com ou sem acentos),
 * filtros, ordenação e paginação no servidor. Exige alunos.ver (dados pessoais).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/alunos.php';

use Biblioteca\Services\AlunoImportService;

alunos_iniciar('alunos.ver');
$base = admin_url('alunos');
$hoje = emprestimos_hoje();
$filtros = alunos_filtros($_GET);
$podeEditar = auth_pode('alunos.editar');
try {
    $resumo = alunos_repo()->resumo($hoje);
    $turmas = alunos_repo()->turmas();
    $resultado = alunos_repo()->listar($filtros, $hoje, max(1, (int)admin_param($_GET, 'pagina', '1')), (int)$filtros['por_pagina']);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível carregar os alunos', alunos_erro_tecnico($e, 'listagem de alunos'));
    exit;
}
$temFiltro = $filtros['q'] !== '' || $filtros['turma'] !== '' || $filtros['turno'] !== '' || $filtros['situacao'] !== 'ativos' || $filtros['circulacao'] !== '';
$num = fn (int $n) => number_format($n, 0, ',', '.');

admin_page_start('alunos', 'Alunos', 'Alunos', 'Cadastro dos alunos da biblioteca', ALUNOS_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Gerenciamento'], ['Alunos']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Alunos</h1>
        <p>Consulte e corrija o cadastro dos alunos sem importar a planilha de novo. A matrícula identifica cada aluno; nenhum cadastro é excluído.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if (auth_pode('alunos.importar')): ?><a class="adm-button adm-button--ghost" href="<?= e(admin_url('importar-alunos')) ?>"><?= icon('file-up') ?> Importar Alunos</a><?php endif; ?>
        <?php if ($podeEditar): ?><a class="adm-button adm-button--primary" href="<?= e(admin_url('aluno-editar')) ?>"><?= icon('user-plus') ?> Cadastrar aluno</a><?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>

<section class="adm-stats" aria-label="Resumo dos alunos">
    <?php foreach ([
        ['Alunos ativos', $resumo['ativos'], 'de ' . $num($resumo['total']) . ' cadastrados', 'graduation-cap', 'blue', ['situacao' => null]],
        ['Com livro emprestado', $resumo['com_emprestimo'], 'Empréstimos em aberto', 'book-open-check', 'indigo', ['circulacao' => 'com_emprestimo', 'situacao' => 'todos']],
        ['Com atraso', $resumo['com_atraso'], 'Pelo menos um livro atrasado', 'clock-alert', $resumo['com_atraso'] ? 'red' : 'green', ['circulacao' => 'com_atraso', 'situacao' => 'todos']],
        ['Inativos', $resumo['inativos'], 'Não fazem novos empréstimos', 'user-x', 'muted', ['situacao' => 'inativos']],
    ] as [$titulo, $valor, $desc, $ic, $tom, $link]): ?>
        <a class="adm-card adm-stat adm-stat--compact adm-stat--action" href="<?= e(admin_url_filtros($base, [], $link)) ?>">
            <div class="adm-stat__top"><p class="adm-stat__title"><?= e($titulo) ?></p><span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($ic) ?></span></div>
            <p class="adm-stat__value"><?= $num((int)$valor) ?></p>
            <p class="adm-stat__desc"><?= e($desc) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-alunos">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-alunos">Alunos cadastrados</h2>
            <p aria-live="polite"><?= $num($resultado['total']) ?> <?= $resultado['total'] === 1 ? 'aluno encontrado' : 'alunos encontrados' ?></p>
        </div>
    </div>

    <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>" data-loading-form>
        <label class="adm-field-search adm-toolbar__q">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar alunos</span>
            <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Nome, matrícula ou e-mail do aluno..." autocomplete="off">
        </label>
        <div class="adm-filters adm-filters--4">
            <label class="adm-filter"><span>Turma</span>
                <select class="adm-select<?= $filtros['turma'] !== '' ? ' has-value' : '' ?>" name="turma">
                    <option value="">Todas</option>
                    <?php foreach ($turmas as $t): ?><option value="<?= e($t) ?>"<?= $filtros['turma'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                    <option value="-"<?= $filtros['turma'] === '-' ? ' selected' : '' ?>>Sem turma</option>
                </select>
            </label>
            <label class="adm-filter"><span>Turno</span>
                <select class="adm-select<?= $filtros['turno'] !== '' ? ' has-value' : '' ?>" name="turno">
                    <option value="">Todos</option>
                    <?php foreach (AlunoImportService::TURNOS as $valor => $rotulo): ?><option value="<?= e($valor) ?>"<?= $filtros['turno'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="adm-filter"><span>Situação</span>
                <select class="adm-select<?= $filtros['situacao'] !== 'ativos' ? ' has-value' : '' ?>" name="situacao">
                    <option value="ativos">Ativos</option>
                    <option value="inativos"<?= $filtros['situacao'] === 'inativos' ? ' selected' : '' ?>>Inativos</option>
                    <option value="todos"<?= $filtros['situacao'] === 'todos' ? ' selected' : '' ?>>Todos</option>
                </select>
            </label>
            <label class="adm-filter"><span>Empréstimos</span>
                <select class="adm-select<?= $filtros['circulacao'] !== '' ? ' has-value' : '' ?>" name="circulacao">
                    <option value="">Todos</option>
                    <option value="com_emprestimo"<?= $filtros['circulacao'] === 'com_emprestimo' ? ' selected' : '' ?>>Com livro emprestado</option>
                    <option value="com_atraso"<?= $filtros['circulacao'] === 'com_atraso' ? ' selected' : '' ?>>Com atraso</option>
                    <option value="sem_emprestimo"<?= $filtros['circulacao'] === 'sem_emprestimo' ? ' selected' : '' ?>>Sem empréstimo em aberto</option>
                </select>
            </label>
        </div>
        <input type="hidden" name="ordem" value="<?= e($filtros['ordem']) ?>">
        <input type="hidden" name="dir" value="<?= e($filtros['dir']) ?>">
        <button type="submit" class="adm-button adm-button--primary adm-button--sm"><?= icon('search') ?> <span>Pesquisar</span></button>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a>
    </form>

    <div class="adm-table-wrap adm-table-wrap--flush" data-loading-area>
        <table class="adm-table adm-table--cards">
            <thead>
                <tr>
                    <?= admin_th_ordenar('Nome', 'nome', $base, $filtros) ?>
                    <?= admin_th_ordenar('Matrícula', 'matricula', $base, $filtros) ?>
                    <th scope="col">E-mail institucional</th>
                    <?= admin_th_ordenar('Turma', 'turma', $base, $filtros) ?>
                    <th scope="col">Empréstimos</th>
                    <th scope="col">Situação</th>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultado['itens'] as $a): ?>
                    <tr<?= $a['atrasados'] ? ' class="adm-row--alerta"' : '' ?>>
                        <td class="adm-td-title"><a class="adm-book-link" href="<?= e(admin_url('aluno') . '?id=' . $a['id']) ?>"><?= e($a['nome']) ?></a></td>
                        <td data-label="Matrícula" class="adm-mono"><?= e($a['matricula']) ?></td>
                        <td data-label="E-mail institucional" class="adm-td-wrap"><?= $a['email'] ? e($a['email']) : '<span class="adm-table__muted">—</span>' ?></td>
                        <td data-label="Turma"><?= $a['turma'] ? e($a['turma']) : '<span class="adm-table__muted">—</span>' ?><?= $a['turno'] ? ' <small class="adm-table__muted">' . e(alunos_turno($a['turno'])) . '</small>' : '' ?></td>
                        <td data-label="Empréstimos">
                            <?php if ($a['abertos']): ?>
                                <?= (int)$a['abertos'] ?> em aberto<?= $a['atrasados'] ? ' · <strong class="adm-atraso">' . (int)$a['atrasados'] . ' atrasado(s)</strong>' : '' ?>
                            <?php else: ?><span class="adm-table__muted">Nenhum em aberto</span><?php endif; ?>
                        </td>
                        <td data-label="Situação"><?= alunos_badge_situacao((bool)$a['ativo']) ?></td>
                        <td class="adm-actions-col">
                            <span class="adm-row-actions">
                                <a class="adm-icon-button adm-icon-button--sm" href="<?= e(admin_url('aluno') . '?id=' . $a['id']) ?>" aria-label="Ver o cadastro de <?= e($a['nome']) ?>" title="Ver detalhes"><?= icon('eye') ?></a>
                                <?php if ($podeEditar): ?>
                                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('aluno-editar') . '?id=' . $a['id']) ?>"><?= icon('pencil') ?> Editar</a>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$resultado['itens']): ?>
                    <tr><td colspan="7"><div class="adm-empty"><?= icon('graduation-cap') ?>
                        <strong><?= $temFiltro ? 'Nenhum aluno encontrado' : 'Nenhum aluno cadastrado ainda' ?></strong>
                        <span><?= $temFiltro ? 'Revise a pesquisa ou os filtros. A pesquisa aceita nome, matrícula ou e-mail, com ou sem acentos.' : 'Importe a planilha da secretaria em Importar Alunos ou cadastre um aluno individualmente.' ?></span>
                        <?php if ($temFiltro): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base) ?>"><?= icon('filter-x') ?> Limpar filtros</a><?php endif; ?>
                    </div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= admin_paginacao($resultado['total'], $resultado['pagina'], (int)$filtros['por_pagina'], $base, $filtros) ?>
</section>

<?php
admin_page_end();
