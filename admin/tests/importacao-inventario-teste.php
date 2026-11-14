<?php
/**
 * Testes da importação sem obrigatoriedade + conferência e correção no Inventário (migração 110).
 *
 * Uso: php admin/tests/importacao-inventario-teste.php
 *
 * Tudo acontece num banco temporário "bdp_teste_impinv_XXXX" (migrações aplicadas só nele, apagado
 * no final), com dados FICTÍCIOS. Cobre:
 *   * importação de linhas incompletas, com ISBN inválido, possíveis duplicidades e sem nenhum dado
 *     bibliográfico: tudo é importado, os valores originais e a linha da planilha são preservados,
 *     os alertas ficam ligados a cada livro e nada é inventado;
 *   * a pré-visualização (sem alertas, incompletos, inconsistências, duplicidades);
 *   * uma só transação: uma falha técnica no meio não deixa nada gravado;
 *   * aviso de planilha repetida (confirmação, sem bloquear, mesclar ou apagar);
 *   * os registros entram Pendentes no inventário em andamento, nenhum como encontrado;
 *   * "Salvar e marcar como encontrado" com o código BDP do carimbo (único), clique duplo,
 *     correção dos dados e dos alertas na mesma transação;
 *   * "+ Cadastrar livro não listado" (todos os campos vazios, correspondências, outro exemplar,
 *     token de uso único);
 *   * finalizar estante/setor: marcar não localizados em lote (com confirmação) e voltar para pendente;
 *   * páginas do painel sem erro técnico e permissões.
 */
require __DIR__ . '/suporte.php';

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ConferenciaCadastroService;
use Biblioteca\Services\ConferenciaDuplicadaException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;

[$raiz, $banco, $env] = teste_banco_temporario('impinv', ['STORAGE_IMPORTACOES' => sys_get_temp_dir() . '/bdp-impinv-' . bin2hex(random_bytes(3))]);
$_SERVER['SCRIPT_NAME'] = '/admin/tests/importacao-inventario-teste.php';
require __DIR__ . '/../includes/importacao.php';
$pdo = db();
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$val = fn (string $sql, array $p = []) => db_query($sql, $p)->fetchColumn();
$recusa = function (callable $fn): ?string {
    try {
        $fn();
    } catch (AcervoException | RuntimeException $e) {
        return $e->getMessage();
    }
    return null;
};

$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$repo = importacao_repo();
$exServ = new ExemplarService($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);
$cad = new ConferenciaCadastroService($pdo, $invServ);

