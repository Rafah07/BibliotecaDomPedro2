<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Ocorrências e Conservação (migração 150): problemas físicos encontrados em um EXEMPLAR
 * (nunca na obra bibliográfica), com a decisão "Deixar exemplar indisponível para empréstimo?".
 *
 * Regras de disponibilidade (a situação do exemplar é a mesma usada no catálogo e nos empréstimos):
 *   * Bloquear: exemplar Disponível ou Aguardando liberação passa a "Danificado" (fora de circulação).
 *     Se estiver emprestado, nada muda agora: a devolução deixa o exemplar "Danificado"
 *     (EmprestimoService). Em manutenção, extraviado ou baixado continua como está.
 *   * Não bloquear: a ocorrência fica registrada e o exemplar continua emprestável.
 *   * Resolver: a ocorrência é fechada (data, responsável e o que foi feito). Com "Liberar para
 *     empréstimo", o exemplar só volta a Disponível se não houver OUTRO motivo para o bloqueio:
 *     outra ocorrência aberta que bloqueia, empréstimo aberto ou preparo pendente (carimbo/estante).
 *   * Nada é apagado: cancelar (desfazer) muda o status para "cancelada".
 */
final class OcorrenciaService
{
    use ConsultasPdo;

    public const TIPOS = [
        'capa_danificada' => 'Capa danificada', 'paginas_rasgadas' => 'Páginas rasgadas', 'paginas_faltando' => 'Páginas faltando',
        'encadernacao_danificada' => 'Encadernação danificada', 'molhado' => 'Livro molhado', 'restauracao' => 'Necessita de restauração', 'outro' => 'Outra observação',
    ];

    public const STATUS = ['aberta' => 'Aberta', 'resolvida' => 'Resolvida', 'cancelada' => 'Cancelada'];

    public function __construct(private PDO $pdo, private ExemplarService $exemplares)
    {
    }

