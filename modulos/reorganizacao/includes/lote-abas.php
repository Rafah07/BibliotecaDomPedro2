<?php
/**
 * Abas internas de um lote com equipes. Aparecem na área das equipes (admin/lote.php do módulo) e na
 * página permanente do lote (gancho lote.painel), para o lote ser um lugar só:
 * Visão geral · Grupo 1 · Grupo 2 · Grupo 3 · Grupo 4 · Participantes · Pendências · Histórico ·
 * Relatório e Planilha BDP (página permanente).
 */

use Biblioteca\Reorganizacao\Reorganizacao;

const REORG_ABAS_LOTE = [
    'visao' => ['Visão geral', 'layout-dashboard'],
    'g1' => ['G1 Levantamento', 'clipboard-list'],
    'g2' => ['G2 Revisão', 'list-checks'],
    'g3' => ['G3 Carimbação', 'stamp'],
    'g4' => ['G4 Conferência', 'library'],
    'participantes' => ['Participantes', 'users'],
    'pendencias' => ['Pendências', 'triangle-alert'],
    'historico' => ['Histórico', 'history'],
    'relatorio' => ['Relatório e Planilha BDP', 'file-text'],
];

/** $rl: lote das equipes (Reorganizacao::lote). $ativa: chave da aba atual. */
function reorg_abas_lote_html(array $rl, string $ativa): string
{
    $r = reorg();
    $id = (int)$rl['id'];
    $c = $r->contadores($id);
    $etapas = Reorganizacao::etapasEmAndamento($c);
    $part = (int)db_query('SELECT COUNT(*) FROM reorg_atribuicoes WHERE lote_id = ? AND encerrada_em IS NULL', [$id])->fetchColumn();
    $h = '<nav class="adm-tabs adm-tabs--links reorg-abas-lote adm-no-print" aria-label="Áreas do lote">';
    foreach (REORG_ABAS_LOTE as $k => [$rot, $ic]) {
        $cont = match ($k) {
            'g1' => $etapas['grupo1'] ?? 0, 'g2' => $etapas['grupo2'] ?? 0, 'g3' => $etapas['grupo3'] ?? 0, 'g4' => $etapas['grupo4'] ?? 0,
            'participantes' => $part, 'pendencias' => $c['pendencias_abertas'], default => 0,
        };
        $url = $k === 'relatorio' ? url('admin/lote.php?id=' . (int)$rl['cadastro_lote_id']) : reorg_url('admin/lote.php?id=' . $id . '&aba=' . $k);
        $h .= '<a class="adm-tab' . ($ativa === $k ? ' is-active' : '') . '" href="' . e($url) . '"' . ($ativa === $k ? ' aria-current="page"' : '') . '>' . icon($ic) . ' ' . e($rot)
            . ($cont ? ' <span class="adm-tabs__count">' . (int)$cont . '</span>' : '') . '</a>';
    }
    return $h . '</nav>';
}
