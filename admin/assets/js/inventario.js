/*
 * Acervo físico: seleção de exemplares para etiquetas, campo de digitação do código BDP,
 * confirmação de extravio e zoom da pré-visualização das etiquetas.
 * Tudo funciona sem JavaScript; aqui só ficam atalhos de uso.
 */
(() => {
    'use strict';

    // ---------- Seleção de exemplares (lista de exemplares) ----------
    document.querySelectorAll('form[data-selecao]').forEach((form) => {
        const itens = () => Array.from(form.querySelectorAll('[data-selecao-item]'));
        const todos = form.querySelector('[data-selecao-todos]');
        const total = form.querySelector('[data-selecao-total]');
        const botao = form.querySelector('[data-selecao-enviar]');
        const atualizar = () => {
            const marcados = itens().filter((i) => i.checked).length;
            if (total) total.textContent = marcados === 0 ? 'Nenhum exemplar selecionado' : (marcados === 1 ? '1 exemplar selecionado' : `${marcados} exemplares selecionados`);
            if (botao) botao.disabled = marcados === 0;
            if (todos) {
                todos.checked = marcados > 0 && marcados === itens().length;
                todos.indeterminate = marcados > 0 && marcados < itens().length;
            }
        };
        if (todos) todos.addEventListener('change', () => { itens().forEach((i) => { i.checked = todos.checked; }); atualizar(); });
        form.addEventListener('change', (e) => { if (e.target.matches('[data-selecao-item]')) atualizar(); });
        atualizar();
    });

    // ---------- Campo de digitação do código BDP ----------
    // A bibliotecária digita o código escrito na etiqueta (ex.: BDP-000001) e pressiona Enter.
    // O campo fica selecionado para que o próximo código substitua o anterior; Esc limpa.
    // Os espaços nas pontas são retirados antes de enviar (o servidor também confere tudo).
    document.querySelectorAll('[data-campo-codigo]').forEach((campo) => {
        campo.addEventListener('focus', () => campo.select());
        campo.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { campo.value = ''; }
        });
        if (campo.form) {
            campo.form.addEventListener('submit', () => { campo.value = campo.value.trim(); });
        }
    });
    // ---------- Sugestões enquanto digita (conferência do inventário) ----------
    // Mostra códigos BDP e livros parecidos (título, autor, ISBN, editora). Nada é escolhido
    // sozinho: Enter sem sugestão marcada faz a pesquisa normal, pelo servidor.
    document.querySelectorAll('[data-sugestoes]').forEach((campo) => {
        const lista = document.getElementById(campo.getAttribute('aria-controls'));
        const base = campo.dataset.sugestoesBase || '';
        if (!lista || !window.fetch) return;
        let itens = [];
        let ativo = -1;
        let espera = null;
        let controle = null;
        let ultimo = '';

        const destino = (s) => s.tipo === 'exemplar'
            ? `${base}&codigo=${encodeURIComponent(s.codigo)}`
            : `${base}&q=${encodeURIComponent(campo.value.trim())}#livro-${s.livro_id}`;
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
                const t = document.createElement('strong'); t.textContent = s.titulo;
                const d = document.createElement('small'); d.textContent = s.detalhe;
                texto.append(t, d);
                li.append(tipo, texto);
                li.addEventListener('mousedown', (e) => { e.preventDefault(); window.location.href = destino(s); });
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
            else if (e.key === 'Enter' && ativo >= 0) { e.preventDefault(); window.location.href = destino(itens[ativo]); }
            else if (e.key === 'Escape') { e.stopImmediatePropagation(); e.preventDefault(); fechar(); ultimo = ''; }
        });
        campo.addEventListener('blur', () => setTimeout(fechar, 150));
        campo.addEventListener('focus', () => { ultimo = ''; if (campo.value.trim().length >= 2) buscar(); });
        if (campo.form) campo.form.addEventListener('submit', fechar);
    });

    const leitura = document.getElementById('leitura');
    if (leitura && !document.querySelector('[autofocus]:not(#leitura)')) {
        leitura.focus();
    }
    // Atalho: "/" ou F2 voltam ao campo do código na conferência.
    if (leitura) {
        document.addEventListener('keydown', (e) => {
            const alvo = e.target;
            const digitando = alvo instanceof HTMLElement && (alvo.matches('input, textarea, select') || alvo.isContentEditable);
            if (e.key === 'F2' || (e.key === '/' && !digitando)) {
                e.preventDefault();
                leitura.focus();
            }
        });
    }

    // ---------- Confirmação ao registrar extravio ----------
    document.querySelectorAll('form.adm-decisao').forEach((form) => form.addEventListener('submit', (e) => {
        const decisao = form.querySelector('select[name="decisao"]');
        if (decisao && decisao.value === 'registrar_extravio'
            && !window.confirm('Registrar este exemplar como extraviado? Ele deixará de aparecer para empréstimo.')) {
            e.preventDefault();
        }
    }));

    // ---------- Zoom da pré-visualização das etiquetas ----------
    const zoom = document.querySelector('[data-previa-zoom]');
    const previa = document.querySelector('[data-previa]');
    if (zoom && previa) {
        zoom.addEventListener('change', () => {
            const escala = parseFloat(zoom.value) || 1;
            previa.querySelectorAll('.adm-previa__folha').forEach((folha) => {
                folha.style.transform = escala === 1 ? '' : `scale(${escala})`;
                folha.style.marginBottom = escala === 1 ? '' : `calc(${folha.style.height} * ${escala - 1})`;
                folha.style.marginRight = escala === 1 ? '' : `calc(${folha.style.width} * ${escala - 1})`;
            });
        });
    }
})();
