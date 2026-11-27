<?php
/**
 * Inicialização do módulo temporário de reorganização do acervo.
 *
 * Carregado só pelas páginas do próprio módulo e pelos pontos de integração (menu do painel e botão
 * da tela de login). Registra o carregamento das classes Biblioteca\Reorganizacao\ (pasta src/ deste
 * módulo) e oferece os atalhos reorg_*.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/configuracoes.php';

spl_autoload_register(function (string $classe): void {
    $prefixo = 'Biblioteca\\Reorganizacao\\';
    if (str_starts_with($classe, $prefixo)) {
        $arquivo = __DIR__ . '/src/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
        if (is_file($arquivo)) {
            require $arquivo;
        }
    }
});

/**
 * Configuração central (config.php). Os tempos das sessões temporárias podem ser ajustados pelos
 * administradores em Inventário → Configurações das equipes (tabela reorg_configuracoes, migração 230).
 */
function reorg_config(?string $chave = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        if ($config['ativo'] && db_modo() === 'mysql') {
            try {
                foreach (db()->query('SELECT chave, valor FROM reorg_configuracoes')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                    if (in_array($k, ['intervalo_sinal', 'tolerancia_desconexao', 'reserva_edicao'], true)) {
                        $config[$k] = (int)$v;
                    }
                }
            } catch (Throwable $e) {
                // migração 230 ainda não aplicada: valem os tempos do config.php
            }
        }
    }
    return $chave === null ? $config : ($config[$chave] ?? null);
}

/** O módulo está ligado (config.php) e as migrações 220 e 230 foram aplicadas? */
function reorg_modulo_ativo(): bool
{
    static $ativo = null;
    if ($ativo !== null) {
        return $ativo;
    }
    if (!reorg_config('ativo') || db_modo() !== 'mysql') {
        return $ativo = false;
    }
    try {
        return $ativo = (bool)db()->query("SHOW TABLES LIKE 'reorg_configuracoes'")->fetchColumn();
    } catch (Throwable $e) {
        return $ativo = false;
    }
}

/** Convenção dos módulos opcionais (admin_modulo_ativo no menu do painel). */
function modulo_reorganizacao_ativo(): bool
{
    return reorg_modulo_ativo();
}

/** Serviço principal (uma instância por requisição). */
function reorg(): Biblioteca\Reorganizacao\Reorganizacao
{
    static $s = null;
    if ($s === null) {
        $ex = new Biblioteca\Services\ExemplarService(db());
        $ex->definirRegrasPreparo(config_bool('EXEMPLAR_EMPRESTAR_SEM_CARIMBO', false), config_bool('EXEMPLAR_EMPRESTAR_SEM_ORGANIZACAO', false));
        $s = new Biblioteca\Reorganizacao\Reorganizacao(db(), reorg_config(), $ex);
    }
    return $s;
}

/** Endereço dentro do módulo: reorg_url('admin/lote.php?id=3'). */
function reorg_url(string $caminho = ''): string
{
    return url('modulos/reorganizacao/' . ltrim($caminho, '/'));
}

/** Arquivo estático do módulo com versão (evita cache antigo). */
function reorg_asset(string $arquivo): string
{
    $caminho = __DIR__ . '/assets/' . $arquivo;
    return reorg_url('assets/' . $arquivo) . (is_file($caminho) ? '?v=' . filemtime($caminho) : '');
}
