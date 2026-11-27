<?php

namespace Biblioteca\Reorganizacao;

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\LoteService;
use PDO;

/**
 * Núcleo do módulo temporário de reorganização do acervo: período, sessões temporárias dos
 * participantes, lotes, atribuições, solicitações, pendências, notificações e contadores.
 * O levantamento/revisão (Grupos 1 e 2) fica em Levantamento; carimbação e conferência (Grupos 3 e 4)
 * em Etapas.
 *
 * Integração com o sistema permanente (sem duplicar nada):
 *   * cada lote da reorganização abre um lote GERENCIADO permanente (LoteService::criar), com a
 *     capacidade igual à quantidade prevista. Os exemplares e os códigos BDP são criados nele pelo
 *     cadastro normal (ExemplarService::cadastrarEmQuantidade, sequência única de códigos), e a
 *     Planilha BDP do sistema mostra esse lote como qualquer outro;
 *   * exemplar de lote gerenciado só fica disponível para empréstimo depois de carimbado e guardado
 *     na estante (regra permanente do sistema): livros ainda em reorganização não aparecem como
 *     disponíveis, sem depender deste módulo.
 *
 * Participantes não têm conta: o que fazem nos exemplares permanentes fica em nome do administrador
 * responsável pelo lote (as tabelas permanentes exigem uma conta) e, no histórico deste módulo, com o
 * nome informado e o identificador da sessão.
 */
final class Reorganizacao
{
    use Apoio;

    private ?Levantamento $levantamento = null;
    private ?Etapas $etapas = null;
    private LoteService $lotesPermanentes;

    public function __construct(private PDO $pdo, private array $config, private ExemplarService $exemplares)
    {
        $this->lotesPermanentes = new LoteService($pdo, $exemplares);
    }

    public function levantamento(): Levantamento
    {
        return $this->levantamento ??= new Levantamento($this->pdo, $this->config, $this->exemplares, $this);
    }

    public function etapas(): Etapas
    {
        return $this->etapas ??= new Etapas($this->pdo, $this->config, $this->exemplares, $this);
    }

    public function config(string $chave): mixed
    {
        return $this->config[$chave] ?? null;
    }

    public function exemplaresServico(): ExemplarService
    {
        return $this->exemplares;
    }

    public function lotesPermanentes(): LoteService
    {
        return $this->lotesPermanentes;
    }

    // =================================================================
    // Período
    // =================================================================

    public function periodoAtivo(): ?array
    {
        return $this->linha('SELECT * FROM reorg_periodos WHERE ativo = 1 ORDER BY id DESC LIMIT 1');
    }

    /** Período ativo ou, se nenhum, o último (para consulta). */
    public function periodoAtual(): ?array
    {
        return $this->linha('SELECT * FROM reorg_periodos ORDER BY ativo DESC, id DESC LIMIT 1');
    }

    public function periodos(): array
    {
        return $this->todas('SELECT p.*, a.nome AS criado_por_nome FROM reorg_periodos p JOIN administradores a ON a.id = p.criado_por ORDER BY p.id DESC');
    }

