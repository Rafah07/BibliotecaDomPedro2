<?php
/**
 * Testes do site público: página inicial, catálogo (busca com e sem acentos, filtros, ordenação,
 * paginação), detalhes do livro, disponibilidade física, livros arquivados, Sobre/Regras,
 * textos institucionais do painel, capas, privacidade dos alunos, SQL injection, XSS,
 * proteção de arquivos, responsividade (se o Playwright estiver instalado) e compatibilidade.
 *
 * Uso: php admin/tests/site-publico-teste.php [--manter]
 *
 * O teste NUNCA usa o banco do .env. Ele usa só o servidor e o usuário de lá para criar um banco
 * temporário "bdp_teste_site_XXXX", aplica as migrações, cria livros, exemplares e alunos
 * FICTÍCIOS só nesse banco e o apaga no final (com --manter, o banco temporário fica para
 * conferência visual e o nome é mostrado no fim).
 * O usuário precisa poder criar bancos (no XAMPP, o root pode). Para outro usuário, defina
 * TESTE_DB_USER e TESTE_DB_PASSWORD.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Biblioteca\Repositories\CatalogoRepository;
use Biblioteca\Repositories\LivroRepository;
use Biblioteca\Repositories\SiteConfiguracaoRepository;
use Biblioteca\Services\CatalogoService;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\SiteConteudo;
use Biblioteca\Support\RegrasCirculacao;

require __DIR__ . '/../../config/app.php';

$manter = in_array('--manter', $argv, true);
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

$host = env('DB_HOST', '127.0.0.1');
$porta = (int)env('DB_PORT', '3306');
$usuarioDb = getenv('TESTE_DB_USER') ?: env('DB_USER');
$senhaDb = getenv('TESTE_DB_USER') ? (string)getenv('TESTE_DB_PASSWORD') : (string)env('DB_PASSWORD', '');
if (!$usuarioDb) {
    echo "Testes não executados: configure DB_HOST/DB_USER no .env.\n";
    exit(0);
}
$banco = 'bdp_teste_site_' . bin2hex(random_bytes(4));
$raiz = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $usuarioDb, $senhaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raiz->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$servidor = null;
$temporarios = [];
register_shutdown_function(function () use ($raiz, $banco, &$servidor, &$temporarios, $manter) {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
    }
    foreach ($temporarios as $f) {
        @unlink($f);
    }
    if ($manter) {
        echo "Banco temporário mantido para conferência: $banco (apague com DROP DATABASE `$banco`).\n";
        return;
    }
    $raiz->exec("DROP DATABASE IF EXISTS `$banco`");
    echo "Banco temporário $banco apagado.\n";
});
echo "Banco temporário: $banco (o banco do .env não é usado)\n";
$envTeste = ['DB_HOST' => $host, 'DB_PORT' => (string)$porta, 'DB_NAME' => $banco, 'DB_USER' => $usuarioDb, 'DB_PASSWORD' => $senhaDb, 'APP_BASE_PATH' => '/',
    'APP_ENV' => 'production', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'EMPRESTIMO_PRAZO_DIAS' => ''];
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

// ---------------------------------------------------------------
// Dados fictícios (somente no banco temporário)
// ---------------------------------------------------------------
$senhaPadrao = 'SenhaDeTeste123';
$pdo->prepare("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES ('Bia Bibliotecária Teste', 'bib@teste.br', ?, 'bibliotecaria')")->execute([password_hash($senhaPadrao, PASSWORD_DEFAULT)]);
$bibId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES ('Caio Auxiliar Teste', 'aux@teste.br', ?, 'auxiliar')")->execute([password_hash($senhaPadrao, PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO alunos (nome, matricula, email, turma) VALUES ('Aluna Secreta Fictícia', 'MAT-SECRETA-77', 'aluna.secreta@escola.teste', '8B')")->execute();
$alunoId = (int)$pdo->lastInsertId();

$categoria = [];
foreach (['Literatura brasileira', 'Ficção', 'Ciências'] as $nome) {
    $pdo->prepare('INSERT INTO categorias (nome) VALUES (?)')->execute([$nome]);
    $categoria[$nome] = (int)$pdo->lastInsertId();
}
$autorIds = [];
$criarLivro = function (array $d) use ($pdo, $categoria, &$autorIds): int {
    $pdo->prepare('INSERT INTO livros (titulo, subtitulo, isbn, editora, ano_publicacao, edicao, idioma, sinopse, assunto, palavras_chave, observacoes_internas, capa, criado_em)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$d['titulo'], $d['subtitulo'] ?? null, $d['isbn'] ?? null, $d['editora'] ?? null, $d['ano'] ?? null, $d['edicao'] ?? null, $d['idioma'] ?? 'Português',
            $d['sinopse'] ?? null, $d['assunto'] ?? null, $d['palavras'] ?? null, $d['interna'] ?? null, $d['capa'] ?? null, $d['criado'] ?? date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    foreach (array_values($d['autores'] ?? []) as $i => $autor) {
        if (!isset($autorIds[$autor])) {
            $pdo->prepare('INSERT INTO autores (nome) VALUES (?)')->execute([$autor]);
            $autorIds[$autor] = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, ?)')->execute([$id, $autorIds[$autor], $i + 1]);
    }
    if (!empty($d['categoria'])) {
        $pdo->prepare('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, 1)')->execute([$id, $categoria[$d['categoria']]]);
    }
    return $id;
};

$livro = [];
$livro['principe'] = $criarLivro(['titulo' => 'O Pequeno Príncipe das Estrelas', 'subtitulo' => 'Uma fábula fictícia', 'isbn' => '9788500000017', 'editora' => 'Editora Teste',
    'ano' => 2015, 'edicao' => '2ª edição', 'sinopse' => "Sinopse fictícia do príncipe.\n\nSegundo parágrafo.", 'assunto' => 'Literatura infantojuvenil', 'palavras' => 'amizade; estrelas',
    'interna' => 'OBSERVACAO-INTERNA-SIGILOSA', 'autores' => ['Antônio Exupério Teste'], 'categoria' => 'Ficção', 'criado' => '2026-01-10 10:00:00']);
$livro['coracao'] = $criarLivro(['titulo' => 'Coração de Papel', 'editora' => 'Editora Teste', 'ano' => 1999, 'autores' => ['Maria Conceição Fictícia'], 'categoria' => 'Literatura brasileira',
    'assunto' => 'Romance', 'criado' => '2026-01-11 10:00:00']);
$livro['mesmoautor'] = $criarLivro(['titulo' => 'Outra Viagem do Príncipe', 'ano' => 2018, 'autores' => ['Antônio Exupério Teste'], 'criado' => '2026-01-12 10:00:00']);
$livro['ciencia'] = $criarLivro(['titulo' => 'Ciência Divertida de Teste', 'isbn' => '8500000019', 'ano' => 2021, 'autores' => ['Zé Cientista Teste'], 'categoria' => 'Ciências',
    'assunto' => 'Experimentos de física', 'criado' => '2026-01-13 10:00:00']);
$livro['xss'] = $criarLivro(['titulo' => '<script>alert("xss")</script> Livro Perigoso', 'autores' => ['<img src=x onerror=alert(1)>'], 'sinopse' => '<b>negrito</b><script>alert(2)</script>',
    'criado' => '2026-01-14 10:00:00']);
$livro['arquivado'] = $criarLivro(['titulo' => 'Livro Arquivado Fictício', 'autores' => ['Autor Arquivado'], 'categoria' => 'Ficção', 'criado' => '2026-01-15 10:00:00']);
$livro['capa_ok'] = $criarLivro(['titulo' => 'Livro Com Capa Local', 'capa' => 'uploads/capas/livro-teste-capa.jpg', 'autores' => ['Autor Capa'], 'criado' => '2026-01-16 10:00:00']);
$livro['capa_externa'] = $criarLivro(['titulo' => 'Livro Com Capa Externa', 'capa' => 'https://rastreador.exemplo/capa.jpg?aluno=1', 'autores' => ['Autor Capa'], 'criado' => '2026-01-17 10:00:00']);
for ($i = 1; $i <= 50; $i++) {
    $criarLivro(['titulo' => sprintf('Volume de Paginação %02d', $i), 'ano' => 1950 + $i, 'autores' => ['Autor da Série Teste'], 'categoria' => $i % 2 ? 'Ficção' : 'Ciências',
        'criado' => sprintf('2025-06-%02d 10:00:00', min(28, $i))]);
}
$totalAtivos = 8 + 50 - 1; // todos menos o arquivado

// Planilha antiga: quantidade 7 para o "Coração de Papel" (não pode virar disponibilidade) e CDD antiga.
$pdo->prepare("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, usuario_id, status) VALUES ('acervo-ficticio.xlsx', SHA2('site', 256), 'x.upload', 'xlsx', 1, ?, 'concluida')")->execute([$bibId]);
$impId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, hash_linha, livro_resultante_id, resultado_importacao, processado_em) VALUES (?, 2, '{}', '{}', SHA1('site'), ?, 'criado', NOW())")->execute([$impId, $livro['coracao']]);
$pdo->prepare("INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, titulo_original, localizacao_antiga, cdd_original, quantidade_informada, dados_originais)
               VALUES (?, ?, ?, 'acervo-ficticio.xlsx', 2, 'CORACAO', 'ESTANTE-ANTIGA-99', '999.99', 7, '{}')")->execute([$impId, (int)$pdo->lastInsertId(), $livro['coracao']]);
// CDD aprovada (aparece) e CDD apenas sugerida (não aparece).
$pdo->prepare("INSERT INTO classificacoes_cdd (livro_id, status, cdd_aprovada, descricao, aprovado_por, aprovado_em) VALUES (?, 'aprovada', '843.91', 'Ficção fictícia', ?, NOW())")->execute([$livro['principe'], $bibId]);
$pdo->prepare("INSERT INTO classificacoes_cdd (livro_id, status, cdd_original, cdd_proposta) VALUES (?, 'sugerida', '999.99', '869.35')")->execute([$livro['coracao']]);

// Exemplares: Príncipe 2 disponíveis; Ciência 1 (será emprestado); Coração nenhum.
$exServ = new ExemplarService($pdo);
$exPrincipe = $exServ->cadastrar($livro['principe'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId, 2);
$exCiencia = $exServ->cadastrar($livro['ciencia'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId, 1);
$exServ->cadastrar($livro['mesmoautor'], ['estado_conservacao' => 'bom', 'situacao' => 'aguardando'], $bibId, 1);
$circ = new EmprestimoService($pdo, new RegrasCirculacao(fuso: 'America/Sao_Paulo'));
$emprestimo = $circ->registrar($alunoId, (int)$exCiencia[0]['id'], date('Y-m-d', strtotime('+10 days')), $bibId, 'Observação fictícia do empréstimo');
(new LivroRepository($pdo))->arquivar($livro['arquivado']);

// ---------------------------------------------------------------
echo "\n1. Serviço do catálogo (consultas diretas)\n";
$catalogo = new CatalogoService(new CatalogoRepository($pdo));
$busca = fn (array $f) => $catalogo->buscarLivros(CatalogoService::filtros($f));
$titulos = fn (array $r) => array_column($r['itens'], 'titulo');
confere('fonte "acervo" com livros reais', $catalogo->fonte() === 'acervo');
$r = $busca([]);
confere('listagem: total sem o livro arquivado e 24 por página', $r['total'] === $totalAtivos && count($r['itens']) === 24 && $r['paginas'] === 3);
confere('pesquisa por título', $titulos($busca(['q' => 'Coração de Papel']))  === ['Coração de Papel']);
confere('pesquisa sem acento encontra título acentuado ("principe")', in_array('O Pequeno Príncipe das Estrelas', $titulos($busca(['q' => 'pequeno principe'])), true));
confere('pesquisa sem acento e sem cedilha ("coracao")', $titulos($busca(['q' => 'coracao'])) === ['Coração de Papel']);
confere('pesquisa com acento e maiúsculas ("PRÍNCIPE")', count($busca(['q' => 'PRÍNCIPE'])['itens']) === 2);
confere('palavras em qualquer ordem ("estrelas pequeno")', $titulos($busca(['q' => 'estrelas pequeno'])) === ['O Pequeno Príncipe das Estrelas']);
confere('pesquisa por autor sem acento ("antonio exuperio")', count($busca(['q' => 'antonio exuperio', 'campo' => 'autor'])['itens']) === 2);
confere('campo "autor" não encontra pelo título', $busca(['q' => 'coracao', 'campo' => 'autor'])['total'] === 0);
confere('pesquisa por ISBN-13 com hífens', $titulos($busca(['q' => '978-85-00000-01-7'])) === ['O Pequeno Príncipe das Estrelas']);
confere('pesquisa por ISBN-10 no campo ISBN', $titulos($busca(['q' => '8500000019', 'campo' => 'isbn'])) === ['Ciência Divertida de Teste']);
confere('pesquisa por assunto ("fisica" sem acento)', $titulos($busca(['q' => 'fisica', 'campo' => 'assunto'])) === ['Ciência Divertida de Teste']);
confere('pesquisa por palavra-chave', $titulos($busca(['q' => 'amizade'])) === ['O Pequeno Príncipe das Estrelas']);
confere('pesquisa por nome da categoria ("ciencias")', $busca(['q' => 'ciencias', 'campo' => 'assunto'])['total'] === 26);
confere('livro arquivado não aparece na pesquisa', $busca(['q' => 'Arquivado'])['total'] === 0);
confere('filtro por categoria', $busca(['categoria' => (string)$categoria['Literatura brasileira']])['total'] === 1);
confere('filtro por autor', $busca(['autor' => (string)$autorIds['Antônio Exupério Teste']])['total'] === 2);
confere('filtro por ano (de/até)', $busca(['ano_de' => '1999', 'ano_ate' => '2000'])['total'] === 3);
confere('anos invertidos são corrigidos', $busca(['ano_de' => '2000', 'ano_ate' => '1999'])['total'] === 3);
confere('filtro "disponível agora" usa só exemplares físicos', $titulos($busca(['disponibilidade' => 'disponivel'])) === ['O Pequeno Príncipe das Estrelas']);
confere('filtro "com exemplares identificados"', $busca(['disponibilidade' => 'identificado'])['total'] === 3);
confere('ordenação título A–Z', array_slice($titulos($busca(['ordem' => 'titulo'])), 0, 3) === ['<script>alert("xss")</script> Livro Perigoso', 'Ciência Divertida de Teste', 'Coração de Papel']);
confere('ordenação título Z–A', $titulos($busca(['ordem' => 'titulo_desc']))[0] === 'Volume de Paginação 50');
confere('ordenação por autor', array_slice(array_column($busca(['ordem' => 'autor'])['itens'], 'autor'), 0, 3) === ['<img src=x onerror=alert(1)>', 'Antônio Exupério Teste', 'Antônio Exupério Teste']);
confere('ordenação por ano (mais novos e mais antigos)', $busca(['ordem' => 'ano_desc'])['itens'][0]['ano'] === 2021 && $busca(['ordem' => 'ano_asc'])['itens'][0]['ano'] === 1951);
confere('ordenação "adicionados recentemente"', $titulos($busca(['ordem' => 'recentes']))[0] === 'Livro Com Capa Externa');
$p3 = $busca(['pagina' => '3']);
confere('paginação no servidor (página 3 com o restante)', $p3['pagina'] === 3 && count($p3['itens']) === $totalAtivos - 48);
confere('página além do fim mostra a última', $busca(['pagina' => '999'])['pagina'] === 3);
confere('parâmetros inválidos são ignorados', CatalogoService::filtros(['ordem' => 'id; DROP TABLE livros', 'campo' => 'senha', 'categoria' => '1 OR 1=1', 'pagina' => '-5', 'ano_de' => 'abc'])
    === ['q' => '', 'campo' => 'tudo', 'categoria' => 0, 'autor' => 0, 'ano_de' => null, 'ano_ate' => null, 'disponibilidade' => '', 'ordem' => 'titulo', 'pagina' => 1]);
confere('valores de array na URL não quebram os filtros', CatalogoService::filtros(['q' => ['x'], 'categoria' => ['1']])['q'] === '');
$det = $catalogo->livro($livro['coracao']);
confere('sem exemplar físico: "ainda não identificados" (planilha com 7 não conta)', $det['disponibilidade']['estado'] === 'sem_exemplar' && $det['exemplares']['total'] === 0);
$det = $catalogo->livro($livro['principe']);
confere('com exemplares disponíveis: 2 de 2', $det['disponibilidade']['estado'] === 'disponivel' && $det['exemplares']['disponiveis'] === 2 && $det['exemplares']['total'] === 2);
$det = $catalogo->livro($livro['ciencia']);
confere('todos emprestados: nenhum disponível', $det['disponibilidade']['estado'] === 'indisponivel' && $det['exemplares']['emprestados'] === 1);
confere('exemplar "aguardando liberação" conta como identificado, mas não disponível', $catalogo->livro($livro['mesmoautor'])['disponibilidade']['estado'] === 'indisponivel');
confere('livro arquivado não tem página', $catalogo->livro($livro['arquivado']) === null && $catalogo->livro(999999) === null);
confere('só a CDD aprovada é exibida', $catalogo->livro($livro['principe'])['cdd']['cdd_aprovada'] === '843.91' && $catalogo->livro($livro['coracao'])['cdd'] === null);
$rel = $catalogo->relacionados($catalogo->livro($livro['principe']));
confere('relacionados: mesmo autor e mesma categoria, sem repetir o livro', array_column($rel['autor'], 'titulo') === ['Outra Viagem do Príncipe'] && count($rel['categoria']) === 4
    && !in_array($livro['principe'], array_column($rel['categoria'], 'id'), true));
confere('capa local aceita; capa externa ignorada (sem enviar dados a terceiros)', $catalogo->livro($livro['capa_ok'])['capa'] === 'uploads/capas/livro-teste-capa.jpg' && $catalogo->livro($livro['capa_externa'])['capa'] === null
    && CatalogoService::capaSegura('uploads/capas/../../.env') === null && CatalogoService::capaSegura('javascript:alert(1)') === null);
confere('página inicial: recentes em ordem de cadastro, sem o arquivado', array_column($catalogo->recentes(3), 'titulo') === ['Livro Com Capa Externa', 'Livro Com Capa Local', '<script>alert("xss")</script> Livro Perigoso']);
confere('estatísticas: títulos, exemplares e disponíveis', $catalogo->estatisticas() === ['titulos' => $totalAtivos, 'exemplares' => 4, 'disponiveis' => 2]);
confere('categorias com quantidade (sem contar o arquivado)', array_column($catalogo->categorias(), 'total', 'nome') === ['Ciências' => 26, 'Ficção' => 26, 'Literatura brasileira' => 1]);
confere('autores com busca sem acento', array_column($catalogo->autores('conceicao'), 'nome') === ['Maria Conceição Fictícia']);
confere('banco vazio: fonte "vazio"; sem banco: "nao_configurado"', (new CatalogoService(null))->fonte() === 'nao_configurado' && (new CatalogoService(null, true))->fonte() === 'indisponivel');

echo "\n2. Textos institucionais\n";
$siteRepo = new SiteConfiguracaoRepository($pdo);
$vazio = new SiteConteudo($siteRepo, new RegrasCirculacao(prazoDias: 14, prazoProvisorio: true));
confere('sem textos: tudo "aguardando confirmação" e boas-vindas neutra', !$vazio->preenchido('horario') && !$vazio->preenchido('email') && $vazio->valor('boas_vindas') !== '');
[$v, $erros] = SiteConteudo::validar(['email' => 'nao-e-email', 'telefone' => str_repeat('9', 61), 'mostrar_prazo' => '1']);
confere('validação: e-mail inválido e texto longo são recusados', isset($erros['email'], $erros['telefone']));
[$v, $erros] = SiteConteudo::validar(['horario' => "- Segunda: 8h às 12h (fictício)\n- Terça: 8h às 12h", 'email' => 'biblioteca@escola.teste', 'mostrar_prazo' => '1', 'escola_nome' => "Escola\x07 Fictícia"]);
confere('validação aceita textos corretos e remove caracteres de controle', !$erros && $v['escola_nome'] === 'Escola Fictícia');
$siteRepo->salvar($v, $bibId);
confere('prazo NÃO aparece enquanto for provisório, mesmo com a opção marcada', (new SiteConteudo($siteRepo, new RegrasCirculacao(prazoProvisorio: true)))->prazoEmprestimo() === null);
confere('prazo aparece quando configurado e liberado no painel', (new SiteConteudo($siteRepo, new RegrasCirculacao(prazoDias: 10, prazoProvisorio: false)))->prazoEmprestimo() === 10);
$siteRepo->salvar(['mostrar_prazo' => '0'] + $v, $bibId);
confere('prazo configurado, mas não liberado: não aparece', (new SiteConteudo($siteRepo, new RegrasCirculacao(prazoDias: 10, prazoProvisorio: false)))->prazoEmprestimo() === null);

// ---------------------------------------------------------------
echo "\n3. Páginas públicas (servidor PHP embutido no banco temporário)\n";
$portaHttp = 8790 + random_int(451, 600);
$log = sys_get_temp_dir() . "/$banco.log";
$temporarios[] = $log;
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaHttp", '-t', realpath(__DIR__ . '/../..')], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipesHttp, null, array_merge(getenv(), $envTeste));
usleep(900000);
$baseHttp = "http://127.0.0.1:$portaHttp/";

/** GET/POST simples com cookies. Retorna [status, corpo, location, cabeçalhos]. */
final class Navegador
{
    private array $cookies = [];
    public function __construct(private string $base) {}
    public function ir(string $pagina, ?array $post = null, ?string $multipart = null, string $limite = ''): array
    {
        $tipo = $multipart !== null ? "multipart/form-data; boundary=$limite" : 'application/x-www-form-urlencoded';
        $ctx = stream_context_create(['http' => [
            'method' => $post === null && $multipart === null ? 'GET' : 'POST',
            'header' => "Content-Type: $tipo\r\nCookie: " . http_build_query($this->cookies, '', '; ') . "\r\n",
            'content' => $multipart ?? ($post === null ? '' : http_build_query($post)),
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30,
        ]]);
        $corpo = (string)@file_get_contents($this->base . $pagina, false, $ctx);
        $status = 0;
        $local = '';
        $cab = [];
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int)$m[1];
            } elseif (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) {
                $this->cookies[$m[1]] = $m[2];
            } elseif (preg_match('/^Location: (.*)$/i', $h, $m)) {
                $local = trim($m[1]);
            }
            if (str_contains($h, ':')) {
                [$k, $vv] = explode(':', $h, 2);
                $cab[strtolower(trim($k))] = trim($vv);
            }
        }
        return [$status, $corpo, $local, $cab];
    }
    public function csrf(string $pagina): string
    {
        [, $corpo] = $this->ir($pagina);
        return preg_match('/name="csrf" value="([a-f0-9]+)"/', $corpo, $m) ? $m[1] : '';
    }
    public function entrar(string $email, string $senha): int
    {
        $t = $this->csrf('admin/login.php');
        return $this->ir('admin/login.php', ['csrf' => $t, 'email' => $email, 'senha' => $senha])[0];
    }
}

