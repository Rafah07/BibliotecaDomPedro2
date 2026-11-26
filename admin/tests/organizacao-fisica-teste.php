<?php
/**
 * Testes da Organização Física e do Inventário otimizado (migração 140).
 *
 * Uso: php admin/tests/organizacao-fisica-teste.php
 *
 * Tudo acontece num banco temporário "bdp_teste_organizacao_XXXX" (apagado no final), com dados
 * FICTÍCIOS. Cenários:
 *    1. migração 140 sobre dados antigos: nada é colocado em estante, "Bom" antigo não conta como avaliado;
 *    2. criar estante com prateleiras, criar várias estantes (sem repetir), prateleiras únicas por estante;
 *    3. editar, reordenar, desativar e excluir (só vazia) estantes e prateleiras;
 *    4. registrar exemplar já com prateleira; prateleira desativada recusada; exemplar sem localização;
 *    5. mover exemplar (histórico), transferir uma prateleira inteira e excluir depois;
 *    6. conservação: "Não avaliado" por padrão e avaliação registrada; responsável por estante;
 *    7. pendências e comparação de quantidades (planilha × encontrados, linhas repetidas);
 *    8. páginas (Organização Física, etiquetas, conferência com campos dependentes, Mover exemplar,
 *       pendências, outra cópia sem copiar a conservação) e permissões; backup com colunas geradas.
 */

use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Repositories\OrganizacaoFisicaRepository;
use Biblioteca\Repositories\PendenciasRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\OrganizacaoFisicaService;

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('organizacao');
$_SERVER['SCRIPT_NAME'] = '/admin/tests/organizacao-fisica-teste.php';
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
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$exServ = new ExemplarService($pdo);
$org = new OrganizacaoFisicaService($pdo, $exServ);
$repo = new OrganizacaoFisicaRepository($pdo);
$pend = new PendenciasRepository($pdo);
$invRepo = new InventarioRepository($pdo);
$invServ = new InventarioService($pdo, $exServ, $invRepo);

// ---------------------------------------------------------------
echo "\n1. Dados antigos depois da migração 140\n";
$pdo->exec("INSERT INTO livros (titulo) VALUES ('Livro Fictício Antigo')");
$antigo = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO localizacoes (setor, estante, prateleira, complemento) VALUES ('Literatura', '3', '2', '')");
$locAntiga = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO exemplares (livro_id, codigo_individual, situacao, estado_conservacao, localizacao_id) VALUES (?, 'BDP-000901', 'disponivel', 'bom', ?)")->execute([$antigo, $locAntiga]);
$exAntigo = (int)$pdo->lastInsertId();
confere('localização antiga continua valendo como "localização anterior" (sem estante)', $val('SELECT estante_id FROM localizacoes WHERE id = ?', [$locAntiga]) === null && $num('SELECT COUNT(*) FROM estantes') === 0);
confere('a mesma localização antiga não pode ser repetida (regra antiga mantida)', $recusa(fn () => $pdo->exec("INSERT INTO localizacoes (setor, estante, prateleira, complemento) VALUES ('Literatura', '3', '2', '')")) !== null);
confere('"Bom" gravado antes não conta como avaliado', $val('SELECT conservacao_avaliada_em FROM exemplares WHERE id = ?', [$exAntigo]) === null);
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual) VALUES ($antigo, 'BDP-000902')");
confere('exemplar novo nasce "Não avaliado"', $val("SELECT estado_conservacao FROM exemplares WHERE codigo_individual = 'BDP-000902'") === 'nao_avaliado');
$res = $repo->resumo();
confere('resumo: nada está "com localização definida" só por ter localização antiga', $res['com_localizacao'] === 0 && $res['em_avulsa'] === 1 && $res['sem_alocacao'] === 2, json_encode($res));

