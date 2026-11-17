<?php
/**
 * Testes do "+ Adicionar obra" na conferência do Inventário (obras que não estão na planilha).
 *
 * Uso: php admin/tests/nova-obra-conferencia-teste.php
 *
 * Banco temporário "bdp_teste_novaobra_XXXX" (apagado no final), dados FICTÍCIOS. Cenários:
 *    1. "+ Adicionar obra" ao lado da pesquisa, junto com o "Cadastrar livro não listado" (preservado), e
 *       mensagem quando a pesquisa não encontra nada;
 *    2. obras parecidas enquanto digita (API) e ao salvar, sem gravar nem unir nada;
 *    3. "Salvar obra": só a obra (sem ISBN, Dados não verificados), sem exemplar, sem código BDP, sem vaga no lote;
 *    4. clique duplo não cria outra obra;
 *    5. "Salvar, verificar e registrar exemplar": abre o registro do exemplar, sem linha da planilha;
 *    6. três cópias: três exemplares, três códigos BDP, três vagas no lote ativo, Planilha BDP do lote;
 *    7. capacidade do lote preservada (a 4ª cópia num lote de 3 é recusada);
 *    8. "Salvar e marcar dados como verificados";
 *    9. origem na ficha, na lista de livros e nos relatórios (com filtro);
 *   10. permissões (auxiliar não verifica; consulta não cadastra).
 */

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\RelatorioRepository;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LoteService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('novaobra', ['STORAGE_IMPORTACOES' => sys_get_temp_dir() . '/bdp-novaobra-' . bin2hex(random_bytes(3))]);
$_SERVER['SCRIPT_NAME'] = '/admin/tests/nova-obra-conferencia-teste.php';
require __DIR__ . '/../includes/importacao.php';
$pdo = db();
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$val = fn (string $sql, array $p = []) => db_query($sql, $p)->fetchColumn();

$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$pdo->exec("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Literatura Fictícia', 'E01', 'P01', 'LF-E01-P01')");
$loc = (int)$val("SELECT id FROM localizacoes WHERE codigo = 'LF-E01-P01'");