$web = new Navegador($baseHttp);
$semErros = fn (string $html) => !preg_match('/Fatal error|Warning:|Notice:|Deprecated:|Uncaught|SQLSTATE|PDOException/', $html);
$paginas = ['', 'index.php', 'biblioteca_php/catalogo.php', 'biblioteca_php/catalogo.php?q=principe', 'biblioteca_php/livro.php?id=' . $livro['principe'],
    'biblioteca_php/sobre.php', 'biblioteca_php/regras.php', 'biblioteca_php/generos.php', 'biblioteca_php/autores.php', 'biblioteca_php/autores.php?q=conceicao'];
$html = [];
$quebradas = [];
foreach ($paginas as $p) {
    [$s, $h, , $cab] = $web->ir($p);
    $html[$p] = $h;
    if ($s !== 200 || !$semErros($h) || !str_contains($h, '</html>')) {
        $quebradas[] = "/$p ($s)";
    }
}
confere('todas as páginas públicas abrem (200) sem erros nem avisos', !$quebradas, implode(', ', $quebradas));
[, , , $cab] = $web->ir('');
confere('cabeçalhos de segurança (CSP, nosniff, frame)', str_contains($cab['content-security-policy'] ?? '', "default-src 'self'") && ($cab['x-content-type-options'] ?? '') === 'nosniff' && isset($cab['x-frame-options']));
confere('nenhuma página pública carrega recursos externos (fontes, scripts, imagens)', !preg_match('#(src|href)="https?://#', implode('', $html)));
$inicio = $html[''];
confere('página inicial: nome, boas-vindas, pesquisa e botão do catálogo', str_contains($inicio, 'Biblioteca Dom Pedro II') && str_contains($inicio, 'name="q"') && str_contains($inicio, 'Ver o catálogo completo'));
confere('página inicial: livros recentes reais do banco, sem o arquivado', str_contains($inicio, 'Livro Com Capa Externa') && !str_contains($inicio, 'Livro Arquivado'));
confere('página inicial: atalhos para Catálogo, Sobre e Regras', str_contains($inicio, 'biblioteca_php/sobre.php') && str_contains($inicio, 'biblioteca_php/regras.php'));
confere('menu com Início, Catálogo, Sobre e Regras e acesso da equipe separado', str_contains($inicio, '>Início<') && str_contains($inicio, '>Catálogo<') && str_contains($inicio, 'Sobre a biblioteca') && str_contains($inicio, 'Regras e funcionamento') && str_contains($inicio, 'Acesso da equipe'));
confere('rodapé com nome da escola e e-mail informados no painel', str_contains($inicio, 'Escola Fictícia') && str_contains($inicio, 'biblioteca@escola.teste'));
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?q=pequeno+principe');
confere('catálogo: pesquisa sem acento pela página', $s === 200 && str_contains($h, 'O Pequeno Príncipe das Estrelas') && str_contains($h, 'Ver detalhes'));
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?q=livro+inexistente+xyz');
confere('catálogo: mensagem quando nada é encontrado e botão para limpar filtros', str_contains($h, 'Nenhum livro encontrado') && str_contains($h, 'Limpar filtros'));
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?categoria=' . $categoria['Ficção'] . '&ordem=ano_desc&pagina=2');
confere('catálogo: paginação preserva categoria e ordenação nos links', str_contains($h, 'categoria=' . $categoria['Ficção']) && str_contains($h, 'ordem=ano_desc') && preg_match('/aria-current="page">2</', $h) === 1);
confere('catálogo: filtros aplicados aparecem e podem ser removidos', str_contains($h, 'Filtros:') && str_contains($h, 'Limpar tudo'));
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?autor=' . $autorIds['Antônio Exupério Teste']);
confere('catálogo: filtro por autor mostra o nome do autor', str_contains($h, 'Autor: Antônio Exupério Teste') && str_contains($h, '2 livros'));
$d = $html['biblioteca_php/livro.php?id=' . $livro['principe']];
confere('detalhes: título, subtítulo, autor, editora, ano, edição, ISBN, idioma, sinopse e assunto', str_contains($d, 'O Pequeno Príncipe das Estrelas') && str_contains($d, 'Uma fábula fictícia')
    && str_contains($d, 'Antônio Exupério Teste') && str_contains($d, 'Editora Teste') && str_contains($d, '2015') && str_contains($d, '2ª edição') && str_contains($d, '9788500000017')
    && str_contains($d, 'Português') && str_contains($d, 'Sinopse fictícia do príncipe.') && str_contains($d, 'Literatura infantojuvenil'));
