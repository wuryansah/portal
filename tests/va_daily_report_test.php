<?php
require_once __DIR__ . '/../lib/va_daily_report.php';

$total = 0; $gagal = 0;
function cek(string $nama, $dapat, $harap): void {
    global $total, $gagal;
    $total++;
    if ($dapat === $harap) return;
    $gagal++;
    fwrite(STDERR, "GAGAL: $nama\n  harap: " . var_export($harap, true) . "\n  dapat: " . var_export($dapat, true) . "\n");
}

$run = [
    'date' => '2026-10-23',
    'dryRun' => false,
    'groups' => [[
        'folder' => '10. Oktober', 'period' => 'Oct-26', 'periodRows' => 3798,
        'files' => ['Mall 21 Oktober 2026.txt'],
        'booked' => [['unit' => 'L3-0A135', 'amount' => 592937.0, 'reference' => 'VA-8030001000135', 'source_file' => 'Mall 21 Oktober 2026.txt', 'txn_date' => '21/10/26']],
        'skips' => [['unit_number' => 'GF-0C999', 'va_number' => '4030003000999', 'amount' => '1,000.00', 'txn_date' => '21/10/26', 'txn_time' => '09:10:00', 'source_file' => 'Mall 21 Oktober 2026.txt', 'reason' => 'no billing']],
    ]],
    'fatal' => null,
];

$mail = va_build_report($run);
cek('subjek memuat tanggal', strpos($mail['subject'], '23-10-2026') !== false, true);
cek('subjek memuat jumlah dibukukan', strpos($mail['subject'], '1 dibukukan') !== false, true);
cek('subjek memuat nominal', strpos($mail['subject'], 'Rp592.937') !== false, true);
cek('subjek memuat jumlah skip', strpos($mail['subject'], '1 skip') !== false, true);
cek('body memuat referensi', strpos($mail['html'], 'VA-8030001000135') !== false, true);
cek('body memuat alasan skip', strpos($mail['html'], 'no billing') !== false, true);
cek('body memuat saran tindakan', strpos($mail['html'], 'buat billing') !== false, true);
cek('body memuat periode', strpos($mail['html'], 'Oct-26') !== false, true);

$dry = va_build_report(['date' => '2026-10-23', 'dryRun' => true, 'groups' => [], 'fatal' => null]);
cek('dry run ditandai di subjek', strpos($dry['subject'], 'DRY RUN') !== false, true);
cek('dry run tidak mengirim bahasa sukses', strpos($dry['subject'], '0 dibukukan') !== false, true);

$fatal = va_build_report(['date' => '2026-10-23', 'dryRun' => false, 'groups' => [], 'fatal' => 'MySQL tidak bisa dihubungi']);
cek('alert gagal di subjek', strpos($fatal['subject'], 'GAGAL') !== false, true);
cek('alert gagal memuat alasan', strpos($fatal['html'], 'MySQL tidak bisa dihubungi') !== false, true);

$unsafe = va_build_report(['date' => '2026-10-23', 'dryRun' => false, 'fatal' => null, 'groups' => [[
    'folder' => '10. Oktober', 'period' => 'Oct-26', 'periodRows' => 1, 'files' => ['x.txt'], 'booked' => [],
    'skips' => [['unit_number' => '<script>alert(1)</script>', 'va_number' => '1', 'amount' => '0', 'txn_date' => '21/10/26', 'txn_time' => '00:00:00', 'source_file' => 'x.txt', 'reason' => 'unmatched']],
]]]);
cek('html di-escape', strpos($unsafe['html'], '<script>') === false, true);
cek('saran unmatched', strpos($unsafe['html'], 'mapping') !== false, true);

/* Tabel pembayaran dibatasi 200 baris. */
$many = [];
for ($i = 0; $i < 250; $i++) {
    $many[] = ['unit' => 'U-' . $i, 'amount' => 1000.0, 'reference' => 'VA-' . $i, 'source_file' => 'x.txt', 'txn_date' => '21/10/26'];
}
$capped = va_build_report(['date' => '2026-10-23', 'dryRun' => false, 'fatal' => null, 'groups' => [[
    'folder' => '10. Oktober', 'period' => 'Oct-26', 'periodRows' => 1, 'files' => ['x.txt'], 'booked' => $many, 'skips' => [],
]]]);
cek('tabel dibatasi 200 baris', substr_count($capped['html'], 'VA-'), 200);
cek('catatan baris tersisa', strpos($capped['html'], '50 baris lain') !== false, true);

