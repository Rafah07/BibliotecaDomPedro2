<?php
/**
 * Central de Ajuda — Sistema → Configurações → Tutorial do Sistema.
 *
 * Endereços:
 *   ajuda.php                      página principal (pesquisa e módulos)
 *   ajuda.php?modulo=emprestimos   tutoriais de um módulo
 *   ajuda.php?t=registrar-emprestimo   um tutorial
 *   ajuda.php?q=emprestimo         pesquisa (com e sem acentos)
 *   &voltar=novo-emprestimo.php    página do painel de onde a pessoa veio (validada em ajuda_voltar())
 *
 * Aberta a todos os perfis autenticados (painel.ver), inclusive a quem não pode alterar as
 * configurações. Só lê os arquivos de admin/includes/ajuda/: não consulta nem grava dados
 * do acervo, dos alunos ou dos empréstimos e não executa nenhuma operação administrativa.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ajuda.php';
require_once __DIR__ . '/includes/acervo.php';

auth_exigir('painel.ver');

$modulos = ajuda_modulos();
$q = mb_substr(admin_param($_GET, 'q'), 0, 80);
$tutorial = ajuda_tutorial(admin_param($_GET, 't'));
$modulo = $modulos[admin_param($_GET, 'modulo')] ?? null;
if ($tutorial) {
    $modulo = $modulos[$tutorial['modulo']];
}
$voltar = ajuda_voltar($_GET['voltar'] ?? null);
$naoEncontrado = (admin_param($_GET, 't') !== '' && !$tutorial) || (admin_param($_GET, 'modulo') !== '' && !$modulo);
if ($naoEncontrado) {
    http_response_code(404);
}
$resultados = $q !== '' && !$tutorial ? ajuda_pesquisar($q) : null;

$tituloPagina = $tutorial['titulo'] ?? ($resultados !== null ? 'Pesquisa na ajuda' : ($modulo['nome'] ?? 'Central de Ajuda'));
$sugestoes = ['Empréstimo', 'Devolução', 'Renovação', 'Aluno', 'Exemplar', 'BDP', 'Inventário', 'CDD', 'Etiqueta', 'Relatório', 'Backup'];
$primeiros = array_filter(array_map('ajuda_tutorial', ['o-que-e-o-sistema', 'menu-lateral', 'registrar-emprestimo', 'registrar-devolucao', 'codigos-bdp', 'faq-funcao-nao-aparece']));

/** Selo de permissão de um tutorial para o perfil atual. */
$seloPermissao = function (array $t): string {
    if (empty($t['permissao'])) {
        return '';
    }
    return auth_pode($t['permissao'])
        ? '<span class="adm-status adm-tone--green ajd-selo">' . icon('check') . ' Disponível para o seu perfil</span>'
        : '<span class="adm-status adm-tone--amber ajd-selo">' . icon('lock') . ' Seu perfil não tem esta função</span>';
};

/** Cartão de tutorial usado nas listas. */
$cartao = function (array $t, bool $comModulo = false) use ($modulos, $seloPermissao): string {
    $m = $modulos[$t['modulo']];
    return '<li class="ajd-item"><a class="ajd-item__link" href="' . e(ajuda_url(['t' => $t['id']])) . '">'
        . '<span class="ajd-item__icone adm-icon-box adm-icon-box--sm adm-tone--blue">' . icon($m['icone']) . '</span>'
        . '<span class="ajd-item__texto"><strong>' . e($t['titulo']) . '</strong>'
        . ($comModulo ? '<small class="ajd-item__modulo">' . e($m['nome']) . '</small>' : '')
        . '<span class="ajd-item__resumo">' . e($t['resumo'] ?? '') . '</span></span>'
        . icon('chevron-right', 'ajd-item__seta') . '</a>' . $seloPermissao($t) . '</li>';
};

$migalhas = [['Administração', admin_url('dashboard')], ['Configurações', auth_pode('configuracoes.ver') ? admin_url('configuracoes') : null], ['Tutorial do Sistema', ($tutorial || $modulo || $resultados !== null) ? ajuda_url() : null]];
if ($modulo) {
    $migalhas[] = [$modulo['nome'], $tutorial ? ajuda_url(['modulo' => $modulo['id']]) : null];
}
if ($tutorial) {
    $migalhas[] = [$tutorial['titulo']];
}

admin_page_start('ajuda', $tituloPagina . ' — Central de Ajuda', 'Central de Ajuda', 'Tutorial do Sistema', ['assets/css/acervo.css?v=20261114', 'assets/css/ajuda.css?v=20261103e11']);
?>

<?= acervo_breadcrumb($migalhas) ?>

