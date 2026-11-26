/*
 * Central de Equipes: comunicação periódica (sinal), notificações, lotes que aparecem sozinhos,
 * pausa/saída com conferência do que não foi sincronizado, pedidos, problemas, Grupo 3 e Grupo 4.
 */
(function () {
  'use strict';
  var b = document.body;
  if (!b.hasAttribute('data-reorg-equipe')) { return; }
  var api = b.getAttribute('data-api');
  var csrf = b.getAttribute('data-csrf');
  var base = b.getAttribute('data-base') || '.';
  var lote = b.getAttribute('data-lote');
  var equipe = b.getAttribute('data-equipe');
  var assinatura = b.getAttribute('data-assinatura');
  var intervalo = Math.max(5, parseInt(b.getAttribute('data-intervalo'), 10) || 15) * 1000;
  var indicador = document.querySelector('[data-reorg-indicador]');
  var notifs = document.querySelector('[data-reorg-notifs]');
  var modal = document.querySelector('[data-reorg-modal]');
  var conteudoModal = document.querySelector('[data-reorg-modal-conteudo]');
  var vistas = {};
  var falhas = 0;

  function post(dados) {
    var corpo = new URLSearchParams();
    Object.keys(dados).forEach(function (k) {
      var v = dados[k];
      if (v === undefined || v === null) { return; }
      if (Array.isArray(v)) { v.forEach(function (x) { corpo.append(k + '[]', x); }); } else { corpo.append(k, v); }
    });
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: corpo })
      .then(function (r) { return r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor.' }; }); })
      .then(function (j) { if (j.encerrada) { encerrada(j.erro); } return j; });
  }

  function encerrada(msg) {
    abrirModal('<h2>Sessão encerrada</h2><p></p><p>Para continuar, entre de novo informando o nome. O administrador atribui os lotes novamente.</p>');
    conteudoModal.querySelector('p').textContent = msg || '';
    setTimeout(function () { window.location.href = base + '?encerrada=1'; }, 4000);
  }

  function formPendente() { return !!(window.REORG_FORM && window.REORG_FORM.pendente()); }

  function toast(texto, tipo) {
    var d = document.createElement('div');
    d.className = 'reorg-toast' + (tipo ? ' is-' + tipo : '');
    d.textContent = texto;
    document.body.appendChild(d);
    setTimeout(function () { d.remove(); }, 4500);
  }

  // ---------------------------------------------------------------- modal
  function abrirModal(html) {
    conteudoModal.innerHTML = html;
    modal.hidden = false;
    var f = conteudoModal.querySelector('input, select, textarea, button');
    if (f) { f.focus(); }
  }
  function fecharModal() { modal.hidden = true; conteudoModal.innerHTML = ''; }
  document.querySelector('[data-reorg-fechar]').addEventListener('click', fecharModal);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !modal.hidden) { fecharModal(); } });

  function modalFormulario(tpl, botao, enviar) {
    var t = document.querySelector('[data-reorg-tpl="' + tpl + '"]');
    abrirModal('<form class="reorg-form" novalidate></form>');
    var f = conteudoModal.querySelector('form');
    f.appendChild(t.content.cloneNode(true));
    var bt = document.createElement('button'); bt.type = 'submit'; bt.className = 'adm-button adm-button--primary'; bt.textContent = botao; f.appendChild(bt);
    var erro = document.createElement('p'); erro.className = 'adm-field__error'; f.appendChild(erro);
    f.addEventListener('submit', function (ev) {
      ev.preventDefault(); bt.disabled = true;
      var dados = {}; new FormData(f).forEach(function (v, k) { dados[k] = v; });
      enviar(dados).then(function (j) { bt.disabled = false; if (j && !j.ok) { erro.textContent = j.erro; } });
    });
    var primeiro = f.querySelector('input, select, textarea'); if (primeiro) { primeiro.focus(); }
  }

  function confirmar(texto, okRotulo) {
    return new Promise(function (resolve) {
      abrirModal('<p class="reorg-confirma"></p><div class="reorg-linha"><button type="button" class="adm-button adm-button--primary" data-sim></button><button type="button" class="adm-button" data-nao>Cancelar</button></div>');
      conteudoModal.querySelector('.reorg-confirma').textContent = texto;
      conteudoModal.querySelector('[data-sim]').textContent = okRotulo || 'Confirmar';
      conteudoModal.querySelector('[data-sim]').addEventListener('click', function () { fecharModal(); resolve(true); });
      conteudoModal.querySelector('[data-nao]').addEventListener('click', function () { fecharModal(); resolve(false); });
    });
  }

  // ---------------------------------------------------------------- sinal (a cada ~15 s)
  function registroAberto() { var m = document.querySelector('[data-reorg-registro]'); return m ? m.getAttribute('data-reorg-registro') : ''; }

  function sinal() {
    if (b.getAttribute('data-pausada') === '1') {
      return post({ acao: 'sinal' }).then(function () { falhas = 0; }).catch(function () { falhas++; });
    }
    return post({ acao: 'sinal', trabalhando: lote ? '1' : '0', registro: registroAberto() }).then(function (j) {
      if (!j.ok) { return; }
      falhas = 0;
      if (indicador) { indicador.textContent = 'Conectado'; indicador.setAttribute('data-estado', 'salvo'); }
      mostrarNotificacoes(j.notificacoes || []);
      if (j.assinatura && assinatura && j.assinatura !== assinatura && !formPendente() && modal.hidden) {
        window.location.reload(); // lotes atribuídos, retirados, pausados ou troca de equipe: aparecem sozinhos
      }
    }).catch(function () {
      falhas++;
      if (indicador) { indicador.textContent = 'Sem conexão'; indicador.setAttribute('data-estado', 'offline'); }
    });
  }

  function mostrarNotificacoes(lista) {
    lista.forEach(function (n) {
      if (vistas[n.id]) { return; }
      vistas[n.id] = true;
      var d = document.createElement('div');
      d.className = 'reorg-notif';
      var s = document.createElement('span'); s.textContent = n.texto;
      var bt = document.createElement('button'); bt.type = 'button'; bt.className = 'adm-button adm-button--sm'; bt.textContent = 'Ok';
      bt.addEventListener('click', function () { post({ acao: 'notificacoes_lidas', ids: [n.id] }); d.remove(); });
      d.appendChild(s); d.appendChild(bt);
      notifs.appendChild(d);
    });
  }

  sinal();
  setInterval(sinal, intervalo);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { sinal(); } });

  // ---------------------------------------------------------------- pausa, retomada e saída
  function aposSalvar() { return window.REORG_FORM && window.REORG_FORM.pendente() ? window.REORG_FORM.salvarAgora() : Promise.resolve(); }
  /** Só segue se tudo o que foi digitado já foi confirmado pelo servidor. */
  function exigirSalvo() {
    return aposSalvar().then(function () {
      if (formPendente()) { toast('Ainda há alterações não salvas (veja o indicador). Aguarde a confirmação e tente de novo; nada foi enviado.', 'erro'); return false; }
      return true;
    });
  }

  function pausar() {
    aposSalvar().then(function () {
      if (formPendente()) { toast('Ainda há alterações não sincronizadas. Aguarde a conexão antes de pausar.', 'erro'); return; }
      post({ acao: 'pausar' }).then(function (j) {
        if (!j.ok) { toast(j.erro, 'erro'); return; }
        if (window.REORG_FORM) { window.REORG_FORM.estado('pausado'); }
        if (indicador) { indicador.textContent = 'Atividade pausada'; indicador.setAttribute('data-estado', 'pausado'); }
        abrirModal('<h2>Atividade pausada</h2><p>Tudo está salvo. Progresso no momento da pausa:</p><p class="reorg-resumo-pausa"></p><button type="button" class="adm-button adm-button--primary" data-ok>Ok</button>');
        conteudoModal.querySelector('.reorg-resumo-pausa').textContent = j.resumo;
        conteudoModal.querySelector('[data-ok]').addEventListener('click', function () { window.location.href = base; });
      });
    });
  }
  var bPausar = document.querySelector('[data-reorg-pausar]');
  if (bPausar) { bPausar.addEventListener('click', pausar); }
  var bRetomar = document.querySelector('[data-reorg-retomar]');
  if (bRetomar) { bRetomar.addEventListener('click', function () { post({ acao: 'retomar' }).then(function () { window.location.href = base; }); }); }

  var bSair = document.querySelector('[data-reorg-sair]');
  if (bSair) {
    bSair.addEventListener('click', function () {
      aposSalvar().then(function () {
        var aviso = formPendente() ? 'ATENÇÃO: há alterações que ainda não chegaram ao servidor e serão perdidas se você sair agora. ' : '';
        return confirmar(aviso + 'Sair encerra esta sessão. Para voltar, será preciso informar o nome de novo e aguardar a atribuição do administrador. Sair?', 'Sair');
      }).then(function (sim) {
        if (!sim) { return; }
        post({ acao: 'sair' }).then(function (j) { if (window.REORG_FORM) { window.REORG_FORM.esquecer(); } window.location.href = (j && j.url) || base; });
      });
    });
  }

  // ---------------------------------------------------------------- pedidos e problemas
  document.querySelectorAll('[data-reorg-pedido]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      var tipo = bt.getAttribute('data-reorg-pedido');
      modalFormulario(tipo, 'Enviar pedido', function (dados) {
        dados.acao = 'solicitar'; dados.tipo = tipo;
        return post(dados).then(function (j) { if (j.ok) { fecharModal(); toast('Pedido enviado ao administrador.'); } return j; });
      });
    });
  });

  function informarProblema(extra) {
    modalFormulario('problema', 'Registrar problema', function (dados) {
      dados.acao = 'problema'; dados.lote = lote;
      Object.keys(extra || {}).forEach(function (k) { dados[k] = extra[k]; });
      return post(dados).then(function (j) { if (j.ok) { fecharModal(); toast('Problema registrado. O administrador foi avisado.'); if (equipe !== 'grupo1') { setTimeout(function () { window.location.reload(); }, 800); } } return j; });
    });
    if (extra && extra.tipoSugerido) { var s = conteudoModal.querySelector('[name=tipo]'); if (s) { s.value = extra.tipoSugerido; } }
  }
  document.addEventListener('click', function (ev) {
    var bt = ev.target.closest('[data-reorg-problema]');
    if (!bt) { return; }
    informarProblema({ registro: bt.getAttribute('data-registro') || '', exemplar: bt.getAttribute('data-exemplar') || '' });
  });

  document.querySelector('[data-reorg-ajuda]').addEventListener('click', function () {
    abrirModal(''); conteudoModal.appendChild(document.querySelector('[data-reorg-tpl="ajuda-atalhos"]').content.cloneNode(true));
  });
  var bInstr = document.querySelector('[data-reorg-instrucoes]');
  if (bInstr) {
    bInstr.addEventListener('click', function () {
      abrirModal(''); conteudoModal.appendChild(document.querySelector('[data-reorg-tpl="instrucoes"]').content.cloneNode(true));
    });
  }

  // ---------------------------------------------------------------- Grupo 1
  function novoRegistro() {
    var bt = document.querySelector('[data-reorg-novo]');
    if (!bt || bt.disabled) { return; }
    exigirSalvo().then(function (ok) {
      if (!ok) { return; }
      post({ acao: 'novo', lote: lote }).then(function (j) {
        if (!j.ok) { toast(j.erro, 'erro'); return; }
        window.location.href = j.url;
      });
    });
  }
  var bNovo = document.querySelector('[data-reorg-novo]');
  if (bNovo) { bNovo.addEventListener('click', novoRegistro); }

  document.querySelectorAll('[data-reorg-encaminhar]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      var g = bt.getAttribute('data-reorg-encaminhar');
      exigirSalvo().then(function (ok) { return ok ? post({ acao: 'previa_' + g, lote: lote }) : null; }).then(function (p) {
        if (!p) { return; }
        if (!p.ok) { toast(p.erro, 'erro'); return; }
        if (!p.exemplares) { toast(g === 'g2' ? 'Nenhum registro completo para encaminhar agora.' : 'Nenhum exemplar carimbado para encaminhar agora.', 'erro'); return; }
        var texto = g === 'g2'
          ? p.exemplares + ' exemplar(es) completos serão encaminhados para a revisão. ' + p.restantes + ' continuarão pendentes no levantamento (incompletos ou ainda não registrados).'
          : p.exemplares + ' exemplar(es) carimbados serão encaminhados para a conferência. ' + p.restantes + ' continuarão pendentes na carimbação.';
        return confirmar(texto, 'Encaminhar').then(function (sim) {
          if (!sim) { return; }
          return post({ acao: 'encaminhar_' + g, lote: lote }).then(function (j) {
            if (!j.ok) { toast(j.erro, 'erro'); return; }
            toast(j.exemplares + ' exemplar(es) encaminhados.'); setTimeout(function () { window.location.reload(); }, 700);
          });
        });
      });
    });
  });

  document.querySelectorAll('[data-reorg-descartar]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      confirmar('Descartar este registro? Use só se foi criado por engano ou em duplicidade.', 'Descartar').then(function (sim) {
        if (!sim) { return; }
        post({ acao: 'descartar', id: bt.getAttribute('data-reorg-descartar') }).then(function (j) {
          if (!j.ok) { toast(j.erro, 'erro'); return; }
          if (window.REORG_FORM) { window.REORG_FORM.esquecer(); }
          window.location.href = base + '?lote=' + lote;
        });
      });
    });
  });
  document.querySelectorAll('[data-reorg-sugerir]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      post({ acao: 'sugerir', id: registroAberto(), livro_id: bt.getAttribute('data-reorg-sugerir') }).then(function (j) { if (!j.ok) { toast(j.erro, 'erro'); return; } window.location.reload(); });
    });
  });
  // Ao trocar de registro, libera a reserva do atual (outra pessoa pode continuar).
  window.addEventListener('pagehide', function () {
    var reg = registroAberto();
    if (reg && navigator.sendBeacon) { navigator.sendBeacon(api, new URLSearchParams({ acao: 'liberar', id: reg, csrf: csrf })); }
  });

  // ---------------------------------------------------------------- Grupo 3
  document.querySelectorAll('[data-reorg-reservar]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      post({ acao: 'reservar', id: bt.getAttribute('data-reorg-reservar') }).then(function (j) { if (!j.ok) { toast(j.erro, 'erro'); } window.location.reload(); });
    });
  });
  document.querySelectorAll('[data-reorg-carimbar]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      bt.disabled = true;
      post({ acao: 'carimbar', id: bt.getAttribute('data-reorg-carimbar') }).then(function (j) {
        if (!j.ok) { bt.disabled = false; toast(j.erro, 'erro'); return; }
        toast(j.codigo + ' marcado como carimbado.');
        var linha = bt.closest('tr'); if (linha) { linha.classList.add('is-feito'); }
        setTimeout(function () { window.location.reload(); }, 600);
      });
    });
  });

  // ---------------------------------------------------------------- Grupo 4
  var fConf = document.querySelector('[data-reorg-conferir]');
  var resultado = document.querySelector('[data-reorg-resultado]');
  var TONS = { valido: 'green', ja_conferido: 'amber', sem_carimbo: 'amber', nao_encaminhado: 'amber', inexistente: 'red', formato: 'red', outro_lote: 'red', fora_reorganizacao: 'red' };

  function linhaDado(dl, rotulo, valor) {
    var dt = document.createElement('dt'); dt.textContent = rotulo;
    var dd = document.createElement('dd'); dd.textContent = valor || '—';
    var d = document.createElement('div'); d.appendChild(dt); d.appendChild(dd); dl.appendChild(d);
  }

  if (fConf) {
    var campoCodigo = fConf.querySelector('[name=codigo]');
    fConf.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var codigo = campoCodigo.value.trim();
      if (!codigo) { campoCodigo.focus(); return; }
      post({ acao: 'conferir', lote: lote, codigo: codigo }).then(function (j) {
        resultado.hidden = false; resultado.innerHTML = '';
        if (!j.ok) { var e = document.createElement('div'); e.className = 'adm-notice adm-notice--red'; e.textContent = j.erro; resultado.appendChild(e); return; }
        var msg = document.createElement('div'); msg.className = 'adm-notice adm-notice--' + (TONS[j.situacao] || 'amber'); msg.textContent = j.mensagem; resultado.appendChild(msg);
        if (j.exemplar) {
          var x = j.exemplar;
          var dl = document.createElement('dl'); dl.className = 'adm-meta adm-meta--two reorg-dados';
          linhaDado(dl, 'Código BDP', x.codigo_individual); linhaDado(dl, 'Título', x.titulo + (x.subtitulo ? ': ' + x.subtitulo : ''));
          linhaDado(dl, 'Autor(es)', x.autores); linhaDado(dl, 'Editora', x.editora); linhaDado(dl, 'Edição', x.edicao); linhaDado(dl, 'Ano', x.ano_publicacao);
          linhaDado(dl, 'CDD', x.cdd); linhaDado(dl, 'Conservação', x.conservacao); linhaDado(dl, 'Carimbado por', x.carimbado_nome);
          if (x.localizacao) { linhaDado(dl, 'Localização', x.localizacao); }
          resultado.appendChild(dl);
        }
        if (j.situacao === 'valido') {
          var f = document.createElement('form'); f.className = 'reorg-form';
          f.appendChild(document.querySelector('[data-reorg-tpl="confirmar"]').content.cloneNode(true));
          var linha = document.createElement('div'); linha.className = 'reorg-linha';
          var ok = document.createElement('button'); ok.type = 'submit'; ok.className = 'adm-button adm-button--primary'; ok.textContent = 'Confirmar conferência';
          var pr = document.createElement('button'); pr.type = 'button'; pr.className = 'adm-button'; pr.textContent = 'Informar problema (não corresponde)';
          linha.appendChild(ok); linha.appendChild(pr); f.appendChild(linha); resultado.appendChild(f);
          pr.addEventListener('click', function () { informarProblema({ exemplar: j.exemplar.id, tipoSugerido: 'codigo_livro_errado' }); });
          f.addEventListener('submit', function (e2) {
            e2.preventDefault(); ok.disabled = true;
            var d = { acao: 'confirmar', id: j.exemplar.id };
            new FormData(f).forEach(function (v, k) { d[k] = v; });
            post(d).then(function (r2) {
              ok.disabled = false;
              if (!r2.ok) { toast(r2.erro, 'erro'); return; }
              toast(r2.codigo + ' conferido' + (r2.organizado ? ' e organizado' : '') + '.');
              setTimeout(function () { window.location.reload(); }, 700);
            });
          });
          var primeiro = f.querySelector('input'); if (primeiro) { primeiro.focus(); }
        } else if (j.exemplar && ['ja_conferido', 'sem_carimbo', 'nao_encaminhado'].indexOf(j.situacao) >= 0) {
          var p2 = document.createElement('button'); p2.type = 'button'; p2.className = 'adm-button adm-button--sm'; p2.textContent = 'Informar problema';
          p2.addEventListener('click', function () { informarProblema({ exemplar: j.exemplar.id, tipoSugerido: j.situacao === 'ja_conferido' ? 'codigo_ja_conferido' : 'codigo_incorreto' }); });
          resultado.appendChild(p2);
        }
      });
    });
  }
  document.querySelectorAll('[data-reorg-organizar]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      var id = bt.getAttribute('data-reorg-organizar');
      var sel = document.querySelector('[data-reorg-prateleira="' + id + '"]');
      post({ acao: 'organizar', id: id, localizacao_id: sel ? sel.value : '' }).then(function (j) {
        if (!j.ok) { toast(j.erro, 'erro'); return; }
        toast(j.codigo + ' organizado na estante.'); setTimeout(function () { window.location.reload(); }, 600);
      });
    });
  });

  // ---------------------------------------------------------------- atalhos
  function mover(delta) {
    var itens = Array.prototype.slice.call(document.querySelectorAll('[data-reorg-item]'));
    if (!itens.length) { return; }
    var atual = itens.findIndex(function (el) { return el === document.activeElement || el.contains(document.activeElement); });
    if (atual < 0) { atual = itens.findIndex(function (el) { return el.closest('.is-ativo'); }); }
    var alvo = itens[Math.max(0, Math.min(itens.length - 1, atual + delta))];
    var foco = alvo.matches('a, button') ? alvo : alvo.querySelector('a, button') || alvo;
    if (!foco.hasAttribute('tabindex') && !foco.matches('a, button')) { foco.setAttribute('tabindex', '-1'); }
    foco.focus();
    alvo.scrollIntoView({ block: 'nearest' });
  }
  document.addEventListener('keydown', function (ev) {
    if (!ev.altKey || ev.ctrlKey || ev.metaKey) { return; }
    var k = ev.key.toLowerCase();
    var feito = true;
    if (k === 'n') { novoRegistro(); }
    else if (k === 'j') { mover(1); }
    else if (k === 'k') { mover(-1); }
    else if (k === 'o') { if (fConf) { fConf.querySelector('[name=codigo]').focus(); } else if (window.REORG_FORM) { window.REORG_FORM.abrirPesquisa(); } }
    else if (k === 'p') { if (bPausar) { pausar(); } }
    else if (k === 'h') { document.querySelector('[data-reorg-ajuda]').click(); }
    else if (k === 'enter') { var av = document.querySelector('[data-reorg-avancar]'); if (av) { av.click(); } }
    else { feito = false; }
    if (feito) { ev.preventDefault(); }
  });
})();
