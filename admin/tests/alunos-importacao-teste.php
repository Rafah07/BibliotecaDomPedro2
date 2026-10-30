<?php
/**
 * Testes da importação de alunos (admin/importar-alunos.php), só com alunos FICTÍCIOS.
 *
 * Uso: php admin/tests/alunos-importacao-teste.php
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá (DB_HOST, DB_PORT,
 * DB_USER, DB_PASSWORD) para criar um banco temporário "bdp_teste_alunos_XXXX", aplica as migrações,
 * cria livros, exemplares, empréstimos, administradores e alunos fictícios e apaga tudo no final.
 * As planilhas de teste (.csv e .xlsx) são geradas aqui, numa pasta temporária apagada no final.
 * Para as páginas e a API, sobe um servidor PHP embutido (php -S) apontando para o banco temporário.
 * Para outro usuário do MySQL, defina TESTE_DB_USER e TESTE_DB_PASSWORD.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Services\AlunoImportService;

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
    echo ($ok ? '  ok  ' : '  FALHOU  ') . $nome . ($ok || $detalhe === '' ? '' : " — $detalhe") . "\n";
}

/** Executa $fn e devolve a exceção lançada (ou null). */
function excecao(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

// ---------------------------------------------------------------
echo "Regras (sem banco)\n";
$servico = new AlunoImportService('escola.exemplo');
$cab = ['A' => 'Nome do Aluno', 'B' => 'Matrícula', 'C' => 'CPF', 'D' => 'Turma', 'E' => 'Turno', 'F' => 'E-mail institucional', 'G' => 'Nome da mãe', 'H' => 'Endereço'];
$sug = $servico->sugerirMapeamento($cab);
confere('sugestão reconhece nome, matrícula, turma, turno e e-mail', $sug['A'] === 'nome' && $sug['B'] === 'matricula' && $sug['D'] === 'turma' && $sug['E'] === 'turno' && $sug['F'] === 'email');
confere('colunas pessoais desconhecidas (CPF, mãe, endereço) ficam ignoradas', $sug['C'] === 'ignorar' && $sug['G'] === 'ignorar' && $sug['H'] === 'ignorar');
confere('mapeamento sem matrícula é recusado', $servico->problemaNoMapeamento(['A' => 'nome'], $cab) !== null);
confere('mesmo campo em duas colunas é recusado', $servico->problemaNoMapeamento(['A' => 'nome', 'B' => 'matricula', 'G' => 'nome'], $cab) !== null);
confere('coluna inexistente ou campo desconhecido é recusado', $servico->problemaNoMapeamento(['A' => 'nome', 'B' => 'matricula', 'Z' => 'turma'], $cab) !== null
    && $servico->problemaNoMapeamento(['A' => 'nome', 'B' => 'matricula', 'C' => 'senha'], $cab) !== null);
confere('mapeamento válido aceito', $servico->problemaNoMapeamento($sug, $cab) === null);
$n = $servico->normalizar([
    2 => ['A' => '  Carla   Fictícia ', 'B' => 'F-001', 'D' => '1º A', 'E' => 'Matutino', 'F' => 'Carla@Escola.Exemplo', 'C' => '000.000.000-00'],
    3 => ['A' => '', 'B' => 'F 002', 'E' => 'Madrugada', 'F' => 'carla@gmail.example'],
    4 => ['A' => 'Sem e-mail', 'B' => 'F-003', 'F' => 'nao-e-email'],
    5 => ['A' => '', 'B' => '', 'C' => 'só coluna ignorada'],
], $sug);
confere('linha só com coluna ignorada preenchida não conta', count($n) === 3);
confere('espaços extras removidos e turno "Matutino" vira manhã', $n[0]['dados']['nome'] === 'Carla Fictícia' && $n[0]['dados']['turno'] === 'manha' && !$n[0]['erros'] && !$n[0]['avisos']);
confere('e-mail em minúsculas', $n[0]['dados']['email'] === 'carla@escola.exemplo');
confere('dados das colunas ignoradas não aparecem no resultado', !str_contains(json_encode($n), '000.000.000'));
confere('nome em branco e matrícula com espaço são erros', count($n[1]['erros']) === 2);
confere('turno desconhecido e e-mail de outro domínio viram aviso e ficam em branco', count($n[1]['avisos']) === 2 && $n[1]['dados']['turno'] === null && $n[1]['dados']['email'] === null);
confere('e-mail inválido vira aviso', $n[2]['avisos'] && $n[2]['dados']['email'] === null && !$n[2]['erros']);
$plano = $servico->planejar([
    ['linha' => 2, 'dados' => ['nome' => 'Fulano Fictício', 'matricula' => 'x1', 'turma' => null, 'turno' => null, 'email' => null], 'erros' => [], 'avisos' => []],
], ['X1' => ['id' => 9, 'nome' => 'Outro Nome', 'matricula' => 'X1', 'turma' => '1A', 'turno' => null, 'email' => null, 'ativo' => 1, 'atualizado_em' => '2026-01-01 00:00:00']], [], [], ['modo' => 'atualizar']);
confere('matrícula é comparada sem diferenciar maiúsculas (x1 = X1)', $plano['itens'][0]['situacao'] === 'existente');
confere('nome diferente na mesma matrícula gera aviso para conferência', count($plano['itens'][0]['avisos']) === 1);
confere('célula vazia não apaga dado cadastrado (turma)', !isset($plano['itens'][0]['alteracoes']['turma']));

// ---------------------------------------------------------------
$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "\nTestes com banco não executados: configure DB_HOST/DB_USER no .env.\n";
    echo "\n$total testes, $falhas falha(s).\n";
    exit($falhas ? 1 : 0);
}
$banco = 'bdp_teste_alunos_' . bin2hex(random_bytes(4));
$pasta = sys_get_temp_dir() . '/' . $banco;
mkdir($pasta, 0700);
$logServidor = "$pasta/servidor.log";
$raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$servidor = null;
register_shutdown_function(function () use ($raiz, $banco, $pasta, &$servidor) {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
    }
    $raiz->exec("DROP DATABASE IF EXISTS `$banco`");
    foreach (array_merge(glob("$pasta/uploads/*") ?: [], glob("$pasta/*") ?: []) as $f) {
        is_dir($f) ? @rmdir($f) : unlink($f);
    }
    @rmdir("$pasta/uploads");
    @rmdir($pasta);
    echo "Banco temporário $banco e planilhas de teste apagados.\n";
});
echo "\nBanco temporário: $banco (o banco do .env não é usado)\n";
foreach (['DB_HOST' => $host, 'DB_PORT' => $porta, 'DB_NAME' => $banco, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/', 'ALUNOS_EMAIL_DOMINIO' => ''] as $k => $val) {
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
require_once __DIR__ . '/../includes/alunos-importacao.php';
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();

// ---------------------------------------------------------------
// Dados fictícios já existentes (somente no banco temporário)
// ---------------------------------------------------------------
$senhaPadrao = 'SenhaDeTeste123';
$contas = [];
foreach ([['Ana Admin', 'admin@teste.br', 'administrador'], ['Bia Bibliotecaria', 'bib@teste.br', 'bibliotecaria'],
          ['Caio Auxiliar', 'aux@teste.br', 'auxiliar'], ['Duda Consulta', 'cons@teste.br', 'consulta']] as [$nm, $em, $p]) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute([$nm, $em, password_hash($senhaPadrao, PASSWORD_DEFAULT), $p]);
    $contas[$p] = (int)$pdo->lastInsertId();
}
$pdo->exec("INSERT INTO livros (titulo, origem_cadastro) VALUES ('Livro Fictício 1', 'manual'), ('Livro Fictício 2', 'planilha')");
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual, situacao) VALUES (1, 'BDP-000001', 'emprestado'), (2, 'BDP-000002', 'disponivel')");
$pdo->exec("INSERT INTO alunos (nome, matricula, turma, turno, email) VALUES
    ('Ana Fictícia', '2026000001', '1º A', 'manha', 'ana.ficticia@escola.exemplo'),
    ('Bruno Teste', '2026000002', '1º B', 'tarde', NULL)");
$pdo->exec("INSERT INTO alunos (nome, matricula, ativo) VALUES ('Inativo Fictício', '2026000003', 0)");
$pdo->exec("INSERT INTO emprestimos (exemplar_id, aluno_id, data_prevista, emprestado_por) VALUES (1, 1, CURDATE() + INTERVAL 7 DAY, {$contas['bibliotecaria']})");
$pdo->prepare("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, usuario_id, status) VALUES ('acervo-ficticio.xlsx', ?, 'x.xlsx.upload', 'xlsx', 100, ?, 'concluida')")
    ->execute([hash('sha256', 'ficticio'), $contas['bibliotecaria']]);
$contagem = fn () => array_map(fn ($t) => $num("SELECT COUNT(*) FROM $t"), ['livros' => 'livros', 'exemplares' => 'exemplares', 'administradores' => 'administradores', 'importacoes' => 'importacoes', 'emprestimos' => 'emprestimos']);
$antes = $contagem();
$emprestimoAntes = db_query('SELECT * FROM emprestimos WHERE id = 1')->fetch();

// ---------------------------------------------------------------
// Planilhas fictícias
// ---------------------------------------------------------------
function csv_ficticio(string $caminho, array $linhas): array
{
    $celula = fn ($v) => preg_match('/[;"\r\n]/', (string)$v) ? '"' . str_replace('"', '""', (string)$v) . '"' : (string)$v;
    $conteudo = "\xEF\xBB\xBF" . implode("\r\n", array_map(fn ($l) => implode(';', array_map($celula, $l)), $linhas)) . "\r\n";
    file_put_contents($caminho, $conteudo);
    return ['caminho' => $caminho, 'tipo' => 'csv', 'hash' => hash_file('sha256', $caminho), 'nome' => basename($caminho), 'tamanho' => filesize($caminho)];
}
$cabecalho = ['Nome do Aluno', 'Matrícula', 'CPF', 'Turma', 'Turno', 'E-mail institucional', 'Nome da mãe'];
$linhasCsv = [
    $cabecalho,
    ['Carla Fictícia Souza', '2026000010', '111.111.111-11', '1º A', 'Manhã', 'carla.f@escola.exemplo', 'Mãe Fictícia Um'],      // 2 novo
    ['Ana Fictícia', '2026000001', '222.222.222-22', '2º B', 'tarde', 'ana.ficticia@escola.exemplo', 'Mãe Fictícia Dois'],    // 3 existente (turma/turno)
    ['Bruno Teste', '2026000002', '', '1º B', 'Tarde', '', ''],                                                                 // 4 igual
    ['ANA FICTICIA', '2026000099', '', '3º C', '', '', ''],                                                                     // 5 possível duplicidade (nome)
    ['Dado Repetido', '2026000020', '', '', '', '', ''],                                                                        // 6 matrícula repetida
    ['Dado Outro', '2026000020', '', '', '', '', ''],                                                                           // 7 matrícula repetida
    ['', '2026000030', '', '', '', '', ''],                                                                                     // 8 sem nome
    ['Eva Teste', '2026 0040', '', '', '', '', ''],                                                                             // 9 matrícula com espaço
    ["Robert'); DROP TABLE alunos;--", '2026000050', '', "1A' OR '1'='1", '', '', ''],                                         // 10 texto malicioso (novo)
    ['Fábio Turno', '2026000060', '', '', 'Madrugada', 'email-invalido', ''],                                                   // 11 novo com avisos
    ['', '', '', '', '', '', ''],                                                                                               // 12 vazia
];
$csv = csv_ficticio("$pasta/alunos.csv", $linhasCsv);
$params = ['aba' => null, 'linha_cabecalho' => 1, 'mapeamento' => ['A' => 'nome', 'B' => 'matricula', 'C' => 'ignorar', 'D' => 'turma', 'E' => 'turno', 'F' => 'email', 'G' => 'ignorar'], 'modo' => 'novos', 'importar_duplicidades' => false];
$chave = alunos_importacao_chave();

// ---------------------------------------------------------------
echo "\nPrévia (nada é gravado)\n";
$alunosAntes = $num('SELECT COUNT(*) FROM alunos');
$plano = alunos_importacao_analisar($csv, $params);
$r = $plano['resumo'];
$porLinha = array_column($plano['itens'], null, 'linha');
confere('10 registros lidos (linha vazia descartada)', $r['total'] === 10, json_encode($r));
confere('3 novos a incluir', $r['incluir'] === 3 && $porLinha[2]['acao'] === 'incluir' && $porLinha[10]['acao'] === 'incluir' && $porLinha[11]['acao'] === 'incluir');
confere('matrícula já cadastrada com diferença não é alterada no modo "somente novos"', $porLinha[3]['situacao'] === 'existente' && $porLinha[3]['acao'] === 'nenhuma' && $r['atualizar'] === 0);
confere('diferenças do cadastro existente aparecem na prévia', isset($porLinha[3]['alteracoes']['turma'], $porLinha[3]['alteracoes']['turno']) && !isset($porLinha[3]['alteracoes']['nome']));
confere('cadastro idêntico fica "sem alterações"', $porLinha[4]['situacao'] === 'igual');
confere('nome igual com outra matrícula = possível duplicidade (não substitui e não entra por padrão)', $porLinha[5]['situacao'] === 'duplicidade' && $porLinha[5]['acao'] === 'nenhuma');
confere('matrícula repetida na planilha: as duas linhas viram erro', $porLinha[6]['situacao'] === 'erro' && $porLinha[7]['situacao'] === 'erro');
confere('linha sem nome e matrícula com espaço são erros', $porLinha[8]['situacao'] === 'erro' && $porLinha[9]['situacao'] === 'erro' && $r['erros'] === 4);
confere('turno e e-mail inválidos são avisos (linha ainda entra)', count($porLinha[11]['avisos']) === 2 && $porLinha[11]['acao'] === 'incluir');
confere('colunas ignoradas (CPF, mãe) não chegam à análise', !str_contains(json_encode($plano, JSON_UNESCAPED_UNICODE), '111.111') && !str_contains(json_encode($plano, JSON_UNESCAPED_UNICODE), 'Mãe Fictícia'));
confere('prévia não grava nada', $num('SELECT COUNT(*) FROM alunos') === $alunosAntes);

// ---------------------------------------------------------------
echo "\nGravação transacional\n";
$e = excecao(fn () => alunos_importacao_gravar($csv, $params, str_repeat('0', 64), false));
confere('assinatura errada é recusada e nada é gravado', $e instanceof AlunosImportacaoConflito && $num('SELECT COUNT(*) FROM alunos') === $alunosAntes);
$outroModo = $params;
$outroModo['modo'] = 'atualizar';
$e = excecao(fn () => alunos_importacao_gravar($csv, $outroModo, AlunoImportService::assinatura($plano, $csv['hash'], $chave), true));
confere('assinatura de uma prévia com outras opções é recusada', $e instanceof AlunosImportacaoConflito);
$res = alunos_importacao_gravar($csv, $params, AlunoImportService::assinatura($plano, $csv['hash'], $chave), false);
confere('3 alunos incluídos', $res['incluidos'] === 3 && $res['atualizados'] === 0 && $num('SELECT COUNT(*) FROM alunos') === $alunosAntes + 3);
confere('cadastro existente não foi sobrescrito', db_query("SELECT turma FROM alunos WHERE matricula = '2026000001'")->fetchColumn() === '1º A');
confere('texto malicioso gravado literalmente (sem SQL injection)', $num("SELECT COUNT(*) FROM alunos WHERE nome = ? AND turma = ?", ["Robert'); DROP TABLE alunos;--", "1A' OR '1'='1"]) === 1);
confere('possível duplicidade não foi incluída', $num("SELECT COUNT(*) FROM alunos WHERE matricula = '2026000099'") === 0);
confere('turno/e-mail inválidos ficaram em branco', db_query("SELECT CONCAT(IFNULL(turno, '-'), IFNULL(email, '-')) FROM alunos WHERE matricula = '2026000060'")->fetchColumn() === '--');
$plano2 = alunos_importacao_analisar($csv, $params);
confere('reimportar o mesmo arquivo não duplica (novos viram "sem alterações")', $plano2['resumo']['incluir'] === 0 && $plano2['resumo']['sem_alteracao'] === 4);
$e = excecao(fn () => alunos_importacao_gravar($csv, $params, AlunoImportService::assinatura($plano2, $csv['hash'], $chave), false));
confere('sem nada para gravar, a confirmação é recusada', $e instanceof RuntimeException && !$e instanceof AlunosImportacaoConflito);

// ---------------------------------------------------------------
echo "\nAtualização separada, com prévia e confirmação\n";
$paramsAtualizar = $params;
$paramsAtualizar['modo'] = 'atualizar';
$plano3 = alunos_importacao_analisar($csv, $paramsAtualizar);
confere('modo atualizar: 1 cadastro a atualizar, nenhum novo', $plano3['resumo']['atualizar'] === 1 && $plano3['resumo']['incluir'] === 0);
$ass3 = AlunoImportService::assinatura($plano3, $csv['hash'], $chave);
$e = excecao(fn () => alunos_importacao_gravar($csv, $paramsAtualizar, $ass3, false));
confere('sem a confirmação explícita, nada é atualizado', $e !== null && db_query("SELECT turma FROM alunos WHERE matricula = '2026000001'")->fetchColumn() === '1º A');
$pdo->exec("UPDATE alunos SET turma = '1º C', observacoes = 'mudou depois da prévia' WHERE matricula = '2026000001'");
$e = excecao(fn () => alunos_importacao_gravar($csv, $paramsAtualizar, $ass3, true));
confere('cadastro alterado depois da prévia: a confirmação é recusada (refazer prévia)', $e instanceof AlunosImportacaoConflito);
$plano3 = alunos_importacao_analisar($csv, $paramsAtualizar);
$res = alunos_importacao_gravar($csv, $paramsAtualizar, AlunoImportService::assinatura($plano3, $csv['hash'], $chave), true);
$ana = db_query("SELECT * FROM alunos WHERE matricula = '2026000001'")->fetch();
confere('com confirmação, turma e turno atualizados', $res['atualizados'] === 1 && $ana['turma'] === '2º B' && $ana['turno'] === 'tarde');
confere('id, nome, observações e empréstimo do aluno preservados', (int)$ana['id'] === 1 && $ana['nome'] === 'Ana Fictícia' && $ana['observacoes'] === 'mudou depois da prévia'
    && db_query('SELECT * FROM emprestimos WHERE id = 1')->fetch() == $emprestimoAntes);
$paramsDup = $params;
$paramsDup['importar_duplicidades'] = true;
$plano4 = alunos_importacao_analisar($csv, $paramsDup);
$res = alunos_importacao_gravar($csv, $paramsDup, AlunoImportService::assinatura($plano4, $csv['hash'], $chave), false);
confere('possível duplicidade só entra com a opção marcada', $res['incluidos'] === 1 && $num("SELECT COUNT(*) FROM alunos WHERE matricula = '2026000099'") === 1);
$inativo = csv_ficticio("$pasta/inativo.csv", [['Nome', 'Matrícula', 'Turma'], ['Inativo Fictício', '2026000003', '9º Z']]);
$p5 = alunos_importacao_analisar($inativo, ['aba' => null, 'linha_cabecalho' => 1, 'mapeamento' => ['A' => 'nome', 'B' => 'matricula', 'C' => 'turma'], 'modo' => 'atualizar', 'importar_duplicidades' => false]);
confere('cadastro inativo é avisado e não é reativado', str_contains(implode(' ', $p5['itens'][0]['avisos']), 'inativo'));

// ---------------------------------------------------------------
echo "\nTudo ou nada, limites e desempenho\n";
$pdo->exec("CREATE TRIGGER teste_falha BEFORE INSERT ON alunos FOR EACH ROW BEGIN IF NEW.matricula = 'FALHA-1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falha simulada'; END IF; END");
$falha = csv_ficticio("$pasta/falha.csv", [['Nome', 'Matrícula'], ['Gil Fictício', 'OK-1'], ['Hana Fictícia', 'OK-2'], ['Ivo Fictício', 'FALHA-1']]);
$pf = ['aba' => null, 'linha_cabecalho' => 1, 'mapeamento' => ['A' => 'nome', 'B' => 'matricula'], 'modo' => 'novos', 'importar_duplicidades' => false];
$qtd = $num('SELECT COUNT(*) FROM alunos');
$e = excecao(fn () => alunos_importacao_gravar($falha, $pf, AlunoImportService::assinatura(alunos_importacao_analisar($falha, $pf), $falha['hash'], $chave), false));
confere('erro no meio da gravação desfaz tudo (nenhuma das 3 linhas entra)', $e instanceof PDOException && $num('SELECT COUNT(*) FROM alunos') === $qtd);
$pdo->exec('DROP TRIGGER teste_falha');
$muitas = [['Nome', 'Matrícula']];
for ($i = 1; $i <= AlunoImportService::MAX_LINHAS + 1; $i++) {
    $muitas[] = ["Aluno Fictício $i", sprintf('L%06d', $i)];
}
$grande = csv_ficticio("$pasta/grande.csv", $muitas);
$e = excecao(fn () => alunos_importacao_analisar($grande, $pf));
confere('mais de 5.000 linhas é recusado com mensagem clara', $e instanceof RuntimeException && str_contains($e->getMessage(), '5.000'));
array_pop($muitas);
$limite = csv_ficticio("$pasta/limite.csv", $muitas);
$t = microtime(true);
$pl = alunos_importacao_analisar($limite, $pf);
$res = alunos_importacao_gravar($limite, $pf, AlunoImportService::assinatura($pl, $limite['hash'], $chave), false);
$tempo = microtime(true) - $t;
confere('5.000 alunos fictícios: prévia + gravação em menos de 20 s', $res['incluidos'] === 5000 && $tempo < 20, sprintf('%.1f s', $tempo));
printf("        (%.2f s)\n", $tempo);

// ---------------------------------------------------------------
echo "\nPlanilha .xlsx com várias abas\n";
if (\Biblioteca\Services\ExcelImportService::xlsxDisponivel()) {
    $xlsx = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $aba1 = $xlsx->getActiveSheet()->setTitle('Empréstimos');
    $aba1->fromArray([['Aluno', 'Livro'], ['Fictício', 'Livro Fictício']]);
    $aba2 = $xlsx->createSheet()->setTitle('Alunos 2026');
    $aba2->fromArray([['Cadastro fictício de alunos'], ['Matrícula', 'Nome completo', 'Responsável', 'Turno'], [2026000100, 'Joana Fictícia', 'Responsável Fictício', 'N'], ['0042', 'Leo Fictício', '', 'integral']]);
    $aba2->setCellValueExplicit('A4', '0042', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($xlsx))->save("$pasta/alunos.xlsx");
    $arqX = ['caminho' => "$pasta/alunos.xlsx", 'tipo' => 'xlsx', 'hash' => hash_file('sha256', "$pasta/alunos.xlsx")];
    $colunas = alunos_importacao_colunas($arqX, 'Alunos 2026', 2);
    confere('lista as abas e lê o cabeçalho da linha escolhida', count($colunas['abas']) === 2 && array_column($colunas['colunas'], 'campo', 'cabecalho') === ['Matrícula' => 'matricula', 'Nome completo' => 'nome', 'Responsável' => 'ignorar', 'Turno' => 'turno']);
    $px = alunos_importacao_analisar($arqX, ['aba' => 'Alunos 2026', 'linha_cabecalho' => 2, 'mapeamento' => ['A' => 'matricula', 'B' => 'nome', 'C' => 'ignorar', 'D' => 'turno'], 'modo' => 'novos', 'importar_duplicidades' => false]);
    $dx = array_column(array_column($px['itens'], 'dados'), null, 'matricula');
    confere('matrícula numérica sem notação científica e zeros à esquerda mantidos (célula texto)', isset($dx['2026000100'], $dx['0042']));
    confere('turno "N" vira noite', $dx['2026000100']['turno'] === 'noite' && $dx['0042']['turno'] === 'integral');
    confere('aba inexistente é recusada', excecao(fn () => alunos_importacao_analisar($arqX, ['aba' => 'Outra', 'linha_cabecalho' => 1, 'mapeamento' => ['A' => 'matricula', 'B' => 'nome'], 'modo' => 'novos', 'importar_duplicidades' => false])) instanceof RuntimeException);
} else {
    echo "  (PhpSpreadsheet não instalado: rode composer install para testar .xlsx)\n";
}

$depois = $contagem();
confere('livros, exemplares, administradores, importações e empréstimos preservados', $antes === $depois, json_encode([$antes, $depois]));

// ---------------------------------------------------------------
echo "\nPáginas e API (servidor PHP embutido no banco temporário)\n";
$portaHttp = 8950 + random_int(0, 150);
$env = array_merge(getenv(), ['DB_NAME' => $banco, 'DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/']);
$tmpUploads = "$pasta/uploads";
mkdir($tmpUploads, 0700);
$servidor = proc_open([PHP_BINARY, '-d', 'error_log=' . $logServidor, '-d', 'log_errors=1', '-d', 'upload_tmp_dir=' . $tmpUploads, '-d', 'upload_max_filesize=40M', '-d', 'post_max_size=40M', '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')],
    [1 => ['file', $logServidor, 'a'], 2 => ['file', $logServidor, 'a']], $pipes, null, $env);
