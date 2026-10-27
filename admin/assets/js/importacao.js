/* Importação de planilhas (Etapa 3): envio, mapeamento, análise, revisão e confirmação. */
(function () {
    'use strict';

    const toast = (titulo, mensagem, tom = 'info') => window.admToast && window.admToast(titulo, mensagem, tom);
    const $ = (sel, raiz = document) => raiz.querySelector(sel);
    const $$ = (sel, raiz = document) => Array.from(raiz.querySelectorAll(sel));
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /** Chamada à API com CSRF. Lança Error com a mensagem do servidor. */
    async function api(base, csrf, arquivo, dados, metodo = 'POST') {
        let url = base + arquivo;
        const opcoes = { method: metodo, headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' }, credentials: 'same-origin' };
        if (metodo === 'GET') {
            url += '?' + new URLSearchParams(dados).toString();
        } else {
            const fd = dados instanceof FormData ? dados : new FormData();
            if (!(dados instanceof FormData)) {
                Object.entries(dados).forEach(([k, v]) => {
                    if (v && typeof v === 'object' && !(v instanceof Blob)) {
                        Object.entries(v).forEach(([k2, v2]) => fd.append(`${k}[${k2}]`, v2 ?? ''));
                    } else {
                        fd.append(k, v ?? '');
                    }
                });
            }
            opcoes.body = fd;
        }
        let resposta;
        try {
            resposta = await fetch(url, opcoes);
        } catch (e) {
            const erro = new Error('Sem conexão com o servidor. Verifique a internet e tente novamente.');
            erro.rede = true;
            throw erro;
        }
        let json = null;
        try {
            json = await resposta.json();
        } catch (e) { /* resposta não JSON */ }
        if (!resposta.ok || !json || json.ok === false) {
            const erro = new Error(json?.erro || 'O servidor não conseguiu concluir a operação.');
            erro.status = resposta.status;
            erro.dados = json;
            if (resposta.status === 401) {
                erro.message += ' Você será levado à tela de login.';
                setTimeout(() => window.location.reload(), 1800);
            }
            throw erro;
        }
        return json;
    }

    function carregando(botao, ativo, texto) {
        if (!botao) return;
        if (ativo) {
            botao.dataset.textoOriginal ??= botao.innerHTML;
            botao.classList.add('is-loading');
            botao.disabled = true;
            if (texto) botao.innerHTML = botao.innerHTML.replace(/>[^<>]*$/, '> ' + esc(texto));
        } else {
            botao.classList.remove('is-loading');
            botao.disabled = false;
            if (botao.dataset.textoOriginal) botao.innerHTML = botao.dataset.textoOriginal;
            delete botao.dataset.textoOriginal;
        }
    }

    function abrirModal(dialog) {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    }
    function fecharModal(dialog) {
        if (!dialog) return;
        if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    }
    document.addEventListener('click', (e) => {
        const fechar = e.target.closest('[data-modal-close]');
        if (fechar) fecharModal(fechar.closest('dialog'));
        const abrir = e.target.closest('[data-modal-open]');
        if (abrir) abrirModal(document.getElementById(abrir.dataset.modalOpen));
    });
    document.addEventListener('click', (e) => {
        if (e.target instanceof HTMLDialogElement && e.target.classList.contains('adm-modal') && !e.target.hasAttribute('data-modal-fixo')) {
            fecharModal(e.target);
        }
    });

    function definirEtapa(raiz, n) {
        $$('.adm-imp-steps__item', raiz).forEach((li) => {
            const etapa = Number(li.dataset.etapa);
            li.classList.toggle('is-done', etapa < n);
            li.classList.toggle('is-current', etapa === n);
            li.classList.toggle('is-todo', etapa > n);
            if (etapa === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
            const num = $('.adm-imp-steps__num', li);
            num.innerHTML = etapa < n ? '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>' : String(etapa);
        });
    }

    const tamanhoTexto = (bytes) => bytes < 1024 ? bytes + ' B' : bytes < 1048576 ? (bytes / 1024).toFixed(1).replace('.', ',') + ' KB' : (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
    const numero = (n) => Number(n || 0).toLocaleString('pt-BR');

    // =================================================================
    // Assistente de envio (importacao.php)
    // =================================================================
    const app = $('[data-importacao-app]');
    const configEl = document.getElementById('importacao-config');
    if (app && configEl) {
        const cfg = JSON.parse(configEl.textContent);
        const csrf = app.dataset.csrf;
        const chamar = (arquivo, dados, metodo) => api(cfg.api, csrf, arquivo, dados, metodo);
        let importacao = null;   // {id, nome, tamanho, abas, aba}
        let colunas = [];
        let arquivoEscolhido = null;

        const passo = (n) => {
            $$('[data-passo]', app).forEach((el) => { el.hidden = Number(el.dataset.passo) !== n; });
            definirEtapa(app, n);
        };

        $$('[data-nova-importacao]').forEach((a) => a.addEventListener('click', (e) => {
            e.preventDefault();
            app.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (!importacao) setTimeout(() => $('[data-drop]', app)?.focus(), 300);
        }));

        // ---------- 1. Enviar ----------
        const drop = $('[data-drop]', app);
        const input = $('[data-arquivo]', app);
        const escolhido = $('[data-arquivo-escolhido]', app);
        const erroEnvio = $('[data-erro-envio]', app);
        const botaoEnviar = $('[data-enviar]', app);
        drop.tabIndex = 0;
        drop.setAttribute('role', 'button');
        drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });

        function escolher(arquivo) {
            erroEnvio.textContent = '';
            arquivoEscolhido = null;
            botaoEnviar.disabled = true;
            escolhido.hidden = true;
            if (!arquivo) return;
            const ext = arquivo.name.split('.').pop().toLowerCase();
            if (!['xlsx', 'csv'].includes(ext)) {
                erroEnvio.textContent = 'Formato não aceito. Envie um arquivo .xlsx ou .csv.';
                return;
            }
            if (ext === 'xlsx' && !cfg.xlsx) {
                erroEnvio.textContent = 'A leitura de .xlsx ainda não está instalada no servidor. Salve a planilha como CSV e envie novamente.';
                return;
            }
            if (arquivo.size > cfg.limite) {
                erroEnvio.textContent = 'O arquivo passa de 20 MB. Divida a planilha em partes menores.';
                return;
            }
            if (arquivo.size === 0) {
                erroEnvio.textContent = 'O arquivo está vazio.';
                return;
            }
            arquivoEscolhido = arquivo;
            $('[data-arquivo-nome]', app).textContent = arquivo.name;
            $('[data-arquivo-tamanho]', app).textContent = tamanhoTexto(arquivo.size) + ' · ' + ext.toUpperCase();
            escolhido.hidden = false;
            botaoEnviar.disabled = false;
            botaoEnviar.focus();
        }
        input.addEventListener('change', () => escolher(input.files[0]));
        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
        drop.addEventListener('drop', (e) => {
            const arquivos = e.dataTransfer?.files;
            if (arquivos && arquivos.length > 1) {
                erroEnvio.textContent = 'Envie uma planilha por vez.';
                return;
            }
            escolher(arquivos?.[0]);
        });
        $('[data-arquivo-limpar]', app).addEventListener('click', () => { input.value = ''; escolher(null); });

        botaoEnviar.addEventListener('click', async () => {
            if (!arquivoEscolhido) return;
            const fd = new FormData();
            fd.append('acao', 'enviar');
            fd.append('arquivo', arquivoEscolhido);
            carregando(botaoEnviar, true, 'Lendo planilha…');
            erroEnvio.textContent = '';
            try {
                const r = await chamar('upload.php', fd);
                importacao = r.importacao;
                history.replaceState(null, '', '?id=' + importacao.id + '#nova-importacao');
                mostrarMapeamento(r);
                toast('Arquivo recebido', `${importacao.nome} foi guardado com segurança.`, 'success');
            } catch (e) {
                erroEnvio.innerHTML = esc(e.message) + (e.dados?.url ? ` <a class="adm-link" href="${esc(e.dados.url)}">Abrir importação existente</a>` : '');
            } finally {
                carregando(botaoEnviar, false);
            }
        });

        // ---------- 2. Mapear ----------
        const selAba = $('[data-aba]', app);
        const inputLinha = $('[data-linha-cabecalho]', app);
        const selSalvo = $('[data-mapeamento-salvo]', app);
        const corpo = $('[data-colunas]', app);
        const erroMapa = $('[data-erro-mapa]', app);

        function preencherSalvos(lista) {
            const atual = selSalvo.value;
            selSalvo.innerHTML = '<option value="">Sugestão automática</option>' + (lista || []).map((m) => `<option value="${m.id}">${esc(m.nome)}</option>`).join('');
            selSalvo.value = lista?.some((m) => String(m.id) === atual) ? atual : '';
        }

        function mostrarMapeamento(r) {
            importacao = r.importacao;
            colunas = r.colunas;
            $('[data-info="nome"]', app).textContent = importacao.nome;
            $('[data-info="tamanho"]', app).textContent = importacao.tamanho;
            $('[data-info="linhas"]', app).textContent = numero(r.linhas);
            $('[data-info="colunas"]', app).textContent = numero(r.colunas.length);
            selAba.innerHTML = importacao.abas.map((a) => `<option value="${esc(a.nome)}">${esc(a.nome)} (${numero(Math.max(0, a.linhas - 1))} linhas)</option>`).join('');
            selAba.value = r.aba;
            selAba.disabled = importacao.abas.length < 2;
            $('[data-aba-dica]', app).textContent = importacao.abas.length > 1 ? `${importacao.abas.length} abas encontradas. Escolha a que contém o acervo.` : 'A planilha tem uma única aba.';
            inputLinha.value = r.linha_cabecalho;
            preencherSalvos(r.mapeamentos);
            const opcoes = Object.entries(cfg.campos).map(([valor, rotulo]) => `<option value="${valor}">${esc(rotulo)}</option>`).join('');
            corpo.innerHTML = r.colunas.map((c, i) => `
                <tr>
                    <th scope="row"><span class="adm-imp-col"><span class="adm-imp-col__letra">${esc(c.letra)}</span>${esc(c.cabecalho)}</span></th>
                    <td class="adm-imp-exemplos">${c.exemplos.length ? c.exemplos.map((x) => `<span>${esc(x)}</span>`).join('') : '<span class="adm-missing">Sem valores nas primeiras linhas</span>'}</td>
                    <td><label class="adm-visually-hidden" for="campo-${i}">Campo para a coluna ${esc(c.cabecalho)}</label>
                        <select class="adm-select adm-select--sm" id="campo-${i}" data-campo="${esc(c.cabecalho)}">${opcoes}</select></td>
                </tr>`).join('');
            $$('[data-campo]', corpo).forEach((sel, i) => { sel.value = r.colunas[i].campo; });
            marcarRepetidos();
            erroMapa.textContent = '';
            passo(2);
        }

        function mapeamentoAtual() {
            const mapa = {};
            $$('[data-campo]', corpo).forEach((sel) => { mapa[sel.dataset.campo] = sel.value; });
            return mapa;
        }

        function marcarRepetidos() {
            const contagem = {};
            const selects = $$('[data-campo]', corpo);
            selects.forEach((s) => { contagem[s.value] = (contagem[s.value] || 0) + 1; });
            selects.forEach((s) => {
                const repetido = !['ignorar', 'extra', 'observacoes'].includes(s.value) && contagem[s.value] > 1;
                s.classList.toggle('is-invalid', repetido);
                s.closest('tr').classList.toggle('is-ignored', s.value === 'ignorar');
            });
            const temTitulo = selects.some((s) => s.value === 'titulo');
            const repetidos = selects.some((s) => s.classList.contains('is-invalid'));
            erroMapa.textContent = !temTitulo ? 'Escolha a coluna que contém o título do livro.' : repetidos ? 'Há campos escolhidos para mais de uma coluna.' : '';
            return temTitulo && !repetidos;
        }
        corpo.addEventListener('change', marcarRepetidos);

        async function inspecionar() {
            erroMapa.textContent = '';
            app.classList.add('is-busy');
            try {
                const r = await chamar('upload.php', { acao: 'inspecionar', id: importacao.id, aba: selAba.value, linha_cabecalho: inputLinha.value, mapeamento_id: selSalvo.value });
                mostrarMapeamento(r);
            } catch (e) {
                erroMapa.textContent = e.message;
            } finally {
                app.classList.remove('is-busy');
            }
        }
        selAba.addEventListener('change', inspecionar);
        inputLinha.addEventListener('change', inspecionar);
        selSalvo.addEventListener('change', inspecionar);

        $('[data-salvar-mapeamento]', app).addEventListener('click', async (e) => {
            const nome = $('[data-mapeamento-nome]', app).value.trim();
            if (!nome) {
                $('[data-mapeamento-nome]', app).focus();
                erroMapa.textContent = 'Dê um nome ao mapeamento para salvá-lo.';
                return;
            }
            carregando(e.currentTarget, true);
            try {
                const r = await chamar('upload.php', { acao: 'salvar_mapeamento', nome, mapeamento: JSON.stringify(mapeamentoAtual()) });
                preencherSalvos(r.mapeamentos);
                selSalvo.value = String(r.id);
                toast('Mapeamento salvo', `"${nome}" poderá ser usado nas próximas importações.`, 'success');
            } catch (err) {
                erroMapa.textContent = err.message;
            } finally {
                carregando(e.currentTarget, false);
            }
        });

        const modalTroca = document.getElementById('modal-cancelar-envio');
        $('[data-trocar-arquivo]', app).addEventListener('click', () => abrirModal(modalTroca));
        $('[data-confirmar-troca]', modalTroca).addEventListener('click', async (e) => {
            carregando(e.currentTarget, true);
            try {
                await chamar('confirmar.php', { acao: 'cancelar', id: importacao.id });
                fecharModal(modalTroca);
                importacao = null;
                input.value = '';
                escolher(null);
                history.replaceState(null, '', window.location.pathname + '#nova-importacao');
                passo(1);
                toast('Importação cancelada', 'Escolha outro arquivo para continuar.', 'info');
            } catch (err) {
                toast('Não foi possível cancelar', err.message, 'error');
            } finally {
                carregando(e.currentTarget, false);
            }
        });

        // ---------- 3. Validar ----------
        const barra = $('[data-progresso-barra]', app);
        const textoProgresso = $('[data-progresso-texto]', app);
        const erroAnalise = $('[data-erro-analise]', app);
        const acoesAnalise = $('[data-analise-acoes]', app);

        $('[data-analisar]', app).addEventListener('click', async (e) => {
            if (!marcarRepetidos()) return;
            carregando(e.currentTarget, true, 'Preparando…');
            try {
                const r = await chamar('validar.php', { acao: 'analisar', id: importacao.id, aba: selAba.value, linha_cabecalho: inputLinha.value, mapeamento: JSON.stringify(mapeamentoAtual()) });
                passo(3);
                analisar(r.total_previsto);
            } catch (err) {
                erroMapa.textContent = err.message;
            } finally {
                carregando(e.currentTarget, false);
            }
        });

        async function analisar(totalPrevisto) {
            erroAnalise.textContent = '';
            acoesAnalise.hidden = true;
            let tentativas = 0;
            for (;;) {
                try {
                    const r = await chamar('validar.php', { acao: 'lote', id: importacao.id });
                    tentativas = 0;
                    const total = r.total_previsto || totalPrevisto || 0;
                    const pct = total ? Math.min(100, Math.round((r.analisadas / total) * 100)) : 100;
                    barra.style.width = pct + '%';
                    textoProgresso.textContent = `${numero(r.analisadas)} de ${numero(total)} linhas analisadas (${pct}%).`;
                    if (r.concluida) {
                        $('[data-progresso-titulo]', app).textContent = 'Análise concluída. Abrindo a revisão…';
                        window.location.href = r.url || (cfg.detalhes + '?id=' + importacao.id);
                        return;
                    }
                } catch (err) {
                    // Quedas de conexão: tenta de novo algumas vezes (o servidor ignora blocos repetidos).
                    if (err.rede && tentativas < 3) {
                        tentativas++;
                        textoProgresso.textContent = `Conexão instável. Tentando novamente (${tentativas}/3)…`;
                        await new Promise((ok) => setTimeout(ok, 1500 * tentativas));
                        continue;
                    }
                    erroAnalise.textContent = err.message;
                    acoesAnalise.hidden = false;
                    return;
                }
            }
        }
        $('[data-retomar-analise]', app).addEventListener('click', () => analisar(0));

        // ---------- Retomar uma importação aberta ----------
        if (cfg.retomar) {
            importacao = { id: cfg.retomar.id };
            if (cfg.retomar.status === 'em_analise') {
                passo(3);
                analisar(0);
            } else {
                chamar('upload.php', { acao: 'inspecionar', id: cfg.retomar.id }).then(mostrarMapeamento).catch((e) => { erroEnvio.textContent = e.message; });
            }
        }
    }

    // =================================================================
    // Revisão e confirmação (detalhes-importacao.php)
    // =================================================================
    const revisao = $('[data-revisao]');
    const revisaoCfgEl = document.getElementById('revisao-config');
    if (revisao && revisaoCfgEl) {
        const cfg = JSON.parse(revisaoCfgEl.textContent);
        const chamar = (arquivo, dados, metodo) => api(cfg.api, cfg.csrf, arquivo, dados, metodo);
        const modal = document.getElementById('modal-linha');
        let itemAtual = null;

        // Clique na linha da tabela abre os detalhes (o botão "Revisar" também).
        $$('[data-item]', revisao).forEach((tr) => {
            tr.addEventListener('click', (e) => {
                if (e.target.closest('a')) return;
                abrirItem(tr.dataset.item);
            });
            tr.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.target === tr) abrirItem(tr.dataset.item);
            });
        });

        async function abrirItem(id) {
            $('[data-linha-corpo]', modal).innerHTML = '<p class="adm-imp-loading">Carregando…</p>';
            abrirModal(modal);
            try {
                const r = await chamar('validar.php', { acao: 'item', item_id: id }, 'GET');
                renderItem(r.item);
            } catch (e) {
                $('[data-linha-corpo]', modal).innerHTML = `<div class="adm-notice adm-notice--red">${esc(e.message)}</div>`;
            }
        }

        const nivelInfo = {
            erro: ['red', 'Erro'], aviso: ['amber', 'Aviso'], duplicidade: ['indigo', 'Duplicidade'], info: ['blue', 'Informação'],
        };

        function livroTexto(l) {
            if (!l) return '';
            return `<strong>nº ${esc(l.id)} — ${esc(l.titulo)}</strong><small>${esc([l.autores, l.editora, l.ano_publicacao, l.isbn ? 'ISBN ' + l.isbn : ''].filter(Boolean).join(' · '))}</small>`;
        }

        function renderItem(item) {
            itemAtual = item;
            $('[data-linha-titulo]', modal).textContent = `Linha ${item.linha} da planilha`;
            $('[data-linha-subtitulo]', modal).textContent = item.normalizados.titulo || 'Sem título';
            const problemas = item.problemas.length
                ? `<ul class="adm-imp-problems">${item.problemas.map((p) => `<li class="adm-imp-problem adm-imp-problem--${p.nivel}"><span class="adm-status adm-status--soft adm-tone--${nivelInfo[p.nivel][0]}">${nivelInfo[p.nivel][1]}</span><span>${esc(p.mensagem)}</span></li>`).join('')}</ul>`
                : '<p class="adm-imp-ok">Nenhum problema encontrado nesta linha.</p>';

            const normalizados = Object.entries(item.normalizados)
                .map(([k, v]) => `<div><dt>${esc(item.rotulos[k] || k)}${item.corrigidos[k] !== undefined ? ' <span class="adm-pill adm-tone--blue">corrigido</span>' : ''}</dt><dd>${v === null || v === '' ? '<span class="adm-missing">—</span>' : esc(v)}</dd></div>`).join('');
            const originais = item.originais
                .map((o) => `<tr><th scope="row">${esc(o.coluna)}</th><td>${o.valor === '' ? '<span class="adm-missing">vazio</span>' : esc(o.valor)}</td><td class="adm-table__muted">${esc(o.campo)}</td></tr>`).join('');

            let decisao = '';
            if (item.resultado) {
                decisao = `<div class="adm-notice adm-notice--${item.resultado === 'falhou' ? 'red' : 'green'} adm-notice--compact"><div class="adm-notice__content"><strong>${esc(item.resultado_label)}</strong>${item.mensagem_processamento ? `<p>${esc(item.mensagem_processamento)}</p>` : ''}${item.livro_url ? `<p><a class="adm-link" href="${esc(item.livro_url)}">Abrir o livro no acervo</a></p>` : ''}</div></div>`;
            }
            const sugestao = item.livro_sugerido ? `<div class="adm-imp-book">${livroTexto(item.livro_sugerido)}</div>` : '';
            const vinculado = item.livro_vinculado ? `<div class="adm-imp-book">${livroTexto(item.livro_vinculado)}</div>` : '';

            const editaveis = Object.entries(item.editaveis).map(([k, v]) => `
                <label class="adm-field${k === 'titulo' ? ' adm-field--full' : ''}">
                    <span class="adm-field__label">${esc(item.rotulos[k] || k)}</span>
                    <input class="adm-input" name="${k}" value="${esc(v ?? '')}" ${k === 'quantidade_informada' || k === 'ano_publicacao' ? 'inputmode="numeric"' : ''} ${item.editavel ? '' : 'disabled'}>
                </label>`).join('');

            const acoes = item.editavel ? `
                <section class="adm-imp-decision" aria-labelledby="titulo-decisao">
                    <h3 id="titulo-decisao">O que fazer com esta linha?</h3>
                    <p class="adm-field__hint">Decisão atual: <strong>${esc(item.acao_label || 'aguardando decisão')}</strong>${item.acao_automatica && item.acao ? ' (sugestão automática)' : ''}${item.revisado_por ? ` · revisado por ${esc(item.revisado_por)} em ${esc(item.revisado_em)}` : ''}.</p>
                    <div class="adm-imp-decision__grid">
                        <button type="button" class="adm-button adm-button--ghost" data-decidir="criar" ${item.situacao === 'erro' ? 'disabled title="Corrija os erros primeiro"' : ''}>${icone('plus')} Criar novo livro</button>
                        ${item.livro_sugerido ? `<button type="button" class="adm-button adm-button--ghost" data-decidir="vincular" data-livro="${item.livro_sugerido.id}">${icone('link')} Vincular ao nº ${esc(item.livro_sugerido.id)}</button>` : ''}
                        ${item.linha_relacionada ? `<button type="button" class="adm-button adm-button--ghost" data-decidir="outro_exemplar" data-linha="${item.linha_relacionada}">${icone('copy')} Outro exemplar da linha ${esc(item.linha_relacionada)}</button>` : ''}
                        <button type="button" class="adm-button adm-button--ghost" data-decidir="revisar_depois">${icone('hourglass')} Revisar depois</button>
                        <button type="button" class="adm-button adm-button--ghost adm-button--danger-text" data-decidir="ignorar">${icone('ban')} Ignorar registro</button>
                        ${!item.acao_automatica ? `<button type="button" class="adm-button adm-button--ghost" data-decidir="automatica">${icone('undo-2')} Voltar à sugestão automática</button>` : ''}
                    </div>
                    <details class="adm-imp-link-other">
                        <summary>Vincular a outro livro do acervo</summary>
                        <div class="adm-imp-search">
                            <input class="adm-input" type="search" placeholder="Pesquisar por título, autor, ISBN ou nº do cadastro" data-buscar-livro>
                            <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-buscar-livro-btn>${icone('search')} Pesquisar</button>
                        </div>
                        <ul class="adm-imp-results" data-resultados-livros></ul>
                    </details>
                    <details class="adm-imp-link-other">
                        <summary>Confirmar que é outro exemplar de outra linha</summary>
                        <div class="adm-imp-search">
                            <input class="adm-input" type="number" min="1" placeholder="Número da linha na planilha" data-linha-ref inputmode="numeric">
                            <button type="button" class="adm-button adm-button--ghost adm-button--sm" data-decidir-linha>${icone('copy')} Confirmar</button>
                        </div>
                    </details>
                    <p class="adm-field__error" data-erro-decisao role="alert"></p>
                </section>` : '';

            $('[data-linha-corpo]', modal).innerHTML = `
                <div class="adm-imp-modal-head">
                    <span class="adm-status adm-status--soft adm-tone--${{ valido: 'green', aviso: 'amber', erro: 'red', duplicidade: 'indigo' }[item.situacao]}">${esc(item.situacao_label)}</span>
                    ${item.ja_cadastrado ? '<span class="adm-status adm-status--soft adm-tone--blue">Já cadastrado no acervo</span>' : ''}
                </div>
                ${decisao}
                <section><h3 class="adm-imp-h3">Problemas identificados</h3>${problemas}</section>
                ${sugestao ? `<section><h3 class="adm-imp-h3">Livro parecido no acervo</h3>${sugestao}</section>` : ''}
                ${vinculado && !item.resultado ? `<section><h3 class="adm-imp-h3">Livro escolhido para vínculo</h3>${vinculado}</section>` : ''}
                ${item.referencia_linha ? `<p class="adm-field__hint">Registrada como outro exemplar da linha ${esc(item.referencia_linha)}.</p>` : ''}
                ${acoes}
                <section class="adm-imp-compare">
                    <div>
                        <h3 class="adm-imp-h3">Dados originais (planilha)</h3>
                        <div class="adm-table-wrap adm-table-wrap--flush"><table class="adm-table adm-imp-orig"><thead><tr><th>Coluna</th><th>Valor</th><th>Campo</th></tr></thead><tbody>${originais}</tbody></table></div>
                    </div>
                    <div>
                        <h3 class="adm-imp-h3">Dados normalizados</h3>
                        <dl class="adm-meta adm-meta--stack adm-imp-norm">${normalizados}</dl>
                    </div>
                </section>
                <section>
                    <h3 class="adm-imp-h3">Corrigir dados</h3>
                    <p class="adm-field__hint">As correções ficam separadas: o valor original da planilha nunca é apagado. A linha é validada de novo ao salvar.</p>
                    <form class="adm-form-grid" data-form-correcao novalidate>${editaveis}</form>
                    ${item.editavel ? `<div class="adm-form-actions adm-form-actions--split">
                        ${Object.keys(item.corrigidos).length ? `<button type="button" class="adm-button adm-button--ghost" data-desfazer>${icone('undo-2')} Desfazer correções</button>` : '<span></span>'}
                        <button type="button" class="adm-button adm-button--primary" data-salvar-correcao>${icone('save')} Salvar e validar novamente</button>
                    </div>` : '<p class="adm-field__hint">Esta linha não pode mais ser alterada.</p>'}
                    <p class="adm-field__error" data-erro-correcao role="alert"></p>
                </section>`;
        }

        // Ícones usados nos botões montados pelo JavaScript (mesmo traço dos ícones Lucide do painel).
        const ICONES = cfg.icones || {};
        function icone(nome) {
            return `<svg class="icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONES[nome] || ''}</svg>`;
        }

        let alterou = false;
        async function decidir(dados, botao) {
            const erro = $('[data-erro-decisao]', modal);
            if (erro) erro.textContent = '';
            carregando(botao, true);
            try {
                const r = await chamar('validar.php', { acao: 'decidir', item_id: itemAtual.id, ...dados });
                alterou = true;
                renderItem(r.item);
                toast('Decisão registrada', `Linha ${r.item.linha}: ${r.item.acao_label || 'sugestão automática'}.`, 'success');
            } catch (e) {
                if (erro) erro.textContent = e.message;
                carregando(botao, false);
            }
        }

        modal.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-decidir]');
            if (btn) {
                decidir({ decisao: btn.dataset.decidir, livro_id: btn.dataset.livro || '', linha: btn.dataset.linha || '' }, btn);
                return;
            }
            const escolherLivro = e.target.closest('[data-escolher-livro]');
            if (escolherLivro) {
                decidir({ decisao: 'vincular', livro_id: escolherLivro.dataset.escolherLivro }, escolherLivro);
                return;
            }
            if (e.target.closest('[data-decidir-linha]')) {
                const linha = $('[data-linha-ref]', modal).value;
                decidir({ decisao: 'outro_exemplar', linha }, e.target.closest('[data-decidir-linha]'));
                return;
            }
            if (e.target.closest('[data-buscar-livro-btn]')) {
                buscarLivros();
                return;
            }
            const salvar = e.target.closest('[data-salvar-correcao]');
            if (salvar) {
                const campos = {};
                $$('[data-form-correcao] input', modal).forEach((i) => { campos[i.name] = i.value; });
                carregando(salvar, true, 'Validando…');
                try {
                    const r = await chamar('validar.php', { acao: 'corrigir', item_id: itemAtual.id, campos });
                    alterou = true;
                    renderItem(r.item);
                    toast('Correção salva', `Linha ${r.item.linha}: ${r.item.situacao_label}.`, r.item.situacao === 'erro' ? 'warning' : 'success');
                } catch (err) {
                    $('[data-erro-correcao]', modal).textContent = err.message;
                    carregando(salvar, false);
                }
                return;
            }
            const desfazer = e.target.closest('[data-desfazer]');
            if (desfazer) {
                carregando(desfazer, true);
                try {
                    const r = await chamar('validar.php', { acao: 'desfazer', item_id: itemAtual.id });
                    alterou = true;
                    renderItem(r.item);
                } catch (err) {
                    $('[data-erro-correcao]', modal).textContent = err.message;
                    carregando(desfazer, false);
                }
            }
        });
        modal.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.matches('[data-buscar-livro]')) {
                e.preventDefault();
                buscarLivros();
            }
        });
        async function buscarLivros() {
            const q = $('[data-buscar-livro]', modal).value.trim();
            const lista = $('[data-resultados-livros]', modal);
            if (!q) return;
            lista.innerHTML = '<li class="adm-imp-loading">Pesquisando…</li>';
            try {
                const r = await chamar('validar.php', { acao: 'buscar_livros', q }, 'GET');
                lista.innerHTML = r.livros.length
                    ? r.livros.map((l) => `<li><span class="adm-imp-book">${livroTexto(l)}</span><button type="button" class="adm-button adm-button--ghost adm-button--sm" data-escolher-livro="${l.id}">${icone('link')} Vincular</button></li>`).join('')
                    : '<li class="adm-missing">Nenhum livro encontrado.</li>';
            } catch (err) {
                lista.innerHTML = `<li class="adm-field__error">${esc(err.message)}</li>`;
            }
        }
        // Ao fechar o modal depois de alguma alteração, recarrega a tabela e os totais.
        modal.addEventListener('close', () => {
            if (alterou) window.location.reload();
        });

        // Decisões em lote
        $$('[data-decidir-lote]', revisao).forEach((btn) => btn.addEventListener('click', async () => {
            if (!window.confirm(btn.dataset.confirmar)) return;
            carregando(btn, true);
            try {
                const r = await chamar('validar.php', { acao: 'decidir_lote', id: cfg.id, filtro: btn.dataset.filtro, decisao: btn.dataset.decidirLote });
                toast('Decisão aplicada', `${numero(r.alteradas)} linhas atualizadas.`, 'success');
                setTimeout(() => window.location.reload(), 600);
            } catch (e) {
                toast('Não foi possível aplicar', e.message, 'error');
                carregando(btn, false);
            }
        }));

        // ---------- Confirmação ----------
        const modalConfirmar = document.getElementById('modal-confirmar');
        const modalCancelar = document.getElementById('modal-cancelar');
        const progresso = $('[data-confirmacao-progresso]');

        async function gravar() {
            const barra = $('[data-confirmacao-barra]');
            const texto = $('[data-confirmacao-texto]');
            const erro = $('[data-confirmacao-erro]');
            const total = Number(progresso.dataset.total || 0);
            let gravados = Number(progresso.dataset.feitos || 0);
            let tentativas = 0;
            progresso.hidden = false;
            erro.textContent = '';
            $('[data-confirmacao-retomar]').hidden = true;
            progresso.scrollIntoView({ behavior: 'smooth', block: 'center' });
            for (;;) {
                try {
                    const r = await chamar('confirmar.php', { acao: 'lote', id: cfg.id });
                    tentativas = 0;
                    gravados += r.gravados + r.falhas;
                    const pct = total ? Math.min(100, Math.round((gravados / total) * 100)) : 100;
                    barra.style.width = pct + '%';
                    texto.textContent = `${numero(gravados)} de ${numero(total)} linhas processadas · ${numero(r.totais.criados)} livros criados, ${numero(r.totais.vinculados)} vinculados${r.totais.falhas ? `, ${numero(r.totais.falhas)} com falha` : ''}.`;
                    if (r.concluida) {
                        texto.textContent += ' Concluído!';
                        toast('Importação concluída', 'Os registros aprovados foram gravados no acervo.', 'success');
                        setTimeout(() => window.location.reload(), 900);
                        return;
                    }
                } catch (e) {
                    if (e.rede && tentativas < 3) {
                        tentativas++;
                        texto.textContent = `Conexão instável. Tentando novamente (${tentativas}/3)… Nenhuma linha será gravada duas vezes.`;
                        await new Promise((ok) => setTimeout(ok, 1500 * tentativas));
                        continue;
                    }
                    erro.textContent = e.message + ' As linhas já gravadas continuam salvas; você pode continuar depois.';
                    $('[data-confirmacao-retomar]').hidden = false;
                    return;
                }
            }
        }

        $('[data-confirmar-importacao]', modalConfirmar)?.addEventListener('click', async (e) => {
            carregando(e.currentTarget, true, 'Iniciando…');
            try {
                await chamar('confirmar.php', { acao: 'iniciar', id: cfg.id });
                fecharModal(modalConfirmar);
                $$('[data-bloquear-durante]').forEach((b) => { b.disabled = true; });
                gravar();
            } catch (err) {
                $('[data-erro-confirmar]', modalConfirmar).textContent = err.message;
            } finally {
                carregando(e.currentTarget, false);
            }
        });
        $('[data-confirmacao-retomar]')?.addEventListener('click', gravar);
        if (cfg.status === 'importando' && progresso) {
            gravar(); // retomada automática após interrupção
        }

        $('[data-cancelar-importacao]', modalCancelar)?.addEventListener('click', async (e) => {
            carregando(e.currentTarget, true);
            try {
                await chamar('confirmar.php', { acao: 'cancelar', id: cfg.id });
                toast('Importação cancelada', 'Nenhuma outra linha será gravada.', 'info');
                setTimeout(() => window.location.reload(), 600);
            } catch (err) {
                $('[data-erro-cancelar]', modalCancelar).textContent = err.message;
                carregando(e.currentTarget, false);
            }
        });
    }
})();
