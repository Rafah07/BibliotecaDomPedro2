<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Isbn;
use PDO;

/**
 * Equipes da organização inicial por links sem login (migração 210).
 *
 *   * Links: o administrador gera um link por equipe ("coleta" ou "carimbagem") para um lote gerenciado,
 *     com data de validade. O token aparece só uma vez; o banco guarda o hash SHA-256. Expirado ou
 *     revogado, o link para de funcionar na hora. Tudo o que é feito pelo link fica em nome do
 *     administrador que o criou (as tabelas de exemplares exigem uma conta) e do nome digitado.
 *   * Pré-cadastro (equipe de coleta): cada livro do lote, com a quantidade de exemplares. A quantidade
 *     ocupa vagas do lote na hora (com a linha do lote bloqueada): pré-cadastros pendentes + exemplares
 *     já registrados nunca passam da capacidade. Nada entra no acervo sem aprovação.
 *   * Revisão (administradores): vincularObra() guarda a obra escolhida ou criada; aprovar() cria os
 *     exemplares com códigos BDP no lote (ExemplarService, a mesma regra de todos os cadastros).
 *   * Carimbagem (equipe de carimbagem): marca exemplares do lote como carimbados e conclui a etapa
 *     (LoteService::confirmarCarimbagem, que exige todos carimbados).
 *   * Pausa: qualquer etapa pode ser pausada com motivo; "onde parou" é gravado. Enquanto pausada, a
 *     etapa não recebe alterações até alguém retomar.
 */
final class ColetaService
{
    use ConsultasPdo;

    public const TIPOS = ['coleta' => 'Coleta (pré-cadastro dos livros)', 'carimbagem' => 'Carimbagem (Planilha BDP)'];
    public const ETAPAS = ['coleta' => 'Coleta', 'revisao' => 'Revisão', 'carimbagem' => 'Carimbagem'];
    public const VALIDADE_MAXIMA_DIAS = 365;

    public function __construct(private PDO $pdo, private ?ExemplarService $exemplares = null, private ?LoteService $lotes = null)
    {
        $this->exemplares ??= new ExemplarService($pdo);
        $this->lotes ??= new LoteService($pdo, $this->exemplares);
    }

    // =================================================================
    // Links
    // =================================================================

