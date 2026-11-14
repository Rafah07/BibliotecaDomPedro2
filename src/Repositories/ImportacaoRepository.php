<?php

namespace Biblioteca\Repositories;

use Biblioteca\Services\ImportValidationService;
use Biblioteca\Support\Isbn;
use Biblioteca\Support\IsbnDiagnostico;
use Biblioteca\Support\Texto;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Acesso ao MySQL do módulo de importação.
 * Todas as consultas são preparadas. As gravações no acervo acontecem em importarTodos()
 * (uma única transação: ou a planilha inteira entra, ou nada entra) e, nas importações
 * antigas que estavam "Gravando no acervo", em confirmarLote().
 *
 * Desde a migração 110 nenhuma linha é barrada por falta de dados ou por valores errados:
 * os alertas viram alertas cadastrais do livro (livro_pendencias) e cada linha gravada entra
 * como item "Pendente" do inventário em andamento, para a conferência física.
 */
final class ImportacaoRepository
{
    /** Situações em que a revisão ainda pode alterar as linhas. */
    public const STATUS_EDITAVEIS = ['em_revisao', 'parcial'];
    public const ACOES = ['criar', 'vincular', 'outro_exemplar', 'ignorar', 'revisar_depois'];
    private const ACOES_GRAVAM = ['criar', 'vincular', 'outro_exemplar'];

    public function __construct(private PDO $pdo, private ImportValidationService $validador)
    {
    }

    // ---------------------------------------------------------------
    // Importações
    // ---------------------------------------------------------------

