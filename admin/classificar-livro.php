<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/cdd.php';

$usuario = cdd_pagina_iniciar('cdd.ver');
$id = (int)($_GET['id'] ?? 0);
$item = cdd_item($id);

admin_page_start('classificacao', $item ? 'Classificar: ' . $item['titulo'] : 'Livro não encontrado', 'Acervo', 'Reclassificação bibliográfica pela CDD', [
    'assets/css/acervo.css?v=20261027',
    'assets/css/classificacao.css?v=20261027',
]);
echo acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Classificação CDD', admin_url('classificacao')], ['Revisão individual']]);

if (!$item): ?>
    <section class="adm-card adm-wip">
        <span class="adm-icon-box adm-icon-box--lg adm-tone--amber"><?= icon('book-x') ?></span>
        <h1>Livro não encontrado</h1>
        <p>O cadastro solicitado não existe ou foi arquivado.</p>
        <div class="adm-wip__actions">
            <a class="adm-button adm-button--primary" href="<?= htmlspecialchars(admin_url('classificacao')) ?>"><?= icon('arrow-left') ?> Voltar à classificação</a>
        </div>
    </section>
    <?php
    admin_page_end();
    return;
endif;

$c = $item['class'];
$legado = $item['legado'];
$pesquisa = cdd_ultima_pesquisa($id);
$historico = cdd_historico(['livro_id' => $id, 'limite' => 8]);
$proximo = cdd_proximo_livro($id);
$podeSugerir = auth_pode('cdd.sugerir');
$podeAprovar = auth_pode('cdd.aprovar');
$fontes = cdd_fontes_automaticas();
$termos = cdd_termos_livro($item);
$v = fn (?string $valor) => htmlspecialchars((string)$valor);
$responsavel = $c['sugerido_por'] ? cdd_usuario_nome((int)$c['sugerido_por']) : $usuario['nome'];
$livroAcervo = db_modo() === 'demo' ? acervo_livro($id) : null;
$totalExemplares = db_modo() === 'mysql'
    ? (int)db_query('SELECT COUNT(*) FROM exemplares WHERE livro_id = ? AND situacao <> "baixado"', [$id])->fetchColumn()
    : (int)($livroAcervo['total_exemplares'] ?? 0);

$meta = [
    'ISBN' => $item['isbn'],
    'Editora' => $item['editora'],
    'Ano' => $item['ano'],
    'Edição' => $item['edicao'],
    'Assunto' => $item['assunto'],
    'Categoria' => $item['categoria'],
];
?>

