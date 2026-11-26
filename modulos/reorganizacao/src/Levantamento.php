<?php

namespace Biblioteca\Reorganizacao;

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\LivroRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ConferenciaCadastroService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\ObraVerificacaoService;
use Biblioteca\Services\OperacaoJaConcluidaException;
use Biblioteca\Support\Isbn;
use PDO;

/**
 * Grupo 1 (levantamento) e Grupo 2 (revisão e cadastramento).
 *
 * Cada registro é UMA obra/edição com N exemplares físicos (reorg_registro_exemplares), cada um com o
 * próprio estado de conservação. O progresso conta exemplares físicos, não formulários.
 *
 * Fluxo de um registro: rascunho (Grupo 1, salvamento automático) → aguardando_revisao (encaminhado)
 * → aprovado (Grupo 2) → cadastrado (obra criada ou reaproveitada, exemplares e códigos BDP criados
 * pelo cadastro permanente) — ou correcao (devolvido ao Grupo 1) / descartado (nunca apagado).
 *
 * Concorrência (no servidor e no banco): linha do lote bloqueada para a quantidade nunca passar do
 * previsto; reserva de edição por registro (editando_*); versão do registro contra sobrescrita;
 * cadastro com a linha do registro bloqueada e chave de idempotência (uma repetição não cria de novo).
 */
final class Levantamento
{
    use Apoio;

    /**
     * Campos bibliográficos: os mesmos da revisão administrativa (admin/includes/form-conferencia-livro.php:
     * título, subtítulo, autores, ISBN, editora, ano, edição, idioma, categoria, assunto, CDD e fonte da CDD),
     * mais as opções "encontrado / não encontrado" de ISBN e CDD, a quantidade e as observações.
     * (As colunas antigas autor_nao_indicado e editora_nao_indicada não são mais usadas: autor e editora
     * são obrigatórios.)
     */
    public const CAMPOS = ['titulo', 'subtitulo', 'autores', 'editora', 'isbn', 'isbn_nao_encontrado', 'isbn_situacao',
        'cdd', 'cdd_nao_encontrada', 'cdd_situacao', 'cdd_fonte', 'ano_publicacao', 'edicao', 'idioma', 'categoria_id', 'assunto', 'quantidade', 'observacoes'];

    /** Campos comparados com a obra do acervo quando o registro é vinculado a uma obra existente. */
    public const CAMPOS_OBRA = ['titulo', 'subtitulo', 'autores', 'editora', 'isbn', 'ano_publicacao', 'edicao', 'idioma', 'categoria_id', 'assunto'];

    public const ROTULOS = [
        'titulo' => 'Título', 'subtitulo' => 'Subtítulo', 'autores' => 'Autor(es)', 'editora' => 'Editora', 'isbn' => 'ISBN', 'isbn_nao_encontrado' => 'ISBN não encontrado',
        'isbn_situacao' => 'ISBN encontrado?', 'cdd' => 'CDD', 'cdd_nao_encontrada' => 'CDD não encontrada', 'cdd_situacao' => 'CDD encontrada?', 'cdd_fonte' => 'Fonte da CDD',
        'ano_publicacao' => 'Ano', 'edicao' => 'Edição', 'idioma' => 'Idioma', 'categoria_id' => 'Categoria', 'assunto' => 'Assunto', 'quantidade' => 'Quantidade',
        'observacoes' => 'Observações', 'estados' => 'Conservação',
    ];

    public const STATUS = [
        'rascunho' => ['Em levantamento', 'muted'],
        'aguardando_revisao' => ['Aguardando revisão', 'amber'],
        'correcao' => ['Devolvido para correção', 'red'],
        'aprovado' => ['Aprovado (aguardando cadastro)', 'blue'],
        'cadastrado' => ['Cadastrado · códigos BDP gerados', 'green'],
        'descartado' => ['Descartado', 'muted'],
    ];

    public function __construct(private PDO $pdo, private array $config, private ExemplarService $exemplares, private Reorganizacao $reorg)
    {
    }

    // =================================================================
    // Validação
    // =================================================================

    /**
     * Limpa e confere os dados do formulário do Grupo 1.
     * @return array{0: array, 1: array<string, string>, 2: bool} dados, erros por campo, completo
     */
    public function validar(array $p): array
    {
        $t = fn (string $c, int $max) => ($v = trim(preg_replace('/\s+/u', ' ', (string)($p[$c] ?? '')) ?? '')) === '' ? null : mb_substr($v, 0, $max);
        $sim = fn (string $c) => in_array((string)($p[$c] ?? ''), ['1', 'true', 'on'], true) || ($p[$c] ?? null) === true;
        $autores = [];
        foreach (preg_split('/[;\n]+/u', (string)($p['autores'] ?? '')) as $a) {
            $a = trim(preg_replace('/\s+/u', ' ', $a) ?? '');
            if ($a !== '' && !in_array(mb_strtolower($a), array_map('mb_strtolower', $autores), true)) {
                $autores[] = mb_substr($a, 0, 150);
            }
        }
        // "Informação encontrada / não encontrada" (ISBN e CDD). Aceita também o indicador antigo.
        $isbnSit = in_array($p['isbn_situacao'] ?? '', ['encontrado', 'nao_encontrado'], true) ? $p['isbn_situacao'] : ($sim('isbn_nao_encontrado') ? 'nao_encontrado' : null);
        $cddSit = in_array($p['cdd_situacao'] ?? '', ['encontrada', 'nao_encontrada'], true) ? $p['cdd_situacao'] : ($sim('cdd_nao_encontrada') ? 'nao_encontrada' : null);
        $d = [
            'titulo' => $t('titulo', 255), 'subtitulo' => $t('subtitulo', 255),
            'autores' => $autores ? implode('; ', array_slice($autores, 0, 10)) : null,
            'editora' => $t('editora', 150),
            'isbn' => $t('isbn', 30), 'isbn_nao_encontrado' => $isbnSit === 'nao_encontrado' ? 1 : 0, 'isbn_situacao' => $isbnSit,
            'cdd' => $t('cdd', 30), 'cdd_nao_encontrada' => $cddSit === 'nao_encontrada' ? 1 : 0, 'cdd_situacao' => $cddSit, 'cdd_fonte' => $t('cdd_fonte', 255),
            'ano_publicacao' => $t('ano_publicacao', 10), 'edicao' => $t('edicao', 60), 'idioma' => $t('idioma', 30),
            'categoria_id' => (int)($p['categoria_id'] ?? 0) ?: null, 'assunto' => $t('assunto', 255),
            'quantidade' => (string)($p['quantidade'] ?? '1'), 'observacoes' => $t('observacoes', 1000),
        ];
        $e = [];
        if ($d['titulo'] === null) {
            $e['titulo'] = 'Informe o título.';
        }
        if ($d['autores'] === null) {
            $e['autores'] = 'Informe o autor (como aparece na capa ou na folha de rosto).';
        }
        if ($d['editora'] === null) {
            $e['editora'] = 'Informe a editora (geralmente na lombada, na folha de rosto ou na ficha catalográfica).';
        }
        if ($d['isbn_situacao'] === 'nao_encontrado') {
            $d['isbn'] = null; // a ausência fica no indicador, nunca escrita no campo
        } elseif ($d['isbn'] === null) {
            $e['isbn'] = $d['isbn_situacao'] === 'encontrado' ? 'Digite o ISBN.' : 'Informe se o ISBN foi encontrado no livro.';
        } else {
            $d['isbn_situacao'] = 'encontrado';
            $limpo = Isbn::normalizar($d['isbn']);
            if (!preg_match('/^[\d\-\s.Xx]+$/', $d['isbn']) || !Isbn::valido($limpo)) {
                $e['isbn'] = strlen($limpo) === 10 || strlen($limpo) === 13
                    ? 'ISBN incorreto: o dígito de controle não confere. Confira no livro.'
                    : 'O ISBN tem 10 ou 13 dígitos. Confira no livro.';
            }
            $d['isbn'] = mb_substr($limpo, 0, 20);
        }
        if ($d['cdd_situacao'] === 'nao_encontrada') {
            $d['cdd'] = null;
        } elseif ($d['cdd'] === null) {
            $e['cdd'] = $d['cdd_situacao'] === 'encontrada' ? 'Digite a CDD.' : 'Informe se a CDD foi encontrada no livro.';
        } else {
            $d['cdd_situacao'] = 'encontrada';
            $d['cdd'] = str_replace(',', '.', preg_replace('/\s+/', '', $d['cdd']));
            if (!preg_match('/^\d{3}(\.\d{1,10})?$/', $d['cdd'])) {
                $e['cdd'] = 'A CDD começa com 3 números (ex.: 869 ou 869.3).';
            }
        }
        if ($d['ano_publicacao'] !== null) {
            $anoMax = (int)date('Y') + 1;
            if (!preg_match('/^\d{4}$/', $d['ano_publicacao']) || (int)$d['ano_publicacao'] < 1450 || (int)$d['ano_publicacao'] > $anoMax) {
                $e['ano_publicacao'] = "O ano tem 4 números (entre 1450 e $anoMax). Se não souber, deixe vazio.";
                $d['ano_publicacao'] = mb_substr(preg_replace('/\D/', '', $d['ano_publicacao']), 0, 4) ?: null;
            }
        }
        if ($d['categoria_id'] !== null && !$this->valor('SELECT 1 FROM categorias WHERE id = ? AND arquivado_em IS NULL', [$d['categoria_id']])) {
            $e['categoria_id'] = 'Categoria não encontrada.';
            $d['categoria_id'] = null;
        }
        $max = (int)$this->config['max_exemplares_registro'];
        if (!ctype_digit($d['quantidade']) || (int)$d['quantidade'] < 1 || (int)$d['quantidade'] > $max) {
            $e['quantidade'] = 'Informe de 1 a ' . $max . ' exemplares.';
            $d['quantidade'] = max(1, min($max, (int)$d['quantidade']));
        } else {
            $d['quantidade'] = (int)$d['quantidade'];
        }
        // Estado de conservação de cada exemplar (obrigatório). "aplicar_todos" preenche os vazios.
        $estados = [];
        $todos = (string)($p['aplicar_todos'] ?? '');
        $lista = is_array($p['estados'] ?? null) ? $p['estados'] : [];
        for ($i = 1; $i <= $d['quantidade']; $i++) {
            $v = (string)($lista[$i] ?? $lista[(string)$i] ?? '');
            if ($v === '' && isset(self::CONSERVACAO[$todos])) {
                $v = $todos;
            }
            $estados[$i] = isset(self::CONSERVACAO[$v]) ? $v : null;
        }
        $faltam = array_keys(array_filter($estados, fn ($v) => $v === null));
        if ($faltam) {
            $e['estados'] = 'Escolha o estado de conservação ' . (count($faltam) === 1 ? 'do exemplar ' . $faltam[0] : 'dos exemplares ' . implode(', ', $faltam)) . '.';
        }
        $d['estados'] = $estados;
        return [$d, $e, !$e];
    }

