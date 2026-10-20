<?php
/**
 * OPTIONAL schema split: `medicines` (one row per BATCH today) -> medicine_catalog + batches.
 *
 * Advice first: the alert rules do NOT require this split — the normalized name key in
 * api/alerts.php already groups batches correctly. Split is worth doing only if you plan
 * per-medicine attributes that must stay consistent (description, barcode, default price,
 * reorder level) or want to stop repeating the medicine name on every batch row.
 *
 * This script is deliberately ADDITIVE and non-destructive:
 *   - it only CREATEs two new tables and backfills them from `medicines`;
 *   - it never ALTERs, renames or drops the legacy table, so the app keeps working;
 *   - re-running refuses to touch a non-empty target (no silent data mixing).
 * Rollback = DROP TABLE batches, medicine_catalog.
 *
 * Usage (dry run prints the plan and source counts only):
 *   php scripts/migrate_to_batches.php
 * Apply + verify:
 *   php scripts/migrate_to_batches.php --apply
 * Optional: run against a scratch copy first (same credentials, different database):
 *   DB_NAME=billing_migrate_test php scripts/migrate_to_batches.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$apply = in_array('--apply', array_slice($argv, 1), true);
$dbName = getenv('DB_NAME') ?: 'billing_system';
$host = '127.0.0.1';
$user = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to database '{$dbName}': " . $e->getMessage() . "\n");
    exit(1);
}

// Same normalization the alert endpoint uses: lowercase, trimmed, inner whitespace removed.
$nameKey = "LOWER(REPLACE(REPLACE(TRIM(name), ' ', ''), '\t', ''))";
$nameKeyM = "LOWER(REPLACE(REPLACE(TRIM(m.name), ' ', ''), '\t', ''))";

$sourceCount = (int) $pdo->query('SELECT COUNT(*) FROM medicines')->fetchColumn();
$medicineCount = (int) $pdo->query("SELECT COUNT(DISTINCT {$nameKey}) FROM medicines")->fetchColumn();
$sumMatches = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT {$nameKey} k, SUM(quantity) s FROM medicines GROUP BY k) t")->fetchColumn();

echo "Source: {$sourceCount} batch row(s) in `medicines` -> {$medicineCount} distinct medicine(s).\n";

$exists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
};

if (!$apply) {
    echo "Dry run only. Would create:\n";
    echo "  medicine_catalog (id, name_key UNIQUE, name, category, image, created_at)\n";
    echo "  batches (id, medicine_id -> medicine_catalog.id, batch_number, quantity,\n";
    echo "           expiration_date, supplier, price, legacy_medicine_id, timestamps)\n";
    echo "Run again with --apply to execute and verify. The legacy table is never modified.\n";
    exit(0);
}

if ($exists('batches')) {
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM batches')->fetchColumn();
    if ($rows > 0) {
        fwrite(STDERR, "Refusing to run: `batches` already holds {$rows} row(s). Drop it first for a fresh migration.\n");
        exit(1);
    }
}
if ($exists('medicine_catalog') && !$exists('batches')) {
    // Recover from an interrupted earlier run: an empty batches table means nothing was
    // switched over, so clearing our own scratch table and starting clean is safe.
    $leftover = (int) $pdo->query('SELECT COUNT(*) FROM medicine_catalog')->fetchColumn();
    if ($leftover > 0) {
        echo "Clearing {$leftover} leftover row(s) from an interrupted run (medicine_catalog only).\n";
        $pdo->exec('TRUNCATE TABLE medicine_catalog');
    }
}

try {
    // NOTE: CREATE TABLE implicitly commits in MySQL/MariaDB, so DDL runs OUTSIDE the
    // transaction — only the backfill INSERTs are transactional (and therefore rollback-able).
    if (!$exists('medicine_catalog')) {
        $pdo->exec("CREATE TABLE medicine_catalog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            category VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Uncategorized',
            image VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_medicine_catalog_name_key (name_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!$exists('batches')) {
        $pdo->exec("CREATE TABLE batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT NOT NULL,
            batch_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
            quantity INT NOT NULL DEFAULT 0,
            expiration_date DATE DEFAULT NULL,
            supplier VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            legacy_medicine_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_batches_medicine_id (medicine_id),
            KEY idx_batches_batch_number (batch_number),
            KEY idx_batches_expiration_date (expiration_date),
            FOREIGN KEY (medicine_id) REFERENCES medicine_catalog(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $pdo->beginTransaction();

    // Backfill the medicine master from distinct normalized names.
    $pdo->exec("INSERT INTO medicine_catalog (name_key, name, category, image)
                SELECT {$nameKey}, MIN(TRIM(m.name)), MIN(m.category), MIN(m.image)
                FROM medicines m GROUP BY {$nameKey}");

    // Backfill batches, linking each legacy row to its medicine. The legacy expression must
    // be table-qualified here (both tables have a `name` column).
    $pdo->exec("INSERT INTO batches (medicine_id, batch_number, quantity, expiration_date, supplier, price, legacy_medicine_id)
                SELECT c.id, m.batch_number, m.quantity, m.expiration_date, m.supplier, m.price, m.id
                FROM medicines m
                JOIN medicine_catalog c ON c.name_key = {$nameKeyM}");

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration failed and was rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

// --- Verification (the migration only "succeeds" if these all hold) ---
$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$check('batch rows copied (' . $pdo->query('SELECT COUNT(*) FROM batches')->fetchColumn() . " of {$sourceCount})",
    (int) $pdo->query('SELECT COUNT(*) FROM batches')->fetchColumn() === $sourceCount);
$check('medicines created (' . $pdo->query('SELECT COUNT(*) FROM medicine_catalog')->fetchColumn() . " of {$medicineCount})",
    (int) $pdo->query('SELECT COUNT(*) FROM medicine_catalog')->fetchColumn() === $medicineCount);

$sumMismatch = (int) $pdo->query('SELECT COUNT(*) FROM (
        SELECT c.id
        FROM medicine_catalog c
        LEFT JOIN batches b ON b.medicine_id = c.id
        JOIN (SELECT ' . $nameKeyM . ' k, SUM(quantity) s FROM medicines m GROUP BY k) src
             ON src.k = c.name_key
        GROUP BY c.id
        HAVING COALESCE(SUM(b.quantity), 0) <> MIN(src.s)
      ) t')->fetchColumn();
$check("per-medicine quantity totals match source ({$sumMatches} medicine(s) compared)", $sumMismatch === 0);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} verification check(s) failed — inspect before switching the app over.\n");
    exit(1);
}

echo "Migration verified. The legacy `medicines` table was NOT modified, so the app is unaffected.\n";
echo "Switch-over plan (query by query, when you're ready):\n";
echo "  1. Stock alerts: GROUP BY b.medicine_id with SUM(b.quantity) WHERE expiration_date >= CURDATE().\n";
echo "  2. Expiry alerts: SELECT FROM batches WHERE quantity > 0 AND expiration_date <= CURDATE() + threshold.\n";
echo "  3. POS stock deduction: pick batches by FIFO/expiry as today, but UPDATE batches instead of medicines.\n";
echo "  4. Only after all readers/writers are moved: RENAME medicines -> medicines_legacy (keep as backup).\n";
