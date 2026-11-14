<?php
/**
 * Testes da separação OBRA × EXEMPLAR no Inventário (migração 120).
 *
 * Uso: php admin/tests/obra-exemplares-teste.php
 *
 * Tudo acontece num banco temporário "bdp_teste_obraex_XXXX" (migrações aplicadas só nele, apagado
 * no final), com dados FICTÍCIOS. Cobre:
 *   * registros importados começam com "Dados não verificados", sem exemplar e sem contar como encontrados;
 *     títulos e ISBN iguais NÃO são unidos;
 *   * "Salvar e marcar dados como verificados" (dados ausentes podem continuar vazios), histórico,
 *     alteração relevante devolve a obra para "Dados não verificados", alteração secundária mantém;
 *   * "+ Registrar exemplar": só para obra verificada, código obrigatório, vínculo com a linha da
 *     planilha, vários exemplares da mesma obra sem repetir o cadastro, Encontrado / Pendente;
 *   * gerador de código BDP: reserva no servidor, mesma reserva para quem clica de novo, códigos
 *     diferentes para pessoas diferentes, código de outra pessoa recusado, código digitado à mão
 *     validado, código de exemplar baixado nunca reaproveitado, processos SIMULTÂNEOS sem repetição;
 *   * histórico e reservas não podem ser apagados;
 *   * páginas: ficha da obra, painéis, API "Gerar código BDP" (login, permissão, CSRF, método).
 */

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\LivroRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\ConferenciaCadastroService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\ObraVerificacaoService;

// Processo filho do teste de concorrência: gera códigos e salva exemplares ao mesmo tempo que os outros.
if (($argv[1] ?? '') === '--filho') {
    require __DIR__ . '/../../config/app.php';
    [, , $livroFilho, $adminFilho, $voltas, $inicio] = $argv;
    $codigos = new CodigoBdpService(db());
    $exemplares = new ExemplarService(db());
    while (microtime(true) < (float)$inicio) {
        usleep(300);
    }
    $saida = [];
    try {
        for ($i = 0; $i < (int)$voltas; $i++) {
            if ($i % 2 === 0) {
                $r = $codigos->reservar((int)$adminFilho, (int)$livroFilho);      // "Gerar código BDP"
                $saida[] = $exemplares->cadastrarComCodigo((int)$livroFilho, $r['codigo'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], (int)$adminFilho)['codigo'];
            } else {
                $saida[] = $exemplares->cadastrar((int)$livroFilho, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], (int)$adminFilho)[0]['codigo']; // próximo livre
            }
        }
        echo implode(',', $saida);
    } catch (Throwable $e) {
        echo 'erro:' . get_class($e) . ':' . $e->getMessage();
    }
    exit(0);
}

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('obraex', ['STORAGE_IMPORTACOES' => sys_get_temp_dir() . '/bdp-obraex-' . bin2hex(random_bytes(3))]);
$_SERVER['SCRIPT_NAME'] = '/admin/tests/obra-exemplares-teste.php';
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
$auxId = $contas['auxiliar'];
$repo = importacao_repo();
$exServ = new ExemplarService($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);
$cad = new ConferenciaCadastroService($pdo, $invServ);
$codigos = new CodigoBdpService($pdo);
$verif = new ObraVerificacaoService($pdo);

// ---------------------------------------------------------------
echo "\n1. Migração 120 e registros importados\n";
confere('colunas e tabelas novas existem', $num("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'livros' AND column_name IN ('dados_verificados_em', 'dados_verificados_por')") === 2
    && $num("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('livro_verificacoes', 'exemplar_codigo_reservas')") === 2);
