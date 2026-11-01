<?php
/**
 * Testes da central de relatórios (Etapa 5): totais, separação entre registros, exemplares e
 * quantidade histórica, circulação por período, atrasos, alunos, inventários, CDD, gráficos,
 * filtros de período, exportações (PDF, CSV, XLSX), acentos, zeros à esquerda, injeção de
 * fórmulas, permissões, privacidade, paginação/desempenho e integridade dos dados.
 *
 * Uso: php admin/tests/relatorios-teste.php
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá para criar um banco
 * temporário "bdp_teste_relatorios_XXXX", aplica as migrações, cria dados FICTÍCIOS só nesse
 * banco e o apaga no final. Nenhum dado real de aluno é usado.
 * Para outro usuário do MySQL, defina TESTE_DB_USER e TESTE_DB_PASSWORD.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\RelatorioRepository;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\RelatorioService;
use Biblioteca\Support\ExportadorTabela;
use Biblioteca\Support\PeriodoRelatorio;
use Biblioteca\Support\RelatorioPdf;

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

$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "Testes não executados: configure DB_HOST/DB_USER no .env.\n";
    exit(0);
}
$banco = 'bdp_teste_relatorios_' . bin2hex(random_bytes(4));
$raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$servidor = null;
$temporarios = [];
register_shutdown_function(function () use ($raiz, $banco, &$servidor, &$temporarios) {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
    }
    foreach ($temporarios as $f) {
        @unlink($f);
    }
    $raiz->exec("DROP DATABASE IF EXISTS `$banco`");
    echo "Banco temporário $banco apagado.\n";
});
echo "Banco temporário: $banco (o banco do .env não é usado)\n";
$envTeste = ['DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_NAME' => $banco, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/',
    'APP_TIMEZONE' => 'America/Sao_Paulo', 'EMPRESTIMO_PRAZO_DIAS' => '', 'ETIQUETA_BIBLIOTECA' => '', 'APP_ENV' => 'development'];
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
$exec = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int)$pdo->lastInsertId();
};

// ---------------------------------------------------------------
// Dados fictícios (somente no banco temporário)
// ---------------------------------------------------------------
$tz = new DateTimeZone('America/Sao_Paulo');
$H = new DateTimeImmutable('today', $tz);
$hoje = $H->format('Y-m-d');
$d = fn (int $dias, string $hora = '10:00:00') => $H->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d') . ' ' . $hora;
$dia = fn (int $dias) => $H->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');

$senhaPadrao = 'SenhaDeTeste123';
$contas = [];
foreach ([['Ana Admin', 'admin@teste.br', 'administrador'], ['Bia Bibliotecaria', 'bib@teste.br', 'bibliotecaria'],
          ['Caio Auxiliar', 'aux@teste.br', 'auxiliar'], ['Duda Consulta', 'cons@teste.br', 'consulta']] as [$n, $em, $p]) {
    $contas[$p] = $exec('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)', [$n, $em, password_hash($senhaPadrao, PASSWORD_DEFAULT), $p]);
}
$bibId = $contas['bibliotecaria'];
$catLit = $exec("INSERT INTO categorias (nome) VALUES ('Literatura')");
$catCien = $exec("INSERT INTO categorias (nome) VALUES ('Ciências')");
$autMachado = $exec("INSERT INTO autores (nome) VALUES ('Machado Fictício')");
$autAutora = $exec("INSERT INTO autores (nome) VALUES ('Autora de Teste Ção')");
$locA = $exec("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Literatura', '01', '1', 'LIT-01-1')");
$locB = $exec("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Ciências', '02', '3', 'CIE-02-3')");

$livro = [];
foreach ([
    'dom' => ['Dom Casmurro de Teste', '0306406152', 'Editora X', 1899, $autMachado, $catLit, 'Romance'],
    'dup' => ['Dom Casmurro de Teste', null, 'Editora Y', null, $autMachado, null, 'Romance'],
    'semautor' => ['Livro Sem Autor', null, null, 2000, null, $catCien, null],
    'formula' => ['=HYPERLINK("http://exemplo.invalid","clique")', '9780000000002', 'Editora Z', 2010, $autAutora, null, 'Teste'],
    'acento' => ['Ação e Reação — Coração <script>alert(1)</script>', '8535902775', 'Ática', 2015, $autAutora, null, 'Física'],
    'planilha' => ['Livro da Planilha Antiga', null, 'Editora Antiga', 1980, $autAutora, null, null],
    'arquivado' => ['Livro Arquivado de Teste', null, 'Editora', 1990, $autAutora, $catLit, null],
] as $k => [$titulo, $isbn, $editora, $ano, $autor, $cat, $assunto]) {
    $livro[$k] = $exec('INSERT INTO livros (titulo, isbn, editora, ano_publicacao, assunto, origem_cadastro) VALUES (?, ?, ?, ?, ?, ?)', [$titulo, $isbn, $editora, $ano, $assunto, $k === 'planilha' ? 'planilha' : 'manual']);
    if ($autor) {
        $exec('INSERT INTO livro_autores (livro_id, autor_id) VALUES (?, ?)', [$livro[$k], $autor]);
    }
    if ($cat) {
        $exec('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, 1)', [$livro[$k], $cat]);
    }
}
// Quantidade histórica da planilha (5) para o livro sem exemplar físico.
$imp = $exec("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, status) VALUES ('planilha-ficticia.xlsx', ?, 'x.xlsx', 'xlsx', 100, 'concluida')", [hash('sha256', 'ficticia')]);
$item = $exec("INSERT INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, hash_linha) VALUES (?, 2, '{}', '{}', ?)", [$imp, sha1('linha')]);
$exec("INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, cdd_original, quantidade_informada, dados_originais) VALUES (?, ?, ?, 'planilha-ficticia.xlsx', 2, '028.5', 5, '{}')", [$imp, $item, $livro['planilha']]);

$ex = [];
$n = 0;
foreach ([
    ['dom', 'disponivel', $locA, 'bom'], ['dom', 'emprestado', $locA, 'bom'], ['dom', 'emprestado', $locB, 'bom'],
    ['semautor', 'manutencao', null, 'necessita_reparo'], ['semautor', 'danificado', $locB, 'danificado'],
    ['formula', 'extraviado', $locA, 'bom'], ['formula', 'baixado', $locA, 'regular'],
    ['acento', 'disponivel', $locA, 'regular'], ['acento', 'aguardando', null, 'bom'], ['acento', 'emprestado', $locA, 'bom'],
    ['arquivado', 'disponivel', $locA, 'bom'], ['acento', 'emprestado', $locA, 'bom'],
] as [$l, $sit, $loc, $cons]) {
    $n++;
    $ex[$n] = $exec('INSERT INTO exemplares (livro_id, codigo_individual, localizacao_id, estado_conservacao, situacao, baixado_em) VALUES (?, ?, ?, ?, ?, ?)',
        [$livro[$l], sprintf('BDP-%06d', $n), $loc, $cons, $sit, $sit === 'baixado' ? $d(-100) : null]);
}
$pdo->exec('UPDATE livros SET arquivado_em = NOW() WHERE id = ' . $livro['arquivado']);

$aluno = [];
foreach ([
    'a1' => ['Aluna Fictícia Um', 'TESTE-0001', '9A', 'manha', 1, 'aluna.um@escola.teste'],
    'a2' => ['Aluno Fictício Dois', '00012345', '9A', 'tarde', 1, 'aluno.dois@escola.teste'],
    'a3' => ['Aluno Inativo Fictício', 'TESTE-0003', '8B', 'manha', 0, null],
    'a4' => ['Aluno Sem Turma Fictício', 'TESTE-0004', null, null, 1, null],
] as $k => [$nome, $mat, $turma, $turno, $ativo, $email]) {
    $aluno[$k] = $exec('INSERT INTO alunos (nome, matricula, turma, turno, ativo, email) VALUES (?, ?, ?, ?, ?, ?)', [$nome, $mat, $turma, $turno, $ativo, $email]);
}
$emp = [];
foreach ([
    'e1' => [2, 'a1', -20, -10, null, 1],
    'e2' => [3, 'a2', -40, -30, null, 0],
    'e3' => [10, 'a1', -2, 5, null, 0],
    'e4' => [1, 'a2', -60, -50, -45, 0],
    'e5' => [8, 'a4', -5, 9, -1, 0],
    'e6' => [1, 'a1', -400, -390, -391, 0],
    'e7' => [12, 'a2', -10, -3, null, 0],
] as $k => [$exN, $al, $ret, $prev, $dev, $ren]) {
    $emp[$k] = $exec('INSERT INTO emprestimos (exemplar_id, aluno_id, data_emprestimo, data_prevista, data_devolucao, renovacoes, emprestado_por) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$ex[$exN], $aluno[$al], $d($ret), $dia($prev), $dev === null ? null : $d($dev, '15:00:00'), $ren, $bibId]);
}
$exec('INSERT INTO emprestimo_renovacoes (emprestimo_id, prevista_anterior, prevista_nova, renovado_por, criado_em) VALUES (?, ?, ?, ?, ?)', [$emp['e1'], $dia(-14), $dia(-10), $bibId, $d(-12)]);

// CDD: aprovada (869.3), sugerida, em revisão e CDD antiga só da planilha.
$exec("INSERT INTO classificacoes_cdd (livro_id, status, cdd_aprovada, aprovado_por, aprovado_em) VALUES (?, 'aprovada', '869.3', ?, NOW())", [$livro['dom'], $bibId]);
$exec("INSERT INTO classificacoes_cdd (livro_id, status, cdd_proposta) VALUES (?, 'sugerida', '530')", [$livro['semautor']]);
$exec("INSERT INTO classificacoes_cdd (livro_id, status, precisa_revisao) VALUES (?, 'em_revisao', 1)", [$livro['acento']]);
$exec("INSERT INTO classificacoes_cdd (livro_id, status, cdd_original) VALUES (?, 'pendente', '028.5')", [$livro['planilha']]);

// Inventário real pelo serviço existente: 3 conferências (uma sem divergência, uma de conservação, uma de localização).
$invServ = new InventarioService($pdo, new ExemplarService($pdo), new InventarioRepository($pdo));
$inv = $invServ->criar(['titulo' => 'Inventário fictício de teste', 'data_inicio' => $hoje, 'responsavel_id' => $bibId], $bibId);
$previstosInv = $invServ->iniciar($inv, $bibId);
$invServ->conferir($inv, $ex[1], ['estado_conservacao' => 'bom', 'localizacao_id' => $locA], $bibId);
$invServ->conferir($inv, $ex[8], ['estado_conservacao' => 'bom', 'localizacao_id' => $locA], $bibId);
$invServ->conferir($inv, $ex[5], ['estado_conservacao' => 'danificado', 'localizacao_id' => $locA], $bibId);
// Ações administrativas: uma de conta de acesso (com e-mail) e uma de livro.
$exec("INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, 'usuario.cadastrar', 'administrador', ?, ?, '10.0.0.99')", [$contas['administrador'], $contas['consulta'], json_encode(['email' => 'cons@teste.br', 'perfil' => 'consulta'])]);
$exec("INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, 'livro.cadastrar', 'livro', ?, ?, '10.0.0.98')", [$bibId, $livro['dom'], json_encode(['titulo' => 'Dom Casmurro de Teste', 'senha' => 'nao-deveria-aparecer'])]);

/** Retrato das tabelas (para provar que os relatórios não alteram nada). */
$tabelasDados = ['livros', 'autores', 'livro_autores', 'livro_categorias', 'categorias', 'exemplares', 'alunos', 'emprestimos', 'emprestimo_renovacoes', 'classificacoes_cdd',
    'inventarios', 'inventario_itens', 'localizacoes', 'movimentacoes', 'registros_legados', 'administradores'];
