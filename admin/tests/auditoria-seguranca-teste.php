<?php
/**
 * Auditoria de segurança do painel e do site público (Etapa 7 — revisão final).
 *
 * Uso: php admin/tests/auditoria-seguranca-teste.php
 *      TESTE_URL_APACHE=http://localhost/BibliotecaDomPedro2/ php admin/tests/auditoria-seguranca-teste.php
 *
 * Roda num banco temporário "bdp_teste_seguranca_XXXX" (apagado no final), com dados fictícios,
 * pelo servidor PHP embutido. Confere:
 *   1. acesso direto a TODAS as páginas do painel, por perfil (administrador, bibliotecária,
 *      auxiliar, consulta) e sem login — o servidor bloqueia, não só o menu;
 *   2. todos os formulários do painel reenviados à força pelo perfil consulta, por quem não entrou
 *      e sem token CSRF: nada pode ser gravado;
 *   3. sessão: cookie HttpOnly/SameSite (e Secure com HTTPS), fixação de sessão, logout que
 *      invalida o cookie antigo, tempo de inatividade, conta desativada e senha trocada;
 *   4. login: bloqueio por tentativas, mensagem genérica, redirecionamento só para o painel;
 *   5. parâmetros estranhos (arrays, SQL, scripts) sem erro técnico, sem SQL injection e sem XSS;
 *   6. site público sem dados de alunos, observações internas ou contas do painel.
 * Com TESTE_URL_APACHE (o projeto servido pelo Apache do XAMPP), também confere que .env, .git,
 * backups, logs, planilhas, código interno e uploads executáveis não podem ser baixados.
 * O banco do .env nunca é usado.
 */
require __DIR__ . '/suporte.php';

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LocalizacaoService;
use Biblioteca\Support\RegrasCirculacao;

$pastaSessoes = sys_get_temp_dir() . '/bdp-teste-sessoes-' . bin2hex(random_bytes(4));
mkdir($pastaSessoes, 0700);
register_shutdown_function(function () use ($pastaSessoes) {
    array_map('unlink', glob($pastaSessoes . '/*') ?: []);
    @rmdir($pastaSessoes);
});
[$raiz, $banco, $env] = teste_banco_temporario('seguranca');
$pdo = db();
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$senha = 'SenhaDeTeste123';
$emails = ['administrador' => 'admin@teste.br', 'bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'];
$contas = [];
foreach ($emails as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . $perfil, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia Extra', 'extra@teste.br', password_hash($senha, PASSWORD_DEFAULT), 'auxiliar']);
$contaExtra = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO alunos (nome, matricula, turma, email) VALUES ('Aluna Fictícia Sigilosa', 'FIC-SIG-01', '7A', 'aluna.sigilosa@escola.teste')");
$aluno = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO livros (titulo, origem_cadastro, observacoes_internas) VALUES ('Livro Fictício da Auditoria', 'manual', 'OBSERVACAO-INTERNA-FICTICIA')");
$livro = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO livros (titulo, origem_cadastro, arquivado_em) VALUES ('Livro Fictício Arquivado', 'manual', NOW())");
$livroArquivado = (int)$pdo->lastInsertId();
$st = $pdo->prepare('CALL gerar_exemplares(?, 3, NULL, ?, ?)');
$st->execute([$livro, 'bom', 'disponivel']);
$ex = array_map('intval', array_column($st->fetchAll(), 'id'));
$st->closeCursor();
$loc = (new LocalizacaoService($pdo))->salvar(['setor' => 'Literatura', 'estante' => 'E1'], $contas['administrador']);
$emp = (new EmprestimoService($pdo, RegrasCirculacao::doAmbiente('config_valor')))->registrar($aluno, $ex[0], date('Y-m-d', strtotime('+7 days')), $contas['administrador'])['id'];
$inv = (new InventarioService($pdo, new ExemplarService($pdo), new InventarioRepository($pdo)))->criar(['titulo' => 'Inventário fictício', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $contas['administrador']], $contas['administrador']);

$base = teste_servidor($env + ['PHP_INI_SCAN_DIR' => '']);
// Segundo servidor com sessões numa pasta conhecida (para simular inatividade) e APP_HTTPS=true.
$portaS = 9950 + random_int(0, 40);
$logS = sys_get_temp_dir() . "/bdp-teste-servidor-$portaS.log";
$GLOBALS['teste_temporarios'][] = $logS;
$GLOBALS['teste_servidores'][] = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $pastaSessoes, '-S', "127.0.0.1:$portaS", '-t', realpath(__DIR__ . '/../..')],
    [1 => ['file', $logS, 'a'], 2 => ['file', $logS, 'a']], $pipes, null, array_merge(getenv(), $env, ['APP_HTTPS' => 'true']));
