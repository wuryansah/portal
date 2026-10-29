<?php
require_once __DIR__ . '/../lib/mailer.php';

$total = 0; $gagal = 0;
function cek(string $nama, $dapat, $harap): void {
    global $total, $gagal;
    $total++;
    if ($dapat === $harap) return;
    $gagal++;
    fwrite(STDERR, "GAGAL: $nama\n  harap: " . var_export($harap, true) . "\n  dapat: " . var_export($dapat, true) . "\n");
}

/* Retry hanya untuk kegagalan sementara (jaringan/SMTP), bukan input salah. */
cek('sukses tidak perlu retry', mailerRetryable(['ok' => true]), false);
cek('recipient invalid tidak diulang', mailerRetryable(['ok' => false, 'error' => 'invalid recipient']), false);
cek('connect gagal dicoba ulang', mailerRetryable(['ok' => false, 'error' => 'connect failed: Connection refused (111)']), true);
cek('ehlo gagal dicoba ulang', mailerRetryable(['ok' => false, 'error' => 'ehlo(2) failed (code 220)']), true);
cek('delivery 421 dicoba ulang', mailerRetryable(['ok' => false, 'error' => 'delivery failed (code 421)']), true);
cek('error tanpa pesan tetap dicoba ulang', mailerRetryable(['ok' => false]), true);

/* Alamat salah harus ditolak sebelum menyentuh jaringan. */
cek('sendMail menolak alamat invalid', sendMail('bukan-email', 'x', '<p>x</p>'), ['ok' => false, 'error' => 'invalid recipient']);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
