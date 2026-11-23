<?php
/**
 * Registrar exemplares físicos de uma obra: um ou vários de uma vez ("Registrar vários exemplares").
 * A partir da página do livro (?livro=ID, &modo=varios) ou pesquisando o livro. Também usado pelas
 * Aquisições (&aquisicao=ID).
 *
 * - Cada cópia física vira um exemplar INDIVIDUAL com o próprio código BDP, gerado e confirmado pelo
 *   servidor (sequência global bloqueada; nenhum código se repete ou é reaproveitado).
 * - Uma única transação: ou todas as cópias são gravadas, ou nenhuma.
 * - O lote ativo é respeitado: sem vagas para todas, nada é gravado e a página oferece reduzir a
 *   quantidade, escolher outro lote aberto ou cancelar (nunca divide entre lotes sozinha).
 * - Chave única da operação (requisicoes_idempotentes, migração 190): reenviar o mesmo formulário
 *   (clique duplo, conexão perdida, página recarregada) não cria cópias novas: mostra os códigos já
 *   atribuídos. O formulário também fica guardado no navegador até a confirmação do servidor.
 * - Conservação: nenhuma cópia é considerada "Bom" automaticamente. A mesma avaliação só vale para
 *   todas quando a pessoa marca que conferiu todas as cópias; senão cada uma tem a sua (ou "Não avaliado").
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\LoteRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\LoteCapacidadeException;
use Biblioteca\Services\ObraEdicaoService;
use Biblioteca\Services\OperacaoJaConcluidaException;

$usuario = fisico_iniciar('exemplares.gerenciar');
$livroId = (int)($_POST['livro_id'] ?? $_GET['livro'] ?? 0);
$q = mb_substr(admin_param($_GET, 'q'), 0, 100);
$modoVarios = admin_param($_GET, 'modo') === 'varios' || (int)($_POST['quantidade'] ?? 1) > 1;
$erro = null;
$falhaIncerta = false;
$semVagas = null;
$fx = [];
$copiasPost = [];
$base = admin_url('cadastrar-exemplar');
$aquisicaoId = (int)($_POST['aquisicao_id'] ?? $_GET['aquisicao'] ?? 0) ?: null;
// Chave única deste formulário: gerada pelo servidor ao abrir a página e reenviada sem mudar.
$chave = preg_match('/^[a-f0-9]{32}$/', (string)($_POST['chave'] ?? '')) ? (string)$_POST['chave'] : bin2hex(random_bytes(16));

/** Endereço do resultado (os códigos vêm do banco, pela chave da operação). */
$urlResultado = fn (int $livro, string $ch, bool $repetido = false) => $base . '?livro=' . $livro . '&resultado=' . $ch . ($repetido ? '&repetido=1' : '') . ($aquisicaoId ? '&aquisicao=' . $aquisicaoId : '');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_exigir_csrf();
        $fx = fisico_dados_exemplar($_POST);
        $fx['estado_todos'] = admin_param($_POST, 'estado_todos') === '1' ? '1' : '';
        $fx['data_entrada'] = mb_substr(admin_param($_POST, 'data_entrada'), 0, 10);
        $copiasPost = array_values(array_filter((array)($_POST['copias'] ?? []), 'is_array'));
        try {
            if (admin_param($_POST, 'acao') === 'trocar_lote') {
                // "Selecionar outro lote aberto": troca o lote ativo e mantém o formulário preenchido.
                lotes_servico()->escolherAtivo((int)admin_param($_POST, 'lote_id') ?: null, (int)$usuario['id']);
                admin_flash('sucesso', 'Lote ativo alterado.', 'Confira as vagas e confirme o registro. Os dados digitados foram recuperados do navegador.');
                admin_redirecionar($base . '?livro=' . $livroId . '&modo=varios' . ($aquisicaoId ? '&aquisicao=' . $aquisicaoId : '') . '#form-exemplares');
            }
            if (admin_param($_POST, 'confirmar') !== '1') {
                throw new AcervoException('Marque a confirmação de que os exemplares estão fisicamente com você.');
            }
            $quantidade = max(1, min(ExemplarService::LIMITE_EM_QUANTIDADE, (int)$fx['quantidade']));
            if (($reduzir = (int)admin_param($_POST, 'reduzir')) > 0) {
                $quantidade = min($quantidade, $reduzir); // "Reduzir a quantidade" (sem vagas para todas)
                $fx['quantidade'] = (string)$quantidade;
            }
            $comuns = [
                'situacao' => $fx['situacao'] ?: 'disponivel',
                'localizacao_id' => fisico_resolver_local($fx, $usuario['id']),
                'observacoes' => $fx['observacoes'],
                // A mesma avaliação para todas só quando a pessoa conferiu todas as cópias.
                'estado_conservacao' => $fx['estado_todos'] === '1' ? $fx['estado_conservacao'] : '',
            ];
            $copias = [];
            for ($i = 0; $i < $quantidade; $i++) {
                $c = $copiasPost[$i] ?? [];
                $copias[] = [
                    'estado_conservacao' => $fx['estado_todos'] === '1' ? '' : mb_substr((string)($c['estado_conservacao'] ?? ''), 0, 20),
                    'observacoes' => mb_substr((string)($c['observacoes'] ?? ''), 0, 300),
                    'localizacao_id' => (string)($c['localizacao_id'] ?? ''),
                ];
            }
            $criados = exemplares_servico()->cadastrarEmQuantidade($livroId, $comuns, $copias, (int)$usuario['id'], $chave,
                ['aquisicao_id' => $aquisicaoId, 'data_entrada' => $fx['data_entrada'] ?: null]);
            try {
                rascunhos_servico()->descartar($usuario['id'], 'cadastrar_exemplar', 'livro' . $livroId);
            } catch (Throwable) {
            }
            admin_flash('sucesso', count($criados) === 1 ? 'Exemplar registrado: ' . $criados[0]['codigo'] . '.' : count($criados) . ' exemplares registrados.',
                'Os códigos abaixo foram atribuídos e confirmados pelo banco de dados. Eles não se repetem e não serão reaproveitados.');
            admin_redirecionar($urlResultado($livroId, $chave));
        } catch (OperacaoJaConcluidaException $e) {
            admin_flash('aviso', 'Este registro já tinha sido concluído antes.', 'O mesmo formulário foi enviado de novo (clique duplo, página recarregada ou conexão perdida). Nenhuma cópia nova foi criada: os códigos abaixo são os que foram atribuídos na primeira vez.');
            admin_redirecionar($urlResultado($livroId, $chave, true));
        } catch (LoteCapacidadeException $e) {
            $erro = $e->getMessage();
            $semVagas = ['restam' => $e->restam, 'lote_id' => $e->loteId];
        } catch (AcervoException $e) {
            $erro = $e->getMessage();
        } catch (PDOException $e) {
            // Conexão perdida durante a gravação: o resultado é incerto. Confere pela chave antes de responder.
            $falhaIncerta = Biblioteca\Support\Migracoes::conexaoPerdida($e);
            if ($falhaIncerta) {
                try {
                    if (exemplares_servico()->resultadoAnterior($chave) !== null) {
                        admin_redirecionar($urlResultado($livroId, $chave, true));
                    }
                } catch (Throwable) {
                }
            }
            $erro = fisico_erro_tecnico($e, 'registro de exemplares (chave ' . $chave . ')', $falhaIncerta);
        }
    }

    $livro = $livroId ? livros_cadastro_resumo($livroId) : null;
    $busca = !$livro && $q !== '' ? inventario_repo()->pesquisarLivros(0, $q) : null;
    $locais = localizacoes_servico()->ativas();
    // Localização sugerida: a última prateleira usada por esta pessoa (nunca a da planilha antiga).
    $fxSugestao = fisico_sugestao_local((int)$usuario['id']);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && $fxSugestao['id'] !== null) {
        $fx['localizacao_id'] = (string)$fxSugestao['id'];
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $fx['quantidade'] = $modoVarios ? (string)max(2, (int)admin_param($_GET, 'quantidade', '2')) : '1';
    }
    $resultadoChave = admin_param($_GET, 'resultado');
    $resultado = $resultadoChave !== '' ? exemplares_servico()->resultadoAnterior($resultadoChave) : null;
    $criadosDb = $resultado ? exemplares_repo()->paraEtiquetas(array_map(fn ($c) => (int)$c['id'], $resultado)) : [];
    $loteAtivo = lotes_repo()->loteAtivo((int)$usuario['id']);
    $loteResultado = $resultado && !empty($resultado[0]['lote_id']) ? lotes_repo()->lote((int)$resultado[0]['lote_id']) : null;
    $lotesEmUso = lotes_repo()->emUso();
    $meusLotes = array_values(array_filter(lotes_repo()->meusLotes((int)$usuario['id']), fn ($l) => $l['status'] === 'aberto' && $l['modo'] === 'gerenciado'));
    $aquisicao = $aquisicaoId ? db_query('SELECT * FROM aquisicoes WHERE id = ?', [$aquisicaoId])->fetch() : null;
    $editandoObra = [];
    if ($livro) {
        try {
            $editandoObra = (new ObraEdicaoService(db()))->outros($livroId, (int)$usuario['id']);
        } catch (PDOException) {
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir o registro de exemplares', fisico_erro_tecnico($e, 'registro de exemplares'));
    exit;
}

/** Livro com autores, contagens, verificação e dados da planilha (só referência). */
function livros_cadastro_resumo(int $id): ?array
{
    $l = db_query('SELECT l.id, l.titulo, l.subtitulo, l.isbn, l.editora, l.ano_publicacao, l.edicao, l.arquivado_em, l.dados_verificados_em,
                          (SELECT GROUP_CONCAT(au.nome ORDER BY la.ordem SEPARATOR ", ") FROM livro_autores la JOIN autores au ON au.id = la.autor_id WHERE la.livro_id = l.id) AS autores
                   FROM livros l WHERE l.id = ?', [$id])->fetch();
    if (!$l) {
        return null;
    }
    return $l + exemplares_repo()->contagemLivro($id) + ['planilha' => exemplares_repo()->dadosPlanilha($id)];
}

$restamAtivo = $loteAtivo && $loteAtivo['capacidade'] !== null ? max(0, (int)$loteAtivo['capacidade'] - (int)$loteAtivo['total']) : null;
$quantidadeForm = max(1, (int)($fx['quantidade'] ?? 1));
$opcoesLocal = ['' => 'Igual à localização comum'];
foreach ($locais as $l) {
    $opcoesLocal[(string)$l['id']] = ExemplarService::localizacaoTexto($l);
}

admin_page_start('exemplares', $modoVarios ? 'Registrar vários exemplares' : 'Registrar exemplar', 'Exemplares', 'Unidades físicas do acervo', array_merge(FISICO_CSS, ['assets/css/gestao.css?v=20261118']));
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Exemplares', admin_url('exemplares')], [$modoVarios ? 'Registrar vários exemplares' : 'Registrar exemplar']]) ?>

<section class="adm-page-head">
    <div>
        <h1><?= $modoVarios ? 'Registrar vários exemplares' : 'Registrar exemplar físico' ?></h1>
        <p><?= $modoVarios ? 'Várias cópias físicas da mesma obra, cada uma com o próprio código BDP, numa única operação.' : 'Registre uma unidade real do livro que está com você. O código BDP é gerado na confirmação.' ?></p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($livro): ?>
            <a class="adm-button adm-button--ghost" href="<?= e($base . '?livro=' . (int)$livro['id'] . ($modoVarios ? '' : '&modo=varios') . ($aquisicaoId ? '&aquisicao=' . $aquisicaoId : '')) ?>"><?= icon($modoVarios ? 'book-plus' : 'book-copy') ?> <?= $modoVarios ? 'Registrar só um' : 'Registrar vários exemplares' ?></a>
            <a class="adm-button adm-button--ghost" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$livro['id']) ?>"><?= icon('arrow-left') ?> Voltar ao livro</a>
        <?php endif; ?>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($erro): ?>
    <?= admin_aviso('erro', $falhaIncerta ? 'Não foi possível confirmar se o cadastro foi concluído.' : 'Os exemplares não foram registrados. Suas informações foram preservadas.', $erro) ?>
<?php endif; ?>

<?php if ($aquisicao): ?>
    <div class="adm-notice adm-notice--blue adm-notice--compact" role="note"><?= icon('inbox') ?>
        <p>Entrada no acervo: <strong>aquisição nº <?= (int)$aquisicao['id'] ?></strong> — <?= e(AQUISICAO_TIPOS[$aquisicao['tipo']] ?? $aquisicao['tipo']) ?> · <?= e($aquisicao['origem']) ?> · recebida em <?= e(date('d/m/Y', strtotime($aquisicao['data_recebimento']))) ?>.
            <a class="adm-link" href="<?= e(admin_url('aquisicoes') . '?id=' . (int)$aquisicao['id']) ?>">Ver aquisição</a></p></div>
<?php endif; ?>

<?php if ($resultado !== null && $livro): ?>
    <section class="adm-card adm-panel adm-criados" aria-labelledby="titulo-criados" id="resultado" data-protegido-concluido="exemplares-livro<?= (int)$livroId ?>">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-criados"><?= count($criadosDb) ?> exemplar<?= count($criadosDb) > 1 ? 'es registrados' : ' registrado' ?><?= admin_param($_GET, 'repetido') === '1' ? ' anteriormente' : '' ?></h2>
            <p>Códigos atribuídos e confirmados pelo banco de dados<?= $loteResultado ? ' · ' . e(LoteRepository::nome($loteResultado)) : '' ?>. Escreva cada código no carimbo do livro, na ordem do lote.</p>
        </div></div>
        <ul class="adm-code-list">
            <?php foreach ($criadosDb as $c): ?>
                <li><a class="adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$c['id']) ?>"><?= e($c['codigo_individual']) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($loteResultado): ?>
            <div class="adm-gestao-resumo">
                <p><strong><?= e(LoteRepository::nome($loteResultado)) ?></strong> <?= lote_badge($loteResultado['status']) ?></p>
                <?= lote_progresso($loteResultado) ?>
                <?php if ($loteResultado['status'] !== 'aberto'): ?>
                    <p class="adm-field__hint"><?= icon('info') ?> O lote atingiu a capacidade e foi fechado para novos registros — segue para a carimbagem. Para continuar registrando, escolha outro lote.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="adm-actions-inline">
            <a class="adm-button adm-button--primary" href="<?= e($base . '?livro=' . (int)$livroId . '&modo=varios' . ($aquisicaoId ? '&aquisicao=' . $aquisicaoId : '')) ?>"><?= icon('plus') ?> Registrar mais exemplares</a>
            <a class="adm-button adm-button--ghost" href="<?= e($base . ($aquisicaoId ? '?aquisicao=' . $aquisicaoId : '')) ?>"><?= icon('skip-forward') ?> Próximo livro</a>
            <?php if ($loteResultado): ?>
                <a class="adm-button adm-button--ghost" href="<?= e(admin_url('planilha-bdp') . '?aba=todos&lote=' . (int)$loteResultado['id']) ?>"><?= icon('stamp') ?> Consultar planilha do lote</a>
            <?php endif; ?>
            <?php if (auth_pode('etiquetas.gerar') && $criadosDb): ?>
                <a class="adm-button adm-button--ghost" href="<?= e(admin_url('etiquetas') . '?ids=' . implode(',', array_column($criadosDb, 'id'))) ?>"><?= icon('printer') ?> Etiquetas</a>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!$livro): ?>
    <section class="adm-card adm-panel" aria-labelledby="titulo-busca">
        <div class="adm-panel__header"><div><h2 id="titulo-busca">1. Escolha a obra</h2><p>Pesquise pelo título, autor ou ISBN do registro bibliográfico.</p></div></div>
        <form class="adm-toolbar adm-toolbar--search" method="get" action="<?= e($base) ?>">
            <?php if ($aquisicaoId): ?><input type="hidden" name="aquisicao" value="<?= (int)$aquisicaoId ?>"><?php endif; ?>
            <?php if ($modoVarios): ?><input type="hidden" name="modo" value="varios"><?php endif; ?>
            <label class="adm-field-search adm-toolbar__q"><?= icon('search') ?><span class="adm-visually-hidden">Pesquisar livro</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Título, autor ou ISBN..." autofocus autocomplete="off"></label>
            <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('search') ?> Pesquisar</button>
        </form>
        <?php if ($busca !== null): ?>
            <?php if (!$busca['livros']): ?>
                <div class="adm-empty adm-empty--left"><?= icon('book-x') ?><strong>Nenhum livro encontrado.</strong><span>Se a obra ainda não tem registro bibliográfico, cadastre-a primeiro.</span>
                    <?php if (auth_pode('livros.editar')): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('cadastrar-livro')) ?>"><?= icon('book-plus') ?> Adicionar obra</a><?php endif; ?></div>
            <?php else: ?>
                <ul class="adm-resultados">
                    <?php foreach ($busca['livros'] as $l): ?>
                        <li>
                            <div><strong><?= e($l['titulo']) ?></strong><small><?= e($l['autores'] ?: 'Autor não informado') ?><?= $l['isbn'] ? ' · ISBN ' . e($l['isbn']) : '' ?> · <?= count($l['exemplares']) ?> exemplar(es) físico(s)</small></div>
                            <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '?livro=' . (int)$l['id'] . ($modoVarios ? '&modo=varios' : '') . ($aquisicaoId ? '&aquisicao=' . $aquisicaoId : '')) ?>"><?= icon('plus') ?> Escolher</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($busca['mais']): ?><p class="adm-field__hint">Há mais resultados. Refine a pesquisa.</p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php elseif ($resultado === null): ?>
    <div class="adm-form-layout">
        <div class="adm-form-layout__main">
            <?php if ($semVagas): ?>
                <section class="adm-notice adm-notice--amber" role="alert" aria-labelledby="titulo-sem-vagas">
                    <?= icon('layers') ?>
                    <div class="adm-notice__content">
                        <strong id="titulo-sem-vagas"><?= $semVagas['restam'] > 0 ? 'O lote tem só ' . (int)$semVagas['restam'] . ' vaga(s) disponível(is).' : 'O lote atingiu sua capacidade.' ?></strong>
                        <p>Nada foi gravado. Os exemplares não são divididos entre lotes sem a sua confirmação. Escolha uma opção:</p>
                        <div class="adm-actions-inline">
                            <?php if ($semVagas['restam'] > 0): ?>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit" form="form-exemplares" name="reduzir" value="<?= (int)$semVagas['restam'] ?>"><?= icon('check') ?> Registrar só <?= (int)$semVagas['restam'] ?> neste lote</button>
                            <?php endif; ?>
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$livroId) ?>">Cancelar</a>
                        </div>
                        <p class="adm-field__hint">Ou selecione outro lote aberto no quadro “Lote” ao lado.</p>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($livro['arquivado_em'] !== null): ?>
                <?= admin_aviso('erro', 'Este livro está arquivado.', 'Restaure o cadastro antes de registrar exemplares.') ?>
            <?php else: ?>
            <form method="post" id="form-exemplares" class="adm-card adm-panel adm-form-section" data-loading-form data-rascunho="cadastrar_exemplar" data-rascunho-ref="livro<?= (int)$livroId ?>"
                  data-protegido="exemplares-livro<?= (int)$livroId ?>" data-registro-varios>
                <?= csrf_campo() ?>
                <input type="hidden" name="livro_id" value="<?= (int)$livro['id'] ?>">
                <input type="hidden" name="chave" value="<?= e($chave) ?>">
                <?php if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'): ?><span hidden data-servidor-preencheu></span><?php endif; ?>
                <?php if ($aquisicaoId): ?><input type="hidden" name="aquisicao_id" value="<?= (int)$aquisicaoId ?>"><?php endif; ?>
                <div class="adm-form-section__header"><span class="adm-step">2</span><div><h2>Exemplares em mãos</h2><p>Cada cópia física recebe um código próprio. Informe só as que estão com você.</p></div></div>

                <div class="adm-form-grid">
                    <label class="adm-field">
                        <span class="adm-field__label">Quantidade de exemplares <span class="adm-required" aria-hidden="true">*</span></span>
                        <input class="adm-input" type="number" name="quantidade" min="1" max="<?= ExemplarService::LIMITE_EM_QUANTIDADE ?>" value="<?= (int)$quantidadeForm ?>" inputmode="numeric" required data-quantidade-copias>
                        <span class="adm-field__hint"><?= $restamAtivo !== null ? 'Vagas no lote ativo: <strong>' . $restamAtivo . '</strong>. ' : '' ?>Máximo de <?= ExemplarService::LIMITE_EM_QUANTIDADE ?> por registro.</span>
                    </label>
                    <label class="adm-field">
                        <span class="adm-field__label">Situação inicial</span>
                        <?= fisico_select_situacao('situacao', $fx['situacao'] ?? 'disponivel') ?>
                        <span class="adm-field__hint">Exemplar novo fica “Aguardando liberação” até ser carimbado e organizado na estante.</span>
                    </label>
                    <div class="adm-field">
                        <span class="adm-field__label">Carimbagem</span>
                        <p class="adm-gestao-fixo"><?= icon('stamp') ?> Aguardando carimbo <small>(o código é gerado agora; escreva-o no carimbo de cada livro)</small></p>
                    </div>
                    <label class="adm-field">
                        <span class="adm-field__label">Data de entrada no acervo <span class="adm-optional">opcional</span></span>
                        <input class="adm-input" type="date" name="data_entrada" max="<?= date('Y-m-d') ?>" value="<?= e($fx['data_entrada'] ?? ($aquisicao['data_recebimento'] ?? '')) ?>">
                        <span class="adm-field__hint">Vazio = data de hoje (data do registro).</span>
                    </label>
                    <fieldset class="adm-field adm-field--full">
                        <legend class="adm-field__label">Localização comum (estante e prateleira sugeridas)</legend>
                        <?= fisico_campo_local('localizacao_id', (int)($fx['localizacao_id'] ?? 0) ?: null, 'Aguardando organização (área de triagem)', 'fx-local') ?>
                        <?= fisico_sugestao_html($fxSugestao, (int)($fx['localizacao_id'] ?? 0) ?: null) ?>
                        <span class="adm-field__hint">Vale para todas as cópias, exceto as que tiverem localização própria abaixo.</span>
                    </fieldset>
                    <div class="adm-field adm-field--full adm-gestao-conservacao">
                        <label class="adm-check-confirm">
                            <input type="checkbox" name="estado_todos" value="1"<?= ($fx['estado_todos'] ?? '') === '1' ? ' checked' : '' ?> data-estado-todos>
                            <span>Conferi <strong>todas</strong> as cópias e o estado de conservação é o mesmo para todas</span>
                        </label>
                        <label class="adm-field" data-estado-comum<?= ($fx['estado_todos'] ?? '') === '1' ? '' : ' hidden' ?>>
                            <span class="adm-field__sublabel">Estado de conservação de todas as cópias</span>
                            <?= fisico_select_conservacao('estado_conservacao', $fx['estado_conservacao'] ?? 'nao_avaliado') ?>
                        </label>
                        <span class="adm-field__hint">Sem esta confirmação, cada cópia fica com a sua avaliação abaixo (ou “Não avaliado”). Nenhuma cópia é considerada em bom estado automaticamente.</span>
                    </div>
                    <label class="adm-field adm-field--full">
                        <span class="adm-field__label">Observações comuns <span class="adm-optional">opcional</span></span>
                        <textarea class="adm-textarea" name="observacoes" rows="2" maxlength="500" placeholder="Ex.: doação recebida em 2026"><?= e($fx['observacoes'] ?? '') ?></textarea>
                    </label>
                </div>

                <details class="adm-gestao-copias" <?= $copiasPost || $modoVarios ? 'open' : '' ?>>
                    <summary><?= icon('list') ?> Diferenças de cada cópia (conservação, observações, localização)</summary>
                    <p class="adm-field__hint">Preencha só o que for diferente. Se alguma cópia for de outra edição, não a registre aqui: registre-a na obra correspondente.</p>
                    <div class="adm-gestao-copias__lista" data-copias>
                        <?php for ($i = 0; $i < max($quantidadeForm, 1); $i++): $c = $copiasPost[$i] ?? []; ?>
                            <fieldset class="adm-gestao-copia" data-copia>
                                <legend>Cópia <span data-copia-numero><?= $i + 1 ?></span></legend>
                                <label class="adm-field"><span class="adm-field__sublabel">Conservação</span><?= fisico_select_conservacao('copias[' . $i . '][estado_conservacao]', (string)($c['estado_conservacao'] ?? 'nao_avaliado')) ?></label>
                                <label class="adm-field"><span class="adm-field__sublabel">Localização</span>
                                    <select class="adm-select" name="copias[<?= $i ?>][localizacao_id]">
                                        <?php foreach ($opcoesLocal as $lv => $lt): ?><option value="<?= e((string)$lv) ?>"<?= (string)($c['localizacao_id'] ?? '') === (string)$lv ? ' selected' : '' ?>><?= e($lt) ?></option><?php endforeach; ?>
                                    </select></label>
                                <label class="adm-field adm-gestao-copia__obs"><span class="adm-field__sublabel">Observações / ocorrência</span>
                                    <input class="adm-input" name="copias[<?= $i ?>][observacoes]" maxlength="300" value="<?= e((string)($c['observacoes'] ?? '')) ?>" placeholder="Ex.: capa rasgada"></label>
                            </fieldset>
                        <?php endfor; ?>
                    </div>
                </details>

                <label class="adm-check-confirm">
                    <input type="checkbox" name="confirmar" value="1" required<?= admin_param($_POST, 'confirmar') === '1' ? ' checked' : '' ?>>
                    <span>Estou com <span data-quantidade-texto><?= (int)$quantidadeForm ?></span> exemplar(es) físico(s) desta obra em mãos e quero gerar os códigos BDP.</span>
                </label>
                <p class="adm-field__hint"><?= icon('shield-check') ?> Se a conexão cair, envie de novo: o sistema verifica se o registro já foi concluído e não cria cópias repetidas.</p>
                <button class="adm-button adm-button--primary" type="submit" name="acao" value="registrar" data-submit><?= icon('check') ?> <span data-submit-label>Registrar <?= (int)$quantidadeForm ?> exemplar(es)</span></button>
            </form>
            <?php endif; ?>
        </div>
        <aside class="adm-form-layout__side">
            <section class="adm-card adm-panel adm-form-section">
                <div class="adm-form-section__header"><span class="adm-step">1</span><div><h2>Obra</h2></div></div>
                <dl class="adm-meta adm-meta--stack adm-meta--boxed">
                    <div><dt>Título</dt><dd><a class="adm-link" href="<?= e(admin_url('detalhes-livro') . '?id=' . (int)$livro['id']) ?>"><?= e($livro['titulo']) ?></a></dd></div>
                    <div><dt>Autor</dt><dd><?= e($livro['autores'] ?: 'Não informado') ?></dd></div>
                    <div><dt>Edição</dt><dd><?= e(implode(' · ', array_filter([$livro['editora'], $livro['edicao'], $livro['ano_publicacao']])) ?: '—') ?></dd></div>
                    <div><dt>ISBN (identifica a edição)</dt><dd class="adm-mono"><?= $livro['isbn'] ? e($livro['isbn']) : '—' ?></dd></div>
                    <div><dt>Verificação bibliográfica</dt><dd><span class="adm-status adm-tone--<?= $livro['dados_verificados_em'] ? 'green' : 'amber' ?>"><?= $livro['dados_verificados_em'] ? 'Dados verificados' : 'Dados não verificados' ?></span></dd></div>
                    <div><dt>Exemplares físicos cadastrados</dt><dd><?= (int)$livro['ativos'] ?></dd></div>
                    <div><dt>Disponíveis para empréstimo</dt><dd><?= (int)$livro['disponiveis'] ?></dd></div>
                </dl>
                <?php if (!$livro['dados_verificados_em']): ?><p class="adm-side-note"><?= icon('info') ?> Os dados desta obra ainda não foram verificados. O registro dos exemplares não depende disso.</p><?php endif; ?>
                <?php if ($editandoObra): ?><p class="adm-side-note"><?= icon('users') ?> <?= e(ObraEdicaoService::aviso($editandoObra, auth_pode('livros.editar'))) ?> O registro de exemplares não é afetado.</p><?php endif; ?>
                <a class="adm-link" href="<?= e($base . ($modoVarios ? '?modo=varios' : '')) ?>"><?= icon('search') ?> Escolher outra obra</a>
            </section>
            <section class="adm-card adm-panel adm-form-section" aria-labelledby="titulo-lote-registro">
                <div class="adm-form-section__header"><div><h2 id="titulo-lote-registro">Lote</h2></div></div>
                <?php if ($loteAtivo): ?>
                    <p><strong><?= e(LoteRepository::nome($loteAtivo)) ?></strong> <?= lote_badge($loteAtivo['status']) ?></p>
                    <?= lote_progresso($loteAtivo) ?>
                <?php elseif ($lotesEmUso): ?>
                    <p class="adm-field__hint">Você não tem lote ativo. Os exemplares só podem ser registrados num lote aberto.</p>
                <?php else: ?>
                    <p class="adm-field__hint">O primeiro exemplar registrado abre automaticamente o seu lote de cadastramento.</p>
                <?php endif; ?>
                <?php if ($meusLotes && (count($meusLotes) > 1 || !$loteAtivo || (int)$loteAtivo['id'] !== (int)$meusLotes[0]['id'] || $semVagas)): ?>
                    <form method="post" class="adm-form-inline" data-loading-form>
                        <?= csrf_campo() ?>
                        <input type="hidden" name="livro_id" value="<?= (int)$livroId ?>">
                        <input type="hidden" name="acao" value="trocar_lote">
                        <label class="adm-field"><span class="adm-field__sublabel">Selecionar outro lote aberto</span>
                            <select class="adm-select" name="lote_id">
                                <?php foreach ($meusLotes as $ml): ?>
                                    <option value="<?= (int)$ml['id'] ?>"<?= $loteAtivo && (int)$loteAtivo['id'] === (int)$ml['id'] ? ' selected' : '' ?>><?= e(LoteRepository::nome($ml)) ?><?= $ml['capacidade'] !== null ? ' — ' . max(0, (int)$ml['capacidade'] - (int)$ml['total']) . ' vaga(s)' : '' ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('layers') ?> Usar este lote</button>
                        <p class="adm-field__hint">O formulário ao lado continua guardado no navegador.</p>
                    </form>
                <?php endif; ?>
            </section>
        </aside>
    </div>
<?php endif; ?>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/rascunhos.js?v=20261115', 'assets/js/gestao.js?v=20261118']));
