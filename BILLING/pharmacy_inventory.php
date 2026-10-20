<?php
// Medicine Inventory (Pharmacy page) - now the SHARED inventory view (Task 3).
//
// The view itself (status badges, summary cards, filters, table columns) lives in
// includes/inventory_view.php and is the same one the POS "Inventory Dashboard" button opens, so
// the two screens can never drift apart. This page only adds what is admin-specific: the
// Add/Edit/Delete actions, on top of its existing server-side gate.
require_once 'config/db.php';
require_once 'includes/inventory_view.php';
require_once 'includes/permissions.php';
// TASK 3: managed through the grantable Pharmacy page permission (admin always has it).
requirePageAccess('pharmacy');

$activePage = 'pharmacy';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['medicine_id'])) {
    // Destructive action: still admin-only, regardless of the Pharmacy grant.
    requireAdmin();
    $id = (int) $_POST['medicine_id'];
    $pdo->prepare('DELETE FROM medicines WHERE id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM expiry_statuses WHERE medicine_id = ?')->execute([$id]);
    $_SESSION['alert'] = 'Medicine deleted.';
    header('Location: pharmacy_inventory.php');
    exit;
}

// Deep-linkable filters: the shared script filters live, and these values pre-apply on load.
$data = inventoryViewData($pdo, [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? 'all')),
    'sort' => trim((string) ($_GET['sort'] ?? 'attention')),
]);

include 'includes/header.php';
?>
<div class="page-title">
    <div>
        <h1 class="page-heading">Medicine Inventory</h1>
        <p class="page-subtitle">
            The same view the POS opens from its Inventory Dashboard button &mdash; add, update,
            search, and review medicine stock.
        </p>
    </div>
    <div class="page-title-actions">
        <a href="pharmacy_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        <a href="pharmacy_form.php" class="btn btn-primary">Add Medicine</a>
    </div>
</div>

<?php if (!empty($_SESSION['alert'])) : ?>
    <div class="alert"><?= htmlspecialchars((string) $_SESSION['alert'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>

<?= renderInventoryView($data, [
    'manage' => true,
    // This page is the admin stock screen, so it keeps the per-batch Delete action. Delete still
    // posts to this page (action=delete), which re-checks requireAdmin() server-side.
    // TASK 3: deleting a batch stays administrator-only, so the delete form is only rendered for
    // admins even when a staff member has the Pharmacy page; the POST still re-checks requireAdmin().
    'allow_delete' => ($_SESSION['role'] ?? '') === 'admin',
    'endpoint' => 'api/inventory.php',
    'search' => (string) ($_GET['search'] ?? ''),
    'status' => (string) ($_GET['status'] ?? 'all'),
    'sort' => (string) ($_GET['sort'] ?? 'attention'),
    'sortable' => true,
]) ?>

<script src="assets/js/inventory-view.js"></script>
<?php include 'includes/footer.php'; ?>
