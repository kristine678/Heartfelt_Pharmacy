<?php

require_once __DIR__ . '/../config/mail.php';

function computeMedicineStatus(string $expirationDate, int $thresholdDays, ?int $quantity = null, ?int $stockThreshold = null): array {
    $today = new DateTime('today');
    $expiry = new DateTime($expirationDate);
    $daysRemaining = (int) $today->diff($expiry)->format('%r%a');

    $stockLimit = $stockThreshold ?? 10;
    if ($quantity !== null && $quantity <= $stockLimit) {
        if ($expiry < $today) {
            return [
                'status' => 'Expired',
                'days_remaining' => $daysRemaining,
                'badge_class' => 'status-expired',
            ];
        }

        if ($daysRemaining <= $thresholdDays) {
            return [
                'status' => 'Near-Expiry',
                'days_remaining' => $daysRemaining,
                'badge_class' => 'status-warning',
            ];
        }

        return [
            'status' => 'Low stock',
            'days_remaining' => $daysRemaining,
            'badge_class' => 'status-warning',
        ];
    }

    if ($expiry < $today) {
        return [
            'status' => 'Expired',
            'days_remaining' => $daysRemaining,
            'badge_class' => 'status-expired',
        ];
    }

    if ($daysRemaining <= $thresholdDays) {
        return [
            'status' => 'Near-Expiry',
            'days_remaining' => $daysRemaining,
            'badge_class' => 'status-warning',
        ];
    }

    return [
        'status' => 'Safe',
        'days_remaining' => $daysRemaining,
        'badge_class' => 'status-safe',
    ];
}

function getPharmacyThreshold(PDO $pdo): int {
    $stmt = $pdo->query('SELECT alert_threshold_days FROM pharmacy_settings ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->exec("INSERT INTO pharmacy_settings (alert_threshold_days, stock_threshold, notify_email) VALUES (30, 10, NULL)");
        return 30;
    }

    return max(1, (int) $row['alert_threshold_days']);
}

function getPharmacyStockThreshold(PDO $pdo): int {
    $stmt = $pdo->query('SELECT stock_threshold FROM pharmacy_settings ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->exec("INSERT INTO pharmacy_settings (alert_threshold_days, stock_threshold, notify_email) VALUES (30, 10, NULL)");
        return 10;
    }

    return max(1, (int) $row['stock_threshold']);
}

function setPharmacyThreshold(PDO $pdo, int $thresholdDays): void {
    $threshold = max(1, (int) $thresholdDays);
    $stmt = $pdo->query('SELECT id FROM pharmacy_settings ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();

    if ($row) {
        $update = $pdo->prepare('UPDATE pharmacy_settings SET alert_threshold_days = ? WHERE id = ?');
        $update->execute([$threshold, $row['id']]);
        return;
    }

    $insert = $pdo->prepare('INSERT INTO pharmacy_settings (alert_threshold_days, stock_threshold, notify_email) VALUES (?, 10, NULL)');
    $insert->execute([$threshold]);
}

function refreshExpiryStatuses(PDO $pdo, int $thresholdDays): void {
    $threshold = max(1, (int) $thresholdDays);
    $stockThreshold = getPharmacyStockThreshold($pdo);
    $pdo->exec('DELETE FROM expiry_statuses');

    $stmt = $pdo->query('SELECT id, expiration_date, quantity FROM medicines WHERE expiration_date IS NOT NULL');
    $medicines = $stmt->fetchAll();

    $insert = $pdo->prepare('INSERT INTO expiry_statuses (medicine_id, status, days_remaining, threshold_days, checked_at) VALUES (?, ?, ?, ?, NOW())');
    foreach ($medicines as $medicine) {
        $status = computeMedicineStatus($medicine['expiration_date'], $threshold, (int) $medicine['quantity'], $stockThreshold);
        $insert->execute([
            (int) $medicine['id'],
            $status['status'],
            $status['days_remaining'],
            $threshold,
        ]);
    }
}

function buildMedicineSummary(PDO $pdo, int $thresholdDays): array {
    refreshExpiryStatuses($pdo, $thresholdDays);

    $summary = [
        'total' => 0,
        'safe' => 0,
        'warning' => 0,
        'expired' => 0,
    ];

    $stmt = $pdo->query('SELECT status FROM expiry_statuses');
    foreach ($stmt->fetchAll() as $row) {
        $summary['total']++;
        if ($row['status'] === 'Expired') {
            $summary['expired']++;
        } elseif ($row['status'] === 'Near-Expiry') {
            $summary['warning']++;
        } else {
            $summary['safe']++;
        }
    }

    return $summary;
}

function getExpiryAlertRecipients(PDO $pdo): array {
    $stmt = $pdo->query('SELECT notify_email FROM pharmacy_settings ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    if (!$row || empty(trim((string) $row['notify_email']))) {
        $mailConfig = getMailConfig();
        return array_filter([$mailConfig['from_email'] ?: $mailConfig['username']]);
    }

    return array_filter(array_map('trim', preg_split('/[;,]/', (string) $row['notify_email']) ?: []));
}

function sendExpiryAlerts(PDO $pdo): array {
    $threshold = getPharmacyThreshold($pdo);
    refreshExpiryStatuses($pdo, $threshold);

    $stmt = $pdo->query('SELECT m.name, m.batch_number, m.expiration_date, e.status, e.days_remaining FROM medicines m JOIN expiry_statuses e ON e.medicine_id = m.id WHERE e.status IN ("Near-Expiry", "Expired") ORDER BY e.days_remaining ASC');
    $items = $stmt->fetchAll();

    if (empty($items)) {
        return [
            'sent' => false,
            'message' => 'No near-expiry or expired medicines found.',
        ];
    }

    $recipients = getExpiryAlertRecipients($pdo);
    if (empty($recipients)) {
        return [
            'sent' => false,
            'message' => 'No alert recipient is configured.',
        ];
    }

    $lines = [];
    foreach ($items as $item) {
        $lines[] = '- ' . $item['name'] . ' (Batch ' . $item['batch_number'] . '): ' . $item['status'] . ' - ' . $item['days_remaining'] . ' days';
    }

    $body = "Expiry alert for your pharmacy inventory:\n\n" . implode("\n", $lines) . "\n\nPlease review stock promptly.";

    $subject = 'Pharmacy expiry alert: ' . count($items) . ' item(s) need attention';
    $sentCount = 0;
    foreach ($recipients as $recipient) {
        $result = sendMail($recipient, $subject, $body);
        if ($result['sent']) {
            $sentCount++;
        }
    }

    return [
        'sent' => $sentCount > 0,
        'message' => 'Sent ' . $sentCount . ' email alert(s).',
    ];
}