$pdo->exec("INSERT INTO localizacoes (setor, estante, prateleira, codigo) VALUES ('Literatura Fictícia', 'E01', 'P01', 'LF-E01-P01')");
$local = (int)$pdo->lastInsertId();
$inv = $invServ->criar(['titulo' => 'Inventário Fictício Obra x Exemplar', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv, $bibId);
$cab = ['Localização', 'Título', 'Sobrenome/Prenome do Autor', 'Editora', 'ISBN', 'Qtd', 'Ano'];
$mapa = importacao_validador()->sugerirMapeamento($cab);
$imp = $repo->criar(['nome_arquivo' => 'acervo-obra-exemplar.csv', 'identificador_arquivo' => hash('sha256', 'obra-ex'), 'arquivo_armazenado' => 'x.csv.upload',
    'tipo_arquivo' => 'csv', 'tamanho_bytes' => 100, 'abas' => [['nome' => 'CSV', 'linhas' => 4, 'colunas' => count($cab)]], 'aba' => 'CSV', 'usuario_id' => $bibId, 'total_linhas' => 0]);
$repo->iniciarAnalise($imp, 'CSV', 1, $cab, $mapa);
$linhas = [
    2 => ['A-01', 'O Pequeno Príncipe Fictício', 'Saint, Autor Fictício', 'Editora Teste', '', '3', '1990'],
    3 => ['A-01', 'O Pequeno Príncipe Fictício', 'Saint, Autor Fictício', 'Outra Editora', '', '1', '2015'],
    4 => ['A-02', 'Contos Sem ISBN Fictícios', 'Rocha, Davi Fictício', '', '', '', ''],
];
$repo->inserirLinhas($imp, array_map(fn ($l) => array_combine($cab, array_pad($l, count($cab), '')), $linhas), $mapa, 4);
$repo->concluirAnalise($imp);
$repo->importarTodos($imp, $bibId);
$livroDa = fn (int $linha) => (int)$val('SELECT r.livro_id FROM registros_legados r WHERE r.linha = ? ORDER BY r.id LIMIT 1', [$linha]);
$principe = $livroDa(2);
$principe2 = $livroDa(3);
$contos = $livroDa(4);
confere('títulos iguais não são unidos: duas obras separadas', $principe > 0 && $principe2 > 0 && $principe !== $principe2);
confere('todas as obras importadas começam com "Dados não verificados"', $num('SELECT COUNT(*) FROM livros WHERE dados_verificados_em IS NOT NULL') === 0);
confere('nenhum exemplar é criado pela importação (nem pela quantidade da planilha)', $num('SELECT COUNT(*) FROM exemplares') === 0);
$r = $invRepo->resumo($inv);
confere('linhas importadas ficam Pendentes no inventário e não contam como encontradas', $r['itens'] === 3 && $r['sem_codigo'] === 3 && $r['encontrados'] === 0 && $r['obras'] === 3 && $r['obras_verificadas'] === 0, json_encode($r));
$regPrincipe = $invRepo->registrosSemExemplar($inv, $principe);
confere('a obra mostra a sua linha da planilha sem exemplar', count($regPrincipe) === 1 && (int)$regPrincipe[0]['origem_linha'] === 2);

// ---------------------------------------------------------------
echo "\n2. Verificação dos dados da obra\n";
$dadosPrincipe = ['titulo' => 'O Pequeno Príncipe Fictício', 'autores' => 'Saint, Autor Fictício', 'editora' => 'Editora Teste', 'ano_publicacao' => '1990', 'isbn' => ''];
$res = $cad->atualizarLivro($principe, $cad->dados($dadosPrincipe), $bibId);
confere('"Salvar alterações" sem marcar não verifica', $res['verificacao'] === 'nao_verificado' && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$principe]) === null);
$res = $cad->atualizarLivro($principe, $cad->dados($dadosPrincipe), $bibId, false, true, $inv);
confere('"Salvar e marcar dados como verificados" verifica, com ISBN vazio', $res['verificacao'] === 'verificado' && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$principe]) !== null
    && (int)$val('SELECT dados_verificados_por FROM livros WHERE id = ?', [$principe]) === $bibId && $val('SELECT isbn FROM livros WHERE id = ?', [$principe]) === null);
$pdo->exec("INSERT INTO categorias (nome) VALUES ('Infantojuvenil Fictício')");
$cat = (int)$pdo->lastInsertId();
$res = $cad->atualizarLivro($principe, $cad->dados($dadosPrincipe + ['categoria_id' => (string)$cat]), $bibId);
confere('mudar só a categoria mantém "Dados verificados" (fica no histórico)', $res['verificacao'] === 'verificado' && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$principe]) !== null
    && $num("SELECT COUNT(*) FROM livro_verificacoes WHERE livro_id = ? AND acao = 'alterado'", [$principe]) === 1);
