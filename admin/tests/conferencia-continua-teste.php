<?php
/**
 * Testes da conferência contínua do Inventário (migração 130).
 *
 * Uso: php admin/tests/conferencia-continua-teste.php
 *
 * Tudo acontece em bancos temporários "bdp_teste_conferencia_XXXX" (apagados no final), com dados
 * FICTÍCIOS. Cenários:
 *    1. migração 130 sobre um banco com dados da 120: o vínculo linha × exemplar é recuperado;
 *    2. duas abas (dois formulários) recebem códigos BDP diferentes; a mesma aba recebe o mesmo;
 *    3. reserva cancelada não é aceita e o número não volta para a sequência; expiração;
 *    4. duas pessoas registrando ao mesmo tempo (8 processos paralelos), duas disputando a mesma linha;
 *    5. nenhuma linha da planilha é escolhida automaticamente;
 *    6. exemplar adicional, sem linha correspondente;
 *    7. obra sem ISBN;
 *    8. inventário novo depois de registrar só parte das cópias de uma obra;
 *    9. vincular linhas a uma obra já verificada (com confirmação) e desfazer, sem perder a origem;
 *   10. desfazer vínculo linha × exemplar feito por engano;
 *   11. estante atual: finalizar (só os escolhidos, com confirmação) e reabrir;
 *   12. livro não listado com código do formulário;
 *   13. registros seguidos pela página (outra cópia mantém local e estado; clique duplo não duplica);
 *   14. indicadores separados, pesquisa paginada e resposta parcial (X-Conferencia) sem erros.
 */

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\ConferenciaCadastroService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\RegistroRepetidoException;
use Biblioteca\Services\VinculoObraService;

// ---- Processo filho: uma pessoa gera o código no seu formulário e registra o exemplar. ----
if (($argv[1] ?? '') === '--registrar') {
    require __DIR__ . '/../../config/app.php';
    [, , $inv, $livro, $adm, $inicio, $vinculo] = $argv;
    $ex = new ExemplarService(db());
    $s = new InventarioService(db(), $ex, new InventarioRepository(db()));
    $c = new CodigoBdpService(db());
    while (microtime(true) < (float)$inicio) {
        usleep(200);
    }
    $r = ['codigo' => ''];
    try {
        $proc = CodigoBdpService::novoProcesso();
        $r = $c->reservar((int)$adm, (int)$livro, $proc);
        $x = $s->registrarExemplar((int)$inv, (int)$livro, ['codigo' => $r['codigo'], 'processo_bdp' => $proc, 'vinculo' => $vinculo, 'estado_conservacao' => 'bom', 'situacao_inventario' => 'encontrado'], (int)$adm);
        echo 'ok:' . $x['codigo'] . ':' . ($x['registro_id'] ?? 0);
    } catch (Throwable $e) {
        echo 'recusado:' . $r['codigo'] . ':' . $e->getMessage();
    }
    exit(0);
}

