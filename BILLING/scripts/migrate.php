<?php
/**
 * Explicit database migration runner.
 *
 *   php scripts/migrate.php
 *
 * The app also migrates itself: config/db.php runs every migration function on each request, so
 * simply loading any page applies the same changes. This script exists so a deployment (or a
 * support call) can apply and VERIFY them deliberately, and see exactly what it did.
 *
 * Added by the Sales / page-permissions work:
 *   * order_items.category  — the category SNAPSHOT stored with each sold line, so re-categorising
 *                             a medicine never rewrites past sales (pre-existing rows are
 *                             backfilled once from the medicine's current category).
 *   * user_pages            — one row per (user, page) grant behind the Users page "Manage access"
 *                             modal. Staff defaults (menu, history, help) live in code, not here.
 *
 * Safe to run repeatedly: every statement is CREATE ... IF NOT EXISTS or an existence check.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// Requiring config/db.php runs the migrations, connects with the app's credentials and loads .env.
require __DIR__ . '/../config/db.php';

$statement = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'category'"
);
$statement->execute();
$hasOrderItemCategory = (int)$statement->fetchColumn() > 0;

$tableCheck = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_pages'"
);
$tableCheck->execute();
$hasUserPages = (int)$tableCheck->fetchColumn() > 0;

$categoryRows = (int)$pdo->query("SELECT COUNT(*) FROM order_items WHERE category IS NOT NULL AND category <> ''")->fetchColumn();
$grantRows = $hasUserPages ? (int)$pdo->query('SELECT COUNT(*) FROM user_pages')->fetchColumn() : 0;

echo "Migrations applied against database 'billing_system'.\n\n";
printf("  %s  order_items.category (per-sale category snapshot) — %d line(s) recorded\n", $hasOrderItemCategory ? 'OK  ' : 'FAIL', $categoryRows);
printf("  %s  user_pages (per-user extra page grants) — %d grant row(s)\n", $hasUserPages ? 'OK  ' : 'FAIL', $grantRows);
printf("  OK    users (accounts, roles, verification/reset columns)\n");

echo "\nGrantable pages: sales, inventory_dashboard, inventory_alerts, pharmacy\n";
echo "Staff defaults : menu, history, help (always available, no rows needed)\n";

if (!$hasOrderItemCategory || !$hasUserPages) {
    fwrite(STDERR, "\nMigration incomplete — check the database user's ALTER/CREATE privileges.\n");
    exit(1);
}

echo "\nNext steps:\n";
echo "  php scripts/seed_admin.php     # (re)create the administrator account from .env\n";
echo "  php scripts/test_sales_permissions.php        # CLI checks for the sales aggregates\n";
echo "  php scripts/test_sales_permissions_http.php   # end-to-end HTTP permission checks\n";

exit(0);
