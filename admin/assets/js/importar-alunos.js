/*
 * Importar Alunos: escolha do arquivo, mapeamento, prévia e confirmação.
 * O arquivo escolhido fica só na memória desta página e é reenviado ao servidor local a cada etapa.
 * Nada é guardado no navegador (sem localStorage) nem enviado a outros endereços.
 */
(function () {
    'use strict';

    const app = document.querySelector('[data-alunos-app]');
    const configEl = document.getElementById('alunos-config');
    if (!app || !configEl) return;

    const cfg = JSON.parse(configEl.textContent);
    const csrf = app.dataset.csrf;
    const $ = (sel, raiz = app) => raiz.querySelector(sel);
    const $$ = (sel, raiz = app) => Array.from(raiz.querySelectorAll(sel));
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const numero = (n) => Number(n || 0).toLocaleString('pt-BR');
    const tamanhoTexto = (b) => b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(1).replace('.', ',') + ' KB' : (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
    const toast = (t, m, tom) => window.admToast && window.admToast(t, m, tom);
    const modal = document.getElementById('modal-confirmar-importacao');
    const POR_PAGINA = 50;

    let arquivo = null;
    let leitura = null;   // resposta de "ler"
    let previa = null;    // resposta de "previa"
    let filtro = 'todos';
    let pagina = 1;
    let relatorio = [];

    // ---------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------
    async function chamar(acao, extras = {}) {
        const fd = new FormData();
        fd.append('acao', acao);
        fd.append('arquivo', arquivo, arquivo.name);
        Object.entries(extras).forEach(([k, v]) => fd.append(k, v));
        let resposta;
        try {
            resposta = await fetch(cfg.api, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' } });
        } catch (e) {
            throw new Error('Sem conexão com o servidor local. Confira se o XAMPP está ligado.');
        }
        let json = null;
        try { json = await resposta.json(); } catch (e) { /* resposta não JSON */ }
        if (!resposta.ok || !json || json.ok === false) {
            const erro = new Error(json?.erro || 'O servidor não conseguiu concluir a operação.');
            erro.dados = json;
            if (resposta.status === 401) setTimeout(() => window.location.reload(), 1800);
            throw erro;
        }
        return json;
    }

    function ocupado(botao, ativo) {
        app.classList.toggle('is-busy', ativo);
        if (!botao) return;
        botao.disabled = ativo;
        botao.classList.toggle('is-loading', ativo);
    }

    function erro(passo, mensagem) {
        const el = $(`[data-erro="${passo}"]`);
        if (el) el.textContent = mensagem || '';
    }

    function passo(n) {
        $$('[data-passo]').forEach((el) => { el.hidden = Number(el.dataset.passo) !== n; });
        $$('.adm-imp-steps__item').forEach((li) => {
            const etapa = Number(li.dataset.etapa);
            li.classList.toggle('is-done', etapa < n);
            li.classList.toggle('is-current', etapa === n);
            li.classList.toggle('is-todo', etapa > n);
            if (etapa === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
        });
        app.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function mapeamentoAtual() {
        const mapa = {};
        $$('[data-campo]').forEach((sel) => { mapa[sel.dataset.campo] = sel.value; });
        return mapa;
    }

    function parametros() {
        return {
            aba: $('[data-aba]').value,
            linha_cabecalho: $('[data-linha-cabecalho]').value || '1',
            mapeamento: JSON.stringify(mapeamentoAtual()),
            modo: $('input[name="modo"]:checked').value,
            importar_duplicidades: $('[data-importar-duplicidades]').checked ? '1' : '0',
        };
    }

    // ---------------------------------------------------------------
    // 1. Arquivo
    // ---------------------------------------------------------------
    const input = $('[data-arquivo]');
    const drop = $('[data-drop]');

    function escolher(f) {
        erro(1, '');
        if (!f) return;
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!['xlsx', 'csv'].includes(ext)) {
            erro(1, 'Formato não aceito. Escolha um arquivo .xlsx ou .csv.');
            return;
        }
        if (ext === 'xlsx' && !cfg.xlsx) {
            erro(1, 'A leitura de .xlsx não está instalada. Salve a planilha como CSV ou rode "composer install".');
            return;
        }
        if (f.size > cfg.limite) {
            erro(1, 'O arquivo passa de 5 MB. Remova abas e colunas que não serão usadas ou divida a planilha.');
            return;
        }
        arquivo = f;
        $('[data-arquivo-nome]').textContent = f.name;
        $('[data-arquivo-tamanho]').textContent = tamanhoTexto(f.size);
        $('[data-arquivo-escolhido]').hidden = false;
        drop.hidden = true;
        $('[data-ler]').disabled = false;
    }

    function limparArquivo() {
        arquivo = null;
        leitura = null;
        previa = null;
        relatorio = [];
        input.value = '';
        $('[data-arquivo-escolhido]').hidden = true;
        drop.hidden = false;
        $('[data-ler]').disabled = true;
        $('[data-colunas]').innerHTML = '';
        $('[data-linhas]').innerHTML = '';
        $('[data-relatorio-linhas]').innerHTML = '';
    }

    input.addEventListener('change', () => escolher(input.files[0]));
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (e) => escolher(e.dataTransfer.files[0]));
    $('[data-arquivo-limpar]').addEventListener('click', limparArquivo);

    async function ler(botao, extras = {}) {
        ocupado(botao, true);
        erro(1, '');
        erro(2, '');
        try {
            leitura = await chamar('ler', extras);
            mostrarColunas();
            passo(2);
        } catch (e) {
            erro(leitura ? 2 : 1, e.message);
        } finally {
            ocupado(botao, false);
        }
    }
    $('[data-ler]').addEventListener('click', (e) => ler(e.currentTarget));

    // ---------------------------------------------------------------
    // 2. Mapeamento
    // ---------------------------------------------------------------
    function mostrarColunas() {
        const abaSel = $('[data-aba]');
        abaSel.innerHTML = leitura.abas.map((a) => `<option value="${esc(a.nome)}"${a.nome === leitura.aba ? ' selected' : ''}>${esc(a.nome)} (${numero(a.linhas)} linhas)</option>`).join('');
        abaSel.disabled = leitura.tipo === 'csv';
        $('[data-linha-cabecalho]').value = leitura.linha_cabecalho;
        $('[data-info="nome"]').textContent = arquivo.name;
        $('[data-info="linhas"]').textContent = numero(leitura.linhas);
        $('[data-info="colunas"]').textContent = numero(leitura.colunas.length);
        $('[data-info="tipo"]').textContent = leitura.tipo === 'csv' ? 'CSV' : 'Excel (.xlsx)';
        const aviso = $('[data-aviso-linhas]');
        aviso.hidden = !leitura.aviso;
        aviso.textContent = leitura.aviso || '';
        const opcoes = Object.entries(cfg.campos);
        $('[data-colunas]').innerHTML = leitura.colunas.map((c) => `
            <tr class="${c.campo === 'ignorar' ? 'is-ignored' : ''}">
                <th scope="row"><span class="adm-imp-col"><span class="adm-imp-col__letra">${esc(c.letra)}</span>${esc(c.cabecalho)}</span></th>
                <td><span class="adm-imp-exemplos">${c.exemplos.length ? c.exemplos.map((x) => `<span>${esc(x)}</span>`).join('') : '<span class="adm-missing">vazia</span>'}</span></td>
                <td><select class="adm-select" data-campo="${esc(c.letra)}" aria-label="Campo da coluna ${esc(c.cabecalho)}">
                    ${opcoes.map(([v, r]) => `<option value="${esc(v)}"${v === c.campo ? ' selected' : ''}>${esc(r)}</option>`).join('')}
                </select></td>
            </tr>`).join('');
        conferirMapeamento();
    }

    function conferirMapeamento() {
        const usados = {};
        let problema = '';
        $$('[data-campo]').forEach((sel) => {
            sel.closest('tr').classList.toggle('is-ignored', sel.value === 'ignorar');
            sel.classList.remove('is-invalid');
            if (sel.value === 'ignorar') return;
            if (usados[sel.value]) {
                sel.classList.add('is-invalid');
                usados[sel.value].classList.add('is-invalid');
                problema = `O campo "${cfg.campos[sel.value].replace(' (obrigatório)', '')}" está em mais de uma coluna.`;
            }
            usados[sel.value] = sel;
        });
        if (!problema && (!usados.nome || !usados.matricula)) {
            problema = 'Indique as colunas de Nome e de Matrícula.';
        }
        $('[data-gerar-previa]').disabled = problema !== '';
        erro(2, problema);
        return problema === '';
    }

    $('[data-colunas]').addEventListener('change', (e) => { if (e.target.matches('[data-campo]')) conferirMapeamento(); });
    $('[data-aba]').addEventListener('change', () => ler(null, { aba: $('[data-aba]').value, linha_cabecalho: $('[data-linha-cabecalho]').value }));
    $('[data-linha-cabecalho]').addEventListener('change', () => ler(null, { aba: $('[data-aba]').value, linha_cabecalho: $('[data-linha-cabecalho]').value }));

    async function gerarPrevia(botao) {
        if (!conferirMapeamento()) return;
        ocupado(botao, true);
        erro(2, '');
        erro(3, '');
        try {
            previa = await chamar('previa', parametros());
            filtro = 'todos';
            pagina = 1;
            $('[data-confirmo-atualizacao]').checked = false;
            mostrarPrevia();
            passo(3);
        } catch (e) {
            erro($('[data-passo="3"]').hidden ? 2 : 3, e.message);
            if ($('[data-passo="3"]').hidden === false) { previa = null; atualizarBotaoImportar(); }
        } finally {
            ocupado(botao, false);
        }
    }
    $('[data-gerar-previa]').addEventListener('click', (e) => gerarPrevia(e.currentTarget));

    // ---------------------------------------------------------------
    // 3. Prévia
    // ---------------------------------------------------------------
    const FILTROS = [
        ['todos', 'Todas', () => true],
        ['incluir', 'A incluir', (i) => i.acao === 'incluir'],
        ['atualizar', 'A atualizar', (i) => i.acao === 'atualizar'],
        ['cadastrados', 'Já cadastrados', (i) => i.situacao === 'existente' || i.situacao === 'igual'],
        ['duplicidade', 'Possíveis duplicidades', (i) => i.situacao === 'duplicidade'],
        ['erro', 'Erros', (i) => i.situacao === 'erro'],
        ['avisos', 'Com avisos', (i) => i.avisos.length > 0],
    ];

    function mostrarPrevia() {
        const r = previa.resumo;
        const kpis = [
            ['Registros na planilha', r.total, 'blue'],
            ['Serão incluídos', r.incluir, 'green'],
            ['Serão atualizados', r.atualizar, 'indigo'],
            ['Já cadastrados', r.ja_cadastrados + r.sem_alteracao, 'muted'],
            ['Possíveis duplicidades', r.duplicidades, 'amber'],
            ['Com erro', r.erros, 'red'],
        ];
        $('[data-resumo]').innerHTML = kpis.map(([t, v, tom]) => `
            <div class="adm-kpi adm-alu-kpi--${tom}"><span><span class="adm-kpi__value">${numero(v)}</span><span class="adm-kpi__title">${esc(t)}</span></span></div>`).join('');
        $$('[data-filtros]').forEach((el) => {
            el.innerHTML = FILTROS.map(([id, rotulo, f]) => `<button type="button" role="tab" data-filtro="${id}" aria-selected="${id === filtro}">${esc(rotulo)} <span class="adm-tabs__count">${numero(previa.itens.filter(f).length)}</span></button>`).join('');
        });
        mostrarLinhas();
        atualizarBotaoImportar();
    }

    function celula(item, campo) {
        const alt = item.alteracoes && item.alteracoes[campo];
        let valor = item.dados[campo];
        if (campo === 'turno' && valor) valor = cfg.turnos[valor] || valor;
        if (alt) {
            const de = campo === 'turno' && alt.de ? (cfg.turnos[alt.de] || alt.de) : alt.de;
            const para = campo === 'turno' ? (cfg.turnos[alt.para] || alt.para) : alt.para;
            return `<span class="adm-alu-diff"><del>${de ? esc(de) : '(vazio)'}</del></span><span class="adm-alu-diff"><ins>${esc(para)}</ins></span>`;
        }
        return valor ? esc(valor) : '<span class="adm-missing">—</span>';
    }

    function mostrarLinhas() {
        const f = FILTROS.find(([id]) => id === filtro)[2];
        const lista = previa.itens.filter(f);
        const paginas = Math.max(1, Math.ceil(lista.length / POR_PAGINA));
        pagina = Math.min(pagina, paginas);
        const trecho = lista.slice((pagina - 1) * POR_PAGINA, pagina * POR_PAGINA);
        $('[data-linhas]').innerHTML = trecho.length ? trecho.map((i) => {
            const s = cfg.situacoes[i.situacao] || { label: i.situacao, tom: 'blue' };
            const acao = i.acao === 'incluir' ? 'Será incluído' : i.acao === 'atualizar' ? 'Será atualizado' : 'Não será gravado';
            const msgs = i.erros.map((m) => `<li class="is-erro">${esc(m)}</li>`).concat(i.avisos.map((m) => `<li class="is-aviso">${esc(m)}</li>`)).join('');
            return `<tr class="adm-imp-row--${i.situacao === 'erro' ? 'erro' : i.situacao === 'duplicidade' ? 'duplicidade' : i.avisos.length ? 'aviso' : 'ok'}">
                <td data-label="Linha">${numero(i.linha)}</td>
                <td data-label="Matrícula">${i.dados.matricula ? esc(i.dados.matricula) : '<span class="adm-missing">—</span>'}</td>
                <td class="adm-td-title" data-label="Nome">${celula(i, 'nome')}</td>
                <td data-label="Turma">${celula(i, 'turma')}</td>
                <td data-label="Turno">${celula(i, 'turno')}</td>
                <td data-label="E-mail">${celula(i, 'email')}</td>
                <td data-label="Situação"><span class="adm-status adm-status--soft adm-tone--${s.tom}">${esc(s.label)}</span><br><small>${acao}</small>${msgs ? `<ul class="adm-alu-msgs">${msgs}</ul>` : ''}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="7"><div class="adm-empty"><strong>Nenhuma linha neste filtro.</strong></div></td></tr>';
        $('[data-paginacao]').innerHTML = lista.length > POR_PAGINA ? `
            <span class="adm-pagination__info">${numero((pagina - 1) * POR_PAGINA + 1)}–${numero(Math.min(pagina * POR_PAGINA, lista.length))} de ${numero(lista.length)}</span>
            <span class="adm-pagination__pages">
                <button type="button" data-pagina="${pagina - 1}"${pagina === 1 ? ' disabled' : ''}>Anterior</button>
                <button type="button" data-pagina="${pagina + 1}"${pagina === paginas ? ' disabled' : ''}>Próxima</button>
            </span>` : '';
    }

    function atualizarBotaoImportar() {
        const botao = $('[data-importar]');
        const caixa = $('[data-confirmar-atualizacao]');
        if (!previa) {
            botao.disabled = true;
            caixa.hidden = true;
            return;
        }
        const r = previa.resumo;
        const total = r.incluir + r.atualizar;
        caixa.hidden = r.atualizar === 0;
        $('[data-texto-atualizacao]').textContent = `${numero(r.atualizar)} cadastro(s) existente(s) serão alterados.`;
        const precisaConfirmar = r.atualizar > 0 && !$('[data-confirmo-atualizacao]').checked;
        botao.disabled = total === 0 || precisaConfirmar;
        const partes = [];
        if (r.incluir) partes.push(`incluir ${numero(r.incluir)}`);
        if (r.atualizar) partes.push(`atualizar ${numero(r.atualizar)}`);
        $('[data-texto-importar]').textContent = total ? 'Importar: ' + partes.join(' e ') : 'Nada para importar';
    }

    app.addEventListener('click', (e) => {
        const f = e.target.closest('[data-filtro]');
        if (f) { filtro = f.dataset.filtro; pagina = 1; mostrarPrevia(); }
        const p = e.target.closest('[data-pagina]');
        if (p && !p.disabled) { pagina = Number(p.dataset.pagina); mostrarLinhas(); }
        const v = e.target.closest('[data-voltar]');
        if (v) {
            if (v.dataset.voltar === '1') limparArquivo();
            passo(Number(v.dataset.voltar));
        }
    });
    // Mudou uma opção: a prévia antiga deixa de valer e é gerada de novo.
    $$('[data-opcao]').forEach((el) => el.addEventListener('change', () => {
        previa = null;
        atualizarBotaoImportar();
        gerarPrevia(null);
    }));
    $('[data-confirmo-atualizacao]').addEventListener('change', atualizarBotaoImportar);

    $('[data-importar]').addEventListener('click', () => {
        if (!previa) return;
        const r = previa.resumo;
        const partes = [];
        if (r.incluir) partes.push(`${numero(r.incluir)} aluno(s) novo(s) serão incluídos`);
        if (r.atualizar) partes.push(`${numero(r.atualizar)} cadastro(s) existente(s) serão atualizados`);
        $('[data-modal-texto]', modal).textContent = partes.join(' e ') + `. ${numero(r.ignorados)} linha(s) não serão gravadas. Tudo é gravado de uma vez: se algo falhar, nada muda.`;
        modal.showModal ? modal.showModal() : modal.setAttribute('open', '');
    });

    $('[data-confirmar]', modal).addEventListener('click', async (e) => {
        const botao = e.currentTarget;
        ocupado(botao, true);
        erro(3, '');
        try {
            const extras = parametros();
            extras.assinatura = previa.assinatura;
            extras.confirmo_atualizacao = $('[data-confirmo-atualizacao]').checked ? '1' : '0';
            const r = await chamar('confirmar', extras);
            modal.close ? modal.close() : modal.removeAttribute('open');
            mostrarResultado(r);
        } catch (err) {
            modal.close ? modal.close() : modal.removeAttribute('open');
            erro(3, err.message);
            if (err.dados && err.dados.refazer_previa) toast('Prévia desatualizada', 'Gere a prévia novamente antes de importar.', 'warning');
        } finally {
            ocupado(botao, false);
        }
    });
    modal.addEventListener('click', (e) => {
        if (e.target.closest('[data-modal-close]') || e.target === modal) modal.close ? modal.close() : modal.removeAttribute('open');
    });

    // ---------------------------------------------------------------
    // 4. Resultado
    // ---------------------------------------------------------------
    function mostrarResultado(r) {
        relatorio = r.nao_importados;
        $('[data-resultado-titulo]').textContent = 'Importação concluída';
        $('[data-resultado-texto]').textContent = `${numero(r.incluidos)} aluno(s) incluído(s), ${numero(r.atualizados)} atualizado(s) e ${numero(relatorio.length)} linha(s) não importada(s).`;
        $('[data-relatorio]').hidden = relatorio.length === 0;
        $('[data-baixar-relatorio]').hidden = relatorio.length === 0;
        $('[data-relatorio-linhas]').innerHTML = relatorio.slice(0, 500).map((l) => `<tr><td>${numero(l.linha)}</td><td>${l.matricula ? esc(l.matricula) : '—'}</td><td>${esc(l.motivo)}</td></tr>`).join('')
            + (relatorio.length > 500 ? `<tr><td colspan="3">… e mais ${numero(relatorio.length - 500)} linha(s) no relatório para download.</td></tr>` : '');
        const total = document.querySelector('[data-total-alunos]');
        if (total) total.textContent = numero(r.total_alunos);
        previa = null;
        passo(4);
        toast('Alunos importados', `${numero(r.incluidos + r.atualizados)} cadastro(s) gravado(s).`, 'success');
    }

    // Relatório gerado no navegador (nunca salvo no servidor). Evita fórmulas ao abrir no Excel.
    $('[data-baixar-relatorio]').addEventListener('click', () => {
        const celulaCsv = (v) => {
            let t = String(v ?? '');
            if (/^[=+\-@\t\r]/.test(t)) t = "'" + t;
            return '"' + t.replace(/"/g, '""') + '"';
        };
        const linhas = [['Linha', 'Matrícula', 'Motivo']].concat(relatorio.map((l) => [l.linha, l.matricula, l.motivo]));
        const csv = '﻿' + linhas.map((l) => l.map(celulaCsv).join(';')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'relatorio-importacao-alunos.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    $('[data-nova]').addEventListener('click', () => {
        limparArquivo();
        passo(1);
    });
}());
