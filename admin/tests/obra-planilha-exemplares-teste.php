<?php
/**
 * Testes da relação obra × exemplar × planilha (migração 170): a linha da planilha descreve a OBRA
 * e vale para quantas cópias forem encontradas.
 *
 * Uso: php admin/tests/obra-planilha-exemplares-teste.php
 *
 * Bancos temporários "bdp_teste_obraplan_XXXX" (apagados no final), dados FICTÍCIOS. Cenários:
 *    1. migração 170 sobre dados antigos (linhas de obras já encontradas viram "Obra localizada");
 *    2. cinco exemplares de uma única linha da planilha;
 *    3. outra cópia sem selecionar a linha de novo (pela página);
 *    4. mais exemplares do que a quantidade histórica (divergência só informativa);
 *    5. obra fora da planilha, sem ISBN e sem exemplar;
 *    6. vários exemplares de uma obra cadastrada manualmente;
 *    7. códigos BDP únicos;
 *    8. vagas do lote (só exemplares ocupam vaga; capacidade preservada);
 *    9. "Cadastrar livro não listado" (o "Adicionar livro") continua funcionando;
 *   10. "+ Adicionar obra" funciona de forma independente;
 *   11. indicadores da ficha da obra;
 *   12. consulta dos registros originais (Referências da planilha);
 *   13. vínculos antigos preservados;
 *   14. situações separadas (associação, verificação, exemplares, conferência física);
 *   15. relatórios: registros, obras e exemplares contados separadamente;
 *   16. celular: ficha sem rolagem lateral (Chromium, quando disponível).
 */

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\PendenciasRepository;
use Biblioteca\Repositories\RelatorioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\ConferenciaCadastroService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LoteService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('obraplan', ['STORAGE_IMPORTACOES' => sys_get_temp_dir() . '/bdp-obraplan-' . bin2hex(random_bytes(3))]);
$_SERVER['SCRIPT_NAME'] = '/admin/tests/obra-planilha-exemplares-teste.php';
require __DIR__ . '/../includes/importacao.php';
if (!function_exists('inventario_item_situacao')) {
    require __DIR__ . '/../includes/acervo-fisico.php';
}
$pdo = db();
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$val = fn (string $sql, array $p = []) => db_query($sql, $p)->fetchColumn();
$recusa = function (callable $fn): ?string {
    try {
        $fn();
    } catch (AcervoException | RuntimeException | PDOException $e) {
        return $e->getMessage();
    }
    return null;
};
$rodar = function (array $cmd, array $envFilho): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envFilho);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), trim($out)];
};