$res = $cad->atualizarLivro($principe, $cad->dados(['titulo' => 'O Pequeno Príncipe (Fictício)'] + $dadosPrincipe + ['categoria_id' => (string)$cat]), $bibId);
$hist = $verif->historico($principe);
confere('mudar o título de uma obra verificada a devolve para "Dados não verificados"', $res['verificacao'] === 'nao_verificado' && $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$principe]) === null
    && $hist[0]['acao'] === 'verificacao_retirada' && ($hist[0]['alteracoes']['titulo']['antes'] ?? '') === 'O Pequeno Príncipe Fictício' && ($hist[0]['alteracoes']['titulo']['depois'] ?? '') === 'O Pequeno Príncipe (Fictício)');
$cad->atualizarLivro($principe, $cad->dados(['titulo' => 'O Pequeno Príncipe (Fictício)'] + $dadosPrincipe + ['categoria_id' => (string)$cat]), $bibId, false, true, $inv);
confere('nova verificação depois da alteração', $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$principe]) !== null && $verif->historico($principe)[0]['acao'] === 'verificado'
    && str_contains(ObraVerificacaoService::descrever($verif->historico($principe)[0]), 'marcados como verificados'));
// Edição pela página "Editar livro" (LivroRepository) também respeita a regra.
$livrosRepo = new LivroRepository($pdo);
$atual = $livrosRepo->buscar($contos);
$cad->atualizarLivro($contos, $cad->dados(['titulo' => 'Contos Sem ISBN Fictícios', 'autores' => 'Rocha, Davi Fictício']), $bibId, false, true, $inv);
$livrosRepo->atualizar($contos, ['titulo' => 'Contos Sem ISBN Fictícios', 'autores' => ['Rocha, Davi Fictício'], 'editora' => 'Editora Nova Fictícia', 'status_validacao' => 'validado', 'idioma' => 'Português'], null, $bibId);
confere('"Editar livro": mudar a editora de obra verificada também pede nova verificação', $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$contos]) === null
    && $num("SELECT COUNT(*) FROM livro_verificacoes WHERE livro_id = ? AND acao = 'verificacao_retirada' AND origem = 'editar_livro'", [$contos]) === 1);
confere('o histórico de verificação não pode ser apagado nem alterado', $recusa(function () use ($pdo) {
    try {
        $pdo->exec('DELETE FROM livro_verificacoes LIMIT 1');
    } catch (PDOException $e) {
        throw new RuntimeException($e->getMessage());
    }
}) !== null && $recusa(function () use ($pdo) {
    try {
        $pdo->exec("UPDATE livro_verificacoes SET acao = 'alterado'");
    } catch (PDOException $e) {
        throw new RuntimeException($e->getMessage());
    }
}) !== null);

// ---------------------------------------------------------------
echo "\n3. Gerador de código BDP\n";
$seq = $num('SELECT ultimo FROM exemplar_codigo_seq');
$g1 = $codigos->reservar($bibId, $principe);
confere('gera o próximo número no formato dos carimbos (BDP-000001)', $g1['nova'] && $g1['codigo'] === sprintf('BDP-%06d', $seq + 1) && $num('SELECT ultimo FROM exemplar_codigo_seq') === $seq + 1);
$g2 = $codigos->reservar($bibId, $principe);
confere('clicar de novo sem usar devolve a MESMA reserva (não gasta números)', !$g2['nova'] && $g2['codigo'] === $g1['codigo'] && $num('SELECT ultimo FROM exemplar_codigo_seq') === $seq + 1);
$g3 = $codigos->reservar($auxId, $principe);
confere('outra pessoa recebe outro código', $g3['codigo'] !== $g1['codigo'] && $g3['codigo'] === sprintf('BDP-%06d', $seq + 2));
$msg = $recusa(fn () => $codigos->validarDisponivel($g1['codigo'], $auxId));
confere('código gerado para outra pessoa é recusado (pode já estar no carimbo dela)', $msg !== null && str_contains($msg, 'outra pessoa'));
confere('código incompleto é recusado', $recusa(fn () => $codigos->validarDisponivel('BDP-12', $bibId)) !== null);
confere('código livre digitado à mão é aceito', $codigos->validarDisponivel(' bdp 000900 ', $bibId) === 'BDP-000900');

