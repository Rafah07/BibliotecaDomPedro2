<?php
/**
 * Testes da Etapa 11: módulos Alunos (consulta, detalhes, cadastro individual, correção,
 * inativação) e Categorias (cadastro, edição, desativação, integração com livros e site).
 *
 * Uso: php admin/tests/alunos-categorias-teste.php
 *
 * Tudo acontece num banco temporário "bdp_teste_alunoscat_XXXX" (migrações aplicadas só nele,
 * apagado no final), com alunos, livros e contas FICTÍCIOS. O banco do .env nunca é usado.
 * Parte dos testes chama os serviços direto; a outra parte usa as páginas do painel e do site
 * num servidor PHP embutido, como no navegador (login, CSRF, permissões por perfil).
 * Uma segunda instância do servidor sem banco confere o modo demonstrativo.
 */
require __DIR__ . '/suporte.php';

use Biblioteca\Repositories\AlunoRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\AlunoCadastroException;
use Biblioteca\Services\AlunoCadastroService;
use Biblioteca\Services\AlunoImportService;
use Biblioteca\Services\CategoriaService;
use Biblioteca\Services\CirculacaoException;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Support\RegrasCirculacao;

[$raiz, $banco, $env] = teste_banco_temporario('alunoscat');
$pdo = db();
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['administrador' => 'adm@teste.br', 'bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$bibId = $contas['bibliotecaria'];
$hoje = (new RegrasCirculacao(fuso: 'America/Sao_Paulo'))->hoje();
$repo = new AlunoRepository($pdo);
$regras = new AlunoImportService('escola.teste');
$cadastro = new AlunoCadastroService($pdo, $repo, $regras);
$circulacao = new EmprestimoService($pdo, new RegrasCirculacao(fuso: 'America/Sao_Paulo'));
$categorias = new CategoriaService($pdo);
$recusa = function (callable $fn): ?AlunoCadastroException {
    try {
        $fn();
        return null;
    } catch (AlunoCadastroException $e) {
        return $e;
    }
};
$recusaCat = function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (AcervoException $e) {
        return $e->getMessage();
    }
};

// Dados fictícios
$ins = $pdo->prepare('INSERT INTO alunos (nome, matricula, turma, turno, email, ativo) VALUES (?, ?, ?, ?, ?, ?)');
$aluno = [];
foreach ([
    'joao' => ['João Fictício da Silva', 'FIC-001', '7A', 'manha', 'joao.ficticio@escola.teste', 1],
    'maria' => ['Maria Jose Exemplo', 'FIC-002', '8B', 'tarde', 'maria.exemplo@escola.teste', 1],
    'ana1' => ['Ana Teste Homônima', 'FIC-003', '6A', null, null, 1],
    'ana2' => ['Ana Teste Homônima', 'FIC-004', '9C', null, null, 1],
    'antigo' => ['Pedro Fictício Antigo', 'FIC-005', '9C', 'noite', null, 0],
] as $chave => $d) {
    $ins->execute($d);
    $aluno[$chave] = (int)$pdo->lastInsertId();
}
for ($i = 1; $i <= 60; $i++) {
    $ins->execute([sprintf('Aluno Paginação %03d', $i), sprintf('PAG-%03d', $i), '5P', null, null, 1]);
}
$pdo->exec("INSERT INTO categorias (nome, descricao) VALUES ('Literatura Brasileira', 'Romances e contos fictícios'), ('Ciências Fictícias', NULL)");
$catLit = $num("SELECT id FROM categorias WHERE nome = 'Literatura Brasileira'");
$catCie = $num("SELECT id FROM categorias WHERE nome = 'Ciências Fictícias'");
$livro = function (string $titulo, ?int $categoria, bool $arquivado = false) use ($pdo): int {
    $pdo->prepare('INSERT INTO livros (titulo, status_validacao, arquivado_em) VALUES (?, ?, ?)')->execute([$titulo, 'validado', $arquivado ? date('Y-m-d H:i:s') : null]);
    $id = (int)$pdo->lastInsertId();
    if ($categoria) {
        $pdo->prepare('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, 1)')->execute([$id, $categoria]);
    }
    return $id;
};
$livroA = $livro('Romance Fictício do Teste', $catLit);
$livroB = $livro('Contos Fictícios Reunidos', $catLit);
$livroArq = $livro('Livro Fictício Arquivado', $catCie, true);
$exemplares = [];
foreach ([$livroA, $livroB] as $l) {
    $st = $pdo->prepare("CALL gerar_exemplares(?, 3, NULL, 'bom', 'disponivel')");
    $st->execute([$l]);
    $exemplares = array_merge($exemplares, array_map('intval', array_column($st->fetchAll(), 'id')));
    $st->closeCursor();
}
// João: 1 empréstimo ativo em dia, 1 atrasado e 1 devolvido.
$prevista = $circulacao->prevista();
$empAtivo = $circulacao->registrar($aluno['joao'], $exemplares[0], $prevista, $bibId)['id'];
$empDevolvido = $circulacao->registrar($aluno['joao'], $exemplares[1], $prevista, $bibId)['id'];
$circulacao->devolver($empDevolvido, $bibId);
$pdo->prepare("INSERT INTO emprestimos (exemplar_id, aluno_id, data_emprestimo, data_prevista, emprestado_por) VALUES (?, ?, NOW() - INTERVAL 20 DAY, CURDATE() - INTERVAL 5 DAY, ?)")->execute([$exemplares[2], $aluno['joao'], $bibId]);
$empAtrasado = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE exemplares SET situacao = 'emprestado' WHERE id = ?")->execute([$exemplares[2]]);
$emprestimosJoao = $num('SELECT COUNT(*) FROM emprestimos WHERE aluno_id = ?', [$aluno['joao']]);

