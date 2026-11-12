<?php
/**
 * Testes da Central de Ajuda (Etapa 10): conteúdo, pesquisa, links, permissões e páginas.
 *
 * Uso: php admin/tests/ajuda-teste.php
 *
 * 1. Conteúdo (sem banco): 16 módulos, ids únicos, seções A–J, tutoriais relacionados e links
 *    internos existentes, páginas do painel existentes, imagens com texto alternativo e, para cada
 *    tutorial, os nomes de campos, botões e mensagens citados (campo 'confere') encontrados no
 *    código-fonte das páginas — assim a documentação não descola do sistema.
 * 2. Pesquisa local com e sem acentos, singular/plural e termos obrigatórios.
 * 3. Endereço de retorno ("voltar"): só páginas conhecidas do painel; nada de redirecionamento aberto.
 * 4. Páginas (banco temporário + servidor PHP embutido): login obrigatório, os 4 perfis leem a
 *    ajuda, nenhuma permissão é concedida pela leitura, botão contextual nas páginas, todos os
 *    tutoriais abrem sem erro técnico, sem recursos externos (funciona offline) e sem alterar dados.
 *
 * O banco do .env nunca é usado: as páginas rodam num banco temporário apagado no final.
 */
require __DIR__ . '/suporte.php';
require_once __DIR__ . '/../includes/ajuda.php';

$admin = realpath(__DIR__ . '/..');

// ---------------------------------------------------------------
echo "\n1. Conteúdo dos tutoriais\n";
confere('PHP 8.2 ou mais novo', PHP_VERSION_ID >= 80200, PHP_VERSION);
$modulos = ajuda_modulos();
$tutoriais = ajuda_tutoriais();
confere('16 módulos', count($modulos) === 16, (string)count($modulos));
$semTutorial = array_keys(array_filter($modulos, fn ($m) => empty($m['tutoriais'])));
confere('todo módulo tem tutoriais', !$semTutorial, implode(', ', $semTutorial));
$semCampos = array_keys(array_filter($modulos, fn ($m) => empty($m['nome']) || empty($m['descricao']) || empty($m['icone'])));
confere('todo módulo tem nome, descrição e ícone', !$semCampos, implode(', ', $semCampos));
$iconesFaltando = array_keys(array_filter($modulos, fn ($m) => !isset(ADMIN_ICONS[$m['icone']])));
confere('os ícones dos módulos existem', !$iconesFaltando, implode(', ', $iconesFaltando));

$ids = [];
foreach ($modulos as $m) {
    foreach ($m['tutoriais'] as $t) {
        $ids[] = $t['id'] ?? '';
    }
}
$repetidos = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));
confere('ids únicos (' . count($ids) . ' tutoriais)', !$repetidos, implode(', ', $repetidos));
confere('ids só com letras minúsculas, números e hífen', !array_filter($ids, fn ($i) => !preg_match('/^[a-z0-9-]+$/', $i)));

$obrigatorios = ['titulo', 'resumo', 'objetivo', 'quando', 'requisitos', 'passos', 'exemplo', 'resultado', 'problemas', 'cuidados', 'relacionados'];
$incompletos = [];
foreach ($tutoriais as $id => $t) {
    foreach ($obrigatorios as $c) {
        if (empty($t[$c])) {
            $incompletos[] = "$id ($c)";
        }
    }
    foreach ($t['problemas'] ?? [] as $p) {
        if (count($p) !== 3 || in_array('', $p, true)) {
            $incompletos[] = "$id (problema sem causa ou solução)";
        }
    }
}
confere('todo tutorial tem as seções A a J (título, objetivo, quando, pré-requisitos, passos, exemplo, resultado, problemas, cuidados, próximos passos)', !$incompletos, implode('; ', array_slice($incompletos, 0, 8)));

$relFaltando = [];
foreach ($tutoriais as $id => $t) {
    foreach ($t['relacionados'] ?? [] as $r) {
        if (!isset($tutoriais[$r]) || $r === $id) {
            $relFaltando[] = "$id → $r";
        }
    }
}
confere('tutoriais relacionados existem', !$relFaltando, implode('; ', $relFaltando));

