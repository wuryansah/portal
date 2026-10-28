<?php
/**
 * Pure helpers for the daily VA import (no DB, no network, no filesystem writes).
 *
 * Everything here is deterministic so it can be tested with `php tests/va_daily_lib_test.php`
 * without a database or the bank share.
 */

function va_month_names(): array {
    return [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
}

/**
 * Nama bulan yang mungkin muncul di nama file bank maupun di kolom billing_period.
 * Urutan dipakai apa adanya saat membangun regex periode, jadi jangan diacak.
 */
function va_month_aliases(): array {
    return [
        1  => ['jan', 'januari', 'january'],
        2  => ['feb', 'februari', 'february'],
        3  => ['mar', 'maret', 'march'],
        4  => ['apr', 'april'],
        5  => ['mei', 'may'],
        6  => ['jun', 'juni', 'june'],
        7  => ['jul', 'juli', 'july'],
        8  => ['agu', 'agustus', 'aug', 'august'],
        9  => ['sep', 'september'],
        10 => ['okt', 'oktober', 'oct', 'october'],
        11 => ['nov', 'november'],
        12 => ['des', 'desember', 'dec', 'december'],
    ];
}

function va_folder_name(int $month): string {
    $names = va_month_names();
    if (!isset($names[$month])) return '';
    return sprintf('%02d. %s', $month, $names[$month]);
}

function va_month_from_name(string $name): ?int {
    $name = strtolower(trim($name));
    if ($name === '') return null;
    foreach (va_month_aliases() as $month => $aliases) {
        if (in_array($name, $aliases, true)) return $month;
    }
    return null;
}

function va_shift_month(int $year, int $month, int $delta): array {
    $index = $year * 12 + ($month - 1) + $delta;
    return [
        'year' => intdiv($index, 12),
        'month' => ($index % 12) + 1,
    ];
}

/**
 * "Mall 21 Oktober 2026.txt" -> ['scope'=>'Mall','day'=>21,'month'=>10,'year'=>2026]
 * Nama lain (salinan, bulan asing, ekstensi lain) -> null.
 */
function va_parse_report_filename(string $name): ?array {
    $name = trim($name);
    if (!preg_match('/^(ruko|mall)\s+(\d{1,2})\s+([a-z]+)\s+(\d{4})\.txt$/i', $name, $m)) return null;
    $day = (int) $m[2];
    if ($day < 1 || $day > 31) return null;
    $month = va_month_from_name($m[3]);
    if ($month === null) return null;
    return [
        'scope' => ucfirst(strtolower($m[1])),
        'day' => $day,
        'month' => $month,
        'year' => (int) $m[4],
    ];
}

/**
 * Regex untuk nilai billing_period yang mewakili bulan/tahun tertentu.
 * Contoh: va_period_regex(10, 2026) cocok dengan "Oct-26", "Okt 2026", "October 2026".
 */
function va_period_regex(int $month, int $year): string {
    $aliases = va_month_aliases()[$month] ?? [];
    $names = implode('|', array_map(static fn(string $a): string => preg_quote($a, '/'), $aliases));
    $short = substr((string) $year, -2);
    return '/^(?:' . $names . ')[\s\-]?(?:' . $short . '|' . $year . ')$/i';
}

/**
 * Pilih nilai billing_period yang cocok untuk bulan/tahun tsb.
 * Bila ada beberapa kandidat, ambil yang paling banyak dipakai (count tertinggi);
 * seri diputus dengan strcmp terkecil agar hasilnya deterministik.
 *
 * @param array<string,int> $periodCounts  ['Oct-26' => 3798, ...]
 */
function va_pick_period(array $periodCounts, int $month, int $year): ?string {
    $regex = va_period_regex($month, $year);
    $best = null;
    $bestCount = -1;
    foreach ($periodCounts as $period => $count) {
        $period = (string) $period;
        if (!preg_match($regex, trim($period))) continue;
        $count = (int) $count;
        if ($count > $bestCount || ($count === $bestCount && $best !== null && strcmp($period, $best) < 0)) {
            $best = $period;
            $bestCount = $count;
        }
    }
    return $best;
}

function va_is_run_day(int $dow): bool {
    return $dow !== 0; // 0 = Minggu
}

/**
 * @param array{mtime:int,size:int}|null $entry  entri state lama
 * @param array{mtime:int,size:int}      $sig    kondisi file sekarang
 */
function va_needs_processing(?array $entry, array $sig): bool {
    if ($entry === null) return true;
    return (int) ($entry['mtime'] ?? -1) !== (int) ($sig['mtime'] ?? -2)
        || (int) ($entry['size'] ?? -1) !== (int) ($sig['size'] ?? -2);
}

/**
 * Normalisasi nominal. Format bank yang dipakai preview/record: koma = pemisah ribuan,
 * titik = desimal ("592,937.00"). Format Indonesia ("592.937,00") juga diterima.
 */
function va_amount_value($value): float {
    if (is_int($value) || is_float($value)) return (float) $value;
    $s = trim((string) $value);
    if ($s === '') return 0.0;
    $hasComma = strpos($s, ',') !== false;
    $hasDot = strpos($s, '.') !== false;
    if ($hasComma && $hasDot) {
        if (strrpos($s, ',') > strrpos($s, '.')) {      // 1.234.567,89
            return (float) str_replace(',', '.', str_replace('.', '', $s));
        }
        return (float) str_replace(',', '', $s);        // 1,234,567.89
    }
    if ($hasComma) {
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {   // 1,234
            return (float) str_replace(',', '', $s);
        }
        return (float) str_replace(',', '.', $s);       // 1234,5
    }
    return (float) $s;
}

/**
 * Kunci identitas satu baris transaksi, dipakai untuk membandingkan preview dengan skip CSV.
 */
function va_row_key(array $row): string {
    return implode('|', [
        (string) ($row['source_file'] ?? ''),
        (string) ($row['va_number'] ?? ''),
        number_format(va_amount_value($row['amount'] ?? 0), 2, '.', ''),
        (string) ($row['txn_date'] ?? ''),
        (string) ($row['txn_time'] ?? ''),
    ]);
}

/**
 * Multiset difference: previewRows dikurangi skipRows. Baris yang muncul di skip
 * tidak boleh ikut dilaporkan sebagai "dibukukan".
 */
function va_diff_rows(array $previewRows, array $skipRows): array {
    $remaining = [];
    foreach ($skipRows as $row) {
        $key = va_row_key($row);
        $remaining[$key] = ($remaining[$key] ?? 0) + 1;
    }
    $out = [];
    foreach ($previewRows as $row) {
        $key = va_row_key($row);
        if (!empty($remaining[$key])) {
            $remaining[$key]--;
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function va_skip_signature(array $skipRows): string {
    $keys = [];
    foreach ($skipRows as $row) {
        $keys[] = va_row_key($row) . '|' . (string) ($row['reason'] ?? '');
    }
    sort($keys, SORT_STRING);
    return sha1(implode("\n", $keys));
}

function va_money(float $amount): string {
    return 'Rp' . number_format($amount, 0, ',', '.');
}

/**
 * Argumen untuk `import_va_record.php`. `--yes` selalu dipakai: booking otomatis,
 * tanpa prompt interaktif (keputusan user).
 */
function va_build_record_command(string $php, string $xlsPath, string $period, array $files, int $auditUserId): array {
    $cmd = [
        $php,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'import_va_record.php',
        '--xls', $xlsPath,
        '--period', $period,
        '--user', (string) $auditUserId,
        '--yes',
    ];
    if ($files !== []) {
        $cmd[] = '--files';
        $cmd[] = implode(',', $files);
    }
    return $cmd;
}

function va_period_key(string $period): string {
    return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $period));
}

/**
 * Lokasi CSV review skip. `import_va_record.php` menulisnya di root repo
 * (`__DIR__ . "/va_skip_review_<$periodKey>.csv"`), jadi path ini harus
 * dihitung dari folder lib (satu level di bawah root), bukan dari pemanggil.
 */
function va_skip_csv_path(string $period): string {
    return dirname(__DIR__) . '/va_skip_review_' . va_period_key($period) . '.csv';
}

/**
 * Penjaga anti "skip hilang diam-diam": bila record mengaku ada skip tetapi CSV
 * review tidak ada/kosong, laporan tidak boleh diklaim sukses.
 *
 * @return string|null pesan kesalahan, atau null bila aman
 */
function va_skip_capture_error(int $reportedSkipped, array $skipRows): ?string {
    if ($reportedSkipped > 0 && $skipRows === []) {
        return "record melaporkan $reportedSkipped skip tetapi CSV review tidak ditemukan/kosong";
    }
    return null;
}

/**
 * Baca stdout `import_va_record.php`.
 *
 * @return array{booked:int,skipped:int,reasons:array<string,int>,perFile:array<string,int>}
 */
function va_parse_record_output(string $stdout): array {
    $out = ['booked' => 0, 'skipped' => 0, 'reasons' => [], 'perFile' => []];

    if (preg_match_all('/^Payments booked\s*:\s*(\d+)\s*$/m', $stdout, $m)) {
        $out['booked'] = (int) end($m[1]);
    }
    if (preg_match_all('/^Skipped\s*:\s*(\d+)/m', $stdout, $m)) {
        $out['skipped'] = (int) end($m[1]);
    }
    if (preg_match_all('/^\s{2}-\s+([a-z ]+?)\s*:\s*(\d+)\s*$/mi', $stdout, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $out['reasons'][strtolower(trim($row[1]))] = (int) $row[2];
        }
    }
    if (preg_match_all('/^(\d+) VA payments to .*? from (.+?)\s*$/m', $stdout, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $out['perFile'][trim($row[2])] = (int) $row[1];
        }
    }
    return $out;
}

/**
 * Baris transaksi dari sheet "All Transactions" pada XLS preview.
 * Semua nilai dikembalikan sebagai string kecuali amount (float).
 */
function va_read_preview_rows(string $xlsPath): array {
    if (!is_file($xlsPath)) return [];
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;

    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsPath);
    $sheet = $book->getSheetByName('All Transactions');
    if ($sheet === null) {
        $book->disconnectWorksheets();
        return [];
    }

    $headers = [];
    foreach ($sheet->getRowIterator(1, 1) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $headers[trim((string) $cell->getValue())] = $cell->getColumn();
        }
    }
    $rows = [];
    $highest = (int) $sheet->getHighestDataRow();
    for ($r = 2; $r <= $highest; $r++) {
        $get = static fn(string $name): string => isset($headers[$name])
            ? trim((string) $sheet->getCell($headers[$name] . $r)->getValue())
            : '';
        $rows[] = [
            'source_file' => $get('Source File'),
            'va_number' => $get('VA Number'),
            'va_name' => $get('VA Name'),
            'unit_number' => $get('Unit Number'),
            'amount' => va_amount_value($get('Amount')),
            'txn_date' => $get('Txn Date'),
            'txn_time' => $get('Time'),
            'matched' => $get('Matched'),
        ];
    }
    $book->disconnectWorksheets();
    return $rows;
}

