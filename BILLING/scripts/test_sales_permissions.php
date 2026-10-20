<?php
/**
 * CLI test harness for TASK 1 (Sales analytics) and TASK 3 (page permissions) at the PHP/SQL layer.
 *
 *   php scripts/test_sales_permissions.php
 *   php scripts/test_sales_permissions.php --cleanup
 *
 * Exits non-zero on any failure. It creates its own dated fixtures on a day in a PAST, otherwise
 * empty month (so the exact totals are provable), charges one real order through
 * savePaidOrderTransaction() to check the category snapshot, then removes everything it created.
 *
 * Covers:
 *   1  daily totals  (revenue, orders, items, average) on an isolated day
 *   2  previous-period comparison + percentage change
 *   3  hourly buckets (Daily), day-of-month bucket (Monthly), month buckets (Yearly)
 *   4  category breakdown by quantity and by revenue (with Uncategorized fallback)
 *   5  top selling medicines ordering
 *   6  cancelled / open orders are NEVER counted
 *   7  category snapshot written by the real charge path, and readable afterwards
 *   8  permission storage: defaults, grant, revoke, admin-only rejection, audit log
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/db.php';
// The permission registry + storage helpers live in includes/permissions.php.
require __DIR__ . '/../includes/permissions.php';

$STATE_FILE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'heartfelt_sales_test_state.json';
$cleanup = in_array('--cleanup', array_slice($argv, 1), true);

if ($cleanup) {
    $state = is_file($STATE_FILE) ? json_decode((string) file_get_contents($STATE_FILE), true) : null;
    if (!is_array($state)) {
        echo "Nothing to clean (no state file).\n";
        exit(0);
    }
    removeSalesTestFixtures($pdo, $state);
    @unlink($STATE_FILE);
    echo "Removed sales-test fixtures.\n";
    exit(0);
}

/** Delete everything this harness created (orders first, then the medicines/users it added). */
function removeSalesTestFixtures(PDO $pdo, array $state): void
{
    foreach (($state['order_ids'] ?? []) as $orderId) {
        $orderId = (int) $orderId;
        $pdo->prepare('DELETE a FROM order_item_batch_allocations a JOIN order_items i ON i.id = a.order_item_id WHERE i.order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
    }
    foreach (($state['medicine_ids'] ?? []) as $medicineId) {
        $medicineId = (int) $medicineId;
        $pdo->prepare('DELETE FROM expiry_statuses WHERE medicine_id = ?')->execute([$medicineId]);
        $pdo->prepare('DELETE FROM medicines WHERE id = ?')->execute([$medicineId]);
    }
    foreach (($state['user_ids'] ?? []) as $userId) {
        $userId = (int) $userId;
        $pdo->prepare('DELETE FROM user_pages WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
    foreach (($state['customer_ids'] ?? []) as $customerId) {
        // orders.customer_id carries a FK with ON DELETE CASCADE, so this also clears anything left.
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([(int) $customerId]);
    }
    if (!empty($state['log_ids'])) {
        $pdo->prepare('DELETE FROM admin_logs WHERE id IN (' . implode(',', array_map('intval', $state['log_ids'])) . ')')->execute();
    }
}

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
    if (!$ok) {
        $failures++;
    }
};
$eq = static function (string $label, $expected, $actual) use (&$failures): void {
    // Money arrives as floats (180.0) and counts as ints, so compare numerically when both sides
    // are numeric; otherwise compare as strings.
    if (is_numeric($expected) && is_numeric($actual)) {
        $ok = abs((float) $expected - (float) $actual) < 0.005;
    } else {
        $ok = (string) $expected === (string) $actual;
    }
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? " = {$actual}" : " — expected {$expected}, got {$actual}");
    if (!$ok) {
        $failures++;
    }
};

$state = ['order_ids' => [], 'medicine_ids' => [], 'user_ids' => [], 'customer_ids' => [], 'log_ids' => []];
$logWatermark = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_logs')->fetchColumn();

// orders.customer_id has a FK to customers, so the fixtures need a real (throwaway) customer.
$pdo->prepare("INSERT INTO customers (name, phone, email, table_number) VALUES ('TEST Sales Customer', '', '', '')")->execute();
$testCustomerId = (int) $pdo->lastInsertId();
$state['customer_ids'][] = $testCustomerId;

// ---------------------------------------------------------------------------------------------
// Fixtures on an isolated past month day (proves the exact arithmetic, independent of live data)
// ---------------------------------------------------------------------------------------------
$dailyDate = '2026-09-10';   // an earlier month: nothing else is sold there
$previousDate = '2026-09-09';
$monthInput = '2026-09';     // Monthly window containing the daily fixture
$previousMonthInput = '2026-08';
$yearInput = '2026';

$baselineDay = static function (PDO $pdo, string $start, string $end): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?");
    $stmt->execute([$start, $end]);
    return (float) $stmt->fetchColumn();
};