usleep(800000);
$baseS = "http://127.0.0.1:$portaS/";

$navs = ['anonimo' => new TesteNavegador($base)];
foreach ($emails as $perfil => $email) {
    $navs[$perfil] = new TesteNavegador($base);
    $navs[$perfil]->entrar($email, $senha);
}

// ---------------------------------------------------------------
echo "\n1. Acesso direto às páginas do painel, por perfil\n";
// Esperado: [anônimo, administrador, bibliotecária, auxiliar, consulta]. 302 = vai para o login.
$L = 302;
$todos = [$L, 200, 200, 200, 200];
$equipe = [$L, 200, 200, 200, 403];          // circulação/dados de alunos e cadastro: não para "consulta"
$gestao = [$L, 200, 200, 403, 403];          // bibliotecária e administrador
$soAdmin = [$L, 200, 403, 403, 403];
$esperado = [
    'index.php' => $todos, 'livros.php' => $todos, 'detalhes-livro.php?id=' . $livro => $todos, 'categorias.php' => $todos,
    'exemplares.php' => $todos, 'detalhes-exemplar.php?id=' . $ex[1] => $todos, 'localizacoes.php' => $todos,
    'inventario.php' => $todos, 'inventario-detalhes.php?id=' . $inv => $todos, 'inventario-relatorio.php?id=' . $inv => $todos,
    'classificacao.php' => $todos, 'revisao-cdd.php' => $todos, 'historico-cdd.php' => $todos, 'classificar-livro.php?id=' . $livro => $todos,
    'relatorio-cdd.php' => $todos, 'importacao.php' => $todos, 'historico-importacoes.php' => $todos, 'relatorios.php' => $todos,
    'perfil.php' => $todos, 'permissoes.php' => $todos, 'em-desenvolvimento.php' => $todos,
    'cadastrar-livro.php' => $equipe, 'editar-livro.php?id=' . $livro => $equipe, 'capa-livro.php?id=' . $livro => $equipe,
    'cadastrar-exemplar.php?livro=' . $livro => $equipe, 'etiquetas.php' => $equipe, 'inventario-conferencia.php?id=' . $inv => $equipe,
    'emprestimos.php' => $equipe, 'emprestimo.php?id=' . $emp => $equipe, 'novo-emprestimo.php' => $equipe, 'devolucoes.php' => $equipe,
    'devolver-emprestimo.php?id=' . $emp => $equipe, 'renovar-emprestimo.php?id=' . $emp => $equipe, 'atrasos.php' => $equipe,
    'historico-aluno.php?id=' . $aluno => $equipe, 'historico-exemplar.php?id=' . $ex[0] => $equipe,
    'arquivar-livro.php?id=' . $livro => $gestao, 'etiqueta-modelos.php' => $gestao, 'inventario-editar.php?id=' . $inv => $gestao,
    'localizacao.php?id=' . $loc => $gestao, 'importar-alunos.php' => $gestao, 'configuracoes.php' => $gestao, 'site-publico.php' => $gestao,
    'administradores.php' => $soAdmin, 'administrador.php?id=' . $contas['auxiliar'] => $soAdmin, 'manutencao.php' => $soAdmin,
];
$paginasExistentes = array_map('basename', glob(__DIR__ . '/../*.php'));
$cobertas = array_unique(array_map(fn ($p) => strtok($p, '?'), array_keys($esperado)));
$semRegra = array_diff($paginasExistentes, $cobertas, ['login.php', 'logout.php', 'detalhes-importacao.php', 'isbn-invalidos.php']);
confere('toda página do painel tem uma regra de acesso conferida aqui', !$semRegra, implode(', ', $semRegra));
$perfis = array_keys($navs);
foreach ($esperado as $pagina => $codigos) {
    $obtido = [];
    $vazamento = false;
    foreach ($perfis as $i => $perfil) {
        [$s, $html, $local] = $navs[$perfil]->ir('admin/' . $pagina);
        $obtido[] = $s;
        if ($s === 302 && !str_contains($local, 'login.php')) {
            $obtido[$i] = "302→$local";
        }
        if (!teste_sem_erro_tecnico($html) && !str_contains($html, 'mysql.exe')) {
            $obtido[$i] .= '!erro';
        }
        if ($s !== 200 && (str_contains($html, 'Aluna Fictícia Sigilosa') || str_contains($html, 'FIC-SIG-01'))) {
            $vazamento = true;
        }
    }
    confere("$pagina → " . implode('/', $codigos), $obtido === $codigos && !$vazamento, 'obtido ' . implode('/', $obtido));
}
foreach (['detalhes-importacao.php?id=999', 'isbn-invalidos.php?id=999'] as $p) {
    [$s] = $navs['anonimo']->ir('admin/' . $p);
    [$s2] = $navs['consulta']->ir('admin/' . $p);
    confere("$p: sem login vai para o login; importação inexistente dá 404", $s === 302 && $s2 === 404);
}
foreach (['api/cdd.php?acao=fila', 'api/importacoes/validar.php?acao=buscar_livros&q=a', 'api/importacoes/relatorio.php?tipo=completo&id=1', 'api/alunos/importar.php?acao=modelo'] as $api) {
    [$s, $corpo] = $navs['anonimo']->ir('admin/' . $api);
    confere("API sem login recusada: $api", in_array($s, [401, 403, 405], true) && !str_contains($corpo, 'Livro Fictício'), "status $s");
}
[$s] = $navs['auxiliar']->ir('admin/api/alunos/importar.php?acao=modelo');
confere('API de alunos: auxiliar recebe 403', $s === 403);
[$s, $corpo] = $navs['consulta']->ir('admin/relatorios.php?secao=alunos');
confere('relatório nominal de alunos bloqueado para consulta', $s === 403 && !str_contains($corpo, 'Aluna Fictícia Sigilosa'));
[$s, $corpo, , , $disp] = $navs['auxiliar']->ir('admin/relatorios.php?secao=atrasos&formato=csv&tabela=atrasos');
confere('exportação com nomes de alunos bloqueada sem relatorios.exportar_pessoais', !str_contains($corpo, 'Aluna Fictícia Sigilosa'));
[$s, $corpo] = $navs['bibliotecaria']->ir('admin/manutencao.php', ['csrf' => $navs['bibliotecaria']->csrf('admin/perfil.php'), 'acao' => 'backup', 'ciente' => '1']);
confere('cópia de segurança: bibliotecária não baixa (403, sem SQL na resposta)', $s === 403 && !str_contains($corpo, 'INSERT INTO'));

