<?php
// FIX (Bugs 6/7): history.php — shared paid-orders query, no Customer column, per-order delete
// with pink type-DELETE confirmation, search matches order number only.
// Task 1: admin-only "Delete All History" also lives here — deleting everything restarts
// order numbering at #1 (see clearOrderHistory() in config/db.php).
require_once 'config/db.php';
require_once 'includes/permissions.php';
// TASK 2: History is a default page for staff, but it is still guarded here so a future permission
// change (or a revoked grant) can never leave a direct URL working.
requirePageAccess('history');

if (isset($_POST['clear_order_history'])) {
    if (($_SESSION['role'] ?? 'staff') !== 'admin') {
        $_SESSION['alert'] = 'Only administrators can delete all history.';
    } elseif (!hash_equals((string) ($_SESSION['history_clear_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['alert'] = 'Your session expired. Refresh the page and try again.';
    } else {
        $result = clearOrderHistory($pdo);
        $_SESSION['alert'] = $result['success']
            ? ($result['counter_reset']
                ? 'All order history was deleted. Order numbering restarted at #1.'
                : 'All order history was deleted. Order numbering was left unchanged because records remain.')
            : 'Unable to delete all history. ' . htmlspecialchars($result['message'] ?? '');
    }
    header('Location: history.php');
    exit;
}

$activePage = 'history';
// FIX (Bug 7): customer join removed from the history query entirely.
$query = 'SELECT o.* FROM orders o WHERE o.status = "paid"';
$params = [];

$filterPeriod = $_GET['filter_period'] ?? 'all';
if (!in_array($filterPeriod, ['all', 'day', 'month', 'year'], true)) {
    $filterPeriod = 'all';
}

if ($filterPeriod === 'day') {
    $query .= ' AND DATE(o.paid_at) = CURDATE()';
} elseif ($filterPeriod === 'month') {
    $query .= ' AND YEAR(o.paid_at) = YEAR(CURDATE()) AND MONTH(o.paid_at) = MONTH(CURDATE())';
} elseif ($filterPeriod === 'year') {
    $query .= ' AND YEAR(o.paid_at) = YEAR(CURDATE())';
}

if (!empty($_GET['search'])) {
    // FIX (Bug 7): search now matches order number only (Customer column is gone).
    $query .= ' AND o.id LIKE ?';
    $search = '%' . trim($_GET['search']) . '%';
    $params = [$search];
}

$query .= ' ORDER BY o.paid_at DESC, o.id DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$history = $stmt->fetchAll();

include 'includes/header.php';
?>
<div class="page-title">
    <h1>History</h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <?php if (($_SESSION['role'] ?? 'staff') === 'admin') : ?>
            <?php if (empty($_SESSION['history_clear_csrf'])) {
                $_SESSION['history_clear_csrf'] = bin2hex(random_bytes(32));
            } ?>
            <form method="post" id="delete-all-history-form">
                <input type="hidden" name="clear_order_history" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['history_clear_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" id="delete-all-history-btn" class="btn btn-danger">Delete All History</button>
            </form>
        <?php endif; ?>
        <a href="index.php" class="btn btn-primary">New Order</a>
    </div>
    <form method="get" class="filter-row">
        <input type="text" name="search" placeholder="Search by order #" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        <select name="filter_period">
            <option value="all" <?= $filterPeriod === 'all' ? 'selected' : '' ?>>All time</option>
            <option value="day" <?= $filterPeriod === 'day' ? 'selected' : '' ?>>Days</option>
            <option value="month" <?= $filterPeriod === 'month' ? 'selected' : '' ?>>Months</option>
            <option value="year" <?= $filterPeriod === 'year' ? 'selected' : '' ?>>Years</option>
        </select>
        <button class="btn btn-primary" type="submit">Filter</button>
    </form>
</div>
<?php if (isset($_SESSION['alert'])) : ?>
    <div class="alert"><?= htmlspecialchars($_SESSION['alert']) ?></div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>
<div class="table-wrap">
    <?php if (empty($history)) : ?>
        <div class="empty-state">No orders yet.</div>
    <?php else : ?>
        <table class="table-basic history-table">
            <thead>
                <tr>
                    <!-- FIX (Bug 7): Customer column removed; widths rebalanced across remaining columns. -->
                    <th class="col-bill">Order #</th>
                    <th class="col-status">Status</th>
                    <th class="col-total">Total</th>
                    <th class="col-created">Created</th>
                    <th class="col-action">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $row) : ?>
                    <tr data-order-id="<?= (int)$row['id'] ?>">
                        <td>#<?= (int)$row['id'] ?></td>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                        <td><?= format_currency($row['total']) ?></td>
                        <td><?= htmlspecialchars((string)($row['paid_at'] ?? $row['created_at'])) ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="print-bill.php?order_id=<?= (int)$row['id'] ?>" target="_blank" class="btn btn-secondary">View</a>
                                <a href="email-bill.php?order_id=<?= (int)$row['id'] ?>" class="btn btn-primary">Email</a>
                                <?php if (($_SESSION['role'] ?? 'staff') === 'admin') : ?>
                                    <form method="post" action="ajax/delete_order.php" class="delete-order-form">
                                        <input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="back" value="history">
                                        <button type="submit" class="btn btn-danger order-delete-btn" aria-label="Delete order #<?= (int)$row['id'] ?>">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<script>
    // FIX (Bug 6): pink confirm modal (type DELETE) before a per-order delete is submitted.
    // Task 1: "Delete All History" needs a stronger confirmation — it also restarts order
    // numbering at #1 — and is admin-only (the button is rendered for admins only).
    (function bindDeleteAllHistory() {
        const button = document.getElementById('delete-all-history-btn');
        const form = document.getElementById('delete-all-history-form');
        if (!button || !form) {
            return;
        }
        button.addEventListener('click', () => {
            window.confirmAction({
                title: 'Delete all history?',
                message: 'This will permanently delete all history and restart order numbers at #1. Continue?',
                confirmLabel: 'Delete forever',
                requireText: 'DELETE',
                onConfirm: (confirmed) => {
                    if (confirmed) {
                        form.submit();
                    }
                }
            });
        });
    })();

    document.querySelectorAll('.delete-order-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const orderId = form.querySelector('input[name="order_id"]')?.value || '';
            window.confirmAction({
                title: 'Delete this record?',
                message: `Permanently delete order #${orderId} from history? This cannot be undone.`,
                confirmLabel: 'Delete order',
                requireText: 'DELETE',
                onConfirm: (confirmed) => {
                    if (confirmed) {
                        form.submit();
                    }
                }
            });
        });
    });
</script>
<?php include 'includes/footer.php'; ?>