$retrato = function () use ($pdo, $tabelasDados): array {
    $r = [];
    foreach ($tabelasDados as $t) {
        $r[$t] = $pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_ASSOC)['Checksum'] . '/' . $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
    return $r;
};
$antes = $retrato();

$repo = new RelatorioRepository($pdo);
$servico = new RelatorioService($repo, new InventarioRepository($pdo));
$periodo = fn (string $p, array $extra = []) => PeriodoRelatorio::deParametros(['periodo' => $p] + $extra, 'America/Sao_Paulo');
$ctx = fn (string $p = 'todo', array $f = [], bool $pessoais = true) => ['periodo' => $periodo($p), 'hoje' => $hoje, 'f' => $f, 'pessoais' => $pessoais, 'ocultar' => []];

// ---------------------------------------------------------------
echo "\n1 a 3. Registros bibliográficos, exemplares físicos e quantidade histórica\n";
$a = $repo->resumoAcervo();
confere('total de registros bibliográficos: 6 ativos (o arquivado fica fora)', $a['total'] === 6 && $a['arquivados'] === 1, json_encode($a));
$e = $repo->resumoExemplares();
confere('exemplares: 11 cadastrados de livros ativos, 9 no acervo', $e['cadastrados'] === 11 && $e['no_acervo'] === 9, json_encode($e));
confere('por situação: 2 disponíveis, 4 emprestados, 1 manutenção, 1 danificado, 1 extraviado, 1 baixado, 1 aguardando',
    $e['disponivel'] === 2 && $e['emprestado'] === 4 && $e['manutencao'] === 1 && $e['danificado'] === 1 && $e['extraviado'] === 1 && $e['baixado'] === 1 && $e['aguardando'] === 1);