usleep(800000);
$base = "http://127.0.0.1:$portaHttp/admin/";

/** Navegador mínimo com cookies (curl). Retorna [status, corpo, json|null]. */
final class Navegador
{
    private $curl;
    public function __construct(private string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    }
    public function ir(string $pagina, array|string|null $post = null, array $cabecalhos = []): array
    {
        curl_setopt($this->curl, CURLOPT_URL, $this->base . $pagina);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $cabecalhos);
        if ($post === null) {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post);
        }
        $corpo = (string)curl_exec($this->curl);
        return [(int)curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), $corpo, json_decode($corpo, true)];
    }
    public function csrf(string $pagina): string
    {
        [, $corpo] = $this->ir($pagina);
        return preg_match('/(?:name="csrf" value|data-csrf)="([a-f0-9]+)"/', $corpo, $m) ? $m[1] : '';
    }
    public function entrar(string $email, string $senha): int
    {
        $t = $this->csrf('login.php');
        return $this->ir('login.php', http_build_query(['csrf' => $t, 'email' => $email, 'senha' => $senha]))[0];
    }
}
$api = 'api/alunos/importar.php';
$arquivoCsv = fn (string $caminho, string $nome = 'alunos-ficticios.csv') => new CURLFile($caminho, 'text/csv', $nome);

