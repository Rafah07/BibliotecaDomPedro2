<?php
/**
 * Cenário completo da reorganização (§24 do pedido de correção), num banco de DESENVOLVIMENTO
 * temporário ("bdp_teste_reorgfluxo_XXXX", apagado no final). Dados fictícios.
 *
 * Uso: php modulos/reorganizacao/tests/reorganizacao-fluxo-teste.php
 *      (com Node + Playwright instalados, também testa o navegador em 1366×768 e 1280×720;
 *       defina REORG_CAPTURAS=/pasta para guardar as capturas de tela)
 */

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Reorganizacao\RegistroConflitoException;
use Biblioteca\Reorganizacao\SessaoEncerradaException;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;

require __DIR__ . '/../../../admin/tests/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('reorgfluxo');
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/cdd.php';
$_SERVER['SCRIPT_NAME'] = '/modulos/reorganizacao/tests/reorganizacao-fluxo-teste.php';
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
$pdo->prepare("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES ('Bibliotecária Fictícia', 'adm@teste.br', ?, 'administrador')")->execute([password_hash($senha, PASSWORD_DEFAULT)]);
$adm = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES ('Auxiliar Fictício', 'aux@teste.br', ?, 'administrador')")->execute([password_hash($senha, PASSWORD_DEFAULT)]);
$aux = (int)$pdo->lastInsertId();
$usuarioAdm = ['id' => $adm, 'nome' => 'Bibliotecária Fictícia', 'perfil' => 'administrador', 'email' => 'adm@teste.br'];
$cddAdm = reorg_registrar_cdd($usuarioAdm);

$ex = new ExemplarService($pdo);
$ex->definirRegrasPreparo(false, false);
$r = new Reorganizacao($pdo, reorg_config(), $ex);
$lev = $r->levantamento();
$et = $r->etapas();

// Obra que já existe no acervo (com dados bons que não podem ser apagados) e algumas obras/exemplares permanentes.
$pdo->exec("INSERT INTO livros (titulo, subtitulo, isbn, editora, assunto, dados_verificados_em) VALUES ('Contos Fictícios do Brasil', 'Antologia', '9788535902778', 'Editora Exemplo', 'Contos brasileiros', NOW())");
$obraExistente = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO livros (titulo) VALUES ('Obra Permanente Fictícia')");
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual, situacao) VALUES (" . (int)$pdo->lastInsertId() . ", 'BDP-900001', 'disponivel')");
$pdo->exec("INSERT INTO setores (nome) VALUES ('Setor Fictício')");
$pdo->exec("INSERT INTO categorias (nome) VALUES ('Literatura Fictícia')");
$categoria = (int)$pdo->lastInsertId();

$form = fn (array $extra = []) => $extra + [
    'titulo' => 'Livro Fictício', 'autores' => 'SILVA, Ana Fictícia', 'editora' => 'Editora Teste',
    'isbn_situacao' => 'nao_encontrado', 'cdd_situacao' => 'nao_encontrada', 'quantidade' => '1', 'aplicar_todos' => 'regular',
];
$versao = fn (int $id) => (int)db_query('SELECT versao FROM reorg_registros WHERE id = ?', [$id])->fetchColumn();
$salvar = fn (array $sessao, int $id, array $dados) => $lev->salvar($sessao, $id, $dados, $versao($id));
$exemplaresAntes = $num('SELECT COUNT(*) FROM exemplares');

// =====================================================================
echo "\n1–3. Período, entrada sem conta e escolha das equipes\n";
$periodo = $r->iniciarPeriodo(['nome' => 'Reorganização Fictícia 2026', 'inicio' => date('Y-m-d'), 'instrucoes_gerais' => 'Trabalhe com calma.'], $adm);
confere('1. administrador ativa a reorganização', (int)$val('SELECT ativo FROM reorg_periodos WHERE id = ?', [$periodo]) === 1 && $r->periodoAtivo() !== null);
$ent = [];
foreach (['ana' => 'Ana Fictícia Prado', 'bia' => 'Bia Fictícia Rocha', 'caio' => 'Caio Fictício Neves', 'duda' => 'Duda Fictícia Melo', 'ana2' => 'Ana Fictícia Prado'] as $k => $nome) {
    $ent[$k] = $r->entrar($nome, '10.0.0.' . (count($ent) + 1));
}
$S = array_map(fn ($e) => $r->sessaoPorToken($e['token']), $ent);
confere('2. participantes entram só com o nome (sem conta, sem senha)', count(array_filter($S)) === 5 && $num("SELECT COUNT(*) FROM administradores") === 2);
confere('Extra: nomes idênticos viram sessões distintas, com aviso de nome repetido', $S['ana']['id'] !== $S['ana2']['id']
    && count(array_filter($r->participantesAtivos(), fn ($p) => $p['nome'] === 'Ana Fictícia Prado' && (int)$p['mesmo_nome'] === 1)) === 2);