// ---------------------------------------------------------------
echo "\n4. Registrar exemplares (obra verificada)\n";
$msg = $recusa(fn () => $invServ->registrarExemplar($inv, $principe2, ['codigo' => 'BDP-000901', 'situacao_inventario' => 'encontrado'], $bibId));
confere('obra com "Dados não verificados" não registra exemplar', $msg !== null && str_contains($msg, 'Verifique os dados da obra') && $num('SELECT COUNT(*) FROM exemplares') === 0);
$msg = $recusa(fn () => $invServ->registrarExemplar($inv, $principe, ['codigo' => '', 'situacao_inventario' => 'encontrado'], $bibId));
confere('o código BDP é obrigatório (gerado ou digitado)', $msg !== null && str_contains($msg, 'Gerar código BDP'));
$msg = $recusa(fn () => $invServ->registrarExemplar($inv, $principe, ['codigo' => $g3['codigo'], 'situacao_inventario' => 'encontrado'], $bibId));
confere('salvar com o código reservado por outra pessoa é recusado', $msg !== null && str_contains($msg, 'outra pessoa'));
$msg = $recusa(fn () => $invServ->registrarExemplar($inv, $principe, ['codigo' => $g1['codigo'], 'item_id' => (int)$invRepo->registrosSemExemplar($inv, $contos)[0]['id']], $bibId));
confere('não liga o exemplar à linha da planilha de outra obra', $msg !== null && str_contains($msg, 'não pertence'));
$e1 = $invServ->registrarExemplar($inv, $principe, ['codigo' => $g1['codigo'], 'estado_conservacao' => 'bom', 'localizacao_id' => $local, 'situacao_inventario' => 'encontrado',
    'item_id' => (int)$regPrincipe[0]['id'], 'observacoes' => 'Carimbo novo'], $bibId);
$it1 = $invRepo->item($e1['item_id']);
confere('exemplar 1: ligado à linha 2 da planilha, Encontrado, com localização', $e1['codigo'] === $g1['codigo'] && (int)$it1['id'] === (int)$regPrincipe[0]['id']
    && $it1['codigo_individual'] === $g1['codigo'] && $it1['conferido_em'] !== null && (int)$it1['importacao_item_id'] > 0 && (int)$it1['localizacao_encontrada_id'] === $local);
