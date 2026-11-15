<?php
/**
 * Etiquetas da Organização Física: placas para setores, estantes e prateleiras, em folha A4.
 *
 * Não precisa de impressora especial: a pré-visualização já está no tamanho da folha A4 e o botão
 * "Imprimir" usa a impressão do navegador (o menu e os filtros não saem no papel).
 * Exemplo de etiqueta de prateleira:  ESTANTE A / Literatura Brasileira / PRATELEIRA 03.
 * Nada é gravado no banco: a página só lê as estantes e prateleiras cadastradas.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

$usuario = fisico_iniciar();
$base = admin_url('organizacao-etiquetas');

$tipos = ['prateleiras' => 'Prateleiras', 'estantes' => 'Estantes', 'setores' => 'Setores'];
$tamanhos = ['g' => 'Grande (1 por linha)', 'm' => 'Média (2 por linha)', 'p' => 'Pequena (3 por linha)'];
$tipo = admin_param($_GET, 'tipo', 'prateleiras');
$tipo = isset($tipos[$tipo]) ? $tipo : 'prateleiras';
$tamanho = admin_param($_GET, 'tamanho', 'm');
$tamanho = isset($tamanhos[$tamanho]) ? $tamanho : 'm';
$categoria = admin_param($_GET, 'sem_categoria') !== '1';
$inativas = admin_param($_GET, 'inativas') === '1';
$setorId = (int)admin_param($_GET, 'setor', '0') ?: null;
$estanteIds = array_values(array_filter(array_map('intval', (array)($_GET['estantes'] ?? [])), fn ($i) => $i > 0));
if (($unica = (int)admin_param($_GET, 'estante', '0')) > 0) {
    $estanteIds[] = $unica;
}

$repo = organizacao_repo();
$setores = $repo->setores();
$todas = $repo->paraEtiquetas([], null, true);
$selecionadas = $repo->paraEtiquetas($estanteIds, $setorId, $inativas);
$nomeBiblioteca = etiquetas_biblioteca();

/** Monta as etiquetas (tipo, nome grande, subtítulo) na ordem da estrutura. */
$etiquetas = [];
if ($tipo === 'setores') {
    foreach ($setores as $s) {
        if (($setorId && (int)$s['id'] !== $setorId) || (!$inativas && !(int)$s['ativo'])) {
            continue;
        }
        $etiquetas[] = ['tipo' => 'Setor', 'nome' => $s['nome'], 'sub' => $s['identificacao'] ? 'Código ' . $s['identificacao'] : ($s['descricao'] ?? '')];
    }
} else {
    foreach ($selecionadas as $es) {
        $sub = $categoria && $es['categoria'] ? $es['categoria'] : '';
        if ($tipo === 'estantes') {
            $etiquetas[] = ['tipo' => ($es['setor_nome'] ? $es['setor_nome'] . ' · ' : '') . 'Estante', 'nome' => 'ESTANTE ' . mb_strtoupper($es['identificacao']), 'sub' => $sub !== '' ? $sub : ($es['nome'] !== 'Estante ' . $es['identificacao'] ? $es['nome'] : '')];
            continue;
        }
        foreach ($es['prateleiras'] as $p) {
            $num = ctype_digit((string)$p['prateleira']) ? str_pad((string)$p['prateleira'], 2, '0', STR_PAD_LEFT) : mb_strtoupper((string)$p['prateleira']);
            $etiquetas[] = ['tipo' => 'ESTANTE ' . mb_strtoupper($es['identificacao']), 'nome' => 'PRATELEIRA ' . $num, 'sub' => $sub];
        }
    }
}
$porFolha = ['g' => 4, 'm' => 10, 'p' => 21][$tamanho];
$folhas = array_chunk($etiquetas, $porFolha);

admin_page_start('organizacao-etiquetas', 'Etiquetas das estantes', 'Etiquetas das estantes', 'Placas de setores, estantes e prateleiras para imprimir em A4', FISICO_CSS);
?>
<div class="adm-nao-imprimir">
<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Organização Física', admin_url('organizacao-fisica')], ['Etiquetas das estantes']]) ?>
<?= admin_flash_html() ?>

