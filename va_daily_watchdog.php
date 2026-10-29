<?php
/**
 * Watchdog import VA harian.
 *
 * Dijadwalkan 20 menit setelah setiap run utama. Memeriksa penanda
 * `=== run selesai ===` di log harian dan file share yang belum tercatat di
 * state.json. Bila ada masalah, menjalankan ulang `va_daily.php` sekali
 * (auto catch-up) lalu mengirim email + Telegram.
 *
 * Pemakaian:
 *   php va_daily_watchdog.php
 *   php va_daily_watchdog.php --dry-run --no-notify
 *   php va_daily_watchdog.php --no-catch-up        # hanya periksa & lapor
 *   php va_daily_watchdog.php --force              # abaikan dedupe notifikasi
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/lib/va_daily_lib.php';
require_once __DIR__ . '/lib/va_daily_watchdog_lib.php';
require_once __DIR__ . '/lib/va_daily_report.php';
require_once __DIR__ . '/lib/notifier.php';

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

$args = array_slice($argv, 1);
$config = require __DIR__ . '/va_daily.config.php';

$dryRun = va_cli_flag($args, 'dry-run');
$noNotify = va_cli_flag($args, 'no-notify');
$noCatchUp = va_cli_flag($args, 'no-catch-up');
$force = va_cli_flag($args, 'force');
$forcedPeriod = va_cli_arg($args, 'period');
$baseDir = (string) va_cli_arg($args, 'base-dir', $config['base_dir']);
$workDir = rtrim((string) va_cli_arg($args, 'work-dir', $config['work_dir']), "\\/");
$nowStr = (string) va_cli_arg($args, 'now', date('Y-m-d H:i:s'));
try {
    $now = new DateTimeImmutable($nowStr);
} catch (Throwable $e) {
    fwrite(STDERR, "Nilai --now tidak valid: $nowStr\n");
    exit(2);
}
$nowDate = $now->format('Y-m-d');
$today = $nowDate;

$dirs = [
    'preview' => $workDir . '/preview',
    'logs' => $workDir . '/logs',
    'skips' => $workDir . '/skips',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
}
$logPath = $dirs['logs'] . '/watchdog-' . $nowDate . '.log';
$log = static function (string $message) use ($logPath, $now): void {
    @file_put_contents($logPath, '[' . $now->format('Y-m-d H:i:s') . '] [watchdog] ' . $message . "\n", FILE_APPEND);
};

/* Satu watchdog pada satu waktu. */
$lock = va_acquire_lock($workDir . '/watchdog.lock');
if ($lock === false) {
    $log('watchdog lain sedang berjalan, keluar');
    echo "Watchdog lain sedang berjalan\n";
    exit(0);
}

$log('=== watchdog mulai (now=' . $now->format('Y-m-d H:i:s') . ($dryRun ? ', dry-run' : '') . ') ===');

if (!va_is_run_day((int) $now->format('w'))) {
    $log('bukan hari kerja, keluar');
    echo "Bukan hari kerja, watchdog tidak memeriksa\n";
    exit(0);
}

$statePath = $workDir . '/state.json';
$watchdogPath = $workDir . '/watchdog.json';
$runLogPath = $dirs['logs'] . '/' . $nowDate . '.log';
$lookbackDays = (int) ($config['lookback_days'] ?? 10);
$graceMinutes = (int) ($config['watchdog_grace_minutes'] ?? 20);
$staleHours = (int) ($config['stale_file_hours'] ?? 2);
$autoCatchUp = !empty($config['auto_catch_up']);
$recipients = array_values(array_filter((array) ($config['recipients'] ?? [])));

$scheduleTimes = array_values((array) ($config['schedule_times'] ?? ['09:30', '13:00', '17:00']));

/**
 * Hitung slot belum selesai dan file tertinggal untuk kondisi saat ini.
 *
 * @return array{slots:array,files:array,error:?string}
 */
