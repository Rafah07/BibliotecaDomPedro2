<?php
/**
 * Base das páginas administrativas do módulo de reorganização (Grupo 2 e central administrativa).
 * Usa o layout, o login e o CSRF do painel; só administradores autenticados chegam aqui.
 */
require_once __DIR__ . '/../../../admin/includes/layout.php'; // exige login (auth_exigir)
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/comum.php';

use Biblioteca\Reorganizacao\Reorganizacao;

/** Abas da central administrativa. */
const REORG_ABAS = [
    'inicio' => ['Visão geral', 'layout-dashboard', 'admin/'],
    'periodo' => ['Período', 'calendar', 'admin/periodo.php'],
    'participantes' => ['Participantes', 'users', 'admin/participantes.php'],
    'solicitacoes' => ['Solicitações', 'inbox', 'admin/solicitacoes.php'],
    'pendencias' => ['Pendências', 'triangle-alert', 'admin/pendencias.php'],
    'planilhas' => ['Planilhas BDP', 'stamp', 'admin/planilhas.php'],
    'relatorios' => ['Relatórios', 'file-chart-column', 'admin/relatorios.php'],
    'historico' => ['Histórico', 'history', 'admin/historico.php'],
    'configuracoes' => ['Configurações', 'settings', 'admin/configuracoes.php'],
];

function reorg_admin_usuario(): array
{
    return auth_exigir('painel.ver');
}

/** Módulo desligado ou sem a migração 220: página explicativa (o resto do sistema continua normal). */
function reorg_admin_exigir_modulo(bool $json = false): void
{
    if (reorg_modulo_ativo()) {
        return;
    }
    $msg = 'O módulo de reorganização do acervo está desativado (config.php ou REORGANIZACAO_MODULO_ATIVO) ou a migração 220 não foi aplicada.';
    if ($json) {
        api_resposta(['ok' => false, 'erro' => $msg], 404);
    }
    http_response_code(404);
    admin_pagina_erro('Reorganização do acervo indisponível', $msg, 'archive');
    exit;
}

function reorg_admin_inicio(string $aba, string $titulo, string $subtitulo = 'Módulo temporário'): void
{
    admin_page_start('reorganizacao', $titulo, 'Reorganização do Acervo', $subtitulo, ['assets/css/acervo.css?v=20261115mob', 'assets/css/emprestimos.css?v=20261115mob', 'assets/css/preparo.css?v=20261126']);
    echo '<link rel="stylesheet" href="' . e(reorg_asset('reorg.css')) . '">';
    $usuario = auth_usuario();
    $naoLidas = $usuario ? reorg()->naoLidasAdmin((int)$usuario['id']) : 0;
    $pend = (int)db_query("SELECT COUNT(*) FROM reorg_solicitacoes WHERE status = 'pendente'")->fetchColumn();
    $pendencias = (int)db_query("SELECT COUNT(*) FROM reorg_pendencias WHERE status <> 'resolvida'")->fetchColumn();
    $periodo = reorg()->periodoAtivo();
    echo '<div class="reorg-adm" data-reorg-admin data-api="' . e(reorg_url('admin/api.php')) . '" data-csrf="' . e(csrf_token()) . '">';
    echo '<div class="reorg-faixa ' . ($periodo ? 'is-ativo' : 'is-inativo') . '">'
        . icon($periodo ? 'play' : 'pause') . ' '
        . ($periodo ? 'Período ativo: <strong>' . e($periodo['nome']) . '</strong> desde ' . e(admin_data_hora($periodo['inicio'], false)) . ' — o botão “Equipe de organização” está visível na tela de login.'
            : 'Nenhum período ativo: o botão da equipe está escondido e ninguém entra como participante.')
        . ' <a href="' . e(reorg_url('admin/notificacoes.php')) . '" class="reorg-sino" title="Notificações">' . icon('bell') . ($naoLidas ? '<span data-reorg-naolidas>' . $naoLidas . '</span>' : '<span data-reorg-naolidas hidden>0</span>') . '</a></div>';
    echo '<nav class="adm-tabs adm-tabs--links adm-tabs--solta reorg-abas" aria-label="Seções da reorganização">';
    foreach (REORG_ABAS as $id => [$rotulo, $icone, $caminho]) {
        $extra = $id === 'solicitacoes' && $pend ? ' <span class="adm-tabs__count">' . $pend . '</span>' : ($id === 'pendencias' && $pendencias ? ' <span class="adm-tabs__count">' . $pendencias . '</span>' : '');
        echo '<a class="adm-tab' . ($aba === $id ? ' is-active' : '') . '" href="' . e(reorg_url($caminho)) . '"' . ($aba === $id ? ' aria-current="page"' : '') . '>' . icon($icone) . ' ' . e($rotulo) . $extra . '</a>';
    }
    echo '</nav>';
    echo admin_flash_html();
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
        admin_flash('sucesso', is_string($msg) && $msg !== '' ? $msg : $sucesso);
    } catch (Biblioteca\Services\AcervoException $e) {
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

function reorg_barra(int $feito, int $total, string $rotulo = ''): string
{
    $p = $total > 0 ? min(100, (int)floor(100 * $feito / $total)) : 0;
    return '<div class="reorg-barra" title="' . e($rotulo ?: "$feito de $total") . '"><span style="width:' . $p . '%"></span></div>';
}

/** Administradores ativos (para responsável/autorizados). */
function reorg_administradores(): array
{
    return db_query('SELECT id, nome FROM administradores WHERE ativo = 1 ORDER BY nome')->fetchAll();
}