echo "== fixture isolation ==\n";
$check(
    'the fixture day is free of live sales (exact totals are provable)',
    $baselineDay($pdo, $dailyDate . ' 00:00:00', '2026-09-11 00:00:00') === 0.0
    && $baselineDay($pdo, $previousDate . ' 00:00:00', $dailyDate . ' 00:00:00') === 0.0
    && $baselineDay($pdo, '2026-08-01 00:00:00', '2026-09-01 00:00:00') === 0.0
);

$insertOrder = $pdo->prepare('INSERT INTO orders (customer_id, order_type, status, subtotal, discount, discount_type, service_charge, tax, total, amount_received, change_amount, paid_at) VALUES (?, "Dine In", ?, ?, 0, "none", 0, 0, ?, ?, 0, ?)');
$insertItem = $pdo->prepare('INSERT INTO order_items (order_id, medicine_id, product_id, quantity, name, price, image, category) VALUES (?, 0, 0, ?, ?, ?, NULL, ?)');

$makeOrder = static function (string $status, string $paidAt, float $total, array $lines) use ($pdo, $insertOrder, $insertItem, $testCustomerId, &$state): int {
    $insertOrder->execute([$testCustomerId, $status, $total, $total, $total, $paidAt]);
    $orderId = (int) $pdo->lastInsertId();
    $state['order_ids'][] = $orderId;
    foreach ($lines as $line) {
        $insertItem->execute([$orderId, (int) $line['qty'], (string) $line['name'], (float) $line['price'], (string) $line['category']]);
    }
    return $orderId;
};

// Day fixture: 3 paid orders, plus one open and one cancelled that must never count.
$makeOrder('paid', $dailyDate . ' 10:15:00', 100.00, [
    ['qty' => 2, 'name' => 'TEST Sales Med X', 'price' => 20.00, 'category' => 'TEST Sales Cat A'],
    ['qty' => 1, 'name' => 'TEST Sales Med Y', 'price' => 60.00, 'category' => 'TEST Sales Cat B'],
]);
$makeOrder('paid', $dailyDate . ' 15:45:00', 50.00, [
    ['qty' => 1, 'name' => 'TEST Sales Med X', 'price' => 50.00, 'category' => 'TEST Sales Cat A'],
]);
$makeOrder('paid', $dailyDate . ' 23:30:00', 30.00, [
    ['qty' => 3, 'name' => 'TEST Sales Med Z', 'price' => 10.00, 'category' => ''],
]);
$makeOrder('open', $dailyDate . ' 11:00:00', 999.00, [['qty' => 9, 'name' => 'TEST Sales Med Open', 'price' => 111.00, 'category' => 'TEST Sales Cat A']]);
$makeOrder('cancelled', $dailyDate . ' 12:00:00', 555.00, [['qty' => 5, 'name' => 'TEST Sales Med Void', 'price' => 111.00, 'category' => 'TEST Sales Cat A']]);
// Previous day (for the Daily comparison) and previous month / previous year baselines.
$makeOrder('paid', $previousDate . ' 12:00:00', 200.00, [['qty' => 4, 'name' => 'TEST Sales Med X', 'price' => 50.00, 'category' => 'TEST Sales Cat A']]);
$makeOrder('paid', '2026-08-14 09:00:00', 500.00, [['qty' => 5, 'name' => 'TEST Sales Med Y', 'price' => 100.00, 'category' => 'TEST Sales Cat B']]);
$makeOrder('paid', '2025-09-10 09:00:00', 700.00, [['qty' => 7, 'name' => 'TEST Sales Med Z', 'price' => 100.00, 'category' => 'TEST Sales Cat B']]);