$r->escolherEquipe($S['ana'], 'grupo1');
$r->escolherEquipe($S['bia'], 'grupo1');
$r->escolherEquipe($S['caio'], 'grupo3');
$r->escolherEquipe($S['duda'], 'grupo4');
$r->escolherEquipe($S['ana2'], 'grupo3');
$S = array_map(fn ($s) => $r->sessao((int)$s['id']), $S);
confere('3. cada participante escolhe a própria equipe (1, 3 ou 4)', $S['ana']['equipe'] === 'grupo1' && $S['bia']['equipe'] === 'grupo1' && $S['caio']['equipe'] === 'grupo3' && $S['duda']['equipe'] === 'grupo4');
confere('3. sem lote, o participante fica aguardando a atribuição', $r->lotesDaSessao((int)$S['ana']['id']) === []);

// =====================================================================
echo "\n4–5. Lote de 30 exemplares e dois participantes no Grupo 1\n";
$lote = $r->criarLote(['nome' => 'Lote Fictício — Estante 1', 'quantidade' => 30, 'responsavel_id' => $adm, 'admins' => [$aux], 'prazo' => date('Y-m-d', strtotime('+7 days')),
    'instrucoes' => 'Comece pela prateleira de cima.', 'grupo1' => [$S['ana']['id'], $S['bia']['id']], 'grupo3' => [$S['caio']['id']], 'grupo4' => [$S['duda']['id']]], $adm);
$L = $r->lote($lote);
confere('4. lote de 30 exemplares criado, com lote gerenciado permanente de capacidade 30', (int)$L['quantidade_prevista'] === 30
    && (int)$val('SELECT capacidade FROM cadastro_lotes WHERE id = ?', [$L['cadastro_lote_id']]) === 30);
