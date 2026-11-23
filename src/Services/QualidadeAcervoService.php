<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Isbn;
use Biblioteca\Support\Texto;
use PDO;

/**
 * Central de Qualidade do Acervo (migração 190).
 *
 * Identifica inconsistências nos dados ATUAIS da biblioteca para a equipe resolver aos poucos. Nada aqui
 * bloqueia o cadastro, e nada é corrigido sozinho: cada correção é uma ação explícita, com histórico.
 *
 *  - analisar(): roda as verificações (consultas agregadas, sem carregar o acervo na tela) e guarda o
 *    resultado em qualidade_pendencias. É feita SOB DEMANDA (botão "Atualizar análise") ou pela rotina
 *    database/analisar-qualidade.php — nunca a cada abertura de página. A página só lê a tabela, paginada.
 *  - Cada situação tem uma chave estável (tipo + registros envolvidos). Se sumir numa nova análise, fica
 *    "Resolvida"; se voltar, reabre.
 *  - Gravidade: crítico (compromete a integridade ou o funcionamento), atenção (precisa de análise da
 *    equipe), informativo (informação ausente ou trabalho normal em andamento, como "aguardando carimbo").
 *    Ausência de ISBN, CDD ou categoria NÃO é erro crítico: muitos livros antigos não têm.
 *  - Decisões (qualidade_decisoes): "informação indisponível", "não é duplicidade", justificativa. Guardam
 *    a assinatura dos dados naquele momento: se o registro mudar depois, a pendência volta a ser avaliada.
 *  - Padronização de autores, editoras e categorias: só SUGERE grafias equivalentes (mesma chave sem
 *    acentos, maiúsculas, pontuação ou ordem "SOBRENOME, Nome"). Nomes apenas parecidos não são sugeridos,
 *    e nada é aplicado sem a pessoa comparar os registros afetados e confirmar.
 *  - Unificação de obras realmente equivalentes: os exemplares (mesmos códigos BDP) passam para a obra
 *    mantida, a outra é arquivada e aponta para a mantida. Nunca exclui exemplares nem une edições
 *    diferentes sem confirmação explícita.
 */
final class QualidadeAcervoService
{
    use ConsultasPdo;

    public const GRAVIDADES = ['critico' => 'Crítico', 'atencao' => 'Atenção', 'informativo' => 'Informativo'];
    public const GRUPOS = ['bibliografico' => 'Dados bibliográficos', 'exemplar' => 'Exemplares e integridade', 'padronizacao' => 'Padronização', 'duplicidade' => 'Possíveis duplicidades'];
    public const SITUACOES = ['aberta' => 'Aguardando análise', 'justificada' => 'Justificada', 'resolvida' => 'Resolvida'];
    public const DECISOES = ['justificada' => 'Justificada (não pode ser corrigida)', 'indisponivel' => 'Informação realmente indisponível', 'nao_duplicidade' => 'Não é duplicidade (obras diferentes)', 'reaberta' => 'Reaberta para nova análise'];

    /** tipo => [rótulo, gravidade, grupo, ação sugerida] */
    public const TIPOS = [
        'obra_sem_autor' => ['Obra sem autoria informada', 'informativo', 'bibliografico', 'Completar ou confirmar informação indisponível'],
        'obra_sem_categoria' => ['Obra sem categoria', 'informativo', 'bibliografico', 'Escolher a categoria'],
        'obra_sem_cdd' => ['Obra sem classificação CDD aprovada', 'informativo', 'bibliografico', 'Classificar'],
        'obra_nao_verificada' => ['Obra com exemplares e dados ainda não verificados', 'informativo', 'bibliografico', 'Verificar dados'],
        'isbn_invalido' => ['ISBN inválido ou incompleto', 'atencao', 'bibliografico', 'Corrigir ou confirmar informação indisponível'],
        'ano_contraditorio' => ['Ano de publicação contraditório', 'atencao', 'bibliografico', 'Corrigir dados'],
        'registro_sem_obra' => ['Registro importado sem obra ativa associada', 'atencao', 'bibliografico', 'Relacionar o registro'],
        'duplicidade' => ['Possível obra duplicada', 'atencao', 'duplicidade', 'Comparar obras'],
        'autor_variacao' => ['Autor com grafias diferentes', 'atencao', 'padronizacao', 'Revisar padronização'],
        'editora_variacao' => ['Editora com nomes inconsistentes', 'atencao', 'padronizacao', 'Revisar padronização'],
        'categoria_variacao' => ['Categorias equivalentes', 'atencao', 'padronizacao', 'Revisar padronização'],
        'exemplar_obra_invalida' => ['Exemplar ligado a obra arquivada', 'critico', 'exemplar', 'Corrigir a obra do exemplar'],
        'codigo_bdp_invalido' => ['Código BDP fora do padrão', 'critico', 'exemplar', 'Analisar o exemplar'],
        'sequencia_bdp' => ['Sequência de códigos BDP atrás do maior código usado', 'critico', 'exemplar', 'Avisar a manutenção'],
        'situacao_contraditoria' => ['Situação do exemplar contraditória com os empréstimos', 'critico', 'exemplar', 'Analisar o exemplar'],
        'disponivel_bloqueado' => ['Disponível para empréstimo, mas deveria estar bloqueado', 'critico', 'exemplar', 'Analisar o exemplar'],
        'localizacao_desativada' => ['Exemplar em localização desativada', 'atencao', 'exemplar', 'Definir localização'],
        'lote_inconsistente' => ['Vínculo com lote inconsistente', 'atencao', 'exemplar', 'Analisar o lote'],
        'inventario_sem_decisao' => ['Divergência de inventário concluído sem decisão', 'atencao', 'exemplar', 'Analisar o inventário'],
        'ocorrencia_antiga' => ['Ocorrência aberta há mais de 30 dias', 'atencao', 'exemplar', 'Resolver a ocorrência'],
        'aguardando_carimbo' => ['Exemplares aguardando carimbo', 'informativo', 'exemplar', 'Abrir a planilha BDP'],
        'aguardando_organizacao' => ['Exemplares sem localização definitiva', 'informativo', 'exemplar', 'Definir localização'],
    ];

    /** Abreviações comuns nas categorias antigas ("Lit. Brasileira" = "Literatura brasileira"). */
    private const ABREVIACOES = ['lit' => 'literatura', 'liter' => 'literatura', 'bras' => 'brasileira', 'infanto' => 'infantojuvenil', 'juv' => 'juvenil', 'inf' => 'infantil', 'estrang' => 'estrangeira', 'port' => 'portuguesa'];

    public function __construct(private PDO $pdo)
    {
    }

    // =================================================================
    // Análise
    // =================================================================

