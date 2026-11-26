<?php
/**
 * Revisão dos pré-cadastros feitos pelos links de coleta (migração 210) — equipe de revisão.
 *
 * Para cada livro pendente: conferir (e corrigir) os dados, escolher entre ligar a uma obra que já
 * existe (mesmo ISBN) ou criar a obra, e aprovar. A aprovação cria os exemplares com códigos BDP dentro
 * do lote. "Recusar" guarda o motivo (o pré-cadastro nunca é apagado). "Aprovar todos sem alerta"
 * cria as obras dos livros sem ISBN repetido no acervo e sem erro nos dados.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';
require_once __DIR__ . '/includes/livros.php';

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ColetaService;
use Biblioteca\Services\ExemplarService;

$usuario = fisico_iniciar();
if (!coleta_disponivel()) {
    admin_pagina_erro('Recurso ainda não instalado', 'Aplique a migração 210 (php database/migrar.php) para usar os links das equipes.', 'layers');
    exit;
}
$loteId = (int)($_POST['lote'] ?? $_GET['lote'] ?? 0);
$lote = $loteId ? lotes_repo()->lote($loteId) : null;
if (!$lote) {
    http_response_code(404);
    admin_pagina_erro('Lote não encontrado', 'Abra a revisão pela página do lote (seção “Equipes por link”).', 'layers');
    exit;
}
$nome = LoteRepository::nome($lote);
$filtro = in_array(admin_param($_GET, 'situacao'), ['pendente', 'aprovado', 'recusado', 'todos'], true) ? admin_param($_GET, 'situacao') : 'pendente';
$base = admin_url('coleta') . '?lote=' . $loteId;

/** Dados do formulário de revisão (ou do pré-cadastro) no formato de livros_validar(). */
function coleta_para_livro(array $f, array $item, string $nomeLote): array
{
    $autores = array_values(array_filter(array_map('trim', explode(';', (string)($f['autor'] ?? $item['autor'] ?? '')))));
    return [
        'titulo' => (string)($f['titulo'] ?? $item['titulo']),
        'subtitulo' => (string)($f['subtitulo'] ?? $item['subtitulo'] ?? ''),
        'autores' => $autores,
        'isbn' => (string)($f['isbn'] ?? $item['isbn'] ?? ''),
        'editora' => (string)($f['editora'] ?? $item['editora'] ?? ''),
        'ano_publicacao' => (string)($f['ano'] ?? $item['ano'] ?? ''),
        'edicao' => (string)($f['edicao'] ?? $item['edicao'] ?? ''),
        'assunto' => (string)($f['assunto'] ?? $item['assunto'] ?? ''),
        'cdd' => (string)($f['cdd'] ?? $item['cdd'] ?? ''),
        'cdd_fonte' => 'Ficha catalográfica (coleta da ' . $item['equipe'] . ')',
        'status_validacao' => 'aguardando_conferencia',
        'observacoes_internas' => 'Pré-cadastro nº ' . $item['numero'] . ' do ' . $nomeLote . ', feito por ' . $item['coletado_por'] . ' (' . $item['equipe'] . ').',
    ];
}

