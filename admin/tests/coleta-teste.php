<?php
/**
 * Testes das equipes por link sem login (migração 210): coleta, revisão e carimbagem.
 *
 * Uso: php admin/tests/coleta-teste.php
 *
 * Banco temporário "bdp_teste_coleta_XXXX" (apagado no final), dados FICTÍCIOS. Cenários:
 *   A. links: validade escolhida pelo administrador, token só como hash, expirado/desativado não abre;
 *   B. pré-cadastro: campos obrigatórios (com "sem autor/editora/data"), ISBN e CDD, número sequencial;
 *   C. vagas do lote: a quantidade ocupa vagas na hora, nunca passa da capacidade, retirar devolve;
 *   D. pausa: grava onde parou, bloqueia a etapa até retomar;
 *   E. revisão: aprovar cria obra/exemplares com código BDP no lote; recusar exige motivo;
 *   F. carimbagem pelo link: só depois de fechado, marca carimbos e conclui a etapa;
 *   G. páginas: link público (token sai do endereço, CSRF, cabeçalhos), seção do lote, revisão.
 */

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ColetaService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\LoteService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('coleta');
$_SERVER['SCRIPT_NAME'] = '/admin/tests/coleta-teste.php';
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
foreach (['adm' => 'adm@teste.br', 'bib' => 'bib@teste.br'] as $k => $email) {
    $pdo->prepare("INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'administrador')")->execute(['Administração Fictícia ' . strtoupper($k), $email, password_hash($senha, PASSWORD_DEFAULT)]);
    $contas[$k] = (int)$pdo->lastInsertId();
}
[$adm, $bib] = [$contas['adm'], $contas['bib']];

$ex = new ExemplarService($pdo);
$ex->definirRegrasPreparo(false, false);
$lotes = new LoteService($pdo, $ex);
$col = new ColetaService($pdo, $ex, $lotes);

$livroAntigo = (function () use ($pdo) {
    $pdo->exec("INSERT INTO livros (titulo, isbn, dados_verificados_em) VALUES ('Obra Fictícia Já Cadastrada', '9788535902778', NOW())");
    return (int)$pdo->lastInsertId();
})();
$lote = $lotes->criar(['capacidade' => 5, 'usuarios' => [$bib], 'descricao' => 'Lote da coleta fictícia'], $adm);
$dados = fn (array $extra = []) => ColetaService::validar($extra + ['titulo' => 'Livro Fictício', 'autor' => 'SILVA, Ana Fictícia', 'editora' => 'Editora Teste', 'ano' => '2020', 'tem_isbn' => '0', 'tem_cdd' => '0', 'quantidade' => '1'])[0];

// ---------------------------------------------------------------
echo "\nA. Links\n";
$pdo->exec("INSERT INTO cadastro_lotes (numero, responsavel_id) VALUES (900, $adm)");
$loteAuto = (int)$pdo->lastInsertId();
confere('A1. lote sem capacidade (automático) não recebe link', str_contains((string)$recusa(fn () => $col->criarLink($loteAuto, 'coleta', 'Equipe 1', date('Y-m-d', strtotime('+5 days')), $adm)), 'capacidade'));
confere('A2. validade no passado é recusada', $recusa(fn () => $col->criarLink($lote, 'coleta', 'Equipe 1', date('Y-m-d', strtotime('-1 day')), $adm)) !== null);
confere('A3. validade acima de 365 dias é recusada', $recusa(fn () => $col->criarLink($lote, 'coleta', 'Equipe 1', date('Y-m-d', strtotime('+400 days')), $adm)) !== null);
confere('A4. sem nome de equipe é recusado', $recusa(fn () => $col->criarLink($lote, 'coleta', ' ', date('Y-m-d', strtotime('+5 days')), $adm)) !== null);
$lk = $col->criarLink($lote, 'coleta', 'Equipe 1', date('Y-m-d', strtotime('+10 days')), $adm);
confere('A5. link criado: token de 48 caracteres e só o hash no banco', strlen($lk['token']) === 48
    && $val('SELECT token_hash FROM coleta_links WHERE id = ?', [$lk['id']]) === hash('sha256', $lk['token'])
    && $num('SELECT COUNT(*) FROM coleta_links WHERE token_hash = ?', [$lk['token']]) === 0);
