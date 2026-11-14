<?php
/**
 * Testes da identificação por código ESCRITO (BDP-000001) e das etiquetas só com texto.
 *
 * Uso: php admin/tests/codigos-etiquetas-teste.php
 *
 * Cobre: geração e unicidade dos códigos, preservação dos códigos existentes ao aplicar a
 * migração 100, pesquisa por código digitado (minúsculas, espaços, código incompleto ou
 * inexistente), cadastro, conferência do inventário, empréstimo e devolução pelas páginas do
 * painel, etiquetas individuais, em lote, de lombada (definitiva e provisória) e PDFs sem
 * código de barras/QR Code, integridade do banco e compatibilidade com o XAMPP.
 *
 * O teste NUNCA usa o banco do .env: cria um banco temporário "bdp_teste_codigos_XXXX" no mesmo
 * servidor, com dados FICTÍCIOS, e o apaga no final. Se pdftoppm e zbarimg estiverem instalados
 * (Linux), o PDF também é rasterizado e um leitor de códigos confirma que não há código nenhum.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Repositories\ExemplarRepository;
use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\EtiquetaService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LocalizacaoService;
use Biblioteca\Support\CodigoBdp;

require __DIR__ . '/../../config/app.php';

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

function bloqueado(string $sql): bool
{
    try {
        db()->exec($sql);
        return false;
    } catch (PDOException $e) {
        return true;
    }
}

/** Conteúdo (descomprimido) de todas as páginas de um PDF gerado pelo sistema. */
function pdf_conteudo(string $pdf): string
{
    preg_match_all('/<< \/Length (\d+)( \/Filter \/FlateDecode)? >>\nstream\n/', $pdf, $m, PREG_OFFSET_CAPTURE);
    $out = '';
    foreach ($m[0] as $i => [$cab, $pos]) {
        $dados = substr($pdf, $pos + strlen($cab), (int)$m[1][$i][0]);
        $out .= $m[2][$i][0] !== '' ? (string)@gzuncompress($dados) : $dados;
    }
    return $out;
}

/** Textos impressos (operadores Tj) do PDF, na ordem. */
function pdf_textos(string $pdf): array
{
    preg_match_all('/\((.*?)\) Tj/', pdf_conteudo($pdf), $m);
    return array_map(fn ($t) => stripcslashes($t), $m[1]);
}

$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "Testes não executados: configure DB_HOST/DB_USER no .env.\n";
    exit(0);
}
$banco = 'bdp_teste_codigos_' . bin2hex(random_bytes(4));
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
foreach ($envTeste as $k => $v) {
    putenv("$k=$v");
}
$migrar = fn () => exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../database/migrar.php') . ' 2>&1', $saida, $codigo) !== false && $codigo === 0;
if (!$migrar()) {
    fwrite(STDERR, "As migrações falharam.\n");
    exit(1);
}
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $banco) {
    fwrite(STDERR, "Conexão inesperada: o teste só roda no banco temporário.\n");
    exit(1);
}
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$val = fn (string $sql, array $p = []) => db_query($sql, $p)->fetchColumn();
$hoje = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

// ---------------------------------------------------------------
// Dados fictícios
// ---------------------------------------------------------------
$senha = 'SenhaDeTeste123';
$contas = [];
foreach ([['Bia Bibliotecaria', 'bib@teste.br', 'bibliotecaria'], ['Caio Auxiliar', 'aux@teste.br', 'auxiliar']] as [$n, $em, $p]) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute([$n, $em, password_hash($senha, PASSWORD_DEFAULT), $p]);
    $contas[$p] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$pdo->prepare('INSERT INTO alunos (nome, matricula, email, turma) VALUES (?, ?, ?, ?)')->execute(['Aluna Fictícia dos Códigos', 'TESTE-COD-01', 'aluna.codigos@escola.teste', '8B']);
