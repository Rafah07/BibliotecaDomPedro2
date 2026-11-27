<?php
/**
 * Processo completo do Inventário com a organização por equipes (migrações 240 e 250), em 17 passos:
 *
 *   1. criar inventário · 2. ativar as equipes · 3. conectar participantes · 4. criar UM lote ·
 *   5. atribuir participantes · 6. registro parcial · 7. encaminhar · 8. revisão e cadastro ·
 *   9. códigos BDP · 10. carimbação · 11. conferência do código · 12. organização ·
 *   13. indicadores do inventário · 14. concluir o lote · 15. desativar as equipes ·
 *   16. continuar usando o inventário · 17. nada permanente depende do módulo
 *
 * Uso: php modulos/reorganizacao/tests/inventario-equipes-teste.php
 * Banco temporário "bdp_teste_inveq_XXXX" (apagado no final), dados FICTÍCIOS. O banco do .env não é usado.
 */

use Biblioteca\Reorganizacao\Reorganizacao;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\OperacaoService;

require __DIR__ . '/../../../admin/tests/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('inveq');
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/cdd.php';
$_SERVER['SCRIPT_NAME'] = '/modulos/reorganizacao/tests/inventario-equipes-teste.php';
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
$csrfDe = fn (string $html) => preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';

$senha = 'SenhaDeTeste123';
$pdo->prepare("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES ('Bibliotecária Fictícia', 'adm@teste.br', ?, 'administrador')")->execute([password_hash($senha, PASSWORD_DEFAULT)]);
$adm = (int)$pdo->lastInsertId();
$cddAdm = reorg_registrar_cdd(['id' => $adm, 'nome' => 'Bibliotecária Fictícia', 'perfil' => 'administrador', 'email' => 'adm@teste.br']);
// Acervo antigo: uma obra da planilha ainda sem exemplar (deve ficar "Obra localizada" quando a equipe cadastrar)
$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Obra Permanente Fictícia', NOW())");
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual, situacao) VALUES (" . (int)$pdo->lastInsertId() . ", 'BDP-900001', 'disponivel')");
$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Obra da Planilha Fictícia', NOW())");
$obraPlanilha = (int)$pdo->lastInsertId();

$ex = new ExemplarService($pdo);
$ex->definirRegrasPreparo(false, false);
$r = new Reorganizacao($pdo, reorg_config(), $ex);
$lev = $r->levantamento();
$et = $r->etapas();
$form = fn (array $extra = []) => $extra + [
    'titulo' => 'Livro Fictício', 'autores' => 'SILVA, Ana Fictícia', 'editora' => 'Editora Teste',
    'isbn_situacao' => 'nao_encontrado', 'cdd_situacao' => 'nao_encontrada', 'quantidade' => '1', 'aplicar_todos' => 'regular',
];
$versao = fn (int $id) => (int)db_query('SELECT versao FROM reorg_registros WHERE id = ?', [$id])->fetchColumn();

$base = teste_servidor($env);
$nav = new TesteNavegador($base);
confere('Administrador entra no painel', $nav->entrar('adm@teste.br', $senha) === 200);

// =====================================================================
echo "\n1–2. Inventário criado com a organização por equipes ativada\n";
[, $html] = $nav->ir('admin/inventario-editar.php');
confere('2. formulário do inventário oferece "Ativar organização por equipes"', str_contains($html, 'Ativar organização por equipes') && str_contains($html, 'name="equipes_ativas"'));
[$s, , $loc] = $nav->ir('admin/inventario-editar.php', ['csrf' => $csrfDe($html), 'titulo' => 'Inventário Fictício 2026', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => (string)$adm,
    'setor' => '', 'tipo' => 'inicial', 'descricao' => '', 'equipes_form' => '1', 'equipes_ativas' => '1']);