// ---------------------------------------------------------------
echo "\n2. Formulários reenviados à força (sem permissão, sem login, sem CSRF)\n";
function teste_formularios(string $html, string $origem): array
{
    $out = [];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xp = new DOMXPath($dom);
    foreach ($dom->getElementsByTagName('form') as $f) {
        if (strtolower($f->getAttribute('method')) !== 'post') {
            continue;
        }
        $acao = ltrim(html_entity_decode($f->getAttribute('action') ?: '/admin/' . $origem), '/');
        if (str_contains($acao, 'logout')) {
            continue;
        }
        $campos = [];
        foreach ($xp->query('.//input|.//select|.//textarea|.//button[@name]', $f) as $el) {
            $n = $el->getAttribute('name');
            $t = strtolower($el->getAttribute('type'));
            if ($n === '' || $t === 'file') {
                continue;
            }
            if ($el->nodeName === 'select') {
                $o = $xp->query('.//option', $el);
                $v = ($o->item(1) ?? $o->item(0))?->getAttribute('value') ?? '';
            } elseif ($el->nodeName === 'textarea') {
                $v = 'Teste da auditoria';
            } elseif (in_array($t, ['checkbox', 'radio'], true)) {
                $v = $el->getAttribute('value') ?: '1';
            } else {
                $v = $el->getAttribute('value');
                $v = $v !== '' ? $v : match ($t) { 'number' => '1', 'date' => date('Y-m-d', strtotime('+3 days')), 'hidden' => '', default => 'Teste da auditoria' };
            }
            if (str_ends_with($n, '[]')) {
                $campos[substr($n, 0, -2)][] = $v;
            } elseif (!isset($campos[$n]) || $el->nodeName === 'button') {
                $campos[$n] = $v;
            }
        }
        $out[] = [$acao, $campos];
    }
    return $out;
}
$formularios = [];
foreach (array_keys($esperado) as $pagina) {
    if (!str_starts_with($pagina, 'perfil.php')) {
        $formularios = array_merge($formularios, teste_formularios($navs['administrador']->ir('admin/' . $pagina)[1], $pagina));
    }
}
$formularios = array_values(array_unique($formularios, SORT_REGULAR));
confere('formulários POST encontrados no painel: ' . count($formularios), count($formularios) >= 20);
$tabelas = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name NOT IN ('login_tentativas', 'acoes_administrativas')")->fetchAll(PDO::FETCH_COLUMN);
$retrato = function () use ($pdo, $tabelas): array {
    $r = [];
    foreach ($tabelas as $t) {
        $r[$t] = $pdo->query("CHECKSUM TABLE `$t`")->fetch()['Checksum'];
    }
    return $r;
};
$tokenCons = $navs['consulta']->csrf('admin/perfil.php');
$tokenBib = $navs['bibliotecaria']->csrf('admin/perfil.php');
$falhasForm = ['consulta' => [], 'anonimo' => [], 'sem_csrf' => []];
foreach ($formularios as [$acao, $campos]) {
    foreach (['consulta' => [$navs['consulta'], $tokenCons], 'anonimo' => [$navs['anonimo'], ''], 'sem_csrf' => [$navs['bibliotecaria'], 'token-falso']] as $quem => [$nav, $tok]) {
        $antes = $retrato();
        [$s, $html] = $nav->ir($acao, ['csrf' => $tok] + $campos);
        if ($retrato() !== $antes || !in_array($s, [302, 403, 419], true) || !teste_sem_erro_tecnico($html)) {
            $falhasForm[$quem][] = "$acao [" . ($campos['acao'] ?? '') . "] → $s";
        }
    }
}
confere('perfil consulta: nenhum formulário grava nada (403)', !$falhasForm['consulta'], implode('; ', $falhasForm['consulta']));
confere('sem login: nenhum formulário grava nada (vai para o login)', !$falhasForm['anonimo'], implode('; ', $falhasForm['anonimo']));
confere('sem token CSRF válido: nenhum formulário grava nada (419)', !$falhasForm['sem_csrf'], implode('; ', $falhasForm['sem_csrf']));
// Controle positivo: o mesmo mecanismo, com token válido e perfil autorizado, grava.
$antes = $retrato();
$navs['bibliotecaria']->ir('admin/localizacao.php', ['csrf' => $tokenBib, 'setor' => 'Setor Controle Positivo']);
confere('controle: com token válido e permissão, o formulário grava', $retrato() !== $antes && $num("SELECT COUNT(*) FROM localizacoes WHERE setor = 'Setor Controle Positivo'") === 1);
[$s] = $navs['bibliotecaria']->ir('admin/api/cdd.php', ['acao' => 'sugerir', 'livro_id' => (string)$livro, 'cdd' => '869.3', 'fonte_nome' => 'Teste'], ['X-CSRF-Token' => 'token-falso']);
confere('API: token CSRF falso no cabeçalho é recusado', $s === 419 || $s === 403, "status $s");

