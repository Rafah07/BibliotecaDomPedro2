<?php
/**
 * Importação local de alunos: leitura da planilha enviada, análise e gravação.
 *
 * Privacidade (a planilha real tem dados pessoais de estudantes, possivelmente menores):
 *   - O arquivo NÃO é guardado. Cada etapa reenvia o arquivo escolhido no navegador; o PHP o lê
 *     direto do arquivo temporário do upload, que ele mesmo apaga ao fim da requisição.
 *   - Só as colunas mapeadas são lidas para análise; as ignoradas são descartadas.
 *   - Nada vai para logs, URLs ou registros de ação além de contagens. Erros técnicos registram
 *     apenas o tipo do erro, nunca a mensagem (mensagens do MySQL podem conter valores das linhas).
 *   - Nenhum serviço externo é chamado.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/icones.php';

use Biblioteca\Repositories\AlunoRepository;
use Biblioteca\Services\AlunoImportService;
use Biblioteca\Services\ExcelImportService;

/** Situações de cada linha na prévia. */
const ALUNOS_IMPORTACAO_SITUACOES = [
    'novo' => ['label' => 'Novo aluno', 'tom' => 'green'],
    'existente' => ['label' => 'Já cadastrado, com diferenças', 'tom' => 'blue'],
    'igual' => ['label' => 'Já cadastrado, sem alterações', 'tom' => 'muted'],
    'duplicidade' => ['label' => 'Possível duplicidade', 'tom' => 'indigo'],
    'erro' => ['label' => 'Erro', 'tom' => 'red'],
];

final class AlunosImportacaoConflito extends RuntimeException
{
}

function alunos_importacao_servico(): AlunoImportService
{
    return new AlunoImportService(env('ALUNOS_EMAIL_DOMINIO'));
}

function alunos_repo(): AlunoRepository
{
    static $r = null;
    return $r ??= new AlunoRepository(db());
}

/**
 * Preparação da API: banco real, usuário com a permissão alunos.importar, POST e token CSRF.
 * No modo demonstrativo (sem MySQL) nada é lido nem gravado.
 */
function alunos_importacao_api(bool $exigePost = true): array
{
    if (db_modo() !== 'mysql') {
        api_resposta(['ok' => false, 'erro' => 'Configure o banco MySQL para importar alunos. No modo demonstrativo nenhum arquivo é lido.'], 409);
    }
    try {
        db();
    } catch (DbIndisponivel $e) {
        api_resposta(['ok' => false, 'erro' => $e->getMessage()], 503);
    }
    $usuario = auth_exigir('alunos.importar', true);
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($exigePost && !$post) {
        api_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
    }
    // Envio maior que o post_max_size do PHP: o PHP descarta o formulário inteiro.
    if ($post && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        api_resposta(['ok' => false, 'erro' => 'O arquivo é maior do que o servidor permite. O limite da importação é 5 MB.'], 413);
    }
    if ($post && !csrf_valido()) {
        api_resposta(['ok' => false, 'erro' => 'A página ficou aberta por muito tempo. Recarregue e tente novamente.'], 419);
    }
    return $usuario;
}

