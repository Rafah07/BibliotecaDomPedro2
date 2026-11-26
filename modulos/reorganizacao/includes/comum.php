<?php
/** Pequenos auxiliares de HTML usados pela administração e pela Central de Equipes. */

function reorg_status_badge(string $rotulo, string $tom): string
{
    return '<span class="adm-status adm-tone--' . e($tom) . '">' . e($rotulo) . '</span>';
}

function reorg_opcoes(array $itens, mixed $selecionado, string $chave = 'id', string $rotulo = 'nome'): string
{
    $h = '';
    foreach ($itens as $i) {
        $v = (string)$i[$chave];
        $h .= '<option value="' . e($v) . '"' . ((string)$selecionado === $v ? ' selected' : '') . '>' . e((string)$i[$rotulo]) . '</option>';
    }
    return $h;
}

function reorg_barra(int $feito, int $total, string $rotulo = ''): string
{
    $p = $total > 0 ? min(100, (int)floor(100 * $feito / $total)) : 0;
    return '<div class="reorg-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $p . '" title="' . e($rotulo ?: "$feito de $total") . '"><span style="width:' . $p . '%"></span></div>';
}

function reorg_conservacao_badge(?string $estado): string
{
    if (!$estado) {
        return '<span class="adm-table__muted">—</span>';
    }
    $rot = explode(' — ', \Biblioteca\Reorganizacao\Reorganizacao::CONSERVACAO[$estado] ?? $estado)[0];
    return reorg_status_badge($rot, ['excelente' => 'green', 'regular' => 'amber', 'ruim' => 'red'][$estado] ?? 'muted');
}
