<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Cadastro e manutenção dos exemplares físicos (uma unidade real de um livro).
 *
 * Regras:
 *   * Um exemplar só nasce por uma ação explícita de quem está com o livro em mãos
 *     (cadastro manual ou conferência do inventário). Nada aqui olha a quantidade das planilhas.
 *   * O código BDP-000000 vem da sequência exemplar_codigo_seq, com a linha bloqueada
 *     (SELECT ... FOR UPDATE): dois cadastros ao mesmo tempo recebem códigos diferentes, e o
 *     índice único do código é a segunda barreira. A sequência nunca volta, então o código de
 *     um exemplar baixado não é reaproveitado (migração 070).
 *   * Cada alteração grava uma linha em movimentacoes (histórico do exemplar) e uma ação
 *     administrativa, na mesma transação.
 *   * Exemplar emprestado (ou com empréstimo aberto) não muda de situação, não é baixado e não
 *     é marcado como extraviado: a devolução é registrada no módulo de empréstimos.
 *   * A situação "emprestado" só é definida pelo módulo de empréstimos.
 *   * Baixa é definitiva; não há exclusão de exemplares.
 */
final class ExemplarService
{
    use ConsultasPdo;

    public const CONSERVACAO = ['bom', 'regular', 'danificado', 'necessita_reparo'];

    /** Situações que podem ser escolhidas no cadastro e na alteração manual. */
    public const SITUACOES_MANUAIS = ['disponivel', 'aguardando', 'manutencao', 'danificado'];

    public const LIMITE_POR_CADASTRO = 20;