// ---------------------------------------------------------------
echo "\n3. Sessão\n";
$n = new TesteNavegador($baseS);
$n->cookies['BDPADMIN'] = 'idfixadopeloatacante0123456789';
$n->ir('admin/login.php');
$idAntes = $n->cookies['BDPADMIN'] ?? '';
confere('identificador de sessão inventado é recusado (modo estrito)', $idAntes !== '' && $idAntes !== 'idfixadopeloatacante0123456789');
$cookieLinha = implode("\n", array_filter($n->setCookies, fn ($c) => str_contains($c, 'BDPADMIN')));
confere('cookie da sessão com HttpOnly, SameSite=Lax e Secure (APP_HTTPS=true)', stripos($cookieLinha, 'httponly') !== false && stripos($cookieLinha, 'samesite=lax') !== false && stripos($cookieLinha, 'secure') !== false, $cookieLinha);
$n->entrar($emails['bibliotecaria'], $senha);
$idDepois = $n->cookies['BDPADMIN'] ?? '';
confere('novo identificador de sessão depois do login (contra fixação)', $idDepois !== '' && $idDepois !== $idAntes);
[, , , , , $cab] = $n->ir('admin/index.php');
confere('HTTPS em produção: cabeçalho HSTS enviado', (bool)preg_grep('/^Strict-Transport-Security: max-age=\d+/i', $cab));
[, , , , , $cab] = $navs['anonimo']->ir('admin/login.php');
confere('tela de login: não pode ser embutida (X-Frame-Options) nem guardada em cache', (bool)preg_grep('/^X-Frame-Options: DENY/i', $cab) && (bool)preg_grep('/^Cache-Control: no-store/i', $cab));
confere('sem HTTPS: cookie sem Secure e sem HSTS (XAMPP local continua funcionando)', !preg_grep('/^Strict-Transport-Security/i', $cab)
    && !preg_grep('/BDPADMIN=.*;\s*secure/i', $navs['anonimo']->setCookies));

