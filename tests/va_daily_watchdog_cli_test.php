<?php
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
$work = sys_get_temp_dir() . '/va_wd_test_' . getmypid();
@mkdir($work . '/logs', 0777, true);

/* Tanggal tetap yang pasti hari kerja, relatif terhadap jam nyata agar umur file konsisten. */
$nowDate = date('Y-m-d');
if (date('w') === '0') $nowDate = date('Y-m-d', strtotime($nowDate . ' -1 day')); // Minggu -> Sabtu
$now = $nowDate . ' 10:00:00';
$staleMtime = strtotime($nowDate . ' 07:00:00'); // 3 jam sebelum $now

$baseEmpty = $work . '/base_empty';
@mkdir($baseEmpty, 0777, true);
$logPath = $work . '/logs/' . $nowDate . '.log';

$run = static function (array $extra) use ($php, $root, $work): array {
    $cmd = array_merge([$php, $root . '/va_daily_watchdog.php', '--work-dir', $work,
        '--now', $GLOBALS['now'], '--dry-run', '--no-notify'], $extra);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
};

/* 1. Run terbuka tanpa penanda selesai -> masalah, exit 1. */
file_put_contents($logPath, "[$nowDate 09:31:09] === run ===\n[$nowDate 09:31:24] preview: x\n");
[$code, $out] = $run(['--base-dir', $baseEmpty]);
cek('slot belum selesai terdeteksi', $code, 1);
cek('output menyebut slot 09:30', strpos($out, '09:30') !== false, true);
cek('output menyebut belum selesai', stripos($out, 'belum selesai') !== false, true);
cek('dry-run tidak menulis watchdog.json', is_file($work . '/watchdog.json'), false);

/* 2. Hari normal -> exit 0, tanpa masalah. */
file_put_contents($logPath, "[$nowDate 09:31:09] === run ===\n[$nowDate 09:31:30] === run selesai: done ===\n");
[$code, $out] = $run(['--base-dir', $baseEmpty]);
cek('hari normal exit 0', $code, 0);
cek('hari normal tanpa masalah', stripos($out, 'tidak ada masalah') !== false, true);

/* 3. File tertinggal -> dilaporkan. */
$basePending = $work . '/base_pending';
@mkdir($basePending, 0777, true);
foreach (glob(__DIR__ . '/fixtures/va/*.txt') as $src) {
    $dst = $basePending . '/' . basename($src);
    copy($src, $dst);
    touch($dst, $staleMtime);
}
[$code, $out] = $run(['--base-dir', $basePending, '--no-catch-up']);
cek('file pending exit 1', $code, 1);
cek('output menyebut file pending', strpos($out, 'Mall 21 Oktober 2026.txt') !== false, true);

/* 4. Catch-up dijalankan; child memakai --no-email sehingga tidak ada email dari child. */
$baseCatch = $work . '/base_catch';
@mkdir($baseCatch, 0777, true);
foreach (glob(__DIR__ . '/fixtures/va/*.txt') as $src) {
    $dst = $baseCatch . '/' . basename($src);
    copy($src, $dst);
    touch($dst, $staleMtime);
}
file_put_contents($logPath, "[$nowDate 09:31:09] === run ===\n[$nowDate 09:31:24] preview: x\n");
$cmd = [$php, $root . '/va_daily_watchdog.php', '--base-dir', $baseCatch, '--work-dir', $work,
        '--now', $now, '--no-notify', '--period', '__test_period__'];
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); $code = proc_close($p);
$runsInLogs = 0;
foreach (glob($work . '/logs/*.log') as $f) $runsInLogs += substr_count((string) file_get_contents($f), '=== run ===');
cek('catch-up dijalankan', stripos($out, 'catch-up') !== false, true);
cek('catch-up menulis run baru di log', $runsInLogs >= 2, true);

/* 5. Catch-up gagal (share hilang) -> exit 1 dan dilaporkan. */
file_put_contents($logPath, "[$nowDate 09:31:09] === run ===\n[$nowDate 09:31:24] preview: x\n");
$cmd = [$php, $root . '/va_daily_watchdog.php', '--base-dir', $work . '/tidak-ada', '--work-dir', $work,
        '--now', $now, '--no-notify', '--period', '__test_period__'];
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); $code = proc_close($p);
cek('catch-up gagal exit 1', $code, 1);
cek('output menyebut catch-up gagal', stripos($out, 'catch-up') !== false && stripos($out, 'gagal') !== false, true);

/* 6. Hari Minggu -> keluar tanpa memeriksa. */
$cmd = [$php, $root . '/va_daily_watchdog.php', '--base-dir', $baseEmpty, '--work-dir', $work,
        '--now', '2026-10-25 10:00:00', '--dry-run', '--no-notify'];
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); $code = proc_close($p);
cek('minggu exit 0', $code, 0);
cek('minggu tanpa pemeriksaan', stripos($out, 'hari kerja') !== false, true);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
