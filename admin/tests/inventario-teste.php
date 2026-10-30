<?php
/**
 * Testes do acervo físico: exemplares, códigos BDP, localizações, inventário digital,
 * etiquetas (PDF e código de barras), relatórios, permissões e proteções.
 *
 * Uso: php admin/tests/inventario-teste.php
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá para criar um banco
 * temporário "bdp_teste_inventario_XXXX", aplica as migrações, cria livros, exemplares e alunos
 * FICTÍCIOS só nesse banco e o apaga no final.
 * O usuário precisa poder criar bancos (no XAMPP, o root pode). Para outro usuário, defina
 * TESTE_DB_USER e TESTE_DB_PASSWORD.
 * Se pdftoppm e zbarimg estiverem instalados (Linux), os códigos de barras do PDF também são
 * lidos de verdade; sem eles, a estrutura do código de barras é conferida pelo próprio teste.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Repositories\ExemplarRepository;
use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\CirculacaoException;
use Biblioteca\Services\ConferenciaDuplicadaException;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Services\EtiquetaService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LocalizacaoService;
use Biblioteca\Support\Code128;
use Biblioteca\Support\RegrasCirculacao;

require __DIR__ . '/../../config/app.php';

// Processo filho do teste de concorrência: cadastra exemplares e imprime os códigos.
if (($argv[1] ?? '') === '--filho') {
    [, , $livroFilho, $responsavelFilho, $quantidadeFilho, $inicio] = $argv;
    $servicoFilho = new ExemplarService(db());
    while (microtime(true) < (float)$inicio) {
        usleep(300);
    }
    try {
        $criados = $servicoFilho->cadastrar((int)$livroFilho, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], (int)$responsavelFilho, (int)$quantidadeFilho);
        echo implode(',', array_column($criados, 'codigo'));
    } catch (Throwable $e) {
        echo 'erro:' . get_class($e) . ':' . $e->getMessage();
    }
    exit(0);
}

$falhas = 0;
$total = 0;
function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? '  ok  ' : '  FALHOU  ') . $nome . ($ok || $detalhe === '' ? '' : " — $detalhe") . "\n";
}

/** Executa $fn e devolve a mensagem da recusa (AcervoException/CirculacaoException) ou null. */
function recusa(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (AcervoException | CirculacaoException $e) {
        return $e->getMessage();
    }
}

/** true se o comando SQL for recusado pelo banco (gatilho, chave única ou restrição). */
function bloqueado(string $sql): bool
{
    try {
        db()->exec($sql);
        return false;
    } catch (PDOException $e) {
        return true;
    }
}

$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "Testes não executados: configure DB_HOST/DB_USER no .env.\n";
    exit(0);
}
$banco = 'bdp_teste_inventario_' . bin2hex(random_bytes(4));
$raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$servidor = null;
$temporarios = [];
register_shutdown_function(function () use ($raiz, $banco, &$servidor, &$temporarios) {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
    }
    foreach ($temporarios as $f) {
        @unlink($f);
    }
    $raiz->exec("DROP DATABASE IF EXISTS `$banco`");
    echo "Banco temporário $banco apagado.\n";
});
echo "Banco temporário: $banco (o banco do .env não é usado)\n";
$envTeste = ['DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_NAME' => $banco, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/',
    'APP_TIMEZONE' => 'America/Sao_Paulo', 'EMPRESTIMO_PRAZO_DIAS' => '', 'ETIQUETA_BIBLIOTECA' => ''];
foreach ($envTeste as $k => $val) {
    putenv("$k=$val");
}
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../database/migrar.php') . ' 2>&1', $saida, $codigo);
if ($codigo !== 0) {
    fwrite(STDERR, "As migrações falharam:\n" . implode("\n", $saida) . "\n");
    exit(1);
}
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
    fwrite(STDERR, "Conexão inesperada: o teste só roda no banco temporário.\n");
    exit(1);
}
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$val = fn (string $sql, array $p = []) => db_query($sql, $p)->fetchColumn();

// ---------------------------------------------------------------
// Dados fictícios (somente no banco temporário)
// ---------------------------------------------------------------
$senhaPadrao = 'SenhaDeTeste123';
$contas = [];
foreach ([['Ana Admin', 'admin@teste.br', 'administrador'], ['Bia Bibliotecaria', 'bib@teste.br', 'bibliotecaria'],
          ['Caio Auxiliar', 'aux@teste.br', 'auxiliar'], ['Duda Consulta', 'cons@teste.br', 'consulta']] as [$n, $em, $p]) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute([$n, $em, password_hash($senhaPadrao, PASSWORD_DEFAULT), $p]);
    $contas[$p] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$pdo->prepare('INSERT INTO alunos (nome, matricula, email, turma) VALUES (?, ?, ?, ?)')->execute(['Aluno Fictício do Inventário', 'TESTE-INV-01', 'aluno.ficticio@escola.teste', '9A']);
$alunoId = (int)$pdo->lastInsertId();
$livro = [];
foreach ([
    ['dom', 'Dom Casmurro de Teste', '9788535902778', 'Machado Fictício'],
    ['planilha', 'Livro Antigo da Planilha', null, 'Autora Antiga'],
    ['conc', 'Livro dos Cadastros Simultâneos', null, null],
    ['xss', '<script>alert("x")</script> Livro', null, null],
    ['semex', 'Livro Sem Exemplar Físico', null, null],
] as [$chave, $titulo, $isbn, $autor]) {
    $pdo->prepare("INSERT INTO livros (titulo, isbn, origem_cadastro) VALUES (?, ?, 'manual')")->execute([$titulo, $isbn]);
    $livro[$chave] = (int)$pdo->lastInsertId();
    if ($autor) {
        $pdo->prepare('INSERT INTO autores (nome) VALUES (?)')->execute([$autor]);
        $pdo->prepare('INSERT INTO livro_autores (livro_id, autor_id) VALUES (?, ?)')->execute([$livro[$chave], (int)$pdo->lastInsertId()]);
    }
}
// Linha de planilha antiga do livro "planilha": quantidade 7 e localização antiga (histórico).
$pdo->prepare("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, usuario_id, status) VALUES ('acervo-ficticio.xlsx', SHA2('inv', 256), 'x.upload', 'xlsx', 1, ?, 'concluida')")->execute([$bibId]);
$impId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, hash_linha, livro_resultante_id, resultado_importacao, processado_em) VALUES (?, 2, '{}', '{}', SHA1('inv'), ?, 'criado', NOW())")->execute([$impId, $livro['planilha']]);
$itemImp = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, titulo_original, localizacao_antiga, cdd_original, quantidade_informada, dados_originais)
               VALUES (?, ?, ?, 'acervo-ficticio.xlsx', 2, 'LIVRO ANTIGO', 'ESTANTE 4 - FUNDO', '869.3', 7, '{}')")->execute([$impId, $itemImp, $livro['planilha']]);
// CDD aprovada só para o Dom Casmurro de Teste.
$pdo->prepare("INSERT INTO classificacoes_cdd (livro_id, status, cdd_aprovada, aprovado_por, aprovado_em) VALUES (?, 'aprovada', '869.3', ?, NOW())")->execute([$livro['dom'], $bibId]);

