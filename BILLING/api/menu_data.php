<?php
// GET /api/menu_data.php — fresh sellable inventory for the POS Menu poll (Menu Rule 5).
//
// The POS calls this every 5 minutes so a batch that crossed midnight disappears from the
// grid (and from the cart, via the client-side prune) without a manual page reload.
//
// Filtering happens server-side in getSellableMedicineGroups() (config/db.php):
//   * expired batches (expiry_date < today, app timezone) are dropped before a medicine
//     group is created, so an all-expired medicine never appears here;
//   * quantity is the SUM over the remaining, non-expired batches only;
//   * medicines whose batches are all still dated but empty remain, as "Out of Stock".
// The response can therefore never contain expired stock, regardless of what the browser
// holds in localStorage.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/permissions.php';

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};

// JSON 401 instead of requireLogin()'s HTML redirect, so the poll degrades gracefully
// (the client keeps its last snapshot and the next full page load re-authenticates).
if (empty($_SESSION['user_id'])) {
    $respond(['ok' => false, 'error' => 'Authentication required.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
// TASK 2/3: this feeds the POS grid, so it follows the menu page permission.
if (($_SESSION['role'] ?? 'staff') !== 'admin' && !userCanAccess('menu')) {
    $respond(['ok' => false, 'error' => PAGE_ACCESS_DENIED_MESSAGE, 'message' => PAGE_ACCESS_DENIED_MESSAGE], 403);
}

try {
    $categories = $pdo->query('SELECT name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
    $categories = array_values(array_unique(array_filter(array_map('trim', $categories))));

    $respond([
        'ok' => true,
        // The server's date in the app timezone — lets the client double-check staleness.
        'today' => date('Y-m-d'),
        'medicines' => getSellableMedicineGroups($pdo),
        'categories' => $categories,
    ]);
} catch (Throwable $e) {
    error_log('[Heartfelt Pharmacy] menu_data poll failed: ' . $e->getMessage());
    $respond(['ok' => false, 'error' => 'Could not load inventory.'], 500);
}
