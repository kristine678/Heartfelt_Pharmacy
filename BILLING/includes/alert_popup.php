<?php
// Medicine inventory alert pop-up — the dialog shell only; assets/js/alerts.js fills the body,
// toggles visibility, traps focus, and polls api/alerts.php. Included by includes/footer.php so
// it sits directly under <body> (outside .app-shell) for correct overlay stacking.
$hpAlertInventoryUrl = (function_exists('currentUrlPrefix') ? currentUrlPrefix() : '') . 'pharmacy_inventory.php';
?>
<div class="med-alert-overlay" id="medicine-alert-modal" role="dialog" aria-modal="true"
     aria-labelledby="med-alert-title" aria-describedby="med-alert-sub" hidden>
    <div class="med-alert-card" id="medicine-alert-card">
        <header class="med-alert-head">
            <div class="med-alert-heading">
                <h2 id="med-alert-title">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Inventory alerts
                </h2>
                <p class="med-alert-sub" id="med-alert-sub">Checking inventory…</p>
            </div>
            <button type="button" class="med-alert-close" data-alert-action="dismiss"
                    aria-label="Close inventory alerts">&times;</button>
        </header>

        <div class="med-alert-body" id="med-alert-body" tabindex="-1"></div>

        <footer class="med-alert-foot">
            <button type="button" class="btn btn-secondary" data-alert-action="later">Remind me later</button>
            <a class="btn btn-secondary" href="<?= htmlspecialchars($hpAlertInventoryUrl) ?>"
               data-alert-action="inventory">View Inventory</a>
            <button type="button" class="btn btn-primary" data-alert-action="dismiss">Dismiss</button>
        </footer>
    </div>
</div>
