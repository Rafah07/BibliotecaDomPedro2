<?php
/**
 * Módulo de importação das planilhas (Etapa 3): carregamento das classes,
 * área privada de arquivos, rótulos e registro de ações.
 *
 * As classes ficam em src/ (namespace Biblioteca\) e são carregadas por config/app.php:
 * com o Composer instalado (composer install, necessário para ler .xlsx com o
 * PhpSpreadsheet), pelo autoload de vendor/; sem ele, por um carregador simples,
 * e os arquivos .csv continuam funcionando.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/icones.php';

use Biblioteca\Repositories\ImportacaoRepository;
use Biblioteca\Services\ExcelImportService;
use Biblioteca\Services\ImportValidationService;

const IMPORTACAO_STATUS = [
    'enviada' => ['label' => 'Arquivo enviado', 'tom' => 'blue', 'etapa' => 2],
    'em_analise' => ['label' => 'Em análise', 'tom' => 'blue', 'etapa' => 3],
    'em_revisao' => ['label' => 'Em revisão', 'tom' => 'amber', 'etapa' => 4],
    'importando' => ['label' => 'Gravando no acervo', 'tom' => 'indigo', 'etapa' => 5],
    'parcial' => ['label' => 'Concluída com pendências', 'tom' => 'amber', 'etapa' => 6],
    'concluida' => ['label' => 'Concluída', 'tom' => 'green', 'etapa' => 6],
    'cancelada' => ['label' => 'Cancelada', 'tom' => 'muted', 'etapa' => 0],
    'falhou' => ['label' => 'Falhou', 'tom' => 'red', 'etapa' => 0],
];

const IMPORTACAO_SITUACOES = [
    'valido' => ['label' => 'Válido', 'tom' => 'green', 'icone' => 'circle-check'],
    'aviso' => ['label' => 'Aviso', 'tom' => 'amber', 'icone' => 'triangle-alert'],
    'erro' => ['label' => 'Erro', 'tom' => 'red', 'icone' => 'circle-alert'],
    'duplicidade' => ['label' => 'Possível duplicidade', 'tom' => 'indigo', 'icone' => 'copy'],
];

const IMPORTACAO_ACOES = [
    'criar' => ['label' => 'Criar novo livro', 'tom' => 'green'],
    'vincular' => ['label' => 'Vincular a livro existente', 'tom' => 'blue'],
    'outro_exemplar' => ['label' => 'Outro exemplar', 'tom' => 'teal'],
    'ignorar' => ['label' => 'Ignorar', 'tom' => 'muted'],
    'revisar_depois' => ['label' => 'Revisar depois', 'tom' => 'amber'],
];

const IMPORTACAO_RESULTADOS = [
    'criado' => ['label' => 'Livro criado', 'tom' => 'green'],
    'vinculado' => ['label' => 'Vinculado', 'tom' => 'blue'],
    'outro_exemplar' => ['label' => 'Outro exemplar registrado', 'tom' => 'teal'],
    'falhou' => ['label' => 'Falhou ao gravar', 'tom' => 'red'],
];

/** Pasta privada dos arquivos enviados (fora de admin/; em produção, de preferência fora da pasta pública). */
function importacao_pasta(): string
{
    $pasta = app_config()['armazenamento_importacoes'] ?? (dirname(__DIR__, 2) . '/storage/importacoes');
    if (!is_dir($pasta) && !mkdir($pasta, 0750, true) && !is_dir($pasta)) {
        throw new RuntimeException('Não foi possível preparar a pasta de arquivos da importação.');
    }
    return rtrim($pasta, '/\\');
}

function importacao_arquivo(array $importacao): string
{
    $nome = basename($importacao['arquivo_armazenado']);
    return importacao_pasta() . DIRECTORY_SEPARATOR . $nome;
}

function importacao_validador(): ImportValidationService
{
    static $v = null;
    return $v ??= new ImportValidationService();
}

function importacao_repo(): ImportacaoRepository
{
    static $r = null;
    return $r ??= new ImportacaoRepository(db(), importacao_validador());
}

function importacao_leitor(): ExcelImportService
{
    static $l = null;
    return $l ??= new ExcelImportService();
}

/**
 * Preparação comum dos endpoints da API: banco real, usuário com permissão e, em POST, token CSRF.
 * As operações reais de importação só existem com MySQL e login (nunca no modo demonstrativo).
 */
