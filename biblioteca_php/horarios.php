<?php
require_once __DIR__ . '/../includes/layout.php';
page_start('Horários', 'horarios');
page_heading('Planeje sua visita', 'Horários da biblioteca', 'Confira como os horários de funcionamento poderão ser apresentados.', 'schedule');
?>
<section class="schedule-banner"><div class="schedule-banner-icon"><span class="material-symbols-outlined">schedule</span></div><div><span class="feature-kicker">INFORMAÇÃO IMPORTANTE</span><h2>Horários aguardando confirmação</h2><p>Os horários oficiais serão preenchidos após a confirmação pela escola. Os campos abaixo são apenas o modelo da página.</p></div></section>
<section class="panel spaced-panel">
    <div class="section-topline"><div><p class="page-eyebrow">Funcionamento semanal</p><h2>Quadro de horários</h2></div><span class="muted-pill"><span class="material-symbols-outlined">pending</span> A confirmar</span></div>
    <div class="schedule-table-wrapper"><table class="schedule-table"><thead><tr><th>Dia da semana</th><th>Turno da manhã</th><th>Turno da tarde</th></tr></thead><tbody>
        <?php foreach (['Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira'] as $dia): ?>
            <tr><th scope="row"><span class="material-symbols-outlined">calendar_today</span> <?= htmlspecialchars($dia) ?></th><td><span class="pending-label">A definir</span></td><td><span class="pending-label">A definir</span></td></tr>
        <?php endforeach; ?>
        <tr><th scope="row"><span class="material-symbols-outlined">calendar_today</span> Sábado e domingo</th><td colspan="2"><span class="pending-label">Consultar a escola</span></td></tr>
    </tbody></table></div>
</section>
<section class="info-card-grid schedule-extras">
    <article class="info-card"><span class="material-symbols-outlined">library_books</span><h3>Empréstimos</h3><p>Prazos e regras de empréstimo serão informados pela biblioteca.</p></article>
    <article class="info-card"><span class="material-symbols-outlined">contact_support</span><h3>Precisa de ajuda?</h3><p>Entre em contato com a equipe responsável para tirar dúvidas sobre o atendimento.</p><a class="inline-link" href="<?= e(url('biblioteca_php/contato.php')) ?>">Ir para contato →</a></article>
</section>
<?php page_end(); ?>
