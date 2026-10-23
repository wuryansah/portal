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
 * @param array $run ['date','dryRun','groups','fatal']
 *   groups[]: ['folder','period','periodRows','files','booked','skips']
 *   booked[]: ['unit','reference','amount','txn_date','source_file']
 *   skips[]:  ['unit_number','va_number','amount','txn_date','txn_time','source_file','reason']
 * @return array{subject:string,html:string}
 */
function va_build_report(array $run): array {
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $date = (string) ($run['date'] ?? date('Y-m-d'));
    $ts = strtotime($date) ?: time();
    $dmy = date('d-m-Y', $ts);
    $groups = (array) ($run['groups'] ?? []);
    $fatal = $run['fatal'] ?? null;
    $dryRun = !empty($run['dryRun']);

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
        return ['subject' => $subject, 'html' => $html];
    }

    $html .= '<h3>Ringkasan</h3><table>';
    $html .= '<tr><th>Tanggal</th><td>' . $e($dmy) . '</td></tr>';
    $html .= '<tr><th>Periode billing</th><td>' . ($periods === [] ? '-' : $e(implode(', ', array_keys($periods)))) . '</td></tr>';
    $html .= '<tr><th>File diproses</th><td>' . $e(count($files)) . '</td></tr>';
    $html .= '<tr><th>Dibukukan</th><td>' . $e(count($booked)) . ' pembayaran (' . $e(va_money($total)) . ')</td></tr>';
    $html .= '<tr><th>Di-skip</th><td>' . $e(count($skips)) . '</td></tr>';
    $html .= '</table>';

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
    return ['subject' => $subject, 'html' => $html];
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
