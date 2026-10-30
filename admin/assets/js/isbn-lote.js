/* Tratamento de ISBNs inválidos em lote (isbn-invalidos.php).
   Seleção dos registros elegíveis, contador e botão "Usar no campo de correção".
   A confirmação da importação é feita pelo servidor, que mostra a lista de livros afetados. */
(function () {
    'use strict';

    const area = document.querySelector('[data-isbn-lote]');
    if (area) {
        const todos = area.querySelector('[data-selecionar-todos]');
        const contador = area.querySelector('[data-contador]');
        const botao = area.querySelector('[data-importar-selecionados]');
        const caixas = Array.from(area.querySelectorAll('input[data-elegivel]'));

        const atualizar = () => {
            const marcadas = caixas.filter((c) => c.checked).length;
            if (contador) contador.textContent = marcadas === 1 ? '1 selecionado' : marcadas + ' selecionados';
            if (botao) botao.disabled = marcadas === 0;
            if (todos) {
                todos.checked = marcadas > 0 && marcadas === caixas.length;
                todos.indeterminate = marcadas > 0 && marcadas < caixas.length;
            }
            caixas.forEach((c) => c.closest('tr').classList.toggle('is-selected', c.checked));
        };
        caixas.forEach((c) => c.addEventListener('change', atualizar));
        if (todos) {
            todos.addEventListener('change', () => {
                caixas.forEach((c) => { c.checked = todos.checked; });
                atualizar();
            });
        }
        atualizar(); // o navegador pode restaurar caixas marcadas ao voltar à página

        area.addEventListener('click', (e) => {
            const usar = e.target.closest('[data-usar-sugestao]');
            if (!usar) return;
            const campo = usar.closest('tr').querySelector('[data-campo-isbn]');
            if (campo) {
                campo.value = usar.dataset.usarSugestao;
                campo.focus();
            }
        });
    }

    // Evita o envio duplo dos formulários (ex.: duplo clique em "Confirmar").
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (form.dataset.enviando) {
                e.preventDefault();
                return;
            }
            form.dataset.enviando = '1';
            const botoes = Array.from(form.querySelectorAll('[data-bloquear-envio]'));
            if (form.id) botoes.push(...document.querySelectorAll('[form="' + form.id + '"][type="submit"]'));
            setTimeout(() => botoes.forEach((b) => { b.disabled = true; }), 0);
        });
    });
})();