$alunoId = (int)$pdo->lastInsertId();
$livro = [];
foreach ([['cdd', 'Livro Fictício Com CDD Aprovada'], ['sem', 'Livro Fictício Sem CDD'], ['vazio', 'Livro Fictício Sem Exemplar']] as [$k, $t]) {
    $pdo->prepare("INSERT INTO livros (titulo, origem_cadastro) VALUES (?, 'manual')")->execute([$t]);
    $livro[$k] = (int)$pdo->lastInsertId();
}
$pdo->prepare("INSERT INTO classificacoes_cdd (livro_id, status, cdd_aprovada, aprovado_por, aprovado_em) VALUES (?, 'aprovada', '869.3', ?, NOW())")->execute([$livro['cdd'], $bibId]);
// Livro sem CDD aprovada, mas com CDD e código de autor ANTIGOS na planilha (não validados).
$pdo->prepare("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, usuario_id, status) VALUES ('ficticio.xlsx', SHA2('cod', 256), 'x.upload', 'xlsx', 1, ?, 'concluida')")->execute([$bibId]);
$impId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, hash_linha, livro_resultante_id, resultado_importacao, processado_em) VALUES (?, 2, '{}', '{}', SHA1('cod'), ?, 'criado', NOW())")->execute([$impId, $livro['sem']]);
$pdo->prepare("INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, titulo_original, cdd_original, codigo_autor_antigo, quantidade_informada, dados_originais)
               VALUES (?, ?, ?, 'ficticio.xlsx', 2, 'LIVRO SEM CDD', '808.9', 'X99z', 6, '{}')")->execute([$impId, (int)$pdo->lastInsertId(), $livro['sem']]);
// A planilha antiga do livro sem CDD diz 6 exemplares; só os cadastrados fisicamente recebem código.

$exServ = new ExemplarService($pdo);
$exRepo = new ExemplarRepository($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);
$local = (new LocalizacaoService($pdo))->salvar(['setor' => 'Literatura', 'estante' => 'E02', 'prateleira' => 'P03', 'codigo' => 'LIT-E02-P03'], $bibId);

// ---------------------------------------------------------------
echo "\n1. Geração automática dos códigos BDP\n";
$a = $exServ->cadastrar($livro['cdd'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $local], $bibId, 3);
$b = $exServ->cadastrar($livro['sem'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId, 2);
confere('códigos gerados em sequência no cadastro (BDP-000001 a BDP-000005)', array_merge(array_column($a, 'codigo'), array_column($b, 'codigo')) === ['BDP-000001', 'BDP-000002', 'BDP-000003', 'BDP-000004', 'BDP-000005']);
confere('cada código ligado ao registro bibliográfico correto', $num("SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND codigo_individual IN ('BDP-000001','BDP-000002','BDP-000003')", [$livro['cdd']]) === 3
    && $num("SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND codigo_individual IN ('BDP-000004','BDP-000005')", [$livro['sem']]) === 2);
confere('livro sem exemplar físico não recebe código; a quantidade da planilha (6) não cria exemplares', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livro['vazio']]) === 0
    && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livro['sem']]) === 2);
confere('formato do código: CodigoBdp::formatar(7) = BDP-000007', CodigoBdp::formatar(7) === 'BDP-000007');