<?php if ($voltar): ?>
    <div class="ajd-origem" role="note">
        <?= icon('info') ?>
        <span>Você abriu a ajuda a partir da página <strong><?= e($voltar[1]) ?></strong>.</span>
        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_base() . $voltar[0]) ?>"><?= icon('arrow-left') ?> Voltar para <?= e($voltar[1]) ?></a>
    </div>
<?php endif; ?>

<section class="ajd-hero<?= ($tutorial || $modulo) ? ' ajd-hero--compacto' : '' ?>" aria-labelledby="ajd-titulo">
    <div class="ajd-hero__texto">
        <p class="ajd-hero__eyebrow"><?= icon('circle-help') ?> Tutorial do Sistema</p>
        <?php if (!$tutorial && !$modulo && $resultados === null): ?>
            <h1 id="ajd-titulo">Central de Ajuda</h1>
            <p>Aprenda a utilizar as ferramentas da Biblioteca Dom Pedro II com tutoriais passo a passo.</p>
        <?php else: ?>
            <p class="ajd-hero__nome" id="ajd-titulo">Central de Ajuda</p>
        <?php endif; ?>
    </div>
    <form class="ajd-busca" role="search" method="get" action="<?= e(admin_url('ajuda')) ?>">
        <?php if ($voltar): ?><input type="hidden" name="voltar" value="<?= e($voltar[0]) ?>"><?php endif; ?>
        <label class="ajd-busca__campo">
            <?= icon('search') ?>
            <span class="adm-visually-hidden">Pesquisar na Central de Ajuda</span>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar uma dúvida ou funcionalidade..." autocomplete="off" maxlength="80"<?= (!$tutorial && !$modulo) ? ' autofocus' : '' ?>>
        </label>
        <button class="adm-button adm-button--primary" type="submit"><?= icon('search') ?> <span>Pesquisar</span></button>
    </form>
    <?php if (!$tutorial && !$modulo): ?>
        <p class="ajd-sugestoes"><span>Sugestões:</span>
            <?php foreach ($sugestoes as $s): ?>
                <a class="ajd-chip" href="<?= e(ajuda_url(['q' => $s])) ?>"><?= e($s) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</section>

<?php if ($naoEncontrado): ?>
    <?= admin_aviso('aviso', 'Tutorial não encontrado.', 'O endereço pode estar desatualizado. Pesquise o assunto acima ou escolha um módulo abaixo.') ?>
<?php endif; ?>

