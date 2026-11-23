/*
 * Gestão permanente do acervo (migração 190). Sem dependências.
 *
 * 1. [data-edicao-obra]      aviso de edição simultânea: confirma a cada N segundos que o formulário
 *                            continua aberto e mostra quem mais está editando. Ao sair, retira a marca.
 *                            É só um aviso: nunca bloqueia nada (a marca expira sozinha no servidor).
 * 2. [data-sugestoes=campo]  sugestões de valores já cadastrados (editora, autor, categoria) num
 *                            <datalist>. Só sugere: um valor novo continua aceito.
 * 3. [data-protegido=chave]  guarda o formulário no navegador enquanto a pessoa digita. Se a página
 *                            cair (conexão, navegador fechado, erro do servidor), os dados voltam ao
 *                            abrir de novo. Só é apagado quando o servidor confirma o registro
 *                            ([data-protegido-concluido=chave] na página de resultado). Nunca guarda
 *                            o token CSRF. A chave da operação vai junto: reenviar não duplica nada.
 * 4. [data-registro-varios]  linhas "Cópia 1, 2, 3..." acompanham a quantidade; "conferi todas as
 *                            cópias" mostra o estado comum e esconde a conservação de cada cópia.
 */
(function () {
    'use strict';
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
    const csrf = () => ($('input[name="csrf"]') || {}).value || '';
    const base = () => {
        const p = window.location.pathname;
        const i = p.indexOf('/admin/');
        return i >= 0 ? p.slice(0, i + 7) : '/admin/';
    };

    // ------------------------------------------------------------ 1. edição simultânea
    const edicao = $('[data-edicao-obra]');
    if (edicao && window.fetch && window.FormData) {
        const livro = edicao.dataset.edicaoObra;
        const url = edicao.dataset.edicaoUrl;
        const intervalo = Math.max(10, parseInt(edicao.dataset.edicaoIntervalo || '30', 10)) * 1000;
        const aviso = $('[data-aviso-edicao]');
        const texto = $('[data-aviso-edicao-texto]');
        const enviar = (acao) => {
            const corpo = new FormData();
            corpo.append('csrf', csrf());
            corpo.append('livro_id', livro);
            corpo.append('acao', acao);
            return corpo;
        };
        const renovar = () => {
            if (document.hidden) return;
            fetch(url, { method: 'POST', body: enviar('renovar'), credentials: 'same-origin' })
                .then((r) => r.json()).then((d) => {
                    if (!d || !d.ok || !aviso) return;
                    if (d.aviso) {
                        texto.textContent = d.aviso + ' Você pode continuar: se a outra pessoa salvar antes, o sistema mostra as diferenças em vez de sobrescrever.';
                        aviso.hidden = false;
                    } else {
                        aviso.hidden = true;
                    }
                }).catch(() => { /* sem conexão: o aviso só deixa de ser atualizado */ });
        };
        setInterval(renovar, intervalo);
        document.addEventListener('visibilitychange', renovar);
        let enviando = false;
        edicao.addEventListener('submit', () => { enviando = true; }, true);
        window.addEventListener('pagehide', () => {
            if (!enviando && navigator.sendBeacon) navigator.sendBeacon(url, enviar('sair'));
        });
    }

    // ------------------------------------------------------------ 2. sugestões de padronização
    $$('[data-sugestoes]').forEach((input) => {
        const lista = input.list || (input.getAttribute('list') && document.getElementById(input.getAttribute('list')));
        if (!lista || !window.fetch) return;
        let timer = null;
        let ultimo = '';
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2 || q === ultimo) return;
            timer = setTimeout(() => {
                ultimo = q;
                fetch(base() + 'api/acervo/valores.php?campo=' + encodeURIComponent(input.dataset.sugestoes) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then((r) => r.json()).then((d) => {
                        if (!d || !d.ok) return;
                        lista.innerHTML = '';
                        d.valores.forEach((v) => {
                            const o = document.createElement('option');
                            o.value = v.valor;
                            o.label = v.obras + (v.obras === 1 ? ' obra' : ' obras');
                            lista.appendChild(o);
                        });
                    }).catch(() => {});
            }, 250);
        });
    });

    // ------------------------------------------------------------ 3. formulário protegido no navegador
    const armazenamento = (() => {
        try {
            const k = '__bdp_teste';
            window.localStorage.setItem(k, '1');
            window.localStorage.removeItem(k);
            return window.localStorage;
        } catch (e) { return null; }
    })();
    const chaveLocal = (nome) => 'bdp-protegido:' + nome;
    $$('[data-protegido-concluido]').forEach((el) => {
        if (armazenamento) armazenamento.removeItem(chaveLocal(el.dataset.protegidoConcluido));
    });
    $$('form[data-protegido]').forEach((form) => {
        if (!armazenamento) return;
        const nome = chaveLocal(form.dataset.protegido);
        const coletar = () => {
            const dados = {};
            new FormData(form).forEach((v, k) => {
                if (k === 'csrf' || typeof v !== 'string') return;
                (dados[k] = dados[k] || []).push(v);
            });
            return dados;
        };
        const salvar = () => {
            try { armazenamento.setItem(nome, JSON.stringify({ quando: Date.now(), dados: coletar() })); } catch (e) { /* cota cheia */ }
        };
        // Restaura (só se a página não veio de um POST com os dados já preenchidos pelo servidor).
        const salvo = (() => { try { return JSON.parse(armazenamento.getItem(nome) || 'null'); } catch (e) { return null; } })();
        const recente = salvo && Date.now() - salvo.quando < 3 * 24 * 3600 * 1000;
        if (recente && !form.querySelector('[data-servidor-preencheu]')) {
            const qtd = salvo.dados.quantidade && salvo.dados.quantidade[0];
            const campoQtd = form.querySelector('[data-quantidade-copias]');
            if (qtd && campoQtd && campoQtd.value !== qtd) {
                campoQtd.value = qtd;
                campoQtd.dispatchEvent(new Event('input', { bubbles: true }));
            }
            Object.entries(salvo.dados).forEach(([k, valores]) => {
                const campos = $$('[name="' + CSS.escape(k) + '"]', form);
                campos.forEach((c, i) => {
                    if (c.type === 'checkbox' || c.type === 'radio') {
                        c.checked = valores.includes(c.value);
                    } else if (c.type !== 'file' && valores[i] !== undefined) {
                        c.value = valores[i];
                    }
                    c.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
            const aviso = document.createElement('div');
            aviso.className = 'adm-notice adm-notice--blue adm-notice--compact';
            aviso.setAttribute('role', 'status');
            aviso.innerHTML = '<p><strong>Dados recuperados.</strong> O preenchimento anterior deste formulário foi restaurado. Se ele já tinha sido enviado, reenviar é seguro: o sistema confere e não cria registros repetidos. <button type="button" class="adm-link" data-protegido-descartar>Começar de novo</button></p>';
            form.prepend(aviso);
            aviso.querySelector('[data-protegido-descartar]').addEventListener('click', () => {
                armazenamento.removeItem(nome);
                window.location.reload();
            });
        }
        let t = null;
        form.addEventListener('input', () => { clearTimeout(t); t = setTimeout(salvar, 400); });
        form.addEventListener('change', salvar);
        form.addEventListener('submit', () => {
            salvar();
            // Evita o segundo clique (o servidor também não duplica: chave da operação).
            setTimeout(() => $$('button[type="submit"]', form).forEach((b) => { b.disabled = true; }), 0);
        });
    });

    // ------------------------------------------------------------ Central de Qualidade: unificar
    // O cadastro arquivado é sempre o outro da dupla escolhida.
    $$('input[name="mantido"][data-outro]').forEach((r) => {
        r.addEventListener('change', () => {
            const h = $('[data-unificado]');
            if (h && r.checked) h.value = r.dataset.outro;
        });
    });

    // ------------------------------------------------------------ 4. registro de vários exemplares
    $$('[data-registro-varios]').forEach((form) => {
        const qtd = $('[data-quantidade-copias]', form);
        const lista = $('[data-copias]', form);
        const modelo = lista && $('[data-copia]', lista);
        const texto = $('[data-quantidade-texto]', form);
        const rotulo = $('[data-submit-label]', form);
        const todos = $('[data-estado-todos]', form);
        const comum = $('[data-estado-comum]', form);
        const ajustar = () => {
            if (!qtd || !lista || !modelo) return;
            const n = Math.max(1, Math.min(parseInt(qtd.max || '50', 10), parseInt(qtd.value || '1', 10) || 1));
            let linhas = $$('[data-copia]', lista);
            while (linhas.length < n) {
                const nova = modelo.cloneNode(true);
                const i = linhas.length;
                $$('[name]', nova).forEach((c) => {
                    c.name = c.name.replace(/copias\[\d+\]/, 'copias[' + i + ']');
                    if (c.tagName === 'SELECT') c.selectedIndex = 0; else c.value = '';
                });
                $('[data-copia-numero]', nova).textContent = String(i + 1);
                lista.appendChild(nova);
                linhas = $$('[data-copia]', lista);
            }
            linhas.forEach((l, i) => { l.hidden = i >= n; $$('[name]', l).forEach((c) => { c.disabled = i >= n; }); });
            if (texto) texto.textContent = String(n);
            if (rotulo) rotulo.textContent = 'Registrar ' + n + ' exemplar' + (n > 1 ? 'es' : '');
            conservacao();
        };
        const conservacao = () => {
            if (!todos) return;
            if (comum) comum.hidden = !todos.checked;
            $$('select[name$="[estado_conservacao]"]', form).forEach((s) => {
                s.disabled = todos.checked;
                s.closest('label').hidden = todos.checked;
            });
        };
        qtd && qtd.addEventListener('input', ajustar);
        todos && todos.addEventListener('change', conservacao);
        ajustar();
    });
})();