// ---------------------------------------------------------------
echo "\n2. Unicidade e não reutilização\n";
confere('código duplicado é recusado pelo banco', bloqueado("INSERT INTO exemplares (livro_id, codigo_individual) VALUES ({$livro['cdd']}, 'BDP-000001')"));
confere('código fora do padrão é recusado', bloqueado("INSERT INTO exemplares (livro_id, codigo_individual) VALUES ({$livro['cdd']}, 'bdp-000099')"));
confere('código não pode ser alterado', bloqueado("UPDATE exemplares SET codigo_individual = 'BDP-000500' WHERE codigo_individual = 'BDP-000002'"));
$exServ->baixar((int)$b[1]['id'], $bibId, 'teste fictício de baixa');
$c = $exServ->cadastrar($livro['sem'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId);
confere('código de exemplar baixado não é reutilizado (próximo: BDP-000006)', $c[0]['codigo'] === 'BDP-000006');
confere('nenhum código repetido no banco', $num('SELECT COUNT(*) FROM exemplares') === $num('SELECT COUNT(DISTINCT codigo_individual) FROM exemplares'));

// ---------------------------------------------------------------
echo "\n3. Preservação dos códigos existentes ao aplicar a migração 100\n";
// Simula um banco que já tinha exemplares, empréstimo e CDD antes da migração 100:
// volta o estado dos modelos ao da 070 e aplica a 100 de novo pelo migrar.php.
$circ = emprestimos_servico_teste($pdo);
function emprestimos_servico_teste(PDO $pdo): Biblioteca\Services\EmprestimoService
{
    return new Biblioteca\Services\EmprestimoService($pdo, new Biblioteca\Support\RegrasCirculacao(fuso: 'America/Sao_Paulo'));
}
$empAntigo = $circ->registrar($alunoId, (int)$a[2]['id'], $circ->prevista(), $bibId);
$foto = fn () => [
    'exemplares' => db_query('SELECT id, livro_id, codigo_individual, situacao FROM exemplares ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'seq' => $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1'),
    'emprestimos' => db_query('SELECT id, exemplar_id, aluno_id, data_emprestimo, data_prevista, data_devolucao FROM emprestimos ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'movimentacoes' => $num('SELECT COUNT(*) FROM movimentacoes'),
    'cdd' => db_query('SELECT livro_id, status, cdd_aprovada FROM classificacoes_cdd ORDER BY livro_id')->fetchAll(PDO::FETCH_ASSOC),
];
$antes = $foto();
$pdo->exec("DELETE FROM etiqueta_modelos WHERE nome IN ('Patrimonial A4 — 3 × 12 (50 × 20 mm, com espaço para recorte)', 'Patrimonial individual — 50 × 20 mm', 'Lombada individual — 30 × 20 mm')");
$pdo->exec("UPDATE etiqueta_modelos SET codigo_barras = TRUE WHERE nome <> 'Lombada A4 — 5 × 13 (38,1 × 21,2 mm)'");
$pdo->exec("DELETE FROM schema_migracoes WHERE arquivo = '100_etiquetas_codigo_escrito.sql'");
confere('migração 100 aplicada de novo sem erro', $migrar());
$depois = $foto();
confere('códigos, livros e situações dos exemplares idênticos', $antes['exemplares'] === $depois['exemplares']);
confere('sequência BDP não mudou', $antes['seq'] === $depois['seq']);
confere('empréstimos e históricos intactos', $antes['emprestimos'] === $depois['emprestimos'] && $antes['movimentacoes'] === $depois['movimentacoes']);
confere('classificações CDD intactas', $antes['cdd'] === $depois['cdd']);
confere('modelos: nenhum ficou marcado com código de barras e os 3 de 50 × 20 / 30 × 20 mm existem', $num('SELECT COUNT(*) FROM etiqueta_modelos WHERE codigo_barras') === 0 && $num('SELECT COUNT(*) FROM etiqueta_modelos') === 7);
confere('tabela etiqueta_modelos não foi recriada (coluna antiga mantida)', $num("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'etiqueta_modelos' AND COLUMN_NAME = 'codigo_barras'") === 1);
$circ->devolver((int)$empAntigo['id'], $bibId);

// ---------------------------------------------------------------
echo "\n4 a 7. Pesquisa pelo código digitado\n";
$empRepo = new EmprestimoRepository($pdo);
confere('código exato BDP-000001 encontra o exemplar e o título', ($exRepo->porCodigo('BDP-000001')['titulo'] ?? '') === 'Livro Fictício Com CDD Aprovada');
confere('minúsculas: bdp-000001', ($exRepo->porCodigo('bdp-000001')['codigo_individual'] ?? '') === 'BDP-000001');
confere('espaços antes e depois (inclusive tabulação e espaço "duro" de texto colado)', ($exRepo->porCodigo("  \tBDP-000004 \u{00A0}")['codigo_individual'] ?? '') === 'BDP-000004');
confere('sem hífen ou com espaço no lugar: BDP000004 e BDP 000004', ($exRepo->porCodigo('BDP000004')['codigo_individual'] ?? '') === 'BDP-000004' && ($exRepo->porCodigo('bdp 000004')['codigo_individual'] ?? '') === 'BDP-000004');
confere('código inexistente: nada encontrado', $exRepo->porCodigo('BDP-999999') === null && $empRepo->exemplarPorCodigo('BDP-999999') === null);
confere('sem correspondência aproximada: "BDP-1", "BDP-00001", "BDP-0000011" e "000001" não selecionam nada',
    $exRepo->porCodigo('BDP-1') === null && $exRepo->porCodigo('BDP-00001') === null && $exRepo->porCodigo('BDP-0000011') === null && $exRepo->porCodigo('000001') === null
    && $empRepo->exemplarPorCodigo('BDP-1') === null);
confere('código incompleto é reconhecido para mostrar mensagem clara', CodigoBdp::pareceCodigo('bdp-12') && !CodigoBdp::normalizar('bdp-12') && str_contains(CodigoBdp::mensagemInvalido('bdp-12'), 'BDP-000001'));
confere('título comum não é tratado como código', !CodigoBdp::pareceCodigo('Dom Casmurro') && !CodigoBdp::pareceCodigo('BDPX'));
$r = $empRepo->pesquisarExemplares(' bdp-000001 ');
confere('pesquisa de empréstimo com o código: só aquele exemplar', count($r['disponiveis']) === 1 && $r['disponiveis'][0]['codigo_individual'] === 'BDP-000001');
$r = $empRepo->pesquisarExemplares('Fictício Com CDD');
confere('pesquisa alternativa por título continua funcionando', count($r['disponiveis']) === 3);

// ---------------------------------------------------------------
echo "\n8 a 11 e 17. Páginas do painel (servidor PHP embutido, como no XAMPP)\n";
$portaHttp = 8790 + random_int(451, 600);
$log = sys_get_temp_dir() . "/$banco.log";
$temporarios[] = $log;
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, array_merge(getenv(), $envTeste));
usleep(900000);
$base = "http://127.0.0.1:$portaHttp/admin/";

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
}
/** Caminho relativo a admin/ de um Location. */
$destino = fn (string $loc) => ltrim(str_replace('/admin/', '', (string)parse_url($loc, PHP_URL_PATH)) . (parse_url($loc, PHP_URL_QUERY) ? '?' . parse_url($loc, PHP_URL_QUERY) : ''), '/');

$bib = new Navegador($base);
$tk = $bib->csrf('login.php');
$bib->ir('login.php', ['csrf' => $tk, 'email' => 'bib@teste.br', 'senha' => $senha]);
$tk = $bib->csrf('index.php');
confere('login da bibliotecária', $tk !== '');

// 8. Cadastro de exemplar pela página
$antesCad = $num('SELECT COUNT(*) FROM exemplares');
[$s, , $loc] = $bib->ir('cadastrar-exemplar.php', ['csrf' => $tk, 'livro_id' => $livro['cdd'], 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'quantidade' => '1', 'confirmar' => '1']);
confere('8. cadastro pela página gera o próximo código (BDP-000007) e nada mais', $s === 303 && $num('SELECT COUNT(*) FROM exemplares') === $antesCad + 1
    && $val('SELECT codigo_individual FROM exemplares ORDER BY id DESC LIMIT 1') === 'BDP-000007');

