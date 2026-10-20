<?php
/**
 * Idempotent migration: index medicines.expiration_date (Menu Rule 3 / technical requirements).
 *
 * The Menu query, the 5-minute poll and every charge validate expiry_date on each request;
 * the index keeps those lookups cheap as the batch table grows.
 *
 * Usage:
 *   php scripts/add_expiry_index.php            # adds the index if missing
 *   php scripts/add_expiry_index.php --status   # print current index state only
 *
 * Fresh installs get the index from database.sql; this script covers existing databases.
 * Rollback: ALTER TABLE medicines DROP INDEX idx_medicines_expiration_date;
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/db.php';

$statusOnly = in_array('--status', array_slice($argv, 1), true);

$indexName = 'idx_medicines_expiration_date';
$check = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
);
$check->execute(['medicines', $indexName]);
$exists = (bool) $check->fetchColumn();

if ($statusOnly) {
    echo $exists
        ? "Index {$indexName} already exists on medicines.expiration_date.\n"
        : "Index {$indexName} is MISSING on medicines.expiration_date.\n";
    exit($exists ? 0 : 1);
}

if ($exists) {
    echo "Index {$indexName} already exists — nothing to do.\n";
    exit(0);
}

try {
    // INFORMATION_SCHEMA check above makes this safe to re-run; MySQL would error on duplicates.
    $pdo->exec("ALTER TABLE medicines ADD INDEX {$indexName} (expiration_date)");
    echo "Added index {$indexName} on medicines.expiration_date.\n";
} catch (PDOException $e) {
    fwrite(STDERR, 'Could not add index: ' . $e->getMessage() . "\n");
    exit(1);
}
