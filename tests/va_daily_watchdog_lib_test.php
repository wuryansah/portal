<?php
require_once __DIR__ . '/../lib/va_daily_watchdog_lib.php';

$total = 0; $gagal = 0;
function cek(string $nama, $dapat, $harap): void {
    global $total, $gagal;
    $total++;
    if ($dapat === $harap) return;
    $gagal++;
    fwrite(STDERR, "GAGAL: $nama\n  harap: " . var_export($harap, true) . "\n  dapat: " . var_export($dapat, true) . "\n");
}

$slots = va_watchdog_due_slots(['09:30', '13:00', '17:00'], new DateTimeImmutable('2026-10-28 09:49:00'), true, 20);
cek('slot 09:30 belum due sebelum grace', count($slots), 0);
$slots = va_watchdog_due_slots(['09:30', '13:00', '17:00'], new DateTimeImmutable('2026-10-28 09:50:00'), true, 20);
cek('slot 09:30 due tepat pada grace', array_column($slots, 'time'), ['09:30']);
cek('minggu tidak ada slot', va_watchdog_due_slots(['09:30'], new DateTimeImmutable('2026-10-25 09:50:00'), false, 20), []);

$log = "[2026-10-28 09:31:09] === run ===\n"
     . "[2026-10-28 09:31:24] preview: x\n"
     . "[2026-10-28 13:00:03] === run selesai: fatal ===\n"
     . "[2026-10-28 13:00:05] === run ===\n"
     . "[2026-10-28 13:00:20] === run selesai: done ===\n";
$runs = va_watchdog_parse_runs($log);
cek('parse dua run', count($runs), 2);
cek('run pertama fatal', [$runs[0]['finished'], $runs[0]['status']], [true, 'fatal']);
cek('run kedua selesai', [$runs[1]['finished'], $runs[1]['status']], [true, 'done']);
cek('run tanpa penanda belum selesai', va_watchdog_parse_runs('[2026-10-28 09:31:09] === run ===')[0]['finished'], false);

$due = [['time' => '09:30', 'ts' => 1000], ['time' => '13:00', 'ts' => 2000]];
$runs = [
    ['start_ts' => 1100, 'finished' => false, 'status' => null],
    ['start_ts' => 2100, 'finished' => true, 'status' => 'done'],
];
cek('slot tanpa run selesai', array_column(va_watchdog_unfinished_slots($due, $runs), 'time'), ['09:30']);
cek('status fatal tidak menutup slot', array_column(va_watchdog_unfinished_slots(
    [['time' => '09:30', 'ts' => 1000]],
    [['start_ts' => 1100, 'finished' => true, 'status' => 'fatal']]
), 'time'), ['09:30']);
cek('run telat menutup slot sebelumnya', va_watchdog_unfinished_slots(
    [['time' => '09:30', 'ts' => 1000]],
    [['start_ts' => 1900, 'finished' => true, 'status' => 'done']]
), []);

$now = time();
$files = [
    ['name' => 'A.txt', 'mtime' => $now - 10800, 'size' => 10],
    ['name' => 'B.txt', 'mtime' => $now - 10800, 'size' => 10],
    ['name' => 'C.txt', 'mtime' => $now - 1800, 'size' => 10],
    ['name' => 'D.txt', 'mtime' => $now - 86400 * 40, 'size' => 10],
];
$state = ['files' => ['B.txt' => ['mtime' => $now - 10800, 'size' => 10]]];
$pending = va_watchdog_pending_files($files, $state, $now - 86400 * 10, $now - 7200);
cek('hanya file lama yang belum diproses', array_column($pending, 'name'), ['A.txt']);

$p1 = ['slots' => [['time' => '13:00'], ['time' => '09:30']], 'files' => [['name' => 'B.txt'], ['name' => 'A.txt']]];
$p2 = ['slots' => [['time' => '09:30'], ['time' => '13:00']], 'files' => [['name' => 'A.txt'], ['name' => 'B.txt']]];
cek('signature stabil terhadap urutan', va_watchdog_signature($p1), va_watchdog_signature($p2));
cek('signature berbeda untuk masalah berbeda', va_watchdog_signature($p1) === va_watchdog_signature(['slots' => [], 'files' => []]), false);
cek('notify saat force', va_watchdog_should_notify('x', '2026-10-28', 'x', '2026-10-28', true), true);
cek('tidak notify signature sama di hari sama', va_watchdog_should_notify('x', '2026-10-28', 'x', '2026-10-28', false), false);
cek('notify hari baru', va_watchdog_should_notify('x', '2026-10-27', 'x', '2026-10-28', false), true);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
