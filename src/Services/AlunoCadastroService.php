<?php

namespace Biblioteca\Services;

use Biblioteca\Repositories\AlunoRepository;
use PDO;
use PDOException;
use Throwable;

/**
 * Cadastro individual, correção e inativação de alunos (Gerenciamento → Alunos).
 *
 * Regras (as mesmas da importação, para não existirem dois conjuntos de regras):
 *   * Limpeza e formato dos campos: AlunoImportService::validarCadastro() — a mesma função que
 *     valida cada linha da planilha. Aqui um e-mail ou turno inválido vira erro (a pessoa corrige
 *     na hora) em vez de ficar em branco.
 *   * A matrícula identifica o aluno e não se repete (maiúsculas e minúsculas contam como iguais).
 *   * Nome ou e-mail iguais aos de outro aluno são "possível duplicidade": só grava depois de a
 *     pessoa confirmar que são alunos diferentes (como na prévia da importação).
 *   * O id interno nunca muda: corrigir nome, matrícula ou e-mail mantém todos os empréstimos,
 *     renovações e devoluções ligados ao mesmo aluno.
 *   * Nenhum aluno é excluído. Inativar só impede novos empréstimos (EmprestimoService já recusa
 *     aluno inativo); os empréstimos em aberto continuam e podem ser devolvidos e renovados.
 *   * Cada gravação fica em acoes_administrativas (quem, quando, aluno, campos alterados), na
 *     mesma transação. Os valores digitados não vão para o registro, só os nomes dos campos e,
 *     quando a matrícula muda, a matrícula anterior (para rastrear a identidade do cadastro).
 */
final class AlunoCadastroService
{
    /** Rótulos dos campos editáveis (na ordem do formulário). */
    public const CAMPOS = ['nome' => 'Nome', 'matricula' => 'Matrícula', 'turma' => 'Turma', 'turno' => 'Turno', 'email' => 'E-mail institucional'];

    public function __construct(
        private PDO $pdo,
        private AlunoRepository $repo,
        private AlunoImportService $regras,
        private string $ip = 'cli',
    ) {
    }

    /**
     * Cadastra um aluno fora da importação.
     * @param bool $confirmarDuplicidade a pessoa confirmou que nome/e-mail repetidos são de outro aluno
     * @return int id do novo cadastro
     */
    public function cadastrar(array $entrada, int $responsavelId, bool $confirmarDuplicidade = false): int
    {
        $d = $this->validar($entrada);
        return $this->transacao(function () use ($d, $responsavelId, $confirmarDuplicidade) {
            $this->conferirMatricula($d['matricula'], null);
            $this->conferirDuplicidades($d, null, $confirmarDuplicidade);
            $id = $this->gravarComChaveUnica(fn () => $this->repo->incluir($d), $d['matricula']);
            $this->acao($responsavelId, 'aluno.cadastrar', $id, ['origem' => 'cadastro individual']);
            return $id;
        });
    }

    /**
     * Corrige o cadastro. Campos deixados em branco (turma, turno, e-mail) ficam vazios no cadastro.
     * @param bool $confirmarDuplicidade nome/e-mail iguais aos de outro aluno conferidos
     * @param bool $confirmarMatricula a pessoa confirmou a troca da matrícula
     * @return list<string> campos alterados (vazio = nada mudou)
     */
    public function editar(int $id, array $entrada, int $responsavelId, bool $confirmarDuplicidade = false, bool $confirmarMatricula = false): array
    {
        $d = $this->validar($entrada);
        return $this->transacao(function () use ($id, $d, $responsavelId, $confirmarDuplicidade, $confirmarMatricula) {
            $atual = $this->repo->buscar($id, true);
            if (!$atual) {
                throw new AlunoCadastroException('Aluno não encontrado.', ['geral' => 'O cadastro não existe mais.']);
            }
            $mudancas = self::diferencas($atual, $d);
            if (!$mudancas) {
                return [];
            }
            if (isset($mudancas['matricula'])) {
                $this->conferirMatricula($d['matricula'], $id);
                if (!$confirmarMatricula) {
                    throw new AlunoCadastroException('Confirme a troca da matrícula.', ['confirmar_matricula' => 'A matrícula identifica o aluno. Marque a confirmação para corrigi-la. Os empréstimos continuam ligados a este mesmo aluno.'], [], true);
                }
            }
            // Só confere duplicidade do que mudou: um nome que já era igual ao de outro aluno não bloqueia outras correções.
            if (isset($mudancas['nome']) || isset($mudancas['email'])) {
                $this->conferirDuplicidades([
                    'nome' => isset($mudancas['nome']) ? $d['nome'] : null,
                    'email' => isset($mudancas['email']) ? $d['email'] : null,
                ], $id, $confirmarDuplicidade);
            }
            $this->gravarComChaveUnica(fn () => $this->repo->corrigir($id, array_intersect_key($d, $mudancas)), $d['matricula']);
            $detalhes = ['campos' => implode(', ', array_map(fn ($c) => self::CAMPOS[$c], array_keys($mudancas)))];
            if (isset($mudancas['matricula'])) {
                $detalhes['matricula_anterior'] = $atual['matricula'];
            }
            $this->acao($responsavelId, 'aluno.editar', $id, $detalhes);
            return array_keys($mudancas);
        });
    }

