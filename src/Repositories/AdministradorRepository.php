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
 *   * Sempre sobra pelo menos um administrador ativo: o último não pode ser desativado
 *     nem perder o perfil de administrador.
 */
final class AdministradorRepository
{
    public const PERFIS = ['administrador', 'bibliotecaria', 'auxiliar', 'consulta'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Filtros: q (nome ou e-mail), perfil, status (ativo|inativo), ordem (nome|email|criado|acesso), dir.
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
        if (in_array($f['perfil'] ?? '', self::PERFIS, true)) {
            $where[] = 'perfil = ?';
            $params[] = $f['perfil'];
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

    /** Totais por perfil e situação, para o topo da página. */
    public function resumo(): array
    {
        $r = $this->pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(ativo = 1), 0) AS ativos,
                                       COALESCE(SUM(ativo = 1 AND perfil = 'administrador'), 0) AS administradores,
                                       COALESCE(SUM(ativo = 1 AND perfil = 'bibliotecaria'), 0) AS bibliotecarias
                                FROM administradores")->fetch(PDO::FETCH_ASSOC);
        return array_map('intval', $r);
    }

    public function emailEmUso(string $email, ?int $ignorarId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM administradores WHERE email = ? AND id <> ?');
        $stmt->execute([mb_strtolower(trim($email)), $ignorarId ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Cria a conta. $senhaHash já vem de password_hash(). */
    public function criar(string $nome, string $email, string $perfil, string $cargo, string $senhaHash): int
    {
        $this->perfilValido($perfil);
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
     * Atualiza nome, e-mail, perfil, cargo e situação. Recusa deixar o sistema sem administrador ativo.
     * @return array<string, array{0: mixed, 1: mixed}> campos alterados [antes, depois]
     */
    public function atualizar(int $id, string $nome, string $email, string $perfil, string $cargo, bool $ativo): array
    {
        $this->perfilValido($perfil);
        return $this->transacao(function () use ($id, $nome, $email, $perfil, $cargo, $ativo) {
            $atual = $this->bloquear($id);
            $perdeAdmin = $atual['perfil'] === 'administrador' && (int)$atual['ativo'] === 1
                && ($perfil !== 'administrador' || !$ativo);
            if ($perdeAdmin && $this->outrosAdministradoresAtivos($id) === 0) {
                throw new RuntimeException('Esta é a única conta de administrador ativa. Cadastre ou ative outro administrador antes de mudar o perfil ou desativar esta conta.');
            }
            $novo = ['nome' => trim($nome), 'email' => mb_strtolower(trim($email)), 'perfil' => $perfil, 'cargo' => $cargo, 'ativo' => $ativo ? 1 : 0];
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
        // FOR UPDATE: duas pessoas não conseguem rebaixar os dois últimos administradores ao mesmo tempo.
        $stmt = $this->pdo->prepare("SELECT id FROM administradores WHERE perfil = 'administrador' AND ativo = 1 AND id <> ? FOR UPDATE");
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

    private function perfilValido(string $perfil): void
    {
        if (!in_array($perfil, self::PERFIS, true)) {
            throw new RuntimeException('Perfil de acesso inválido.');
        }
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
