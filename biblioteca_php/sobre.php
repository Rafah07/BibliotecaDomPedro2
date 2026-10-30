<?php
/**
 * Página institucional. Os textos vêm do painel (Sistema → Site público); campos não preenchidos
 * aparecem como "aguardando confirmação da escola" — nada é inventado.
 */
require_once __DIR__ . '/../includes/layout.php';

$site = site_conteudo();
$contatos = array_filter([
    'map-pin' => ['Localização', $site->valor('endereco')],
    'phone' => ['Telefone', $site->valor('telefone')],
    'mail' => ['E-mail', $site->valor('email')],
], static fn ($c) => $c[1] !== '');

page_start('Sobre a biblioteca', 'sobre', 'Conheça a Biblioteca Dom Pedro II: apresentação, espaço, objetivos, horários e contato.');
page_heading('Nossa biblioteca', 'Sobre a Biblioteca Dom Pedro II', $site->valor('escola_nome'), 'info');
?>
<section class="about-hero panel">
    <div class="about-copy">
        <p class="page-eyebrow">Apresentação</p>
        <h2>Um espaço para ler, estudar e descobrir</h2>
        <?= site_bloco('apresentacao') ?>
        <a class="action-button" href="<?= e(url('biblioteca_php/catalogo.php')) ?>"><?= icon('library-big') ?> Consultar o catálogo</a>
    </div>
    <div class="about-emblem"><img src="<?= e(asset('img/emblema-pequeno.png')) ?>" alt="Emblema da escola" width="360" height="240" loading="lazy"></div>
</section>

<section class="info-card-grid spaced-panel">
    <article class="info-card"><?= icon('landmark') ?><h2>O espaço</h2><?= site_bloco('espaco') ?></article>
    <article class="info-card"><?= icon('target') ?><h2>Objetivos da biblioteca escolar</h2><?= site_bloco('objetivos') ?></article>
    <article class="info-card"><?= icon('lightbulb') ?><h2>Orientações de uso</h2><?= site_bloco('orientacoes') ?></article>
</section>

<section class="contact-layout spaced-panel">
    <article class="panel" id="horario">
        <div class="section-topline"><div><p class="page-eyebrow">Atendimento</p><h2>Horário de funcionamento</h2></div></div>
        <?= site_bloco('horario') ?>
    </article>
    <article class="panel" id="contato">
        <div class="section-topline"><div><p class="page-eyebrow">Fale com a biblioteca</p><h2>Contato</h2></div></div>
        <?php if ($contatos): ?>
            <?php foreach ($contatos as $icone => [$rotulo, $valor]): ?>
                <div class="contact-detail"><?= icon($icone) ?><div><strong><?= e($rotulo) ?></strong>
                    <p><?= $icone === 'mail' ? '<a href="mailto:' . e($valor) . '">' . e($valor) . '</a>' : e($valor) ?></p></div></div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="pending-note"><?= icon('clock') ?> Os contatos oficiais da biblioteca ainda não foram informados pela escola.</p>
        <?php endif; ?>
        <?php if ($site->preenchido('contato_observacao')): ?><div class="rich-text"><?= site_texto_html($site->valor('contato_observacao')) ?></div><?php endif; ?>
        <p class="muted-text">Para dúvidas sobre livros e empréstimos, procure a equipe pessoalmente na biblioteca.</p>
    </article>
</section>
<?php page_end(); ?>