confere('5. dois participantes selecionados para o Grupo 1 (e avisados)', $num("SELECT COUNT(*) FROM reorg_atribuicoes WHERE lote_id = ? AND equipe = 'grupo1' AND encerrada_em IS NULL", [$lote]) === 2
    && $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE sessao_id IN (?, ?) AND tipo = 'lote_atribuido'", [$S['ana']['id'], $S['bia']['id']]) === 2);
$outro = $r->criarLote(['nome' => 'Lote Fictício — Estante 2', 'quantidade' => 5, 'responsavel_id' => $aux], $adm);
confere('Extra: participante não acessa lote não atribuído', $recusa(fn () => $lev->novoRegistro($S['ana'], $outro)) !== null);

// =====================================================================
echo "\n6–7. Grupo 1 registra 20 exemplares (um registro com 5 exemplares da mesma obra)\n";
// A: 5 exemplares da obra existente (pesquisada no acervo). Dados levantados diferem um pouco da obra.
$A = $lev->novoRegistro($S['ana'], $lote);
$salvar($S['ana'], (int)$A['id'], $form(['titulo' => 'Contos Fictícios do Brasil', 'autores' => 'PRADO, Rui Fictício', 'editora' => 'Editora Exemplo Ltda.', 'ano_publicacao' => '2001',
    'isbn_situacao' => 'encontrado', 'isbn' => '978-85-359-0277-8', 'quantidade' => '5', 'estados' => [1 => 'excelente', 2 => 'regular', 3 => 'regular', 4 => 'excelente', 5 => 'ruim'], 'aplicar_todos' => '']));
$lev->sugerirObra((int)$A['id'], $obraExistente, $S['ana']);
$rA = $lev->registro((int)$A['id']);
confere('7. um registro com 5 exemplares da mesma obra (cada um com seu estado)', (int)$rA['quantidade'] === 5 && (int)$rA['completo'] === 1
    && $val("SELECT GROUP_CONCAT(estado_conservacao ORDER BY ordem) FROM reorg_registro_exemplares WHERE registro_id = ? AND removido_em IS NULL", [$A['id']]) === 'excelente,regular,regular,excelente,ruim');
confere('G1. obra existente escolhida pela pesquisa fica vinculada ao registro', (int)$rA['livro_sugerido_id'] === $obraExistente);
// B: obra nova com CDD encontrada (será corrigida pelo Grupo 2).
$B = $lev->novoRegistro($S['bia'], $lote);
$salvar($S['bia'], (int)$B['id'], $form(['titulo' => 'Poemas Ficticios', 'autores' => 'MELO, Clara Fictícia', 'editora' => 'Editora Nova Fictícia', 'ano_publicacao' => '2015',
    'cdd_situacao' => 'encontrada', 'cdd' => '869,1', 'cdd_fonte' => 'Ficha catalográfica', 'categoria_id' => (string)$categoria, 'quantidade' => '3']));
confere('G1. CDD com vírgula gravada com ponto; sem idioma padrão "Português"', $val('SELECT cdd FROM reorg_registros WHERE id = ?', [$B['id']]) === '869.1'
    && $val('SELECT idioma FROM reorg_registros WHERE id = ?', [$B['id']]) === null);
// C, D, E: obras novas sem ISBN/CDD; F: rascunho incompleto (2 exemplares, sem autor).
$C = $lev->novoRegistro($S['ana'], $lote);
$salvar($S['ana'], (int)$C['id'], $form(['titulo' => 'Histórias Fictícias I', 'quantidade' => '3']));
$D = $lev->novoRegistro($S['bia'], $lote);
$salvar($S['bia'], (int)$D['id'], $form(['titulo' => 'Histórias Fictícias II', 'quantidade' => '3', 'isbn_situacao' => 'encontrado', 'isbn' => '0-306-40615-2']));
$E = $lev->novoRegistro($S['ana'], $lote);
$salvar($S['ana'], (int)$E['id'], $form(['titulo' => 'Quadrinhos Fictícios', 'quantidade' => '4', 'cdd_situacao' => 'encontrada', 'cdd' => '741.5']));
$F = $lev->novoRegistro($S['bia'], $lote);
$resF = $salvar($S['bia'], (int)$F['id'], ['titulo' => 'Rascunho Fictício Incompleto', 'quantidade' => '2', 'aplicar_todos' => 'regular']);
confere('Extra: rascunho incompleto é salvo no banco (não se perde) e marcado como incompleto', !$resF['completo'] && $val('SELECT titulo FROM reorg_registros WHERE id = ?', [$F['id']]) === 'Rascunho Fictício Incompleto');
$c = $r->contadores($lote);
confere('6. 20 exemplares registrados pelos dois participantes', $c['registrados'] === 20, json_encode($c));
[, $erros] = $lev->validar($form(['autores' => '', 'editora' => '']));
confere('G1. autor e editora são obrigatórios (sem opção "não indicado")', isset($erros['autores']) && isset($erros['editora']));
[, $erros] = $lev->validar($form(['isbn_situacao' => '', 'cdd_situacao' => '']));
confere('G1. ISBN e CDD exigem "Informação encontrada" ou "não encontrada"', isset($erros['isbn']) && isset($erros['cdd']));
confere('G1. nunca passa da quantidade prevista (13 num formulário que só comporta 12)', str_contains((string)$recusa(fn () => $salvar($S['bia'], (int)$F['id'], ['titulo' => 'X', 'quantidade' => '13'])), 'no máximo 12'));

// ---- extras do Grupo 1: falha ao salvar, requisições simultâneas, sair e voltar
$vC = $versao((int)$C['id']);
$conflito = null;
try {
    $lev->salvar($S['ana'], (int)$C['id'], ['titulo' => 'Versão antiga'], $vC - 1);
} catch (RegistroConflitoException $e) {
    $conflito = $e;
}
confere('Extra: falha ao salvar (versão antiga) não grava por cima e devolve os dados atuais', $conflito !== null && ($conflito->atual['titulo'] ?? '') === 'Histórias Fictícias I'
    && $val('SELECT titulo FROM reorg_registros WHERE id = ?', [$C['id']]) === 'Histórias Fictícias I');
$tk = $ent['ana']['token'];
$cmdSalvar = fn (string $titulo) => escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . __DIR__ . '/../bootstrap.php"; $s = reorg()->sessaoPorToken("' . $tk . '"); try { reorg()->levantamento()->salvar($s, ' . (int)$C['id'] . ', ["titulo" => "' . $titulo . '", "autores" => "SILVA, Ana Fictícia", "editora" => "Editora Teste", "isbn_situacao" => "nao_encontrado", "cdd_situacao" => "nao_encontrada", "quantidade" => "3", "aplicar_todos" => "bom"], ' . $vC . '); echo "OK"; } catch (Throwable $e) { echo get_class($e); }');
$ps = [popen($cmdSalvar('Histórias Fictícias I') . ' 2>&1', 'r'), popen($cmdSalvar('Histórias Fictícias Um') . ' 2>&1', 'r')];
$saidas = array_map(function ($p) { $s = trim(stream_get_contents($p)); pclose($p); return $s; }, $ps);
confere('Extra: dois salvamentos simultâneos com a mesma versão: um grava, o outro recebe conflito', count(array_filter($saidas, fn ($s) => $s === 'OK')) === 1
    && count(array_filter($saidas, fn ($s) => str_contains($s, 'RegistroConflitoException'))) === 1 && $versao((int)$C['id']) === $vC + 1, implode(' | ', $saidas));
$salvar($S['ana'], (int)$C['id'], $form(['titulo' => 'Histórias Fictícias I', 'quantidade' => '3']));

// =====================================================================
echo "\n8. Encaminhamento parcial ao Grupo 2\n";
foreach ([$B, $D, $F] as $g) {
    $lev->liberar((int)$g['id'], $S['bia']); // a Bia fechou os formulários dela
}
$previa = $lev->previaEncaminhamento($lote);
$enc = $lev->encaminhar($lote, $S['ana']);
confere('8. só os registros completos seguem (18 exemplares); o rascunho incompleto fica (restam 12: 2 do rascunho + 10 sem levantamento)', $enc['exemplares'] === 18 && $enc['restantes'] === 12 && $previa['exemplares'] === 18, json_encode($enc));
confere('8. Grupo 1 não altera o que já foi encaminhado', $recusa(fn () => $salvar($S['ana'], (int)$C['id'], $form())) !== null);

