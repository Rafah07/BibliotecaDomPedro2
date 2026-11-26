<?php
/**
 * Testes da gestão permanente do acervo (migração 190).
 *
 * Uso: php admin/tests/gestao-permanente-teste.php   (no XAMPP: C:\xampp\php\php.exe admin/tests/gestao-permanente-teste.php)
 *
 * Tudo acontece num banco temporário "bdp_teste_gestao_XXXX" (apagado no final), com dados FICTÍCIOS.
 * O banco do .env nunca é usado. Cenários:
 *   A. Obras e exemplares: correção bibliográfica aparece em todos os exemplares; códigos BDP, lotes e
 *      carimbagem inalterados; verificação; histórico; planilha BDP emitida preserva os dados da época.
 *   B. Concorrência: dois usuários na mesma obra (campos diferentes e mesmo campo), aviso de edição,
 *      expiração da marca de formulário abandonado.
 *   C. Cadastro em quantidade: 5 exemplares, códigos exclusivos, lote quase cheio, disputa pelas últimas
 *      vagas (processos paralelos), falha no meio (nada gravado), idempotência (mesma chave).
 *   D. Qualidade: duplicidades, edições diferentes, padronização, informação indisponível, unificação.
 *   E. Planilhas BDP: versão 01, correção, versão desatualizada, versão anterior preservada, versão 02.
 *   F. Confiabilidade: conexão perdida, repetição sem duplicar, migração parcialmente aplicada,
 *      backup gerado e restaurado num banco separado, permissões, registro de erros sem dados sensíveis.
 *   G. Gestão permanente: aquisição sem inventário, baixa, inventário periódico, devolução com dano,
 *      bloquear ou manter disponível, relatório de circulação, capacidade de prateleira.
 *   H. Páginas e permissões (servidor PHP embutido).
 */

use Biblioteca\Repositories\LivroRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\AquisicaoService;
use Biblioteca\Services\BackupService;
use Biblioteca\Services\ConflitoEdicaoException;
use Biblioteca\Services\DiagnosticoCompleto;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\LoteCapacidadeException;
use Biblioteca\Services\LoteService;
use Biblioteca\Services\ObraEdicaoService;
use Biblioteca\Services\OperacaoJaConcluidaException;
use Biblioteca\Services\OrganizacaoFisicaService;
use Biblioteca\Services\PlanilhaBdpVersaoService;
use Biblioteca\Services\QualidadeAcervoService;
use Biblioteca\Support\Migracoes;
use Biblioteca\Support\RegistroErros;
use Biblioteca\Support\RegrasCirculacao;

// ---------------------------------------------------------------
// Processo filho (disputa pelas últimas vagas / mesma chave ao mesmo tempo)
// ---------------------------------------------------------------
if (($argv[1] ?? '') === '--filho') {
    require __DIR__ . '/../../config/app.php';
    [, , $livroFilho, $adminFilho, $qtdFilho, $chaveFilho, $inicio] = $argv;
    $ex = new ExemplarService(db());
    while (microtime(true) < (float)$inicio) {
        usleep(300);
    }
    try {
        $r = $ex->cadastrarEmQuantidade((int)$livroFilho, ['situacao' => 'disponivel'], array_fill(0, (int)$qtdFilho, []), (int)$adminFilho, $chaveFilho !== '-' ? $chaveFilho : null);
        echo 'ok:' . implode(',', array_column($r, 'codigo'));
    } catch (OperacaoJaConcluidaException $e) {
        echo 'repetido:' . implode(',', array_column($e->resultado, 'codigo'));
    } catch (LoteCapacidadeException $e) {
        echo 'sem_vagas:' . $e->restam;
    } catch (Throwable $e) {
        echo 'erro:' . get_class($e) . ':' . $e->getMessage();
    }
    exit(0);
}

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('gestao');
$_SERVER['SCRIPT_NAME'] = '/admin/tests/gestao-permanente-teste.php';
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

$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['administrador' => 'adm@teste.br', 'bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Pessoa Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
[$adm, $bib, $aux] = [$contas['administrador'], $contas['bibliotecaria'], $contas['auxiliar']];

$ex = new ExemplarService($pdo);
$ex->definirRegrasPreparo(false, false);
$livros = new LivroRepository($pdo);
$lotes = new LoteService($pdo, $ex);
$versoes = new PlanilhaBdpVersaoService($pdo);
$qual = new QualidadeAcervoService($pdo);
$org = new OrganizacaoFisicaService($pdo, $ex);

$obra = fn (string $titulo, array $autores, array $extra = []) => $livros->criar(['titulo' => $titulo, 'autores' => $autores, 'categoria' => null] + $extra + ['status_validacao' => 'aguardando_conferencia']);
// Linha fictícia da planilha original (importação concluída) ligada a uma obra.
$pdo->prepare("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, usuario_id, status) VALUES ('acervo.xlsx', SHA2('gestao', 256), 'x.upload', 'xlsx', 1, ?, 'concluida')")->execute([$adm]);
$impId = (int)$pdo->lastInsertId();
$linhaPlanilha = function (int $livroId, int $linha, string $titulo, string $autor = '') use ($pdo, $impId): int {
    $pdo->prepare("INSERT INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, hash_linha, livro_resultante_id, resultado_importacao, processado_em) VALUES (?, ?, '{}', '{}', SHA1(?), ?, 'criado', NOW())")
        ->execute([$impId, $linha, 'l' . $linha, $livroId]);
    $item = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, titulo_original, autor_original, dados_originais) VALUES (?, ?, ?, 'acervo.xlsx', ?, ?, ?, '{}')")
        ->execute([$impId, $item, $livroId, $linha, $titulo, $autor]);
    return (int)$pdo->lastInsertId();
};
$exemplaresDe = fn (int $livroId) => db_query('SELECT id, codigo_individual, lote_id, lote_ordem, carimbo_status, localizacao_id FROM exemplares WHERE livro_id = ? ORDER BY id', [$livroId])->fetchAll();

// Lote gerenciado para os cadastros (capacidade 30, usuários adm, bib e aux).
$loteA = $lotes->criar(['capacidade' => 30, 'usuarios' => [$adm, $bib, $aux], 'descricao' => 'Lote de teste A'], $adm);
foreach ([$adm, $bib, $aux] as $u) {
    $lotes->escolherAtivo($loteA, $u);
}

// =====================================================================
echo "\nA. Obras e exemplares — dados bibliográficos compartilhados\n";
$pp = $obra('O Pequeno Principe', ['Antoine de Saint Exupery']);
$v0 = (int)$val('SELECT versao FROM livros WHERE id = ?', [$pp]);
$livros->atualizar($pp, ['titulo' => 'O Pequeno Principe', 'autores' => ['Antoine de Saint Exupery'], 'categoria' => null, 'status_validacao' => 'validado'], (string)$v0, $adm, true);
confere('A1. obra marcada como verificada', $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$pp]) !== null);
$criados = $ex->cadastrarEmQuantidade($pp, ['situacao' => 'disponivel'], [[], [], []], $adm, bin2hex(random_bytes(16)));
$codigosAntes = array_column($exemplaresDe($pp), 'codigo_individual');
confere('A2. três exemplares individuais com códigos próprios', count($criados) === 3 && count(array_unique($codigosAntes)) === 3);
$planilhaV1 = $versoes->emitir($loteA, $adm);
confere('E1. Planilha BDP do lote: Versão 01 emitida pelo servidor', $planilhaV1['nova'] && (int)$planilhaV1['versao']['versao'] === 1 && (int)$planilhaV1['versao']['total'] === 3);
$pdo->exec("UPDATE exemplares SET carimbo_status = 'carimbado', carimbado_em = NOW() WHERE livro_id = $pp");
$repetida = $versoes->emitir($loteA, $adm);
confere('E2. confirmar carimbagem e emitir de novo NÃO cria versão nova', !$repetida['nova'] && (int)$repetida['versao']['versao'] === 1);

