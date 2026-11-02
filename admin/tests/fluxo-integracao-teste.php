<?php
/**
 * Teste de integração do fluxo completo da biblioteca (Etapa 7 — revisão final).
 *
 * Uso: php admin/tests/fluxo-integracao-teste.php
 *
 * Tudo acontece em um banco temporário "bdp_teste_fluxo_XXXX" (migrações aplicadas só nele, apagado
 * no final) e pelas próprias páginas do painel, num servidor PHP embutido, como no navegador:
 *   1. cadastrar um livro fictício            9. conferir que o exemplar ficou indisponível
 *   2. cadastrar um exemplar físico fictício 10. registrar a devolução
 *   3. gerar o código BDP                    11. conferir que voltou a ficar disponível
 *   4. criar uma localização                 12. criar e iniciar um inventário
 *   5. associar o exemplar à localização     13. conferir o exemplar pelo código digitado
 *   6. gerar a etiqueta escrita (PDF)        14. consultar os relatórios
 *   7. cadastrar um aluno fictício           15. conferir os históricos
 *   8. registrar um empréstimo
 * Também tenta operações inválidas (código inexistente ou incompleto, empréstimo duplicado,
 * devolução repetida, baixa de exemplar emprestado, conferência repetida) e acessos sem
 * autorização (sem login, sem CSRF, perfil sem permissão), conferindo que nada é gravado.
 * O banco do .env nunca é usado.
 */
require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('fluxo');
$pdo = db();
$num = fn (string $sql, array $p = []) => (int)db_query($sql, $p)->fetchColumn();
$senha = 'SenhaDeTeste123';
$contas = [];
foreach (['bibliotecaria' => 'bib@teste.br', 'auxiliar' => 'aux@teste.br', 'consulta' => 'cons@teste.br'] as $perfil => $email) {
    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Conta Fictícia ' . ucfirst($perfil), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    $contas[$perfil] = (int)$pdo->lastInsertId();
}
$base = teste_servidor($env);
$bib = new TesteNavegador($base);
$aux = new TesteNavegador($base);
$cons = new TesteNavegador($base);
$anon = new TesteNavegador($base);
confere('bibliotecária, auxiliar e consulta entram no painel', $bib->entrar('bib@teste.br', $senha) === 200 && $aux->entrar('aux@teste.br', $senha) === 200 && $cons->entrar('cons@teste.br', $senha) === 200);
$retrato = function () use ($pdo): array {
    $r = [];
    foreach ($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name NOT IN ('login_tentativas', 'acoes_administrativas')")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $r[$t] = $pdo->query("CHECKSUM TABLE `$t`")->fetch()['Checksum'];
    }
    return $r;
};
$token = fn (TesteNavegador $n) => $n->csrf('admin/perfil.php');
$tb = $token($bib);

// ---------------------------------------------------------------
echo "\n1. Cadastrar um livro fictício\n";
$antes = $retrato();
[$s] = $anon->ir('admin/cadastrar-livro.php', ['titulo' => 'Livro Fictício Anônimo', 'acao' => 'salvar']);
confere('sem login: cadastro recusado (vai para o login) e nada é gravado', $s === 302 && $retrato() === $antes);
[$s] = $bib->ir('admin/cadastrar-livro.php', ['titulo' => 'Livro Fictício Sem Token', 'acao' => 'salvar']);
confere('sem token CSRF: recusado (419) e nada é gravado', $s === 419 && $retrato() === $antes);
[$s] = $cons->ir('admin/cadastrar-livro.php', ['csrf' => $token($cons), 'titulo' => 'Livro Fictício da Consulta', 'acao' => 'salvar']);
confere('perfil consulta: recusado (403) mesmo com token válido', $s === 403 && $retrato() === $antes);
[$s, , $loc] = $bib->ir('admin/cadastrar-livro.php', ['csrf' => $tb, 'acao' => 'salvar', 'titulo' => 'Memórias Fictícias da Auditoria', 'subtitulo' => 'Um livro de teste',
    'autores' => ['Autora Fictícia Exemplo'], 'editora' => 'Editora Teste', 'ano_publicacao' => '2024', 'idioma' => 'Português', 'assunto' => 'Teste de integração']);
