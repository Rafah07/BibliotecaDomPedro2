// Gráficos do dashboard. Os dados vêm de admin/includes/dados-demo.php (bloco #dashboard-data),
// assim a troca por consultas reais acontece em um único lugar.
(function () {
    const dataEl = document.getElementById('dashboard-data');
    if (!dataEl) return;
    const data = JSON.parse(dataEl.textContent);

    const barsBox = document.querySelector('[data-chart="emprestimos"]');
    const donutBox = document.querySelector('[data-chart="categorias"]');
    const summary = document.querySelector('[data-chart-summary]');
    const periodButtons = document.querySelectorAll('[data-period]');
    const formatNumber = (n) => n.toLocaleString('pt-BR');

    function fail(box) {
        if (!box) return;
        box.classList.remove('is-loading');
        box.classList.add('is-failed');
        box.querySelector('.adm-chart__fallback').hidden = false;
    }

    function ready(box) {
        box?.classList.remove('is-loading');
    }

    if (typeof window.Chart === 'undefined') {
        fail(barsBox);
        fail(donutBox);
        return;
    }

    Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif";
    Chart.defaults.color = '#64748B';

    const tooltipStyle = {
        backgroundColor: '#172033',
        titleColor: '#FFFFFF',
        bodyColor: '#E2E8F0',
        padding: 10,
        cornerRadius: 8,
        displayColors: false,
        titleFont: { weight: '600' },
    };

    function periodData(months) {
        const all = data.emprestimos.meses12;
        return all.slice(-months);
    }

    // ---------- Gráfico 1: empréstimos por mês ----------
    let barChart = null;
    function renderBars(months) {
        const rows = periodData(months);
        const labels = rows.map((r) => r.mes.split('/')[0] + (months > 6 ? '/' + r.mes.split('/')[1] : ''));
        const values = rows.map((r) => r.total);
        const last = values.length - 1;

        if (summary) {
            const total = values.reduce((a, b) => a + b, 0);
            summary.innerHTML = `Total no período: <strong>${formatNumber(total)}</strong> empréstimos · dados demonstrativos`;
        }

        const colors = values.map((_, i) => (i === last ? '#2457C5' : '#A9C1F2'));
        const hoverColors = values.map((_, i) => (i === last ? '#1E4697' : '#2E73FF'));

        if (barChart) {
            barChart.data.labels = labels;
            barChart.data.datasets[0].data = values;
            barChart.data.datasets[0].backgroundColor = colors;
            barChart.data.datasets[0].hoverBackgroundColor = hoverColors;
            barChart.data.datasets[0].fullLabels = rows.map((r) => r.mes);
            barChart.update();
            return;
        }

        barChart = new Chart(document.getElementById('grafico-emprestimos'), {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Empréstimos',
                    data: values,
                    fullLabels: rows.map((r) => r.mes),
                    backgroundColor: colors,
                    hoverBackgroundColor: hoverColors,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 44,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 450 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...tooltipStyle,
                        callbacks: {
                            title: (items) => items[0].dataset.fullLabels[items[0].dataIndex].replace('/', ' de 20'),
                            label: (item) => `${formatNumber(item.parsed.y)} empréstimos`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#EEF2F7' },
                        ticks: { font: { size: 11 }, maxTicksLimit: 5, precision: 0 },
                    },
                },
            },
        });
    }

    periodButtons.forEach((button) => {
        button.addEventListener('click', () => {
            periodButtons.forEach((b) => {
                const active = b === button;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-pressed', String(active));
            });
            renderBars(Number(button.dataset.period));
        });
    });

    // ---------- Gráfico 2: acervo por categoria ----------
    function renderDonut() {
        const cats = data.categorias;
        new Chart(document.getElementById('grafico-categorias'), {
            type: 'doughnut',
            data: {
                labels: cats.map((c) => c.categoria),
                datasets: [{
                    data: cats.map((c) => c.percentual),
                    backgroundColor: cats.map((c) => c.cor),
                    borderColor: '#FFFFFF',
                    borderWidth: 2,
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 1,
                cutout: '66%',
                layout: { padding: 6 },
                animation: { duration: 500 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...tooltipStyle,
                        callbacks: { label: (item) => `${item.label}: ${item.parsed}% do acervo` },
                    },
                },
            },
        });
    }

    // Pequeno atraso para exibir o estado de carregamento demonstrativo.
    setTimeout(() => {
        try {
            renderBars(6);
            ready(barsBox);
        } catch (e) {
            fail(barsBox);
        }
        try {
            renderDonut();
            ready(donutBox);
        } catch (e) {
            fail(donutBox);
        }
    }, 350);
})();
