// Módulo de classificação CDD: pesquisa nas fontes, revisão, aprovação e lotes.
// Todas as gravações passam pela API (admin/api/cdd.php), com token CSRF.
(function () {
    const dataEl = document.getElementById('cdd-data');
    if (!dataEl) return;
    const CFG = JSON.parse(dataEl.textContent);

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (title, message, tone) => (window.admToast ? window.admToast(title, message || '', tone) : alert(title + '\n' + (message || '')));
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const svg = (p) => `<svg class="icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;
    const ICON = {
        x: svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
        ext: svg('<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'),
        check: svg('<path d="M20 6 9 17l-5-5"/>'),
        alert: svg('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'),
        info: svg('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>'),
        loader: svg('<path d="M21 12a9 9 0 1 1-6.219-8.56"/>'),
        send: svg('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>'),
        play: svg('<polygon points="6 3 20 12 6 21 6 3"/>'),
        layers: svg('<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/>'),
    };

    // ---------- API ----------
    async function api(acao, dados = {}) {
        const body = dados instanceof FormData ? dados : new FormData();
        if (!(dados instanceof FormData)) {
            Object.entries(dados).forEach(([k, v]) => {
                if (Array.isArray(v)) v.forEach((item) => body.append(k + '[]', item));
                else if (v !== undefined && v !== null) body.append(k, v);
            });
        }
        body.set('acao', acao);
        let resposta;
        try {
            resposta = await fetch(CFG.api, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-Token': CFG.csrf, Accept: 'application/json' } });
        } catch (e) {
            throw Object.assign(new Error('Sem conexão com o servidor. Verifique a internet e tente novamente.'), { campos: {} });
        }
        let json = null;
        try { json = await resposta.json(); } catch (e) { /* resposta inesperada */ }
        if (resposta.status === 401) {
            location.href = (CFG.urls.painel || '').replace(/[^/]*$/, '') + 'login.php?voltar=' + encodeURIComponent(location.pathname + location.search);
        }
        if (!json || !json.ok) {
            throw Object.assign(new Error(json?.erro || 'Não foi possível concluir a operação.'), { campos: json?.campos || {}, status: resposta.status });
        }
        return json;
    }

    function busy(button, on, label) {
        if (!button) return;
        if (on) {
            button.dataset.html = button.innerHTML;
            button.disabled = true;
            button.classList.add('is-loading');
            button.innerHTML = ICON.loader + `<span>${esc(label || 'Aguarde...')}</span>`;
        } else if (button.dataset.html) {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.innerHTML = button.dataset.html;
        }
    }

    // ---------- Modais ----------
    function open(dialog) {
        if (typeof dialog.showModal === 'function') { if (!dialog.open) dialog.showModal(); } else dialog.setAttribute('open', '');
    }
    function close(dialog) {
        if (typeof dialog.close === 'function' && dialog.open) dialog.close(); else dialog.removeAttribute('open');
    }

    /**
     * Caixa de diálogo com campos. Resolve com os valores ou null (cancelado).
     * fields: [{ name, label, type: 'textarea'|'text', required, value, hint, placeholder, mono }]
     */
    function formDialog({ title, subtitle = '', tone = 'blue', fields = [], confirm = 'Confirmar', variant = 'primary', intro = '' }) {
        return new Promise((resolve) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'adm-modal';
            const id = 'dlg-' + Math.random().toString(36).slice(2, 8);
            dialog.setAttribute('aria-labelledby', id);
            dialog.innerHTML = `
                <form class="adm-modal__box" method="dialog" novalidate>
                    <header class="adm-modal__header">
                        <span class="adm-icon-box adm-icon-box--sm adm-tone--${tone}">${tone === 'red' ? ICON.alert : ICON.info}</span>
                        <div><h2 id="${id}">${esc(title)}</h2>${subtitle ? `<p>${esc(subtitle)}</p>` : ''}</div>
                        <button type="button" class="adm-modal__close" data-cancel aria-label="Fechar">${ICON.x}</button>
                    </header>
                    <div class="adm-modal__body">
                        ${intro}
                        <div class="adm-form-grid">
                        ${fields.map((f) => `
                            <label class="adm-field adm-field--full">
                                <span class="adm-field__label">${esc(f.label)} ${f.required ? '<span class="adm-required" aria-hidden="true">*</span>' : '<span class="adm-optional">opcional</span>'}</span>
                                ${f.type === 'textarea'
                                    ? `<textarea class="adm-textarea" name="${f.name}" rows="3" maxlength="5000" placeholder="${esc(f.placeholder || '')}">${esc(f.value || '')}</textarea>`
                                    : `<input class="adm-input${f.mono ? ' adm-input--cdd' : ''}" name="${f.name}" value="${esc(f.value || '')}" maxlength="255" placeholder="${esc(f.placeholder || '')}" autocomplete="off">`}
                                ${f.hint ? `<span class="adm-field__hint">${esc(f.hint)}</span>` : ''}
                                <span class="adm-field__error" data-error-for="${f.name}"></span>
                            </label>`).join('')}
                        </div>
                    </div>
                    <footer class="adm-modal__footer">
                        <button type="button" class="adm-button adm-button--ghost" data-cancel>Cancelar</button>
                        <button type="submit" class="adm-button adm-button--${variant}">${esc(confirm)}</button>
                    </footer>
                </form>`;
            document.body.appendChild(dialog);
            const form = dialog.querySelector('form');
            let result = null;
            dialog.addEventListener('click', (e) => { if (e.target.closest('[data-cancel]')) close(dialog); });
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const values = Object.fromEntries(new FormData(form));
                let ok = true;
                fields.forEach((f) => {
                    const err = form.querySelector(`[data-error-for="${f.name}"]`);
                    const msg = f.required && !String(values[f.name] || '').trim() ? 'Preencha este campo.' : (f.validate ? f.validate(values[f.name]) : '');
                    err.textContent = msg || '';
                    err.closest('.adm-field').classList.toggle('is-invalid', !!msg);
                    if (msg) ok = false;
                });
                if (!ok) return;
                result = values;
                close(dialog);
            });
            dialog.addEventListener('close', () => { dialog.remove(); resolve(result); });
            open(dialog);
            setTimeout(() => form.querySelector('textarea, input')?.focus(), 30);
        });
    }

    // ---------- Validação da CDD (mesma regra de cdd_validar no PHP) ----------
    function validarCdd(valor) {
        const v = String(valor || '').trim();
        if (!v) return { valida: false, erro: 'Informe a CDD.' };
        if (v.includes(',')) return { valida: false, erro: 'Use ponto para separar os decimais (ex.: 869.3), não vírgula.' };
        const n = v.replace(/[\/'’\s]/g, '');
        if (!/^\d{3}(\.\d+)?$/.test(n)) return { valida: false, erro: 'Formato inválido. Use três dígitos e, se houver, decimais depois do ponto (ex.: 869.3 ou 028.5).' };
        const avisos = [];
        if (n !== v) avisos.push('As marcas de segmentação (/ ou \') serão removidas.');
        if (/\.\d*0$/.test(n)) avisos.push('Números da CDD não terminam em zero depois do ponto. Confira o número.');
        return { valida: true, numero: n, avisos };
    }

    // ---------- Resultados de pesquisa ----------
    function renderFontes(fontes) {
        return Object.values(fontes || {}).map((f) => {
            const tom = f.status === 'ok' ? 'green' : f.status === 'erro' ? 'red' : 'muted';
            const texto = f.status === 'ok' ? `${f.total} registro(s)` : f.status === 'erro' ? (f.mensagem || 'falha na consulta') : 'nenhum registro';
            return `<span class="adm-status adm-status--soft adm-tone--${tom}">${esc(f.nome)}: ${esc(texto)}</span>`;
        }).join(' ');
    }

    function renderRegistro(r, selecionavel) {
        const cdds = r.cdds || (r.cdd ? [{ numero: r.cdd, ddc_edicao: r.ddc_edicao }] : []);
        const podeUsar = selecionavel && r.cdd && r.compatibilidade !== 'incompativel';
        const tom = r.compat_tom || { exata: 'green', provavel: 'blue', incerta: 'amber', incompativel: 'red' }[r.compatibilidade] || 'amber';
        const label = r.compat_label || { exata: 'Mesma edição', provavel: 'Provável', incerta: 'Incerta', incompativel: 'Não corresponde' }[r.compatibilidade] || '';
        return `
            <article class="adm-result adm-result--${esc(r.compatibilidade)}">
                <div class="adm-result__cdd">
                    ${cdds.length
                        ? cdds.map((c) => `<strong class="adm-cdd adm-cdd--lg">${esc(c.numero)}</strong>${c.ddc_edicao ? `<small>CDD ed. ${esc(c.ddc_edicao)}</small>` : ''}`).join('')
                        : '<span class="adm-missing">Sem CDD no registro</span>'}
                </div>
                <div class="adm-result__body">
                    <p class="adm-result__source">${esc(r.fonte_nome)} <span class="adm-status adm-status--soft adm-tone--${tom}">${esc(label)}</span></p>
                    <p class="adm-result__title">${esc(r.titulo || 'Título não informado')}</p>
                    <p class="adm-table__muted">${esc([r.autor, r.editora, r.ano, r.edicao].filter(Boolean).join(' · ') || 'Sem dados de publicação')}</p>
                    ${r.isbn ? `<p class="adm-table__muted adm-mono">ISBN ${esc(r.isbn)}</p>` : ''}
                    ${r.lcc ? `<p class="adm-table__muted" title="Library of Congress Classification: outro sistema, não é CDD">LCC (não é CDD): <span class="adm-mono">${esc(r.lcc)}</span></p>` : ''}
                    ${(r.motivos || []).length ? `<ul class="adm-result__reasons">${r.motivos.map((m) => `<li>${esc(m)}</li>`).join('')}</ul>` : ''}
                </div>
                <div class="adm-result__actions">
                    ${r.registro_url ? `<a class="adm-button adm-button--ghost adm-button--sm" href="${esc(r.registro_url)}" target="_blank" rel="noopener noreferrer">${ICON.ext}<span>Ver registro</span></a>` : ''}
                    ${podeUsar ? `<button type="button" class="adm-button adm-button--primary adm-button--sm" data-usar-consulta="${esc(r.id)}">${ICON.check}<span>Usar esta CDD</span></button>` : ''}
                </div>
            </article>`;
    }

    function renderPesquisa(container, pesquisa, { selecionavel = false } = {}) {
        if (!container) return;
        if (!pesquisa) { container.innerHTML = ''; return; }
        const registros = pesquisa.consultas || pesquisa.registros || [];
        const comCdd = registros.some((r) => r.cdd || (r.cdds && r.cdds.length));
        const tom = pesquisa.resultado === 'com_cdd' ? 'green' : pesquisa.resultado === 'conflito' ? 'red' : pesquisa.resultado === 'erro' ? 'red' : 'amber';
        const mensagem = pesquisa.mensagem || (comCdd ? '' : CFG.semResultado);
        container.innerHTML = `
            <div class="adm-results-head">
                <p class="adm-results-head__sources">${renderFontes(pesquisa.fontes)}</p>
                ${pesquisa.consultado_em ? `<small>Última pesquisa: ${esc(pesquisa.consultado_em)}</small>` : ''}
            </div>
            ${mensagem ? `<div class="adm-notice adm-notice--${tom} adm-notice--compact" role="status">${tom === 'green' ? ICON.check : ICON.info}<div><strong>${esc(mensagem)}</strong>${comCdd ? '<p>Confira se o registro é a mesma edição antes de usar o número. Toda sugestão precisa de validação.</p>' : ''}</div></div>` : ''}
            <div class="adm-results-list">${registros.map((r) => renderRegistro(r, selecionavel)).join('')}</div>`;
        container._registros = registros;
    }

    // ============================================================
    // Painel: seleção, lotes e pesquisa livre
    // ============================================================
    if (CFG.pagina === 'painel') {
        const bar = $('[data-selection-bar]');
        const selecionados = new Set();
        const atualizarBarra = () => {
            if (!bar) return;
            bar.hidden = selecionados.size === 0;
            $('[data-selection-count]', bar).textContent = selecionados.size === 1 ? '1 livro selecionado' : `${selecionados.size} livros selecionados`;
            const visiveis = $$('tr[data-row]:not([hidden]) [data-select-row]');
            const todos = $('[data-select-page]');
            if (todos) {
                todos.checked = visiveis.length > 0 && visiveis.every((c) => c.checked);
                todos.indeterminate = !todos.checked && visiveis.some((c) => c.checked);
            }
        };
        document.addEventListener('change', (e) => {
            if (e.target.matches('[data-select-row]')) {
                e.target.checked ? selecionados.add(e.target.value) : selecionados.delete(e.target.value);
                e.target.closest('tr').classList.toggle('is-selected', e.target.checked);
                atualizarBarra();
            } else if (e.target.matches('[data-select-page]')) {
                $$('tr[data-row]:not([hidden]) [data-select-row]').forEach((c) => {
                    c.checked = e.target.checked;
                    c.checked ? selecionados.add(c.value) : selecionados.delete(c.value);
                    c.closest('tr').classList.toggle('is-selected', c.checked);
                });
                atualizarBarra();
            }
        });
        // A paginação e os filtros mudam as linhas visíveis.
        const tbody = $('[data-cdd-tabela] [data-rows]');
        if (tbody) new MutationObserver(atualizarBarra).observe(tbody, { attributes: true, subtree: true, attributeFilter: ['hidden'] });
        $('[data-selection-clear]')?.addEventListener('click', () => {
            selecionados.clear();
            $$('[data-select-row]').forEach((c) => { c.checked = false; c.closest('tr').classList.remove('is-selected'); });
            atualizarBarra();
        });

        $('[data-enviar-lote]')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            busy(btn, true, 'Enviando...');
            try {
                const r = await api('enviar_revisao_lote', { livros: [...selecionados] });
                toast('Encaminhado para aprovação', r.mensagem, r.enviados ? 'success' : 'warning');
                if (r.enviados) setTimeout(() => location.reload(), 900);
            } catch (err) {
                toast('Não foi possível enviar', err.message, 'error');
            } finally { busy(btn, false); }
        });

        // ----- Lotes -----
        const lotesBox = $('[data-lotes]');
        const lista = $('[data-lotes-lista]');
        const rodando = new Set();

        function renderLote(li, lote) {
            const ativo = ['pendente', 'processando'].includes(lote.status);
            const nesteNavegador = rodando.has(lote.id);
            const g = lote.grupos || {};
            const sugeridos = lote.itens.filter((i) => i.resultado === 'com_cdd' && i.status_cdd === 'sugerida').map((i) => i.livro_id);
            const coinc = Object.entries(lote.coincidentes || {});
            const titulo = (id) => esc(lote.itens.find((i) => i.livro_id === Number(id))?.titulo || '');
            const statusTxt = { pendente: 'Aguardando', processando: nesteNavegador ? 'Pesquisando...' : 'Pausado', concluido: 'Concluído', cancelado: 'Cancelado' }[lote.status];
            li.className = 'adm-batch__item';
            li.dataset.loteStatus = lote.status;
            li.innerHTML = `
                <div class="adm-batch__head">
                    <div><strong>${esc(lote.nome)}</strong><small>${esc(statusTxt)} · ${lote.processados} de ${lote.total} pesquisados</small></div>
                    <div class="adm-batch__buttons">
                        ${ativo && !nesteNavegador ? `<button type="button" class="adm-button adm-button--primary adm-button--sm" data-lote-continuar="${lote.id}">${ICON.play}<span>Continuar</span></button>` : ''}
                        ${ativo ? `<button type="button" class="adm-button adm-button--ghost adm-button--sm" data-lote-cancelar="${lote.id}">${ICON.x}<span>Cancelar</span></button>` : ''}
                        ${!ativo && lote.status !== 'cancelado' && g.erro ? `<button type="button" class="adm-button adm-button--ghost adm-button--sm" data-lote-repetir="${lote.id}">${ICON.play}<span>Repetir ${g.erro} falha(s)</span></button>` : ''}
                        ${sugeridos.length ? `<button type="button" class="adm-button adm-button--ghost adm-button--sm" data-lote-enviar="${sugeridos.join(',')}">${ICON.send}<span>Enviar ${sugeridos.length} sugestão(ões) para aprovação</span></button>` : ''}
                    </div>
                </div>
                <div class="adm-progress" role="progressbar" aria-valuemin="0" aria-valuemax="${lote.total}" aria-valuenow="${lote.processados}" aria-label="Progresso do lote">
                    <span class="adm-progress__bar adm-progress__bar--ok" style="width:${lote.percentual}%"></span>
                </div>
                <ul class="adm-batch__counts">
                    <li><span class="adm-dot adm-dot--ok"></span> Com sugestão: <strong>${g.com_cdd || 0}</strong></li>
                    <li><span class="adm-dot adm-dot--warn"></span> Conflitos: <strong>${g.conflito || 0}</strong></li>
                    <li><span class="adm-dot adm-dot--muted"></span> Registro sem CDD: <strong>${g.sem_cdd || 0}</strong></li>
                    <li><span class="adm-dot adm-dot--muted"></span> Sem correspondência: <strong>${g.sem_registro || 0}</strong></li>
                    ${g.erro ? `<li><span class="adm-dot adm-dot--warn"></span> Falhas: <strong>${g.erro}</strong></li>` : ''}
                </ul>
                ${coinc.length ? `<details class="adm-batch__details"><summary>Sugestões coincidentes (${coinc.length} número(s))</summary>
                    <p class="adm-table__muted">Livros diferentes com a mesma CDD sugerida. Cada edição continua sendo aprovada individualmente.</p>
                    <ul>${coinc.map(([n, ids]) => `<li><strong class="adm-cdd">${esc(n)}</strong> ${ids.map((id) => `<a class="adm-link" href="${esc(CFG.urls.classificar)}?id=${id}">${titulo(id)}</a>`).join(', ')}</li>`).join('')}</ul></details>` : ''}
                <details class="adm-batch__details"><summary>Ver todos os livros do lote</summary>
                    <ul class="adm-batch__items">${lote.itens.map((i) => `
                        <li><a class="adm-link" href="${esc(CFG.urls.classificar)}?id=${i.livro_id}">${esc(i.titulo)}</a>
                        <small>${i.status === 'pendente' ? 'aguardando pesquisa' : esc(i.mensagem || '')}${i.cdd_proposta ? ` · proposta <span class="adm-mono">${esc(i.cdd_proposta)}</span>` : ''} · ${esc(i.status_cdd_label)}</small></li>`).join('')}</ul></details>`;
        }

        async function carregarLote(li) {
            try {
                const r = await api('lote_status', { lote_id: li.dataset.loteId });
                renderLote(li, r.lote);
            } catch (e) { li.remove(); }
        }

        async function processar(loteId) {
            if (rodando.has(loteId)) return;
            rodando.add(loteId);
            const li = lista.querySelector(`[data-lote-id="${loteId}"]`);
            try {
                let lote;
                for (;;) {
                    lote = (await api('lote_processar', { lote_id: loteId })).lote;
                    if (li.isConnected) renderLote(li, lote);
                    if (!['pendente', 'processando'].includes(lote.status) || !rodando.has(loteId)) break;
                    await wait(250); // o servidor já respeita o intervalo de cada fonte
                }
                if (lote.status === 'concluido') {
                    const falhas = lote.grupos.erro || 0;
                    falhas
                        ? toast('Lote concluído com falhas', `${falhas} livro(s) não puderam ser pesquisados porque as fontes não responderam. Use "Repetir falhas" mais tarde.`, 'warning')
                        : toast('Pesquisa em lote concluída', 'Revise as sugestões encontradas e envie para aprovação.', 'success');
                }
            } catch (e) {
                toast('Pesquisa em lote interrompida', e.message + ' Você pode continuar depois.', 'warning');
            } finally {
                rodando.delete(loteId);
                if (li.isConnected) carregarLote(li);
            }
        }

        lista && $$('[data-lote-id]', lista).forEach(carregarLote);
        lista?.addEventListener('click', async (e) => {
            const cont = e.target.closest('[data-lote-continuar]');
            const canc = e.target.closest('[data-lote-cancelar]');
            const env = e.target.closest('[data-lote-enviar]');
            if (cont) processar(Number(cont.dataset.loteContinuar));
            const rep = e.target.closest('[data-lote-repetir]');
            if (rep) {
                try {
                    await api('lote_repetir', { lote_id: rep.dataset.loteRepetir });
                    processar(Number(rep.dataset.loteRepetir));
                } catch (err) { toast('Erro', err.message, 'error'); }
            }
            if (canc) {
                const id = Number(canc.dataset.loteCancelar);
                rodando.delete(id);
                try { const r = await api('lote_cancelar', { lote_id: id }); renderLote(canc.closest('li'), r.lote); } catch (err) { toast('Erro', err.message, 'error'); }
            }
            if (env) {
                busy(env, true, 'Enviando...');
                try {
                    const r = await api('enviar_revisao_lote', { livros: env.dataset.loteEnviar.split(',') });
                    toast('Encaminhado para aprovação', r.mensagem, 'success');
                    carregarLote(env.closest('li'));
                } catch (err) { toast('Não foi possível enviar', err.message, 'error'); busy(env, false); }
            }
        });

        const modalLote = $('#modal-lote');
        $('[data-lote-iniciar]')?.addEventListener('click', () => {
            $('[data-lote-resumo]', modalLote).textContent = `${selecionados.size} livro(s) serão pesquisados um de cada vez, sem bloquear o painel.`;
            open(modalLote);
        });
        modalLote?.querySelector('form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fontes = $$('input[name="fontes[]"]:checked', modalLote).map((c) => c.value);
            if (!fontes.length) return toast('Selecione uma fonte', 'Escolha ao menos uma fonte para pesquisar.', 'warning');
            const btn = e.submitter || $('[type=submit]', modalLote);
            busy(btn, true, 'Criando lote...');
            try {
                const r = await api('lote_criar', { livros: [...selecionados], fontes });
                close(modalLote);
                lotesBox.hidden = false;
                const li = document.createElement('li');
                li.dataset.loteId = r.lote.id;
                lista.prepend(li);
                renderLote(li, r.lote);
                lotesBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
                $('[data-selection-clear]')?.click();
                processar(r.lote.id);
            } catch (err) {
                toast('Não foi possível criar o lote', err.message, 'error');
            } finally { busy(btn, false); }
        });
        window.addEventListener('beforeunload', (e) => {
            if (rodando.size) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    // ---------- Pesquisa livre (modal do painel) ----------
    const livre = $('[data-pesquisa-livre]');
    livre?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.submitter || $('[type=submit]', livre);
        const box = $('[data-resultados]', livre);
        busy(btn, true, 'Pesquisando...');
        box.innerHTML = '<p class="adm-table__muted">Consultando as fontes...</p>';
        try {
            const r = await api('pesquisa_livre', new FormData(livre));
            renderPesquisa(box, { registros: r.registros, fontes: r.fontes, mensagem: r.mensagem, resultado: r.mensagem ? 'sem_registro' : 'com_cdd' });
        } catch (err) {
            box.innerHTML = `<div class="adm-notice adm-notice--red adm-notice--compact">${ICON.alert}<div><strong>${esc(err.message)}</strong></div></div>`;
        } finally { busy(btn, false); }
    });

    // ============================================================
    // Revisão individual
    // ============================================================
    if (CFG.pagina === 'livro') {
        const form = $('[data-classificacao-form]');
        const resultados = $('[data-resultados]');
        const cddInput = $('[data-cdd-input]', form);
        const feedback = $('[data-cdd-feedback]', form);
        const hintPadrao = feedback.textContent;
        renderPesquisa(resultados, CFG.pesquisa, { selecionavel: CFG.pode.sugerir });

        let validarTimer;
        cddInput.addEventListener('input', () => {
            cddInput.closest('.adm-field').classList.remove('is-invalid');
            $('[data-error-for="cdd"]', form).textContent = '';
            clearTimeout(validarTimer);
            validarTimer = setTimeout(mostrarValidacao, 250);
        });
        cddInput.addEventListener('blur', async () => {
            const local = validarCdd(cddInput.value);
            if (!local.valida || !cddInput.value.trim()) return;
            try {
                const r = await api('validar', { cdd: cddInput.value });
                const v = r.validacao;
                if (v.referencia) feedback.innerHTML = `<span class="adm-text-green">${ICON.check} ${esc(v.referencia)}</span>`;
                else if (v.avisos?.length) feedback.textContent = v.avisos.join(' ');
            } catch (e) { /* validação local continua valendo */ }
        });
        function mostrarValidacao() {
            if (!cddInput.value.trim()) { feedback.textContent = hintPadrao; feedback.classList.remove('is-warning'); return; }
            const v = validarCdd(cddInput.value);
            feedback.classList.toggle('is-warning', !v.valida || v.avisos.length > 0);
            feedback.textContent = v.valida ? (v.avisos.length ? v.avisos.join(' ') : 'Formato válido. Confira se a classe é adequada ao assunto do livro.') : v.erro;
        }
        mostrarValidacao();

        // Pesquisa
        const pForm = $('[data-pesquisa-form]');
        pForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = e.submitter || $('[type=submit]', pForm);
            const fd = new FormData(pForm);
            fd.set('livro_id', CFG.livroId);
            busy(btn, true, 'Pesquisando...');
            resultados.innerHTML = '<p class="adm-table__muted">Consultando as fontes bibliográficas. Isso pode levar alguns segundos...</p>';
            try {
                const r = await api('pesquisar', fd);
                renderPesquisa(resultados, { ...r.pesquisa, consultado_em: 'agora' }, { selecionavel: true });
                const tone = r.resultado === 'com_cdd' ? 'success' : r.resultado === 'conflito' || r.resultado === 'erro' ? 'warning' : 'info';
                toast(r.resultado === 'com_cdd' ? 'Sugestão encontrada' : 'Pesquisa concluída', r.mensagem, tone);
                if (r.classificacao) form.elements.versao.value = r.classificacao.versao;
                if (r.resultado === 'com_cdd' && !cddInput.value && r.classificacao?.cdd_proposta) {
                    const reg = r.pesquisa.consultas.find((c) => c.cdd === r.classificacao.cdd_proposta);
                    if (reg) usarConsulta(reg, false);
                }
            } catch (err) {
                resultados.innerHTML = `<div class="adm-notice adm-notice--red adm-notice--compact">${ICON.alert}<div><strong>${esc(err.message)}</strong></div></div>`;
            } finally { busy(btn, false); }
        });

        function usarConsulta(reg, avisar = true) {
            cddInput.value = reg.cdd;
            form.elements.fonte_nome.value = reg.fonte_nome;
            form.elements.fonte_url.value = reg.registro_url || '';
            form.elements.consulta_id.value = reg.id;
            form.elements.data_consulta.value = new Date().toISOString().slice(0, 10);
            mostrarValidacao();
            if (avisar) {
                toast('CDD copiada para o formulário', 'Revise a descrição e registre a sugestão.', 'info');
                cddInput.focus();
                cddInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        resultados?.addEventListener('click', (e) => {
            const b = e.target.closest('[data-usar-consulta]');
            if (!b) return;
            const reg = (resultados._registros || []).find((r) => String(r.id) === b.dataset.usarConsulta);
            if (reg) usarConsulta(reg);
        });
        // Editar a CDD manualmente desfaz o vínculo com o registro escolhido.
        cddInput.addEventListener('change', () => {
            const reg = (resultados?._registros || []).find((r) => String(r.id) === form.elements.consulta_id.value);
            if (reg && reg.cdd !== validarCdd(cddInput.value).numero) form.elements.consulta_id.value = '';
        });

        function limparErros() {
            $('[data-form-errors]', form).hidden = true;
            $$('.adm-field.is-invalid', form).forEach((f) => f.classList.remove('is-invalid'));
            $$('[data-error-for]', form).forEach((e) => { e.textContent = ''; });
        }
        function mostrarErros(err) {
            const box = $('[data-form-errors]', form);
            $('[data-form-errors-text]', box).textContent = err.message;
            box.hidden = false;
            Object.entries(err.campos || {}).forEach(([campo, msg]) => {
                const el = $(`[data-error-for="${campo}"]`, form);
                if (el) { el.textContent = msg; el.closest('.adm-field')?.classList.add('is-invalid'); }
            });
            (form.querySelector('.is-invalid input, .is-invalid textarea') || box).focus?.();
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function precisaCdd(tipo) {
            return ['sugerir', 'rascunho', 'enviar_revisao', 'aprovar', 'corrigir'].includes(tipo);
        }

        async function executar(tipo, botao, { proximo = false, extra = {} } = {}) {
            limparErros();
            if (precisaCdd(tipo) && (cddInput.value.trim() || tipo !== 'enviar_revisao')) {
                const v = validarCdd(cddInput.value);
                if (!v.valida) return mostrarErros({ message: v.erro, campos: { cdd: v.erro } });
            }
            if (['sugerir', 'enviar_revisao', 'aprovar'].includes(tipo) && !form.elements.fonte_nome.value.trim()) {
                return mostrarErros({ message: 'Informe a fonte bibliográfica utilizada.', campos: { fonte_nome: 'Informe de onde veio a classificação.' } });
            }
            const fd = new FormData(form);
            fd.set('tipo', tipo);
            fd.set('livro_id', CFG.livroId);
            Object.entries(extra).forEach(([k, v]) => fd.set(k, v));
            if (proximo) fd.set('proximo', '1');
            busy(botao, true, 'Salvando...');
            try {
                const r = await api('executar', fd);
                toast(r.classificacao.status_label, r.mensagem, 'success');
                if (proximo) {
                    location.href = r.proximo_url || CFG.urls.painel;
                } else {
                    setTimeout(() => location.reload(), 700);
                }
            } catch (err) {
                if (err.status === 409) toast('Classificação alterada por outra pessoa', err.message, 'warning');
                mostrarErros(err);
                busy(botao, false);
            }
        }

        form.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-acao]');
            if (!b) return;
            const acao = b.dataset.acao;
            if (acao === 'salvar_proximo') {
                const como = form.querySelector('input[name="salvar_como"]:checked')?.value || 'enviar_revisao';
                return executar(como, b, { proximo: true });
            }
            if (acao === 'aprovar') {
                const proposta = validarCdd(cddInput.value).numero;
                const ok = await formDialog({
                    title: `Aprovar a CDD ${proposta || ''}?`, tone: 'green', confirm: 'Aprovar CDD', variant: 'success',
                    intro: '<p class="adm-modal__text">Confirme que conferiu o livro e a fonte. A aprovação fica registrada com seu nome, data e horário. A CDD antiga da planilha é preservada.</p>',
                    fields: [{ name: 'observacao', label: 'Observação da aprovação', type: 'textarea' }],
                });
                if (ok) executar('aprovar', b, { extra: ok });
                return;
            }
            if (acao === 'rejeitar') {
                const ok = await formDialog({
                    title: 'Rejeitar sugestão', tone: 'red', confirm: 'Rejeitar', variant: 'danger',
                    subtitle: 'O livro volta para a fila e fica marcado como "precisa de revisão".',
                    fields: [{ name: 'observacao', label: 'Motivo', type: 'textarea', required: true, placeholder: 'Ex.: o registro é de outra edição; a classe não corresponde ao assunto.' }],
                });
                if (ok) executar('rejeitar', b, { extra: ok });
                return;
            }
            executar(acao, b);
        });
        // Ctrl+Enter salva e abre o próximo livro.
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const b = $('[data-acao="salvar_proximo"]', form);
                if (b && !b.disabled) { e.preventDefault(); b.click(); }
            }
        });
    }

    // ============================================================
    // Fila de aprovação
    // ============================================================
    if (CFG.pagina === 'revisao') {
        const contador = $('[data-fila-contagem]');
        document.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-revisao]');
            if (!b) return;
            const card = b.closest('[data-fila-item]');
            const tipo = b.dataset.revisao;
            const titulo = card.dataset.titulo;
            const configs = {
                aprovar: { title: `Aprovar ${card.dataset.cdd} para “${titulo}”?`, tone: 'green', confirm: 'Aprovar', variant: 'success',
                    intro: '<p class="adm-modal__text">A aprovação registra a CDD final, seu nome, a data, o horário e a fonte.</p>',
                    fields: [{ name: 'justificativa', label: 'Justificativa', type: 'textarea' }] },
                corrigir: { title: `Corrigir a classificação de “${titulo}”`, confirm: 'Corrigir e aprovar', variant: 'primary',
                    fields: [
                        { name: 'cdd', label: 'CDD correta', required: true, value: card.dataset.cdd, mono: true, validate: (v) => (validarCdd(v).valida ? (v.trim() === card.dataset.cdd ? 'Informe um número diferente do proposto, ou use Aprovar.' : '') : validarCdd(v).erro) },
                        { name: 'fonte_nome', label: 'Fonte utilizada na correção', required: true, placeholder: 'Ex.: WebDewey (acesso licenciado)' },
                        { name: 'justificativa', label: 'Justificativa', type: 'textarea', required: true },
                    ] },
                rejeitar: { title: `Rejeitar a sugestão para “${titulo}”?`, tone: 'red', confirm: 'Rejeitar', variant: 'danger',
                    fields: [{ name: 'observacao', label: 'Motivo', type: 'textarea', required: true }] },
                nova_pesquisa: { title: 'Solicitar nova pesquisa', subtitle: titulo, tone: 'amber', confirm: 'Solicitar',
                    fields: [{ name: 'observacao', label: 'O que deve ser pesquisado de novo?', type: 'textarea', required: true }] },
                observacao: { title: 'Adicionar observação', subtitle: titulo, confirm: 'Registrar',
                    fields: [{ name: 'observacao', label: 'Observação', type: 'textarea', required: true }] },
            };
            const valores = await formDialog(configs[tipo]);
            if (!valores) return;
            busy(b, true, 'Salvando...');
            try {
                const r = await api('executar', { ...valores, tipo, livro_id: card.dataset.livroId, versao: card.dataset.versao });
                toast(r.classificacao.status_label, r.mensagem, 'success');
                if (tipo === 'observacao') {
                    card.dataset.versao = r.classificacao.versao;
                    busy(b, false);
                    return;
                }
                card.classList.add('is-done');
                setTimeout(() => {
                    card.remove();
                    const restantes = $$('[data-fila-item]').length;
                    if (contador) contador.textContent = restantes === 1 ? '1 livro na fila' : `${restantes} livros na fila`;
                    if (!restantes) location.reload();
                }, 350);
            } catch (err) {
                toast('Não foi possível salvar', err.message, 'error');
                busy(b, false);
            }
        });
    }
})();
