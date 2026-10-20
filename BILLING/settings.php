<?php
// FIX (Bugs 2/3/5/6): settings.php — category delete + reassignment, Add Medicine with saved-field
// alerts (used by the Menu's cross-tab refresh), a pink type-DELETE clear-history modal, and
// staff-only action gating.
require_once 'config/db.php';
require_once 'includes/medicine_helpers.php';
require_once 'includes/permissions.php';
// TASK 2/3: Setting is admin-only and not grantable. A staff account typing the URL is redirected to
// the Menu with the shared "no access" message by the permission gate.
requirePageAccess('settings');

$activePage = 'settings';
$settings = getSettings($pdo);
$pharmacySettings = $pdo->query('SELECT * FROM pharmacy_settings ORDER BY id DESC LIMIT 1')->fetch();
// FIX (Bug 2): categories come from the shared `categories` table — the same source the Menu reads.
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$medicines = $pdo->query('SELECT * FROM medicines ORDER BY name ASC')->fetchAll();

function saveMedicineImage(array $file): string {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return '';
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return '';
    }

    $size = filesize($file['tmp_name']);
    if ($size === false || $size > 2 * 1024 * 1024) {
        return '';
    }

    $dir = __DIR__ . '/assets/img/medicines';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'png';
    $filename = 'medicine_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return '';
    }

    return 'assets/img/medicines/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // FIX (Bug 1): dedicated CREATE-CATEGORY branch. Root cause of "category never saves": the old
    // input lived OUTSIDE the assignment form, so its value was never POSTed and PHP received an
    // empty name. This branch validates (trimmed, non-empty, case-insensitive duplicate) with a
    // pink inline error and performs a real INSERT into the shared `categories` table.
    if (isset($_POST['create_category'])) {
        $newName = trim((string)($_POST['category_name'] ?? ''));
        if ($newName === '') {
            // FIX (Bug 1): pink inline error instead of silently doing nothing.
            $_SESSION['category_error'] = 'Please enter a category name.';
        } else {
            // FIX (Bug 1): case-insensitive duplicate check against the shared categories table.
            // TRIM applies to BOTH sides so stray spaces can never sneak a duplicate past the check.
            $dup = $pdo->prepare('SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
            $dup->execute([$newName]);
            if ($dup->fetch()) {
                $_SESSION['category_error'] = 'Category already exists.';
            } else {
                $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$newName]);
                $_SESSION['alert'] = 'Category "' . $newName . '" created.';
            }
        }
        header('Location: settings.php#current-categories-card');
        exit;
    }

    // FIX (Bug 6): staff-only gate + flash alert + the Menu listens for this key to auto-refresh.
    if (isset($_POST['clear_order_history'])) {
        if (($_SESSION['role'] ?? 'staff') !== 'admin') {
            $_SESSION['alert'] = 'Only administrators can clear order history.';
            header('Location: settings.php');
            exit;
        }
        // Destructive action: CSRF token from the form (same pattern as users.php).
        if (!hash_equals((string) ($_SESSION['history_clear_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['alert'] = 'Your session expired. Refresh the page and try again.';
            header('Location: settings.php');
            exit;
        }
        $result = clearOrderHistory($pdo);
        if ($result['success']) {
            $_SESSION['alert'] = $result['counter_reset']
                ? 'All order history was deleted. Order numbering restarted at #1.'
                : 'All order history was deleted. Order numbering was left unchanged because records remain.';
        } else {
            $_SESSION['alert'] = 'Unable to clear order history. ' . htmlspecialchars($result['message'] ?? '');
        }
        header('Location: settings.php');
        exit;
    }

    // FIX (Bug 6): per-order deletes are handled by ajax/delete_order.php so staff-facing
    // Bills/History rows work without going through this admin-only page.

    if (isset($_POST['pharmacy_action'])) {
        $threshold = max(1, (int)($_POST['alert_threshold_days'] ?? 30));
        $stockThreshold = max(1, (int)($_POST['stock_threshold'] ?? 10));
        $email = trim($_POST['notify_email'] ?? '');
        $stmt = $pdo->query('SELECT id FROM pharmacy_settings ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        if ($row) {
            $update = $pdo->prepare('UPDATE pharmacy_settings SET alert_threshold_days = ?, stock_threshold = ?, notify_email = ? WHERE id = ?');
            $update->execute([$threshold, $stockThreshold, $email, $row['id']]);
        } else {
            $insert = $pdo->prepare('INSERT INTO pharmacy_settings (alert_threshold_days, stock_threshold, notify_email) VALUES (?, ?, ?)');
            $insert->execute([$threshold, $stockThreshold, $email]);
        }
        refreshExpiryStatuses($pdo, getPharmacyThreshold($pdo));
        header('Location: settings.php');
        exit;
    }

    // FIX (Bug 3): the Rates UPDATE only runs when the Rates form was actually submitted —
    // previously ANY other POST here (Add Medicine, category delete…) silently zeroed the rates.
    if (isset($_POST['tax_rate'])) {
        $taxRate = (float)($_POST['tax_rate'] ?? 0);
        $serviceChargeRate = (float)($_POST['service_charge_rate'] ?? 0);
        $studentDiscountRate = max(0, (float)($_POST['student_discount_rate'] ?? 10));
        $seniorDiscountRate = max(0, (float)($_POST['senior_discount_rate'] ?? 20));
        $pwdDiscountRate = max(0, (float)($_POST['pwd_discount_rate'] ?? 20));
        $removeVat = isset($_POST['remove_vat_for_discounted']) ? (int)$_POST['remove_vat_for_discounted'] : 1;
        $pdo->prepare('UPDATE settings SET tax_rate = ?, service_charge_rate = ?, student_discount_rate = ?, senior_discount_rate = ?, pwd_discount_rate = ?, remove_vat_for_discounted = ? WHERE id = ?')->execute([
            $taxRate,
            $serviceChargeRate,
            $studentDiscountRate,
            $seniorDiscountRate,
            $pwdDiscountRate,
            $removeVat,
            1,
        ]);
    }

    // FIX (Bug 2): deleting a category ALWAYS reassigns its medicines to Uncategorized first,
    // then removes the category row — medicines are never deleted, and no orphan values remain.
    if (isset($_POST['delete_category'])) {
        if (($_SESSION['role'] ?? 'staff') !== 'admin') {
            $_SESSION['alert'] = 'Only administrators can delete categories.';
            header('Location: settings.php');
            exit;
        }
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        $category = $stmt->fetch();

        if ($category) {
            $pdo->beginTransaction();
            try {
                $reassign = $pdo->prepare("UPDATE medicines SET category = 'Uncategorized', updated_at = NOW() WHERE category = ?");
                $reassign->execute([$category['name']]);
                $movedCount = $reassign->rowCount();

                $delete = $pdo->prepare('DELETE FROM categories WHERE id = ?');
                $delete->execute([$categoryId]);

                $pdo->prepare('INSERT INTO admin_logs (action, details) VALUES (?, ?)')->execute([
                    'category_deleted',
                    'Category "' . $category['name'] . '" deleted; ' . $movedCount . ' medicine(s) moved to Uncategorized.'
                ]);

                $pdo->commit();
                $_SESSION['alert'] = 'Category "' . $category['name'] . '" deleted. ' . $movedCount . ' medicine(s) moved to Uncategorized.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['alert'] = 'Unable to delete category: ' . htmlspecialchars($e->getMessage());
            }
        } else {
            $_SESSION['alert'] = 'That category no longer exists.';
        }
        header('Location: settings.php');
        exit;
    }

    if (isset($_POST['category_assignment'])) {
        $selected = $_POST['selected_medicines'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }
        $targetCategory = trim((string)($_POST['assign_category'] ?? ''));
        $newCategory = trim((string)($_POST['new_category_name'] ?? ''));
        $applyCategory = $targetCategory !== '' ? $targetCategory : $newCategory;
        // FIX (Task 3): AJAX apply (from the Assign-to-category button) answers with JSON so the list
        // updates in place — labels, cleared checkboxes and a pink toast — instead of a full reload.
        $isAjax = isset($_POST['assignment_ajax']);

        // FIX (Task 3): "Uncategorized" is the un-assign target and lives only on medicines — never
        // insert it as a categories row (it would duplicate the Menu's chip source of truth).
        $isUncategorized = strcasecmp($applyCategory, 'Uncategorized') === 0;
        if ($applyCategory !== '' && !$isUncategorized) {
            $check = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
            $check->execute([$applyCategory]);
            if (!$check->fetch()) {
                $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$applyCategory]);
            }
        }

        // FIX (Task 3): count what ACTUALLY moved so the toast/receipt message is accurate.
        $moved = 0;
        if ($applyCategory !== '' && !empty($selected)) {
            $updateMedicine = $pdo->prepare('UPDATE medicines SET category = ?, updated_at = NOW() WHERE id = ?');
            foreach ($selected as $id) {
                $updateMedicine->execute([$applyCategory, (int)$id]);
                $moved += $updateMedicine->rowCount();
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            if ($applyCategory === '') {
                echo json_encode(['success' => false, 'message' => 'Pick a category before applying.']);
            } elseif ($moved === 0) {
                echo json_encode(['success' => false, 'message' => 'Tick at least one medicine, then Apply.']);
            } else {
                // Fresh medicines list so the shared localStorage snapshot stays server-accurate.
                $freshMedicines = $pdo->query('SELECT * FROM medicines ORDER BY name ASC')->fetchAll();
                echo json_encode([
                    'success' => true,
                    'moved' => $moved,
                    'category' => $applyCategory,
                    'medicines' => $freshMedicines,
                ]);
            }
            exit;
        }

        // FIX (Bug 2): a no-op apply explains itself instead of flashing a misleading success.
        if ($applyCategory === '') {
            $_SESSION['alert'] = 'Pick a category or type a new one before applying.';
        } elseif ($moved === 0) {
            $_SESSION['alert'] = 'Tick the medicines to assign, then Apply again.';
        } else {
            $_SESSION['alert'] = $moved . ($moved === 1 ? ' medicine moved' : ' medicines moved') . ' to "' . $applyCategory . '".';
        }
        header('Location: settings.php');
        exit;
    }

    if (isset($_POST['add_medicine'])) {
        $name = trim($_POST['medicine_name'] ?? '');
        $batch = trim($_POST['medicine_batch'] ?? '');
        $supplier = trim($_POST['medicine_supplier'] ?? '');
        // FIX (Bug 3): qty is clamped to >= 0 (a new item is never silently dropped for qty 0).
        $quantity = max(0, (int)($_POST['medicine_quantity'] ?? 0));
        $expirationDate = trim($_POST['medicine_expiry'] ?? '');
        // FIX (Bug 3): price is saved even when 0 — required-field validation happens in the form.
        $price = max(0, (float)($_POST['medicine_price'] ?? 0));
        // FIX (Bug 3): an empty category ALWAYS defaults to "Uncategorized" so the item shows on the Menu.
        $category = trim($_POST['medicine_category'] ?? '');
        if ($category === '') {
            $category = 'Uncategorized';
        }
        $image = saveMedicineImage($_FILES['medicine_image'] ?? []);

        if ($name !== '' && $supplier !== '' && $expirationDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDate)) {
            try {
                $batch = assignMedicineBatchNumber($pdo, $name, $batch);
            } catch (InvalidArgumentException $e) {
                $_SESSION['alert'] = $e->getMessage();
                header('Location: settings.php#add-medicine-card');
                exit;
            }

            $pdo->prepare('INSERT INTO medicines (name, batch_number, quantity, supplier, expiration_date, price, category, image, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute([
                $name,
                $batch,
                $quantity,
                $supplier,
                $expirationDate,
                $price,
                $category,
                $image !== '' ? $image : null,
            ]);

            // FIX (Bug 2): keep the shared categories table in sync when Add Medicine uses a new name.
            $check = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
            $check->execute([$category]);
            if (!$check->fetch()) {
                $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$category]);
            }

            $_SESSION['alert'] = 'Medicine "' . $name . '" added to inventory.';
        } else {
            $_SESSION['alert'] = 'Medicine was NOT saved — please complete name, supplier and a valid YYYY-MM-DD expiration date.';
        }

        header('Location: settings.php');
        exit;
    }

    $categoryName = trim($_POST['category_name'] ?? '');
    if ($categoryName !== '') {
        $check = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $check->execute([$categoryName]);
        if (!$check->fetch()) {
            $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$categoryName]);
        }
    }

    header('Location: settings.php');
    exit;
}

