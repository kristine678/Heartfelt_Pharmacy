<?php
/**
 * CLI test harness for the Menu expired-stock rules (Menu Rule 1-5).
 *
 *   php scripts/test_menu_expiry.php
 *
 * Prereq: php scripts/seed_menu_test_data.php
 * Exits non-zero on any failure. Covers the PHP/server layer:
 *   1  single expired batch        -> hidden from the Menu list, DB row untouched
 *   2  expired + valid batches     -> shown with ONLY the valid batch's quantity
 *   3  all batches expired         -> medicine hidden completely
 *   4  expires today               -> still shown (sellable)
 *   5  expires tomorrow            -> shown
 *   8  search over sellable list   -> expired medicine's name finds nothing
 *   -  qty0 non-expired            -> still shown as out-of-stock (existing behavior)
 *   -  FEFO helper                 -> reduceStock refuses to deduct expired batches
 *   -  timezone                    -> PHP date() and MySQL CURDATE() agree (Rule 5)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/db.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
    if (!$ok) {
        $failures++;
    }
};

$groups = getSellableMedicineGroups($pdo);
$byName = [];
foreach ($groups as $group) {
    $byName[strtolower(trim((string) $group['name']))] = $group;
}
$today = date('Y-m-d');

// --- Scenario 1: single expired batch -> NOT in the Menu, still in the DB -------------
$check('1  expired single batch hidden from Menu', !isset($byName[strtolower('TEST Menu Expired Solo')]));
$row = $pdo->query("SELECT quantity, expiration_date FROM medicines WHERE name = 'TEST Menu Expired Solo'")->fetch();
$check('1  expired batch still exists in DB (40 units)', $row && (int) $row['quantity'] === 40, $row ? "qty {$row['quantity']}, exp {$row['expiration_date']}" : 'missing');

// --- Scenario 2: expired + valid -> shown with ONLY the valid batch's quantity ---------
$mixed = $byName[strtolower('TEST Menu Mixed Stock')] ?? null;
$check('2  mixed medicine shown in Menu', (bool) $mixed);
$check('2  quantity = valid batch only (60, not 85)', $mixed && (int) $mixed['quantity'] === 60, $mixed ? "qty {$mixed['quantity']}" : '');
$check('2  counts exactly 1 sellable batch', $mixed && (int) $mixed['available_batch_count'] === 1, $mixed ? "batches {$mixed['available_batch_count']}" : '');
$check('2  displayed expiry is the valid batch (not the expired one)', $mixed && (string) $mixed['expiration_date'] > $today, $mixed ? "exp {$mixed['expiration_date']}" : '');

// --- Scenario 3: all batches expired -> medicine hidden completely --------------------
$check('3  all-expired medicine hidden from Menu', !isset($byName[strtolower('TEST Menu All Expired')]));
$allExpiredCount = (int) $pdo->query("SELECT COUNT(*) FROM medicines WHERE name = 'TEST Menu All Expired'")->fetchColumn();
$check('3  both expired batches still in DB', $allExpiredCount === 2, "rows {$allExpiredCount}");

// --- Scenario 4 / 5: expires today and tomorrow -> shown ------------------------------
$todayMed = $byName[strtolower('TEST Menu Expires Today')] ?? null;
$check('4  expires today still sellable', $todayMed && (int) $todayMed['quantity'] === 15, $todayMed ? "qty {$todayMed['quantity']}, exp {$todayMed['expiration_date']}" : '');
$tomorrowMed = $byName[strtolower('TEST Menu Expires Tomorrow')] ?? null;
$check('5  expires tomorrow shown', $tomorrowMed && (int) $tomorrowMed['quantity'] === 18, $tomorrowMed ? "qty {$tomorrowMed['quantity']}" : '');

// --- Existing behavior: qty0 but dated -> stays on the Menu as out of stock -----------
$soldOut = $byName[strtolower('TEST Menu Sold Out')] ?? null;
$check('-  qty0 non-expired stays visible (out of stock row)', $soldOut && (int) $soldOut['quantity'] === 0);

// --- Two VALID batches combine (and give the HTTP suite its FEFO case) -----------------
$fefo = $byName[strtolower('TEST Menu FEFO Stock')] ?? null;
$check('-  two valid batches sum into one row (qty 70)', $fefo && (int) $fefo['quantity'] === 70, $fefo ? "qty {$fefo['quantity']}" : '');

// --- Scenario 8: search runs over the sellable list only ------------------------------
$needle = 'test menu expired solo';
$searchHits = array_filter(array_keys($byName), static fn (string $key): bool => strpos($key, $needle) !== false);
$check('8  search for expired medicine name returns nothing', count($searchHits) === 0, count($searchHits) . ' hit(s)');
$needle2 = 'test menu mixed';
$searchHits2 = array_filter(array_keys($byName), static fn (string $key): bool => strpos($key, $needle2) !== false);
$check('8  search for valid medicine still works', count($searchHits2) === 1, count($searchHits2) . ' hit(s)');

// --- Invariant: no group ever exposes an expired batch --------------------------------
$leaks = [];
foreach ($groups as $group) {
    $exp = (string) ($group['expiration_date'] ?? '');
    if ($exp === '' || $exp < $today) {
        $leaks[] = trim((string) $group['name']) . " exp {$exp}";
    }
}
$check('-  no group carries an expired batch date', count($leaks) === 0, implode(', ', $leaks));

// --- FEFO helper: reduceStock must refuse expired batches -----------------------------
$soloId = (int) $pdo->query("SELECT id FROM medicines WHERE name = 'TEST Menu Expired Solo'")->fetchColumn();
reduceStock($pdo, $soloId, 5);
$after = (int) $pdo->query("SELECT quantity FROM medicines WHERE id = {$soloId}")->fetchColumn();
$check('-  reduceStock leaves expired batch untouched', $after === 40, "qty {$after} (expected 40)");
$mixedValidId = (int) $pdo->query("SELECT id FROM medicines WHERE name = 'TEST Menu Mixed Stock' AND batch_number = 'Batch 2'")->fetchColumn();
reduceStock($pdo, $mixedValidId, 10);
$afterValid = (int) $pdo->query("SELECT quantity FROM medicines WHERE id = {$mixedValidId}")->fetchColumn();
$check('-  reduceStock still deducts from a valid batch', $afterValid === 50, "qty {$afterValid} (expected 50)");
$pdo->prepare('UPDATE medicines SET quantity = 60 WHERE id = ?')->execute([$mixedValidId]); // restore

// --- Rule 5: one timezone — PHP date() and MySQL CURDATE() must agree -----------------
$phpToday = date('Y-m-d');
$mysqlToday = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
$check('-  PHP date() matches MySQL CURDATE() (same day)', $phpToday === $mysqlToday, "php {$phpToday} / mysql {$mysqlToday}");
$dbTz = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
$check('-  DB session timezone is set explicitly', $dbTz !== 'SYSTEM', $dbTz);

echo $failures === 0 ? "\nAll menu-expiry checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