// Uma obra importada da planilha (para as parecidas e para a origem).
$repo = importacao_repo();
$cab = ['Localização', 'Título', 'Sobrenome/Prenome do Autor', 'Editora', 'ISBN', 'Qtd', 'Ano'];
$mapa = importacao_validador()->sugerirMapeamento($cab);
$imp = $repo->criar(['nome_arquivo' => 'acervo-novaobra.csv', 'identificador_arquivo' => hash('sha256', 'novaobra'), 'arquivo_armazenado' => 'c.csv.upload',
    'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => 1, 'colunas' => count($cab)]], 'aba' => 'CSV', 'usuario_id' => $bibId, 'total_linhas' => 0]);
$repo->iniciarAnalise($imp, 'CSV', 1, $cab, $mapa);
$repo->inserirLinhas($imp, [2 => array_combine($cab, ['A-01', 'Contos Fictícios da Serra', 'Mares, Ana Fictícia', 'Editora Onda', '', '1', '2001'])], $mapa, 2);
$repo->concluirAnalise($imp);
$repo->importarTodos($imp, $bibId);
$importada = (int)$val('SELECT livro_id FROM registros_legados ORDER BY id LIMIT 1');

$exServ = new ExemplarService($pdo);
$invServ = new InventarioService($pdo, $exServ, new InventarioRepository($pdo));
$inv = $invServ->criar(['titulo' => 'Inventário Fictício Novas Obras', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv, $bibId);
$lotes = new LoteService($pdo, $exServ);
$lote = $lotes->criar(['capacidade' => 3, 'usuarios' => [$bibId, $contas['auxiliar']], 'descricao' => 'Lote fictício de teste'], $bibId);
$lotes->escolherAtivo($lote, $bibId);
$vagas = fn () => $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$lote]);

$base = teste_servidor($env);
$bib = new TesteNavegador($base);
confere('bibliotecária entra no painel', $bib->entrar('bib@teste.br', $senha) === 200);
$pg = 'admin/inventario-conferencia.php?id=' . $inv;
$tk = $bib->csrf($pg);

echo "\n1. Botão e pesquisa sem resultado\n";
[$s, $html] = $bib->ir($pg);
confere('"+ Adicionar obra" ao lado do campo de pesquisa; "Cadastrar livro não listado" continua no topo', $s === 200 && preg_match('/id="leitura".*?\+ Adicionar obra/s', $html) === 1 && str_contains($html, 'data-nova-obra-link') && str_contains($html, 'Cadastrar livro não listado</a>'));
[$s, $html] = $bib->ir($pg . '&q=' . rawurlencode('Lendas Fictícias do Sertão'));
confere('pesquisa sem resultado: mensagem e botão de cadastro', $s === 200 && str_contains($html, 'Nenhuma obra encontrada.')
    && str_contains($html, 'Caso o livro esteja em mãos e ainda não esteja cadastrado, adicione uma nova obra.') && str_contains($html, '&amp;nova_obra=1&amp;titulo=Lendas'));
[$s, $html] = $bib->ir($pg . '&q=' . rawurlencode('Contos Fictícios'));
confere('com resultados, o botão continua disponível', $s === 200 && str_contains($html, 'Contos Fictícios da Serra') && str_contains($html, '+ Adicionar obra') && str_contains($html, 'Cadastrar livro não listado'));

echo "\n2. Obras parecidas\n";
[$s, $json] = $bib->ir('admin/api/inventario/correspondencias.php?id=' . $inv . '&titulo=' . rawurlencode('Contos Fictícios da Serra') . '&editora=' . rawurlencode('Editora Onda'));
$obras = json_decode($json, true)['obras'] ?? [];
confere('enquanto digita: a obra importada aparece, com motivo e "mesma editora"', $s === 200 && ($obras[0]['id'] ?? 0) === $importada && in_array('mesma editora', $obras[0]['detalhes'] ?? [], true), $json);
[$s, $json] = $bib->ir('admin/api/inventario/correspondencias.php?id=' . $inv . '&titulo=Serra&autores=' . rawurlencode('Ana Mares'));
confere('mesmo autor com uma palavra do título também é sugerido', in_array($importada, array_column(json_decode($json, true)['obras'] ?? [], 'id'), true), $json);
[$s, $json] = $bib->ir('admin/api/inventario/correspondencias.php?id=' . $inv . '&titulo=' . rawurlencode('Lendas Fictícias do Sertão'));
confere('obra inexistente: nenhuma sugestão', $s === 200 && (json_decode($json, true)['obras'] ?? null) === []);
[$s] = (new TesteNavegador($base))->ir('admin/api/inventario/correspondencias.php?id=' . $inv . '&titulo=Contos');
confere('API exige login (401)', $s === 401);
[, $html] = $bib->ir($pg . '&nova_obra=1&titulo=' . rawurlencode('Contos Fictícios da Serra'));
preg_match('/name="token" value="([a-f0-9]+)"/', $html, $m);
confere('formulário: campos da obra, CDD, três ações e nenhum campo de exemplar', str_contains($html, 'Adicionar obra') && str_contains($html, 'name="cdd"')
    && str_contains($html, 'value="registrar"') && str_contains($html, 'value="verificar"') && str_contains($html, 'value="obra"') && !str_contains($html, 'name="codigo_bdp"')
    && preg_match('/name="titulo"[^>]*value="Contos Fictícios da Serra"/', $html) === 1);
$antes = $num('SELECT COUNT(*) FROM livros');
$post = ['csrf' => $tk, 'id' => $inv, 'acao' => 'nova_obra', 'token' => $m[1] ?? '', 'editar_livro' => '1', 'titulo' => 'Contos Fictícios da Serra', 'editora' => 'Editora Onda', 'edicao' => '2ª ed.', 'salvar' => 'registrar'];
[$s, $html] = $bib->ir($pg, $post);
confere('ao salvar: parecidas com "Utilizar obra existente", nada gravado', $s === 200 && str_contains($html, 'obra(s) parecida(s)') && str_contains($html, 'Utilizar obra existente')
    && str_contains($html, 'Continuar cadastrando uma nova obra.') && $num('SELECT COUNT(*) FROM livros') === $antes);
[$s, , $loc1] = $bib->ir($pg, $post + ['escolha' => 'novo']);
$outraEdicao = (int)$val("SELECT MAX(id) FROM livros WHERE titulo = 'Contos Fictícios da Serra'");
confere('"Continuar cadastrando": título igual não impede (outra edição); nada é unido', $s === 303 && $outraEdicao !== $importada && $num('SELECT COUNT(*) FROM livros') === $antes + 1
    && $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ?', [$importada]) === 1);

echo "\n3. Salvar obra (sem ISBN)\n";
[, $html] = $bib->ir($pg . '&nova_obra=1');
preg_match('/name="token" value="([a-f0-9]+)"/', $html, $m);
$postObra = ['csrf' => $tk, 'id' => $inv, 'acao' => 'nova_obra', 'token' => $m[1] ?? '', 'editar_livro' => '1', 'titulo' => 'Crônicas Fictícias Só Obra', 'autores' => 'Prado, Lia Fictícia', 'salvar' => 'obra'];
$reservas = $num('SELECT COUNT(*) FROM exemplar_codigo_reservas');
$exAntes = $num('SELECT COUNT(*) FROM exemplares');
[$s, , $locObra] = $bib->ir($pg, $postObra);
$soObra = (int)$val("SELECT id FROM livros WHERE titulo = 'Crônicas Fictícias Só Obra'");
confere('obra criada sem ISBN, origem "conferência", Dados não verificados', $s === 303 && $soObra > 0 && $val('SELECT isbn FROM livros WHERE id = ?', [$soObra]) === null
    && $val('SELECT isbn_original FROM livros WHERE id = ?', [$soObra]) === null && $val('SELECT origem_cadastro FROM livros WHERE id = ?', [$soObra]) === 'inventario'
    && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$soObra]) === null);