    // =================================================================
    // Grupo 1
    // =================================================================

    /** Novo registro (rascunho) já reservado para a sessão. Ocupa 1 exemplar previsto do lote. */
    public function novoRegistro(array $sessao, int $loteId): array
    {
        return $this->transacao(function () use ($sessao, $loteId) {
            $this->exigirAcesso($sessao, $loteId, 'grupo1');
            $lote = $this->bloquearLote($loteId);
            if ($this->reorg->exemplaresRegistrados($loteId) >= (int)$lote['quantidade_prevista']) {
                throw new AcervoException('Todos os ' . $lote['quantidade_prevista'] . ' exemplares previstos do ' . self::nomeLote($lote) . ' já foram registrados. Se há mais livros, avise o administrador.');
            }
            $numero = (int)$this->valor('SELECT COALESCE(MAX(numero), 0) + 1 FROM reorg_registros WHERE lote_id = ?', [$loteId]);
            $this->executar('INSERT INTO reorg_registros (lote_id, numero, criado_sessao_id, criado_nome, atualizado_sessao_id, atualizado_nome, editando_sessao_id, editando_ate)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)',
                [$loteId, $numero, $sessao['id'], $sessao['nome'], $sessao['id'], $sessao['nome'], $sessao['id'], (int)$this->config['reserva_edicao']]);
            $id = (int)$this->pdo->lastInsertId();
            $this->executar('INSERT INTO reorg_registro_exemplares (registro_id, lote_id, ordem) VALUES (?, ?, 1)', [$id, $loteId]);
            $this->executar("UPDATE reorg_sessoes SET situacao = 'trabalhando' WHERE id = ?", [$sessao['id']]);
            $this->historico('registro_criado', $sessao['nome'] . ' criou o registro nº ' . $numero, $loteId, $sessao);
            return $this->registro($id);
        });
    }

