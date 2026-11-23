<?php
/**
 * Testes do gerenciamento de lotes e etapas de trabalho (migração 160).
 *
 * Uso: php admin/tests/lotes-teste.php
 *
 * Tudo acontece num banco temporário "bdp_teste_lotes_XXXX" (apagado no final), com dados FICTÍCIOS.
 * Cenários:
 *    1. lotes existentes preservados pela migração;               2. abrir lote (número do servidor, vários usuários);
 *    3. lote ativo e usuários não autorizados;                     4. capacidade por exemplar físico e fechamento automático;
 *    5. registros simultâneos disputando a última vaga;            6. fechamento antecipado;
 *    7. planilha BDP do lote (do banco, depois de fechado);         8. carimbagem (individual, em lote, confirmação bloqueada);
 *    9. dúvidas (não bloqueiam o trabalho; bloqueiam a etapa quando comprometem a identificação);
 *   10. organização nas estantes (sugestão, vários de uma vez, quem organizou);
 *   11. disponibilidade para empréstimo;                          12. encerramento e preservação;
 *   13. histórico, relatório e produtividade com números reais;   14. rascunhos;
 *   15. correções administrativas;                                16. páginas, permissões e exportações.
 */

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\DuvidaService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\LoteService;
use Biblioteca\Services\OcorrenciaService;
use Biblioteca\Services\OperacaoService;
use Biblioteca\Services\OrganizacaoFisicaService;
use Biblioteca\Services\RascunhoService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('lotes');
$_SERVER['SCRIPT_NAME'] = '/admin/tests/lotes-teste.php';
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
foreach (['administrador' => 'adm@teste.br', 'bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'auxiliar2' => 'aux2@teste.br', 'consulta' => 'cons@teste.br', 'auxiliar3' => 'aux3@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Colaborador Fictício ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), preg_replace('/\d$/', '', $perfil)]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
[$adm, $bib, $c1, $c2, $c3] = [$contas['administrador'], $contas['bibliotecaria'], $contas['auxiliar'], $contas['auxiliar2'], $contas['auxiliar3']];

// Mesma regra padrão do painel (exemplares_servico): só libera carimbado e com estante.
$ex = new ExemplarService($pdo);
$ex->definirRegrasPreparo(false, false);
$lotes = new LoteService($pdo, $ex);
$repo = new LoteRepository($pdo);
$org = new OrganizacaoFisicaService($pdo, $ex);
$ops = new OperacaoService($pdo, $ex);
$oc = new OcorrenciaService($pdo, $ex);
$duv = new DuvidaService($pdo, $ex);
$rasc = new RascunhoService($pdo);

$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Dom Casmurro (fictício)', NOW()), ('Memórias Fictícias', NOW()), ('Terceira Obra Fictícia', NOW())");
$livro = (int)$val("SELECT id FROM livros WHERE titulo = 'Dom Casmurro (fictício)'");
$livro2 = (int)$val("SELECT id FROM livros WHERE titulo = 'Memórias Fictícias'");
$livro3 = (int)$val("SELECT id FROM livros WHERE titulo = 'Terceira Obra Fictícia'");
$estA = $org->criarEstante(['identificacao' => 'A', 'prateleiras' => 3], $adm);
$pratA = array_map('intval', array_column(db_query('SELECT id FROM localizacoes WHERE estante_id = ? ORDER BY ordem, id', [$estA])->fetchAll(), 'id'));
$novo = fn (int $livroId, int $quem, int $qtd = 1, array $extra = []) => $ex->cadastrar($livroId, $extra + ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $quem, $qtd);

// ---------------------------------------------------------------
echo "\n1. Lotes automáticos (migração 150) continuam funcionando e preservados\n";
$antigo = $novo($livro3, $bib)[0];
$loteAntigo = (int)$antigo['lote_id'];
confere('1. sem lote gerenciado, o registro usa o lote automático como antes', $loteAntigo > 0 && $val('SELECT modo FROM cadastro_lotes WHERE id = ?', [$loteAntigo]) === 'automatico'
    && $val('SELECT capacidade FROM cadastro_lotes WHERE id = ?', [$loteAntigo]) === null);
confere('1. lote automático tem histórico e participante', $num('SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ?', [$loteAntigo]) >= 2 && $lotes->participa($loteAntigo, $bib));
confere('1. lotes não podem ser apagados', $recusa(fn () => $pdo->exec("DELETE FROM cadastro_lotes WHERE id = $loteAntigo")) !== null);

