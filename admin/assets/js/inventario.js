/*
 * Acervo físico: seleção de exemplares para etiquetas, campo do leitor de código de barras,
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

    // ---------- Campo do leitor de código de barras ----------
    // O leitor USB funciona como teclado: digita o código e envia Enter. O campo fica
    // selecionado para que a próxima leitura substitua a anterior.
    document.querySelectorAll('[data-scanner]').forEach((campo) => {
        campo.addEventListener('focus', () => campo.select());
        campo.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { campo.value = ''; }
        });
    });
    const leitura = document.getElementById('leitura');
    if (leitura && !document.querySelector('[autofocus]:not(#leitura)')) {
        leitura.focus();
    }
    // Atalho: "/" ou F2 voltam ao campo de leitura na conferência.
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
