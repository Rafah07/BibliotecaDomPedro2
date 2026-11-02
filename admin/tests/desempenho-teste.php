<?php
/**
 * Desempenho com um acervo do tamanho do real (Etapa 7 — revisão final).
 *
 * Uso: php admin/tests/desempenho-teste.php [--livros=10000] [--manter]
 *
 * Cria um banco temporário "bdp_teste_desempenho_XXXX", aplica as migrações e gera SOMENTE nele
 * dados FICTÍCIOS: 10.000 livros (com autores e categorias), 4.000 exemplares, 300 alunos,
 * empréstimos abertos, atrasados e devolvidos, e um inventário em andamento. Depois mede, pelo
 * servidor PHP embutido, o tempo das páginas mais usadas (início, catálogo, pesquisa com e sem
 * acento, filtros, paginação profunda, dashboard, relatórios, exportação, empréstimos e inventário)
 * e confere que nenhuma página carrega o catálogo inteiro de uma vez.
 *
 * Os limites de tempo são folgados (computador da escola pode ser simples): a ideia é detectar
 * consultas que crescem com o acervo, não medir milissegundos. --manter não apaga o banco no final
 * (útil para abrir as páginas no navegador); apague depois com DROP DATABASE.
 */
require __DIR__ . '/suporte.php';

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LocalizacaoService;
use Biblioteca\Support\RegrasCirculacao;

$opcoes = getopt('', ['livros::', 'manter']);
$totalLivros = max(1000, (int)($opcoes['livros'] ?? 10000));
$manter = isset($opcoes['manter']);