confere('nenhum exemplar, nenhum código BDP reservado, nenhuma vaga no lote', $num('SELECT COUNT(*) FROM exemplares') === $exAntes && $num('SELECT COUNT(*) FROM exemplar_codigo_reservas') === $reservas && $vagas() === 0);
confere('campos ausentes não são inventados (editora, ano e edição vazios)', db_query('SELECT editora IS NULL AND ano_publicacao IS NULL AND edicao IS NULL FROM livros WHERE id = ?', [$soObra])->fetchColumn() == 1);

echo "\n4. Clique duplo\n";
[$s, , $locDup] = $bib->ir($pg, $postObra);
confere('o mesmo formulário de novo não cria outra obra', $s === 303 && $num("SELECT COUNT(*) FROM livros WHERE titulo = 'Crônicas Fictícias Só Obra'") === 1 && str_contains((string)$locDup, 'obra=' . $soObra));

echo "\n5. Salvar, verificar e registrar exemplar\n";
[, $html] = $bib->ir($pg . '&nova_obra=1');
preg_match('/name="token" value="([a-f0-9]+)"/', $html, $m);
[$s, , $locReg] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'nova_obra', 'token' => $m[1] ?? '', 'editar_livro' => '1',
    'titulo' => 'Lendas Fictícias do Sertão', 'autores' => 'Rocha, Davi Fictício', 'isbn' => '', 'salvar' => 'registrar']);
$lendas = (int)$val("SELECT id FROM livros WHERE titulo = 'Lendas Fictícias do Sertão'");
confere('obra verificada e o painel "Registrar exemplar" abre em seguida', $s === 303 && str_contains((string)$locReg, 'obra=' . $lendas) && str_contains((string)$locReg, 'painel=registrar')
    && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$lendas]) !== null && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$lendas]) === 0);
