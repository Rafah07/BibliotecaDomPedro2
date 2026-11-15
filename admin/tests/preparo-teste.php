<?php
/**
 * Testes do preparo e controle dos exemplares (migração 150).
 *
 * Uso: php admin/tests/preparo-teste.php
 *
 * Tudo acontece num banco temporário "bdp_teste_preparo_XXXX" (apagado no final), com dados FICTÍCIOS.
 * Cenários (os pedidos para esta etapa):
 *    1. vários registros seguidos, no mesmo lote e em ordem;           2. códigos BDP únicos;
 *    3. registros aparecem na Planilha de Códigos BDP;                  4. impressão só do lote escolhido;
 *    5. confirmação de carimbo (e liberação só quando organizado);      6. registro sem localização (triagem);
 *    7. localização sugerida;                                           8. troca manual da sugestão;
 *    9. movimentação de muitos exemplares (tudo ou nada, sem repetir);  10. dano sem bloqueio;
 *   11. dano com bloqueio;                                              12. resolução (sem liberar se houver outro bloqueio);
 *   13. mapa salvo e consultado (sem mexer em exemplares);              14. relatório de atividades com dados reais;
 *   15. desfazer movimentação;                                          16. backup gerado e conferido;
 *   17. integridade de obras, exemplares e códigos BDP; páginas sem erro de PHP.
 */

use Biblioteca\Repositories\OrganizacaoFisicaRepository;
use Biblioteca\Repositories\PreparoRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\BackupService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\LoteService;
use Biblioteca\Services\OcorrenciaService;
use Biblioteca\Services\OperacaoService;
use Biblioteca\Services\OrganizacaoFisicaService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('preparo');
$_SERVER['SCRIPT_NAME'] = '/admin/tests/preparo-teste.php';
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
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br', 'administrador' => 'adm@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bib = $contas['bibliotecaria'];
$aux = $contas['auxiliar'];

// Mesma regra padrão do painel: só libera para empréstimo o exemplar carimbado e com estante definitiva.
$ex = new ExemplarService($pdo);
$ex->definirRegrasPreparo(false, false);
$org = new OrganizacaoFisicaService($pdo, $ex);
$orgRepo = new OrganizacaoFisicaRepository($pdo);
$ops = new OperacaoService($pdo, $ex);
$oc = new OcorrenciaService($pdo, $ex);
$lotes = new LoteService($pdo);
$prep = new PreparoRepository($pdo);

$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Obra Fictícia do Preparo', NOW()), ('Segunda Obra Fictícia', NOW())");
$livro = (int)$val("SELECT id FROM livros WHERE titulo = 'Obra Fictícia do Preparo'");
$livro2 = (int)$val("SELECT id FROM livros WHERE titulo = 'Segunda Obra Fictícia'");
$estA = $org->criarEstante(['identificacao' => 'A', 'prateleiras' => 3], $bib);
$estB = $org->criarEstante(['identificacao' => 'B', 'prateleiras' => 2], $bib);
$pratA = array_map('intval', array_column(db_query('SELECT id FROM localizacoes WHERE estante_id = ? ORDER BY ordem, id', [$estA])->fetchAll(), 'id'));
$pratB = array_map('intval', array_column(db_query('SELECT id FROM localizacoes WHERE estante_id = ? ORDER BY ordem, id', [$estB])->fetchAll(), 'id'));

// ---------------------------------------------------------------
echo "\n1–3. Registros seguidos, códigos únicos e Planilha de Códigos BDP\n";
$loteNum = $lotes->abrirNovo($bib, 'Equipe 1');
$reg = [];
for ($i = 0; $i < 5; $i++) {
    $reg[] = $ex->cadastrar($livro, ['estado_conservacao' => 'nao_avaliado', 'situacao' => 'disponivel'], $bib)[0];
}
$reg = array_merge($reg, $ex->cadastrar($livro2, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bib, 3));
$ids = array_column($reg, 'id');
confere('1. oito registros seguidos ficam no mesmo lote, na ordem 1 a 8', count(array_unique(array_column($reg, 'lote_id'))) === 1 && $reg[0]['lote_id'] === $loteNum
    && array_column($reg, 'lote_ordem') === range(1, 8), json_encode(array_column($reg, 'lote_ordem')));