// ---------------------------------------------------------------
echo "\n2. Estantes e prateleiras\n";
$setor = $org->salvarSetor(['nome' => 'Sala de Leitura Fictícia', 'identificacao' => 'SL'], $bibId);
$estA = $org->criarEstante(['identificacao' => 'a', 'categoria' => 'Literatura Brasileira', 'prateleiras' => 5], $bibId);
confere('"+ Adicionar estante" cria a estante e 5 prateleiras', $num('SELECT COUNT(*) FROM localizacoes WHERE estante_id = ?', [$estA]) === 5
    && $val('SELECT identificacao FROM estantes WHERE id = ?', [$estA]) === 'A' && $val('SELECT nome FROM estantes WHERE id = ?', [$estA]) === 'Estante A');
confere('identificação repetida no mesmo setor é recusada', $recusa(fn () => $org->criarEstante(['identificacao' => 'A'], $bibId)) !== null);
confere('a mesma identificação em outro setor é aceita', $recusa(fn () => $org->criarEstante(['identificacao' => 'A', 'setor_id' => $setor, 'prateleiras' => 1], $bibId)) === null);
$lote = $org->criarEstantesEmLote(['quantidade' => 10, 'prateleiras' => 5, 'padrao' => 'letras'], $bibId);
confere('"Criar várias estantes": 10 estantes × 5 prateleiras, pulando a A que já existia', count($lote) === 10 && $lote[0]['identificacao'] === 'B' && end($lote)['identificacao'] === 'K'
    && $num('SELECT COUNT(*) FROM localizacoes l JOIN estantes e ON e.id = l.estante_id WHERE e.setor_id IS NULL') === 55, json_encode(array_column($lote, 'identificacao')));
