<?php
/**
 * TASK 2 + TASK 3 — the ONE page-permission source.
 *
 * Every navigation item, every route guard and every JSON endpoint asks THIS file what a user may
 * open, so the sidebar, the Menu header buttons and the server-side checks can never disagree.
 *
 * Rules:
 *   * admin            -> every page, always (cannot be reduced).
 *   * new staff        -> menu, history, help ONLY (the locked-on defaults).
 *   * grantable pages  -> sales, inventory_dashboard, inventory_alerts, pharmacy. An admin grants
 *                         these per user from the Users page ("Manage access").
 *   * users / settings -> admin-only. They are NOT grantable, and a row that somehow appeared in
 *                         user_pages for them is ignored.
 *
 * Grants live in the `user_pages` table and are re-read from the database on every request (cached
 * for the lifetime of one request only). That is deliberate: revoking a permission takes effect on
 * the user's very next click, with no new sign-up and no stale session copy to expire.
 */

require_once __DIR__ . '/../config/db.php';

/**
 * The page registry: key => [label, icon, url, staff_default, grantable, admin_only].
 * Order in this array is the order of the sidebar.
 */
function pageDefinitions(): array
{
    static $pages = null;
    if ($pages !== null) {
        return $pages;
    }

    $pages = [
        'menu' => [
            'label' => 'Menu',
            'icon' => 'fa-solid fa-burger',
            'url' => 'menu.php',
            'title' => 'Menu',
            'staff_default' => true,
            'grantable' => false,
            'admin_only' => false,
        ],
        'history' => [
            'label' => 'History',
            'icon' => 'fa-solid fa-clock-rotate-left',
            'url' => 'history.php',
            'title' => 'History',
            'staff_default' => true,
            'grantable' => false,
            'admin_only' => false,
        ],
        // TASK 1: the Sales page sits directly below History.
        'sales' => [
            'label' => 'Sales',
            'icon' => 'fa-solid fa-chart-line',
            'url' => 'sales.php',
            'title' => 'Sales',
            'description' => 'Sales totals, charts and the category breakdown',
            'staff_default' => false,
            'grantable' => true,
            'admin_only' => false,
        ],
        'users' => [
            'label' => 'Users',
            'icon' => 'fa-solid fa-users',
            'url' => 'users.php',
            'title' => 'Users',
            'staff_default' => false,
            'grantable' => false,
            'admin_only' => true,
        ],
        'settings' => [
            'label' => 'Setting',
            'icon' => 'fa-solid fa-gear',
            'url' => 'settings.php',
            'title' => 'Settings',
            'staff_default' => false,
            'grantable' => false,
            'admin_only' => true,
        ],
        'pharmacy' => [
            'label' => 'Pharmacy',
            'icon' => 'fa-solid fa-capsules',
            'url' => 'pharmacy_dashboard.php',
            'title' => 'Pharmacy',
            'description' => 'Pharmacy inventory: add, edit and review stock',
            'staff_default' => false,
            'grantable' => true,
            'admin_only' => false,
        ],
        // Not a sidebar destination: it is reached from the Inventory Dashboard button in the Menu
        // header, so 'sidebar' => false keeps the sidebar exactly as it was before.
        'inventory_dashboard' => [
            'label' => 'Inventory Dashboard',
            'icon' => 'fa-solid fa-boxes-stacked',
            'url' => 'inventory-dashboard.php',
            'title' => 'Inventory Dashboard',
            'description' => 'The Inventory Dashboard button in the Menu header',
            'sidebar' => false,
            'staff_default' => false,
            'grantable' => true,
            'admin_only' => false,
        ],
        // Not a sidebar destination either: it is the alert bell + pop-up in the sidebar footer, so
        // rendering a second "Inventory Alerts" link would duplicate that control.
        'inventory_alerts' => [
            'label' => 'Inventory Alerts',
            'icon' => 'fa-solid fa-bell',
            'url' => 'pharmacy_alerts.php',
            'title' => 'Inventory Alerts',
            'description' => 'The alert bell, pop-ups and the Alert Center',
            'sidebar' => false,
            'staff_default' => false,
            'grantable' => true,
            'admin_only' => false,
        ],
        'help' => [
            'label' => 'Help Center',
            'icon' => 'fa-solid fa-circle-question',
            'url' => 'help.php',
            'title' => 'Help Center',
            'staff_default' => true,
            'grantable' => false,
            'admin_only' => false,
        ],
    ];

    return $pages;
}

