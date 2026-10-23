<?php
/**
 * Import VA harian: pindai laporan bank di share, preview, lalu (Task 3) booking.
 *
 * Pemakaian:
 *   php va_daily.php                       # sama seperti jadwal
 *   php va_daily.php --dry-run             # preview saja, tidak menulis DB
 *   php va_daily.php --no-email            # tidak mengirim email
 *   php va_daily.php --period Oct-26       # paksa periode billing
 *   php va_daily.php --since-days 3        # batasi lookback file
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/lib/va_daily_lib.php';

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

function va_cli_arg(array $args, string $name, ?string $default = null): ?string {
    foreach ($args as $i => $arg) {
        if ($arg === '--' . $name && isset($args[$i + 1])) return trim((string) $args[$i + 1]);
    }
    return $default;
}

function va_cli_flag(array $args, string $name): bool {
    return in_array('--' . $name, $args, true);
}

/**
 * Jalankan subprocess tanpa risiko deadlock: stdout/stderr ditampung di file.
 *
 * @return array{code:int,stdout:string,stderr:string}
 */
function va_run(array $cmd): array {
    $outFile = tempnam(sys_get_temp_dir(), 'vaout');
    $errFile = tempnam(sys_get_temp_dir(), 'vaerr');
    if ($outFile === false || $errFile === false) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'gagal membuat file sementara'];
    }
    $proc = proc_open($cmd, [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes);
    $code = is_resource($proc) ? (int) proc_close($proc) : -1;
    $stdout = (string) @file_get_contents($outFile);
    $stderr = (string) @file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Jumlah baris transaksi di sheet "All Transactions" pada XLS preview. */
function va_preview_row_count(string $xlsPath): int {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($xlsPath) || !is_file($autoload)) return 0;
    require_once $autoload;
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsPath);
    $sheet = $book->getSheetByName('All Transactions');
    if ($sheet === null) return 0;
    $rows = (int) $sheet->getHighestDataRow() - 1;
    $book->disconnectWorksheets();
    return max(0, $rows);
}

$args = array_slice($argv, 1);
$config = require __DIR__ . '/va_daily.config.php';

$dryRun = va_cli_flag($args, 'dry-run');
$noEmail = va_cli_flag($args, 'no-email');
$forcedPeriod = va_cli_arg($args, 'period');
$sinceDays = (int) (va_cli_arg($args, 'since-days', (string) $config['lookback_days']));
$baseDir = (string) va_cli_arg($args, 'base-dir', $config['base_dir']);
$workDir = rtrim((string) va_cli_arg($args, 'work-dir', $config['work_dir']), "\\/");

$dirs = [
    'preview' => $workDir . '/preview',
    'logs' => $workDir . '/logs',
    'skips' => $workDir . '/skips',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
}
$logPath = $dirs['logs'] . '/' . date('Y-m-d') . '.log';
$log = static function (string $message) use ($logPath): void {
    @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
};

$log('=== run ===' . ($dryRun ? ' (dry-run)' : '') . ($noEmail ? ' (no-email)' : ''));

if (!va_is_run_day((int) date('w'))) {
    $log('bukan hari kerja (Minggu), keluar tanpa memproses');
    echo "Bukan hari kerja\n";
    exit(0);
}

if (!is_dir($baseDir)) {
    $log("share tidak bisa diakses: $baseDir");
    fwrite(STDERR, "Share tidak bisa diakses: $baseDir\n");
    exit(1);
}

try {
    $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mall_portal', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    $log('MySQL tidak bisa dihubungi: ' . $e->getMessage());
    fwrite(STDERR, "MySQL tidak bisa dihubungi\n");
    exit(1);
}

$periodCounts = [];
foreach ($db->query("SELECT billing_period, COUNT(*) AS n FROM billing GROUP BY billing_period") as $row) {
    $periodCounts[(string) $row['billing_period']] = (int) $row['n'];
}

