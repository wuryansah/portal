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
require_once __DIR__ . '/lib/va_daily_report.php';

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
$noTelegram = va_cli_flag($args, 'no-telegram');
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

/* Satu import pada satu waktu. Lock lepas otomatis saat proses selesai/mati. */
$runStatus = 'ok';
$runLock = va_acquire_lock($workDir . '/va_daily.lock');
if ($runLock === false) {
    $log('run lain sedang berjalan, keluar');
    echo "Run lain sedang berjalan\n";
    exit(0);
}
register_shutdown_function(static function () use (&$runStatus, $log): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $runStatus = 'fatal';
    }
    $log('=== run selesai: ' . $runStatus . ' ===');
});

$statePath = $workDir . '/state.json';
$state = va_state_read($statePath);
$auditUserId = (int) $config['audit_user_id'];
$recipients = array_values(array_filter((array) ($config['recipients'] ?? [])));
$runGroups = [];
$runNotes = [];
$today = date('Y-m-d');

/**
 * Laporkan kegagalan keras lewat email + Telegram (sekali per pesan identik per hari).
 */
$sendFatal = static function (string $message) use (&$state, $statePath, $recipients, $noEmail, $noTelegram, $log, $today, $dryRun): void {
    $log('FATAL: ' . $message);
    if ($noEmail) {
        $log('notifikasi fatal dilewati (--no-email)');
        return;
    }
    $sig = sha1((string) preg_replace('/\s+/', ' ', $message));
    if (($state['lastFatalSignature'] ?? null) === $sig && ($state['lastFatalDate'] ?? null) === $today) {
        $log('alert fatal identik sudah dikirim hari ini, tidak diulang');
        return;
    }
    $report = va_build_report(['date' => $today, 'dryRun' => $dryRun, 'groups' => [], 'fatal' => $message]);
    $results = va_send_report($report, $recipients);
    $sent = false;
    foreach ($results as $to => $result) {
        if (!empty($result['ok'])) {
            $sent = true;
            $log("alert email terkirim ke $to");
        } else {
            $log("EMAIL GAGAL ke $to: " . ($result['error'] ?? 'unknown'));
        }
    }
    if ($noTelegram) {
        $log('alert telegram dilewati (--no-telegram)');
    } else {
        $tg = va_send_report_telegram($report);
        if (!empty($tg['ok'])) {
            $sent = true;
            $log('alert telegram terkirim');
        } elseif (($tg['error'] ?? '') === 'telegram not configured') {
            $log('alert telegram dilewati: token/chat id kosong');
        } else {
            $log('TELEGRAM GAGAL: ' . ($tg['error'] ?? 'unknown'));
        }
    }
    if ($sent) {
        $state['lastFatalSignature'] = $sig;
        $state['lastFatalDate'] = $today;
        va_state_write($statePath, $state);
    }
};

$log('=== run ===' . ($dryRun ? ' (dry-run)' : '') . ($noEmail ? ' (no-email)' : ''));

if (!va_is_run_day((int) date('w'))) {
    $log('bukan hari kerja (Minggu), keluar tanpa memproses');
    $runStatus = 'not-workday';
    echo "Bukan hari kerja\n";
    exit(0);
}

if (!is_dir($baseDir)) {
    $sendFatal("Share tidak bisa diakses: $baseDir");
    $runStatus = 'fatal';
    fwrite(STDERR, "Share tidak bisa diakses: $baseDir\n");
    exit(1);
}

try {
    $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mall_portal', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    $sendFatal('MySQL tidak bisa dihubungi: ' . $e->getMessage());
    $runStatus = 'fatal';
    fwrite(STDERR, "MySQL tidak bisa dihubungi\n");
    exit(1);
}

