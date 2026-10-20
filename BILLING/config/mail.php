<?php

function getMailConfig(): array {
    // FIX (staff verification): credentials live in .env (loaded by config/env.php) and take
    // precedence; config/mail.local.php remains only as a legacy fallback for installs that
    // have not migrated their SMTP credentials to .env yet.
    $localConfigPath = __DIR__ . '/mail.local.php';
    $localConfig = file_exists($localConfigPath) ? include $localConfigPath : [];
    if (!is_array($localConfig)) {
        $localConfig = [];
    }

    // FIX (mail not sending): the documented names (EMAIL_HOST/EMAIL_PORT/EMAIL_USER/EMAIL_PASS/
    // EMAIL_FROM) and the app's original BILLING_SMTP_* names are BOTH accepted, so credentials
    // can be written either way; EMAIL_* wins when both are set. A misspelt key therefore still
    // leaves the mailer configured instead of silently disabling every send.
    $value = static function (array $envKeys, string $localKey, $default) use ($localConfig) {
        foreach ($envKeys as $envKey) {
            $envValue = getenv($envKey);
            if ($envValue !== false && $envValue !== '') {
                return $envValue;
            }
        }
        if (isset($localConfig[$localKey]) && $localConfig[$localKey] !== '') {
            return $localConfig[$localKey];
        }
        return $default;
    };

    return [
        'host' => (string) $value(['EMAIL_HOST', 'BILLING_SMTP_HOST'], 'host', 'smtp.gmail.com'),
        'port' => (int) $value(['EMAIL_PORT', 'BILLING_SMTP_PORT'], 'port', 587),
        'encryption' => strtolower((string) $value(['EMAIL_ENCRYPTION', 'BILLING_SMTP_ENCRYPTION'], 'encryption', 'tls')),
        'username' => (string) $value(['EMAIL_USER', 'BILLING_SMTP_USERNAME'], 'username', ''),
        'password' => (string) $value(['EMAIL_PASS', 'BILLING_SMTP_PASSWORD'], 'password', ''),
        'from_email' => (string) $value(['EMAIL_FROM', 'BILLING_SMTP_FROM_EMAIL'], 'from_email', ''),
        'from_name' => (string) $value(['EMAIL_FROM_NAME', 'BILLING_SMTP_FROM_NAME'], 'from_name', 'Heartfelt Pharmacy'),
    ];
}

// FIX (mail not sending): ONE place that answers "can this install actually send email?".
// The verification/reset code service uses it to pick between sending the code and printing it to
// the server console (development fallback), and to report a friendly message to the user.
function mailIsConfigured(): bool
{
    if (strtolower((string) (getenv('BILLING_MAIL_ENABLED') ?: 'true')) === 'false') {
        return false;
    }
    $config = getMailConfig();
    return !empty($config['username']) && !empty($config['password']);
}

function smtpReadResponse($socket): string {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $response .= $line;
        if (preg_match('/^\d{3} /', $line)) {
            $code = (int) substr($line, 0, 3);
            if ($code >= 400) {
                break;
            }
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
    }

    return trim($response);
}

function smtpSendCommand($socket, string $command): string {
    fwrite($socket, $command . "\r\n");
    return smtpReadResponse($socket);
}

