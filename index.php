<?php
/** Página inicial oficial do site (antes duplicada em Index/Index.php). */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/catalogo.php';

$catalogo = catalogo();
$demonstracao = $catalogo->ehDemonstracao();
$livros = $catalogo->destaques(4);

// Gêneros e autores com mais títulos. Na demonstração, os números são ilustrativos.
$generos = $catalogo->generos();
usort($generos, static fn ($a, $b) => $b['total'] <=> $a['total']);
$generos = array_slice($generos, 0, 6);
$totalGeneros = max(1, array_sum(array_column($generos, 'total')));
$autores = array_slice($catalogo->autores(), 0, 5);

page_start('', 'inicio');
?>
        <section class="hero"></section>

        <?= catalogo_aviso() ?>

        <section class="dashboard-grid">
            <section class="panel recommendations-panel">
                <div class="panel-header">
                    <div>
                        <span class="section-icon" aria-hidden="true">☆</span>
                        <h2><?= $demonstracao ? 'Recomendações da Biblioteca' : 'Chegaram ao acervo' ?></h2>
                    </div>
                    <a href="<?= e(url('biblioteca_php/catalogo.php')) ?>">Ver todos →</a>
                </div>

                <?php if ($livros): ?>
                    <div class="books-grid">
                        <?php foreach ($livros as $livro): ?>
                            <article class="book-card">
                                <?= book_cover($livro) ?>
                                <h3><?= e($livro['titulo']) ?></h3>
                                <p><?= e($livro['autor']) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><span class="material-symbols-outlined" aria-hidden="true">auto_stories</span><h3>Nenhum livro para mostrar ainda</h3><p>Os destaques aparecerão quando o acervo for cadastrado.</p></div>
                <?php endif; ?>
            </section>

            <div class="right-column">
                <section class="panel genres-panel">
                    <div class="panel-header compact">
                        <div>
                            <span class="material-symbols-outlined" aria-hidden="true">book</span>
                            <h2><?= $demonstracao ? 'Gêneros mais procurados' : 'Gêneros do acervo' ?></h2>
                        </div>
                        <a href="<?= e(url('biblioteca_php/generos.php')) ?>">Ver todos →</a>
                    </div>

                    <?php if ($generos): ?>
                        <div class="bars">
                            <?php foreach ($generos as $genero): ?>
                                <?php $pct = (int)round($genero['total'] / $totalGeneros * 100); ?>
                                <div class="bar-row">
                                    <span><?= e($genero['nome']) ?></span>
                                    <div class="bar-track"><div class="bar-fill" style="width: <?= min(100, $pct * 2) ?>%"></div></div>
                                    <strong><?= $pct ?>%</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($demonstracao): ?><p class="prototype-footnote">Percentuais calculados sobre os livros de exemplo.</p><?php endif; ?>
                    <?php else: ?>
                        <p class="prototype-footnote">Nenhum gênero cadastrado ainda.</p>
                    <?php endif; ?>
                </section>

                <section class="panel authors-panel">
                    <div class="panel-header compact">
                        <div>
                            <span class="section-icon" aria-hidden="true">●●</span>
                            <h2><?= $demonstracao ? 'Autores em destaque' : 'Autores do acervo' ?></h2>
                        </div>
                        <a href="<?= e(url('biblioteca_php/autores.php')) ?>">Ver todos →</a>
                    </div>

                    <?php if ($autores): ?>
                        <ol class="authors-list">
                            <?php foreach ($autores as $indice => $autor): ?>
                                <li>
                                    <span class="ranking"><?= $indice + 1 ?></span>
                                    <span class="avatar"><?= e(initial($autor['nome'])) ?></span>
                                    <strong><?= e($autor['nome']) ?></strong>
                                    <span class="search-count"><?= (int)$autor['total'] ?> <?= (int)$autor['total'] === 1 ? 'título' : 'títulos' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="prototype-footnote">Nenhum autor cadastrado ainda.</p>
                    <?php endif; ?>
                </section>
            </div>
        </section>
<?php page_end(); ?>
