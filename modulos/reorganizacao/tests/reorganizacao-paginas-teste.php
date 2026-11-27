<?php
/**
 * Testes das páginas do módulo (servidor PHP embutido + navegador mínimo). Incluído no fim de
 * reorganizacao-teste.php, que prepara o banco temporário e os dados fictícios.
 */
use Biblioteca\Reorganizacao\Reorganizacao;

['adm' => $adm, 'senha' => $senha, 'lote' => $loteAntigo, 'env' => $env] = $GLOBALS['reorg_testes_contexto'];
echo "\nPáginas: botão, entrada, equipe, administração, segurança\n";

$rp = new Reorganizacao(db(), reorg_config(), new Biblioteca\Services\ExemplarService(db()));
$invTeste = (int)db_query("SELECT id FROM inventarios WHERE status = 'em_andamento' ORDER BY id DESC LIMIT 1")->fetchColumn();
$rp->iniciarPeriodo(['nome' => 'Período das páginas', 'instrucoes_gerais' => 'Use luvas <b>sempre</b>'], $adm, $invTeste ?: null);
$base = teste_servidor($env);
$csrfDe = fn (string $html) => preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/data-csrf="([a-f0-9]+)"/', $html, $m) ? $m[1] : '');

// ---------------------------------------------------------------- tela de login e entrada
$pub = new TesteNavegador($base);
[$s, $html] = $pub->ir('admin/login.php');
confere('T02. tela de login mostra o botão discreto com período ativo', $s === 200 && str_contains($html, 'reorg-botao-equipe') && str_contains($html, 'modulos/reorganizacao/equipe/'));
[$s, $html] = $pub->ir('modulos/reorganizacao/equipe/');
confere('T03. Central de Equipes pede só o nome (sem e-mail, senha ou CPF)', $s === 200 && str_contains($html, 'name="nome"') && !preg_match('/type="password"|name="email"|name="cpf"/i', $html) && teste_sem_erro_tecnico($html));
$t = $csrfDe($html);
[$s] = $pub->ir('modulos/reorganizacao/equipe/', ['acao' => 'entrar', 'nome' => 'Joana Fictícia Lima']);
confere('T03. sem CSRF a entrada é recusada', !db_query('SELECT 1 FROM reorg_sessoes WHERE nome = ?', ['Joana Fictícia Lima'])->fetchColumn());
[$s, , $loc] = $pub->ir('modulos/reorganizacao/equipe/', ['csrf' => $t, 'acao' => 'entrar', 'nome' => 'Joana Fictícia Lima']);
[$s, $html] = $pub->ir('modulos/reorganizacao/equipe/');
confere('Fluxo: depois do nome vem a etapa de instruções (antes da equipe)', $s === 200 && str_contains($html, 'value="instrucoes"') && str_contains($html, 'Use luvas &lt;b&gt;sempre&lt;/b&gt;') && !str_contains($html, 'value="grupo1"'));
$pub->ir('modulos/reorganizacao/equipe/', ['csrf' => $csrfDe($html), 'acao' => 'instrucoes']);
[$s, $html] = $pub->ir('modulos/reorganizacao/equipe/');
confere('T04. depois das instruções: escolha do Grupo 1, 3 ou 4 (sem Grupo 2)', $s === 200 && str_contains($html, 'Grupo 1') && str_contains($html, 'Grupo 3') && str_contains($html, 'Grupo 4')
    && !str_contains($html, 'value="grupo2"'));