// Inatividade: a sessão "parada" há mais tempo que o limite exige novo login.
$arquivoSessao = $pastaSessoes . '/sess_' . $idDepois;
$conteudo = (string)@file_get_contents($arquivoSessao);
$alterado = preg_replace('/s:5:"visto";i:\d+;/', 's:5:"visto";i:' . (time() - 3 * 3600) . ';', $conteudo, 1, $trocas);
file_put_contents($arquivoSessao, $alterado);
[$s, , $local] = $n->ir('admin/index.php');
confere('sessão parada há 3 horas (limite padrão de 120 min) volta para o login', $trocas === 1 && $s === 302 && str_contains($local, 'login.php'));

// Logout invalida o cookie antigo.
$n2 = new TesteNavegador($base);
$n2->entrar($emails['auxiliar'], $senha);
$cookieAntigo = $n2->cookies;
[$s] = $n2->ir('admin/logout.php');
confere('GET em logout.php não encerra a sessão (só POST com token)', $n2->ir('admin/index.php')[0] === 200);
$n2->sair();
$copia = new TesteNavegador($base);
$copia->cookies = $cookieAntigo;
[$s, , $local] = $copia->ir('admin/index.php');
confere('depois do logout, o cookie antigo não abre mais o painel', $s === 302 && str_contains($local, 'login.php'));
[, , , , , $cab] = $navs['bibliotecaria']->ir('admin/emprestimos.php');
confere('cabeçalho Cache-Control: no-store nas páginas com dados pessoais', (bool)preg_grep('/^Cache-Control: no-store/i', $cab));

