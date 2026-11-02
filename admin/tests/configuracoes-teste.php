<?php
/**
 * Testes da Etapa 6: configurações gerais (painel → .env → padrão), histórico imutável, regras novas
 * de empréstimo (limite por aluno e bloqueio por atraso), segurança do painel (tentativas e sessão),
 * site em manutenção, perfis e permissões, manutenção (diagnóstico, cópia de segurança restaurável
 * e limpeza), CSRF, XSS e preservação dos dados existentes.
 *
 * Uso: php admin/tests/configuracoes-teste.php [--manter]
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá para criar um banco
 * temporário "bdp_teste_config_XXXX" (e um segundo, para restaurar a cópia de segurança), aplica as
 * migrações, cria dados FICTÍCIOS só nesse banco e apaga tudo no final.
 * A restauração da cópia usa o cliente "mysql" (no XAMPP: C:\xampp\mysql\bin\mysql.exe); sem ele,
 * essa parte é pulada e o teste avisa. Defina MYSQL_CLIENTE com o caminho, se precisar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Repositories\ConfiguracaoRepository;
use Biblioteca\Services\BackupService;
use Biblioteca\Services\CirculacaoException;
use Biblioteca\Services\DiagnosticoSistema;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Support\ConfiguracaoSistema;
use Biblioteca\Support\RegrasCirculacao;

require __DIR__ . '/../../config/app.php';

$manter = in_array('--manter', $argv, true);
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

$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "Testes não executados: configure DB_HOST/DB_USER no .env.\n";
    exit(0);
}
$banco = 'bdp_teste_config_' . bin2hex(random_bytes(4));
$bancoRestaurado = $banco . '_rest';
$raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$servidor = null;
$temporarios = [];
register_shutdown_function(function () use ($raiz, $banco, $bancoRestaurado, &$servidor, &$temporarios, $manter) {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
    }
    foreach ($temporarios as $f) {
        @unlink($f);
    }
    $raiz->exec("DROP DATABASE IF EXISTS `$bancoRestaurado`");
    if ($manter) {
        echo "Banco temporário mantido para conferência: $banco (apague com DROP DATABASE `$banco`).\n";
        return;
    }
    $raiz->exec("DROP DATABASE IF EXISTS `$banco`");
    echo "Banco temporário $banco apagado.\n";
});
echo "Banco temporário: $banco (o banco do .env não é usado)\n";

// Ambiente do teste: .env "limpo" para as chaves configuráveis (só EMPRESTIMO_PRAZO_DIAS vem do "env").
$envTeste = ['DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_NAME' => $banco, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/',
    'APP_ENV' => 'production', 'APP_TIMEZONE' => 'America/Sao_Paulo'];
foreach (array_keys(ConfiguracaoSistema::CAMPOS) as $chave) {
    $envTeste[$chave] = '';
}
$envTeste['EMPRESTIMO_PRAZO_DIAS'] = '20';
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
require_once __DIR__ . '/../includes/auth.php';

// ---------------------------------------------------------------
// Dados fictícios (somente no banco temporário)
// ---------------------------------------------------------------
$senhaPadrao = 'SenhaDeTeste123';
$contas = [];
foreach ([['Ana Admin Teste', 'admin@teste.br', 'administrador'], ['Bia Bibliotecária Teste', 'bib@teste.br', 'bibliotecaria'],
          ['Caio Auxiliar Teste', 'aux@teste.br', 'auxiliar'], ['Duda Consulta Teste', 'cons@teste.br', 'consulta']] as [$nome, $email, $perfil]) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute([$nome, $email, password_hash($senhaPadrao, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$alunos = [];
foreach (['Aluna Fictícia Um' => 'CFG-001', 'Aluno Fictício Dois' => 'CFG-002', 'Aluna Fictícia Três' => 'CFG-003'] as $nome => $mat) {
    $pdo->prepare('INSERT INTO alunos (nome, matricula, email, turma) VALUES (?, ?, ?, ?)')->execute([$nome, $mat, strtolower($mat) . '@escola.teste', '7A']);
    $alunos[$mat] = (int)$pdo->lastInsertId();
}
$pdo->prepare('INSERT INTO livros (titulo, origem_cadastro, observacoes_internas) VALUES (?, ?, ?)')->execute(["Livro Fictício com 'aspas' e \\barra\\ e acentuação ção", 'manual', "linha 1\nlinha 2"]);
$livroId = (int)$pdo->lastInsertId();
$st = $pdo->prepare('CALL gerar_exemplares(?, ?, NULL, ?, ?)');
$st->execute([$livroId, 8, 'bom', 'disponivel']);
$exemplares = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
$st->closeCursor();

$tabelasDados = ['livros', 'exemplares', 'alunos', 'emprestimos', 'emprestimo_renovacoes', 'movimentacoes', 'administradores', 'site_configuracoes', 'classificacoes_cdd', 'importacoes', 'inventarios'];
$retrato = function () use ($num, $tabelasDados): array {
    $r = [];
    foreach ($tabelasDados as $t) {
        $r[$t] = $num("SELECT COUNT(*) FROM `$t`");
    }
    return $r;
};

// ---------------------------------------------------------------
echo "\n1. Migração 090 e histórico imutável\n";
confere('tabelas configuracoes_sistema e configuracoes_historico criadas', $num("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name IN ('configuracoes_sistema','configuracoes_historico')") === 2);
confere('a migração começa sem nenhuma configuração salva (nada muda até alguém salvar)', $num('SELECT COUNT(*) FROM configuracoes_sistema') === 0);
$repo = new ConfiguracaoRepository($pdo);
$repo->salvar(['ETIQUETA_BIBLIOTECA' => 'Biblioteca de Teste'], $contas['administrador']);
foreach (['UPDATE configuracoes_historico SET valor_novo = 1', 'DELETE FROM configuracoes_historico'] as $sql) {
    try {
        $pdo->exec($sql);
        confere('histórico recusa: ' . strtok($sql, ' '), false);
    } catch (PDOException $e) {
        confere('histórico recusa: ' . strtok($sql, ' '), str_contains($e->getMessage(), 'histórico de configurações'));
    }
}
$repo->salvar(['ETIQUETA_BIBLIOTECA' => ''], $contas['administrador']);

// ---------------------------------------------------------------
echo "\n2. Prioridade painel → .env → padrão\n";
config_banco(true);
confere('sem valor no painel: usa o .env', config_valor('EMPRESTIMO_PRAZO_DIAS') === '20' && config_origem('EMPRESTIMO_PRAZO_DIAS') === 'env');
confere('sem painel nem .env: usa o padrão', config_valor('EMPRESTIMO_MAX_RENOVACOES') === null && config_origem('EMPRESTIMO_MAX_RENOVACOES') === 'padrao');
$alt = $repo->salvar(['EMPRESTIMO_PRAZO_DIAS' => '30', 'EMPRESTIMO_MAX_RENOVACOES' => '2'], $contas['bibliotecaria'], '10.1.2.3');
config_banco(true);
confere('salvar devolve as chaves alteradas', $alt === ['EMPRESTIMO_PRAZO_DIAS', 'EMPRESTIMO_MAX_RENOVACOES']);
confere('valor do painel vale mais que o .env', config_valor('EMPRESTIMO_PRAZO_DIAS') === '30' && config_origem('EMPRESTIMO_PRAZO_DIAS') === 'painel');
$regras = RegrasCirculacao::doAmbiente('config_valor');
confere('regras de empréstimo usam o painel (prazo 30, renovação 30, 2 renovações, não provisório)', $regras->prazoDias === 30 && $regras->renovacaoDias === 30 && $regras->maxRenovacoes === 2 && !$regras->prazoProvisorio);
confere('salvar o mesmo valor de novo não grava nada', $repo->salvar(['EMPRESTIMO_PRAZO_DIAS' => '30'], $contas['bibliotecaria']) === []);
$repo->salvar(['EMPRESTIMO_PRAZO_DIAS' => ''], $contas['bibliotecaria']);
config_banco(true);
confere('campo vazio remove o valor do painel e volta ao .env', config_valor('EMPRESTIMO_PRAZO_DIAS') === '20' && $num("SELECT COUNT(*) FROM configuracoes_sistema WHERE chave = 'EMPRESTIMO_PRAZO_DIAS'") === 0);
$h = db_query("SELECT valor_anterior, valor_novo, usuario_id, ip FROM configuracoes_historico WHERE chave = 'EMPRESTIMO_PRAZO_DIAS' ORDER BY id")->fetchAll();
confere('histórico guarda antes/depois, quem e de onde', count($h) === 2 && $h[0]['valor_anterior'] === null && $h[0]['valor_novo'] === '30' && $h[1]['valor_novo'] === null
    && (int)$h[0]['usuario_id'] === $contas['bibliotecaria'] && $h[0]['ip'] === '10.1.2.3');
confere('chaves fora da lista (ex.: DB_PASSWORD, APP_ENV) são ignoradas ao salvar', $repo->salvar(['DB_PASSWORD' => 'x', 'APP_ENV' => 'development'], $contas['administrador']) === [] && $num('SELECT COUNT(*) FROM configuracoes_sistema WHERE chave IN (\'DB_PASSWORD\',\'APP_ENV\')') === 0);
$pdo->exec("INSERT INTO configuracoes_sistema (chave, valor) VALUES ('DB_NAME', 'outro_banco'), ('APP_ENV', 'development')");
config_banco(true);
confere('linha estranha gravada direto no banco não sobrescreve o .env', config_valor('DB_NAME') === $banco && app_ambiente() === 'production');
$pdo->exec("DELETE FROM configuracoes_sistema WHERE chave IN ('DB_NAME','APP_ENV')");
$pdo->exec('RENAME TABLE configuracoes_sistema TO configuracoes_sistema_tmp');
config_banco(true);
confere('sem a migração 090 (tabela ausente): tudo vem do .env, sem erro', config_valor('EMPRESTIMO_PRAZO_DIAS') === '20' && config_valor('EMPRESTIMO_MAX_RENOVACOES') === null);
$pdo->exec('RENAME TABLE configuracoes_sistema_tmp TO configuracoes_sistema');
config_banco(true);

// ---------------------------------------------------------------
echo "\n3. Validação dos campos\n";
$tudo = fn () => true;
$efetivo = fn ($k) => config_valor($k);
[$v, $err] = ConfiguracaoSistema::validar(['EMPRESTIMO_PRAZO_DIAS' => ' 15 ', 'EMPRESTIMO_RENOVAR_ATRASADO' => '0', 'ALUNOS_EMAIL_DOMINIO' => '@Escola.Edu.BR', 'ETIQUETA_BIBLIOTECA' => "  Biblioteca\x07  Teste  "], $tudo, $efetivo);
confere('valores válidos são normalizados (espaços, @ e maiúsculas do domínio, controle)', !$err && $v['EMPRESTIMO_PRAZO_DIAS'] === '15' && $v['ALUNOS_EMAIL_DOMINIO'] === 'escola.edu.br' && $v['ETIQUETA_BIBLIOTECA'] === 'Biblioteca Teste' && $v['EMPRESTIMO_RENOVAR_ATRASADO'] === '0');
foreach ([
    ['EMPRESTIMO_PRAZO_DIAS', '0'], ['EMPRESTIMO_PRAZO_DIAS', '366'], ['EMPRESTIMO_PRAZO_DIAS', '1e3'], ['EMPRESTIMO_PRAZO_DIAS', '-5'], ['EMPRESTIMO_PRAZO_DIAS', '14 dias'],
    ['EMPRESTIMO_MAX_RENOVACOES', '11'], ['EMPRESTIMO_LIMITE_ALUNO', '0'], ['PAINEL_INATIVIDADE_MINUTOS', '5'], ['LOGIN_MAX_TENTATIVAS', '50'],
    ['EMPRESTIMO_RENOVAR_ATRASADO', 'talvez'], ['ALUNOS_EMAIL_DOMINIO', 'aluno@escola.br'], ['ALUNOS_EMAIL_DOMINIO', 'escola'], ['ALUNOS_EMAIL_DOMINIO', 'esc ola.br'],
    ['ETIQUETA_BIBLIOTECA', str_repeat('x', 61)], ['SITE_MANUTENCAO_MENSAGEM', str_repeat('y', 501)],
] as [$chave, $valor]) {
    [, $err] = ConfiguracaoSistema::validar([$chave => $valor], $tudo, $efetivo);
    confere("recusa $chave = " . mb_strimwidth($valor, 0, 20, '…'), isset($err[$chave]));
}
[, $err] = ConfiguracaoSistema::validar(['EMPRESTIMO_PRAZO_DIAS' => '60', 'EMPRESTIMO_PRAZO_MAXIMO_DIAS' => '30'], $tudo, $efetivo);
confere('prazo padrão maior que o prazo máximo é recusado', isset($err['EMPRESTIMO_PRAZO_DIAS']));
[, $err] = ConfiguracaoSistema::validar(['EMPRESTIMO_PRAZO_MAXIMO_DIAS' => '10'], $tudo, $efetivo);
confere('reduzir o prazo máximo abaixo do prazo em uso (.env: 20) é recusado', isset($err['EMPRESTIMO_PRAZO_MAXIMO_DIAS']));
[$v] = ConfiguracaoSistema::validar(['SITE_MANUTENCAO' => '1', 'EMPRESTIMO_PRAZO_DIAS' => '10'], fn ($p) => $p === 'configuracoes.editar', $efetivo);
confere('campos sem permissão são ignorados na validação (bibliotecária não liga a manutenção)', !array_key_exists('SITE_MANUTENCAO', $v) && $v['EMPRESTIMO_PRAZO_DIAS'] === '10');
confere('toda chave configurável tem grupo e permissão conhecidos', !array_filter(ConfiguracaoSistema::CAMPOS, fn ($c) => !isset(ConfiguracaoSistema::GRUPOS[$c['grupo']]) || !in_array($c['permissao'], ['configuracoes.editar', 'sistema.manutencao'], true)));

// ---------------------------------------------------------------
echo "\n4. Regras novas de empréstimo (limite por aluno e atraso)\n";
$padrao = new EmprestimoService($pdo, RegrasCirculacao::doAmbiente('config_valor'));
confere('sem configuração: nenhuma regra nova (comportamento anterior)', $padrao->impedimentoAluno($alunos['CFG-001']) === null && RegrasCirculacao::doAmbiente('config_valor')->limiteAluno === null);
$repo->salvar(['EMPRESTIMO_LIMITE_ALUNO' => '2'], $contas['bibliotecaria']);
config_banco(true);
$servico = new EmprestimoService($pdo, RegrasCirculacao::doAmbiente('config_valor'));
$prev = $servico->prevista();
$servico->registrar($alunos['CFG-001'], $exemplares[0], $prev, $contas['bibliotecaria']);
$servico->registrar($alunos['CFG-001'], $exemplares[1], $prev, $contas['bibliotecaria']);
try {
    $servico->registrar($alunos['CFG-001'], $exemplares[2], $prev, $contas['bibliotecaria']);
    confere('terceiro empréstimo com limite 2 é recusado', false);
} catch (CirculacaoException $e) {
    confere('terceiro empréstimo com limite 2 é recusado, com mensagem clara', str_contains($e->getMessage(), 'limite da biblioteca é de 2'));
}
confere('exemplar recusado continua disponível', $num('SELECT COUNT(*) FROM exemplares WHERE id = ? AND situacao = \'disponivel\'', [$exemplares[2]]) === 1);
$servico->devolver((int)db_query('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$exemplares[0]])->fetchColumn(), $contas['bibliotecaria']);
$servico->registrar($alunos['CFG-001'], $exemplares[2], $prev, $contas['bibliotecaria']);
confere('depois de uma devolução, o aluno volta a poder pegar livro', $num('SELECT COUNT(*) FROM emprestimos WHERE aluno_id = ? AND data_devolucao IS NULL', [$alunos['CFG-001']]) === 2);

// Empréstimo atrasado (inserido direto, com datas no passado).
$pdo->prepare("INSERT INTO emprestimos (exemplar_id, aluno_id, data_emprestimo, data_prevista, emprestado_por) VALUES (?, ?, NOW() - INTERVAL 30 DAY, CURDATE() - INTERVAL 5 DAY, ?)")
    ->execute([$exemplares[3], $alunos['CFG-002'], $contas['bibliotecaria']]);
$pdo->prepare("UPDATE exemplares SET situacao = 'emprestado' WHERE id = ?")->execute([$exemplares[3]]);
$servico->registrar($alunos['CFG-002'], $exemplares[4], $prev, $contas['bibliotecaria']);
confere('com o bloqueio desligado, aluno com atraso ainda pode pegar livro', $num('SELECT COUNT(*) FROM emprestimos WHERE aluno_id = ? AND data_devolucao IS NULL', [$alunos['CFG-002']]) === 2);
$servico->devolver((int)db_query('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$exemplares[4]])->fetchColumn(), $contas['bibliotecaria']);
$repo->salvar(['EMPRESTIMO_BLOQUEAR_ATRASO' => '1'], $contas['bibliotecaria']);
config_banco(true);
$servico = new EmprestimoService($pdo, RegrasCirculacao::doAmbiente('config_valor'));
try {
    $servico->registrar($alunos['CFG-002'], $exemplares[5], $prev, $contas['bibliotecaria']);
    confere('bloqueio ligado: aluno com atraso não pega livro', false);
} catch (CirculacaoException $e) {
    confere('bloqueio ligado: aluno com atraso não pega livro, com mensagem clara', str_contains($e->getMessage(), 'atrasada'));
}
confere('bloqueio não afeta aluno sem atraso', $servico->impedimentoAluno($alunos['CFG-003']) === null);
confere('texto das regras mostra o limite e o bloqueio', (function () {
    require_once __DIR__ . '/../includes/emprestimos.php';
    $t = emprestimos_regras_texto();
    return str_contains($t, 'Até 2 livro(s)') && str_contains($t, 'atrasado não pode');
})());

// ---------------------------------------------------------------
echo "\n5. Segurança do painel e perfis\n";
confere('padrões: 120 minutos de sessão e 5 tentativas', auth_inatividade_minutos() === 120 && auth_max_tentativas() === 5);
$repo->salvar(['PAINEL_INATIVIDADE_MINUTOS' => '30', 'LOGIN_MAX_TENTATIVAS' => '3'], $contas['administrador']);
config_banco(true);
confere('valores do painel: 30 minutos e 3 tentativas', auth_inatividade_minutos() === 30 && auth_max_tentativas() === 3);
$descritas = array_merge(...array_values(array_map('array_keys', AUTH_PERMISSOES_DESCRICOES)));
$usadas = array_unique(array_merge(...array_values(AUTH_PERMISSOES)));
confere('toda permissão dos perfis tem descrição (e nenhuma descrição sobra)', !array_diff($usadas, $descritas) && !array_diff($descritas, $usadas), implode(', ', array_merge(array_diff($usadas, $descritas), array_diff($descritas, $usadas))));
confere('sistema.manutencao só para o administrador', array_keys(array_filter(AUTH_PERMISSOES, fn ($p) => in_array('sistema.manutencao', $p, true))) === ['administrador']);
confere('configuracoes.editar só para administrador e bibliotecária', array_keys(array_filter(AUTH_PERMISSOES, fn ($p) => in_array('configuracoes.editar', $p, true))) === ['administrador', 'bibliotecaria']);

// ---------------------------------------------------------------
echo "\n6. Diagnóstico e cópia de segurança\n";
$diag = new DiagnosticoSistema($pdo, realpath(__DIR__ . '/../..'));
confere('nenhuma migração pendente no banco temporário', $diag->migracoesPendentes() === []);
$itens = $diag->verificar('production', false, false);
confere('diagnóstico traz PHP, banco e migrações sem expor a senha do banco', count($itens) > 8 && !str_contains(json_encode($itens), $senhaDb ?: '§§§') && in_array('Migrações do banco', array_column($itens, 'titulo'), true));
$antes = $retrato();
$arquivo = sys_get_temp_dir() . "/$banco.sql";
$temporarios[] = $arquivo;
$fh = fopen($arquivo, 'wb');
$resumo = (new BackupService($pdo))->exportar($fh);
fclose($fh);
$sql = (string)file_get_contents($arquivo);
confere('cópia tem tabelas, views, procedimento e gatilhos', $resumo['tabelas'] >= 30 && $resumo['views'] >= 2 && $resumo['rotinas'] >= 1 && $resumo['gatilhos'] >= 20, json_encode($resumo));
confere('cópia não tem DROP TABLE nem DEFINER', !preg_match('/DROP\s+TABLE/i', $sql) && !preg_match('/DEFINER\s*=/i', $sql));
confere('fuso da conexão restaurado depois da cópia', $pdo->query('SELECT @@session.time_zone')->fetchColumn() === date('P'));
confere('cópia não altera nenhum dado', $retrato() === $antes);
$cliente = getenv('MYSQL_CLIENTE') ?: trim((string)shell_exec('command -v mysql 2>/dev/null'));
if ($cliente === '' || !is_executable($cliente)) {
    echo "  (cliente mysql não encontrado: restauração da cópia não testada)\n";
} else {
    $raiz->exec("CREATE DATABASE `$bancoRestaurado` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $cmd = escapeshellarg($cliente) . ' -h ' . escapeshellarg($host) . ' -P ' . (int)$porta . ' -u ' . escapeshellarg($usuarioDb) . ' ' . escapeshellarg($bancoRestaurado) . ' < ' . escapeshellarg($arquivo) . ' 2>&1';
    $saidaMysql = shell_exec('MYSQL_PWD=' . escapeshellarg($senhaDb) . ' ' . $cmd);
    confere('cópia restaura sem erros em um banco vazio', trim((string)$saidaMysql) === '', (string)$saidaMysql);
    $rest = new PDO("mysql:host=$host;port=$porta;dbname=$bancoRestaurado;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $iguais = true;
    $todas = $pdo->query("SELECT table_name FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($todas as $t) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() !== (int)$rest->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()) {
            $iguais = false;
            echo "    diferença em $t\n";
        }
    }
    confere('banco restaurado tem as mesmas tabelas e o mesmo número de linhas', $iguais && count($todas) === (int)$rest->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn());
    confere('texto com aspas, barra invertida, acentos e quebra de linha volta idêntico',
        $rest->query("SELECT CONCAT(titulo, '|', observacoes_internas) FROM livros WHERE id = $livroId")->fetchColumn() === $pdo->query("SELECT CONCAT(titulo, '|', observacoes_internas) FROM livros WHERE id = $livroId")->fetchColumn());
    confere('datas dos empréstimos voltam idênticas', $rest->query('SELECT GROUP_CONCAT(data_emprestimo, data_prevista ORDER BY id) FROM emprestimos')->fetchColumn() === $pdo->query('SELECT GROUP_CONCAT(data_emprestimo, data_prevista ORDER BY id) FROM emprestimos')->fetchColumn());
    confere('gatilhos, views e procedimento restaurados', (int)$rest->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE trigger_schema = DATABASE()')->fetchColumn() === $resumo['gatilhos']
        && (int)$rest->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE table_schema = DATABASE()")->fetchColumn() === $resumo['views']
        && (int)$rest->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE routine_schema = DATABASE()")->fetchColumn() === $resumo['rotinas']);
    try {
        $rest->exec('DELETE FROM configuracoes_historico');
        confere('gatilho de proteção do histórico funciona no banco restaurado', false);
    } catch (PDOException $e) {
        confere('gatilho de proteção do histórico funciona no banco restaurado', str_contains($e->getMessage(), 'histórico'));
    }
    $rest = null;
    $saidaDeNovo = shell_exec('MYSQL_PWD=' . escapeshellarg($senhaDb) . ' ' . $cmd);
    confere('restaurar sobre um banco com dados falha em vez de sobrescrever', str_contains((string)$saidaDeNovo, 'already exists'));
}

// ---------------------------------------------------------------
echo "\n7. Páginas, permissões, CSRF, XSS e site em manutenção (servidor PHP embutido, como no XAMPP)\n";
// Volta segurança ao padrão para os logins do teste.
$repo->salvar(['LOGIN_MAX_TENTATIVAS' => '', 'PAINEL_INATIVIDADE_MINUTOS' => ''], $contas['administrador']);
$portaHttp = 8790 + random_int(601, 800);
$log = sys_get_temp_dir() . "/$banco.log";
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipesHttp, null, array_merge(getenv(), $envTeste));
$temporarios[] = $log;
usleep(900000);
$base = "http://127.0.0.1:$portaHttp/";

/** Navegador mínimo com cookies. Retorna [status, corpo, location, content-type, content-disposition]. */
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
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60,
        ]]);
        $corpo = (string)@file_get_contents($this->base . $pagina, false, $ctx);
        $status = 0;
        $local = $tipo = $disp = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int)$m[1];
            } elseif (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) {
                $this->cookies[$m[1]] = $m[2];
            } elseif (preg_match('/^Location: (.*)$/i', $h, $m)) {
                $local = trim($m[1]);
            } elseif (preg_match('/^Content-Type: (.*)$/i', $h, $m)) {
                $tipo = trim($m[1]);
            } elseif (preg_match('/^Content-Disposition: (.*)$/i', $h, $m)) {
                $disp = trim($m[1]);
            }
        }
        return [$status, $corpo, $local, $tipo, $disp];
    }
    public function csrf(string $pagina): string
    {
        return preg_match('/name="csrf" value="([a-f0-9]+)"/', $this->ir($pagina)[1], $m) ? $m[1] : '';
    }
    public function entrar(string $email, string $senha): string
    {
        $t = $this->csrf('admin/login.php');
        return $this->ir('admin/login.php', ['csrf' => $t, 'email' => $email, 'senha' => $senha])[1] . '|' . $this->ir('admin/index.php')[0];
    }
}
$semErro = fn (string $html) => !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $html);