/** Cria (ou liga) a obra e aprova o pré-cadastro. Devolve os códigos BDP criados. */
function coleta_aprovar_item(array $item, array $f, string $destino, int $obraExistente, array $usuario, string $nomeLote): array
{
    if (!$item['livro_id']) {
        if ($destino === 'existente') {
            if ($obraExistente < 1) {
                throw new AcervoException('Escolha a obra existente.');
            }
            coleta_servico()->vincularObra((int)$item['id'], $obraExistente);
        } else {
            [$dados, $erros] = livros_validar(coleta_para_livro($f, $item, $nomeLote), true);
            if ($erros) {
                throw new AcervoException('Livro nº ' . $item['numero'] . ': ' . implode(' ', $erros));
            }
            $novo = livros_cadastrar($dados, $usuario);
            coleta_servico()->vincularObra((int)$item['id'], (int)$novo['id']);
        }
    }
    return coleta_servico()->aprovar((int)$item['id'], $usuario['id']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    fisico_exigir('exemplares.gerenciar');
    $acao = admin_param($_POST, 'acao');
    try {
        if ($acao === 'aprovar' || $acao === 'recusar') {
            $item = coleta_servico()->item((int)admin_param($_POST, 'item_id'));
            if (!$item || (int)$item['lote_id'] !== $loteId) {
                throw new AcervoException('Pré-cadastro não encontrado neste lote.');
            }
            if ($acao === 'recusar') {
                coleta_servico()->recusar((int)$item['id'], $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Livro nº ' . $item['numero'] . ' recusado.', 'As vagas que ele ocupava voltaram para o lote.');
            } else {
                // destino: "nova" ou "obra:ID" (uma das obras com o mesmo ISBN, oferecidas na tela).
                $destino = admin_param($_POST, 'destino', 'nova');
                $obraId = preg_match('/^obra:(\d+)$/', $destino, $m) ? (int)$m[1] : 0;
                if ($obraId && !in_array($obraId, array_map('intval', array_column(coleta_servico()->obrasComMesmoIsbn($item), 'id')), true)) {
                    throw new AcervoException('Escolha uma das obras oferecidas para este livro.');
                }
                $codigos = coleta_aprovar_item($item, $_POST, $obraId ? 'existente' : 'nova', $obraId, $usuario, $nome);
                admin_flash('sucesso', 'Livro nº ' . $item['numero'] . ' aprovado.', count($codigos) . ' exemplar(es) com código: ' . implode(', ', $codigos) . '.');
            }
        } elseif ($acao === 'aprovar_todos') {
            $ok = 0;
            $pulados = [];
            foreach (array_reverse(coleta_servico()->itens($loteId, 'pendente')) as $item) {
                if ($item['livro_id'] || coleta_servico()->obrasComMesmoIsbn($item)) {
                    $pulados[] = $item['numero'];
                    continue;
                }
                [, $erros] = livros_validar(coleta_para_livro([], $item, $nome), true);
                if ($erros) {
                    $pulados[] = $item['numero'];
                    continue;
                }
                coleta_aprovar_item($item, [], 'nova', 0, $usuario, $nome);
                $ok++;
            }
            admin_flash($ok ? 'sucesso' : 'aviso', $ok . ' livro(s) aprovado(s).', $pulados ? 'Revise um a um os livros nº ' . implode(', ', $pulados) . ' (ISBN já existente no acervo ou dados a corrigir).' : 'Todos os pendentes sem alerta foram aprovados.');
        } else {
            throw new AcervoException('Ação desconhecida.');
        }
    } catch (AcervoException $e) {
        admin_flash('erro', 'Nada foi alterado neste livro.', $e->getMessage());
    } catch (PDOException $e) {
        admin_flash('erro', 'Nada foi alterado neste livro.', fisico_erro_tecnico($e, 'revisão da coleta'));
    }
    admin_redirecionar($base . '&situacao=' . $filtro);
}

$itens = coleta_servico()->itens($loteId, $filtro === 'todos' ? '' : $filtro);
if ($filtro === 'todos') {
    $itens = array_merge($itens, coleta_servico()->itens($loteId, 'recusado'));
}
$resumo = coleta_servico()->resumo($loteId);
$vagas = coleta_servico()->vagas($loteId);
$pausa = coleta_servico()->pausaAberta($loteId, 'revisao');
$podeAprovar = auth_pode('exemplares.gerenciar') && !$pausa;

admin_page_start('lote', 'Revisão da coleta — ' . $nome, 'Inventário', 'Pré-cadastros das equipes', array_merge(FISICO_CSS, ['assets/css/coleta.css?v=20261126']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Histórico de Lotes', admin_url('lotes')], [$nome, admin_url('lote') . '?id=' . $loteId], ['Revisão da coleta']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Revisão da coleta — <?= e($nome) ?> <?= lote_badge($lote['status']) ?></h1>
        <p>Confira cada livro pré-cadastrado pelas equipes. Ao aprovar, a obra é criada (ou ligada à existente) e os exemplares recebem os códigos BDP dentro do lote.</p>
    </div>
    <div class="adm-page-head__actions"><a class="adm-button adm-button--ghost" href="<?= e(admin_url('lote') . '?id=' . $loteId . '#equipes') ?>"><?= icon('arrow-left') ?> Voltar ao lote</a></div>
</section>

<?= admin_flash_html() ?>

<?php if ($pausa): ?>
    <div class="adm-notice adm-notice--amber" role="status"><?= icon('pause') ?><p><strong>A revisão deste lote está pausada</strong> por <?= e($pausa['pausado_por']) ?> (<?= e($pausa['onde_parou']) ?>). Retome na página do lote para aprovar ou recusar.</p></div>
<?php endif; ?>

<dl class="coleta-vagas">
    <div><dt>Aguardando revisão</dt><dd><?= (int)$resumo['pendente'] ?></dd></div>
    <div><dt>Aprovados</dt><dd><?= (int)$resumo['aprovado'] ?></dd></div>
    <div><dt>Recusados ou retirados</dt><dd><?= (int)$resumo['recusado'] ?></dd></div>
    <div><dt>Vagas livres no lote</dt><dd><?= (int)$vagas['restantes'] ?> de <?= (int)$vagas['capacidade'] ?></dd></div>
</dl>

<div class="adm-filters coleta-acoes">
    <?php foreach (['pendente' => 'Aguardando revisão', 'aprovado' => 'Aprovados', 'recusado' => 'Recusados', 'todos' => 'Todos'] as $v => $r): ?>
        <a class="adm-button adm-button--sm <?= $filtro === $v ? 'adm-button--primary' : 'adm-button--ghost' ?>" href="<?= e($base . '&situacao=' . $v) ?>"><?= e($r) ?></a>
    <?php endforeach; ?>
    <?php if ($podeAprovar && $filtro === 'pendente' && $resumo['pendente'] > 1): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="lote" value="<?= $loteId ?>"><input type="hidden" name="acao" value="aprovar_todos">
            <button class="adm-button adm-button--ghost adm-button--sm" type="submit" data-confirmar="Aprovar todos os livros pendentes sem alerta? Cada um vira uma obra nova com os exemplares e códigos BDP."><?= icon('list-checks') ?> Aprovar todos sem alerta</button></form>
    <?php endif; ?>
</div>

<?php foreach ($itens as $it): $iguais = $it['status'] === 'pendente' ? coleta_servico()->obrasComMesmoIsbn($it) : []; ?>
    <article class="adm-card adm-panel" id="item-<?= (int)$it['id'] ?>">
        <div class="adm-panel__header"><div>
            <h2>Nº <?= (int)$it['numero'] ?> · <?= e($it['titulo']) ?></h2>
            <p><?= (int)$it['quantidade'] ?> exemplar(es) · por <?= e($it['coletado_por']) ?> (<?= e($it['equipe']) ?>) em <?= e(admin_data_hora($it['criado_em'])) ?>
                · <?= ['pendente' => '<span class="adm-status adm-tone--amber">Aguardando revisão</span>', 'aprovado' => '<span class="adm-status adm-tone--green">Aprovado</span>', 'recusado' => '<span class="adm-status adm-tone--muted">Recusado</span>'][$it['status']] ?></p>
        </div></div>
        <?php if ($it['status'] !== 'pendente'): ?>
            <dl class="adm-meta">
                <div><dt>Autor</dt><dd><?= e($it['autor'] ?: 'Sem autor indicado') ?></dd></div>
                <div><dt>Editora / ano</dt><dd><?= e(($it['editora'] ?: 'Sem editora') . ' · ' . ($it['ano'] ?: 's.d.')) ?></dd></div>
                <div><dt>ISBN / CDD</dt><dd><?= e(($it['isbn'] ?: 'sem ISBN') . ' · ' . ($it['cdd'] ?: 'sem CDD')) ?></dd></div>
                <?php if ($it['status'] === 'aprovado'): ?><div><dt>Obra</dt><dd><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$it['livro_id']) ?>">Abrir a obra</a> · aprovado por <?= e((string)$it['revisado_por_nome']) ?> em <?= e(admin_data_hora($it['revisado_em'])) ?></dd></div>
                <?php else: ?><div><dt>Motivo</dt><dd><?= e((string)$it['motivo_recusa']) ?></dd></div><?php endif; ?>
            </dl>
        <?php else: ?>
            <form method="post" class="adm-form-section">
                <?= csrf_campo() ?><input type="hidden" name="lote" value="<?= $loteId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="acao" value="aprovar">
                <div class="adm-form-grid adm-form-grid--3">
                    <label class="adm-field adm-field--full"><span class="adm-field__label">Título</span><input class="adm-input" type="text" name="titulo" maxlength="255" value="<?= e($it['titulo']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Subtítulo</span><input class="adm-input" type="text" name="subtitulo" maxlength="255" value="<?= e((string)$it['subtitulo']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Autor(es) — separe com ;</span><input class="adm-input" type="text" name="autor" maxlength="500" value="<?= e((string)$it['autor']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Editora</span><input class="adm-input" type="text" name="editora" maxlength="150" value="<?= e((string)$it['editora']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Ano</span><input class="adm-input" type="text" name="ano" maxlength="4" value="<?= e((string)$it['ano']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">ISBN</span><input class="adm-input" type="text" name="isbn" maxlength="20" value="<?= e((string)$it['isbn']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">CDD</span><input class="adm-input" type="text" name="cdd" maxlength="30" value="<?= e((string)$it['cdd']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Edição</span><input class="adm-input" type="text" name="edicao" maxlength="60" value="<?= e((string)$it['edicao']) ?>"></label>
                    <label class="adm-field"><span class="adm-field__label">Assunto</span><input class="adm-input" type="text" name="assunto" maxlength="255" value="<?= e((string)$it['assunto']) ?>"></label>
                    <?php if ($it['observacoes'] || $it['cod_autor']): ?><p class="adm-field adm-field--full adm-field__hint">Observações da equipe: <?= e(trim(($it['observacoes'] ?? '') . ($it['cod_autor'] ? ' · Cutter: ' . $it['cod_autor'] : ''), ' ·')) ?></p><?php endif; ?>
                </div>
                <fieldset class="adm-field"><legend class="adm-field__label">Obra</legend>
                    <div class="coleta-escolha">
                        <?php foreach ($iguais as $i => $o): ?>
                            <label><input type="radio" name="destino" value="obra:<?= (int)$o['id'] ?>"<?= $i === 0 ? ' checked' : '' ?>> Ligar à obra que já existe: <a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$o['id']) ?>" target="_blank" rel="noopener"><?= e($o['titulo']) ?></a> (<?= e(trim(($o['editora'] ?? '') . ' ' . ($o['ano_publicacao'] ?? '') . ' ' . ($o['edicao'] ?? ''))) ?>)</label>
                        <?php endforeach; ?>
                        <label><input type="radio" name="destino" value="nova"<?= $iguais ? '' : ' checked' ?>> Criar obra nova com os dados acima</label>
                    </div>
                    <?php if ($iguais): ?><span class="adm-field__hint">Já existe obra com este ISBN no acervo: normalmente basta ligar a ela (os exemplares ficam na mesma obra).</span><?php endif; ?>
                </fieldset>
                <?php if ($podeAprovar): ?>
                    <div class="adm-form-actions adm-form-actions--static">
                        <button class="adm-button adm-button--primary" type="submit"><?= icon('check') ?> Aprovar e gerar <?= (int)$it['quantidade'] ?> código(s) BDP</button>
                    </div>
                <?php endif; ?>
            </form>
            <?php if ($podeAprovar): ?>
                <form method="post" class="adm-inline-form"><?= csrf_campo() ?><input type="hidden" name="lote" value="<?= $loteId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="acao" value="recusar">
                    <input class="adm-input adm-input--sm" type="text" name="motivo" maxlength="255" required placeholder="Motivo da recusa (ex.: livro repetido)" aria-label="Motivo da recusa">
                    <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('x') ?> Recusar</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
<?php if (!$itens): ?>
    <div class="adm-card adm-panel"><div class="adm-empty"><?= icon('inbox') ?><strong>Nada por aqui</strong><span><?= $filtro === 'pendente' ? 'Nenhum livro aguardando revisão neste lote.' : 'Nenhum pré-cadastro nesta situação.' ?></span></div></div>
<?php endif; ?>

<?php
admin_page_end(['assets/js/coleta.js?v=20261126']);
