// Menu lateral: em telas largas começa aberto (ou como o visitante deixou); no celular, fica recolhido
// e abre como painel sobre o conteúdo (fecha com Esc, com o botão X, tocando fora ou ao escolher uma página).
document.querySelectorAll('.app-shell').forEach((shell) => {
    const sidebar = shell.querySelector('.sidebar');
    const sidebarToggle = shell.querySelector('.sidebar-toggle');
    const mobileToggle = shell.querySelector('.mobile-menu-button');
    const closeButton = shell.querySelector('.sidebar-close');
    const backdrop = shell.querySelector('.sidebar-backdrop');
    const isMobile = () => window.matchMedia('(max-width: 860px)').matches;
    const lembrar = (valor) => { try { localStorage.setItem('bdp-menu', valor); } catch (e) { /* armazenamento bloqueado */ } };
    const preferencia = () => { try { return localStorage.getItem('bdp-menu'); } catch (e) { return null; } };

    function setExpanded(expanded) {
        const mobile = isMobile();
        shell.classList.toggle('sidebar-collapsed', !expanded);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', String(expanded));
            sidebarToggle.setAttribute('aria-label', expanded ? 'Recolher menu' : 'Expandir menu');
        }
        if (mobileToggle) {
            mobileToggle.setAttribute('aria-expanded', String(expanded && mobile));
            mobileToggle.setAttribute('aria-label', expanded && mobile ? 'Fechar menu' : 'Abrir menu');
        }
        if (backdrop) backdrop.hidden = !expanded || !mobile;
        // No celular, o menu fechado sai da ordem de tabulação; aberto, recebe o papel de diálogo.
        if (sidebar) {
            sidebar.inert = mobile && !expanded;
            if (mobile && expanded) { sidebar.setAttribute('role', 'dialog'); sidebar.setAttribute('aria-modal', 'true'); }
            else { sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal'); }
        }
        document.body.classList.toggle('mobile-menu-open', expanded && mobile);
    }

    function fecharCelular(devolverFoco) {
        setExpanded(false);
        if (devolverFoco) mobileToggle?.focus();
    }

    sidebarToggle?.addEventListener('click', () => {
        const expandir = shell.classList.contains('sidebar-collapsed');
        setExpanded(expandir);
        if (!isMobile()) lembrar(expandir ? 'aberto' : 'fechado');
    });
    mobileToggle?.addEventListener('click', () => {
        if (!shell.classList.contains('sidebar-collapsed')) { fecharCelular(false); return; }
        setExpanded(true);
        // Espera o painel ficar visível para mover o foco para a página atual do menu.
        window.setTimeout(() => (shell.querySelector('.sidebar-nav a[aria-current="page"]') || shell.querySelector('.sidebar-nav a'))?.focus(), 50);
    });
    closeButton?.addEventListener('click', () => fecharCelular(true));
    backdrop?.addEventListener('click', () => fecharCelular(true));
    shell.querySelectorAll('.sidebar-nav a').forEach((link) => link.addEventListener('click', () => {
        if (isMobile()) setExpanded(false);
    }));
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isMobile() && !shell.classList.contains('sidebar-collapsed')) {
            fecharCelular(true);
        }
        // Mantém o foco dentro do menu aberto no celular.
        if (event.key === 'Tab' && isMobile() && !shell.classList.contains('sidebar-collapsed') && sidebar) {
            const focaveis = [...sidebar.querySelectorAll('a[href], button:not([disabled])')].filter((el) => el.offsetParent !== null);
            if (!focaveis.length) return;
            const primeiro = focaveis[0];
            const ultimo = focaveis[focaveis.length - 1];
            if (event.shiftKey && document.activeElement === primeiro) { event.preventDefault(); ultimo.focus(); }
            else if (!event.shiftKey && document.activeElement === ultimo) { event.preventDefault(); primeiro.focus(); }
        }
    });
    const estadoInicial = () => (!isMobile() && window.matchMedia('(min-width: 1280px)').matches ? preferencia() !== 'fechado' : !isMobile() && preferencia() === 'aberto');
    let eraCelular = isMobile();
    window.addEventListener('resize', () => {
        const agora = isMobile();
        if (agora !== eraCelular) {
            eraCelular = agora;
            setExpanded(estadoInicial());
        }
    });
    // Evita a animação do menu no carregamento da página.
    setExpanded(estadoInicial());
    requestAnimationFrame(() => shell.classList.add('is-ready'));
});

// Indicador de carregamento nas pesquisas (computadores mais lentos): evita cliques repetidos.
document.querySelectorAll('form[data-loading-form], form.search-bar, form.hero-search').forEach((form) => {
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

// Catálogo: no computador o painel de filtros já começa aberto; no celular fica recolhido.
document.querySelectorAll('details[data-open-desktop]').forEach((painel) => {
    if (window.matchMedia('(min-width: 861px)').matches) painel.open = true;
});