// ---------------------------------------------------------------
echo "\n2. Abrir lote\n";
$chaveAbrir = OperacaoService::novaChave();
$l1 = $lotes->criar(['capacidade' => 3, 'usuarios' => [$c1, $c2], 'descricao' => 'Literatura — estante A'], $adm, $chaveAbrir);
$l1b = $lotes->criar(['capacidade' => 3, 'usuarios' => [$c1, $c2]], $adm, $chaveAbrir);
$lote1 = $repo->lote($l1);
confere('2. número sequencial gerado pelo servidor (seguinte ao lote automático)', (int)$lote1['numero'] === (int)$val('SELECT numero FROM cadastro_lotes WHERE id = ?', [$loteAntigo]) + 1);
confere('2. clique duplo (mesmo formulário) não abre dois lotes', $l1b === $l1 && $num("SELECT COUNT(*) FROM cadastro_lotes WHERE modo = 'gerenciado'") === 1);
confere('2. capacidade, observação, criador e status "Em cadastro"', (int)$lote1['capacidade'] === 3 && $lote1['descricao'] === 'Literatura — estante A' && (int)$lote1['responsavel_id'] === $adm && $lote1['status'] === 'aberto');
confere('2. vários usuários no mesmo lote', $lotes->participa($l1, $c1) && $lotes->participa($l1, $c2) && !$lotes->participa($l1, $c3));
$l2 = $lotes->criar(['capacidade' => 10, 'usuarios' => [$c1, $c3]], $adm);
confere('2. o mesmo usuário em vários lotes', $lotes->participa($l2, $c1) && count($repo->meusLotes($c1)) === 2);
confere('2. capacidade e usuários são obrigatórios', $recusa(fn () => $lotes->criar(['capacidade' => 0, 'usuarios' => [$c1]], $adm)) !== null && $recusa(fn () => $lotes->criar(['capacidade' => 5, 'usuarios' => []], $adm)) !== null);
confere('2. eventos: lote aberto e usuários atribuídos', $num("SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ? AND tipo = 'lote_aberto' AND administrador_id = ?", [$l1, $adm]) === 1
    && $num("SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ? AND tipo = 'usuario_atribuido'", [$l1]) === 2);
confere('2. o número do lote não pode ser alterado', $recusa(fn () => $pdo->exec("UPDATE cadastro_lotes SET numero = 99 WHERE id = $l1")) !== null);

// ---------------------------------------------------------------
echo "\n3. Lote ativo e usuários não autorizados\n";
confere('3. depois do primeiro lote gerenciado, sem lote ativo o registro é recusado', ($m = $recusa(fn () => $novo($livro, $c1))) !== null && str_contains($m, 'Meus lotes'));
confere('3. quem não participa não escolhe o lote', $recusa(fn () => $lotes->escolherAtivo($l1, $c3)) !== null);
$lotes->escolherAtivo($l1, $c1);
$lotes->escolherAtivo($l1, $c2);
$lotes->escolherAtivo($l2, $c3);
$pdo->prepare('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?')->execute([$l1, $c3]); // forçado por fora (formulário adulterado)
confere('3. lote ativo forçado por quem não participa: registro recusado', ($m = $recusa(fn () => $novo($livro, $c3))) !== null && str_contains($m, 'não está atribuído'));
$lotes->escolherAtivo($l2, $c3);
$r1 = $novo($livro, $c1)[0];
confere('3. exemplar entra no lote ativo, com quem cadastrou', (int)$r1['lote_id'] === $l1 && (int)$val('SELECT cadastrado_por FROM exemplares WHERE id = ?', [$r1['id']]) === $c1 && $r1['lote_ordem'] === 1);
confere('3. evento "exemplar cadastrado" com responsável', $num("SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ? AND exemplar_id = ? AND tipo = 'exemplar_cadastrado' AND administrador_id = ?", [$l1, $r1['id'], $c1]) === 1);

// ---------------------------------------------------------------
echo "\n4. Capacidade por exemplar físico e fechamento automático\n";
confere('4. pedir mais exemplares do que as vagas é recusado (nada gravado)', ($m = $recusa(fn () => $novo($livro, $c2, 3))) !== null && str_contains($m, '2 vaga') && $num('SELECT total FROM cadastro_lotes WHERE id = ?', [$l1]) === 1);
$r2 = $novo($livro, $c2, 2); // dois exemplares da MESMA obra = duas vagas
$lote1 = $repo->lote($l1);
confere('4. três exemplares de Dom Casmurro ocupam três vagas', (int)$lote1['total'] === 3 && (int)$lote1['exemplares'] === 3);
confere('4. ao atingir o limite: "Lote fechado — Aguardando carimbagem" automaticamente', $lote1['status'] === 'fechado' && $lote1['fechamento_tipo'] === 'automatico' && $lote1['fechado_em'] !== null && end($r2)['lote_fechou'] === true);
confere('4. rótulo do status', ExemplarService::rotuloStatusLote('fechado') === 'Lote fechado — Aguardando carimbagem');
// Lote fechado por ter enchido responde como "sem vagas" (LoteCapacidadeException, migração 190); fechado à mão: "não recebe novos exemplares".
confere('4. lote fechado não recebe novos exemplares', ($m = $recusa(fn () => $novo($livro2, $c1))) !== null && (str_contains($m, 'não recebe novos exemplares') || str_contains($m, 'já está completo')));
confere('4. fechar não bloqueia correções nos registros existentes', $recusa(fn () => $ex->editar($r1['id'], ['estado_conservacao' => 'regular', 'observacoes' => 'corrigido depois do fechamento'], $c1)) === null
    && $val('SELECT observacoes FROM exemplares WHERE id = ?', [$r1['id']]) === 'corrigido depois do fechamento');
confere('4. lote fechado não pode ser escolhido como ativo', $recusa(fn () => $lotes->escolherAtivo($l1, $c2)) !== null);

