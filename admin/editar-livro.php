<?php
/**
 * Editar dados da obra (registro bibliográfico).
 *
 * - Os exemplares leem título, autor, ISBN etc. da obra pelo vínculo exemplares.livro_id: uma correção
 *   aparece automaticamente em todos eles (ficha, acervo, conferência, pesquisas, catálogo público).
 *   Nada físico muda: códigos BDP, localizações, lotes, carimbagem, conservação, empréstimos,
 *   ocorrências e históricos dos exemplares ficam como estão.
 * - A edição nunca é bloqueada por a obra estar verificada, ter exemplares (carimbados ou não), lotes
 *   encerrados, empréstimos ou linhas da planilha original.
 * - "Salvar e marcar dados como verificados" confirma de novo a verificação. Uma alteração relevante
 *   salva sem essa confirmação devolve a obra para "Dados não verificados" (precisa de nova conferência
 *   dos DADOS, não dos exemplares).
 * - Edição simultânea (migração 190): aviso discreto de quem mais está com a obra aberta (sem bloquear)
 *   e controle de versão no servidor: se outra pessoa salvou enquanto o formulário estava aberto, nada
 *   é sobrescrito e a página mostra a comparação, sem descartar o que foi digitado.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/livros.php';

use Biblioteca\Repositories\LivroRepository;
use Biblioteca\Services\ConflitoEdicaoException;
use Biblioteca\Services\ObraEdicaoService;

$usuario = auth_exigir('livros.editar');
$id = (int)($_GET['id'] ?? 0);
$modo = 'editar';
$erros = [];
$simulado = false;
$falha = null;
$versao = null;
$livroForm = null;
$valores = [];
$baseEdicao = [];
$conflito = null;
$outrosEditando = [];
$verificadoEm = null;

/** Valor do instantâneo (texto) de volta ao formato do formulário. */
function editar_livro_valor(string $campo, mixed $v): mixed
{
    return match ($campo) {
        'autores' => is_array($v) ? $v : [],
        'categoria' => (string)$v !== '' ? (int)$v : null,
        'isbn' => (string)$v !== '' ? (string)$v : null,
        default => (string)$v,
    };
}

/** Texto de um campo do instantâneo para a tabela de comparação. */
function editar_livro_texto(string $campo, mixed $v): string
{
    if ($campo === 'autores') {
        return implode('; ', (array)$v);
    }
    if ($campo === 'categoria') {
        return (string)$v !== '' ? (livros_categorias_ativas()[(int)$v] ?? 'Categoria nº ' . (int)$v) : '';
    }
    if ($campo === 'status_validacao') {
        return ACERVO_STATUS[$v]['label'] ?? (string)$v;
    }
    return (string)$v;
}

if (livros_persistem()) {
    $registro = livros_repo()->buscar($id);
    if ($registro && $registro['arquivado_em'] === null) {
        $contagem = db_query("SELECT COUNT(*) AS total, COALESCE(SUM(situacao = 'disponivel'), 0) AS disponiveis,
                                     COALESCE(SUM(carimbo_status = 'carimbado'), 0) AS carimbados
                              FROM exemplares WHERE livro_id = ? AND situacao NOT IN ('baixado', 'extraviado')", [$id])->fetch();
        $livroForm = [
            'id' => $id,
            'titulo' => $registro['titulo'],
            'autor_principal' => $registro['autores'][0] ?? 'Autor não informado',
            'origem_cadastro' => $registro['origem_cadastro'],
            'criado_em' => substr($registro['criado_em'], 0, 10),
            'total_exemplares' => (int)$contagem['total'],
            'disponiveis' => (int)$contagem['disponiveis'],
            'carimbados' => (int)$contagem['carimbados'],
            'cor' => acervo_mysql_cor($id),
        ];
        $valores = $registro;
        $valores['categoria'] = $registro['categorias'][0] ?? '';
        // Versão do registro (migração 190). Antes dela, a data de atualização faz o mesmo papel.
        $versao = isset($registro['versao']) ? (string)$registro['versao'] : $registro['atualizado_em'];
        $baseEdicao = livros_repo()->instantaneo($id);
        $verificadoEm = $registro['dados_verificados_em'] ?? null;
        try {
            $edicoes = new ObraEdicaoService(db());
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $edicoes->entrar($id, (int)$usuario['id'], (int)($registro['versao'] ?? 0));
            }
            $outrosEditando = $edicoes->outros($id, (int)$usuario['id']);
        } catch (PDOException) {
            $edicoes = null; // migração 190 ainda não aplicada: sem aviso, a edição continua normal
        }
    }
} else {
    $livroForm = acervo_livro($id);
    if ($livroForm) {
        $valores = $livroForm;
        $valores['autores'] = $livroForm['autores_nomes'];
        $valores['categoria'] = $livroForm['categorias'][0] ?? '';
    }
}