$t = $csrfDe($html);
$pub->ir('modulos/reorganizacao/equipe/', ['csrf' => $t, 'acao' => 'equipe', 'equipe' => 'grupo2']);
$sessP = db_query("SELECT * FROM reorg_sessoes WHERE nome = 'Joana Fictícia Lima' ORDER BY id DESC LIMIT 1")->fetch();
confere('T05. Grupo 2 não pode ser escolhido pela página', $sessP && $sessP['equipe'] === null);
$pub->ir('modulos/reorganizacao/equipe/', ['csrf' => $t, 'acao' => 'equipe', 'equipe' => 'grupo1']);
[$s, $html] = $pub->ir('modulos/reorganizacao/equipe/');
confere('T11. painel do participante sem lote mostra a espera pela atribuição', $s === 200 && str_contains($html, 'Aguardando a atribuição de um lote pelo administrador.') && teste_sem_erro_tecnico($html));
$tApi = $csrfDe($html);
$api = fn (TesteNavegador $n, array $dados, string $token) => (function () use ($n, $dados, $token) {
    [$st, $corpo] = $n->ir('modulos/reorganizacao/equipe/api.php', $dados, ['X-CSRF-Token' => $token]);
    return [$st, json_decode($corpo, true) ?: []];
})();
[$st, $j] = $api($pub, ['acao' => 'sinal'], 'token-errado');
confere('T41. API sem o token CSRF é recusada', $st === 419);
[$st, $j] = $api($pub, ['acao' => 'sinal', 'trabalhando' => '0'], $tApi);
confere('T06. comunicação periódica responde com equipe, lotes e notificações', $st === 200 && $j['ok'] && $j['equipe'] === 'grupo1' && $j['lotes'] === []);

// ---------------------------------------------------------------- acesso proibido aos participantes
foreach (['modulos/reorganizacao/admin/', 'modulos/reorganizacao/admin/lote.php?id=' . $loteAntigo, 'modulos/reorganizacao/admin/api.php?acao=contadores&lote=' . $loteAntigo, 'admin/alunos.php', 'admin/emprestimos.php', 'admin/configuracoes.php'] as $pagina) {
    [$s, $html, $loc] = $pub->ir($pagina);
    confere("T41. participante não acessa $pagina", in_array($s, [302, 401, 403], true) && !str_contains($html, 'Fictícia Souza'));
}
[$st, $j] = $api($pub, ['acao' => 'novo', 'lote' => $loteAntigo], $tApi);
confere('T41. participante não cria registro em lote não atribuído', $st === 422 && !$j['ok']);
[$st, $j] = $api($pub, ['acao' => 'pesquisar', 'q' => "' OR 1=1 -- "], $tApi);
confere('T41. pesquisa com tentativa de SQL injection não quebra nem vaza', $st === 200 && $j['ok'] && $j['obras'] === []);
confere('T41. pastas internas do módulo bloqueadas no Apache (src, includes, tests)', str_contains((string)file_get_contents(__DIR__ . '/../src/.htaccess'), 'Require all denied')
    && str_contains((string)file_get_contents(__DIR__ . '/../includes/.htaccess'), 'Require all denied') && str_contains((string)file_get_contents(__DIR__ . '/../tests/.htaccess'), 'Require all denied'));

// ---------------------------------------------------------------- administração
$admNav = new TesteNavegador($base);
confere('Administrador entra no painel', $admNav->entrar('adm@teste.br', $senha) === 200);
[$s, $html] = $admNav->ir('admin/index.php');
confere('Menu do painel NÃO tem mais a Central separada (as equipes ficam dentro do Inventário)', !str_contains($html, 'modulos/reorganizacao/admin/') && str_contains($html, 'Inventário'));
$regAntigo = (int)db_query('SELECT id FROM reorg_registros WHERE lote_id = ? ORDER BY id LIMIT 1', [$loteAntigo])->fetchColumn();
$paginasAdm = ['admin/participantes.php', 'admin/solicitacoes.php', 'admin/solicitacoes.php?ver=solicitacoes&status=todas',
    'admin/solicitacoes.php?ver=pendencias&status=resolvidas', 'admin/historico.php', 'admin/historico.php?grupo=grupo1&acao=&q=teste&lote_id=' . $loteAntigo, 'admin/configuracoes.php', 'admin/notificacoes.php',
    'admin/registro.php?id=' . $regAntigo, 'admin/registro.php?id=' . $regAntigo . '&volta=' . rawurlencode('lote.php?id=' . $loteAntigo . '&aba=g2')];
