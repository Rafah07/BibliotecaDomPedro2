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
    // Computador: começa recolhido (só ícones). A escolha explícita de expandir/recolher
    // fica salva neste navegador; sem preferência salva, o menu continua recolhido.
    function setExpanded(expanded, save = true) {
        root.classList.toggle('adm-sidebar-expanded', expanded);
        if (toggle) {
            const label = expanded ? 'Recolher menu' : 'Expandir menu';
            toggle.setAttribute('aria-expanded', String(expanded));
            toggle.setAttribute('aria-label', label);
            toggle.dataset.tooltip = label;
        }
        if (save) {
            try { localStorage.setItem(STORAGE_KEY, expanded ? 'expanded' : 'collapsed'); } catch (e) { /* armazenamento indisponível */ }
        }
        hideTooltip();
    }

    // Celular e tablet: painel lateral sobreposto, com foco preso dentro dele enquanto aberto.
    const main = document.querySelector('.adm-main');
    let lastOpener = null;
    function focusables() {
        return Array.from(sidebar?.querySelectorAll('a[href], button:not([disabled])') || [])
            .filter((el) => el.offsetParent !== null);
    }
    function setMobileOpen(open, restoreFocus = true) {
        const wasOpen = body.classList.contains('adm-mobile-open');
        body.classList.toggle('adm-mobile-open', open);
        if (backdrop) backdrop.hidden = !open;
        if (main) {
            if (open) main.setAttribute('inert', '');
            else main.removeAttribute('inert');
        }
        document.querySelectorAll('[data-mobile-open]').forEach((btn) => {
            btn.setAttribute('aria-expanded', String(open));
            btn.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
        });
        if (open) {
            lastOpener = document.activeElement;
            // Espera o painel ficar visível para poder receber o foco.
            requestAnimationFrame(() => (sidebar?.querySelector('.adm-nav__link.is-active') || sidebar?.querySelector('.adm-sidebar__close'))?.focus({ preventScroll: true }));
        } else if (wasOpen && restoreFocus) {
            (lastOpener && document.contains(lastOpener) ? lastOpener : document.querySelector('[data-mobile-open]'))?.focus({ preventScroll: true });
        }
    }

    toggle?.addEventListener('click', () => setExpanded(!root.classList.contains('adm-sidebar-expanded')));
    if (toggle) setExpanded(root.classList.contains('adm-sidebar-expanded'), false);

    document.querySelectorAll('[data-mobile-open]').forEach((btn) => btn.addEventListener('click', () => setMobileOpen(!body.classList.contains('adm-mobile-open'))));
    document.querySelectorAll('[data-mobile-close]').forEach((el) => el.addEventListener('click', () => setMobileOpen(false)));
    mobileQuery.addEventListener('change', () => setMobileOpen(false, false));

    // No celular, escolher uma página fecha o painel (a navegação continua normalmente).
    sidebar?.querySelectorAll('a.adm-nav__link').forEach((link) => link.addEventListener('click', () => {
        if (mobileQuery.matches) setMobileOpen(false, false);
    }));

    // Tab e Shift+Tab circulam só pelos itens do painel enquanto ele está aberto.
    sidebar?.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab' || !body.classList.contains('adm-mobile-open')) return;
        const items = focusables();
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    // ---------- Tooltips do menu recolhido ----------
    function showTooltip(target) {
        if (!tooltip || mobileQuery.matches || (root.classList.contains('adm-sidebar-expanded') && !target.hasAttribute('data-sidebar-toggle'))) return;
        const rect = target.getBoundingClientRect();
        if (!rect.width) return;
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

    // ---------- Pesquisa do cabeçalho no celular ----------
    // Até 900 px a pesquisa fica recolhida num botão (o cabeçalho ocupa uma linha só).
    const buscaBtn = document.querySelector('[data-busca-topo]');
    const header = document.querySelector('.adm-header');
    function setBusca(aberta) {
        if (!buscaBtn || !header) return;
        header.classList.toggle('is-busca-aberta', aberta);
        buscaBtn.setAttribute('aria-expanded', String(aberta));
        buscaBtn.setAttribute('aria-label', aberta ? 'Fechar a pesquisa' : 'Pesquisar no acervo');
        if (aberta) header.querySelector('.adm-search input')?.focus();
    }
    buscaBtn?.addEventListener('click', () => setBusca(!header.classList.contains('is-busca-aberta')));
    header?.querySelector('.adm-search')?.addEventListener('focusout', (event) => {
        const campo = event.currentTarget.querySelector('input');
        // Fecha ao sair da pesquisa vazia (a lista de sugestões fica dentro do formulário).
        if (mobileQuery.matches && !event.currentTarget.contains(event.relatedTarget) && event.relatedTarget !== buscaBtn && campo && campo.value.trim() === '') setBusca(false);
    });
    mobileQuery.addEventListener('change', () => setBusca(false));

    // ---------- Ações do topo da página no celular ----------
    // Até 640 px, as ações secundárias de ".adm-page-head__actions" vão para um menu "Mais ações"
    // (sem empilhar quatro ou cinco botões largos antes do conteúdo). No computador, nada muda.
    // data-acoes-visiveis="N" define quantas ficam à mostra (padrão: 1).
    const acoesQuery = window.matchMedia('(max-width: 640px)');
    document.querySelectorAll('.adm-page-head__actions').forEach((caixa, n) => {
        const itens = Array.from(caixa.children);
        const visiveis = Math.max(0, parseInt(caixa.dataset.acoesVisiveis ?? '1', 10) || 0);
        if (itens.length - visiveis < 2) return; // um botão a mais cabe na linha: não vale um menu
        const mais = document.createElement('div');
        mais.className = 'adm-dropdown adm-acoes-mais';
        mais.innerHTML = '<button class="adm-button adm-button--ghost" type="button" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-controls="acoes-mais-' + n + '">'
            + '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>'
            + '<span>' + (visiveis ? 'Mais ações' : 'Ações') + '</span></button><div class="adm-dropdown__menu" id="acoes-mais-' + n + '"></div>';
        const menu = mais.querySelector('.adm-dropdown__menu');
        caixa.appendChild(mais);
        const organizar = () => {
            const celular = acoesQuery.matches;
            itens.slice(visiveis).forEach((el) => (celular ? menu : caixa).insertBefore(el, celular ? null : mais));
            mais.hidden = !celular;
            caixa.classList.toggle('has-mais', celular);
        };
        organizar();
        acoesQuery.addEventListener('change', organizar);
    });

    // ---------- Tabelas em cartões: "Mais detalhes" ----------
    // No celular, as células marcadas com .adm-td-sec ficam escondidas no cartão; este botão
    // mostra todas. No computador a célula do botão não aparece (a tabela já é completa).
    document.querySelectorAll('.adm-table--cards tbody tr').forEach((linha) => {
        if (!linha.querySelector(':scope > td.adm-td-sec')) return;
        const celula = document.createElement('td');
        celula.className = 'adm-td-mais';
        celula.innerHTML = '<button type="button" class="adm-td-mais__botao" aria-expanded="false"><span>Mais detalhes</span>'
            + '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>';
        const botao = celula.querySelector('button');
        botao.addEventListener('click', () => {
            const aberta = linha.classList.toggle('is-expandida');
            botao.setAttribute('aria-expanded', String(aberta));
            botao.querySelector('span').textContent = aberta ? 'Menos detalhes' : 'Mais detalhes';
        });
        // Antes da célula de ações (se houver), para as ações continuarem no fim do cartão.
        const acoes = linha.querySelector(':scope > td.adm-actions-col, :scope > td.adm-table__actions, :scope > td.adm-td-actions');
        linha.insertBefore(celula, acoes);
    });

    // ---------- Blocos recolhidos só no celular ----------
    // <details data-aberto-no-computador>: aberto no computador e no tablet, fechado no celular
    // (informação secundária não ocupa a área principal da tela pequena).
    if (!window.matchMedia('(max-width: 720px)').matches) {
        document.querySelectorAll('details[data-aberto-no-computador]').forEach((det) => { det.open = true; });
    }

    // ---------- Teclado virtual ----------
    // Com um campo de texto em foco no celular, a barra inferior some para não cobrir o campo
    // nem os resultados (o teclado já ocupa metade da tela).
    const digitavel = (el) => el && (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || (el.tagName === 'INPUT' && !/^(checkbox|radio|button|submit|reset|range|color|file|hidden)$/i.test(el.type)) || el.isContentEditable);
    document.addEventListener('focusin', (event) => body.classList.toggle('adm-digitando', mobileQuery.matches && digitavel(event.target)));
    document.addEventListener('focusout', () => window.setTimeout(() => {
        if (!digitavel(document.activeElement)) body.classList.remove('adm-digitando');
    }, 60));

    // ---------- Listas longas de opções com filtro (data-filtro-lista="#id") ----------
    // Ex.: usuários de um lote. O campo filtra as opções pelo texto, sem desmarcar nada.
    document.querySelectorAll('[data-filtro-lista]').forEach((campo) => {
        const lista = document.querySelector(campo.dataset.filtroLista);
        if (!lista) return;
        const contador = campo.dataset.filtroContador ? document.querySelector(campo.dataset.filtroContador) : null;
        const sem = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const contar = () => {
            if (contador) contador.textContent = String(lista.querySelectorAll('input:checked').length);
        };
        campo.addEventListener('input', () => {
            const termo = sem(campo.value.trim());
            lista.querySelectorAll('label').forEach((op) => { op.hidden = termo !== '' && !sem(op.textContent).includes(termo); });
        });
        lista.addEventListener('change', contar);
        contar();
    });

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
            if (header?.classList.contains('is-busca-aberta')) { setBusca(false); buscaBtn?.focus(); }
            if (body.classList.contains('adm-mobile-open')) setMobileOpen(false);
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

    // ---------- Listagens com paginação no servidor ----------
    // "Mostrar N por página" envia o formulário ao trocar a opção (sem JavaScript, há um botão).
    document.querySelectorAll('[data-auto-submit]').forEach((select) => {
        select.addEventListener('change', () => select.form?.requestSubmit ? select.form.requestSubmit() : select.form?.submit());
    });
    // Indica o carregamento enquanto a próxima página é buscada no servidor.
    const markLoading = () => {
        document.querySelectorAll('[data-loading-area]').forEach((area) => {
            area.classList.add('is-loading');
            area.setAttribute('aria-busy', 'true');
        });
    };
    document.querySelectorAll('[data-loading-form]').forEach((form) => form.addEventListener('submit', () => {
        form.setAttribute('aria-busy', 'true');
        markLoading();
    }));
    document.querySelectorAll('.adm-pagination a, a.adm-th-sort').forEach((link) => link.addEventListener('click', markLoading));
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) document.querySelectorAll('[data-loading-area].is-loading').forEach((area) => area.classList.remove('is-loading'));
    });

    // ---------- Confirmação de ações (botões com data-confirm) ----------
    document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));

    // ---------- Envio em andamento ----------
    // Depois do envio (formulário POST que navega), o botão mostra "processando" e um segundo
    // envio do mesmo formulário é ignorado. O botão NÃO é desativado: o navegador ainda envia
    // o nome/valor dele. Exportações (target) e downloads voltam ao normal sozinhos.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
        if ((form.method || '').toLowerCase() !== 'post' || form.target || form.hasAttribute('data-sem-ocupado')) return;
        const botao = event.submitter;
        if (botao && (botao.formTarget || botao.hasAttribute('formtarget') || botao.formNoValidate && botao.dataset.gerarCodigo)) return;
        const propria = form.hasAttribute('data-enviar-uma-vez'); // inventario.js já protege estes
        if (!propria && form.dataset.admOcupado === '1') { event.preventDefault(); return; }
        // Decide depois dos outros scripts: se algum enviou em segundo plano (preventDefault), não marca.
        window.setTimeout(() => {
            if (event.defaultPrevented) return;
            if (!propria) form.dataset.admOcupado = '1';
            form.setAttribute('aria-busy', 'true');
            if (botao && botao.classList.contains('adm-button')) botao.classList.add('is-busy');
            window.setTimeout(() => {
                delete form.dataset.admOcupado;
                form.removeAttribute('aria-busy');
                botao?.classList.remove('is-busy');
            }, 8000);
        }, 0);
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.querySelectorAll('form[data-adm-ocupado], form[aria-busy]').forEach((f) => { delete f.dataset.admOcupado; f.removeAttribute('aria-busy'); });
        document.querySelectorAll('.adm-button.is-busy').forEach((b) => b.classList.remove('is-busy'));
    });

    // ---------- Detalhes que lembram se ficaram abertos (data-lembrar) ----------
    const lembrarDetalhes = (raiz) => raiz.querySelectorAll('details[data-lembrar]').forEach((det) => {
        const chave = 'adm-det-' + det.dataset.lembrar;
        try { if (localStorage.getItem(chave) === '1') det.open = true; } catch (e) { /* sem armazenamento */ }
        if (det.dataset.lembrarOk) return;
        det.dataset.lembrarOk = '1';
        det.addEventListener('toggle', () => { try { localStorage.setItem(chave, det.open ? '1' : '0'); } catch (e) { /* ignora */ } });
    });
    lembrarDetalhes(document);
    new MutationObserver((mudancas) => mudancas.forEach((m) => m.addedNodes.forEach((n) => { if (n.nodeType === 1) lembrarDetalhes(n); })))
        .observe(document.body, { childList: true, subtree: true });

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
