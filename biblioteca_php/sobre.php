<?php
require_once __DIR__ . '/../includes/layout.php';
page_start('Sobre', 'sobre');
page_heading('Nossa biblioteca', 'Um espaço para descobrir', 'Conheça a proposta da Biblioteca Dom Pedro II e o que estamos construindo.', 'info');
?>
<section class="about-hero panel">
    <div class="about-copy"><p class="page-eyebrow">BIBLIOTECA DOM PEDRO II</p><h2>Mais histórias, mais descobertas.</h2><p>Este site foi pensado para aproximar a comunidade escolar dos livros e tornar a busca por novas leituras mais simples e agradável.</p><p>Aqui você poderá explorar o catálogo, conhecer diferentes gêneros e descobrir autores.</p><a class="action-button" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><span class="material-symbols-outlined">menu_book</span> Conhecer o catálogo</a></div>
    <div class="about-emblem"><img src="<?= e(asset('img/emblema.png')) ?>" alt="Emblema da escola"></div>
</section>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">O que queremos oferecer</p><h2>Uma biblioteca mais acessível</h2></div></div>
    <div class="info-card-grid">
        <article class="info-card"><span class="material-symbols-outlined">search</span><h3>Busca fácil</h3><p>Pesquise títulos, autores e gêneros para encontrar livros com rapidez.</p></article>
        <article class="info-card"><span class="material-symbols-outlined">favorite</span><h3>Incentivo à leitura</h3><p>Descubra livros, explore novos assuntos e encontre sugestões de leitura.</p></article>
        <article class="info-card"><span class="material-symbols-outlined">groups</span><h3>Comunidade escolar</h3><p>Um espaço pensado para aproximar a escola, a biblioteca e os leitores.</p></article>
    </div>
</section>
<section class="secondary-banner"><span class="material-symbols-outlined">lightbulb</span><div><h2>Site em desenvolvimento</h2><p>Esta versão apresenta apenas o design inicial. Informações e recursos definitivos serão adicionados após a validação com a escola.</p></div></section>
<?php page_end(); ?>