confere('detalhes: CDD aprovada e disponibilidade "2 exemplares disponíveis"', str_contains($d, '843.91') && str_contains($d, '2 exemplares disponíveis'));
confere('detalhes: livros relacionados reais', str_contains($d, 'Mais livros do mesmo autor') && str_contains($d, 'Outra Viagem do Príncipe'));
confere('detalhes: nada interno (observações, códigos BDP, situação de validação)', !str_contains($d, 'OBSERVACAO-INTERNA-SIGILOSA') && !str_contains($d, 'BDP-0') && !str_contains($d, 'aguardando_conferencia'));
[$s, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['coracao']);
confere('livro sem exemplar, sem ISBN e sem capa: aviso adequado e capa padrão', $s === 200 && str_contains($d, 'exemplares físicos ainda não foram identificados') && !str_contains($d, '>ISBN<') && str_contains($d, 'cover-title'));
confere('dados antigos da planilha não aparecem (quantidade, CDD e localização antigas, CDD sugerida)', !str_contains($d, '999.99') && !str_contains($d, 'ESTANTE-ANTIGA-99') && !str_contains($d, '869.35') && !preg_match('/\b7 exemplares\b/', $d));
[$s, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['ciencia']);
confere('todos emprestados: "Nenhum exemplar disponível no momento"', str_contains($d, 'Nenhum exemplar disponível no momento') && str_contains($d, 'Emprestados'));
[$s, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['arquivado']);
confere('livro arquivado: página 404 amigável', $s === 404 && str_contains($d, 'Livro não encontrado') && $semErros($d));
[$s, $d] = $web->ir('biblioteca_php/livro.php?id=abc');
confere('id inválido: 404 amigável', $s === 404 && $semErros($d));
[$s, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['capa_ok']);
confere('capa local exibida com carregamento tardio', str_contains($d, '/uploads/capas/livro-teste-capa.jpg'));
[$s, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['capa_externa']);
confere('capa externa não é carregada', !str_contains($d, 'rastreador.exemplo'));

