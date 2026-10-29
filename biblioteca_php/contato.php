<?php
require_once __DIR__ . '/../includes/layout.php';
page_start('Contato', 'contato');
page_heading('Estamos por aqui', 'Entre em contato', 'Tem uma dúvida sobre a biblioteca? Esta é uma prévia da futura página de contato.', 'mail');
?>
<div class="contact-layout">
    <section class="panel contact-panel"><p class="page-eyebrow">FALE COM A BIBLIOTECA</p><h2>Envie sua mensagem</h2><p class="contact-intro">O formulário abaixo é apenas ilustrativo. Nenhuma mensagem será enviada nesta versão.</p>
        <form class="contact-form" id="contact-demo-form">
            <label class="field"><span>Seu nome</span><input type="text" name="nome" placeholder="Digite seu nome" required maxlength="90"></label>
            <label class="field"><span>Seu e-mail</span><input type="email" name="email" placeholder="exemplo@email.com" required maxlength="180"></label>
            <label class="field"><span>Assunto</span><select name="assunto" required><option value="">Selecione um assunto</option><option>Dúvida sobre livros</option><option>Horários de atendimento</option><option>Sugestão</option><option>Outro assunto</option></select></label>
            <label class="field"><span>Mensagem</span><textarea rows="5" name="mensagem" placeholder="Escreva sua mensagem..." required maxlength="1000"></textarea></label>
            <button class="action-button" type="submit"><span class="material-symbols-outlined">send</span> Testar formulário</button>
            <p class="form-demo-message" id="form-demo-message" hidden role="status">Este é um formulário de demonstração. Nenhuma mensagem foi enviada.</p>
        </form>
    </section>
    <aside class="contact-aside"><section class="panel"><p class="page-eyebrow">CANAIS DE ATENDIMENTO</p><h2>Informações da biblioteca</h2><div class="contact-detail"><span class="material-symbols-outlined">location_on</span><div><strong>Localização</strong><p>Biblioteca da Escola Dom Pedro II</p><small>Endereço completo a confirmar</small></div></div><div class="contact-detail"><span class="material-symbols-outlined">alternate_email</span><div><strong>E-mail institucional</strong><p>Aguardando informação da escola</p></div></div><div class="contact-detail"><span class="material-symbols-outlined">call</span><div><strong>Telefone</strong><p>A definir</p></div></div><div class="contact-detail"><span class="material-symbols-outlined">schedule</span><div><strong>Horário de atendimento</strong><p>Consulte a página de horários</p><a href="<?= e(url('biblioteca_php/horarios.php')) ?>" class="inline-link">Ver horários →</a></div></div></section><section class="contact-note"><span class="material-symbols-outlined">info</span><p>As informações oficiais de contato serão inseridas quando fornecidas pela direção da escola.</p></section></aside>
</div>
<?php page_end(); ?>
