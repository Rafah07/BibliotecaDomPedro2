/* Mapa das estantes: arrastar blocos (mouse, toque ou caneta) e mover/redimensionar com o teclado.
   As posições ficam nos campos numéricos do formulário ("Ajustar as posições por números"), que é o
   que o servidor recebe ao clicar em "Salvar mapa". Sem JavaScript, os campos numéricos funcionam sozinhos. */
(() => {
    'use strict';
    // ---------- Celular: Lista ou Mapa (com zoom) ----------
    const vista = document.querySelector('[data-mapa-vista]');
    const area = document.querySelector('[data-mapa-area]');
    const mapaVisivel = area && area.querySelector('[data-mapa]');
    if (vista && area && mapaVisivel) {
        const zoomBox = vista.querySelector('[data-mapa-zoom]');
        const valor = vista.querySelector('[data-zoom-valor]');
        let zoom = 1;
        const larguraMapa = () => (parseInt(mapaVisivel.dataset.colunas, 10) || 24) * 32;
        const ajuste = () => Math.max(0.3, Math.min(1, (area.clientWidth - 2) / larguraMapa()));
        const aplicarZoom = (z) => {
            zoom = Math.max(0.3, Math.min(1.6, Math.round(z * 100) / 100));
            mapaVisivel.style.setProperty('--mapa-zoom', String(zoom));
            if (valor) valor.textContent = Math.round(zoom * 100) + '%';
        };
        const escolher = (modo) => {
            const ehMapa = modo === 'mapa';
            area.classList.toggle('is-mapa', ehMapa);
            vista.querySelectorAll('[data-vista]').forEach((b) => {
                const ativo = b.dataset.vista === modo;
                b.classList.toggle('is-active', ativo);
                b.setAttribute('aria-pressed', String(ativo));
            });
            if (zoomBox) zoomBox.hidden = !ehMapa;
            if (ehMapa) aplicarZoom(ajuste()); // começa mostrando a sala inteira na largura da tela
            try { sessionStorage.setItem('adm-mapa-vista', modo); } catch (e) { /* sem armazenamento */ }
        };
        vista.querySelectorAll('[data-vista]').forEach((b) => b.addEventListener('click', () => escolher(b.dataset.vista)));
        vista.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => aplicarZoom(zoom + 0.2 * Number(b.dataset.zoom))));
        try { if (sessionStorage.getItem('adm-mapa-vista') === 'mapa') escolher('mapa'); } catch (e) { /* sem armazenamento */ }
    }

    const mapa = document.querySelector('[data-mapa][data-mapa-editavel]');
    const form = document.querySelector('[data-mapa-form]');
    if (form) {
        form.querySelectorAll('button[data-confirm]').forEach((b) => b.addEventListener('click', (e) => {
            if (!window.confirm(b.dataset.confirm)) e.preventDefault();
        }));
    }
    if (!mapa || !form) return;

    const colunas = parseInt(mapa.dataset.colunas, 10) || 24;
    const linhaPx = parseInt(mapa.dataset.linha, 10) || 46;
    const status = form.querySelector('[data-mapa-status]');
    let alterado = false;

    const campos = (id) => {
        const tr = form.querySelector(`[data-posicao="${id}"]`);
        const c = {};
        if (tr) tr.querySelectorAll('[data-campo]').forEach((i) => { c[i.dataset.campo] = i; });
        return c;
    };
    const ler = (bloco) => ({
        x: parseInt(bloco.style.getPropertyValue('--x'), 10) || 0,
        y: parseInt(bloco.style.getPropertyValue('--y'), 10) || 0,
        w: parseInt(bloco.style.getPropertyValue('--w'), 10) || 4,
        h: parseInt(bloco.style.getPropertyValue('--h'), 10) || 3,
    });
    const ajustarAltura = () => {
        let max = 6;
        mapa.querySelectorAll('[data-bloco]').forEach((b) => { const p = ler(b); max = Math.max(max, p.y + p.h + 2); });
        mapa.style.setProperty('--mapa-linhas', String(max));
    };
    const aplicar = (bloco, p, marcar = true) => {
        p.w = Math.max(1, Math.min(colunas, p.w));
        p.h = Math.max(1, Math.min(20, p.h));
        p.x = Math.max(0, Math.min(colunas - p.w, p.x));
        p.y = Math.max(0, Math.min(200, p.y));
        ['x', 'y', 'w', 'h'].forEach((k) => bloco.style.setProperty('--' + k, String(p[k])));
        const c = campos(bloco.dataset.bloco);
        ['x', 'y', 'w', 'h'].forEach((k) => { if (c[k]) c[k].value = String(p[k]); });
        ajustarAltura();
        if (marcar && !alterado) {
            alterado = true;
            if (status) status.textContent = 'Alterações ainda não salvas.';
        }
    };

    // Campos numéricos → bloco.
    form.querySelectorAll('[data-posicao]').forEach((tr) => tr.addEventListener('input', () => {
        const bloco = mapa.querySelector(`[data-bloco="${tr.dataset.posicao}"]`);
        if (!bloco) return;
        const c = campos(tr.dataset.posicao);
        aplicar(bloco, { x: +c.x.value || 0, y: +c.y.value || 0, w: +c.w.value || 1, h: +c.h.value || 1 });
    }));

    // Arrastar.
    let arraste = null;
    mapa.addEventListener('pointerdown', (e) => {
        const bloco = e.target.closest('[data-bloco]');
        if (!bloco || e.target.closest('a, button') || e.button !== 0) return;
        if (window.matchMedia('(max-width: 640px)').matches) return; // no celular é uma lista
        e.preventDefault();
        bloco.setPointerCapture(e.pointerId);
        bloco.classList.add('is-arrastando');
        arraste = { bloco, inicio: ler(bloco), x0: e.clientX, y0: e.clientY, coluna: mapa.clientWidth / colunas };
    });
    mapa.addEventListener('pointermove', (e) => {
        if (!arraste) return;
        const dx = Math.round((e.clientX - arraste.x0) / arraste.coluna);
        const dy = Math.round((e.clientY - arraste.y0) / linhaPx);
        aplicar(arraste.bloco, { ...arraste.inicio, x: arraste.inicio.x + dx, y: arraste.inicio.y + dy }, dx !== 0 || dy !== 0);
    });
    const soltar = () => {
        if (!arraste) return;
        arraste.bloco.classList.remove('is-arrastando');
        arraste.bloco.focus({ preventScroll: true });
        arraste = null;
    };
    mapa.addEventListener('pointerup', soltar);
    mapa.addEventListener('pointercancel', soltar);

    // Teclado: setas movem; Shift + setas mudam o tamanho.
    mapa.addEventListener('keydown', (e) => {
        const bloco = e.target.closest('[data-bloco]');
        if (!bloco || e.target !== bloco) return;
        const mapaTeclas = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        const d = mapaTeclas[e.key];
        if (!d) return;
        e.preventDefault();
        const p = ler(bloco);
        if (e.shiftKey) { p.w += d[0]; p.h += d[1]; } else { p.x += d[0]; p.y += d[1]; }
        aplicar(bloco, p);
        if (status) status.textContent = `Estante ${bloco.querySelector('.adm-mapa__id')?.textContent || ''}: coluna ${ler(bloco).x}, linha ${ler(bloco).y}, ${ler(bloco).w} × ${ler(bloco).h}. Alterações ainda não salvas.`;
    });

    form.addEventListener('submit', () => { alterado = false; });
    window.addEventListener('beforeunload', (e) => {
        if (alterado) { e.preventDefault(); e.returnValue = ''; }
    });
})();