$vAtual = (string)$val('SELECT versao FROM livros WHERE id = ?', [$pp]);
$alt = $livros->atualizar($pp, ['titulo' => 'O Pequeno Príncipe', 'autores' => ['Antoine de Saint-Exupéry'], 'categoria' => null, 'status_validacao' => 'validado'], $vAtual, $bib, false);
confere('A3. correção do título e do autor registrada', isset($alt['titulo'], $alt['autores']));
$repo = new Biblioteca\Repositories\ExemplarRepository($pdo);
$fichas = array_map(fn ($e) => $repo->buscar((int)$e['id']), $exemplaresDe($pp));
confere('A4. os três exemplares mostram o título e o autor corrigidos', count(array_filter($fichas, fn ($f) => $f['titulo'] === 'O Pequeno Príncipe' && $f['autores'] === 'Antoine de Saint-Exupéry')) === 3);
confere('A5. códigos BDP, lote, ordem e carimbagem inalterados', array_column($exemplaresDe($pp), 'codigo_individual') === $codigosAntes
    && count(array_filter($exemplaresDe($pp), fn ($e) => (int)$e['lote_id'] === $loteA && $e['carimbo_status'] === 'carimbado')) === 3);
confere('A6. alteração relevante sem nova verificação devolve a obra para "Dados não verificados"', $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$pp]) === null);
$livros->atualizar($pp, ['titulo' => 'O Pequeno Príncipe', 'autores' => ['Antoine de Saint-Exupéry'], 'categoria' => null, 'status_validacao' => 'validado'], (string)$val('SELECT versao FROM livros WHERE id = ?', [$pp]), $bib, true);
confere('A7. "Salvar e marcar como verificados" confirma de novo a verificação (sem alterar exemplares)', $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$pp]) !== null
    && array_column($exemplaresDe($pp), 'codigo_individual') === $codigosAntes);
$hist = (new Biblioteca\Services\ObraVerificacaoService($pdo))->historico($pp);
$temAutor = false;
foreach ($hist as $h) {
    if (($h['alteracoes']['autores']['antes'] ?? null) === 'Antoine de Saint Exupery' && ($h['alteracoes']['autores']['depois'] ?? null) === 'Antoine de Saint-Exupéry' && (int)$h['administrador_id'] === $bib) {
        $temAutor = true;
    }
}
confere('A8. histórico: campo, valor anterior, novo valor, responsável e data', $temAutor);
confere('A9. catálogo público lê os dados atuais da obra', str_contains((string)$val("SELECT titulo FROM livros WHERE id = ?", [$pp]), 'Príncipe'));

$sit = $versoes->situacao($loteA);
confere('E3. a Versão 01 ficou desatualizada e as mudanças são listadas', $sit['desatualizada'] && count(array_filter($sit['diferencas'], fn ($d) => str_contains($d['texto'], 'Saint-Exupéry'))) === 3);
$v1 = $versoes->versao((int)$planilhaV1['versao']['id']);
confere('E4. a Versão 01 continua mostrando os dados da emissão (histórico preservado)', $v1['linhas'][0]['autores'] === 'Antoine de Saint Exupery' && $v1['linhas'][0]['titulo'] === 'O Pequeno Principe');
$v2 = $versoes->emitir($loteA, $adm);
confere('E5. Versão 02 emitida com o motivo (diferenças) registrado', $v2['nova'] && (int)$v2['versao']['versao'] === 2 && str_contains((string)$v2['versao']['motivo'], 'Autor'));
confere('E6. códigos BDP iguais nas duas versões', array_column($v1['linhas'], 'codigo') === array_column($v2['versao']['linhas'], 'codigo'));
confere('E7. versões emitidas não podem ser alteradas', $recusa(fn () => $pdo->exec("UPDATE planilha_bdp_versoes SET total = 99 WHERE id = " . (int)$v1['id'])) !== null);

// =====================================================================
echo "\nB. Edição simultânea\n";
$dc = $obra('Dom Casmurro', ['Machado de Assis'], ['editora' => 'Ática', 'ano_publicacao' => '1999']);
$vA = (string)$val('SELECT versao FROM livros WHERE id = ?', [$dc]);
$vB = $vA; // os dois abriram o formulário na mesma versão
$livros->atualizar($dc, ['titulo' => 'Dom Casmurro', 'autores' => ['Machado de Assis'], 'editora' => 'Editora Ática', 'ano_publicacao' => '1999', 'categoria' => null, 'status_validacao' => 'aguardando_conferencia'], $vA, $adm);
$conflito = null;
try {
    $livros->atualizar($dc, ['titulo' => 'Dom Casmurro (romance)', 'autores' => ['Machado de Assis'], 'editora' => 'Ática', 'ano_publicacao' => '1999', 'categoria' => null, 'status_validacao' => 'aguardando_conferencia'], $vB, $bib);
} catch (ConflitoEdicaoException $e) {
    $conflito = $e;
}
confere('B1. quem salva a partir da versão antiga recebe o conflito (nada é sobrescrito)', $conflito !== null && $val('SELECT titulo FROM livros WHERE id = ?', [$dc]) === 'Dom Casmurro'
    && $val('SELECT editora FROM livros WHERE id = ?', [$dc]) === 'Editora Ática');
confere('B2. o conflito traz os dados salvos agora para a comparação', $conflito && $conflito->atual['editora'] === 'Editora Ática' && $conflito->versaoAtual === (int)$vA + 1);
$edic = new ObraEdicaoService($pdo);
$edic->entrar($dc, $adm, (int)$vA);
confere('B3. aviso: outro colaborador está editando (com o nome para quem pode ver)', str_contains((string)ObraEdicaoService::aviso($edic->outros($dc, $bib), true), 'Administrador'));
confere('B4. aviso genérico para quem não pode ver os nomes', ObraEdicaoService::aviso($edic->outros($dc, $bib), false) === 'Esta obra está sendo editada por outro colaborador.');
$pdo->exec("UPDATE obra_edicoes SET renovado_em = NOW() - INTERVAL 5 MINUTE WHERE livro_id = $dc");
confere('B5. formulário abandonado (navegador fechado) deixa de valer sozinho', $edic->outros($dc, $bib) === []);
$edic->entrar($dc, $adm, (int)$vA);
$edic->sair($dc, $adm);
confere('B6. ao salvar ou sair, a marca é retirada', $edic->outros($dc, $bib) === []);
$edic->entrar($dc, $adm, (int)$vA);
$r = $ex->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel'], [[]], $bib, bin2hex(random_bytes(16)));
confere('B7. a marca de edição não impede registrar exemplares da obra', count($r) === 1);
$edic->sair($dc, $adm);