// ---------------------------------------------------------------------------------------------
// 1 + 2 — daily totals and period-over-period change
// ---------------------------------------------------------------------------------------------
echo "\n== 1/2  daily totals + change vs yesterday ==\n";
$report = salesReport($pdo, 'daily', ['date' => $dailyDate]);
$eq('total sales', '180.00', $report['totals']['revenue']);
$eq('orders', 3, $report['totals']['orders']);
$eq('items sold', 7, $report['totals']['items']);
$eq('average order value', '60.00', $report['totals']['average']);
$eq('previous-day sales', '200.00', $report['previous']['revenue']);
$eq('revenue change % vs yesterday', '-10', $report['change']['revenue']);
$eq('range label', 'September 10, 2026', $report['range']['label']);
$eq('previous range label', 'September 9, 2026', $report['previous_range']['label']);
$check('has_sales flag', $report['has_sales'] === true);

// 6 — cancelled/open orders are excluded (they would add ₱1554 and 14 items)
$excludedDay = salesReport($pdo, 'daily', ['date' => $dailyDate]);
$check('cancelled + open orders excluded', $excludedDay['totals']['revenue'] === 180.0 && $excludedDay['totals']['items'] === 7, 'revenue ' . $excludedDay['totals']['revenue'] . ', items ' . $excludedDay['totals']['items']);

// ---------------------------------------------------------------------------------------------
// 3 — chart buckets
// ---------------------------------------------------------------------------------------------
echo "\n== 3  chart buckets ==\n";
$series = $report['series'];
$eq('24 hourly buckets exist', 24, count($series['revenue']));
$eq('hour 10 revenue', '100', $series['revenue'][10]);
$eq('hour 15 revenue', '50', $series['revenue'][15]);
$eq('hour 23 revenue', '30', $series['revenue'][23]);
$eq('hour 10 order count', 1, $series['orders'][10]);
$eq('empty hour 11 revenue', '0', $series['revenue'][11]);
$eq('hour label formatting', '11 PM', $series['labels'][23]);

// September holds BOTH fixture days (the 9th's 200 and the 10th's 180).
$monthly = salesReport($pdo, 'monthly', ['month' => $monthInput]);
$eq('monthly total sales', '380.00', $monthly['totals']['revenue']);
$eq('monthly orders', 4, $monthly['totals']['orders']);
$eq('monthly items sold', 11, $monthly['totals']['items']);
$eq('monthly average order value', '95.00', $monthly['totals']['average']);
$eq('30 day buckets for September', 30, count($monthly['series']['revenue']));
$eq('day 10 bucket revenue', '180', $monthly['series']['revenue'][9]);
$eq('day 9 bucket revenue', '200', $monthly['series']['revenue'][8]);
$eq('monthly label', 'September 2026', $monthly['range']['label']);
$eq('previous month label', 'August 2026', $monthly['previous_range']['label']);
$eq('monthly change % vs August', '-24', $monthly['change']['revenue']);

