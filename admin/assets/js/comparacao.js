/* Comparar com planilha original: impressão (a página funciona sem JavaScript). */
(function () {
    'use strict';
    document.querySelectorAll('[data-imprimir]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });
})();
