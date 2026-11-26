/*
 * Equipes por links (migração 210): página pública de coleta/carimbagem e seção "Equipes" do lote.
 * Sem dependências. Tudo é conferido de novo no servidor; aqui é só conveniência.
 *
 *   [data-copiar]              copia o texto de [data-copiar-campo] (link recém-criado)
 *   [data-confirmar="texto"]   pede confirmação antes de enviar o formulário do botão
 *   [data-mostra-se="campo=valor"]  mostra o bloco só quando o rádio/caixa "campo" tem o valor
 *   [data-desliga="id"]        caixa "Sem autor/editora/data": desliga e esvazia o campo indicado
 *   [data-isbn]                confere o dígito verificador do ISBN enquanto se digita
 *   [data-marcar-todos]        marca/desmarca todas as caixas [data-marcavel] da tabela
 *   [data-imprimir]            abre a impressão da página
 */
(function () {
    'use strict';
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));

    $$('[data-copiar]').forEach((b) => b.addEventListener('click', () => {
        const campo = document.querySelector('[data-copiar-campo]');
        if (!campo) return;
        campo.select();
        const ok = () => { b.textContent = 'Copiado!'; };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(campo.value).then(ok, () => { document.execCommand('copy'); ok(); });
        } else {
            document.execCommand('copy');
            ok();
        }
    }));

    $$('[data-confirmar]').forEach((b) => b.addEventListener('click', (e) => {
        if (!window.confirm(b.getAttribute('data-confirmar'))) e.preventDefault();
    }));

    // Blocos que dependem de uma resposta (ex.: "Tem ISBN?" → campo do ISBN).
    const condicionais = $$('[data-mostra-se]');
    const atualizar = () => condicionais.forEach((bloco) => {
        const [campo, valor] = bloco.getAttribute('data-mostra-se').split('=');
        const marcado = document.querySelector('[name="' + campo + '"]:checked');
        const mostrar = !!marcado && marcado.value === valor;
        bloco.hidden = !mostrar;
        $$('input, select, textarea', bloco).forEach((i) => { i.disabled = !mostrar; });
    });
    if (condicionais.length) {
        document.addEventListener('change', atualizar);
        atualizar();
    }

    $$('[data-desliga]').forEach((caixa) => {
        const alvo = document.getElementById(caixa.getAttribute('data-desliga'));
        if (!alvo) return;
        const aplicar = () => {
            alvo.disabled = caixa.checked;
            alvo.required = !caixa.checked;
            if (caixa.checked) alvo.value = '';
        };
        caixa.addEventListener('change', aplicar);
        aplicar();
    });

    // ISBN: 10 ou 13 dígitos com o dígito verificador certo.
    const isbnValido = (bruto) => {
        const s = bruto.replace(/[\s.\-]/g, '').toUpperCase();
        if (/^\d{9}[\dX]$/.test(s)) {
            let soma = 0;
            for (let i = 0; i < 10; i++) soma += (s[i] === 'X' ? 10 : +s[i]) * (10 - i);
            return soma % 11 === 0;
        }
        if (/^\d{13}$/.test(s)) {
            let soma = 0;
            for (let i = 0; i < 13; i++) soma += +s[i] * (i % 2 ? 3 : 1);
            return soma % 10 === 0;
        }
        return null;
    };
    $$('[data-isbn]').forEach((campo) => {
        const aviso = document.getElementById(campo.getAttribute('data-isbn'));
        const conferir = () => {
            if (!aviso) return;
            const v = campo.value.trim();
            const r = v === '' ? null : isbnValido(v);
            aviso.textContent = v === '' ? '' : (r === true ? 'ISBN confere.' : (r === false ? 'O último dígito não confere: leia o ISBN de novo no livro.' : 'O ISBN tem 10 ou 13 dígitos.'));
            aviso.className = 'adm-field__hint ' + (r === true ? 'coleta-ok' : (v === '' ? '' : 'coleta-erro'));
        };
        campo.addEventListener('input', conferir);
        conferir();
    });

    $$('[data-marcar-todos]').forEach((mestre) => mestre.addEventListener('change', () => {
        $$('[data-marcavel]').forEach((c) => { if (!c.disabled) c.checked = mestre.checked; });
    }));

    $$('[data-imprimir]').forEach((b) => b.addEventListener('click', () => window.print()));
})();
