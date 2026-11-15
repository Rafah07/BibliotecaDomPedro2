<?php
/**
 * Formulário "+ Abrir novo lote" (Histórico de Lotes e Conferência). Envia para lotes.php (acao=abrir).
 * Variáveis: $atribuiveis (contas), $formAbrir (valores após erro, ou null), $erroAbrir, $voltarAbrir (opcional).
 * O número do lote e a data de criação são gerados no servidor no momento do envio.
 */

use Biblioteca\Services\LoteService;
use Biblioteca\Services\OperacaoService;

$formAbrir ??= null;
$voltarAbrir ??= '';
$selUsuarios = array_map('intval', is_array($formAbrir['usuarios'] ?? null) ? $formAbrir['usuarios'] : []);
$proximoNumero = (int)db_query('SELECT COALESCE(MAX(numero), 0) + 1 FROM cadastro_lotes')->fetchColumn();
?>
<section class="adm-card adm-panel adm-abrir-lote" aria-labelledby="titulo-abrir-lote" id="abrir-lote">
    <h2 id="titulo-abrir-lote" class="adm-panel__title"><?= icon('plus') ?> Abrir novo lote</h2>
    <?php if (!empty($erroAbrir)): ?><?= admin_aviso('erro', 'O lote não foi aberto.', $erroAbrir) ?><?php endif; ?>
    <form method="post" action="<?= e(admin_url('lotes')) ?>" data-enviar-uma-vez>
        <?= csrf_campo() ?><input type="hidden" name="acao" value="abrir"><input type="hidden" name="chave" value="<?= e(OperacaoService::novaChave()) ?>">
        <input type="hidden" name="voltar" value="<?= e($voltarAbrir) ?>">
        <div class="adm-form-grid">
            <div class="adm-field"><span class="adm-field__sublabel">Número do lote</span>
                <p class="adm-lote-numero">Lote <?= e(str_pad((string)$proximoNumero, 3, '0', STR_PAD_LEFT)) ?> <small class="adm-table__muted">(gerado automaticamente ao abrir; se outra pessoa abrir um lote antes, você recebe o seguinte)</small></p></div>
            <div class="adm-field"><span class="adm-field__sublabel">Data de criação</span><p class="adm-lote-numero"><?= e(date('d/m/Y')) ?> <small class="adm-table__muted">(registrada no momento da abertura)</small></p></div>
            <label class="adm-field"><span class="adm-field__sublabel">Quantidade máxima de exemplares *</span>
                <input class="adm-input" type="number" name="capacidade" min="1" max="<?= LoteService::CAPACIDADE_MAXIMA ?>" required value="<?= e((string)($formAbrir['capacidade'] ?? '30')) ?>">
                <span class="adm-field__hint">Contada por exemplar físico: 3 exemplares de Dom Casmurro ocupam 3 vagas. Ao completar, o lote fecha sozinho.</span></label>
            <label class="adm-field"><span class="adm-field__sublabel">Nome ou observação <span class="adm-optional">(opcional)</span></span>
                <input class="adm-input" name="descricao" maxlength="255" value="<?= e((string)($formAbrir['descricao'] ?? '')) ?>" placeholder="Ex.: Literatura brasileira — estante A"></label>
            <fieldset class="adm-field adm-field--full"><legend class="adm-field__sublabel">Usuários responsáveis pelo cadastro *</legend>
                <div class="adm-lote-usuarios">
                    <?php foreach ($atribuiveis as $a): ?>
                        <label class="adm-check"><input type="checkbox" name="usuarios[]" value="<?= (int)$a['id'] ?>"<?= in_array((int)$a['id'], $selUsuarios, true) ? ' checked' : '' ?>> <span><?= e($a['nome']) ?> <small class="adm-table__muted"><?= e(AUTH_PERFIS[$a['perfil']] ?? $a['perfil']) ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <span class="adm-field__hint">Escolha um ou mais. A mesma pessoa pode participar de vários lotes. Só os usuários do lote podem registrar exemplares nele.</span></fieldset>
        </div>
        <div class="adm-form-actions adm-form-actions--static">
            <button class="adm-button adm-button--primary" type="submit"><?= icon('plus') ?> Abrir lote</button>
        </div>
    </form>
</section>
