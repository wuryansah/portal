<?php
/**
 * Kanal notifikasi tambahan (Telegram). Email tetap kanal utama; fungsi di sini
 * tidak boleh melempar fatal — kegagalan hanya dikembalikan sebagai array.
 */

/** Konfigurasi Telegram dari environment (.env dimuat oleh env.php). */
function telegramConfig(): array {
    $token = getenv('TELEGRAM_BOT_TOKEN');
    $chat = getenv('TELEGRAM_CHAT_ID');
    return [
        'token' => $token === false ? '' : trim($token),
        'chat_id' => $chat === false ? '' : trim($chat),
    ];
}

/** Telegram hanya dipakai bila token dan chat id sama-sama terisi. */
function telegramEnabled(array $cfg): bool {
    return trim((string) ($cfg['token'] ?? '')) !== '' && trim((string) ($cfg['chat_id'] ?? '')) !== '';
}

/** Potong teks agar muat batas Telegram (4096 karakter). */
function telegramTrim(string $text, int $limit = 4000): string {
    if ($limit < 1) return '';
    if (strlen($text) <= $limit) return $text;
    return substr($text, 0, $limit);
}

function telegramApiUrl(array $cfg): string {
    return 'https://api.telegram.org/bot' . (string) ($cfg['token'] ?? '') . '/sendMessage';
}

/**
 * Kirim pesan teks ke Telegram.
 *
 * @return array{ok:bool,error?:string,raw?:string}
 */
function sendTelegram(string $text): array {
    $cfg = telegramConfig();
    if (!telegramEnabled($cfg)) {
        return ['ok' => false, 'error' => 'telegram not configured'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl extension not available'];
    }

    $payload = json_encode([
        'chat_id' => $cfg['chat_id'],
        'text' => telegramTrim($text),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $lastError = 'unknown error';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init(telegramApiUrl($cfg));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $lastError = 'curl: ' . $error;
        } else {
            $raw = substr((string) $body, 0, 500);
            $decoded = json_decode((string) $body, true);
            $apiOk = is_array($decoded) && ($decoded['ok'] ?? false) === true;
            if ($status === 200 && $apiOk) {
                return ['ok' => true, 'raw' => $raw];
            }
            $description = is_array($decoded) && isset($decoded['description'])
                ? (string) $decoded['description']
                : 'HTTP ' . $status;
            $lastError = 'telegram: ' . $description;
        }
        if ($attempt === 0) usleep(500000);
    }

    return ['ok' => false, 'error' => $lastError];
}