$anon = new Navegador($base);
confere('sem login, a página leva ao login', $anon->ir('importar-alunos.php')[0] === 302);
confere('sem login, a API responde 401', $anon->ir($api, ['acao' => 'ler'])[0] === 401);

foreach (['aux@teste.br' => 'auxiliar', 'cons@teste.br' => 'consulta'] as $email => $perfil) {
    $nav = new Navegador($base);
    $nav->entrar($email, $senhaPadrao);
    [$s, $corpo] = $nav->ir('importar-alunos.php');
    $csrfOutro = $nav->csrf('livros.php');
    [$sApi] = $nav->ir($api, ['acao' => 'ler', 'csrf' => $csrfOutro, 'arquivo' => $arquivoCsv($csv['caminho'])]);
    [, $menu] = $nav->ir('index.php');
    confere("perfil $perfil: página 403, API 403 e item fora do menu", $s === 403 && $sApi === 403 && !str_contains($menu, 'importar-alunos.php'));
}

$bib = new Navegador($base);
confere('login da bibliotecária', $bib->entrar('bib@teste.br', $senhaPadrao) === 302);
[$s, $corpo] = $bib->ir('importar-alunos.php');
confere('bibliotecária abre a página e vê o item no menu', $s === 200 && str_contains($corpo, 'Importar Alunos') && str_contains($corpo, 'importar-alunos.php'));
$token = preg_match('/data-csrf="([a-f0-9]+)"/', $corpo, $m) ? $m[1] : '';
confere('a página não usa serviços externos para a planilha (sem CDN de planilha/IA)', !preg_match('#(sheetjs|xlsx\.full|openai|anthropic|googleapis\.com/(?!css))#i', $corpo . file_get_contents(__DIR__ . '/../assets/js/importar-alunos.js')));

