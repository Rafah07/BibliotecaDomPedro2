<?php
/**
 * Circulação no painel: empréstimos, devoluções, renovações, atrasos e históricos.
 *
 * - Só funciona com o banco configurado (no modo demonstrativo não há alunos nem exemplares reais).
 * - As permissões (emprestimos.ver / .registrar / .prazo) são conferidas no servidor em cada página.
 * - Gravações: Biblioteca\Services\EmprestimoService (transações e bloqueio do exemplar).
 * - Privacidade: nome, matrícula e e-mail dos alunos só aparecem nestas páginas do painel.
 *   Nada disso vai para URLs públicas, logs do servidor ou registros de ação.
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php'; // acervo_breadcrumb

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Support\RegrasCirculacao;

const EMPRESTIMOS_SITUACOES = [
    'ativos' => ['label' => 'Ativos', 'icone' => 'book-open-check'],
    'atrasados' => ['label' => 'Atrasados', 'icone' => 'clock-alert'],
    'devolvidos' => ['label' => 'Devolvidos', 'icone' => 'rotate-ccw'],
    'todos' => ['label' => 'Histórico completo', 'icone' => 'history'],
];

const EMPRESTIMOS_MOVIMENTOS = [
    'cadastro' => ['label' => 'Cadastro do exemplar', 'icone' => 'book-plus', 'tom' => 'indigo'],
    'emprestimo' => ['label' => 'Empréstimo', 'icone' => 'book-open-check', 'tom' => 'blue'],
    'devolucao' => ['label' => 'Devolução', 'icone' => 'rotate-ccw', 'tom' => 'green'],
    'renovacao' => ['label' => 'Renovação', 'icone' => 'refresh-cw', 'tom' => 'teal'],
    'manutencao' => ['label' => 'Manutenção', 'icone' => 'wrench', 'tom' => 'amber'],
    'localizacao' => ['label' => 'Localização', 'icone' => 'map-pin', 'tom' => 'blue'],
    'inventario' => ['label' => 'Inventário', 'icone' => 'clipboard-check', 'tom' => 'amber'],
    'extravio' => ['label' => 'Extravio', 'icone' => 'package-x', 'tom' => 'red'],
    'baixa' => ['label' => 'Baixa', 'icone' => 'archive', 'tom' => 'red'],
    'conservacao' => ['label' => 'Conservação', 'icone' => 'wrench', 'tom' => 'blue'],
    'situacao' => ['label' => 'Situação', 'icone' => 'refresh-cw', 'tom' => 'blue'],
];

function emprestimos_regras(): RegrasCirculacao
{
    static $r = null;
    return $r ??= RegrasCirculacao::doAmbiente('env');
}

function emprestimos_repo(): EmprestimoRepository
{
    static $r = null;
    return $r ??= new EmprestimoRepository(db());
}

function emprestimos_servico(): EmprestimoService
{
    static $s = null;
    return $s ??= new EmprestimoService(db(), emprestimos_regras(), (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
}

function emprestimos_hoje(): string
{
    return emprestimos_regras()->hoje();
}

/** Início das páginas: permissão conferida no servidor e banco obrigatório. */
function emprestimos_iniciar(string $permissao = 'emprestimos.ver'): array
{
    $usuario = auth_exigir($permissao);
    if (db_modo() !== 'mysql') {
        admin_pagina_erro('Disponível só com o banco de dados', 'Os empréstimos usam os alunos e os exemplares reais cadastrados no MySQL. Configure o banco para usar a circulação.', 'book-open-check');
        exit;
    }
    try {
        db();
    } catch (DbIndisponivel $e) {
        http_response_code(503);
        admin_pagina_erro('Banco de dados indisponível', $e->getMessage());
        exit;
    }
    return $usuario;
}

/**
 * Registra no log do servidor só o tipo do erro (a mensagem do MySQL pode conter valores
 * digitados, como matrícula ou nome) e devolve uma mensagem genérica para a tela.
 */
