<?php
require_once 'config/db.php';
require_once 'includes/medicine_helpers.php';
require_once 'includes/permissions.php';
// TASK 2/3: the Inventory Alerts page follows the inventory_alerts permission (admin always has it;
// staff only when the admin grants it). Staff without it are redirected to the Menu.
requirePageAccess('inventory_alerts');

$activePage = 'pharmacy';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['threshold_days'])) {
    setPharmacyThreshold($pdo, (int)$_POST['threshold_days']);
    refreshExpiryStatuses($pdo, getPharmacyThreshold($pdo));
    $message = 'Threshold updated successfully.';
}

if (isset($_GET['send_alerts'])) {
    $result = sendExpiryAlerts($pdo);
    $message = $result['message'];
}

$threshold = getPharmacyThreshold($pdo);
$alerts = $pdo->query('SELECT m.name, m.batch_number, m.supplier, e.status, e.days_remaining, m.expiration_date FROM medicines m JOIN expiry_statuses e ON e.medicine_id = m.id WHERE e.status IN ("Near-Expiry", "Expired") ORDER BY e.days_remaining ASC')->fetchAll();

include 'includes/header.php';
?>
<div class="page-title">
    <div>
        <h1 class="page-heading">Alert Center</h1>
        <p class="page-subtitle">Adjust the threshold and send expiry alerts to the configured recipients.</p>
    </div>
    <a href="pharmacy_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($message) : ?>
    <div class="alert"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="form-card">
    <form method="post" class="filter-row">
        <label style="margin:0;">Warning Threshold (days)</label>
        <input type="number" name="threshold_days" min="1" value="<?= (int)$threshold ?>" style="max-width:180px;">
        <button class="btn btn-primary" type="submit">Update Threshold</button>
    </form>
    <div class="action-row">
        <a href="pharmacy_alerts.php?send_alerts=1" class="btn btn-secondary">Send Alert Emails</a>
    </div>
</div>

<div class="form-card" style="margin-top:20px;">
    <h2>Current Alerts</h2>
    <?php if (empty($alerts)) : ?>
        <p class="empty-state">No alerting medicines currently flagged.</p>
    <?php else : ?>
        <ul class="stack-list">
            <?php foreach ($alerts as $alert) : ?>
                <li class="stack-item">
                    <div>
                        <strong><?= htmlspecialchars($alert['name']) ?></strong>
                        <div class="muted">Batch <?= htmlspecialchars($alert['batch_number']) ?> • Supplier <?= htmlspecialchars($alert['supplier']) ?></div>
                        <div class="muted">Expiry: <?= htmlspecialchars($alert['expiration_date']) ?> • <?= (int)$alert['days_remaining'] ?> days</div>
                    </div>
                    <span class="pill" style="background:<?= $alert['status'] === 'Expired' ? '#ffe3e3' : '#fff3bf' ?>;color:<?= $alert['status'] === 'Expired' ? '#c92a2a' : '#e67700' ?>;"><?= htmlspecialchars($alert['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
