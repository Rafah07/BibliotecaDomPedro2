<?php
/**
 * Configurações → Manutenção → Diagnóstico do sistema (permissão sistema.manutencao; migração 190).
 *
 * Mostra, em linguagem simples, as condições técnicas do funcionamento: conexão e versão do banco,
 * PHP, migrações pendentes ou interrompidas, tabelas e colunas necessárias, permissões do usuário do
 * banco, integridade, pastas, disco, cópias de segurança e erros técnicos recentes (com o código que
 * a equipe vê na tela). Só leitura. Funciona também com o banco fora do ar (mostra o que não depende dele).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/configuracoes.php';
require_once __DIR__ . '/includes/backup.php';

use Biblioteca\Services\DiagnosticoCompleto;
use Biblioteca\Support\RegistroErros;

$usuario = auth_exigir('sistema.manutencao');
$pdo = null;
try {
    $pdo = db_modo() === 'mysql' ? db() : null;
} catch (Throwable) {
    $pdo = null; // banco fora do ar: as verificações independentes continuam
}
$diag = new DiagnosticoCompleto($pdo, realpath(APP_RAIZ) ?: APP_RAIZ, backup_pasta());
$secoes = $diag->verificar();
$erros = RegistroErros::recentes(20);
$contagem = ['ok' => 0, 'aviso' => 0, 'erro' => 0, 'nao_verificado' => 0];
foreach ($secoes as $itens) {
    foreach ($itens as $it) {
        $contagem[$it['situacao']]++;
    }
}
$icones = ['ok' => 'circle-check', 'aviso' => 'triangle-alert', 'erro' => 'circle-alert', 'nao_verificado' => 'circle-help'];
$operacoes = [];

admin_page_start('diagnostico', 'Diagnóstico do sistema', 'Configurações', 'Manutenção → Diagnóstico', array_merge(CONFIG_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Configurações', admin_url('configuracoes')], ['Manutenção', admin_url('manutencao')], ['Diagnóstico']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Diagnóstico do sistema</h1>
        <p>Verificado agora (<?= e(date('d/m/Y H:i')) ?>): <?= $contagem['ok'] ?> normal · <?= $contagem['aviso'] ?> com atenção · <?= $contagem['erro'] ?> com problema · <?= $contagem['nao_verificado'] ?> não verificado(s).
            Nada é alterado por esta página.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('diagnostico')) ?>"><?= icon('refresh-cw') ?> Verificar de novo</a>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('manutencao')) ?>"><?= icon('database-backup') ?> Manutenção e backups</a>
    </div>
</section>

<?php if (!$pdo): ?>
    <?= admin_aviso('erro', 'O banco de dados não está respondendo.', 'As verificações que dependem dele aparecem como “Não verificado”. Ligue o MySQL/MariaDB no painel do XAMPP e clique em “Verificar de novo”.') ?>
<?php endif; ?>

<?php foreach ($secoes as $titulo => $itens): ?>
    <section class="adm-card adm-panel" aria-label="<?= e($titulo) ?>">
        <h2 class="adm-panel__title"><?= e($titulo) ?></h2>
        <ul class="adm-diag">
            <?php foreach ($itens as $it): ?>
                <li class="is-<?= e($it['situacao']) ?>">
                    <?= icon($icones[$it['situacao']]) ?>
                    <strong><?= e($it['titulo']) ?> <span class="adm-visually-hidden">(<?= e(DiagnosticoCompleto::ROTULOS[$it['situacao']]) ?>)</span></strong>
                    <span><?= e($it['detalhe']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-erros">
    <div class="adm-panel__header"><div><h2 id="titulo-erros">Registro técnico dos erros</h2>
        <p>Quando algo falha, a equipe vê uma mensagem simples com um código (ex.: ERR-20261118-1a2b3c). Aqui fica o detalhe técnico protegido, sem senhas nem consultas SQL.</p></div></div>
    <?php if (!$erros): ?>
        <p class="adm-field__hint">Nenhum erro registrado nos últimos dois meses.</p>
    <?php else: ?>
        <div class="adm-table-wrap">
            <table class="adm-table adm-table--compact">
                <thead><tr><th scope="col">Código</th><th scope="col">Data</th><th scope="col">Operação</th><th scope="col">Arquivo</th><th scope="col">Código técnico</th><th scope="col">Detalhe</th></tr></thead>
                <tbody>
                <?php foreach ($erros as $er): ?>
                    <tr>
                        <td data-label="Código" class="adm-mono"><?= e((string)($er['id'] ?? '')) ?></td>
                        <td data-label="Data"><?= e(isset($er['data']) ? date('d/m/Y H:i', strtotime($er['data'])) : '') ?></td>
                        <td data-label="Operação"><?= e((string)($er['operacao'] ?? '')) ?><?= !empty($er['operacao_chave']) ? '<small class="adm-block-muted">operação ' . e(substr((string)$er['operacao_chave'], 0, 12)) . '…</small>' : '' ?></td>
                        <td data-label="Arquivo" class="adm-mono"><?= e((string)($er['arquivo'] ?? '')) ?> <small class="adm-block-muted"><?= e((string)($er['pagina'] ?? '')) ?></small></td>
                        <td data-label="Código técnico" class="adm-mono"><?= e((string)($er['codigo'] ?? '')) ?><?= !empty($er['conexao_perdida']) ? ' <span class="adm-status adm-tone--amber">conexão perdida</span>' : '' ?></td>
                        <td data-label="Detalhe"><?= e(RegistroErros::limpar((string)($er['mensagem'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="adm-card adm-panel" aria-labelledby="titulo-comandos">
    <h2 class="adm-panel__title" id="titulo-comandos">Comandos de manutenção (Prompt de Comando, na pasta do sistema)</h2>
    <ul class="adm-versao-diff">
        <li><code>C:\xampp\php\php.exe database/migrar.php --verificar</code> — confere conexão, permissões e migrações pendentes ou parcialmente aplicadas, sem mudar nada.</li>
        <li><code>C:\xampp\php\php.exe database/migrar.php</code> — faz a cópia de segurança e aplica as migrações pendentes (retoma com segurança uma migração interrompida).</li>
        <li><code>C:\xampp\php\php.exe database/analisar-qualidade.php</code> — atualiza a Central de Qualidade do Acervo (pode ser agendada para a noite).</li>
    </ul>
</section>

<?php
admin_page_end();
