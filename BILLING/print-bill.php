<?php
require_once 'config/db.php';
requireLogin();

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    header('Location: index.php');
    exit;
}

$settings = getSettings($pdo);
// FIX (Task 3): LEFT JOIN + aliased legacy email — the receipt email now prefers orders.customer_email
// and only falls back to the linked customer's email for orders created before that column existed.
// LEFT JOIN so a missing customer row can never blank out the receipt.
$stmt = $pdo->prepare('SELECT o.*, c.email AS legacy_email FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: history.php');
    exit;
}

// FIX (Bug 4): order items carry their own name/price — do not JOIN products on product_id,
// because POS charges store product_id = 0 and the receipt rendered with zero items.
$itemsStmt = $pdo->prepare('SELECT oi.* FROM order_items oi WHERE oi.order_id = ?');
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();
$batchAllocations = getOrderBatchAllocations($pdo, $orderId);
$calculatedTotals = calculateTotals($items, $settings, $order['discount']);

// FIX (Bug 1): Amount Paid / Change are read from the SAVED order record — never recalculated — so a
// receipt reopened later from History shows exactly what was taken at the counter. Orders saved
// before this fix stored 0.00 in these columns, so "not recorded" renders as an em dash.
$payment = getReceiptPayment($order);
$amountPaidDisplay = $payment['recorded'] ? format_currency($payment['amount_paid']) : '—';
$changeDisplay = $payment['recorded'] ? format_currency($payment['change']) : '—';

// FIX (Bug 1): the rest of the money rows also prefer the saved snapshot, so historical receipts
// stay accurate even if tax/service rates change later (legacy rows fall back to calculated totals).
$receiptTotals = getReceiptTotals($order, $calculatedTotals);

// FIX (Task 3): saved email drives the Email Receipt button (inline field appears when it is empty).
$receiptEmail = getReceiptEmail($order);

// FIX (Task 2): original charge date/time from the saved order record (paid_at, created_at fallback).
$chargeDateText = getReceiptChargeDate($order);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Heartfelt Pharmacy receipt and order summary.">
    <title>Heartfelt Pharmacy | Receipt</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<!-- FIX (Bug 2): receipt now sits on a white sheet (.receipt) so text stays readable on the dark background. -->
<div class="main-content">
<!-- FIX (Bug 2): single .receipt white sheet (a duplicated wrapper was removed here). -->
<div class="receipt">
        <?php if (!empty($_SESSION['flash'])) : ?>
            <div class="alert" style="margin-bottom: 12px;"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>
        <h1>Heartfelt Pharmacy</h1>
        <!-- FIX (Task 2): the Customer / Table / Type lines were removed from the receipt — this is a
             pharmacy counter receipt, so only the Order # identifies it. The underlying fields are
             NOT deleted: orders.customer_id / orders.order_type are still written by the charge flow
             and used by ajax/add_item.php + ajax/create_order.php, and customers.email/name still
             feed the legacy email fallback in getReceiptEmail(). -->
        <p class="receipt-tagline">Your health, our heart.</p>
        <p class="receipt-order-no"><strong>Order #<?= (int)$orderId ?></strong></p>
        <?php if ($chargeDateText !== '') : ?>
            <!-- FIX (Task 2): the ORIGINAL charge date/time, pulled from the saved order record. -->
            <p class="receipt-datetime"><?= htmlspecialchars($chargeDateText) ?></p>
        <?php endif; ?>
        <hr>
        <?php foreach ($items as $item) : ?>
            <div class="total-line">
                <span><?= htmlspecialchars($item['name']) ?> x<?= (int)$item['quantity'] ?></span>
                <strong><?= format_currency((float)$item['price'] * (int)$item['quantity']) ?></strong>
            </div>
            <?php if (!empty($batchAllocations[(int)$item['id']])) : ?>
                <div class="muted" style="margin:-4px 0 8px 10px;">
                    Batches:
                    <?php foreach ($batchAllocations[(int)$item['id']] as $index => $allocation) : ?>
                        <?= $index > 0 ? ', ' : '' ?><?= htmlspecialchars($allocation['batch_number'] ?: ('Batch ' . $allocation['medicine_id'])) ?> ×<?= (int)$allocation['allocated_quantity'] ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <hr>
        <div class="total-line"><span>Sub Total</span><strong><?= format_currency($receiptTotals['subtotal']) ?></strong></div>
        <div class="total-line"><span>Discount</span><strong><?= format_currency($receiptTotals['discount']) ?></strong></div>
        <div class="total-line"><span>Service Charge</span><strong><?= format_currency($receiptTotals['service_charge']) ?></strong></div>
        <div class="total-line"><span>Tax</span><strong><?= format_currency($receiptTotals['tax']) ?></strong></div>
        <div class="total-line total-final"><span>Total</span><strong><?= format_currency($receiptTotals['total']) ?></strong></div>
        <!-- FIX (Bug 1): Amount Paid + Change, taken straight from the saved order record. -->
        <div class="total-line receipt-paid"><span>Amount Paid</span><strong><?= htmlspecialchars($amountPaidDisplay) ?></strong></div>
        <div class="total-line receipt-change"><span>Change</span><strong><?= htmlspecialchars($changeDisplay) ?></strong></div>

        <!-- FIX (Task 3): the Email Receipt button now really sends. It posts the order id (plus an
             email typed right here when none was saved) to email-bill.php and shows loading +
             success/failure inline instead of silently redirecting. -->
        <div class="action-row receipt-actions">
            <form id="emailReceiptForm" class="email-receipt-form" data-order-id="<?= (int)$orderId ?>">
                <div class="receipt-email-row">
                    <input id="receiptEmailInput" type="email" class="soft-search<?= $receiptEmail === '' ? '' : ' hidden' ?>" placeholder="customer@email.com" value="<?= htmlspecialchars($receiptEmail) ?>" aria-label="Customer email for the receipt" inputmode="email" autocomplete="email" />
                    <button type="submit" id="emailReceiptBtn" class="btn btn-primary">Email Receipt</button>
                    <button type="button" id="receiptEmailToggle" class="btn btn-secondary<?= $receiptEmail === '' ? ' hidden' : '' ?>">Change email</button>
                </div>
            </form>
            <div id="emailReceiptMessage" class="inline-warning hidden" role="status" aria-live="polite"></div>
        </div>