$novo = csv_ficticio("$pasta/http.csv", [['Nome', 'Matrícula', 'Turma', 'Turno', 'E-mail', 'CPF'], ['Mara Fictícia HTTP', 'H-0001', '5º A', 'tarde', 'mara@escola.exemplo', '999.999.999-99'], ['Nino Fictício HTTP', 'H-0002', '5º A', 'manhã', '', '']]);
[$s, , $j] = $bib->ir($api, ['acao' => 'ler', 'arquivo' => $arquivoCsv($novo['caminho'])]);
confere('POST sem token CSRF é recusado (419)', $s === 419);
[$s, , $j] = $bib->ir($api, ['acao' => 'ler', 'csrf' => $token, 'arquivo' => $arquivoCsv($novo['caminho'])]);
$sugestao = $j ? array_column($j['colunas'], 'campo', 'letra') : [];
confere('ler: colunas e sugestão (CPF ignorado)', $s === 200 && $sugestao === ['A' => 'nome', 'B' => 'matricula', 'C' => 'turma', 'D' => 'turno', 'E' => 'email', 'F' => 'ignorar'], (string)json_encode($j));
$form = ['csrf' => $token, 'aba' => 'CSV', 'linha_cabecalho' => '1', 'mapeamento' => json_encode($sugestao), 'modo' => 'novos', 'importar_duplicidades' => '0'];
[$s, $corpo, $j] = $bib->ir($api, ['acao' => 'previa', 'arquivo' => $arquivoCsv($novo['caminho'])] + $form);
confere('prévia pela API: 2 a incluir, sem o CPF na resposta', $s === 200 && $j['resumo']['incluir'] === 2 && !str_contains($corpo, '999.999'));
$assinatura = $j['assinatura'] ?? '';
[$s, , $j2] = $bib->ir($api, ['acao' => 'confirmar', 'assinatura' => str_repeat('a', 64), 'arquivo' => $arquivoCsv($novo['caminho'])] + $form);
confere('confirmar com assinatura falsa: 409 e nada gravado', $s === 409 && $num("SELECT COUNT(*) FROM alunos WHERE matricula LIKE 'H-%'") === 0);
$outro = csv_ficticio("$pasta/http2.csv", [['Nome', 'Matrícula', 'Turma', 'Turno', 'E-mail', 'CPF'], ['Outra Pessoa', 'H-0009', '', '', '', '']]);
[$s] = $bib->ir($api, ['acao' => 'confirmar', 'assinatura' => $assinatura, 'arquivo' => $arquivoCsv($outro['caminho'])] + $form);
confere('confirmar com outro arquivo que não o da prévia: 409', $s === 409);
[$s, , $j] = $bib->ir($api, ['acao' => 'confirmar', 'assinatura' => $assinatura, 'arquivo' => $arquivoCsv($novo['caminho'])] + $form);
confere('confirmar: 2 alunos incluídos', $s === 200 && $j['incluidos'] === 2 && $num("SELECT COUNT(*) FROM alunos WHERE matricula LIKE 'H-%'") === 2);
$acao = db_query("SELECT detalhes FROM acoes_administrativas WHERE acao = 'alunos.importar' ORDER BY id DESC LIMIT 1")->fetchColumn();
confere('registro de ações guarda só contagens (sem nome, matrícula ou e-mail)', $acao && !preg_match('/Mara|H-000|mara@/', $acao));
confere('nenhuma conta de acesso foi criada para alunos', $num('SELECT COUNT(*) FROM administradores') === 4);