// ---------------------------------------------------------------
echo "\n5. Registros simultâneos disputando a última vaga\n";
$l3 = $lotes->criar(['capacidade' => 2, 'usuarios' => [$c1, $c2]], $adm);
$lotes->escolherAtivo($l3, $c1);
$lotes->escolherAtivo($l3, $c2);
$novo($livro2, $c1); // resta 1 vaga
$filho = sys_get_temp_dir() . '/bdp-teste-lote-filho-' . bin2hex(random_bytes(4)) . '.php';
$GLOBALS['teste_temporarios'][] = $filho;
file_put_contents($filho, '<?php
require ' . var_export(realpath(__DIR__ . '/../../config/app.php'), true) . ';
$ex = new Biblioteca\Services\ExemplarService(db());
$ex->definirRegrasPreparo(false, false);
$inicio = (float)$argv[3];
while (microtime(true) < $inicio) { usleep(2000); }
try {
    $r = $ex->cadastrar((int)$argv[1], ["estado_conservacao" => "bom", "situacao" => "disponivel"], (int)$argv[2], 1);
    echo "OK " . $r[0]["codigo"];
} catch (Throwable $e) {
    echo "RECUSADO " . $e->getMessage();
}');
$corrida = function (array $quem) use ($filho, $livro2) {
    $inicio = sprintf('%.3f', microtime(true) + 0.6);
    $procs = [];
    foreach ($quem as $q) {
        $procs[] = proc_open([PHP_BINARY, $filho, (string)$livro2, (string)$q, $inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
        $saidas[] = $pipes;
    }
    $r = [];
    foreach ($procs as $i => $p) {
        $r[] = trim(stream_get_contents($saidas[$i][1]) . stream_get_contents($saidas[$i][2]));
        proc_close($p);
    }
    return $r;
};
$res = $corrida([$c1, $c2]);
$oks = count(array_filter($res, fn ($s) => str_starts_with($s, 'OK')));
confere('5. duas pessoas ao mesmo tempo na última vaga: só uma operação conclui', $oks === 1 && count(array_filter($res, fn ($s) => str_starts_with($s, 'RECUSADO'))) === 1, json_encode($res, JSON_UNESCAPED_UNICODE));
confere('5. capacidade nunca ultrapassada e lote fechado', $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$l3]) === 2 && $num('SELECT total FROM cadastro_lotes WHERE id = ?', [$l3]) === 2
    && $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$l3]) === 'fechado');