// =====================================================================
echo "\nC. Cadastro de vários exemplares\n";
$seqAntes = (int)$val('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1');
$chave5 = bin2hex(random_bytes(16));
$cinco = $ex->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel', 'observacoes' => 'Doação 2026'],
    [['estado_conservacao' => 'ruim', 'observacoes' => 'capa rasgada'], [], [], ['estado_conservacao' => 'bom'], []], $aux, $chave5);
$cods = array_column($cinco, 'codigo');
confere('C1. cinco exemplares físicos individuais criados', count($cinco) === 5 && $num('SELECT COUNT(*) FROM exemplares WHERE id IN (' . implode(',', array_column($cinco, 'id')) . ')') === 5);
confere('C2. cinco códigos BDP exclusivos e em sequência, gerados pelo servidor', count(array_unique($cods)) === 5 && $cods[0] === sprintf('BDP-%06d', $seqAntes + 1) && end($cods) === sprintf('BDP-%06d', $seqAntes + 5));
$estados = db_query('SELECT estado_conservacao, observacoes FROM exemplares WHERE id IN (' . implode(',', array_column($cinco, 'id')) . ') ORDER BY id')->fetchAll();
confere('C3. nenhuma cópia considerada "Bom" automaticamente; diferenças individuais gravadas', $estados[0]['estado_conservacao'] === 'ruim' && $estados[1]['estado_conservacao'] === 'nao_avaliado'
    && $estados[3]['estado_conservacao'] === 'bom' && str_contains((string)$estados[0]['observacoes'], 'capa rasgada') && str_contains((string)$estados[1]['observacoes'], 'Doação'));
confere('C4. todos entraram no lote (planilha BDP), em ordem', count(array_unique(array_column($cinco, 'lote_ordem'))) === 5 && (int)$cinco[0]['lote_id'] === $loteA);
$repet = null;
try {
    $ex->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel'], array_fill(0, 5, []), $aux, $chave5);
} catch (OperacaoJaConcluidaException $e) {
    $repet = $e;
}
confere('C5. reenviar o mesmo formulário não cria cópias: devolve os mesmos códigos', $repet && array_column($repet->resultado, 'codigo') === $cods && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$dc]) === 6);

// Lote quase cheio: capacidade 30, faltam 2 vagas.
$total = (int)$val('SELECT total FROM cadastro_lotes WHERE id = ?', [$loteA]);
$encher = 28 - $total;
if ($encher > 0) {
    $ex->cadastrarEmQuantidade($pp, ['situacao' => 'disponivel'], array_fill(0, $encher, []), $adm, bin2hex(random_bytes(16)));
}
$seq28 = (int)$val('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1');
$exAntes = $num('SELECT COUNT(*) FROM exemplares');
$semVagas = null;
try {
    $ex->cadastrarEmQuantidade($pp, ['situacao' => 'disponivel'], array_fill(0, 5, []), $adm, bin2hex(random_bytes(16)));
} catch (LoteCapacidadeException $e) {
    $semVagas = $e;
}
confere('C6. lote com 28 de 30: pedir 5 informa que restam só 2 vagas', $semVagas && $semVagas->restam === 2);
confere('C7. nada gravado: exemplares e sequência BDP inalterados', $num('SELECT COUNT(*) FROM exemplares') === $exAntes && (int)$val('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1') === $seq28);

// Duas pessoas disputando as 2 últimas vagas ao mesmo tempo (processos separados), 2 cópias cada.
$inicio = microtime(true) + 1.5;
$procs = [];
foreach ([$bib, $aux] as $u) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, '--filho', (string)$pp, (string)$u, '2', bin2hex(random_bytes(16)), (string)$inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$u], null, array_merge(getenv(), $env));
}
$saidas = [];
foreach ([$bib, $aux] as $i => $u) {
    $saidas[] = trim(stream_get_contents($pipes[$u][1]) . stream_get_contents($pipes[$u][2]));
    proc_close($procs[$i]);
}
$ok = count(array_filter($saidas, fn ($s) => str_starts_with($s, 'ok:')));
$sem = count(array_filter($saidas, fn ($s) => str_starts_with($s, 'sem_vagas:')));
confere('C8. disputa pelas últimas vagas: um registra, o outro recebe "sem vagas"', $ok === 1 && $sem === 1, implode(' | ', $saidas));
confere('C9. capacidade nunca ultrapassada; lote fechado automaticamente ao encher', (int)$val('SELECT total FROM cadastro_lotes WHERE id = ?', [$loteA]) === 30
    && $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$loteA]) === 30 && $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$loteA]) === 'fechado');

// Falha no meio da operação (3ª cópia recusada pelo banco): nada fica gravado.
$loteB = $lotes->criar(['capacidade' => 50, 'usuarios' => [$adm, $bib, $aux], 'descricao' => 'Lote de teste B'], $adm);
foreach ([$adm, $bib, $aux] as $u) {
    $lotes->escolherAtivo($loteB, $u);
}
$pdo->exec("CREATE TRIGGER teste_falha_cadastro BEFORE INSERT ON exemplares FOR EACH ROW BEGIN IF NEW.observacoes = 'FALHAR AQUI' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falha simulada'; END IF; END");
$seqF = (int)$val('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1');
$exF = $num('SELECT COUNT(*) FROM exemplares');
$chaveF = bin2hex(random_bytes(16));
$erroF = $recusa(fn () => $ex->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel'], [[], [], ['observacoes' => 'FALHAR AQUI'], []], $adm, $chaveF));
$pdo->exec('DROP TRIGGER teste_falha_cadastro');
confere('C10. falha na 3ª cópia: nenhuma cópia gravada (operação atômica)', $erroF !== null && $num('SELECT COUNT(*) FROM exemplares') === $exF);
confere('C11. sequência BDP, lote e chave da operação voltaram ao estado anterior', (int)$val('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1') === $seqF
    && (int)$val('SELECT total FROM cadastro_lotes WHERE id = ?', [$loteB]) === 0 && $ex->resultadoAnterior($chaveF) === null);
$depois = $ex->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel'], [[], [], [], []], $adm, $chaveF);
confere('C12. tentar de novo com a mesma chave grava as 4 cópias uma única vez', count($depois) === 4 && $num('SELECT COUNT(*) FROM exemplares') === $exF + 4);

// Mesma chave enviada duas vezes AO MESMO TEMPO (clique duplo em duas abas).
$chaveDupla = bin2hex(random_bytes(16));
$inicio = microtime(true) + 1.5;
$procs = [];
$pipes = [];
foreach ([0, 1] as $i) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, '--filho', (string)$dc, (string)$bib, '3', $chaveDupla, (string)$inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, array_merge(getenv(), $env));
}
$saidas = [];
foreach ([0, 1] as $i) {
    $saidas[] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]));
    proc_close($procs[$i]);
}
$criou = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'ok:')));
$rep = array_values(array_filter($saidas, fn ($s) => str_starts_with($s, 'repetido:')));
confere('C13. mesma chave ao mesmo tempo: só um envio cria; o outro recebe os mesmos códigos', count($criou) === 1 && count($rep) === 1 && substr($criou[0], 3) === substr($rep[0], 9), implode(' | ', $saidas));

