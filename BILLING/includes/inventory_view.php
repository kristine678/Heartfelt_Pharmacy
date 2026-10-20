<?php
// ONE inventory view (Task 3), shared by:
//   * the Pharmacy pages  (pharmacy_inventory.php, pharmacy_dashboard.php)
//   * the POS "Inventory Dashboard" button (inventory-dashboard.php)
//
// Everything - the status rules, the summary cards, the filters, the table markup and the numbers -
// lives here once. Both pages read the same `medicines` rows with the same `pharmacy_settings`
// thresholds and refresh from the same endpoint (api/inventory.php), so a change in one place shows
// up in the other immediately and the totals can never disagree.
//
// Staff get the read-only view ($options['manage'] = false); admins additionally get per-batch Edit
// links and the Add Medicine button. The server enforces that split (the admin-only pages keep
// requirePageAccess()/requireAdmin()), never the buttons alone.

require_once __DIR__ . '/medicine_helpers.php';

// --- Status rules: the single source of truth for every inventory badge ------------------------
//
// Priority is what a pharmacist needs to act on first: an expired batch, a batch expiring inside
// the alert threshold, an empty batch, a low batch, then healthy stock. Quantities of expired
// batches are NOT counted as usable stock (same rule the Inventory Alerts page uses).
function inventoryStatusFor(int $quantity, ?string $expirationDate, int $expiryThresholdDays, int $stockThreshold): array
{
    $today = date('Y-m-d');
    $expiry = trim((string) $expirationDate);
    $daysRemaining = null;
    if ($expiry !== '') {
        $daysRemaining = (int) floor((strtotime($expiry) - strtotime($today)) / 86400);
    }

    if ($daysRemaining !== null && $daysRemaining < 0) {
        return ['code' => 'expired', 'label' => 'Expired', 'rank' => 0];
    }
    if ($quantity <= 0) {
        return ['code' => 'out', 'label' => 'Out', 'rank' => 2];
    }
    if ($daysRemaining !== null && $daysRemaining <= $expiryThresholdDays) {
        return ['code' => 'expiring', 'label' => 'Expiring soon', 'rank' => 1];
    }
    if ($quantity <= $stockThreshold) {
        return ['code' => 'low', 'label' => 'Low', 'rank' => 3];
    }

    return ['code' => 'in-stock', 'label' => 'In stock', 'rank' => 4];
}

// Normalised medicine key, so "Paracetamol 500mg" and "paracetamol 500 mg " are one medicine
// (identical to the grouping the Inventory Alerts page performs in SQL).
function inventoryMedicineKey(string $name): string
{
    $normalized = preg_replace('/\s+/', '', trim($name));
    $normalized = $normalized === null ? trim($name) : $normalized;
    return function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
}

/**
 * Build the whole view model once: per-batch rows + medicine-level summary + thresholds.
 *
 * $options['search'] / $options['status'] pre-filter server-side so a deep link
 * (?search=para&status=low) still works; the shared script then filters the same table live.
 */