    /**
     * Cria o link de uma equipe. $expira = 'AAAA-MM-DD' (vale até 23:59 desse dia).
     * @return array{id: int, token: string} o token só é devolvido aqui
     */
    public function criarLink(int $loteId, string $tipo, string $equipe, string $expira, int $administradorId): array
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw new AcervoException('Escolha para que serve o link (coleta ou carimbagem).');
        }
        $equipe = self::textoLimpo($equipe, 80) ?? '';
        if (mb_strlen($equipe) < 2) {
            throw new AcervoException('Informe o nome da equipe (ex.: Equipe 1).');
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $expira);
        if (!$data || $data->format('Y-m-d') !== $expira) {
            throw new AcervoException('Informe a data de validade do link.');
        }
        $hoje = new \DateTimeImmutable('today');
        if ($data < $hoje) {
            throw new AcervoException('A validade precisa ser hoje ou uma data futura.');
        }
        if ($data > $hoje->modify('+' . self::VALIDADE_MAXIMA_DIAS . ' days')) {
            throw new AcervoException('A validade pode ser de no máximo ' . self::VALIDADE_MAXIMA_DIAS . ' dias.');
        }
        return $this->transacao(function () use ($loteId, $tipo, $equipe, $data, $administradorId) {
            $lote = $this->bloquearLote($loteId);
            if ($lote['modo'] !== 'gerenciado' || $lote['capacidade'] === null) {
                throw new AcervoException('Os links das equipes só existem para lotes abertos pela Conferência, com capacidade definida.');
            }
            if ($lote['status'] === 'encerrado') {
                throw new AcervoException(self::nome($lote) . ' está encerrado.');
            }
            $token = bin2hex(random_bytes(24));
            $this->executar('INSERT INTO coleta_links (lote_id, tipo, equipe, token_hash, expira_em, criado_por) VALUES (?, ?, ?, ?, ?, ?)',
                [$loteId, $tipo, $equipe, hash('sha256', $token), $data->format('Y-m-d') . ' 23:59:59', $administradorId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->exemplares->eventoLote($loteId, null, 'coleta_link_criado', 'Link de ' . ($tipo === 'coleta' ? 'coleta' : 'carimbagem') . ' criado para a ' . $equipe . ' (válido até ' . $data->format('d/m/Y') . ')',
                $administradorId, ['link_id' => $id, 'tipo' => $tipo]);
            $this->registrarAcao($administradorId, 'coleta.link_criar', 'lote', $loteId, ['link_id' => $id, 'tipo' => $tipo, 'equipe' => $equipe, 'expira' => $data->format('Y-m-d')]);
            return ['id' => $id, 'token' => $token];
        });
    }

    public function revogarLink(int $linkId, int $administradorId): void
    {
        $this->transacao(function () use ($linkId, $administradorId) {
            $link = $this->linha('SELECT * FROM coleta_links WHERE id = ? FOR UPDATE', [$linkId]);
            if (!$link) {
                throw new AcervoException('Link não encontrado.');
            }
            if ($link['revogado_em'] !== null) {
                throw new AcervoException('Este link já estava desativado.');
            }
            $this->executar('UPDATE coleta_links SET revogado_em = NOW(), revogado_por = ? WHERE id = ?', [$administradorId, $linkId]);
            $this->exemplares->eventoLote((int)$link['lote_id'], null, 'coleta_link_revogado', 'Link da ' . $link['equipe'] . ' desativado', $administradorId, ['link_id' => $linkId]);
            $this->registrarAcao($administradorId, 'coleta.link_revogar', 'lote', (int)$link['lote_id'], ['link_id' => $linkId]);
        });
    }

    /** Links do lote, com a situação calculada (ativo, expirado, desativado). */
    public function links(int $loteId): array
    {
        $agora = date('Y-m-d H:i:s');
        return array_map(function (array $l) use ($agora) {
            $l['situacao'] = $l['revogado_em'] !== null ? 'desativado' : ($l['expira_em'] < $agora ? 'expirado' : 'ativo');
            return $l;
        }, $this->todas('SELECT l.*, a.nome AS criado_por_nome FROM coleta_links l JOIN administradores a ON a.id = l.criado_por WHERE l.lote_id = ? ORDER BY l.id DESC', [$loteId]));
    }

    /**
     * Abre o link pelo token (página pública). Lança AcervoException com uma mensagem amigável quando
     * o link não existe, expirou ou foi desativado. Devolve o link com os dados do lote.
     */
    public function abrirPorToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new AcervoException('Link inválido. Confira se o endereço foi copiado inteiro.');
        }
        $link = $this->linha('SELECT * FROM coleta_links WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$link) {
            throw new AcervoException('Link inválido. Confira se o endereço foi copiado inteiro.');
        }
        if ($link['revogado_em'] !== null) {
            throw new AcervoException('Este link foi desativado pela administração da biblioteca.');
        }
        if ($link['expira_em'] < date('Y-m-d H:i:s')) {
            throw new AcervoException('Este link expirou em ' . date('d/m/Y', strtotime($link['expira_em'])) . '. Peça um novo link à administração da biblioteca.');
        }
        $lote = $this->linha('SELECT * FROM cadastro_lotes WHERE id = ?', [(int)$link['lote_id']]);
        if (!$lote) {
            throw new AcervoException('Lote não encontrado.');
        }
        $this->executar('UPDATE coleta_links SET ultimo_uso_em = NOW() WHERE id = ?', [(int)$link['id']]);
        $link['id'] = (int)$link['id'];
        $link['lote_id'] = (int)$link['lote_id'];
        $link['criado_por'] = (int)$link['criado_por'];
        $link['lote'] = $lote;
        return $link;
    }

    // =================================================================
    // Vagas e pré-cadastro
    // =================================================================

    /** @return array{capacidade: int, registrados: int, reservados: int, restantes: int, itens: int} */
    public function vagas(int $loteId): array
    {
        $lote = $this->linha('SELECT capacidade, total FROM cadastro_lotes WHERE id = ?', [$loteId]);
        $p = $this->linha("SELECT COUNT(*) AS itens, COALESCE(SUM(quantidade), 0) AS reservados FROM coleta_itens WHERE lote_id = ? AND status = 'pendente'", [$loteId]);
        $cap = (int)($lote['capacidade'] ?? 0);
        $registrados = (int)($lote['total'] ?? 0);
        $reservados = (int)$p['reservados'];
        return ['capacidade' => $cap, 'registrados' => $registrados, 'reservados' => $reservados, 'restantes' => max(0, $cap - $registrados - $reservados), 'itens' => (int)$p['itens']];
    }

    /**
     * Valida os campos do pré-cadastro. Obrigatórios: título, autor (ou "sem autor"), editora (ou
     * "sem editora"), ano (ou "sem data"), tem ISBN? (e o ISBN, se tiver), tem CDD? (e a CDD, se tiver)
     * e quantidade. Devolve [dados limpos, erros por campo].
     */
    public static function validar(array $p): array
    {
        $txt = fn (string $c, int $max) => self::textoLimpo(is_string($p[$c] ?? null) ? $p[$c] : '', $max);
        $sim = fn (string $c) => ($p[$c] ?? '') === '1';
        $d = [
            'titulo' => $txt('titulo', 255),
            'subtitulo' => $txt('subtitulo', 255),
            'sem_autor' => $sim('sem_autor'),
            'autor' => $txt('autor', 500),
            'sem_editora' => $sim('sem_editora'),
            'editora' => $txt('editora', 150),
            'sem_ano' => $sim('sem_ano'),
            'ano' => $txt('ano', 4),
            'tem_isbn' => $p['tem_isbn'] ?? '',
            'isbn' => $txt('isbn', 20),
            'tem_cdd' => $p['tem_cdd'] ?? '',
            'cdd' => $txt('cdd', 30),
            'edicao' => $txt('edicao', 60),
            'assunto' => $txt('assunto', 255),
            'cod_autor' => $txt('cod_autor', 30),
            'quantidade' => $txt('quantidade', 3) ?? '1',
            'observacoes' => $txt('observacoes', 500),
        ];
        $e = [];
        if ($d['titulo'] === null) {
            $e['titulo'] = 'Informe o título.';
        }
        if ($d['sem_autor']) {
            $d['autor'] = null;
        } elseif ($d['autor'] === null) {
            $e['autor'] = 'Informe o autor ou marque “Sem autor indicado”.';
        }
        if ($d['sem_editora']) {
            $d['editora'] = null;
        } elseif ($d['editora'] === null) {
            $e['editora'] = 'Informe a editora ou marque “Sem editora indicada”.';
        }
        $anoMax = (int)date('Y') + 1;
        if ($d['sem_ano']) {
            $d['ano'] = null;
        } elseif ($d['ano'] === null) {
            $e['ano'] = 'Informe o ano ou marque “Sem data no livro”.';
        } elseif (!ctype_digit($d['ano']) || (int)$d['ano'] < 1450 || (int)$d['ano'] > $anoMax) {
            $e['ano'] = "Informe um ano entre 1450 e $anoMax.";
        } else {
            $d['ano'] = (int)$d['ano'];
        }
        if (!in_array($d['tem_isbn'], ['0', '1'], true)) {
            $e['tem_isbn'] = 'Responda se o livro tem ISBN.';
        } elseif ($d['tem_isbn'] === '1') {
            $isbn = Isbn::normalizar((string)$d['isbn']);
            if ($d['isbn'] === null) {
                $e['isbn'] = 'Digite o ISBN (só os números).';
            } elseif (!preg_match('/^[\d\-\s.Xx]+$/', (string)$d['isbn']) || !Isbn::valido($isbn)) {
                $e['isbn'] = strlen($isbn) === 10 || strlen($isbn) === 13
                    ? 'O ISBN não confere (o último dígito não bate). Leia de novo no livro.'
                    : 'O ISBN tem 10 ou 13 dígitos. Confira no livro.';
            } else {
                $d['isbn'] = $isbn;
            }
        } else {
            $d['isbn'] = null;
        }
        if (!in_array($d['tem_cdd'], ['0', '1'], true)) {
            $e['tem_cdd'] = 'Responda se o livro tem CDD.';
        } elseif ($d['tem_cdd'] === '1') {
            $cdd = str_replace(',', '.', preg_replace('/\s+/', '', (string)$d['cdd']));
            if ($cdd === '') {
                $e['cdd'] = 'Digite a CDD (ex.: 869.3).';
            } elseif (!preg_match('/^\d{3}(\.\d{1,10})?$/', $cdd)) {
                $e['cdd'] = 'A CDD começa com 3 números (ex.: 869 ou 869.3).';
            } else {
                $d['cdd'] = $cdd;
            }
        } else {
            $d['cdd'] = null;
        }
        if (!ctype_digit((string)$d['quantidade']) || (int)$d['quantidade'] < 1 || (int)$d['quantidade'] > ExemplarService::LIMITE_EM_QUANTIDADE) {
            $e['quantidade'] = 'Informe de 1 a ' . ExemplarService::LIMITE_EM_QUANTIDADE . ' exemplares.';
        } else {
            $d['quantidade'] = (int)$d['quantidade'];
        }
        $d['tem_isbn'] = $d['tem_isbn'] === '1';
        $d['tem_cdd'] = $d['tem_cdd'] === '1';
        return [$d, $e];
    }

    /**
     * Grava (novo ou correção de um pendente) o pré-cadastro feito pelo link de coleta.
     * @return array{id: int, numero: int, avisos: list<string>}
     */
    public function salvarItem(array $link, string $nome, array $dados, ?int $itemId = null): array
    {
        $nome = self::nomePessoa($nome);
        if ($link['tipo'] !== 'coleta') {
            throw new AcervoException('Este link não serve para cadastrar livros.');
        }
        return $this->transacao(function () use ($link, $nome, $dados, $itemId) {
            $lote = $this->bloquearLote($link['lote_id']);
            if ($lote['status'] !== 'aberto') {
                throw new AcervoException(self::nome($lote) . ' não recebe mais livros (' . ExemplarService::rotuloStatusLote($lote['status']) . ').');
            }
            $this->exigirSemPausa($link['lote_id'], 'coleta');
            $atual = null;
            if ($itemId !== null) {
                $atual = $this->linha('SELECT * FROM coleta_itens WHERE id = ? AND lote_id = ? FOR UPDATE', [$itemId, $link['lote_id']]);
                if (!$atual) {
                    throw new AcervoException('Pré-cadastro não encontrado neste lote.');
                }
                if ($atual['status'] !== 'pendente') {
                    throw new AcervoException('Este livro já foi ' . ($atual['status'] === 'aprovado' ? 'aprovado' : 'retirado') . ' e não pode mais ser alterado por aqui.');
                }
            }
            $vagas = $this->vagas($link['lote_id']);
            $disponivel = $vagas['restantes'] + ($atual ? (int)$atual['quantidade'] : 0);
            if ($dados['quantidade'] > $disponivel) {
                throw new AcervoException($disponivel <= 0
                    ? self::nome($lote) . ' já está completo: não há vagas para mais exemplares.'
                    : self::nome($lote) . ' tem só ' . $disponivel . ' vaga(s). Informe no máximo ' . $disponivel . ' exemplar(es) ou fale com a administração.');
            }
            $campos = ['titulo', 'subtitulo', 'autor', 'sem_autor', 'editora', 'sem_editora', 'ano', 'sem_ano', 'tem_isbn', 'isbn', 'tem_cdd', 'cdd', 'edicao', 'assunto', 'cod_autor', 'quantidade', 'observacoes'];
            $valores = array_map(fn ($c) => is_bool($dados[$c]) ? (int)$dados[$c] : $dados[$c], array_combine($campos, $campos));
            if ($atual) {
                $this->executar('UPDATE coleta_itens SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $campos)) . ', coletado_por = ? WHERE id = ?',
                    [...array_values($valores), $nome, $atual['id']]);
                $id = (int)$atual['id'];
                $numero = (int)$atual['numero'];
            } else {
                $numero = (int)$this->valor('SELECT COALESCE(MAX(numero), 0) + 1 FROM coleta_itens WHERE lote_id = ?', [$link['lote_id']]);
                $this->executar('INSERT INTO coleta_itens (lote_id, link_id, numero, coletado_por, ' . implode(', ', $campos) . ') VALUES (?, ?, ?, ?' . str_repeat(', ?', count($campos)) . ')',
                    [$link['lote_id'], $link['id'], $numero, $nome, ...array_values($valores)]);
                $id = (int)$this->pdo->lastInsertId();
            }
            return ['id' => $id, 'numero' => $numero, 'avisos' => $this->avisos($link['lote_id'], $id, $dados)];
        });
    }

    /** Retira um pré-cadastro pendente (feito por engano). A linha fica, como "recusado", com o motivo. */
    public function retirarItem(array $link, string $nome, int $itemId): void
    {
        $nome = self::nomePessoa($nome);
        $this->transacao(function () use ($link, $nome, $itemId) {
            $this->bloquearLote($link['lote_id']);
            $this->exigirSemPausa($link['lote_id'], 'coleta');
            $n = $this->executar("UPDATE coleta_itens SET status = 'recusado', motivo_recusa = ? WHERE id = ? AND lote_id = ? AND status = 'pendente'",
                ['Retirado pela equipe de coleta (' . $nome . ')', $itemId, $link['lote_id']]);
            if (!$n) {
                throw new AcervoException('Só é possível retirar um livro que ainda aguarda revisão.');
            }
        });
    }

    /** Alertas que não impedem o pré-cadastro (a revisão decide). */
    private function avisos(int $loteId, int $itemId, array $d): array
    {
        $avisos = [];
        if ($d['isbn'] !== null) {
            $isbns = array_values(array_unique([$d['isbn'], strlen($d['isbn']) === 10 ? Isbn::para13($d['isbn']) : $d['isbn']]));
            $ph = implode(',', array_fill(0, count($isbns), '?'));
            if ($this->valor("SELECT 1 FROM livros WHERE isbn IN ($ph) AND arquivado_em IS NULL LIMIT 1", $isbns)) {
                $avisos[] = 'Já existe uma obra com este ISBN no acervo. A revisão vai ligar este livro a ela.';
            }
            $outro = $this->valor("SELECT numero FROM coleta_itens WHERE lote_id = ? AND id <> ? AND status <> 'recusado' AND isbn IN ($ph) LIMIT 1", [$loteId, $itemId, ...$isbns]);
            if ($outro) {
                $avisos[] = 'O livro nº ' . $outro . ' deste lote tem o mesmo ISBN. Se for o mesmo livro, some as quantidades em um só.';
            }
        }
        return $avisos;
    }

    /** Pré-cadastros do lote (mais recentes primeiro). $status: '' = todos menos os retirados. */
    public function itens(int $loteId, string $status = ''): array
    {
        $where = $status !== '' ? 'AND i.status = ?' : "AND i.status <> 'recusado'";
        return $this->todas("SELECT i.*, l.equipe, a.nome AS revisado_por_nome FROM coleta_itens i JOIN coleta_links l ON l.id = i.link_id
                             LEFT JOIN administradores a ON a.id = i.revisado_por WHERE i.lote_id = ? $where ORDER BY i.numero DESC",
            $status !== '' ? [$loteId, $status] : [$loteId]);
    }

    public function item(int $itemId): ?array
    {
        return $this->linha('SELECT i.*, l.equipe FROM coleta_itens i JOIN coleta_links l ON l.id = i.link_id WHERE i.id = ?', [$itemId]);
    }

    /** Contagem por situação, para a página do lote e a revisão. */
    public function resumo(int $loteId): array
    {
        $r = ['pendente' => 0, 'aprovado' => 0, 'recusado' => 0, 'exemplares_pendentes' => 0];
        foreach ($this->todas('SELECT status, COUNT(*) AS n, SUM(quantidade) AS q FROM coleta_itens WHERE lote_id = ? GROUP BY status', [$loteId]) as $l) {
            $r[$l['status']] = (int)$l['n'];
            if ($l['status'] === 'pendente') {
                $r['exemplares_pendentes'] = (int)$l['q'];
            }
        }
        return $r;
    }

    // =================================================================
    // Revisão (administradores)
    // =================================================================

    /** Obras do acervo com o mesmo ISBN do pré-cadastro (para ligar em vez de criar outra). */
    public function obrasComMesmoIsbn(array $item): array
    {
        if (empty($item['isbn'])) {
            return [];
        }
        $isbns = array_values(array_unique([$item['isbn'], strlen($item['isbn']) === 10 ? Isbn::para13($item['isbn']) : $item['isbn']]));
        $ph = implode(',', array_fill(0, count($isbns), '?'));
        return $this->todas("SELECT id, titulo, editora, ano_publicacao, edicao FROM livros WHERE isbn IN ($ph) AND arquivado_em IS NULL ORDER BY id", $isbns);
    }

    /** Guarda a obra do pré-cadastro antes de criar os exemplares (uma nova tentativa reaproveita a mesma obra). */
    public function vincularObra(int $itemId, int $livroId): void
    {
        $this->transacao(function () use ($itemId, $livroId) {
            $item = $this->linha('SELECT status, livro_id FROM coleta_itens WHERE id = ? FOR UPDATE', [$itemId]);
            if (!$item || $item['status'] !== 'pendente') {
                throw new AcervoException('Este pré-cadastro não aguarda mais revisão.');
            }
            if (!$this->valor('SELECT 1 FROM livros WHERE id = ? AND arquivado_em IS NULL', [$livroId])) {
                throw new AcervoException('Obra não encontrada ou arquivada.');
            }
            $this->executar('UPDATE coleta_itens SET livro_id = ? WHERE id = ?', [$livroId, $itemId]);
        });
    }

    /**
     * Aprova: cria os exemplares (com códigos BDP) da obra já vinculada, dentro do lote do pré-cadastro.
     * O administrador entra como participante do lote, se ainda não for; o lote ativo dele volta ao que
     * era depois. Devolve os códigos criados.
     * @return list<string>
     */
    public function aprovar(int $itemId, int $administradorId): array
    {
        $item = $this->linha('SELECT * FROM coleta_itens WHERE id = ?', [$itemId]);
        if (!$item || $item['status'] !== 'pendente') {
            throw new AcervoException('Este pré-cadastro não aguarda mais revisão.');
        }
        if (!$item['livro_id']) {
            throw new AcervoException('Escolha ou crie a obra antes de aprovar.');
        }
        $this->exigirSemPausa((int)$item['lote_id'], 'revisao');
        $loteId = (int)$item['lote_id'];
        return $this->transacao(function () use ($item, $itemId, $loteId, $administradorId) {
            if (!$this->lotes->participa($loteId, $administradorId)) {
                $this->lotes->atribuir($loteId, [$administradorId], $administradorId);
            }
            $anterior = $this->valor('SELECT lote_ativo_id FROM administradores WHERE id = ?', [$administradorId]);
            $this->executar('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?', [$loteId, $administradorId]);
            // A reserva deste pré-cadastro é liberada antes de ocupar as vagas de verdade.
            $this->executar("UPDATE coleta_itens SET status = 'aprovado', revisado_por = ?, revisado_em = NOW() WHERE id = ? AND status = 'pendente'", [$administradorId, $itemId]);
            $copias = array_fill(0, (int)$item['quantidade'], []);
            $obs = 'Pré-cadastro nº ' . $item['numero'] . ' da coleta (' . $item['coletado_por'] . ')' . ($item['observacoes'] ? ' · ' . $item['observacoes'] : '');
            $criados = $this->exemplares->cadastrarEmQuantidade((int)$item['livro_id'], ['situacao' => 'disponivel', 'observacoes' => $obs], $copias, $administradorId, md5('coleta-item-' . $itemId));
            $this->executar('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?', [$anterior ?: null, $administradorId]);
            $codigos = array_column($criados, 'codigo');
            $this->exemplares->eventoLote($loteId, null, 'coleta_aprovada', 'Pré-cadastro nº ' . $item['numero'] . ' aprovado: ' . implode(', ', $codigos), $administradorId,
                ['item_id' => $itemId, 'livro_id' => (int)$item['livro_id']]);
            $this->registrarAcao($administradorId, 'coleta.aprovar', 'lote', $loteId, ['item_id' => $itemId, 'livro_id' => (int)$item['livro_id'], 'exemplares' => count($codigos)]);
            return $codigos;
        });
    }

    public function recusar(int $itemId, int $administradorId, string $motivo): void
    {
        $motivo = self::textoLimpo($motivo, 255);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo da recusa (ex.: livro repetido, não pertence ao acervo).');
        }
        $this->transacao(function () use ($itemId, $administradorId, $motivo) {
            $item = $this->linha('SELECT * FROM coleta_itens WHERE id = ? FOR UPDATE', [$itemId]);
            if (!$item || $item['status'] !== 'pendente') {
                throw new AcervoException('Este pré-cadastro não aguarda mais revisão.');
            }
            $this->exigirSemPausa((int)$item['lote_id'], 'revisao');
            $this->executar("UPDATE coleta_itens SET status = 'recusado', motivo_recusa = ?, revisado_por = ?, revisado_em = NOW() WHERE id = ?", [$motivo, $administradorId, $itemId]);
            $this->registrarAcao($administradorId, 'coleta.recusar', 'lote', (int)$item['lote_id'], ['item_id' => $itemId]);
        });
    }

    // =================================================================
    // Carimbagem pelo link
    // =================================================================

    /** Planilha BDP do lote: exemplares na ordem do lote, com obra e situação do carimbo. */
    public function planilha(int $loteId): array
    {
        return $this->todas("SELECT e.id, e.codigo_individual, e.lote_ordem, e.carimbo_status, e.situacao, l.titulo,
                                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                    (SELECT cc.nome FROM coleta_carimbos cc WHERE cc.exemplar_id = e.id ORDER BY cc.id DESC LIMIT 1) AS carimbado_por_nome
                             FROM exemplares e JOIN livros l ON l.id = e.livro_id
                             WHERE e.lote_id = ? AND e.situacao <> 'baixado' ORDER BY e.lote_ordem, e.id", [$loteId]);
    }

    /** @return list<string> códigos marcados agora */
    public function marcarCarimbado(array $link, string $nome, array $ids): array
    {
        $nome = self::nomePessoa($nome);
        if ($link['tipo'] !== 'carimbagem') {
            throw new AcervoException('Este link não serve para a carimbagem.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            throw new AcervoException('Marque pelo menos um exemplar.');
        }
        return $this->transacao(function () use ($link, $nome, $ids) {
            $lote = $this->bloquearLote($link['lote_id']);
            if (!in_array($lote['status'], ['fechado', 'em_carimbagem'], true)) {
                throw new AcervoException($lote['status'] === 'aberto'
                    ? self::nome($lote) . ' ainda está em cadastro. A carimbagem começa quando o lote for fechado.'
                    : 'A carimbagem do ' . self::nome($lote) . ' já foi concluída.');
            }
            $this->exigirSemPausa($link['lote_id'], 'carimbagem');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $doLote = $this->todas("SELECT id, codigo_individual FROM exemplares WHERE lote_id = ? AND id IN ($ph)", [$link['lote_id'], ...$ids]);
            if (count($doLote) !== count($ids)) {
                throw new AcervoException('Algum exemplar marcado não pertence a este lote.');
            }
            $porCodigo = array_column($doLote, 'id', 'codigo_individual');
            $codigos = $this->exemplares->marcarCarimbado($ids, $link['criado_por'], 'Carimbado pela ' . $link['equipe'] . ' (' . $nome . ')');
            foreach ($codigos as $c) {
                if (isset($porCodigo[$c])) {
                    $this->executar('INSERT INTO coleta_carimbos (link_id, exemplar_id, nome) VALUES (?, ?, ?)', [$link['id'], (int)$porCodigo[$c], $nome]);
                }
            }
            return $codigos;
        });
    }

    /** "Concluir carimbagem do lote" pelo link: exige todos os exemplares carimbados. */
    public function concluirCarimbagem(array $link, string $nome): void
    {
        $nome = self::nomePessoa($nome);
        if ($link['tipo'] !== 'carimbagem') {
            throw new AcervoException('Este link não serve para a carimbagem.');
        }
        $this->exigirSemPausa($link['lote_id'], 'carimbagem');
        $this->transacao(function () use ($link, $nome) {
            $this->lotes->confirmarCarimbagem($link['lote_id'], $link['criado_por']);
            $this->exemplares->eventoLote($link['lote_id'], null, 'coleta_carimbagem_concluida', 'Carimbagem concluída pela ' . $link['equipe'] . ' (' . $nome . ')', $link['criado_por'], ['link_id' => $link['id']]);
        });
    }

    // =================================================================
    // Pausa
    // =================================================================

    public function pausaAberta(int $loteId, string $etapa): ?array
    {
        return $this->linha('SELECT * FROM coleta_pausas WHERE lote_id = ? AND etapa = ? AND retomado_em IS NULL ORDER BY id DESC LIMIT 1', [$loteId, $etapa]);
    }

    public function pausas(int $loteId): array
    {
        return $this->todas('SELECT * FROM coleta_pausas WHERE lote_id = ? ORDER BY id DESC', [$loteId]);
    }

    /** "Pausar trabalho": grava quem, por quê e onde parou. $linkId (pela equipe) ou $administradorId (pelo painel). */
    public function pausar(int $loteId, string $etapa, string $nome, string $motivo, ?int $administradorId = null, ?int $linkId = null): void
    {
        if (!isset(self::ETAPAS[$etapa])) {
            throw new AcervoException('Etapa inválida.');
        }
        $nome = self::nomePessoa($nome);
        $this->transacao(function () use ($loteId, $etapa, $nome, $motivo, $administradorId, $linkId) {
            $lote = $this->bloquearLote($loteId);
            if ($this->pausaAberta($loteId, $etapa)) {
                throw new AcervoException('A etapa de ' . mb_strtolower(self::ETAPAS[$etapa]) . ' já está pausada.');
            }
            $onde = $this->ondeParou($loteId, $etapa);
            $this->executar('INSERT INTO coleta_pausas (lote_id, etapa, pausado_por, administrador_id, link_id, motivo, onde_parou) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$loteId, $etapa, $nome, $administradorId, $linkId, self::textoLimpo($motivo, 255), $onde]);
            $responsavel = $administradorId ?? (int)$this->valor('SELECT criado_por FROM coleta_links WHERE id = ?', [$linkId]);
            $this->exemplares->eventoLote($loteId, null, 'coleta_pausada', self::nome($lote) . ': ' . mb_strtolower(self::ETAPAS[$etapa]) . ' pausada por ' . $nome . ' — ' . $onde, $responsavel ?: null,
                ['etapa' => $etapa, 'motivo' => $motivo]);
        });
    }

    public function retomar(int $loteId, string $etapa, string $nome): void
    {
        $nome = self::nomePessoa($nome);
        $this->transacao(function () use ($loteId, $etapa, $nome) {
            $lote = $this->bloquearLote($loteId);
            $pausa = $this->pausaAberta($loteId, $etapa);
            if (!$pausa) {
                throw new AcervoException('Esta etapa não está pausada.');
            }
            $this->executar('UPDATE coleta_pausas SET retomado_em = NOW(), retomado_por = ? WHERE id = ?', [$nome, $pausa['id']]);
            $responsavel = $pausa['administrador_id'] ?: (int)$this->valor('SELECT criado_por FROM coleta_links WHERE id = ?', [$pausa['link_id']]);
            $this->exemplares->eventoLote($loteId, null, 'coleta_retomada', self::nome($lote) . ': ' . mb_strtolower(self::ETAPAS[$etapa]) . ' retomada por ' . $nome, $responsavel ?: null, ['etapa' => $etapa]);
        });
    }

    /** Resumo do ponto em que a etapa parou, gravado na pausa. */
    public function ondeParou(int $loteId, string $etapa): string
    {
        $v = $this->vagas($loteId);
        $r = $this->resumo($loteId);
        if ($etapa === 'coleta') {
            $ultimo = (int)$this->valor("SELECT COALESCE(MAX(numero), 0) FROM coleta_itens WHERE lote_id = ? AND status <> 'recusado'", [$loteId]);
            return ($r['pendente'] + $r['aprovado']) . ' livro(s) pré-cadastrado(s), último nº ' . $ultimo . '; ' . $v['restantes'] . ' de ' . $v['capacidade'] . ' vaga(s) livre(s)';
        }
        if ($etapa === 'revisao') {
            return $r['aprovado'] . ' aprovado(s), ' . $r['pendente'] . ' aguardando revisão, ' . $r['recusado'] . ' recusado(s)';
        }
        $t = $this->linha("SELECT COUNT(*) AS total, SUM(carimbo_status = 'carimbado') AS feitos FROM exemplares WHERE lote_id = ? AND situacao <> 'baixado'", [$loteId]);
        return (int)$t['feitos'] . ' de ' . (int)$t['total'] . ' exemplar(es) carimbado(s)';
    }

    private function exigirSemPausa(int $loteId, string $etapa): void
    {
        $p = $this->pausaAberta($loteId, $etapa);
        if ($p) {
            throw new AcervoException('O trabalho de ' . mb_strtolower(self::ETAPAS[$etapa]) . ' deste lote está pausado (por ' . $p['pausado_por'] . '). Clique em “Retomar trabalho” para continuar.');
        }
    }

    // =================================================================
    // Apoio
    // =================================================================

    private function bloquearLote(int $loteId): array
    {
        $lote = $this->linha('SELECT * FROM cadastro_lotes WHERE id = ? FOR UPDATE', [$loteId]);
        if (!$lote) {
            throw new AcervoException('Lote não encontrado.');
        }
        return $lote;
    }

    /** Nome digitado por quem usa o link (ou do administrador): obrigatório, 2 a 100 caracteres. */
    public static function nomePessoa(string $nome): string
    {
        $nome = self::textoLimpo(strip_tags($nome), 100) ?? '';
        if (mb_strlen($nome) < 2) {
            throw new AcervoException('Digite o seu nome antes de continuar.');
        }
        return $nome;
    }

    public static function nome(array $lote): string
    {
        return 'Lote ' . ExemplarService::numeroLote($lote['numero']);
    }
}
