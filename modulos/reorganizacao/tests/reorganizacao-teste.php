<?php
/**
 * Testes do módulo temporário de reorganização do acervo (migração 220).
 *
 * Uso: php modulos/reorganizacao/tests/reorganizacao-teste.php
 *
 * Banco temporário "bdp_teste_reorg_XXXX" (apagado no final), dados FICTÍCIOS. Cobre os 42 cenários
 * obrigatórios da especificação (numerados T01..T42 nas verificações) e as páginas do módulo.
 */

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Reorganizacao\RegistroConflitoException;
use Biblioteca\Reorganizacao\SessaoEncerradaException;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;

require __DIR__ . '/../../../admin/tests/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('reorg');
require_once __DIR__ . '/../bootstrap.php';
$_SERVER['SCRIPT_NAME'] = '/modulos/reorganizacao/tests/reorganizacao-teste.php';
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
foreach (['adm' => 'adm@teste.br', 'bib' => 'bib@teste.br', 'out' => 'outro@teste.br'] as $k => $email) {
    $pdo->prepare("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'administrador')")->execute(['Administração Fictícia ' . strtoupper($k), $email, password_hash($senha, PASSWORD_DEFAULT)]);
    $contas[$k] = (int)$pdo->lastInsertId();
}
[$adm, $bib, $out] = [$contas['adm'], $contas['bib'], $contas['out']];

$config = reorg_config();
$config['tolerancia_desconexao'] = 120;
$ex = new ExemplarService($pdo);
$ex->definirRegrasPreparo(false, false);
$r = new Reorganizacao($pdo, $config, $ex);
$lev = $r->levantamento();
$et = $r->etapas();

$pdo->exec("INSERT INTO livros (titulo, isbn, editora, dados_verificados_em) VALUES ('Branca de Neve Fictícia', '9788535902778', 'Editora Exemplo', NOW())");
$obraExistente = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO setores (nome) VALUES ('Setor Teste')");
$setor = (int)$pdo->lastInsertId();

$form = fn (array $extra = []) => $extra + [
    'titulo' => 'Livro Fictício', 'autores' => 'SILVA, Ana Fictícia', 'editora' => 'Editora Teste', 'isbn_nao_encontrado' => '1', 'cdd_nao_encontrada' => '1',
    'quantidade' => '1', 'estados' => [1 => 'excelente'],
];

// ---------------------------------------------------------------
echo "\nT01–T02. Ativação e desativação do período; botão de acesso\n";
confere('T01. sem período ativo, ninguém entra', str_contains((string)$recusa(fn () => $r->entrar('Maria Fictícia Souza')), 'período'));
$botao = function () {
    ob_start();
    include __DIR__ . '/../includes/login-botao.php';
    return ob_get_clean();
};
confere('T02. botão "Equipe de organização" escondido sem período ativo', !str_contains($botao(), 'Equipe de organização'));
$periodo = $r->iniciarPeriodo(['nome' => 'Reorganização Fictícia', 'inicio' => date('Y-m-d'), 'instrucoes_gerais' => 'Instruções gerais fictícias', 'instrucoes_grupo1' => 'Copie os dados da folha de rosto'], $adm);
confere('T01. período iniciado e ativo', (int)$val('SELECT ativo FROM reorg_periodos WHERE id = ?', [$periodo]) === 1);
confere('T01. não inicia dois períodos ao mesmo tempo', $recusa(fn () => $r->iniciarPeriodo(['nome' => 'Outro'], $adm)) !== null);
confere('T02. botão aparece com o período ativo (cinza e discreto)', str_contains($botao(), 'Equipe de organização') && str_contains($botao(), 'reorg-botao-equipe'));
confere('T01. links de equipe da migração 210 desligados pela migração 220', $val("SELECT valor FROM configuracoes_sistema WHERE chave = 'COLETA_LINKS_ATIVOS'") === 'false');

// ---------------------------------------------------------------
echo "\nT03–T05. Entrada sem conta, nomes repetidos, sessões independentes\n";
$e1 = $r->entrar('Ana Participante Fictícia', '10.0.0.1');
$e2 = $r->entrar('Ana Participante Fictícia', '10.0.0.1');
$e3 = $r->entrar('Bruno Fictício Lima', '10.0.0.1');
$e4 = $r->entrar('Carla Fictícia Dias', '10.0.0.1');
$e5 = $r->entrar('Davi Fictício Rocha', '10.0.0.1');
confere('T03. entrada só com o nome: sessão criada, identificador de 64 caracteres, banco guarda só o hash',
    strlen($e1['token']) === 64 && $num('SELECT COUNT(*) FROM reorg_sessoes WHERE token_hash = ?', [hash('sha256', $e1['token'])]) === 1 && $num('SELECT COUNT(*) FROM reorg_sessoes WHERE token_hash = ?', [$e1['token']]) === 0);
