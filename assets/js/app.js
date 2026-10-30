// Menu lateral: em telas largas começa aberto (ou como o visitante deixou); no celular, fica recolhido.
document.querySelectorAll('.app-shell').forEach((shell) => {
    const sidebarToggle = shell.querySelector('.sidebar-toggle');
    const mobileToggle = shell.querySelector('.mobile-menu-button');
    const backdrop = shell.querySelector('.sidebar-backdrop');
    const isMobile = () => window.matchMedia('(max-width: 860px)').matches;
    const lembrar = (valor) => { try { localStorage.setItem('bdp-menu', valor); } catch (e) { /* armazenamento bloqueado */ } };
    const preferencia = () => { try { return localStorage.getItem('bdp-menu'); } catch (e) { return null; } };

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

    sidebarToggle?.addEventListener('click', () => {
        const expandir = shell.classList.contains('sidebar-collapsed');
        setExpanded(expandir);
        if (!isMobile()) lembrar(expandir ? 'aberto' : 'fechado');
    });
    mobileToggle?.addEventListener('click', () => {
        setExpanded(true);
        shell.querySelector('.sidebar-nav a')?.focus();
    });
    backdrop?.addEventListener('click', () => setExpanded(false));
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isMobile() && !shell.classList.contains('sidebar-collapsed')) {
            setExpanded(false);
            mobileToggle?.focus();
        }
    });
    window.addEventListener('resize', () => {
        if (backdrop) backdrop.hidden = shell.classList.contains('sidebar-collapsed') || !isMobile();
    });
    setExpanded(!isMobile() && window.matchMedia('(min-width: 1280px)').matches ? preferencia() !== 'fechado' : !isMobile() && preferencia() === 'aberto');
});

// Indicador de carregamento nas pesquisas (computadores mais lentos): evita cliques repetidos.
document.querySelectorAll('form[data-loading-form], form.search-bar').forEach((form) => {
    form.addEventListener('submit', () => {
        const botao = form.querySelector('button[type="submit"]');
        if (botao) {
            botao.classList.add('is-loading');
            botao.setAttribute('aria-busy', 'true');
        }
    });
});

// Campos vazios não vão para a URL do catálogo (endereços mais curtos e fáceis de compartilhar).
document.querySelectorAll('form.catalog-form').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelectorAll('input, select').forEach((campo) => {
            const padrao = (campo.name === 'campo' && campo.value === 'tudo') || (campo.name === 'ordem' && campo.value === 'titulo');
            if (campo.name && (campo.value === '' || padrao)) campo.disabled = true;
        });
    });
});

// Ao voltar pelo histórico do navegador, reativa os campos e botões.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('.is-loading').forEach((b) => { b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); });
    document.querySelectorAll('form.catalog-form [disabled]').forEach((c) => { c.disabled = false; });
});