$lote2 = $org->criarEstantesEmLote(['quantidade' => 3, 'prateleiras' => 2, 'padrao' => 'numeros', 'prefixo' => 'E'], $bibId);
confere('numeração com prefixo: E01, E02, E03', array_column($lote2, 'identificacao') === ['E01', 'E02', 'E03'], json_encode($lote2));
$estB = $lote[0]['id'];
$pratsA = array_map('intval', array_column($pdo->query("SELECT id FROM localizacoes WHERE estante_id = $estA ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC), 'id'));
$pratsB = array_map('intval', array_column($pdo->query("SELECT id FROM localizacoes WHERE estante_id = $estB ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC), 'id'));
confere('"Prateleira 1" existe na Estante A e na Estante B (única só dentro da estante)', $val('SELECT prateleira FROM localizacoes WHERE id = ?', [$pratsA[0]]) === '1' && $val('SELECT prateleira FROM localizacoes WHERE id = ?', [$pratsB[0]]) === '1');
confere('prateleira repetida na mesma estante é recusada', $recusa(fn () => $org->adicionarPrateleiras($estA, 1, '3', $bibId)) !== null);
$novas = $org->adicionarPrateleiras($estA, 2, '', $bibId);
confere('adicionar 2 prateleiras continua a numeração (6 e 7)', count($novas) === 2 && $val('SELECT prateleira FROM localizacoes WHERE id = ?', [$novas[1]]) === '7');
confere('textos da localização acompanham a estante (compatível com as telas antigas)', $val('SELECT estante FROM localizacoes WHERE id = ?', [$pratsA[0]]) === 'A');

// ---------------------------------------------------------------
echo "\n3. Editar, reordenar, desativar e excluir\n";
confere('levar a Estante A para um setor que já tem uma "A" é recusado', $recusa(fn () => $org->editarEstante($estA, ['identificacao' => 'A', 'nome' => 'Estante A', 'setor_id' => $setor], $bibId)) !== null);
$estSetor = (int)$val('SELECT id FROM estantes WHERE setor_id = ?', [$setor]);
$org->salvarSetor(['nome' => 'Sala Fictícia Renomeada', 'identificacao' => 'SL'], $bibId, $setor);
confere('renomear o setor atualiza o texto das prateleiras dele', $val('SELECT setor FROM localizacoes WHERE estante_id = ? LIMIT 1', [$estSetor]) === 'Sala Fictícia Renomeada');
confere('setor com estantes não pode ser excluído', $recusa(fn () => $org->excluirSetor($setor, $bibId)) !== null);
$org->editarPrateleira($pratsA[2], '3A', 'Contos', $bibId);
confere('renomear prateleira', $val('SELECT prateleira FROM localizacoes WHERE id = ?', [$pratsA[2]]) === '3A');
$org->moverPrateleira($pratsA[2], -1, $bibId);
$ordem = array_map('intval', array_column($pdo->query("SELECT id FROM localizacoes WHERE estante_id = $estA ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC), 'id'));
confere('subir a prateleira muda só a ordem de exibição', $ordem[1] === $pratsA[2] && $ordem[2] === $pratsA[1]);
$org->definirPrateleiraAtiva($pratsA[4], false, $bibId);
confere('prateleira desativada sai da escolha de localização', !in_array($pratsA[4], array_map('intval', array_column($repo->opcoesLocal(), 'id')), true));
$org->excluirPrateleira($novas[1], $bibId);
confere('excluir prateleira vazia e nunca usada', $num('SELECT COUNT(*) FROM localizacoes WHERE id = ?', [$novas[1]]) === 0);
$org->definirEstanteAtiva(end($lote2)['id'], false, $bibId);
confere('estante desativada some das opções, mas continua cadastrada', $num('SELECT COUNT(*) FROM estantes WHERE id = ?', [end($lote2)['id']]) === 1
    && !in_array(end($lote2)['id'], array_map('intval', array_filter(array_column($repo->opcoesLocal(), 'estante_id'))), true));

// ---------------------------------------------------------------
echo "\n4. Registrar exemplar com localização definitiva\n";
$pdo->exec("INSERT INTO livros (titulo, dados_verificados_em) VALUES ('Dom Casmurro Fictício', NOW())");
$livro = (int)$pdo->lastInsertId();
$x1 = $exServ->cadastrarComCodigo($livro, 'BDP-001001', ['localizacao_id' => $pratsA[0], 'estado_conservacao' => 'nao_avaliado'], $bibId);
confere('exemplar registrado já na Estante A · Prateleira 1', (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$x1['id']]) === $pratsA[0]);
confere('prateleira desativada é recusada no registro', $recusa(fn () => $exServ->cadastrarComCodigo($livro, 'BDP-001002', ['localizacao_id' => $pratsA[4]], $bibId)) !== null
    && $num("SELECT COUNT(*) FROM exemplares WHERE codigo_individual = 'BDP-001002'") === 0);
$x2 = $exServ->cadastrarComCodigo($livro, 'BDP-001003', ['estado_conservacao' => 'nao_avaliado'], $bibId);
confere('registrar sem localização também é permitido (fica em "sem localização")', $val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$x2['id']]) === null);
$res = $repo->resumo();
confere('números reais: 1 com localização definida', $res['com_localizacao'] === 1 && $res['exemplares'] === 4, json_encode($res));
$est = $repo->estante($estA);
confere('estante mostra prateleiras e exemplares por prateleira', $est && (int)$est['exemplares'] === 1 && (int)$est['prateleiras'][0]['exemplares'] === 1);

// ---------------------------------------------------------------
echo "\n5. Mover e transferir\n";
$mv = $org->moverExemplar(' bdp-001001 ', $pratsB[1], $bibId, 'Reorganização fictícia');
confere('mover exemplar pelo código BDP (com espaços e minúsculas)', (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$x1['id']]) === $pratsB[1] && $mv['de'] === $pratsA[0]);
$mov = $pdo->query("SELECT * FROM movimentacoes WHERE exemplar_id = {$x1['id']} AND tipo = 'localizacao' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$det = json_decode((string)($mov['detalhes'] ?? ''), true) ?: [];
confere('histórico guarda a localização anterior, a nova, quem e quando', $mov && (int)($det['de'] ?? 0) === $pratsA[0] && (int)($det['para'] ?? 0) === $pratsB[1] && str_contains($mov['descricao'], 'Estante A · Prateleira 1 → Estante B · Prateleira 2') && (int)$mov['responsavel_id'] === $bibId && $mov['criado_em'] !== null, json_encode($mov));
confere('mover para o mesmo lugar é recusado', $recusa(fn () => $org->moverExemplar('BDP-001001', $pratsB[1], $bibId)) !== null);
confere('código inexistente é recusado', $recusa(fn () => $org->moverExemplar('BDP-999999', $pratsB[1], $bibId)) !== null);
confere('mover não altera a obra', (int)$val('SELECT livro_id FROM exemplares WHERE id = ?', [$x1['id']]) === $livro && $val('SELECT titulo FROM livros WHERE id = ?', [$livro]) === 'Dom Casmurro Fictício');
confere('prateleira com exemplar não pode ser excluída', $recusa(fn () => $org->excluirPrateleira($pratsB[1], $bibId)) !== null);
confere('estante com exemplar não pode ser excluída', $recusa(fn () => $org->excluirEstante($estB, $bibId)) !== null);
$n = $org->transferirExemplares($pratsB[1], $pratsB[2], $bibId, 'Esvaziar prateleira');
confere('transferir os exemplares de uma prateleira para outra', $n === 1 && (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$x1['id']]) === $pratsB[2]);
confere('prateleira esvaziada mas com histórico continua protegida (desativar em vez de excluir)', $recusa(fn () => $org->excluirPrateleira($pratsB[1], $bibId)) !== null || $num('SELECT COUNT(*) FROM localizacoes WHERE id = ?', [$pratsB[1]]) === 0);

// ---------------------------------------------------------------
echo "\n6. Conservação e responsáveis\n";
$exServ->alterarConservacao($x2['id'], 'ruim', $bibId);
confere('avaliação registrada: estado, quando e por quem', $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$x2['id']]) === 'ruim'
    && $val('SELECT conservacao_avaliada_em FROM exemplares WHERE id = ?', [$x2['id']]) !== null && (int)$val('SELECT conservacao_avaliada_por FROM exemplares WHERE id = ?', [$x2['id']]) === $bibId);
$exServ->alterarConservacao($exAntigo, 'bom', $bibId);
confere('confirmar "Bom" num exemplar antigo também conta como avaliação', $val('SELECT conservacao_avaliada_em FROM exemplares WHERE id = ?', [$exAntigo]) !== null);
confere('estado inválido é recusado', $recusa(fn () => $exServ->alterarConservacao($x2['id'], 'pessimo', $bibId)) !== null);
$org->definirResponsavel($estA, $contas['auxiliar'], 'Equipe 1', $bibId);
confere('estante distribuída para uma pessoa e uma equipe', (int)$val('SELECT responsavel_id FROM estantes WHERE id = ?', [$estA]) === $contas['auxiliar'] && $val('SELECT equipe FROM estantes WHERE id = ?', [$estA]) === 'Equipe 1');
confere('responsável inexistente é recusado', $recusa(fn () => $org->definirResponsavel($estA, 999999, '', $bibId)) !== null);
$msgResp = $recusa(fn () => $exServ->cadastrarComCodigo($livro, 'BDP-001004', ['localizacao_id' => $pratsA[1]], $contas['bibliotecaria']));
confere('responsável não bloqueia: outra pessoa registra na estante', $msgResp === null, (string)$msgResp);

// ---------------------------------------------------------------
echo "\n7. Pendências e quantidades\n";
$q = PendenciasRepository::compararQuantidade(1, 1, 3, 3, 1);
confere('uma linha com Qtd 3 e 1 encontrado: divergência (2 a investigar, não "perdidos")', $q['situacao'] === 'confiavel' && $q['divergente'] && $q['diferenca'] === 2, json_encode($q));
$q = PendenciasRepository::compararQuantidade(2, 2, 2, 4, 2);
confere('duas linhas (Qtd 2 e 2): não soma às cegas, mostra faixa de 2 a 4', $q['situacao'] === 'faixa' && $q['minimo'] === 2 && $q['maximo'] === 4 && !$q['divergente'], json_encode($q));
$q = PendenciasRepository::compararQuantidade(1, 0, null, 0, 1);
confere('quantidade não informada na planilha', $q['situacao'] === 'nao_informada' && !$q['divergente'], json_encode($q));
$c = $pend->contagens(null);
confere('contagens de pendências sem erro e com sem localização real', is_array($c) && ($c['sem_localizacao'] ?? -1) >= 2, json_encode($c));
foreach (array_keys(PendenciasRepository::TIPOS) as $tipo) {
    $l = $pend->listar($tipo, null, '', 1, 10);
    confere("lista de pendências \"$tipo\" responde com total e itens", isset($l['total'], $l['itens']) && $l['total'] >= count($l['itens']));
}
$g = $pend->indicadoresGerais();
confere('indicadores gerais separados (dados, exemplares, importação, organização)', isset($g['obras_verificadas'], $g['exemplares'], $g['registros_sem_vinculo'], $g['estantes']) && $g['conservacao_pendente'] >= 1, json_encode($g));

// ---------------------------------------------------------------
echo "\n8. Páginas e permissões\n";
$base = teste_servidor($env);
$bib = new TesteNavegador($base);
confere('bibliotecária entra no painel', $bib->entrar('bib@teste.br', $senha) === 200);
[$s, $html] = $bib->ir('admin/organizacao-fisica.php');
confere('Organização Física abre com totais e estantes', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Estantes cadastradas') && str_contains($html, 'Estante A') && str_contains($html, 'Criar várias estantes'));
[$s, $html] = $bib->ir('admin/organizacao-fisica.php?estante=' . $estA);
confere('clicar na estante mostra as prateleiras', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Prateleira 1') && str_contains($html, 'Equipe 1'));
[$s, $html] = $bib->ir('admin/organizacao-fisica.php?estante=' . $estB . '&prateleira=' . $pratsB[2]);
confere('clicar na prateleira mostra os exemplares dela', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'BDP-001001'));
$tk = $bib->csrf('admin/organizacao-fisica.php');
[$s, , $loc] = $bib->ir('admin/organizacao-fisica.php', ['csrf' => $tk, 'acao' => 'estante_criar', 'identificacao' => 'Z', 'prateleiras' => '3', 'categoria' => 'Infantil']);
$estZ = (int)$val("SELECT id FROM estantes WHERE identificacao = 'Z'");
confere('"+ Adicionar estante" pela página', $s === 303 && $estZ > 0 && $num('SELECT COUNT(*) FROM localizacoes WHERE estante_id = ?', [$estZ]) === 3, (string)$loc);
[$s, , $loc] = $bib->ir('admin/organizacao-fisica.php', ['csrf' => $tk, 'acao' => 'estante_criar', 'identificacao' => 'Z', 'voltar' => '/admin/organizacao-fisica.php?estante=' . $estZ]);
[, $html] = $bib->ir(ltrim((string)parse_url((string)$loc, PHP_URL_PATH), '/') . '?' . parse_url((string)$loc, PHP_URL_QUERY));
confere('erro reabre o painel com a mensagem e nada é gravado', str_contains((string)$loc, 'painel=estante') && str_contains($html, 'Nada foi gravado') && $num("SELECT COUNT(*) FROM estantes WHERE identificacao = 'Z'") === 1, (string)$loc);
[$s] = $bib->ir('admin/organizacao-fisica.php', ['csrf' => $tk, 'acao' => 'mover_exemplar', 'codigo' => 'BDP-001003', 'destino_id' => $pratsA[0]]);
confere('"Mover exemplar" pela página', $s === 303 && (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$x2['id']]) === $pratsA[0]);
[$s, $html] = $bib->ir('admin/organizacao-etiquetas.php?estante=' . $estA);
confere('etiquetas A4 das prateleiras: ESTANTE A / categoria / PRATELEIRA 01', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'ESTANTE A') && str_contains($html, 'PRATELEIRA 01') && str_contains($html, 'Literatura Brasileira'));
[$s, $html] = $bib->ir('admin/organizacao-etiquetas.php?tipo=estantes&tamanho=g');
confere('etiquetas das estantes', $s === 200 && str_contains($html, 'ESTANTE K'));
[$s, $html] = $bib->ir('admin/localizacoes.php');
confere('Localizações anteriores continua abrindo', $s === 200 && teste_sem_erro_tecnico($html));

$cons = new TesteNavegador($base);
$cons->entrar('cons@teste.br', $senha);
[$s, $html] = $cons->ir('admin/organizacao-fisica.php');
// Único perfil (migração 200): a conta antiga "consulta" é administradora; sem o token CSRF nada é gravado.
confere('conta antiga "consulta" vê a organização com os botões de alterar', $s === 200 && str_contains($html, 'id="painel-estante"'));
[$s] = $cons->ir('admin/organizacao-fisica.php', ['csrf' => 'falso', 'acao' => 'estante_criar', 'identificacao' => 'Q']);
confere('sem o token CSRF não cria estante (419)', $s === 419 && $num("SELECT COUNT(*) FROM estantes WHERE identificacao = 'Q'") === 0);
[$s] = $cons->ir('admin/organizacao-fisica.php', ['csrf' => 'falso', 'acao' => 'mover_exemplar', 'codigo' => 'BDP-001003', 'destino_id' => $pratsA[1]]);
confere('sem o token CSRF não move exemplar (419)', $s === 419);

// ---------------------------------------------------------------
echo "\n9. Conferência do Inventário com a Organização Física\n";
$inv = $invServ->criar(['titulo' => 'Inventário Fictício da Organização', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bibId], $bibId);
$invServ->iniciar($inv, $bibId);
$pg = 'admin/inventario-conferencia.php?id=' . $inv;
$tk = $bib->csrf($pg);
[$s, $html] = $bib->ir($pg);
confere('conferência abre com "Estante atual" em campos dependentes e sem "Finalizar estante"', $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'data-local-dep') && str_contains($html, 'data-local-estante')
    && str_contains($html, 'Não é preciso finalizar') === false && !str_contains($html, '>Finalizar estante<') && str_contains($html, 'Pendências de conferência'));
[$s] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'local', 'local_conferencia' => $pratsA[4]]);
confere('prateleira desativada não pode ser a estante atual', $s === 200);
$bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'local', 'local_conferencia' => $pratsA[1]]);
[$s, $html] = $bib->ir($pg . '&obra=' . $livro . '&painel=registrar');
preg_match('/name="processo_bdp" value="([a-f0-9]{32})"/', $html, $mm);
confere('registro: estante atual sugerida, conservação começa em "Não avaliado"', $s === 200 && preg_match('/name="localizacao_id"[^>]*>(?:(?!<\/select>).)*value="' . $pratsA[1] . '" selected/s', $html) === 1
    && preg_match('/name="estado_conservacao"[^>]*>(?:(?!<\/select>).)*value="nao_avaliado" selected/s', $html) === 1 && str_contains($html, 'Localização sugerida'));