<section class="adm-card adm-panel">
    <form method="get" action="<?= e($base) ?>" class="adm-form">
        <div class="adm-form-grid">
            <label class="adm-field"><span class="adm-field__label">O que imprimir</span>
                <select class="adm-select" name="tipo"><?php foreach ($tipos as $k => $l): ?><option value="<?= $k ?>"<?= $k === $tipo ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
            <label class="adm-field"><span class="adm-field__label">Tamanho</span>
                <select class="adm-select" name="tamanho"><?php foreach ($tamanhos as $k => $l): ?><option value="<?= $k ?>"<?= $k === $tamanho ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
            <?php if ($setores): ?>
                <label class="adm-field"><span class="adm-field__label">Setor</span>
                    <select class="adm-select" name="setor"><option value="">Todos</option><?php foreach ($setores as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $setorId ? ' selected' : '' ?>><?= e($s['nome']) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
        </div>
        <?php if ($todas): ?>
            <fieldset class="adm-field">
                <legend class="adm-field__label">Estantes (deixe tudo desmarcado para imprimir todas)</legend>
                <div class="adm-chips">
                    <?php foreach ($todas as $es): ?>
                        <label class="adm-check"><input type="checkbox" name="estantes[]" value="<?= (int)$es['id'] ?>"<?= in_array((int)$es['id'], $estanteIds, true) ? ' checked' : '' ?>> <?= e(fisico_rotulo_estante($es)) ?><?= !(int)$es['ativa'] ? ' (desativada)' : '' ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>
        <fieldset class="adm-fieldset adm-etq-opcoes">
            <legend class="adm-field__label">Opções de impressão</legend>
            <label class="adm-check"><input type="checkbox" name="sem_categoria" value="1"<?= $categoria ? '' : ' checked' ?>> Não mostrar a categoria da estante (ex.: Literatura Brasileira)</label>
            <label class="adm-check"><input type="checkbox" name="inativas" value="1"<?= $inativas ? ' checked' : '' ?>> Incluir estantes, prateleiras e setores desativados</label>
        </fieldset>
        <div class="adm-actions-inline adm-etq-acoes">
            <button class="adm-button adm-button--ghost" type="submit"><?= icon('eye') ?> Atualizar pré-visualização</button>
            <?php if ($etiquetas): ?><button class="adm-button adm-button--primary" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir (<?= count($etiquetas) ?> etiqueta(s), <?= count($folhas) ?> folha(s) A4)</button><?php endif; ?>
        </div>
        <p class="adm-field__hint">Ao imprimir, escolha papel A4, escala 100% e margens “Nenhuma”. Papel comum serve: recorte e cole na estante ou plastifique.</p>
    </form>
</section>
</div>

<?php if (!$etiquetas): ?>
    <section class="adm-card adm-panel adm-nao-imprimir">
        <div class="adm-empty"><?= icon('printer') ?>
            <strong><?= $todas || $setores ? 'Nenhuma etiqueta com estas escolhas.' : 'Nenhuma estante cadastrada.' ?></strong>
            <span><?= $todas || $setores ? 'Mude “O que imprimir”, o setor ou as estantes marcadas e atualize a pré-visualização.' : 'Cadastre a estrutura física da biblioteca (estantes e prateleiras) para gerar as etiquetas.' ?></span>
            <?php if (!$todas && !$setores && auth_pode('localizacoes.gerenciar')): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e(admin_url('organizacao-fisica') . '?painel=estante') ?>"><?= icon('plus') ?> Cadastrar estantes</a><?php endif; ?>
        </div>
    </section>
<?php else: ?>
    <div class="adm-etq-previa-topo adm-nao-imprimir">
        <h2><?= icon('eye') ?> Pré-visualização</h2>
        <span class="adm-table__muted"><?= count($etiquetas) ?> etiqueta(s) · <?= count($folhas) ?> folha(s) A4 · tamanho <?= e(mb_strtolower($tamanhos[$tamanho])) ?></span>
    </div>
    <div class="adm-org-previa">
        <?php foreach ($folhas as $folha): ?>
            <div class="adm-org-folha adm-org-folha--tamanho-<?= $tamanho ?>">
                <?php foreach ($folha as $et): ?>
                    <div class="adm-org-etq">
                        <span class="adm-org-etq__tipo"><?= e($et['tipo']) ?></span>
                        <span class="adm-org-etq__nome"><?= e($et['nome']) ?></span>
                        <?php if ($et['sub'] !== ''): ?><span class="adm-org-etq__sub"><?= e($et['sub']) ?></span><?php endif; ?>
                        <span class="adm-org-etq__rodape"><?= e($nomeBiblioteca) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php admin_page_end(); ?>