$statePath = $workDir . '/state.json';
$state = [];
if (is_file($statePath)) {
    $decoded = json_decode((string) @file_get_contents($statePath), true);
    if (is_array($decoded)) $state = $decoded;
}

/* Pindai folder induk + subfolder bulan berjalan dan bulan sebelumnya. */
$nowYm = ['year' => (int) date('Y'), 'month' => (int) date('n')];
$prevYm = va_shift_month($nowYm['year'], $nowYm['month'], -1);
$folders = [$baseDir];
foreach ([$nowYm, $prevYm] as $ym) {
    $candidate = rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR . va_folder_name($ym['month']);
    if (is_dir($candidate)) $folders[] = $candidate;
}

$tomorrow = strtotime(date('Y-m-d') . ' +1 day');
$files = [];
foreach (array_values(array_unique($folders)) as $folder) {
    foreach (glob($folder . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $path) {
        $name = basename($path);
        $parsed = va_parse_report_filename($name);
        if ($parsed === null) continue;
        $fileTs = strtotime(sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day']));
        if ($fileTs !== false && $fileTs > $tomorrow) {
            $log("lewati file bertanggal masa depan: $name");
            continue;
        }
        $files[] = [
            'path' => $path,
            'name' => $name,
            'folder' => $folder,
            'mtime' => (int) @filemtime($path),
            'size' => (int) @filesize($path),
        ];
    }
}

$collected = va_collect_candidates($files, $state, time() - $sinceDays * 86400);
if ($collected['skippedOld'] > 0) {
    $log("{$collected['skippedOld']} file di luar lookback {$sinceDays} hari diabaikan");
}

$groups = $collected['groups'];
if ($groups === []) {
    $log('tidak ada file baru');
    echo "Tidak ada file baru\n";
    exit(0);
}

$monthNames = va_month_names();
foreach ($groups as $group) {
    $period = $forcedPeriod;
    if ($period === null || $period === '') {
        $period = va_pick_period($periodCounts, $group['month'], $group['year']);
    }
    $label = sprintf('%s %s %d', basename($group['folder']), $monthNames[$group['month']] ?? '', $group['year']);
    if ($period === null) {
        $log("TANPA PERIODE $label: billing_period belum ada");
        echo "TANPA PERIODE $label (billing_period belum ada)\n";
    }

    $suffix = substr(md5($group['folder'] . '|' . $group['month'] . '|' . $group['year']), 0, 6);
    $xlsPath = sprintf('%s/%s_%s_%s.xls', $dirs['preview'], date('Y-m-d_Hi'), ($monthNames[$group['month']] ?? 'bulan'), $suffix);

    $cmd = va_build_preview_command(
        PHP_BINARY,
        $xlsPath,
        $group['folder'],
        $group['month'],
        $group['year'],
        $group['days'],
        (string) $period
    );
    $log('preview: ' . implode(' ', array_map(static fn($p) => str_contains($p, ' ') ? '"' . $p . '"' : $p, $cmd)));
    $result = va_run($cmd);
    foreach (array_slice(array_filter(explode("\n", trim($result['stdout']))), -3) as $line) {
        $log('  preview> ' . $line);
    }
    if ($result['code'] !== 0) {
        $log('preview GAGAL (exit ' . $result['code'] . '): ' . trim($result['stderr']));
        echo "PREVIEW GAGAL $label\n";
        continue;
    }
    if (trim($result['stderr']) !== '') {
        $log('  preview stderr> ' . trim($result['stderr']));
    }

    $rows = va_preview_row_count($xlsPath);
    $fileCount = count($group['files']);
    if ($rows === 0) {
        $log("preview $label: 0 baris (tidak dilaporkan sebagai hasil)");
        continue;
    }
    $log("preview $label: $rows baris, $fileCount file, xls=$xlsPath");
    echo "PREVIEW $label: $rows baris ($fileCount file)\n";
}

if ($dryRun) {
    $log('dry-run: berhenti setelah preview, state tidak diubah');
}
