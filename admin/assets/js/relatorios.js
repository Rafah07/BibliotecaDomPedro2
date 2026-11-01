/* Central de relatórios: datas do período personalizado e impressão. */
(function () {
    // Período: as datas só são necessárias no "Período personalizado". Impressão da tela.
document.querySelectorAll('[data-periodo]').forEach(function (box) {
    var sel = box.querySelector('[data-periodo-select]');
    function atualizar() {
        box.querySelectorAll('[data-periodo-datas]').forEach(function (el) { el.hidden = sel.value !== 'personalizado'; });
    }
    sel.addEventListener('change', atualizar);
    box.querySelectorAll('input[type=date]').forEach(function (i) { i.addEventListener('change', function () { sel.value = 'personalizado'; atualizar(); }); });
    atualizar();
});
document.querySelectorAll('[data-imprimir]').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('.rel-chart__data').forEach(function (d) { d.open = true; });
        window.print();
    });
});
})();
