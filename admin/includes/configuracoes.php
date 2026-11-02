<?php
/**
 * Configurações gerais, perfis e manutenção (Etapa 6).
 *
 * - Sistema → Configurações: valores salvos no painel valem mais que o .env (ver config/configuracoes.php).
 * - As permissões (configuracoes.ver / configuracoes.editar / sistema.manutencao) são conferidas
 *   no servidor, campo por campo, além de esconder o que o perfil não pode alterar.
 * - Senhas, tokens e a senha do banco nunca aparecem na tela nem no histórico.
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php'; // acervo_breadcrumb

use Biblioteca\Repositories\ConfiguracaoRepository;
use Biblioteca\Support\ConfiguracaoSistema;

const CONFIG_CSS = ['assets/css/acervo.css?v=20261102', 'assets/css/configuracoes.css?v=20261101'];

/**
 * Variáveis que continuam só no .env (técnicas). 'segredo' => mostra só se está definida.
 * Exibidas apenas para quem tem sistema.manutencao.
 */
const CONFIG_SO_ENV = [
    'APP_ENV' => ['rotulo' => 'Ambiente', 'padrao' => 'development'],
    'APP_BASE_PATH' => ['rotulo' => 'Caminho do site no navegador', 'padrao' => 'descoberto automaticamente'],
    'APP_TIMEZONE' => ['rotulo' => 'Fuso horário da escola', 'padrao' => 'America/Sao_Paulo'],
    'DB_HOST' => ['rotulo' => 'Servidor do banco', 'padrao' => '127.0.0.1'],
    'DB_PORT' => ['rotulo' => 'Porta do banco', 'padrao' => '3306'],
    'DB_NAME' => ['rotulo' => 'Nome do banco', 'padrao' => '—'],
    'DB_USER' => ['rotulo' => 'Usuário do banco', 'padrao' => '—'],
    'DB_PASSWORD' => ['rotulo' => 'Senha do banco', 'padrao' => 'vazia', 'segredo' => true],
    'STORAGE_IMPORTACOES' => ['rotulo' => 'Pasta privada das planilhas', 'padrao' => 'storage/importacoes'],
    'CDD_CONTATO' => ['rotulo' => 'Contato enviado às fontes CDD', 'padrao' => 'vazio'],
    'CDD_FONTE_LOC' => ['rotulo' => 'Fonte CDD: Library of Congress', 'padrao' => 'true'],
    'CDD_FONTE_OPENLIBRARY' => ['rotulo' => 'Fonte CDD: Open Library', 'padrao' => 'true'],
    'CDD_FONTE_WEBDEWEY' => ['rotulo' => 'Fonte CDD: WebDewey (licença OCLC)', 'padrao' => 'false'],
];

const CONFIG_ORIGENS = [
    'painel' => ['Definido no painel', 'blue'],
    'env' => ['Definido no .env', 'indigo'],
    'padrao' => ['Padrão do sistema', 'amber'],
];

function config_repo(): ConfiguracaoRepository
{
    static $r = null;
    return $r ??= new ConfiguracaoRepository(db());
}

/** Início das páginas de configuração: permissão no servidor e banco obrigatório. */
function config_iniciar(string $permissao): array
{
    $usuario = auth_exigir($permissao);
    if (db_modo() !== 'mysql') {
        admin_pagina_erro('Disponível só com o banco de dados', 'As configurações são gravadas no MySQL. Configure o banco no arquivo .env para usar esta página.', 'settings');
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

/** Erro técnico: detalhe só no log; mensagem genérica na tela. */
function config_erro_tecnico(Throwable $e, string $contexto): string
{
    $codigo = $e instanceof PDOException ? ' ' . ($e->errorInfo[1] ?? $e->getCode()) : '';
    error_log('[biblioteca] Erro em ' . $contexto . ': ' . get_class($e) . $codigo . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    return 'Não foi possível concluir a operação. Nada foi gravado. Se o problema continuar, verifique se as migrações foram aplicadas (php database/migrar.php).';
}

/** Selo com a origem do valor em uso. */
function config_origem_html(string $origem): string
{
    [$rotulo, $tom] = CONFIG_ORIGENS[$origem] ?? CONFIG_ORIGENS['padrao'];
    return '<span class="adm-status adm-tone--' . $tom . '">' . e($rotulo) . '</span>';
}

/** Texto do valor em uso para um campo (sem o valor do painel: .env ou padrão). */
function config_valor_base(string $chave): string
{
    $env = env($chave);
    return $env !== null ? '.env: ' . ConfiguracaoSistema::exibir($chave, $env) : 'Padrão: ' . ConfiguracaoSistema::CAMPOS[$chave]['padrao'];
}

/** Valor em uso, já formatado (com "dias", sim/não etc.). */
function config_em_uso(string $chave): string
{
    $valor = config_valor($chave);
    if ($valor === null) {
        return ConfiguracaoSistema::CAMPOS[$chave]['padrao'];
    }
    return ConfiguracaoSistema::exibir($chave, $valor);
}

/** Valor exibido para uma variável técnica do .env (nunca mostra segredos). */
function config_env_exibir(string $chave): string
{
    $info = CONFIG_SO_ENV[$chave];
    $valor = env($chave);
    if (!empty($info['segredo'])) {
        return $valor === null ? 'não definida' : 'definida (oculta)';
    }
    return $valor ?? ('padrão: ' . $info['padrao']);
}
