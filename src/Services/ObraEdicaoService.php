<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Aviso de edição simultânea das obras (migração 190, tabela obra_edicoes).
 *
 *  - Ao abrir "Editar dados da obra", a pessoa entra na lista de quem está editando; o navegador
 *    confirma a cada 30 segundos que o formulário continua aberto.
 *  - Sem confirmação por EXPIRA_SEGUNDOS (navegador fechado, internet caiu, computador desligado),
 *    a marca deixa de valer sozinha. Ao salvar ou sair da página, ela é retirada.
 *  - É só um AVISO: nunca impede consultar a obra, salvar (o conflito real é tratado pela versão do
 *    registro, livros.versao) nem registrar exemplares de uma obra verificada.
 */
final class ObraEdicaoService
{
    use ConsultasPdo;

    public const EXPIRA_SEGUNDOS = 90;
    public const RENOVAR_SEGUNDOS = 30;

    public function __construct(private PDO $pdo)
    {
    }

    /** Marca (ou renova) que $administradorId está com o formulário da obra aberto. */
    public function entrar(int $livroId, int $administradorId, int $versao): void
    {
        $this->executar('INSERT INTO obra_edicoes (livro_id, administrador_id, versao_aberta) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE renovado_em = NOW(), versao_aberta = VALUES(versao_aberta),
                                                 iniciado_em = IF(renovado_em < NOW() - INTERVAL ' . self::EXPIRA_SEGUNDOS . ' SECOND, NOW(), iniciado_em)',
            [$livroId, $administradorId, $versao]);
    }

    /** O navegador confirmou que o formulário continua aberto. */
    public function renovar(int $livroId, int $administradorId): bool
    {
        return $this->executar('UPDATE obra_edicoes SET renovado_em = NOW() WHERE livro_id = ? AND administrador_id = ?', [$livroId, $administradorId]) > 0
            || (bool)$this->valor('SELECT 1 FROM obra_edicoes WHERE livro_id = ? AND administrador_id = ?', [$livroId, $administradorId]);
    }

    /** Formulário salvo, cancelado ou página fechada. */
    public function sair(int $livroId, int $administradorId): void
    {
        $this->executar('DELETE FROM obra_edicoes WHERE livro_id = ? AND administrador_id = ?', [$livroId, $administradorId]);
    }

    /**
     * Outras pessoas com o formulário desta obra aberto agora (marcas expiradas não contam).
     * @return list<array{administrador_id: int, nome: string, iniciado_em: string, renovado_em: string}>
     */
    public function outros(int $livroId, int $administradorId): array
    {
        return array_map(fn ($r) => ['administrador_id' => (int)$r['administrador_id']] + $r,
            $this->todas('SELECT o.administrador_id, a.nome, o.iniciado_em, o.renovado_em FROM obra_edicoes o JOIN administradores a ON a.id = o.administrador_id
                          WHERE o.livro_id = ? AND o.administrador_id <> ? AND o.renovado_em >= NOW() - INTERVAL ' . self::EXPIRA_SEGUNDOS . ' SECOND
                          ORDER BY o.iniciado_em', [$livroId, $administradorId]));
    }

    /** Remove marcas expiradas há mais de um dia (limpeza; não muda nenhum dado da obra). */
    public function limparExpiradas(): int
    {
        return $this->executar('DELETE FROM obra_edicoes WHERE renovado_em < NOW() - INTERVAL 1 DAY');
    }

    /** Texto do aviso. $mostrarNomes: só quem pode editar obras vê quem está editando. */
    public static function aviso(array $outros, bool $mostrarNomes): ?string
    {
        if (!$outros) {
            return null;
        }
        if (!$mostrarNomes) {
            return 'Esta obra está sendo editada por outro colaborador.';
        }
        $nomes = array_column($outros, 'nome');
        return 'Esta obra está sendo editada por ' . (count($nomes) === 1 ? $nomes[0] : implode(', ', array_slice($nomes, 0, -1)) . ' e ' . end($nomes))
            . ' (desde ' . date('H:i', strtotime((string)$outros[0]['iniciado_em'])) . ').';
    }
}
