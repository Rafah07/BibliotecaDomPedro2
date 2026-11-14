<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Lotes de cadastramento (migração 150). Cada pessoa tem no máximo um lote aberto: os exemplares
 * que ela registra entram nele, na ordem do registro (ExemplarService). "Começar novo lote" fecha o
 * atual e abre outro, com o nome da equipe. Lotes não são apagados e a ordem dos exemplares dentro
 * de um lote nunca muda (gatilho exemplares_preservar_identidade).
 *
 * A numeração dos lotes usa a mesma trava da sequência BDP: lotes criados ao mesmo tempo (por um
 * cadastro e por este botão) não repetem o número.
 */
final class LoteService
{
    use ConsultasPdo;

    public function __construct(private PDO $pdo)
    {
    }

    /** Fecha o lote aberto de quem pediu (se houver) e abre um novo. Devolve o id do novo lote. */
    public function abrirNovo(int $administradorId, string $equipe = '', string $descricao = ''): int
    {
        $equipe = self::textoLimpo($equipe, 80);
        $descricao = self::textoLimpo($descricao, 255);
        return $this->transacao(function () use ($administradorId, $equipe, $descricao) {
            $this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            $aberto = $this->linha("SELECT id, total FROM cadastro_lotes WHERE responsavel_id = ? AND status = 'aberto' ORDER BY id DESC LIMIT 1 FOR UPDATE", [$administradorId]);
            if ($equipe !== null) {
                // A equipe fica guardada na conta: os lotes abertos automaticamente depois usam a mesma.
                $this->executar('UPDATE administradores SET equipe = ? WHERE id = ?', [$equipe, $administradorId]);
            }
            if ($aberto && (int)$aberto['total'] === 0) {
                // Lote ainda vazio: só atualiza a equipe e a descrição (não gasta um número à toa).
                $this->executar('UPDATE cadastro_lotes SET equipe = ?, descricao = ? WHERE id = ?', [$equipe, $descricao, $aberto['id']]);
                return (int)$aberto['id'];
            }
            if ($aberto) {
                $this->executar("UPDATE cadastro_lotes SET status = 'fechado', fechado_em = NOW(), fechado_por = ? WHERE id = ?", [$administradorId, $aberto['id']]);
            }
            $numero = (int)$this->valor('SELECT COALESCE(MAX(numero), 0) + 1 FROM cadastro_lotes');
            $this->executar('INSERT INTO cadastro_lotes (numero, equipe, descricao, responsavel_id) VALUES (?, ?, ?, ?)', [$numero, $equipe, $descricao, $administradorId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->registrarAcao($administradorId, 'lote.abrir', 'lote', $id, ['numero' => $numero, 'equipe' => $equipe, 'fechado' => $aberto['id'] ?? null]);
            return $id;
        });
    }

    /** Fecha um lote (os próximos registros de quem o abriu vão para um lote novo). */
    public function fechar(int $loteId, int $administradorId): void
    {
        $this->transacao(function () use ($loteId, $administradorId) {
            $lote = $this->linha('SELECT * FROM cadastro_lotes WHERE id = ? FOR UPDATE', [$loteId]);
            if (!$lote) {
                throw new AcervoException('Lote não encontrado.');
            }
            if ($lote['status'] === 'fechado') {
                return;
            }
            $this->executar("UPDATE cadastro_lotes SET status = 'fechado', fechado_em = NOW(), fechado_por = ? WHERE id = ?", [$administradorId, $loteId]);
            $this->registrarAcao($administradorId, 'lote.fechar', 'lote', $loteId, ['numero' => (int)$lote['numero']]);
        });
    }

    /** Equipe de uma conta (usada nos lotes novos e no filtro "Equipe" do Relatório de Atividades). */
    public function definirEquipe(int $administradorId, string $equipe, int $responsavelId): void
    {
        $equipe = self::textoLimpo($equipe, 80);
        $this->transacao(function () use ($administradorId, $equipe, $responsavelId) {
            if (!$this->linha('SELECT id FROM administradores WHERE id = ? FOR UPDATE', [$administradorId])) {
                throw new AcervoException('Conta não encontrada.');
            }
            $this->executar('UPDATE administradores SET equipe = ? WHERE id = ?', [$equipe, $administradorId]);
            $this->registrarAcao($responsavelId, 'usuario.equipe', 'usuario', $administradorId, ['equipe' => $equipe]);
        });
    }
}