/** Cria uma importação analisada (ainda não gravada) com as linhas dadas. */
$importacao = function (string $nomeArquivo, string $hashArquivo, array $linhas) use ($repo, $bibId): int {
    $cab = ['Localização', 'Título', 'Sobrenome/Prenome do Autor', 'Editora', 'ISBN', 'Qtd', 'Ano'];
    $mapa = importacao_validador()->sugerirMapeamento($cab);
    $id = $repo->criar(['nome_arquivo' => $nomeArquivo, 'identificador_arquivo' => $hashArquivo, 'arquivo_armazenado' => 'x.csv.upload',
        'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => count($linhas), 'colunas' => count($cab)]], 'aba' => 'CSV', 'usuario_id' => $bibId, 'total_linhas' => 0]);
    $repo->iniciarAnalise($id, 'CSV', 1, $cab, $mapa);
    $repo->inserirLinhas($id, array_map(fn ($l) => array_combine($cab, array_pad($l, count($cab), '')), $linhas), $mapa, max(array_keys($linhas)));
    $repo->concluirAnalise($id);
    return $id;
};

// ---------------------------------------------------------------
echo "\n1. Inventário em andamento antes da importação\n";
$loc = (int)$val('SELECT 1'); // garante conexão
$pdo->prepare("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Literatura Fictícia', 'E01', 'P01', 'LF-E01-P01')")->execute();
$localE01 = (int)$pdo->lastInsertId();
$inv = $invServ->criar(['titulo' => 'Inventário Fictício 2026', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
confere('inventário iniciado (acervo vazio)', $invServ->iniciar($inv, $bibId) === 0 && $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'em_andamento');

// ---------------------------------------------------------------
echo "\n2. Importação aceita tudo\n";
$linhas = [
    2 => ['A-01', 'Memórias Fictícias', 'Silva, Ana Fictícia', 'Editora Teste', '9788594452603', '2', '1999'],   // completa
    3 => ['A-01', '', 'Souza, Bruno Fictício', 'Editora Teste', '', '1', ''],                                    // sem título
    4 => ['A-02', 'Contos Inventados', '', '', '85-359-03282-8', '1', ''],                                       // ISBN inválido, sem autor
    5 => ['A-02', '', '', '', '', '', ''],                                                                       // só a localização
    6 => ['A-03', 'Poemas de Teste', 'Lima, Carla Fictícia', 'Editora Teste', '', '1', 'mil novecentos'],         // ano inválido
    7 => ['A-03', 'Poemas de Teste', 'LIMA, Carla Fictícia', 'Outra Editora', '', '1', ''],                       // possível duplicidade
    8 => ['A-04', 'Memórias Fictícias', 'Silva, Ana Fictícia', 'Editora Teste', '9788594452603', '2', '1999'],   // linha idêntica à 2
];
$imp1 = $importacao('acervo-ficticio.csv', hash('sha256', 'arquivo-1'), $linhas);
$previa = $repo->previa($imp1);
confere('pré-visualização conta todas as linhas', $previa['total'] === 7, json_encode($previa));
confere('pré-visualização separa sem alertas, incompletos, inconsistências e duplicidades',
    $previa['sem_alertas'] >= 1 && $previa['incompletos'] >= 3 && $previa['inconsistencias'] >= 2 && $previa['duplicidades'] >= 2, json_encode($previa));
confere('nenhuma linha fica com erro bloqueante', $num("SELECT COUNT(*) FROM importacao_itens WHERE importacao_id = ? AND status_validacao = 'erro'", [$imp1]) === 0);

// Falha técnica no meio: nada pode ficar gravado.
$pdo->exec("CREATE TRIGGER teste_falha_tecnica BEFORE INSERT ON registros_legados FOR EACH ROW BEGIN IF NEW.linha = 6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falha técnica simulada'; END IF; END");
$falhou = null;
try {
    $repo->importarTodos($imp1, $bibId);
} catch (Throwable $e) {
    $falhou = $e->getMessage();
}
$pdo->exec('DROP TRIGGER teste_falha_tecnica');
confere('falha técnica no meio: a importação inteira é desfeita (uma só transação)', $falhou !== null && $num('SELECT COUNT(*) FROM livros') === 0
    && $num('SELECT COUNT(*) FROM livro_pendencias') === 0 && $num('SELECT COUNT(*) FROM inventario_itens') === 0
    && $num('SELECT COUNT(*) FROM importacao_itens WHERE importacao_id = ? AND processado_em IS NOT NULL', [$imp1]) === 0, (string)$falhou);
confere('depois da falha a importação continua pronta para tentar de novo', in_array($repo->buscar($imp1)['status'], ImportacaoRepository_status_editaveis(), true));

$r = $repo->importarTodos($imp1, $bibId);
confere('"Importar todos": as 7 linhas viram 7 livros (nada mesclado, nem a linha idêntica)', $r['criados'] === 7 && $num('SELECT COUNT(*) FROM livros') === 7, json_encode($r));
confere('importação concluída e ligada ao inventário em andamento', $r['status'] === 'concluida' && (int)$r['inventario_id'] === $inv && $r['no_inventario'] === 7, json_encode($r));
confere('nenhum exemplar nem código BDP criado na importação', $num('SELECT COUNT(*) FROM exemplares') === 0);
$item = fn (int $linha) => $repo->itemPorLinha($imp1, $linha);
$livroDa = fn (int $linha) => db_query('SELECT * FROM livros WHERE id = ?', [$item($linha)['livro_resultante_id']])->fetch();
confere('livro sem título fica com título vazio (NULL), sem inventar', $livroDa(3)['titulo'] === null);
confere('linha só com localização vira livro sem dados (NULL), com a referência da linha', $livroDa(5)['titulo'] === null && $livroDa(5)['isbn'] === null
    && $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ? AND linha = 5 AND localizacao_antiga = ?', [$livroDa(5)['id'], 'A-02']) === 1);
confere('ISBN inválido guardado como veio (isbn_original) e ISBN oficial vazio', $livroDa(4)['isbn'] === null && $livroDa(4)['isbn_original'] === '85-359-03282-8');
confere('ISBN repetido permitido em dois livros ativos', $num("SELECT COUNT(*) FROM livros WHERE isbn = '9788594452603' AND arquivado_em IS NULL") === 2);
confere('dados originais da planilha preservados', $item(6)['dados_originais_json']['Ano'] === 'mil novecentos');
$alertas = fn (int $livroId) => array_column(db_query('SELECT codigo FROM livro_pendencias WHERE livro_id = ? AND resolvida_em IS NULL', [$livroId])->fetchAll(), 'codigo');
confere('alertas ligados aos livros: sem título, ISBN inválido, sem dados, ano inválido, duplicidade',
    in_array('sem_titulo', $alertas($livroDa(3)['id']), true) && in_array('isbn_invalido', $alertas($livroDa(4)['id']), true)
    && in_array('sem_dados_bibliograficos', $alertas($livroDa(5)['id']), true) && in_array('ano_invalido', $alertas($livroDa(6)['id']), true)
    && $num("SELECT COUNT(*) FROM livro_pendencias WHERE tipo = 'duplicidade'") >= 2);
confere('alertas guardam a linha da planilha de origem', $num('SELECT COUNT(*) FROM livro_pendencias WHERE importacao_item_id = ? AND mensagem LIKE ?', [$item(4)['id'], 'Linha 4 da planilha%']) >= 1);
$resumo = $invRepo->resumo($inv);
confere('todos entram Pendentes no inventário (nenhum encontrado automaticamente)', $resumo['itens'] === 7 && $resumo['a_conferir'] === 7 && $resumo['encontrados'] === 0 && $resumo['sem_codigo'] === 7);
confere('indicador de alertas cadastrais no inventário', $resumo['com_alertas'] >= 5 && $resumo['percentual'] === 0);
$r2 = $repo->importarTodos($imp1, $bibId);
confere('repetir o pedido não duplica nada', $num('SELECT COUNT(*) FROM livros') === 7 && $num('SELECT COUNT(*) FROM inventario_itens') === 7 && $r2['status'] === 'concluida');

// ---------------------------------------------------------------
echo "\n3. Planilha repetida: aviso, sem bloquear nem mesclar\n";
$imp2 = $importacao('acervo-ficticio.csv', hash('sha256', 'arquivo-1'), $linhas);
$rep = $repo->repeticoes($imp2);
confere('mesmo arquivo e mesmas linhas são detectados', $rep['repetida'] && $rep['arquivo'] !== null && $rep['conteudo'] !== null && $rep['linhas_ja_importadas'] === 7, json_encode($rep));
confere('sem confirmação, nada é gravado', $recusa(fn () => $repo->importarTodos($imp2, $bibId)) !== null && $num('SELECT COUNT(*) FROM livros') === 7);
$r3 = $repo->importarTodos($imp2, $bibId, true);
confere('com confirmação, importa de novo como registros próprios (sem mesclar nem apagar)', $r3['criados'] === 7 && $num('SELECT COUNT(*) FROM livros') === 14
    && $val('SELECT repeticao_confirmada_por FROM importacoes WHERE id = ?', [$imp2]) == $bibId);
$imp3 = $importacao('outro-nome.csv', hash('sha256', 'arquivo-2'), [2 => ['Z-09', 'Livro Novo Fictício', 'Rocha, Davi Fictício']]);
confere('planilha diferente não gera aviso', !$repo->repeticoes($imp3)['repetida']);
$repo->importarTodos($imp3, $bibId);

// ---------------------------------------------------------------
echo "\n4. Encontrar, corrigir e registrar o código BDP\n";
$itemDe = fn (int $livroId) => (int)$val('SELECT id FROM inventario_itens WHERE inventario_id = ? AND livro_id = ?', [$inv, $livroId]);
$l3 = (int)$livroDa(3)['id'];
$it3 = $itemDe($l3);
// Correção + encontrado na mesma transação, com o código do carimbo.
$res = $cad->emTransacao(function () use ($cad, $invServ, $l3, $it3, $bibId, $localE01) {
    $cad->atualizarLivro($l3, $cad->dados(['titulo' => 'Crônicas Fictícias', 'autores' => 'Souza, Bruno Fictício', 'isbn' => '', 'editora' => 'Editora Teste']), $bibId);
    return $invServ->encontrarRegistro($it3, ['codigo' => 'bdp 000123', 'estado_conservacao' => 'regular', 'localizacao_id' => $localE01], $bibId);
});
confere('"Salvar e marcar como encontrado": exemplar com o código do carimbo', $res['codigo'] === 'BDP-000123' && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual = 'BDP-000123' AND livro_id = ?", [$l3]) === 1);
$it = $invRepo->item($it3);
confere('item fica Encontrado, com local e estado', $it['conferido_em'] !== null && (int)$it['exemplar_id'] === $res['exemplar_id'] && (int)$it['localizacao_encontrada_id'] === $localE01 && $it['estado_encontrado'] === 'regular');
confere('cadastro corrigido e alerta "sem título" resolvido como corrigido', $val('SELECT titulo FROM livros WHERE id = ?', [$l3]) === 'Crônicas Fictícias'
    && !in_array('sem_titulo', $alertas($l3), true) && $num("SELECT COUNT(*) FROM livro_pendencias WHERE livro_id = ? AND codigo = 'sem_titulo' AND resolucao = 'corrigida'", [$l3]) === 1);
confere('a sequência BDP avança além do código digitado', $num('SELECT ultimo FROM exemplar_codigo_seq') >= 123);
$dup = null;
try {
    $invServ->encontrarRegistro($it3, ['codigo' => 'BDP-000124', 'estado_conservacao' => 'bom'], $bibId);
} catch (ConferenciaDuplicadaException $e) {
    $dup = $e;
}
confere('clique duplo: nada é gravado de novo', $dup !== null && $num('SELECT COUNT(*) FROM exemplares') === 1);
$l4 = (int)$livroDa(4)['id'];
$it4 = $itemDe($l4);
$msg = $recusa(fn () => $invServ->encontrarRegistro($it4, ['codigo' => 'BDP-000123', 'estado_conservacao' => 'bom'], $bibId));
confere('código BDP já usado é recusado, com mensagem clara', $msg !== null && str_contains($msg, 'BDP-000123') && $invRepo->item($it4)['conferido_em'] === null);
confere('código BDP incompleto é recusado', $recusa(fn () => $invServ->encontrarRegistro($it4, ['codigo' => 'BDP-12', 'estado_conservacao' => 'bom'], $bibId)) !== null);
$antesFalha = $val('SELECT titulo FROM livros WHERE id = ?', [$l4]);
$msg = $recusa(fn () => $cad->emTransacao(function () use ($cad, $invServ, $l4, $it4, $bibId) {
    $cad->atualizarLivro($l4, $cad->dados(['titulo' => 'Título que não pode ficar']), $bibId);
    return $invServ->encontrarRegistro($it4, ['codigo' => 'BDP-000123', 'estado_conservacao' => 'bom'], $bibId);
}));
confere('se a conferência falha, a correção do livro também é desfeita', $msg !== null && $val('SELECT titulo FROM livros WHERE id = ?', [$l4]) === $antesFalha);
$res4 = $invServ->encontrarRegistro($it4, ['codigo' => '', 'estado_conservacao' => 'bom'], $bibId);
confere('sem carimbo: o sistema usa o próximo código livre', $res4['codigo'] === 'BDP-000124');
confere('encontrado sem preencher nenhum dado bibliográfico', $invRepo->item($it4)['conferido_em'] !== null && in_array('isbn_invalido', $alertas($l4), true));
$cad->atualizarLivro($l4, $cad->dados(['titulo' => 'Contos Inventados', 'isbn' => '85-262-7986-6']), $bibId);
confere('ISBN corrigido resolve o alerta de ISBN inválido', !in_array('isbn_invalido', $alertas($l4), true) && $val('SELECT isbn FROM livros WHERE id = ?', [$l4]) === '8526279866' && $val('SELECT isbn_original FROM livros WHERE id = ?', [$l4]) === null);
$cad->atualizarLivro($l4, $cad->dados(['titulo' => 'Contos Inventados', 'isbn' => '85-262-7986-6']), $bibId, true);
confere('"alertas conferidos": os restantes ficam confirmados e não voltam', $alertas($l4) === [] && $val('SELECT status_validacao FROM livros WHERE id = ?', [$l4]) === 'validado'
    && $num("SELECT COUNT(*) FROM livro_pendencias WHERE livro_id = ? AND resolucao = 'confirmada'", [$l4]) >= 1);
confere('ano inválido digitado na correção é recusado com orientação', $recusa(fn () => $cad->dados(['ano_publicacao' => '19'])) !== null);
confere('alertas nunca são apagados (só resolvidos)', $recusa(function () use ($pdo) {
    try {
        $pdo->exec('DELETE FROM livro_pendencias LIMIT 1');
    } catch (PDOException $e) {
        throw new RuntimeException($e->getMessage());
    }
}) !== null);

// ---------------------------------------------------------------
echo "\n5. Livro não listado\n";
$antesLivros = $num('SELECT COUNT(*) FROM livros');
$vazio = $cad->dados([]);
$soObra = $cad->cadastrarNaoListado($inv, $vazio, ['codigo' => '', 'estado_conservacao' => 'bom'], $bibId);
confere('sem confirmar os dados: só a obra é cadastrada, "Dados não verificados" e sem exemplar', $soObra['novo_livro'] && $soObra['exemplar_id'] === null && !$soObra['verificado']
    && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$soObra['livro_id']]) === 0 && $num('SELECT COUNT(*) FROM livros') === $antesLivros + 1);
$antesLivros++;
$novo = $cad->cadastrarNaoListado($inv, $vazio, ['codigo' => 'BDP-000200', 'estado_conservacao' => 'bom', 'localizacao_id' => $localE01], $bibId, null, true);
$itNovo = $invRepo->item($novo['item_id']);
confere('todos os campos vazios: obra verificada, exemplar e item Encontrado criados juntos', $novo['novo_livro'] && $novo['verificado'] && $num('SELECT COUNT(*) FROM livros') === $antesLivros + 1
    && $novo['codigo'] === 'BDP-000200' && $itNovo['conferido_em'] !== null && (int)$itNovo['cadastrado_no_inventario'] === 1);
confere('livro não listado recebe os alertas de dados que faltam', in_array('sem_titulo', $alertas($novo['livro_id']), true) && in_array('sem_autor', $alertas($novo['livro_id']), true)
    && $val('SELECT origem_cadastro FROM livros WHERE id = ?', [$novo['livro_id']]) === 'inventario');
$corr = $cad->correspondencias($cad->dados(['titulo' => 'Memórias Fictícias', 'autores' => 'Silva, Ana Fictícia', 'isbn' => '9788594452603']));
confere('antes de cadastrar, mostra livros parecidos (mesmo ISBN primeiro)', count($corr) >= 2 && $corr[0]['motivo'] === 'Mesmo ISBN');
$msgNv = $recusa(fn () => $cad->cadastrarNaoListado($inv, $vazio, ['codigo' => 'BDP-000201', 'estado_conservacao' => 'bom'], $bibId, (int)$corr[0]['id']));
confere('outro exemplar de obra ainda não verificada é recusado (verificar primeiro)', $msgNv !== null && str_contains($msgNv, 'Verifique os dados da obra'));
$cad->atualizarLivro((int)$corr[0]['id'], $cad->dados(['titulo' => 'Memórias Fictícias', 'autores' => 'Silva, Ana Fictícia', 'isbn' => '9788594452603']), $bibId, false, true, $inv);
$msgNv = $recusa(fn () => $cad->cadastrarNaoListado($inv, $vazio, ['codigo' => '', 'estado_conservacao' => 'bom'], $bibId, (int)$corr[0]['id']));
confere('registrar exemplar exige o código BDP (gerado ou digitado)', $msgNv !== null && str_contains($msgNv, 'Gerar código BDP'));
$outro = $cad->cadastrarNaoListado($inv, $vazio, ['codigo' => 'BDP-000201', 'estado_conservacao' => 'bom'], $bibId, (int)$corr[0]['id']);
confere('permite registrar outro exemplar da mesma obra (sem criar livro)', !$outro['novo_livro'] && $outro['livro_id'] === (int)$corr[0]['id'] && $num('SELECT COUNT(*) FROM livros') === $antesLivros + 1);
confere('código BDP duplicado também é recusado aqui (e nada fica pela metade)', $recusa(fn () => $cad->cadastrarNaoListado($inv, $cad->dados(['titulo' => 'Repetido']), ['codigo' => 'BDP-000200', 'estado_conservacao' => 'bom'], $bibId, null, true)) !== null
    && $num('SELECT COUNT(*) FROM livros') === $antesLivros + 1);
$r = $invRepo->resumo($inv);
confere('contadores atualizados na hora', $r['encontrados'] === 4 && $r['cadastrados'] === 2 && $r['percentual'] > 0, json_encode(['enc' => $r['encontrados'], 'cad' => $r['cadastrados']]));

// ---------------------------------------------------------------
echo "\n6. Finalizar estante: não localizados e correção\n";
$pendA02 = $invRepo->itens($inv, 'a_conferir', '', 1, 0, ['antiga' => 'A-02']);
// A-02: linhas 4 e 5 das duas importações; a linha 4 da primeira já foi encontrada.
confere('lista só os pendentes de um local antigo da planilha', $pendA02['total'] === 3 && !array_filter($pendA02['itens'], fn ($i) => $i['conferido_em'] !== null), (string)$pendA02['total']);
$ids = array_map(fn ($i) => (int)$i['id'], $pendA02['itens']);
$n = $invServ->marcarNaoLocalizados($inv, $ids, 'estante A-02 conferida', $bibId);
$r = $invRepo->resumo($inv);
confere('marca em lote como não localizados', $n === 3 && $r['nao_localizados'] === 3);
confere('nada vira não localizado sozinho (os outros continuam pendentes)', $r['a_conferir'] === $r['itens'] - $r['encontrados'] - 3);
confere('marcar de novo não altera nada', $invServ->marcarNaoLocalizados($inv, $ids, '', $bibId) === 0);
$invServ->voltarPendente($ids[0], $bibId);
confere('"Voltar para pendente" desfaz a marcação', $invRepo->item($ids[0])['nao_localizado_em'] === null && $invRepo->resumo($inv)['nao_localizados'] === 2);
$invServ->encontrarRegistro($ids[1], ['codigo' => '', 'estado_conservacao' => 'bom'], $bibId);
confere('livro não localizado que aparece depois pode ser marcado como encontrado', $invRepo->item($ids[1])['conferido_em'] !== null && $invRepo->item($ids[1])['nao_localizado_em'] === null);
confere('desfazer "Encontrado" exige motivo', $recusa(fn () => $invServ->voltarPendente($it3, $bibId)) !== null);
$invServ->voltarPendente($it3, $bibId, 'conferido no livro errado (teste)');
confere('desfazer "Encontrado" volta a Pendente e mantém o exemplar e o código', $invRepo->item($it3)['conferido_em'] === null && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual = 'BDP-000123'") === 1);

// ---------------------------------------------------------------
echo "\n7. Inventário novo inclui os registros importados ainda sem exemplar\n";
$invServ->manterAusentes($inv, 'teste', $bibId);
foreach ($invRepo->itens($inv, 'pendentes', '', 1, 0)['itens'] as $p) {
    $invServ->decidir((int)$p['id'], 'manter_cadastro', 'teste', $bibId);
}
$invServ->concluir($inv, $bibId);
confere('inventário concluído fica no histórico', $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'concluido');
// Migração 130: cada linha da planilha é conferida individualmente (vínculo registros_legados.exemplar_id),
// e não mais "só se a obra não tiver nenhum exemplar".
$semExemplar = $num("SELECT COUNT(*) FROM registros_legados r JOIN livros l ON l.id = r.livro_id AND l.arquivado_em IS NULL
                     JOIN importacao_itens it ON it.id = r.importacao_item_id AND it.resultado_importacao <> 'vinculado' WHERE r.exemplar_id IS NULL");
$inv2 = $invServ->criar(['titulo' => 'Inventário Fictício 2027', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$prev = $invServ->iniciar($inv2, $bibId);
confere('ao iniciar: exemplares ativos + registros importados sem exemplar, todos Pendentes', $prev === $num("SELECT COUNT(*) FROM exemplares WHERE situacao NOT IN ('baixado', 'extraviado')") + $semExemplar
    && $invRepo->resumo($inv2)['encontrados'] === 0, "$prev / $semExemplar");

// ---------------------------------------------------------------
echo "\n8. Páginas do painel\n";
$base = teste_servidor($env);
$bib = new TesteNavegador($base);
$aux = new TesteNavegador($base);
$cons = new TesteNavegador($base);
confere('contas entram no painel', $bib->entrar('bib@teste.br', $senha) === 200 && $aux->entrar('aux@teste.br', $senha) === 200 && $cons->entrar('cons@teste.br', $senha) === 200);
$tk = $bib->csrf('admin/perfil.php');
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2);
confere('conferência mostra progresso, indicadores e "Cadastrar livro não listado"', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'dos exemplares deste inventário')
    && str_contains($html, 'Não localizados') && str_contains($html, 'Linhas sem vínculo físico') && str_contains($html, 'Cadastrar livro não listado'));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&q=' . rawurlencode('Poemas de Teste'));
confere('pesquisa por título mostra a obra, as linhas da planilha sem exemplar e "Abrir obra"', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'linha(s) sem exemplar') && str_contains($html, '#obra">Abrir</a>'));
$itPoema = (int)$val('SELECT i.id FROM inventario_itens i JOIN registros_legados r ON r.importacao_item_id = i.importacao_item_id WHERE i.inventario_id = ? AND r.linha = 6 ORDER BY i.id LIMIT 1', [$inv2]);
[$s, , $locItem] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&item=' . $itPoema);
$livroPoema = (int)$val('SELECT livro_id FROM inventario_itens WHERE id = ?', [$itPoema]);
confere('endereço antigo do registro (&item=) leva à ficha da obra', $s === 303 && str_contains((string)$locItem, 'obra=' . $livroPoema));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&obra=' . $livroPoema);
confere('ficha da obra: dados, "Dados não verificados", linhas da planilha e registro bloqueado até verificar', $s === 200 && teste_sem_erro_tecnico($html)
    && str_contains($html, 'Dados não verificados') && str_contains($html, 'Verificar e corrigir dados') && str_contains($html, '<strong>Linha 6</strong>')
    && str_contains($html, 'não conta como exemplar encontrado') && !str_contains($html, 'id="painel-registrar"'));
confere('painel de verificação com os dois botões e os alertas', str_contains($html, 'id="painel-verificar"') && str_contains($html, 'Salvar e marcar dados como verificados')
    && str_contains($html, 'Salvar alterações') && str_contains($html, 'alerta(s) cadastral(is)'));
$postVer = ['csrf' => $tk, 'id' => $inv2, 'acao' => 'verificar_obra', 'livro_id' => $livroPoema, 'editar_livro' => '1', 'titulo' => 'Poemas de Teste', 'autores' => 'Lima, Carla Fictícia', 'ano_publicacao' => '2001', 'isbn' => ''];
[$s] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, $postVer + ['marcar_verificado' => '0']);
confere('"Salvar alterações" grava sem marcar como verificado', $s === 303 && (int)$val('SELECT ano_publicacao FROM livros WHERE id = ?', [$livroPoema]) === 2001
    && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$livroPoema]) === null);
[$s] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, $postVer + ['marcar_verificado' => '1']);
confere('"Salvar e marcar dados como verificados" verifica a obra (ISBN pode ficar vazio)', $s === 303 && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$livroPoema]) !== null
    && $val('SELECT isbn FROM livros WHERE id = ?', [$livroPoema]) === null);
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&obra=' . $livroPoema . '&painel=registrar');
confere('obra verificada libera "+ Registrar exemplar" com "Gerar código BDP" e a escolha da linha da planilha', $s === 200 && str_contains($html, 'id="painel-registrar"') && str_contains($html, 'Gerar código BDP')
    && str_contains($html, 'Este exemplar corresponde a qual linha da planilha?') && str_contains($html, 'data-painel-auto'));