<section class="adm-card adm-book-hero adm-book-hero--cdd">
    <div class="adm-book-hero__cover"><?= acervo_capa(['titulo' => $item['titulo'], 'capa' => $item['capa'], 'cor' => $item['cor'], 'autor_principal' => $item['autor']], 'lg') ?></div>
    <div class="adm-book-hero__body">
        <div class="adm-book-hero__tags" data-status-tags>
            <?= cdd_badge_status($c['status'], (bool)$c['rascunho']) ?>
            <?php if ($c['precisa_revisao']): ?><span class="adm-chip adm-chip--warn">Precisa de revisão</span><?php endif; ?>
            <?php if ($c['cdd_aprovada']): ?><span class="adm-chip">CDD aprovada: <strong class="adm-mono"><?= $v($c['cdd_aprovada']) ?></strong></span><?php endif; ?>
        </div>
        <h1><?= $v($item['titulo']) ?></h1>
        <?php if ($item['subtitulo']): ?><p class="adm-book-hero__subtitle"><?= $v($item['subtitulo']) ?></p><?php endif; ?>
        <p class="adm-book-hero__author">por <strong><?= $v($item['autores'] ?: 'Autor não informado') ?></strong></p>
        <dl class="adm-meta">
            <?php foreach ($meta as $rotulo => $valor): ?>
                <div>
                    <dt><?= $rotulo ?></dt>
                    <dd<?= $rotulo === 'ISBN' ? ' class="adm-mono"' : '' ?>><?= $valor !== null && $valor !== '' ? $v((string)$valor) : '<span class="adm-missing">Não informado</span>' ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <?php if ($item['sinopse']): ?>
            <details class="adm-sinopse">
                <summary>Sinopse</summary>
                <p class="adm-prose"><?= nl2br($v($item['sinopse'])) ?></p>
            </details>
        <?php endif; ?>
        <div class="adm-book-hero__actions">
            <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('detalhes-livro') . '?id=' . $id) ?>"><?= icon('book-open') ?> Ficha do livro</a>
            <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('classificacao')) ?>"><?= icon('arrow-left') ?> Voltar ao painel</a>
            <?php if ($proximo): ?>
                <a class="adm-button adm-button--ghost" href="<?= htmlspecialchars(admin_url('classificar-livro') . '?id=' . $proximo) ?>"><?= icon('skip-forward') ?> Pular para o próximo</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="adm-form-layout adm-cdd-layout">
    <div class="adm-form-layout__main">
        <?php if ($podeSugerir): ?>
        <section class="adm-card adm-panel" aria-labelledby="titulo-pesquisa" data-pesquisa-livro>
            <div class="adm-form-section__header">
                <span class="adm-step">1</span>
                <div>
                    <h2 id="titulo-pesquisa">Pesquisar classificação</h2>
                    <p>Consulta fontes bibliográficas e mostra apenas as CDDs que elas informam.</p>
                </div>
            </div>
            <form class="adm-cdd-search" data-pesquisa-form novalidate>
                <details class="adm-termos">
                    <summary>Ajustar termos da pesquisa</summary>
                    <div class="adm-form-grid adm-form-grid--3">
                        <?php foreach (['isbn' => 'ISBN', 'titulo' => 'Título', 'autor' => 'Autor', 'editora' => 'Editora', 'ano' => 'Ano', 'edicao' => 'Edição', 'assunto' => 'Assunto'] as $campo => $rotulo): ?>
                            <label class="adm-field<?= $campo === 'assunto' ? ' adm-field--full' : '' ?>">
                                <span class="adm-field__label"><?= $rotulo ?></span>
                                <input class="adm-input<?= $campo === 'isbn' ? ' adm-mono' : '' ?>" name="termos[<?= $campo ?>]" value="<?= $v($termos[$campo]) ?>" maxlength="200">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
                <div class="adm-cdd-search__bar">
                    <div class="adm-fontes-inline">
                        <?php foreach ($fontes as $fid => $f): ?>
                            <label title="<?= $v($f['descricao']) ?>"><input type="checkbox" name="fontes[]" value="<?= $fid ?>" checked> <?= $v($f['nome']) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="adm-button adm-button--primary"<?= $fontes ? '' : ' disabled' ?>><?= icon('search') ?> Pesquisar classificação</button>
                </div>
                <?php if (!empty(cdd_fontes_ativas()['webdewey'])): ?>
                    <p class="adm-side-note"><?= icon('info') ?> WebDewey está habilitado como fonte licenciada: consulte em <a class="adm-link" href="<?= CDD_FONTES['webdewey']['site'] ?>" target="_blank" rel="noopener noreferrer">WebDewey</a> e registre o número manualmente abaixo.</p>
                <?php endif; ?>
            </form>
            <div class="adm-cdd-results" data-resultados aria-live="polite"></div>
        </section>
        <?php endif; ?>

        <section class="adm-card adm-panel" aria-labelledby="titulo-nova">
            <div class="adm-form-section__header">
                <span class="adm-step"><?= $podeSugerir ? 2 : 1 ?></span>
                <div>
                    <h2 id="titulo-nova">Nova classificação</h2>
                    <p>Registre a CDD com a fonte. Um formato correto não garante que a classe seja adequada ao assunto.</p>
                </div>
            </div>
            <form class="adm-cdd-form" data-classificacao-form data-livro-id="<?= $id ?>" novalidate>
                <input type="hidden" name="versao" value="<?= (int)$c['versao'] ?>">
                <input type="hidden" name="consulta_id" value="<?= $v((string)$c['consulta_id']) ?>">
                <div class="adm-notice adm-notice--red" role="alert" data-form-errors hidden>
                    <?= icon('circle-alert') ?>
                    <div><strong data-form-errors-text>Verifique os campos destacados.</strong></div>
                </div>
                <fieldset class="adm-fieldset"<?= $podeSugerir ? '' : ' disabled' ?>>
                    <div class="adm-form-grid">
                        <label class="adm-field">
                            <span class="adm-field__label">CDD proposta <span class="adm-required" aria-hidden="true">*</span></span>
                            <input class="adm-input adm-input--cdd" name="cdd" value="<?= $v($c['cdd_proposta']) ?>" maxlength="50" placeholder="Ex.: 869.3" autocomplete="off" inputmode="decimal" data-cdd-input aria-describedby="cdd-feedback">
                            <span class="adm-field__hint" id="cdd-feedback" data-cdd-feedback>Três dígitos e decimais opcionais após o ponto. Zeros à esquerda são mantidos.</span>
                            <span class="adm-field__error" data-error-for="cdd"></span>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field__label">Descrição da classificação</span>
                            <input class="adm-input" name="descricao" value="<?= $v($c['descricao']) ?>" maxlength="255" placeholder="Ex.: Ficção em língua portuguesa">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field__label">Assunto principal</span>
                            <input class="adm-input" name="assunto_principal" value="<?= $v($c['assunto_principal'] ?? $item['assunto']) ?>" maxlength="255">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field__label">Fonte bibliográfica utilizada <span class="adm-required" aria-hidden="true">*</span></span>
                            <input class="adm-input" name="fonte_nome" value="<?= $v($c['fonte_nome']) ?>" maxlength="255" list="fontes-cdd" placeholder="Ex.: Ficha catalográfica (CIP) impressa no livro">
                            <datalist id="fontes-cdd">
                                <?php foreach (CDD_FONTES_MANUAIS as $fonteManual): ?><option value="<?= $v($fonteManual) ?>"><?php endforeach; ?>
                            </datalist>
                            <span class="adm-field__error" data-error-for="fonte_nome"></span>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field__label">Link para a fonte <span class="adm-optional">opcional</span></span>
                            <input class="adm-input" type="url" name="fonte_url" value="<?= $v($c['fonte_url']) ?>" maxlength="2000" placeholder="https://">
                            <span class="adm-field__error" data-error-for="fonte_url"></span>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field__label">Data da consulta</span>
                            <input class="adm-input" type="date" name="data_consulta" value="<?= $v($c['data_consulta'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
                            <span class="adm-field__error" data-error-for="data_consulta"></span>
                        </label>
                        <div class="adm-field">
                            <span class="adm-field__label">Responsável pela sugestão</span>
                            <p class="adm-readonly"><?= icon('user-round') ?> <?= $v($responsavel) ?><?= $c['sugerido_em'] ? ' · ' . cdd_data_hora($c['sugerido_em']) : '' ?></p>
                        </div>
                        <div class="adm-field">
                            <span class="adm-field__label">Situação da revisão</span>
                            <p class="adm-readonly" data-status-text><?= cdd_badge_status($c['status'], (bool)$c['rascunho']) ?></p>
                        </div>
                        <label class="adm-field adm-field--full">
                            <span class="adm-field__label">Justificativa</span>
                            <textarea class="adm-textarea" name="justificativa" rows="3" maxlength="5000" placeholder="Por que esta classe é adequada ao livro? Obrigatória ao corrigir uma sugestão."><?= $v($c['justificativa']) ?></textarea>
                            <span class="adm-field__error" data-error-for="justificativa"></span>
                        </label>
                        <label class="adm-field adm-field--full">
                            <span class="adm-field__label">Observações <span class="adm-optional">opcional</span></span>
                            <textarea class="adm-textarea" name="observacoes" rows="2" maxlength="5000"><?= $v($c['observacoes']) ?></textarea>
                        </label>
                    </div>

                    <div class="adm-salvar-como" role="radiogroup" aria-label="Salvar como">
                        <span class="adm-field__label">Salvar como</span>
                        <label class="adm-radio-pill"><input type="radio" name="salvar_como" value="rascunho"> Rascunho</label>
                        <label class="adm-radio-pill"><input type="radio" name="salvar_como" value="enviar_revisao" checked> Em revisão</label>
                        <label class="adm-radio-pill<?= $podeAprovar ? '' : ' is-disabled' ?>" title="<?= $podeAprovar ? '' : 'Somente a bibliotecária ou um administrador pode aprovar' ?>">
                            <input type="radio" name="salvar_como" value="aprovar"<?= $podeAprovar ? '' : ' disabled' ?>> Aprovado <?= $podeAprovar ? '' : icon('lock') ?>
                        </label>
                    </div>
                </fieldset>

                <div class="adm-form-actions adm-cdd-actions">
                    <?php if ($podeSugerir): ?>
                        <button type="button" class="adm-button adm-button--ghost" data-acao="sugerir"><?= icon('book-marked') ?> Registrar sugestão</button>
                        <button type="button" class="adm-button adm-button--ghost" data-acao="enviar_revisao"><?= icon('send') ?> Enviar para revisão</button>
                    <?php endif; ?>
                    <?php if ($podeAprovar): ?>
                        <button type="button" class="adm-button adm-button--ghost adm-button--danger-text" data-acao="rejeitar"<?= $c['cdd_proposta'] && $c['status'] !== 'aprovada' ? '' : ' hidden' ?>><?= icon('circle-x') ?> Rejeitar sugestão</button>
                        <button type="button" class="adm-button adm-button--success" data-acao="aprovar"><?= icon('check-check') ?> Aprovar CDD</button>
                    <?php endif; ?>
                    <?php if ($podeSugerir): ?>
                        <button type="button" class="adm-button adm-button--primary" data-acao="salvar_proximo"><?= icon('save') ?> Salvar e próximo livro</button>
                    <?php endif; ?>
                </div>
                <?php if (!$podeAprovar && $podeSugerir): ?>
                    <p class="adm-form-actions__note"><?= icon('lock') ?> A aprovação fica disponível apenas para a bibliotecária ou um administrador.</p>
                <?php elseif (!$podeSugerir): ?>
                    <p class="adm-form-actions__note"><?= icon('lock') ?> Seu perfil permite apenas consultar.</p>
                <?php endif; ?>
            </form>
        </section>
    </div>

    <aside class="adm-form-layout__side">
        <section class="adm-card adm-panel adm-legacy" aria-labelledby="titulo-legado">
            <h2 class="adm-subtitle" id="titulo-legado"><?= icon('lock') ?> Dados antigos</h2>
            <dl class="adm-meta adm-meta--stack adm-meta--boxed">
                <div><dt>CDD original</dt><dd><?= cdd_numero($c['cdd_original'] ?? ($legado['cdd_original'] ?? null), 'Não informada') ?>
                    <?php $orig = $c['cdd_original'] ?? ($legado['cdd_original'] ?? null); if ($orig !== null && !cdd_formato_valido($orig)): ?><small class="adm-num__hint">Fora do formato da CDD</small><?php endif; ?></dd></div>
                <div><dt>Código de autor antigo</dt><dd><?= !empty($legado['codigo_autor_antigo']) ? '<span class="adm-mono">' . $v($legado['codigo_autor_antigo']) . '</span>' : '<span class="adm-missing">Não informado</span>' ?></dd></div>
                <div><dt>Código de localização antigo</dt><dd><?= !empty($legado['localizacao_antiga']) ? $v($legado['localizacao_antiga']) : '<span class="adm-missing">Não informado</span>' ?></dd></div>
                <div><dt>Categoria original</dt><dd><?= !empty($legado['categoria_original']) ? $v($legado['categoria_original']) : '<span class="adm-missing">Não informada</span>' ?></dd></div>
                <div><dt>Fonte da importação</dt><dd><?= $legado ? $v($legado['arquivo'] ?? '—') . (!empty($legado['linha']) ? ' (linha ' . (int)$legado['linha'] . ')' : '') . (!empty($legado['importado_em']) ? '<br><small>' . cdd_data_hora($legado['importado_em']) . '</small>' : '') : '<span class="adm-missing">Cadastrado sem planilha</span>' ?></dd></div>
            </dl>
            <p class="adm-side-note"><?= icon('shield-check') ?> Preservados como vieram da planilha. O sistema não altera nem apaga estes dados.</p>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-situacao">
            <h2 class="adm-subtitle" id="titulo-situacao"><?= icon('book-marked') ?> Classificação vigente</h2>
            <?php if ($c['cdd_aprovada']): ?>
                <p class="adm-cdd-big"><?= $v($c['cdd_aprovada']) ?></p>
                <dl class="adm-meta adm-meta--stack">
                    <div><dt>Aprovada por</dt><dd><?= $v(cdd_usuario_nome((int)$c['aprovado_por'])) ?></dd></div>
                    <div><dt>Data da aprovação</dt><dd><?= cdd_data_hora($c['aprovado_em']) ?></dd></div>
                    <div><dt>Fonte</dt><dd><?= $v($c['aprovada_fonte'] ?? $c['fonte_nome']) ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="adm-missing">Classificação pendente de revisão.</p>
            <?php endif; ?>
            <p class="adm-side-note"><?= icon('barcode') ?> <?= $totalExemplares ?> exemplar(es) identificado(s). A classificação não depende do inventário, e os códigos BDP não mudam quando a CDD é corrigida.</p>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-hist">
            <h2 class="adm-subtitle" id="titulo-hist"><?= icon('history') ?> Histórico</h2>
            <?php if (!$historico): ?>
                <p class="adm-missing">Nenhuma alteração registrada.</p>
            <?php else: ?>
                <ol class="adm-timeline adm-timeline--compact">
                    <?php foreach ($historico as $h): ?>
                        <?php $tipo = CDD_HISTORICO_TIPOS[$h['tipo']]; ?>
                        <li>
                            <span class="adm-icon-box adm-icon-box--xs adm-tone--<?= $tipo['tom'] ?>"><?= icon($tipo['icone']) ?></span>
                            <div>
                                <p><strong><?= $tipo['label'] ?></strong><?php if ($h['cdd_nova'] || $h['cdd_anterior']): ?> · <span class="adm-mono"><?= $v($h['cdd_anterior'] ?? '—') ?> → <?= $v($h['cdd_nova'] ?? '—') ?></span><?php endif; ?></p>
                                <?php if ($h['observacao'] || $h['justificativa']): ?><p class="adm-timeline__desc"><?= $v($h['justificativa'] ?: $h['observacao']) ?></p><?php endif; ?>
                                <small><?= cdd_data_hora($h['criado_em']) ?> · <?= $v($h['responsavel'] ?? '—') ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <a class="adm-link" href="<?= htmlspecialchars(admin_url('historico-cdd') . '?livro=' . $id) ?>">Ver histórico completo <?= icon('arrow-right') ?></a>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php
