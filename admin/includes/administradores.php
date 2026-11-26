<?php
/**
 * Gerenciamento das contas de acesso ao painel. Desde a migração 200 há um único perfil:
 * toda conta é de administrador, com acesso total. Só funciona com o banco configurado e exige a
 * permissão usuarios.gerenciar, conferida no servidor em cada página.
 *
 * O cadastro rápido (nome, e-mail, função opcional e senha inicial) aparece no topo de
 * Administradores e em Meu perfil; os dois usam administradores_cadastrar_rapido().
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php'; // acervo_breadcrumb

use Biblioteca\Repositories\AdministradorRepository;

/** Texto curto sobre o acesso das contas (único perfil). */
const ADMINISTRADORES_ACESSO = 'Acesso total ao painel: acervo, exemplares, empréstimos, alunos, inventário, relatórios, configurações, cópias de segurança e cadastro de outros administradores.';

/** Tamanho máximo da função (coluna administradores.cargo). */
const ADMINISTRADORES_FUNCAO_MAX = 100;

function administradores_repo(): AdministradorRepository
{
    static $r = null;
    return $r ??= new AdministradorRepository(db());
}

/** Início das páginas: banco obrigatório e permissão conferida no servidor. */
function administradores_iniciar(): array
{
    $usuario = auth_exigir('usuarios.gerenciar');
    if (db_modo() !== 'mysql') {
        admin_pagina_erro('Disponível só com o banco de dados', 'No modo demonstrativo as contas são fixas. Configure o MySQL para gerenciar os acessos.', 'users');
        exit;
    }
    return $usuario;
}

function administradores_filtros(array $get): array
{
    $ordem = admin_param($get, 'ordem', 'nome');
    $porPagina = (int)admin_param($get, 'por_pagina', '25');
    return [
        'q' => mb_substr(admin_param($get, 'q'), 0, 100),
        'status' => in_array(admin_param($get, 'status'), ['ativo', 'inativo'], true) ? admin_param($get, 'status') : '',
        'ordem' => in_array($ordem, ['nome', 'email', 'criado', 'acesso'], true) ? $ordem : 'nome',
        'dir' => admin_param($get, 'dir') === 'desc' ? 'desc' : 'asc',
        'por_pagina' => in_array($porPagina, [25, 50, 100], true) ? (string)$porPagina : '25',
    ];
}

/**
 * Valida os dados da conta. $id null = nova conta (a senha inicial é obrigatória).
 * $exigirConfirmacao = false no cadastro rápido, em que a senha fica visível para ser conferida.
 * Retorna [dados limpos, erros por campo].
 */
function administradores_validar(array $post, ?int $id, bool $exigirConfirmacao = true): array
{
    $dados = [
        'nome' => trim(preg_replace('/\s+/u', ' ', admin_param($post, 'nome'))),
        'email' => mb_strtolower(admin_param($post, 'email')),
        'cargo' => trim(preg_replace('/\s+/u', ' ', admin_param($post, 'cargo'))),
        'ativo' => admin_param($post, 'ativo', $id === null ? '1' : '0') === '1',
    ];
    $erros = [];
    if (mb_strlen($dados['nome']) < 3) {
        $erros['nome'] = 'Informe o nome completo.';
    } elseif (mb_strlen($dados['nome']) > 150) {
        $erros['nome'] = 'O nome pode ter no máximo 150 caracteres.';
    }
    if (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($dados['email']) > 190) {
        $erros['email'] = 'Informe um e-mail válido.';
    } elseif (administradores_repo()->emailEmUso($dados['email'], $id)) {
        $erros['email'] = 'Já existe uma conta com este e-mail.';
    }
    if (mb_strlen($dados['cargo']) > ADMINISTRADORES_FUNCAO_MAX) {
        $erros['cargo'] = 'A função pode ter no máximo ' . ADMINISTRADORES_FUNCAO_MAX . ' caracteres.';
    }
    if ($id === null) {
        $senha = (string)($post['senha'] ?? '');
        $confirmacao = $exigirConfirmacao ? (string)($post['senha_confirmacao'] ?? '') : $senha;
        $problema = auth_senha_problema($senha, $confirmacao);
        if ($problema) {
            $erros['senha'] = $problema;
        }
    }
    return [$dados, $erros];
}

/**
 * Cadastro rápido de um administrador (POST já conferido com CSRF e usuarios.gerenciar).
 * Retorna ['ok' => bool, 'valores' => campos para reexibir, 'erros' => por campo, 'falha' => ?string].
 * Em caso de sucesso grava o registro de ação e a mensagem de sucesso (flash).
 */
