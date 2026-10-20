// ONE script for every inventory view (Pharmacy page + POS Inventory Dashboard).
//
//   1. Live search / status / sort filtering, client-side, on the server-rendered table.
//   2. Automatic refresh from the shared endpoint after a sale:
//        - the POS writes localStorage 'medicines' on a successful charge, which the browser
//          delivers to every other open tab as a 'storage' event (and as 'pinkStorageChanged'
//          in the tab that charged);
//        - the tab also refreshes when it becomes visible again and on a slow 60s poll as a
//          safety net, so the numbers cannot drift while the screen sits open.
//      Cards and rows are patched in place; if the set of batches itself changed, the page reloads
//      once so newly added/removed stock appears instantly.
(() => {
    const containers = [...document.querySelectorAll('[data-inventory-view]')];
    if (!containers.length) return;

    const REFRESH_INTERVAL_MS = 60000;
    const STATUS_CLASSES = ['in-stock', 'low', 'out', 'expiring', 'expired'];
    const RANK = { expired: 0, expiring: 1, out: 2, low: 3, 'in-stock': 4 };

    containers.forEach((container) => {
        const search = container.querySelector('[data-inv-search]');
        const statusFilter = container.querySelector('[data-inv-status]');
        const sortSelect = container.querySelector('[data-inv-sort]');
        const countEl = container.querySelector('[data-inv-count]');
        const table = container.querySelector('#invTable');
        const endpoint = container.dataset.inventoryEndpoint;

        const rows = () => [...container.querySelectorAll('#invTable tbody tr')];

        function applyFilters() {
            if (!table) return;
            const query = (search && search.value ? search.value : '').trim().toLowerCase();
            const wanted = statusFilter ? statusFilter.value : 'all';
            const sort = sortSelect ? sortSelect.value : 'attention';
            const body = table.querySelector('tbody');

            let visible = 0;
            rows().forEach((row) => {
                const matchesQuery = !query || (row.dataset.search || '').includes(query);
                // "Needs attention" statuses also match their parent group, so filtering by
                // "Low" shows exactly the low rows and nothing else.
                const matchesStatus = wanted === 'all' || row.dataset.status === wanted;
                const show = matchesQuery && matchesStatus;
                row.hidden = !show;
                if (show) visible++;
            });

            if (sort !== 'attention' && body) {
                const sorted = rows().sort((a, b) => {
                    if (sort === 'name') {
                        return (a.dataset.name || a.textContent).localeCompare(b.dataset.name || b.textContent);
                    }
                    // expiry: soonest first, empty dates last.
                    const ea = a.dataset.expiry || '9999-12-31';
                    const eb = b.dataset.expiry || '9999-12-31';
                    return ea.localeCompare(eb);
                });
                sorted.forEach((row) => body.appendChild(row));
            } else if (body) {
                const byRank = rows().sort((a, b) => Number(a.dataset.rank || 9) - Number(b.dataset.rank || 9));
                byRank.forEach((row) => body.appendChild(row));
            }

            if (countEl) {
                countEl.textContent = `${visible} of ${rows().length} batches`;
            }
        }

        if (search) search.addEventListener('input', applyFilters);
        if (statusFilter) statusFilter.addEventListener('change', applyFilters);
        if (sortSelect) sortSelect.addEventListener('change', applyFilters);
        applyFilters();

        // ---- refresh from the shared endpoint -------------------------------------------------
        function patch(view, payload) {
            const summary = payload.summary || {};
            Object.entries(summary).forEach(([key, value]) => {
                const target = view.querySelector(`[data-inv-value="${key}"]`);
                if (target) {
                    const next = key === 'total_units' ? Number(value).toLocaleString() : String(value);
                    if (target.textContent.trim() !== next) target.textContent = next;
                }
            });

            const rendered = new Set(rows().map((row) => row.dataset.invId));
            const incoming = new Set((payload.rows || []).map((row) => String(row.id)));
            const sameBatches = rendered.size === incoming.size
                && [...incoming].every((id) => rendered.has(id));
            if (!sameBatches) {
                // A batch appeared or disappeared (new stock / deleted medicine): one reload shows it.
                window.location.reload();
                return;
            }

            (payload.rows || []).forEach((row) => {
                const target = view.querySelector(`tr[data-inv-id="${row.id}"]`);
                if (!target) return;
                const qtyCell = target.querySelector('[data-inv-qty]');
                if (qtyCell && qtyCell.textContent.trim() !== String(row.quantity)) {
                    qtyCell.textContent = String(row.quantity);
                }
                const badge = target.querySelector('[data-inv-badge]');
                if (badge) {
                    if (badge.textContent.trim() !== row.status_label) badge.textContent = row.status_label;
                    STATUS_CLASSES.forEach((cls) => badge.classList.remove(cls));
                    badge.classList.add(row.status_code);
                }
                STATUS_CLASSES.forEach((cls) => target.classList.remove(`inv-row-${cls}`));
                target.classList.add(`inv-row-${row.status_code}`);
                target.dataset.status = row.status_code;
                target.dataset.rank = String(row.rank);
                target.dataset.expiry = row.expiry || '';
            });

            const stamp = view.querySelector('[data-inv-updated-at]');
            if (stamp && payload.generated_label) stamp.textContent = payload.generated_label;
            applyFilters();
        }

        let inFlight = false;
        function refresh() {
            if (!endpoint || inFlight || document.hidden) return;
            inFlight = true;
            fetch(endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : null))
                .then((payload) => {
                    if (!payload || !payload.ok) return;
                    containers.forEach((view) => patch(view, payload));
                })
                .catch(() => { /* offline / expired session: keep the current view, try again later */ })
                .finally(() => { inFlight = false; });
        }

        // After a POS charge: same tab (custom event) and every other tab (native storage event).
        window.addEventListener('pinkStorageChanged', (event) => {
            const key = event && event.detail ? event.detail.key : '';
            if (!key || key === 'medicines' || key === 'orders') refresh();
        });
        window.addEventListener('storage', (event) => {
            if (!event.key || event.key === 'medicines' || event.key === 'orders') refresh();
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
        window.setInterval(refresh, REFRESH_INTERVAL_MS);
    });
})();
