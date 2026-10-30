<?php
/**
 * Capa de um livro: envio (JPG, PNG ou WebP, até 2 MB) e remoção.
 *
 * Segurança: o tipo é conferido pelo conteúdo do arquivo (não pela extensão), a imagem é
 * regravada pelo GD quando disponível (descarta metadados e qualquer conteúdo escondido) e salva
 * em uploads/capas/ com nome aleatório. A pasta não executa scripts (uploads/capas/.htaccess).
 * O site público só exibe capas dessa pasta (CatalogoService::capaSegura).
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Services\CatalogoService;

const CAPA_MAX_BYTES = 2 * 1024 * 1024;
const CAPA_PASTA = 'uploads/capas';
const CAPA_TIPOS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

$usuario = fisico_iniciar('livros.editar');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$livro = db_query('SELECT id, titulo, capa FROM livros WHERE id = ? AND arquivado_em IS NULL', [$id])->fetch();
if (!$livro) {
    http_response_code(404);
    admin_pagina_erro('Livro não encontrado', 'O cadastro não existe ou foi arquivado.', 'book-x');
    exit;
}
$voltar = admin_url('detalhes-livro') . '?id=' . $id;
$erro = null;

/** Apaga do disco uma capa antiga enviada por esta página (nunca arquivos fora de uploads/capas). */
function capa_apagar_arquivo(?string $capa): void
{
    $segura = CatalogoService::capaSegura((string)$capa);
    if ($segura !== null && str_starts_with($segura, CAPA_PASTA . '/')) {
        $arquivo = APP_RAIZ . '/' . $segura;
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
    }
}

/** Valida e grava a imagem enviada. Devolve o caminho relativo salvo. */
function capa_salvar(array $arquivo, int $livroId): string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Escolha uma imagem para enviar.');
    }
    if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE || ($arquivo['size'] ?? 0) > CAPA_MAX_BYTES) {
        throw new RuntimeException('A imagem é maior que 2 MB. Reduza o tamanho e tente novamente.');
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        throw new RuntimeException('O envio da imagem falhou. Tente novamente.');
    }
    $info = @getimagesize($arquivo['tmp_name']);
    $tipo = $info[2] ?? null;
    if (!$info || !isset(CAPA_TIPOS[$tipo]) || $info[0] < 50 || $info[1] < 50 || $info[0] > 6000 || $info[1] > 6000) {
        throw new RuntimeException('Envie uma imagem JPG, PNG ou WebP válida (entre 50 e 6000 pixels de lado).');
    }
    $pasta = APP_RAIZ . '/' . CAPA_PASTA;
    if (!is_dir($pasta) && !mkdir($pasta, 0755, true)) {
        throw new RuntimeException('A pasta das capas não pôde ser criada. Avise o responsável pelo sistema.');
    }
    $nome = 'livro-' . $livroId . '-' . bin2hex(random_bytes(6)) . '.' . CAPA_TIPOS[$tipo];
    $destino = $pasta . '/' . $nome;

    $regravada = false;
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring((string)file_get_contents($arquivo['tmp_name']));
        if ($img !== false) {
            // Limita a 1200 px de altura: suficiente para a página do livro e leve para computadores simples.
            if (imagesy($img) > 1200) {
                $nova = imagescale($img, (int)round(imagesx($img) * 1200 / imagesy($img)), 1200);
                if ($nova !== false) {
                    imagedestroy($img);
                    $img = $nova;
                }
            }
            $regravada = match ($tipo) {
                IMAGETYPE_JPEG => imagejpeg($img, $destino, 85),
                IMAGETYPE_PNG => imagesavealpha($img, true) && imagepng($img, $destino, 8),
                IMAGETYPE_WEBP => function_exists('imagewebp') && imagewebp($img, $destino, 85),
            };
            imagedestroy($img);
        }
    }
    if (!$regravada && !move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('A imagem não pôde ser gravada. Avise o responsável pelo sistema.');
    }
    return CAPA_PASTA . '/' . $nome;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    try {
        if ($acao === 'remover') {
            db_query('UPDATE livros SET capa = NULL WHERE id = ? AND arquivado_em IS NULL', [$id]);
            capa_apagar_arquivo($livro['capa']);
            registrar_acao('livro.capa', 'livro', $id, ['acao' => 'remover']);
            admin_flash('sucesso', 'Capa removida.', 'O site passa a mostrar a capa padrão com o título.');
            admin_redirecionar($voltar);
        }
        $caminho = capa_salvar($_FILES['capa'] ?? [], $id);
        try {
            db_query('UPDATE livros SET capa = ? WHERE id = ? AND arquivado_em IS NULL', [$caminho, $id]);
        } catch (Throwable $e) {
            @unlink(APP_RAIZ . '/' . $caminho);
            throw $e;
        }
        capa_apagar_arquivo($livro['capa']);
        registrar_acao('livro.capa', 'livro', $id, ['acao' => 'enviar', 'arquivo' => basename($caminho)]);
        admin_flash('sucesso', 'Capa atualizada.', 'A nova capa já aparece no catálogo público.');
        admin_redirecionar($voltar);
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) {
            $erro = fisico_erro_tecnico($e, 'capa do livro');
        } else {
            $erro = $e->getMessage();
        }
    }
}

admin_page_start('acervo', 'Capa do livro', 'Acervo', 'Livros, exemplares e categorias da biblioteca', FISICO_CSS);
?>
<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Capa do livro']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Capa do livro</h1>
        <p><?= e($livro['titulo']) ?></p>
    </div>
    <div class="adm-page-head__actions">
        <a class="adm-button adm-button--ghost" href="<?= e($voltar) ?>"><?= icon('arrow-left') ?> Voltar ao livro</a>
    </div>
</section>

<?php if ($erro): ?><?= admin_aviso('erro', 'A capa não foi alterada.', $erro) ?><?php endif; ?>

<section class="adm-card adm-panel adm-form-section">
    <div class="adm-upload">
        <div class="adm-upload__preview"><?= acervo_capa(['titulo' => $livro['titulo'], 'capa' => $livro['capa'], 'cor' => '#143477', 'autor_principal' => ''], 'lg') ?></div>
        <p class="adm-field__hint">Use somente a foto ou a imagem da capa deste livro (JPG, PNG ou WebP, até 2 MB). Não use imagens de outros livros nem fotos de pessoas.</p>
        <form method="post" action="<?= e(admin_url('capa-livro')) ?>" enctype="multipart/form-data" data-loading-form>
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="acao" value="enviar">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= CAPA_MAX_BYTES ?>">
            <label class="adm-field"><span class="adm-field__label">Nova imagem</span><input class="adm-input" type="file" name="capa" accept="image/jpeg,image/png,image/webp" required></label>
            <button class="adm-button adm-button--primary" type="submit"><?= icon('upload') ?> Enviar capa</button>
        </form>
        <?php if (CatalogoService::capaSegura((string)$livro['capa']) !== null): ?>
            <form method="post" action="<?= e(admin_url('capa-livro')) ?>" data-confirm="Remover a capa deste livro?">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="acao" value="remover">
                <button class="adm-button adm-button--ghost adm-button--danger-text" type="submit"><?= icon('trash-2') ?> Remover capa</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php
admin_page_end(FISICO_JS);