$inspect = static function () use ($scheduleTimes, $now, $graceMinutes, $runLogPath, $statePath, $baseDir, $lookbackDays, $staleHours, $log): array {
    $logText = is_file($runLogPath) ? (string) @file_get_contents($runLogPath) : '';
    $due = va_watchdog_due_slots($scheduleTimes, $now, true, $graceMinutes);
    $runs = va_watchdog_parse_runs($logText);
    $slots = va_watchdog_unfinished_slots($due, $runs);

    $error = null;
    $files = [];
    if (!is_dir($baseDir)) {
        $error = 'Share tidak bisa diakses: ' . $baseDir;
        $log($error);
    } else {
        $nowYm = ['year' => (int) $now->format('Y'), 'month' => (int) $now->format('n')];
        $prevYm = va_shift_month($nowYm['year'], $nowYm['month'], -1);
        $folders = [$baseDir];
        foreach ([$nowYm, $prevYm] as $ym) {
            $candidate = rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR . va_folder_name($ym['month']);
            if (is_dir($candidate)) $folders[] = $candidate;
        }
        $tomorrow = strtotime($now->format('Y-m-d') . ' +1 day') ?: time();
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
                    'name' => $name,
                    'mtime' => (int) @filemtime($path),
                    'size' => (int) @filesize($path),
                ];
            }
        }
    }

    $state = va_state_read($statePath);
    $nowTs = $now->getTimestamp();
    $pending = va_watchdog_pending_files($files, $state, $nowTs - $lookbackDays * 86400, $nowTs - $staleHours * 3600);

    return ['slots' => $slots, 'files' => $pending, 'error' => $error, 'runs' => $runs];
};

$inspectResult = $inspect();
$slots = $inspectResult['slots'];
$files = $inspectResult['files'];
$error = $inspectResult['error'];
$runs = (array) ($inspectResult['runs'] ?? []);
$slotReasons = va_watchdog_slot_failure_reasons($slots, $runs);

foreach ($slots as $slot) {
    $reason = (string) ($slotReasons[(string) $slot['time']] ?? '');
    $log('slot ' . $slot['time'] . ' belum selesai' . ($reason !== '' ? ' (' . $reason . ')' : ''));
}
foreach ($files as $file) $log('file menunggu: ' . $file['name']);

/* --- Auto catch-up -------------------------------------------------------- */
$catchup = ['ran' => false, 'ok' => false, 'summary' => ''];
$importBusy = false;
$hasProblems = $slots !== [] || $files !== [];
/* Catch-up tidak bisa mengirim ulang laporan; slot yang hanya gagal kirim
   notifikasi tetap dilaporkan tanpa menjalankan import ulang. */
$catchupSlots = array_values(array_filter(
    $slots,
    static fn(array $slot): bool => (string) ($slotReasons[(string) ($slot['time'] ?? '')] ?? '') !== 'notify-failed'
));
$needCatchup = $files !== [] || $catchupSlots !== [];

if ($hasProblems && $autoCatchUp && !$noCatchUp && !$dryRun && $needCatchup) {
    $probe = va_acquire_lock($workDir . '/va_daily.lock');
    if ($probe === false) {
        $importBusy = true;
        $log('import utama sedang berjalan, catch-up dilewati');
    } else {
        va_release_lock($probe);

        $cmd = [PHP_BINARY, __DIR__ . '/va_daily.php', '--work-dir', $workDir, '--base-dir', $baseDir, '--no-email'];
        if ($forcedPeriod !== null && $forcedPeriod !== '') $cmd[] = '--period';
        if ($forcedPeriod !== null && $forcedPeriod !== '') $cmd[] = $forcedPeriod;

        $log('catch-up: ' . implode(' ', $cmd));
        echo "Catch-up dijalankan...\n";
        $result = va_run_process($cmd);

        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $result['stdout'])), static fn($l) => $l !== ''));
        $summary = '';
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/DIBUKUKAN|TANPA PERIODE|Tidak ada file baru|Tidak ada perubahan|PREVIEW|BOOKING GAGAL|PREVIEW GAGAL/i', $line)) {
                $summary = $line;
                break;
            }
        }
        if ($summary === '') {
            $stderrLines = array_values(array_filter(array_map('trim', explode("\n", (string) $result['stderr'])), static fn($l) => $l !== ''));
            $summary = $stderrLines[0] ?? ($lines === [] ? '' : (string) end($lines));
        }

        $catchup = ['ran' => true, 'ok' => $result['code'] === 0, 'summary' => $summary];
        $log('catch-up selesai: exit ' . $result['code'] . ($summary !== '' ? ' — ' . $summary : ''));

        $inspectResult = $inspect();
        $slots = $inspectResult['slots'];
        $files = $inspectResult['files'];
        $error = $inspectResult['error'];
        $runs = (array) ($inspectResult['runs'] ?? []);
        $slotReasons = va_watchdog_slot_failure_reasons($slots, $runs);
        $hasProblems = $slots !== [] || $files !== [];
    }
}

