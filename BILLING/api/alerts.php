<?php
// GET /api/alerts.php — medicine inventory alerts for the pop-up.
//
// Two alert families, computed in SQL (never in the frontend):
//
//  1. STOCK alerts are per MEDICINE. Batches of the same medicine are grouped by a
//     normalized name key (lowercased, trimmed, internal whitespace removed, so
//     "Paracetamol 500mg" == "paracetamol 500 mg "), and the quantity considered is the
//     SUM across that medicine's batches that are NOT expired — expired stock cannot be
//     sold, so it must not mask an out-of-stock state.
//       * out_of_stock: total = 0          (one row per medicine)
//       * low_stock:    0 < total <= stock_threshold (one row per medicine)
//
//  2. EXPIRY alerts are per BATCH, always — a batch expiring soon alerts even when a
//     fresher batch of the same medicine is fine. Batches with quantity 0 are skipped:
//     there is nothing left on the shelf to expire.
//       * expired:         days_remaining < 0
//       * expiring_soon:   0 <= days_remaining <= threshold (threshold day itself counts)
//
// Thresholds are configuration, not constants: alert_threshold_days and stock_threshold
// come from pharmacy_settings (Settings page). Days remaining is computed server-side.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/medicine_helpers.php';
require_once __DIR__ . '/../includes/permissions.php';

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};

