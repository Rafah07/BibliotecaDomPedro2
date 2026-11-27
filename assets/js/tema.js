/*
 * Modo escuro do site público, do painel e das páginas das equipes.
 * Carregado no <head> (sem defer) para aplicar o tema antes da pintura e evitar o "clarão".
 *
 * - Na primeira visita segue o tema do aparelho (claro ou escuro) e acompanha se ele mudar.
 * - Quando a pessoa usa o botão [data-tema-alternar], a escolha fica guardada neste navegador
 *   (localStorage 'bdp-tema') e passa a valer no site e no painel.
 * - A impressão é sempre clara: as regras escuras ficam dentro de @media screen nos CSS.
 */
(function () {
    'use strict';
    var CHAVE = 'bdp-tema';
    var raiz = document.documentElement;
    var sistema = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var escolhaDaPagina = null; // usada quando o navegador bloqueia o armazenamento

    function guardado() {
        try {
            var v = localStorage.getItem(CHAVE);
            return v === 'claro' || v === 'escuro' ? v : escolhaDaPagina;
        } catch (e) {
            return escolhaDaPagina;
        }
    }

    function atual() {
        return guardado() || (sistema && sistema.matches ? 'escuro' : 'claro');
    }

    function atualizarBotoes(tema) {
        var escuro = tema === 'escuro';
        var botoes = document.querySelectorAll('[data-tema-alternar]');
        for (var i = 0; i < botoes.length; i++) {
            var b = botoes[i];
            b.setAttribute('aria-pressed', String(escuro));
            var rotulo = escuro ? 'Usar modo claro' : 'Usar modo escuro';
            b.setAttribute('aria-label', rotulo);
            b.setAttribute('title', rotulo);
            var texto = b.querySelector('[data-tema-texto]');
            if (texto) texto.textContent = escuro ? 'Modo claro' : 'Modo escuro';
            if (b.hasAttribute('data-tooltip')) b.setAttribute('data-tooltip', escuro ? 'Modo claro' : 'Modo escuro');
            if (b.tagName === 'A') b.removeAttribute('title'); // o menu já mostra a dica própria
        }
    }

    function aplicar(tema) {
        raiz.setAttribute('data-tema', tema);
        atualizarBotoes(tema);
    }

    aplicar(atual());

    // Segue o aparelho enquanto a pessoa não escolheu um tema.
    if (sistema) {
        var aoMudar = function () { if (!guardado()) aplicar(atual()); };
        if (sistema.addEventListener) sistema.addEventListener('change', aoMudar);
        else if (sistema.addListener) sistema.addListener(aoMudar);
    }

    // Outra aba mudou o tema: acompanha.
    window.addEventListener('storage', function (ev) {
        if (ev.key === CHAVE) aplicar(atual());
    });

    document.addEventListener('click', function (ev) {
        var alvo = ev.target && ev.target.closest ? ev.target.closest('[data-tema-alternar]') : null;
        if (!alvo) return;
        ev.preventDefault();
        var novo = atual() === 'escuro' ? 'claro' : 'escuro';
        escolhaDaPagina = novo;
        try { localStorage.setItem(CHAVE, novo); } catch (e) { /* armazenamento bloqueado: vale só nesta página */ }
        raiz.classList.add('tema-trocando');
        aplicar(novo);
        window.setTimeout(function () { raiz.classList.remove('tema-trocando'); }, 60);
    });

    // Links com role="button" (menu do site) também respondem à barra de espaço.
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== ' ' || !ev.target || !ev.target.matches || !ev.target.matches('a[data-tema-alternar]')) return;
        ev.preventDefault();
        ev.target.click();
    });

    document.addEventListener('DOMContentLoaded', function () { atualizarBotoes(atual()); });
})();
