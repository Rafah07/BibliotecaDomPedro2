<?php

namespace Biblioteca\Services;

use Biblioteca\Support\ConsultasPdo;
use Biblioteca\Support\Isbn;
use Biblioteca\Support\Texto;
use PDO;

/**
 * Correção dos dados bibliográficos e cadastro de livros não listados, feitos na própria
 * página de conferência do Inventário (não existe uma página de revisão separada).
 *
 * Regras:
 *   * Nenhum campo bibliográfico é obrigatório: o que não se sabe fica vazio (NULL) e vira
 *     alerta cadastral. Marcar um livro como Encontrado nunca depende desses campos.
 *   * ISBN que não confere (dígito verificador, tamanho) é guardado em isbn_original, como foi
 *     digitado, e gera o alerta "isbn_invalido". O ISBN não é único: exemplares e livros com o
 *     mesmo ISBN são permitidos (pode haver mais de um cadastro da mesma obra).
 *   * Os alertas ficam em livro_pendencias, ligados ao livro (e à linha da planilha, quando vieram
 *     da importação). Eles são independentes do status do inventário. Quando o dado é corrigido,
 *     o alerta é resolvido como "corrigida"; quem conferiu pode marcar os restantes como
 *     "confirmada" (ex.: o livro realmente não tem autor indicado). Nada é apagado.
 *   * A verificação dos dados é da OBRA (ObraVerificacaoService): "Dados não verificados" até a
 *     equipe confirmar explicitamente. O exemplar (código BDP, situação no inventário) é outra
 *     ação: InventarioService::registrarExemplar, só para obras verificadas.
 *   * O cadastro de livro não listado cria, em UMA transação, a obra, os alertas e, quando a
 *     pessoa confirma os dados, a verificação e o primeiro exemplar com o código BDP.
 */
final class ConferenciaCadastroService
{
    use ConsultasPdo;

    /** Alertas que o sistema sabe reconferir sozinho depois de uma correção. */
    private const ALERTAS_AUTOMATICOS = ['sem_titulo', 'sem_autor', 'sem_dados_bibliograficos', 'dados_insuficientes', 'isbn_invalido', 'ano_invalido', 'titulo_insuficiente', 'titulo_numerico'];

    private ObraVerificacaoService $verificacao;

    public function __construct(private PDO $pdo, private InventarioService $inventario)
    {
        $this->verificacao = new ObraVerificacaoService($pdo);
    }

    /** Executa várias gravações (correção do livro + conferência) em uma só transação. */
    public function emTransacao(callable $fn): mixed
    {
        return $this->transacao($fn);
    }

    /**
     * Lê e limpa os campos do formulário. Todos podem ficar vazios.
     * @return array{titulo: ?string, subtitulo: ?string, autores: list<string>, isbn: ?string, isbn_original: ?string, editora: ?string,
     *               ano_publicacao: ?int, edicao: ?string, idioma: ?string, assunto: ?string, categoria_id: ?int}
     */
    public function dados(array $post): array
    {
        $t = fn (string $c, int $max) => ($v = Texto::limpar($post[$c] ?? null)) === null ? null : mb_substr($v, 0, $max);
        $autores = [];
        foreach (preg_split('/[;\n]+/u', (string)($post['autores'] ?? '')) as $nome) {
            $nome = Texto::limpar($nome);
            if ($nome !== null && !in_array(mb_strtolower($nome), array_map('mb_strtolower', $autores), true)) {
                $autores[] = mb_substr($nome, 0, 150);
            }
        }
        $isbnTexto = $t('isbn', 100);
        $isbn = null;
        $isbnOriginal = null;
        if ($isbnTexto !== null) {
            if (Isbn::valido($isbnTexto)) {
                $isbn = Isbn::normalizar($isbnTexto);
            } else {
                $isbnOriginal = $isbnTexto; // guardado como veio, com alerta
            }
        }
        $anoTexto = $t('ano_publicacao', 10);
        $ano = null;
        if ($anoTexto !== null) {
            if (!preg_match('/^\d{4}$/', $anoTexto) || (int)$anoTexto < 1450 || (int)$anoTexto > (int)date('Y') + 1) {
                throw new AcervoException('O ano de publicação deve ter 4 números (ex.: 1998). Se não souber, deixe o campo vazio.');
            }
            $ano = (int)$anoTexto;
        }
        $categoria = (int)($post['categoria_id'] ?? 0) ?: null;
        if ($categoria !== null && !$this->valor('SELECT 1 FROM categorias WHERE id = ? AND arquivado_em IS NULL', [$categoria])) {
            throw new AcervoException('Categoria não encontrada. Escolha outra ou deixe sem categoria.');
        }
        return [
            'titulo' => $t('titulo', 255), 'subtitulo' => $t('subtitulo', 255), 'autores' => array_slice($autores, 0, 10),
            'isbn' => $isbn, 'isbn_original' => $isbnOriginal, 'editora' => $t('editora', 150), 'ano_publicacao' => $ano,
            'edicao' => $t('edicao', 60), 'idioma' => $t('idioma', 30), 'assunto' => $t('assunto', 255), 'categoria_id' => $categoria,
        ];
    }