[$raiz, $banco, $env] = teste_banco_temporario('desempenho');
if ($manter) {
    // Tira o banco da lista de limpeza: fica para conferência manual.
    $GLOBALS['teste_bancos'] = [];
    echo "--manter: o banco $banco NÃO será apagado no final.\n";
}
$pdo = db();
$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['administrador' => 'admin@teste.br', 'bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . $perfil, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------
echo "\nGerando dados fictícios ($totalLivros livros)...\n";
$t0 = microtime(true);
$palavras = ['Viagem', 'Estrelas', 'Coração', 'Ciência', 'História', 'Mistério', 'Jardim', 'Oceano', 'Número', 'Música', 'Brasil', 'Floresta', 'Cidade', 'Tempo', 'Sonho', 'Ação', 'Lição', 'Família'];
$sobrenomes = ['Silva Fictício', 'Souza Teste', 'Conceição Exemplo', 'Araújo Modelo', 'Gonçalves Demo', 'Pereira Simulado', 'Lima Inventado', 'Ribeiro Hipotético'];
$categorias = [];
foreach (['Literatura brasileira', 'Ficção', 'Ciências', 'História', 'Matemática', 'Infantojuvenil', 'Poesia', 'Geografia'] as $nome) {
    $pdo->prepare('INSERT INTO categorias (nome) VALUES (?)')->execute([$nome]);
    $categorias[] = (int)$pdo->lastInsertId();
}
$autores = [];
for ($i = 1; $i <= 1500; $i++) {
    $autores[] = ['Autor ' . $palavras[$i % count($palavras)] . ' ' . $sobrenomes[$i % count($sobrenomes)] . ' ' . $i];
}
$pdo->beginTransaction();
$st = $pdo->prepare('INSERT INTO autores (nome) VALUES (?)');
foreach ($autores as $a) {
    $st->execute($a);
}
$primeiroAutor = (int)$pdo->query('SELECT MIN(id) FROM autores')->fetchColumn();
$insLivro = $pdo->prepare('INSERT INTO livros (titulo, isbn, editora, ano_publicacao, assunto, origem_cadastro, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insAutor = $pdo->prepare('INSERT INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, 1)');
$insCat = $pdo->prepare('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, 1)');
for ($i = 1; $i <= $totalLivros; $i++) {
    $titulo = $palavras[$i % count($palavras)] . ' ' . $palavras[($i * 7) % count($palavras)] . ' — volume fictício ' . $i;
    $isbn = $i % 3 === 0 ? null : '978' . str_pad((string)$i, 9, '0', STR_PAD_LEFT) . '0';
    $insLivro->execute([$titulo, $isbn, 'Editora Teste ' . ($i % 40), 1950 + $i % 75, 'Assunto ' . $palavras[$i % 5], $i % 2 ? 'planilha' : 'manual', date('Y-m-d H:i:s', time() - $i * 600)]);
    $id = (int)$pdo->lastInsertId();
    $insAutor->execute([$id, $primeiroAutor + ($i % 1500)]);
    if ($i % 10 !== 0) {
        $insCat->execute([$id, $categorias[$i % count($categorias)]]);
    }
}
$pdo->commit();
$pdo->exec("UPDATE livros SET arquivado_em = NOW() WHERE id % 97 = 0");
$loc = new LocalizacaoService($pdo);
$locais = [];
foreach (['Literatura', 'Ciências', 'Referência'] as $setor) {
    for ($e = 1; $e <= 4; $e++) {
        $locais[] = $loc->salvar(['setor' => $setor, 'estante' => 'E' . $e, 'prateleira' => 'P1'], $contas['administrador']);
    }
}
$gerar = $pdo->prepare('CALL gerar_exemplares(?, ?, ?, ?, ?)');
$exemplares = [];
for ($i = 1; count($exemplares) < 4000 && $i <= $totalLivros; $i += 2) {
    if ($i % 97 === 0) {
        continue;
    }
    $gerar->execute([$i, 1 + $i % 2, $locais[$i % count($locais)], 'bom', 'disponivel']);
    foreach ($gerar->fetchAll() as $linha) {
        $exemplares[] = (int)$linha['id'];
    }
    $gerar->closeCursor();
}
$pdo->beginTransaction();
$insAluno = $pdo->prepare('INSERT INTO alunos (nome, matricula, turma, turno) VALUES (?, ?, ?, ?)');
for ($a = 1; $a <= 300; $a++) {
    $insAluno->execute(['Aluno Fictício ' . $a, 'FIC-' . str_pad((string)$a, 5, '0', STR_PAD_LEFT), (6 + $a % 4) . chr(65 + $a % 3), $a % 2 ? 'Manhã' : 'Tarde']);
}
$pdo->commit();
$primeiroAluno = (int)$pdo->query('SELECT MIN(id) FROM alunos')->fetchColumn();
$servico = new EmprestimoService($pdo, RegrasCirculacao::doAmbiente('config_valor'));
for ($k = 0; $k < 600; $k++) {
    $r = $servico->registrar($primeiroAluno + $k % 300, $exemplares[$k], date('Y-m-d', strtotime('+10 days')), $contas['bibliotecaria']);
    if ($k % 3 === 0) {
        $servico->devolver($r['id'], $contas['bibliotecaria']);
    }
}
// Alguns atrasados: prazo no passado direto no banco temporário (o serviço não deixa criar assim).
$pdo->exec('UPDATE emprestimos SET data_prevista = CURDATE() - INTERVAL 5 DAY WHERE data_devolucao IS NULL AND id % 4 = 0');
$inv = new InventarioService($pdo, new ExemplarService($pdo), new InventarioRepository($pdo));
$invId = $inv->criar(['titulo' => 'Inventário fictício de desempenho', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $contas['bibliotecaria']], $contas['bibliotecaria']);
$inv->iniciar($invId, $contas['bibliotecaria']);
for ($k = 1000; $k < 1300; $k++) {
    $inv->conferir($invId, $exemplares[$k], ['estado_conservacao' => 'bom'], $contas['bibliotecaria']);
}
$totais = $pdo->query("SELECT (SELECT COUNT(*) FROM livros) l, (SELECT COUNT(*) FROM exemplares) e, (SELECT COUNT(*) FROM emprestimos) m, (SELECT COUNT(*) FROM alunos) a")->fetch();
printf("  %d livros, %d exemplares, %d empréstimos, %d alunos em %.1f s\n", $totais['l'], $totais['e'], $totais['m'], $totais['a'], microtime(true) - $t0);
confere('dados fictícios gerados só no banco temporário', $pdo->query('SELECT DATABASE()')->fetchColumn() === $banco && (int)$totais['l'] === $totalLivros);

// ---------------------------------------------------------------
echo "\nTempos das páginas (servidor PHP embutido; limite folgado por página)\n";
$base = teste_servidor($env);
$anon = new TesteNavegador($base);
$anon->ir(''); // aquece o servidor
$navs = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $navs[$perfil] = new TesteNavegador($base);
    $navs[$perfil]->entrar($email, $senha);
}
$medir = function (TesteNavegador $nav, string $pagina, int $repeticoes = 3): array {
    $tempos = [];
    $s = 0;
    $html = '';
    for ($r = 0; $r < $repeticoes; $r++) {
        $t = microtime(true);
        [$s, $html] = $nav->ir($pagina);
        $tempos[] = microtime(true) - $t;
    }
    sort($tempos);
    return [$s, $html, $tempos[intdiv(count($tempos), 2)]];
};
$resultados = [];
$contagens = [];
$casos = [
    ['Início do site', $anon, '', 1.5],
    ['Catálogo (1ª página)', $anon, 'biblioteca_php/catalogo.php', 1.5],
    ['Pesquisa com acento ("coração")', $anon, 'biblioteca_php/catalogo.php?q=cora%C3%A7%C3%A3o&campo=titulo', 2.0],
    ['Pesquisa sem acento ("coracao")', $anon, 'biblioteca_php/catalogo.php?q=coracao&campo=titulo', 2.0],
    ['Pesquisa por autor', $anon, 'biblioteca_php/catalogo.php?q=Gon%C3%A7alves', 2.0],
    ['Pesquisa por ISBN', $anon, 'biblioteca_php/catalogo.php?q=978000004321', 2.0],
    ['Filtro categoria + disponíveis + ordem por título', $anon, 'biblioteca_php/catalogo.php?categoria=2&disponibilidade=disponivel&ordem=titulo', 2.0],
    ['Paginação profunda (página 300)', $anon, 'biblioteca_php/catalogo.php?pagina=300', 2.0],
    ['Detalhes de um livro', $anon, 'biblioteca_php/livro.php?id=101', 1.0],
    ['Autores', $anon, 'biblioteca_php/autores.php', 1.5],
    ['Categorias', $anon, 'biblioteca_php/generos.php', 1.5],
    ['Dashboard do painel', $navs['bibliotecaria'], 'admin/index.php', 2.5],
    ['Livros do painel (busca)', $navs['bibliotecaria'], 'admin/livros.php?q=historia', 2.0],
    ['Exemplares do painel', $navs['bibliotecaria'], 'admin/exemplares.php', 2.0],
    ['Pesquisa de exemplar por código BDP', $navs['bibliotecaria'], 'admin/exemplares.php?q=BDP-000123', 1.5],
    ['Empréstimos em aberto', $navs['bibliotecaria'], 'admin/emprestimos.php', 2.0],
    ['Atrasos', $navs['bibliotecaria'], 'admin/atrasos.php', 2.0],
    ['Relatórios — visão geral', $navs['bibliotecaria'], 'admin/relatorios.php', 3.0],
    ['Relatórios — acervo', $navs['bibliotecaria'], 'admin/relatorios.php?secao=acervo', 3.0],
    ['Relatórios — empréstimos', $navs['bibliotecaria'], 'admin/relatorios.php?secao=emprestimos', 3.0],
    ['Inventário em andamento', $navs['bibliotecaria'], 'admin/inventario-detalhes.php?id=' . $invId, 2.0],
    ['Dashboard (perfil consulta)', $navs['consulta'], 'admin/index.php', 2.5],
];
foreach ($casos as [$nome, $nav, $pagina, $limite]) {
    [$s, $html, $mediana] = $medir($nav, $pagina);
    $resultados[] = sprintf('%-52s %6.0f ms', $nome, $mediana * 1000);
    confere(sprintf('%s: %d em %.0f ms (limite %.1f s)', $nome, $s, $mediana * 1000, $limite), $s === 200 && $mediana < $limite && teste_sem_erro_tecnico($html));
    if ($pagina === 'biblioteca_php/catalogo.php') {
        $cartoes = substr_count($html, 'class="book-card catalog-book"');
        confere("catálogo mostra uma página de resultados ($cartoes cartões), não o acervo inteiro", $cartoes > 0 && $cartoes <= 48 && strlen($html) < 600000);
    }
    if (str_contains($pagina, 'q=cora')) {
        preg_match('/result-count">([\d.]+) livro/', $html, $m);
        $contagens[] = $m[1] ?? '?';
    }
    if (str_contains($pagina, 'q=coracao')) {
        confere('pesquisa sem acento encontra os títulos com acento', str_contains($html, 'Coração'));
        confere('com e sem acento: o mesmo número de resultados (' . implode(' = ', $contagens) . ')', count($contagens) === 2 && $contagens[0] === $contagens[1] && $contagens[0] !== '?');
    }
}
$t = microtime(true);
[$s, $csv, , $tipo, $disp] = $navs['bibliotecaria']->ir('admin/relatorios.php?secao=acervo&formato=csv&tabela=livros');
$tempoCsv = microtime(true) - $t;
$resultados[] = sprintf('%-52s %6.0f ms', 'Exportação CSV do acervo', $tempoCsv * 1000);
confere(sprintf('exportação CSV do acervo inteiro em %.1f s', $tempoCsv), $s !== 200 || ($tempoCsv < 15 && str_contains($disp, 'attachment')), "status $s tipo $tipo");