confere('1. o lote guarda a equipe e o total', $val('SELECT equipe FROM cadastro_lotes WHERE id = ?', [$loteNum]) === 'Equipe 1' && $num('SELECT total FROM cadastro_lotes WHERE id = ?', [$loteNum]) === 8);
confere('1. todos entram como "Aguardando carimbo" e não ficam disponíveis', $num("SELECT COUNT(*) FROM exemplares WHERE id IN (" . implode(',', $ids) . ") AND carimbo_status = 'aguardando' AND situacao = 'aguardando' AND liberacao_pendente = 1") === 8);
$codigos = array_column($reg, 'codigo');
confere('2. códigos BDP únicos, no formato BDP-000000 e seguidos', count(array_unique($codigos)) === 8 && !array_filter($codigos, fn ($c) => !preg_match('/^BDP-\d{6}$/', $c))
    && (int)substr(end($codigos), 4) - (int)substr($codigos[0], 4) === 7);
confere('2. o banco não aceita repetir um código', $recusa(fn () => $pdo->exec("UPDATE exemplares SET codigo_individual = '{$codigos[0]}' WHERE id = {$ids[1]}")) !== null);
confere('2. código digitado que já existe é recusado', ($m = $recusa(fn () => $ex->cadastrarComCodigo($livro, $codigos[2], ['estado_conservacao' => 'bom'], $bib))) !== null && str_contains($m, 'já pertence'));
$plan = $prep->planilha(['carimbo' => 'aguardando', 'organizacao' => 'todos', 'encontrado' => 'todos', 'ordem' => 'lote'], 1, 50);
confere('3. os 8 registros aparecem na Planilha (aguardando carimbo), na ordem do lote', $plan['total'] === 8 && array_column($plan['itens'], 'codigo_individual') === $codigos);
$ind = $prep->indicadores();
confere('3. indicadores separados: 8 aguardando carimbo e 8 aguardando organização', $ind['aguardando_carimbo'] === 8 && $ind['aguardando_organizacao'] === 8 && $ind['carimbados'] === 0, json_encode($ind));

// Ordem e lote são imutáveis.
confere('1. a ordem no lote não pode ser alterada depois', $recusa(fn () => $pdo->exec("UPDATE exemplares SET lote_ordem = 99 WHERE id = {$ids[0]}")) !== null);

// ---------------------------------------------------------------
echo "\n4. Lotes separados\n";
$lote2 = $lotes->abrirNovo($bib, 'Equipe 2');
$r2 = $ex->cadastrar($livro2, ['estado_conservacao' => 'nao_avaliado', 'situacao' => 'disponivel'], $bib, 2);
confere('4. novo lote recebe o número seguinte e começa na ordem 1', $lote2 !== $loteNum && $num('SELECT numero FROM cadastro_lotes WHERE id = ?', [$lote2]) === $num('SELECT numero FROM cadastro_lotes WHERE id = ?', [$loteNum]) + 1
    && array_column($r2, 'lote_ordem') === [1, 2] && $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$loteNum]) === 'fechado');
confere('4. lote aberto e vazio é reaproveitado (não gasta número)', $lotes->abrirNovo($aux, 'Equipe 3') === $lotes->abrirNovo($aux, 'Equipe 3b'));
$soLote1 = $prep->planilha(['carimbo' => 'todos', 'organizacao' => 'todos', 'encontrado' => 'todos', 'lote' => (string)$loteNum, 'ordem' => 'lote'], 1, 50);
confere('4. filtro por lote traz só os exemplares daquele lote', $soLote1['total'] === 8 && !array_intersect(array_column($soLote1['itens'], 'codigo_individual'), array_column($r2, 'codigo')));

// ---------------------------------------------------------------
echo "\n5–6. Carimbo e área de triagem\n";
confere('6. registro sem localização fica na triagem', $val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$ids[0]]) === null);
$carimbados = $ex->marcarCarimbado([$ids[0], $ids[1], $ids[0]], $aux, 'carimbo na folha de rosto');
confere('5. carimbo confirmado em 2 exemplares (repetição ignorada), com quem e quando', count($carimbados) === 2
    && $num("SELECT COUNT(*) FROM exemplares WHERE id IN ({$ids[0]}, {$ids[1]}) AND carimbo_status = 'carimbado' AND carimbado_por = ? AND carimbado_em IS NOT NULL", [$aux]) === 2);