confere('A6. validade vai até 23:59 do dia escolhido', str_ends_with((string)$val('SELECT expira_em FROM coleta_links WHERE id = ?', [$lk['id']]), '23:59:59'));
$link = $col->abrirPorToken($lk['token']);
confere('A7. o token abre o link do lote certo', $link['lote_id'] === $lote && $link['tipo'] === 'coleta' && $link['criado_por'] === $adm);
confere('A8. token inválido ou incompleto não abre', $recusa(fn () => $col->abrirPorToken('abc')) !== null && $recusa(fn () => $col->abrirPorToken(str_repeat('a', 48))) !== null);
$lkVelho = $col->criarLink($lote, 'coleta', 'Equipe Antiga', date('Y-m-d'), $adm);
$pdo->prepare('UPDATE coleta_links SET expira_em = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', strtotime('-1 minute')), $lkVelho['id']]);
confere('A9. link expirado não abre e diz quando expirou', str_contains((string)$recusa(fn () => $col->abrirPorToken($lkVelho['token'])), 'expirou'));
$lkRev = $col->criarLink($lote, 'coleta', 'Equipe Revogada', date('Y-m-d', strtotime('+3 days')), $adm);
$col->revogarLink($lkRev['id'], $adm);
confere('A10. link desativado não abre', str_contains((string)$recusa(fn () => $col->abrirPorToken($lkRev['token'])), 'desativado'));
confere('A11. links listados com a situação (ativo, expirado, desativado)', array_count_values(array_column($col->links($lote), 'situacao')) == ['ativo' => 1, 'expirado' => 1, 'desativado' => 1]);

// ---------------------------------------------------------------
echo "\nB. Pré-cadastro: validação\n";
[, $e] = ColetaService::validar([]);
confere('B1. vazio: título, autor, editora, ano, ISBN e CDD obrigatórios', !array_diff(['titulo', 'autor', 'editora', 'ano', 'tem_isbn', 'tem_cdd'], array_keys($e)));
[$d, $e] = ColetaService::validar(['titulo' => 'Sem dados', 'sem_autor' => '1', 'sem_editora' => '1', 'sem_ano' => '1', 'tem_isbn' => '0', 'tem_cdd' => '0', 'quantidade' => '1']);
confere('B2. "sem autor/editora/data" dispensa os campos', !$e && $d['autor'] === null && $d['editora'] === null && $d['ano'] === null);
[, $e] = ColetaService::validar(['titulo' => 'X', 'autor' => 'A', 'editora' => 'B', 'ano' => '2020', 'tem_isbn' => '1', 'isbn' => '9788535902779', 'tem_cdd' => '0', 'quantidade' => '1']);
confere('B3. ISBN com dígito errado é recusado', isset($e['isbn']) && str_contains($e['isbn'], 'não confere'));
[$d, $e] = ColetaService::validar(['titulo' => 'X', 'autor' => 'A', 'editora' => 'B', 'ano' => '2020', 'tem_isbn' => '1', 'isbn' => '978-85-359-0277-8', 'tem_cdd' => '1', 'cdd' => '869,3', 'quantidade' => '2']);
confere('B4. ISBN com hífens e CDD com vírgula são normalizados', !$e && $d['isbn'] === '9788535902778' && $d['cdd'] === '869.3' && $d['quantidade'] === 2);
[, $e] = ColetaService::validar(['titulo' => 'X', 'autor' => 'A', 'editora' => 'B', 'ano' => '2020', 'tem_isbn' => '0', 'tem_cdd' => '1', 'cdd' => 'LIT', 'quantidade' => '0']);
confere('B5. CDD fora do formato e quantidade zero são recusados', isset($e['cdd'], $e['quantidade']));
confere('B6. nome de quem coleta é obrigatório', $recusa(fn () => ColetaService::nomePessoa(' ')) !== null);