// =================================================================
echo "\nALUNOS — serviços\n";
$todos = ['situacao' => 'todos'];
$r = $repo->listar($todos, $hoje, 1, 25);
confere('1. listagem paginada: total real e só 25 linhas por página', $r['total'] === 65 && count($r['itens']) === 25 && $r['paginas'] === 3);
$r3 = $repo->listar($todos, $hoje, 3, 25);
confere('4. paginação: a última página tem o restante (15) e não repete alunos', count($r3['itens']) === 15 && !array_intersect(array_column($r3['itens'], 'id'), array_column($r['itens'], 'id')));
confere('4. página fora do intervalo vai para a última', $repo->listar($todos, $hoje, 99, 25)['pagina'] === 3);
confere('1. padrão: lista só os ativos', $repo->listar([], $hoje, 1, 100)['total'] === 64);
$acha = fn (string $q, array $f = []) => array_column($repo->listar(['q' => $q, 'situacao' => 'todos'] + $f, $hoje, 1, 100)['itens'], 'id');
confere('2. pesquisa por nome', $acha('Fictício da Silva') === [$aluno['joao']]);
confere('2. pesquisa por matrícula', $acha('FIC-002') === [$aluno['maria']]);
confere('2. pesquisa por e-mail institucional', $acha('maria.exemplo@') === [$aluno['maria']]);
confere('3. pesquisa sem acentos encontra nome com acento ("joao ficticio")', $acha('joao ficticio') === [$aluno['joao']]);
confere('3. pesquisa com acento encontra nome sem acento ("José" → "Maria Jose")', $acha('José') === [$aluno['maria']]);
confere('3. maiúsculas não importam ("JOÃO")', $acha('JOÃO') === [$aluno['joao']]);
confere('2. pesquisa com % e _ não vira curinga', $acha('%') === [] && $acha('_') === []);
confere('filtro por turma', count($acha('', ['turma' => '9C'])) === 2);
confere('filtro por turno', $acha('', ['turno' => 'tarde']) === [$aluno['maria']]);
confere('filtro por situação: inativos', array_column($repo->listar(['situacao' => 'inativos'], $hoje, 1, 100)['itens'], 'id') === [$aluno['antigo']]);
confere('filtro: com atraso', $acha('', ['circulacao' => 'com_atraso']) === [$aluno['joao']]);
$ord = array_column($repo->listar(['situacao' => 'todos', 'ordem' => 'matricula', 'dir' => 'desc'], $hoje, 1, 3)['itens'], 'matricula');
confere('ordenação por matrícula decrescente', $ord === ['PAG-060', 'PAG-059', 'PAG-058']);
$linhaJoao = $repo->listar(['q' => 'FIC-001', 'situacao' => 'todos'], $hoje)['itens'][0];
confere('listagem mostra empréstimos em aberto e atrasados do aluno', $linhaJoao['abertos'] === 2 && $linhaJoao['atrasados'] === 1);
confere('resumo: ativos, inativos, com empréstimo e com atraso', $repo->resumo($hoje) === ['total' => 65, 'ativos' => 64, 'inativos' => 1, 'com_emprestimo' => 1, 'com_atraso' => 1]);

