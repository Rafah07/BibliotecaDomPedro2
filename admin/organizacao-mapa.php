<?php
/**
 * Mapa visual das estantes (migração 150): cada estante é um bloco com a identificação, o nome, as
 * prateleiras e o número de exemplares. Quem gerencia as localizações arrasta os blocos (ou usa as
 * setas do teclado, ou os campos numéricos sem JavaScript) e salva a disposição de cada setor.
 *
 * O mapa só desenha a sala: salvar o mapa NÃO muda nenhum exemplar, prateleira ou localização.
 * Endereços: ?setor=ID|sem.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\OrganizacaoFisicaService;

$usuario = fisico_iniciar();
$base = admin_url('organizacao-mapa');
$podeEditar = auth_pode('localizacoes.gerenciar');
const MAPA_LINHA_PX = 46;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $setorPost = admin_param($_POST, 'setor');
    $setorPost = preg_match('/^(sem|\d+)$/', $setorPost) ? $setorPost : '';
    try {
        fisico_exigir('localizacoes.gerenciar');
        if (admin_param($_POST, 'acao') === 'reiniciar') {
            organizacao_servico()->reiniciarMapa($setorPost === 'sem' || $setorPost === '' ? null : (int)$setorPost, (int)$usuario['id']);
            admin_flash('sucesso', 'Disposição automática restaurada.', 'Os blocos voltaram para a ordem das estantes. Nenhum exemplar foi alterado.');
        } else {
            $pos = $_POST['posicao'] ?? [];
            $n = organizacao_servico()->salvarMapa(is_array($pos) ? $pos : [], (int)$usuario['id']);
            admin_flash('sucesso', 'Mapa salvo.', fisico_numero($n) . ' estante(s) com a nova posição. Os exemplares e as localizações não mudam.');
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi gravado.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi gravado.', fisico_erro_tecnico($e, 'mapa das estantes'));
    }
    admin_redirecionar($base . ($setorPost !== '' ? '?setor=' . rawurlencode($setorPost) : ''));
}

try {
    $repo = organizacao_repo();
    $setores = $repo->setoresDoMapa();
    $chaves = array_column($setores, 'chave');
    $setor = admin_param($_GET, 'setor');
    $setor = in_array($setor, $chaves, true) ? $setor : ($chaves[0] ?? '');
    $estantes = $setor !== '' ? $repo->mapa($setor) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir o mapa', fisico_erro_tecnico($e, 'mapa das estantes') . ' Se acabou de atualizar o sistema, rode a migração 150 (php database/migrar.php).');
    exit;
}

// Disposição: a posição salva, ou automática (em fileiras, na ordem das estantes) para quem não tem.
$cols = OrganizacaoFisicaService::MAPA_COLUNAS;
$ocupadoAte = 0;
foreach ($estantes as $e) {
    if ($e['mapa_x'] !== null) {
        $ocupadoAte = max($ocupadoAte, (int)$e['mapa_y'] + (int)$e['mapa_altura']);
    }
}
$autoX = 0;
$autoY = $ocupadoAte > 0 ? $ocupadoAte + 1 : 0;
$semPosicao = 0;
foreach ($estantes as &$e) {
    if ($e['mapa_x'] === null) {
        $semPosicao++;
        $e['mapa_largura'] = 4;
        $e['mapa_altura'] = 3;
        if ($autoX + 4 > $cols) {
            $autoX = 0;
            $autoY += 4;
        }
        $e['mapa_x'] = $autoX;
        $e['mapa_y'] = $autoY;
        $autoX += 5;
    }
    foreach (['mapa_x', 'mapa_y', 'mapa_largura', 'mapa_altura'] as $k) {
        $e[$k] = (int)$e[$k];
    }
}
unset($e);
$linhas = 6;
foreach ($estantes as $e) {
    $linhas = max($linhas, $e['mapa_y'] + $e['mapa_altura'] + 2);
}
$nomeSetor = '';
foreach ($setores as $s) {
    if ($s['chave'] === $setor) {
        $nomeSetor = $s['nome'];
    }
}

admin_page_start('organizacao-mapa', 'Mapa das estantes', 'Organização Física', 'Disposição das estantes na biblioteca', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Organização Física', admin_url('organizacao-fisica')], ['Mapa das estantes']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Mapa das estantes</h1>
        <p>Veja onde fica cada estante na sala. Clique numa estante para abrir as prateleiras e os exemplares dela.<?= $podeEditar ? ' Arraste os blocos (ou use as setas do teclado) e clique em <strong>Salvar mapa</strong>.' : '' ?> O mapa é só um desenho: não muda nenhum exemplar.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('organizacao-fisica')) ?>"><?= icon('arrow-left') ?> Organização Física</a>
    </div>
</section>

<?= admin_flash_html() ?>

<?php if (!$setores): ?>
    <section class="adm-card adm-panel"><div class="adm-empty"><?= icon('map') ?><strong>Nenhuma estante cadastrada ainda.</strong>
        <span>Cadastre as estantes em <a class="adm-link" href="<?= e(admin_url('organizacao-fisica')) ?>">Organização Física</a>; elas aparecem aqui automaticamente.</span></div></section>
<?php else: ?>
    <?php if (count($setores) > 1): ?>
        <nav class="adm-tabs adm-tabs--links adm-tabs--solta" aria-label="Setores">
            <?php foreach ($setores as $s): ?>
                <a class="adm-tab<?= $s['chave'] === $setor ? ' is-active' : '' ?>" href="<?= e($base . '?setor=' . rawurlencode($s['chave'])) ?>"<?= $s['chave'] === $setor ? ' aria-current="page"' : '' ?>><?= e($s['nome']) ?> <span class="adm-tab__count"><?= (int)$s['estantes'] ?></span></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <form method="post" action="<?= e($base) ?>" class="adm-card adm-panel adm-mapa-painel" data-mapa-form data-enviar-uma-vez>
        <?= csrf_campo() ?><input type="hidden" name="setor" value="<?= e($setor) ?>">
        <div class="adm-panel__header">
            <div>
                <h2><?= icon('map') ?> <?= e($nomeSetor) ?></h2>
                <p><?= fisico_numero(count($estantes)) ?> estante(s) · <?= fisico_numero(array_sum(array_column($estantes, 'exemplares'))) ?> exemplar(es)
                    <?= $semPosicao ? ' · ' . fisico_numero($semPosicao) . ' em posição automática (ainda não salva)' : '' ?></p>
            </div>
            <?php if ($podeEditar): ?>
                <div class="adm-actions-inline">
                    <span class="adm-mapa__status" role="status" aria-live="polite" data-mapa-status></span>
                    <button class="adm-button adm-button--primary" type="submit" name="acao" value="salvar"><?= icon('save') ?> Salvar mapa</button>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($podeEditar): ?>
            <p class="adm-field__hint adm-mapa__dica"><?= icon('info') ?> Arraste um bloco para mudar o lugar. Com o bloco selecionado (Tab), use as <kbd>setas</kbd> para mover e <kbd>Shift</kbd> + <kbd>setas</kbd> para mudar o tamanho. No celular, o mapa aparece como lista.</p>
        <?php endif; ?>

        <div class="adm-mapa" style="--mapa-colunas: <?= $cols ?>; --mapa-linha: <?= MAPA_LINHA_PX ?>px; --mapa-linhas: <?= $linhas ?>;" data-mapa data-colunas="<?= $cols ?>" data-linha="<?= MAPA_LINHA_PX ?>"<?= $podeEditar ? ' data-mapa-editavel' : '' ?>
             role="list" aria-label="Estantes de <?= e($nomeSetor) ?>">
            <?php foreach ($estantes as $e): $eid = (int)$e['id']; ?>
                <div class="adm-mapa__bloco<?= (int)$e['ativa'] ? '' : ' is-inativa' ?>" role="listitem" data-bloco="<?= $eid ?>"
                     style="--x: <?= $e['mapa_x'] ?>; --y: <?= $e['mapa_y'] ?>; --w: <?= $e['mapa_largura'] ?>; --h: <?= $e['mapa_altura'] ?>;"
                     <?= $podeEditar ? 'tabindex="0" aria-label="Estante ' . e($e['identificacao']) . ': use as setas para mover"' : '' ?>>
                    <span class="adm-mapa__id"><?= e($e['identificacao']) ?></span>
                    <strong class="adm-mapa__nome"><?= e($e['nome'] ?: 'Estante ' . $e['identificacao']) ?></strong>
                    <span class="adm-mapa__info"><?= fisico_numero((int)$e['prateleiras']) ?> prat. · <?= fisico_numero((int)$e['exemplares']) ?> ex.<?= (int)$e['ativa'] ? '' : ' · desativada' ?></span>
                    <a class="adm-mapa__abrir" href="<?= e(admin_url('organizacao-fisica') . '?estante=' . $eid) ?>" draggable="false"><?= icon('eye') ?> Abrir</a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($podeEditar): ?>
            <details class="adm-acao adm-mapa__numeros">
                <summary><?= icon('sliders-horizontal') ?> Ajustar as posições por números</summary>
                <p class="adm-field__hint">Coluna de 0 a <?= $cols - 1 ?> (da esquerda para a direita), linha a partir de 0 (de cima para baixo), largura e altura em quadradinhos.</p>
                <div class="adm-table-wrap adm-table-wrap--flush">
                    <table class="adm-table adm-table--compacta">
                        <thead><tr><th scope="col">Estante</th><th scope="col">Coluna</th><th scope="col">Linha</th><th scope="col">Largura</th><th scope="col">Altura</th></tr></thead>
                        <tbody>
                        <?php foreach ($estantes as $e): $eid = (int)$e['id']; $n = 'posicao[' . $eid . ']'; ?>
                            <tr data-posicao="<?= $eid ?>">
                                <th scope="row"><?= e($e['identificacao']) ?> <small class="adm-table__muted"><?= e((string)$e['nome']) ?></small></th>
                                <td><input class="adm-input adm-input--sm" type="number" name="<?= $n ?>[x]" min="0" max="<?= $cols - 1 ?>" value="<?= $e['mapa_x'] ?>" data-campo="x" aria-label="Coluna da estante <?= e($e['identificacao']) ?>"></td>
                                <td><input class="adm-input adm-input--sm" type="number" name="<?= $n ?>[y]" min="0" max="<?= OrganizacaoFisicaService::MAPA_LINHAS ?>" value="<?= $e['mapa_y'] ?>" data-campo="y" aria-label="Linha da estante <?= e($e['identificacao']) ?>"></td>
                                <td><input class="adm-input adm-input--sm" type="number" name="<?= $n ?>[w]" min="1" max="<?= $cols ?>" value="<?= $e['mapa_largura'] ?>" data-campo="w" aria-label="Largura da estante <?= e($e['identificacao']) ?>"></td>
                                <td><input class="adm-input adm-input--sm" type="number" name="<?= $n ?>[h]" min="1" max="20" value="<?= $e['mapa_altura'] ?>" data-campo="h" aria-label="Altura da estante <?= e($e['identificacao']) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <div class="adm-form-actions">
                <button class="adm-button adm-button--ghost" type="submit" name="acao" value="reiniciar" data-confirm="Voltar todas as estantes deste setor para a disposição automática?"><?= icon('rotate-ccw') ?> Disposição automática</button>
                <button class="adm-button adm-button--primary" type="submit" name="acao" value="salvar"><?= icon('save') ?> Salvar mapa</button>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/organizacao-mapa.js?v=20261114a']));