// =====================================================================
echo "\n9–13. Grupo 2: revisão, obra existente, obra nova, CDD e códigos BDP\n";
$rB = $lev->abrir((int)$B['id'], null, $adm);
$lev->salvarRevisao($adm, (int)$B['id'], ['titulo' => 'Poemas Fictícios', 'cdd' => '869.1', 'cdd_situacao' => 'encontrada', 'idioma' => ''] + $lev->paraFormulario($rB), (int)$rB['versao']);
$lev->liberar((int)$B['id'], null, $adm);
confere('9. Grupo 2 corrige os dados, o original do Grupo 1 fica guardado', $val('SELECT titulo FROM reorg_registros WHERE id = ?', [$B['id']]) === 'Poemas Fictícios'
    && isset($lev->diferencas($lev->registro((int)$B['id']))['titulo']));
$comp = $lev->comparacaoObra($lev->registro((int)$A['id']));
confere('10. comparação campo a campo com a obra existente (editora divergente; ano só no levantamento; assunto só na obra)', $comp['campos']['editora']['divergente']
    && $comp['campos']['ano_publicacao']['escolha'] === 'levantamento' && $comp['campos']['assunto']['escolha'] === 'obra' && $comp['campos']['assunto']['levantamento'] === null);
$lev->salvarEscolhasObra($adm, (int)$A['id'], ['editora' => 'obra', 'ano_publicacao' => 'levantamento', 'autores' => 'levantamento', 'assunto' => 'levantamento']);
$lev->aprovar($adm, (int)$A['id']);
$cdA = $lev->cadastrar($adm, (int)$A['id'], $cddAdm);
$obra = db_query('SELECT * FROM livros WHERE id = ?', [$obraExistente])->fetch();
confere('10. 5 exemplares vinculados à obra existente, sem criar outra obra', !$cdA['obra_nova'] && $cdA['livro_id'] === $obraExistente && count($cdA['codigos']) === 5
    && $num('SELECT COUNT(*) FROM livros WHERE titulo = ?', ['Contos Fictícios do Brasil']) === 1);
confere('10. escolhas respeitadas: editora mantida, ano preenchido, dados bons não apagados por campos vazios', $obra['editora'] === 'Editora Exemplo' && (int)$obra['ano_publicacao'] === 2001
    && $obra['assunto'] === 'Contos brasileiros' && $obra['subtitulo'] === 'Antologia' && $obra['isbn'] === '9788535902778');
$lev->aprovar($adm, (int)$B['id'], true);
$histAntes = $num('SELECT COUNT(*) FROM classificacoes_cdd_historico');
$cdB = $lev->cadastrar($adm, (int)$B['id'], $cddAdm);
$livroB = db_query('SELECT * FROM livros WHERE id = ?', [$cdB['livro_id']])->fetch();
confere('11. obra nova cadastrada com os dados revisados (sem idioma inventado)', $cdB['obra_nova'] && $livroB['titulo'] === 'Poemas Fictícios' && $livroB['idioma'] === null && $livroB['editora'] === 'Editora Nova Fictícia');
confere('11. obra nova não fica presa em "aguardando conferência"', $livroB['status_validacao'] !== 'aguardando_conferencia', (string)$livroB['status_validacao']);
$cl = db_query('SELECT * FROM classificacoes_cdd WHERE livro_id = ?', [$cdB['livro_id']])->fetch();
confere('12. CDD 869.1 registrada na classificação permanente (aprovada) com histórico', $cl && ($cl['cdd_aprovada'] ?? null) === '869.1' && $num('SELECT COUNT(*) FROM classificacoes_cdd_historico') > $histAntes, json_encode($cl));
$cdB2 = $lev->cadastrar($adm, (int)$B['id'], $cddAdm);
confere('12. repetir o cadastro não duplica exemplares, códigos nem histórico de CDD', $cdB2['repetido'] && $cdB2['codigos'] === $cdB['codigos']
    && $num('SELECT COUNT(*) FROM classificacoes_cdd_historico WHERE livro_id = ?', [$cdB['livro_id']]) === $num('SELECT COUNT(*) FROM classificacoes_cdd_historico WHERE livro_id = ?', [$cdB['livro_id']]) && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$cdB['livro_id']]) === 3);
// E: CDD inválida no momento do cadastro → nada é cadastrado (transação única)
$massa = $lev->aprovarEmMassa($adm, $lote, [(int)$C['id'], (int)$D['id'], (int)$E['id']]);
$pdo->prepare("UPDATE reorg_registros SET cdd = '7A1.5' WHERE id = ?")->execute([$E['id']]);
$exAntes = $num('SELECT COUNT(*) FROM exemplares');
$livAntes = $num('SELECT COUNT(*) FROM livros');
$erroE = $recusa(fn () => $lev->cadastrar($adm, (int)$E['id'], $cddAdm));
confere('Extra: CDD inválida no cadastro desfaz tudo (nenhuma obra, exemplar ou código criado)', $erroE !== null && $num('SELECT COUNT(*) FROM exemplares') === $exAntes
    && $num('SELECT COUNT(*) FROM livros') === $livAntes && $val('SELECT status FROM reorg_registros WHERE id = ?', [$E['id']]) === 'aprovado', (string)$erroE);