$inv = (int)$val("SELECT id FROM inventarios WHERE titulo = 'Inventário Fictício 2026'");
confere('1. inventário criado (em preparação)', $inv > 0 && in_array($s, [302, 303], true) && $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'aberto', "$s $loc");
$periodo = $r->periodoAtivo();
confere('2. equipes ativas e ligadas a este inventário (período com inventario_id)', $periodo && (int)$periodo['inventario_id'] === $inv);
[, $html] = $nav->ir('admin/inventario-detalhes.php?id=' . $inv);
$nav->ir('admin/inventario-detalhes.php', ['csrf' => $csrfDe($html), 'id' => (string)$inv, 'acao' => 'iniciar']);
confere('1. conferência do inventário iniciada', $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'em_andamento');
// Linha da planilha original ainda sem exemplar (como as importadas): pendente até a obra ser encontrada.
$pdo->prepare('INSERT INTO inventario_itens (inventario_id, exemplar_id, livro_id, previsto) VALUES (?, NULL, ?, 1)')->execute([$inv, $obraPlanilha]);
[$s, $html] = $nav->ir('admin/inventario-detalhes.php?id=' . $inv);
confere('Navegação: abas do inventário com as das equipes (Visão geral, Lotes, Equipes, Conferência, Pendências e solicitações, Histórico, Configurações)',
    $s === 200 && count(array_filter(['Visão geral', 'Lotes', 'Equipes e participantes', 'Conferência', 'Pendências e solicitações', 'Histórico', 'Configurações'], fn ($t) => str_contains($html, '</svg> ' . $t))) === 7);
[, $html] = $nav->ir('admin/index.php');
confere('Navegação: o menu principal não tem a Central separada', !str_contains($html, 'modulos/reorganizacao/admin/'));
confere('Extra: só um inventário por vez usa as equipes', str_contains((string)$recusa(function () use ($pdo, $r, $adm) {
    $pdo->exec("INSERT INTO inventarios (titulo, status) VALUES ('Outro inventário fictício', 'aberto')");
    $r->ativarEquipesNoInventario((int)$pdo->lastInsertId(), [], $adm);
}), 'já está ativa'));

// =====================================================================
echo "\n3. Participantes entram só com o nome\n";
$S = [];
foreach (['ana' => ['Ana Fictícia Prado', 'grupo1'], 'caio' => ['Caio Fictício Reis', 'grupo3'], 'duda' => ['Duda Fictícia Melo', 'grupo4']] as $k => [$nome, $eq]) {
    $e = $r->entrar($nome, '10.0.0.' . (count($S) + 1));
    $s = $r->sessaoPorToken($e['token']);
    $r->escolherEquipe($s, $eq);
    $S[$k] = $r->sessao((int)$s['id']);
}
confere('3. três participantes conectados (sem conta), aguardando lote', count($r->participantesAtivos()) === 3 && $r->lotesDaSessao((int)$S['ana']['id']) === []);

// =====================================================================
echo "\n4–5. Um lote só, pelo formulário único, com os participantes\n";
$lotesAntes = $num('SELECT COUNT(*) FROM cadastro_lotes');
[$s, $html] = $nav->ir('admin/lote-novo.php?inventario=' . $inv);
confere('4. formulário único mostra os campos do lote e os participantes das equipes', $s === 200 && str_contains($html, 'Nome do lote') && str_contains($html, 'Prazo') && str_contains($html, 'Participantes das equipes')
    && str_contains($html, 'Ana Fictícia Prado') && teste_sem_erro_tecnico($html));
[$s, , $loc] = $nav->ir('admin/lote-novo.php', ['csrf' => $csrfDe($html), 'chave' => OperacaoService::novaChave(), 'inventario_id' => (string)$inv, 'nome' => 'Estante A — fictícia',
    'capacidade' => '10', 'responsavel_id' => (string)$adm, 'prazo' => date('Y-m-d', strtotime('+5 days')), 'instrucoes' => 'Comece pela prateleira de cima', 'observacoes' => 'Lote do teste',
    'equipes_lote' => '1', 'grupo1' => [(string)$S['ana']['id']], 'grupo3' => [(string)$S['caio']['id']], 'grupo4' => [(string)$S['duda']['id']]]);
$cad = (int)$val("SELECT id FROM cadastro_lotes WHERE nome = 'Estante A — fictícia'");
$lote = (int)$val('SELECT id FROM reorg_lotes WHERE cadastro_lote_id = ?', [$cad]);
confere('4. UM lote permanente criado, no inventário, com nome, prazo e instruções', $num('SELECT COUNT(*) FROM cadastro_lotes') === $lotesAntes + 1 && $cad > 0
    && (int)$val('SELECT inventario_id FROM cadastro_lotes WHERE id = ?', [$cad]) === $inv && $val('SELECT instrucoes FROM cadastro_lotes WHERE id = ?', [$cad]) === 'Comece pela prateleira de cima');
