<?php
/**
 * Importa o acervo REAL da biblioteca (aba "Catalogar" da planilha DOM PEDRO BIBLIOTECA)
 * pelo mesmo fluxo da tela de importação: análise, detecção de duplicidades, registros
 * legados (CDD, código de autor, localização e quantidade antigos) e gravação em lotes.
 *
 * Uso:
 *   php database/seeds/importar-acervo.php                 importa database/seeds/dados/acervo-catalogar.csv
 *   php database/seeds/importar-acervo.php --apagar-demo   apaga antes os dados demonstrativos do acervo
 *   php database/seeds/importar-acervo.php planilha.xlsx --aba=Catalogar
 *   php database/seeds/importar-acervo.php database/seeds/dados/entrada-obras.csv --complementar
 *
 * Opções:
 *   --apagar-demo   esvazia acervo, exemplares, empréstimos, CDD, inventários e importações
 *                   (mantém administradores, alunos e o registro de ações). Pede confirmação.
 *   --sim           não pede confirmação (para rodar em scripts).
 *   --email=...     administrador registrado como responsável (padrão: o primeiro ativo).
 *   --aba=...       aba da planilha .xlsx (padrão: Catalogar).
 *   --complementar  para uma planilha com livros que em parte já estão no banco (ver abaixo).
 *
 * Decisões automáticas, iguais às que a bibliotecária faria na revisão:
 *   * linha idêntica a outra, com o mesmo ISBN, ou com o mesmo título/autor e a mesma
 *     editora, ano e edição → "outro exemplar" do livro da primeira linha;
 *   * mesmo título/autor com editora, ano ou edição diferentes → outro livro (outra edição);
 *   * as demais linhas válidas → "criar livro".
 * Linhas com erro ou com dúvidas que o script não resolve ficam pendentes e aparecem
 * em Importações → detalhes, para decidir pelo painel.
 * Nenhum exemplar ou código BDP é criado: a quantidade fica em registros_legados até a conferência física.
 *
 * Com --complementar (acervo já carregado):
 *   * linha de um livro que já está no acervo (mesmo ISBN, ou mesmo título/autor sem ano diferente)
 *     → não cria nada; só preenche o ISBN, a editora e o ano do livro quando estão VAZIOS no banco.
 *     Nenhum valor existente é trocado. A linha fica como "ignorada" na importação;
 *   * mesmo título de um livro do acervo, mas com outro autor → "revisar depois" (pode ser o mesmo
 *     livro com o autor escrito de outro jeito, ou outro livro com o mesmo nome);
 *   * as demais seguem as regras acima.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../../admin/includes/importacao.php';

use Biblioteca\Services\ImportValidationService;
use Biblioteca\Support\Isbn;
use Biblioteca\Support\Texto;

const IMPORTAR_ACERVO_LOTE = 400;

$opcoes = ['apagar-demo' => false, 'sim' => false, 'complementar' => false, 'email' => null, 'aba' => 'Catalogar'];
$arquivo = __DIR__ . '/dados/acervo-catalogar.csv';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(apagar-demo|sim|complementar)$/', $arg, $m)) {
        $opcoes[$m[1]] = true;
    } elseif (preg_match('/^--(email|aba)=(.+)$/', $arg, $m)) {
        $opcoes[$m[1]] = $m[2];
    } elseif (!str_starts_with($arg, '--')) {
        $arquivo = $arg;
    } else {
        fwrite(STDERR, "Opção desconhecida: $arg\n");
        exit(1);
    }
}

try {
    @set_time_limit(0);
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Banco não configurado. Copie .env.example para .env e preencha DB_NAME, DB_USER e DB_PASSWORD.');
    }
    if (!is_file($arquivo)) {
        throw new RuntimeException("Arquivo não encontrado: $arquivo");
    }
    if ($opcoes['complementar'] && $opcoes['apagar-demo']) {
        throw new RuntimeException('--complementar serve para acrescentar a um acervo já carregado; não use junto com --apagar-demo.');
    }
    $admin = importar_admin($pdo, $opcoes['email']);
    echo "Responsável: {$admin['nome']} <{$admin['email']}>\n";

    if ($opcoes['apagar-demo']) {
        importar_apagar_demo($pdo, $opcoes['sim']);
    } elseif (!$opcoes['complementar'] && (int)$pdo->query('SELECT COUNT(*) FROM livros')->fetchColumn() > 0) {
        echo "Aviso: já existem livros no banco. As linhas parecidas com eles ficarão pendentes para revisão.\n"
            . "Para começar do zero, rode de novo com --apagar-demo.\n";
    }

    $repo = importacao_repo();
    $leitor = importacao_leitor();
    $nome = basename($arquivo);
    $tipo = $leitor->validarArquivo($arquivo, $nome);
    $aba = $tipo === 'csv' ? 'CSV' : $opcoes['aba'];
    $abas = $leitor->abas($arquivo, $tipo);
    $infoAba = array_values(array_filter($abas, fn ($a) => $a['nome'] === $aba))[0] ?? null;
    if (!$infoAba) {
        throw new RuntimeException("A aba \"$aba\" não existe na planilha.");
    }
    // Mapeamento sugerido automaticamente (o mesmo que a tela propõe), conferido antes de gravar qualquer coisa.
    $folha = $tipo === 'csv' ? null : $aba;
    $cabecalhos = $leitor->cabecalhos($arquivo, $tipo, $folha, 1);
    $mapeamento = importacao_validador()->sugerirMapeamento(array_values($cabecalhos));
    echo "Colunas:\n";
    foreach ($mapeamento as $coluna => $campo) {
        printf("  %s → %s\n", $coluna . str_repeat(' ', max(0, 28 - mb_strlen($coluna))), $campo === 'ignorar' ? '(guardada só nos dados originais)' : ImportValidationService::CAMPOS[$campo]['label']);
    }
    if ($erros = importacao_validador()->validarMapeamento($mapeamento)) {
        throw new RuntimeException(implode(' ', $erros) . ' Nada foi gravado.');
    }

    $hash = hash_file('sha256', $arquivo);
    if ($existente = $repo->buscarPorArquivo($hash)) {
        $existente = $repo->buscar((int)$existente['id']);
        $gravou = (int)$existente['total_importados'] + (int)$existente['total_vinculados'] > 0
            || (int)$pdo->query('SELECT COUNT(*) FROM importacao_itens WHERE importacao_id = ' . (int)$existente['id'] . ' AND processado_em IS NOT NULL')->fetchColumn() > 0;
        if (in_array($existente['status'], ['enviada', 'em_analise', 'em_revisao'], true) && !$gravou) {
            // Tentativa anterior interrompida antes de gravar no acervo: é encerrada e a importação recomeça.
            $repo->cancelar((int)$existente['id']);
            importar_registrar($pdo, $admin['id'], 'importacao.cancelar', (int)$existente['id'], ['motivo' => 'tentativa anterior interrompida (linha de comando)']);
            echo "A tentativa anterior (importação nº {$existente['id']}) não gravou nada e foi cancelada. Recomeçando.\n";
        } elseif ($existente['status'] === 'importando') {
            echo "Retomando a gravação da importação nº {$existente['id']}, interrompida no meio.\n";
            importar_gravar($repo, $pdo, $admin['id'], (int)$existente['id']);
            exit(0);
        } else {
            throw new RuntimeException('Este arquivo já foi importado (importação nº ' . $existente['id'] . ', ' . $existente['status'] . '). Nada foi alterado.');
        }
    }

    // Cópia na área privada, como no envio pela tela, para a importação poder ser reaberta no painel.
    $armazenado = bin2hex(random_bytes(16)) . '.' . $tipo . '.upload';
    if (!copy($arquivo, importacao_pasta() . DIRECTORY_SEPARATOR . $armazenado)) {
        throw new RuntimeException('Não foi possível copiar o arquivo para a pasta de importações.');
    }
    $id = $repo->criar([
        'nome_arquivo' => $nome,
        'identificador_arquivo' => $hash,
        'arquivo_armazenado' => $armazenado,
        'tipo_arquivo' => $tipo,
        'tamanho_bytes' => (int)filesize($arquivo),
        'abas' => $abas,
        'aba' => $aba,
        'usuario_id' => $admin['id'],
        'total_linhas' => 0,
    ]);
    importar_registrar($pdo, $admin['id'], 'importacao.enviar', $id, ['arquivo' => $nome, 'tipo' => $tipo, 'origem' => 'linha de comando']);

    $repo->iniciarAnalise($id, $aba, 1, $cabecalhos, $mapeamento);
    $ultima = (int)$infoAba['linhas'];
    for ($inicio = 2; $inicio <= $ultima; $inicio += IMPORTAR_ACERVO_LOTE) {
        $fim = min($inicio + IMPORTAR_ACERVO_LOTE - 1, $ultima);
        $repo->inserirLinhas($id, $leitor->lerLinhas($arquivo, $tipo, $folha, $inicio, IMPORTAR_ACERVO_LOTE, $cabecalhos), $mapeamento, $fim);
        echo "\rAnalisando... linha $fim de $ultima";
    }
    echo "\n";
    $repo->concluirAnalise($id);
    $analise = $repo->buscar($id);
    importar_registrar($pdo, $admin['id'], 'importacao.validar', $id, [
        'linhas' => (int)$analise['total_linhas'], 'validos' => (int)$analise['total_validos'], 'avisos' => (int)$analise['total_avisos'],
        'erros' => (int)$analise['total_erros'], 'duplicidades' => (int)$analise['total_duplicidades'],
    ]);

    // Outros exemplares: linhas idênticas, com o mesmo ISBN, ou com o mesmo título/autor da mesma edição.
    $exemplares = $repo->decidirEmLote($id, 'linha_identica', 'outro_exemplar', $admin['id']);
    $edicoes = 0;
    $stmt = $pdo->prepare(
        "SELECT a.id, a.linha_relacionada, a.erros_json, a.dados_normalizados_json AS dados, r.dados_normalizados_json AS dados_ref, r.acao_importacao AS acao_ref
         FROM importacao_itens a
         JOIN importacao_itens r ON r.importacao_id = a.importacao_id AND r.numero_linha = a.linha_relacionada
         WHERE a.importacao_id = ? AND a.status_validacao = 'duplicidade' AND a.acao_automatica = 1 ORDER BY a.numero_linha"
    );
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $item) {
        $codigos = array_column(array_filter(json_decode($item['erros_json'], true) ?? [], fn ($p) => $p['nivel'] === 'duplicidade'), 'codigo');
        if ($item['acao_ref'] !== 'criar' || !in_array($codigos, [['mesmo_isbn'], ['obra_semelhante']], true)) {
            continue; // fica para a revisão no painel
        }
        $mesmaEdicao = $codigos === ['mesmo_isbn'] || importar_mesma_edicao(json_decode($item['dados'], true), json_decode($item['dados_ref'], true));
        $repo->decidir((int)$item['id'], $mesmaEdicao ? 'outro_exemplar' : 'criar', null, $mesmaEdicao ? (int)$item['linha_relacionada'] : null, $admin['id']);
        $mesmaEdicao ? $exemplares++ : $edicoes++;
    }
    importar_registrar($pdo, $admin['id'], 'importacao.decidir_lote', $id, ['outro_exemplar' => $exemplares, 'outra_edicao' => $edicoes]);

    if ($opcoes['complementar']) {
        $c = importar_complementar($repo, $pdo, $admin['id'], $id);
        $revisar = importar_revisar_titulos_iguais($repo, $pdo, $admin['id'], $id);
        importar_registrar($pdo, $admin['id'], 'importacao.decidir_lote', $id, $c + ['revisar_titulo_igual' => $revisar]);
        echo "Já estavam no acervo: {$c['ja_no_acervo']} linhas ({$c['livros_complementados']} livros ganharam ISBN, editora ou ano que faltavam)\n"
            . "Mesmo título de um livro do acervo, com outro autor (revisar depois): $revisar\n";
    }

    $resumo = $repo->resumoConfirmacao($id);
    echo "Livros novos: {$resumo['criar']} · outros exemplares: {$resumo['outro_exemplar']} · pendentes para revisão: "
        . ($resumo['sem_decisao'] + $resumo['com_erro'] + $resumo['revisar_depois']) . "\n";

    importar_registrar($pdo, $admin['id'], 'importacao.confirmar', $id, $resumo);
    importar_gravar($repo, $pdo, $admin['id'], $id);
} catch (Throwable $e) {
    fwrite(STDERR, "\nErro: " . $e->getMessage() . "\n");
    exit(1);
}

/** Mesmo título e autor: é a mesma edição se editora, ano e número da edição coincidem (vazio não conta como diferença). */
function importar_mesma_edicao(array $a, array $b): bool
{
    $igual = fn (?string $x, ?string $y) => $x === null || $y === null || $x === '' || $y === '' || $x === $y;
    $editora = fn (array $n) => Texto::chave($n['editora'] ?? null) ?: null;
    $edicao = fn (array $n) => preg_replace('/\D+/', '', (string)($n['edicao'] ?? '')) ?: null;
    $ano = fn (array $n) => isset($n['ano_publicacao']) ? (string)$n['ano_publicacao'] : null;
    return $igual($editora($a), $editora($b)) && $igual($ano($a), $ano($b)) && $igual($edicao($a), $edicao($b));
}