/* --- Susun & kirim notifikasi -------------------------------------------- */
$report = va_watchdog_build_report([
    'date' => $nowDate,
    'slots' => $slots,
    'files' => $files,
    'slot_reasons' => $slotReasons,
    'catchup' => $catchup,
    'error' => $error,
]);
$signature = va_watchdog_signature(['slots' => $slots, 'files' => $files]);
$wdState = va_state_read($watchdogPath);
$shouldNotify = ($catchup['ran'] || $hasProblems || $error !== null)
    && !$importBusy
    && va_watchdog_should_notify(
        $wdState['lastSignature'] ?? null,
        $wdState['lastDate'] ?? null,
        $signature,
        $today,
        $force
    );

$notifyChannels = [];
if ($shouldNotify && ($dryRun || $noNotify)) {
    $log('notifikasi dilewati (' . ($dryRun ? '--dry-run' : '--no-notify') . '): ' . $report['subject']);
} elseif ($shouldNotify) {
    if ($recipients === []) {
        $log('email dilewati: recipients kosong');
    } else {
        foreach (va_send_report($report, $recipients) as $to => $result) {
            if (!empty($result['ok'])) {
                $notifyChannels[] = 'email:' . $to;
                $log("email terkirim ke $to: " . $report['subject']);
            } else {
                $log("EMAIL GAGAL ke $to: " . ($result['error'] ?? 'unknown'));
            }
        }
    }

    $tgCfg = telegramConfig();
    if (telegramEnabled($tgCfg)) {
        $tg = sendTelegram($report['text']);
        if (!empty($tg['ok'])) {
            $notifyChannels[] = 'telegram';
            $log('telegram terkirim');
        } else {
            $log('TELEGRAM GAGAL: ' . ($tg['error'] ?? 'unknown'));
        }
    } else {
        $log('telegram dilewati: token/chat id kosong');
    }

    if ($notifyChannels !== []) {
        va_state_write($watchdogPath, [
            'lastSignature' => $signature,
            'lastDate' => $today,
            'lastNotifiedAt' => $now->format('c'),
            'lastSubject' => $report['subject'],
        ]);
        $log('notifikasi terkirim via ' . implode(', ', $notifyChannels));
    } else {
        $log('tidak ada kanal notifikasi yang berhasil, dedupe tidak disimpan');
    }
} else {
    $log('tidak ada notifikasi: ' . ($importBusy ? 'import sedang berjalan' : 'tidak ada masalah / sudah pernah dikirim'));
}

/* --- Ringkasan ke konsol ------------------------------------------------- */
echo 'Watchdog ' . $now->format('d-m-Y H:i') . "\n";
if ($importBusy) {
    echo "- import utama sedang berjalan, watchdog tidak melakukan catch-up\n";
}
if ($slots !== []) {
    $times = array_map(static fn(array $s): string => (string) $s['time'], $slots);
    echo '- slot belum selesai: ' . implode(', ', $times) . "\n";
}
if ($files !== []) {
    echo '- file menunggu: ' . implode(', ', array_map(static fn(array $f): string => (string) $f['name'], $files)) . "\n";
}
if ($catchup['ran']) {
    echo '- catch-up ' . ($catchup['ok'] ? 'berhasil' : 'gagal')
        . ($catchup['summary'] !== '' ? ': ' . $catchup['summary'] : '') . "\n";
}
if ($error !== null) {
    echo '- error: ' . $error . "\n";
}
if ($slots === [] && $files === [] && !$catchup['ran'] && $error === null) {
    echo "- tidak ada masalah\n";
}
if ($shouldNotify) {
    echo '- notifikasi: ' . $report['subject'] . ($notifyChannels === [] ? ' (tidak terkirim)' : ' (' . implode(', ', $notifyChannels) . ')') . "\n";
}

$log('=== watchdog selesai ===');
$failed = $hasProblems || ($catchup['ran'] && !$catchup['ok']) || $error !== null;
exit($failed ? 1 : 0);