// =====================================================================
echo "\nD. Central de Qualidade\n";
$dcPlan = $obra('DOM CASMURRO', ['ASSIS, Machado de'], ['editora' => 'ática', 'ano_publicacao' => '1999']);
$pdo->exec("UPDATE livros SET origem_cadastro = 'planilha' WHERE id = $dcPlan");
$isbn1 = '9788508040186';
$isbn2 = '9788535914849';
$ed1 = $obra('Memórias Póstumas de Brás Cubas', ['Machado de Assis'], ['isbn' => $isbn1, 'edicao' => '1ª edição']);
$ed2 = $obra('Memorias Postumas de Bras Cubas', ['Machado de Assis'], ['isbn' => $isbn2, 'edicao' => '3ª edição']);
$semIsbn = $obra('Livro Antigo Sem ISBN', ['Autor Antigo']);
$pdo->exec("UPDATE livros SET isbn_original = '85-1234' WHERE id = $semIsbn");
$t = $qual->analisar($adm);
$dup = $qual->pendencia('duplicidade:' . min($dc, $dcPlan) . ':' . max($dc, $dcPlan));
confere('D1. possível duplicidade entre a obra manual e a importada', $dup && $dup['situacao'] === 'aberta' && str_contains($dup['descricao'], 'manual pode corresponder'));
$dupEd = $qual->pendencia('duplicidade:' . min($ed1, $ed2) . ':' . max($ed1, $ed2));
confere('D2. edições diferentes aparecem só como informativo ("podem ser edições diferentes")', $dupEd && $dupEd['gravidade'] === 'informativo' && str_contains($dupEd['descricao'], 'edições diferentes'));
confere('D3. unificar edições diferentes é recusado sem confirmação explícita', ($m = $recusa(fn () => $qual->unificar($ed1, $ed2, 'teste', $adm, true, false))) !== null && str_contains($m, 'edições diferentes')
    && $val('SELECT arquivado_em FROM livros WHERE id = ?', [$ed2]) === null);
$grupoAutor = array_values(array_filter($qual->gruposVariantes('autor'), fn ($g) => in_array('ASSIS, Machado de', array_column($g, 'nome'), true)));
confere('D4. padronização sugere "ASSIS, Machado de" = "Machado de Assis"', count($grupoAutor) === 1 && in_array('Machado de Assis', array_column($grupoAutor[0], 'nome'), true));
$grupoEd = array_values(array_filter($qual->gruposVariantes('editora'), fn ($g) => count(array_intersect(['Ática', 'ática', 'Editora Ática'], array_column($g, 'nome'))) >= 2));
confere('D5. editoras "Editora Ática" / "ática" identificadas como a mesma', count($grupoEd) === 1);
confere('D6. nomes só parecidos NÃO são sugeridos (pessoas diferentes)', !array_filter($qual->gruposVariantes('autor'), fn ($g) => in_array('Antoine de Saint-Exupéry', array_column($g, 'nome'), true)));
$pdo->exec("UPDATE livros SET dados_verificados_em = NOW() WHERE id = $dcPlan");
$pad = $qual->padronizarAutores(array_column($grupoAutor[0], 'id'), 'Machado de Assis', $bib);
confere('D7. padronização aplicada só depois da confirmação: obras usam um único autor', $num("SELECT COUNT(DISTINCT la.autor_id) FROM livro_autores la WHERE la.livro_id IN ($dc, $dcPlan)") === 1 && $pad['obras'] >= 2);
confere('D8. padronização mantém a verificação e fica no histórico da obra', $val('SELECT dados_verificados_em FROM livros WHERE id = ?', [$dcPlan]) !== null
    && $num("SELECT COUNT(*) FROM livro_verificacoes WHERE livro_id = ? AND origem = 'padronizacao'", [$dcPlan]) === 1);
$qual->padronizarEditoras(array_column($grupoEd[0], 'nome'), 'Ática', $bib);
confere('D9. editora padronizada em todas as obras afetadas', $num("SELECT COUNT(*) FROM livros WHERE id IN ($dc, $dcPlan) AND editora COLLATE utf8mb4_bin = 'Ática'") === 2);
$chIsbn = 'isbn_invalido:' . $semIsbn;
confere('D10. ISBN inválido/incompleto é "Atenção", não crítico', ($qual->pendencia($chIsbn)['gravidade'] ?? '') === 'atencao');
$qual->decidir($chIsbn, 'indisponivel', 'Livro de 1958 sem ISBN impresso', $bib);
$qual->analisar($adm);
confere('D11. informação confirmada como indisponível não volta como pendência aberta', $qual->pendencia($chIsbn)['situacao'] === 'justificada');
$pdo->exec("UPDATE livros SET isbn_original = '85-9999' WHERE id = $semIsbn");
$qual->analisar($adm);
confere('D12. registro alterado depois: a pendência volta a ser avaliada', $qual->pendencia($chIsbn)['situacao'] === 'aberta');
$qual->decidir('duplicidade:' . min($ed1, $ed2) . ':' . max($ed1, $ed2), 'nao_duplicidade', 'Edições diferentes', $bib);
confere('D13. "não é duplicidade" registrada (edições preservadas)', $qual->pendencia('duplicidade:' . min($ed1, $ed2) . ':' . max($ed1, $ed2))['situacao'] === 'justificada'
    && $num("SELECT COUNT(*) FROM livros WHERE id IN ($ed1, $ed2) AND arquivado_em IS NULL") === 2);
// Unificação de cadastros realmente equivalentes.
$exDcPlan = $ex->cadastrarEmQuantidade($dcPlan, ['situacao' => 'disponivel'], [[]], $adm, bin2hex(random_bytes(16)));
$reg = $linhaPlanilha($dcPlan, 125, 'DOM CASMURRO', 'ASSIS, Machado de');
$exTotalAntes = $num('SELECT COUNT(*) FROM exemplares');
$codigoMovido = $exDcPlan[0]['codigo'];
$u = $qual->unificar($dc, $dcPlan, 'Mesmo livro cadastrado duas vezes', $adm, true, false);
confere('D14. unificação: exemplares preservados com os mesmos códigos BDP', $u['exemplares'] === [$codigoMovido] && (int)$val('SELECT livro_id FROM exemplares WHERE codigo_individual = ?', [$codigoMovido]) === $dc
    && $num('SELECT COUNT(*) FROM exemplares') === $exTotalAntes);
confere('D15. cadastro unificado arquivado, apontando para a obra mantida', (int)$val('SELECT unificado_em_id FROM livros WHERE id = ?', [$dcPlan]) === $dc && $val('SELECT arquivado_em FROM livros WHERE id = ?', [$dcPlan]) !== null);
confere('D16. linha da planilha relacionada à obra mantida, com a original guardada', (int)$val('SELECT livro_id FROM registros_legados WHERE id = ?', [$reg]) === $dc && (int)$val('SELECT livro_original_id FROM registros_legados WHERE id = ?', [$reg]) === $dcPlan);
confere('D17. histórico completo da unificação (imutável)', $num('SELECT COUNT(*) FROM obra_unificacoes WHERE livro_mantido_id = ? AND livro_unificado_id = ?', [$dc, $dcPlan]) === 1
    && $recusa(fn () => $pdo->exec('DELETE FROM obra_unificacoes')) !== null);