// Todos os textos de um tutorial, para conferir os links internos.
$textos = function (array $t): array {
    $r = [];
    array_walk_recursive($t, function ($v, $k) use (&$r) {
        if (is_string($v) && !in_array($k, ['id', 'arquivo'], true)) {
            $r[] = $v;
        }
    });
    return $r;
};
$linkFaltando = [];
foreach ($tutoriais as $id => $t) {
    foreach ($textos($t) as $texto) {
        preg_match_all('/\[\[(pagina|tutorial):([a-z0-9\-]+)\|[^\]]+\]\]/u', $texto, $mm, PREG_SET_ORDER);
        foreach ($mm as [, $tipo, $alvo]) {
            if ($tipo === 'tutorial' ? !isset($tutoriais[$alvo]) : !admin_menu_item($alvo)) {
                $linkFaltando[] = "$id → $tipo:$alvo";
            }
        }
        if (preg_match('/\[\[(?!(pagina|tutorial):[a-z0-9\-]+\|[^\]]+\]\])/u', $texto)) {
            $linkFaltando[] = "$id (marcação de link malformada)";
        }
    }
}
confere('links internos ([[tutorial:…]] e [[pagina:…]]) apontam para tutoriais e páginas existentes', !$linkFaltando, implode('; ', $linkFaltando));

$paginasFaltando = [];
$paginasMapeadas = [];
foreach ($tutoriais as $id => $t) {
    foreach ($t['paginas'] ?? [] as $p) {
        $paginasMapeadas[$p][] = $id;
        if (!is_file("$admin/$p")) {
            $paginasFaltando[] = "$id → $p";
        }
    }
}
confere('páginas da ajuda contextual existem no painel', !$paginasFaltando, implode('; ', $paginasFaltando));
$duplas = array_filter($paginasMapeadas, fn ($l) => count($l) > 1);
confere('cada página do painel aponta para um único tutorial principal', !$duplas, implode('; ', array_map(fn ($p, $l) => "$p: " . implode(', ', $l), array_keys($duplas), $duplas)));

$descricoes = array_merge(...array_values(AUTH_PERMISSOES_DESCRICOES));
$permFaltando = array_keys(array_filter($tutoriais, fn ($t) => isset($t['permissao']) && !isset($descricoes[$t['permissao']])));
confere('permissões citadas existem', !$permFaltando, implode(', ', $permFaltando));

$imgProblemas = [];
$imagens = 0;
foreach ($tutoriais as $id => $t) {
    foreach ($t['passos'] as $p) {
        if (is_array($p) && isset($p['imagem'])) {
            $imagens++;
            $arq = "$admin/" . AJUDA_IMAGENS . ($p['imagem']['arquivo'] ?? '');
            if (!is_file($arq)) {
                $imgProblemas[] = "$id: falta " . ($p['imagem']['arquivo'] ?? '?');
            } elseif (filesize($arq) > 400 * 1024) {
                $imgProblemas[] = "$id: " . $p['imagem']['arquivo'] . ' passa de 400 KB';
            }
            if (trim($p['imagem']['alt'] ?? '') === '' || trim($p['imagem']['legenda'] ?? '') === '') {
                $imgProblemas[] = "$id: imagem sem texto alternativo ou legenda";
            }
        }
    }
}
confere("$imagens capturas de tela existem, têm texto alternativo e são leves", !$imgProblemas, implode('; ', $imgProblemas));

