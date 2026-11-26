/*
 * Cadastro rápido de administradores (Administradores e Meu perfil). Sem dependências.
 *
 * [data-gerar-senha] preenche [data-senha-campo] com uma senha aleatória de 12 caracteres que
 * atende às regras do servidor (letras e números, sem caracteres parecidos como 0/O e 1/l/I) e
 * seleciona o texto para facilitar copiar. A senha sempre é conferida de novo pelo servidor.
 */
(function () {
    'use strict';
    const letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    const numeros = '23456789';

    function sortear(conjunto, n) {
        const valores = new Uint32Array(n);
        window.crypto.getRandomValues(valores);
        return Array.from(valores, (v) => conjunto[v % conjunto.length]).join('');
    }

    function gerar() {
        // Garante letras e números; o restante é misturado.
        const partes = (sortear(letras, 7) + sortear(numeros, 3) + sortear(letras + numeros, 2)).split('');
        const ordem = new Uint32Array(partes.length);
        window.crypto.getRandomValues(ordem);
        for (let i = partes.length - 1; i > 0; i--) {
            const j = ordem[i] % (i + 1);
            [partes[i], partes[j]] = [partes[j], partes[i]];
        }
        return partes.join('');
    }

    document.querySelectorAll('[data-cadastro-rapido]').forEach((form) => {
        const campo = form.querySelector('[data-senha-campo]');
        const botao = form.querySelector('[data-gerar-senha]');
        if (!campo || !botao || !window.crypto || !window.crypto.getRandomValues) {
            if (botao) botao.hidden = true;
            return;
        }
        botao.addEventListener('click', () => {
            campo.value = gerar();
            campo.focus();
            campo.select();
        });
    });
})();