    /** Inativa ou reativa um cadastro. Devolve false quando ele já estava assim. */
    public function definirAtivo(int $id, bool $ativo, int $responsavelId, string $motivo = ''): bool
    {
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
        if (mb_strlen($motivo) > 200) {
            throw new AlunoCadastroException('O motivo pode ter no máximo 200 caracteres.', ['motivo' => 'Use no máximo 200 caracteres.']);
        }
        return $this->transacao(function () use ($id, $ativo, $responsavelId, $motivo) {
            $atual = $this->repo->buscar($id, true);
            if (!$atual) {
                throw new AlunoCadastroException('Aluno não encontrado.', ['geral' => 'O cadastro não existe mais.']);
            }
            if ((bool)$atual['ativo'] === $ativo) {
                return false;
            }
            $this->repo->definirAtivo($id, $ativo);
            $this->acao($responsavelId, $ativo ? 'aluno.reativar' : 'aluno.inativar', $id, $motivo !== '' ? ['motivo' => $motivo] : []);
            return true;
        });
    }

    /**
     * Mesma validação da importação; e-mail e turno inválidos viram erro.
     * @return array{nome: string, matricula: string, turma: ?string, turno: ?string, email: ?string}
     */
    public function validar(array $entrada): array
    {
        $r = $this->regras->validarCadastro($entrada);
        if ($r['erros']) {
            throw new AlunoCadastroException('Confira os campos destacados.', $r['erros']);
        }
        return $r['dados'];
    }

    /** Campos que mudam (a comparação do e-mail ignora maiúsculas). @return array<string, true> */
    public static function diferencas(array $atual, array $novo): array
    {
        $mudancas = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $de = $atual[$campo] === null || $atual[$campo] === '' ? null : (string)$atual[$campo];
            $para = $novo[$campo] ?? null;
            $igual = $campo === 'email' ? mb_strtolower((string)$de) === mb_strtolower((string)$para) : $de === $para;
            if (!$igual) {
                $mudancas[$campo] = true;
            }
        }
        return $mudancas;
    }

    private function conferirMatricula(string $matricula, ?int $exceto): void
    {
        $outro = $this->repo->outroComMatricula($matricula, $exceto);
        if ($outro) {
            throw new AlunoCadastroException('Matrícula já cadastrada.', ['matricula' => 'A matrícula ' . $outro['matricula'] . ' já pertence a outro aluno (' . $outro['nome'] . '). Cada matrícula só pode ser usada uma vez.']);
        }
    }

    private function conferirDuplicidades(array $d, ?int $exceto, bool $confirmado): void
    {
        $iguais = $this->repo->possiveisDuplicidades($d['nome'], $d['email'], $exceto);
        if ($iguais && !$confirmado) {
            throw new AlunoCadastroException('Possível duplicidade.', ['confirmar_duplicidade' => 'Já existe aluno com o mesmo nome ou e-mail. Confira a lista abaixo e confirme que é outra pessoa.'], $iguais, true);
        }
    }

    /** Executa a gravação traduzindo a violação da chave única de matrícula (dois cadastros ao mesmo tempo). */
    private function gravarComChaveUnica(callable $fn, string $matricula): mixed
    {
        try {
            return $fn();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new AlunoCadastroException('Matrícula já cadastrada.', ['matricula' => 'A matrícula ' . $matricula . ' já pertence a outro aluno.']);
            }
            throw $e;
        }
    }

    private function acao(int $responsavelId, string $acao, int $alunoId, array $detalhes): void
    {
        $this->pdo->prepare('INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$responsavelId ?: null, $acao, 'aluno', $alunoId, $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null, substr($this->ip, 0, 45)]);
    }

    private function transacao(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