$qual->analisar($adm);
confere('D18. a duplicidade unificada aparece como resolvida', ($qual->pendencia('duplicidade:' . min($dc, $dcPlan) . ':' . max($dc, $dcPlan))['situacao'] ?? '') === 'resolvida');
confere('D19. pendências normais de trabalho são informativas (aguardando carimbo por lote)', ($qual->pendencia('aguardando_carimbo:' . $loteB)['gravidade'] ?? '') === 'informativo');
$ind = $qual->indicadores();
confere('D20. painel com indicadores calculados da tabela de pendências', isset($ind['criticas'], $ind['duplicidades'], $ind['resolvidas']) && $ind['resolvidas'] >= 1);

// =====================================================================
echo "\nF. Confiabilidade\n";
// Conexão perdida no meio do cadastro: o resultado é verificado pela chave antes de tentar de novo.
$c = app_config();
$pdoPerdido = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $banco), $c['username'], (string)$c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$idConexao = (int)$pdoPerdido->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('KILL ' . $idConexao);
$chaveP = bin2hex(random_bytes(16));
$erroPerdido = null;
try {
    (new ExemplarService($pdoPerdido))->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel'], [[], []], $adm, $chaveP);
} catch (PDOException $e) {
    $erroPerdido = $e;
}
confere('F1. conexão perdida é reconhecida ("server has gone away")', $erroPerdido && Migracoes::conexaoPerdida($erroPerdido), $erroPerdido ? $erroPerdido->getMessage() : 'sem erro');
confere('F2. mensagem ao funcionário: conexão interrompida, sem SQL nem caminhos', $erroPerdido && str_contains(RegistroErros::mensagemUsuario($erroPerdido, 'ERR-TESTE', true, true), 'conexão com o banco foi interrompida')
    && !str_contains(RegistroErros::mensagemUsuario($erroPerdido, 'ERR-TESTE', true, true), 'SQLSTATE'));
confere('F3. a operação interrompida não foi concluída (nada gravado com essa chave)', $ex->resultadoAnterior($chaveP) === null);
$retry = $ex->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel'], [[], []], $adm, $chaveP);
$retry2 = null;
try {
    $ex->cadastrarEmQuantidade($dc, ['situacao' => 'disponivel'], [[], []], $adm, $chaveP);
} catch (OperacaoJaConcluidaException $e) {
    $retry2 = $e;
}
confere('F4. nova tentativa grava uma vez; repetir a requisição não duplica', count($retry) === 2 && $retry2 && array_column($retry2->resultado, 'codigo') === array_column($retry, 'codigo'));
// Registro técnico protegido.
$idErro = RegistroErros::registrar(new PDOException("SQLSTATE[HY000] [1045] Access denied password=segredo123 near 'SELECT * FROM livros WHERE x'"), 'teste de registro', ['operacao_chave' => $chaveP]);
$linhaLog = array_values(array_filter(RegistroErros::recentes(50), fn ($l) => ($l['id'] ?? '') === $idErro))[0] ?? [];
confere('F5. erro registrado com código, operação e chave, sem senha nem consulta SQL', $linhaLog && $linhaLog['operacao_chave'] === $chaveP && !str_contains(json_encode($linhaLog), 'segredo123') && !str_contains(json_encode($linhaLog), 'SELECT *'));
confere('F6. pasta de registros protegida contra acesso público', is_file(RegistroErros::pasta() . '/.htaccess') || is_file(APP_RAIZ . '/storage/.htaccess'));
// Migrações: pendente e parcialmente aplicada.
$diag = new DiagnosticoCompleto($pdo, APP_RAIZ, sys_get_temp_dir());
$itensMig = $diag->verificar()['Migrações'];
confere('F7. diagnóstico: nenhuma migração pendente no banco de teste', str_contains($itensMig[0]['detalhe'], 'Nenhuma') && $itensMig[0]['situacao'] === 'ok');
$pdo->exec("DELETE FROM schema_migracoes WHERE arquivo = '190_gestao_permanente_acervo.sql'");
$pdo->exec('DROP TABLE qualidade_decisoes');
$conteudo190 = (string)file_get_contents(APP_RAIZ . '/database/migrations/190_gestao_permanente_acervo.sql');
confere('F8. migração interrompida é detectada como parcialmente aplicada', Migracoes::situacaoPendente($pdo, $conteudo190) === 'parcial');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_RAIZ . '/database/migrar.php') . ' --verificar 2>&1', $saidaVer, $codVer);
confere('F9. migrar.php --verificar informa a migração parcial sem mudar nada', $codVer === 0 && str_contains(implode("\n", $saidaVer), 'PARCIALMENTE') && !Migracoes::tabelaExiste($pdo, 'qualidade_decisoes'));
$exAntesMig = $num('SELECT COUNT(*) FROM exemplares');
$codsAntesMig = db_query('SELECT GROUP_CONCAT(codigo_individual ORDER BY id) FROM exemplares')->fetchColumn();
$p = proc_open([PHP_BINARY, APP_RAIZ . '/database/migrar.php', '--sem-backup'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp2, null, array_merge(getenv(), $env));
$saidaMig = stream_get_contents($pp2[1]) . stream_get_contents($pp2[2]);
$codMig = proc_close($p);
confere('F10. retomada confere a estrutura real, recria só o que falta e registra a migração', $codMig === 0 && Migracoes::tabelaExiste($pdo, 'qualidade_decisoes')
    && $num("SELECT COUNT(*) FROM schema_migracoes WHERE arquivo = '190_gestao_permanente_acervo.sql'") === 1, $saidaMig);
confere('F11. retomada não altera dados nem códigos BDP', $num('SELECT COUNT(*) FROM exemplares') === $exAntesMig && db_query('SELECT GROUP_CONCAT(codigo_individual ORDER BY id) FROM exemplares')->fetchColumn() === $codsAntesMig);
confere('F12. cada tentativa e comando ficam no histórico das migrações', $num("SELECT COUNT(*) FROM schema_migracoes_execucoes WHERE arquivo = '190_gestao_permanente_acervo.sql' AND situacao = 'concluida'") >= 1
    && $num('SELECT COUNT(*) FROM schema_migracoes_passos') > 0);
// Permissões do usuário do banco.
$priv = Migracoes::privilegios($pdo);
confere('F13. permissões do usuário do banco conferidas', $priv['verificado'] === false || is_array($priv['faltando']));
// F14/F15 criam um usuário temporário no servidor (CREATE USER/GRANT). No XAMPP desta escola, o GRANT já
// derrubou o MariaDB (tabelas de sistema danificadas), por isso só rodam quando pedidos explicitamente:
//   set TESTE_USUARIO_LIMITADO=1 && C:\xampp\php\php.exe admin/tests/gestao-permanente-teste.php
$usuarioLimitado = 'bdp_lim_' . bin2hex(random_bytes(3));
if (getenv('TESTE_USUARIO_LIMITADO') === '1') {
    register_shutdown_function(function () use ($raiz, $usuarioLimitado) {
        try {
            $raiz->exec("DROP USER IF EXISTS '$usuarioLimitado'@'localhost'");
            $raiz->exec("DROP USER IF EXISTS '$usuarioLimitado'@'127.0.0.1'");
        } catch (Throwable) {
        }
    });
}
try {
    if (getenv('TESTE_USUARIO_LIMITADO') !== '1') {
        throw new PDOException('pulado');
    }
    $senhaLim = bin2hex(random_bytes(8));
    foreach (['localhost', '127.0.0.1'] as $h) {
        $raiz->exec("CREATE USER '$usuarioLimitado'@'$h' IDENTIFIED BY '$senhaLim'");
        $raiz->exec("GRANT SELECT ON `$banco`.* TO '$usuarioLimitado'@'$h'");
    }
    $pdoLim = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $banco), $usuarioLimitado, $senhaLim, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $privLim = Migracoes::privilegios($pdoLim);
    confere('F14. falta de permissões é identificada (usuário só com SELECT)', $privLim['verificado'] && in_array('ALTER', $privLim['faltando'], true) && in_array('TRIGGER', $privLim['faltando'], true));
    $erroLim = null;
    try {
        $pdoLim->exec("INSERT INTO autores (nome) VALUES ('X')");
    } catch (PDOException $e) {
        $erroLim = $e;
    }
    confere('F15. erro de permissão vira mensagem clara para o funcionário', $erroLim && str_contains(RegistroErros::mensagemUsuario($erroLim, 'ERR-X'), 'permissão'));
} catch (PDOException $e) {
    echo $e->getMessage() === 'pulado'
        ? "  (F14/F15 não executados: criam um usuário no servidor; use TESTE_USUARIO_LIMITADO=1 só onde o GRANT é seguro)\n"
        : "  (F14/F15 não executados: sem permissão para criar um usuário de teste no servidor)\n";
}
// Backup gerado, conferido e restaurado num banco separado.
$pastaBk = sys_get_temp_dir() . '/bdp-teste-backups-' . bin2hex(random_bytes(3));
$bk = new BackupService($pdo);
$copia = $bk->salvarNoServidor($pastaBk, $adm, 'servidor', 'teste');
$GLOBALS['teste_temporarios'][] = $copia['caminho'];
confere('F16. cópia de segurança gerada e conferida (arquivo completo, SHA-256)', BackupService::terminaCompleto($copia['caminho']) && $copia['tamanho'] > 0 && $num('SELECT verificado FROM backups WHERE id = ?', [$copia['id']]) === 1);
$conteudoBk = (string)file_get_contents($copia['caminho']);
confere('F17. a cópia não contém as credenciais do .env', ($c['password'] === '' || !str_contains($conteudoBk, (string)$c['password'])) && !str_contains($conteudoBk, 'DB_PASSWORD'));
$teste = $bk->testarRestauracao((int)$copia['id'], $pastaBk, ['host' => $c['host'], 'port' => $c['port'], 'database' => $banco, 'username' => $c['username'], 'password' => (string)$c['password']]);
confere('F18. teste de recuperação num banco separado: tabelas e linhas conferem', $teste['ok'] === true, $teste['detalhe']);
confere('F19. o banco temporário do teste de recuperação foi apagado', $num("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?", [$banco . '_verifica_%']) === 0);
confere('F20. resultado do teste de recuperação registrado na cópia', $num('SELECT restauracao_ok FROM backups WHERE id = ?', [$copia['id']]) === 1);
@unlink($copia['caminho']);
@unlink($pastaBk . '/.htaccess');
@unlink($pastaBk . '/index.html');
@rmdir($pastaBk);