/** Administrador responsável: o e-mail informado ou o primeiro administrador/bibliotecária ativo. */
function importar_admin(PDO $pdo, ?string $email): array
{
    $sql = "SELECT id, nome, email FROM administradores WHERE ativo = 1 AND perfil IN ('administrador', 'bibliotecaria')";
    $stmt = $email
        ? $pdo->prepare($sql . ' AND email = ?')
        : $pdo->prepare($sql . " ORDER BY perfil = 'administrador' DESC, id LIMIT 1");
    $stmt->execute($email ? [mb_strtolower($email)] : []);
    $admin = $stmt->fetch();
    if (!$admin) {
        throw new RuntimeException($email
            ? "Nenhum administrador ou bibliotecária ativo com o e-mail $email."
            : 'Crie primeiro um usuário administrativo: php database/criar-admin.php "Nome" email administrador');
    }
    $admin['id'] = (int)$admin['id'];
    return $admin;
}

/**
 * Esvazia os dados demonstrativos. Usa TRUNCATE porque as tabelas de histórico (CDD,
 * registros legados, empréstimos) têm gatilhos que proíbem DELETE — de propósito, para o uso normal.
 */
function importar_apagar_demo(PDO $pdo, bool $semPergunta): void
{
    $tabelas = [
        'cdd_lote_itens', 'cdd_lotes', 'cdd_consultas', 'cdd_pesquisas', 'classificacoes_cdd_historico', 'classificacoes_cdd',
        'registros_legados', 'importacao_itens', 'importacoes',
        'inventario_conferencias', 'inventarios', 'emprestimos', 'movimentacoes', 'exemplares',
        'livro_autores', 'livro_categorias', 'livros', 'autores', 'categorias', 'localizacoes',
    ];
    $contar = fn (string $t) => (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "Serão apagados: {$contar('livros')} livros, {$contar('exemplares')} exemplares, {$contar('emprestimos')} empréstimos e "
        . "{$contar('importacoes')} importações. Administradores e alunos são mantidos.\n";
    if (!$semPergunta) {
        echo 'Digite APAGAR para confirmar: ';
        if (trim((string)fgets(STDIN)) !== 'APAGAR') {
            throw new RuntimeException('Cancelado. Nada foi alterado.');
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tabelas as $t) {
            $pdo->exec("TRUNCATE TABLE $t");
        }
        $pdo->exec('UPDATE exemplar_codigo_seq SET ultimo = 0 WHERE id = 1');
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    // Os arquivos das importações apagadas não servem mais.
    foreach (glob(importacao_pasta() . DIRECTORY_SEPARATOR . '*.upload') ?: [] as $f) {
        @unlink($f);
    }
    echo "Dados demonstrativos apagados.\n";
}

function importar_registrar(PDO $pdo, int $usuarioId, string $acao, int $importacaoId, array $detalhes): void
{
    $pdo->prepare("INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, ?, 'importacao', ?, ?, 'cli')")
        ->execute([$usuarioId, $acao, $importacaoId, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
}

/** Grava no acervo, em lotes, as linhas aprovadas (também retoma uma gravação interrompida). */
function importar_gravar(\Biblioteca\Repositories\ImportacaoRepository $repo, PDO $pdo, int $adminId, int $id): void
{
    $repo->iniciarConfirmacao($id);
    do {
        $r = $repo->confirmarLote($id, $adminId, 200);
        echo "\rGravando no acervo... faltam {$r['restantes']}   ";
    } while (!$r['concluida']);
    echo "\n";

    $final = $repo->buscar($id);
    importar_registrar($pdo, $adminId, 'importacao.concluir', $id, [
        'status' => $final['status'], 'criados' => (int)$final['total_importados'],
        'vinculados' => (int)$final['total_vinculados'], 'falhas' => (int)$final['total_falhas'],
    ]);
    $pendentes = $repo->resumoConfirmacao($id);
    echo "\nImportação nº $id " . ($final['status'] === 'concluida' ? 'concluída' : 'concluída com pendências') . ":\n"
        . "  livros criados ............ {$final['total_importados']}\n"
        . "  outros exemplares ......... {$final['total_vinculados']}\n"
        . "  falharam ao gravar ........ {$final['total_falhas']}\n"
        . "  aguardando decisão ........ {$pendentes['sem_decisao']}\n"
        . "  com erro na planilha ...... {$pendentes['com_erro']}\n"
        . "Revise as pendências no painel: Importações → importação nº $id.\n";
}

/**
 * --complementar: as linhas de livros que já estão no acervo não criam nada. Só preenchem o ISBN,
 * a editora e o ano quando esses campos estão vazios no livro (nada existente é trocado), e ficam
 * como "ignorar". Mesmo título/autor com ano diferente pode ser outra edição: fica para a revisão.
 * @return array{ja_no_acervo: int, livros_complementados: int}
 */
function importar_complementar(\Biblioteca\Repositories\ImportacaoRepository $repo, PDO $pdo, int $adminId, int $id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, numero_linha, livro_sugerido_id, erros_json, dados_normalizados_json AS dados FROM importacao_itens
         WHERE importacao_id = ? AND ja_cadastrado = 1 AND livro_sugerido_id IS NOT NULL AND processado_em IS NULL
           AND acao_automatica = 1 AND status_validacao <> 'erro' ORDER BY numero_linha"
    );
    $stmt->execute([$id]);
    $nomeArquivo = $repo->buscar($id)['nome_arquivo'];
    $livroStmt = $pdo->prepare('SELECT isbn, editora, ano_publicacao FROM livros WHERE id = ? AND arquivado_em IS NULL FOR UPDATE');
    $isbnEmUso = $pdo->prepare('SELECT id FROM livros WHERE isbn_ativo IN (?, ?) LIMIT 1');
    $anoMax = (int)date('Y') + 1;
    $total = 0;
    $livros = [];
    foreach ($stmt->fetchAll() as $item) {
        $n = json_decode($item['dados'], true);
        $porIsbn = in_array('ja_cadastrado', array_column(json_decode($item['erros_json'], true) ?? [], 'codigo'), true);
        $livroId = (int)$item['livro_sugerido_id'];
        $ano = $n['ano_publicacao'] !== null && $n['ano_publicacao'] >= 1450 && $n['ano_publicacao'] <= $anoMax ? (int)$n['ano_publicacao'] : null;

        $pdo->beginTransaction();
        try {
            $livroStmt->execute([$livroId]);
            $livro = $livroStmt->fetch();
            if (!$livro || (!$porIsbn && $ano !== null && $livro['ano_publicacao'] !== null && (int)$livro['ano_publicacao'] !== $ano)) {
                $pdo->rollBack();
                continue; // livro arquivado ou possível outra edição: fica para a revisão no painel
            }
            $novos = [];
            if ($livro['isbn'] === null && $n['isbn'] !== null && Isbn::valido($n['isbn'])) {
                $isbnEmUso->execute([$n['isbn'], Isbn::para13($n['isbn'])]);
                if (!$isbnEmUso->fetchColumn()) {
                    $novos['isbn'] = $n['isbn'];
                }
            }
            if ($livro['editora'] === null && $n['editora'] !== null) {
                $novos['editora'] = mb_substr($n['editora'], 0, 150);
            }
            if ($livro['ano_publicacao'] === null && $ano !== null) {
                $novos['ano_publicacao'] = $ano;
            }
            if ($novos) {
                $nota = 'Complementado com a planilha "' . $nomeArquivo . '", linha ' . $item['numero_linha'] . ': ' . implode(', ', array_keys($novos)) . '.';
                $pdo->prepare(
                    'UPDATE livros SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($novos)))
                    . ", observacoes_internas = CONCAT_WS('\n', observacoes_internas, ?) WHERE id = ?"
                )->execute([...array_values($novos), $nota, $livroId]);
                $detalhes = ['importacao' => $id, 'linha' => (int)$item['numero_linha']];
                foreach ($novos as $campo => $valor) {
                    $detalhes[$campo] = ['antes' => null, 'depois' => $valor];
                }
                $pdo->prepare("INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, 'livro.complementar', 'livro', ?, ?, 'cli')")
                    ->execute([$adminId, $livroId, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
                $livros[$livroId] = true;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $repo->decidir((int)$item['id'], 'ignorar', null, null, $adminId);
        $total++;
    }
    return ['ja_no_acervo' => $total, 'livros_complementados' => count($livros)];
}

/**
 * --complementar: linha que seria criada, mas com o mesmo título de um livro do acervo (autor diferente)
 * → "revisar depois". As cópias dela na planilha também, para não falharem sem a linha de referência.
 */
function importar_revisar_titulos_iguais(\Biblioteca\Repositories\ImportacaoRepository $repo, PDO $pdo, int $adminId, int $id): int
{
    $chave = fn (?string $t) => preg_replace('/^(o|a|os|as|um|uma|the) /', '', Texto::chave($t));
    $titulos = [];
    foreach ($pdo->query('SELECT titulo FROM livros WHERE arquivado_em IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $titulos[$chave($t)] = true;
    }
    $stmt = $pdo->prepare(
        "SELECT id, acao_importacao, item_referencia_id, dados_normalizados_json AS dados FROM importacao_itens
         WHERE importacao_id = ? AND processado_em IS NULL AND acao_importacao IN ('criar', 'outro_exemplar') ORDER BY numero_linha"
    );
    $stmt->execute([$id]);
    $marcados = [];
    foreach ($stmt->fetchAll() as $item) {
        $n = json_decode($item['dados'], true);
        $copiaDeMarcada = $item['acao_importacao'] === 'outro_exemplar' && isset($marcados[(int)$item['item_referencia_id']]);
        if ($copiaDeMarcada || ($item['acao_importacao'] === 'criar' && isset($titulos[$chave($n['titulo'])]))) {
            $repo->decidir((int)$item['id'], 'revisar_depois', null, null, $adminId);
            $marcados[(int)$item['id']] = true;
        }
    }
    return count($marcados);
}
