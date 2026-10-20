<?php
require_once 'config/db.php';
require_once 'includes/medicine_helpers.php';
require_once 'includes/inventory_view.php';
require_once 'includes/permissions.php';
// TASK 3: Pharmacy is a grantable page (admin always has it, staff only when granted it).
requirePageAccess('pharmacy');

$activePage = 'pharmacy';
try {
    $threshold = getPharmacyThreshold($pdo);
    $summary = buildMedicineSummary($pdo, $threshold);
    // FIX (Task3): the summary cards below come from the SAME view model the shared inventory view
    // (Pharmacy inventory page + POS Inventory Dashboard) uses, so all three agree on the numbers.
    $inventoryData = inventoryViewData($pdo);
    $alerts = $pdo->query('SELECT m.name, m.batch_number, m.supplier, m.quantity, e.status, e.days_remaining, m.expiration_date FROM medicines m JOIN expiry_statuses e ON e.medicine_id = m.id WHERE e.status IN ("Near-Expiry", "Expired", "Low stock") ORDER BY CASE WHEN e.status = "Expired" THEN 0 WHEN e.status = "Near-Expiry" THEN 1 ELSE 2 END, e.days_remaining ASC LIMIT 10')->fetchAll();
    $recent = $pdo->query('SELECT m.*, e.status, e.days_remaining FROM medicines m LEFT JOIN expiry_statuses e ON e.medicine_id = m.id ORDER BY m.created_at DESC LIMIT 8')->fetchAll();
} catch (PDOException $e) {
    $error = 'Database error: ' . htmlspecialchars($e->getMessage());
    $summary = ['total' => 0, 'safe' => 0, 'warning' => 0, 'expired' => 0];
    $inventoryData = null;
    $alerts = [];
    $recent = [];
}

include 'includes/header.php';
?>
<?php if (!empty($error)) : ?>
    <div class="alert"><?= $error ?></div>
<?php endif; ?>
<div class="page-title">
    <div>
        <h1 class="page-heading">Pharmacy Inventory Dashboard</h1>
        <p class="page-subtitle">Monitor stock health, expiry risk, and alerts from one view.</p>
    </div>
    <div class="page-title-actions">
        <!-- FIX (Task3): the same inventory view the POS opens from its Inventory Dashboard button. -->
        <a href="inventory-dashboard.php" class="btn btn-secondary">Inventory Dashboard</a>
        <a href="pharmacy_inventory.php" class="btn btn-primary">Manage Inventory</a>
    </div>
</div>

<div class="filter-row">
    <form method="post" action="pharmacy_alerts.php" style="display:flex;gap:10px;flex-wrap:wrap;">
        <input type="number" name="threshold_days" min="1" value="<?= (int)$threshold ?>" style="max-width:140px;">
        <button class="btn btn-secondary" type="submit">Save Threshold</button>
    </form>
</div>

<?php if ($inventoryData !== null) : ?>
    <!-- FIX (Task3): the shared summary cards (total / units / low / out / expiring / expired). -->
    <?= inventorySummaryCards($inventoryData) ?>
<?php else : ?>
    <div class="menu-grid">
        <article class="menu-card">
            <h3>Total Medicines</h3>
            <p style="font-size:2rem;margin:6px 0;"><?= (int)$summary['total'] ?></p>
        </article>
        <article class="menu-card" style="border-color:#dff7ea;">
            <h3>Safe</h3>
            <p style="font-size:2rem;margin:6px 0;color:#2f9e44;"><?= (int)$summary['safe'] ?></p>
        </article>
        <article class="menu-card" style="border-color:#fff3bf;">
            <h3>Near-Expiry</h3>
            <p style="font-size:2rem;margin:6px 0;color:#e67700;"><?= (int)$summary['warning'] ?></p>
        </article>
        <article class="menu-card" style="border-color:#ffe3e3;">
            <h3>Expired</h3>
            <p style="font-size:2rem;margin:6px 0;color:#c92a2a;"><?= (int)$summary['expired'] ?></p>
        </article>
    </div>
<?php endif; ?>

<div class="dashboard-grid" style="margin-top:20px;">
    <section class="center-panel">
        <h2>Priority Alerts</h2>
        <?php if (empty($alerts)) : ?>
            <p class="empty-state">No near-expiry or expired medicines at the moment.</p>
        <?php else : ?>
            <ul class="stack-list">
                <?php foreach ($alerts as $alert) : ?>
                    <li class="stack-item">
                        <div>
                            <strong><?= htmlspecialchars($alert['name']) ?></strong>
                            <div class="muted">Batch <?= htmlspecialchars($alert['batch_number']) ?> • Supplier <?= htmlspecialchars($alert['supplier']) ?></div>
                            <?php if ($alert['status'] === 'Low stock') : ?>
                                <div class="muted">Stock: <?= (int)$alert['quantity'] ?> units</div>
                            <?php else : ?>
                                <div class="muted">Expiry: <?= htmlspecialchars($alert['expiration_date']) ?> • <?= (int)$alert['days_remaining'] ?> days</div>
                            <?php endif; ?>
                        </div>
                        <span class="pill" style="background:<?= $alert['status'] === 'Expired' ? '#ffe3e3' : '#fff3bf' ?>;color:<?= $alert['status'] === 'Expired' ? '#c92a2a' : '#e67700' ?>;"><?= htmlspecialchars($alert['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <aside class="order-panel">
        <h2>Recent Inventory</h2>
        <ul class="stack-list">
            <?php foreach ($recent as $row) : ?>
                <li class="stack-item">
                    <div>
                        <strong><?= htmlspecialchars($row['name']) ?></strong>
                        <div class="muted">Qty: <?= (int)$row['quantity'] ?> • Exp: <?= htmlspecialchars($row['expiration_date']) ?></div>
                    </div>
                    <span class="pill" style="background:<?= ($row['status'] ?? 'Safe') === 'Expired' ? '#ffe3e3' : (in_array(($row['status'] ?? 'Safe'), ['Near-Expiry', 'Low stock'], true) ? '#fff3bf' : '#dff7ea') ?>;color:<?= ($row['status'] ?? 'Safe') === 'Expired' ? '#c92a2a' : (in_array(($row['status'] ?? 'Safe'), ['Near-Expiry', 'Low stock'], true) ? '#e67700' : '#2f9e44') ?>;"><?= htmlspecialchars($row['status'] ?? 'Safe') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>
</div>
<?php include 'includes/footer.php'; ?>