// ---------------------------------------------------------------
echo "\n1. Migração 170 sobre dados antigos\n";
$b160 = $banco . '_v160';
$raiz->exec("CREATE DATABASE `$b160` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$GLOBALS['teste_bancos'][] = $b160;
$env160 = array_merge(getenv(), $env, ['DB_NAME' => $b160]);
$raiz->exec("CREATE TABLE `$b160`.schema_migracoes (arquivo VARCHAR(190) PRIMARY KEY, aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$raiz->exec("INSERT INTO `$b160`.schema_migracoes (arquivo) VALUES ('170_obra_planilha_exemplares.sql')");
$migrar = [PHP_BINARY, __DIR__ . '/../../database/migrar.php'];
[$cod, $out] = $rodar($migrar, $env160);
confere('banco antigo criado só até a migração 160', $cod === 0 && (int)$raiz->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$b160' AND table_name = 'inventario_itens' AND column_name = 'obra_localizada_em'")->fetchColumn() === 0, $out);
// Dados como a versão 160 gravava: a obra tem uma linha Pendente e uma cópia já Encontrada no inventário em andamento.
$q = fn (string $sql) => $raiz->exec(str_replace('{b}', "`$b160`", $sql));
$q("INSERT INTO {b}.administradores (nome, email, senha_hash, perfil) VALUES ('Conta Fictícia Antiga', 'antiga@teste.br', 'x', 'bibliotecaria')");
$admAntigo = (int)$raiz->query("SELECT id FROM `$b160`.administradores LIMIT 1")->fetchColumn();
$q("INSERT INTO {b}.livros (titulo, dados_verificados_em) VALUES ('Obra Antiga Fictícia', NOW()), ('Outra Obra Antiga Fictícia', NULL)");
$livA = (int)$raiz->query("SELECT id FROM `$b160`.livros WHERE titulo = 'Obra Antiga Fictícia'")->fetchColumn();
$livB = (int)$raiz->query("SELECT id FROM `$b160`.livros WHERE titulo = 'Outra Obra Antiga Fictícia'")->fetchColumn();
$q("INSERT INTO {b}.exemplares (livro_id, codigo_individual, situacao, estado_conservacao) VALUES ($livA, 'BDP-000050', 'disponivel', 'bom')");
$exA = (int)$raiz->query("SELECT id FROM `$b160`.exemplares LIMIT 1")->fetchColumn();
$q("INSERT INTO {b}.inventarios (titulo, data_inicio, responsavel_id, status, aberto_por) VALUES ('Inventário antigo fictício', CURDATE(), $admAntigo, 'em_andamento', $admAntigo)");
$invA = (int)$raiz->query("SELECT id FROM `$b160`.inventarios LIMIT 1")->fetchColumn();
$q("INSERT INTO {b}.inventario_itens (inventario_id, exemplar_id, livro_id, previsto, conferido_em, conferido_por) VALUES ($invA, $exA, $livA, 1, NOW(), $admAntigo)");
$q("INSERT INTO {b}.inventario_itens (inventario_id, exemplar_id, livro_id, previsto) VALUES ($invA, NULL, $livA, 1), ($invA, NULL, $livB, 1)");
$raiz->exec("DELETE FROM `$b160`.schema_migracoes WHERE arquivo = '170_obra_planilha_exemplares.sql'");
[$cod, $out] = $rodar($migrar, $env160);
confere('migração 170 aplicada pelo migrar.php sobre os dados antigos', $cod === 0 && str_contains($out, '170_obra_planilha_exemplares.sql... ok'), $out);
$q160 = fn (string $sql) => $raiz->query(str_replace('{b}', "`$b160`", $sql))->fetchColumn();
confere('linha de obra já encontrada passa a "Obra localizada"; a de obra sem cópia continua Pendente', $q160("SELECT COUNT(*) FROM {b}.inventario_itens WHERE livro_id = $livA AND exemplar_id IS NULL AND obra_localizada_em IS NOT NULL") == 1
    && $q160("SELECT COUNT(*) FROM {b}.inventario_itens WHERE livro_id = $livB AND obra_localizada_em IS NULL") == 1);
confere('nada apagado e códigos BDP inalterados', (int)$q160('SELECT COUNT(*) FROM {b}.inventario_itens') === 3 && $q160("SELECT codigo_individual FROM {b}.exemplares WHERE id = $exA") === 'BDP-000050');
[$cod, $out] = $rodar($migrar, $env160);
confere('rodar as migrações de novo não faz nada', $cod === 0 && str_contains($out, 'Nenhuma migração pendente'), $out);

// ---------------------------------------------------------------
// Dados fictícios do banco principal.
$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$pdo->exec("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Literatura Fictícia', 'E01', 'P01', 'LF-E01-P01')");
$loc = (int)$val("SELECT id FROM localizacoes WHERE codigo = 'LF-E01-P01'");

$repo = importacao_repo();
$cab = ['Localização', 'Título', 'Sobrenome/Prenome do Autor', 'Editora', 'ISBN', 'Qtd', 'Ano'];
$mapa = importacao_validador()->sugerirMapeamento($cab);
$imp = $repo->criar(['nome_arquivo' => 'acervo-obras.csv', 'identificador_arquivo' => hash('sha256', 'obraplan'), 'arquivo_armazenado' => 'o.csv.upload',
    'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => 2, 'colunas' => count($cab)]], 'aba' => 'CSV', 'usuario_id' => $bibId, 'total_linhas' => 0]);
$repo->iniciarAnalise($imp, 'CSV', 1, $cab, $mapa);
$repo->inserirLinhas($imp, [
    2 => array_combine($cab, ['A-01', 'DOM CASMURRO (FICTÍCIO)', 'Assis, Machado Fictício', 'Editora Onda', '', '3', '1999']),
    3 => array_combine($cab, ['B-01', 'Poemas Fictícios Sem Cópia', 'Lima, Bia Fictícia', 'Editora Verso', '', '2', '2005']),
], $mapa, 3);
$repo->concluirAnalise($imp);
$repo->importarTodos($imp, $bibId);
$regDom = db_query('SELECT * FROM registros_legados WHERE linha = 2')->fetch();
$dom = (int)$regDom['livro_id'];
$poemas = (int)$val('SELECT livro_id FROM registros_legados WHERE linha = 3');

$exServ = new ExemplarService($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);
$cad = new ConferenciaCadastroService($pdo, $invServ);
$codigos = new CodigoBdpService($pdo);
$inv = $invServ->criar(['titulo' => 'Inventário Fictício Obras e Cópias', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv, $bibId);
$lotes = new LoteService($pdo, $exServ);
$lote = $lotes->criar(['capacidade' => 12, 'usuarios' => [$bibId, $contas['auxiliar']], 'descricao' => 'Lote fictício de teste'], $bibId);
$lotes->escolherAtivo($lote, $bibId);
$noLote = fn () => $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$lote]);
$cad->atualizarLivro($dom, $cad->dados(['titulo' => 'Dom Casmurro (Fictício)', 'autores' => 'Assis, Joaquim Maria Machado de', 'editora' => 'Editora Onda', 'ano_publicacao' => '1999']), $bibId, false, true, $inv);
$registrar = function (int $livro, array $extra = []) use ($invServ, $codigos, $bibId, $inv, $loc): array {
    $proc = CodigoBdpService::novoProcesso();
    return $invServ->registrarExemplar($inv, $livro, $extra + ['codigo' => $codigos->reservar($bibId, $livro, $proc)['codigo'], 'processo_bdp' => $proc,
        'estado_conservacao' => 'bom', 'situacao_inventario' => 'encontrado', 'localizacao_id' => $loc], $bibId);
};

// ---------------------------------------------------------------
echo "\n2. Cinco exemplares de uma única linha da planilha\n";
$cinco = [];
for ($i = 0; $i < 5; $i++) {
    $cinco[] = $registrar($dom);
}
confere('as cinco cópias são gravadas, todas com a associação da linha 2', count($cinco) === 5 && count(array_filter($cinco, fn ($c) => $c['registro_linha'] === 2)) === 5);
confere('a linha não é "consumida": continua sem vínculo direto e única na obra', $val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regDom['id']]) === null && $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ?', [$dom]) === 1);
confere('nenhum dado da planilha foi alterado', $val('SELECT titulo_original FROM registros_legados WHERE id = ?', [$regDom['id']]) === 'DOM CASMURRO (FICTÍCIO)');

// ---------------------------------------------------------------
echo "\n3. Outra cópia sem selecionar a linha novamente\n";
$base = teste_servidor($env);
$bib = new TesteNavegador($base);
confere('bibliotecária entra no painel', $bib->entrar('bib@teste.br', $senha) === 200);
$pg = 'admin/inventario-conferencia.php?id=' . $inv;
$tk = $bib->csrf($pg);
[$s, $html] = $bib->ir($pg . '&obra=' . $dom . '&painel=registrar&copia=1');
preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $mm);
confere('"Registrar outra cópia": sem escolha de linha, com a associação só como informação', $s === 200 && preg_match('/type="radio" name="vinculo"/', $html) === 0
    && str_contains($html, 'Associação bibliográfica aproveitada automaticamente') && str_contains($html, 'Origem: linha 2'));
[, $json] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $dom, 'processo' => $mm[1] ?? '']);
$codSexto = json_decode($json, true)['codigo'] ?? '';
[$s, , $l] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $dom, 'codigo_bdp' => $codSexto, 'processo_bdp' => $mm[1] ?? '',
    'estado_conservacao' => 'regular', 'localizacao_id' => $loc, 'situacao_inventario' => 'encontrado']);
