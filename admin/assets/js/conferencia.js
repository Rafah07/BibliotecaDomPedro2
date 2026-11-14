/*
 * Conferência contínua do Inventário (inventario-conferencia.php).
 *
 * Tudo continua funcionando SEM JavaScript (cada ação volta para a mesma página). Com JavaScript,
 * os formulários e links da conferência são enviados em segundo plano e só as regiões da página
 * (data-regiao: área principal, estante atual, indicadores, últimas conferências) são trocadas:
 * sem recarregar, sem perder a pesquisa e com o foco no lugar certo.
 *
 *   * Pesquisa enquanto digita (a partir de 3 letras, 350 ms depois da última tecla, cancelando a
 *     consulta anterior). Código BDP continua sendo lido com Enter.
 *   * Depois de "Salvar e marcar dados como verificados", o registro do exemplar abre sozinho
 *     (o servidor devolve a página com o painel aberto).
 *   * "Próximo livro" limpa a área, mantém a estante atual e volta o foco para a pesquisa.
 *   * Atalhos (fora de campos de texto): / ou F2 pesquisa · N próximo livro · C outra cópia ·
 *     V verificar dados · R registrar exemplar. Alt + a mesma letra funciona até dentro de campos.
 *     Nenhum atalho salva nada sozinho: Ctrl+Enter salva o formulário aberto (ação consciente);
 *     Enter dentro dos formulários de registro não envia, para evitar registros acidentais.
 *   * Clique duplo: o formulário fica bloqueado até a resposta (o servidor também recusa reenvio).
 */
