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

/**
 * CDD do registro gravada na classificação permanente DENTRO da transação do cadastro (mesmas regras
 * da conferência do Inventário: aprovada para quem aprova CDD, sugestão para os demais). Se falhar,
 * o cadastro inteiro é desfeito: nenhum exemplar fica liberado ao Grupo 3 sem a classificação.
 */
function reorg_registrar_cdd(array $usuario): callable
{
    require_once __DIR__ . '/../../../admin/includes/cdd.php';
    return function (int $livroId, string $cdd, string $fonte) use ($usuario): void {
        try {
            $v = cdd_validar($cdd);
            if (!$v['valida']) {
                throw new AcervoException('CDD ' . $cdd . ': ' . $v['erro']);
            }
            cdd_itens(true);
            $item = cdd_item($livroId);
            if (!$item) {
                throw new AcervoException('Obra não encontrada para registrar a CDD.');
            }
            $permissoes = AUTH_PERMISSOES[$usuario['perfil']] ?? [];
            $acao = in_array('cdd.aprovar', $permissoes, true) ? 'aprovar' : (in_array('cdd.sugerir', $permissoes, true) ? 'sugerir' : null);
            if ($acao === null) {
                throw new AcervoException('Seu perfil não pode registrar classificações CDD.');
            }
            $atual = cdd_linha_bloqueada($livroId, $item);
            $vigente = cdd_vigente(['cdd_aprovada' => $atual['cdd_aprovada'] ?? null, 'cdd_proposta' => $atual['cdd_proposta'] ?? null, 'cdd_original' => $atual['cdd_original'] ?? null]);
            if ($vigente['numero'] === $v['numero'] && ($vigente['tipo'] === 'aprovada' || ($vigente['tipo'] === 'proposta' && $acao === 'sugerir'))) {
                return;
            }
            [$nova, $historicos] = cdd_aplicar($acao, $atual, cdd_dados_limpos([
                'cdd' => $v['numero'],
                'fonte_nome' => trim($fonte) !== '' ? $fonte : 'Livro em mãos (reorganização do acervo)',
                'justificativa' => 'CDD ' . ($vigente['numero'] !== '' ? 'corrigida (antes: ' . $vigente['numero'] . ')' : 'informada') . ' na reorganização do acervo, revisada por ' . $usuario['nome'] . '.',
            ]), $usuario);
            cdd_gravar_mysql($nova, $historicos, $usuario);
        } catch (CddErro $e) {
            throw new AcervoException('CDD não registrada: ' . $e->getMessage() . ' Nada foi cadastrado.');
        }
    };
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
    return '<div class="reorg-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $p . '" title="' . e($rotulo ?: "$feito de $total") . '"><span style="width:' . $p . '%"></span></div>';
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
