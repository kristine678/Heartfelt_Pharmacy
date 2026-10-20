// Medicine inventory alert pop-up.
// - Polls api/alerts.php on load and every 5 minutes (no page reload).
// - Auto-opens when something needs attention, unless THIS signature was dismissed in
//   this browser session; a new/changed alert set (new signature) opens again.
// - Bell reopens the dialog at any time; Esc closes; focus is trapped and restored.
(function () {
    'use strict';

    var ENDPOINT = 'api/alerts.php';
    var POLL_MS = 5 * 60 * 1000;      // refresh every 5 minutes
    var SNOOZE_MS = 5 * 60 * 1000;    // "Remind me later" = one refresh cycle
    var K_DISMISS = 'hpMedAlert:dismissed';
    var K_SNOOZE = 'hpMedAlert:snoozed';

    // Two alert families (see api/alerts.php): expiry is per BATCH, stock is per MEDICINE
    // (totalled across its non-expired batches). Sections run in urgency order: expired,
    // then soonest expiry, then out of stock, then low stock.
    var SECTIONS = [
        { title: 'Expiring / Expired Batches', groups: ['expired', 'expiring_soon'] },
        { title: 'Out of Stock', groups: ['out_of_stock'] },
        { title: 'Low Stock', groups: ['low_stock'] }
    ];
    var FLAG_META = {
        expired: { label: 'Expired', tag: 'tag-red' },
        expiring_soon: { label: 'Expiring soon', tag: 'tag-orange' },
        out_of_stock: { label: 'Out of stock', tag: 'tag-red' },
        low_stock: { label: 'Low stock', tag: 'tag-yellow' }
    };

    var overlay, card, body, sub, bell, badge;
    var lastData = null;
    var lastFocused = null;
    var lastRefreshAt = Date.now();
    var isOpen = false;

    function esc(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

    function daysLabel(item) {
        if (item.days_remaining === null || item.days_remaining === undefined) return 'No expiry date';
        if (item.days_remaining < 0) return plural(-item.days_remaining, 'day') + ' ago';
        if (item.days_remaining === 0) return 'Expires today';
        return plural(item.days_remaining, 'day') + ' left';
    }

    function rowHtml(item, sev) {
        var tags = '';
        Object.keys(FLAG_META).forEach(function (flag) {
            if (item.flags && item.flags[flag] && FLAG_META[flag].label !== item.status) {
                tags += '<span class="med-alert-tag ' + FLAG_META[flag].tag + '">' + esc(FLAG_META[flag].label) + '</span>';
            }
        });

        // Medicine rows: stock is the TOTAL across that medicine's non-expired batches.
        // Batch rows: the exact batch that is expiring, with its own quantity and date.
        var isMedicineRow = item.kind === 'medicine';
        var liveBatches = item.batch_count || 0;
        var meta = isMedicineRow
            ? 'Total in stock: <b>' + item.total_quantity + '</b>' +
                ' <span class="med-alert-batch">across ' + liveBatches + ' non-expired ' +
                (liveBatches === 1 ? 'batch' : 'batches') + '</span>'
            : 'Qty in batch: <b>' + item.quantity + '</b>' +
                ' &middot; Expires: <b>' + esc(item.expiration_date || '—') + '</b>' +
                ' &middot; <b>' + esc(daysLabel(item)) + '</b>';

        return '<li class="med-alert-row sev-' + sev + '">' +
            '<span class="med-alert-chip">' + esc(item.status) + '</span>' +
            '<div class="med-alert-info">' +
                '<span class="med-alert-name">' + esc(item.name) +
                    (!isMedicineRow && item.batch_number
                        ? ' <span class="med-alert-batch">Batch ' + esc(item.batch_number) + '</span>' : '') +
                '</span>' +
                '<div class="med-alert-meta">' + meta + '</div>' +
                (tags ? '<div class="med-alert-tags">' + tags + '</div>' : '') +
            '</div>' +
        '</li>';
    }

    function render(data) {
        if (!data.total) {
            body.innerHTML =
                '<div class="med-alert-empty">' +
                    '<i class="fa-solid fa-circle-check" aria-hidden="true"></i>' +
                    '<p><strong>No inventory alerts.</strong></p>' +
                    '<p class="empty-detail">Every medicine has stock across its batches, and no batch ' +
                        'expires within ' + data.threshold_days + ' days.</p>' +
                '</div>';
            sub.textContent = 'Everything looks healthy.';
            return;
        }

        var html = '';
        SECTIONS.forEach(function (section) {
            var entries = [];
            section.groups.forEach(function (key) {
                ((data.groups && data.groups[key]) || []).forEach(function (item) {
                    entries.push(item);
                });
            });
            if (!entries.length) return; // empty section: don't render a heading
            html += '<section class="med-alert-group">' +
                '<h3 class="med-alert-group-title">' + esc(section.title) +
                    ' <span class="group-count">' + entries.length + '</span></h3>' +
                '<ul class="med-alert-list">';
            entries.forEach(function (item) {
                html += rowHtml(item, item.severity === 'warning' ? 'warning' : (item.severity === 'low' ? 'low' : 'critical'));
            });
            html += '</ul></section>';
        });
        body.innerHTML = html;

        sub.textContent = plural(data.total, 'alert') + ' · batches expiring within ' +
            data.threshold_days + ' days, or medicine totals at/below ' + data.stock_threshold + '.';
    }

    function updateBadge(data) {
        if (!bell) return;
        var count = data && data.total ? data.total : 0;
        badge.textContent = String(count);
        badge.hidden = count === 0;
        bell.classList.toggle('has-alerts', count > 0);
        bell.setAttribute('aria-label', count > 0
            ? 'Inventory alerts: ' + plural(count, 'item') + ' need attention. Open alert pop-up.'
            : 'Inventory alerts: none. Open alert pop-up.');
    }

    function dismissedFor(signature) {
        try { return sessionStorage.getItem(K_DISMISS) === signature; } catch (e) { return false; }
    }

    function snoozedFor(signature) {
        try {
            var raw = sessionStorage.getItem(K_SNOOZE);
            if (!raw) return false;
            var s = JSON.parse(raw);
            return s && s.signature === signature && Date.now() < s.until;
        } catch (e) { return false; }
    }

    function shouldAutoOpen(data) {
        if (!data || !data.total) return false;                    // empty state: never pop up
        if (dismissedFor(data.signature)) return false;            // dismissed this session
        if (snoozedFor(data.signature)) return false;              // "remind me later"
        return true;
    }

    function focusables() {
        return Array.prototype.filter.call(
            card.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'),
            function (el) { return el.offsetParent !== null || el === document.activeElement; }
        );
    }

    function onKeydown(event) {
        if (!isOpen) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            dismiss(); // Esc acts as Dismiss
            return;
        }
        if (event.key !== 'Tab') return;
        var items = focusables();
        if (!items.length) { event.preventDefault(); body.focus(); return; }
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === body)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function openModal(trigger) {
        if (isOpen) return;
        lastFocused = trigger || document.activeElement;
        overlay.hidden = false;
        void overlay.offsetWidth; // force reflow so the transition plays
        overlay.classList.add('visible');
        isOpen = true;
        document.addEventListener('keydown', onKeydown, true);
        document.body.style.overflow = 'hidden';
        var items = focusables();
        (items[0] || body).focus();
    }

    function closeModal() {
        if (!isOpen) return;
        isOpen = false;
        overlay.classList.remove('visible');
        document.removeEventListener('keydown', onKeydown, true);
        document.body.style.overflow = '';
        window.setTimeout(function () { if (!isOpen) overlay.hidden = true; }, 200);
        if (lastFocused && document.contains(lastFocused)) lastFocused.focus();
        lastFocused = null;
    }

    function store(key, value) {
        try { sessionStorage.setItem(key, value); } catch (e) { /* private mode */ }
    }

    function dismiss() {
        if (lastData && lastData.signature) store(K_DISMISS, lastData.signature);
        closeModal();
    }

    function remindLater() {
        if (lastData && lastData.signature) {
            store(K_SNOOZE, JSON.stringify({
                signature: lastData.signature,
                until: Date.now() + SNOOZE_MS
            }));
        }
        closeModal();
    }

    function refresh(autoOpen, trigger) {
        lastRefreshAt = Date.now();
        return fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                if (response.status === 401) {
                    updateBadge({ total: 0 });
                    if (bell) bell.hidden = true; // signed out — nothing to show
                    return null;
                }
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.ok) return;
                lastData = data;
                render(data);
                updateBadge(data);
                if (autoOpen && shouldAutoOpen(data)) openModal(trigger);
            })
            .catch(function () { /* transient failure — keep the last good state */ });
    }

    function onClick(event) {
        var target = event.target.closest('[data-alert-action]');
        if (!target) {
            if (event.target === overlay) closeModal(); // click outside the card
            return;
        }
        var action = target.getAttribute('data-alert-action');
        if (action === 'dismiss') { dismiss(); return; }
        if (action === 'later') { remindLater(); return; }
        if (action === 'inventory') { closeModal(); } // let the link navigate
    }

    function init() {
        overlay = document.getElementById('medicine-alert-modal');
        card = document.getElementById('medicine-alert-card');
        body = document.getElementById('med-alert-body');
        sub = document.getElementById('med-alert-sub');
        bell = document.getElementById('alert-bell');
        badge = document.getElementById('alert-bell-count');
        if (!overlay || !card || !body || !sub) return;

        overlay.addEventListener('click', onClick);
        if (bell) {
            // Reopens the pop-up on demand with fresh data.
            bell.addEventListener('click', function () {
                refresh(false, bell).then(function () { openModal(bell); });
            });
        }

        refresh(true, bell); // auto-open on login / page load

        // Refresh every 5 minutes without a page reload.
        window.setInterval(function () { refresh(true, bell); }, POLL_MS);

        // Also refresh when the tab becomes active again after a full poll cycle.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && Date.now() - lastRefreshAt >= POLL_MS) {
                refresh(true, bell);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