    /**
     * Roda todas as verificações e atualiza qualidade_pendencias. Só grava a tabela de pendências:
     * nenhum dado do acervo é alterado.
     * @return array{critico: int, atencao: int, informativo: int, novas: int, resolvidas: int, analise_id: int}
     */
    public function analisar(?int $administradorId): array
    {
        $this->executar('INSERT INTO qualidade_analises (administrador_id) VALUES (?)', [$administradorId]);
        $analiseId = (int)$this->pdo->lastInsertId();
        $encontradas = $this->coletar();

        return $this->transacao(function () use ($encontradas, $analiseId) {
            $existentes = [];
            foreach ($this->todas('SELECT id, chave, situacao, assinatura FROM qualidade_pendencias') as $p) {
                $existentes[$p['chave']] = $p;
            }
            $decisoes = [];
            foreach ($this->todas('SELECT d.chave, d.decisao, d.assinatura FROM qualidade_decisoes d
                                   JOIN (SELECT chave, MAX(id) AS id FROM qualidade_decisoes GROUP BY chave) u ON u.id = d.id') as $d) {
                $decisoes[$d['chave']] = $d;
            }
            $novas = 0;
            $ins = $this->pdo->prepare('INSERT INTO qualidade_pendencias (chave, tipo, gravidade, grupo, alvo_tipo, alvo_id, alvo2_id, descricao, detalhes, origem, responsavel_id, assinatura, situacao, analise_id)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $upd = $this->pdo->prepare('UPDATE qualidade_pendencias SET tipo = ?, gravidade = ?, grupo = ?, alvo_tipo = ?, alvo_id = ?, alvo2_id = ?, descricao = ?, detalhes = ?, origem = ?,
                                               responsavel_id = ?, assinatura = ?, situacao = ?, ultima_vez_em = NOW(), resolvida_em = IF(? = \'resolvida\', resolvida_em, NULL), analise_id = ? WHERE id = ?');
            foreach ($encontradas as $chave => $p) {
                // Decisão vale enquanto os dados do registro forem os mesmos de quando ela foi tomada.
                $d = $decisoes[$chave] ?? null;
                $situacao = $d && $d['decisao'] !== 'reaberta' && hash_equals($d['assinatura'], $p['assinatura']) ? 'justificada' : 'aberta';
                $detalhes = json_encode($p['detalhes'], JSON_UNESCAPED_UNICODE);
                if (isset($existentes[$chave])) {
                    $upd->execute([$p['tipo'], $p['gravidade'], $p['grupo'], $p['alvo_tipo'], $p['alvo_id'], $p['alvo2_id'], mb_substr($p['descricao'], 0, 500), $detalhes, $p['origem'],
                        $p['responsavel_id'], $p['assinatura'], $situacao, $situacao, $analiseId, $existentes[$chave]['id']]);
                } else {
                    $ins->execute([$chave, $p['tipo'], $p['gravidade'], $p['grupo'], $p['alvo_tipo'], $p['alvo_id'], $p['alvo2_id'], mb_substr($p['descricao'], 0, 500), $detalhes, $p['origem'],
                        $p['responsavel_id'], $p['assinatura'], $situacao, $analiseId]);
                    $novas++;
                }
            }
            // O que não apareceu nesta análise foi corrigido: fica "Resolvida" (o registro não é apagado).
            $resolvidas = 0;
            foreach ($existentes as $chave => $p) {
                if (!isset($encontradas[$chave]) && $p['situacao'] !== 'resolvida') {
                    $this->executar("UPDATE qualidade_pendencias SET situacao = 'resolvida', resolvida_em = NOW(), analise_id = ? WHERE id = ?", [$analiseId, $p['id']]);
                    $resolvidas++;
                }
            }
            $totais = ['critico' => 0, 'atencao' => 0, 'informativo' => 0];
            foreach ($encontradas as $p) {
                $totais[$p['gravidade']]++;
            }
            $totais += ['novas' => $novas, 'resolvidas' => $resolvidas, 'analise_id' => $analiseId];
            $this->executar('UPDATE qualidade_analises SET concluida_em = NOW(), totais = ? WHERE id = ?', [json_encode($totais), $analiseId]);
            return $totais;
        });
    }

    /** @return array<string, array> chave => pendência */
    private function coletar(): array
    {
        $saida = [];
        $add = function (string $tipo, string $alvoTipo, ?int $alvoId, ?int $alvo2Id, string $descricao, array $detalhes, ?string $origem = null, ?int $responsavel = null, ?string $chave = null) use (&$saida) {
            [, $gravidade, $grupo] = self::TIPOS[$tipo];
            $gravidade = $detalhes['_gravidade'] ?? $gravidade;
            unset($detalhes['_gravidade']);
            $chave ??= $tipo . ':' . $alvoId . ($alvo2Id ? ':' . $alvo2Id : '');
            $saida[$chave] = ['tipo' => $tipo, 'gravidade' => $gravidade, 'grupo' => $grupo, 'alvo_tipo' => $alvoTipo, 'alvo_id' => $alvoId, 'alvo2_id' => $alvo2Id,
                'descricao' => $descricao, 'detalhes' => $detalhes, 'origem' => $origem, 'responsavel_id' => $responsavel, 'assinatura' => sha1(json_encode($detalhes, JSON_UNESCAPED_UNICODE))];
        };

        // ---------- Obras (uma consulta para as verificações de dados bibliográficos) ----------
        $obras = $this->todas("SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.isbn_original, l.editora, l.ano_publicacao, l.edicao, l.origem_cadastro, l.dados_verificados_em,
                                      (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                      (SELECT COUNT(*) FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id AND c.arquivado_em IS NULL WHERE lc.livro_id = l.id) AS categorias,
                                      (SELECT cc.status FROM classificacoes_cdd cc WHERE cc.livro_id = l.id LIMIT 1) AS cdd_status,
                                      (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao <> 'baixado') AS exemplares
                               FROM livros l WHERE l.arquivado_em IS NULL");
        $anoMax = (int)date('Y') + 1;
        $porChave = [];
        $porIsbn = [];
        foreach ($obras as $o) {
            $id = (int)$o['id'];
            $nome = '“' . mb_strimwidth((string)$o['titulo'], 0, 80, '…') . '” (nº ' . $id . ')';
            $origem = $o['origem_cadastro'];
            if (trim((string)$o['autores']) === '') {
                $add('obra_sem_autor', 'obra', $id, null, $nome . ' não tem autor informado.', ['titulo' => $o['titulo']], $origem);
            }
            if (!(int)$o['categorias']) {
                $add('obra_sem_categoria', 'obra', $id, null, $nome . ' não tem categoria.', ['titulo' => $o['titulo']], $origem);
            }
            if ($o['cdd_status'] !== 'aprovada') {
                $add('obra_sem_cdd', 'obra', $id, null, $nome . ' ainda não tem CDD aprovada' . ($o['cdd_status'] ? ' (' . $o['cdd_status'] . ')' : '') . '.', ['cdd' => $o['cdd_status']], $origem);
            }
            if ($o['dados_verificados_em'] === null && (int)$o['exemplares'] > 0) {
                $add('obra_nao_verificada', 'obra', $id, null, $nome . ' tem ' . (int)$o['exemplares'] . ' exemplar(es) e os dados bibliográficos ainda não foram verificados.', ['exemplares' => (int)$o['exemplares']], $origem);
            }
            $isbn = (string)($o['isbn'] ?? '');
            if (($isbn !== '' && !Isbn::valido($isbn)) || ($isbn === '' && trim((string)$o['isbn_original']) !== '')) {
                $add('isbn_invalido', 'obra', $id, null, $nome . ': ISBN “' . ($isbn ?: $o['isbn_original']) . '” é inválido ou está incompleto.', ['isbn' => $isbn, 'isbn_original' => $o['isbn_original']], $origem);
            }
            if ($o['ano_publicacao'] !== null && ((int)$o['ano_publicacao'] > $anoMax || (int)$o['ano_publicacao'] < 1450)) {
                $add('ano_contraditorio', 'obra', $id, null, $nome . ': ano de publicação ' . (int)$o['ano_publicacao'] . ' é impossível.', ['ano' => (int)$o['ano_publicacao']], $origem);
            }
            $ch = Texto::chaveObra($o['titulo'], explode(';', (string)$o['autores'])[0] ?? '');
            if ($ch !== null) {
                $porChave[$ch][] = $o;
            }
            if ($isbn !== '' && Isbn::valido($isbn)) {
                $porIsbn[Isbn::para13($isbn)][] = $o;
            }
        }

        // ---------- Possíveis duplicidades (mesmo título e autor, ou mesmo ISBN) ----------
        $pares = [];
        $parear = function (array $grupo, string $motivo) use (&$pares) {
            usort($grupo, fn ($a, $b) => (int)$a['id'] <=> (int)$b['id']);
            $primeiro = $grupo[0];
            foreach (array_slice($grupo, 1, 20) as $outro) {
                $k = (int)$primeiro['id'] . ':' . (int)$outro['id'];
                $pares[$k]['obras'] = [$primeiro, $outro];
                $pares[$k]['motivos'][] = $motivo;
            }
        };
        foreach ($porChave as $grupo) {
            if (count($grupo) > 1) {
                $parear($grupo, 'mesmo título e autor');
            }
        }
        foreach ($porIsbn as $grupo) {
            if (count($grupo) > 1) {
                $parear($grupo, 'mesmo ISBN');
            }
        }
        foreach ($pares as $k => $p) {
            [$a, $b] = $p['obras'];
            $motivos = array_values(array_unique($p['motivos']));
            $edicaoDiferente = (Isbn::normalizar($a['isbn']) !== '' && Isbn::normalizar($b['isbn']) !== '' && Isbn::para13(Isbn::normalizar($a['isbn'])) !== Isbn::para13(Isbn::normalizar($b['isbn'])))
                || ((string)$a['edicao'] !== '' && (string)$b['edicao'] !== '' && Texto::chave($a['edicao']) !== Texto::chave($b['edicao']))
                || ($a['ano_publicacao'] && $b['ano_publicacao'] && (int)$a['ano_publicacao'] !== (int)$b['ano_publicacao']);
            $manualImportada = ($a['origem_cadastro'] === 'planilha') !== ($b['origem_cadastro'] === 'planilha');
            $add('duplicidade', 'obra', (int)$a['id'], (int)$b['id'],
                '“' . mb_strimwidth((string)$a['titulo'], 0, 70, '…') . '” (nº ' . $a['id'] . ') e “' . mb_strimwidth((string)$b['titulo'], 0, 70, '…') . '” (nº ' . $b['id'] . '): ' . implode(' e ', $motivos)
                . ($manualImportada ? '. Uma obra manual pode corresponder a uma obra importada' : '')
                . ($edicaoDiferente ? '. ISBN, edição ou ano diferentes: podem ser edições diferentes' : ''),
                ['a' => [$a['titulo'], $a['autores'], $a['isbn'], $a['edicao'], $a['ano_publicacao']], 'b' => [$b['titulo'], $b['autores'], $b['isbn'], $b['edicao'], $b['ano_publicacao']], 'motivos' => $motivos,
                    '_gravidade' => $edicaoDiferente ? 'informativo' : 'atencao'], $a['origem_cadastro'], null, 'duplicidade:' . $k);
        }

        // ---------- Registros importados sem obra ativa ----------
        foreach ($this->todas('SELECT r.id, r.arquivo, r.linha, r.titulo_original, r.livro_id, l.arquivado_em, l.unificado_em_id FROM registros_legados r
                               LEFT JOIN livros l ON l.id = r.livro_id WHERE r.livro_id IS NULL OR l.id IS NULL OR l.arquivado_em IS NOT NULL LIMIT 2000') as $r) {
            $add('registro_sem_obra', 'obra', $r['livro_id'] ? (int)$r['livro_id'] : null, (int)$r['id'],
                'Linha ' . (int)$r['linha'] . ' de ' . $r['arquivo'] . ' (“' . mb_strimwidth((string)$r['titulo_original'], 0, 70, '…') . '”) está ligada a uma obra arquivada' . ($r['unificado_em_id'] ? ' (unificada na obra nº ' . (int)$r['unificado_em_id'] . ')' : '') . '.',
                ['livro_id' => $r['livro_id'], 'arquivado' => $r['arquivado_em']], 'planilha', null, 'registro_sem_obra:' . (int)$r['id']);
        }

        // ---------- Padronização ----------
        foreach ($this->gruposVariantes('autor') as $g) {
            $add('autor_variacao', 'autor', (int)$g[0]['id'], (int)$g[1]['id'], 'Grafias diferentes para o mesmo nome: ' . implode(' · ', array_map(fn ($v) => '“' . $v['nome'] . '” (' . $v['obras'] . ' obra(s))', $g)) . '.',
                ['nomes' => array_column($g, 'nome')], 'sistema', null, 'autor_variacao:' . implode('-', array_column($g, 'id')));
        }
        foreach ($this->gruposVariantes('editora') as $g) {
            $add('editora_variacao', 'editora', null, null, 'Nomes diferentes para a mesma editora: ' . implode(' · ', array_map(fn ($v) => '“' . $v['nome'] . '” (' . $v['obras'] . ' obra(s))', $g)) . '.',
                ['nomes' => array_column($g, 'nome')], 'sistema', null, 'editora_variacao:' . substr(sha1(Texto::chave($g[0]['chave'])), 0, 16));
        }
        foreach ($this->gruposVariantes('categoria') as $g) {
            $add('categoria_variacao', 'categoria', (int)$g[0]['id'], (int)$g[1]['id'], 'Categorias equivalentes: ' . implode(' · ', array_map(fn ($v) => '“' . $v['nome'] . '” (' . $v['obras'] . ' obra(s))', $g)) . '.',
                ['nomes' => array_column($g, 'nome')], 'sistema', null, 'categoria_variacao:' . implode('-', array_column($g, 'id')));
        }

        // ---------- Exemplares e integridade ----------
        foreach ($this->todas("SELECT e.id, e.codigo_individual, e.livro_id, e.cadastrado_por, l.titulo, l.unificado_em_id FROM exemplares e JOIN livros l ON l.id = e.livro_id
                               WHERE l.arquivado_em IS NOT NULL AND e.situacao <> 'baixado' LIMIT 2000") as $e) {
            $add('exemplar_obra_invalida', 'exemplar', (int)$e['id'], (int)$e['livro_id'], $e['codigo_individual'] . ' está ligado à obra arquivada “' . $e['titulo'] . '” (nº ' . $e['livro_id'] . ').',
                ['livro_id' => (int)$e['livro_id']], 'sistema', $e['cadastrado_por'] ? (int)$e['cadastrado_por'] : null);
        }
        foreach ($this->todas("SELECT id, codigo_individual, cadastrado_por FROM exemplares WHERE codigo_individual IS NULL OR NOT (codigo_individual COLLATE utf8mb4_bin REGEXP '^BDP-[0-9]{6}$') LIMIT 500") as $e) {
            $add('codigo_bdp_invalido', 'exemplar', (int)$e['id'], null, 'Exemplar nº ' . $e['id'] . ' tem o código “' . ($e['codigo_individual'] ?? 'vazio') . '”, fora do padrão BDP-000000 (registro antigo).',
                ['codigo' => $e['codigo_individual']], 'sistema', $e['cadastrado_por'] ? (int)$e['cadastrado_por'] : null);
        }
        $seq = $this->linha("SELECT (SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1) AS ultimo,
                                    (SELECT MAX(CAST(SUBSTRING(codigo_individual, 5) AS UNSIGNED)) FROM exemplares WHERE codigo_individual REGEXP '^BDP-[0-9]{6}$') AS maior");
        if ($seq && (int)$seq['maior'] > (int)$seq['ultimo']) {
            $add('sequencia_bdp', 'exemplar', null, null, 'A sequência está em ' . (int)$seq['ultimo'] . ', mas já existe o código BDP-' . str_pad((string)(int)$seq['maior'], 6, '0', STR_PAD_LEFT) . '. Novos cadastros poderiam tentar repetir um código (o banco recusaria).',
                ['ultimo' => (int)$seq['ultimo'], 'maior' => (int)$seq['maior']], 'sistema', null, 'sequencia_bdp');
        }
        foreach ($this->todas("SELECT e.id, e.codigo_individual, e.situacao, e.cadastrado_por, m.id AS emprestimo FROM exemplares e
                               LEFT JOIN emprestimos m ON m.exemplar_id = e.id AND m.data_devolucao IS NULL
                               WHERE (e.situacao = 'emprestado' AND m.id IS NULL) OR (e.situacao <> 'emprestado' AND m.id IS NOT NULL) LIMIT 500") as $e) {
            $add('situacao_contraditoria', 'exemplar', (int)$e['id'], null, $e['codigo_individual'] . ($e['emprestimo'] ? ' tem empréstimo em aberto (nº ' . $e['emprestimo'] . '), mas está como “' . ExemplarService::rotuloSituacao($e['situacao']) . '”.' : ' está como “Emprestado”, mas não há empréstimo em aberto.'),
                ['situacao' => $e['situacao'], 'emprestimo' => $e['emprestimo']], 'sistema', $e['cadastrado_por'] ? (int)$e['cadastrado_por'] : null);
        }
        foreach ($this->todas("SELECT e.id, e.codigo_individual, e.cadastrado_por, e.liberacao_pendente,
                                      EXISTS(SELECT 1 FROM exemplar_ocorrencias o WHERE o.exemplar_id = e.id AND o.status = 'aberta' AND o.bloqueia_emprestimo = 1) AS ocorrencia
                               FROM exemplares e WHERE e.situacao = 'disponivel'
                                 AND (e.liberacao_pendente = 1 OR EXISTS(SELECT 1 FROM exemplar_ocorrencias o WHERE o.exemplar_id = e.id AND o.status = 'aberta' AND o.bloqueia_emprestimo = 1)) LIMIT 500") as $e) {
            $add('disponivel_bloqueado', 'exemplar', (int)$e['id'], null, $e['codigo_individual'] . ' está disponível para empréstimo, mas ' . ((int)$e['ocorrencia'] ? 'tem ocorrência aberta que deveria deixá-lo indisponível.' : 'ainda aguarda liberação (carimbo ou estante).'),
                ['ocorrencia' => (int)$e['ocorrencia'], 'liberacao' => (int)$e['liberacao_pendente']], 'sistema', $e['cadastrado_por'] ? (int)$e['cadastrado_por'] : null);
        }
        foreach ($this->todas("SELECT e.id, e.codigo_individual, e.cadastrado_por, lo.id AS local_id FROM exemplares e JOIN localizacoes lo ON lo.id = e.localizacao_id
                               LEFT JOIN estantes es ON es.id = lo.estante_id
                               WHERE e.situacao NOT IN ('baixado', 'extraviado') AND (lo.ativa = 0 OR es.ativa = 0) LIMIT 1000") as $e) {
            $add('localizacao_desativada', 'exemplar', (int)$e['id'], (int)$e['local_id'], $e['codigo_individual'] . ' está numa prateleira (ou estante) desativada.', ['local' => (int)$e['local_id']], 'sistema', $e['cadastrado_por'] ? (int)$e['cadastrado_por'] : null);
        }
        foreach ($this->todas("SELECT lt.id, lt.numero, lt.total, lt.capacidade, lt.responsavel_id, COUNT(e.id) AS exemplares, MAX(e.lote_ordem) AS maior_ordem,
                                      SUM(e.lote_ordem IS NULL) AS sem_ordem
                               FROM cadastro_lotes lt LEFT JOIN exemplares e ON e.lote_id = lt.id GROUP BY lt.id, lt.numero, lt.total, lt.capacidade, lt.responsavel_id
                               HAVING sem_ordem > 0 OR (capacidade IS NOT NULL AND exemplares > capacidade) OR maior_ordem > total") as $l) {
            $add('lote_inconsistente', 'lote', (int)$l['id'], null, 'Lote ' . str_pad((string)(int)$l['numero'], 3, '0', STR_PAD_LEFT) . ': ' . (int)$l['exemplares'] . ' exemplar(es), contador ' . (int)$l['total']
                . ($l['capacidade'] !== null ? ', capacidade ' . (int)$l['capacidade'] : '') . ((int)$l['sem_ordem'] ? ', ' . (int)$l['sem_ordem'] . ' sem ordem no lote' : '') . '.',
                ['exemplares' => (int)$l['exemplares'], 'total' => (int)$l['total'], 'capacidade' => $l['capacidade'], 'sem_ordem' => (int)$l['sem_ordem']], 'sistema', $l['responsavel_id'] ? (int)$l['responsavel_id'] : null);
        }
        foreach ($this->todas("SELECT i.inventario_id, v.titulo, COUNT(*) AS itens FROM inventario_itens i JOIN inventarios v ON v.id = i.inventario_id AND v.status = 'concluido'
                               WHERE (i.divergencia_local = 1 OR i.divergencia_estado = 1 OR i.divergencia_situacao = 1) AND i.decisao IS NULL GROUP BY i.inventario_id, v.titulo") as $i) {
            $add('inventario_sem_decisao', 'exemplar', null, (int)$i['inventario_id'], 'Inventário “' . $i['titulo'] . '” foi concluído com ' . (int)$i['itens'] . ' divergência(s) sem decisão registrada.',
                ['itens' => (int)$i['itens']], 'inventario', null, 'inventario_sem_decisao:' . (int)$i['inventario_id']);
        }
        foreach ($this->todas("SELECT o.id, o.exemplar_id, e.codigo_individual, o.criado_em, o.registrada_por FROM exemplar_ocorrencias o JOIN exemplares e ON e.id = o.exemplar_id
                               WHERE o.status = 'aberta' AND o.criado_em < NOW() - INTERVAL 30 DAY LIMIT 1000") as $o) {
            $add('ocorrencia_antiga', 'exemplar', (int)$o['exemplar_id'], (int)$o['id'], $o['codigo_individual'] . ': ocorrência nº ' . (int)$o['id'] . ' aberta desde ' . date('d/m/Y', strtotime($o['criado_em'])) . '.',
                ['ocorrencia' => (int)$o['id']], 'sistema', $o['registrada_por'] ? (int)$o['registrada_por'] : null, 'ocorrencia_antiga:' . (int)$o['id']);
        }
        // Trabalho normal em andamento: agrupado por lote (não vira uma pendência por exemplar).
        foreach ($this->todas("SELECT COALESCE(e.lote_id, 0) AS lote, MAX(lt.numero) AS numero, MAX(lt.responsavel_id) AS resp,
                                      SUM(e.carimbo_status = 'aguardando') AS carimbo, SUM(e.localizacao_id IS NULL) AS triagem
                               FROM exemplares e LEFT JOIN cadastro_lotes lt ON lt.id = e.lote_id
                               WHERE e.situacao NOT IN ('baixado', 'extraviado') GROUP BY COALESCE(e.lote_id, 0)
                               HAVING carimbo > 0 OR triagem > 0") as $g) {
            $nomeLote = (int)$g['lote'] ? 'Lote ' . str_pad((string)(int)$g['numero'], 3, '0', STR_PAD_LEFT) : 'Exemplares sem lote';
            if ((int)$g['carimbo']) {
                $add('aguardando_carimbo', 'lote', (int)$g['lote'] ?: null, null, $nomeLote . ': ' . (int)$g['carimbo'] . ' exemplar(es) aguardando carimbo.', ['total' => (int)$g['carimbo']], 'sistema', $g['resp'] ? (int)$g['resp'] : null, 'aguardando_carimbo:' . (int)$g['lote']);
            }
            if ((int)$g['triagem']) {
                $add('aguardando_organizacao', 'lote', (int)$g['lote'] ?: null, null, $nomeLote . ': ' . (int)$g['triagem'] . ' exemplar(es) sem localização definitiva (área de triagem).', ['total' => (int)$g['triagem']], 'sistema', $g['resp'] ? (int)$g['resp'] : null, 'aguardando_organizacao:' . (int)$g['lote']);
            }
        }
        return $saida;
    }

    // =================================================================
    // Consulta
    // =================================================================

    /** Filtros: tipo, gravidade, grupo, origem, responsavel, situacao, q. Paginado. */
    public function listar(array $f, int $pagina = 1, int $porPagina = 30): array
    {
        [$where, $params] = $this->filtros($f);
        $total = (int)$this->valor('SELECT COUNT(*) FROM qualidade_pendencias p' . $where, $params);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $itens = $this->todas("SELECT p.*, a.nome AS responsavel_nome,
                                      (SELECT d.justificativa FROM qualidade_decisoes d WHERE d.chave = p.chave ORDER BY d.id DESC LIMIT 1) AS justificativa,
                                      (SELECT d.decisao FROM qualidade_decisoes d WHERE d.chave = p.chave ORDER BY d.id DESC LIMIT 1) AS decisao
                               FROM qualidade_pendencias p LEFT JOIN administradores a ON a.id = p.responsavel_id" . $where . "
                               ORDER BY FIELD(p.situacao, 'aberta', 'justificada', 'resolvida'), FIELD(p.gravidade, 'critico', 'atencao', 'informativo'), p.grupo, p.id
                               LIMIT " . (int)$porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina), $params);
        foreach ($itens as &$i) {
            $i['detalhes'] = json_decode((string)$i['detalhes'], true) ?: [];
        }
        unset($i);
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    private function filtros(array $f): array
    {
        $w = [];
        $p = [];
        foreach (['tipo' => 'p.tipo', 'gravidade' => 'p.gravidade', 'grupo' => 'p.grupo', 'origem' => 'p.origem', 'situacao' => 'p.situacao'] as $chave => $coluna) {
            if (($f[$chave] ?? '') !== '') {
                $w[] = $coluna . ' = ?';
                $p[] = $f[$chave];
            }
        }
        if ((int)($f['responsavel'] ?? 0) > 0) {
            $w[] = 'p.responsavel_id = ?';
            $p[] = (int)$f['responsavel'];
        }
        if (trim((string)($f['q'] ?? '')) !== '') {
            $w[] = 'p.descricao LIKE ?';
            $p[] = self::like(trim((string)$f['q']));
        }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** Números do painel (uma consulta agregada). */
    public function indicadores(): array
    {
        $r = $this->linha("SELECT SUM(situacao = 'aberta' AND gravidade = 'critico') AS criticas,
                                  SUM(situacao = 'aberta' AND gravidade = 'atencao') AS atencao,
                                  SUM(situacao = 'aberta' AND gravidade = 'informativo') AS informativas,
                                  SUM(situacao = 'aberta' AND tipo = 'duplicidade') AS duplicidades,
                                  SUM(situacao = 'aberta' AND tipo IN ('obra_sem_autor', 'obra_sem_categoria', 'obra_sem_cdd', 'isbn_invalido')) AS incompletas,
                                  SUM(situacao = 'aberta' AND grupo IN ('bibliografico', 'padronizacao') AND tipo NOT IN ('obra_sem_autor', 'obra_sem_categoria', 'obra_sem_cdd', 'obra_nao_verificada')) AS inconsistencias,
                                  SUM(situacao = 'aberta' AND tipo IN ('exemplar_obra_invalida', 'registro_sem_obra', 'lote_inconsistente', 'situacao_contraditoria')) AS associacao,
                                  SUM(situacao = 'aberta') AS abertas,
                                  SUM(situacao = 'justificada') AS justificadas,
                                  SUM(situacao = 'resolvida') AS resolvidas
                           FROM qualidade_pendencias") ?: [];
        $ultima = $this->linha('SELECT q.*, a.nome AS administrador_nome FROM qualidade_analises q LEFT JOIN administradores a ON a.id = q.administrador_id WHERE q.concluida_em IS NOT NULL ORDER BY q.id DESC LIMIT 1');
        return array_map(fn ($v) => (int)$v, $r) + ['ultima' => $ultima];
    }

    public function pendencia(string $chave): ?array
    {
        $p = $this->linha('SELECT * FROM qualidade_pendencias WHERE chave = ?', [$chave]);
        if ($p) {
            $p['detalhes'] = json_decode((string)$p['detalhes'], true) ?: [];
        }
        return $p;
    }

    public function decisoes(string $chave): array
    {
        return $this->todas('SELECT d.*, a.nome AS administrador_nome FROM qualidade_decisoes d LEFT JOIN administradores a ON a.id = d.administrador_id WHERE d.chave = ? ORDER BY d.id DESC', [$chave]);
    }

    /**
     * Registra a decisão sobre uma pendência que não pode (ou não precisa) ser corrigida. Ela deixa de
     * aparecer como pendência aberta enquanto os dados continuarem os mesmos.
     */
    public function decidir(string $chave, string $decisao, string $justificativa, int $administradorId): void
    {
        if (!isset(self::DECISOES[$decisao]) || $decisao === 'reaberta') {
            throw new AcervoException('Escolha a decisão.');
        }
        $justificativa = self::textoLimpo($justificativa, 1000);
        if ($justificativa === null) {
            throw new AcervoException('Escreva a justificativa (ex.: “livro de 1950, sem ISBN impresso”).');
        }
        $this->transacao(function () use ($chave, $decisao, $justificativa, $administradorId) {
            $p = $this->linha('SELECT id, assinatura, tipo FROM qualidade_pendencias WHERE chave = ? FOR UPDATE', [$chave]);
            if (!$p) {
                throw new AcervoException('Pendência não encontrada. Atualize a análise.');
            }
            if ($decisao === 'nao_duplicidade' && $p['tipo'] !== 'duplicidade' && !str_ends_with($p['tipo'], '_variacao')) {
                throw new AcervoException('“Não é duplicidade” vale só para possíveis duplicidades e padronizações.');
            }
            $this->executar('INSERT INTO qualidade_decisoes (chave, decisao, justificativa, assinatura, administrador_id) VALUES (?, ?, ?, ?, ?)', [$chave, $decisao, $justificativa, $p['assinatura'], $administradorId]);
            $this->executar("UPDATE qualidade_pendencias SET situacao = 'justificada' WHERE id = ?", [$p['id']]);
            $this->registrarAcao($administradorId, 'qualidade.decidir', 'qualidade', (int)$p['id'], ['chave' => $chave, 'decisao' => $decisao]);
        });
    }

    public function reabrir(string $chave, string $motivo, int $administradorId): void
    {
        $this->transacao(function () use ($chave, $motivo, $administradorId) {
            $p = $this->linha('SELECT id, assinatura FROM qualidade_pendencias WHERE chave = ? FOR UPDATE', [$chave]);
            if (!$p) {
                throw new AcervoException('Pendência não encontrada.');
            }
            $this->executar('INSERT INTO qualidade_decisoes (chave, decisao, justificativa, assinatura, administrador_id) VALUES (?, ?, ?, ?, ?)',
                [$chave, 'reaberta', self::textoLimpo($motivo, 1000) ?? 'Reaberta para nova análise', $p['assinatura'], $administradorId]);
            $this->executar("UPDATE qualidade_pendencias SET situacao = 'aberta' WHERE id = ? AND situacao = 'justificada'", [$p['id']]);
        });
    }

    // =================================================================
    // Padronização de autores, editoras e categorias
    // =================================================================

    /** Chave de comparação (sem acentos, maiúsculas, pontuação; editora sem "Editora"/"Ltda"; abreviações expandidas). */
    public static function chavePadronizacao(string $tipo, string $nome): string
    {
        if ($tipo === 'autor') {
            // "ASSIS, Machado de" = "Machado de Assis": inverte antes de comparar (palavras curtas contam).
            $n = trim($nome);
            if (substr_count($n, ',') === 1) {
                [$sobrenome, $prenome] = array_map('trim', explode(',', $n));
                $n = $prenome . ' ' . $sobrenome;
            }
            return Texto::chave($n);
        }
        $c = Texto::chave($nome);
        if ($tipo === 'editora') {
            $c = trim(preg_replace('/\b(editora|editorial|ed|livraria|ltda|s a|sa|me|eireli|cia|companhia|grupo)\b/', ' ', $c) ?? $c);
            return trim(preg_replace('/\s+/', ' ', $c));
        }
        $palavras = array_map(fn ($p) => self::ABREVIACOES[$p] ?? $p, explode(' ', $c));
        return implode(' ', $palavras);
    }

    /**
     * Grupos de valores com a MESMA chave de padronização e grafias diferentes (no máximo 200 grupos).
     * @return list<list<array{id: ?int, nome: string, obras: int, chave: string}>>
     */
    public function gruposVariantes(string $tipo): array
    {
        $linhas = match ($tipo) {
            'autor' => $this->todas('SELECT a.id, a.nome, COUNT(la.livro_id) AS obras FROM autores a JOIN livro_autores la ON la.autor_id = a.id
                                     JOIN livros l ON l.id = la.livro_id AND l.arquivado_em IS NULL GROUP BY a.id, a.nome'),
            'editora' => $this->todas("SELECT NULL AS id, editora COLLATE utf8mb4_bin AS nome, COUNT(*) AS obras FROM livros WHERE arquivado_em IS NULL AND editora IS NOT NULL AND editora <> ''
                                       GROUP BY nome"),
            'categoria' => $this->todas('SELECT c.id, c.nome, (SELECT COUNT(*) FROM livro_categorias lc WHERE lc.categoria_id = c.id) AS obras FROM categorias c WHERE c.arquivado_em IS NULL'),
            default => throw new AcervoException('Tipo de padronização desconhecido.'),
        };
        $grupos = [];
        foreach ($linhas as $l) {
            $k = self::chavePadronizacao($tipo, (string)$l['nome']);
            if ($k !== '') {
                $grupos[$k][] = ['id' => $l['id'] !== null ? (int)$l['id'] : null, 'nome' => (string)$l['nome'], 'obras' => (int)$l['obras'], 'chave' => $k];
            }
        }
        $saida = [];
        foreach ($grupos as $g) {
            if (count($g) > 1) {
                usort($g, fn ($a, $b) => $b['obras'] <=> $a['obras'] ?: strcmp($a['nome'], $b['nome']));
                $saida[] = $g;
            }
        }
        usort($saida, fn ($a, $b) => array_sum(array_column($b, 'obras')) <=> array_sum(array_column($a, 'obras')));
        return array_slice($saida, 0, 200);
    }

    /** Obras afetadas por uma padronização (para comparar antes de aplicar). */
    public function obrasAfetadas(string $tipo, array $valores, int $limite = 200): array
    {
        if (!$valores) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($valores), '?'));
        $sql = match ($tipo) {
            'autor' => "SELECT DISTINCT l.id, l.titulo, a.nome AS valor FROM livro_autores la JOIN autores a ON a.id = la.autor_id JOIN livros l ON l.id = la.livro_id
                        WHERE l.arquivado_em IS NULL AND a.id IN ($marcas) ORDER BY l.titulo",
            'editora' => "SELECT l.id, l.titulo, l.editora AS valor FROM livros l WHERE l.arquivado_em IS NULL AND l.editora COLLATE utf8mb4_bin IN ($marcas) ORDER BY l.titulo",
            'categoria' => "SELECT l.id, l.titulo, c.nome AS valor FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id JOIN livros l ON l.id = lc.livro_id
                            WHERE l.arquivado_em IS NULL AND c.id IN ($marcas) ORDER BY l.titulo",
            default => throw new AcervoException('Tipo desconhecido.'),
        };
        return $this->todas($sql . ' LIMIT ' . max(1, $limite), array_values($valores));
    }

    /**
     * Padroniza autores: as obras dos autores $ids passam a usar um único cadastro com o nome $nomeFinal.
     * Histórico em cada obra (campo Autor(es), antes e depois); a verificação das obras é mantida.
     * @return array{obras: int, nome: string}
     */
    public function padronizarAutores(array $ids, string $nomeFinal, int $administradorId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $nomeFinal = self::textoLimpo($nomeFinal, 150);
        if (count($ids) < 1 || $nomeFinal === null) {
            throw new AcervoException('Escolha os autores e o nome padronizado.');
        }
        return $this->transacao(function () use ($ids, $nomeFinal, $administradorId) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $obras = array_map('intval', array_column($this->todas("SELECT DISTINCT livro_id FROM livro_autores WHERE autor_id IN ($marcas)", $ids), 'livro_id'));
            $antes = $this->autoresDe($obras);
            // Destino: o autor que já tem exatamente esse nome (a comparação ignora maiúsculas) ou o primeiro escolhido, renomeado.
            $destino = (int)($this->valor('SELECT id FROM autores WHERE nome = ?', [$nomeFinal]) ?: $ids[0]);
            $this->executar('UPDATE autores SET nome = ? WHERE id = ?', [$nomeFinal, $destino]);
            foreach ($ids as $origem) {
                if ($origem === $destino) {
                    continue;
                }
                // Obra que já tem o destino: o vínculo repetido é retirado; as demais trocam de autor.
                $this->executar('DELETE la FROM livro_autores la JOIN livro_autores d ON d.livro_id = la.livro_id AND d.autor_id = ? WHERE la.autor_id = ?', [$destino, $origem]);
                $this->executar('UPDATE livro_autores SET autor_id = ? WHERE autor_id = ?', [$destino, $origem]);
            }
            $this->registrarPadronizacao($obras, 'autores', $antes, $this->autoresDe($obras), $administradorId);
            $this->registrarAcao($administradorId, 'qualidade.padronizar_autor', 'autor', $destino, ['nome' => $nomeFinal, 'autores' => $ids, 'obras' => count($obras)]);
            return ['obras' => count($obras), 'nome' => $nomeFinal];
        });
    }

    /** Padroniza o nome da editora nas obras que usam qualquer uma das grafias $variantes. */
    public function padronizarEditoras(array $variantes, string $nomeFinal, int $administradorId): array
    {
        $variantes = array_values(array_unique(array_filter(array_map(fn ($v) => (string)$v, $variantes), fn ($v) => $v !== '')));
        $nomeFinal = self::textoLimpo($nomeFinal, 150);
        if (!$variantes || $nomeFinal === null) {
            throw new AcervoException('Escolha as grafias e o nome padronizado da editora.');
        }
        return $this->transacao(function () use ($variantes, $nomeFinal, $administradorId) {
            $marcas = implode(',', array_fill(0, count($variantes), '?'));
            $linhas = $this->todas("SELECT id, editora FROM livros WHERE arquivado_em IS NULL AND editora COLLATE utf8mb4_bin IN ($marcas) FOR UPDATE", $variantes);
            $verificacao = new ObraVerificacaoService($this->pdo);
            $n = 0;
            foreach ($linhas as $l) {
                if ($l['editora'] === $nomeFinal) {
                    continue;
                }
                $this->executar('UPDATE livros SET editora = ? WHERE id = ?', [$nomeFinal, $l['id']]);
                $verificacao->registrar((int)$l['id'], ['editora' => [$l['editora'], $nomeFinal]], $administradorId, false, 'padronizacao', null, true);
                $n++;
            }
            $this->registrarAcao($administradorId, 'qualidade.padronizar_editora', 'editora', null, ['nome' => $nomeFinal, 'variantes' => $variantes, 'obras' => $n]);
            return ['obras' => $n, 'nome' => $nomeFinal];
        });
    }

    /** Une categorias equivalentes: as obras de $ids passam para $destinoId e as categorias de origem são desativadas (não apagadas). */
    public function padronizarCategorias(array $ids, int $destinoId, int $administradorId): array
    {
        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $ids))), [$destinoId]));
        if (!$ids || $destinoId <= 0) {
            throw new AcervoException('Escolha a categoria que fica e as que serão unidas a ela.');
        }
        return $this->transacao(function () use ($ids, $destinoId, $administradorId) {
            $destino = $this->linha('SELECT id, nome FROM categorias WHERE id = ? AND arquivado_em IS NULL FOR UPDATE', [$destinoId]);
            if (!$destino) {
                throw new AcervoException('A categoria escolhida não existe ou está desativada.');
            }
            $verificacao = new ObraVerificacaoService($this->pdo);
            $obras = 0;
            foreach ($ids as $origem) {
                $cat = $this->linha('SELECT id, nome FROM categorias WHERE id = ? FOR UPDATE', [$origem]);
                if (!$cat) {
                    continue;
                }
                foreach ($this->todas('SELECT livro_id, principal FROM livro_categorias WHERE categoria_id = ?', [$origem]) as $lc) {
                    $this->executar('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE principal = GREATEST(principal, VALUES(principal))',
                        [$lc['livro_id'], $destinoId, (int)$lc['principal']]);
                    $this->executar('DELETE FROM livro_categorias WHERE livro_id = ? AND categoria_id = ?', [$lc['livro_id'], $origem]);
                    $verificacao->registrar((int)$lc['livro_id'], ['categoria' => [$cat['nome'], $destino['nome']]], $administradorId, false, 'padronizacao', null, true);
                    $obras++;
                }
                $this->executar('UPDATE categorias SET arquivado_em = NOW() WHERE id = ? AND arquivado_em IS NULL', [$origem]);
            }
            $this->registrarAcao($administradorId, 'qualidade.padronizar_categoria', 'categoria', $destinoId, ['unidas' => $ids, 'obras' => $obras]);
            return ['obras' => $obras, 'nome' => $destino['nome']];
        });
    }

    private function autoresDe(array $obras): array
    {
        $saida = [];
        foreach ($obras as $id) {
            $saida[$id] = implode('; ', array_column($this->todas('SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = ? ORDER BY la.ordem, a.id', [$id]), 'nome'));
        }
        return $saida;
    }

    private function registrarPadronizacao(array $obras, string $campo, array $antes, array $depois, int $administradorId): void
    {
        $verificacao = new ObraVerificacaoService($this->pdo);
        foreach ($obras as $id) {
            if (($antes[$id] ?? '') !== ($depois[$id] ?? '')) {
                $this->executar('UPDATE livros SET atualizado_em = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
                $verificacao->registrar($id, [$campo => [$antes[$id] ?? null, $depois[$id] ?? null]], $administradorId, false, 'padronizacao', null, true);
            }
        }
    }

    // =================================================================
    // Duplicidades: comparar e unificar
    // =================================================================

    /** Dados de uma obra para a comparação lado a lado. */
    public function fichaComparacao(int $id): ?array
    {
        $l = $this->linha("SELECT l.*, (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                  (SELECT GROUP_CONCAT(c.nome SEPARATOR ', ') FROM livro_categorias lc JOIN categorias c ON c.id = lc.categoria_id WHERE lc.livro_id = l.id) AS categorias_nomes,
                                  (SELECT cc.cdd_aprovada FROM classificacoes_cdd cc WHERE cc.livro_id = l.id AND cc.status = 'aprovada' LIMIT 1) AS cdd
                           FROM livros l WHERE l.id = ?", [$id]);
        if (!$l) {
            return null;
        }
        $l['exemplares'] = $this->todas("SELECT e.id, e.codigo_individual, e.situacao, (SELECT m.id FROM emprestimos m WHERE m.exemplar_id = e.id AND m.data_devolucao IS NULL LIMIT 1) AS emprestimo
                                         FROM exemplares e WHERE e.livro_id = ? ORDER BY e.codigo_individual", [$id]);
        $l['registros'] = $this->todas('SELECT id, arquivo, linha, titulo_original, autor_original FROM registros_legados WHERE livro_id = ? ORDER BY id LIMIT 50', [$id]);
        $l['historico'] = (int)$this->valor('SELECT COUNT(*) FROM livro_verificacoes WHERE livro_id = ?', [$id]);
        return $l;
    }

    /**
     * Unifica dois cadastros bibliográficos REALMENTE equivalentes. Os exemplares de $unificadoId
     * (mesmo id, código BDP, lote, carimbo, localização, empréstimos e histórico) passam para $mantidoId,
     * as linhas da planilha também (a obra original fica guardada), e $unificadoId é arquivado com
     * unificado_em_id = $mantidoId. Recusa exemplar emprestado. Edição diferente exige $mesmaEdicao.
     * @return array{exemplares: list<string>, registros: int}
     */
    public function unificar(int $mantidoId, int $unificadoId, string $motivo, int $administradorId, bool $confirmado, bool $mesmaEdicao): array
    {
        if (!$confirmado) {
            throw new AcervoException('Confirme que conferiu os dados e os vínculos que serão afetados.');
        }
        if ($mantidoId === $unificadoId || $mantidoId <= 0 || $unificadoId <= 0) {
            throw new AcervoException('Escolha duas obras diferentes.');
        }
        $motivo = self::textoLimpo($motivo, 500);
        if ($motivo === null) {
            throw new AcervoException('Informe o motivo da unificação.');
        }
        return $this->transacao(function () use ($mantidoId, $unificadoId, $motivo, $administradorId, $mesmaEdicao) {
            foreach ([min($mantidoId, $unificadoId), max($mantidoId, $unificadoId)] as $lid) {
                $this->linha('SELECT id FROM livros WHERE id = ? FOR UPDATE', [$lid]);
            }
            $a = $this->fichaComparacao($mantidoId);
            $b = $this->fichaComparacao($unificadoId);
            if (!$a || !$b || $a['arquivado_em'] !== null || $b['arquivado_em'] !== null) {
                throw new AcervoException('As duas obras precisam existir e estar ativas.');
            }
            $diferencas = self::diferencasEdicao($a, $b);
            if ($diferencas && !$mesmaEdicao) {
                throw new AcervoException('As obras têm ' . implode(', ', $diferencas) . ' diferentes: podem ser edições diferentes. Só unifique se tiver certeza de que são a mesma edição (marque a confirmação).');
            }
            foreach ($b['exemplares'] as $e) {
                if ($e['emprestimo'] || $e['situacao'] === 'emprestado') {
                    throw new AcervoException('O exemplar ' . $e['codigo_individual'] . ' está emprestado. Registre a devolução antes de unificar.');
                }
            }
            $comparacao = new ComparacaoPlanilhaService($this->pdo);
            $movidos = [];
            foreach ($b['exemplares'] as $e) {
                if ($e['situacao'] === 'baixado') {
                    // Exemplar baixado não muda de obra (regra do banco): continua na obra arquivada, com o histórico.
                    continue;
                }
                $comparacao->corrigirObraExemplar((int)$e['id'], $mantidoId, $administradorId, true, 'Unificação de cadastros duplicados: ' . $motivo);
                $movidos[] = ['id' => (int)$e['id'], 'codigo' => $e['codigo_individual']];
            }
            $registros = array_map('intval', array_column($this->todas('SELECT id FROM registros_legados WHERE livro_id = ? FOR UPDATE', [$unificadoId]), 'id'));
            foreach ($registros as $rid) {
                // A obra original da linha fica guardada (livro_original_id), como no "vincular a uma obra".
                $this->executar('UPDATE registros_legados SET livro_original_id = COALESCE(livro_original_id, livro_id), livro_id = ? WHERE id = ?', [$mantidoId, $rid]);
                $this->executar('INSERT INTO registro_vinculos (registro_id, acao, livro_anterior_id, livro_novo_id, observacao, administrador_id) VALUES (?, ?, ?, ?, ?, ?)',
                    [$rid, 'vincular_obra', $unificadoId, $mantidoId, mb_substr('Unificação de cadastros: ' . $motivo, 0, 500), $administradorId]);
            }
            // Linhas sem exemplar nos inventários em andamento acompanham a obra mantida.
            $this->executar("UPDATE inventario_itens i JOIN inventarios v ON v.id = i.inventario_id AND v.status IN ('em_andamento', 'pausado')
                             SET i.livro_id = ? WHERE i.livro_id = ? AND i.exemplar_id IS NULL", [$mantidoId, $unificadoId]);
            $this->executar("UPDATE livros SET arquivado_em = NOW(), unificado_em_id = ?, observacoes_internas = TRIM(CONCAT(COALESCE(observacoes_internas, ''), '\n', ?)) WHERE id = ?",
                [$mantidoId, mb_substr('Unificada na obra nº ' . $mantidoId . ' em ' . date('d/m/Y') . ': ' . $motivo, 0, 500), $unificadoId]);
            ObraVerificacaoService::novaVersao($this->pdo, $mantidoId);
            $copia = array_intersect_key($b, array_flip(['titulo', 'subtitulo', 'autores', 'isbn', 'isbn_original', 'editora', 'ano_publicacao', 'edicao', 'idioma', 'assunto', 'categorias_nomes', 'cdd', 'origem_cadastro', 'dados_verificados_em']));
            $this->executar('INSERT INTO obra_unificacoes (livro_mantido_id, livro_unificado_id, exemplares, registros, dados_unificado, motivo, administrador_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $mantidoId, $unificadoId, json_encode($movidos, JSON_UNESCAPED_UNICODE), json_encode($registros), json_encode($copia, JSON_UNESCAPED_UNICODE), $motivo, $administradorId,
            ]);
            $this->executar("UPDATE qualidade_pendencias SET situacao = 'resolvida', resolvida_em = NOW() WHERE tipo = 'duplicidade' AND ((alvo_id = ? AND alvo2_id = ?) OR (alvo_id = ? AND alvo2_id = ?))",
                [$mantidoId, $unificadoId, $unificadoId, $mantidoId]);
            $this->registrarAcao($administradorId, 'livro.unificar', 'livro', $mantidoId, ['unificado' => $unificadoId, 'exemplares' => array_column($movidos, 'codigo'), 'registros' => count($registros), 'motivo' => $motivo]);
            return ['exemplares' => array_column($movidos, 'codigo'), 'registros' => count($registros)];
        });
    }

    /** Campos de edição que diferem entre duas obras (ISBN, edição, ano, editora). */
    public static function diferencasEdicao(array $a, array $b): array
    {
        $dif = [];
        $isbnA = Isbn::normalizar($a['isbn'] ?? '');
        $isbnB = Isbn::normalizar($b['isbn'] ?? '');
        if ($isbnA !== '' && $isbnB !== '' && Isbn::para13($isbnA) !== Isbn::para13($isbnB)) {
            $dif[] = 'ISBN';
        }
        if (trim((string)$a['edicao']) !== '' && trim((string)$b['edicao']) !== '' && Texto::chave($a['edicao']) !== Texto::chave($b['edicao'])) {
            $dif[] = 'edição';
        }
        if ($a['ano_publicacao'] && $b['ano_publicacao'] && (int)$a['ano_publicacao'] !== (int)$b['ano_publicacao']) {
            $dif[] = 'ano';
        }
        if (trim((string)$a['editora']) !== '' && trim((string)$b['editora']) !== '' && self::chavePadronizacao('editora', $a['editora']) !== self::chavePadronizacao('editora', $b['editora'])) {
            $dif[] = 'editora';
        }
        return $dif;
    }

    /** Ação sugerida (rótulo) para um tipo de pendência. */
    public static function acao(string $tipo): string
    {
        return self::TIPOS[$tipo][3] ?? 'Analisar';
    }
}
