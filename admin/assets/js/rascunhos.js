/*
 * Rascunhos de formulários interrompidos (migração 160).
 * Formulários com data-rascunho="nome" e data-rascunho-ref="referência" são salvos no servidor enquanto
 * a pessoa digita (api/rascunhos/rascunho.php). Ao voltar à página, aparece "Recuperar rascunho".
 * Nunca envia código BDP, CSRF nem tokens (o servidor também filtra). O rascunho não cria exemplar
 * e não ocupa vaga do lote: só o botão Salvar do formulário grava o registro.
 * Delegado: também funciona nos formulários trazidos depois pela conferência contínua.
 */
(function () {
    'use strict';
    if (!window.fetch || !window.FormData) return;
    const PROIBIDOS = /^(csrf|acao|id|codigo|codigo_bdp|codigo_gerado|processo|processo_bdp|chave|token|unico|confirmar)$|token/i;
    const endpoint = () => guessBase() + 'api/rascunhos/rascunho.php';
    function guessBase() {
        const p = window.location.pathname;
        const i = p.indexOf('/admin/');
        return i >= 0 ? p.slice(0, i + 7) : '/admin/';
    }
    const timers = new WeakMap();
    const iniciais = new WeakMap();

    function campos(form) {
        const dados = {};
        new FormData(form).forEach((v, k) => {
            if (typeof v !== 'string' || PROIBIDOS.test(k)) return;
            const nome = k.endsWith('[]') ? k.slice(0, -2) : k;
            if (k.endsWith('[]')) { (dados[nome] = dados[nome] || []).push(v); } else { dados[nome] = v; }
        });
        return dados;
    }

    function preenchido(form) {
        return Object.entries(campos(form)).some(([k, v]) => !/(_id|situacao|estado_conservacao|carimbo|vinculo|tem_linhas|origem_form|situacao_inventario)$/.test(k) && String(v).trim() !== '' && v !== (iniciais.get(form) || {})[k]);
    }

    function enviar(form, extra) {
        const csrf = form.querySelector('input[name="csrf"]');
        if (!csrf) return Promise.resolve(null);
        const corpo = new FormData();
        corpo.append('csrf', csrf.value);
        corpo.append('formulario', form.dataset.rascunho);
        corpo.append('referencia', form.dataset.rascunhoRef);
        if (extra) { Object.entries(extra).forEach(([k, v]) => corpo.append(k, v)); } else {
            Object.entries(campos(form)).forEach(([k, v]) => {
                if (Array.isArray(v)) v.forEach((x) => corpo.append('campos[' + k + '][]', x)); else corpo.append('campos[' + k + ']', v);
            });
        }
        return fetch(endpoint(), { method: 'POST', body: corpo, credentials: 'same-origin', headers: { Accept: 'application/json' }, keepalive: true })
            .then((r) => r.json()).catch(() => null);
    }

    function status(form, texto) {
        let el = form.querySelector('[data-rascunho-status]');
        if (!el) {
            el = document.createElement('p');
            el.className = 'adm-field__hint adm-rascunho-status';
            el.setAttribute('data-rascunho-status', '');
            el.setAttribute('aria-live', 'polite');
            form.appendChild(el);
        }
        el.textContent = texto;
    }

    function agendar(form) {
        clearTimeout(timers.get(form));
        timers.set(form, setTimeout(() => {
            if (!preenchido(form)) return;
            enviar(form).then((r) => { if (r && r.ok && r.salvo_em) status(form, 'Rascunho salvo às ' + r.salvo_em.slice(-5) + '. Se a página fechar, você poderá recuperá-lo.'); });
        }, 1500));
    }

    function aplicar(form, valores) {
        Object.entries(valores).forEach(([k, v]) => {
            if (PROIBIDOS.test(k)) return;
            const lista = Array.isArray(v) ? v : [v];
            const els = form.querySelectorAll('[name="' + CSS.escape(k) + '"], [name="' + CSS.escape(k) + '[]"]');
            els.forEach((el) => {
                if (el.type === 'hidden' || el.disabled || el.readOnly) return;
                if (el.type === 'checkbox' || el.type === 'radio') { el.checked = lista.includes(el.value); } else if (el.tagName === 'SELECT' && el.multiple) {
                    Array.from(el.options).forEach((o) => { o.selected = lista.includes(o.value); });
                } else { el.value = lista[0]; }
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    function iniciar(raiz) {
        (raiz || document).querySelectorAll('form[data-rascunho][data-rascunho-ref]').forEach((form) => {
            if (form.dataset.rascunhoIniciado === '1') return;
            form.dataset.rascunhoIniciado = '1';
            iniciais.set(form, campos(form));
            form.addEventListener('input', () => agendar(form));
            form.addEventListener('change', () => agendar(form));
            const q = new URLSearchParams({ formulario: form.dataset.rascunho, referencia: form.dataset.rascunhoRef });
            fetch(endpoint() + '?' + q, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then((r) => r.json()).then((j) => {
                if (!j || !j.ok || !j.rascunho || form.dataset.rascunhoRecusado === '1') return;
                const r = j.rascunho;
                const aviso = document.createElement('div');
                aviso.className = 'adm-notice adm-notice--amber adm-rascunho';
                aviso.setAttribute('role', 'status');
                const texto = document.createElement('div');
                const forte = document.createElement('strong');
                forte.textContent = 'Há um rascunho deste formulário salvo em ' + r.atualizado_em + (r.lote ? ' (' + r.lote + ')' : '') + '.';
                const p = document.createElement('p');
                p.textContent = 'Recuperar preenche os campos digitados. O código BDP não é guardado no rascunho: gere ou digite o código de novo antes de salvar. Nada foi gravado ainda.'
                    + (r.lote && !r.lote_aberto ? ' Atenção: o ' + r.lote + ' foi fechado; ao salvar, o exemplar só entra se o seu lote ativo tiver vaga.' : '');
                const recuperar = document.createElement('button');
                recuperar.type = 'button';
                recuperar.className = 'adm-button adm-button--primary adm-button--sm';
                recuperar.textContent = 'Recuperar rascunho';
                const descartar = document.createElement('button');
                descartar.type = 'button';
                descartar.className = 'adm-button adm-button--ghost adm-button--sm';
                descartar.textContent = 'Descartar';
                recuperar.addEventListener('click', () => { aplicar(form, r.campos || {}); aviso.remove(); status(form, 'Rascunho recuperado. Confira os campos e salve.'); });
                descartar.addEventListener('click', () => { enviar(form, { descartar: '1' }); form.dataset.rascunhoRecusado = '1'; aviso.remove(); });
                const acoes = document.createElement('p');
                acoes.className = 'adm-actions-inline';
                acoes.append(recuperar, ' ', descartar);
                texto.append(forte, p, acoes);
                aviso.append(texto);
                form.prepend(aviso);
            }).catch(() => {});
        });
    }

    // Antes de sair da página, guarda o que ainda não foi salvo.
    window.addEventListener('pagehide', () => {
        document.querySelectorAll('form[data-rascunho][data-rascunho-ref]').forEach((form) => {
            if (form.dataset.enviando === '1' || !preenchido(form)) return;
            clearTimeout(timers.get(form));
            enviar(form);
        });
    });
    document.addEventListener('submit', (e) => { if (e.target.matches && e.target.matches('form[data-rascunho]')) clearTimeout(timers.get(e.target)); }, true);
    iniciar(document);
    document.addEventListener('conferencia:atualizada', () => iniciar(document));
})();