// Transação aberta segurando a última vaga: o outro cadastro espera e é recusado depois do commit.
$l4 = $lotes->criar(['capacidade' => 1, 'usuarios' => [$c1, $c2]], $adm);
$lotes->escolherAtivo($l4, $c1);
$lotes->escolherAtivo($l4, $c2);
$pdo->beginTransaction();
$ex->cadastrar($livro2, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $c1, 1);
$proc = proc_open([PHP_BINARY, $filho, (string)$livro2, (string)$c2, '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, getenv());
usleep(800000);
$pdo->commit();
$saida = trim(stream_get_contents($pp[1]) . stream_get_contents($pp[2]));
proc_close($proc);
confere('5. cadastro que esperava a trava é recusado depois que a vaga foi ocupada', str_starts_with($saida, 'RECUSADO') && $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ?', [$l4]) === 1, $saida);
confere('5. nenhuma ordem repetida dentro dos lotes', !$num('SELECT COUNT(*) FROM (SELECT lote_id, lote_ordem FROM exemplares WHERE lote_id IS NOT NULL GROUP BY lote_id, lote_ordem HAVING COUNT(*) > 1) x'));

// ---------------------------------------------------------------
echo "\n6. Fechamento antecipado\n";
$r5 = $novo($livro2, $c3, 2); // lote 2: 2 de 10
$lotes->fecharAntecipado($l2, $adm, 'estante terminou');
$lote2 = $repo->lote($l2);
confere('6. administrador fecha antes do limite', $lote2['status'] === 'fechado' && $lote2['fechamento_tipo'] === 'antecipado' && (int)$lote2['fechado_por'] === $adm && (int)$lote2['total'] === 2);
confere('6. fechar de novo é recusado', $recusa(fn () => $lotes->fecharAntecipado($l2, $adm)) !== null);
confere('6. evento de fechamento com o motivo', $num("SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ? AND tipo = 'lote_fechado' AND descricao LIKE '%estante terminou%'", [$l2]) === 1);

// ---------------------------------------------------------------
echo "\n8–9. Carimbagem e dúvidas\n";
$ids1 = array_map('intval', array_column(db_query('SELECT id FROM exemplares WHERE lote_id = ? ORDER BY lote_ordem', [$l1])->fetchAll(), 'id'));
confere('8. confirmação recusada enquanto houver exemplares sem carimbo (e diz quais)', ($m = $recusa(fn () => $lotes->confirmarCarimbagem($l1, $c2))) !== null && str_contains($m, '3 exemplar')
    && str_contains($m, (string)$val('SELECT codigo_individual FROM exemplares WHERE id = ?', [$ids1[0]])));
$ex->marcarCarimbado([$ids1[0]], $c2);
$lote1 = $repo->lote($l1);
confere('8. primeiro carimbo: lote "Em carimbagem", com quem e quando', $lote1['status'] === 'em_carimbagem' && (int)$lote1['carimbagem_iniciada_por'] === $c2 && $lote1['carimbagem_iniciada_em'] !== null);
$dId = $duv->registrar(['exemplar_id' => $ids1[1], 'tipo' => 'edicao_nao_identificada', 'descricao' => 'Capa e folha de rosto divergem', 'compromete' => '1'], $c1);
confere('9. dúvida registrada não impede continuar trabalhando', $recusa(fn () => $ex->marcarCarimbado([$ids1[1], $ids1[2]], $c3)) === null);
confere('9. dúvida aparece no lote do exemplar', (int)$val('SELECT lote_id FROM conferencia_duvidas WHERE id = ?', [$dId]) === $l1 && count($repo->duvidas($l1, true)) === 1);
confere('9. dúvida que compromete a identificação bloqueia a confirmação da etapa', ($m = $recusa(fn () => $lotes->confirmarCarimbagem($l1, $c2))) !== null && str_contains($m, 'dúvida'));
$duv->resolver($dId, 'Conferido com a ficha catalográfica: 2ª edição', $bib);
confere('9. resolução com responsável e data', $val('SELECT status FROM conferencia_duvidas WHERE id = ?', [$dId]) === 'resolvida' && (int)$val('SELECT resolvida_por FROM conferencia_duvidas WHERE id = ?', [$dId]) === $bib);
confere('9. dúvidas não podem ser apagadas', $recusa(fn () => $pdo->exec("DELETE FROM conferencia_duvidas WHERE id = $dId")) !== null);
$lotes->confirmarCarimbagem($l1, $c2, OperacaoService::novaChave());
$lote1 = $repo->lote($l1);
confere('8. todos carimbados: "Aguardando organização nas estantes", com responsável e data', $lote1['status'] === 'aguardando_organizacao' && (int)$lote1['carimbagem_concluida_por'] === $c2 && $lote1['carimbagem_concluida_em'] !== null);
confere('8. carimbo do lote confirmado não volta para "aguardando" (Desfazer recusado)', $recusa(fn () => $ex->voltarAguardandoCarimbo($ids1[0], $adm)) !== null);
confere('8. status de carimbagem separado da conferência bibliográfica', $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND conferido_em IS NOT NULL', [$l1]) === 0);

// ---------------------------------------------------------------
echo "\n10–11. Organização nas estantes e disponibilidade\n";
confere('11. carimbado mas sem estante: indisponível para empréstimo', $val('SELECT situacao FROM exemplares WHERE id = ?', [$ids1[0]]) === 'aguardando');
$o = $oc->registrar($ids1[2], ['tipo' => 'paginas_faltando', 'descricao' => 'Faltam páginas', 'bloquear' => '1'], $c3);
confere('11. lote em organização não aceita exemplar sem carimbo nem de outro lote', $recusa(fn () => $lotes->organizar($l1, [$r5[0]['id']], $pratA[0], $c3)) !== null);
confere('10. a localização precisa ser uma prateleira da Organização Física', $recusa(fn () => $lotes->organizar($l1, [$ids1[0]], 999999, $c3)) !== null);
$n = $lotes->organizar($l1, [$ids1[0], $ids1[1]], $pratA[0], $c3);
confere('10. vários exemplares de uma vez no mesmo lugar, com quem organizou e quando', $n === 2
    && $num('SELECT COUNT(*) FROM exemplares WHERE id IN (?, ?) AND localizacao_id = ? AND organizado_por = ? AND organizado_em IS NOT NULL', [$ids1[0], $ids1[1], $pratA[0], $c3]) === 2);
confere('10. a última prateleira usada fica para a sugestão', (int)$val('SELECT ultima_localizacao_id FROM administradores WHERE id = ?', [$c3]) === $pratA[0]);
confere('11. carimbado + organizado: disponível para empréstimo', $val('SELECT situacao FROM exemplares WHERE id = ?', [$ids1[0]]) === 'disponivel');
$lotes->organizar($l1, [$ids1[2]], $pratA[1], $c2);
confere('11. ocorrência que bloqueia o empréstimo não é liberada pelo lote', $val('SELECT situacao FROM exemplares WHERE id = ?', [$ids1[2]]) === 'danificado');
$livreSemCarimbo = new ExemplarService($pdo);
$livreSemCarimbo->definirRegrasPreparo(true, true); // configuração permissiva
$l5 = $lotes->criar(['capacidade' => 5, 'usuarios' => [$bib]], $adm);
$lotes->escolherAtivo($l5, $bib);
$r6 = $livreSemCarimbo->cadastrar($livro3, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $pratA[2]], $bib)[0];
confere('11. exemplar de lote sem carimbo não fica disponível nem com a configuração permissiva', $r6['situacao'] === 'aguardando');

// ---------------------------------------------------------------
echo "\n12. Encerramento e preservação\n";
$mov = $ex->transferir($ids1[1], $pratA[2], $bib, 'reorganização posterior');
confere('12. movimentação posterior não apaga quem organizou originalmente', $mov && (int)$val('SELECT organizado_por FROM exemplares WHERE id = ?', [$ids1[1]]) === $c3
    && (int)$val('SELECT organizado_localizacao_id FROM exemplares WHERE id = ?', [$ids1[1]]) === $pratA[0]);
confere('12. encerrar antes de concluir a carimbagem é recusado', $recusa(fn () => $lotes->encerrar($l2, $adm)) !== null);
$ex->transferir($ids1[2], null, $bib, 'voltou para a triagem');
confere('12. encerrar com exemplar sem estante é recusado e diz qual', ($m = $recusa(fn () => $lotes->encerrar($l1, $adm))) !== null && str_contains($m, (string)$val('SELECT codigo_individual FROM exemplares WHERE id = ?', [$ids1[2]])));
$lotes->organizar($l1, [$ids1[2]], $pratA[1], $c2);
$codigosAntes = db_query('SELECT id, codigo_individual FROM exemplares WHERE lote_id = ? ORDER BY id', [$l1])->fetchAll();
$lotes->encerrar($l1, $adm, OperacaoService::novaChave());
$lote1 = $repo->lote($l1);
confere('12. "Lote encerrado" com data, hora e administrador', $lote1['status'] === 'encerrado' && (int)$lote1['encerrado_por'] === $adm && $lote1['encerrado_em'] !== null);
confere('12. exemplares e códigos BDP preservados', $codigosAntes === db_query('SELECT id, codigo_individual FROM exemplares WHERE lote_id = ? ORDER BY id', [$l1])->fetchAll());
confere('12. lote encerrado não volta para etapas anteriores', $recusa(fn () => $pdo->exec("UPDATE cadastro_lotes SET status = 'aberto' WHERE id = $l1")) !== null);
confere('12. o encerramento original não pode ser apagado', $recusa(fn () => $pdo->exec("UPDATE cadastro_lotes SET encerrado_em = NULL WHERE id = $l1")) !== null);
confere('12. lote encerrado não recebe exemplares (nem forçando o lote ativo)', ($pdo->prepare('UPDATE administradores SET lote_ativo_id = ? WHERE id = ?')->execute([$l1, $c1]) && ($m = $recusa(fn () => $novo($livro, $c1))) !== null));
confere('12. histórico do lote não pode ser alterado nem apagado', $recusa(fn () => $pdo->exec("DELETE FROM lote_eventos WHERE lote_id = $l1")) !== null && $recusa(fn () => $pdo->exec("UPDATE lote_eventos SET descricao = 'x' WHERE lote_id = $l1")) !== null);

// ---------------------------------------------------------------
echo "\n13. Histórico, relatório e produtividade\n";
$hist = $repo->historico([]);
confere('13. o histórico mostra todos os lotes, inclusive os encerrados', $hist['total'] === $num('SELECT COUNT(*) FROM cadastro_lotes') && in_array($l1, array_map('intval', array_column($hist['itens'], 'id')), true));
confere('13. filtros por status, número e responsável', $repo->historico(['status' => 'encerrado'])['total'] === 1 && $repo->historico(['numero' => $lote1['numero']])['total'] === 1
    && $repo->historico(['responsavel' => $c3])['total'] === $num('SELECT COUNT(DISTINCT lote_id) FROM cadastro_lote_usuarios WHERE administrador_id = ?', [$c3]) + 0);
$resumo = $repo->resumo($l1);
confere('13. resumo do lote com números reais', $resumo['cadastrados'] === 3 && $resumo['carimbados'] === 3 && $resumo['organizados'] === 3 && $resumo['pendentes'] === 0 && $resumo['ocorrencias_abertas'] === 1 && $resumo['duvidas_total'] === 1);
$prod = array_column($repo->produtividade($l1), null, 'id');
confere('13. produtividade separa quem cadastrou, carimbou e organizou', $prod[$c1]['cadastrados'] === 1 && $prod[$c2]['cadastrados'] === 2 && $prod[$c2]['carimbados'] === 1 && $prod[$c3]['carimbados'] === 2
    && $prod[$c3]['organizados'] === 2 && $prod[$c2]['organizados'] === 1, json_encode($prod));
confere('13. totais da produtividade = exemplares do lote (sem contar duas vezes)', array_sum(array_column($prod, 'cadastrados')) === 3 && array_sum(array_column($prod, 'carimbados')) === 3 && array_sum(array_column($prod, 'organizados')) === 3);
$painel = $repo->painel([]);
confere('13. painel geral por status', $painel['status']['encerrado'] === 1 && $painel['status']['fechado'] >= 2 && $painel['status']['total'] === $num('SELECT COUNT(*) FROM cadastro_lotes'));
confere('13. painel: produtividade por dia, semana e mês', count($painel['por_periodo']['dia']) === 1 && $painel['por_periodo']['dia'][0]['cadastrados'] === $painel['totais']['cadastrados']
    && $painel['por_periodo']['mes'][0]['carimbados'] === $painel['totais']['carimbados']);
confere('13. filtro por colaborador', $repo->painel(['colaborador' => $c3])['totais']['organizados'] === 2 && $repo->painel(['colaborador' => $c3, 'lote' => $l1])['totais']['cadastrados'] === 0);
$t = LoteRepository::tempos($lote1);
confere('13. tempo de cada etapa calculado (duração, não horas trabalhadas)', $t['total']['segundos'] !== null && !$t['total']['em_andamento'] && $t['cadastro']['segundos'] !== null);
confere('13. duração em texto', LoteRepository::duracaoTexto(90061) === '1 d 1 h 1 min' && LoteRepository::duracaoTexto(30) === '0 min');

// ---------------------------------------------------------------
echo "\n14. Rascunhos\n";
$exAntes = $num('SELECT COUNT(*) FROM exemplares');
$seqAntes = $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1');
$rasc->salvar($c1, 'registrar_exemplar', 'inv1-obra' . $livro, ['observacao' => 'capa solta', 'codigo_bdp' => 'BDP-000999', 'processo_bdp' => str_repeat('a', 32), 'csrf' => 'x', 'estado_conservacao' => 'bom']);
$r = $rasc->obter($c1, 'registrar_exemplar', 'inv1-obra' . $livro);
confere('14. rascunho recuperado com os campos digitados', $r !== null && $r['campos']['observacao'] === 'capa solta' && $r['campos']['estado_conservacao'] === 'bom');
confere('14. rascunho nunca guarda código BDP, token nem CSRF', !isset($r['campos']['codigo_bdp']) && !isset($r['campos']['processo_bdp']) && !isset($r['campos']['csrf']));
confere('14. rascunho não cria exemplar, não ocupa vaga e não gasta código', $exAntes === $num('SELECT COUNT(*) FROM exemplares') && $seqAntes === $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1'));
confere('14. rascunho é de cada usuário', $rasc->obter($c2, 'registrar_exemplar', 'inv1-obra' . $livro) === null);
confere('14. rascunho guarda o lote ativo e mostra se ele fechou', (int)$r['lote_id'] === $l1 && $r['lote_status'] === 'encerrado');
$rasc->descartar($c1, 'registrar_exemplar', 'inv1-obra' . $livro);
confere('14. descartado depois de salvar (não sobra para duplicar)', $rasc->obter($c1, 'registrar_exemplar', 'inv1-obra' . $livro) === null);
confere('14. formulário desconhecido é recusado', $recusa(fn () => $rasc->salvar($c1, 'qualquer', 'x', ['a' => 'b'])) !== null);

// ---------------------------------------------------------------
echo "\n15. Correções administrativas\n";
$lotes->escolherAtivo($l5, $bib);
$lotes->corrigir($l5, ['capacidade' => 2, 'justificativa' => 'estante menor do que o previsto'], $adm);
confere('15. capacidade reduzida até o que já foi registrado fecha o lote', $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$l5]) === 'aberto' && $num('SELECT capacidade FROM cadastro_lotes WHERE id = ?', [$l5]) === 2);
confere('15. capacidade abaixo do registrado é recusada', $recusa(fn () => $lotes->corrigir($l5, ['capacidade' => 0, 'justificativa' => 'teste de limite'], $adm)) !== null);
confere('15. correção exige justificativa', $recusa(fn () => $lotes->corrigir($l1, ['descricao' => 'x', 'justificativa' => ''], $adm)) !== null);
$lotes->corrigir($l1, ['descricao' => 'Literatura brasileira — estante A', 'justificativa' => 'nome do lote corrigido'], $adm);
confere('15. correção em lote encerrado fica no histórico e não muda o encerramento', $val('SELECT descricao FROM cadastro_lotes WHERE id = ?', [$l1]) === 'Literatura brasileira — estante A'
    && $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$l1]) === 'encerrado' && $val('SELECT encerrado_em FROM cadastro_lotes WHERE id = ?', [$l1]) === $lote1['encerrado_em']
    && $num("SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ? AND tipo = 'correcao' AND descricao LIKE '%nome do lote corrigido%'", [$l1]) === 1);