$pdo->prepare("UPDATE reorg_registros SET cdd = '741.5' WHERE id = ?")->execute([$E['id']]);
$resto = $lev->cadastrarAprovados($adm, $lote, null, $cddAdm);
confere('13. demais aprovados cadastrados (aprovação em massa de 3 registros)', $massa['aprovados'] === 3 && !$resto['erros'], json_encode($resto['erros']));
$codigos = db_query('SELECT e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.lote_id = ?', [$lote])->fetchAll(PDO::FETCH_COLUMN);
confere('13. cada um dos 18 exemplares tem um código BDP único', count($codigos) === 18 && count(array_unique($codigos)) === 18 && $num('SELECT COUNT(*) FROM exemplares WHERE codigo_individual IN (' . implode(',', array_fill(0, 18, '?')) . ')', $codigos) === 18);
confere('13. obras novas com CDD encontrada têm a classificação gravada; sem CDD, nenhuma inventada', (string)$val("SELECT c.cdd_aprovada FROM classificacoes_cdd c JOIN livros l ON l.id = c.livro_id WHERE l.titulo = 'Quadrinhos Fictícios'") === '741.5'
    && !$val("SELECT c.cdd_aprovada FROM classificacoes_cdd c JOIN livros l ON l.id = c.livro_id WHERE l.titulo = 'Histórias Fictícias I'"));
confere('13. nenhuma obra da reorganização ficou em "aguardando conferência"', $num("SELECT COUNT(*) FROM livros l JOIN exemplares e ON e.livro_id = l.id JOIN reorg_registro_exemplares x ON x.exemplar_id = e.id WHERE x.lote_id = ? AND l.status_validacao = 'aguardando_conferencia'", [$lote]) === 0);

// =====================================================================
echo "\n14–15. Grupo 3: recebe os aprovados e confirma a carimbação\n";
$pl = $et->planilhaCarimbacao($lote, 'pendentes');
confere('14. Grupo 3 recebe os 18 exemplares aprovados, com o código BDP já gerado', count($pl) === 18 && count(array_filter($pl, fn ($x) => $x['codigo_individual'])) === 18);
confere('Extra: Grupo 3 de outro participante não carimba o que está reservado', (function () use ($et, $S, $pl, $recusa) {
    $et->reservar($S['caio'], (int)$pl[0]['id'], 'grupo3');
    return $recusa(fn () => $et->marcarCarimbado($S['ana2'], (int)$pl[0]['id'])) !== null;
})());
foreach ($pl as $item) {
    $et->marcarCarimbado($S['caio'], (int)$item['id']);
}
confere('15. carimbação confirmada nos 18 exemplares (também no cadastro permanente)', count($et->planilhaCarimbacao($lote, 'carimbados')) === 18
    && $num("SELECT COUNT(*) FROM exemplares e JOIN reorg_registro_exemplares x ON x.exemplar_id = e.id WHERE x.lote_id = ? AND e.carimbo_status = 'carimbado'", [$lote]) === 18);
$g4 = $et->encaminharG4($lote, $S['caio']);
confere('15. encaminhado ao Grupo 4', $g4['exemplares'] === 18);

// =====================================================================
echo "\n16–17. Grupo 4: digita os códigos, confere e organiza\n";
$primeiro = true;
foreach ($codigos as $codigo) {
    $digitado = $primeiro ? '  ' . strtolower($codigo) . ' ' : $codigo;
    $res = $et->conferirCodigo($S['duda'], $lote, $digitado);
    if ($primeiro) {
        confere('16. código digitado à mão (minúsculas e espaços) é localizado e mostra os dados para comparar', $res['situacao'] === 'valido' && $res['exemplar']['titulo'] !== '', json_encode($res));
        $primeiro = false;
    }
    $et->confirmarConferencia($S['duda'], (int)$res['exemplar']['id'], true, true);
    $et->organizar($S['duda'], (int)$res['exemplar']['id']);
}
confere('Extra: código BDP inexistente é avisado', $et->conferirCodigo($S['duda'], $lote, 'BDP-999998')['situacao'] === 'inexistente');
confere('Extra: código incompleto é avisado', $et->conferirCodigo($S['duda'], $lote, 'BDP-1')['situacao'] === 'formato');
confere('Extra: código de exemplar fora da reorganização é avisado', $et->conferirCodigo($S['duda'], $lote, 'BDP-900001')['situacao'] === 'fora_reorganizacao');
confere('Extra: código já conferido é avisado', $et->conferirCodigo($S['duda'], $lote, $codigos[0])['situacao'] === 'ja_conferido');
$c = $r->contadores($lote);
confere('17. 18 exemplares conferidos e organizados', $c['conferidos'] === 18 && $c['organizados'] === 18, json_encode($c));
confere('Extra: Grupo 4 lista os conferidos e organizados', count($et->exemplaresG4($lote, 'organizados')) === 18 && count($et->exemplaresG4($lote, 'aguardando')) === 0);