confere('exemplares sem localização cadastrada: 2 (manutenção e aguardando; conferir no inventário não muda o cadastro)', $e['sem_localizacao'] === 2, (string)$e['sem_localizacao']);
confere('quantidade histórica (5) separada e NÃO somada aos exemplares', $a['quantidade_historica'] === 5 && $e['cadastrados'] === 11);
confere('registros sem exemplar físico: 3 (duplicado, planilha e o que só tem extraviado/baixado)', $a['sem_exemplar'] === 3, (string)$a['sem_exemplar']);
$lp = $servico->linhas('livros', $ctx('todo', ['pendencia' => 'sem_exemplar']), 0, 10);
$linhaPlanilha = array_values(array_filter($lp, fn ($l) => $l['_livro_id'] === $livro['planilha']))[0] ?? [];
confere('livro da planilha: 0 exemplares físicos e quantidade histórica 5 em coluna própria', ($linhaPlanilha['exemplares'] ?? -1) === 0 && ($linhaPlanilha['historica'] ?? '') === '5');
confere('duplicados não viram exemplares: o par "Dom Casmurro" continua com 3 físicos no total', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id IN (?, ?)', [$livro['dom'], $livro['dup']]) === 3
    && $servico->linhas('livros', $ctx('todo', ['titulo' => 'Dom Casmurro']), 0, 10)[1]['exemplares'] === 0);

echo "\n4 e 5. Categorias, autores, assuntos e dados incompletos\n";
$cats = array_column($repo->livrosPorCategoria(), 'valor', 'rotulo');
confere('por categoria: Literatura 1, Ciências 1, Não informado 4', ($cats['Literatura'] ?? 0) === 1 && ($cats['Ciências'] ?? 0) === 1 && ($cats['Não informado'] ?? 0) === 4, json_encode($cats));
$aut = array_column($repo->autores(), 'valor', 'rotulo');
confere('autores com mais obras: Autora 3, Machado 2', ($aut['Autora de Teste Ção'] ?? 0) === 3 && ($aut['Machado Fictício'] ?? 0) === 2, json_encode($aut));
$ass = array_column($repo->livrosPorAssunto(), 'valor', 'rotulo');
confere('por assunto: Romance 2 e "Não informado" 2', ($ass['Romance'] ?? 0) === 2 && ($ass['Não informado'] ?? 0) === 2, json_encode($ass));
confere('com ISBN 3, sem ISBN 3, sem autor 1, sem editora 1, sem ano 1', $a['com_isbn'] === 3 && $a['sem_isbn'] === 3 && $a['sem_autor'] === 1 && $a['sem_editora'] === 1 && $a['sem_ano'] === 1, json_encode($a));
confere('informações incompletas: 2 (sem autor/editora e sem ano)', $a['incompletos'] === 2);
confere('possíveis duplicidades: 2 registros, 1 grupo', $a['duplicidade'] === 2 && $repo->contarGruposDuplicidade() === 1);
$li = $servico->linhas('livros', $ctx('todo', ['pendencia' => 'sem_autor']), 0, 10);
confere('"Não informado" no lugar de autor e editora ausentes', count($li) === 1 && $li[0]['autores'] === 'Não informado' && $li[0]['editora'] === 'Não informado');
confere('filtro por autor, categoria e ano', $repo->contarLivros(['autor' => 'Machado']) === 2 && $repo->contarLivros(['categoria' => $catLit]) === 1 && $repo->contarLivros(['ano' => 1899]) === 1 && $repo->contarLivros(['ano' => 'sem']) === 1);
confere('pesquisa por ISBN com zero à esquerda', $repo->contarLivros(['titulo' => '0306406152']) === 1);

echo "\n6 a 10. Empréstimos, devoluções, atrasos e renovações\n";
$c = $repo->resumoCirculacao($hoje);
confere('empréstimos registrados 7, ativos 4, atrasados 3, concluídos 3', $c['total'] === 7 && $c['ativos'] === 4 && $c['atrasados'] === 3 && $c['concluidos'] === 3, json_encode($c));
$p30 = $repo->resumoCirculacaoPeriodo($periodo('30'));
confere('últimos 30 dias: 4 empréstimos (pela retirada), 1 devolução (pela devolução), 1 renovação', $p30['emprestimos'] === 4 && $p30['devolucoes'] === 1 && $p30['renovacoes'] === 1, json_encode($p30));
$p90 = $repo->resumoCirculacaoPeriodo($periodo('90'));
confere('últimos 90 dias: 6 empréstimos, 2 devoluções, 1 devolvido após o prazo', $p90['emprestimos'] === 6 && $p90['devolucoes'] === 2 && $p90['devolvidos_com_atraso'] === 1, json_encode($p90));
confere('empréstimo renovado conta uma vez só', $repo->contarEmprestimos($periodo('todo'), $hoje, []) === 7 && $c['renovacoes'] === 1);
$top = $repo->livrosMaisEmprestados($periodo('todo'), $hoje, []);
confere('livros mais emprestados: Dom Casmurro 4, Ação e Reação 3', $top[0]['livro_id'] == $livro['dom'] && $top[0]['valor'] === 4 && $top[1]['valor'] === 3);
$ec = array_column($repo->emprestimosPorCategoria($periodo('todo'), $hoje, []), 'valor', 'rotulo');
confere('categorias com mais empréstimos: Literatura 4, Não informado 3', ($ec['Literatura'] ?? 0) === 4 && ($ec['Não informado'] ?? 0) === 3, json_encode($ec));
confere('filtro por situação na lista (ativos 4, atrasados 3, concluídos 3)', $repo->contarEmprestimos($periodo('todo'), $hoje, ['situacao' => 'ativos']) === 4
    && $repo->contarEmprestimos($periodo('todo'), $hoje, ['situacao' => 'atrasados']) === 3 && $repo->contarEmprestimos($periodo('todo'), $hoje, ['situacao' => 'concluidos']) === 3);