$anon = new Navegador($base);
foreach (['configuracoes.php', 'permissoes.php', 'manutencao.php'] as $p) {
    [$s, , $loc] = $anon->ir('admin/' . $p);
    confere("sem login: $p redireciona para o login", $s === 302 && str_contains($loc, 'login.php'));
}
$nav = [];
foreach (['administrador' => 'admin@teste.br', 'bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $nav[$perfil] = new Navegador($base);
    $nav[$perfil]->entrar($email, $senhaPadrao);
}
$esperado = [
    'configuracoes.php' => ['administrador' => 200, 'bibliotecaria' => 200, 'auxiliar' => 403, 'consulta' => 403],
    'permissoes.php' => ['administrador' => 200, 'bibliotecaria' => 200, 'auxiliar' => 200, 'consulta' => 200],
    'manutencao.php' => ['administrador' => 200, 'bibliotecaria' => 403, 'auxiliar' => 403, 'consulta' => 403],
];
foreach ($esperado as $pagina => $porPerfil) {
    $obtido = [];
    foreach ($porPerfil as $perfil => $codigo) {
        [$s, $html] = $nav[$perfil]->ir('admin/' . $pagina);
        $obtido[$perfil] = $s . ($semErro($html) ? '' : '!erro');
    }
    confere("acesso a $pagina por perfil", $obtido === array_map('strval', $porPerfil), json_encode($obtido));
}
[, $html] = $nav['administrador']->ir('admin/configuracoes.php');
confere('administrador vê as configurações técnicas, sem a senha do banco', str_contains($html, 'Configurações técnicas')
    && (bool)preg_match('#DB_PASSWORD</td>\s*<td[^>]*>(definida \(oculta\)|não definida)</td>#', $html));
confere('página mostra a origem do valor em uso (.env e padrão)', str_contains($html, 'Definido no .env') && str_contains($html, 'Padrão do sistema'));
[, $htmlBib] = $nav['bibliotecaria']->ir('admin/configuracoes.php');
confere('bibliotecária não vê as configurações técnicas', !str_contains($htmlBib, 'Configurações técnicas'));
confere('bibliotecária vê a segurança e a manutenção só para leitura (campos desabilitados)', (bool)preg_match('/name="LOGIN_MAX_TENTATIVAS"[^>]*disabled/', $htmlBib) && (bool)preg_match('/name="SITE_MANUTENCAO"[^>]*disabled/', $htmlBib));
confere('menu: Configurações aparece para a bibliotecária e não para a consulta', str_contains($htmlBib, 'configuracoes.php') && !str_contains($nav['consulta']->ir('admin/permissoes.php')[1], 'href="/admin/configuracoes.php"'));
[, $htmlPerm] = $nav['consulta']->ir('admin/permissoes.php');
confere('perfis e permissões: consulta vê a matriz completa e o próprio perfil destacado', str_contains($htmlPerm, 'sistema.manutencao') && str_contains($htmlPerm, 'seu perfil') && !str_contains($htmlPerm, 'conta(s) ativa(s)'));
confere('perfis e permissões: administrador vê quantas contas ativas há por perfil', str_contains($nav['administrador']->ir('admin/permissoes.php')[1], 'conta(s) ativa(s)'));

$t = $nav['bibliotecaria']->csrf('admin/configuracoes.php');
[$s] = $nav['bibliotecaria']->ir('admin/configuracoes.php', ['EMPRESTIMO_PRAZO_DIAS' => '21']);
confere('POST sem CSRF é recusado (419) e nada é gravado', $s === 419 && $num("SELECT COUNT(*) FROM configuracoes_sistema WHERE chave = 'EMPRESTIMO_PRAZO_DIAS'") === 0);
[$s, $html] = $nav['bibliotecaria']->ir('admin/configuracoes.php', ['csrf' => $t, 'EMPRESTIMO_PRAZO_DIAS' => '400']);
confere('valor inválido: mostra o erro e não grava', $s === 200 && str_contains($html, 'Confira os campos') && $num("SELECT COUNT(*) FROM configuracoes_sistema WHERE chave = 'EMPRESTIMO_PRAZO_DIAS'") === 0);
[$s, , $loc] = $nav['bibliotecaria']->ir('admin/configuracoes.php', ['csrf' => $t, 'EMPRESTIMO_PRAZO_DIAS' => '21', 'SITE_MANUTENCAO' => '1', 'LOGIN_MAX_TENTATIVAS' => '20']);
confere('bibliotecária salva o prazo, mas não consegue ligar a manutenção nem mudar a segurança', $s === 303
    && $num("SELECT COUNT(*) FROM configuracoes_sistema WHERE chave = 'EMPRESTIMO_PRAZO_DIAS' AND valor = '21'") === 1
    && $num("SELECT COUNT(*) FROM configuracoes_sistema WHERE chave IN ('SITE_MANUTENCAO','LOGIN_MAX_TENTATIVAS')") === 0);
confere('alteração registrada nas ações administrativas (sem valores pessoais)', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'configuracao.editar' AND usuario_id = ?", [$contas['bibliotecaria']]) === 1);
[, $html] = $nav['bibliotecaria']->ir('admin/configuracoes.php');
confere('depois de salvar: mensagem de sucesso, valor em uso e histórico com o nome de quem alterou', str_contains($html, 'Configurações salvas') && str_contains($html, 'Prazo padrão: 21 dia(s)') && str_contains($html, 'Bia Bibliotecária Teste'));
[, $html] = $nav['bibliotecaria']->ir('admin/novo-emprestimo.php?aluno=' . $alunos['CFG-002']);
confere('novo empréstimo avisa já na escolha do aluno que ele tem livro atrasado', str_contains($html, 'devolução atrasada') && $semErro($html));

$t = $nav['administrador']->csrf('admin/configuracoes.php');
$nav['administrador']->ir('admin/configuracoes.php', ['csrf' => $t, 'ETIQUETA_BIBLIOTECA' => '<script>alert(1)</script>']);
[, $html] = $nav['administrador']->ir('admin/configuracoes.php');
confere('XSS: valor salvo aparece escapado', !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
$nav['administrador']->ir('admin/configuracoes.php', ['csrf' => $t, 'ETIQUETA_BIBLIOTECA' => '']);

// Site em manutenção.
[$s] = $anon->ir('');
confere('site público no ar antes da manutenção', $s === 200);
$nav['administrador']->ir('admin/configuracoes.php', ['csrf' => $t, 'SITE_MANUTENCAO' => '1', 'SITE_MANUTENCAO_MENSAGEM' => "Voltamos às 14h.\n<b>teste</b>"]);
config_banco(true);
[$s, $html] = $anon->ir('');
confere('manutenção ligada: página inicial responde 503 com a mensagem (escapada)', $s === 503 && str_contains($html, 'Voltamos às 14h.') && str_contains($html, '&lt;b&gt;teste&lt;/b&gt;') && !str_contains($html, 'Livro Fictício'));
[$s, $html] = $anon->ir('biblioteca_php/catalogo.php?q=livro');
confere('manutenção ligada: catálogo também fica fora do ar', $s === 503 && !str_contains($html, 'Livro Fictício'));
[$s] = $nav['administrador']->ir('admin/index.php');
confere('manutenção ligada: painel continua funcionando', $s === 200);
[, $html] = $nav['administrador']->ir('admin/manutencao.php');
confere('manutenção aparece no diagnóstico', str_contains($html, 'Em manutenção'));
$nav['administrador']->ir('admin/configuracoes.php', ['csrf' => $t, 'SITE_MANUTENCAO' => '']);
[$s] = $anon->ir('');
confere('manutenção desligada: site volta ao ar', $s === 200);

// Cópia de segurança pela página.
$t = $nav['administrador']->csrf('admin/manutencao.php');
[$s, $html, , $tipo] = $nav['administrador']->ir('admin/manutencao.php', ['csrf' => $t, 'acao' => 'backup']);
confere('cópia sem confirmar o cuidado com dados pessoais é recusada', $s === 200 && str_contains($html, 'não foi gerada') && !str_contains($tipo, 'sql'));
[$s, $corpo, , $tipo, $disp] = $nav['administrador']->ir('admin/manutencao.php', ['csrf' => $t, 'acao' => 'backup', 'ciente' => '1']);
confere('cópia pela página baixa um .sql completo', $s === 200 && str_contains($tipo, 'application/sql') && preg_match('/attachment; filename="biblioteca-dom-pedro-ii-[\d-]+\.sql"/', $disp)
    && str_contains($corpo, 'CREATE TABLE `livros`') && str_contains($corpo, 'Fim da cópia de segurança') && !str_contains($corpo, 'ERRO'));
confere('cópia registrada nas ações administrativas', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'sistema.backup'") === 1);
$t = $nav['bibliotecaria']->csrf('admin/configuracoes.php');
[$s, $corpo] = $nav['bibliotecaria']->ir('admin/manutencao.php', ['csrf' => $t, 'acao' => 'backup', 'ciente' => '1']);
confere('bibliotecária não consegue baixar a cópia (403)', $s === 403 && !str_contains($corpo, 'CREATE TABLE'));

// Limpeza de tentativas de login.
$pdo->exec("INSERT INTO login_tentativas (email, ip, sucesso, criado_em) VALUES ('velho@teste.br', '1.1.1.1', 0, NOW() - INTERVAL 40 DAY), ('velho2@teste.br', '1.1.1.1', 1, NOW() - INTERVAL 31 DAY)");
$recentes = $num('SELECT COUNT(*) FROM login_tentativas WHERE criado_em >= NOW() - INTERVAL 30 DAY');
$t = $nav['administrador']->csrf('admin/manutencao.php');
[$s] = $nav['administrador']->ir('admin/manutencao.php', ['csrf' => $t, 'acao' => 'limpar_tentativas']);
confere('limpeza apaga só tentativas com mais de 30 dias', $s === 303 && $num('SELECT COUNT(*) FROM login_tentativas WHERE criado_em < NOW() - INTERVAL 30 DAY') === 0 && $num('SELECT COUNT(*) FROM login_tentativas') === $recentes);

// Limite de tentativas configurado no painel.
$repo->salvar(['LOGIN_MAX_TENTATIVAS' => '3'], $contas['administrador']);
$pdo->exec("DELETE FROM login_tentativas WHERE email = 'cons@teste.br'");
$tentativa = new Navegador($base);
for ($i = 0; $i < 3; $i++) {
    $tentativa->entrar('cons@teste.br', 'senha-errada-123');
}
confere('com LOGIN_MAX_TENTATIVAS = 3, a 4ª tentativa (mesmo com a senha certa) é bloqueada', str_contains($tentativa->entrar('cons@teste.br', $senhaPadrao), 'Muitas tentativas'));
$repo->salvar(['LOGIN_MAX_TENTATIVAS' => ''], $contas['administrador']);

// Erros de PHP no servidor.
$logServidor = (string)@file_get_contents($log);
confere('nenhum erro de PHP registrado pelo servidor durante os testes', !preg_match('/(PHP (Fatal|Warning|Notice|Deprecated)|Uncaught)/', $logServidor), mb_substr($logServidor, 0, 400));

// ---------------------------------------------------------------
echo "\n8. Preservação dos dados\n";
$depois = $retrato();
$esperadoDepois = $antes;
confere('livros, exemplares, alunos, contas, textos do site, CDD, importações e inventários intactos', array_diff_key($depois, ['emprestimos' => 1, 'movimentacoes' => 1]) === array_diff_key($esperadoDepois, ['emprestimos' => 1, 'movimentacoes' => 1]));
confere('empréstimos só os criados pelo próprio teste (nenhum apagado)', $depois['emprestimos'] === $antes['emprestimos']);

echo "\n" . ($falhas === 0 ? "Todos os $total testes passaram." : "$falhas de $total testes falharam.") . "\n";
exit($falhas === 0 ? 0 : 1);
