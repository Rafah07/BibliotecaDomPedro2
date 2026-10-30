<?php
/**
 * Testes da circulação: empréstimo, devolução, renovação, atrasos, concorrência, permissões,
 * pesquisas, histórico e integridade em falhas.
 *
 * Uso: php admin/tests/emprestimos-teste.php
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá para criar um banco
 * temporário "bdp_teste_emprestimos_XXXX", aplica as migrações, cria alunos, livros e exemplares
 * FICTÍCIOS só nesse banco e o apaga no final. Nenhum dado real de aluno é usado.
 * O usuário precisa poder criar bancos (no XAMPP, o root pode). Para outro usuário, defina
 * TESTE_DB_USER e TESTE_DB_PASSWORD.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Services\CirculacaoException;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Support\RegrasCirculacao;

require __DIR__ . '/../../config/app.php';

// Processo filho do teste de concorrência: tenta emprestar o exemplar e imprime o resultado.
if (($argv[1] ?? '') === '--filho') {
    [, , $alunoFilho, $exemplarFilho, $responsavelFilho, $inicio] = $argv;
    $servicoFilho = new EmprestimoService(db(), new RegrasCirculacao(fuso: 'America/Sao_Paulo'));
    while (microtime(true) < (float)$inicio) {
        usleep(500);
    }
    try {
        $servicoFilho->registrar((int)$alunoFilho, (int)$exemplarFilho, $servicoFilho->prevista(), (int)$responsavelFilho);
        echo 'ok';
    } catch (CirculacaoException $e) {
        echo 'recusado';
    } catch (Throwable $e) {
        echo 'erro:' . get_class($e);
    }
    exit(0);
}

$falhas = 0;
$total = 0;
function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? '  ok  ' : '  FALHOU  ') . $nome . ($ok || $detalhe === '' ? '' : " — $detalhe") . "\n";
}

/** Executa $fn e devolve a mensagem da CirculacaoException (ou null se não houve recusa). */
function recusa(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (CirculacaoException $e) {
        return $e->getMessage();
    }
}

$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "Testes não executados: configure DB_HOST/DB_USER no .env.\n";
    exit(0);
}
$banco = 'bdp_teste_emprestimos_' . bin2hex(random_bytes(4));
$raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$servidor = null;
register_shutdown_function(function () use ($raiz, $banco, &$servidor) {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
    }
    $raiz->exec("DROP DATABASE IF EXISTS `$banco`");
    echo "Banco temporário $banco apagado.\n";
});
echo "Banco temporário: $banco (o banco do .env não é usado)\n";
$envTeste = ['DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_NAME' => $banco, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/', 'APP_TIMEZONE' => 'America/Sao_Paulo',
    'EMPRESTIMO_PRAZO_DIAS' => '', 'EMPRESTIMO_MAX_RENOVACOES' => '', 'EMPRESTIMO_RENOVACAO_DIAS' => '', 'EMPRESTIMO_RENOVAR_ATRASADO' => ''];
foreach ($envTeste as $k => $val) {
    putenv("$k=$val");
}
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../database/migrar.php') . ' 2>&1', $saida, $codigo);
if ($codigo !== 0) {
    fwrite(STDERR, "As migrações falharam:\n" . implode("\n", $saida) . "\n");
    exit(1);
}
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
    fwrite(STDERR, "Conexão inesperada: o teste só roda no banco temporário.\n");
    exit(1);
}
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();

// ---------------------------------------------------------------
// Dados fictícios (somente no banco temporário)
// ---------------------------------------------------------------
$senhaPadrao = 'SenhaDeTeste123';
$contas = [];
foreach ([['Ana Admin', 'admin@teste.br', 'administrador'], ['Bia Bibliotecaria', 'bib@teste.br', 'bibliotecaria'],
          ['Caio Auxiliar', 'aux@teste.br', 'auxiliar'], ['Duda Consulta', 'cons@teste.br', 'consulta']] as [$n, $em, $p]) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute([$n, $em, password_hash($senhaPadrao, PASSWORD_DEFAULT), $p]);
    $contas[$p] = (int)$pdo->lastInsertId();
}
$aluno = [];
foreach ([
    ['joao', 'João Fictício da Silva', 'TESTE-0001', 'joao.ficticio@escola.teste', '9A', 1],
    ['maria1', 'Maria Exemplo', 'TESTE-0002', 'maria.exemplo2@escola.teste', '8B', 1],
    ['maria2', 'Maria Exemplo', 'TESTE-0003', 'maria.exemplo3@escola.teste', '7C', 1],
    ['inativo', 'Pedro Inativo Fictício', 'TESTE-0004', null, '9A', 0],
    ['xss', '<script>alert(1)</script> Aluno', 'TESTE-0005', null, null, 1],
] as [$chave, $nome, $mat, $email, $turma, $ativo]) {
    $pdo->prepare('INSERT INTO alunos (nome, matricula, email, turma, ativo) VALUES (?, ?, ?, ?, ?)')->execute([$nome, $mat, $email, $turma, $ativo]);
    $aluno[$chave] = (int)$pdo->lastInsertId();
}
for ($i = 6; $i <= 25; $i++) {
    $pdo->prepare('INSERT INTO alunos (nome, matricula) VALUES (?, ?)')->execute(["Aluno Concorrente $i", sprintf('TESTE-%04d', $i)]);
}
$livro = [];
foreach ([
    ['dom', 'Dom Casmurro de Teste', '9788535902778', 'manual', 'Machado Fictício'],
    ['planilha', 'Livro da Planilha Sem Inventário', null, 'planilha', 'Autora Antiga'],
    ['aguardando', 'Livro Aguardando Conferência', null, 'manual', null],
    ['manut', 'Livro em Manutenção', null, 'manual', null],
] as [$chave, $titulo, $isbn, $origem, $autor]) {
    $pdo->prepare('INSERT INTO livros (titulo, isbn, origem_cadastro) VALUES (?, ?, ?)')->execute([$titulo, $isbn, $origem]);
    $livro[$chave] = (int)$pdo->lastInsertId();
    if ($autor) {
        $pdo->prepare('INSERT INTO autores (nome) VALUES (?)')->execute([$autor]);
        $pdo->prepare('INSERT INTO livro_autores (livro_id, autor_id) VALUES (?, ?)')->execute([$livro[$chave], (int)$pdo->lastInsertId()]);
    }
}
$gerar = function (int $livroId, int $qtd, string $situacao) use ($pdo): array {
    $st = $pdo->prepare('CALL gerar_exemplares(?, ?, NULL, ?, ?)');
    $st->execute([$livroId, $qtd, 'bom', $situacao]);
    $ids = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $st->closeCursor();
    return $ids;
};
$exDom = $gerar($livro['dom'], 6, 'disponivel');          // BDP-000001 ... BDP-000006
$exAguardando = $gerar($livro['aguardando'], 1, 'aguardando')[0];
$exManut = $gerar($livro['manut'], 1, 'manutencao')[0];

