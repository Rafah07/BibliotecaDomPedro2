<?php
/**
 * Testes do tratamento de ISBNs inválidos em lote (admin/isbn-invalidos.php).
 *
 * Uso: php admin/tests/isbn-lote-teste.php
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá (DB_HOST, DB_PORT,
 * DB_USER, DB_PASSWORD) para criar um banco temporário "bdp_teste_isbn_XXXX", aplica as migrações,
 * monta uma importação de exemplo e apaga o banco temporário no final.
 * O usuário precisa poder criar bancos (no XAMPP, o root pode). Para usar outro usuário, defina
 * TESTE_DB_USER e TESTE_DB_PASSWORD.
 *
 * Cobre: diagnóstico dos ISBNs, seleção de vários registros, importação sem ISBN, preservação do
 * ISBN original, livros já importados intocados, linhas com outros erros, duplicidades pendentes,
 * permissões, CSRF, registro de quem/quando e repetição da operação sem duplicar nada.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Modo interno: simula uma requisição à página em um processo separado (sessão, POST e CSRF).
if (($argv[1] ?? '') === '--requisicao') {
    $req = json_decode(base64_decode($argv[2]), true);
    $_SERVER['SCRIPT_NAME'] = '/admin/isbn-invalidos.php';
    $_SERVER['REQUEST_METHOD'] = $req['metodo'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = ['id' => $req['importacao']];
    $_POST = $req['post'];
    $_SESSION = ['auth' => ['id' => $req['usuario'], 'modo' => 'mysql'], 'csrf' => 'token-de-teste'];
    register_shutdown_function(function () {
        file_put_contents(getenv('TESTE_SAIDA'), json_encode(['flash' => $_SESSION['isbn_lote_flash'] ?? null]));
    });
    ob_start();
    require __DIR__ . '/../isbn-invalidos.php';
    ob_end_clean();
    exit;
}

use Biblioteca\Support\Isbn;
use Biblioteca\Support\IsbnDiagnostico;

require __DIR__ . '/../../config/app.php';

$falhas = 0;
$total = 0;
function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? "  ok  " : "  FALHOU  ") . $nome . ($ok || $detalhe === '' ? '' : " — $detalhe") . "\n";
}

echo "Diagnóstico dos ISBNs (sem banco)\n";
$d = IsbnDiagnostico::analisar('85-359-03282-8');
confere('11 dígitos: comprimento incorreto, sem sugestão', $d['tipo'] === 'comprimento' && $d['digitos'] === 11 && $d['sugestoes'] === []);
$d = IsbnDiagnostico::analisar('85-7056-576-6');
confere('ISBN-10 com dígito verificador errado: nenhuma correção "matemática" sugerida', $d['tipo'] === 'digito_verificador' && $d['sugestoes'] === []);
$d = IsbnDiagnostico::analisar('978-85-8392-134-6');
confere('ISBN-13 com dígito verificador errado: sem sugestão', $d['tipo'] === 'digito_verificador' && $d['sugestoes'] === []);
$d = IsbnDiagnostico::analisar('978-85-262-7986-6');
confere('978 + ISBN-10 sem recalcular: sugere o próprio ISBN-10 (mesmos dígitos)', ($d['sugestoes'][0]['isbn'] ?? null) === '8526279866' && ($d['sugestoes'][0]['confianca'] ?? '') === 'alta'
    && Isbn::valido($d['sugestoes'][0]['isbn']) && str_contains('9788526279866', $d['sugestoes'][0]['isbn']));
$d = IsbnDiagnostico::analisar('988-85-00-02746-8');
confere('prefixo 988: sugestão 978 marcada para conferência', $d['tipo'] === 'prefixo' && ($d['sugestoes'][0]['isbn'] ?? '') === '9788500027468' && $d['sugestoes'][0]['confianca'] === 'media');
$d = IsbnDiagnostico::analisar('85-O8-04045-8');
confere('letra O no lugar do zero', $d['tipo'] === 'caracteres' && ($d['sugestoes'][0]['isbn'] ?? '') === '8508040458');
$d = IsbnDiagnostico::analisar('85-08-04045-8 / 978-85-08-04045-2');
confere('dois ISBNs da mesma edição na célula: uma sugestão', $d['tipo'] === 'varios_isbns' && count($d['sugestoes']) === 1);
$d = IsbnDiagnostico::analisar('9,78857E+12');
confere('notação científica do Excel: sem sugestão', $d['tipo'] === 'notacao_cientifica' && $d['sugestoes'] === []);
$todas = array_merge(...array_map(fn ($v) => IsbnDiagnostico::analisar($v)['sugestoes'], ['85-359-03282-8', '978-85-2029-0465-7', '978-85-7531-092-5', '988-85-00-02746-8']));
confere('toda sugestão é um ISBN válido', $todas && !array_filter($todas, fn ($s) => !Isbn::valido($s['isbn'])));

// ---------------------------------------------------------------
// Banco temporário
// ---------------------------------------------------------------
$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "\n(Testes com banco não executados: configure DB_HOST/DB_USER no .env.)\n";
    echo "\n" . ($falhas ? "$falhas de $total testes falharam.\n" : "Todos os $total testes passaram.\n");
    exit($falhas ? 1 : 0);
}
$banco = 'bdp_teste_isbn_' . bin2hex(random_bytes(4));
try {
    $raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    fwrite(STDERR, "Não foi possível criar o banco temporário ($banco): " . $e->getMessage() . "\nUse um usuário que possa criar bancos (TESTE_DB_USER / TESTE_DB_PASSWORD).\n");
    exit(1);
}
register_shutdown_function(function () use ($raiz, $banco) {
    $raiz->exec("DROP DATABASE IF EXISTS `$banco`");
    echo "Banco temporário $banco apagado.\n";
});
echo "\nBanco temporário: $banco (o banco do .env não é usado)\n";
putenv("DB_HOST=$host");
putenv("DB_PORT=$porta");
putenv("DB_NAME=$banco");
putenv("DB_USER=$usuarioDb");
putenv("DB_PASSWORD=$senhaDb");
putenv('STORAGE_IMPORTACOES=' . sys_get_temp_dir() . '/' . $banco);

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../database/migrar.php') . ' 2>&1', $saida, $codigo);
if ($codigo !== 0) {
    fwrite(STDERR, "As migrações falharam no banco temporário:\n" . implode("\n", $saida) . "\n");
    exit(1);
}

$_SERVER['SCRIPT_NAME'] = '/admin/tests/isbn-lote-teste.php';
require __DIR__ . '/../includes/importacao.php';
$pdo = db();
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
    fwrite(STDERR, "Conexão inesperada: o teste só roda no banco temporário.\n");
    exit(1);
}
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();

$criarUsuario = function (string $nome, string $perfil) use ($pdo): int {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')
        ->execute([$nome, strtolower(str_replace(' ', '.', $nome)) . '@teste.br', password_hash('x', PASSWORD_DEFAULT), $perfil]);
    return (int)$pdo->lastInsertId();
};
$bibliotecaria = $criarUsuario('Ana Bibliotecaria', 'bibliotecaria');
$auxiliar = $criarUsuario('Carlos Auxiliar', 'auxiliar');
$consulta = $criarUsuario('Dora Consulta', 'consulta');

// Importação de exemplo, pelo mesmo caminho da tela: análise, revisão automática e gravação.
$repo = importacao_repo();
$cab = ['Título', 'Sobrenome/Prenome do Autor', 'Editora', 'ISBN', 'Qtd'];
$mapa = importacao_validador()->sugerirMapeamento($cab);
$linhas = [
    2 => ['Dom Casmurro', 'ASSIS, Machado de', 'Ática', '9788594452603', '2'],      // válida → gravada
    3 => ['Pipocas', 'Scliar, Moacyr', 'Companhia das Letras', '85-359-03282-8', '1'], // só ISBN (11 dígitos)
    4 => ['Noel', 'Cruz, Nelson', 'Scipione', '978-85-262-7986-6', '1'],                // só ISBN (978 + ISBN-10)
    5 => ['Otelo', 'Shakespeare, William', 'Scipione', '978-85268-378-7', 'dois'],     // ISBN + quantidade inválida
    6 => ['Dom Casmurro', 'Machado de Assis', 'Moderna', '978-00-0000-000-1', '1'],    // ISBN + possível duplicidade
    7 => ['Vidas Secas', 'Ramos, Graciliano', 'Record', '', '3'],                      // válida → gravada
    8 => ['Vidas Secas', 'RAMOS, Graciliano', 'José Olympio', '', '1'],                // duplicidade aguardando decisão
];
$impId = $repo->criar(['nome_arquivo' => 'teste.csv', 'identificador_arquivo' => hash('sha256', $banco), 'arquivo_armazenado' => 'x.csv.upload',
    'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => 8, 'colunas' => 5]], 'aba' => 'CSV', 'usuario_id' => $bibliotecaria, 'total_linhas' => 0]);
$repo->iniciarAnalise($impId, 'CSV', 1, $cab, $mapa);
$repo->inserirLinhas($impId, array_map(fn ($l) => array_combine($cab, $l), $linhas), $mapa, 8);
$repo->concluirAnalise($impId);
$repo->iniciarConfirmacao($impId);
do {
    $r = $repo->confirmarLote($impId, $bibliotecaria);
} while (!$r['concluida']);
$item = fn (int $linha) => $repo->itemPorLinha($impId, $linha);

echo "Situação inicial\n";
confere('importação concluída com pendências', $repo->buscar($impId)['status'] === 'parcial');
confere('2 livros gravados pela importação normal', $num('SELECT COUNT(*) FROM livros') === 2);
confere('linha 8 aguarda decisão de duplicidade', $item(8)['status_validacao'] === 'duplicidade' && $item(8)['acao_importacao'] === null);

$pend = $repo->pendenciasIsbn($impId);
$porLinha = array_column($pend, null, 'numero_linha');
confere('4 linhas pendentes com ISBN inválido', count($pend) === 4 && $repo->contagemIsbnInvalido($impId) === 4, implode(',', array_keys($porLinha)));
confere('linhas 3 e 4 podem ser importadas sem ISBN (ISBN é o único erro)', $porLinha[3]['avaliacao']['elegivel'] && $porLinha[4]['avaliacao']['elegivel']);
confere('linha 5 (ISBN + quantidade inválida) fica para revisão individual', !$porLinha[5]['avaliacao']['elegivel'] && str_contains(implode(' ', $porLinha[5]['avaliacao']['motivos']), 'Quantidade'));
confere('linha 6 (ISBN + possível duplicidade) fica para revisão individual', !$porLinha[6]['avaliacao']['elegivel']);
confere('linha 4 recebe sugestão confiável; linha 3 não', ($porLinha[4]['diagnostico']['sugestoes'][0]['isbn'] ?? '') === '8526279866' && $porLinha[3]['diagnostico']['sugestoes'] === []);
confere('filtro "isbn_invalido" da página de detalhes', $repo->itens($impId, 'isbn_invalido', '', 1, 50)['total'] === 4);

// Retrato do que não pode mudar.
$retrato = fn () => [
    'livros_antigos' => db_query('SELECT id, titulo, isbn, editora, observacoes_internas, status_validacao, atualizado_em FROM livros WHERE id IN (1, 2) ORDER BY id')->fetchAll(),
    'exemplares' => $num('SELECT COUNT(*) FROM exemplares'),
    'cdd' => $num('SELECT COUNT(*) FROM classificacoes_cdd'),
    'originais' => db_query('SELECT id, dados_originais_json FROM importacao_itens ORDER BY id')->fetchAll(),
    'linha8' => db_query('SELECT status_validacao, acao_importacao, processado_em, dados_corrigidos_json FROM importacao_itens WHERE id = ?', [$item(8)['id']])->fetch(),
    'linhas_5_6' => db_query('SELECT dados_corrigidos_json, status_validacao, processado_em FROM importacao_itens WHERE numero_linha IN (5, 6) AND importacao_id = ?', [$impId])->fetchAll(),
];
$antes = $retrato();
$estado = fn () => [$num('SELECT COUNT(*) FROM livros'), $num('SELECT COUNT(*) FROM registros_legados'), $num('SELECT COUNT(*) FROM importacao_itens WHERE processado_em IS NOT NULL'), db_query('SELECT dados_corrigidos_json FROM importacao_itens ORDER BY id')->fetchAll()];

/** Requisição simulada à página, em outro processo (como o navegador faria). */
$requisicao = function (int $usuario, array $post, string $metodo = 'POST') use ($impId): ?array {
    $arq = tempnam(sys_get_temp_dir(), 'bdp');
    putenv("TESTE_SAIDA=$arq");
    $dados = base64_encode(json_encode(['usuario' => $usuario, 'post' => $post, 'metodo' => $metodo, 'importacao' => $impId]));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --requisicao ' . escapeshellarg($dados) . ' 2>&1', $saida);
    $r = json_decode((string)file_get_contents($arq), true);
    unlink($arq);
    if ($r === null && $saida) {
        echo '    ' . implode("\n    ", $saida) . "\n";
    }
    return $r['flash'] ?? null;
};
$ids = fn (array $linhasNum) => array_map(fn ($l) => (string)$item($l)['id'], $linhasNum);

