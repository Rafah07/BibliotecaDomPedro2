<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;
use PDOException;

/**
 * Organização Física da biblioteca (migração 140): setores (opcionais), estantes e prateleiras.
 *
 * Modelo (sem tabelas redundantes):
 *   * setores        salas ou setores, opcionais;
 *   * estantes       identificação curta (A, B, E01...), nome, categoria/finalidade, ordem, situação,
 *                    responsável (administrador) e/ou nome de equipe (só organização, não dá acesso);
 *   * localizacoes   cada PRATELEIRA é uma linha de localizacoes com estante_id. É a mesma tabela que
 *                    os exemplares já usam (exemplares.localizacao_id), então o histórico, os filtros e
 *                    os relatórios continuam funcionando. As colunas de texto (setor, estante,
 *                    prateleira) são mantidas iguais à estrutura para as telas antigas.
 *
 * Regras:
 *   * Nada é criado sozinho: só o que o administrador cadastra (inclusive "Criar várias estantes").
 *   * A identificação e o nome da estante não se repetem no mesmo setor; a identificação da
 *     prateleira não se repete na mesma estante (pode se repetir em estantes diferentes).
 *   * Alterar ou desativar uma estante/prateleira nunca mexe nos exemplares. Uma prateleira com
 *     exemplares (ou já usada em inventários) não é excluída: transfira os exemplares ou desative-a.
 *   * Mover um exemplar só muda a localização dele (com histórico); os dados da obra não mudam.
 *   * A localização antiga das planilhas nunca é usada aqui.
 *   * Tudo em transação; erros de duplicidade viram mensagens claras.
 */
final class OrganizacaoFisicaService
{
    use ConsultasPdo;

    public const MAX_PRATELEIRAS = 50;
    public const MAX_ESTANTES_LOTE = 100;

    public function __construct(private PDO $pdo, private ExemplarService $exemplares)
    {
    }

    // ---------------------------------------------------------------
    // Setores
    // ---------------------------------------------------------------

    /** Cria (sem $id) ou edita um setor. @return int id */
    public function salvarSetor(array $dados, int $responsavelId, ?int $id = null): int
    {
        $nome = $this->texto($dados['nome'] ?? '', 80);
        if ($nome === '') {
            throw new AcervoException('Informe o nome do setor ou sala (ex.: Sala de leitura).');
        }
        $ident = $this->identificacao($dados['identificacao'] ?? '', 'do setor', false);
        $descricao = $this->texto($dados['descricao'] ?? '', 255) ?: null;
        $ordem = $this->ordem($dados['ordem'] ?? null);
        return $this->transacao(function () use ($nome, $ident, $descricao, $ordem, $responsavelId, $id) {
            try {
                if ($id === null) {
                    $ordem ??= (int)$this->valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM setores');
                    $this->executar('INSERT INTO setores (nome, identificacao, descricao, ordem) VALUES (?, ?, ?, ?)', [$nome, $ident, $descricao, $ordem]);
                    $id = (int)$this->pdo->lastInsertId();
                    $this->registrarAcao($responsavelId, 'organizacao.setor_criar', 'setor', $id, ['nome' => $nome]);
                    return $id;
                }
                $antes = $this->linha('SELECT * FROM setores WHERE id = ? FOR UPDATE', [$id]);
                if (!$antes) {
                    throw new AcervoException('Setor não encontrado.');
                }
                $this->executar('UPDATE setores SET nome = ?, identificacao = ?, descricao = ?, ordem = ? WHERE id = ?', [$nome, $ident, $descricao, $ordem ?? (int)$antes['ordem'], $id]);
                if ($antes['nome'] !== $nome) {
                    // As prateleiras guardam o nome do setor em texto (telas antigas e filtros por setor).
                    $this->executar('UPDATE localizacoes l JOIN estantes e ON e.id = l.estante_id SET l.setor = ? WHERE e.setor_id = ?', [$nome, $id]);
                }
                $this->registrarAcao($responsavelId, 'organizacao.setor_editar', 'setor', $id, ['antes' => $antes['nome'], 'depois' => $nome]);
                return $id;
            } catch (PDOException $e) {
                throw $this->duplicidade($e, 'Já existe um setor com este nome ou identificação.');
            }
        });
    }

