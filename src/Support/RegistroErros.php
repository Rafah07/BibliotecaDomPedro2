<?php

namespace Biblioteca\Support;

use PDOException;
use Throwable;

/**
 * Registro técnico protegido das falhas (para a manutenção), separado da mensagem mostrada ao
 * funcionário.
 *
 *  - Grava em storage/logs/erros-AAAA-MM.log (uma linha JSON por falha). A pasta storage/ é bloqueada
 *    para o navegador (.htaccess) e fica fora do Git. Funciona mesmo com o banco fora do ar.
 *  - Cada falha recebe um identificador curto (ERR-20261118-1a2b3c) que aparece na tela: a equipe
 *    informa esse número e a manutenção encontra o registro no Diagnóstico do sistema.
 *  - Nunca grava senhas, conteúdo do .env, consultas SQL completas nem caminhos completos do servidor.
 */
final class RegistroErros
{
    private const MAX_MENSAGEM = 300;

    public static function pasta(): string
    {
        return (defined('APP_RAIZ') ? APP_RAIZ : dirname(__DIR__, 2)) . '/storage/logs';
    }

    /**
     * Registra a falha e devolve o identificador.
     * @param array{operacao_chave?: ?string, usuario_id?: ?int} $extra
     */
    public static function registrar(Throwable $e, string $operacao, array $extra = []): string
    {
        $id = 'ERR-' . date('Ymd') . '-' . bin2hex(random_bytes(3));
        $linha = [
            'id' => $id,
            'data' => date('Y-m-d H:i:s'),
            'operacao' => mb_substr($operacao, 0, 120),
            'arquivo' => basename($e->getFile()) . ':' . $e->getLine(),
            'tipo' => (new \ReflectionClass($e))->getShortName(),
            'codigo' => self::codigo($e),
            'conexao_perdida' => Migracoes::conexaoPerdida($e),
            'mensagem' => self::limpar($e->getMessage()),
            'operacao_chave' => isset($extra['operacao_chave']) ? preg_replace('/[^a-f0-9]/i', '', (string)$extra['operacao_chave']) : null,
            'usuario_id' => $extra['usuario_id'] ?? null,
            'pagina' => isset($_SERVER['SCRIPT_NAME']) ? basename((string)$_SERVER['SCRIPT_NAME']) : 'cli',
        ];
        $json = json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        try {
            $pasta = self::pasta();
            if (!is_dir($pasta)) {
                @mkdir($pasta, 0700, true);
                @file_put_contents($pasta . '/.htaccess', "# Registros técnicos: nunca podem ser abertos pelo navegador.\nRequire all denied\n");
                @file_put_contents($pasta . '/index.html', '');
            }
            @file_put_contents($pasta . '/erros-' . date('Y-m') . '.log', $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Sem pasta gravável: fica só no log do PHP (abaixo).
        }
        error_log('[biblioteca] ' . $id . ' ' . $linha['operacao'] . ': ' . $linha['tipo'] . ' ' . $linha['codigo'] . ' em ' . $linha['arquivo']);
        return $id;
    }

    /** SQLSTATE/código do driver ("23000/1062"), ou o código da exceção. */
    public static function codigo(Throwable $e): string
    {
        if ($e instanceof PDOException) {
            $info = $e->errorInfo ?? [];
            return trim(($info[0] ?? $e->getCode()) . '/' . ($info[1] ?? ''), '/');
        }
        return (string)$e->getCode();
    }

    /** Remove o que não deve ir para o log: senhas, trechos de SQL e caminhos completos. */
    public static function limpar(string $msg): string
    {
        $msg = preg_replace('/(password|senha|pwd|pass)\s*[=:]\s*\S+/i', '$1=***', $msg) ?? '';
        // O código técnico (SQLSTATE/driver) já fica no campo "codigo": a mensagem guarda só o texto.
        $msg = preg_replace('/SQLSTATE\[\w+\]:?\s*(\[\d+\]\s*)?(General error:\s*\d+\s*)?/i', '', $msg) ?? '';
        $msg = preg_replace("/near '.*$/s", "near '…'", $msg) ?? '';                         // trecho da consulta
        $msg = preg_replace('#[A-Za-z]:\\\\[^\s\'"]+|/(?:var|home|usr|opt|xampp)[^\s\'"]*#', '[caminho]', $msg) ?? '';
        $msg = preg_replace('/\s+/', ' ', $msg) ?? '';
        return mb_substr(trim($msg), 0, self::MAX_MENSAGEM);
    }

    /**
     * Falhas mais recentes (dos dois últimos meses), da mais nova para a mais antiga.
     * @return list<array<string, mixed>>
     */
    public static function recentes(int $limite = 20): array
    {
        $linhas = [];
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $mes) {
            $arquivo = self::pasta() . '/erros-' . $mes . '.log';
            if (!is_file($arquivo)) {
                continue;
            }
            foreach (array_reverse(@file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $l) {
                $d = json_decode($l, true);
                if (is_array($d)) {
                    $linhas[] = $d;
                    if (count($linhas) >= $limite) {
                        return $linhas;
                    }
                }
            }
        }
        return $linhas;
    }

    /** Quantas falhas nos últimos $dias dias (mês atual e anterior). */
    public static function contarRecentes(int $dias = 7): int
    {
        $limite = date('Y-m-d H:i:s', time() - $dias * 86400);
        return count(array_filter(self::recentes(500), fn ($l) => ($l['data'] ?? '') >= $limite));
    }

    /**
     * Mensagem para o funcionário (sem SQL, senhas ou caminhos), já com o identificador.
     * $resultadoIncerto: a conexão caiu durante uma gravação — não dá para saber se foi concluída.
     */
    public static function mensagemUsuario(Throwable $e, string $id, bool $preservado = true, bool $resultadoIncerto = false): string
    {
        if (Migracoes::conexaoPerdida($e)) {
            return $resultadoIncerto
                ? 'A conexão com o banco foi interrompida durante a gravação. Não foi possível confirmar se o cadastro foi concluído: verifique antes de tentar novamente (reenviar este mesmo formulário é seguro — nada é gravado duas vezes). Código: ' . $id . '.'
                : 'A conexão com o banco foi interrompida. ' . ($preservado ? 'Suas informações foram preservadas. ' : '') . 'Tente novamente em instantes. Código: ' . $id . '.';
        }
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1644) {
            return (string)($e->errorInfo[2] ?? 'Operação recusada pelo banco de dados.'); // regra do banco escrita para a tela
        }
        if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1054, 1146], true)) {
            return 'O banco de dados está desatualizado (falta uma tabela ou coluna). Nada foi gravado. Peça ao responsável para conferir o Diagnóstico do sistema e aplicar as migrações. Código: ' . $id . '.';
        }
        if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1142, 1143, 1227, 1044, 1045], true)) {
            return 'O usuário do banco de dados não tem uma permissão necessária. Nada foi gravado. Peça ao responsável para conferir o Diagnóstico do sistema. Código: ' . $id . '.';
        }
        return 'Não foi possível concluir a operação.' . ($preservado ? ' Suas informações foram preservadas.' : '') . ' Tente novamente; se o problema continuar, informe o código ' . $id . ' ao responsável pelo sistema.';
    }
}