$regras = new RegrasCirculacao(fuso: 'America/Sao_Paulo');
$servico = new EmprestimoService($pdo, $regras);
$repo = new EmprestimoRepository($pdo);
$hoje = $regras->hoje();
$codigo = fn (int $exId) => (string)db_query('SELECT codigo_individual FROM exemplares WHERE id = ?', [$exId])->fetchColumn();
$situacao = fn (int $exId) => (string)db_query('SELECT situacao FROM exemplares WHERE id = ?', [$exId])->fetchColumn();

// ---------------------------------------------------------------
echo "\nRegras configuráveis e fuso horário\n";
$padrao = RegrasCirculacao::doAmbiente(fn ($k) => null);
confere('sem .env: prazo provisório de 14 dias, sem limite de renovações, fuso America/Sao_Paulo', $padrao->prazoDias === 14 && $padrao->prazoProvisorio && $padrao->maxRenovacoes === null && $padrao->fuso === 'America/Sao_Paulo');
$cfg = RegrasCirculacao::doAmbiente(fn ($k) => ['EMPRESTIMO_PRAZO_DIAS' => '7', 'EMPRESTIMO_MAX_RENOVACOES' => '2', 'EMPRESTIMO_RENOVAR_ATRASADO' => 'false', 'APP_TIMEZONE' => 'America/Manaus'][$k] ?? null);
confere('valores do .env são usados (7 dias, 2 renovações, sem renovar atrasado, Manaus)', $cfg->prazoDias === 7 && $cfg->renovacaoDias === 7 && $cfg->maxRenovacoes === 2 && !$cfg->renovarAtrasado && $cfg->fuso === 'America/Manaus' && !$cfg->prazoProvisorio);
confere('valores inválidos no .env voltam ao padrão', RegrasCirculacao::doAmbiente(fn ($k) => ['EMPRESTIMO_PRAZO_DIAS' => 'abc', 'APP_TIMEZONE' => 'Lua/Base'][$k] ?? null)->prazoDias === 14
    && RegrasCirculacao::doAmbiente(fn ($k) => ['APP_TIMEZONE' => 'Lua/Base'][$k] ?? null)->fuso === 'America/Sao_Paulo');