/* --- Teks Telegram untuk laporan harian ----------------------------------- */
cek('laporan punya teks telegram', is_string($mail['text'] ?? null), true);
cek('teks telegram memuat tanggal', strpos($mail['text'] ?? '', '23-10-2026') !== false, true);
cek('teks telegram memuat jumlah dibukukan', strpos($mail['text'] ?? '', '1 dibukukan') !== false, true);
cek('teks telegram memuat nominal', strpos($mail['text'] ?? '', 'Rp592.937') !== false, true);
cek('teks telegram memuat periode', strpos($mail['text'] ?? '', 'Oct-26') !== false, true);
cek('teks telegram memuat alasan skip', strpos($mail['text'] ?? '', 'no billing') !== false, true);
cek('teks telegram memuat saran skip', strpos($mail['text'] ?? '', 'buat billing') !== false, true);

cek('teks telegram dry run ditandai', strpos($dry['text'] ?? '', 'DRY RUN') !== false, true);
cek('teks telegram alert gagal memuat alasan', strpos($fatal['text'] ?? '', 'MySQL tidak bisa dihubungi') !== false, true);
cek('teks telegram di-escape', strlen($unsafe['text'] ?? '') > 0 && strpos($unsafe['text'] ?? '', '<script>') === false, true);
cek('teks telegram memakai entity html', strpos($unsafe['text'] ?? '', '&lt;script&gt;') !== false, true);

/* Batas keras Telegram 4096 karakter; ringkasan harus tetap muat. */
$banyakSkip = [];
for ($i = 0; $i < 400; $i++) {
    $banyakSkip[] = ['unit_number' => 'GF-0C' . $i, 'va_number' => '4' . $i, 'amount' => 1234.0,
        'txn_date' => '21/10/26', 'txn_time' => '09:10:00', 'source_file' => 'Mall 21 Oktober 2026.txt',
        'reason' => $i % 2 === 0 ? 'no billing' : 'unmatched'];
}
$besar = va_build_report(['date' => '2026-10-23', 'dryRun' => false, 'fatal' => null, 'groups' => [[
    'folder' => '10. Oktober', 'period' => 'Oct-26', 'periodRows' => 1, 'files' => ['a.txt', 'b.txt'],
    'booked' => $many, 'skips' => $banyakSkip,
]]]);
cek('teks telegram muat batas karakter', strlen($besar['text'] ?? '') > 0 && strlen($besar['text'] ?? '') <= 4000, true);

/* Run tanpa transaksi tetap punya laporan yang menjelaskan alasannya. */
$kosong = va_build_report(['date' => '2026-10-29', 'dryRun' => false, 'groups' => [], 'fatal' => null,
    'notes' => ['tidak ada file bank baru']]);
cek('subjek laporan kosong tetap rapi', strpos($kosong['subject'], '0 dibukukan') !== false, true);
cek('html menjelaskan tidak ada file baru', strpos($kosong['html'], 'tidak ada file bank baru') !== false, true);
cek('teks telegram menjelaskan tidak ada file baru', strpos($kosong['text'], 'tidak ada file bank baru') !== false, true);

/* --- Kirim Telegram ------------------------------------------------------- */
putenv('TELEGRAM_BOT_TOKEN');
putenv('TELEGRAM_CHAT_ID');
cek('kirim telegram laporan tanpa config gagal terkendali', va_send_report_telegram($mail), ['ok' => false, 'error' => 'telegram not configured']);

/* --- Watchdog alert report ------------------------------------------------ */
$r = va_watchdog_build_report([
    'date' => '2026-10-28',
    'slots' => [['time' => '09:30']],
    'files' => [['name' => 'Mall 26 Oktober 2026.txt', 'mtime' => time()]],
    'catchup' => ['ran' => false, 'ok' => false, 'summary' => ''],
    'error' => null,
]);
cek('subject peringatan', strpos($r['subject'], 'PERINGATAN 28-10-2026') !== false, true);
cek('subject menyebut run', strpos($r['subject'], '09:30') !== false, true);
cek('html menyebut file', strpos($r['html'], 'Mall 26 Oktober 2026.txt') !== false, true);
cek('teks telegram menyebut file', strpos($r['text'], 'Mall 26 Oktober 2026.txt') !== false, true);

$ok = va_watchdog_build_report([
    'date' => '2026-10-28', 'slots' => [], 'files' => [],
    'catchup' => ['ran' => true, 'ok' => true, 'summary' => '38 dibukukan (Rp102.798.838), 0 skip'],
    'error' => null,
]);
cek('subject catch-up sukses', strpos($ok['subject'], 'catch-up') !== false, true);
cek('teks catch-up berisi hasil', strpos($ok['text'], '38 dibukukan') !== false, true);

$fail = va_watchdog_build_report([
    'date' => '2026-10-28', 'slots' => [], 'files' => [],
    'catchup' => ['ran' => true, 'ok' => false, 'summary' => ''],
    'error' => 'preview gagal',
]);
cek('subject gagal watchdog', strpos($fail['subject'], 'GAGAL WATCHDOG') !== false, true);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
