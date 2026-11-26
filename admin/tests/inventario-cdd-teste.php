<?php
/**
 * Testes da CDD no formulário "Verificar e corrigir dados" do Inventário.
 *
 * Uso: php admin/tests/inventario-cdd-teste.php
 *
 * Banco temporário "bdp_teste_invcdd_XXXX" (apagado no final), dados FICTÍCIOS. Cenários:
 *   1. a ficha da obra e o formulário mostram a CDD (a antiga da planilha só na dica);
 *   2. salvar sem mexer na CDD não cria classificação nem histórico;
 *   3. bibliotecária corrige a CDD junto com o título: fica aprovada, com histórico de correção;
 *   4. CDD em formato inválido: nada é gravado (nem o título);
 *   5. conta antiga "auxiliar" muda a CDD: fica aprovada (único perfil, migração 200);
 *   6. livro não listado cadastrado com CDD.
 */

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('invcdd', ['STORAGE_IMPORTACOES' => sys_get_temp_dir() . '/bdp-invcdd-' . bin2hex(random_bytes(3))]);
$_SERVER['SCRIPT_NAME'] = '/admin/tests/inventario-cdd-teste.php';
require __DIR__ . '/../includes/importacao.php';
$pdo = db();
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$val = fn (string $sql, array $p = []) => db_query($sql, $p)->fetchColumn();

$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];

