<?php
/**
 * Testes das páginas do módulo (servidor PHP embutido + navegador mínimo). Incluído no fim de
 * reorganizacao-teste.php, que prepara o banco temporário e os dados fictícios.
 */
use Biblioteca\Reorganizacao\Reorganizacao;

['adm' => $adm, 'senha' => $senha, 'lote' => $loteAntigo, 'env' => $env] = $GLOBALS['reorg_testes_contexto'];
echo "\nPáginas: botão, entrada, equipe, administração, segurança\n";

$rp = new Reorganizacao(db(), reorg_config(), new Biblioteca\Services\ExemplarService(db()));
$rp->iniciarPeriodo(['nome' => 'Período das páginas', 'instrucoes_gerais' => 'Use luvas <b>sempre</b>'], $adm);
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
confere('T04. depois do nome: instruções e escolha do Grupo 1, 3 ou 4 (sem Grupo 2)', $s === 200 && str_contains($html, 'Grupo 1') && str_contains($html, 'Grupo 3') && str_contains($html, 'Grupo 4')
    && !str_contains($html, 'value="grupo2"') && str_contains($html, 'Use luvas &lt;b&gt;sempre&lt;/b&gt;'));
$t = $csrfDe($html);
$pub->ir('modulos/reorganizacao/equipe/', ['csrf' => $t, 'acao' => 'equipe', 'equipe' => 'grupo2']);
$sessP = db_query("SELECT * FROM reorg_sessoes WHERE nome = 'Joana Fictícia Lima' ORDER BY id DESC LIMIT 1")->fetch();
confere('T05. Grupo 2 não pode ser escolhido pela página', $sessP && $sessP['equipe'] === null);
$pub->ir('modulos/reorganizacao/equipe/', ['csrf' => $t, 'acao' => 'equipe', 'equipe' => 'grupo1']);
[$s, $html] = $pub->ir('modulos/reorganizacao/equipe/');
confere('T11. painel do participante sem lote mostra a espera pela atribuição', $s === 200 && str_contains($html, 'Nenhum lote atribuído') && teste_sem_erro_tecnico($html));
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
confere('Menu do painel mostra “Reorganização do Acervo”', str_contains($html, 'Reorganização do Acervo') && str_contains($html, 'modulos/reorganizacao/admin/'));
$regAntigo = (int)db_query('SELECT id FROM reorg_registros WHERE lote_id = ? ORDER BY id LIMIT 1', [$loteAntigo])->fetchColumn();
foreach (['admin/', 'admin/periodo.php', 'admin/participantes.php', 'admin/solicitacoes.php', 'admin/solicitacoes.php?status=', 'admin/pendencias.php?status=', 'admin/planilhas.php', 'admin/relatorios.php',
          'admin/relatorios.php?lote=' . $loteAntigo, 'admin/historico.php', 'admin/configuracoes.php', 'admin/notificacoes.php', 'admin/lote.php?id=' . $loteAntigo, 'admin/lote.php?id=' . $loteAntigo . '&f=todos', 'admin/registro.php?id=' . $regAntigo] as $pagina) {
    [$s, $html] = $admNav->ir('modulos/reorganizacao/' . $pagina);
    confere("Página $pagina abre sem erro técnico", $s === 200 && teste_sem_erro_tecnico($html), $s . ' ' . mb_substr(strip_tags($html), 0, 300));
}
[$s, $html] = $admNav->ir('modulos/reorganizacao/admin/participantes.php');
confere('T03. central mostra a sessão S-0000 do participante conectado', str_contains($html, 'Joana Fictícia Lima') && str_contains($html, Reorganizacao::rotuloSessao($sessP['id'])));
[$s, $csv, , $tipo, $disp] = $admNav->ir('modulos/reorganizacao/admin/historico.php?exportar=csv');
confere('T40. exportação do histórico em CSV', $s === 200 && str_contains($tipo, 'text/csv') && str_contains($disp, 'attachment') && str_contains($csv, 'conferencia_codigo'));
[$s, $csv, , $tipo] = $admNav->ir('modulos/reorganizacao/admin/relatorios.php?lote=' . $loteAntigo . '&exportar=exemplares');
confere('Relatório CSV dos exemplares do lote (códigos BDP)', $s === 200 && str_contains($tipo, 'text/csv') && substr_count($csv, 'BDP-') >= 30);

// criar lote pela página e atribuir o participante
[$s, $html] = $admNav->ir('modulos/reorganizacao/admin/?novo=1');
$t = $csrfDe($html);
[$s, , $loc] = $admNav->ir('modulos/reorganizacao/admin/', ['csrf' => $t, 'acao' => 'criar_lote', 'nome' => 'Lote Páginas', 'quantidade' => '4', 'responsavel_id' => (string)$adm, 'grupo1' => [(string)$sessP['id']]]);
$novoLote = (int)db_query("SELECT id FROM reorg_lotes WHERE nome = 'Lote Páginas'")->fetchColumn();
confere('T10. lote criado pela página, com o participante atribuído', $novoLote > 0 && in_array($s, [302, 303], true));
[$st, $j] = $api($pub, ['acao' => 'sinal', 'trabalhando' => '0'], $tApi);
confere('T11. o lote aparece sozinho para o participante (sinal), com notificação', $j['ok'] && count($j['lotes']) === 1 && (int)$j['lotes'][0]['id'] === $novoLote && count($j['notificacoes']) >= 1);
[$st, $j] = $api($pub, ['acao' => 'novo', 'lote' => $novoLote], $tApi);
confere('T16. novo registro pela Central de Equipes', $st === 200 && $j['ok'] && $j['id'] > 0);
$regNovo = (int)$j['id'];
[$s, $html] = $pub->ir('modulos/reorganizacao/equipe/?lote=' . $novoLote . '&reg=' . $regNovo);
confere('T16. formulário do Grupo 1 com os campos da revisão (título, autores, editora, ISBN, CDD, fonte, ano, edição, idioma, categoria, assunto, conservação)', $s === 200 && teste_sem_erro_tecnico($html)
    && count(array_filter(['name="titulo"', 'name="subtitulo"', 'name="autores"', 'name="editora"', 'name="isbn"', 'name="isbn_nao_encontrado"', 'name="cdd"', 'name="cdd_nao_encontrada"', 'name="cdd_fonte"', 'name="ano_publicacao"', 'name="edicao"', 'name="idioma"', 'name="categoria_id"', 'name="assunto"', 'name="quantidade"', 'name="estados[1]"'], fn ($c) => str_contains($html, $c))) === 16);
[$st, $j] = $api($pub, ['acao' => 'salvar', 'id' => $regNovo, 'versao' => '0', 'titulo' => 'Livro <script>alert(1)</script> Fictício', 'autores' => 'SOUZA, Teste', 'editora' => 'Editora Teste',
    'isbn' => '978-85-359-0277-8', 'cdd_nao_encontrada' => '1', 'quantidade' => '2', 'aplicar_todos' => 'regular'], $tApi);
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
    'isbn' => '9788535902778', 'cdd' => '869.3', 'quantidade' => '2', 'estados' => [1 => 'regular', 2 => 'ruim']], ['X-CSRF-Token' => $tAdm]);
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