function emprestimos_erro_tecnico(Throwable $e, string $contexto): string
{
    $codigo = $e instanceof PDOException ? ' ' . ($e->errorInfo[1] ?? $e->getCode()) : '';
    error_log('[biblioteca] Erro em ' . $contexto . ': ' . get_class($e) . $codigo . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    return 'Não foi possível concluir a operação. Nada foi gravado. Tente novamente; se o problema continuar, verifique se as migrações foram aplicadas (php database/migrar.php).';
}

function emprestimos_filtros(array $get, string $situacaoPadrao = 'ativos'): array
{
    $data = fn (string $nome) => preg_match('/^\d{4}-\d{2}-\d{2}$/', admin_param($get, $nome)) && strtotime(admin_param($get, $nome)) ? admin_param($get, $nome) : '';
    $porPagina = (int)admin_param($get, 'por_pagina', '25');
    $situacao = admin_param($get, 'situacao', $situacaoPadrao);
    return [
        'q' => mb_substr(admin_param($get, 'q'), 0, 100),
        'situacao' => isset(EMPRESTIMOS_SITUACOES[$situacao]) ? $situacao : $situacaoPadrao,
        'de' => $data('de'),
        'ate' => $data('ate'),
        'ordem' => in_array(admin_param($get, 'ordem'), EmprestimoRepository::ORDENS, true) ? admin_param($get, 'ordem') : '',
        'dir' => in_array(admin_param($get, 'dir'), ['asc', 'desc'], true) ? admin_param($get, 'dir') : '',
        'por_pagina' => in_array($porPagina, [25, 50, 100], true) ? (string)$porPagina : '25',
    ];
}

function emprestimos_data(?string $valor, bool $hora = false): string
{
    return admin_data_hora($valor, $hora);
}

/** Situação do empréstimo: [rótulo, tom]. */
function emprestimos_situacao(array $m, string $hoje): array
{
    $atraso = (int)$m['dias_atraso'];
    if ($m['data_devolucao'] !== null) {
        return $atraso > 0 ? ['Devolvido com ' . $atraso . ' dia' . ($atraso > 1 ? 's' : '') . ' de atraso', 'amber'] : ['Devolvido', 'green'];
    }
    if ($m['data_prevista'] < $hoje) {
        return ['Atrasado há ' . $atraso . ' dia' . ($atraso > 1 ? 's' : ''), 'red'];
    }
    if ($m['data_prevista'] === $hoje) {
        return ['Vence hoje', 'amber'];
    }
    return ['Em dia', 'blue'];
}

function emprestimos_badge(array $m, string $hoje): string
{
    [$rotulo, $tom] = emprestimos_situacao($m, $hoje);
    return '<span class="adm-status adm-tone--' . $tom . '">' . e($rotulo) . '</span>';
}

/** Identificação do aluno: matrícula e, quando houver, o e-mail institucional. */
function emprestimos_aluno_id(array $a): string
{
    $partes = ['Matrícula ' . $a['matricula']];
    if (!empty($a['email'])) {
        $partes[] = $a['email'];
    }
    return implode(' · ', $partes);
}

function emprestimos_situacao_exemplar(string $situacao): string
{
    return ACERVO_SITUACOES[$situacao]['label'] ?? $situacao;
}

/** Texto das regras em uso, para mostrar nas telas (deixa claro o que ainda é provisório). */
function emprestimos_regras_texto(): string
{
    $r = emprestimos_regras();
    $t = 'Prazo padrão: ' . $r->prazoDias . ' dia(s)' . ($r->prazoProvisorio ? ' (valor provisório: a escola ainda não definiu; ajuste EMPRESTIMO_PRAZO_DIAS no .env)' : '') . '. ';
    $t .= 'Renovação: +' . $r->renovacaoDias . ' dia(s), ';
    $t .= $r->maxRenovacoes === null ? 'sem limite definido pela escola (limite técnico de ' . RegrasCirculacao::RENOVACOES_LIMITE_TECNICO . ')' : 'até ' . $r->maxRenovacoes . ' renovação(ões)';
    $t .= $r->renovarAtrasado ? '.' : '; empréstimos atrasados não podem ser renovados.';
    return $t;
}

/**
 * Tabela de empréstimos usada na listagem, nos atrasos, nas devoluções e nos históricos.
 * $opcoes: 'sem_aluno' (histórico do aluno), 'sem_devolucao' (só em aberto), 'com_atraso' (coluna de dias de atraso).
 */
function emprestimos_tabela(array $itens, string $hoje, array $opcoes = [], string $vazio = 'Nenhum empréstimo encontrado', string $vazioDica = ''): string
{
    $registrar = auth_pode('emprestimos.registrar');
    $comAluno = !in_array('sem_aluno', $opcoes, true);
    $comDevolucao = !in_array('sem_devolucao', $opcoes, true);
    $comAtraso = in_array('com_atraso', $opcoes, true);
    $colunas = 6 + ($comAluno ? 1 : 0) + ($comDevolucao ? 1 : 0) + ($comAtraso ? 1 : 0);
    ob_start();
    ?>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards adm-table--emprestimos">
            <thead>
                <tr>
                    <?php if ($comAluno): ?><th scope="col">Aluno</th><?php endif; ?>
                    <th scope="col">Livro</th>
                    <th scope="col">Exemplar</th>
                    <th scope="col">Retirada</th>
                    <th scope="col">Devolução prevista</th>
                    <?php if ($comDevolucao): ?><th scope="col">Devolvido em</th><?php endif; ?>
                    <?php if ($comAtraso): ?><th scope="col" class="adm-num">Dias de atraso</th><?php endif; ?>
                    <th scope="col">Situação</th>
                    <th scope="col" class="adm-actions-col">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $m): ?>
                    <tr>
                        <?php if ($comAluno): ?>
                            <td class="adm-td-title">
                                <a class="adm-book-link" href="<?= e(admin_url('historico-aluno') . '?id=' . (int)$m['aluno_id']) ?>"><?= e($m['aluno_nome']) ?></a>
                                <small><?= e(emprestimos_aluno_id($m)) ?><?= $m['turma'] ? ' · ' . e($m['turma']) : '' ?><?= !(int)$m['aluno_ativo'] ? ' · cadastro inativo' : '' ?></small>
                            </td>
                        <?php endif; ?>
                        <td data-label="Livro" class="adm-td-wrap"><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$m['livro_id']) ?>"><?= e($m['titulo']) ?></a></td>
                        <td data-label="Exemplar" class="adm-mono"><a class="adm-link" href="<?= e(admin_url('historico-exemplar') . '?id=' . (int)$m['exemplar_id']) ?>"><?= e($m['codigo_individual']) ?></a></td>
                        <td data-label="Retirada" class="adm-table__muted"><?= emprestimos_data($m['data_emprestimo'], true) ?></td>
                        <td data-label="Devolução prevista"><?= emprestimos_data($m['data_prevista']) ?><?= (int)$m['renovacoes'] > 0 ? ' <small class="adm-table__muted">(' . (int)$m['renovacoes'] . ' renov.)</small>' : '' ?></td>
                        <?php if ($comDevolucao): ?><td data-label="Devolvido em" class="adm-table__muted"><?= emprestimos_data($m['data_devolucao'], true) ?></td><?php endif; ?>
                        <?php if ($comAtraso): ?><td data-label="Dias de atraso" class="adm-num"><strong class="adm-atraso"><?= (int)$m['dias_atraso'] ?></strong></td><?php endif; ?>
                        <td data-label="Situação"><?= emprestimos_badge($m, $hoje) ?></td>
                        <td class="adm-actions-col">
                            <span class="adm-row-actions">
                                <a class="adm-icon-button adm-icon-button--sm" href="<?= e(admin_url('emprestimo') . '?id=' . (int)$m['id']) ?>" aria-label="Ver o empréstimo nº <?= (int)$m['id'] ?>" title="Ver detalhes"><?= icon('eye') ?></a>
                                <?php if ($registrar && $m['data_devolucao'] === null): ?>
                                    <a class="adm-icon-button adm-icon-button--sm" href="<?= e(admin_url('renovar-emprestimo') . '?id=' . (int)$m['id']) ?>" aria-label="Renovar o empréstimo nº <?= (int)$m['id'] ?>" title="Renovar"><?= icon('refresh-cw') ?></a>
                                    <a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url('devolver-emprestimo') . '?id=' . (int)$m['id']) ?>"><?= icon('rotate-ccw') ?> Registrar devolução</a>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$itens): ?>
                    <tr data-empty><td colspan="<?= $colunas ?>">
                        <div class="adm-empty">
                            <?= icon('book-open-check') ?>
                            <strong><?= e($vazio) ?></strong>
                            <?php if ($vazioDica !== ''): ?><span><?= e($vazioDica) ?></span><?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Bloco com os dados de um empréstimo (usado nas confirmações de devolução e renovação). */