$lotes->remover($l2, $c3, $adm);
confere('15. remover usuário guarda quem e quando, sem apagar a participação', $num('SELECT COUNT(*) FROM cadastro_lote_usuarios WHERE lote_id = ? AND administrador_id = ? AND removido_em IS NOT NULL AND removido_por = ?', [$l2, $c3, $adm]) === 1);

// ---------------------------------------------------------------
echo "\n16. Páginas, permissões e exportações\n";
$base = teste_servidor($env);
$nav = new TesteNavegador($base);
confere('administrador entra no painel', $nav->entrar('adm@teste.br', $senha) === 200);
$tok = function (TesteNavegador $n, string $pagina): string {
    [, $html] = $n->ir($pagina);
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
};
$inv = (int)$val("SELECT id FROM inventarios LIMIT 1");
if (!$inv) {
    $pdo->prepare("INSERT INTO inventarios (titulo, status, iniciado_em, aberto_por) VALUES ('Inventário fictício', 'em_andamento', NOW(), ?)")->execute([$adm]);
    $inv = (int)$pdo->lastInsertId();
}
[$s, $html] = $nav->ir('admin/inventario-conferencia.php?id=' . $inv);
confere('16. Conferência mostra "Meus lotes" e "Abrir novo lote" para o administrador', $s === 200 && str_contains($html, 'Meus lotes') && str_contains($html, 'Abrir novo lote') && teste_sem_erro_tecnico($html), "status $s");
$t = $tok($nav, 'admin/lotes.php?novo=1');
[$s, , $loc] = $nav->ir('admin/lotes.php', ['csrf' => $t, 'acao' => 'abrir', 'capacidade' => '30', 'usuarios' => [(string)$c1, (string)$c2], 'descricao' => 'Lote pela página', 'chave' => OperacaoService::novaChave(),
    'voltar' => '/admin/inventario-conferencia.php?id=' . $inv]);