$postReg = ['csrf' => $tk, 'id' => $inv2, 'acao' => 'registrar_exemplar', 'livro_id' => $livroPoema, 'codigo_bdp' => 'BDP-000300', 'estado_conservacao' => 'bom',
    'localizacao_id' => (string)$localE01, 'situacao_inventario' => 'encontrado', 'item_id' => (string)$itPoema];
[$s] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, $postReg);
[$s2, $html2] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, $postReg);
confere('formulário grava o exemplar ligado à linha da planilha; o segundo envio não duplica', $s === 303 && $s2 === 200 && str_contains($html2, 'Nada foi gravado')
    && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual = 'BDP-000300'") === 1 && $invRepo->item($itPoema)['codigo_individual'] === 'BDP-000300'
    && $invRepo->item($itPoema)['conferido_em'] !== null, "$s/$s2 " . (preg_match('/Nada foi gravado.{0,400}/su', (string)$html2, $mm) ? strip_tags($mm[0]) : 'sem msg') . ' ' . json_encode($invRepo->item($itPoema)['codigo_individual']));
// Correção dos dados da obra depois de verificada: histórico e nova verificação.
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&q=' . rawurlencode('Poemas de Teste'));
confere('resultado da pesquisa tem "Abrir obra" e o status da obra', $s === 200 && str_contains($html, '&amp;obra=' . $livroPoema) && str_contains($html, 'Dados verificados'));
[$s, , $locCorr] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&corrigir=' . $livroPoema);
confere('endereço antigo "Corrigir dados" abre o painel de verificação da obra', $s === 303 && str_contains((string)$locCorr, 'painel=verificar'));
[$s] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, ['marcar_verificado' => '0', 'titulo' => 'Poemas de Teste Corrigido', 'editora' => 'Editora Fictícia'] + $postVer);
confere('alterar o título de uma obra verificada grava e volta para "Dados não verificados"', $s === 303 && $val('SELECT titulo FROM livros WHERE id = ?', [$livroPoema]) === 'Poemas de Teste Corrigido'
    && $val('SELECT editora FROM livros WHERE id = ?', [$livroPoema]) === 'Editora Fictícia' && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$livroPoema]) === null);
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&obra=' . $livroPoema);
confere('histórico da verificação mostra a alteração (antes e depois)', $s === 200 && str_contains($html, 'Histórico (') && str_contains($html, 'voltou para') && str_contains($html, 'Poemas de Teste Corrigido'));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&codigo=BDP-000300');
confere('exemplar já encontrado mostra a obra e o link "Ver obra e exemplares"', $s === 200 && str_contains($html, 'Ver obra e exemplares') && str_contains($html, '&amp;obra=' . $livroPoema));
[$s, $html] = $bib->ir('admin/inventario-detalhes.php?id=' . $inv2 . '&filtro=encontrados');
confere('lista de itens do inventário abre a obra e mostra o status dos dados', $s === 200 && str_contains($html, 'Abrir obra (verificar dados e exemplares)'));
// Pesquisa do inventário: uma palavra sem números não pode trazer todos os itens (antes "ISBN LIKE '%%'").
$todosItens = $invRepo->itens($inv2, 'todos', '', 1, 0)['total'];
$soPoemas = $invRepo->itens($inv2, 'todos', 'Poemas', 1, 0)['total'];
confere('lista do inventário: pesquisa por palavra filtra de verdade', $soPoemas >= 1 && $soPoemas < $todosItens, "$soPoemas de $todosItens");
confere('lista do inventário: pesquisa por código BDP traz só o exemplar', $invRepo->itens($inv2, 'todos', 'BDP-000300', 1, 0)['total'] === 1);
confere('pesquisa da conferência acha pela editora', (bool)array_filter($invRepo->pesquisarLivros($inv2, 'Editora Fictícia')['livros'], fn ($l) => (int)$l['id'] === $livroPoema));
[$s, $json] = $bib->ir('admin/api/inventario/sugestoes.php?id=' . $inv2 . '&q=' . rawurlencode('Poem'));
$sug = json_decode($json, true)['sugestoes'] ?? [];
confere('sugestões ao digitar: livro pelo começo do título', $s === 200 && (bool)array_filter($sug, fn ($x) => $x['tipo'] === 'livro' && $x['livro_id'] === $livroPoema), $json);
[$s, $json] = $bib->ir('admin/api/inventario/sugestoes.php?id=' . $inv2 . '&q=' . rawurlencode('bdp-0003'));
$sug = json_decode($json, true)['sugestoes'] ?? [];
confere('sugestões ao digitar: código BDP incompleto sugere os códigos (sem escolher sozinho)', $s === 200 && in_array('BDP-000300', array_column($sug, 'codigo'), true), $json);
[$s] = (new TesteNavegador($base))->ir('admin/api/inventario/sugestoes.php?id=' . $inv2 . '&q=Poem');
[$s2] = $cons->ir('admin/api/inventario/sugestoes.php?id=' . $inv2 . '&q=Poem');
confere('sugestões exigem login (401) e permissão de conferir (403)', $s === 401 && $s2 === 403, "$s / $s2");
[$s, $json] = $aux->ir('admin/api/acervo/sugestoes.php?q=' . rawurlencode('poem'));
$sug = json_decode($json, true)['sugestoes'] ?? [];
confere('pesquisa do topo: "poem" sugere o livro "Poemas de Teste..." pelos dados do acervo', $s === 200 && (bool)array_filter($sug, fn ($x) => $x['tipo'] === 'livro' && $x['livro_id'] === $livroPoema), $json);
[$s] = (new TesteNavegador($base))->ir('admin/api/acervo/sugestoes.php?q=poem');
confere('sugestões do topo exigem login (401)', $s === 401);
[$s, $html] = $bib->ir('admin/inventario-detalhes.php?id=' . $inv2);
confere('lista de itens e pesquisa do topo com sugestões ligadas', str_contains($html, 'data-sugestoes-modo="preencher"') && str_contains($html, 'id="topo-sugestoes"') && str_contains($html, 'assets/js/sugestoes.js'));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2);
confere('campo de pesquisa da conferência tem as sugestões ligadas', str_contains($html, 'data-sugestoes=') && str_contains($html, 'role="listbox"'));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&novo=1');
$token = preg_match('/name="token" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
confere('formulário de livro não listado com token de uso único', $s === 200 && $token !== '' && teste_sem_erro_tecnico($html));
$antes = $num('SELECT COUNT(*) FROM livros');
$postNovo = ['csrf' => $tk, 'id' => $inv2, 'acao' => 'nao_listado', 'token' => $token, 'editar_livro' => '1', 'titulo' => 'Memórias Fictícias', 'autores' => 'Silva, Ana Fictícia', 'codigo_bdp' => '', 'estado_conservacao' => 'bom', 'marcar_verificado' => '1'];
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, $postNovo);
confere('obras parecidas aparecem antes de gravar (nada gravado ainda), com "abrir esta obra"', $s === 200 && str_contains($html, 'obra(s) parecida(s)') && str_contains($html, 'É esta obra: abrir e registrar exemplar') && $num('SELECT COUNT(*) FROM livros') === $antes);
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, ['acao' => 'gerar_codigo', 'origem_form' => 'nao_listado', 'escolha' => 'novo'] + $postNovo);
$codigoNovo = preg_match('/name="codigo_bdp"[^>]*value="(BDP-\d{6})"/', $html, $m) ? $m[1] : '';
$postNovo['processo_bdp'] = preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $m) ? $m[1] : '';
confere('"Gerar código BDP" sem JavaScript volta com o código reservado preenchido', $s === 200 && $codigoNovo !== '' && str_contains($html, 'Código reservado para este formulário') && $num('SELECT COUNT(*) FROM livros') === $antes);
[$s] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, ['codigo_bdp' => $codigoNovo, 'escolha' => 'novo'] + $postNovo);
[$s2, , $loc2] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2, ['codigo_bdp' => $codigoNovo, 'escolha' => 'novo'] + $postNovo);
$livroMem = (int)$val('SELECT livro_id FROM exemplares WHERE codigo_individual = ?', [$codigoNovo]);
confere('"obra diferente" cadastra a obra verificada e o 1º exemplar; o clique duplo não cria outro', $s === 303 && $s2 === 303 && $num('SELECT COUNT(*) FROM livros') === $antes + 1
    && $livroMem > 0 && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$livroMem]) !== null && $val('SELECT usado_em FROM exemplar_codigo_reservas WHERE codigo = ?', [$codigoNovo]) !== null);
