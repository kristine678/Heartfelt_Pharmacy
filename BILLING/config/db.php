<?php
// FIX (staff verification): load .env (APP_URL, TOKEN_SECRET, BILLING_SMTP_*...) into the
// runtime first - Apache does not read .env on its own, so getenv() would fail without this.
require_once __DIR__ . '/env.php';

// FIX: Added constants and comments marking all shared-data fixes in this file.
const STAFF_SESSION_TIMEOUT_SECONDS = 1800;

// FIX (Menu Rule 5): ONE timezone for every expiry decision in the app. This runs before any
// date()/DateTime call, so "today" is identical for the Menu, the alerts, the Inventory badges
// and checkout. Override with the APP_TIMEZONE env var if the shop is not in Manila.
$appTimezone = getenv('APP_TIMEZONE');
if (!is_string($appTimezone) || $appTimezone === '' || !@date_default_timezone_set($appTimezone)) {
    date_default_timezone_set('Asia/Manila');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => STAFF_SESSION_TIMEOUT_SECONDS,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('heartfelt_pharmacy_session');
    session_start();
}

function currentUrlPrefix(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/');
    return preg_match('#/staff/?$#', $dir) ? '../' : '';
}

function staffLoginUrl(): string {
    return currentUrlPrefix() . 'staff/';
}

function staffDashboardUrl(): string {
    return currentUrlPrefix() . 'menu.php';
}

function getPublicMedicines(PDO $pdo): array {
    return array_map(static function (array $row): array {
        $quantity = (int)$row['quantity'];
        $row['stock_status'] = $quantity <= 0 ? 'Out of stock' : ($quantity <= 10 ? 'Low stock' : 'In stock');
        return $row;
    }, getSellableMedicineGroups($pdo));
}

$host = '127.0.0.1';
$port = 3306;
$dbname = 'billing_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // FIX (Menu Rule 5): the DB session clock must agree with PHP's date() above, otherwise
    // CURDATE()/NOW() used by legacy SQL and status scripts drifts a day from the Menu's expiry
    // checks. A fixed numeric offset needs no MySQL timezone tables; Asia/Manila has no DST.
    $dbTzOffset = getenv('APP_DB_TZ_OFFSET');
    if (!is_string($dbTzOffset) || !preg_match('/^[+-]\d{2}:\d{2}$/', $dbTzOffset)) {
        $dbTzOffset = '+08:00';
    }
    $pdo->exec('SET time_zone = ' . $pdo->quote($dbTzOffset));
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

function migrate_pharmacy_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pharmacy_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alert_threshold_days INT NOT NULL DEFAULT 30,
        stock_threshold INT NOT NULL DEFAULT 10,
        notify_email VARCHAR(255) DEFAULT NULL
    )");
    $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM pharmacy_settings');
    $row = $stmt->fetch();
    if ((int)$row['cnt'] === 0) {
        $pdo->exec("INSERT INTO pharmacy_settings (alert_threshold_days, stock_threshold, notify_email) VALUES (30, 10, 'admin@example.com')");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS medicines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        batch_number VARCHAR(100) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        supplier VARCHAR(150) NOT NULL,
        expiration_date DATE NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        category VARCHAR(100) NOT NULL DEFAULT 'Uncategorized',
        image VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $pdo->exec("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    $pdo->exec("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS category VARCHAR(100) NOT NULL DEFAULT 'Uncategorized'");
    $pdo->exec("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS expiry_statuses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        medicine_id INT NOT NULL,
        status VARCHAR(50) NOT NULL,
        days_remaining INT NOT NULL,
        threshold_days INT NOT NULL,
        checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE
    )");

    $pdo->exec("ALTER TABLE pharmacy_settings ADD COLUMN IF NOT EXISTS stock_threshold INT NOT NULL DEFAULT 10");

    // Alert endpoint: the per-batch expiry query filters on expiration_date and the
    // per-medicine stock query groups all live batches — index expiry (and name, which the
    // inventory/order queries already sort and match on) so this stays cheap as batches grow.
    $indexCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medicines' AND INDEX_NAME = ?");
    foreach (['idx_medicines_expiration_date' => 'expiration_date', 'idx_medicines_name' => 'name'] as $indexName => $column) {
        $indexCheck->execute([$indexName]);
        if (!(int) $indexCheck->fetchColumn()) {
            try {
                $pdo->exec("CREATE INDEX {$indexName} ON medicines ({$column})");
            } catch (PDOException $e) {
                // A concurrent request created it first — safe to ignore.
            }
        }
    }
}

function migrate_product_columns(PDO $pdo): void {
    migrate_pharmacy_tables($pdo);

    try {
        $pdo->exec('ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_quantity INT NOT NULL DEFAULT 0');
    } catch (PDOException $e) {
        try {
            $pdo->query('SELECT stock_quantity FROM products LIMIT 1');
        } catch (PDOException $e2) {
            $pdo->exec('ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 0');
        }
    }
    try {
        $pdo->exec('ALTER TABLE products ADD COLUMN IF NOT EXISTS expiration_date DATE DEFAULT NULL');
    } catch (PDOException $e) {
        try {
            $pdo->query('SELECT expiration_date FROM products LIMIT 1');
        } catch (PDOException $e2) {
            $pdo->exec('ALTER TABLE products ADD COLUMN expiration_date DATE DEFAULT NULL');
        }
    }
}

function migrate_customer_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        status ENUM('unverified', 'verified') NOT NULL DEFAULT 'unverified',
        verification_code CHAR(6) DEFAULT NULL,
        verification_code_expires_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

migrate_product_columns($pdo);
migrate_customer_tables($pdo);

// Retain opaque signup-attempt records for the signup cooldown; account data lives in users.
function migrate_staff_auth_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_auth_attempts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(20) NOT NULL,
        subject_hash CHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff_auth_attempts_lookup (action, subject_hash, created_at)
    )");
}

migrate_staff_auth_tables($pdo);

// FIX (Bug 6): make sure the walk-in customer row the charge flow writes to exists with the right columns.
function migrate_walkin_customers(PDO $pdo): void {
    try {
        $pdo->query('SELECT name, phone, email, table_number FROM customers LIMIT 1');
        return; // POS-style customers table already present.
    } catch (PDOException $e) {
        // FALL THROUGH: create the POS-style walk-in table.
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL DEFAULT 'Walk-in Customer',
            phone VARCHAR(30) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            table_number VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $e) {
        // Another concurrent request created it first — nothing to do.
    }
}

migrate_walkin_customers($pdo);

function migrate_order_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL DEFAULT 0,
        order_type VARCHAR(50) DEFAULT 'Dine In',
        status VARCHAR(50) DEFAULT 'open',
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_type VARCHAR(30) DEFAULT 'none',
        discount_id_number VARCHAR(100) DEFAULT NULL,
        service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_received DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP NULL DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        customer_email VARCHAR(150) DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        medicine_id INT NOT NULL,
        product_id INT NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 1,
        name VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image VARCHAR(255) DEFAULT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_item_batch_allocations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_item_id INT NOT NULL,
        medicine_id INT NOT NULL,
        batch_number VARCHAR(100) NOT NULL,
        allocated_quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        expiration_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order_item_batch_allocations_order_item (order_item_id),
        FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(150) NOT NULL,
        details TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $columns = [
        'discount' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'discount_type' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_type VARCHAR(30) DEFAULT "none"',
        'discount_id_number' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_id_number VARCHAR(100) DEFAULT NULL',
        'service_charge' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'tax' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS tax DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'amount_received' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS amount_received DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'change_amount' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'paid_at' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP NULL DEFAULT NULL',
        'notes' => 'ALTER TABLE orders ADD COLUMN IF NOT EXISTS notes VARCHAR(255) DEFAULT NULL',
    ];
    foreach ($columns as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Ignore column-already-exists migration errors
        }
    }
}

