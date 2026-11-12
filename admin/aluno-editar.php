<?php
/**
 * Cadastro individual (sem id) e correção (com id) de um aluno. Exige alunos.editar.
 * Validação: as mesmas regras da importação (AlunoCadastroService → AlunoImportService).
 * O id interno nunca muda; os empréstimos continuam ligados ao mesmo aluno.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/alunos.php';

use Biblioteca\Services\AlunoCadastroException;
use Biblioteca\Services\AlunoImportService;

$usuario = alunos_iniciar('alunos.editar');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0) ?: null;
$erros = [];
$duplicidades = [];
$mensagemErro = null;
try {
    $atual = $id ? alunos_repo()->buscar($id) : null;
    $resumoCirculacao = $atual ? emprestimos_repo()->resumoAluno($id, emprestimos_hoje()) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir o cadastro', alunos_erro_tecnico($e, 'cadastro de aluno'));
    exit;
}
if ($id && !$atual) {
    http_response_code(404);
    admin_pagina_erro('Aluno não encontrado', 'O cadastro solicitado não existe. Volte à lista de alunos e pesquise pela matrícula.', 'graduation-cap');
    exit;
}
$valores = [];
foreach (['nome', 'matricula', 'turma', 'turno', 'email'] as $c) {
    $valores[$c] = (string)($atual[$c] ?? '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $valores = alunos_valores_post($_POST);
    $confirmarDup = admin_param($_POST, 'confirmar_duplicidade') === '1';
    try {
        if ($id) {
            $campos = alunos_cadastro_servico()->editar($id, $valores, $usuario['id'], $confirmarDup, admin_param($_POST, 'confirmar_matricula') === '1');
            if ($campos) {
                admin_flash('sucesso', 'Cadastro corrigido.', 'Campos alterados: ' . implode(', ', array_map(fn ($c) => Biblioteca\Services\AlunoCadastroService::CAMPOS[$c], $campos)) . '. Os empréstimos continuam ligados a este aluno.');
            } else {
                admin_flash('aviso', 'Nada foi alterado.', 'Os dados enviados são iguais aos do cadastro.');
            }
            admin_redirecionar(admin_url('aluno') . '?id=' . $id);
        }
        $novo = alunos_cadastro_servico()->cadastrar($valores, $usuario['id'], $confirmarDup);
        admin_flash('sucesso', 'Aluno cadastrado.', 'O aluno já pode fazer empréstimos.');
        admin_redirecionar(admin_url('aluno') . '?id=' . $novo);
    } catch (AlunoCadastroException $e) {
        $erros = $e->erros;
        $duplicidades = $e->duplicidades;
        $mensagemErro = $e->getMessage();
    } catch (PDOException $e) {
        $mensagemErro = alunos_erro_tecnico($e, 'gravação de aluno');
    }
}

$dominio = alunos_importacao_servico()->dominioEmail();
$titulo = $id ? 'Editar cadastro do aluno' : 'Cadastrar aluno';
$erro = fn (string $campo) => isset($erros[$campo]) ? '<span class="adm-field__error">' . e($erros[$campo]) . '</span>' : '';
$inv = fn (string $campo) => isset($erros[$campo]) ? ' is-invalid' : '';
$matriculaMudou = $id && isset($erros['confirmar_matricula']);

admin_page_start('alunos', $titulo, 'Alunos', 'Cadastro dos alunos da biblioteca', ALUNOS_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Alunos', admin_url('alunos')], [$titulo]]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= e($titulo) ?></h1>
        <p><?= $id ? 'Corrija os dados com erro. O aluno continua sendo o mesmo: empréstimos, devoluções e renovações ficam ligados a ele.' : 'Use para incluir um aluno fora da importação. As regras são as mesmas da planilha: a matrícula não pode se repetir.' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e($id ? admin_url('aluno') . '?id=' . $id : admin_url('alunos')) ?>"><?= icon('arrow-left') ?> Voltar</a>
    </div>
</section>

<?php if ($mensagemErro): ?><?= admin_aviso('erro', $id ? 'O cadastro não foi corrigido.' : 'O aluno não foi cadastrado.', $mensagemErro . (isset($erros['geral']) ? ' ' . $erros['geral'] : '')) ?><?php endif; ?>

<form method="post" class="adm-card adm-panel adm-form-section" data-loading-form novalidate>
    <?= csrf_campo() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
    <div class="adm-form-grid">
        <label class="adm-field adm-field--full<?= $inv('nome') ?>"><span class="adm-field__label">Nome <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input" name="nome" maxlength="150" required value="<?= e($valores['nome']) ?>" autocomplete="off"<?= $id ? '' : ' autofocus' ?>>
            <?= $erro('nome') ?></label>
        <label class="adm-field<?= $inv('matricula') ?>"><span class="adm-field__label">Matrícula <span class="adm-required" aria-hidden="true">*</span></span>
            <input class="adm-input adm-mono" name="matricula" maxlength="40" required value="<?= e($valores['matricula']) ?>" autocomplete="off">
            <span class="adm-field__hint">Letras, números, ponto, hífen, barra ou sublinhado, sem espaços. Não se repete.</span>
            <?= $erro('matricula') ?></label>
        <label class="adm-field<?= $inv('email') ?>"><span class="adm-field__label">E-mail institucional <span class="adm-optional">(opcional)</span></span>
            <input class="adm-input" type="email" name="email" maxlength="190" value="<?= e($valores['email']) ?>" autocomplete="off" placeholder="<?= e($dominio ? 'nome@' . $dominio : 'nome@escola.edu.br') ?>">
            <?php if ($dominio): ?><span class="adm-field__hint">Só e-mails @<?= e($dominio) ?> (definido em Sistema → Configurações).</span><?php endif; ?>
            <?= $erro('email') ?></label>
        <label class="adm-field<?= $inv('turma') ?>"><span class="adm-field__label">Turma <span class="adm-optional">(opcional)</span></span>
            <input class="adm-input" name="turma" maxlength="40" value="<?= e($valores['turma']) ?>" placeholder="Ex.: 7º A" autocomplete="off">
            <?= $erro('turma') ?></label>
        <label class="adm-field<?= $inv('turno') ?>"><span class="adm-field__label">Turno <span class="adm-optional">(opcional)</span></span>
            <select class="adm-select" name="turno">
                <option value="">Não informado</option>
                <?php foreach (AlunoImportService::TURNOS as $valor => $rotulo): ?><option value="<?= e($valor) ?>"<?= $valores['turno'] === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?>
            </select>
            <?= $erro('turno') ?></label>
    </div>

    <?php if ($id && $resumoCirculacao && (int)$resumoCirculacao['total'] > 0): ?>
        <?= admin_aviso('info', 'Este aluno tem ' . (int)$resumoCirculacao['total'] . ' empréstimo(s) registrado(s).', 'A correção não muda o histórico: os empréstimos continuam ligados a este mesmo cadastro, inclusive se a matrícula for corrigida.') ?>
    <?php endif; ?>

    <?php if ($matriculaMudou): ?>
        <div class="adm-notice adm-notice--amber" role="alert"><?= icon('triangle-alert') ?><div class="adm-notice__content">
            <strong>A matrícula será trocada de <?= e($atual['matricula']) ?> para <?= e(trim($valores['matricula'])) ?>.</strong>
            <p><?= e($erros['confirmar_matricula']) ?></p>
            <label class="adm-check-confirm"><input type="checkbox" name="confirmar_matricula" value="1"> <span>Conferi com a secretaria: a matrícula correta é a nova.</span></label>
        </div></div>
    <?php elseif ($id && admin_param($_POST, 'confirmar_matricula') === '1'): ?>
        <input type="hidden" name="confirmar_matricula" value="1">
    <?php endif; ?>

    <?php if ($duplicidades): ?>
        <div class="adm-notice adm-notice--amber" role="alert"><?= icon('triangle-alert') ?><div class="adm-notice__content">
            <strong>Possível duplicidade: já existe aluno com o mesmo nome ou e-mail.</strong>
            <p>Nomes iguais não significam a mesma pessoa. Confira matrícula e turma; se for outro aluno, confirme abaixo.</p>
            <ul class="adm-dupe-list">
                <?php foreach ($duplicidades as $d): ?>
                    <li><?= e($d['nome']) ?> · Matrícula <?= e($d['matricula']) ?><?= $d['turma'] ? ' · Turma ' . e($d['turma']) : '' ?><?= !(int)$d['ativo'] ? ' · cadastro inativo' : '' ?></li>
                <?php endforeach; ?>
            </ul>
            <label class="adm-check-confirm"><input type="checkbox" name="confirmar_duplicidade" value="1"> <span>Conferi os cadastros acima e confirmo que se trata de outro aluno.</span></label>
        </div></div>
    <?php endif; ?>

    <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> <?= $id ? 'Salvar correção' : 'Cadastrar aluno' ?></button>
</form>

<?php
admin_page_end();
