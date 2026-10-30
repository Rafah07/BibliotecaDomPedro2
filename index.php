<?php
/** Página inicial do site público. */
require_once __DIR__ . '/includes/layout.php';

$catalogo = catalogo();
$site = site_conteudo();
$recentes = $catalogo->recentes(8);
$estatisticas = $catalogo->estatisticas();
$categorias = $catalogo->categorias();
usort($categorias, static fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcoll($a['nome'], $b['nome']));
$categorias = array_slice($categorias, 0, 8);
$numero = static fn (int $n): string => number_format($n, 0, ',', '.');

page_start('', 'inicio', 'Biblioteca Dom Pedro II: pesquise o catálogo, veja a disponibilidade dos livros e conheça a biblioteca.');
?>
        <section class="hero" aria-label="Boas-vindas">
            <picture>
                <source media="(max-width: 700px)" srcset="<?= e(asset('img/banner-celular.jpg')) ?>">
                <img src="<?= e(asset('img/banner.jpg')) ?>" alt="Mesa com livros em uma biblioteca — Descubra novos mundos. Leia, aprenda e se inspire." width="1600" height="600" fetchpriority="high">
            </picture>
        </section>

        <section class="welcome-panel">
            <div class="welcome-copy">
                <p class="page-eyebrow">Bem-vindo à</p>
                <h1>Biblioteca Dom Pedro II</h1>
                <?php if ($site->preenchido('escola_nome')): ?><p class="welcome-school"><?= icon('school') ?> <?= e($site->valor('escola_nome')) ?></p><?php endif; ?>
                <p class="welcome-text"><?= nl2br(e($site->valor('boas_vindas'))) ?></p>
            </div>
            <form class="welcome-search" action="<?= e(url('biblioteca_php/catalogo.php')) ?>" method="get" role="search" data-loading-form>
                <label class="field field-grow" for="busca-inicio"><span>O que você procura?</span>
                    <div class="field-control with-icon"><?= icon('search') ?><input id="busca-inicio" type="search" name="q" placeholder="Título, autor, ISBN ou assunto" maxlength="120"></div>
                </label>
                <div class="welcome-actions">
                    <button class="action-button" type="submit"><?= icon('search') ?> Pesquisar</button>
                    <a class="ghost-button" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><?= icon('library-big') ?> Ver o catálogo completo</a>
                </div>
            </form>
        </section>

        <?= catalogo_aviso() ?>

        <?php if ($estatisticas): ?>
        <section class="stats-strip" aria-label="O acervo em números">
            <div><strong><?= $numero($estatisticas['titulos']) ?></strong><span><?= $estatisticas['titulos'] === 1 ? 'título no catálogo' : 'títulos no catálogo' ?></span></div>
            <div><strong><?= $numero($estatisticas['exemplares']) ?></strong><span>exemplares físicos identificados</span></div>
            <div><strong><?= $numero($estatisticas['disponiveis']) ?></strong><span>disponíveis para empréstimo agora</span></div>
        </section>
        <?php endif; ?>

        <section class="panel spaced-panel" aria-labelledby="titulo-recentes">
            <div class="section-topline">
                <div><p class="page-eyebrow">Novidades</p><h2 id="titulo-recentes">Adicionados recentemente</h2></div>
                <?php if ($recentes): ?><a class="inline-link" href="<?= e(url('biblioteca_php/catalogo.php?ordem=recentes')) ?>">Ver todos <?= icon('arrow-right') ?></a><?php endif; ?>
            </div>
            <?php if ($recentes): ?>
                <div class="books-grid catalog-books">
                    <?php foreach ($recentes as $livro): ?><?= book_card($livro) ?><?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><?= icon('inbox') ?><h3>Nenhum livro para mostrar ainda</h3><p>Os livros cadastrados pela biblioteca aparecerão aqui.</p></div>
            <?php endif; ?>
        </section>

        <div class="home-columns">
            <section class="panel" aria-labelledby="titulo-explorar">
                <div class="section-topline">
                    <div><p class="page-eyebrow">Explore</p><h2 id="titulo-explorar">Categorias do acervo</h2></div>
                    <?php if ($categorias): ?><a class="inline-link" href="<?= e(url('biblioteca_php/generos.php')) ?>">Todas <?= icon('arrow-right') ?></a><?php endif; ?>
                </div>
                <?php if ($categorias): ?>
                    <div class="filter-chips">
                        <?php foreach ($categorias as $c): ?>
                            <a class="chip" href="<?= e(url('biblioteca_php/catalogo.php?categoria=' . $c['id'])) ?>"><?= e($c['nome']) ?> <small><?= (int)$c['total'] ?></small></a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted-text">As categorias aparecerão quando os livros forem organizados pela biblioteca.</p>
                <?php endif; ?>
            </section>

            <section class="panel" aria-labelledby="titulo-biblioteca">
                <div class="section-topline">
                    <div><p class="page-eyebrow">A biblioteca</p><h2 id="titulo-biblioteca">Informações gerais</h2></div>
                </div>
                <ul class="info-list">
                    <li><?= icon('clock') ?><div><strong>Horário de funcionamento</strong><?= site_bloco('horario') ?></div></li>
                    <li><?= icon('book-open-check') ?><div><strong>Empréstimos</strong><p>Feitos presencialmente, no balcão da biblioteca. Não é preciso criar conta neste site.</p></div></li>
                </ul>
            </section>
        </div>

        <section class="shortcut-grid" aria-label="Atalhos">
            <a class="shortcut-card" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><?= icon('library-big') ?><strong>Catálogo</strong><span>Pesquise e filtre todos os livros</span></a>
            <a class="shortcut-card" href="<?= e(url('biblioteca_php/catalogo.php?disponibilidade=disponivel')) ?>"><?= icon('circle-check') ?><strong>Disponíveis agora</strong><span>Livros com exemplar na estante</span></a>
            <a class="shortcut-card" href="<?= e(url('biblioteca_php/sobre.php')) ?>"><?= icon('info') ?><strong>Sobre a biblioteca</strong><span>Espaço, objetivos e contato</span></a>
            <a class="shortcut-card" href="<?= e(url('biblioteca_php/regras.php')) ?>"><?= icon('file-text') ?><strong>Regras e funcionamento</strong><span>Como emprestar e devolver</span></a>
        </section>
<?php page_end(); ?>