[$s, $html] = $bib->ir($pg . '&obra=' . $lendas . '&painel=registrar');
preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $mm);
confere('registro direto como cópia física, sem pedir linha da planilha; origem discreta na ficha', $s === 200 && str_contains($html, 'o exemplar é registrado direto como cópia física')
    && !str_contains($html, 'corresponde a qual linha da planilha') && str_contains($html, 'Cadastrada manualmente (conferência)') && ($mm[1] ?? '') !== '');
confere('a obra nova aparece na pesquisa do Inventário', in_array($lendas, array_map('intval', array_column((new InventarioRepository($pdo))->pesquisarLivros($inv, 'Lendas Sertão', 15, 1)['livros'], 'id')), true));

echo "\n6. Três cópias\n";
$codigos = [];
$proc = $mm[1] ?? '';
for ($i = 1; $i <= 3; $i++) {
    if ($i > 1) {
        [, $html] = $bib->ir($pg . '&obra=' . $lendas . '&painel=registrar&copia=1');
        preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $mm);
        $proc = $mm[1] ?? '';
    }
    [, $json] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $lendas, 'processo' => $proc]);
    $cod = json_decode($json, true)['codigo'] ?? '';
    [$s, , $l] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $lendas, 'codigo_bdp' => $cod, 'processo_bdp' => $proc,
        'estado_conservacao' => 'bom', 'localizacao_id' => $loc, 'situacao_inventario' => 'encontrado']);
    $codigos[] = $cod;
    confere("cópia $i registrada ($cod)", $s === 303 && str_contains((string)$l, 'criados='), "$s $l");
}
confere('três exemplares com três códigos BDP diferentes', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$lendas]) === 3 && count(array_unique(array_filter($codigos))) === 3);
confere('três vagas usadas no lote ativo (a obra em si não ocupou vaga)', $vagas() === 3 && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND lote_id = ?', [$lendas, $lote]) === 3);
confere('nenhum vínculo com registros importados', $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ?', [$lendas]) === 0);
confere('exemplares encontrados neste inventário', $num('SELECT COUNT(*) FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id WHERE i.inventario_id = ? AND e.livro_id = ? AND i.conferido_em IS NOT NULL', [$inv, $lendas]) === 3);
[$s, $html] = $bib->ir('admin/planilha-bdp.php?lote=' . $lote);
confere('os códigos aparecem na Planilha BDP do lote', $s === 200 && str_contains($html, $codigos[0]) && str_contains($html, $codigos[2]));
[$s, $html] = $bib->ir(ltrim((string)parse_url($l, PHP_URL_PATH), '/') . '?' . parse_url($l, PHP_URL_QUERY));
confere('depois de salvar: "Registrar outra cópia" e "Próximo livro"; a ficha lista as cópias', $s === 200 && str_contains($html, 'Registrar outra cópia') && str_contains($html, 'Próximo livro') && str_contains($html, $codigos[0]) && str_contains($html, $codigos[1]));

echo "\n7. Capacidade do lote\n";
[, $html] = $bib->ir($pg . '&obra=' . $lendas . '&painel=registrar&copia=1');
preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $mm);
[, $json] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $lendas, 'processo' => $mm[1] ?? '']);
[$s] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $lendas, 'codigo_bdp' => json_decode($json, true)['codigo'] ?? '', 'processo_bdp' => $mm[1] ?? '',
    'estado_conservacao' => 'bom', 'localizacao_id' => $loc, 'situacao_inventario' => 'encontrado']);
confere('a 4ª cópia num lote de 3 vagas é recusada', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$lendas]) === 3 && $vagas() === 3);