    public function registro(int $id): ?array
    {
        $r = $this->linha('SELECT r.*, l.nome AS lote_nome, l.responsavel_id, s.nome AS editando_nome, a.nome AS editando_admin_nome, c.nome AS categoria_nome,
                                  lv.titulo AS livro_sugerido_titulo, lv.isbn AS livro_sugerido_isbn, lv.editora AS livro_sugerido_editora, lv.edicao AS livro_sugerido_edicao
                           FROM reorg_registros r JOIN reorg_lotes l ON l.id = r.lote_id
                           LEFT JOIN reorg_sessoes s ON s.id = r.editando_sessao_id LEFT JOIN administradores a ON a.id = r.editando_admin_id
                           LEFT JOIN categorias c ON c.id = r.categoria_id LEFT JOIN livros lv ON lv.id = r.livro_sugerido_id WHERE r.id = ?', [$id]);
        if (!$r) {
            return null;
        }
        $r['exemplares'] = $this->todas('SELECT x.*, e.codigo_individual FROM reorg_registro_exemplares x LEFT JOIN exemplares e ON e.id = x.exemplar_id
                                         WHERE x.registro_id = ? AND x.removido_em IS NULL ORDER BY x.ordem', [$id]);
        $r['estados'] = [];
        foreach ($r['exemplares'] as $x) {
            $r['estados'][(int)$x['ordem']] = $x['estado_conservacao'];
        }
        $r['reservado_por_outro'] = $r['editando_ate'] !== null && strtotime($r['editando_ate']) > time();
        return $r;
    }

    /**
     * Abre para editar: reserva o registro para a sessão (ou o administrador). Se outra pessoa está
     * editando, devolve o registro com 'somente_leitura' e quem está com ele.
     */
    public function abrir(int $id, ?array $sessao = null, ?int $adminId = null): array
    {
        return $this->transacao(function () use ($id, $sessao, $adminId) {
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) {
                throw new AcervoException('Registro não encontrado.');
            }
            if ($sessao !== null) {
                $this->exigirAcesso($sessao, (int)$r['lote_id'], 'grupo1', false);
            }
            $editavel = $sessao !== null ? in_array($r['status'], ['rascunho', 'correcao'], true) : in_array($r['status'], ['aguardando_revisao', 'aprovado', 'correcao'], true);
            $ocupado = $this->reservadoPorOutro($r, $sessao, $adminId);
            if ($editavel && !$ocupado) {
                $this->executar('UPDATE reorg_registros SET editando_sessao_id = ?, editando_admin_id = ?, editando_ate = NOW() + INTERVAL ? SECOND WHERE id = ?',
                    [$sessao['id'] ?? null, $adminId, (int)$this->config['reserva_edicao'], $id]);
                if ($sessao !== null) {
                    $this->executar("UPDATE reorg_sessoes SET situacao = 'trabalhando' WHERE id = ?", [$sessao['id']]);
                }
            }
            $reg = $this->registro($id);
            $reg['somente_leitura'] = !$editavel || $ocupado;
            $reg['motivo_leitura'] = $ocupado ? 'Em edição por ' . ($reg['editando_nome'] ?? $reg['editando_admin_nome'] ?? 'outra pessoa') . '. Você pode consultar, mas não alterar agora.'
                : (!$editavel ? 'Este registro está “' . self::STATUS[$r['status']][0] . '” e não pode ser alterado aqui.' : '');
            return $reg;
        });
    }

    private function reservadoPorOutro(array $r, ?array $sessao, ?int $adminId): bool
    {
        if ($r['editando_ate'] === null || strtotime($r['editando_ate']) <= time()) {
            return false;
        }
        if ($sessao !== null) {
            return !((int)$r['editando_sessao_id'] === (int)$sessao['id']);
        }
        return !((int)$r['editando_admin_id'] === (int)$adminId);
    }

    /** Libera a reserva de edição (ao sair do registro). */
    public function liberar(int $id, ?array $sessao = null, ?int $adminId = null): void
    {
        if ($sessao !== null) {
            $this->executar('UPDATE reorg_registros SET editando_sessao_id = NULL, editando_ate = NULL WHERE id = ? AND editando_sessao_id = ?', [$id, $sessao['id']]);
        } else {
            $this->executar('UPDATE reorg_registros SET editando_admin_id = NULL, editando_ate = NULL WHERE id = ? AND editando_admin_id = ?', [$id, $adminId]);
        }
    }

    /**
     * Salvamento automático do Grupo 1 (formulários incompletos também). Só confirma depois de gravar.
     * $versao = versão que a tela carregou: se outra pessoa salvou antes, nada é sobrescrito.
     * @return array{versao: int, completo: bool, erros: array, salvo_em: string, avisos: list<string>}
     */
    public function salvar(array $sessao, int $id, array $post, int $versao): array
    {
        [$d, $erros, $completo] = $this->validar($post);
        return $this->transacao(function () use ($sessao, $id, $d, $erros, $completo, $versao) {
            $r0 = $this->linha('SELECT lote_id FROM reorg_registros WHERE id = ?', [$id]);
            if (!$r0) {
                throw new AcervoException('Registro não encontrado.');
            }
            $this->exigirAcesso($sessao, (int)$r0['lote_id'], 'grupo1');
            $lote = $this->bloquearLote((int)$r0['lote_id']);
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!in_array($r['status'], ['rascunho', 'correcao'], true)) {
                throw new AcervoException('O registro nº ' . $r['numero'] . ' já foi ' . ($r['status'] === 'descartado' ? 'descartado' : 'encaminhado para a revisão') . ' e não pode ser alterado pelo Grupo 1.');
            }
            if ($this->reservadoPorOutro($r, $sessao, null)) {
                throw new AcervoException('O registro nº ' . $r['numero'] . ' está sendo editado por outra pessoa. Nada foi gravado.');
            }
            $this->exigirVersao($r, $versao);
            $this->exigirCapacidade($lote, $r, (int)$d['quantidade']);
            $this->gravar($r, $d, $completo, $sessao, null);
            $this->executar('UPDATE reorg_registros SET editando_sessao_id = ?, editando_admin_id = NULL, editando_ate = NOW() + INTERVAL ? SECOND WHERE id = ?', [$sessao['id'], (int)$this->config['reserva_edicao'], $id]);
            $novo = $this->linha('SELECT versao, atualizado_em FROM reorg_registros WHERE id = ?', [$id]);
            return ['versao' => (int)$novo['versao'], 'completo' => $completo, 'erros' => $erros, 'salvo_em' => $novo['atualizado_em'], 'avisos' => $this->avisos($r, $d)];
        });
    }

    private function exigirVersao(array $r, int $versao): void
    {
        if ($versao > 0 && $versao !== (int)$r['versao']) {
            throw new RegistroConflitoException('Outra pessoa salvou o registro nº ' . $r['numero'] . ' enquanto você editava. Nada foi sobrescrito: confira os dados atuais.', $this->registro((int)$r['id']) ?? []);
        }
    }