// =====================================================================
echo "\n18. Os 10 restantes vão para novos participantes\n";
// Bia sai e volta com o mesmo nome: nova sessão, sem levar o lote junto.
$r->sair($S['bia']);
confere('Extra: sessão encerrada não salva mais nada', (function () use ($lev, $S, $F, $versao) {
    try {
        $lev->salvar($S['bia'], (int)$F['id'], ['titulo' => 'Depois de sair'], $versao((int)$F['id']));
    } catch (SessaoEncerradaException | AcervoException $e) {
        return true;
    }
    return false;
})());
$volta = $r->entrar('Bia Fictícia Rocha', '10.0.0.9');
$bia2 = $r->sessaoPorToken($volta['token']);
confere('Extra: sair e voltar com o mesmo nome cria outra sessão, sem lote até nova atribuição', (int)$bia2['id'] !== (int)$S['bia']['id'] && $r->lotesDaSessao((int)$bia2['id']) === []);
$r->removerDoLote($lote, (int)$S['ana']['id'], $adm);
$novos = ['eva' => $r->entrar('Eva Fictícia Lins'), 'gil' => $r->entrar('Gil Fictício Paz'), 'ivo' => $r->entrar('Ivo Fictício Sá')];
$N = array_map(fn ($e) => $r->sessaoPorToken($e['token']), $novos);
$r->escolherEquipe($N['eva'], 'grupo1');
$r->escolherEquipe($N['gil'], 'grupo3');
$r->escolherEquipe($N['ivo'], 'grupo4');
$N = array_map(fn ($s) => $r->sessao((int)$s['id']), $N);
$sol = $r->solicitar($N['eva'], 'novo_lote');
$r->responderSolicitacao($sol, 'atribuir', $adm, '', ['lote_id' => $lote]);
$r->atribuir($lote, (int)$N['gil']['id'], 'grupo3', $adm);
$r->atribuir($lote, (int)$N['ivo']['id'], 'grupo4', $adm);
confere('Extra: pedido de lote respondido pelo administrador (participante atribuído ao lote)', $val('SELECT status FROM reorg_solicitacoes WHERE id = ?', [$sol]) !== 'pendente'
    && count($r->lotesDaSessao((int)$N['eva']['id'])) === 1);
$ajuda = $r->solicitar($N['eva'], 'ajuda', ['descricao' => 'Onde fica o ano de publicação?', 'lote_id' => $lote]);
$r->responderSolicitacao($ajuda, 'responder', $adm, 'Na folha de rosto ou no verso dela.');
confere('Extra: pedido de ajuda respondido e avisado ao participante', $num("SELECT COUNT(*) FROM reorg_notificacoes WHERE sessao_id = ?", [$N['eva']['id']]) >= 2);
$rF = $lev->abrir((int)$F['id'], $N['eva']);
confere('Extra: o novo participante continua o rascunho incompleto deixado no lote', $rF['titulo'] === 'Rascunho Fictício Incompleto' && !$rF['somente_leitura']);
$salvar($N['eva'], (int)$F['id'], $form(['titulo' => 'Rascunho Fictício Completo', 'quantidade' => '2']));
$G = $lev->novoRegistro($N['eva'], $lote);
confere('Extra: limite de quantidade respeitado (11 num lote com 10 vagas)', str_contains((string)$recusa(fn () => $salvar($N['eva'], (int)$G['id'], $form(['quantidade' => '11']))), 'no máximo 10'));
$salvar($N['eva'], (int)$G['id'], $form(['titulo' => 'Últimos Livros Fictícios', 'quantidade' => '10']));
$lev->liberar((int)$G['id'], $N['eva']);
$enc = $lev->encaminhar($lote, $N['eva']);
confere('18. os 10 restantes (mais o rascunho de 2) registrados e encaminhados pelos novos participantes', $enc['exemplares'] === 12 && $r->contadores($lote)['registrados'] === 30);
$lev->aprovarEmMassa($adm, $lote, [(int)$F['id'], (int)$G['id']]);
$cad = $lev->cadastrarAprovados($adm, $lote, null, $cddAdm);
foreach ($et->planilhaCarimbacao($lote, 'pendentes') as $item) {
    $et->marcarCarimbado($N['gil'], (int)$item['id']);
}
$et->encaminharG4($lote, $N['gil']);
foreach ($et->exemplaresG4($lote, 'aguardando') as $x) {
    $res = $et->conferirCodigo($N['ivo'], $lote, (string)$x['codigo_individual']);
    $et->confirmarConferencia($N['ivo'], (int)$res['exemplar']['id'], true, true, true);
}
$c = $r->contadores($lote);
confere('18. 30 exemplares com código, carimbados, conferidos e organizados', $c['codigos'] === 30 && $c['conferidos'] === 30 && $c['organizados'] === 30 && !$cad['erros'], json_encode($c));
$todos = db_query('SELECT e.codigo_individual FROM reorg_registro_exemplares x JOIN exemplares e ON e.id = x.exemplar_id WHERE x.lote_id = ?', [$lote])->fetchAll(PDO::FETCH_COLUMN);
confere('13/18. 30 códigos BDP, todos diferentes', count(array_unique($todos)) === 30);