/**
 * Baca CSV review skip (format `import_va_record.php`).
 */
function va_read_skip_csv(string $path): array {
    if (!is_file($path)) return [];
    $fp = @fopen($path, 'r');
    if ($fp === false) return [];
    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }
    $map = [];
    foreach ($header as $i => $name) $map[strtolower(trim((string) $name))] = $i;
    $rows = [];
    while (($line = fgetcsv($fp)) !== false) {
        if ($line === [null] || $line === false) continue;
        $pick = static fn(string $name): string => isset($map[$name], $line[$map[$name]])
            ? trim((string) $line[$map[$name]])
            : '';
        $rows[] = [
            'source_file' => $pick('source file'),
            'va_number' => $pick('va number'),
            'va_name' => $pick('va name'),
            'unit_number' => $pick('unit number'),
            'amount' => $pick('amount'),
            'txn_date' => $pick('txn date'),
            'txn_time' => $pick('time'),
            'reason' => $pick('reason'),
        ];
    }
    fclose($fp);
    return $rows;
}

/**
 * Tulis CSV review skip dengan format yang sama seperti `import_va_record.php`.
 */
function va_write_skip_csv(string $path, array $rows): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $fp = @fopen($path, 'w');
    if ($fp === false) return;
    fputcsv($fp, ['Row', 'Source File', 'VA Number', 'VA Name', 'Unit Number', 'Amount', 'Txn Date', 'Time', 'Reason']);
    $i = 1;
    foreach ($rows as $row) {
        fputcsv($fp, [
            $i++,
            (string) ($row['source_file'] ?? ''),
            (string) ($row['va_number'] ?? ''),
            (string) ($row['va_name'] ?? ''),
            (string) ($row['unit_number'] ?? ''),
            number_format(va_amount_value($row['amount'] ?? 0), 2, ',', '.'),
            (string) ($row['txn_date'] ?? ''),
            (string) ($row['txn_time'] ?? ''),
            (string) ($row['reason'] ?? ''),
        ]);
    }
    fclose($fp);
}

