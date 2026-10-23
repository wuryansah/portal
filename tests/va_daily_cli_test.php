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

$php = PHP_BINARY;
$root = dirname(__DIR__);
$fixture = __DIR__ . '/fixtures/va';
$work = sys_get_temp_dir() . '/va_daily_test_' . getmypid();
@mkdir($work, 0777, true);

$cmd = [$php, $root . '/va_daily.php', '--base-dir', $fixture, '--work-dir', $work,
        '--dry-run', '--no-email', '--since-days', '30'];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
$code = proc_close($proc);

cek('dry-run exit 0', $code, 0);
cek('preview dibuat', glob($work . '/preview/*.xls') ? true : false, true);
cek('log dibuat', glob($work . '/logs/*.log') ? true : false, true);
cek('tidak ada booking saat dry-run', strpos($out, 'DIBUKUKAN') === false, true);
cek('laporan menyebut 3 transaksi', strpos($out, '3 baris') !== false, true);
cek('error kosong', trim($err), '');

/* kandidat: file minggu lalu diabaikan bila di luar lookback */
$old = $work . '/old';
@mkdir($old, 0777, true);
file_put_contents($old . '/Mall 01 Oktober 2026.txt', "x\n");
cek('lookback membatasi file lama',
    va_collect_candidates([['path' => $old . '/Mall 01 Oktober 2026.txt', 'name' => 'Mall 01 Oktober 2026.txt',
        'folder' => $old, 'mtime' => time() - 86400 * 40, 'size' => 2]], [], time())['skippedOld'], 1);

/* file bulan lalu (31 Oktober) yang muncul 1 November tetap periode Oktober */
$c = va_collect_candidates([
    ['path' => $old . '/Mall 31 Oktober 2026.txt', 'name' => 'Mall 31 Oktober 2026.txt',
     'folder' => $old, 'mtime' => time(), 'size' => 2],
], [], time() - 86400 * 2);
cek('bulan diambil dari nama file', [$c['groups'][0]['month'], $c['groups'][0]['year']], [10, 2026]);

/* report tanpa transaksi tidak membuat email "0 dibukukan" */
cek('deteksi file kosong', strpos($out, '0 baris'), false);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
