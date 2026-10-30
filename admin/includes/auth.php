<?php
/**
 * Autenticação, permissões e proteção CSRF do painel.
 *
 * Com MySQL configurado, os usuários vêm da tabela "administradores" (senha com password_hash).
 * No modo demonstrativo, existem duas contas fictícias (ver AUTH_CONTAS_DEMO), só para testar telas.
 * As contas demonstrativas só funcionam fora de produção (APP_ENV diferente de "production"):
 * em produção, sem banco configurado, ninguém consegue entrar no painel.
 */
require_once __DIR__ . '/db.php';

/** Permissões de cada perfil. */
/**
 * Permissões de cada perfil. A conferência é sempre feita no servidor (auth_exigir / auth_pode);
 * esconder um botão na tela é só conveniência.
 *   livros.editar     cadastrar e editar livros
 *   livros.arquivar   arquivar e restaurar livros
 *   usuarios.gerenciar  cadastrar, editar, ativar/desativar contas do painel
 *   alunos.importar   importar e atualizar o cadastro de alunos a partir de planilhas
 *   emprestimos.ver   ver empréstimos, atrasos e históricos (contém dados pessoais dos alunos)
 *   emprestimos.registrar  registrar empréstimos, devoluções e renovações no prazo padrão
 *   emprestimos.prazo escolher outra data de devolução (no empréstimo e na renovação) e
 *                     informar a data real de uma devolução registrada depois
 *   exemplares.gerenciar  cadastrar exemplares físicos, editar conservação e observações,
 *                     transferir de localização e mudar a situação (disponível, manutenção...)
 *   exemplares.baixar dar baixa e registrar extravio de exemplares
 *   localizacoes.gerenciar  cadastrar, editar, desativar e reativar localizações
 *   inventario.conferir  registrar conferências e cadastrar exemplares durante o inventário
 *   inventario.gerenciar criar, iniciar, pausar, concluir e cancelar inventários, decidir
 *                     divergências e ausências e corrigir conferências
 *   etiquetas.gerar   pré-visualizar e gerar o PDF das etiquetas
 *   etiquetas.configurar  cadastrar e editar os modelos (tamanhos e margens) das etiquetas
 * Ver exemplares, localizações e inventários é permitido a todos os perfis (painel.ver).
 */
const AUTH_PERMISSOES = [
    'administrador' => ['painel.ver', 'livros.editar', 'livros.arquivar', 'usuarios.gerenciar', 'alunos.importar', 'emprestimos.ver', 'emprestimos.registrar', 'emprestimos.prazo', 'cdd.ver', 'cdd.sugerir', 'cdd.lote', 'cdd.aprovar', 'cdd.exportar', 'importacao.ver', 'importacao.revisar', 'importacao.executar', 'exemplares.gerenciar', 'exemplares.baixar', 'localizacoes.gerenciar', 'inventario.conferir', 'inventario.gerenciar', 'etiquetas.gerar', 'etiquetas.configurar'],
    'bibliotecaria' => ['painel.ver', 'livros.editar', 'livros.arquivar', 'alunos.importar', 'emprestimos.ver', 'emprestimos.registrar', 'emprestimos.prazo', 'cdd.ver', 'cdd.sugerir', 'cdd.lote', 'cdd.aprovar', 'cdd.exportar', 'importacao.ver', 'importacao.revisar', 'importacao.executar', 'exemplares.gerenciar', 'exemplares.baixar', 'localizacoes.gerenciar', 'inventario.conferir', 'inventario.gerenciar', 'etiquetas.gerar', 'etiquetas.configurar'],
    'auxiliar' => ['painel.ver', 'livros.editar', 'emprestimos.ver', 'emprestimos.registrar', 'cdd.ver', 'cdd.sugerir', 'cdd.lote', 'cdd.exportar', 'importacao.ver', 'importacao.revisar', 'exemplares.gerenciar', 'inventario.conferir', 'etiquetas.gerar'],
    'consulta' => ['painel.ver', 'cdd.ver', 'cdd.exportar', 'importacao.ver'],
];

const AUTH_PERFIS = [
    'administrador' => 'Administrador',
    'bibliotecaria' => 'Bibliotecária',
    'auxiliar' => 'Auxiliar de biblioteca',
    'consulta' => 'Somente consulta',
];

/** Contas do modo demonstrativo (sem banco). Senha de ambas: demo */
const AUTH_CONTAS_DEMO = [
    'bibliotecaria@demo' => ['id' => 1, 'nome' => 'Ana Souza', 'perfil' => 'bibliotecaria', 'cargo' => 'Bibliotecária'],
    'auxiliar@demo' => ['id' => 2, 'nome' => 'Carlos Lima', 'perfil' => 'auxiliar', 'cargo' => 'Auxiliar de biblioteca'],
];

const AUTH_MAX_TENTATIVAS = 5;

/** Sessão encerrada depois deste tempo sem uso (computadores compartilhados da escola). */
const AUTH_INATIVIDADE_MINUTOS = 120;

/** Tamanho mínimo das senhas (o mesmo de database/criar-admin.php). */
const AUTH_SENHA_MINIMA = 10;