migrate_order_tables($pdo);

// FIX (Task 3): orders.customer_email — the optional email typed in Order Details is saved on the
// order itself so "Email Receipt" can address it later. Uses an information_schema existence check
// (same pattern as migrate_order_item_columns) so it also works on plain MySQL, where
// "ADD COLUMN IF NOT EXISTS" is unsupported and the ALTER above would be silently skipped.
function migrate_order_email_column(PDO $pdo): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'customer_email'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN customer_email VARCHAR(150) DEFAULT NULL');
        } catch (PDOException $e) {
            // A concurrent request already added it — safe to ignore.
        }
    }
}

migrate_order_email_column($pdo);

// FIX (charge bug SQLSTATE 42S22 "Unknown column 'medicine_id'"): the live order_items table can
// still be the LEGACY schema (id, order_id, product_id, quantity, modifiers, price only). The POS
// writes medicine_id/name/image, so align the table on every request — this also repairs old
// databases without a manual schema update.
function migrate_order_item_columns(PDO $pdo): void {
    $columnExists = static function (PDO $pdo, string $column): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    };

    // No ADD COLUMN IF NOT EXISTS here so this also works on MySQL (not just MariaDB).
    $requiredColumns = [
        'medicine_id' => "ALTER TABLE order_items ADD COLUMN medicine_id INT NOT NULL DEFAULT 0",
        'name' => "ALTER TABLE order_items ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT 'Medicine'",
        'image' => 'ALTER TABLE order_items ADD COLUMN image VARCHAR(255) DEFAULT NULL',
    ];
    foreach ($requiredColumns as $column => $sql) {
        if (!$columnExists($pdo, $column)) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // A concurrent request already added it — safe to ignore.
            }
        }
    }

    // FIX (charge bug): the legacy FK order_items.product_id -> products(id) rejects the
    // POS convention product_id = 0 (items carry their own name/price), so drop it. The POS
    // flow keeps product_id for legacy rows only.
    $foreignKeys = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'product_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($foreignKeys as $foreignKey) {
        $constraintName = str_replace('`', '', (string)$foreignKey['CONSTRAINT_NAME']);
        try {
            $pdo->exec('ALTER TABLE order_items DROP FOREIGN KEY `' . $constraintName . '`');
        } catch (PDOException $e) {
            // Already dropped or permissions issue — the charge must not die here.
        }
    }
}

migrate_order_item_columns($pdo);

function migrate_user_management_columns(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) DEFAULT 'staff'
    )");

    $columns = [
        'full_name' => "ALTER TABLE users ADD COLUMN full_name VARCHAR(150) NOT NULL DEFAULT ''",
        'status' => "ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'",
        'created_at' => 'ALTER TABLE users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        // FIX (admin login): login accepts an email OR a username, so users needs an email
        // column (NULL for legacy accounts; username login keeps working either way).
        'email' => 'ALTER TABLE users ADD COLUMN email VARCHAR(150) DEFAULT NULL',
        // FIX (staff verification): single-use hashed verification token + bookkeeping.
        // Only an HMAC of the random token is ever stored; expiry20 min; resent replaces it.
        'verification_token_hash' => 'ALTER TABLE users ADD COLUMN verification_token_hash CHAR(64) DEFAULT NULL',
        'verification_expires_at' => 'ALTER TABLE users ADD COLUMN verification_expires_at DATETIME DEFAULT NULL',
        'verification_sent_at' => 'ALTER TABLE users ADD COLUMN verification_sent_at DATETIME DEFAULT NULL',
        // Accounts are verified by default so the email-verification rule can never lock
        // out existing/seeded accounts; scripts/seed_admin.php sets it to 1 explicitly.
        'email_verified' => 'ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1',
        // FIX (verification code): the 6-digit code flow stores only an HMAC of the code (never
        // the code itself), how many wrong guesses it has taken, and whether it was already used.
        // verification_expires_at / verification_sent_at (above) carry its 10-minute TTL and the
        // 60-second resend cooldown.
        'verification_code_hash' => 'ALTER TABLE users ADD COLUMN verification_code_hash CHAR(64) DEFAULT NULL',
        'verification_attempts' => 'ALTER TABLE users ADD COLUMN verification_attempts INT NOT NULL DEFAULT 0',
        'verification_used_at' => 'ALTER TABLE users ADD COLUMN verification_used_at DATETIME DEFAULT NULL',
        // FIX (password reset): a SEPARATE code namespace, so asking for a reset code can never
        // wipe an outstanding sign-up verification code (and vice versa).
        'reset_code_hash' => 'ALTER TABLE users ADD COLUMN reset_code_hash CHAR(64) DEFAULT NULL',
        'reset_expires_at' => 'ALTER TABLE users ADD COLUMN reset_expires_at DATETIME DEFAULT NULL',
        'reset_attempts' => 'ALTER TABLE users ADD COLUMN reset_attempts INT NOT NULL DEFAULT 0',
        'reset_used_at' => 'ALTER TABLE users ADD COLUMN reset_used_at DATETIME DEFAULT NULL',
        'reset_sent_at' => 'ALTER TABLE users ADD COLUMN reset_sent_at DATETIME DEFAULT NULL',
        // FIX (reset must kill old sessions): token_version is stamped into the session at login
        // and re-checked on every request (requireLogin). Any password change bumps it, which
        // invalidates every session that used the old password - the reset user must log in again.
        'token_version' => 'ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 1',
        'password_changed_at' => 'ALTER TABLE users ADD COLUMN password_changed_at DATETIME DEFAULT NULL',
    ];
    $checkColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
    foreach ($columns as $column => $sql) {
        $checkColumn->execute([$column]);
        if (!(int)$checkColumn->fetchColumn()) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                $checkColumn->execute([$column]);
                if (!(int)$checkColumn->fetchColumn()) {
                    throw $exception;
                }
            }
        }
        $pdo->exec("UPDATE users SET full_name = username WHERE full_name = ''");
    }

    $unsupportedRoles = $pdo->query("SELECT id, username, role FROM users WHERE LOWER(TRIM(role)) IN ('pharmacist', 'cashier')")->fetchAll(PDO::FETCH_ASSOC);
    if ($unsupportedRoles) {
        $pdo->exec("UPDATE users SET role = 'staff' WHERE LOWER(TRIM(role)) IN ('pharmacist', 'cashier')");
        $affectedUsers = array_map(static function (array $user): string {
            return '#' . (int)$user['id'] . ' ' . (string)$user['username'] . ' (' . (string)$user['role'] . ')';
        }, $unsupportedRoles);
        $log = $pdo->prepare('INSERT INTO admin_logs (action, details) VALUES (?, ?)');
        $log->execute([
            'unsupported_user_roles_migrated',
            'Converted unsupported roles to staff: ' . implode(', ', $affectedUsers),
        ]);
    }
}

migrate_user_management_columns($pdo);

