<?php
require_once '../config/db.php';
require_once '../includes/permissions.php';
// TASK 2/3: creating an order is a Menu action, guarded by the same page permission.
requirePageAccess('menu', ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$customerName = trim($_POST['customer_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$orderType = 'Dine In';

$customerName = $customerName !== '' ? $customerName : 'Walk-in Guest';

$stmt = $pdo->prepare('INSERT INTO customers (name, phone, email, table_number) VALUES (?, ?, ?, ?)');
$stmt->execute([$customerName, '', $email, '']);
$customerId = $pdo->lastInsertId();

$stmt = $pdo->prepare('INSERT INTO orders (customer_id, order_type, status) VALUES (?, ?, ?)');
$stmt->execute([$customerId, $orderType, 'open']);
$orderId = $pdo->lastInsertId();

$_SESSION['active_order_id'] = $orderId;

echo json_encode(['success' => true, 'order_id' => $orderId]);
