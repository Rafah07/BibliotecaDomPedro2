<?php
/**
 * Página pública das equipes da organização inicial (migração 210). Sem login: o acesso vem do link
 * gerado pelo administrador na página do lote (token aleatório, válido só para aquele lote e até a
 * data escolhida). Quem abre digita só o nome.
 *
 *   Link de coleta     → pré-cadastro de cada livro do lote (nada entra no acervo sem a revisão).
 *   Link de carimbagem → Planilha BDP do lote: marcar exemplares como carimbados e concluir a etapa.
 *   Os dois           → "Pausar trabalho" / "Retomar trabalho" da própria etapa.
 *
 * Segurança: o token sai do endereço logo no primeiro acesso (fica só na sessão desta página, que é
 * separada da sessão do painel); cada envio exige o token CSRF da sessão; limite de envios por minuto;
 * nada de alunos, empréstimos ou do painel aparece aqui. A configuração COLETA_LINKS_ATIVOS desliga
 * todos os links de uma vez.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../admin/includes/icones.php';

use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ColetaService;
use Biblioteca\Services\ExemplarService;

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');          // o endereço com o token nunca vai para outro site
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

$base = url('coleta/');

function coleta_parar(int $status, string $titulo, string $mensagem): never
{
    http_response_code($status);
    coleta_inicio($titulo, null);
    echo '<div class="adm-card adm-panel"><div class="adm-empty">' . icon('link') . '<strong>' . e($titulo) . '</strong><span>' . e($mensagem) . '</span></div></div>';
    coleta_fim();
    exit;
}

function coleta_inicio(string $titulo, ?array $link): void
{
    $equipe = $link ? $link['equipe'] . ' · ' . ColetaService::nome($link['lote']) : '';
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($titulo) . ' — Biblioteca Dom Pedro II</title>'
        . '<link rel="stylesheet" href="' . e(url('admin/assets/css/admin.css')) . '"><link rel="stylesheet" href="' . e(url('admin/assets/css/acervo.css')) . '">'
        . '<link rel="stylesheet" href="' . e(url('admin/assets/css/coleta.css?v=20261126')) . '"></head><body class="adm-body coleta-body">'
        . '<header class="coleta-topo"><div class="coleta-topo__in"><strong>Biblioteca Dom Pedro II · Organização do acervo</strong><small>' . e($equipe) . '</small></div></header>'
        . '<main class="coleta-main">';
}

function coleta_fim(): void
{
    echo '</main><script src="' . e(url('admin/assets/js/coleta.js?v=20261126')) . '"></script></body></html>';
}

function coleta_redirecionar(string $destino): never
{
    header('Location: ' . $destino, true, 303);
    exit;
}

function coleta_flash(string $tipo, string $texto): void
{
    $_SESSION['coleta_flash'] = [$tipo, $texto];
}

// ---------------------------------------------------------------- sessão própria desta página
session_name('BDPCOLETA');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => app_https(), 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();
$_SESSION['coleta_csrf'] ??= bin2hex(random_bytes(32));

if (!db()) {
    coleta_parar(503, 'Sistema indisponível', 'Não foi possível conectar ao banco de dados. Tente novamente em alguns minutos.');
}
try {
    if (!db_query("SHOW TABLES LIKE 'coleta_links'")->fetchColumn()) {
        coleta_parar(503, 'Recurso ainda não instalado', 'A administração precisa aplicar a atualização do sistema (migração 210).');
    }
} catch (PDOException) {
    coleta_parar(503, 'Sistema indisponível', 'Tente novamente em alguns minutos.');
}
if (!config_bool('COLETA_LINKS_ATIVOS', true)) {
    coleta_parar(403, 'Links desligados', 'Os links das equipes foram desligados pela administração da biblioteca.');
}

$servico = new ColetaService(db());

// Primeiro acesso: guarda o token na sessão e tira-o do endereço.
$tokenUrl = is_string($_GET['t'] ?? null) ? strtolower(trim($_GET['t'])) : '';
if ($tokenUrl !== '') {
    try {
        $servico->abrirPorToken($tokenUrl);
    } catch (AcervoException $e) {
        coleta_parar(403, 'Link indisponível', $e->getMessage());
    }
    if (($_SESSION['coleta_token'] ?? '') !== $tokenUrl) {
        session_regenerate_id(true);
        $_SESSION['coleta_token'] = $tokenUrl;
        unset($_SESSION['coleta_nome']);
    }
    coleta_redirecionar($base);
}
$token = (string)($_SESSION['coleta_token'] ?? '');
if ($token === '') {
    coleta_parar(403, 'Abra pelo link recebido', 'Esta página só funciona pelo link enviado pela administração da biblioteca.');
}
try {
    $link = $servico->abrirPorToken($token);
} catch (AcervoException $e) {
    unset($_SESSION['coleta_token'], $_SESSION['coleta_nome']);
    coleta_parar(403, 'Link indisponível', $e->getMessage());
}
$lote = $link['lote'];
$nomeLote = ColetaService::nome($lote);
$etapa = $link['tipo'] === 'coleta' ? 'coleta' : 'carimbagem';
$nome = (string)($_SESSION['coleta_nome'] ?? '');

// ---------------------------------------------------------------- envios
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['coleta_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        coleta_parar(419, 'Página expirada', 'Recarregue a página e tente de novo. Nada foi gravado.');
    }
    // Limite simples: no máximo 60 envios por minuto nesta sessão.
    $_SESSION['coleta_envios'] = array_values(array_filter($_SESSION['coleta_envios'] ?? [], fn ($t) => $t > time() - 60));
    if (count($_SESSION['coleta_envios']) >= 60) {
        coleta_parar(429, 'Muitos envios seguidos', 'Aguarde um minuto e tente de novo.');
    }
    $_SESSION['coleta_envios'][] = time();
    $acao = (string)($_POST['acao'] ?? '');
    $voltar = $base;
    try {
        if ($acao === 'nome') {
            $_SESSION['coleta_nome'] = ColetaService::nomePessoa((string)($_POST['nome'] ?? ''));
            coleta_redirecionar($base);
        }
        if ($nome === '') {
            throw new AcervoException('Digite o seu nome antes de continuar.');
        }
        switch ($acao) {
            case 'salvar':
                [$dados, $erros] = ColetaService::validar($_POST);
                $itemId = (int)($_POST['item_id'] ?? 0) ?: null;
                if ($erros) {
                    $_SESSION['coleta_form'] = ['valores' => $_POST, 'erros' => $erros, 'item_id' => $itemId];
                    coleta_flash('erro', 'Confira os campos destacados. Nada foi gravado.');
                    break;
                }
                $r = $servico->salvarItem($link, $nome, $dados, $itemId);
                coleta_flash('sucesso', 'Livro nº ' . $r['numero'] . ' ' . ($itemId ? 'corrigido' : 'salvo') . '. Escreva o número ' . $r['numero'] . ' no marcador e deixe-o dentro do livro.'
                    . ($r['avisos'] ? ' Atenção: ' . implode(' ', $r['avisos']) : ''));
                break;
            case 'retirar':
                $servico->retirarItem($link, $nome, (int)($_POST['item_id'] ?? 0));
                coleta_flash('sucesso', 'Livro retirado do pré-cadastro. As vagas voltaram para o lote.');
                break;
            case 'carimbar':
                if (($_POST['conferi'] ?? '') !== '1') {
                    throw new AcervoException('Marque “Conferi o código escrito em cada livro marcado” antes de confirmar.');
                }
                $codigos = $servico->marcarCarimbado($link, $nome, is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []);
                coleta_flash($codigos ? 'sucesso' : 'aviso', $codigos ? count($codigos) . ' exemplar(es) marcado(s) como carimbado(s).' : 'Os exemplares marcados já estavam carimbados.');
                break;
            case 'concluir_carimbagem':
                $servico->concluirCarimbagem($link, $nome);
                coleta_flash('sucesso', 'Carimbagem do ' . $nomeLote . ' concluída. Obrigado! A administração segue com a organização nas estantes.');
                break;
            case 'pausar':
                $servico->pausar($link['lote_id'], $etapa, $nome, (string)($_POST['motivo'] ?? ''), null, $link['id']);
                coleta_flash('sucesso', 'Trabalho pausado. Quando voltarem, cliquem em “Retomar trabalho”: tudo o que já foi feito continua salvo.');
                break;
            case 'retomar':
                $servico->retomar($link['lote_id'], $etapa, $nome);
                coleta_flash('sucesso', 'Trabalho retomado.');
                break;
            case 'trocar_nome':
                unset($_SESSION['coleta_nome']);
                break;
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        coleta_flash('erro', $e->getMessage());
        if ($acao === 'salvar' && isset($dados)) {
            $_SESSION['coleta_form'] = ['valores' => $_POST, 'erros' => [], 'item_id' => $itemId ?? null];
        }
    } catch (PDOException $e) {
        error_log('[biblioteca] coleta: ' . $e->getMessage());
        coleta_flash('erro', 'Não foi possível gravar agora. Tente de novo em instantes.');
        if ($acao === 'salvar') {
            $_SESSION['coleta_form'] = ['valores' => $_POST, 'erros' => [], 'item_id' => $itemId ?? null];
        }
    }
    coleta_redirecionar($voltar);
}

// ---------------------------------------------------------------- página
$csrf = '<input type="hidden" name="csrf" value="' . e($_SESSION['coleta_csrf']) . '">';
$flash = $_SESSION['coleta_flash'] ?? null;
unset($_SESSION['coleta_flash']);
coleta_inicio($etapa === 'coleta' ? 'Pré-cadastro dos livros' : 'Carimbagem', $link);

if ($flash) {
    $tom = ['sucesso' => 'green', 'aviso' => 'amber', 'erro' => 'red'][$flash[0]] ?? 'blue';
    echo '<div class="adm-notice adm-notice--' . $tom . '" role="status">' . icon($flash[0] === 'erro' ? 'triangle-alert' : 'circle-check') . '<p>' . e($flash[1]) . '</p></div>';
}

if ($nome === '') {
    ?>
    <form class="adm-card adm-panel adm-form-section" method="post">
        <?= $csrf ?><input type="hidden" name="acao" value="nome">
        <div class="adm-form-section__header"><div>
            <h1><?= e($link['equipe']) ?> — <?= e($nomeLote) ?></h1>
            <p><?= $etapa === 'coleta' ? 'Pré-cadastro dos livros deste lote.' : 'Carimbagem dos livros deste lote (Planilha BDP).' ?> Digite o seu nome para começar: ele fica registrado em cada livro que você enviar.</p>
        </div></div>
        <label class="adm-field"><span class="adm-field__label">Seu nome</span><input class="adm-input" type="text" name="nome" maxlength="100" required autocomplete="name" autofocus></label>
        <div class="adm-form-actions adm-form-actions--static"><button class="adm-button adm-button--primary" type="submit"><?= icon('arrow-right') ?> Começar</button></div>
        <p class="adm-field__hint">Este link vale até <?= e(date('d/m/Y', strtotime($link['expira_em']))) ?>. Não compartilhe com quem não é da equipe.</p>
    </form>
    <?php
    coleta_fim();
    exit;
}

$pausa = $servico->pausaAberta($link['lote_id'], $etapa);
?>
<section class="adm-card adm-panel">
    <div class="coleta-acoes" style="justify-content: space-between">
        <div><h1 style="margin:0"><?= e($nomeLote) ?> · <?= e($link['equipe']) ?></h1>
            <p class="adm-table__muted" style="margin:4px 0 0">Você: <strong><?= e($nome) ?></strong> · <?= e(ExemplarService::rotuloStatusLote($lote['status'])) ?> · link válido até <?= e(date('d/m/Y', strtotime($link['expira_em']))) ?></p></div>
        <form method="post" class="coleta-nao-imprimir"><?= $csrf ?><input type="hidden" name="acao" value="trocar_nome"><button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('user-round') ?> Não sou <?= e($nome) ?></button></form>
    </div>
</section>

<?php if ($pausa): ?>
    <section class="adm-card adm-panel coleta-aviso-pausa">
        <h2><?= icon('pause') ?> Trabalho pausado</h2>
        <p>Pausado por <strong><?= e($pausa['pausado_por']) ?></strong> em <?= e(date('d/m/Y H:i', strtotime($pausa['pausado_em']))) ?><?= $pausa['motivo'] ? ' · ' . e($pausa['motivo']) : '' ?>.</p>
        <p><strong>Onde parou:</strong> <?= e($pausa['onde_parou']) ?></p>
        <form method="post"><?= $csrf ?><input type="hidden" name="acao" value="retomar"><button class="adm-button adm-button--primary" type="submit"><?= icon('play') ?> Retomar trabalho</button></form>
    </section>
<?php
    coleta_fim();
    exit;
endif;

if ($etapa === 'coleta'):
    $vagas = $servico->vagas($link['lote_id']);
    $form = $_SESSION['coleta_form'] ?? null;
    unset($_SESSION['coleta_form']);
    $editarId = (int)($_GET['editar'] ?? 0);
    $valores = $form['valores'] ?? [];
    $erros = $form['erros'] ?? [];
    $itemEdit = $form['item_id'] ?? null;
    if (!$form && $editarId) {
        $it = $servico->item($editarId);
        if ($it && (int)$it['lote_id'] === $link['lote_id'] && $it['status'] === 'pendente') {
            $itemEdit = (int)$it['id'];
            $valores = $it + ['tem_isbn' => (string)$it['tem_isbn'], 'tem_cdd' => (string)$it['tem_cdd']];
            $valores['tem_isbn'] = (string)(int)$it['tem_isbn'];
            $valores['tem_cdd'] = (string)(int)$it['tem_cdd'];
        }
    }
    $v = fn (string $c) => e((string)($valores[$c] ?? ''));
    $err = fn (string $c) => isset($erros[$c]) ? '<span class="adm-field__error">' . e($erros[$c]) . '</span>' : '';
    $inv = fn (string $c) => isset($erros[$c]) ? ' is-invalid' : '';
    $marcado = fn (string $c, string $valor) => (string)($valores[$c] ?? '') === $valor ? ' checked' : '';
    $itens = $servico->itens($link['lote_id']);
    $aberto = $lote['status'] === 'aberto';
    ?>
    <dl class="coleta-vagas">
        <div><dt>Capacidade do lote</dt><dd><?= (int)$vagas['capacidade'] ?></dd></div>
        <div><dt>Exemplares já anotados</dt><dd><?= (int)($vagas['registrados'] + $vagas['reservados']) ?></dd></div>
        <div><dt>Vagas restantes</dt><dd><?= (int)$vagas['restantes'] ?></dd></div>
        <div><dt>Livros enviados</dt><dd><?= count($itens) ?></dd></div>
    </dl>

    <?php if (!$aberto): ?>
        <div class="adm-notice adm-notice--blue" role="status"><?= icon('info') ?><p><?= e($nomeLote) ?> não recebe mais livros (<?= e(ExemplarService::rotuloStatusLote($lote['status'])) ?>). Obrigado pelo trabalho!</p></div>
    <?php elseif ($vagas['restantes'] <= 0 && !$itemEdit): ?>
        <div class="adm-notice adm-notice--green" role="status"><?= icon('circle-check') ?><p>Todas as vagas do lote foram anotadas. Confira a lista abaixo; se algo estiver errado, use “Corrigir”.</p></div>
    <?php else: ?>
    <form class="adm-card adm-panel adm-form-section" method="post" novalidate>
        <?= $csrf ?><input type="hidden" name="acao" value="salvar"><?php if ($itemEdit): ?><input type="hidden" name="item_id" value="<?= (int)$itemEdit ?>"><?php endif; ?>
        <div class="adm-form-section__header"><div>
            <h2><?= $itemEdit ? 'Corrigir livro' : 'Novo livro' ?></h2>
            <p>Copie da <strong>página de créditos</strong> (verso da folha de rosto): título, autor, editora, ano, ISBN e CDD (na ficha catalográfica). Campos com * são obrigatórios.</p>
        </div></div>
        <div class="adm-form-grid">
            <label class="adm-field adm-field--full<?= $inv('titulo') ?>"><span class="adm-field__label">Título *</span><input class="adm-input" type="text" name="titulo" maxlength="255" required value="<?= $v('titulo') ?>"><?= $err('titulo') ?></label>
            <label class="adm-field adm-field--full"><span class="adm-field__label">Subtítulo</span><input class="adm-input" type="text" name="subtitulo" maxlength="255" value="<?= $v('subtitulo') ?>"></label>
            <div class="adm-field<?= $inv('autor') ?>"><label class="adm-field__label" for="c-autor">Autor *</label>
                <input class="adm-input" id="c-autor" type="text" name="autor" maxlength="500" value="<?= $v('autor') ?>" placeholder="SOBRENOME, Nome (vários: separe com ;)">
                <label class="adm-check"><input type="checkbox" name="sem_autor" value="1" data-desliga="c-autor"<?= $marcado('sem_autor', '1') ?>> Sem autor indicado</label><?= $err('autor') ?></div>
            <div class="adm-field<?= $inv('editora') ?>"><label class="adm-field__label" for="c-editora">Editora *</label>
                <input class="adm-input" id="c-editora" type="text" name="editora" maxlength="150" value="<?= $v('editora') ?>">
                <label class="adm-check"><input type="checkbox" name="sem_editora" value="1" data-desliga="c-editora"<?= $marcado('sem_editora', '1') ?>> Sem editora indicada</label><?= $err('editora') ?></div>
            <div class="adm-field<?= $inv('ano') ?>"><label class="adm-field__label" for="c-ano">Ano *</label>
                <input class="adm-input" id="c-ano" type="text" name="ano" inputmode="numeric" maxlength="4" value="<?= $v('ano') ?>" placeholder="Ex.: 2019">
                <label class="adm-check"><input type="checkbox" name="sem_ano" value="1" data-desliga="c-ano"<?= $marcado('sem_ano', '1') ?>> Sem data no livro</label><?= $err('ano') ?></div>
            <label class="adm-field<?= $inv('quantidade') ?>"><span class="adm-field__label">Quantos exemplares iguais? *</span>
                <input class="adm-input" type="number" name="quantidade" min="1" max="<?= max(1, min(ExemplarService::LIMITE_EM_QUANTIDADE, (int)$vagas['restantes'] + (int)($itemEdit ? ($valores['quantidade'] ?? 0) : 0))) ?>" value="<?= $v('quantidade') ?: '1' ?>" required>
                <span class="adm-field__hint">Cada exemplar ocupa uma vaga do lote.</span><?= $err('quantidade') ?></label>

            <fieldset class="adm-field<?= $inv('tem_isbn') ?>"><legend class="adm-field__label">Tem ISBN? *</legend>
                <div class="coleta-escolha"><label><input type="radio" name="tem_isbn" value="1"<?= $marcado('tem_isbn', '1') ?>> Sim</label><label><input type="radio" name="tem_isbn" value="0"<?= $marcado('tem_isbn', '0') ?>> Não</label></div><?= $err('tem_isbn') ?></fieldset>
            <div class="adm-field<?= $inv('isbn') ?>" data-mostra-se="tem_isbn=1"><label class="adm-field__label" for="c-isbn">ISBN (só os números) *</label>
                <input class="adm-input" id="c-isbn" type="text" name="isbn" inputmode="numeric" maxlength="20" value="<?= $v('isbn') ?>" data-isbn="c-isbn-aviso" autocomplete="off"><span id="c-isbn-aviso" class="adm-field__hint" aria-live="polite"></span><?= $err('isbn') ?></div>

            <fieldset class="adm-field<?= $inv('tem_cdd') ?>"><legend class="adm-field__label">Tem CDD? *</legend>
                <div class="coleta-escolha"><label><input type="radio" name="tem_cdd" value="1"<?= $marcado('tem_cdd', '1') ?>> Sim</label><label><input type="radio" name="tem_cdd" value="0"<?= $marcado('tem_cdd', '0') ?>> Não</label></div><?= $err('tem_cdd') ?></fieldset>
            <div class="adm-field<?= $inv('cdd') ?>" data-mostra-se="tem_cdd=1"><label class="adm-field__label" for="c-cdd">CDD *</label>
                <input class="adm-input" id="c-cdd" type="text" name="cdd" maxlength="30" value="<?= $v('cdd') ?>" placeholder="Ex.: 869.3"><?= $err('cdd') ?></div>
        </div>
        <details<?= ($valores['edicao'] ?? '') . ($valores['assunto'] ?? '') . ($valores['cod_autor'] ?? '') . ($valores['observacoes'] ?? '') !== '' ? ' open' : '' ?>><summary>Informações complementares (opcionais)</summary>
            <div class="adm-form-grid">
                <label class="adm-field"><span class="adm-field__label">Edição</span><input class="adm-input" type="text" name="edicao" maxlength="60" value="<?= $v('edicao') ?>" placeholder="Ex.: 2ª edição"></label>
                <label class="adm-field"><span class="adm-field__label">Assunto</span><input class="adm-input" type="text" name="assunto" maxlength="255" value="<?= $v('assunto') ?>"></label>
                <label class="adm-field"><span class="adm-field__label">Código do autor (Cutter)</span><input class="adm-input" type="text" name="cod_autor" maxlength="30" value="<?= $v('cod_autor') ?>" placeholder="Ex.: A848d"></label>
                <label class="adm-field"><span class="adm-field__label">Observações</span><input class="adm-input" type="text" name="observacoes" maxlength="500" value="<?= $v('observacoes') ?>" placeholder="Ex.: capa solta, páginas faltando"></label>
            </div>
        </details>
        <div class="adm-form-actions adm-form-actions--static">
            <?php if ($itemEdit): ?><a class="adm-button adm-button--ghost" href="<?= e($base) ?>">Cancelar correção</a><?php endif; ?>
            <button class="adm-button adm-button--primary" type="submit"><?= icon('check') ?> <?= $itemEdit ? 'Salvar correção' : 'Salvar livro' ?></button>
        </div>
    </form>
    <?php endif; ?>

    <section class="adm-card adm-panel">
        <div class="adm-panel__header"><div><h2>Livros deste lote</h2><p>Os aprovados já receberam o código BDP. Os pendentes ainda podem ser corrigidos.</p></div></div>
        <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-table--cards coleta-tabela">
            <thead><tr><th scope="col">Nº</th><th scope="col">Título</th><th scope="col">Autor</th><th scope="col">Qtd.</th><th scope="col">Situação</th><th scope="col">Por</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($itens as $it): ?>
                <tr>
                    <td data-label="Nº"><strong><?= (int)$it['numero'] ?></strong></td>
                    <td data-label="Título"><?= e($it['titulo']) ?></td>
                    <td data-label="Autor"><?= e($it['autor'] ?: 'Sem autor indicado') ?></td>
                    <td data-label="Qtd."><?= (int)$it['quantidade'] ?></td>
                    <td data-label="Situação"><?= $it['status'] === 'aprovado' ? '<span class="adm-status adm-tone--green">Aprovado</span>' : '<span class="adm-status adm-tone--amber">Aguardando revisão</span>' ?></td>
                    <td data-label="Por" class="adm-table__muted"><?= e($it['coletado_por']) ?></td>
                    <td><?php if ($it['status'] === 'pendente' && $aberto): ?>
                        <div class="coleta-acoes"><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '?editar=' . (int)$it['id']) ?>"><?= icon('pencil') ?> Corrigir</a>
                        <form method="post"><?= $csrf ?><input type="hidden" name="acao" value="retirar"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit" data-confirmar="Retirar o livro nº <?= (int)$it['numero'] ?> do pré-cadastro?"><?= icon('x') ?> Retirar</button></form></div>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$itens): ?><tr><td colspan="7"><div class="adm-empty"><?= icon('book-plus') ?><strong>Nenhum livro enviado ainda</strong><span>Use o formulário acima para o primeiro livro.</span></div></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
<?php
else:
    $planilha = $servico->planilha($link['lote_id']);
    $total = count($planilha);
    $feitos = count(array_filter($planilha, fn ($x) => $x['carimbo_status'] === 'carimbado'));
    $podeCarimbar = in_array($lote['status'], ['fechado', 'em_carimbagem'], true);
    ?>
    <dl class="coleta-vagas">
        <div><dt>Exemplares no lote</dt><dd><?= $total ?></dd></div>
        <div><dt>Carimbados</dt><dd><?= $feitos ?></dd></div>
        <div><dt>Faltam</dt><dd><?= $total - $feitos ?></dd></div>
    </dl>
    <?php if ($lote['status'] === 'aberto'): ?>
        <div class="adm-notice adm-notice--blue" role="status"><?= icon('info') ?><p><?= e($nomeLote) ?> ainda está em cadastro (<?= (int)$lote['total'] ?> de <?= (int)$lote['capacidade'] ?> exemplares com código). A carimbagem começa quando o lote for fechado. A lista abaixo já mostra os códigos gerados.</p></div>
    <?php elseif (!$podeCarimbar): ?>
        <div class="adm-notice adm-notice--green" role="status"><?= icon('circle-check') ?><p>A carimbagem deste lote foi concluída (<?= e(ExemplarService::rotuloStatusLote($lote['status'])) ?>).</p></div>
    <?php endif; ?>

    <form class="adm-card adm-panel" method="post">
        <?= $csrf ?><input type="hidden" name="acao" value="carimbar">
        <div class="adm-panel__header"><div><h2>Planilha BDP — <?= e($nomeLote) ?></h2><p>Escreva o código no carimbo de cada livro e marque aqui. Confira o código antes de marcar.</p></div>
            <div class="coleta-acoes coleta-nao-imprimir"><button class="adm-button adm-button--ghost adm-button--sm" type="button" data-imprimir><?= icon('printer') ?> Imprimir</button></div></div>
        <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table coleta-tabela">
            <thead><tr><th scope="col" class="coleta-nao-imprimir"><?php if ($podeCarimbar): ?><input type="checkbox" data-marcar-todos aria-label="Marcar todos"><?php endif; ?></th><th scope="col">Ordem</th><th scope="col">Código BDP</th><th scope="col">Título</th><th scope="col">Autor</th><th scope="col">Carimbo</th></tr></thead>
            <tbody>
            <?php foreach ($planilha as $x): $ok = $x['carimbo_status'] === 'carimbado'; ?>
                <tr>
                    <td class="coleta-nao-imprimir"><?php if ($podeCarimbar && !$ok): ?><input type="checkbox" name="ids[]" value="<?= (int)$x['id'] ?>" data-marcavel aria-label="Marcar <?= e($x['codigo_individual']) ?>"><?php endif; ?></td>
                    <td><?= (int)$x['lote_ordem'] ?></td>
                    <td class="coleta-codigo"><?= e($x['codigo_individual']) ?></td>
                    <td><?= e($x['titulo']) ?></td>
                    <td><?= e((string)$x['autores']) ?></td>
                    <td><?= $ok ? '<span class="adm-status adm-tone--green">Carimbado</span>' . ($x['carimbado_por_nome'] ? ' <small>' . e($x['carimbado_por_nome']) . '</small>' : '') : '<span class="adm-status adm-tone--amber">Falta carimbar</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$planilha): ?><tr><td colspan="6"><div class="adm-empty"><?= icon('stamp') ?><strong>Nenhum exemplar com código ainda</strong><span>Os códigos aparecem aqui quando a administração aprova os livros do lote.</span></div></td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($podeCarimbar && $feitos < $total): ?>
            <div class="adm-form-actions adm-form-actions--static coleta-nao-imprimir">
                <label class="adm-check"><input type="checkbox" name="conferi" value="1"> Conferi o código escrito em cada livro marcado</label>
                <button class="adm-button adm-button--primary" type="submit"><?= icon('stamp') ?> Marcar como carimbados</button>
            </div>
        <?php endif; ?>
    </form>
    <?php if ($podeCarimbar && $total > 0 && $feitos === $total): ?>
        <form class="adm-card adm-panel coleta-nao-imprimir" method="post"><?= $csrf ?><input type="hidden" name="acao" value="concluir_carimbagem">
            <p><strong>Todos os <?= $total ?> exemplares estão carimbados.</strong> Concluir a carimbagem fecha esta etapa do lote.</p>
            <button class="adm-button adm-button--primary" type="submit" data-confirmar="Concluir a carimbagem do <?= e($nomeLote) ?>?"><?= icon('circle-check') ?> Concluir carimbagem do lote</button>
        </form>
    <?php endif; ?>
<?php
endif;
?>
<details class="adm-card adm-panel coleta-nao-imprimir">
    <summary><?= icon('pause') ?> Precisa parar antes de terminar?</summary>
    <form method="post" class="adm-inline-form"><?= $csrf ?><input type="hidden" name="acao" value="pausar">
        <input class="adm-input" type="text" name="motivo" maxlength="255" placeholder="Motivo (ex.: fim da aula)" aria-label="Motivo da pausa">
        <button class="adm-button adm-button--ghost" type="submit"><?= icon('pause') ?> Pausar trabalho</button></form>
    <p class="adm-field__hint">Tudo o que já foi salvo continua guardado. A pausa registra onde vocês pararam, e quem voltar clica em “Retomar trabalho”.</p>
</details>
<?php
coleta_fim();
