<?php
/**
 * Testes da retomada do migrar.php: um arquivo que parou no meio numa tentativa anterior
 * (estrutura já criada, mas sem registro em schema_migracoes) é concluído ao rodar de novo.
 *
 * Uso: php admin/tests/migrar-retomada-teste.php
 *
 * Banco temporário "bdp_teste_migret_XXXX" (apagado no final), sem dados reais. Cenários:
 *   1. 170 com as colunas já criadas e o UPDATE pendente: é concluída (o UPDATE roda);
 *   2. 180 com tabelas e gatilhos já criados: é concluída, sem duplicar nada;
 *   3. coluna faltando no mesmo ALTER: o erro aparece e o arquivo não é marcado;
 *   4. outros erros continuam interrompendo; dados existentes não mudam.
 */

require __DIR__ . '/suporte.php';

[$raiz, $banco, $env] = teste_banco_temporario('migret');
$pdo = db();
$num = fn (string $sql) => (int)$pdo->query($sql)->fetchColumn();
$rodar = function () use ($env): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/../../database/migrar.php', '--sem-backup'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), trim($out)];
};
$marcada = fn (string $a) => $num("SELECT COUNT(*) FROM schema_migracoes WHERE arquivo = '$a'") === 1;
$m170 = '170_obra_planilha_exemplares.sql';
$m180 = '180_comparacao_planilha.sql';

// Dados fictícios: um inventário em andamento com uma linha da planilha cuja obra já teve cópia encontrada.
$pdo->exec("INSERT INTO livros (titulo, origem_cadastro) VALUES ('Obra Fictícia de Teste', 'manual')");
$livro = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventarios (titulo, status) VALUES ('Inventário fictício', 'em_andamento')");
$inv = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO exemplares (livro_id, codigo_individual) VALUES ($livro, 'BDP-990001')");
$ex = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventario_itens (inventario_id, livro_id, exemplar_id, conferido_em) VALUES ($inv, $livro, $ex, '2026-11-18 10:00:00')");
$pdo->exec("INSERT INTO inventario_itens (inventario_id, livro_id) VALUES ($inv, $livro)");
$linha = (int)$pdo->lastInsertId();
$codigos = $pdo->query('SELECT GROUP_CONCAT(codigo_individual ORDER BY id) FROM exemplares')->fetchColumn();

echo "\n1. 170 parada depois do ALTER (colunas existem, UPDATE não rodou, sem registro)\n";
$pdo->exec("DELETE FROM schema_migracoes WHERE arquivo IN ('$m170', '$m180')");
$pdo->exec("UPDATE inventario_itens SET obra_localizada_em = NULL WHERE id = $linha");
[$cod, $out] = $rodar();
confere('migrar.php termina sem erro', $cod === 0, $out);
confere('170 informa o comando já aplicado e pulado', str_contains($out, "$m170... ok (1 comando(s) já aplicado(s) antes, pulado(s))"), $out);
confere('170 fica registrada', $marcada($m170));
confere('o UPDATE da 170 rodou (linha virou "Obra localizada")', $num("SELECT COUNT(*) FROM inventario_itens WHERE id = $linha AND obra_localizada_em = '2026-11-18 10:00:00'") === 1);

echo "\n2. 180 parada no meio (tabelas e gatilhos já criados)\n";
confere('180 também foi retomada e registrada', $marcada($m180) && str_contains($out, "$m180... ok ("), $out);
confere('nenhuma tabela da 180 duplicada ou recriada', $num("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('comparacao_resolucoes','comparacoes','exemplar_obra_correcoes')") === 3);
confere('gatilhos da 180 presentes', $num("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('comparacao_resolucoes_sem_exclusao','comparacoes_sem_alteracao','exemplar_obra_correcoes_sem_exclusao','exemplares_preservar_identidade','inventario_itens_alterar')") === 5);
$recusou = false;
try {
    $pdo->exec("UPDATE exemplares SET codigo_individual = 'BDP-990002' WHERE id = $ex");
} catch (PDOException $e) {
    $recusou = true;
}
confere('o código BDP continua protegido depois da retomada', $recusou);
[$cod, $out] = $rodar();
confere('rodar de novo: nada pendente', $cod === 0 && str_contains($out, 'Nenhuma migração pendente'), $out);

echo "\n3. Coluna faltando no mesmo ALTER: o erro não é escondido\n";
$pdo->exec("DELETE FROM schema_migracoes WHERE arquivo IN ('$m170', '$m180')");
$pdo->exec('ALTER TABLE inventario_itens DROP COLUMN obra_localizada_por');
[$cod, $out] = $rodar();
confere('migrar.php para com o erro de coluna duplicada', $cod !== 0 && str_contains($out, '1060'), $out);
confere('170 não fica registrada', !$marcada($m170));
confere('nada além do que existia foi criado', $num("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventario_itens' AND COLUMN_NAME = 'obra_localizada_por'") === 0);
$pdo->exec('ALTER TABLE inventario_itens ADD COLUMN obra_localizada_por BIGINT NULL AFTER obra_localizada_em');
[$cod, $out] = $rodar();
confere('com a estrutura completa, conclui normalmente', $cod === 0 && $marcada($m170) && $marcada($m180), $out);

echo "\n4. Outros erros continuam interrompendo; dados preservados\n";
$f = __DIR__ . '/../../database/migrations/999_teste_retomada_erro.sql';
file_put_contents($f, "ALTER TABLE tabela_que_nao_existe ADD COLUMN x INT;\n");
$GLOBALS['teste_temporarios'][] = $f;
[$cod, $out] = $rodar();
@unlink($f);
confere('erro diferente (tabela inexistente) interrompe', $cod !== 0 && !$marcada('999_teste_retomada_erro.sql'), $out);
confere('códigos BDP sem mudança', $pdo->query('SELECT GROUP_CONCAT(codigo_individual ORDER BY id) FROM exemplares')->fetchColumn() === $codigos);
confere('registros do inventário preservados', $num("SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = $inv") === 2);

teste_resultado();
