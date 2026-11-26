/*
 * Formulário do registro (Grupo 1 e revisão do Grupo 2): salvamento automático confirmado pelo
 * servidor, indicador de situação, exemplares/conservação, pesquisa de obras e atalhos.
 * Sem dependências. Usado em equipe/ (participantes) e admin/registro.php.
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-reorg-form]');
  if (!form) { return; }

  var admin = document.querySelector('[data-reorg-admin]');
  var modo = admin ? 'admin' : 'equipe';
  var raiz = admin || document.body;
  var api = raiz.getAttribute('data-api');
  var csrf = raiz.getAttribute('data-csrf');
  var marca = document.querySelector('[data-reorg-registro]');
  var somenteLeitura = admin ? !!(window.REORG_REGISTRO && window.REORG_REGISTRO.somenteLeitura) : (marca && marca.getAttribute('data-somente-leitura') === '1');
  var id = form.getAttribute('data-id');
  var versao = parseInt(form.getAttribute('data-versao'), 10) || 0;
  var indicador = document.querySelector('[data-reorg-indicador]');
  var chaveLocal = 'reorg-pendente-' + modo + '-' + id;
  var temporizador = null, salvando = false, pendente = false, nova = false, emConflito = false;

  var ROTULOS = { salvando: 'Salvando alterações...', salvo: 'Todas as alterações salvas', offline: 'Sem conexão — alterações ainda não sincronizadas', erro: 'Erro ao salvar — tentar novamente', leitura: 'Somente consulta' };

  function estado(tipo, extra) {
    if (!indicador) { return; }
    indicador.textContent = ROTULOS[tipo] + (extra ? ' · ' + extra : '');
    indicador.setAttribute('data-estado', tipo);
  }

  function post(dados) {
    var corpo = new URLSearchParams();
    Object.keys(dados).forEach(function (k) {
      var v = dados[k];
      if (Array.isArray(v)) { v.forEach(function (x) { corpo.append(k + '[]', x); }); } else { corpo.append(k, v); }
    });
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: corpo })
      .then(function (r) { return r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor.' }; }).then(function (j) { j._status = r.status; return j; }); });
  }
  window.REORG_POST = window.REORG_POST || post;

  function dadosFormulario() {
    var fd = new FormData(form);
    var corpo = new URLSearchParams();
    fd.forEach(function (v, k) { corpo.append(k, v); });
    return corpo;
  }

  function hora(texto) {
    if (!texto) { return ''; }
    var m = /(\d{2}):(\d{2}):(\d{2})$/.exec(texto);
    return m ? 'servidor ' + m[1] + ':' + m[2] + ':' + m[3] : '';
  }

  function mostrarErros(erros) {
    form.querySelectorAll('[data-campo]').forEach(function (el) {
      var msg = erros && erros[el.getAttribute('data-campo')];
      el.classList.toggle('is-invalid', !!msg);
      var alvo = el.querySelector('.adm-field__error');
      if (alvo) { alvo.textContent = msg || ''; }
    });
  }

  function mostrarAvisos(avisos) {
    var caixa = form.querySelector('[data-reorg-avisos]');
    if (!caixa) { return; }
    caixa.hidden = !(avisos && avisos.length);
    caixa.innerHTML = '';
    (avisos || []).forEach(function (a) { var p = document.createElement('p'); p.textContent = a; caixa.appendChild(p); });
  }

  function salvar() {
    if (somenteLeitura || emConflito) { return Promise.resolve(); }
    if (salvando) { nova = true; return Promise.resolve(); }
    clearTimeout(temporizador);
    salvando = true; nova = false;
    estado('salvando');
    var corpo = dadosFormulario();
    corpo.append('acao', 'salvar'); corpo.append('id', id); corpo.append('versao', versao);
    try { localStorage.setItem(chaveLocal, corpo.toString()); } catch (e) { /* armazenamento indisponível */ }
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: corpo })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); })
      .then(function (j) {
        salvando = false;
        if (j.ok) {
          versao = j.versao; form.setAttribute('data-versao', versao);
          pendente = nova;
          try { localStorage.removeItem(chaveLocal); } catch (e) { /* ignora */ }
          mostrarErros(j.erros || {}); mostrarAvisos(j.avisos || []);
          estado('salvo', (j.completo ? 'completo' : 'incompleto') + (hora(j.salvo_em) ? ' · ' + hora(j.salvo_em) : ''));
          if (nova) { agendar(200); }
          return;
        }
        if (j.encerrada) { pendente = true; estado('erro'); window.location.href = (document.body.getAttribute('data-base') || '.') + '?encerrada=1'; return; }
        if (j.conflito) { emConflito = true; pendente = false; mostrarConflito(j); estado('erro'); return; }
        pendente = true; estado('erro'); aviso(j.erro || 'Não foi possível salvar.');
      })
      .catch(function () { salvando = false; pendente = true; estado('offline'); agendar(5000); });
  }

  function agendar(ms) { clearTimeout(temporizador); temporizador = setTimeout(salvar, ms); }
  function alterado() { if (somenteLeitura) { return; } pendente = true; estado('salvando'); agendar(900); }

  function aviso(texto) {
    var caixa = form.querySelector('[data-reorg-avisos]');
    if (!caixa) { alert(texto); return; }
    caixa.hidden = false; caixa.innerHTML = ''; var p = document.createElement('p'); p.className = 'is-erro'; p.textContent = texto; caixa.appendChild(p);
  }

  function mostrarConflito(j) {
    var caixa = form.querySelector('[data-reorg-avisos]');
    caixa.hidden = false; caixa.innerHTML = '';
    var p = document.createElement('p'); p.className = 'is-erro'; p.textContent = j.erro; caixa.appendChild(p);
    if (j.atual) {
      var lista = document.createElement('ul');
      ['titulo', 'autores', 'editora', 'isbn', 'cdd', 'quantidade'].forEach(function (c) {
        var li = document.createElement('li'); li.textContent = c + ': ' + (j.atual[c] == null ? '—' : j.atual[c]); lista.appendChild(li);
      });
      caixa.appendChild(lista);
    }
    var b = document.createElement('button'); b.type = 'button'; b.className = 'adm-button adm-button--sm'; b.textContent = 'Carregar os dados atuais';
    b.addEventListener('click', function () { try { localStorage.removeItem(chaveLocal); } catch (e) { /* ignora */ } window.location.reload(); });
    caixa.appendChild(b);
  }

  // --- exemplares e conservação
  var lista = form.querySelector('[data-reorg-estados]');
  var qtd = form.querySelector('[data-reorg-qtd]');
  var aplicar = form.querySelector('[data-reorg-aplicar]');
  var max = (window.REORG_REGISTRO && window.REORG_REGISTRO.max) || (marca && parseInt(marca.getAttribute('data-max'), 10)) || 50;
  var opcoes = [['excelente', 'Excelente'], ['regular', 'Regular'], ['ruim', 'Ruim']];

  function linhas() {
    var n = Math.max(1, Math.min(max, parseInt(qtd.value, 10) || 1));
    var atuais = lista.querySelectorAll('li');
    for (var i = atuais.length + 1; i <= n; i++) {
      var li = document.createElement('li'); li.setAttribute('data-ordem', i);
      var s = document.createElement('span'); s.textContent = 'Exemplar ' + i; li.appendChild(s);
      opcoes.forEach(function (o) {
        var l = document.createElement('label'); l.className = 'reorg-radio';
        var r = document.createElement('input'); r.type = 'radio'; r.name = 'estados[' + i + ']'; r.value = o[0];
        if (aplicar && aplicar.value === o[0]) { r.checked = true; }
        l.appendChild(r); l.appendChild(document.createTextNode(' ' + o[1])); li.appendChild(l);
      });
      lista.appendChild(li);
    }
    for (var j = atuais.length; j > n; j--) { lista.removeChild(lista.lastElementChild); }
  }
  if (qtd && lista) { qtd.addEventListener('input', linhas); }
  if (aplicar) {
    aplicar.addEventListener('change', function () {
      if (!aplicar.value) { return; }
      lista.querySelectorAll('input[value="' + aplicar.value + '"]').forEach(function (r) { r.checked = true; });
    });
  }

  // --- "não encontrado / não indicado": o campo fica vazio e desativado (nada é escrito nele)
  form.querySelectorAll('[data-limpa]').forEach(function (chk) {
    var campo = form.querySelector('[name="' + chk.getAttribute('data-limpa') + '"]');
    function aplicarMarca() { if (!campo) { return; } if (chk.checked) { campo.value = ''; } campo.disabled = chk.checked || somenteLeitura; }
    chk.addEventListener('change', aplicarMarca); aplicarMarca();
  });

  if (somenteLeitura) { estado('leitura'); } else {
    form.addEventListener('input', alterado);
    form.addEventListener('change', alterado);
    // Recupera o que ficou sem sincronizar (queda de conexão) e tenta de novo.
    try {
      var guardado = localStorage.getItem(chaveLocal);
      if (guardado) {
        var dados = new URLSearchParams(guardado);
        if (window.confirm('Há alterações deste registro que não chegaram ao servidor (conexão perdida). Enviar agora?')) {
          dados.forEach(function (v, k) { var el = form.querySelector('[name="' + k + '"]'); if (el && el.type !== 'radio' && el.type !== 'checkbox') { el.value = v; } });
          dados.forEach(function (v, k) { var el = form.querySelector('[name="' + k + '"][value="' + v + '"]'); if (el && (el.type === 'radio' || el.type === 'checkbox')) { el.checked = true; } });
          if (qtd) { linhas(); }
          salvar();
        } else { localStorage.removeItem(chaveLocal); }
      }
    } catch (e) { /* ignora */ }
  }

  // Enter = próximo campo; Shift+Enter = anterior; Ctrl+S = salvar agora.
  form.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.tagName !== 'TEXTAREA' && ev.target.tagName !== 'BUTTON') {
      ev.preventDefault();
      var campos = Array.prototype.filter.call(form.querySelectorAll('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])'), function (el) { return el.offsetParent !== null; });
      var i = campos.indexOf(ev.target);
      var alvo = campos[i + (ev.shiftKey ? -1 : 1)];
      if (alvo) { alvo.focus(); }
    }
  });
  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && (ev.key === 's' || ev.key === 'S')) { ev.preventDefault(); salvar(); }
  });

  window.addEventListener('beforeunload', function (ev) {
    if (pendente || salvando) { ev.preventDefault(); ev.returnValue = ''; }
  });
  window.addEventListener('online', function () { if (pendente) { salvar(); } });

  // Administração: mantém a reserva de edição enquanto a página está aberta e libera ao sair.
  if (modo === 'admin' && !somenteLeitura) {
    setInterval(function () { post({ acao: 'manter', id: id }); }, 30000);
    window.addEventListener('pagehide', function () {
      var b = new URLSearchParams({ acao: 'liberar', id: id, csrf: csrf });
      if (navigator.sendBeacon) { navigator.sendBeacon(api, b); }
    });
    document.querySelectorAll('[data-reorg-salvar-antes]').forEach(function (b) {
      b.addEventListener('click', function (ev) {
        if (!pendente && !salvando) { return; }
        ev.preventDefault(); salvar().then(function () { setTimeout(function () { b.form.submit(); }, 300); });
      });
    });
  }

  // --- pesquisa de obras do acervo
  var pesquisa = document.querySelector('[data-reorg-pesquisa]');
  if (pesquisa && !somenteLeitura) {
    var q = pesquisa.querySelector('[data-reorg-q]');
    var res = pesquisa.querySelector('[data-reorg-resultados]');
    var tq = null;
    var abrir = document.querySelector('[data-reorg-abrir-pesquisa]');
    if (abrir) { abrir.addEventListener('click', function () { pesquisa.hidden = !pesquisa.hidden; if (!pesquisa.hidden) { q.focus(); } }); }
    q.addEventListener('input', function () {
      clearTimeout(tq);
      tq = setTimeout(function () {
        var termo = q.value.trim();
        if (termo.length < 2) { res.innerHTML = ''; return; }
        var pedido = modo === 'admin'
          ? fetch(api + '?acao=pesquisar&q=' + encodeURIComponent(termo), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
          : post({ acao: 'pesquisar', q: termo });
        pedido.then(function (j) {
          res.innerHTML = '';
          if (!j.ok || !j.obras.length) { var v = document.createElement('li'); v.className = 'adm-table__muted'; v.textContent = j.erro || 'Nenhuma obra encontrada. Preencha os dados do livro.'; res.appendChild(v); return; }
          j.obras.forEach(function (o) {
            var li = document.createElement('li');
            var t = document.createElement('div');
            t.innerHTML = '<strong></strong><small></small>';
            t.querySelector('strong').textContent = o.titulo + (o.subtitulo ? ': ' + o.subtitulo : '');
            t.querySelector('small').textContent = [o.autores, o.editora, o.edicao, o.ano_publicacao, o.isbn ? 'ISBN ' + o.isbn : '', o.cdd ? 'CDD ' + o.cdd : '', o.exemplares + ' exemplar(es) no acervo'].filter(Boolean).join(' · ');
            var b = document.createElement('button'); b.type = 'button'; b.className = 'adm-button adm-button--sm'; b.textContent = 'Usar esta obra';
            b.addEventListener('click', function () { usarObra(o); });
            li.appendChild(t); li.appendChild(b); res.appendChild(li);
          });
        });
      }, 350);
    });
  }

  function usarObra(o) {
    if (modo === 'admin') {
      var f = document.querySelector('[data-reorg-vincular]');
      f.querySelector('[name=livro_id]').value = o.id;
      salvar().then(function () { f.submit(); });
      return;
    }
    var mapa = { titulo: o.titulo, subtitulo: o.subtitulo, autores: o.autores, editora: o.editora, isbn: o.isbn, ano_publicacao: o.ano_publicacao, edicao: o.edicao, idioma: o.idioma, assunto: o.assunto, categoria_id: o.categoria_id, cdd: o.cdd };
    Object.keys(mapa).forEach(function (k) {
      var el = form.querySelector('[name="' + k + '"]');
      if (el && mapa[k] != null && mapa[k] !== '') { el.value = mapa[k]; }
    });
    ['autor_nao_indicado', 'editora_nao_indicada', 'isbn_nao_encontrado', 'cdd_nao_encontrada'].forEach(function (k) {
      var c = form.querySelector('[name="' + k + '"]'), alvo = c && form.querySelector('[name="' + c.getAttribute('data-limpa') + '"]');
      if (c && alvo && alvo.value) { c.checked = false; alvo.disabled = false; }
    });
    salvar().then(function () { return post({ acao: 'sugerir', id: id, livro_id: o.id }); }).then(function (j) {
      if (j && !j.ok) { aviso(j.erro); return; }
      pendente = false; window.location.reload();
    });
  }

  window.REORG_FORM = { pendente: function () { return pendente || salvando; }, esquecer: function () { pendente = false; salvando = false; clearTimeout(temporizador); try { localStorage.removeItem(chaveLocal); } catch (e) { /* ignora */ } }, salvarAgora: salvar, abrirPesquisa: function () { var a = document.querySelector('[data-reorg-abrir-pesquisa]'); if (a) { a.click(); } } };
})();
