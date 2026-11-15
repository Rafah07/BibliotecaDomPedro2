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
        const botoes = Array.from(form.querySelectorAll('[data-selecao-enviar]'));
        const atualizar = () => {
            const marcados = itens().filter((i) => i.checked).length;
            if (total) total.textContent = marcados === 0 ? 'Nenhum exemplar selecionado' : (marcados === 1 ? '1 exemplar selecionado' : `${marcados} exemplares selecionados`);
            botoes.forEach((b) => { b.disabled = marcados === 0; });
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

    // ---------- Painéis laterais do Inventário (Verificar e corrigir dados / Registrar exemplar) ----------
    // Sem JavaScript, os links abrem a mesma página com &painel=... e o painel já vem aberto.
    const abrirPainel = (dialog) => {
        if (!dialog) return false;
        if (typeof dialog.showModal === 'function') {
            if (dialog.open) dialog.close();
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        const primeiro = dialog.querySelector('.adm-modal__body input:not([type=hidden]):not([readonly]), .adm-modal__body select');
        setTimeout(() => primeiro && primeiro.focus(), 40);
        return true;
    };
    document.querySelectorAll('dialog[data-painel-auto]').forEach((d) => abrirPainel(d));
    document.addEventListener('click', (e) => {
        const abrir = e.target.closest('[data-painel-abrir]');
        if (abrir && abrirPainel(document.getElementById(abrir.dataset.painelAbrir))) {
            e.preventDefault();
            return;
        }
        const fechar = e.target.closest('[data-painel-fechar]');
        if (fechar) {
            const d = fechar.closest('dialog');
            if (d) {
                e.preventDefault();
                if (typeof d.close === 'function') d.close(); else d.removeAttribute('open');
            }
        }
    });
    // Clique no fundo escuro fecha o painel.
    document.querySelectorAll('dialog.adm-painel-lateral').forEach((d) => d.addEventListener('mousedown', (e) => {
        if (e.target !== d) return;
        const r = d.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close();
    }));

    // ---------- Gerar código BDP ----------
    // O número é calculado e RESERVADO pelo servidor (api/inventario/gerar-codigo.php). Aqui só se
    // mostra o código recebido; nada é calculado no navegador. Sem JavaScript, o botão envia o
    // formulário e a página volta com o código preenchido.
    // Delegado: também funciona nos formulários trazidos depois pela conferência contínua (conferencia.js).
    // A reserva é deste formulário (campo processo_bdp): outra aba recebe outro número.
    document.addEventListener('click', async (e) => {
        const botao = e.target.closest('[data-gerar-codigo]');
        if (!botao) return;
        e.preventDefault();
        if (botao.dataset.gerando === '1') return;
        const caixa = botao.closest('[data-codigo-bdp]');
        const campo = caixa && caixa.querySelector('[data-codigo-campo]');
        const status = caixa && caixa.querySelector('[data-codigo-status]');
        const form = botao.form;
        const csrf = form && form.querySelector('input[name="csrf"]');
        const processo = form && form.querySelector('input[name="processo_bdp"]');
        if (!campo || !csrf || !processo || !window.fetch) { if (form && form.requestSubmit) form.requestSubmit(botao); return; }
        botao.dataset.gerando = '1';
        botao.disabled = true;
        if (status) { status.classList.remove('is-erro'); status.textContent = 'Reservando código no servidor…'; }
        try {
            const dados = new FormData();
            dados.append('csrf', csrf.value);
            dados.append('livro_id', botao.dataset.livro || '0');
            dados.append('processo', processo.value);
            const resp = await fetch(botao.dataset.gerarCodigo, { method: 'POST', body: dados, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const json = await resp.json().catch(() => ({}));
            if (!resp.ok || !json.ok) throw new Error(json.erro || 'Não foi possível gerar o código agora.');
            campo.value = json.codigo;
            campo.dataset.reservado = json.codigo;
            if (status) {
                status.textContent = '';
                status.append(json.nova ? 'Código reservado para este formulário (ainda não atribuído): ' : 'Este formulário já tinha este código reservado: ');
                const forte = document.createElement('strong');
                forte.textContent = json.codigo;
                status.append(forte, '. Ele fica definitivo ao clicar em Salvar exemplar (carimbe agora ou depois, por lote).');
            }
            campo.focus();
        } catch (erro) {
            if (status) { status.classList.add('is-erro'); status.textContent = erro.message; }
        } finally {
            botao.disabled = false;
            delete botao.dataset.gerando;
        }
    });

    // ---------- Localização com campos dependentes (Setor → Estante → Prateleira) ----------
    // Sem JavaScript, só a lista de prateleiras (agrupada por estante) aparece e já funciona.
    // Com JavaScript, aparecem os seletores de setor e estante e a lista de prateleiras passa a
    // mostrar só as prateleiras da estante escolhida. Só a prateleira é enviada ao servidor.
    const iniciarLocal = (caixa) => {
        if (caixa.dataset.pronto === '1') return;
        caixa.dataset.pronto = '1';
        const setor = caixa.querySelector('[data-local-setor]');
        const estante = caixa.querySelector('[data-local-estante]');
        const prat = caixa.querySelector('[data-local-prateleira]');
        if (!estante || !prat) return;
        caixa.querySelectorAll('.adm-local-dep__aux').forEach((l) => { l.hidden = false; });
        const vazio = prat.querySelector('option[value=""]');
        const grupos = Array.from(prat.querySelectorAll('optgroup')).map((g) => g.cloneNode(true));
        const estantes = Array.from(estante.querySelectorAll('option')).map((o) => o.cloneNode(true));
        const montarEstantes = () => {
            const s = setor ? setor.value : '';
            const atual = estante.value;
            estante.replaceChildren(...estantes
                .filter((o) => o.value === '' || s === '' || o.dataset.setor === s)
                .map((o) => o.cloneNode(true)));
            estante.value = Array.from(estante.options).some((o) => o.value === atual) ? atual : '';
        };
        const montarPrateleiras = () => {
            const e = estante.value;
            const atual = prat.value;
            const s = setor ? setor.value : '';
            const validas = new Set(Array.from(estante.options).map((o) => o.value));
            const lista = grupos.filter((g) => (e !== '' ? g.dataset.estante === e : (s === '' || validas.has(g.dataset.estante))));
            const filhos = [vazio ? vazio.cloneNode(true) : new Option('', '')];
            if (e !== '' && lista.length === 1) {
                lista[0].querySelectorAll('option').forEach((o) => filhos.push(o.cloneNode(true)));
            } else {
                lista.forEach((g) => filhos.push(g.cloneNode(true)));
            }
            prat.replaceChildren(...filhos);
            prat.value = Array.from(prat.options).some((o) => o.value === atual) ? atual : '';
        };
        if (setor) setor.addEventListener('change', () => { montarEstantes(); montarPrateleiras(); });
        estante.addEventListener('change', () => { montarPrateleiras(); if (estante.value !== '') prat.focus(); });
        montarEstantes();
        montarPrateleiras();
    };
    // "Limpar seleção" da localização sugerida: volta tudo para "Aguardando organização".
    document.addEventListener('click', (e) => {
        const botao = e.target.closest('[data-local-limpar]');
        if (!botao) return;
        const area = botao.closest('form') || document;
        const caixa = area.querySelector('[data-local-dep]');
        if (!caixa) return;
        ['[data-local-setor]', '[data-local-estante]', '[data-local-prateleira]'].forEach((sel) => {
            const s = caixa.querySelector(sel);
            if (s) { s.value = ''; s.dispatchEvent(new Event('change', { bubbles: true })); }
        });
        const aviso = botao.closest('[data-local-sugestao]');
        if (aviso) aviso.hidden = true;
        const prat = caixa.querySelector('[data-local-prateleira]');
        if (prat) prat.focus();
    });
    // Trocar a prateleira à mão esconde o texto "Localização sugerida".
    document.addEventListener('change', (e) => {
        if (!e.isTrusted || !e.target.matches || !e.target.matches('[data-local-prateleira]')) return;
        const area = e.target.closest('form');
        const aviso = area && area.querySelector('[data-local-sugestao]');
        if (aviso) aviso.hidden = true;
    });
    const iniciarLocais = () => document.querySelectorAll('[data-local-dep]').forEach(iniciarLocal);
    iniciarLocais();
    document.addEventListener('conferencia:atualizada', iniciarLocais);

    // ---------- Proteção contra clique duplo ----------
    // Formulários com data-enviar-uma-vez ficam bloqueados depois do primeiro envio
    // (o servidor também confere tudo dentro de uma transação).
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-enviar-uma-vez') || e.defaultPrevented) return;
        if (form.dataset.enviando === '1') { e.preventDefault(); return; }
        form.dataset.enviando = '1';
        setTimeout(() => form.querySelectorAll('button[type=submit], button:not([type])').forEach((b) => { b.disabled = true; }), 0);
    });
    window.addEventListener('pageshow', () => document.querySelectorAll('form[data-enviando]').forEach((f) => {
        delete f.dataset.enviando;
        f.querySelectorAll('button').forEach((b) => { b.disabled = false; });
    }));

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