function inventoryViewData(PDO $pdo, array $options = []): array
{
    $expiryThresholdDays = getPharmacyThreshold($pdo);
    $stockThreshold = getPharmacyStockThreshold($pdo);
    $today = date('Y-m-d');

    // Keep the cached expiry_statuses in step with live stock (the Inventory Alerts pop-up and the
    // pharmacy dashboard read from it) - the badges below are computed from the same medicines rows.
    refreshExpiryStatuses($pdo, $expiryThresholdDays);

    $batches = $pdo->query(
        'SELECT id, name, batch_number, quantity, supplier, expiration_date, price
         FROM medicines
         ORDER BY name ASC, expiration_date ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    $medicines = [];
    foreach ($batches as $batch) {
        $quantity = (int) $batch['quantity'];
        $name = trim((string) $batch['name']);
        $batchNumber = trim((string) $batch['batch_number']);
        $expiry = trim((string) $batch['expiration_date']);
        $price = (float) $batch['price'];
        $status = inventoryStatusFor($quantity, $expiry, $expiryThresholdDays, $stockThreshold);
        $status['days_remaining'] = $expiry !== '' ? (int) floor((strtotime($expiry) - strtotime($today)) / 86400) : null;

        $rows[] = [
            'id' => (int) $batch['id'],
            'name' => $name,
            'batch' => $batchNumber,
            'supplier' => trim((string) $batch['supplier']),
            'quantity' => $quantity,
            'expiry' => $expiry,
            'price' => $price,
            'price_formatted' => format_currency($price),
            'status_code' => $status['code'],
            'status_label' => $status['label'],
            'rank' => $status['rank'],
            'days_remaining' => $status['days_remaining'],
            'search' => strtolower($name . ' ' . $batchNumber . ' ' . trim((string) $batch['supplier'])),
        ];

        $key = inventoryMedicineKey($name);
        if (!isset($medicines[$key])) {
            $medicines[$key] = [
                'name' => $name,
                'live_quantity' => 0,
                'has_low' => false,
                'has_out' => false,
                'has_expiring' => false,
                'has_expired' => false,
            ];
        }
        if ($status['code'] === 'expired') {
            // Expired stock cannot be sold, so it must not mask an out-of-stock state.
            $medicines[$key]['has_expired'] = true;
        } else {
            $medicines[$key]['live_quantity'] += $quantity;
        }
        if ($status['code'] === 'expiring') {
            $medicines[$key]['has_expiring'] = true;
        }
    }

    // Medicine-level stock state (one row per medicine, like the alerts) -> summary cards.
    $low = 0;
    $out = 0;
    $expiring = 0;
    $expired = 0;
    $inStock = 0;
    $totalUnits = 0;
    foreach ($medicines as $medicine) {
        $live = (int) $medicine['live_quantity'];
        $totalUnits += $live;
        if ($live <= 0) {
            $out++;
        } elseif ($live <= $stockThreshold) {
            $low++;
        } else {
            $inStock++;
        }
        if ($medicine['has_expiring']) {
            $expiring++;
        }
        if ($medicine['has_expired']) {
            $expired++;
        }
    }

    // Problem batches first (expired, expiring, out, low, in stock), then by name.
    usort($rows, static function (array $a, array $b): int {
        return [$a['rank'], $a['name'], $a['expiry']] <=> [$b['rank'], $b['name'], $b['expiry']];
    });

    $data = [
        'rows' => $rows,
        'summary' => [
            'total_medicines' => count($medicines),
            'total_units' => $totalUnits,
            'low' => $low,
            'out' => $out,
            'expiring' => $expiring,
            'expired' => $expired,
            'in_stock' => $inStock,
            'batches' => count($rows),
        ],
        // Both thresholds come from pharmacy_settings, so the badges here agree with the Inventory
        // Alerts pop-up by construction (one configuration, one meaning of "expiring soon").
        'thresholds' => [
            'expiry_days' => $expiryThresholdDays,
            'stock_units' => $stockThreshold,
        ],
        'generated_at' => date('c'),
        'generated_label' => date('M j, Y g:i a'),
    ];

    return $data;
}

// Summary cards (same tones/labels everywhere; the numbers are the model above).
function inventorySummaryCards(array $data): string
{
    $summary = $data['summary'];
    $cards = [
        ['fa-capsules', (string) $summary['total_medicines'], 'Total medicines', ''],
        ['fa-layer-group', number_format((int) $summary['total_units']), 'Total stock units', ''],
        ['fa-triangle-exclamation', (string) $summary['low'], 'Low stock', $summary['low'] > 0 ? 'is-warning' : ''],
        ['fa-box-open', (string) $summary['out'], 'Out of stock', $summary['out'] > 0 ? 'is-danger' : ''],
        ['fa-hourglass-half', (string) $summary['expiring'], 'Expiring soon (≤' . (int) $data['thresholds']['expiry_days'] . 'd)', $summary['expiring'] > 0 ? 'is-warning' : ''],
        ['fa-calendar-xmark', (string) $summary['expired'], 'Expired batches', $summary['expired'] > 0 ? 'is-danger' : ''],
    ];
    $keys = ['total_medicines', 'total_units', 'low', 'out', 'expiring', 'expired'];

    $html = '<div class="inv-dash-cards">';
    foreach ($cards as $index => $card) {
        $html .= '<div class="inv-dash-card' . ($card[3] !== '' ? ' ' . $card[3] : '') . '">'
            . '<div class="inv-dash-icon"><i class="fa-solid ' . $card[0] . '" aria-hidden="true"></i></div>'
            . '<div class="inv-dash-value" data-inv-value="' . $keys[$index] . '">' . htmlspecialchars($card[1], ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="inv-dash-label">' . htmlspecialchars($card[2], ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div>';
    }
    return $html . '</div>';
}

// Search + status filter (+ optional sort). Identical on every page; the shared script filters the
// rendered table live, and the same values also work as a server-side deep link.
function inventoryFilters(array $data, array $options = []): string
{
    $statuses = [
        'expired' => 'Expired',
        'expiring' => 'Expiring soon',
        'out' => 'Out',
        'low' => 'Low',
        'in-stock' => 'In stock',
    ];
    $activeStatus = (string) ($options['status'] ?? 'all');
    $search = (string) ($options['search'] ?? '');
    $sortable = !empty($options['sortable']);
    $activeSort = (string) ($options['sort'] ?? 'attention');

    $html = '<div class="inv-dash-toolbar">';
    $html .= '<input id="invSearch" type="search" class="soft-search" data-inv-search placeholder="Search medicine, batch, or supplier..." '
        . 'aria-label="Search medicines" value="' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<select id="invStatus" class="inv-dash-status-filter" data-inv-status aria-label="Filter by status" style="max-width:200px;">';
    $html .= '<option value="all">All statuses</option>';
    foreach ($statuses as $code => $label) {
        $html .= '<option value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
            . ($activeStatus === $code ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $html .= '</select>';
    if ($sortable) {
        $html .= '<select id="invSort" data-inv-sort aria-label="Sort medicines" style="max-width:200px;">'
            . '<option value="attention"' . ($activeSort === 'name' ? '' : ' selected') . '>Sort: needs attention first</option>'
            . '<option value="name"' . ($activeSort === 'name' ? ' selected' : '') . '>Sort: name (A-Z)</option>'
            . '<option value="expiry"' . ($activeSort === 'expiry' ? ' selected' : '') . '>Sort: expiry date</option>'
            . '</select>';
    }
    $html .= '<span class="muted" data-inv-count>' . count($data['rows']) . ' of ' . count($data['rows']) . ' batches</span>';
    $html .= '</div>';
    return $html;
}

// The table itself. $options['manage'] adds the admin-only action column; $options['allow_delete']
// additionally renders the admin-only delete form (a POST, never a GET link).
function inventoryTable(array $data, array $options = []): string
{
    $manage = !empty($options['manage']);
    $allowDelete = !empty($options['allow_delete']);
    $editUrl = (string) ($options['edit_url'] ?? 'pharmacy_form.php?id=');
    $rows = $data['rows'];

    if (!$rows) {
        return '<p class="empty-state">No medicines in the inventory yet.</p>';
    }

    $html = '<div class="table-wrap"><table class="table-basic inv-dash-table" id="invTable">'
        . '<thead><tr><th>Medicine</th><th>Batch</th><th>Qty</th><th>Supplier</th><th>Expiry</th><th>Price</th><th>Status</th>'
        . ($manage ? '<th>Actions</th>' : '') . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr class="inv-row-' . htmlspecialchars($row['status_code'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-inv-id="' . (int) $row['id'] . '"'
            . ' data-status="' . htmlspecialchars($row['status_code'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-rank="' . (int) $row['rank'] . '"'
            . ' data-search="' . htmlspecialchars($row['search'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-expiry="' . htmlspecialchars($row['expiry'], ENT_QUOTES, 'UTF-8') . '">'
            . '<td><strong>' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</strong></td>'
            . '<td>' . htmlspecialchars($row['batch'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td data-inv-qty>' . (int) $row['quantity'] . '</td>'
            . '<td>' . htmlspecialchars($row['supplier'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($row['expiry'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($row['price_formatted'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td><span class="inv-status ' . htmlspecialchars($row['status_code'], ENT_QUOTES, 'UTF-8') . '" data-inv-badge>'
            . htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') . '</span></td>';
        if ($manage) {
            $html .= '<td><a class="btn btn-secondary" href="' . htmlspecialchars($editUrl . (int) $row['id'], ENT_QUOTES, 'UTF-8') . '">Edit</a>';
            if ($allowDelete) {
                $html .= '<form method="post" style="display:inline;">'
                    . '<input type="hidden" name="action" value="delete">'
                    . '<input type="hidden" name="medicine_id" value="' . (int) $row['id'] . '">'
                    . '<button class="btn btn-danger" type="submit" onclick="return confirm(\'Delete this medicine?\')">Delete</button>'
                    . '</form>';
            }
            $html .= '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
}

/**
 * Render the complete view.
 *
 * Options: manage (admin actions), back_to_pos (adds the POS return button), add_url (Add Medicine
 * button for admins), endpoint (shared JSON endpoint used by the auto-refresh script),
 * search/status/sort (initial filter values), edit_url, extras (extra header buttons HTML).
 */
function renderInventoryView(array $data, array $options = []): string
{
    $endpoint = (string) ($options['endpoint'] ?? 'api/inventory.php');
    $manage = !empty($options['manage']);
    $allowDelete = !empty($options['allow_delete']);

    $html = '<div class="inventory-view" data-inventory-view data-inventory-endpoint="' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-can-manage="' . ($manage ? 'true' : 'false') . '">';
    $html .= inventorySummaryCards($data);
    $html .= '<div class="form-card">';
    $html .= inventoryFilters($data, $options);
    $html .= inventoryTable($data, $options + ['allow_delete' => $allowDelete]);
    $html .= '<p class="muted" data-inv-updated style="margin-top:10px;font-size:0.8rem;">'
        . 'Numbers refresh automatically after every sale · updated <span data-inv-updated-at>'
        . htmlspecialchars((string) $data['generated_label'], ENT_QUOTES, 'UTF-8') . '</span></p>';
    $html .= '</div></div>';

    return $html;
}