confere('a sexta cópia é gravada sem enviar linha nenhuma', $s === 303 && str_contains((string)$l, 'criados=') && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$dom]) === 6, "$s $l");
[$s, $html] = $bib->ir(ltrim((string)parse_url((string)$l, PHP_URL_PATH), '/') . '?' . parse_url((string)$l, PHP_URL_QUERY));
confere('depois de salvar, oferece de novo "Registrar outra cópia" e "Próximo livro"', $s === 200 && str_contains($html, 'Registrar outra cópia') && str_contains($html, 'Próximo livro') && str_contains($html, 'Associação da obra aproveitada (linha 2'));

// ---------------------------------------------------------------
echo "\n4. Mais exemplares do que a quantidade histórica\n";
$qt = (new PendenciasRepository($pdo))->quantidadeObra($dom, $inv);
confere('6 cópias para quantidade histórica 3: divergência informada, nada bloqueado', $qt['situacao'] === 'confiavel' && $qt['divergente'] && $qt['cadastrados'] === 6 && $qt['encontrados'] === 6 && (int)$qt['historica'] === 3, json_encode($qt));

// ---------------------------------------------------------------
echo "\n5. Obra fora da planilha, sem ISBN e sem exemplar\n";
$nova = $cad->cadastrarObra($inv, $cad->dados(['titulo' => 'Crônicas Fictícias da Vila', 'autores' => 'Prado, Eva Fictícia', 'isbn' => '']), $bibId, true);
$cronicas = (int)$nova['livro_id'];
confere('obra criada sem ISBN, sem exemplar e sem linha da planilha', $cronicas > 0 && $val('SELECT isbn FROM livros WHERE id = ?', [$cronicas]) === null
    && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$cronicas]) === 0 && $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ?', [$cronicas]) === 0, json_encode($nova));