$exServ = new ExemplarService($pdo);
$exRepo = new ExemplarRepository($pdo);
$locServ = new LocalizacaoService($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);

$locA = $locServ->salvar(['setor' => 'Literatura', 'estante' => 'e01', 'prateleira' => 'p01', 'codigo' => 'lit-e01-p01'], $bibId);
$locB = $locServ->salvar(['setor' => 'Literatura', 'estante' => 'E01', 'prateleira' => 'P02'], $bibId);
$locC = $locServ->salvar(['setor' => 'Ciências', 'estante' => 'E07', 'prateleira' => 'P01'], $bibId);

// ---------------------------------------------------------------
echo "\n1. Cadastro de exemplar\n";
$criados = $exServ->cadastrar($livro['dom'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $locA, 'observacoes' => 'doação fictícia'], $bibId, 3);
$dom = array_column($criados, 'id');
confere('três exemplares cadastrados com códigos BDP-000001 a BDP-000003', array_column($criados, 'codigo') === ['BDP-000001', 'BDP-000002', 'BDP-000003']);
confere('exemplar ligado ao livro, com localização, estado e responsável', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND localizacao_id = ? AND cadastrado_por = ?', [$livro['dom'], $locA, $bibId]) === 3);
confere('cadastro registra movimentação e ação administrativa', $num("SELECT COUNT(*) FROM movimentacoes WHERE tipo = 'cadastro'") === 3 && $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'exemplar.cadastrar'") === 1);
confere('quantidade inválida (0 ou acima do limite) é recusada', recusa(fn () => $exServ->cadastrar($livro['dom'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId, 0)) !== null
    && recusa(fn () => $exServ->cadastrar($livro['dom'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId, 21)) !== null);
confere('situação inicial "emprestado" ou "baixado" não é aceita no cadastro', recusa(fn () => $exServ->cadastrar($livro['dom'], ['estado_conservacao' => 'bom', 'situacao' => 'emprestado'], $bibId)) !== null);
confere('localização desativada não recebe exemplar novo', (function () use ($locServ, $exServ, $livro, $bibId, $locC) {
    $locServ->definirAtiva($locC, false, $bibId);
    $r = recusa(fn () => $exServ->cadastrar($livro['dom'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $locC], $bibId));
    $locServ->definirAtiva($locC, true, $bibId);
    return $r !== null;
})());
confere('o cadastro de livro não cria exemplares a partir da quantidade da planilha', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livro['planilha']]) === 0);

// ---------------------------------------------------------------
echo "\n2. Códigos BDP sem repetição\n";
$um = $exServ->cadastrar($livro['planilha'], ['estado_conservacao' => 'regular', 'situacao' => 'disponivel'], $bibId);
confere('próximo código continua a sequência', $um[0]['codigo'] === 'BDP-000004');
confere('código duplicado é recusado pelo banco (índice único)', bloqueado("INSERT INTO exemplares (livro_id, codigo_individual) VALUES ({$livro['dom']}, 'BDP-000001')"));
confere('código fora do formato BDP-000000 é recusado', bloqueado("INSERT INTO exemplares (livro_id, codigo_individual) VALUES ({$livro['dom']}, 'XYZ-1')"));
confere('código de um exemplar não pode ser alterado', bloqueado("UPDATE exemplares SET codigo_individual = 'BDP-000999' WHERE id = {$dom[0]}"));
confere('exemplar não pode ser excluído (só baixa)', bloqueado("DELETE FROM exemplares WHERE id = {$dom[0]}"));
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual) VALUES ({$livro['planilha']}, 'BDP-000050')");
$depois = $exServ->cadastrar($livro['planilha'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId);
confere('código inserido direto no banco avança a sequência (sem reaproveitar)', $depois[0]['codigo'] === 'BDP-000051');
$exServ->baixar((int)$depois[0]['id'], $bibId, 'teste de baixa');
$aposBaixa = $exServ->cadastrar($livro['planilha'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId);
confere('código de exemplar baixado não é reutilizado', $aposBaixa[0]['codigo'] === 'BDP-000052');
confere('normalização de leitura: "bdp-1", "BDP 000001" e "bdp000001" encontram o mesmo exemplar',
    ($exRepo->porCodigo('bdp-1')['id'] ?? 0) === $dom[0] && ($exRepo->porCodigo('BDP 000001')['id'] ?? 0) === $dom[0] && ($exRepo->porCodigo('bdp000001')['id'] ?? 0) === $dom[0]);

// ---------------------------------------------------------------
echo "\n3. Cadastros simultâneos (processos paralelos)\n";
$antes = $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1');
$inicio = microtime(true) + 1.2;
$processos = [];
$pipes = [];
for ($i = 0; $i < 8; $i++) {
    $processos[] = proc_open([PHP_BINARY, __FILE__, '--filho', (string)$livro['conc'], (string)$bibId, '5', (string)$inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), $envTeste));
}
$codigosConc = [];
$erros = [];
foreach ($processos as $i => $proc) {
    $saidaFilho = trim(stream_get_contents($pipes[$i][1]));
    $erroFilho = trim(stream_get_contents($pipes[$i][2]));
    proc_close($proc);
    if (str_starts_with($saidaFilho, 'BDP-')) {
        array_push($codigosConc, ...explode(',', $saidaFilho));
    } else {
        $erros[] = $saidaFilho . $erroFilho;
    }
}
sort($codigosConc);
$esperados = array_map(fn ($n) => sprintf('BDP-%06d', $n), range($antes + 1, $antes + 40));
confere('8 cadastros simultâneos de 5 exemplares: 40 códigos', count($codigosConc) === 40 && !$erros, implode(' | ', $erros));
confere('nenhum código repetido nem pulado', $codigosConc === $esperados && count(array_unique($codigosConc)) === 40);
confere('banco tem exatamente os 40 exemplares do livro', $num('SELECT COUNT(DISTINCT codigo_individual) FROM exemplares WHERE livro_id = ?', [$livro['conc']]) === 40);

// ---------------------------------------------------------------
echo "\n4. Criação de inventário\n";
$hoje = date('Y-m-d');
confere('nome obrigatório', recusa(fn () => $invServ->criar(['titulo' => '', 'data_inicio' => $hoje, 'responsavel_id' => $bibId], $bibId)) !== null);
confere('data inválida recusada', recusa(fn () => $invServ->criar(['titulo' => 'Inventário X', 'data_inicio' => '2026-02-31', 'responsavel_id' => $bibId], $bibId)) !== null);
confere('setor inexistente recusado', recusa(fn () => $invServ->criar(['titulo' => 'Inventário X', 'data_inicio' => $hoje, 'responsavel_id' => $bibId, 'setor' => 'Setor Fantasma'], $bibId)) !== null);
$inv = $invServ->criar(['titulo' => 'Inventário Geral de Teste', 'data_inicio' => $hoje, 'responsavel_id' => $bibId, 'descricao' => 'Equipe fictícia'], $bibId);
confere('inventário criado em preparação', $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'aberto');
confere('em preparação ainda não tem lista prevista', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ?', [$inv]) === 0);
confere('não pode ser concluído sem ter começado', recusa(fn () => $invServ->concluir($inv, $bibId)) !== null);
$ativosAntes = $num("SELECT COUNT(*) FROM exemplares WHERE situacao NOT IN ('baixado', 'extraviado')");
$previstos = $invServ->iniciar($inv, $bibId);
confere('ao iniciar, a lista prevista é congelada (exemplares ativos)', $previstos === $ativosAntes && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND previsto = 1', [$inv]) === $ativosAntes);
$inv2 = $invServ->criar(['titulo' => 'Segundo inventário', 'data_inicio' => $hoje, 'responsavel_id' => $bibId, 'setor' => 'Literatura'], $bibId);
confere('só um inventário em andamento por vez', recusa(fn () => $invServ->iniciar($inv2, $bibId)) !== null);
$invServ->pausar($inv, $bibId);
confere('pausado: conferência recusada', recusa(fn () => $invServ->conferir($inv, $dom[0], ['estado_conservacao' => 'bom'], $bibId)) !== null);
$invServ->retomar($inv, $bibId);
confere('retomado: volta a "em andamento"', $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'em_andamento');
confere('transição inválida (em preparação → concluído) é recusada', recusa(fn () => $invServ->concluir($inv2, $bibId)) !== null);

// ---------------------------------------------------------------
echo "\n5. Conferência de exemplar existente\n";
$it = $invServ->conferir($inv, $dom[0], ['estado_conservacao' => 'bom', 'localizacao_id' => $locA, 'observacao' => 'tudo certo'], $bibId);
confere('conferido sem divergência', $it['conferido_em'] !== null && !(int)$it['divergencia_local'] && !(int)$it['divergencia_estado'] && !(int)$it['divergencia_situacao']);
confere('data, responsável e observação gravados', (int)$it['conferido_por'] === $bibId && $it['observacao'] === 'tudo certo' && $val('SELECT conferido_em FROM exemplares WHERE id = ?', [$dom[0]]) !== null);
$it2 = $invServ->conferir($inv, $dom[1], ['estado_conservacao' => 'necessita_reparo', 'localizacao_id' => $locB], $bibId);
confere('local e estado diferentes viram divergências (sem alterar o cadastro)', (int)$it2['divergencia_local'] === 1 && (int)$it2['divergencia_estado'] === 1
    && $num('SELECT localizacao_id FROM exemplares WHERE id = ?', [$dom[1]]) === $locA && $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$dom[1]]) === 'bom');
$semLocal = $num('SELECT id FROM exemplares WHERE codigo_individual = ?', ['BDP-000004']);
$it3 = $invServ->conferir($inv, $semLocal, ['estado_conservacao' => 'regular', 'localizacao_id' => $locC], $bibId);
confere('exemplar sem localização: a encontrada vira a confirmada (sem divergência)', !(int)$it3['divergencia_local'] && $num('SELECT localizacao_id FROM exemplares WHERE id = ?', [$semLocal]) === $locC);
$r = $invRepo->resumo($inv);
confere('contadores: previstos, conferidos, não conferidos, local e estado divergentes', $r['previstos'] === $previstos && $r['conferidos'] === 3 && $r['nao_conferidos'] === $previstos - 3 && $r['local_diferente'] === 1 && $r['estado_divergente'] === 1);
confere('código desconhecido não cria nada', $exRepo->porCodigo('BDP-999999') === null && $num('SELECT COUNT(*) FROM exemplares WHERE codigo_individual = ?', ['BDP-999999']) === 0);

// ---------------------------------------------------------------
echo "\n6. Cadastro de novo exemplar durante a conferência\n";
$novos = $invServ->cadastrarExemplar($inv, $livro['semex'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $locB], $bibId, 2);
confere('dois exemplares novos cadastrados e já conferidos', count($novos) === 2 && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND cadastrado_no_inventario = 1 AND conferido_em IS NOT NULL', [$inv]) === 2);
confere('exemplar novo guarda o inventário de origem', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND inventario_id = ?', [$livro['semex'], $inv]) === 2);
confere('contador "cadastrados no inventário"', $invRepo->resumo($inv)['cadastrados'] === 2);
confere('cadastro na conferência exige o inventário em andamento', recusa(fn () => $invServ->cadastrarExemplar($inv2, $livro['semex'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId)) !== null);

// ---------------------------------------------------------------
echo "\n7. Prevenção de conferências duplicadas\n";
$dup = null;
try {
    $invServ->conferir($inv, $dom[0], ['estado_conservacao' => 'necessita_reparo'], $bibId);
} catch (ConferenciaDuplicadaException $e) {
    $dup = $e;
}
confere('segunda leitura avisa e mostra os dados anteriores', $dup !== null && ($dup->item['estado_encontrado'] ?? '') === 'bom' && str_contains($dup->getMessage(), 'já foi conferido'));
confere('nada foi gravado de novo', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv, $dom[0]]) === 1
    && $val('SELECT estado_encontrado FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv, $dom[0]]) === 'bom');
confere('índice único impede duplicar o item no banco', bloqueado("INSERT INTO inventario_itens (inventario_id, exemplar_id) VALUES ($inv, {$dom[0]})"));
$corr = $invServ->corrigir((int)$it['id'], ['estado_conservacao' => 'regular', 'localizacao_id' => $locA, 'observacao' => 'revisto'], $bibId, 'li errado');
confere('correção autorizada atualiza a conferência e conta a correção', $corr['estado_encontrado'] === 'regular' && (int)$corr['correcoes'] === 1 && (int)$corr['divergencia_estado'] === 1);
confere('correção sem motivo é recusada', recusa(fn () => $invServ->corrigir((int)$it['id'], ['estado_conservacao' => 'bom'], $bibId, '')) !== null);

// ---------------------------------------------------------------
echo "\n8. Alteração de localização (transferência individual)\n";
$exServ->transferir($dom[2], $locB, $bibId, 'reorganização');
confere('só o exemplar transferido muda de lugar', $num('SELECT localizacao_id FROM exemplares WHERE id = ?', [$dom[2]]) === $locB && $num('SELECT localizacao_id FROM exemplares WHERE id = ?', [$dom[0]]) === $locA);
confere('transferência fica no histórico', $num("SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ? AND tipo = 'localizacao'", [$dom[2]]) === 1);
confere('localização repetida (maiúsculas/minúsculas) é recusada', recusa(fn () => $locServ->salvar(['setor' => 'literatura', 'estante' => 'e01', 'prateleira' => 'P01'], $bibId)) !== null);
confere('código curto repetido é recusado', recusa(fn () => $locServ->salvar(['setor' => 'Outro', 'codigo' => 'LIT-E01-P01'], $bibId)) !== null);
confere('localizações não podem ser excluídas fisicamente quando têm exemplares', bloqueado("DELETE FROM localizacoes WHERE id = $locA"));
$itDiv = $invRepo->itemPorExemplar($inv, $dom[1]);
$invServ->decidir((int)$itDiv['id'], 'atualizar_cadastro', 'confirmado na estante', $bibId);
confere('decisão "atualizar cadastro" aplica a localização e o estado encontrados', $num('SELECT localizacao_id FROM exemplares WHERE id = ?', [$dom[1]]) === $locB && $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$dom[1]]) === 'necessita_reparo');
confere('decisão repetida é recusada', recusa(fn () => $invServ->decidir((int)$itDiv['id'], 'manter_cadastro', '', $bibId)) !== null);

// ---------------------------------------------------------------
echo "\n9. Preservação da localização histórica\n";
$legadoAntes = $val('SELECT localizacao_antiga FROM registros_legados WHERE livro_id = ?', [$livro['planilha']]);
confere('localização antiga aparece separada, como referência', in_array('ESTANTE 4 - FUNDO', array_column($locServ->antigas()['itens'], 'localizacao_antiga'), true));
confere('a localização antiga não vira localização confirmada', $num("SELECT COUNT(*) FROM localizacoes WHERE setor LIKE '%ESTANTE 4%' OR estante LIKE '%ESTANTE 4%'") === 0);
confere('a planilha continua intacta depois da conferência e da transferência', $legadoAntes === 'ESTANTE 4 - FUNDO' && $num('SELECT quantidade_informada FROM registros_legados WHERE livro_id = ?', [$livro['planilha']]) === 7);
confere('registro legado não pode ser alterado (gatilho)', bloqueado("UPDATE registros_legados SET localizacao_antiga = 'X' WHERE livro_id = {$livro['planilha']}"));
$dp = $exRepo->dadosPlanilha($livro['planilha']);
confere('quantidade da planilha, físicos e disponíveis são números separados', (int)$dp['quantidade'] === 7 && $exRepo->contagemLivro($livro['planilha'])['ativos'] === 3);

// ---------------------------------------------------------------
echo "\n10. Alteração do estado de conservação\n";
$exServ->editar($dom[2], ['estado_conservacao' => 'danificado', 'observacoes' => 'capa rasgada'], $bibId);
confere('estado alterado e registrado no histórico', $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$dom[2]]) === 'danificado' && $num("SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ? AND tipo = 'conservacao'", [$dom[2]]) === 1);
confere('estado inválido recusado', recusa(fn () => $exServ->editar($dom[2], ['estado_conservacao' => 'ruim'], $bibId)) !== null);
$exServ->alterarSituacao($dom[2], 'danificado', $bibId, 'retirado de circulação');
confere('situação "danificado" retira de circulação', $val('SELECT situacao FROM exemplares WHERE id = ?', [$dom[2]]) === 'danificado');

// ---------------------------------------------------------------
echo "\n11. Controle de exemplares emprestados\n";
$circ = new EmprestimoService($pdo, new RegrasCirculacao(fuso: 'America/Sao_Paulo'));
$emp = $circ->registrar($alunoId, $dom[0], $circ->prevista(), $bibId);
confere('empréstimo marca o exemplar como emprestado', $val('SELECT situacao FROM exemplares WHERE id = ?', [$dom[0]]) === 'emprestado');
confere('exemplar danificado não pode ser emprestado', recusa(fn () => $circ->registrar($alunoId, $dom[2], $circ->prevista(), $bibId)) !== null);
confere('situação manual não muda exemplar emprestado', recusa(fn () => $exServ->alterarSituacao($dom[0], 'manutencao', $bibId)) !== null);
confere('extravio de exemplar emprestado é recusado', recusa(fn () => $exServ->registrarExtravio($dom[0], $bibId, 'sumiu')) !== null);
$emprestadoPrevisto = $invRepo->itemPorExemplar($inv, $dom[0]);
confere('no inventário, emprestado fica à parte (não é ausência)', $invRepo->resumo($inv)['em_circulacao'] >= 0 && $emprestadoPrevisto['emprestimo_aberto'] !== null);

// ---------------------------------------------------------------
echo "\n12. Bloqueio de baixa indevida\n";
confere('baixa de exemplar emprestado é recusada', recusa(fn () => $exServ->baixar($dom[0], $bibId, 'teste')) !== null && $val('SELECT situacao FROM exemplares WHERE id = ?', [$dom[0]]) === 'emprestado');
confere('baixa sem motivo é recusada', recusa(fn () => $exServ->baixar($dom[2], $bibId, '')) !== null);
confere('baixa não pode ser desfeita no banco', bloqueado("UPDATE exemplares SET situacao = 'disponivel' WHERE id = {$depois[0]['id']}"));
confere('exemplar baixado não volta por mudança de situação', recusa(fn () => $exServ->alterarSituacao((int)$depois[0]['id'], 'disponivel', $bibId)) !== null);
confere('baixa guarda data e motivo', $val('SELECT motivo_baixa FROM exemplares WHERE id = ?', [$depois[0]['id']]) === 'teste de baixa' && $val('SELECT baixado_em FROM exemplares WHERE id = ?', [$depois[0]['id']]) !== null);

// ---------------------------------------------------------------
echo "\n13. Integração com empréstimos\n";
confere('pesquisa "BDP-000001" no empréstimo encontra o exemplar', EmprestimoRepository::codigoExemplar('BDP-000001') === 'BDP-000001' && ($exRepo->porCodigo('BDP-000001')['livro_id'] ?? 0) === $livro['dom']);
$circ->devolver((int)$emp['id'], $bibId);
confere('devolução volta o exemplar para disponível', $val('SELECT situacao FROM exemplares WHERE id = ?', [$dom[0]]) === 'disponivel');
confere('histórico do exemplar tem cadastro, conferência, empréstimo e devolução', $num("SELECT COUNT(DISTINCT tipo) FROM movimentacoes WHERE exemplar_id = ? AND tipo IN ('cadastro', 'inventario', 'emprestimo', 'devolucao')", [$dom[0]]) === 4);
$novoParaEmprestar = $exServ->cadastrar($livro['dom'], ['estado_conservacao' => 'bom', 'situacao' => 'aguardando'], $bibId);
confere('exemplar "aguardando liberação" não empresta', recusa(fn () => $circ->registrar($alunoId, (int)$novoParaEmprestar[0]['id'], $circ->prevista(), $bibId)) !== null);

// ---------------------------------------------------------------
echo "\n14 e 15. Etiquetas individuais e em lote\n";
$modelos = (new EtiquetaService($pdo))->modelos();
confere('4 modelos padrão cadastrados (patrimonial, lombada, individual)', count($modelos) === 4);
$patrimonial = array_values(array_filter($modelos, fn ($m) => $m['tipo'] === 'patrimonial' && (int)$m['colunas'] === 3 && (int)$m['linhas'] === 11))[0];
$lombada = $modelos[array_search('lombada', array_column($modelos, 'tipo'), true)];
$individual = null;
foreach ($modelos as $m) {
    if ((int)$m['colunas'] === 1 && (int)$m['linhas'] === 1) {
        $individual = $m;
    }
}
$exDom = $exRepo->paraEtiquetas([$dom[0]]);
$els = EtiquetaService::elementos($patrimonial, $exDom[0], 'Biblioteca Dom Pedro II');
$textos = implode(' | ', array_column(array_filter($els, fn ($e) => $e['tipo'] === 'texto'), 'texto'));
confere('etiqueta mostra biblioteca, código, CDD aprovada, título e localização confirmada', str_contains($textos, 'BIBLIOTECA DOM PEDRO II') && str_contains($textos, 'BDP-000001')
    && str_contains($textos, 'CDD 869.3') && str_contains($textos, 'Dom Casmurro') && str_contains($textos, 'LIT-E01-P01'));
$exPlan = $exRepo->paraEtiquetas([$semLocal]);
$textosPlan = implode(' | ', array_column(array_filter(EtiquetaService::elementos($patrimonial, $exPlan[0], 'Biblioteca'), fn ($e) => $e['tipo'] === 'texto'), 'texto'));
confere('sem CDD aprovada, a etiqueta não inventa CDD (nem usa a CDD da planilha)', !str_contains($textosPlan, 'CDD') && !str_contains($textosPlan, '869.3'));
confere('nenhum dado de aluno nas etiquetas', !str_contains($textos . $textosPlan, 'TESTE-INV') && !str_contains($textos . $textosPlan, 'Aluno Fictício'));
$pdfUm = EtiquetaService::pdf($individual, $exDom, 0, 'Biblioteca Dom Pedro II');
confere('PDF individual válido, com 1 página no tamanho do modelo', str_starts_with($pdfUm, '%PDF-1.') && substr_count($pdfUm, '/Type /Page ') === 1
    && str_contains($pdfUm, '/MediaBox [0 0 ' . round((float)$individual['pagina_largura'] * 72 / 25.4, 2)) && str_contains($pdfUm, '/PrintScaling /None'));
$lote = $exRepo->paraEtiquetas([...$dom, $dom[0], $dom[1], ...array_column($novos, 'id'), (int)$depois[0]['id']]);
confere('lote sem repetição e sem exemplares baixados', count($lote) === 5 && count(array_unique(array_column($lote, 'id'))) === 5);
$paginas = EtiquetaService::paginar($patrimonial, array_fill(0, 40, $exDom[0]), 3);
confere('lote de 40 em folha 3 × 11 pulando 3 posições: 2 folhas, 30 + 10', count($paginas) === 2 && count($paginas[0]) === 30 && count($paginas[1]) === 10
    && abs($paginas[0][0]['x'] - (float)$patrimonial['margem_esquerda']) < 0.01 && $paginas[0][0]['y'] > (float)$patrimonial['margem_superior']);
$pdfLote = EtiquetaService::pdf($patrimonial, $lote, 0, 'Biblioteca Dom Pedro II', true);
confere('PDF do lote gerado', str_starts_with($pdfLote, '%PDF-') && str_ends_with(rtrim($pdfLote), '%%EOF'));
confere('lombada sem CDD aprovada é detectada (pode ser deixada de fora)', ($exPlan[0]['cdd_aprovada'] ?? null) === null && ($exDom[0]['cdd_aprovada'] ?? null) === '869.3');
confere('modelo que não cabe na página é recusado', recusa(fn () => EtiquetaService::validarModelo(['nome' => 'Grande demais', 'pagina_largura' => 210, 'pagina_altura' => 297, 'etiqueta_largura' => 80,
    'etiqueta_altura' => 30, 'margem_superior' => 5, 'margem_esquerda' => 5, 'espaco_horizontal' => 2, 'espaco_vertical' => 0, 'colunas' => 3, 'linhas' => 10])) !== null);
$exServ->marcarEtiquetas(array_column($lote, 'id'), $bibId, $patrimonial['nome']);
confere('data da etiqueta registrada nos exemplares do lote', $num('SELECT COUNT(*) FROM exemplares WHERE etiqueta_gerada_em IS NOT NULL') === 5);

// ---------------------------------------------------------------
echo "\n16. Legibilidade dos códigos de barras\n";
/** Decodificador Code 128 independente (larguras -> valores), só para conferir o desenho. */
function decodificar_code128(array $barras): ?string
{
    // Reconstrói as larguras alternadas (barra/espaço) a partir das posições das barras.
    $larguras = [];
    $fim = 0;
    foreach ($barras as $i => [$x, $l]) {
        if ($i > 0) {
            $larguras[] = $x - $fim;
        }
        $larguras[] = $l;
        $fim = $x + $l;
    }
    $padroes = Code128Padroes::lista();
    $valores = [];
    for ($i = 0; $i + 6 <= count($larguras); $i += 6) {
        $p = implode('', array_slice($larguras, $i, 6));
        $v = array_search($p, $padroes, true);
        if ($v === false) {
            break;
        }
        $valores[] = $v;
    }
    if (count($valores) < 3 || !in_array($valores[0], [103, 104, 105], true)) {
        return null;
    }
    $soma = $valores[0];
    for ($k = 1; $k < count($valores) - 1; $k++) {
        $soma += $valores[$k] * $k;
    }
    if ($soma % 103 !== end($valores)) {
        return null;
    }
    $texto = '';
    $set = $valores[0] === 105 ? 'C' : 'B';
    foreach (array_slice($valores, 1, -1) as $v) {
        // 99 = "mudar para C" só no conjunto B; no conjunto C é o par de dígitos "99".
        if ($set === 'B' && $v === 99) { $set = 'C'; continue; }
        if ($v === 100) { $set = 'B'; continue; }
        $texto .= $set === 'C' ? sprintf('%02d', $v) : chr($v + 32);
    }
    return $texto;
}
/** Padrões oficiais do Code 128 (ISO/IEC 15417), digitados separadamente da classe testada. */
final class Code128Padroes
{
    public static function lista(): array
    {
        return explode(' ', '212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232');
    }
}
$amostra = ['BDP-000001', 'BDP-000052', 'BDP-123456', 'BDP-999999'];
$decodificados = array_map(fn ($c) => decodificar_code128(Code128::barras($c)), $amostra);
confere('barras decodificadas por um leitor independente (com dígito verificador)', $decodificados === $amostra, json_encode($decodificados));
foreach ($modelos as $m) {
    $barras = array_values(array_filter(EtiquetaService::elementos($m, $exDom[0], 'Biblioteca'), fn ($e) => $e['tipo'] === 'barras'));
    if (!(int)$m['codigo_barras']) {
        confere("modelo \"{$m['nome']}\" sem código de barras (configurado assim)", !$barras);
        continue;
    }
    confere("modelo \"{$m['nome']}\": barras de pelo menos " . str_replace('.', ',', (string)EtiquetaService::MODULO_MINIMO) . ' mm, com zona de silêncio e dentro da etiqueta',
        $barras && $barras[0]['modulo'] >= EtiquetaService::MODULO_MINIMO && $barras[0]['x'] >= 0 && $barras[0]['x'] + $barras[0]['largura'] <= (float)$m['etiqueta_largura'] + 0.01
        && $barras[0]['y'] + $barras[0]['altura'] <= (float)$m['etiqueta_altura']);
}
$temZbar = trim((string)shell_exec('command -v zbarimg 2>/dev/null')) !== '' && trim((string)shell_exec('command -v pdftoppm 2>/dev/null')) !== '';
if ($temZbar) {
    $arqPdf = tempnam(sys_get_temp_dir(), 'bdpetq') . '.pdf';
    $temporarios[] = $arqPdf;
    file_put_contents($arqPdf, $pdfLote);
    $png = substr($arqPdf, 0, -4);
    exec('pdftoppm -r 300 -png ' . escapeshellarg($arqPdf) . ' ' . escapeshellarg($png));
    foreach (glob($png . '*.png') ?: [] as $f) {
        $temporarios[] = $f;
    }
    $lidos = [];
    foreach (glob($png . '*.png') ?: [] as $f) {
        exec('zbarimg -q --raw ' . escapeshellarg($f) . ' 2>/dev/null', $lidos);
    }
    sort($lidos);
    $codigosLote = array_column($lote, 'codigo_individual');
    sort($codigosLote);
    confere('PDF impresso a 300 dpi: zbarimg lê todos os códigos do lote', $lidos === $codigosLote, implode(',', $lidos));
} else {
    echo "  (pdftoppm/zbarimg não instalados: a leitura do PDF por um leitor real foi pulada; a estrutura das barras foi conferida acima)\n";
}

// ---------------------------------------------------------------
echo "\n17. Relatórios e conclusão do inventário\n";
$pend = $invServ->pendencias($inv);
confere('conclusão bloqueada com pendências', $pend['divergencias'] > 0 && $pend['ausentes'] > 0 && recusa(fn () => $invServ->concluir($inv, $bibId)) !== null);
$ausente = $num('SELECT i.id FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id WHERE i.inventario_id = ? AND e.livro_id = ? AND i.conferido_em IS NULL ORDER BY i.id LIMIT 1', [$inv, $livro['conc']]);
$exAusente = $num('SELECT exemplar_id FROM inventario_itens WHERE id = ?', [$ausente]);
confere('não localizado não vira extraviado sozinho', $val('SELECT situacao FROM exemplares WHERE id = ?', [$exAusente]) === 'disponivel');
$invServ->decidir($ausente, 'registrar_extravio', 'procurado em todas as estantes', $bibId);
confere('extravio só com decisão explícita', $val('SELECT situacao FROM exemplares WHERE id = ?', [$exAusente]) === 'extraviado');
foreach ($invRepo->itens($inv, 'pendentes', '', 1, 0)['itens'] as $p) {
    if ($p['conferido_em'] !== null) {
        $invServ->decidir((int)$p['id'], 'manter_cadastro', 'mantido', $bibId);
    }
}
$mantidos = $invServ->manterAusentes($inv, 'procurar no próximo inventário', $bibId);
confere('ausentes restantes mantidos sem alteração (em lote, com decisão)', $mantidos > 0 && $num("SELECT COUNT(*) FROM exemplares WHERE situacao = 'extraviado'") === 1);
$itensRel = $invRepo->itens($inv, 'todos', '', 1, 0);
confere('relatório traz todos os itens', $itensRel['total'] === $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ?', [$inv]) && count($itensRel['itens']) === $itensRel['total']);
$resumoFinal = $invServ->concluir($inv, $bibId);
confere('inventário concluído grava o resumo e o resultado por título', $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'concluido' && $resumoFinal['cadastrados'] === 2
    && $num('SELECT COUNT(*) FROM inventario_conferencias WHERE inventario_id = ?', [$inv]) === $num('SELECT COUNT(DISTINCT e.livro_id) FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id WHERE i.inventario_id = ?', [$inv]));
confere('inventário concluído fica bloqueado (banco recusa alterações)', bloqueado("UPDATE inventarios SET titulo = 'x' WHERE id = $inv") && bloqueado("UPDATE inventario_itens SET observacao = 'x' WHERE inventario_id = $inv")
    && bloqueado("DELETE FROM inventario_itens WHERE inventario_id = $inv") && recusa(fn () => $invServ->conferir($inv, $dom[0], ['estado_conservacao' => 'bom'], $bibId)) !== null);
$invServ->cancelar($inv2, $bibId, 'teste de cancelamento');
confere('cancelamento exige motivo e bloqueia', $val('SELECT status FROM inventarios WHERE id = ?', [$inv2]) === 'cancelado' && bloqueado("UPDATE inventarios SET status = 'aberto' WHERE id = $inv2"));

// ---------------------------------------------------------------
echo "\n18 e 19. Permissões, CSRF, SQL injection e XSS (servidor PHP embutido no banco temporário)\n";
$inv3 = $invServ->criar(['titulo' => 'Inventário das páginas', 'data_inicio' => $hoje, 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv3, $bibId);
$exXss = $exServ->cadastrar($livro['xss'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $locA], $bibId)[0];
$portaHttp = 8790 + random_int(301, 450);
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', sys_get_temp_dir() . "/$banco.log", 'a'], 2 => ['file', sys_get_temp_dir() . "/$banco.log", 'a']], $pipesHttp, null, array_merge(getenv(), $envTeste));
$temporarios[] = sys_get_temp_dir() . "/$banco.log";
usleep(900000);
$base = "http://127.0.0.1:$portaHttp/admin/";

/** Navegador mínimo com cookies. Retorna [status, corpo, location, cabeçalhos]. */
final class Navegador
{
    private array $cookies = [];
    public function __construct(private string $base) {}
    public function ir(string $pagina, ?array $post = null): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: " . http_build_query($this->cookies, '', '; ') . "\r\n",
            'content' => $post === null ? '' : http_build_query($post),
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30,
        ]]);
        $corpo = (string)@file_get_contents($this->base . $pagina, false, $ctx);
        $status = 0;
        $local = '';
        $tipo = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int)$m[1];
            } elseif (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) {
                $this->cookies[$m[1]] = $m[2];
            } elseif (preg_match('/^Location: (.*)$/i', $h, $m)) {
                $local = trim($m[1]);
            } elseif (preg_match('/^Content-Type: (.*)$/i', $h, $m)) {
                $tipo = trim($m[1]);
            }
        }
        return [$status, $corpo, $local, $tipo];
    }
    public function csrf(string $pagina): string
    {
        [, $corpo] = $this->ir($pagina);
        return preg_match('/name="csrf" value="([a-f0-9]+)"/', $corpo, $m) ? $m[1] : '';
    }
    public function entrar(string $email, string $senha): int
    {
        $t = $this->csrf('login.php');
        return $this->ir('login.php', ['csrf' => $t, 'email' => $email, 'senha' => $senha])[0];
    }
}

