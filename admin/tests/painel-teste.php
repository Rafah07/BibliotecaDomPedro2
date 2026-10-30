<?php
/**
 * Testes do painel administrativo (Etapa 1): livros, administradores, permissões e segurança.
 *
 * Uso: php admin/tests/painel-teste.php
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá (DB_HOST, DB_PORT,
 * DB_USER, DB_PASSWORD) para criar um banco temporário "bdp_teste_painel_XXXX", aplica as
 * migrações, gera 3.000 livros FICTÍCIOS só nesse banco e o apaga no final.
 * Para as páginas, sobe um servidor PHP embutido (php -S) apontando para o banco temporário.
 * O usuário precisa poder criar bancos (no XAMPP, o root pode). Para outro usuário, defina
 * TESTE_DB_USER e TESTE_DB_PASSWORD.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Repositories\AdministradorRepository;
use Biblioteca\Repositories\LivroRepository;

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
$banco = 'bdp_teste_painel_' . bin2hex(random_bytes(4));
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
foreach (['DB_HOST' => $host, 'DB_PORT' => $porta, 'DB_NAME' => $banco, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/'] as $k => $val) {
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
$pdo->exec("INSERT INTO categorias (nome) VALUES ('Literatura'), ('Ciências')");
$pdo->beginTransaction();
$ins = $pdo->prepare("INSERT INTO livros (titulo, editora, ano_publicacao, origem_cadastro, criado_em) VALUES (?, ?, ?, 'planilha', ?)");
$autor = $pdo->prepare('INSERT INTO autores (nome) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
$vinc = $pdo->prepare('INSERT INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, 1)');
for ($i = 1; $i <= 3000; $i++) {
    $ins->execute(["Livro de teste $i", 'Editora ' . ($i % 7), 1990 + $i % 30, date('Y-m-d H:i:s', strtotime("2020-01-01 +$i hour"))]);
    $id = (int)$pdo->lastInsertId();
    $autor->execute(['Autor ' . ($i % 50)]);
    $vinc->execute([$id, (int)$pdo->lastInsertId()]);
}
$pdo->exec("INSERT INTO livros (titulo, origem_cadastro) VALUES ('Ação e reação na física', 'manual'), ('CORAÇÃO DE ESTUDANTE', 'manual'), ('Pequeno príncipe', 'manual')");
$pdo->commit();
$repo = new LivroRepository($pdo);
$admins = new AdministradorRepository($pdo);

// ---------------------------------------------------------------
echo "\nListagem: pesquisa, filtros e paginação\n";
$r = $repo->listar([], 1, 25);
confere('3.003 títulos, 25 por página, 121 páginas', $r['total'] === 3003 && count($r['itens']) === 25 && $r['paginas'] === 121);
$r = $repo->listar([], 500, 25);
confere('página acima do limite volta para a última', $r['pagina'] === 121 && count($r['itens']) === 3);
$t = microtime(true);
$repo->listar(['q' => 'livro teste', 'ordem' => 'autor'], 50, 25);
confere('consulta com pesquisa + ordenação por autor em menos de 1 s', microtime(true) - $t < 1);
// "acao" aparece em "Ação e reação" e em "CORAÇÃO".
confere('pesquisa sem acento encontra com acento ("acao")', $repo->listar(['q' => 'acao'], 1, 25)['total'] === 2);
confere('pesquisa com acento encontra sem diferença ("Ação")', $repo->listar(['q' => 'Ação'], 1, 25)['total'] === 2);
confere('pesquisa "reacao" encontra "reação"', $repo->listar(['q' => 'reacao'], 1, 25)['total'] === 1);
confere('maiúsculas/minúsculas e acentos ("coracao" → CORAÇÃO)', $repo->listar(['q' => 'coracao'], 1, 25)['total'] === 1);
confere('"principe" encontra "príncipe"', $repo->listar(['q' => 'principe'], 1, 25)['total'] === 1);
// Cada palavra pode estar em qualquer campo: "49" também aparece em títulos; os 60 livros do autor precisam vir.
$porAutor = $repo->listar(['q' => 'Autor 49'], 1, 100);
confere('pesquisa por autor', count(array_filter($porAutor['itens'], fn ($l) => $l['autores'] === 'Autor 49')) === 60);
confere('várias palavras (todas precisam aparecer)', $repo->listar(['q' => 'teste 2999'], 1, 25)['total'] === 1);
confere('texto malicioso não vira SQL', $repo->listar(['q' => "' OR 1=1 -- %"], 1, 25)['total'] === 0);
confere('curinga % digitado é tratado como texto', $repo->listar(['q' => '%'], 1, 25)['total'] === 0);
$r = $repo->listar(['ordem' => 'titulo', 'dir' => 'desc'], 1, 5);
confere('ordenação por título (Z–A)', $r['itens'][0]['titulo'] === 'Pequeno príncipe');
$r = $repo->listar(['ordem' => 'cadastro', 'dir' => 'desc'], 1, 5);
confere('ordenação por data de cadastro', in_array($r['itens'][0]['titulo'], ['Ação e reação na física', 'CORAÇÃO DE ESTUDANTE', 'Pequeno príncipe'], true));
$r = $repo->listar(['ordem' => 'autor', 'dir' => 'asc'], 121, 25);
confere('ordenação por autor deixa livros sem autor no fim', end($r['itens'])['autores'] === null);
confere('filtro por origem', $repo->listar(['origem' => 'manual'], 1, 25)['total'] === 3);
confere('filtro "aguardando reclassificação CDD"', $repo->listar(['cdd' => 'aguardando'], 1, 25)['total'] === 3003);
confere('filtro "sem categoria"', $repo->listar(['categoria' => 'sem'], 1, 25)['total'] === 3003);

// ---------------------------------------------------------------
echo "\nCadastro, validação e edição\n";
require_once __DIR__ . '/../includes/livros.php';
[$d, $e] = livros_validar(['titulo' => '', 'isbn' => '978-85-359-0277-1', 'ano_publicacao' => '3000'], true);
confere('título obrigatório', isset($e['titulo']));
confere('ISBN com dígito errado é recusado (não é "corrigido")', isset($e['isbn']));
confere('ano fora do intervalo é recusado', isset($e['ano_publicacao']));
[$d, $e] = livros_validar(['titulo' => 'Dom Casmurro', 'isbn' => '978-85-359-0277-8', 'autores' => ['Machado de Assis', ' machado  de assis '], 'status_validacao' => 'validado', 'cdd' => '869,3'], true);
confere('ISBN-13 válido aceito e gravado só com dígitos', !isset($e['isbn']) && $d['isbn'] === '9788535902778');
confere('CDD com vírgula é recusada', isset($e['cdd']));
[$d, $e] = livros_validar(['titulo' => 'Dom Casmurro', 'isbn' => '85-359-0277-5', 'status_validacao' => 'validado'], true);
confere('ISBN-10 válido aceito', !$e && $d['isbn'] === '8535902775');
[$d, $e] = livros_validar(['titulo' => 'Sem dados', 'status_validacao' => 'aguardando_conferencia'], true);
confere('campos vazios ficam vazios (nada inventado)', !$e && $d['isbn'] === null && $d['cdd'] === '' && $d['categoria'] === null);
[$d, $e] = livros_validar(['titulo' => 'X', 'categoria' => '999', 'status_validacao' => 'validado'], true);
confere('categoria inexistente é recusada', isset($e['categoria']));

[$dados] = livros_validar(['titulo' => 'Dom Casmurro', 'isbn' => '9788535902778', 'autores' => ['Machado de Assis', ' machado  de assis '], 'editora' => 'Companhia', 'status_validacao' => 'validado'], true);
$novo = $repo->criar($dados);
$l = $repo->buscar($novo);
confere('livro cadastrado como manual, autores sem repetição', $l['origem_cadastro'] === 'manual' && $l['autores'] === ['Machado de Assis']);
confere('cadastro não cria exemplares', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$novo]) === 0);
$dup = $repo->duplicidades(['isbn' => '8535902775', 'titulo' => 'Outro'], null);
confere('mesmo ISBN em formato 10 é detectado como duplicado', $dup['isbn'] && (int)$dup['isbn']['id'] === $novo);
confere('título igual aparece como aviso', count($repo->duplicidades(['isbn' => null, 'titulo' => 'dom casmurro'])['titulo']) === 1);
$versao = $l['atualizado_em'];
sleep(1);
$alt = $repo->atualizar($novo, ['titulo' => 'Dom Casmurro', 'subtitulo' => 'Romance', 'autores' => ['Machado de Assis', 'Outro Autor'], 'editora' => 'Companhia', 'isbn' => '9788535902778', 'status_validacao' => 'validado'], $versao);
confere('edição devolve só os campos alterados', array_keys($alt) == ['subtitulo', 'autores']);
$erroVersao = null;
try {
    $repo->atualizar($novo, ['titulo' => 'Outro título', 'status_validacao' => 'validado'], $versao);
} catch (RuntimeException $ex) {
    $erroVersao = $ex->getMessage();
}
confere('edição simultânea é recusada (versão antiga)', $erroVersao !== null && $repo->buscar($novo)['titulo'] === 'Dom Casmurro');

// Livro "importado": dados originais em registros_legados continuam iguais depois da edição.
$pdo->exec("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, status) VALUES ('acervo.xlsx', SHA2('x', 256), 'a.xlsx', 'xlsx', 1, 'concluida')");
$impId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, hash_linha, livro_resultante_id, resultado_importacao, processado_em) VALUES (?, 2, '{\"Título\":\"LIVRO DE TESTE 1\"}', '{}', SHA1('a'), 1, 'criado', NOW())")->execute([$impId]);
$itemId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, titulo_original, cdd_original, dados_originais) VALUES (?, ?, 1, 'acervo.xlsx', 2, 'LIVRO DE TESTE 1', '869.3', '{\"Título\":\"LIVRO DE TESTE 1\",\"CDD\":\"869.3\"}')")->execute([$impId, $itemId]);
$l1 = $repo->buscar(1);
$repo->atualizar(1, ['titulo' => 'Livro de teste 1 (corrigido)', 'autores' => $l1['autores'], 'status_validacao' => 'validado'], $l1['atualizado_em']);
confere('editar livro importado preserva o título original da planilha', db_query('SELECT titulo_original FROM registros_legados WHERE livro_id = 1')->fetchColumn() === 'LIVRO DE TESTE 1');
confere('origem da importação disponível para consulta', ($repo->origemImportacao(1)[0]['dados_originais']['CDD'] ?? null) === '869.3');
confere('CDD antiga aparece na listagem', $repo->listar(['q' => 'corrigido'], 1, 5)['itens'][0]['cdd_antiga'] === '869.3');

// ---------------------------------------------------------------
echo "\nArquivamento seguro\n";
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual, situacao) VALUES (2, 'BDP-000001', 'disponivel')");
$bloqueio = null;
try {
    $repo->arquivar(2);
} catch (RuntimeException $ex) {
    $bloqueio = $ex->getMessage();
}
confere('livro com exemplar em uso não é arquivado', $bloqueio !== null && $repo->buscar(2)['arquivado_em'] === null);
$pdo->exec("INSERT INTO alunos (nome, matricula) VALUES ('Aluno Fictício', 'T001')");
$pdo->exec("UPDATE exemplares SET situacao = 'emprestado' WHERE codigo_individual = 'BDP-000001'");
$pdo->exec("INSERT INTO emprestimos (exemplar_id, aluno_id, data_prevista) VALUES ((SELECT id FROM exemplares WHERE codigo_individual = 'BDP-000001'), (SELECT id FROM alunos LIMIT 1), CURDATE() - INTERVAL 3 DAY)");
$v = $repo->vinculos(2);
confere('vínculos contam exemplares e empréstimos abertos', $v['exemplares_ativos'] === 1 && $v['emprestimos_abertos'] === 1);
$bloqueio = null;
try {
    $repo->arquivar(2);
} catch (RuntimeException $ex) {
    $bloqueio = $ex->getMessage();
}
confere('livro com empréstimo aberto não é arquivado', $bloqueio !== null && str_contains($bloqueio, 'empréstimo'));
$repo->arquivar(1);
confere('livro importado (sem exemplares) é arquivado, não excluído', $repo->buscar(1)['arquivado_em'] !== null && $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = 1') === 1);
confere('arquivado sai da listagem e aparece em "arquivados"', $repo->listar(['q' => 'corrigido'], 1, 5)['total'] === 0 && $repo->listar(['q' => 'corrigido', 'arquivados' => '1'], 1, 5)['total'] === 1);
$repo->restaurar(1);
confere('livro restaurado volta à listagem', $repo->listar(['q' => 'corrigido'], 1, 5)['total'] === 1);
$repo->arquivar($novo);
[$outro] = livros_validar(['titulo' => 'Dom Casmurro (outra edição)', 'isbn' => '9788535902778', 'status_validacao' => 'validado'], true);
$outroId = $repo->criar($outro);
$erroRest = null;
try {
    $repo->restaurar($novo);
} catch (RuntimeException $ex) {
    $erroRest = $ex->getMessage();
}
confere('restaurar com ISBN já usado por outro livro ativo é recusado', $erroRest !== null && str_contains($erroRest, 'ISBN'));
$pdo->exec('DELETE FROM livros WHERE id = ' . $outroId);

// ---------------------------------------------------------------
echo "\nContas de acesso\n";
confere('e-mail duplicado é detectado (sem diferenciar maiúsculas)', $admins->emailEmUso('ADMIN@teste.br'));
$ultimo = null;
try {
    $admins->atualizar($contas['administrador'], 'Ana Admin', 'admin@teste.br', 'bibliotecaria', 'Bibliotecária', true);
} catch (RuntimeException $ex) {
    $ultimo = $ex->getMessage();
}
confere('único administrador ativo não perde o perfil', $ultimo !== null && $admins->buscar($contas['administrador'])['perfil'] === 'administrador');
$ultimo = null;
try {
    $admins->atualizar($contas['administrador'], 'Ana Admin', 'admin@teste.br', 'administrador', 'Administrador', false);
} catch (RuntimeException $ex) {
    $ultimo = $ex->getMessage();
}
confere('único administrador ativo não pode ser desativado', $ultimo !== null && $admins->buscar($contas['administrador'])['ativo']);
$novoAdmin = $admins->criar('Edu Admin', 'Edu@Teste.br', 'administrador', 'Administrador', password_hash('Outra123456', PASSWORD_DEFAULT));
confere('e-mail gravado em minúsculas e senha só como hash', db_query('SELECT email FROM administradores WHERE id = ?', [$novoAdmin])->fetchColumn() === 'edu@teste.br'
    && str_starts_with((string)$admins->senhaHash($novoAdmin), '$2y$'));
$alt = $admins->atualizar($contas['administrador'], 'Ana Admin', 'admin@teste.br', 'administrador', 'Administrador', true);
confere('com outro administrador ativo, a proteção libera mudanças', !isset($alt['perfil']) && !isset($alt['ativo']));
$alt = $admins->atualizar($contas['administrador'], 'Ana Admin', 'admin@teste.br', 'bibliotecaria', 'Bibliotecária', true);
confere('…inclusive rebaixar quando há outro administrador', isset($alt['perfil']));
$admins->atualizar($contas['administrador'], 'Ana Admin', 'admin@teste.br', 'administrador', 'Administrador', true);
$erroEmail = null;
try {
    $admins->criar('Repetido', 'bib@teste.br', 'auxiliar', 'Auxiliar', password_hash('x', PASSWORD_DEFAULT));
} catch (RuntimeException $ex) {
    $erroEmail = $ex->getMessage();
}
confere('banco recusa e-mail repetido', $erroEmail !== null);
confere('senha curta recusada', auth_senha_problema('abc123', 'abc123') !== null);
confere('senha sem números recusada', auth_senha_problema('somenteletras', 'somenteletras') !== null);
confere('confirmação diferente recusada', auth_senha_problema('SenhaBoa12345', 'SenhaBoa12346') !== null);
confere('senha válida aceita', auth_senha_problema('SenhaBoa12345', 'SenhaBoa12345') === null);
confere('perfis: bibliotecária não gerencia contas; auxiliar não arquiva; consulta não edita',
    !in_array('usuarios.gerenciar', AUTH_PERMISSOES['bibliotecaria'], true) && in_array('livros.arquivar', AUTH_PERMISSOES['bibliotecaria'], true)
    && !in_array('livros.arquivar', AUTH_PERMISSOES['auxiliar'], true) && !in_array('livros.editar', AUTH_PERMISSOES['consulta'], true));

// ---------------------------------------------------------------
echo "\nPáginas (servidor PHP embutido no banco temporário)\n";
$portaHttp = 8790 + random_int(0, 150);
$env = array_merge(getenv(), ['DB_NAME' => $banco, 'DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/']);
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', sys_get_temp_dir() . "/$banco.log", 'a'], 2 => ['file', sys_get_temp_dir() . "/$banco.log", 'a']], $pipes, null, $env);
usleep(700000);
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
[$s, , $loc] = $anon->ir('livros.php');
confere('sem login, páginas redirecionam para o login', $s === 302 && str_contains($loc, 'login.php'));
[$s, , $loc] = $anon->ir('administradores.php');
confere('sem login, administradores redireciona para o login', $s === 302 && str_contains($loc, 'login.php'));
$x = new Navegador($base);
confere('senha errada não entra', $x->entrar('admin@teste.br', 'errada') === 200 && $x->ir('livros.php')[0] === 302);

$adm = new Navegador($base);
confere('login do administrador', $adm->entrar('admin@teste.br', $senhaPadrao) === 302);
[$s, $html] = $adm->ir('livros.php?q=coracao');
confere('listagem do acervo carrega e pesquisa sem acento', $s === 200 && str_contains($html, 'CORAÇÃO DE ESTUDANTE') && str_contains($html, '1 título encontrado'));
[$s, $html] = $adm->ir('livros.php?pagina=3&por_pagina=25');
confere('só a página atual vai para o navegador (25 linhas)', $s === 200 && substr_count($html, 'class="adm-book-link"') === 25 && !str_contains($html, '"livros":[{'));
[$s, $html] = $adm->ir('livros.php?q=' . rawurlencode('"><script>alert(1)</script>'));
confere('pesquisa com HTML é escapada (XSS)', $s === 200 && !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'));
[$s, $html] = $adm->ir('index.php');
confere('dashboard com números reais (3.003 títulos, 1 empréstimo atrasado)', $s === 200 && str_contains($html, '3.003') && str_contains($html, 'Empréstimos atrasados') && str_contains($html, 'Aluno Fictício'));

$token = $adm->csrf('cadastrar-livro.php');
[$s] = $adm->ir('cadastrar-livro.php', ['titulo' => 'Sem token', 'status_validacao' => 'validado']);
confere('cadastro sem token CSRF é recusado', $s === 419 && $num("SELECT COUNT(*) FROM livros WHERE titulo = 'Sem token'") === 0);
[$s, , $loc] = $adm->ir('cadastrar-livro.php', ['csrf' => $token, 'titulo' => '<img src=x onerror=alert(1)>', 'autores' => ['Autora Nova'], 'isbn' => '', 'status_validacao' => 'aguardando_conferencia', 'cdd' => '869.3', 'acao' => 'salvar']);
$idXss = $num("SELECT id FROM livros WHERE titulo = '<img src=x onerror=alert(1)>'");
confere('cadastro pelo formulário grava e redireciona', $s === 303 && $idXss > 0 && str_contains($loc, 'detalhes-livro.php?id=' . $idXss));
confere('CDD informada entra como sugestão (não aprovada)', db_query('SELECT status FROM classificacoes_cdd WHERE livro_id = ?', [$idXss])->fetchColumn() === 'sugerida');
confere('cadastro registrado no histórico de ações', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'livro.cadastrar' AND entidade_id = ? AND usuario_id = ?", [$idXss, $contas['administrador']]) === 1);
[, $html] = $adm->ir('detalhes-livro.php?id=' . $idXss);
confere('título com HTML é exibido escapado (XSS)', !str_contains($html, '<img src=x onerror') && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && str_contains($html, 'Livro cadastrado com sucesso'));
[$s, $html] = $adm->ir('cadastrar-livro.php', ['csrf' => $token, 'titulo' => 'Livro ISBN ruim', 'isbn' => '978-85-359-0277-1', 'status_validacao' => 'validado']);
confere('ISBN inválido pelo formulário: mensagem e nada gravado', $s === 200 && str_contains($html, 'dígito de controle') && $num("SELECT COUNT(*) FROM livros WHERE titulo = 'Livro ISBN ruim'") === 0);
[$s, $html] = $adm->ir('cadastrar-livro.php', ['csrf' => $token, 'titulo' => 'Pequeno príncipe', 'status_validacao' => 'validado']);
confere('título repetido pede confirmação antes de gravar', $s === 200 && str_contains($html, 'confirmar_duplicidade') && $num("SELECT COUNT(*) FROM livros WHERE titulo = 'Pequeno príncipe'") === 1);
[$s] = $adm->ir('cadastrar-livro.php', ['csrf' => $token, 'titulo' => 'Pequeno príncipe', 'status_validacao' => 'validado', 'confirmar_duplicidade' => '1']);
confere('com a confirmação, outra edição é gravada', $s === 303 && $num("SELECT COUNT(*) FROM livros WHERE titulo = 'Pequeno príncipe'") === 2);
$atual = $repo->buscar($idXss);
[$s] = $adm->ir('editar-livro.php?id=' . $idXss, ['csrf' => $token, 'versao' => $atual['atualizado_em'], 'titulo' => 'Título corrigido', 'autores' => ['Autora Nova'], 'status_validacao' => 'validado']);
confere('edição pelo formulário grava e registra os campos alterados', $s === 303 && $repo->buscar($idXss)['titulo'] === 'Título corrigido'
    && $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'livro.editar' AND entidade_id = ?", [$idXss]) === 1);
[$s, $html] = $adm->ir('arquivar-livro.php?id=2');
confere('tela de arquivamento mostra o bloqueio (empréstimo aberto)', $s === 200 && str_contains($html, 'não pode ser arquivado'));
[$s] = $adm->ir('arquivar-livro.php?id=' . $idXss, ['csrf' => $token, 'id' => $idXss, 'acao' => 'arquivar', 'motivo' => 'ok']);
confere('arquivar exige motivo', $s === 200 && $repo->buscar($idXss)['arquivado_em'] === null);
[$s] = $adm->ir('arquivar-livro.php?id=' . $idXss, ['csrf' => $token, 'id' => $idXss, 'acao' => 'arquivar', 'motivo' => 'Cadastro de teste', 'confirmar' => '1']);
confere('arquivar com motivo e confirmação', $s === 303 && $repo->buscar($idXss)['arquivado_em'] !== null
    && $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'livro.arquivar' AND entidade_id = ?", [$idXss]) === 1);

// Administradores pelo painel
$tk = $adm->csrf('administrador.php');
[$s] = $adm->ir('administrador.php', ['csrf' => $tk, 'acao' => 'salvar', 'nome' => 'Fê Auxiliar', 'email' => 'fe@teste.br', 'perfil' => 'auxiliar', 'senha' => 'curta', 'senha_confirmacao' => 'curta', 'ativo' => '1']);
confere('nova conta com senha fraca é recusada', $s === 200 && !$admins->emailEmUso('fe@teste.br'));
[$s] = $adm->ir('administrador.php', ['csrf' => $tk, 'acao' => 'salvar', 'nome' => 'Fê Auxiliar', 'email' => 'fe@teste.br', 'perfil' => 'auxiliar', 'senha' => 'SenhaDaFe2026', 'senha_confirmacao' => 'SenhaDaFe2026', 'ativo' => '1']);
$fe = $num("SELECT id FROM administradores WHERE email = 'fe@teste.br'");
confere('nova conta cadastrada pelo painel', $s === 303 && $fe > 0);
[$s, $html] = $adm->ir('administrador.php', ['csrf' => $tk, 'acao' => 'salvar', 'nome' => 'Outro', 'email' => 'BIB@teste.br', 'perfil' => 'auxiliar', 'senha' => 'SenhaDaFe2026', 'senha_confirmacao' => 'SenhaDaFe2026']);
confere('e-mail repetido recusado pelo formulário', $s === 200 && str_contains($html, 'Já existe uma conta com este e-mail'));
[$s] = $adm->ir('administrador.php?id=' . $contas['administrador'], ['csrf' => $tk, 'acao' => 'salvar', 'nome' => 'Ana Admin', 'email' => 'admin@teste.br', 'perfil' => 'consulta', 'ativo' => '0']);
confere('ninguém rebaixa nem desativa a própria conta', $admins->buscar($contas['administrador'])['perfil'] === 'administrador' && $admins->buscar($contas['administrador'])['ativo']);

$feNav = new Navegador($base);
confere('nova conta consegue entrar', $feNav->entrar('fe@teste.br', 'SenhaDaFe2026') === 302 && $feNav->ir('livros.php')[0] === 200);
$adm->ir('administrador.php?id=' . $fe, ['csrf' => $tk, 'acao' => 'senha', 'senha' => 'NovaSenhaFe2027', 'senha_confirmacao' => 'NovaSenhaFe2027']);
confere('senha redefinida encerra a sessão aberta com a senha antiga', $feNav->ir('livros.php')[0] === 302);
confere('senha redefinida não aparece no histórico', !str_contains((string)db_query("SELECT GROUP_CONCAT(COALESCE(detalhes, '')) FROM acoes_administrativas")->fetchColumn(), 'NovaSenhaFe2027'));
$adm->ir('administrador.php?id=' . $fe, ['csrf' => $tk, 'acao' => 'salvar', 'nome' => 'Fê Auxiliar', 'email' => 'fe@teste.br', 'perfil' => 'auxiliar', 'ativo' => '0']);
$feNav2 = new Navegador($base);
$feNav2->entrar('fe@teste.br', 'NovaSenhaFe2027');
confere('conta desativada não entra', $feNav2->ir('livros.php')[0] === 302);

// Restrições por perfil (no servidor)
$bib = new Navegador($base);
$bib->entrar('bib@teste.br', $senhaPadrao);
confere('bibliotecária: acesso ao acervo e ao cadastro', $bib->ir('livros.php')[0] === 200 && $bib->ir('cadastrar-livro.php')[0] === 200);
confere('bibliotecária: administradores bloqueado (403)', $bib->ir('administradores.php')[0] === 403 && $bib->ir('administrador.php?id=1')[0] === 403);
$tkb = $bib->csrf('livros.php');
[$s] = $bib->ir('administrador.php', ['csrf' => $tkb, 'acao' => 'salvar', 'nome' => 'Invasor', 'email' => 'inv@teste.br', 'perfil' => 'administrador', 'senha' => 'SenhaInvasor1', 'senha_confirmacao' => 'SenhaInvasor1']);
confere('bibliotecária não cria conta nem enviando o formulário direto', $s === 403 && !$admins->emailEmUso('inv@teste.br'));
[, $html] = $bib->ir('index.php');
confere('menu da bibliotecária não mostra "Administradores"', !str_contains($html, 'administradores.php'));
$aux = new Navegador($base);
$aux->entrar('aux@teste.br', $senhaPadrao);
confere('auxiliar: pode cadastrar, não pode arquivar (403)', $aux->ir('cadastrar-livro.php')[0] === 200 && $aux->ir('arquivar-livro.php?id=3')[0] === 403);
$cons = new Navegador($base);
$cons->entrar('cons@teste.br', $senhaPadrao);
confere('consulta: vê o acervo, não cadastra nem edita (403)', $cons->ir('livros.php')[0] === 200 && $cons->ir('cadastrar-livro.php')[0] === 403 && $cons->ir('editar-livro.php?id=3')[0] === 403);
[$s] = $adm->ir('logout.php', ['csrf' => $tk]);
confere('logout encerra a sessão', $s === 302 && $adm->ir('livros.php')[0] === 302);

echo "\n" . ($falhas ? "$falhas de $total testes falharam.\n" : "Todos os $total testes passaram.\n");
exit($falhas ? 1 : 0);