file_put_contents("$pasta/falso.xlsx", 'isto não é uma planilha');
[$s, , $j] = $bib->ir($api, ['acao' => 'ler', 'csrf' => $token, 'arquivo' => new CURLFile("$pasta/falso.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'falso.xlsx')]);
confere('arquivo falso com extensão .xlsx é recusado', $s === 422);
file_put_contents("$pasta/script.php", '<?php echo 1;');
[$s] = $bib->ir($api, ['acao' => 'ler', 'csrf' => $token, 'arquivo' => new CURLFile("$pasta/script.php", 'text/plain', 'script.php')]);
confere('extensão diferente de .xlsx/.csv é recusada', $s === 422);
$fp = fopen("$pasta/enorme.csv", 'w');
fwrite($fp, "Nome;Matrícula\r\n");
for ($i = 0; $i < 230000; $i++) {
    fwrite($fp, "Aluno Fictício Grande $i;G$i\r\n");
}
fclose($fp);
[$s, , $j] = $bib->ir($api, ['acao' => 'ler', 'csrf' => $token, 'arquivo' => $arquivoCsv("$pasta/enorme.csv")]);
confere('arquivo acima de 5 MB é recusado', $s === 422 && str_contains((string)($j['erro'] ?? ''), '5 MB'), (string)($j['erro'] ?? $s));
[$s] = $bib->ir($api . '?acao=ler');
confere('GET em ação que grava/lê arquivo é recusado (405)', $s === 405);
[$s, $corpo] = $bib->ir($api . '?acao=modelo');
confere('modelo CSV só com dados fictícios', $s === 200 && str_contains($corpo, 'Fictício') && str_contains($corpo, 'Matrícula'));
[$s, , $j] = $bib->ir($api, ['acao' => 'previa', 'arquivo' => $arquivoCsv($novo['caminho'])] + array_merge($form, ['mapeamento' => '{"A":"nome","B":"matricula","Z":"turma"}']));
confere('mapeamento adulterado é recusado', $s === 422);

usleep(300000);
$log = (string)@file_get_contents($logServidor);
confere('o log do servidor não contém dados dos alunos fictícios', !preg_match('/Fictíci|Mara|H-000|2026000|mara@/u', $log), mb_substr($log, 0, 300));
confere('nenhum arquivo enviado ficou guardado (temporários apagados pelo PHP)', count(glob("$tmpUploads/*") ?: []) === 0);
$storage = glob(__DIR__ . '/../../storage/importacoes/*') ?: [];
confere('nada novo na pasta de arquivos da importação do acervo', count(array_filter($storage, fn ($f) => filemtime($f) >= $_SERVER['REQUEST_TIME'])) === 0);

echo "\n$total testes, $falhas falha(s).\n";
exit($falhas ? 1 : 0);
