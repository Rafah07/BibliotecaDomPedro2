<?php

namespace Biblioteca\Reorganizacao;

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Support\CodigoBdp;
use PDO;

/**
 * Grupo 3 (carimbação e identificação) e Grupo 4 (conferência do código BDP e organização).
 *
 * Grupo 3: planilha interativa dos exemplares cadastrados do lote. "Estou com este livro" reserva o
 * exemplar (dois participantes não pegam o mesmo); "Carimbado" confirma, sem redigitar o código
 * (a conferência é do Grupo 4). A confirmação vai também para o exemplar permanente
 * (ExemplarService::marcarCarimbado). Os carimbados seguem ao Grupo 4 só quando encaminhados.
 *
 * Grupo 4: digita o código BDP escrito no livro. O sistema confere se existe, se é do lote autorizado,
 * se foi cadastrado e carimbado, se foi encaminhado e se já foi conferido; mostra os dados para a
 * pessoa comparar com o livro em mãos (o código existir não prova que foi escrito no livro certo).
 * Confirma "código conferido", "informações conferidas" e, depois, "organizado na estante".
 */
final class Etapas
{
    use Apoio;

    public function __construct(private PDO $pdo, private array $config, private ExemplarService $exemplares, private Reorganizacao $reorg)
    {
    }