function migrate_legacy_staff_accounts(PDO $pdo): void {
    $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_accounts'");
    $tableCheck->execute();
    if (!(int)$tableCheck->fetchColumn()) {
        return;
    }

    $legacyRows = $pdo->query('SELECT full_name, username, password_hash, status, created_at FROM staff_accounts ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $find = $pdo->prepare('SELECT id, full_name, username, password_hash, role FROM users WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1');
    $insert = $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, status, created_at) VALUES (?, ?, ?, 'staff', ?, ?)");
    $migrated = [];
    $conflicts = [];

    foreach ($legacyRows as $legacy) {
        $fullName = trim((string)$legacy['full_name']);
        $username = trim((string)$legacy['username']);
        $passwordHash = (string)$legacy['password_hash'];
        $find->execute([$username]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (
                (string)$existing['role'] === 'staff'
                && (string)$existing['full_name'] === $fullName
                && (string)$existing['password_hash'] === $passwordHash
            ) {
                $migrated[] = $username;
                continue;
            }
            if (
                (
                    (string)$existing['role'] === 'staff'
                    && trim((string)$existing['full_name']) === ''
                )
                || (
                    (string)$existing['role'] === 'staff'
                    && strcasecmp(trim((string)$existing['full_name']), trim((string)$existing['username'])) === 0
                    && (string)$existing['username'] === 'staff'
                )
            ) {
                $status = (string)$legacy['status'] === 'verified' ? 'active' : 'inactive';
                $update = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, password_hash = ?, status = ?, created_at = ? WHERE id = ? AND role = "staff"');
                $update->execute([
                    $fullName,
                    $username,
                    $passwordHash,
                    $status,
                    $legacy['created_at'] ?: date('Y-m-d H:i:s'),
                    (int)$existing['id'],
                ]);
                $migrated[] = $username;
                continue;
            }
            $conflicts[] = $username;
            continue;
        }

        $status = (string)$legacy['status'] === 'verified' ? 'active' : 'inactive';
        $insert->execute([
            $fullName,
            $username,
            $passwordHash,
            $status,
            $legacy['created_at'] ?: date('Y-m-d H:i:s'),
        ]);
        $migrated[] = $username;
    }

    if ($migrated) {
        $details = 'Migrated legacy staff accounts into users: ' . implode(', ', $migrated);
        $checkLog = $pdo->prepare('SELECT id FROM admin_logs WHERE action = ? AND details = ? LIMIT 1');
        $checkLog->execute(['legacy_staff_accounts_migrated', $details]);
        if (!$checkLog->fetchColumn()) {
            $pdo->prepare('INSERT INTO admin_logs (action, details) VALUES (?, ?)')->execute([
                'legacy_staff_accounts_migrated',
                $details,
            ]);
        }
    }

    if ($conflicts) {
        $details = 'Legacy staff accounts were not copied because their usernames already belong to different users: ' . implode(', ', $conflicts);
        $checkLog = $pdo->prepare('SELECT id FROM admin_logs WHERE action = ? AND details = ? LIMIT 1');
        $checkLog->execute(['legacy_staff_account_conflicts', $details]);
        if (!$checkLog->fetchColumn()) {
            $pdo->prepare('INSERT INTO admin_logs (action, details) VALUES (?, ?)')->execute([
                'legacy_staff_account_conflicts',
                $details,
            ]);
        }
        return;
    }

    $pdo->exec('DROP TABLE staff_accounts');
}

migrate_legacy_staff_accounts($pdo);

// TASK 3 (page permissions): the per-user page grants live in their own table — ONE row per
// (user, page). Written only by users.php's admin-only save_pages endpoint; read on every request
// by includes/permissions.php, which is what makes a revoked permission stop working immediately
// instead of lingering in a session or a cached sidebar.
function migrate_user_pages(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_pages (
            user_id INT NOT NULL,
            page VARCHAR(50) NOT NULL,
            granted_by INT DEFAULT NULL,
            granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, page),
            CONSTRAINT fk_user_pages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        return;
    } catch (PDOException $e) {
        // Older MySQL/MariaDB (or a users table on a different engine) can refuse the FK. The
        // permission table itself must never break the whole app, so fall back to the same table
        // without the constraint and clean orphans up in users.php on delete.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_pages (
        user_id INT NOT NULL,
        page VARCHAR(50) NOT NULL,
        granted_by INT DEFAULT NULL,
        granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, page)
    )");
}

migrate_user_pages($pdo);

// TASK 1 (category analytics): order_items.category is a SNAPSHOT of the medicine's category at
// the moment it was sold, so re-categorising a medicine later never rewrites past sales.
//   * new sales: savePaidOrderTransaction() writes the snapshot.
//   * rows that predate the column: backfilled ONCE from the medicine's current category (the
//     best available approximation), then left alone forever.
function migrate_order_item_category(PDO $pdo): void
{
    $columnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'category'");
    $columnCheck->execute();
    if ((int)$columnCheck->fetchColumn() > 0) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT 'Uncategorized'");
    } catch (PDOException $e) {
        // A concurrent request added it first — re-check before deciding what to do next.
        $columnCheck->execute();
        if ((int)$columnCheck->fetchColumn() === 0) {
            return;
        }
    }

    try {
        $pdo->exec("UPDATE order_items oi
                    JOIN medicines m ON m.id = oi.medicine_id
                    SET oi.category = COALESCE(NULLIF(TRIM(m.category), ''), 'Uncategorized')
                    WHERE oi.category = 'Uncategorized' AND m.id > 0");
    } catch (PDOException $e) {
        // Backfill is best-effort: unmigrated rows already default to 'Uncategorized', which is
        // exactly how a medicine without a category is reported in the Sales analytics.
    }
}

migrate_order_item_category($pdo);

// FIX (admin login): shared lookup used by login.php (Admin), staff-login.php and
// api/login.php. Accepts an email OR a username: input is trimmed, both sides compare
// case-insensitively (LOWER(TRIM(...))), and a username match always wins when an email
// happens to collide with another account's username. Returns null when nothing matches.
// FIX (password reset): the ONE place a password hash is ever replaced. It writes the new bcrypt
// hash AND invalidates every existing session for that account (token_version), so after a reset
// the old password can no longer be used by anyone - including an already-signed-in browser.
function setUserPassword(PDO $pdo, int $userId, string $plainPassword): void
{
    $statement = $pdo->prepare(
        'UPDATE users
         SET password_hash = ?, password_changed_at = NOW(), token_version = token_version + 1
         WHERE id = ?'
    );
    $statement->execute([password_hash($plainPassword, PASSWORD_DEFAULT), $userId]);
}

function findUserForLogin(PDO $pdo, string $identifier): ?array {
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM users
         WHERE LOWER(TRIM(username)) = LOWER(:by_username)
            OR LOWER(TRIM(email)) = LOWER(:by_email)
         ORDER BY (LOWER(TRIM(username)) = LOWER(:order_username)) DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':by_username' => $identifier,
        ':by_email' => $identifier,
        ':order_username' => $identifier,
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user === false ? null : $user;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . staffLoginUrl());
        exit;
    }

    global $pdo;
    $userCheck = $pdo->prepare('SELECT full_name, username, role, status, token_version FROM users WHERE id = ? LIMIT 1');
    $userCheck->execute([(int)$_SESSION['user_id']]);
    $currentUser = $userCheck->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser || (string)$currentUser['status'] !== 'active') {
        session_unset();
        header('Location: ' . staffLoginUrl());
        exit;
    }

    // FIX (reset must kill old sessions): the password hash that authorised this session is the
    // one whose token_version was stamped at login. A password reset/replacement bumps the stored
    // value, so the comparison below ends every session still holding the OLD password - including
    // the one that performed the reset, which is why the user is sent back to a fresh login.
    $sessionTokenVersion = $_SESSION['token_version'] ?? null;
    if ($sessionTokenVersion === null) {
        // Sessions created before this column existed adopt the current value once.
        $_SESSION['token_version'] = (int)($currentUser['token_version'] ?? 1);
    } elseif ((int)$sessionTokenVersion !== (int)($currentUser['token_version'] ?? 1)) {
        session_unset();
        session_destroy();
        header('Location: ' . staffLoginUrl() . '?password_changed=1');
        exit;
    }

    $_SESSION['full_name'] = (string)$currentUser['full_name'];
    $_SESSION['username'] = (string)$currentUser['username'];
    $_SESSION['role'] = (string)$currentUser['role'];

    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
    if (($now - $lastActivity) > STAFF_SESSION_TIMEOUT_SECONDS) {
        session_unset();
        session_destroy();
        header('Location: ' . staffLoginUrl() . '?expired=1');
        exit;
    }

    $_SESSION['last_activity'] = $now;
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['role'] ?? 'staff') !== 'admin') {
        http_response_code(403);
        echo 'Access denied. Administrator privileges are required.';
        exit;
    }
}