echo "Segurança\n";
$e0 = $estado();
$f = $requisicao($bibliotecaria, ['acao' => 'importar', 'confirmado' => '1', 'itens' => $ids([3, 4])]);
confere('sem token CSRF: recusado e nada muda', ($f['tipo'] ?? '') === 'erro' && $estado() == $e0);
$f = $requisicao($bibliotecaria, ['csrf' => 'outro', 'acao' => 'importar', 'confirmado' => '1', 'itens' => $ids([3, 4])]);
confere('token CSRF errado: recusado', ($f['tipo'] ?? '') === 'erro' && $estado() == $e0);
$f = $requisicao($auxiliar, ['csrf' => 'token-de-teste', 'acao' => 'importar', 'confirmado' => '1', 'itens' => $ids([3, 4])]);
confere('auxiliar não pode importar em lote', ($f['tipo'] ?? '') === 'erro' && str_contains($f['mensagem'], 'bibliotecária') && $estado() == $e0);
$f = $requisicao($consulta, ['csrf' => 'token-de-teste', 'acao' => 'corrigir', 'item_id' => $ids([5])[0], 'isbn' => '9788525406262']);
confere('perfil "consulta" não pode corrigir', ($f['tipo'] ?? '') === 'erro' && $estado() == $e0);
$f = $requisicao($bibliotecaria, ['csrf' => 'token-de-teste', 'acao' => 'importar', 'itens' => $ids([3, 4])]);
confere('sem confirmação, só mostra a lista (nada é gravado)', $f === null && $estado() == $e0);
$f = $requisicao($bibliotecaria, ['csrf' => 'token-de-teste', 'acao' => 'importar', 'confirmado' => '1', 'itens' => ["0 OR 1=1", "x'; DROP TABLE livros; --"]]);
confere('ids maliciosos são descartados (sem SQL injection)', ($f['tipo'] ?? '') === 'erro' && $num('SELECT COUNT(*) FROM livros') === 2 && $estado() == $e0);