echo "\n8. Salvar e marcar dados como verificados\n";
[, $html] = $bib->ir($pg . '&nova_obra=1');
preg_match('/name="token" value="([a-f0-9]+)"/', $html, $m);
[$s, , $l] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'nova_obra', 'token' => $m[1] ?? '', 'editar_livro' => '1', 'titulo' => 'Poemas Fictícios Verificados', 'cdd' => '869.1', 'salvar' => 'verificar']);
$poemas = (int)$val("SELECT id FROM livros WHERE titulo = 'Poemas Fictícios Verificados'");
confere('obra verificada, sem exemplar, com a CDD informada', $s === 303 && !str_contains((string)$l, 'painel=registrar') && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$poemas]) !== null
    && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$poemas]) === 0 && $val('SELECT cdd_aprovada FROM classificacoes_cdd WHERE livro_id = ?', [$poemas]) === '869.1');

echo "\n9. Origem: acervo e relatórios\n";
$rel = new RelatorioRepository($pdo);
confere('relatório de acervo filtra por origem (conferência × planilha)', $rel->contarLivros(['origem' => 'inventario']) === 4 && $rel->contarLivros(['origem' => 'planilha']) === 1 && $rel->contarLivros(['origem' => 'manual']) === 4);
confere('relatório de exemplares filtra pela origem da obra', $rel->contarExemplares(['origem' => 'inventario']) === 3 && $rel->contarExemplares(['origem' => 'planilha']) === 0);
confere('obra sem exemplar entra em "Sem exemplar físico" (não fica disponível para empréstimo)', $rel->contarLivros(['origem' => 'inventario', 'pendencia' => 'sem_exemplar']) === 3);
[$s, $html] = $bib->ir('admin/relatorios.php?secao=acervo&origem=inventario');
confere('página de relatórios: filtro, coluna e indicador de origem', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'name="origem"') && str_contains($html, 'Cadastrada manualmente (conferência)')
    && str_contains($html, 'Cadastradas manualmente') && str_contains($html, 'Lendas Fictícias do Sertão'), json_encode([$s, str_contains($html, 'name="origem"'), str_contains($html, 'Cadastrada manualmente (conferência)'), str_contains($html, 'Cadastradas manualmente'), str_contains($html, 'Lendas Fictícias do Sertão'), str_contains($html, '>Contos Fictícios da Serra<')]));
[$s, $csv] = $bib->ir('admin/relatorios.php?secao=exemplares&origem=inventario&formato=csv&tabela=exemplares');
confere('exportação dos exemplares traz a origem da obra', $s === 200 && str_contains($csv, 'Origem da obra') && str_contains($csv, $codigos[0]), substr($csv, 0, 200));
[$s, $html] = $bib->ir('admin/livros.php?origem=inventario');
confere('lista de livros: filtro por origem e etiqueta "Conferência"', $s === 200 && str_contains($html, 'Lendas Fictícias do Sertão') && str_contains($html, '>Conferência<') && !str_contains($html, 'Crônicas Fictícias Só Obra') === false, json_encode([$s, str_contains($html, 'Lendas Fictícias do Sertão'), str_contains($html, '>Conferência<'), str_contains($html, 'Contos Fictícios da Serra</')]));

echo "\n10. Permissões\n";
$aux = new TesteNavegador($base);
$aux->entrar('aux@teste.br', $senha);
[$s, $html] = $aux->ir($pg . '&nova_obra=1');
preg_match('/name="token" value="([a-f0-9]+)"/', $html, $m);
confere('auxiliar (edita livros) também usa as três ações', $s === 200 && str_contains($html, 'value="obra"') && str_contains($html, 'value="registrar"'));
$cons = new TesteNavegador($base);
$cons->entrar('cons@teste.br', $senha);
[$s] = $cons->ir($pg, ['csrf' => $cons->csrf('admin/perfil.php'), 'id' => $inv, 'acao' => 'nova_obra', 'token' => 'x', 'titulo' => 'Obra Consulta']);
confere('perfil consulta não cadastra (403)', $s === 403);

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