/** Erro inesperado: registra só o tipo e o local do erro (sem dados de alunos) e responde com mensagem genérica. */
function alunos_importacao_falha(Throwable $e): never
{
    if ($e instanceof AlunosImportacaoConflito) {
        api_resposta(['ok' => false, 'erro' => $e->getMessage(), 'refazer_previa' => true], 409);
    }
    if ($e instanceof \PhpOffice\PhpSpreadsheet\Exception || $e instanceof \PhpOffice\PhpSpreadsheet\Reader\Exception) {
        api_resposta(['ok' => false, 'erro' => 'Não foi possível ler a planilha. Abra-a no Excel, salve novamente e tente outra vez.'], 422);
    }
    if ($e instanceof RuntimeException && !$e instanceof PDOException && !$e instanceof DbIndisponivel) {
        api_resposta(['ok' => false, 'erro' => $e->getMessage()], 422);
    }
    $codigo = $e instanceof PDOException ? ' SQLSTATE ' . $e->getCode() : '';
    error_log('[biblioteca] Erro na importação de alunos: ' . get_class($e) . $codigo . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    api_resposta(['ok' => false, 'erro' => 'Não foi possível concluir a operação. Nenhum aluno foi gravado. Tente novamente; se o problema continuar, avise o responsável pelo sistema.'], 500);
}

/**
 * Arquivo enviado nesta requisição, validado (tipo, tamanho, conteúdo). Fica só no temporário do PHP.
 * @return array{caminho: string, tipo: string, hash: string, nome: string, tamanho: int}
 */
function alunos_importacao_arquivo(): array
{
    $arquivo = $_FILES['arquivo'] ?? null;
    if (!is_array($arquivo) || is_array($arquivo['error'] ?? null)) {
        throw new RuntimeException('Selecione um arquivo.');
    }
    $mensagens = [
        UPLOAD_ERR_INI_SIZE => 'O arquivo é maior do que o servidor permite.',
        UPLOAD_ERR_FORM_SIZE => 'O arquivo é maior do que o permitido.',
        UPLOAD_ERR_PARTIAL => 'O envio foi interrompido. Tente novamente.',
        UPLOAD_ERR_NO_FILE => 'Selecione um arquivo.',
    ];
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException($mensagens[$arquivo['error']] ?? 'Não foi possível receber o arquivo.');
    }
    if (!is_uploaded_file($arquivo['tmp_name'])) {
        throw new RuntimeException('Arquivo inválido.');
    }
    $tamanho = (int)filesize($arquivo['tmp_name']);
    if ($tamanho > AlunoImportService::TAMANHO_MAXIMO) {
        throw new RuntimeException('O arquivo passa de 5 MB. Remova colunas e abas que não serão usadas ou divida a planilha.');
    }
    $nome = mb_substr(basename(str_replace('\\', '/', (string)$arquivo['name'])), 0, 255);
    $tipo = (new ExcelImportService())->validarArquivo($arquivo['tmp_name'], $nome);
    return ['caminho' => $arquivo['tmp_name'], 'tipo' => $tipo, 'hash' => hash_file('sha256', $arquivo['tmp_name']), 'nome' => $nome, 'tamanho' => $tamanho];
}

/** Abas da planilha e a aba escolhida (a primeira com dados, se nenhuma for indicada). */
function alunos_importacao_abas(array $arquivo, ?string $aba): array
{
    $abas = (new ExcelImportService())->abas($arquivo['caminho'], $arquivo['tipo']);
    $nomes = array_column($abas, 'nome');
    if ($aba !== null && $aba !== '' && !in_array($aba, $nomes, true)) {
        throw new RuntimeException('A aba selecionada não existe nesta planilha.');
    }
    if ($aba === null || $aba === '') {
        foreach ($abas as $a) {
            if ($a['linhas'] > 1) {
                $aba = $a['nome'];
                break;
            }
        }
        $aba ??= $nomes[0] ?? 'CSV';
    }
    $info = $abas[array_search($aba, $nomes, true)] ?? ['linhas' => 0];
    return [$abas, $aba, (int)$info['linhas']];
}