[$s, $html] = $bib->ir($pg . '&obra=' . $cronicas);
confere('ficha mostra "Obra sem planilha" e nenhum exemplar registrado', $s === 200 && str_contains($html, 'Obra sem planilha') && str_contains($html, 'Nenhum registrado') && !str_contains($html, 'Referências da planilha'));

// ---------------------------------------------------------------
echo "\n6. Vários exemplares de uma obra cadastrada manualmente\n";
$copiasCron = [$registrar($cronicas), $registrar($cronicas), $registrar($cronicas, ['situacao_inventario' => 'pendente'])];
confere('três cópias da obra manual, sem referência de planilha', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$cronicas]) === 3 && !array_filter($copiasCron, fn ($c) => $c['registro_id'] !== null));

// ---------------------------------------------------------------
echo "\n7. Códigos BDP únicos\n";
$m = $recusa(fn () => $invServ->registrarExemplar($inv, $dom, ['codigo' => $cinco[0]['codigo'], 'situacao_inventario' => 'encontrado'], $bibId));
confere('código já usado é recusado', $m !== null && str_contains($m, 'já pertence a outro exemplar'), (string)$m);
confere('nenhum código repetido no banco', $num('SELECT COUNT(*) FROM exemplares') === $num('SELECT COUNT(DISTINCT codigo_individual) FROM exemplares'));

// ---------------------------------------------------------------
echo "\n8. Vagas do lote\n";
confere('só exemplares ocupam vaga (obras e linhas da planilha não)', $noLote() === $num('SELECT COUNT(*) FROM exemplares') && $noLote() === 9);
$livresLote = 12 - $noLote();
for ($i = 0; $i < $livresLote; $i++) {
    $registrar($cronicas);
}
$m = $recusa(fn () => $registrar($cronicas));
confere('capacidade do lote preservada: a cópia além da capacidade é recusada', $noLote() === 12 && $m !== null, (string)$m);
$lote2 = $lotes->criar(['capacidade' => 20, 'usuarios' => [$bibId], 'descricao' => 'Segundo lote fictício'], $bibId);
$lotes->escolherAtivo($lote2, $bibId);