function va_state_read(string $path): array {
    $default = ['files' => [], 'lastSkipSignature' => null, 'lastSkipDate' => null, 'lastRun' => null];
    if (!is_file($path)) return $default;
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) return $default;
    $state = array_merge($default, $data);
    if (!is_array($state['files'])) $state['files'] = [];
    return $state;
}

/**
 * Tulis state secara atomik: temp file di folder yang sama lalu rename.
 */
function va_state_write(string $path, array $state): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json . "\n") === false) return;
    if (!@rename($tmp, $path)) {
        @unlink($path);
        @rename($tmp, $path);
    }
}

/**
 * Jalankan subprocess tanpa risiko deadlock: stdout/stderr ditampung di file.
 *
 * @return array{code:int,stdout:string,stderr:string}
 */
function va_run_process(array $cmd): array {
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

/**
 * Ambil lock eksklusif non-blocking. Lock otomatis lepas saat proses/PHP selesai.
 *
 * @return resource|false
 */
function va_acquire_lock(string $path) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $fp = @fopen($path, 'c');
    if ($fp === false) return false;
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function va_release_lock($handle): void {
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function va_collect_candidates(array $files, array $state, int $sinceTs): array {
    $known = (array) ($state['files'] ?? []);
    $groups = [];
    $skippedOld = 0;
    foreach ($files as $file) {
        $name = (string) ($file['name'] ?? '');
        $parsed = va_parse_report_filename($name);
        if ($parsed === null) continue;
        $mtime = (int) ($file['mtime'] ?? 0);
        if ($mtime < $sinceTs) {
            $skippedOld++;
            continue;
        }
        $sig = ['mtime' => $mtime, 'size' => (int) ($file['size'] ?? 0)];
        if (!va_needs_processing($known[$name] ?? null, $sig)) continue;

        $key = (string) ($file['folder'] ?? '') . '|' . $parsed['month'] . '|' . $parsed['year'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'folder' => (string) ($file['folder'] ?? ''),
                'month' => $parsed['month'],
                'year' => $parsed['year'],
                'days' => [],
                'files' => [],
            ];
        }
        $groups[$key]['days'][] = $parsed['day'];
        $groups[$key]['files'][] = $name;
    }

    $out = [];
    foreach ($groups as $group) {
        $group['days'] = array_values(array_unique($group['days']));
        sort($group['days']);
        $group['files'] = array_values(array_unique($group['files']));
        sort($group['files']);
        $out[] = $group;
    }
    usort($out, static fn(array $a, array $b): int => [$a['year'], $a['month'], $a['folder']] <=> [$b['year'], $b['month'], $b['folder']]);
    return ['groups' => $out, 'skippedOld' => $skippedOld];
}

/**
 * Argumen untuk `import_va_preview.php` (base dir selalu diakhiri separator).
 */
function va_build_preview_command(string $php, string $xlsPath, string $baseDir, int $month, int $year, array $days, string $period): array {
    $names = va_month_names();
    $cmd = [
        $php,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'import_va_preview.php',
        '--base-dir', rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR,
        '--month', (string) ($names[$month] ?? ''),
        '--year', (string) $year,
        '--days', implode(',', $days),
        '--out', $xlsPath,
    ];
    if ($period !== '') {
        $cmd[] = '--period';
        $cmd[] = $period;
    }
    return $cmd;
}