$periodCounts = [];
foreach ($db->query("SELECT billing_period, COUNT(*) AS n FROM billing GROUP BY billing_period") as $row) {
    $periodCounts[(string) $row['billing_period']] = (int) $row['n'];
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
    $runNotes[] = 'tidak ada file bank baru pada run ini';
    echo "Tidak ada file baru\n";
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
    $result = va_run_process($cmd);
    foreach (array_slice(array_filter(explode("\n", trim($result['stdout']))), -3) as $line) {
        $log('  preview> ' . $line);
    }
    if ($result['code'] !== 0) {
        $log('preview GAGAL (exit ' . $result['code'] . '): ' . trim($result['stderr']));
        $runNotes[] = "preview gagal: $label";
        echo "PREVIEW GAGAL $label\n";
        continue;
    }
    if (trim($result['stderr']) !== '') {
        $log('  preview stderr> ' . trim($result['stderr']));
    }

    $rows = va_preview_row_count($xlsPath);
    $fileCount = count($group['files']);
    if ($rows === 0) {
        $log("preview $label: 0 baris (tetap dilaporkan sebagai tanpa transaksi)");
        $runNotes[] = "preview 0 baris: $label";
        continue;
    }
    $log("preview $label: $rows baris, $fileCount file, xls=$xlsPath");
    echo "PREVIEW $label: $rows baris ($fileCount file)\n";

    if ($dryRun) {
        $log("dry-run: preview saja untuk $label");
        continue;
    }
    if ($period === null) {
        continue; // sudah dilaporkan sebagai TANPA PERIODE di atas
    }

    $allPreview = va_read_preview_rows($xlsPath);

    /* Periode belum ada di tabel billing: jangan sentuh DB, jangan tandai selesai.
       Tetap hasilkan CSV review supaya admin melihat baris yang menunggu. */
    if (empty($periodCounts[$period])) {
        echo "TANPA PERIODE $label ($period tidak ada di billing)\n";
        $log("TANPA PERIODE $label: $period tidak ada di billing, " . count($allPreview) . ' baris menunggu');
        $localSkips = [];
        foreach ($allPreview as $row) {
            $wouldBook = $row['matched'] === 'YES' && $row['unit_number'] !== '' && $row['unit_number'] !== '???';
            $row['reason'] = $wouldBook ? 'no billing' : 'unmatched';
            $localSkips[] = $row;
        }
        if ($localSkips !== []) {
            $archive = sprintf('%s/%s_%s.csv', $dirs['skips'], date('Y-m-d_Hi'), va_period_key($period));
            va_write_skip_csv($archive, $localSkips);
            $log('arsip skip: ' . $archive);
        }
        $runGroups[] = [
            'folder' => basename($group['folder']),
            'period' => $period,
            'periodRows' => 0,
            'files' => $group['files'],
            'booked' => [],
            'skips' => $localSkips,
        ];
        continue;
    }

    $rootCsv = va_skip_csv_path($period);
    @unlink($rootCsv); // jangan pernah membaca CSV basi dari run sebelumnya

    $record = va_run_process(va_build_record_command(PHP_BINARY, $xlsPath, $period, $group['files'], $auditUserId));
    $recordLines = array_filter(explode("\n", trim($record['stdout'])));
    foreach (array_slice($recordLines, 0, 40) as $line) {
        $log('  record> ' . $line);
    }
    if (count($recordLines) > 40) {
        $log('  record> ... ' . (count($recordLines) - 40) . ' baris lainnya');
    }
    if ($record['code'] !== 0) {
        $log('record GAGAL (exit ' . $record['code'] . '): ' . trim($record['stderr']));
        echo "BOOKING GAGAL $label\n";
        continue; // state tidak diubah, run berikutnya mencoba lagi
    }
    if (trim($record['stderr']) !== '') {
        $log('  record stderr> ' . trim($record['stderr']));
    }

    $parsed = va_parse_record_output($record['stdout']);
    $skipRows = va_read_skip_csv($rootCsv);
    $captureError = va_skip_capture_error((int) $parsed['skipped'], $skipRows);
    if ($captureError !== null) {
        /* Jangan pernah mengklaim sukses bila skip tidak bisa dilaporkan. */
        $sendFatal("[$label] $captureError ($rootCsv)");
        echo "BOOKING TIDAK DILAPORKAN $label — $captureError\n";
        continue; // state tidak diubah, run berikutnya melaporkan ulang
    }
    if ($skipRows !== []) {
        $archive = sprintf('%s/%s_%s.csv', $dirs['skips'], date('Y-m-d_Hi'), va_period_key($period));
        if (@copy($rootCsv, $archive)) {
            $log('arsip skip: ' . $archive);
        } else {
            $log('GAGAL menyalin arsip skip: ' . $rootCsv);
        }
    }

    $previewRows = array_values(array_filter($allPreview, static fn(array $r): bool => $r['matched'] === 'YES'));
    $bookedRows = va_diff_rows($previewRows, $skipRows);
    $bookedTotal = 0.0;
    foreach ($bookedRows as $row) {
        $bookedTotal += va_amount_value($row['amount']);
    }
    if (count($bookedRows) !== $parsed['booked']) {
        $log('peringatan: diff booked (' . count($bookedRows) . ') != laporan record (' . $parsed['booked'] . ')');
    }
    if ($bookedRows !== []) {
        echo 'DIBUKUKAN ' . count($bookedRows) . ' baris (' . va_money($bookedTotal) . ")\n";
        $log("dibukukan $label: " . count($bookedRows) . ' baris ' . va_money($bookedTotal));
    } else {
        $log("tidak ada baris baru dibukukan untuk $label (semua dedup/skip)");
    }

    $runGroups[] = [
        'folder' => basename($group['folder']),
        'period' => $period,
        'periodRows' => (int) $periodCounts[$period],
        'files' => $group['files'],
        'booked' => array_map(static fn(array $row): array => [
            'unit' => (string) ($row['unit_number'] ?? ''),
            'reference' => 'VA-' . (string) ($row['va_number'] ?? ''),
            'amount' => va_amount_value($row['amount'] ?? 0),
            'txn_date' => (string) ($row['txn_date'] ?? ''),
            'source_file' => (string) ($row['source_file'] ?? ''),
        ], $bookedRows),
        'skips' => $skipRows,
    ];

    /* Tandai file selesai hanya setelah record sukses dan periode benar-benar ada. */
    $sources = [];
    foreach ($files as $file) $sources[$file['name']] = $file;
    foreach ($group['files'] as $name) {
        $state['files'][$name] = [
            'mtime' => (int) ($sources[$name]['mtime'] ?? 0),
            'size' => (int) ($sources[$name]['size'] ?? 0),
            'period' => $period,
            'booked' => (int) ($parsed['perFile'][$name] ?? 0),
            'at' => date('c'),
        ];
    }
    $state['lastRun'] = date('c');
    va_state_write($statePath, $state);
}