// ---------------------------------------------------------------
echo "\n9. \"Cadastrar livro não listado\" (Adicionar livro) continua funcionando\n";
[$s, $html] = $bib->ir($pg);
confere('os dois botões aparecem: "Cadastrar livro não listado" e "+ Adicionar obra"', $s === 200 && str_contains($html, 'Cadastrar livro não listado') && str_contains($html, '+ Adicionar obra'));
$procNl = CodigoBdpService::novoProcesso();
$nl = $cad->cadastrarNaoListado($inv, $cad->dados(['titulo' => 'Livro Fictício Não Listado']), ['codigo' => $codigos->reservar($bibId, null, $procNl)['codigo'], 'processo_bdp' => $procNl, 'localizacao_id' => $loc], $bibId, null, true);
confere('cadastra a obra e o primeiro exemplar, como antes', $nl['novo_livro'] && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$nl['livro_id']]) === 1);

// ---------------------------------------------------------------
echo "\n10. \"+ Adicionar obra\" de forma independente\n";
[, $html] = $bib->ir($pg . '&nova_obra=1');
preg_match('/name="token" value="([a-f0-9]+)"/', $html, $mt);
[$s, , $l] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'nova_obra', 'token' => $mt[1] ?? '', 'editar_livro' => '1', 'titulo' => 'Contos Fictícios Independentes', 'salvar' => 'obra']);
$contos = (int)$val("SELECT id FROM livros WHERE titulo = 'Contos Fictícios Independentes'");
confere('"Salvar obra" cria só a obra (sem exemplar, sem vaga no lote)', $s === 303 && $contos > 0 && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$contos]) === 0
    && $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$lote2]) === 1, "$s $l");

// ---------------------------------------------------------------
echo "\n11. Indicadores da ficha da obra\n";
[$s, $html] = $bib->ir($pg . '&obra=' . $dom);
$chip = fn (string $rot, string $valor) => preg_match('/<strong>' . preg_quote($valor, '/') . '<\/strong> <span>' . preg_quote($rot, '/') . '<\/span>/u', $html) === 1;
confere('exemplares registrados, encontrados, registros, quantidade histórica e divergência', $s === 200 && teste_sem_erro_tecnico($html) && $chip('Exemplares registrados', '6') && $chip('Encontrados no inventário atual', '6')
    && $chip('Registros da planilha', '1') && $chip('Quantidade histórica', '3') && $chip('Divergência', '3'));
confere('origem e associação: "Origem: linha 2 da planilha. Associação bibliográfica confirmada."', str_contains($html, 'Origem: linha 2 da planilha.') && str_contains($html, 'Associação bibliográfica confirmada.'));
confere('ações rápidas: registrar exemplar, outra cópia, consultar exemplares, registrar dúvida', str_contains($html, 'Registrar exemplar') && str_contains($html, 'Registrar outra cópia')
    && str_contains($html, 'Consultar exemplares') && str_contains($html, 'Registrar dúvida') && str_contains($html, 'Corrigir dados da obra'));
confere('lista de exemplares com código, lote, carimbo, conservação, estante e empréstimo', str_contains($html, '<th>Lote</th>') && str_contains($html, '<th>Carimbo</th>') && str_contains($html, '<th>Estante / prateleira</th>')
    && str_contains($html, '<th>Empréstimo</th>') && str_contains($html, 'Lote ' . ExemplarService::numeroLote($val('SELECT numero FROM cadastro_lotes WHERE id = ?', [$lote]))) && str_contains($html, $cinco[4]['codigo']));
[$s, $html] = $bib->ir($pg . '&obra=' . $poemas);
confere('obra não verificada: o botão de verificar fica dentro do aviso', $s === 200 && preg_match('/class="adm-obra__aviso".*?Verificar e corrigir dados<\/a>/s', $html) === 1 && str_contains($html, 'A confirmar'));
// Muitos exemplares: a lista é paginada.
for ($i = 0; $i < 15; $i++) {
    $registrar($dom);
}
[$s, $html] = $bib->ir($pg . '&obra=' . $dom);
[$s2, $html2] = $bib->ir($pg . '&obra=' . $dom . '&expag=2');
confere('com 21 exemplares a lista mostra 20 por página e tem a página 2', $s === 200 && substr_count($html, 'data-label="Código BDP"') === 20 && str_contains($html, 'aria-label="Páginas de exemplares"')
    && $s2 === 200 && substr_count($html2, 'data-label="Código BDP"') === 1);