confere('5. carimbado mas ainda na triagem: continua indisponível para empréstimo', $val('SELECT situacao FROM exemplares WHERE id = ?', [$ids[0]]) === 'aguardando');
confere('5. marcar de novo não grava nada', $ex->marcarCarimbado([$ids[0]], $aux) === []);
$ex->transferir($ids[0], $pratA[0], $bib, 'organização');
confere('5. carimbado + estante definitiva: liberado para empréstimo', $val('SELECT situacao FROM exemplares WHERE id = ?', [$ids[0]]) === 'disponivel'
    && (int)$val('SELECT liberacao_pendente FROM exemplares WHERE id = ?', [$ids[0]]) === 0);
$ex->transferir($ids[2], $pratA[0], $bib, 'organização');
confere('5. com estante mas sem carimbo: continua aguardando', $val('SELECT situacao FROM exemplares WHERE id = ?', [$ids[2]]) === 'aguardando');
$livre = new ExemplarService($pdo);
$livre->definirRegrasPreparo(true, false);
$rx = $livre->cadastrarComCodigo($livro, 'BDP-000500', ['estado_conservacao' => 'bom', 'localizacao_id' => $pratA[1]], $bib);
confere('5. código digitado do carimbo entra "Carimbado" e, com estante, já disponível', $rx['carimbo'] === 'carimbado' && $rx['situacao'] === 'disponivel');
confere('5. regra "emprestar sem carimbo" respeitada quando ligada', $livre->cadastrar($livro, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $pratA[1]], $bib)[0]['situacao'] === 'disponivel');

// ---------------------------------------------------------------
echo "\n7–8. Localização sugerida\n";
confere('7. a última prateleira usada fica guardada para a sugestão', (int)$val('SELECT ultima_localizacao_id FROM administradores WHERE id = ?', [$bib]) === $pratA[1]);

// ---------------------------------------------------------------
echo "\n9. Movimentação em lote\n";
$mover = [$ids[3], $ids[4], $ids[5], $ids[6]];
$movAntes = $num('SELECT COUNT(*) FROM movimentacoes');
$op = $ops->realizar('movimentacao_lote', 'Teste: 4 exemplares para B1', $bib, function () use ($ex, $mover, $pratB, $bib) {
    $n = 0;
    foreach ($mover as $i) {
        $n += $ex->transferir($i, $pratB[0], $bib, 'movimentação em lote') ? 1 : 0;
    }
    return $n;
}, OperacaoService::novaChave());
confere('9. os 4 exemplares mudaram de lugar numa única operação', $op['resultado'] === 4 && $op['total'] === 4
    && $num('SELECT COUNT(*) FROM exemplares WHERE localizacao_id = ? AND id IN (' . implode(',', $mover) . ')', [$pratB[0]]) === 4);
confere('9. cada mudança tem histórico (anterior, nova, responsável, operação)', $num("SELECT COUNT(*) FROM movimentacoes WHERE operacao_id = ? AND tipo = 'localizacao' AND responsavel_id = ?", [$op['id'], $bib]) === 4);
$chave = OperacaoService::novaChave();
$ops->realizar('mover', 'chave repetida', $bib, fn () => $ex->transferir($ids[7], $pratB[1], $bib), $chave);
confere('9. clique duplo (mesma chave) não grava duas vezes', $recusa(fn () => $ops->realizar('mover', 'chave repetida', $bib, fn () => $ex->transferir($ids[7], $pratB[0], $bib), $chave)) !== null
    && $num('SELECT localizacao_id FROM exemplares WHERE id = ?', [$ids[7]]) === $pratB[1]);
