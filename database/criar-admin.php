<?php
/**
 * Cria (ou reativa) uma conta de administrador no MySQL. Desde a migração 200 o painel tem um único
 * perfil: toda conta é administradora, com acesso total.
 *
 * Uso: php database/criar-admin.php "Nome Completo" email@escola ["Função"]
 * A função é opcional e só identifica a pessoa (ex.: "Bibliotecária"). Os nomes de perfil antigos
 * (bibliotecaria, auxiliar, consulta) ainda são aceitos e viram a função correspondente.
 * A senha é pedida no terminal (não fica no histórico do shell).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../admin/includes/auth.php';

[, $nome, $email, $funcao] = $argv + [null, '', '', ''];
$email = mb_strtolower(trim((string)$email));
$antigos = ['administrador' => 'Administrador', 'bibliotecaria' => 'Bibliotecária', 'auxiliar' => 'Auxiliar de biblioteca', 'consulta' => 'Consulta'];
$funcao = $antigos[mb_strtolower(trim((string)$funcao))] ?? mb_substr(trim((string)$funcao), 0, 100);
$funcao = $funcao !== '' ? $funcao : 'Administrador';
if (trim((string)$nome) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php database/criar-admin.php \"Nome\" email [\"Função\"]\n");
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
        [trim($nome), $email, password_hash($senha, PASSWORD_DEFAULT), 'administrador', $funcao]
    );
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Administrador $email salvo (função: $funcao).\n";
