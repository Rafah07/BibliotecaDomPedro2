<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Rascunhos de formulários interrompidos (migração 160): dados bibliográficos e registro de
 * exemplares. O navegador salva os campos enquanto a pessoa digita; se a página fechar sem querer, o
 * formulário oferece "Recuperar rascunho".
 *
 *   * Um rascunho por usuário + formulário + referência (obra/inventário), com o lote ativo do momento.
 *   * Nunca guarda código BDP, token do formulário nem CSRF: recuperar um rascunho não atribui código
 *     definitivo nem reaproveita uma reserva. O código é gerado (ou digitado) de novo ao salvar.
 *   * Um rascunho não cria exemplar e não ocupa vaga do lote: a vaga só é conferida ao salvar o
 *     registro (ExemplarService), então um lote fechado nunca é ultrapassado por um rascunho.
 *   * Salvar o formulário apaga o rascunho (não sobra nada para recuperar e duplicar o cadastro).
 */
final class RascunhoService
{
    use ConsultasPdo;

    public const FORMULARIOS = ['verificar_obra', 'registrar_exemplar', 'nao_listado', 'cadastrar_exemplar', 'editar_livro'];

    /** Campos que nunca entram no rascunho. */
    private const PROIBIDOS = '/^(csrf|acao|id|codigo|codigo_bdp|codigo_gerado|processo|processo_bdp|chave|token|unico|confirmar|senha)$|^_|token/i';

    public const LIMITE_BYTES = 60000;

    public const VALIDADE_DIAS = 30;

    public function __construct(private PDO $pdo)
    {
    }

    public static function referenciaValida(string $r): ?string
    {
        return preg_match('/^[a-z0-9_-]{1,64}$/i', $r) ? $r : null;
    }

    /** Guarda (ou substitui) o rascunho. Devolve a data gravada. */
    public function salvar(int $administradorId, string $formulario, string $referencia, array $campos): string
    {
        [$formulario, $referencia] = $this->validar($formulario, $referencia);
        $limpos = self::limpar($campos);
        if (!$limpos) {
            $this->descartar($administradorId, $formulario, $referencia);
            return '';
        }
        $json = json_encode($limpos, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::LIMITE_BYTES) {
            throw new AcervoException('Rascunho grande demais para ser salvo.');
        }
        $lote = $this->valor('SELECT lote_ativo_id FROM administradores WHERE id = ?', [$administradorId]) ?: null;
        $this->executar('INSERT INTO formulario_rascunhos (administrador_id, formulario, referencia, lote_id, dados) VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE dados = VALUES(dados), lote_id = VALUES(lote_id), atualizado_em = NOW()',
            [$administradorId, $formulario, $referencia, $lote, $json]);
        return (string)$this->valor('SELECT atualizado_em FROM formulario_rascunhos WHERE administrador_id = ? AND formulario = ? AND referencia = ?', [$administradorId, $formulario, $referencia]);
    }

    /** Rascunho com os campos e a situação do lote (para avisar se ele fechou). */
    public function obter(int $administradorId, string $formulario, string $referencia): ?array
    {
        [$formulario, $referencia] = $this->validar($formulario, $referencia);
        $r = $this->linha('SELECT r.*, lt.numero AS lote_numero, lt.status AS lote_status FROM formulario_rascunhos r LEFT JOIN cadastro_lotes lt ON lt.id = r.lote_id
                           WHERE r.administrador_id = ? AND r.formulario = ? AND r.referencia = ? AND r.atualizado_em >= DATE_SUB(NOW(), INTERVAL ' . self::VALIDADE_DIAS . ' DAY)',
            [$administradorId, $formulario, $referencia]);
        if (!$r) {
            return null;
        }
        $r['campos'] = json_decode((string)$r['dados'], true) ?: [];
        unset($r['dados']);
        return $r;
    }

    public function descartar(int $administradorId, string $formulario, string $referencia): void
    {
        [$formulario, $referencia] = $this->validar($formulario, $referencia);
        $this->executar('DELETE FROM formulario_rascunhos WHERE administrador_id = ? AND formulario = ? AND referencia = ?', [$administradorId, $formulario, $referencia]);
    }

    /** Rascunhos antigos (mais de 30 dias) são apagados. */
    public function limparAntigos(): int
    {
        return $this->executar('DELETE FROM formulario_rascunhos WHERE atualizado_em < DATE_SUB(NOW(), INTERVAL ' . self::VALIDADE_DIAS . ' DAY)');
    }

    /** @return array<string, string|list<string>> */
    public static function limpar(array $campos): array
    {
        $r = [];
        foreach ($campos as $k => $v) {
            $k = (string)$k;
            if (preg_match(self::PROIBIDOS, $k) || strlen($k) > 60 || count($r) >= 80) {
                continue;
            }
            if (is_array($v)) {
                $v = array_slice(array_map(fn ($x) => mb_substr((string)$x, 0, 2000), array_filter($v, 'is_scalar')), 0, 50);
            } elseif (is_scalar($v)) {
                $v = mb_substr((string)$v, 0, 5000);
            } else {
                continue;
            }
            $r[$k] = $v;
        }
        return $r;
    }

    /** @return array{0: string, 1: string} */
    private function validar(string $formulario, string $referencia): array
    {
        if (!in_array($formulario, self::FORMULARIOS, true) || self::referenciaValida($referencia) === null) {
            throw new AcervoException('Rascunho inválido.');
        }
        return [$formulario, $referencia];
    }
}