echo "\n4. Disponibilidade acompanha o painel (empréstimo, devolução, exemplar, edição, arquivamento)\n";
$circ->devolver((int)$emprestimo['id'], $bibId);
[, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['ciencia']);
confere('após a devolução: "1 exemplar disponível"', str_contains($d, '1 exemplar disponível'));
$emp2 = $circ->registrar($alunoId, (int)$exPrincipe[0]['id'], date('Y-m-d', strtotime('+7 days')), $bibId);
[, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['principe']);
confere('após novo empréstimo: "1 exemplar disponível" de 2', str_contains($d, '1 exemplar disponível') && str_contains($d, 'de 2 exemplares identificados'));
$exServ->cadastrar($livro['coracao'], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bibId, 1);
[, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['coracao']);
confere('após cadastrar exemplar: o livro passa a ter 1 disponível', str_contains($d, '1 exemplar disponível'));
$pdo->prepare("UPDATE livros SET titulo = 'Coração de Papel Revisado' WHERE id = ?")->execute([$livro['coracao']]);
[, $d] = $web->ir('biblioteca_php/catalogo.php?q=revisado');
confere('edição bibliográfica aparece no catálogo', str_contains($d, 'Coração de Papel Revisado'));
(new LivroRepository($pdo))->arquivar($livro['capa_externa']);
[$s] = $web->ir('biblioteca_php/livro.php?id=' . $livro['capa_externa']);
[, $d] = $web->ir('biblioteca_php/catalogo.php?q=' . rawurlencode('capa externa'));
confere('livro arquivado no painel some do site', $s === 404 && str_contains($d, 'Nenhum livro encontrado'));
confere('o site público não grava nada: empréstimos e exemplares inalterados após as visitas', $num('SELECT COUNT(*) FROM emprestimos') === 2 && $num('SELECT COUNT(*) FROM exemplares') === 5);
$circ->devolver((int)$emp2['id'], $bibId);