// =====================================================================
echo "\n19–20. Conclusão do lote e encerramento do período\n";
confere('19. nenhum impedimento para concluir', $r->impedimentosConclusao($lote) === [], implode(' ', $r->impedimentosConclusao($lote)));
$conclusao = $r->concluirLote($lote, $adm);
confere('19. lote concluído', $val('SELECT status FROM reorg_lotes WHERE id = ?', [$lote]) === 'concluido');
confere('19. exemplares organizados sem estante cadastrada ficam avisados (aguardam a Organização Física)', str_contains(implode(' ', $conclusao['avisos']), 'sem estante'), json_encode($conclusao['avisos']));
// criação de vários lotes ao mesmo tempo (processos separados)
$cmdLote = fn (int $k) => escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . __DIR__ . '/../bootstrap.php"; try { echo reorg()->criarLote(["nome" => "Lote Simultâneo ' . $k . '", "quantidade" => 3, "responsavel_id" => ' . $adm . '], ' . $adm . '); } catch (Throwable $e) { echo "ERRO:" . $e->getMessage(); }');
$ps = [];
foreach (range(1, 5) as $k) {
    $ps[] = popen($cmdLote($k) . ' 2>&1', 'r');
}
$ids = array_map(function ($p) { $s = trim(stream_get_contents($p)); pclose($p); return $s; }, $ps);
confere('Extra: 5 lotes criados ao mesmo tempo, cada um com seu lote permanente', count(array_filter($ids, 'ctype_digit')) === 5
    && $num("SELECT COUNT(DISTINCT cadastro_lote_id) FROM reorg_lotes WHERE nome LIKE 'Lote Simultâneo %'") === 5, implode(' | ', $ids));
$avisos = $r->encerrarPeriodo($periodo, $adm);
$r->encerrarPeriodo($periodo, $adm, true);
confere('20. período desativado (com avisos antes de confirmar, pois há lotes abertos)', $avisos !== [] && (int)$val('SELECT ativo FROM reorg_periodos WHERE id = ?', [$periodo]) === 0 && $r->periodoAtivo() === null);
confere('20. ninguém mais entra depois do encerramento', $recusa(fn () => $r->entrar('Nova Pessoa Fictícia')) !== null);
confere('20. nada foi apagado: registros, histórico e exemplares preservados', $num('SELECT COUNT(*) FROM reorg_registros WHERE lote_id = ?', [$lote]) === 7
    && $num('SELECT COUNT(*) FROM reorg_historico WHERE lote_id = ?', [$lote]) > 20 && $num('SELECT COUNT(*) FROM exemplares') === $exemplaresAntes + 30);

// =====================================================================
echo "\n21. Inventário e funcionalidades permanentes\n";
$base = teste_servidor($env);
$nav = new TesteNavegador($base);
confere('21. administrador entra no painel', $nav->entrar('adm@teste.br', $senha) === 200);
$livroCod = (int)$val('SELECT id FROM exemplares WHERE codigo_individual = ?', [$todos[0]]);
foreach (['admin/index.php', 'admin/livros.php', 'admin/exemplares.php', 'admin/inventario.php', 'admin/planilha-bdp.php', 'admin/lotes.php', 'admin/lote.php?id=' . $L['cadastro_lote_id'],
          'admin/emprestimos.php', 'admin/classificacao.php', 'admin/relatorios.php', 'admin/organizacao-fisica.php', 'admin/detalhes-livro.php?id=' . $cdB['livro_id'], 'admin/detalhes-exemplar.php?id=' . $livroCod,
          'admin/configuracoes.php', 'admin/categorias.php', 'admin/alunos.php'] as $pagina) {
    [$s, $html] = $nav->ir($pagina);
    confere("21. $pagina funciona normalmente", $s === 200 && teste_sem_erro_tecnico($html), $s . ' ' . mb_substr(strip_tags($html), 0, 200));
}
[$s, $html] = $nav->ir('admin/exemplares.php?q=' . rawurlencode($todos[0]));
confere('21. exemplar da reorganização aparece no cadastro permanente pelo código BDP', $s === 200 && str_contains($html, $todos[0]));
foreach (['admin/', 'admin/lotes.php?status=concluido', 'admin/lote.php?id=' . $lote, 'admin/lote.php?id=' . $lote . '&aba=historico', 'admin/historico.php?lote_id=' . $lote, 'admin/configuracoes.php'] as $pagina) {
    [$s, $html] = $nav->ir('modulos/reorganizacao/' . $pagina);
    confere("Extra: depois do encerramento, $pagina continua consultável", $s === 200 && teste_sem_erro_tecnico($html), $s . ' ' . mb_substr(strip_tags($html), 0, 200));
}

