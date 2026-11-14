/*
 * Sugestões enquanto digita (autocompletar) nos campos de pesquisa do painel.
 * Ex.: digitar "sher" já sugere "Sherlock Holmes", com os dados do acervo (título, autor,
 * ISBN, editora) e os códigos BDP dos exemplares. Nada é escolhido sozinho: Enter sem
 * sugestão marcada faz a pesquisa normal. Sem JavaScript, os campos continuam funcionando.
 */
(() => {
    'use strict';

    document.querySelectorAll('[data-sugestoes]').forEach((campo) => {
        const lista = document.getElementById(campo.getAttribute('aria-controls'));
        const base = campo.dataset.sugestoesBase || '';
        if (!lista || !window.fetch) return;
        let itens = [];
        let ativo = -1;
        let espera = null;
        let controle = null;
        let ultimo = '';

        const preencher = campo.dataset.sugestoesModo === 'preencher';
        const destino = (s) => s.tipo === 'exemplar'
            ? `${base}&codigo=${encodeURIComponent(s.codigo)}`
            : `${base}&q=${encodeURIComponent(campo.value.trim())}#livro-${s.livro_id}`;
        // data-sugestoes-modo:
        //   (padrão)   conferência do inventário: abre o exemplar ou o livro na própria conferência;
        //   preencher  completa o campo com o título (ou código) e pesquisa;
        //   abrir      pesquisa do topo: abre a página do livro (também ao escolher um código BDP).
        const abrir = campo.dataset.sugestoesModo === 'abrir';
        const escolher = (s) => {
            if (abrir && campo.dataset.sugestoesLivro) {
                window.location.href = campo.dataset.sugestoesLivro + encodeURIComponent(s.livro_id);
                return;
            }
            if ((preencher || abrir) && campo.form) {
                campo.value = s.tipo === 'exemplar' ? s.codigo : s.titulo;
                fechar();
                campo.form.submit();
                return;
            }
            window.location.href = destino(s);
        };
        // Destaca no título o trecho digitado (sem diferenciar maiúsculas e acentos).
        const semAcento = (c) => c.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const destacar = (el, texto, busca) => {
            const base1 = Array.from(texto).map(semAcento).join('');
            const alvo = semAcento(busca.trim());
            const pos = alvo && Array.from(texto).every((c) => semAcento(c).length === 1) ? base1.indexOf(alvo) : -1;
            if (pos < 0) { el.textContent = texto; return; }
            const letras = Array.from(texto);
            const marca = document.createElement('mark');
            marca.textContent = letras.slice(pos, pos + alvo.length).join('');
            el.append(letras.slice(0, pos).join(''), marca, letras.slice(pos + alvo.length).join(''));
        };
        const fechar = () => {
            lista.hidden = true; lista.innerHTML = ''; itens = []; ativo = -1;
            campo.setAttribute('aria-expanded', 'false'); campo.removeAttribute('aria-activedescendant');
        };
        const marcar = (i) => {
            ativo = i;
            lista.querySelectorAll('[role="option"]').forEach((li, k) => li.setAttribute('aria-selected', k === i ? 'true' : 'false'));
            const atual = lista.querySelector(`[data-indice="${i}"]`);
            if (atual) { campo.setAttribute('aria-activedescendant', atual.id); atual.scrollIntoView({ block: 'nearest' }); }
            else campo.removeAttribute('aria-activedescendant');
        };
        const mostrar = (dados) => {
            lista.innerHTML = '';
            itens = dados;
            ativo = -1;
            if (!dados.length) {
                const li = document.createElement('li');
                li.className = 'adm-sugestoes__vazio';
                li.textContent = 'Nenhuma sugestão. Pressione Enter para pesquisar ou cadastre o livro não listado.';
                lista.appendChild(li);
            }
            dados.forEach((s, i) => {
                const li = document.createElement('li');
                li.className = 'adm-sugestoes__item';
                li.id = `leitura-sugestao-${i}`;
                li.dataset.indice = String(i);
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                const tipo = document.createElement('span');
                tipo.className = 'adm-sugestoes__tipo' + (s.conferido ? ' adm-sugestoes__tipo--ok' : '');
                tipo.textContent = s.tipo === 'exemplar' ? s.codigo : 'Livro';
                const texto = document.createElement('span');
                texto.className = 'adm-sugestoes__texto';
                const t = document.createElement('strong'); destacar(t, s.titulo, campo.value);
                const d = document.createElement('small'); d.textContent = s.detalhe;
                texto.append(t, d);
                li.append(tipo, texto);
                li.addEventListener('mousedown', (e) => { e.preventDefault(); escolher(s); });
                lista.appendChild(li);
            });
            lista.hidden = false;
            campo.setAttribute('aria-expanded', 'true');
        };
        const buscar = () => {
            const q = campo.value.trim();
            if (q === ultimo) return;
            ultimo = q;
            if (q.length < 2) { fechar(); return; }
            if (controle) controle.abort();
            controle = window.AbortController ? new AbortController() : null;
            fetch(`${campo.dataset.sugestoes}&q=${encodeURIComponent(q)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controle ? controle.signal : undefined })
                .then((r) => r.json())
                .then((json) => { if (campo.value.trim() === q && document.activeElement === campo) { if (json.ok) mostrar(json.sugestoes || []); else fechar(); } })
                .catch(() => {});
        };

        campo.addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(buscar, 180); });
        campo.addEventListener('keydown', (e) => {
            if (lista.hidden) return;
            if (e.key === 'ArrowDown' && itens.length) { e.preventDefault(); marcar((ativo + 1) % itens.length); }
            else if (e.key === 'ArrowUp' && itens.length) { e.preventDefault(); marcar(ativo <= 0 ? itens.length - 1 : ativo - 1); }
            else if (e.key === 'Enter' && ativo >= 0) { e.preventDefault(); escolher(itens[ativo]); }
            else if (e.key === 'Escape') { e.stopImmediatePropagation(); e.preventDefault(); fechar(); ultimo = ''; }
        });
        campo.addEventListener('blur', () => setTimeout(fechar, 150));
        campo.addEventListener('focus', () => { ultimo = ''; if (campo.value.trim().length >= 2) buscar(); });
        if (campo.form) campo.form.addEventListener('submit', fechar);
    });
})();
