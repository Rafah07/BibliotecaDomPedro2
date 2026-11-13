<?php
/**
 * Módulo Alunos do painel (Gerenciamento → Alunos): listagem, detalhes, cadastro individual,
 * correção e inativação.
 *
 * - Só funciona com o banco configurado: no modo demonstrativo não há alunos reais e nada é gravado.
 * - Permissões conferidas no servidor em cada página e em cada envio:
 *     alunos.ver       lista e detalhes (dados pessoais) — administrador, bibliotecária, auxiliar
 *     alunos.editar    cadastrar e corrigir — administrador, bibliotecária
 *     alunos.inativar  inativar e reativar — administrador, bibliotecária
 *   O perfil "Somente consulta" não vê dados pessoais de alunos.
 * - Regras de validação: as mesmas da importação (AlunoImportService::validarCadastro).
 * - Gravações: Biblioteca\Services\AlunoCadastroService (transação, bloqueio da linha, registro da ação).
 * - Privacidade: nome, matrícula e e-mail só aparecem nestas páginas do painel. Nada vai para
 *   URLs públicas, logs do servidor ou para o registro de ações (que guarda só os nomes dos campos).
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php'; // acervo_breadcrumb
require_once __DIR__ . '/emprestimos.php'; // emprestimos_tabela, emprestimos_hoje, emprestimos_repo
require_once __DIR__ . '/alunos-importacao.php'; // alunos_repo, alunos_importacao_servico

use Biblioteca\Services\AlunoCadastroService;
use Biblioteca\Services\AlunoImportService;

const ALUNOS_CSS = ['assets/css/acervo.css?v=20261113', 'assets/css/emprestimos.css?v=20261113'];

/** Rótulos das ações registradas para um aluno (Histórico de alterações). */
const ALUNOS_ACOES = [
    'aluno.cadastrar' => ['Cadastro individual', 'user-plus', 'green'],
    'aluno.editar' => ['Cadastro corrigido', 'pencil', 'blue'],
    'aluno.inativar' => ['Cadastro inativado', 'user-x', 'amber'],
    'aluno.reativar' => ['Cadastro reativado', 'user-check', 'green'],
];

function alunos_cadastro_servico(): AlunoCadastroService
{
    static $s = null;
    return $s ??= new AlunoCadastroService(db(), alunos_repo(), alunos_importacao_servico(), (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
}

/** Início das páginas: permissão conferida no servidor e banco obrigatório. */
function alunos_iniciar(string $permissao = 'alunos.ver'): array
{
    $usuario = auth_exigir($permissao);
    if (db_modo() !== 'mysql') {
        admin_pagina_erro('Disponível só com o banco de dados', 'O cadastro de alunos usa os dados reais do MySQL. No modo demonstrativo não há alunos e nada é gravado. Configure o banco para usar esta página.', 'graduation-cap');
        exit;
    }
    try {
        db();
    } catch (DbIndisponivel $e) {
        http_response_code(503);
        admin_pagina_erro('Banco de dados indisponível', $e->getMessage());
        exit;
    }
    return $usuario;
}

/** Filtros da listagem lidos de $_GET (somente valores conhecidos). */
function alunos_filtros(array $get): array
{
    $porPagina = (int)admin_param($get, 'por_pagina', '25');
    $situacao = admin_param($get, 'situacao', 'ativos');
    $ordem = admin_param($get, 'ordem', 'nome');
    return [
        'q' => mb_substr(admin_param($get, 'q'), 0, 100),
        'turma' => mb_substr(admin_param($get, 'turma'), 0, 40),
        'turno' => isset(AlunoImportService::TURNOS[admin_param($get, 'turno')]) ? admin_param($get, 'turno') : '',
        'situacao' => in_array($situacao, ['ativos', 'inativos', 'todos'], true) ? $situacao : 'ativos',
        'circulacao' => in_array(admin_param($get, 'circulacao'), ['com_emprestimo', 'com_atraso', 'sem_emprestimo'], true) ? admin_param($get, 'circulacao') : '',
        'ordem' => isset(Biblioteca\Repositories\AlunoRepository::ORDENS[$ordem]) ? $ordem : 'nome',
        'dir' => admin_param($get, 'dir') === 'desc' ? 'desc' : 'asc',
        'por_pagina' => in_array($porPagina, [25, 50, 100], true) ? (string)$porPagina : '25',
    ];
}

function alunos_turno(?string $turno): string
{
    return $turno ? (AlunoImportService::TURNOS[$turno] ?? $turno) : '—';
}

function alunos_badge_situacao(bool $ativo): string
{
    return $ativo ? '<span class="adm-status adm-tone--green">Ativo</span>' : '<span class="adm-status adm-tone--muted">Inativo</span>';
}

/** Registra no log do servidor só o tipo do erro (nunca valores digitados) e devolve a mensagem da tela. */
function alunos_erro_tecnico(Throwable $e, string $contexto): string
{
    return emprestimos_erro_tecnico($e, $contexto);
}

/** Valores do formulário de cadastro/correção, lidos do POST (só os campos conhecidos). */
function alunos_valores_post(array $post): array
{
    $v = [];
    foreach (array_keys(AlunoCadastroService::CAMPOS) as $campo) {
        $v[$campo] = mb_substr(admin_param($post, $campo), 0, 200);
    }
    return $v;
}