// Restrict staff-only destinations to active staff users.
function requireStaffAccount(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'staff') {
        http_response_code(403);
        echo 'Access denied. A staff account is required.';
        exit;
    }
}// TASK 2/3: the old "staff cannot touch pharmacy pages at all" guard is gone. Pharmacy access is
// now a grantable PAGE PERMISSION, checked by requirePageAccess('pharmacy') on every Pharmacy page
// (see includes/permissions.php), so a staff member an admin grants Pharmacy can work there while
// everyone else is redirected to the Menu with an explanation.

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

// FIX (Bug 6): the settings row must exist before UPDATE ... WHERE id = 1 can work.
function ensure_settings_row(PDO $pdo): void {
    $row = $pdo->query('SELECT id FROM settings LIMIT 1')->fetch();
    if (!$row) {
        $pdo->exec("INSERT INTO settings (tax_rate, service_charge_rate, student_discount_rate, senior_discount_rate, pwd_discount_rate, remove_vat_for_discounted)
                    VALUES (12, 5, 10, 20, 20, 1)");
    }
}

function getSettings(PDO $pdo): array {
    // FIX (Bug 6): call ensure_settings_row() first so getSettings() can never return an empty set.
    ensure_settings_row($pdo);

    foreach (['student_discount_rate', 'senior_discount_rate', 'pwd_discount_rate', 'remove_vat_for_discounted'] as $column) {
        try {
            $pdo->query("SELECT {$column} FROM settings LIMIT 1")->fetch();
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN {$column} DECIMAL(5,2) DEFAULT 0");
        }
    }

    $stmt = $pdo->query('SELECT * FROM settings LIMIT 1');
    $row = $stmt->fetch();
    if ($row) {
        $row['student_discount_rate'] = (float)($row['student_discount_rate'] ?? 10);
        $row['senior_discount_rate'] = (float)($row['senior_discount_rate'] ?? 20);
        $row['pwd_discount_rate'] = (float)($row['pwd_discount_rate'] ?? 20);
        $row['remove_vat_for_discounted'] = (int)($row['remove_vat_for_discounted'] ?? 1);
    }
    return is_array($row) ? $row : [];
}

function format_currency($value): string {
    return '₱' . number_format((float) $value, 2);
}

// FIX (Bug 1): money snapshot shared by the printed and emailed receipts. A recorded payment proves
// the order was written by the POS charge flow, which stores every money column in one INSERT — so
// those saved values (including a legitimate ₱0.00 tax or service charge) are authoritative and the
// receipt stays identical when reopened later, even if tax/service rates changed since. Legacy
// orders with no payment snapshot (amount_received still 0.00) fall back to the calculated totals
// row by row instead of printing zeros everywhere.
function getReceiptTotals(array $order, array $calculatedTotals): array {
    $hasSnapshot = getReceiptPayment($order)['recorded'];
    $pick = static function (string $key, float $fallback) use ($order, $hasSnapshot): float {
        return $hasSnapshot ? (float)($order[$key] ?? 0) : $fallback;
    };

    return [
        'subtotal' => $pick('subtotal', (float)($calculatedTotals['subtotal'] ?? 0)),
        'discount' => $pick('discount', (float)($calculatedTotals['discount'] ?? 0)),
        'service_charge' => $pick('service_charge', (float)($calculatedTotals['service_charge'] ?? 0)),
        'tax' => $pick('tax', (float)($calculatedTotals['tax'] ?? 0)),
        'total' => $pick('total', (float)($calculatedTotals['total'] ?? 0)),
    ];
}

// FIX (Bug 1): payment snapshot for the receipt. amount_received <= 0 means "never recorded"
// (orders saved before the charge handler stored it), which the receipt renders as "—" instead
// of a misleading ₱0.00. Change is only meaningful when an amount was actually recorded.
function getReceiptPayment(array $order): array {
    $amountPaid = (float)($order['amount_received'] ?? 0);

    return [
        'recorded' => $amountPaid > 0,
        'amount_paid' => $amountPaid,
        'change' => (float)($order['change_amount'] ?? 0),
    ];
}

// FIX (Task 3): receipt email resolution — the email saved on the order wins; orders created
// before that column existed fall back to the linked customer's email (legacy source).
function getReceiptEmail(array $order): string {
    $email = trim((string)($order['customer_email'] ?? ''));
    if ($email === '') {
        $email = trim((string)($order['legacy_email'] ?? ($order['email'] ?? '')));
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

// FIX (Task 2): charge date/time for the receipt. The charge INSERT already stores paid_at = NOW()
// (created_at as fallback), so this formats the ORIGINAL charge moment from the saved record — a
// receipt reopened later shows the same time, never "now". Returns '' when no timestamp exists.
function getReceiptChargeDate(array $order): string {
    $timestamp = trim((string)($order['paid_at'] ?? ''));
    if ($timestamp === '' || $timestamp === '0000-00-00 00:00:00') {
        $timestamp = trim((string)($order['created_at'] ?? ''));
    }
    if ($timestamp === '' || $timestamp === '0000-00-00 00:00:00') {
        return '';
    }

    $ts = strtotime($timestamp);
    if ($ts === false) {
        return '';
    }

    return date('F j, Y', $ts) . ' — ' . date('g:i A', $ts);
}

function calculateTotals(array $items, array $settings, $discount = 0): array {
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += (float) $item['price'] * (int) $item['quantity'];
    }

    $serviceCharge = $subtotal * ((float) ($settings['service_charge_rate'] ?? 0) / 100);
    $tax = ($subtotal + $serviceCharge - (float)$discount) * ((float) ($settings['tax_rate'] ?? 0) / 100);
    $total = $subtotal + $serviceCharge + $tax - (float)$discount;

    return [
        'subtotal' => round($subtotal, 2),
        'discount' => round((float) $discount, 2),
        'service_charge' => round($serviceCharge, 2),
        'tax' => round($tax, 2),
        'total' => round($total, 2),
    ];
}

function getOrderBatchAllocations(PDO $pdo, int $orderId): array {
    $stmt = $pdo->prepare('SELECT a.order_item_id, a.medicine_id, a.batch_number, a.allocated_quantity, a.unit_price, a.expiration_date FROM order_item_batch_allocations a JOIN order_items oi ON oi.id = a.order_item_id WHERE oi.order_id = ? ORDER BY a.order_item_id ASC, a.expiration_date ASC, a.id ASC');
    $stmt->execute([$orderId]);
    $allocations = [];
    foreach ($stmt->fetchAll() as $row) {
        $allocations[(int)$row['order_item_id']][] = $row;
    }
    return $allocations;
}

function getSellableMedicineGroups(PDO $pdo): array {
    $rows = $pdo->query('SELECT * FROM medicines ORDER BY expiration_date ASC, id ASC')->fetchAll();
    $groups = [];
    $today = date('Y-m-d');

    foreach ($rows as $row) {
        $expiry = (string)($row['expiration_date'] ?? '');

        // FIX (Menu Rule 1/3): an expired batch is never sellable. Skip it BEFORE the medicine
        // group is created, so a medicine whose batches are all expired disappears from the Menu
        // completely, while expired stock stays untouched in the DB for Inventory badges and the
        // expiry pop-up alerts. A batch expiring today ($expiry === $today) is still sellable.
        if ($expiry === '' || $expiry < $today) {
            continue;
        }

        $name = trim((string)($row['name'] ?? ''));
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (!isset($groups[$key])) {
            $groups[$key] = $row;
            $groups[$key]['quantity'] = 0;
            $groups[$key]['stock'] = 0;
            $groups[$key]['batch_number'] = trim((string)($row['batch_number'] ?? ''));
            $groups[$key]['expiration_date'] = $row['expiration_date'] ?? null;
            $groups[$key]['price'] = (float)($row['price'] ?? 0);
            $groups[$key]['available_batch_count'] = 0;
            $groups[$key]['id'] = (int)$row['id'];
        }

        $quantity = (int)($row['quantity'] ?? 0);
        if ($quantity <= 0) {
            // FIX (Menu Rule 2): an empty but still-dated batch keeps its medicine on the Menu
            // as "Out of Stock" (existing behavior) without contributing any quantity.
            continue;
        }

        if ($groups[$key]['available_batch_count'] === 0) {
            $groups[$key]['id'] = (int)$row['id'];
            $groups[$key]['price'] = (float)$row['price'];
            $groups[$key]['batch_number'] = trim((string)($row['batch_number'] ?? ''));
            $groups[$key]['expiration_date'] = $expiry;
            $groups[$key]['category'] = trim((string)($row['category'] ?? 'Uncategorized')) ?: 'Uncategorized';
            $groups[$key]['image'] = $row['image'] ?? null;
        }

        $groups[$key]['quantity'] += $quantity;
        $groups[$key]['stock'] = $groups[$key]['quantity'];
        $groups[$key]['available_batch_count']++;
    }

    return array_values($groups);
}

// TASK 1 (category data): resolve a submitted category name to the name already stored in the
// shared `categories` table (case-insensitive), so "vitamins" and "Vitamins" can never split the
// Sales breakdown into two categories. Unknown names are inserted once and returned as typed; a
// missing/blank name is always 'Uncategorized'. Returns the canonical name to store on a medicine.
function canonicalCategoryName(PDO $pdo, string $categoryName): string
{
    $categoryName = trim($categoryName);
    if ($categoryName === '') {
        return 'Uncategorized';
    }

    try {
        $find = $pdo->prepare('SELECT name FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY id ASC LIMIT 1');
        $find->execute([$categoryName]);
        $existing = $find->fetchColumn();
        if ($existing !== false) {
            return trim((string)$existing);
        }

        getOrCreateCategoryId($pdo, $categoryName);
    } catch (PDOException $e) {
        // The `categories` table is optional for this write path — never block the medicine save.
        error_log('[Heartfelt Pharmacy] category canonicalisation failed: ' . $e->getMessage());
    }

    return $categoryName;
}

function getOrCreateCategoryId(PDO $pdo, string $categoryName): int {
    $categoryName = trim($categoryName) ?: 'Uncategorized';
    $findCategory = $pdo->prepare('SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY id ASC LIMIT 1');
    $findCategory->execute([$categoryName]);
    $categoryId = $findCategory->fetchColumn();
    if ($categoryId !== false) {
        return (int)$categoryId;
    }

    try {
        $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$categoryName]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // A concurrent request may have created the same category after the lookup.
        $findCategory->execute([$categoryName]);
        $categoryId = $findCategory->fetchColumn();
        if ($categoryId !== false) {
            return (int)$categoryId;
        }
        throw $e;
    }
}

function assignMedicineBatchNumber(PDO $pdo, string $medicineName, string $requestedBatch = ''): string {
    $stmt = $pdo->prepare('SELECT batch_number FROM medicines WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))');
    $stmt->execute([$medicineName]);
    $existing = array_map(static function (array $row): string {
        return trim((string)($row['batch_number'] ?? ''));
    }, $stmt->fetchAll());

    $requestedBatch = trim($requestedBatch);
    if ($requestedBatch !== '') {
        foreach ($existing as $batch) {
            if (strcasecmp($batch, $requestedBatch) === 0) {
                throw new InvalidArgumentException('That batch number already exists for this medicine.');
            }
        }
        return $requestedBatch;
    }

    $largestNumber = 0;
    foreach ($existing as $batch) {
        if (preg_match('/^batch[\s-]*(\d+)$/i', $batch, $match)) {
            $largestNumber = max($largestNumber, (int)$match[1]);
        }
    }

    $number = max(count($existing), $largestNumber) + 1;
    do {
        $candidate = 'Batch ' . $number++;
        $alreadyUsed = false;
        foreach ($existing as $batch) {
            if (strcasecmp($batch, $candidate) === 0) {
                $alreadyUsed = true;
                break;
            }
        }
    } while ($alreadyUsed);

    return $candidate;
}

// FIX (Bug 4): single reusable stock-deduction helper used by every sale path (Bug 4 requirement).
function reduceStock(PDO $pdo, int $medicineId, int $qty): void {
    $qty = max(0, $qty);
    if ($medicineId <= 0 || $qty <= 0) {
        return;
    }
    // FIX (Menu Rule 3): no code path may deduct stock from an expired batch — the shared
    // helper carries the same expiry guard as the charge transaction and add_item endpoint.
    $stmt = $pdo->prepare('UPDATE medicines SET quantity = GREATEST(quantity - ?, 0) WHERE id = ? AND expiration_date >= ?');
    $stmt->execute([$qty, $medicineId, date('Y-m-d')]);
}

// FIX (Bug 4): charge now runs order + items + stock deductions in ONE transaction and returns fresh stock.
function savePaidOrderTransaction(PDO $pdo, array $orderPayload): array {
    $items = is_array($orderPayload['items'] ?? null) ? $orderPayload['items'] : [];
    if (empty($items)) {
        return ['success' => false, 'message' => 'Order must contain at least one item.'];
    }

    $discountType = trim((string)($orderPayload['discount_type'] ?? 'none')) ?: 'none';
    $discountIdNumber = trim((string)($orderPayload['discount_id_number'] ?? ''));
    if (!in_array($discountType, ['none', 'student', 'senior', 'pwd'], true)) {
        return ['success' => false, 'message' => 'The selected discount type is invalid.'];
    }
    if ($discountType !== 'none' && $discountIdNumber === '') {
        return ['success' => false, 'message' => 'A discount ID number is required for this discount type.'];
    }

    $amountReceived = round((float)($orderPayload['amount_received'] ?? 0), 2);

    // FIX (Task 3): optional customer email — stored on the order so "Email Receipt" can address
    // it later. An invalid value is dropped (null) rather than failing the whole charge.
    $customerEmail = trim((string)($orderPayload['customer_email'] ?? ''));
    if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $customerEmail = '';
    }

    $customerId = (int)($orderPayload['customer_id'] ?? 0);
    try {
        $settings = getSettings($pdo);
        $pdo->beginTransaction();
        try {
            $requestedGroups = [];
            $medicineLookup = $pdo->prepare('SELECT name FROM medicines WHERE id = ?');
            foreach ($items as $item) {
                $medicineId = (int)($item['medicine_id'] ?? $item['id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? $item['qty'] ?? 0);
                if ($medicineId <= 0 || $quantity <= 0) {
                    throw new DomainException('Each order item must have a valid medicine and quantity.');
                }

                $medicineLookup->execute([$medicineId]);
                $medicine = $medicineLookup->fetch();
                if (!$medicine) {
                    throw new DomainException('A selected medicine is no longer available.');
                }

                $name = trim((string)$medicine['name']);
                $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                if (!isset($requestedGroups[$key])) {
                    $requestedGroups[$key] = [
                        'name' => $name,
                        'quantity' => 0,
                        'image' => trim((string)($item['image'] ?? '')),
                    ];
                }
                $requestedGroups[$key]['quantity'] += $quantity;
            }
            ksort($requestedGroups, SORT_STRING);

            // FIX (Menu Rule 3/5): FEFO — only non-expired batches can be locked and sold,
            // earliest expiry first. :today is PHP's date in the app timezone (Asia/Manila),
            // never MySQL's CURDATE(), so checkout and the Menu always agree on "expired".
            $today = date('Y-m-d');
            // TASK 1: `category` rides along with each locked batch so the sale can store the
            // category SNAPSHOT on the order item (see the INSERT below).
            $lockBatches = $pdo->prepare('SELECT id, name, batch_number, quantity, expiration_date, price, image, category FROM medicines WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) AND quantity > 0 AND expiration_date >= :today ORDER BY expiration_date ASC, id ASC FOR UPDATE');
            $plans = [];
            foreach ($requestedGroups as $key => $requested) {
                $lockBatches->execute([':name' => $requested['name'], ':today' => $today]);
                $batches = $lockBatches->fetchAll();
                $available = array_sum(array_map(static function (array $batch): int {
                    return (int)$batch['quantity'];
                }, $batches));
                if ($requested['quantity'] > $available) {
                    if ($available === 0) {
                        // FIX (Menu Rule 3): with zero sellable stock the cart item most likely
                        // expired after it was added — look up the expired batch so the POS can
                        // remove the line and tell the cashier exactly which batch went bad.
                        $expiredStmt = $pdo->prepare('SELECT batch_number FROM medicines WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) AND quantity > 0 AND expiration_date < :today ORDER BY expiration_date DESC, id ASC LIMIT 1');
                        $expiredStmt->execute([':name' => $requested['name'], ':today' => $today]);
                        $expiredBatch = trim((string)$expiredStmt->fetchColumn());
                        if ($expiredBatch !== '') {
                            throw new DomainException('EXPIRED_ITEM:' . $expiredBatch . '|' . $requested['name']);
                        }
                    }
                    throw new DomainException('Not enough unexpired stock for ' . $requested['name'] . '. Available: ' . $available . '.');
                }

                $remaining = $requested['quantity'];
                $allocations = [];
                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $allocated = min($remaining, (int)$batch['quantity']);
                    $allocations[] = [
                        'id' => (int)$batch['id'],
                        'batch_number' => trim((string)$batch['batch_number']),
                        'quantity' => $allocated,
                        'unit_price' => (float)$batch['price'],
                        'expiration_date' => $batch['expiration_date'],
                    ];
                    $remaining -= $allocated;
                }

                // TASK 1: snapshot the sold medicine's category. A medicine with no category (or
                // only whitespace) is recorded as 'Uncategorized', which is also how the Sales
                // analytics report any legacy row.
                $soldCategory = trim((string)($batches[0]['category'] ?? ''));

                $plans[] = [
                    'medicine_id' => (int)$batches[0]['id'],
                    'name' => (string)$batches[0]['name'],
                    'quantity' => $requested['quantity'],
                    'price' => (float)$batches[0]['price'],
                    'image' => trim((string)($batches[0]['image'] ?? '')) ?: ($requested['image'] ?: null),
                    'category' => $soldCategory === '' ? 'Uncategorized' : $soldCategory,
                    'allocations' => $allocations,
                ];
            }

            $subtotal = 0.0;
            foreach ($plans as $plan) {
                $subtotal += $plan['price'] * $plan['quantity'];
            }
            $subtotal = round($subtotal, 2);

            $discountRateKey = $discountType === 'student' ? 'student_discount_rate' : ($discountType === 'senior' ? 'senior_discount_rate' : ($discountType === 'pwd' ? 'pwd_discount_rate' : null));
            $discountRate = $discountRateKey !== null ? max(0, (float)($settings[$discountRateKey] ?? 0)) : 0;
            $discountAmount = round($subtotal * $discountRate / 100, 2);
            $serviceCharge = round($subtotal * max(0, (float)($settings['service_charge_rate'] ?? 0)) / 100, 2);
            $taxRate = (in_array($discountType, ['senior', 'pwd'], true) && !empty($settings['remove_vat_for_discounted']))
                ? 0
                : max(0, (float)($settings['tax_rate'] ?? 0));
            $tax = round(($subtotal - $discountAmount + $serviceCharge) * $taxRate / 100, 2);
            $total = round($subtotal - $discountAmount + $serviceCharge + $tax, 2);
            $change = round($amountReceived - $total, 2);

            if ($total <= 0) {
                throw new DomainException('Order total must be greater than zero.');
            }
            if ($amountReceived < $total) {
                throw new DomainException('Amount received is less than the total due. Updated total: ' . number_format($total, 2) . '.');
            }

            if ($customerId <= 0) {
                $customerStmt = $pdo->prepare('INSERT INTO customers (name, phone, email, table_number) VALUES (?, ?, ?, ?)');
                $customerStmt->execute(['Walk-in Customer', '', '', '']);
                $customerId = (int)$pdo->lastInsertId();
            }

            $insertOrder = $pdo->prepare('INSERT INTO orders (customer_id, order_type, status, subtotal, discount, discount_type, discount_id_number, service_charge, tax, total, amount_received, change_amount, customer_email, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $insertOrder->execute([
                $customerId,
                'Dine In',
                'paid',
                $subtotal,
                $discountAmount,
                $discountType,
                $discountIdNumber !== '' ? $discountIdNumber : null,
                $serviceCharge,
                $tax,
                $total,
                $amountReceived,
                $change,
                $customerEmail !== '' ? $customerEmail : null,
            ]);
            $orderId = (int)$pdo->lastInsertId();

            $savedItems = [];
            // TASK 1: category is stored WITH the sale (snapshot), so old sales keep reporting the
            // category they were sold under even after a medicine is re-categorised.
            $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, medicine_id, product_id, quantity, name, price, image, category) VALUES (?, ?, 0, ?, ?, ?, ?, ?)');
            $insertAllocation = $pdo->prepare('INSERT INTO order_item_batch_allocations (order_item_id, medicine_id, batch_number, allocated_quantity, unit_price, expiration_date) VALUES (?, ?, ?, ?, ?, ?)');
            // FIX (Menu Rule 3): the deduction itself refuses to touch an expired batch — a sale
            // can only decrement stock whose expiry_date is still >= today.
            $deductBatch = $pdo->prepare('UPDATE medicines SET quantity = quantity - :dec WHERE id = :id AND quantity >= :have AND expiration_date >= :today');
            foreach ($plans as $plan) {
                $insertItem->execute([
                    $orderId,
                    $plan['medicine_id'],
                    $plan['quantity'],
                    $plan['name'],
                    $plan['price'],
                    $plan['image'],
                    $plan['category'],
                ]);
                $orderItemId = (int)$pdo->lastInsertId();
                foreach ($plan['allocations'] as $allocation) {
                    $deductBatch->execute([
                        ':dec' => $allocation['quantity'],
                        ':id' => $allocation['id'],
                        ':have' => $allocation['quantity'],
                        ':today' => $today,
                    ]);
                    if ($deductBatch->rowCount() !== 1) {
                        throw new DomainException('Stock changed while charging. Please review the order and try again.');
                    }
                    $insertAllocation->execute([
                        $orderItemId,
                        $allocation['id'],
                        $allocation['batch_number'],
                        $allocation['quantity'],
                        $allocation['unit_price'],
                        $allocation['expiration_date'],
                    ]);
                }
                $savedItems[] = [
                    'medicine_id' => $plan['medicine_id'],
                    'name' => $plan['name'],
                    'quantity' => $plan['quantity'],
                    'price' => $plan['price'],
                ];
            }

            $pdo->prepare('INSERT INTO admin_logs (action, details) VALUES (?, ?)')->execute([
                'order_paid',
                'Paid order #' . $orderId . ' via checkout.'
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!$e instanceof DomainException) {
                error_log('[Heartfelt Pharmacy] Charge transaction failed: ' . $e->getMessage());
                return ['success' => false, 'message' => 'The charge could not be completed. Please try again.'];
            }
            $message = $e->getMessage();
            // FIX (Menu Rule 3): an expired cart item rolls back with a structured payload the POS
            // uses to drop that medicine from the cart and show which batch went bad.
            if (strpos($message, 'EXPIRED_ITEM:') === 0) {
                $parts = explode('|', substr($message, 13), 2);
                $expiredBatch = $parts[0];
                $expiredName = $parts[1] ?? '';
                return [
                    'success' => false,
                    'code' => 'expired_items',
                    'expired' => [['name' => $expiredName, 'batch_number' => $expiredBatch]],
                    'message' => $expiredName . ' (' . $expiredBatch . ') has expired and was removed from your order.',
                ];
            }
            return ['success' => false, 'message' => $message];
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'medicines' => getSellableMedicineGroups($pdo),
            'items' => $savedItems,
            'totals' => [
                'subtotal' => $subtotal,
                'discount' => $discountAmount,
                'service_charge' => $serviceCharge,
                'tax' => $tax,
                'total' => $total,
                'amount_received' => $amountReceived,
                'change' => $change,
            ],
        ];
    } catch (PDOException $e) {
        error_log('[Heartfelt Pharmacy] Charge failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'The charge could not be completed. Please try again.'];
    }
}

// FIX (Bug 6): delete a single order + its items atomically.
function deleteOrderById(PDO $pdo, int $orderId): bool {
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

// FIX (Bug 6): permanently clear all order history (orders + items only — inventory untouched).
//
// Order numbers ARE orders.id (AUTO_INCREMENT), so clearing history must also restart the
// counter, otherwise the next sale keeps numbering from the old max (e.g. #96).
//
// Everything runs inside ONE transaction — children first, then parents, then the reset:
//  * The DELETEs hold row/gap locks, so a concurrent checkout cannot slip an order in
//    between "empty" and "restart".
//  * ALTER TABLE ... AUTO_INCREMENT is DDL and implicitly commits on success; if it fails,
//    nothing was committed and the ROLLBACK undoes the deletes too. Delete + reset therefore
//    succeed or fail together.
//  * The counter is only restarted when ZERO rows remain. If any order survived, numbering
//    continues normally (max + 1) instead of ever colliding with an existing id.
function clearOrderHistory(PDO $pdo): array {
    $pdo->beginTransaction();
    try {
        // Children first (CASCADE would cover it, but explicit order keeps the delete deterministic).
        $pdo->exec('DELETE FROM order_item_batch_allocations');
        $pdo->exec('DELETE FROM order_items');
        $deletedOrders = (int) $pdo->exec('DELETE FROM orders');

        $remaining = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $counterReset = false;
        if ($remaining === 0) {
            $pdo->exec('ALTER TABLE orders AUTO_INCREMENT = 1');
            $counterReset = true;
        }

        // After the implicit commit this INSERT runs in autocommit — same outcome either way.
        $pdo->prepare('INSERT INTO admin_logs (action, details) VALUES (?, ?)')->execute([
            'history_cleared',
            'Order history cleared (' . $deletedOrders . ' order(s) deleted)'
                . ($counterReset ? '; order numbering restarted at #1.' : '; numbering unchanged because records remain.'),
        ]);

        if (!$counterReset) {
            $pdo->commit();
        }

        return ['success' => true, 'deleted' => $deletedOrders, 'counter_reset' => $counterReset];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/* ============================================================================================
 * TASK 1 — SALES ANALYTICS (the Sales page)
 *
 * Source of truth: the SAME completed orders History shows — `orders` with status = 'paid' and
 * `order_items`. There is no second copy of sales data anywhere; every number below is an
 * aggregate computed in SQL (never by shipping rows to the browser).
 *
 * A cancelled or voided order can never be counted: only status = 'paid' is selected (an order
 * that is 'open', 'cancelled', 'voided' or 'refunded' is excluded by that predicate).
 *
 * Timezone: period boundaries are built with PHP's clock (Asia/Manila, set at the top of this
 * file) and compared against paid_at, whose column type is a naive local timestamp — the PDO
 * session time_zone is pinned to the same offset above, so "today" means the shop's today.
 * ============================================================================================ */

// Where a paid order counts. 'paid' is the only completed state the POS writes, so this predicate
// is also what excludes cancelled/voided rows.
const SALES_PAID_FILTER = "o.status = 'paid' AND o.paid_at IS NOT NULL AND o.paid_at >= :start AND o.paid_at < :end";

/**
 * Turn the requested period + user input into concrete [start, end) bounds plus the previous
 * period to compare against. Invalid input falls back to the current day/month/year instead of
 * erroring, so a hand-typed URL can never break the page.
 *
 * Returns ['period', 'start', 'end', 'prev_start', 'prev_end', 'label', 'previous_label',
 *          'input' => ['date','month','year'] normalized values].
 */
function salesPeriodBounds(string $period, array $input = []): array
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $today = new DateTimeImmutable('today', $tz);
    $period = in_array($period, ['daily', 'monthly', 'yearly'], true) ? $period : 'daily';

    if ($period === 'monthly') {
        $start = null;
        $monthInput = trim((string)($input['month'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $monthInput, $parts)) {
            $year = (int)$parts[1];
            $monthNumber = (int)$parts[2];
            if ($year >= 2000 && $year <= 2100 && $monthNumber >= 1 && $monthNumber <= 12) {
                $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $monthNumber), $tz);
            }
        }
        if ($start === null) {
            $start = $today->modify('first day of this month');
        }

        return [
            'period' => 'monthly',
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $start->modify('+1 month')->format('Y-m-d H:i:s'),
            'prev_start' => $start->modify('-1 month')->format('Y-m-d H:i:s'),
            'prev_end' => $start->format('Y-m-d H:i:s'),
            'label' => $start->format('F Y'),
            'previous_label' => $start->modify('-1 month')->format('F Y'),
            'input' => ['month' => $start->format('Y-m')],
        ];
    }

    if ($period === 'yearly') {
        $yearInput = trim((string)($input['year'] ?? ''));
        $year = (int)$yearInput;
        if (!preg_match('/^\d{4}$/', $yearInput) || $year < 2000 || $year > 2100) {
            $year = (int)$today->format('Y');
        }
        $start = new DateTimeImmutable($year . '-01-01 00:00:00', $tz);

        return [
            'period' => 'yearly',
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $start->modify('+1 year')->format('Y-m-d H:i:s'),
            'prev_start' => $start->modify('-1 year')->format('Y-m-d H:i:s'),
            'prev_end' => $start->format('Y-m-d H:i:s'),
            'label' => $start->format('Y'),
            'previous_label' => $start->modify('-1 year')->format('Y'),
            'input' => ['year' => $start->format('Y')],
        ];
    }

    $dateInput = trim((string)($input['date'] ?? ''));
    $day = null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateInput, $parts)) {
        $year = (int)$parts[1];
        $monthNumber = (int)$parts[2];
        $dayNumber = (int)$parts[3];
        if (checkdate($monthNumber, $dayNumber, $year)) {
            $day = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $monthNumber, $dayNumber), $tz);
        }
    }
    if ($day === null) {
        $day = $today;
    }

    return [
        'period' => 'daily',
        'start' => $day->format('Y-m-d H:i:s'),
        'end' => $day->modify('+1 day')->format('Y-m-d H:i:s'),
        'prev_start' => $day->modify('-1 day')->format('Y-m-d H:i:s'),
        'prev_end' => $day->format('Y-m-d H:i:s'),
        'label' => $day->format('F j, Y'),
        'previous_label' => $day->modify('-1 day')->format('F j, Y'),
        'input' => ['date' => $day->format('Y-m-d')],
    ];
}