// ---------------------------------------------------------------
echo "\nC. Vagas do lote\n";
$i1 = $col->salvarItem($link, 'Aluna Fictícia', $dados(['titulo' => 'Primeiro Livro', 'quantidade' => '3']));
confere('C1. primeiro livro é o nº 1 e ocupa 3 vagas', $i1['numero'] === 1 && $col->vagas($lote)['restantes'] === 2 && $col->vagas($lote)['reservados'] === 3);
confere('C2. mais exemplares que as vagas é recusado (nada gravado)', str_contains((string)$recusa(fn () => $col->salvarItem($link, 'Aluna Fictícia', $dados(['titulo' => 'Grande', 'quantidade' => '3']))), 'só 2 vaga')
    && $num('SELECT COUNT(*) FROM coleta_itens WHERE lote_id = ?', [$lote]) === 1);
$i2 = $col->salvarItem($link, 'Aluno Fictício', $dados(['titulo' => 'Segundo Livro', 'tem_isbn' => '1', 'isbn' => '9788535902778', 'quantidade' => '2']));
confere('C3. segundo livro é o nº 2; ISBN já existente gera aviso (não bloqueia)', $i2['numero'] === 2 && $i2['avisos'] && str_contains($i2['avisos'][0], 'Já existe uma obra'));
confere('C4. lote cheio: vagas restantes 0', $col->vagas($lote)['restantes'] === 0);
$col->salvarItem($link, 'Aluno Fictício', $dados(['titulo' => 'Segundo Livro (corrigido)', 'tem_isbn' => '1', 'isbn' => '9788535902778', 'quantidade' => '1']), $i2['id']);
confere('C5. corrigir o pendente mantém o número e devolve a vaga', $col->item($i2['id'])['numero'] == 2 && $col->item($i2['id'])['titulo'] === 'Segundo Livro (corrigido)' && $col->vagas($lote)['restantes'] === 1);
$i3 = $col->salvarItem($link, 'Aluna Fictícia', $dados(['titulo' => 'Livro Errado', 'quantidade' => '1']));
$col->retirarItem($link, 'Aluna Fictícia', $i3['id']);
confere('C6. retirar guarda a linha como recusada e devolve a vaga', $col->item($i3['id'])['status'] === 'recusado' && str_contains($col->item($i3['id'])['motivo_recusa'], 'Aluna Fictícia') && $col->vagas($lote)['restantes'] === 1);
$i4 = $col->salvarItem($link, 'Aluna Fictícia', $dados(['titulo' => 'Quarto Livro', 'quantidade' => '1']));
confere('C7. o número continua a sequência (não reaproveita o retirado)', $i4['numero'] === 4 && $col->vagas($lote)['restantes'] === 0);
$linkCar = $col->abrirPorToken($col->criarLink($lote, 'carimbagem', 'Equipe 3', date('Y-m-d', strtotime('+10 days')), $adm)['token']);
confere('C8. link de carimbagem não faz pré-cadastro', $recusa(fn () => $col->salvarItem($linkCar, 'Pessoa X', $dados())) !== null);

// ---------------------------------------------------------------
echo "\nD. Pausa\n";
$col->pausar($lote, 'coleta', 'Aluna Fictícia', 'Fim da aula', null, $link['id']);
$p = $col->pausaAberta($lote, 'coleta');
confere('D1. pausa grava quem, motivo e onde parou', $p && $p['pausado_por'] === 'Aluna Fictícia' && $p['motivo'] === 'Fim da aula' && str_contains($p['onde_parou'], 'último nº 4'));
$msgD2 = (string)$recusa(fn () => $col->salvarItem($link, 'Pessoa X', $dados(), $i4['id']));
confere('D2. com a coleta pausada, nada é alterado', str_contains($msgD2, 'pausado'), $msgD2);
confere('D3. não pausa duas vezes a mesma etapa', $recusa(fn () => $col->pausar($lote, 'coleta', 'Outro', '', null, $link['id'])) !== null);
confere('D4. outras etapas continuam (revisão não está pausada)', $col->pausaAberta($lote, 'revisao') === null);
$col->retomar($lote, 'coleta', 'Aluno Fictício');
confere('D5. retomar grava quem e libera a etapa', $col->pausaAberta($lote, 'coleta') === null && $val('SELECT retomado_por FROM coleta_pausas WHERE lote_id = ?', [$lote]) === 'Aluno Fictício');
confere('D6. pausa e retomada ficam no histórico do lote', $num("SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ? AND tipo IN ('coleta_pausada', 'coleta_retomada')", [$lote]) === 2);