// ---- Processo filho: grava dados como eram ANTES da migração 130 (banco só até a 120). ----
if (($argv[1] ?? '') === '--antigo') {
    $_SERVER['SCRIPT_NAME'] = '/admin/tests/conferencia-continua-teste.php';
    require __DIR__ . '/../../config/app.php';
    require __DIR__ . '/../includes/importacao.php';
    $pdo = db();
    $pdo->exec("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES ('Conta Fictícia Antiga', 'antiga@teste.br', 'x', 'bibliotecaria')");
    $adm = (int)$pdo->lastInsertId();
    $repo = importacao_repo();
    $cab = ['Título', 'Sobrenome/Prenome do Autor', 'Editora', 'Qtd'];
    $mapa = importacao_validador()->sugerirMapeamento($cab);
    $imp = $repo->criar(['nome_arquivo' => 'antiga.csv', 'identificador_arquivo' => hash('sha256', 'antiga'), 'arquivo_armazenado' => 'a.csv.upload', 'tipo_arquivo' => 'csv',
        'tamanho_bytes' => 10, 'abas' => [['nome' => 'CSV', 'linhas' => 2, 'colunas' => 4]], 'aba' => 'CSV', 'usuario_id' => $adm, 'total_linhas' => 0]);
    $repo->iniciarAnalise($imp, 'CSV', 1, $cab, $mapa);
    $repo->inserirLinhas($imp, [2 => array_combine($cab, ['Obra Antiga Fictícia', 'Velho, Autor Fictício', 'Editora Antiga', '2']), 3 => array_combine($cab, ['Outra Obra Antiga Fictícia', 'Novo, Autor Fictício', 'Editora Antiga', '1'])], $mapa, 3);
    $repo->concluirAnalise($imp);
    $repo->importarTodos($imp, $adm);
    $regs = $pdo->query('SELECT id, livro_id, importacao_item_id FROM registros_legados ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE livros SET dados_verificados_em = NOW() WHERE id = ?')->execute([$regs[0]['livro_id']]);
    $c = (new ExemplarService($pdo))->cadastrarComCodigo((int)$regs[0]['livro_id'], 'BDP-000777', ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $adm);
    $inv = (new Biblioteca\Services\InventarioService($pdo, new ExemplarService($pdo), new Biblioteca\Repositories\InventarioRepository($pdo)))
        ->criar(['titulo' => 'Inventário antigo fictício', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $adm], $adm);
    $pdo->prepare("UPDATE inventarios SET status = 'em_andamento' WHERE id = ?")->execute([$inv]);
    // Como a versão 120 fazia: o item da linha da planilha passava a apontar para o exemplar.
    $pdo->prepare('INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, importacao_item_id, previsto, conferido_em, conferido_por) VALUES (?, ?, ?, ?, 1, NOW(), ?)')
        ->execute([$inv, $c['id'], $regs[0]['livro_id'], $regs[0]['importacao_item_id'], $adm]);
    $pdo->prepare('INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, importacao_item_id, previsto) VALUES (?, NULL, ?, ?, 1)')
        ->execute([$inv, $regs[1]['livro_id'], $regs[1]['importacao_item_id']]);
    echo json_encode(['reg1' => (int)$regs[0]['id'], 'reg2' => (int)$regs[1]['id'], 'exemplar' => $c['id'], 'inv' => $inv, 'adm' => $adm]);
    exit(0);
}

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('conferencia', ['STORAGE_IMPORTACOES' => sys_get_temp_dir() . '/bdp-conferencia-' . bin2hex(random_bytes(3))]);
$_SERVER['SCRIPT_NAME'] = '/admin/tests/conferencia-continua-teste.php';
require __DIR__ . '/../includes/importacao.php';
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
echo "\n1. Migração 130 sobre um banco com dados da versão 120\n";
$banco120 = $banco . '_v120';
$raiz->exec("CREATE DATABASE `$banco120` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$GLOBALS['teste_bancos'][] = $banco120;
$env120 = array_merge(getenv(), $env, ['DB_NAME' => $banco120]);
$raiz->exec("CREATE TABLE `$banco120`.schema_migracoes (arquivo VARCHAR(190) PRIMARY KEY, aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$raiz->exec("INSERT INTO `$banco120`.schema_migracoes (arquivo) VALUES ('130_conferencia_continua.sql')"); // finge aplicada: o banco fica na 120
$migrar = [PHP_BINARY, __DIR__ . '/../../database/migrar.php'];
[$cod] = $rodar($migrar, $env120);
confere('banco antigo criado só até a migração 120', $cod === 0 && (int)$raiz->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$banco120' AND table_name = 'registros_legados' AND column_name = 'exemplar_id'")->fetchColumn() === 0);
[$cod, $out] = $rodar([PHP_BINARY, __FILE__, '--antigo'], $env120);
$antigo = json_decode($out, true) ?: [];
confere('dados antigos gravados (linha da planilha ligada a um exemplar pelo item do inventário)', $cod === 0 && !empty($antigo['exemplar']), $out);
$raiz->exec("DELETE FROM `$banco120`.schema_migracoes WHERE arquivo = '130_conferencia_continua.sql'");
[$cod, $out] = $rodar($migrar, $env120);
confere('migração 130 aplicada sobre os dados antigos sem erro', $cod === 0 && str_contains($out, '130_conferencia_continua.sql... ok'), $out);
$q120 = fn (string $sql) => $raiz->query(str_replace('{b}', "`$banco120`", $sql))->fetchColumn();
confere('vínculo recuperado: a linha ligada ao exemplar passa a ter exemplar_id', (int)$q120('SELECT exemplar_id FROM {b}.registros_legados WHERE id = ' . (int)($antigo['reg1'] ?? 0)) === (int)($antigo['exemplar'] ?? -1));
confere('a linha que não tinha exemplar continua sem vínculo', $q120('SELECT exemplar_id FROM {b}.registros_legados WHERE id = ' . (int)($antigo['reg2'] ?? 0)) === null);
confere('nenhum dado apagado pela migração', (int)$q120('SELECT COUNT(*) FROM {b}.registros_legados') === 2 && (int)$q120('SELECT COUNT(*) FROM {b}.inventario_itens') === 2 && (int)$q120('SELECT COUNT(*) FROM {b}.exemplares') === 1);
[$cod, $out] = $rodar($migrar, $env120);
confere('rodar as migrações de novo não faz nada (idempotente)', $cod === 0 && str_contains($out, 'Nenhuma migração pendente'), $out);

// ---------------------------------------------------------------
// Dados fictícios do banco principal.
$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$auxId = $contas['auxiliar'];
$exServ = new ExemplarService($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);
$cad = new ConferenciaCadastroService($pdo, $invServ);
$codigos = new CodigoBdpService($pdo);
$vinc = new VinculoObraService($pdo);
$pdo->exec("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Literatura Fictícia', 'E01', 'P01', 'LF-E01-P01'), ('Literatura Fictícia', 'E02', 'P01', 'LF-E02-P01')");
$loc1 = (int)$val("SELECT id FROM localizacoes WHERE codigo = 'LF-E01-P01'");
$loc2 = (int)$val("SELECT id FROM localizacoes WHERE codigo = 'LF-E02-P01'");

$repo = importacao_repo();
$cab = ['Localização', 'Título', 'Sobrenome/Prenome do Autor', 'Editora', 'ISBN', 'Qtd', 'Ano'];
$mapa = importacao_validador()->sugerirMapeamento($cab);
$imp = $repo->criar(['nome_arquivo' => 'acervo-conferencia.csv', 'identificador_arquivo' => hash('sha256', 'conferencia'), 'arquivo_armazenado' => 'c.csv.upload',
    'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => 7, 'colunas' => count($cab)]], 'aba' => 'CSV', 'usuario_id' => $bibId, 'total_linhas' => 0]);
$repo->iniciarAnalise($imp, 'CSV', 1, $cab, $mapa);
$linhas = [
    ['A-01', 'Cópias Fictícias do Mar', 'Mares, Ana Fictícia', 'Editora Onda', '', '3', '2001'],
    ['A-01', 'Cópias Fictícias do Mar (segunda linha)', 'Mares, Ana Fictícia', 'Editora Onda', '', '', '2001'],
    ['A-02', 'Cópias Fictícias do Mar (terceira linha)', 'Mares, Ana Fictícia', 'Editora Onda', '', '', '2001'],
    ['B-01', 'Contos Fictícios Sem ISBN', 'Rocha, Davi Fictício', '', '', '1', ''],
    ['B-02', 'Poemas Fictícios Repetidos', 'Lima, Bia Fictícia', 'Editora Verso', '', '1', '1999'],
    ['B-03', 'Poemas Fictícios Repetidos (cópia da planilha)', 'Lima, Bia Fictícia', 'Editora Verso', '', '1', '1999'],
    ['C-01', 'Livro Fictício Para Pesquisa', 'Souza, Caio Fictício', 'Editora Busca', '', '1', '2010'],
];
$repo->inserirLinhas($imp, array_combine(range(2, 8), array_map(fn ($l) => array_combine($cab, $l), $linhas)), $mapa, 8);
$repo->concluirAnalise($imp);
$repo->importarTodos($imp, $bibId);
$regDaLinha = fn (int $linha) => (int)$val('SELECT id FROM registros_legados WHERE linha = ? ORDER BY id LIMIT 1', [$linha]);
$livroDaLinha = fn (int $linha) => (int)$val('SELECT livro_id FROM registros_legados WHERE linha = ? ORDER BY id LIMIT 1', [$linha]);
$mar = $livroDaLinha(2);
$mar2 = $livroDaLinha(3);
$mar3 = $livroDaLinha(4);
$contos = $livroDaLinha(5);
$poemas = $livroDaLinha(6);
$poemas2 = $livroDaLinha(7);
$busca = $livroDaLinha(8);
confere('7 linhas importadas como 7 obras separadas, nenhuma unida sozinha', count(array_unique([$mar, $mar2, $mar3, $contos, $poemas, $poemas2, $busca])) === 7 && $num('SELECT COUNT(*) FROM exemplares') === 0);
$inv = $invServ->criar(['titulo' => 'Inventário Fictício Contínuo', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$previstos = $invServ->iniciar($inv, $bibId);
confere('ao iniciar, cada linha da planilha entra como Pendente', $previstos === 7 && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id IS NULL', [$inv]) === 7);
$verificar = fn (int $livro, array $d) => $cad->atualizarLivro($livro, $cad->dados($d), $bibId, false, true, $inv);
$verificar($mar, ['titulo' => 'Cópias Fictícias do Mar', 'autores' => 'Mares, Ana Fictícia', 'editora' => 'Editora Onda', 'ano_publicacao' => '2001']);

// ---------------------------------------------------------------
echo "\n9. Vincular linhas a uma obra já verificada\n";
$sug = $vinc->sugestoes($mar2);
confere('sugestões mostram a obra verificada parecida, com motivos', in_array($mar, array_map('intval', array_column($sug, 'id')), true) && !empty($sug[0]['motivos']), json_encode(array_column($sug, 'titulo')));
confere('sem confirmação nada muda', $recusa(fn () => $vinc->vincular($mar2, $mar, [$regDaLinha(3)], $bibId, false, $inv)) !== null && $livroDaLinha(3) === $mar2);
confere('não vincula a uma obra ainda não verificada', $recusa(fn () => $vinc->vincular($mar3, $mar2, [$regDaLinha(4)], $bibId, true, $inv)) !== null);
$r = $vinc->vincular($mar2, $mar, [$regDaLinha(3)], $bibId, true, $inv, 'Mesma edição, conferido com o livro');
$vinc->vincular($mar3, $mar, [$regDaLinha(4)], $bibId, true, $inv);
confere('com confirmação, a linha passa para a obra verificada e guarda a obra de origem', $r['movidas'] === 1 && $livroDaLinha(3) === $mar && (int)$val('SELECT livro_original_id FROM registros_legados WHERE id = ?', [$regDaLinha(3)]) === $mar2);
confere('o item Pendente do inventário acompanha a linha', (int)$val('SELECT livro_id FROM inventario_itens WHERE inventario_id = ? AND importacao_item_id = (SELECT importacao_item_id FROM registros_legados WHERE id = ?)', [$inv, $regDaLinha(3)]) === $mar);
confere('a obra repetida, sem linhas nem exemplares, é arquivada (não apagada)', $r['origem_arquivada'] && $val('SELECT arquivado_em FROM livros WHERE id = ?', [$mar2]) !== null && $num('SELECT COUNT(*) FROM livros WHERE id = ?', [$mar2]) === 1);
confere('os dados originais da planilha não mudam', $val('SELECT titulo_original FROM registros_legados WHERE id = ?', [$regDaLinha(3)]) === 'Cópias Fictícias do Mar (segunda linha)');
confere('dados originais continuam protegidos contra alteração', $recusa(fn () => $pdo->exec("UPDATE registros_legados SET titulo_original = 'x' WHERE id = " . $regDaLinha(3))) !== null);
confere('histórico de vínculos não pode ser apagado nem alterado', $recusa(fn () => $pdo->exec('DELETE FROM registro_vinculos LIMIT 1')) !== null && $recusa(fn () => $pdo->exec("UPDATE registro_vinculos SET observacao = 'x'")) !== null);
// Outro par (Poemas) para desfazer.
$verificar($poemas, ['titulo' => 'Poemas Fictícios Repetidos', 'autores' => 'Lima, Bia Fictícia', 'editora' => 'Editora Verso', 'ano_publicacao' => '1999']);
$vinc->vincular($poemas2, $poemas, [$regDaLinha(7)], $bibId, true, $inv);
confere('desfazer exige motivo', $recusa(fn () => $vinc->desfazer($regDaLinha(7), $bibId, '', $inv)) !== null);
$volta = $vinc->desfazer($regDaLinha(7), $bibId, 'Era outra tiragem', $inv);
confere('desfazer devolve a linha à obra original e a restaura', $volta === $poemas2 && $livroDaLinha(7) === $poemas2 && $val('SELECT arquivado_em FROM livros WHERE id = ?', [$poemas2]) === null
    && $val('SELECT livro_original_id FROM registros_legados WHERE id = ?', [$regDaLinha(7)]) === null);
confere('vínculo e desfazer ficam no histórico', $num("SELECT COUNT(*) FROM registro_vinculos WHERE registro_id = ? AND acao IN ('vincular_obra', 'desfazer_obra')", [$regDaLinha(7)]) === 2);
$regsMar = $invRepo->registrosDaObra($inv, $mar);
confere('a obra verificada mostra as 3 linhas da planilha, com o número da linha', count($regsMar) === 3 && array_map('intval', array_column($regsMar, 'linha')) == [2, 3, 4], json_encode(array_column($regsMar, 'linha')));

// ---------------------------------------------------------------
echo "\n2. Duas abas, dois formulários: códigos diferentes\n";
$abaA = CodigoBdpService::novoProcesso();
$abaB = CodigoBdpService::novoProcesso();
$ra = $codigos->reservar($bibId, $mar, $abaA);
$rb = $codigos->reservar($bibId, $mar, $abaB);
$ra2 = $codigos->reservar($bibId, $mar, $abaA);
confere('aba A e aba B (mesma pessoa, mesma obra) recebem códigos diferentes', $ra['codigo'] !== $rb['codigo'] && $ra['nova'] && $rb['nova']);
confere('clicar de novo na mesma aba devolve o mesmo código (nada é gasto)', $ra2['codigo'] === $ra['codigo'] && !$ra2['nova']);
$regMar = fn (string $codigo, string $proc, string $vinculo, array $extra = []) => $invServ->registrarExemplar($inv, $mar, $extra + ['codigo' => $codigo, 'processo_bdp' => $proc, 'vinculo' => $vinculo, 'estado_conservacao' => 'bom', 'situacao_inventario' => 'encontrado', 'localizacao_id' => $loc1], $bibId);
$m = $recusa(fn () => $regMar($rb['codigo'], $abaA, 'adicional'));
confere('o código da aba B não é aceito no formulário da aba A', $m !== null && str_contains($m, 'outro formulário'), (string)$m);
confere('o código de outra pessoa não é aceito', ($m = $recusa(fn () => $invServ->registrarExemplar($inv, $mar, ['codigo' => $ra['codigo'], 'processo_bdp' => $abaA, 'vinculo' => 'adicional'], $auxId))) !== null && str_contains($m, 'outra pessoa'));
$pdo->prepare('UPDATE exemplar_codigo_reservas SET reservado_em = NOW() - INTERVAL 25 HOUR WHERE codigo = ?')->execute([$rb['codigo']]);
confere('reserva não usada aparece como expirada depois de 24 h', CodigoBdpService::situacaoReserva(db_query('SELECT * FROM exemplar_codigo_reservas WHERE codigo = ?', [$rb['codigo']])->fetch()) === 'expirada');
confere('a mesma aba não recebe de volta uma reserva expirada', $codigos->reservar($bibId, $mar, $abaB)['codigo'] !== $rb['codigo']);

// ---------------------------------------------------------------
echo "\n5. Nenhuma linha escolhida automaticamente\n";
$m = $recusa(fn () => $regMar($ra['codigo'], $abaA, ''));
confere('sem escolher a linha (obra com linhas livres), o registro é recusado', $m !== null && str_contains($m, 'Escolha a qual linha'), (string)$m);
confere('nada foi gravado', $num('SELECT COUNT(*) FROM exemplares') === 0);
$x1 = $regMar($ra['codigo'], $abaA, 'r:' . $regDaLinha(3));
confere('com a linha escolhida, o exemplar fica ligado a ELA (não à primeira)', $x1['registro_id'] === $regDaLinha(3) && (int)$val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regDaLinha(3)]) === $x1['id']
    && $val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regDaLinha(2)]) === null);
confere('o item da linha vira o exemplar Encontrado (sem item duplicado)', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND importacao_item_id = (SELECT importacao_item_id FROM registros_legados WHERE id = ?)', [$inv, $regDaLinha(3)]) === 1
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ? AND conferido_em IS NOT NULL', [$inv, $x1['id']]) === 1);
confere('a linha já ligada não aceita outro exemplar', ($m = $recusa(fn () => $regMar($codigos->reservar($bibId, $mar, $abaB = CodigoBdpService::novoProcesso())['codigo'], $abaB, 'r:' . $regDaLinha(3)))) !== null && str_contains($m, 'já está ligada'), (string)$m);
confere('mesmo formulário enviado de novo: aviso, nada duplicado', (function () use ($regMar, $ra, $abaA, $num) {
    try {
        $regMar($ra['codigo'], $abaA, 'adicional');
    } catch (RegistroRepetidoException $e) {
        return $e->codigo === $ra['codigo'] && $num('SELECT COUNT(*) FROM exemplares') === 1;
    }
    return false;
})());
confere('histórico: data do código, quem registrou e a linha', $num("SELECT COUNT(*) FROM registro_vinculos WHERE registro_id = ? AND acao = 'vincular_exemplar' AND exemplar_id = ? AND administrador_id = ?", [$regDaLinha(3), $x1['id'], $bibId]) === 1
    && $val('SELECT usado_em FROM exemplar_codigo_reservas WHERE codigo = ?', [$ra['codigo']]) !== null && $val('SELECT vinculado_por FROM registros_legados WHERE id = ?', [$regDaLinha(3)]) == $bibId);

// ---------------------------------------------------------------
echo "\n3. Cancelar reserva\n";
$abaC = CodigoBdpService::novoProcesso();
$rc = $codigos->reservar($bibId, $mar, $abaC);
confere('só quem gerou cancela (outra pessoa não)', $codigos->cancelar($rc['codigo'], $auxId, $abaC) === false);
confere('outro formulário da mesma pessoa não cancela', $codigos->cancelar($rc['codigo'], $bibId, CodigoBdpService::novoProcesso()) === false);
confere('quem gerou, no mesmo formulário, cancela', $codigos->cancelar($rc['codigo'], $bibId, $abaC) === true && $val('SELECT cancelada_em FROM exemplar_codigo_reservas WHERE codigo = ?', [$rc['codigo']]) !== null);
confere('código cancelado não é aceito ao salvar', ($m = $recusa(fn () => $regMar($rc['codigo'], $abaC, 'adicional'))) !== null && str_contains($m, 'cancelado'), (string)$m);
$rc2 = $codigos->reservar($bibId, $mar, $abaC);
confere('o número cancelado não volta: o formulário recebe um código novo e maior', $rc2['codigo'] !== $rc['codigo'] && $rc2['codigo'] > $rc['codigo']);
confere('reservas canceladas continuam guardadas (não podem ser apagadas)', $recusa(fn () => $pdo->exec("DELETE FROM exemplar_codigo_reservas WHERE codigo = '" . $rc['codigo'] . "'")) !== null);

// ---------------------------------------------------------------
echo "\n6. Exemplar adicional, sem linha correspondente\n";
$x2 = $regMar($rc2['codigo'], $abaC, 'adicional', ['situacao_inventario' => 'pendente']);
confere('exemplar adicional não liga nenhuma linha (as livres continuam sem exemplar)', $x2['registro_id'] === null && $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ? AND exemplar_id IS NULL', [$mar]) === 2);
confere('situação Pendente: o exemplar entra no inventário sem estar encontrado', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ? AND conferido_em IS NULL AND localizacao_esperada_id = ?', [$inv, $x2['id'], $loc1]) === 1);

// ---------------------------------------------------------------
echo "\n7. Obra sem ISBN\n";
$res = $verificar($contos, ['titulo' => 'Contos Fictícios Sem ISBN', 'autores' => 'Rocha, Davi Fictício', 'isbn' => '']);
$procContos = CodigoBdpService::novoProcesso();
$xc = $invServ->registrarExemplar($inv, $contos, ['codigo' => $codigos->reservar($bibId, $contos, $procContos)['codigo'], 'processo_bdp' => $procContos, 'vinculo' => 'r:' . $regDaLinha(5), 'estado_conservacao' => 'regular', 'localizacao_id' => $loc2], $bibId);
confere('obra sem ISBN é verificada e recebe exemplar normalmente', $res['verificacao'] === 'verificado' && $val('SELECT isbn FROM livros WHERE id = ?', [$contos]) === null && $xc['registro_id'] === $regDaLinha(5));

// ---------------------------------------------------------------
echo "\n4. Duas pessoas registrando ao mesmo tempo (8 formulários em paralelo)\n";
$inicio = microtime(true) + 1.2;
$filhos = [];
foreach ([[$bibId, 'r:' . $regDaLinha(2)], [$auxId, 'r:' . $regDaLinha(2)], [$bibId, 'adicional'], [$auxId, 'adicional'], [$bibId, 'adicional'], [$auxId, 'adicional'], [$bibId, 'adicional'], [$auxId, 'adicional']] as $i => [$adm, $v]) {
    $filhos[$i] = proc_open([PHP_BINARY, __FILE__, '--registrar', (string)$inv, (string)$mar, (string)$adm, (string)$inicio, $v], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp[$i], null, array_merge(getenv(), $env));
}
$saidas = [];
foreach ($filhos as $i => $f) {
    $saidas[$i] = trim(stream_get_contents($pp[$i][1]) . stream_get_contents($pp[$i][2]));
    proc_close($f);
}
$oks = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'ok:')));
$codigosFilhos = array_map(fn ($s) => explode(':', $s)[1] ?? '', $saidas);
confere('8 formulários ao mesmo tempo recebem 8 códigos diferentes', count(array_unique(array_filter($codigosFilhos))) === 8, implode(' | ', $saidas));
confere('a mesma linha disputada por duas pessoas fica com UM exemplar; a outra é avisada', count($oks) === 7 && (str_starts_with($saidas[0], 'ok:') xor str_starts_with($saidas[1], 'ok:'))
    && str_contains($saidas[0] . $saidas[1], 'ligada'), implode(' | ', $saidas));
confere('nenhum código repetido no banco e só um exemplar ligado à linha', $num('SELECT COUNT(*) FROM exemplares') === $num('SELECT COUNT(DISTINCT codigo_individual) FROM exemplares')
    && $val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regDaLinha(2)]) !== null && $num('SELECT COUNT(*) FROM registro_vinculos WHERE registro_id = ? AND acao = \'vincular_exemplar\'', [$regDaLinha(2)]) === 1);

// ---------------------------------------------------------------
echo "\n10. Desfazer vínculo linha × exemplar feito por engano\n";
$exLinha2 = (int)$val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regDaLinha(2)]);
confere('desfazer exige motivo', $recusa(fn () => $invServ->desfazerVinculoLinha($inv, $regDaLinha(2), $bibId, '')) !== null);
$invServ->desfazerVinculoLinha($inv, $regDaLinha(2), $bibId, 'Escolhi a linha errada');
confere('a linha volta para "sem exemplar" e Pendente; o exemplar continua existindo', $val('SELECT exemplar_id FROM registros_legados WHERE id = ?', [$regDaLinha(2)]) === null
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id IS NULL AND importacao_item_id = (SELECT importacao_item_id FROM registros_legados WHERE id = ?)', [$inv, $regDaLinha(2)]) === 1
    && $num('SELECT COUNT(*) FROM exemplares WHERE id = ?', [$exLinha2]) === 1);