foreach (['visao', 'g1', 'g2', 'g3', 'g4', 'participantes', 'pendencias', 'historico'] as $aba) {
    $paginasAdm[] = 'admin/lote.php?id=' . $loteAntigo . '&aba=' . $aba;
}
array_push($paginasAdm, 'admin/lote.php?id=' . $loteAntigo . '&aba=g2&f=todos', 'admin/lote.php?id=' . $loteAntigo . '&aba=g4&f=todos');
foreach ($paginasAdm as $pagina) {
    [$s, $html] = $admNav->ir('modulos/reorganizacao/' . $pagina);
    confere("Página $pagina abre sem erro técnico", $s === 200 && teste_sem_erro_tecnico($html), $s . ' ' . mb_substr(strip_tags($html), 0, 300));
}
foreach (['admin/' => 'inventario-detalhes.php', 'admin/lotes.php' => 'inventario-lotes.php', 'admin/lote-novo.php' => 'admin/lote-novo.php?inventario=', 'admin/periodo.php' => 'configuracoes.php', 'admin/pendencias.php' => 'solicitacoes.php', 'admin/planilhas.php' => 'lotes.php', 'admin/relatorios.php' => 'historico.php', 'admin/relatorios.php?lote=' . $loteAntigo => 'lote.php'] as $pagina => $destino) {
    [$s, , $loc] = $admNav->ir('modulos/reorganizacao/' . $pagina);
    confere("Página antiga $pagina redireciona para $destino", in_array($s, [302, 303], true) && str_contains((string)$loc, $destino), $s . ' ' . $loc);
}
[$s, $html] = $admNav->ir('modulos/reorganizacao/admin/participantes.php');
confere('Abas do inventário nas páginas das equipes (Visão geral, Lotes, Equipes, Conferência, Pendências e solicitações, Histórico, Configurações)',
    count(array_filter(['Visão geral', 'Lotes', 'Equipes e participantes', 'Conferência', 'Pendências e solicitações', 'Histórico', 'Configurações'], fn ($t) => str_contains($html, $t))) === 7 && str_contains($html, 'adm-inv-abas'));
[$s, $html] = $admNav->ir('admin/inventario-detalhes.php?id=' . $invTeste);
confere('Inventário mostra as abas das equipes quando ativas', $s === 200 && str_contains($html, 'Equipes e participantes') && str_contains($html, 'Pendências e solicitações') && str_contains($html, 'Organização por equipes') && teste_sem_erro_tecnico($html));
[$s, $html] = $admNav->ir('modulos/reorganizacao/admin/participantes.php');
confere('T03. central mostra a sessão S-0000 do participante conectado', str_contains($html, 'Joana Fictícia Lima') && str_contains($html, Reorganizacao::rotuloSessao($sessP['id'])));
[$s, $csv, , $tipo, $disp] = $admNav->ir('modulos/reorganizacao/admin/historico.php?exportar=csv');
confere('T40. exportação do histórico em CSV', $s === 200 && str_contains($tipo, 'text/csv') && str_contains($disp, 'attachment') && str_contains($csv, 'conferencia_codigo'));
[$s, $csv, , $tipo] = $admNav->ir('modulos/reorganizacao/admin/historico.php?lote_id=' . $loteAntigo . '&exportar=exemplares');
confere('Relatório CSV dos exemplares do lote (códigos BDP)', $s === 200 && str_contains($tipo, 'text/csv') && substr_count($csv, 'BDP-') >= 30);

// criar lote pela página e atribuir o participante
[$s, $html] = $admNav->ir('admin/lote-novo.php?inventario=' . $invTeste);
confere('Formulário único de lote com os participantes das equipes', $s === 200 && str_contains($html, 'Nome do lote') && str_contains($html, 'Quantidade prevista de exemplares') && str_contains($html, 'Participantes das equipes')
    && str_contains($html, 'G1 — Levantamento') && str_contains($html, 'Joana Fictícia Lima'), mb_substr(strip_tags($html), 0, 300));