// =====================================================================
echo "\nNavegador (Chromebook 1366×768 e 1280×720, perda de conexão)\n";
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$temPlaywright = $node !== '' && trim((string)shell_exec(escapeshellarg($node) . ' -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
if (!$temPlaywright) {
    echo "  (Node/Playwright não instalados: parte do navegador não executada)\n";
} else {
    $p2 = $r->iniciarPeriodo(['nome' => 'Período do navegador', 'instrucoes_gerais' => 'Instruções fictícias do navegador.'], $adm);
    $baseNav = teste_servidor($env + ['REORGANIZACAO_INTERVALO_SINAL' => '5', 'PHP_CLI_SERVER_WORKERS' => '4']);
    $estado = sys_get_temp_dir() . '/reorg-pw-' . getmypid() . '.json';
    $cfg = ['base' => $baseNav, 'estado' => $estado, 'senha' => $senha, 'capturas' => getenv('REORG_CAPTURAS') ?: '', 'maxEsperado' => 12];
    $js = __DIR__ . '/navegador.js';
    $ultimoJson = function (string $texto) {
        $linhas = array_values(array_filter(explode("\n", $texto), fn ($l) => str_starts_with($l, '{')));
        $j = $linhas ? json_decode(end($linhas), true) : null;
        return is_array($j) ? $j : ['erro' => mb_substr($texto, -1500)];
    };
    // Participante: o processo do navegador avisa quando está na tela de espera; então o lote é criado.
    $proc = proc_open([$node, $js, 'participante', json_encode($cfg)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $linha = (string)fgets($pipes[1]);
    $e1 = json_decode($linha, true) ?: ['erro' => $linha . stream_get_contents($pipes[2])];
    confere('Navegador: nome → instruções → equipe → "Aguardando a atribuição de um lote pelo administrador."', ($e1['espera'] ?? false) === true, json_encode($e1));
    $sessNav = db_query("SELECT * FROM reorg_sessoes WHERE nome = 'Participante Navegador' AND status = 'ativa' ORDER BY id DESC LIMIT 1")->fetch();
    $loteNav = $sessNav ? $r->criarLote(['nome' => 'Lote do Navegador', 'quantidade' => 12, 'responsavel_id' => $adm, 'grupo1' => [(int)$sessNav['id']]], $adm) : 0;
    fwrite($pipes[0], "ok\n");
    fclose($pipes[0]);
    $e2 = $ultimoJson(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    proc_close($proc);
    $regNav = db_query('SELECT * FROM reorg_registros WHERE lote_id = ? ORDER BY id LIMIT 1', [$loteNav])->fetch() ?: [];
    confere('Navegador: o lote aparece sozinho, sem recarregar a página', ($e2['loteApareceu'] ?? false) === true, json_encode($e2));
    confere('Navegador: salvamento automático confirmado ("Todas as alterações salvas")', ($e2['salvo'] ?? false) === true && ($regNav['titulo'] ?? '') === 'Livro do Navegador', json_encode($e2));
    confere('Navegador: sem conexão, o indicador avisa e o rascunho fica guardado no navegador', ($e2['offlineIndicador'] ?? false) === true && ($e2['rascunhoLocal'] ?? false) === true, json_encode($e2));
    confere('Navegador: ao voltar a conexão, o que foi digitado sem conexão chega ao servidor', ($e2['sincronizou'] ?? false) === true && ($regNav['autores'] ?? '') === 'OFFLINE, Teste Fictício', json_encode($e2));
    confere('Navegador: quantidade limitada ao que cabe no lote (12)', ($e2['quantidadeLimitada'] ?? false) === true, json_encode($e2));
    confere('Navegador: nenhum erro de JavaScript na página do participante', ($e2['errosJs'] ?? ['?']) === [], json_encode($e2['errosJs'] ?? null));
    // Layout nas resoluções de Chromebook
    $paginasAdmin = ['modulos/reorganizacao/admin/', 'modulos/reorganizacao/admin/lotes.php', 'modulos/reorganizacao/admin/lote-novo.php', 'modulos/reorganizacao/admin/participantes.php',
        'modulos/reorganizacao/admin/solicitacoes.php', 'modulos/reorganizacao/admin/historico.php', 'modulos/reorganizacao/admin/configuracoes.php', 'modulos/reorganizacao/admin/registro.php?id=' . (int)$B['id']];
    foreach (['visao', 'g1', 'g2', 'g3', 'g4', 'participantes', 'pendencias', 'historico'] as $aba) {
        $paginasAdmin[] = 'modulos/reorganizacao/admin/lote.php?id=' . $lote . '&aba=' . $aba;
    }
    $paginasEquipe = ['modulos/reorganizacao/equipe/', 'modulos/reorganizacao/equipe/?lote=' . $loteNav . ($regNav ? '&reg=' . (int)$regNav['id'] : '')];
    $saida = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($js) . ' layout ' . escapeshellarg(json_encode($cfg + compact('paginasAdmin', 'paginasEquipe'))) . ' 2>&1');
    $e3 = $ultimoJson($saida);
    foreach (($e3['paginas'] ?? []) as $p) {
        confere('Layout ' . $p['tela'] . ': ' . $p['pagina'] . ' sem rolagem lateral', $p['ok'], json_encode($p));
    }
    confere('Layout: ' . (count($paginasAdmin) + count($paginasEquipe)) . ' páginas verificadas em cada resolução', count($e3['paginas'] ?? []) === 2 * (count($paginasAdmin) + count($paginasEquipe)), json_encode($e3));
    @unlink($estado);
}

$avisosPhp = [];
foreach ($GLOBALS['teste_temporarios'] ?? [] as $log) {
    foreach (is_file($log) ? file($log) : [] as $linha) {
        if (preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|\[reorganizacao\]/', $linha)) {
            $avisosPhp[] = trim($linha);
        }
    }
}
confere('Nenhum aviso ou erro do PHP no servidor', !$avisosPhp, implode("\n", array_slice(array_unique($avisosPhp), 0, 10)));

teste_resultado();
