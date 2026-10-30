<?php

namespace Biblioteca\Repositories;

use Biblioteca\Services\AlunoImportService;
use Biblioteca\Support\Texto;
use PDO;

/**
 * Acesso à tabela alunos usado pela importação.
 * Alunos não têm conta, login nem senha: aqui só existe o cadastro usado nos empréstimos.
 * Nenhum aluno é excluído; a importação só inclui ou atualiza campos de cadastro.
 */
final class AlunoRepository
{
    private const COLUNAS = 'id, nome, matricula, turma, turno, email, ativo';
    private const LOTE_CONSULTA = 500;

    public function __construct(private PDO $pdo)
    {
    }

    public function total(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn();
    }

    /**
     * Cadastros com as matrículas informadas.
     * @param string[] $matriculas
     * @param bool $bloquear trava as linhas até o fim da transação (usado na gravação)
     * @return array<string, array> AlunoImportService::chaveMatricula => cadastro
     */
    public function porMatriculas(array $matriculas, bool $bloquear = false): array
    {
        $resultado = [];
        foreach ($this->consultarEmLotes('matricula', $matriculas, $bloquear) as $linha) {
            $resultado[AlunoImportService::chaveMatricula($linha['matricula'])] = $linha;
        }
        return $resultado;
    }

    /**
     * Cadastros com os mesmos nomes (a collation do banco ignora maiúsculas e acentos).
     * @return array<string, list<array>> Texto::chave(nome) => cadastros
     */
    public function porNomes(array $nomes): array
    {
        $resultado = [];
        foreach ($this->consultarEmLotes('nome', $nomes) as $linha) {
            $resultado[Texto::chave($linha['nome'])][] = $linha;
        }
        return $resultado;
    }

    /** @return array<string, list<array>> e-mail em minúsculas => cadastros */
    public function porEmails(array $emails): array
    {
        $resultado = [];
        foreach ($this->consultarEmLotes('email', $emails) as $linha) {
            $resultado[mb_strtolower((string)$linha['email'])][] = $linha;
        }
        return $resultado;
    }

    /** Inclui um aluno (sem conta de acesso). Devolve o id. */
    public function incluir(array $dados): int
    {
        $this->pdo->prepare('INSERT INTO alunos (nome, matricula, turma, turno, email) VALUES (?, ?, ?, ?, ?)')
            ->execute([$dados['nome'], $dados['matricula'], $dados['turma'], $dados['turno'], $dados['email']]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atualiza só os campos informados (nome, turma, turno, email). A matrícula nunca muda.
     * @param array<string, array{de: ?string, para: string}> $alteracoes
     */
    public function atualizar(int $id, array $alteracoes): void
    {
        $permitidos = ['nome', 'turma', 'turno', 'email'];
        $sets = [];
        $valores = [];
        foreach ($alteracoes as $campo => $mudanca) {
            if (in_array($campo, $permitidos, true)) {
                $sets[] = "$campo = ?";
                $valores[] = $mudanca['para'];
            }
        }
        if (!$sets) {
            return;
        }
        $valores[] = $id;
        $this->pdo->prepare('UPDATE alunos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($valores);
    }

    /** @return list<array> */
    private function consultarEmLotes(string $coluna, array $valores, bool $bloquear = false): array
    {
        $valores = array_values(array_unique(array_filter($valores, fn ($v) => is_string($v) && $v !== '')));
        $linhas = [];
        foreach (array_chunk($valores, self::LOTE_CONSULTA) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));
            // $coluna vem sempre do próprio código (matricula, nome ou email); os valores vão como parâmetros.
            $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . " FROM alunos WHERE $coluna IN ($marcadores)" . ($bloquear ? ' FOR UPDATE' : ''));
            $stmt->execute($lote);
            array_push($linhas, ...$stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $linhas;
    }
}