confere('4. o mesmo lote ganhou a área das equipes (sem segundo lote) e abre nela', $lote > 0 && $num('SELECT COUNT(*) FROM reorg_lotes WHERE cadastro_lote_id = ?', [$cad]) === 1
    && str_contains((string)$loc, 'modulos/reorganizacao/admin/lote.php?id=' . $lote), (string)$loc);
confere('5. participantes atribuídos (cada um vê só o próprio lote)', count($r->lotesDaSessao((int)$S['ana']['id'])) === 1 && count($r->lotesDaSessao((int)$S['caio']['id'])) === 1 && count($r->lotesDaSessao((int)$S['duda']['id'])) === 1);
[$s, $html] = $nav->ir('admin/inventario-lotes.php?id=' . $inv);
confere('4. aba Lotes do inventário lista o lote com as equipes', $s === 200 && str_contains($html, 'Lote 001 · Estante A — fictícia') && str_contains($html, 'Equipes') && teste_sem_erro_tecnico($html));
[$s, $html] = $nav->ir('admin/lote.php?id=' . $cad);
confere('4. relatório permanente do lote mostra as abas das equipes e fica dentro do inventário', $s === 200 && str_contains($html, 'G1 Levantamento') && str_contains($html, 'Relatório e Planilha BDP') && str_contains($html, 'adm-inv-abas'));
[$s, $html] = $nav->ir('modulos/reorganizacao/admin/lote.php?id=' . $lote);
confere('4. área das equipes do lote dentro das abas do inventário', $s === 200 && str_contains($html, 'adm-inv-abas') && str_contains($html, 'Inventário Fictício 2026') && teste_sem_erro_tecnico($html));
$segundo = $r->ativarEquipesNoLote($cad, [], $adm);
confere('Extra: ativar as equipes de novo no mesmo lote não duplica', $segundo === $lote && $num('SELECT COUNT(*) FROM reorg_lotes') === 1);

// =====================================================================
echo "\n6–7. Registro parcial e encaminhamento\n";
$A = $lev->novoRegistro($S['ana'], $lote);
$lev->salvar($S['ana'], (int)$A['id'], $form(['titulo' => 'Obra da Planilha Fictícia', 'quantidade' => '2']), $versao((int)$A['id']));
$lev->sugerirObra((int)$A['id'], $obraPlanilha, $S['ana']);
$B = $lev->novoRegistro($S['ana'], $lote);
$lev->salvar($S['ana'], (int)$B['id'], $form(['titulo' => 'Poemas Fictícios', 'quantidade' => '4', 'cdd_situacao' => 'encontrada', 'cdd' => '869.1', 'cdd_fonte' => 'Ficha catalográfica']), $versao((int)$B['id']));
$lev->liberar((int)$A['id'], $S['ana']);
$lev->liberar((int)$B['id'], $S['ana']);
$c = $r->contadores($lote);
confere('6. registro parcial: 6 de 10 exemplares registrados', $c['registrados'] === 6 && $c['aguardando_levantamento'] === 4, json_encode($c));
$exAntes = $num('SELECT COUNT(*) FROM exemplares');
confere('8/indicadores. registro temporário não é exemplar oficial antes da aprovação', $num('SELECT COUNT(*) FROM exemplares') === $exAntes && $num("SELECT COUNT(*) FROM exemplares e JOIN cadastro_lotes c ON c.id = e.lote_id WHERE c.inventario_id = ?", [$inv]) === 0);
$enc = $lev->encaminhar($lote, $S['ana']);
confere('7. encaminhamento parcial (6 exemplares) para a revisão', $enc['exemplares'] === 6);
[$s, $html] = $nav->ir('admin/inventario-conferencia.php?id=' . $inv);
confere('7. a conferência do inventário mostra o levantamento aguardando revisão, com o lote de origem', $s === 200 && str_contains($html, 'Levantamento das equipes aguardando revisão') && str_contains($html, 'Lote 001 · Estante A — fictícia')
    && str_contains($html, 'Poemas Fictícios'));
confere('Navegação: "Abrir novo lote" da conferência leva ao formulário único', str_contains($html, 'lote-novo.php?inventario=' . $inv));