confere('filtro por livro e por categoria', $repo->contarEmprestimos($periodo('todo'), $hoje, ['livro' => $livro['acento']]) === 3 && $repo->contarEmprestimos($periodo('todo'), $hoje, ['categoria' => $catLit]) === 4);
confere('filtro por turma (9A = 6 empréstimos)', $repo->contarEmprestimos($periodo('todo'), $hoje, ['turma' => '9A']) === 6);
$serie = $servico->graficos('emprestimos', $ctx('30'))[0];
confere('evolução: série diária de 30 dias, somas iguais aos indicadores', count($serie['dados']) === 30 && array_sum(array_column($serie['dados'], 'emprestimos')) === 4 && array_sum(array_column($serie['dados'], 'devolucoes')) === 1);
$serieAno = $servico->graficos('emprestimos', $ctx('todo'))[0];
confere('todo o período: agrupado por mês, inclui o empréstimo do ano passado', array_sum(array_column($serieAno['dados'], 'emprestimos')) === 7);
$at = $repo->resumoAtrasos($hoje);
confere('atrasos: 3, total de 43 dias, faixas 1-7 = 1, 8-15 = 1, >15 = 1', $at['atrasados'] === 3 && $at['dias_total'] === 43 && $at['faixa_1_7'] === 1 && $at['faixa_8_15'] === 1 && $at['faixa_16'] === 1, json_encode($at));
confere('lista por faixa', $repo->contarAtrasados($hoje, '16') === 1 && $repo->atrasados($hoje, '16')[0]['dias_atraso'] == 30);
$dv = $repo->resumoDevolvidosComAtraso($periodo('90'));
confere('devoluções após o prazo (90 dias): 1, com 5 dias', $dv['total'] === 1 && $dv['dias_total'] === 5);
confere('nenhuma multa é calculada (sem coluna/tabela de multa no relatório)', !preg_grep('/multa/i', array_merge(...array_values(array_map(fn ($t) => array_values($t['colunas']), RelatorioService::tabelas())))));

echo "\n11. Alunos (funciona com a tabela vazia e com dados)\n";
$al = $repo->resumoAlunos($hoje);
confere('4 alunos, 3 ativos, 1 inativo, 2 com empréstimo ativo, 2 com atraso, 1 sem turma', $al['total'] === 4 && $al['ativos'] === 3 && $al['inativos'] === 1 && $al['com_emprestimo_ativo'] === 2 && $al['com_atraso'] === 2 && $al['sem_turma'] === 1, json_encode($al));
$tur = array_column($repo->alunosPorTurma(), null, 'rotulo');
confere('por turma: 9A 2 (2 com empréstimo), 8B 1, Não informado 1', ($tur['9A']['valor'] ?? 0) === 2 && ($tur['9A']['com_emprestimo'] ?? 0) === 2 && ($tur['8B']['valor'] ?? 0) === 1 && ($tur['Não informado']['valor'] ?? 0) === 1);
$turnos = $repo->alunosPorTurno();
confere('por turno: manhã 2, tarde 1, não informado 1', ($turnos['manha'] ?? 0) === 2 && ($turnos['tarde'] ?? 0) === 1 && ($turnos['nao_informado'] ?? 0) === 1);
$lista = $servico->linhas('alunos_emprestimo', $ctx(), 0, 10);
confere('lista nominal em ordem alfabética (não é ranking) e sem e-mail', count($lista) === 2 && $lista[0]['aluno'] < $lista[1]['aluno'] && !str_contains(json_encode($lista, JSON_UNESCAPED_UNICODE), '@escola.teste'));
confere('sem permissão: lista nominal vazia e fora das tabelas da seção', $servico->linhas('alunos_emprestimo', $ctx('todo', [], false)) === [] && !in_array('alunos_emprestimo', RelatorioService::tabelasDaSecao('alunos', ['pessoais' => false]), true));
$semNomes = json_encode(array_merge($servico->linhas('emprestimos', $ctx('todo', [], false), 0, 50), $servico->linhas('atrasos', $ctx('todo', [], false), 0, 50)), JSON_UNESCAPED_UNICODE);
confere('empréstimos e atrasos sem permissão: nenhum nome ou matrícula', !str_contains($semNomes, 'Fictíci') && !str_contains($semNomes, 'TESTE-000') && !str_contains($semNomes, '00012345'));
$comNomes = json_encode($servico->linhas('atrasos', $ctx(), 0, 50), JSON_UNESCAPED_UNICODE);
confere('com permissão: nome e matrícula aparecem, e-mail nunca', str_contains($comNomes, 'Aluna Fictícia Um') && str_contains($comNomes, '00012345') && !str_contains($comNomes, '@escola.teste'));
// Tabela de alunos vazia: banco separado em transação desfeita não é possível com DDL; simula com filtro de turma inexistente.
$repoVazio = new class($pdo) {
    public function __construct(private PDO $pdo) {}
    public function vazio(): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DELETE FROM alunos');
            $r = (new RelatorioRepository($this->pdo))->resumoAlunos(date('Y-m-d'));
            $t = (new RelatorioRepository($this->pdo))->alunosPorTurma();
            return $r['total'] === 0 && $t === [];
        } finally {
            $this->pdo->rollBack();
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
};
confere('relatórios de alunos funcionam com a tabela vazia (transação desfeita no fim)', $repoVazio->vazio() && $num('SELECT COUNT(*) FROM alunos') === 4);

echo "\n12. Inventários\n";
$ri = $repo->resumoInventarios();
confere('1 inventário em andamento', $ri['total'] === 1 && $ri['em_andamento'] === 1);
$li = $servico->linhas('inventarios', $ctx(), 0, 10)[0];
confere('previstos 9 = exemplares no acervo no início', $previstosInv === 9 && $li['previstos'] === 9);
confere('progresso: "3 de 9 exemplares conferidos — 33,3%"', $li['progresso'] === '3 de 9 exemplares conferidos — 33,3%', $li['progresso']);
confere('não conferidos 2, emprestados fora da estante 4, divergência de local 1, de conservação 1', $li['nao_conferidos'] === 2 && $li['em_circulacao'] === 4 && $li['local'] === 1 && $li['estado'] === 1, json_encode($li));
$oficial = (new InventarioRepository($pdo))->resumo($inv);
confere('mesmos números do módulo de inventário (InventarioRepository::resumo)', $oficial['previstos_conferidos'] === $li['conferidos'] && $oficial['nao_conferidos'] === $li['nao_conferidos'] && $oficial['local_diferente'] === $li['local']);
confere('nenhum exemplar não conferido virou extraviado', $num("SELECT COUNT(*) FROM exemplares WHERE situacao = 'extraviado'") === 1 && $num("SELECT status FROM inventarios WHERE id = ?", [$inv]) === 0 && db_query('SELECT status FROM inventarios WHERE id = ?', [$inv])->fetchColumn() === 'em_andamento');
$gi = $servico->graficos('inventarios', $ctx());
confere('gráficos do inventário: progresso, divergências e situação', count($gi) === 3 && $gi[0]['id'] === 'progresso' && array_sum(array_column($gi[0]['dados'], 'valor')) === 9);