include 'includes/header.php';
?>
<div class="page-title settings-page-header">
    <h1>Settings</h1>
</div>
<?php if (isset($_SESSION['alert'])) : ?>
    <div class="alert"><?= htmlspecialchars($_SESSION['alert']) ?></div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>
<div class="form-grid settings-grid">
    <div class="form-card">
        <h3>Rates</h3>
        <form method="post">
            <label>Tax Rate (%)</label>
            <input type="number" step="0.01" name="tax_rate" value="<?= htmlspecialchars((string)($settings['tax_rate'] ?? 12)) ?>">
            <label>Service Charge (%)</label>
            <input type="number" step="0.01" name="service_charge_rate" value="<?= htmlspecialchars((string)($settings['service_charge_rate'] ?? 5)) ?>">
            <label>Student Discount (%)</label>
            <input type="number" step="0.01" name="student_discount_rate" value="<?= htmlspecialchars((string)($settings['student_discount_rate'] ?? 10)) ?>">
            <label>Senior Discount (%)</label>
            <input type="number" step="0.01" name="senior_discount_rate" value="<?= htmlspecialchars((string)($settings['senior_discount_rate'] ?? 20)) ?>">
            <label>PWD Discount (%)</label>
            <input type="number" step="0.01" name="pwd_discount_rate" value="<?= htmlspecialchars((string)($settings['pwd_discount_rate'] ?? 20)) ?>">
            <label>Remove VAT for Senior/PWD</label>
            <select name="remove_vat_for_discounted">
                <option value="1" <?= (($settings['remove_vat_for_discounted'] ?? 1) == 1) ? 'selected' : '' ?>>On</option>
                <option value="0" <?= (($settings['remove_vat_for_discounted'] ?? 1) == 0) ? 'selected' : '' ?>>Off</option>
            </select>
            <button class="btn btn-primary" type="submit">Save Settings</button>
        </form>
    </div>
    <!-- FIX (Task 3): "Create new category" is now its own card in the SAME grid row as Rates —
         Rates on the left, Create Category directly above-right of it; the Add Medicine and
         Current Categories cards stay in the row below, unchanged. -->
    <div class="form-card" id="create-category-card">
        <h3>Create New Category</h3>
        <!-- FIX (Bug 1): the create-category form keeps its OWN form so the value is actually
             POSTed (the original root cause of categories never saving). -->
        <form method="post" id="create-category-form" class="create-category-block">
            <input type="hidden" name="create_category" value="1">
            <label for="new-category-name">Category name</label>
            <div class="assignment-actions-row">
                <input type="text" id="new-category-name" name="category_name" placeholder="e.g. Pain Relief" class="soft-search" autocomplete="off">
                <button type="submit" class="btn btn-primary" id="apply-new-category-button">+ Add</button>
            </div>
            <?php if (isset($_SESSION['category_error'])) : ?>
                <div class="inline-warning" role="alert"><?= htmlspecialchars($_SESSION['category_error']) ?></div>
                <?php unset($_SESSION['category_error']); ?>
            <?php endif; ?>
        </form>
    </div>