// =====================================================================
echo "\n8–9. Revisão, cadastro e códigos BDP\n";
$pdo->prepare("UPDATE inventarios SET status = 'pausado' WHERE id = ?")->execute([$inv]);
$lev->aprovar($adm, (int)$B['id'], true);
$erroPausa = $recusa(fn () => $lev->cadastrar($adm, (int)$B['id'], $cddAdm));
confere('8. com o inventário pausado, o cadastro não acontece (nada pela metade)', $erroPausa !== null && str_contains($erroPausa, 'pausado') && $num('SELECT COUNT(*) FROM exemplares') === $exAntes, (string)$erroPausa);
$pdo->prepare("UPDATE inventarios SET status = 'em_andamento' WHERE id = ?")->execute([$inv]);
$cdB = $lev->cadastrar($adm, (int)$B['id'], $cddAdm);
$lev->aprovar($adm, (int)$A['id']);
$cdA = $lev->cadastrar($adm, (int)$A['id'], $cddAdm);
$codigos = array_merge($cdB['codigos'], $cdA['codigos']);
confere('9. códigos BDP gerados pelo gerador permanente, sem repetição', count($codigos) === 6 && count(array_unique($codigos)) === 6
    && $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$cad]) === 6);
confere('8. CDD gravada na classificação permanente', (string)$val('SELECT cdd_aprovada FROM classificacoes_cdd WHERE livro_id = ?', [$cdB['livro_id']]) === '869.1');
confere('8. cadastrados entram no inventário como encontrados (uma vez cada)', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND cadastrado_no_inventario = 1 AND conferido_em IS NOT NULL', [$inv]) === 6
    && $num('SELECT COUNT(*) FROM inventario_itens i JOIN exemplares e ON e.id = i.exemplar_id WHERE i.inventario_id = ? AND e.lote_id = ?', [$inv, $cad]) === 6);
confere('8. obra revisada e aprovada não fica "aguardando conferência" (linhas da obra = obra localizada / encontradas)',
    $cdA['livro_id'] === $obraPlanilha && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND livro_id = ? AND conferido_em IS NULL AND obra_localizada_em IS NULL', [$inv, $obraPlanilha]) === 0
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND livro_id = ? AND exemplar_id IS NULL AND obra_localizada_em IS NOT NULL', [$inv, $obraPlanilha]) === 1);
$cdB2 = $lev->cadastrar($adm, (int)$B['id'], $cddAdm);
confere('Extra: cadastrar de novo não duplica exemplares, códigos nem itens do inventário', $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$cad]) === 6
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND cadastrado_no_inventario = 1', [$inv]) === 6);