// 9. Conferência no inventário pelo código digitado
$inv = $invServ->criar(['titulo' => 'Inventário Fictício dos Códigos', 'data_inicio' => $hoje, 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv, $bibId);
[$s, $html] = $bib->ir('inventario-conferencia.php?id=' . $inv);
confere('9. tela de conferência pede para digitar o código (sem leitor ou câmera)', $s === 200 && str_contains($html, 'Pesquisar livro: código BDP')
    && !preg_match('/c[óo]digo de barras|leitor|escane|QR ?Code|c[âa]mera/iu', strip_tags($html)));
[$s, , $loc] = $bib->ir('inventario-conferencia.php?id=' . $inv, ['csrf' => $tk, 'id' => $inv, 'acao' => 'ler', 'leitura' => '  bdp-000002  ']);
[, $html] = $bib->ir($destino($loc));
confere('9. código digitado (minúsculo, com espaços) mostra o título e o formulário de confirmação', $s === 303 && str_contains($loc, 'codigo=BDP-000002') && str_contains($html, 'Livro Fictício Com CDD Aprovada') && str_contains($html, 'Salvar e marcar como encontrado'));
$exB2 = (int)$a[1]['id'];
[$s] = $bib->ir('inventario-conferencia.php?id=' . $inv, ['csrf' => $tk, 'id' => $inv, 'acao' => 'conferir', 'exemplar_id' => $exB2, 'estado_conservacao' => 'bom', 'localizacao_id' => $local]);
confere('9. conferência registrada', $s === 303 && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ? AND conferido_em IS NOT NULL', [$inv, $exB2]) === 1);
[$s] = $bib->ir('inventario-conferencia.php?id=' . $inv, ['csrf' => $tk, 'id' => $inv, 'acao' => 'conferir', 'exemplar_id' => $exB2, 'estado_conservacao' => 'regular']);
[, $html] = $bib->ir('inventario-conferencia.php?id=' . $inv . '&codigo=BDP-000002');
confere('9. conferência repetida não grava de novo', str_contains($html, 'já conferido neste inventário') && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv, $exB2]) === 1
    && $val('SELECT estado_encontrado FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv, $exB2]) === 'bom');
[$s, $html] = $bib->ir('inventario-conferencia.php?id=' . $inv, ['csrf' => $tk, 'id' => $inv, 'acao' => 'ler', 'leitura' => 'BDP-2']);
confere('9. código incompleto: mensagem clara e nada gravado', $s === 200 && str_contains($html, 'incompleto') && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND conferido_em IS NOT NULL', [$inv]) === 1);
[, , $loc] = $bib->ir('inventario-conferencia.php?id=' . $inv, ['csrf' => $tk, 'id' => $inv, 'acao' => 'ler', 'leitura' => 'BDP-888888']);
[, $html] = $bib->ir($destino($loc));
confere('9. código inexistente: "não encontrado", nada criado', str_contains($html, 'BDP-888888 não encontrado') && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual = 'BDP-888888'") === 0);
[, , $loc] = $bib->ir('inventario-conferencia.php?id=' . $inv, ['csrf' => $tk, 'id' => $inv, 'acao' => 'ler', 'leitura' => 'Sem Exemplar']);
[, $html] = $bib->ir($destino($loc));
confere('9. pesquisa alternativa por título localiza o registro bibliográfico', str_contains($html, 'Obras encontradas') && str_contains($html, 'Livro Fictício Sem Exemplar'));

// 10. Empréstimo: aluno → código → conferir → confirmar
[$s, $html] = $bib->ir('novo-emprestimo.php?aluno=' . $alunoId);
confere('10. passo do exemplar pede o código BDP', $s === 200 && str_contains($html, 'Digite o código BDP') && str_contains($html, 'Pesquisar exemplar'));
[$s, , $loc] = $bib->ir('novo-emprestimo.php?aluno=' . $alunoId . '&qe=' . rawurlencode(' bdp-000001 '));
confere('10. código disponível vai direto para a conferência do livro', $s === 303 && str_contains($loc, 'exemplar=' . (int)$a[0]['id']));
[$s, $html] = $bib->ir($destino($loc));
confere('10. resumo mostra o título e o código encontrados antes de confirmar', $s === 200 && str_contains($html, 'Livro Fictício Com CDD Aprovada') && str_contains($html, 'BDP-000001') && str_contains($html, 'name="confirmar"'));
[$s, $html] = $bib->ir('novo-emprestimo.php?aluno=' . $alunoId . '&qe=BDP-1');
confere('10. código incompleto no empréstimo: mensagem clara, nenhum exemplar listado', $s === 200 && str_contains($html, 'Código incompleto') && !str_contains($html, 'Exemplares disponíveis'));
[$s, $html] = $bib->ir('novo-emprestimo.php?aluno=' . $alunoId . '&qe=BDP-777777');
confere('10. código inexistente no empréstimo: mensagem clara', $s === 200 && str_contains($html, 'BDP-777777 não existe'));
[$s, , $loc] = $bib->ir('novo-emprestimo.php', ['csrf' => $tk, 'aluno' => $alunoId, 'exemplar' => (int)$a[0]['id'], 'confirmar' => '1']);
$empId = $num('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [(int)$a[0]['id']]);
confere('10. empréstimo registrado após a confirmação', $s === 303 && $empId > 0 && $val('SELECT situacao FROM exemplares WHERE id = ?', [(int)$a[0]['id']]) === 'emprestado');
[$s, , $loc] = $bib->ir('novo-emprestimo.php?aluno=' . $alunoId . '&qe=BDP-000001');
[, $html] = $bib->ir('novo-emprestimo.php?aluno=' . $alunoId . '&qe=BDP-000001');
confere('10. exemplar já emprestado não vai para a confirmação (validação mantida)', $s === 200 && str_contains($html, 'Encontrados, mas sem exemplar disponível'));

// 11. Devolução pelo código
[$s, $html] = $bib->ir('devolucoes.php');
confere('11. tela de devolução pede o código BDP', $s === 200 && str_contains($html, 'Informe o código BDP') && !preg_match('/c[óo]digo de barras|leitor|escane|QR ?Code|c[âa]mera/iu', strip_tags($html)));
[$s, , $loc] = $bib->ir('devolucoes.php?q=' . rawurlencode('  Bdp-000001 '));
confere('11. código digitado localiza o empréstimo ativo', $s === 303 && str_contains($loc, 'devolver-emprestimo.php?id=' . $empId));
[$s] = $bib->ir('devolver-emprestimo.php?id=' . $empId, ['csrf' => $tk, 'id' => $empId, 'confirmar' => '1']);
confere('11. devolução confirmada', $s === 303 && $val('SELECT data_devolucao FROM emprestimos WHERE id = ?', [$empId]) !== null && $val('SELECT situacao FROM exemplares WHERE id = ?', [(int)$a[0]['id']]) === 'disponivel');
[, $html] = $bib->ir('devolucoes.php?q=BDP-000001');
confere('11. sem empréstimo ativo: mensagem clara', str_contains($html, 'não tem empréstimo em aberto'));
[, $html] = $bib->ir('devolucoes.php?q=bdp-12');
confere('11. código incompleto: mensagem clara', str_contains($html, 'incompleto'));
[, $html] = $bib->ir('devolucoes.php?q=BDP-555555');
confere('11. código inexistente: mensagem clara', str_contains($html, 'BDP-555555 não existe'));

// ---------------------------------------------------------------
echo "\n12 a 15. Etiquetas (PDF sem código de barras ou QR Code)\n";
$modelos = [];
foreach ((new EtiquetaService($pdo))->modelos() as $m) {
    $modelos[$m['nome']] = $m;
}
$mIndividual = $modelos['Patrimonial individual — 50 × 20 mm'];
$mFolha = $modelos['Patrimonial A4 — 3 × 12 (50 × 20 mm, com espaço para recorte)'];
$mLombada = $modelos['Lombada individual — 30 × 20 mm'];
$mLombadaA4 = $modelos['Lombada A4 — 5 × 13 (38,1 × 21,2 mm)'];
confere('modelo padrão de 50 × 20 mm, fundo branco, só biblioteca e código', (float)$mIndividual['etiqueta_largura'] === 50.0 && (float)$mIndividual['etiqueta_altura'] === 20.0
    && (int)$mIndividual['mostrar_biblioteca'] === 1 && !(int)$mIndividual['mostrar_titulo'] && !(int)$mIndividual['mostrar_cdd'] && !(int)$mIndividual['mostrar_local']);

$exUm = $exRepo->paraEtiquetas([(int)$a[0]['id']]);
$els = EtiquetaService::elementos($mIndividual, $exUm[0], 'Biblioteca Dom Pedro II');
confere('12. etiqueta patrimonial: "BIBLIOTECA DOM PEDRO II" e "BDP-000001", nesta ordem', array_column($els, 'texto') === ['BIBLIOTECA DOM PEDRO II', 'BDP-000001']);
confere('12. código centralizado e com a maior letra', $els[1]['alinhamento'] === 'centro' && abs($els[1]['x'] - 25) < 0.01 && $els[1]['tamanho'] > $els[0]['tamanho'] * 2 && $els[1]['tamanho'] >= EtiquetaService::CODIGO_LEGIVEL);
confere('12. margens internas para recorte (texto longe da borda)', $els[0]['y'] - $els[0]['tamanho'] * 25.4 / 72 > 1.2 && $els[1]['y'] < 20 - 1.2
    && Biblioteca\Support\Pdf::medir($els[0]['texto'], $els[0]['tamanho'], 'negrito') <= 50 - 2 * 1.2);
[$s, $pdfUm, , $tipo] = $bib->ir('etiquetas.php', ['csrf' => $tk, 'acao' => 'pdf', 'ids' => (string)$a[0]['id'], 'modelo' => $mIndividual['id']]);
confere('12. PDF individual pelo painel: 1 página de 50 × 20 mm, impressão em 100%', $s === 200 && str_starts_with($tipo, 'application/pdf') && substr_count($pdfUm, '/Type /Page ') === 1
    && str_contains($pdfUm, '/MediaBox [0 0 141.732 56.693]') && str_contains($pdfUm, '/PrintScaling /None'));
confere('12. PDF individual contém exatamente os textos da etiqueta', pdf_textos($pdfUm) === ['BIBLIOTECA DOM PEDRO II', 'BDP-000001']);
[$s, $html] = $bib->ir('etiquetas.php?ids=' . $a[0]['id'] . '&modelo=' . $mIndividual['id']);
confere('12. pré-visualização em tamanho real (SVG em mm, fundo branco, só texto)', $s === 200 && str_contains($html, 'width="50mm" height="20mm"') && str_contains($html, '<rect width="50" height="20" fill="#fff"/>')
    && preg_match('#<svg[^>]*adm-etiqueta__svg.*?</svg>#s', $html, $svgEtq) && substr_count($svgEtq[0], '<rect') === 1 && str_contains($svgEtq[0], '>BDP-000001</text>'));

// 13. Lote: ids repetidos, baixado e inexistente
$todos = array_map('intval', array_column(db_query('SELECT id FROM exemplares ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'id'));
$pedido = array_merge($todos, $todos, [999999]);
$lote = $exRepo->paraEtiquetas($pedido);
$codigosLote = array_column($lote, 'codigo_individual');
confere('13. lote sem duplicadas, sem baixados e sem inexistentes', count($codigosLote) === count(array_unique($codigosLote)) && !in_array('BDP-000005', $codigosLote, true) && count($lote) === count($todos) - 1);
$muitos = [];
for ($i = 1; $i <= 40; $i++) {
    $muitos[] = ['codigo_individual' => CodigoBdp::formatar($i), 'titulo' => "Livro $i", 'cdd_aprovada' => null, 'localizacao_id' => null];
}
$paginas = EtiquetaService::paginar($mFolha, $muitos, 2);
confere('13. 40 etiquetas em folha A4 3 × 12 pulando 2 posições: 34 + 6, quebra de página correta', count($paginas) === 2 && count($paginas[0]) === 34 && count($paginas[1]) === 6
    && $paginas[1][0]['x'] === (float)$mFolha['margem_esquerda'] && $paginas[1][0]['y'] === (float)$mFolha['margem_superior']);
$cabe = true;
foreach ($paginas as $pg) {
    foreach ($pg as $slot) {
        $cabe = $cabe && $slot['x'] >= 0 && $slot['y'] >= 0 && $slot['x'] + 50 <= 210 && $slot['y'] + 20 <= 297;
    }
}
confere('13. todas as etiquetas dentro da folha A4, alinhadas na grade', $cabe && count(array_unique(array_map(fn ($s) => $s['x'], $paginas[0]))) === 3);
$pdfLote = EtiquetaService::pdf($mFolha, $muitos, 0, 'Biblioteca Dom Pedro II', true);
$codigosPdf = array_values(array_filter(pdf_textos($pdfLote), fn ($t) => str_starts_with($t, 'BDP-')));
confere('13. PDF do lote: 2 folhas e cada código impresso uma única vez', substr_count($pdfLote, '/Type /Page ') === 2 && $codigosPdf === array_column($muitos, 'codigo_individual'));
[$s, $pdfHttp] = $bib->ir('etiquetas.php', ['csrf' => $tk, 'acao' => 'pdf', 'ids' => implode(',', $pedido), 'modelo' => $mFolha['id']]);
$codigosHttp = array_values(array_filter(pdf_textos($pdfHttp), fn ($t) => str_starts_with($t, 'BDP-')));
confere('13. lote pelo painel com ids repetidos: cada exemplar uma vez', $s === 200 && count($codigosHttp) === count($todos) - 1 && count(array_unique($codigosHttp)) === count($codigosHttp));

// 14. Lombada
$exCdd = $exRepo->paraEtiquetas([(int)$a[1]['id']])[0];
$exSem = $exRepo->paraEtiquetas([(int)$b[0]['id']])[0];
$lCdd = EtiquetaService::elementos($mLombadaA4, $exCdd, 'Biblioteca Dom Pedro II');
confere('14. lombada com CDD aprovada: CDD, localização confirmada e código BDP', array_column($lCdd, 'texto') === ['869.3', 'LIT-E02-P03', 'BDP-000002'] && !EtiquetaService::lombadaProvisoria($mLombadaA4, $exCdd));
$lSem = EtiquetaService::elementos($mLombada, $exSem, 'Biblioteca Dom Pedro II');
confere('14. sem CDD aprovada: lombada provisória só com o código BDP', array_column($lSem, 'texto') === ['BDP-000004'] && EtiquetaService::lombadaProvisoria($mLombada, $exSem));
$todasLombadas = implode('|', array_merge(array_column($lSem, 'texto'), array_column(EtiquetaService::elementos($mLombadaA4, $exSem, 'B'), 'texto')));
confere('14. CDD antiga (808.9) e código de autor antigo (X99z) da planilha nunca são impressos', !str_contains($todasLombadas, '808.9') && !str_contains($todasLombadas, 'X99z'));
[$s, $html] = $bib->ir('etiquetas.php?ids=' . $a[1]['id'] . ',' . $b[0]['id'] . '&modelo=' . $mLombadaA4['id']);
confere('14. página avisa quantas lombadas saem provisórias', $s === 200 && str_contains($html, '1 etiqueta(s) de lombada provisória(s)'));
[$s, $html] = $bib->ir('etiquetas.php?ids=' . $a[1]['id'] . ',' . $b[0]['id'] . '&modelo=' . $mLombadaA4['id'] . '&sem_cdd=1');
confere('14. opção de deixar de fora quem não tem CDD aprovada', $s === 200 && str_contains($html, '1 exemplar(es) sem CDD aprovada ficaram fora'));
[$s, $pdfLomb] = $bib->ir('etiquetas.php', ['csrf' => $tk, 'acao' => 'pdf', 'ids' => $a[1]['id'] . ',' . $b[0]['id'], 'modelo' => $mLombadaA4['id']]);
confere('14. PDF de lombada: definitiva e provisória', $s === 200 && pdf_textos($pdfLomb) === ['869.3', 'LIT-E02-P03', 'BDP-000002', 'BDP-000004']);
confere('14. a CDD não foi alterada pela impressão', $val('SELECT cdd_aprovada FROM classificacoes_cdd WHERE livro_id = ?', [$livro['cdd']]) === '869.3' && $num('SELECT COUNT(*) FROM classificacoes_cdd') === 1);

// 15. Nenhum código visual em nenhum PDF
$semDesenho = true;
foreach ([$pdfUm, $pdfHttp, $pdfLomb, EtiquetaService::pdf($mFolha, $muitos, 0, 'B')] as $p) {
    $conteudo = pdf_conteudo($p);
    $semDesenho = $semDesenho && !str_contains($conteudo, 're f') && !preg_match('/\/(XObject|Image)\b/', $p) && preg_match_all('/ Tj /', $conteudo) > 0;
}
confere('15. PDFs sem retângulos preenchidos (barras), sem imagens: só texto', $semDesenho);
confere('15. com as linhas de recorte, só contornos finos (re S), nenhum preenchimento', str_contains(pdf_conteudo($pdfLote), 're S') && !str_contains(pdf_conteudo($pdfLote), 're f'));
confere('15. classe Code128 removida do sistema', !class_exists('Biblioteca\\Support\\Code128') && !file_exists(__DIR__ . '/../../src/Support/Code128.php'));
$fontes = '';
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: [], glob(__DIR__ . '/../includes/demo/*.php') ?: [], glob(__DIR__ . '/../assets/js/*.js') ?: [], glob(__DIR__ . '/../../src/*/*.php') ?: []) as $f) {
    $fontes .= file_get_contents($f);
}
confere('15. interface sem "escaneie", "leitor de código", "QR Code", "aponte a câmera" ou "Code 128"',
    !preg_match('/escane|leitor (de|USB)|leia o c[óo]digo|QR ?Code(?! nem| ou qualquer|, RFID| ou outro)|aponte a c[âa]mera|Code ?128/iu', preg_replace('/sem c[óo]digo de barras[^.\n]*|n[ãa]o h[áa] c[óo]digo de barras[^.\n]*|nenhuma etiqueta desenha c[óo]digo de barras[^.\n]*/iu', '', $fontes)));
$temZbar = trim((string)shell_exec('command -v zbarimg 2>/dev/null')) !== '' && trim((string)shell_exec('command -v pdftoppm 2>/dev/null')) !== '';
if ($temZbar) {
    $arq = tempnam(sys_get_temp_dir(), 'bdpetq') . '.pdf';
    $temporarios[] = $arq;
    file_put_contents($arq, $pdfHttp);
    exec('pdftoppm -r 300 -png ' . escapeshellarg($arq) . ' ' . escapeshellarg(substr($arq, 0, -4)));
    $lidos = [];
    $imagens = glob(substr($arq, 0, -4) . '*.png') ?: [];
    foreach ($imagens as $f) {
        $temporarios[] = $f;
        exec('zbarimg -q --raw ' . escapeshellarg($f) . ' 2>/dev/null', $lidos);
    }
    confere('15. PDF rasterizado a 300 dpi: o leitor zbarimg não encontra nenhum código de barras/QR', $imagens && $lidos === []);
    if (trim((string)shell_exec('command -v pdftotext 2>/dev/null')) !== '') {
        $txt = (string)shell_exec('pdftotext -layout ' . escapeshellarg($arq) . ' -');
        confere('15. texto do PDF legível por outro programa (pdftotext): códigos escritos', str_contains($txt, 'BDP-000001') && str_contains($txt, 'BIBLIOTECA DOM PEDRO II'));
    }
} else {
    echo "  (pdftoppm/zbarimg não instalados: a verificação por um leitor real foi pulada; o conteúdo do PDF foi conferido acima)\n";
}

// ---------------------------------------------------------------
echo "\n16. Integridade do banco\n";
confere('sequência nunca abaixo do maior código', $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1') >= $num("SELECT MAX(CAST(SUBSTRING(codigo_individual, 5) AS UNSIGNED)) FROM exemplares"));
confere('todos os códigos no padrão BDP-000000', $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual COLLATE utf8mb4_bin NOT REGEXP '^BDP-[0-9]{6}$'") === 0);
confere('todo exemplar aponta para um livro existente', $num('SELECT COUNT(*) FROM exemplares e LEFT JOIN livros l ON l.id = e.livro_id WHERE l.id IS NULL') === 0);
confere('nenhum exemplar com dois empréstimos abertos', $num('SELECT COUNT(*) FROM (SELECT exemplar_id FROM emprestimos WHERE data_devolucao IS NULL GROUP BY exemplar_id HAVING COUNT(*) > 1) x') === 0);
confere('exemplar não pode ser apagado', bloqueado('DELETE FROM exemplares WHERE id = ' . (int)$a[0]['id']));
confere('impressão registra a data da etiqueta sem mudar o código', $val('SELECT etiqueta_gerada_em FROM exemplares WHERE id = ?', [(int)$a[0]['id']]) !== null
    && $val('SELECT codigo_individual FROM exemplares WHERE id = ?', [(int)$a[0]['id']]) === 'BDP-000001');
confere('modelo com medidas que não cabem na folha é recusado', (function () {
    try {
        EtiquetaService::validarModelo(['nome' => 'Grande demais', 'pagina_largura' => 210, 'pagina_altura' => 297, 'etiqueta_largura' => 80, 'etiqueta_altura' => 30,
            'margem_superior' => 5, 'margem_esquerda' => 5, 'espaco_horizontal' => 2, 'espaco_vertical' => 0, 'colunas' => 3, 'linhas' => 10]);
        return false;
    } catch (AcervoException $e) {
        return true;
    }
})());
confere('dimensões configuráveis: modelo novo de 60 × 30 mm salvo sem campo de código de barras', (function () use ($pdo, $bibId) {
    $id = (new EtiquetaService($pdo))->salvarModelo(['nome' => 'Teste 60 × 30', 'tipo' => 'patrimonial', 'pagina_largura' => '60', 'pagina_altura' => '30', 'etiqueta_largura' => '60', 'etiqueta_altura' => '30',
        'margem_superior' => '0', 'margem_esquerda' => '0', 'espaco_horizontal' => '0', 'espaco_vertical' => '0', 'colunas' => '1', 'linhas' => '1', 'mostrar_biblioteca' => '1', 'codigo_barras' => '1'], $bibId);
    return (int)db_query('SELECT codigo_barras FROM etiqueta_modelos WHERE id = ?', [$id])->fetchColumn() === 0;
})());

// ---------------------------------------------------------------
echo "\n17. Compatibilidade com o XAMPP\n";
confere('PHP 8.2 ou mais novo', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
confere('só extensões que o XAMPP já traz (pdo_mysql, mbstring)', extension_loaded('pdo_mysql') && extension_loaded('mbstring'));
confere('migração 100 sem DELIMITER, aplicada pelo migrar.php', !str_contains((string)file_get_contents(__DIR__ . '/../../database/migrations/100_etiquetas_codigo_escrito.sql'), 'DELIMITER')
    && $num("SELECT COUNT(*) FROM schema_migracoes WHERE arquivo = '100_etiquetas_codigo_escrito.sql'") === 1);
confere('rodar as migrações de novo não altera nada', $migrar() && $num('SELECT COUNT(*) FROM etiqueta_modelos') === 8);
$quebradas = [];
foreach (['exemplares.php', 'exemplares.php?q=BDP-00', 'detalhes-exemplar.php?id=' . $a[0]['id'], 'detalhes-exemplar.php?codigo=bdp-000001', 'etiquetas.php', 'etiqueta-modelos.php', 'etiqueta-modelos.php?novo=1',
          'inventario.php', 'inventario-detalhes.php?id=' . $inv, 'devolucoes.php', 'emprestimos.php', 'index.php', 'historico-exemplar.php?codigo=BDP-000001'] as $p) {
    [$s, $html] = $bib->ir($p);
    if ($s !== 200 || preg_match('/Fatal error|Warning:|Notice:|Deprecated:/', $html)) {
        $quebradas[] = "$p ($s)";
    }
}
confere('páginas relacionadas abrem sem erros', !$quebradas, implode(', ', $quebradas));
[, $html] = $bib->ir('detalhes-exemplar.php?id=' . $a[0]['id']);
confere('página do exemplar mostra o código escrito, sem desenho de barras', str_contains($html, 'adm-codigo-escrito') && !str_contains($html, 'adm-barcode') && !str_contains($html, 'Code 128'));
[, $html] = $bib->ir('etiqueta-modelos.php?novo=1');
confere('formulário de modelo sem a opção "Código de barras"', !str_contains($html, 'name="codigo_barras"') && str_contains($html, 'value="50"') && str_contains($html, 'value="20"'));
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', (string)@file_get_contents($log)),
    implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', file($log) ?: []), 0, 3)));
confere('registros de erro sem dados de alunos', !preg_match('/TESTE-COD|Aluna Fictícia|escola\.teste/', implode("\n", preg_grep('/\[biblioteca\]/', file($log) ?: []))));

echo "\n" . ($falhas ? "$falhas de $total testes falharam.\n" : "Todos os $total testes passaram.\n");
exit($falhas ? 1 : 0);
