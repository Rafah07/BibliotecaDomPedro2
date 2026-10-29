<?php
/**
 * Importação do Acervo (Etapa 3): painel, envio da planilha e mapeamento de colunas.
 * A revisão, a confirmação e a conclusão ficam em detalhes-importacao.php.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/importacao.php';
require_once __DIR__ . '/includes/acervo.php';

use Biblioteca\Services\ExcelImportService;
use Biblioteca\Services\ImportValidationService;

$erroBanco = null;
$estatisticas = ['importacoes' => 0, 'importados' => 0, 'pendencias' => 0, 'erros' => 0];
$abertas = [];
$retomar = null;
$modoReal = db_modo() === 'mysql';

if ($modoReal) {
    try {
        db();
        auth_exigir('importacao.ver');
        $repo = importacao_repo();
        $estatisticas = $repo->estatisticas();
        $abertas = $repo->listar(['abertas' => true, 'limite' => 10]);
        $idRetomar = (int)($_GET['id'] ?? 0);
        if ($idRetomar) {
            $retomar = $repo->buscar($idRetomar);
            if ($retomar && !in_array($retomar['status'], ['enviada', 'em_analise'], true)) {
                header('Location: ' . admin_url('detalhes-importacao') . '?id=' . $idRetomar);
                exit;
            }
        }
    } catch (DbIndisponivel $e) {
        $erroBanco = $e->getMessage();
        $modoReal = false;
    }
}
$podeEnviar = $modoReal && auth_pode('importacao.revisar');

$cards = [
    ['titulo' => 'Total de importações', 'valor' => $estatisticas['importacoes'], 'descricao' => 'Arquivos processados', 'icone' => 'file-spreadsheet', 'tom' => 'blue'],
    ['titulo' => 'Registros importados', 'valor' => $estatisticas['importados'], 'descricao' => 'Linhas aprovadas e gravadas no acervo', 'icone' => 'circle-check', 'tom' => 'green'],
    ['titulo' => 'Registros com pendências', 'valor' => $estatisticas['pendencias'], 'descricao' => 'Aguardando decisão ou revisão', 'icone' => 'hourglass', 'tom' => 'amber'],
    ['titulo' => 'Erros identificados', 'valor' => $estatisticas['erros'], 'descricao' => 'Linhas que não puderam ser importadas', 'icone' => 'circle-alert', 'tom' => 'red'],
];

$campos = ['ignorar' => 'Ignorar coluna'];
foreach (ImportValidationService::CAMPOS as $campo => $def) {
    $campos[$campo] = $def['label'];
}
$dadosJs = [
    'api' => admin_base() . 'api/importacoes/',
    'detalhes' => admin_url('detalhes-importacao'),
    'campos' => $campos,
    'xlsx' => ExcelImportService::xlsxDisponivel(),
    'limite' => ExcelImportService::TAMANHO_MAXIMO,
    'retomar' => $retomar ? ['id' => (int)$retomar['id'], 'status' => $retomar['status']] : null,
];

admin_page_start('importar', 'Importação de Planilhas', 'Importação de Planilhas', 'Acervo · Planilhas antigas da biblioteca', ['assets/css/acervo.css?v=20261027', 'assets/css/importacao.css?v=20261027']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Importação']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Importação do Acervo</h1>
        <p>Importe, revise e organize os registros das planilhas antigas da biblioteca.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('historico-importacoes')) ?>"><?= icon('history') ?> Histórico de importações</a>
        <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_base() . 'api/importacoes/relatorio.php?tipo=modelo') ?>"><?= icon('file-down') ?> Baixar modelo de planilha</a>
        <a class="adm-button adm-button--primary" href="#nova-importacao" data-nova-importacao><?= icon('file-up') ?> Nova importação</a>
    </div>
</section>

<?php if (!$modoReal): ?>
    <?= importacao_aviso_banco($erroBanco) ?>
<?php endif; ?>

<section class="adm-stats" aria-label="Resumo das importações">
    <?php foreach ($cards as $card): ?>
        <article class="adm-card adm-stat adm-stat--compact">
            <div class="adm-stat__top">
                <p class="adm-stat__title"><?= htmlspecialchars($card['titulo']) ?></p>
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $card['tom'] ?>"><?= icon($card['icone']) ?></span>
            </div>
            <p class="adm-stat__value"><?= number_format($card['valor'], 0, ',', '.') ?></p>
            <p class="adm-stat__desc"><?= htmlspecialchars($card['descricao']) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($modoReal && $estatisticas['importacoes'] === 0 && !$abertas): ?>
    <div class="adm-notice adm-notice--blue" role="note">
        <?= icon('info') ?>
        <div class="adm-notice__content">
            <strong>Nenhuma planilha foi importada ainda.</strong>
            <p>Comece enviando o primeiro arquivo abaixo. Nada entra no acervo antes da sua revisão e confirmação; se preferir, baixe o modelo de planilha para conferir as colunas esperadas.</p>
        </div>
    </div>
<?php endif; ?>

<?php if ($abertas): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-abertas">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-abertas">Importações em andamento</h2>
                <p>Continue de onde parou. Nada foi perdido.</p>
            </div>
        </div>
        <ul class="adm-imp-open">
            <?php foreach ($abertas as $a): ?>
                <?php $continuar = in_array($a['status'], ['enviada', 'em_analise'], true) ? admin_url('importar') . '?id=' . $a['id'] . '#nova-importacao' : admin_url('detalhes-importacao') . '?id=' . $a['id']; ?>
                <li>
                    <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('file-spreadsheet') ?></span>
                    <span class="adm-imp-open__text">
                        <strong><?= htmlspecialchars($a['nome_arquivo']) ?></strong>
                        <small>Enviada em <?= importacao_data($a['criado_em']) ?> por <?= htmlspecialchars($a['usuario_nome'] ?? '—') ?> · <?= number_format((int)$a['total_linhas'], 0, ',', '.') ?> linhas</small>
                    </span>
                    <?= importacao_badge_status($a['status']) ?>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= htmlspecialchars($continuar) ?>">Continuar <?= icon('arrow-right') ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="adm-card adm-panel adm-imp-wizard" id="nova-importacao" aria-labelledby="titulo-nova"
    data-importacao-app data-csrf="<?= $modoReal ? htmlspecialchars(csrf_token()) : '' ?>">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-nova">Nova importação</h2>
            <p>O arquivo fica guardado em uma área protegida. Nenhum registro entra no acervo oficial antes da confirmação.</p>
        </div>
    </div>

    <?= importacao_etapas(1) ?>

    <?php if (!$podeEnviar): ?>
        <div class="adm-empty adm-imp-blocked">
            <span class="adm-icon-box adm-icon-box--lg adm-tone--<?= $modoReal ? 'amber' : 'blue' ?>"><?= icon($modoReal ? 'lock' : 'file-spreadsheet') ?></span>
            <strong><?= $modoReal ? 'Seu perfil pode consultar as importações, mas não enviar planilhas.' : 'O envio de planilhas fica disponível com o banco MySQL configurado.' ?></strong>
            <p><?= $modoReal ? 'Peça à bibliotecária responsável para fazer o envio.' : 'Assim nenhuma alteração real é feita antes de existir login e controle de permissões.' ?></p>
        </div>
    <?php else: ?>

    <!-- 1. Enviar arquivo -->
    <div class="adm-imp-step" data-passo="1">
        <label class="adm-imp-drop" data-drop>
            <input type="file" name="arquivo" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" data-arquivo hidden>
            <span class="adm-icon-box adm-icon-box--lg adm-tone--blue"><?= icon('upload') ?></span>
            <strong>Arraste a planilha para cá ou <span class="adm-link">escolha um arquivo</span></strong>
            <small>Excel (.xlsx) ou CSV (.csv), até 20 MB.<?= ExcelImportService::xlsxDisponivel() ? '' : ' A leitura de .xlsx ainda não está instalada no servidor: envie CSV por enquanto.' ?></small>
        </label>
        <div class="adm-imp-file" data-arquivo-escolhido hidden>
            <span class="adm-icon-box adm-icon-box--sm adm-tone--green"><?= icon('sheet') ?></span>
            <span class="adm-imp-file__text"><strong data-arquivo-nome></strong><small data-arquivo-tamanho></small></span>
            <button type="button" class="adm-icon-button adm-icon-button--sm" data-arquivo-limpar aria-label="Remover arquivo"><?= icon('x') ?></button>
        </div>
        <p class="adm-field__error" data-erro-envio role="alert"></p>
        <div class="adm-form-actions">
            <button type="button" class="adm-button adm-button--primary" data-enviar disabled><?= icon('file-up') ?> Enviar e ler planilha</button>
        </div>
    </div>

    <!-- 2. Mapear colunas -->
    <div class="adm-imp-step" data-passo="2" hidden>
        <dl class="adm-imp-summary">
            <div><dt>Arquivo</dt><dd data-info="nome">—</dd></div>
            <div><dt>Tamanho</dt><dd data-info="tamanho">—</dd></div>
            <div><dt>Linhas de dados</dt><dd data-info="linhas">—</dd></div>
            <div><dt>Colunas identificadas</dt><dd data-info="colunas">—</dd></div>
        </dl>

        <div class="adm-form-grid adm-form-grid--3 adm-imp-options">
            <label class="adm-field">
                <span class="adm-field__label">Aba da planilha</span>
                <select class="adm-select" data-aba></select>
                <span class="adm-field__hint" data-aba-dica></span>
            </label>
            <label class="adm-field">
                <span class="adm-field__label">Linha do cabeçalho</span>
                <input class="adm-input" type="number" min="1" max="50" value="1" data-linha-cabecalho inputmode="numeric">
                <span class="adm-field__hint">Linha onde ficam os nomes das colunas.</span>
            </label>
            <label class="adm-field">
                <span class="adm-field__label">Usar mapeamento salvo</span>
                <select class="adm-select" data-mapeamento-salvo><option value="">Sugestão automática</option></select>
                <span class="adm-field__hint">Reaproveita a associação de importações anteriores.</span>
            </label>
        </div>

        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-imp-map">
                <thead>
                    <tr>
                        <th scope="col">Coluna da planilha</th>
                        <th scope="col">Exemplos de valores</th>
                        <th scope="col">Campo no sistema</th>
                    </tr>
                </thead>
                <tbody data-colunas></tbody>
            </table>
        </div>

        <div class="adm-notice adm-notice--blue adm-notice--compact">
            <?= icon('info') ?>
            <div class="adm-notice__content">
                <p>O conteúdo original de <strong>todas</strong> as colunas, inclusive as ignoradas, fica guardado junto com cada linha. A CDD antiga é preservada como texto e não precisa ser válida: ela será revista na reclassificação.</p>
            </div>
        </div>

        <div class="adm-imp-save-map">
            <label class="adm-field">
                <span class="adm-field__label">Salvar este mapeamento <span class="adm-optional">(opcional)</span></span>
                <input class="adm-input" type="text" maxlength="120" placeholder="Ex.: Planilha antiga da biblioteca" data-mapeamento-nome>
            </label>
            <button type="button" class="adm-button adm-button--ghost" data-salvar-mapeamento><?= icon('save') ?> Salvar mapeamento</button>
        </div>

        <p class="adm-field__error" data-erro-mapa role="alert"></p>
        <div class="adm-form-actions adm-form-actions--split">
            <button type="button" class="adm-button adm-button--ghost" data-trocar-arquivo><?= icon('arrow-left') ?> Escolher outro arquivo</button>
            <button type="button" class="adm-button adm-button--primary" data-analisar><?= icon('list-checks') ?> Analisar planilha</button>
        </div>
    </div>

    <!-- 3. Validar -->
    <div class="adm-imp-step" data-passo="3" hidden>
        <div class="adm-imp-progress" role="status" aria-live="polite">
            <span class="adm-icon-box adm-icon-box--lg adm-tone--blue"><?= icon('loader-circle', 'adm-spin') ?></span>
            <strong data-progresso-titulo>Analisando a planilha…</strong>
            <div class="adm-progress adm-progress--lg"><div class="adm-progress__bar" data-progresso-barra style="width: 0%"></div></div>
            <small data-progresso-texto>Preparando a leitura.</small>
            <p class="adm-field__hint">A análise é feita em blocos. Se a conexão cair, basta abrir a importação de novo para continuar.</p>
        </div>
        <p class="adm-field__error" data-erro-analise role="alert"></p>
        <div class="adm-form-actions" data-analise-acoes hidden>
            <button type="button" class="adm-button adm-button--primary" data-retomar-analise><?= icon('refresh-cw') ?> Tentar novamente</button>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php if ($podeEnviar): ?>
<dialog class="adm-modal" id="modal-cancelar-envio" aria-labelledby="modal-cancelar-envio-titulo">
    <div class="adm-modal__box">
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--amber"><?= icon('triangle-alert') ?></span>
            <div>
                <h2 id="modal-cancelar-envio-titulo">Escolher outro arquivo?</h2>
                <p>Esta importação será cancelada. O arquivo enviado continua registrado no histórico.</p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Continuar com este arquivo</button>
            <button type="button" class="adm-button adm-button--danger" data-confirmar-troca><?= icon('x') ?> Cancelar e escolher outro</button>
        </footer>
    </div>
</dialog>
<script type="application/json" id="importacao-config"><?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php
admin_page_end($podeEnviar ? ['assets/js/importacao.js?v=20261027'] : []);