echo "\n5. Privacidade, SQL injection e XSS\n";
$todas = '';
foreach (array_merge($paginas, ['biblioteca_php/livro.php?id=' . $livro['ciencia'], 'biblioteca_php/catalogo.php?q=Secreta', 'biblioteca_php/catalogo.php?q=MAT-SECRETA']) as $p) {
    $todas .= $web->ir($p)[1];
}
confere('nenhum dado de aluno (nome, matrícula, e-mail) nem observação de empréstimo aparece', !str_contains($todas, 'Aluna Secreta') && !str_contains($todas, 'MAT-SECRETA-77') && !str_contains($todas, 'aluna.secreta@') && !str_contains($todas, 'Observação fictícia do empréstimo'));
confere('nenhum dado de administrador aparece', !str_contains($todas, 'bib@teste.br') && !str_contains($todas, 'Bia Bibliotecária') && !str_contains($todas, '$2y$'));
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?q=' . rawurlencode("' OR 1=1 -- "));
confere('SQL injection na pesquisa não lista tudo nem expõe erros', $s === 200 && str_contains($h, 'Nenhum livro encontrado') && $semErros($h));
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?q=' . rawurlencode("x' UNION SELECT senha_hash FROM administradores -- ") . '&ordem=' . rawurlencode('titulo; DROP TABLE livros'));
confere('UNION e ordenação maliciosa são neutralizados', $s === 200 && !str_contains($h, '$2y$') && $num('SELECT COUNT(*) FROM livros') > 0);
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?categoria=' . rawurlencode('1 OR 1=1') . '&ano_de=' . rawurlencode('2000 OR 1') . '&pagina=' . rawurlencode('-1'));
confere('parâmetros numéricos maliciosos são ignorados', $s === 200 && $semErros($h));
[$s, $h] = $web->ir('biblioteca_php/catalogo.php?q=' . rawurlencode('<script>alert(1)</script>'));
confere('pesquisa com HTML é escapada (XSS refletido)', $s === 200 && !str_contains($h, '<script>alert(1)</script>') && str_contains($h, '&lt;script&gt;alert(1)&lt;/script&gt;'));
[$s, $h] = $web->ir('biblioteca_php/livro.php?id=' . $livro['xss']);
confere('título, autor e sinopse com HTML são escapados (XSS armazenado)', !str_contains($h, '<script>alert("xss")</script>') && !str_contains($h, '<img src=x') && !str_contains($h, '<b>negrito</b>') && str_contains($h, '&lt;script&gt;'));
confere('livro com HTML no título também é escapado na página inicial', !str_contains($inicio, '<script>alert("xss")</script>'));
$siteRepo->salvar(['apresentacao' => '<script>alert("site")</script> Texto'] + SiteConteudo::validar([])[0], $bibId);
[, $h] = $web->ir('biblioteca_php/sobre.php');
confere('texto institucional com HTML é escapado', !str_contains($h, '<script>alert("site")</script>') && str_contains($h, '&lt;script&gt;'));
confere('campos institucionais vazios mostram "aguardando confirmação da escola"', str_contains($h, 'aguardando confirmação da escola'));
[, $h] = $web->ir('biblioteca_php/regras.php');
confere('regras: empréstimo presencial, sem reservas on-line e sem prazo inventado', str_contains($h, 'presencialmente') && str_contains($h, 'não há reservas') && !preg_match('/Prazo de empréstimo: <strong>/', $h));
[$s, , $loc] = $web->ir('biblioteca_php/horarios.php');
[$s2, , $loc2] = $web->ir('biblioteca_php/contato.php');
confere('endereços antigos (horários e contato) redirecionam para Sobre', $s === 301 && str_contains($loc, 'sobre.php#horario') && $s2 === 301 && str_contains($loc2, 'sobre.php#contato'));

