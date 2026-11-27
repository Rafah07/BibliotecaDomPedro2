/*
 * Formulário do registro (Grupo 1 e revisão do Grupo 2).
 *
 * Salvamento automático confiável:
 *  - um envio por vez (nunca dois em paralelo), então uma resposta antiga não sobrescreve uma nova;
 *  - cada alteração aumenta a "geração"; só mostra "Todas as alterações salvas" quando o servidor
 *    confirmou a geração mais recente. Alterou durante o envio? Envia de novo logo depois;
 *  - a versão do registro vai junto: se outra pessoa salvou antes, nada é sobrescrito (conflito);
 *  - sem conexão, guarda uma cópia local por sessão e tenta de novo; a cópia de uma sessão nunca é
 *    oferecida a outra sessão (e expira em 24 horas);
 *  - encaminhar, aprovar, avançar, pausar ou trocar de registro só acontecem depois da confirmação.
 * Indicador: "Salvando alterações...", "Todas as alterações salvas", "Sem conexão — alterações ainda
 * não sincronizadas", "Erro ao salvar", "Atividade pausada".
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
  var cfg = document.querySelector('[data-reorg-registro-cfg]') || document.querySelector('[data-reorg-registro]');
  var somenteLeitura = !!(cfg && cfg.getAttribute('data-somente-leitura') === '1');
  var id = form.getAttribute('data-id');
  var versao = parseInt(form.getAttribute('data-versao'), 10) || 0;
  var sessao = document.body.getAttribute('data-sessao') || 'admin';
  var indicador = document.querySelector('[data-reorg-indicador-form]') || document.querySelector('[data-reorg-indicador]');
  var ultimoEl = document.querySelector('[data-reorg-ultimo]');
  var PREFIXO = 'reorg-rascunho-v2-';
  var chaveLocal = PREFIXO + modo + '-' + sessao + '-' + id;
  var VALIDADE = 24 * 3600 * 1000;

  var geracao = 0;          // aumenta a cada alteração
  var confirmada = 0;       // última geração confirmada pelo servidor
  var emVoo = null;         // promessa do envio em andamento
  var falhou = false;       // último envio falhou (sem conexão / erro)
  var conflito = false;
  var temporizador = null;
  var ultimoResultado = null;

  var ROTULOS = { salvando: 'Salvando alterações...', salvo: 'Todas as alterações salvas', offline: 'Sem conexão — alterações ainda não sincronizadas', erro: 'Erro ao salvar', leitura: 'Somente consulta', pausado: 'Atividade pausada' };

  function sujo() { return geracao !== confirmada; }

  function estado(tipo, extra) {
    if (!indicador) { return; }
    indicador.textContent = ROTULOS[tipo] + (extra ? ' · ' + extra : '');
    indicador.setAttribute('data-estado', tipo);
  }

  function hora(texto) {
    var m = /(\d{2}):(\d{2}):(\d{2})$/.exec(texto || '');
    return m ? m[1] + ':' + m[2] + ':' + m[3] : '';
  }

  function post(dados) {
    var corpo = new URLSearchParams();
    Object.keys(dados).forEach(function (k) {
      var v = dados[k];
      if (Array.isArray(v)) { v.forEach(function (x) { corpo.append(k + '[]', x); }); } else { corpo.append(k, v); }
    });
    return enviarCorpo(corpo);
  }
  function enviarCorpo(corpo) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: corpo })
      .then(function (r) { return r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor.' }; }).then(function (j) { j._status = r.status; return j; }); });
  }
  window.REORG_POST = window.REORG_POST || post;

  function dadosFormulario() {
    var corpo = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { corpo.append(k, v); });
    return corpo;
  }

  // ---------------------------------------------------------------- cópia local (por sessão)
  function guardarLocal() {
    try { localStorage.setItem(chaveLocal, JSON.stringify({ v: versao, t: Date.now(), d: dadosFormulario().toString() })); } catch (e) { /* armazenamento indisponível */ }
  }
  function esquecerLocal() { try { localStorage.removeItem(chaveLocal); } catch (e) { /* ignora */ } }
  function limparAntigos() {
    try {
      var remover = [];
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (!k) { continue; }
        if (k.indexOf('reorg-pendente-') === 0) { remover.push(k); continue; } // formato antigo, sem sessão
        if (k.indexOf(PREFIXO) !== 0) { continue; }
        // rascunho de outra sessão de participante neste navegador: não pertence a esta sessão
        if (modo === 'equipe' && k.indexOf(PREFIXO + 'equipe-' + sessao + '-') !== 0 && k.indexOf(PREFIXO + 'equipe-') === 0) { remover.push(k); continue; }
        var x = JSON.parse(localStorage.getItem(k) || '{}');
        if (!x.t || Date.now() - x.t > VALIDADE) { remover.push(k); }
      }
      remover.forEach(function (k) { localStorage.removeItem(k); });
    } catch (e) { /* ignora */ }
  }

  // ---------------------------------------------------------------- mensagens
  function caixaAvisos() { return form.querySelector('[data-reorg-avisos]'); }
  function mostrarErros(erros) {
    form.querySelectorAll('[data-campo]').forEach(function (el) {
      var msg = erros && erros[el.getAttribute('data-campo')];
      el.classList.toggle('is-invalid', !!msg);
      var alvo = el.querySelector('.adm-field__error');
      if (alvo) { alvo.textContent = msg || ''; }
    });
  }
  function mostrarAvisos(avisos) {
    var caixa = caixaAvisos();
    if (!caixa) { return; }
    caixa.hidden = !(avisos && avisos.length);
    caixa.innerHTML = '';
    (avisos || []).forEach(function (a) { var p = document.createElement('p'); p.textContent = a; caixa.appendChild(p); });
  }
  function aviso(texto, botoes) {
    var caixa = caixaAvisos();
    if (!caixa) { window.alert(texto); return; }
    caixa.hidden = false; caixa.innerHTML = '';
    var p = document.createElement('p'); p.className = 'is-erro'; p.textContent = texto; caixa.appendChild(p);
    (botoes || []).forEach(function (b) {
      var bt = document.createElement('button'); bt.type = 'button'; bt.className = 'adm-button adm-button--sm'; bt.textContent = b[0];
      bt.addEventListener('click', b[1]); caixa.appendChild(bt);
    });
    caixa.scrollIntoView({ block: 'nearest' });
  }

  function mostrarConflito(j) {
    var caixa = caixaAvisos();
    caixa.hidden = false; caixa.innerHTML = '';
    var p = document.createElement('p'); p.className = 'is-erro'; p.textContent = j.erro; caixa.appendChild(p);
    if (j.atual) {
      var lista = document.createElement('ul');
      [['titulo', 'Título'], ['autores', 'Autor(es)'], ['editora', 'Editora'], ['isbn', 'ISBN'], ['cdd', 'CDD'], ['quantidade', 'Quantidade']].forEach(function (c) {
        var li = document.createElement('li'); li.textContent = c[1] + ': ' + (j.atual[c[0]] == null ? '—' : j.atual[c[0]]); lista.appendChild(li);
      });
      caixa.appendChild(lista);
    }
    var b = document.createElement('button'); b.type = 'button'; b.className = 'adm-button adm-button--sm'; b.textContent = 'Carregar os dados atuais';
    b.addEventListener('click', function () { esquecerLocal(); confirmada = geracao; window.location.reload(); });
    caixa.appendChild(b);
  }

  // ---------------------------------------------------------------- salvamento
  function enviar() {
    var geracaoEnviada = geracao;
    var corpo = dadosFormulario();
    corpo.append('acao', 'salvar'); corpo.append('id', id); corpo.append('versao', versao);
    estado('salvando');
    emVoo = enviarCorpo(corpo).then(function (j) {
      if (j.ok) {
        falhou = false;
        versao = j.versao; form.setAttribute('data-versao', versao);
        document.querySelectorAll('[data-reorg-versao]').forEach(function (el) { el.value = versao; });
        ultimoResultado = j;
        if (geracao === geracaoEnviada) {
          confirmada = geracaoEnviada;
          esquecerLocal();
          mostrarErros(j.erros || {}); mostrarAvisos(j.avisos || []);
          estado('salvo', modo === 'equipe' ? (j.completo ? 'formulário completo' : 'formulário incompleto') : '');
        } else {
          guardarLocal(); // mudou enquanto salvava: a versão nova é enviada em seguida
        }
        if (ultimoEl && j.salvo_em) { ultimoEl.textContent = 'Último salvamento confirmado: ' + hora(j.salvo_em); }
        return;
      }
      falhou = true;
      if (j.encerrada) { estado('erro'); aviso(j.erro || 'Sua sessão foi encerrada.'); return; }
      if (j.conflito) { conflito = true; estado('erro'); mostrarConflito(j); return; }
      estado('erro');
      aviso((j.erro || 'Não foi possível salvar.') + ' Nada foi perdido nesta tela.', [['Tentar novamente', function () { falhou = false; salvar(); }]]);
    }).catch(function () {
      falhou = true; estado('offline'); agendar(5000);
    }).then(function () { emVoo = null; });
    return emVoo;
  }

  /** Salva até não restar nada pendente (ou falhar). Devolve o último resultado confirmado. */
  function salvar() {
    clearTimeout(temporizador);
    if (somenteLeitura || conflito) { return Promise.resolve(ultimoResultado); }
    if (emVoo) { return emVoo.then(function () { return sujo() && !falhou && !conflito ? salvar() : ultimoResultado; }); }
    if (!sujo()) { return Promise.resolve(ultimoResultado); }
    falhou = false;
    return enviar().then(function () { return sujo() && !falhou && !conflito ? salvar() : ultimoResultado; });
  }

  function agendar(ms) { clearTimeout(temporizador); temporizador = setTimeout(function () { falhou = false; salvar(); }, ms); }
  function alterado() {
    if (somenteLeitura) { return; }
    geracao++;
    guardarLocal();
    estado('salvando');
    agendar(800);
  }

  /** Para ações que dependem dos dados gravados: salva antes e diz se ficou tudo confirmado. */
  function garantirSalvo() {
    return salvar().then(function (res) {
      if (sujo() || falhou || conflito) {
        aviso(falhou && indicador && indicador.getAttribute('data-estado') === 'offline'
          ? 'Sem conexão: as alterações ainda não chegaram ao servidor. Aguarde a conexão voltar; nada foi enviado.'
          : 'As alterações ainda não foram salvas. Resolva o aviso acima e tente de novo; nada foi enviado.');
        return null;
      }
      return res || { ok: true, completo: form.getAttribute('data-completo') === '1' };
    });
  }

  // ---------------------------------------------------------------- ISBN e CDD: encontrado / não encontrado
  function aplicarSituacao(bloco) {
    var campo = bloco.querySelector('input.adm-input');
    var nao = bloco.querySelector('input[type=radio][value^="nao_"]');
    if (!campo || !nao) { return; }
    if (nao.checked) { campo.value = ''; }
    campo.disabled = nao.checked || somenteLeitura;
  }
  form.querySelectorAll('[data-situacao]').forEach(function (bloco) {
    bloco.querySelectorAll('input[type=radio]').forEach(function (r) {
      r.addEventListener('change', function () {
        aplicarSituacao(bloco);
        if (r.value.indexOf('nao_') !== 0) { var c = bloco.querySelector('input.adm-input'); if (c) { c.focus(); } }
      });
    });
    var campo = bloco.querySelector('input.adm-input');
    if (campo) {
      campo.addEventListener('input', function () {
        var sim = bloco.querySelector('input[type=radio]:not([value^="nao_"])');
        if (campo.value.trim() !== '' && sim && !sim.checked) { sim.checked = true; }
      });
    }
    aplicarSituacao(bloco);
  });

  // ---------------------------------------------------------------- exemplares e conservação
  var lista = form.querySelector('[data-reorg-estados]');
  var qtd = form.querySelector('[data-reorg-qtd]');
  var max = Math.min(parseInt((cfg && cfg.getAttribute('data-max')) || '50', 10) || 50, qtd ? parseInt(qtd.getAttribute('max'), 10) || 50 : 50);
  var opcoes = [['excelente', 'Excelente'], ['regular', 'Regular'], ['ruim', 'Ruim']];

  function linhas() {
    var pedido = parseInt(qtd.value, 10) || 1;
    var n = Math.max(1, Math.min(max, pedido));
    var erroQtd = qtd.closest('[data-campo]') && qtd.closest('[data-campo]').querySelector('.adm-field__error');
    if (pedido > max) {
      qtd.value = n;
      if (erroQtd) { erroQtd.textContent = 'Este registro pode ter no máximo ' + max + ' exemplar(es): o lote não pode passar da quantidade prevista.'; }
    }
    var atuais = lista.querySelectorAll('li');
    for (var i = atuais.length + 1; i <= n; i++) {
      var li = document.createElement('li'); li.setAttribute('data-ordem', i);
      var s = document.createElement('span'); s.textContent = 'Exemplar ' + i; li.appendChild(s);
      opcoes.forEach(function (o) {
        var l = document.createElement('label'); l.className = 'reorg-radio';
        var r = document.createElement('input'); r.type = 'radio'; r.name = 'estados[' + i + ']'; r.value = o[0];
        l.appendChild(r); l.appendChild(document.createTextNode(' ' + o[1])); li.appendChild(l);
      });
      lista.appendChild(li);
    }
    for (var j = atuais.length; j > n; j--) { lista.removeChild(lista.lastElementChild); }
  }
  if (qtd && lista) { qtd.addEventListener('input', linhas); }
  form.querySelectorAll('[data-reorg-aplicar]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      var v = bt.getAttribute('data-reorg-aplicar');
      lista.querySelectorAll('input[value="' + v + '"]').forEach(function (r) { r.checked = true; });
      alterado();
    });
  });

  // ---------------------------------------------------------------- recuperação da cópia local
  function restaurar(texto) {
    var dados = new URLSearchParams(texto);
    if (qtd && dados.get('quantidade')) { qtd.value = dados.get('quantidade'); linhas(); }
    form.querySelectorAll('input[type=radio]').forEach(function (r) { if (dados.has(r.name)) { r.checked = dados.get(r.name) === r.value; } });
    dados.forEach(function (v, k) {
      var el = form.querySelector('[name="' + k + '"]');
      if (el && el.type !== 'radio' && el.type !== 'checkbox' && el.type !== 'button') { el.value = v; }
    });
    form.querySelectorAll('[data-situacao]').forEach(aplicarSituacao);
    alterado();
    salvar();
  }

  if (somenteLeitura) { estado('leitura'); } else {
    form.addEventListener('input', function (ev) { if (ev.target !== qtd || qtd.value !== '') { alterado(); } });
    form.addEventListener('change', alterado);
    limparAntigos();
    try {
      var guardado = JSON.parse(localStorage.getItem(chaveLocal) || 'null');
      if (guardado && guardado.d) {
        if (guardado.v === versao) {
          aviso('Há alterações deste registro feitas nesta sessão que não chegaram ao servidor (conexão perdida ou página fechada).',
            [['Recuperar e salvar agora', function () { mostrarAvisos([]); restaurar(guardado.d); }], ['Descartar', function () { esquecerLocal(); mostrarAvisos([]); }]]);
        } else {
          esquecerLocal(); // o registro mudou no servidor depois da cópia: valem os dados atuais
        }
      }
    } catch (e) { /* ignora */ }
  }

  // ---------------------------------------------------------------- teclado
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
    if (!somenteLeitura && (sujo() || emVoo)) { ev.preventDefault(); ev.returnValue = ''; }
  });
  window.addEventListener('online', function () { if (sujo()) { falhou = false; salvar(); } });

  // ---------------------------------------------------------------- ações que exigem tudo salvo
  // Administração: aprovar, cadastrar, devolver, pausar, vincular... (botões com data-reorg-salvar-antes)
  document.querySelectorAll('[data-reorg-salvar-antes]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      var pergunta = b.getAttribute('data-reorg-confirmar');
      if (pergunta && !window.confirm(pergunta)) { ev.preventDefault(); return; }
      if (somenteLeitura || (!sujo() && !emVoo)) { return; }
      ev.preventDefault();
      garantirSalvo().then(function (ok) {
        if (!ok) { return; }
        if (b.form.requestSubmit) { b.form.requestSubmit(b); } else { b.form.submit(); }
      });
    });
  });

  // Grupo 1: anterior / próximo / lista de registros
  document.querySelectorAll('[data-reorg-navegar]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (somenteLeitura || (!sujo() && !emVoo)) { return; }
      ev.preventDefault();
      garantirSalvo().then(function (ok) { if (ok) { window.location.href = a.href; } });
    });
  });

  // Grupo 1: confirmar e avançar (só com o formulário completo e salvo)
  var avancar = document.querySelector('[data-reorg-avancar]');
  if (avancar) {
    avancar.addEventListener('click', function () {
      avancar.disabled = true;
      if (!sujo() && !ultimoResultado) { geracao++; } // força uma conferência no servidor
      garantirSalvo().then(function (res) {
        avancar.disabled = false;
        if (!res) { return; }
        if (!res.completo) {
          mostrarErros(res.erros || {});
          aviso('Complete os campos destacados para confirmar este registro. O que já foi preenchido está salvo.');
          var primeiro = form.querySelector('.is-invalid input:not([disabled]), .is-invalid select, .is-invalid textarea');
          if (primeiro) { primeiro.focus(); }
          return;
        }
        var destino = avancar.getAttribute('data-reorg-avancar');
        if (destino !== 'novo') { window.location.href = destino; return; }
        post({ acao: 'novo', lote: document.body.getAttribute('data-lote') }).then(function (j) {
          if (j.ok) { window.location.href = j.url; return; }
          aviso(j.erro || 'Não foi possível abrir um novo registro.');
        });
      });
    });
  }
  var pausarBarra = document.querySelector('[data-reorg-pausar-barra]');
  if (pausarBarra) { pausarBarra.addEventListener('click', function () { var p = document.querySelector('[data-reorg-pausar]'); if (p) { p.click(); } }); }

  // Administração: mantém a reserva de edição enquanto a página está aberta e libera ao sair.
  if (modo === 'admin' && !somenteLeitura) {
    setInterval(function () { post({ acao: 'manter', id: id }); }, 30000);
    window.addEventListener('pagehide', function () {
      var b = new URLSearchParams({ acao: 'liberar', id: id, csrf: csrf });
      if (navigator.sendBeacon) { navigator.sendBeacon(api, b); }
    });
  }

  // ---------------------------------------------------------------- pesquisa de obras do acervo
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
            var b = document.createElement('button'); b.type = 'button'; b.className = 'adm-button adm-button--sm'; b.textContent = modo === 'admin' ? 'Vincular a esta obra' : 'Usar esta obra';
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
      garantirSalvo().then(function (ok) { if (ok) { f.submit(); } });
      return;
    }
    // Grupo 1: copia os dados da obra só para os campos vazios (o que foi lido no livro prevalece).
    var mapa = { titulo: o.titulo, subtitulo: o.subtitulo, autores: o.autores, editora: o.editora, isbn: o.isbn, ano_publicacao: o.ano_publicacao, edicao: o.edicao, idioma: o.idioma, assunto: o.assunto, categoria_id: o.categoria_id, cdd: o.cdd };
    Object.keys(mapa).forEach(function (k) {
      var el = form.querySelector('[name="' + k + '"]');
      if (el && mapa[k] != null && mapa[k] !== '' && !el.value) {
        if (el.disabled && (k === 'isbn' || k === 'cdd')) { return; } // marcado como não encontrado no livro
        el.value = mapa[k];
        var bloco = el.closest('[data-situacao]');
        var sim = bloco && bloco.querySelector('input[type=radio]:not([value^="nao_"])');
        if (sim) { sim.checked = true; aplicarSituacao(bloco); }
      }
    });
    alterado();
    garantirSalvo().then(function (ok) { return ok ? post({ acao: 'sugerir', id: id, livro_id: o.id }) : null; }).then(function (j) {
      if (!j) { return; }
      if (!j.ok) { aviso(j.erro); return; }
      window.location.reload();
    });
  }

  window.REORG_FORM = {
    pendente: function () { return !somenteLeitura && (sujo() || !!emVoo); },
    esquecer: function () { confirmada = geracao; clearTimeout(temporizador); esquecerLocal(); },
    salvarAgora: salvar,
    garantirSalvo: garantirSalvo,
    estado: estado,
    abrirPesquisa: function () { var a = document.querySelector('[data-reorg-abrir-pesquisa]'); if (a) { a.click(); } }
  };
})();