// Conta desativada e senha trocada.
$n3 = new TesteNavegador($base);
$n3->entrar('extra@teste.br', $senha);
confere('conta extra entrou', $n3->ir('admin/index.php')[0] === 200);
$pdo->prepare('UPDATE administradores SET ativo = 0 WHERE id = ?')->execute([$contaExtra]);
confere('conta desativada perde o acesso na hora', $n3->ir('admin/index.php')[0] === 302);
$pdo->prepare('UPDATE administradores SET ativo = 1 WHERE id = ?')->execute([$contaExtra]);
$n3->entrar('extra@teste.br', $senha);
$pdo->prepare('UPDATE administradores SET senha_hash = ? WHERE id = ?')->execute([password_hash('OutraSenha12345', PASSWORD_DEFAULT), $contaExtra]);
confere('senha redefinida encerra as sessões abertas com a senha antiga', $n3->ir('admin/index.php')[0] === 302);
confere('senhas guardadas com password_hash (bcrypt/argon), nunca em texto', $num("SELECT COUNT(*) FROM administradores WHERE senha_hash NOT LIKE '$2y$%' AND senha_hash NOT LIKE '\$argon2%'") === 0);

// ---------------------------------------------------------------
echo "\n4. Login\n";
$n4 = new TesteNavegador($base);
$t = $n4->csrf('admin/login.php');
[, $html] = $n4->ir('admin/login.php', ['csrf' => $t, 'email' => 'naoexiste@teste.br', 'senha' => 'qualquer']);
[, $html2] = $n4->ir('admin/login.php', ['csrf' => $t, 'email' => $emails['consulta'], 'senha' => 'errada']);
confere('mensagem igual para e-mail inexistente e senha errada', str_contains($html, 'E-mail ou senha incorretos.') && str_contains($html2, 'E-mail ou senha incorretos.'));
[$s, $html] = $n4->ir('admin/login.php', ['email' => $emails['consulta'], 'senha' => $senha]);
confere('login sem token CSRF é recusado', $s === 200 && $n4->ir('admin/index.php')[0] === 302);
$n5 = new TesteNavegador($base);
$t = $n5->csrf('admin/login.php');
for ($i = 0; $i < 5; $i++) {
    $n5->ir('admin/login.php', ['csrf' => $t, 'email' => 'extra@teste.br', 'senha' => 'senha-errada-' . $i]);
}
[, $html] = $n5->ir('admin/login.php', ['csrf' => $t, 'email' => 'extra@teste.br', 'senha' => 'OutraSenha12345']);
confere('5 senhas erradas: a 6ª tentativa (mesmo correta) fica bloqueada por 15 minutos', str_contains($html, 'Muitas tentativas') && $n5->ir('admin/index.php')[0] === 302);
$pdo->exec("DELETE FROM login_tentativas");
foreach (['https://site-malicioso.exemplo/', '//site-malicioso.exemplo/', '/\\site-malicioso.exemplo', '/biblioteca_php/catalogo.php'] as $voltar) {
    $n6 = new TesteNavegador($base);
    $t = $n6->csrf('admin/login.php');
    [$s, , $local] = $n6->ir('admin/login.php', ['csrf' => $t, 'email' => $emails['consulta'], 'senha' => $senha, 'voltar' => $voltar]);
    confere("depois do login, \"voltar=$voltar\" não sai do painel", $s === 302 && str_starts_with($local, '/admin/'), $local);
}