confere('T03. nome curto ou vazio é recusado', $recusa(fn () => $r->entrar('  ')) !== null && $recusa(fn () => $r->entrar('Ab')) !== null);
confere('T04. mesmo nome = duas sessões diferentes, com identificadores diferentes', $e1['sessao']['id'] !== $e2['sessao']['id'] && $e1['token'] !== $e2['token']);
$ativos = $r->participantesAtivos();
$ana = array_values(array_filter($ativos, fn ($s) => $s['nome'] === 'Ana Participante Fictícia'));
confere('T04. central mostra o aviso de nome repetido nas duas sessões', count($ana) === 2 && (int)$ana[0]['mesmo_nome'] === 1 && (int)$ana[1]['mesmo_nome'] === 1);
confere('T04. administradores notificados da entrada (com aviso de nome repetido)', $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE administrador_id = ? AND tipo = 'participante_conectado' AND texto LIKE '%mesmo nome%'", [$adm]) === 1);
confere('T05. sessão vale pelo identificador, não pelo nome', ($r->sessaoPorToken($e1['token'])['id'] ?? 0) === $e1['sessao']['id'] && $r->sessaoPorToken(str_repeat('a', 64)) === null);
$s1 = $r->sessaoPorToken($e1['token']);
$s2 = $r->sessaoPorToken($e2['token']);
$s3 = $r->sessaoPorToken($e3['token']);
$s4 = $r->sessaoPorToken($e4['token']);
$s5 = $r->sessaoPorToken($e5['token']);
$r->escolherEquipe($s1, 'grupo1');
$r->escolherEquipe($s2, 'grupo1');
$r->escolherEquipe($s3, 'grupo3');
$r->escolherEquipe($s4, 'grupo4');
$r->escolherEquipe($s5, 'grupo3');
confere('T05. Grupo 2 não pode ser escolhido por participante', $recusa(fn () => $r->escolherEquipe($r->entrar('Erica Fictícia')['sessao'], 'grupo2')) !== null);
confere('T05. a equipe não muda sem solicitação', $recusa(fn () => $r->escolherEquipe($s1, 'grupo3')) !== null);
foreach (['s1', 's2', 's3', 's4', 's5'] as $v) {
    $$v = $r->sessao((int)$$v['id']);
}

// ---------------------------------------------------------------
echo "\nT10–T11. Vários lotes ao mesmo tempo, vários participantes por equipe\n";
$lote = $r->criarLote(['nome' => 'Lote 001 — Estante A (fictício)', 'quantidade' => 30, 'responsavel_id' => $adm, 'admins' => [$bib], 'grupo1' => [$s1['id'], $s2['id']], 'grupo3' => [$s3['id'], $s5['id']], 'grupo4' => [$s4['id']], 'instrucoes' => 'Comece pela prateleira de cima'], $adm);
$lote2 = $r->criarLote(['nome' => 'Lote 002 — Estante B (fictício)', 'quantidade' => 5, 'responsavel_id' => $bib], $adm);
$lote3 = $r->criarLote(['nome' => 'Lote 003 — Estante C (fictício)', 'quantidade' => 3, 'responsavel_id' => $adm], $adm);
$L = $r->lote($lote);
confere('T10. três lotes ao mesmo tempo, cada um com o lote gerenciado permanente', $num('SELECT COUNT(*) FROM reorg_lotes') === 3
    && $val('SELECT modo FROM cadastro_lotes WHERE id = ?', [$L['cadastro_lote_id']]) === 'gerenciado' && (int)$val('SELECT capacidade FROM cadastro_lotes WHERE id = ?', [$L['cadastro_lote_id']]) === 30);
confere('T11. dois participantes no Grupo 1 e dois no Grupo 3 do mesmo lote', $num("SELECT COUNT(*) FROM reorg_atribuicoes WHERE lote_id = ? AND equipe = 'grupo1' AND encerrada_em IS NULL", [$lote]) === 2
    && $num("SELECT COUNT(*) FROM reorg_atribuicoes WHERE lote_id = ? AND equipe = 'grupo3' AND encerrada_em IS NULL", [$lote]) === 2);
confere('T11. atribuição aparece no painel do participante (com notificação)', count($r->lotesDaSessao((int)$s1['id'])) === 1 && $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE sessao_id = ? AND tipo = 'lote_atribuido'", [$s1['id']]) === 1);
confere('T11. participante não pode ser atribuído em equipe diferente da sua', $recusa(fn () => $r->atribuir($lote2, (int)$s3['id'], 'grupo1', $adm)) !== null);
confere('T41. participante sem atribuição não acessa o lote', $recusa(fn () => $lev->novoRegistro($s1, $lote2)) !== null);

// ---------------------------------------------------------------
echo "\nT16–T25. Grupo 1: salvamento, ISBN, CDD, múltiplos exemplares, pesquisa, correção\n";
$reg = $lev->novoRegistro($s1, $lote);
confere('T16. novo registro nasce como rascunho reservado para quem criou', $reg['status'] === 'rascunho' && (int)$reg['editando_sessao_id'] === (int)$s1['id']);
$res = $lev->salvar($s1, (int)$reg['id'], ['titulo' => 'Branca de Neve Fictícia'], (int)$reg['versao']);
confere('T16. formulário incompleto é salvo no banco (sem completar)', !$res['completo'] && $val('SELECT titulo FROM reorg_registros WHERE id = ?', [$reg['id']]) === 'Branca de Neve Fictícia' && isset($res['erros']['autores']));
confere('T16. salvamento devolve a versão e o horário confirmados pelo servidor', $res['versao'] === (int)$reg['versao'] + 1 && $res['salvo_em'] !== '');
[, $eIsbn] = $lev->validar($form(['isbn_nao_encontrado' => '', 'isbn' => '978-85-359-0277-9']));
[, $eIsbn10] = $lev->validar($form(['isbn_nao_encontrado' => '', 'isbn' => '0-306-40615-2']));
[, $eIsbnOk] = $lev->validar($form(['isbn_nao_encontrado' => '', 'isbn' => '978-85-359-0277-8']));
[, $eIsbn10x] = $lev->validar($form(['isbn_nao_encontrado' => '', 'isbn' => '0-8044-2957-X']));
confere('T21. ISBN-13 com dígito errado é recusado; ISBN-13 e ISBN-10 (inclusive com X) corretos passam', isset($eIsbn['isbn']) && !isset($eIsbnOk['isbn']) && !isset($eIsbn10['isbn']) && !isset($eIsbn10x['isbn']));
[, $eSemIsbn] = $lev->validar($form(['isbn_nao_encontrado' => '']));
confere('T21. ISBN não pode ser ignorado (nem número nem "não encontrado")', isset($eSemIsbn['isbn']));
[$dNao, $eNao] = $lev->validar($form(['isbn' => '123']));
confere('T22. "ISBN não encontrado": campo fica vazio e o indicador separado é gravado', !isset($eNao['isbn']) && $dNao['isbn'] === null && $dNao['isbn_nao_encontrado'] === 1);
[, $eCdd] = $lev->validar($form(['cdd_nao_encontrada' => '']));
[$dCdd, $eCdd2] = $lev->validar($form(['cdd_nao_encontrada' => '', 'cdd' => '869,3']));
confere('T23. CDD obrigatória ou "não encontrada"; CDD com vírgula vira 869.3', isset($eCdd['cdd']) && !isset($eCdd2['cdd']) && $dCdd['cdd'] === '869.3');
[$dCddN] = $lev->validar($form(['cdd' => 'Não encontrada']));
confere('T23. com "CDD não encontrada", nada é escrito no campo CDD', $dCddN['cdd'] === null && $dCddN['cdd_nao_encontrada'] === 1);
$busca = $lev->pesquisarObras('Branca');
$buscaIsbn = $lev->pesquisarObras('978-85-359-0277-8');
$buscaEd = $lev->pesquisarObras('Editora Exemplo');
confere('T24. pesquisa de obras existentes por título, ISBN e editora', ($busca[0]['id'] ?? 0) === $obraExistente && ($buscaIsbn[0]['id'] ?? 0) === $obraExistente && ($buscaEd[0]['id'] ?? 0) === $obraExistente);
$lev->sugerirObra((int)$reg['id'], $obraExistente, $s1);
$v = (int)$val('SELECT versao FROM reorg_registros WHERE id = ?', [$reg['id']]);
$res = $lev->salvar($s1, (int)$reg['id'], $form(['titulo' => 'Branca de Neve Fictícia', 'editora' => 'Editora Exemplo', 'isbn_nao_encontrado' => '', 'isbn' => '9788535902778', 'quantidade' => '5', 'estados' => [1 => 'excelente', 2 => 'regular', 3 => 'ruim', 4 => 'excelente', 5 => 'regular']]), $v);
confere('T19. 5 exemplares da mesma obra: dados bibliográficos uma vez só, 5 exemplares no progresso', $res['completo'] && $num('SELECT COUNT(*) FROM reorg_registro_exemplares WHERE registro_id = ? AND removido_em IS NULL', [$reg['id']]) === 5
    && $r->contadores($lote)['registrados'] === 5);
confere('T20. estados de conservação diferentes na mesma obra', $val("SELECT GROUP_CONCAT(estado_conservacao ORDER BY ordem) FROM reorg_registro_exemplares WHERE registro_id = ? AND removido_em IS NULL", [$reg['id']]) === 'excelente,regular,ruim,excelente,regular');
[, $eEst] = $lev->validar($form(['quantidade' => '3', 'estados' => [1 => 'excelente']]));
[$dTodos, $eTodos] = $lev->validar($form(['quantidade' => '3', 'estados' => [], 'aplicar_todos' => 'regular']));
confere('T20. estado obrigatório para cada exemplar; "aplicar a todos" preenche', isset($eEst['estados']) && !isset($eTodos['estados']) && $dTodos['estados'] === [1 => 'regular', 2 => 'regular', 3 => 'regular']);
confere('T26. versão antiga não sobrescreve (outra pessoa salvou antes)', $recusa(fn () => $lev->salvar($s1, (int)$reg['id'], $form(), $v)) !== null);
try {
    $lev->salvar($s1, (int)$reg['id'], $form(), $v);
} catch (RegistroConflitoException $c) {
    confere('T26. conflito devolve os dados atuais para a pessoa conferir', ($c->atual['titulo'] ?? '') === 'Branca de Neve Fictícia');
}
$aberto = $lev->abrir((int)$reg['id'], $s2);
confere('T26. registro em edição por outra pessoa abre só para consulta, com aviso', $aberto['somente_leitura'] && str_contains($aberto['motivo_leitura'], 'Ana Participante'));
confere('T26. salvar registro reservado por outra pessoa é recusado no servidor', str_contains((string)$recusa(fn () => $lev->salvar($s2, (int)$reg['id'], $form(), (int)$aberto['versao'])), 'editado por outra pessoa'));
$lev->liberar((int)$reg['id'], $s1);
$aberto2 = $lev->abrir((int)$reg['id'], $s2);
confere('T17. rascunho liberado pode ser recuperado por outro participante autorizado do mesmo lote', !$aberto2['somente_leitura'] && $aberto2['titulo'] === 'Branca de Neve Fictícia' && count($aberto2['exemplares']) === 5);
$lev->liberar((int)$reg['id'], $s2);
// 15 registros completos de 1 exemplar → 20 exemplares
$ids = [(int)$reg['id']];
for ($i = 1; $i <= 15; $i++) {
    $n = $lev->novoRegistro($i % 2 ? $s1 : $s2, $lote);
    $lev->salvar($i % 2 ? $s1 : $s2, (int)$n['id'], $form(['titulo' => 'Obra Fictícia ' . $i, 'estados' => [1 => $i % 3 ? 'excelente' : 'ruim']]), (int)$n['versao']);
    $lev->liberar((int)$n['id'], $i % 2 ? $s1 : $s2);
    $ids[] = (int)$n['id'];
}
confere('T28. lote de 30 com 20 exemplares registrados', $r->contadores($lote)['registrados'] === 20 && $r->contadores($lote)['aguardando_levantamento'] === 10);
$inc = $lev->novoRegistro($s2, $lote);
$lev->salvar($s2, (int)$inc['id'], ['titulo' => 'Obra incompleta fictícia', 'quantidade' => '1'], (int)$inc['versao']);
$previa = $lev->previaEncaminhamento($lote);
confere('T28. prévia do encaminhamento: 20 encaminhados e 10 restantes', $previa['exemplares'] === 20 && $previa['restantes'] === 10);
$enc = $lev->encaminhar($lote, $s1);
confere('T28. encaminhamento parcial: 20 ao Grupo 2, incompleto e não registrados continuam pendentes', $enc['exemplares'] === 20 && $enc['restantes'] === 10
    && $val('SELECT status FROM reorg_registros WHERE id = ?', [$inc['id']]) === 'rascunho' && $num("SELECT COUNT(*) FROM reorg_registros WHERE lote_id = ? AND status = 'aguardando_revisao'", [$lote]) === 16);
confere('T28. responsável notificado dos registros enviados para revisão', $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE administrador_id = ? AND tipo = 'registros_revisao'", [$adm]) === 1);
confere('T28. dados originais guardados no encaminhamento', $val('SELECT dados_originais FROM reorg_registros WHERE id = ?', [$reg['id']]) !== null);
confere('T25. Grupo 1 não altera registro encaminhado', $recusa(fn () => $lev->salvar($s1, (int)$reg['id'], $form(), 0)) !== null);
confere('T15. capacidade: não passa da quantidade prevista (11 exemplares num lote com 10 vagas livres)', str_contains((string)$recusa(fn () => $lev->salvar($s2, (int)$inc['id'], $form(['quantidade' => '11', 'aplicar_todos' => 'regular']), 0)), 'no máximo 10'));

// ---------------------------------------------------------------
echo "\nT27, T30–T32. Grupo 2: revisão, aprovação, códigos BDP\n";
confere('T27. aprovação só pelo responsável ou administrador autorizado', $recusa(fn () => $lev->aprovar($out, (int)$reg['id'])) !== null);
$regAberto = $lev->abrir((int)$reg['id'], null, $adm);
$rev = $lev->salvarRevisao($adm, (int)$reg['id'], ['cdd' => '398.2', 'cdd_nao_encontrada' => '', 'cdd_situacao' => 'encontrada'] + $lev->paraFormulario($regAberto), (int)$regAberto['versao']);
confere('T27. correção administrativa salva, original preservado e diferença visível', $val('SELECT cdd FROM reorg_registros WHERE id = ?', [$reg['id']]) === '398.2'
    && isset($lev->diferencas($lev->registro((int)$reg['id']))['cdd']));
$lev->liberar((int)$reg['id'], null, $adm);
$lev->aprovar($adm, (int)$reg['id']);
confere('T27. aprovado (obra existente escolhida pelo Grupo 1)', $val('SELECT status FROM reorg_registros WHERE id = ?', [$reg['id']]) === 'aprovado');
confere('T27. aprovação duplicada é recusada', str_contains((string)$recusa(fn () => $lev->aprovar($bib, (int)$reg['id'])), 'já foi aprovado'));
$massa = $lev->aprovarEmMassa($bib, $lote, array_slice($ids, 1));
confere('T27. aprovação em massa (15 registros) pelo administrador autorizado', $massa['aprovados'] === 15);
$cad = $lev->cadastrar($adm, (int)$reg['id']);
confere('T31. cadastro: 5 exemplares = 5 códigos BDP distintos, na obra existente (relacionamentos preservados)', count($cad['codigos']) === 5 && count(array_unique($cad['codigos'])) === 5
    && !$cad['obra_nova'] && $cad['livro_id'] === $obraExistente && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$obraExistente]) === 5);
confere('T18. estados de conservação vão para o cadastro permanente dos exemplares', $val("SELECT GROUP_CONCAT(e.estado_conservacao ORDER BY x.ordem) FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.registro_id = ?", [$reg['id']]) === 'excelente,regular,ruim,excelente,regular');
$cad2 = $lev->cadastrar($adm, (int)$reg['id']);
confere('T31. repetir a requisição não cadastra de novo (devolve os mesmos códigos)', $cad2['repetido'] && $cad2['codigos'] === $cad['codigos'] && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$obraExistente]) === 5);
confere('T32. exemplares no lote permanente (Planilha BDP), na ordem do lote', $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$L['cadastro_lote_id']]) === 5
    && $val('SELECT GROUP_CONCAT(lote_ordem ORDER BY lote_ordem) FROM exemplares WHERE lote_id = ?', [$L['cadastro_lote_id']]) === '1,2,3,4,5');
