/*
 * "Meus lotes" (Conferência): clicar no cartão de um lote abre a janela ampliada com os detalhes
 * (<dialog id="lote-janela-N">). Escuta no documento porque a Conferência troca partes da página sem
 * recarregar. Fecha com Esc, com o X ou clicando fora da janela.
 */
(function () {
    'use strict';
    document.addEventListener('click', (e) => {
        const gatilho = e.target.closest('[data-abrir-lote]');
        if (gatilho) {
            const janela = document.getElementById(gatilho.getAttribute('data-abrir-lote'));
            if (!janela) return;
            e.preventDefault();
            if (typeof janela.showModal === 'function') {
                if (!janela.open) janela.showModal();
            } else {
                janela.setAttribute('open', '');
            }
            return;
        }
        // Clique no fundo escuro (fora da caixa) fecha a janela.
        const aberta = e.target.closest('dialog.adm-lote-janela');
        if (aberta && e.target === aberta) {
            aberta.close();
        }
    });
})();
