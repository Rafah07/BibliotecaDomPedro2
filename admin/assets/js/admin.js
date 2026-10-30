// Interações gerais do painel administrativo (menu, dropdowns, tooltips e avisos).
(function () {
    const root = document.documentElement;
    const body = document.body;
    const sidebar = document.getElementById('adm-sidebar');
    const backdrop = document.querySelector('.adm-backdrop');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const mobileQuery = window.matchMedia('(max-width: 900px)');
    const tooltip = document.querySelector('.adm-tooltip');
    const STORAGE_KEY = 'adm-sidebar';

    const svg = (paths) => '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    const icons = {
        info: svg('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>'),
        success: svg('<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'),
        warning: svg('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'),
        error: svg('<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>'),
    };

    // ---------- Avisos (toasts) ----------
    const toastArea = document.querySelector('.adm-toasts');
    // tone: info | success | warning | error
    function showToast(title, message, tone = 'info') {
        if (!toastArea) return;
        const toast = document.createElement('div');
        toast.className = 'adm-toast adm-toast--' + (icons[tone] ? tone : 'info');
        toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        toast.innerHTML = (icons[tone] || icons.info) + '<div><strong></strong><span></span></div>';
        toast.querySelector('strong').textContent = title;
        toast.querySelector('span').textContent = message;
        toastArea.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('is-leaving');
            setTimeout(() => toast.remove(), 250);
        }, tone === 'error' || tone === 'warning' ? 6500 : 4200);
    }
    window.admToast = showToast;

    // ---------- Menu lateral ----------
    function setExpanded(expanded, save = true) {
        root.classList.toggle('adm-sidebar-expanded', expanded);
        if (toggle) {
            toggle.setAttribute('aria-expanded', String(expanded));
            toggle.setAttribute('aria-label', expanded ? 'Recolher menu' : 'Expandir menu');
        }
        if (save) {
            try { localStorage.setItem(STORAGE_KEY, expanded ? 'expanded' : 'collapsed'); } catch (e) { /* armazenamento indisponível */ }
        }
        hideTooltip();
    }

    function setMobileOpen(open) {
        body.classList.toggle('adm-mobile-open', open);
        if (backdrop) backdrop.hidden = !open;
        document.querySelectorAll('[data-mobile-open]').forEach((btn) => btn.setAttribute('aria-expanded', String(open)));
        if (open) {
            sidebar?.querySelector('.adm-nav__link.is-active, .adm-nav__link')?.focus({ preventScroll: true });
        }
    }

    toggle?.addEventListener('click', () => setExpanded(!root.classList.contains('adm-sidebar-expanded')));
    if (toggle) setExpanded(root.classList.contains('adm-sidebar-expanded'), false);

    document.querySelectorAll('[data-mobile-open]').forEach((btn) => btn.addEventListener('click', () => setMobileOpen(true)));
    document.querySelectorAll('[data-mobile-close]').forEach((el) => el.addEventListener('click', () => setMobileOpen(false)));
    mobileQuery.addEventListener('change', () => setMobileOpen(false));

    // ---------- Tooltips do menu recolhido ----------
    function showTooltip(target) {
        if (!tooltip || mobileQuery.matches || root.classList.contains('adm-sidebar-expanded')) return;
        const rect = target.getBoundingClientRect();
        tooltip.textContent = target.dataset.tooltip;
        tooltip.style.left = rect.right + 12 + 'px';
        tooltip.style.top = rect.top + rect.height / 2 + 'px';
        tooltip.hidden = false;
    }
    function hideTooltip() {
        if (tooltip) tooltip.hidden = true;
    }
    document.querySelectorAll('[data-tooltip]').forEach((el) => {
        el.addEventListener('mouseenter', () => showTooltip(el));
        el.addEventListener('focus', () => showTooltip(el));
        el.addEventListener('mouseleave', hideTooltip);
        el.addEventListener('blur', hideTooltip);
    });
    sidebar?.querySelector('.adm-nav')?.addEventListener('scroll', hideTooltip, { passive: true });

    // ---------- Menus suspensos ----------
    const dropdowns = document.querySelectorAll('.adm-dropdown');
    function closeDropdowns(except) {
        dropdowns.forEach((dropdown) => {
            if (dropdown === except) return;
            dropdown.classList.remove('is-open');
            dropdown.querySelector('[data-dropdown-toggle]')?.setAttribute('aria-expanded', 'false');
        });
    }
    dropdowns.forEach((dropdown) => {
        const button = dropdown.querySelector('[data-dropdown-toggle]');
        button?.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = !dropdown.classList.contains('is-open');
            closeDropdowns(dropdown);
            dropdown.classList.toggle('is-open', open);
            button.setAttribute('aria-expanded', String(open));
        });
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.adm-dropdown')) closeDropdowns();
    });

    // ---------- Teclado ----------
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDropdowns();
            if (body.classList.contains('adm-mobile-open')) {
                setMobileOpen(false);
                document.querySelector('[data-mobile-open]')?.focus();
            }
        }
        // Atalho "/" para focar a pesquisa, como em outras plataformas administrativas.
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '');
        if (event.key === '/' && !typing) {
            const input = document.querySelector('.adm-search input');
            if (input) {
                event.preventDefault();
                input.focus();
            }
        }
    });

    // ---------- Data atual ----------
    document.querySelectorAll('[data-today]').forEach((el) => {
        const now = new Date();
        try {
            el.textContent = now.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        } catch (e) { /* mantém a data gerada pelo PHP */ }
        const pad = (n) => String(n).padStart(2, '0');
        el.setAttribute('datetime', `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`);
    });
})();
