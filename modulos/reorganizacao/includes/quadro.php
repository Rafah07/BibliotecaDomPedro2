<?php
/**
 * Quadro das pessoas de um lote: 4 colunas (Levantamento, Revisão, Carimbação, Organização), cada uma com
 * as pessoas e o progresso da etapa. Usado dentro do lote (aba Participantes), no formulário único de lote
 * e no aviso "pessoas aguardando lote" do inventário — o mesmo visual nos três lugares.
 */

use Biblioteca\Reorganizacao\Reorganizacao;

/** equipe => [sigla, etapa, ícone, chave do contador "feito"] */
const REORG_QUADRO_COLUNAS = [
    'grupo1' => ['G1', 'Levantamento', 'clipboard-list', 'registrados'],
    'grupo2' => ['G2', 'Revisão', 'list-checks', 'codigos'],
    'grupo3' => ['G3', 'Carimbação', 'stamp', 'carimbados'],
    'grupo4' => ['G4', 'Organização', 'library', 'organizados'],
];

/** Cabeçalho de uma coluna do quadro (sigla, etapa e quantidade de pessoas). */
function reorg_quadro_cabecalho(string $eq, ?int $pessoas): string
{
    [$sigla, $etapa, $icone] = REORG_QUADRO_COLUNAS[$eq];
    $sub = $pessoas === null ? 'usuários do painel' : $pessoas . ' pessoa' . ($pessoas === 1 ? '' : 's');
    return '<header class="reorg-quadro__cab"><span class="reorg-quadro__icone">' . icon($icone) . '</span><div><strong>' . e($etapa) . '</strong><small>' . e($sigla)
        . ' · ' . $sub . '</small></div></header>';
}

/** Progresso da etapa num lote ("12 de 30" + barra + quantos esperam). */
function reorg_quadro_progresso(string $eq, array $c): string
{
    $feito = (int)($c[REORG_QUADRO_COLUNAS[$eq][3]] ?? 0);
    $total = (int)$c['prevista'];
    $esperando = (int)(Reorganizacao::etapasEmAndamento($c)[$eq] ?? 0);
    $rot = ['grupo1' => 'levantados', 'grupo2' => 'com código BDP', 'grupo3' => 'carimbados', 'grupo4' => 'organizados'][$eq];
    return '<div class="reorg-quadro__prog"><div class="reorg-quadro__prog-num"><strong>' . $feito . '</strong> de ' . $total . ' ' . e($rot) . '</div>'
        . reorg_barra($feito, $total, "$feito de $total $rot")
        . ($esperando ? '<small class="reorg-quadro__espera">' . $esperando . ' esperando esta etapa</small>' : '<small class="reorg-quadro__espera is-zero">Nada esperando</small>') . '</div>';
}

/** Situação de uma pessoa conectada, para a lista de escolha ("Disponível" ou os lotes em que já trabalha). */
function reorg_quadro_situacao(array $s): string
{
    return !$s['lotes'] ? '<span class="reorg-ponto is-livre"></span> Disponível'
        : '<span class="reorg-ponto"></span> Já trabalha em: ' . e(implode(', ', array_column($s['lotes'], 'nome')));
}

/** Abre e fecha as janelas do quadro (data-reorg-dialogo="id" / data-reorg-fechar). Uma vez por página. */
function reorg_quadro_js(): string
{
    static $feito = false;
    if ($feito) {
        return '';
    }
    $feito = true;
    return <<<'HTML'
<script>
(function () {
    document.addEventListener('click', function (ev) {
        var abrir = ev.target.closest('[data-reorg-dialogo]');
        if (abrir) {
            var d = document.getElementById(abrir.getAttribute('data-reorg-dialogo'));
            if (d) { ev.preventDefault(); if (typeof d.showModal === 'function') { if (!d.open) { d.showModal(); } } else { d.setAttribute('open', ''); } }
            return;
        }
        var fechar = ev.target.closest('[data-reorg-fechar]');
        if (fechar) {
            var dd = fechar.closest('dialog');
            if (dd) { ev.preventDefault(); if (typeof dd.close === 'function') { dd.close(); } else { dd.removeAttribute('open'); } }
        }
    });
    document.addEventListener('click', function (ev) {
        if (ev.target.tagName === 'DIALOG' && ev.target.classList.contains('reorg-janela') && typeof ev.target.close === 'function') { ev.target.close(); }
    });
})();
</script>
HTML;
}

/** Início de uma janela (dialog) no visual do painel. */
function reorg_janela_inicio(string $id, string $titulo, string $sub, string $icone = 'user-plus'): string
{
    return '<dialog class="adm-modal adm-modal--wide reorg-janela" id="' . e($id) . '" aria-labelledby="' . e($id) . '-t"><div class="adm-modal__box">'
        . '<header class="adm-modal__header"><span class="adm-icon-box adm-icon-box--sm adm-tone--blue">' . icon($icone) . '</span><div><h2 id="' . e($id) . '-t">' . e($titulo) . '</h2>'
        . ($sub !== '' ? '<p>' . $sub . '</p>' : '') . '</div><button type="button" class="adm-modal__close" data-reorg-fechar aria-label="Fechar">' . icon('x') . '</button></header>';
}

function reorg_janela_fim(): string
{
    return '</div></dialog>';
}

/** Endereço de volta seguro (só caminhos deste site). */
function reorg_voltar_seguro(string $voltar, string $padrao): string
{
    return preg_match('#^/(?!/)[^\s\\\\]*$#', $voltar) ? $voltar : $padrao;
}