// ---------------------------------------------------------------
echo "\nE. Revisão\n";
confere('E1. aprovar sem obra é recusado', str_contains((string)$recusa(fn () => $col->aprovar($i1['id'], $adm)), 'obra'));
confere('E2. recusar sem motivo é recusado', $recusa(fn () => $col->recusar($i4['id'], $adm, ' ')) !== null);
$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Primeiro Livro', NOW())");
$obra1 = (int)$pdo->lastInsertId();
$col->vincularObra($i1['id'], $obra1);
$codigos = $col->aprovar($i1['id'], $adm);
confere('E3. aprovar cria 3 exemplares com códigos BDP no lote', count($codigos) === 3 && $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND livro_id = ?', [$lote, $obra1]) === 3
    && preg_match('/^BDP-\d{6}$/', $codigos[0]) === 1);
confere('E4. o administrador entrou no lote e o lote ativo dele voltou ao que era', $lotes->participa($lote, $adm) && $val('SELECT lote_ativo_id FROM administradores WHERE id = ?', [$adm]) === null);
confere('E5. vagas: 3 registrados, reservas só dos pendentes', $col->vagas($lote) == ['capacidade' => 5, 'registrados' => 3, 'reservados' => 2, 'restantes' => 0, 'itens' => 2]);
confere('E6. aprovado guarda obra, quem e quando', ($it = $col->item($i1['id']))['status'] === 'aprovado' && (int)$it['livro_id'] === $obra1 && (int)$it['revisado_por'] === $adm && $it['revisado_em'] !== null);
confere('E7. ISBN repetido: a revisão oferece a obra existente', array_column($col->obrasComMesmoIsbn($col->item($i2['id'])), 'id') == [$livroAntigo]);
$col->vincularObra($i2['id'], $livroAntigo);
$col->aprovar($i2['id'], $adm);
confere('E8. ligar à obra existente não cria outra obra', $num("SELECT COUNT(*) FROM livros WHERE isbn = '9788535902778'") === 1 && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livroAntigo]) === 1);
$col->recusar($i4['id'], $adm, 'Livro repetido');
confere('E9. recusado guarda o motivo e devolve a vaga', $col->item($i4['id'])['motivo_recusa'] === 'Livro repetido' && $col->vagas($lote)['restantes'] === 1);
confere('E10. não aprova nem recusa de novo', $recusa(fn () => $col->recusar($i1['id'], $adm, 'x')) !== null && $recusa(fn () => $col->aprovar($i4['id'], $adm)) !== null);
$i5 = $col->salvarItem($link, 'Aluna Fictícia', $dados(['titulo' => 'Último Livro']));
$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Último Livro', NOW())");
$col->vincularObra($i5['id'], (int)$pdo->lastInsertId());
$col->pausar($lote, 'revisao', 'Administração Fictícia ADM', '', $adm);
confere('E11. revisão pausada não aprova', str_contains((string)$recusa(fn () => $col->aprovar($i5['id'], $adm)), 'pausado'));
$col->retomar($lote, 'revisao', 'Administração Fictícia ADM');
$col->aprovar($i5['id'], $adm);
confere('E12. o último exemplar enche o lote e ele fecha sozinho', $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$lote]) === 'fechado' && $num('SELECT total FROM cadastro_lotes WHERE id = ?', [$lote]) === 5);
$msgE13 = (string)$recusa(fn () => $col->salvarItem($link, 'Pessoa X', $dados()));
confere('E13. lote fechado não recebe pré-cadastro', str_contains($msgE13, 'não recebe'), $msgE13);