function emprestimos_resumo_html(array $m, string $hoje): string
{
    ob_start();
    ?>
    <dl class="adm-meta adm-meta--stack adm-meta--boxed">
        <div><dt>Aluno</dt><dd><?= e($m['aluno_nome']) ?><br><small class="adm-table__muted"><?= e(emprestimos_aluno_id($m)) ?><?= $m['turma'] ? ' · ' . e($m['turma']) : '' ?></small></dd></div>
        <div><dt>Livro</dt><dd><?= e($m['titulo']) ?></dd></div>
        <div><dt>Exemplar</dt><dd class="adm-mono"><?= e($m['codigo_individual']) ?></dd></div>
        <div><dt>Retirada</dt><dd><?= emprestimos_data($m['data_emprestimo'], true) ?><?= $m['emprestado_por_nome'] ? '<br><small class="adm-table__muted">por ' . e($m['emprestado_por_nome']) . '</small>' : '' ?></dd></div>
        <div><dt>Devolução prevista</dt><dd><?= emprestimos_data($m['data_prevista']) ?></dd></div>
        <div><dt>Renovações</dt><dd><?= (int)$m['renovacoes'] ?></dd></div>
        <?php if ($m['data_devolucao'] !== null): ?>
            <div><dt>Devolvido em</dt><dd><?= emprestimos_data($m['data_devolucao'], true) ?><?= $m['recebido_por_nome'] ? '<br><small class="adm-table__muted">recebido por ' . e($m['recebido_por_nome']) . '</small>' : '' ?></dd></div>
        <?php endif; ?>
        <div><dt>Situação</dt><dd><?= emprestimos_badge($m, $hoje) ?></dd></div>
    </dl>
    <?php
    return (string)ob_get_clean();
}

/** Abas de situação (links), com as contagens. */
function emprestimos_abas(string $base, array $filtros, array $resumo): string
{
    $html = '<nav class="adm-tabs adm-tabs--links" aria-label="Situação dos empréstimos">';
    foreach (EMPRESTIMOS_SITUACOES as $valor => $s) {
        $url = admin_url_filtros($base, $filtros, ['situacao' => $valor, 'pagina' => null, 'ordem' => null, 'dir' => null]);
        $atual = $filtros['situacao'] === $valor;
        $html .= '<a href="' . e($url) . '"' . ($atual ? ' aria-current="page"' : '') . '>' . icon($s['icone']) . ' ' . e($s['label'])
            . ' <span class="adm-tabs__count">' . number_format($resumo[$valor] ?? 0, 0, ',', '.') . '</span></a>';
    }
    return $html . '</nav>';
}
