<?php

namespace Biblioteca\Repositories;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Contas de acesso ao painel (tabela administradores).
 *
 * Regras:
 *   * E-mail único, sempre gravado em minúsculas.
 *   * Senhas só como hash (password_hash); nunca são lidas de volta nem registradas.
 *   * Contas não são excluídas: são desativadas (o histórico de ações aponta para elas).
 *   * Único perfil: toda conta é gravada como administrador (migração 200). O campo cargo é só a
 *     função da pessoa (ex.: Bibliotecária), sem efeito nas permissões.
 *   * Sempre sobra pelo menos uma conta ativa: a última não pode ser desativada.
 */
final class AdministradorRepository
{
    /** Perfil gravado em toda conta (a coluna continua existindo por compatibilidade). */
    public const PERFIL = 'administrador';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Filtros: q (nome ou e-mail), status (ativo|inativo), ordem (nome|email|criado|acesso), dir.
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 25): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(nome LIKE ? OR email LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if (($f['status'] ?? '') === 'ativo') {
            $where[] = 'ativo = 1';
        } elseif (($f['status'] ?? '') === 'inativo') {
            $where[] = 'ativo = 0';
        }
        $sqlWhere = implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM administradores WHERE $sqlWhere");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $porPagina = max(1, min(100, $porPagina));
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $ordem = match ($f['ordem'] ?? 'nome') {
            'email' => "email $dir",
            'criado' => "criado_em $dir, id $dir",
            'acesso' => "(ultimo_acesso IS NULL), ultimo_acesso $dir",
            default => "nome $dir, id",
        };
        $stmt = $this->pdo->prepare("SELECT id, nome, email, perfil, cargo, ativo, ultimo_acesso, criado_em, atualizado_em
                                     FROM administradores WHERE $sqlWhere ORDER BY $ordem
                                     LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
        $stmt->execute($params);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($itens as &$a) {
            $a['id'] = (int)$a['id'];
            $a['ativo'] = (bool)$a['ativo'];
        }
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, email, perfil, cargo, ativo, ultimo_acesso, criado_em, atualizado_em FROM administradores WHERE id = ?');
        $stmt->execute([$id]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            return null;
        }
        $a['id'] = (int)$a['id'];
        $a['ativo'] = (bool)$a['ativo'];
        return $a;
    }

    /** Totais por situação, para o topo da página. */
    public function resumo(): array
    {
        $r = $this->pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(ativo = 1), 0) AS ativos,
                                       COALESCE(SUM(ultimo_acesso IS NULL), 0) AS nunca_entraram
                                FROM administradores")->fetch(PDO::FETCH_ASSOC);
        return array_map('intval', $r);
    }

    public function emailEmUso(string $email, ?int $ignorarId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM administradores WHERE email = ? AND id <> ?');
        $stmt->execute([mb_strtolower(trim($email)), $ignorarId ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Cria a conta (sempre administrador). $cargo é a função, opcional. $senhaHash já vem de password_hash(). */
    public function criar(string $nome, string $email, string $cargo, string $senhaHash): int
    {
        $perfil = self::PERFIL;
        $cargo = trim($cargo) !== '' ? trim($cargo) : 'Administrador';
        try {
            $this->pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil, cargo, ativo) VALUES (?, ?, ?, ?, ?, 1)')
                ->execute([trim($nome), mb_strtolower(trim($email)), $senhaHash, $perfil, $cargo]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Já existe uma conta com este e-mail.');
            }
            throw $e;
        }
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atualiza nome, e-mail, função (cargo) e situação. Recusa deixar o sistema sem nenhuma conta ativa.
     * @return array<string, array{0: mixed, 1: mixed}> campos alterados [antes, depois]
     */
    public function atualizar(int $id, string $nome, string $email, string $cargo, bool $ativo): array
    {
        $cargo = trim($cargo) !== '' ? trim($cargo) : 'Administrador';
        return $this->transacao(function () use ($id, $nome, $email, $cargo, $ativo) {
            $atual = $this->bloquear($id);
            if ((int)$atual['ativo'] === 1 && !$ativo && $this->outrosAdministradoresAtivos($id) === 0) {
                throw new RuntimeException('Esta é a única conta de administrador ativa. Cadastre ou ative outro administrador antes de desativar esta conta.');
            }
            $novo = ['nome' => trim($nome), 'email' => mb_strtolower(trim($email)), 'perfil' => self::PERFIL, 'cargo' => $cargo, 'ativo' => $ativo ? 1 : 0];
            try {
                $this->pdo->prepare('UPDATE administradores SET nome = ?, email = ?, perfil = ?, cargo = ?, ativo = ? WHERE id = ?')
                    ->execute([...array_values($novo), $id]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new RuntimeException('Já existe uma conta com este e-mail.');
                }
                throw $e;
            }
            $alteracoes = [];
            foreach ($novo as $campo => $valor) {
                if ((string)$atual[$campo] !== (string)$valor) {
                    $alteracoes[$campo] = [$atual[$campo], $valor];
                }
            }
            return $alteracoes;
        });
    }

    /** Grava uma nova senha (hash). */
    public function definirSenha(int $id, string $senhaHash): void
    {
        $this->bloquear($id);
        $this->pdo->prepare('UPDATE administradores SET senha_hash = ? WHERE id = ?')->execute([$senhaHash, $id]);
    }

    public function senhaHash(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT senha_hash FROM administradores WHERE id = ?');
        $stmt->execute([$id]);
        $h = $stmt->fetchColumn();
        return $h === false ? null : (string)$h;
    }

    private function outrosAdministradoresAtivos(int $id): int
    {
        // FOR UPDATE: duas pessoas não conseguem desativar as duas últimas contas ao mesmo tempo.
        $stmt = $this->pdo->prepare("SELECT id FROM administradores WHERE ativo = 1 AND id <> ? FOR UPDATE");
        $stmt->execute([$id]);
        return count($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function bloquear(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, email, perfil, cargo, ativo FROM administradores WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            throw new RuntimeException('Conta não encontrada.');
        }
        return $a;
    }

    private function transacao(callable $fn): mixed
    {
        $propria = !$this->pdo->inTransaction();
        if ($propria) {
            $this->pdo->beginTransaction();
        }
        try {
            $r = $fn();
            if ($propria) {
                $this->pdo->commit();
            }
            return $r;
        } catch (\Throwable $e) {
            if ($propria && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
