<?php
require_once __DIR__ . '/../lib/notifier.php';

$total = 0; $gagal = 0;
function cek(string $nama, $dapat, $harap): void {
    global $total, $gagal;
    $total++;
    if ($dapat === $harap) return;
    $gagal++;
    fwrite(STDERR, "GAGAL: $nama\n  harap: " . var_export($harap, true) . "\n  dapat: " . var_export($dapat, true) . "\n");
}

putenv('TELEGRAM_BOT_TOKEN=123:abc');
putenv('TELEGRAM_CHAT_ID=70891352');
$cfg = telegramConfig();
cek('token terbaca', $cfg['token'], '123:abc');
cek('chat id terbaca', $cfg['chat_id'], '70891352');
cek('telegram aktif', telegramEnabled($cfg), true);
cek('url bot api', telegramApiUrl($cfg), 'https://api.telegram.org/bot123:abc/sendMessage');
cek('nonaktif tanpa chat id', telegramEnabled(['token' => 'x', 'chat_id' => '']), false);
cek('teks pendek utuh', telegramTrim('halo'), 'halo');
cek('teks panjang dipotong', strlen(telegramTrim(str_repeat('a', 5000), 100)), 100);
putenv('TELEGRAM_BOT_TOKEN');
putenv('TELEGRAM_CHAT_ID');
cek('kirim tanpa config gagal terkendali', sendTelegram('halo'), ['ok' => false, 'error' => 'telegram not configured']);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
