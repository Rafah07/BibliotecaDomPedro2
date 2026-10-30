<?php
/**
 * Gerenciamento das contas de acesso ao painel (administradores, bibliotecárias,
 * auxiliares e consulta). Só funciona com o banco configurado e exige a permissão
 * usuarios.gerenciar, conferida no servidor em cada página.
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php'; // acervo_breadcrumb

use Biblioteca\Repositories\AdministradorRepository;

/** O que cada perfil pode fazer, em linguagem simples (as regras reais estão em AUTH_PERMISSOES). */
const ADMINISTRADORES_PERFIS_DESCRICAO = [
    'administrador' => 'Acesso total: livros, classificação CDD, importações e gerenciamento das contas de acesso.',
    'bibliotecaria' => 'Gerencia livros (cadastrar, editar, arquivar), aprova classificações CDD e executa importações. Não gerencia contas.',
    'auxiliar' => 'Cadastra e edita livros, sugere classificações CDD e revisa importações. Não arquiva livros nem aprova CDD.',
    'consulta' => 'Somente consulta: vê o acervo, a classificação e as importações, sem alterar nada.',
];

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
        'perfil' => isset(AUTH_PERFIS[admin_param($get, 'perfil')]) ? admin_param($get, 'perfil') : '',
        'status' => in_array(admin_param($get, 'status'), ['ativo', 'inativo'], true) ? admin_param($get, 'status') : '',
        'ordem' => in_array($ordem, ['nome', 'email', 'criado', 'acesso'], true) ? $ordem : 'nome',
        'dir' => admin_param($get, 'dir') === 'desc' ? 'desc' : 'asc',
        'por_pagina' => in_array($porPagina, [25, 50, 100], true) ? (string)$porPagina : '25',
    ];
}

/**
 * Valida os dados da conta. $id null = nova conta (a senha inicial é obrigatória).
 * Retorna [dados limpos, erros por campo].
 */
function administradores_validar(array $post, ?int $id): array
{
    $dados = [
        'nome' => trim(preg_replace('/\s+/u', ' ', admin_param($post, 'nome'))),
        'email' => mb_strtolower(admin_param($post, 'email')),
        'perfil' => admin_param($post, 'perfil'),
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
    if (!isset(AUTH_PERFIS[$dados['perfil']])) {
        $erros['perfil'] = 'Escolha o perfil de acesso.';
    }
    if ($id === null) {
        $problema = auth_senha_problema((string)($post['senha'] ?? ''), (string)($post['senha_confirmacao'] ?? ''));
        if ($problema) {
            $erros['senha'] = $problema;
        }
    }
    return [$dados, $erros];
}

function administradores_badge_perfil(string $perfil): string
{
    $tom = ['administrador' => 'indigo', 'bibliotecaria' => 'blue', 'auxiliar' => 'teal', 'consulta' => 'muted'][$perfil] ?? 'blue';
    return '<span class="adm-status adm-status--soft adm-tone--' . $tom . '">' . e(AUTH_PERFIS[$perfil] ?? $perfil) . '</span>';
}

function administradores_badge_status(bool $ativo): string
{
    return $ativo
        ? '<span class="adm-status-dot">Ativo</span>'
        : '<span class="adm-status-dot adm-status-dot--off">Inativo</span>';
}