// Cadastro individual
$e = $recusa(fn () => $cadastro->cadastrar(['nome' => '', 'matricula' => ''], $bibId));
confere('7. campos obrigatórios: nome e matrícula em branco são recusados', $e && isset($e->erros['nome'], $e->erros['matricula']));
$e = $recusa(fn () => $cadastro->cadastrar(['nome' => 'Aluno Novo Fictício', 'matricula' => 'FIC 777'], $bibId));
confere('7. matrícula com espaço recusada (mesma regra da importação)', $e && isset($e->erros['matricula']));
$e = $recusa(fn () => $cadastro->cadastrar(['nome' => 'Aluno Novo Fictício', 'matricula' => 'FIC-777', 'email' => 'nao-e-email'], $bibId));
confere('10. e-mail com formato inválido recusado', $e && isset($e->erros['email']));
$e = $recusa(fn () => $cadastro->cadastrar(['nome' => 'Aluno Novo Fictício', 'matricula' => 'FIC-777', 'email' => 'novo@outra.teste'], $bibId));
confere('10. e-mail fora do domínio institucional recusado', $e && isset($e->erros['email']) && str_contains($e->erros['email'], '@escola.teste'));
$e = $recusa(fn () => $cadastro->cadastrar(['nome' => 'Aluno Novo Fictício', 'matricula' => 'fic-001'], $bibId));
confere('9. matrícula duplicada recusada (sem diferenciar maiúsculas)', $e && isset($e->erros['matricula']));
$e = $recusa(fn () => $cadastro->cadastrar(['nome' => 'ana teste homonima', 'matricula' => 'FIC-778'], $bibId));
confere('possível duplicidade: nome igual (sem acento) pede confirmação e lista os cadastros', $e && $e->pedeConfirmacao && count($e->duplicidades) === 2);
$antesAcoes = $num('SELECT COUNT(*) FROM acoes_administrativas');
$novoId = $cadastro->cadastrar(['nome' => "  Aluno   Novo\tFictício ", 'matricula' => 'FIC-777', 'turma' => ' 7A ', 'turno' => 'manha', 'email' => 'NOVO.Aluno@Escola.Teste'], $bibId);
$novo = $repo->buscar($novoId);
confere('7. cadastro individual grava com limpeza (espaços) e e-mail em minúsculas', $novo['nome'] === 'Aluno Novo Fictício' && $novo['turma'] === '7A' && $novo['email'] === 'novo.aluno@escola.teste' && (int)$novo['ativo'] === 1);
confere('13. auditoria do cadastro (quem, o quê, qual aluno)', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'aluno.cadastrar' AND entidade = 'aluno' AND entidade_id = ? AND usuario_id = ?", [$novoId, $bibId]) === 1 && $num('SELECT COUNT(*) FROM acoes_administrativas') === $antesAcoes + 1);
$homonimo = $cadastro->cadastrar(['nome' => 'Ana Teste Homônima', 'matricula' => 'FIC-778'], $bibId, true);
confere('possível duplicidade: com a confirmação, grava', $homonimo > 0);

// Mesmas regras da importação
$linhaPlanilha = $regras->normalizar([2 => ['A' => "  Aluno   Novo\tFictício ", 'B' => 'FIC-777', 'C' => ' 7A ', 'D' => 'manhã', 'E' => 'NOVO.Aluno@Escola.Teste']], ['A' => 'nome', 'B' => 'matricula', 'C' => 'turma', 'D' => 'turno', 'E' => 'email'])[0]['dados'];
confere('14. cadastro e importação limpam os dados do mesmo jeito', $linhaPlanilha === array_intersect_key($novo, $linhaPlanilha));
$plano = $regras->planejar([['linha' => 2, 'dados' => $linhaPlanilha, 'erros' => [], 'avisos' => []]], $repo->porMatriculas(['FIC-777']), [], []);
confere('14. a importação reconhece o aluno cadastrado individualmente (sem alteração, nada a incluir)', $plano['itens'][0]['situacao'] === 'igual' && $plano['resumo']['incluir'] === 0);

// Edição
$e = $recusa(fn () => $cadastro->editar($aluno['maria'], ['nome' => 'Maria Jose Exemplo', 'matricula' => 'FIC-001', 'turma' => '8B', 'turno' => 'tarde', 'email' => 'maria.exemplo@escola.teste'], $bibId, false, true));
confere('9. edição não cria matrícula duplicada', $e && isset($e->erros['matricula']) && $repo->buscar($aluno['maria'])['matricula'] === 'FIC-002');
$e = $recusa(fn () => $cadastro->editar($aluno['maria'], ['nome' => 'Maria Jose Exemplo', 'matricula' => 'FIC-002', 'email' => 'invalido@'], $bibId));
confere('10. edição valida o e-mail', $e && isset($e->erros['email']));
$e = $recusa(fn () => $cadastro->editar($aluno['maria'], ['nome' => 'Maria Jose Exemplo', 'matricula' => 'FIC-002', 'turma' => '8B', 'turno' => 'tarde', 'email' => 'joao.ficticio@escola.teste'], $bibId));
confere('conflito de identidade: e-mail de outro aluno pede confirmação', $e && $e->pedeConfirmacao && $e->duplicidades[0]['matricula'] === 'FIC-001');
confere('nada muda quando os dados são iguais (sem registro de ação)', $cadastro->editar($aluno['maria'], ['nome' => 'Maria Jose Exemplo', 'matricula' => 'FIC-002', 'turma' => '8B', 'turno' => 'tarde', 'email' => 'MARIA.exemplo@escola.teste'], $bibId) === []);
$e = $recusa(fn () => $cadastro->editar($aluno['joao'], ['nome' => 'João Fictício da Silva', 'matricula' => 'FIC-101', 'turma' => '7A', 'turno' => 'manha', 'email' => 'joao.ficticio@escola.teste'], $bibId));
confere('troca de matrícula exige confirmação explícita', $e && $e->pedeConfirmacao && isset($e->erros['confirmar_matricula']) && $repo->buscar($aluno['joao'])['matricula'] === 'FIC-001');
$campos = $cadastro->editar($aluno['joao'], ['nome' => 'João Fictício da Silva Corrigido', 'matricula' => 'FIC-101', 'turma' => '8A', 'turno' => 'manha', 'email' => 'joao.ficticio@escola.teste'], $bibId, false, true);
$joao = $repo->buscar($aluno['joao']);
confere('8. edição corrige nome, matrícula e turma', $campos === ['nome', 'matricula', 'turma'] && $joao['nome'] === 'João Fictício da Silva Corrigido' && $joao['matricula'] === 'FIC-101' && $joao['turma'] === '8A');
confere('11. o id interno não muda e os empréstimos continuam ligados ao aluno', $joao['id'] === $aluno['joao'] && $num('SELECT COUNT(*) FROM emprestimos WHERE aluno_id = ?', [$aluno['joao']]) === $emprestimosJoao);
$acao = db_query("SELECT usuario_id, detalhes FROM acoes_administrativas WHERE acao = 'aluno.editar' AND entidade_id = ? ORDER BY id DESC LIMIT 1", [$aluno['joao']])->fetch();
$det = json_decode($acao['detalhes'], true);
confere('13. auditoria da edição: responsável, campos alterados e matrícula anterior', (int)$acao['usuario_id'] === $bibId && $det['campos'] === 'Nome, Matrícula, Turma' && $det['matricula_anterior'] === 'FIC-001');
confere('13. auditoria não guarda o nome novo nem o e-mail do aluno', !str_contains($acao['detalhes'], 'Corrigido') && !str_contains($acao['detalhes'], '@'));
confere('edição permite apagar turma e e-mail (correção individual)', $cadastro->editar($aluno['maria'], ['nome' => 'Maria Jose Exemplo', 'matricula' => 'FIC-002', 'turma' => '', 'turno' => '', 'email' => ''], $bibId) === ['turma', 'turno', 'email'] && $repo->buscar($aluno['maria'])['email'] === null);
$plano = $regras->planejar($regras->normalizar([2 => ['A' => 'João Fictício da Silva Corrigido', 'B' => 'FIC-101']], ['A' => 'nome', 'B' => 'matricula']), $repo->porMatriculas(['FIC-101']), [], []);
confere('14. a importação encontra o aluno pela matrícula corrigida', $plano['itens'][0]['situacao'] === 'igual');

// Inativação
confere('15. inativar grava e registra a ação com o motivo', $cadastro->definirAtivo($novoId, false, $bibId, 'Transferido (fictício)') === true && (int)$repo->buscar($novoId)['ativo'] === 0
    && $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'aluno.inativar' AND entidade_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(detalhes, '$.motivo')) = 'Transferido (fictício)'", [$novoId]) === 1);
confere('15. inativar de novo não muda nada', $cadastro->definirAtivo($novoId, false, $bibId) === false);
$msg = null;
try {
    $circulacao->registrar($novoId, $exemplares[3], $prevista, $bibId);
} catch (CirculacaoException $ex) {
    $msg = $ex->getMessage();
}
confere('15. aluno inativo não faz novo empréstimo', $msg !== null && str_contains($msg, 'inativo'));
$cadastro->definirAtivo($aluno['joao'], false, $bibId);
$dev = $circulacao->devolver($empAtivo, $bibId);
confere('15. empréstimo em aberto de aluno inativo pode ser devolvido; nada é apagado', $dev['id'] === $empAtivo && $num('SELECT COUNT(*) FROM emprestimos WHERE aluno_id = ?', [$aluno['joao']]) === $emprestimosJoao);
$cadastro->definirAtivo($aluno['joao'], true, $bibId);
$cadastro->definirAtivo($novoId, true, $bibId);
$reemp = $circulacao->registrar($novoId, $exemplares[3], $prevista, $bibId);
confere('15. reativado, o aluno volta a poder fazer empréstimos', $reemp['id'] > 0);
confere('não existe exclusão de alunos (contagem preservada)', $num('SELECT COUNT(*) FROM alunos') === 67);

// =================================================================
echo "\nCATEGORIAS — serviços\n";
$e1 = $recusaCat(fn () => $categorias->salvar(['nome' => '   '], $bibId));
confere('3. nome vazio recusado', $e1 !== null && str_contains($e1, 'Informe'));
confere('3. nome sem letras recusado', $recusaCat(fn () => $categorias->salvar(['nome' => '123'], $bibId)) !== null);
confere('3. nome com < > ou aspas recusado', $recusaCat(fn () => $categorias->salvar(['nome' => '<script>Teste</script>'], $bibId)) !== null && $recusaCat(fn () => $categorias->salvar(['nome' => 'Poesia "nova"'], $bibId)) !== null);
confere('3. nome com mais de 80 caracteres recusado', $recusaCat(fn () => $categorias->salvar(['nome' => str_repeat('a', 81)], $bibId)) !== null);
$catPoesia = $categorias->salvar(['nome' => "  Poesia   e  Cordel ", 'descricao' => " Versos fictícios "], $bibId);
$c = $categorias->buscar($catPoesia);
confere('1. cadastro real no banco, com espaços removidos', $c['nome'] === 'Poesia e Cordel' && $c['descricao'] === 'Versos fictícios' && $c['arquivado_em'] === null);
confere('12. auditoria do cadastro', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'categoria.cadastrar' AND entidade_id = ? AND usuario_id = ?", [$catPoesia, $bibId]) === 1);
foreach (['POESIA E CORDEL', 'poesia e cordel', 'Poésia e Cordél', 'Poesia-e-Cordel', 'Poesia, e Cordel'] as $variante) {
    $m = $recusaCat(fn () => $categorias->salvar(['nome' => $variante], $bibId));
    confere("4. duplicidade recusada: \"$variante\"", $m !== null && str_contains($m, 'Já existe'));
}
confere('4. o banco também recusa duplicidade direta (índice único)', (function () use ($pdo) {
    try {
        $pdo->exec("INSERT INTO categorias (nome) VALUES ('POESIA E CORDEL')");
        return false;
    } catch (PDOException $e) {
        return $e->getCode() === '23000';
    }
})());
$categorias->salvar(['nome' => 'Literatura Brasileira Contemporânea', 'descricao' => 'Romances e contos fictícios'], $bibId, $catLit);
confere('2. edição renomeia sem mexer nos livros', $categorias->buscar($catLit)['nome'] === 'Literatura Brasileira Contemporânea' && $num('SELECT COUNT(*) FROM livro_categorias WHERE categoria_id = ?', [$catLit]) === 2);
$det = json_decode((string)db_query("SELECT detalhes FROM acoes_administrativas WHERE acao = 'categoria.editar' AND entidade_id = ?", [$catLit])->fetchColumn(), true);
confere('12. auditoria da edição com o nome anterior', ($det['nome_anterior'] ?? '') === 'Literatura Brasileira' && $det['campos'] === 'nome');
confere('4. edição não pode copiar o nome de outra categoria', $recusaCat(fn () => $categorias->salvar(['nome' => 'ciencias ficticias'], $bibId, $catPoesia)) !== null);
confere('2. corrigir maiúsculas do próprio nome é permitido', $categorias->salvar(['nome' => 'Poesia E Cordel'], $bibId, $catPoesia) === $catPoesia && $categorias->buscar($catPoesia)['nome'] === 'Poesia E Cordel');
$lista = $categorias->listar(['situacao' => 'todas'], 1, 25)['itens'];
$porId = array_column($lista, null, 'id');
confere('5. contagem real de livros ativos (o arquivado não conta)', $porId[$catLit]['livros'] === 2 && $porId[$catCie]['livros'] === 0 && $porId[$catPoesia]['livros'] === 0);
confere('pesquisa por nome sem acento', array_column($categorias->listar(['q' => 'ciencias'], 1, 25)['itens'], 'id') === [$catCie]);
confere('ordenação por quantidade de livros', $categorias->listar(['ordem' => 'livros', 'dir' => 'desc'], 1, 25)['itens'][0]['id'] === $catLit);
$m = $recusaCat(fn () => $categorias->definirAtiva($catLit, false, $bibId));
confere('6/7. categoria com livros ativos não pode ser desativada', $m !== null && str_contains($m, '2 livro(s)') && $categorias->buscar($catLit)['arquivado_em'] === null);
confere('7. não existe exclusão: nenhuma categoria apagada e vínculos preservados', $num('SELECT COUNT(*) FROM categorias') === 3 && $num('SELECT COUNT(*) FROM livro_categorias') === 3);
confere('categoria só com livro arquivado pode ser desativada (vínculo preservado)', $categorias->definirAtiva($catCie, false, $bibId) === true && $num('SELECT COUNT(*) FROM livro_categorias WHERE categoria_id = ?', [$catCie]) === 1);
$m = $recusaCat(fn () => $categorias->salvar(['nome' => 'Ciencias Ficticias'], $bibId));
confere('4. nome de categoria desativada: orienta reativar em vez de duplicar', $m !== null && str_contains($m, 'desativada'));
confere('12. auditoria da desativação', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'categoria.desativar' AND entidade_id = ?", [$catCie]) === 1);
confere('reativar volta a categoria', $categorias->definirAtiva($catCie, true, $bibId) === true && $categorias->buscar($catCie)['arquivado_em'] === null);
$pdo->prepare("INSERT INTO importacoes (nome_arquivo, identificador_arquivo, arquivo_armazenado, tipo_arquivo, tamanho_bytes, usuario_id, status) VALUES ('acervo-ficticio.xlsx', SHA2('cat', 256), 'x.upload', 'xlsx', 1, ?, 'concluida')")->execute([$bibId]);
$impId = (int)$pdo->lastInsertId();
foreach ([[$livroA, 'POESIA E CORDEL'], [$livroB, 'Gênero antigo fictício']] as $n => [$lid, $textoCat]) {
    $pdo->prepare("INSERT INTO importacao_itens (importacao_id, numero_linha, dados_originais_json, dados_normalizados_json, hash_linha, livro_resultante_id, resultado_importacao, processado_em) VALUES (?, ?, '{}', '{}', SHA1(?), ?, 'criado', NOW())")
        ->execute([$impId, $n + 2, 'cat' . $n, $lid]);
    $pdo->prepare("INSERT INTO registros_legados (importacao_id, importacao_item_id, livro_id, arquivo, linha, categoria_original, dados_originais) VALUES (?, ?, ?, 'acervo-ficticio.xlsx', ?, ?, '{}')")
        ->execute([$impId, (int)$pdo->lastInsertId(), $lid, $n + 2, $textoCat]);
}
$ant = $categorias->antigas();
$antPorTexto = array_column($ant['itens'], null, 'categoria_original');
confere('10. categorias das planilhas antigas aparecem, com a equivalente cadastrada', $ant['total'] === 2 && ($antPorTexto['POESIA E CORDEL']['equivalente']['id'] ?? 0) == $catPoesia && $antPorTexto['Gênero antigo fictício']['equivalente'] === null);
confere('10. o texto das planilhas não é alterado nem vira categoria sozinho', $num('SELECT COUNT(*) FROM categorias') === 3 && $num("SELECT COUNT(*) FROM registros_legados WHERE categoria_original = 'POESIA E CORDEL'") === 1);
$mapa = (new Biblioteca\Services\ImportValidationService())->sugerirMapeamento(['Título', 'Categoria']);
confere('10. a importação do acervo continua mapeando a coluna Categoria para o texto original', ($mapa['Categoria'] ?? '') === 'categoria_original');

// =================================================================
echo "\nPÁGINAS — permissões, formulários e integração\n";
$base = teste_servidor($env);
$navs = ['anonimo' => new TesteNavegador($base)];
foreach (['administrador' => 'adm@teste.br', 'bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $navs[$perfil] = new TesteNavegador($base);
    $navs[$perfil]->entrar($email, $senha);
}
$bib = $navs['bibliotecaria'];
$aux = $navs['auxiliar'];
$cons = $navs['consulta'];
$tok = fn (TesteNavegador $n) => $n->csrf('admin/perfil.php');
$L = 302;
foreach ([
    'alunos.php' => [$L, 200, 200, 200, 403],
    'aluno.php?id=' . $aluno['joao'] => [$L, 200, 200, 200, 403],
    'aluno-editar.php' => [$L, 200, 200, 403, 403],
    'aluno-editar.php?id=' . $aluno['joao'] => [$L, 200, 200, 403, 403],
    'categorias.php' => [$L, 200, 200, 200, 200],
    'categorias.php?aba=antigas' => [$L, 200, 200, 200, 200],
    'categoria.php' => [$L, 200, 200, 403, 403],
    'categoria.php?id=' . $catLit => [$L, 200, 200, 403, 403],
] as $pagina => $esperado) {
    $obtido = [];
    $vazou = false;
    foreach (['anonimo', 'administrador', 'bibliotecaria', 'auxiliar', 'consulta'] as $p) {
        [$s, $html] = $navs[$p]->ir('admin/' . $pagina);
        $obtido[] = $s . (teste_sem_erro_tecnico($html) ? '' : '!erro');
        if ($s !== 200 && (str_contains($html, 'FIC-101') || str_contains($html, 'joao.ficticio'))) {
            $vazou = true;
        }
    }
    confere("12. $pagina → " . implode('/', $esperado), $obtido === array_map('strval', $esperado) && !$vazou, 'obtido ' . implode('/', $obtido));
}
[, $html] = $cons->ir('admin/index.php');
confere('12. perfil consulta não vê o item Alunos no menu', !str_contains($html, 'alunos.php'));
[, $html] = $aux->ir('admin/index.php');
confere('12. auxiliar vê o item Alunos no menu (sem "Em breve")', str_contains($html, 'alunos.php') && !preg_match('#alunos\.php"[^>]*>(?:(?!</a>).)*Em breve#s', $html));

[$s, $html] = $bib->ir('admin/alunos.php?q=joao+ficticio&situacao=todos');
confere('1/3. página Alunos: pesquisa sem acento mostra o aluno', $s === 200 && str_contains($html, 'João Fictício da Silva Corrigido') && str_contains($html, 'FIC-101') && !str_contains($html, 'Aluno Paginação'));
[, $html] = $bib->ir('admin/alunos.php?turma=5P&por_pagina=25&pagina=2&ordem=matricula');
confere('4. página Alunos: paginação no servidor (25 por página)', substr_count($html, 'aluno.php?id=') === 50 && str_contains($html, 'PAG-026') && !str_contains($html, 'PAG-025') && str_contains($html, 'Mostrando 26–50 de 60'));
[, $html] = $aux->ir('admin/alunos.php');
confere('12. auxiliar consulta, mas não vê os botões de cadastrar e editar', !str_contains($html, 'aluno-editar.php'));
[$s, $html] = $bib->ir('admin/aluno.php?id=' . $aluno['joao']);
confere('5. detalhes: dados cadastrais', $s === 200 && str_contains($html, 'FIC-101') && str_contains($html, 'joao.ficticio@escola.teste') && str_contains($html, '8A') && str_contains($html, 'Manhã'));
confere('6. detalhes: empréstimos ativos, atrasados e devoluções anteriores (registros reais)', str_contains($html, 'Empréstimos atrasados') && str_contains($html, 'Devoluções anteriores') && str_contains($html, 'emprestimo.php?id=' . $empAtrasado) && str_contains($html, 'emprestimo.php?id=' . $empDevolvido) && str_contains($html, 'Atrasado há 5 dias'));
confere('13. detalhes: histórico de alterações com responsável e matrícula anterior', str_contains($html, 'Cadastro corrigido') && str_contains($html, 'Matrícula anterior: FIC-001') && str_contains($html, 'Conta Fictícia Bibliotecaria'));
confere('ajuda contextual na página do aluno', str_contains($html, 'Como usar esta página?'));

// Cadastro pela página
$antes = $num('SELECT COUNT(*) FROM alunos');
[$s] = $aux->ir('admin/aluno-editar.php', ['csrf' => $tok($aux), 'nome' => 'Aluno do Auxiliar', 'matricula' => 'AUX-1']);
[$s2] = $cons->ir('admin/aluno-editar.php', ['csrf' => $tok($cons), 'nome' => 'Aluno da Consulta', 'matricula' => 'CON-1']);
[$s3] = $bib->ir('admin/aluno-editar.php', ['csrf' => 'falso', 'nome' => 'Aluno Sem Token', 'matricula' => 'TOK-1']);
[$s4] = $navs['anonimo']->ir('admin/aluno-editar.php', ['nome' => 'Aluno Anônimo', 'matricula' => 'ANO-1']);
confere('12. cadastro recusado no servidor para auxiliar (403), consulta (403), sem CSRF (419) e sem login (302)', [$s, $s2, $s3, $s4] === [403, 403, 419, 302] && $num('SELECT COUNT(*) FROM alunos') === $antes);
[$s, $html] = $bib->ir('admin/aluno-editar.php', ['csrf' => $tok($bib), 'nome' => 'Aluna Página Fictícia', 'matricula' => 'FIC-002']);
confere('9. página: matrícula duplicada mostra erro claro e não grava', $s === 200 && str_contains($html, 'já pertence a outro aluno') && $num('SELECT COUNT(*) FROM alunos') === $antes);
[$s, $html] = $bib->ir('admin/aluno-editar.php', ['csrf' => $tok($bib), 'nome' => '<b>Aluna</b> Página Fictícia', 'matricula' => 'FIC-900', 'email' => 'aluna.pagina@escola.teste', 'turno' => 'tarde']);
$idPagina = $num("SELECT id FROM alunos WHERE matricula = 'FIC-900'");
[, $html] = $bib->ir('admin/aluno.php?id=' . $idPagina);
confere('7. página: cadastro grava e o nome é escapado na saída (XSS)', $idPagina > 0 && str_contains($html, '&lt;b&gt;Aluna&lt;/b&gt;') && !str_contains($html, '<b>Aluna</b>'));
[$s, $html] = $bib->ir('admin/aluno-editar.php', ['csrf' => $tok($bib), 'id' => (string)$idPagina, 'nome' => 'Aluna Página Fictícia', 'matricula' => 'FIC-901', 'email' => 'aluna.pagina@escola.teste', 'turno' => 'tarde']);
confere('página: troca de matrícula pede confirmação', $s === 200 && str_contains($html, 'confirmar_matricula') && $repo->buscar($idPagina)['matricula'] === 'FIC-900');
[$s, , $loc] = $bib->ir('admin/aluno-editar.php', ['csrf' => $tok($bib), 'id' => (string)$idPagina, 'nome' => 'Aluna Página Fictícia', 'matricula' => 'FIC-901', 'email' => 'aluna.pagina@escola.teste', 'turno' => 'tarde', 'confirmar_matricula' => '1']);
confere('8. página: correção confirmada grava e volta aos detalhes', $s === 303 && str_contains($loc, 'aluno.php?id=' . $idPagina) && $repo->buscar($idPagina)['matricula'] === 'FIC-901');
[$s] = $aux->ir('admin/aluno.php', ['csrf' => $tok($aux), 'id' => (string)$idPagina, 'acao' => 'inativar']);
confere('12. auxiliar não inativa (403)', $s === 403 && (int)$repo->buscar($idPagina)['ativo'] === 1);
[$s] = $bib->ir('admin/aluno.php', ['csrf' => $tok($bib), 'id' => (string)$idPagina, 'acao' => 'inativar', 'motivo' => 'Teste']);
confere('15. bibliotecária inativa pela página', $s === 303 && (int)$repo->buscar($idPagina)['ativo'] === 0);
[, $html] = $bib->ir('admin/novo-emprestimo.php?aluno=' . $idPagina);
[, $htmlDet] = $bib->ir('admin/aluno.php?id=' . $idPagina);
confere('15. aluno inativo: sem botão de novo empréstimo e com aviso', !str_contains($htmlDet, 'novo-emprestimo.php?aluno=') && str_contains($htmlDet, 'Cadastro inativo.'));

// Categorias pela página
$antes = $num('SELECT COUNT(*) FROM categorias');
[$s] = $aux->ir('admin/categoria.php', ['csrf' => $tok($aux), 'nome' => 'Categoria do Auxiliar']);
[$s2] = $cons->ir('admin/categoria.php', ['csrf' => $tok($cons), 'nome' => 'Categoria da Consulta']);
[$s3] = $bib->ir('admin/categoria.php', ['csrf' => 'falso', 'nome' => 'Categoria Sem Token']);
[$s4] = $cons->ir('admin/categorias.php', ['csrf' => $tok($cons), 'id' => (string)$catPoesia, 'acao' => 'desativar']);
confere('11. categorias: auxiliar e consulta recebem 403; sem CSRF, 419; nada gravado', [$s, $s2, $s3, $s4] === [403, 403, 419, 403] && $num('SELECT COUNT(*) FROM categorias') === $antes && $categorias->buscar($catPoesia)['arquivado_em'] === null);
[$s, $html] = $bib->ir('admin/categoria.php', ['csrf' => $tok($bib), 'nome' => 'literatura brasileira contemporanea']);
confere('4. página: nome repetido (minúsculas, sem acento) mostra erro e não grava', $s === 200 && str_contains($html, 'Já existe a categoria') && $num('SELECT COUNT(*) FROM categorias') === $antes);
[$s, , $loc] = $bib->ir('admin/categoria.php', ['csrf' => $tok($bib), 'nome' => 'Aventura Fictícia', 'descricao' => 'Histórias de aventura']);
$catAv = $num("SELECT id FROM categorias WHERE nome = 'Aventura Fictícia'");
confere('1. página: categoria cadastrada no banco', $s === 303 && $catAv > 0);
[, $html] = $bib->ir('admin/categorias.php');
confere('categorias: lista real com contagem, sem o aviso de dados demonstrativos', str_contains($html, 'Aventura Fictícia') && str_contains($html, 'Literatura Brasileira Contemporânea') && !str_contains($html, 'Dados demonstrativos') && str_contains($html, 'Em uso'));
confere('ajuda contextual na página de categorias', str_contains($html, 'Como usar esta página?'));
[, $html] = $bib->ir('admin/categorias.php?aba=antigas');
confere('10. aba das planilhas antigas mostra o texto original', str_contains($html, 'Gênero antigo fictício'));
[$s] = $bib->ir('admin/categorias.php', ['csrf' => $tok($bib), 'id' => (string)$catLit, 'acao' => 'desativar']);
[, $html] = $bib->ir('admin/categorias.php');
confere('7. página: desativar categoria em uso é bloqueado com mensagem clara', $s === 303 && str_contains($html, 'não pode ser desativada') && $categorias->buscar($catLit)['arquivado_em'] === null);

// Integração com o cadastro de livros
[, $html] = $bib->ir('admin/cadastrar-livro.php');
confere('8. cadastro de livros: as opções vêm do banco (nova categoria aparece)', str_contains($html, '<option value="' . $catAv . '"') && str_contains($html, 'Literatura Brasileira Contemporânea'));
[$s, , $loc] = $bib->ir('admin/cadastrar-livro.php', ['csrf' => $tok($bib), 'acao' => 'salvar', 'titulo' => 'Aventura Fictícia na Ilha', 'autores' => ['Autora Fictícia'], 'idioma' => 'Português', 'categoria' => (string)$catAv]);
$livroAv = preg_match('/detalhes-livro\.php\?id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
confere('8. livro cadastrado com a categoria nova', $livroAv > 0 && $num('SELECT COUNT(*) FROM livro_categorias WHERE livro_id = ? AND categoria_id = ? AND principal = 1', [$livroAv, $catAv]) === 1);
$categorias->definirAtiva($catPoesia, false, $bibId);
[, $html] = $bib->ir('admin/editar-livro.php?id=' . $livroAv);
confere('8. edição de livros: categoria desativada some das opções', str_contains($html, 'Aventura Fictícia') && !str_contains($html, '<option value="' . $catPoesia . '"'));
[$s, $html] = $bib->ir('admin/cadastrar-livro.php', ['csrf' => $tok($bib), 'acao' => 'salvar', 'titulo' => 'Livro com Categoria Desativada', 'autores' => ['Autora Fictícia'], 'idioma' => 'Português', 'categoria' => (string)$catPoesia]);
confere('8. livro não pode ser gravado com categoria desativada', $num("SELECT COUNT(*) FROM livros WHERE titulo = 'Livro com Categoria Desativada'") === 0);
[, $html] = $bib->ir('admin/livros.php?categoria=' . $catAv);
confere('filtro por categoria na lista de livros do painel', str_contains($html, 'Aventura Fictícia na Ilha') && !str_contains($html, 'Romance Fictício do Teste'));

// Integração com o site público
$pub = $navs['anonimo'];
[, $html] = $pub->ir('biblioteca_php/generos.php');
confere('9. site: página de categorias com o nome editado e a contagem', str_contains($html, 'Literatura Brasileira Contemporânea') && str_contains($html, 'Aventura Fictícia') && !str_contains($html, 'Poesia E Cordel'));
[, $html] = $pub->ir('biblioteca_php/catalogo.php?categoria=' . $catLit);
confere('9. site: filtro por categoria mostra só os livros dela', str_contains($html, 'Romance Fictício do Teste') && str_contains($html, 'Contos Fictícios Reunidos') && !str_contains($html, 'Aventura Fictícia na Ilha'));
[, $html] = $pub->ir('biblioteca_php/catalogo.php');
confere('9. site: categoria editada aparece nos filtros do catálogo', str_contains($html, 'Literatura Brasileira Contemporânea'));
[, $html] = $pub->ir('biblioteca_php/livro.php?id=' . $livroA);
confere('9. site: página do livro mostra o nome atual da categoria', str_contains($html, 'Literatura Brasileira Contemporânea'));
[, $html] = $pub->ir('biblioteca_php/catalogo.php?q=Aventura');
confere('9. site: resultado da pesquisa mostra a categoria do livro', str_contains($html, 'Aventura Fictícia na Ilha') && str_contains($html, 'Aventura Fictícia'));
foreach (['index.php', 'biblioteca_php/catalogo.php', 'biblioteca_php/generos.php'] as $p) {
    [, $html] = $pub->ir($p);
    if (str_contains($html, 'FIC-101') || str_contains($html, 'joao.ficticio') || str_contains($html, 'alunos.php')) {
        confere("site público não expõe alunos ($p)", false);
        continue 1;
    }
}
confere('site público não expõe lista nem dados de alunos', true);

// Relatório de atividades mostra as ações novas sem dados pessoais
[, $html] = $bib->ir('admin/relatorios.php?secao=atividades');
confere('13. Atividades: ações de alunos e categorias com rótulo, sem e-mail de aluno', str_contains($html, 'Cadastro de aluno corrigido') && str_contains($html, 'Categoria cadastrada') && !str_contains($html, 'joao.ficticio'));

// =================================================================
echo "\nMODO DEMONSTRATIVO (sem banco)\n";
$demo = teste_servidor(array_merge($env, ['DB_PORT' => '1', 'APP_ENV' => 'development']));
$nd = new TesteNavegador($demo);
confere('entra com a conta demonstrativa', $nd->entrar('bibliotecaria@demo', 'demo123') === 200);
[$s, $html] = $nd->ir('admin/categorias.php');
confere('categorias no modo demonstrativo: só consulta, com aviso', $s === 200 && str_contains($html, 'Modo demonstrativo.') && !str_contains($html, 'categoria.php"'));
[$s, $html] = $nd->ir('admin/categorias.php', ['csrf' => $nd->csrf('admin/perfil.php'), 'id' => '1', 'acao' => 'desativar']);
confere('modo demonstrativo: envio não grava nada (redireciona com aviso)', $s === 303 && $num('SELECT COUNT(*) FROM categorias WHERE arquivado_em IS NOT NULL') === 1);
[$s, $html] = $nd->ir('admin/categoria.php');
[$s2, $html2] = $nd->ir('admin/alunos.php');
confere('modo demonstrativo: cadastro de categoria e alunos indisponíveis, sem erro técnico', str_contains($html, 'Disponível só com o banco de dados') && str_contains($html2, 'Disponível só com o banco de dados') && teste_sem_erro_tecnico($html . $html2));

teste_resultado();
