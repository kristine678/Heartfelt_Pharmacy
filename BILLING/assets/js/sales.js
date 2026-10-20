/**
 * TASK 1 — Sales page behaviour.
 *
 * Responsibilities:
 *   * render the totals, the trend chart, the category donut + legend and the top-selling table
 *     from a salesReport payload (the SAME shape sales.php renders on first paint);
 *   * switch Daily / Monthly / Yearly and the date / month / year picker by refetching
 *     api/sales.php (never by pulling all rows into the browser);
 *   * toggle the donut between quantity and revenue;
 *   * show loading, empty and error states, and keep working when the chart library is not
 *     reachable (numbers and tables still render).
 */
(() => {
    const panel = document.getElementById('sales-panel');
    if (!panel) {
        return;
    }

    const endpoint = window.__salesEndpoint || 'api/sales.php';
    const status = document.getElementById('sales-status');
    const emptyState = document.getElementById('sales-empty');
    const rangeLabel = document.getElementById('sales-range-label');
    const previousLabel = document.getElementById('sales-previous-label');
    const applyButton = document.getElementById('sales-apply');
    const topBody = document.getElementById('sales-top-body');
    const topEmpty = document.getElementById('sales-top-empty');
    const legend = document.getElementById('sales-legend');
    const trendTitle = document.getElementById('sales-trend-title');
    const trendFallback = document.getElementById('sales-trend-fallback');
    const categoryFallback = document.getElementById('sales-category-fallback');

    // Pink-based palette (mirrors the --pink-* design tokens) with enough separation between
    // neighbours that categories stay distinguishable on the light card background.
    const PINK_PALETTE = ['#ff2e8a', '#b8095a', '#ff85bd', '#d946a8', '#e6136e', '#ff5ca3', '#6b0836', '#f4b6c2', '#c2255c', '#ffadd2'];

    const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const compactPeso = (value) => {
        const amount = Number(value) || 0;
        if (Math.abs(amount) >= 1000) {
            return '₱' + (amount / 1000).toFixed(amount % 1000 === 0 ? 0 : 1) + 'k';
        }
        return '₱' + amount.toFixed(0);
    };
    const formatMoney = (value) => peso.format(Number(value) || 0);
    const formatCount = (value) => new Intl.NumberFormat('en-PH').format(Number(value) || 0);

    let period = window.__salesReport?.period || 'daily';
    let metric = 'quantity';
    let report = window.__salesReport || null;
    let trendChart = null;
    let categoryChart = null;
    let requestInFlight = false;

    const chartsAvailable = () => typeof window.Chart === 'function';

    function setStatus(message, kind = 'info') {
        if (!status) {
            return;
        }
        if (!message) {
            status.hidden = true;
            status.textContent = '';
            status.className = 'sales-status';
            return;
        }
        status.hidden = false;
        status.className = 'sales-status ' + kind;
        status.textContent = message;
    }

    function setLoading(isLoading) {
        panel.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        panel.classList.toggle('is-loading', isLoading);
        if (applyButton) {
            applyButton.disabled = isLoading;
        }
        if (isLoading) {
            setStatus('Loading sales…', 'info');
        }
    }

    function changeBadge(change, previousRangeLabel) {
        if (change === null || change === undefined) {
            return '<span class="sales-trend neutral" title="No sales in ' + (previousRangeLabel || 'the previous period') + ' to compare with">— no prior sales</span>';
        }
        const value = Number(change);
        const direction = value > 0 ? 'up' : (value < 0 ? 'down' : 'flat');
        const arrow = value > 0 ? '▲' : (value < 0 ? '▼' : '■');
        const sign = value > 0 ? '+' : '';
        return '<span class="sales-trend ' + direction + '" title="vs ' + (previousRangeLabel || 'the previous period') + '">' +
            arrow + ' ' + sign + value.toFixed(1) + '%</span>';
    }

    function renderTotals(data) {
        const totals = data.totals || {};
        const change = data.change || {};
        const previousRangeLabel = data.previous_range?.label || '';
        const map = [
            ['revenue', formatMoney(totals.revenue)],
            ['orders', formatCount(totals.orders)],
            ['items', formatCount(totals.items)],
            ['average', formatMoney(totals.average)]
        ];
        map.forEach(([key, text]) => {
            const valueNode = document.getElementById('sales-kpi-' + key);
            const changeNode = document.getElementById('sales-kpi-' + key + '-change');
            if (valueNode) {
                valueNode.textContent = text;
            }
            if (changeNode) {
                changeNode.innerHTML = changeBadge(change[key], previousRangeLabel);
            }
        });
    }

    function renderTrend(data) {
        const series = data.series || { labels: [], revenue: [], orders: [] };
        if (trendTitle) {
            trendTitle.textContent = data.period === 'daily'
                ? 'Sales by hour'
                : (data.period === 'monthly' ? 'Sales by day of month' : 'Sales by month');
        }

        if (!chartsAvailable()) {
            if (trendFallback) {
                trendFallback.hidden = false;
                trendFallback.textContent = 'Chart library unavailable — totals and the table below are still accurate.';
            }
            return;
        }
        if (trendFallback) {
            trendFallback.hidden = true;
        }

        const canvas = document.getElementById('sales-trend-chart');
        if (!canvas) {
            return;
        }
        const existing = window.Chart.getChart ? window.Chart.getChart(canvas) : null;
        if (existing) {
            existing.destroy();
        }

        trendChart = new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: series.labels,
                datasets: [{
                    label: 'Sales',
                    data: series.revenue,
                    backgroundColor: 'rgba(255, 46, 138, 0.72)',
                    hoverBackgroundColor: '#e6136e',
                    borderColor: '#b8095a',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 38
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(107, 8, 54, 0.94)',
                        titleColor: '#ffd6e8',
                        bodyColor: '#ffffff',
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            title: (items) => items[0]?.label ?? '',
                            label: (item) => {
                                const orders = Number(series.orders?.[item.dataIndex] || 0);
                                return [
                                    'Sales: ' + formatMoney(item.parsed.y),
                                    'Orders: ' + formatCount(orders)
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#7d5a72', maxRotation: 0, autoSkipPadding: 8 }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 133, 179, 0.25)' },
                        ticks: {
                            color: '#7d5a72',
                            callback: (value) => compactPeso(value)
                        }
                    }
                }
            }
        });
    }

    function categoryValues(data) {
        const rows = data.categories || [];
        return rows.map((row) => metric === 'revenue' ? Number(row.revenue) : Number(row.quantity));
    }

    function renderCategories(data) {
        const rows = data.categories || [];
        const values = categoryValues(data);
        const total = values.reduce((sum, value) => sum + value, 0);
        const colors = rows.map((row, index) => PINK_PALETTE[index % PINK_PALETTE.length]);

        if (legend) {
            legend.innerHTML = rows.map((row, index) => {
                const share = total > 0 ? (values[index] / total) * 100 : 0;
                return '<li class="sales-legend-item">' +
                    '<span class="sales-swatch" style="background:' + colors[index] + '"></span>' +
                    '<span class="sales-legend-name">' + escapeHtml(row.category) + '</span>' +
                    '<span class="sales-legend-share">' + share.toFixed(1) + '%</span>' +
                    '<span class="sales-legend-detail">' + formatCount(row.quantity) + ' sold · ' + formatMoney(row.revenue) + '</span>' +
                '</li>';
            }).join('');
        }

        if (!chartsAvailable()) {
            if (categoryFallback) {
                categoryFallback.hidden = false;
                categoryFallback.textContent = rows.length
                    ? 'Chart library unavailable — the category breakdown is listed below.'
                    : 'Chart library unavailable.';
            }
            return;
        }
        if (categoryFallback) {
            categoryFallback.hidden = true;
        }

        const canvas = document.getElementById('sales-category-chart');
        if (!canvas) {
            return;
        }
        const existing = window.Chart.getChart ? window.Chart.getChart(canvas) : null;
        if (existing) {
            existing.destroy();
        }

        categoryChart = new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: rows.map((row) => row.category),
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    // The HTML legend under the canvas carries the percentages and counts.
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(107, 8, 54, 0.94)',
                        titleColor: '#ffd6e8',
                        bodyColor: '#ffffff',
                        padding: 10,
                        callbacks: {
                            label: (item) => {
                                const row = rows[item.dataIndex] || {};
                                const share = total > 0 ? ((Number(item.parsed) / total) * 100).toFixed(1) : '0.0';
                                return [
                                    formatCount(row.quantity) + ' sold (' + share + '%)',
                                    formatMoney(row.revenue),
                                    row.category
                                ];
                            }
                        }
                    }
                }
            }
        });
    }

    function renderTopMedicines(data) {
        if (!topBody) {
            return;
        }
        const rows = data.top_medicines || [];
        if (topEmpty) {
            topEmpty.hidden = rows.length > 0;
        }
        topBody.innerHTML = rows.map((row) => '<tr>' +
            '<td>' + escapeHtml(row.name) + '</td>' +
            '<td><span class="pill">' + escapeHtml(row.category) + '</span></td>' +
            '<td class="num">' + formatCount(row.quantity) + '</td>' +
            '<td class="num">' + formatMoney(row.revenue) + '</td>' +
        '</tr>').join('');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }

    function render(data) {
        report = data;
        period = data.period || period;
        if (rangeLabel) {
            rangeLabel.textContent = data.range?.label || '';
        }
        if (previousLabel) {
            previousLabel.textContent = data.previous_range?.label || '';
        }
        const hasSales = Boolean(data.has_sales);
        if (emptyState) {
            emptyState.hidden = hasSales;
        }
        document.querySelectorAll('.sales-chart-card, .sales-table-card').forEach((card) => {
            card.classList.toggle('sales-card-hidden', !hasSales);
        });
        renderTotals(data);
        renderTrend(data);
        renderCategories(data);
        renderTopMedicines(data);
        syncPickers(data);
    }

    function syncPickers(data) {
        const input = data.input || {};
        const dateInput = document.getElementById('sales-date');
        const monthInput = document.getElementById('sales-month');
        const yearInput = document.getElementById('sales-year');
        if (dateInput && input.date) {
            dateInput.value = input.date;
        }
        if (monthInput && input.month) {
            monthInput.value = input.month;
        }
        if (yearInput && input.year) {
            yearInput.value = input.year;
        }
    }

    function syncTabs() {
        document.querySelectorAll('.sales-tab').forEach((tab) => {
            const isActive = tab.dataset.period === period;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('aria-controls', 'sales-panel');
        });
        document.querySelectorAll('.sales-picker').forEach((picker) => {
            picker.hidden = picker.dataset.picker !== period;
        });
        const activeTab = document.getElementById('sales-tab-' + period);
        if (activeTab && panel) {
            panel.setAttribute('aria-labelledby', activeTab.id);
        }
    }

    function currentQuery() {
        const params = new URLSearchParams({ period });
        const dateValue = document.getElementById('sales-date')?.value;
        const monthValue = document.getElementById('sales-month')?.value;
        const yearValue = document.getElementById('sales-year')?.value;
        if (period === 'daily' && dateValue) {
            params.set('date', dateValue);
        } else if (period === 'monthly' && monthValue) {
            params.set('month', monthValue);
        } else if (period === 'yearly' && yearValue) {
            params.set('year', yearValue);
        }
        return params;
    }

    async function loadSales() {
        if (requestInFlight) {
            return;
        }
        requestInFlight = true;
        const params = currentQuery();
        setLoading(true);
        try {
            const response = await fetch(endpoint + '?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.ok === false) {
                const message = payload?.error || 'Could not load the sales figures.';
                setStatus(message + ' Please try again.', 'error');
                return;
            }
            render(payload);
            setStatus('');
            // Keep the URL shareable/bookmarkable without a reload.
            const url = new URL(window.location.href);
            url.search = params.toString();
            window.history.replaceState({}, '', url);
        } catch (error) {
            setStatus('Could not reach the server. Check your connection and try again.', 'error');
        } finally {
            requestInFlight = false;
            setLoading(false);
        }
    }

    document.querySelectorAll('.sales-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            if (tab.dataset.period === period) {
                return;
            }
            period = tab.dataset.period;
            syncTabs();
            loadSales();
        });
    });

    ['sales-date', 'sales-month', 'sales-year'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', loadSales);
    });
    applyButton?.addEventListener('click', loadSales);

    document.querySelectorAll('#sales-metric-toggle .chip').forEach((chip) => {
        chip.addEventListener('click', () => {
            metric = chip.dataset.metric || 'quantity';
            document.querySelectorAll('#sales-metric-toggle .chip').forEach((other) => {
                const isActive = other === chip;
                other.classList.toggle('active', isActive);
                other.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            if (report) {
                renderCategories(report);
            }
        });
    });

    syncTabs();
    if (report) {
        render(report);
    }
    if (!chartsAvailable()) {
        setStatus('Chart library could not be loaded — figures and tables are still shown.', 'warning');
    }
})();