$org->definirPrateleiraAtiva($pratA[2], false, $bib);
$antes = array_map(fn ($i) => $val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$i]), $mover);
$falhou = $recusa(fn () => $ops->realizar('movimentacao_lote', 'deve falhar', $bib, function () use ($ex, $mover, $pratA, $bib) {
    foreach ($mover as $k => $i) {
        $ex->transferir($i, $k < 2 ? $pratA[1] : $pratA[2], $bib); // os 2 últimos vão para uma prateleira desativada
    }
}));
confere('9. falha no meio: nada fica pela metade', $falhou !== null && $antes === array_map(fn ($i) => $val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$i]), $mover)
    && !$num("SELECT COUNT(*) FROM operacoes WHERE descricao = 'deve falhar'"));

// ---------------------------------------------------------------
echo "\n10–12. Ocorrências e disponibilidade\n";
$alvo = $ids[0]; // carimbado, organizado, disponível
$o1 = $oc->registrar($alvo, ['tipo' => 'capa_danificada', 'descricao' => 'Capa levemente dobrada', 'bloquear' => '0'], $aux);
confere('10. dano leve sem bloqueio: continua disponível', !$o1['bloqueou'] && $val('SELECT situacao FROM exemplares WHERE id = ?', [$alvo]) === 'disponivel');
confere('10. a pergunta do bloqueio é obrigatória', $recusa(fn () => $oc->registrar($alvo, ['tipo' => 'outro', 'descricao' => 'x', 'bloquear' => ''], $aux)) !== null);
$o2 = $oc->registrar($alvo, ['tipo' => 'encadernacao_danificada', 'descricao' => 'Caderno do meio solto', 'bloquear' => '1'], $aux);
confere('11. dano com bloqueio: indisponível (Danificado)', $o2['bloqueou'] && $val('SELECT situacao FROM exemplares WHERE id = ?', [$alvo]) === 'danificado'
    && OcorrenciaService::bloqueiaEmprestimo($pdo, $alvo));
$o3 = $oc->registrar($alvo, ['tipo' => 'molhado', 'descricao' => 'Mancha de água', 'bloquear' => '1'], $aux);
$res = $oc->resolver($o2['id'], 'Caderno colado', true, $bib);
confere('12. resolver uma ocorrência não libera se outra ainda bloqueia', !$res['liberado'] && $val('SELECT situacao FROM exemplares WHERE id = ?', [$alvo]) === 'danificado'
    && $val('SELECT status FROM exemplar_ocorrencias WHERE id = ?', [$o2['id']]) === 'resolvida');
$res = $oc->resolver($o3['id'], 'Secou e foi prensado', true, $bib);
confere('12. resolvida a última que bloqueia: volta a ficar disponível', $res['liberado'] && $val('SELECT situacao FROM exemplares WHERE id = ?', [$alvo]) === 'disponivel');
confere('12. ocorrências não podem ser apagadas', $recusa(fn () => $pdo->exec("DELETE FROM exemplar_ocorrencias WHERE id = {$o1['id']}")) !== null);
$oPrep = $oc->registrar($ids[2], ['tipo' => 'outro', 'descricao' => 'Lombada rasgada', 'bloquear' => '1'], $aux);
$oc->resolver($oPrep['id'], 'Lombada colada', true, $bib);
confere('12. exemplar ainda sem carimbo volta para "aguardando", não para disponível', $val('SELECT situacao FROM exemplares WHERE id = ?', [$ids[2]]) === 'aguardando');

// ---------------------------------------------------------------
echo "\n13. Mapa das estantes\n";
$movAntes = $num('SELECT COUNT(*) FROM movimentacoes');
$locAntes = db_query('SELECT id, localizacao_id FROM exemplares ORDER BY id')->fetchAll();
$n = $org->salvarMapa([$estA => ['x' => 2, 'y' => 1, 'w' => 6, 'h' => 2], $estB => ['x' => 30, 'y' => 4, 'w' => 4, 'h' => 3]], $bib);
$mapa = array_column($orgRepo->mapa('sem'), null, 'id');
confere('13. posições salvas e consultadas (com limite da grade)', $n === 2 && (int)$mapa[$estA]['mapa_x'] === 2 && (int)$mapa[$estA]['mapa_largura'] === 6
    && (int)$mapa[$estB]['mapa_x'] === OrganizacaoFisicaService::MAPA_COLUNAS - 4);
