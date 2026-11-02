<?php
/**
 * Sistema → Manutenção (somente administrador, permissão sistema.manutencao):
 *   - diagnóstico do servidor (PHP, extensões, banco, migrações, pastas, proteções);
 *   - totais dos dados preservados;
 *   - cópia de segurança completa do banco (.sql) para download;
 *   - limpeza de tentativas de login antigas.
 * Nenhuma ação desta página apaga livros, exemplares, alunos, empréstimos ou históricos.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/configuracoes.php';

use Biblioteca\Services\BackupService;
use Biblioteca\Services\DiagnosticoSistema;
use Biblioteca\Support\RegrasCirculacao;

$usuario = config_iniciar('sistema.manutencao');
$base = admin_url('manutencao');
$diagnostico = new DiagnosticoSistema(db(), realpath(APP_RAIZ) ?: APP_RAIZ);
$erroBackup = null;
$dias = 30;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'backup') {
        if (($_POST['ciente'] ?? '') !== '1') {
            $erroBackup = 'Confirme que o arquivo será guardado em local protegido: ele contém dados pessoais dos alunos.';
        } else {
            @set_time_limit(0);
            $arquivo = 'biblioteca-dom-pedro-ii-' . date('Y-m-d-His') . '.sql';
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $arquivo . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, private');
            $saida = fopen('php://output', 'wb');
            try {
                $resumo = (new BackupService(db()))->exportar($saida);
                registrar_acao('sistema.backup', 'sistema', null, $resumo);
            } catch (Throwable $e) {
                // O download já começou: o erro vai para o log e o arquivo termina com um aviso claro.
                config_erro_tecnico($e, 'cópia de segurança');
                fwrite($saida, "\n-- ERRO: a cópia de segurança NÃO terminou. Não use este arquivo; gere outra cópia.\n");
            }
            fclose($saida);
            exit;
        }
    } elseif ($acao === 'limpar_tentativas') {
        try {
            $apagadas = $diagnostico->limparTentativasAntigas($dias);
            registrar_acao('sistema.limpeza', 'sistema', null, ['tentativas_login_apagadas' => $apagadas, 'mais_antigas_que_dias' => $dias]);
            admin_flash('sucesso', 'Limpeza concluída.', $apagadas . ' tentativa(s) de login com mais de ' . $dias . ' dias foram apagadas. Nenhum outro dado foi alterado.');
        } catch (PDOException $e) {
            admin_flash('erro', 'A limpeza não foi feita.', config_erro_tecnico($e, 'limpeza de tentativas de login'));
        }
        admin_redirecionar($base);
    }
}

$regras = RegrasCirculacao::doAmbiente('config_valor');
$itens = $diagnostico->verificar(app_ambiente(), $regras->prazoProvisorio, config_bool('SITE_MANUTENCAO', false));
$totais = $diagnostico->totais();
$tamanho = $diagnostico->tamanhoBanco();
try {
    $antigas = $diagnostico->tentativasAntigas($dias);
} catch (PDOException $e) {
    $antigas = 0;
}
$contagem = array_count_values(array_column($itens, 'situacao'));
$situacoes = ['ok' => ['circle-check', 'green', 'OK'], 'aviso' => ['triangle-alert', 'amber', 'Atenção'], 'erro' => ['circle-alert', 'red', 'Problema']];

admin_page_start('manutencao', 'Manutenção', 'Manutenção', 'Diagnóstico e cópia de segurança', CONFIG_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Configurações', admin_url('configuracoes')], ['Manutenção']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Manutenção do sistema</h1>
        <p>Confira se o servidor está pronto, gere cópias de segurança do banco e faça limpezas que não afetam o acervo, os alunos nem os históricos.</p>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($erroBackup): ?><?= admin_aviso('erro', 'A cópia de segurança não foi gerada.', $erroBackup) ?><?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-diagnostico">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-diagnostico">Diagnóstico</h2>
            <p><?= (int)($contagem['ok'] ?? 0) ?> OK · <?= (int)($contagem['aviso'] ?? 0) ?> com atenção · <?= (int)($contagem['erro'] ?? 0) ?> com problema</p>
        </div>
    </div>
    <ul class="adm-config-diagnostico">
        <?php foreach ($itens as $it): ?>
            <?php [$ic, $tom, $rot] = $situacoes[$it['situacao']]; ?>
            <li class="is-<?= e($it['situacao']) ?>">
                <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $tom ?>"><?= icon($ic) ?><span class="adm-visually-hidden"><?= e($rot) ?></span></span>
                <div><strong><?= e($it['titulo']) ?></strong><span><?= e($it['detalhe']) ?></span></div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-dados">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-dados">Dados guardados no banco</h2>
            <p>Tamanho aproximado: <?= $tamanho === null ? '—' : e(number_format($tamanho / 1048576, 1, ',', '.')) . ' MB' ?>. Estes totais servem para conferir uma cópia de segurança restaurada.</p>
        </div>
    </div>
    <dl class="adm-config-totais">
        <?php foreach ($totais as $rotulo => $total): ?>
            <div><dt><?= e($rotulo) ?></dt><dd><?= $total === null ? '—' : e(number_format($total, 0, ',', '.')) ?></dd></div>
        <?php endforeach; ?>
    </dl>
</section>

<div class="adm-config-duas">
    <section class="adm-card adm-panel" aria-labelledby="titulo-backup">
        <div class="adm-form-section__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon('download') ?></span>
            <div>
                <h2 id="titulo-backup">Cópia de segurança</h2>
                <p>Baixa um arquivo .sql com todas as tabelas, dados, views e gatilhos. Faça uma cópia antes de aplicar migrações, importar planilhas grandes ou atualizar o sistema.</p>
            </div>
        </div>
        <form method="post" action="<?= e($base) ?>">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="backup">
            <label class="adm-check-confirm"><input type="checkbox" name="ciente" value="1" required> <span>Entendo que o arquivo contém nomes e matrículas dos alunos e as senhas criptografadas das contas, e vou guardá-lo em local protegido (nunca em pasta pública ou e-mail).</span></label>
            <button class="adm-button adm-button--primary" type="submit"><?= icon('download') ?> Baixar cópia de segurança</button>
        </form>
        <details class="adm-config-detalhes">
            <summary>Como restaurar a cópia</summary>
            <ol>
                <li>No phpMyAdmin do XAMPP, crie um banco <strong>novo e vazio</strong> (utf8mb4_unicode_ci).</li>
                <li>Abra o banco novo → Importar → escolha o arquivo .sql → Executar.</li>
                <li>Ou, no Prompt de Comando: <code>C:\xampp\mysql\bin\mysql.exe -u root -p banco_novo &lt; copia.sql</code></li>
                <li>Aponte DB_NAME no .env para o banco novo e confira os totais acima.</li>
            </ol>
            <p>A cópia não apaga tabelas: importar sobre um banco que já tem dados falha em vez de sobrescrever.</p>
        </details>
    </section>

    <section class="adm-card adm-panel" aria-labelledby="titulo-limpeza">
        <div class="adm-form-section__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--amber"><?= icon('wrench') ?></span>
            <div>
                <h2 id="titulo-limpeza">Limpeza de tentativas de login</h2>
                <p>As tentativas de login só servem para bloquear quem erra a senha muitas vezes em 15 minutos. Registros com mais de <?= $dias ?> dias podem ser apagados.</p>
            </div>
        </div>
        <p class="adm-field__hint"><?= (int)$antigas ?> registro(s) antigo(s) hoje. O histórico de ações, de empréstimos e de configurações nunca é apagado.</p>
        <form method="post" action="<?= e($base) ?>" data-confirm="Apagar as tentativas de login com mais de <?= $dias ?> dias?">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="limpar_tentativas">
            <button class="adm-button adm-button--ghost adm-button--danger-text" type="submit"<?= $antigas ? '' : ' disabled' ?>><?= icon('archive') ?> Apagar tentativas antigas</button>
        </form>
    </section>
</div>

<?php
admin_page_end();