// =====================================================================
echo "\nG. Gestão permanente\n";
$aquis = new AquisicaoService($pdo);
$aqId = $aquis->registrar(['tipo' => 'doacao', 'origem' => 'Doação fictícia da família Teste', 'data_recebimento' => date('Y-m-d'), 'quantidade_recebida' => '2', 'documento' => 'Termo 12/2026'], $bib);
$nova = $obra('Livro Novo Recebido', ['Autora Nova']);
confere('G1. aquisição registrada sem inventário em andamento', $aqId > 0 && $num("SELECT COUNT(*) FROM inventarios WHERE status IN ('em_andamento', 'pausado')") === 0);
$exAq = $ex->cadastrarEmQuantidade($nova, ['situacao' => 'disponivel'], [[], []], $bib, bin2hex(random_bytes(16)), ['aquisicao_id' => $aqId, 'data_entrada' => date('Y-m-d')]);
confere('G2. exemplares da aquisição com código BDP, entrada e origem registradas', count($exAq) === 2 && $num('SELECT COUNT(*) FROM exemplares WHERE aquisicao_id = ? AND data_entrada IS NOT NULL', [$aqId]) === 2
    && (int)$aquis->buscar($aqId)['registrados'] === 2);
$baixado = (int)$exAq[1]['id'];
$codBaixado = $exAq[1]['codigo'];
$ex->baixar($baixado, $bib, 'Dano irreparável: molhado', 'dano', 'Autorizado pela direção');
$b = db_query('SELECT situacao, codigo_individual, baixa_tipo, baixado_por FROM exemplares WHERE id = ?', [$baixado])->fetch();
confere('G3. baixa: sai do acervo ativo, com motivo e responsável; código preservado', $b['situacao'] === 'baixado' && $b['codigo_individual'] === $codBaixado && $b['baixa_tipo'] === 'dano' && (int)$b['baixado_por'] === $bib);
confere('G4. o cadastro baixado não é excluído (exclusão recusada pelo banco)', $recusa(fn () => $pdo->exec("DELETE FROM exemplares WHERE id = $baixado")) !== null);
$depoisBaixa = $ex->cadastrarEmQuantidade($nova, ['situacao' => 'disponivel'], [[]], $bib, bin2hex(random_bytes(16)));
confere('G5. o código de um exemplar baixado não é reaproveitado', $depoisBaixa[0]['codigo'] > $codBaixado);
// Empréstimo e devolução com dano.
$pdo->exec("INSERT INTO alunos (nome, matricula) VALUES ('Aluno Fictício Um', 'TESTE-0001')");
$aluno = (int)$pdo->lastInsertId();
$emp = new EmprestimoService($pdo, new RegrasCirculacao(fuso: 'America/Sao_Paulo'));
$exEmp = (int)$exAq[0]['id'];
$pdo->exec("UPDATE exemplares SET situacao = 'disponivel', liberacao_pendente = 0, carimbo_status = 'carimbado' WHERE id = $exEmp");
$m = $emp->registrar($aluno, $exEmp, $emp->prevista(), $bib);
confere('G6. baixa comum recusada com empréstimo ativo', ($rb = $recusa(fn () => $ex->baixar($exEmp, $bib, 'teste', 'descarte'))) !== null && str_contains($rb, 'empréstimo ativo'));
$d = $emp->devolver($m['id'], $bib, null, false, '', ['estado' => 'ruim', 'ocorrencia' => 'capa_danificada', 'descricao' => 'capa solta', 'bloquear' => '1']);
confere('G7. devolução com dano: conservação registrada e exemplar indisponível por decisão', $d['situacao_exemplar'] === 'danificado' && $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$exEmp]) === 'ruim'
    && $val('SELECT conservacao_devolucao FROM emprestimos WHERE id = ?', [$m['id']]) === 'ruim' && $num("SELECT COUNT(*) FROM exemplar_ocorrencias WHERE exemplar_id = ? AND status = 'aberta' AND bloqueia_emprestimo = 1", [$exEmp]) === 1);