    /** @return array{0: array, 1: array<string, string>} dados limpos e erros */
    public static function validarPeriodo(array $p): array
    {
        $t = fn (string $c, int $max) => ($v = trim((string)($p[$c] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $d = [
            'nome' => $t('nome', 120),
            'inicio' => $t('inicio', 10),
            'previsao_fim' => $t('previsao_fim', 10),
            'instrucoes_gerais' => $t('instrucoes_gerais', 5000),
            'instrucoes_grupo1' => $t('instrucoes_grupo1', 5000),
            'instrucoes_grupo2' => $t('instrucoes_grupo2', 5000),
            'instrucoes_grupo3' => $t('instrucoes_grupo3', 5000),
            'instrucoes_grupo4' => $t('instrucoes_grupo4', 5000),
        ];
        $e = [];
        if ($d['nome'] === null) {
            $e['nome'] = 'Informe o nome do período (ex.: Reorganização 2026).';
        }
        foreach (['inicio', 'previsao_fim'] as $c) {
            if ($d[$c] !== null && !(($dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d[$c])) && $dt->format('Y-m-d') === $d[$c])) {
                $e[$c] = 'Data inválida.';
            }
        }
        $d['inicio'] ??= date('Y-m-d');
        if (!isset($e['previsao_fim']) && $d['previsao_fim'] !== null && $d['previsao_fim'] < $d['inicio']) {
            $e['previsao_fim'] = 'A previsão de encerramento não pode ser antes do início.';
        }
        return [$d, $e];
    }

    public function iniciarPeriodo(array $dados, int $adminId): int
    {
        [$d, $e] = self::validarPeriodo($dados);
        if ($e) {
            throw new AcervoException(implode(' ', $e));
        }
        return $this->transacao(function () use ($d, $adminId) {
            // Um período ativo por vez (a trava evita dois cliques simultâneos).
            $this->todas('SELECT id FROM reorg_periodos WHERE ativo = 1 FOR UPDATE');
            if ($this->valor('SELECT 1 FROM reorg_periodos WHERE ativo = 1')) {
                throw new AcervoException('Já existe um período de reorganização ativo. Encerre-o antes de iniciar outro.');
            }
            $this->executar('INSERT INTO reorg_periodos (nome, inicio, previsao_fim, instrucoes_gerais, instrucoes_grupo1, instrucoes_grupo2, instrucoes_grupo3, instrucoes_grupo4, criado_por)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...array_values($d), $adminId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->executar('INSERT INTO reorg_historico (periodo_id, administrador_id, acao, descricao) VALUES (?, ?, ?, ?)', [$id, $adminId, 'periodo_iniciado', 'Período “' . $d['nome'] . '” iniciado']);
            $this->registrarAcao($adminId, 'reorganizacao.periodo_iniciar', 'reorg_periodo', $id, ['nome' => $d['nome']]);
            return $id;
        });
    }

    public function atualizarPeriodo(int $periodoId, array $dados, int $adminId): void
    {
        [$d, $e] = self::validarPeriodo($dados);
        if ($e) {
            throw new AcervoException(implode(' ', $e));
        }
        $this->transacao(function () use ($periodoId, $d, $adminId) {
            $p = $this->linha('SELECT * FROM reorg_periodos WHERE id = ? FOR UPDATE', [$periodoId]);
            if (!$p) {
                throw new AcervoException('Período não encontrado.');
            }
            $this->executar('UPDATE reorg_periodos SET nome = ?, inicio = ?, previsao_fim = ?, instrucoes_gerais = ?, instrucoes_grupo1 = ?, instrucoes_grupo2 = ?, instrucoes_grupo3 = ?, instrucoes_grupo4 = ? WHERE id = ?',
                [...array_values($d), $periodoId]);
            $this->executar('INSERT INTO reorg_historico (periodo_id, administrador_id, acao, descricao) VALUES (?, ?, ?, ?)', [$periodoId, $adminId, 'periodo_alterado', 'Configurações do período alteradas']);
        });
    }

    /** O que ainda falta antes de encerrar o período (aviso, não bloqueio). @return list<string> */
    public function avisosEncerramento(int $periodoId): array
    {
        $a = [];
        $lotes = (int)$this->valor("SELECT COUNT(*) FROM reorg_lotes WHERE periodo_id = ? AND status <> 'concluido'", [$periodoId]);
        if ($lotes) {
            $a[] = $lotes . ' lote(s) ainda não concluído(s).';
        }
        $rasc = (int)$this->valor("SELECT COUNT(*) FROM reorg_registros r JOIN reorg_lotes l ON l.id = r.lote_id WHERE l.periodo_id = ? AND r.status IN ('rascunho', 'correcao')", [$periodoId]);
        if ($rasc) {
            $a[] = $rasc . ' registro(s) do Grupo 1 em rascunho ou aguardando correção.';
        }
        $rev = (int)$this->valor("SELECT COUNT(*) FROM reorg_registros r JOIN reorg_lotes l ON l.id = r.lote_id WHERE l.periodo_id = ? AND r.status IN ('aguardando_revisao', 'aprovado')", [$periodoId]);
        if ($rev) {
            $a[] = $rev . ' registro(s) aguardando revisão ou cadastramento.';
        }
        $pend = (int)$this->valor("SELECT COUNT(*) FROM reorg_pendencias p JOIN reorg_lotes l ON l.id = p.lote_id WHERE l.periodo_id = ? AND p.status <> 'resolvida'", [$periodoId]);
        if ($pend) {
            $a[] = $pend . ' pendência(s) não resolvida(s).';
        }
        $sol = (int)$this->valor("SELECT COUNT(*) FROM reorg_solicitacoes WHERE periodo_id = ? AND status = 'pendente'", [$periodoId]);
        if ($sol) {
            $a[] = $sol . ' solicitação(ões) pendente(s).';
        }
        return $a;
    }

    /**
     * Encerra o período: o botão "Equipe de organização" some, novas sessões não são criadas e as
     * sessões abertas são encerradas. Lotes, registros e histórico ficam guardados. Com avisos
     * (lotes incompletos, rascunhos, pendências), só encerra com $confirmado.
     * @return list<string> avisos (vazio = encerrado)
     */
    public function encerrarPeriodo(int $periodoId, int $adminId, bool $confirmado = false): array
    {
        $avisos = $this->avisosEncerramento($periodoId);
        if ($avisos && !$confirmado) {
            return $avisos;
        }
        $this->transacao(function () use ($periodoId, $adminId) {
            $p = $this->linha('SELECT * FROM reorg_periodos WHERE id = ? FOR UPDATE', [$periodoId]);
            if (!$p || !(int)$p['ativo']) {
                throw new AcervoException('Este período já está encerrado.');
            }
            foreach ($this->todas("SELECT * FROM reorg_sessoes WHERE periodo_id = ? AND status = 'ativa'", [$periodoId]) as $s) {
                $this->notificarSessao((int)$s['id'], 'periodo_encerrado', 'O período de reorganização foi encerrado. Obrigado pela participação!');
                $this->encerrarSessaoInterna($s, 'periodo_encerrado', $adminId);
            }
            $this->executar('UPDATE reorg_periodos SET ativo = 0, encerrado_em = NOW(), encerrado_por = ? WHERE id = ?', [$adminId, $periodoId]);
            $this->executar('INSERT INTO reorg_historico (periodo_id, administrador_id, acao, descricao) VALUES (?, ?, ?, ?)', [$periodoId, $adminId, 'periodo_encerrado', 'Período “' . $p['nome'] . '” encerrado']);
            $this->registrarAcao($adminId, 'reorganizacao.periodo_encerrar', 'reorg_periodo', $periodoId, []);
        });
        return [];
    }

    // =================================================================
    // Sessões temporárias
    // =================================================================

    /**
     * Entrada pela página "Equipe de organização": só o nome. Cria SEMPRE uma sessão nova, com um
     * identificador aleatório (o navegador guarda o identificador; o banco, só o hash). Informar o
     * mesmo nome de outra pessoa não dá acesso a nada dela.
     * @return array{token: string, sessao: array}
     */
    public function entrar(string $nome, string $ip = ''): array
    {
        $nome = trim(preg_replace('/\s+/u', ' ', strip_tags($nome)) ?? '');
        if (mb_strlen($nome) < 3 || !preg_match('/\pL/u', $nome)) {
            throw new AcervoException('Digite o seu nome completo.');
        }
        $nome = mb_substr($nome, 0, 100);
        $this->varrer();
        return $this->transacao(function () use ($nome, $ip) {
            $periodo = $this->linha('SELECT * FROM reorg_periodos WHERE ativo = 1 ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE');
            if (!$periodo) {
                throw new AcervoException('Não há período de reorganização ativo no momento.');
            }
            $ip = mb_substr($ip, 0, 45);
            if ($ip !== '' && (int)$this->valor('SELECT COUNT(*) FROM reorg_sessoes WHERE ip = ? AND iniciada_em > NOW() - INTERVAL 1 MINUTE', [$ip]) >= (int)$this->config['limite_entradas_minuto']) {
                throw new AcervoException('Muitas entradas seguidas a partir desta rede. Aguarde um minuto e tente novamente.');
            }
            $token = bin2hex(random_bytes(32));
            $this->executar('INSERT INTO reorg_sessoes (periodo_id, token_hash, nome, nome_chave, ip) VALUES (?, ?, ?, ?, ?)',
                [(int)$periodo['id'], hash('sha256', $token), $nome, self::chaveNome($nome), $ip ?: null]);
            $sessao = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ?', [(int)$this->pdo->lastInsertId()]);
            $this->historico('sessao_iniciada', $nome . ' entrou (' . self::rotuloSessao($sessao['id']) . ')', null, $sessao);
            $repetido = (int)$this->valor("SELECT COUNT(*) FROM reorg_sessoes WHERE nome_chave = ? AND status = 'ativa' AND id <> ?", [$sessao['nome_chave'], $sessao['id']]);
            $this->notificarAdmins('participante_conectado', $nome . ' (' . self::rotuloSessao($sessao['id']) . ') entrou na Central de Equipes'
                . ($repetido ? ' — atenção: já há ' . $repetido . ' sessão(ões) ativa(s) com o mesmo nome' : '') . '.', null, null, null);
            return ['token' => $token, 'sessao' => $sessao];
        });
    }

    /**
     * Sessão pelo identificador do navegador. Null se não existe, foi encerrada, o período acabou ou
     * passou da tolerância sem comunicação (nesse caso é encerrada agora).
     */
    public function sessaoPorToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $s = $this->linha("SELECT s.* FROM reorg_sessoes s JOIN reorg_periodos p ON p.id = s.periodo_id AND p.ativo = 1 WHERE s.token_hash = ? AND s.status = 'ativa'", [hash('sha256', $token)]);
        if (!$s) {
            return null;
        }
        if (strtotime($s['ultimo_sinal_em']) < time() - (int)$this->config['tolerancia_desconexao']) {
            $this->varrer();
            return null;
        }
        return $s;
    }

    public function sessao(int $id): ?array
    {
        return $this->linha('SELECT * FROM reorg_sessoes WHERE id = ?', [$id]);
    }

    public function escolherEquipe(array $sessao, string $equipe): void
    {
        if (!in_array($equipe, self::EQUIPES_PARTICIPANTES, true)) {
            throw new AcervoException('Escolha o Grupo 1, 3 ou 4.');
        }
        $this->transacao(function () use ($sessao, $equipe) {
            $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ? FOR UPDATE', [(int)$sessao['id']]);
            $this->exigirSessaoAtiva($s);
            if ($s['equipe'] !== null) {
                throw new AcervoException('Você já escolheu a sua equipe. Para mudar, use “Solicitar alteração de equipe”.');
            }
            $this->executar('UPDATE reorg_sessoes SET equipe = ? WHERE id = ?', [$equipe, $s['id']]);
            $this->historico('equipe_escolhida', $s['nome'] . ' escolheu ' . self::EQUIPES[$equipe], null, $s);
        });
    }

    /**
     * Comunicação periódica (a cada intervalo_sinal segundos). $trabalhando = a pessoa está numa tela
     * de trabalho. Renova as reservas de edição dela. Devolve o estado para o painel do participante.
     */
    public function sinal(array $sessao, bool $trabalhando = false, ?int $registroEditando = null): array
    {
        $this->varrer();
        $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ?', [(int)$sessao['id']]);
        if (!$s || $s['status'] !== 'ativa') {
            throw new SessaoEncerradaException(self::motivoTexto($s['motivo_encerramento'] ?? null));
        }
        $situacao = $s['situacao'] === 'pausada' ? 'pausada' : ($trabalhando ? 'trabalhando' : 'disponivel');
        $this->executar('UPDATE reorg_sessoes SET ultimo_sinal_em = NOW(), situacao = ? WHERE id = ?', [$situacao, $s['id']]);
        $r = (int)$this->config['reserva_edicao'];
        if ($registroEditando) {
            $this->executar('UPDATE reorg_registros SET editando_ate = NOW() + INTERVAL ? SECOND WHERE id = ? AND editando_sessao_id = ?', [$r, $registroEditando, $s['id']]);
        }
        $this->executar('UPDATE reorg_registro_exemplares SET reservado_ate = NOW() + INTERVAL ? SECOND WHERE reservado_sessao_id = ? AND reservado_ate > NOW()', [$r, $s['id']]);
        return $this->estadoParticipante($s);
    }

    /** Lotes, notificações e situação do participante (sem histórico pessoal permanente). */
    public function estadoParticipante(array $s): array
    {
        $lotes = $this->lotesDaSessao((int)$s['id']);
        $notifs = $this->todas('SELECT id, tipo, texto, criado_em FROM reorg_notificacoes WHERE sessao_id = ? AND lida_em IS NULL ORDER BY id DESC LIMIT 20', [$s['id']]);
        return [
            'equipe' => $s['equipe'],
            'situacao' => $s['situacao'],
            'lotes' => $lotes,
            'assinatura' => md5(json_encode([$s['equipe'], array_map(fn ($l) => [$l['id'], $l['equipe'], $l['status'], $l['etapas_pausadas']], $lotes)])),
            'notificacoes' => $notifs,
            'solicitacoes_pendentes' => (int)$this->valor("SELECT COUNT(*) FROM reorg_solicitacoes WHERE sessao_id = ? AND status = 'pendente'", [$s['id']]),
        ];
    }

    public function marcarNotificacoesLidas(array $sessao, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids) {
            $this->executar('UPDATE reorg_notificacoes SET lida_em = NOW() WHERE sessao_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [(int)$sessao['id'], ...$ids]);
        }
    }

    /** Lotes com atribuição ativa da sessão (somente estes podem ser vistos e trabalhados). */
    public function lotesDaSessao(int $sessaoId): array
    {
        return $this->todas('SELECT l.id, l.nome, l.status, l.etapas_pausadas, l.instrucoes, l.quantidade_prevista, a.equipe, a.atribuida_em
                             FROM reorg_atribuicoes a JOIN reorg_lotes l ON l.id = a.lote_id
                             WHERE a.sessao_id = ? AND a.encerrada_em IS NULL ORDER BY a.atribuida_em', [$sessaoId]);
    }

    public function pausarParticipante(array $sessao): string
    {
        return $this->transacao(function () use ($sessao) {
            $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ? FOR UPDATE', [(int)$sessao['id']]);
            $this->exigirSessaoAtiva($s);
            $this->liberarReservas((int)$s['id']);
            $this->executar("UPDATE reorg_sessoes SET situacao = 'pausada', ultimo_sinal_em = NOW() WHERE id = ?", [$s['id']]);
            $resumo = [];
            foreach ($this->lotesDaSessao((int)$s['id']) as $l) {
                $c = $this->contadores((int)$l['id']);
                $resumo[] = $l['nome'] . ': ' . $c['registrados'] . '/' . $c['prevista'] . ' registrados, ' . $c['carimbados'] . ' carimbados, ' . $c['conferidos'] . ' conferidos';
                $this->historico('atividade_pausada', $s['nome'] . ' pausou (' . self::EQUIPES[$l['equipe']] . ')', (int)$l['id'], $s, null, ['progresso' => $c]);
            }
            return $resumo ? implode(' · ', $resumo) : 'Sem lotes atribuídos.';
        });
    }

    public function retomarParticipante(array $sessao): void
    {
        $this->exigirSessaoAtiva($sessao);
        $this->executar("UPDATE reorg_sessoes SET situacao = 'disponivel', ultimo_sinal_em = NOW() WHERE id = ? AND status = 'ativa'", [(int)$sessao['id']]);
        $this->historico('atividade_retomada', $sessao['nome'] . ' retomou a atividade', null, $sessao);
    }

    /** Botão "Sair": encerra a sessão na hora (a página confere antes se há algo não sincronizado). */
    public function sair(array $sessao): void
    {
        $this->transacao(function () use ($sessao) {
            $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ? FOR UPDATE', [(int)$sessao['id']]);
            if ($s && $s['status'] === 'ativa') {
                $this->encerrarSessaoInterna($s, 'saida', null);
            }
        });
    }

    public function encerrarForcado(int $sessaoId, int $adminId): void
    {
        $this->transacao(function () use ($sessaoId, $adminId) {
            $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ? FOR UPDATE', [$sessaoId]);
            if (!$s || $s['status'] !== 'ativa') {
                throw new AcervoException('Esta sessão já estava encerrada.');
            }
            $this->notificarSessao($sessaoId, 'sessao_encerrada', 'Sua sessão foi encerrada pelo administrador.');
            $this->encerrarSessaoInterna($s, 'forcado', $adminId);
        });
    }

    /**
     * Encerra (dentro de uma transação): atribuições deixam de valer, reservas são liberadas,
     * o que foi preenchido continua salvo. Avisa o responsável quando um lote fica sem ninguém numa etapa.
     */
    private function encerrarSessaoInterna(array $s, string $motivo, ?int $adminId): void
    {
        $lotes = $this->lotesDaSessao((int)$s['id']);
        $this->executar("UPDATE reorg_sessoes SET status = 'encerrada', encerrada_em = NOW(), motivo_encerramento = ?, encerrada_por = ? WHERE id = ?", [$motivo, $adminId, $s['id']]);
        $this->executar("UPDATE reorg_atribuicoes SET encerrada_em = NOW(), encerrada_por = ?, motivo_fim = 'sessao_encerrada' WHERE sessao_id = ? AND encerrada_em IS NULL", [$adminId, $s['id']]);
        $this->liberarReservas((int)$s['id']);
        $this->executar("UPDATE reorg_solicitacoes SET status = 'resolvida', resposta = COALESCE(resposta, 'Sessão encerrada antes da resposta.') WHERE sessao_id = ? AND status = 'pendente' AND tipo IN ('novo_lote', 'troca_equipe')", [$s['id']]);
        $this->historico($motivo === 'inatividade' ? 'sessao_desconectada' : 'sessao_encerrada', $s['nome'] . ' (' . self::rotuloSessao($s['id']) . '): ' . self::motivoTexto($motivo), null, $s, $adminId);
        if ($motivo === 'inatividade') {
            $this->notificarAdmins('participante_desconectado', $s['nome'] . ' (' . self::rotuloSessao($s['id']) . ') foi desconectado por falta de comunicação. O que foi preenchido continua salvo.', null, null, 'desconectado:' . $s['id']);
        }
        foreach ($lotes as $l) {
            $this->avisarSemParticipantes((int)$l['id'], $l['equipe']);
        }
    }

    private function liberarReservas(int $sessaoId): void
    {
        $this->executar('UPDATE reorg_registros SET editando_sessao_id = NULL, editando_ate = NULL WHERE editando_sessao_id = ?', [$sessaoId]);
        $this->executar('UPDATE reorg_registro_exemplares SET reservado_sessao_id = NULL, reservado_ate = NULL WHERE reservado_sessao_id = ?', [$sessaoId]);
    }

    /** Lote ficou sem participante ativo numa etapa que ainda tem trabalho: avisa o responsável. */
    private function avisarSemParticipantes(int $loteId, string $equipe): void
    {
        $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$loteId]);
        if (!$lote || $lote['status'] === 'concluido') {
            return;
        }
        if ($this->valor('SELECT 1 FROM reorg_atribuicoes WHERE lote_id = ? AND equipe = ? AND encerrada_em IS NULL LIMIT 1', [$loteId, $equipe])) {
            return;
        }
        $c = $this->contadores($loteId);
        $trabalho = match ($equipe) {
            'grupo1' => $c['aguardando_levantamento'] + $c['em_levantamento'],
            'grupo3' => $c['aguardando_carimbacao'],
            'grupo4' => $c['aguardando_conferencia'] + $c['conferidos'] - $c['organizados'],
            default => 0,
        };
        if ($trabalho > 0) {
            $this->notificarAdmins('lote_sem_participantes', self::nomeLote($lote) . ' ficou sem participantes ativos no ' . self::EQUIPES[$equipe] . ' (' . $trabalho . ' exemplar(es) aguardando).',
                $loteId, (int)$lote['responsavel_id'], 'sem_participantes:' . $loteId . ':' . $equipe);
        }
    }

    /** Encerra as sessões sem comunicação além da tolerância (chamado a cada requisição; sem tarefa agendada). */
    public function varrer(): int
    {
        $limite = (int)$this->config['tolerancia_desconexao'];
        $vencidas = $this->todas("SELECT id FROM reorg_sessoes WHERE status = 'ativa' AND ultimo_sinal_em < NOW() - INTERVAL ? SECOND", [$limite]);
        $n = 0;
        foreach ($vencidas as $v) {
            $n += $this->transacao(function () use ($v, $limite) {
                $s = $this->linha("SELECT * FROM reorg_sessoes WHERE id = ? AND status = 'ativa' AND ultimo_sinal_em < NOW() - INTERVAL ? SECOND FOR UPDATE", [$v['id'], $limite]);
                if (!$s) {
                    return 0;
                }
                $this->encerrarSessaoInterna($s, 'inatividade', null);
                return 1;
            });
        }
        // Encerrado o período (ou a sessão de um período antigo): encerra também.
        foreach ($this->todas("SELECT s.* FROM reorg_sessoes s JOIN reorg_periodos p ON p.id = s.periodo_id WHERE s.status = 'ativa' AND p.ativo = 0") as $s) {
            $this->transacao(fn () => $this->encerrarSessaoInterna($s, 'periodo_encerrado', null));
        }
        return $n;
    }

    public static function motivoTexto(?string $motivo): string
    {
        return match ($motivo) {
            'saida' => 'saiu da Central de Equipes',
            'forcado' => 'sessão encerrada pelo administrador',
            'inatividade' => 'desconectado (sem comunicação dentro do tempo de tolerância)',
            'periodo_encerrado' => 'o período de reorganização foi encerrado',
            default => 'sessão encerrada',
        };
    }

    /**
     * Estado de conexão para a central administrativa:
     * conectado | trabalhando | disponivel | pausada | interrompida (sem sinal recente, dentro da
     * tolerância) | desconectado (encerrada por inatividade) | encerrada.
     */
    public function estadoConexao(array $s): string
    {
        if ($s['status'] !== 'ativa') {
            return $s['motivo_encerramento'] === 'inatividade' ? 'desconectado' : 'encerrada';
        }
        $atraso = time() - strtotime($s['ultimo_sinal_em']);
        if ($atraso > 2 * (int)$this->config['intervalo_sinal'] + 5) {
            return 'interrompida';
        }
        if ($s['situacao'] === 'pausada') {
            return 'pausada';
        }
        if ($s['situacao'] === 'trabalhando') {
            return 'trabalhando';
        }
        return $s['equipe'] === null ? 'conectado' : 'disponivel';
    }

    public const ESTADOS_CONEXAO = [
        'conectado' => ['Conectado (escolhendo equipe)', 'blue'],
        'trabalhando' => ['Trabalhando', 'green'],
        'disponivel' => ['Disponível', 'indigo'],
        'pausada' => ['Atividade pausada', 'amber'],
        'interrompida' => ['Conexão interrompida', 'red'],
        'desconectado' => ['Desconectado', 'muted'],
        'encerrada' => ['Sessão encerrada', 'muted'],
    ];

    /** Participantes com sessão ativa, com os dados da central administrativa. */
    public function participantesAtivos(string $equipe = '', string $estado = ''): array
    {
        $this->varrer();
        $lista = $this->todas("SELECT s.*,
                (SELECT COUNT(*) FROM reorg_sessoes o WHERE o.nome_chave = s.nome_chave AND o.status = 'ativa' AND o.id <> s.id) AS mesmo_nome,
                (SELECT COUNT(*) FROM reorg_solicitacoes q WHERE q.sessao_id = s.id AND q.status = 'pendente') AS solicitacoes,
                (SELECT COUNT(*) FROM reorg_registros r WHERE r.criado_sessao_id = s.id AND r.status <> 'descartado') AS registros,
                (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.carimbado_sessao_id = s.id) AS carimbos,
                (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.conferido_sessao_id = s.id) AS conferencias
            FROM reorg_sessoes s WHERE s.status = 'ativa'" . ($equipe !== '' ? ' AND s.equipe = ?' : '') . ' ORDER BY s.nome, s.id', $equipe !== '' ? [$equipe] : []);
        $saida = [];
        foreach ($lista as $s) {
            $s['estado'] = $this->estadoConexao($s);
            if ($estado !== '' && $s['estado'] !== $estado) {
                continue;
            }
            $s['lotes'] = $this->lotesDaSessao((int)$s['id']);
            $s['atividade'] = $this->atividadeAtual($s);
            $saida[] = $s;
        }
        return $saida;
    }

    private function atividadeAtual(array $s): string
    {
        $reg = $this->linha('SELECT r.numero, l.nome FROM reorg_registros r JOIN reorg_lotes l ON l.id = r.lote_id WHERE r.editando_sessao_id = ? AND r.editando_ate > NOW() LIMIT 1', [$s['id']]);
        if ($reg) {
            return 'Editando o registro nº ' . $reg['numero'] . ' (' . $reg['nome'] . ')';
        }
        $ex = $this->linha('SELECT e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.reservado_sessao_id = ? AND x.reservado_ate > NOW() LIMIT 1', [$s['id']]);
        if ($ex) {
            return 'Com o exemplar ' . $ex['codigo_individual'];
        }
        return $s['situacao'] === 'pausada' ? 'Atividade pausada' : ($s['lotes'] ? 'Sem atividade em andamento' : 'Aguardando atribuição de lote');
    }

    public function sessoesAnteriores(int $limite = 200): array
    {
        return $this->todas("SELECT s.*, a.nome AS encerrada_por_nome,
                (SELECT COUNT(*) FROM reorg_registros r WHERE r.criado_sessao_id = s.id AND r.status <> 'descartado') AS registros,
                (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.carimbado_sessao_id = s.id) AS carimbos,
                (SELECT COUNT(*) FROM reorg_registro_exemplares x WHERE x.conferido_sessao_id = s.id) AS conferencias,
                (SELECT GROUP_CONCAT(DISTINCT l.nome SEPARATOR ', ') FROM reorg_atribuicoes t JOIN reorg_lotes l ON l.id = t.lote_id WHERE t.sessao_id = s.id) AS lotes
            FROM reorg_sessoes s LEFT JOIN administradores a ON a.id = s.encerrada_por WHERE s.status = 'encerrada' ORDER BY s.encerrada_em DESC LIMIT " . max(1, min(1000, $limite)));
    }

    // =================================================================
    // Lotes
    // =================================================================

    /**
     * Cria o lote da reorganização e o lote gerenciado permanente ligado a ele.
     * Dados: nome, quantidade, responsavel_id, admins (outros administradores autorizados no Grupo 2),
     * grupo1/grupo3/grupo4 (ids de sessões ativas), observacoes, instrucoes, prazo.
     */
    public function criarLote(array $dados, int $adminId): int
    {
        $nome = self::textoLimpo((string)($dados['nome'] ?? ''), 120);
        if ($nome === null) {
            throw new AcervoException('Informe o nome ou a identificação do lote (ex.: Lote 001 — Estante A).');
        }
        $qtd = (int)($dados['quantidade'] ?? 0);
        $max = (int)$this->config['max_exemplares_lote'];
        if ($qtd < 1 || $qtd > $max) {
            throw new AcervoException('Informe a quantidade prevista de exemplares físicos (de 1 a ' . $max . ').');
        }
        $responsavel = (int)($dados['responsavel_id'] ?? $adminId) ?: $adminId;
        $prazo = trim((string)($dados['prazo'] ?? ''));
        if ($prazo !== '' && !(($dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $prazo)) && $dt->format('Y-m-d') === $prazo)) {
            throw new AcervoException('Prazo inválido.');
        }
        // Dois administradores criando lotes no mesmo instante podem disputar o mesmo número de lote
        // permanente (chave única). Fora de outra transação, tenta de novo com uma leitura nova.
        $tentativas = $this->pdo->inTransaction() ? 1 : 5;
        for ($t = 1; ; $t++) {
            try {
                return $this->criarLoteTransacao($dados, $adminId, $nome, $qtd, $responsavel, $prazo);
            } catch (\PDOException $e) {
                $disputa = in_array((int)($e->errorInfo[1] ?? 0), [1062, 1213], true) && !str_contains($e->getMessage(), 'reorg_');
                if (!$disputa || $t >= $tentativas) {
                    throw $e;
                }
                usleep(random_int(20000, 120000));
            }
        }
    }

    private function criarLoteTransacao(array $dados, int $adminId, string $nome, int $qtd, int $responsavel, string $prazo): int
    {
        return $this->transacao(function () use ($dados, $adminId, $nome, $qtd, $responsavel, $prazo) {
            $periodo = $this->periodoAtivo();
            if (!$periodo) {
                throw new AcervoException('Inicie um período de reorganização antes de criar lotes.');
            }
            if (!$this->valor('SELECT 1 FROM administradores WHERE id = ? AND ativo = 1', [$responsavel])) {
                throw new AcervoException('Escolha um administrador responsável ativo.');
            }
            $cadastroId = $this->lotesPermanentes->criar(['capacidade' => $qtd, 'usuarios' => [$responsavel], 'descricao' => 'Reorganização: ' . $nome], $adminId);
            $this->executar('INSERT INTO reorg_lotes (periodo_id, cadastro_lote_id, nome, quantidade_inicial, quantidade_prevista, responsavel_id, observacoes, instrucoes, prazo, criado_por)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                (int)$periodo['id'], $cadastroId, $nome, $qtd, $qtd, $responsavel,
                self::textoLimpoLongo($dados['observacoes'] ?? null), self::textoLimpoLongo($dados['instrucoes'] ?? null), $prazo ?: null, $adminId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->historico('lote_criado', 'Lote “' . $nome . '” criado com ' . $qtd . ' exemplar(es) previsto(s)', $id, null, $adminId, ['cadastro_lote_id' => $cadastroId, 'responsavel_id' => $responsavel]);
            foreach (array_unique(array_map('intval', (array)($dados['admins'] ?? []))) as $outro) {
                if ($outro > 0 && $outro !== $responsavel && $this->valor('SELECT 1 FROM administradores WHERE id = ? AND ativo = 1', [$outro])) {
                    $this->executar('INSERT INTO reorg_lote_admins (lote_id, administrador_id, incluido_por) VALUES (?, ?, ?)', [$id, $outro, $adminId]);
                }
            }
            foreach (self::EQUIPES_PARTICIPANTES as $eq) {
                foreach (array_unique(array_map('intval', (array)($dados[$eq] ?? []))) as $sessaoId) {
                    if ($sessaoId > 0) {
                        $this->atribuirInterno($id, $sessaoId, $eq, $adminId);
                    }
                }
            }
            $this->registrarAcao($adminId, 'reorganizacao.lote_criar', 'reorg_lote', $id, ['nome' => $nome, 'quantidade' => $qtd]);
            return $id;
        });
    }

    private static function textoLimpoLongo(mixed $v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, 5000);
    }

    public function lote(int $id): ?array
    {
        return $this->linha('SELECT l.*, a.nome AS responsavel_nome, c.numero AS cadastro_numero, c.status AS cadastro_status, p.nome AS periodo_nome
                             FROM reorg_lotes l JOIN administradores a ON a.id = l.responsavel_id JOIN cadastro_lotes c ON c.id = l.cadastro_lote_id
                             JOIN reorg_periodos p ON p.id = l.periodo_id WHERE l.id = ?', [$id]);
    }

    /**
     * Lotes com contadores, etapas em andamento e participantes por equipe.
     * Filtros: $status (em_andamento | pausado | concluido), $responsavel (id), $etapa (grupo1..grupo4:
     * lotes com exemplares esperando aquela etapa).
     */
    public function lotes(string $status = '', int $responsavel = 0, string $etapa = ''): array
    {
        $w = [];
        $p = [];
        if ($status !== '') {
            $w[] = 'l.status = ?';
            $p[] = $status;
        }
        if ($responsavel > 0) {
            $w[] = 'l.responsavel_id = ?';
            $p[] = $responsavel;
        }
        $lotes = $this->todas('SELECT l.*, a.nome AS responsavel_nome, c.numero AS cadastro_numero FROM reorg_lotes l JOIN administradores a ON a.id = l.responsavel_id
                               JOIN cadastro_lotes c ON c.id = l.cadastro_lote_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY l.id DESC', $p);
        $saida = [];
        foreach ($lotes as $l) {
            $l['contadores'] = $this->contadores((int)$l['id']);
            $l['etapas'] = self::etapasEmAndamento($l['contadores']);
            if ($etapa !== '' && !isset($l['etapas'][$etapa])) {
                continue;
            }
            $l['participantes'] = (int)$this->valor('SELECT COUNT(*) FROM reorg_atribuicoes WHERE lote_id = ? AND encerrada_em IS NULL', [$l['id']]);
            $l['por_equipe'] = array_column($this->todas('SELECT equipe, COUNT(*) AS n FROM reorg_atribuicoes WHERE lote_id = ? AND encerrada_em IS NULL GROUP BY equipe', [$l['id']]), 'n', 'equipe');
            $saida[] = $l;
        }
        return $saida;
    }

    /**
     * Etapas com exemplares esperando trabalho (um lote pode estar em várias ao mesmo tempo).
     * @return array<string, int> equipe => exemplares esperando
     */
    public static function etapasEmAndamento(array $c): array
    {
        $e = [
            'grupo1' => $c['aguardando_levantamento'] + $c['em_levantamento'],
            'grupo2' => $c['aguardando_revisao'] + $c['aprovados_sem_cadastro'],
            'grupo3' => $c['aguardando_carimbacao'] + $c['carimbados_nao_encaminhados'],
            'grupo4' => $c['carimbados'] - $c['carimbados_nao_encaminhados'] - $c['organizados'],
        ];
        return array_filter($e, fn ($n) => $n > 0);
    }

    /** Totais de todos os lotes do período ativo (Visão geral). */
    public function totaisPeriodo(): array
    {
        $t = ['previstos' => 0, 'registrados' => 0, 'revisados' => 0, 'codigos' => 0, 'carimbados' => 0, 'conferidos' => 0, 'organizados' => 0, 'com_problema' => 0];
        $periodo = $this->periodoAtivo();
        if (!$periodo) {
            return $t;
        }
        foreach ($this->todas('SELECT id FROM reorg_lotes WHERE periodo_id = ?', [$periodo['id']]) as $l) {
            $c = $this->contadores((int)$l['id']);
            $t['previstos'] += $c['prevista'];
            foreach (['registrados', 'revisados', 'codigos', 'carimbados', 'conferidos', 'organizados', 'com_problema'] as $k) {
                $t[$k] += $c[$k];
            }
        }
        return $t;
    }

    // =================================================================
    // Configurações editáveis (tempos das sessões temporárias)
    // =================================================================

    /** Chave => [rótulo, mínimo, máximo] (segundos). Gravadas em reorg_configuracoes (migração 230). */
    public const CONFIG_EDITAVEL = [
        'intervalo_sinal' => ['Comunicação periódica do navegador com o servidor', 5, 60],
        'tolerancia_desconexao' => ['Tempo sem comunicação até encerrar a sessão (desconectado)', 30, 3600],
        'reserva_edicao' => ['Reserva de um registro ou exemplar aberto por alguém', 30, 900],
    ];

    public function salvarConfiguracoes(array $dados, int $adminId): void
    {
        $this->transacao(function () use ($dados, $adminId) {
            foreach (self::CONFIG_EDITAVEL as $chave => [$rotulo, $min, $max]) {
                if (!isset($dados[$chave]) || (string)$dados[$chave] === '') {
                    continue;
                }
                $v = (int)$dados[$chave];
                if ($v < $min || $v > $max) {
                    throw new AcervoException($rotulo . ': use de ' . $min . ' a ' . $max . ' segundos.');
                }
                $this->executar('INSERT INTO reorg_configuracoes (chave, valor, atualizado_por) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por)', [$chave, (string)$v, $adminId]);
                $this->config[$chave] = $v;
            }
            if ((int)$this->config['tolerancia_desconexao'] < 2 * (int)$this->config['intervalo_sinal'] + 10) {
                throw new AcervoException('O tempo sem comunicação precisa ser pelo menos o dobro da comunicação periódica (mais 10 segundos).');
            }
            $this->historico('configuracoes', 'Tempos das sessões: comunicação ' . $this->config['intervalo_sinal'] . ' s, desconexão ' . $this->config['tolerancia_desconexao'] . ' s, reserva ' . $this->config['reserva_edicao'] . ' s', null, null, $adminId);
        });
    }

    /** Administradores do Grupo 2 deste lote (responsável + autorizados). */
    public function adminsDoLote(int $loteId): array
    {
        return $this->todas('SELECT a.id, a.nome, (a.id = l.responsavel_id) AS responsavel FROM reorg_lotes l JOIN administradores a ON a.id = l.responsavel_id WHERE l.id = ?
                             UNION SELECT a.id, a.nome, 0 FROM reorg_lote_admins x JOIN administradores a ON a.id = x.administrador_id WHERE x.lote_id = ? AND x.removido_em IS NULL', [$loteId, $loteId]);
    }

    public function autorizarAdmin(int $loteId, int $outroId, int $adminId, bool $incluir = true): void
    {
        $this->transacao(function () use ($loteId, $outroId, $adminId, $incluir) {
            $lote = $this->bloquearLote($loteId);
            if ($incluir) {
                if (!$this->valor('SELECT 1 FROM administradores WHERE id = ? AND ativo = 1', [$outroId])) {
                    throw new AcervoException('Administrador não encontrado.');
                }
                $this->executar('INSERT INTO reorg_lote_admins (lote_id, administrador_id, incluido_por) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE removido_em = NULL, incluido_por = VALUES(incluido_por), incluido_em = NOW()', [$loteId, $outroId, $adminId]);
            } else {
                $this->executar('UPDATE reorg_lote_admins SET removido_em = NOW() WHERE lote_id = ? AND administrador_id = ?', [$loteId, $outroId]);
            }
            $nome = (string)$this->valor('SELECT nome FROM administradores WHERE id = ?', [$outroId]);
            $this->historico($incluir ? 'admin_autorizado' : 'admin_removido', $nome . ($incluir ? ' autorizado' : ' retirado') . ' no Grupo 2 do ' . self::nomeLote($lote), $loteId, null, $adminId);
        });
    }

    /** Participantes atuais (atribuições ativas) e anteriores do lote. */
    public function participantesDoLote(int $loteId): array
    {
        $todos = $this->todas('SELECT a.*, s.nome, s.status AS sessao_status, s.ultimo_sinal_em, s.situacao, s.equipe AS equipe_sessao, s.motivo_encerramento, d.nome AS atribuida_por_nome
                               FROM reorg_atribuicoes a JOIN reorg_sessoes s ON s.id = a.sessao_id JOIN administradores d ON d.id = a.atribuida_por
                               WHERE a.lote_id = ? ORDER BY a.encerrada_em IS NOT NULL, a.equipe, a.atribuida_em', [$loteId]);
        $r = ['atuais' => [], 'anteriores' => []];
        foreach ($todos as $t) {
            $r[$t['encerrada_em'] === null ? 'atuais' : 'anteriores'][] = $t;
        }
        return $r;
    }

    public function atribuir(int $loteId, int $sessaoId, string $equipe, int $adminId): void
    {
        $this->transacao(fn () => $this->atribuirInterno($loteId, $sessaoId, $equipe, $adminId));
    }

    private function atribuirInterno(int $loteId, int $sessaoId, string $equipe, int $adminId): void
    {
        if (!in_array($equipe, self::EQUIPES_PARTICIPANTES, true)) {
            throw new AcervoException('Equipe inválida.');
        }
        $lote = $this->bloquearLote($loteId);
        if ($lote['status'] === 'concluido') {
            throw new AcervoException(self::nomeLote($lote) . ' já foi concluído.');
        }
        $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ? FOR UPDATE', [$sessaoId]);
        if (!$s || $s['status'] !== 'ativa') {
            throw new AcervoException('Só participantes com sessão ativa podem receber lotes. Esta sessão já foi encerrada.');
        }
        if ($s['equipe'] !== $equipe) {
            throw new AcervoException($s['nome'] . ' está no ' . ($s['equipe'] ? self::EQUIPES[$s['equipe']] : 'sem equipe escolhida') . '. Transfira a pessoa de equipe antes.');
        }
        if ($this->valor('SELECT 1 FROM reorg_atribuicoes WHERE lote_id = ? AND sessao_id = ? AND encerrada_em IS NULL', [$loteId, $sessaoId])) {
            return; // já atribuído
        }
        $this->executar('INSERT INTO reorg_atribuicoes (lote_id, sessao_id, equipe, atribuida_por) VALUES (?, ?, ?, ?)', [$loteId, $sessaoId, $equipe, $adminId]);
        if ($s['situacao'] === 'pausada') {
            $this->executar("UPDATE reorg_sessoes SET situacao = 'disponivel' WHERE id = ?", [$sessaoId]);
        }
        $this->notificarSessao($sessaoId, 'lote_atribuido', 'Novo lote atribuído: ' . self::nomeLote($lote) . ' (' . self::EQUIPES[$equipe] . ').', $loteId);
        $this->historico('participante_atribuido', $s['nome'] . ' (' . self::rotuloSessao($sessaoId) . ') atribuído ao ' . self::nomeLote($lote) . ' — ' . self::EQUIPES[$equipe], $loteId, $s, $adminId);
    }

    public function removerDoLote(int $loteId, int $sessaoId, int $adminId, string $motivo = 'removido'): void
    {
        $this->transacao(function () use ($loteId, $sessaoId, $adminId, $motivo) {
            $lote = $this->bloquearLote($loteId);
            $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ?', [$sessaoId]);
            $atrib = $this->todas('SELECT * FROM reorg_atribuicoes WHERE lote_id = ? AND sessao_id = ? AND encerrada_em IS NULL FOR UPDATE', [$loteId, $sessaoId]);
            if (!$atrib) {
                throw new AcervoException('Esta pessoa não está atribuída a este lote.');
            }
            $this->executar('UPDATE reorg_atribuicoes SET encerrada_em = NOW(), encerrada_por = ?, motivo_fim = ? WHERE lote_id = ? AND sessao_id = ? AND encerrada_em IS NULL', [$adminId, $motivo, $loteId, $sessaoId]);
            // Reservas deste lote liberadas; o que foi preenchido continua salvo no lote.
            $this->executar('UPDATE reorg_registros SET editando_sessao_id = NULL, editando_ate = NULL WHERE lote_id = ? AND editando_sessao_id = ?', [$loteId, $sessaoId]);
            $this->executar('UPDATE reorg_registro_exemplares SET reservado_sessao_id = NULL, reservado_ate = NULL WHERE lote_id = ? AND reservado_sessao_id = ?', [$loteId, $sessaoId]);
            if ($motivo === 'removido') {
                $this->notificarSessao($sessaoId, 'lote_removido', 'Você não está mais no ' . self::nomeLote($lote) . '. O que você preencheu continua salvo.', $loteId);
            }
            $this->historico('participante_removido', $s['nome'] . ' (' . self::rotuloSessao($sessaoId) . ') saiu do ' . self::nomeLote($lote) . ($motivo === 'transferido' ? ' (transferência)' : ''), $loteId, $s, $adminId);
            $this->avisarSemParticipantes($loteId, $atrib[0]['equipe']);
        });
    }

    /** Transfere o participante de um lote para outro (mesma equipe). Nada do que foi preenchido é apagado. */
    public function transferirLote(int $sessaoId, int $deLote, int $paraLote, int $adminId): void
    {
        if ($deLote === $paraLote) {
            throw new AcervoException('Escolha um lote diferente.');
        }
        $this->transacao(function () use ($sessaoId, $deLote, $paraLote, $adminId) {
            $equipe = $this->valor('SELECT equipe FROM reorg_atribuicoes WHERE lote_id = ? AND sessao_id = ? AND encerrada_em IS NULL', [$deLote, $sessaoId]);
            if (!$equipe) {
                throw new AcervoException('Esta pessoa não está atribuída ao lote de origem.');
            }
            $this->removerDoLote($deLote, $sessaoId, $adminId, 'transferido');
            $this->atribuirInterno($paraLote, $sessaoId, $equipe, $adminId);
            $para = $this->linha('SELECT nome FROM reorg_lotes WHERE id = ?', [$paraLote]);
            $this->notificarSessao($sessaoId, 'transferencia', 'Você foi transferido para o ' . $para['nome'] . '.', $paraLote);
        });
    }

    /**
     * Transfere o participante de equipe (também usado para aprovar uma solicitação de troca).
     * As atribuições da equipe anterior terminam; o que foi preenchido continua no lote.
     */
    public function transferirEquipe(int $sessaoId, string $equipe, int $adminId): void
    {
        if (!in_array($equipe, self::EQUIPES_PARTICIPANTES, true)) {
            throw new AcervoException('Equipe inválida.');
        }
        $this->transacao(function () use ($sessaoId, $equipe, $adminId) {
            $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ? FOR UPDATE', [$sessaoId]);
            if (!$s || $s['status'] !== 'ativa') {
                throw new AcervoException('Esta sessão já foi encerrada.');
            }
            if ($s['equipe'] === $equipe) {
                throw new AcervoException($s['nome'] . ' já está nesta equipe.');
            }
            $antigos = $this->lotesDaSessao($sessaoId);
            $this->executar("UPDATE reorg_atribuicoes SET encerrada_em = NOW(), encerrada_por = ?, motivo_fim = 'troca_equipe' WHERE sessao_id = ? AND encerrada_em IS NULL", [$adminId, $sessaoId]);
            $this->liberarReservas($sessaoId);
            $this->executar('UPDATE reorg_sessoes SET equipe = ? WHERE id = ?', [$equipe, $sessaoId]);
            $this->notificarSessao($sessaoId, 'troca_equipe', 'Sua equipe foi alterada para ' . self::EQUIPES[$equipe] . '. Aguarde a atribuição de um lote.');
            $this->historico('equipe_transferida', $s['nome'] . ' (' . self::rotuloSessao($sessaoId) . '): ' . ($s['equipe'] ? self::EQUIPES[$s['equipe']] : 'sem equipe') . ' → ' . self::EQUIPES[$equipe], null, $s, $adminId);
            foreach ($antigos as $l) {
                $this->avisarSemParticipantes((int)$l['id'], $l['equipe']);
            }
        });
    }

    public function pausarLote(int $loteId, int $adminId, bool $pausar = true): void
    {
        $this->transacao(function () use ($loteId, $adminId, $pausar) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] === 'concluido') {
                throw new AcervoException('Lote concluído. Use “Reabrir lote”.');
            }
            if (($lote['status'] === 'pausado') === $pausar) {
                return;
            }
            $this->executar('UPDATE reorg_lotes SET status = ? WHERE id = ?', [$pausar ? 'pausado' : 'em_andamento', $loteId]);
            $this->executar('UPDATE reorg_registros SET editando_sessao_id = NULL, editando_ate = NULL WHERE lote_id = ?', [$loteId]);
            $this->executar('UPDATE reorg_registro_exemplares SET reservado_sessao_id = NULL, reservado_ate = NULL WHERE lote_id = ?', [$loteId]);
            $c = $this->contadores($loteId);
            $this->historico($pausar ? 'lote_pausado' : 'lote_retomado', self::nomeLote($lote) . ($pausar ? ' pausado' : ' retomado'), $loteId, null, $adminId, ['progresso' => $c]);
            $this->notificarParticipantesLote($loteId, 'lote_alterado', self::nomeLote($lote) . ($pausar ? ' foi pausado pelo administrador. O que você fez está salvo.' : ' foi retomado.'));
        });
    }

    /** Pausa ou libera uma etapa específica do lote (grupo1..grupo4). */
    public function pausarEtapa(int $loteId, string $etapa, int $adminId, bool $pausar = true): void
    {
        if (!isset(self::EQUIPES[$etapa])) {
            throw new AcervoException('Etapa inválida.');
        }
        $this->transacao(function () use ($loteId, $etapa, $adminId, $pausar) {
            $lote = $this->bloquearLote($loteId);
            $set = array_filter(explode(',', (string)$lote['etapas_pausadas']));
            $set = $pausar ? array_unique([...$set, $etapa]) : array_diff($set, [$etapa]);
            $this->executar('UPDATE reorg_lotes SET etapas_pausadas = ? WHERE id = ?', [implode(',', $set), $loteId]);
            $this->historico($pausar ? 'etapa_pausada' : 'etapa_liberada', self::nomeLote($lote) . ': ' . self::EQUIPES[$etapa] . ($pausar ? ' pausada' : ' liberada'), $loteId, null, $adminId);
            if ($etapa !== 'grupo2') {
                $this->notificarParticipantesLote($loteId, $pausar ? 'etapa_pausada' : 'etapa_liberada', self::nomeLote($lote) . ': ' . self::EQUIPES[$etapa] . ($pausar ? ' pausada pelo administrador.' : ' liberada.'), $etapa);
            }
        });
    }

    public function reabrirLote(int $loteId, int $adminId, string $motivo): void
    {
        $motivo = self::textoLimpo($motivo, 255);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo da reabertura.');
        }
        $this->transacao(function () use ($loteId, $adminId, $motivo) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] !== 'concluido') {
                throw new AcervoException('Só um lote concluído pode ser reaberto.');
            }
            $this->executar("UPDATE reorg_lotes SET status = 'em_andamento' WHERE id = ?", [$loteId]);
            $this->historico('lote_reaberto', self::nomeLote($lote) . ' reaberto · ' . $motivo, $loteId, null, $adminId);
        });
    }

    /**
     * Ajusta a quantidade final do lote (a quantidade física prevista estava errada). Nunca abaixo do
     * que já foi registrado pelo Grupo 1. O lote permanente acompanha (capacidade e reabertura para
     * cadastro, se for o caso), com o evento no histórico do lote.
     */
    public function ajustarQuantidade(int $loteId, int $nova, int $adminId, string $justificativa): void
    {
        $justificativa = self::textoLimpo($justificativa, 255);
        if ($justificativa === null) {
            throw new AcervoException('Informe o motivo do ajuste da quantidade.');
        }
        $max = (int)$this->config['max_exemplares_lote'];
        $this->transacao(function () use ($loteId, $nova, $adminId, $justificativa, $max) {
            $this->valor('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1 FOR UPDATE'); // mesma ordem de travas do cadastro
            $lote = $this->bloquearLote($loteId);
            $registrados = $this->exemplaresRegistrados($loteId);
            if ($nova < max(1, $registrados) || $nova > $max) {
                throw new AcervoException('A quantidade precisa ficar entre ' . max(1, $registrados) . ' (já registrados pelo Grupo 1) e ' . $max . '.');
            }
            if ($nova === (int)$lote['quantidade_prevista']) {
                return;
            }
            $cad = $this->linha('SELECT * FROM cadastro_lotes WHERE id = ? FOR UPDATE', [(int)$lote['cadastro_lote_id']]);
            $total = (int)$cad['total'];
            if ($cad['status'] === 'encerrado') {
                throw new AcervoException('O lote permanente ligado a este lote já foi encerrado. Crie outro lote para os exemplares que faltam.');
            }
            $status = $cad['status'];
            if ($total < $nova && in_array($status, ['fechado', 'em_carimbagem', 'aguardando_organizacao'], true)) {
                $status = 'aberto'; // volta a receber cadastros
                $this->executar("UPDATE cadastro_lotes SET status = 'aberto' WHERE id = ?", [$cad['id']]);
            } elseif ($total >= $nova && $status === 'aberto') {
                $status = 'fechado';
                $this->executar("UPDATE cadastro_lotes SET status = 'fechado', fechado_em = NOW(), fechado_por = ?, fechamento_tipo = 'automatico' WHERE id = ?", [$adminId, $cad['id']]);
            }
            $this->executar('UPDATE cadastro_lotes SET capacidade = ? WHERE id = ?', [$nova, $cad['id']]);
            $this->exemplares->eventoLote((int)$cad['id'], null, 'correcao', 'Reorganização: quantidade prevista ' . $lote['quantidade_prevista'] . ' → ' . $nova . ' · ' . $justificativa
                . ($status !== $cad['status'] ? ' · situação: ' . ExemplarService::rotuloStatusLote($status) : ''), $adminId, ['de' => (int)$lote['quantidade_prevista'], 'para' => $nova]);
            $this->executar('UPDATE reorg_lotes SET quantidade_prevista = ? WHERE id = ?', [$nova, $loteId]);
            $this->historico('quantidade_alterada', self::nomeLote($lote) . ': quantidade prevista ' . $lote['quantidade_prevista'] . ' → ' . $nova . ' · ' . $justificativa, $loteId, null, $adminId,
                ['de' => (int)$lote['quantidade_prevista'], 'para' => $nova]);
            $this->notificarParticipantesLote($loteId, 'lote_alterado', self::nomeLote($lote) . ': quantidade prevista alterada para ' . $nova . ' exemplar(es).', 'grupo1');
        });
    }

    public function exemplaresRegistrados(int $loteId): int
    {
        return (int)$this->valor("SELECT COUNT(*) FROM reorg_registro_exemplares x JOIN reorg_registros r ON r.id = x.registro_id
                                  WHERE x.lote_id = ? AND x.removido_em IS NULL AND r.status <> 'descartado'", [$loteId]);
    }

    public function alterarLote(int $loteId, array $dados, int $adminId): void
    {
        $this->transacao(function () use ($loteId, $dados, $adminId) {
            $lote = $this->bloquearLote($loteId);
            $nome = self::textoLimpo((string)($dados['nome'] ?? $lote['nome']), 120) ?? $lote['nome'];
            $prazo = trim((string)($dados['prazo'] ?? ''));
            if ($prazo !== '' && !(($dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $prazo)) && $dt->format('Y-m-d') === $prazo)) {
                throw new AcervoException('Prazo inválido.');
            }
            $this->executar('UPDATE reorg_lotes SET nome = ?, observacoes = ?, instrucoes = ?, prazo = ? WHERE id = ?',
                [$nome, self::textoLimpoLongo($dados['observacoes'] ?? null), self::textoLimpoLongo($dados['instrucoes'] ?? null), $prazo ?: null, $loteId]);
            $this->historico('lote_alterado', self::nomeLote($lote) . ': dados do lote alterados', $loteId, null, $adminId);
        });
    }

    /** Notifica todos os participantes ativos do lote (de uma equipe, se informada). */
    public function notificarParticipantesLote(int $loteId, string $tipo, string $texto, ?string $equipe = null, ?string $chave = null): void
    {
        $sql = 'SELECT DISTINCT sessao_id FROM reorg_atribuicoes WHERE lote_id = ? AND encerrada_em IS NULL' . ($equipe ? ' AND equipe = ?' : '');
        foreach ($this->todas($sql, $equipe ? [$loteId, $equipe] : [$loteId]) as $a) {
            $this->notificarSessao((int)$a['sessao_id'], $tipo, $texto, $loteId, $chave !== null ? $chave . ':' . $a['sessao_id'] : null);
        }
    }

    /** Interno (Levantamento/Etapas). */
    public function registrarHistorico(string $acao, string $descricao, ?int $loteId = null, ?array $sessao = null, ?int $adminId = null, array $detalhes = []): void
    {
        $this->historico($acao, $descricao, $loteId, $sessao, $adminId, $detalhes);
    }

    public function avisarAdmins(string $tipo, string $texto, ?int $loteId = null, ?int $adminId = null, ?string $chave = null): void
    {
        $this->notificarAdmins($tipo, $texto, $loteId, $adminId, $chave);
    }

    public function avisarSessao(int $sessaoId, string $tipo, string $texto, ?int $loteId = null, ?string $chave = null): void
    {
        $this->notificarSessao($sessaoId, $tipo, $texto, $loteId, $chave);
    }

    // =================================================================
    // Contadores e conclusão
    // =================================================================

    /**
     * Números do lote. O progresso é do LOTE (por exemplar físico), não da sessão de quem trabalhou.
     */
    public function contadores(int $loteId): array
    {
        $lote = $this->linha('SELECT quantidade_prevista, quantidade_inicial FROM reorg_lotes WHERE id = ?', [$loteId]);
        $x = $this->linha("SELECT
                COUNT(*) AS registrados,
                SUM(r.status IN ('rascunho', 'correcao')) AS em_levantamento,
                SUM(r.status = 'aguardando_revisao') AS aguardando_revisao,
                SUM(r.status IN ('aprovado', 'cadastrado')) AS aprovados,
                SUM(r.status = 'aprovado') AS aprovados_sem_cadastro,
                SUM(r.status = 'cadastrado') AS cadastrados,
                SUM(x.exemplar_id IS NOT NULL) AS codigos,
                SUM(x.exemplar_id IS NOT NULL AND x.carimbado_em IS NULL) AS aguardando_carimbacao,
                SUM(x.carimbado_em IS NOT NULL) AS carimbados,
                SUM(x.carimbado_em IS NOT NULL AND x.encaminhado_g4_em IS NULL) AS carimbados_nao_encaminhados,
                SUM(x.encaminhado_g4_em IS NOT NULL AND x.conferido_em IS NULL) AS aguardando_conferencia,
                SUM(x.conferido_em IS NOT NULL) AS conferidos,
                SUM(x.organizado_em IS NOT NULL) AS organizados,
                SUM(x.problema_aberto = 1) AS com_problema,
                SUM(x.estado_conservacao = 'excelente') AS excelente,
                SUM(x.estado_conservacao = 'regular') AS regular,
                SUM(x.estado_conservacao = 'ruim') AS ruim
            FROM reorg_registro_exemplares x JOIN reorg_registros r ON r.id = x.registro_id
            WHERE x.lote_id = ? AND x.removido_em IS NULL AND r.status <> 'descartado'", [$loteId]);
        $c = array_map('intval', $x ?: []);
        $c['revisados'] = (int)$this->valor("SELECT COALESCE(SUM(quantidade), 0) FROM reorg_registros WHERE lote_id = ? AND revisado_em IS NOT NULL AND status <> 'descartado'", [$loteId]);
        $c['prevista'] = (int)($lote['quantidade_prevista'] ?? 0);
        $c['inicial'] = (int)($lote['quantidade_inicial'] ?? 0);
        $c['aguardando_levantamento'] = max(0, $c['prevista'] - $c['registrados']);
        $c['restantes'] = max(0, $c['prevista'] - $c['organizados']);
        $c['registros_por_status'] = array_column($this->todas('SELECT status, COUNT(*) AS n FROM reorg_registros WHERE lote_id = ? GROUP BY status', [$loteId]), 'n', 'status');
        $c['pendencias_abertas'] = (int)$this->valor("SELECT COUNT(*) FROM reorg_pendencias WHERE lote_id = ? AND status <> 'resolvida'", [$loteId]);
        $c['ajuda_pendente'] = (int)$this->valor("SELECT COUNT(*) FROM reorg_solicitacoes WHERE lote_id = ? AND tipo = 'ajuda' AND status = 'pendente'", [$loteId]);
        $c['percentual'] = $c['prevista'] ? (int)floor(100 * $c['organizados'] / $c['prevista']) : 0;
        return $c;
    }

    /** O que impede a conclusão integral (vazio = pode concluir). @return list<string> */
    public function impedimentosConclusao(int $loteId): array
    {
        $c = $this->contadores($loteId);
        $m = [];
        if ($c['registrados'] < $c['prevista']) {
            $m[] = ($c['prevista'] - $c['registrados']) . ' exemplar(es) previsto(s) ainda sem levantamento (ajuste a quantidade final se o lote tem menos livros).';
        }
        if ($c['em_levantamento'] + $c['aguardando_revisao'] + $c['aprovados_sem_cadastro'] > 0) {
            $m[] = ($c['em_levantamento'] + $c['aguardando_revisao'] + $c['aprovados_sem_cadastro']) . ' exemplar(es) ainda em levantamento, revisão ou aguardando cadastro.';
        }
        if ($c['aguardando_carimbacao'] > 0) {
            $m[] = $c['aguardando_carimbacao'] . ' exemplar(es) aguardando carimbação.';
        }
        if ($c['codigos'] - $c['conferidos'] > 0) {
            $m[] = ($c['codigos'] - $c['conferidos']) . ' exemplar(es) sem a conferência do Grupo 4.';
        }
        if ($c['conferidos'] - $c['organizados'] > 0) {
            $m[] = ($c['conferidos'] - $c['organizados']) . ' exemplar(es) conferido(s) ainda não organizado(s).';
        }
        if ($c['pendencias_abertas'] > 0) {
            $m[] = $c['pendencias_abertas'] . ' pendência(s) não resolvida(s).';
        }
        return $m;
    }

    /** Resumo do lote (o mesmo gravado na conclusão). */
    public function resumo(int $loteId): array
    {
        $lote = $this->lote($loteId);
        $c = $this->contadores($loteId);
        return [
            'lote' => $lote['nome'],
            'prevista_inicial' => (int)$lote['quantidade_inicial'],
            'quantidade_final' => (int)$lote['quantidade_prevista'],
            'obras_cadastradas' => (int)$this->valor("SELECT COUNT(DISTINCT livro_id) FROM reorg_registros WHERE lote_id = ? AND status = 'cadastrado' AND obra_nova = 1", [$loteId]),
            'obras_reaproveitadas' => (int)$this->valor("SELECT COUNT(DISTINCT livro_id) FROM reorg_registros WHERE lote_id = ? AND status = 'cadastrado' AND obra_nova = 0", [$loteId]),
            'exemplares_cadastrados' => $c['codigos'],
            'codigos_gerados' => $c['codigos'],
            'carimbados' => $c['carimbados'],
            'conferidos' => $c['conferidos'],
            'organizados' => $c['organizados'],
            'conservacao' => ['excelente' => $c['excelente'], 'regular' => $c['regular'], 'ruim' => $c['ruim']],
            'participantes' => array_column($this->todas('SELECT DISTINCT s.nome, s.id FROM reorg_atribuicoes a JOIN reorg_sessoes s ON s.id = a.sessao_id WHERE a.lote_id = ? ORDER BY s.nome', [$loteId]), 'nome'),
            'administradores' => array_column($this->adminsDoLote($loteId), 'nome'),
            'pendencias_resolvidas' => (int)$this->valor("SELECT COUNT(*) FROM reorg_pendencias WHERE lote_id = ? AND status = 'resolvida'", [$loteId]),
            'sem_estante' => (int)$this->valor('SELECT COUNT(*) FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.lote_id = ? AND e.localizacao_id IS NULL', [$loteId]),
            'concluido_em' => $lote['concluido_em'],
        ];
    }

    /**
     * Conclusão INTEGRAL: todos os exemplares do escopo final passaram por todas as etapas. Grava o
     * resumo, encerra as atribuições e acompanha o lote permanente (carimbagem confirmada e, quando
     * todos têm estante, encerrado). Avisos do lote permanente não impedem a conclusão.
     * @return array{resumo: array, avisos: list<string>}
     */
    public function concluirLote(int $loteId, int $adminId): array
    {
        $avisos = [];
        $resumo = $this->transacao(function () use ($loteId, $adminId) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['status'] === 'concluido') {
                throw new AcervoException(self::nomeLote($lote) . ' já está concluído.');
            }
            $imp = $this->impedimentosConclusao($loteId);
            if ($imp) {
                throw new AcervoException('O lote ainda não pode ser concluído: ' . implode(' ', $imp));
            }
            $this->executar("UPDATE reorg_lotes SET status = 'concluido', concluido_em = NOW(), concluido_por = ? WHERE id = ?", [$adminId, $loteId]);
            $resumo = $this->resumo($loteId);
            $this->executar('UPDATE reorg_lotes SET resumo_conclusao = ? WHERE id = ?', [json_encode($resumo, JSON_UNESCAPED_UNICODE), $loteId]);
            $this->notificarParticipantesLote($loteId, 'lote_concluido', self::nomeLote($lote) . ' foi concluído. Obrigado!');
            $this->executar("UPDATE reorg_atribuicoes SET encerrada_em = NOW(), encerrada_por = ?, motivo_fim = 'lote_concluido' WHERE lote_id = ? AND encerrada_em IS NULL", [$adminId, $loteId]);
            $this->historico('lote_concluido', self::nomeLote($lote) . ' concluído: ' . $resumo['organizados'] . ' exemplar(es) organizados', $loteId, null, $adminId, $resumo);
            $this->notificarAdmins('lote_concluido', self::nomeLote($lote) . ' foi concluído.', $loteId);
            $this->registrarAcao($adminId, 'reorganizacao.lote_concluir', 'reorg_lote', $loteId, []);
            return $resumo;
        });
        // Lote permanente: cada passo em sua própria transação; um aviso não desfaz a conclusão.
        $lote = $this->lote($loteId);
        $cadId = (int)$lote['cadastro_lote_id'];
        try {
            $cad = $this->linha('SELECT status FROM cadastro_lotes WHERE id = ?', [$cadId]);
            if ($cad['status'] === 'aberto') {
                $this->lotesPermanentes->fecharAntecipado($cadId, $adminId, 'Reorganização concluída');
            }
            $cad = $this->linha('SELECT status FROM cadastro_lotes WHERE id = ?', [$cadId]);
            if (in_array($cad['status'], ['fechado', 'em_carimbagem'], true)) {
                $this->lotesPermanentes->confirmarCarimbagem($cadId, $adminId);
            }
            if ($resumo['sem_estante'] === 0) {
                $this->lotesPermanentes->encerrar($cadId, $adminId);
            } else {
                $avisos[] = $resumo['sem_estante'] . ' exemplar(es) organizado(s) sem estante e prateleira cadastradas: continuam “Aguardando liberação” para empréstimo até receberem a localização (Organização Física).';
            }
        } catch (AcervoException $e) {
            $avisos[] = 'Lote permanente: ' . $e->getMessage();
        }
        return ['resumo' => $resumo, 'avisos' => $avisos];
    }

    // =================================================================
    // Solicitações (novo lote, ajuda, troca de equipe)
    // =================================================================

    public const TIPOS_PROBLEMA = [
        'duvida_dados' => 'Dúvida sobre os dados do livro',
        'livro_nao_localizado' => 'Livro não localizado',
        'codigo' => 'Problema com o código BDP',
        'sistema' => 'Problema no sistema',
        'organizacao' => 'Dificuldade na organização',
        'outro' => 'Outro',
    ];

    public function solicitar(array $sessao, string $tipo, array $dados = []): int
    {
        if (!in_array($tipo, ['novo_lote', 'ajuda', 'troca_equipe'], true)) {
            throw new AcervoException('Tipo de solicitação inválido.');
        }
        $this->exigirSessaoAtiva($sessao);
        $s = $this->linha('SELECT * FROM reorg_sessoes WHERE id = ?', [(int)$sessao['id']]);
        $loteId = (int)($dados['lote_id'] ?? 0) ?: null;
        if ($loteId !== null && !$this->valor('SELECT 1 FROM reorg_atribuicoes WHERE lote_id = ? AND sessao_id = ? AND encerrada_em IS NULL', [$loteId, $s['id']])) {
            $loteId = null; // só lotes da própria sessão
        }
        $descricao = self::textoLimpo((string)($dados['descricao'] ?? ''), 1000);
        $desejada = null;
        if ($tipo === 'ajuda' && $descricao === null) {
            throw new AcervoException('Descreva a dúvida ou a dificuldade.');
        }
        if ($tipo === 'troca_equipe') {
            $desejada = (string)($dados['equipe_desejada'] ?? '');
            if (!in_array($desejada, self::EQUIPES_PARTICIPANTES, true) || $desejada === $s['equipe']) {
                throw new AcervoException('Escolha a equipe para a qual deseja mudar.');
            }
        }
        if (in_array($tipo, ['novo_lote', 'troca_equipe'], true)
            && $this->valor("SELECT 1 FROM reorg_solicitacoes WHERE sessao_id = ? AND tipo = ? AND status = 'pendente'", [$s['id'], $tipo])) {
            throw new AcervoException('Você já tem uma solicitação deste tipo aguardando o administrador.');
        }
        $problema = (string)($dados['problema_tipo'] ?? '');
        return $this->transacao(function () use ($s, $tipo, $loteId, $descricao, $desejada, $problema, $dados) {
            $this->executar('INSERT INTO reorg_solicitacoes (periodo_id, tipo, sessao_id, nome, equipe_atual, equipe_desejada, lote_id, etapa, problema_tipo, descricao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                (int)$s['periodo_id'], $tipo, $s['id'], $s['nome'], $s['equipe'], $desejada, $loteId,
                self::textoLimpo((string)($dados['etapa'] ?? ''), 80) ?? ($s['equipe'] ? self::EQUIPES[$s['equipe']] : null),
                isset(self::TIPOS_PROBLEMA[$problema]) ? self::TIPOS_PROBLEMA[$problema] : null, $descricao,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $rotulo = ['novo_lote' => 'pediu um novo lote', 'ajuda' => 'pediu ajuda', 'troca_equipe' => 'pediu para mudar para o ' . ($desejada ? self::EQUIPES[$desejada] : '')][$tipo];
            $responsavel = $loteId ? (int)$this->valor('SELECT responsavel_id FROM reorg_lotes WHERE id = ?', [$loteId]) : null;
            $this->notificarAdmins('solicitacao_' . $tipo, $s['nome'] . ' (' . self::rotuloSessao($s['id']) . ') ' . $rotulo . ($descricao ? ': ' . mb_substr($descricao, 0, 150) : '.'), $loteId, $responsavel);
            $this->historico('solicitacao_' . $tipo, $s['nome'] . ' ' . $rotulo, $loteId, $s, null, ['solicitacao_id' => $id]);
            return $id;
        });
    }

    public function solicitacoes(string $status = 'pendente', string $tipo = ''): array
    {
        $w = [];
        $p = [];
        if ($status !== '') {
            $w[] = 'q.status = ?';
            $p[] = $status;
        }
        if ($tipo !== '') {
            $w[] = 'q.tipo = ?';
            $p[] = $tipo;
        }
        return $this->todas('SELECT q.*, s.status AS sessao_status, l.nome AS lote_nome, a.nome AS respondida_por_nome, f.nome AS encaminhada_para_nome
                             FROM reorg_solicitacoes q JOIN reorg_sessoes s ON s.id = q.sessao_id LEFT JOIN reorg_lotes l ON l.id = q.lote_id
                             LEFT JOIN administradores a ON a.id = q.respondida_por LEFT JOIN administradores f ON f.id = q.encaminhada_para'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY q.status = \'pendente\' DESC, q.id DESC LIMIT 300', $p);
    }

    /**
     * Resposta do administrador. $decisao:
     *   novo_lote:    aprovar | recusar | atribuir (lote_id) | pendente (mantém) — "criar" = criar o lote
     *                 na página e depois "atribuir";
     *   troca_equipe: aprovar | recusar;
     *   ajuda:        responder | resolver | encaminhar (admin_id).
     */
    public function responderSolicitacao(int $id, string $decisao, int $adminId, string $resposta = '', array $extra = []): void
    {
        $resposta = self::textoLimpo($resposta, 1000);
        $this->transacao(function () use ($id, $decisao, $adminId, $resposta, $extra) {
            $q = $this->linha('SELECT * FROM reorg_solicitacoes WHERE id = ? FOR UPDATE', [$id]);
            if (!$q) {
                throw new AcervoException('Solicitação não encontrada.');
            }
            $sessaoAtiva = $this->valor("SELECT 1 FROM reorg_sessoes WHERE id = ? AND status = 'ativa'", [$q['sessao_id']]);
            $novo = null;
            $texto = null;
            switch ($q['tipo'] . ':' . $decisao) {
                case 'novo_lote:pendente':
                    if ($resposta !== null) {
                        $this->executar('UPDATE reorg_solicitacoes SET resposta = ? WHERE id = ?', [$resposta, $id]);
                        $texto = 'Sobre o seu pedido de novo lote: ' . $resposta;
                    }
                    break;
                case 'novo_lote:aprovar':
                    $novo = 'aprovada';
                    $texto = 'Seu pedido de novo lote foi aprovado. Aguarde a atribuição pelo administrador.' . ($resposta ? ' ' . $resposta : '');
                    break;
                case 'novo_lote:atribuir':
                    if (!$sessaoAtiva) {
                        throw new AcervoException('A sessão desta pessoa já foi encerrada.');
                    }
                    $loteId = (int)($extra['lote_id'] ?? 0);
                    $s = $this->linha('SELECT equipe FROM reorg_sessoes WHERE id = ?', [$q['sessao_id']]);
                    $this->atribuirInterno($loteId, (int)$q['sessao_id'], (string)$s['equipe'], $adminId);
                    $novo = 'aprovada';
                    $texto = null; // a atribuição já notifica
                    $this->executar('UPDATE reorg_solicitacoes SET lote_id = ? WHERE id = ?', [$loteId, $id]);
                    break;
                case 'novo_lote:recusar':
                case 'troca_equipe:recusar':
                    $novo = 'recusada';
                    $texto = 'Sua solicitação (' . ($q['tipo'] === 'novo_lote' ? 'novo lote' : 'alteração de equipe') . ') foi recusada.' . ($resposta ? ' ' . $resposta : '');
                    break;
                case 'troca_equipe:aprovar':
                    if (!$sessaoAtiva) {
                        throw new AcervoException('A sessão desta pessoa já foi encerrada.');
                    }
                    $this->transferirEquipe((int)$q['sessao_id'], (string)$q['equipe_desejada'], $adminId);
                    $novo = 'aprovada';
                    break;
                case 'ajuda:responder':
                    if ($resposta === null) {
                        throw new AcervoException('Escreva a resposta.');
                    }
                    $novo = 'respondida';
                    $texto = 'Resposta ao seu pedido de ajuda: ' . $resposta;
                    break;
                case 'ajuda:resolver':
                    $novo = 'resolvida';
                    $texto = 'Seu pedido de ajuda foi marcado como resolvido.' . ($resposta ? ' ' . $resposta : '');
                    break;
                case 'ajuda:encaminhar':
                    $para = (int)($extra['admin_id'] ?? 0);
                    if (!$this->valor('SELECT 1 FROM administradores WHERE id = ? AND ativo = 1', [$para])) {
                        throw new AcervoException('Escolha o administrador para quem encaminhar.');
                    }
                    $this->executar("UPDATE reorg_solicitacoes SET status = 'encaminhada', encaminhada_para = ?, respondida_por = ?, respondida_em = NOW(), resposta = COALESCE(?, resposta) WHERE id = ?", [$para, $adminId, $resposta, $id]);
                    $this->notificarAdmins('ajuda_encaminhada', 'Pedido de ajuda de ' . $q['nome'] . ' encaminhado para você: ' . mb_substr((string)$q['descricao'], 0, 150), $q['lote_id'] ? (int)$q['lote_id'] : null, $para);
                    $texto = 'Seu pedido de ajuda foi encaminhado a outro administrador.';
                    break;
                default:
                    throw new AcervoException('Ação inválida para esta solicitação.');
            }
            if ($novo !== null) {
                $this->executar('UPDATE reorg_solicitacoes SET status = ?, resposta = COALESCE(?, resposta), respondida_por = ?, respondida_em = NOW() WHERE id = ?', [$novo, $resposta, $adminId, $id]);
            }
            if ($texto !== null && $sessaoAtiva) {
                $this->notificarSessao((int)$q['sessao_id'], 'solicitacao_resposta', $texto, $q['lote_id'] ? (int)$q['lote_id'] : null);
            }
            $this->historico('solicitacao_respondida', 'Solicitação nº ' . $id . ' (' . $q['tipo'] . ') de ' . $q['nome'] . ': ' . $decisao, $q['lote_id'] ? (int)$q['lote_id'] : null, null, $adminId);
        });
    }

    /** Solicitações e respostas da sessão atual (só desta sessão). */
    public function solicitacoesDaSessao(int $sessaoId): array
    {
        return $this->todas('SELECT id, tipo, status, descricao, resposta, criado_em, respondida_em FROM reorg_solicitacoes WHERE sessao_id = ? ORDER BY id DESC LIMIT 30', [$sessaoId]);
    }

    // =================================================================
    // Pendências
    // =================================================================

    public const TIPOS_PENDENCIA = [
        'isbn_nao_encontrado' => 'ISBN não encontrado',
        'cdd_nao_encontrada' => 'CDD não encontrada',
        'dados_incompletos' => 'Dados bibliográficos incompletos',
        'divergencia_obras' => 'Divergência entre obras',
        'exemplar_danificado' => 'Exemplar danificado',
        'livro_nao_localizado' => 'Livro não localizado',
        'codigo_incorreto' => 'Código BDP incorreto',
        'codigo_livro_errado' => 'Código BDP associado ao livro errado',
        'codigo_ja_conferido' => 'Código BDP já conferido',
        'organizacao' => 'Dificuldade de organização',
        'outro' => 'Outro problema',
    ];

    /**
     * Registra uma pendência. Participante: $sessao (lote atribuído obrigatório); administrador: $adminId.
     * @param array{registro_id?: int, registro_exemplar_id?: int} $ref
     */
    public function registrarPendencia(int $loteId, string $tipo, string $descricao, string $grupo, ?array $sessao = null, ?int $adminId = null, array $ref = []): int
    {
        if (!isset(self::TIPOS_PENDENCIA[$tipo])) {
            throw new AcervoException('Escolha o tipo do problema.');
        }
        $descricao = self::textoLimpo($descricao, 1000) ?? self::TIPOS_PENDENCIA[$tipo];
        return $this->transacao(function () use ($loteId, $tipo, $descricao, $grupo, $sessao, $adminId, $ref) {
            if ($sessao !== null) {
                $this->exigirAcesso($sessao, $loteId, $grupo, false);
            }
            $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$loteId]);
            if (!$lote) {
                throw new AcervoException('Lote não encontrado.');
            }
            $regId = !empty($ref['registro_id']) ? (int)$ref['registro_id'] : null;
            $rexId = !empty($ref['registro_exemplar_id']) ? (int)$ref['registro_exemplar_id'] : null;
            if ($regId && !$this->valor('SELECT 1 FROM reorg_registros WHERE id = ? AND lote_id = ?', [$regId, $loteId])) {
                $regId = null;
            }
            if ($rexId && !$this->valor('SELECT 1 FROM reorg_registro_exemplares WHERE id = ? AND lote_id = ?', [$rexId, $loteId])) {
                $rexId = null;
            }
            $this->executar('INSERT INTO reorg_pendencias (lote_id, registro_id, registro_exemplar_id, tipo, grupo, sessao_id, nome, administrador_id, descricao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$loteId, $regId, $rexId, $tipo, $grupo, $sessao['id'] ?? null, $sessao['nome'] ?? null, $adminId, $descricao]);
            $id = (int)$this->pdo->lastInsertId();
            if ($rexId) {
                $this->executar('UPDATE reorg_registro_exemplares SET problema_aberto = 1 WHERE id = ?', [$rexId]);
            }
            $this->historico('pendencia_registrada', 'Pendência: ' . self::TIPOS_PENDENCIA[$tipo] . ' · ' . mb_substr($descricao, 0, 200), $loteId, $sessao, $adminId, ['pendencia_id' => $id]);
            if ($sessao !== null) {
                $chave = in_array($tipo, ['codigo_incorreto', 'codigo_livro_errado', 'codigo_ja_conferido'], true) ? null : 'pend:' . $loteId . ':' . $tipo . ':' . ($rexId ?? $regId ?? 0);
                $this->notificarAdmins(in_array($tipo, ['codigo_incorreto', 'codigo_livro_errado', 'codigo_ja_conferido'], true) ? 'codigo_divergente' : 'problema_informado',
                    self::nomeLote($lote) . ': ' . self::TIPOS_PENDENCIA[$tipo] . ' informado por ' . $sessao['nome'] . ' — ' . mb_substr($descricao, 0, 150), $loteId, (int)$lote['responsavel_id'], $chave);
            }
            return $id;
        });
    }

    public function pendencias(string $status = '', int $loteId = 0, string $tipo = ''): array
    {
        $w = [];
        $p = [];
        if ($status === 'abertas') {
            $w[] = "p.status <> 'resolvida'";
        } elseif ($status !== '') {
            $w[] = 'p.status = ?';
            $p[] = $status;
        }
        if ($loteId) {
            $w[] = 'p.lote_id = ?';
            $p[] = $loteId;
        }
        if ($tipo !== '') {
            $w[] = 'p.tipo = ?';
            $p[] = $tipo;
        }
        return $this->todas('SELECT p.*, l.nome AS lote_nome, r.numero AS registro_numero, e.codigo_individual, a.nome AS admin_nome, rv.nome AS resolvida_por_nome
                             FROM reorg_pendencias p JOIN reorg_lotes l ON l.id = p.lote_id LEFT JOIN reorg_registros r ON r.id = p.registro_id
                             LEFT JOIN reorg_registro_exemplares x ON x.id = p.registro_exemplar_id LEFT JOIN exemplares e ON e.id = x.exemplar_id
                             LEFT JOIN administradores a ON a.id = p.administrador_id LEFT JOIN administradores rv ON rv.id = p.resolvida_por'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY p.status = 'resolvida', p.id DESC LIMIT 500", $p);
    }

    public function atualizarPendencia(int $id, string $status, int $adminId, string $resolucao = ''): void
    {
        if (!in_array($status, ['aberta', 'em_analise', 'resolvida'], true)) {
            throw new AcervoException('Situação inválida.');
        }
        $resolucao = self::textoLimpo($resolucao, 1000);
        if ($status === 'resolvida' && $resolucao === null) {
            throw new AcervoException('Descreva a resolução administrativa.');
        }
        $this->transacao(function () use ($id, $status, $adminId, $resolucao) {
            $p = $this->linha('SELECT * FROM reorg_pendencias WHERE id = ? FOR UPDATE', [$id]);
            if (!$p) {
                throw new AcervoException('Pendência não encontrada.');
            }
            $this->executar('UPDATE reorg_pendencias SET status = ?, resolucao = COALESCE(?, resolucao), resolvida_por = ?, resolvida_em = ? WHERE id = ?',
                [$status, $resolucao, $status === 'resolvida' ? $adminId : null, $status === 'resolvida' ? date('Y-m-d H:i:s') : null, $id]);
            if ($p['registro_exemplar_id'] && $status === 'resolvida'
                && !$this->valor("SELECT 1 FROM reorg_pendencias WHERE registro_exemplar_id = ? AND status <> 'resolvida' AND id <> ?", [$p['registro_exemplar_id'], $id])) {
                $this->executar('UPDATE reorg_registro_exemplares SET problema_aberto = 0 WHERE id = ?', [$p['registro_exemplar_id']]);
            }
            $this->historico('pendencia_' . $status, 'Pendência nº ' . $id . ' (' . self::TIPOS_PENDENCIA[$p['tipo']] . '): ' . $status . ($resolucao ? ' · ' . $resolucao : ''), (int)$p['lote_id'], null, $adminId);
            if ($status === 'resolvida' && $p['sessao_id'] && $this->valor("SELECT 1 FROM reorg_sessoes WHERE id = ? AND status = 'ativa'", [$p['sessao_id']])) {
                $this->notificarSessao((int)$p['sessao_id'], 'pendencia_resolvida', 'O problema que você informou foi resolvido: ' . mb_substr((string)$resolucao, 0, 200), (int)$p['lote_id']);
            }
        });
    }

    // =================================================================
    // Notificações e histórico (administradores)
    // =================================================================

    public function notificacoesAdmin(int $adminId, bool $soNaoLidas = false, int $limite = 30): array
    {
        return $this->todas('SELECT n.*, l.nome AS lote_nome FROM reorg_notificacoes n LEFT JOIN reorg_lotes l ON l.id = n.lote_id WHERE n.administrador_id = ?'
            . ($soNaoLidas ? ' AND n.lida_em IS NULL' : '') . ' ORDER BY n.id DESC LIMIT ' . max(1, min(200, $limite)), [$adminId]);
    }

    public function naoLidasAdmin(int $adminId): int
    {
        return (int)$this->valor('SELECT COUNT(*) FROM reorg_notificacoes WHERE administrador_id = ? AND lida_em IS NULL', [$adminId]);
    }

    public function marcarLidasAdmin(int $adminId): void
    {
        $this->executar('UPDATE reorg_notificacoes SET lida_em = NOW() WHERE administrador_id = ? AND lida_em IS NULL', [$adminId]);
    }

    /** Histórico completo (só administradores). */
    public function historicoLista(array $f = [], int $limite = 500): array
    {
        $w = [];
        $p = [];
        foreach (['lote_id' => 'h.lote_id = ?', 'sessao_id' => 'h.sessao_id = ?', 'administrador_id' => 'h.administrador_id = ?'] as $c => $sql) {
            if (!empty($f[$c])) {
                $w[] = $sql;
                $p[] = (int)$f[$c];
            }
        }
        if (!empty($f['acao'])) {
            $w[] = 'h.acao = ?';
            $p[] = (string)$f['acao'];
        }
        if (!empty($f['grupo'])) {
            if ($f['grupo'] === 'grupo2') {
                $w[] = 'h.administrador_id IS NOT NULL';
            } else {
                $w[] = 'h.sessao_id IN (SELECT id FROM reorg_sessoes WHERE equipe = ?)';
                $p[] = (string)$f['grupo'];
            }
        }
        if (!empty($f['de']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['de'])) {
            $w[] = 'h.criado_em >= ?';
            $p[] = $f['de'] . ' 00:00:00';
        }
        if (!empty($f['ate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['ate'])) {
            $w[] = 'h.criado_em <= ?';
            $p[] = $f['ate'] . ' 23:59:59';
        }
        if (!empty($f['participante'])) {
            $w[] = 'h.nome LIKE ?';
            $p[] = self::like((string)$f['participante']);
        }
        if (!empty($f['q'])) {
            $w[] = '(h.descricao LIKE ? OR h.nome LIKE ?)';
            $p[] = self::like((string)$f['q']);
            $p[] = self::like((string)$f['q']);
        }
        return $this->todas('SELECT h.*, a.nome AS admin_nome, l.nome AS lote_nome FROM reorg_historico h LEFT JOIN administradores a ON a.id = h.administrador_id
                             LEFT JOIN reorg_lotes l ON l.id = h.lote_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY h.id DESC LIMIT ' . max(1, min(100000, $limite)), $p);
    }
}