$yearly = salesReport($pdo, 'yearly', ['year' => $yearInput]);
$eq('12 month buckets', 12, count($yearly['series']['revenue']));
$eq('September bucket revenue', '380', $yearly['series']['revenue'][8]);
$eq('August bucket revenue', '500', $yearly['series']['revenue'][7]);
$eq('yearly change % is computed against 2025', salesChangePercent((float) $yearly['totals']['revenue'], (float) $yearly['previous']['revenue']), $yearly['change']['revenue']);
$eq('month label', 'Sep', $yearly['series']['labels'][8]);
$eq('year label', '2026', $yearly['range']['label']);

// 4 — category breakdown (quantity + revenue), including the Uncategorized fallback
echo "\n== 4  category breakdown ==\n";
$categories = [];
foreach ($report['categories'] as $row) {
    $categories[$row['category']] = $row;
}
$eq('Cat A quantity', 3, $categories['TEST Sales Cat A']['quantity'] ?? -1);
$eq('Cat A revenue', '90', $categories['TEST Sales Cat A']['revenue'] ?? -1);
$eq('blank category falls back to Uncategorized', 3, $categories['Uncategorized']['quantity'] ?? -1);
$eq('Uncategorized revenue', '30', $categories['Uncategorized']['revenue'] ?? -1);
$check('quantity ordering (Cat A before Uncategorized on the tie)', array_key_first($categories) === 'TEST Sales Cat A');
$eq('categories_total = total sales? (items revenue only)', '180', array_sum(array_column($report['categories'], 'revenue')));

// 5 — top selling medicines
echo "\n== 5  top selling medicines ==\n";
$top = $report['top_medicines'];
$eq('top row is the highest revenue of the tied quantity', 'TEST Sales Med X', $top[0]['name']);
$eq('top row quantity', 3, $top[0]['quantity']);
$eq('top row revenue', '90', $top[0]['revenue']);
$eq('second row', 'TEST Sales Med Z', $top[1]['name']);
$eq('third row', 'TEST Sales Med Y', $top[2]['name']);

// ---------------------------------------------------------------------------------------------
// 7 — the real charge path writes the category snapshot
// ---------------------------------------------------------------------------------------------
echo "\n== 7  category snapshot written by the charge path ==\n";
$pdo->prepare('INSERT INTO medicines (name, batch_number, quantity, supplier, expiration_date, price, category) VALUES (?, ?, 40, ?, ?, ?, ?)')->execute([
    'TEST Sales Charge Med', 'TEST Sales Batch', 'TEST Supplier', date('Y-m-d', strtotime('+60 days')), 25.00, 'TEST Sales Cat Charge',
]);
$chargeMedicineId = (int) $pdo->lastInsertId();
$state['medicine_ids'][] = $chargeMedicineId;

$charge = savePaidOrderTransaction($pdo, [
    'discount_type' => 'none',
    'amount_received' => 1000,
    'items' => [['medicine_id' => $chargeMedicineId, 'quantity' => 2]],
]);
$check('charge succeeded', ($charge['success'] ?? false) === true, (string) ($charge['message'] ?? ''));
if (($charge['success'] ?? false) === true) {
    $chargeOrderId = (int) $charge['order_id'];
    $state['order_ids'][] = $chargeOrderId;
    $snapshot = $pdo->prepare('SELECT category, quantity, name FROM order_items WHERE order_id = ?');
    $snapshot->execute([$chargeOrderId]);
    $line = $snapshot->fetch(PDO::FETCH_ASSOC);
    $eq('order item stores the medicine category', 'TEST Sales Cat Charge', $line['category'] ?? '');
    $eq('order item quantity', 2, $line['quantity'] ?? 0);

    // Changing the medicine's category later must NOT rewrite the saved sale.
    $pdo->prepare('UPDATE medicines SET category = ? WHERE id = ?')->execute(['TEST Sales Cat Renamed', $chargeMedicineId]);
    $snapshot->execute([$chargeOrderId]);
    $eq('snapshot survives a later re-categorisation', 'TEST Sales Cat Charge', $snapshot->fetch(PDO::FETCH_ASSOC)['category'] ?? '');

    $todayReport = salesReport($pdo, 'daily', ['date' => date('Y-m-d')]);
    $todayChargeRow = null;
    foreach ($todayReport['categories'] as $row) {
        if ($row['category'] === 'TEST Sales Cat Charge') {
            $todayChargeRow = $row;
        }
    }
    $check("today's report includes the charge fixture", $todayChargeRow !== null && $todayChargeRow['quantity'] >= 2, $todayChargeRow ? 'qty ' . $todayChargeRow['quantity'] : 'missing');
    $check('re-categorised medicine did not leak a new category row', strpos(json_encode($todayReport['categories']), 'TEST Sales Cat Renamed') === false);
}