confere('T25. livro em reorganização NÃO fica disponível para empréstimo', $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND situacao = 'disponivel'", [$L['cadastro_lote_id']]) === 0);
// T30: cadastro simultâneo em processos separados
$cmd = fn (int $regId, int $admin) => escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . __DIR__ . '/../bootstrap.php"; try { $c = reorg()->levantamento()->cadastrar(' . $admin . ', ' . $regId . '); echo implode(",", $c["codigos"]); } catch (Throwable $e) { echo "ERRO:" . $e->getMessage(); }');
$procs = [];
foreach (array_slice($ids, 1, 8) as $k => $rid) {
    $procs[] = popen($cmd($rid, $k % 2 ? $adm : $bib) . ' 2>&1', 'r');
}
$saidas = [];
foreach ($procs as $p) {
    $saidas[] = stream_get_contents($p);
    pclose($p);
}
$codigosConc = array_merge(...array_map(fn ($s) => str_starts_with($s, 'ERRO') ? [] : explode(',', trim($s)), $saidas));
confere('T30. 8 cadastros simultâneos (processos separados): 8 códigos, nenhum repetido', count($codigosConc) === 8 && count(array_unique($codigosConc)) === 8, implode(' | ', $saidas));
$repeat = [];
foreach ([1, 2, 3] as $k) {
    $repeat[] = popen($cmd($ids[9], $adm) . ' 2>&1', 'r');
}
$outs = array_map(function ($p) { $s = stream_get_contents($p); pclose($p); return trim($s); }, $repeat);
confere('T31. mesma requisição de cadastro enviada 3 vezes ao mesmo tempo: um só exemplar', count(array_unique($outs)) === 1 && !str_starts_with($outs[0], 'ERRO')
    && $num('SELECT COUNT(*) FROM reorg_registro_exemplares WHERE registro_id = ? AND exemplar_id IS NOT NULL', [$ids[9]]) === 1, implode(' | ', $outs));