if ($dryRun) {
    $log('dry-run: berhenti setelah preview, state tidak diubah');
    $runStatus = 'dry-run';
    exit(0);
}

/* --- Email + Telegram ----------------------------------------------------
   Laporan dikirim di SETIAP slot (Senin-Sabtu), termasuk saat tidak ada file
   bank baru / tidak ada transaksi, supaya admin tetap punya bukti run jalan.
   Bila tidak ada hasil, alasannya masuk ke bagian "Catatan". */

$allSkips = [];
foreach ($runGroups as $groupResult) {
    foreach ($groupResult['skips'] as $skip) $allSkips[] = $skip;
}
if ($runGroups === [] && $runNotes === []) {
    $runNotes[] = 'tidak ada transaksi baru pada run ini';
}
$skipSignature = va_skip_signature($allSkips);

if ($noEmail) {
    $log('notifikasi dilewati (--no-email)');
    $runStatus = 'no-email';
    exit(0);
}
if ($recipients === [] && $noTelegram) {
    $log('notifikasi dilewati: recipients kosong di va_daily.config.php dan --no-telegram');
    $runStatus = 'no-recipients';
    exit(0);
}

$report = va_build_report([
    'date' => $today,
    'dryRun' => $dryRun,
    'groups' => $runGroups,
    'fatal' => null,
    'notes' => $runNotes,
]);
if ($runNotes !== []) {
    $log('catatan run: ' . implode('; ', $runNotes));
}
$results = va_send_report($report, $recipients);
$sent = false;
foreach ($results as $to => $result) {
    if (!empty($result['ok'])) {
        $sent = true;
        $log("email terkirim ke $to: " . $report['subject']);
    } else {
        $log("EMAIL GAGAL ke $to: " . ($result['error'] ?? 'unknown'));
        fwrite(STDERR, "Email gagal ke $to\n");
    }
}

/* Telegram: kanal kedua, aturan kirim sama dengan email. Gagal di sini tidak
   boleh menggagalkan booking/email — hanya dicatat di log. */
if ($noTelegram) {
    $log('telegram dilewati (--no-telegram)');
} else {
    $tg = va_send_report_telegram($report);
    if (!empty($tg['ok'])) {
        $log('telegram terkirim');
        $sent = true;
    } elseif (($tg['error'] ?? '') === 'telegram not configured') {
        $log('telegram dilewati: token/chat id kosong');
    } else {
        $log('TELEGRAM GAGAL: ' . ($tg['error'] ?? 'unknown'));
        fwrite(STDERR, "Telegram gagal: " . ($tg['error'] ?? 'unknown') . "\n");
    }
}

if ($sent && !$dryRun) {
    $state['lastSkipSignature'] = $skipSignature;
    $state['lastSkipDate'] = $today;
    $state['lastRun'] = date('c');
    va_state_write($statePath, $state);
}
if (!$sent) {
    /* Email dan Telegram dua-duanya gagal: jangan tutup slot, biar watchdog
       memperingatkan admin pada pemeriksaan berikutnya. */
    $log('notifikasi GAGAL di semua kanal (email + telegram)');
    $runStatus = 'notify-failed';
}
if ($sent) {
    echo "Email terkirim: {$report['subject']}\n";
}
if ($runStatus !== 'notify-failed') $runStatus = 'done';