<?php if ($tutorial): ?>
    <?php
    $t = $tutorial;
    $lista = array_values($modulo['tutoriais']);
    $posicao = array_search($t['id'], array_column($lista, 'id'), true);
    $anterior = $lista[$posicao - 1] ?? null;
    $proximo = $lista[$posicao + 1] ?? null;
    $secoes = array_filter([
        'objetivo' => !empty($t['objetivo']) ? 'Objetivo' : null,
        'quando' => !empty($t['quando']) ? 'Quando utilizar' : null,
        'requisitos' => (!empty($t['requisitos']) || !empty($t['permissao'])) ? 'Pré-requisitos' : null,
        'passos' => !empty($t['passos']) ? 'Passo a passo' : null,
        'exemplo' => !empty($t['exemplo']) ? 'Exemplo prático' : null,
        'resultado' => !empty($t['resultado']) ? 'Resultado esperado' : null,
        'problemas' => !empty($t['problemas']) ? 'Problemas frequentes' : null,
        'cuidados' => !empty($t['cuidados']) ? 'Cuidados importantes' : null,
        'relacionados' => !empty($t['relacionados']) ? 'Próximos passos' : null,
    ]);
    // A = título; as seções seguem a ordem B a J do modelo de tutorial.
    $letras = range('B', 'Z');
    $n = 0;
    ?>
    <div class="ajd-layout">
        <article class="adm-card ajd-artigo" aria-labelledby="ajd-tutorial-titulo">
            <header class="ajd-artigo__topo">
                <p class="ajd-artigo__modulo"><a class="adm-link" href="<?= e(ajuda_url(['modulo' => $modulo['id']])) ?>"><?= icon($modulo['icone']) ?> <?= e($modulo['nome']) ?></a> · Tutorial <?= $posicao + 1 ?> de <?= count($lista) ?></p>
                <h1 id="ajd-tutorial-titulo"><?= e($t['titulo']) ?></h1>
                <?php if (!empty($t['resumo'])): ?><p class="ajd-artigo__resumo"><?= ajuda_formatar($t['resumo']) ?></p><?php endif; ?>
                <div class="ajd-artigo__acoes">
                    <?= $seloPermissao($t) ?>
                    <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-ajuda-imprimir><?= icon('printer') ?> Imprimir tutorial</button>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(ajuda_url(['modulo' => $modulo['id']])) ?>"><?= icon('list') ?> Voltar à lista</a>
                </div>
            </header>

            <?php if (!empty($t['objetivo'])): ?>
                <section class="ajd-secao" id="objetivo">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Objetivo</h2>
                    <p><?= ajuda_formatar($t['objetivo']) ?></p>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['quando'])): ?>
                <section class="ajd-secao" id="quando">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Quando utilizar</h2>
                    <ul class="ajd-lista"><?php foreach ($t['quando'] as $item): ?><li><?= ajuda_formatar($item) ?></li><?php endforeach; ?></ul>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['requisitos']) || !empty($t['permissao'])): ?>
                <section class="ajd-secao" id="requisitos">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Pré-requisitos</h2>
                    <?php if (!empty($t['permissao'])): ?>
                        <?php $pode = auth_pode($t['permissao']); ?>
                        <div class="ajd-permissao ajd-permissao--<?= $pode ? 'ok' : 'nao' ?>">
                            <?= icon($pode ? 'shield-check' : 'lock') ?>
                            <div>
                                <strong>Permissão necessária: <?= e(ajuda_permissao_nome($t['permissao'])) ?></strong>
                                <p>Perfis que têm esta permissão: <?= e(implode(', ', ajuda_perfis_com($t['permissao']))) ?>.
                                    <?= $pode ? 'O seu perfil pode realizar este procedimento.' : 'O seu perfil não tem esta permissão: leia o tutorial para entender o processo e peça a uma pessoa autorizada para executá-lo.' ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($t['requisitos'])): ?>
                        <ul class="ajd-lista ajd-lista--check"><?php foreach ($t['requisitos'] as $item): ?><li><?= icon('square-check') ?><span><?= ajuda_formatar($item) ?></span></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['passos'])): ?>
                <section class="ajd-secao" id="passos">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Passo a passo</h2>
                    <ol class="ajd-passos">
                        <?php foreach ($t['passos'] as $i => $p): ?>
                            <?php $p = is_array($p) ? $p : ['texto' => $p]; ?>
                            <li class="ajd-passo" id="passo-<?= $i + 1 ?>">
                                <span class="ajd-passo__numero" aria-hidden="true"><?= $i + 1 ?></span>
                                <div class="ajd-passo__corpo">
                                    <?php if (!empty($p['titulo'])): ?><h3><span class="adm-visually-hidden">Passo <?= $i + 1 ?>: </span><?= ajuda_formatar($p['titulo']) ?></h3><?php endif; ?>
                                    <?php if (!empty($p['texto'])): ?><p><?= ajuda_formatar($p['texto']) ?></p><?php endif; ?>
                                    <?php if (!empty($p['lista'])): ?>
                                        <ul class="ajd-lista"><?php foreach ($p['lista'] as $item): ?><li><?= ajuda_formatar($item) ?></li><?php endforeach; ?></ul>
                                    <?php endif; ?>
                                    <?php if (!empty($p['dica'])): ?><p class="ajd-nota ajd-nota--dica"><?= icon('lightbulb') ?><span><strong>Dica:</strong> <?= ajuda_formatar($p['dica']) ?></span></p><?php endif; ?>
                                    <?php if (!empty($p['atencao'])): ?><p class="ajd-nota ajd-nota--atencao"><?= icon('triangle-alert') ?><span><strong>Atenção:</strong> <?= ajuda_formatar($p['atencao']) ?></span></p><?php endif; ?>
                                    <?php if (!empty($p['imagem']) && is_file(__DIR__ . '/' . AJUDA_IMAGENS . $p['imagem']['arquivo'])): ?>
                                        <figure class="ajd-figura">
                                            <a href="<?= e(admin_asset(AJUDA_IMAGENS . $p['imagem']['arquivo'])) ?>" target="_blank" rel="noopener" title="Abrir a imagem em tamanho original">
                                                <img src="<?= e(admin_asset(AJUDA_IMAGENS . $p['imagem']['arquivo'])) ?>" alt="<?= e($p['imagem']['alt']) ?>" loading="lazy" decoding="async"<?php $dim = @getimagesize(__DIR__ . '/' . AJUDA_IMAGENS . $p['imagem']['arquivo']); ?><?= $dim ? ' width="' . (int)$dim[0] . '" height="' . (int)$dim[1] . '"' : '' ?>>
                                            </a>
                                            <figcaption><?= ajuda_formatar($p['imagem']['legenda'] ?? $p['imagem']['alt']) ?> <span class="ajd-figura__nota">Captura com dados fictícios.</span></figcaption>
                                        </figure>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['exemplo'])): ?>
                <section class="ajd-secao" id="exemplo">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Exemplo prático</h2>
                    <div class="ajd-exemplo"><?= icon('sparkles') ?><p><?= ajuda_formatar($t['exemplo']) ?></p></div>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['resultado'])): ?>
                <section class="ajd-secao" id="resultado">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Resultado esperado</h2>
                    <ul class="ajd-lista ajd-lista--ok"><?php foreach ($t['resultado'] as $item): ?><li><?= icon('circle-check') ?><span><?= ajuda_formatar($item) ?></span></li><?php endforeach; ?></ul>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['problemas'])): ?>
                <section class="ajd-secao" id="problemas">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Problemas frequentes</h2>
                    <div class="ajd-problemas">
                        <?php foreach ($t['problemas'] as [$problema, $causa, $solucao]): ?>
                            <details class="ajd-problema">
                                <summary><?= icon('circle-alert') ?> <span><?= ajuda_formatar($problema) ?></span></summary>
                                <dl>
                                    <div><dt>Possível causa</dt><dd><?= ajuda_formatar($causa) ?></dd></div>
                                    <div><dt>Como resolver com segurança</dt><dd><?= ajuda_formatar($solucao) ?></dd></div>
                                </dl>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['cuidados'])): ?>
                <section class="ajd-secao" id="cuidados">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Cuidados importantes</h2>
                    <ul class="ajd-lista ajd-lista--cuidado"><?php foreach ($t['cuidados'] as $item): ?><li><?= icon('shield-alert') ?><span><?= ajuda_formatar($item) ?></span></li><?php endforeach; ?></ul>
                </section>
            <?php endif; ?>

            <?php if (!empty($t['relacionados'])): ?>
                <section class="ajd-secao" id="relacionados">
                    <h2><span class="ajd-letra"><?= $letras[$n++] ?></span> Próximos passos</h2>
                    <ul class="ajd-itens ajd-itens--compacto">
                        <?php foreach ($t['relacionados'] as $rid): ?>
                            <?php if ($r = ajuda_tutorial($rid)): ?><?= $cartao($r, $r['modulo'] !== $t['modulo']) ?><?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <nav class="ajd-paginacao" aria-label="Outros tutoriais deste módulo">
                <?php if ($anterior): ?>
                    <a class="ajd-paginacao__link" href="<?= e(ajuda_url(['t' => $anterior['id']])) ?>" rel="prev"><?= icon('chevron-left') ?><span><small>Anterior</small><?= e($anterior['titulo']) ?></span></a>
                <?php else: ?><span></span><?php endif; ?>
                <?php if ($proximo): ?>
                    <a class="ajd-paginacao__link ajd-paginacao__link--proximo" href="<?= e(ajuda_url(['t' => $proximo['id']])) ?>" rel="next"><span><small>Próximo</small><?= e($proximo['titulo']) ?></span><?= icon('chevron-right') ?></a>
                <?php endif; ?>
            </nav>
            <p class="ajd-impressao-rodape">Biblioteca Dom Pedro II · Central de Ajuda do painel · <?= e($modulo['nome']) ?> · impresso em <?= e(date('d/m/Y')) ?></p>
        </article>

        <aside class="ajd-lateral" aria-label="Navegação do tutorial">
            <nav class="adm-card ajd-indice" aria-labelledby="ajd-indice-titulo">
                <h2 id="ajd-indice-titulo"><?= icon('list-ordered') ?> Neste tutorial</h2>
                <ol>
                    <li><a href="#ajd-tutorial-titulo"><span>A</span> Título</a></li>
                    <?php $n = 0; foreach ($secoes as $ancora => $rotulo): ?>
                        <li><a href="#<?= e($ancora) ?>"><span><?= $letras[$n++] ?></span> <?= e($rotulo) ?></a></li>
                    <?php endforeach; ?>
                </ol>
                <?php if (count($t['passos'] ?? []) > 1): ?>
                    <p class="ajd-indice__etapas">Ir para a etapa:
                        <?php foreach ($t['passos'] as $i => $_): ?><a href="#passo-<?= $i + 1 ?>" aria-label="Passo <?= $i + 1 ?>"><?= $i + 1 ?></a><?php endforeach; ?>
                    </p>
                <?php endif; ?>
            </nav>
            <nav class="adm-card ajd-indice" aria-labelledby="ajd-modulo-titulo">
                <h2 id="ajd-modulo-titulo"><?= icon($modulo['icone']) ?> <?= e($modulo['nome']) ?></h2>
                <ol class="ajd-indice__tutoriais">
                    <?php foreach ($lista as $item): ?>
                        <li><a href="<?= e(ajuda_url(['t' => $item['id']])) ?>"<?= $item['id'] === $t['id'] ? ' aria-current="page"' : '' ?>><?= e($item['titulo']) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        </aside>
    </div>

