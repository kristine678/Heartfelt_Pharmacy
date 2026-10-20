<?php
require_once 'config/db.php';
// TASK 2/3: ONE permission source for this page's Server-side guard, its header button and the
// sidebar the shared header renders.
require_once 'includes/permissions.php';
requirePageAccess('menu');

$activePage = 'menu';
// TASK 2/3: the Menu header button reads the SAME permission source as the sidebar and the route
// guards (includes/permissions.php, loaded by the header below).
$canSeeInventoryDashboard = userCanAccess('inventory_dashboard');
$settings = getSettings($pdo);
$medicines = getSellableMedicineGroups($pdo);
// FIX (Bug 1): the Menu now seeds its category list from the SAME shared `categories` table
// Settings writes to (previously it derived the list from DISTINCT medicines.category, which
// both overwrote the shared source and dropped any category with 0 medicines — so a newly
// created, not-yet-assigned category never got a chip). Uncategorized appears only when it
// actually has medicines, matching the app-wide chip rule.
$allCategories = $pdo->query('SELECT name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
$categoryList = array_map(
    static fn (string $name): array => ['id' => strtolower(str_replace(' ', '-', trim($name))), 'name' => trim($name)],
    array_values(array_unique(array_filter(array_map('trim', $allCategories))))
);
if (empty($categoryList)) {
    $categoryList = [['id' => 'uncategorized', 'name' => 'Uncategorized']];
}

include 'includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Menu</h1>
        <p class="page-subtitle">Prepare medicine orders for your customers.</p>
    </div>
    <div class="page-title-actions">
        <button type="button" id="createNewOrderBtn" class="btn btn-primary">Create New Order</button>
        <?php if ($canSeeInventoryDashboard) : ?>
        <!-- FIX (Task3): jump from the POS to the stock dashboard (same pink pill style).
             TASK 2/3: shown only when the signed-in user holds the inventory_dashboard page, so
             granting it to a staff member reveals the button and revoking it removes it again. -->
        <a href="inventory-dashboard.php" class="btn btn-primary">
            <i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Inventory Dashboard
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_SESSION['alert'])) : ?>
    <!-- TASK 2: a blocked page sends the user here with an explanation (a staff account opening a
         page it has not been granted, by typing the URL). -->
    <div class="alert" role="status"><?= htmlspecialchars((string)$_SESSION['alert'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>

<div class="top-bar">
    <input id="searchInput" type="search" class="soft-search" placeholder="Search medicines..." aria-label="Search medicines">
    <div class="chip-row" aria-label="Medicine categories"></div>
</div>

<div class="dashboard-grid">
    <section class="center-panel">
        <h2>Available Medicines</h2>
        <div id="menuGrid" class="menu-grid"></div>
    </section>

    <aside class="order-panel">
        <div class="order-panel-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <h2>Order Details</h2>
            <span id="currentOrderLabel" class="pill">#1</span>
        </div>

        <div id="orderItemsList" class="order-items-list"></div>

        <!-- FIX (Task 3): optional customer email, saved with the order at Charge so the receipt's
             "Email Receipt" button can send to it. Placed above the Discount section as requested. -->
        <div class="email-block" style="margin-top:18px;">
            <label for="customerEmailInput" class="amount-received-label">Email (optional)</label>
            <input id="customerEmailInput" type="email" class="soft-search" placeholder="customer@email.com" autocomplete="email" inputmode="email" />
            <div id="emailWarning" class="inline-warning hidden">Enter a valid email address</div>
        </div>

        <div class="discount-block" style="margin-top:18px;">
            <div class="discount-label" style="margin-bottom:8px; font-weight:700; color: var(--pink-700);">Discount</div>
            <div id="discountRow" class="chip-row"></div>
            <div id="discountIdWrap" class="discount-id-wrap hidden" style="margin-top:12px;">
                <label for="discountIdInput" style="display:block; margin-bottom:6px; color: var(--pink-700); font-weight:600;">ID Number</label>
                <input id="discountIdInput" type="text" class="soft-search" placeholder="Enter ID number" />
            </div>
        </div>

        <div class="summary-box" style="margin-top:18px; border-top:1px solid var(--pink-200); padding-top:14px; display:grid; gap:10px;">
            <div class="summary-row" style="display:flex; justify-content:space-between; align-items:center;"><span>Subtotal</span><strong id="subtotalValue">₱0.00</strong></div>
            <div class="summary-row" style="display:flex; justify-content:space-between; align-items:center;"><span>Discount</span><strong id="discountValue">- ₱0.00</strong></div>
            <div class="summary-row" style="display:flex; justify-content:space-between; align-items:center;"><span>Service Charge</span><strong id="serviceValue">₱0.00</strong></div>
            <div class="summary-row" style="display:flex; justify-content:space-between; align-items:center;"><span>Tax</span><strong id="taxValue">₱0.00</strong></div>
            <div class="summary-row total-row" style="display:flex; justify-content:space-between; align-items:center; font-size:1.2rem; color: var(--pink-700);"><span>Total</span><strong id="totalValue">₱0.00</strong></div>

            <div class="amount-received-block">
                <label for="amountReceivedInput" class="amount-received-label">Amount Received</label>
                <div class="amount-input-wrap">
                    <span class="currency-prefix">₱</span>
                    <input id="amountReceivedInput" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" aria-label="Amount received" />
                </div>
                <div class="quick-amount-row">
                    <button type="button" class="quick-amount-btn" data-amount="exact">Exact total</button>
                    <button type="button" class="quick-amount-btn" data-amount="100">₱100</button>
                    <button type="button" class="quick-amount-btn" data-amount="200">₱200</button>
                    <button type="button" class="quick-amount-btn" data-amount="500">₱500</button>
                    <button type="button" class="quick-amount-btn" data-amount="1000">₱1000</button>
                </div>
                <div class="change-row">
                    <span>Change</span>
                    <strong id="changeValue">₱0.00</strong>
                </div>
                <div id="amountWarning" class="inline-warning hidden">Insufficient amount</div>
            </div>
        </div>

        <!-- FIX (Bug 1): pink inline message target so a failed validation shows exactly which check failed -->
        <div id="chargeMessage" class="inline-warning hidden" role="alert" aria-live="polite" style="margin-top:12px;"></div>
        <button type="button" id="chargeBtn" class="btn btn-primary" style="width:100%; margin-top:18px;" disabled>
            <i class="fa-solid fa-wallet"></i> <span>Charge</span>
        </button>
    </aside>
</div>

<script>
    window.__initialMedicines = <?= json_encode($medicines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.__initialCategories = <?= json_encode($categoryList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.__initialSettings = <?= json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // FIX (Bug 6): server-rendered role flag so app.js can gate staff/admin-only actions.
    window.__userRole = <?= json_encode(($_SESSION['role'] ?? 'staff'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // TASK 2/3: the effective page list, straight from the server-side permission source.
    window.__userPages = <?= json_encode(array_values(currentUserAllowedPages()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // FIX (Bug 1/4): marks this page as the POS so app.js binds the charge + order-panel logic.
    window.isPosPage = true;
</script>
<?php include 'includes/footer.php'; ?>