echo "\n6. Arquivos sensíveis e regras do Apache (.htaccess)\n";
$htaccess = (string)file_get_contents(__DIR__ . '/../../.htaccess');
preg_match('/<FilesMatch "([^"]+)">/', $htaccess, $m);
$regex = '#' . str_replace('#', '\#', $m[1] ?? 'nada') . '#';
$bloqueados = ['.env', '.env.local', '.gitignore', 'backup.sql', 'acervo.xlsx', 'alunos.csv', 'banco.sql.gz', 'erro.log', 'config.php.bak', 'README.md', 'composer.json', 'dump.zip'];
$liberados = ['index.php', 'style.css', 'app.js', 'banner.jpg', 'emblema-pequeno.png', 'catalogo.php'];
confere('.htaccess bloqueia .env, backups, planilhas, SQL, logs e documentação', array_reduce($bloqueados, fn ($ok, $a) => $ok && preg_match($regex, $a) === 1, true));
confere('.htaccess não bloqueia páginas, estilos, scripts e imagens', array_reduce($liberados, fn ($ok, $a) => $ok && preg_match($regex, $a) === 0, true));
preg_match('/RedirectMatch 404 (\S+)/', $htaccess, $m2);
$pastas = '#' . str_replace('#', '\#', $m2[1] ?? 'nada') . '#';
confere('pastas internas respondem 404 no Apache', array_reduce(['/config/database.php', '/src/Services/X.php', '/storage/importacoes/a.xlsx', '/database/migrar.php', '/vendor/autoload.php', '/admin/tests/site-publico-teste.php', '/includes/layout.php', '/.git/config', '/backups/b.sql'],
    fn ($ok, $u) => $ok && preg_match($pastas, $u) === 1, true) && preg_match($pastas, '/biblioteca_php/catalogo.php') === 0 && preg_match($pastas, '/admin/login.php') === 0);