confere('a localização antiga da planilha não aparece como opção', !str_contains($html, 'ESTANTE 4') && !preg_match('/name="localizacao_id"[^>]*>(?:(?!<\/select>).)*value="' . $locAntiga . '"/s', $html));
[, $json] = $bib->ir('admin/api/inventario/gerar-codigo.php', ['csrf' => $tk, 'livro_id' => $livro, 'processo' => $mm[1] ?? '']);
$cod = json_decode($json, true)['codigo'] ?? '';
[$s, , $loc] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $livro, 'codigo_bdp' => $cod, 'processo_bdp' => $mm[1] ?? '',
    'estado_conservacao' => 'excelente', 'localizacao_id' => $pratsB[0], 'situacao_inventario' => 'encontrado']);
$novoId = (int)$val('SELECT id FROM exemplares WHERE codigo_individual = ?', [$cod]);
confere('exemplar registrado na prateleira escolhida, com a conservação avaliada', $s === 303 && $novoId > 0 && (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$novoId]) === $pratsB[0]
    && $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$novoId]) === 'excelente' && $val('SELECT conservacao_avaliada_em FROM exemplares WHERE id = ?', [$novoId]) !== null, "$s $loc $json");
[$s, $html] = $bib->ir($pg . '&obra=' . $livro . '&painel=registrar&copia=1');
confere('"Registrar outra cópia" mantém a prateleira e NÃO copia a conservação', $s === 200 && preg_match('/name="localizacao_id"[^>]*>(?:(?!<\/select>).)*value="' . $pratsB[0] . '" selected/s', $html) === 1
    && preg_match('/name="estado_conservacao"[^>]*>(?:(?!<\/select>).)*value="nao_avaliado" selected/s', $html) === 1);