// ---------------------------------------------------------------
echo "\nPlanos das consultas principais (EXPLAIN)\n";
$plano = function (string $sql, array $p = []) use ($pdo): array {
    $st = $pdo->prepare('EXPLAIN ' . $sql);
    $st->execute($p);
    return $st->fetchAll();
};
$usaIndice = fn (array $linhas, string $tabela) => (bool)array_filter($linhas, fn ($l) => $l['table'] === $tabela && $l['type'] !== 'ALL');
confere('pesquisa por código BDP usa o índice único', $usaIndice($plano('SELECT id FROM exemplares WHERE codigo_individual = ?', ['BDP-000123']), 'exemplares'));
confere('empréstimo em aberto de um exemplar usa índice', $usaIndice($plano('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [5]), 'emprestimos'));
confere('atrasos usam o índice por devolução/prazo', $usaIndice($plano('SELECT id FROM emprestimos WHERE data_devolucao IS NULL AND data_prevista < CURDATE()'), 'emprestimos'));
confere('exemplares de um livro usam índice', $usaIndice($plano("SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND situacao = 'disponivel'", [11]), 'exemplares'));
confere('histórico de um aluno usa índice', $usaIndice($plano('SELECT id FROM emprestimos WHERE aluno_id = ? ORDER BY data_emprestimo DESC', [$primeiroAluno]), 'emprestimos'));

echo "\nResumo dos tempos (mediana de 3 acessos):\n  " . implode("\n  ", $resultados) . "\n";
teste_resultado();