$lPag = (int)$val("SELECT id FROM cadastro_lotes WHERE descricao = 'Lote pela página'");
confere('16. "+ Abrir novo lote" pela página volta para a Conferência', $s === 303 && $lPag > 0 && str_contains($loc, 'inventario-conferencia.php') && $num('SELECT capacidade FROM cadastro_lotes WHERE id = ?', [$lPag]) === 30);

$aux = new TesteNavegador($base);
$aux->entrar('aux@teste.br', $senha);
[$s, $html] = $aux->ir('admin/inventario-conferencia.php?id=' . $inv);
confere('16. colaborador vê os lotes atribuídos e não vê "Abrir novo lote"', $s === 200 && str_contains($html, 'Lote ' . str_pad((string)$num('SELECT numero FROM cadastro_lotes WHERE id = ?', [$lPag]), 3, '0', STR_PAD_LEFT))
    && !str_contains($html, 'name="acao" value="abrir"') && str_contains($html, 'Trabalhar neste lote'));
$t = $tok($aux, 'admin/inventario-conferencia.php?id=' . $inv);
[$s] = $aux->ir('admin/lotes.php', ['csrf' => $t, 'acao' => 'ativo', 'lote_id' => (string)$lPag, 'voltar' => '/admin/inventario-conferencia.php?id=' . $inv]);
confere('16. "Trabalhar neste lote" define o lote ativo', $s === 303 && (int)$val('SELECT lote_ativo_id FROM administradores WHERE id = ?', [$c1]) === $lPag);
[$s] = $aux->ir('admin/lotes.php', ['csrf' => $t, 'acao' => 'abrir', 'capacidade' => '5', 'usuarios' => [(string)$c1]]);
confere('16. colaborador não abre lote (403)', $s === 403);
[$s] = $aux->ir('admin/lotes-produtividade.php');
confere('16. Relatório de Produtividade restrito à administração (403)', $s === 403);
[$s, $html] = $aux->ir('admin/lote.php?id=' . $l1);
confere('16. colaborador abre o relatório do lote, sem a produtividade individual', $s === 200 && str_contains($html, 'Exemplares') && !str_contains($html, 'Relatório de Produtividade do lote'));