if ($livroForm && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    [$dados, $erros] = livros_validar($_POST, false);
    // Conflito resolvido campo a campo: "usar o salvo" troca o campo pelo valor gravado pelo outro colaborador.
    $basePost = json_decode((string)($_POST['base'] ?? ''), true);
    if (is_array($basePost)) {
        $baseEdicao = LivroRepository::normalizarInstantaneo($basePost);
        foreach ((array)($_POST['resolver'] ?? []) as $campo => $escolha) {
            if ($escolha === 'salvo' && in_array($campo, LivroRepository::CAMPOS_EDICAO, true)) {
                $dados[$campo] = editar_livro_valor($campo, $baseEdicao[$campo]);
            }
        }
    }
    $valores = $dados + $valores;
    $versao = admin_param($_POST, 'versao');
    if (!livros_persistem()) {
        $simulado = !$erros;
    } elseif (!$erros) {
        $marcar = admin_param($_POST, 'acao') === 'verificar';
        try {
            // ISBN repetido não bloqueia (pode haver mais de um cadastro da mesma obra): só avisa.
            $dup = livros_repo()->duplicidades($dados, $id);
            if ($dup['isbn']) {
                admin_flash('aviso', 'ISBN também usado em outro livro.', 'O livro "' . ($dup['isbn']['titulo'] ?? 'Sem título') . '" (nº ' . (int)$dup['isbn']['id'] . ') tem o mesmo ISBN. Confira se não é um cadastro repetido (Central de Qualidade → Possíveis duplicidades).');
            }
            $verificadoAntes = (bool)db_query('SELECT dados_verificados_em IS NOT NULL FROM livros WHERE id = ?', [$id])->fetchColumn();
            $alteracoes = livros_repo()->atualizar($id, $dados, $versao, (int)($usuario['id'] ?? 0) ?: null, $marcar);
            if (isset($edicoes)) {
                $edicoes->sair($id, (int)$usuario['id']);
            }
            $verificadoDepois = (bool)db_query('SELECT dados_verificados_em IS NOT NULL FROM livros WHERE id = ?', [$id])->fetchColumn();
            if ($verificadoAntes && !$verificadoDepois) {
                admin_flash('aviso', 'A obra voltou para "Dados não verificados".', 'Título, autor, ISBN, editora, ano ou edição mudaram. Quando conferir os dados com o livro em mãos, use "Salvar e marcar dados como verificados". Os exemplares não precisam ser conferidos de novo.');
            }
            $exTxt = $livroForm['total_exemplares'] > 0 ? ' A correção já aparece nos ' . $livroForm['total_exemplares'] . ' exemplar(es) desta obra; códigos BDP, localizações, lotes e carimbagem não mudaram.' : '';
            if ($alteracoes) {
                $detalhes = [];
                foreach ($alteracoes as $campo => [$antes, $depois]) {
                    $detalhes[$campo] = ['antes' => $antes, 'depois' => $depois];
                }
                registrar_acao('livro.editar', 'livro', $id, $detalhes);
                $nomes = array_map(fn ($c) => LIVROS_CAMPOS[$c] ?? $c, array_keys($alteracoes));
                admin_flash('sucesso', $marcar ? 'Alterações salvas e dados marcados como verificados.' : 'Alterações salvas.', 'Campos alterados: ' . implode(', ', $nomes) . '.' . $exTxt);
            } elseif ($marcar) {
                admin_flash('sucesso', 'Dados marcados como verificados.', 'Nenhum campo foi alterado; a verificação ficou registrada no histórico da obra.');
            } else {
                admin_flash('aviso', 'Nenhuma alteração', 'Os dados enviados são iguais aos que já estavam cadastrados.');
            }
            admin_redirecionar(admin_url('detalhes-livro') . '?id=' . $id);
        } catch (ConflitoEdicaoException $e) {
            // Outra pessoa salvou antes: nada foi gravado. Monta a comparação e mescla sem descartar o digitado.
            $meu = LivroRepository::normalizarInstantaneo($dados);
            $base = $baseEdicao ?: $e->atual;
            $mesclado = [];
            $linhas = [];
            foreach (LivroRepository::CAMPOS_EDICAO as $c) {
                $igual = fn ($x, $y) => json_encode($x, JSON_UNESCAPED_UNICODE) === json_encode($y, JSON_UNESCAPED_UNICODE);
                $mudouMeu = !$igual($meu[$c], $base[$c]);
                $mudouOutro = !$igual($e->atual[$c], $base[$c]);
                $estado = $igual($meu[$c], $e->atual[$c]) ? 'igual' : ($mudouMeu && $mudouOutro ? 'conflito' : ($mudouMeu ? 'so_meu' : 'so_outro'));
                $mesclado[$c] = in_array($estado, ['so_meu', 'conflito'], true) ? $meu[$c] : $e->atual[$c];
                if ($mudouMeu || $mudouOutro) {
                    $linhas[$c] = ['rotulo' => LIVROS_CAMPOS[$c] ?? $c, 'base' => editar_livro_texto($c, $base[$c]), 'atual' => editar_livro_texto($c, $e->atual[$c]),
                        'meu' => editar_livro_texto($c, $meu[$c]), 'estado' => $estado];
                }
            }
            foreach ($mesclado as $c => $v) {
                $valores[$c] = editar_livro_valor($c, $v);
            }
            $versao = (string)$e->versaoAtual;
            $baseEdicao = $e->atual;
            $conflito = ['linhas' => $linhas, 'alterado_por' => $e->alteradoPor, 'alterado_em' => $e->alteradoEm, 'livro_id' => $id];
            registrar_acao('livro.conflito_edicao', 'livro', $id, ['campos' => array_keys($linhas)]);
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                $falha = fisico_erro_tecnico($e, 'edição dos dados da obra ' . $id);
            } else {
                $falha = $e->getMessage();
            }
        }
    }
}