$rest = $lev->cadastrarAprovados($adm, $lote);
confere('T31. demais aprovados cadastrados; total de códigos = total de exemplares', !$rest['erros'] && $r->contadores($lote)['codigos'] === 20
    && $num('SELECT COUNT(DISTINCT e.codigo_individual) FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.lote_id = ?', [$lote]) === 20);
confere('T31. o código de um exemplar não muda (gatilho permanente)', $recusa(fn () => $pdo->exec("UPDATE exemplares SET codigo_individual = 'BDP-999999' WHERE id = (SELECT exemplar_id FROM reorg_registro_exemplares WHERE lote_id = $lote LIMIT 1)")) !== null);
confere('T32. Planilha BDP permanente mostra os 20 exemplares do lote', count((new Biblioteca\Services\PlanilhaBdpVersaoService($pdo))->conteudoAtual((int)$L['cadastro_lote_id'])) === 20);

// ---------------------------------------------------------------
echo "\nT29. Continuação dos 10 restantes\n";
$v = (int)$val('SELECT versao FROM reorg_registros WHERE id = ?', [$inc['id']]);
$lev->salvar($s2, (int)$inc['id'], $form(['titulo' => 'Obra Fictícia Restante', 'quantidade' => '6', 'aplicar_todos' => 'regular']), $v);
$n2 = $lev->novoRegistro($s1, $lote);
$lev->salvar($s1, (int)$n2['id'], $form(['titulo' => 'Mais uma Obra Fictícia', 'quantidade' => '4', 'aplicar_todos' => 'excelente', 'isbn_nao_encontrado' => '', 'isbn' => '0-306-40615-2']), (int)$n2['versao']);
confere('T29. 30 de 30 registrados', $r->contadores($lote)['registrados'] === 30);
confere('T29. lote completo não aceita novo registro', $recusa(fn () => $lev->novoRegistro($s1, $lote)) !== null);
$enc2 = $lev->encaminhar($lote, $s2);
confere('T26. registro aberto por outra pessoa fica para o próximo encaminhamento', $enc2['exemplares'] === 6, json_encode($enc2));
$lev->liberar((int)$n2['id'], $s1);
$enc3 = $lev->encaminhar($lote, $s2);
confere('T29. os 10 restantes encaminhados depois', $enc2['exemplares'] + $enc3['exemplares'] === 10 && $enc3['restantes'] === 0, json_encode($enc3));
$lev->devolver($adm, (int)$inc['id'], 'Confira o subtítulo na folha de rosto');
confere('T25. devolução de um registro específico para correção, com notificação ao Grupo 1', $val('SELECT status FROM reorg_registros WHERE id = ?', [$inc['id']]) === 'correcao'
    && $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE sessao_id = ? AND tipo = 'correcao'", [$s1['id']]) >= 1);
$v = (int)$val('SELECT versao FROM reorg_registros WHERE id = ?', [$inc['id']]);
$lev->salvar($s2, (int)$inc['id'], $form(['titulo' => 'Obra Fictícia Restante', 'subtitulo' => 'Subtítulo corrigido', 'quantidade' => '6', 'aplicar_todos' => 'regular']), $v);
$lev->encaminhar($lote, $s2);
$lev->aprovar($adm, (int)$inc['id']);
$lev->aprovar($adm, (int)$n2['id']);
$c10 = $lev->cadastrarAprovados($adm, $lote);
confere('T29. 30 códigos BDP no lote', $r->contadores($lote)['codigos'] === 30 && !$c10['erros']);
confere('T29. obra nova criada com os dados revisados (ISBN-10 aceito, sem "não encontrado" nos campos)', (int)$val('SELECT COUNT(*) FROM livros WHERE titulo = ? AND subtitulo = ?', ['Obra Fictícia Restante', 'Subtítulo corrigido']) === 1
    && $val("SELECT isbn FROM livros WHERE titulo = 'Obra Fictícia Restante'") === null);
confere('T29. lote permanente fechou ao atingir 30 (capacidade)', $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$L['cadastro_lote_id']]) === 'fechado');

