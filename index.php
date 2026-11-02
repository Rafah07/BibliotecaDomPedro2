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
        <section class="hero-banner" aria-labelledby="titulo-inicio">
            <svg class="hero-art" viewBox="0 0 520 360" aria-hidden="true" focusable="false">
                <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M60 250c60-26 120-26 180 0V96c-60-26-120-26-180 0z"/>
                    <path d="M240 250c60-26 120-26 180 0V96c-60-26-120-26-180 0z"/>
                    <path d="M88 132c40-12 80-12 120 0M88 164c40-12 80-12 120 0M88 196c40-12 80-12 120 0"/>
                    <path d="M272 132c40-12 80-12 120 0M272 164c40-12 80-12 120 0M272 196c40-12 80-12 120 0"/>
                    <path d="M30 300h460M70 318h380"/>
                    <path d="M452 60v190M470 76v174M488 92v158"/>
                </g>
            </svg>
            <div class="hero-copy">
                <p class="hero-kicker"><span class="hero-rule" aria-hidden="true"></span>Bem-vindo à Biblioteca Dom Pedro II</p>
                <h1 id="titulo-inicio" class="hero-title"><span class="visually-hidden">Biblioteca Dom Pedro II: </span>Seu próximo livro começa aqui.</h1>
                <p class="hero-text"><?= nl2br(e($site->valor('boas_vindas'))) ?></p>
                <form class="hero-search" action="<?= e(url('biblioteca_php/catalogo.php')) ?>" method="get" role="search" data-loading-form>
                    <label class="visually-hidden" for="busca-inicio">O que você procura? Título, autor, ISBN ou assunto</label>
                    <div class="hero-search-field"><?= icon('search') ?><input id="busca-inicio" type="search" name="q" placeholder="Título, autor, ISBN ou assunto" maxlength="120"></div>
                    <button class="btn btn-gold" type="submit"><?= icon('search') ?> Pesquisar</button>
                </form>
                <div class="hero-actions">
                    <a class="btn btn-on-dark" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><?= icon('library-big') ?> Ver o catálogo completo</a>
                    <?php if ($site->preenchido('escola_nome')): ?><p class="hero-school"><?= icon('school') ?> <?= e($site->valor('escola_nome')) ?></p><?php endif; ?>
                </div>
            </div>
            <div class="hero-emblem">
                <div class="hero-emblem-plate">
                    <img src="<?= e(asset('img/emblema-pequeno.png')) ?>" alt="Emblema da escola" width="360" height="240" fetchpriority="high">
                </div>
            </div>
        </section>

        <?= catalogo_aviso() ?>

        <section class="home-access" aria-labelledby="titulo-acesso">
            <h2 id="titulo-acesso" class="visually-hidden">Pesquisa e acesso ao catálogo</h2>
            <?php if ($estatisticas): ?>
            <dl class="stats-strip">
                <div><dt><?= $estatisticas['titulos'] === 1 ? 'título no catálogo' : 'títulos no catálogo' ?></dt><dd><?= $numero($estatisticas['titulos']) ?></dd></div>
                <div><dt>exemplares físicos identificados</dt><dd><?= $numero($estatisticas['exemplares']) ?></dd></div>
                <div><dt>disponíveis para empréstimo agora</dt><dd><?= $numero($estatisticas['disponiveis']) ?></dd></div>
            </dl>
            <?php endif; ?>
            <nav class="quick-links" aria-label="Acessos rápidos ao catálogo">
                <a class="quick-link" href="<?= e(url('biblioteca_php/catalogo.php?disponibilidade=disponivel')) ?>"><?= icon('circle-check') ?> Disponíveis agora</a>
                <a class="quick-link" href="<?= e(url('biblioteca_php/catalogo.php?ordem=recentes')) ?>"><?= icon('sparkles') ?> Mais recentes</a>
                <a class="quick-link" href="<?= e(url('biblioteca_php/generos.php')) ?>"><?= icon('tags') ?> Categorias</a>
                <a class="quick-link" href="<?= e(url('biblioteca_php/autores.php')) ?>"><?= icon('feather') ?> Autores</a>
            </nav>
        </section>

        <section class="home-section home-recentes" aria-labelledby="titulo-recentes">
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

        <section class="home-section" aria-labelledby="titulo-explorar">
            <div class="section-topline">
                <div><p class="page-eyebrow">Explore</p><h2 id="titulo-explorar">Categorias do acervo</h2></div>
                <?php if ($categorias): ?><a class="inline-link" href="<?= e(url('biblioteca_php/generos.php')) ?>">Todas as categorias <?= icon('arrow-right') ?></a><?php endif; ?>
            </div>
            <?php if ($categorias): ?>
                <div class="category-grid">
                    <?php foreach ($categorias as $c): ?>
                        <a class="category-card" href="<?= e(url('biblioteca_php/catalogo.php?categoria=' . $c['id'])) ?>">
                            <span class="category-icon"><?= icon($c['icon']) ?></span>
                            <span class="category-name"><?= e($c['nome']) ?></span>
                            <span class="category-count"><?= (int)$c['total'] ?> <?= (int)$c['total'] === 1 ? 'livro' : 'livros' ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted-text">As categorias aparecerão quando os livros forem organizados pela biblioteca.</p>
            <?php endif; ?>
        </section>

        <section class="home-about" aria-labelledby="titulo-biblioteca">
            <div class="home-about-copy">
                <p class="page-eyebrow">A biblioteca</p>
                <h2 id="titulo-biblioteca">Um espaço para ler, estudar e descobrir</h2>
                <?= site_bloco('apresentacao') ?>
                <a class="btn btn-secondary" href="<?= e(url('biblioteca_php/sobre.php')) ?>"><?= icon('info') ?> Conheça a biblioteca</a>
            </div>
            <ul class="info-list">
                <li><span class="info-list-icon"><?= icon('clock') ?></span><div><strong>Horário de funcionamento</strong><?= site_bloco('horario') ?></div></li>
                <li><span class="info-list-icon"><?= icon('book-open-check') ?></span><div><strong>Empréstimos</strong><p>Feitos presencialmente, no balcão da biblioteca. Não é preciso criar conta neste site.</p></div></li>
            </ul>
        </section>

        <section class="home-section" aria-labelledby="titulo-atalhos">
            <div class="section-topline"><div><p class="page-eyebrow">Atalhos</p><h2 id="titulo-atalhos">Para onde você quer ir?</h2></div></div>
            <div class="shortcut-grid">
                <a class="shortcut-card" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><span class="shortcut-icon"><?= icon('library-big') ?></span><strong>Catálogo</strong><span>Pesquise e filtre todos os livros</span></a>
                <a class="shortcut-card" href="<?= e(url('biblioteca_php/catalogo.php?disponibilidade=disponivel')) ?>"><span class="shortcut-icon"><?= icon('circle-check') ?></span><strong>Disponíveis agora</strong><span>Livros com exemplar na estante</span></a>
                <a class="shortcut-card" href="<?= e(url('biblioteca_php/sobre.php')) ?>"><span class="shortcut-icon"><?= icon('info') ?></span><strong>Sobre a biblioteca</strong><span>Espaço, objetivos e contato</span></a>
                <a class="shortcut-card" href="<?= e(url('biblioteca_php/regras.php')) ?>"><span class="shortcut-icon"><?= icon('file-text') ?></span><strong>Regras e funcionamento</strong><span>Como emprestar e devolver</span></a>
            </div>
        </section>
<?php page_end(); ?>