confere('a reserva vira definitiva (usada pelo exemplar salvo)', $val('SELECT exemplar_id FROM exemplar_codigo_reservas WHERE codigo = ?', [$g1['codigo']]) == $e1['id']);
confere('salvar de novo o mesmo código é recusado (UNIQUE)', $recusa(fn () => $invServ->registrarExemplar($inv, $principe, ['codigo' => $g1['codigo']], $bibId)) !== null);
$msg = $recusa(fn () => $invServ->registrarExemplar($inv, $principe, ['codigo' => $codigos->reservar($bibId, $principe)['codigo'], 'item_id' => (int)$regPrincipe[0]['id']], $bibId));
confere('a mesma linha da planilha não recebe dois exemplares', $msg !== null && str_contains($msg, 'já está ligada'));
$g4 = $codigos->reservar($bibId, $principe);
confere('depois de salvar, "Gerar" entrega o próximo número (a reserva anterior ficou presa à falha acima e é reaproveitada só por quem a recebeu)', $g4['codigo'] !== $g1['codigo']);
$e2 = $invServ->registrarExemplar($inv, $principe, ['codigo' => $g4['codigo'], 'situacao_inventario' => 'encontrado'], $bibId);
$e3 = $invServ->registrarExemplar($inv, $principe, ['codigo' => 'BDP-000900', 'situacao_inventario' => 'pendente'], $bibId);
$exs = $invRepo->exemplaresDaObra($inv, $principe);
confere('vários exemplares da mesma obra sem duplicar o cadastro bibliográfico', count($exs) === 3 && $num('SELECT COUNT(*) FROM livros WHERE titulo LIKE ?', ['O Pequeno Príncipe (Fictício)']) === 1);
confere('cada exemplar tem a sua situação no inventário (Encontrado, Encontrado, Pendente)', array_map(fn ($x) => inventario_status_teste($x), $exs) === ['Encontrado', 'Encontrado', 'Pendente'], json_encode(array_column($exs, 'codigo_individual')));
confere('o exemplar Pendente fica "Aguardando" e o Encontrado "Disponível"', $val('SELECT situacao FROM exemplares WHERE id = ?', [$e3['id']]) === 'aguardando' && $val('SELECT situacao FROM exemplares WHERE id = ?', [$e2['id']]) === 'disponivel');
confere('código digitado maior que a sequência faz a sequência avançar (nunca repete)', $num('SELECT ultimo FROM exemplar_codigo_seq') >= 900 && $codigos->reservar($auxId, null)['codigo'] !== 'BDP-000900');
$r = $invRepo->resumo($inv);
confere('contadores: 2 encontrados; as linhas sem exemplar das outras obras continuam fora da conta', $r['encontrados'] === 2 && $r['sem_codigo'] === 2 && $r['obras_verificadas'] === 1, json_encode($r));
confere('a obra parecida (outra edição) continua sem exemplares: nada foi unido', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$principe2]) === 0 && count($invRepo->registrosSemExemplar($inv, $principe2)) === 1);
$conf = $invServ->conferir($inv, $e3['id'], ['estado_conservacao' => 'bom'], $bibId);
confere('o exemplar Pendente pode ser marcado como Encontrado depois, pelo código', $conf['conferido_em'] !== null && $invRepo->resumo($inv)['encontrados'] === 3);
// Baixa: o código nunca volta.
$exServ->alterarSituacao($e2['id'], 'disponivel', $bibId);
$exServ->baixar($e2['id'], $bibId, 'teste de baixa (fictício)');
$msg = $recusa(fn () => $codigos->validarDisponivel($g4['codigo'], $bibId));
confere('código de exemplar baixado nunca é reaproveitado', $msg !== null && str_contains($msg, 'já baixado'));
confere('reservas não podem ser apagadas', $recusa(function () use ($pdo) {
    try {
        $pdo->exec('DELETE FROM exemplar_codigo_reservas LIMIT 1');
    } catch (PDOException $e) {
        throw new RuntimeException($e->getMessage());
    }
}) !== null);

// ---------------------------------------------------------------
echo "\n5. Códigos gerados ao mesmo tempo (processos paralelos)\n";
$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Livro Fictício Concorrência', NOW())");
$livroConc = (int)$pdo->lastInsertId();
$admins = [];
for ($i = 0; $i < 6; $i++) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Equipe Fictícia ' . $i, "equipe$i@teste.br", 'x', 'auxiliar']);
    $admins[] = (int)$pdo->lastInsertId();
}
$inicio = microtime(true) + 1.2;
$processos = [];
$pipes = [];
foreach ($admins as $i => $adm) {
    $processos[] = proc_open([PHP_BINARY, __FILE__, '--filho', (string)$livroConc, (string)$adm, '8', (string)$inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), $env));
}
$todos = [];
$erros = [];
foreach ($processos as $i => $proc) {
    $out = trim(stream_get_contents($pipes[$i][1]));
    $err = trim(stream_get_contents($pipes[$i][2]));
    proc_close($proc);
    if (str_starts_with($out, 'BDP-')) {
        array_push($todos, ...explode(',', $out));
    } else {
        $erros[] = $out . $err;
    }
}
confere('6 pessoas × 8 exemplares ao mesmo tempo: 48 códigos, sem erro', count($todos) === 48 && !$erros, implode(' | ', $erros));
confere('nenhum código repetido entre geração (reserva) e próximo livre', count(array_unique($todos)) === 48 && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livroConc]) === 48);
confere('todas as reservas desses processos foram usadas e a sequência está no maior código', $num('SELECT COUNT(*) FROM exemplar_codigo_reservas WHERE livro_id = ? AND usado_em IS NULL', [$livroConc]) === 0
    && $num('SELECT ultimo FROM exemplar_codigo_seq') >= $num("SELECT MAX(CAST(SUBSTRING(codigo_individual, 5) AS UNSIGNED)) FROM exemplares"));