// ---------------------------------------------------------------------------------------------
// 8 — permission storage
// ---------------------------------------------------------------------------------------------
echo "\n== 8  page permissions ==\n";
$pdo->prepare("INSERT INTO users (username, password_hash, role, full_name, status, email_verified) VALUES ('sales_test_staff', ?, 'staff', 'Sales Test Staff', 'active', 1) ON DUPLICATE KEY UPDATE role = 'staff', status = 'active'")->execute([password_hash('SalesTest123!', PASSWORD_DEFAULT)]);
$staffId = (int) $pdo->query("SELECT id FROM users WHERE username = 'sales_test_staff'")->fetchColumn();
$state['user_ids'][] = $staffId;

$defaults = allowedPagesForUser($pdo, $staffId, 'staff');
$eq('new staff allowed pages', 'menu,history,help', implode(',', $defaults));
$pdo->prepare('DELETE FROM user_pages WHERE user_id = ?')->execute([$staffId]);
$eq('after clearing grants', 'menu,history,help', implode(',', allowedPagesForUser($pdo, $staffId, 'staff')));

$grant = setUserPages($pdo, $staffId, ['sales', 'pharmacy', 'users', 'not_a_page', 'sales'], 1, 'test-admin');
$check('grant accepted', $grant['success'] === true);
$eq('admin-only + unknown names filtered out', 'sales,pharmacy', implode(',', $grant['pages']));
$eq('effective pages after grant', 'menu,history,sales,pharmacy,help', implode(',', allowedPagesForUser($pdo, $staffId, 'staff')));
$eq('stored grants in registry order', 'sales,pharmacy', implode(',', storedPageGrants($pdo, $staffId)));

$revoke = setUserPages($pdo, $staffId, [], 1, 'test-admin');
$check('revoke accepted', $revoke['success'] === true);
$eq('effective pages after revoke', 'menu,history,help', implode(',', allowedPagesForUser($pdo, $staffId, 'staff')));

$auditRows = $pdo->prepare("SELECT details FROM admin_logs WHERE id > ? AND action = 'page_access_changed' ORDER BY id ASC");
$auditRows->execute([$logWatermark]);
$audit = $auditRows->fetchAll(PDO::FETCH_COLUMN);
$check('audit log written for grant and revoke', count($audit) === 2, count($audit) . ' row(s)');
$check('audit mentions the granted pages', isset($audit[0]) && strpos((string) $audit[0], 'Sales') !== false, (string) ($audit[0] ?? ''));

$adminPages = allowedPagesForUser($pdo, 1, 'admin');
$eq('admin always has every page', implode(',', allPageKeys()), implode(',', $adminPages));

// ---------------------------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------------------------
$pdo->prepare('DELETE FROM admin_logs WHERE id > ? AND action = ?')->execute([$logWatermark, 'page_access_changed']);
removeSalesTestFixtures($pdo, $state);
@unlink($STATE_FILE);

$leftover = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE id IN (" . implode(',', array_merge([0], array_map('intval', $state['order_ids']))) . ')')->fetchColumn();
$check('fixtures removed', $leftover === 0, "{$leftover} order(s) left");

echo "\n" . ($failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