echo "\n13. Classificação CDD\n";
$cd = $repo->resumoCdd();
confere('1 aprovada, 5 sem aprovação, 3 aguardando classificação, 1 sugerida, 1 em revisão', $cd['aprovadas'] === 1 && $cd['sem_aprovacao'] === 5 && $cd['pendentes'] === 3 && $cd['sugeridas'] === 1 && $cd['em_revisao'] === 1, json_encode($cd));
confere('CDD antiga (028.5) contada à parte e não como aprovada', $cd['com_cdd_antiga'] === 1 && $repo->contarCdd(['status' => 'aprovada']) === 1);
$cls = $repo->cddPorClasse();
confere('classes principais: 10 classes, classe 8 = 1', count($cls) === 10 && $cls['8'] === 1 && array_sum($cls) === 1);
$lc = array_values(array_filter($servico->linhas('cdd', $ctx(), 0, 20), fn ($l) => $l['id'] === $livro['planilha']))[0] ?? [];
confere('linha da planilha: CDD antiga "028.5" (zero preservado) e aprovada vazia', ($lc['antiga'] ?? '') === '028.5' && ($lc['aprovada'] ?? 'x') === '');
require_once __DIR__ . '/../includes/dashboard-dados.php';
confere('"sem aprovação" igual ao "aguardando reclassificação" do dashboard', dashboard_aguardando_cdd() === $cd['sem_aprovacao']);

echo "\n14. Gráficos\n";
$todos = [];
foreach (array_keys(RelatorioService::SECOES) as $s) {
    foreach ($servico->graficos($s, $ctx('todo')) as $g) {
        $todos[] = $g;
    }
}
confere('todas as seções geram gráficos com título, tipo e dados', count($todos) >= 18 && !array_filter($todos, fn ($g) => !$g['titulo'] || !in_array($g['tipo'], ['barras', 'colunas', 'rosca'], true)));
$gEx = $servico->graficos('exemplares', $ctx())[0];
confere('gráfico de situação = números reais do banco', array_sum(array_column($gEx['dados'], 'valor')) === 11);
$gVazio = $servico->graficos('atrasos', $ctx('7'))[0];
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/relatorios.php';
$htmlVazio = relatorios_grafico(['id' => 'x', 'titulo' => 'T', 'tipo' => 'barras', 'descricao' => '', 'series' => ['valor' => 'Q'], 'dados' => [['rotulo' => 'A', 'valor' => 0]]]);
confere('estado vazio quando não há registros', str_contains($htmlVazio, 'Sem registros para exibir'));
$htmlG = relatorios_grafico($gEx);
confere('gráfico com legenda, valores e tabela de dados', str_contains($htmlG, 'rel-legend') && str_contains($htmlG, 'Ver dados em tabela') && str_contains($htmlG, '>11<'));
$htmlXss = relatorios_grafico(['id' => 'x', 'titulo' => 'T', 'tipo' => 'barras', 'descricao' => '', 'series' => ['valor' => 'Q'], 'dados' => [['rotulo' => '<script>alert(1)</script>', 'valor' => 2]]]);
confere('rótulos dos gráficos escapados (XSS)', !str_contains($htmlXss, '<script>alert(1)</script>'));

echo "\n15. Filtros de período\n";
$agora = new DateTimeImmutable('2026-03-15 23:30:00', $tz);
$p7 = PeriodoRelatorio::deParametros(['periodo' => '7'], 'America/Sao_Paulo', 'ano', $agora);
confere('últimos 7 dias = 09/03 a 15/03 (inclui hoje)', $p7->inicio === '2026-03-09' && $p7->fim === '2026-03-15' && $p7->sqlFimExclusivo() === '2026-03-16 00:00:00');
confere('ano atual = 01/01 até hoje', PeriodoRelatorio::deParametros(['periodo' => 'ano'], 'America/Sao_Paulo', 'ano', $agora)->inicio === '2026-01-01');
$utc = new DateTimeImmutable('2026-03-16 01:30:00', new DateTimeZone('UTC')); // ainda dia 15 em São Paulo
confere('datas no fuso da escola (01:30 UTC ainda é dia 15 em São Paulo)', PeriodoRelatorio::deParametros(['periodo' => '7'], 'America/Sao_Paulo', 'ano', $utc)->fim === '2026-03-15');
$inv1 = PeriodoRelatorio::deParametros(['periodo' => 'personalizado', 'de' => '2026-03-10', 'ate' => '2026-03-01'], 'America/Sao_Paulo', 'ano', $agora);
confere('data inicial depois da final é recusada (não é invertida)', $inv1->erro !== null && str_contains($inv1->erro, 'posterior') && $inv1->preset === 'ano');
confere('datas inválidas e período longo demais são recusados', PeriodoRelatorio::deParametros(['periodo' => 'personalizado', 'de' => '2026-02-31', 'ate' => '2026-03-01'], 'America/Sao_Paulo')->erro !== null
    && PeriodoRelatorio::deParametros(['periodo' => 'personalizado', 'de' => '2000-01-01', 'ate' => '2026-03-01'], 'America/Sao_Paulo')->erro !== null
    && PeriodoRelatorio::deParametros(['periodo' => 'personalizado', 'de' => "2026-01-01' OR 1=1", 'ate' => '2026-03-01'], 'America/Sao_Paulo')->erro !== null);
$ok = PeriodoRelatorio::deParametros(['de' => '2026-01-01', 'ate' => '2026-01-31'], 'America/Sao_Paulo');
confere('período personalizado válido', $ok->erro === null && $ok->preset === 'personalizado' && $ok->dias() === 31 && $ok->rotulo() === '01/01/2026 a 31/01/2026');
$pDia = PeriodoRelatorio::deParametros(['de' => $dia(-1), 'ate' => $dia(-1)], 'America/Sao_Paulo');
confere('devolução às 15h de ontem entra no período "ontem a ontem" (fim inclusivo)', $repo->resumoCirculacaoPeriodo($pDia)['devolucoes'] === 1);
$cards = $servico->indicadores('emprestimos', $ctx('30'));
$cardsMap = array_column($cards, 'valor', 'titulo');
confere('mesmo período nos indicadores, tabela e gráfico', $cardsMap['Empréstimos no período'] === 4 && $servico->contar('emprestimos', $ctx('30')) === 4);
confere('acervo: período usa a data de cadastro no sistema (todos cadastrados hoje)', $repo->contarLivros(['periodo' => $periodo('7')]) === 6 && $repo->contarLivros(['periodo' => PeriodoRelatorio::deParametros(['de' => '2020-01-01', 'ate' => '2020-12-31'], 'America/Sao_Paulo')]) === 0);

