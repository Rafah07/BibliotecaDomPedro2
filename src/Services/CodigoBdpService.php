<?php

namespace Biblioteca\Services;

use Biblioteca\Support\CodigoBdp;
use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Gerador do código BDP dos exemplares (botão "Gerar código BDP" do Inventário).
 *
 * O código é calculado no SERVIDOR, nunca no navegador:
 *   1. a linha da sequência exemplar_codigo_seq é bloqueada (SELECT ... FOR UPDATE), então dois
 *      pedidos ao mesmo tempo esperam um pelo outro e recebem números diferentes;
 *   2. o próximo número é gravado como RESERVA de quem pediu, ligada ao FORMULÁRIO que pediu
 *      (exemplar_codigo_reservas.processo, migração 130), e a sequência avança na mesma
 *      transação. Ela nunca volta: um código reservado e abandonado, cancelado ou de um
 *      exemplar baixado não é entregue de novo;
 *   3. a pessoa vê o código antes de salvar ("reservado para este formulário"), escreve-o no
 *      carimbo e salva o exemplar. Só então o código vira definitivo
 *      (exemplares.codigo_individual, com índice único).
 *
 * Correção (migração 130): antes, quem clicava de novo recebia a reserva não usada da MESMA
 * PESSOA por até 12 horas, mesmo em outra aba ou em outro livro. Duas abas mostravam o mesmo
 * código, que podia ser escrito em dois carimbos. Agora só o MESMO formulário (mesmo
 * identificador de processo) recebe a mesma reserva; outra aba, outro livro ou "Registrar outra
 * cópia" recebem sempre um número novo.
 *
 * Ao salvar, um código reservado só é aceito para quem o gerou e no formulário que o gerou.
 * Reserva cancelada não é aceita. Reserva de outro formulário da mesma pessoa só é aceita
 * depois de expirada (RESERVA_VALIDADE_HORAS), quando nenhuma aba aberta deveria mais usá-la.
 * Um código digitado à mão (carimbo antigo) é aceito se estiver completo, não pertencer a
 * nenhum exemplar e não estiver reservado. O formato é o mesmo dos carimbos: BDP-000001.
 */
final class CodigoBdpService
{
    use ConsultasPdo;

    private const CODIGO_MAXIMO = 999999;

    /** Depois deste prazo, uma reserva não usada aparece como "expirada" (o número continua gasto). */
    public const RESERVA_VALIDADE_HORAS = 24;

    public function __construct(private PDO $pdo)
    {
    }

