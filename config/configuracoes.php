<?php
/**
 * Leitura das configurações gerais (Etapa 6).
 *
 * config_valor('EMPRESTIMO_PRAZO_DIAS') devolve, nesta ordem:
 *   1. o valor salvo no painel (Sistema → Configurações, tabela configuracoes_sistema);
 *   2. o valor do .env (ou da variável do servidor);
 *   3. $padrao.
 * Só as chaves de Biblioteca\Support\ConfiguracaoSistema::CAMPOS podem vir do painel; as demais
 * (banco, ambiente, fuso, pastas) são lidas só do .env, como antes.
 *
 * Sem banco, com o banco fora do ar ou com a migração 090 ainda não aplicada, tudo continua
 * vindo do .env: o sistema funciona exatamente como antes desta etapa.
 */
require_once __DIR__ . '/database.php';

use Biblioteca\Support\ConfiguracaoSistema;

/** Valores salvos no painel (chave => valor). $recarregar relê o banco (depois de salvar). */
function config_banco(bool $recarregar = false): array
{
    static $valores = null;
    if ($recarregar) {
        $valores = null;
    }
    if ($valores !== null) {
        return $valores;
    }
    $valores = [];
    if (!db_configurado()) {
        return $valores;
    }
    try {
        foreach (db()->query('SELECT chave, valor FROM configuracoes_sistema') as $linha) {
            if (isset(ConfiguracaoSistema::CAMPOS[$linha['chave']]) && $linha['valor'] !== null && trim((string)$linha['valor']) !== '') {
                $valores[$linha['chave']] = (string)$linha['valor'];
            }
        }
    } catch (DbIndisponivel $e) {
        // Banco fora do ar: as páginas já tratam isso; aqui só caímos no .env.
    } catch (PDOException $e) {
        // 42S02: tabela inexistente (migração 090 ainda não aplicada). Outros erros vão para o log.
        if (($e->errorInfo[1] ?? 0) !== 1146) {
            error_log('[biblioteca] Configurações do painel indisponíveis: ' . $e->getMessage());
        }
    }
    return $valores;
}

/** Valor efetivo de uma configuração: painel, depois .env, depois $padrao. */
function config_valor(string $chave, ?string $padrao = null): ?string
{
    $banco = config_banco();
    if (isset($banco[$chave])) {
        return $banco[$chave];
    }
    return env($chave, $padrao);
}

/** De onde vem o valor em uso: 'painel', 'env' ou 'padrao'. */
function config_origem(string $chave): string
{
    if (isset(config_banco()[$chave])) {
        return 'painel';
    }
    return env($chave) !== null ? 'env' : 'padrao';
}

/** Configuração inteira dentro de [min, max], ou $padrao quando vazia/inválida. */
function config_inteiro(string $chave, int $padrao, int $min, int $max): int
{
    $valor = config_valor($chave);
    if ($valor === null || !preg_match('/^\d+$/', trim($valor))) {
        return $padrao;
    }
    return max($min, min($max, (int)$valor));
}

function config_bool(string $chave, bool $padrao): bool
{
    return ConfiguracaoSistema::opcao(config_valor($chave)) ?? $padrao;
}