function administradores_cadastrar_rapido(array $post): array
{
    [$dados, $erros] = administradores_validar($post, null, false);
    $valores = ['nome' => $dados['nome'], 'email' => $dados['email'], 'cargo' => $dados['cargo']];
    if ($erros) {
        return ['ok' => false, 'valores' => $valores, 'erros' => $erros, 'falha' => null];
    }
    try {
        $novoId = administradores_repo()->criar($dados['nome'], $dados['email'], $dados['cargo'], password_hash((string)$post['senha'], PASSWORD_DEFAULT));
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) {
            error_log('[biblioteca] Erro ao cadastrar administrador: ' . $e->getMessage());
            return ['ok' => false, 'valores' => $valores, 'erros' => [], 'falha' => 'Não foi possível cadastrar. Tente novamente.'];
        }
        return ['ok' => false, 'valores' => $valores, 'erros' => ['email' => $e->getMessage()], 'falha' => null];
    }
    registrar_acao('usuario.cadastrar', 'administrador', $novoId, ['email' => $dados['email'], 'cargo' => $dados['cargo'] ?: 'Administrador', 'origem' => 'cadastro_rapido']);
    admin_flash('sucesso', 'Administrador cadastrado.', $dados['nome'] . ' já pode entrar no painel com o e-mail ' . $dados['email'] . ' e a senha inicial. Entregue a senha pessoalmente e peça que a troque em "Meu perfil".');
    return ['ok' => true, 'valores' => [], 'erros' => [], 'falha' => null, 'id' => $novoId];
}

/**
 * Formulário do cadastro rápido. $acao = URL que recebe o POST (a própria página).
 * $estado = retorno de administradores_cadastrar_rapido() quando houve erro.
 */
function administradores_form_rapido(string $acao, array $estado = [], bool $compacto = false): string
{
    $v = fn (string $c) => e((string)($estado['valores'][$c] ?? ''));
    $erros = $estado['erros'] ?? [];
    $erro = fn (string $c) => isset($erros[$c]) ? '<span class="adm-field__error">' . e($erros[$c]) . '</span>' : '';
    $inv = fn (string $c) => isset($erros[$c]) ? ' is-invalid' : '';
    ob_start();
    ?>
    <form class="adm-card adm-panel adm-form-section adm-cadastro-rapido" method="post" action="<?= e($acao) ?>" novalidate data-cadastro-rapido>
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="cadastro_rapido">
        <div class="adm-form-section__header">
            <span class="adm-icon-box adm-icon-box--sm adm-tone--indigo"><?= icon('user-plus') ?></span>
            <div>
                <h2 id="titulo-cadastro-rapido">Cadastrar administrador</h2>
                <p>Preencha e pronto: a pessoa já entra no painel com acesso total.</p>
            </div>
        </div>
        <?php if (!empty($estado['falha'])): ?><?= admin_aviso('erro', 'Não foi possível cadastrar.', $estado['falha']) ?><?php endif; ?>
        <?php if ($erros): ?><?= admin_aviso('erro', 'Verifique os campos destacados.') ?><?php endif; ?>
        <div class="adm-form-grid<?= $compacto ? '' : ' adm-cadastro-rapido__grid' ?>">
            <label class="adm-field<?= $compacto ? ' adm-field--full' : '' ?><?= $inv('nome') ?>">
                <span class="adm-field__label">Nome completo <span class="adm-required" aria-hidden="true">*</span></span>
                <input class="adm-input" type="text" name="nome" maxlength="150" required value="<?= $v('nome') ?>" autocomplete="off">
                <?= $erro('nome') ?>
            </label>
            <label class="adm-field<?= $compacto ? ' adm-field--full' : '' ?><?= $inv('email') ?>">
                <span class="adm-field__label">E-mail <span class="adm-required" aria-hidden="true">*</span></span>
                <input class="adm-input" type="email" name="email" maxlength="190" required value="<?= $v('email') ?>" autocomplete="off">
                <?= $erro('email') ?>
            </label>
            <label class="adm-field<?= $compacto ? ' adm-field--full' : '' ?><?= $inv('cargo') ?>">
                <span class="adm-field__label">Função <span class="adm-table__muted">(opcional)</span></span>
                <input class="adm-input" type="text" name="cargo" maxlength="<?= ADMINISTRADORES_FUNCAO_MAX ?>" value="<?= $v('cargo') ?>" placeholder="Ex.: Bibliotecária, Coordenação" autocomplete="off">
                <?= $erro('cargo') ?>
            </label>
            <div class="adm-field<?= $compacto ? ' adm-field--full' : '' ?><?= $inv('senha') ?>">
                <label class="adm-field__label" for="cadastro-rapido-senha">Senha inicial <span class="adm-required" aria-hidden="true">*</span></label>
                <div class="adm-senha-gerada">
                    <input class="adm-input" id="cadastro-rapido-senha" type="text" name="senha" minlength="<?= AUTH_SENHA_MINIMA ?>" maxlength="72" required autocomplete="new-password" spellcheck="false" data-senha-campo>
                    <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-gerar-senha><?= icon('key-round') ?> Gerar senha</button>
                </div>
                <span class="adm-field__hint">Pelo menos <?= AUTH_SENHA_MINIMA ?> caracteres, com letras e números. A senha fica visível para você anotar e entregar.</span>
                <?= $erro('senha') ?>
            </div>
        </div>
        <div class="adm-form-actions adm-form-actions--static">
            <button type="submit" class="adm-button adm-button--primary"><?= icon('check') ?> Cadastrar administrador</button>
        </div>
    </form>
    <?php
    return (string)ob_get_clean();
}

/** Função exibida da conta (cargo); sem função, "Administrador". */
function administradores_funcao(array $conta): string
{
    $cargo = trim((string)($conta['cargo'] ?? ''));
    return $cargo !== '' ? $cargo : 'Administrador';
}

function administradores_badge_status(bool $ativo): string
{
    return $ativo
        ? '<span class="adm-status-dot">Ativo</span>'
        : '<span class="adm-status-dot adm-status-dot--off">Inativo</span>';
}