echo "\n16 a 19. Exportação: CSV, XLSX e PDF, acentos, zeros à esquerda e fórmulas\n";
$exportCsv = function (string $tabela, array $c) use ($servico): string {
    $out = fopen('php://memory', 'w+');
    (new ExportadorTabela(RelatorioService::colunas($tabela, $c), RelatorioService::tabela($tabela)['tipos'] ?? []))->escreverCsv($out, $servico->iterar($tabela, $c));
    rewind($out);
    return stream_get_contents($out);
};
$csv = $exportCsv('livros', $ctx());
confere('CSV começa com BOM UTF-8 e usa ";"', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'ID;Título;'));
confere('CSV preserva acentos', str_contains($csv, 'Ação e Reação — Coração') && str_contains($csv, 'Ática') && str_contains($csv, 'Autora de Teste Ção'));
confere('CSV: ISBN com zero à esquerda protegido como texto (="0306406152")', str_contains($csv, '"=""0306406152"""') || str_contains($csv, '="0306406152"'));
confere('CSV: título que parece fórmula recebe apóstrofo', str_contains($csv, "'=HYPERLINK") && !preg_match('/(^|;)"?=HYPERLINK/m', $csv));
confere('CSV exporta só os filtrados (pendência sem ISBN = 3 linhas)', count(array_filter(explode("\n", trim($exportCsv('livros', $ctx('todo', ['pendencia' => 'sem_isbn']))))) ) === 4);
$csvEx = $exportCsv('exemplares', $ctx());
confere('CSV: códigos BDP intactos', str_contains($csvEx, 'BDP-000001') && str_contains($csvEx, 'BDP-000012'));
$csvMat = $exportCsv('atrasos', $ctx());
confere('CSV: matrícula só com dígitos mantém os zeros (="00012345")', str_contains($csvMat, '="00012345"') || str_contains($csvMat, '"=""00012345"""'));
confere('células com +, -, @ também são protegidas', ExportadorTabela::celulaSegura('+1') === "'+1" && ExportadorTabela::celulaSegura('-2') === "'-2" && ExportadorTabela::celulaSegura('@SUM(A1)') === "'@SUM(A1)" && ExportadorTabela::celulaSegura("\t=1") === "'\t=1");

$cx = $ctx();
[$arquivoX, $nX] = (new ExportadorTabela(RelatorioService::colunas('livros', $cx), RelatorioService::tabela('livros')['tipos']))->gerarXlsx($servico->iterar('livros', $cx), 'Acervo', ['Biblioteca Dom Pedro II — teste']);
$temporarios[] = $arquivoX;
$zip = new ZipArchive();
$abriu = $zip->open($arquivoX) === true;
$folha = $abriu ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
$wb = $abriu ? (string)$zip->getFromName('xl/workbook.xml') : '';
confere('XLSX válido (zip com planilha e workbook), 6 linhas', $abriu && $folha !== '' && $wb !== '' && $nX === 6);
confere('XLSX: ISBN como texto com zero à esquerda', str_contains($folha, 't="inlineStr"><is><t xml:space="preserve">0306406152</t>'));
confere('XLSX: nenhuma fórmula e título perigoso com apóstrofo', !str_contains($folha, '<f>') && (str_contains($folha, "&apos;=HYPERLINK") || str_contains($folha, "&#039;=HYPERLINK")));
confere('XLSX: acentos em UTF-8 e contagem numérica', str_contains($folha, 'Ação e Reação') && preg_match('/<c r="H\d+" s="2"><v>3<\/v><\/c>/', $folha) === 1);
confere('XLSX: temporário fora de pastas públicas', str_starts_with(realpath($arquivoX), realpath(sys_get_temp_dir())) && !str_starts_with(realpath($arquivoX), realpath(__DIR__ . '/../..')));

$pdfObj = new RelatorioPdf('Biblioteca Dom Pedro II', 'Acervo — Registros bibliográficos', 'Todo o período', ['Pendência' => 'Sem ISBN'], 'Bia Bibliotecaria');
$pdfObj->indicadores([['Registros', 6, 'teste'], ['Exemplares', 11]]);
$pdfObj->grafico('Por categoria', [['rotulo' => 'Literatura', 'valor' => 1], ['rotulo' => 'Não informado', 'valor' => 4]]);
$cols = [];
foreach (RelatorioService::colunas('livros', $cx) as $k => $r) {
    $cols[$k] = [$r, $k === 'titulo' ? 3 : 1, 'esquerda'];
}
$linhasMuitas = (function () use ($servico, $cx) {
    for ($i = 0; $i < 60; $i++) {
        foreach ($servico->linhas('livros', $cx, 0, 10) as $l) {
            yield $l;
        }
    }
})();
$pdfObj->tabela($cols, $linhasMuitas, 300, 360);
$pdf = $pdfObj->gerar();
$textoPdf = '';
preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
foreach ($m[1] as $s) {
    $textoPdf .= @gzuncompress($s) ?: $s;
}
$paginasPdf = preg_match_all('#/Type /Page /#', $pdf);
confere('PDF válido (cabeçalho e %%EOF), várias páginas A4', str_starts_with($pdf, '%PDF-1.4') && str_ends_with(trim($pdf), '%%EOF') && $paginasPdf >= 2 && str_contains($pdf, '/MediaBox [0 0 595.276 841.890]'));
confere('PDF: nome da biblioteca, período, filtros e paginação "Página X de Y"', str_contains($textoPdf, 'Biblioteca Dom Pedro II') && str_contains($textoPdf, 'Todo o per') && str_contains($textoPdf, 'Pend') && str_contains($textoPdf, "(P\xE1gina 1 de $paginasPdf)") && str_contains($textoPdf, "(P\xE1gina $paginasPdf de $paginasPdf)"));
confere('PDF: acentos convertidos para a fonte (WinAnsi), sem "?"', str_contains($textoPdf, "A\xE7\xE3o e Rea\xE7\xE3o"));
confere('PDF: limite de linhas avisa para usar CSV/XLSX', str_contains($textoPdf, 'Mostrando 300 de 360'));

echo "\n22. Paginação e desempenho (5.000 registros fictícios a mais)\n";
foreach (['SET SESSION max_recursive_iterations = 10000', 'SET SESSION cte_max_recursion_depth = 10000'] as $ajuste) {
    try {
        $pdo->exec($ajuste); // MariaDB usa a primeira variável; MySQL, a segunda
    } catch (PDOException) {
    }
}
$pdo->exec("INSERT INTO livros (titulo, editora, ano_publicacao, origem_cadastro)
            WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 5000)
            SELECT CONCAT('Volume fictício ', LPAD(i, 5, '0')), IF(i % 3 = 0, NULL, 'Editora em Lote'), IF(i % 5 = 0, NULL, 1950 + i % 70), 'planilha' FROM n");
