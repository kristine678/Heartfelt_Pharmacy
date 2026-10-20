<?php
// FIX (Task 3): email-bill.php now builds a pink HTML receipt and really sends it through
// config/mail.php. It serves BOTH callers with one file:
//   - POST (the Email Receipt button on the receipt, via fetch) -> JSON
//       { success, message, demo?, demo_html?, needs_email? }
//   - GET  (the Email button on History, formerly on the removed Bills page) -> session
//     flash + redirect back to the receipt, exactly like before, so old links keep working.
require_once 'config/db.php';
require_once 'config/mail.php';
requireLogin();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$postedEmail = trim((string)($_POST['email'] ?? ''));

// FIX (Task 3): one response helper — JSON for the AJAX receipt button, flash + redirect for GET.
function respondEmailReceipt(bool $isPost, array $payload, int $orderId): void {
    if ($isPost) {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    $_SESSION['flash'] = (string)($payload['message'] ?? '');
    header('Location: print-bill.php?order_id=' . $orderId);
    exit;
}

// FIX (Task 3): the emailed receipt is a pink HTML document. Table layout + inline styles so it
// renders in Gmail/Outlook; every amount comes from the SAME saved order record as the printout.
function buildReceiptEmailHtml(array $items, array $totals, int $orderId, bool $hasPaymentRecord, float $amountPaid, float $changeAmount, string $chargeDateText = '', array $batchAllocations = []): string {
    $money = static function (float $value): string {
        return '₱' . number_format($value, 2);
    };
    $totalRow = static function (string $label, string $value, bool $strong = false): string {
        $labelStyle = $strong
            ? 'padding:10px 0 4px;font-size:16px;font-weight:800;color:#6b0836;'
            : 'padding:5px 0;color:#6b0836;';
        $valueStyle = $strong
            ? 'padding:10px 0 4px;text-align:right;font-size:16px;font-weight:800;color:#e6136e;'
            : 'padding:5px 0;text-align:right;font-weight:700;color:#b8095a;';
        return '<tr><td style="' . $labelStyle . '">' . htmlspecialchars($label) . '</td><td style="' . $valueStyle . '">' . htmlspecialchars($value) . '</td></tr>';
    };

    $itemRows = '';
    foreach ($items as $item) {
        $itemId = (int)($item['id'] ?? 0);
        $itemRows .= '<tr>'
            . '<td style="padding:8px 0;border-bottom:1px solid #ffd6e7;color:#6b0836;">' . htmlspecialchars((string)$item['name'])
            . ' <span style="color:#b8095a;font-weight:700;">x' . (int)$item['quantity'] . '</span></td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #ffd6e7;text-align:right;color:#6b0836;font-weight:700;">'
            . $money((float)$item['price'] * (int)$item['quantity']) . '</td>'
            . '</tr>';
        if (!empty($batchAllocations[$itemId])) {
            $batchParts = [];
            foreach ($batchAllocations[$itemId] as $allocation) {
                $batchLabel = trim((string)$allocation['batch_number']) ?: ('Batch ' . (int)$allocation['medicine_id']);
                $batchParts[] = htmlspecialchars($batchLabel) . ' x' . (int)$allocation['allocated_quantity'];
            }
            $itemRows .= '<tr><td colspan="2" style="padding:0 0 8px;border-bottom:1px solid #ffd6e7;color:#b8095a;font-size:12px;">Batches: ' . implode(', ', $batchParts) . '</td></tr>';
        }
    }
    if ($itemRows === '') {
        $itemRows = '<tr><td colspan="2" style="padding:8px 0;color:#6b0836;">No items recorded for this order.</td></tr>';
    }

    // FIX (Bug 1): "—" for orders saved before Amount Paid / Change were stored.
    $paidText = $hasPaymentRecord ? $money($amountPaid) : '—';
    $changeText = $hasPaymentRecord ? $money($changeAmount) : '—';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Heartfelt Pharmacy Receipt</title></head>'
        . '<body style="margin:0;padding:24px 12px;background:#fff0f6;font-family:Segoe UI,Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 14px 30px rgba(184,9,90,0.18);">'
        . '<tr><td style="background:#e6136e;background:linear-gradient(135deg,#ff2e8a,#b8095a);padding:26px 24px;text-align:center;color:#ffffff;">'
        . '<div style="font-size:22px;font-weight:800;letter-spacing:0.02em;">&#9829; Heartfelt Pharmacy</div>'
        . '<div style="margin-top:6px;font-size:13px;opacity:0.92;">Your health, our heart.</div>'
        . '<div style="margin-top:14px;display:inline-block;background:rgba(255,255,255,0.18);border-radius:999px;padding:6px 14px;font-size:14px;font-weight:700;">Order #' . $orderId . '</div>'
        // FIX (Task 2): the original charge date/time, straight from the saved order record.
        . ($chargeDateText !== '' ? '<div style="margin-top:8px;font-size:12px;opacity:0.92;">' . htmlspecialchars($chargeDateText) . '</div>' : '')
        . '</td></tr>'
        . '<tr><td style="padding:22px 24px 4px;color:#6b0836;font-size:14px;">Thank you for your purchase! Here is your receipt.</td></tr>'
        . '<tr><td style="padding:8px 24px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $itemRows . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px 0;"><div style="border-top:1px dashed #ffd6e7;"></div></td></tr>'
        . '<tr><td style="padding:6px 24px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        . $totalRow('Sub Total', $money((float)$totals['subtotal']))
        . $totalRow('Discount', '- ' . $money((float)$totals['discount']))
        . $totalRow('Service Charge', $money((float)$totals['service_charge']))
        . $totalRow('Tax', $money((float)$totals['tax']))
        . $totalRow('Total', $money((float)$totals['total']), true)
        . $totalRow('Amount Paid', $paidText)
        . $totalRow('Change', $changeText)
        . '</table></td></tr>'
        . '<tr><td style="padding:20px 24px 26px;color:#b8095a;font-size:13px;">Please keep this receipt for your records. This is a system-generated email from Heartfelt Pharmacy.</td></tr>'
        . '</table>'
        . '</body></html>';
}

if (!$orderId) {
    respondEmailReceipt($isPost, ['success' => false, 'message' => 'No order was specified for this receipt.'], 0);
}

$settings = getSettings($pdo);
// FIX (Task 3): LEFT JOIN so a missing customer row can never break the email; the legacy customer
// email is only a fallback for orders created before orders.customer_email existed.
$stmt = $pdo->prepare('SELECT o.*, c.email AS legacy_email FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    respondEmailReceipt($isPost, ['success' => false, 'message' => 'That order could not be found.'], $orderId);
}

// FIX (Bug 4): order items carry their own name/price — do not JOIN products on product_id,
// because POS charges store product_id = 0 and the emailed receipt had zero items.
$itemsStmt = $pdo->prepare('SELECT oi.* FROM order_items oi WHERE oi.order_id = ?');
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();
$batchAllocations = getOrderBatchAllocations($pdo, $orderId);
$calculatedTotals = calculateTotals($items, $settings, $order['discount']);

// FIX (Bug 1): same saved snapshots as the printed receipt, so the email and the printout always agree.
$payment = getReceiptPayment($order);
$totals = getReceiptTotals($order, $calculatedTotals);

// FIX (Task 3): prefer the email saved on the order; the receipt's inline field can supply one, and
// it is persisted on the order so later reopens already know the address.
$to = getReceiptEmail($order);
if ($postedEmail !== '') {
    if (!filter_var($postedEmail, FILTER_VALIDATE_EMAIL)) {
        respondEmailReceipt($isPost, ['success' => false, 'message' => 'That email address does not look valid.'], $orderId);
    }

    $to = $postedEmail;
    if (trim((string)($order['customer_email'] ?? '')) !== $postedEmail) {
        try {
            $pdo->prepare('UPDATE orders SET customer_email = ? WHERE id = ?')->execute([$postedEmail, $orderId]);
        } catch (PDOException $e) {
            // Saving the email is a convenience — never block the send because of it.
        }
    }
}

if ($to === '') {
    // FIX (Task 3): never fail silently — the receipt reveals its inline email field instead.
    respondEmailReceipt($isPost, [
        'success' => false,
        'needs_email' => true,
        'message' => 'No customer email saved for this order. Enter one on the receipt, then send.',
    ], $orderId);
}

// FIX (Task 2): same saved charge timestamp the printed receipt shows.
$chargeDateText = getReceiptChargeDate($order);

$html = buildReceiptEmailHtml($items, $totals, $orderId, (bool)$payment['recorded'], (float)$payment['amount_paid'], (float)$payment['change'], $chargeDateText, $batchAllocations);
$subject = 'Your Heartfelt Pharmacy Receipt (Order #' . $orderId . ')';

// FIX (Task 3): DEMO MODE — with no SMTP credentials the receipt is not sent; it is written to the
// server log and returned so the receipt page can log it to the browser console for preview.
$mailConfig = getMailConfig();
$mailReady = !empty($mailConfig['username']) && !empty($mailConfig['password']);
if (!$mailReady) {
    error_log('[Heartfelt Pharmacy][DEMO MODE] SMTP not configured — receipt email for order #' . $orderId . ' to ' . $to . " would have been:\n" . $html);
    respondEmailReceipt($isPost, [
        'success' => true,
        'demo' => true,
        'demo_html' => $html,
        'message' => 'Demo mode: SMTP is not configured yet, so the receipt was logged instead of sent. Add your mail credentials to config/mail.local.php.',
    ], $orderId);
}

$result = sendMail($to, $subject, $html, null, true);
respondEmailReceipt($isPost, [
    'success' => (bool)$result['sent'],
    'message' => $result['sent'] ? ('Receipt sent to ' . $to . '.') : ("Couldn't send the receipt: " . $result['message']),
], $orderId);
