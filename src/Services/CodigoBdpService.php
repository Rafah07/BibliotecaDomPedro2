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
 *   2. o próximo número é gravado como RESERVA de quem pediu (exemplar_codigo_reservas) e a
 *      sequência avança na mesma transação. Ela nunca volta: um código reservado e abandonado, ou
 *      de um exemplar baixado, não é entregue de novo;
 *   3. a pessoa vê o código antes de salvar, escreve-o no carimbo e salva o exemplar. Só então o
 *      código vira definitivo (exemplares.codigo_individual, com índice único).
 *
 * Quem clica de novo sem ter usado o código recebe a MESMA reserva (não gasta números à toa).
 * Um código digitado à mão é aceito se estiver completo, não pertencer a nenhum exemplar e não
 * estiver reservado por outra pessoa. O formato é o mesmo dos carimbos que já existem: BDP-000001.
 */
final class CodigoBdpService
{
    use ConsultasPdo;

    private const CODIGO_MAXIMO = 999999;

    /** Por quanto tempo a mesma reserva volta para quem clica de novo em "Gerar código BDP". */
    private const REUSO_HORAS = 12;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Reserva o próximo código para $responsavelId (ou devolve a reserva ainda não usada dele).
     * @return array{codigo: string, nova: bool}
     */
    public function reservar(int $responsavelId, ?int $livroId = null): array
    {
        return $this->transacao(function () use ($responsavelId, $livroId) {
            $ultimo = (int)$this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            $pendente = $this->valor('SELECT r.codigo FROM exemplar_codigo_reservas r
                                      WHERE r.reservado_por = ? AND r.usado_em IS NULL AND r.reservado_em >= NOW() - INTERVAL ' . self::REUSO_HORAS . ' HOUR
                                        AND NOT EXISTS (SELECT 1 FROM exemplares e WHERE e.codigo_individual = r.codigo)
                                      ORDER BY r.numero DESC LIMIT 1', [$responsavelId]);
            if ($pendente) {
                return ['codigo' => (string)$pendente, 'nova' => false];
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
            $this->executar('INSERT INTO exemplar_codigo_reservas (codigo, numero, reservado_por, livro_id) VALUES (?, ?, ?, ?)', [$codigo, $numero, $responsavelId, $livroId]);
            $this->registrarAcao($responsavelId, 'exemplar.reservar_codigo', 'livro', $livroId, ['codigo' => $codigo]);
            return ['codigo' => $codigo, 'nova' => true];
        });
    }

    /**
     * Confere se um código pode ser usado por $responsavelId agora (sem gravar nada).
     * @return string código no formato oficial
     */
    public function validarDisponivel(string $texto, int $responsavelId): string
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
        $res = $this->linha('SELECT r.reservado_por, a.nome FROM exemplar_codigo_reservas r LEFT JOIN administradores a ON a.id = r.reservado_por WHERE r.codigo = ?', [$codigo]);
        if ($res && (int)$res['reservado_por'] !== $responsavelId) {
            throw new AcervoException('O código ' . $codigo . ' foi gerado para outra pessoa' . ($res['nome'] ? ' (' . $res['nome'] . ')' : '')
                . ' e pode já estar escrito em outro livro. Clique em "Gerar código BDP" para receber um código só seu.');
        }
        return $codigo;
    }

    /** Marca a reserva como usada (dentro da transação que salvou o exemplar). */
    public function marcarUsado(string $codigo, int $exemplarId): void
    {
        $this->executar('UPDATE exemplar_codigo_reservas SET usado_em = NOW(), exemplar_id = ? WHERE codigo = ? AND usado_em IS NULL', [$exemplarId, $codigo]);
    }
}