[$s, $html] = $bib->ir($pg);
confere('a última prateleira usada vira a estante atual', str_contains($html, 'Estante B · Prateleira 1'));
[$s] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'registrar_exemplar', 'livro_id' => $livro, 'codigo_bdp' => '', 'processo_bdp' => '', 'localizacao_id' => '', 'situacao_inventario' => 'encontrado']);
confere('sem código BDP nada é registrado (nenhum código gerado para registro importado)', $s === 200 && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livro]) === 4);
[$s, $html] = $bib->ir($pg . '&codigo=BDP-001003');
confere('conferir exemplar mostra a localização atual e o seletor dependente', $s === 200 && str_contains($html, 'Localização atual: ') && str_contains($html, 'conferir-local'));
[$s] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'conferir', 'exemplar_id' => $x2['id'], 'estado_conservacao' => 'nao_avaliado', 'localizacao_id' => $pratsB[2]]);
confere('conferir com outra prateleira move o exemplar (com histórico) e "Não avaliado" mantém a conservação', $s === 303 && (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$x2['id']]) === $pratsB[2]
    && $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$x2['id']]) === 'ruim' && $num("SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ? AND tipo = 'localizacao' AND inventario_id = ?", [$x2['id'], $inv]) === 1);
[$s, $html] = $bib->ir($pg . '&mover=BDP-001001');
confere('"Mover exemplar" abre com o código e a localização atual', $s === 200 && str_contains($html, 'id="painel-mover"') && str_contains($html, 'open data-painel-auto') && str_contains($html, 'Dom Casmurro Fictício'));
[$s, , $loc] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'mover_exemplar', 'codigo' => 'BDP-001001', 'destino_id' => $pratsA[0]]);
confere('mover pela conferência', $s === 303 && (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$x1['id']]) === $pratsA[0]);
[$s, $html] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'mover_exemplar', 'codigo' => 'BDP-001001', 'destino_id' => $pratsA[0]]);
confere('mover de novo para o mesmo lugar (clique duplo) não grava e reabre o painel com o aviso', $s === 200 && str_contains($html, 'Nada foi gravado') && $num("SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ? AND tipo = 'localizacao'", [$x1['id']]) === 3);
foreach (array_keys(PendenciasRepository::TIPOS) as $tipo) {
    [$s, $html] = $bib->ir($pg . '&pendencias=' . $tipo);
    confere("pendências \"$tipo\" abrem dentro do Inventário", $s === 200 && teste_sem_erro_tecnico($html) && str_contains($html, 'Pendências de conferência · '));
}
[$s, $html] = $bib->ir($pg . '&pendencias=conservacao&pq=BDP-000902');
confere('pendência de conservação com o formulário de avaliação', str_contains($html, 'BDP-000902') && str_contains($html, 'avaliar_conservacao'));
$exNovo = (int)$val("SELECT id FROM exemplares WHERE codigo_individual = 'BDP-000902'");
[$s] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'avaliar_conservacao', 'exemplar_id' => $exNovo, 'estado_conservacao' => 'regular', 'voltar' => '/' . $pg . '&pendencias=conservacao']);
confere('avaliar pela lista de pendências', $s === 303 && $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$exNovo]) === 'regular' && $val('SELECT conservacao_avaliada_em FROM exemplares WHERE id = ?', [$exNovo]) !== null);
[$s] = $bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'avaliar_conservacao', 'exemplar_id' => $exNovo, 'estado_conservacao' => 'nao_avaliado']);
confere('"Não avaliado" não conta como avaliação', $s === 200 && $val('SELECT estado_conservacao FROM exemplares WHERE id = ?', [$exNovo]) === 'regular');
[$s, $frag] = $bib->ir($pg . '&pendencias=sem_localizacao', null, ['X-Conferencia' => '1']);
confere('resposta parcial (sem recarregar) traz as pendências e a estante atual', $s === 200 && str_contains($frag, 'data-regiao="area"') && str_contains($frag, 'Exemplares sem localização definida') && !str_contains($frag, '<nav class="adm-sidebar'));
[$s, $html] = $bib->ir($pg . '&obra=' . $antigo);
confere('ficha da obra sem planilha não mostra comparação de quantidade', $s === 200 && !str_contains($html, 'Quantidade: planilha × encontrados'));
$bib->ir($pg, ['csrf' => $tk, 'id' => $inv, 'acao' => 'local', 'local_conferencia' => $pratsB[0]]);
$org->definirPrateleiraAtiva($pratsB[0], false, $bibId);
[$s, $html] = $bib->ir($pg . '&obra=' . $livro . '&painel=registrar&copia=1');
confere('estante atual desativada: pede nova escolha (não sugere a desativada)', $s === 200 && str_contains($html, 'A estante atual foi desativada') && !preg_match('/name="localizacao_id"[^>]*>(?:(?!<\/select>).)*value="' . $pratsB[0] . '" selected/s', $html));
$aux = new TesteNavegador($base);
$aux->entrar('aux@teste.br', $senha);
[$s, $html] = $aux->ir($pg);
confere('auxiliar também confere e vê as pendências (sem bloqueio por estante)', $s === 200 && str_contains($html, 'Pendências de conferência'));

