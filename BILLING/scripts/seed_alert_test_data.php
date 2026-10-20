<?php
/**
 * Sample data for the medicine alert rules (stock grouped per MEDICINE, expiry per BATCH).
 *
 *   Seed : php scripts/seed_alert_test_data.php
 *   Clean: php scripts/seed_alert_test_data.php --cleanup
 *
 * All fixtures are prefixed "TEST " so they are obviously test data. Idempotent and
 * parameterized; --cleanup removes exactly these rows.
 * Thresholds assumed (live config in pharmacy_settings): expiry = 30 days, stock = 10.
 *
 * | # | Medicine (batches)                                        | Expected result                                  |
 * |---|-----------------------------------------------------------|--------------------------------------------------|
 * | 1 | TEST Paracetamol:      B1 qty0 +365d, B2 qty200 +365d     | NO alert (B2 covers B1)                          |
 * | 2 | TEST Ibuprofen:        B1 qty50 +10d,  B2 qty200 +300d    | expiry alert for B1 only                         |
 * | 3 | TEST Amoxicillin:      B1 qty0 +30d,   B2 qty0 +200d      | ONE "Out of stock" medicine row; no expiry rows  |
 * | 4 | TEST Vitamin C:        B1 qty0 +200d,  B2 qty30 +5d       | expiry alert for B2 only (not out of stock)      |
 * | 5 | TEST Expired Paracetamol: B1 qty40 -5d, B2 qty200 +300d   | Expired alert for B1 only                        |
 * | 6 | TEST Single Batch Med: qty0 +100d                         | "Out of stock" (single batch)                    |
 * | 7 | TEST Grouping Agent / "  test grouping agent " (casing+ws)| NO alert — grouped into one medicine (total 150)  |
 * | 8 | TEST Wasteland Tabs:   qty0 -3d                           | out of stock, but NO expiry alert (empty batch)   |
 * | 9 | TEST Boundary Med:     B1 qty25 +30d, B2 qty25 +31d       | expiry alert for B1 only (threshold day counts)   |
 * |10 | TEST Low Stock Med:    B1 qty4 +400d, B2 qty6 +400d       | ONE low-stock medicine row (total 10 <= 10)       |
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/db.php';

$cleanup = in_array('--cleanup', array_slice($argv, 1), true);

// Exact stored name => [ [batch_number, quantity, days_from_today], ... ]
$fixtures = [
    'TEST Paracetamol' => [
        ['B1', 0, 365], ['B2', 200, 365],
    ],
    'TEST Ibuprofen' => [
        ['B1', 50, 10], ['B2', 200, 300],
    ],
    'TEST Amoxicillin' => [
        ['B1', 0, 30], ['B2', 0, 200],
    ],
    'TEST Vitamin C' => [
        ['B1', 0, 200], ['B2', 30, 5],
    ],
    'TEST Expired Paracetamol' => [
        ['B1', 40, -5], ['B2', 200, 300],
    ],
    'TEST Single Batch Med' => [
        ['A', 0, 100],
    ],
    'TEST Grouping Agent' => [
        ['A', 0, 400],
    ],
    '  test grouping agent ' => [
        ['B', 150, 400],
    ],
    'TEST Wasteland Tabs' => [
        ['A', 0, -3],
    ],
    'TEST Boundary Med' => [
        ['B1', 25, 30], ['B2', 25, 31],
    ],
    'TEST Low Stock Med' => [
        ['B1', 4, 400], ['B2', 6, 400],
    ],
];

$names = array_keys($fixtures);
$placeholders = implode(',', array_fill(0, count($names), '?'));

if ($cleanup) {
    $stmt = $pdo->prepare("DELETE FROM medicines WHERE name IN ($placeholders)");
    $stmt->execute($names);
    echo 'Removed ' . $stmt->rowCount() . " alert test row(s).\n";
    exit(0);
}

$delete = $pdo->prepare("DELETE FROM medicines WHERE name IN ($placeholders)");
$delete->execute($names);

$insert = $pdo->prepare(
    "INSERT INTO medicines (name, batch_number, quantity, supplier, expiration_date, price, category)
     VALUES (?, ?, ?, 'Alert test seed', ?, 1.00, 'Test fixture')"
);

$today = new DateTime('today');
$expected = 0;
foreach ($fixtures as $name => $batches) {
    foreach ($batches as [$batch, $quantity, $offset]) {
        $expiry = (clone $today)->modify(sprintf('%+d days', $offset))->format('Y-m-d');
        $insert->execute([rtrim($name), $batch, $quantity, $expiry]);
        $expected++;
        printf("  seeded: %-24s batch %s qty %-3d expires %s (%+d d)\n", trim($name), $batch, $quantity, $expiry, $offset);
    }
}

echo "Seeded {$expected} batch row(s) across " . count($fixtures) . " medicine name(s).\n";
echo "Expected alerts: 1 expired, 3 expiring_soon (5/10/30 d), 3 out_of_stock, 1 low_stock = 8 total.\n";
echo "Clean up with: php scripts/seed_alert_test_data.php --cleanup\n";
