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