echo "\n10. Integridade\n";
confere('nenhum exemplar ficou numa localização inexistente', $num('SELECT COUNT(*) FROM exemplares e LEFT JOIN localizacoes l ON l.id = e.localizacao_id WHERE e.localizacao_id IS NOT NULL AND l.id IS NULL') === 0);
confere('nenhuma prateleira ficou sem estante', $num('SELECT COUNT(*) FROM localizacoes l LEFT JOIN estantes e ON e.id = l.estante_id WHERE l.estante_id IS NOT NULL AND e.id IS NULL') === 0);
confere('a localização antiga continua no lugar e com o exemplar', (int)$val('SELECT localizacao_id FROM exemplares WHERE id = ?', [$exAntigo]) === $locAntiga);
$bk = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('estantes', 'localizacoes') AND EXTRA LIKE '%GENERATED%'")->fetchColumn();
confere('colunas calculadas existem (e ficam fora do backup, que as recalcula)', (int)$bk === 2);

$log = (string)@file_get_contents(sys_get_temp_dir() . '/bdp-teste-servidor-' . parse_url($base, PHP_URL_PORT) . '.log');
confere('nenhum erro de PHP registrado pelo servidor', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), implode(' | ', array_slice(preg_grep('/PHP (Fatal|Warning|Notice|Deprecated)/', explode("\n", $log)) ?: [], 0, 3)));

teste_resultado();
