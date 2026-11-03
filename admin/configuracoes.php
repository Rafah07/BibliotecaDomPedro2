<?php
/**
 * Sistema → Configurações: identificação, regras de empréstimo, cadastro de alunos, segurança do
 * painel e site em manutenção. Valores salvos aqui valem mais que o .env; campo vazio volta a usar
 * o .env (ou o padrão). Cada alteração fica no histórico (migração 090).
 *
 * Permissões: configuracoes.ver (abrir), configuracoes.editar (identificação, empréstimos, alunos),
 * sistema.manutencao (segurança, manutenção e configurações técnicas do .env).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/configuracoes.php';
require_once __DIR__ . '/includes/emprestimos.php'; // emprestimos_regras_texto

use Biblioteca\Support\ConfiguracaoSistema;
use Biblioteca\Support\RegrasCirculacao;

$usuario = config_iniciar('configuracoes.ver');
$base = admin_url('configuracoes');
$erros = [];
$falha = null;
$enviados = [];

try {
    $salvas = config_repo()->salvas();
    $historico = config_repo()->historico(30);
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir as configurações', config_erro_tecnico($e, 'configurações (aplique a migração 090 com php database/migrar.php)'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    if (!auth_pode('configuracoes.editar') && !auth_pode('sistema.manutencao')) {
        http_response_code(403);
        admin_pagina_erro('Acesso não permitido', 'Seu perfil pode ver as configurações, mas não alterá-las.', 'lock');
        exit;
    }
    [$enviados, $erros] = ConfiguracaoSistema::validar($_POST, 'auth_pode', 'config_valor');
    if (!$erros) {
        try {
            $alteradas = config_repo()->salvar($enviados, $usuario['id'], (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
            config_banco(true);
            if ($alteradas) {
                registrar_acao('configuracao.editar', 'configuracao', null, ['campos' => $alteradas]);
                $nomes = array_map(fn ($c) => ConfiguracaoSistema::CAMPOS[$c]['rotulo'], $alteradas);
                admin_flash('sucesso', 'Configurações salvas.', 'Alteradas: ' . implode('; ', $nomes) . '. Valem a partir de agora para novas operações.');
            } else {
                admin_flash('aviso', 'Nenhuma alteração', 'Os valores enviados são iguais aos que já estavam em uso.');
            }
            admin_redirecionar($base);
        } catch (PDOException $e) {
            $falha = config_erro_tecnico($e, 'salvar configurações');
        }
    }
}

$regras = RegrasCirculacao::doAmbiente('config_valor');
$campos = [];
foreach (ConfiguracaoSistema::CAMPOS as $chave => $campo) {
    $campos[$campo['grupo']][$chave] = $campo;
}
$podeAlgum = auth_pode('configuracoes.editar') || auth_pode('sistema.manutencao');
$siteEmManutencao = config_bool('SITE_MANUTENCAO', false);

admin_page_start('configuracoes', 'Configurações', 'Configurações', 'Regras de funcionamento da biblioteca', CONFIG_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Configurações']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Configurações gerais</h1>
        <p>Regras de funcionamento da biblioteca. O que for salvo aqui vale mais que o arquivo .env; deixe um campo vazio para voltar ao valor do .env ou ao padrão do sistema.</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('permissoes')) ?>"><?= icon('shield-check') ?> Perfis e permissões</a>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('ajuda')) ?>"><?= icon('circle-help') ?> Tutorial do Sistema</a>
        <?php if (auth_pode('sistema.manutencao')): ?>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('manutencao')) ?>"><?= icon('wrench') ?> Manutenção</a>
        <?php endif; ?>
        <?php if (auth_pode('site.editar')): ?>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('site-publico')) ?>"><?= icon('globe') ?> Textos do site</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($falha): ?><?= admin_aviso('erro', 'As configurações não foram salvas.', $falha) ?><?php endif; ?>
<?php if ($erros): ?><?= admin_aviso('erro', 'Confira os campos destacados.', 'Nenhuma alteração foi salva.') ?><?php endif; ?>
<?php if ($siteEmManutencao): ?>
    <?= admin_aviso('aviso', 'O site público está em manutenção.', 'Os visitantes veem só a mensagem de manutenção. Para reabrir, altere "Colocar o site público em manutenção" abaixo.') ?>
<?php endif; ?>

<section class="adm-card adm-panel adm-config-resumo" aria-labelledby="titulo-resumo">
    <div class="adm-form-section__header">
        <span class="adm-icon-box adm-icon-box--sm adm-tone--<?= $regras->prazoProvisorio ? 'amber' : 'green' ?>"><?= icon('book-open-check') ?></span>
        <div>
            <h2 id="titulo-resumo">Regras de empréstimo em uso agora</h2>
            <p><?= e(emprestimos_regras_texto()) ?></p>
        </div>
    </div>
    <?php if ($regras->prazoProvisorio): ?>
        <p class="adm-field__hint">O prazo de 14 dias é provisório: a escola ainda não aprovou um regulamento. Preencha o prazo oficial em "Regras de empréstimo" quando ele for definido.</p>
    <?php endif; ?>
</section>

<form method="post" action="<?= e($base) ?>" data-loading-form novalidate>
    <?= csrf_campo() ?>
    <?php foreach (ConfiguracaoSistema::GRUPOS as $grupo => $info): ?>
        <?php if (empty($campos[$grupo])) continue; ?>
        <?php $podeGrupo = auth_pode(reset($campos[$grupo])['permissao']); ?>
        <section class="adm-card adm-panel adm-form-section adm-config-grupo" aria-labelledby="grupo-<?= e($grupo) ?>" id="<?= e($grupo) ?>">
            <div class="adm-form-section__header">
                <span class="adm-icon-box adm-icon-box--sm adm-tone--blue"><?= icon($info['icone']) ?></span>
                <div>
                    <h2 id="grupo-<?= e($grupo) ?>"><?= e($info['titulo']) ?></h2>
                    <p><?= e($info['descricao']) ?></p>
                </div>
            </div>
            <?php if (!$podeGrupo): ?>
                <p class="adm-config-bloqueado"><?= icon('lock') ?> Somente leitura para o seu perfil.</p>
            <?php endif; ?>
            <div class="adm-config-campos">
                <?php foreach ($campos[$grupo] as $chave => $campo): ?>
                    <?php
                    $pode = auth_pode($campo['permissao']);
                    $valor = array_key_exists($chave, $enviados) ? $enviados[$chave] : (string)($salvas[$chave]['valor'] ?? '');
                    $erro = $erros[$chave] ?? null;
                    $id = 'cfg-' . strtolower($chave);
                    $desab = $pode ? '' : ' disabled';
                    ?>
                    <div class="adm-field adm-config-campo<?= $erro ? ' is-invalid' : '' ?>">
                        <label class="adm-field__label" for="<?= e($id) ?>"><?= e($campo['rotulo']) ?></label>
                        <?php if ($campo['tipo'] === 'opcao'): ?>
                            <select class="adm-select" id="<?= e($id) ?>" name="<?= e($chave) ?>"<?= $desab ?>>
                                <option value=""<?= $valor === '' ? ' selected' : '' ?>>Não definir aqui (<?= e(config_valor_base($chave)) ?>)</option>
                                <option value="1"<?= $valor === '1' ? ' selected' : '' ?>>Sim</option>
                                <option value="0"<?= $valor === '0' ? ' selected' : '' ?>>Não</option>
                            </select>
                        <?php elseif ($campo['tipo'] === 'texto'): ?>
                            <textarea class="adm-textarea" id="<?= e($id) ?>" name="<?= e($chave) ?>" rows="3" maxlength="<?= (int)$campo['max'] ?>" placeholder="<?= e(config_valor_base($chave)) ?>"<?= $desab ?><?= $erro ? ' aria-invalid="true"' : '' ?>><?= e($valor) ?></textarea>
                        <?php else: ?>
                            <input class="adm-input" id="<?= e($id) ?>" name="<?= e($chave) ?>" value="<?= e($valor) ?>" placeholder="<?= e(config_valor_base($chave)) ?>"
                                <?= $campo['tipo'] === 'inteiro' ? 'type="number" inputmode="numeric" min="' . (int)$campo['min'] . '" max="' . (int)$campo['max'] . '" step="1"' : 'type="text" maxlength="' . (int)$campo['max'] . '"' ?><?= $desab ?><?= $erro ? ' aria-invalid="true"' : '' ?>>
                        <?php endif; ?>
                        <span class="adm-config-uso">Em uso: <strong><?= e(config_em_uso($chave)) ?></strong> <?= config_origem_html(config_origem($chave)) ?></span>
                        <?php if ($campo['ajuda'] !== ''): ?><span class="adm-field__hint"><?= e($campo['ajuda']) ?></span><?php endif; ?>
                        <?php if (isset($salvas[$chave])): ?>
                            <span class="adm-field__hint">Salvo no painel em <?= e(admin_data_hora($salvas[$chave]['atualizado_em'])) ?><?= $salvas[$chave]['atualizado_por_nome'] ? ' por ' . e($salvas[$chave]['atualizado_por_nome']) : '' ?>.</span>
                        <?php endif; ?>
                        <?php if ($erro): ?><span class="adm-field__error"><?= e($erro) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if ($podeAlgum): ?>
        <div class="adm-config-acoes">
            <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar configurações</button>
            <span class="adm-field__hint">Só os campos alterados são gravados. Empréstimos já registrados não mudam de data.</span>
        </div>
    <?php endif; ?>
</form>

<?php if (auth_pode('sistema.manutencao')): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-env">
        <div class="adm-panel__header">
            <div>
                <h2 id="titulo-env">Configurações técnicas (somente no .env)</h2>
                <p>Banco de dados, ambiente, fuso horário, pastas e fontes CDD. Um valor errado aqui pode tirar o sistema do ar, por isso só são alteradas no arquivo .env, pelo responsável técnico. Senhas nunca são exibidas.</p>
            </div>
        </div>
        <div class="adm-table-wrap adm-table-wrap--flush">
            <table class="adm-table adm-table--cards">
                <thead><tr><th scope="col">Configuração</th><th scope="col">Variável</th><th scope="col">Valor</th></tr></thead>
                <tbody>
                    <?php foreach (CONFIG_SO_ENV as $chave => $info): ?>
                        <tr>
                            <td class="adm-td-title"><?= e($info['rotulo']) ?></td>
                            <td data-label="Variável" class="adm-mono"><?= e($chave) ?></td>
                            <td data-label="Valor" class="adm-td-wrap"><?= e(config_env_exibir($chave)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="adm-card adm-panel" aria-labelledby="titulo-historico">
    <div class="adm-panel__header">
        <div>
            <h2 id="titulo-historico">Histórico de alterações</h2>
            <p>As 30 alterações mais recentes. O histórico não pode ser editado nem apagado.</p>
        </div>
    </div>
    <div class="adm-table-wrap adm-table-wrap--flush">
        <table class="adm-table adm-table--cards">
            <thead><tr><th scope="col">Data</th><th scope="col">Configuração</th><th scope="col">Antes</th><th scope="col">Depois</th><th scope="col">Por</th></tr></thead>
            <tbody>
                <?php foreach ($historico as $h): ?>
                    <tr>
                        <td class="adm-table__muted"><?= e(admin_data_hora($h['criado_em'])) ?></td>
                        <td data-label="Configuração" class="adm-td-wrap"><?= e(ConfiguracaoSistema::CAMPOS[$h['chave']]['rotulo'] ?? $h['chave']) ?></td>
                        <td data-label="Antes" class="adm-td-wrap"><?= $h['valor_anterior'] === null ? '<span class="adm-table__muted">.env / padrão</span>' : e(mb_strimwidth(ConfiguracaoSistema::exibir($h['chave'], $h['valor_anterior']), 0, 80, '…')) ?></td>
                        <td data-label="Depois" class="adm-td-wrap"><?= $h['valor_novo'] === null ? '<span class="adm-table__muted">.env / padrão</span>' : e(mb_strimwidth(ConfiguracaoSistema::exibir($h['chave'], $h['valor_novo']), 0, 80, '…')) ?></td>
                        <td data-label="Por"><?= e($h['usuario_nome'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$historico): ?>
                    <tr data-empty><td colspan="5"><div class="adm-empty"><?= icon('history') ?><strong>Nenhuma alteração registrada</strong><span>Até agora, tudo vem do .env ou do padrão do sistema.</span></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_page_end();
