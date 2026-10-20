</main>
</div>
<?php
// TASK 2: the alert pop-up shell + polling script ship ONLY to users who hold the inventory_alerts
// page (an admin always does). Staff without it never load the dialog or call api/alerts.php, and
// the endpoint refuses them server-side anyway.
$hpShowInventoryAlerts = function_exists('userCanAccess') ? userCanAccess('inventory_alerts') : false;
?>
<?php if ($hpShowInventoryAlerts) : ?>
<!-- Medicine inventory alert pop-up shell (body-level so it overlays the whole app). -->
<?php require __DIR__ . '/alert_popup.php'; ?>
<?php endif; ?>
<script src="assets/js/app.js"></script>
<?php if ($hpShowInventoryAlerts) : ?>
<!-- Inventory alert logic: fetches api/alerts.php, renders the list, badge, focus trap, polling.
     The ?v= param is a cache-buster: browsers heuristically cache this static file otherwise,
     so bump it whenever alerts.js changes (e.g. ?v=202610132). -->
<script src="assets/js/alerts.js?v=20261016"></script>
<?php endif; ?>
<!-- FIX (Bug 2): ONE shared background snippet — pauses animations when the tab is hidden. -->
<script src="assets/js/bg-pause.js"></script>
<!-- FIX (logo animations): ONE shared snippet — sidebar badge twirls once on every staff page load. -->
<script src="assets/js/hp-logo-anim.js"></script>
</body>
</html>