// ---------------------------------------------------------------
echo "\n6. Páginas e API\n";
$base = teste_servidor($env);
$bib = new TesteNavegador($base);
$cons = new TesteNavegador($base);
confere('contas entram no painel', $bib->entrar('bib@teste.br', $senha) === 200 && $cons->entrar('cons@teste.br', $senha) === 200);
$tk = $bib->csrf('admin/perfil.php');
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv . '&obra=' . $principe);
confere('ficha da obra: título, "Dados verificados", exemplares com códigos e situações', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'O Pequeno Príncipe (Fictício)')
    && str_contains($html, 'Dados verificados') && str_contains($html, $g1['codigo']) && str_contains($html, 'BDP-000900') && str_contains($html, 'Registrar exemplar') && str_contains($html, 'Histórico da verificação'));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv . '&obra=' . $principe2);
confere('obra não verificada: botão de registro bloqueado e orientação para verificar', $s === 200 && str_contains($html, 'Dados não verificados') && str_contains($html, 'Primeiro, verifique os dados da obra')
    && !str_contains($html, 'id="painel-registrar"') && str_contains($html, 'Linha 3 da planilha'));
[$s, $html] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv . '&q=' . rawurlencode('Pequeno Príncipe'));
confere('pesquisa mostra as duas edições separadas, cada uma com o seu status', $s === 200 && substr_count($html, 'Abrir obra') >= 2 && str_contains($html, 'Dados verificados') && str_contains($html, 'Dados não verificados'));
[$s, $json] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $principe]);
$j = json_decode($json, true) ?: [];
confere('API "Gerar código BDP" reserva no servidor', $s === 200 && ($j['ok'] ?? false) && preg_match('/^BDP-\d{6}$/', $j['codigo'] ?? '') === 1 && $num('SELECT COUNT(*) FROM exemplar_codigo_reservas WHERE codigo = ? AND reservado_por = ?', [$j['codigo'] ?? '', $bibId]) === 1, $json);
[$s2, $json2] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $principe]);
confere('API: clicar de novo devolve o mesmo código ainda não usado', $s2 === 200 && (json_decode($json2, true)['codigo'] ?? '') === ($j['codigo'] ?? 'x'));
[$s] = (new TesteNavegador($base))->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => 'x']);
[$s2] = $cons->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $cons->csrf('admin/perfil.php')]);
[$s3] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => 'falso']);
[$s4] = $bib->ir('admin/api/inventario/gerar-codigo.php');
confere('API exige login (401), permissão (403), CSRF (419) e POST (405)', $s === 401 && $s2 === 403 && $s3 === 419 && $s4 === 405, "$s/$s2/$s3/$s4");
$postReg = ['csrf' => $tk, 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $principe, 'codigo_bdp' => $j['codigo'] ?? '', 'estado_conservacao' => 'regular', 'situacao_inventario' => 'encontrado'];
[$s, , $loc] = $bib->ir('admin/inventario-conferencia.php?id=' . $inv, $postReg);
confere('"Salvar exemplar" pela página grava e volta para a ficha com o código em destaque', $s === 303 && str_contains((string)$loc, 'criados=') && $num('SELECT COUNT(*) FROM exemplares WHERE codigo_individual = ?', [$j['codigo'] ?? '']) === 1);
[$s, $html] = $bib->ir(ltrim((string)parse_url((string)$loc, PHP_URL_PATH), '/') . '?' . parse_url((string)$loc, PHP_URL_QUERY));
confere('mensagem orienta a conferir o carimbo e oferece "Registrar outro exemplar desta obra"', $s === 200 && str_contains($html, 'Exemplar registrado') && str_contains($html, 'Registrar outro exemplar desta obra') && str_contains($html, 'adm-code--grande'));
[$s] = $cons->ir('admin/inventario-conferencia.php?id=' . $inv, ['csrf' => $cons->csrf('admin/perfil.php'), 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $principe, 'codigo_bdp' => 'BDP-000950']);
confere('perfil consulta não registra exemplar (403)', $s === 403 && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual = 'BDP-000950'") === 0);

teste_resultado();

/** Rótulo da situação do exemplar no inventário (mesma regra de inventario_exemplar_status do painel). */
function inventario_status_teste(array $ex): string
{
    return match (true) {
        $ex['conferido_em'] !== null => 'Encontrado',
        $ex['nao_localizado_em'] !== null => 'Não localizado',
        default => 'Pendente',
    };
}