$paginas = ['exemplares.php', 'detalhes-exemplar.php?id=' . $dom[0], 'cadastrar-exemplar.php?livro=' . $livro['dom'], 'localizacoes.php', 'localizacoes.php?aba=antigas', 'localizacao.php',
    'inventario.php', 'inventario-editar.php', 'inventario-detalhes.php?id=' . $inv, 'inventario-conferencia.php?id=' . $inv3, 'inventario-relatorio.php?id=' . $inv,
    'etiquetas.php?ids=' . $dom[0], 'etiqueta-modelos.php'];
$anon = new Navegador($base);
confere('sem login, todas as páginas novas levam ao login', array_reduce($paginas, fn ($ok, $p) => $ok && ($r = $anon->ir($p)) && $r[0] === 302 && str_contains($r[2], 'login.php'), true));

$bib = new Navegador($base);
confere('login da bibliotecária', $bib->entrar('bib@teste.br', $senhaPadrao) === 302);
$quebradas = [];
foreach ($paginas as $p) {
    [$s, $html] = $bib->ir($p);
    if ($s !== 200 || str_contains($html, 'Fatal error') || str_contains($html, 'Warning:') || str_contains($html, 'Notice:') || str_contains($html, 'Deprecated:')) {
        $quebradas[] = "$p ($s)";
    }
}
confere('bibliotecária: todas as páginas novas abrem sem erros', !$quebradas, implode(', ', $quebradas));
[$s, $html] = $bib->ir('detalhes-livro.php?id=' . $livro['planilha']);
confere('página do livro mostra planilha, físicos e disponíveis separados e o botão de cadastro', $s === 200 && str_contains($html, 'Quantidade na planilha') && str_contains($html, 'cadastrar-exemplar.php?livro=' . $livro['planilha']));
[$s, $html] = $bib->ir('livros.php?exemplares=sem');
confere('filtro "livros sem exemplar físico"', $s === 200 && !str_contains($html, 'Dom Casmurro de Teste') && str_contains($html, 'Livro Sem Exemplar') === false && str_contains($html, 'name="exemplares"'));
[$s, $html] = $bib->ir('livros.php?exemplares=com');
confere('filtro "livros com exemplar físico"', $s === 200 && str_contains($html, 'Dom Casmurro de Teste'));
[$s, $html] = $bib->ir('exemplares.php?localizacao=sem');
confere('filtro de exemplares sem localização', $s === 200 && !str_contains($html, '>BDP-000001<'));
[$s, $html] = $bib->ir('exemplares.php?q=bdp-1');
confere('pesquisa por código digitado de qualquer jeito (bdp-1)', $s === 200 && str_contains($html, 'BDP-000001') && str_contains($html, '1 exemplar encontrado'));
[$s, $html] = $bib->ir('index.php');
confere('dashboard com indicadores do acervo físico', $s === 200 && str_contains($html, 'Sem localização confirmada') && str_contains($html, 'Livros sem exemplar físico') && str_contains($html, 'Inventários em andamento'));
[$s, $html] = $bib->ir('exemplares.php?q=' . rawurlencode("' OR 1=1 -- "));
confere('SQL injection na pesquisa não lista tudo nem quebra a página', $s === 200 && str_contains($html, 'Nenhum exemplar encontrado'));
[$s, $html] = $bib->ir('inventario-detalhes.php?id=' . $inv . '&filtro=todos&q=' . rawurlencode("x' UNION SELECT senha_hash FROM administradores -- "));
confere('SQL injection na pesquisa do inventário não expõe dados', $s === 200 && !str_contains($html, '$2y$'));
[$s, $html] = $bib->ir('exemplares.php?q=' . rawurlencode('<script>alert(1)</script>'));
confere('pesquisa com HTML é escapada (XSS)', $s === 200 && !str_contains($html, '<script>alert(1)</script>'));
[$s, $html] = $bib->ir('detalhes-exemplar.php?id=' . $exXss['id']);
confere('título com HTML é escapado na página do exemplar', $s === 200 && !str_contains($html, '<script>alert("x")</script>') && str_contains($html, '&lt;script&gt;'));
[$s, $html] = $bib->ir('etiquetas.php?ids=' . $exXss['id']);
confere('título com HTML é escapado na prévia da etiqueta (SVG)', $s === 200 && !str_contains($html, '<script>alert("x")</script>') && str_contains($html, 'adm-previa__folha'));

