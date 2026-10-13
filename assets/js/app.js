// Menu lateral: compacto até o usuário optar por expandir.
document.querySelectorAll('.app-shell').forEach((shell) => {
    const sidebarToggle = shell.querySelector('.sidebar-toggle');
    const mobileToggle = shell.querySelector('.mobile-menu-button');
    const backdrop = shell.querySelector('.sidebar-backdrop');
    const isMobile = () => window.matchMedia('(max-width: 860px)').matches;

    function setExpanded(expanded) {
        shell.classList.toggle('sidebar-collapsed', !expanded);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', String(expanded));
            sidebarToggle.setAttribute('aria-label', expanded ? 'Recolher menu' : 'Expandir menu');
        }
        if (mobileToggle) mobileToggle.setAttribute('aria-expanded', String(expanded));
        if (backdrop) backdrop.hidden = !expanded || !isMobile();
        document.body.classList.toggle('mobile-menu-open', expanded && isMobile());
    }

    sidebarToggle?.addEventListener('click', () => setExpanded(shell.classList.contains('sidebar-collapsed') === true ? true : false));
    mobileToggle?.addEventListener('click', () => setExpanded(true));
    backdrop?.addEventListener('click', () => setExpanded(false));
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !shell.classList.contains('sidebar-collapsed')) setExpanded(false);
    });
    window.addEventListener('resize', () => {
        if (backdrop) backdrop.hidden = shell.classList.contains('sidebar-collapsed') || !isMobile();
    });
    setExpanded(false);
});

// Abas visuais da página inicial.
document.querySelectorAll('.filter-tabs').forEach((container) => {
    container.querySelectorAll('.tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            container.querySelectorAll('.tab').forEach((item) => item.classList.remove('active'));
            tab.classList.add('active');
        });
    });
});

// O formulário de contato é apenas uma demonstração.
const demoForm = document.getElementById('contact-demo-form');
if (demoForm) {
    demoForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const notice = document.getElementById('form-demo-message');
        if (notice) notice.hidden = false;
    });
}
