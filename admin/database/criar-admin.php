<?php
/**
 * Cria (ou reativa) um usuário administrativo no MySQL.
 *
 * Uso: php admin/database/criar-admin.php "Nome Completo" email@escola perfil
 * Perfis: administrador | bibliotecaria | auxiliar | consulta
 * A senha é pedida no terminal (não fica no histórico do shell).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/auth.php';

[, $nome, $email, $perfil] = $argv + [null, '', '', 'bibliotecaria'];
$email = mb_strtolower(trim((string)$email));
if (trim((string)$nome) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(AUTH_PERFIS[$perfil])) {
    fwrite(STDERR, "Uso: php admin/database/criar-admin.php \"Nome\" email perfil\nPerfis: " . implode(', ', array_keys(AUTH_PERFIS)) . "\n");
    exit(1);
}
$senha = getenv('BDP_NOVA_SENHA') ?: null;
if ($senha === null) {
    echo 'Senha (mínimo 10 caracteres): ';
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $senha = trim((string)fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
    }
    echo "\n";
}
if (mb_strlen($senha) < 10) {
    fwrite(STDERR, "A senha precisa ter pelo menos 10 caracteres.\n");
    exit(1);
}
try {
    db_query(
        'INSERT INTO administradores (nome, email, senha_hash, perfil, cargo) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash), perfil = VALUES(perfil), cargo = VALUES(cargo), ativo = 1',
        [trim($nome), $email, password_hash($senha, PASSWORD_DEFAULT), $perfil, AUTH_PERFIS[$perfil]]
    );
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Usuário $email salvo com o perfil " . AUTH_PERFIS[$perfil] . ".\n";