echo cdd_json_script([
    'pagina' => 'livro',
    'livroId' => $id,
    'proximoUrl' => $proximo ? admin_url('classificar-livro') . '?id=' . $proximo : null,
    'pesquisa' => $pesquisa ? [
        'resultado' => $pesquisa['resultado'], 'mensagem' => $pesquisa['mensagem'], 'fontes' => $pesquisa['fontes'], 'consultado_em' => cdd_data_hora($pesquisa['consultado_em']),
        'consultas' => array_map(fn ($q) => [
            'id' => (int)$q['id'], 'fonte_nome' => $q['fonte_nome'], 'registro_url' => $q['registro_url'], 'titulo' => $q['titulo'], 'autor' => $q['autor'], 'editora' => $q['editora'],
            'ano' => $q['ano'], 'edicao' => $q['edicao'], 'isbn' => $q['isbn'], 'cdd' => $q['cdd'], 'ddc_edicao' => $q['ddc_edicao'], 'lcc' => $q['lcc'],
            'compatibilidade' => $q['compatibilidade'], 'compat_label' => CDD_COMPATIBILIDADE[$q['compatibilidade']]['label'], 'compat_tom' => CDD_COMPATIBILIDADE[$q['compatibilidade']]['tom'], 'motivos' => $q['motivos'],
        ], $pesquisa['consultas']),
    ] : null,
]);
admin_page_end(['assets/js/acervo.js?v=20261027', 'assets/js/classificacao.js?v=20261027']);