/** Colunas da aba com até 2 exemplos de valores (só exibidos na tela) e o campo sugerido. */
function alunos_importacao_colunas(array $arquivo, ?string $aba, int $linhaCabecalho): array
{
    [$abas, $aba, $linhas] = alunos_importacao_abas($arquivo, $aba);
    $leitor = new ExcelImportService();
    $abaLeitura = $arquivo['tipo'] === 'csv' ? null : $aba;
    $cabecalhos = $leitor->cabecalhos($arquivo['caminho'], $arquivo['tipo'], $abaLeitura, $linhaCabecalho);
    if (!$cabecalhos) {
        throw new RuntimeException('Não encontramos cabeçalhos na linha ' . $linhaCabecalho . ' desta aba. Escolha outra aba ou outra linha de cabeçalho.');
    }
    $amostra = $leitor->lerLinhas($arquivo['caminho'], $arquivo['tipo'], $abaLeitura, $linhaCabecalho + 1, 6, null);
    $sugestao = alunos_importacao_servico()->sugerirMapeamento($cabecalhos);
    $colunas = [];
    foreach ($cabecalhos as $letra => $titulo) {
        $exemplos = [];
        foreach ($amostra as $linha) {
            $v = trim((string)($linha[$letra] ?? ''));
            if ($v !== '' && count($exemplos) < 2) {
                $exemplos[] = mb_strimwidth($v, 0, 40, '…');
            }
        }
        $colunas[] = ['letra' => $letra, 'cabecalho' => $titulo, 'exemplos' => $exemplos, 'campo' => $sugestao[$letra]];
    }
    return [
        'abas' => array_map(fn ($a) => ['nome' => $a['nome'], 'linhas' => $a['linhas']], $abas),
        'aba' => $aba,
        'linha_cabecalho' => $linhaCabecalho,
        'linhas' => max(0, $linhas - $linhaCabecalho),
        'colunas' => $colunas,
    ];
}

/**
 * Lê as linhas (só colunas mapeadas), valida e monta o plano contra o banco atual.
 * @param array{aba: ?string, linha_cabecalho: int, mapeamento: array, modo: string, importar_duplicidades: bool} $params
 */
function alunos_importacao_analisar(array $arquivo, array $params, bool $bloquear = false): array
{
    [, $aba, $linhas] = alunos_importacao_abas($arquivo, $params['aba']);
    $linhaCabecalho = $params['linha_cabecalho'];
    if ($linhas - $linhaCabecalho > AlunoImportService::MAX_LINHAS) {
        throw new RuntimeException('A aba tem mais de ' . number_format(AlunoImportService::MAX_LINHAS, 0, ',', '.') . ' linhas. Divida a planilha em partes menores (por exemplo, por turma).');
    }
    $leitor = new ExcelImportService();
    $abaLeitura = $arquivo['tipo'] === 'csv' ? null : $aba;
    $cabecalhos = $leitor->cabecalhos($arquivo['caminho'], $arquivo['tipo'], $abaLeitura, $linhaCabecalho);
    $servico = alunos_importacao_servico();
    if ($problema = $servico->problemaNoMapeamento($params['mapeamento'], $cabecalhos)) {
        throw new RuntimeException($problema);
    }
    $brutas = $leitor->lerLinhas($arquivo['caminho'], $arquivo['tipo'], $abaLeitura, $linhaCabecalho + 1, AlunoImportService::MAX_LINHAS + 1, null);
    $usadas = array_filter($params['mapeamento'], fn ($c) => $c !== 'ignorar');
    // Descarta já aqui o conteúdo das colunas ignoradas.
    $brutas = array_map(fn ($l) => array_intersect_key($l, $usadas), $brutas);
    $normalizadas = $servico->normalizar($brutas, $params['mapeamento']);
    unset($brutas);
    if (!$normalizadas) {
        throw new RuntimeException('Nenhum aluno encontrado nas colunas escolhidas. Confira a aba, a linha do cabeçalho e o mapeamento.');
    }

    $repo = alunos_repo();
    $dados = array_column($normalizadas, 'dados');
    $plano = $servico->planejar(
        $normalizadas,
        $repo->porMatriculas(array_column($dados, 'matricula'), $bloquear),
        $repo->porNomes(array_column($dados, 'nome')),
        $repo->porEmails(array_column($dados, 'email')),
        ['modo' => $params['modo'], 'importar_duplicidades' => $params['importar_duplicidades']]
    );
    $plano['aba'] = $aba;
    return $plano;
}