// =====================================================================
echo "\n10–12. Carimbação, conferência do código e organização\n";
$pl = $et->planilhaCarimbacao($lote, 'pendentes');
confere('10. planilha do Grupo 3 alimentada pelos dados oficiais (códigos e conservação)', count($pl) === 6 && count(array_filter($pl, fn ($x) => $x['codigo_individual'] && $x['estado_conservacao'])) === 6);
foreach (array_slice($pl, 0, 4) as $item) {
    $et->marcarCarimbado($S['caio'], (int)$item['id']);
}
confere('10. fluxo parcial: 4 de 6 carimbados (também no cadastro permanente)', $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND carimbo_status = 'carimbado'", [$cad]) === 4);
$g4 = $et->encaminharG4($lote, $S['caio']);
confere('10. 4 encaminhados ao Grupo 4; 2 continuam na carimbação', $g4['exemplares'] === 4 && count($et->planilhaCarimbacao($lote, 'pendentes')) === 2, json_encode($g4));
foreach (array_slice($pl, 0, 4) as $item) {
    $res = $et->conferirCodigo($S['duda'], $lote, strtolower((string)$item['codigo_individual']));
    if ($item === $pl[0]) {
        confere('11. código BDP digitado à mão é validado', $res['situacao'] === 'valido');
    }
    $et->confirmarConferencia($S['duda'], (int)$res['exemplar']['id'], true, true);
    $et->organizar($S['duda'], (int)$res['exemplar']['id']);
}
$c = $r->contadores($lote);
confere('12. 4 conferidos e organizados', $c['conferidos'] === 4 && $c['organizados'] === 4, json_encode($c));
foreach (array_slice($pl, 4) as $item) {
    $et->marcarCarimbado($S['caio'], (int)$item['id']);
}
$et->encaminharG4($lote, $S['caio']);
foreach (array_slice($pl, 4) as $item) {
    $res = $et->conferirCodigo($S['duda'], $lote, (string)$item['codigo_individual']);
    $et->confirmarConferencia($S['duda'], (int)$res['exemplar']['id'], true, true);
    $et->organizar($S['duda'], (int)$res['exemplar']['id']);
}

// =====================================================================
echo "\n13. Indicadores do inventário, sem contagem dupla\n";
$tot = lotes_repo_teste()->totaisInventario($inv);
confere('13. lotes do inventário: 10 previstos, 6 cadastrados, 6 carimbados, contados uma vez', $tot['previstos'] === 10 && $tot['cadastrados'] === 6 && $tot['carimbados'] === 6, json_encode($tot));
$res = (new Biblioteca\Repositories\InventarioRepository($pdo))->resumo($inv);
confere('13. inventário: 6 exemplares cadastrados na conferência', (int)$res['cadastrados'] === 6, json_encode(array_intersect_key($res, array_flip(['cadastrados', 'encontrados', 'itens']))));
$tEq = $r->totaisInventario($inv);
confere('13. números temporários das equipes separados dos oficiais', $tEq['aguardando_revisao'] === 0 && $tEq['conferidos'] === 6 && $tEq['lotes'] === 1, json_encode($tEq));
[$s, $html] = $nav->ir('admin/inventario-detalhes.php?id=' . $inv);
confere('13. Visão geral mostra os lotes (previstos, cadastrados com código BDP, carimbados) e as equipes à parte', $s === 200 && str_contains($html, 'Lotes deste inventário') && str_contains($html, 'Cadastrados com código BDP')
    && str_contains($html, 'Em levantamento (G1)') && teste_sem_erro_tecnico($html));

// =====================================================================
echo "\n14. Conclusão do lote\n";
$imp = $r->impedimentosConclusao($lote);
confere('14. o lote só conclui com tudo pronto (4 exemplares ainda não registrados impedem)', $imp !== []);
$r->ajustarQuantidade($lote, 6, $adm, 'Só havia 6 livros nesta estante');
confere('14. ajuste da quantidade acompanha o lote permanente', (int)$val('SELECT capacidade FROM cadastro_lotes WHERE id = ?', [$cad]) === 6);
$conc = $r->concluirLote($lote, $adm);
confere('14. lote concluído pelas equipes', $val('SELECT status FROM reorg_lotes WHERE id = ?', [$lote]) === 'concluido');

// =====================================================================
echo "\n15. Desativar as equipes (o inventário continua)\n";
[, $html] = $nav->ir('admin/inventario-editar.php?id=' . $inv);
confere('15. configuração do inventário mostra a opção marcada', str_contains($html, 'name="equipes_ativas" value="1" checked'));
$nav->ir('admin/inventario-editar.php', ['csrf' => $csrfDe($html), 'id' => (string)$inv, 'titulo' => 'Inventário Fictício 2026', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => (string)$adm,
    'tipo' => 'inicial', 'descricao' => '', 'equipes_form' => '1', 'equipes_confirmar' => '1']);
confere('15. equipes desativadas: período encerrado e sessões encerradas', $r->periodoAtivo() === null && $num("SELECT COUNT(*) FROM reorg_sessoes WHERE status = 'ativa'") === 0);
confere('15. o inventário continua em andamento', $val('SELECT status FROM inventarios WHERE id = ?', [$inv]) === 'em_andamento');
[$s, $html] = $nav->ir('admin/inventario-detalhes.php?id=' . $inv);
confere('15. sem as equipes, as abas temporárias somem e o histórico continua acessível', $s === 200 && !str_contains($html, 'Equipes e participantes') && str_contains($html, 'Histórico das equipes'));

// =====================================================================
echo "\n16. Continuar usando o inventário\n";
[, $html] = $nav->ir('admin/lote-novo.php?inventario=' . $inv);
confere('16. formulário de lote sem os campos das equipes', !str_contains($html, 'Participantes das equipes'));
$nav->ir('admin/lote-novo.php', ['csrf' => $csrfDe($html), 'chave' => OperacaoService::novaChave(), 'inventario_id' => (string)$inv, 'nome' => 'Estante B — sem equipes', 'capacidade' => '3', 'responsavel_id' => (string)$adm]);
$cad2 = (int)$val("SELECT id FROM cadastro_lotes WHERE nome = 'Estante B — sem equipes'");
confere('16. lote comum criado no inventário, sem área das equipes', $cad2 > 0 && !$val('SELECT 1 FROM reorg_lotes WHERE cadastro_lote_id = ?', [$cad2]) && (int)$val('SELECT inventario_id FROM cadastro_lotes WHERE id = ?', [$cad2]) === $inv);
$pdo->prepare('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?')->execute([$cad2, $adm]);
$invServ = new Biblioteca\Services\InventarioService($pdo, $ex, new Biblioteca\Repositories\InventarioRepository($pdo));
$criados = $invServ->cadastrarExemplar($inv, (int)$cdB['livro_id'], ['estado_conservacao' => 'regular', 'situacao' => 'disponivel'], $adm);
confere('16. conferência permanente cadastra exemplar no lote comum e no inventário', count($criados) === 1 && (int)$val('SELECT lote_id FROM exemplares WHERE id = ?', [$criados[0]['id']]) === $cad2
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$inv, $criados[0]['id']]) === 1);
[$s, $html] = $nav->ir('admin/lote.php?id=' . $cad2);
confere('16. relatório do lote comum sem abas das equipes', $s === 200 && !str_contains($html, 'G1 Levantamento') && teste_sem_erro_tecnico($html));