// ---------------------------------------------------------------
echo "\n12. Consulta dos registros originais\n";
confere('"Referências da planilha" recolhível: linha, arquivo, original × atual (autor corrigido destacado; só maiúsculas não conta)', str_contains($html, 'Referências da planilha') && preg_match('/<details class="adm-card adm-panel adm-referencias"/', $html) === 1
    && str_contains($html, '<strong>Linha 2</strong>') && str_contains($html, 'acervo-obras.csv') && str_contains($html, 'Planilha original') && str_contains($html, 'DOM CASMURRO (FICTÍCIO)')
    && str_contains($html, 'Dom Casmurro (Fictício)') && preg_match('/<tr class="is-alterado"><th scope="row">Autor<\/th>/', $html) === 1
    && preg_match('/<tr class="is-alterado"><th scope="row">Título<\/th>/', $html) === 0, json_encode([str_contains($html, 'Referências da planilha'), preg_match('/<details class="adm-card adm-panel adm-referencias"/', $html),
    str_contains($html, '<strong>Linha 2</strong>'), str_contains($html, 'acervo-obras.csv'), str_contains($html, 'Planilha original'), str_contains($html, 'DOM CASMURRO (FICTÍCIO)'), str_contains($html, 'Dom Casmurro (Fictício)'), str_contains($html, 'is-alterado')]));

// ---------------------------------------------------------------
echo "\n13. Vínculos antigos preservados\n";
// Como a versão 130 gravava: a linha da Poemas apontava para uma cópia.
$cad->atualizarLivro($poemas, $cad->dados(['titulo' => 'Poemas Fictícios Sem Cópia', 'autores' => 'Lima, Bia Fictícia']), $bibId, false, true, $inv);
$exPoema = $registrar($poemas);
$regPoema = (int)$val('SELECT id FROM registros_legados WHERE linha = 3');
$pdo->prepare('UPDATE registros_legados SET exemplar_id = ?, vinculado_em = NOW(), vinculado_por = ? WHERE id = ?')->execute([$exPoema['id'], $bibId, $regPoema]);
$registrar($poemas);
$registrar($poemas);
confere('registrar mais cópias não mexe no vínculo antigo', (int)$val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regPoema]) === $exPoema['id'] && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$poemas]) === 3);
[$s, $html] = $bib->ir($pg . '&obra=' . $poemas);
confere('a ficha mostra o vínculo antigo como histórico, com "Ligada por engano?"', $s === 200 && str_contains($html, 'Vínculo antigo: ' . $exPoema['codigo']) && str_contains($html, 'Ligada por engano?'));

// ---------------------------------------------------------------
echo "\n14. Situações separadas\n";
$linhaItem = db_query('SELECT * FROM inventario_itens WHERE inventario_id = ? AND livro_id = ? AND exemplar_id IS NULL', [$inv, $dom])->fetch();
$r = $invRepo->resumo($inv);
confere('a linha da obra encontrada fica "Obra localizada": não é exemplar conferido nem cópia ausente', $linhaItem && $linhaItem['conferido_em'] === null && $linhaItem['obra_localizada_em'] !== null
    && $linhaItem['nao_localizado_em'] === null && inventario_item_situacao($linhaItem + ['emprestimo_aberto' => null])[0] === 'Obra localizada');
confere('"Encontrados" conta só exemplares; a linha sai dos pendentes', $r['encontrados'] === $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id IS NOT NULL AND conferido_em IS NOT NULL', [$inv])
    && $invRepo->itens($inv, 'a_conferir', 'Dom Casmurro')['total'] === 0 && $invRepo->itens($inv, 'obra_localizada')['total'] >= 1);
