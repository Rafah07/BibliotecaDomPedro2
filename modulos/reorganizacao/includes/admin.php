<?php
/**
 * Base das páginas administrativas da Central de Reorganização (Grupo 2).
 * Usa o layout, o login e o CSRF do painel; só administradores autenticados chegam aqui.
 *
 * Navegação: Reorganização do Acervo → [Visão geral | Lotes | Equipes e participantes |
 * Solicitações e pendências | Histórico | Configurações]. Cada lote tem o próprio ambiente de
 * trabalho (admin/lote.php) com abas internas.
 */
require_once __DIR__ . '/../../../admin/includes/layout.php'; // exige login (auth_exigir)
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/comum.php';
require_once __DIR__ . '/cdd.php';

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;

/** Abas da Central (id => rótulo, ícone, endereço). */
const REORG_ABAS = [
    'inicio' => ['Visão geral', 'layout-dashboard', 'admin/'],
    'lotes' => ['Lotes', 'layers', 'admin/lotes.php'],
    'participantes' => ['Equipes e participantes', 'users', 'admin/participantes.php'],
    'solicitacoes' => ['Solicitações e pendências', 'inbox', 'admin/solicitacoes.php'],
    'historico' => ['Histórico', 'history', 'admin/historico.php'],
    'configuracoes' => ['Configurações', 'settings', 'admin/configuracoes.php'],
];

function reorg_admin_usuario(): array
{
    return auth_exigir('painel.ver');
}

/** Módulo desligado ou sem as migrações: página explicativa (o resto do sistema continua normal). */
function reorg_admin_exigir_modulo(bool $json = false): void
{
    if (reorg_modulo_ativo()) {
        return;
    }
    $msg = 'O módulo de reorganização do acervo está desativado (config.php ou REORGANIZACAO_MODULO_ATIVO) ou as migrações 220 e 230 não foram aplicadas (php database/migrar.php).';
    if ($json) {
        api_resposta(['ok' => false, 'erro' => $msg], 404);
    }
    http_response_code(404);
    admin_pagina_erro('Reorganização do acervo indisponível', $msg, 'archive');
    exit;
}

/**
 * Abre a página: faixa do período, abas da Central e caminho de navegação.
 * $caminho: lista de [rótulo, endereço|null] depois de "Reorganização do Acervo".
 */
function reorg_admin_inicio(string $aba, string $titulo, array $caminho = []): void
{
    admin_page_start('reorganizacao', $titulo, 'Reorganização do Acervo', 'Módulo temporário', ['assets/css/acervo.css?v=20261115mob', 'assets/css/emprestimos.css?v=20261115mob', 'assets/css/preparo.css?v=20261126']);
    echo '<link rel="stylesheet" href="' . e(reorg_asset('reorg.css')) . '">';
    $usuario = auth_usuario();
    $naoLidas = $usuario ? reorg()->naoLidasAdmin((int)$usuario['id']) : 0;
    $pendentes = (int)db_query("SELECT COUNT(*) FROM reorg_solicitacoes WHERE status = 'pendente'")->fetchColumn()
        + (int)db_query("SELECT COUNT(*) FROM reorg_pendencias WHERE status <> 'resolvida'")->fetchColumn();
    $periodo = reorg()->periodoAtivo();
    echo '<div class="reorg-adm" data-reorg-admin data-api="' . e(reorg_url('admin/api.php')) . '" data-csrf="' . e(csrf_token()) . '">';
    echo '<div class="reorg-faixa ' . ($periodo ? 'is-ativo' : 'is-inativo') . '"><span>' . icon($periodo ? 'play' : 'pause') . ' '
        . ($periodo ? 'Reorganização em andamento: <strong>' . e($periodo['nome']) . '</strong> · o botão “Equipe de organização” aparece na tela de login.'
            : 'Reorganização parada: o botão da equipe está escondido. <a href="' . e(reorg_url('admin/configuracoes.php#periodo')) . '">Iniciar período</a>')
        . '</span><a href="' . e(reorg_url('admin/notificacoes.php')) . '" class="reorg-sino" title="Notificações" aria-label="Notificações (' . $naoLidas . ' não lidas)">' . icon('bell')
        . '<span data-reorg-naolidas' . ($naoLidas ? '' : ' hidden') . '>' . $naoLidas . '</span></a></div>';
    echo '<nav class="adm-tabs adm-tabs--links adm-tabs--solta reorg-abas" aria-label="Seções da Reorganização do Acervo">';
    foreach (REORG_ABAS as $id => [$rotulo, $icone, $endereco]) {
        $extra = $id === 'solicitacoes' && $pendentes ? ' <span class="adm-tabs__count">' . $pendentes . '</span>' : '';
        echo '<a class="adm-tab' . ($aba === $id ? ' is-active' : '') . '" href="' . e(reorg_url($endereco)) . '"' . ($aba === $id ? ' aria-current="page"' : '') . '>' . icon($icone) . ' ' . e($rotulo) . $extra . '</a>';
    }
    echo '</nav>';
    echo reorg_caminho(array_merge([[REORG_ABAS[$aba][0] ?? 'Reorganização do Acervo', $caminho ? reorg_url(REORG_ABAS[$aba][2] ?? 'admin/') : null]], $caminho));
    echo admin_flash_html();
}