/** Pages a brand-new staff account can open without any grant. */
function defaultStaffPages(): array
{
    static $defaults = null;
    if ($defaults === null) {
        $defaults = array_keys(array_filter(pageDefinitions(), static fn (array $page): bool => !empty($page['staff_default'])));
    }

    return $defaults;
}

/** Pages an admin may hand to a staff member from the Users page. */
function grantablePages(): array
{
    static $grantable = null;
    if ($grantable === null) {
        $grantable = array_keys(array_filter(pageDefinitions(), static fn (array $page): bool => !empty($page['grantable'])));
    }

    return $grantable;
}

/** Pages no staff account can ever be granted (Users, Setting). */
function adminOnlyPages(): array
{
    static $adminOnly = null;
    if ($adminOnly === null) {
        $adminOnly = array_keys(array_filter(pageDefinitions(), static fn (array $page): bool => !empty($page['admin_only'])));
    }

    return $adminOnly;
}

/** Every page key that exists — an admin has all of them. */
function allPageKeys(): array
{
    return array_keys(pageDefinitions());
}

function isValidPageKey(string $page): bool
{
    return array_key_exists($page, pageDefinitions());
}

function pageLabel(string $page): string
{
    $definitions = pageDefinitions();

    return $definitions[$page]['label'] ?? ucfirst($page);
}

/** One-line explanation shown next to each checkbox in the Users page access modal. */
function pageDescription(string $page): string
{
    $definitions = pageDefinitions();

    return $definitions[$page]['description'] ?? '';
}

/** Human-readable list used by the Users page badges, e.g. "Sales, Pharmacy". */
function pageLabels(array $pages): string
{
    return implode(', ', array_map('pageLabel', $pages));
}

/**
 * The extra grants stored for one user (never includes the locked-on defaults or admin-only
 * pages, so a stale row can never widen access). Reads the DB directly — used by the Users page
 * table so the badges show the stored truth.
 */
function storedPageGrants(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT page FROM user_pages WHERE user_id = ?');
    $stmt->execute([$userId]);
    $granted = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $page) {
        $page = (string)$page;
        if (in_array($page, grantablePages(), true)) {
            $granted[] = $page;
        }
    }

    // Keep the sidebar's order (the registry order), not the insertion order.
    $ordered = [];
    foreach (array_keys(pageDefinitions()) as $key) {
        if (in_array($key, $granted, true)) {
            $ordered[] = $key;
        }
    }

    return $ordered;
}

/**
 * Effective allowed pages for a user: admin = everything, staff = defaults + stored grants.
 * Always returned in REGISTRY order, so every consumer (sidebar, badges, tests) sees one stable
 * ordering instead of "defaults then whatever was granted first".
 */
function allowedPagesForUser(PDO $pdo, int $userId, string $role): array
{
    if ($role === 'admin') {
        return allPageKeys();
    }

    $allowed = array_values(array_unique(array_merge(defaultStaffPages(), storedPageGrants($pdo, $userId))));
    $ordered = [];
    foreach (array_keys(pageDefinitions()) as $key) {
        if (in_array($key, $allowed, true)) {
            $ordered[] = $key;
        }
    }

    return $ordered;
}

/**
 * Allowed pages for the signed-in user, resolved at most ONCE per request. Re-reading the grants
 * on each request (rather than trusting a session copy) is what makes a revoked page stop working
 * immediately — including for a browser tab that is already open.
 */
function currentUserAllowedPages(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    global $pdo;
    if (empty($_SESSION['user_id'])) {
        return $cache = [];
    }

    return $cache = allowedPagesForUser($pdo, (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? 'staff'));
}

/** Does the signed-in user have this page? Admin always does. */
function userCanAccess(string $page): bool
{
    return in_array($page, currentUserAllowedPages(), true);
}

/** Any-of helper (an endpoint shared by two pages, e.g. api/inventory.php). */
function userCanAccessAny(array $pages): bool
{
    foreach ($pages as $page) {
        if (userCanAccess((string)$page)) {
            return true;
        }
    }

    return false;
}

const PAGE_ACCESS_DENIED_MESSAGE = "You don't have access to this page.";

