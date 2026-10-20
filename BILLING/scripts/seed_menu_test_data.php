<?php
/**
 * Seed data for the Menu expired-stock rules (Menu Rule 1-5).
 *
 *   Seed : php scripts/seed_menu_test_data.php
 *   Clean: php scripts/seed_menu_test_data.php --cleanup
 *
 * All fixtures are prefixed "TEST Menu " so they are obviously test data. Idempotent and
 * parameterized; --cleanup removes exactly these rows. Date offsets are relative to today
 * in the app timezone (Asia/Manila).
 *
 * | # | Scenario                                                        | Fixture                                             |
 * |---|-----------------------------------------------------------------|-----------------------------------------------------|
 * | 1 | Single batch, expired  -> NOT in Menu, Inventory "Expired" badge | TEST Menu Expired Solo:  B1 qty40  -3d             |
 * | 2 | B1 expired + B2 valid  -> Menu shows only B2's quantity          | TEST Menu Mixed Stock:   B1 qty25 -5d, B2 qty60 +120d |
 * | 3 | All batches expired    -> medicine hidden from Menu              | TEST Menu All Expired:   B1 qty10 -9d, B2 qty5 -1d  |
 * | 4 | Expires today          -> still shown (sellable)                | TEST Menu Expires Today: B1 qty15   0d             |
 * | 5 | Expires tomorrow       -> shown                                 | TEST Menu Expires Tomorrow: B1 qty18 +1d           |
 * | 6 | Carted, expires before checkout -> rejected at charge            | use scenario 2's stock: expire B2 via SQL, charge  |
 * | 7 | Direct API sell of expired batch -> rejected by the server       | TEST Menu Expired Solo (scenario 1)                |
 * | 8 | Search of expired medicine's name -> no result                  | search filters the sellable list = scenario 1      |
 * | - | Existing out-of-stock behavior kept: qty0 (not expired) stays    | TEST Menu Sold Out:       B1 qty0 +200d            |
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/db.php';

$cleanup = in_array('--cleanup', array_slice($argv, 1), true);

// Exact stored name => [ [batch_number, quantity, days_from_today], ... ]
$fixtures = [
    'TEST Menu Expired Solo' => [
        ['Batch 1', 40, -3],
    ],
    'TEST Menu Mixed Stock' => [
        ['Batch 1', 25, -5],
        ['Batch 2', 60, 120],
    ],
    'TEST Menu All Expired' => [
        ['Batch 1', 10, -9],
        ['Batch 2', 5, -1],
    ],
    'TEST Menu Expires Today' => [
        ['Batch 1', 15, 0],
    ],
    'TEST Menu Expires Tomorrow' => [
        ['Batch 1', 18, 1],
    ],
    'TEST Menu Sold Out' => [
        ['Batch 1', 0, 200],
    ],
    // Two VALID batches so the charge test proves FEFO: earliest expiry must be drained first.
    'TEST Menu FEFO Stock' => [
        ['Batch 1', 30, 10],
        ['Batch 2', 40, 200],
    ],
];

$names = array_keys($fixtures);
$placeholders = implode(',', array_fill(0, count($names), '?'));

if ($cleanup) {
    $stmt = $pdo->prepare("DELETE FROM medicines WHERE name IN ($placeholders)");
    $stmt->execute($names);
    echo 'Removed ' . $stmt->rowCount() . " menu test row(s).\n";
    exit(0);
}

$delete = $pdo->prepare("DELETE FROM medicines WHERE name IN ($placeholders)");
$delete->execute($names);

$insert = $pdo->prepare(
    "INSERT INTO medicines (name, batch_number, quantity, supplier, expiration_date, price, category)
     VALUES (?, ?, ?, 'Menu test seed', ?, 1.00, 'Test fixture')"
);

$today = new DateTime('today');
$expected = 0;
foreach ($fixtures as $name => $batches) {
    foreach ($batches as [$batch, $quantity, $offset]) {
        $expiry = (clone $today)->modify(sprintf('%+d days', $offset))->format('Y-m-d');
        $insert->execute([$name, $batch, $quantity, $expiry]);
        $expected++;
        printf("  seeded: %-26s %-9s qty %-3d expires %s (%+d d)\n", $name, $batch, $quantity, $expiry, $offset);
    }
}

echo "Seeded {$expected} batch row(s) across " . count($fixtures) . " medicine name(s).\n";
echo "Expected in Menu: Mixed Stock (qty 60), Expires Today (15), Expires Tomorrow (18), Sold Out (0, out of stock), FEFO Stock (70).\n";
echo "Expected HIDDEN from Menu: Expired Solo, All Expired.\n";
echo "Clean up with: php scripts/seed_menu_test_data.php --cleanup\n";