admin_page_start('acervo', 'Editar dados da obra', 'Acervo', 'Livros, exemplares e categorias da biblioteca', ['assets/css/acervo.css?v=20261115mob', 'assets/css/gestao.css?v=20261118']);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Acervo', admin_url('acervo')], ['Editar dados da obra']]) ?>

<?php if (!$livroForm): ?>
    <section class="adm-card adm-wip">
        <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon('book-x') ?></span>
        <h1>Livro não encontrado</h1>
        <p>O cadastro solicitado não existe ou foi arquivado.</p>
        <div class="adm-wip__actions">
            <a class="adm-button adm-button--primary" href="<?= e(admin_url('acervo')) ?>"><?= icon('arrow-left') ?> Voltar ao acervo</a>
        </div>
    </section>
<?php else: ?>
    <section class="adm-page-head">
        <div>
            <h1>Editar dados da obra</h1>
            <p><?= e($livroForm['titulo']) ?> · <?= e($livroForm['autor_principal']) ?>
                <?php if (livros_persistem()): ?> · <span class="adm-status adm-tone--<?= $verificadoEm ? 'green' : 'amber' ?>"><?= $verificadoEm ? 'Dados verificados' : 'Dados não verificados' ?></span><?php endif; ?></p>
        </div>
        <div class="adm-page-head__actions">
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('detalhes-livro') . '?id=' . $livroForm['id']) ?>"><?= icon('eye') ?> Ver detalhes</a>
        </div>
    </section>

    <?php if (livros_persistem()): ?>
        <div class="adm-notice adm-notice--amber adm-notice--compact" role="status" data-aviso-edicao<?= ObraEdicaoService::aviso($outrosEditando, true) ? '' : ' hidden' ?>>
            <?= icon('users') ?><p data-aviso-edicao-texto><?= e((string)ObraEdicaoService::aviso($outrosEditando, true)) ?> Você pode continuar: se a outra pessoa salvar antes, o sistema mostra as diferenças em vez de sobrescrever.</p>
        </div>
        <?php if ($livroForm['total_exemplares'] > 0): ?>
            <div class="adm-notice adm-notice--blue adm-notice--compact" role="note">
                <?= icon('book-copy') ?>
                <p>Esta obra possui <strong><?= (int)$livroForm['total_exemplares'] ?> exemplar<?= $livroForm['total_exemplares'] > 1 ? 'es' : '' ?></strong>. As informações bibliográficas atualizadas serão exibidas automaticamente em <?= $livroForm['total_exemplares'] > 1 ? 'todos eles' : 'ele' ?>.
                    Códigos BDP, localizações, lotes, carimbagem<?= $livroForm['carimbados'] ? ' (' . (int)$livroForm['carimbados'] . ' já carimbado(s))' : '' ?>, conservação e empréstimos não mudam.
                    Se apenas uma cópia é de outra edição, corrija só ela na ficha do exemplar (“Corrigir a obra deste exemplar”).</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($falha): ?><?= admin_aviso('erro', 'As alterações não foram salvas.', $falha) ?><?php endif; ?>

    <?php if ($simulado): ?>
        <div class="adm-notice adm-notice--green" role="status">
            <?= icon('circle-check') ?>
            <div>
                <strong>Os dados estão corretos.</strong>
                <p>Simulação: as alterações não foram gravadas, pois o banco de dados ainda não está conectado.</p>
            </div>
        </div>
    <?php endif; ?>

    <p class="adm-form-legend">Campos com <span class="adm-required">*</span> são obrigatórios. Alterações no título, no ISBN ou na situação pedem confirmação.</p>

    <div data-edicao-obra="<?= (int)$livroForm['id'] ?>" data-edicao-url="<?= e(admin_base() . 'api/acervo/edicao.php') ?>" data-edicao-intervalo="<?= ObraEdicaoService::RENOVAR_SEGUNDOS ?>">
        <?php include __DIR__ . '/includes/form-livro.php'; ?>
    </div>
<?php endif; ?>

<?php
echo acervo_json_script();
admin_page_end(['assets/js/acervo.js?v=20261030', 'assets/js/gestao.js?v=20261118']);
