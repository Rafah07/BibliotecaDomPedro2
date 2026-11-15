/* Lotes (migração 160): selecionar todos e imprimir o relatório. */
(function () {
    'use strict';
    document.addEventListener('change', (e) => {
        const todos = e.target.closest('[data-marcar-todos]');
        if (!todos) return;
        const form = todos.closest('form');
        if (!form) return;
        form.querySelectorAll('input[type="checkbox"][name="' + todos.dataset.marcarTodos + '"]').forEach((c) => { c.checked = todos.checked; });
    });
    document.querySelectorAll('[data-imprimir]').forEach((b) => b.addEventListener('click', () => window.print()));
})();