// Nomes de campos, botões e mensagens citados: precisam existir no código-fonte.
$fontes = [];
$naoEncontrados = [];
$conferidos = 0;
foreach ($tutoriais as $id => $t) {
    foreach ($t['confere'] ?? [] as $arquivo => $frases) {
        $caminho = "$admin/$arquivo";
        if (!isset($fontes[$caminho])) {
            $fontes[$caminho] = is_file($caminho) ? html_entity_decode((string)file_get_contents($caminho), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        }
        if ($fontes[$caminho] === null) {
            $naoEncontrados[] = "$id: arquivo $arquivo não existe";
            continue;
        }
        foreach ($frases as $f) {
            $conferidos++;
            if (!str_contains($fontes[$caminho], $f)) {
                $naoEncontrados[] = "$id: \"$f\" não está em $arquivo";
            }
        }
    }
}
confere("$conferidos nomes de campos, botões e mensagens citados existem no código das páginas", !$naoEncontrados, implode("\n           ", $naoEncontrados));
$semConfere = array_keys(array_filter($tutoriais, fn ($t) => empty($t['confere'])));
confere('todo tutorial confere ao menos um nome citado', !$semConfere, implode(', ', $semConfere));

$exigidos = [
    'o-que-e-o-sistema', 'acessar-e-entrar', 'sair-com-seguranca', 'menu-lateral', 'usar-central-ajuda',
    'visao-geral-dashboard', 'cadastrar-livro', 'editar-livro', 'livro-sem-isbn', 'livro-x-exemplar',
    'o-que-e-exemplar', 'cadastrar-exemplar', 'codigos-bdp', 'localizacoes', 'pendencias-exemplar',
    'registrar-emprestimo', 'registrar-devolucao', 'renovar-emprestimo', 'emprestimos-atrasados',
    'pesquisar-alunos', 'privacidade-alunos', 'importar-alunos', 'importar-acervo', 'isbn-invalidos-importacao',
    'conferir-inventario', 'divergencias-pendencias-inventario', 'gerar-etiquetas', 'o-que-e-cdd', 'aprovar-cdd',
    'acessar-relatorios', 'exportar-relatorios', 'perfis-existentes', 'trocar-minha-senha',
    'abrir-configuracoes', 'configurar-regras-emprestimo', 'gerar-backup', 'restaurar-backup',
    'faq-nao-consigo-entrar', 'faq-esqueci-senha', 'faq-aluno-nao-aparece', 'faq-livro-nao-aparece-catalogo',
    'faq-codigo-bdp-nao-encontrado', 'faq-exemplar-indisponivel', 'faq-emprestimo-nao-concluido', 'faq-nao-consigo-renovar',
    'faq-csv-erro', 'faq-planilha-incompleta', 'faq-funcao-nao-aparece', 'faq-etiqueta-impressao', 'faq-relatorio-vazio',
    'faq-pagina-nao-carrega', 'faq-mensagem-erro',
];
$ausentes = array_values(array_diff($exigidos, array_keys($tutoriais)));
confere('os assuntos exigidos existem (' . count($exigidos) . ')', !$ausentes, implode(', ', $ausentes));
confere('o FAQ tem as 15 perguntas', count($modulos['problemas']['tutoriais']) === 15, (string)count($modulos['problemas']['tutoriais']));

$tudo = mb_strtolower(implode("\n", array_merge(...array_map($textos, array_values($tutoriais)))));
$proibidos = ['demo123', 'bibliotecaria@demo', 'senhateste', 'db_password=', 'drop database', 'delete from', 'truncate '];
$achados = array_filter($proibidos, fn ($p) => str_contains($tudo, $p));
confere('o conteúdo não revela credenciais nem orienta comandos destrutivos no banco', !$achados, implode(', ', $achados));
preg_match_all('/[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]+/', $tudo, $emails);
$emailsReais = array_filter(array_unique($emails[0]), fn ($e) => !preg_match('/@(escola\.exemplo|teste\.local)$/', $e));
confere('os e-mails citados são fictícios', !$emailsReais, implode(', ', $emailsReais));

// ---------------------------------------------------------------
echo "\n2. Pesquisa local\n";
confere('normalização tira acentos e maiúsculas', ajuda_normalizar('Empréstimo  DEVOLUÇÕES') === 'emprestimo devolucoes', ajuda_normalizar('Empréstimo  DEVOLUÇÕES'));
$ids = fn (string $q) => array_map(fn ($r) => $r['tutorial']['id'], ajuda_pesquisar($q));
$casos = [
    'emprestimo' => 'registrar-emprestimo', 'empréstimo' => 'registrar-emprestimo', 'EMPRESTIMOS' => 'registrar-emprestimo',
    'devolucao' => 'registrar-devolucao', 'devoluções' => 'registrar-devolucao', 'renovar' => 'renovar-emprestimo',
    'atrasados' => 'emprestimos-atrasados', 'BDP' => 'codigos-bdp', 'bdp-000001' => 'codigos-bdp', 'codigo incompleto' => 'codigos-bdp',
    'exemplar' => 'o-que-e-exemplar', 'etiquetas' => 'gerar-etiquetas', 'inventario' => 'para-que-serve-inventario',
    'cdd' => 'o-que-e-cdd', 'isbn invalido' => 'isbn-invalidos-importacao', 'csv' => 'faq-csv-erro', 'planilha alunos' => 'importar-alunos',
    'backup' => 'gerar-backup', 'copia de seguranca' => 'gerar-backup', 'esqueci senha' => 'faq-esqueci-senha',
    'permissoes' => 'perfis-existentes', 'relatorios' => 'acessar-relatorios', 'privacidade' => 'privacidade-alunos',
    'localizacao' => 'localizacoes', 'configuracoes' => 'abrir-configuracoes', 'menu' => 'menu-lateral',
];
foreach ($casos as $q => $esperado) {
    $r = $ids($q);
    confere("\"$q\" encontra $esperado", in_array($esperado, array_slice($r, 0, 8), true), implode(', ', array_slice($r, 0, 8)));
}
confere('com e sem acento dão o mesmo resultado', $ids('emprestimo') === $ids('empréstimo') && $ids('devolucoes') === $ids('devoluções'));
confere('termo inexistente não encontra nada', $ids('xyzqwk') === []);
confere('pesquisa vazia ou só com símbolos não quebra', $ids('') === [] && is_array($ids('<script>alert(1)</script>')) && is_array($ids(str_repeat('a ', 500))));

// ---------------------------------------------------------------
echo "\n3. Endereço de retorno (voltar)\n";
foreach (['emprestimos.php', 'livros.php?q=dom+casmurro&pagina=2', 'detalhes-exemplar.php?id=3', 'configuracoes.php'] as $v) {
    confere("aceita $v", ajuda_voltar($v) !== null);
}
foreach (['http://exemplo.com/', '//exemplo.com/livros.php', 'https://exemplo.com/livros.php', '../config/app.php', '/admin/livros.php',
          'javascript:alert(1)', 'livros.php#x', 'inexistente.php', 'ajuda.php', 'login.php', 'livros.php?q=<script>', "livros.php\nLocation: x",
          'LIVROS.PHP', str_repeat('a', 290) . '.php'] as $v) {
    confere('recusa ' . str_replace("\n", '\n', mb_strimwidth($v, 0, 40, '…')), ajuda_voltar($v) === null);
}

// ---------------------------------------------------------------
echo "\n4. Páginas da Central de Ajuda\n";
[$raiz, $banco, $env] = teste_banco_temporario('ajuda');
$pdo = db();
$senha = 'SenhaDeTeste123';
$contas = ['administrador' => 'adm@teste.local', 'bibliotecaria' => 'bib@teste.local', 'auxiliar' => 'aux@teste.local', 'consulta' => 'cons@teste.local'];
foreach ($contas as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
}
$pdo->exec("INSERT INTO livros (titulo) VALUES ('Livro Fictício da Ajuda')");
$retrato = function () use ($pdo): array {
    $r = [];
    foreach ($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name NOT IN ('login_tentativas', 'acoes_administrativas')")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $r[$t] = $pdo->query("CHECKSUM TABLE `$t`")->fetch()['Checksum'];
    }
    return $r;
};
$base = teste_servidor($env);
$anon = new TesteNavegador($base);
[$s, , $loc] = $anon->ir('admin/ajuda.php');
confere('sem login: a ajuda pede login', $s === 302 && str_contains($loc, 'login.php'), "$s $loc");
[$s, , $loc] = $anon->ir('admin/ajuda.php?t=registrar-emprestimo');
confere('sem login: um tutorial também pede login', $s === 302 && str_contains($loc, 'login.php'), "$s $loc");

$nav = [];
foreach ($contas as $perfil => $email) {
    $nav[$perfil] = new TesteNavegador($base);
    confere("$perfil entra no painel", $nav[$perfil]->entrar($email, $senha) === 200);
}
$antes = $retrato();

foreach ($nav as $perfil => $n) {
    [$s, $html] = $n->ir('admin/ajuda.php');
    $cards = preg_match_all('/class="adm-card ajd-modulo"/', $html);
    confere("$perfil: abre a Central de Ajuda com os 16 módulos", $s === 200 && str_contains($html, 'Central de Ajuda') && $cards === 16 && teste_sem_erro_tecnico($html), "status $s, $cards cartões");
    confere("$perfil: cabeçalho, subtítulo e campo de pesquisa com os textos pedidos", str_contains($html, 'Aprenda a utilizar as ferramentas da Biblioteca Dom Pedro II com tutoriais passo a passo.') && str_contains($html, 'Pesquisar uma dúvida ou funcionalidade...'));
    confere("$perfil: link Tutorial do Sistema visível (menu ou perfil)", substr_count($html, 'Tutorial do Sistema') >= 1 && str_contains($html, 'ajuda.php'));
    [$s, $html] = $n->ir('admin/ajuda.php?q=' . rawurlencode('emprestimo'));
    confere("$perfil: a pesquisa \"emprestimo\" mostra resultados", $s === 200 && str_contains($html, 't=registrar-emprestimo'));
}

// Todos os tutoriais abrem para todos os perfis, sem erro técnico e sem recurso externo.
$erros = [];
$externos = [];
$semAlt = [];
foreach ($nav as $perfil => $n) {
    foreach (array_keys($tutoriais) as $id) {
        [$s, $html] = $n->ir('admin/ajuda.php?t=' . $id);
        if ($s !== 200 || !teste_sem_erro_tecnico($html) || !str_contains($html, 'data-ajuda-imprimir')) {
            $erros[] = "$perfil/$id ($s)";
        }
        if (preg_match('/<(script|link|img|iframe)[^>]+(src|href)="(https?:)?\/\//i', $html)) {
            $externos[] = "$perfil/$id";
        }
        if (preg_match('/<img(?![^>]*\balt="[^"]+")[^>]*>/i', $html)) {
            $semAlt[] = "$perfil/$id";
        }
    }
}
confere('todos os ' . count($tutoriais) . ' tutoriais abrem para os 4 perfis, sem erro técnico, com botão de imprimir', !$erros, implode(', ', array_slice($erros, 0, 10)));
confere('nenhum recurso externo (funciona sem internet)', !$externos, implode(', ', array_slice($externos, 0, 5)));
confere('toda imagem tem texto alternativo', !$semAlt, implode(', ', array_slice($semAlt, 0, 5)));

[$s, $html] = $nav['consulta']->ir('admin/ajuda.php?t=registrar-emprestimo');
confere('consulta: tutorial restrito mostra o aviso "Seu perfil não tem esta função"', $s === 200 && str_contains($html, 'Seu perfil não tem esta função'));
confere('consulta: o tutorial não mostra link para páginas que o perfil não abre', !preg_match('/href="[^"]*novo-emprestimo\.php/', $html));
[$s] = $nav['consulta']->ir('admin/novo-emprestimo.php');
confere('consulta: depois de ler a ajuda, Novo empréstimo continua bloqueado (403)', $s === 403, (string)$s);
[$s] = $nav['auxiliar']->ir('admin/manutencao.php');
confere('auxiliar: depois de ler a ajuda, Manutenção continua bloqueada (403)', $s === 403, (string)$s);
[$s, $html] = $nav['bibliotecaria']->ir('admin/ajuda.php?t=registrar-emprestimo');
confere('bibliotecária: o mesmo tutorial não mostra o aviso de função indisponível', !str_contains($html, 'Seu perfil não tem esta função'));

[$s, $html] = $nav['bibliotecaria']->ir('admin/ajuda.php?t=nao-existe');
confere('tutorial inexistente: 404 com aviso amigável', $s === 404 && str_contains($html, 'Tutorial não encontrado.'));
[$s, $html] = $nav['bibliotecaria']->ir('admin/ajuda.php?modulo=nao-existe');
confere('módulo inexistente: 404', $s === 404);
[$s, $html] = $nav['bibliotecaria']->ir('admin/ajuda.php?t=registrar-emprestimo&voltar=' . rawurlencode('emprestimos.php?situacao=atrasados'));
confere('voltar válido: botão "Voltar para Empréstimos" com a página de origem', str_contains($html, 'Voltar para Empréstimos') && str_contains($html, 'emprestimos.php?situacao=atrasados'));
confere('voltar válido é mantido nos links para outros tutoriais', (bool)preg_match('/ajuda\.php\?t=[a-z0-9-]+&amp;voltar=emprestimos\.php/', $html));
foreach (['https://exemplo.com/', '//exemplo.com', '../config/app.php', 'javascript:alert(1)'] as $v) {
    [$s, $html] = $nav['bibliotecaria']->ir('admin/ajuda.php?t=registrar-emprestimo&voltar=' . rawurlencode($v));
    confere('voltar inválido ignorado: ' . $v, $s === 200 && !str_contains($html, 'Voltar para') && !str_contains($html, 'exemplo.com') && !str_contains($html, 'javascript:'));
}
[$s, $html] = $nav['bibliotecaria']->ir('admin/ajuda.php?q=' . rawurlencode('<script>alert(1)</script>'));
confere('pesquisa com HTML é escapada', $s === 200 && !str_contains($html, '<script>alert(1)</script>'));

// Botão "Como usar esta página?" nas páginas do painel.
$contextuais = [
    'bibliotecaria' => ['index.php' => 'visao-geral-dashboard', 'livros.php' => 'consultar-livros', 'cadastrar-livro.php' => 'cadastrar-livro', 'exemplares.php' => 'consultar-exemplar',
        'emprestimos.php' => 'consultar-emprestimos', 'novo-emprestimo.php' => 'registrar-emprestimo', 'devolucoes.php' => 'registrar-devolucao', 'atrasos.php' => 'emprestimos-atrasados',
        'inventario.php' => 'para-que-serve-inventario', 'etiquetas.php' => 'gerar-etiquetas', 'classificacao.php' => 'painel-cdd', 'relatorios.php' => 'acessar-relatorios',
        'importacao.php' => 'importar-acervo', 'importar-alunos.php' => 'importar-alunos', 'configuracoes.php' => 'abrir-configuracoes', 'permissoes.php' => 'perfis-existentes',
        'perfil.php' => 'trocar-minha-senha', 'localizacoes.php' => 'localizacoes', 'site-publico.php' => 'textos-site-publico',
        'alunos.php' => 'pesquisar-alunos', 'aluno-editar.php' => 'cadastrar-aluno', 'categorias.php' => 'gerenciar-categorias', 'categoria.php' => 'cadastrar-categoria'],
    'administrador' => ['administradores.php' => 'consultar-contas', 'manutencao.php' => 'gerar-backup'],
    'consulta' => ['livros.php' => 'consultar-livros', 'index.php' => 'visao-geral-dashboard'],
];
foreach ($contextuais as $perfil => $paginas) {
    foreach ($paginas as $pagina => $tid) {
        [$s, $html] = $nav[$perfil]->ir('admin/' . $pagina);
        $ok = $s === 200 && str_contains($html, 'Como usar esta página?') && (bool)preg_match('/ajuda\.php\?t=' . preg_quote($tid, '/') . '&amp;voltar=' . preg_quote($pagina, '/') . '/', $html);
        confere("$perfil: $pagina tem \"Como usar esta página?\" para $tid", $ok, (string)$s);
    }
}
[$s, $html] = $nav['bibliotecaria']->ir('admin/ajuda.php');
confere('a própria Central de Ajuda não mostra o botão contextual', !str_contains($html, 'Como usar esta página?'));

confere('ler a ajuda e abrir as páginas não alterou nenhum dado', $retrato() === $antes);

teste_resultado();
