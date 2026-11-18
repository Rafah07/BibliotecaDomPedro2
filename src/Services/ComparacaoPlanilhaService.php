<?php

namespace Biblioteca\Services;

use Biblioteca\Repositories\PendenciasRepository;
use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Isbn;
use Biblioteca\Support\Texto;
use PDO;

/**
 * "Comparar com planilha original" (migração 180).
 *
 * Compara, nas DUAS direções, a planilha original importada (registros_legados + importacao_itens,
 * que são imutáveis) com o acervo atual (obras, exemplares e o inventário escolhido):
 *   * Planilha → acervo: cada registro (linha) da planilha e a obra a que está associado hoje.
 *   * Acervo → planilha: cada obra ativa e os registros dela (ou a falta deles).
 *   * Exemplares: só os que têm associação bibliográfica inconsistente.
 *
 * Regras importantes:
 *   * Abrir, filtrar, exportar e atualizar a comparação NÃO grava nada. Só gravam as ações
 *     confirmadas por quem tem permissão (decisões, resultado salvo, associação de registro e
 *     correção da obra de um exemplar).
 *   * Uma linha da planilha descreve a OBRA e vale para várias cópias (migração 170): exemplar
 *     sem vínculo direto com uma linha NÃO é problema; linha não conta como exemplar físico.
 *   * Obra cadastrada manualmente que não está na planilha é "Obra nova" (não é erro). Só vira
 *     "Possível correspondência" quando os dados combinam com um registro da planilha.
 *   * Dados alterados depois da importação são "Dados corrigidos" (não são erro).
 *   * As correspondências são só SUGESTÕES (ISBN, título, subtítulo, autor, editora, edição e
 *     ano, tolerando maiúsculas, acentos, espaços, pontuação, pequenos erros de digitação e
 *     hífens do ISBN). Nada é mesclado automaticamente; edições diferentes podem ser obras
 *     diferentes.
 *   * Durante um inventário em andamento, "sem exemplar" ou "não encontrado" nunca é perda.
 */
final class ComparacaoPlanilhaService
{
    use ConsultasPdo;

    /** código => [rótulo, tom, exige atenção] */
    public const CATEGORIAS = [
        'correspondencia_confirmada' => ['Correspondência confirmada', 'green', false],
        'dados_corrigidos' => ['Dados corrigidos', 'teal', false],
        'aguardando_verificacao' => ['Aguardando verificação', 'amber', false],
        'sem_exemplar' => ['Sem exemplar registrado', 'muted', false],
        'obra_nova' => ['Obra nova', 'blue', false],
        'possivel_correspondencia' => ['Possível correspondência', 'indigo', true],
        'possivel_duplicidade' => ['Possível duplicidade', 'amber', true],
        'vinculo_inconsistente' => ['Vínculo inconsistente', 'red', true],
        'divergencia_quantidade' => ['Divergência de quantidade', 'amber', true],
        'sem_correspondencia' => ['Sem correspondência no acervo', 'red', true],
    ];

    public const DIRECOES = [
        'planilha' => 'Planilha → acervo',
        'acervo' => 'Acervo → planilha',
        'exemplar' => 'Exemplares',
    ];

    public const DECISOES = [
        'confirmar_correspondencia' => 'Correspondência confirmada',
        'confirmar_obra_nova' => 'Obra nova confirmada',
        'nao_duplicidade' => 'Não é duplicidade',
        'resolvida' => 'Marcada como resolvida',
        'reaberta' => 'Reaberta',
    ];

    /** Campos do quadro "lado a lado". */
    public const CAMPOS = ['titulo' => 'Título', 'subtitulo' => 'Subtítulo', 'autor' => 'Autor', 'isbn' => 'ISBN', 'editora' => 'Editora',
        'ano' => 'Ano', 'edicao' => 'Edição', 'cdd' => 'CDD'];

    private const PALAVRAS_VAZIAS = ['para', 'com', 'sem', 'uma', 'umas', 'uns', 'dos', 'das', 'nos', 'nas', 'pelo', 'pela', 'sobre', 'entre', 'livro', 'volume', 'edicao', 'the', 'and'];
    private const LIMITE_BLOCO = 400;

    /** @var array<int, array> obras (inclusive arquivadas) por id */
    private array $obras = [];
    /** @var array<string, list<int>> índices de obras ativas */
    private array $idxIsbn = [];
    private array $idxTitulo = [];
    private array $idxPalavra = [];

    public function __construct(private PDO $pdo)
    {
    }

    public static function rotulo(string $categoria): string
    {
        return self::CATEGORIAS[$categoria][0] ?? $categoria;
    }

