<?php
/**
 * Cópia de segurança e restauração (Etapa 7 — revisão final).
 *
 * Uso: php admin/tests/backup-restauracao-teste.php
 *      (no XAMPP, se o mysql não estiver no PATH: set MYSQL_CLIENTE=C:\xampp\mysql\bin\mysql.exe)
 *
 * 1. Cria um banco temporário "bdp_teste_backup_XXXX" com dados FICTÍCIOS de todos os módulos
 *    (livros, autores, categorias, exemplares, localizações, alunos, empréstimos devolvidos e em
 *    aberto, renovação, inventário, CDD aprovada, configuração salva, ações registradas).
 * 2. Gera a cópia com o mesmo serviço da página Sistema → Manutenção (BackupService).
 * 3. Restaura SOMENTE em outro banco temporário "..._rest", com o cliente mysql (como no XAMPP).
 * 4. Compara estrutura, índices, chaves estrangeiras, gatilhos, views, procedimento, contagens e
 *    conteúdo (CHECKSUM) de cada tabela, e confere que as regras continuam valendo no banco
 *    restaurado (código BDP não reaproveitado, um empréstimo aberto por exemplar, histórico imutável).
 * 5. Confere que a cópia é uma "fotografia" única: um empréstimo gravado no meio da cópia não
 *    aparece pela metade.
 * O banco do .env nunca é usado, e nenhum dos dois bancos temporários fica no servidor.
 */
require __DIR__ . '/suporte.php';

use Biblioteca\Repositories\ConfiguracaoRepository;
use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\BackupService;
use Biblioteca\Services\EmprestimoService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LocalizacaoService;
use Biblioteca\Support\RegrasCirculacao;