// ---------------------------------------------------------------
echo "\nT33. Carimbação (Grupo 3), inclusive simultânea\n";
$pl = $et->planilhaCarimbacao($lote, 'pendentes');
confere('T33. planilha do Grupo 3 com título, autor, editora, estado e código BDP', count($pl) === 30 && $pl[0]['codigo_individual'] && array_key_exists('editora', $pl[0]) && $pl[0]['estado_conservacao']);
$et->reservar($s3, (int)$pl[0]['id'], 'grupo3');
confere('T33. dois participantes não pegam o mesmo exemplar', str_contains((string)$recusa(fn () => $et->reservar($s5, (int)$pl[0]['id'], 'grupo3')), 'Outra pessoa'));
confere('T33. o outro não consegue marcar o exemplar reservado', $recusa(fn () => $et->marcarCarimbado($s5, (int)$pl[0]['id'])) !== null);
$et->marcarCarimbado($s3, (int)$pl[0]['id']);
confere('T33. carimbação duplicada é recusada', str_contains((string)$recusa(fn () => $et->marcarCarimbado($s5, (int)$pl[0]['id'])), 'já foi marcado'));
confere('T33. carimbo vai para o exemplar permanente (sem redigitar o código)', $val('SELECT carimbo_status FROM exemplares WHERE id = (SELECT exemplar_id FROM reorg_registro_exemplares WHERE id = ?)', [$pl[0]['id']]) === 'carimbado');
$tk3 = $e3['token'];
$tk5 = $e5['token'];
$cmdC = fn (string $tk, int $rex) => escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . __DIR__ . '/../bootstrap.php"; $s = reorg()->sessaoPorToken("' . $tk . '"); try { echo reorg()->etapas()->marcarCarimbado($s, ' . $rex . '); } catch (Throwable $e) { echo "ERRO"; }');
$ps = [];
foreach (array_slice($pl, 1, 6) as $item) {
    $ps[] = popen($cmdC($tk3, (int)$item['id']) . ' 2>&1', 'r');
    $ps[] = popen($cmdC($tk5, (int)$item['id']) . ' 2>&1', 'r');
}
$res = array_map(function ($p) { $s = stream_get_contents($p); pclose($p); return trim($s); }, $ps);
confere('T33. 2 participantes carimbando os mesmos 6 exemplares ao mesmo tempo: cada um carimbado uma vez', count(array_filter($res, fn ($s) => $s !== 'ERRO')) === 6
    && $num('SELECT COUNT(*) FROM reorg_registro_exemplares WHERE lote_id = ? AND carimbado_em IS NOT NULL', [$lote]) === 7, implode(' ', $res));
foreach (array_slice($pl, 7, 13) as $item) {
    $et->marcarCarimbado($s5, (int)$item['id']);
}
confere('T33. filtros: 20 carimbados e 10 pendentes', count($et->planilhaCarimbacao($lote, 'carimbados')) === 20 && count($et->planilhaCarimbacao($lote, 'pendentes')) === 10);
$r->registrarPendencia($lote, 'livro_nao_localizado', 'Não achei este livro na pilha', 'grupo3', $s3, null, ['registro_exemplar_id' => (int)$pl[25]['id']]);
confere('T33. problema informado aparece no filtro "Com problemas" e na Central de Pendências', count($et->planilhaCarimbacao($lote, 'problemas')) === 1 && count($r->pendencias('abertas', $lote)) === 1);
confere('T33. Grupo 3 não altera código nem dados oficiais (não há operação para isso)', !method_exists($et, 'alterarCodigo'));
$g4 = $et->encaminharG4($lote, $s3);
confere('T15. encaminhamento parcial ao Grupo 4: 20 carimbados, 10 continuam', $g4['exemplares'] === 20 && $g4['restantes'] === 10);