confere('13. o mapa mostra prateleiras e exemplares reais', (int)$mapa[$estB]['exemplares'] === $num('SELECT COUNT(*) FROM exemplares e JOIN localizacoes l ON l.id = e.localizacao_id WHERE l.estante_id = ?', [$estB]) && (int)$mapa[$estA]['prateleiras'] === 3);
confere('13. salvar o mapa não muda nenhum exemplar', $movAntes === $num('SELECT COUNT(*) FROM movimentacoes') && $locAntes === db_query('SELECT id, localizacao_id FROM exemplares ORDER BY id')->fetchAll());

// ---------------------------------------------------------------
echo "\n14. Relatório de atividades\n";
$hoje = date('Y-m-d');
$at = $prep->atividades($hoje, $hoje);
$reais = [
    'exemplares_registrados' => $num("SELECT COUNT(*) FROM movimentacoes WHERE tipo = 'cadastro'"),
    'carimbados' => $num("SELECT COUNT(*) FROM movimentacoes WHERE tipo = 'carimbo' AND JSON_EXTRACT(detalhes, '$.para') = 'carimbado'"),
    'ocorrencias' => $num('SELECT COUNT(*) FROM exemplar_ocorrencias'),
];
confere('14. totais iguais aos registros reais do dia', $at['totais']['exemplares_registrados'] === $reais['exemplares_registrados'] && $at['totais']['carimbados'] === $reais['carimbados']
    && $at['totais']['ocorrencias'] === $reais['ocorrencias'], json_encode([$at['totais'], $reais]));
confere('14. por pessoa: a auxiliar aparece com os carimbos e as ocorrências', ($at['por_pessoa'][$aux]['carimbados'] ?? 0) === 2 && ($at['por_pessoa'][$aux]['ocorrencias'] ?? 0) === 4);
$soAux = $prep->atividades($hoje, $hoje, [$aux]);
confere('14. filtro por administrador', ($soAux['totais']['exemplares_registrados'] ?? -1) === 0 && $soAux['totais']['carimbados'] === 2);

// ---------------------------------------------------------------
echo "\n15. Desfazer movimentação\n";
$analise = $ops->analisar($op['id']);
confere('15. movimentação recente pode ser desfeita com segurança', $analise['pode'] === true, json_encode($analise['motivos']));
$codAntes = $val('SELECT codigo_individual FROM exemplares WHERE id = ?', [$mover[0]]);
$desf = $ops->desfazer($op['id'], $bib, 'movido para a estante errada', OperacaoService::novaChave());
confere('15. os exemplares voltaram para onde estavam', $num('SELECT COUNT(*) FROM exemplares WHERE localizacao_id IS NULL AND id IN (' . implode(',', $mover) . ')') === 4);
confere('15. a reversão é uma nova operação e a original fica marcada como desfeita', $val('SELECT tipo FROM operacoes WHERE id = ?', [$desf]) === 'desfazer'
    && (int)$val('SELECT desfeita_por_operacao_id FROM operacoes WHERE id = ?', [$op['id']]) === $desf);
confere('15. o histórico original não foi apagado e o código BDP não mudou', $num('SELECT COUNT(*) FROM movimentacoes WHERE operacao_id = ?', [$op['id']]) === 4
    && $val('SELECT codigo_individual FROM exemplares WHERE id = ?', [$mover[0]]) === $codAntes);
confere('15. não dá para desfazer duas vezes', $recusa(fn () => $ops->desfazer($op['id'], $bib)) !== null);
$op2 = $ops->realizar('mover', 'mover e mover de novo', $bib, fn () => $ex->transferir($ids[3], $pratB[1], $bib));
$ex->transferir($ids[3], $pratB[0], $bib, 'outra mudança depois');
confere('15. mudança seguida de outra não é desfeita sozinha', $ops->analisar($op2['id'])['pode'] === false);