function importacao_api(string $permissao, bool $exigePost = false): array
{
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => false, 'erro' => 'Configure o banco MySQL para usar a importação. No modo demonstrativo nenhuma planilha é gravada.'], 409);
    }
    try {
        db();
    } catch (DbIndisponivel $e) {
        api_resposta(['ok' => false, 'erro' => $e->getMessage()], 503);
    }
    $usuario = auth_exigir($permissao, true);
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($exigePost && !$post) {
        api_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
    }
    if ($post && !csrf_valido()) {
        api_resposta(['ok' => false, 'erro' => 'A página ficou aberta por muito tempo. Recarregue e tente novamente.'], 419);
    }
    return $usuario;
}

/** Erro inesperado na API: registra o detalhe no log do servidor e devolve uma mensagem segura. */
function importacao_api_falha(Throwable $e): never
{
    if ($e instanceof RuntimeException && !$e instanceof PDOException && !$e instanceof DbIndisponivel) {
        api_resposta(['ok' => false, 'erro' => $e->getMessage()], 422);
    }
    error_log('[biblioteca] Erro na importação: ' . $e);
    api_resposta(['ok' => false, 'erro' => 'Não foi possível concluir a operação. Tente novamente; se o problema continuar, avise o responsável pelo sistema.'], 500);
}

function importacao_id_param(string $nome = 'id'): int
{
    $valor = $_POST[$nome] ?? $_GET[$nome] ?? 0;
    return is_scalar($valor) ? max(0, (int)$valor) : 0;
}

// ---------------------------------------------------------------
// Componentes visuais
// ---------------------------------------------------------------

function importacao_badge_status(string $status): string
{
    $s = IMPORTACAO_STATUS[$status] ?? ['label' => $status, 'tom' => 'blue'];
    return '<span class="adm-status adm-tone--' . $s['tom'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function importacao_badge_situacao(string $situacao): string
{
    $s = IMPORTACAO_SITUACOES[$situacao] ?? ['label' => $situacao, 'tom' => 'blue', 'icone' => 'info'];
    return '<span class="adm-status adm-status--soft adm-tone--' . $s['tom'] . ' adm-imp-flag">' . icon($s['icone']) . htmlspecialchars($s['label']) . '</span>';
}

function importacao_data(?string $data, bool $hora = true): string
{
    if (!$data) {
        return '—';
    }
    $d = date_create($data);
    return $d ? $d->format($hora ? 'd/m/Y H:i' : 'd/m/Y') : $data;
}

function importacao_tamanho(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

/** Etapas do assistente: 1. Enviar → 2. Mapear → 3. Validar → 4. Revisar → 5. Confirmar → 6. Concluir */
function importacao_etapas(int $atual): string
{
    $etapas = ['Enviar arquivo', 'Mapear colunas', 'Validar', 'Revisar', 'Confirmar', 'Concluir'];
    $html = '<ol class="adm-imp-steps" aria-label="Etapas da importação">';
    foreach ($etapas as $i => $nome) {
        $n = $i + 1;
        $estado = $n < $atual ? 'done' : ($n === $atual ? 'current' : 'todo');
        $html .= '<li class="adm-imp-steps__item is-' . $estado . '" data-etapa="' . $n . '"' . ($estado === 'current' ? ' aria-current="step"' : '') . '>'
            . '<span class="adm-imp-steps__num">' . ($estado === 'done' ? icon('check') : $n) . '</span>'
            . '<span class="adm-imp-steps__label">' . $nome . '</span></li>';
    }
    return $html . '</ol>';
}

/** Aviso exibido quando o MySQL ainda não está configurado ou disponível. */
function importacao_aviso_banco(?string $erro = null): string
{
    $msg = $erro ?? 'Para importar planilhas, configure o MySQL (copie .env.example para .env e preencha os dados do banco), rode "php database/migrar.php" e entre com um usuário administrativo. No modo demonstrativo nenhuma planilha é lida ou gravada.';
    return '<div class="adm-notice adm-notice--amber" role="note">' . icon('triangle-alert')
        . '<div class="adm-notice__content"><strong>Importação indisponível</strong><p>' . htmlspecialchars($msg) . '</p></div></div>';
}