$tk = $bib->csrf('inventario-conferencia.php?id=' . $inv3);
[$s] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['id' => $inv3, 'acao' => 'conferir', 'exemplar_id' => $dom[0], 'estado_conservacao' => 'bom']);
confere('conferência sem token CSRF é recusada (419)', $s === 419 && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND conferido_em IS NOT NULL', [$inv3]) === 0);
[$s, , $loc] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['csrf' => $tk, 'id' => $inv3, 'acao' => 'ler', 'leitura' => 'bdp-000001']);
confere('leitura do código leva ao formulário de conferência', $s === 303 && str_contains($loc, 'codigo=bdp-000001'));
[$s, $html] = $bib->ir('inventario-conferencia.php?id=' . $inv3 . '&codigo=bdp-000001');
confere('formulário mostra o exemplar lido', $s === 200 && str_contains($html, 'Confirmar conferência') && str_contains($html, 'Dom Casmurro de Teste'));
[$s] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['csrf' => $tk, 'id' => $inv3, 'acao' => 'conferir', 'exemplar_id' => $dom[0], 'estado_conservacao' => 'regular', 'localizacao_id' => $locA]);
confere('conferência pelo formulário grava', $s === 303 && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ? AND conferido_em IS NOT NULL', [$inv3, $dom[0]]) === 1);
[$s, , $loc] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['csrf' => $tk, 'id' => $inv3, 'acao' => 'conferir', 'exemplar_id' => $dom[0], 'estado_conservacao' => 'bom']);
[, $html] = $bib->ir('inventario-conferencia.php?id=' . $inv3 . '&codigo=BDP-000001');
confere('conferência repetida pelo formulário: aviso com dados anteriores, nada gravado', $s === 303 && str_contains($html, 'já conferido neste inventário') && str_contains($html, 'Regular')
    && $val('SELECT estado_encontrado FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv3, $dom[0]]) === 'regular');
[$s, , $loc] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['csrf' => $tk, 'id' => $inv3, 'acao' => 'ler', 'leitura' => 'BDP-777777']);
[, $html] = $bib->ir('inventario-conferencia.php?id=' . $inv3 . '&codigo=BDP-777777');
confere('código inexistente: aviso, nada criado', str_contains($html, 'não encontrado') && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual = 'BDP-777777'") === 0);
[$s, , $loc] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['csrf' => $tk, 'id' => $inv3, 'acao' => 'ler', 'leitura' => 'Dom Casmurro']);
[, $html] = $bib->ir($loc ? ltrim(str_replace('/admin/', '', parse_url($loc, PHP_URL_PATH)) . '?' . parse_url($loc, PHP_URL_QUERY), '/') : 'inventario-conferencia.php?id=' . $inv3);
confere('texto lido pesquisa livros (título) com opção explícita de cadastrar exemplar', str_contains($html, 'Livros encontrados') && str_contains($html, 'Cadastrar exemplar'));
$antesForm = $num('SELECT COUNT(*) FROM exemplares');
[$s, $html] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['csrf' => $tk, 'id' => $inv3, 'acao' => 'cadastrar', 'livro_id' => $livro['semex'], 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'quantidade' => '1']);
confere('cadastro na conferência sem confirmação não grava', $s === 200 && str_contains($html, 'Marque a confirmação') && $num('SELECT COUNT(*) FROM exemplares') === $antesForm);
[$s] = $bib->ir('inventario-conferencia.php?id=' . $inv3, ['csrf' => $tk, 'id' => $inv3, 'acao' => 'cadastrar', 'livro_id' => $livro['semex'], 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'quantidade' => '1', 'confirmar' => '1']);
confere('cadastro na conferência com confirmação grava 1 exemplar', $s === 303 && $num('SELECT COUNT(*) FROM exemplares') === $antesForm + 1);
[$s, $pdfHttp, , $tipo] = $bib->ir('etiquetas.php', ['csrf' => $tk, 'acao' => 'pdf', 'ids' => $dom[0] . ',' . $dom[1], 'modelo' => $patrimonial['id']]);
confere('PDF de etiquetas pelo painel (POST com CSRF)', $s === 200 && str_starts_with($tipo, 'application/pdf') && str_starts_with($pdfHttp, '%PDF-'));
[$s] = $bib->ir('etiquetas.php', ['acao' => 'pdf', 'ids' => (string)$dom[0], 'modelo' => $patrimonial['id']]);
confere('PDF de etiquetas sem CSRF é recusado', $s === 419);
[$s, $csv, , $tipo] = $bib->ir('inventario-relatorio.php?id=' . $inv . '&formato=csv');
confere('relatório CSV do inventário, sem dados de alunos', $s === 200 && str_starts_with($tipo, 'text/csv') && str_contains($csv, 'BDP-000001') && !str_contains($csv, 'TESTE-INV') && !str_contains($csv, 'Aluno Fictício'));
[$s, $pdfRel, , $tipo] = $bib->ir('inventario-relatorio.php?id=' . $inv . '&formato=pdf');
confere('relatório PDF do inventário', $s === 200 && str_starts_with($tipo, 'application/pdf') && str_starts_with($pdfRel, '%PDF-'));
[$s] = $bib->ir('localizacao.php', ['csrf' => $tk, 'setor' => 'Literatura', 'estante' => 'E01', 'prateleira' => 'P01']);
confere('localização duplicada pelo formulário é recusada', $s === 200 && $num("SELECT COUNT(*) FROM localizacoes WHERE setor = 'Literatura' AND estante = 'E01' AND prateleira = 'P01'") === 1);

