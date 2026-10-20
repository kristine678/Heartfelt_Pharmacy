<?php
/**
 * End-to-end HTTP tests for the Menu expired-stock rules (Menu Rule 1-5), run against a
 * live local server (e.g. XAMPP at http://localhost/BILLING).
 *
 *   Run   : php scripts/test_menu_expiry_http.php
 *   Clean : php scripts/test_menu_expiry_http.php --cleanup
 *   BASE  : BASE_URL=http://localhost/BILLING php scripts/test_menu_expiry_http.php
 *
 * Prereq: php scripts/seed_menu_test_data.php
 *
 * Covers the wire-level paths the browser uses:
 *   7a add_item.php (cart add) of an expired batch      -> rejected, code=expired_items
 *   7b charge.php direct sale of an expired batch       -> rejected, code=expired_items
 *   6  item valid when carted, expired before checkout  -> rejected naming the batch
 *      (and no order row is written)
 *      charge qty > valid stock                         -> expired stock not countable
 *   -  FEFO sale                                        -> earliest expiry drained first,
 *      expired batch untouched
 *   8  Menu page HTML                                   -> expired names absent
 *   4  menu_data.php poll                               -> sellable list, expired absent
 *   4  Inventory page + expiry_statuses                 -> expired rows visible, "Expired"
 *   4  api/alerts.php                                   -> expired batch still alerts
 *
 * --cleanup removes the test user, orders, customers and admin logs created by a run.
 * Fixtures ("TEST Menu ...") are left in place; remove them with:
 *   php scripts/seed_menu_test_data.php --cleanup
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/db.php';

$cleanup = in_array('--cleanup', array_slice($argv, 1), true);
$BASE = rtrim(getenv('BASE_URL') ?: 'http://localhost/BILLING', '/');
$STATE_FILE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'heartfelt_menu_http_state.json';

if ($cleanup) {
    $state = is_file($STATE_FILE) ? json_decode((string) file_get_contents($STATE_FILE), true) : null;
    if (!is_array($state)) {
        echo "No test state file — nothing to clean.\n";
        exit(0);
    }
    $orderWatermark = (int) ($state['order_watermark'] ?? 0);
    $removed = 0;
    // Order artifacts, children first (allocations may not cascade).
    $oldOrderIds = $pdo->query("SELECT id FROM orders WHERE id > {$orderWatermark}")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($oldOrderIds as $oid) {
        $oid = (int) $oid;
        $pdo->prepare('DELETE a FROM order_item_batch_allocations a JOIN order_items i ON i.id = a.order_item_id WHERE i.order_id = ?')->execute([$oid]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$oid]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
        $pdo->prepare("DELETE FROM admin_logs WHERE details = ?")->execute(['Paid order #' . $oid . ' via checkout.']);
        $removed++;
    }
    $customerWatermark = (int) ($state['customer_watermark'] ?? 0);
    // 'Menu Test Customer' is created only by this script (safe to remove outright);
    // 'Walk-in Guest' is a real POS name, so only remove ones above the run's watermark.
    $delCustomers = $pdo->prepare("DELETE FROM customers WHERE name = 'Menu Test Customer' OR (id > ? AND name = 'Walk-in Guest')");
    $delCustomers->execute([$customerWatermark]);
    $pdo->prepare("DELETE FROM users WHERE username = 'menu_tester'")->execute();
    unlink($STATE_FILE);
    echo "Cleaned {$removed} test order(s), " . $delCustomers->rowCount() . " customer(s), menu_tester user, state file.\n";
    echo "Fixtures kept — remove with: php scripts/seed_menu_test_data.php --cleanup\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Assertion bookkeeping
// ---------------------------------------------------------------------------
$pass = 0;
$fail = 0;
function pass(string $label, string $detail = ''): void
{
    global $pass;
    $pass++;
    echo "  [PASS] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}
function fail(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}
function check(string $label, bool $ok, string $detail = ''): void
{
    $ok ? pass($label, $detail) : fail($label, $detail);
}

// ---------------------------------------------------------------------------
// Minimal JSON/cookie HTTP client on curl
// ---------------------------------------------------------------------------
$COOKIE = '';
$GLOBALS['cookieJar'] = [];

function httpRequest(string $method, string $path, ?string $body = null, bool $asJson = true): array
{
    global $BASE, $COOKIE;
    $ch = curl_init($BASE . $path);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = $asJson ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($COOKIE !== '') {
        $headers[] = 'Cookie: ' . $COOKIE;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line): int {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/i', $line, $m)) {
                $GLOBALS['cookieJar'][$m[1]] = $m[2];
            }
            return strlen($line);
        },
    ]);
    $responseBody = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($GLOBALS['cookieJar'] !== []) {
        $pairs = [];
        foreach ($GLOBALS['cookieJar'] as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $COOKIE = implode('; ', $pairs);
    }
    $decoded = json_decode($responseBody, true);
    return ['status' => $status, 'body' => $responseBody, 'json' => is_array($decoded) ? $decoded : null];
}

$chargeBody = static function (array $items, int $customerId, array $overrides = []) use ($BASE, &$COOKIE): string {
    // Field names mirror what the POS actually posts to charge.php (note the camelCase
    // amountReceived/change keys — charge.php reads those exact names).
    $order = array_merge([
        'customer_id' => $customerId,
        'subtotal' => 0, 'discount_amount' => 0, 'discountType' => 'none', 'idNumber' => '',
        'service_charge' => 0, 'tax' => 0, 'total' => 40, 'amountReceived' => 100, 'change' => 60,
        'items' => $items,
    ], $overrides);
    return json_encode(['order' => $order]);
};

// ---------------------------------------------------------------------------
// Setup: watermarks, test customer, tester account, login
// ---------------------------------------------------------------------------
$orderWatermark = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
$customerWatermark = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM customers')->fetchColumn();
$pdo->prepare("INSERT INTO customers (name, phone, email, table_number) VALUES ('Menu Test Customer', '', '', '')")->execute();
$testCustomerId = (int) $pdo->lastInsertId();
file_put_contents($STATE_FILE, json_encode([
    'order_watermark' => $orderWatermark,
    'customer_watermark' => $customerWatermark,
    'test_customer_id' => $testCustomerId,
]));

// The Inventory page redirects role=staff by design (redirectNewStaffFromInventory), so the
// tester needs a role that may browse pharmacy management pages to check the Expired badge.
$testerHash = password_hash('MenuTest123!', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username, password_hash, role, full_name, status) VALUES ('menu_tester', ?, 'admin', 'Menu Tester', 'active')
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active', role = 'admin'")->execute([$testerHash]);

$login = httpRequest('POST', '/api/login.php', json_encode(['username' => 'menu_tester', 'password' => 'MenuTest123!']));
check('login as menu_tester', ($login['json']['success'] ?? false) === true, 'HTTP ' . $login['status']);

// Fixture ids
$ids = [];
foreach ($pdo->query("SELECT id, name, batch_number FROM medicines WHERE name LIKE 'TEST Menu %'")->fetchAll() as $row) {
    $ids[$row['name'] . '|' . $row['batch_number']] = (int) $row['id'];
}
$idOf = static function (string $name, string $batch = 'Batch 1') use ($ids): int {
    return $ids[$name . '|' . $batch] ?? 0;
};
$expiredSoloId = $idOf('TEST Menu Expired Solo');
$mixedB2Id = $idOf('TEST Menu Mixed Stock', 'Batch 2');
check('fixture rows present', $expiredSoloId > 0 && $mixedB2Id > 0, count($ids) . ' batch rows');

$orderCount = static function () use ($pdo): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
};

// ---------------------------------------------------------------------------
// 7a: cart-add endpoint refuses an expired batch
// ---------------------------------------------------------------------------
$ordersBefore = $orderCount();
$addExpired = httpRequest('POST', '/ajax/add_item.php', 'medicine_id=' . $expiredSoloId, false);
$j = $addExpired['json'] ?? [];
check('7a add_item rejects expired batch', ($j['success'] ?? null) === false && ($j['code'] ?? '') === 'expired_items', 'HTTP ' . $addExpired['status']);
check('7a add_item clear message', ($j['message'] ?? '') === 'TEST Menu Expired Solo (Batch 1) has expired and was removed from your order.', (string) ($j['message'] ?? '(none)'));

// ---------------------------------------------------------------------------
// 4: menu_data poll only returns sellable stock
// ---------------------------------------------------------------------------
$md = httpRequest('GET', '/api/menu_data.php');
$j = $md['json'] ?? [];
$byName = [];
foreach (($j['medicines'] ?? []) as $m) {
    $byName[trim((string) $m['name'])] = $m;
}
check('4 menu_data ok', ($j['ok'] ?? false) === true && ($j['today'] ?? '') === date('Y-m-d'), 'today ' . ($j['today'] ?? '?'));
check('4 poll hides expired single batch', !isset($byName['TEST Menu Expired Solo']));
check('4 poll hides all-expired medicine', !isset($byName['TEST Menu All Expired']));
check('4 poll shows mixed medicine with valid qty only', isset($byName['TEST Menu Mixed Stock']) && (int) $byName['TEST Menu Mixed Stock']['quantity'] === 60, isset($byName['TEST Menu Mixed Stock']) ? 'qty ' . $byName['TEST Menu Mixed Stock']['quantity'] : 'missing');
check('4 poll shows expires-today', isset($byName['TEST Menu Expires Today']) && (int) $byName['TEST Menu Expires Today']['quantity'] === 15);
check('4 poll shows expires-tomorrow', isset($byName['TEST Menu Expires Tomorrow']));
check('4 poll shows FEFO fixture (qty 70)', isset($byName['TEST Menu FEFO Stock']) && (int) $byName['TEST Menu FEFO Stock']['quantity'] === 70);

// ---------------------------------------------------------------------------
// 7b: direct charge of an expired batch
// ---------------------------------------------------------------------------
$ordersBefore = $orderCount();
$chargeExpired = httpRequest('POST', '/charge.php', $chargeBody([['medicine_id' => $expiredSoloId, 'quantity' => 5]], $testCustomerId));
$j = $chargeExpired['json'] ?? [];
check('7b charge rejects expired batch', ($j['success'] ?? null) === false && ($j['code'] ?? '') === 'expired_items', 'HTTP ' . $chargeExpired['status']);
check('7b charge names the batch', ($j['message'] ?? '') === 'TEST Menu Expired Solo (Batch 1) has expired and was removed from your order.', (string) ($j['message'] ?? '(none)'));
check('7b no order row written', $orderCount() === $ordersBefore, 'orders ' . $orderCount());

// ---------------------------------------------------------------------------
// 6: valid when carted, expires before checkout
// ---------------------------------------------------------------------------
$pdo->prepare("UPDATE medicines SET expiration_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE name = 'TEST Menu Mixed Stock' AND batch_number = 'Batch 2'")->execute();
$ordersBefore = $orderCount();
$chargeLate = httpRequest('POST', '/charge.php', $chargeBody([['medicine_id' => $mixedB2Id, 'quantity' => 10]], $testCustomerId));
$j = $chargeLate['json'] ?? [];
check('6 carted item that expired is rejected', ($j['success'] ?? null) === false && ($j['code'] ?? '') === 'expired_items', 'HTTP ' . $chargeLate['status']);
check('6 message names the newly expired batch', ($j['message'] ?? '') === 'TEST Menu Mixed Stock (Batch 2) has expired and was removed from your order.', (string) ($j['message'] ?? '(none)'));
check('6 rejected charge wrote no order', $orderCount() === $ordersBefore, 'orders ' . $orderCount());
$pdo->prepare("UPDATE medicines SET expiration_date = DATE_ADD(CURDATE(), INTERVAL 120 DAY) WHERE name = 'TEST Menu Mixed Stock' AND batch_number = 'Batch 2'")->execute();

// ---------------------------------------------------------------------------
// Expired stock is not countable toward a sale
// ---------------------------------------------------------------------------
$chargeTooMuch = httpRequest('POST', '/charge.php', $chargeBody([['medicine_id' => $mixedB2Id, 'quantity' => 70]], $testCustomerId));
$j = $chargeTooMuch['json'] ?? [];
check('over-qty cannot include expired stock', ($j['success'] ?? null) === false
    && ($j['message'] ?? '') === 'Not enough unexpired stock for TEST Menu Mixed Stock. Available: 60.', (string) ($j['message'] ?? '(none)'));

// ---------------------------------------------------------------------------
// FEFO: earliest-expiring valid batch drains first; expired batch untouched
// ---------------------------------------------------------------------------
$fefoB1 = $idOf('TEST Menu FEFO Stock', 'Batch 1');
$fefoB2 = $idOf('TEST Menu FEFO Stock', 'Batch 2');
$chargeFefo = httpRequest('POST', '/charge.php', $chargeBody([['medicine_id' => $fefoB1, 'quantity' => 40]], $testCustomerId));
$j = $chargeFefo['json'] ?? [];
$fefoOrderId = (int) ($j['order_id'] ?? 0);
check('FEFO sale succeeds', ($j['success'] ?? null) === true && $fefoOrderId > 0,
    $fefoOrderId > 0 ? 'order #' . $fefoOrderId : 'HTTP ' . $chargeFefo['status'] . ' — ' . (string) ($j['message'] ?? '(no message)'));
if ($fefoOrderId > 0) {
    $alloc = $pdo->prepare('SELECT a.batch_number, SUM(a.allocated_quantity) AS q FROM order_item_batch_allocations a JOIN order_items i ON i.id = a.order_item_id WHERE i.order_id = ? GROUP BY a.batch_number');
    $alloc->execute([$fefoOrderId]);
    $allocMap = [];
    foreach ($alloc->fetchAll() as $row) {
        $allocMap[$row['batch_number']] = (int) $row['q'];
    }
    check('FEFO drained Batch 1 (+10d) first', ($allocMap['Batch 1'] ?? 0) === 30, json_encode($allocMap));
    check('FEFO topped up from Batch 2 (+200d)', ($allocMap['Batch 2'] ?? 0) === 10, json_encode($allocMap));
}
$qty = static function (int $id) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT quantity FROM medicines WHERE id = ?');
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn();
};
check('FEFO Batch 1 now empty', $qty($fefoB1) === 0, 'qty ' . $qty($fefoB1));
check('FEFO Batch 2 reduced by remainder', $qty($fefoB2) === 30, 'qty ' . $qty($fefoB2));
check('expired Mixed Batch 1 untouched by all charges', $qty($idOf('TEST Menu Mixed Stock', 'Batch 1')) === 25, 'qty ' . $qty($idOf('TEST Menu Mixed Stock', 'Batch 1')));
// Restore FEFO fixture stock so the seeded state stays reproducible.
$pdo->prepare('UPDATE medicines SET quantity = 30 WHERE id = ?')->execute([$fefoB1]);
$pdo->prepare('UPDATE medicines SET quantity = 40 WHERE id = ?')->execute([$fefoB2]);

// ---------------------------------------------------------------------------
// 8 + 4: the rendered Menu page and Inventory page
// ---------------------------------------------------------------------------
$menuItem = httpRequest('GET', '/menu.php');
check('8 Menu HTML omits expired medicine', strpos($menuItem['body'], 'TEST Menu Expired Solo') === false);
check('8 Menu HTML omits all-expired medicine', strpos($menuItem['body'], 'TEST Menu All Expired') === false);
check('8 Menu HTML still lists mixed medicine', strpos($menuItem['body'], 'TEST Menu Mixed Stock') !== false);
check('8 Menu HTML still lists expires-today', strpos($menuItem['body'], 'TEST Menu Expires Today') !== false);

require_once __DIR__ . '/../includes/medicine_helpers.php';
refreshExpiryStatuses($pdo, getPharmacyThreshold($pdo));
$statuses = [];
foreach ($pdo->query("SELECT m.name, m.batch_number, e.status FROM medicines m JOIN expiry_statuses e ON e.medicine_id = m.id WHERE m.name LIKE 'TEST Menu %'")->fetchAll() as $row) {
    $statuses[$row['name'] . '|' . $row['batch_number']] = $row['status'];
}
check('Inventory data marks expired solo "Expired"', ($statuses['TEST Menu Expired Solo|Batch 1'] ?? '') === 'Expired', (string) ($statuses['TEST Menu Expired Solo|Batch 1'] ?? 'missing'));
check('Inventory data marks both all-expired batches "Expired"', ($statuses['TEST Menu All Expired|Batch 1'] ?? '') === 'Expired' && ($statuses['TEST Menu All Expired|Batch 2'] ?? '') === 'Expired');
check('Inventory data keeps valid batches non-expired', ($statuses['TEST Menu Mixed Stock|Batch 2'] ?? '') !== 'Expired', (string) ($statuses['TEST Menu Mixed Stock|Batch 2'] ?? 'missing'));

$inventory = httpRequest('GET', '/pharmacy_inventory.php');
check('Inventory page still shows expired medicine', strpos($inventory['body'], 'TEST Menu Expired Solo') !== false, 'HTTP ' . $inventory['status']);
check('Inventory page renders Expired badge for it', preg_match('/TEST Menu Expired Solo.{0,800}Expired|Expired.{0,800}TEST Menu Expired Solo/s', $inventory['body']) === 1);

// ---------------------------------------------------------------------------
// 4: expiry alerts still fire for the expired batches
// ---------------------------------------------------------------------------
$alerts = httpRequest('GET', '/api/alerts.php');
$j = $alerts['json'] ?? [];
check('alerts endpoint ok', ($j['ok'] ?? false) === true, 'HTTP ' . $alerts['status']);
$expiredAlerts = $j['groups']['expired'] ?? [];
$names = array_map(static fn (array $row): string => $row['name'] . '|' . $row['batch_number'], $expiredAlerts);
check('alerts still flag expired solo batch', in_array('TEST Menu Expired Solo|Batch 1', $names, true), count($names) . ' expired row(s)');
check('alerts still flag both all-expired batches', in_array('TEST Menu All Expired|Batch 1', $names, true) && in_array('TEST Menu All Expired|Batch 2', $names, true));
check('alerts flag the mixed medicine expired batch', in_array('TEST Menu Mixed Stock|Batch 1', $names, true));

// ---------------------------------------------------------------------------
echo "\n{$pass} passed, {$fail} failed.\n";
if ($fail === 0) {
    echo "Clean up test artifacts with: php scripts/test_menu_expiry_http.php --cleanup\n";
    echo "Remove seed fixtures with     : php scripts/seed_menu_test_data.php --cleanup\n";
}
exit($fail === 0 ? 0 : 1);
