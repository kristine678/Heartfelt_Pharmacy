<?php
// Inventory Dashboard (Task 3) - opened by the "Inventory Dashboard" button in the POS header.
//
// This page renders the SAME view as the Pharmacy page: includes/inventory_view.php owns the status
// rules, the summary cards, the filters, the table columns and the numbers, and both pages refresh
// from the same endpoint (api/inventory.php). There is no second implementation here.
//
// Permissions: staff get it read-only; admins additionally get per-batch Edit links and the
// "Add Medicine" button. Any stock-changing action lives on the admin-only pages, which enforce
// their own server-side gate (requirePageAccess() / requireAdmin()).
require_once 'config/db.php';
require_once 'includes/inventory_view.php';
require_once 'includes/permissions.php';
// TASK 2/3: reached from the Menu header button, so it follows the inventory_dashboard page
// permission — a staff account without it is sent back to the Menu with a message.
requirePageAccess('inventory_dashboard');

$activePage = 'inventory';
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$data = inventoryViewData($pdo, [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? 'all')),
]);

include 'includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Inventory Dashboard</h1>
        <p class="page-subtitle">
            The same live stock view as the Pharmacy page, straight from the POS medicines list.
            Low stock: ≤ <?= (int) $data['thresholds']['stock_units'] ?> units &middot;
            Expiring soon: within <?= (int) $data['thresholds']['expiry_days'] ?> days
            <?php if (!$isAdmin) : ?><span class="pill">Read-only view</span><?php endif; ?>
        </p>
    </div>
    <div class="page-title-actions">
        <a href="menu.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to POS</a>
        <?php if ($isAdmin) : ?>
            <a href="pharmacy_form.php" class="btn btn-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Medicine</a>
            <a href="pharmacy_inventory.php" class="btn btn-secondary"><i class="fa-solid fa-capsules" aria-hidden="true"></i> Pharmacy Inventory</a>
        <?php endif; ?>
    </div>
</div>

<!-- Low-stock and expiring batches are highlighted at the top of the table (the shared view sorts
     by "needs attention" and colours the rows exactly like the Inventory Alerts page). -->
<?= renderInventoryView($data, [
    'manage' => $isAdmin,
    'endpoint' => 'api/inventory.php',
    'search' => (string) ($_GET['search'] ?? ''),
    'status' => (string) ($_GET['status'] ?? 'all'),
    'sortable' => true,
]) ?>

<script src="assets/js/inventory-view.js"></script>
<?php include 'includes/footer.php'; ?>