// Conta demonstrativa (Bibliotecaria@demo / demo123): só sem MySQL interligado e fora de produção.
$nDemo = new TesteNavegador($base);
confere('com o MySQL conectado, Bibliotecaria@demo / demo123 não entra', $nDemo->entrar('Bibliotecaria@demo', 'demo123') === 302 && !str_contains($nDemo->ir('admin/login.php')[1], 'Modo demonstrativo'));
// MySQL "desligado": mesmo .env, mas numa porta onde nada responde.
$semMysql = ['DB_PORT' => '1', 'PHP_INI_SCAN_DIR' => ''] + $env;
$baseSem = teste_servidor(['APP_ENV' => 'development'] + $semMysql);
$nDemo = new TesteNavegador($baseSem);
[, $htmlLogin] = $nDemo->ir('admin/login.php');
confere('sem conexão com o MySQL, a tela de login mostra o acesso demonstrativo', str_contains($htmlLogin, 'sem conexão com o MySQL') && str_contains($htmlLogin, 'Bibliotecaria@demo') && str_contains($htmlLogin, 'demo123'));
confere('sem conexão com o MySQL, Bibliotecaria@demo / demo123 entra no painel', $nDemo->entrar('Bibliotecaria@demo', 'demo123') === 200);
[$s, $html] = $nDemo->ir('admin/livros.php');
confere('sem conexão com o MySQL, o painel abre com dados fictícios e sem erro técnico', $s === 200 && teste_sem_erro_tecnico($html));
$nDemo = new TesteNavegador($baseSem);
confere('sem conexão com o MySQL, senha errada continua recusada', $nDemo->entrar('Bibliotecaria@demo', 'demo') === 302);
$baseProd = teste_servidor(['APP_ENV' => 'production'] + $semMysql);
$nDemo = new TesteNavegador($baseProd);
confere('em produção, sem MySQL, a conta demonstrativa fica desligada', $nDemo->entrar('Bibliotecaria@demo', 'demo123') === 302 && !str_contains($nDemo->ir('admin/login.php')[1], 'demo123'));

// ---------------------------------------------------------------
echo "\n5. Parâmetros estranhos: sem erro técnico, sem SQL injection, sem XSS\n";
$sujos = ['id[]=1&q[]=x&pagina[]=2', "id=1'%20OR%20'1'='1&q=%27%3B%20DROP%20TABLE%20livros%3B--&ordem=titulo%3BDROP", 'id=-1&pagina=-5&por_pagina=100000&q=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
    'id=99999999999999999999&de=2026-99-99&ate=abc&secao=../../.env&formato=../../etc'];
$errosTecnicos = [];
foreach (array_merge(array_keys($esperado), ['../biblioteca_php/catalogo.php', '../biblioteca_php/livro.php', '../biblioteca_php/autores.php', '../index.php']) as $pagina) {
    foreach ($sujos as $q) {
        $url = 'admin/' . strtok($pagina, '?') . '?' . $q;
        [$s, $html] = $navs['administrador']->ir($url);
        if ($s >= 500 || !teste_sem_erro_tecnico($html) || str_contains($html, '<script>alert(1)</script>')) {
            if (!str_contains($html, 'mysql.exe')) {
                $errosTecnicos[] = "$url → $s";
            }
        }
    }
}
confere('nenhuma página quebra, mostra erro técnico ou reflete script com parâmetros estranhos', !$errosTecnicos, implode('; ', array_slice($errosTecnicos, 0, 8)));
confere('tabelas intactas depois das tentativas de SQL injection', $num('SELECT COUNT(*) FROM livros') === 2 && $num('SELECT COUNT(*) FROM alunos') === 1);
[, $html] = $navs['anonimo']->ir('biblioteca_php/catalogo.php?q=' . rawurlencode("' OR 1=1 -- "));
confere('catálogo: "\' OR 1=1" é texto pesquisado, não SQL (nenhum livro encontrado)', !str_contains($html, 'Livro Fictício da Auditoria'));

// ---------------------------------------------------------------
echo "\n6. Site público sem dados pessoais nem administrativos\n";
$publico = '';
foreach (['', 'biblioteca_php/catalogo.php', 'biblioteca_php/catalogo.php?q=livro', 'biblioteca_php/livro.php?id=' . $livro, 'biblioteca_php/autores.php', 'biblioteca_php/generos.php', 'biblioteca_php/sobre.php', 'biblioteca_php/regras.php'] as $p) {
    [$s, $html, , , , $cab] = $navs['anonimo']->ir($p);
    $publico .= $html;
    confere("público $p: 200, com CSP e sem erro técnico", $s === 200 && (bool)preg_grep('/^Content-Security-Policy:/i', $cab) && teste_sem_erro_tecnico($html));
}
confere('nenhum nome, matrícula ou e-mail de aluno no site público', !preg_match('/Aluna Fictícia Sigilosa|FIC-SIG-01|aluna\.sigilosa/', $publico));
confere('nenhuma observação interna nem e-mail de conta do painel no site público', !str_contains($publico, 'OBSERVACAO-INTERNA-FICTICIA') && !preg_match('/(admin|bib|aux|cons)@teste\.br/', $publico));
[$s] = $navs['anonimo']->ir('biblioteca_php/livro.php?id=' . $livroArquivado);
confere('livro arquivado não aparece no site público (404)', $s === 404 && !str_contains($publico, 'Livro Fictício Arquivado'));
confere('site público não carrega nada de servidores externos', !preg_match('#<(script|link|img)[^>]+(src|href)="https?://#i', $publico));
[, $painel] = $navs['administrador']->ir('admin/index.php');
confere('painel também não carrega scripts nem fontes de servidores externos', !preg_match('#<(script|link)[^>]+(src|href)="https?://#i', $painel . $navs['anonimo']->ir('admin/login.php')[1]));