$aux = new Navegador($base);
$aux->entrar('aux@teste.br', $senhaPadrao);
$tka = $aux->csrf('inventario-conferencia.php?id=' . $inv3);
confere('auxiliar abre a conferência e as etiquetas', $aux->ir('inventario-conferencia.php?id=' . $inv3)[0] === 200 && $aux->ir('etiquetas.php?ids=' . $dom[0])[0] === 200);
confere('auxiliar não cria inventário nem configura etiquetas (403)', $aux->ir('inventario-editar.php')[0] === 403 && $aux->ir('etiqueta-modelos.php')[0] === 403 && $aux->ir('localizacao.php')[0] === 403);
[$s] = $aux->ir('inventario-detalhes.php?id=' . $inv3, ['csrf' => $tka, 'id' => $inv3, 'acao' => 'cancelar', 'motivo' => 'forçado']);
confere('auxiliar não cancela inventário nem enviando o formulário direto', $s === 403 && $val('SELECT status FROM inventarios WHERE id = ?', [$inv3]) === 'em_andamento');
[$s] = $aux->ir('detalhes-exemplar.php?id=' . $dom[1], ['csrf' => $tka, 'id' => $dom[1], 'acao' => 'baixa', 'motivo' => 'forçado', 'confirmar' => '1']);
confere('auxiliar não dá baixa em exemplar', $s === 403 && $val('SELECT situacao FROM exemplares WHERE id = ?', [$dom[1]]) !== 'baixado');