// ---------------------------------------------------------------
echo "\nF. Carimbagem pelo link\n";
$planilha = $col->planilha($lote);
$ids = array_map('intval', array_column($planilha, 'id'));
confere('F1. planilha do lote com os 5 códigos na ordem', count($planilha) === 5 && array_column($planilha, 'lote_ordem') == [1, 2, 3, 4, 5]);
confere('F2. link de coleta não carimba', $recusa(fn () => $col->marcarCarimbado($link, 'Pessoa X', $ids)) !== null);
$outro = $lotes->criar(['capacidade' => 2, 'usuarios' => [$bib]], $adm);
$lotes->escolherAtivo($outro, $bib);
$exOutro = $ex->cadastrar($obra1, ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bib)[0]['id'];
confere('F3. exemplar de outro lote é recusado', str_contains((string)$recusa(fn () => $col->marcarCarimbado($linkCar, 'Carimbador Fictício', [$ids[0], $exOutro])), 'não pertence'));
$m = $col->marcarCarimbado($linkCar, 'Carimbador Fictício', array_slice($ids, 0, 3));
confere('F4. marca 3 exemplares e registra o nome de quem carimbou', count($m) === 3 && $num('SELECT COUNT(*) FROM coleta_carimbos WHERE nome = ?', ['Carimbador Fictício']) === 3
    && $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND carimbo_status = 'carimbado'", [$lote]) === 3);
confere('F5. concluir com exemplares faltando é recusado', str_contains((string)$recusa(fn () => $col->concluirCarimbagem($linkCar, 'Carimbador Fictício')), 'sem carimbo'));
$col->pausar($lote, 'carimbagem', 'Carimbador Fictício', 'Acabou a tinta', null, $linkCar['id']);
confere('F6. pausa da carimbagem guarda quantos foram feitos', str_contains($col->pausaAberta($lote, 'carimbagem')['onde_parou'], '3 de 5'));
confere('F7. carimbagem pausada não marca', str_contains((string)$recusa(fn () => $col->marcarCarimbado($linkCar, 'Pessoa X', [$ids[3]])), 'pausado'));
$col->retomar($lote, 'carimbagem', 'Outra Pessoa Fictícia');
$col->marcarCarimbado($linkCar, 'Outra Pessoa Fictícia', array_slice($ids, 3));
$col->concluirCarimbagem($linkCar, 'Outra Pessoa Fictícia');
confere('F8. com todos carimbados, a equipe conclui e o lote vai para a organização', $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$lote]) === 'aguardando_organizacao'
    && $num("SELECT COUNT(*) FROM lote_eventos WHERE lote_id = ? AND tipo = 'coleta_carimbagem_concluida'", [$lote]) === 1);

// ---------------------------------------------------------------
echo "\nG. Páginas\n";
$lote2 = $lotes->criar(['capacidade' => 4, 'usuarios' => [$bib]], $adm);
$tk2 = $col->criarLink($lote2, 'coleta', 'Equipe 2', date('Y-m-d', strtotime('+10 days')), $adm)['token'];
$base = teste_servidor($env);
$pub = new TesteNavegador($base);
[$s, $html] = $pub->ir('coleta/');
confere('G1. sem o link, a página não abre (403)', $s === 403 && str_contains($html, 'Abra pelo link recebido'));
[$s] = $pub->ir('coleta/?t=' . str_repeat('b', 48));
confere('G2. token errado: 403', $s === 403);
[$s, , $loc, , , $cab] = $pub->ir('coleta/?t=' . $tk2);
confere('G3. link certo: o token sai do endereço (303 para /coleta/)', $s === 303 && str_ends_with($loc, '/coleta/') && !str_contains($loc, $tk2));
confere('G4. cabeçalhos: sem referrer, sem indexação, sem cache', (bool)preg_grep('/^Referrer-Policy: no-referrer/i', $cab) && (bool)preg_grep('/^X-Robots-Tag: noindex/i', $cab) && (bool)preg_grep('/^Cache-Control: no-store/i', $cab));
[$s, $html] = $pub->ir('coleta/');
confere('G5. pede só o nome; nada do painel aparece', $s === 200 && str_contains($html, 'Seu nome') && !str_contains($html, 'administradores.php') && !str_contains($html, 'senha'));
[$s] = $pub->ir('coleta/', ['acao' => 'nome', 'nome' => 'Aluna Fictícia', 'csrf' => 'falso']);
confere('G6. envio sem o token CSRF da sessão: 419', $s === 419);
$csrf = preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $mm) ? $mm[1] : '';
$pub->ir('coleta/', ['acao' => 'nome', 'nome' => 'Aluna Fictícia', 'csrf' => $csrf]);
[$s, $html] = $pub->ir('coleta/');
confere('G7. depois do nome: formulário do livro e vagas do lote', $s === 200 && str_contains($html, 'Novo livro') && str_contains($html, 'Tem ISBN?') && str_contains($html, 'Tem CDD?') && str_contains($html, 'Vagas restantes'));
[$s] = $pub->ir('coleta/', ['acao' => 'salvar', 'csrf' => $csrf, 'titulo' => '', 'tem_isbn' => '0', 'tem_cdd' => '0', 'quantidade' => '1']);
[, $html] = $pub->ir('coleta/');
confere('G8. faltando campos: mostra os erros e não grava', str_contains($html, 'Informe o título') && $num('SELECT COUNT(*) FROM coleta_itens WHERE lote_id = ?', [$lote2]) === 0);
$pub->ir('coleta/', ['acao' => 'salvar', 'csrf' => $csrf, 'titulo' => 'Livro pela Página <b>', 'autor' => 'SOUZA, Caio Fictício', 'editora' => 'Editora Teste', 'ano' => '2018',
    'tem_isbn' => '1', 'isbn' => '8535902775', 'tem_cdd' => '1', 'cdd' => '869.3', 'quantidade' => '2']);