/** Total sales, number of orders, items sold and average order value for one [start, end) window. */
function salesPeriodTotals(PDO $pdo, string $start, string $end): array
{
    $headers = $pdo->prepare('SELECT COUNT(*) AS order_count, COALESCE(SUM(o.total), 0) AS revenue FROM orders o WHERE ' . SALES_PAID_FILTER);
    $headers->execute([':start' => $start, ':end' => $end]);
    $head = $headers->fetch(PDO::FETCH_ASSOC) ?: ['order_count' => 0, 'revenue' => 0];

    // Items are counted in their own query: joining order_items into the header query above would
    // multiply the SUM(o.total) rows and inflate the revenue.
    $items = $pdo->prepare('SELECT COALESCE(SUM(oi.quantity), 0) AS item_count FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE ' . SALES_PAID_FILTER);
    $items->execute([':start' => $start, ':end' => $end]);

    $orderCount = (int)$head['order_count'];
    $revenue = round((float)$head['revenue'], 2);

    return [
        'revenue' => $revenue,
        'orders' => $orderCount,
        'items' => (int)$items->fetchColumn(),
        'average' => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0.0,
    ];
}

/**
 * Chart buckets for the selected period: hours of the day (Daily), days of the month (Monthly)
 * or months of the year (Yearly). Every bucket is present, including empty ones, so the chart
 * never shifts its axis when a quiet hour has no sales.
 *
 * Returns ['labels' => [...], 'revenue' => [...], 'orders' => [...]].
 */
