/*
 * "+ Adicionar obra" na conferência do Inventário.
 *  - O botão ao lado da pesquisa leva o texto já digitado para o campo Título.
 *  - Enquanto a pessoa preenche título, autor, ISBN, editora ou edição, mostra as obras parecidas
 *    que já existem (api/inventario/correspondencias.php), com "Utilizar obra existente" e
 *    "Continuar cadastrando uma nova obra". Nada é unido nem bloqueado: edições diferentes podem
 *    ter o mesmo título. Ao salvar, o servidor confere de novo.
 *  - Funciona com as regiões trocadas pelo conferencia.js (eventos delegados no documento).
 *    Sem JavaScript, o formulário continua funcionando (o servidor mostra as parecidas ao salvar).
 */
(() => {
    'use strict';
    if (!window.fetch) return;

    const CAMPOS = ['titulo', 'autores', 'isbn', 'editora', 'edicao'];
    const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let espera = null;
    let controle = null;

    // Botão "+ Adicionar obra" ao lado da pesquisa: leva o que foi digitado para o Título.
    document.addEventListener('click', (e) => {
        const link = e.target.closest && e.target.closest('[data-nova-obra-link]');
        if (!link) return;
        const campo = document.getElementById('leitura');
        const texto = campo ? campo.value.trim() : '';
        const u = new URL(link.getAttribute('href'), window.location.href);
        if (texto && !/^bdp/i.test(texto)) u.searchParams.set('titulo', texto); else u.searchParams.delete('titulo');
        link.setAttribute('href', u.pathname + u.search + u.hash);
    }, true);

    const caixa = (form) => form.querySelector('[data-correspondencias-vivas]');
    const escolha = (form) => form.querySelector('[data-escolha-viva]');

    const mostrar = (form, obras) => {
        const box = caixa(form);
        if (!box) return;
        // O servidor já mostrou as parecidas (com a caixa "Continuar cadastrando"): não repete a lista.
        if (form.querySelector('input[name="escolha"][type="checkbox"]')) { box.hidden = true; return; }
        if (!obras.length || form.dataset.continuarNova === '1') { box.hidden = true; box.innerHTML = ''; return; }
        const base = form.dataset.base || '';
        box.innerHTML = `<p><strong>Já existe(m) ${obras.length} obra(s) parecida(s) no sistema.</strong> Confira antes de continuar. Nada é unido automaticamente: edições diferentes podem ter o mesmo título.</p>
            <ul class="adm-resultados">${obras.map((o) => `<li><span><strong>${esc(o.titulo)}</strong> · ${esc(o.autores || 'Autor não informado')}${o.isbn ? ` · ISBN ${esc(o.isbn)}` : ''}${o.editora ? ` · ${esc(o.editora)}` : ''}${o.ano ? ` · ${esc(o.ano)}` : ''}${o.edicao ? ` · ${esc(o.edicao)}` : ''}
                <small class="adm-block-muted">${esc(o.motivo)}${o.detalhes && o.detalhes.length ? ` · ${esc(o.detalhes.join(', '))}` : ''} · ${esc(o.exemplares)} exemplar(es)</small></span>
                <a class="adm-button adm-button--ghost adm-button--sm" href="${esc(`${base}&obra=${o.id}#obra`)}">Utilizar obra existente</a></li>`).join('')}</ul>
            <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-continuar-nova>Continuar cadastrando uma nova obra</button>`;
        box.hidden = false;
        const esc2 = escolha(form);
        if (esc2) esc2.value = '';
    };

    const conferir = (form) => {
        const params = new URLSearchParams();
        CAMPOS.forEach((c) => { const el = form.elements[c]; if (el && el.value.trim()) params.set(c, el.value.trim()); });
        const titulo = params.get('titulo') || '';
        if (!params.get('isbn') && titulo.length < 3) { mostrar(form, []); return; }
        if (controle) controle.abort();
        controle = new AbortController();
        fetch(`${form.dataset.novaObra}&${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controle.signal })
            .then((r) => r.json())
            .then((j) => { if (j && j.ok) mostrar(form, j.obras || []); })
            .catch(() => {});
    };

    document.addEventListener('input', (e) => {
        const form = e.target.closest && e.target.closest('form[data-nova-obra]');
        if (!form || !CAMPOS.includes(e.target.name)) return;
        form.dataset.continuarNova = '';
        clearTimeout(espera);
        espera = setTimeout(() => conferir(form), 400);
    });

    document.addEventListener('click', (e) => {
        const botao = e.target.closest && e.target.closest('[data-continuar-nova]');
        if (!botao) return;
        const form = botao.closest('form[data-nova-obra]');
        form.dataset.continuarNova = '1';
        const esc2 = escolha(form);
        if (esc2) esc2.value = 'novo'; // a pessoa já viu as parecidas e decidiu: o servidor não pergunta de novo
        const box = caixa(form);
        box.innerHTML = '<p class="adm-field__hint">Ok: será cadastrada uma obra nova. As parecidas continuam no sistema, sem mudança.</p>';
    });

    // Formulário aberto já com título (vindo da pesquisa): confere logo.
    const iniciar = () => document.querySelectorAll('form[data-nova-obra]').forEach((f) => {
        if (!f.dataset.iniciado) { f.dataset.iniciado = '1'; conferir(f); }
    });
    document.addEventListener('conferencia:atualizada', iniciar);
    iniciar();
})();