</div>
<div class="form-grid settings-grid">
    <div class="form-card" id="add-medicine-card">
        <h3>Add Medicine to Inventory</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="add_medicine" value="1">
            <label>Medicine Name</label>
            <input type="text" name="medicine_name" placeholder="Paracetamol" required>
            <label>Batch</label>
            <input type="text" name="medicine_batch" placeholder="Auto-assigned if blank">
            <label>Supplier</label>
            <input type="text" name="medicine_supplier" placeholder="Medi Supply" required>
            <label>Stock Quantity</label>
            <input type="number" name="medicine_quantity" min="0" value="10" required>
            <label>Expiration Date</label>
            <input type="date" name="medicine_expiry" required>
            <label>Price</label>
            <input type="number" step="0.01" name="medicine_price" value="10.00" required>
            <label>Category</label>
            <select name="medicine_category">
                <!-- FIX (Bug 3): the safe default is the FIRST option so no medicine is ever left without a valid category. -->
                <option value="Uncategorized" selected>Uncategorized</option>
                <?php foreach ($categories as $category) : ?>
                    <option value="<?= htmlspecialchars($category['name']) ?>"><?= htmlspecialchars($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Image</label>
            <div class="upload-box">
                <input id="setting-medicine-image" type="file" name="medicine_image" accept="image/jpeg,image/png,image/webp" class="visually-hidden">
                <label for="setting-medicine-image" class="btn btn-secondary upload-trigger"><i class="fa-solid fa-upload"></i> Upload Image</label>
                <div id="setting-medicine-preview" class="upload-preview-box"><span class="upload-empty-state">No image selected</span></div>
                <div id="setting-medicine-error" class="upload-error" hidden></div>
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:14px;">Add Medicine</button>
        </form>
        <!-- FIX (Bug 5): pink "← Back to Inventory" button right under the Add Medicine form card. -->
        <a href="pharmacy_inventory.php" class="btn btn-primary back-to-inventory-btn" style="margin-top:12px;">
            <i class="fa-solid fa-arrow-left"></i> ← Back to Inventory
        </a>
    </div>
    <div class="form-card" id="current-categories-card">
        <h3>Current Categories &amp; Products</h3>
        <!-- FIX (Task 3): the create-category input moved to its own card above (next to Rates),
             so this card now holds only search, Select all, the assign dropdown, Apply and the list. -->
        <div class="assignment-controls">
            <label class="checkbox-row"><input type="checkbox" id="select-all-medicines"> <span>Select all</span></label>
            <input type="search" id="medicine-assign-search" placeholder="Search medicines..." class="soft-search">
            <div class="assignment-actions-row">
                <!-- FIX (Task 3): ROOT CAUSE of assignments never saving — this select had NO name
                     attribute and sat OUTSIDE #category-assignment-form, so $_POST['assign_category']
                     never reached the server and PHP always saw an empty category. It is now named
                     AND associated with the form via the form= attribute, and gains an explicit
                     "Uncategorized" option so a medicine can also be un-assigned. -->
                <select id="category-assign-select" name="assign_category" form="category-assignment-form">
                    <option value="" disabled selected>Assign to category</option>
                    <option value="Uncategorized">Uncategorized</option>
                    <?php foreach ($categories as $category) : ?>
                        <option value="<?= htmlspecialchars($category['name']) ?>"><?= htmlspecialchars($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- FIX (Task 3): Apply is now a real submit button bound to the assignment form
                     (works even if JavaScript fails). -->
                <button type="submit" class="btn btn-primary" id="apply-category-button" form="category-assignment-form">Apply</button>
            </div>
            <div class="selected-count" id="selected-medicine-count">0 selected</div>
        </div>
        <form method="post" id="category-assignment-form">
            <input type="hidden" name="category_assignment" value="1">
            <!-- FIX (Task 3): assignment_ajax is added by the fetch call only — a hidden field here
                 would also fire on the NATIVE fallback POST and dump raw JSON into the browser. -->
            <div class="medicine-assignment-list" id="medicine-assignment-list">
                <?php foreach ($medicines as $medicine) : ?>
                    <!-- FIX (Task 3): data-name/data-batch/data-category on the ROW itself — the old
                         search filter read dataset.name from the row (undefined) and hid every item. -->
                    <label class="assignment-item" data-name="<?= htmlspecialchars($medicine['name']) ?>" data-batch="<?= htmlspecialchars($medicine['batch_number']) ?>" data-category="<?= htmlspecialchars($medicine['category'] ?: 'Uncategorized') ?>">
                        <input type="checkbox" name="selected_medicines[]" value="<?= (int)$medicine['id'] ?>" data-name="<?= htmlspecialchars($medicine['name']) ?>">
                        <?php if (!empty($medicine['image'])) : ?>
                            <img src="<?= htmlspecialchars($medicine['image']) ?>" alt="<?= htmlspecialchars($medicine['name']) ?>" class="inventory-thumb">
                        <?php else : ?>
                            <span class="image-placeholder-mini"><i class="fa-solid fa-capsules"></i></span>
                        <?php endif; ?>
                        <span class="assignment-info">
                            <strong><?= htmlspecialchars($medicine['name']) ?></strong>
                            <small><?= htmlspecialchars($medicine['batch_number']) ?> · <?= htmlspecialchars($medicine['category'] ?: 'Uncategorized') ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </form>
        <!-- FIX (Bug 2): delete (trash) button per category group; deleting reassigns medicines to Uncategorized. -->
        <div class="category-groups" id="category-groups">
            <?php foreach ($categories as $category) : ?>
                <div class="category-group-row" data-category-id="<?= (int)$category['id'] ?>" data-category-name="<?= htmlspecialchars($category['name']) ?>">
                    <span class="category-group-name"><i class="fa-solid fa-tag"></i> <?= htmlspecialchars($category['name']) ?></span>
                    <button type="button" class="category-delete-btn" data-category-id="<?= (int)$category['id'] ?>" data-category-name="<?= htmlspecialchars($category['name']) ?>" aria-label="Delete category <?= htmlspecialchars($category['name']) ?>">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="post" id="delete-category-form" class="hidden">
            <input type="hidden" name="delete_category" value="1">
            <input type="hidden" name="category_id" id="delete-category-id" value="">
        </form>
    </div>
</div>

<div class="form-card" style="margin-top:20px;">
    <h3>Pharmacy Expiry Alert Settings</h3>
    <form method="post" class="filter-row" style="margin-top:12px;">
        <input type="hidden" name="pharmacy_action" value="1">
        <label style="margin:0;white-space:nowrap;">Threshold (days)</label>
        <input type="number" name="alert_threshold_days" min="1" value="<?= (int)($pharmacySettings['alert_threshold_days'] ?? 30) ?>" style="max-width:140px;">
        <label style="margin:0;white-space:nowrap;">Low stock threshold</label>
        <input type="number" name="stock_threshold" min="1" value="<?= (int)($pharmacySettings['stock_threshold'] ?? 10) ?>" style="max-width:140px;">
        <label style="margin:0;white-space:nowrap;">Notify Email</label>
        <input type="email" name="notify_email" value="<?= htmlspecialchars((string)($pharmacySettings['notify_email'] ?? '')) ?>" placeholder="admin@example.com" style="max-width:240px;">
        <button class="btn btn-primary" type="submit">Save Pharmacy Settings</button>
    </form>
</div>

<!-- FIX (Bug 6): Clear All History is admin-only and guarded by a pink modal requiring "DELETE". -->
<div class="form-card" style="margin-top:20px;">
    <h3>Order History</h3>
    <p class="muted" style="margin:0 0 12px;">⚠️ <strong>Warning:</strong> clearing history permanently deletes <strong>all</strong> order records and restarts order numbering at #1. This cannot be undone. Medicines and stock are not touched.</p>
    <?php if (($_SESSION['role'] ?? 'staff') === 'admin') : ?>
        <?php if (empty($_SESSION['history_clear_csrf'])) {
            $_SESSION['history_clear_csrf'] = bin2hex(random_bytes(32));
        } ?>
        <form method="post" id="clear-history-form">
            <input type="hidden" name="clear_order_history" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['history_clear_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" id="clear-history-btn" class="btn btn-danger">Delete All History</button>
        </form>
    <?php else : ?>
        <p class="muted">Only administrators can clear order history.</p>
    <?php endif; ?>
</div>

<script>
    // FIX (Bug 6): pink confirm modal requires typing DELETE; form submits only after confirmation.
    (function bindClearHistory() {
        const button = document.getElementById('clear-history-btn');
        const form = document.getElementById('clear-history-form');
        if (!button || !form) {
            return;
        }
        button.addEventListener('click', () => {
            window.confirmAction({
                title: 'Delete all history?',
                message: 'This will permanently delete all history and restart order numbers at #1. Continue?',
                confirmLabel: 'Delete forever',
                requireText: 'DELETE',
                onConfirm: (confirmed) => {
                    if (confirmed) {
                        form.submit();
                    }
                }
            });
        });
    })();

    // FIX (Bug 2): pink confirm modal for category delete — mirrors the exact wording requested.
    (function bindCategoryDelete() {
        const form = document.getElementById('delete-category-form');
        const idField = document.getElementById('delete-category-id');
        document.querySelectorAll('.category-delete-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const name = button.dataset.categoryName || 'this category';
                const id = button.dataset.categoryId;
                if (!form || !idField) {
                    return;
                }
                window.confirmAction({
                    title: 'Delete category?',
                    message: `Delete category '${name}'? Its medicines will move to Uncategorized.`,
                    confirmLabel: 'Delete category',
                    onConfirm: (confirmed) => {
                        if (confirmed) {
                            idField.value = id;
                            form.submit();
                        }
                    }
                });
            });
        });
    })();

    // FIX (Bug 2/3): assignment controls (kept from the previous working behaviour).
    (function bindAssignmentControls() {
        const selectAll = document.getElementById('select-all-medicines');
        const search = document.getElementById('medicine-assign-search');
        const applyButton = document.getElementById('apply-category-button');
        const newCategoryButton = document.getElementById('apply-new-category-button');
        const countLabel = document.getElementById('selected-medicine-count');
        const form = document.getElementById('category-assignment-form');
        const assignSelect = document.getElementById('category-assign-select');
        const newCategoryName = document.getElementById('new-category-name');

        const items = Array.from(document.querySelectorAll('#medicine-assignment-list .assignment-item'));
        if (countLabel) {
            const updateCount = () => {
                const count = items.filter((item) => item.querySelector('input[type="checkbox"]').checked).length;
                countLabel.textContent = `${count} selected`;
            };
            items.forEach((item) => item.querySelector('input[type="checkbox"]').addEventListener('change', updateCount));
            updateCount();
        }

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                items.forEach((item) => {
                    if (item.style.display !== 'none') {
                        item.querySelector('input[type="checkbox"]').checked = selectAll.checked;
                    }
                });
                if (countLabel) {
                    const count = items.filter((item) => item.querySelector('input[type="checkbox"]').checked).length;
                    countLabel.textContent = `${count} selected`;
                }
            });
        }

        if (search) {
            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                items.forEach((item) => {
                    const name = (item.dataset.name || '').toLowerCase();
                    item.style.display = !query || name.includes(query) ? '' : 'none';
                });
            });
        }

        // FIX (Task 3): Apply now sends the assignment over AJAX and updates the list in place —
        // moved medicines instantly show their new category next to the name, checkboxes reset,
        // and a pink toast confirms ("2 medicines moved to Pain Relief"). Falls back to the
        // native POST if fetch/JSON fails, so the assignment still saves.
        if (form) {
            form.addEventListener('submit', (event) => {
                if (!window.fetch) {
                    return; // very old browser — let the native POST go through
                }
                event.preventDefault();

                const category = assignSelect ? String(assignSelect.value || '').trim() : '';
                const checked = items
                    .filter((item) => {
                        const checkbox = item.querySelector('input[type="checkbox"]');
                        return checkbox && checkbox.checked;
                    })
                    .map((item) => String(parseInt(item.querySelector('input[type="checkbox"]').value, 10)));

                if (!category) {
                    if (window.showToast) { window.showToast('Pick a category first.'); }
                    if (assignSelect) { assignSelect.focus(); }
                    return;
                }
                if (!checked.length) {
                    if (window.showToast) { window.showToast('Tick at least one medicine, then Apply.'); }
                    return;
                }

                const applyOriginalLabel = applyButton ? applyButton.textContent : 'Apply';
                if (applyButton) {
                    applyButton.disabled = true;
                    applyButton.textContent = 'Applying…';
                }

                const payload = new FormData(form);
                payload.set('assign_category', category);
                // FIX (Task 3): JSON mode is opt-in per request (never on the native fallback POST).
                payload.set('assignment_ajax', '1');

                fetch('settings.php', { method: 'POST', body: payload, headers: { 'Accept': 'application/json' } })
                    .then((response) => response.json().catch(() => null))
                    .then((json) => {
                        if (!json || json.success !== true) {
                            throw new Error((json && json.message) || 'Assignment failed.');
                        }

                        // 1) moved rows instantly show their new category next to the name
                        //    (e.g. "Biogesic — batch · Pain Relief").
                        items.forEach((item) => {
                            const checkbox = item.querySelector('input[type="checkbox"]');
                            if (!checkbox || !checked.includes(String(parseInt(checkbox.value, 10)))) {
                                return;
                            }
                            item.dataset.category = category;
                            const small = item.querySelector('.assignment-info small');
                            if (small) {
                                small.textContent = `${item.dataset.batch || ''} · ${category}`;
                            }
                            // 2) checkboxes reset after a successful apply
                            checkbox.checked = false;
                        });

                        // 3) pink success toast with the exact moved count
                        if (window.showToast) {
                            window.showToast(`${json.moved} ${json.moved === 1 ? 'medicine' : 'medicines'} moved to ${json.category}.`);
                        }

                        // 4) keep the shared data sources in sync so the Menu's category chips and
                        //    filtering are correct on next load (and other tabs refresh instantly).
                        if (Array.isArray(json.medicines)) {
                            window.__initialMedicines = json.medicines;
                            try {
                                window.localStorage.setItem('medicines', JSON.stringify(json.medicines));
                                window.dispatchEvent(new CustomEvent('pinkStorageChanged', { detail: { key: 'medicines' } }));
                            } catch (error) {
                                // Storage unavailable — the next page load still picks up server data.
                            }
                        }

                        if (countLabel) {
                            countLabel.textContent = '0 selected';
                        }
                        if (selectAll) {
                            selectAll.checked = false;
                        }
                    })
                    .catch(() => {
                        // FIX (Task 3): graceful degradation — the native POST still does the job.
                        form.submit();
                    })
                    .finally(() => {
                        if (applyButton) {
                            applyButton.disabled = false;
                            applyButton.textContent = applyOriginalLabel;
                        }
                    });
            });
        }

        // FIX (Bug 1): the old "create from the assignment form" path was the broken one — the
        // create input now lives in its OWN form (#create-category-form) with a native submit,
        // and this client-side pass adds the two required inline validations (trimmed empty name
        // and case-insensitive duplicate) as instant pink errors before the POST.
        (function bindCreateCategory() {
            const createForm = document.getElementById('create-category-form');
            const nameInput = document.getElementById('new-category-name');
            const existing = <?= json_encode(array_map(static fn ($row) => mb_strtolower(trim((string)$row['name'])), $categories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            if (!createForm || !nameInput) {
                return;
            }
            const showError = (message) => {
                let box = createForm.querySelector('.inline-warning');
                if (!box) {
                    box = document.createElement('div');
                    box.className = 'inline-warning';
                    box.setAttribute('role', 'alert');
                    createForm.appendChild(box);
                    // FIX (Bug 1): keep the server-rendered error div (if any) in front of the client one.
                    createForm.querySelectorAll('.inline-warning').forEach((el, i) => el.style.order = String(i));
                }
                box.textContent = message;
                box.hidden = false;
            };
            createForm.addEventListener('submit', (event) => {
                const value = nameInput.value.trim();
                if (!value) {
                    event.preventDefault();
                    showError('Please enter a category name.');
                    nameInput.focus();
                    return;
                }
                if (existing.includes(value.toLowerCase())) {
                    event.preventDefault();
                    showError('Category already exists.');
                    nameInput.focus();
                    return;
                }
                // FIX (Bug 1): valid — clear any stale inline error and let the POST go through.
                const box = createForm.querySelector('.inline-warning');
                if (box) {
                    box.hidden = true;
                }
            });
        })();
    })();

    function attachMedicineUploadPreview(inputId, previewId, errorId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        const errorBox = document.getElementById(errorId);
        if (!input || !preview) {
            return;
        }

        input.addEventListener('change', (event) => {
            const file = event.target.files && event.target.files[0];
            if (!file) {
                preview.innerHTML = '<span class="upload-empty-state">No image selected</span>';
                return;
            }

            const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
            const isValidType = validTypes.includes(file.type);
            const isValidSize = file.size <= 2 * 1024 * 1024;
            if (!isValidType || !isValidSize) {
                if (errorBox) {
                    errorBox.hidden = false;
                    errorBox.textContent = 'Please choose a JPG, PNG, or WEBP image up to 2MB.';
                }
                preview.innerHTML = '<span class="upload-empty-state">No image selected</span>';
                input.value = '';
                return;
            }

            if (errorBox) {
                errorBox.hidden = true;
                errorBox.textContent = '';
            }

            const reader = new FileReader();
            reader.onload = () => {
                preview.innerHTML = `
                    <img src="${reader.result}" alt="Medicine preview" class="upload-preview-image">
                    <button type="button" class="remove-upload-btn" data-clear-input="${inputId}" aria-label="Remove image">×</button>
                `;
                preview.querySelector('.remove-upload-btn')?.addEventListener('click', () => {
                    input.value = '';
                    preview.innerHTML = '<span class="upload-empty-state">No image selected</span>';
                });
            };
            reader.readAsDataURL(file);
        });
    }

    attachMedicineUploadPreview('setting-medicine-image', 'setting-medicine-preview', 'setting-medicine-error');

    // FIX (Bugs 2/3): server truth is pushed to the shared localStorage snapshot so the Menu,
    // Inventory and public catalog stay in sync without a manual cache clear.
    window.__initialMedicines = <?= json_encode($medicines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.__initialCategories = <?= json_encode(array_map(static fn ($row) => ['id' => strtolower(str_replace(' ', '-', trim((string)$row['name']))), 'name' => trim((string)$row['name'])], $categories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.__initialSettings = <?= json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.__userRole = <?= json_encode(($_SESSION['role'] ?? 'staff'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // FIX (Bug 3): after an Add Medicine/assign round-trip, tell other open tabs to refresh their
    // Menu immediately (storage event) instead of showing stale stock.
    (function notifySiblingTabs() {
        try {
            window.localStorage.setItem('medicines', JSON.stringify(window.__initialMedicines));
            window.localStorage.setItem('categories', JSON.stringify(window.__initialCategories));
        } catch (error) {
            // Storage may be unavailable — page reload still picks up server data.
        }
    })();
</script>
<?php include 'includes/footer.php'; ?>