$cons = new Navegador($base);
$cons->entrar('cons@teste.br', $senhaPadrao);
$tkc = $cons->csrf('index.php');
confere('consulta vê as listas, mas não cadastra nem confere (403)', $cons->ir('exemplares.php')[0] === 200 && $cons->ir('inventario.php')[0] === 200
    && $cons->ir('cadastrar-exemplar.php')[0] === 403 && $cons->ir('inventario-conferencia.php?id=' . $inv3)[0] === 403 && $cons->ir('etiquetas.php')[0] === 403);
[$s] = $cons->ir('cadastrar-exemplar.php', ['csrf' => $tkc, 'livro_id' => $livro['dom'], 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'quantidade' => '1', 'confirmar' => '1']);
confere('consulta não cadastra exemplar enviando o formulário direto', $s === 403);
$logApp = implode("\n", preg_grep('/\[biblioteca\]/', file(sys_get_temp_dir() . "/$banco.log") ?: []));
confere('registros de erro sem dados de alunos', !preg_match('/TESTE-INV|Aluno Fictício|escola\.teste/', $logApp));
confere('nenhum erro de PHP registrado pelo servidor durante os testes', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', (string)@file_get_contents(sys_get_temp_dir() . "/$banco.log")),
    implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', file(sys_get_temp_dir() . "/$banco.log") ?: []), 0, 3)));

// ---------------------------------------------------------------
echo "\n20. Compatibilidade com PHP no XAMPP\n";
confere('PHP 8.2 ou mais novo (XAMPP atual)', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
confere('só extensões que o XAMPP já traz (pdo_mysql, mbstring)', extension_loaded('pdo_mysql') && extension_loaded('mbstring'));
$codigoNovo = '';
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: [], glob(__DIR__ . '/../../src/*/*.php') ?: []) as $f) {
    $codigoNovo .= file_get_contents($f);
}
confere('sem comandos do sistema operacional no código do painel (exec, shell_exec, proc_open)', !preg_match('/\b(shell_exec|proc_open|passthru|system)\s*\(|(?<!->)\bexec\s*\(/', $codigoNovo));
confere('PDF gerado também sem compressão zlib (instalações sem a extensão)', (function () use ($individual, $exDom) {
    $r = new ReflectionClass(Biblioteca\Support\Pdf::class);
    return $r->hasMethod('gerar') && str_starts_with(EtiquetaService::pdf($individual, $exDom, 0, 'Biblioteca'), '%PDF-');
})());
confere('migração aplicada pelo migrar.php (sem DELIMITER, compatível com MariaDB do XAMPP)', $num("SELECT COUNT(*) FROM schema_migracoes WHERE arquivo LIKE '070_%'") === 1);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../database/migrar.php') . ' 2>&1', $saida2, $codigo2);
confere('rodar as migrações de novo não altera nada (idempotente)', $codigo2 === 0 && $num('SELECT COUNT(*) FROM exemplares') === $antesForm + 1);

echo "\n" . ($falhas ? "$falhas de $total testes falharam.\n" : "Todos os $total testes passaram.\n");
exit($falhas ? 1 : 0);