if (empty($_SESSION['user_id'])) {
    $respond(['ok' => false, 'error' => 'Authentication required.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
// TASK 2/3: the alert bell/pop-up is gated by the inventory_alerts page. The shell is not even
// shipped without it, and this endpoint refuses to serve alert data to anyone who lacks it.
if (($_SESSION['role'] ?? 'staff') !== 'admin' && !userCanAccess('inventory_alerts')) {
    $respond(['ok' => false, 'error' => PAGE_ACCESS_DENIED_MESSAGE, 'message' => PAGE_ACCESS_DENIED_MESSAGE], 403);
}

try {
    // Configuration values (>= 1) — never hardcoded here.
    $thresholdDays = getPharmacyThreshold($pdo);
    $stockThreshold = getPharmacyStockThreshold($pdo);

    // "Today" is PHP's clock — the same clock the seed script, the page displays and
    // computeMedicineStatus() use. Binding it keeps MySQL's session clock (which can run
    // in a different timezone) from ever disagreeing about "expired" or "30 days".
    $today = date('Y-m-d');

    // Medicine identity: case- and whitespace-insensitive, so "Paracetamol 500mg" and
    // "paracetamol 500 mg " are the same medicine. Portable expression (no REGEXP_REPLACE
    // dependency) — TRIM handles outer whitespace, REPLACE handles the inner kind.
    $medicineKey = "LOWER(REPLACE(REPLACE(TRIM(m.name), ' ', ''), '\t', ''))";

    // Live-batch predicate: a batch counts toward stock only while it is sellable.
    $liveBatch = "(m.expiration_date IS NULL OR m.expiration_date >= :today)";

    // --- Query 1: stock per MEDICINE, grouped in SQL ---
    // EVERY medicine is grouped (not just those with live batches), but only LIVE
    // (non-expired) batches contribute quantity: a medicine whose batches are all expired
    // or empty totals 0 and must still alert "Out of stock" instead of silently vanishing.
    // HAVING does the alerting filter, so an empty result really means "nothing to report".
    $stockStmt = $pdo->prepare(
        "SELECT {$medicineKey} AS medicine_key,
                MIN(TRIM(m.name)) AS name,
                SUM(CASE WHEN {$liveBatch} THEN m.quantity ELSE 0 END) AS total_quantity,
                SUM(CASE WHEN {$liveBatch} THEN 1 ELSE 0 END) AS batch_count
         FROM medicines AS m
         GROUP BY medicine_key
         HAVING SUM(CASE WHEN {$liveBatch} THEN m.quantity ELSE 0 END) <= :stock_threshold"
    );
    $stockStmt->bindValue(':today', $today);
    $stockStmt->bindValue(':stock_threshold', $stockThreshold, PDO::PARAM_INT);
    $stockStmt->execute();
    $stockRows = $stockStmt->fetchAll();

    // --- Query 2: expiry per BATCH, skipping batches that are already empty ---
    // "<= threshold" makes the boundary day itself (exactly 30 days) alert.
    $expiryStmt = $pdo->prepare(
        "SELECT m.id, TRIM(m.name) AS name, m.batch_number, m.quantity, m.expiration_date,
                DATEDIFF(m.expiration_date, :today) AS days_remaining
         FROM medicines AS m
         WHERE m.quantity > 0
           AND m.expiration_date IS NOT NULL
           AND m.expiration_date <= DATE_ADD(:today, INTERVAL :expiry_days DAY)"
    );
    $expiryStmt->bindValue(':today', $today);
    $expiryStmt->bindValue(':expiry_days', $thresholdDays, PDO::PARAM_INT);
    $expiryStmt->execute();
    $expiryRows = $expiryStmt->fetchAll();
} catch (PDOException $e) {
    $respond(['ok' => false, 'error' => 'Database error.'], 500);
}

$groups = ['expired' => [], 'expiring_soon' => [], 'out_of_stock' => [], 'low_stock' => []];

// Expiry alerts — one row per batch.
foreach ($expiryRows as $row) {
    $daysRemaining = $row['days_remaining'] === null ? null : (int) $row['days_remaining'];
    if ($daysRemaining === null || (int) $row['quantity'] <= 0) {
        continue; // guard: empty or undated batches never expire-alert
    }

    $isExpired = $daysRemaining < 0;
    $groups[$isExpired ? 'expired' : 'expiring_soon'][] = [
        'kind' => 'batch',
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'batch_number' => (string) ($row['batch_number'] ?? ''),
        'quantity' => (int) $row['quantity'],
        'expiration_date' => $row['expiration_date'],
        'days_remaining' => $daysRemaining,
        'status' => $isExpired ? 'Expired' : 'Expiring soon',
        'severity' => $isExpired ? 'critical' : 'warning',
        'flags' => ['expired' => $isExpired, 'expiring_soon' => !$isExpired],
    ];
}

// Stock alerts — one row per medicine (totalled across its non-expired batches).
foreach ($stockRows as $row) {
    $total = (int) $row['total_quantity'];
    $isOutOfStock = $total <= 0;
    $groups[$isOutOfStock ? 'out_of_stock' : 'low_stock'][] = [
        'kind' => 'medicine',
        'name' => (string) $row['name'],
        'total_quantity' => $total,
        'batch_count' => (int) $row['batch_count'],
        'status' => $isOutOfStock ? 'Out of stock' : 'Low stock',
        'severity' => $isOutOfStock ? 'critical' : 'low',
        'flags' => [$isOutOfStock ? 'out_of_stock' : 'low_stock' => true],
    ];
}

// Sort by urgency inside each group.
usort($groups['expired'], static fn (array $a, array $b) => $a['days_remaining'] <=> $b['days_remaining']);
usort($groups['expiring_soon'], static fn (array $a, array $b) => $a['days_remaining'] <=> $b['days_remaining']);
usort($groups['out_of_stock'], static fn (array $a, array $b) => strcmp($a['name'], $b['name']));
usort($groups['low_stock'], static fn (array $a, array $b) => $a['total_quantity'] <=> $b['total_quantity'] ?: strcmp($a['name'], $b['name']));

$counts = array_map('count', $groups);
$total = array_sum($counts);

// Signature of WHAT is alerting — the front-end re-shows the pop-up when it changes,
// even if the user dismissed an earlier version during this session.
$signature = substr(hash('sha256', json_encode($groups)), 0, 16);

$respond([
    'ok' => true,
    'today' => $today,
    'generated_at' => date('c'),
    'threshold_days' => $thresholdDays,
    'stock_threshold' => $stockThreshold,
    'total' => $total,
    'counts' => $counts,
    'signature' => $signature,
    'groups' => $groups,
]);