    public function buscar(int $id, bool $bloquear = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, a.nome AS usuario_nome FROM importacoes i LEFT JOIN administradores a ON a.id = i.usuario_id WHERE i.id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id]);
        $linha = $stmt->fetch();
        if (!$linha) {
            return null;
        }
        foreach (['abas_json', 'colunas_json', 'mapeamento_json'] as $campo) {
            $linha[$campo] = $linha[$campo] !== null ? json_decode($linha[$campo], true) : null;
        }
        return $linha;
    }

    /**
     * Importação anterior com o mesmo arquivo (mesmo SHA-256), que não foi cancelada antes de gravar.
     * Enviar o mesmo arquivo de novo é permitido, mas só depois de a pessoa confirmar o aviso.
     */
    public function buscarPorArquivo(string $hash, ?int $ignorarId = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome_arquivo, status, criado_em, total_importados + total_vinculados AS gravados
                                     FROM importacoes WHERE arquivo_ativo = ? AND id <> ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$hash, $ignorarId ?? 0]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Sinais de que esta importação repete outra: mesmo arquivo, mesmo conteúdo (outro arquivo com
     * as mesmas linhas) ou linhas idênticas a linhas já gravadas por importações anteriores.
     * Exemplares diferentes da mesma obra NÃO entram aqui: só linhas inteiras iguais, de OUTRA importação.
     * @return array{repetida: bool, arquivo: ?array, conteudo: ?array, linhas_ja_importadas: int, confirmada_em: ?string}
     */
    public function repeticoes(int $id): array
    {
        $imp = $this->buscar($id);
        if (!$imp) {
            return ['repetida' => false, 'arquivo' => null, 'conteudo' => null, 'linhas_ja_importadas' => 0, 'confirmada_em' => null];
        }
        $arquivo = $this->buscarPorArquivo($imp['identificador_arquivo'], $id);
        $conteudo = null;
        if ($imp['hash_conteudo']) {
            $stmt = $this->pdo->prepare("SELECT id, nome_arquivo, status, criado_em FROM importacoes
                                         WHERE hash_conteudo = ? AND id <> ? AND (status NOT IN ('cancelada', 'falhou') OR total_importados + total_vinculados > 0)
                                         ORDER BY id DESC LIMIT 1");
            $stmt->execute([$imp['hash_conteudo'], $id]);
            $conteudo = $stmt->fetch() ?: null;
        }
        $linhas = (int)$this->valor(
            "SELECT COUNT(*) FROM importacao_itens it
             WHERE it.importacao_id = ? AND it.processado_em IS NULL
               AND EXISTS (SELECT 1 FROM importacao_itens o WHERE o.hash_linha = it.hash_linha AND o.importacao_id <> it.importacao_id
                           AND o.resultado_importacao IN ('criado', 'vinculado', 'outro_exemplar'))",
            [$id]
        );
        return [
            'repetida' => $arquivo !== null || $conteudo !== null || $linhas > 0,
            'arquivo' => $arquivo,
            'conteudo' => $conteudo,
            'linhas_ja_importadas' => $linhas,
            'confirmada_em' => $imp['repeticao_confirmada_em'],
        ];
    }

    public function criar(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, abas_json, aba, usuario_id, total_linhas,
                                      repete_importacao_id, repeticao_confirmada_em, repeticao_confirmada_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $repete = $dados['repete_importacao_id'] ?? null;
        $stmt->execute([
            mb_substr($dados['nome_arquivo'], 0, 255), $dados['identificador_arquivo'], $dados['arquivo_armazenado'], $dados['tipo_arquivo'],
            $dados['tamanho_bytes'], json_encode($dados['abas'], JSON_UNESCAPED_UNICODE), $dados['aba'], $dados['usuario_id'], $dados['total_linhas'],
            $repete, $repete ? date('Y-m-d H:i:s') : null, $repete ? $dados['usuario_id'] : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array{status?: string, limite?: int} $filtros */
    public function listar(array $filtros = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filtros['status'])) {
            $where[] = 'i.status = ?';
            $params[] = $filtros['status'];
        }
        if (!empty($filtros['abertas'])) {
            $where[] = "i.status IN ('enviada', 'em_analise', 'em_revisao', 'importando', 'parcial')";
        }
        $sql = 'SELECT i.*, a.nome AS usuario_nome FROM importacoes i LEFT JOIN administradores a ON a.id = i.usuario_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY i.criado_em DESC, i.id DESC LIMIT ' . max(1, min(500, (int)($filtros['limite'] ?? 200)));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Números dos cards da página de importação. */
    public function estatisticas(): array
    {
        $imp = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(total_importados + total_vinculados), 0) AS importados
             FROM importacoes WHERE status <> 'enviada'"
        )->fetch();
        // Livros importados que ainda esperam a conferência física, e os que têm pendências de dados.
        $rev = $this->pdo->query(
            "SELECT COALESCE(SUM(NOT EXISTS (SELECT 1 FROM exemplares e WHERE e.livro_id = l.id)), 0) AS pendentes,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM livro_pendencias p WHERE p.livro_id = l.id AND p.resolvida_em IS NULL)), 0) AS com_pendencias
             FROM livros l WHERE l.arquivado_em IS NULL AND l.origem_cadastro = 'planilha'"
        )->fetch();
        return [
            'importacoes' => (int)$imp['total'],
            'importados' => (int)$imp['importados'],
            'pendencias' => (int)$rev['com_pendencias'],
            'pendentes_revisao' => (int)$rev['pendentes'],
            // Mantido por compatibilidade: a importação não barra mais linhas por erro.
            'erros' => 0,
        ];
    }

    public function atualizar(int $id, array $campos): void
    {
        $sets = [];
        $params = [];
        foreach ($campos as $campo => $valor) {
            if (!preg_match('/^[a-z_]+$/', $campo)) {
                throw new RuntimeException('Campo inválido.');
            }
            $sets[] = "$campo = ?";
            $params[] = is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : $valor;
        }
        $params[] = $id;
        $this->pdo->prepare('UPDATE importacoes SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /**
     * Prepara (ou refaz) a análise com a aba e o mapeamento escolhidos.
     * As linhas anteriores, ainda não gravadas no acervo, são descartadas.
     */
    public function iniciarAnalise(int $id, string $aba, int $linhaCabecalho, array $cabecalhos, array $mapeamento): void
    {
        $this->transacao(function () use ($id, $aba, $linhaCabecalho, $cabecalhos, $mapeamento) {
            $imp = $this->buscar($id, true);
            if (!$imp || !in_array($imp['status'], ['enviada', 'em_analise', 'em_revisao'], true)) {
                throw new RuntimeException('Esta importação não pode mais ser analisada novamente.');
            }
            $processados = (int)$this->valor('SELECT COUNT(*) FROM importacao_itens WHERE importacao_id = ? AND processado_em IS NOT NULL', [$id]);
            if ($processados > 0) {
                throw new RuntimeException('Esta importação já gravou registros no acervo e não pode ser analisada novamente.');
            }
            $this->pdo->prepare('UPDATE importacao_itens SET item_referencia_id = NULL WHERE importacao_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM importacao_itens WHERE importacao_id = ?')->execute([$id]);
            $this->atualizar($id, [
                'aba' => $aba,
                'linha_cabecalho' => $linhaCabecalho,
                'colunas_json' => array_values($cabecalhos),
                'mapeamento_json' => $mapeamento,
                'status' => 'em_analise',
                'total_linhas' => 0,
                'linhas_analisadas' => $linhaCabecalho,
                'mensagem_erro' => null,
            ] + array_fill_keys(['total_validos', 'total_avisos', 'total_erros', 'total_duplicidades', 'total_ja_cadastrados', 'total_ignorados'], 0));
        });
    }

    /**
     * Grava um bloco de linhas lidas da planilha (já normalizadas e validadas individualmente).
     * @param array<int, array<string, string>> $linhas número da linha => [cabeçalho => valor]
     */
    public function inserirLinhas(int $id, array $linhas, array $mapeamento, int $ultimaLinhaLida): void
    {
        $this->transacao(function () use ($id, $linhas, $mapeamento, $ultimaLinhaLida) {
            $imp = $this->buscar($id, true);
            if (!$imp || $imp['status'] !== 'em_analise') {
                throw new RuntimeException('A análise desta importação foi interrompida ou já terminou.');
            }
            if ($ultimaLinhaLida <= (int)$imp['linhas_analisadas']) {
                return; // bloco repetido (ex.: reenvio após queda de conexão)
            }
            if ($linhas) {
                $valores = [];
                $params = [];
                foreach ($linhas as $numero => $original) {
                    $n = $this->validador->normalizar($this->validador->mapear($original, $mapeamento));
                    $problemas = $this->validador->validarLinha($n);
                    $chaves = $this->validador->chaves($n);
                    $valores[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $id, $numero, $this->json($original), $this->json($n), ImportValidationService::situacao($problemas),
                        $this->json($problemas), $chaves['hash'], $chaves['isbn'], $chaves['obra']);
                }
                $this->pdo->prepare(
                    'INSERT IGNORE INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, status_validacao, erros_json, hash_linha, chave_isbn, chave_obra)
                     VALUES ' . implode(', ', $valores)
                )->execute($params);
            }
            $this->pdo->prepare('UPDATE importacoes SET linhas_analisadas = ?, total_linhas = (SELECT COUNT(*) FROM importacao_itens WHERE importacao_id = ?) WHERE id = ?')
                ->execute([$ultimaLinhaLida, $id, $id]);
        });
    }

    /** Termina a análise: procura duplicidades, guarda a impressão digital do conteúdo e libera a pré-visualização. */
    public function concluirAnalise(int $id): void
    {
        $this->recalcular($id);
        $this->pdo->prepare("UPDATE importacoes SET status = 'em_revisao', hash_conteudo = ? WHERE id = ? AND status = 'em_analise'")
            ->execute([$this->hashConteudo($id), $id]);
    }

    /**
     * Impressão digital das linhas lidas (na ordem da planilha). Duas planilhas com as mesmas linhas
     * têm o mesmo valor, mesmo que o arquivo tenha outro nome ou tenha sido salvo de novo.
     */
    public function hashConteudo(int $id): ?string
    {
        $ctx = hash_init('sha1');
        $ultimo = 0;
        $total = 0;
        do {
            $stmt = $this->pdo->prepare('SELECT numero_linha, hash_linha FROM importacao_itens WHERE importacao_id = ? AND numero_linha > ? ORDER BY numero_linha LIMIT 2000');
            $stmt->execute([$id, $ultimo]);
            $lote = $stmt->fetchAll();
            foreach ($lote as $l) {
                hash_update($ctx, $l['hash_linha'] . "\n");
                $ultimo = (int)$l['numero_linha'];
                $total++;
            }
        } while (count($lote) === 2000);
        return $total > 0 ? hash_final($ctx) : null;
    }

    // ---------------------------------------------------------------
    // Validação completa (linhas + duplicidades) e totais
    // ---------------------------------------------------------------

    /** Índices do acervo ativo para comparar ISBN e título/autor. */
    public function indiceAcervo(): array
    {
        $indice = ['isbn' => [], 'obra' => [], 'isbn_livro' => []];
        $stmt = $this->pdo->query(
            "SELECT l.id, l.titulo, l.isbn,
                    (SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id ORDER BY la.ordem LIMIT 1) AS autor
             FROM livros l WHERE l.arquivado_em IS NULL"
        );
        while ($l = $stmt->fetch()) {
            $id = (int)$l['id'];
            $isbn = $l['isbn'] ? Isbn::para13($l['isbn']) : null;
            $indice['isbn_livro'][$id] = $isbn;
            if ($isbn) {
                $indice['isbn'][$isbn] ??= $id;
            }
            $obra = Texto::chaveObra($l['titulo'], $l['autor']);
            if ($obra) {
                $indice['obra'][$obra][] = $id;
            }
        }
        return $indice;
    }

    /**
     * Revalida todas as linhas ainda não gravadas (as duplicidades dependem das outras linhas)
     * e atualiza a ação sugerida e os totais.
     */
    public function recalcular(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, numero_linha, dados_normalizados_json, status_validacao, erros_json, ja_cadastrado, livro_sugerido_id, linha_relacionada,
                    acao_importacao, acao_automatica, hash_linha, chave_isbn, chave_obra, processado_em, resultado_importacao
             FROM importacao_itens WHERE importacao_id = ?'
        );
        $stmt->execute([$id]);
        $itens = $stmt->fetchAll();
        $dups = $this->validador->analisarDuplicidades(array_map(fn ($i) => [
            'id' => (int)$i['id'], 'linha' => (int)$i['numero_linha'], 'hash' => $i['hash_linha'], 'isbn' => $i['chave_isbn'], 'obra' => $i['chave_obra'],
        ], $itens), $this->indiceAcervo());

        $update = $this->pdo->prepare(
            'UPDATE importacao_itens SET status_validacao = ?, erros_json = ?, ja_cadastrado = ?, livro_sugerido_id = ?, linha_relacionada = ?, acao_importacao = ?
             WHERE id = ? AND processado_em IS NULL'
        );
        $this->transacao(function () use ($itens, $dups, $update) {
            foreach ($itens as $item) {
                if ($item['processado_em'] !== null && $item['resultado_importacao'] !== 'falhou') {
                    continue;
                }
                $n = json_decode($item['dados_normalizados_json'], true);
                $d = $dups[(int)$item['id']];
                $problemas = array_merge($this->validador->validarLinha($n), $d['problemas']);
                $status = ImportValidationService::situacao($problemas);
                $acao = $item['acao_importacao'];
                if ((int)$item['acao_automatica'] === 1) {
                    // Toda linha entra como livro novo; os alertas viram alertas cadastrais, conferidos no inventário.
                    $acao = 'criar';
                }
                $novo = [$status, $this->json($problemas), $d['ja_cadastrado'] ? 1 : 0, $d['livro_sugerido_id'], $d['linha_relacionada'], $acao];
                $atual = [$item['status_validacao'], $item['erros_json'] === null ? null : $this->json(json_decode($item['erros_json'], true)), (int)$item['ja_cadastrado'],
                    $item['livro_sugerido_id'] === null ? null : (int)$item['livro_sugerido_id'], $item['linha_relacionada'] === null ? null : (int)$item['linha_relacionada'], $item['acao_importacao']];
                if ($novo != $atual) {
                    $update->execute([...$novo, $item['id']]);
                }
            }
        });
        $this->atualizarTotais($id);
    }

    public function atualizarTotais(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE importacoes i JOIN (
                SELECT importacao_id,
                       COUNT(*) AS total,
                       COALESCE(SUM(status_validacao = 'valido'), 0) AS validos,
                       COALESCE(SUM(status_validacao = 'aviso'), 0) AS avisos,
                       COALESCE(SUM(status_validacao = 'erro'), 0) AS erros,
                       COALESCE(SUM(status_validacao = 'duplicidade'), 0) AS duplicidades,
                       COALESCE(SUM(ja_cadastrado), 0) AS ja_cadastrados,
                       COALESCE(SUM(acao_importacao = 'ignorar'), 0) AS ignorados,
                       COALESCE(SUM(resultado_importacao = 'criado'), 0) AS importados,
                       COALESCE(SUM(resultado_importacao IN ('vinculado', 'outro_exemplar')), 0) AS vinculados,
                       COALESCE(SUM(resultado_importacao = 'falhou'), 0) AS falhas
                FROM importacao_itens WHERE importacao_id = ? GROUP BY importacao_id
             ) t ON t.importacao_id = i.id
             SET i.total_linhas = t.total, i.total_validos = t.validos, i.total_avisos = t.avisos, i.total_erros = t.erros,
                 i.total_duplicidades = t.duplicidades, i.total_ja_cadastrados = t.ja_cadastrados, i.total_ignorados = t.ignorados,
                 i.total_importados = t.importados, i.total_vinculados = t.vinculados, i.total_falhas = t.falhas
             WHERE i.id = ?"
        )->execute([$id, $id]);
    }

    // ---------------------------------------------------------------
    // Linhas
    // ---------------------------------------------------------------

    public const FILTROS = [
        '' => 'Todas',
        'valido' => 'Sem alertas',
        'incompleto' => 'Informações incompletas',
        'inconsistencia' => 'Possíveis inconsistências',
        'duplicidade' => 'Possíveis duplicidades',
        'aviso' => 'Com alertas',
        'erro' => 'Com erro (importação antiga)',
        'ja_cadastrado' => 'Já cadastradas',
        'sem_decisao' => 'Aguardando decisão',
        'revisar_depois' => 'Revisar depois',
        'ignorado' => 'Ignoradas',
        'importado' => 'Gravadas no acervo',
        'falhou' => 'Falharam ao gravar',
    ];

    /** Linhas paginadas para a tabela de revisão. */
    public function itens(int $id, string $filtro, string $busca, int $pagina, int $porPagina): array
    {
        $where = ['it.importacao_id = ?'];
        $params = [$id];
        switch ($filtro) {
            case 'valido':
            case 'aviso':
            case 'erro':
            case 'duplicidade':
                $where[] = 'it.status_validacao = ?';
                $params[] = $filtro;
                break;
            case 'incompleto':
            case 'inconsistencia':
                $where[] = self::sqlTipo($filtro);
                break;
            case 'ja_cadastrado':
                $where[] = 'it.ja_cadastrado = 1';
                break;
            case 'sem_decisao':
                $where[] = "it.processado_em IS NULL AND it.acao_importacao IS NULL";
                break;
            case 'revisar_depois':
                $where[] = "it.acao_importacao = 'revisar_depois' AND it.processado_em IS NULL";
                break;
            case 'ignorado':
                $where[] = "it.acao_importacao = 'ignorar'";
                break;
            case 'importado':
                $where[] = "it.resultado_importacao IN ('criado', 'vinculado', 'outro_exemplar')";
                break;
            case 'falhou':
                $where[] = "it.resultado_importacao = 'falhou'";
                break;
            case 'problemas':
                $where[] = "it.status_validacao <> 'valido'";
                break;
            case 'isbn_invalido':
                $where[] = 'it.processado_em IS NULL AND ' . self::SQL_ISBN_INVALIDO;
                break;
        }
        $busca = trim($busca);
        if ($busca !== '') {
            if (ctype_digit($busca) && strlen($busca) < 7) {
                $where[] = 'it.numero_linha = ?';
                $params[] = (int)$busca;
            } else {
                $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(it.dados_normalizados_json, '$.titulo')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(it.dados_normalizados_json, '$.autor')) LIKE ? OR it.chave_isbn LIKE ?)";
                $like = '%' . addcslashes($busca, '%_\\') . '%';
                array_push($params, $like, $like, '%' . preg_replace('/[^0-9X]/i', '', $busca) . '%');
            }
        }
        $whereSql = implode(' AND ', $where);
        $total = (int)$this->valor("SELECT COUNT(*) FROM importacao_itens it WHERE $whereSql", $params);
        $offset = max(0, ($pagina - 1) * $porPagina);
        $stmt = $this->pdo->prepare(
            "SELECT it.*, l.titulo AS livro_resultante_titulo
             FROM importacao_itens it LEFT JOIN livros l ON l.id = it.livro_resultante_id
             WHERE $whereSql ORDER BY it.numero_linha LIMIT $porPagina OFFSET $offset"
        );
        $stmt->execute($params);
        return ['total' => $total, 'itens' => array_map([$this, 'decodificarItem'], $stmt->fetchAll())];
    }

    /** Contagem de linhas por filtro (abas da tabela de revisão). */
    public function contagemFiltros(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS todas,
                    COALESCE(SUM(status_validacao = 'valido'), 0) AS valido, COALESCE(SUM(status_validacao = 'aviso'), 0) AS aviso,
                    COALESCE(SUM(status_validacao = 'erro'), 0) AS erro, COALESCE(SUM(status_validacao = 'duplicidade'), 0) AS duplicidade,
                    COALESCE(SUM(ja_cadastrado), 0) AS ja_cadastrado,
                    COALESCE(SUM(" . self::sqlTipo('incompleto', '') . "), 0) AS incompleto,
                    COALESCE(SUM(" . self::sqlTipo('inconsistencia', '') . "), 0) AS inconsistencia,
                    COALESCE(SUM(processado_em IS NULL AND acao_importacao IS NULL), 0) AS sem_decisao,
                    COALESCE(SUM(processado_em IS NULL AND acao_importacao = 'revisar_depois'), 0) AS revisar_depois,
                    COALESCE(SUM(acao_importacao = 'ignorar'), 0) AS ignorado,
                    COALESCE(SUM(resultado_importacao IN ('criado', 'vinculado', 'outro_exemplar')), 0) AS importado,
                    COALESCE(SUM(resultado_importacao = 'falhou'), 0) AS falhou
             FROM importacao_itens WHERE importacao_id = ?"
        );
        $stmt->execute([$id]);
        $linha = $stmt->fetch();
        $contagem = ['' => (int)$linha['todas']];
        foreach ($linha as $chave => $valor) {
            if ($chave !== 'todas') {
                $contagem[$chave] = (int)$valor;
            }
        }
        return $contagem;
    }

    public function item(int $itemId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT it.*, l.titulo AS livro_resultante_titulo, ref.numero_linha AS referencia_linha, a.nome AS revisado_por_nome
             FROM importacao_itens it
             LEFT JOIN livros l ON l.id = it.livro_resultante_id
             LEFT JOIN importacao_itens ref ON ref.id = it.item_referencia_id
             LEFT JOIN administradores a ON a.id = it.revisado_por
             WHERE it.id = ?'
        );
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        return $item ? $this->decodificarItem($item) : null;
    }

    public function itemPorLinha(int $importacaoId, int $linha): ?array
    {
        $id = $this->valor('SELECT id FROM importacao_itens WHERE importacao_id = ? AND numero_linha = ?', [$importacaoId, $linha]);
        return $id ? $this->item((int)$id) : null;
    }

    private function decodificarItem(array $item): array
    {
        foreach (['dados_originais_json', 'dados_normalizados_json', 'dados_corrigidos_json', 'erros_json'] as $campo) {
            $item[$campo] = $item[$campo] !== null ? json_decode($item[$campo], true) : null;
        }
        return $item;
    }

    /** Livros do acervo para "vincular a livro existente". */
    public function livrosResumo(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao, l.arquivado_em,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR \'; \') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores
             FROM livros l WHERE l.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $res = [];
        foreach ($stmt->fetchAll() as $l) {
            $res[(int)$l['id']] = $l;
        }
        return $res;
    }

    public function pesquisarLivros(string $termo, int $limite = 10): array
    {
        $termo = trim($termo);
        if ($termo === '') {
            return [];
        }
        $isbn = Isbn::normalizar($termo);
        if (ctype_digit($termo) && strlen($termo) < 8) {
            $ids = [(int)$termo];
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT l.id FROM livros l
                 LEFT JOIN livro_autores la ON la.livro_id = l.id LEFT JOIN autores a ON a.id = la.autor_id
                 WHERE l.arquivado_em IS NULL AND (l.titulo LIKE ? OR a.nome LIKE ? OR (? <> '' AND l.isbn = ?))
                 ORDER BY l.id LIMIT " . max(1, min(30, $limite))
            );
            $like = '%' . addcslashes($termo, '%_\\') . '%';
            $stmt->execute([$like, $like, $isbn, $isbn]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return array_values(array_filter($this->livrosResumo($ids), fn ($l) => $l['arquivado_em'] === null));
    }

    // ---------------------------------------------------------------
    // Revisão
    // ---------------------------------------------------------------

    /**
     * Corrige campos de uma linha. Os dados originais continuam intactos;
     * a linha é revalidada junto com as demais.
     */
    public function corrigir(int $itemId, array $correcoes, int $usuarioId): array
    {
        $item = $this->itemEditavel($itemId);
        $imp = $this->buscar((int)$item['importacao_id']);
        $atuais = $item['dados_corrigidos_json'] ?? [];
        foreach ($correcoes as $campo => $valor) {
            if (!in_array($campo, ImportValidationService::CAMPOS_CORRIGIVEIS, true)) {
                continue;
            }
            $valor = trim((string)$valor);
            $atuais[$campo] = mb_substr($valor, 0, 500);
        }
        $brutos = $this->validador->mapear($item['dados_originais_json'], $imp['mapeamento_json'] ?? []);
        $n = $this->validador->aplicarCorrecoes($brutos, $atuais);
        $chaves = $this->validador->chaves($n);
        $this->pdo->prepare(
            'UPDATE importacao_itens SET dados_corrigidos_json = ?, dados_normalizados_json = ?, hash_linha = ?, chave_isbn = ?, chave_obra = ?,
                    revisado_por = ?, revisado_em = NOW(), processado_em = NULL, resultado_importacao = NULL, mensagem_processamento = NULL
             WHERE id = ?'
        )->execute([$this->json($atuais), $this->json($n), $chaves['hash'], $chaves['isbn'], $chaves['obra'], $usuarioId, $itemId]);
        $this->recalcular((int)$item['importacao_id']);
        return $this->item($itemId);
    }

    /** Desfaz as correções de uma linha, voltando aos valores da planilha. */
    public function desfazerCorrecoes(int $itemId, int $usuarioId): array
    {
        $item = $this->itemEditavel($itemId);
        $imp = $this->buscar((int)$item['importacao_id']);
        $n = $this->validador->normalizar($this->validador->mapear($item['dados_originais_json'], $imp['mapeamento_json'] ?? []));
        $chaves = $this->validador->chaves($n);
        $this->pdo->prepare(
            'UPDATE importacao_itens SET dados_corrigidos_json = NULL, dados_normalizados_json = ?, hash_linha = ?, chave_isbn = ?, chave_obra = ?,
                    revisado_por = ?, revisado_em = NOW(), processado_em = NULL, resultado_importacao = NULL, mensagem_processamento = NULL
             WHERE id = ?'
        )->execute([$this->json($n), $chaves['hash'], $chaves['isbn'], $chaves['obra'], $usuarioId, $itemId]);
        $this->recalcular((int)$item['importacao_id']);
        return $this->item($itemId);
    }

    /**
     * Registra a decisão sobre uma linha.
     * $acao: criar | vincular (exige $livroId) | outro_exemplar (exige $linhaReferencia ou $livroId) | ignorar | revisar_depois | automatica
     */
    public function decidir(int $itemId, string $acao, ?int $livroId, ?int $linhaReferencia, int $usuarioId): array
    {
        $item = $this->itemEditavel($itemId);
        $importacaoId = (int)$item['importacao_id'];
        $vinculado = null;
        $referencia = null;

        if ($acao === 'automatica') {
            $this->pdo->prepare(
                'UPDATE importacao_itens SET acao_automatica = 1, acao_importacao = NULL, livro_vinculado_id = NULL, item_referencia_id = NULL,
                        revisado_por = ?, revisado_em = NOW(), processado_em = NULL, resultado_importacao = NULL, mensagem_processamento = NULL WHERE id = ?'
            )->execute([$usuarioId, $itemId]);
            $this->recalcular($importacaoId);
            return $this->item($itemId);
        }
        if (!in_array($acao, self::ACOES, true)) {
            throw new RuntimeException('Ação inválida.');
        }
        if ($acao === 'vincular') {
            if (!$livroId || !$this->livrosResumo([$livroId]) || $this->livrosResumo([$livroId])[$livroId]['arquivado_em'] !== null) {
                throw new RuntimeException('Escolha um livro ativo do acervo para vincular esta linha.');
            }
            $vinculado = $livroId;
        }
        if ($acao === 'outro_exemplar') {
            if ($linhaReferencia) {
                $ref = $this->itemPorLinha($importacaoId, $linhaReferencia);
                if (!$ref || (int)$ref['id'] === $itemId) {
                    throw new RuntimeException('Informe outra linha desta planilha como referência.');
                }
                if (in_array($ref['acao_importacao'], ['ignorar', 'outro_exemplar'], true) && $ref['resultado_importacao'] === null) {
                    throw new RuntimeException('A linha ' . $linhaReferencia . ' também precisa ser importada como livro (criar ou vincular) para servir de referência.');
                }
                $referencia = (int)$ref['id'];
            } elseif ($livroId && $this->livrosResumo([$livroId])) {
                $vinculado = $livroId;
            } else {
                throw new RuntimeException('Informe a linha ou o livro do qual esta linha é outro exemplar.');
            }
        }
        $this->pdo->prepare(
            'UPDATE importacao_itens SET acao_importacao = ?, acao_automatica = 0, livro_vinculado_id = ?, item_referencia_id = ?,
                    revisado_por = ?, revisado_em = NOW(), processado_em = NULL, resultado_importacao = NULL, mensagem_processamento = NULL WHERE id = ?'
        )->execute([$acao, $vinculado, $referencia, $usuarioId, $itemId]);
        $this->atualizarTotais($importacaoId);
        return $this->item($itemId);
    }

    /** Aplica a mesma decisão a várias linhas (ex.: ignorar todas as linhas idênticas). */
    public function decidirEmLote(int $importacaoId, string $filtro, string $acao, int $usuarioId): int
    {
        $imp = $this->buscar($importacaoId);
        if (!$imp || !in_array($imp['status'], self::STATUS_EDITAVEIS, true)) {
            throw new RuntimeException('Esta importação não está em revisão.');
        }
        $permitidas = [
            'linha_identica:ignorar' => "JSON_SEARCH(it.erros_json, 'one', 'linha_identica', NULL, '$[*].codigo') IS NOT NULL",
            'linha_identica:outro_exemplar' => "JSON_SEARCH(it.erros_json, 'one', 'linha_identica', NULL, '$[*].codigo') IS NOT NULL",
            'ja_cadastrado:vincular' => "it.ja_cadastrado = 1 AND it.livro_sugerido_id IS NOT NULL AND JSON_SEARCH(it.erros_json, 'one', 'ja_cadastrado', NULL, '$[*].codigo') IS NOT NULL",
        ];
        $chave = $filtro . ':' . $acao;
        if (!isset($permitidas[$chave])) {
            throw new RuntimeException('Ação em lote não permitida.');
        }
        $extra = match ($chave) {
            'linha_identica:outro_exemplar' => ', it.item_referencia_id = r.id',
            'ja_cadastrado:vincular' => ', it.livro_vinculado_id = it.livro_sugerido_id',
            default => '',
        };
        // Junta a linha de referência (a primeira ocorrência) quando a ação precisa dela.
        $join = $chave === 'linha_identica:outro_exemplar'
            ? 'JOIN importacao_itens r ON r.importacao_id = it.importacao_id AND r.numero_linha = it.linha_relacionada'
            : '';
        $condicao = $permitidas[$chave];
        $stmt = $this->pdo->prepare(
            "UPDATE importacao_itens it $join SET it.acao_importacao = ?, it.acao_automatica = 0, it.revisado_por = ?, it.revisado_em = NOW() $extra
             WHERE it.importacao_id = ? AND it.processado_em IS NULL AND it.acao_automatica = 1 AND $condicao"
        );
        $stmt->execute([$acao, $usuarioId, $importacaoId]);
        $this->atualizarTotais($importacaoId);
        return $stmt->rowCount();
    }

    private function itemEditavel(int $itemId): array
    {
        $item = $this->item($itemId);
        if (!$item) {
            throw new RuntimeException('Linha não encontrada.');
        }
        $imp = $this->buscar((int)$item['importacao_id']);
        if (!in_array($imp['status'], self::STATUS_EDITAVEIS, true)) {
            throw new RuntimeException('Esta importação não está em revisão.');
        }
        if ($item['processado_em'] !== null && $item['resultado_importacao'] !== 'falhou') {
            throw new RuntimeException('Esta linha já foi gravada no acervo e não pode mais ser alterada.');
        }
        return $item;
    }

    // ---------------------------------------------------------------
    // ISBNs inválidos: tratamento em lote
    // ---------------------------------------------------------------

    /** Linha cujo problema inclui "ISBN inválido". */
    private const SQL_ISBN_INVALIDO = "JSON_SEARCH(it.erros_json, 'one', 'isbn_invalido', NULL, '$[*].codigo') IS NOT NULL";

    /** Quantas linhas ainda não gravadas têm ISBN inválido. */
    public function contagemIsbnInvalido(int $id): int
    {
        return (int)$this->valor('SELECT COUNT(*) FROM importacao_itens it WHERE it.importacao_id = ? AND it.processado_em IS NULL AND ' . self::SQL_ISBN_INVALIDO, [$id]);
    }

    /**
     * Linhas ainda não gravadas com ISBN inválido, cada uma com o diagnóstico do ISBN
     * e com a resposta: pode ser importada sem ISBN? (só quando o ISBN é o único impedimento).
     */
    public function pendenciasIsbn(int $id): array
    {
        $imp = $this->buscar($id);
        if (!$imp) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT it.*, a.nome AS revisado_por_nome FROM importacao_itens it LEFT JOIN administradores a ON a.id = it.revisado_por
             WHERE it.importacao_id = ? AND it.processado_em IS NULL AND ' . self::SQL_ISBN_INVALIDO . ' ORDER BY it.numero_linha'
        );
        $stmt->execute([$id]);
        $lista = [];
        foreach ($stmt->fetchAll() as $item) {
            $item = $this->decodificarItem($item);
            $item['isbn_original'] = $this->isbnOriginal($item, $imp);
            $item['diagnostico'] = IsbnDiagnostico::analisar((string)($item['dados_normalizados_json']['isbn_informado'] ?? $item['isbn_original'] ?? ''));
            $item['avaliacao'] = $this->avaliarSemIsbn($item, $imp);
            $lista[] = $item;
        }
        return $lista;
    }

    /** Linhas desta importação gravadas sem ISBN pelo tratamento em lote (o ISBN original fica para conferência). */
    public function importadosSemIsbn(int $id): array
    {
        $imp = $this->buscar($id);
        $stmt = $this->pdo->prepare(
            "SELECT it.*, l.titulo AS livro_resultante_titulo, a.nome AS revisado_por_nome
             FROM importacao_itens it
             LEFT JOIN livros l ON l.id = it.livro_resultante_id
             LEFT JOIN administradores a ON a.id = it.revisado_por
             WHERE it.importacao_id = ? AND it.resultado_importacao = 'criado'
               AND JSON_UNQUOTE(JSON_EXTRACT(it.dados_corrigidos_json, '$.isbn')) = ''
             ORDER BY it.numero_linha"
        );
        $stmt->execute([$id]);
        $lista = [];
        foreach ($stmt->fetchAll() as $item) {
            $item = $this->decodificarItem($item);
            $item['isbn_original'] = $this->isbnOriginal($item, $imp);
            if ($item['isbn_original'] !== null) {
                $lista[] = $item;
            }
        }
        return $lista;
    }

    /**
     * Uma linha pode ser importada sem ISBN quando:
     *   * ainda não foi gravada no acervo e não foi marcada para ignorar ou vincular;
     *   * o ISBN inválido é o seu único erro e ela não tem possível duplicidade
     *     (as linhas com duplicidade esperam a decisão da bibliotecária);
     *   * sem o ISBN, a linha passa na validação.
     * Retirar um ISBN inválido não cria duplicidades novas: a chave de ISBN da linha
     * já era vazia (ISBN inválido não é comparado), e título/autor não mudam.
     * @return array{elegivel: bool, motivos: string[]}
     */
    public function avaliarSemIsbn(array $item, array $imp): array
    {
        if ($item['processado_em'] !== null) {
            return ['elegivel' => false, 'motivos' => [$item['resultado_importacao'] === 'falhou'
                ? 'A gravação anterior desta linha falhou. Revise-a individualmente.'
                : 'Esta linha já foi gravada no acervo.']];
        }
        $motivos = [];
        if (!in_array($item['acao_importacao'], [null, 'criar', 'revisar_depois'], true)) {
            $motivos[] = 'A linha já tem outra decisão ("' . $item['acao_importacao'] . '"). Altere-a na revisão individual.';
        }
        $problemas = $item['erros_json'] ?? [];
        if (!in_array('isbn_invalido', array_column($problemas, 'codigo'), true)) {
            $motivos[] = 'A linha não tem erro de ISBN.';
        }
        foreach ($problemas as $p) {
            if (in_array($p['nivel'], ['erro', 'duplicidade'], true) && $p['codigo'] !== 'isbn_invalido') {
                $motivos[] = $p['mensagem'];
            }
        }
        if (!$motivos) {
            [, $n] = $this->semIsbn($item, $imp);
            foreach ($this->validador->validarLinha($n) as $p) {
                if ($p['nivel'] === 'erro') {
                    $motivos[] = $p['mensagem'];
                }
            }
        }
        return ['elegivel' => !$motivos, 'motivos' => array_values(array_unique($motivos))];
    }

    /**
     * Importa as linhas escolhidas sem o ISBN inválido.
     *
     * Para cada linha: o ISBN é retirado só dos dados corrigidos (dados_corrigidos_json.isbn = ""),
     * o original continua em dados_originais_json e é copiado para registros_legados e para as
     * observações internas do livro. Tudo roda em uma transação, com um savepoint por linha:
     * uma linha que falha não leva as outras junto e volta a ficar pendente, sem alteração.
     * Linhas já gravadas são puladas (repetir a operação não duplica nada); linhas que não
     * cumprem as regras de avaliarSemIsbn() continuam pendentes.
     * Não cria exemplares nem classificações CDD e não altera livros existentes.
     *
     * @param int[] $itemIds
     * @return array{concluidos: array, pendentes: array, ja_importados: array, status: string}
     */
    public function importarSemIsbn(int $id, array $itemIds, int $usuarioId, string $ip = 'cli'): array
    {
        $itemIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $itemIds), fn ($v) => $v > 0))), 0, 500);
        if (!$itemIds) {
            throw new RuntimeException('Selecione pelo menos um registro.');
        }
        $resultado = $this->transacao(function () use ($id, $itemIds, $usuarioId, $ip) {
            $imp = $this->buscar($id, true); // bloqueia a importação: uma operação por vez
            if (!$imp || !in_array($imp['status'], self::STATUS_EDITAVEIS, true)) {
                throw new RuntimeException('Esta importação não está aberta para revisão.');
            }
            $stmt = $this->pdo->prepare(
                'SELECT * FROM importacao_itens WHERE importacao_id = ? AND id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ') ORDER BY numero_linha FOR UPDATE'
            );
            $stmt->execute([$id, ...$itemIds]);
            $itens = array_map([$this, 'decodificarItem'], $stmt->fetchAll());

            $res = ['concluidos' => [], 'pendentes' => [], 'ja_importados' => []];
            $encontrados = array_map('intval', array_column($itens, 'id'));
            foreach (array_diff($itemIds, $encontrados) as $faltando) {
                $res['pendentes'][] = ['item_id' => $faltando, 'linha' => null, 'titulo' => null, 'motivos' => ['Registro não encontrado nesta importação.']];
            }
            $corrigir = $this->pdo->prepare(
                'UPDATE importacao_itens SET dados_corrigidos_json = ?, dados_normalizados_json = ?, hash_linha = ?, chave_isbn = ?, chave_obra = ?,
                        status_validacao = ?, erros_json = ?, acao_importacao = \'criar\', acao_automatica = 0, livro_vinculado_id = NULL, item_referencia_id = NULL,
                        revisado_por = ?, revisado_em = NOW() WHERE id = ? AND processado_em IS NULL'
            );
            $marcar = $this->pdo->prepare(
                "UPDATE importacao_itens SET processado_em = NOW(), livro_resultante_id = ?, resultado_importacao = 'criado', mensagem_processamento = NULL WHERE id = ?"
            );
            $registrar = $this->pdo->prepare(
                "INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, 'importacao.importar_sem_isbn', 'importacao_item', ?, ?, ?)"
            );
            foreach ($itens as $item) {
                $resumo = ['item_id' => (int)$item['id'], 'linha' => (int)$item['numero_linha'], 'titulo' => $item['dados_normalizados_json']['titulo'] ?? null];
                if ($item['processado_em'] !== null && $item['resultado_importacao'] !== 'falhou') {
                    $res['ja_importados'][] = $resumo + ['livro_id' => (int)$item['livro_resultante_id']];
                    continue;
                }
                $avaliacao = $this->avaliarSemIsbn($item, $imp);
                if (!$avaliacao['elegivel']) {
                    $res['pendentes'][] = $resumo + ['motivos' => $avaliacao['motivos']];
                    continue;
                }
                $isbnOriginal = $this->isbnOriginal($item, $imp);
                $isbnRemovido = $item['dados_normalizados_json']['isbn_informado'] ?? $isbnOriginal;
                $this->pdo->exec('SAVEPOINT sem_isbn');
                try {
                    [$correcoes, $n] = $this->semIsbn($item, $imp);
                    $chaves = $this->validador->chaves($n);
                    // Mantém as duplicidades/avisos já calculados; troca só a validação da linha.
                    $problemas = array_merge(
                        $this->validador->validarLinha($n),
                        array_values(array_filter($item['erros_json'] ?? [], fn ($p) => $p['nivel'] === 'duplicidade' || in_array($p['codigo'], ['possui_copias', 'outra_edicao'], true)))
                    );
                    $corrigir->execute([$this->json($correcoes), $this->json($n), $chaves['hash'], $chaves['isbn'], $chaves['obra'],
                        ImportValidationService::situacao($problemas), $this->json($problemas), $usuarioId, $item['id']]);
                    $item = $this->decodificarItem($this->pdo->query('SELECT * FROM importacao_itens WHERE id = ' . (int)$item['id'])->fetch());
                    $item['isbn_removido'] = $isbnRemovido;
                    [$livroId] = $this->gravarItem($imp, $item, $usuarioId);
                    $marcar->execute([$livroId, $item['id']]);
                    $registrar->execute([$usuarioId, $item['id'], $this->json([
                        'importacao_id' => $id, 'linha' => (int)$item['numero_linha'], 'isbn_original' => $isbnOriginal,
                        'isbn_removido' => $isbnRemovido, 'livro_id' => $livroId,
                    ]), mb_substr($ip, 0, 45)]);
                    $this->pdo->exec('RELEASE SAVEPOINT sem_isbn');
                    $res['concluidos'][] = $resumo + ['livro_id' => $livroId, 'isbn_original' => $isbnOriginal];
                } catch (ItemNaoGravado | PDOException $e) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT sem_isbn');
                    if ($e instanceof PDOException) {
                        error_log('[biblioteca] Falha ao importar sem ISBN a linha ' . $item['numero_linha'] . ': ' . $e->getMessage());
                    }
                    $res['pendentes'][] = $resumo + ['motivos' => [$e instanceof ItemNaoGravado ? $e->getMessage() : $this->mensagemBanco($e)]];
                }
            }

            $this->atualizarTotais($id);
            $status = $imp['status'];
            if ($status === 'parcial') {
                $r = $this->resumoConfirmacao($id);
                if ($r['revisar_depois'] + $r['sem_decisao'] + $r['com_erro'] + $r['falharam'] + $r['a_gravar'] === 0) {
                    $this->pdo->prepare("UPDATE importacoes SET status = 'concluida', finalizado_em = NOW() WHERE id = ?")->execute([$id]);
                    $status = 'concluida';
                }
            }
            $res['status'] = $status;
            return $res;
        });
        if ($resultado['concluidos'] && in_array($resultado['status'], self::STATUS_EDITAVEIS, true)) {
            // Os livros novos entram na comparação das linhas que continuam pendentes.
            $this->recalcular($id);
        }
        return $resultado;
    }

    /** ISBN exatamente como veio na planilha (coluna mapeada para "isbn"). */
    private function isbnOriginal(array $item, ?array $imp): ?string
    {
        $bruto = $this->validador->mapear($item['dados_originais_json'] ?? [], $imp['mapeamento_json'] ?? [])['isbn'] ?? null;
        return $bruto !== null && trim($bruto) !== '' ? trim($bruto) : null;
    }

    /** Correções da linha com o ISBN retirado e os dados normalizados resultantes. */
    private function semIsbn(array $item, array $imp): array
    {
        $correcoes = $item['dados_corrigidos_json'] ?? [];
        $correcoes['isbn'] = '';
        $brutos = $this->validador->mapear($item['dados_originais_json'], $imp['mapeamento_json'] ?? []);
        return [$correcoes, $this->validador->aplicarCorrecoes($brutos, $correcoes)];
    }

    // ---------------------------------------------------------------
    // Confirmação
    // ---------------------------------------------------------------

    /** O que acontecerá ao confirmar (linhas ainda não gravadas). */
    public function resumoConfirmacao(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(processado_em IS NULL AND acao_importacao = 'criar'), 0) AS criar,
                COALESCE(SUM(processado_em IS NULL AND acao_importacao = 'vincular'), 0) AS vincular,
                COALESCE(SUM(processado_em IS NULL AND acao_importacao = 'outro_exemplar'), 0) AS outro_exemplar,
                COALESCE(SUM(acao_importacao = 'ignorar' AND processado_em IS NULL), 0) AS ignorar,
                COALESCE(SUM(processado_em IS NULL AND acao_importacao = 'revisar_depois'), 0) AS revisar_depois,
                COALESCE(SUM(processado_em IS NULL AND acao_importacao IS NULL), 0) AS sem_decisao,
                0 AS com_erro,
                COALESCE(SUM(resultado_importacao IN ('criado', 'vinculado', 'outro_exemplar')), 0) AS ja_gravadas,
                COALESCE(SUM(resultado_importacao = 'falhou'), 0) AS falharam
             FROM importacao_itens WHERE importacao_id = ?"
        );
        $stmt->execute([$id]);
        $r = array_map('intval', $stmt->fetch() ?: []);
        $r['a_gravar'] = $r['criar'] + $r['vincular'] + $r['outro_exemplar'];
        // "Importar todos e revisar depois": tudo o que ainda não foi gravado, menos o que alguém marcou para ignorar.
        $r['todos'] = $r['a_gravar'] + $r['revisar_depois'] + $r['sem_decisao'] + $r['falharam'];
        return $r;
    }

    /** Começa (ou retoma) a gravação no acervo. Só uma confirmação por vez. */
    public function iniciarConfirmacao(int $id): array
    {
        return $this->transacao(function () use ($id) {
            $imp = $this->buscar($id, true);
            if (!$imp) {
                throw new RuntimeException('Importação não encontrada.');
            }
            if ($imp['status'] === 'importando') {
                return $imp; // retomada após interrupção
            }
            if (!in_array($imp['status'], self::STATUS_EDITAVEIS, true)) {
                throw new RuntimeException('Esta importação não está pronta para confirmação.');
            }
            if ($this->resumoConfirmacao($id)['a_gravar'] === 0) {
                throw new RuntimeException('Não há linhas para gravar.');
            }
            $this->pdo->prepare("UPDATE importacoes SET status = 'importando', iniciado_em = COALESCE(iniciado_em, NOW()) WHERE id = ?")->execute([$id]);
            return $this->buscar($id);
        });
    }

    /**
     * Grava o próximo bloco de linhas aprovadas, em uma transação.
     * Cada linha é marcada como processada na mesma transação em que é gravada:
     * se a conexão cair, nada fica pela metade e nada é aplicado duas vezes.
     */
    public function confirmarLote(int $id, int $usuarioId, int $tamanho = 100): array
    {
        return $this->transacao(function () use ($id, $usuarioId, $tamanho) {
            $imp = $this->buscar($id, true); // bloqueia a importação: um lote por vez
            if (!$imp || $imp['status'] !== 'importando') {
                throw new RuntimeException('A gravação desta importação não está em andamento.');
            }
            $stmt = $this->pdo->prepare(
                "SELECT * FROM importacao_itens
                 WHERE importacao_id = ? AND processado_em IS NULL
                   AND acao_importacao IN ('criar', 'vincular', 'outro_exemplar')
                 ORDER BY FIELD(acao_importacao, 'criar', 'vincular', 'outro_exemplar'), numero_linha
                 LIMIT " . max(1, min(500, $tamanho)) . ' FOR UPDATE'
            );
            $stmt->execute([$id]);
            $itens = array_map([$this, 'decodificarItem'], $stmt->fetchAll());

            $gravados = 0;
            $falhas = 0;
            foreach ($itens as $item) {
                $this->pdo->exec('SAVEPOINT linha');
                try {
                    [$livroId, $resultado] = $this->gravarItem($imp, $item, $usuarioId);
                    $this->pdo->prepare(
                        'UPDATE importacao_itens SET processado_em = NOW(), livro_resultante_id = ?, resultado_importacao = ?, mensagem_processamento = NULL WHERE id = ?'
                    )->execute([$livroId, $resultado, $item['id']]);
                    $this->pdo->exec('RELEASE SAVEPOINT linha');
                    $gravados++;
                } catch (ItemNaoGravado | PDOException $e) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT linha');
                    $mensagem = $e instanceof ItemNaoGravado ? $e->getMessage() : $this->mensagemBanco($e);
                    if ($e instanceof PDOException) {
                        error_log('[biblioteca] Falha ao importar linha ' . $item['numero_linha'] . ': ' . $e->getMessage());
                    }
                    $this->pdo->prepare(
                        "UPDATE importacao_itens SET processado_em = NOW(), resultado_importacao = 'falhou', mensagem_processamento = ? WHERE id = ?"
                    )->execute([mb_substr($mensagem, 0, 500), $item['id']]);
                    $falhas++;
                }
            }

            $restantes = (int)$this->valor(
                "SELECT COUNT(*) FROM importacao_itens WHERE importacao_id = ? AND processado_em IS NULL
                 AND acao_importacao IN ('criar', 'vincular', 'outro_exemplar')",
                [$id]
            );
            $this->atualizarTotais($id);
            $concluida = false;
            if ($restantes === 0) {
                $r = $this->resumoConfirmacao($id);
                $pendentes = $r['revisar_depois'] + $r['sem_decisao'] + $r['com_erro'] + $r['falharam'];
                $this->pdo->prepare('UPDATE importacoes SET status = ?, finalizado_em = NOW() WHERE id = ?')
                    ->execute([$pendentes > 0 ? 'parcial' : 'concluida', $id]);
                $concluida = true;
            }
            return ['gravados' => $gravados, 'falhas' => $falhas, 'restantes' => $restantes, 'concluida' => $concluida];
        });
    }

    // ---------------------------------------------------------------
    // Importar todos e revisar depois
    // ---------------------------------------------------------------

    /** Filtro SQL: a linha tem ao menos um alerta do tipo informado (incompleto | inconsistencia | duplicidade). */
    private static function sqlTipo(string $tipo, string $alias = 'it.'): string
    {
        $tipo = in_array($tipo, ['incompleto', 'inconsistencia', 'duplicidade'], true) ? $tipo : 'outro';
        return "JSON_SEARCH({$alias}erros_json, 'one', '$tipo', NULL, '$[*].tipo') IS NOT NULL";
    }

    /**
     * Números da pré-visualização. Um registro pode ter mais de um tipo de alerta
     * (ex.: sem autor e com ISBN inválido), por isso os grupos podem se sobrepor.
     */
    public function previa(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status_validacao = 'valido'), 0) AS sem_alertas,
                    COALESCE(SUM(" . self::sqlTipo('incompleto', '') . "), 0) AS incompletos,
                    COALESCE(SUM(" . self::sqlTipo('inconsistencia', '') . " OR status_validacao = 'erro'), 0) AS inconsistencias,
                    COALESCE(SUM(status_validacao = 'duplicidade' OR " . self::sqlTipo('duplicidade', '') . "), 0) AS duplicidades,
                    COALESCE(SUM(ja_cadastrado), 0) AS ja_cadastrados
             FROM importacao_itens WHERE importacao_id = ?"
        );
        $stmt->execute([$id]);
        return array_map('intval', $stmt->fetch() ?: []);
    }

    /**
     * Grava no acervo TODAS as linhas ainda não gravadas (menos as que alguém marcou para ignorar),
     * mesmo as que têm alertas. Cada linha vira um livro, os alertas viram alertas cadastrais desse
     * livro e, se houver inventário em andamento (ou pausado), a linha entra nele como item "Pendente"
     * (sem exemplar ainda: o código BDP é registrado quando o livro é encontrado na estante).
     * Nada é inventado: campo ausente fica vazio (NULL). Nada é marcado como encontrado.
     *
     * Tudo roda em UMA transação: se acontecer uma falha técnica (queda do banco, disco cheio...),
     * nada desta planilha fica gravado e a importação volta para a pré-visualização, pronta para
     * tentar de novo. Repetir o pedido não duplica nada (linhas já gravadas são puladas).
     *
     * @param bool $repeticaoConfirmada a pessoa confirmou o aviso de importação repetida
     * @return array{criados: int, vinculados: int, ignorados: int, livros_com_pendencias: int, status: string}
     */
    public function importarTodos(int $id, int $usuarioId, bool $repeticaoConfirmada = false): array
    {
        $rep = $this->repeticoes($id);
        return $this->transacao(function () use ($id, $usuarioId, $repeticaoConfirmada, $rep) {
            $imp = $this->buscar($id, true); // bloqueia a importação: um pedido por vez
            if (!$imp) {
                throw new RuntimeException('Importação não encontrada.');
            }
            if ($imp['status'] === 'concluida') {
                return $this->resultadoImportacao($id, 'concluida');
            }
            if (!in_array($imp['status'], [...self::STATUS_EDITAVEIS, 'importando'], true)) {
                throw new RuntimeException('Esta importação não está pronta para ser gravada.');
            }
            if ($rep['repetida'] && !$repeticaoConfirmada) {
                throw new RuntimeException('Esta planilha parece já ter sido importada. Confirme que deseja importar de novo (os registros repetidos não serão mesclados nem apagados).');
            }
            if ($rep['repetida']) {
                $this->pdo->prepare('UPDATE importacoes SET repete_importacao_id = COALESCE(repete_importacao_id, ?), repeticao_confirmada_em = NOW(), repeticao_confirmada_por = ? WHERE id = ?')
                    ->execute([$rep['arquivo']['id'] ?? $rep['conteudo']['id'] ?? null, $usuarioId, $id]);
            }
            // Linhas sem decisão, deixadas para depois ou que falharam antes: entram como livro novo.
            $this->pdo->prepare(
                "UPDATE importacao_itens SET acao_importacao = 'criar', processado_em = NULL, resultado_importacao = NULL, mensagem_processamento = NULL
                 WHERE importacao_id = ? AND (processado_em IS NULL OR resultado_importacao = 'falhou')
                   AND (acao_importacao IS NULL OR acao_importacao = 'revisar_depois' OR resultado_importacao = 'falhou')
                   AND COALESCE(acao_importacao, '') <> 'ignorar'"
            )->execute([$id]);
            $this->pdo->prepare("UPDATE importacoes SET status = 'importando', iniciado_em = COALESCE(iniciado_em, NOW()), mensagem_erro = NULL WHERE id = ?")->execute([$id]);

            $marcar = $this->pdo->prepare('UPDATE importacao_itens SET processado_em = NOW(), livro_resultante_id = ?, resultado_importacao = ?, mensagem_processamento = ? WHERE id = ?');
            // Inventário em andamento ou pausado: cada linha gravada entra como item "Pendente" (sem exemplar).
            $inventarioId = (int)$this->valor("SELECT id FROM inventarios WHERE status IN ('em_andamento', 'pausado') ORDER BY id DESC LIMIT 1 FOR UPDATE") ?: null;
            $paraInventario = $this->pdo->prepare('INSERT IGNORE INTO inventario_itens (inventario_id, exemplar_id, livro_id, importacao_item_id, previsto) VALUES (?, NULL, ?, ?, 1)');
            $noInventario = 0;
            $ultimaOrdem = -1;
            $ultimaLinha = 0;
            do {
                // Primeiro os livros novos, depois vínculos e "outro exemplar" (que podem apontar para eles).
                $stmt = $this->pdo->prepare(
                    "SELECT *, FIELD(acao_importacao, 'criar', 'vincular', 'outro_exemplar') AS ordem FROM importacao_itens
                     WHERE importacao_id = ? AND processado_em IS NULL AND acao_importacao IN ('criar', 'vincular', 'outro_exemplar')
                       AND (FIELD(acao_importacao, 'criar', 'vincular', 'outro_exemplar') > ? OR (FIELD(acao_importacao, 'criar', 'vincular', 'outro_exemplar') = ? AND numero_linha > ?))
                     ORDER BY ordem, numero_linha LIMIT 500 FOR UPDATE"
                );
                $stmt->execute([$id, $ultimaOrdem, $ultimaOrdem, $ultimaLinha]);
                $lote = $stmt->fetchAll();
                foreach ($lote as $linha) {
                    $item = $this->decodificarItem($linha);
                    $mensagem = null;
                    try {
                        [$livroId, $resultado] = $this->gravarItem($imp, $item, $usuarioId);
                    } catch (ItemNaoGravado $e) {
                        // Vínculo impossível (livro arquivado, linha de referência ignorada...):
                        // a linha não é perdida, entra como livro novo e o motivo vira pendência.
                        $item['acao_importacao'] = 'criar';
                        $item['pendencia_extra'] = 'Não foi possível ligar esta linha ao livro escolhido (' . $e->getMessage() . '). Ela foi importada como livro separado: confira se é o mesmo livro.';
                        $this->pdo->prepare("UPDATE importacao_itens SET acao_importacao = 'criar' WHERE id = ?")->execute([$item['id']]);
                        [$livroId, $resultado] = $this->gravarItem($imp, $item, $usuarioId);
                        $mensagem = mb_substr($item['pendencia_extra'], 0, 500);
                    }
                    $marcar->execute([$livroId, $resultado, $mensagem, $item['id']]);
                    if ($inventarioId && $resultado !== 'vinculado') {
                        // "Vincular" = a mesma unidade de um livro já cadastrado (já está no inventário pelo exemplar).
                        $paraInventario->execute([$inventarioId, $livroId, $item['id']]);
                        $noInventario++;
                    }
                    $ultimaOrdem = (int)$linha['ordem'];
                    $ultimaLinha = (int)$linha['numero_linha'];
                }
            } while (count($lote) === 500);

            $this->atualizarTotais($id);
            $this->pdo->prepare("UPDATE importacoes SET status = 'concluida', finalizado_em = NOW() WHERE id = ?")->execute([$id]);
            return ['inventario_id' => $inventarioId, 'no_inventario' => $noInventario] + $this->resultadoImportacao($id, 'concluida');
        });
    }

    private function resultadoImportacao(int $id, string $status): array
    {
        $imp = $this->buscar($id);
        $comPendencias = (int)$this->valor(
            'SELECT COUNT(DISTINCT p.livro_id) FROM livro_pendencias p WHERE p.importacao_id = ? AND p.resolvida_em IS NULL',
            [$id]
        );
        return [
            'criados' => (int)$imp['total_importados'],
            'vinculados' => (int)$imp['total_vinculados'],
            'ignorados' => (int)$imp['total_ignorados'],
            'livros_com_pendencias' => $comPendencias,
            'status' => $status,
            'inventario_id' => null,
            'no_inventario' => 0,
        ];
    }

    /** Guarda os alertas da linha como alertas cadastrais do livro (conferidos no inventário). */
    private function registrarPendencias(int $livroId, array $imp, array $item, array $extras = []): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO livro_pendencias (livro_id, importacao_id, importacao_item_id, tipo, codigo, campo, mensagem) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $n = 0;
        $vistos = [];
        foreach ([...($item['erros_json'] ?? []), ...$extras] as $p) {
            if (!in_array($p['nivel'] ?? '', ['aviso', 'duplicidade', 'erro'], true)) {
                continue;
            }
            $tipo = self::tipoPendencia($p);
            $chave = $p['codigo'] . '|' . ($p['campo'] ?? '');
            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;
            $stmt->execute([$livroId, $imp['id'], $item['id'], $tipo, mb_substr((string)$p['codigo'], 0, 40), $p['campo'] ?? null,
                mb_substr('Linha ' . $item['numero_linha'] . ' da planilha: ' . $p['mensagem'], 0, 500)]);
            $n++;
        }
        return $n;
    }

    /** Tipo da pendência; alertas de importações antigas (sem "tipo") são classificados pelo código. */
    public static function tipoPendencia(array $p): string
    {
        if (isset($p['tipo']) && in_array($p['tipo'], ['incompleto', 'inconsistencia', 'duplicidade'], true)) {
            return $p['tipo'];
        }
        return match (true) {
            ($p['nivel'] ?? '') === 'duplicidade' => 'duplicidade',
            in_array($p['codigo'] ?? '', ['sem_titulo', 'sem_autor', 'dados_insuficientes', 'sem_dados_bibliograficos'], true) => 'incompleto',
            ($p['nivel'] ?? '') === 'erro', ($p['nivel'] ?? '') === 'aviso' => 'inconsistencia',
            default => 'outro',
        };
    }

    /** @return array{0: int, 1: string} [livro_id, resultado] */
    private function gravarItem(array $imp, array $item, int $usuarioId): array
    {
        $n = $item['dados_normalizados_json'];
        $acao = $item['acao_importacao'];

        if ($acao === 'criar') {
            $livroId = $this->criarLivro($imp, $item, $n);
            $resultado = 'criado';
        } elseif ($acao === 'vincular') {
            $livroId = (int)$item['livro_vinculado_id'];
            if (!$this->livroAtivo($livroId)) {
                throw new ItemNaoGravado('O livro escolhido para vínculo não existe mais ou foi arquivado.');
            }
            $resultado = 'vinculado';
        } else { // outro_exemplar
            if ($item['item_referencia_id']) {
                $livroId = (int)$this->valor('SELECT livro_resultante_id FROM importacao_itens WHERE id = ? AND resultado_importacao IN (\'criado\', \'vinculado\', \'outro_exemplar\')', [$item['item_referencia_id']]);
                if (!$livroId) {
                    throw new ItemNaoGravado('A linha de referência ainda não foi gravada no acervo. Importe-a primeiro.');
                }
            } else {
                $livroId = (int)$item['livro_vinculado_id'];
                if (!$this->livroAtivo($livroId)) {
                    throw new ItemNaoGravado('O livro de referência não existe mais ou foi arquivado.');
                }
            }
            $resultado = 'outro_exemplar';
        }

        $extras = [];
        if (!empty($item['pendencia_extra'])) {
            $extras[] = ['nivel' => 'aviso', 'tipo' => 'duplicidade', 'codigo' => 'vinculo_nao_feito', 'campo' => null, 'mensagem' => $item['pendencia_extra']];
        }
        if ($acao !== 'criar') {
            // Outra linha da planilha passou a apontar para este livro: fica o alerta para a conferência.
            $extras[] = ['nivel' => 'aviso', 'tipo' => 'duplicidade', 'codigo' => $acao === 'vincular' ? 'linha_vinculada' : 'outro_exemplar',
                'campo' => null, 'mensagem' => $acao === 'vincular'
                    ? 'Foi ligada a este livro. Confira se é o mesmo livro e registre o código BDP do exemplar.'
                    : 'Indica outro exemplar deste livro. Confira na estante e registre o código BDP de cada exemplar.'];
        }
        $this->registrarPendencias($livroId, $imp, $item, $extras);

        $this->pdo->prepare(
            'INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, titulo_original, autor_original, codigo_autor_antigo,
                localizacao_antiga, cdd_original, assunto_original, categoria_original, quantidade_informada, representa_exemplar, observacoes, dados_originais)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $imp['id'], $item['id'], $livroId, $imp['nome_arquivo'], $item['numero_linha'],
            $this->corte($n['titulo'], 500), $this->corte($n['autor'], 500), $this->corte($n['codigo_autor_antigo'], 50), $this->corte($n['localizacao_antiga'], 100),
            $this->corte($n['cdd_original'], 50), $this->corte($n['assunto_original'], 500), $this->corte($n['categoria_original'], 255),
            $n['quantidade_informada'] !== null && $n['quantidade_informada'] >= 0 && $n['quantidade_informada'] <= 2147483647 ? $n['quantidade_informada'] : null,
            $acao === 'outro_exemplar' ? 1 : 0, $n['observacoes'], $this->json($item['dados_originais_json']),
        ]);
        return [$livroId, $resultado];
    }

    /**
     * Cria o livro com o que a planilha trouxe. Nada é inventado: campo ausente fica NULL.
     * Valores que não cabem nas regras do acervo não impedem a gravação:
     *   * ISBN inválido → isbn NULL e o texto original em isbn_original;
     *   * ano inválido → ano NULL (o original fica nos dados da planilha e nas observações);
     *   * texto longo demais → o início do texto (o completo fica nos dados originais).
     * A situação do cadastro acompanha os alertas: dados incompletos, com divergência ou
     * aguardando conferência. A existência física só é confirmada no inventário.
     */
    private function criarLivro(array $imp, array $item, array $n): int
    {
        $isbn = $n['isbn'] !== null && Isbn::valido($n['isbn']) ? $n['isbn'] : null;
        $isbnOriginal = $isbn === null && ($n['isbn_informado'] ?? null) !== null ? $this->corte($n['isbn_informado'], 100) : null;
        $nota = 'Importado da planilha "' . $imp['nome_arquivo'] . '", linha ' . $item['numero_linha'] . '.';
        if (!empty($item['isbn_removido'])) {
            $nota .= ' ISBN informado na planilha (inválido, não gravado): "' . $item['isbn_removido'] . '". Confira no livro físico.';
            $isbnOriginal ??= $this->corte($item['isbn_removido'], 100);
        } elseif ($isbnOriginal !== null) {
            $nota .= ' ISBN da planilha (inválido ou incompleto, guardado para conferência): "' . $isbnOriginal . '".';
        }
        $anoMax = (int)date('Y') + 1;
        $ano = $n['ano_publicacao'] !== null && $n['ano_publicacao'] >= 1450 && $n['ano_publicacao'] <= $anoMax ? $n['ano_publicacao'] : null;
        if ($ano === null && ($n['ano_informado'] ?? null) !== null) {
            $nota .= ' Ano da planilha: "' . mb_substr($n['ano_informado'], 0, 40) . '".';
        }
        $tipos = array_map([self::class, 'tipoPendencia'], array_filter($item['erros_json'] ?? [], fn ($p) => in_array($p['nivel'] ?? '', ['aviso', 'duplicidade', 'erro'], true)));
        $status = match (true) {
            in_array('incompleto', $tipos, true) => 'dados_incompletos',
            in_array('inconsistencia', $tipos, true) || in_array('duplicidade', $tipos, true) || !empty($item['pendencia_extra']) => 'com_divergencia',
            default => 'aguardando_conferencia',
        };
        $titulo = $this->corte($n['titulo'], 255);
        $this->pdo->prepare(
            "INSERT INTO livros (titulo, subtitulo, isbn, isbn_original, editora, ano_publicacao, edicao, idioma, assunto, observacoes_internas,
                                 status_validacao, origem_cadastro)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planilha')"
        )->execute([
            $titulo !== null && trim($titulo) !== '' ? $titulo : null,
            $this->corte($n['subtitulo'], 255), $isbn, $isbnOriginal, $this->corte($n['editora'], 150), $ano, $this->corte($n['edicao'], 60),
            $this->corte($n['idioma'], 30),
            $this->corte($n['assunto_original'], 255),
            $nota,
            $status,
        ]);
        $livroId = (int)$this->pdo->lastInsertId();

        $autorStmt = $this->pdo->prepare('INSERT INTO autores (nome) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        $vinculo = $this->pdo->prepare('INSERT IGNORE INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, ?)');
        foreach (array_slice($n['autores'] ?? [], 0, 10) as $ordem => $nome) {
            $autorStmt->execute([$this->corte($nome, 150)]);
            $vinculo->execute([$livroId, (int)$this->pdo->lastInsertId(), $ordem + 1]);
        }
        return $livroId;
    }

    public function cancelar(int $id): void
    {
        $this->transacao(function () use ($id) {
            $imp = $this->buscar($id, true);
            if (!$imp || in_array($imp['status'], ['concluida', 'cancelada'], true)) {
                throw new RuntimeException('Esta importação não pode ser cancelada.');
            }
            $this->pdo->prepare("UPDATE importacoes SET status = 'cancelada', finalizado_em = NOW() WHERE id = ?")->execute([$id]);
        });
    }

    // ---------------------------------------------------------------
    // Mapeamentos salvos
    // ---------------------------------------------------------------

    public function mapeamentosSalvos(): array
    {
        $lista = $this->pdo->query('SELECT id, nome, mapeamento_json, atualizado_em FROM importacao_mapeamentos ORDER BY nome')->fetchAll();
        foreach ($lista as &$m) {
            $m['mapeamento'] = json_decode($m['mapeamento_json'], true);
            unset($m['mapeamento_json']);
        }
        return $lista;
    }

    public function mapeamentoSalvo(int $id): ?array
    {
        $json = $this->valor('SELECT mapeamento_json FROM importacao_mapeamentos WHERE id = ?', [$id]);
        return $json ? json_decode($json, true) : null;
    }

    /** Salva pelo cabeçalho normalizado, para funcionar em planilhas com pequenas diferenças de escrita. */
    public function salvarMapeamento(string $nome, array $mapeamento, int $usuarioId): int
    {
        $porChave = [];
        foreach ($mapeamento as $cabecalho => $campo) {
            $porChave[Texto::chave($cabecalho)] = $campo;
        }
        $this->pdo->prepare(
            'INSERT INTO importacao_mapeamentos (nome, mapeamento_json, criado_por) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE mapeamento_json = VALUES(mapeamento_json), id = LAST_INSERT_ID(id)'
        )->execute([mb_substr(trim($nome), 0, 120), $this->json($porChave), $usuarioId]);
        return (int)$this->pdo->lastInsertId();
    }

    // ---------------------------------------------------------------
    // Relatórios
    // ---------------------------------------------------------------

    /** Percorre todas as linhas (para CSV), em blocos, sem carregar tudo na memória. */
    public function percorrerItens(int $id, bool $somenteProblemas, callable $fn): void
    {
        $ultimo = 0;
        do {
            $stmt = $this->pdo->prepare(
                'SELECT it.*, l.titulo AS livro_resultante_titulo FROM importacao_itens it LEFT JOIN livros l ON l.id = it.livro_resultante_id
                 WHERE it.importacao_id = ? AND it.numero_linha > ?' . ($somenteProblemas ? " AND (it.status_validacao <> 'valido' OR it.resultado_importacao = 'falhou')" : '') .
                ' ORDER BY it.numero_linha LIMIT 500'
            );
            $stmt->execute([$id, $ultimo]);
            $lote = $stmt->fetchAll();
            foreach ($lote as $item) {
                $fn($this->decodificarItem($item));
                $ultimo = (int)$item['numero_linha'];
            }
        } while (count($lote) === 500);
    }

    // ---------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------

    private function livroAtivo(int $id): bool
    {
        return $id > 0 && (bool)$this->valor('SELECT 1 FROM livros WHERE id = ? AND arquivado_em IS NULL', [$id]);
    }

    private function valor(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function json(mixed $dados): string
    {
        return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function corte(?string $texto, int $limite): ?string
    {
        return $texto === null ? null : mb_substr($texto, 0, $limite);
    }

    private function mensagemBanco(PDOException $e): string
    {
        $codigo = (int)($e->errorInfo[1] ?? 0);
        return match ($codigo) {
            1062 => 'O banco recusou um registro repetido. Tente novamente; se continuar, avise o responsável pelo sistema.',
            3819, 4025 => 'Algum valor não passou nas regras do acervo. Tente novamente; se continuar, avise o responsável pelo sistema.',
            1406 => 'Algum texto é longo demais para o campo do acervo. Avise o responsável pelo sistema.',
            default => 'Não foi possível gravar esta linha por uma falha técnica. Tente novamente.',
        };
    }

    /** Executa em transação, reaproveitando uma transação já aberta. */
    private function transacao(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $resultado = $fn();
            $this->pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
