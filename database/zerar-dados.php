<?php
/**
 * Zera os dados do sistema e deixa só os administradores (para começar do zero depois dos testes).
 *
 * Uso:  php database/zerar-dados.php              só MOSTRA o que seria apagado e o que fica (não muda nada)
 *       php database/zerar-dados.php --executar   faz uma cópia de segurança conferida (storage/backups ou
 *                                                 BACKUP_DIR no .env) e, só se ela der certo, apaga
 *
 * No XAMPP: C:\xampp\php\php.exe database/zerar-dados.php --executar
 *
 * Ficam: administradores (contas e senhas), configurações do sistema e do site, modelos de etiqueta,
 * categorias/setores/tabela CDD de referência, a sequência dos códigos BDP (para nunca repetir um código
 * já escrito num livro), o registro das migrações e o registro das cópias de segurança.
 * Sai todo o resto: livros, autores, importações, exemplares, inventários, lotes, estantes,
 * localizações, alunos, empréstimos, equipes de reorganização, históricos e tentativas de login.
 * A estrutura das tabelas não muda.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/database.php';

const ZERAR_MANTER = [
    'administradores', 'configuracoes_sistema', 'site_configuracoes', 'reorg_configuracoes', 'etiqueta_modelos',
    'categorias', 'setores', 'cdd_tabela_referencia', 'exemplar_codigo_seq',
    'schema_migracoes', 'schema_migracoes_execucoes', 'schema_migracoes_passos', 'backups',
];

$argv = $argv ?? [];
$executar = in_array('--executar', $argv, true);
$pdo = db();
if (!$pdo) {
    fwrite(STDERR, "Sem conexão com o banco. Confira o .env.\n");
    exit(1);
}
$tabelas = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$contar = fn (string $t): int => (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`')->fetchColumn();
$apagar = array_values(array_diff($tabelas, ZERAR_MANTER));

echo "Banco: " . env('DB_NAME') . "\n\nFICAM:\n";
foreach (array_intersect($tabelas, ZERAR_MANTER) as $t) {
    echo '  ' . str_pad($t, 34) . $contar($t) . "\n";
}
foreach ($pdo->query('SELECT id, nome, perfil, ativo FROM administradores ORDER BY id') as $a) {
    echo "    administrador #{$a['id']} {$a['nome']} ({$a['perfil']}" . ((int)$a['ativo'] ? '' : ', inativo') . ")\n";
}
echo "\nSAEM (linhas):\n";
$total = 0;
foreach ($apagar as $t) {
    $n = $contar($t);
    $total += $n;
    if ($n) {
        echo '  ' . str_pad($t, 34) . $n . "\n";
    }
}
echo "  Total: $total linha(s) em " . count($apagar) . " tabela(s).\n";

if ($total === 0) {
    echo "\nJá está zerado: nada para apagar.\n";
    exit(0);
}
if (!$executar) {
    echo "\nNada foi apagado. Para apagar (com cópia de segurança antes): php database/zerar-dados.php --executar\n";
    exit(0);
}

// Cópia de segurança conferida antes de apagar. Se falhar, nada é apagado.
echo "\nFazendo a cópia de segurança... ";
$pasta = (env('BACKUP_DIR') !== null && trim((string)env('BACKUP_DIR')) !== '') ? rtrim(trim((string)env('BACKUP_DIR')), '/\\') : __DIR__ . '/../storage/backups';
$arquivo = null;
try {
    \Biblioteca\Services\BackupService::prepararPasta($pasta);
    $arquivo = $pasta . DIRECTORY_SEPARATOR . 'biblioteca-dom-pedro-ii-antes-de-zerar-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
    $fb = fopen($arquivo, 'xb');
    if ($fb === false) {
        throw new RuntimeException('não foi possível criar o arquivo em ' . $pasta);
    }
    try {
        $resumo = (new \Biblioteca\Services\BackupService($pdo))->exportar($fb);
    } finally {
        fclose($fb);
    }
    clearstatcache(true, $arquivo);
    if (!\Biblioteca\Services\BackupService::terminaCompleto($arquivo)) {
        throw new RuntimeException('a cópia ficou incompleta');
    }
    $pdo->prepare("INSERT INTO backups (arquivo, origem, motivo, tamanho, sha256, tabelas, linhas, verificado) VALUES (?, 'servidor', 'Antes de zerar os dados', ?, ?, ?, ?, 1)")
        ->execute([basename($arquivo), filesize($arquivo), hash_file('sha256', $arquivo), $resumo['tabelas'], $resumo['linhas']]);
    echo "ok (" . basename($arquivo) . ", " . $resumo['linhas'] . " linhas)\n";
} catch (Throwable $e) {
    if ($arquivo && is_file($arquivo)) {
        @unlink($arquivo);
    }
    fwrite(STDERR, "ERRO na cópia de segurança (" . $e->getMessage() . "). Nada foi apagado.\n");
    exit(1);
}

echo "Apagando... ";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    $pdo->exec('UPDATE administradores SET lote_ativo_id = NULL, ultima_localizacao_id = NULL');
    foreach (['configuracoes_sistema', 'site_configuracoes', 'reorg_configuracoes'] as $t) {
        if (in_array($t, $tabelas, true)) {
            $pdo->exec("UPDATE `$t` SET atualizado_por = NULL");
        }
    }
    foreach ($apagar as $t) {
        $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '', $t) . '`');
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}
$sobra = array_sum(array_map($contar, $apagar));
echo $sobra === 0 ? "ok\n" : "ATENÇÃO: ainda restam $sobra linha(s)\n";
echo "Pronto. Ficaram os administradores e as configurações. Cópia: " . basename($arquivo) . "\n";
