<?php
require_once __DIR__ . '/../lib/va_daily_lib.php';

$total = 0; $gagal = 0;
function cek(string $nama, $dapat, $harap): void {
    global $total, $gagal;
    $total++;
    if ($dapat === $harap) return;
    $gagal++;
    fwrite(STDERR, "GAGAL: $nama\n  harap: " . var_export($harap, true) . "\n  dapat: " . var_export($dapat, true) . "\n");
}

cek('folder Oktober', va_folder_name(10), '10. Oktober');
cek('folder Januari', va_folder_name(1), '01. Januari');
cek('geser bulan mundur', va_shift_month(2026, 1, -1), ['year' => 2025, 'month' => 12]);
cek('geser bulan maju', va_shift_month(2026, 12, 1), ['year' => 2027, 'month' => 1]);
cek('parse Mall', va_parse_report_filename('Mall 21 Oktober 2026.txt'),
    ['scope' => 'Mall', 'day' => 21, 'month' => 10, 'year' => 2026]);
cek('parse Ruko lowercase', va_parse_report_filename('ruko 3 Agustus 2026.txt'),
    ['scope' => 'Ruko', 'day' => 3, 'month' => 8, 'year' => 2026]);
cek('tolak salinan', va_parse_report_filename('Mall 21 Oktober 2026 - Copy.txt'), null);
cek('tolak bulan asing', va_parse_report_filename('Mall 21 Foo 2026.txt'), null);

$periodCounts = ['Aug-26' => 3587, 'Sep 2026' => 3633, 'Oct-26' => 3798, 'Jan-26' => 1];
cek('periode Oktober', va_pick_period($periodCounts, 10, 2026), 'Oct-26');
cek('periode September', va_pick_period($periodCounts, 9, 2026), 'Sep 2026');
cek('periode November belum ada', va_pick_period($periodCounts, 11, 2026), null);
cek('pilih yang paling banyak dipakai', va_pick_period(['Aug 2026' => 5, 'Aug-26' => 10], 8, 2026), 'Aug-26');
cek('tahun lain tidak cocok', va_pick_period(['Oct-25' => 999], 10, 2026), null);

cek('Minggu bukan hari kerja', va_is_run_day(0), false);
cek('Senin hari kerja', va_is_run_day(1), true);
cek('Sabtu hari kerja', va_is_run_day(6), true);

$sig = ['mtime' => 100, 'size' => 20];
cek('file baru', va_needs_processing(null, $sig), true);
cek('file tidak berubah', va_needs_processing($sig, $sig), false);
cek('file berubah size', va_needs_processing(['mtime' => 100, 'size' => 21], $sig), true);

$preview = [
    ['source_file' => 'Mall 21 Oktober 2026.txt', 'va_number' => '8030001000135', 'amount' => 592937.0, 'txn_date' => '21/10/26', 'txn_time' => '09:00:00'],
    ['source_file' => 'Mall 21 Oktober 2026.txt', 'va_number' => '8030001000101', 'amount' => 760366.0, 'txn_date' => '21/10/26', 'txn_time' => '09:05:00'],
];
$skips = [$preview[1] + ['reason' => 'duplicate amount']];
$booked = va_diff_rows($preview, $skips);
cek('diff menyisakan yang dibukukan', count($booked), 1);
cek('diff memilih baris pertama', $booked[0]['va_number'], '8030001000135');
cek('diff aman saat skip kosong', count(va_diff_rows($preview, [])), 2);
cek('signature skip stabil', va_skip_signature($skips), va_skip_signature([$skips[0]]));
cek('format uang', va_money(17675675.0), 'Rp17.675.675');

/* Nominal dari CSV skip ditulis sebagai "592,937.00"; kunci harus sama dengan preview (float). */
$csvSkip = ['source_file' => 'Mall 21 Oktober 2026.txt', 'va_number' => '8030001000135',
            'amount' => '592,937.00', 'txn_date' => '21/10/26', 'txn_time' => '09:00:00', 'reason' => 'no billing'];
cek('nominal CSV berkoma setara float',
    va_row_key($csvSkip),
    va_row_key($preview[0]));
cek('diff mengenali skip berformat CSV', count(va_diff_rows($preview, [$csvSkip])), 1);

/* --- Task 3: booking state ------------------------------------------------ */
$recordOut = <<<TXT

=== ANALYSIS (Oct-26) ===
Queued for booking : 2 payments
Skipped            : 1
  - no billing : 1

=== DONE ===
Payments booked : 2
Skipped         : 1 (no billing: 1)
2 VA payments to Oct-26 from Mall 21 Oktober 2026.txt
0 VA payments to Oct-26 from Ruko 21 Oktober 2026.txt
Skipped items saved for review: C:\\laragon\\www\\portal\\va_skip_review_oct26.csv
TXT;
$parsed = va_parse_record_output($recordOut);
cek('parse booked', $parsed['booked'], 2);
cek('parse skipped', $parsed['skipped'], 1);
cek('parse alasan skip', $parsed['reasons'], ['no billing' => 1]);
cek('parse per file', $parsed['perFile'], ['Mall 21 Oktober 2026.txt' => 2, 'Ruko 21 Oktober 2026.txt' => 0]);
cek('parse nothing to do', va_parse_record_output("Nothing to do.\n")['booked'], 0);
cek('kunci periode', va_period_key('Sep 2026'), 'sep2026');
$recordCmd = va_build_record_command('php', 'x.xls', 'Oct-26', ['Mall 21 Oktober 2026.txt'], 3);
cek('record --yes selalu dipakai', in_array('--yes', $recordCmd, true), true);
cek('record memakai periode', $recordCmd[array_search('--period', $recordCmd, true) + 1], 'Oct-26');

$skipCsvPath = sys_get_temp_dir() . '/va_skip_test_' . getmypid() . '.csv';
va_write_skip_csv($skipCsvPath, [[
    'source_file' => 'Mall 21 Oktober 2026.txt', 'va_number' => '8030001000135', 'va_name' => 'GF0C143',
    'unit_number' => 'GF-0C143', 'amount' => 1000.0, 'txn_date' => '21/10/26', 'txn_time' => '09:00:00',
    'reason' => 'no billing',
]]);
$back = va_read_skip_csv($skipCsvPath);
cek('csv skip roundtrip jumlah', count($back), 1);
cek('csv skip roundtrip alasan', $back[0]['reason'] ?? null, 'no billing');
cek('csv skip roundtrip nominal', va_amount_value($back[0]['amount'] ?? '0'), 1000.0);
@unlink($skipCsvPath);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