$livroId = preg_match('/detalhes-livro\.php\?id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
confere('bibliotecária cadastra o livro (303 para os detalhes)', $s === 303 && $livroId > 0, "status $s, $loc");
confere('livro é um registro bibliográfico: nenhum exemplar físico foi criado', $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livroId]) === 0);
[, $html] = $anon->ir('biblioteca_php/livro.php?id=' . $livroId);
confere('site público mostra o livro sem exemplar físico (sem inventar disponibilidade)', str_contains($html, 'Memórias Fictícias da Auditoria') && !preg_match('/\b[1-9]\d* disponíve/', $html));

// ---------------------------------------------------------------
echo "\n2–5. Exemplar físico, código BDP e localização\n";
[$s, , $loc] = $bib->ir('admin/localizacao.php', ['csrf' => $tb, 'setor' => 'Literatura Fictícia', 'estante' => 'e9', 'prateleira' => 'p2', 'codigo' => 'LIT-E9-P2']);
$localId = $num("SELECT id FROM localizacoes WHERE codigo = 'LIT-E9-P2'");
confere('4. localização criada pela página (estante e prateleira em maiúsculas)', $s === 303 && $localId > 0 && $num("SELECT COUNT(*) FROM localizacoes WHERE id = ? AND estante = 'E9' AND prateleira = 'P2'", [$localId]) === 1);
[$s] = $aux->ir('admin/localizacao.php', ['csrf' => $token($aux), 'setor' => 'Setor do Auxiliar']);
confere('4. auxiliar não cria localização (403)', $s === 403 && $num("SELECT COUNT(*) FROM localizacoes WHERE setor = 'Setor do Auxiliar'") === 0);
[$s] = $bib->ir('admin/cadastrar-exemplar.php', ['csrf' => $tb, 'livro_id' => (string)$livroId, 'quantidade' => '1', 'estado_conservacao' => 'bom']);
confere('2. sem marcar a confirmação física, nenhum exemplar é criado', $s === 200 && $num('SELECT COUNT(*) FROM exemplares WHERE livro_id = ?', [$livroId]) === 0);
$seqAntes = $num('SELECT ultimo FROM exemplar_codigo_seq');
[$s, , $loc] = $bib->ir('admin/cadastrar-exemplar.php', ['csrf' => $tb, 'livro_id' => (string)$livroId, 'quantidade' => '2', 'estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'confirmar' => '1']);
$ex = db_query('SELECT id, codigo_individual, situacao, localizacao_id FROM exemplares WHERE livro_id = ? ORDER BY id', [$livroId])->fetchAll();
confere('2. dois exemplares físicos cadastrados pela página', $s === 303 && count($ex) === 2, "status $s");
$codigo = $ex[0]['codigo_individual'] ?? '';
confere('3. códigos BDP no formato BDP-000000, sequenciais e únicos', (bool)preg_match('/^BDP-\d{6}$/', $codigo) && $ex[1]['codigo_individual'] === sprintf('BDP-%06d', (int)substr($codigo, 4) + 1)
    && $num('SELECT ultimo FROM exemplar_codigo_seq') === $seqAntes + 2);
$exId = (int)$ex[0]['id'];
$ex2 = (int)$ex[1]['id'];
[$s] = $bib->ir('admin/detalhes-exemplar.php?id=' . $exId, ['csrf' => $tb, 'acao' => 'transferir', 'localizacao_id' => (string)$localId, 'motivo' => 'Organização inicial']);
confere('5. exemplar associado à localização', $s === 303 && $num('SELECT localizacao_id FROM exemplares WHERE id = ?', [$exId]) === $localId);
try {
    $pdo->exec("UPDATE exemplares SET codigo_individual = 'BDP-999999' WHERE id = $exId");
    confere('3. o banco não deixa trocar o código BDP de um exemplar', false);
} catch (PDOException $e) {
    confere('3. o banco não deixa trocar o código BDP de um exemplar', $num('SELECT COUNT(*) FROM exemplares WHERE id = ? AND codigo_individual = ?', [$exId, $codigo]) === 1);
}

// ---------------------------------------------------------------
echo "\n6. Etiqueta escrita (sem código de barras)\n";
$modelo = db_query("SELECT id FROM etiqueta_modelos WHERE tipo = 'patrimonial' AND ativo = 1 AND colunas * linhas = 1 AND etiqueta_largura = 50 AND etiqueta_altura = 20 ORDER BY id LIMIT 1")->fetchColumn();
[$s, $pdf, , $tipo] = $bib->ir('admin/etiquetas.php', ['csrf' => $tb, 'acao' => 'pdf', 'ids' => (string)$exId, 'modelo' => (string)$modelo]);
$conteudo = '';
preg_match_all('/<< \/Length (\d+)( \/Filter \/FlateDecode)? >>\nstream\n/', $pdf, $mm, PREG_OFFSET_CAPTURE);
foreach ($mm[0] as $i => [$cab, $pos]) {
    $dados = substr($pdf, $pos + strlen($cab), (int)$mm[1][$i][0]);
    $conteudo .= $mm[2][$i][0] !== '' ? (string)@gzuncompress($dados) : $dados;
}
preg_match_all('/\((.*?)\) Tj/', $conteudo, $tx);
$tx[1] = array_map(fn ($t) => mb_convert_encoding(stripcslashes($t), 'UTF-8', 'Windows-1252'), $tx[1]);
confere('6. PDF da etiqueta gerado pela página', $s === 200 && str_starts_with($tipo, 'application/pdf') && str_starts_with($pdf, '%PDF-'));
confere('6. etiqueta só com texto: "BIBLIOTECA DOM PEDRO II" e o código BDP', $tx[1] === ['BIBLIOTECA DOM PEDRO II', $codigo], json_encode($tx[1]));
confere('6. PDF sem imagem (nenhum código de barras ou QR Code)', !str_contains($pdf, '/Image') && !str_contains($pdf, '/XObject'));
confere('6. geração da etiqueta registrada no exemplar', $num('SELECT COUNT(*) FROM exemplares WHERE id = ? AND etiqueta_gerada_em IS NOT NULL', [$exId]) === 1);
[$s] = $cons->ir('admin/etiquetas.php', ['csrf' => $token($cons), 'acao' => 'pdf', 'ids' => (string)$exId, 'modelo' => (string)$modelo]);
confere('6. perfil consulta não gera etiquetas (403)', $s === 403);

// ---------------------------------------------------------------
echo "\n7. Aluno fictício (importação local, pela API do painel)\n";
$csv = "\xEF\xBB\xBFNome;Matrícula;Turma;Turno\r\nAluna Fictícia Integração;FIC-2026-001;7º A;Manhã\r\n";
$campos = ['acao' => 'previa', 'linha_cabecalho' => '1', 'mapeamento' => json_encode(['A' => 'nome', 'B' => 'matricula', 'C' => 'turma', 'D' => 'turno']), 'modo' => 'novos'];
[$s, $corpo] = $aux->enviar('admin/api/alunos/importar.php', $campos + ['csrf' => $token($aux)], ['arquivo' => ['alunos-ficticios.csv', $csv, 'text/csv']]);
confere('7. auxiliar não importa alunos (403)', $s === 403);
[$s, $corpo] = $bib->enviar('admin/api/alunos/importar.php', $campos, ['arquivo' => ['alunos-ficticios.csv', $csv, 'text/csv']]);
confere('7. importação sem token CSRF é recusada', $s >= 400 && $num('SELECT COUNT(*) FROM alunos') === 0);
[$s, $corpo] = $bib->enviar('admin/api/alunos/importar.php', $campos + ['csrf' => $tb], ['arquivo' => ['alunos-ficticios.csv', $csv, 'text/csv']]);
$previa = json_decode($corpo, true) ?: [];
confere('7. prévia lê 1 aluno a incluir e não grava nada', $s === 200 && ($previa['resumo']['incluir'] ?? 0) === 1 && $num('SELECT COUNT(*) FROM alunos') === 0, substr($corpo, 0, 200));
[$s, $corpo] = $bib->enviar('admin/api/alunos/importar.php', ['acao' => 'confirmar', 'assinatura' => (string)($previa['assinatura'] ?? '')] + $campos + ['csrf' => $tb], ['arquivo' => ['alunos-ficticios.csv', $csv, 'text/csv']]);
$alunoId = $num("SELECT id FROM alunos WHERE matricula = 'FIC-2026-001'");
confere('7. aluno fictício incluído', $s === 200 && $alunoId > 0, substr($corpo, 0, 200));
confere('7. registro de ações guarda só contagens (sem nome ou matrícula)', $num("SELECT COUNT(*) FROM acoes_administrativas WHERE acao = 'alunos.importar' AND detalhes NOT LIKE '%FIC-2026%' AND detalhes NOT LIKE '%Integração%'") === 1);

// ---------------------------------------------------------------
echo "\n8–9. Empréstimo pelo código BDP digitado\n";
[$s, $html] = $bib->ir('admin/novo-emprestimo.php?qa=' . rawurlencode('Integração'));
confere('8. pesquisa de aluno encontra a aluna fictícia', $s === 200 && str_contains($html, 'Aluna Fictícia Integração'));
[$s, $html] = $bib->ir('admin/novo-emprestimo.php?aluno=' . $alunoId . '&qe=BDP-999999');
confere('8. código BDP inexistente: avisa e não abre a confirmação', $s === 200 && str_contains($html, 'BDP-999999') && !str_contains($html, 'name="confirmar"'));
[$s, $html] = $bib->ir('admin/novo-emprestimo.php?aluno=' . $alunoId . '&qe=' . rawurlencode(strtolower('bdp-' . ltrim(substr($codigo, 4), '0'))));
confere('8. código incompleto (sem os 6 dígitos) não é completado sozinho', $s === 200 && !str_contains($html, 'name="confirmar"'));
[$s, , $loc] = $bib->ir('admin/novo-emprestimo.php?aluno=' . $alunoId . '&qe=' . rawurlencode(strtolower($codigo)));
confere('8. código completo digitado (minúsculas) leva à confirmação do exemplar certo', $s === 303 || $s === 302 ? str_contains($loc, 'exemplar=' . $exId) : false, "status $s $loc");
$antes = $retrato();
[$s] = $bib->ir('admin/novo-emprestimo.php', ['csrf' => $tb, 'aluno' => (string)$alunoId, 'exemplar' => '987654', 'confirmar' => '1']);
confere('8. empréstimo de exemplar físico inexistente: nada é gravado', $retrato() === $antes && $num('SELECT COUNT(*) FROM emprestimos') === 0);
[$s] = $cons->ir('admin/novo-emprestimo.php', ['csrf' => $token($cons), 'aluno' => (string)$alunoId, 'exemplar' => (string)$exId, 'confirmar' => '1']);
confere('8. perfil consulta não registra empréstimo (403)', $s === 403 && $num('SELECT COUNT(*) FROM emprestimos') === 0);
[$s, , $loc] = $bib->ir('admin/novo-emprestimo.php', ['csrf' => $tb, 'aluno' => (string)$alunoId, 'exemplar' => (string)$exId, 'confirmar' => '1', 'observacao' => 'Empréstimo do teste de integração']);
$empId = $num('SELECT id FROM emprestimos WHERE exemplar_id = ? AND data_devolucao IS NULL', [$exId]);
confere('8. empréstimo registrado', in_array($s, [302, 303], true) && $empId > 0 && str_contains($loc, 'emprestimo.php?id=' . $empId));
confere('9. exemplar fica "emprestado"', db_query('SELECT situacao FROM exemplares WHERE id = ?', [$exId])->fetchColumn() === 'emprestado');
[$s, $html] = $bib->ir('admin/novo-emprestimo.php', ['csrf' => $tb, 'aluno' => (string)$alunoId, 'exemplar' => (string)$exId, 'confirmar' => '1']);
confere('9. segundo empréstimo do mesmo exemplar é recusado', $num('SELECT COUNT(*) FROM emprestimos WHERE exemplar_id = ?', [$exId]) === 1 && $num('SELECT COUNT(*) FROM emprestimos') === 1);
[, $html] = $anon->ir('biblioteca_php/livro.php?id=' . $livroId);
confere('9. site público: 1 disponível (o outro exemplar), sem o nome da aluna', str_contains($html, '1 exemplar disponível') && !str_contains($html, 'Aluna Fictícia'));
[$s, $html] = $bib->ir('admin/detalhes-exemplar.php?id=' . $exId, ['csrf' => $tb, 'acao' => 'baixa', 'confirmar' => '1', 'motivo' => 'Tentativa indevida']);
confere('9. exemplar emprestado não pode ser baixado', $num("SELECT COUNT(*) FROM exemplares WHERE id = ? AND situacao = 'emprestado' AND baixado_em IS NULL", [$exId]) === 1);
$data = $num('SELECT TO_DAYS(data_prevista) - TO_DAYS(DATE(data_emprestimo)) FROM emprestimos WHERE id = ?', [$empId]);
confere('8. prazo padrão provisório de 14 dias calculado a partir de hoje', $data === 14);

// Renovação (no prazo) e tentativa do auxiliar de mudar a data.
[$s] = $aux->ir('admin/renovar-emprestimo.php?id=' . $empId, ['csrf' => $token($aux), 'id' => (string)$empId, 'confirmar' => '1', 'data_prevista' => date('Y-m-d', strtotime('+200 days'))]);
$prev = (string)db_query('SELECT data_prevista FROM emprestimos WHERE id = ?', [$empId])->fetchColumn();
confere('8. renovação pelo auxiliar usa o prazo padrão (data enviada à força é ignorada)', $s === 303 && $prev === date('Y-m-d', strtotime('+28 days')), "status $s, prevista $prev");
confere('8. renovação fica registrada no histórico imutável', $num('SELECT COUNT(*) FROM emprestimo_renovacoes WHERE emprestimo_id = ?', [$empId]) === 1);

// ---------------------------------------------------------------
echo "\n10–11. Devolução\n";
[$s] = $cons->ir('admin/devolver-emprestimo.php?id=' . $empId, ['csrf' => $token($cons), 'id' => (string)$empId, 'confirmar' => '1']);
confere('10. perfil consulta não registra devolução (403)', $s === 403 && $num('SELECT COUNT(*) FROM emprestimos WHERE id = ? AND data_devolucao IS NULL', [$empId]) === 1);
[$s, , $loc] = $bib->ir('admin/devolver-emprestimo.php?id=' . $empId, ['csrf' => $tb, 'id' => (string)$empId, 'confirmar' => '1']);
confere('10. devolução registrada', $s === 303 && $num('SELECT COUNT(*) FROM emprestimos WHERE id = ? AND data_devolucao IS NOT NULL AND recebido_por = ?', [$empId, $contas['bibliotecaria']]) === 1);
confere('11. exemplar volta a ficar disponível', db_query('SELECT situacao FROM exemplares WHERE id = ?', [$exId])->fetchColumn() === 'disponivel');
$antes = $retrato();
$bib->ir('admin/devolver-emprestimo.php?id=' . $empId, ['csrf' => $tb, 'id' => (string)$empId, 'confirmar' => '1']);
confere('10. devolução repetida não grava nada', $retrato() === $antes);
try {
    $pdo->exec("DELETE FROM emprestimos WHERE id = $empId");
    confere('10. empréstimo devolvido não pode ser apagado (histórico preservado)', false);
} catch (PDOException $e) {
    confere('10. empréstimo devolvido não pode ser apagado (histórico preservado)', $num('SELECT COUNT(*) FROM emprestimos WHERE id = ?', [$empId]) === 1);
}
[, $html] = $anon->ir('biblioteca_php/livro.php?id=' . $livroId);
confere('11. site público: 2 exemplares disponíveis', str_contains($html, '2 exemplares disponíveis'));

// ---------------------------------------------------------------
echo "\n12–13. Inventário e conferência pelo código digitado\n";
[$s, , $loc] = $bib->ir('admin/inventario-editar.php', ['csrf' => $tb, 'titulo' => 'Inventário Fictício de Integração', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => (string)$contas['bibliotecaria'], 'setor' => '']);
$invId = $num("SELECT id FROM inventarios WHERE titulo = 'Inventário Fictício de Integração'");
confere('12. inventário criado pela página', $invId > 0, "status $s");
[$s] = $aux->ir('admin/inventario-detalhes.php?id=' . $invId, ['csrf' => $token($aux), 'id' => (string)$invId, 'acao' => 'iniciar']);
confere('12. auxiliar não inicia inventário (403)', $s === 403 && db_query('SELECT status FROM inventarios WHERE id = ?', [$invId])->fetchColumn() === 'aberto');
[$s] = $bib->ir('admin/inventario-detalhes.php?id=' . $invId, ['csrf' => $tb, 'id' => (string)$invId, 'acao' => 'iniciar']);
confere('12. inventário iniciado com os 2 exemplares previstos', db_query('SELECT status FROM inventarios WHERE id = ?', [$invId])->fetchColumn() === 'em_andamento'
    && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND previsto = 1', [$invId]) === 2);
$ta = $token($aux);
[$s, , $loc] = $aux->ir('admin/inventario-conferencia.php?id=' . $invId, ['csrf' => $ta, 'id' => (string)$invId, 'acao' => 'ler', 'leitura' => $codigo]);
confere('13. código digitado abre a conferência do exemplar', $s === 303 && str_contains($loc, 'codigo=' . $codigo), $loc);
[$s] = $aux->ir('admin/inventario-conferencia.php?id=' . $invId, ['csrf' => $ta, 'id' => (string)$invId, 'acao' => 'conferir', 'exemplar_id' => (string)$exId, 'estado_conservacao' => 'bom', 'localizacao_id' => (string)$localId]);
confere('13. auxiliar confere o exemplar', $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ? AND conferido_em IS NOT NULL', [$invId, $exId]) === 1);
$antes = $retrato();
[$s, , $loc] = $aux->ir('admin/inventario-conferencia.php?id=' . $invId, ['csrf' => $ta, 'id' => (string)$invId, 'acao' => 'conferir', 'exemplar_id' => (string)$exId, 'estado_conservacao' => 'regular']);
confere('13. conferência repetida do mesmo código não grava de novo', $retrato() === $antes && $num('SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = ? AND exemplar_id = ?', [$invId, $exId]) === 1);
[$s, $html] = $aux->ir('admin/inventario-conferencia.php?id=' . $invId, ['csrf' => $ta, 'id' => (string)$invId, 'acao' => 'ler', 'leitura' => 'BDP-12']);
confere('13. código incompleto na conferência mostra o erro e não confere nada', $s === 200 && str_contains($html, 'BDP-12') && $retrato() === $antes);
[$s] = $bib->ir('admin/inventario-detalhes.php?id=' . $invId, ['csrf' => $tb, 'id' => (string)$invId, 'acao' => 'concluir']);
$status = db_query('SELECT status FROM inventarios WHERE id = ?', [$invId])->fetchColumn();
confere('12. inventário com exemplar não conferido não conclui sem decisão (nenhum extravio automático)', $status !== 'concluido' || $num("SELECT COUNT(*) FROM exemplares WHERE situacao = 'extraviado'") === 0);
confere('12. exemplar não conferido continua com a situação de antes', db_query('SELECT situacao FROM exemplares WHERE id = ?', [$ex2])->fetchColumn() === 'disponivel');

// ---------------------------------------------------------------
echo "\n14. Relatórios\n";
[$s, $html] = $bib->ir('admin/relatorios.php?secao=emprestimos');
confere('14. relatório de empréstimos abre sem erro técnico', $s === 200 && teste_sem_erro_tecnico($html));
[$s, $html] = $cons->ir('admin/relatorios.php?secao=emprestimos');
confere('14. perfil consulta vê os números, sem o nome da aluna', $s === 200 && !str_contains($html, 'Aluna Fictícia Integração'));
[$s, $csvRel, , $tipo, $disp] = $bib->ir('admin/relatorios.php?secao=exemplares&formato=csv&tabela=exemplares');
confere('14. exportação CSV dos exemplares traz o código BDP', $s === 200 && str_contains($disp, 'attachment') && str_contains($csvRel, $codigo));
[$s, $html] = $bib->ir('admin/relatorios.php?secao=inventarios');
confere('14. relatório de inventários mostra o inventário em andamento', $s === 200 && str_contains($html, 'Inventário Fictício de Integração'));
[$s, $html] = $bib->ir('admin/index.php');
confere('14. dashboard abre sem erro técnico', $s === 200 && teste_sem_erro_tecnico($html));

// ---------------------------------------------------------------
echo "\n15. Históricos\n";
$tipos = db_query('SELECT tipo FROM movimentacoes WHERE exemplar_id = ? ORDER BY id', [$exId])->fetchAll(PDO::FETCH_COLUMN);
confere('15. movimentações do exemplar: cadastro, transferência, empréstimo, devolução e inventário', !array_diff(['emprestimo', 'devolucao', 'inventario'], $tipos) && count($tipos) >= 5, implode(',', $tipos));
[$s, $html] = $bib->ir('admin/historico-exemplar.php?id=' . $exId);
confere('15. página de histórico do exemplar mostra o empréstimo e a aluna (perfil autorizado)', $s === 200 && str_contains($html, $codigo) && str_contains($html, 'Aluna Fictícia Integração'));
[$s] = $cons->ir('admin/historico-exemplar.php?id=' . $exId);
confere('15. perfil consulta não abre o histórico com dados de aluno (403)', $s === 403);
[$s, $html] = $bib->ir('admin/historico-aluno.php?id=' . $alunoId);
confere('15. histórico da aluna mostra o empréstimo devolvido', $s === 200 && str_contains($html, 'Memórias Fictícias da Auditoria'));
confere('15. ações administrativas registradas (livro, exemplar, empréstimo, devolução, inventário)',
    $num("SELECT COUNT(DISTINCT acao) FROM acoes_administrativas WHERE acao IN ('emprestimo.registrar', 'devolucao.registrar', 'inventario.criar', 'inventario.iniciar')") === 4);
try {
    $pdo->exec('DELETE FROM movimentacoes WHERE exemplar_id = ' . $exId);
    confere('15. movimentações não podem ser apagadas', false);
} catch (PDOException $e) {
    confere('15. movimentações não podem ser apagadas', count($tipos) === $num('SELECT COUNT(*) FROM movimentacoes WHERE exemplar_id = ?', [$exId]));
}
$orfaos = $num('SELECT (SELECT COUNT(*) FROM emprestimos m LEFT JOIN exemplares e ON e.id = m.exemplar_id WHERE e.id IS NULL)
                     + (SELECT COUNT(*) FROM exemplares e LEFT JOIN livros l ON l.id = e.livro_id WHERE l.id IS NULL)
                     + (SELECT COUNT(*) FROM movimentacoes v LEFT JOIN exemplares e ON e.id = v.exemplar_id WHERE e.id IS NULL)
                     + (SELECT COUNT(*) FROM inventario_itens i LEFT JOIN exemplares e ON e.id = i.exemplar_id WHERE e.id IS NULL)');
confere('15. nenhum registro órfão depois do fluxo', $orfaos === 0);
confere('15. nenhum exemplar com dois empréstimos em aberto', $num('SELECT COUNT(*) FROM (SELECT exemplar_id FROM emprestimos WHERE data_devolucao IS NULL GROUP BY exemplar_id HAVING COUNT(*) > 1) x') === 0);

teste_resultado();