$kiritimati = new RegrasCirculacao(fuso: 'Pacific/Kiritimati');
$pagoPago = new RegrasCirculacao(fuso: 'Pacific/Pago_Pago');
confere('"hoje" segue o fuso configurado (UTC+14 e UTC−11 ficam em dias diferentes)', $kiritimati->hoje() === (new DateTime('now', new DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d') && $kiritimati->hoje() !== $pagoPago->hoje());
confere('prazo padrão = hoje + 14 dias', $servico->prevista() === (new DateTimeImmutable($hoje))->modify('+14 days')->format('Y-m-d'));

// ---------------------------------------------------------------
echo "\n1. Registro de empréstimo\n";
$e1 = $servico->registrar($aluno['joao'], $exDom[0], $servico->prevista(), $contas['bibliotecaria'], 'Capa com marca de uso');
confere('empréstimo registrado', $e1['id'] > 0 && $e1['codigo'] === 'BDP-000001');
$linha = db_query('SELECT * FROM emprestimos WHERE id = ?', [$e1['id']])->fetch();
confere('aluno, exemplar, prazo e responsável gravados', (int)$linha['aluno_id'] === $aluno['joao'] && (int)$linha['exemplar_id'] === $exDom[0] && $linha['data_prevista'] === $servico->prevista() && (int)$linha['emprestado_por'] === $contas['bibliotecaria']);
confere('data do empréstimo no horário da escola', substr($linha['data_emprestimo'], 0, 10) === $hoje);
confere('exemplar passou para "emprestado"', $situacao($exDom[0]) === 'emprestado');
confere('movimentação "emprestimo" ligada ao empréstimo', $num("SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ? AND emprestimo_id = ? AND tipo = 'emprestimo' AND responsavel_id = ?", [$exDom[0], $e1['id'], $contas['bibliotecaria']]) === 1);
confere('ação administrativa registrada', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'emprestimo.registrar' AND entidade_id = ? AND usuario_id = ?", [$e1['id'], $contas['bibliotecaria']]) === 1);
$logs = (string)db_query("SELECT CONCAT(GROUP_CONCAT(COALESCE(detalhes, '')), (SELECT GROUP_CONCAT(COALESCE(descricao, '')) FROM movimentacoes)) FROM acoes_administrativas")->fetchColumn();
confere('registros de ação e movimentações sem nome, matrícula ou e-mail do aluno', !str_contains($logs, 'João') && !str_contains($logs, 'TESTE-0001') && !str_contains($logs, 'joao.ficticio'));
confere('data prevista no passado é recusada', recusa(fn () => $servico->registrar($aluno['joao'], $exDom[1], date('Y-m-d', strtotime($hoje . ' -1 day')), $contas['bibliotecaria'])) !== null && $situacao($exDom[1]) === 'disponivel');
confere('data prevista além do prazo máximo é recusada', recusa(fn () => $servico->registrar($aluno['joao'], $exDom[1], date('Y-m-d', strtotime($hoje . ' +400 days')), $contas['bibliotecaria'])) !== null);
$e2 = $servico->registrar($aluno['maria1'], $exDom[1], date('Y-m-d', strtotime($hoje . ' +30 days')), $contas['administrador']);
confere('data prevista escolhida (30 dias) é aceita', db_query('SELECT data_prevista FROM emprestimos WHERE id = ?', [$e2['id']])->fetchColumn() === date('Y-m-d', strtotime($hoje . ' +30 days')));

// ---------------------------------------------------------------
echo "\n5. Bloqueio de empréstimo duplicado do mesmo exemplar\n";
confere('mesmo exemplar não é emprestado de novo', str_contains((string)recusa(fn () => $servico->registrar($aluno['maria2'], $exDom[0], $servico->prevista(), $contas['bibliotecaria'])), 'já está emprestado'));
$dup = null;
try {
    $pdo->prepare('INSERT INTO emprestimos (exemplar_id, aluno_id, data_prevista) VALUES (?, ?, ?)')->execute([$exDom[0], $aluno['maria2'], $servico->prevista()]);
} catch (PDOException $e) {
    $dup = $e->getCode();
}
confere('o banco também recusa um segundo empréstimo aberto (índice único)', $dup === '23000');
confere('continua 1 empréstimo aberto para o exemplar', $num('SELECT COUNT(*) FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$exDom[0]]) === 1);

// ---------------------------------------------------------------
echo "\n6. Tentativas simultâneas de emprestar o mesmo exemplar\n";
$alvo = $exDom[2];
$inicio = microtime(true) + 1.5;
$processos = [];
for ($i = 0; $i < 10; $i++) {
    $alunoConc = $num('SELECT id FROM alunos WHERE matricula = ?', [sprintf('TESTE-%04d', 6 + $i)]);
    $processos[] = proc_open([PHP_BINARY, __FILE__, '--filho', (string)$alunoConc, (string)$alvo, (string)$contas['bibliotecaria'], (string)$inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), $envTeste));
}
$resultados = [];
foreach ($processos as $i => $proc) {
    $resultados[] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]));
    proc_close($proc);
}
$contagem = array_count_values($resultados);
confere('10 pedidos ao mesmo tempo: exatamente 1 empréstimo', ($contagem['ok'] ?? 0) === 1 && ($contagem['recusado'] ?? 0) === 9, json_encode($contagem));
confere('no banco: 1 empréstimo, 1 movimentação, exemplar emprestado', $num('SELECT COUNT(*) FROM emprestimos WHERE exemplar_id = ?', [$alvo]) === 1
    && $num("SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ? AND tipo = 'emprestimo'", [$alvo]) === 1 && $situacao($alvo) === 'emprestado');

// ---------------------------------------------------------------
echo "\n7. Aluno inexistente ou inativo\n";
confere('aluno inexistente é recusado', str_contains((string)recusa(fn () => $servico->registrar(999999, $exDom[3], $servico->prevista(), $contas['bibliotecaria'])), 'Aluno não encontrado'));
confere('aluno inativo é recusado', str_contains((string)recusa(fn () => $servico->registrar($aluno['inativo'], $exDom[3], $servico->prevista(), $contas['bibliotecaria'])), 'inativo'));
confere('nada foi gravado e o exemplar continua disponível', $num('SELECT COUNT(*) FROM emprestimos WHERE exemplar_id = ?', [$exDom[3]]) === 0 && $situacao($exDom[3]) === 'disponivel');
confere('nenhum aluno foi criado', $num('SELECT COUNT(*) FROM alunos') === 25);

// ---------------------------------------------------------------
echo "\n8. Exemplar indisponível\n";
confere('exemplar aguardando conferência é recusado', str_contains((string)recusa(fn () => $servico->registrar($aluno['joao'], $exAguardando, $servico->prevista(), $contas['bibliotecaria'])), 'conferência física'));
confere('exemplar em manutenção é recusado', str_contains((string)recusa(fn () => $servico->registrar($aluno['joao'], $exManut, $servico->prevista(), $contas['bibliotecaria'])), 'manutenção'));
confere('exemplar inexistente é recusado', recusa(fn () => $servico->registrar($aluno['joao'], 999999, $servico->prevista(), $contas['bibliotecaria'])) === 'Exemplar não encontrado.');

// ---------------------------------------------------------------
echo "\n9. Livro sem exemplar físico cadastrado\n";
$antes = $num('SELECT COUNT(*) FROM exemplares');
$r = $repo->pesquisarExemplares('Planilha Sem Inventário');
confere('livro da planilha aparece como "sem exemplar físico", nunca como disponível', !$r['disponiveis'] && count($r['indisponiveis']) === 1 && $r['indisponiveis'][0]['total_exemplares'] === 0 && $r['indisponiveis'][0]['origem_cadastro'] === 'planilha');
$r = $repo->pesquisarExemplares('Aguardando Conferência');
confere('livro só com exemplar aguardando: não disponível, com a contagem por situação', !$r['disponiveis'] && ($r['indisponiveis'][0]['situacoes']['aguardando'] ?? 0) === 1);
confere('a pesquisa não cria exemplares', $num('SELECT COUNT(*) FROM exemplares') === $antes);

// ---------------------------------------------------------------
echo "\n12. Filtros e pesquisas\n";
$r = $repo->pesquisarAlunos('Maria Exemplo');
confere('homônimos: os dois alunos aparecem para escolha (nada é escolhido sozinho)', count($r['itens']) === 2 && $r['itens'][0]['matricula'] !== $r['itens'][1]['matricula']);
$r = $repo->pesquisarAlunos('TESTE-0003');
confere('pesquisa por matrícula', count($r['itens']) === 1 && (int)$r['itens'][0]['id'] === $aluno['maria2']);
$r = $repo->pesquisarAlunos('maria.exemplo2@escola.teste');
confere('pesquisa por e-mail institucional', count($r['itens']) === 1 && (int)$r['itens'][0]['id'] === $aluno['maria1']);
$r = $repo->pesquisarAlunos('joao ficticio');
confere('pesquisa por nome sem acento', count($r['itens']) === 1);
$r = $repo->pesquisarAlunos('TESTE-0004');
confere('aluno inativo aparece marcado como inativo', count($r['itens']) === 1 && (int)$r['itens'][0]['ativo'] === 0);
confere('pesquisa com curinga/SQL não quebra', $repo->pesquisarAlunos("' OR 1=1 -- %")['itens'] === []);
$r = $repo->pesquisarAlunos('Aluno Concorrente', 5);
confere('limite de resultados avisa que há mais', count($r['itens']) === 5 && $r['mais']);
$r = $repo->pesquisarExemplares('bdp-4');
confere('pesquisa pelo código do exemplar (bdp-4 → BDP-000004)', count($r['disponiveis']) === 1 && $r['disponiveis'][0]['codigo_individual'] === 'BDP-000004');
$r = $repo->pesquisarExemplares('978-85-359-0277-8');
confere('pesquisa por ISBN com hífens', count($r['disponiveis']) === 3);
$r = $repo->pesquisarExemplares('Machado');
confere('pesquisa por autor mostra só os exemplares disponíveis', count($r['disponiveis']) === 3 && !in_array('emprestado', array_column($r['disponiveis'], 'situacao'), true));
confere('listagem: pesquisa por matrícula', $repo->listar(['q' => 'TESTE-0001', 'situacao' => 'todos'], $hoje)['total'] === 1);
confere('listagem: pesquisa por e-mail', $repo->listar(['q' => 'maria.exemplo2', 'situacao' => 'todos'], $hoje)['total'] === 1);
confere('listagem: pesquisa por título', $repo->listar(['q' => 'casmurro', 'situacao' => 'todos'], $hoje)['total'] === 3);
confere('listagem: pesquisa por código', $repo->listar(['q' => 'BDP-000002', 'situacao' => 'todos'], $hoje)['total'] === 1);
confere('listagem: período de retirada (hoje)', $repo->listar(['situacao' => 'todos', 'de' => $hoje, 'ate' => $hoje], $hoje)['total'] === 3
    && $repo->listar(['situacao' => 'todos', 'ate' => date('Y-m-d', strtotime($hoje . ' -1 day'))], $hoje)['total'] === 0);
$pag = $repo->listar(['situacao' => 'todos'], $hoje, 2, 2);
confere('paginação no servidor', $pag['total'] === 3 && $pag['paginas'] === 2 && count($pag['itens']) === 1);

// ---------------------------------------------------------------
echo "\n4. Empréstimos atrasados\n";
// Empréstimo antigo (retirado há 20 dias, previsto há 5 dias), gravado direto no banco de teste.
$pdo->prepare("UPDATE exemplares SET situacao = 'emprestado' WHERE id = ?")->execute([$exDom[4]]);
$pdo->prepare('INSERT INTO emprestimos (exemplar_id, aluno_id, data_emprestimo, data_prevista, emprestado_por) VALUES (?, ?, ?, ?, ?)')
    ->execute([$exDom[4], $aluno['maria2'], date('Y-m-d 10:00:00', strtotime($hoje . ' -20 days')), date('Y-m-d', strtotime($hoje . ' -5 days')), $contas['bibliotecaria']]);
$atrasado = (int)$pdo->lastInsertId();
$r = $repo->listar(['situacao' => 'atrasados'], $hoje);
confere('atrasado identificado automaticamente, com 5 dias de atraso', $r['total'] === 1 && (int)$r['itens'][0]['id'] === $atrasado && (int)$r['itens'][0]['dias_atraso'] === 5);
confere('resumo: 4 ativos, 1 atrasado', $repo->resumo($hoje)['ativos'] === 4 && $repo->resumo($hoje)['atrasados'] === 1);
confere('o atraso usa o "hoje" da escola (um dia depois: 6 dias)', (int)$repo->listar(['situacao' => 'atrasados'], date('Y-m-d', strtotime($hoje . ' +1 day')))['itens'][0]['dias_atraso'] === 6);
confere('nenhuma multa ou cobrança é criada (não há tabela de multas)', $num("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%multa%'") === 0);

// ---------------------------------------------------------------
echo "\n3. Renovação\n";
$ren = $servico->renovar($e1['id'], $servico->previstaRenovacao($servico->prevista()), $contas['auxiliar']);
confere('renovação registrada com o novo prazo', $ren['renovacoes'] === 1 && db_query('SELECT data_prevista FROM emprestimos WHERE id = ?', [$e1['id']])->fetchColumn() === $ren['prevista_nova']);
confere('histórico da renovação (prazo anterior, novo, responsável)', $num('SELECT COUNT(*) FROM emprestimo_renovacoes WHERE emprestimo_id = ? AND prevista_anterior = ? AND prevista_nova = ? AND renovado_por = ?', [$e1['id'], $ren['prevista_anterior'], $ren['prevista_nova'], $contas['auxiliar']]) === 1);
confere('movimentação e ação da renovação', $num("SELECT COUNT(*) FROM movimentacoes WHERE emprestimo_id = ? AND tipo = 'renovacao'", [$e1['id']]) === 1 && $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'emprestimo.renovar' AND entidade_id = ?", [$e1['id']]) === 1);
confere('nova data igual ou anterior ao prazo atual é recusada', recusa(fn () => $servico->renovar($e1['id'], $ren['prevista_nova'], $contas['auxiliar'])) !== null);
$limitado = new EmprestimoService($pdo, new RegrasCirculacao(maxRenovacoes: 1));
confere('limite de renovações configurado (1) é respeitado', str_contains((string)recusa(fn () => $limitado->renovar($e1['id'], date('Y-m-d', strtotime($ren['prevista_nova'] . ' +7 days')), $contas['auxiliar'])), 'limite de 1'));
$semAtraso = new EmprestimoService($pdo, new RegrasCirculacao(renovarAtrasado: false));
confere('regra "não renovar atrasado" (quando configurada)', str_contains((string)recusa(fn () => $semAtraso->renovar($atrasado, $servico->previstaRenovacao($hoje), $contas['auxiliar'])), 'atrasados'));
$renAtraso = $servico->renovar($atrasado, $servico->previstaRenovacao(date('Y-m-d', strtotime($hoje . ' -5 days'))), $contas['bibliotecaria']);
confere('com a regra padrão, o atrasado renova a partir de hoje e sai dos atrasos', $renAtraso['prevista_nova'] === (new DateTimeImmutable($hoje))->modify('+14 days')->format('Y-m-d') && $repo->resumo($hoje)['atrasados'] === 0);
$trava = null;
try {
    $pdo->exec('DELETE FROM emprestimo_renovacoes');
} catch (PDOException $e) {
    $trava = $e->getMessage();
}
confere('histórico de renovações não pode ser apagado', $trava !== null && $num('SELECT COUNT(*) FROM emprestimo_renovacoes') === 2);

// ---------------------------------------------------------------
echo "\n2. Devolução\n";
$d1 = $servico->devolver($e1['id'], $contas['auxiliar'], null, false, 'Sem danos');
$linha = db_query('SELECT * FROM emprestimos WHERE id = ?', [$e1['id']])->fetch();
confere('devolução registrada com data real e responsável', $linha['data_devolucao'] !== null && (int)$linha['recebido_por'] === $contas['auxiliar'] && $d1['dias_atraso'] === 0);
confere('exemplar liberado (disponível)', $situacao($exDom[0]) === 'disponivel' && $d1['situacao_exemplar'] === 'disponivel');
confere('movimentação e ação da devolução', $num("SELECT COUNT(*) FROM movimentacoes WHERE emprestimo_id = ? AND tipo = 'devolucao'", [$e1['id']]) === 1 && $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'devolucao.registrar' AND entidade_id = ?", [$e1['id']]) === 1);
confere('não é possível devolver duas vezes', str_contains((string)recusa(fn () => $servico->devolver($e1['id'], $contas['auxiliar'])), 'já foi devolvido'));
confere('empréstimo devolvido não pode ser renovado', str_contains((string)recusa(fn () => $servico->renovar($e1['id'], date('Y-m-d', strtotime($hoje . ' +60 days')), $contas['auxiliar'])), 'devolvidos não podem'));
$trava = null;
try {
    $pdo->prepare('UPDATE emprestimos SET data_devolucao = NULL WHERE id = ?')->execute([$e1['id']]);
} catch (PDOException $e) {
    $trava = $e->getMessage();
}
confere('o banco impede reabrir ou alterar um empréstimo devolvido', $trava !== null && str_contains($trava, 'já foi devolvido'));
$d2 = $servico->devolver($e2['id'], $contas['bibliotecaria'], null, true, 'Página rasgada');
confere('devolução com reparo: exemplar vai para manutenção', $situacao($exDom[1]) === 'manutencao' && $num("SELECT COUNT(*) FROM movimentacoes WHERE emprestimo_id = ? AND tipo = 'manutencao'", [$e2['id']]) === 1);
$retro = date('Y-m-d H:i:s', strtotime($hoje . ' -1 day 09:00'));
confere('data de devolução anterior à retirada é recusada', recusa(fn () => $servico->devolver($atrasado, $contas['bibliotecaria'], date('Y-m-d H:i:s', strtotime($hoje . ' -30 days')))) !== null);
confere('data de devolução no futuro é recusada', recusa(fn () => $servico->devolver($atrasado, $contas['bibliotecaria'], date('Y-m-d H:i:s', strtotime('+2 days')))) !== null);
$d3 = $servico->devolver($atrasado, $contas['bibliotecaria'], $retro);
confere('devolução com a data real informada (ontem) fica gravada assim', db_query('SELECT data_devolucao FROM emprestimos WHERE id = ?', [$atrasado])->fetchColumn() === $retro);
confere('mudar a situação de um exemplar emprestado fora da devolução é bloqueado', (function () use ($pdo, $alvo) {
    try {
        $pdo->prepare("UPDATE exemplares SET situacao = 'disponivel' WHERE id = ?")->execute([$alvo]);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

// ---------------------------------------------------------------
echo "\n10. Histórico preservado\n";
$pdo->prepare('UPDATE alunos SET ativo = 0 WHERE id = ?')->execute([$aluno['maria2']]);
$h = $repo->listar(['situacao' => 'todos', 'aluno' => $aluno['maria2']], $hoje);
confere('aluno desativado: histórico continua disponível', $h['total'] === 1 && (int)$h['itens'][0]['aluno_ativo'] === 0 && $repo->resumoAluno($aluno['maria2'], $hoje)['renovacoes'] === 1);
$trava = null;
try {
    $pdo->prepare('DELETE FROM alunos WHERE id = ?')->execute([$aluno['maria2']]);
} catch (PDOException $e) {
    $trava = $e->getCode();
}
confere('aluno com empréstimos não pode ser excluído', $trava === '23000' && $repo->aluno($aluno['maria2']) !== null);
$trava = null;
try {
    $pdo->exec('DELETE FROM emprestimos');
} catch (PDOException $e) {
    $trava = $e->getMessage();
}
confere('empréstimos não podem ser excluídos', $trava !== null && $num('SELECT COUNT(*) FROM emprestimos') === 4);
confere('histórico do exemplar: empréstimos e movimentações', $repo->resumoExemplar($exDom[0], $hoje)['total'] === 1 && count($repo->movimentacoes($exDom[0])) === 4);
$trava = null;
try {
    $pdo->prepare('UPDATE emprestimos SET aluno_id = ? WHERE exemplar_id = ? AND data_devolucao IS NULL')->execute([$aluno['joao'], $alvo]);
} catch (PDOException $e) {
    $trava = $e->getMessage();
}
confere('aluno e exemplar de um empréstimo não podem ser trocados', $trava !== null);

// ---------------------------------------------------------------
echo "\n14. Integridade dos dados durante falhas\n";
// Falha simulada no meio da operação: a gravação da movimentação é recusada pelo banco.
$pdo->exec("CREATE TRIGGER teste_falha_movimentacao BEFORE INSERT ON movimentacoes FOR EACH ROW
            BEGIN IF NEW.tipo IN ('emprestimo', 'devolucao', 'renovacao') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falha simulada'; END IF; END");
$antesEmp = $num('SELECT COUNT(*) FROM emprestimos');
$antesAcoes = $num('SELECT COUNT(*) FROM acoes_administrativas');
$falhou = false;
try {
    $servico->registrar($aluno['joao'], $exDom[3], $servico->prevista(), $contas['bibliotecaria']);
} catch (PDOException $e) {
    $falhou = true;
}
confere('falha no empréstimo: nada gravado, exemplar continua disponível', $falhou && $num('SELECT COUNT(*) FROM emprestimos') === $antesEmp && $situacao($exDom[3]) === 'disponivel' && $num('SELECT COUNT(*) FROM acoes_administrativas') === $antesAcoes);
$aberto = (int)db_query('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$alvo])->fetchColumn();
$falhou = false;
try {
    $servico->devolver($aberto, $contas['bibliotecaria']);
} catch (PDOException $e) {
    $falhou = true;
}
confere('falha na devolução: empréstimo continua aberto e exemplar emprestado', $falhou && db_query('SELECT data_devolucao FROM emprestimos WHERE id = ?', [$aberto])->fetchColumn() === null && $situacao($alvo) === 'emprestado');
$previstaAntes = db_query('SELECT data_prevista FROM emprestimos WHERE id = ?', [$aberto])->fetchColumn();
$falhou = false;
try {
    $servico->renovar($aberto, $servico->previstaRenovacao($previstaAntes), $contas['bibliotecaria']);
} catch (PDOException $e) {
    $falhou = true;
}
confere('falha na renovação: prazo e contagem inalterados', $falhou && db_query('SELECT data_prevista FROM emprestimos WHERE id = ?', [$aberto])->fetchColumn() === $previstaAntes && $num('SELECT renovacoes FROM emprestimos WHERE id = ?', [$aberto]) === 0);
$pdo->exec('DROP TRIGGER teste_falha_movimentacao');
confere('depois da falha, a mesma operação funciona', $servico->devolver($aberto, $contas['bibliotecaria'])['situacao_exemplar'] === 'disponivel');
confere('a variável do fluxo de devolução não fica ligada', db_query('SELECT @biblioteca_fluxo')->fetchColumn() === null);

// ---------------------------------------------------------------
echo "\n11. Permissões e páginas (servidor PHP embutido no banco temporário)\n";
$portaHttp = 8790 + random_int(151, 300);
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', sys_get_temp_dir() . "/$banco.log", 'a'], 2 => ['file', sys_get_temp_dir() . "/$banco.log", 'a']], $pipesHttp, null, array_merge(getenv(), $envTeste));
usleep(800000);
$base = "http://127.0.0.1:$portaHttp/admin/";

/** Navegador mínimo com cookies. Retorna [status, corpo, location]. */
final class Navegador
{
    private array $cookies = [];
    public function __construct(private string $base) {}
    public function ir(string $pagina, ?array $post = null): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: " . http_build_query($this->cookies, '', '; ') . "\r\n",
            'content' => $post === null ? '' : http_build_query($post),
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20,
        ]]);
        $corpo = (string)@file_get_contents($this->base . $pagina, false, $ctx);
        $status = 0;
        $local = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int)$m[1];
            } elseif (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) {
                $this->cookies[$m[1]] = $m[2];
            } elseif (preg_match('/^Location: (.*)$/i', $h, $m)) {
                $local = trim($m[1]);
            }
        }
        return [$status, $corpo, $local];
    }
    public function csrf(string $pagina): string
    {
        [, $corpo] = $this->ir($pagina);
        return preg_match('/name="csrf" value="([a-f0-9]+)"/', $corpo, $m) ? $m[1] : '';
    }
    public function entrar(string $email, string $senha): int
    {
        $t = $this->csrf('login.php');
        return $this->ir('login.php', ['csrf' => $t, 'email' => $email, 'senha' => $senha])[0];
    }
}

$anon = new Navegador($base);
$todas = ['emprestimos.php', 'atrasos.php', 'devolucoes.php', 'novo-emprestimo.php', 'emprestimo.php?id=' . $e1['id'], 'historico-aluno.php?id=' . $aluno['joao'], 'historico-exemplar.php?id=' . $exDom[0]];
confere('sem login, todas as páginas da circulação levam ao login', array_reduce($todas, fn ($ok, $p) => $ok && ($r = $anon->ir($p)) && $r[0] === 302 && str_contains($r[2], 'login.php'), true));
[, $html] = $anon->ir('../biblioteca_php/catalogo.php');
confere('site público não mostra dados de alunos nem empréstimos', !str_contains($html, 'TESTE-0001') && !str_contains($html, 'João Fictício'));

$cons = new Navegador($base);
$cons->entrar('cons@teste.br', $senhaPadrao);
confere('perfil consulta: circulação bloqueada (403)', array_reduce($todas, fn ($ok, $p) => $ok && $cons->ir($p)[0] === 403, true));
[, $html] = $cons->ir('index.php');
confere('perfil consulta: dashboard sem a tabela de empréstimos (nomes de alunos) e sem o menu Circulação', !str_contains($html, 'Empréstimos recentes') && !str_contains($html, 'emprestimos.php') && !str_contains($html, 'João Fictício'));

$bib = new Navegador($base);
confere('login da bibliotecária', $bib->entrar('bib@teste.br', $senhaPadrao) === 302);
[$s, $html] = $bib->ir('emprestimos.php?situacao=todos&q=TESTE-0001');
confere('listagem carrega e pesquisa por matrícula', $s === 200 && str_contains($html, 'João Fictício da Silva') && str_contains($html, '1 empréstimo encontrado'));
[$s, $html] = $bib->ir('emprestimos.php?situacao=todos&q=' . rawurlencode('<script>alert(1)</script>'));
confere('pesquisa com HTML é escapada (XSS)', $s === 200 && !str_contains($html, '<script>alert(1)</script>'));
[$s, $html] = $bib->ir('novo-emprestimo.php?qa=' . rawurlencode('Maria Exemplo'));
confere('novo empréstimo: homônimos listados lado a lado, nenhum escolhido', $s === 200 && substr_count($html, 'Maria Exemplo') >= 2 && str_contains($html, 'TESTE-0002') && str_contains($html, 'TESTE-0003') && !str_contains($html, 'Confirmar empréstimo'));
[$s, $html] = $bib->ir('novo-emprestimo.php?aluno=' . $aluno['xss'] . '&qe=planilha');
confere('nome com HTML escapado e livro sem exemplar explicado', $s === 200 && !str_contains($html, '<script>alert(1)</script> Aluno') && str_contains($html, 'Nenhum exemplar físico identificado'));
[$s, $html] = $bib->ir('novo-emprestimo.php?aluno=' . $aluno['joao'] . '&exemplar=' . $exDom[3]);
confere('resumo antes de confirmar (aluno, livro, exemplar, prazo)', $s === 200 && str_contains($html, 'Confirmar empréstimo') && str_contains($html, 'BDP-000004') && str_contains($html, 'name="data_prevista"'));
$tk = $bib->csrf('novo-emprestimo.php?aluno=' . $aluno['joao'] . '&exemplar=' . $exDom[3]);
[$s] = $bib->ir('novo-emprestimo.php', ['aluno' => $aluno['joao'], 'exemplar' => $exDom[3], 'confirmar' => '1']);
confere('sem token CSRF é recusado', $s === 419 && $situacao($exDom[3]) === 'disponivel');
[$s, $html] = $bib->ir('novo-emprestimo.php', ['csrf' => $tk, 'aluno' => $aluno['joao'], 'exemplar' => $exDom[3]]);
confere('sem marcar a confirmação, nada é gravado', $s === 200 && str_contains($html, 'Marque a confirmação') && $situacao($exDom[3]) === 'disponivel');
[$s, , $loc] = $bib->ir('novo-emprestimo.php', ['csrf' => $tk, 'aluno' => $aluno['joao'], 'exemplar' => $exDom[3], 'confirmar' => '1', 'data_prevista' => date('Y-m-d', strtotime($hoje . ' +21 days'))]);
$novo = $num('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$exDom[3]]);
confere('empréstimo pelo formulário: grava e mostra sucesso', $s === 303 && $novo > 0 && str_contains($loc, 'emprestimo.php?id=' . $novo) && str_contains($bib->ir('emprestimo.php?id=' . $novo)[1], 'Empréstimo registrado'));
confere('bibliotecária pode escolher a data prevista', db_query('SELECT data_prevista FROM emprestimos WHERE id = ?', [$novo])->fetchColumn() === date('Y-m-d', strtotime($hoje . ' +21 days')));
[$s, $html] = $bib->ir('novo-emprestimo.php', ['csrf' => $tk, 'aluno' => $aluno['maria1'], 'exemplar' => $exDom[3], 'confirmar' => '1']);
confere('formulário reenviado para exemplar já emprestado: mensagem clara', $s === 200 && str_contains($html, 'já está emprestado'));
[$s, $html] = $bib->ir('novo-emprestimo.php', ['csrf' => $tk, 'aluno' => $aluno['inativo'], 'exemplar' => $exDom[5], 'confirmar' => '1']);
confere('formulário com aluno inativo: recusado', $s === 200 && str_contains($html, 'inativo') && $situacao($exDom[5]) === 'disponivel');
[$s, , $loc] = $bib->ir('devolucoes.php?q=bdp-4');
confere('devoluções: código lido vai direto para a confirmação', $s === 303 && str_contains($loc, 'devolver-emprestimo.php?id=' . $novo));
[$s, $html] = $bib->ir('devolver-emprestimo.php?id=' . $novo);
confere('confirmação mostra os dados do empréstimo', $s === 200 && str_contains($html, 'João Fictício da Silva') && str_contains($html, 'BDP-000004') && str_contains($html, 'Registrar devolução'));
[$s, , $loc] = $bib->ir('renovar-emprestimo.php?id=' . $novo, ['csrf' => $tk, 'id' => $novo, 'confirmar' => '1']);
confere('renovação pelo formulário', $s === 303 && $num('SELECT renovacoes FROM emprestimos WHERE id = ?', [$novo]) === 1);
[$s] = $bib->ir('devolver-emprestimo.php?id=' . $novo, ['csrf' => $tk, 'id' => $novo, 'confirmar' => '1']);
confere('devolução pelo formulário', $s === 303 && $situacao($exDom[3]) === 'disponivel');
[$s, $html] = $bib->ir('devolver-emprestimo.php?id=' . $novo, ['csrf' => $tk, 'id' => $novo, 'confirmar' => '1']);
confere('segunda devolução pelo formulário é recusada', $s === 200 && str_contains($html, 'já foi devolvido') && $num("SELECT COUNT(*) FROM movimentacoes WHERE emprestimo_id = ? AND tipo = 'devolucao'", [$novo]) === 1);
[$s, $html] = $bib->ir('atrasos.php');
confere('página de atrasos carrega', $s === 200 && str_contains($html, 'Nenhum empréstimo atrasado'));
[$s, $html] = $bib->ir('historico-aluno.php?id=' . $aluno['maria2']);
confere('histórico do aluno inativo continua acessível', $s === 200 && str_contains($html, 'Cadastro inativo (histórico preservado)') && str_contains($html, 'Dom Casmurro de Teste'));
[$s, $html] = $bib->ir('historico-exemplar.php?codigo=BDP-000001');
confere('histórico do exemplar por código', $s === 200 && str_contains($html, 'BDP-000001') && str_contains($html, 'Devolução'));
[$s, $html] = $bib->ir('index.php');
confere('dashboard com números reais e atalhos da circulação', $s === 200 && str_contains($html, 'Devoluções realizadas') && str_contains($html, 'Novo empréstimo') && str_contains($html, 'Consultar atrasos') && str_contains($html, 'Empréstimo registrado'));

$aux = new Navegador($base);
$aux->entrar('aux@teste.br', $senhaPadrao);
$tka = $aux->csrf('novo-emprestimo.php');
[$s, $html] = $aux->ir('novo-emprestimo.php?aluno=' . $aluno['joao'] . '&exemplar=' . $exDom[5]);
confere('auxiliar: vê o prazo padrão, sem campo para alterar a data', $s === 200 && !str_contains($html, 'name="data_prevista"'));
[$s] = $aux->ir('novo-emprestimo.php', ['csrf' => $tka, 'aluno' => $aluno['joao'], 'exemplar' => $exDom[5], 'confirmar' => '1', 'data_prevista' => date('Y-m-d', strtotime($hoje . ' +90 days'))]);
$doAux = $num('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$exDom[5]]);
confere('auxiliar registra empréstimo; data enviada à força é ignorada (prazo padrão)', $s === 303 && db_query('SELECT data_prevista FROM emprestimos WHERE id = ?', [$doAux])->fetchColumn() === $servico->prevista());
[$s] = $cons->ir('novo-emprestimo.php', ['csrf' => $cons->csrf('index.php'), 'aluno' => $aluno['joao'], 'exemplar' => $exDom[0], 'confirmar' => '1']);
confere('consulta não registra empréstimo nem enviando o formulário direto', $s === 403 && $situacao($exDom[0]) === 'disponivel');
$logApp = implode("\n", preg_grep('/\[biblioteca\]/', file(sys_get_temp_dir() . "/$banco.log") ?: []));
confere('registros de erro do sistema não expõem dados de alunos', !preg_match('/TESTE-\d{4}|Fictício|Exemplo|escola\.teste/', $logApp));

echo "\n" . ($falhas ? "$falhas de $total testes falharam.\n" : "Todos os $total testes passaram.\n");
exit($falhas ? 1 : 0);