    public function definirSetorAtivo(int $id, bool $ativo, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $ativo, $responsavelId) {
            if (!$this->linha('SELECT id FROM setores WHERE id = ? FOR UPDATE', [$id])) {
                throw new AcervoException('Setor não encontrado.');
            }
            $this->executar('UPDATE setores SET ativo = ? WHERE id = ?', [$ativo ? 1 : 0, $id]);
            $this->registrarAcao($responsavelId, $ativo ? 'organizacao.setor_ativar' : 'organizacao.setor_desativar', 'setor', $id);
        });
    }

    /** Exclui um setor sem estantes. Com estantes, mova-as para outro setor ou desative o setor. */
    public function excluirSetor(int $id, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $responsavelId) {
            $s = $this->linha('SELECT * FROM setores WHERE id = ? FOR UPDATE', [$id]);
            if (!$s) {
                throw new AcervoException('Setor não encontrado.');
            }
            $n = (int)$this->valor('SELECT COUNT(*) FROM estantes WHERE setor_id = ?', [$id]);
            if ($n > 0) {
                throw new AcervoException('O setor "' . $s['nome'] . '" tem ' . $n . ' estante(s). Mude as estantes de setor ou desative o setor; nada foi excluído.');
            }
            $this->executar('DELETE FROM setores WHERE id = ?', [$id]);
            $this->registrarAcao($responsavelId, 'organizacao.setor_excluir', 'setor', $id, ['nome' => $s['nome']]);
        });
    }

    // ---------------------------------------------------------------
    // Estantes
    // ---------------------------------------------------------------

    /**
     * "+ Adicionar estante": cria a estante e as suas prateleiras (1, 2, 3...) na mesma transação.
     * Dados: identificacao*, nome, descricao, categoria, setor_id, ordem, prateleiras (quantidade).
     * @return int id da estante
     */
    public function criarEstante(array $dados, int $responsavelId): int
    {
        $d = $this->validarEstante($dados);
        $qtd = $this->quantidade($dados['prateleiras'] ?? 0, 0, self::MAX_PRATELEIRAS, 'prateleiras');
        return $this->transacao(function () use ($d, $qtd, $responsavelId) {
            $id = $this->inserirEstante($d, $responsavelId);
            $this->criarPrateleiras($id, $qtd);
            $this->registrarAcao($responsavelId, 'organizacao.estante_criar', 'estante', $id, ['identificacao' => $d['identificacao'], 'nome' => $d['nome'], 'prateleiras' => $qtd]);
            return $id;
        });
    }

    /**
     * "Criar várias estantes": gera $quantidade estantes com identificações sequenciais, cada uma
     * com $prateleiras prateleiras. Identificações que já existem no setor são puladas (nunca repete).
     * Dados: quantidade*, prateleiras, padrao ('letras' = A, B, C... | 'numeros' = prefixo + 01, 02...),
     *        prefixo (só números), inicio (primeira letra ou número), setor_id, categoria.
     * @return list<array{id: int, identificacao: string}>
     */
    public function criarEstantesEmLote(array $dados, int $responsavelId): array
    {
        $quantidade = $this->quantidade($dados['quantidade'] ?? 0, 1, self::MAX_ESTANTES_LOTE, 'estantes');
        $prateleiras = $this->quantidade($dados['prateleiras'] ?? 0, 0, self::MAX_PRATELEIRAS, 'prateleiras');
        $padrao = ($dados['padrao'] ?? 'letras') === 'numeros' ? 'numeros' : 'letras';
        $prefixo = $this->identificacao($dados['prefixo'] ?? '', 'do prefixo', false) ?? '';
        if (mb_strlen($prefixo) > 10) {
            throw new AcervoException('Use um prefixo curto (até 10 caracteres), por exemplo E.');
        }
        $inicioTexto = mb_strtoupper(trim((string)($dados['inicio'] ?? '')));
        $setorId = $this->setorValido($dados['setor_id'] ?? null);
        $categoria = $this->texto($dados['categoria'] ?? '', 120) ?: null;

        return $this->transacao(function () use ($quantidade, $prateleiras, $padrao, $prefixo, $inicioTexto, $setorId, $categoria, $responsavelId) {
            // Trava as estantes do setor: dois lotes ao mesmo tempo não geram a mesma identificação.
            $existentes = array_map('mb_strtoupper', array_column($this->todas('SELECT identificacao FROM estantes WHERE setor_chave = ? FOR UPDATE', [$setorId ?? 0]), 'identificacao'));
            $nomes = array_map('mb_strtoupper', array_column($this->todas('SELECT nome FROM estantes WHERE setor_chave = ?', [$setorId ?? 0]), 'nome'));
            $ordem = (int)$this->valor('SELECT COALESCE(MAX(ordem), 0) FROM estantes WHERE setor_chave = ?', [$setorId ?? 0]);
            $n = $padrao === 'letras' ? ($inicioTexto !== '' && preg_match('/^[A-Z]{1,3}$/', $inicioTexto) ? self::numeroDaLetra($inicioTexto) : 1)
                                     : ($inicioTexto !== '' && ctype_digit($inicioTexto) ? max(1, (int)$inicioTexto) : 1);
            $largura = $padrao === 'numeros' ? max(2, strlen((string)($n + $quantidade + count($existentes)))) : 0;
            $criadas = [];
            $tentativas = 0;
            while (count($criadas) < $quantidade) {
                if (++$tentativas > $quantidade + 2000) {
                    throw new AcervoException('Não foi possível gerar identificações livres. Escolha outro início ou prefixo.');
                }
                $ident = $padrao === 'letras' ? self::letraDoNumero($n) : $prefixo . str_pad((string)$n, $largura, '0', STR_PAD_LEFT);
                $n++;
                $nome = 'Estante ' . $ident;
                if (mb_strlen($ident) > 20 || in_array(mb_strtoupper($ident), $existentes, true) || in_array(mb_strtoupper($nome), $nomes, true)) {
                    continue;
                }
                $id = $this->inserirEstante(['identificacao' => $ident, 'nome' => $nome, 'descricao' => null, 'categoria' => $categoria, 'setor_id' => $setorId, 'ordem' => ++$ordem], $responsavelId);
                $this->criarPrateleiras($id, $prateleiras);
                $existentes[] = mb_strtoupper($ident);
                $criadas[] = ['id' => $id, 'identificacao' => $ident];
            }
            $this->registrarAcao($responsavelId, 'organizacao.estantes_lote', 'estante', null, [
                'quantidade' => $quantidade, 'prateleiras' => $prateleiras, 'identificacoes' => array_column($criadas, 'identificacao'), 'setor_id' => $setorId,
            ]);
            return $criadas;
        });
    }

    /** Edita os dados da estante. As prateleiras e os exemplares continuam onde estão. */
    public function editarEstante(int $id, array $dados, int $responsavelId): void
    {
        $d = $this->validarEstante($dados);
        $this->transacao(function () use ($id, $d, $responsavelId) {
            $antes = $this->linha('SELECT * FROM estantes WHERE id = ? FOR UPDATE', [$id]);
            if (!$antes) {
                throw new AcervoException('Estante não encontrada.');
            }
            try {
                $this->executar('UPDATE estantes SET identificacao = ?, nome = ?, descricao = ?, categoria = ?, setor_id = ?, ordem = ? WHERE id = ?',
                    [$d['identificacao'], $d['nome'], $d['descricao'], $d['categoria'], $d['setor_id'], $d['ordem'] ?? (int)$antes['ordem'], $id]);
            } catch (PDOException $e) {
                throw $this->duplicidade($e, 'Já existe outra estante com esta identificação ou este nome no mesmo setor.');
            }
            $this->sincronizarTexto($id);
            $this->registrarAcao($responsavelId, 'organizacao.estante_editar', 'estante', $id, [
                'antes' => ['identificacao' => $antes['identificacao'], 'nome' => $antes['nome'], 'setor_id' => $antes['setor_id'], 'categoria' => $antes['categoria']],
                'depois' => ['identificacao' => $d['identificacao'], 'nome' => $d['nome'], 'setor_id' => $d['setor_id'], 'categoria' => $d['categoria']],
            ]);
        });
    }

    /** Desativar: a estante deixa de aparecer para novos exemplares; os que estão nela continuam lá. */
    public function definirEstanteAtiva(int $id, bool $ativa, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $ativa, $responsavelId) {
            if (!$this->linha('SELECT id FROM estantes WHERE id = ? FOR UPDATE', [$id])) {
                throw new AcervoException('Estante não encontrada.');
            }
            $this->executar('UPDATE estantes SET ativa = ? WHERE id = ?', [$ativa ? 1 : 0, $id]);
            $this->registrarAcao($responsavelId, $ativa ? 'organizacao.estante_ativar' : 'organizacao.estante_desativar', 'estante', $id);
        });
    }

    /**
     * Exclusão definitiva: só para estante cujas prateleiras não têm exemplares e nunca foram usadas
     * em inventários. Caso contrário, transfira os exemplares ou desative a estante.
     */
    public function excluirEstante(int $id, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $responsavelId) {
            $e = $this->linha('SELECT * FROM estantes WHERE id = ? FOR UPDATE', [$id]);
            if (!$e) {
                throw new AcervoException('Estante não encontrada.');
            }
            $prateleiras = array_map('intval', array_column($this->todas('SELECT id FROM localizacoes WHERE estante_id = ? FOR UPDATE', [$id]), 'id'));
            foreach ($prateleiras as $p) {
                $this->exigirLivre($p, 'a estante');
            }
            if ($prateleiras) {
                $this->executar('DELETE FROM localizacoes WHERE estante_id = ?', [$id]);
            }
            $this->executar('DELETE FROM estantes WHERE id = ?', [$id]);
            $this->registrarAcao($responsavelId, 'organizacao.estante_excluir', 'estante', $id, ['identificacao' => $e['identificacao'], 'nome' => $e['nome'], 'prateleiras' => count($prateleiras)]);
        });
    }

    /**
     * Responsável pela estante: um administrador existente e/ou um nome de equipe (ex.: Equipe 1).
     * É só organização: não bloqueia a edição por outras pessoas e não dá acesso ao sistema.
     */
    public function definirResponsavel(int $id, ?int $administradorId, string $equipe, int $responsavelId): void
    {
        $equipe = $this->texto($equipe, 80) ?: null;
        $this->transacao(function () use ($id, $administradorId, $equipe, $responsavelId) {
            $e = $this->linha('SELECT * FROM estantes WHERE id = ? FOR UPDATE', [$id]);
            if (!$e) {
                throw new AcervoException('Estante não encontrada.');
            }
            if ($administradorId !== null && !$this->valor('SELECT 1 FROM administradores WHERE id = ? AND ativo = 1', [$administradorId])) {
                throw new AcervoException('Escolha uma pessoa com acesso ativo ao painel, ou deixe só o nome da equipe.');
            }
            $this->executar('UPDATE estantes SET responsavel_id = ?, equipe = ?, responsavel_definido_em = ' . ($administradorId === null && $equipe === null ? 'NULL' : 'NOW()') . ' WHERE id = ?',
                [$administradorId, $equipe, $id]);
            $this->registrarAcao($responsavelId, 'organizacao.estante_responsavel', 'estante', $id, [
                'antes' => ['responsavel_id' => $e['responsavel_id'] !== null ? (int)$e['responsavel_id'] : null, 'equipe' => $e['equipe']],
                'depois' => ['responsavel_id' => $administradorId, 'equipe' => $equipe],
            ]);
        });
    }

    // ---------------------------------------------------------------
    // Prateleiras
    // ---------------------------------------------------------------

    /**
     * Adiciona prateleiras ao fim da estante. Com $identificacao, cria uma com esse nome;
     * sem ela, cria $quantidade prateleiras numeradas (continua a numeração existente).
     * @return list<int> ids criados
     */
    public function adicionarPrateleiras(int $estanteId, int $quantidade, string $identificacao, int $responsavelId): array
    {
        $ident = $this->identificacaoPrateleira($identificacao, false);
        if ($ident === null) {
            $quantidade = $this->quantidade($quantidade, 1, self::MAX_PRATELEIRAS, 'prateleiras');
        }
        return $this->transacao(function () use ($estanteId, $quantidade, $ident, $responsavelId) {
            if (!$this->linha('SELECT id FROM estantes WHERE id = ? FOR UPDATE', [$estanteId])) {
                throw new AcervoException('Estante não encontrada.');
            }
            $total = (int)$this->valor('SELECT COUNT(*) FROM localizacoes WHERE estante_id = ?', [$estanteId]);
            if ($total + ($ident !== null ? 1 : $quantidade) > self::MAX_PRATELEIRAS * 2) {
                throw new AcervoException('Uma estante pode ter no máximo ' . (self::MAX_PRATELEIRAS * 2) . ' prateleiras.');
            }
            try {
                $ids = $ident !== null ? [$this->inserirPrateleira($estanteId, $ident)] : $this->criarPrateleiras($estanteId, $quantidade);
            } catch (PDOException $e) {
                throw $this->duplicidade($e, 'Já existe uma prateleira "' . $ident . '" nesta estante.');
            }
            $this->registrarAcao($responsavelId, 'organizacao.prateleiras_adicionar', 'estante', $estanteId, ['quantidade' => count($ids)]);
            return $ids;
        });
    }

    /** Renomeia a prateleira (identificação e descrição). Os exemplares dela continuam nela. */
    public function editarPrateleira(int $id, string $identificacao, string $descricao, int $responsavelId): void
    {
        $ident = $this->identificacaoPrateleira($identificacao, true);
        $descricao = $this->texto($descricao, 255) ?: null;
        $this->transacao(function () use ($id, $ident, $descricao, $responsavelId) {
            $p = $this->prateleiraBloqueada($id);
            try {
                $this->executar('UPDATE localizacoes SET prateleira = ?, descricao = ? WHERE id = ?', [$ident, $descricao, $id]);
            } catch (PDOException $e) {
                throw $this->duplicidade($e, 'Já existe uma prateleira "' . $ident . '" nesta estante.');
            }
            $this->registrarAcao($responsavelId, 'organizacao.prateleira_editar', 'localizacao', $id, ['antes' => $p['prateleira'], 'depois' => $ident]);
        });
    }

    /** Sobe (-1) ou desce (+1) a prateleira na ordem de exibição da estante. */
    public function moverPrateleira(int $id, int $direcao, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $direcao, $responsavelId) {
            $p = $this->prateleiraBloqueada($id);
            $lista = array_map('intval', array_column($this->todas('SELECT id FROM localizacoes WHERE estante_id = ? ORDER BY ordem, id FOR UPDATE', [$p['estante_id']]), 'id'));
            $pos = array_search($id, $lista, true);
            $novo = $pos + ($direcao < 0 ? -1 : 1);
            if ($pos === false || $novo < 0 || $novo >= count($lista)) {
                return;
            }
            [$lista[$pos], $lista[$novo]] = [$lista[$novo], $lista[$pos]];
            foreach ($lista as $i => $pid) {
                $this->executar('UPDATE localizacoes SET ordem = ? WHERE id = ?', [$i + 1, $pid]);
            }
            $this->registrarAcao($responsavelId, 'organizacao.prateleira_ordem', 'localizacao', $id, ['direcao' => $direcao < 0 ? 'subir' : 'descer']);
        });
    }

    public function definirPrateleiraAtiva(int $id, bool $ativa, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $ativa, $responsavelId) {
            $this->prateleiraBloqueada($id);
            $this->executar('UPDATE localizacoes SET ativa = ? WHERE id = ?', [$ativa ? 1 : 0, $id]);
            $this->registrarAcao($responsavelId, $ativa ? 'localizacao.ativar' : 'localizacao.desativar', 'localizacao', $id);
        });
    }

    /** Exclusão definitiva de uma prateleira vazia e nunca usada em inventários. */
    public function excluirPrateleira(int $id, int $responsavelId): void
    {
        $this->transacao(function () use ($id, $responsavelId) {
            $p = $this->prateleiraBloqueada($id);
            $this->exigirLivre($id, 'a prateleira');
            $this->executar('DELETE FROM localizacoes WHERE id = ?', [$id]);
            $this->registrarAcao($responsavelId, 'organizacao.prateleira_excluir', 'localizacao', $id, ['estante_id' => (int)$p['estante_id'], 'prateleira' => $p['prateleira']]);
        });
    }

    /**
     * Transfere TODOS os exemplares de uma localização para outra (ex.: antes de excluir uma
     * prateleira). Cada exemplar recebe a sua movimentação no histórico. @return int transferidos
     */
    public function transferirExemplares(int $origemId, int $destinoId, int $responsavelId, string $motivo = ''): int
    {
        if ($origemId === $destinoId) {
            throw new AcervoException('Escolha uma prateleira de destino diferente da atual.');
        }
        return $this->transacao(function () use ($origemId, $destinoId, $responsavelId, $motivo) {
            if (!$this->linha('SELECT id FROM localizacoes WHERE id = ? FOR UPDATE', [$origemId])) {
                throw new AcervoException('Prateleira de origem não encontrada.');
            }
            $this->exemplares->localizacaoValida($destinoId);
            $ids = array_map('intval', array_column($this->todas("SELECT id FROM exemplares WHERE localizacao_id = ? AND situacao <> 'baixado' ORDER BY id", [$origemId]), 'id'));
            foreach ($ids as $ex) {
                $this->exemplares->transferir($ex, $destinoId, $responsavelId, $motivo !== '' ? $motivo : 'transferência em lote na Organização Física');
            }
            $this->registrarAcao($responsavelId, 'organizacao.transferir_exemplares', 'localizacao', $origemId, ['destino' => $destinoId, 'total' => count($ids)]);
            return count($ids);
        });
    }

    /**
     * "Mover exemplar": pelo código BDP, para outra prateleira. Registra no histórico do exemplar a
     * localização anterior, a nova, quem moveu e quando. Os dados da obra não mudam.
     * @return array{id: int, codigo: string, de: ?int, para: int, livro_id: int}
     */
    public function moverExemplar(string $codigo, int $destinoId, int $responsavelId, string $motivo = '', ?int $inventarioId = null): array
    {
        $normal = \Biblioteca\Support\CodigoBdp::normalizar(trim($codigo));
        if ($normal === null) {
            throw new AcervoException(\Biblioteca\Support\CodigoBdp::mensagemInvalido($codigo));
        }
        if ($destinoId <= 0) {
            throw new AcervoException('Escolha a estante e a prateleira de destino.');
        }
        return $this->transacao(function () use ($normal, $destinoId, $responsavelId, $motivo, $inventarioId) {
            $ex = $this->linha('SELECT id, livro_id, localizacao_id, situacao FROM exemplares WHERE codigo_individual = ? FOR UPDATE', [$normal]);
            if (!$ex) {
                throw new AcervoException('Nenhum exemplar tem o código ' . $normal . '. Confira o carimbo.');
            }
            $de = $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null;
            if ($de === $destinoId) {
                throw new AcervoException('O exemplar ' . $normal . ' já está nesta prateleira. Nada foi alterado.');
            }
            $this->exemplares->transferir((int)$ex['id'], $destinoId, $responsavelId, $motivo !== '' ? $motivo : 'movido na Organização Física', $inventarioId);
            return ['id' => (int)$ex['id'], 'codigo' => $normal, 'de' => $de, 'para' => $destinoId, 'livro_id' => (int)$ex['livro_id']];
        });
    }

    // ---------------------------------------------------------------

    /** Etiqueta "Estante A · Prateleira 2" (ou "Sala 1 · Estante A · Prateleira 2"). */
    public static function rotuloPrateleira(string $prateleira): string
    {
        return 'Prateleira ' . $prateleira;
    }

    /** 1 → A, 26 → Z, 27 → AA. */
    public static function letraDoNumero(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    public static function numeroDaLetra(string $letras): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letras)) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }
        return $n;
    }

    private function inserirEstante(array $d, int $responsavelId): int
    {
        if ($d['ordem'] === null) {
            $d['ordem'] = (int)$this->valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM estantes WHERE setor_chave = ?', [$d['setor_id'] ?? 0]);
        }
        try {
            $this->executar('INSERT INTO estantes (setor_id, identificacao, nome, descricao, categoria, ordem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$d['setor_id'], $d['identificacao'], $d['nome'], $d['descricao'], $d['categoria'], $d['ordem'], $responsavelId]);
        } catch (PDOException $e) {
            throw $this->duplicidade($e, 'Já existe uma estante com a identificação "' . $d['identificacao'] . '" ou o nome "' . $d['nome'] . '" neste setor.');
        }
        return (int)$this->pdo->lastInsertId();
    }

    /** Cria $qtd prateleiras numeradas, continuando a numeração da estante. @return list<int> */
    private function criarPrateleiras(int $estanteId, int $qtd): array
    {
        $ids = [];
        if ($qtd <= 0) {
            return $ids;
        }
        $usadas = array_map('mb_strtoupper', array_column($this->todas('SELECT prateleira FROM localizacoes WHERE estante_id = ?', [$estanteId]), 'prateleira'));
        $numero = 1;
        while (count($ids) < $qtd) {
            $ident = (string)$numero++;
            if (in_array($ident, $usadas, true)) {
                continue;
            }
            $ids[] = $this->inserirPrateleira($estanteId, $ident);
            $usadas[] = $ident;
        }
        return $ids;
    }

    private function inserirPrateleira(int $estanteId, string $ident): int
    {
        $e = $this->linha('SELECT e.identificacao, COALESCE(s.nome, \'\') AS setor FROM estantes e LEFT JOIN setores s ON s.id = e.setor_id WHERE e.id = ?', [$estanteId]);
        $ordem = (int)$this->valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM localizacoes WHERE estante_id = ?', [$estanteId]);
        $this->executar('INSERT INTO localizacoes (estante_id, setor, estante, prateleira, complemento, ordem) VALUES (?, ?, ?, ?, \'\', ?)',
            [$estanteId, $e['setor'], $e['identificacao'], $ident, $ordem]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Mantém o texto (setor, estante) das prateleiras igual à estrutura. */
    private function sincronizarTexto(int $estanteId): void
    {
        $this->executar('UPDATE localizacoes l JOIN estantes e ON e.id = l.estante_id LEFT JOIN setores s ON s.id = e.setor_id
                         SET l.setor = COALESCE(s.nome, \'\'), l.estante = e.identificacao WHERE l.estante_id = ?', [$estanteId]);
    }

    private function prateleiraBloqueada(int $id): array
    {
        $p = $this->linha('SELECT * FROM localizacoes WHERE id = ? FOR UPDATE', [$id]);
        if (!$p || $p['estante_id'] === null) {
            throw new AcervoException('Prateleira não encontrada.');
        }
        return $p;
    }

    /** Recusa a exclusão de uma localização com exemplares ou já usada em inventários. */
    private function exigirLivre(int $localId, string $oque): void
    {
        $n = (int)$this->valor('SELECT COUNT(*) FROM exemplares WHERE localizacao_id = ?', [$localId]);
        if ($n > 0) {
            $nome = (string)$this->valor('SELECT prateleira FROM localizacoes WHERE id = ?', [$localId]);
            throw new AcervoException('A prateleira ' . $nome . ' tem ' . $n . ' exemplar(es). Transfira os exemplares para outra prateleira antes de excluir ' . $oque . ', ou apenas desative. Nada foi excluído.');
        }
        $usada = (int)$this->valor('SELECT COUNT(*) FROM inventario_itens WHERE localizacao_esperada_id = ? OR localizacao_encontrada_id = ?', [$localId, $localId])
            + (int)$this->valor('SELECT COUNT(*) FROM inventario_estantes WHERE localizacao_id = ?', [$localId]);
        if ($usada > 0) {
            throw new AcervoException('Esta localização já aparece no histórico de inventários e não pode ser excluída. Desative-a: ela some das escolhas e o histórico fica preservado.');
        }
    }

    /** @return array{identificacao: string, nome: string, descricao: ?string, categoria: ?string, setor_id: ?int, ordem: ?int} */
    private function validarEstante(array $dados): array
    {
        $ident = $this->identificacao($dados['identificacao'] ?? '', 'da estante', true);
        $nome = $this->texto($dados['nome'] ?? '', 80);
        return [
            'identificacao' => $ident,
            'nome' => $nome !== '' ? $nome : 'Estante ' . $ident,
            'descricao' => $this->texto($dados['descricao'] ?? '', 255) ?: null,
            'categoria' => $this->texto($dados['categoria'] ?? '', 120) ?: null,
            'setor_id' => $this->setorValido($dados['setor_id'] ?? null),
            'ordem' => $this->ordem($dados['ordem'] ?? null),
        ];
    }

    private function setorValido(mixed $id): ?int
    {
        if ($id === null || $id === '' || (int)$id <= 0) {
            return null;
        }
        if (!$this->valor('SELECT 1 FROM setores WHERE id = ?', [(int)$id])) {
            throw new AcervoException('Setor não encontrado.');
        }
        return (int)$id;
    }

    private function identificacao(mixed $valor, string $deQue, bool $obrigatoria): ?string
    {
        $v = mb_strtoupper($this->texto($valor, 30));
        if ($v === '') {
            if ($obrigatoria) {
                throw new AcervoException('Informe a identificação curta ' . $deQue . ' (ex.: A, B ou E01).');
            }
            return null;
        }
        if (mb_strlen($v) > 20 || !preg_match('/^[A-Z0-9][A-Z0-9.\-]*$/', $v)) {
            throw new AcervoException('A identificação ' . $deQue . ' aceita até 20 letras sem acento, números, ponto e hífen (ex.: A, E01, B-2).');
        }
        return $v;
    }

    private function identificacaoPrateleira(mixed $valor, bool $obrigatoria): ?string
    {
        $v = mb_strtoupper($this->texto($valor, 30));
        if ($v === '') {
            if ($obrigatoria) {
                throw new AcervoException('Informe a identificação da prateleira (ex.: 1, 2 ou Superior).');
            }
            return null;
        }
        if (mb_strlen($v) > 20) {
            throw new AcervoException('Use até 20 caracteres na identificação da prateleira.');
        }
        return $v;
    }

    private function quantidade(mixed $valor, int $min, int $max, string $deQue): int
    {
        $n = filter_var($valor, FILTER_VALIDATE_INT);
        if ($n === false || $n < $min || $n > $max) {
            throw new AcervoException('Informe de ' . $min . ' a ' . $max . ' ' . $deQue . '.');
        }
        return $n;
    }

    private function ordem(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $n = filter_var($valor, FILTER_VALIDATE_INT);
        if ($n === false || $n < 0 || $n > 100000) {
            throw new AcervoException('A ordem de exibição deve ser um número inteiro (0 a 100000).');
        }
        return $n;
    }

    private function texto(mixed $valor, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$valor) ?? ''), 0, $max);
    }

    private function duplicidade(PDOException $e, string $mensagem): \Throwable
    {
        return $e->getCode() === '23000' && (int)($e->errorInfo[1] ?? 0) === 1062 ? new AcervoException($mensagem) : $e;
    }
}