    /** Identificador novo de formulário/processo de cadastro (32 caracteres hexadecimais). */
    public static function novoProcesso(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Identificador de processo válido (ou null). */
    public static function processoValido(?string $processo): ?string
    {
        $p = strtolower(trim((string)$processo));
        return preg_match('/^[a-f0-9]{32}$/', $p) ? $p : null;
    }

    /**
     * Reserva o próximo código para $responsavelId neste formulário ($processo). Clicar de novo
     * no MESMO formulário devolve a mesma reserva ainda não usada; qualquer outro formulário
     * recebe um número novo.
     * @return array{codigo: string, nova: bool}
     */
    public function reservar(int $responsavelId, ?int $livroId = null, ?string $processo = null): array
    {
        $processo = self::processoValido($processo);
        return $this->transacao(function () use ($responsavelId, $livroId, $processo) {
            $ultimo = (int)$this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            if ($processo !== null) {
                $pendente = $this->valor('SELECT r.codigo FROM exemplar_codigo_reservas r
                                          WHERE r.processo = ? AND r.reservado_por = ? AND r.usado_em IS NULL AND r.cancelada_em IS NULL
                                            AND r.reservado_em >= NOW() - INTERVAL ' . self::RESERVA_VALIDADE_HORAS . ' HOUR
                                            AND NOT EXISTS (SELECT 1 FROM exemplares e WHERE e.codigo_individual = r.codigo)
                                          ORDER BY r.numero DESC LIMIT 1', [$processo, $responsavelId]);
                if ($pendente) {
                    return ['codigo' => (string)$pendente, 'nova' => false];
                }
            }
            $numero = $ultimo + 1;
            // Defesa extra: pula números que, por algum motivo, já existam (nunca deveria acontecer).
            while ($numero <= self::CODIGO_MAXIMO && $this->valor('SELECT 1 FROM exemplares WHERE codigo_individual = ? UNION SELECT 1 FROM exemplar_codigo_reservas WHERE codigo = ?',
                    [CodigoBdp::formatar($numero), CodigoBdp::formatar($numero)])) {
                $numero++;
            }
            if ($numero > self::CODIGO_MAXIMO) {
                throw new AcervoException('A numeração BDP chegou ao limite de ' . self::CODIGO_MAXIMO . ' exemplares.');
            }
            $codigo = CodigoBdp::formatar($numero);
            $this->executar('UPDATE exemplar_codigo_seq SET ultimo = ? WHERE id = 1', [$numero]);
            $this->executar('INSERT INTO exemplar_codigo_reservas (codigo, numero, reservado_por, livro_id, processo) VALUES (?, ?, ?, ?, ?)',
                [$codigo, $numero, $responsavelId, $livroId, $processo]);
            $this->registrarAcao($responsavelId, 'exemplar.reservar_codigo', 'livro', $livroId, ['codigo' => $codigo, 'processo' => $processo]);
            return ['codigo' => $codigo, 'nova' => true];
        });
    }

    /**
     * Cancela uma reserva ainda não usada (o cadastro foi cancelado antes de salvar). O número
     * continua gasto: não volta para a sequência e não é entregue a ninguém.
     * Só quem gerou cancela, e só no formulário que gerou (quando houver processo).
     * @return bool true se cancelou agora
     */
    public function cancelar(string $texto, int $responsavelId, ?string $processo = null): bool
    {
        $codigo = CodigoBdp::normalizar($texto);
        if ($codigo === null) {
            return false;
        }
        $processo = self::processoValido($processo);
        return $this->transacao(function () use ($codigo, $responsavelId, $processo) {
            $r = $this->linha('SELECT * FROM exemplar_codigo_reservas WHERE codigo = ? FOR UPDATE', [$codigo]);
            if (!$r || $r['usado_em'] !== null || $r['cancelada_em'] !== null || (int)$r['reservado_por'] !== $responsavelId) {
                return false;
            }
            if ($r['processo'] !== null && $r['processo'] !== $processo) {
                return false;
            }
            $this->executar('UPDATE exemplar_codigo_reservas SET cancelada_em = NOW(), cancelada_por = ? WHERE codigo = ?', [$responsavelId, $codigo]);
            $this->registrarAcao($responsavelId, 'exemplar.cancelar_codigo', 'livro', $r['livro_id'] !== null ? (int)$r['livro_id'] : null, ['codigo' => $codigo]);
            return true;
        });
    }

    /**
     * Motivo pelo qual $responsavelId NÃO pode usar agora a reserva $reserva (linha de
     * exemplar_codigo_reservas ainda não usada), ou null se pode. Usado ao salvar o exemplar.
     */
    public static function motivoRecusa(array $reserva, int $responsavelId, ?string $processo): ?string
    {
        $codigo = $reserva['codigo'];
        if ($reserva['cancelada_em'] !== null) {
            return 'O código ' . $codigo . ' foi cancelado e não é mais usado. Clique em "Gerar código BDP" para receber outro.';
        }
        if ((int)$reserva['reservado_por'] !== $responsavelId) {
            return 'O código ' . $codigo . ' foi gerado para outra pessoa' . (!empty($reserva['nome']) ? ' (' . $reserva['nome'] . ')' : '')
                . ' e pode já estar escrito em outro livro. Clique em "Gerar código BDP" para receber um código só seu.';
        }
        $processo = self::processoValido($processo);
        $expirada = strtotime((string)$reserva['reservado_em']) < time() - self::RESERVA_VALIDADE_HORAS * 3600;
        if ($reserva['processo'] !== null && $reserva['processo'] !== $processo && !$expirada) {
            return 'O código ' . $codigo . ' foi gerado em outro formulário (outra aba ou outro livro) e pode estar sendo usado lá. '
                . 'Use o código gerado neste formulário ou clique em "Gerar código BDP" para receber um novo.';
        }
        return null;
    }

    /**
     * Confere se um código pode ser usado por $responsavelId neste formulário agora (sem gravar nada).
     * @return string código no formato oficial
     */
    public function validarDisponivel(string $texto, int $responsavelId, ?string $processo = null): string
    {
        $codigo = CodigoBdp::normalizar($texto);
        if ($codigo === null) {
            throw new AcervoException(CodigoBdp::mensagemInvalido($texto));
        }
        $ex = $this->linha('SELECT e.situacao, l.titulo FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE e.codigo_individual = ?', [$codigo]);
        if ($ex) {
            throw new AcervoException('O código ' . $codigo . ' já pertence a outro exemplar ("' . ($ex['titulo'] ?? 'Sem título') . '"'
                . ($ex['situacao'] === 'baixado' ? ', já baixado' : '') . '). Cada código BDP é único e não é reaproveitado.');
        }
        $res = $this->linha('SELECT r.*, a.nome FROM exemplar_codigo_reservas r LEFT JOIN administradores a ON a.id = r.reservado_por WHERE r.codigo = ? AND r.usado_em IS NULL', [$codigo]);
        if ($res && ($motivo = self::motivoRecusa($res, $responsavelId, $processo)) !== null) {
            throw new AcervoException($motivo);
        }
        return $codigo;
    }

    /** Marca a reserva como usada (dentro da transação que salvou o exemplar). */
    public function marcarUsado(string $codigo, int $exemplarId): void
    {
        $this->executar('UPDATE exemplar_codigo_reservas SET usado_em = NOW(), exemplar_id = ? WHERE codigo = ? AND usado_em IS NULL', [$exemplarId, $codigo]);
    }

    /** Situação de uma reserva para exibir: reservada | usada | cancelada | expirada. */
    public static function situacaoReserva(array $r): string
    {
        return match (true) {
            $r['usado_em'] !== null => 'usada',
            $r['cancelada_em'] !== null => 'cancelada',
            strtotime((string)$r['reservado_em']) < time() - self::RESERVA_VALIDADE_HORAS * 3600 => 'expirada',
            default => 'reservada',
        };
    }
}
