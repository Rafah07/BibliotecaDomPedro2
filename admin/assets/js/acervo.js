// Interações do módulo de acervo (livros, exemplares e categorias).
// No modo demonstrativo, tudo o que é "salvo" aqui é SIMULADO (fica só na memória da página).
// Com o banco configurado, o formulário de livro (data-persistir) é enviado ao servidor, que valida e grava.
(function () {
    const dataEl = document.getElementById('acervo-data');
    const DATA = dataEl ? JSON.parse(dataEl.textContent) : { livros: [], autores: [], categorias: [], localizacoes: [], codigos: [], situacoes: {}, conservacao: {}, urls: {} };
    const toast = (title, message, tone) => (window.admToast ? window.admToast(title, message, tone) : alert(title + '\n' + message));
    const params = new URLSearchParams(location.search);

    // ---------- Utilidades ----------
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const normTitle = (text) => normalize(text).replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const livroPorId = (id) => DATA.livros.find((l) => String(l.id) === String(id));
    const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;
    const hoje = () => new Date().toLocaleDateString('pt-BR');

    const svg = (paths) => `<svg class="icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
    const ICONS = {
        info: svg('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>'),
        check: svg('<path d="M20 6 9 17l-5-5"/>'),
        'circle-check': svg('<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'),
        alert: svg('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'),
        archive: svg('<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>'),
        x: svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
        loader: svg('<path d="M21 12a9 9 0 1 1-6.219-8.56"/>'),
        eye: svg('<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>'),
        plus: svg('<path d="M5 12h14"/><path d="M12 5v14"/>'),
        copy: svg('<path d="M2 16V4a2 2 0 0 1 2-2h11"/><path d="M22 18H11a2 2 0 1 0 0 4h10.5a.5.5 0 0 0 .5-.5v-15a.5.5 0 0 0-.5-.5H11a2 2 0 0 0-2 2v12"/><path d="M5 14H4a2 2 0 1 0 0 4h1"/>'),
        pencil: svg('<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>'),
        user: svg('<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>'),
        'user-plus': svg('<path d="M5 12h14"/><path d="M12 5v14"/>'),
        left: svg('<path d="m15 18-6-6 6-6"/>'),
        right: svg('<path d="m9 18 6-6-6-6"/>'),
        pin: svg('<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>'),
        wrench: svg('<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'),
        package: svg('<path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m7.5 4.27 9 5.15"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" x2="12" y1="22" y2="12"/><path d="m17 13 5 5m-5 0 5-5"/>'),
    };

    // ---------- Validação de ISBN (mesma regra de acervo_isbn_valido no PHP) ----------
    const isbnNormalize = (value) => String(value || '').toUpperCase().replace(/[^0-9X]/g, '');
    function isbnValid(value) {
        const isbn = isbnNormalize(value);
        if (/^\d{9}[\dX]$/.test(isbn)) {
            let sum = 0;
            for (let i = 0; i < 10; i++) sum += (isbn[i] === 'X' ? 10 : Number(isbn[i])) * (10 - i);
            return sum % 11 === 0;
        }
        if (/^\d{13}$/.test(isbn)) {
            let sum = 0;
            for (let i = 0; i < 12; i++) sum += Number(isbn[i]) * (i % 2 === 0 ? 1 : 3);
            return (10 - (sum % 10)) % 10 === Number(isbn[12]);
        }
        return false;
    }

    // ---------- Modais ----------
    function openModal(dialog) {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        const first = dialog.querySelector('[autofocus], .adm-modal__body input:not([type=hidden]):not([disabled]), .adm-modal__body select:not([disabled]), .adm-modal__body textarea, .adm-modal__footer .adm-button--primary, .adm-modal__footer .adm-button');
        setTimeout(() => first?.focus(), 30);
    }
    function closeModal(dialog) {
        if (!dialog) return;
        if (typeof dialog.close === 'function' && dialog.open) dialog.close();
        else dialog.removeAttribute('open');
    }
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-modal-open]');
        if (opener) {
            event.preventDefault();
            if (opener.dataset.blocked) return showBlocked(opener.dataset.blocked);
            const dialog = document.getElementById(opener.dataset.modalOpen);
            resetForm(dialog?.querySelector('form'));
            openModal(dialog);
            return;
        }
        const closer = event.target.closest('[data-modal-close]');
        if (closer) closeModal(closer.closest('dialog'));
    });
    // Clique fora da caixa fecha o modal.
    document.addEventListener('mousedown', (event) => {
        const dialog = event.target;
        if (dialog instanceof HTMLDialogElement && dialog.classList.contains('adm-modal')) {
            const rect = dialog.getBoundingClientRect();
            const inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
            if (!inside) closeModal(dialog);
        }
    });

    /**
     * Abre um modal montado na hora e devolve uma Promise com o valor do botão clicado (ou null).
     * actions: [{ label, value, variant: 'primary'|'ghost'|'danger', icon, href, requires: seletor de checkbox }]
     */
    function dialogBox({ tone = 'blue', icon = 'info', title, subtitle = '', content = '', actions = [], wide = false }) {
        return new Promise((resolve) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'adm-modal' + (wide ? ' adm-modal--wide' : '');
            const id = 'dlg-' + Math.random().toString(36).slice(2, 8);
            dialog.setAttribute('aria-labelledby', id);
            dialog.innerHTML = `
                <div class="adm-modal__box">
                    <header class="adm-modal__header">
                        <span class="adm-icon-box adm-icon-box--sm adm-tone--${tone}">${ICONS[icon] || ICONS.info}</span>
                        <div><h2 id="${id}">${esc(title)}</h2>${subtitle ? `<p>${esc(subtitle)}</p>` : ''}</div>
                        <button type="button" class="adm-modal__close" data-value="" aria-label="Fechar">${ICONS.x}</button>
                    </header>
                    <div class="adm-modal__body"></div>
                    <footer class="adm-modal__footer"></footer>
                </div>`;
            const body = dialog.querySelector('.adm-modal__body');
            if (content instanceof Node) body.appendChild(content);
            else body.innerHTML = content;
            if (!body.childNodes.length) body.hidden = true;
            const footer = dialog.querySelector('.adm-modal__footer');
            actions.forEach((action) => {
                const el = document.createElement(action.href ? 'a' : 'button');
                el.className = `adm-button adm-button--${action.variant || 'ghost'}`;
                if (action.href) el.href = action.href;
                else el.type = 'button';
                el.innerHTML = (action.icon ? ICONS[action.icon] || '' : '') + `<span>${esc(action.label)}</span>`;
                el.dataset.value = action.value ?? '';
                if (action.requires) {
                    const check = body.querySelector(action.requires);
                    el.disabled = true;
                    check?.addEventListener('change', () => { el.disabled = !check.checked; });
                }
                footer.appendChild(el);
            });
            let result = null;
            dialog.addEventListener('click', (event) => {
                const btn = event.target.closest('button[data-value], a[data-value]');
                if (!btn || btn.disabled) return;
                if (btn.tagName === 'A') return; // links navegam normalmente
                result = btn.dataset.value || null;
                closeModal(dialog);
            });
            dialog.addEventListener('close', () => {
                resolve(result);
                setTimeout(() => dialog.remove(), 200);
            });
            document.body.appendChild(dialog);
            openModal(dialog);
        });
    }
    window.admDialog = dialogBox;

    function showBlocked(reason) {
        const textos = {
            emprestado: ['Este exemplar está emprestado', 'Não é possível alterar a situação de um exemplar com empréstimo ativo. Registre a devolução antes de enviá-lo para manutenção ou solicitar a baixa.'],
            encerrado: ['Exemplar fora de circulação', 'Este exemplar está baixado ou extraviado. Uma nova manutenção só pode ser registrada depois da revisão no inventário.'],
            baixado: ['Exemplar já baixado', 'A baixa deste exemplar já foi registrada. O histórico continua disponível para consulta.'],
        };
        const [title, message] = textos[reason] || ['Ação indisponível', 'Esta ação não pode ser realizada agora.'];
        return dialogBox({ tone: 'amber', icon: 'alert', title, content: `<p>${esc(message)}</p>`, actions: [{ label: 'Entendi', variant: 'primary' }] });
    }

    // ---------- Formulários: erros e botões ----------
    function setFieldError(form, name, message) {
        const error = form.querySelector(`[data-error-for="${name}"]`);
        if (error) error.textContent = message || '';
        const field = (form.elements[name] && form.elements[name].closest?.('.adm-field')) || error?.closest('.adm-field, .adm-fieldset');
        field?.classList.toggle('is-invalid', Boolean(message));
    }
    function clearErrors(form) {
        $$('[data-error-for]', form).forEach((el) => { el.textContent = ''; });
        $$('.is-invalid', form).forEach((el) => el.classList.remove('is-invalid'));
    }
    function resetForm(form) {
        if (!form) return;
        form.reset();
        clearErrors(form);
        $$('[data-local]', form).forEach((select) => select.dispatchEvent(new Event('change', { bubbles: true })));
    }
    function requiredErrors(form) {
        const errors = {};
        $$('[required]', form).forEach((field) => {
            if (field.disabled || field.closest('[hidden]')) return;
            if (!String(field.value).trim()) {
                const name = field.dataset.local ? 'localizacao' : field.name;
                errors[name] = field.dataset.local ? 'Selecione setor, estante e prateleira.' : field.dataset.msg || (field.tagName === 'SELECT' ? 'Selecione uma opção.' : 'Preencha este campo.');
            }
        });
        return errors;
    }
    function showErrors(form, errors) {
        clearErrors(form);
        Object.entries(errors).forEach(([name, message]) => setFieldError(form, name, message));
        const first = Object.keys(errors)[0];
        if (first) {
            const el = form.elements[first] || form.querySelector(`[data-error-for="${first}"]`)?.closest('.adm-field, .adm-fieldset')?.querySelector('input, select, textarea');
            (el?.length && !el.tagName ? el[0] : el)?.focus?.();
        }
        return !first;
    }

    /** Estado visual de carregamento e confirmação de um botão. */
    async function simulateSave(button, { loading = 'Salvando...', done = 'Salvo (simulação)', ms = 900 } = {}) {
        if (!button) return wait(ms);
        const label = button.querySelector('[data-submit-label]') || button;
        const icon = button.querySelector('.icon');
        const original = { text: label.textContent, icon: icon?.outerHTML };
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        if (icon) icon.outerHTML = ICONS.loader;
        label.textContent = loading;
        await wait(ms);
        button.classList.remove('is-loading');
        button.classList.add('is-done');
        button.removeAttribute('aria-busy');
        const current = button.querySelector('.icon');
        if (current) current.outerHTML = ICONS.check;
        label.textContent = done;
        setTimeout(() => {
            button.classList.remove('is-done');
            const now = button.querySelector('.icon');
            if (now && original.icon) now.outerHTML = original.icon;
            label.textContent = original.text;
        }, 1800);
    }

    // ---------- Localização: setor > estante > prateleira ----------
    function bindLocation(root) {
        const setor = root.querySelector('[data-local="setor"]');
        const estante = root.querySelector('[data-local="estante"]');
        const prateleira = root.querySelector('[data-local="prateleira"]');
        if (!setor || !estante || !prateleira) return;
        const fill = (select, values, placeholder) => {
            select.innerHTML = `<option value="">${placeholder}</option>` + values.map((v) => `<option>${esc(v)}</option>`).join('');
            select.disabled = values.length === 0;
        };
        const unique = (list) => [...new Set(list)];
        setor.addEventListener('change', () => {
            const estantes = unique(DATA.localizacoes.filter((l) => l.setor === setor.value).map((l) => l.estante));
            fill(estante, estantes, setor.value ? 'Selecione' : 'Selecione o setor');
            if (estantes.length === 1) estante.value = estantes[0];
            estante.dispatchEvent(new Event('change'));
        });
        estante.addEventListener('change', () => {
            const prateleiras = DATA.localizacoes.filter((l) => l.setor === setor.value && l.estante === estante.value).map((l) => l.prateleira);
            fill(prateleira, prateleiras, estante.value ? 'Selecione' : 'Selecione a estante');
            if (prateleiras.length === 1) prateleira.value = prateleiras[0];
        });
    }
    $$('form').forEach(bindLocation);

    // ---------- Tabela: pesquisa, filtros, ordenação e paginação ----------
    class Collection {
        constructor(root) {
            this.root = root;
            this.tbody = root.querySelector('[data-rows]');
            this.search = root.querySelector('[data-search]');
            this.filters = $$('[data-filter]', root);
            this.sortSelect = root.querySelector('[data-sort-select]');
            this.sortButtons = $$('[data-sort]', root);
            this.pageSize = root.querySelector('[data-page-size]');
            this.pagination = root.querySelector('[data-pagination]');
            this.empty = root.querySelector('[data-empty]');
            this.countEl = root.querySelector('[data-count]');
            this.page = 1;
            this.sort = this.sortSelect ? this.parseSort(this.sortSelect.value) : null;
            this.reload();

            let timer;
            this.search?.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => { this.page = 1; this.apply(); }, 120);
            });
            this.filters.forEach((select) => select.addEventListener('change', () => { this.page = 1; this.apply(); }));
            this.sortSelect?.addEventListener('change', () => { this.sort = this.parseSort(this.sortSelect.value); this.apply(); });
            this.sortButtons.forEach((btn) => btn.addEventListener('click', () => {
                const key = btn.dataset.sort;
                const dir = this.sort && this.sort.key === key && this.sort.dir === 'asc' ? 'desc' : 'asc';
                this.sort = { key, dir };
                if (this.sortSelect) {
                    const option = [...this.sortSelect.options].find((o) => o.value === `${key}:${dir}`);
                    this.sortSelect.value = option ? option.value : this.sortSelect.value;
                }
                this.apply();
            }));
            this.pageSize?.addEventListener('change', () => { this.page = 1; this.apply(); });
            $$('[data-clear-filters]', root).forEach((btn) => btn.addEventListener('click', () => this.clear()));
            this.filtersToggle = root.querySelector('[data-filters-toggle]');
            this.filtersToggle?.addEventListener('click', () => {
                const open = this.filtersToggle.closest('.adm-toolbar').classList.toggle('is-open');
                this.filtersToggle.setAttribute('aria-expanded', String(open));
            });
            this.apply();
        }
        parseSort(value) {
            const [key, dir] = String(value).split(':');
            return key ? { key, dir: dir || 'asc' } : null;
        }
        reload() {
            this.rows = $$('tr[data-row]', this.tbody);
        }
        clear() {
            if (this.search) this.search.value = '';
            this.filters.forEach((select) => { select.value = ''; });
            if (this.sortSelect) {
                this.sortSelect.selectedIndex = 0;
                this.sort = this.parseSort(this.sortSelect.value);
            } else {
                this.sort = null;
            }
            this.page = 1;
            this.apply();
            this.search?.focus();
        }
        matches(row) {
            if (row.dataset.archived) return false;
            const terms = normalize(this.search?.value).split(/\s+/).filter(Boolean);
            const text = row.dataset.search || '';
            if (!terms.every((t) => text.includes(t))) return false;
            return this.filters.every((select) => {
                if (!select.value) return true;
                const wanted = select.value.split('|');
                const values = String(row.dataset[select.dataset.filter] || '').split('|');
                return wanted.some((w) => values.includes(w));
            });
        }
        compare(a, b) {
            if (!this.sort) return 0;
            const prop = 'sort' + this.sort.key.charAt(0).toUpperCase() + this.sort.key.slice(1);
            const va = a.dataset[prop] ?? '';
            const vb = b.dataset[prop] ?? '';
            const na = Number(va);
            const nb = Number(vb);
            let result = va !== '' && vb !== '' && !Number.isNaN(na) && !Number.isNaN(nb) ? na - nb : va.localeCompare(vb, 'pt-BR');
            if (result === 0) result = (a.dataset.sortTitulo || a.dataset.sortCodigo || '').localeCompare(b.dataset.sortTitulo || b.dataset.sortCodigo || '', 'pt-BR');
            return this.sort.dir === 'desc' ? -result : result;
        }
        apply() {
            const filtered = this.rows.filter((row) => this.matches(row)).sort((a, b) => this.compare(a, b));
            const size = Number(this.pageSize?.value) || filtered.length || 1;
            const pages = Math.max(1, Math.ceil(filtered.length / size));
            this.page = Math.min(this.page, pages);
            const start = (this.page - 1) * size;
            const visible = new Set(filtered.slice(start, start + size));

            filtered.forEach((row) => this.tbody.insertBefore(row, this.empty));
            this.rows.forEach((row) => { row.hidden = !visible.has(row); });
            if (this.empty) this.empty.hidden = filtered.length > 0;

            const active = this.rows.filter((row) => !row.dataset.archived).length;
            if (this.countEl) {
                const [um, varios] = (this.countEl.dataset.noun || 'registro|registros').split('|');
                this.countEl.textContent = filtered.length === active ? plural(active, um, varios) : `${plural(filtered.length, um, varios)} encontrados de ${active}`.replace(`${um} encontrados`, `${um} encontrado`);
            }
            this.filters.forEach((select) => select.classList.toggle('has-value', Boolean(select.value)));
            const activeFilters = this.filters.filter((select) => select.value).length;
            const badge = this.root.querySelector('[data-filters-count]');
            if (badge) { badge.textContent = activeFilters; badge.hidden = activeFilters === 0; }
            this.sortButtons.forEach((btn) => {
                const th = btn.closest('th');
                if (this.sort && btn.dataset.sort === this.sort.key) {
                    btn.setAttribute('aria-sort', this.sort.dir);
                    th?.setAttribute('aria-sort', this.sort.dir === 'asc' ? 'ascending' : 'descending');
                } else {
                    btn.removeAttribute('aria-sort');
                    th?.removeAttribute('aria-sort');
                }
            });
            $$('[data-apply-filter]').forEach((btn) => {
                const select = this.root.querySelector(`[data-filter="${btn.dataset.applyFilter}"]`);
                if (select) btn.classList.toggle('is-active', select.value === btn.dataset.value);
            });
            this.renderPagination(filtered.length, start, size, pages);
        }
        renderPagination(total, start, size, pages) {
            if (!this.pagination) return;
            const info = this.pagination.querySelector('[data-page-info]');
            const nav = this.pagination.querySelector('[data-pages]');
            this.pagination.hidden = total === 0;
            if (info) info.textContent = total ? `Mostrando ${start + 1}–${Math.min(start + size, total)} de ${total}` : '';
            if (!nav) return;
            const numbers = [];
            for (let p = 1; p <= pages; p++) {
                if (p === 1 || p === pages || Math.abs(p - this.page) <= 1) numbers.push(p);
                else if (numbers[numbers.length - 1] !== '…') numbers.push('…');
            }
            nav.innerHTML =
                `<button type="button" data-page="${this.page - 1}" ${this.page === 1 ? 'disabled' : ''} aria-label="Página anterior">${ICONS.left}</button>` +
                numbers.map((p) => p === '…'
                    ? '<button type="button" disabled aria-hidden="true">…</button>'
                    : `<button type="button" data-page="${p}" ${p === this.page ? 'aria-current="page"' : ''} aria-label="Página ${p}">${p}</button>`).join('') +
                `<button type="button" data-page="${this.page + 1}" ${this.page === pages ? 'disabled' : ''} aria-label="Próxima página">${ICONS.right}</button>`;
            nav.onclick = (event) => {
                const btn = event.target.closest('[data-page]');
                if (!btn || btn.disabled) return;
                this.page = Number(btn.dataset.page);
                this.apply();
                const top = this.root.getBoundingClientRect().top + window.scrollY - 90;
                if (window.scrollY > top) window.scrollTo({ top, behavior: 'smooth' });
            };
        }
    }
    const collections = $$('[data-collection]').map((root) => new Collection(root));

    // Cards que aplicam um filtro (ex.: "Cadastros pendentes").
    $$('[data-apply-filter]').forEach((btn) => btn.addEventListener('click', () => {
        const select = document.querySelector(`[data-filter="${btn.dataset.applyFilter}"]`);
        if (!select) return;
        select.value = select.value === btn.dataset.value ? '' : btn.dataset.value;
        select.dispatchEvent(new Event('change'));
        select.closest('[data-collection]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));

    // ---------- Menu de ações de cada linha ----------
    let openMenu = null;
    function closeRowMenu() {
        if (!openMenu) return;
        openMenu.list.hidden = true;
        openMenu.button.setAttribute('aria-expanded', 'false');
        openMenu = null;
    }
    function positionMenu(button, list) {
        const rect = button.getBoundingClientRect();
        list.style.visibility = 'hidden';
        list.hidden = false;
        const { offsetWidth: w, offsetHeight: h } = list;
        let top = rect.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, rect.top - h - 6);
        const left = Math.min(Math.max(8, rect.right - w), window.innerWidth - w - 8);
        list.style.top = top + 'px';
        list.style.left = left + 'px';
        list.style.visibility = '';
    }
    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-rowmenu-toggle]');
        if (toggle) {
            const list = toggle.parentElement.querySelector('.adm-rowmenu__list');
            const isOpen = openMenu && openMenu.list === list;
            closeRowMenu();
            if (!isOpen) {
                positionMenu(toggle, list);
                toggle.setAttribute('aria-expanded', 'true');
                openMenu = { button: toggle, list };
                list.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
            }
            return;
        }
        if (openMenu && (!event.target.closest('.adm-rowmenu__list') || event.target.closest('[role="menuitem"]'))) closeRowMenu();
    });
    document.addEventListener('keydown', (event) => {
        if (!openMenu) return;
        const items = $$('[role="menuitem"]', openMenu.list);
        const index = items.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            const button = openMenu.button;
            closeRowMenu();
            button.focus();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
            items[next]?.focus();
        } else if (event.key === 'Tab') {
            closeRowMenu();
        }
    });
    window.addEventListener('resize', closeRowMenu);
    // Ao rolar a página, o menu acompanha o botão (e fecha se o botão sair da tela).
    window.addEventListener('scroll', () => {
        if (!openMenu) return;
        const rect = openMenu.button.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > window.innerHeight) closeRowMenu();
        else positionMenu(openMenu.button, openMenu.list);
    }, { passive: true, capture: true });

    // ---------- Abas ----------
    $$('[data-tabs]').forEach((root) => {
        const tabs = $$('[role="tab"]', root);
        const select = (tab, focus = false, updateHash = true) => {
            tabs.forEach((t) => {
                const selected = t === tab;
                t.setAttribute('aria-selected', String(selected));
                t.tabIndex = selected ? 0 : -1;
                document.getElementById(t.getAttribute('aria-controls')).hidden = !selected;
            });
            if (focus) tab.focus();
            if (updateHash) history.replaceState(null, '', '#' + tab.dataset.tab);
        };
        tabs.forEach((tab, i) => {
            tab.addEventListener('click', () => select(tab));
            tab.addEventListener('keydown', (event) => {
                const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
                if (move) select(tabs[(i + move + tabs.length) % tabs.length], true);
                if (event.key === 'Home') select(tabs[0], true);
                if (event.key === 'End') select(tabs[tabs.length - 1], true);
            });
        });
        const fromHash = () => {
            const tab = tabs.find((t) => '#' + t.dataset.tab === location.hash);
            if (tab) select(tab, false, false);
        };
        window.addEventListener('hashchange', fromHash);
        fromHash();
    });
    window.admSelectTab = (name) => document.querySelector(`[role="tab"][data-tab="${name}"]`)?.click();

    // ---------- Adicionar exemplares (simulação) ----------
    const usedCodes = new Set(DATA.codigos || []);
    let nextCodeNumber = Number(String(DATA.proximoCodigo || 'BDP-000001').slice(4));
    const formatCode = (n) => 'BDP-' + String(n).padStart(6, '0');
    function peekCodes(quantity) {
        const codes = [];
        let n = nextCodeNumber;
        while (codes.length < quantity) {
            const code = formatCode(n++);
            if (!usedCodes.has(code)) codes.push(code);
        }
        return codes;
    }
    function takeCodes(quantity) {
        const codes = peekCodes(quantity);
        codes.forEach((code) => usedCodes.add(code)); // garante que nenhum código se repita nesta página
        nextCodeNumber = Number(codes[codes.length - 1].slice(4)) + 1;
        return codes;
    }

    const exModal = document.getElementById('modal-exemplares');
    if (exModal) {
        const form = exModal.querySelector('[data-exemplares-form]');
        const quantity = form.elements.quantidade;
        const preview = form.querySelector('[data-code-preview]');
        const livroSelect = form.elements.livro_id;
        const submitLabel = form.querySelector('[data-submit-label]');
        const steps = (name) => $$('[data-step]', form).forEach((el) => { el.hidden = el.dataset.step !== name; });

        const updatePreview = () => {
            const q = Math.min(Math.max(parseInt(quantity.value, 10) || 1, 1), 50);
            const codes = peekCodes(q);
            preview.textContent = q === 1 ? codes[0] : `${codes[0]} a ${codes[codes.length - 1]}`;
            submitLabel.textContent = q === 1 ? 'Salvar exemplar' : `Salvar ${q} exemplares`;
        };
        quantity.addEventListener('input', updatePreview);

        window.admOpenExemplares = (livroId) => {
            resetForm(form);
            steps('form');
            if (livroId) livroSelect.value = String(livroId);
            updatePreview();
            openModal(exModal);
        };
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-add-exemplares]');
            if (!btn) return;
            window.admOpenExemplares(btn.dataset.addExemplares || livroSelect.value);
        });
        form.querySelector('[data-exemplares-again]')?.addEventListener('click', () => window.admOpenExemplares(livroSelect.value));

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const errors = requiredErrors(form);
            const q = Number(quantity.value);
            if (!Number.isInteger(q) || q < 1 || q > 50) errors.quantidade = 'Informe uma quantidade entre 1 e 50.';
            if (!livroSelect.value) errors.livro_id = 'Selecione o livro relacionado.';
            if (!showErrors(form, errors)) return;

            await simulateSave(form.querySelector('[data-submit]'), { loading: 'Gerando códigos...', done: 'Pronto', ms: 800 });
            const codes = takeCodes(q);
            const livroNome = livroSelect.options[livroSelect.selectedIndex].text.trim();
            const registro = {
                livroId: livroSelect.value,
                local: [form.elements.setor.value, form.elements.estante.value, form.elements.prateleira.value].join(' / '),
                setor: form.elements.setor.value,
                conservacao: form.elements.estado_conservacao.value,
                situacao: form.elements.situacao.value,
            };
            form.querySelector('[data-result-title]').textContent = `${plural(q, 'código simulado', 'códigos simulados')} para "${livroNome.split(' — ')[0]}":`;
            form.querySelector('[data-result-codes]').innerHTML = codes.map((c) => `<li>${ICONS['circle-check']}${c}</li>`).join('');
            steps('result');
            addSimulatedRows(codes, registro);
            toast('Exemplares simulados', `${plural(q, 'código foi gerado', 'códigos foram gerados')} apenas nesta tela. Nada foi gravado.`, 'success');
        });
        updatePreview();
    }

    const statusBadge = (situacao) => {
        const s = DATA.situacoes[situacao] || { label: situacao, tom: 'blue' };
        return `<span class="adm-status adm-status--soft adm-tone--${s.tom}">${esc(s.label)}</span>`;
    };
    const conservacaoBadge = (estado) => `<span class="adm-condition${['danificado', 'necessita_reparo'].includes(estado) ? ' adm-condition--alert' : ''}">${esc(DATA.conservacao[estado] || estado)}</span>`;

    /** Mostra os exemplares simulados nas tabelas da página atual. */
    function addSimulatedRows(codes, reg) {
        const livro = livroPorId(reg.livroId);
        const simTag = '<span class="adm-demo-tag">Simulado</span>';
        // Aba "Exemplares" da página de detalhes do livro.
        const bookTable = document.querySelector(`[data-exemplares-rows][data-livro-id="${reg.livroId}"]`);
        if (bookTable) {
            const empty = bookTable.querySelector('[data-empty]');
            codes.forEach((code) => {
                const tr = document.createElement('tr');
                tr.className = 'is-simulated';
                tr.innerHTML = `<td class="adm-td-title"><span class="adm-code">${code}</span> ${simTag}</td>
                    <td data-label="Localização">${esc(reg.local)}</td>
                    <td data-label="Conservação">${conservacaoBadge(reg.conservacao)}</td>
                    <td data-label="Situação">${statusBadge(reg.situacao)}</td>
                    <td data-label="Última conferência" class="adm-table__muted"><span class="adm-missing">Não conferido</span></td>
                    <td class="adm-actions-col"></td>`;
                bookTable.insertBefore(tr, empty);
            });
            if (empty) empty.hidden = true;
            const count = document.querySelector('[data-exemplares-count]');
            if (count) count.textContent = Number(count.textContent) + codes.length;
            const kpi = (name, add) => { const el = document.querySelector(`[data-kpi="${name}"]`); if (el) el.textContent = Number(el.textContent) + add; };
            kpi('Exemplares identificados', codes.length);
            if (reg.situacao === 'disponivel') kpi('Disponíveis', codes.length);
            else if (reg.situacao === 'manutencao') kpi('Em manutenção', codes.length);
            else kpi('Outras situações', codes.length);
            window.admSelectTab?.('exemplares');
        }
        // Tabela geral de exemplares.
        const collection = collections.find((c) => c.root.querySelector('[data-filter="conservacao"]'));
        if (collection && livro) {
            codes.forEach((code) => {
                const tr = document.createElement('tr');
                tr.className = 'is-simulated';
                tr.dataset.row = '';
                tr.dataset.search = normalize([code, code.slice(4), livro.titulo, livro.autores.join(' '), livro.isbn].join(' '));
                tr.dataset.situacao = reg.situacao;
                tr.dataset.setor = normalize(reg.setor);
                tr.dataset.conservacao = reg.conservacao;
                tr.dataset.pendente = 'sim';
                tr.dataset.sortCodigo = code;
                tr.dataset.sortLivro = normalize(livro.titulo);
                tr.dataset.sortLocal = normalize(reg.local);
                tr.dataset.sortSituacao = String(Object.keys(DATA.situacoes).indexOf(reg.situacao));
                tr.dataset.sortConferencia = '0000';
                tr.innerHTML = `<td class="adm-td-title"><span class="adm-code">${code}</span> ${simTag}</td>
                    <td data-label="Livro"><a class="adm-book-link" href="${esc(DATA.urls.detalhesLivro)}?id=${livro.id}">${esc(livro.titulo)}</a><small class="adm-block-muted">${esc(livro.autores[0] || '')}</small></td>
                    <td data-label="Localização">${esc(reg.local)}</td>
                    <td data-label="Conservação">${conservacaoBadge(reg.conservacao)}</td>
                    <td data-label="Situação">${statusBadge(reg.situacao)}</td>
                    <td data-label="Última conferência" class="adm-table__muted"><span class="adm-missing">Não conferido</span></td>
                    <td class="adm-actions-col"></td>`;
                collection.tbody.insertBefore(tr, collection.tbody.firstChild);
            });
            collection.reload();
            collection.sort = { key: 'codigo', dir: 'desc' };
            collection.page = 1;
            collection.apply();
        }
        // Lista de livros: atualiza a contagem da linha e os cards.
        const bookRow = document.querySelector(`[data-collection] tr[data-row][data-id="${reg.livroId}"]`);
        if (bookRow) {
            const cell = bookRow.querySelector('td[data-label="Exemplares"]');
            if (cell) cell.firstChild.textContent = String(Number(bookRow.dataset.sortExemplares) + codes.length) + ' ';
            bookRow.dataset.sortExemplares = Number(bookRow.dataset.sortExemplares) + codes.length;
            if (reg.situacao === 'disponivel') {
                const disp = bookRow.querySelector('td[data-label="Disponíveis"] strong');
                bookRow.dataset.sortDisponiveis = Number(bookRow.dataset.sortDisponiveis) + codes.length;
                if (disp) { disp.textContent = bookRow.dataset.sortDisponiveis; disp.className = 'adm-text-green'; }
                bookRow.dataset.disponibilidade = 'com';
                bumpStat('disponiveis', codes.length);
            } else if (bookRow.dataset.disponibilidade.includes('nenhum')) {
                bookRow.dataset.disponibilidade = 'sem';
            }
            bumpStat('exemplares', codes.length);
            bookRow.classList.add('is-simulated');
        }
    }
    function bumpStat(name, add) {
        const el = document.querySelector(`[data-stat="${name}"]`);
        if (el) el.textContent = (Number(el.textContent.replace(/\D/g, '')) + add).toLocaleString('pt-BR');
    }

    // ---------- Arquivar livro ----------
    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-archive-livro]');
        if (!btn) return;
        const livro = livroPorId(btn.dataset.archiveLivro);
        if (!livro) return;
        if (livro.emprestados > 0) {
            await dialogBox({
                tone: 'amber', icon: 'alert', title: 'Não é possível arquivar agora',
                content: `<p>"${esc(livro.titulo)}" possui ${plural(livro.emprestados, 'exemplar emprestado', 'exemplares emprestados')}. Registre ${livro.emprestados === 1 ? 'a devolução' : 'as devoluções'} antes de arquivar o cadastro.</p>`,
                actions: [{ label: 'Entendi', variant: 'primary' }],
            });
            return;
        }
        const ok = await dialogBox({
            tone: 'amber', icon: 'archive', title: 'Deseja realmente arquivar este cadastro?', subtitle: livro.titulo,
            content: `<p>O livro deixará de aparecer nas pesquisas e no catálogo. <strong>O cadastro não será excluído</strong>: o histórico de empréstimos, inventários e ${plural(livro.exemplares, 'exemplar', 'exemplares')} continua preservado e poderá ser restaurado.</p>
                <p class="adm-side-note">${ICONS.info} Simulação: nada será gravado. Ao recarregar a página, o livro volta a aparecer.</p>`,
            actions: [{ label: 'Cancelar', value: '' }, { label: 'Arquivar cadastro', value: 'ok', variant: 'danger', icon: 'archive' }],
        });
        if (ok !== 'ok') return;
        const row = document.querySelector(`tr[data-row][data-id="${livro.id}"]`);
        if (row) {
            row.dataset.archived = '1';
            if (row.dataset.status.includes('pendente')) bumpStat('pendentes', -1);
            bumpStat('titulos', -1);
            collections.forEach((c) => c.apply());
        }
        toast('Cadastro arquivado (simulação)', `"${livro.titulo}" foi ocultado apenas nesta tela. Nada foi gravado.`, 'success');
    });

    // ---------- Formulário de livro ----------
    const bookForm = document.querySelector('[data-livro-form]');
    if (bookForm) initBookForm(bookForm);

    function initBookForm(form) {
        const editing = form.dataset.modo === 'editar';
        const selfId = Number(form.dataset.livroId || 0);
        const el = form.elements;
        const errorBox = form.querySelector('[data-form-errors]');
        const chips = form.querySelector('[data-chips]');
        const autorInput = form.querySelector('[data-autor-input]');
        const list = form.querySelector('#lista-autores');
        const authors = () => $$('input[name="autores[]"]', chips).map((i) => i.value);

        // Autores: pesquisa, seleção e inclusão de novos nomes.
        let activeIndex = -1;
        const addAuthor = (name) => {
            name = name.trim().replace(/\s+/g, ' ');
            if (!name) return;
            if (authors().some((a) => normalize(a) === normalize(name))) {
                autorInput.value = '';
                return closeList();
            }
            const known = DATA.autores.some((a) => normalize(a) === normalize(name));
            const li = document.createElement('li');
            li.className = 'adm-chip adm-chip--removable' + (known ? '' : ' adm-chip--new');
            li.title = known ? '' : 'Novo autor: será cadastrado junto com o livro';
            li.innerHTML = `${esc(name)}${known ? '' : ' <small>(novo)</small>'}<input type="hidden" name="autores[]" value="${esc(name)}"><button type="button" data-chip-remove aria-label="Remover ${esc(name)}">${ICONS.x}</button>`;
            chips.appendChild(li);
            autorInput.value = '';
            closeList();
            updateCover();
        };
        const closeList = () => {
            list.hidden = true;
            autorInput.setAttribute('aria-expanded', 'false');
            autorInput.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        };
        const renderList = () => {
            const term = normalize(autorInput.value);
            if (!term) return closeList();
            const chosen = authors().map(normalize);
            const matches = DATA.autores.filter((a) => normalize(a).includes(term) && !chosen.includes(normalize(a))).slice(0, 6);
            const exact = DATA.autores.some((a) => normalize(a) === term);
            const options = matches.map((a) => ({ label: a, value: a, icon: 'user' }));
            if (!exact) options.push({ label: `Adicionar novo autor: "${autorInput.value.trim()}"`, value: autorInput.value.trim(), icon: 'user-plus', isNew: true });
            list.innerHTML = options.map((o, i) => `<li role="option" id="autor-opt-${i}" data-value="${esc(o.value)}" aria-selected="false">${ICONS[o.icon]}<span>${esc(o.label)}</span>${o.isNew ? '<small>novo</small>' : ''}</li>`).join('');
            list.hidden = false;
            autorInput.setAttribute('aria-expanded', 'true');
            activeIndex = -1;
        };
        const highlight = (index) => {
            const items = $$('[role="option"]', list);
            if (!items.length) return;
            activeIndex = (index + items.length) % items.length;
            items.forEach((item, i) => item.setAttribute('aria-selected', String(i === activeIndex)));
            autorInput.setAttribute('aria-activedescendant', items[activeIndex].id);
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        };
        autorInput?.addEventListener('input', renderList);
        autorInput?.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') { event.preventDefault(); if (list.hidden) renderList(); highlight(activeIndex + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(activeIndex - 1); }
            else if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                const item = $$('[role="option"]', list)[activeIndex];
                addAuthor(item ? item.dataset.value : autorInput.value);
            } else if (event.key === 'Escape' && !list.hidden) { event.stopPropagation(); closeList(); }
            else if (event.key === 'Backspace' && !autorInput.value && chips.lastElementChild) { chips.lastElementChild.remove(); updateCover(); }
        });
        autorInput?.addEventListener('blur', () => setTimeout(closeList, 150));
        list?.addEventListener('mousedown', (event) => {
            const item = event.target.closest('[role="option"]');
            if (item) { event.preventDefault(); addAuthor(item.dataset.value); autorInput.focus(); }
        });
        form.querySelector('.adm-chips-input')?.addEventListener('click', (event) => { if (event.target === event.currentTarget) autorInput.focus(); });
        chips?.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-chip-remove]');
            if (btn) { btn.closest('li').remove(); updateCover(); autorInput.focus(); }
        });

        // Capa padrão acompanha o título digitado.
        const coverBox = form.querySelector('[data-upload-preview]');
        const placeholderHTML = coverBox?.innerHTML;
        let hasImage = false;
        function updateCover() {
            if (hasImage || !coverBox) return;
            const title = coverBox.querySelector('.adm-cover__title');
            const author = coverBox.querySelector('.adm-cover__author');
            if (title) title.textContent = el.titulo.value.trim() || 'Título do livro';
            if (author) author.textContent = authors()[0] || '';
        }
        el.titulo.addEventListener('input', () => { updateCover(); editionHint(); if (el.titulo.value.trim()) setFieldError(form, 'titulo', ''); });

        // Upload da capa: formato, tamanho e pré-visualização.
        const upload = form.querySelector('[data-upload-input]');
        const removeBtn = form.querySelector('[data-upload-remove]');
        const status = form.querySelector('[data-upload-status]');
        const defaultStatus = status?.textContent;
        let objectUrl = null;
        const useFile = (file) => {
            setFieldError(form, 'capa', '');
            if (!file) return;
            const okType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
            if (!okType || file.size > 2 * 1024 * 1024) {
                setFieldError(form, 'capa', okType ? 'A imagem tem mais de 2 MB. Escolha um arquivo menor.' : 'Formato não aceito. Use JPG, PNG ou WebP.');
                upload.value = '';
                return;
            }
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);
            hasImage = true;
            coverBox.innerHTML = `<span class="adm-cover adm-cover--lg"><img src="${objectUrl}" alt="Pré-visualização da capa"></span>`;
            status.textContent = `${file.name} (${Math.round(file.size / 1024)} KB). Pré-visualização apenas: a imagem não é enviada nesta versão.`;
            removeBtn.disabled = false;
        };
        upload?.addEventListener('change', () => useFile(upload.files[0]));
        removeBtn?.addEventListener('click', () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            hasImage = false;
            upload.value = '';
            coverBox.innerHTML = placeholderHTML;
            status.textContent = defaultStatus;
            removeBtn.disabled = true;
            updateCover();
        });
        ['dragover', 'dragleave', 'drop'].forEach((type) => coverBox?.addEventListener(type, (event) => {
            event.preventDefault();
            coverBox.classList.toggle('is-dragover', type === 'dragover');
            if (type === 'drop') useFile(event.dataTransfer.files[0]);
        }));

        // ISBN: validação enquanto digita.
        const isbnHint = form.querySelector('[data-isbn-hint]');
        const isbnDefault = isbnHint?.textContent;
        const checkIsbn = (final) => {
            const raw = el.isbn.value.trim();
            const digits = isbnNormalize(raw);
            isbnHint.classList.remove('is-ok');
            if (!raw) { isbnHint.textContent = isbnDefault; setFieldError(form, 'isbn', ''); return true; }
            if (isbnValid(raw)) {
                isbnHint.textContent = `ISBN-${digits.length} válido.`;
                isbnHint.classList.add('is-ok');
                setFieldError(form, 'isbn', '');
                return true;
            }
            isbnHint.textContent = `${digits.length} de ${digits.length > 10 ? 13 : '10 ou 13'} dígitos`;
            if (final) setFieldError(form, 'isbn', digits.length === 10 || digits.length === 13 ? 'O dígito de controle não confere. Verifique se o ISBN foi digitado corretamente.' : 'O ISBN deve ter 10 ou 13 dígitos.');
            return false;
        };
        el.isbn.addEventListener('input', () => checkIsbn(false));
        el.isbn.addEventListener('blur', () => checkIsbn(true));
        if (el.isbn.value) checkIsbn(false);

        // Contador da sinopse.
        const counter = form.querySelector('[data-counter]');
        const counterValue = form.querySelector('[data-counter-value]');
        const updateCounter = () => { if (counterValue) counterValue.textContent = counter.value.length; };
        counter?.addEventListener('input', updateCounter);
        updateCounter();

        // Aviso de títulos iguais já cadastrados (outras edições não são bloqueadas).
        const hint = form.querySelector('[data-edition-hint]');
        const hintText = form.querySelector('[data-edition-hint-text]');
        function editionHint() {
            if (!hint) return;
            const title = normTitle(el.titulo.value);
            const same = title.length >= 3 ? DATA.livros.filter((l) => l.id !== selfId && normTitle(l.titulo) === title) : [];
            hint.hidden = same.length === 0;
            if (!same.length) return;
            hintText.innerHTML = `Já ${same.length === 1 ? 'existe 1 cadastro' : `existem ${same.length} cadastros`} com este título: ` +
                same.map((l) => `<a class="adm-link" href="${esc(DATA.urls.detalhesLivro)}?id=${l.id}" target="_blank" rel="noopener">${esc(l.titulo)}</a> (${esc([l.editora || 'editora não informada', l.ano, l.edicao].filter(Boolean).join(', '))})`).join('; ') +
                '. Se for outra edição, continue normalmente. Se for a mesma, adicione exemplares ao cadastro existente.';
        }
        editionHint();

        el.ano_publicacao.addEventListener('blur', () => { const errs = validate(); setFieldError(form, 'ano_publicacao', errs.ano_publicacao || ''); });

        function validate() {
            const errors = {};
            const title = el.titulo.value.trim();
            if (!title) errors.titulo = 'Informe o título do livro.';
            if (el.isbn.value.trim() && !isbnValid(el.isbn.value)) {
                const len = isbnNormalize(el.isbn.value).length;
                errors.isbn = len === 10 || len === 13 ? 'O dígito de controle não confere. Verifique se o ISBN foi digitado corretamente.' : 'O ISBN deve ter 10 ou 13 dígitos.';
            }
            const ano = el.ano_publicacao.value.trim();
            const maxAno = new Date().getFullYear() + 1;
            if (ano && (!/^\d+$/.test(ano) || Number(ano) < 1450 || Number(ano) > maxAno)) errors.ano_publicacao = `Informe um ano entre 1450 e ${maxAno}.`;
            if (el.ano_publicacao.validity.badInput) errors.ano_publicacao = 'Digite apenas números no ano.';
            const pag = el.numero_paginas.value.trim();
            if (pag && (!/^\d+$/.test(pag) || Number(pag) < 1 || Number(pag) > 10000)) errors.numero_paginas = 'Informe um número de páginas entre 1 e 10.000.';
            return errors;
        }

        function findDuplicates() {
            const isbn = isbnNormalize(el.isbn.value);
            const title = normTitle(el.titulo.value);
            const autores = authors().map(normalize);
            const editora = normalize(el.editora.value);
            const edicao = normalize(el.edicao.value);
            const ano = el.ano_publicacao.value.trim();
            const result = [];
            DATA.livros.forEach((l) => {
                if (l.id === selfId) return;
                const reasons = [];
                const sameIsbn = isbn && l.isbn && isbn === l.isbn;
                if (sameIsbn) reasons.push('Mesmo ISBN');
                const lt = normTitle(l.titulo);
                const sameTitle = lt === title || (title.length >= 8 && (lt.includes(title) || title.includes(lt)));
                if (!sameIsbn && !sameTitle) return;
                if (sameTitle) reasons.push(lt === title ? 'Mesmo título' : 'Título semelhante');
                const sameAuthor = l.autores.some((a) => autores.includes(normalize(a)));
                if (sameAuthor) reasons.push('Mesmo autor');
                if (editora && normalize(l.editora) === editora) reasons.push('Mesma editora');
                if (edicao && normalize(l.edicao) === edicao) reasons.push('Mesma edição');
                if (ano && String(l.ano) === ano) reasons.push('Mesmo ano');
                const otherEdition = (edicao && l.edicao && normalize(l.edicao) !== edicao) || (ano && l.ano && String(l.ano) !== ano) || (editora && l.editora && normalize(l.editora) !== editora);
                const authorConflict = autores.length && l.autores.length && !sameAuthor;
                // Mesmo título com outra edição, ano ou editora não é tratado como duplicidade.
                if (sameIsbn || (!otherEdition && !authorConflict)) result.push({ livro: l, reasons });
            });
            return result;
        }

        function duplicateDialog(dupes) {
            const content = document.createElement('div');
            content.innerHTML = `
                <p>Encontramos um possível cadastro semelhante. Confira se o livro já existe antes de continuar.</p>
                <ul class="adm-dupes">${dupes.map(({ livro, reasons }) => `
                    <li class="adm-dupe">
                        <span class="adm-cover adm-cover--sm adm-cover--placeholder" style="--cover:${esc(livro.cor)}"><span class="adm-cover__title">${esc(livro.titulo)}</span></span>
                        <div class="adm-dupe__body">
                            <strong>${esc(livro.titulo)}</strong>
                            <small>${esc(livro.autores.join(', ') || 'Autor não informado')} · ${esc([livro.editora || 'Editora não informada', livro.ano, livro.edicao].filter(Boolean).join(', '))}</small>
                            <small>${esc(livro.status)} · ${plural(livro.exemplares, 'exemplar', 'exemplares')}${livro.isbn ? ' · ISBN ' + esc(livro.isbn) : ''}</small>
                            <div class="adm-dupe__reasons">${reasons.map((r) => `<span class="adm-chip">${esc(r)}</span>`).join('')}</div>
                            <div class="adm-dupe__actions">
                                <a class="adm-button adm-button--ghost adm-button--sm" href="${esc(DATA.urls.detalhesLivro)}?id=${livro.id}" target="_blank" rel="noopener">${ICONS.eye} Visualizar cadastro existente</a>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="${esc(DATA.urls.detalhesLivro)}?id=${livro.id}&amp;acao=adicionar-exemplares#exemplares">${ICONS.plus} Adicionar exemplar ao livro existente</a>
                            </div>
                        </div>
                    </li>`).join('')}
                </ul>
                <label class="adm-check-confirm"><input type="checkbox" data-dupe-confirm> <span>Conferi os registros acima e confirmo que se trata de uma obra ou edição diferente.</span></label>`;
            return dialogBox({
                tone: 'amber', icon: 'alert', wide: true,
                title: 'Este título possui possíveis registros semelhantes',
                content,
                actions: [{ label: 'Voltar ao formulário', value: '' }, { label: 'Continuar cadastro', value: 'continuar', variant: 'primary', requires: '[data-dupe-confirm]' }],
            });
        }

        // Valores iniciais, para identificar alterações importantes na edição.
        const snapshot = () => ({
            Título: el.titulo.value.trim(),
            ISBN: el.isbn.value.trim(),
            Autores: authors().join(', '),
            Categoria: el.categoria.options[el.categoria.selectedIndex]?.text || '',
            'Situação do cadastro': form.querySelector('input[name="status_validacao"]:checked')?.closest('label').querySelector('.adm-status')?.textContent || '',
        });
        const initial = snapshot();

        let submitter = null;
        $$('[data-submit]', form).forEach((btn) => btn.addEventListener('click', () => { submitter = btn; }));

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = event.submitter || submitter || form.querySelector('[data-submit]');
            if (autorInput?.value.trim()) addAuthor(autorInput.value);
            const errors = validate();
            const ok = showErrors(form, errors);
            errorBox.hidden = ok;
            if (!ok) {
                toast('Não foi possível salvar as informações', 'Verifique os campos destacados e tente novamente.', 'error');
                return;
            }

            if (!editing && DATA.livros.length) {
                const dupes = findDuplicates();
                if (dupes.length && (await duplicateDialog(dupes)) !== 'continuar') return;
            } else {
                const now = snapshot();
                const changes = Object.keys(initial).filter((k) => initial[k] !== now[k]);
                if (changes.length) {
                    const answer = await dialogBox({
                        tone: 'blue', icon: 'pencil', title: 'Confirmar alterações importantes',
                        content: `<p>As seguintes informações serão alteradas:</p><ul class="adm-change-list">${changes.map((k) => `<li><strong>${esc(k)}:</strong> <del>${esc(initial[k] || '(vazio)')}</del> → <ins>${esc(now[k] || '(vazio)')}</ins></li>`).join('')}</ul>`,
                        actions: [{ label: 'Revisar', value: '' }, { label: 'Confirmar alterações', value: 'ok', variant: 'primary', icon: 'check' }],
                    });
                    if (answer !== 'ok') return;
                }
            }

            if (form.dataset.persistir === '1') {
                // Envio real: o servidor valida de novo, grava e redireciona.
                if (button?.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = button.name;
                    hidden.value = button.value;
                    form.appendChild(hidden);
                }
                $$('[data-submit]', form).forEach((b) => { b.disabled = true; });
                const label = button?.querySelector('[data-submit-label]');
                if (label) label.textContent = 'Salvando...';
                button?.classList.add('is-loading');
                HTMLFormElement.prototype.submit.call(form);
                return;
            }

            await simulateSave(button);
            const title = el.titulo.value.trim();
            if (editing) {
                const answer = await dialogBox({
                    tone: 'green', icon: 'circle-check', title: 'Alterações salvas (simulação)',
                    content: `<p>As informações de "${esc(title)}" foram validadas, mas <strong>não foram gravadas</strong>: o banco de dados ainda não está conectado.</p>`,
                    actions: [{ label: 'Continuar editando', value: '' }, { label: 'Ver detalhes do livro', href: `${DATA.urls.detalhesLivro}?id=${selfId}`, variant: 'primary', icon: 'eye' }],
                });
                return answer;
            }
            const withCopies = button?.value === 'salvar_exemplares';
            const answer = await dialogBox({
                tone: 'green', icon: 'circle-check', title: 'Livro cadastrado com sucesso.',
                content: `<p>"${esc(title)}" passou por todas as verificações. <strong>Simulação:</strong> o livro não foi gravado, pois o banco de dados ainda não está conectado.</p>` +
                    (withCopies ? '<p>Agora informe os exemplares físicos. Os códigos também serão apenas simulados.</p>' : '<p>Lembre-se: o livro só ficará disponível para empréstimo depois que seus exemplares físicos forem cadastrados.</p>'),
                actions: withCopies
                    ? [{ label: 'Agora não', value: '' }, { label: 'Adicionar exemplares', value: 'exemplares', variant: 'primary', icon: 'plus' }]
                    : [{ label: 'Cadastrar outro livro', value: 'novo' }, { label: 'Ir para o acervo', href: DATA.urls.livros, variant: 'primary' }],
            });
            if (answer === 'novo') {
                form.reset();
                chips.innerHTML = '';
                removeBtn?.click();
                clearErrors(form);
                hint.hidden = true;
                updateCover();
                updateCounter();
                window.scrollTo({ top: 0, behavior: 'smooth' });
                el.titulo.focus();
            } else if (answer === 'exemplares' && exModal) {
                const select = exModal.querySelector('[data-livro-select]');
                let option = select.querySelector('option[data-simulado]');
                if (!option) {
                    option = new Option('', 'novo');
                    option.dataset.simulado = '1';
                    select.add(option, 1);
                }
                option.textContent = `${title} — ${authors()[0] || 'autor não informado'} (novo cadastro, simulação)`;
                window.admOpenExemplares('novo');
            }
        });
    }

    // ---------- Detalhes do exemplar: ações simuladas ----------
    const timeline = document.querySelector('[data-timeline]');
    function addTimeline(tipo, descricao, tone, icon) {
        if (!timeline) return;
        const li = document.createElement('li');
        li.className = 'is-simulated';
        li.innerHTML = `<span class="adm-icon-box adm-icon-box--xs adm-tone--${tone}">${ICONS[icon] || ICONS.info}</span>
            <div><p><strong>${esc(tipo)}</strong></p><p class="adm-timeline__desc">${esc(descricao)}</p><small>${hoje()} · Ana Souza</small></div>`;
        timeline.prepend(li);
    }
    const setField = (name, html) => { const dd = document.querySelector(`[data-field="${name}"]`); if (dd) dd.innerHTML = html; };
    const handlers = {
        'editar-exemplar': (form) => {
            setField('conservacao', conservacaoBadge(form.elements.estado_conservacao.value));
            if (!form.elements.situacao.disabled) setField('situacao', statusBadge(form.elements.situacao.value));
            const obs = form.elements.observacoes.value.trim();
            setField('observacoes', obs ? esc(obs) : '<span class="adm-missing">Sem observações.</span>');
            addTimeline('Dados atualizados', 'Conservação, situação ou observações alteradas', 'blue', 'pencil');
            return ['Dados atualizados (simulação)', 'As alterações aparecem apenas nesta tela. Nada foi gravado.'];
        },
        localizacao: (form) => {
            const local = [form.elements.setor.value, form.elements.estante.value, form.elements.prateleira.value].join(' / ');
            setField('localizacao', esc(local));
            addTimeline('Localização', `Movido para ${local}`, 'teal', 'pin');
            return ['Localização alterada (simulação)', `Nova localização: ${local}. Nada foi gravado.`];
        },
        manutencao: (form) => {
            setField('situacao', statusBadge('manutencao'));
            addTimeline('Manutenção', `Enviado para manutenção: ${form.elements.motivo.value}`, 'amber', 'wrench');
            return ['Manutenção registrada (simulação)', 'O exemplar aparece como "Em manutenção" apenas nesta tela.'];
        },
        baixa: (form) => {
            addTimeline('Baixa solicitada', `${form.elements.motivo.value}. Aguardando aprovação.`, 'red', 'package');
            return ['Solicitação de baixa registrada (simulação)', 'A situação continua a mesma até a aprovação. Nada foi gravado.'];
        },
    };
    $$('form[data-simulate]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!showErrors(form, requiredErrors(form))) return;
            await simulateSave(form.querySelector('[data-submit]'), { ms: 700 });
            const [title, message] = handlers[form.dataset.simulate](form);
            closeModal(form.closest('dialog'));
            toast(title, message, 'success');
        });
    });
    $$('[data-scroll-to]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault();
        const target = document.getElementById(link.dataset.scrollTo);
        target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        target?.focus({ preventScroll: true });
    }));

    // ---------- Categorias ----------
    const catModal = document.getElementById('modal-categoria');
    if (catModal) {
        const form = catModal.querySelector('[data-categoria-form]');
        const titleEl = catModal.querySelector('[data-categoria-titulo]');
        const collection = collections[0];
        const rowOf = (id) => document.querySelector(`tr[data-categoria-id="${id}"]`);
        const titulosDe = (id) => DATA.categorias.find((c) => String(c.id) === String(id))?.titulos ?? 0;
        let nextId = Math.max(0, ...DATA.categorias.map((c) => c.id)) + 1;

        document.querySelector('[data-categoria-nova]')?.addEventListener('click', () => {
            resetForm(form);
            form.elements.id.value = '';
            titleEl.textContent = 'Cadastrar categoria';
            openModal(catModal);
        });
        document.addEventListener('click', (event) => {
            const edit = event.target.closest('[data-categoria-editar]');
            if (!edit) return;
            const row = rowOf(edit.dataset.categoriaEditar);
            resetForm(form);
            form.elements.id.value = edit.dataset.categoriaEditar;
            form.elements.nome.value = row.querySelector('[data-cat-nome]').textContent;
            form.elements.descricao.value = row.querySelector('[data-cat-descricao]').textContent;
            titleEl.textContent = 'Editar categoria';
            openModal(catModal);
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const errors = requiredErrors(form);
            const nome = form.elements.nome.value.trim().replace(/\s+/g, ' ');
            const id = form.elements.id.value;
            const repetida = $$('tr[data-categoria-id]').some((row) => !row.dataset.archived && row.dataset.categoriaId !== id && normalize(row.querySelector('[data-cat-nome]').textContent) === normalize(nome));
            if (nome && repetida) errors.nome = 'Já existe uma categoria com este nome.';
            if (!showErrors(form, errors)) return;
            await simulateSave(form.querySelector('[data-submit]'), { ms: 600 });
            const descricao = form.elements.descricao.value.trim();
            if (id) {
                const row = rowOf(id);
                row.querySelector('[data-cat-nome]').textContent = nome;
                row.querySelector('[data-cat-descricao]').textContent = descricao;
                row.dataset.search = normalize(nome + ' ' + descricao);
                row.dataset.sortNome = normalize(nome);
                row.classList.add('is-simulated');
            } else {
                const newId = 'novo-' + nextId++;
                const template = collection.rows[0].cloneNode(true);
                template.dataset.categoriaId = newId;
                template.dataset.search = normalize(nome + ' ' + descricao);
                template.dataset.sortNome = normalize(nome);
                template.dataset.sortTitulos = '0';
                template.hidden = false;
                template.classList.add('is-simulated');
                template.querySelector('[data-cat-nome]').innerHTML = `${esc(nome)} <span class="adm-demo-tag">Simulado</span>`;
                template.querySelector('[data-cat-descricao]').textContent = descricao;
                template.querySelector('td[data-label="Títulos"]').innerHTML = '<span class="adm-table__muted">0</span>';
                template.querySelector('[data-categoria-editar]').dataset.categoriaEditar = newId;
                template.querySelector('[data-categoria-arquivar]').dataset.categoriaArquivar = newId;
                DATA.categorias.push({ id: newId, nome, titulos: 0 });
                collection.tbody.prepend(template);
                collection.reload();
            }
            collection.apply();
            closeModal(catModal);
            toast(id ? 'Categoria atualizada (simulação)' : 'Categoria cadastrada (simulação)', `"${nome}" aparece apenas nesta tela. Nada foi gravado.`, 'success');
        });

        const arqModal = document.getElementById('modal-arquivar-categoria');
        const arqForm = arqModal.querySelector('form');
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-categoria-arquivar]');
            if (!btn) return;
            const id = btn.dataset.categoriaArquivar;
            const nome = rowOf(id).querySelector('[data-cat-nome]').childNodes[0].textContent.trim();
            const total = titulosDe(id);
            resetForm(arqForm);
            arqForm.elements.id.value = id;
            arqModal.querySelector('[data-arq-cat-nome]').textContent = nome;
            arqModal.querySelector('[data-arq-cat-uso]').hidden = total === 0;
            arqModal.querySelector('[data-arq-cat-livre]').hidden = total > 0;
            arqModal.querySelector('[data-arq-cat-total]').textContent = plural(total, 'título', 'títulos');
            const destino = arqModal.querySelector('[data-arq-cat-destino]');
            destino.innerHTML = '<option value="">Selecione a categoria de destino</option>' + $$('tr[data-categoria-id]')
                .filter((row) => row.dataset.categoriaId !== id && !row.dataset.archived)
                .map((row) => `<option value="${esc(row.dataset.categoriaId)}">${esc(row.querySelector('[data-cat-nome]').childNodes[0].textContent.trim())}</option>`).join('');
            openModal(arqModal);
        });
        arqForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!showErrors(arqForm, requiredErrors(arqForm))) return;
            await simulateSave(arqForm.querySelector('[data-submit]'), { loading: 'Arquivando...', done: 'Arquivada', ms: 600 });
            const id = arqForm.elements.id.value;
            const row = rowOf(id);
            const nome = row.querySelector('[data-cat-nome]').childNodes[0].textContent.trim();
            const total = titulosDe(id);
            const destino = arqForm.elements.destino;
            row.dataset.archived = '1';
            collection.apply();
            closeModal(arqModal);
            toast('Categoria arquivada (simulação)', total
                ? `${plural(total, 'título seria movido', 'títulos seriam movidos')} de "${nome}" para "${destino.options[destino.selectedIndex].text}". Nada foi gravado.`
                : `"${nome}" foi ocultada apenas nesta tela. Nada foi gravado.`, 'success');
        });
    }

    // ---------- Ações vindas do endereço (ex.: ?acao=adicionar-exemplares) ----------
    const acao = params.get('acao');
    if (acao === 'adicionar-exemplares' && window.admOpenExemplares) {
        window.admOpenExemplares(params.get('id'));
    } else if (acao && document.getElementById('modal-' + acao)) {
        document.querySelector(`[data-modal-open="modal-${acao}"]`)?.click();
    }
})();