[$s, $html] = $bib->ir('admin/inventario-detalhes.php?id=' . $inv2 . '&fechar=' . rawurlencode('antiga:A-03'));
confere('detalhes: progresso, abas novas e "Finalizar estante ou setor"', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Finalizar estante ou setor')
    && str_contains($html, 'Marcar selecionados como não localizados') && str_contains($html, 'data-confirm='));
$pend = array_map(fn ($i) => (string)$i['id'], $invRepo->itens($inv2, 'a_conferir', '', 1, 0, ['antiga' => 'A-03'])['itens']);
[$s] = $bib->ir('admin/inventario-detalhes.php?id=' . $inv2, ['csrf' => $tk, 'id' => $inv2, 'acao' => 'marcar_nao_localizados', 'fechar' => 'antiga:A-03', 'itens' => $pend]);
confere('marcar em lote sem a confirmação não grava', $s === 303 && $invRepo->resumo($inv2)['nao_localizados'] === 0);
[$s] = $bib->ir('admin/inventario-detalhes.php?id=' . $inv2, ['csrf' => $tk, 'id' => $inv2, 'acao' => 'marcar_nao_localizados', 'fechar' => 'antiga:A-03', 'itens' => $pend, 'confirmar' => '1']);
confere('com a confirmação, marca os selecionados', $s === 303 && $invRepo->resumo($inv2)['nao_localizados'] === count($pend) && count($pend) > 0);
[$s, $html] = $bib->ir('admin/inventario-detalhes.php?id=' . $inv2 . '&filtro=nao_localizados');
confere('aba "Não localizados" com "Voltar para pendente"', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Voltar para pendente'));
[$s] = $aux->ir('admin/inventario-detalhes.php?id=' . $inv2, ['csrf' => $aux->csrf('admin/perfil.php'), 'id' => $inv2, 'acao' => 'marcar_nao_localizados', 'itens' => $pend, 'confirmar' => '1']);
confere('auxiliar não marca em lote (403)', $s === 403);
[$s] = $cons->ir('admin/inventario-conferencia.php?id=' . $inv2, ['csrf' => $cons->csrf('admin/perfil.php'), 'id' => $inv2, 'acao' => 'nao_listado', 'token' => 'x']);
confere('perfil consulta não usa a conferência (403)', $s === 403);
[$s, $csv] = $bib->ir('admin/inventario-relatorio.php?id=' . $inv2 . '&formato=csv');
confere('relatório CSV com itens sem código', $s === 200 && str_contains($csv, 'Sem código'));
[$s, $html] = $bib->ir('admin/detalhes-importacao.php?id=' . $imp1);
confere('detalhes da importação: pré-visualização nova, sem erro técnico', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Informações incompletas') && str_contains($html, 'Revisar no inventário'));
$imp4 = $importacao('acervo-ficticio.csv', hash('sha256', 'arquivo-1'), [2 => ['Q-01', 'Mais Um Fictício']]);
[$s, $html] = $bib->ir('admin/detalhes-importacao.php?id=' . $imp4);
confere('importação repetida: aviso e botão "Importar todos e revisar no inventário"', $s === 200 && str_contains($html, 'parece já ter sido importada') && str_contains($html, 'Importar todos e revisar no inventário'));
[$s, $json] = $bib->ir('admin/api/importacoes/confirmar.php', ['acao' => 'importar_todos', 'id' => $imp4], ['X-CSRF-Token' => $tk]);
confere('API: repetida sem confirmação devolve aviso (409) e não grava', $s === 409 && (json_decode($json, true)['repetida'] ?? false) && $repo->buscar($imp4)['status'] !== 'concluida');
[$s, $json] = $bib->ir('admin/api/importacoes/confirmar.php', ['acao' => 'importar_todos', 'id' => $imp4, 'confirmar_repeticao' => '1'], ['X-CSRF-Token' => $tk]);
confere('API: com confirmação importa e entra no inventário', $s === 200 && (json_decode($json, true)['resultado']['criados'] ?? 0) === 1 && $num('SELECT COUNT(*) FROM inventario_itens WHERE importacao_item_id IN (SELECT id FROM importacao_itens WHERE importacao_id = ?)', [$imp4]) === 1);
// Revisão geral (2026-11-14): páginas que quebravam com livro importado sem título.
$pdo->exec("INSERT INTO livros (titulo, isbn, idioma) VALUES (NULL, NULL, NULL)"); // como uma linha só com a localização
$semTitulo = (int)$pdo->lastInsertId();
$exSemTitulo = $exServ->cadastrar($semTitulo, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId)[0]['id'];
$codSemTitulo = (string)$val('SELECT codigo_individual FROM exemplares WHERE id = ?', [$exSemTitulo]);
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&codigo=' . rawurlencode($codSemTitulo));
confere('exemplar com código mostra a obra "Sem título (a conferir)" e o link para a ficha da obra', $s === 200 && teste_sem_erro_tecnico($html)
    && str_contains($html, 'Sem título (a conferir)') && str_contains($html, 'Ver obra e exemplares') && !str_contains($html, 'name="titulo"'));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv2 . '&obra=' . $semTitulo);
confere('ficha da obra sem título abre com o painel "Verificar e corrigir dados" (campos do livro)', $s === 200 && teste_sem_erro_tecnico($html)
    && str_contains($html, 'Informações do livro') && str_contains($html, 'name="titulo"') && str_contains($html, 'Verificar e corrigir dados') && str_contains($html, $codSemTitulo));
foreach (['admin/classificacao.php', 'admin/classificar-livro.php?id=' . $semTitulo, 'admin/detalhes-livro.php?id=' . $semTitulo, 'admin/livros.php',
    'admin/etiquetas.php?ids=' . $exSemTitulo, 'admin/detalhes-exemplar.php?id=' . $exSemTitulo] as $pagina) {
    [$s, $html] = $bib->ir($pagina);
    confere("livro sem título: $pagina abre sem erro técnico", $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, "</html>"), 'HTTP ' . $s);
}
[$s, $html] = $bib->ir('admin/classificacao.php');
confere('livro sem título e sem autor aparece na lista da Classificação CDD (antes a lista parava vazia)', str_contains($html, 'data-id="' . $semTitulo . '"') && str_contains($html, 'Sem título (a conferir)'));
[$s, $xlsx, , $tipo] = $bib->ir('admin/api/importacoes/relatorio.php?tipo=modelo');
confere('modelo de planilha baixa como .xlsx sem depender do PhpSpreadsheet', $s === 200 && str_starts_with($xlsx, 'PK') && str_contains((string)$tipo, 'spreadsheetml'), 'HTTP ' . $s . ' ' . $tipo);
[$s, $html] = $bib->ir('biblioteca_php/catalogo.php');
confere('catálogo público não mostra livros sem título', $s === 200 && !str_contains($html, 'Sem título (a conferir)'));
$log = $GLOBALS['teste_temporarios'][array_key_last($GLOBALS['teste_temporarios'])] ?? '';
$erros = $log && is_file($log) ? preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', file($log)) : [];
confere('nenhum erro de PHP registrado pelo servidor', !$erros, implode(' | ', array_slice($erros, 0, 3)));

teste_resultado();

function ImportacaoRepository_status_editaveis(): array
{
    return Biblioteca\Repositories\ImportacaoRepository::STATUS_EDITAVEIS;
}