// ---------------------------------------------------------------
echo "\nT34–T38. Conferência do Grupo 4\n";
$codigo = (string)$val('SELECT e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.id = ?', [$pl[0]['id']]);
$ok = $et->conferirCodigo($s4, $lote, strtolower($codigo));
confere('T34. código digitado à mão é localizado (aceita minúsculas) e mostra os dados para comparar', $ok['situacao'] === 'valido' && $ok['exemplar']['titulo'] !== '' && array_key_exists('cdd', $ok['exemplar']));
confere('T35. código inexistente', $et->conferirCodigo($s4, $lote, 'BDP-999998')['situacao'] === 'inexistente');
confere('T35. código incompleto', $et->conferirCodigo($s4, $lote, 'BDP-12')['situacao'] === 'formato');
// outro lote: cadastra 1 exemplar no lote 2
$r->atribuir($lote2, (int)$s1['id'], 'grupo1', $adm);
$o = $lev->novoRegistro($s1, $lote2);
$lev->salvar($s1, (int)$o['id'], $form(['titulo' => 'Obra Fictícia do Lote 2']), (int)$o['versao']);
$lev->encaminhar($lote2, $s1);
$lev->aprovar($bib, (int)$o['id']);
$co = $lev->cadastrar($bib, (int)$o['id']);
confere('T36. código pertencente a outro lote', $et->conferirCodigo($s4, $lote, $co['codigos'][0])['situacao'] === 'outro_lote');
$pdo->exec("INSERT INTO livros (titulo) VALUES ('Obra Fictícia Fora da Reorganização')");
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual, situacao) VALUES (" . (int)$pdo->lastInsertId() . ", 'BDP-900001', 'disponivel')");
confere('T36. código de exemplar fora da reorganização', $et->conferirCodigo($s4, $lote, 'BDP-900001')['situacao'] === 'fora_reorganizacao');
$semCarimbo = (string)$val('SELECT e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.lote_id = ? AND x.carimbado_em IS NULL LIMIT 1', [$lote]);
confere('T34. código ainda sem carimbação confirmada é avisado', $et->conferirCodigo($s4, $lote, $semCarimbo)['situacao'] === 'sem_carimbo');
confere('T34. conferência exige as duas confirmações', $recusa(fn () => $et->confirmarConferencia($s4, (int)$ok['exemplar']['id'], true, false)) !== null);
$et->confirmarConferencia($s4, (int)$ok['exemplar']['id'], true, true);
confere('T38. código já conferido', $et->conferirCodigo($s4, $lote, $codigo)['situacao'] === 'ja_conferido');
confere('T38. conferência duplicada é recusada', $recusa(fn () => $et->confirmarConferencia($s4, (int)$ok['exemplar']['id'], true, true)) !== null);
$errado = $et->conferirCodigo($s4, $lote, (string)$pl[1]['codigo_individual']);
$pend = $r->registrarPendencia($lote, 'codigo_livro_errado', 'O livro em mãos é outro: o título não corresponde', 'grupo4', $s4, null, ['registro_exemplar_id' => (int)$errado['exemplar']['id']]);
confere('T37. código válido escrito no livro errado: vira pendência e o exemplar fica com problema (não é conferido)', $errado['situacao'] === 'valido'
    && $val('SELECT conferido_em FROM reorg_registro_exemplares WHERE id = ?', [$errado['exemplar']['id']]) === null
    && (int)$val('SELECT problema_aberto FROM reorg_registro_exemplares WHERE id = ?', [$errado['exemplar']['id']]) === 1);