    /**
     * Registra a ocorrência. Dados: tipo, descricao, bloquear ('1' = deixar indisponível).
     * @return array{id: int, codigo: string, bloqueou: bool, situacao: string}
     */
    public function registrar(int $exemplarId, array $dados, int $responsavelId, ?int $inventarioId = null): array
    {
        $tipo = (string)($dados['tipo'] ?? '');
        if (!isset(self::TIPOS[$tipo])) {
            throw new AcervoException('Escolha o tipo da ocorrência (capa danificada, páginas rasgadas...).');
        }
        $desc = self::textoLimpo($dados['descricao'] ?? null, 1000);
        if ($tipo === 'outro' && $desc === null) {
            throw new AcervoException('Descreva a ocorrência.');
        }
        $bloquearValor = (string)($dados['bloquear'] ?? '');
        if (!in_array($bloquearValor, ['0', '1'], true)) {
            throw new AcervoException('Responda: deixar o exemplar indisponível para empréstimo? (Sim ou Não)');
        }
        $bloquear = $bloquearValor === '1';
        return $this->transacao(function () use ($exemplarId, $tipo, $desc, $bloquear, $responsavelId, $inventarioId) {
            $ex = $this->exemplares->bloquear($exemplarId);
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('Exemplares baixados não recebem ocorrências.');
            }
            $situacaoAnterior = null;
            $novaSituacao = $ex['situacao'];
            if ($bloquear && in_array($ex['situacao'], ['disponivel', 'aguardando'], true) && $ex['emprestimo_aberto'] === null) {
                $situacaoAnterior = $ex['situacao'];
                $novaSituacao = 'danificado';
            }
            $this->executar('INSERT INTO exemplar_ocorrencias (exemplar_id, tipo, descricao, bloqueia_emprestimo, situacao_anterior, inventario_id, operacao_id, registrada_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$exemplarId, $tipo, $desc, $bloquear ? 1 : 0, $situacaoAnterior, $inventarioId, $this->exemplares->operacaoAtual(), $responsavelId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->exemplares->movimentacao($exemplarId, 'ocorrencia', 'Ocorrência nº ' . $id . ': ' . self::TIPOS[$tipo] . ($desc ? ' · ' . $desc : '')
                . ($bloquear ? ' · indisponível para empréstimo' : ' · empréstimo permitido'), $responsavelId, $inventarioId,
                ['ocorrencia_id' => $id, 'acao' => 'registrada', 'bloqueia' => $bloquear]);
            if ($novaSituacao !== $ex['situacao']) {
                $this->executar('UPDATE exemplares SET situacao = ? WHERE id = ?', [$novaSituacao, $exemplarId]);
                $this->exemplares->movimentacao($exemplarId, 'situacao', 'Situação: ' . ExemplarService::rotuloSituacao($ex['situacao']) . ' → ' . ExemplarService::rotuloSituacao($novaSituacao)
                    . ' · ocorrência nº ' . $id, $responsavelId, $inventarioId, ['de' => $ex['situacao'], 'para' => $novaSituacao, 'ocorrencia_id' => $id, 'pendente_antes' => (int)$ex['liberacao_pendente']]);
            }
            $this->registrarAcao($responsavelId, 'exemplar.ocorrencia', 'exemplar', $exemplarId, ['codigo' => $ex['codigo_individual'], 'tipo' => $tipo, 'bloqueia' => $bloquear]);
            return ['id' => $id, 'codigo' => $ex['codigo_individual'], 'bloqueou' => $novaSituacao !== $ex['situacao'], 'situacao' => $novaSituacao,
                'emprestado' => $ex['emprestimo_aberto'] !== null];
        });
    }

    /** Edita as observações (e a decisão de bloqueio vale só para ocorrências abertas). */
    public function editarDescricao(int $id, string $descricao, int $responsavelId): void
    {
        $desc = self::textoLimpo($descricao, 1000);
        $this->transacao(function () use ($id, $desc, $responsavelId) {
            $oc = $this->linha('SELECT * FROM exemplar_ocorrencias WHERE id = ? FOR UPDATE', [$id]);
            if (!$oc) {
                throw new AcervoException('Ocorrência não encontrada.');
            }
            if ($oc['tipo'] === 'outro' && $desc === null) {
                throw new AcervoException('Descreva a ocorrência.');
            }
            $this->executar('UPDATE exemplar_ocorrencias SET descricao = ? WHERE id = ?', [$desc, $id]);
            $this->exemplares->movimentacao((int)$oc['exemplar_id'], 'ocorrencia', 'Ocorrência nº ' . $id . ': observações editadas', $responsavelId, null,
                ['ocorrencia_id' => $id, 'acao' => 'observacao']);
        });
    }

    /**
     * Resolve a ocorrência. $liberar = "Liberar para empréstimo" (só se não houver outro bloqueio).
     * @return array{liberado: bool, motivo: ?string}
     */
    public function resolver(int $id, string $resolucao, bool $liberar, int $responsavelId): array
    {
        $texto = self::textoLimpo($resolucao, 1000);
        if ($texto === null) {
            throw new AcervoException('Conte o que foi feito (ex.: "capa colada", "páginas recolocadas", "sem conserto: encaminhado para baixa").');
        }
        return $this->transacao(function () use ($id, $texto, $liberar, $responsavelId) {
            $oc = $this->linha('SELECT * FROM exemplar_ocorrencias WHERE id = ? FOR UPDATE', [$id]);
            if (!$oc) {
                throw new AcervoException('Ocorrência não encontrada.');
            }
            if ($oc['status'] !== 'aberta') {
                throw new AcervoException('Esta ocorrência já foi ' . ($oc['status'] === 'resolvida' ? 'resolvida' : 'cancelada') . '. Nada foi alterado.');
            }
            $exId = (int)$oc['exemplar_id'];
            $ex = $this->exemplares->bloquear($exId);
            $this->executar("UPDATE exemplar_ocorrencias SET status = 'resolvida', resolvida_em = NOW(), resolvida_por = ?, resolucao = ? WHERE id = ?", [$responsavelId, $texto, $id]);
            $this->exemplares->movimentacao($exId, 'ocorrencia', 'Ocorrência nº ' . $id . ' resolvida · ' . $texto, $responsavelId, null, ['ocorrencia_id' => $id, 'acao' => 'resolvida']);
            $motivo = null;
            $liberado = false;
            if ($liberar) {
                $motivo = $this->motivoBloqueio($ex, $id);
                if ($motivo === null && $ex['situacao'] === 'danificado') {
                    $nova = (int)$ex['liberacao_pendente'] && !$this->exemplares->preparoCompleto($ex) ? 'aguardando' : 'disponivel';
                    $this->executar('UPDATE exemplares SET situacao = ?, liberacao_pendente = ? WHERE id = ?', [$nova, $nova === 'aguardando' ? 1 : 0, $exId]);
                    $this->exemplares->movimentacao($exId, 'situacao', 'Situação: Danificado → ' . ExemplarService::rotuloSituacao($nova) . ' · ocorrência nº ' . $id . ' resolvida',
                        $responsavelId, null, ['de' => 'danificado', 'para' => $nova, 'ocorrencia_id' => $id, 'pendente_antes' => (int)$ex['liberacao_pendente']]);
                    $liberado = $nova === 'disponivel';
                    if (!$liberado) {
                        $motivo = 'O exemplar ainda aguarda o preparo (carimbo ou estante) e fica "Aguardando liberação".';
                    }
                } elseif ($motivo === null && $ex['situacao'] !== 'disponivel') {
                    $motivo = 'O exemplar está "' . ExemplarService::rotuloSituacao($ex['situacao']) . '". Ajuste a situação no cadastro do exemplar, se for o caso.';
                }
            }
            $this->registrarAcao($responsavelId, 'exemplar.ocorrencia_resolvida', 'exemplar', $exId, ['codigo' => $ex['codigo_individual'], 'ocorrencia' => $id, 'liberado' => $liberado]);
            return ['liberado' => $liberado, 'motivo' => $motivo];
        });
    }

    /** Outro motivo que impede liberar o exemplar (ou null). Exemplar já bloqueado na transação. */
    public function motivoBloqueio(array $ex, ?int $ignorarOcorrencia = null): ?string
    {
        if ($ex['emprestimo_aberto'] !== null || $ex['situacao'] === 'emprestado') {
            return 'O exemplar está emprestado: a disponibilidade é decidida na devolução.';
        }
        $outras = (int)$this->valor("SELECT COUNT(*) FROM exemplar_ocorrencias WHERE exemplar_id = ? AND status = 'aberta' AND bloqueia_emprestimo = 1 AND id <> ?", [$ex['id'], (int)$ignorarOcorrencia]);
        if ($outras > 0) {
            return 'Há outra ocorrência aberta que deixa o exemplar indisponível. Resolva-a também para liberar.';
        }
        if (in_array($ex['situacao'], ['baixado', 'extraviado', 'manutencao'], true)) {
            return 'O exemplar está "' . ExemplarService::rotuloSituacao($ex['situacao']) . '" e continua assim.';
        }
        return null;
    }

    /** Há ocorrência aberta que bloqueia o empréstimo deste exemplar? (usado na devolução) */
    public static function bloqueiaEmprestimo(PDO $pdo, int $exemplarId): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM exemplar_ocorrencias WHERE exemplar_id = ? AND status = 'aberta' AND bloqueia_emprestimo = 1 LIMIT 1");
        $stmt->execute([$exemplarId]);
        return (bool)$stmt->fetchColumn();
    }
}