$t = $csrfDe($html);
$lotesPermAntes = (int)db_query('SELECT COUNT(*) FROM cadastro_lotes')->fetchColumn();
[$s, , $loc] = $admNav->ir('admin/lote-novo.php', ['csrf' => $t, 'chave' => Biblioteca\Services\OperacaoService::novaChave(), 'inventario_id' => (string)$invTeste, 'nome' => 'Lote Páginas', 'capacidade' => '4',
    'responsavel_id' => (string)$adm, 'equipes_lote' => '1', 'grupo1' => [(string)$sessP['id']]]);
$novoLote = (int)db_query("SELECT id FROM reorg_lotes WHERE nome = 'Lote Páginas'")->fetchColumn();
confere('T10. lote criado pelo formulário único, com o participante atribuído (um lote só)', $novoLote > 0 && in_array($s, [302, 303], true) && str_contains((string)$loc, 'modulos/reorganizacao/admin/lote.php?id=' . $novoLote)
    && (int)db_query('SELECT COUNT(*) FROM cadastro_lotes')->fetchColumn() === $lotesPermAntes + 1
    && (int)db_query('SELECT c.inventario_id FROM reorg_lotes l JOIN cadastro_lotes c ON c.id = l.cadastro_lote_id WHERE l.id = ?', [$novoLote])->fetchColumn() === $invTeste, $s . ' ' . $loc);
[$st, $j] = $api($pub, ['acao' => 'sinal', 'trabalhando' => '0'], $tApi);
confere('T11. o lote aparece sozinho para o participante (sinal), com notificação', $j['ok'] && count($j['lotes']) === 1 && (int)$j['lotes'][0]['id'] === $novoLote && count($j['notificacoes']) >= 1);
[$st, $j] = $api($pub, ['acao' => 'novo', 'lote' => $novoLote], $tApi);
confere('T16. novo registro pela Central de Equipes', $st === 200 && $j['ok'] && $j['id'] > 0);
$regNovo = (int)$j['id'];
[$s, $html] = $pub->ir('modulos/reorganizacao/equipe/?lote=' . $novoLote . '&reg=' . $regNovo);
confere('T16. formulário do Grupo 1 com os campos da revisão (título, autores, editora, ISBN, CDD, fonte, ano, edição, idioma, categoria, assunto, conservação)', $s === 200 && teste_sem_erro_tecnico($html)
    && count(array_filter(['name="titulo"', 'name="subtitulo"', 'name="autores"', 'name="editora"', 'name="isbn"', 'name="isbn_situacao"', 'name="cdd"', 'name="cdd_situacao"', 'name="cdd_fonte"', 'name="ano_publicacao"', 'name="edicao"', 'name="idioma"', 'name="categoria_id"', 'name="assunto"', 'name="quantidade"', 'name="estados[1]"'], fn ($c) => str_contains($html, $c))) === 16);
[$st, $j] = $api($pub, ['acao' => 'salvar', 'id' => $regNovo, 'versao' => '0', 'titulo' => 'Livro <script>alert(1)</script> Fictício', 'autores' => 'SOUZA, Teste', 'editora' => 'Editora Teste',
    'isbn_situacao' => 'encontrado', 'isbn' => '978-85-359-0277-8', 'cdd_situacao' => 'nao_encontrada', 'quantidade' => '2', 'aplicar_todos' => 'regular'], $tApi);