$protegidas = ['config', 'database', 'src', 'storage', 'includes', 'docs', 'admin/includes', 'admin/tests'];
confere('cada pasta interna tem .htaccess "Require all denied"', array_reduce($protegidas, fn ($ok, $p) => $ok && str_contains((string)@file_get_contents(__DIR__ . "/../../$p/.htaccess"), 'Require all denied'), true));
confere('pasta das capas não executa scripts', str_contains((string)@file_get_contents(__DIR__ . '/../../uploads/capas/.htaccess'), 'engine off'));
$git = [];
exec('git -C ' . escapeshellarg(realpath(__DIR__ . '/../..')) . ' ls-files 2>/dev/null', $git);
confere('Git: .env, backups, dumps e logs não estão no repositório', $git && !array_filter($git, fn ($f) => preg_match('#(^|/)\.env$|\.(bak|log|dump|zip)$|^backups?/|^uploads/capas/.+\.(jpe?g|png|webp)$#', $f)));
$gitignore = (string)file_get_contents(__DIR__ . '/../../.gitignore');
confere('.gitignore cobre .env, backups e capas enviadas', str_contains($gitignore, '/.env') && str_contains($gitignore, '*.bak') && str_contains($gitignore, '/uploads/capas/*'));
[$s, $h] = $web->ir('admin/tests/site-publico-teste.php');
confere('scripts de teste não rodam pelo navegador', $s === 404 && !str_contains($h, 'Banco temporário'));

echo "\n7. Painel: textos do site e capa (permissões, CSRF)\n";
$anon = new Navegador($baseHttp);
[$s, , $loc] = $anon->ir('admin/site-publico.php');
confere('sem login, "Site público" leva ao login', $s === 302 && str_contains($loc, 'login.php'));
$aux = new Navegador($baseHttp);
$aux->entrar('aux@teste.br', $senhaPadrao);
[$s] = $aux->ir('admin/site-publico.php');
confere('auxiliar não edita os textos do site (403)', $s === 403);
$bib = new Navegador($baseHttp);
confere('login da bibliotecária', $bib->entrar('bib@teste.br', $senhaPadrao) === 302);
[$s, $h] = $bib->ir('admin/site-publico.php');
confere('página "Site público" abre sem erros', $s === 200 && $semErros($h) && str_contains($h, 'Horário de funcionamento'));
[$s] = $bib->ir('admin/site-publico.php', ['horario' => 'sem token']);
confere('salvar sem CSRF é recusado', $s === 419);
$t = $bib->csrf('admin/site-publico.php');
[$s, , $loc] = $bib->ir('admin/site-publico.php', ['csrf' => $t, 'horario' => 'Segunda a sexta, horário fictício de teste', 'telefone' => '(00) 0000-0000']);
[, $h] = $web->ir('biblioteca_php/sobre.php');
confere('texto salvo no painel aparece na página Sobre', $s === 303 && str_contains($h, 'horário fictício de teste') && str_contains($h, '(00) 0000-0000'));
confere('alteração registrada nas ações administrativas', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'site.editar'") === 1);
foreach (['admin/detalhes-livro.php?id=' . $livro['principe'], 'admin/capa-livro.php?id=' . $livro['principe'], 'admin/livros.php', 'admin/index.php'] as $p) {
    [$s, $h] = $bib->ir($p);
    confere("painel: /$p abre sem erros", $s === 200 && $semErros($h));
}
// Envio de capa: arquivo PHP disfarçado é recusado; PNG válido é aceito e aparece no site.
$enviar = function (string $nome, string $conteudo) use ($bib, $livro) {
    $t = $bib->csrf('admin/capa-livro.php?id=' . $livro['principe']);
    $lim = 'lim' . bin2hex(random_bytes(6));
    $corpo = "--$lim\r\nContent-Disposition: form-data; name=\"csrf\"\r\n\r\n$t\r\n--$lim\r\nContent-Disposition: form-data; name=\"id\"\r\n\r\n{$livro['principe']}\r\n"
        . "--$lim\r\nContent-Disposition: form-data; name=\"acao\"\r\n\r\nenviar\r\n--$lim\r\nContent-Disposition: form-data; name=\"capa\"; filename=\"$nome\"\r\nContent-Type: image/png\r\n\r\n$conteudo\r\n--$lim--\r\n";
    return $bib->ir('admin/capa-livro.php', null, $corpo, $lim);
};
[$s, $h] = $enviar('capa.png.php', "<?php echo 'invadido'; ?>");
confere('capa: arquivo que não é imagem é recusado', $s === 200 && str_contains($h, 'JPG, PNG ou WebP') && $num('SELECT COUNT(*) FROM livros WHERE id = ? AND capa IS NOT NULL', [$livro['principe']]) === 0);
if (function_exists('imagecreatetruecolor')) {
    $img = imagecreatetruecolor(120, 180);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 60, 140));
    ob_start();
    imagepng($img);
    $png = (string)ob_get_clean();
    [$s, , $loc] = $enviar('minha capa.png', $png);
    $capa = (string)db_query('SELECT capa FROM livros WHERE id = ?', [$livro['principe']])->fetchColumn();
    $temporarios[] = __DIR__ . '/../../' . $capa;
    [, $d] = $web->ir('biblioteca_php/livro.php?id=' . $livro['principe']);
    confere('capa: PNG válido salvo com nome aleatório em uploads/capas e exibido no site', $s === 303 && preg_match('#^uploads/capas/livro-\d+-[a-f0-9]{12}\.png$#', $capa) === 1 && is_file(__DIR__ . '/../../' . $capa) && str_contains($d, '/' . $capa));
} else {
    echo "  (pulado) envio de capa válido: extensão GD indisponível\n";
}

