<?php
/**
 * Importar Alunos: leitura local de uma planilha (.xlsx ou .csv), mapeamento das colunas,
 * prévia com problemas e possíveis duplicidades, e gravação na tabela alunos.
 *
 * Somente para perfis com a permissão alunos.importar. Alunos não ganham conta, login ou senha.
 * A planilha não é guardada no servidor: veja admin/includes/alunos-importacao.php.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/alunos-importacao.php';
require_once __DIR__ . '/includes/acervo.php';

use Biblioteca\Services\AlunoImportService;
use Biblioteca\Services\ExcelImportService;

auth_exigir('alunos.importar');

$modoReal = db_modo() === 'mysql';
$erroBanco = null;
$totalAlunos = 0;
if ($modoReal) {
    try {
        $totalAlunos = alunos_repo()->total();
    } catch (DbIndisponivel $e) {
        $erroBanco = $e->getMessage();
        $modoReal = false;
    }
}

$campos = ['ignorar' => 'Ignorar coluna'];
foreach (AlunoImportService::CAMPOS as $campo => $def) {
    $campos[$campo] = $def['label'] . ($def['obrigatorio'] ? ' (obrigatório)' : '');
}
$dadosJs = [
    'api' => admin_base() . 'api/alunos/importar.php',
    'campos' => $campos,
    'situacoes' => ALUNOS_IMPORTACAO_SITUACOES,
    'turnos' => AlunoImportService::TURNOS,
    'limite' => AlunoImportService::TAMANHO_MAXIMO,
    'xlsx' => ExcelImportService::xlsxDisponivel(),
];
$etapas = ['Escolher arquivo', 'Mapear colunas', 'Prévia', 'Resultado'];

admin_page_start('importar-alunos', 'Importar Alunos', 'Importar Alunos', 'Cadastro de alunos · Planilha local', ['assets/css/acervo.css?v=20261030', 'assets/css/importacao.css?v=20261030', 'assets/css/importar-alunos.css?v=20261030']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Alunos'], ['Importar Alunos']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Importar Alunos</h1>
        <p>Inclua ou atualize o cadastro de alunos a partir de uma planilha. Os alunos não recebem conta, login nem senha.</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($modoReal): ?>
            <a class="adm-button adm-button--ghost" href="<?= e($dadosJs['api'] . '?acao=modelo') ?>"><?= icon('file-down') ?> Baixar modelo (CSV fictício)</a>
        <?php endif; ?>
    </div>
</section>

<div class="adm-notice adm-notice--blue" role="note">
    <?= icon('shield-check') ?>
    <div class="adm-notice__content">
        <strong>A planilha fica só neste computador.</strong>
        <p>O arquivo é lido pelo servidor local (XAMPP) a cada etapa e descartado em seguida: ele não é guardado, não vai para serviços externos e os dados dos alunos não entram em logs. Só as colunas que você mapear são usadas; marque como “Ignorar” as colunas com dados pessoais que o sistema não precisa (CPF, endereço, responsáveis, telefone…).</p>
    </div>
</div>

<?php if (!$modoReal): ?>
    <div class="adm-notice adm-notice--amber" role="note">
        <?= icon('triangle-alert') ?>
        <div class="adm-notice__content">
            <strong>Importação indisponível</strong>
            <p><?= e($erroBanco ?? 'Para importar alunos, configure o MySQL no arquivo .env, rode "php database/migrar.php" e entre com uma conta administrativa. No modo demonstrativo nenhum arquivo é lido.') ?></p>
        </div>
    </div>
<?php else: ?>

<section class="adm-stats adm-stats--2" aria-label="Resumo">
    <article class="adm-card adm-stat adm-stat--compact">
        <div class="adm-stat__top">
            <p class="adm-stat__title">Alunos cadastrados</p>
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('graduation-cap') ?></span>
        </div>
        <p class="adm-stat__value" data-total-alunos><?= number_format($totalAlunos, 0, ',', '.') ?></p>
        <p class="adm-stat__desc">Identificados pela matrícula</p>
    </article>
    <article class="adm-card adm-stat adm-stat--compact">
        <div class="adm-stat__top">
            <p class="adm-stat__title">Limites por arquivo</p>
            <span class="adm-icon-box adm-icon-box--sm adm-tone--indigo"><?= icon('file-spreadsheet') ?></span>
        </div>
        <p class="adm-stat__value"><?= number_format(AlunoImportService::MAX_LINHAS, 0, ',', '.') ?></p>
        <p class="adm-stat__desc">linhas por aba · .xlsx ou .csv até 5 MB</p>
    </article>
</section>

<section class="adm-card adm-panel adm-imp-wizard" aria-labelledby="titulo-importar" data-alunos-app data-csrf="<?= e(csrf_token()) ?>">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-importar">Nova importação de alunos</h2>
            <p>Nada é gravado antes da prévia e da sua confirmação.</p>
        </div>
    </div>

    <ol class="adm-imp-steps" aria-label="Etapas da importação">
        <?php foreach ($etapas as $i => $nome): ?>
            <li class="adm-imp-steps__item <?= $i === 0 ? 'is-current' : 'is-todo' ?>" data-etapa="<?= $i + 1 ?>"<?= $i === 0 ? ' aria-current="step"' : '' ?>>
                <span class="adm-imp-steps__num"><?= $i + 1 ?></span>
                <span class="adm-imp-steps__label"><?= e($nome) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <!-- 1. Arquivo -->
    <div class="adm-imp-step" data-passo="1">
        <label class="adm-imp-drop" data-drop>
            <input type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" data-arquivo hidden>
            <span class="adm-icon-box adm-icon-box--lg adm-tone--blue"><?= icon('upload') ?></span>
            <strong>Arraste a planilha de alunos para cá ou <span class="adm-link">escolha um arquivo</span></strong>
            <small>Excel (.xlsx) ou CSV (.csv), até 5 MB.<?= ExcelImportService::xlsxDisponivel() ? '' : ' A leitura de .xlsx não está instalada (rode "composer install"): use CSV por enquanto.' ?></small>
        </label>
        <div class="adm-imp-file" data-arquivo-escolhido hidden>
            <span class="adm-icon-box adm-icon-box--sm adm-tone--green"><?= icon('sheet') ?></span>
            <span class="adm-imp-file__text"><strong data-arquivo-nome></strong><small data-arquivo-tamanho></small></span>
            <button type="button" class="adm-icon-button adm-icon-button--sm" data-arquivo-limpar aria-label="Remover arquivo"><?= icon('x') ?></button>
        </div>
        <p class="adm-field__error" data-erro="1" role="alert"></p>
        <div class="adm-form-actions">
            <button type="button" class="adm-button adm-button--primary" data-ler disabled><?= icon('file-search') ?> Ler planilha</button>
        </div>
    </div>

    <!-- 2. Mapear colunas -->
    <div class="adm-imp-step" data-passo="2" hidden>
        <dl class="adm-imp-summary">
            <div><dt>Arquivo</dt><dd data-info="nome">—</dd></div>
            <div><dt>Linhas de dados</dt><dd data-info="linhas">—</dd></div>
            <div><dt>Colunas</dt><dd data-info="colunas">—</dd></div>
            <div><dt>Tipo</dt><dd data-info="tipo">—</dd></div>
        </dl>
        <div class="adm-form-grid adm-form-grid--3 adm-imp-options">
            <label class="adm-field">
                <span class="adm-field__label">Aba da planilha</span>
                <select class="adm-select" data-aba></select>
                <span class="adm-field__hint">Escolha a aba com o cadastro dos alunos.</span>
            </label>
            <label class="adm-field">
                <span class="adm-field__label">Linha do cabeçalho</span>
                <input class="adm-input" type="number" min="1" max="50" value="1" data-linha-cabecalho inputmode="numeric">
                <span class="adm-field__hint">Linha onde ficam os nomes das colunas.</span>
            </label>
        </div>
        <p class="adm-field__hint" data-aviso-linhas hidden></p>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-imp-map">
                <thead>
                    <tr>
                        <th scope="col">Coluna da planilha</th>
                        <th scope="col">Exemplos</th>
                        <th scope="col">Campo no sistema</th>
                    </tr>
                </thead>
                <tbody data-colunas></tbody>
            </table>
        </div>
        <div class="adm-notice adm-notice--blue adm-notice--compact">
            <?= icon('info') ?>
            <div class="adm-notice__content">
                <p>Nome e matrícula são obrigatórios. Colunas marcadas como “Ignorar coluna” não são lidas na análise nem gravadas. A matrícula identifica o aluno: nomes iguais nunca substituem um cadastro.</p>
            </div>
        </div>
        <p class="adm-field__error" data-erro="2" role="alert"></p>
        <div class="adm-form-actions adm-form-actions--split">
            <button type="button" class="adm-button adm-button--ghost" data-voltar="1"><?= icon('arrow-left') ?> Escolher outro arquivo</button>
            <button type="button" class="adm-button adm-button--primary" data-gerar-previa><?= icon('list-checks') ?> Gerar prévia</button>
        </div>
    </div>

    <!-- 3. Prévia -->
    <div class="adm-imp-step" data-passo="3" hidden>
        <div class="adm-kpis adm-alu-kpis" data-resumo></div>

        <fieldset class="adm-alu-opcoes">
            <legend>O que fazer com os alunos já cadastrados</legend>
            <div class="adm-radio-cards adm-radio-cards--2">
                <label class="adm-radio-card">
                    <input type="radio" name="modo" value="novos" checked data-opcao>
                    <span><strong>Somente incluir alunos novos</strong><small>Matrículas que já existem ficam como estão. Recomendado.</small></span>
                </label>
                <label class="adm-radio-card">
                    <input type="radio" name="modo" value="atualizar" data-opcao>
                    <span><strong>Incluir novos e atualizar existentes</strong><small>Altera nome, turma, turno ou e-mail das matrículas já cadastradas, após você revisar cada alteração. Células vazias não apagam dados.</small></span>
                </label>
            </div>
            <label class="adm-alu-check">
                <input type="checkbox" data-opcao data-importar-duplicidades>
                <span>Incluir também as linhas marcadas como <strong>possível duplicidade</strong> (matrícula nova, mas nome ou e-mail já usado por outro aluno). Marque só depois de conferir que são pessoas diferentes.</span>
            </label>
        </fieldset>

        <div class="adm-tabs adm-alu-tabs" role="tablist" data-filtros></div>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards adm-imp-table adm-alu-previa">
                <thead>
                    <tr>
                        <th scope="col">Linha</th>
                        <th scope="col">Matrícula</th>
                        <th scope="col">Nome</th>
                        <th scope="col">Turma</th>
                        <th scope="col">Turno</th>
                        <th scope="col">E-mail</th>
                        <th scope="col">Situação</th>
                    </tr>
                </thead>
                <tbody data-linhas></tbody>
            </table>
        </div>
        <div class="adm-pagination" data-paginacao></div>

        <div class="adm-notice adm-notice--amber" data-confirmar-atualizacao hidden>
            <?= icon('triangle-alert') ?>
            <div class="adm-notice__content">
                <strong data-texto-atualizacao></strong>
                <p>Revise as alterações na aba “A atualizar”. Os dados atuais serão substituídos pelos da planilha.</p>
                <label class="adm-alu-check">
                    <input type="checkbox" data-confirmo-atualizacao>
                    <span>Revisei as alterações e quero atualizar esses cadastros.</span>
                </label>
            </div>
        </div>

        <p class="adm-field__error" data-erro="3" role="alert"></p>
        <div class="adm-form-actions adm-form-actions--split">
            <button type="button" class="adm-button adm-button--ghost" data-voltar="2"><?= icon('arrow-left') ?> Voltar ao mapeamento</button>
            <button type="button" class="adm-button adm-button--primary" data-importar disabled><?= icon('circle-check') ?> <span data-texto-importar>Importar</span></button>
        </div>
    </div>

    <!-- 4. Resultado -->
    <div class="adm-imp-step" data-passo="4" hidden>
        <div class="adm-imp-progress" role="status">
            <span class="adm-icon-box adm-icon-box--lg adm-tone--green"><?= icon('circle-check') ?></span>
            <strong data-resultado-titulo>Importação concluída</strong>
            <small data-resultado-texto></small>
        </div>
        <div data-relatorio hidden>
            <h3 class="adm-alu-subtitulo">Linhas não importadas</h3>
            <p class="adm-field__hint">O relatório mostra a linha da planilha, a matrícula e o motivo. Ele é gerado no seu navegador e não fica guardado no servidor.</p>
            <div class="adm-table-wrap adm-table-wrap--flush">
                <table class="adm-table adm-table--compact">
                    <thead><tr><th scope="col">Linha</th><th scope="col">Matrícula</th><th scope="col">Motivo</th></tr></thead>
                    <tbody data-relatorio-linhas></tbody>
                </table>
            </div>
        </div>
        <div class="adm-form-actions adm-form-actions--split">
            <button type="button" class="adm-button adm-button--ghost" data-baixar-relatorio hidden><?= icon('download') ?> Baixar relatório (CSV)</button>
            <button type="button" class="adm-button adm-button--primary" data-nova><?= icon('file-up') ?> Nova importação</button>
        </div>
    </div>
</section>

<dialog class="adm-modal" id="modal-confirmar-importacao" aria-labelledby="modal-confirmar-importacao-titulo">
    <div class="adm-modal__box">
        <header class="adm-modal__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('users') ?></span>
            <div>
                <h2 id="modal-confirmar-importacao-titulo">Confirmar importação?</h2>
                <p data-modal-texto></p>
            </div>
            <button type="button" class="adm-modal__close" data-modal-close aria-label="Fechar"><?= icon('x') ?></button>
        </header>
        <footer class="adm-modal__footer">
            <button type="button" class="adm-button adm-button--ghost" data-modal-close>Voltar à prévia</button>
            <button type="button" class="adm-button adm-button--primary" data-confirmar><?= icon('circle-check') ?> Confirmar e gravar</button>
        </footer>
    </div>
</dialog>
<script type="application/json" id="alunos-config"><?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php
admin_page_end($modoReal ? ['assets/js/importar-alunos.js?v=20261030'] : []);