$t0 = microtime(true);
$a2 = $repo->resumoAcervo();
$tResumo = microtime(true) - $t0;
confere('resumo do acervo com 5.006 registros (contas certas)', $a2['total'] === 5006 && $a2['sem_editora'] === 1 + 1666 && $a2['sem_ano'] === 1 + 1000, json_encode(['total' => $a2['total'], 'sem_editora' => $a2['sem_editora'], 'sem_ano' => $a2['sem_ano']]));
confere('resumo do acervo em menos de 5 s', $tResumo < 5, number_format($tResumo, 2) . ' s');
$t0 = microtime(true);
$pag = $servico->linhas('livros', $cx, 4975, 25);
$tPag = microtime(true) - $t0;
confere('paginação no servidor: página 200 com 25 linhas em menos de 3 s', count($pag) === 25 && $tPag < 3, number_format($tPag, 2) . ' s');
$memAntes = memory_get_usage();
$out = fopen('php://temp', 'w+');
$linhasCsv = (new ExportadorTabela(RelatorioService::colunas('livros', $cx), RelatorioService::tabela('livros')['tipos']))->escreverCsv($out, $servico->iterar('livros', $cx));
fclose($out);
$memUsada = memory_get_peak_usage() - $memAntes;
confere('CSV de 5.006 linhas em lotes, sem carregar tudo na memória (< 32 MB)', $linhasCsv === 5006 && $memUsada < 32 * 1024 * 1024, round($memUsada / 1048576, 1) . ' MB');
[$arqGrande, $nGrande] = (new ExportadorTabela(RelatorioService::colunas('livros', $cx), RelatorioService::tabela('livros')['tipos']))->gerarXlsx($servico->iterar('livros', $cx));
$temporarios[] = $arqGrande;
confere('XLSX de 5.006 linhas gerado em arquivo temporário', $nGrande === 5006 && filesize($arqGrande) > 10000);
confere('iteração respeita o limite máximo de linhas', iterator_count($servico->iterar('livros', $cx, 1500)) === 1500);
$pdo->exec("DELETE FROM livros WHERE titulo LIKE 'Volume fictício %'");

echo "\n24. Integridade: os relatórios não alteram nenhum dado\n";
foreach (array_keys(RelatorioService::SECOES) as $s) {
    foreach (['todo', '30', 'ano'] as $pp) {
        $servico->indicadores($s, $ctx($pp));
        $servico->graficos($s, $ctx($pp));
    }
}
foreach (array_keys(RelatorioService::tabelas()) as $t) {
    iterator_to_array($servico->iterar($t, $ctx()), false);
}
confere('livros, exemplares, alunos, empréstimos, CDD, inventários etc. idênticos depois de todos os relatórios', $retrato() === $antes, json_encode(array_diff_assoc($retrato(), $antes)));

// ---------------------------------------------------------------
echo "\n20, 21 e 23. Permissões, privacidade e páginas (servidor PHP embutido, como no XAMPP)\n";
$portaHttp = 8790 + random_int(451, 600);
$log = sys_get_temp_dir() . "/$banco.log";
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipesHttp, null, array_merge(getenv(), $envTeste));
$temporarios[] = $log;
usleep(900000);
$base = "http://127.0.0.1:$portaHttp/admin/";

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
    public function entrar(string $email, string $senha): int
    {
        [, $corpo] = $this->ir('login.php');
        $t = preg_match('/name="csrf" value="([a-f0-9]+)"/', $corpo, $m) ? $m[1] : '';
        return $this->ir('login.php', ['csrf' => $t, 'email' => $email, 'senha' => $senha])[0];
    }
}
$semErro = fn (string $html) => !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $html);

$anon = new Navegador($base);
[$s, , $loc] = $anon->ir('relatorios.php');
confere('sem login: central redireciona para o login', $s === 302 && str_contains($loc, 'login.php'));
[$s, $corpo, $loc] = $anon->ir('relatorios.php?secao=atrasos&tabela=atrasos&formato=csv');
confere('sem login: exportação redireciona para o login e não entrega dados', $s === 302 && !str_contains($corpo, 'Fictíci'));

$adm = new Navegador($base);
$adm->entrar('admin@teste.br', $senhaPadrao);
$quebradas = [];
foreach (array_keys(RelatorioService::SECOES) as $sec) {
    foreach (['', '&periodo=7', '&periodo=todo'] as $extra) {
        [$s, $html] = $adm->ir('relatorios.php?secao=' . $sec . $extra);
        if ($s !== 200 || !$semErro($html)) {
            $quebradas[] = "$sec$extra ($s)";
        }
    }
}
confere('administrador: todas as seções abrem sem erros de PHP', !$quebradas, implode(', ', $quebradas));
[$s, $html] = $adm->ir('relatorios.php?secao=atividades');
confere('atividades: administrador vê ações de contas, sem e-mail, IP ou senha', str_contains($html, 'Conta de acesso criada') && !str_contains($html, 'cons@teste.br') && !str_contains($html, '10.0.0.99') && !str_contains($html, 'nao-deveria-aparecer'));
[$s, $html] = $adm->ir('index.php');
confere('dashboard abre e tem atalho para os relatórios', $s === 200 && $semErro($html) && str_contains($html, 'relatorios.php'));
$dash = dashboard_dados_mysql();
$sec = array_column($dash['secundarios'], 'valor', 'titulo');
confere('dashboard e relatórios: mesmos ativos (4), atrasados (3) e exemplares no acervo (9)', $sec['Empréstimos ativos'] === 4 && $sec['Empréstimos atrasados'] === 3 && $dash['indicadores'][1]['valor'] === 9
    && $repo->resumoCirculacao($hoje)['ativos'] === 4 && $repo->resumoExemplares()['no_acervo'] === 9);
[$s, $html] = $adm->ir('relatorios.php?secao=emprestimos');
confere('menu lateral com "Relatórios" e "Atividades" prontos', str_contains($html, 'href="/admin/relatorios.php"') && str_contains($html, 'relatorios.php?secao=atividades') && !str_contains($html, 'pagina=relatorios'));

$bib = new Navegador($base);
$bib->entrar('bib@teste.br', $senhaPadrao);
[$s, $csvB, , $tipo, $disp] = $bib->ir('relatorios.php?secao=atrasos&tabela=atrasos&formato=csv');
confere('bibliotecária exporta atrasos com nomes (CSV, nome de arquivo descritivo)', $s === 200 && str_contains($tipo, 'text/csv') && str_contains($csvB, 'Aluna Fictícia Um') && preg_match('/filename="relatorio-atrasos-atrasos-\d{4}-\d{2}-\d{2}-a-\d{4}-\d{2}-\d{2}\.csv"/', $disp) === 1, $disp);
confere('exportação com dados pessoais registrada (sem nomes no registro)', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'relatorio.exportar' AND JSON_EXTRACT(detalhes, '$.dados_pessoais') = true AND usuario_id = ?", [$bibId]) === 1
    && !str_contains((string)db_query("SELECT GROUP_CONCAT(detalhes) FROM acoes_administrativas WHERE acao = 'relatorio.exportar'")->fetchColumn(), 'Fictíci'));
