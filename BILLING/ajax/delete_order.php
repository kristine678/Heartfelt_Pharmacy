<?php
// FIX (Bug 6): dedicated per-order delete endpoint used by History rows.
// Lives outside the admin-only settings.php so staff pages don't hit a 403 mid-action.
require_once '../config/db.php';
requireLogin();

// FIX (Bug 6): destructive action — administrators only; staff get bounced back with an explanation.
if (($_SESSION['role'] ?? 'staff') !== 'admin') {
    $_SESSION['alert'] = 'Only administrators can delete history records.';
    header('Location: ../history.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../history.php');
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
// The old Bills page is gone — anything pointing back at it lands on History now.
$back = '../history.php';

if ($orderId <= 0) {
    $_SESSION['alert'] = 'Invalid order reference.';
    header('Location: ' . $back);
    exit;
}

// FIX (Bug 6): single shared transactional delete (order + order_items) from config/db.php.
if (deleteOrderById($pdo, $orderId)) {
    $_SESSION['alert'] = 'Order #' . $orderId . ' was deleted.';
} else {
    $_SESSION['alert'] = 'Unable to delete order #' . $orderId . '.';
}

header('Location: ' . $back);
exit;