/**
 * Server-side route guard.
 *
 *   requirePageAccess('sales');                     -> page: redirect to the Menu with a message
 *   requirePageAccess('sales', true);               -> endpoint: HTTP 403 JSON
 *   requirePageAccess(['inventory_dashboard', 'pharmacy']);  -> any of these
 *
 * Hiding a sidebar link is never enough on its own; every page and endpoint calls this.
 */
function requirePageAccess($pages, bool $json = false): void
{
    requireLogin();
    if (($_SESSION['role'] ?? 'staff') === 'admin') {
        return;
    }

    foreach ((array)$pages as $page) {
        if (userCanAccess((string)$page)) {
            return;
        }
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'success' => false, 'error' => PAGE_ACCESS_DENIED_MESSAGE, 'message' => PAGE_ACCESS_DENIED_MESSAGE],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        exit;
    }

    $_SESSION['alert'] = PAGE_ACCESS_DENIED_MESSAGE;
    header('Location: ' . currentUrlPrefix() . 'menu.php');
    exit;
}

/**
 * The sidebar, in registry order, for whoever is signed in. Pages with their own dedicated
 * control (`'sidebar' => false`, i.e. the Inventory Dashboard header button and the alert bell)
 * are still permission-checked — they just do not get a second sidebar link.
 */
function navItemsForCurrentUser(): array
{
    $items = [];
    foreach (pageDefinitions() as $key => $page) {
        if (!userCanAccess($key) || ($page['sidebar'] ?? true) === false) {
            continue;
        }
        $items[] = [
            'page' => $key,
            'label' => $page['label'],
            'icon' => $page['icon'],
            'url' => $page['url'],
            'title' => $page['title'],
        ];
    }

    return $items;
}

/**
 * Replace a staff member's extra page grants. Admin-only by policy (users.php enforces that before
 * calling this), validated against the grantable list, and audited in admin_logs.
 *
 * Returns ['success' => bool, 'message' => string, 'pages' => string[]].
 */
function setUserPages(PDO $pdo, int $userId, array $requestedPages, ?int $actorId = null, string $actorLabel = ''): array
{
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user.', 'pages' => []];
    }

    // Only known, grantable page names survive validation; duplicates are dropped.
    $clean = [];
    foreach ($requestedPages as $page) {
        $page = trim((string)$page);
        if ($page !== '' && in_array($page, grantablePages(), true) && !in_array($page, $clean, true)) {
            $clean[] = $page;
        }
    }

    $target = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ? LIMIT 1');
    $target->execute([$userId]);
    $user = $target->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return ['success' => false, 'message' => 'That user no longer exists.', 'pages' => []];
    }
    if ((string)$user['role'] === 'admin') {
        return ['success' => false, 'message' => 'Administrators already have access to every page.', 'pages' => []];
    }

    $before = storedPageGrants($pdo, $userId);

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM user_pages WHERE user_id = ?')->execute([$userId]);
        if ($clean) {
            $insert = $pdo->prepare('INSERT INTO user_pages (user_id, page, granted_by, granted_at) VALUES (?, ?, ?, NOW())');
            foreach ($clean as $page) {
                $insert->execute([$userId, $page, $actorId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Heartfelt Pharmacy] page grant update failed: ' . $e->getMessage());

        return ['success' => false, 'message' => 'Could not save the access changes. Please try again.', 'pages' => $before];
    }

    // Audit trail: who changed whose access, from what to what, and when.
    $describe = static function (array $pages): string {
        return $pages ? pageLabels($pages) : 'none';
    };
    $details = sprintf(
        '%s set page access for #%d %s: %s -> %s',
        $actorLabel !== '' ? $actorLabel : ('admin #' . (int)$actorId),
        (int)$user['id'],
        (string)$user['username'],
        $describe($before),
        $describe($clean)
    );
    try {
        $pdo->prepare('INSERT INTO admin_logs (action, details) VALUES (?, ?)')->execute(['page_access_changed', $details]);
    } catch (PDOException $e) {
        // The grant itself is already saved; a logging failure must not undo it.
        error_log('[Heartfelt Pharmacy] page access audit log failed: ' . $e->getMessage());
    }

    return ['success' => true, 'message' => 'Access updated.', 'pages' => $clean];
}
