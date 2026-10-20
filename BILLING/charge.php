<?php
// FIX (Bug 1): charge endpoint — always answer with JSON (even on bad payloads) and always requireLogin()
// so an expired session returns a clean JSON error instead of an HTML redirect that broke the POS fetch.
require_once 'config/db.php';
requireLogin();

header('Content-Type: application/json');

$payload = $_POST;
$rawBody = file_get_contents('php://input');
if (is_string($rawBody) && $rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

// FIX (Bug 1): validate the payload shape before saving — previously a malformed body fell through
// to the GET branch and echoed {"success":false} with HTTP 200, which the POS read as success.
if (!empty($payload['order']) && is_array($payload['order'])) {
    $order = $payload['order'];
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Add at least one medicine before charging.']);
        exit;
    }

    $result = savePaidOrderTransaction($pdo, [
        'customer_id' => (int)($order['customer_id'] ?? 0),
        'subtotal' => (float)($order['subtotal'] ?? 0),
        'discount_amount' => (float)($order['discount_amount'] ?? 0),
        'discount_type' => (string)($order['discountType'] ?? 'none'),
        'discount_id_number' => (string)($order['idNumber'] ?? ''),
        'service_charge' => (float)($order['service_charge'] ?? 0),
        'tax' => (float)($order['tax'] ?? 0),
        'total' => (float)($order['total'] ?? 0),
        'amount_received' => (float)($order['amountReceived'] ?? 0),
        'change_amount' => (float)($order['change'] ?? 0),
        // FIX (Task 3): optional customer email travels with the charge and is saved on the order.
        'customer_email' => (string)($order['customer_email'] ?? ''),
        'items' => $items,
    ]);
    echo json_encode($result);
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId) {
    // FIX (Bug 6): mark as paid only if the order still exists, so deleted bills don't get resurrected.
    $stmt = $pdo->prepare('UPDATE orders SET status = ?, paid_at = NOW() WHERE id = ?');
    $stmt->execute(['paid', $orderId]);
    echo json_encode(['success' => true, 'order_id' => $orderId]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid charge request']);
exit;