// FIX (Task 3): optional $isHtml flag — the receipt email is HTML; existing plain-text callers
// (expiry alerts) keep the exact same behaviour because the flag defaults to false.
function sendMail(string $to, string $subject, string $body, ?array $config = null, bool $isHtml = false): array {
    $config = $config ?? getMailConfig();

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'sent' => false,
            'message' => 'The recipient email address is invalid.',
        ];
    }

    // FIX (staff verification): explicit dev switch - BILLING_MAIL_ENABLED=false in .env makes
    // the app behave as if no email service is configured, so callers fall back to printing
    // verification links to the server console (Apache error log) instead of sending mail.
    if (strtolower((string) (getenv('BILLING_MAIL_ENABLED') ?: 'true')) === 'false') {
        return [
            'sent' => false,
            'message' => 'Email sending is disabled (BILLING_MAIL_ENABLED=false in .env).',
        ];
    }

    if (empty($config['username']) || empty($config['password'])) {
        return [
            'sent' => false,
            'message' => 'SMTP credentials are not configured. Set EMAIL_USER / EMAIL_PASS (or BILLING_SMTP_USERNAME / BILLING_SMTP_PASSWORD) in .env with a Gmail app password.',
        ];
    }

    if (empty($config['from_email'])) {
        $config['from_email'] = $config['username'];
    }

    $host = $config['host'];
    $port = (int) $config['port'];
    $encryption = strtolower((string) $config['encryption']);
    $connectionString = $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $socket = @stream_socket_client($connectionString, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        return [
            'sent' => false,
            'message' => 'Unable to connect to SMTP host: ' . $errstr . ' (' . $errno . ')',
        ];
    }

    stream_set_timeout($socket, 15);
    $response = smtpReadResponse($socket);
    if (strpos($response, '220') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP host did not accept the connection: ' . $response,
        ];
    }

    $helloResponse = smtpSendCommand($socket, 'EHLO ' . gethostname());
    if (strpos($helloResponse, '250') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP greeting failed: ' . $helloResponse,
        ];
    }

    if ($encryption === 'tls') {
        $tlsResponse = smtpSendCommand($socket, 'STARTTLS');
        if (strpos($tlsResponse, '220') !== 0) {
            fclose($socket);
            return [
                'sent' => false,
                'message' => 'STARTTLS failed: ' . $tlsResponse,
            ];
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return [
                'sent' => false,
                'message' => 'TLS negotiation failed.',
            ];
        }

        $helloResponse = smtpSendCommand($socket, 'EHLO ' . gethostname());
        if (strpos($helloResponse, '250') !== 0) {
            fclose($socket);
            return [
                'sent' => false,
                'message' => 'SMTP EHLO after STARTTLS failed: ' . $helloResponse,
            ];
        }
    }

    $authResponse = smtpSendCommand($socket, 'AUTH LOGIN');
    if (strpos($authResponse, '334') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP AUTH LOGIN is not available: ' . $authResponse,
        ];
    }

    $userResponse = smtpSendCommand($socket, base64_encode($config['username']));
    if (strpos($userResponse, '334') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP username challenge failed: ' . $userResponse,
        ];
    }

    $passResponse = smtpSendCommand($socket, base64_encode($config['password']));
    if (strpos($passResponse, '235') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP authentication failed. Use a Gmail app password, not your normal password.',
        ];
    }

    $mailFromResponse = smtpSendCommand($socket, 'MAIL FROM:<' . $config['from_email'] . '>');
    if (strpos($mailFromResponse, '250') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP MAIL FROM failed: ' . $mailFromResponse,
        ];
    }

    $rcptResponse = smtpSendCommand($socket, 'RCPT TO:<' . $to . '>');
    if (strpos($rcptResponse, '250') !== 0 && strpos($rcptResponse, '251') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP RCPT TO failed: ' . $rcptResponse,
        ];
    }

    $dataResponse = smtpSendCommand($socket, 'DATA');
    if (strpos($dataResponse, '354') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP DATA failed: ' . $dataResponse,
        ];
    }

    // FIX (Task 3): HTML receipts are base64 encoded so the ₱ sign and long markup lines survive
    // SMTP's 1000-character line limit; plain-text callers keep the original 7bit behaviour.
    if ($isHtml) {
        $contentType = 'text/html; charset=UTF-8';
        $contentTransferEncoding = 'base64';
        $encodedBody = chunk_split(base64_encode($body), 76, "\r\n");
    } else {
        $contentType = 'text/plain; charset=UTF-8';
        $contentTransferEncoding = '7bit';
        $encodedBody = $body;
    }

    $message = "From: {$config['from_name']} <{$config['from_email']}>\r\n";
    $message .= "Reply-To: {$config['from_email']}\r\n";
    $message .= "To: {$to}\r\n";
    $message .= "Date: " . gmdate('D, d M Y H:i:s O') . "\r\n";
    $message .= "Subject: {$subject}\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: {$contentType}\r\n";
    $message .= "Content-Transfer-Encoding: {$contentTransferEncoding}\r\n";
    $message .= "X-Mailer: PinkBilling\r\n";
    $message .= "X-Priority: 3\r\n";
    $message .= "\r\n";
    $message .= $encodedBody . "\r\n";
    $message .= "\r\n.\r\n";

    fwrite($socket, $message);
    $sendResponse = smtpReadResponse($socket);
    if (strpos($sendResponse, '250') !== 0) {
        fclose($socket);
        return [
            'sent' => false,
            'message' => 'SMTP message send failed: ' . $sendResponse,
        ];
    }

    smtpSendCommand($socket, 'QUIT');
    fclose($socket);

    return [
        'sent' => true,
        'message' => 'Email sent.',
    ];
}