// Carimbo e confirmação pela página (lote 3, fechado)
$ids3 = array_map('intval', array_column(db_query('SELECT id FROM exemplares WHERE lote_id = ? ORDER BY lote_ordem', [$l3])->fetchAll(), 'id'));
$t = $tok($nav, 'admin/lote.php?id=' . $l3);
[$s] = $nav->ir('admin/lote.php', ['csrf' => $t, 'id' => (string)$l3, 'acao' => 'carimbar', 'ids' => array_map('strval', $ids3), 'chave' => OperacaoService::novaChave()]);
confere('16. confirmação em lote exige o aviso de segurança marcado', $s === 303 && $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND carimbo_status = 'carimbado'", [$l3]) === 0);
[$s] = $nav->ir('admin/lote.php', ['csrf' => $t, 'id' => (string)$l3, 'acao' => 'carimbar', 'ids' => array_map('strval', $ids3), 'confirmo' => '1', 'chave' => OperacaoService::novaChave()]);
confere('16. "Marcar selecionados como carimbados" pela página', $s === 303 && $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND carimbo_status = 'carimbado' AND carimbado_por = ?", [$l3, $adm]) === 2);
[$s] = $nav->ir('admin/lote.php', ['csrf' => $t, 'id' => (string)$l3, 'acao' => 'confirmar_carimbagem', 'chave' => OperacaoService::novaChave()]);
confere('16. "Confirmar carimbagem do lote" pela página', $s === 303 && $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$l3]) === 'aguardando_organizacao');
db_query('UPDATE administradores SET ultima_localizacao_id = ? WHERE id = ?', [$pratA[1], $adm]); // prateleira usada antes pelo funcionário
[$s, $html] = $nav->ir('admin/lote.php?id=' . $l3);
confere('16. organização sugere a última prateleira usada pelo funcionário', $s === 200 && str_contains($html, 'Localização sugerida') && str_contains($html, 'Guardar selecionados nesta prateleira'));
[$s] = $nav->ir('admin/lote.php', ['csrf' => $t, 'id' => (string)$l3, 'acao' => 'organizar', 'localizacao_id' => (string)$pratA[2], 'ids' => array_map('strval', $ids3), 'chave' => OperacaoService::novaChave()]);
[$s2, $html] = $nav->ir('admin/lote.php?id=' . $l3);
confere('16. organizar vários pela página e o botão "Encerrar lote" aparece', $s === 303 && $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND localizacao_id = ?', [$l3, $pratA[2]]) === 2 && str_contains($html, 'Encerrar lote</button>') === true
    || str_contains($html, '> Encerrar lote'));