<?php elseif ($resultados !== null): ?>
    <section class="adm-card adm-panel" aria-labelledby="ajd-resultados-titulo">
        <div class="adm-panel__header">
            <div>
                <h1 class="ajd-h2" id="ajd-resultados-titulo">Resultados para “<?= e($q) ?>”</h1>
                <p aria-live="polite"><?= count($resultados) === 1 ? '1 tutorial encontrado' : count($resultados) . ' tutoriais encontrados' ?>. A pesquisa ignora acentos e maiúsculas.</p>
            </div>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(ajuda_url()) ?>"><?= icon('filter-x') ?> Limpar pesquisa</a>
        </div>
        <?php if ($resultados): ?>
            <ul class="ajd-itens"><?php foreach ($resultados as $r): ?><?= $cartao($r['tutorial'], true) ?><?php endforeach; ?></ul>
        <?php else: ?>
            <div class="adm-empty">
                <?= icon('file-search') ?>
                <strong>Nenhum tutorial encontrado.</strong>
                <span>Use uma palavra mais simples (por exemplo, “emprestimo”, “aluno” ou “etiqueta”), confira a grafia ou navegue pelos módulos abaixo. Problemas e mensagens de erro estão em <a class="adm-link" href="<?= e(ajuda_url(['modulo' => 'problemas'])) ?>">Solução de problemas</a>.</span>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($modulo): ?>
    <section class="adm-card adm-panel" aria-labelledby="ajd-modulo-h1">
        <div class="ajd-modulo-topo">
            <span class="adm-icon-box adm-tone--blue"><?= icon($modulo['icone']) ?></span>
            <div>
                <h1 class="ajd-h2" id="ajd-modulo-h1"><?= e($modulo['nome']) ?></h1>
                <p><?= e($modulo['descricao']) ?> · <?= count($modulo['tutoriais']) ?> tutoriais</p>
            </div>
            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(ajuda_url()) ?>"><?= icon('arrow-left') ?> Todos os módulos</a>
        </div>
        <ul class="ajd-itens"><?php foreach ($modulo['tutoriais'] as $t): ?><?= $cartao($t) ?><?php endforeach; ?></ul>
    </section>