$exLeve = (int)$depoisBaixa[0]['id'];
$pdo->exec("UPDATE exemplares SET situacao = 'disponivel', liberacao_pendente = 0, carimbo_status = 'carimbado' WHERE id = $exLeve");
$m2 = $emp->registrar($aluno, $exLeve, $emp->prevista(), $bib);
$d2 = $emp->devolver($m2['id'], $bib, null, false, '', ['estado' => 'regular', 'ocorrencia' => 'outro', 'descricao' => 'pequeno rasgo na orelha', 'bloquear' => '0']);
confere('G8. dano leve: ocorrência registrada e exemplar continua disponível', $d2['situacao_exemplar'] === 'disponivel' && $num("SELECT COUNT(*) FROM exemplar_ocorrencias WHERE exemplar_id = ? AND bloqueia_emprestimo = 0", [$exLeve]) === 1);
// Capacidade estimada das prateleiras.
$estante = $org->criarEstante(['identificacao' => 'T1', 'nome' => 'Estante de teste', 'prateleiras' => 0], $adm);
$prats = $org->adicionarPrateleiras($estante, 1, '', $adm);
$prat = (int)$prats[0];
$org->definirCapacidade($prat, 2, $adm);
$ex->transferir($exLeve, $prat, $bib);
$aviso = $org->avisoCapacidade($prat, 2);
confere('G9. capacidade estimada: aviso ao ultrapassar, sem bloquear', $aviso !== null && str_contains($aviso, 'estimativa'));
$ex->transferir((int)$exAq[0]['id'], $prat, $bib);
$ex->transferir((int)$retry[0]['id'], $prat, $bib);
confere('G10. a alocação além da estimativa continua permitida', $num('SELECT COUNT(*) FROM exemplares WHERE localizacao_id = ?', [$prat]) === 3);
confere('G11. ocupação calculada como estimativa (possivelmente lotada)', OrganizacaoFisicaService::ocupacao(3, 2)['estado'] === 'lotada' && OrganizacaoFisicaService::ocupacao(0, 50)['estado'] === 'vazia');
// Inventário periódico.
$linhaPlanilha($obra('Obra Só Na Planilha', ['Autor X']), 900, 'Obra só na planilha');
$seqInv = (int)$val('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1');
$inv = new InventarioService($pdo, $ex, new InventarioRepository($pdo));
$invId = $inv->criar(['titulo' => 'Inventário periódico 2027', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => (string)$adm, 'tipo' => 'periodico'], $adm);
$previstos = $inv->iniciar($invId, $adm);
confere('G12. inventário periódico usa só os exemplares ativos já cadastrados', $previstos === $num("SELECT COUNT(*) FROM exemplares e JOIN livros l ON l.id = e.livro_id AND l.arquivado_em IS NULL WHERE e.situacao NOT IN ('baixado', 'extraviado')")
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id IS NULL', [$invId]) === 0);
$alvo = (int)$val("SELECT id FROM exemplares WHERE situacao = 'disponivel' AND localizacao_id IS NOT NULL LIMIT 1");
$inv->conferir($invId, $alvo, ['estado_conservacao' => 'bom'], $aux);
confere('G13. exemplar confirmado pelo BDP, sem gerar códigos novos', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ? AND conferido_em IS NOT NULL', [$invId, $alvo]) === 1
    && (int)$val('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1') === $seqInv);
confere('G14. resultados de inventários anteriores preservados (um item por inventário)', $num('SELECT COUNT(DISTINCT inventario_id) FROM inventario_itens') >= 1 && $num('SELECT COUNT(*) FROM inventarios WHERE tipo = ?', ['periodico']) === 1);

// =====================================================================
echo "\nH. Páginas e permissões\n";
$base = teste_servidor($env);
$nav = new TesteNavegador($base);
confere('administrador entra no painel', $nav->entrar('adm@teste.br', $senha) === 200);
$tok = function (TesteNavegador $n, string $pagina): string {
    [, $html] = $n->ir($pagina);
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
};
$exPag = (int)$val('SELECT id FROM exemplares ORDER BY id LIMIT 1');
foreach (['admin/editar-livro.php?id=' . $pp, 'admin/detalhes-livro.php?id=' . $pp, 'admin/cadastrar-exemplar.php?livro=' . $pp . '&modo=varios', 'admin/qualidade.php', 'admin/qualidade.php?aba=padronizacao&tipo=autor',
    'admin/qualidade.php?aba=comparar&a=' . $ed1 . '&b=' . $ed2, 'admin/planilha-bdp-versoes.php?lote=' . $loteA, 'admin/planilha-bdp-versoes.php?versao=' . (int)$v1['id'] . '&imprimir=1',
    'admin/planilha-bdp-versoes.php?versao=' . (int)$v1['id'] . '&comparar=atual', 'admin/diagnostico.php', 'admin/manutencao.php', 'admin/aquisicoes.php', 'admin/aquisicoes.php?id=' . $aqId, 'admin/baixas.php',
    'admin/relatorio-circulacao.php', 'admin/detalhes-exemplar.php?id=' . $exPag, 'admin/organizacao-fisica.php', 'admin/organizacao-fisica.php?estante=' . $estante, 'admin/inventario-editar.php',
    'admin/inventario-detalhes.php?id=' . $invId, 'admin/lote.php?id=' . $loteA, 'admin/index.php'] as $pg) {
    [$s, $html] = $nav->ir($pg);
    // Diagnóstico e manutenção mostram de propósito os comandos do XAMPP (C:\xampp\php\php.exe): só erros do PHP/SQL contam.
    $limpa = in_array(strtok($pg, '?'), ['admin/diagnostico.php', 'admin/manutencao.php'], true)
        ? !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|SQLSTATE|PDOException)/', $html) : teste_sem_erro_tecnico($html);
    confere('H. página ' . $pg . ' abre sem erro técnico', $s === 200 && $limpa, 'status ' . $s . ' ' . substr(strip_tags($html), 0, 300));
}
[, $html] = $nav->ir('admin/editar-livro.php?id=' . $pp);
confere('H1. editar obra informa quantos exemplares recebem a correção', str_contains($html, 'As informações bibliográficas atualizadas serão exibidas automaticamente'));
[, $html] = $nav->ir('admin/planilha-bdp-versoes.php?versao=' . (int)$v1['id'] . '&imprimir=1');
confere('H2. reimpressão de versão antiga avisa que está desatualizada e mostra lote/versão/data/quantidade', str_contains($html, 'desatualizada') && str_contains($html, 'Versão 01') && str_contains($html, 'exemplar(es)') && str_contains($html, 'counter(page)'));
[, $html] = $nav->ir('admin/relatorio-circulacao.php');
confere('H3. relatório de circulação usa os empréstimos reais e não mostra nomes de alunos', str_contains($html, 'Empréstimos no período') && !str_contains($html, 'Aluno Fictício Um'));