/** Parâmetros da análise vindos do formulário, já conferidos. */
function alunos_importacao_parametros(array $origem): array
{
    $mapeamento = json_decode(is_string($origem['mapeamento'] ?? null) ? $origem['mapeamento'] : '', true);
    if (!is_array($mapeamento) || !$mapeamento || count($mapeamento) > 60) {
        throw new RuntimeException('Escolha o campo de cada coluna antes de continuar.');
    }
    $modo = ($origem['modo'] ?? '') === 'atualizar' ? 'atualizar' : 'novos';
    return [
        'aba' => is_string($origem['aba'] ?? null) ? mb_substr($origem['aba'], 0, 120) : null,
        'linha_cabecalho' => max(1, min(50, (int)($origem['linha_cabecalho'] ?? 1))),
        'mapeamento' => $mapeamento,
        'modo' => $modo,
        'importar_duplicidades' => ($origem['importar_duplicidades'] ?? '') === '1',
    ];
}

/** Chave da assinatura da prévia, ligada à sessão de quem a gerou. */
function alunos_importacao_chave(): string
{
    return hash('sha256', 'alunos-importacao|' . csrf_token());
}

/** Motivo, para o relatório, de uma linha que não será gravada. */
function alunos_importacao_motivo(array $item): string
{
    return match ($item['situacao']) {
        'erro' => implode(' ', $item['erros']),
        'igual' => 'Já cadastrado, sem alterações.',
        'existente' => 'Já cadastrado. Para alterar o cadastro, use a opção de atualização.',
        'duplicidade' => 'Possível duplicidade: ' . implode(' ', $item['avisos']),
        default => '',
    };
}

/**
 * Grava o plano numa única transação: ou entram todas as linhas aprovadas, ou nenhuma.
 * O plano é recalculado com os cadastros travados e precisa ser idêntico ao da prévia.
 * @return array{plano: array, incluidos: int, atualizados: int}
 */
function alunos_importacao_gravar(array $arquivo, array $params, string $assinatura, bool $confirmouAtualizacao): array
{
    try {
        return db_transacao(function () use ($arquivo, $params, $assinatura, $confirmouAtualizacao) {
            $plano = alunos_importacao_analisar($arquivo, $params, true);
            if (!hash_equals(AlunoImportService::assinatura($plano, $arquivo['hash'], alunos_importacao_chave()), $assinatura)) {
                throw new AlunosImportacaoConflito('O arquivo, as opções ou os cadastros mudaram desde a prévia. Gere a prévia novamente antes de importar.');
            }
            if (!$plano['acoes']) {
                throw new RuntimeException('Não há alunos para incluir ou atualizar com as opções escolhidas.');
            }
            if ($plano['resumo']['atualizar'] > 0 && !$confirmouAtualizacao) {
                throw new RuntimeException('Confirme que revisou as alterações nos cadastros existentes antes de atualizar.');
            }
            $repo = alunos_repo();
            $incluidos = 0;
            $atualizados = 0;
            foreach ($plano['acoes'] as $acao) {
                if ($acao[0] === 'incluir') {
                    $repo->incluir($acao[1]);
                    $incluidos++;
                } else {
                    $repo->atualizar($acao[1], $acao[2]);
                    $atualizados++;
                }
            }
            return ['plano' => $plano, 'incluidos' => $incluidos, 'atualizados' => $atualizados];
        });
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new AlunosImportacaoConflito('Uma das matrículas foi cadastrada por outra pessoa durante a importação. Nada foi gravado. Gere a prévia novamente.');
        }
        throw $e;
    }
}

/** Itens da prévia como serão enviados ao navegador. */
function alunos_importacao_itens_previa(array $plano): array
{
    return array_map(fn ($i) => [
        'linha' => $i['linha'],
        'situacao' => $i['situacao'],
        'acao' => $i['acao'],
        'dados' => $i['dados'],
        'atual' => $i['atual'],
        'alteracoes' => $i['alteracoes'] ?: null,
        'erros' => $i['erros'],
        'avisos' => $i['avisos'],
    ], $plano['itens']);
}