// ---------------------------------------------------------------
echo "\n16. Cópia de segurança\n";
$pasta = sys_get_temp_dir() . '/bdp-teste-backups-' . bin2hex(random_bytes(4));
$GLOBALS['teste_temporarios'][] = $pasta;
$b = (new BackupService($pdo))->salvarNoServidor($pasta, $contas['administrador'], 'servidor', 'teste');
$conteudo = (string)@file_get_contents($b['caminho']);
confere('16. arquivo gerado, completo e com o resumo conferido', is_file($b['caminho']) && filesize($b['caminho']) === $b['tamanho'] && BackupService::terminaCompleto($b['caminho'])
    && hash_file('sha256', $b['caminho']) === $b['sha256'] && str_contains($conteudo, 'exemplar_ocorrencias'));
confere('16. registrado com quem fez e "conferido"', $num('SELECT COUNT(*) FROM backups WHERE arquivo = ? AND verificado = 1 AND administrador_id = ?', [$b['arquivo'], $contas['administrador']]) === 1);
confere('16. pasta protegida contra acesso público', is_file($pasta . '/.htaccess') && str_contains((string)file_get_contents($pasta . '/.htaccess'), 'Require all denied'));
confere('16. nenhuma senha em texto puro na cópia', !str_contains($conteudo, $senha));
foreach (glob($pasta . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
@rmdir($pasta);

// ---------------------------------------------------------------
echo "\n17. Integridade\n";
confere('17. nenhum exemplar sem obra e nenhum código repetido', !$num('SELECT COUNT(*) FROM exemplares e LEFT JOIN livros l ON l.id = e.livro_id WHERE l.id IS NULL')
    && $num('SELECT COUNT(*) FROM exemplares') === $num('SELECT COUNT(DISTINCT codigo_individual) FROM exemplares'));
confere('17. nenhuma ordem repetida dentro de um lote', !$num('SELECT COUNT(*) FROM (SELECT lote_id, lote_ordem FROM exemplares WHERE lote_id IS NOT NULL GROUP BY lote_id, lote_ordem HAVING COUNT(*) > 1) x'));
confere('17. total de cada lote confere com os exemplares dele', !$num('SELECT COUNT(*) FROM cadastro_lotes lt WHERE lt.total <> (SELECT COUNT(*) FROM exemplares e WHERE e.lote_id = lt.id)'));
confere('17. sequência BDP nunca fica atrás do maior código usado', $num('SELECT ultimo FROM exemplar_codigo_seq WHERE id = 1') >= $num("SELECT MAX(CAST(SUBSTRING(codigo_individual, 5) AS UNSIGNED)) FROM exemplares WHERE codigo_individual <> 'BDP-000500'"));
confere('17. nenhuma movimentação ou ocorrência órfã', !$num('SELECT (SELECT COUNT(*) FROM movimentacoes v LEFT JOIN exemplares e ON e.id = v.exemplar_id WHERE e.id IS NULL)
                                                              + (SELECT COUNT(*) FROM exemplar_ocorrencias o LEFT JOIN exemplares e ON e.id = o.exemplar_id WHERE e.id IS NULL)'));

// ---------------------------------------------------------------
echo "\n7–8 e páginas do painel\n";
$base = teste_servidor($env);
$nav = new TesteNavegador($base);
confere('bibliotecária entra no painel', $nav->entrar('bib@teste.br', $senha) === 200);
$tok = function (TesteNavegador $n, string $pagina): string {
    [, $html] = $n->ir($pagina);
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
};
$pdo->prepare('UPDATE administradores SET ultima_localizacao_id = ? WHERE id = ?')->execute([$pratB[1], $bib]);
[$s, $html] = $nav->ir('admin/cadastrar-exemplar.php?livro=' . $livro);
confere('7. o cadastro sugere a última prateleira usada (e permite limpar)', $s === 200 && str_contains($html, 'Localização sugerida: ') && str_contains($html, 'última utilizada')
    && str_contains($html, 'data-local-limpar') && preg_match('/<option value="' . $pratB[1] . '" selected/', $html));
$t = $tok($nav, 'admin/cadastrar-exemplar.php?livro=' . $livro);
[$s] = $nav->ir('admin/cadastrar-exemplar.php', ['csrf' => $t, 'livro_id' => (string)$livro, 'quantidade' => '1', 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => (string)$pratA[0], 'confirmar' => '1']);
$novo = db_query('SELECT * FROM exemplares ORDER BY id DESC LIMIT 1')->fetch();
confere('8. trocar a sugestão à mão grava a prateleira escolhida', $s === 303 && (int)$novo['localizacao_id'] === $pratA[0] && (int)$val('SELECT ultima_localizacao_id FROM administradores WHERE id = ?', [$bib]) === $pratA[0]);
[$s] = $nav->ir('admin/cadastrar-exemplar.php', ['csrf' => $t, 'livro_id' => (string)$livro, 'quantidade' => '1', 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => '', 'confirmar' => '1']);
confere('8. "Aguardando organização" (vazio) deixa na triagem', $s === 303 && $val('SELECT localizacao_id FROM exemplares ORDER BY id DESC LIMIT 1') === null);

[$s, $html] = $nav->ir('admin/planilha-bdp.php?' . http_build_query(['saida' => 'imprimir', 'carimbo' => 'todos', 'lote' => $lote2]));
confere('4. "Reimprimir lote" mostra só os códigos do lote escolhido', $s === 200 && str_contains($html, $r2[0]['codigo']) && str_contains($html, $r2[1]['codigo']) && !str_contains($html, $codigos[3]));
[$s, , , $tipo] = $nav->ir('admin/planilha-bdp.php?' . http_build_query(['saida' => 'exportar', 'formato' => 'xlsx', 'carimbo' => 'aguardando']));
confere('3. "Exportar para Excel" gera um .xlsx', $s === 200 && str_contains((string)$tipo, 'spreadsheetml'));
$t = $tok($nav, 'admin/planilha-bdp.php');
[$s] = $nav->ir('admin/planilha-bdp.php', ['csrf' => $t, 'unico' => 'carimbar:' . $r2[0]['id']]);
confere('5. "Marcar como carimbado" pela página', $s === 303 && $val('SELECT carimbo_status FROM exemplares WHERE id = ?', [$r2[0]['id']]) === 'carimbado');
[$s, , , $tipo] = $nav->ir('admin/relatorio-atividades.php?exportar=xlsx');
confere('14. Relatório de Atividades exporta para Excel', $s === 200 && str_contains((string)$tipo, 'spreadsheetml'));
foreach (['planilha-bdp.php', 'planilha-bdp.php?aba=triagem', 'planilha-bdp.php?aba=lotes', 'movimentacao-lote.php', 'movimentacao-lote.php?origem=triagem', 'ocorrencias.php', 'ocorrencias.php?status=todas',
             'acoes-recentes.php', 'acoes-recentes.php?operacao=' . $op['id'], 'relatorio-atividades.php', 'organizacao-mapa.php', 'organizacao-fisica.php', 'detalhes-exemplar.php?id=' . $alvo] as $p) {
    [$s, $html] = $nav->ir('admin/' . $p);
    confere("página $p abre sem erro técnico", $s === 200 && teste_sem_erro_tecnico($html), "status $s");
}
$t = $tok($nav, 'admin/organizacao-mapa.php');
[$s] = $nav->ir('admin/organizacao-mapa.php', ['csrf' => $t, 'setor' => 'sem', 'acao' => 'salvar', 'posicao' => [$estA => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]]]);
confere('13. salvar o mapa pela página', $s === 303 && (int)$val('SELECT mapa_x FROM estantes WHERE id = ?', [$estA]) === 0 && (int)$val('SELECT mapa_largura FROM estantes WHERE id = ?', [$estA]) === 4);
$cons = new TesteNavegador($base);
$cons->entrar('cons@teste.br', $senha);
$t = $tok($cons, 'admin/organizacao-mapa.php');
[$s] = $cons->ir('admin/organizacao-mapa.php', ['csrf' => $t, 'setor' => 'sem', 'acao' => 'salvar', 'posicao' => [$estA => ['x' => 9]]]);
confere('13. perfil consulta vê o mapa mas não salva', (int)$val('SELECT mapa_x FROM estantes WHERE id = ?', [$estA]) === 0);
[$s] = $cons->ir('admin/movimentacao-lote.php');
confere('9. perfil consulta não abre a movimentação em lote (403)', $s === 403);

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