// Conflito pela página: dois usuários, campos diferentes e o mesmo campo.
$nav2 = new TesteNavegador($base);
$nav2->entrar('bib@teste.br', $senha);
[, $form1] = $nav->ir('admin/editar-livro.php?id=' . $dc);
[, $form2] = $nav2->ir('admin/editar-livro.php?id=' . $dc);
$campos = function (string $html): array {
    preg_match('/name="csrf" value="([^"]+)"/', $html, $c);
    preg_match('/name="versao" value="([^"]*)"/', $html, $v);
    preg_match('/name="base" value="([^"]*)"/', $html, $b);
    return ['csrf' => html_entity_decode($c[1] ?? ''), 'versao' => $v[1] ?? '', 'base' => html_entity_decode($b[1] ?? '', ENT_QUOTES)];
};
[$f1, $f2] = [$campos($form1), $campos($form2)];
confere('H4. aviso de edição simultânea aparece para o segundo colaborador', str_contains($form2, 'Esta obra está sendo editada por'));
$dadosDc = fn (array $extra) => $extra + ['titulo' => 'Dom Casmurro', 'autores' => ['Machado de Assis'], 'editora' => 'Ática', 'ano_publicacao' => '1999', 'idioma' => 'Português', 'status_validacao' => 'aguardando_conferencia', 'acao' => 'salvar'];
[$s] = $nav->ir('admin/editar-livro.php?id=' . $dc, $dadosDc(['ano_publicacao' => '1997']) + $f1);
confere('H5. primeiro colaborador salva (ano)', $s === 303 && (int)$val('SELECT ano_publicacao FROM livros WHERE id = ?', [$dc]) === 1997);
[$s, $html] = $nav2->ir('admin/editar-livro.php?id=' . $dc, $dadosDc(['editora' => 'Editora Ática S.A.']) + $f2);
confere('H6. segundo colaborador (outro campo) recebe a comparação em vez de sobrescrever', $s === 200 && str_contains($html, 'Esta obra foi atualizada por outro usuário enquanto você estava editando')
    && (int)$val('SELECT ano_publicacao FROM livros WHERE id = ?', [$dc]) === 1997 && $val('SELECT editora FROM livros WHERE id = ?', [$dc]) === 'Ática');
confere('H7. o formulário preserva a alteração digitada e traz a do outro colaborador', str_contains($html, 'value="Editora Ática S.A."') && str_contains($html, 'value="1997"') && str_contains($html, 'Só você alterou'));
$f2b = $campos($html);
[$s] = $nav2->ir('admin/editar-livro.php?id=' . $dc, $dadosDc(['editora' => 'Editora Ática S.A.', 'ano_publicacao' => '1997']) + $f2b);
confere('H8. salvar de novo grava as alterações não conflitantes dos dois', $s === 303 && (int)$val('SELECT ano_publicacao FROM livros WHERE id = ?', [$dc]) === 1997 && $val('SELECT editora FROM livros WHERE id = ?', [$dc]) === 'Editora Ática S.A.');
[, $form1] = $nav->ir('admin/editar-livro.php?id=' . $dc);
[, $form2] = $nav2->ir('admin/editar-livro.php?id=' . $dc);
[$f1, $f2] = [$campos($form1), $campos($form2)];
$nav->ir('admin/editar-livro.php?id=' . $dc, $dadosDc(['editora' => 'Editora Ática S.A.', 'ano_publicacao' => '1997', 'titulo' => 'Dom Casmurro — edição escolar']) + $f1);
[$s, $html] = $nav2->ir('admin/editar-livro.php?id=' . $dc, $dadosDc(['editora' => 'Editora Ática S.A.', 'ano_publicacao' => '1997', 'titulo' => 'Dom Casmurro — edição de bolso']) + $f2);
confere('H9. mesmo campo alterado pelos dois: escolha "manter o meu" / "usar o salvo"', str_contains($html, 'Os dois alteraram') && str_contains($html, 'name="resolver[titulo]"'));
$f2c = $campos($html);
[$s] = $nav2->ir('admin/editar-livro.php?id=' . $dc, $dadosDc(['editora' => 'Editora Ática S.A.', 'ano_publicacao' => '1997', 'titulo' => 'Dom Casmurro — edição de bolso', 'resolver' => ['titulo' => 'salvo']]) + $f2c);
confere('H10. "usar o salvo" mantém o valor do outro colaborador', $s === 303 && $val('SELECT titulo FROM livros WHERE id = ?', [$dc]) === 'Dom Casmurro — edição escolar');

// Registro de vários exemplares pela página, com a mesma chave enviada duas vezes.
$lotes->escolherAtivo($loteB, $adm);
[, $html] = $nav->ir('admin/cadastrar-exemplar.php?livro=' . $nova . '&modo=varios');
preg_match('/name="chave" value="([a-f0-9]{32})"/', $html, $mc);
$postReg = ['csrf' => $tok($nav, 'admin/cadastrar-exemplar.php?livro=' . $nova), 'livro_id' => (string)$nova, 'chave' => $mc[1] ?? '', 'quantidade' => '3', 'situacao' => 'disponivel', 'confirmar' => '1', 'acao' => 'registrar'];
$antesPag = $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$nova]);
[$s1, , $loc1] = $nav->ir('admin/cadastrar-exemplar.php', $postReg);
[$s2, , $loc2] = $nav->ir('admin/cadastrar-exemplar.php', $postReg);
confere('H11. "Registrar vários exemplares" pela página cria 3 cópias e mostra o resultado do banco', $s1 === 303 && str_contains($loc1, 'resultado=') && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$nova]) === $antesPag + 3);
confere('H12. reenviar o mesmo formulário não duplica (mostra "registrado anteriormente")', $s2 === 303 && str_contains($loc2, 'repetido=1') && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$nova]) === $antesPag + 3);
[, $html] = $nav->ir(preg_replace('#^https?://[^/]+/#', '', $loc2));
confere('H13. resultado lista os códigos atribuídos, o lote e as ações seguintes', substr_count($html, 'class="adm-code"') === 3 && str_contains($html, 'Registrar mais exemplares') && str_contains($html, 'Próximo livro') && str_contains($html, 'Consultar planilha do lote'));

// Permissões no servidor.
$auxNav = new TesteNavegador($base);
$auxNav->entrar('aux@teste.br', $senha);
[$s] = $auxNav->ir('admin/qualidade.php');
confere('H14. auxiliar consulta a Central de Qualidade', $s === 200);
// Único perfil (migração 200): contas antigas com outro perfil são administradoras; quem não pode gravar é
// quem não entrou ou envia sem o token CSRF.
[$s] = $auxNav->ir('admin/qualidade.php', ['csrf' => 'falso', 'acao' => 'analisar']);
confere('H15. sem o token CSRF, a análise não é atualizada (419)', $s === 419);
[$s] = $auxNav->ir('admin/qualidade.php', ['csrf' => 'falso', 'acao' => 'unificar', 'mantido' => (string)$ed1, 'unificado' => (string)$ed2, 'motivo' => 'x', 'confirmo' => '1', 'mesma_edicao' => '1']);
confere('H16. sem o token CSRF, nada é unificado (419)', $s === 419 && $val('SELECT arquivado_em FROM livros WHERE id = ?', [$ed2]) === null);
[$s] = $auxNav->ir('admin/diagnostico.php');
confere('H17. conta antiga "auxiliar" abre o diagnóstico', $s === 200);
$consNav = new TesteNavegador($base);
$consNav->entrar('cons@teste.br', $senha);
[$s] = $consNav->ir('admin/qualidade.php');
confere('H18. conta antiga "consulta" abre a Central de Qualidade', $s === 200);
[$s] = (new TesteNavegador($base))->ir('admin/aquisicoes.php', ['tipo' => 'compra', 'origem' => 'X', 'data_recebimento' => date('Y-m-d'), 'quantidade_recebida' => '1']);
confere('H19. sem login, não registra aquisição (302)', $s === 302);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/(PHP (Fatal|Warning|Notice|Deprecated)|Uncaught)/', $log), substr($log, 0, 1500));

teste_resultado();