confere('T16. salvamento automático pela API confirma versão e horário do servidor', $st === 200 && $j['ok'] && $j['completo'] && $j['versao'] > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$j['salvo_em']));
[$st, $j] = $api($pub, ['acao' => 'salvar', 'id' => $regNovo, 'versao' => '1', 'titulo' => 'Versão antiga'], $tApi);
confere('T26. API devolve conflito (409) com os dados atuais quando a versão é antiga', $st === 409 && !empty($j['conflito']) && isset($j['atual']['titulo']));
[$st, $j] = $api($pub, ['acao' => 'previa_g2', 'lote' => $novoLote], $tApi);
confere('T28. prévia do encaminhamento pela API', $st === 200 && $j['exemplares'] === 2 && $j['restantes'] === 2);
[$st, $j] = $api($pub, ['acao' => 'encaminhar_g2', 'lote' => $novoLote], $tApi);
confere('T28. encaminhamento parcial pela API', $st === 200 && $j['exemplares'] === 2);
[$s, $html] = $admNav->ir('modulos/reorganizacao/admin/registro.php?id=' . $regNovo);
confere('T42/XSS. o título com <script> aparece escapado na revisão', $s === 200 && !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
$tAdm = $csrfDe($html);
$versaoAtual = (int)db_query('SELECT versao FROM reorg_registros WHERE id = ?', [$regNovo])->fetchColumn();
[$st, $corpo] = $admNav->ir('modulos/reorganizacao/admin/api.php', ['acao' => 'salvar', 'id' => $regNovo, 'versao' => $versaoAtual, 'titulo' => 'Livro Fictício Corrigido', 'autores' => 'SOUZA, Teste', 'editora' => 'Editora Teste',
    'isbn_situacao' => 'encontrado', 'isbn' => '9788535902778', 'cdd_situacao' => 'encontrada', 'cdd' => '869.3', 'quantidade' => '2', 'estados' => [1 => 'regular', 2 => 'ruim']], ['X-CSRF-Token' => $tAdm]);
$j = json_decode($corpo, true);
confere('T27. correção administrativa com salvamento automático (API do Grupo 2)', $st === 200 && $j['ok'] && db_query('SELECT cdd FROM reorg_registros WHERE id = ?', [$regNovo])->fetchColumn() === '869.3');
[$s, $html] = $admNav->ir('modulos/reorganizacao/admin/registro.php?id=' . $regNovo);
confere('T27. revisão mostra as diferenças com o original do Grupo 1', str_contains($html, 'Informações originais do Grupo 1') && str_contains($html, 'Livro Fictício Corrigido'));
[$st, $j] = $api($pub, ['acao' => 'sair'], $tApi);
[$st2, $j2] = $api($pub, ['acao' => 'sinal'], $tApi);
confere('T06. “Sair” encerra a sessão: a API passa a responder sessão encerrada', $st === 200 && $st2 === 401 && !empty($j2['encerrada']));

// ---------------------------------------------------------------- módulo desligado
$baseOff = teste_servidor($env + ['REORGANIZACAO_MODULO_ATIVO' => 'false']);
$off = new TesteNavegador($baseOff);
[$s, $html] = $off->ir('admin/login.php');
confere('T42. módulo desligado: login sem o botão', $s === 200 && !str_contains($html, 'Equipe de organização'));
$off->entrar('adm@teste.br', $senha);
[$s, $html] = $off->ir('admin/index.php');
confere('T42. módulo desligado: painel funciona e o item some do menu', $s === 200 && !str_contains($html, 'modulos/reorganizacao/admin/'));
[$s] = $off->ir('modulos/reorganizacao/admin/');
[$s2] = $off->ir('modulos/reorganizacao/equipe/');
confere('T42. módulo desligado: páginas do módulo indisponíveis (404)', $s === 404 && $s2 === 404);
foreach (['admin/livros.php', 'admin/exemplares.php', 'admin/planilha-bdp.php', 'admin/lotes.php', 'admin/emprestimos.php'] as $pagina) {
    [$s, $html] = $off->ir($pagina);
    confere("T42. funcionalidade permanente $pagina continua funcionando sem o módulo", $s === 200 && teste_sem_erro_tecnico($html));
}

$avisosPhp = [];
foreach ($GLOBALS['teste_temporarios'] ?? [] as $log) {
    foreach (is_file($log) ? file($log) : [] as $linha) {
        if (preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|\[reorganizacao\]/', $linha)) {
            $avisosPhp[] = trim($linha);
        }
    }
}
confere('Nenhum aviso ou erro do PHP no servidor durante as páginas', !$avisosPhp, implode("\n", array_slice(array_unique($avisosPhp), 0, 10)));