    private const CODIGO_MAXIMO = 999999;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Cadastra $quantidade exemplares físicos do livro, todos com os mesmos dados.
     * Dados: estado_conservacao, situacao, localizacao_id (ou null), observacoes.
     * @return list<array{id: int, codigo: string}>
     */
    public function cadastrar(int $livroId, array $dados, int $responsavelId, int $quantidade = 1, ?int $inventarioId = null): array
    {
        if ($quantidade < 1 || $quantidade > self::LIMITE_POR_CADASTRO) {
            throw new AcervoException('Informe de 1 a ' . self::LIMITE_POR_CADASTRO . ' exemplares por cadastro.');
        }
        $estado = (string)($dados['estado_conservacao'] ?? '');
        $situacao = (string)($dados['situacao'] ?? '');
        if (!in_array($estado, self::CONSERVACAO, true)) {
            throw new AcervoException('Escolha o estado de conservação do exemplar.');
        }
        if (!in_array($situacao, self::SITUACOES_MANUAIS, true)) {
            throw new AcervoException('Escolha a situação inicial do exemplar (disponível, aguardando, em manutenção ou danificado).');
        }
        $obs = self::textoLimpo($dados['observacoes'] ?? null);
        $localId = $this->localizacaoValida($dados['localizacao_id'] ?? null);

        return $this->transacao(function () use ($livroId, $estado, $situacao, $obs, $localId, $responsavelId, $quantidade, $inventarioId) {
            $livro = $this->linha('SELECT id, titulo, arquivado_em FROM livros WHERE id = ? LOCK IN SHARE MODE', [$livroId]);
            if (!$livro) {
                throw new AcervoException('Livro não encontrado.');
            }
            if ($livro['arquivado_em'] !== null) {
                throw new AcervoException('Este livro está arquivado. Restaure o cadastro antes de registrar exemplares.');
            }
            // Bloqueia a sequência até o fim da transação: cadastros simultâneos esperam aqui.
            $ultimo = (int)$this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE');
            if ($ultimo + $quantidade > self::CODIGO_MAXIMO) {
                throw new AcervoException('A numeração BDP chegou ao limite de ' . self::CODIGO_MAXIMO . ' exemplares.');
            }
            $this->executar('UPDATE exemplar_codigo_seq SET ultimo = ? WHERE id = 1', [$ultimo + $quantidade]);

            $criados = [];
            for ($i = 1; $i <= $quantidade; $i++) {
                $codigo = sprintf('BDP-%06d', $ultimo + $i);
                $this->executar('INSERT INTO exemplares (livro_id, codigo_individual, localizacao_id, estado_conservacao, situacao, observacoes, cadastrado_por, inventario_id)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$livroId, $codigo, $localId, $estado, $situacao, $obs, $responsavelId, $inventarioId]);
                $id = (int)$this->pdo->lastInsertId();
                $this->movimentacao($id, 'cadastro', $inventarioId ? 'Exemplar cadastrado durante a conferência do inventário nº ' . $inventarioId : 'Exemplar físico cadastrado',
                    $responsavelId, $inventarioId, ['estado' => $estado, 'situacao' => $situacao, 'localizacao_id' => $localId]);
                $criados[] = ['id' => $id, 'codigo' => $codigo];
            }
            $this->registrarAcao($responsavelId, 'exemplar.cadastrar', 'livro', $livroId, [
                'titulo' => $livro['titulo'], 'codigos' => array_column($criados, 'codigo'), 'inventario_id' => $inventarioId,
            ]);
            return $criados;
        });
    }

    /** Altera o estado de conservação e as observações. */
    public function editar(int $id, array $dados, int $responsavelId): void
    {
        $estado = (string)($dados['estado_conservacao'] ?? '');
        if (!in_array($estado, self::CONSERVACAO, true)) {
            throw new AcervoException('Escolha o estado de conservação do exemplar.');
        }
        $obs = self::textoLimpo($dados['observacoes'] ?? null);
        $this->transacao(function () use ($id, $estado, $obs, $responsavelId) {
            $ex = $this->bloquear($id);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Exemplares baixados não podem ser alterados.');
            }
            if ($ex['estado_conservacao'] === $estado && (string)$ex['observacoes'] === (string)$obs) {
                return;
            }
            $this->executar('UPDATE exemplares SET estado_conservacao = ?, observacoes = ? WHERE id = ?', [$estado, $obs, $id]);
            if ($ex['estado_conservacao'] !== $estado) {
                $this->movimentacao($id, 'conservacao', 'Estado de conservação: ' . self::rotuloConservacao($ex['estado_conservacao']) . ' → ' . self::rotuloConservacao($estado),
                    $responsavelId, null, ['de' => $ex['estado_conservacao'], 'para' => $estado]);
            }
            $this->registrarAcao($responsavelId, 'exemplar.editar', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'estado' => $estado]);
        });
    }

    /** Só o estado de conservação (usado nas decisões do inventário). */
    public function alterarConservacao(int $id, string $estado, int $responsavelId, ?int $inventarioId = null): void
    {
        if (!in_array($estado, self::CONSERVACAO, true)) {
            throw new AcervoException('Estado de conservação inválido.');
        }
        $this->transacao(function () use ($id, $estado, $responsavelId, $inventarioId) {
            $ex = $this->bloquear($id);
            if ($ex['situacao'] === 'baixado' || $ex['estado_conservacao'] === $estado) {
                return;
            }
            $this->executar('UPDATE exemplares SET estado_conservacao = ? WHERE id = ?', [$estado, $id]);
            $this->movimentacao($id, 'conservacao', 'Estado de conservação: ' . self::rotuloConservacao($ex['estado_conservacao']) . ' → ' . self::rotuloConservacao($estado),
                $responsavelId, $inventarioId, ['de' => $ex['estado_conservacao'], 'para' => $estado]);
        });
    }

    /**
     * Transfere o exemplar para outra localização (ou deixa sem localização, com null).
     * Só este exemplar muda: os outros exemplares do livro continuam onde estão.
     */
    public function transferir(int $id, ?int $localizacaoId, int $responsavelId, string $motivo = '', ?int $inventarioId = null): void
    {
        $localId = $this->localizacaoValida($localizacaoId);
        $motivo = self::textoLimpo($motivo);
        $this->transacao(function () use ($id, $localId, $responsavelId, $motivo, $inventarioId) {
            $ex = $this->bloquear($id);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Exemplares baixados não mudam de localização.');
            }
            $anterior = $ex['localizacao_id'] !== null ? (int)$ex['localizacao_id'] : null;
            if ($anterior === $localId) {
                return;
            }
            $this->executar('UPDATE exemplares SET localizacao_id = ? WHERE id = ?', [$localId, $id]);
            $texto = 'Localização: ' . $this->localTexto($anterior) . ' → ' . $this->localTexto($localId) . ($motivo ? ' · ' . $motivo : '');
            $this->movimentacao($id, 'localizacao', $texto, $responsavelId, $inventarioId, ['de' => $anterior, 'para' => $localId]);
            $this->registrarAcao($responsavelId, 'exemplar.transferir', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'de' => $anterior, 'para' => $localId]);
        });
    }

    /**
     * Muda a situação para disponível, aguardando, em manutenção ou danificado.
     * Exemplar extraviado volta ao acervo por aqui (quando reencontrado); baixado, nunca.
     */
    public function alterarSituacao(int $id, string $nova, int $responsavelId, string $motivo = '', ?int $inventarioId = null): void
    {
        if (!in_array($nova, self::SITUACOES_MANUAIS, true)) {
            throw new AcervoException('Situação inválida. Empréstimos, baixas e extravios têm fluxos próprios.');
        }
        $motivo = self::textoLimpo($motivo);
        $this->transacao(function () use ($id, $nova, $responsavelId, $motivo, $inventarioId) {
            $ex = $this->bloquear($id);
            $this->recusarSeEmprestado($ex);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Exemplares baixados não voltam ao acervo.');
            }
            if ($ex['situacao'] === $nova) {
                return;
            }
            $this->executar('UPDATE exemplares SET situacao = ? WHERE id = ?', [$nova, $id]);
            $tipo = $nova === 'manutencao' ? 'manutencao' : 'situacao';
            $texto = 'Situação: ' . self::rotuloSituacao($ex['situacao']) . ' → ' . self::rotuloSituacao($nova) . ($motivo ? ' · ' . $motivo : '');
            $this->movimentacao($id, $tipo, $texto, $responsavelId, $inventarioId, ['de' => $ex['situacao'], 'para' => $nova]);
            $this->registrarAcao($responsavelId, 'exemplar.situacao', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'de' => $ex['situacao'], 'para' => $nova]);
        });
    }

    /** Registra o extravio (exemplar não encontrado). Pode ser revertido com alterarSituacao(). */
    public function registrarExtravio(int $id, int $responsavelId, string $motivo, ?int $inventarioId = null): void
    {
        $motivo = self::textoLimpo($motivo);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo do extravio.');
        }
        $this->transacao(function () use ($id, $responsavelId, $motivo, $inventarioId) {
            $ex = $this->bloquear($id);
            $this->recusarSeEmprestado($ex);
            if (in_array($ex['situacao'], ['baixado', 'extraviado'], true)) {
                throw new AcervoException('Este exemplar já está ' . ($ex['situacao'] === 'baixado' ? 'baixado.' : 'registrado como extraviado.'));
            }
            $this->executar("UPDATE exemplares SET situacao = 'extraviado' WHERE id = ?", [$id]);
            $this->movimentacao($id, 'extravio', 'Registrado como extraviado · ' . $motivo, $responsavelId, $inventarioId, ['de' => $ex['situacao']]);
            $this->registrarAcao($responsavelId, 'exemplar.extravio', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'inventario_id' => $inventarioId]);
        });
    }

    /** Baixa definitiva: o exemplar sai do acervo, mas o código e o histórico ficam. */
    public function baixar(int $id, int $responsavelId, string $motivo): void
    {
        $motivo = self::textoLimpo($motivo, 255);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo da baixa.');
        }
        $this->transacao(function () use ($id, $responsavelId, $motivo) {
            $ex = $this->bloquear($id);
            $this->recusarSeEmprestado($ex);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Este exemplar já foi baixado.');
            }
            $this->executar("UPDATE exemplares SET situacao = 'baixado', baixado_em = NOW(), motivo_baixa = ? WHERE id = ?", [$motivo, $id]);
            $this->movimentacao($id, 'baixa', 'Baixa do acervo · ' . $motivo, $responsavelId, null, ['de' => $ex['situacao']]);
            $this->registrarAcao($responsavelId, 'exemplar.baixar', 'exemplar', $id, ['codigo' => $ex['codigo_individual'], 'motivo' => $motivo]);
        });
    }

    /** Marca a data em que as etiquetas destes exemplares foram geradas. */
    public function marcarEtiquetas(array $ids, int $responsavelId, string $modelo): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            return;
        }
        $this->transacao(function () use ($ids, $responsavelId, $modelo) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $this->executar("UPDATE exemplares SET etiqueta_gerada_em = NOW() WHERE id IN ($marcas)", $ids);
            $codigos = $this->todas("SELECT codigo_individual FROM exemplares WHERE id IN ($marcas) ORDER BY codigo_individual", $ids);
            $this->registrarAcao($responsavelId, 'etiqueta.gerar', 'exemplar', count($ids) === 1 ? $ids[0] : null, [
                'modelo' => $modelo, 'total' => count($ids), 'codigos' => array_slice(array_column($codigos, 'codigo_individual'), 0, 200),
            ]);
        });
    }

    // ---------------------------------------------------------------

    /** Exemplar com a linha bloqueada até o fim da transação. */
    public function bloquear(int $id): array
    {
        $ex = $this->linha('SELECT e.*, (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL LIMIT 1) AS emprestimo_aberto
                            FROM exemplares e WHERE e.id = ? FOR UPDATE', [$id]);
        if (!$ex) {
            throw new AcervoException('Exemplar não encontrado.');
        }
        return $ex;
    }

    private function recusarSeEmprestado(array $ex): void
    {
        if ($ex['situacao'] === 'emprestado' || $ex['emprestimo_aberto'] !== null) {
            throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' possui empréstimo ativo. Registre a devolução no módulo de empréstimos primeiro.');
        }
    }

    /** Confere se a localização existe e está ativa. Vazio = sem localização. */
    public function localizacaoValida(mixed $id): ?int
    {
        if ($id === null || $id === '' || (int)$id === 0) {
            return null;
        }
        $local = $this->linha('SELECT id, ativa FROM localizacoes WHERE id = ?', [(int)$id]);
        if (!$local) {
            throw new AcervoException('Localização não encontrada.');
        }
        if (!(int)$local['ativa']) {
            throw new AcervoException('Esta localização está desativada. Escolha outra ou reative-a em Localizações.');
        }
        return (int)$local['id'];
    }

    public function movimentacao(int $exemplarId, string $tipo, string $descricao, ?int $responsavelId, ?int $inventarioId = null, array $detalhes = []): void
    {
        $this->executar('INSERT INTO movimentacoes (exemplar_id, inventario_id, tipo, descricao, detalhes, responsavel_id) VALUES (?, ?, ?, ?, ?, ?)', [
            $exemplarId, $inventarioId, $tipo, mb_substr($descricao, 0, 1000), $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null, $responsavelId,
        ]);
    }

    private function localTexto(?int $id): string
    {
        if ($id === null) {
            return 'sem localização';
        }
        $l = $this->linha('SELECT setor, estante, prateleira, complemento FROM localizacoes WHERE id = ?', [$id]);
        return $l ? self::localizacaoTexto($l) : 'localização nº ' . $id;
    }

    /** "Literatura · Estante E03 · Prateleira P02 · Complemento". */
    public static function localizacaoTexto(?array $l): string
    {
        if (!$l || ($l['setor'] ?? null) === null) {
            return 'Sem localização';
        }
        $partes = [$l['setor']];
        if (($l['estante'] ?? '') !== '') {
            $partes[] = 'Estante ' . $l['estante'];
        }
        if (($l['prateleira'] ?? '') !== '') {
            $partes[] = 'Prateleira ' . $l['prateleira'];
        }
        if (($l['complemento'] ?? '') !== '') {
            $partes[] = $l['complemento'];
        }
        return implode(' · ', $partes);
    }

    public static function rotuloSituacao(string $s): string
    {
        return [
            'disponivel' => 'Disponível', 'emprestado' => 'Emprestado', 'manutencao' => 'Em manutenção', 'extraviado' => 'Extraviado',
            'baixado' => 'Baixado', 'aguardando' => 'Aguardando liberação', 'danificado' => 'Danificado',
        ][$s] ?? $s;
    }

    public static function rotuloConservacao(string $e): string
    {
        return ['bom' => 'Bom', 'regular' => 'Regular', 'danificado' => 'Danificado', 'necessita_reparo' => 'Necessita reparo'][$e] ?? $e;
    }
}