/** Caminho de navegação: Reorganização do Acervo › Lotes › Lote 001 › Revisão. */
function reorg_caminho(array $itens): string
{
    $h = '<nav class="reorg-caminho" aria-label="Você está em"><ol><li><a href="' . e(reorg_url('admin/')) . '">Reorganização do Acervo</a></li>';
    $n = count($itens);
    foreach ($itens as $i => [$rotulo, $url]) {
        $ultimo = $i === $n - 1;
        $h .= '<li' . ($ultimo ? ' aria-current="page"' : '') . '>' . ($url && !$ultimo ? '<a href="' . e($url) . '">' . e($rotulo) . '</a>' : e($rotulo)) . '</li>';
    }
    return $h . '</ol></nav>';
}

function reorg_admin_fim(): void
{
    echo '</div><script src="' . e(reorg_asset('admin.js')) . '" defer></script>';
    admin_page_end();
}

/** Executa a ação do formulário com CSRF; erro de regra vira aviso (nada foi gravado). */
function reorg_admin_acao(callable $fn, string $sucesso, ?string $destino = null): void
{
    admin_exigir_csrf();
    try {
        $msg = $fn();
        if ($msg !== false) {
            admin_flash('sucesso', is_string($msg) && $msg !== '' ? $msg : $sucesso);
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Não foi possível concluir', $e->getMessage());
    }
    admin_redirecionar($destino ?? ($_SERVER['REQUEST_URI'] ?? reorg_url('admin/')));
}

function reorg_status_lote(array $l): string
{
    return match ($l['status']) {
        'em_andamento' => reorg_status_badge('Em andamento', 'blue'),
        'pausado' => reorg_status_badge('Pausado', 'amber'),
        'concluido' => reorg_status_badge('Concluído', 'green'),
        default => reorg_status_badge($l['status'], 'muted'),
    };
}

function reorg_estado_badge(string $estado): string
{
    [$r, $t] = Reorganizacao::ESTADOS_CONEXAO[$estado] ?? [$estado, 'muted'];
    return reorg_status_badge($r, $t);
}


/** Rótulos curtos das etapas. */
const REORG_ETAPAS = [
    'grupo1' => ['G1', 'Levantamento'],
    'grupo2' => ['G2', 'Revisão'],
    'grupo3' => ['G3', 'Carimbação'],
    'grupo4' => ['G4', 'Conferência e organização'],
];

function reorg_etapas_html(array $etapas): string
{
    if (!$etapas) {
        return '<span class="adm-table__muted">—</span>';
    }
    $h = '';
    foreach ($etapas as $eq => $n) {
        $h .= '<span class="reorg-etapa-chip" title="' . e(REORG_ETAPAS[$eq][1] . ': ' . $n . ' exemplar(es) esperando') . '">' . e(REORG_ETAPAS[$eq][0]) . ' <b>' . (int)$n . '</b></span>';
    }
    return $h;
}

/** Administradores ativos (para responsável/autorizados). */
function reorg_administradores(): array
{
    return db_query('SELECT id, nome FROM administradores WHERE ativo = 1 ORDER BY nome')->fetchAll();
}

function reorg_categorias(): array
{
    return db_query('SELECT id, nome FROM categorias WHERE arquivado_em IS NULL ORDER BY nome')->fetchAll();
}


/** Destaques do registro na revisão: campos vazios, ISBN/CDD não encontrados, conservação ruim, obra existente. */
function reorg_destaques_registro(array $g): string
{
    $h = '';
    if (!(int)$g['completo'] && in_array($g['status'], ['rascunho', 'correcao'], true)) {
        $h .= reorg_status_badge('Incompleto', 'amber');
    }
    if ((int)$g['isbn_nao_encontrado'] && !$g['isbn']) {
        $h .= reorg_status_badge('ISBN não encontrado', 'amber');
    }
    if ((int)$g['cdd_nao_encontrada'] && !$g['cdd']) {
        $h .= reorg_status_badge('CDD não encontrada', 'amber');
    }
    if (str_contains((string)$g['estados'], 'ruim')) {
        $h .= reorg_status_badge('Conservação ruim', 'red');
    }
    if ($g['livro_sugerido_id']) {
        $h .= reorg_status_badge('Obra existente', 'indigo');
    }
    if ($g['status'] === 'correcao' && $g['correcao_motivo']) {
        $h .= '<small class="reorg-motivo" title="' . e($g['correcao_motivo']) . '">Devolvido: ' . e(mb_strimwidth((string)$g['correcao_motivo'], 0, 60, '…')) . '</small>';
    }
    return $h;
}

/** Tabela de registros do levantamento (abas G1 e G2 do lote). $volta: endereço da aba/filtro atual. */
function reorg_tabela_registros(array $regs, string $volta, bool $selecao): string
{
    ob_start(); ?>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact reorg-tabela-registros">
        <thead><tr><?php if ($selecao): ?><th><input type="checkbox" data-reorg-todos aria-label="Marcar todos"></th><?php endif; ?><th>Nº</th><th>Título / autor</th><th>Editora · edição</th><th>ISBN</th><th>CDD</th><th class="reorg-num">Qtd.</th><th>Conservação</th><th>Situação</th><th>Por</th></tr></thead>
        <tbody>
        <?php if (!$regs): ?><tr><td colspan="<?= $selecao ? 10 : 9 ?>" class="adm-table__muted">Nenhum registro neste filtro.</td></tr><?php endif; ?>
        <?php foreach ($regs as $g): [$st, $tom] = Biblioteca\Reorganizacao\Levantamento::STATUS[$g['status']]; $url = reorg_url('admin/registro.php?id=' . $g['id'] . '&volta=' . rawurlencode($volta)); ?>
            <tr>
                <?php if ($selecao): ?><td><input type="checkbox" name="ids[]" value="<?= (int)$g['id'] ?>" aria-label="Marcar registro <?= (int)$g['numero'] ?>"></td><?php endif; ?>
                <td><a href="<?= e($url) ?>" class="adm-table__strong"><?= (int)$g['numero'] ?></a></td>
                <td><a href="<?= e($url) ?>"><?= e($g['titulo'] ?: '(sem título)') ?></a><br><small><?= e($g['autores'] ?: '—') ?></small><div class="reorg-destaques"><?= reorg_destaques_registro($g) ?></div></td>
                <td><?= e(($g['editora'] ?: '—') . ($g['edicao'] ? ' · ' . $g['edicao'] : '')) ?></td>
                <td class="adm-mono"><?= $g['isbn'] ? e($g['isbn']) : ((int)$g['isbn_nao_encontrado'] ? '<small>não encontrado</small>' : '<span class="reorg-vazio-campo">vazio</span>') ?></td>
                <td class="adm-mono"><?= $g['cdd'] ? e($g['cdd']) : ((int)$g['cdd_nao_encontrada'] ? '<small>não encontrada</small>' : '<span class="reorg-vazio-campo">vazio</span>') ?></td>
                <td class="reorg-num"><?= (int)$g['quantidade'] ?></td>
                <td><small><?= e(implode(', ', array_map(fn ($v) => explode(' — ', Reorganizacao::CONSERVACAO[$v] ?? '?')[0], array_filter(explode(',', (string)$g['estados']))))) ?></small></td>
                <td><?= reorg_status_badge($st, $tom) ?><?= $g['editando_ate'] && strtotime($g['editando_ate']) > time() ? '<br><small>Em edição: ' . e($g['editando_nome'] ?? $g['editando_admin_nome'] ?? '') . '</small>' : '' ?></td>
                <td><small><?= e($g['criado_nome'] ?? '—') ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php return (string)ob_get_clean();
}

/** Tabela de pendências com resolução no próprio lugar. $campos: campos ocultos do formulário (aba, filtros). */
function reorg_tabela_pendencias(array $lista, bool $mostrarLote, array $campos = []): string
{
    $ocultos = '';
    foreach ($campos as $k => $v) {
        $ocultos .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    }
    ob_start(); ?>
    <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--compact">
        <thead><tr><th>Nº</th><?php if ($mostrarLote): ?><th>Lote</th><?php endif; ?><th>Tipo</th><th>Descrição</th><th>Exemplar / registro</th><th>Informado por</th><th>Situação</th><th>Ação</th></tr></thead>
        <tbody>
        <?php if (!$lista): ?><tr><td colspan="<?= $mostrarLote ? 8 : 7 ?>" class="adm-table__muted">Nenhuma pendência.</td></tr><?php endif; ?>
        <?php foreach ($lista as $p): ?>
            <tr><td><?= (int)$p['id'] ?></td>
                <?php if ($mostrarLote): ?><td><a href="<?= e(reorg_url('admin/lote.php?id=' . $p['lote_id'] . '&aba=pendencias')) ?>"><?= e($p['lote_nome']) ?></a></td><?php endif; ?>
                <td><?= e(Reorganizacao::TIPOS_PENDENCIA[$p['tipo']] ?? $p['tipo']) ?><br><small><?= e(Reorganizacao::EQUIPES[$p['grupo']] ?? $p['grupo']) ?></small></td>
                <td><?= e($p['descricao']) ?><?= $p['resolucao'] ? '<br><small><strong>Resolução:</strong> ' . e($p['resolucao']) . '</small>' : '' ?></td>
                <td class="adm-mono"><?= e($p['codigo_individual'] ?? '') ?><?= $p['registro_numero'] ? ' <a href="' . e(reorg_url('admin/registro.php?id=' . $p['registro_id'])) . '">reg. ' . (int)$p['registro_numero'] . '</a>' : '' ?></td>
                <td><small><?= e($p['nome'] ?? $p['admin_nome'] ?? '') ?><br><?= e(admin_data_hora($p['criado_em'])) ?></small></td>
                <td><?= reorg_status_badge(['aberta' => 'Aberta', 'em_analise' => 'Em análise', 'resolvida' => 'Resolvida'][$p['status']] ?? $p['status'], ['aberta' => 'red', 'em_analise' => 'amber', 'resolvida' => 'green'][$p['status']] ?? 'muted') ?>
                    <?= $p['resolvida_por_nome'] ? '<br><small>' . e($p['resolvida_por_nome']) . '</small>' : '' ?></td>
                <td><form method="post" class="reorg-mini-form"><?= csrf_campo() ?><?= $ocultos ?><input type="hidden" name="acao" value="pendencia_status"><input type="hidden" name="pendencia_id" value="<?= (int)$p['id'] ?>">
                    <?php if ($p['status'] !== 'resolvida'): ?>
                        <input class="adm-input" name="resolucao" placeholder="Como foi resolvido" maxlength="1000" aria-label="Resolução da pendência <?= (int)$p['id'] ?>">
                        <button class="adm-button adm-button--sm adm-button--primary" name="status" value="resolvida">Resolver</button>
                        <?php if ($p['status'] === 'aberta'): ?><button class="adm-button adm-button--sm" name="status" value="em_analise">Em análise</button><?php endif; ?>
                    <?php else: ?><button class="adm-button adm-button--sm" name="status" value="aberta">Reabrir</button><?php endif; ?>
                </form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php return (string)ob_get_clean();
}