(() => {
    'use strict';

    const raiz = document.querySelector('[data-conferencia]');
    if (!raiz || !window.fetch || !window.DOMParser || !window.history.replaceState) return;
    const base = raiz.dataset.base;
    const campo = document.getElementById('leitura');
    let controle = null;

    const regioes = () => Array.from(document.querySelectorAll('[data-regiao]'));
    const ocupado = (sim) => regioes().forEach((r) => { r.classList.toggle('is-loading', sim); r.setAttribute('aria-busy', sim ? 'true' : 'false'); });
    const semFragmento = (url) => url.split('#')[0];
    const mesmaPagina = (url) => {
        try {
            const u = new URL(url, window.location.href);
            const b = new URL(base, window.location.href);
            return u.origin === b.origin && u.pathname === b.pathname && u.searchParams.get('id') === b.searchParams.get('id');
        } catch (e) { return false; }
    };

    const abrirPaineis = () => {
        document.querySelectorAll('dialog[data-painel-auto]').forEach((d) => {
            if (typeof d.showModal === 'function') {
                if (d.open) d.close();
                try { d.showModal(); } catch (e) { d.setAttribute('open', ''); }
            } else {
                d.setAttribute('open', '');
            }
            const primeiro = d.querySelector('[data-foco], .adm-modal__body input:not([type=hidden]):not([readonly]):not([disabled]), .adm-modal__body select');
            setTimeout(() => primeiro && primeiro.focus(), 40);
        });
    };

    const focar = (ancora) => {
        if (document.querySelector('dialog[open]')) return; // o painel aberto já cuida do foco
        const alvo = (ancora && document.getElementById(ancora)) || document.querySelector('[data-regiao] [data-foco]');
        if (alvo) {
            if (!alvo.hasAttribute('tabindex') && !/^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(alvo.tagName)) alvo.setAttribute('tabindex', '-1');
            alvo.focus({ preventScroll: true });
            alvo.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    };

    /** Troca as regiões da página pelo HTML recebido. Devolve false se a resposta não for da conferência. */
    const aplicar = (html) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const novas = doc.querySelectorAll('[data-regiao]');
        if (!novas.length) return false;
        document.querySelectorAll('dialog[open]').forEach((d) => { if (typeof d.close === 'function') d.close(); });
        novas.forEach((nova) => {
            const atual = document.querySelector(`[data-regiao="${nova.dataset.regiao}"]`);
            if (atual) atual.innerHTML = nova.innerHTML;
        });
        abrirPaineis();
        return true;
    };

    /** Busca uma URL da conferência e troca as regiões. modo: 'push' | 'replace' | 'nenhum'. */
    const navegar = async (url, opcoes = {}, modo = 'push') => {
        if (controle) controle.abort();
        controle = new AbortController();
        const ancora = url.includes('#') ? url.split('#')[1] : '';
        ocupado(true);
        try {
            const resp = await fetch(semFragmento(url), {
                method: opcoes.method || 'GET', body: opcoes.body, credentials: 'same-origin', signal: controle.signal,
                headers: { 'X-Conferencia': '1', Accept: 'text/html' },
            });
            const html = await resp.text();
            if (!aplicar(html)) { window.location.href = resp.url || url; return; }
            const destino = semFragmento(resp.url || url);
            if (modo === 'push' && destino !== window.location.href.split('#')[0]) window.history.pushState({ conferencia: true }, '', destino);
            else if (modo !== 'nenhum') window.history.replaceState({ conferencia: true }, '', destino);
            focar(ancora);
        } catch (erro) {
            if (erro.name === 'AbortError') return;
            // Sem conexão com o servidor: segue o caminho normal (recarrega a página).
            window.location.href = url;
        } finally {
            ocupado(false);
        }
    };

    // ---------- Formulários: enviados em segundo plano ----------
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || e.defaultPrevented) return;
        if (!form.closest('[data-regiao]') && !form.matches('[data-pesquisa]')) return;
        const acao = form.getAttribute('action') || window.location.href;
        if (!mesmaPagina(acao)) return;
        e.preventDefault();
        if (form.dataset.enviando === '1') return; // clique duplo: ignora
        if (form.dataset.confirmar && !window.confirm(form.dataset.confirmar)) return;
        const botao = e.submitter || null;
        let dados;
        try { dados = new FormData(form, botao); } catch (erro) {
            dados = new FormData(form);
            if (botao && botao.name) dados.append(botao.name, botao.value);
        }
        if (form.matches('[data-pesquisa]') && campo) campo.value = campo.value.trim();
        const metodo = (form.getAttribute('method') || 'get').toUpperCase();
        form.dataset.enviando = '1';
        form.querySelectorAll('button[type=submit], button:not([type])').forEach((b) => { b.disabled = true; });
        let url = acao;
        let opcoes = { method: 'POST', body: dados };
        if (metodo === 'GET') {
            const u = new URL(acao, window.location.href);
            dados.forEach((v, k) => u.searchParams.set(k, v));
            url = u.toString();
            opcoes = {};
        }
        navegar(url, opcoes, metodo === 'GET' ? 'push' : 'replace').finally(() => {
            delete form.dataset.enviando;
            form.querySelectorAll('button[type=submit], button:not([type])').forEach((b) => { b.disabled = false; });
            if (form.matches('[data-pesquisa]') && campo && !document.querySelector('dialog[open]') && !document.querySelector('[data-regiao] [data-foco]')) campo.focus();
        });
    });

    // ---------- Links da conferência ----------
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[href]');
        if (!link || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || link.target) return;
        if (link.closest('[data-painel-fechar], .adm-pagination') || link.hasAttribute('data-painel-abrir')) return;
        if (!link.closest('[data-regiao]') && !link.hasAttribute('data-pjax')) return;
        if (!mesmaPagina(link.href)) return;
        e.preventDefault();
        if (link.hasAttribute('data-proximo')) { proximoLivro(); return; }
        navegar(link.href);
    });

    // ---------- Próximo livro ----------
    const proximoLivro = () => {
        navegar(base, {}, 'push').then(() => {
            if (campo) { campo.value = ''; campo.focus(); }
        });
    };

    // ---------- Pesquisa enquanto digita ----------
    if (campo) {
        let espera = null;
        let ultimo = campo.value.trim();
        const pareceCodigo = (t) => /^bdp/i.test(t) || /^\d{1,6}$/.test(t);
        campo.addEventListener('input', () => {
            clearTimeout(espera);
            const texto = campo.value.trim();
            if (texto === ultimo) return;
            if (texto !== '' && (texto.length < 3 || pareceCodigo(texto))) return; // código BDP: Enter
            espera = setTimeout(() => {
                ultimo = texto;
                navegar(texto === '' ? base : `${base}&q=${encodeURIComponent(texto)}`, {}, 'replace');
            }, 350);
        });
        campo.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                const primeiro = document.querySelector('[data-resultados] a');
                if (primeiro) { e.preventDefault(); primeiro.focus(); }
            }
        });
        // Setas dentro da lista de resultados.
        document.addEventListener('keydown', (e) => {
            const lista = e.target.closest && e.target.closest('[data-resultados]');
            if (!lista || !['ArrowDown', 'ArrowUp'].includes(e.key)) return;
            const links = Array.from(lista.querySelectorAll('a.adm-resultado__principal'));
            const atual = links.indexOf(e.target.closest('li')?.querySelector('a.adm-resultado__principal'));
            const prox = e.key === 'ArrowDown' ? atual + 1 : atual - 1;
            e.preventDefault();
            if (prox < 0) campo.focus(); else if (links[prox]) links[prox].focus();
        });
    }

    // ---------- Seletor da estante: aplica ao trocar ----------
    document.addEventListener('change', (e) => {
        const sel = e.target.closest('[data-estante-form] select, [data-estante-form] input[type=checkbox]');
        if (sel && sel.form && sel.form.requestSubmit) sel.form.requestSubmit();
        const todos = e.target.closest('[data-marcar-todos]');
        if (todos) {
            const form = todos.closest('form');
            form.querySelectorAll(`input[name="${todos.dataset.marcarTodos}"]`).forEach((c) => { c.checked = todos.checked; });
        }
    });

    // ---------- Formulários de registro: Enter não salva (Ctrl+Enter salva) ----------
    document.addEventListener('keydown', (e) => {
        const form = e.target.closest && e.target.closest('form[data-sem-enter]');
        if (!form || e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') return;
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            const salvar = form.querySelector('[data-salvar]') || form.querySelector('button.adm-button--primary[type=submit]');
            if (salvar && form.requestSubmit) form.requestSubmit(salvar);
            return;
        }
        if (e.target.matches('input:not([type=checkbox]):not([type=radio]), select')) e.preventDefault();
    });

    // ---------- Atalhos ----------
    const clicar = (seletor) => {
        const el = Array.from(document.querySelectorAll(seletor)).find((x) => x.offsetParent !== null || x.closest('dialog[open]'));
        if (el) { el.click(); return true; }
        return false;
    };
    document.addEventListener('keydown', (e) => {
        const alvo = e.target;
        const digitando = alvo instanceof HTMLElement && (alvo.matches('input, textarea, select') || alvo.isContentEditable);
        if (e.ctrlKey || e.metaKey || (digitando && !e.altKey)) return;
        if (!e.altKey && document.querySelector('dialog[open]')) return; // painel aberto: só Alt+letra
        const tecla = e.key.toLowerCase();
        const mapa = { n: '[data-atalho="proximo"]', c: '[data-atalho="copia"]', v: '[data-atalho="verificar"]', r: '[data-atalho="registrar"]', g: 'dialog[open] [data-atalho="gerar"]' };
        if (tecla === 'n' && !document.querySelector('[data-atalho="proximo"]')) {
            if (campo) { e.preventDefault(); campo.focus(); campo.select(); }
            return;
        }
        if (mapa[tecla] && clicar(mapa[tecla])) e.preventDefault();
    });

    // ---------- Voltar/avançar do navegador ----------
    window.addEventListener('popstate', () => {
        if (mesmaPagina(window.location.href)) {
            const u = new URL(window.location.href);
            if (campo) campo.value = u.searchParams.get('q') || '';
            navegar(window.location.href, {}, 'nenhum');
        }
    });
    window.history.replaceState({ conferencia: true }, '', window.location.href);
    if (document.querySelector('[data-regiao] [data-foco]') && !document.querySelector('dialog[open]')) focar(window.location.hash.slice(1));
})();
