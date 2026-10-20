<?php
// GET /api/inventory.php - the ONE inventory endpoint (Task 3).
//
// Both the Pharmacy page and the POS "Inventory Dashboard" button render the shared
// includes/inventory_view.php component from this exact payload, so the two views can never show
// different numbers. It is also what the shared refresher (assets/js/inventory-view.js) refetches
// after a POS sale, which is how stock updates everywhere without a manual reload.
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/inventory_view.php';
require_once __DIR__ . '/../includes/permissions.php';

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};

// JSON 401 instead of requireLogin()'s HTML redirect, so the in-page refresh degrades gracefully.
if (empty($_SESSION['user_id'])) {
    $respond(['ok' => false, 'error' => 'Authentication required.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
// TASK 2/3: shared by the Inventory Dashboard and the Pharmacy inventory page, so either page
// permission grants it. A staff account with neither gets HTTP 403 instead of stock data.
if (($_SESSION['role'] ?? 'staff') !== 'admin' && !userCanAccessAny(['inventory_dashboard', 'pharmacy'])) {
    $respond(['ok' => false, 'error' => PAGE_ACCESS_DENIED_MESSAGE, 'message' => PAGE_ACCESS_DENIED_MESSAGE], 403);
}

try {
    $data = inventoryViewData($pdo);
    $respond([
        'ok' => true,
        'generated_at' => $data['generated_at'],
        'generated_label' => $data['generated_label'],
        'thresholds' => $data['thresholds'],
        'summary' => $data['summary'],
        'rows' => $data['rows'],
    ]);
} catch (Throwable $exception) {
    error_log('[Heartfelt Pharmacy] inventory endpoint failed: ' . $exception->getMessage());
    $respond(['ok' => false, 'error' => 'Could not load the inventory.'], 500);
}