[$raiz, $banco, $env] = teste_banco_temporario('backup');
$bancoPrincipal = null;
foreach (@file(APP_RAIZ . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $linha) {
    if (preg_match('/^\s*DB_NAME\s*=\s*"?([^"\s]+)"?/', $linha, $m)) {
        $bancoPrincipal = $m[1];
    }
}
$bancoRest = $banco . '_rest';
if ($bancoRest === $bancoPrincipal || $banco === $bancoPrincipal) {
    fwrite(STDERR, "Recusado: o teste nunca restaura no banco principal.\n");
    exit(1);
}
$pdo = db();
$num = fn (PDO $p, string $sql) => (int)$p->query($sql)->fetchColumn();

// ---------------------------------------------------------------
echo "\nDados fictícios de todos os módulos\n";
$pdo->prepare('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')->execute(['Bibliotecária Fictícia', 'bib@teste.br', password_hash('SenhaDeTeste123', PASSWORD_DEFAULT), 'bibliotecaria']);
$bib = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categorias (nome) VALUES ('Ficção Fictícia')");
$cat = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO autores (nome) VALUES ('Autora D''Ávila Fictícia')");
$autor = (int)$pdo->lastInsertId();
$livros = [];
foreach (["Livro com 'aspas', \\barra\\ e emoji 📚", "Segundo Livro Fictício\nCom quebra de linha", 'Terceiro Livro Fictício'] as $titulo) {
    $pdo->prepare('INSERT INTO livros (titulo, origem_cadastro, observacoes_internas) VALUES (?, ?, ?)')->execute([$titulo, 'manual', 'Observação fictícia ção']);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO livro_autores (livro_id, autor_id, ordem) VALUES (?, ?, 1)')->execute([$id, $autor]);
    $pdo->prepare('INSERT INTO livro_categorias (livro_id, categoria_id, principal) VALUES (?, ?, 1)')->execute([$id, $cat]);
    $livros[] = $id;
}
$local = (new LocalizacaoService($pdo))->salvar(['setor' => 'Literatura', 'estante' => 'E1', 'prateleira' => 'P1'], $bib);
$exs = new ExemplarService($pdo);
$ex = array_column($exs->cadastrar($livros[0], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel', 'localizacao_id' => $local], $bib, 4), 'id');
$exs->cadastrar($livros[1], ['estado_conservacao' => 'regular', 'situacao' => 'disponivel'], $bib, 2);
$exs->baixar((int)$ex[3], $bib, 'Exemplar fictício danificado');
$pdo->exec("INSERT INTO alunos (nome, matricula, turma) VALUES ('Aluno Fictício Backup', 'FIC-BK-1', '8B'), ('Aluna Fictícia Backup', 'FIC-BK-2', '9A')");
$alunos = $pdo->query('SELECT id FROM alunos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$circ = new EmprestimoService($pdo, RegrasCirculacao::doAmbiente('config_valor'));
$e1 = $circ->registrar((int)$alunos[0], (int)$ex[0], date('Y-m-d', strtotime('+14 days')), $bib);
$circ->renovar($e1['id'], date('Y-m-d', strtotime('+21 days')), $bib);
$e2 = $circ->registrar((int)$alunos[1], (int)$ex[1], date('Y-m-d', strtotime('+14 days')), $bib);
$circ->devolver($e2['id'], $bib);
$inv = new InventarioService($pdo, $exs, new InventarioRepository($pdo));
$invId = $inv->criar(['titulo' => 'Inventário fictício do backup', 'data_inicio' => date('Y-m-d'), 'responsavel_id' => $bib], $bib);
$inv->iniciar($invId, $bib);
$inv->conferir($invId, (int)$ex[2], ['estado_conservacao' => 'bom'], $bib);
$pdo->prepare("INSERT INTO classificacoes_cdd (livro_id, cdd_original, cdd_aprovada, status, aprovado_por, aprovado_em) VALUES (?, 'LIT', '869.3', 'aprovada', ?, NOW())")->execute([$livros[0], $bib]);
(new ConfiguracaoRepository($pdo))->salvar(['EMPRESTIMO_PRAZO_DIAS' => '21'], $bib);
$pdo->exec("INSERT INTO acoes_administrativas (usuario_id, acao) VALUES ($bib, 'teste.backup')");
confere('dados fictícios criados em todos os módulos', $num($pdo, 'SELECT COUNT(*) FROM emprestimo_renovacoes') === 1 && $num($pdo, 'SELECT COUNT(*) FROM inventario_itens') >= 5);

// ---------------------------------------------------------------
echo "\nCópia de segurança\n";
$arquivo = sys_get_temp_dir() . "/$banco.sql";
$GLOBALS['teste_temporarios'][] = $arquivo;
$fh = fopen($arquivo, 'wb');
$resumo = (new BackupService($pdo))->exportar($fh);
fclose($fh);
$sql = (string)file_get_contents($arquivo);
confere('cópia gerada com tabelas, views, procedimento e gatilhos: ' . json_encode($resumo), $resumo['tabelas'] >= 30 && $resumo['views'] >= 2 && $resumo['rotinas'] >= 1 && $resumo['gatilhos'] >= 20);
confere('cópia termina com a marca de fim (arquivo completo)', str_contains($sql, '-- Fim da cópia de segurança.'));
confere('cópia não apaga nada no destino (sem DROP) e não fixa o dono dos objetos (sem DEFINER)', !preg_match('/\bDROP\s+(TABLE|DATABASE|VIEW|TRIGGER|PROCEDURE)/i', $sql) && !preg_match('/DEFINER\s*=/i', $sql));
confere('conexão continua sem transação aberta depois da cópia', !$pdo->inTransaction() && $pdo->query('SELECT @@session.time_zone')->fetchColumn() === date('P'));

$cliente = getenv('MYSQL_CLIENTE') ?: trim((string)shell_exec('command -v mysql 2>/dev/null'));
if ($cliente === '' || !is_executable($cliente)) {
    echo "  (cliente mysql não encontrado: a restauração não foi testada. Defina MYSQL_CLIENTE.)\n";
    teste_resultado();
}
$raiz->exec("CREATE DATABASE `$bancoRest` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$GLOBALS['teste_bancos'][] = $bancoRest;
$restaurar = function (string $destino, string $arquivo) use ($cliente, $env): string {
    $cmd = escapeshellarg($cliente) . ' -h ' . escapeshellarg($env['DB_HOST']) . ' -P ' . (int)$env['DB_PORT'] . ' -u ' . escapeshellarg($env['DB_USER'])
        . ' --default-character-set=utf8mb4 ' . escapeshellarg($destino) . ' < ' . escapeshellarg($arquivo) . ' 2>&1';
    return trim((string)shell_exec('MYSQL_PWD=' . escapeshellarg($env['DB_PASSWORD']) . ' ' . $cmd));
};
$saida = $restaurar($bancoRest, $arquivo);
confere('restauração em banco temporário vazio sem erros', $saida === '', $saida);
$rest = new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname=$bancoRest;charset=utf8mb4", $env['DB_USER'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ---------------------------------------------------------------
echo "\nComparação do banco original com o restaurado\n";
$lista = fn (PDO $p, string $sql) => $p->query($sql)->fetchAll(PDO::FETCH_NUM);
$tabelas = $pdo->query("SELECT table_name FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$tabelasRest = $rest->query("SELECT table_name FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
confere('mesmas tabelas (' . count($tabelas) . ')', $tabelas === $tabelasRest);
$difEstrutura = $difContagem = $difConteudo = $difAuto = [];
foreach ($tabelas as $t) {
    $semAuto = fn (string $c) => preg_replace('/ AUTO_INCREMENT=\d+/', '', $c);
    if ($semAuto($pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1]) !== $semAuto($rest->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1])) {
        $difEstrutura[] = $t;
    }
    if ($num($pdo, "SELECT COUNT(*) FROM `$t`") !== $num($rest, "SELECT COUNT(*) FROM `$t`")) {
        $difContagem[] = $t;
    }
    if ($pdo->query("CHECKSUM TABLE `$t`")->fetch()['Checksum'] !== $rest->query("CHECKSUM TABLE `$t`")->fetch()['Checksum']) {
        $difConteudo[] = $t;
    }
    $auto = "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '$t'";
    if ($num($pdo, $auto) > $num($rest, $auto)) {
        $difAuto[] = $t;
    }
}
confere('estrutura idêntica de cada tabela (colunas, tipos, índices, restrições)', !$difEstrutura, implode(', ', $difEstrutura));
confere('mesmo número de registros em cada tabela', !$difContagem, implode(', ', $difContagem));
confere('conteúdo idêntico em cada tabela (CHECKSUM)', !$difConteudo, implode(', ', $difConteudo));
confere('contadores AUTO_INCREMENT preservados (ids antigos não são reaproveitados)', !$difAuto, implode(', ', $difAuto));
$q = [
    'chaves estrangeiras' => "SELECT table_name, constraint_name, referenced_table_name, update_rule, delete_rule FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE constraint_schema = DATABASE() ORDER BY 1, 2",
    'índices' => "SELECT table_name, index_name, non_unique, seq_in_index, column_name FROM information_schema.STATISTICS WHERE table_schema = DATABASE() ORDER BY 1, 2, 4",
    'gatilhos' => "SELECT trigger_name, event_manipulation, event_object_table, action_timing, action_statement FROM information_schema.TRIGGERS WHERE trigger_schema = DATABASE() ORDER BY 1",
    'views' => "SELECT table_name, REPLACE(view_definition, '`" . $banco . "`.', '') FROM information_schema.VIEWS WHERE table_schema = DATABASE() ORDER BY 1",
    'procedimentos' => "SELECT routine_name, routine_type, routine_definition FROM information_schema.ROUTINES WHERE routine_schema = DATABASE() ORDER BY 1",
    'restrições CHECK' => "SELECT constraint_name, check_clause FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() ORDER BY 1",
];
foreach ($q as $rotulo => $consulta) {
    $a = $lista($pdo, $consulta);
    $b = $lista($rest, str_replace("'`" . $banco . "`.'", "'`" . $bancoRest . "`.'", $consulta));
    confere("mesmos $rotulo (" . count($a) . ')', $a === $b && count($a) > 0);
}
$orfaos = $num($rest, 'SELECT (SELECT COUNT(*) FROM emprestimos m LEFT JOIN exemplares e ON e.id = m.exemplar_id WHERE e.id IS NULL)
    + (SELECT COUNT(*) FROM emprestimos m LEFT JOIN alunos a ON a.id = m.aluno_id WHERE a.id IS NULL)
    + (SELECT COUNT(*) FROM exemplares e LEFT JOIN livros l ON l.id = e.livro_id WHERE l.id IS NULL)
    + (SELECT COUNT(*) FROM movimentacoes v LEFT JOIN exemplares e ON e.id = v.exemplar_id WHERE e.id IS NULL)
    + (SELECT COUNT(*) FROM inventario_itens i LEFT JOIN inventarios n ON n.id = i.inventario_id WHERE n.id IS NULL)
    + (SELECT COUNT(*) FROM livro_autores la LEFT JOIN autores a ON a.id = la.autor_id WHERE a.id IS NULL)');
confere('integridade referencial no banco restaurado (nenhum registro órfão)', $orfaos === 0);
confere('texto com aspas, barra, emoji e quebra de linha idêntico', $rest->query('SELECT GROUP_CONCAT(titulo ORDER BY id) FROM livros')->fetchColumn() === $pdo->query('SELECT GROUP_CONCAT(titulo ORDER BY id) FROM livros')->fetchColumn());
confere('datas e horas dos empréstimos idênticas (sem deslocamento de fuso)', $rest->query('SELECT GROUP_CONCAT(data_emprestimo, data_prevista, COALESCE(data_devolucao, "") ORDER BY id) FROM emprestimos')->fetchColumn()
    === $pdo->query('SELECT GROUP_CONCAT(data_emprestimo, data_prevista, COALESCE(data_devolucao, "") ORDER BY id) FROM emprestimos')->fetchColumn());
confere('CDD aprovada e configuração salva restauradas', $rest->query('SELECT cdd_aprovada FROM classificacoes_cdd')->fetchColumn() === '869.3'
    && $rest->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'EMPRESTIMO_PRAZO_DIAS'")->fetchColumn() === '21');

// ---------------------------------------------------------------
echo "\nRegras do sistema no banco restaurado\n";
$ultimo = $num($rest, 'SELECT ultimo FROM exemplar_codigo_seq');
confere('sequência dos códigos BDP restaurada no último número usado', $ultimo === $num($pdo, 'SELECT ultimo FROM exemplar_codigo_seq') && $ultimo === 6);
$novos = (new ExemplarService($rest))->cadastrar($livros[2], ['estado_conservacao' => 'bom', 'situacao' => 'disponivel'], $bib, 1);
confere('novo exemplar no banco restaurado recebe o próximo código (nenhum código antigo reaproveitado)', $novos[0]['codigo'] === 'BDP-000007');
try {
    $rest->exec("INSERT INTO exemplares (livro_id, codigo_individual, situacao) VALUES ({$livros[2]}, 'BDP-000002', 'disponivel')");
    confere('código BDP repetido é recusado no banco restaurado', false);
} catch (PDOException $e) {
    confere('código BDP repetido é recusado no banco restaurado', true);
}
try {
    (new EmprestimoService($rest, RegrasCirculacao::doAmbiente('config_valor')))->registrar((int)$alunos[1], (int)$ex[0], date('Y-m-d', strtotime('+7 days')), $bib);
    confere('exemplar emprestado não pode ser emprestado de novo no banco restaurado', false);
} catch (Throwable $e) {
    confere('exemplar emprestado não pode ser emprestado de novo no banco restaurado', $num($rest, "SELECT COUNT(*) FROM emprestimos WHERE exemplar_id = {$ex[0]} AND data_devolucao IS NULL") === 1);
}
foreach (['DELETE FROM emprestimos' => 'empréstimos', 'DELETE FROM movimentacoes' => 'movimentações', 'UPDATE emprestimo_renovacoes SET observacao = 1' => 'renovações', 'DELETE FROM exemplares' => 'exemplares'] as $sqlProibido => $rotulo) {
    try {
        $rest->exec($sqlProibido);
        confere("histórico de $rotulo protegido pelos gatilhos restaurados", false);
    } catch (PDOException $e) {
        confere("histórico de $rotulo protegido pelos gatilhos restaurados", true);
    }
}
$st = $rest->prepare('CALL gerar_exemplares(?, 1, NULL, ?, ?)');
$st->execute([$livros[2], 'bom', 'disponivel']);
$cod = $st->fetchAll()[0]['codigo_individual'] ?? '';
$st->closeCursor();
confere('procedimento gerar_exemplares funciona no banco restaurado (BDP-000008)', $cod === 'BDP-000008');
$rest = null;
$saida = $restaurar($bancoRest, $arquivo);
confere('restaurar de novo sobre um banco com dados falha em vez de sobrescrever', str_contains($saida, 'already exists'));

// ---------------------------------------------------------------
echo "\nFotografia única: gravação durante a cópia\n";
/** Saída que, no primeiro trecho gravado, registra um empréstimo por outra conexão (como outro computador). */
final class SaidaComInterrupcao
{
    public $context;
    public static $acao;
    public static string $texto = '';
    private static bool $feito = false;
    public function stream_open(): bool
    {
        return true;
    }
    public function stream_write(string $d): int
    {
        if (!self::$feito && str_contains($d, '-- Tabela')) {
            self::$feito = true;
            (self::$acao)();
        }
        self::$texto .= $d;
        return strlen($d);
    }
}
stream_wrapper_register('bdpteste', SaidaComInterrupcao::class);
$outra = new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname=$banco;charset=utf8mb4", $env['DB_USER'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$outra->exec("SET time_zone = '" . date('P') . "'");
SaidaComInterrupcao::$acao = function () use ($outra, $alunos, $ex, $bib) {
    (new EmprestimoService($outra, RegrasCirculacao::doAmbiente('config_valor')))->registrar((int)$alunos[1], (int)$ex[2], date('Y-m-d', strtotime('+7 days')), $bib);
};
$fh = fopen('bdpteste://copia', 'w');
(new BackupService($pdo))->exportar($fh);
fclose($fh);
$copia = SaidaComInterrupcao::$texto;
$secao = function (string $tabela) use ($copia): string {
    $ini = strpos($copia, "-- Tabela $tabela\n");
    if ($ini === false) {
        return '';
    }
    $fim = strpos($copia, '-- Tabela ', $ini + 10);
    return substr($copia, $ini, $fim === false ? null : $fim - $ini);
};
confere('o empréstimo gravado durante a cópia foi feito de verdade no banco', $num($pdo, "SELECT COUNT(*) FROM emprestimos WHERE exemplar_id = {$ex[2]}") === 1);
confere('a cópia não traz o empréstimo gravado no meio dela', preg_match_all('/^\(\d+, ' . (int)$ex[2] . ', /m', $secao('emprestimos')) === 0 && $secao('emprestimos') !== '');
confere('e o exemplar aparece na cópia como estava antes (disponível), sem estado pela metade',
    (bool)preg_match('/^\(' . (int)$ex[2] . ', [^\n]*\'disponivel\'/m', $secao('exemplares')));

teste_resultado();