confere('as quatro situações aparecem na ficha', str_contains($html, '<span>Associação</span>') && str_contains($html, '<span>Verificação</span>') && str_contains($html, '<span>Exemplares</span>') && str_contains($html, '<span>Conferência física</span>'));
$invServ->cancelar($inv, $bibId, 'Teste fictício: inventário novo');
$inv2 = $invServ->criar(['titulo' => 'Segundo Inventário Fictício', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv2, $bibId);
confere('inventário novo: obra com cópias não traz a linha de volta (sem "cópia ausente")', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND livro_id = ? AND exemplar_id IS NULL', [$inv2, $dom]) === 0
    && $invServ->registrosSemExemplarFaltando($inv2) === 0);

// ---------------------------------------------------------------
echo "\n15. Relatórios\n";
$a = (new RelatorioRepository($pdo))->resumoAcervo();
confere('registros originais, associados, obras verificadas e exemplares contados separadamente', $a['registros_originais'] === 2 && $a['registros_associados'] === 2 && $a['registros_com_exemplar'] === 2
    && $a['exemplares_registrados'] === $num("SELECT COUNT(*) FROM exemplares WHERE situacao NOT IN ('baixado', 'extraviado')") && $a['total'] === $num('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL')
    && $a['verificadas'] === $num('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL AND dados_verificados_em IS NOT NULL') && $a['divergencias_quantidade'] === 2, json_encode(array_intersect_key($a, array_flip(['registros_originais', 'registros_associados', 'registros_com_exemplar', 'exemplares_registrados', 'exemplares_encontrados', 'total', 'verificadas', 'divergencias_quantidade']))));
confere('registros não são somados como exemplares nem exemplares como obras', $a['exemplares_registrados'] > $a['registros_originais'] && $a['total'] < $a['exemplares_registrados']);
[$s, $html] = $bib->ir('admin/relatorios.php?secao=acervo');
confere('página de relatórios mostra os novos indicadores', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Registros originais da planilha') && str_contains($html, 'Registros associados')
    && str_contains($html, 'Exemplares registrados') && str_contains($html, 'Exemplares encontrados') && str_contains($html, 'Divergências de quantidade') && str_contains($html, 'Obras verificadas'));

// ---------------------------------------------------------------
echo "\n16. Celular\n";
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$temPw = $node !== '' && trim((string)shell_exec(escapeshellarg($node) . " -e \"require('playwright');console.log('ok')\" 2>/dev/null")) === 'ok';
if (!$temPw) {
    echo "  (pulado) Node.js com Playwright não está instalado: a verificação no celular não foi executada.\n";
} else {
    $script = sys_get_temp_dir() . '/bdp-obraplan-celular-' . bin2hex(random_bytes(3)) . '.js';
    $GLOBALS['teste_temporarios'][] = $script;
    $shot = sys_get_temp_dir() . '/bdp-obraplan-celular.png';
    file_put_contents($script, <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const [url, cookies, shot] = [process.argv[2], JSON.parse(process.argv[3]), process.argv[4]];
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const u = new URL(url);
  await ctx.addCookies(Object.entries(cookies).map(([name, value]) => ({ name, value: String(value), domain: u.hostname, path: '/' })));
  const page = await ctx.newPage();
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.evaluate(() => { const d = document.querySelector('#registros'); if (d) d.open = true; });
  const r = await page.evaluate(() => ({
    largura: document.documentElement.scrollWidth, tela: window.innerWidth, url: location.href,
    indicadores: !!document.querySelector('.adm-obra-indicadores'), tabela: document.querySelector('.adm-exemplares-tabela thead') ? getComputedStyle(document.querySelector('.adm-exemplares-tabela thead')).display : 'ausente',
  }));
  await page.screenshot({ path: shot, fullPage: true });
  console.log(JSON.stringify(r));
  await browser.close();
})().catch(e => { console.log(JSON.stringify({ erro: String(e) })); process.exit(0); });
JS);
    $out = trim((string)shell_exec(implode(' ', array_map('escapeshellarg', [$node, $script, $base . 'admin/inventario-conferencia.php?id=' . $inv2 . '&obra=' . $dom, json_encode($bib->cookies), $shot])) . ' 2>&1'));
    $r = json_decode((string)substr($out, (int)strrpos($out, '{')), true) ?: [];
    confere('ficha da obra a 390 px: sem rolagem lateral, indicadores visíveis e exemplares em cartões', ($r['largura'] ?? 9999) <= ($r['tela'] ?? 0) && !empty($r['indicadores']) && ($r['tabela'] ?? '') === 'none', $out);
}

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
