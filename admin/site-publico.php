<?php
/**
 * Textos institucionais do site público: apresentação, espaço, objetivos, horários, contato,
 * regras e créditos. Campos vazios aparecem no site como "aguardando confirmação da escola".
 * Somente administrador e bibliotecária (permissão site.editar).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\SiteConfiguracaoRepository;
use Biblioteca\Services\SiteConteudo;
use Biblioteca\Support\RegrasCirculacao;

$usuario = fisico_iniciar('site.editar');
$repo = new SiteConfiguracaoRepository(db());
$base = admin_url('site-publico');
$erros = [];
$falha = null;

try {
    $valores = $repo->todos();
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir os textos do site', fisico_erro_tecnico($e, 'textos do site público (aplique a migração 080 com php database/migrar.php)'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    [$novos, $erros] = SiteConteudo::validar($_POST);
    $valores = $novos + $valores;
    if (!$erros) {
        try {
            $alteradas = $repo->salvar($novos, $usuario['id']);
            if ($alteradas) {
                registrar_acao('site.editar', 'site', null, ['campos' => $alteradas]);
                $nomes = array_map(fn ($c) => SiteConteudo::CAMPOS[$c]['rotulo'], $alteradas);
                admin_flash('sucesso', 'Textos do site salvos.', 'Campos alterados: ' . implode(', ', $nomes) . '.');
            } else {
                admin_flash('aviso', 'Nenhuma alteração', 'Os textos enviados são iguais aos que já estavam salvos.');
            }
            admin_redirecionar($base);
        } catch (PDOException $e) {
            error_log('[biblioteca] Erro ao salvar textos do site: ' . $e->getMessage());
            $falha = 'Não foi possível salvar. Tente novamente; se o problema continuar, avise o responsável pelo sistema.';
        }
    }
}

$regras = RegrasCirculacao::doAmbiente('env');
$grupos = [];
foreach (SiteConteudo::CAMPOS as $chave => $campo) {
    $grupos[$campo['grupo']][$chave] = $campo;
}

admin_page_start('site-publico', 'Site público', 'Site público', 'Textos institucionais exibidos aos alunos e visitantes', FISICO_CSS);
?>

<section class="adm-page-head">
    <div>
        <h1>Textos do site público</h1>
        <p>Preencha somente informações oficiais, confirmadas pela escola. Campos vazios aparecem no site como "Informação aguardando confirmação da escola".</p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e(url('biblioteca_php/sobre.php')) ?>" target="_blank" rel="noopener"><?= icon('external-link') ?> Ver página Sobre</a>
        <a class="adm-button adm-button--ghost" href="<?= e(url('biblioteca_php/regras.php')) ?>" target="_blank" rel="noopener"><?= icon('external-link') ?> Ver página Regras</a>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($falha): ?><?= admin_aviso('erro', 'Os textos não foram salvos.', $falha) ?><?php endif; ?>
<?php if ($erros): ?><?= admin_aviso('erro', 'Confira os campos destacados.', 'Nenhuma alteração foi salva.') ?><?php endif; ?>

<div class="adm-notice adm-notice--blue" role="note">
    <?= icon('info') ?>
    <div>
        <strong>Formatação simples</strong>
        <p>Deixe uma linha em branco para começar outro parágrafo. Linhas que começam com "- " viram uma lista. Não use dados pessoais de alunos ou de funcionários.</p>
    </div>
</div>

<form method="post" action="<?= e($base) ?>" data-loading-form>
    <?= csrf_campo() ?>
    <?php foreach ($grupos as $grupo => $campos): ?>
        <section class="adm-card adm-panel adm-form-section">
            <h2 class="adm-panel__title"><?= e($grupo) ?></h2>
            <div class="adm-form-grid">
                <?php foreach ($campos as $chave => $campo): ?>
                    <?php $valor = (string)($valores[$chave] ?? ''); $erro = $erros[$chave] ?? null; ?>
                    <?php if ($campo['tipo'] === 'opcao'): ?>
                        <div class="adm-field adm-field--full">
                            <label class="adm-check-confirm"><input type="checkbox" name="<?= e($chave) ?>" value="1"<?= $valor === '1' ? ' checked' : '' ?>> <span><?= e($campo['rotulo']) ?></span></label>
                            <span class="adm-field__hint"><?= e($campo['ajuda']) ?>
                                <?= $regras->prazoProvisorio ? ' Hoje o prazo NÃO está configurado (o sistema usa 14 dias provisórios), então nada será exibido.' : ' Prazo configurado hoje: ' . (int)$regras->prazoDias . ' dias.' ?></span>
                        </div>
                    <?php else: ?>
                        <label class="adm-field adm-field--full">
                            <span class="adm-field__label"><?= e($campo['rotulo']) ?></span>
                            <?php if ($campo['tipo'] === 'texto'): ?>
                                <textarea class="adm-textarea" name="<?= e($chave) ?>" rows="<?= $campo['max'] > 1500 ? 6 : 3 ?>" maxlength="<?= $campo['max'] ?>"<?= $erro ? ' aria-invalid="true"' : '' ?>><?= e($valor) ?></textarea>
                            <?php else: ?>
                                <input class="adm-input" type="<?= $campo['tipo'] === 'email' ? 'email' : 'text' ?>" name="<?= e($chave) ?>" maxlength="<?= $campo['max'] ?>" value="<?= e($valor) ?>"<?= $erro ? ' aria-invalid="true"' : '' ?>>
                            <?php endif; ?>
                            <?php if ($campo['ajuda'] !== ''): ?><span class="adm-field__hint"><?= e($campo['ajuda']) ?></span><?php endif; ?>
                            <?php if ($erro): ?><span class="adm-field__error"><?= e($erro) ?></span><?php endif; ?>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar textos do site</button>
</form>

<?php
admin_page_end(FISICO_JS);