[, $html] = $pub->ir('coleta/');
confere('G9. livro salvo pela página com o nome de quem coletou (e escapado)', $num("SELECT COUNT(*) FROM coleta_itens WHERE lote_id = ? AND coletado_por = 'Aluna Fictícia' AND quantidade = 2 AND isbn = '8535902775'", [$lote2]) === 1
    && str_contains($html, 'Livro nº 1 salvo') && str_contains($html, 'Livro pela Página &lt;b&gt;'));
[$s] = $pub->ir('coleta/', ['acao' => 'pausar', 'csrf' => $csrf, 'motivo' => 'Fim da aula']);
[, $html] = $pub->ir('coleta/');
confere('G10. pausar pela página mostra onde parou e o botão de retomar', str_contains($html, 'Trabalho pausado') && str_contains($html, 'último nº 1') && str_contains($html, 'Retomar trabalho') && !str_contains($html, 'Novo livro'));
$pub->ir('coleta/', ['acao' => 'retomar', 'csrf' => $csrf]);
[, $html] = $pub->ir('coleta/');
confere('G11. retomar volta ao formulário', str_contains($html, 'Novo livro'));
$pdo->exec("INSERT INTO configuracoes_sistema (chave, valor) VALUES ('COLETA_LINKS_ATIVOS', 'false')");
[$s, $html] = (new TesteNavegador($base))->ir('coleta/?t=' . $tk2);
confere('G12. links desligados em Configurações: nada abre', $s === 403 && str_contains($html, 'desligados'));
$pdo->exec("DELETE FROM configuracoes_sistema WHERE chave = 'COLETA_LINKS_ATIVOS'");

$admNav = new TesteNavegador($base);
$admNav->entrar('adm@teste.br', $senha);
[$s, $html] = $admNav->ir('admin/lote.php?id=' . $lote2);
confere('G13. página do lote mostra a seção das equipes, vagas e links', $s === 200 && str_contains($html, 'Equipes por link') && str_contains($html, 'Reservados por pré-cadastros') && str_contains($html, 'Equipe 2'));
$tka = $admNav->csrf('admin/perfil.php');
[$s] = $admNav->ir('admin/lote.php', ['csrf' => $tka, 'id' => $lote2, 'acao' => 'link_criar', 'tipo' => 'carimbagem', 'equipe' => 'Equipe 3', 'expira' => date('Y-m-d', strtotime('+7 days'))]);
[, $html] = $admNav->ir('admin/lote.php?id=' . $lote2);
$urlNova = preg_match('#value="(http[^"]+/coleta/\?t=([a-f0-9]{48}))"#', $html, $mm) ? $mm : null;
confere('G14. criar link pela página mostra o endereço uma vez', $s === 303 && $urlNova !== null);
[, $html] = $admNav->ir('admin/lote.php?id=' . $lote2);
confere('G15. recarregando, o endereço não aparece de novo', !str_contains($html, $urlNova[2] ?? 'x'));
[$s, $html] = $admNav->ir('admin/coleta.php?lote=' . $lote2);
confere('G16. revisão lista o pendente com os dados para conferir', $s === 200 && str_contains($html, 'Livro pela Página &lt;b&gt;') && str_contains($html, 'Aprovar e gerar 2 código(s) BDP'));
$itemPag = (int)$val('SELECT id FROM coleta_itens WHERE lote_id = ?', [$lote2]);
[$s] = $admNav->ir('admin/coleta.php', ['csrf' => $tka, 'lote' => $lote2, 'item_id' => $itemPag, 'acao' => 'aprovar', 'destino' => 'nova', 'titulo' => 'Livro pela Página', 'autor' => 'SOUZA, Caio Fictício',
    'editora' => 'Editora Teste', 'ano' => '2018', 'isbn' => '8535902775', 'cdd' => '869.3']);