<?php endif; ?>

<?php if (!$tutorial && $resultados === null && !$modulo): ?>
    <section class="adm-card adm-panel ajd-comece" aria-labelledby="ajd-comece-titulo">
        <div class="adm-panel__header"><div><h2 id="ajd-comece-titulo">Comece por aqui</h2><p>Os tutoriais mais usados no dia a dia da biblioteca.</p></div></div>
        <ul class="ajd-itens ajd-itens--grade"><?php foreach ($primeiros as $t): ?><?= $cartao($t, true) ?><?php endforeach; ?></ul>
    </section>
<?php endif; ?>

<?php if (!$tutorial): ?>
    <section class="ajd-modulos-secao" aria-labelledby="ajd-modulos-titulo">
        <h2 class="ajd-h2" id="ajd-modulos-titulo"><?= $modulo || $resultados !== null ? 'Outros módulos' : 'Tutoriais por módulo' ?></h2>
        <ul class="ajd-modulos">
            <?php foreach ($modulos as $m): ?>
                <?php if ($modulo && $m['id'] === $modulo['id']) continue; ?>
                <li class="adm-card ajd-modulo">
                    <span class="adm-icon-box adm-tone--blue"><?= icon($m['icone']) ?></span>
                    <h3><?= e($m['nome']) ?></h3>
                    <p><?= e($m['descricao']) ?></p>
                    <p class="ajd-modulo__total"><?= count($m['tutoriais']) ?> <?= count($m['tutoriais']) === 1 ? 'tutorial' : 'tutoriais' ?></p>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(ajuda_url(['modulo' => $m['id']])) ?>" aria-label="Ver tutoriais de <?= e($m['nome']) ?>">Ver tutoriais <?= icon('arrow-right') ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="ajd-rodape"><?= icon('info') ?> <?= ajuda_total() ?> tutoriais em <?= count($modulos) ?> módulos. A ajuda funciona sem internet e não altera nenhum dado do sistema. Exemplos e capturas de tela usam somente dados fictícios.</p>
    </section>
<?php endif; ?>

<?php
admin_page_end(['assets/js/ajuda.js?v=20261103e11']);