    /** Importações que deixaram registros da planilha no acervo. */
    public function importacoes(): array
    {
        return $this->todas('SELECT i.id, i.nome_arquivo, i.criado_em, i.finalizado_em, i.status, i.total_linhas, COUNT(r.id) AS registros, MIN(r.importado_em) AS importado_em
                             FROM importacoes i JOIN registros_legados r ON r.importacao_id = i.id
                             GROUP BY i.id, i.nome_arquivo, i.criado_em, i.finalizado_em, i.status, i.total_linhas ORDER BY i.criado_em, i.id');
    }

    // ---------------------------------------------------------------
    // Comparação (somente leitura)
    // ---------------------------------------------------------------

    /**
     * Calcula a comparação. Não grava nada.
     * @param list<int> $importacaoIds vazio = todas as importações
     * @param list<array>|null $linhasArquivo linhas de uma planilha enviada só para comparação (nada é gravado)
     * @return array{gerado_em: string, inventario: ?array, importacoes: list<array>, fonte: string, itens: list<array>, indicadores: array}
     */
    public function analisar(array $importacaoIds = [], ?int $inventarioId = null, ?array $linhasArquivo = null): array
    {
        $todasImportacoes = $this->importacoes();
        $ids = array_values(array_intersect(array_map('intval', $importacaoIds), array_map(fn ($i) => (int)$i['id'], $todasImportacoes)));
        $selecionadas = $ids ? array_values(array_filter($todasImportacoes, fn ($i) => in_array((int)$i['id'], $ids, true))) : $todasImportacoes;
        $inventario = $inventarioId ? $this->linha('SELECT id, titulo, status, data_inicio, iniciado_em FROM inventarios WHERE id = ?', [$inventarioId]) : null;
        $invId = $inventario ? (int)$inventario['id'] : null;
        $emAndamento = $inventario && in_array($inventario['status'], ['em_andamento', 'pausado', 'aberto'], true);

        $this->carregarObras($invId);
        $registros = $this->carregarRegistros();
        $resolucoes = $this->carregarResolucoes();

        // Registros por obra (todas as importações: uma obra que veio de outro arquivo não é "obra nova").
        $regPorObra = [];
        $movidosDe = []; // obra original => obras para onde os registros foram vinculados
        foreach ($registros as $r) {
            $regPorObra[(int)$r['livro_id']][] = $r;
            if ($r['livro_original_id'] !== null && (int)$r['livro_original_id'] !== (int)$r['livro_id']) {
                $movidosDe[(int)$r['livro_original_id']][(int)$r['livro_id']] = true;
            }
        }
        $fonte = 'importacoes';
        if ($linhasArquivo !== null) {
            $fonte = 'arquivo';
            $registrosPlanilha = $linhasArquivo;
        } else {
            $registrosPlanilha = $ids ? array_values(array_filter($registros, fn ($r) => in_array((int)$r['importacao_id'], $ids, true))) : $registros;
        }

        $itens = [];
        // ---------- Planilha → acervo ----------
        foreach ($registrosPlanilha as $r) {
            $itens[] = $this->itemRegistro($r, $resolucoes, $emAndamento, $fonte === 'arquivo');
        }
        // Índice dos registros da planilha escolhida (para obras manuais que provavelmente estavam nela).
        $idxReg = ['isbn' => [], 'titulo' => [], 'palavra' => []];
        foreach ($registrosPlanilha as $pos => $r) {
            $n = $this->normalizar($r['titulo'], $r['autor'], $r['isbn']);
            if ($n['isbn'] !== '') {
                $idxReg['isbn'][$n['isbn']][] = $pos;
            }
            if ($n['tk'] !== '') {
                $idxReg['titulo'][$n['tk']][] = $pos;
                foreach ($n['palavras'] as $p) {
                    $idxReg['palavra'][$p][] = $pos;
                }
            }
        }

        // ---------- Acervo → planilha ----------
        $duplicidades = $this->duplicidades($resolucoes);
        foreach ($this->obras as $o) {
            if ($o['arquivado_em'] !== null) {
                continue;
            }
            $itens[] = $this->itemObra($o, $regPorObra[(int)$o['id']] ?? [], $movidosDe[(int)$o['id']] ?? [], $duplicidades[(int)$o['id']] ?? [],
                $registrosPlanilha, $idxReg, $resolucoes, $emAndamento, $fonte === 'arquivo');
        }

        // ---------- Exemplares com associação inconsistente ----------
        foreach ($this->exemplaresInconsistentes($invId, $movidosDe, $resolucoes) as $it) {
            $itens[] = $it;
        }

        return [
            'gerado_em' => date('Y-m-d H:i:s'),
            'inventario' => $inventario,
            'importacoes' => $selecionadas,
            'fonte' => $fonte,
            'itens' => $itens,
            'indicadores' => $this->indicadores($itens, $invId, count($registrosPlanilha)),
        ];
    }

    /**
     * Filtra os itens já calculados (pesquisa, categoria, direção, verificação, origem, lote, vínculo, atenção).
     * @return list<array>
     */
    public static function filtrar(array $itens, array $f): array
    {
        $termos = array_values(array_filter(preg_split('/\s+/u', Texto::chave((string)($f['q'] ?? ''))) ?: [], fn ($t) => $t !== ''));
        $qBruto = trim((string)($f['q'] ?? ''));
        $codigo = preg_match('/^bdp[\s-]*(\d+)$/i', $qBruto, $m) ? 'BDP-' . str_pad($m[1], 6, '0', STR_PAD_LEFT) : null;
        $isbn = Isbn::normalizar($qBruto);
        $linha = preg_match('/^(?:linha\s*)?(\d{1,7})$/iu', $qBruto, $ml) ? (int)$ml[1] : null;
        return array_values(array_filter($itens, function ($i) use ($f, $termos, $codigo, $isbn, $linha) {
            if (($f['direcao'] ?? '') !== '' && $i['direcao'] !== $f['direcao']) {
                return false;
            }
            if (($f['categoria'] ?? '') !== '' && !in_array($f['categoria'], $i['categorias'], true)) {
                return false;
            }
            if (($f['atencao'] ?? '') === '1' && !$i['atencao']) {
                return false;
            }
            if (($f['verificacao'] ?? '') !== '' && ($i['verificada'] === null || ($f['verificacao'] === 'verificada') !== $i['verificada'])) {
                return false;
            }
            if (($f['origem'] ?? '') !== '' && $i['origem'] !== $f['origem']) {
                return false;
            }
            if ((int)($f['lote'] ?? 0) > 0 && !in_array((int)$f['lote'], $i['lotes'], true)) {
                return false;
            }
            if (($f['vinculo'] ?? '') !== '' && $i['vinculo'] !== $f['vinculo']) {
                return false;
            }
            if ((int)($f['importacao'] ?? 0) > 0 && !in_array((int)$f['importacao'], $i['importacoes'], true)) {
                return false;
            }
            if ($termos) {
                if ($codigo !== null && in_array($codigo, $i['codigos'], true)) {
                    return true;
                }
                if ($linha !== null && in_array($linha, $i['linhas'], true)) {
                    return true;
                }
                if (strlen($isbn) >= 10 && ($i['isbn_chave'] === Isbn::para13($isbn) || $i['isbn_chave'] === $isbn)) {
                    return true;
                }
                $alvo = $i['busca'];
                foreach ($termos as $t) {
                    if (!str_contains($alvo, $t)) {
                        return false;
                    }
                }
            }
            return true;
        }));
    }

    /** Contagem de itens por categoria (de uma direção, ou de todas). */
    public static function contarCategorias(array $itens, string $direcao = ''): array
    {
        $c = array_fill_keys(array_keys(self::CATEGORIAS), 0);
        foreach ($itens as $i) {
            if ($direcao !== '' && $i['direcao'] !== $direcao) {
                continue;
            }
            foreach ($i['categorias'] as $cat) {
                $c[$cat] = ($c[$cat] ?? 0) + 1;
            }
        }
        return $c;
    }

    /**
     * Quadro "lado a lado" de um registro da planilha (ou do primeiro registro de uma obra).
     * @return array{registro: ?array, obra: ?array, linhas: list<array>, campos: list<array>, historico: list<array>}
     */
    public function detalhe(string $tipo, int $id): array
    {
        $registro = null;
        $obra = null;
        if ($tipo === 'registro') {
            $registro = $this->registroCompleto($id);
            $obra = $registro ? $this->obraCompleta((int)$registro['livro_id']) : null;
        } elseif ($tipo === 'obra') {
            $obra = $this->obraCompleta($id);
            $primeiro = $obra ? $this->valor('SELECT id FROM registros_legados WHERE livro_id = ? ORDER BY linha, id LIMIT 1', [$id]) : null;
            $registro = $primeiro ? $this->registroCompleto((int)$primeiro) : null;
        } elseif ($tipo === 'exemplar') {
            $ex = $this->linha('SELECT id, livro_id FROM exemplares WHERE id = ?', [$id]);
            $obra = $ex ? $this->obraCompleta((int)$ex['livro_id']) : null;
            $rid = $ex ? $this->valor('SELECT id FROM registros_legados WHERE exemplar_id = ?', [$id]) : null;
            $registro = $rid ? $this->registroCompleto((int)$rid) : null;
        }
        $campos = [];
        foreach (self::CAMPOS as $campo => $rotulo) {
            $a = $registro[$campo] ?? null;
            $b = $obra[$campo] ?? null;
            $campos[] = ['campo' => $campo, 'rotulo' => $rotulo, 'planilha' => $a, 'atual' => $b, 'alterado' => $registro !== null && $obra !== null && self::diferente($campo, $a, $b)];
        }
        $linhas = $obra ? $this->todas('SELECT r.id, r.arquivo, r.linha, r.titulo_original, r.quantidade_informada, r.importado_em, r.livro_original_id
                                        FROM registros_legados r WHERE r.livro_id = ? ORDER BY r.arquivo, r.linha LIMIT 200', [(int)$obra['id']]) : [];
        return ['registro' => $registro, 'obra' => $obra, 'linhas' => $linhas, 'campos' => $campos, 'historico' => $this->historico($tipo, $id, $obra ? (int)$obra['id'] : null, $registro ? (int)$registro['id'] : null)];
    }

    // ---------------------------------------------------------------
    // Ações confirmadas
    // ---------------------------------------------------------------

    /** Registra uma decisão explícita sobre um item da comparação. */
    public function decidir(string $alvoTipo, int $alvoId, string $categoria, string $decisao, int $administradorId, string $observacao = '', ?int $alvo2Id = null): int
    {
        if (!in_array($alvoTipo, ['registro', 'obra', 'exemplar'], true) || $alvoId <= 0) {
            throw new AcervoException('Item da comparação inválido.');
        }
        if (!isset(self::CATEGORIAS[$categoria]) || !isset(self::DECISOES[$decisao])) {
            throw new AcervoException('Escolha uma decisão válida.');
        }
        $obs = self::textoLimpo($observacao, 500);
        if ($decisao === 'nao_duplicidade') {
            if ($alvoTipo !== 'obra' || !$alvo2Id || $alvo2Id === $alvoId) {
                throw new AcervoException('Indique as duas obras que não são duplicadas.');
            }
            [$alvoId, $alvo2Id] = [min($alvoId, $alvo2Id), max($alvoId, $alvo2Id)];
        } else {
            $alvo2Id = null;
        }
        if ($decisao === 'confirmar_obra_nova' && $alvoTipo !== 'obra') {
            throw new AcervoException('Só uma obra pode ser confirmada como obra nova.');
        }
        $tabela = ['registro' => 'registros_legados', 'obra' => 'livros', 'exemplar' => 'exemplares'][$alvoTipo];
        if (!$this->valor("SELECT 1 FROM $tabela WHERE id = ?", [$alvoId]) || ($alvo2Id && !$this->valor('SELECT 1 FROM livros WHERE id = ?', [$alvo2Id]))) {
            throw new AcervoException('O item não foi encontrado. Atualize a comparação.');
        }
        return $this->transacao(function () use ($alvoTipo, $alvoId, $alvo2Id, $categoria, $decisao, $obs, $administradorId) {
            $this->executar('INSERT INTO comparacao_resolucoes (alvo_tipo, alvo_id, alvo2_id, categoria, decisao, observacao, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$alvoTipo, $alvoId, $alvo2Id, $categoria, $decisao, $obs, $administradorId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->registrarAcao($administradorId, 'comparacao.decisao', $alvoTipo === 'obra' ? 'livro' : $alvoTipo, $alvoId,
                ['categoria' => $categoria, 'decisao' => $decisao, 'outra_obra' => $alvo2Id, 'observacao' => $obs]);
            return $id;
        });
    }

    /**
     * Corrige a obra associada a um exemplar. O exemplar continua o MESMO: mesmo id, código BDP, lote,
     * carimbo, localização, conservação, ocorrências, empréstimos e histórico. Nenhum código novo é gerado.
     * Recusa exemplar baixado ou com empréstimo em aberto.
     * @return array{exemplar: array, anterior: int, nova: int, linhas_desligadas: int, itens_inventario: int}
     */
    public function corrigirObraExemplar(int $exemplarId, int $destinoId, int $administradorId, bool $confirmado, string $motivo, ?int $inventarioId = null): array
    {
        if (!$confirmado) {
            throw new AcervoException('Confirme que este exemplar físico é da obra escolhida. O código BDP continua o mesmo.');
        }
        $motivo = self::textoLimpo($motivo, 500);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo da correção (ex.: a etiqueta foi colocada no livro errado).');
        }
        return $this->transacao(function () use ($exemplarId, $destinoId, $administradorId, $motivo, $inventarioId) {
            $ex = $this->linha('SELECT id, livro_id, codigo_individual, situacao, lote_id FROM exemplares WHERE id = ? FOR UPDATE', [$exemplarId]);
            if (!$ex) {
                throw new AcervoException('Exemplar não encontrado.');
            }
            $anterior = (int)$ex['livro_id'];
            if ($anterior === $destinoId) {
                throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' já está nesta obra.');
            }
            if ($ex['situacao'] === 'baixado') {
                throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' foi baixado. Exemplares baixados não mudam de obra.');
            }
            $emprestimo = $this->valor('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$exemplarId]);
            if ($ex['situacao'] === 'emprestado' || $emprestimo) {
                throw new AcervoException('O exemplar ' . $ex['codigo_individual'] . ' está emprestado. Registre a devolução antes de corrigir a obra, para o empréstimo em aberto não mudar de livro.');
            }
            foreach ([min($anterior, $destinoId), max($anterior, $destinoId)] as $lid) {
                $this->linha('SELECT id FROM livros WHERE id = ? FOR UPDATE', [$lid]);
            }
            $destino = $this->linha('SELECT id, titulo, arquivado_em FROM livros WHERE id = ?', [$destinoId]);
            if (!$destino || $destino['arquivado_em'] !== null) {
                throw new AcervoException('A obra escolhida não foi encontrada ou está arquivada.');
            }
            $origem = $this->linha('SELECT id, titulo FROM livros WHERE id = ?', [$anterior]);

            $this->executar('SET @bdp_corrigir_obra_exemplar = ?', [$exemplarId]);
            try {
                $this->executar('UPDATE exemplares SET livro_id = ? WHERE id = ?', [$destinoId, $exemplarId]);
                // Inventários em andamento/pausados: o item deste exemplar acompanha a obra.
                $itens = $this->todas("SELECT i.id, i.inventario_id, i.conferido_em, i.importacao_item_id FROM inventario_itens i
                                       JOIN inventarios v ON v.id = i.inventario_id AND v.status IN ('em_andamento', 'pausado')
                                       WHERE i.exemplar_id = ? FOR UPDATE", [$exemplarId]);
                foreach ($itens as $it) {
                    $this->executar('UPDATE inventario_itens SET livro_id = ? WHERE id = ?', [$destinoId, (int)$it['id']]);
                }
            } finally {
                $this->executar('SET @bdp_corrigir_obra_exemplar = NULL');
            }

            // Vínculo antigo (anterior à migração 170) com uma linha da obra anterior: desfeito, com histórico.
            $desligadas = 0;
            $reg = $this->linha('SELECT id, livro_id, importacao_item_id FROM registros_legados WHERE exemplar_id = ? FOR UPDATE', [$exemplarId]);
            if ($reg && (int)$reg['livro_id'] !== $destinoId) {
                $this->executar('UPDATE registros_legados SET exemplar_id = NULL, vinculado_em = NULL, vinculado_por = NULL WHERE id = ?', [(int)$reg['id']]);
                $this->executar('INSERT INTO registro_vinculos (registro_id, acao, livro_anterior_id, livro_novo_id, exemplar_id, inventario_id, observacao, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [(int)$reg['id'], 'desfazer_exemplar', (int)$reg['livro_id'], (int)$reg['livro_id'], $exemplarId, $inventarioId,
                        'Obra do exemplar corrigida para a obra nº ' . $destinoId . ': ' . $motivo, $administradorId]);
                $this->executar("UPDATE inventario_itens i JOIN inventarios v ON v.id = i.inventario_id AND v.status IN ('em_andamento', 'pausado')
                                 SET i.importacao_item_id = NULL WHERE i.exemplar_id = ? AND i.importacao_item_id = ?", [$exemplarId, (int)$reg['importacao_item_id']]);
                $desligadas = 1;
            }
            // "Obra localizada" nos inventários abertos: vale para a obra nova; a anterior perde a marca se
            // este era o único exemplar dela encontrado ali.
            foreach ($itens as $it) {
                if ($it['conferido_em'] === null) {
                    continue;
                }
                $inv = (int)$it['inventario_id'];
                $this->executar('UPDATE inventario_itens SET obra_localizada_em = NOW(), obra_localizada_por = ?, nao_localizado_em = NULL, nao_localizado_por = NULL, nao_localizado_obs = NULL
                                 WHERE inventario_id = ? AND livro_id = ? AND exemplar_id IS NULL AND conferido_em IS NULL AND obra_localizada_em IS NULL', [$administradorId, $inv, $destinoId]);
                $outro = $this->valor('SELECT 1 FROM inventario_itens WHERE inventario_id = ? AND livro_id = ? AND exemplar_id IS NOT NULL AND conferido_em IS NOT NULL LIMIT 1', [$inv, $anterior]);
                if (!$outro) {
                    $this->executar('UPDATE inventario_itens SET obra_localizada_em = NULL, obra_localizada_por = NULL WHERE inventario_id = ? AND livro_id = ? AND exemplar_id IS NULL AND obra_localizada_em IS NOT NULL', [$inv, $anterior]);
                }
            }

            $this->executar('INSERT INTO exemplar_obra_correcoes (exemplar_id, codigo_individual, livro_anterior_id, livro_novo_id, motivo, inventario_id, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$exemplarId, $ex['codigo_individual'], $anterior, $destinoId, $motivo, $inventarioId, $administradorId]);
            $detalhes = ['antes' => ['livro_id' => $anterior, 'titulo' => $origem['titulo'] ?? null], 'depois' => ['livro_id' => $destinoId, 'titulo' => $destino['titulo']],
                'codigo' => $ex['codigo_individual'], 'motivo' => $motivo, 'linha_desligada' => $desligadas ? (int)$reg['id'] : null];
            $this->executar('INSERT INTO movimentacoes (exemplar_id, inventario_id, tipo, descricao, detalhes, responsavel_id) VALUES (?, ?, ?, ?, ?, ?)', [
                $exemplarId, $inventarioId, 'obra', mb_substr('Obra corrigida: de “' . ($origem['titulo'] ?? 'Sem título') . '” (nº ' . $anterior . ') para “' . ($destino['titulo'] ?? 'Sem título') . '” (nº ' . $destinoId . '). O código ' . $ex['codigo_individual'] . ' continua o mesmo. Motivo: ' . $motivo, 0, 1000),
                json_encode($detalhes, JSON_UNESCAPED_UNICODE), $administradorId]);
            $this->registrarAcao($administradorId, 'exemplar.corrigir_obra', 'exemplar', $exemplarId, $detalhes);
            return ['exemplar' => $ex, 'anterior' => $anterior, 'nova' => $destinoId, 'linhas_desligadas' => $desligadas, 'itens_inventario' => count($itens)];
        });
    }

    /**
     * "Associar registro a obra existente": a linha da planilha passa para a obra verificada escolhida.
     * Os dados originais não mudam (a obra anterior fica em livro_original_id) e tudo vai para o histórico.
     * Usa o mesmo serviço da conferência; para um registro cuja obra foi arquivada, faz o mesmo vínculo.
     */
    public function associarRegistro(int $registroId, int $destinoId, int $administradorId, bool $confirmado, string $observacao = '', ?int $inventarioId = null): void
    {
        $reg = $this->linha('SELECT r.id, r.livro_id, l.arquivado_em FROM registros_legados r JOIN livros l ON l.id = r.livro_id WHERE r.id = ?', [$registroId]);
        if (!$reg) {
            throw new AcervoException('Registro da planilha não encontrado.');
        }
        $obs = self::textoLimpo($observacao, 500) ?? 'Associado na comparação com a planilha original.';
        if ($reg['arquivado_em'] === null) {
            (new VinculoObraService($this->pdo))->vincular((int)$reg['livro_id'], $destinoId, [$registroId], $administradorId, $confirmado, $inventarioId, $obs);
            return;
        }
        if (!$confirmado) {
            throw new AcervoException('Confirme que o registro da planilha descreve a MESMA obra e a MESMA edição da obra escolhida.');
        }
        $this->transacao(function () use ($registroId, $destinoId, $administradorId, $obs, $inventarioId) {
            $r = $this->linha('SELECT * FROM registros_legados WHERE id = ? FOR UPDATE', [$registroId]);
            $destino = $this->linha('SELECT id, arquivado_em, dados_verificados_em FROM livros WHERE id = ? FOR UPDATE', [$destinoId]);
            if (!$destino || $destino['arquivado_em'] !== null) {
                throw new AcervoException('A obra escolhida não foi encontrada ou está arquivada.');
            }
            if ($destino['dados_verificados_em'] === null) {
                throw new AcervoException('Só é possível associar a uma obra com "Dados verificados". Verifique os dados dela antes.');
            }
            if ((int)$r['livro_id'] === $destinoId) {
                throw new AcervoException('O registro já está nesta obra.');
            }
            if ($r['exemplar_id'] !== null) {
                throw new AcervoException('A linha ' . (int)$r['linha'] . ' está ligada a um exemplar. Corrija primeiro a obra do exemplar.');
            }
            $this->executar('UPDATE registros_legados SET livro_id = ?, livro_original_id = COALESCE(livro_original_id, ?) WHERE id = ?', [$destinoId, (int)$r['livro_id'], $registroId]);
            $this->executar("UPDATE inventario_itens i JOIN inventarios v ON v.id = i.inventario_id AND v.status IN ('em_andamento', 'pausado')
                             SET i.livro_id = ? WHERE i.importacao_item_id = ? AND i.exemplar_id IS NULL", [$destinoId, (int)$r['importacao_item_id']]);
            $this->executar('INSERT INTO registro_vinculos (registro_id, acao, livro_anterior_id, livro_novo_id, inventario_id, observacao, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$registroId, 'vincular_obra', (int)$r['livro_id'], $destinoId, $inventarioId, $obs, $administradorId]);
            $this->registrarAcao($administradorId, 'livro.vincular_obra', 'livro', $destinoId, ['origem' => (int)$r['livro_id'], 'registros' => [$registroId], 'origem_arquivada' => true, 'comparacao' => true]);
        });
    }

    /** Salva o resultado (imutável). @return int id */
    public function salvar(array $resultado, int $administradorId, string $observacao = ''): int
    {
        if ($resultado['fonte'] !== 'importacoes') {
            throw new AcervoException('O resultado com uma planilha enviada só para comparação não é salvo (o arquivo não fica no servidor).');
        }
        $itens = array_map([self::class, 'compactar'], $resultado['itens']);
        $importacoes = array_map(fn ($i) => ['id' => (int)$i['id'], 'arquivo' => $i['nome_arquivo'], 'importado_em' => $i['importado_em'] ?? $i['criado_em'], 'registros' => (int)$i['registros']], $resultado['importacoes']);
        $atencao = count(array_filter($resultado['itens'], fn ($i) => $i['atencao']));
        return $this->transacao(function () use ($resultado, $itens, $importacoes, $atencao, $administradorId, $observacao) {
            $this->executar('INSERT INTO comparacoes (inventario_id, fonte, importacoes_json, indicadores_json, itens_json, total_itens, total_atencao, observacao, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $resultado['inventario']['id'] ?? null, $resultado['fonte'], json_encode($importacoes, JSON_UNESCAPED_UNICODE), json_encode($resultado['indicadores'], JSON_UNESCAPED_UNICODE),
                json_encode($itens, JSON_UNESCAPED_UNICODE), count($itens), $atencao, self::textoLimpo($observacao, 500), $administradorId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->registrarAcao($administradorId, 'comparacao.salvar', 'comparacao', $id, ['itens' => count($itens), 'atencao' => $atencao, 'inventario_id' => $resultado['inventario']['id'] ?? null]);
            return $id;
        });
    }

    public function salvas(int $pagina = 1, int $porPagina = 20): array
    {
        $total = (int)$this->valor('SELECT COUNT(*) FROM comparacoes');
        $pagina = max(1, $pagina);
        $itens = $this->todas('SELECT c.id, c.inventario_id, c.fonte, c.importacoes_json, c.indicadores_json, c.total_itens, c.total_atencao, c.observacao, c.criado_em,
                                      a.nome AS administrador_nome, v.titulo AS inventario_titulo
                               FROM comparacoes c LEFT JOIN administradores a ON a.id = c.administrador_id LEFT JOIN inventarios v ON v.id = c.inventario_id
                               ORDER BY c.id DESC LIMIT ' . (int)$porPagina . ' OFFSET ' . (int)(($pagina - 1) * $porPagina));
        return ['total' => $total, 'pagina' => $pagina, 'itens' => $itens];
    }

    /** Resultado salvo, com os itens expandidos (no mesmo formato da comparação atual). */
    public function salva(int $id): ?array
    {
        $c = $this->linha('SELECT c.*, a.nome AS administrador_nome, v.titulo AS inventario_titulo, v.status AS inventario_status
                           FROM comparacoes c LEFT JOIN administradores a ON a.id = c.administrador_id LEFT JOIN inventarios v ON v.id = c.inventario_id WHERE c.id = ?', [$id]);
        if (!$c) {
            return null;
        }
        $c['importacoes'] = json_decode((string)$c['importacoes_json'], true) ?: [];
        $c['indicadores'] = json_decode((string)$c['indicadores_json'], true) ?: [];
        $c['itens'] = array_map([self::class, 'expandir'], json_decode((string)$c['itens_json'], true) ?: []);
        unset($c['itens_json']);
        return $c;
    }

    /** Linhas para Excel/PDF/CSV. */
    public static function linhasExportacao(array $itens): \Generator
    {
        foreach ($itens as $i) {
            $resolvidas = array_intersect($i['categorias'], $i['resolvidas']);
            yield [
                'direcao' => self::DIRECOES[$i['direcao']] ?? $i['direcao'],
                'titulo' => $i['titulo'],
                'autor' => $i['autor'] ?? '',
                'isbn' => $i['isbn'] ?? '',
                'linha_original' => $i['arquivo'] ? $i['arquivo'] . ' · linha ' . $i['linha'] : ($i['linhas'] ? count($i['linhas']) . ' linha(s): ' . implode(', ', array_slice($i['linhas'], 0, 8)) : 'Sem registro na planilha'),
                'obra' => $i['livro_id'] ? 'Nº ' . $i['livro_id'] . ($i['livro_titulo'] && $i['livro_titulo'] !== $i['titulo'] ? ' · ' . $i['livro_titulo'] : '') : '—',
                'exemplares' => $i['codigo'] ?: (string)(int)$i['exemplares'],
                'encontrados' => $i['encontrados'] === null ? '—' : (string)(int)$i['encontrados'],
                'categorias' => implode('; ', array_map([self::class, 'rotulo'], $i['categorias'])),
                'detalhes' => implode(' ', $i['detalhes']),
                'resolucao' => $i['atencao'] ? 'Pendente' : ($resolvidas ? 'Resolvido' : 'Sem pendência'),
            ];
        }
    }

    public const COLUNAS_EXPORTACAO = ['direcao' => 'Direção', 'titulo' => 'Obra / registro', 'autor' => 'Autor', 'isbn' => 'ISBN', 'linha_original' => 'Linha original',
        'obra' => 'Obra no acervo', 'exemplares' => 'Exemplares', 'encontrados' => 'Encontrados no inventário', 'categorias' => 'Tipo de divergência',
        'detalhes' => 'Detalhes', 'resolucao' => 'Situação da resolução'];

    // ---------------------------------------------------------------
    // Planilha enviada só para comparação (nada é gravado)
    // ---------------------------------------------------------------

    /**
     * Lê uma planilha .xlsx/.csv enviada só para comparar. O arquivo não é guardado e nada é gravado.
     * @return list<array> linhas no formato dos registros (id negativo, sem obra)
     */
    public static function lerArquivo(string $caminho, string $nomeOriginal, int $limite = 10000): array
    {
        $excel = new ExcelImportService();
        $tipo = $excel->validarArquivo($caminho, $nomeOriginal);
        $abas = $excel->abas($caminho, $tipo);
        $aba = $tipo === 'csv' ? null : ($abas[0]['nome'] ?? null);
        $cab = $excel->cabecalhos($caminho, $tipo, $aba, 1);
        $validacao = new ImportValidationService();
        $mapa = $validacao->sugerirMapeamento(array_values($cab));
        if (!in_array('titulo', $mapa, true) && !in_array('isbn', $mapa, true)) {
            throw new AcervoException('Não encontramos as colunas de título ou ISBN na primeira linha da planilha.');
        }
        $linhas = [];
        $inicio = 2;
        while (count($linhas) < $limite) {
            $bloco = $excel->lerLinhas($caminho, $tipo, $aba, $inicio, 500, $cab);
            if (!$bloco) {
                break;
            }
            foreach ($bloco as $num => $orig) {
                $n = $validacao->normalizar($validacao->mapear($orig, $mapa));
                if (($n['titulo'] ?? null) === null && ($n['isbn'] ?? null) === null) {
                    continue;
                }
                $linhas[] = ['id' => -$num, 'importacao_id' => 0, 'livro_id' => 0, 'livro_original_id' => null, 'exemplar_id' => null, 'arquivo' => mb_substr($nomeOriginal, 0, 255),
                    'linha' => $num, 'titulo' => $n['titulo'], 'subtitulo' => $n['subtitulo'] ?? null, 'autor' => $n['autor'], 'isbn' => $n['isbn'] ?? ($n['isbn_informado'] ?? null),
                    'editora' => $n['editora'] ?? null, 'ano' => $n['ano_publicacao'] ?? null, 'edicao' => $n['edicao'] ?? null, 'cdd' => $n['cdd_original'] ?? null,
                    'quantidade_informada' => $n['quantidade_informada'] ?? null, 'importado_em' => null, 'exemplar_livro_id' => null, 'exemplar_situacao' => null, 'exemplar_codigo' => null];
                if (count($linhas) >= $limite) {
                    break;
                }
            }
            $inicio = max(array_keys($bloco)) + 1;
        }
        return $linhas;
    }

    // ---------------------------------------------------------------
    // Montagem dos itens
    // ---------------------------------------------------------------

    private function itemRegistro(array $r, array $res, bool $emAndamento, bool $externo): array
    {
        $cats = [];
        $det = [];
        $cand = [];
        $o = $externo ? null : ($this->obras[(int)$r['livro_id']] ?? null);
        $chave = 'registro:' . (int)$r['id'];
        $vinculo = 'associado';
        if ($externo) {
            $vinculo = 'sem_obra';
            $cand = $this->candidatos($r, null, 3);
            if ($cand && $cand[0]['pontos'] >= 7) {
                $o = $this->obras[$cand[0]['livro_id']];
                $vinculo = 'associado';
                $det[] = 'Corresponde à obra nº ' . $o['id'] . ' (' . implode(', ', $cand[0]['motivos']) . ').';
                array_shift($cand);
            } elseif ($cand) {
                $cats[] = 'possivel_correspondencia';
                $det[] = 'Possível correspondência com a obra nº ' . $cand[0]['livro_id'] . ' (' . implode(', ', $cand[0]['motivos']) . '). Confirme antes de associar.';
            } else {
                $cats[] = 'sem_correspondencia';
                $det[] = 'Nenhuma obra do acervo combina com esta linha (ISBN, título e autor).';
            }
        } elseif (!$o || $o['arquivado_em'] !== null) {
            $vinculo = 'sem_obra';
            $cats[] = 'sem_correspondencia';
            $det[] = $o ? 'A obra associada (nº ' . $o['id'] . ') foi arquivada: este registro da planilha está sem obra ativa.' : 'Registro sem obra no acervo.';
            $cand = $this->candidatos($r, (int)$r['livro_id'], 3);
            if ($cand) {
                $cats[] = 'possivel_correspondencia';
                $det[] = 'Possível obra correspondente: nº ' . $cand[0]['livro_id'] . ' (' . implode(', ', $cand[0]['motivos']) . ').';
            }
            $o = null;
        }
        if ($o && !$externo) {
            // Vínculo antigo com um exemplar de outra obra.
            if ($r['exemplar_id'] !== null && $r['exemplar_livro_id'] !== null && (int)$r['exemplar_livro_id'] !== (int)$o['id'] && $r['exemplar_situacao'] !== 'baixado') {
                $cats[] = 'vinculo_inconsistente';
                $vinculo = 'inconsistente';
                $det[] = 'A linha aponta para o exemplar ' . $r['exemplar_codigo'] . ', que hoje é da obra nº ' . (int)$r['exemplar_livro_id'] . '.';
            }
            $alterados = $this->camposAlterados($r, $o);
            if ($alterados) {
                $cats[] = 'dados_corrigidos';
                $det[] = 'Alterado depois da importação: ' . implode(', ', $alterados) . '.';
            }
            // Os dados da linha combinam muito mais com outra obra? (possível associação incorreta)
            $proprio = $this->pontuar($this->normalizar($r['titulo'], $r['autor'], $r['isbn']) + ['editora' => $r['editora'], 'ano' => $r['ano'], 'edicao' => $r['edicao']], $o);
            if ($proprio['pontos'] < 4) {
                $melhores = array_values(array_filter($this->candidatos($r, (int)$o['id'], 3), fn ($c) => $c['pontos'] >= max(5, $proprio['pontos'] + 3)));
                if ($melhores) {
                    $cand = $melhores;
                    $cats[] = 'possivel_correspondencia';
                    $det[] = 'Os dados originais desta linha combinam mais com a obra nº ' . $melhores[0]['livro_id'] . ' (' . implode(', ', $melhores[0]['motivos']) . ') do que com a obra associada.';
                }
            }
            if ($o['dados_verificados_em'] === null) {
                $cats[] = 'aguardando_verificacao';
            }
            if ((int)$o['exemplares'] === 0) {
                $cats[] = 'sem_exemplar';
                $det[] = $emAndamento ? 'A obra ainda não tem exemplar registrado (inventário em andamento: não significa perda).' : 'A obra ainda não tem exemplar registrado.';
            }
        }
        [$cats, $resolvidas] = $this->aplicarResolucoes($cats, $res, $chave);
        if ($o && $o['dados_verificados_em'] !== null && !array_intersect($cats, ['vinculo_inconsistente', 'possivel_correspondencia', 'sem_correspondencia'])) {
            $cats[] = 'correspondencia_confirmada';
        } elseif ($o && in_array('possivel_correspondencia', $resolvidas, true) && ($res[$chave . ':possivel_correspondencia']['decisao'] ?? '') === 'confirmar_correspondencia') {
            $cats[] = 'correspondencia_confirmada';
        }
        return $this->montar([
            'chave' => 'r' . (int)$r['id'], 'direcao' => 'planilha', 'tipo' => 'registro', 'id' => (int)$r['id'],
            'titulo' => $r['titulo'] ?? 'Sem título na planilha', 'autor' => $r['autor'], 'isbn' => $r['isbn'],
            'arquivo' => $r['arquivo'], 'linha' => (int)$r['linha'], 'linhas' => [(int)$r['linha']], 'importacoes' => [(int)$r['importacao_id']],
            'livro_id' => $o ? (int)$o['id'] : null, 'livro_titulo' => $o['titulo'] ?? null, 'origem' => $o['origem_cadastro'] ?? null,
            'verificada' => $o ? $o['dados_verificados_em'] !== null : null, 'exemplares' => $o ? (int)$o['exemplares'] : 0, 'encontrados' => $o ? $o['encontrados'] : null,
            'lotes' => $o['lotes'] ?? [], 'codigos' => $r['exemplar_codigo'] ? [$r['exemplar_codigo']] : [], 'codigo' => null,
            'categorias' => $cats, 'resolvidas' => $resolvidas, 'detalhes' => $det, 'candidatos' => $cand, 'vinculo' => $vinculo,
            'quantidade' => $r['quantidade_informada'] !== null ? (int)$r['quantidade_informada'] : null,
        ]);
    }

    private function itemObra(array $o, array $regs, array $movidos, array $dups, array $registrosPlanilha, array $idxReg, array $res, bool $emAndamento, bool $externo): array
    {
        $cats = [];
        $det = [];
        $cand = [];
        $chave = 'obra:' . (int)$o['id'];
        $vinculo = $regs ? 'associado' : 'sem_registro';
        if (!$regs) {
            if ($movidos) {
                $destinos = array_keys($movidos);
                $cats[] = 'vinculo_inconsistente';
                $vinculo = 'inconsistente';
                $det[] = 'Os registros da planilha desta obra foram vinculados à obra nº ' . implode(', nº ', $destinos) . ', mas ' . ((int)$o['exemplares'] === 1 ? 'o exemplar continua' : 'os exemplares continuam') . ' aqui. Confira se ' . ((int)$o['exemplares'] === 1 ? 'ele deve ser corrigido' : 'eles devem ser corrigidos') . ' para a obra verificada.';
                foreach ($destinos as $d) {
                    if (isset($this->obras[$d])) {
                        $cand[] = ['livro_id' => $d, 'titulo' => $this->obras[$d]['titulo'], 'pontos' => 9, 'motivos' => ['Registros vinculados a ela'], 'diferencas' => []];
                    }
                }
            } elseif ($o['origem_cadastro'] === 'planilha') {
                $cats[] = 'vinculo_inconsistente';
                $vinculo = 'inconsistente';
                $det[] = 'Obra importada da planilha sem nenhum registro original associado.';
            } else {
                // Obra manual/do inventário: só é suspeita quando os dados combinam com um registro da planilha.
                $n = $this->normalizar($o['titulo'], $o['autor'], $o['isbn_chave']);
                $achados = [];
                foreach ($this->posicoesCandidatas($n, $idxReg) as $pos) {
                    $r = $registrosPlanilha[$pos];
                    $p = $this->pontuar($this->normalizar($r['titulo'], $r['autor'], $r['isbn']) + ['editora' => $r['editora'], 'ano' => $r['ano'], 'edicao' => $r['edicao']], $o);
                    if ($p['pontos'] >= 5) {
                        $alvo = $externo ? null : ($this->obras[(int)$r['livro_id']] ?? null);
                        $achados[] = ['livro_id' => $alvo && $alvo['arquivado_em'] === null ? (int)$alvo['id'] : null, 'titulo' => $alvo['titulo'] ?? $r['titulo'], 'pontos' => $p['pontos'],
                            'motivos' => $p['motivos'], 'diferencas' => $p['diferencas'], 'registro_id' => (int)$r['id'], 'arquivo' => $r['arquivo'], 'linha' => (int)$r['linha']];
                    }
                }
                usort($achados, fn ($a, $b) => $b['pontos'] <=> $a['pontos']);
                $achados = array_slice($achados, 0, 3);
                if ($achados) {
                    $cats[] = 'possivel_correspondencia';
                    $cand = $achados;
                    $a = $achados[0];
                    $det[] = 'Cadastrada ' . ($o['origem_cadastro'] === 'inventario' ? 'na conferência' : 'manualmente') . ', mas provavelmente já existia na planilha: linha ' . $a['linha'] . ' de ' . $a['arquivo']
                        . ($a['livro_id'] && $a['livro_id'] !== (int)$o['id'] ? ' (obra nº ' . $a['livro_id'] . ')' : '') . ' — ' . implode(', ', $a['motivos']) . '.'
                        . ($a['diferencas'] ? ' Diferenças: ' . implode(', ', $a['diferencas']) . '.' : '');
                } else {
                    $cats[] = 'obra_nova';
                    $det[] = 'Obra que não estava na planilha original (cadastrada ' . ($o['origem_cadastro'] === 'inventario' ? 'na conferência' : 'manualmente') . '). Não é erro.';
                }
            }
        } else {
            $alterados = $this->camposAlterados($regs[0], $o);
            if ($alterados) {
                $cats[] = 'dados_corrigidos';
                $det[] = 'Alterado depois da importação: ' . implode(', ', $alterados) . '.';
            }
            if ($o['dados_verificados_em'] !== null) {
                $comLinhasQtd = array_values(array_filter($regs, fn ($r) => $r['quantidade_informada'] !== null));
                $q = PendenciasRepository::compararQuantidade(count($regs), count($comLinhasQtd), $comLinhasQtd ? max(array_map(fn ($r) => (int)$r['quantidade_informada'], $comLinhasQtd)) : null,
                    array_sum(array_map(fn ($r) => (int)$r['quantidade_informada'], $comLinhasQtd)), (int)$o['exemplares']);
                if ($q['divergente']) {
                    $cats[] = 'divergencia_quantidade';
                    $det[] = 'Planilha: ' . ($q['historica'] ?? ($q['minimo'] . ' a ' . $q['maximo'])) . ' exemplar(es); acervo: ' . (int)$o['exemplares'] . ' registrado(s). A investigar' . ($emAndamento ? ' (o inventário ainda está em andamento)' : '') . '.';
                } elseif ($q['situacao'] === 'nao_informada') {
                    $det[] = 'A planilha não informou a quantidade: mostramos só o que foi registrado.';
                }
            }
        }
        if ($dups) {
            $cats[] = 'possivel_duplicidade';
            foreach ($dups as $d) {
                $cand[] = $d;
            }
            $d = $dups[0];
            $det[] = 'Possível duplicidade com a obra nº ' . $d['livro_id'] . ' (' . implode(', ', $d['motivos']) . ').' . ($d['diferencas'] ? ' ' . implode(', ', $d['diferencas']) . ': edições diferentes podem ser obras separadas.' : '')
                . (!empty($d['exemplar_unico']) ? ' As duas têm um único exemplar: pode ser um exemplar cadastrado como obra separada.' : '');
        }
        if ($o['dados_verificados_em'] === null) {
            $cats[] = 'aguardando_verificacao';
        }
        if ((int)$o['exemplares'] === 0) {
            $cats[] = 'sem_exemplar';
            if ($regs) {
                $det[] = $emAndamento ? 'Ainda sem exemplar registrado (inventário em andamento: não significa perda).' : 'Ainda sem exemplar registrado.';
            }
        }
        [$cats, $resolvidas] = $this->aplicarResolucoes($cats, $res, $chave, (int)$o['id']);
        if (in_array('possivel_correspondencia', $resolvidas, true) && ($res[$chave . ':possivel_correspondencia']['decisao'] ?? '') === 'confirmar_obra_nova') {
            $cats[] = 'obra_nova';
        }
        if (in_array('obra_nova', $cats, true) && ($res[$chave . ':obra_nova']['decisao'] ?? '') === 'confirmar_obra_nova') {
            $resolvidas[] = 'obra_nova';
        }
        if ($regs && $o['dados_verificados_em'] !== null && !array_intersect($cats, ['vinculo_inconsistente', 'possivel_correspondencia', 'possivel_duplicidade'])) {
            $cats[] = 'correspondencia_confirmada';
        }
        $linhas = array_map(fn ($r) => (int)$r['linha'], $regs);
        sort($linhas);
        return $this->montar([
            'chave' => 'o' . (int)$o['id'], 'direcao' => 'acervo', 'tipo' => 'obra', 'id' => (int)$o['id'],
            'titulo' => $o['titulo'] ?? 'Sem título (a conferir)', 'autor' => $o['autor'], 'isbn' => $o['isbn'] ?? $o['isbn_original'],
            'arquivo' => null, 'linha' => null, 'linhas' => $linhas, 'importacoes' => array_values(array_unique(array_map(fn ($r) => (int)$r['importacao_id'], $regs))),
            'livro_id' => (int)$o['id'], 'livro_titulo' => $o['titulo'], 'origem' => $o['origem_cadastro'],
            'verificada' => $o['dados_verificados_em'] !== null, 'exemplares' => (int)$o['exemplares'], 'encontrados' => $o['encontrados'],
            'lotes' => $o['lotes'], 'codigos' => [], 'codigo' => null,
            'categorias' => $cats, 'resolvidas' => $resolvidas, 'detalhes' => $det, 'candidatos' => array_slice($cand, 0, 6), 'vinculo' => $vinculo,
            'quantidade' => null, 'registros' => count($regs),
        ]);
    }

    /** Exemplares (não baixados) com associação bibliográfica inconsistente. */
    private function exemplaresInconsistentes(?int $invId, array $movidosDe, array $res): array
    {
        $porId = [];
        $add = function (array $e, string $msg, ?int $candidato) use (&$porId) {
            $id = (int)$e['id'];
            $porId[$id] ??= ['e' => $e, 'det' => [], 'cand' => []];
            $porId[$id]['det'][] = $msg;
            if ($candidato && isset($this->obras[$candidato]) && !in_array($candidato, array_column($porId[$id]['cand'], 'livro_id'), true)) {
                $porId[$id]['cand'][] = ['livro_id' => $candidato, 'titulo' => $this->obras[$candidato]['titulo'], 'pontos' => 9, 'motivos' => ['Indicada pelo vínculo'], 'diferencas' => []];
            }
        };
        $campos = 'e.id, e.livro_id, e.codigo_individual, e.situacao, e.lote_id';
        foreach ($this->todas("SELECT $campos FROM exemplares e JOIN livros l ON l.id = e.livro_id WHERE e.situacao <> 'baixado' AND l.arquivado_em IS NOT NULL") as $e) {
            $add($e, 'O exemplar está numa obra arquivada (nº ' . (int)$e['livro_id'] . ').', null);
        }
        foreach ($this->todas("SELECT $campos, r.livro_id AS registro_livro_id, r.linha, r.arquivo FROM registros_legados r JOIN exemplares e ON e.id = r.exemplar_id
                               WHERE e.situacao <> 'baixado' AND r.livro_id <> e.livro_id") as $e) {
            $add($e, 'A linha ' . (int)$e['linha'] . ' de ' . $e['arquivo'] . ' aponta para este exemplar, mas a linha é da obra nº ' . (int)$e['registro_livro_id'] . '.', (int)$e['registro_livro_id']);
        }
        if ($invId) {
            foreach ($this->todas("SELECT $campos, i.livro_id AS item_livro_id FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id
                                   WHERE i.inventario_id = ? AND e.situacao <> 'baixado' AND i.livro_id <> e.livro_id", [$invId]) as $e) {
                $add($e, 'No inventário, o exemplar aparece na obra nº ' . (int)$e['item_livro_id'] . ', mas o cadastro dele é da obra nº ' . (int)$e['livro_id'] . '.', (int)$e['item_livro_id']);
            }
        }
        if ($movidosDe) {
            $ids = array_keys($movidosDe);
            foreach (array_chunk($ids, 500) as $parte) {
                $marcas = implode(',', array_fill(0, count($parte), '?'));
                foreach ($this->todas("SELECT $campos FROM exemplares e WHERE e.situacao <> 'baixado' AND e.livro_id IN ($marcas)
                                       AND NOT EXISTS (SELECT 1 FROM registros_legados r WHERE r.livro_id = e.livro_id)", $parte) as $e) {
                    $destino = array_key_first($movidosDe[(int)$e['livro_id']]);
                    $add($e, 'Os registros da planilha da obra deste exemplar foram vinculados à obra nº ' . $destino . '; o exemplar ficou na obra anterior.', $destino);
                }
            }
        }
        $itens = [];
        foreach ($porId as $id => $x) {
            $e = $x['e'];
            $o = $this->obras[(int)$e['livro_id']] ?? null;
            $chave = 'exemplar:' . $id;
            [$cats, $resolvidas] = $this->aplicarResolucoes(['vinculo_inconsistente'], $res, $chave);
            $itens[] = $this->montar([
                'chave' => 'e' . $id, 'direcao' => 'exemplar', 'tipo' => 'exemplar', 'id' => $id,
                'titulo' => $e['codigo_individual'] . ' · ' . ($o['titulo'] ?? 'Sem título (a conferir)'), 'autor' => $o['autor'] ?? null, 'isbn' => $o['isbn'] ?? null,
                'arquivo' => null, 'linha' => null, 'linhas' => [], 'importacoes' => [],
                'livro_id' => (int)$e['livro_id'], 'livro_titulo' => $o['titulo'] ?? null, 'origem' => $o['origem_cadastro'] ?? null,
                'verificada' => $o ? $o['dados_verificados_em'] !== null : null, 'exemplares' => 1, 'encontrados' => null,
                'lotes' => $e['lote_id'] ? [(int)$e['lote_id']] : [], 'codigos' => [$e['codigo_individual']], 'codigo' => $e['codigo_individual'],
                'categorias' => $cats, 'resolvidas' => $resolvidas, 'detalhes' => $x['det'], 'candidatos' => $x['cand'], 'vinculo' => 'inconsistente', 'quantidade' => null,
                'situacao_exemplar' => $e['situacao'],
            ]);
        }
        return $itens;
    }

    /**
     * Pares de obras ativas parecidas (mesmo ISBN, mesmo título e autor, ou título quase igual com autor compatível).
     * @return array<int, list<array>> obra => outras obras
     */
    private function duplicidades(array $res): array
    {
        $pares = [];
        $considerar = function (int $a, int $b) use (&$pares, $res) {
            if ($a === $b) {
                return;
            }
            [$x, $y] = [min($a, $b), max($a, $b)];
            if (isset($pares[$x][$y]) || isset($res['par:' . $x . ':' . $y])) {
                return;
            }
            $p = $this->pontuar(['isbn' => $this->obras[$x]['isbn_chave']] + $this->obras[$x], $this->obras[$y]);
            $mesmoIsbn = in_array('Mesmo ISBN', $p['motivos'], true);
            $titulo = array_intersect(['Mesmo título', 'Título quase igual (pequena diferença de digitação)'], $p['motivos']);
            $autorOk = !in_array('Autor diferente', $p['diferencas'], true);
            if ($mesmoIsbn || ($titulo && $autorOk && $p['pontos'] >= 4)) {
                $pares[$x][$y] = $p;
            }
        };
        foreach ([$this->idxIsbn, $this->idxTitulo] as $indice) {
            foreach ($indice as $ids) {
                $n = count($ids);
                if ($n < 2 || $n > self::LIMITE_BLOCO) {
                    continue;
                }
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $considerar($ids[$i], $ids[$j]);
                    }
                }
            }
        }
        // Pequenos erros de digitação: compara obras que dividem a palavra mais longa do título.
        foreach ($this->obras as $o) {
            if ($o['arquivado_em'] !== null || $o['tk'] === '' || !$o['palavras']) {
                continue;
            }
            $ids = $this->idxPalavra[$o['palavras'][0]] ?? [];
            if (count($ids) > self::LIMITE_BLOCO) {
                continue;
            }
            foreach ($ids as $outro) {
                if ($outro > (int)$o['id'] && $this->obras[$outro]['tk'] !== $o['tk'] && self::similar($o['tk'], $this->obras[$outro]['tk']) >= 0.85) {
                    $considerar((int)$o['id'], $outro);
                }
            }
        }
        $porObra = [];
        foreach ($pares as $x => $ys) {
            foreach ($ys as $y => $p) {
                $unico = (int)$this->obras[$x]['exemplares'] === 1 && (int)$this->obras[$y]['exemplares'] === 1 && !$p['diferencas'];
                $porObra[$x][] = ['livro_id' => $y, 'titulo' => $this->obras[$y]['titulo'], 'pontos' => $p['pontos'], 'motivos' => $p['motivos'], 'diferencas' => $p['diferencas'], 'duplicidade' => true, 'exemplar_unico' => $unico];
                $porObra[$y][] = ['livro_id' => $x, 'titulo' => $this->obras[$x]['titulo'], 'pontos' => $p['pontos'], 'motivos' => $p['motivos'], 'diferencas' => $p['diferencas'], 'duplicidade' => true, 'exemplar_unico' => $unico];
            }
        }
        foreach ($porObra as &$lista) {
            usort($lista, fn ($a, $b) => $b['pontos'] <=> $a['pontos']);
            $lista = array_slice($lista, 0, 5);
        }
        return $porObra;
    }

    private function indicadores(array $itens, ?int $invId, int $totalRegistros): array
    {
        $i = [
            'planilha' => ['registros' => $totalRegistros, 'associados' => 0, 'sem_correspondencia' => 0],
            'acervo' => ['obras' => 0, 'importadas' => 0, 'manuais' => 0, 'verificadas' => 0, 'nao_verificadas' => 0],
            'exemplares' => ['total' => 0, 'encontrados_inventario' => null, 'sem_associacao' => 0, 'com_problemas' => 0],
            'auditoria' => ['duplicidades' => 0, 'correspondencias_a_confirmar' => 0, 'divergencias_quantidade' => 0, 'vinculos_inconsistentes' => 0, 'resolvidos' => 0, 'pendentes' => 0],
            'progresso' => ['obras_planilha' => 0, 'verificadas' => 0, 'a_verificar' => 0, 'com_exemplar' => 0, 'obras_novas' => 0, 'exemplares_registrados' => 0],
        ];
        $obrasComProblema = [];
        foreach ($itens as $it) {
            $tem = fn ($c) => in_array($c, $it['categorias'], true);
            if ($it['atencao']) {
                $i['auditoria']['pendentes']++;
            }
            if (array_intersect($it['categorias'], $it['resolvidas'])) {
                $i['auditoria']['resolvidos']++;
            }
            $pend = fn ($c) => $tem($c) && !in_array($c, $it['resolvidas'], true);
            if ($it['direcao'] === 'planilha') {
                if ($it['vinculo'] !== 'sem_obra') {
                    $i['planilha']['associados']++;
                }
                if ($tem('sem_correspondencia')) {
                    $i['planilha']['sem_correspondencia']++;
                }
                $i['auditoria']['correspondencias_a_confirmar'] += $pend('possivel_correspondencia') ? 1 : 0;
            } elseif ($it['direcao'] === 'acervo') {
                $i['acervo']['obras']++;
                $importada = $it['registros'] > 0 || $it['origem'] === 'planilha';
                $i['acervo'][$importada ? 'importadas' : 'manuais']++;
                $i['acervo'][$it['verificada'] ? 'verificadas' : 'nao_verificadas']++;
                $i['exemplares']['total'] += $it['exemplares'];
                if ($invId) {
                    $i['exemplares']['encontrados_inventario'] = (int)$i['exemplares']['encontrados_inventario'] + (int)$it['encontrados'];
                }
                $i['auditoria']['duplicidades'] += $pend('possivel_duplicidade') ? 1 : 0;
                $i['auditoria']['correspondencias_a_confirmar'] += $pend('possivel_correspondencia') ? 1 : 0;
                $i['auditoria']['divergencias_quantidade'] += $pend('divergencia_quantidade') ? 1 : 0;
                $i['auditoria']['vinculos_inconsistentes'] += $pend('vinculo_inconsistente') ? 1 : 0;
                if ($it['registros'] > 0) {
                    $i['progresso']['obras_planilha']++;
                    $i['progresso'][$it['verificada'] ? 'verificadas' : 'a_verificar']++;
                    $i['progresso']['com_exemplar'] += $it['exemplares'] > 0 ? 1 : 0;
                    $i['progresso']['exemplares_registrados'] += $it['exemplares'];
                }
                $i['progresso']['obras_novas'] += $tem('obra_nova') ? 1 : 0;
                if ($it['atencao']) {
                    $obrasComProblema[$it['id']] = $it['exemplares'];
                }
            } else {
                $i['exemplares']['sem_associacao'] += $pend('vinculo_inconsistente') ? 1 : 0;
                $i['auditoria']['vinculos_inconsistentes'] += $pend('vinculo_inconsistente') ? 1 : 0;
                if ($it['atencao'] && !isset($obrasComProblema[$it['livro_id']])) {
                    $i['exemplares']['com_problemas']++;
                }
            }
        }
        $i['exemplares']['com_problemas'] += array_sum($obrasComProblema);
        return $i;
    }

    // ---------------------------------------------------------------
    // Carga e comparação de dados
    // ---------------------------------------------------------------

    private function carregarObras(?int $invId): void
    {
        $this->obras = $this->idxIsbn = $this->idxTitulo = $this->idxPalavra = [];
        $encontrados = [];
        if ($invId) {
            foreach ($this->todas("SELECT e.livro_id, COUNT(*) AS n FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id
                                   WHERE i.inventario_id = ? AND i.conferido_em IS NOT NULL AND e.situacao <> 'baixado' GROUP BY e.livro_id", [$invId]) as $x) {
                $encontrados[(int)$x['livro_id']] = (int)$x['n'];
            }
        }
        $lista = $this->todas("SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.isbn_original, l.editora, l.ano_publicacao, l.edicao, l.origem_cadastro, l.dados_verificados_em, l.arquivado_em,
                                      (SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id ORDER BY la.ordem, a.id LIMIT 1) AS autor,
                                      cc.cdd_aprovada, COALESCE(ex.n, 0) AS exemplares, ex.lotes
                               FROM livros l
                               LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id
                               LEFT JOIN (SELECT livro_id, COUNT(*) AS n, GROUP_CONCAT(DISTINCT lote_id) AS lotes FROM exemplares WHERE situacao <> 'baixado' GROUP BY livro_id) ex ON ex.livro_id = l.id");
        foreach ($lista as $o) {
            $id = (int)$o['id'];
            $n = $this->normalizar($o['titulo'], $o['autor'], $o['isbn'] ?: $o['isbn_original']);
            $o += $n;
            $o['ano'] = $o['ano_publicacao'];
            $o['cdd'] = $o['cdd_aprovada'];
            $o['isbn_chave'] = $n['isbn'];
            $o['lotes'] = $o['lotes'] ? array_map('intval', explode(',', $o['lotes'])) : [];
            $o['encontrados'] = $invId ? ($encontrados[$id] ?? 0) : null;
            $this->obras[$id] = $o;
            if ($o['arquivado_em'] !== null) {
                continue;
            }
            if ($n['isbn'] !== '') {
                $this->idxIsbn[$n['isbn']][] = $id;
            }
            if ($n['tk'] !== '') {
                $this->idxTitulo[$n['tk'] . '|' . $n['ak']][] = $id;
                foreach ($n['palavras'] as $p) {
                    $this->idxPalavra[$p][] = $id;
                }
            }
        }
    }

    private function carregarRegistros(): array
    {
        $campo = fn ($k) => "JSON_UNQUOTE(JSON_EXTRACT(ii.dados_normalizados_json, '$.$k'))";
        $lista = $this->todas("SELECT r.id, r.importacao_id, r.livro_id, r.livro_original_id, r.exemplar_id, r.arquivo, r.linha, r.quantidade_informada, r.importado_em,
                                      COALESCE(r.titulo_original, {$campo('titulo')}) AS titulo, {$campo('subtitulo')} AS subtitulo,
                                      COALESCE(r.autor_original, {$campo('autor')}) AS autor, COALESCE({$campo('isbn')}, {$campo('isbn_informado')}) AS isbn,
                                      {$campo('editora')} AS editora, {$campo('ano_publicacao')} AS ano, {$campo('edicao')} AS edicao, r.cdd_original AS cdd,
                                      le.livro_id AS exemplar_livro_id, le.situacao AS exemplar_situacao, le.codigo_individual AS exemplar_codigo
                               FROM registros_legados r
                               LEFT JOIN importacao_itens ii ON ii.id = r.importacao_item_id
                               LEFT JOIN exemplares le ON le.id = r.exemplar_id
                               ORDER BY r.importacao_id, r.linha, r.id");
        foreach ($lista as &$r) {
            foreach (['titulo', 'subtitulo', 'autor', 'isbn', 'editora', 'ano', 'edicao'] as $k) {
                if ($r[$k] === 'null' || $r[$k] === '') {
                    $r[$k] = null;
                }
            }
        }
        unset($r);
        return $lista;
    }

    /** Decisão mais recente de cada item/categoria (e pares "não é duplicidade"). */
    private function carregarResolucoes(): array
    {
        $res = [];
        foreach ($this->todas('SELECT c.*, a.nome AS administrador_nome FROM comparacao_resolucoes c LEFT JOIN administradores a ON a.id = c.administrador_id ORDER BY c.id') as $c) {
            if ($c['decisao'] === 'nao_duplicidade' || ($c['alvo2_id'] !== null && $c['decisao'] === 'reaberta')) {
                $k = 'par:' . (int)$c['alvo_id'] . ':' . (int)$c['alvo2_id'];
                if ($c['decisao'] === 'reaberta') {
                    unset($res[$k]);
                } else {
                    $res[$k] = $c;
                }
                continue;
            }
            $res[$c['alvo_tipo'] . ':' . (int)$c['alvo_id'] . ':' . $c['categoria']] = $c;
        }
        return $res;
    }

    /** @return array{0: list<string>, 1: list<string>} categorias e as já resolvidas */
    private function aplicarResolucoes(array $cats, array $res, string $chave, ?int $obraId = null): array
    {
        $cats = array_values(array_unique($cats));
        $resolvidas = [];
        foreach ($cats as $c) {
            $d = $res[$chave . ':' . $c] ?? null;
            if ($d && $d['decisao'] !== 'reaberta' && (self::CATEGORIAS[$c][2] ?? false)) {
                $resolvidas[] = $c;
            }
        }
        return [$cats, $resolvidas];
    }

    private function montar(array $i): array
    {
        $i['categorias'] = array_values(array_unique($i['categorias']));
        $i['resolvidas'] = array_values(array_unique(array_intersect($i['resolvidas'], $i['categorias'])));
        $i['atencao'] = (bool)array_filter($i['categorias'], fn ($c) => (self::CATEGORIAS[$c][2] ?? false) && !in_array($c, $i['resolvidas'], true));
        $i['isbn_chave'] = self::chaveIsbn($i['isbn']);
        $i['busca'] = Texto::chave(implode(' ', array_filter([$i['titulo'], $i['autor'], $i['livro_titulo'], $i['arquivo'], $i['codigo']])));
        $i['registros'] ??= $i['tipo'] === 'registro' ? 1 : 0;
        return $i;
    }

    /** @return array{tk: string, ak: string, isbn: string, palavras: list<string>} */
    private function normalizar(?string $titulo, ?string $autor, ?string $isbn): array
    {
        $tk = Texto::chave($titulo);
        $tk = (string)preg_replace('/^(o|a|os|as|um|uma|the) /', '', $tk);
        $palavras = array_values(array_unique(array_filter(explode(' ', $tk), fn ($p) => strlen($p) >= 4 && !in_array($p, self::PALAVRAS_VAZIAS, true) && !ctype_digit($p))));
        usort($palavras, fn ($a, $b) => [strlen($b), $a] <=> [strlen($a), $b]);
        return ['tk' => $tk, 'ak' => Texto::chaveAutor($autor), 'isbn' => self::chaveIsbn($isbn), 'palavras' => array_slice($palavras, 0, 2)];
    }

    public static function chaveIsbn(?string $isbn): string
    {
        $n = Isbn::normalizar($isbn);
        if (strlen($n) === 10 && Isbn::valido($n)) {
            return Isbn::para13($n);
        }
        return strlen($n) === 13 || strlen($n) === 10 ? $n : '';
    }

    /** Semelhança 0..1 entre duas chaves (tolera pequenos erros de digitação). */
    public static function similar(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $a = substr($a, 0, 255);
        $b = substr($b, 0, 255);
        $max = max(strlen($a), strlen($b));
        return 1 - levenshtein($a, $b) / $max;
    }

    /** Autor compatível: igual, contido ou com pelo menos metade das palavras em comum. null = sem autor para comparar. */
    private static function autorCompativel(string $a, string $b): ?bool
    {
        if ($a === '' || $b === '') {
            return null;
        }
        if ($a === $b || self::similar($a, $b) >= 0.8) {
            return true;
        }
        $pa = explode(' ', $a);
        $pb = explode(' ', $b);
        return count(array_intersect($pa, $pb)) / max(1, min(count($pa), count($pb))) >= 0.5;
    }

    /**
     * Pontua a semelhança entre dados (normalizados) e uma obra.
     * @return array{pontos: int, motivos: list<string>, diferencas: list<string>}
     */
    private function pontuar(array $n, array $o): array
    {
        $pontos = 0;
        $motivos = [];
        $dif = [];
        if ($n['isbn'] !== '' && $o['isbn_chave'] !== '') {
            if ($n['isbn'] === $o['isbn_chave']) {
                $pontos += 6;
                $motivos[] = 'Mesmo ISBN';
            } else {
                $pontos -= 3;
                $dif[] = 'ISBN diferente';
            }
        }
        $st = self::similar($n['tk'], $o['tk']);
        // Números diferentes no título ("volume 2" × "volume 3") indicam obras diferentes, não erro de digitação.
        preg_match_all('/\d+/', $n['tk'], $na);
        preg_match_all('/\d+/', $o['tk'], $nb);
        if ($st < 1.0 && $na[0] !== $nb[0] && ($na[0] || $nb[0])) {
            $st = 0.0;
            $dif[] = 'Número no título diferente (volume ou parte)';
        }
        if ($st === 1.0) {
            $pontos += 4;
            $motivos[] = 'Mesmo título';
        } elseif ($st >= 0.85) {
            $pontos += 3;
            $motivos[] = 'Título quase igual (pequena diferença de digitação)';
        } elseif ($n['tk'] !== '' && $o['tk'] !== '' && strlen($n['tk']) >= 8 && strlen($o['tk']) >= 8 && (str_contains($n['tk'], $o['tk']) || str_contains($o['tk'], $n['tk']))) {
            $pontos += 2;
            $motivos[] = 'Título parecido';
        }
        $ac = self::autorCompativel($n['ak'], $o['ak']);
        if ($ac === true) {
            $pontos += $n['ak'] === $o['ak'] ? 2 : 1;
            $motivos[] = $n['ak'] === $o['ak'] ? 'Mesmo autor' : 'Autor parecido';
        } elseif ($ac === false) {
            $pontos -= 2;
            $dif[] = 'Autor diferente';
        }
        foreach (['editora' => 'Editora', 'ano' => 'Ano', 'edicao' => 'Edição'] as $c => $rot) {
            $a = Texto::chave(isset($n[$c]) ? (string)$n[$c] : null);
            $b = Texto::chave(isset($o[$c]) ? (string)$o[$c] : null);
            if ($c === 'edicao') {
                $a = (string)preg_replace('/\D+/', '', $a) ?: $a;
                $b = (string)preg_replace('/\D+/', '', $b) ?: $b;
            }
            if ($a !== '' && $b !== '') {
                if ($a === $b) {
                    $pontos += 1;
                    $motivos[] = ($c === 'ano' ? 'Mesmo ' : 'Mesma ') . mb_strtolower($rot);
                } else {
                    $pontos -= 1;
                    $dif[] = $rot . ' diferente';
                }
            }
        }
        return ['pontos' => $pontos, 'motivos' => $motivos, 'diferencas' => $dif];
    }

    /** Obras ativas parecidas com um registro (exceto $excetoId). */
    private function candidatos(array $r, ?int $excetoId, int $limite): array
    {
        $n = $this->normalizar($r['titulo'], $r['autor'], $r['isbn']) + ['editora' => $r['editora'], 'ano' => $r['ano'], 'edicao' => $r['edicao']];
        $ids = [];
        foreach ($this->idxIsbn[$n['isbn']] ?? [] as $id) {
            $ids[$id] = true;
        }
        if ($n['tk'] !== '') {
            foreach ($this->idxTitulo[$n['tk'] . '|' . $n['ak']] ?? [] as $id) {
                $ids[$id] = true;
            }
            foreach ($n['palavras'] as $p) {
                $bloco = $this->idxPalavra[$p] ?? [];
                if (count($bloco) <= self::LIMITE_BLOCO) {
                    foreach ($bloco as $id) {
                        $ids[$id] = true;
                    }
                }
            }
        }
        unset($ids[(int)$excetoId]);
        $lista = [];
        foreach (array_keys($ids) as $id) {
            $p = $this->pontuar($n, $this->obras[$id]);
            if ($p['pontos'] >= 5) {
                $lista[] = ['livro_id' => $id, 'titulo' => $this->obras[$id]['titulo'], 'verificada' => $this->obras[$id]['dados_verificados_em'] !== null] + $p;
            }
        }
        usort($lista, fn ($a, $b) => [$b['pontos'], $a['livro_id']] <=> [$a['pontos'], $b['livro_id']]);
        return array_slice($lista, 0, $limite);
    }

    /** Posições dos registros da planilha que podem combinar com os dados normalizados $n. */
    private function posicoesCandidatas(array $n, array $idx): array
    {
        $pos = [];
        foreach ($idx['isbn'][$n['isbn']] ?? [] as $p) {
            $pos[$p] = true;
        }
        if ($n['tk'] !== '') {
            foreach ($idx['titulo'][$n['tk']] ?? [] as $p) {
                $pos[$p] = true;
            }
            foreach ($n['palavras'] as $w) {
                $bloco = $idx['palavra'][$w] ?? [];
                if (count($bloco) <= self::LIMITE_BLOCO) {
                    foreach ($bloco as $p) {
                        $pos[$p] = true;
                    }
                }
            }
        }
        return array_keys($pos);
    }

    /** Campos que mudaram entre a planilha e o cadastro atual (ignora maiúsculas, acentos, espaços, pontuação e hífens do ISBN). */
    private function camposAlterados(array $r, array $o): array
    {
        $lista = [];
        $atual = ['titulo' => $o['titulo'], 'subtitulo' => $o['subtitulo'], 'autor' => $o['autor'], 'isbn' => $o['isbn'] ?: $o['isbn_original'], 'editora' => $o['editora'],
            'ano' => $o['ano_publicacao'], 'edicao' => $o['edicao'], 'cdd' => $o['cdd_aprovada']];
        foreach (self::CAMPOS as $c => $rot) {
            if (($r[$c] ?? null) === null || ($r[$c] ?? '') === '') {
                continue; // a planilha não informou: não é correção
            }
            if (self::diferente($c, $r[$c], $atual[$c])) {
                $lista[] = mb_strtolower($rot);
            }
        }
        return $lista;
    }

    public static function diferente(string $campo, mixed $a, mixed $b): bool
    {
        $a = $a === null ? '' : (string)$a;
        $b = $b === null ? '' : (string)$b;
        if ($a === '' && $b === '') {
            return false;
        }
        return match ($campo) {
            'isbn' => self::chaveIsbn($a) !== '' || self::chaveIsbn($b) !== '' ? self::chaveIsbn($a) !== self::chaveIsbn($b) : Isbn::normalizar($a) !== Isbn::normalizar($b),
            'autor' => Texto::chaveAutor($a) !== Texto::chaveAutor($b),
            'cdd' => preg_replace('/\s+/', '', $a) !== preg_replace('/\s+/', '', $b),
            default => Texto::chave($a) !== Texto::chave($b),
        };
    }

    private function registroCompleto(int $id): ?array
    {
        $campo = fn ($k) => "JSON_UNQUOTE(JSON_EXTRACT(ii.dados_normalizados_json, '$.$k'))";
        $r = $this->linha("SELECT r.*, COALESCE(r.titulo_original, {$campo('titulo')}) AS titulo, {$campo('subtitulo')} AS subtitulo, COALESCE(r.autor_original, {$campo('autor')}) AS autor,
                                  COALESCE({$campo('isbn_informado')}, {$campo('isbn')}) AS isbn, {$campo('editora')} AS editora, {$campo('ano_publicacao')} AS ano, {$campo('edicao')} AS edicao,
                                  r.cdd_original AS cdd, i.nome_arquivo, i.criado_em AS importacao_em, vp.nome AS vinculado_por_nome, e.codigo_individual AS exemplar_codigo
                           FROM registros_legados r LEFT JOIN importacao_itens ii ON ii.id = r.importacao_item_id LEFT JOIN importacoes i ON i.id = r.importacao_id
                           LEFT JOIN administradores vp ON vp.id = r.vinculado_por LEFT JOIN exemplares e ON e.id = r.exemplar_id WHERE r.id = ?", [$id]);
        if ($r) {
            foreach (['titulo', 'subtitulo', 'autor', 'isbn', 'editora', 'ano', 'edicao'] as $k) {
                if ($r[$k] === 'null' || $r[$k] === '') {
                    $r[$k] = null;
                }
            }
        }
        return $r;
    }

    private function obraCompleta(int $id): ?array
    {
        $o = $this->linha("SELECT l.*, l.ano_publicacao AS ano, cc.cdd_aprovada AS cdd, vp.nome AS verificado_por_nome,
                                  (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autor,
                                  (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao <> 'baixado') AS exemplares
                           FROM livros l LEFT JOIN classificacoes_cdd cc ON cc.livro_id = l.id LEFT JOIN administradores vp ON vp.id = l.dados_verificados_por WHERE l.id = ?", [$id]);
        if ($o && ($o['isbn'] ?? null) === null) {
            $o['isbn'] = $o['isbn_original'];
        }
        return $o;
    }

    /** Histórico: verificações e alterações, vínculos das linhas, correções de exemplares e decisões. */
    private function historico(string $tipo, int $id, ?int $obraId, ?int $registroId): array
    {
        $h = [];
        if ($obraId) {
            foreach ($this->todas('SELECT v.acao, v.alteracoes, v.origem, v.criado_em, a.nome FROM livro_verificacoes v LEFT JOIN administradores a ON a.id = v.administrador_id WHERE v.livro_id = ? ORDER BY v.id DESC LIMIT 30', [$obraId]) as $v) {
                $alt = json_decode((string)$v['alteracoes'], true) ?: [];
                $h[] = ['quando' => $v['criado_em'], 'quem' => $v['nome'], 'texto' => ['verificado' => 'Dados verificados', 'alterado' => 'Dados alterados', 'verificacao_retirada' => 'Verificação retirada'][$v['acao']] ?? $v['acao'],
                    'alteracoes' => $alt];
            }
            foreach ($this->todas('SELECT c.*, a.nome, e.codigo_individual FROM exemplar_obra_correcoes c LEFT JOIN administradores a ON a.id = c.administrador_id JOIN exemplares e ON e.id = c.exemplar_id
                                   WHERE c.livro_anterior_id = ? OR c.livro_novo_id = ? ORDER BY c.id DESC LIMIT 30', [$obraId, $obraId]) as $c) {
                $h[] = ['quando' => $c['criado_em'], 'quem' => $c['nome'], 'texto' => 'Exemplar ' . $c['codigo_individual'] . ' corrigido da obra nº ' . $c['livro_anterior_id'] . ' para a obra nº ' . $c['livro_novo_id'] . ' (o código continuou o mesmo). Motivo: ' . $c['motivo'], 'alteracoes' => []];
            }
            foreach ((new VinculoObraService($this->pdo))->historico($obraId, 20) as $v) {
                $h[] = ['quando' => $v['criado_em'], 'quem' => $v['administrador_nome'], 'texto' => VinculoObraService::descrever($v) . ($v['observacao'] ? ' — ' . $v['observacao'] : ''), 'alteracoes' => []];
            }
        }
        $alvos = [[$tipo, $id]];
        if ($obraId && $tipo !== 'obra') {
            $alvos[] = ['obra', $obraId];
        }
        if ($registroId && $tipo !== 'registro') {
            $alvos[] = ['registro', $registroId];
        }
        foreach ($alvos as [$t, $aid]) {
            foreach ($this->todas('SELECT c.*, a.nome FROM comparacao_resolucoes c LEFT JOIN administradores a ON a.id = c.administrador_id
                                   WHERE (c.alvo_tipo = ? AND c.alvo_id = ?) OR (? = \'obra\' AND c.alvo2_id = ?) ORDER BY c.id DESC LIMIT 30', [$t, $aid, $t, $aid]) as $c) {
                $h[] = ['quando' => $c['criado_em'], 'quem' => $c['nome'], 'texto' => 'Comparação: ' . (self::DECISOES[$c['decisao']] ?? $c['decisao']) . ' (' . self::rotulo($c['categoria']) . ')'
                    . ($c['alvo2_id'] ? ' entre as obras nº ' . $c['alvo_id'] . ' e nº ' . $c['alvo2_id'] : '') . ($c['observacao'] ? ' — ' . $c['observacao'] : ''), 'alteracoes' => []];
            }
        }
        usort($h, fn ($a, $b) => strcmp((string)$b['quando'], (string)$a['quando']));
        return $h;
    }

    private static function compactar(array $i): array
    {
        return ['k' => $i['chave'], 'd' => $i['direcao'], 'ti' => $i['tipo'], 'id' => $i['id'], 't' => $i['titulo'], 'a' => $i['autor'], 'i' => $i['isbn'], 'f' => $i['arquivo'], 'l' => $i['linha'],
            'ls' => array_slice($i['linhas'], 0, 50), 'im' => $i['importacoes'], 'o' => $i['livro_id'], 'ot' => $i['livro_titulo'], 'or' => $i['origem'], 'v' => $i['verificada'],
            'n' => $i['exemplares'], 'e' => $i['encontrados'], 'lt' => $i['lotes'], 'cd' => $i['codigo'], 'c' => $i['categorias'], 'r' => $i['resolvidas'], 'x' => $i['atencao'],
            'dt' => $i['detalhes'], 'vn' => $i['vinculo'], 'rg' => $i['registros']];
    }

    private static function expandir(array $c): array
    {
        $i = ['chave' => $c['k'], 'direcao' => $c['d'], 'tipo' => $c['ti'], 'id' => $c['id'], 'titulo' => $c['t'], 'autor' => $c['a'], 'isbn' => $c['i'], 'arquivo' => $c['f'], 'linha' => $c['l'],
            'linhas' => $c['ls'] ?? [], 'importacoes' => $c['im'] ?? [], 'livro_id' => $c['o'], 'livro_titulo' => $c['ot'], 'origem' => $c['or'], 'verificada' => $c['v'],
            'exemplares' => $c['n'], 'encontrados' => $c['e'], 'lotes' => $c['lt'] ?? [], 'codigo' => $c['cd'], 'codigos' => $c['cd'] ? [$c['cd']] : [], 'categorias' => $c['c'], 'resolvidas' => $c['r'],
            'atencao' => (bool)$c['x'], 'detalhes' => $c['dt'] ?? [], 'vinculo' => $c['vn'], 'registros' => $c['rg'] ?? 0, 'candidatos' => [], 'quantidade' => null];
        $i['isbn_chave'] = self::chaveIsbn($i['isbn']);
        $i['busca'] = Texto::chave(implode(' ', array_filter([$i['titulo'], $i['autor'], $i['livro_titulo'], $i['arquivo'], $i['codigo']])));
        return $i;
    }
}
