<?php
require_once 'config/db.php';
requireLogin();

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId) {
    $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $stmt->execute(['fired', $orderId]);
}
header('Location: history.php');
exit;