/** Contas demonstrativas só valem sem banco e fora de produção. */
function auth_demo_disponivel(): bool
{
    return db_modo() === 'demo' && app_ambiente() !== 'production';
}
const AUTH_JANELA_MINUTOS = 15;

function auth_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        $_SESSION ??= [];
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('BDPADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** Usuário logado (id, nome, perfil, cargo, iniciais) ou null. */
function auth_usuario(): ?array
{
    static $usuario = false;
    if ($usuario !== false) {
        return $usuario;
    }
    auth_sessao();
    $sessao = $_SESSION['auth'] ?? null;
    if (!$sessao || ($sessao['modo'] ?? '') !== db_modo() || ($sessao['modo'] === 'demo' && !auth_demo_disponivel())) {
        return $usuario = null;
    }
    // Sessão parada há muito tempo: exige novo login.
    if (isset($sessao['visto']) && time() - (int)$sessao['visto'] > AUTH_INATIVIDADE_MINUTOS * 60) {
        unset($_SESSION['auth']);
        return $usuario = null;
    }
    $_SESSION['auth']['visto'] = time();
    if ($sessao['modo'] === 'mysql') {
        // Confere a cada requisição: um usuário desativado perde o acesso imediatamente,
        // e uma senha redefinida encerra as sessões abertas com a senha antiga.
        try {
            $linha = db_query('SELECT id, nome, email, perfil, cargo, senha_hash FROM administradores WHERE id = ? AND ativo = 1', [$sessao['id']])->fetch();
        } catch (Throwable $e) {
            error_log('[biblioteca] Falha ao carregar usuário: ' . $e->getMessage());
            return $usuario = null;
        }
        if (!$linha || !hash_equals(auth_marca_senha($linha['senha_hash']), (string)($sessao['senha'] ?? ''))) {
            unset($_SESSION['auth']);
            return $usuario = null;
        }
        unset($linha['senha_hash']);
        $sessao = array_merge($sessao, $linha);
    }
    unset($sessao['senha'], $sessao['visto']);
    $sessao['id'] = (int)$sessao['id'];
    $sessao['iniciais'] = auth_iniciais($sessao['nome']);
    $sessao['cargo'] = $sessao['cargo'] ?: (AUTH_PERFIS[$sessao['perfil']] ?? '');
    return $usuario = $sessao;
}

function auth_iniciais(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome)) ?: [];
    $primeira = $partes[0] ?? '';
    $ultima = count($partes) > 1 ? end($partes) : '';
    return mb_strtoupper(mb_substr($primeira, 0, 1) . mb_substr($ultima, 0, 1));
}

function auth_pode(string $permissao): bool
{
    $usuario = auth_usuario();
    return $usuario !== null && in_array($permissao, AUTH_PERMISSOES[$usuario['perfil']] ?? [], true);
}

/**
 * Exige usuário logado com a permissão indicada.
 * Páginas: redireciona para o login. API ($json = true): responde 401/403 em JSON.
 */
function auth_exigir(string $permissao = 'painel.ver', bool $json = false): array
{
    $usuario = auth_usuario();
    if (!$usuario) {
        if ($json) {
            api_resposta(['ok' => false, 'erro' => 'Sua sessão expirou. Entre novamente.'], 401);
        }
        $voltar = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . admin_base() . 'login.php?voltar=' . rawurlencode($voltar));
        exit;
    }
    if (!auth_pode($permissao)) {
        if ($json) {
            api_resposta(['ok' => false, 'erro' => 'Você não tem permissão para esta ação.'], 403);
        }
        http_response_code(403);
        admin_pagina_erro('Acesso não permitido', 'Seu perfil não tem permissão para acessar esta página. Fale com a bibliotecária responsável.', 'lock');
        exit;
    }
    return $usuario;
}