confere('fica no histórico (vincular + desfazer)', $num("SELECT COUNT(*) FROM registro_vinculos WHERE registro_id = ? AND acao = 'desfazer_exemplar'", [$regDaLinha(2)]) === 1);

// ---------------------------------------------------------------
echo "\n11. Estante atual: finalizar e reabrir\n";
// Na estante 1: x1 (encontrado), x2 (pendente), e mais dois pendentes. Um exemplar sem localização não conta.
$pend = [];
foreach ([1, 2] as $k) {
    $p = CodigoBdpService::novoProcesso();
    $pend[] = $regMar($codigos->reservar($bibId, $mar, $p)['codigo'], $p, 'adicional', ['situacao_inventario' => 'pendente']);
}
$p = CodigoBdpService::novoProcesso();
$semLocal = $regMar($codigos->reservar($bibId, $mar, $p)['codigo'], $p, 'adicional', ['situacao_inventario' => 'pendente', 'localizacao_id' => '']);
$est = $invRepo->resumoEstante($inv, $loc1);
confere('a estante conta só exemplares cadastrados nela (sem localização fica de fora)', $est['pendentes'] === 3 && $est['encontrados_aqui'] >= 1
    && !in_array($semLocal['item_id'], array_map('intval', array_column($invRepo->pendentesEstante($inv, $loc1), 'id')), true), json_encode($est));
