<?php
/**
 * Testes de "Comparar com planilha original" (migração 180).
 *
 * Uso: php admin/tests/comparacao-planilha-teste.php
 *
 * Banco temporário "bdp_teste_comparacao_XXXX" (apagado no final), dados FICTÍCIOS. Cenários:
 *    1. obra da planilha com vários exemplares (a linha não é consumida nem contada como cópia);
 *    2. obra nova, fora da planilha (não é erro);
 *    3. obra cadastrada manualmente que já existia na planilha (possível correspondência);
 *    4. obra com dados corrigidos depois da importação (não é erro; quadro lado a lado);
 *    5. exemplar associado à obra errada (vínculo inconsistente);
 *    6. correção da obra do exemplar SEM mudar o código BDP (e recusas de segurança);
 *    7. obra sem ISBN (correspondência por título e autor, com erro de digitação);
 *    8. possíveis duplicidades com edições diferentes (alerta, nada é unido);
 *    9. registros cuja obra ainda não tem exemplar (não é perda durante o inventário);
 *   10. comparação durante um inventário em andamento: abrir/filtrar/exportar não grava nada;
 *   11. resultados salvos preservados (imutáveis) e decisões no histórico;
 *   12. exportações (CSV, Excel, PDF; completa e só inconsistências) e permissões.
 */

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\ComparacaoPlanilhaService as Comparacao;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LoteService;
use Biblioteca\Services\ObraVerificacaoService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('comparacao', ['STORAGE_IMPORTACOES' => sys_get_temp_dir() . '/bdp-comparacao-' . bin2hex(random_bytes(3))]);
$_SERVER['SCRIPT_NAME'] = '/admin/tests/comparacao-planilha-teste.php';
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
/** "Impressão digital" das tabelas que a comparação NUNCA pode alterar ao ser aberta. */
$digital = function () use ($pdo): string {
    $partes = [];
    foreach (['livros', 'exemplares', 'registros_legados', 'registro_vinculos', 'inventario_itens', 'movimentacoes', 'comparacao_resolucoes', 'comparacoes', 'exemplar_obra_correcoes', 'importacao_itens', 'livro_autores', 'acoes_administrativas'] as $t) {
        $partes[] = $t . ':' . implode(',', $pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM));
    }
    return implode('|', $partes);
};
$itemDe = function (array $res, string $chave): ?array {
    foreach ($res['itens'] as $i) {
        if ($i['chave'] === $chave) {
            return $i;
        }
    }
    return null;
};
$tem = fn (?array $i, string $cat) => $i !== null && in_array($cat, $i['categorias'], true);

// ---------------------------------------------------------------
// Dados fictícios
$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'consulta@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$pdo->exec("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Literatura Fictícia', 'E01', 'P01', 'LF-E01-P01')");
$loc = (int)$val("SELECT id FROM localizacoes WHERE codigo = 'LF-E01-P01'");

