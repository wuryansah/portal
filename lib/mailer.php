<?php
// Minimal transactional email sender.
// Config comes from the environment (see .env):
//   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_ENC (tls|ssl|none)
//   MAIL_FROM, MAIL_FROM_NAME
// When SMTP_HOST is empty it falls back to PHP mail() (e.g. Laragon sendmail bridge).

function mailerConfig(): array {
    return [
        'host' => trim((string)getenv('SMTP_HOST')),
        'port' => (int)(getenv('SMTP_PORT') ?: 587),
        'user' => trim((string)getenv('SMTP_USER')),
        'pass' => (string)getenv('SMTP_PASS'),
        'enc'  => strtolower(trim((string)getenv('SMTP_ENC'))) ?: 'tls',
        'from' => trim((string)getenv('MAIL_FROM')) ?: trim((string)getenv('SMTP_USER')),
        'fromName' => trim((string)getenv('MAIL_FROM_NAME')) ?: (getenv('APP_NAME') ?: 'M2S Portal'),
    ];
}

function sendMail(string $to, string $subject, string $html): array {
    $cfg = mailerConfig();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'invalid recipient'];
    if ($cfg['from'] === '' || !filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) $cfg['from'] = 'no-reply@localhost';
    if ($cfg['host'] === '') return mailerSendPhp($to, $subject, $html, $cfg);
    return mailerSendSmtp($to, $subject, $html, $cfg);
}

function mailerSendPhp(string $to, string $subject, string $html, array $cfg): array {
    $headers = "From: " . mailerEncodeHeader($cfg['fromName']) . " <{$cfg['from']}>\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "X-Mailer: M2S Portal";
    $sent = @mail($to, mailerEncodeHeader($subject), $html, $headers);
    return $sent ? ['ok' => true] : ['ok' => false, 'error' => 'mail() failed'];
}

function mailerSendSmtp(string $to, string $subject, string $html, array $cfg): array {
    $host = $cfg['host'];
    $port = $cfg['port'];
    $scheme = $cfg['enc'] === 'ssl' ? 'ssl://' : '';
    $conn = @fsockopen($scheme . $host, $port, $errno, $errstr, 15);
    if (!$conn) return ['ok' => false, 'error' => "connect failed: $errstr ($errno)"];
    stream_set_timeout($conn, 15);

    $code = null; $resp = '';
    if (!mailerCmd($conn, null, $code) || $code !== 220) return mailerClose($conn, 'banner', $code);

    if (!mailerCmd($conn, "EHLO " . (gethostname() ?: 'localhost'), $code) || $code !== 250) return mailerClose($conn, 'ehlo', $code);

    if ($cfg['enc'] === 'tls') {
        if (!mailerCmd($conn, "STARTTLS", $code) || $code !== 220) return mailerClose($conn, 'starttls', $code);
        if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return mailerClose($conn, 'tls handshake', 0);
        if (!mailerCmd($conn, "EHLO " . (gethostname() ?: 'localhost'), $code) || $code !== 250) return mailerClose($conn, 'ehlo(2)', $code);
    }

    $authed = false;
    if ($cfg['user'] !== '') {
        if (mailerCmd($conn, "AUTH LOGIN", $code) && $code === 334
            && mailerCmd($conn, base64_encode($cfg['user']), $code) && $code === 334
            && mailerCmd($conn, base64_encode($cfg['pass']), $code)
            && ($code === 235 || $code === 503)) $authed = true;
        if (!$authed) return mailerClose($conn, 'auth', $code);
    }

    if (!mailerCmd($conn, "MAIL FROM:<{$cfg['from']}>", $code) || $code !== 250) return mailerClose($conn, 'mail from', $code);
    if (!mailerCmd($conn, "RCPT TO:<$to>", $code) || ($code !== 250 && $code !== 251)) return mailerClose($conn, 'rcpt to', $code);
    if (!mailerCmd($conn, "DATA", $code) || $code !== 354) return mailerClose($conn, 'data', $code);

    $messageId = '<' . bin2hex(random_bytes(16)) . '@' . (gethostname() ?: 'localhost') . '>';
    $header = "From: " . mailerEncodeHeader($cfg['fromName']) . " <{$cfg['from']}>\r\n"
        . "To: <$to>\r\n"
        . "Subject: " . mailerEncodeHeader($subject) . "\r\n"
        . "Date: " . date('r') . "\r\n"
        . "Message-ID: $messageId\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "X-Mailer: M2S Portal\r\n"
        . "\r\n";
    $body = preg_replace('/^\./m', '..', $html);
    $body = str_replace("\r\n", "\n", str_replace("\r", "\n", $body));
    $body = str_replace("\n", "\r\n", $body) . "\r\n.\r\n";

    fwrite($conn, $header . $body);
    $resp = '';
    while (($line = fgets($conn)) !== false) { $resp = $line; if (isset($line[3]) && $line[3] === ' ') break; }
    $code = (int)substr($resp, 0, 3);
    if ($code !== 250) return mailerClose($conn, 'delivery', $code);

    mailerCmd($conn, "QUIT", $code);
    fclose($conn);
    return ['ok' => true];
}

function mailerCmd($conn, ?string $cmd, ?int &$code): bool {
    if ($cmd !== null) fwrite($conn, $cmd . "\r\n");
    $resp = '';
    while (($line = fgets($conn)) !== false) {
        $resp .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    if ($line === false && $resp === '') return false;
    $code = (int)substr($resp, 0, 3);
    return $code > 0;
}

function mailerClose($conn, string $stage, ?int $code): array {
    @fclose($conn);
    return ['ok' => false, 'error' => "$stage failed" . ($code ? " (code $code)" : '')];
}

function mailerEncodeHeader(string $value): string {
    if (preg_match('/^[\x20-\x7E]*$/', $value)) return $value;
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}