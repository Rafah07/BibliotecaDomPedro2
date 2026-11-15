<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use PDO;

/**
 * Operações numeradas e "Desfazer ações recentes" (migração 150).
 *
 * realizar(): abre uma operação (linha em "operacoes"), roda a ação dentro de UMA transação e marca
 * cada movimentação do histórico com o número da operação. Se algo falhar, nada é gravado — nem a
 * operação, nem metade das mudanças. A "chave" (token do formulário) impede gravar a mesma operação
 * duas vezes (clique duplo, reenvio da página).
 *
 * desfazer(): reverte, em ordem inversa, as mudanças que a operação fez nos exemplares — somente
 * quando é seguro: o exemplar continua exatamente como a operação deixou (nenhuma mudança posterior
 * do mesmo tipo), não tem empréstimo aberto e a localização anterior ainda está ativa. A reversão é
 * uma NOVA operação ("desfazer"), com histórico próprio; a original fica marcada como desfeita e
 * nada é apagado. Cadastro de exemplares e códigos BDP nunca são desfeitos automaticamente (o código
 * não é reaproveitado): nesses casos a tela mostra a correção manual orientada.
 */
final class OperacaoService
{
    use ConsultasPdo;

    public const TIPOS = [
        'mover' => 'Exemplar movido',
        'movimentacao_lote' => 'Movimentação em lote',
        'transferencia_prateleira' => 'Transferência de prateleira inteira',
        'localizacao' => 'Localização definida',
        'carimbo' => 'Carimbo confirmado',
        'ocorrencia' => 'Ocorrência registrada',
        'ocorrencia_resolvida' => 'Ocorrência resolvida',
        'conferencia' => 'Conferência do inventário',
        'registro' => 'Exemplar registrado',
        'situacao' => 'Situação alterada',
        'desfazer' => 'Desfazer',
        'desfazer_correcao' => 'Correção bibliográfica desfeita',
    ];

    /** Campos da obra que o "Desfazer" devolve sozinho (os mesmos de "Verificar e corrigir dados"). */
    public const CAMPOS_LIVRO = ['titulo' => 'Título', 'subtitulo' => 'Subtítulo', 'isbn' => 'ISBN', 'isbn_original' => 'ISBN original', 'editora' => 'Editora',
        'ano_publicacao' => 'Ano', 'edicao' => 'Edição', 'idioma' => 'Idioma', 'assunto' => 'Assunto'];

    public const LIMITE_DIAS = 30;

    public function __construct(private PDO $pdo, private ExemplarService $exemplares)
    {
    }

    /** Token de formulário (32 caracteres hexadecimais) ou null. */
    public static function chaveValida(mixed $chave): ?string
    {
        $chave = strtolower(trim((string)$chave));
        return preg_match('/^[a-f0-9]{32}$/', $chave) ? $chave : null;
    }