echo "\n8. Compatibilidade (PHP 8.2+, XAMPP sem intl) e responsividade\n";
$arquivos = array_merge(glob(__DIR__ . '/../../*.php'), glob(__DIR__ . '/../../biblioteca_php/*.php'), glob(__DIR__ . '/../../includes/*.php'), glob(__DIR__ . '/../../src/*/*.php'), glob(__DIR__ . '/../*.php'));
$erros = [];
foreach ($arquivos as $arq) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($arq) . ' 2>&1', $o, $c);
    if ($c !== 0) {
        $erros[] = basename($arq);
    }
}
confere('php -l em todas as páginas e classes', !$erros, implode(', ', $erros));
$publico = '';
foreach (array_merge([__DIR__ . '/../../index.php'], glob(__DIR__ . '/../../biblioteca_php/*.php'), glob(__DIR__ . '/../../includes/*.php'), [__DIR__ . '/../../src/Repositories/CatalogoRepository.php', __DIR__ . '/../../src/Services/CatalogoService.php']) as $arq) {
    $publico .= file_get_contents($arq);
}
confere('código público não depende de intl (Normalizer/Collator) nem de iconv', !preg_match('/\b(Normalizer|Collator|iconv)\b/', $publico));
confere('código público não consulta alunos, empréstimos, administradores nem planilhas', !preg_match('/\b(FROM|JOIN)\s+(alunos|emprestimos|administradores|registros_legados|acoes_administrativas)\b/i', $publico));
confere('código público não grava no banco', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $publico));
$css = (string)file_get_contents(__DIR__ . '/../../assets/css/style.css');
confere('CSS tem regras para celular, tablet e computador', str_contains($css, 'max-width: 620px') && str_contains($css, 'max-width: 860px') && str_contains($css, 'max-width: 1200px'));
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$temPlaywright = $node !== '' && trim((string)shell_exec(escapeshellarg($node) . " -e \"require('playwright'); console.log('ok')\" 2>/dev/null")) === 'ok';
if ($temPlaywright) {
    $script = sys_get_temp_dir() . "/$banco-responsivo.js";
    $temporarios[] = $script;
    $urls = json_encode(array_map(fn ($p) => $baseHttp . $p, ['', 'biblioteca_php/catalogo.php?q=volume', 'biblioteca_php/livro.php?id=' . $livro['principe'], 'biblioteca_php/sobre.php', 'biblioteca_php/regras.php']));
    file_put_contents($script, "const { chromium } = require('playwright');
(async () => {
  const urls = $urls; const b = await chromium.launch(); const problemas = [];
  for (const [w, h] of [[360, 740], [768, 1024], [1366, 768], [1920, 1080]]) {
    const p = await b.newPage({ viewport: { width: w, height: h } });
    for (const u of urls) {
      await p.goto(u);
      const r = await p.evaluate(() => ({ larg: document.documentElement.scrollWidth, vis: innerWidth,
        menu: getComputedStyle(document.querySelector(innerWidth <= 860 ? '.mobile-menu-button' : '.sidebar')).display !== 'none' }));
      if (r.larg > r.vis + 1 || !r.menu) problemas.push(w + 'px ' + u + ' ' + JSON.stringify(r));
    }
    if (w === 360) {
      await p.goto(urls[0]); await p.click('.mobile-menu-button'); await p.waitForTimeout(400);
      const aberto = await p.evaluate(() => { const s = document.querySelector('.sidebar').getBoundingClientRect(); return s.left >= 0 && !document.querySelector('.sidebar-backdrop').hidden; });
      await p.keyboard.press('Escape'); await p.waitForTimeout(400);
      const fechado = await p.evaluate(() => document.querySelector('.sidebar-backdrop').hidden);
      if (!aberto || !fechado) problemas.push('menu do celular não abre/fecha');
    }
    await p.close();
  }
  await b.close(); console.log(JSON.stringify(problemas));
})();");
    $saidaNode = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($script) . ' 2>&1'));
    confere('responsividade (360, 768, 1366 e 1920 px): sem rolagem horizontal e com menu acessível; menu do celular abre e fecha', $saidaNode === '[]', $saidaNode);
} else {
    echo "  (pulado) verificação visual de responsividade: Node + Playwright não instalados\n";
}
$logServidor = (string)@file_get_contents($log);
confere('servidor sem erros de PHP no log', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $logServidor), mb_substr($logServidor, 0, 300));

echo "\n$total verificações, $falhas falha(s).\n";
exit($falhas ? 1 : 0);