    /** Planilha da carimbação. $filtro: pendentes | carimbados | problemas | todos */
    public function planilhaCarimbacao(int $loteId, string $filtro = 'pendentes'): array
    {
        $w = match ($filtro) {
            'pendentes' => 'x.carimbado_em IS NULL',
            'carimbados' => 'x.carimbado_em IS NOT NULL',
            'problemas' => 'x.problema_aberto = 1',
            default => '1 = 1',
        };
        return $this->todas("SELECT x.id, x.ordem, x.estado_conservacao, x.carimbado_em, x.carimbado_nome, x.encaminhado_g4_em, x.problema_aberto, x.reservado_sessao_id, x.reservado_ate,
                                    e.codigo_individual, e.lote_ordem, l.titulo, l.editora, l.edicao, r.numero AS registro_numero, s.nome AS reservado_nome,
                                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                             FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id JOIN livros l ON l.id = e.livro_id
                             JOIN reorg_registros r ON r.id = x.registro_id LEFT JOIN reorg_sessoes s ON s.id = x.reservado_sessao_id AND x.reservado_ate > NOW()
                             WHERE x.lote_id = ? AND x.removido_em IS NULL AND $w ORDER BY e.lote_ordem, x.id", [$loteId]);
    }

    /** "Estou com este livro": reserva temporária do exemplar (Grupo 3 ou 4). */
    public function reservar(array $sessao, int $rexId, string $equipe): array
    {
        return $this->transacao(function () use ($sessao, $rexId, $equipe) {
            $x = $this->linha('SELECT * FROM reorg_registro_exemplares WHERE id = ? FOR UPDATE', [$rexId]);
            if (!$x || $x['exemplar_id'] === null) {
                throw new AcervoException('Exemplar não encontrado.');
            }
            $this->exigirAcesso($sessao, (int)$x['lote_id'], $equipe);
            if ($equipe === 'grupo3' && $x['carimbado_em'] !== null) {
                throw new AcervoException('Este exemplar já foi carimbado por ' . $x['carimbado_nome'] . '.');
            }
            if ($x['reservado_sessao_id'] && (int)$x['reservado_sessao_id'] !== (int)$sessao['id'] && strtotime((string)$x['reservado_ate']) > time()) {
                $nome = $this->valor('SELECT nome FROM reorg_sessoes WHERE id = ?', [$x['reservado_sessao_id']]);
                throw new AcervoException('Outra pessoa (' . $nome . ') está com este exemplar agora. Escolha outro.');
            }
            // Uma reserva por vez: a anterior desta sessão é liberada.
            $this->executar('UPDATE reorg_registro_exemplares SET reservado_sessao_id = NULL, reservado_ate = NULL WHERE reservado_sessao_id = ? AND id <> ?', [$sessao['id'], $rexId]);
            $this->executar('UPDATE reorg_registro_exemplares SET reservado_sessao_id = ?, reservado_ate = NOW() + INTERVAL ? SECOND WHERE id = ?', [$sessao['id'], (int)$this->config['reserva_edicao'], $rexId]);
            $this->executar("UPDATE reorg_sessoes SET situacao = 'trabalhando' WHERE id = ?", [$sessao['id']]);
            return ['ok' => true];
        });
    }

    public function liberarReserva(array $sessao, int $rexId): void
    {
        $this->executar('UPDATE reorg_registro_exemplares SET reservado_sessao_id = NULL, reservado_ate = NULL WHERE id = ? AND reservado_sessao_id = ?', [$rexId, $sessao['id']]);
    }

    /** "Carimbado": o código foi escrito no livro. Repetir (ou outra pessoa ao mesmo tempo) não duplica. */
    public function marcarCarimbado(array $sessao, int $rexId): string
    {
        return $this->transacao(function () use ($sessao, $rexId) {
            $x = $this->linha('SELECT x.*, e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.id = ? FOR UPDATE', [$rexId]);
            if (!$x) {
                throw new AcervoException('Exemplar não encontrado ou ainda sem código BDP.');
            }
            $lote = $this->exigirAcesso($sessao, (int)$x['lote_id'], 'grupo3');
            if ($x['carimbado_em'] !== null) {
                throw new AcervoException($x['codigo_individual'] . ' já foi marcado como carimbado por ' . $x['carimbado_nome'] . '.');
            }
            if ($x['reservado_sessao_id'] && (int)$x['reservado_sessao_id'] !== (int)$sessao['id'] && strtotime((string)$x['reservado_ate']) > time()) {
                throw new AcervoException('Outra pessoa está com este exemplar agora.');
            }
            $this->executar('UPDATE reorg_registro_exemplares SET carimbado_em = NOW(), carimbado_sessao_id = ?, carimbado_nome = ?, reservado_sessao_id = NULL, reservado_ate = NULL WHERE id = ?',
                [$sessao['id'], $sessao['nome'], $rexId]);
            $this->exemplares->marcarCarimbado([(int)$x['exemplar_id']], (int)$lote['responsavel_id'], 'Reorganização: carimbado por ' . $sessao['nome'] . ' (' . self::rotuloSessao($sessao['id']) . ')');
            $this->historico('carimbacao', $x['codigo_individual'] . ' carimbado por ' . $sessao['nome'], (int)$x['lote_id'], $sessao);
            $this->etapaConcluida($lote, 'grupo3');
            return $x['codigo_individual'];
        });
    }

    public function previaEncaminhamentoG4(int $loteId): array
    {
        $c = $this->reorg->contadores($loteId);
        return ['exemplares' => $c['carimbados_nao_encaminhados'], 'restantes' => $c['aguardando_carimbacao'] + max(0, $c['prevista'] - $c['codigos'])];
    }

    /** Encaminha ao Grupo 4 os exemplares já carimbados (parcial: os demais continuam no Grupo 3). */
    public function encaminharG4(int $loteId, ?array $sessao = null, ?int $adminId = null): array
    {
        return $this->transacao(function () use ($loteId, $sessao, $adminId) {
            if ($sessao !== null) {
                $this->exigirAcesso($sessao, $loteId, 'grupo3');
            }
            $lote = $this->bloquearLote($loteId);
            if ($adminId !== null) {
                $this->exigirAdminDoLote($lote, $adminId);
            }
            $n = $this->executar('UPDATE reorg_registro_exemplares SET encaminhado_g4_em = NOW() WHERE lote_id = ? AND carimbado_em IS NOT NULL AND encaminhado_g4_em IS NULL AND removido_em IS NULL', [$loteId]);
            $previa = $this->previaEncaminhamentoG4($loteId);
            if ($n) {
                $this->historico('encaminhamento_g4', 'Encaminhados ao Grupo 4: ' . $n . ' exemplar(es) carimbado(s); ' . $previa['restantes'] . ' ainda sem carimbo', $loteId, $sessao, $adminId, ['exemplares' => $n]);
                $this->reorg->notificarParticipantesLote($loteId, 'etapa_liberada', self::nomeLote($lote) . ': ' . $n . ' exemplar(es) liberados para a conferência.', 'grupo4', 'g4:' . $loteId);
            }
            return ['exemplares' => $n, 'restantes' => $previa['restantes']];
        });
    }

    /**
     * Grupo 4: confere o código digitado. Nunca altera nada.
     * @return array{situacao: string, mensagem: string, exemplar?: array}
     *   situacao: valido | inexistente | formato | outro_lote | fora_reorganizacao | sem_carimbo | nao_encaminhado | ja_conferido
     */
    public function conferirCodigo(array $sessao, int $loteId, string $texto): array
    {
        $this->exigirAcesso($sessao, $loteId, 'grupo4', false);
        $codigo = CodigoBdp::normalizar($texto);
        if ($codigo === null) {
            return ['situacao' => 'formato', 'mensagem' => CodigoBdp::mensagemInvalido($texto)];
        }
        $ex = $this->linha('SELECT id, codigo_individual FROM exemplares WHERE codigo_individual = ?', [$codigo]);
        if (!$ex) {
            return ['situacao' => 'inexistente', 'mensagem' => 'O código ' . $codigo . ' não existe no sistema. Confira o que está escrito no livro.'];
        }
        $x = $this->linha('SELECT x.*, r.status AS registro_status FROM reorg_registro_exemplares x JOIN reorg_registros r ON r.id = x.registro_id WHERE x.exemplar_id = ?', [$ex['id']]);
        if (!$x) {
            return ['situacao' => 'fora_reorganizacao', 'mensagem' => 'O código ' . $codigo . ' é de um exemplar que não faz parte da reorganização. Separe o livro e informe o administrador.'];
        }
        if ((int)$x['lote_id'] !== $loteId) {
            return ['situacao' => 'outro_lote', 'mensagem' => 'O código ' . $codigo . ' pertence a outro lote, não ao lote em que você está trabalhando. Separe o livro e informe o administrador.'];
        }
        $dados = $this->dadosExemplar((int)$x['id']);
        if ($x['carimbado_em'] === null) {
            return ['situacao' => 'sem_carimbo', 'mensagem' => 'A carimbação de ' . $codigo . ' ainda não foi confirmada pelo Grupo 3.', 'exemplar' => $dados];
        }
        if ($x['encaminhado_g4_em'] === null) {
            return ['situacao' => 'nao_encaminhado', 'mensagem' => $codigo . ' foi carimbado, mas ainda não foi encaminhado para a conferência.', 'exemplar' => $dados];
        }
        if ($x['conferido_em'] !== null) {
            return ['situacao' => 'ja_conferido', 'mensagem' => $codigo . ' já foi conferido por ' . $x['conferido_nome'] . ' em ' . date('d/m/Y H:i', strtotime($x['conferido_em'])) . '. Se você está com outro livro com o mesmo código, informe o problema.', 'exemplar' => $dados];
        }
        return ['situacao' => 'valido', 'mensagem' => 'Código encontrado. Compare as informações abaixo com o livro em mãos.', 'exemplar' => $dados];
    }

    /** Dados do exemplar para o Grupo 4 (nada de alunos ou empréstimos). */
    public function dadosExemplar(int $rexId): ?array
    {
        return $this->linha("SELECT x.id, x.lote_id, x.estado_conservacao, x.carimbado_em, x.carimbado_nome, x.conferido_em, x.conferido_nome, x.organizado_em, x.organizado_nome, x.problema_aberto,
                                    e.codigo_individual, l.titulo, l.subtitulo, l.editora, l.edicao, l.ano_publicacao,
                                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                    COALESCE((SELECT COALESCE(c.cdd_aprovada, c.cdd_proposta) FROM classificacoes_cdd c WHERE c.livro_id = l.id ORDER BY c.id DESC LIMIT 1), r.cdd) AS cdd,
                                    CONCAT_WS(' · ', lc.setor, lc.estante, lc.prateleira) AS localizacao
                             FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id JOIN livros l ON l.id = e.livro_id
                             JOIN reorg_registros r ON r.id = x.registro_id LEFT JOIN localizacoes lc ON lc.id = e.localizacao_id WHERE x.id = ?", [$rexId]);
    }

    /**
     * Confirma a conferência (código e informações) e, se marcado, a organização na estante.
     * $localizacaoId (opcional): prateleira cadastrada na Organização Física. Com ela, o exemplar
     * recebe a localização definitiva (e é liberado para empréstimo pela regra permanente).
     */
    public function confirmarConferencia(array $sessao, int $rexId, bool $codigoOk, bool $infoOk, bool $organizado = false, ?int $localizacaoId = null): array
    {
        if (!$codigoOk || !$infoOk) {
            throw new AcervoException('Marque “Código BDP conferido” e “Informações do exemplar conferidas” — ou informe o problema, se algo não corresponde ao livro.');
        }
        return $this->transacao(function () use ($sessao, $rexId, $organizado, $localizacaoId) {
            $x = $this->linha('SELECT x.*, e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.id = ? FOR UPDATE', [$rexId]);
            if (!$x) {
                throw new AcervoException('Exemplar não encontrado.');
            }
            $lote = $this->exigirAcesso($sessao, (int)$x['lote_id'], 'grupo4');
            if ($x['carimbado_em'] === null || $x['encaminhado_g4_em'] === null) {
                throw new AcervoException('Este exemplar ainda não foi liberado para a conferência.');
            }
            if ($x['conferido_em'] !== null) {
                throw new AcervoException($x['codigo_individual'] . ' já foi conferido por ' . $x['conferido_nome'] . '.');
            }
            $this->executar('UPDATE reorg_registro_exemplares SET conferido_em = NOW(), conferido_sessao_id = ?, conferido_nome = ?, reservado_sessao_id = NULL, reservado_ate = NULL WHERE id = ?', [$sessao['id'], $sessao['nome'], $rexId]);
            $this->exemplares->movimentacao((int)$x['exemplar_id'], 'inventario', 'Reorganização: código BDP e informações conferidos por ' . $sessao['nome'] . ' (' . self::rotuloSessao($sessao['id']) . ')', (int)$lote['responsavel_id']);
            $this->historico('conferencia_codigo', $x['codigo_individual'] . ' conferido por ' . $sessao['nome'], (int)$x['lote_id'], $sessao);
            $r = ['codigo' => $x['codigo_individual'], 'organizado' => false];
            if ($organizado) {
                $this->organizarInterno($sessao, $lote, $x, $localizacaoId);
                $r['organizado'] = true;
            }
            $this->etapaConcluida($lote, 'grupo4');
            return $r;
        });
    }

    /** "Exemplar organizado na estante" (para os já conferidos). */
    public function organizar(array $sessao, int $rexId, ?int $localizacaoId = null): string
    {
        return $this->transacao(function () use ($sessao, $rexId, $localizacaoId) {
            $x = $this->linha('SELECT x.*, e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.id = ? FOR UPDATE', [$rexId]);
            if (!$x) {
                throw new AcervoException('Exemplar não encontrado.');
            }
            $lote = $this->exigirAcesso($sessao, (int)$x['lote_id'], 'grupo4');
            if ($x['conferido_em'] === null) {
                throw new AcervoException('Confira o código antes de confirmar a organização.');
            }
            if ($x['organizado_em'] !== null) {
                throw new AcervoException($x['codigo_individual'] . ' já foi organizado por ' . $x['organizado_nome'] . '.');
            }
            $this->organizarInterno($sessao, $lote, $x, $localizacaoId);
            $this->etapaConcluida($lote, 'grupo4');
            return $x['codigo_individual'];
        });
    }

    private function organizarInterno(array $sessao, array $lote, array $x, ?int $localizacaoId): void
    {
        if ($localizacaoId) {
            $local = $this->linha('SELECT id, estante_id FROM localizacoes WHERE id = ? AND ativa = 1', [$localizacaoId]);
            if (!$local || $local['estante_id'] === null) {
                throw new AcervoException('Escolha uma prateleira cadastrada na Organização Física (ou deixe sem prateleira).');
            }
            $this->exemplares->transferir((int)$x['exemplar_id'], $localizacaoId, (int)$lote['responsavel_id'], 'organização na reorganização do acervo (' . $sessao['nome'] . ')');
        }
        $this->executar('UPDATE reorg_registro_exemplares SET organizado_em = NOW(), organizado_sessao_id = ?, organizado_nome = ?, localizacao_id = ? WHERE id = ?',
            [$sessao['id'], $sessao['nome'], $localizacaoId ?: null, $x['id']]);
        $this->historico('organizacao', $x['codigo_individual'] . ' organizado na estante por ' . $sessao['nome'], (int)$x['lote_id'], $sessao, null, ['localizacao_id' => $localizacaoId]);
    }

    /** Conferidos e ainda não organizados (lista do Grupo 4). */
    public function aguardandoOrganizacao(int $loteId): array
    {
        return $this->todas('SELECT x.id, e.codigo_individual, l.titulo, x.conferido_nome, x.conferido_em FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id
                             JOIN livros l ON l.id = e.livro_id WHERE x.lote_id = ? AND x.conferido_em IS NOT NULL AND x.organizado_em IS NULL ORDER BY e.lote_ordem', [$loteId]);
    }

    /** Exemplares do Grupo 4 no lote. $filtro: aguardando | conferidos | organizados | todos */
    public function exemplaresG4(int $loteId, string $filtro = 'aguardando'): array
    {
        $w = match ($filtro) {
            'aguardando' => 'x.conferido_em IS NULL',
            'conferidos' => 'x.conferido_em IS NOT NULL AND x.organizado_em IS NULL',
            'organizados' => 'x.organizado_em IS NOT NULL',
            default => '1 = 1',
        };
        return $this->todas("SELECT x.id, x.estado_conservacao, x.encaminhado_g4_em, x.conferido_em, x.conferido_nome, x.organizado_em, x.organizado_nome, x.problema_aberto,
                                    e.codigo_individual, l.titulo, l.editora, l.edicao, CONCAT_WS(' · ', lc.setor, lc.estante, lc.prateleira) AS local_rotulo
                             FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id JOIN livros l ON l.id = e.livro_id
                             LEFT JOIN localizacoes lc ON lc.id = x.localizacao_id
                             WHERE x.lote_id = ? AND x.removido_em IS NULL AND x.encaminhado_g4_em IS NOT NULL AND $w ORDER BY e.lote_ordem, x.id", [$loteId]);
    }

    /** Prateleiras ativas (para o Grupo 4 escolher, quando houver). */
    public function prateleiras(): array
    {
        return $this->todas("SELECT id, CONCAT_WS(' · ', setor, estante, prateleira) AS rotulo FROM localizacoes WHERE ativa = 1 AND estante_id IS NOT NULL ORDER BY setor, estante, ordem, prateleira LIMIT 2000");
    }

    /** Avisa os administradores quando uma etapa não tem mais nada pendente no lote (sem repetir). */
    private function etapaConcluida(array $lote, string $equipe): void
    {
        $c = $this->reorg->contadores((int)$lote['id']);
        $fim = match ($equipe) {
            'grupo3' => $c['codigos'] > 0 && $c['aguardando_carimbacao'] === 0 && $c['codigos'] >= $c['prevista'],
            'grupo4' => $c['codigos'] > 0 && $c['organizados'] >= $c['prevista'],
            default => false,
        };
        if ($fim) {
            $this->notificarAdmins('etapa_concluida', self::nomeLote($lote) . ': ' . self::EQUIPES[$equipe] . ' concluído para todos os exemplares.', (int)$lote['id'], (int)$lote['responsavel_id'], 'etapa_fim:' . $lote['id'] . ':' . $equipe);
            $this->historico('etapa_concluida', self::nomeLote($lote) . ': ' . self::EQUIPES[$equipe] . ' concluído', (int)$lote['id']);
        }
    }
}