confere('linhas da planilha sem localização não entram na estante', !array_filter($invRepo->pendentesEstante($inv, $loc1), fn ($i) => $i['exemplar_id'] === null));
$itemX2 = (int)$val('SELECT id FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv, $x2['id']]);
$r = $invServ->finalizarEstante($inv, $loc1, [$itemX2, $semLocal['item_id']], 'Procurado duas vezes', $bibId);
confere('finalizar marca só o item escolhido E desta estante; os outros continuam Pendentes', $r['nao_localizados'] === 1 && $r['pendentes'] === 2
    && $val('SELECT nao_localizado_em FROM inventario_itens WHERE id = ?', [$semLocal['item_id']]) === null);
confere('estante fica "finalizada", com quem e quando', $val('SELECT status FROM inventario_estantes WHERE inventario_id = ? AND localizacao_id = ?', [$inv, $loc1]) === 'finalizada'
    && (int)$val('SELECT finalizada_por FROM inventario_estantes WHERE inventario_id = ? AND localizacao_id = ?', [$inv, $loc1]) === $bibId);
confere('a situação do exemplar no acervo não muda (não vira extraviado)', $val('SELECT situacao FROM exemplares WHERE id = ?', [$x2['id']]) !== 'extraviado');
$n = $invServ->reabrirEstante($inv, $loc1, true, $bibId);
confere('reabrir volta a estante para "aberta" e os não localizados para Pendente', $n === 1 && $val('SELECT status FROM inventario_estantes WHERE inventario_id = ? AND localizacao_id = ?', [$inv, $loc1]) === 'aberta'
    && $val('SELECT nao_localizado_em FROM inventario_itens WHERE id = ?', [$itemX2]) === null && $val('SELECT reaberta_em FROM inventario_estantes WHERE inventario_id = ? AND localizacao_id = ?', [$inv, $loc1]) !== null);

// ---------------------------------------------------------------
echo "\n12. Livro não listado\n";
$procNl = CodigoBdpService::novoProcesso();
$codNl = $codigos->reservar($bibId, null, $procNl)['codigo'];
$nl = $cad->cadastrarNaoListado($inv, $cad->dados(['titulo' => 'Livro Fictício Não Listado', 'autores' => 'Prado, Eva Fictícia']), ['codigo' => $codNl, 'processo_bdp' => $procNl, 'localizacao_id' => $loc2], $bibId, null, true);
confere('obra criada, verificada e com o exemplar do código gerado no formulário', $nl['novo_livro'] && $nl['verificado'] && $nl['codigo'] === $codNl && $num('SELECT COUNT(*) FROM livros WHERE titulo = ?', ['Livro Fictício Não Listado']) === 1);
$m = $recusa(fn () => $cad->cadastrarNaoListado($inv, $cad->dados(['titulo' => 'Outro Fictício']), ['codigo' => $rc2['codigo'], 'processo_bdp' => $procNl], $bibId, null, true));
confere('não listado com código já usado é recusado e nada é criado', $m !== null && $num("SELECT COUNT(*) FROM livros WHERE titulo = 'Outro Fictício'") === 0, (string)$m);

// ---------------------------------------------------------------
echo "\n13. Registros seguidos pela página\n";
$base = teste_servidor($env);
$bib = new TesteNavegador($base);
confere('bibliotecária entra no painel', $bib->entrar('bib@teste.br', $senha) === 200);
$pg = 'admin/inventario-conferencia.php?id=' . $inv;
$tk = $bib->csrf($pg);
$bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'local', 'local_conferencia' => $loc2]);
[$s, $html] = $bib->ir($pg . '&obra=' . $mar . '&painel=registrar');
preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $mm);
$procPg = $mm[1] ?? '';
confere('painel "Registrar exemplar": formulário com identificação própria e estante atual preenchida', $s === 200 && $procPg !== '' && str_contains($html, 'id="painel-registrar"')
    && preg_match('/name="localizacao_id".*?<option value="' . $loc2 . '" selected/s', $html) === 1);