// ---------------------------------------------------------------
$apache = getenv('TESTE_URL_APACHE');
if ($apache) {
    echo "\n7. Arquivos privados no Apache ($apache)\n";
    $apache = rtrim($apache, '/') . '/';
    $criados = [];
    $criar = function (string $rel, string $conteudo) use (&$criados) {
        $arq = APP_RAIZ . '/' . $rel;
        if (!is_dir(dirname($arq))) {
            mkdir(dirname($arq), 0755, true);
            $criados[] = dirname($arq);
        }
        if (!is_file($arq)) {
            file_put_contents($arq, $conteudo);
            chmod($arq, 0644);
            $criados[] = $arq;
        }
    };
    $criar('backups/teste-auditoria.sql', '-- backup fictício');
    $criar('storage/logs/teste-auditoria.log', 'log fictício');
    $criar('storage/importacoes/teste-auditoria.xlsx.upload', 'planilha fictícia');
    $criar('uploads/capas/teste-auditoria.php', '<?php echo "EXECUTOU";');
    $criar('uploads/capas/teste-auditoria.html', '<script>alert(1)</script>');
    $criar('alunos-teste-auditoria.csv', 'nome;matricula');
    foreach (['.env', '.env.example', '.git/HEAD', '.git/config', '.gitignore', '.htaccess', 'composer.json', 'composer.lock', 'vendor/autoload.php',
                 'backups/teste-auditoria.sql', 'storage/logs/teste-auditoria.log', 'storage/importacoes/teste-auditoria.xlsx.upload', 'storage/importacoes/',
                 'config/app.php', 'config/database.php', 'database/migrar.php', 'database/migrations/001_acervo.sql', 'database/seeds/dados/acervo-catalogar.csv',
                 'docs/manual-bibliotecaria.md', 'README.md', 'src/Services/BackupService.php', 'includes/layout.php', 'admin/includes/auth.php',
                 'admin/tests/suporte.php', 'admin/tests/', 'uploads/capas/', 'uploads/capas/teste-auditoria.php', 'uploads/capas/teste-auditoria.html',
                 'alunos-teste-auditoria.csv', 'admin/assets/lib/chartjs/LICENSE.md'] as $p) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $corpo = (string)@file_get_contents($apache . $p, false, $ctx);
        preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
        $s = (int)($m[1] ?? 0);
        confere("Apache bloqueia $p ($s)", in_array($s, [403, 404], true) && !str_contains($corpo, 'EXECUTOU'));
    }
    foreach (['', 'biblioteca_php/catalogo.php', 'admin/login.php', 'assets/css/style.css', 'admin/assets/lib/chartjs/chart-4.4.1.umd.min.js'] as $p) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        @file_get_contents($apache . $p, false, $ctx);
        preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
        confere("Apache serve normalmente $p", (int)($m[1] ?? 0) === 200);
    }
    foreach (array_reverse($criados) as $c) {
        is_dir($c) ? @rmdir($c) : @unlink($c);
    }
} else {
    echo "\n7. Arquivos privados no Apache: não testado (defina TESTE_URL_APACHE; o servidor PHP embutido não lê .htaccess).\n";
}

teste_resultado();