$repo = importacao_repo();
$cab = ['Localização', 'Título', 'Sobrenome/Prenome do Autor', 'Editora', 'ISBN', 'Qtd', 'Ano', 'CDD'];
$mapa = importacao_validador()->sugerirMapeamento($cab);
$imp = $repo->criar(['nome_arquivo' => 'acervo-cdd.csv', 'identificador_arquivo' => hash('sha256', 'invcdd'), 'arquivo_armazenado' => 'c.csv.upload',
    'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => 2, 'colunas' => count($cab)]], 'aba' => 'CSV', 'usuario_id' => $bibId, 'total_linhas' => 0]);
$repo->iniciarAnalise($imp, 'CSV', 1, $cab, $mapa);
$linhas = [
    ['A-01', 'Romance Fictício Errado', 'Mares, Ana Fictícia', 'Editora Onda', '', '1', '2001', 'LIT. BRAS.'],
    ['A-02', 'Poemas Fictícios', 'Lima, Bia Fictícia', 'Editora Verso', '', '1', '1999', ''],
];
$repo->inserirLinhas($imp, array_combine(range(2, 3), array_map(fn ($l) => array_combine($cab, $l), $linhas)), $mapa, 3);
$repo->concluirAnalise($imp);
$repo->importarTodos($imp, $bibId);
$livro = (int)$val('SELECT livro_id FROM registros_legados WHERE linha = 2 ORDER BY id LIMIT 1');
$poemas = (int)$val('SELECT livro_id FROM registros_legados WHERE linha = 3 ORDER BY id LIMIT 1');
$exServ = new ExemplarService($pdo);
$invServ = new InventarioService($pdo, $exServ, new InventarioRepository($pdo));
$inv = $invServ->criar(['titulo' => 'Inventário Fictício CDD', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv, $bibId);

$base = teste_servidor($env);
$bib = new TesteNavegador($base);
confere('bibliotecária entra no painel', $bib->entrar('bib@teste.br', $senha) === 200);
$pg = 'admin/inventario-conferencia.php?id=' . $inv;
$tk = $bib->csrf($pg);
$dadosLivro = ['csrf' => $tk, 'id' => $inv, 'acao' => 'verificar_obra', 'livro_id' => $livro, 'marcar_verificado' => '0', 'editar_livro' => '1',
    'titulo' => 'Romance Fictício Errado', 'autores' => 'Mares, Ana Fictícia', 'editora' => 'Editora Onda', 'ano_publicacao' => '2001'];

echo "\n1. CDD na ficha e no formulário\n";
[$s, $html] = $bib->ir($pg . '&obra=' . $livro . '&painel=verificar');
confere('formulário "Verificar e corrigir dados" tem o campo CDD e a fonte', $s === 200 && str_contains($html, 'name="cdd"') && str_contains($html, 'name="cdd_fonte"'));
confere('a CDD antiga da planilha aparece só na dica, não no campo', preg_match('/name="cdd" [^>]*value=""/', $html) === 1 && str_contains($html, 'Atual: LIT. BRAS.'));
confere('ficha da obra mostra a CDD', str_contains($html, '<dt>CDD</dt>'));

echo "\n2. Salvar sem mexer na CDD\n";
[$s] = $bib->ir($pg, $dadosLivro + ['cdd' => '', 'cdd_exibida' => '']);
confere('nada muda na classificação', $s === 303 && $num('SELECT COUNT(*) FROM classificacoes_cdd_historico WHERE livro_id = ?', [$livro]) === 0);

echo "\n3. Bibliotecária corrige a CDD junto com o título\n";
[$s] = $bib->ir($pg, ['titulo' => 'Romance Fictício Certo', 'cdd' => '869.3', 'cdd_exibida' => '', 'cdd_fonte' => 'Ficha catalográfica'] + $dadosLivro);
confere('título corrigido', $s === 303 && $val('SELECT titulo FROM livros WHERE id = ?', [$livro]) === 'Romance Fictício Certo');
$c = db_query('SELECT * FROM classificacoes_cdd WHERE livro_id = ?', [$livro])->fetch();
confere('CDD aprovada, com a fonte informada', $c && $c['cdd_aprovada'] === '869.3' && $c['status'] === 'aprovada' && $c['aprovada_fonte'] === 'Ficha catalográfica', json_encode($c));
confere('a CDD antiga da planilha é preservada', $c && $c['cdd_original'] === 'LIT. BRAS.');
confere('histórico registra a mudança', $num("SELECT COUNT(*) FROM classificacoes_cdd_historico WHERE livro_id = ? AND cdd_nova = '869.3'", [$livro]) === 1);
[, $html] = $bib->ir($pg . '&obra=' . $livro . '&painel=verificar');
confere('o formulário passa a mostrar a CDD aprovada no campo', preg_match('/name="cdd" [^>]*value="869.3"/', $html) === 1 && str_contains($html, 'CDD 869.3 aprovada'));
[$s] = $bib->ir($pg, ['titulo' => 'Romance Fictício Certo', 'cdd' => '869.3', 'cdd_exibida' => '869.3'] + $dadosLivro);
confere('salvar de novo sem mudar a CDD não cria histórico', $num('SELECT COUNT(*) FROM classificacoes_cdd_historico WHERE livro_id = ?', [$livro]) === 1);

echo "\n4. CDD inválida\n";
[$s, $html] = $bib->ir($pg, ['titulo' => 'Título Que Não Deve Gravar', 'cdd' => '869,3', 'cdd_exibida' => '869.3'] + $dadosLivro);
confere('erro mostrado e nada gravado', $s === 200 && str_contains($html, 'Use ponto para separar os decimais') && $val('SELECT titulo FROM livros WHERE id = ?', [$livro]) === 'Romance Fictício Certo'
    && $val('SELECT cdd_aprovada FROM classificacoes_cdd WHERE livro_id = ?', [$livro]) === '869.3');
confere('o formulário volta aberto com o valor digitado', preg_match('/name="cdd" [^>]*value="869,3"/', $html) === 1);

echo "\n5. Auxiliar muda a CDD\n";
$aux = new TesteNavegador($base);
confere('auxiliar entra no painel', $aux->entrar('aux@teste.br', $senha) === 200);
$tka = $aux->csrf($pg);
[$s] = $aux->ir($pg, ['csrf' => $tka, 'titulo' => 'Romance Fictício Certo', 'cdd' => '869.35', 'cdd_exibida' => '869.3'] + $dadosLivro);
$c = db_query('SELECT * FROM classificacoes_cdd WHERE livro_id = ?', [$livro])->fetch();
confere('fica aprovada direto (único perfil: todas as contas aprovam CDD)', $s === 303 && $c['cdd_aprovada'] === '869.35', json_encode($c));

echo "\n6. Livro não listado com CDD\n";
[$s, $html] = $bib->ir($pg . '&novo=1');
preg_match('/name="token" value="([^"]+)"/', $html, $m);
confere('formulário do livro não listado tem o campo CDD', str_contains($html, 'name="cdd"'));
[$s, , $loc] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'nao_listado', 'token' => $m[1] ?? '', 'escolha' => 'novo', 'editar_livro' => '1', 'marcar_verificado' => '0',
    'titulo' => 'Crônicas Fictícias Novas', 'autores' => 'Prado, Lia Fictícia', 'cdd' => '869.4', 'cdd_exibida' => '']);
$novo = (int)$val("SELECT id FROM livros WHERE titulo = 'Crônicas Fictícias Novas'");
confere('obra criada com a CDD aprovada', $s === 303 && $novo > 0 && $val('SELECT cdd_aprovada FROM classificacoes_cdd WHERE livro_id = ?', [$novo]) === '869.4', "$s $loc");
confere('o outro livro não foi tocado', $num('SELECT COUNT(*) FROM classificacoes_cdd_historico WHERE livro_id = ?', [$poemas]) === 0);

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