    /**
     * Grava as correções da obra e reconfere os alertas. Com $marcarVerificado (botão "Salvar e
     * marcar dados como verificados"), a obra passa a "Dados verificados". Sem ele, alterar um dado
     * relevante de uma obra verificada a devolve para "Dados não verificados" (ObraVerificacaoService).
     * @return array{alteracoes: list<string>, alertas_abertos: int, verificacao: string}
     */
    public function atualizarLivro(int $livroId, array $d, int $responsavelId, bool $confirmarAlertas = false, bool $marcarVerificado = false, ?int $inventarioId = null): array
    {
        return $this->transacao(function () use ($livroId, $d, $responsavelId, $confirmarAlertas, $marcarVerificado, $inventarioId) {
            $atual = $this->linha('SELECT * FROM livros WHERE id = ? FOR UPDATE', [$livroId]);
            if (!$atual || $atual['arquivado_em'] !== null) {
                throw new AcervoException('Livro não encontrado ou arquivado.');
            }
            $campos = ['titulo', 'subtitulo', 'isbn', 'isbn_original', 'editora', 'ano_publicacao', 'edicao', 'idioma', 'assunto'];
            $alteracoes = [];
            $historico = []; // campo => [antes, depois]
            foreach ($campos as $c) {
                if ((string)($atual[$c] ?? '') !== (string)($d[$c] ?? '')) {
                    $alteracoes[] = $c;
                    $historico[$c] = [$atual[$c], $d[$c]];
                }
            }
            if ($alteracoes) {
                $this->executar('UPDATE livros SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $alteracoes)) . ' WHERE id = ?',
                    [...array_map(fn ($c) => $d[$c], $alteracoes), $livroId]);
            }
            $autoresAtuais = array_column($this->todas('SELECT a.nome FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = ? ORDER BY la.ordem, a.id', [$livroId]), 'nome');
            if ($autoresAtuais !== $d['autores']) {
                $this->executar('DELETE FROM livro_autores WHERE livro_id = ?', [$livroId]);
                $this->gravarAutores($livroId, $d['autores']);
                $alteracoes[] = 'autores';
                $historico['autores'] = [implode('; ', $autoresAtuais) ?: null, implode('; ', $d['autores']) ?: null];
            }
            $categoriaAtual = $this->valor('SELECT categoria_id FROM livro_categorias WHERE livro_id = ? AND principal = TRUE LIMIT 1', [$livroId]);
            if ((int)$categoriaAtual !== (int)$d['categoria_id']) {
                $this->gravarCategoria($livroId, $d['categoria_id']);
                $alteracoes[] = 'categoria';
                $historico['categoria'] = [$categoriaAtual ? (int)$categoriaAtual : null, $d['categoria_id']];
            }
            $abertos = $this->reconferirAlertas($livroId, $responsavelId, $confirmarAlertas);
            if ($alteracoes) {
                $this->executar('UPDATE livros SET atualizado_em = CURRENT_TIMESTAMP WHERE id = ?', [$livroId]);
                $this->registrarAcao($responsavelId, 'livro.editar', 'livro', $livroId, ['campos' => $alteracoes, 'origem' => 'inventario']);
            }
            $verificacao = $this->verificacao->registrar($livroId, $historico, $responsavelId, $marcarVerificado, 'inventario', $inventarioId);
            return ['alteracoes' => $alteracoes, 'alertas_abertos' => $abertos, 'verificacao' => $verificacao];
        });
    }