[$s] = $nav->ir('admin/lote.php', ['csrf' => $t, 'id' => (string)$l3, 'acao' => 'encerrar', 'chave' => OperacaoService::novaChave()]);
confere('16. "Encerrar lote" pela página', $s === 303 && $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$l3]) === 'encerrado');

[$s, , $loc] = $nav->ir('admin/lote.php?id=' . $l1 . '&saida=planilha');
[$s2, $html] = $nav->ir(ltrim(parse_url($loc, PHP_URL_PATH) . '?' . parse_url($loc, PHP_URL_QUERY), '/'));
$codL1 = array_column($codigosAntes, 'codigo_individual');
confere('7. planilha BDP do lote encerrado continua disponível, vinda do banco, só com o lote', $s === 303 && $s2 === 200 && !array_diff($codL1, array_filter($codL1, fn ($c) => str_contains($html, $c)))
    && !str_contains($html, (string)$r5[0]['codigo']) && str_contains($html, 'Ordem') && str_contains($html, 'Carimbado'));
[$s, , $loc] = $nav->ir('admin/lote.php?id=' . $l1 . '&saida=planilha_excel');
[$s2, , , $tipo] = $nav->ir(ltrim(parse_url($loc, PHP_URL_PATH) . '?' . parse_url($loc, PHP_URL_QUERY), '/'));
confere('7. "Exportar para Excel" da planilha do lote', $s2 === 200 && str_contains((string)$tipo, 'spreadsheetml'));
foreach (['xlsx' => 'spreadsheetml', 'pdf' => 'application/pdf'] as $fmt => $mime) {
    [$s, , , $tipo] = $nav->ir('admin/lote.php?id=' . $l1 . '&exportar=' . $fmt);
    confere("13. relatório do lote exporta $fmt", $s === 200 && str_contains((string)$tipo, $mime));
    [$s, , , $tipo] = $nav->ir('admin/lotes-produtividade.php?exportar=' . $fmt . '&tabela=lotes');
    confere("18. Relatório de Produtividade exporta $fmt", $s === 200 && str_contains((string)$tipo, $mime));
}
[$s, , , $tipo] = $nav->ir('admin/lotes.php?exportar=xlsx');
confere('13. Histórico de Lotes exporta para Excel', $s === 200 && str_contains((string)$tipo, 'spreadsheetml'));
$t = $tok($aux, 'admin/duvidas.php');
[$s] = $aux->ir('admin/duvidas.php', ['csrf' => $t, 'acao' => 'registrar', 'codigo' => $codL1[0], 'tipo' => 'isbn_desconhecido', 'descricao' => 'ISBN ilegível']);
confere('12. dúvida registrada pela página (colaborador)', $s === 303 && $num("SELECT COUNT(*) FROM conferencia_duvidas WHERE descricao = 'ISBN ilegível' AND registrada_por = ?", [$c1]) === 1);
// Rascunhos pela API
$t = $tok($aux, 'admin/inventario-conferencia.php?id=' . $inv);
[$s, $json] = $aux->ir('admin/api/rascunhos/rascunho.php', ['csrf' => $t, 'formulario' => 'registrar_exemplar', 'referencia' => 'inv' . $inv . '-obra' . $livro, 'campos' => ['observacao' => 'rascunho pela API', 'codigo_bdp' => 'BDP-000777']]);
[$s2, $json2] = $aux->ir('admin/api/rascunhos/rascunho.php?' . http_build_query(['formulario' => 'registrar_exemplar', 'referencia' => 'inv' . $inv . '-obra' . $livro]));
$j2 = json_decode($json2, true);
confere('14. API salva e devolve o rascunho do próprio usuário, sem o código BDP', $s === 200 && ($j2['rascunho']['campos']['observacao'] ?? '') === 'rascunho pela API' && !isset($j2['rascunho']['campos']['codigo_bdp']));
[$s] = $aux->ir('admin/api/rascunhos/rascunho.php', ['formulario' => 'registrar_exemplar', 'referencia' => 'x', 'campos' => ['a' => 'b']]);
confere('14. API recusa POST sem CSRF', $s === 419);

foreach (['lotes.php', 'lotes.php?status=encerrado', 'lotes.php?novo=1', 'lote.php?id=' . $l1, 'lote.php?id=' . $l2, 'lote.php?id=' . $lPag, 'lote.php?id=' . $loteAntigo, 'lotes-produtividade.php',
             'lotes-produtividade.php?periodo=semana', 'lotes-produtividade.php?periodo=mes&colaborador=' . $c3, 'duvidas.php', 'duvidas.php?status=todas&lote=' . $l1, 'planilha-bdp.php?aba=lotes', 'cadastrar-exemplar.php?livro=' . $livro] as $p) {
    [$s, $html] = $nav->ir('admin/' . $p);
    confere("página $p abre sem erro técnico", $s === 200 && teste_sem_erro_tecnico($html), "status $s");
}
$cons = new TesteNavegador($base);
$cons->entrar('cons@teste.br', $senha);
$t = $tok($cons, 'admin/lote.php?id=' . $l2);
[$s] = $cons->ir('admin/lote.php', ['csrf' => $t, 'id' => (string)$l2, 'acao' => 'carimbar', 'ids' => [(string)$r5[0]['id']], 'confirmo' => '1']);
confere('16. perfil consulta não carimba (403)', $s === 403);

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