    public static function novaChave(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Roda $fn(int $operacaoId) como uma operação numerada.
     * @return array{id: int, total: int, resultado: mixed}
     */
    public function realizar(string $tipo, string $descricao, int $administradorId, callable $fn, ?string $chave = null, array $detalhes = []): array
    {
        $chave = self::chaveValida($chave);
        return $this->transacao(function () use ($tipo, $descricao, $administradorId, $fn, $chave, $detalhes) {
            if ($chave !== null && ($ja = $this->linha('SELECT id FROM operacoes WHERE chave = ?', [$chave]))) {
                throw new AcervoException('Esta operação já foi registrada (nº ' . (int)$ja['id'] . '). Nada foi gravado de novo.');
            }
            try {
                $this->executar('INSERT INTO operacoes (tipo, descricao, chave, administrador_id, detalhes) VALUES (?, ?, ?, ?, ?)',
                    [$tipo, mb_substr($descricao, 0, 500), $chave, $administradorId, $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new AcervoException('Esta operação já foi registrada. Nada foi gravado de novo.');
                }
                throw $e;
            }
            $id = (int)$this->pdo->lastInsertId();
            $anterior = $this->exemplares->operacaoAtual();
            $this->exemplares->definirOperacao($id);
            try {
                $resultado = $fn($id);
            } finally {
                $this->exemplares->definirOperacao($anterior);
            }
            $total = (int)$this->valor('SELECT COUNT(DISTINCT exemplar_id) FROM movimentacoes WHERE operacao_id = ?', [$id]);
            $this->executar('UPDATE operacoes SET total = ? WHERE id = ?', [$total, $id]);
            return ['id' => $id, 'total' => $total, 'resultado' => $resultado];
        });
    }

    public function operacao(int $id): ?array
    {
        return $this->linha('SELECT o.*, a.nome AS administrador_nome, d.nome AS desfeita_por_nome
                             FROM operacoes o LEFT JOIN administradores a ON a.id = o.administrador_id LEFT JOIN administradores d ON d.id = o.desfeita_por WHERE o.id = ?', [$id]);
    }

    /**
     * Ações recentes: operações com exemplares alterados e correções bibliográficas, das mais novas
     * para as mais antigas. $administradorId limita às ações de uma pessoa.
     * @return list<array<string, mixed>>
     */
    public function recentes(?int $administradorId = null, int $limite = 60, int $dias = self::LIMITE_DIAS): array
    {
        $limite = max(1, min(200, $limite));
        $desde = date('Y-m-d H:i:s', strtotime('-' . max(1, $dias) . ' days'));
        $ops = $this->todas('SELECT o.id, o.tipo, o.descricao, o.total, o.criado_em, o.desfeita_em, o.desfaz_operacao_id, o.desfeita_por_operacao_id, o.administrador_id,
                                    a.nome AS administrador_nome, d.nome AS desfeita_por_nome
                             FROM operacoes o LEFT JOIN administradores a ON a.id = o.administrador_id LEFT JOIN administradores d ON d.id = o.desfeita_por
                             WHERE o.criado_em >= ? AND o.tipo <> \'desfazer_correcao\' AND (o.total > 0 OR o.tipo = \'desfazer\')' . ($administradorId ? ' AND o.administrador_id = ?' : '') . '
                             ORDER BY o.id DESC LIMIT ' . $limite, array_merge([$desde], $administradorId ? [$administradorId] : []));
        foreach ($ops as &$o) {
            $o['origem'] = 'operacao';
        }
        unset($o);
        $correcoes = $this->todas("SELECT v.id, v.livro_id, v.acao, v.alteracoes, v.origem AS origem_correcao, v.criado_em, v.administrador_id, a.nome AS administrador_nome, l.titulo,
                                          (SELECT o.id FROM operacoes o WHERE o.chave = MD5(CONCAT('livro_verificacao:', v.id))) AS desfeita_operacao_id
                                   FROM livro_verificacoes v JOIN livros l ON l.id = v.livro_id LEFT JOIN administradores a ON a.id = v.administrador_id
                                   WHERE v.criado_em >= ? AND v.alteracoes IS NOT NULL AND v.origem <> 'desfazer'" . ($administradorId ? ' AND v.administrador_id = ?' : '') . '
                                   ORDER BY v.id DESC LIMIT ' . $limite, array_merge([$desde], $administradorId ? [$administradorId] : []));
        foreach ($correcoes as $c) {
            $campos = array_keys(json_decode((string)$c['alteracoes'], true) ?: []);
            $ops[] = ['origem' => 'correcao', 'id' => (int)$c['id'], 'tipo' => 'correcao_livro', 'livro_id' => (int)$c['livro_id'],
                'descricao' => 'Dados da obra "' . ($c['titulo'] ?? 'Sem título') . '" alterados: ' . implode(', ', array_map(fn ($k) => self::CAMPOS_LIVRO[$k] ?? $k, $campos)),
                'total' => 1, 'criado_em' => $c['criado_em'], 'desfeita_em' => $c['desfeita_operacao_id'] ? 'sim' : null, 'administrador_id' => $c['administrador_id'],
                'administrador_nome' => $c['administrador_nome'], 'desfaz_operacao_id' => null, 'desfeita_por_operacao_id' => $c['desfeita_operacao_id']];
        }
        usort($ops, fn ($a, $b) => strcmp((string)$b['criado_em'], (string)$a['criado_em']) ?: ($b['id'] <=> $a['id']));
        return array_slice($ops, 0, $limite);
    }

    /** Movimentações (histórico dos exemplares) gravadas por uma operação. */
    public function itens(int $operacaoId, int $limite = 500): array
    {
        return $this->todas('SELECT m.id, m.exemplar_id, m.tipo, m.descricao, m.detalhes, m.criado_em, e.codigo_individual, l.titulo
                             FROM movimentacoes m JOIN exemplares e ON e.id = m.exemplar_id JOIN livros l ON l.id = e.livro_id
                             WHERE m.operacao_id = ? ORDER BY m.id LIMIT ' . max(1, min(5000, $limite)), [$operacaoId]);
    }

    /**
     * Pode desfazer? Confere cada mudança da operação.
     * @return array{pode: bool, motivos: list<string>, orientacao: string, passos: list<array>}
     */
    public function analisar(int $operacaoId): array
    {
        $op = $this->operacao($operacaoId);
        if (!$op) {
            throw new AcervoException('Operação não encontrada.');
        }
        $nao = fn (string $motivo, string $orientacao = '') => ['pode' => false, 'motivos' => [$motivo], 'orientacao' => $orientacao, 'passos' => []];
        if ($op['desfeita_em'] !== null) {
            return $nao('Esta operação já foi desfeita (operação nº ' . (int)$op['desfeita_por_operacao_id'] . ').');
        }
        if ($op['tipo'] === 'desfazer') {
            return $nao('Uma operação de desfazer não é desfeita.', 'Para refazer a mudança, repita a ação original (ex.: mova de novo ou marque de novo como carimbado).');
        }
        if (strtotime((string)$op['criado_em']) < strtotime('-' . self::LIMITE_DIAS . ' days')) {
            return $nao('Só ações dos últimos ' . self::LIMITE_DIAS . ' dias podem ser desfeitas automaticamente.', 'Corrija manualmente no cadastro do exemplar (o histórico mostra o que mudou).');
        }
        $movs = $this->todas('SELECT m.*, e.codigo_individual, e.localizacao_id AS atual_local, e.carimbo_status AS atual_carimbo, e.situacao AS atual_situacao,
                                     e.estado_conservacao AS atual_estado, e.liberacao_pendente AS atual_pendente,
                                     (SELECT COUNT(*) FROM emprestimos x WHERE x.exemplar_id = e.id AND x.data_devolucao IS NULL) AS emprestimo_aberto
                              FROM movimentacoes m JOIN exemplares e ON e.id = m.exemplar_id WHERE m.operacao_id = ? ORDER BY m.id DESC', [$operacaoId]);
        if (!$movs) {
            return $nao('Esta operação não alterou nenhum exemplar.');
        }
        $motivos = [];
        $passos = [];
        $orientacao = '';
        foreach ($movs as $m) {
            $d = json_decode((string)$m['detalhes'], true) ?: [];
            $cod = $m['codigo_individual'];
            $depois = fn (array $tipos) => (int)$this->valor('SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ? AND id > ? AND tipo IN (' . implode(',', array_fill(0, count($tipos), '?')) . ')',
                array_merge([$m['exemplar_id'], $m['id']], $tipos)) > 0;
            switch ($m['tipo']) {
                case 'localizacao':
                    $de = isset($d['de']) && $d['de'] !== null ? (int)$d['de'] : null;
                    $para = isset($d['para']) && $d['para'] !== null ? (int)$d['para'] : null;
                    if ($m['atual_situacao'] === 'baixado') {
                        $motivos[] = $cod . ': o exemplar foi baixado depois.';
                    } elseif ($depois(['localizacao']) || ($m['atual_local'] !== null ? (int)$m['atual_local'] : null) !== $para) {
                        $motivos[] = $cod . ': foi movido de novo depois desta operação.';
                    } elseif ($de !== null && !$this->localAtivo($de)) {
                        $motivos[] = $cod . ': a localização anterior foi desativada.';
                        $orientacao = 'Reative a prateleira em Organização Física ou mova o exemplar para outra prateleira com "Mover exemplar".';
                    } else {
                        $passos[] = ['tipo' => 'localizacao', 'exemplar_id' => (int)$m['exemplar_id'], 'codigo' => $cod, 'para' => $de];
                    }
                    break;
                case 'carimbo':
                    if ($depois(['carimbo']) || $m['atual_carimbo'] !== ($d['para'] ?? '')) {
                        $motivos[] = $cod . ': a situação do carimbo mudou depois.';
                    } elseif (($d['para'] ?? '') === 'carimbado') {
                        $passos[] = ['tipo' => 'carimbo', 'exemplar_id' => (int)$m['exemplar_id'], 'codigo' => $cod];
                    } else {
                        $motivos[] = $cod . ': esta mudança de carimbo não é desfeita automaticamente.';
                    }
                    break;
                case 'situacao':
                case 'manutencao':
                    if ((int)$m['emprestimo_aberto'] > 0) {
                        $motivos[] = $cod . ': tem empréstimo aberto.';
                        $orientacao = 'Registre a devolução primeiro; depois ajuste a situação no cadastro do exemplar.';
                    } elseif ($depois(['situacao', 'manutencao', 'emprestimo', 'devolucao', 'extravio', 'baixa']) || $m['atual_situacao'] !== ($d['para'] ?? '')) {
                        $motivos[] = $cod . ': a situação mudou depois (empréstimo, devolução ou outra alteração).';
                    } else {
                        $passos[] = ['tipo' => 'situacao', 'exemplar_id' => (int)$m['exemplar_id'], 'codigo' => $cod, 'para' => (string)($d['de'] ?? ''),
                            'liberacao' => !empty($d['liberacao']), 'pendente' => isset($d['pendente_antes']) ? (bool)$d['pendente_antes'] : null];
                    }
                    break;
                case 'conservacao':
                    if ($depois(['conservacao']) || $m['atual_estado'] !== ($d['para'] ?? '')) {
                        $motivos[] = $cod . ': a conservação foi avaliada de novo depois.';
                    } elseif (($d['de'] ?? '') !== ($d['para'] ?? '')) {
                        $passos[] = ['tipo' => 'conservacao', 'exemplar_id' => (int)$m['exemplar_id'], 'codigo' => $cod, 'para' => (string)$d['de']];
                    }
                    break;
                case 'ocorrencia':
                    $oc = isset($d['ocorrencia_id']) ? $this->linha('SELECT id, status FROM exemplar_ocorrencias WHERE id = ?', [(int)$d['ocorrencia_id']]) : null;
                    $acao = $d['acao'] ?? '';
                    if (!$oc) {
                        $motivos[] = $cod . ': ocorrência não encontrada.';
                    } elseif ($depois(['ocorrencia'])) {
                        $motivos[] = $cod . ': a ocorrência foi alterada depois.';
                    } elseif ($acao === 'registrada' && $oc['status'] === 'aberta') {
                        $passos[] = ['tipo' => 'ocorrencia_cancelar', 'exemplar_id' => (int)$m['exemplar_id'], 'codigo' => $cod, 'ocorrencia_id' => (int)$oc['id']];
                    } elseif ($acao === 'resolvida' && $oc['status'] === 'resolvida') {
                        $passos[] = ['tipo' => 'ocorrencia_reabrir', 'exemplar_id' => (int)$m['exemplar_id'], 'codigo' => $cod, 'ocorrencia_id' => (int)$oc['id']];
                    } elseif ($acao !== 'observacao') {
                        $motivos[] = $cod . ': a ocorrência não está mais como a operação deixou.';
                    }
                    break;
                case 'cadastro':
                    $motivos[] = $cod . ': o registro de um exemplar não é desfeito automaticamente (o código BDP fica reservado e não é reaproveitado).';
                    $orientacao = 'Exemplar registrado na obra errada? Se o livro ainda não foi carimbado, abra o exemplar e registre a baixa com o motivo "registrado na obra errada"; depois registre o exemplar na obra correta (ele recebe outro código). Se já foi carimbado, corrija o carimbo com o novo código. O histórico guarda tudo.';
                    break;
                case 'inventario':
                    // O resultado no inventário tem fluxo próprio ("Voltar para pendente").
                    $motivos[] = $cod . ': o resultado no inventário é corrigido com "Voltar para pendente" na conferência.';
                    $orientacao = 'Abra o inventário, procure a obra e use "Voltar para pendente" no item conferido por engano.';
                    break;
                default:
                    $motivos[] = $cod . ': mudança do tipo "' . $m['tipo'] . '" não é desfeita automaticamente.';
            }
        }
        return ['pode' => !$motivos && $passos, 'motivos' => array_values(array_unique($motivos)), 'orientacao' => $orientacao, 'passos' => $passos];
    }

    /**
     * Desfaz a operação inteira (tudo ou nada). Devolve o número da nova operação.
     */
    public function desfazer(int $operacaoId, int $administradorId, string $motivo = '', ?string $chave = null): int
    {
        $motivo = self::textoLimpo($motivo, 200) ?? '';
        return $this->realizar('desfazer', 'Desfeita a operação nº ' . $operacaoId . ($motivo !== '' ? ' · ' . $motivo : ''), $administradorId, function (int $novaId) use ($operacaoId, $administradorId, $motivo) {
            $op = $this->linha('SELECT * FROM operacoes WHERE id = ? FOR UPDATE', [$operacaoId]);
            if (!$op || $op['desfeita_em'] !== null) {
                throw new AcervoException('Esta operação já foi desfeita por outra pessoa. Nada foi alterado.');
            }
            // Os exemplares envolvidos ficam bloqueados antes da análise: ninguém muda nada no meio.
            foreach ($this->todas('SELECT DISTINCT exemplar_id FROM movimentacoes WHERE operacao_id = ? ORDER BY exemplar_id', [$operacaoId]) as $x) {
                $this->exemplares->bloquear((int)$x['exemplar_id']);
            }
            $a = $this->analisar($operacaoId);
            if (!$a['pode']) {
                throw new AcervoException('Não é seguro desfazer esta operação: ' . implode(' ', array_slice($a['motivos'], 0, 5)) . (count($a['motivos']) > 5 ? ' (e outros ' . (count($a['motivos']) - 5) . ')' : ''));
            }
            $texto = 'desfazendo a operação nº ' . $operacaoId . ($motivo !== '' ? ' (' . $motivo . ')' : '');
            foreach ($a['passos'] as $p) {
                $this->aplicarPasso($p, $administradorId, $texto);
            }
            $mudou = $this->executar('UPDATE operacoes SET desfeita_em = NOW(), desfeita_por = ?, desfeita_por_operacao_id = ? WHERE id = ? AND desfeita_em IS NULL', [$administradorId, $novaId, $operacaoId]);
            if ($mudou !== 1) {
                throw new AcervoException('Esta operação já foi desfeita por outra pessoa. Nada foi alterado.');
            }
            $this->executar('UPDATE operacoes SET desfaz_operacao_id = ? WHERE id = ?', [$operacaoId, $novaId]);
            $this->registrarAcao($administradorId, 'operacao.desfazer', 'operacao', $operacaoId, ['nova_operacao' => $novaId, 'passos' => count($a['passos']), 'motivo' => $motivo]);
        }, $chave)['id'];
    }

    /**
     * Desfaz uma correção bibliográfica ("Verificar e corrigir dados"): devolve os campos simples da
     * obra ao valor anterior, se ninguém os mudou de novo. Autores e categoria: correção manual.
     * @return list<string> campos devolvidos
     */
    public function desfazerCorrecaoLivro(int $verificacaoId, int $administradorId, ObraVerificacaoService $verificacao): array
    {
        $v = $this->linha('SELECT v.*, l.titulo FROM livro_verificacoes v JOIN livros l ON l.id = v.livro_id WHERE v.id = ?', [$verificacaoId]);
        if (!$v || $v['alteracoes'] === null) {
            throw new AcervoException('Correção não encontrada.');
        }
        $campos = json_decode((string)$v['alteracoes'], true) ?: [];
        $simples = array_intersect_key($campos, self::CAMPOS_LIVRO);
        if (!$simples) {
            throw new AcervoException('Esta correção mudou só autores ou categoria. Corrija manualmente em "Editar livro" (o histórico da obra mostra o valor anterior).');
        }
        $chave = md5('livro_verificacao:' . $verificacaoId);
        return $this->realizar('desfazer_correcao', 'Correção da obra "' . ($v['titulo'] ?? '') . '" desfeita', $administradorId, function () use ($v, $simples, $administradorId, $verificacao, $verificacaoId) {
            $livro = $this->linha('SELECT * FROM livros WHERE id = ? FOR UPDATE', [$v['livro_id']]);
            if (!$livro || $livro['arquivado_em'] !== null) {
                throw new AcervoException('Obra não encontrada ou arquivada.');
            }
            $mudados = [];
            foreach ($simples as $campo => $p) {
                if ((string)($livro[$campo] ?? '') !== (string)($p['depois'] ?? '')) {
                    throw new AcervoException('O campo "' . self::CAMPOS_LIVRO[$campo] . '" foi alterado de novo depois desta correção. Não é seguro desfazer: corrija manualmente em "Editar livro".');
                }
                $mudados[$campo] = [$livro[$campo], $p['antes'] ?? null];
            }
            $this->executar('UPDATE livros SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($mudados))) . ', atualizado_em = CURRENT_TIMESTAMP WHERE id = ?',
                [...array_map(fn ($p) => $p[1], array_values($mudados)), $v['livro_id']]);
            $verificacao->registrar((int)$v['livro_id'], $mudados, $administradorId, false, 'desfazer');
            $this->registrarAcao($administradorId, 'livro.desfazer_correcao', 'livro', (int)$v['livro_id'], ['campos' => array_keys($mudados), 'verificacao_id' => $verificacaoId]);
            return array_map(fn ($c) => self::CAMPOS_LIVRO[$c], array_keys($mudados));
        }, $chave, ['verificacao_id' => $verificacaoId])['resultado'];
    }

    // ---------------------------------------------------------------

    private function aplicarPasso(array $p, int $administradorId, string $texto): void
    {
        $id = $p['exemplar_id'];
        switch ($p['tipo']) {
            case 'localizacao':
                $this->exemplares->transferir($id, $p['para'], $administradorId, $texto);
                break;
            case 'carimbo':
                $this->exemplares->voltarAguardandoCarimbo($id, $administradorId, $texto);
                break;
            case 'conservacao':
                $this->exemplares->alterarConservacao($id, $p['para'], $administradorId);
                break;
            case 'situacao':
                $ex = $this->exemplares->bloquear($id);
                if ($ex['emprestimo_aberto'] !== null || $ex['situacao'] === 'emprestado' || $ex['situacao'] === 'baixado') {
                    throw new AcervoException($ex['codigo_individual'] . ': a situação não pode ser revertida agora.');
                }
                $pendente = $p['liberacao'] ? ($p['para'] === 'aguardando' ? 1 : 0) : ($p['pendente'] === null ? (int)$ex['liberacao_pendente'] : (int)$p['pendente']);
                $this->executar('UPDATE exemplares SET situacao = ?, liberacao_pendente = ? WHERE id = ?', [$p['para'], $pendente, $id]);
                $this->exemplares->movimentacao($id, 'situacao', 'Situação: ' . ExemplarService::rotuloSituacao($ex['situacao']) . ' → ' . ExemplarService::rotuloSituacao($p['para']) . ' · ' . $texto,
                    $administradorId, null, ['de' => $ex['situacao'], 'para' => $p['para'], 'liberacao' => $p['liberacao'], 'pendente_antes' => (int)$ex['liberacao_pendente']]);
                break;
            case 'ocorrencia_cancelar':
                $this->executar("UPDATE exemplar_ocorrencias SET status = 'cancelada', resolvida_em = NOW(), resolvida_por = ?, resolucao = ? WHERE id = ? AND status = 'aberta'",
                    [$administradorId, 'Cancelada: ' . $texto, $p['ocorrencia_id']]);
                $this->exemplares->movimentacao($id, 'ocorrencia', 'Ocorrência nº ' . $p['ocorrencia_id'] . ' cancelada · ' . $texto, $administradorId, null,
                    ['ocorrencia_id' => $p['ocorrencia_id'], 'acao' => 'cancelada']);
                break;
            case 'ocorrencia_reabrir':
                $this->executar("UPDATE exemplar_ocorrencias SET status = 'aberta', resolvida_em = NULL, resolvida_por = NULL, resolucao = NULL WHERE id = ? AND status = 'resolvida'", [$p['ocorrencia_id']]);
                $this->exemplares->movimentacao($id, 'ocorrencia', 'Ocorrência nº ' . $p['ocorrencia_id'] . ' reaberta · ' . $texto, $administradorId, null,
                    ['ocorrencia_id' => $p['ocorrencia_id'], 'acao' => 'reaberta']);
                break;
        }
    }

    private function localAtivo(int $id): bool
    {
        try {
            return $this->exemplares->localizacaoValida($id) !== null;
        } catch (AcervoException) {
            return false;
        }
    }
}