[$s, $xlsx, , $tipo] = $bib->ir('relatorios.php?secao=acervo&tabela=livros&formato=xlsx&pendencia=sem_isbn');
$tmpX = tempnam(sys_get_temp_dir(), 'bdpteste');
$temporarios[] = $tmpX;
file_put_contents($tmpX, $xlsx);
$z = new ZipArchive();
$fx = $z->open($tmpX) === true ? (string)$z->getFromName('xl/worksheets/sheet1.xml') : '';
confere('XLSX pelo navegador: só os 3 registros filtrados, com cabeçalho de período e filtros', $s === 200 && str_contains($tipo, 'spreadsheetml') && substr_count($fx, '<row ') === 4 + 3 && str_contains($fx, 'Pendência: Sem ISBN'));
[$s, $pdfB, , $tipo] = $bib->ir('relatorios.php?secao=inventarios&tabela=inventarios&formato=pdf');
confere('PDF pelo navegador (inventários)', $s === 200 && str_contains($tipo, 'application/pdf') && str_starts_with($pdfB, '%PDF'));
[$s, $html] = $bib->ir('relatorios.php?secao=emprestimos&periodo=personalizado&de=2026-05-10&ate=2026-05-01');
confere('período com data inicial depois da final: aviso na tela', $s === 200 && str_contains($html, 'A data inicial não pode ser posterior à data final'));
[$s, $html] = $bib->ir('relatorios.php?secao=emprestimos&periodo=personalizado&de=2026-05-10&ate=2026-05-01&tabela=emprestimos&formato=csv');
confere('…e a exportação com período inválido é recusada', $s === 422);
[$s, $html] = $bib->ir('relatorios.php?secao=acervo&titulo=' . rawurlencode("' OR 1=1 -- "));
confere('SQL injection no filtro não lista tudo nem quebra a página', $s === 200 && $semErro($html) && str_contains($html, 'Nenhum registro encontrado'));
[$s, $html] = $bib->ir('relatorios.php?secao=acervo&categoria=' . rawurlencode('1 OR 1=1') . '&status=' . rawurlencode("x' OR '1'='1"));
confere('filtros com valores fora da lista são descartados', $s === 200 && $semErro($html));
[$s, $html] = $bib->ir('relatorios.php?secao=acervo&titulo=' . rawurlencode('<script>alert(9)</script>'));
confere('filtro com HTML é escapado (XSS)', $s === 200 && !str_contains($html, '<script>alert(9)</script>'));
[$s, $html] = $bib->ir('relatorios.php?secao=acervo');
confere('título com HTML nas tabelas é escapado (XSS)', !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
[$s] = $bib->ir('relatorios.php?secao=acervo&tabela=nao_existe&formato=csv');
[$s2] = $bib->ir('relatorios.php?secao=acervo&tabela=atrasos&formato=csv');
[$s3] = $bib->ir('relatorios.php?secao=acervo&tabela=livros&formato=exe');
confere('tabela inexistente, de outra seção ou formato inválido: 404', $s === 404 && $s2 === 404 && $s3 === 404);

$aux = new Navegador($base);
$aux->entrar('aux@teste.br', $senhaPadrao);
[$s, $html] = $aux->ir('relatorios.php?secao=atrasos');
confere('auxiliar vê nomes nos atrasos (já vê na circulação)', $s === 200 && str_contains($html, 'Aluna Fictícia Um'));
[$s, $csvA] = $aux->ir('relatorios.php?secao=atrasos&tabela=atrasos&formato=csv');
confere('auxiliar exporta atrasos SEM nomes e matrículas', $s === 200 && !str_contains($csvA, 'Aluna Fictícia') && !str_contains($csvA, '00012345') && str_contains($csvA, 'BDP-'));
[$s] = $aux->ir('relatorios.php?secao=alunos&tabela=alunos_emprestimo&formato=csv');
confere('auxiliar não exporta a lista nominal de alunos (403)', $s === 403);
[$s] = $aux->ir('relatorios.php?secao=atividades');
confere('auxiliar não abre as atividades administrativas (403)', $s === 403);

$cons = new Navegador($base);
$cons->entrar('cons@teste.br', $senhaPadrao);
[$s, $html] = $cons->ir('relatorios.php');
confere('consulta abre a visão geral', $s === 200 && $semErro($html));
[$s, $html] = $cons->ir('relatorios.php?secao=emprestimos');
confere('consulta: empréstimos agregados, sem nenhum nome ou matrícula de aluno', $s === 200 && !preg_match('/Alun[oa] [^<]*Fictíci|TESTE-000|00012345/u', $html) && !str_contains($html, 'name="turma"'));
[$s, $html] = $cons->ir('relatorios.php?secao=atrasos');
confere('consulta: atrasos sem nomes', $s === 200 && !preg_match('/Alun[oa] [^<]*Fictíci|TESTE-000|00012345/u', $html));
[$s] = $cons->ir('relatorios.php?secao=alunos');
[$s2] = $cons->ir('relatorios.php?secao=atividades');
[$s3] = $cons->ir('relatorios.php?secao=alunos&tabela=turmas&formato=csv');
confere('consulta: alunos e atividades bloqueados no servidor (403), inclusive a exportação', $s === 403 && $s2 === 403 && $s3 === 403);
[$s, $csvC] = $cons->ir('relatorios.php?secao=emprestimos&tabela=emprestimos&formato=csv&periodo=todo&turma=9A');
confere('consulta: exportação de empréstimos sem nomes e com o filtro de turma ignorado', $s === 200 && !preg_match('/Fictíci|TESTE-000|00012345/u', $csvC) && substr_count(trim($csvC), "\n") === 7);
[$s, $html] = $cons->ir('relatorios.php?secao=visao');
confere('consulta: menu sem "Atividades"', !str_contains($html, 'relatorios.php?secao=atividades'));
confere('nenhum arquivo de relatório gravado em pastas públicas', !glob(__DIR__ . '/../../{uploads,admin,storage}/**/relatorio-*', GLOB_BRACE) && !glob(__DIR__ . '/../../relatorio-*'));
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', (string)@file_get_contents($log)), substr((string)@file_get_contents($log), 0, 600));
// O login grava o último acesso em administradores; o resto precisa ficar idêntico.
$semLogin = fn (array $r) => array_diff_key($r, ['administradores' => 1]);
confere('dados intactos depois das páginas e exportações (só o registro das exportações e o último acesso do login mudam)', $semLogin($retrato()) === $semLogin($antes), json_encode(array_diff_assoc($retrato(), $antes)));

echo "\n" . ($falhas === 0 ? "Todos os $total testes passaram." : "$falhas de $total testes falharam.") . "\n";
exit($falhas === 0 ? 0 : 1);