echo "Importação sem ISBN (seleção de vários registros)\n";
$selecao = array_merge($ids([3, 4, 5, 6, 8, 2]), ['999999']);
$f = $requisicao($bibliotecaria, ['csrf' => 'token-de-teste', 'acao' => 'importar', 'confirmado' => '1', 'itens' => $selecao]);
$concluidas = array_column($f['concluidos'] ?? [], 'linha');
sort($concluidas);
confere('só as linhas 3 e 4 foram importadas', $concluidas === [3, 4], json_encode($f, JSON_UNESCAPED_UNICODE));
$pendLinhas = array_column($f['pendentes'] ?? [], 'linha');
sort($pendLinhas);
confere('linhas 5, 6, 8 e o id inexistente continuam pendentes, com motivo', $pendLinhas === [null, 5, 6, 8] && !array_filter($f['pendentes'], fn ($p) => !$p['motivos']));
confere('linha 2 (já importada) é pulada', array_column($f['ja_importados'] ?? [], 'linha') === [2]);
confere('2 livros novos criados', $num('SELECT COUNT(*) FROM livros') === 4);

$l3 = $item(3);
$livro3 = db_query('SELECT * FROM livros WHERE id = ?', [$l3['livro_resultante_id']])->fetch();
confere('livro criado sem ISBN', $livro3 && $livro3['isbn'] === null && $livro3['titulo'] === 'Pipocas');
confere('ISBN original preservado nos dados originais da importação', $l3['dados_originais_json']['ISBN'] === '85-359-03282-8');
confere('correção registrada à parte (ISBN vazio), sem apagar o original', ($l3['dados_corrigidos_json']['isbn'] ?? null) === '' && $l3['resultado_importacao'] === 'criado');
$legado = db_query('SELECT * FROM registros_legados WHERE importacao_item_id = ?', [$l3['id']])->fetch();
confere('registro legado ligado à linha original, com o ISBN da planilha', $legado && (int)$legado['linha'] === 3 && json_decode($legado['dados_originais'], true)['ISBN'] === '85-359-03282-8');
confere('ISBN original nas observações internas do livro', str_contains($livro3['observacoes_internas'], '85-359-03282-8') && str_contains($livro3['observacoes_internas'], 'linha 3'));
confere('livro aguardando conferência', $livro3['status_validacao'] === 'aguardando_conferencia');
confere('quem e quando: revisado_por na linha', (int)$l3['revisado_por'] === $bibliotecaria && $l3['revisado_em'] !== null);
$log = db_query("SELECT * FROM acoes_administrativas WHERE acao = 'importacao.importar_sem_isbn' ORDER BY id")->fetchAll();
confere('quem e quando: uma ação registrada por livro, com usuário, data e ISBN original', count($log) === 2 && (int)$log[0]['usuario_id'] === $bibliotecaria && $log[0]['criado_em'] !== null
    && json_decode($log[0]['detalhes'], true)['isbn_original'] === '85-359-03282-8');