confere('T37. administrador notificado do código divergente', $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE administrador_id = ? AND tipo = 'codigo_divergente'", [$adm]) >= 1);
confere('T41. Grupo 4 só confere o lote autorizado', $recusa(fn () => $et->conferirCodigo($s4, $lote2, $co['codigos'][0])) !== null);
confere('T41. Grupo 3 não acessa a conferência do Grupo 4', $recusa(fn () => $et->conferirCodigo($s3, $lote, $codigo)) !== null);

// ---------------------------------------------------------------
echo "\nT13–T15. Solicitações (novo lote, ajuda, troca de equipe)\n";
$sol = $r->solicitar($s4, 'novo_lote');
confere('T13. solicitação de novo lote não cria nem atribui lote sozinha', $num('SELECT COUNT(*) FROM reorg_lotes') === 3 && count($r->lotesDaSessao((int)$s4['id'])) === 1);
confere('T13. pedido repetido enquanto pendente é recusado', $recusa(fn () => $r->solicitar($s4, 'novo_lote')) !== null);
$r->responderSolicitacao($sol, 'atribuir', $adm, '', ['lote_id' => $lote3]);
confere('T14. aprovação administrativa: lote existente atribuído e participante notificado', count($r->lotesDaSessao((int)$s4['id'])) === 2
    && $val('SELECT status FROM reorg_solicitacoes WHERE id = ?', [$sol]) === 'aprovada' && $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE sessao_id = ? AND tipo = 'lote_atribuido'", [$s4['id']]) === 2);
$sol2 = $r->solicitar($s3, 'novo_lote');
$r->responderSolicitacao($sol2, 'recusar', $adm, 'Aguarde o fim do lote atual');
confere('T13. recusa notifica com o motivo', str_contains((string)$val("SELECT texto FROM reorg_notificacoes WHERE sessao_id = ? AND tipo = 'solicitacao_resposta' ORDER BY id DESC LIMIT 1", [$s3['id']]), 'Aguarde'));
confere('T15. pedido de ajuda exige descrição', $recusa(fn () => $r->solicitar($s3, 'ajuda', ['lote_id' => $lote])) !== null);
$aj = $r->solicitar($s3, 'ajuda', ['lote_id' => $lote, 'problema_tipo' => 'codigo', 'descricao' => 'O carimbo borrou']);
$r->responderSolicitacao($aj, 'responder', $adm, 'Refaça o carimbo ao lado');
confere('T15. resposta do pedido de ajuda chega pelas notificações', str_contains((string)$val("SELECT texto FROM reorg_notificacoes WHERE sessao_id = ? AND tipo = 'solicitacao_resposta' ORDER BY id DESC LIMIT 1", [$s3['id']]), 'Refaça'));
$aj2 = $r->solicitar($s3, 'ajuda', ['descricao' => 'Outra dúvida']);
$r->responderSolicitacao($aj2, 'encaminhar', $adm, '', ['admin_id' => $bib]);
confere('T15. pedido de ajuda encaminhado a outro administrador', $val('SELECT status FROM reorg_solicitacoes WHERE id = ?', [$aj2]) === 'encaminhada' && $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE administrador_id = ? AND tipo = 'ajuda_encaminhada'", [$bib]) === 1);

// ---------------------------------------------------------------
echo "\nT12. Transferências\n";
$r->transferirLote((int)$s2['id'], $lote, $lote3, $adm);
confere('T12. transferência entre lotes: sai de um, entra no outro, registros preservados', !in_array($lote, array_map('intval', array_column($r->lotesDaSessao((int)$s2['id']), 'id')), true)
    && in_array($lote3, array_map('intval', array_column($r->lotesDaSessao((int)$s2['id']), 'id')), true) && $num('SELECT COUNT(*) FROM reorg_registros WHERE criado_sessao_id = ?', [$s2['id']]) > 0);
$t = $r->solicitar($s5, 'troca_equipe', ['equipe_desejada' => 'grupo4']);
$r->responderSolicitacao($t, 'aprovar', $adm);
confere('T12. troca de equipe aprovada: atribuições antigas encerradas, carimbos preservados', $r->sessao((int)$s5['id'])['equipe'] === 'grupo4' && count($r->lotesDaSessao((int)$s5['id'])) === 0
    && $num('SELECT COUNT(*) FROM reorg_registro_exemplares WHERE carimbado_sessao_id = ?', [$s5['id']]) > 0);

// ---------------------------------------------------------------
echo "\nT18. Pausa e retomada\n";
$resumo = $r->pausarParticipante($s1);
confere('T18. pausar grava o progresso, libera reservas e não encaminha nada', str_contains($resumo, 'registrados') && $r->sessao((int)$s1['id'])['situacao'] === 'pausada'
    && $num('SELECT COUNT(*) FROM reorg_registros WHERE editando_sessao_id = ?', [$s1['id']]) === 0 && $num("SELECT COUNT(*) FROM reorg_historico WHERE acao = 'atividade_pausada' AND sessao_id = ?", [$s1['id']]) >= 1);
$r->retomarParticipante($s1);
confere('T18. retomar', $r->sessao((int)$s1['id'])['situacao'] === 'disponivel');
$r->pausarLote($lote, $adm);
confere('T18. lote pausado pelo administrador bloqueia o trabalho', str_contains((string)$recusa(fn () => $et->confirmarConferencia($s4, (int)$errado['exemplar']['id'], true, true)), 'pausado'));
$r->pausarLote($lote, $adm, false);
$r->pausarEtapa($lote, 'grupo3', $adm);
confere('T18. etapa pausada bloqueia só aquela etapa', $recusa(fn () => $et->marcarCarimbado($s3, (int)$pl[20]['id'])) !== null && $et->conferirCodigo($s4, $lote, $codigo)['situacao'] === 'ja_conferido');
$r->pausarEtapa($lote, 'grupo3', $adm, false);

// ---------------------------------------------------------------
echo "\nT06–T09. Encerramento voluntário, forçado, perda de conexão e retorno\n";
$e6 = $r->entrar('Fábio Fictício Nunes', '10.0.0.2');
$s6 = $e6['sessao'];
$r->escolherEquipe($s6, 'grupo3');
$r->atribuir($lote, (int)$s6['id'], 'grupo3', $adm);
$r->sair($r->sessao((int)$s6['id']));
confere('T06. saída voluntária encerra a sessão na hora e revoga atribuições', $r->sessaoPorToken($e6['token']) === null && $num('SELECT COUNT(*) FROM reorg_atribuicoes WHERE sessao_id = ? AND encerrada_em IS NULL', [$s6['id']]) === 0);
$r->encerrarForcado((int)$s3['id'], $adm);
confere('T07. encerramento forçado pelo administrador', $r->sessaoPorToken($e3['token']) === null && $val('SELECT motivo_encerramento FROM reorg_sessoes WHERE id = ?', [$s3['id']]) === 'forcado');
confere('T07. sessão encerrada não consegue mais trabalhar', $recusa(fn () => $et->marcarCarimbado($s3, (int)$pl[21]['id'])) !== null);
confere('T07. sinal de sessão encerrada avisa o navegador', $recusa(fn () => $r->sinal($s3)) !== null);
$r->sinal($s4, true);
$pdo->prepare('UPDATE reorg_sessoes SET ultimo_sinal_em = NOW() - INTERVAL 50 SECOND WHERE id = ?')->execute([$s4['id']]);
confere('T08. sem sinal recente (dentro da tolerância): "conexão interrompida", sessão continua', $r->estadoConexao($r->sessao((int)$s4['id'])) === 'interrompida' && $r->sessaoPorToken($e4['token']) !== null);
$pdo->prepare('UPDATE reorg_sessoes SET ultimo_sinal_em = NOW() - INTERVAL 200 SECOND WHERE id = ?')->execute([$s4['id']]);
$r->varrer();
confere('T08. sem comunicação além da tolerância: sessão encerrada como "desconectado"', $r->estadoConexao($r->sessao((int)$s4['id'])) === 'desconectado');
confere('T08. administradores notificados da desconexão', $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE tipo = 'participante_desconectado' AND texto LIKE ?", ['%Carla%']) >= 1);
confere('T08. lote sem participantes no Grupo 4 avisa o responsável', $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE administrador_id = ? AND tipo = 'lote_sem_participantes'", [$adm]) >= 1);
confere('T08. o que foi feito continua salvo (conferência da Carla)', $num('SELECT COUNT(*) FROM reorg_registro_exemplares WHERE conferido_sessao_id = ?', [$s4['id']]) === 1);
$volta = $r->entrar('Carla Fictícia Dias', '10.0.0.1');
confere('T09. retorno com o mesmo nome: sessão nova, sem equipe, sem lotes e sem notificações anteriores', $volta['sessao']['id'] !== $s4['id'] && $volta['sessao']['equipe'] === null
    && count($r->lotesDaSessao((int)$volta['sessao']['id'])) === 0 && $num('SELECT COUNT(*) FROM reorg_notificacoes WHERE sessao_id = ?', [$volta['sessao']['id']]) === 0);
confere('T05. informar o nome de outra pessoa não dá acesso às atividades dela', $recusa(fn () => $et->conferirCodigo($volta['sessao'], $lote, $codigo)) !== null);
$s7 = $r->sessao((int)$volta['sessao']['id']);
$r->escolherEquipe($s7, 'grupo4');
$r->atribuir($lote, (int)$s7['id'], 'grupo4', $adm);
$s7 = $r->sessao((int)$s7['id']);
$r->atribuir($lote, (int)$s5['id'], 'grupo4', $adm); // s5 agora no grupo 4
$e8 = $r->entrar('Gabriel Fictício Melo', '10.0.0.3');
$r->escolherEquipe($e8['sessao'], 'grupo3');
$r->atribuir($lote, (int)$e8['sessao']['id'], 'grupo3', $adm);
$s8 = $r->sessao((int)$e8['sessao']['id']);

// ---------------------------------------------------------------
echo "\nT39–T40. Conclusão integral e histórico\n";
confere('T39. processamento parcial não conclui o lote', $recusa(fn () => $r->concluirLote($lote, $adm)) !== null && count($r->impedimentosConclusao($lote)) > 0);
foreach ($et->planilhaCarimbacao($lote, 'pendentes') as $item) {
    $et->marcarCarimbado($s8, (int)$item['id']);
}
$et->encaminharG4($lote, null, $adm);
$r->atualizarPendencia($pend, 'resolvida', $adm, 'Código reescrito no livro correto');
foreach ($r->pendencias('abertas', $lote) as $p) {
    $r->atualizarPendencia((int)$p['id'], 'resolvida', $adm, 'Livro encontrado');
}
$prateleira = null;
$pdo->exec("INSERT INTO estantes (setor_id, identificacao, nome) VALUES ($setor, 'EF1', 'Estante Fictícia')");
$estante = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO localizacoes (estante_id, setor, estante, prateleira, ativa) VALUES ($estante, 'Setor Teste', 'Estante Fictícia', 'P1', 1)");
$prateleira = (int)$pdo->lastInsertId();
$i = 0;
foreach ($pdo->query("SELECT x.id, x.conferido_em FROM reorg_registro_exemplares x WHERE x.lote_id = $lote AND x.removido_em IS NULL")->fetchAll() as $x) {
    $sess = $i++ % 2 ? $s7 : $r->sessao((int)$s5['id']);
    if ($x['conferido_em'] === null) {
        $et->confirmarConferencia($sess, (int)$x['id'], true, true, true, $prateleira);
    } else {
        $et->organizar($sess, (int)$x['id'], $prateleira);
    }
}
$c = $r->contadores($lote);
confere('T39. todos conferidos e organizados', $c['conferidos'] === 30 && $c['organizados'] === 30 && $c['restantes'] === 0);
confere('T25. com carimbo e estante, os exemplares ficam disponíveis (regra permanente)', $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND situacao = 'disponivel'", [$L['cadastro_lote_id']]) === 30);
$conc = $r->concluirLote($lote, $adm);
confere('T39. conclusão integral com resumo (prevista, final, obras, códigos, estados, participantes)', $conc['resumo']['quantidade_final'] === 30 && $conc['resumo']['codigos_gerados'] === 30
    && $conc['resumo']['obras_reaproveitadas'] === 1 && $conc['resumo']['obras_cadastradas'] >= 1 && array_sum($conc['resumo']['conservacao']) === 30 && count($conc['resumo']['participantes']) >= 5);
confere('T39. lote permanente encerrado junto (todos com estante)', $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$L['cadastro_lote_id']]) === 'encerrado', implode(' ', $conc['avisos']));
confere('T40. histórico com nome, sessão e administrador; preservado depois das sessões encerradas', $num('SELECT COUNT(*) FROM reorg_historico WHERE lote_id = ? AND sessao_id IS NOT NULL AND nome IS NOT NULL', [$lote]) > 20
    && $num("SELECT COUNT(*) FROM reorg_historico WHERE sessao_id = ? AND acao = 'conferencia_codigo'", [$s4['id']]) === 1);
confere('T40. histórico não pode ser alterado nem apagado', $recusa(fn () => $pdo->exec('DELETE FROM reorg_historico')) !== null && $recusa(fn () => $pdo->exec("UPDATE reorg_historico SET nome = 'x'")) !== null);
confere('T40. ações registradas: criação, atribuição, registros, aprovação, códigos, carimbação, conferência, organização, pausa, conclusão',
    $num("SELECT COUNT(DISTINCT acao) FROM reorg_historico WHERE acao IN ('lote_criado','participante_atribuido','registro_criado','registro_aprovado','codigos_gerados','carimbacao','conferencia_codigo','organizacao','atividade_pausada','lote_concluido','encaminhamento_g2','encaminhamento_g4','quantidade_alterada')") >= 12);

// ---------------------------------------------------------------
echo "\nAjuste da quantidade final\n";
$r->atribuir($lote3, (int)$s1['id'], 'grupo1', $adm);
$q = $lev->novoRegistro($s1, $lote3);
$lev->salvar($s1, (int)$q['id'], $form(['titulo' => 'Obra Fictícia do Lote 3', 'quantidade' => '3', 'aplicar_todos' => 'regular']), (int)$q['versao']);
confere('T15. ajuste abaixo do já registrado é recusado', $recusa(fn () => $r->ajustarQuantidade($lote3, 2, $adm, 'contagem errada')) !== null);
$r->ajustarQuantidade($lote3, 5, $adm, 'Havia mais dois livros atrás da estante');
$L3 = $r->lote($lote3);
confere('T15. quantidade final ajustada no lote e no lote permanente, com histórico', (int)$L3['quantidade_prevista'] === 5 && (int)$L3['quantidade_inicial'] === 3
    && (int)$val('SELECT capacidade FROM cadastro_lotes WHERE id = ?', [$L3['cadastro_lote_id']]) === 5 && $num("SELECT COUNT(*) FROM reorg_historico WHERE lote_id = ? AND acao = 'quantidade_alterada'", [$lote3]) === 1);

// ---------------------------------------------------------------
echo "\nT01/T42. Encerramento do período e funcionamento permanente\n";
$avisos = $r->encerrarPeriodo($periodo, $adm);
confere('T01. aviso ao encerrar com lotes incompletos, rascunhos ou pendências (não encerra sem confirmar)', count($avisos) > 0 && (int)$val('SELECT ativo FROM reorg_periodos WHERE id = ?', [$periodo]) === 1);
$tkAtivo = $e8['token'];
$r->encerrarPeriodo($periodo, $adm, true);
confere('T01. período encerrado: sessões abertas encerradas', $r->sessaoPorToken($tkAtivo) === null && $num("SELECT COUNT(*) FROM reorg_sessoes WHERE status = 'ativa'") === 0);
confere('T01. novas sessões não são criadas', $recusa(fn () => $r->entrar('Hugo Fictício')) !== null);
confere('T02. botão some ao encerrar', !str_contains($botao(), 'Equipe de organização'));
confere('T40. lotes, registros e histórico continuam guardados', $num('SELECT COUNT(*) FROM reorg_lotes') === 3 && $num('SELECT COUNT(*) FROM reorg_historico') > 50);
confere('T42. obras, exemplares e códigos BDP continuam no acervo (independentes do módulo)', $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$L['cadastro_lote_id']]) === 30);
confere('T42. nenhuma tabela permanente aponta para as tabelas reorg_*',
    $num("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME LIKE 'reorg\\_%' AND TABLE_NAME NOT LIKE 'reorg\\_%'") === 0);

$GLOBALS['reorg_testes_contexto'] = compact('adm', 'bib', 'senha', 'lote', 'lote2', 'lote3', 'env');
if (is_file(__DIR__ . '/reorganizacao-paginas-teste.php')) {
    require __DIR__ . '/reorganizacao-paginas-teste.php';
}

teste_resultado();