    /**
     * Livros parecidos com os dados digitados (mesmo ISBN, mesmo título e autor, ou título
     * parecido), para evitar um cadastro repetido sem impedir outro exemplar da mesma obra.
     * @return list<array>
     */
    public function correspondencias(array $d, int $limite = 8): array
    {
        $conds = [];
        $params = [];
        $isbn = $d['isbn'] ?? null;
        if ($isbn) {
            $conds[] = 'l.isbn IN (?, ?)';
            array_push($params, $isbn, strlen($isbn) === 10 ? Isbn::para13($isbn) : $isbn);
        }
        if (!empty($d['isbn_original'])) {
            $conds[] = 'l.isbn_original = ?';
            $params[] = $d['isbn_original'];
        }
        if (!empty($d['titulo']) && mb_strlen($d['titulo']) >= 3) {
            $conds[] = 'l.titulo LIKE ?';
            $params[] = '%' . addcslashes($d['titulo'], '%_\\') . '%';
        }
        if (!$conds) {
            return [];
        }
        $lista = $this->todas('SELECT l.id, COALESCE(l.titulo, \'Sem título (a conferir)\') AS titulo, l.isbn, l.isbn_original, l.editora, l.ano_publicacao, l.edicao,
                                      (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR "; ") FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                      (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao <> \'baixado\') AS exemplares
                               FROM livros l WHERE l.arquivado_em IS NULL AND (' . implode(' OR ', $conds) . ') ORDER BY l.id LIMIT 40', $params);
        $obra = Texto::chaveObra($d['titulo'] ?? null, $d['autores'][0] ?? null);
        foreach ($lista as &$l) {
            $l['motivo'] = match (true) {
                $isbn && in_array($l['isbn'], [$isbn, strlen($isbn) === 10 ? Isbn::para13($isbn) : $isbn], true) => 'Mesmo ISBN',
                $obra !== null && Texto::chaveObra($l['titulo'], explode('; ', (string)$l['autores'])[0] ?: null) === $obra => 'Mesmo título e autor',
                !empty($d['isbn_original']) && $l['isbn_original'] === $d['isbn_original'] => 'Mesmo ISBN (como foi digitado)',
                default => 'Título parecido',
            };
            $l['peso'] = ['Mesmo ISBN' => 0, 'Mesmo título e autor' => 1, 'Mesmo ISBN (como foi digitado)' => 2][$l['motivo']] ?? 3;
        }
        unset($l);
        usort($lista, fn ($a, $b) => [$a['peso'], $a['id']] <=> [$b['peso'], $b['id']]);
        return array_slice($lista, 0, $limite);
    }

    /**
     * "Cadastrar livro não listado": cria a obra (todos os campos opcionais) e os alertas do que
     * falta. Com $marcarVerificado (a pessoa confirmou os dados com o livro em mãos), a obra já fica
     * "Dados verificados" e o primeiro exemplar é registrado no mesmo passo, com o código BDP
     * (obrigatório: gerado ou digitado). Sem a confirmação, só a obra é cadastrada, como
     * "Dados não verificados", e nenhum exemplar é criado.
     * Com $livroExistenteId, não cria obra: só registra outro exemplar da obra (que precisa estar verificada).
     * @return array{livro_id: int, exemplar_id: ?int, codigo: ?string, item_id: ?int, alertas: int, novo_livro: bool, verificado: bool}
     */
    public function cadastrarNaoListado(int $inventarioId, array $d, array $fisico, int $responsavelId, ?int $livroExistenteId = null, bool $marcarVerificado = false): array
    {
        return $this->transacao(function () use ($inventarioId, $d, $fisico, $responsavelId, $livroExistenteId, $marcarVerificado) {
            $novo = $livroExistenteId === null;
            if ($novo) {
                $this->executar("INSERT INTO livros (titulo, subtitulo, isbn, isbn_original, editora, ano_publicacao, edicao, idioma, assunto, observacoes_internas, status_validacao, origem_cadastro)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aguardando_conferencia', 'inventario')", [
                    $d['titulo'], $d['subtitulo'], $d['isbn'], $d['isbn_original'], $d['editora'], $d['ano_publicacao'], $d['edicao'], $d['idioma'], $d['assunto'],
                    'Cadastrado durante a conferência do inventário nº ' . $inventarioId . ' (não constava na lista).',
                ]);
                $livroId = (int)$this->pdo->lastInsertId();
                $this->gravarAutores($livroId, $d['autores']);
                $this->gravarCategoria($livroId, $d['categoria_id']);
                $this->registrarAcao($responsavelId, 'livro.cadastrar', 'livro', $livroId, ['origem' => 'inventario', 'inventario_id' => $inventarioId]);
                if ($marcarVerificado) {
                    $this->verificacao->registrar($livroId, [], $responsavelId, true, 'nao_listado', $inventarioId);
                }
            } else {
                $livroId = $livroExistenteId;
                if (!$this->valor('SELECT 1 FROM livros WHERE id = ? AND arquivado_em IS NULL', [$livroId])) {
                    throw new AcervoException('O livro escolhido não foi encontrado ou está arquivado.');
                }
            }
            $c = null;
            if (!$novo || $marcarVerificado) {
                $c = $this->inventario->registrarExemplar($inventarioId, $livroId, [
                    'codigo' => $fisico['codigo'] ?? '', 'estado_conservacao' => $fisico['estado_conservacao'] ?? 'bom', 'localizacao_id' => $fisico['localizacao_id'] ?? null,
                    'observacoes' => $fisico['observacao'] ?? null, 'situacao_inventario' => $fisico['situacao_inventario'] ?? 'encontrado',
                ], $responsavelId);
            }
            $alertas = $this->reconferirAlertas($livroId, $responsavelId, false);
            $verificado = (bool)$this->valor('SELECT dados_verificados_em IS NOT NULL FROM livros WHERE id = ?', [$livroId]);
            return ['livro_id' => $livroId, 'exemplar_id' => $c['id'] ?? null, 'codigo' => $c['codigo'] ?? null, 'item_id' => $c['item_id'] ?? null,
                'alertas' => $alertas, 'novo_livro' => $novo, 'verificado' => $verificado];
        });
    }

    /**
     * Confere os alertas automáticos com os dados atuais: resolve os que foram corrigidos e
     * cria os que passaram a valer. Com $confirmarRestantes, os demais alertas abertos ficam
     * "confirmados" por quem conferiu. Atualiza status_validacao. @return int alertas abertos
     */
    public function reconferirAlertas(int $livroId, int $responsavelId, bool $confirmarRestantes = false): int
    {
        $l = $this->linha('SELECT titulo, isbn, isbn_original, editora, ano_publicacao FROM livros WHERE id = ?', [$livroId]);
        $temAutor = (bool)$this->valor('SELECT 1 FROM livro_autores WHERE livro_id = ? LIMIT 1', [$livroId]);
        $semTitulo = $l['titulo'] === null;
        $vale = [
            'sem_titulo' => $semTitulo,
            'sem_autor' => !$temAutor,
            'sem_dados_bibliograficos' => $semTitulo && !$temAutor && $l['isbn'] === null && $l['isbn_original'] === null,
            'dados_insuficientes' => $semTitulo && !$temAutor && $l['isbn'] === null,
            'isbn_invalido' => $l['isbn'] === null && $l['isbn_original'] !== null,
            'ano_invalido' => false, // o ano gravado já é válido (o formulário recusa ano inválido)
            'titulo_insuficiente' => !$semTitulo && mb_strlen(trim((string)$l['titulo'])) < 2,
            'titulo_numerico' => !$semTitulo && (bool)preg_match('/^[\d\s.,\-\/]+$/u', (string)$l['titulo']),
        ];
        $abertos = $this->todas('SELECT id, codigo FROM livro_pendencias WHERE livro_id = ? AND resolvida_em IS NULL', [$livroId]);
        $resolver = 'UPDATE livro_pendencias SET resolvida_em = NOW(), resolvida_por = ?, resolucao = ? WHERE id = ?';
        $codigosAbertos = [];
        foreach ($abertos as $a) {
            if (in_array($a['codigo'], self::ALERTAS_AUTOMATICOS, true) && !$vale[$a['codigo']]) {
                $this->executar($resolver, [$responsavelId, 'corrigida', $a['id']]);
            } elseif ($confirmarRestantes) {
                $this->executar($resolver, [$responsavelId, 'confirmada', $a['id']]);
            } else {
                $codigosAbertos[] = $a['codigo'];
            }
        }
        // Alertas novos: só os de dados que faltam, e só se ninguém já confirmou esse mesmo alerta.
        $novos = [
            'sem_titulo' => ['incompleto', 'titulo', 'Sem título. Copie o título da capa ou da folha de rosto quando puder.'],
            'sem_autor' => ['incompleto', 'autor', 'Sem autor. Confira na capa ou na folha de rosto.'],
            'isbn_invalido' => ['inconsistencia', 'isbn', 'ISBN "' . mb_substr((string)$l['isbn_original'], 0, 40) . '" não confere. Foi guardado como foi informado; confira na contracapa.'],
        ];
        if (!$confirmarRestantes) {
            foreach ($novos as $codigo => [$tipo, $campo, $msg]) {
                if (!$vale[$codigo] || in_array($codigo, $codigosAbertos, true)
                    || ($codigo !== 'isbn_invalido' && in_array('sem_dados_bibliograficos', $codigosAbertos, true))
                    || $this->valor("SELECT 1 FROM livro_pendencias WHERE livro_id = ? AND codigo = ? AND resolucao = 'confirmada' LIMIT 1", [$livroId, $codigo])) {
                    continue;
                }
                $this->executar('INSERT INTO livro_pendencias (livro_id, tipo, codigo, campo, mensagem) VALUES (?, ?, ?, ?, ?)', [$livroId, $tipo, $codigo, $campo, $msg]);
                $codigosAbertos[] = $codigo;
            }
        }
        $tipos = array_column($this->todas('SELECT DISTINCT tipo FROM livro_pendencias WHERE livro_id = ? AND resolvida_em IS NULL', [$livroId]), 'tipo');
        $status = match (true) {
            in_array('incompleto', $tipos, true) => 'dados_incompletos',
            (bool)$tipos => 'com_divergencia',
            default => 'validado',
        };
        $this->executar('UPDATE livros SET status_validacao = ? WHERE id = ?', [$status, $livroId]);
        return (int)$this->valor('SELECT COUNT(*) FROM livro_pendencias WHERE livro_id = ? AND resolvida_em IS NULL', [$livroId]);
    }

    private function gravarAutores(int $livroId, array $autores): void
    {
        foreach (array_values($autores) as $ordem => $nome) {
            $this->executar('INSERT INTO autores (nome) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [$nome]);
            $this->executar('INSERT IGNORE INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, ?)', [$livroId, (int)$this->pdo->lastInsertId(), $ordem + 1]);
        }
    }

    private function gravarCategoria(int $livroId, ?int $categoriaId): void
    {
        $this->executar('DELETE FROM livro_categorias WHERE livro_id = ? AND principal = TRUE', [$livroId]);
        if ($categoriaId) {
            $this->executar('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, TRUE) ON DUPLICATE KEY UPDATE principal = TRUE', [$livroId, $categoriaId]);
        }
    }
}
