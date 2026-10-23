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

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
