<?php
require_once 'config/db.php';
require_once 'includes/medicine_helpers.php';
require_once 'includes/permissions.php';
// TASK 3: a Pharmacy page — guarded by the grantable pharmacy permission.
requirePageAccess('pharmacy');

$activePage = 'pharmacy';
$threshold = getPharmacyThreshold($pdo);
refreshExpiryStatuses($pdo, $threshold);

$sql = 'SELECT m.*, e.status, e.days_remaining FROM medicines m LEFT JOIN expiry_statuses e ON e.medicine_id = m.id ORDER BY m.name ASC';
$stmt = $pdo->query($sql);
$medicines = $stmt->fetchAll();

include 'includes/header.php';
?>
<div class="page-title">
    <div>
        <h1 class="page-heading">Printable Inventory Report</h1>
        <p class="page-subtitle">Export a clean report for review or printing.</p>
    </div>
    <div class="action-row">
        <a href="pharmacy_report.php" class="btn btn-secondary" onclick="window.print();return false;">Print Report</a>
        <a href="pharmacy_dashboard.php" class="btn btn-secondary">Back</a>
    </div>
</div>

<div class="table-wrap">
    <table class="table-basic">
        <thead>
            <tr>
                <th>Name</th>
                <th>Batch</th>
                <th>Quantity</th>
                <th>Supplier</th>
                <th>Expiry Date</th>
                <th>Status</th>
                <th>Days Remaining</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($medicines as $medicine) : ?>
                <?php $statusValue = $medicine['status'] ?? 'Safe'; ?>
                <tr>
                    <td><?= htmlspecialchars($medicine['name']) ?></td>
                    <td><?= htmlspecialchars($medicine['batch_number']) ?></td>
                    <td><?= (int)$medicine['quantity'] ?></td>
                    <td><?= htmlspecialchars($medicine['supplier']) ?></td>
                    <td><?= htmlspecialchars($medicine['expiration_date']) ?></td>
                    <td><?= htmlspecialchars($statusValue) ?></td>
                    <td><?= (int)($medicine['days_remaining'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include 'includes/footer.php'; ?>
