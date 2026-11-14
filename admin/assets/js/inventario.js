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
                status.append(forte, '. Escreva-o no carimbo do livro e depois clique em Salvar exemplar.');
            }
            campo.focus();
        } catch (erro) {
            if (status) { status.classList.add('is-erro'); status.textContent = erro.message; }
        } finally {
            botao.disabled = false;
            delete botao.dataset.gerando;
        }
    });

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