confere('nenhuma linha da planilha vem marcada (só "adicional" quando não há linhas livres)', preg_match('/name="vinculo" value="r:\d+"[^>]*checked/', $html) === 0 && str_contains($html, 'Exemplar adicional, sem linha correspondente'));
[$s, $json] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $mar, 'processo' => $procPg]);
$codPg = json_decode($json, true)['codigo'] ?? '';
$post = ['csrf' => $tk, 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $mar, 'codigo_bdp' => $codPg, 'processo_bdp' => $procPg, 'vinculo' => 'r:' . $regDaLinha(2),
    'estado_conservacao' => 'regular', 'localizacao_id' => $loc2, 'situacao_inventario' => 'encontrado'];
[$s, , $loc] = $bib->ir($pg, $post);
confere('salvar pela página grava e mostra o código em destaque', $s === 303 && str_contains($loc, 'criados=') && $num('SELECT COUNT(*) FROM exemplares WHERE codigo_individual = ?', [$codPg]) === 1, "$s $loc");
$antes = $num('SELECT COUNT(*) FROM exemplares');
[$s, , $loc2Url] = $bib->ir($pg, $post);
[, $html] = $bib->ir(ltrim((string)parse_url($loc2Url, PHP_URL_PATH), '/') . '?' . parse_url($loc2Url, PHP_URL_QUERY));
confere('clique duplo (mesmo formulário de novo): nada duplicado, aviso claro', $s === 303 && $num('SELECT COUNT(*) FROM exemplares') === $antes && str_contains($html, 'Nada foi gravado de novo'));
[$s, $html] = $bib->ir($pg . '&obra=' . $mar . '&painel=registrar&copia=1');
preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $mm);
confere('"Registrar outra cópia" mantém obra, localização e estado, com formulário novo', $s === 200 && str_contains($html, 'outra cópia') && ($mm[1] ?? '') !== $procPg
    && preg_match('/name="localizacao_id".*?<option value="' . $loc2 . '" selected/s', $html) === 1 && preg_match('/name="estado_conservacao".*?value="regular" selected/s', $html) === 1);
