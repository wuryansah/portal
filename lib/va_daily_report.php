<?php
/**
 * Penyusun email laporan import VA harian (HTML, tanpa attachment).
 * Dipisah dari va_daily.php agar bisa diuji tanpa mengirim email.
 */

require_once __DIR__ . '/va_daily_lib.php';

/** Saran tindakan untuk satu alasan skip. */
function va_skip_advice(string $reason, string $period): string {
    switch (strtolower(trim($reason))) {
        case 'no billing':
            return 'buat billing periode ' . ($period !== '' ? $period : 'tsb') . ' lalu jalankan ulang';
        case 'unmatched':
            return 'perbaiki mapping nama unit di laporan bank / data user';
        case 'no user':
            return 'tambah user untuk unit tersebut';
        case 'duplicate':
        case 'duplicate amount':
            return 'normal (sudah pernah dibukukan) — cek bila terasa janggal';
        case 'zero amount':
            return 'cek nominal di file bank';
        default:
            return 'periksa manual';
    }
}

function va_report_styles(): string {
    return <<<CSS
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #1f2937; }
  h2 { font-size: 18px; margin: 0 0 6px; }
  h3 { font-size: 14px; margin: 18px 0 6px; }
  table { border-collapse: collapse; width: 100%; margin: 6px 0 12px; }
  th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
  th { background: #f3f4f6; }
  td.num { text-align: right; white-space: nowrap; }
  .muted { color: #6b7280; }
  .warn { background: #fef3c7; border: 1px solid #f59e0b; padding: 8px 10px; margin: 10px 0; }
  .fail { background: #fee2e2; border: 1px solid #dc2626; padding: 8px 10px; margin: 10px 0; }
</style>
CSS;
}

/**
 * @param array $run ['date','dryRun','groups','fatal','notes']
 *   groups[]: ['folder','period','periodRows','files','booked','skips']
 *   booked[]: ['unit','reference','amount','txn_date','source_file']
 *   skips[]:  ['unit_number','va_number','amount','txn_date','txn_time','source_file','reason']
 *   notes[]:  alasan run tanpa hasil (mis. 'tidak ada file bank baru pada run ini')
 * @return array{subject:string,html:string,text:string}
 */
function va_build_report(array $run): array {
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $date = (string) ($run['date'] ?? date('Y-m-d'));
    $ts = strtotime($date) ?: time();
    $dmy = date('d-m-Y', $ts);
    $groups = (array) ($run['groups'] ?? []);
    $fatal = $run['fatal'] ?? null;
    $dryRun = !empty($run['dryRun']);
    $notes = [];
    foreach ((array) ($run['notes'] ?? []) as $note) {
        $note = trim((string) $note);
        if ($note !== '') $notes[] = $note;
    }

    $booked = [];
    $skips = [];
    $periods = [];
    $files = [];
    foreach ($groups as $group) {
        $period = (string) ($group['period'] ?? '');
        $periods[$period] = [
            'period' => $period,
            'periodRows' => (int) ($group['periodRows'] ?? 0),
            'folder' => (string) ($group['folder'] ?? ''),
            'files' => (array) ($group['files'] ?? []),
        ];
        foreach ((array) ($group['files'] ?? []) as $f) $files[$f] = true;
        foreach ((array) ($group['booked'] ?? []) as $row) $booked[] = $row + ['period' => $period];
        foreach ((array) ($group['skips'] ?? []) as $row) $skips[] = $row + ['period' => $period];
    }

    $total = 0.0;
    foreach ($booked as $row) $total += va_amount_value($row['amount'] ?? 0);

    $prefix = $dryRun ? 'DRY RUN · ' : '';
    if ($fatal !== null) {
        $subject = '[' . $prefix . 'VA Harian] GAGAL ' . $dmy . ' — ' . substr(preg_replace('/\s+/', ' ', (string) $fatal), 0, 120);
    } else {
        $subject = '[' . $prefix . 'VA Harian] ' . $dmy . ' — ' . count($booked) . ' dibukukan ('
            . va_money($total) . '), ' . count($skips) . ' skip';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8">' . va_report_styles() . '</head><body>';
    $html .= '<h2>Laporan Import VA Harian — ' . $e($dmy) . '</h2>';
    $html .= '<p class="muted">Dibuat otomatis oleh <code>va_daily.php</code> pada ' . $e(date('d-m-Y H:i:s')) . '.</p>';

    if ($dryRun) {
        $html .= '<div class="warn"><strong>DRY RUN.</strong> Tidak ada pembayaran yang dibukukan dan state tidak diubah.</div>';
    }

    if ($fatal !== null) {
        $html .= '<div class="fail"><strong>Import gagal.</strong><br>' . $e((string) $fatal) . '</div>';
        $html .= '<p>Tindakan: pastikan Laragon/MySQL hidup dan share bank bisa diakses, lalu jalankan ulang '
            . '<code>php va_daily.php</code>. Tidak ada file yang ditandai selesai, jadi run berikutnya akan mengejar.</p>';
        $html .= '</body></html>';
        return [
            'subject' => $subject,
            'html' => $html,
            'text' => va_report_text([
                'subject' => $subject, 'date' => $dmy, 'dryRun' => $dryRun, 'fatal' => (string) $fatal,
                'periods' => [], 'booked' => [], 'skips' => [], 'files' => [], 'total' => 0.0,
            ]),
        ];
    }

    $html .= '<h3>Ringkasan</h3><table>';
    $html .= '<tr><th>Tanggal</th><td>' . $e($dmy) . '</td></tr>';
    $html .= '<tr><th>Periode billing</th><td>' . ($periods === [] ? '-' : $e(implode(', ', array_keys($periods)))) . '</td></tr>';
    $html .= '<tr><th>File diproses</th><td>' . $e(count($files)) . '</td></tr>';
    $html .= '<tr><th>Dibukukan</th><td>' . $e(count($booked)) . ' pembayaran (' . $e(va_money($total)) . ')</td></tr>';
    $html .= '<tr><th>Di-skip</th><td>' . $e(count($skips)) . '</td></tr>';
    $html .= '</table>';

    if ($notes !== []) {
        $html .= '<p class="muted"><strong>Catatan:</strong> ' . $e(implode('; ', $notes)) . '</p>';
    }

    if ($periods !== []) {
        $html .= '<h3>Periode &amp; file</h3><table><tr><th>Periode</th><th>Billing</th><th>File</th></tr>';
        foreach ($periods as $p) {
            $html .= '<tr><td>' . $e($p['period'] !== '' ? $p['period'] : '(belum ada)') . '</td>'
                . '<td class="num">' . $e($p['periodRows'] > 0 ? number_format($p['periodRows'], 0, ',', '.') : '-') . '</td>'
                . '<td>' . $e(implode(', ', $p['files'])) . '</td></tr>';
        }
        $html .= '</table>';
    }

    if ($booked !== []) {
        /* Kelompokkan per periode agar nominal per periode terlihat. */
        $byPeriod = [];
        foreach ($booked as $row) $byPeriod[$row['period']][] = $row;
        foreach ($byPeriod as $period => $rows) {
            $sum = 0.0;
            foreach ($rows as $row) $sum += va_amount_value($row['amount'] ?? 0);
            $html .= '<h3>Dibukukan — ' . $e($period) . ' (' . count($rows) . ' baris, ' . $e(va_money($sum)) . ')</h3>';
            $html .= '<table><tr><th>Unit</th><th>Referensi</th><th>Nominal</th><th>Tanggal</th><th>File</th></tr>';
            foreach (array_slice($rows, 0, 200) as $row) {
                $html .= '<tr><td>' . $e($row['unit'] ?? ($row['unit_number'] ?? '')) . '</td>'
                    . '<td>' . $e($row['reference'] ?? ('VA-' . ($row['va_number'] ?? ''))) . '</td>'
                    . '<td class="num">' . $e(va_money(va_amount_value($row['amount'] ?? 0))) . '</td>'
                    . '<td>' . $e($row['txn_date'] ?? '') . '</td>'
                    . '<td>' . $e($row['source_file'] ?? '') . '</td></tr>';
            }
            $html .= '</table>';
            if (count($rows) > 200) {
                $html .= '<p class="muted">… dan ' . (count($rows) - 200) . ' baris lain, lihat CSV arsip.</p>';
            }
        }
    }

    if ($skips !== []) {
        $byReason = [];
        foreach ($skips as $row) $byReason[(string) ($row['reason'] ?? 'unknown')][] = $row;
        $html .= '<h3>Baris yang di-skip (' . count($skips) . ')</h3>';
        $html .= '<table><tr><th>Alasan</th><th>Jumlah</th><th>Saran tindakan</th></tr>';
        foreach ($byReason as $reason => $rows) {
            $period = (string) ($rows[0]['period'] ?? '');
            $html .= '<tr><td>' . $e($reason) . '</td><td class="num">' . count($rows) . '</td><td>'
                . $e(va_skip_advice($reason, $period)) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<table><tr><th>Alasan</th><th>Unit</th><th>VA</th><th>Nominal</th><th>Tgl</th><th>File</th></tr>';
        $shown = 0;
        foreach ($skips as $row) {
            if ($shown >= 300) break;
            $html .= '<tr><td>' . $e($row['reason'] ?? '') . '</td>'
                . '<td>' . $e($row['unit_number'] ?? '') . '</td>'
                . '<td>' . $e($row['va_number'] ?? '') . '</td>'
                . '<td class="num">' . $e(va_money(va_amount_value($row['amount'] ?? 0))) . '</td>'
                . '<td>' . $e(($row['txn_date'] ?? '') . ' ' . ($row['txn_time'] ?? '')) . '</td>'
                . '<td>' . $e($row['source_file'] ?? '') . '</td></tr>';
            $shown++;
        }
        $html .= '</table>';
        if (count($skips) > 300) {
            $html .= '<p class="muted">… dan ' . (count($skips) - 300) . ' baris skip lain, lihat CSV arsip.</p>';
        }
    } elseif ($booked === []) {
        $html .= '<p class="muted">Tidak ada pembayaran baru dan tidak ada baris skip pada run ini.</p>';
    }

    $html .= '</body></html>';
    return [
        'subject' => $subject,
        'html' => $html,
        'text' => va_report_text([
            'subject' => $subject, 'date' => $dmy, 'dryRun' => $dryRun, 'fatal' => null,
            'periods' => $periods, 'booked' => $booked, 'skips' => $skips, 'files' => $files,
            'total' => $total, 'notes' => $notes,
        ]),
    ];
}

/**
 * Pesan watchdog: alert masalah, hasil catch-up, atau kegagalan watchdog.
 *
 * @param array $data ['date','slots'=>[['time']],'files'=>[['name','mtime']],
 *                    'catchup'=>['ran','ok','summary'],'error'=>?string, 'daily'=>?array]
 * @return array{subject:string,html:string,text:string}
 */
function va_watchdog_build_report(array $data): array {
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $date = (string) ($data['date'] ?? date('Y-m-d'));
    $ts = strtotime($date) ?: time();
    $dmy = date('d-m-Y', $ts);
    $slots = array_values((array) ($data['slots'] ?? []));
    $files = array_values((array) ($data['files'] ?? []));
    $catchup = (array) ($data['catchup'] ?? []);
    $error = $data['error'] ?? null;
    $ran = !empty($catchup['ran']);
    $catchupOk = !empty($catchup['ok']);
    $summary = trim((string) ($catchup['summary'] ?? ''));
    $daily = (array) ($data['daily'] ?? []);
    $slotTimes = [];
    foreach ($slots as $slot) $slotTimes[] = (string) (is_array($slot) ? ($slot['time'] ?? '') : $slot);
    $fileNames = [];
    foreach ($files as $file) $fileNames[] = (string) (is_array($file) ? ($file['name'] ?? '') : $file);

    if ($error !== null) {
        $subject = '[VA Harian] GAGAL WATCHDOG ' . $dmy . ' — ' . substr(preg_replace('/\s+/', ' ', (string) $error), 0, 120);
    } elseif ($ran && $catchupOk && $slots === [] && $files === []) {
        $subject = '[VA Harian] ' . $dmy . ' — catch-up: ' . ($summary !== '' ? substr($summary, 0, 120) : 'selesai');
    } else {
        $slotLabel = $slotTimes !== []
            ? ' (' . implode(', ', array_slice($slotTimes, 0, 4)) . (count($slotTimes) > 4 ? ', …' : '') . ')'
            : '';
        $subject = '[VA Harian] PERINGATAN ' . $dmy . ' — ' . count($slots) . ' run belum selesai'
            . $slotLabel . ', ' . count($files) . ' file menunggu';
    }
    if ($error === null && $ran && !$catchupOk) {
        $subject = '[VA Harian] GAGAL WATCHDOG ' . $dmy . ' — catch-up gagal'
            . ($summary !== '' ? ': ' . substr($summary, 0, 100) : '');
    }

    $html = '<!doctype html><html><head><meta charset="utf-8">' . va_report_styles() . '</head><body>';
    $html .= '<h2>' . $e($subject) . '</h2>';
    $html .= '<p class="muted">Dibuat otomatis oleh <code>va_daily_watchdog.php</code> pada '
        . $e(date('d-m-Y H:i:s')) . '.</p>';

    if ($error !== null) {
        $html .= '<div class="fail"><strong>Watchdog gagal.</strong><br>' . $e((string) $error) . '</div>';
    }
    if ($ran) {
        if ($catchupOk) {
            $html .= '<div class="warn"><strong>Catch-up dijalankan.</strong> Import VA harian dijalankan ulang'
                . ($summary !== '' ? ': ' . $e($summary) : '.') . '</div>';
        } else {
            $html .= '<div class="fail"><strong>Catch-up gagal.</strong> Import yang dijalankan ulang tidak selesai'
                . ($summary !== '' ? ': ' . $e($summary) : '.') . '</div>';
        }
    }

    if ($slotTimes !== []) {
        $html .= '<h3>Run terjadwal belum selesai (' . count($slotTimes) . ')</h3>';
        $html .= '<table><tr><th>Slot</th><th>Keterangan</th></tr>';
        foreach ($slotTimes as $time) {
            $html .= '<tr><td>' . $e($time) . '</td><td>tidak ada penanda <code>=== run selesai ===</code> yang sukses</td></tr>';
        }
        $html .= '</table>';
    }

    if ($fileNames !== []) {
        $html .= '<h3>File menunggu diproses (' . count($fileNames) . ')</h3>';
        $html .= '<table><tr><th>File</th><th>Waktu file</th></tr>';
        foreach ($files as $file) {
            $name = (string) (is_array($file) ? ($file['name'] ?? '') : $file);
            $mtime = is_array($file) ? (int) ($file['mtime'] ?? 0) : 0;
            $html .= '<tr><td>' . $e($name) . '</td>'
                . '<td class="num">' . $e($mtime > 0 ? date('d-m-Y H:i', $mtime) : '-') . '</td></tr>';
        }
        $html .= '</table>';
    }

    if ($slotTimes === [] && $fileNames === [] && $error === null && !$ran) {
        $html .= '<p class="muted">Tidak ada masalah.</p>';
    }

    $html .= '<p class="muted">Jalankan manual: <code>php va_daily.php</code>. Periksa log di '
        . '<code>data/va_daily/logs/</code>.</p>';
    $html .= '</body></html>';

    $esc = static fn(string $v): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);
    $text = $esc($subject) . "\nTanggal: $dmy";
    if ($slotTimes !== []) {
        $text .= "\nRun belum selesai: " . $esc(implode(', ', $slotTimes));
    }
    if ($fileNames !== []) {
        $shown = array_slice($fileNames, 0, 20);
        $text .= "\nFile menunggu (" . count($fileNames) . '): ' . $esc(implode(', ', $shown));
        if (count($fileNames) > 20) $text .= ' …';
    }
    if ($ran) {
        $text .= "\nCatch-up: " . ($catchupOk ? 'berhasil' : 'GAGAL');
        if ($summary !== '') $text .= ' — ' . $esc($summary);
    }
    if ($error !== null) {
        $text .= "\nError: " . $esc((string) $error);
    }
    if (!$ran && $slotTimes === [] && $fileNames === [] && $error === null) {
        $text .= "\nTidak ada masalah.";
    }

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Teks ringkas untuk Telegram (parse_mode=HTML). Dipotong agar muat batas 4096
 * karakter Telegram; daftar file/alasan dibatasi lebih dulu.
 *
 * @param array{subject:string,date:string,dryRun:bool,fatal:?string,periods:array,booked:array,skips:array,files:array,total:float} $d
 */
function va_report_text(array $d): string {
    $esc = static fn($v): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string) $v);
    $lines = ['<b>' . $esc($d['subject']) . '</b>'];
    $lines[] = 'Tanggal: ' . $esc($d['date']);

    if ($d['fatal'] !== null) {
        $lines[] = 'Import gagal: ' . $esc(preg_replace('/\s+/', ' ', (string) $d['fatal']));
        $lines[] = 'Tindakan: pastikan MySQL dan share bank hidup, lalu jalankan php va_daily.php.';
        return implode("\n", $lines);
    }

    $periods = array_keys((array) $d['periods']);
    $lines[] = 'Periode: ' . $esc($periods === [] ? '-' : implode(', ', $periods));
    $lines[] = 'File diproses: ' . count((array) $d['files']);
    $lines[] = 'Dibukukan: ' . count((array) $d['booked']) . ' pembayaran (' . va_money((float) $d['total']) . ')';
    $lines[] = 'Skip: ' . count((array) $d['skips']);

    $byReason = [];
    foreach ((array) $d['skips'] as $row) $byReason[(string) ($row['reason'] ?? 'unknown')][] = $row;
    if ($byReason !== []) {
        $lines[] = 'Alasan skip:';
        $shown = 0;
        foreach ($byReason as $reason => $rows) {
            if ($shown >= 10) break;
            $period = (string) ($rows[0]['period'] ?? ($periods[0] ?? ''));
            $lines[] = '- ' . $esc($reason) . ': ' . count($rows) . ' — ' . $esc(va_skip_advice($reason, $period));
            $shown++;
        }
        if (count($byReason) > 10) $lines[] = '- … dan ' . (count($byReason) - 10) . ' alasan lain';

        /* Baris yang butuh tindakan admin: tampilkan unitnya (maks 12). */
        $perlu = [];
        foreach ((array) $d['skips'] as $row) {
            if (in_array(strtolower(trim((string) ($row['reason'] ?? ''))), ['duplicate', 'duplicate amount'], true)) continue;
            $perlu[] = trim((string) ($row['unit_number'] ?? '')) . ' (' . (string) ($row['reason'] ?? '') . ')';
        }
        if ($perlu !== []) {
            $lines[] = 'Perlu tindakan: ' . $esc(implode(', ', array_slice($perlu, 0, 12)))
                . (count($perlu) > 12 ? ' … dan ' . (count($perlu) - 12) . ' lainnya' : '');
        }
    }

    $names = array_keys((array) $d['files']);
    if ($names !== []) {
        $shown = array_slice($names, 0, 10);
        $lines[] = 'File: ' . $esc(implode(', ', $shown)) . (count($names) > 10 ? ' … dan ' . (count($names) - 10) . ' file lain' : '');
    }

    if ($d['dryRun']) $lines[] = 'DRY RUN: tidak ada yang dibukukan.';
    foreach ((array) ($d['notes'] ?? []) as $note) {
        $note = trim((string) $note);
        if ($note !== '') $lines[] = 'Catatan: ' . $esc($note);
    }
    $lines[] = 'Rincian lengkap ada di email.';

    $text = implode("\n", $lines);
    if (strlen($text) > 4000) {
        $text = substr($text, 0, 3990);
        $cut = strrpos($text, "\n");
        if ($cut !== false && $cut > 0) $text = substr($text, 0, $cut);
        $text .= "\n… (dipotong)";
    }
    return $text;
}

/**
 * Kirim laporan ke semua penerima; coba sekali lagi bila gagal.
 *
 * @return array<string,array{ok:bool,error?:string}>
 */
function va_send_report(array $report, array $recipients): array {
    require_once __DIR__ . '/mailer.php';
    $results = [];
    foreach ($recipients as $to) {
        $to = trim((string) $to);
        if ($to === '') continue;
        $result = sendMail($to, (string) $report['subject'], (string) $report['html']);
        if (empty($result['ok'])) {
            sleep(2);
            $result = sendMail($to, (string) $report['subject'], (string) $report['html']);
        }
        $results[$to] = $result;
    }
    return $results;
}

/**
 * Kirim teks laporan ke Telegram. Tidak pernah melempar fatal: bila token/chat id
 * kosong atau jaringan gagal, hasilnya array dengan ok=false.
 *
 * @return array{ok:bool,error?:string,raw?:string}
 */
function va_send_report_telegram(array $report): array {
    require_once __DIR__ . '/notifier.php';
    if (!telegramEnabled(telegramConfig())) {
        return ['ok' => false, 'error' => 'telegram not configured'];
    }
    $text = trim((string) ($report['text'] ?? ''));
    if ($text === '') $text = trim((string) ($report['subject'] ?? ''));
    if ($text === '') return ['ok' => false, 'error' => 'empty report text'];
    return sendTelegram($text);
}
