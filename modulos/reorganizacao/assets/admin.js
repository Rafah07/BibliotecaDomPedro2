/* Central administrativa da reorganização: contadores ao vivo, notificações, confirmações e seleção. */
(function () {
  'use strict';
  var raiz = document.querySelector('[data-reorg-admin]');
  if (!raiz) { return; }
  var api = raiz.getAttribute('data-api');

  // Confirmação antes de ações com efeito em vários registros (códigos BDP, encaminhamentos, descarte).
  document.addEventListener('click', function (ev) {
    var bt = ev.target.closest('[data-reorg-confirmar]');
    if (bt && bt.hasAttribute('data-reorg-salvar-antes')) { return; } // registro.js confirma e salva antes
    if (bt && !window.confirm(bt.getAttribute('data-reorg-confirmar'))) { ev.preventDefault(); }
  });

  // Selecionar todos e contagem dos marcados (revisão do Grupo 2).
  document.querySelectorAll('[data-reorg-selecao]').forEach(function (f) {
    var info = f.querySelector('[data-reorg-marcados]');
    function contar() {
      if (!info) { return; }
      var n = f.querySelectorAll('input[name="ids[]"]:checked').length;
      info.textContent = n ? n + ' registro(s) marcado(s).' : 'Nenhum marcado.';
    }
    f.addEventListener('change', function (ev) {
      if (ev.target.matches('[data-reorg-todos]')) {
        f.querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = ev.target.checked; });
      }
      contar();
    });
    contar();
  });

  // Criar lote: resumo ao vivo (identificação, quantidade, responsável, participantes por grupo).
  var novo = document.querySelector('[data-reorg-novo-lote]');
  if (novo) {
    var resumo = novo.querySelector('[data-reorg-resumo]');
    var semEquipe = novo.querySelector('[data-r="sem"]');
    var atualizarResumo = function () {
      ['nome', 'quantidade', 'observacoes'].forEach(function (k) {
        var el = novo.querySelector('[data-resumo="' + k + '"]');
        resumo.querySelector('[data-r="' + k + '"]').textContent = el && el.value.trim() ? el.value.trim() : '—';
      });
      var resp = novo.querySelector('[data-resumo="responsavel"]');
      var nomeResp = resp && resp.selectedOptions[0] ? resp.selectedOptions[0].textContent : '';
      resumo.querySelector('[data-r="responsavel"]').textContent = nomeResp || '—';
      var fixo = novo.querySelector('[data-resumo-responsavel]'); if (fixo) { fixo.textContent = nomeResp; }
      var vazios = [];
      novo.querySelectorAll('[data-grupo]').forEach(function (g) {
        var eq = g.getAttribute('data-grupo');
        var nomes = Array.prototype.map.call(g.querySelectorAll('input[type=checkbox]:checked'), function (c) { return c.getAttribute('data-nome'); });
        g.querySelector('[data-contagem]').textContent = nomes.length;
        resumo.querySelector('[data-r="' + eq + '"]').textContent = nomes.length ? nomes.join(', ') : '—';
        if (!nomes.length && eq !== 'grupo2') { vazios.push(g.querySelector('legend').firstChild.textContent.split(' — ')[0].trim()); }
      });
      semEquipe.hidden = !vazios.length;
      semEquipe.textContent = vazios.length ? 'Ainda sem participantes: ' + vazios.join(', ') + '. O lote pode ser criado assim; atribua depois na aba Participantes do lote.' : '';
    };
    novo.addEventListener('input', atualizarResumo);
    novo.addEventListener('change', atualizarResumo);
    atualizarResumo();
  }

  // Filtros que se aplicam ao escolher (sem script embutido no HTML).
  document.querySelectorAll('[data-reorg-auto-submit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
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