[$s, $json] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $mar, 'processo' => $mm[1] ?? '']);
confere('a outra cópia recebe um código novo', (json_decode($json, true)['codigo'] ?? $codPg) !== $codPg, $json);
[$s, , $l] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'cancelar_registro', 'livro_id' => $mar, 'codigo_bdp' => json_decode($json, true)['codigo'] ?? '', 'processo_bdp' => $mm[1] ?? '']);
confere('"Cancelar" no painel cancela o código gerado', $s === 303 && $val('SELECT cancelada_em FROM exemplar_codigo_reservas WHERE codigo = ?', [json_decode($json, true)['codigo'] ?? '']) !== null);
[$s] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'finalizar_estante', 'localizacao_id' => $loc1, 'itens' => [$itemX2]]);
confere('finalizar estante pela página sem a confirmação: nada é marcado', $val('SELECT nao_localizado_em FROM inventario_itens WHERE id = ?', [$itemX2]) === null);
$hdr = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'verificar_obra', 'livro_id' => $busca, 'marcar_verificado' => '1', 'titulo' => 'Livro Fictício Para Pesquisa', 'autores' => 'Souza, Caio Fictício', 'editar_livro' => '1']);
confere('"Salvar e marcar dados como verificados" abre em seguida o painel do exemplar', $hdr[0] === 303 && str_contains((string)$hdr[2], 'painel=registrar') && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$busca]) !== null, (string)$hdr[2]);

