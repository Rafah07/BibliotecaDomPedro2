<?php
/**
 * Regras e funcionamento. As orientações de como consultar, emprestar, devolver e conservar
 * descrevem o funcionamento do sistema (empréstimo presencial, registrado pela equipe).
 * Prazos, regras e limites só aparecem quando aprovados e preenchidos no painel.
 * Não há reservas nem pedidos de empréstimo pelo site.
 */
require_once __DIR__ . '/../includes/layout.php';

$site = site_conteudo();
$prazo = $site->prazoEmprestimo();

page_start('Regras e funcionamento', 'regras', 'Como consultar o catálogo, pegar livros emprestados e devolvê-los na Biblioteca Dom Pedro II.');
page_heading('Como funciona', 'Regras e funcionamento', 'Tudo o que você precisa saber para usar a biblioteca.', 'file-text');
?>
<nav class="guide-nav" aria-label="Nesta página">
    <a href="#consultar"><?= icon('search') ?> Consultar</a>
    <a href="#emprestimo"><?= icon('book-open-check') ?> Empréstimo</a>
    <a href="#devolucao"><?= icon('rotate-ccw') ?> Devolução</a>
    <a href="#conservacao"><?= icon('heart') ?> Conservação</a>
    <a href="#regras-gerais"><?= icon('file-text') ?> Regras gerais</a>
</nav>

<section class="steps-grid" aria-label="Passo a passo">
    <article class="step-card" id="consultar">
        <div class="step-head"><span class="step-number">1</span><span class="step-icon"><?= icon('search') ?></span></div>
        <h2>Consulte o catálogo</h2>
        <ul>
            <li>Use a busca do <a href="<?= e(url('biblioteca_php/catalogo.php')) ?>">catálogo</a> por título, autor, ISBN ou assunto. Acentos são opcionais.</li>
            <li>Use os filtros para ver uma categoria, um período de publicação ou só os livros disponíveis agora.</li>
            <li>Abra o livro para ver os detalhes e quantos exemplares estão disponíveis.</li>
        </ul>
    </article>
    <article class="step-card" id="emprestimo">
        <div class="step-head"><span class="step-number">2</span><span class="step-icon"><?= icon('book-open-check') ?></span></div>
        <h2>Peça o empréstimo no balcão</h2>
        <ul>
            <li>O empréstimo é feito <strong>presencialmente</strong>, com a equipe da biblioteca, que registra a saída do livro no sistema.</li>
            <li>Não é preciso criar conta neste site, e não há reservas ou pedidos de empréstimo pela internet.</li>
            <?php if ($prazo !== null): ?>
                <li>Prazo de empréstimo: <strong><?= $prazo ?> <?= $prazo === 1 ? 'dia' : 'dias' ?></strong>. A data de devolução é informada no momento do empréstimo.</li>
            <?php else: ?>
                <li>A data de devolução é informada pela equipe no momento do empréstimo.</li>
            <?php endif; ?>
        </ul>
        <?php if ($site->preenchido('emprestimo_info')): ?><div class="rich-text"><?= site_texto_html($site->valor('emprestimo_info')) ?></div><?php endif; ?>
    </article>
    <article class="step-card" id="devolucao">
        <div class="step-head"><span class="step-number">3</span><span class="step-icon"><?= icon('rotate-ccw') ?></span></div>
        <h2>Devolva no prazo</h2>
        <ul>
            <li>Entregue o livro diretamente à equipe da biblioteca, que registra a devolução no sistema.</li>
            <li>Assim que a devolução é registrada, o exemplar volta a aparecer como disponível no catálogo.</li>
        </ul>
        <?php if ($site->preenchido('devolucao_info')): ?><div class="rich-text"><?= site_texto_html($site->valor('devolucao_info')) ?></div><?php endif; ?>
    </article>
</section>

<section class="contact-layout spaced-panel">
    <article class="info-panel" id="conservacao">
        <div class="info-panel-head"><span class="info-card-icon"><?= icon('heart') ?></span><div><p class="page-eyebrow">Cuidados</p><h2>Conservação dos livros</h2></div></div>
        <ul class="check-list">
            <li><?= icon('check') ?> Mantenha o livro longe de água, comida e bebidas.</li>
            <li><?= icon('check') ?> Use um marcador de páginas em vez de dobrar as folhas.</li>
            <li><?= icon('check') ?> Não escreva, risque ou cole nada no livro.</li>
            <li><?= icon('check') ?> Transporte o livro protegido, sem apertá-lo na mochila.</li>
            <li><?= icon('check') ?> Se o livro for danificado ou perdido, avise a equipe da biblioteca.</li>
        </ul>
    </article>
    <article class="info-panel" id="regras-gerais">
        <div class="info-panel-head"><span class="info-card-icon"><?= icon('file-text') ?></span><div><p class="page-eyebrow">Regulamento</p><h2>Regras gerais da biblioteca</h2></div></div>
        <?= site_bloco('regras_gerais') ?>
    </article>
</section>
<?php page_end(); ?>