    /** Exemplares do lote (outros registros + este) nunca passam do previsto. Chamado com o lote bloqueado. */
    private function exigirCapacidade(array $lote, array $r, int $quantidade): void
    {
        $outros = (int)$this->valor("SELECT COUNT(*) FROM reorg_registro_exemplares x JOIN reorg_registros g ON g.id = x.registro_id
                                     WHERE x.lote_id = ? AND x.registro_id <> ? AND x.removido_em IS NULL AND g.status <> 'descartado'", [$lote['id'], $r['id']]);
        $livre = (int)$lote['quantidade_prevista'] - $outros;
        if ($quantidade > $livre) {
            throw new AcervoException(self::nomeLote($lote) . ' tem ' . $lote['quantidade_prevista'] . ' exemplares previstos e ' . $outros . ' já registrados em outros formulários: '
                . 'este registro pode ter no máximo ' . max(0, $livre) . '. Nada foi gravado. Se há mais livros, avise o administrador para ajustar a quantidade.');
        }
    }

    /** Grava campos e exemplares (dentro da transação, registro bloqueado). */
    private function gravar(array $r, array $d, bool $completo, ?array $sessao, ?int $adminId): void
    {
        $campos = array_diff(self::CAMPOS, ['quantidade']);
        $sets = array_map(fn ($c) => "$c = ?", $campos);
        $this->executar('UPDATE reorg_registros SET ' . implode(', ', $sets) . ', quantidade = ?, completo = ?, versao = versao + 1, atualizado_sessao_id = ?, atualizado_nome = ?, atualizado_admin_id = ? WHERE id = ?', [
            ...array_map(fn ($c) => $d[$c], $campos), (int)$d['quantidade'], $completo ? 1 : 0,
            $sessao['id'] ?? null, $sessao['nome'] ?? null, $adminId, $r['id'],
        ]);
        if ($r['status'] === 'cadastrado') {
            return; // depois do cadastro os exemplares não mudam por aqui
        }
        $atuais = [];
        foreach ($this->todas('SELECT id, ordem FROM reorg_registro_exemplares WHERE registro_id = ?', [$r['id']]) as $x) {
            $atuais[(int)$x['ordem']] = (int)$x['id'];
        }
        for ($i = 1; $i <= (int)$d['quantidade']; $i++) {
            if (isset($atuais[$i])) {
                $this->executar('UPDATE reorg_registro_exemplares SET removido_em = NULL, estado_conservacao = ? WHERE id = ?', [$d['estados'][$i] ?? null, $atuais[$i]]);
            } else {
                $this->executar('INSERT INTO reorg_registro_exemplares (registro_id, lote_id, ordem, estado_conservacao) VALUES (?, ?, ?, ?)', [$r['id'], $r['lote_id'], $i, $d['estados'][$i] ?? null]);
            }
        }
        $this->executar('UPDATE reorg_registro_exemplares SET removido_em = NOW() WHERE registro_id = ? AND ordem > ? AND removido_em IS NULL', [$r['id'], (int)$d['quantidade']]);
    }

    /** Alertas que não impedem salvar (a revisão decide). */
    private function avisos(array $r, array $d): array
    {
        $a = [];
        if ($d['isbn'] && Isbn::valido($d['isbn'])) {
            $isbns = array_values(array_unique([$d['isbn'], Isbn::para13($d['isbn'])]));
            $ph = implode(',', array_fill(0, count($isbns), '?'));
            $outro = $this->valor("SELECT numero FROM reorg_registros WHERE lote_id = ? AND id <> ? AND status <> 'descartado' AND isbn IN ($ph) LIMIT 1", [$r['lote_id'], $r['id'], ...$isbns]);
            if ($outro) {
                $a[] = 'O registro nº ' . $outro . ' deste lote tem o mesmo ISBN. Se for a mesma obra e edição, some as quantidades em um só registro.';
            }
            if (!$r['livro_sugerido_id'] && $this->valor("SELECT 1 FROM livros WHERE isbn IN ($ph) AND arquivado_em IS NULL LIMIT 1", $isbns)) {
                $a[] = 'Já existe uma obra com este ISBN no acervo. Use “Pesquisar obra” para reaproveitar os dados dela.';
            }
        }
        if ($r['livro_sugerido_id']) {
            $o = $this->linha('SELECT isbn, editora, edicao FROM livros WHERE id = ?', [$r['livro_sugerido_id']]);
            if ($o && $o['isbn'] && $d['isbn'] && Isbn::para13($o['isbn']) !== Isbn::para13($d['isbn'])) {
                $a[] = 'O ISBN digitado é diferente do ISBN da obra escolhida na pesquisa: pode ser outra edição.';
            }
            if ($o && $o['editora'] && $d['editora'] && mb_strtolower(trim($o['editora'])) !== mb_strtolower(trim($d['editora']))) {
                $a[] = 'A editora é diferente da obra escolhida na pesquisa: pode ser outra edição.';
            }
        }
        return $a;
    }

    /** Pesquisa de obras do acervo por título, autor, ISBN ou editora (até 15). */
    public function pesquisarObras(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $isbn = Isbn::normalizar($q);
        $params = [];
        $conds = [];
        if (strlen($isbn) >= 10 && preg_match('/^[\d\-\s.Xx]+$/', $q)) {
            $conds[] = 'l.isbn IN (?, ?)';
            array_push($params, $isbn, Isbn::para13($isbn));
        }
        foreach (array_slice(self::termos($q), 0, 4) as $termo) {
            $like = self::like($termo);
            $conds[] = '(l.titulo LIKE ? OR l.editora LIKE ? OR EXISTS (SELECT 1 FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id AND a.nome LIKE ?))';
            array_push($params, $like, $like, $like);
        }
        $where = count($conds) > 1 && str_starts_with($conds[0], 'l.isbn') ? '(' . array_shift($conds) . ') OR (' . implode(' AND ', $conds) . ')' : implode(' AND ', $conds);
        return $this->todas("SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao, l.idioma, l.assunto,
                                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores,
                                    (SELECT lc.categoria_id FROM livro_categorias lc WHERE lc.livro_id = l.id AND lc.principal = 1 LIMIT 1) AS categoria_id,
                                    (SELECT COALESCE(c.cdd_aprovada, c.cdd_proposta) FROM classificacoes_cdd c WHERE c.livro_id = l.id ORDER BY c.id DESC LIMIT 1) AS cdd,
                                    (SELECT COUNT(*) FROM exemplares e WHERE e.livro_id = l.id AND e.situacao <> 'baixado') AS exemplares
                             FROM livros l WHERE l.arquivado_em IS NULL AND ($where) ORDER BY l.titulo LIMIT 15", $params);
    }

    /** O Grupo 1 escolheu (ou retirou) uma obra do acervo como referência. Continua sujeito à revisão. */
    public function sugerirObra(int $id, ?int $livroId, ?array $sessao = null, ?int $adminId = null): void
    {
        $this->transacao(function () use ($id, $livroId, $sessao, $adminId) {
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) {
                throw new AcervoException('Registro não encontrado.');
            }
            if ($sessao !== null) {
                $this->exigirAcesso($sessao, (int)$r['lote_id'], 'grupo1');
                if (!in_array($r['status'], ['rascunho', 'correcao'], true) || $this->reservadoPorOutro($r, $sessao, null)) {
                    throw new AcervoException('Este registro não pode ser alterado agora.');
                }
            } elseif (!in_array($r['status'], ['aguardando_revisao', 'aprovado', 'correcao'], true)) {
                throw new AcervoException('Este registro não pode ser alterado agora.');
            }
            if ($livroId !== null && !$this->valor('SELECT 1 FROM livros WHERE id = ? AND arquivado_em IS NULL', [$livroId])) {
                throw new AcervoException('Obra não encontrada ou arquivada.');
            }
            $this->executar('UPDATE reorg_registros SET livro_sugerido_id = ?, escolhas_obra = NULL, versao = versao + 1' . ($r['status'] === 'aprovado' ? ", status = 'aguardando_revisao', aprovado_por = NULL, aprovado_em = NULL" : '') . ' WHERE id = ?', [$livroId, $id]);
        });
    }

    /** Registros do lote para as listas (Grupo 1 e Grupo 2). $filtro: pendentes | sem_isbn | sem_cdd | ruim | correcao | aprovados | cadastrados | rascunhos | todos */
    public function registrosDoLote(int $loteId, string $filtro = 'todos'): array
    {
        $w = match ($filtro) {
            'pendentes' => "r.status = 'aguardando_revisao'",
            'sem_isbn' => "r.status <> 'descartado' AND r.isbn_nao_encontrado = 1 AND r.isbn IS NULL",
            'sem_cdd' => "r.status <> 'descartado' AND r.cdd_nao_encontrada = 1 AND r.cdd IS NULL",
            'ruim' => "r.status <> 'descartado' AND EXISTS (SELECT 1 FROM reorg_registro_exemplares x WHERE x.registro_id = r.id AND x.removido_em IS NULL AND x.estado_conservacao = 'ruim')",
            'correcao' => "r.status = 'correcao'",
            'aprovados' => "r.status = 'aprovado'",
            'cadastrados' => "r.status = 'cadastrado'",
            'rascunhos' => "r.status IN ('rascunho', 'correcao')",
            'descartados' => "r.status = 'descartado'",
            default => "r.status <> 'descartado'",
        };
        return $this->todas("SELECT r.id, r.numero, r.status, r.completo, r.titulo, r.autores, r.editora, r.isbn, r.isbn_nao_encontrado, r.cdd, r.cdd_nao_encontrada, r.edicao,
                                    r.quantidade, r.criado_nome, r.atualizado_nome, r.atualizado_em, r.livro_sugerido_id, r.livro_id, r.correcao_motivo, r.versao,
                                    r.editando_ate, s.nome AS editando_nome, a.nome AS editando_admin_nome,
                                    (SELECT GROUP_CONCAT(x.estado_conservacao ORDER BY x.ordem) FROM reorg_registro_exemplares x WHERE x.registro_id = r.id AND x.removido_em IS NULL) AS estados
                             FROM reorg_registros r LEFT JOIN reorg_sessoes s ON s.id = r.editando_sessao_id LEFT JOIN administradores a ON a.id = r.editando_admin_id
                             WHERE r.lote_id = ? AND $w ORDER BY r.numero", [$loteId]);
    }

    /** Descartar um rascunho do Grupo 1 (feito por engano). A linha fica guardada. */
    public function descartar(int $id, ?array $sessao = null, ?int $adminId = null, string $motivo = ''): void
    {
        $this->transacao(function () use ($id, $sessao, $adminId, $motivo) {
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) {
                throw new AcervoException('Registro não encontrado.');
            }
            if ($sessao !== null) {
                $this->exigirAcesso($sessao, (int)$r['lote_id'], 'grupo1');
                if (!in_array($r['status'], ['rascunho', 'correcao'], true) || $this->reservadoPorOutro($r, $sessao, null)) {
                    throw new AcervoException('Só é possível descartar um registro que ainda está em levantamento.');
                }
            } else {
                $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$r['lote_id']]);
                $this->exigirAdminDoLote($lote, (int)$adminId);
                if (in_array($r['status'], ['cadastrado', 'descartado'], true)) {
                    throw new AcervoException('Registro já cadastrado ou descartado.');
                }
            }
            $this->executar("UPDATE reorg_registros SET status = 'descartado', editando_sessao_id = NULL, editando_admin_id = NULL, editando_ate = NULL, correcao_motivo = ?, versao = versao + 1 WHERE id = ?",
                [self::textoLimpo($motivo, 500), $id]);
            $this->historico('registro_descartado', 'Registro nº ' . $r['numero'] . ' descartado' . ($motivo ? ' · ' . $motivo : ''), (int)$r['lote_id'], $sessao, $adminId);
        });
    }

    /** Quantos seriam encaminhados agora (confirmação antes de encaminhar). */
    public function previaEncaminhamento(int $loteId): array
    {
        $ok = $this->linha("SELECT COUNT(*) AS registros, COALESCE(SUM(quantidade), 0) AS exemplares FROM reorg_registros WHERE lote_id = ? AND status IN ('rascunho', 'correcao') AND completo = 1", [$loteId]);
        $inc = $this->linha("SELECT COUNT(*) AS registros, COALESCE(SUM(quantidade), 0) AS exemplares FROM reorg_registros WHERE lote_id = ? AND status IN ('rascunho', 'correcao') AND completo = 0", [$loteId]);
        $c = $this->reorg->contadores($loteId);
        return [
            'registros' => (int)$ok['registros'], 'exemplares' => (int)$ok['exemplares'],
            'incompletos_registros' => (int)$inc['registros'], 'incompletos_exemplares' => (int)$inc['exemplares'],
            'aguardando_levantamento' => $c['aguardando_levantamento'],
            'restantes' => (int)$inc['exemplares'] + $c['aguardando_levantamento'],
            'prevista' => $c['prevista'],
        ];
    }

    /**
     * Encaminha ao Grupo 2 os registros COMPLETOS (os incompletos e os ainda não registrados continuam
     * pendentes no mesmo lote). Participante do Grupo 1 ($sessao) ou administrador ($adminId).
     * @return array{registros: int, exemplares: int, restantes: int}
     */
    public function encaminhar(int $loteId, ?array $sessao = null, ?int $adminId = null): array
    {
        return $this->transacao(function () use ($loteId, $sessao, $adminId) {
            if ($sessao !== null) {
                $this->exigirAcesso($sessao, $loteId, 'grupo1');
            }
            $lote = $this->bloquearLote($loteId);
            if ($adminId !== null) {
                $this->exigirAdminDoLote($lote, $adminId);
            }
            $lista = $this->todas("SELECT * FROM reorg_registros WHERE lote_id = ? AND status IN ('rascunho', 'correcao') AND completo = 1 FOR UPDATE", [$loteId]);
            $exemplares = 0;
            $n = 0;
            foreach ($lista as $r) {
                if ($sessao !== null && $this->reservadoPorOutro($r, $sessao, null)) {
                    continue; // outra pessoa está com ele aberto: fica para o próximo encaminhamento
                }
                // Revalida no servidor (a marca "completo" é conferida de novo com os dados gravados).
                [, $erros] = $this->validar($this->paraFormulario($r));
                if ($erros) {
                    $this->executar('UPDATE reorg_registros SET completo = 0 WHERE id = ?', [$r['id']]);
                    continue;
                }
                $orig = $r['dados_originais'] ?? null;
                if ($orig === null) {
                    $orig = json_encode($this->paraFormulario($r), JSON_UNESCAPED_UNICODE);
                }
                $this->executar("UPDATE reorg_registros SET status = 'aguardando_revisao', enviado_em = NOW(), dados_originais = ?, editando_sessao_id = NULL, editando_ate = NULL, versao = versao + 1 WHERE id = ?", [$orig, $r['id']]);
                $exemplares += (int)$r['quantidade'];
                $n++;
            }
            $previa = $this->previaEncaminhamento($loteId);
            if ($exemplares > 0) {
                $this->historico('encaminhamento_g2', 'Encaminhados ao Grupo 2: ' . $exemplares . ' exemplar(es); restam ' . $previa['restantes'] . ' no levantamento', $loteId, $sessao, $adminId,
                    ['exemplares' => $exemplares, 'restantes' => $previa['restantes']]);
                $this->notificarAdmins('registros_revisao', self::nomeLote($lote) . ': ' . $exemplares . ' exemplar(es) enviados para revisão' . ($sessao ? ' por ' . $sessao['nome'] : '') . '.', $loteId, (int)$lote['responsavel_id'], 'revisao:' . $loteId);
            }
            return ['registros' => $n, 'exemplares' => $exemplares, 'restantes' => $previa['restantes']];
        });
    }

    /** Registro gravado → campos do formulário (para revalidar e para "informações originais"). */
    public function paraFormulario(array $r): array
    {
        $f = [];
        foreach (self::CAMPOS as $c) {
            $f[$c] = $r[$c] ?? null;
        }
        $f['estados'] = [];
        foreach ($this->todas('SELECT ordem, estado_conservacao FROM reorg_registro_exemplares WHERE registro_id = ? AND removido_em IS NULL ORDER BY ordem', [$r['id']]) as $x) {
            $f['estados'][(int)$x['ordem']] = $x['estado_conservacao'];
        }
        foreach (['isbn_nao_encontrado', 'cdd_nao_encontrada'] as $c) {
            $f[$c] = (int)$f[$c] ? '1' : '';
        }
        $f['isbn_situacao'] = (string)($f['isbn_situacao'] ?? '');
        $f['cdd_situacao'] = (string)($f['cdd_situacao'] ?? '');
        $f['quantidade'] = (string)(int)$f['quantidade'];
        return $f;
    }

    // =================================================================
    // Grupo 2
    // =================================================================

    /**
     * Correção administrativa (salvamento automático na revisão). Mantém o original (dados_originais).
     * Se o registro já estava aprovado, volta para "Aguardando revisão" (precisa aprovar de novo).
     */
    public function salvarRevisao(int $adminId, int $id, array $post, int $versao): array
    {
        [$d, $erros, $completo] = $this->validar($post);
        return $this->transacao(function () use ($adminId, $id, $d, $erros, $completo, $versao) {
            $r0 = $this->linha('SELECT lote_id FROM reorg_registros WHERE id = ?', [$id]);
            if (!$r0) {
                throw new AcervoException('Registro não encontrado.');
            }
            $lote = $this->bloquearLote((int)$r0['lote_id']);
            $this->exigirAdminDoLote($lote, $adminId);
            $this->exigirEtapaAberta($lote, 'grupo2');
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!in_array($r['status'], ['aguardando_revisao', 'aprovado'], true)) {
                throw new AcervoException('Só registros aguardando revisão ou aprovados (ainda não cadastrados) podem ser corrigidos aqui.');
            }
            if ($this->reservadoPorOutro($r, null, $adminId)) {
                throw new AcervoException('Outro administrador está revisando este registro. Nada foi gravado.');
            }
            $this->exigirVersao($r, $versao);
            $this->exigirCapacidade($lote, $r, (int)$d['quantidade']);
            $antes = $this->paraFormulario($r);
            $this->gravar($r, $d, $completo, null, $adminId);
            $mudou = array_keys(array_filter(self::CAMPOS, fn ($c) => (string)($antes[$c] ?? '') !== (string)($c === 'quantidade' ? $d[$c] : ($d[$c] ?? ''))));
            $this->executar('UPDATE reorg_registros SET revisado_por = ?, revisado_em = COALESCE(revisado_em, NOW()), editando_admin_id = ?, editando_sessao_id = NULL, editando_ate = NOW() + INTERVAL ? SECOND'
                . ($r['status'] === 'aprovado' && $mudou ? ", status = 'aguardando_revisao', aprovado_por = NULL, aprovado_em = NULL" : '') . ' WHERE id = ?',
                [$adminId, $adminId, (int)$this->config['reserva_edicao'], $id]);
            if ($mudou) {
                $this->historico('registro_corrigido', 'Registro nº ' . $r['numero'] . ' corrigido na revisão: ' . implode(', ', array_map(fn ($i) => self::CAMPOS[$i], $mudou)), (int)$r['lote_id'], null, $adminId);
            }
            $novo = $this->linha('SELECT versao, atualizado_em, status FROM reorg_registros WHERE id = ?', [$id]);
            return ['versao' => (int)$novo['versao'], 'completo' => $completo, 'erros' => $erros, 'salvo_em' => $novo['atualizado_em'], 'status' => $novo['status'], 'avisos' => $this->avisos($r, $d)];
        });
    }

    /** Devolve registros específicos ao Grupo 1 para correção (o resto do lote segue). */
    public function devolver(int $adminId, int $id, string $motivo): void
    {
        $motivo = self::textoLimpo($motivo, 500);
        if ($motivo === null) {
            throw new AcervoException('Explique o que precisa ser corrigido.');
        }
        $this->transacao(function () use ($adminId, $id, $motivo) {
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!$r || !in_array($r['status'], ['aguardando_revisao', 'aprovado'], true)) {
                throw new AcervoException('Só registros aguardando revisão ou aprovados podem ser devolvidos.');
            }
            $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$r['lote_id']]);
            $this->exigirAdminDoLote($lote, $adminId);
            $this->executar("UPDATE reorg_registros SET status = 'correcao', correcao_motivo = ?, aprovado_por = NULL, aprovado_em = NULL, editando_admin_id = NULL, editando_ate = NULL, versao = versao + 1 WHERE id = ?", [$motivo, $id]);
            $this->historico('correcao_solicitada', 'Registro nº ' . $r['numero'] . ' devolvido ao Grupo 1: ' . $motivo, (int)$r['lote_id'], null, $adminId);
            $this->reorg->notificarParticipantesLote((int)$r['lote_id'], 'correcao', self::nomeLote($lote) . ': o registro nº ' . $r['numero'] . ' (' . mb_substr((string)$r['titulo'], 0, 60) . ') precisa de correção — ' . $motivo, 'grupo1');
        });
    }

    /**
     * Aprova o registro (regras do cadastro permanente: título obrigatório, ISBN e ano válidos).
     * Destino: a obra escolhida (livro_sugerido_id) ou uma obra nova. Com obra do acervo de mesmo ISBN
     * e nenhuma escolhida, exige $confirmarNova (evita duplicar a obra sem querer).
     */
    public function aprovar(int $adminId, int $id, bool $confirmarNova = false, ?int $versao = null): void
    {
        $this->transacao(function () use ($adminId, $id, $confirmarNova, $versao) {
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) {
                throw new AcervoException('Registro não encontrado.');
            }
            if ($r['status'] === 'aprovado' || $r['status'] === 'cadastrado') {
                throw new AcervoException('O registro nº ' . $r['numero'] . ' já foi aprovado.');
            }
            if ($r['status'] !== 'aguardando_revisao') {
                throw new AcervoException('O registro nº ' . $r['numero'] . ' não está aguardando revisão.');
            }
            $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$r['lote_id']]);
            $this->exigirAdminDoLote($lote, $adminId);
            $this->exigirEtapaAberta($lote, 'grupo2');
            if ($this->reservadoPorOutro($r, null, $adminId)) {
                throw new AcervoException('Outro administrador está revisando o registro nº ' . $r['numero'] . '.');
            }
            if ($versao !== null && $versao > 0 && $versao !== (int)$r['versao']) {
                throw new AcervoException('Os dados do registro nº ' . $r['numero'] . ' mudaram depois que a tela foi aberta (ou ainda havia alterações sendo salvas). Nada foi aprovado: confira os dados atuais e aprove de novo.');
            }
            $problemas = $this->problemasAprovacao($r, $confirmarNova);
            if ($problemas) {
                throw new AcervoException('Registro nº ' . $r['numero'] . ': ' . implode(' ', $problemas));
            }
            $this->executar("UPDATE reorg_registros SET status = 'aprovado', aprovado_por = ?, aprovado_em = NOW(), revisado_por = COALESCE(revisado_por, ?), revisado_em = COALESCE(revisado_em, NOW()),
                             obra_nova = ?, editando_admin_id = NULL, editando_ate = NULL, versao = versao + 1 WHERE id = ?", [$adminId, $adminId, $r['livro_sugerido_id'] ? 0 : 1, $id]);
            $this->historico('registro_aprovado', 'Registro nº ' . $r['numero'] . ' aprovado (' . (int)$r['quantidade'] . ' exemplar(es); ' . ($r['livro_sugerido_id'] ? 'obra existente nº ' . $r['livro_sugerido_id'] : 'obra nova') . ')', (int)$r['lote_id'], null, $adminId);
        });
    }

    /**
     * O que impede aprovar: os mesmos requisitos do formulário do Grupo 1 (título, autor, editora, ISBN
     * válido ou "não encontrado", CDD válida ou "não encontrada", estado de cada exemplar) e as regras
     * da obra escolhida (sem duplicar obra sem querer).
     * @return list<string>
     */
    public function problemasAprovacao(array $r, bool $confirmarNova = false): array
    {
        [, $erros] = $this->validar($this->paraFormulario($r));
        $p = array_values($erros);
        if ($r['livro_sugerido_id']) {
            $o = $this->linha('SELECT isbn, arquivado_em FROM livros WHERE id = ?', [$r['livro_sugerido_id']]);
            if (!$o || $o['arquivado_em'] !== null) {
                $p[] = 'A obra escolhida não existe mais ou está arquivada.';
            }
        } elseif (!$confirmarNova && $r['isbn'] && Isbn::valido($r['isbn'])
            && $this->valor('SELECT 1 FROM livros WHERE isbn IN (?, ?) AND arquivado_em IS NULL LIMIT 1', [$r['isbn'], Isbn::para13($r['isbn'])])) {
            $p[] = 'Já existe obra com este ISBN no acervo: vincule a ela ou confirme que é uma obra nova.';
        }
        return $p;
    }

    /** Aprovação em massa: aprova os que não têm problema; devolve os pulados com o motivo. */
    public function aprovarEmMassa(int $adminId, int $loteId, array $ids): array
    {
        $ok = 0;
        $pulados = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $r = $this->linha('SELECT id, numero, lote_id FROM reorg_registros WHERE id = ?', [$id]);
            if (!$r || (int)$r['lote_id'] !== $loteId) {
                continue;
            }
            try {
                $this->aprovar($adminId, $id);
                $ok++;
            } catch (AcervoException $e) {
                $pulados[(int)$r['numero']] = $e->getMessage();
            }
        }
        return ['aprovados' => $ok, 'pulados' => $pulados];
    }

    /**
     * Cadastro oficial: cria (ou reaproveita) a obra e os exemplares com códigos BDP no lote permanente,
     * pelo cadastro normal do sistema (mesma sequência de códigos, transação única). Repetir a
     * requisição não cadastra de novo: devolve os códigos já atribuídos. Os exemplares ficam
     * disponíveis ao Grupo 3.
     * @return array{livro_id: int, obra_nova: bool, codigos: list<string>, cdd: ?string, cdd_fonte: ?string, repetido: bool}
     */
    public function cadastrar(int $adminId, int $id, ?callable $registrarCdd = null): array
    {
        // O administrador entra como participante do lote permanente ANTES da transação do cadastro:
        // assim cadastros simultâneos do mesmo administrador enxergam a participação já gravada.
        $loteId = $this->valor('SELECT lote_id FROM reorg_registros WHERE id = ?', [$id]);
        if ($loteId) {
            $loteR = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$loteId]);
            $this->exigirAdminDoLote($loteR, $adminId);
            $lp = $this->reorg->lotesPermanentes();
            if (!$lp->participa((int)$loteR['cadastro_lote_id'], $adminId)) {
                try {
                    $lp->atribuir((int)$loteR['cadastro_lote_id'], [$adminId], $adminId);
                } catch (AcervoException $e) {
                    // lote permanente encerrado: o cadastro abaixo explica o motivo
                }
            }
        }
        try {
            return $this->transacao(function () use ($adminId, $id, $registrarCdd) {
                $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
                if (!$r) {
                    throw new AcervoException('Registro não encontrado.');
                }
                if ($r['status'] === 'cadastrado') {
                    return $this->resultadoCadastro($r, true);
                }
                if ($r['status'] !== 'aprovado') {
                    throw new AcervoException('Aprove o registro nº ' . $r['numero'] . ' antes de cadastrar.');
                }
                $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$r['lote_id']]);
                $this->exigirAdminDoLote($lote, $adminId);
                $this->exigirEtapaAberta($lote, 'grupo2');
                $problemas = $this->problemasAprovacao($r, true);
                if ($problemas) {
                    throw new AcervoException('Registro nº ' . $r['numero'] . ': ' . implode(' ', $problemas));
                }
                $copias = $this->todas('SELECT id, ordem, estado_conservacao, exemplar_id FROM reorg_registro_exemplares WHERE registro_id = ? AND removido_em IS NULL ORDER BY ordem FOR UPDATE', [$id]);
                if (!$copias) {
                    throw new AcervoException('O registro não tem exemplares.');
                }
                $obraNova = !$r['livro_sugerido_id'];
                // Tudo na MESMA transação: obra (nova, ou existente com as correções aprovadas),
                // verificação, CDD, exemplares e códigos BDP. Qualquer falha desfaz tudo e o registro
                // continua "aprovado" (nada é liberado ao Grupo 3); repetir não duplica nada.
                $livroId = $obraNova ? $this->criarObra($r, $lote, $adminId) : $this->atualizarObraExistente($r, $adminId);
                if ($r['cdd'] !== null && $registrarCdd !== null) {
                    $registrarCdd($livroId, (string)$r['cdd'], (string)($r['cdd_fonte'] ?? ''));
                }

                // O lote permanente fica ativo para o administrador só durante o cadastro.
                $cadId = (int)$lote['cadastro_lote_id'];
                $anterior = $this->valor('SELECT lote_ativo_id FROM administradores WHERE id = ?', [$adminId]);
                $this->executar('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?', [$cadId, $adminId]);
                $obs = 'Reorganização do acervo · ' . $lote['nome'] . ' · registro nº ' . $r['numero'] . ($r['criado_nome'] ? ' (levantamento: ' . $r['criado_nome'] . ')' : '');
                $criados = $this->exemplares->cadastrarEmQuantidade($livroId, ['situacao' => 'disponivel', 'observacoes' => $obs],
                    array_map(fn ($c) => ['estado_conservacao' => $c['estado_conservacao']], $copias), $adminId, md5('reorg-registro-' . $id));
                $this->executar('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?', [$anterior ?: null, $adminId]);
                foreach ($copias as $i => $c) {
                    $this->executar('UPDATE reorg_registro_exemplares SET exemplar_id = ? WHERE id = ? AND exemplar_id IS NULL', [$criados[$i]['id'], $c['id']]);
                }
                $this->executar("UPDATE reorg_registros SET status = 'cadastrado', livro_id = ?, obra_nova = ?, cadastrado_por = ?, cadastrado_em = NOW(), versao = versao + 1 WHERE id = ?",
                    [$livroId, $obraNova ? 1 : 0, $adminId, $id]);
                $codigos = array_column($criados, 'codigo');
                $this->historico('codigos_gerados', 'Registro nº ' . $r['numero'] . ' cadastrado (' . ($obraNova ? 'obra nova' : 'obra existente') . ' nº ' . $livroId . '): ' . implode(', ', $codigos), (int)$lote['id'], null, $adminId,
                    ['livro_id' => $livroId, 'codigos' => $codigos]);
                $this->reorg->notificarParticipantesLote((int)$lote['id'], 'etapa_liberada', self::nomeLote($lote) . ': novos exemplares liberados para a carimbação.', 'grupo3', 'g3:' . $lote['id']);
                $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ?', [$id]);
                return $this->resultadoCadastro($r, false);
            });
        } catch (OperacaoJaConcluidaException $e) {
            // Mesmo cadastro já concluído por outra requisição simultânea.
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ?', [$id]);
            return $this->resultadoCadastro($r, true);
        }
    }

    private function resultadoCadastro(array $r, bool $repetido): array
    {
        $codigos = array_column($this->todas('SELECT e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.registro_id = ? ORDER BY x.ordem', [$r['id']]), 'codigo_individual');
        return ['livro_id' => (int)$r['livro_id'], 'obra_nova' => (bool)$r['obra_nova'], 'codigos' => $codigos, 'cdd' => $r['cdd'], 'cdd_fonte' => $r['cdd_fonte'], 'repetido' => $repetido, 'numero' => (int)$r['numero']];
    }

    /** Serviço permanente da conferência (correção, alertas cadastrais e verificação da obra). */
    private function conferencia(): ConferenciaCadastroService
    {
        return new ConferenciaCadastroService($this->pdo, new InventarioService($this->pdo, $this->exemplares, new InventarioRepository($this->pdo)));
    }

    /**
     * Obra nova pelo repositório permanente (os mesmos campos do cadastro manual). A revisão do Grupo 2
     * é a conferência: a obra sai com "Dados verificados" e os alertas cadastrais conferidos (o status
     * deixa de ser "aguardando conferência"; sem alerta aberto fica "validado"). Nada é inventado: o
     * idioma só é gravado se foi informado.
     */
    private function criarObra(array $r, array $lote, int $adminId): int
    {
        $autores = array_values(array_filter(array_map('trim', explode(';', (string)$r['autores']))));
        $livroId = (new LivroRepository($this->pdo))->criar([
            'titulo' => (string)$r['titulo'], 'subtitulo' => $r['subtitulo'], 'autores' => $autores, 'isbn' => $r['isbn'] ? Isbn::normalizar($r['isbn']) : null,
            'editora' => $r['editora'], 'ano_publicacao' => $r['ano_publicacao'], 'edicao' => $r['edicao'], 'idioma' => $r['idioma'],
            'assunto' => $r['assunto'], 'categoria' => $r['categoria_id'], 'status_validacao' => 'aguardando_conferencia',
            'observacoes_internas' => 'Cadastrada na reorganização do acervo (' . $lote['nome'] . ', registro nº ' . $r['numero'] . ').'
                . ($r['isbn_nao_encontrado'] && !$r['isbn'] ? ' ISBN não encontrado no livro.' : '') . ($r['cdd_nao_encontrada'] && !$r['cdd'] ? ' CDD não encontrada no livro.' : ''),
        ]);
        if (trim((string)$r['idioma']) === '') {
            $this->executar('UPDATE livros SET idioma = NULL WHERE id = ?', [$livroId]); // o repositório completaria com "Português"
        }
        $this->registrarAcao($adminId, 'livro.cadastrar', 'livro', $livroId, ['origem' => 'reorganizacao', 'reorg_registro_id' => (int)$r['id']]);
        $this->conferencia()->reconferirAlertas($livroId, $adminId, true);
        (new ObraVerificacaoService($this->pdo))->registrar($livroId, [], $adminId, true, 'reorganizacao');
        return $livroId;
    }

    /**
     * Vincula a uma obra existente aplicando, pelo mecanismo permanente da conferência
     * (ConferenciaCadastroService::atualizarLivro), as informações que o Grupo 2 escolheu campo a campo.
     * Campo sem informação no levantamento mantém o que a obra já tem (nada correto é apagado).
     */
    private function atualizarObraExistente(array $r, int $adminId): int
    {
        $livroId = (int)$r['livro_sugerido_id'];
        $comp = $this->comparacaoObra($r);
        if (!$comp) {
            throw new AcervoException('A obra escolhida não existe mais ou está arquivada.');
        }
        $post = [];
        foreach ($comp['campos'] as $campo => $c) {
            $post[$campo] = $c['escolha'] === 'levantamento' && $c['levantamento'] !== null && $c['levantamento'] !== '' ? $c['levantamento'] : $c['obra'];
        }
        $svc = $this->conferencia();
        $svc->atualizarLivro($livroId, $svc->dados($post), $adminId, true, true);
        return $livroId;
    }

    /**
     * Comparação do registro com a obra vinculada: valor da obra, valor levantado, divergência e a
     * escolha atual (salva em escolhas_obra; padrão: manter o que a obra tem, completar o que falta).
     * @return array{obra: array, campos: array<string, array{rotulo: string, obra: ?string, levantamento: ?string, divergente: bool, escolha: string}>}|null
     */
    public function comparacaoObra(array $r): ?array
    {
        if (!$r['livro_sugerido_id']) {
            return null;
        }
        $o = $this->linha("SELECT l.*, (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordem, a.id SEPARATOR '; ') FROM livro_autores la JOIN autores a ON a.id = la.autor_id WHERE la.livro_id = l.id) AS autores_txt,
                                  (SELECT lc.categoria_id FROM livro_categorias lc WHERE lc.livro_id = l.id AND lc.principal = 1 LIMIT 1) AS categoria_id
                           FROM livros l WHERE l.id = ? AND l.arquivado_em IS NULL", [$r['livro_sugerido_id']]);
        if (!$o) {
            return null;
        }
        $obra = ['titulo' => $o['titulo'], 'subtitulo' => $o['subtitulo'], 'autores' => $o['autores_txt'], 'editora' => $o['editora'], 'isbn' => $o['isbn'] ?: $o['isbn_original'],
            'ano_publicacao' => $o['ano_publicacao'], 'edicao' => $o['edicao'], 'idioma' => $o['idioma'], 'categoria_id' => $o['categoria_id'], 'assunto' => $o['assunto']];
        $salvas = json_decode((string)($r['escolhas_obra'] ?? ''), true) ?: [];
        $campos = [];
        foreach (self::CAMPOS_OBRA as $c) {
            $vo = $obra[$c] === null || trim((string)$obra[$c]) === '' ? null : trim((string)$obra[$c]);
            $vl = $r[$c] === null || trim((string)$r[$c]) === '' ? null : trim((string)$r[$c]);
            $norm = fn ($v) => $v === null ? '' : ($c === 'isbn' ? Isbn::para13(Isbn::normalizar($v)) : mb_strtolower(preg_replace('/\s+/u', ' ', $v)));
            $diverge = $vo !== null && $vl !== null && $norm($vo) !== $norm($vl);
            $padrao = $vo === null && $vl !== null ? 'levantamento' : 'obra';
            $escolha = in_array($salvas[$c] ?? '', ['obra', 'levantamento'], true) ? $salvas[$c] : $padrao;
            if ($vl === null) {
                $escolha = 'obra'; // levantamento sem informação nunca apaga o que a obra tem
            }
            $campos[$c] = ['rotulo' => self::ROTULOS[$c], 'obra' => $vo, 'levantamento' => $vl, 'divergente' => $diverge, 'escolha' => $escolha];
        }
        return ['obra' => $o, 'campos' => $campos];
    }

    /** Grava a escolha campo a campo (obra existente × levantamento). Só antes do cadastro. */
    public function salvarEscolhasObra(int $adminId, int $id, array $escolhas): void
    {
        $this->transacao(function () use ($adminId, $id, $escolhas) {
            $r = $this->linha('SELECT * FROM reorg_registros WHERE id = ? FOR UPDATE', [$id]);
            if (!$r || !in_array($r['status'], ['aguardando_revisao', 'aprovado'], true) || !$r['livro_sugerido_id']) {
                throw new AcervoException('Só é possível escolher as informações de um registro vinculado a uma obra e ainda não cadastrado.');
            }
            $lote = $this->linha('SELECT * FROM reorg_lotes WHERE id = ?', [$r['lote_id']]);
            $this->exigirAdminDoLote($lote, $adminId);
            $limpo = [];
            foreach (self::CAMPOS_OBRA as $c) {
                if (in_array($escolhas[$c] ?? '', ['obra', 'levantamento'], true)) {
                    $limpo[$c] = $escolhas[$c];
                }
            }
            $this->executar('UPDATE reorg_registros SET escolhas_obra = ?, versao = versao + 1, revisado_por = ?, revisado_em = COALESCE(revisado_em, NOW())'
                . ($r['status'] === 'aprovado' ? ", status = 'aguardando_revisao', aprovado_por = NULL, aprovado_em = NULL" : '') . ' WHERE id = ?',
                [json_encode($limpo), $adminId, $id]);
            $troca = array_keys(array_filter($limpo, fn ($v) => $v === 'levantamento'));
            $this->historico('escolhas_obra', 'Registro nº ' . $r['numero'] . ': informações da obra existente escolhidas' . ($troca ? ' (usar o levantamento em: ' . implode(', ', array_map(fn ($c) => self::ROTULOS[$c], $troca)) . ')' : ' (manter a obra)'), (int)$r['lote_id'], null, $adminId);
        });
    }

    /** Possíveis duplicidades: outros registros do lote e obras do acervo com o mesmo ISBN ou o mesmo título e autor. */
    public function possiveisDuplicidades(array $r): array
    {
        $saida = [];
        if ($r['isbn'] && Isbn::valido($r['isbn'])) {
            $isbns = array_values(array_unique([$r['isbn'], Isbn::para13($r['isbn'])]));
            $ph = implode(',', array_fill(0, count($isbns), '?'));
            foreach ($this->todas("SELECT numero FROM reorg_registros WHERE lote_id = ? AND id <> ? AND status <> 'descartado' AND isbn IN ($ph)", [$r['lote_id'], $r['id'], ...$isbns]) as $x) {
                $saida[] = 'Registro nº ' . $x['numero'] . ' deste lote tem o mesmo ISBN.';
            }
        }
        if ($r['titulo'] && !$r['livro_sugerido_id']) {
            foreach ($this->todas('SELECT id, titulo, editora, edicao FROM livros WHERE arquivado_em IS NULL AND titulo = ? LIMIT 3', [$r['titulo']]) as $o) {
                $saida[] = 'Já existe a obra “' . $o['titulo'] . '” (nº ' . $o['id'] . ($o['editora'] ? ', ' . $o['editora'] : '') . ($o['edicao'] ? ', ' . $o['edicao'] : '') . ') no acervo.';
            }
        }
        return $saida;
    }

    /** Cadastra todos os aprovados do lote (cada um na própria transação). */
    public function cadastrarAprovados(int $adminId, int $loteId, ?array $ids = null, ?callable $registrarCdd = null): array
    {
        $resultados = [];
        $erros = [];
        $lista = $this->todas("SELECT id, numero FROM reorg_registros WHERE lote_id = ? AND status = 'aprovado' ORDER BY numero", [$loteId]);
        foreach ($lista as $r) {
            if ($ids !== null && !in_array((int)$r['id'], array_map('intval', $ids), true)) {
                continue;
            }
            try {
                $resultados[] = $this->cadastrar($adminId, (int)$r['id'], $registrarCdd);
            } catch (AcervoException $e) {
                $erros[(int)$r['numero']] = $e->getMessage();
            }
        }
        return ['cadastrados' => $resultados, 'erros' => $erros];
    }

    /** Diferenças entre o que o Grupo 1 enviou e os dados atuais. */
    public function diferencas(array $r): array
    {
        $orig = $r['dados_originais'] ? (json_decode((string)$r['dados_originais'], true) ?: []) : [];
        if (!$orig) {
            return [];
        }
        $atual = $this->paraFormulario($r);
        $dif = [];
        foreach (self::CAMPOS as $c) {
            if ((string)($orig[$c] ?? '') !== (string)($atual[$c] ?? '')) {
                $dif[$c] = [$orig[$c] ?? null, $atual[$c] ?? null];
            }
        }
        if (json_encode(array_values((array)($orig['estados'] ?? []))) !== json_encode(array_values($atual['estados']))) {
            $dif['estados'] = [implode(', ', (array)($orig['estados'] ?? [])), implode(', ', $atual['estados'])];
        }
        return $dif;
    }
}