function salesPeriodSeries(PDO $pdo, string $period, string $start, string $end): array
{
    if ($period === 'yearly') {
        $bucketSql = 'MONTH(o.paid_at)';
        $buckets = range(1, 12);
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    } elseif ($period === 'monthly') {
        $bucketSql = 'DAY(o.paid_at)';
        $startDate = new DateTimeImmutable($start);
        $buckets = range(1, (int)$startDate->format('t'));
        $labels = array_map(static fn (int $day): string => (string)$day, $buckets);
    } else {
        $bucketSql = 'HOUR(o.paid_at)';
        $buckets = range(0, 23);
        $labels = array_map(static function (int $hour): string {
            if ($hour === 0) {
                return '12 AM';
            }
            return $hour < 12 ? $hour . ' AM' : ($hour === 12 ? '12 PM' : ($hour - 12) . ' PM');
        }, $buckets);
    }

    $stmt = $pdo->prepare('SELECT ' . $bucketSql . ' AS bucket, COUNT(*) AS order_count, COALESCE(SUM(o.total), 0) AS revenue FROM orders o WHERE ' . SALES_PAID_FILTER . ' GROUP BY bucket');
    $stmt->execute([':start' => $start, ':end' => $end]);

    $revenueByBucket = [];
    $ordersByBucket = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $revenueByBucket[(int)$row['bucket']] = round((float)$row['revenue'], 2);
        $ordersByBucket[(int)$row['bucket']] = (int)$row['order_count'];
    }

    $revenue = [];
    $orders = [];
    foreach ($buckets as $bucket) {
        $revenue[] = $revenueByBucket[$bucket] ?? 0.0;
        $orders[] = $ordersByBucket[$bucket] ?? 0;
    }

    return ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders];
}