$obraPag = (int)$val('SELECT livro_id FROM coleta_itens WHERE id = ?', [$itemPag]);
confere('G17. aprovar pela página cria a obra corrigida e 2 exemplares no lote', $s === 303 && $obraPag > 0 && $val('SELECT titulo FROM livros WHERE id = ?', [$obraPag]) === 'Livro pela Página'
    && $num('SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND livro_id = ?', [$lote2, $obraPag]) === 2);
confere('G18. a obra guarda de onde veio (observação interna)', str_contains((string)$val('SELECT observacoes_internas FROM livros WHERE id = ?', [$obraPag]), 'Pré-cadastro nº 1'));
[$s] = (new TesteNavegador($base))->ir('admin/coleta.php?lote=' . $lote2);
confere('G19. revisão exige login', $s === 302);

// Carimbagem pela página (link criado pela página do lote).
$car = new TesteNavegador($base);
$car->ir('coleta/?t=' . ($urlNova[2] ?? ''));
[, $html] = $car->ir('coleta/');
$csrfC = preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $mm) ? $mm[1] : '';
$car->ir('coleta/', ['acao' => 'nome', 'nome' => 'Carimbador Fictício', 'csrf' => $csrfC]);
[, $html] = $car->ir('coleta/');
confere('G20. carimbagem com o lote ainda em cadastro: mostra os códigos e avisa', str_contains($html, 'ainda está em cadastro') && str_contains($html, 'BDP-') && !str_contains($html, 'Marcar como carimbados'));
$lotes->fecharAntecipado($lote2, $adm);
[, $html] = $car->ir('coleta/');
$idsPag = array_map('intval', array_column(db_query('SELECT id FROM exemplares WHERE lote_id = ?', [$lote2])->fetchAll(), 'id'));
$car->ir('coleta/', ['acao' => 'carimbar', 'csrf' => $csrfC, 'ids' => $idsPag]);
[, $html2] = $car->ir('coleta/');
confere('G21. sem "Conferi o código", nada é marcado', str_contains($html2, 'Conferi o código') && $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND carimbo_status = 'carimbado'", [$lote2]) === 0);
$car->ir('coleta/', ['acao' => 'carimbar', 'csrf' => $csrfC, 'ids' => $idsPag, 'conferi' => '1']);
[, $html] = $car->ir('coleta/');
confere('G22. marcados pela página, com o botão de concluir', $num("SELECT COUNT(*) FROM exemplares WHERE lote_id = ? AND carimbo_status = 'carimbado'", [$lote2]) === 2 && str_contains($html, 'Concluir carimbagem do lote'));
$car->ir('coleta/', ['acao' => 'concluir_carimbagem', 'csrf' => $csrfC]);
confere('G23. concluir pela página leva o lote à organização', $val('SELECT status FROM cadastro_lotes WHERE id = ?', [$lote2]) === 'aguardando_organizacao');
[$s] = $admNav->ir('admin/lote.php', ['csrf' => $tka, 'id' => $lote2, 'acao' => 'link_revogar', 'link_id' => (int)$val("SELECT id FROM coleta_links WHERE lote_id = ? AND tipo = 'coleta'", [$lote2])]);
[$s2, $html] = (new TesteNavegador($base))->ir('coleta/?t=' . $tk2);
confere('G24. link desativado pela página não abre mais', $s === 303 && $s2 === 403 && str_contains($html, 'desativado'));

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
