<?php
/**
 * End-to-end HTTP tests for TASK 1 (Sales page), TASK 2 (staff restricted to Menu/History/Help)
 * and TASK 3 (granting extra pages from Users), run against a live local server.
 *
 *   Run   : php scripts/test_sales_permissions_http.php
 *   Clean : php scripts/test_sales_permissions_http.php --cleanup
 *   BASE  : BASE_URL=http://localhost/BILLING php scripts/test_sales_permissions_http.php
 *
 * Covers the wire-level behaviour the browser uses:
 *   - staff sidebar shows ONLY Menu, History, Help Center (no Sales, no bell, no Inventory button)
 *   - hidden pages redirect staff to the Menu with "You don't have access to this page."
 *   - every guarded JSON endpoint answers 403 for a staff account without the page
 *   - admin keeps the full sidebar, with Sales directly below History
 *   - api/sales.php totals match an independent SQL aggregate for the same window
 *   - the Users page "Manage access" endpoint grants Sales (staff can then open it) and revokes it
 *     again (staff is blocked on the very next request)
 *   - staff cannot call the grant endpoint, and admin-only pages cannot be granted
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/db.php';

$BASE = rtrim(getenv('BASE_URL') ?: 'http://localhost/BILLING', '/');
$STATE_FILE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'heartfelt_sales_http_state.json';
$cleanup = in_array('--cleanup', array_slice($argv, 1), true);

$runCleanup = static function (PDO $pdo, array $state): void {
    foreach (($state['medicine_ids'] ?? []) as $medicineId) {
        $medicineId = (int) $medicineId;
        $pdo->prepare('DELETE FROM expiry_statuses WHERE medicine_id = ?')->execute([$medicineId]);
        $pdo->prepare('DELETE FROM medicines WHERE id = ?')->execute([$medicineId]);
    }
    if (!empty($state['created_category'])) {
        $pdo->prepare('DELETE FROM categories WHERE name = ?')->execute([(string) $state['created_category']]);
    }
    foreach (($state['order_ids'] ?? []) as $orderId) {
        $orderId = (int) $orderId;
        $pdo->prepare('DELETE a FROM order_item_batch_allocations a JOIN order_items i ON i.id = a.order_item_id WHERE i.order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
    }
    if (!empty($state['customer_id'])) {
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([(int) $state['customer_id']]);
    }
    foreach (($state['user_ids'] ?? []) as $userId) {
        $pdo->prepare('DELETE FROM user_pages WHERE user_id = ?')->execute([(int) $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $userId]);
    }
    if (isset($state['log_watermark'])) {
        $pdo->prepare("DELETE FROM admin_logs WHERE id > ? AND action = 'page_access_changed'")->execute([(int) $state['log_watermark']]);
    }
};

if ($cleanup) {
    $state = is_file($STATE_FILE) ? json_decode((string) file_get_contents($STATE_FILE), true) : null;
    if (!is_array($state)) {
        echo "No state file — nothing to clean.\n";
        exit(0);
    }
    $runCleanup($pdo, $state);
    @unlink($STATE_FILE);
    echo "Cleaned this suite's fixtures (users, page grants, orders, customer, audit rows).\n";
    exit(0);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

// ---------------------------------------------------------------------------------------------
// Minimal curl client with an independent cookie jar per "browser"
// ---------------------------------------------------------------------------------------------
function newClient(): array
{
    return ['cookie' => ''];
}

function request(array &$client, string $method, string $path, ?string $body = null, bool $asJson = true): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $headers = ['Accept: ' . ($asJson ? 'application/json' : 'text/html')];
    if ($body !== null) {
        $headers[] = $asJson
            ? 'Content-Type: application/json'
            : 'Content-Type: application/x-www-form-urlencoded;charset=UTF-8';
    }
    if ($client['cookie'] !== '') {
        $headers[] = 'Cookie: ' . $client['cookie'];
    }
    $jar = [];
    $location = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$jar, &$location): int {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/i', $line, $m)) {
                $jar[$m[1]] = $m[2];
            }
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
            return strlen($line);
        },
    ]);
    $responseBody = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $client['cookie'] = implode('; ', $pairs);
    }
    $decoded = json_decode($responseBody, true);

    return [
        'status' => $status,
        'body' => $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
        'location' => $location,
    ];
}

$extractCsrf = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value="([^"]+)"\s+name="csrf_token"/', $html, $m)) {
        return $m[1];
    }
    return '';
};

$login = static function (array &$client, string $username, string $password): bool {
    $result = request($client, 'POST', '/api/login.php', json_encode(['username' => $username, 'password' => $password]));
    return ($result['json']['success'] ?? false) === true;
};

// ---------------------------------------------------------------------------------------------
// Fixtures: an admin, a staff account, and one paid sale today in its own category
// ---------------------------------------------------------------------------------------------
$state = ['user_ids' => [], 'order_ids' => [], 'medicine_ids' => [], 'customer_id' => 0, 'created_category' => 'TEST Http New Category', 'log_watermark' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_logs')->fetchColumn()];

$staffPassword = 'SalesHttp123!';
$pdo->prepare("INSERT INTO users (username, password_hash, role, full_name, status, email_verified) VALUES ('sales_http_staff', ?, 'staff', 'Sales HTTP Staff', 'active', 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'staff', status = 'active', email_verified = 1")->execute([password_hash($staffPassword, PASSWORD_DEFAULT)]);
$staffId = (int) $pdo->query("SELECT id FROM users WHERE username = 'sales_http_staff'")->fetchColumn();
$state['user_ids'][] = $staffId;

$pdo->prepare("INSERT INTO users (username, password_hash, role, full_name, status, email_verified) VALUES ('sales_http_admin', ?, 'admin', 'Sales HTTP Admin', 'active', 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin', status = 'active', email_verified = 1")->execute([password_hash($staffPassword, PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'sales_http_admin'")->fetchColumn();
$state['user_ids'][] = $adminId;

// Start from a clean slate: a staff account gets ONLY the default pages.
$pdo->prepare('DELETE FROM user_pages WHERE user_id = ?')->execute([$staffId]);

$pdo->prepare("INSERT INTO customers (name, phone, email, table_number) VALUES ('TEST Sales Http Customer', '', '', '')")->execute();
$state['customer_id'] = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO orders (customer_id, order_type, status, subtotal, discount, discount_type, service_charge, tax, total, amount_received, change_amount, paid_at) VALUES (?, "Dine In", "paid", 100, 0, "none", 0, 0, 100, 100, 0, NOW())')->execute([$state['customer_id']]);
$httpOrderId = (int) $pdo->lastInsertId();
$state['order_ids'][] = $httpOrderId;
$pdo->prepare('INSERT INTO order_items (order_id, medicine_id, product_id, quantity, name, price, image, category) VALUES (?, 0, 0, 2, ?, 50.00, NULL, ?)')->execute([$httpOrderId, 'TEST Http Sales Med', 'TEST Http Cat']);

file_put_contents($STATE_FILE, json_encode($state));

// ---------------------------------------------------------------------------------------------
// STAFF: default pages only
// ---------------------------------------------------------------------------------------------
echo "== TASK 2  staff default access ==\n";
$staff = newClient();
check('staff can sign in', $login($staff, 'sales_http_staff', $staffPassword));

$menu = request($staff, 'GET', '/menu.php', null, false);
check('staff Menu loads', $menu['status'] === 200, 'HTTP ' . $menu['status']);
check('sidebar shows History', strpos($menu['body'], 'history.php') !== false);
check('sidebar shows Help Center', strpos($menu['body'], 'help.php') !== false);
check('sidebar HIDES Sales', strpos($menu['body'], 'sales.php') === false);
check('sidebar HIDES Users', strpos($menu['body'], 'users.php') === false);
check('sidebar HIDES Setting', strpos($menu['body'], 'settings.php') === false);
check('sidebar HIDES Pharmacy', strpos($menu['body'], 'pharmacy_dashboard.php') === false);
check('sidebar HIDES the Inventory Alerts bell', strpos($menu['body'], 'id="alert-bell"') === false);
check('Menu header HIDES the Inventory Dashboard button', strpos($menu['body'], 'inventory-dashboard.php') === false);
check('Menu offers Create New Order', strpos($menu['body'], 'createNewOrderBtn') !== false);
check('allowed pages exposed to the page', strpos($menu['body'], '"menu","history","help"') !== false || strpos($menu['body'], '["menu","history","help"]') !== false);
check('alert pop-up shell not shipped to staff', strpos($menu['body'], 'medicine-alert-modal') === false);
check('alerts.js not shipped to staff', strpos($menu['body'], 'alerts.js') === false);

echo "\n== TASK 2  hidden pages are blocked by URL ==\n";
foreach ([
    'sales.php' => 'Sales page',
    'inventory-dashboard.php' => 'Inventory Dashboard page',
    'pharmacy_dashboard.php' => 'Pharmacy page',
    'pharmacy_inventory.php' => 'Pharmacy inventory page',
    'pharmacy_alerts.php' => 'Inventory Alerts page',
    'users.php' => 'Users page',
    'settings.php' => 'Setting page',
] as $path => $label) {
    $blocked = request($staff, 'GET', '/' . $path, null, false);
    $redirected = $blocked['status'] === 302 && strpos($blocked['location'], 'menu.php') !== false;
    check($label . ' redirects staff to the Menu', $redirected, 'HTTP ' . $blocked['status'] . ' -> ' . $blocked['location']);
}

$afterBlock = request($staff, 'GET', '/menu.php', null, false);
check('Menu shows "You don\'t have access to this page."', strpos($afterBlock['body'], "have access to this page") !== false);

foreach ([
    '/api/sales.php?period=daily' => 'sales endpoint',
    '/api/inventory.php' => 'inventory endpoint',
    '/api/alerts.php' => 'alerts endpoint',
] as $path => $label) {
    $api = request($staff, 'GET', $path);
    check($label . ' answers 403 for staff', $api['status'] === 403, 'HTTP ' . $api['status']);
}

echo "\n== TASK 2  pages staff DO have ==\n";
check('History still loads for staff', request($staff, 'GET', '/history.php', null, false)['status'] === 200);
check('Help Center still loads for staff', request($staff, 'GET', '/help.php', null, false)['status'] === 200);
check('menu_data poll still works for staff', (request($staff, 'GET', '/api/menu_data.php')['json']['ok'] ?? false) === true);

// ---------------------------------------------------------------------------------------------
// ADMIN: everything, with Sales below History
// ---------------------------------------------------------------------------------------------
echo "\n== TASK 1  admin sees Sales below History ==\n";
$admin = newClient();
check('admin can sign in', $login($admin, 'sales_http_admin', $staffPassword));

$adminMenu = request($admin, 'GET', '/menu.php', null, false);
check('admin sidebar shows Sales', strpos($adminMenu['body'], 'sales.php') !== false);
check('admin sidebar shows Users, Setting, Pharmacy', strpos($adminMenu['body'], 'users.php') !== false && strpos($adminMenu['body'], 'settings.php') !== false && strpos($adminMenu['body'], 'pharmacy_dashboard.php') !== false);
check('admin keeps the alert bell', strpos($adminMenu['body'], 'id="alert-bell"') !== false);
check('admin keeps the Inventory Dashboard button', strpos($adminMenu['body'], 'inventory-dashboard.php') !== false);
check('Sales sits directly below History', strpos($adminMenu['body'], 'history.php') < strpos($adminMenu['body'], 'sales.php'));
check('Sales appears before Users in the sidebar', strpos($adminMenu['body'], 'sales.php') < strpos($adminMenu['body'], 'users.php'));

$salesPage = request($admin, 'GET', '/sales.php', null, false);
check('Sales page loads', $salesPage['status'] === 200, 'HTTP ' . $salesPage['status']);
check('Sales page renders the Daily/Monthly/Yearly tabs', strpos($salesPage['body'], 'sales-tab-monthly') !== false && strpos($salesPage['body'], 'sales-tab-yearly') !== false);
check('Sales page renders the KPI cards', strpos($salesPage['body'], 'sales-kpi-revenue') !== false && strpos($salesPage['body'], 'sales-kpi-average') !== false);
check('Sales page renders both charts', strpos($salesPage['body'], 'sales-trend-chart') !== false && strpos($salesPage['body'], 'sales-category-chart') !== false);
check('Sales page ships Chart.js', strpos($salesPage['body'], 'Chart.js') !== false);
check('Sales page ships its own stylesheet + script', strpos($salesPage['body'], 'sales.css') !== false && strpos($salesPage['body'], 'sales.js') !== false);
check('Sales page renders the top-selling table', strpos($salesPage['body'], 'Top selling medicines') !== false);

echo "\n== TASK 1  endpoint agrees with the database ==\n";
$today = date('Y-m-d');
$sql = $pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM orders WHERE status = 'paid' AND paid_at >= ? AND paid_at < DATE_ADD(?, INTERVAL 1 DAY)");
$sql->execute([$today . ' 00:00:00', $today . ' 00:00:00']);
$expected = $sql->fetch(PDO::FETCH_ASSOC);

$daily = request($admin, 'GET', '/api/sales.php?period=daily&date=' . $today);
check('daily endpoint ok', ($daily['json']['ok'] ?? false) === true);
check('daily revenue equals the SQL sum for today', abs((float) ($daily['json']['totals']['revenue'] ?? -1) - (float) $expected['revenue']) < 0.005, 'api ' . ($daily['json']['totals']['revenue'] ?? '?') . ' vs sql ' . $expected['revenue']);
check('daily order count equals the SQL count', (int) ($daily['json']['totals']['orders'] ?? -1) === (int) $expected['orders']);

$fixtureCategory = null;
foreach (($daily['json']['categories'] ?? []) as $row) {
    if ($row['category'] === 'TEST Http Cat') {
        $fixtureCategory = $row;
    }
}
check('category breakdown includes the test sale (donut data)', $fixtureCategory !== null && (int) $fixtureCategory['quantity'] === 2 && abs((float) $fixtureCategory['revenue'] - 100.0) < 0.005, $fixtureCategory ? 'qty ' . $fixtureCategory['quantity'] . ', revenue ' . $fixtureCategory['revenue'] : 'missing');
check('peso-formatted total is server-rendered on first paint', strpos($salesPage['body'], '₱') !== false);

$monthly = request($admin, 'GET', '/api/sales.php?period=monthly&month=' . date('Y-m'));
check('monthly endpoint ok', ($monthly['json']['ok'] ?? false) === true && count($monthly['json']['series']['revenue'] ?? []) === (int) date('t'));
$yearly = request($admin, 'GET', '/api/sales.php?period=yearly&year=' . date('Y'));
check('yearly endpoint ok with 12 buckets', ($yearly['json']['ok'] ?? false) === true && count($yearly['json']['series']['revenue'] ?? []) === 12);
check('previous-period comparison present', isset($daily['json']['change']['revenue'], $daily['json']['previous_range']['label']));

// ---------------------------------------------------------------------------------------------
// TASK 1: the Pharmacy page sets a medicine's category from a dropdown (+ add new)
// ---------------------------------------------------------------------------------------------
 echo "\n== TASK 1  category dropdown on the Pharmacy form ==\n";
$form = request($admin, 'GET', '/pharmacy_form.php', null, false);
check('Add Medicine form loads for admin', $form['status'] === 200, 'HTTP ' . $form['status']);
check('Category is a dropdown (select)', strpos($form['body'], 'id="medicine-category"') !== false);
foreach (['Pain Relief', 'Vitamins', 'Antibiotics', 'Cough &amp; Cold', 'Uncategorized'] as $builtIn) {
    check('built-in category offered: ' . html_entity_decode($builtIn), strpos($form['body'], '>' . $builtIn . '<') !== false);
}
check('“+ Add new category…” option present', strpos($form['body'], '__new__') !== false);
check('new-category text field present', strpos($form['body'], 'medicine-category-new') !== false);

$medicineName = 'TEST Http Category Med';
$newCategoryBody = http_build_query([
    'medicine_id' => 0,
    'sync_inventory' => 1,
    'name' => $medicineName,
    'batch_number' => '',
    'supplier' => 'TEST Http Supplier',
    'quantity' => 7,
    'expiration_date' => date('Y-m-d', strtotime('+90 days')),
    'price' => 12.5,
    'category' => '__new__',
    'new_category' => 'TEST Http New Category',
]);
$saved = request($admin, 'POST', '/pharmacy_form.php', $newCategoryBody, false);
check('saving a medicine with a NEW category redirects to inventory', $saved['status'] === 302, 'HTTP ' . $saved['status']);
$savedRow = $pdo->prepare('SELECT id, category FROM medicines WHERE name = ? LIMIT 1');
$savedRow->execute([$medicineName]);
$medicine = $savedRow->fetch(PDO::FETCH_ASSOC);
check('the new category name was stored on the medicine', ($medicine['category'] ?? '') === 'TEST Http New Category', (string) ($medicine['category'] ?? 'missing'));
$state['medicine_ids'][] = (int) ($medicine['id'] ?? 0);
$createdCategory = (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE name = 'TEST Http New Category'")->fetchColumn();
check('the new category was added to the shared categories list', $createdCategory === 1);

// ---------------------------------------------------------------------------------------------
// TASK 3: grant Sales from the Users page, then revoke it
// ---------------------------------------------------------------------------------------------
echo "\n== TASK 3  Users -> Manage access ==\n";
$usersPage = request($admin, 'GET', '/users.php', null, false);
check('Users page loads for admin', $usersPage['status'] === 200);
check('Users table renders the Access column', strpos($usersPage['body'], 'access-badge') !== false);
check('every staff row gets a Manage access button', strpos($usersPage['body'], 'user-access-button') !== false);
check('the modal lists the grantable pages', strpos($usersPage['body'], 'perm-option') !== false && strpos($usersPage['body'], 'value="sales"') !== false);
check('the modal locks the default pages on', strpos($usersPage['body'], 'perm-locked-item') !== false);
check('Users/Setting are not grantable in the modal', strpos($usersPage['body'], 'value="settings"') === false && strpos($usersPage['body'], 'value="users"') === false);

$csrf = $extractCsrf($usersPage['body']);
check('Users CSRF token found', $csrf !== '');

$staffBody = http_build_query(['action' => 'save_pages', 'user_id' => $staffId, 'csrf_token' => 'wrong-token', 'pages' => ['sales']]);
$badCsrf = request($admin, 'POST', '/users.php', $staffBody, false);
check('wrong CSRF token is rejected', $badCsrf['status'] === 403, 'HTTP ' . $badCsrf['status']);

$grantBody = http_build_query(['action' => 'save_pages', 'user_id' => $staffId, 'csrf_token' => $csrf]) . '&pages%5B%5D=sales';
$grant = request($admin, 'POST', '/users.php', $grantBody, false);
check('admin can grant Sales', ($grant['json']['success'] ?? false) === true, (string) ($grant['json']['message'] ?? ''));

$grantedRow = $pdo->prepare('SELECT page FROM user_pages WHERE user_id = ?');
$grantedRow->execute([$staffId]);
check('grant stored in the database', in_array('sales', $grantedRow->fetchAll(PDO::FETCH_COLUMN), true));

$staffMenuGranted = request($staff, 'GET', '/menu.php', null, false);
check('staff sidebar now shows Sales', strpos($staffMenuGranted['body'], 'sales.php') !== false);
check('staff sidebar still hides Users/Setting', strpos($staffMenuGranted['body'], 'users.php') === false && strpos($staffMenuGranted['body'], 'settings.php') === false);
check('staff sidebar still hides the alert bell', strpos($staffMenuGranted['body'], 'id="alert-bell"') === false);

$staffSales = request($staff, 'GET', '/sales.php', null, false);
check('staff can open the Sales page now', $staffSales['status'] === 200, 'HTTP ' . $staffSales['status']);
check('staff Sales page shows the charts', strpos($staffSales['body'], 'sales-category-chart') !== false);
$staffApi = request($staff, 'GET', '/api/sales.php?period=daily&date=' . $today);
check('staff can read the sales endpoint now', ($staffApi['json']['ok'] ?? false) === true && isset($staffApi['json']['totals']));

echo "\n== TASK 3  guards on the grant endpoint ==\n";
$staffGrantAttempt = request($staff, 'POST', '/users.php', $grantBody, false);
check('staff cannot call save_pages', $staffGrantAttempt['status'] === 403, 'HTTP ' . $staffGrantAttempt['status']);

$adminOnlyBody = http_build_query(['action' => 'save_pages', 'user_id' => $staffId, 'csrf_token' => $csrf]) . '&pages%5B%5D=settings';
$adminOnly = request($admin, 'POST', '/users.php', $adminOnlyBody, false);
check('granting Setting is rejected', $adminOnly['status'] === 422, 'HTTP ' . $adminOnly['status']);
$unknownBody = http_build_query(['action' => 'save_pages', 'user_id' => $staffId, 'csrf_token' => $csrf]) . '&pages%5B%5D=evil_page';
$unknown = request($admin, 'POST', '/users.php', $unknownBody, false);
check('granting an unknown page is rejected', $unknown['status'] === 422, 'HTTP ' . $unknown['status']);
check('rejected requests did not change the grant', (int) $pdo->query('SELECT COUNT(*) FROM user_pages WHERE user_id = ' . $staffId)->fetchColumn() === 1);

echo "\n== TASK 3  revocation takes effect immediately ==\n";
$revokeBody = http_build_query(['action' => 'save_pages', 'user_id' => $staffId, 'csrf_token' => $csrf]);
$revoke = request($admin, 'POST', '/users.php', $revokeBody, false);
check('admin can revoke Sales', ($revoke['json']['success'] ?? false) === true);
check('grant row removed', (int) $pdo->query('SELECT COUNT(*) FROM user_pages WHERE user_id = ' . $staffId)->fetchColumn() === 0);

$staffSalesAfter = request($staff, 'GET', '/sales.php', null, false);
check('staff is blocked from Sales again immediately', $staffSalesAfter['status'] === 302 && strpos($staffSalesAfter['location'], 'menu.php') !== false, 'HTTP ' . $staffSalesAfter['status'] . ' -> ' . $staffSalesAfter['location']);
$staffApiAfter = request($staff, 'GET', '/api/sales.php?period=daily');
check('sales endpoint blocks the staff account again', $staffApiAfter['status'] === 403, 'HTTP ' . $staffApiAfter['status']);
$staffMenuAfter = request($staff, 'GET', '/menu.php', null, false);
check('Sales link is gone from the sidebar again', strpos($staffMenuAfter['body'], 'sales.php') === false);

$audit = (int) $pdo->query("SELECT COUNT(*) FROM admin_logs WHERE id > " . (int) $state['log_watermark'] . " AND action = 'page_access_changed'")->fetchColumn();
check('both changes were audited', $audit === 2, $audit . ' audit row(s)');

// ---------------------------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------------------------
$runCleanup($pdo, $state);
@unlink($STATE_FILE);

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED\n" : "{$fail} of " . ($pass + $fail) . " CHECK(S) FAILED\n");
exit($fail === 0 ? 0 : 1);
