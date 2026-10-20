<?php
require_once 'config/db.php';
require_once 'includes/medicine_helpers.php';
require_once 'includes/permissions.php';
// TASK 3: the Pharmacy pages are a GRANTABLE permission now (admin always has it; a staff member
// only after the admin ticks Pharmacy in Users -> Manage access), so the gate is the shared page
// check instead of a hard admin-only block.
requirePageAccess('pharmacy');

$activePage = 'pharmacy';
// TASK 1: the Category dropdown is fed by the SAME shared `categories` table the Menu and Settings
// use, plus the built-in Uncategorized fallback.
$categoryOptions = array_values(array_unique(array_filter(array_map('trim', $pdo->query('SELECT name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN)))));
$builtInCategories = ['Pain Relief', 'Vitamins', 'Antibiotics', 'Cough & Cold', 'Uncategorized'];
$message = '';
$medicine = [
    'id' => 0,
    'name' => '',
    'batch_number' => '',
    'quantity' => 0,
    'supplier' => '',
    'expiration_date' => '',
    'price' => 0,
    'category' => 'Uncategorized',
    'image' => '',
];

function saveMedicineImage(array $file): string {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return '';
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        return '';
    }

    $size = filesize($file['tmp_name']);
    if ($size === false || $size > 2 * 1024 * 1024) {
        return '';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'png';
    $dir = __DIR__ . '/assets/img/medicines';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = 'medicine_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return '';
    }

    return 'assets/img/medicines/' . $filename;
}

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM medicines WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $medicine = $stmt->fetch();
    if (!$medicine) {
        $medicine = [
            'id' => 0,
            'name' => '',
            'batch_number' => '',
            'quantity' => 0,
            'supplier' => '',
            'expiration_date' => '',
            'price' => 0,
            'category' => 'Uncategorized',
            'image' => '',
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $batchNumber = trim($_POST['batch_number'] ?? '');
    $quantity = max(0, (int)($_POST['quantity'] ?? 0));
    $supplier = trim($_POST['supplier'] ?? '');
    $expirationDate = trim($_POST['expiration_date'] ?? '');
    $price = max(0, (float)($_POST['price'] ?? 0));
    // TASK 1: the dropdown posts a category name; the "+ Add new category" choice posts the
    // '__new__' sentinel together with the typed name. Blank input is always Uncategorized.
    $category = trim((string)($_POST['category'] ?? 'Uncategorized'));
    if ($category === '__new__') {
        $category = trim((string)($_POST['new_category'] ?? ''));
    }
    if ($category === '') {
        $category = 'Uncategorized';
    }
    // Canonicalise against the shared table so "vitamins" and "Vitamins" can never become two
    // categories in the analytics.
    $category = canonicalCategoryName($pdo, $category);
    $imagePath = trim((string)($_POST['existing_image'] ?? ''));

    if ($name === '' || ($batchNumber === '' && !empty($_POST['medicine_id'])) || $supplier === '' || $expirationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDate)) {
        $message = 'Please complete all fields and use a valid YYYY-MM-DD expiration date.';
    } elseif ($quantity < 0 || $price < 0) {
        $message = 'Quantity and price cannot be negative.';
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $expirationDate);
        if (!$date || $date->format('Y-m-d') !== $expirationDate) {
            $message = 'The expiration date must be a valid calendar date.';
        } else {
            if (empty($_POST['medicine_id'])) {
                try {
                    $batchNumber = assignMedicineBatchNumber($pdo, $name, $batchNumber);
                } catch (InvalidArgumentException $e) {
                    $message = $e->getMessage();
                }
            }

            if ($message === '') {
                $uploaded = saveMedicineImage($_FILES['medicine_image'] ?? []);
                if ($uploaded !== '') {
                    $imagePath = $uploaded;
                }

                if (!empty($_POST['medicine_id'])) {
                    $stmt = $pdo->prepare('UPDATE medicines SET name = ?, batch_number = ?, quantity = ?, supplier = ?, expiration_date = ?, price = ?, category = ?, image = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->execute([$name, $batchNumber, $quantity, $supplier, $expirationDate, $price, $category, $imagePath, (int)$_POST['medicine_id']]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO medicines (name, batch_number, quantity, supplier, expiration_date, price, category, image, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                    $stmt->execute([$name, $batchNumber, $quantity, $supplier, $expirationDate, $price, $category, $imagePath]);
                }

                $threshold = getPharmacyThreshold($pdo);
                refreshExpiryStatuses($pdo, $threshold);

                if (isset($_POST['sync_inventory'])) {
                    $syncMedicine = $pdo->prepare('SELECT m.*, (SELECT COALESCE(SUM(batch.quantity), 0) FROM medicines batch WHERE LOWER(TRIM(batch.name)) = LOWER(TRIM(?)) AND batch.quantity > 0 AND batch.expiration_date >= CURDATE()) AS combined_quantity FROM medicines m WHERE LOWER(TRIM(m.name)) = LOWER(TRIM(?)) ORDER BY (m.quantity > 0 AND m.expiration_date >= CURDATE()) DESC, m.expiration_date ASC, m.id ASC LIMIT 1');
                    $syncMedicine->execute([$name, $name]);
                    $latest = $syncMedicine->fetch();
                    if ($latest) {
                        $categoryId = getOrCreateCategoryId($pdo, (string)($latest['category'] ?? 'Uncategorized'));
                        $upsert = $pdo->prepare('INSERT INTO products (category_id, name, price, image, course_section, stock_quantity, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price), image = VALUES(image), stock_quantity = VALUES(stock_quantity), expiration_date = VALUES(expiration_date)');
                        $upsert->execute([$categoryId, $latest['name'], $latest['price'], $latest['image'], 'Main', (int)$latest['combined_quantity'], $latest['expiration_date']]);
                    }
                }

                header('Location: pharmacy_inventory.php');
                exit;
            }
        }
    }
}

include 'includes/header.php';
?>
<div class="page-title">
    <div>
        <h1 class="page-heading"><?= !empty($medicine['id']) ? 'Edit Medicine' : 'Add Medicine' ?></h1>
        <p class="page-subtitle">Capture the key inventory fields and validate the expiration date.</p>
    </div>
    <!-- FIX (Bug 5): clearly visible pink "← Back to Inventory" button with the shared hover/active animation. -->
    <a href="pharmacy_inventory.php" class="btn btn-primary back-to-inventory-btn">
        <i class="fa-solid fa-arrow-left"></i> ← Back to Inventory
    </a>
</div>

<?php if ($message) : ?>
    <div class="alert"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="form-card">
    <form method="post" enctype="multipart/form-data" class="medicine-upload-form">
        <input type="hidden" name="medicine_id" value="<?= (int)($medicine['id'] ?? 0) ?>">
        <input type="hidden" name="sync_inventory" value="1">
        <?php if (!empty($medicine['image'])) : ?>
            <input type="hidden" name="existing_image" value="<?= htmlspecialchars($medicine['image']) ?>">
        <?php endif; ?>
        <div class="form-grid">
            <div>
                <label>Medicine Name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($medicine['name'] ?? '') ?>" required>
            </div>
            <div>
                <label>Batch Number</label>
                <input type="text" name="batch_number" value="<?= htmlspecialchars($medicine['batch_number'] ?? '') ?>" <?= !empty($medicine['id']) ? 'required' : '' ?> placeholder="<?= empty($medicine['id']) ? 'Auto-assigned if blank' : '' ?>">
            </div>
            <div>
                <label>Supplier</label>
                <input type="text" name="supplier" value="<?= htmlspecialchars($medicine['supplier'] ?? '') ?>" required>
            </div>
            <div>
                <label>Stock Quantity</label>
                <input type="number" name="quantity" min="0" value="<?= (int)($medicine['quantity'] ?? 0) ?>" required>
            </div>
            <div>
                <label>Expiration Date</label>
                <input type="date" name="expiration_date" value="<?= htmlspecialchars($medicine['expiration_date'] ?? '') ?>" required>
            </div>
            <div>
                <label>Price</label>
                <input type="number" name="price" min="0" step="0.01" value="<?= htmlspecialchars((string)($medicine['price'] ?? 0)) ?>" required>
            </div>
            <div>
                <label for="medicine-category">Category</label>
                <?php
                // TASK 1: a real dropdown of the shared categories, always offering the built-in
                // suggestions, the medicine's current value and a "add new" escape hatch.
                $currentCategory = trim((string)($medicine['category'] ?? '')) ?: 'Uncategorized';
                $selectOptions = array_values(array_unique(array_merge($builtInCategories, $categoryOptions, [$currentCategory])));
                sort($selectOptions, SORT_NATURAL | SORT_FLAG_CASE);
                ?>
                <select id="medicine-category" name="category">
                    <?php foreach ($selectOptions as $optionCategory) : ?>
                        <?php $optionSelected = $optionCategory === $currentCategory ? ' selected' : ''; ?>
                        <option value="<?= htmlspecialchars($optionCategory, ENT_QUOTES, 'UTF-8') ?>"<?= $optionSelected ?>><?= htmlspecialchars($optionCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                    <option value="__new__">+ Add new category…</option>
                </select>
                <input type="text" id="medicine-category-new" name="new_category" maxlength="100"
                       placeholder="New category name" autocomplete="off" hidden
                       aria-label="New category name">
            </div>
            <div>
                <label>Image</label>
                <div class="upload-box">
                    <input id="medicine-image-input" type="file" name="medicine_image" accept="image/jpeg,image/png,image/webp" class="hidden-file-input">
                    <label for="medicine-image-input" class="btn btn-secondary upload-trigger"><i class="fa-solid fa-upload"></i> Upload Image</label>
                    <div class="upload-preview-box">
                        <?php if (!empty($medicine['image'])) : ?>
                            <img src="<?= htmlspecialchars($medicine['image']) ?>" alt="Medicine preview" class="upload-preview-image">
                            <button type="button" class="remove-upload-btn" data-clear-input="medicine-image-input" aria-label="Remove image">×</button>
                        <?php else : ?>
                            <span class="upload-empty-state">No image selected</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="action-row" style="margin-top:16px;">
            <button type="submit" class="btn btn-primary">Save Medicine</button>
            <!-- FIX (Bug 5): bottom Back button too — same pink style, plain navigation, no data loss. -->
            <a href="pharmacy_inventory.php" class="btn btn-primary back-to-inventory-btn">
                <i class="fa-solid fa-arrow-left"></i> ← Back to Inventory
            </a>
        </div>
    </form>
</div>
<script>
    function attachMedicineUploadPreview(inputId, previewSelector) {
        const input = document.getElementById(inputId);
        const preview = document.querySelector(previewSelector);
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
                preview.innerHTML = '<span class="upload-empty-state" style="color: var(--pink-700);">Please choose a JPG, PNG, or WEBP image up to 2MB.</span>';
                input.value = '';
                return;
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

    attachMedicineUploadPreview('medicine-image-input', '.upload-preview-box');

    // TASK 1: "+ Add new category…" reveals the free-text field; picking a real category hides it.
    (function bindNewCategoryToggle() {
        const select = document.getElementById('medicine-category');
        const input = document.getElementById('medicine-category-new');
        if (!select || !input) {
            return;
        }
        const sync = () => {
            const isNew = select.value === '__new__';
            input.hidden = !isNew;
            input.required = isNew;
            if (isNew) {
                input.focus();
            }
        };
        select.addEventListener('change', sync);
        sync();
    })();
</script>
<?php include 'includes/footer.php'; ?>
