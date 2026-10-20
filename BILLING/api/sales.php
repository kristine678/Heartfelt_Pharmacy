<?php
/**
 * GET /api/sales.php — the Sales page's data endpoint.
 *
 * Serves exactly the same salesReport() payload sales.php renders on first paint, so the tabs and
 * the chart refreshes replace numbers with identical ones. All aggregation happens in SQL.
 *
 * Access: the `sales` page permission. An admin always has it; a staff account only after an admin
 * grants it from the Users page. Anyone else gets HTTP 403 JSON (TASK 2/3).
 *
 *   ?period=daily&date=2026-10-16
 *   ?period=monthly&month=2026-10
 *   ?period=yearly&year=2026
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/permissions.php';

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};

// JSON 401 (not an HTML redirect) so the page's fetch degrades gracefully.
if (empty($_SESSION['user_id'])) {
    $respond(['ok' => false, 'error' => 'Authentication required.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
// Page-permission gate, enforced on the server for API callers too.
if (($_SESSION['role'] ?? 'staff') !== 'admin' && !userCanAccess('sales')) {
    $respond(['ok' => false, 'error' => PAGE_ACCESS_DENIED_MESSAGE, 'message' => PAGE_ACCESS_DENIED_MESSAGE], 403);
}

try {
    $report = salesReport($pdo, (string)($_GET['period'] ?? 'daily'), [
        'date' => $_GET['date'] ?? date('Y-m-d'),
        'month' => $_GET['month'] ?? date('Y-m'),
        'year' => $_GET['year'] ?? date('Y'),
    ]);
} catch (Throwable $exception) {
    error_log('[Heartfelt Pharmacy] sales endpoint failed: ' . $exception->getMessage());
    $respond(['ok' => false, 'error' => 'Could not load the sales figures.'], 500);
}

$respond(['ok' => true] + $report);