// =====================================================================
echo "\n17. Nada permanente depende do módulo\n";
$baseOff = teste_servidor($env + ['REORGANIZACAO_MODULO_ATIVO' => 'false']);
$off = new TesteNavegador($baseOff);
$off->entrar('adm@teste.br', $senha);
foreach (['admin/inventario.php', 'admin/inventario-detalhes.php?id=' . $inv, 'admin/inventario-lotes.php?id=' . $inv, 'admin/inventario-conferencia.php?id=' . $inv, 'admin/inventario-editar.php?id=' . $inv,
    'admin/lote-novo.php?inventario=' . $inv, 'admin/lote.php?id=' . $cad, 'admin/lotes.php', 'admin/planilha-bdp.php?lote=' . $cad, 'admin/exemplares.php?q=' . rawurlencode($codigos[0])] as $pg) {
    [$s, $html] = $off->ir($pg);
    confere("17. módulo desligado: $pg abre sem erro e sem nada das equipes", $s === 200 && teste_sem_erro_tecnico($html) && !str_contains($html, 'modulos/reorganizacao') && !str_contains($html, 'Organização por equipes'),
        $s . ' ' . mb_substr(strip_tags($html), 0, 200));
}
[$s, $html] = $off->ir('admin/lote.php?id=' . $cad);
confere('17. lote das equipes continua com exemplares, códigos BDP e nome no sistema permanente', str_contains($html, $codigos[0]) && str_contains($html, 'Estante A — fictícia'));
[$s] = $off->ir('modulos/reorganizacao/admin/participantes.php');
confere('17. páginas do módulo respondem 404 com ele desligado', $s === 404);
// Nenhum arquivo permanente lê as tabelas temporárias (só os pontos de integração que verificam o módulo).
$dirRaiz = realpath(__DIR__ . '/../../..');
$permanentes = array_merge(glob($dirRaiz . '/src/*/*.php'), glob($dirRaiz . '/admin/*.php'), glob($dirRaiz . '/admin/includes/*.php'));
$usamReorg = array_values(array_filter($permanentes, fn ($f) => preg_match('/\breorg_(lotes|registros|periodos|sessoes|atribuicoes|registro_exemplares|historico)\b/', (string)file_get_contents($f))));
confere('17. nenhum arquivo permanente consulta tabelas reorg_*', $usamReorg === [], implode(', ', array_map('basename', $usamReorg)));
$fk = $num("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME LIKE 'reorg\\_%' AND TABLE_NAME NOT LIKE 'reorg\\_%'");
confere('17. nenhuma tabela permanente aponta para tabelas reorg_* (podem ser removidas depois)', $fk === 0);

/** LoteRepository sem as funções do painel. */
function lotes_repo_teste(): Biblioteca\Repositories\LoteRepository
{
    return new Biblioteca\Repositories\LoteRepository(db());
}

teste_resultado();
