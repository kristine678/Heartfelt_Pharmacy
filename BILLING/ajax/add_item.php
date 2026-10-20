<?php
require_once '../config/db.php';
require_once '../includes/permissions.php';
// TASK 2/3: adding to the cart is a Menu action; JSON 403 for this fetch() caller.
requirePageAccess('menu', true);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$medicineId = isset($_POST['medicine_id']) ? (int)$_POST['medicine_id'] : 0;
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$targetId = $productId ?: $medicineId;

if ($targetId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$orderId = $_SESSION['active_order_id'] ?? null;
$customerName = trim($_POST['customer_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$orderType = 'Dine In';

$customerName = $customerName !== '' ? $customerName : 'Walk-in Guest';

if (!$orderId) {
    $customerStmt = $pdo->prepare('INSERT INTO customers (name, phone, email, table_number) VALUES (?, ?, ?, ?)');
    $customerStmt->execute([$customerName, '', $email, '']);
    $customerId = $pdo->lastInsertId();

    $orderStmt = $pdo->prepare('INSERT INTO orders (customer_id, order_type, status) VALUES (?, ?, ?)');
    $orderStmt->execute([$customerId, $orderType, 'open']);
    $orderId = $pdo->lastInsertId();
    $_SESSION['active_order_id'] = $orderId;
} else {
    $customerStmt = $pdo->prepare('SELECT c.* FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
    $customerStmt->execute([$orderId]);
    $customerRow = $customerStmt->fetch();
    if ($customerRow) {
        $updateName = $customerName !== '' ? $customerName : ($customerRow['name'] ?: 'Walk-in Guest');
        $updateEmail = $email !== '' ? $email : $customerRow['email'];
        $updateCustomer = $pdo->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, table_number = ? WHERE id = ?');
        $updateCustomer->execute([$updateName, '', $updateEmail, '', $customerRow['id']]);
    }
    $updateOrder = $pdo->prepare('UPDATE orders SET order_type = ? WHERE id = ?');
    $updateOrder->execute([$orderType, $orderId]);
}

$product = null;
if ($medicineId > 0) {
    $medicineStmt = $pdo->prepare('SELECT * FROM medicines WHERE id = ?');
    $medicineStmt->execute([$medicineId]);
    $medicine = $medicineStmt->fetch();

    if (!$medicine) {
        echo json_encode(['success' => false, 'message' => 'Medicine not found']);
        exit;
    }

    // FIX (Menu Rule 3): expired batches can never enter an order, no matter how the request
    // arrives. Compared against the server date in the app timezone (Asia/Manila), never the
    // browser clock. Batch numbers are stored like "Batch 1", so the message reads as requested.
    $medicineExpiry = (string)($medicine['expiration_date'] ?? '');
    if ($medicineExpiry !== '' && $medicineExpiry < date('Y-m-d')) {
        $expiredName = trim((string)$medicine['name']);
        $expiredBatch = trim((string)($medicine['batch_number'] ?? ''));
        echo json_encode([
            'success' => false,
            'code' => 'expired_items',
            'expired' => [['name' => $expiredName, 'batch_number' => $expiredBatch]],
            'message' => $expiredName . ' (' . $expiredBatch . ') has expired and was removed from your order.',
        ]);
        exit;
    }

    if ((int)$medicine['quantity'] < 1) {
        echo json_encode(['success' => false, 'message' => 'Out of stock: ' . htmlspecialchars($medicine['name'])]);
        exit;
    }

    $productStmt = $pdo->prepare('SELECT * FROM products WHERE name = ? ORDER BY id DESC LIMIT 1');
    $productStmt->execute([$medicine['name']]);
    $product = $productStmt->fetch();

    if (!$product) {
        $categoryId = getOrCreateCategoryId($pdo, (string)($medicine['category'] ?? 'Uncategorized'));

        $insertProduct = $pdo->prepare('INSERT INTO products (category_id, name, price, image, course_section, stock_quantity, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insertProduct->execute([$categoryId, $medicine['name'], $medicine['price'], $medicine['image'] ?: null, 'Main', max(0, (int)$medicine['quantity']), $medicine['expiration_date']]);
        $productId = (int)$pdo->lastInsertId();
    } else {
        $productId = (int)$product['id'];
    }
} else {
    $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    // FIX (Menu Rule 3): products mirror medicines in this POS — never sell an expired one.
    $productExpiry = (string)($product['expiration_date'] ?? '');
    if ($productExpiry !== '' && $productExpiry < date('Y-m-d')) {
        $expiredName = trim((string)$product['name']);
        echo json_encode([
            'success' => false,
            'code' => 'expired_items',
            'expired' => [['name' => $expiredName, 'batch_number' => '']],
            'message' => $expiredName . ' has expired and was removed from your order.',
        ]);
        exit;
    }

    if ((int)$product['stock_quantity'] < 1) {
        echo json_encode(['success' => false, 'message' => 'Out of stock: ' . htmlspecialchars($product['name'])]);
        exit;
    }
}

if (!$product && $productId > 0) {
    $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
}

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

$existing = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? AND product_id = ?');
$existing->execute([$orderId, $productId]);
$item = $existing->fetch();

if ($item) {
    $pdo->prepare('UPDATE order_items SET quantity = quantity + 1 WHERE id = ?')->execute([$item['id']]);
} else {
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, modifiers, price) VALUES (?, ?, 1, ?, ?)')->execute([$orderId, $productId, 'Classic', $product['price']]);
}

if ($medicineId > 0) {
    // FIX (Menu Rule 3): belt-and-braces — the decrement itself skips expired batches, so a sale
    // can never deduct stock from expired medicine even if the guard above raced with midnight.
    $deductMedicine = $pdo->prepare('UPDATE medicines SET quantity = GREATEST(quantity - 1, 0) WHERE id = ? AND expiration_date >= ?');
    $deductMedicine->execute([$medicineId, date('Y-m-d')]);
    $pdo->prepare('UPDATE products SET stock_quantity = GREATEST(stock_quantity - 1, 0), price = ?, image = ?, expiration_date = ? WHERE id = ?')->execute([
        $product['price'], $product['image'] ?: null, $product['expiration_date'], $productId,
    ]);
} else {
    // FIX (Menu Rule 3): mirror table gets the same expiry guard (NULL expiry = sellable).
    $pdo->prepare('UPDATE products SET stock_quantity = GREATEST(stock_quantity - 1, 0) WHERE id = ? AND (expiration_date IS NULL OR expiration_date >= ?)')->execute([$productId, date('Y-m-d')]);
}

$settings = getSettings($pdo);
$itemsStmt = $pdo->prepare('SELECT oi.*, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();
$totals = calculateTotals($items, $settings, 0);

$pdo->prepare('UPDATE orders SET subtotal = ?, service_charge = ?, tax = ?, total = ? WHERE id = ?')->execute([
    $totals['subtotal'],
    $totals['service_charge'],
    $totals['tax'],
    $totals['total'],
    $orderId,
]);

echo json_encode(['success' => true, 'totals' => $totals]);