$repo = importacao_repo();
$cab = ['Título', 'Autor', 'Editora', 'ISBN', 'Qtd', 'Ano', 'Edição'];
$mapa = importacao_validador()->sugerirMapeamento($cab);
$imp = $repo->criar(['nome_arquivo' => 'acervo-comparacao.csv', 'identificador_arquivo' => hash('sha256', 'comparacao'), 'arquivo_armazenado' => 'c.csv.upload',
    'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => 6, 'colunas' => count($cab)]], 'aba' => 'CSV', 'usuario_id' => $bibId, 'total_linhas' => 0]);
$repo->iniciarAnalise($imp, 'CSV', 1, $cab, $mapa);
$repo->inserirLinhas($imp, [
    2 => array_combine($cab, ['Obra de Várias Cópias Fictícia', 'Souza, Ana Fictícia', 'Editora Onda', '978-85-359-0277-5', '3', '2001', '1']),
    3 => array_combine($cab, ['LIVRO CORRIGIDO ORIGINAL', 'Pereira, Davi Fictício', 'Editora Velha', '', '1', '1980', '']),
    4 => array_combine($cab, ['Cronicas Sem Isbn Ficticias', 'Ramos, Clara Fictícia', 'Editora Brisa', '', '', '1995', '']),
    5 => array_combine($cab, ['Poemas Ainda Sem Exemplar', 'Lima, Bia Fictícia', 'Editora Verso', '', '2', '2005', '']),
    6 => array_combine($cab, ['Contos Esquecidos da Planilha', 'Torres, Rui Fictício', 'Editora Mar', '', '1', '2010', '']),
], $mapa, 6);
$repo->concluirAnalise($imp);
$repo->importarTodos($imp, $bibId);
$livroDaLinha = fn (int $l) => (int)$val('SELECT livro_id FROM registros_legados WHERE linha = ?', [$l]);
$regDaLinha = fn (int $l) => (int)$val('SELECT id FROM registros_legados WHERE linha = ?', [$l]);
$varias = $livroDaLinha(2);
$corrigido = $livroDaLinha(3);
$cronicas = $livroDaLinha(4);
$poemas = $livroDaLinha(5);
$contos = $livroDaLinha(6);

/** Obra cadastrada manualmente (fora da importação). */
$manual = function (string $titulo, ?string $autor, array $extra = []) use ($pdo): int {
    $pdo->prepare('INSERT INTO livros (titulo, isbn, editora, ano_publicacao, edicao, origem_cadastro, dados_verificados_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$titulo, $extra['isbn'] ?? null, $extra['editora'] ?? null, $extra['ano'] ?? null, $extra['edicao'] ?? null, $extra['origem'] ?? 'manual', $extra['verificado'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    if ($autor) {
        $pdo->prepare('INSERT IGNORE INTO autores (nome) VALUES (?)')->execute([$autor]);
        $aid = (int)$pdo->query('SELECT id FROM autores WHERE nome = ' . $pdo->quote($autor))->fetchColumn();
        $pdo->prepare('INSERT INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, 1)')->execute([$id, $aid]);
    }
    return $id;
};
$novaObra = $manual('Atlas Totalmente Novo Fictício', 'Nunes, Eva Fictícia');
$contosManual = $manual('Contos Esquecidos da Planiha', 'Rui Fictício Torres'); // erro de digitação e autor na ordem direta
$hist1 = $manual('História Regional Fictícia', 'Moura, Ivo Fictício', ['edicao' => '1ª', 'ano' => 1990, 'editora' => 'Editora Norte', 'verificado' => date('Y-m-d H:i:s')]);
$hist2 = $manual('Historia Regional Ficticia', 'Moura, Ivo Fictício', ['edicao' => '2ª', 'ano' => 2004, 'editora' => 'Editora Norte']);

$exServ = new ExemplarService($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);
$codigos = new CodigoBdpService($pdo);
$inv = $invServ->criar(['titulo' => 'Inventário Fictício Comparação', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv, $bibId);
$lotes = new LoteService($pdo, $exServ);
$lote = $lotes->criar(['capacidade' => 20, 'usuarios' => [$bibId], 'descricao' => 'Lote fictício da comparação'], $bibId);
$lotes->escolherAtivo($lote, $bibId);
$verif = new ObraVerificacaoService($pdo);
foreach ([$varias, $corrigido, $contos] as $l) {
    $pdo->prepare('UPDATE livros SET dados_verificados_em = NOW(), dados_verificados_por = ? WHERE id = ?')->execute([$bibId, $l]);
}
$registrar = function (int $livro) use ($invServ, $codigos, $bibId, $inv, $loc, $pdo): array {
    $proc = CodigoBdpService::novoProcesso();
    $codigo = $codigos->reservar($bibId, $livro, $proc)['codigo'];
    $invServ->registrarExemplar($inv, $livro, ['codigo' => $codigo, 'processo_bdp' => $proc, 'estado_conservacao' => 'bom', 'situacao_inventario' => 'encontrado', 'localizacao_id' => $loc], $bibId);
    return $pdo->query('SELECT * FROM exemplares WHERE codigo_individual = ' . $pdo->quote($codigo))->fetch();
};
$copias = [$registrar($varias), $registrar($varias), $registrar($varias)];
$exContos = $registrar($contos);
$exHist1 = $registrar($hist1);
$pdo->exec("UPDATE livros SET dados_verificados_em = NOW() WHERE id = $hist2");
$exHist2 = $registrar($hist2);
$pdo->exec("UPDATE livros SET dados_verificados_em = NULL WHERE id = $hist2");
$exCorrigido = $registrar($corrigido);
// Correção dos dados de uma obra importada, depois da importação (com histórico de verificação).
$pdo->prepare("UPDATE livros SET titulo = 'Livro Corrigido na Conferência', editora = 'Editora Nova' WHERE id = ?")->execute([$corrigido]);
$pdo->prepare("INSERT INTO livro_verificacoes (livro_id, acao, status_anterior, status_novo, alteracoes, inventario_id, administrador_id) VALUES (?, 'alterado', 'verificado', 'verificado', ?, ?, ?)")
    ->execute([$corrigido, json_encode(['titulo' => ['antes' => 'LIVRO CORRIGIDO ORIGINAL', 'depois' => 'Livro Corrigido na Conferência']]), $inv, $bibId]);
// Vínculo antigo errado: a linha dos Poemas aponta para uma cópia da "Obra de Várias Cópias".
$errado = $copias[2];
$pdo->prepare('UPDATE registros_legados SET exemplar_id = ?, vinculado_em = NOW(), vinculado_por = ? WHERE id = ?')->execute([$errado['id'], $bibId, $regDaLinha(5)]);

$comp = new Comparacao($pdo);

// ---------------------------------------------------------------
echo "\n0. Migração 180\n";
confere('migração 180 aplicada pelo migrar.php (tabelas e gatilhos)', $num("SELECT COUNT(*) FROM schema_migracoes WHERE arquivo = '180_comparacao_planilha.sql'") === 1
    && $num("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('comparacoes', 'comparacao_resolucoes', 'exemplar_obra_correcoes')") === 3
    && $num("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name IN ('comparacoes_sem_alteracao', 'comparacoes_sem_exclusao', 'exemplar_obra_correcoes_sem_alteracao', 'comparacao_resolucoes_sem_exclusao')") === 4);
confere('trocar a obra de um exemplar fora da correção controlada continua recusado', str_contains((string)$recusa(fn () => $pdo->prepare('UPDATE exemplares SET livro_id = ? WHERE id = ?')->execute([$poemas, $errado['id']])), 'não pode ser trocado'));
confere('o código BDP continua imutável', str_contains((string)$recusa(fn () => $pdo->prepare("UPDATE exemplares SET codigo_individual = 'BDP-999999' WHERE id = ?")->execute([$errado['id']])), 'não pode ser alterado'));

// ---------------------------------------------------------------
echo "\n10. Comparação durante o inventário em andamento (somente leitura)\n";
$antes = $digital();
$res = $comp->analisar([], $inv);
confere('a comparação usa o inventário em andamento', $res['inventario']['id'] == $inv && $res['inventario']['status'] === 'em_andamento');
confere('calcular a comparação não grava nada', $digital() === $antes);

// ---------------------------------------------------------------
echo "\n1. Obra da planilha com vários exemplares\n";
$rV = $itemDe($res, 'r' . $regDaLinha(2));
$oV = $itemDe($res, 'o' . $varias);
confere('o registro aparece como correspondência confirmada', $tem($rV, 'correspondencia_confirmada') && !$rV['atencao'], json_encode($rV['categorias'] ?? null));
confere('a obra tem 3 exemplares e 1 registro (a linha não é consumida nem contada como cópia)', $oV['exemplares'] === 3 && $oV['registros'] === 1 && $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ?', [$varias]) === 1);
confere('a quantidade da planilha (3) bate com os exemplares: sem divergência', !$tem($oV, 'divergencia_quantidade'));
confere('3 exemplares encontrados no inventário atual', $oV['encontrados'] === 3);
confere('exemplar sem vínculo direto com a linha não é problema', $itemDe($res, 'e' . $copias[0]['id']) === null && $itemDe($res, 'e' . $copias[1]['id']) === null);

// ---------------------------------------------------------------
echo "\n2. Obra nova, fora da planilha\n";
$oN = $itemDe($res, 'o' . $novaObra);
confere('obra manual que não estava na planilha é "Obra nova" e não exige atenção', $tem($oN, 'obra_nova') && !$oN['atencao'] && $oN['vinculo'] === 'sem_registro', json_encode($oN['categorias'] ?? null));

// ---------------------------------------------------------------
echo "\n3. Obra manual que já existia na planilha\n";
$oC = $itemDe($res, 'o' . $contosManual);
confere('obra manual com dados da linha 6 vira "Possível correspondência" (com erro de digitação e autor invertido)', $tem($oC, 'possivel_correspondencia') && $oC['atencao'] && !$tem($oC, 'obra_nova'), json_encode($oC['categorias'] ?? null));
confere('a sugestão aponta a linha 6 e a obra importada', ($oC['candidatos'][0]['linha'] ?? 0) === 6 && ($oC['candidatos'][0]['livro_id'] ?? 0) === $contos);
confere('a obra importada aparece como possível duplicidade da manual', $tem($itemDe($res, 'o' . $contos), 'possivel_duplicidade'));
confere('nada foi mesclado: as duas obras continuam ativas', $num('SELECT COUNT(*) FROM livros WHERE id IN (?, ?) AND arquivado_em IS NULL', [$contos, $contosManual]) === 2);

// ---------------------------------------------------------------
echo "\n4. Obra com dados corrigidos\n";
$rK = $itemDe($res, 'r' . $regDaLinha(3));
confere('dados alterados depois da importação são "Dados corrigidos", sem exigir atenção', $tem($rK, 'dados_corrigidos') && !$rK['atencao'] && $tem($rK, 'correspondencia_confirmada'), json_encode($rK['categorias'] ?? null) . json_encode($rK['detalhes'] ?? null));
$det = $comp->detalhe('registro', $regDaLinha(3));
$campos = array_column($det['campos'], null, 'campo');
confere('quadro lado a lado: título e editora destacados como alterados; autor e ano não', $campos['titulo']['alterado'] && $campos['editora']['alterado'] && !$campos['autor']['alterado'] && !$campos['ano']['alterado']
    && $campos['titulo']['planilha'] === 'LIVRO CORRIGIDO ORIGINAL' && $campos['titulo']['atual'] === 'Livro Corrigido na Conferência');
confere('o histórico mostra quem alterou e quando', (bool)array_filter($det['historico'], fn ($h) => $h['texto'] === 'Dados alterados' && $h['quem'] === 'Conta Fictícia Bibliotecaria'));
confere('maiúsculas, acentos e espaços não contam como alteração', !Comparacao::diferente('titulo', 'LIVRO  CORRIGIDO', 'livro corrigido') && !Comparacao::diferente('autor', 'Assis, Machado de', 'Machado de Assis')
    && !Comparacao::diferente('isbn', '978-85-359-0277-5', '9788535902775') && Comparacao::diferente('isbn', '9788535902775', '9788535902782'));

// ---------------------------------------------------------------
echo "\n5. Exemplar associado à obra errada\n";
$eE = $itemDe($res, 'e' . $errado['id']);
confere('o exemplar aparece com "Vínculo inconsistente" e sugere a obra da linha', $tem($eE, 'vinculo_inconsistente') && $eE['atencao'] && ($eE['candidatos'][0]['livro_id'] ?? 0) === $poemas, json_encode($eE));
confere('o registro da linha 5 também aponta a inconsistência', $tem($itemDe($res, 'r' . $regDaLinha(5)), 'vinculo_inconsistente'));
confere('a pesquisa pelo código BDP encontra o exemplar', count(Comparacao::filtrar($res['itens'], ['direcao' => 'exemplar', 'q' => strtolower(str_replace('-', ' ', $errado['codigo_individual']))])) === 1
    || count(Comparacao::filtrar($res['itens'], ['direcao' => 'exemplar', 'q' => $errado['codigo_individual']])) === 1);

// ---------------------------------------------------------------
echo "\n6. Corrigir a obra do exemplar sem mudar o código BDP\n";
confere('sem confirmação, recusa', str_contains((string)$recusa(fn () => $comp->corrigirObraExemplar((int)$errado['id'], $poemas, $bibId, false, 'teste', $inv)), 'Confirme'));
confere('sem motivo, recusa', str_contains((string)$recusa(fn () => $comp->corrigirObraExemplar((int)$errado['id'], $poemas, $bibId, true, '  ', $inv)), 'motivo'));
$pdo->prepare("UPDATE exemplares SET situacao = 'emprestado' WHERE id = ?")->execute([$copias[0]['id']]);
confere('exemplar emprestado não muda de obra', str_contains((string)$recusa(fn () => $comp->corrigirObraExemplar((int)$copias[0]['id'], $poemas, $bibId, true, 'teste', $inv)), 'emprestado'));
$antesEx = db_query('SELECT id, codigo_individual, lote_id, lote_ordem, carimbo_status, localizacao_id, estado_conservacao, conferido_em FROM exemplares WHERE id = ?', [$errado['id']])->fetch();
$movAntes = $num('SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ?', [$errado['id']]);
$seqAntes = $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1');
$r6 = $comp->corrigirObraExemplar((int)$errado['id'], $poemas, $bibId, true, 'A etiqueta foi colada no livro de poemas', $inv);
$depoisEx = db_query('SELECT id, codigo_individual, lote_id, lote_ordem, carimbo_status, localizacao_id, estado_conservacao, conferido_em FROM exemplares WHERE id = ?', [$errado['id']])->fetch();
confere('o exemplar passou para a obra correta', (int)$val('SELECT livro_id FROM exemplares WHERE id = ?', [$errado['id']]) === $poemas && $r6['anterior'] === $varias);
confere('mesmo id, código BDP, lote, ordem, carimbo, localização, conservação e conferência', $antesEx === $depoisEx);
confere('nenhum código BDP novo foi gerado', $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1') === $seqAntes && $num('SELECT COUNT(*) FROM exemplares') === 7);
confere('histórico preservado e acrescido ("Obra corrigida")', $num('SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ?', [$errado['id']]) === $movAntes + 1
    && $val("SELECT tipo FROM movimentacoes WHERE exemplar_id = ? ORDER BY id DESC LIMIT 1", [$errado['id']]) === 'obra');
confere('correção registrada com antes/depois, motivo, responsável e data', $num('SELECT COUNT(*) FROM exemplar_obra_correcoes WHERE exemplar_id = ? AND livro_anterior_id = ? AND livro_novo_id = ? AND administrador_id = ? AND codigo_individual = ?', [$errado['id'], $varias, $poemas, $bibId, $errado['codigo_individual']]) === 1
    && $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'exemplar.corrigir_obra' AND entidade_id = ?", [$errado['id']]) === 1);
confere('o item do inventário em andamento acompanhou a obra', (int)$val('SELECT livro_id FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv, $errado['id']]) === $poemas);
confere('a linha 5 continua ligada ao exemplar (agora da mesma obra) e os dados originais não mudaram', (int)$val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regDaLinha(5)]) === (int)$errado['id']
    && $val('SELECT titulo_original FROM registros_legados WHERE id = ?', [$regDaLinha(5)]) === 'Poemas Ainda Sem Exemplar');
$res2 = $comp->analisar([], $inv);
confere('depois da correção, o exemplar não aparece mais como inconsistente', $itemDe($res2, 'e' . $errado['id']) === null && !$tem($itemDe($res2, 'r' . $regDaLinha(5)), 'vinculo_inconsistente'));
confere('baixado não muda de obra', (function () use ($pdo, $exHist2, $comp, $bibId, $hist1, $recusa) {
    $pdo->prepare("UPDATE exemplares SET situacao = 'baixado' WHERE id = ?")->execute([$exHist2['id']]);
    $msg = $recusa(fn () => $comp->corrigirObraExemplar((int)$exHist2['id'], $hist1, $bibId, true, 'teste', null));
    return str_contains((string)$msg, 'baixado');
})());
$pdo->exec("SET @bdp_corrigir_obra_exemplar = NULL");

// ---------------------------------------------------------------
echo "\n7. Obra sem ISBN\n";
$rC = $itemDe($res2, 'r' . $regDaLinha(4));
confere('obra sem ISBN: corresponde pela própria associação, aguardando verificação', $rC !== null && $rC['livro_id'] === $cronicas && $tem($rC, 'aguardando_verificacao') && !$tem($rC, 'sem_correspondencia'), json_encode($rC['categorias'] ?? null));
confere('a pesquisa sem acentos encontra a obra ("cronicas sem isbn")', count(Comparacao::filtrar($res2['itens'], ['direcao' => 'planilha', 'q' => 'crônicas sem isbn'])) === 1);
confere('pesquisa pelo número da linha e pelo arquivo', count(Comparacao::filtrar($res2['itens'], ['direcao' => 'planilha', 'q' => '4'])) === 1
    && count(Comparacao::filtrar($res2['itens'], ['direcao' => 'planilha', 'q' => 'acervo-comparacao'])) === 5);
confere('pesquisa por ISBN com hífens', count(Comparacao::filtrar($res2['itens'], ['direcao' => 'planilha', 'q' => '978-85-359-0277-5'])) === 1);

// ---------------------------------------------------------------
echo "\n8. Possíveis duplicidades com edições diferentes\n";
$oH = $itemDe($res2, 'o' . $hist1);
confere('as duas edições aparecem como possível duplicidade, com o alerta de edição diferente', $tem($oH, 'possivel_duplicidade') && ($oH['candidatos'][0]['livro_id'] ?? 0) === $hist2
    && in_array('Edição diferente', $oH['candidatos'][0]['diferencas'], true), json_encode($oH['candidatos'] ?? null));
confere('o detalhe avisa que edições diferentes podem ser obras separadas', (bool)array_filter($oH['detalhes'], fn ($d) => str_contains($d, 'edições diferentes podem ser obras separadas')));
$comp->decidir('obra', $hist2, 'possivel_duplicidade', 'nao_duplicidade', $bibId, 'Edições diferentes conferidas', $hist1);
$res3 = $comp->analisar([], $inv);
confere('"Não é duplicidade" tira o par da lista nas duas obras, sem mesclar nada', !$tem($itemDe($res3, 'o' . $hist1), 'possivel_duplicidade') && !$tem($itemDe($res3, 'o' . $hist2), 'possivel_duplicidade')
    && $num('SELECT COUNT(*) FROM livros WHERE id IN (?, ?) AND arquivado_em IS NULL', [$hist1, $hist2]) === 2);

// ---------------------------------------------------------------
echo "\n9. Registros sem exemplar encontrado\n";
$rP = $itemDe($res, 'r' . $regDaLinha(5));
$rP3 = $itemDe($res3, 'r' . $regDaLinha(5));
confere('antes da correção: obra dos Poemas sem exemplar registrado, sem tratar como perda', $tem($rP, 'sem_exemplar') && (bool)array_filter($rP['detalhes'], fn ($d) => str_contains($d, 'não significa perda')));
confere('depois da correção do exemplar, os Poemas têm 1 exemplar', !$tem($rP3, 'sem_exemplar') && $rP3['exemplares'] === 1);
confere('"Sem exemplar registrado" não exige atenção (é progresso)', !Comparacao::CATEGORIAS['sem_exemplar'][2]);
$ind = $res3['indicadores'];
confere('indicadores separados por unidade (registros, obras, exemplares)', $ind['planilha']['registros'] === 5 && $ind['acervo']['obras'] === 9 && $ind['acervo']['importadas'] === 5 && $ind['acervo']['manuais'] === 4
    && $ind['exemplares']['total'] === 6 && $ind['exemplares']['encontrados_inventario'] === 6, json_encode($ind));
confere('progresso: obras da planilha verificadas / a verificar / com exemplar', $ind['progresso']['obras_planilha'] === 5 && $ind['progresso']['verificadas'] === 3 && $ind['progresso']['a_verificar'] === 2 && $ind['progresso']['com_exemplar'] === 4, json_encode($ind['progresso']));

// ---------------------------------------------------------------
echo "\n11. Resultados salvos preservados\n";
$salvaId = $comp->salvar($res3, $bibId, 'Comparação fictícia de teste');
$salva = $comp->salva($salvaId);
confere('o resultado salvo guarda data, responsável, fonte, inventário e itens', $salva['administrador_nome'] === 'Conta Fictícia Bibliotecaria' && (int)$salva['inventario_id'] === $inv
    && $salva['importacoes'][0]['arquivo'] === 'acervo-comparacao.csv' && count($salva['itens']) === count($res3['itens']));
$jsonAntes = $val('SELECT itens_json FROM comparacoes WHERE id = ?', [$salvaId]);
$comp->decidir('obra', $contosManual, 'possivel_correspondencia', 'confirmar_obra_nova', $bibId, 'É outra obra');
$res4 = $comp->analisar([], $inv);
confere('decisão nova muda a comparação atual ("Obra nova" confirmada)', $tem($itemDe($res4, 'o' . $contosManual), 'obra_nova') && in_array('possivel_correspondencia', $itemDe($res4, 'o' . $contosManual)['resolvidas'], true));
confere('...mas o resultado salvo não muda', $val('SELECT itens_json FROM comparacoes WHERE id = ?', [$salvaId]) === $jsonAntes
    && $tem(array_values(array_filter($comp->salva($salvaId)['itens'], fn ($i) => $i['chave'] === 'o' . $contosManual))[0], 'possivel_correspondencia'));
confere('o resultado salvo não pode ser alterado nem apagado', str_contains((string)$recusa(fn () => $pdo->exec("UPDATE comparacoes SET observacao = 'x' WHERE id = $salvaId")), 'não são alterados')
    && str_contains((string)$recusa(fn () => $pdo->exec("DELETE FROM comparacoes WHERE id = $salvaId")), 'não são apagados'));
confere('as decisões não podem ser apagadas', str_contains((string)$recusa(fn () => $pdo->exec('DELETE FROM comparacao_resolucoes')), 'não são apagadas'));
$comp->decidir('obra', $contosManual, 'possivel_correspondencia', 'reaberta', $bibId);
confere('reabrir volta a pendência (o histórico guarda as duas decisões)', $itemDe($comp->analisar([], $inv), 'o' . $contosManual)['atencao'] && $num('SELECT COUNT(*) FROM comparacao_resolucoes WHERE alvo_id = ?', [$contosManual]) === 2);
confere('"Confirmar obra nova" só vale para obra', str_contains((string)$recusa(fn () => $comp->decidir('registro', $regDaLinha(4), 'possivel_correspondencia', 'confirmar_obra_nova', $bibId)), 'Só uma obra'));

// Associar registro a obra existente (reaproveita o serviço da conferência; dados originais intactos).
$orig = $val('SELECT CAST(dados_originais AS CHAR) FROM registros_legados WHERE id = ?', [$regDaLinha(6)]);
confere('associar a uma obra NÃO verificada é recusado', str_contains((string)$recusa(fn () => $comp->associarRegistro($regDaLinha(6), $contosManual, $bibId, true, '', $inv)), 'Dados verificados'));
$pdo->prepare('UPDATE livros SET dados_verificados_em = NOW() WHERE id = ?')->execute([$contosManual]);
confere('sem confirmação é recusado', str_contains((string)$recusa(fn () => $comp->associarRegistro($regDaLinha(6), $contosManual, $bibId, false, '', $inv)), 'Confirme'));

// ---------------------------------------------------------------
echo "\n12. Página, exportações e permissões\n";
$base = teste_servidor($env);
$bib = new TesteNavegador($base);
confere('bibliotecária entra no painel', $bib->entrar('bib@teste.br', $senha) === 200);
[$s, $html] = $bib->ir('admin/inventario.php');
confere('botão "Comparar com planilha original" na página do Inventário', $s === 200 && str_contains($html, 'inventario-comparacao.php') && str_contains($html, 'Comparar com planilha original'));
$antes = $digital();
[$s, $html] = $bib->ir('admin/inventario-comparacao.php');
confere('a página abre (planilha → acervo) com fonte, data e inventário', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'acervo-comparacao.csv') && str_contains($html, 'Inventário Fictício Comparação') && str_contains($html, 'Comparado em'), substr(strip_tags($html), 0, 300));
confere('indicadores e nota sobre sobreposição das categorias', str_contains($html, 'Correspondências a confirmar') && str_contains($html, 'as categorias podem se sobrepor'));
[$s2, $html2] = $bib->ir('admin/inventario-comparacao.php?direcao=acervo&atencao=1');
[$s3, $html3] = $bib->ir('admin/inventario-comparacao.php?direcao=acervo&ver=o' . $contosManual);
confere('filtro "só o que exige atenção" e quadro lado a lado da obra', $s2 === 200 && str_contains($html2, 'Contos Esquecidos da Planiha') && !str_contains($html2, 'Atlas Totalmente Novo')
    && $s3 === 200 && str_contains($html3, 'Planilha original') && str_contains($html3, 'Cadastro atual') && str_contains($html3, 'Registrar decisão'));
[$s4, $html4] = $bib->ir('admin/inventario-comparacao.php?direcao=exemplar');
[$s5, $html5] = $bib->ir('admin/inventario-comparacao.php?historico=1');
[$s6, $html6] = $bib->ir('admin/inventario-comparacao.php?salva=' . $salvaId);
confere('abas de exemplares, comparações anteriores e resultado salvo abrem', $s4 === 200 && $s5 === 200 && str_contains($html5, 'Nº ' . $salvaId) && $s6 === 200 && str_contains($html6, 'Este resultado não muda'));
[$sc, $csv, , $tipo] = $bib->ir('admin/inventario-comparacao.php?exportar=csv&escopo=tudo');
[$si, $csvI] = $bib->ir('admin/inventario-comparacao.php?exportar=csv&escopo=inconsistencias');
confere('CSV completo com obra, linha original, exemplares, tipo de divergência e resolução', $sc === 200 && str_contains($tipo, 'text/csv') && str_contains($csv, 'Linha original') && str_contains($csv, 'Tipo de divergência')
    && str_contains($csv, 'Situação da resolução') && str_contains($csv, 'acervo-comparacao.csv · linha 2'));
confere('CSV só de inconsistências: traz a divergência de quantidade (3 na planilha, 2 depois da correção) e não traz obra nova nem registro confirmado', $si === 200 && str_contains($csvI, 'Contos Esquecidos da Planiha')
    && str_contains($csvI, 'Planilha: 3 exemplar(es); acervo: 2 registrado(s)') && !str_contains($csvI, 'Atlas Totalmente Novo') && !str_contains($csvI, 'acervo-comparacao.csv · linha 2'), $csvI);
[$sx, $xlsx] = $bib->ir('admin/inventario-comparacao.php?exportar=xlsx&escopo=tudo');
[$sp, $pdf] = $bib->ir('admin/inventario-comparacao.php?exportar=pdf&escopo=inconsistencias');
[$ss, $csvS] = $bib->ir('admin/inventario-comparacao.php?salva=' . $salvaId . '&exportar=csv&escopo=tudo');
confere('Excel e PDF gerados', $sx === 200 && str_starts_with($xlsx, 'PK') && $sp === 200 && str_starts_with($pdf, '%PDF'));
confere('o resultado salvo também é exportado', $ss === 200 && str_contains($csvS, 'Contos Esquecidos da Planiha'));
[$sv] = $bib->ir('admin/inventario-comparacao.php?imprimir=1&direcao=acervo');
confere('versão para impressão abre', $sv === 200);
// Planilha enviada só para comparação: nada é gravado.
$csvTeste = "Título;Autor;ISBN\nObra de Várias Cópias Fictícia;Souza, Ana Fictícia;9788535902775\nLivro Que Não Existe Fictício;Ninguém Fictício;\n";
$tk = $bib->csrf('admin/inventario-comparacao.php');
[$su] = $bib->enviar('admin/inventario-comparacao.php', ['csrf' => $tk, 'acao' => 'enviar_arquivo'], ['planilha' => ['comparar.csv', $csvTeste, 'text/csv']]);
[$sa, $htmlA] = $bib->ir('admin/inventario-comparacao.php?fonte=arquivo');
confere('planilha enviada só para comparar: lida, comparada e nada gravado', in_array($su, [303, 200], true) && $sa === 200 && str_contains($htmlA, 'Planilha enviada só para comparação') && str_contains($htmlA, 'Livro Que Não Existe Fictício')
    && str_contains($htmlA, 'Sem correspondência no acervo'), substr(strip_tags($htmlA), 0, 400));
confere('abrir, filtrar, exportar e comparar arquivo não alteraram o banco', $digital() === $antes);
// Ações pela página.
$tk = $bib->csrf('admin/inventario-comparacao.php');
[$sd, , $ld] = $bib->ir('admin/inventario-comparacao.php', ['csrf' => $tk, 'acao' => 'decidir', 'alvo_tipo' => 'registro', 'alvo_id' => $regDaLinha(4), 'decisao_cat' => 'resolvida|aguardando_verificacao', 'voltar' => $base . 'admin/inventario-comparacao.php']);
confere('registrar decisão pela página (fica só no histórico)', $sd === 303);
[$sa2, , $la2] = $bib->ir('admin/inventario-comparacao.php', ['csrf' => $tk, 'acao' => 'associar_registro', 'registro_id' => $regDaLinha(6), 'destino' => $contosManual, 'confirmo' => '1', 'observacao' => 'Mesma obra conferida']);
confere('associar registro pela página: a linha 6 passa para a obra verificada, dados originais intactos', $sa2 === 303 && $livroDaLinha(6) === $contosManual
    && (int)$val('SELECT livro_original_id FROM registros_legados WHERE id = ?', [$regDaLinha(6)]) === $contos
    && $val('SELECT CAST(dados_originais AS CHAR) FROM registros_legados WHERE id = ?', [$regDaLinha(6)]) === $orig
    && $num("SELECT COUNT(*) FROM registro_vinculos WHERE registro_id = ? AND acao = 'vincular_obra'", [$regDaLinha(6)]) === 1);
$res5 = $comp->analisar([], $inv);
confere('a obra importada de onde a linha saiu, que manteve o exemplar, passa a apontar o vínculo inconsistente', $tem($itemDe($res5, 'o' . $contos), 'vinculo_inconsistente') && $tem($itemDe($res5, 'e' . $exContos['id']), 'vinculo_inconsistente')
    && ($itemDe($res5, 'e' . $exContos['id'])['candidatos'][0]['livro_id'] ?? 0) === $contosManual);
[$sc2] = $bib->ir('admin/inventario-comparacao.php', ['csrf' => $tk, 'acao' => 'corrigir_exemplar', 'exemplares' => [$exContos['id']], 'destino' => $contosManual, 'confirmo' => '1', 'motivo' => 'Mesma obra']);
confere('corrigir a obra do exemplar pela página mantém o código BDP', $sc2 === 303 && (int)$val('SELECT livro_id FROM exemplares WHERE id = ?', [$exContos['id']]) === $contosManual
    && $val('SELECT codigo_individual FROM exemplares WHERE id = ?', [$exContos['id']]) === $exContos['codigo_individual']);
$aux = new TesteNavegador($base);
$aux->entrar('aux@teste.br', $senha);
$tka = $aux->csrf('admin/inventario-comparacao.php');
[$sx1, $hx1] = $aux->ir('admin/inventario-comparacao.php?direcao=acervo&ver=o' . $hist1);
// Único perfil (migração 200): a conta antiga "auxiliar" é administradora e vê as correções; quem não pode
// gravar é quem não entrou ou envia sem o token CSRF.
$anonC = new TesteNavegador($base);
[$sx2] = $anonC->ir('admin/inventario-comparacao.php', ['acao' => 'corrigir_exemplar', 'exemplares' => [$exHist1['id']], 'destino' => $hist2, 'confirmo' => '1', 'motivo' => 'x']);
[$sx3] = $aux->ir('admin/inventario-comparacao.php', ['csrf' => 'falso', 'acao' => 'salvar']);
confere('conta antiga "auxiliar" abre a comparação', $sx1 === 200);
confere('sem login (302) ou sem CSRF (419) não corrige nem salva, e nada muda', $sx2 === 302 && $sx3 === 419 && (int)$val('SELECT livro_id FROM exemplares WHERE id = ?', [$exHist1['id']]) === $hist1);
$con = new TesteNavegador($base);
$con->entrar('consulta@teste.br', $senha);
[$sq] = $con->ir('admin/inventario-comparacao.php');
[$sq2] = $con->ir('admin/inventario-comparacao.php?exportar=csv&escopo=tudo');
confere('perfil "Somente consulta" abre e exporta (relatórios.exportar)', $sq === 200 && $sq2 === 200);
confere('nenhum código BDP mudou e nada foi apagado', $num('SELECT COUNT(*) FROM exemplares') === 7 && $num('SELECT COUNT(*) FROM registros_legados') === 5
    && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual NOT REGEXP '^BDP-[0-9]{6}$'") === 0);


// ---------------------------------------------------------------
echo "\n13. Layout no celular e no computador\n";
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$temPw = $node !== '' && trim((string)shell_exec(escapeshellarg($node) . " -e \"require('playwright');console.log('ok')\" 2>/dev/null")) === 'ok';
if (!$temPw) {
    echo "  (pulado) Node.js com Playwright não está instalado: a verificação de layout não foi executada.\n";
} else {
    $script = sys_get_temp_dir() . '/bdp-comparacao-layout-' . bin2hex(random_bytes(3)) . '.js';
    $GLOBALS['teste_temporarios'][] = $script;
    $pasta = getenv('TESTE_CAPTURAS') ?: sys_get_temp_dir();
    file_put_contents($script, <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const [url, cookies, pasta] = [process.argv[2], JSON.parse(process.argv[3]), process.argv[4]];
  const browser = await chromium.launch();
  const saida = {};
  for (const [nome, vp] of [['celular', { width: 390, height: 844 }], ['computador', { width: 1366, height: 900 }]]) {
    const ctx = await browser.newContext({ viewport: vp, isMobile: nome === 'celular', hasTouch: nome === 'celular' });
    const u = new URL(url);
    await ctx.addCookies(Object.entries(cookies).map(([name, value]) => ({ name, value: String(value), domain: u.hostname, path: '/' })));
    const page = await ctx.newPage();
    await page.goto(url, { waitUntil: 'networkidle' });
    saida[nome] = await page.evaluate(() => {
      const lado = document.querySelector('.adm-comp-lado thead');
      const ind = document.querySelectorAll('.adm-comp-ind');
      return { largura: document.documentElement.scrollWidth, tela: window.innerWidth, indicadores: ind.length,
        cabecalhoLado: lado ? getComputedStyle(lado).display : 'ausente', detalhe: !!document.querySelector('#detalhe'),
        largos: [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > window.innerWidth + 1 && [...el.children].every(c => c.getBoundingClientRect().right <= window.innerWidth + 1)).slice(0, 4).map(el => el.tagName + '.' + el.className + ' ' + el.offsetWidth + ' in ' + (el.closest('table,section') || {}).className + ' : ' + el.textContent.slice(0, 40)) };
    });
    await page.screenshot({ path: pasta + '/comparacao-' + nome + '.png', fullPage: true });
    await ctx.close();
  }
  console.log(JSON.stringify(saida));
  await browser.close();
})().catch(e => { console.log(JSON.stringify({ erro: String(e) })); process.exit(0); });
JS);
    $url = $base . 'admin/inventario-comparacao.php?direcao=acervo&ver=o' . $hist1;
    $out = trim((string)shell_exec(implode(' ', array_map('escapeshellarg', [$node, $script, $url, json_encode($bib->cookies), $pasta])) . ' 2>&1'));
    $p = strpos($out, '{"celular"');
    $r = $p === false ? [] : (json_decode(substr($out, $p), true) ?: []);
    confere('celular (390 px): sem rolagem lateral, 5 grupos de indicadores e quadro lado a lado em coluna', ($r['celular']['largura'] ?? 9999) <= 390 && ($r['celular']['tela'] ?? 0) <= 390
        && ($r['celular']['indicadores'] ?? 0) === 5 && ($r['celular']['cabecalhoLado'] ?? '') === 'none' && !empty($r['celular']['detalhe']), $out);
    confere('computador (1366 px): sem rolagem lateral e quadro lado a lado em colunas', ($r['computador']['largura'] ?? 9999) <= ($r['computador']['tela'] ?? 0) && ($r['computador']['cabecalhoLado'] ?? '') !== 'none', $out);
}

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
