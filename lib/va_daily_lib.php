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