</div><!-- /.receipt -->
<script>
// FIX (Task 3): receipt email sender — loading state on the button, inline success/failure message,
// inline email prompt when the order has none, and a labeled demo-mode console preview when SMTP is
// not configured yet (see config/mail.local.php). No external libraries, no page reload.
(function () {
    var form = document.getElementById('emailReceiptForm');
    if (!form) { return; }
    var input = document.getElementById('receiptEmailInput');
    var button = document.getElementById('emailReceiptBtn');
    var toggle = document.getElementById('receiptEmailToggle');
    var message = document.getElementById('emailReceiptMessage');
    var defaultLabel = button ? button.innerHTML : 'Email Receipt';

    function setMessage(text, state) {
        if (!message) { return; }
        message.textContent = text;
        message.classList.remove('hidden', 'receipt-message-ok', 'receipt-message-error', 'receipt-message-demo');
        message.classList.add(state === 'ok' ? 'receipt-message-ok' : (state === 'demo' ? 'receipt-message-demo' : 'receipt-message-error'));
    }

    if (toggle && input) {
        toggle.addEventListener('click', function () {
            input.classList.remove('hidden');
            toggle.classList.add('hidden');
            input.focus();
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!button || button.disabled) { return; }

        var email = input ? input.value.trim() : '';
        if (!email) {
            if (input) { input.classList.remove('hidden'); input.focus(); }
            if (toggle) { toggle.classList.add('hidden'); }
            setMessage('Enter the customer email first, then send the receipt.', 'error');
            return;
        }

        button.disabled = true;
        button.innerHTML = '<span class="email-spinner" aria-hidden="true"></span><span>Sending…</span>';
        if (message) { message.classList.add('hidden'); }

        var payload = new FormData();
        payload.append('order_id', form.dataset.orderId || '');
        payload.append('email', email);

        fetch('email-bill.php', { method: 'POST', body: payload, headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json().catch(function () { return null; }); })
            .then(function (json) {
                if (!json) { throw new Error("Couldn't send the receipt, please try again."); }
                if (json.demo_html && window.console) {
                    // DEMO MODE: the generated receipt is logged so it can be previewed before real sending.
                    console.log('%c[DEMO MODE] Heartfelt Pharmacy receipt email — not sent (configure config/mail.local.php):', 'color:#e6136e;font-weight:700;');
                    console.log(json.demo_html);
                }
                if (json.demo) {
                    setMessage(json.message || 'Demo mode: the receipt was logged instead of sent.', 'demo');
                    return;
                }
                if (!json.success) {
                    if (json.needs_email && input) { input.classList.remove('hidden'); input.focus(); }
                    throw new Error(json.message || "Couldn't send the receipt, please try again.");
                }
                if (input) { input.classList.add('hidden'); }
                if (toggle) { toggle.classList.add('hidden'); }
                setMessage(json.message || ('Receipt sent to ' + email + '.'), 'ok');
            })
            .catch(function (error) {
                setMessage(error.message || "Couldn't send the receipt, please try again.", 'error');
            })
            .finally(function () {
                button.disabled = false;
                button.innerHTML = defaultLabel;
            });
    });
})();
</script>
<script>window.print();</script>
</body>
</html>