// ---------------------------------------------------------------
echo "\n8. Inventário novo depois de registrar só parte das cópias\n";
$livresMar = $num('SELECT COUNT(*) FROM registros_legados WHERE livro_id = ? AND exemplar_id IS NULL', [$mar]);
$invServ->cancelar($inv, $bibId, 'Teste fictício: abrir outro inventário');
$inv2 = $invServ->criar(['titulo' => 'Segundo Inventário Fictício', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv2, $bibId);
confere('linhas ainda sem exemplar entram de novo como Pendente, mesmo com a obra já tendo cópias', $livresMar === 1
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND livro_id = ? AND exemplar_id IS NULL', [$inv2, $mar]) === $livresMar);
confere('linhas já ligadas não entram duplicadas: vêm junto com o exemplar', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND importacao_item_id = (SELECT importacao_item_id FROM registros_legados WHERE id = ?)', [$inv2, $regDaLinha(3)]) === 1
    && (int)$val('SELECT exemplar_id FROM inventario_itens WHERE inventario_id = ? AND importacao_item_id = (SELECT importacao_item_id FROM registros_legados WHERE id = ?)', [$inv2, $regDaLinha(3)]) === $x1['id']);
confere('"Incluir linhas que ficaram de fora" não duplica nada', $invServ->registrosSemExemplarFaltando($inv2) === 0 && $invServ->completarRegistrosSemExemplar($inv2, $bibId) === 0);
confere('o inventário cancelado continua no histórico, sem alterações', $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'cancelado' && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ?', [$inv]) > 0);

// ---------------------------------------------------------------
echo "\n14. Indicadores, pesquisa e resposta parcial\n";
$ind = $invRepo->indicadores($inv2);
confere('indicadores: obras, verificadas e não verificadas fecham a conta', $ind['obras'] === $ind['obras_verificadas'] + $ind['obras_nao_verificadas'] && $ind['obras'] === $num('SELECT COUNT(*) FROM livros WHERE arquivado_em IS NULL'), json_encode($ind));
confere('indicadores: exemplares físicos e linhas sem vínculo contados separadamente', $ind['exemplares'] === $num("SELECT COUNT(*) FROM exemplares WHERE situacao <> 'baixado'")
    && $ind['linhas_sem_vinculo'] === $num('SELECT COUNT(*) FROM registros_legados r JOIN livros l ON l.id = r.livro_id AND l.arquivado_em IS NULL WHERE r.exemplar_id IS NULL') && $ind['sem_codigo'] === 0);
confere('progresso usa só exemplares deste inventário (linhas da planilha à parte)', $ind['ex_total'] === $ind['ex_encontrados'] + $ind['ex_pendentes'] + $ind['ex_nao_localizados'] + $ind['ex_emprestados']
    && $ind['linhas_total'] === $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id IS NULL', [$inv2]));
$pg2 = 'admin/inventario-conferencia.php?id=' . $inv2;
[$s, $html] = $bib->ir($pg2 . '&q=' . rawurlencode('Fictícios'));
confere('pesquisa por título mostra obras com status e botões, sem erro', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Obras para') && str_contains($html, 'Contos Fictícios Sem ISBN') && str_contains($html, 'Dados verificados'));
$pesq = $invRepo->pesquisarLivros($inv2, 'Fictíci', 2, 1);
$pesq2 = $invRepo->pesquisarLivros($inv2, 'Fictíci', 2, 2);
confere('pesquisa paginada sem repetir resultados', count($pesq['livros']) === 2 && count($pesq2['livros']) >= 1 && !array_intersect(array_column($pesq['livros'], 'id'), array_column($pesq2['livros'], 'id')), json_encode(array_keys($pesq)));
[$s, $frag] = $bib->ir($pg2 . '&obra=' . $mar, null, ['X-Conferencia' => '1']);
confere('resposta parcial (X-Conferencia) traz só as regiões, sem o layout', $s === 200 && str_contains($frag, 'data-regiao="indicadores"') && str_contains($frag, 'data-regiao="area"') && str_contains($frag, 'data-regiao="estante"') && !str_contains($frag, '<nav'));
[$s, $html] = $bib->ir($pg2 . '&obra=' . $mar);
confere('ficha da obra: linhas da planilha com vínculo, exemplares e indicadores', $s === 200 && str_contains($html, 'Linhas da planilha desta obra') && str_contains($html, 'Ligada ao ' . $x1['codigo']) && str_contains($html, 'Linhas sem vínculo físico'));
$cons = new TesteNavegador($base);
$cons->entrar('cons@teste.br', $senha);
[$s] = $cons->ir($pg2, ['csrf' => $cons->csrf('admin/perfil.php'), 'id' => $inv2, 'acao' => 'finalizar_estante', 'localizacao_id' => $loc1]);
confere('perfil consulta não finaliza estante nem vincula obras (403)', $s === 403);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
