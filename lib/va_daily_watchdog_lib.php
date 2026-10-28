<?php
/**
 * Pure helpers for the daily VA watchdog (no DB, no network, no filesystem writes).
 * Deterministic so they can be tested without the bank share.
 */

require_once __DIR__ . '/va_daily_lib.php';

/**
 * Slot jadwal yang sudah lewat masa tenggang (grace) pada hari ini.
 *
 * @param string[] $times contoh ['09:30','13:00','17:00']
 * @return array<int,array{time:string,ts:int}>
 */
function va_watchdog_due_slots(array $times, DateTimeImmutable $now, bool $runDay, int $graceMinutes): array {
    if (!$runDay) return [];
    $out = [];
    foreach ($times as $time) {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $time), $m)) continue;
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) continue;
        $slot = $now->setTime($hour, $minute, 0);
        if ($now->getTimestamp() >= $slot->getTimestamp() + $graceMinutes * 60) {
            $out[] = ['time' => sprintf('%02d:%02d', $hour, $minute), 'ts' => $slot->getTimestamp()];
        }
    }
    usort($out, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
    return $out;
}

/**
 * Baca penanda run dari log harian.
 * `=== run ===` membuka run; `=== run selesai: <status> ===` menutupnya.
 *
 * @return array<int,array{started_at:string,start_ts:int,finished:bool,status:?string,finished_at:?string}>
 */
function va_watchdog_parse_runs(string $logText): array {
    $runs = [];
    $current = null;
    foreach (preg_split('/\R/', $logText) as $line) {
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*(.*)$/', trim($line), $m)) continue;
        $stamp = $m[1];
        $message = trim($m[2]);
        $ts = strtotime($stamp);
        if ($ts === false) continue;

        if (preg_match('/^=== run ===$/', $message)) {
            if ($current !== null) $runs[] = $current;
            $current = [
                'started_at' => $stamp,
                'start_ts' => $ts,
                'finished' => false,
                'status' => null,
                'finished_at' => null,
            ];
            continue;
        }
        if (preg_match('/^=== run selesai:\s*(.*?)\s*===$/', $message, $sm) && $current !== null && !$current['finished']) {
            $current['finished'] = true;
            $current['status'] = $sm[1] !== '' ? $sm[1] : 'done';
            $current['finished_at'] = $stamp;
        }
    }
    if ($current !== null) $runs[] = $current;
    return $runs;
}

/**
 * Slot yang belum ditutup run sukses. Run dengan status `fatal` tidak menutup slot.
 *
 * @param array<int,array{time:string,ts:int}> $slots
 * @param array<int,array{start_ts:int,finished:bool,status:?string}> $runs
 * @return array<int,array{time:string,ts:int}>
 */
function va_watchdog_unfinished_slots(array $slots, array $runs): array {
    $out = [];
    $count = count($slots);
    foreach ($slots as $i => $slot) {
        $start = (int) ($slot['ts'] ?? 0);
        $next = $i + 1 < $count ? (int) ($slots[$i + 1]['ts'] ?? PHP_INT_MAX) : PHP_INT_MAX;
        $covered = false;
        foreach ($runs as $run) {
            $runStart = (int) ($run['start_ts'] ?? 0);
            if ($runStart < $start || $runStart >= $next) continue;
            if (!empty($run['finished']) && (string) ($run['status'] ?? '') !== 'fatal') {
                $covered = true;
                break;
            }
        }
        if (!$covered) $out[] = $slot;
    }
    return $out;
}

/**
 * File laporan yang sudah cukup lama ada di share tetapi belum tercatat selesai.
 *
 * @param array<int,array{name:string,mtime:int,size:int}> $files
 * @param array $state hasil va_state_read()
 * @return array<int,array>
 */
function va_watchdog_pending_files(array $files, array $state, int $sinceTs, int $staleTs): array {
    $known = (array) ($state['files'] ?? []);
    $out = [];
    foreach ($files as $file) {
        $name = (string) ($file['name'] ?? '');
        if ($name === '') continue;
        $mtime = (int) ($file['mtime'] ?? 0);
        if ($mtime < $sinceTs || $mtime > $staleTs) continue;
        $sig = ['mtime' => $mtime, 'size' => (int) ($file['size'] ?? 0)];
        if (!va_needs_processing($known[$name] ?? null, $sig)) continue;
        $out[] = $file;
    }
    usort($out, static fn(array $a, array $b): int => [(int) $a['mtime'], (string) $a['name']] <=> [(int) $b['mtime'], (string) $b['name']]);
    return $out;
}

/** Signature stabil untuk dedupe notifikasi harian. */
function va_watchdog_signature(array $problems): string {
    $slots = [];
    foreach ((array) ($problems['slots'] ?? []) as $slot) {
        $slots[] = (string) (is_array($slot) ? ($slot['time'] ?? '') : $slot);
    }
    $files = [];
    foreach ((array) ($problems['files'] ?? []) as $file) {
        $files[] = (string) (is_array($file) ? ($file['name'] ?? '') : $file);
    }
    sort($slots, SORT_STRING);
    sort($files, SORT_STRING);
    return sha1(json_encode(['slots' => array_values($slots), 'files' => array_values($files)]));
}

/** Apakah notifikasi yang sama sudah dikirim hari ini? */
function va_watchdog_should_notify(?string $lastSig, ?string $lastDate, string $sig, string $today, bool $force): bool {
    if ($force) return true;
    if ($lastDate !== $today) return true;
    return $lastSig !== $sig;
}
