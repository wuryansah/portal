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

$logFile = glob($work . '/logs/*.log')[0] ?? '';
$logText = $logFile !== '' ? (string) file_get_contents($logFile) : '';
cek('dry-run menulis penanda selesai', strpos($logText, '=== run selesai: dry-run ===') !== false, true);

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

/* --- Task 3: booking + state --------------------------------------------- */
$statePath = $work . '/state.json';

/* periode palsu -> semua baris skip "no billing", tidak ada booking, file tidak ditandai selesai */
$cmd2 = [$php, $root . '/va_daily.php', '--base-dir', $fixture, '--work-dir', $work,
         '--no-email', '--since-days', '30', '--period', '__test_period__'];
$proc = proc_open($cmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out2 = stream_get_contents($pipes[1]);
$err2 = stream_get_contents($pipes[2]);
proc_close($proc);
cek('periode tanpa billing tidak membukukan', strpos($out2, 'DIBUKUKAN') === false, true);
cek('periode tanpa billing melaporkan skip', strpos($out2, 'TANPA PERIODE') !== false, true);
cek('booked 0 dilaporkan sebagai skip', strpos($out2, '3 baris') !== false, true);
$state = va_state_read($statePath);
cek('file tidak ditandai selesai tanpa periode', isset($state['files']['Mall 21 Oktober 2026.txt']), false);
cek('arsip skip ditulis', glob($work . '/skips/*.csv') ? true : false, true);
cek('tidak ada error di stderr', trim($err2), '');
cek('--no-email tidak mengirim email', strpos($out2, 'Email terkirim') === false, true);
cek('--no-email tidak mencatat dedupe email', va_state_read($statePath)['lastSkipSignature'], null);
cek('run no-email menulis penanda selesai', strpos((string) file_get_contents($logFile), '=== run selesai: no-email ===') !== false, true);
cek('--no-email juga melewati telegram', strpos((string) file_get_contents($logFile), 'notifikasi dilewati (--no-email)') !== false, true);
cek('--no-email tidak mencoba telegram', strpos((string) file_get_contents($logFile), 'telegram terkirim') === false, true);

/* --- Setiap slot tetap melapor walau tidak ada file/transaksi baru -------- */
clearstatcache();
$stateLengkap = va_state_read($statePath);
foreach (glob($fixture . '/*.txt') ?: [] as $pathFile) {
    $stateLengkap['files'][basename($pathFile)] = [
        'mtime' => (int) filemtime($pathFile), 'size' => (int) filesize($pathFile),
        'period' => 'Oct-26', 'booked' => 0, 'at' => date('c'),
    ];
}
va_state_write($statePath, $stateLengkap);
$logSebelum = (string) file_get_contents($logFile);

/* SMTP & Telegram sengaja dibuat gagal: test tidak boleh mengirim pesan nyata. */
$envUji = getenv();
$envUji['SMTP_HOST'] = '127.0.0.1';
$envUji['SMTP_PORT'] = '9';
$envUji['SMTP_USER'] = '';
$envUji['SMTP_PASS'] = '';
$envUji['SMTP_ENC'] = 'none';
$envUji['MAIL_FROM'] = 'noreply@example.test';
$envUji['TELEGRAM_BOT_TOKEN'] = '1:token-palsu';
$envUji['TELEGRAM_CHAT_ID'] = '1';
$cmd3 = [$php, $root . '/va_daily.php', '--base-dir', $fixture, '--work-dir', $work, '--since-days', '30'];
$proc = proc_open($cmd3, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envUji);
$out3 = stream_get_contents($pipes[1]);
$err3 = stream_get_contents($pipes[2]);
$code3 = proc_close($proc);
$logBaru = substr((string) file_get_contents($logFile), strlen($logSebelum));

cek('run tanpa file baru tetap exit 0', $code3, 0);
cek('run tanpa file baru melaporkan alasannya', strpos($logBaru, 'tidak ada file bank baru') !== false, true);
cek('run tanpa file baru tetap mencoba email', strpos($logBaru, 'EMAIL GAGAL') !== false, true);
cek('run tanpa file baru tetap mencoba telegram', strpos($logBaru, 'TELEGRAM GAGAL') !== false, true);
cek('semua kanal gagal menandai run notify-failed', strpos($logBaru, '=== run selesai: notify-failed ===') !== false, true);
cek('semua kanal gagal dicatat eksplisit', strpos($logBaru, 'notifikasi GAGAL di semua kanal') !== false, true);

/* Lock: proses lain harus gagal mengambil lock yang sama. */
$lockPath = $work . '/va_daily.lock';
$lock = va_acquire_lock($lockPath);
cek('lock pertama didapat', is_resource($lock), true);
$lockProbe = [$php, '-r', 'require $argv[1]; var_export(va_acquire_lock($argv[2]) === false);',
              $root . '/lib/va_daily_lib.php', $lockPath];
$probe = proc_open($lockProbe, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
$probeOut = trim(stream_get_contents($pp[1]));
$probeErr = trim(stream_get_contents($pp[2]));
cek('proses kedua melihat lock sibuk', [$probeOut, $probeErr], ['true', '']);
proc_close($probe);
va_release_lock($lock);

/* state atomik bolak-balik */
va_state_write($statePath, ['files' => ['a.txt' => ['mtime' => 1, 'size' => 2]], 'lastSkipSignature' => null, 'lastSkipDate' => null, 'lastRun' => null]);
cek('state bisa dibaca kembali', va_state_read($statePath)['files']['a.txt']['size'], 2);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