/** Tenta entrar. Retorna null em caso de sucesso ou a mensagem de erro (sempre genérica). */
function auth_login(string $email, string $senha): ?string
{
    auth_sessao();
    $email = mb_strtolower(trim($email));
    $generica = 'E-mail ou senha incorretos.';
    if ($email === '' || $senha === '') {
        return 'Informe e-mail e senha.';
    }

    if (db_modo() === 'demo') {
        if (!auth_demo_disponivel()) {
            return 'O banco de dados ainda não foi configurado. Fale com o responsável técnico.';
        }
        $conta = AUTH_CONTAS_DEMO[$email] ?? null;
        if (!$conta || !hash_equals('demo', $senha)) {
            return $generica;
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = $conta + ['modo' => 'demo', 'email' => $email];
        return null;
    }

    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
    $falhas = (int)db_query(
        'SELECT COUNT(*) FROM login_tentativas WHERE sucesso = 0 AND (email = ? OR ip = ?) AND criado_em > NOW() - INTERVAL ' . AUTH_JANELA_MINUTOS . ' MINUTE',
        [$email, $ip]
    )->fetchColumn();
    if ($falhas >= AUTH_MAX_TENTATIVAS) {
        return 'Muitas tentativas sem sucesso. Aguarde ' . AUTH_JANELA_MINUTOS . ' minutos e tente novamente.';
    }

    $linha = db_query('SELECT id, senha_hash, ativo FROM administradores WHERE email = ?', [$email])->fetch();
    // password_verify roda mesmo sem usuário, para não revelar quais e-mails existem pelo tempo de resposta.
    $hash = $linha['senha_hash'] ?? '$2y$10$OWXZ9kvzTCmu2qdyG/llLebor8ORAn0Na7ZVT14ZCcUGcTDnVkPwu';
    $ok = password_verify($senha, $hash) && $linha && (int)$linha['ativo'] === 1;
    db_query('INSERT INTO login_tentativas (email, ip, sucesso) VALUES (?, ?, ?)', [$email, $ip, $ok ? 1 : 0]);
    if (!$ok) {
        return $generica;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        db_query('UPDATE administradores SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $linha['id']]);
    }
    db_query('UPDATE administradores SET ultimo_acesso = NOW() WHERE id = ?', [$linha['id']]);
    $hashAtual = (string)db_query('SELECT senha_hash FROM administradores WHERE id = ?', [$linha['id']])->fetchColumn();
    session_regenerate_id(true);
    $_SESSION['auth'] = ['id' => (int)$linha['id'], 'modo' => 'mysql', 'senha' => auth_marca_senha($hashAtual), 'visto' => time()];
    return null;
}

/**
 * Marca da senha guardada na sessão (um resumo do hash, nunca a senha).
 * Quando a senha muda, a marca deixa de bater e as outras sessões abertas são encerradas.
 */
function auth_marca_senha(string $hash): string
{
    return substr(hash_hmac('sha256', $hash, 'bdp-sessao'), 0, 32);
}

/** Atualiza a marca da sessão atual depois que o próprio usuário troca a senha (para não sair do painel). */
function auth_renovar_marca_senha(string $novoHash): void
{
    auth_sessao();
    if (isset($_SESSION['auth'])) {
        $_SESSION['auth']['senha'] = auth_marca_senha($novoHash);
    }
}

/** Regras de senha do painel. Retorna a mensagem do problema ou null quando a senha serve. */
function auth_senha_problema(string $senha, string $confirmacao): ?string
{
    if (mb_strlen($senha) < AUTH_SENHA_MINIMA) {
        return 'A senha precisa ter pelo menos ' . AUTH_SENHA_MINIMA . ' caracteres.';
    }
    if (strlen($senha) > 72) {
        return 'A senha pode ter no máximo 72 caracteres.';
    }
    if (!preg_match('/\pL/u', $senha) || !preg_match('/\d/', $senha)) {
        return 'Use letras e números na senha.';
    }
    if (!hash_equals($senha, $confirmacao)) {
        return 'A confirmação não é igual à senha digitada.';
    }
    return null;
}

/**
 * Registra uma ação administrativa (quem, o quê, quando, de onde) em acoes_administrativas.
 * Falhas no registro não interrompem a operação; nunca grave senhas nos detalhes.
 */
function registrar_acao(string $acao, ?string $entidade = null, ?int $entidadeId = null, array $detalhes = []): void
{
    if (db_modo() !== 'mysql') {
        return;
    }
    try {
        $usuario = auth_usuario();
        db_query(
            'INSERT INTO acoes_administrativas (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?, ?, ?, ?, ?, ?)',
            [$usuario['id'] ?? null, $acao, $entidade, $entidadeId, $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null, substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45)]
        );
    } catch (Throwable $e) {
        error_log('[biblioteca] Falha ao registrar ação ' . $acao . ': ' . $e->getMessage());
    }
}

function auth_logout(): void
{
    auth_sessao();
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// ---------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------

function csrf_token(): string
{
    auth_sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto para formulários. */
function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Confere o token enviado no formulário (campo "csrf") ou no cabeçalho X-CSRF-Token. */
function csrf_valido(): bool
{
    $enviado = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($enviado) && $enviado !== '' && hash_equals(csrf_token(), $enviado);
}

// ---------------------------------------------------------------
// Respostas
// ---------------------------------------------------------------

function api_resposta(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Página simples de erro, com o layout do painel quando disponível. */
function admin_pagina_erro(string $titulo, string $mensagem, string $icone = 'circle-alert'): void
{
    if (!function_exists('admin_page_start')) {
        echo '<!DOCTYPE html><meta charset="utf-8"><title>' . htmlspecialchars($titulo) . '</title><h1>' . htmlspecialchars($titulo) . '</h1><p>' . htmlspecialchars($mensagem) . '</p>';
        return;
    }
    admin_page_start('dashboard', $titulo, 'Painel', 'Biblioteca Dom Pedro II');
    ?>
    <section class="adm-card adm-wip">
        <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon($icone) ?></span>
        <h1><?= htmlspecialchars($titulo) ?></h1>
        <p><?= htmlspecialchars($mensagem) ?></p>
        <div class="adm-wip__actions">
            <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('dashboard')) ?>"><?= icon('arrow-left') ?> Voltar ao painel</a>
        </div>
    </section>
    <?php
    admin_page_end();
}