/**
 * Which medicine categories sell most in the window, by QUANTITY sold (the pie chart's default)
 * and by REVENUE (its toggle). Categories are read from the order item's snapshot, so a medicine
 * that was re-categorised later still reports the category it was sold under. A missing/blank
 * snapshot counts as 'Uncategorized'.
 */
function salesCategoryBreakdown(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(oi.category), ''), 'Uncategorized') AS category,
                SUM(oi.quantity) AS quantity,
                ROUND(SUM(oi.quantity * oi.price), 2) AS revenue
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE " . SALES_PAID_FILTER . "
         GROUP BY category
         ORDER BY quantity DESC, category ASC"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);

    return array_map(static function (array $row): array {
        return [
            'category' => (string)$row['category'],
            'quantity' => (int)$row['quantity'],
            'revenue' => round((float)$row['revenue'], 2),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Best selling medicines (name, category, quantity, revenue) for the window. */
function salesTopMedicines(PDO $pdo, string $start, string $end, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    // LIMIT cannot be a bound parameter on every MySQL build, so it is cast to int and inlined.
    $stmt = $pdo->prepare(
        "SELECT TRIM(oi.name) AS name,
                COALESCE(NULLIF(TRIM(oi.category), ''), 'Uncategorized') AS category,
                SUM(oi.quantity) AS quantity,
                ROUND(SUM(oi.quantity * oi.price), 2) AS revenue
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE " . SALES_PAID_FILTER . "
         GROUP BY name, category
         ORDER BY quantity DESC, revenue DESC, name ASC
         LIMIT " . $limit
    );
    $stmt->execute([':start' => $start, ':end' => $end]);

    return array_map(static function (array $row): array {
        return [
            'name' => (string)$row['name'],
            'category' => (string)$row['category'],
            'quantity' => (int)$row['quantity'],
            'revenue' => round((float)$row['revenue'], 2),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Percentage change vs the previous period; null when there is no baseline to compare against. */
function salesChangePercent(float $current, float $previous): ?float
{
    if ($previous <= 0) {
        return $current > 0 ? 100.0 : null;
    }

    return round((($current - $previous) / $previous) * 100, 1);
}

/**
 * The ONE assembler: the Sales page and api/sales.php both call this, so the numbers on screen and
 * the numbers the charts fetch can never disagree. All aggregates run in SQL.
 */
function salesReport(PDO $pdo, string $period, array $input = []): array
{
    $bounds = salesPeriodBounds($period, $input);
    $totals = salesPeriodTotals($pdo, $bounds['start'], $bounds['end']);
    $previousTotals = salesPeriodTotals($pdo, $bounds['prev_start'], $bounds['prev_end']);
    $series = salesPeriodSeries($pdo, $bounds['period'], $bounds['start'], $bounds['end']);
    $categories = salesCategoryBreakdown($pdo, $bounds['start'], $bounds['end']);

    return [
        'period' => $bounds['period'],
        'range' => [
            'start' => $bounds['start'],
            'end' => $bounds['end'],
            'label' => $bounds['label'],
        ],
        'previous_range' => [
            'start' => $bounds['prev_start'],
            'end' => $bounds['prev_end'],
            'label' => $bounds['previous_label'],
        ],
        'input' => $bounds['input'],
        'totals' => $totals,
        'previous' => $previousTotals,
        'change' => [
            'revenue' => salesChangePercent($totals['revenue'], $previousTotals['revenue']),
            'orders' => salesChangePercent((float)$totals['orders'], (float)$previousTotals['orders']),
            'items' => salesChangePercent((float)$totals['items'], (float)$previousTotals['items']),
            'average' => salesChangePercent($totals['average'], $previousTotals['average']),
        ],
        'series' => $series,
        'categories' => $categories,
        'top_medicines' => salesTopMedicines($pdo, $bounds['start'], $bounds['end']),
        'has_sales' => $totals['orders'] > 0,
        'generated_at' => date('c'),
    ];
}
