/* Central administrativa da reorganização: contadores ao vivo, notificações, confirmações e seleção. */
(function () {
  'use strict';
  var raiz = document.querySelector('[data-reorg-admin]');
  if (!raiz) { return; }
  var api = raiz.getAttribute('data-api');

  // Confirmação antes de ações com efeito em vários registros (códigos BDP, encaminhamentos, descarte).
  document.addEventListener('click', function (ev) {
    var bt = ev.target.closest('[data-reorg-confirmar]');
    if (bt && !window.confirm(bt.getAttribute('data-reorg-confirmar'))) { ev.preventDefault(); }
  });

  // Selecionar todos (revisão do Grupo 2).
  document.querySelectorAll('[data-reorg-todos]').forEach(function (chk) {
    chk.addEventListener('change', function () {
      chk.closest('form').querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = chk.checked; });
    });
  });

  // Contadores do lote atualizados sozinhos (a cada 15 s).
  var painel = document.querySelector('[data-reorg-contadores]');
  var sino = document.querySelector('[data-reorg-naolidas]');
  function atualizar() {
    var url = api + '?acao=' + (painel ? 'contadores&lote=' + painel.getAttribute('data-reorg-contadores') : 'notificacoes');
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { return; }
      if (painel && j.contadores) {
        painel.querySelectorAll('[data-k]').forEach(function (el) {
          var v = j.contadores[el.getAttribute('data-k')];
          if (v !== undefined && String(v) !== el.textContent) { el.textContent = v; el.classList.add('is-mudou'); setTimeout(function () { el.classList.remove('is-mudou'); }, 1500); }
        });
      }
      if (sino) { sino.textContent = j.nao_lidas; sino.hidden = !j.nao_lidas; }
    }).catch(function () { /* sem conexão: tenta na próxima */ });
  }
  setInterval(atualizar, 15000);

  // Páginas de acompanhamento (participantes) recarregam sozinhas, exceto com algo sendo digitado.
  var auto = document.querySelector('[data-reorg-auto-refresh]');
  if (auto) {
    var seg = parseInt(auto.getAttribute('data-reorg-auto-refresh'), 10) || 20;
    setInterval(function () {
      var ativo = document.activeElement;
      if (ativo && (ativo.tagName === 'INPUT' || ativo.tagName === 'SELECT' || ativo.tagName === 'TEXTAREA')) { return; }
      window.location.reload();
    }, seg * 1000);
  }
})();