confere('resumo da operação registrado', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'importacao.isbn_lote' AND usuario_id = ?", [$bibliotecaria]) === 1);

$depois = $retrato();
confere('livros já importados não foram alterados', $depois['livros_antigos'] == $antes['livros_antigos']);
confere('nenhum exemplar criado', $depois['exemplares'] === $antes['exemplares'] && $depois['exemplares'] === 0);
confere('nenhuma classificação CDD criada ou alterada', $depois['cdd'] === $antes['cdd']);
confere('dados originais de todas as linhas intactos', $depois['originais'] == $antes['originais']);
confere('duplicidade aguardando decisão não foi processada', $depois['linha8'] == $antes['linha8']);
confere('linhas com outros erros não foram alteradas', $depois['linhas_5_6'] == $antes['linhas_5_6']);
confere('consulta posterior: lista de importados sem ISBN', array_column($repo->importadosSemIsbn($impId), 'isbn_original') === ['85-359-03282-8', '978-85-262-7986-6']);
confere('importação continua com pendências', $repo->buscar($impId)['status'] === 'parcial' && $repo->contagemIsbnInvalido($impId) === 2);

echo "Repetição e integridade\n";
$e1 = $estado();
$f = $requisicao($bibliotecaria, ['csrf' => 'token-de-teste', 'acao' => 'importar', 'confirmado' => '1', 'itens' => $ids([3, 4])]);
confere('repetir a operação não duplica nada', ($f['concluidos'] ?? null) === [] && count($f['ja_importados']) === 2 && $estado() == $e1);
$r = $repo->importarSemIsbn($impId, $ids([3, 4]), $bibliotecaria);
confere('repetir direto no repositório também não duplica', $r['concluidos'] === [] && $num('SELECT COUNT(*) FROM livros') === 4);
$bloqueou = false;
try {
    db_query('UPDATE importacao_itens SET dados_originais_json = JSON_SET(dados_originais_json, "$.ISBN", "") WHERE id = ?', [$l3['id']]);
} catch (PDOException $e) {
    $bloqueou = true;
}
confere('o banco recusa apagar o ISBN original de uma linha importada', $bloqueou);
$pdo->exec("UPDATE importacoes SET status = 'concluida' WHERE id = $impId");
$erro = null;
try {
    $repo->importarSemIsbn($impId, $ids([5]), $bibliotecaria);
} catch (RuntimeException $e) {
    $erro = $e->getMessage();
}
confere('importação fechada não aceita a operação (transação desfeita)', $erro !== null && $estado() == $e1);
$pdo->exec("UPDATE importacoes SET status = 'parcial' WHERE id = $impId");

echo "Correção manual\n";
$f = $requisicao($auxiliar, ['csrf' => 'token-de-teste', 'acao' => 'corrigir', 'item_id' => $ids([5])[0], 'isbn' => '978-85-268-3787']);
confere('ISBN digitado inválido é recusado', ($f['tipo'] ?? '') === 'erro' && $item(5)['dados_corrigidos_json'] === null);
$f = $requisicao($auxiliar, ['csrf' => 'token-de-teste', 'acao' => 'corrigir', 'item_id' => $ids([5])[0], 'isbn' => '978-85-262-7986-5']);
$l5 = $item(5);
confere('ISBN válido digitado é gravado como correção', ($f['tipo'] ?? '') === 'corrigido' && ($l5['dados_corrigidos_json']['isbn'] ?? '') === '978-85-262-7986-5', json_encode($f, JSON_UNESCAPED_UNICODE));
confere('original da planilha continua guardado', $l5['dados_originais_json']['ISBN'] === '978-85268-378-7');
confere('outro erro (quantidade) continua impedindo a importação', $l5['status_validacao'] === 'erro' && $l5['processado_em'] === null);
confere('correção manual registrada com o usuário', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'importacao.corrigir' AND usuario_id = ? AND entidade_id = ?", [$auxiliar, $l5['id']]) === 1);

echo "\n" . ($falhas ? "$falhas de $total testes falharam.\n" : "Todos os $total testes passaram.\n");
exit($falhas ? 1 : 0);
