/**
 * Central de Ajuda (Etapa 10). Melhorias opcionais: a página funciona sem JavaScript.
 *  - "Imprimir tutorial": abre todas as seções expansíveis antes de imprimir.
 *  - Abre automaticamente o "problema frequente" indicado no endereço (#problemas).
 */
(function () {
    'use strict';

    const problemas = () => Array.from(document.querySelectorAll('.ajd-problema'));
    let fechados = [];

    function abrirTudo() {
        fechados = problemas().filter((d) => !d.open);
        fechados.forEach((d) => { d.open = true; });
    }
    function restaurar() {
        fechados.forEach((d) => { d.open = false; });
        fechados = [];
    }

    // Imagens com carregamento adiado precisam estar carregadas antes de imprimir.
    window.addEventListener('beforeprint', () => document.querySelectorAll('img[loading="lazy"]').forEach((img) => { img.loading = 'eager'; }));
    window.addEventListener('beforeprint', abrirTudo);
    window.addEventListener('afterprint', restaurar);

    document.querySelectorAll('[data-ajuda-imprimir]').forEach((botao) => {
        botao.addEventListener('click', () => window.print());
    });

    if (location.hash === '#problemas') {
        const primeiro = problemas()[0];
        if (primeiro) primeiro.open = true;
    }
})();
