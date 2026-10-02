<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function col($n) { return Coordinate::stringFromColumnIndex($n); }
function cell(&$sheet, $c, $r, $v) { $sheet->getCell(col($c) . $r)->setValue($v); }

function arg($args, $name, $default = null) {
    foreach ($args as $i => $a) {
        if ($a === '--' . $name && isset($args[$i + 1])) return trim($args[$i + 1]);
    }
    return $default;
}

$args = array_slice($argv, 1);
$baseDir = arg($args, 'base-dir');
$month   = arg($args, 'month');
$year    = arg($args, 'year');
$daysArg = arg($args, 'days');
$out     = arg($args, 'out', __DIR__ . '/va_preview.xls');
$period  = arg($args, 'period', '');

if ($baseDir === null || $month === null || $year === null || $daysArg === null) {
    fwrite(STDERR, "Usage: php import_va_preview.php --base-dir <share folder> --month <Month> --year <YYYY> --days <DD[,DD,...]> [--out <file.xls>] [--period <billing period>]\n");
    fwrite(STDERR, "Example: php import_va_preview.php --base-dir \"\\\\10.10.21.222\\pom\\...\\Virtual TXT\\09. September\\\" --month September --year 2026 --days 14 --out sep14.xls --period \"Sep 2026\"\n");
    exit(1);
}

$files = [];
foreach (explode(',', $daysArg) as $d) {
    $d = trim($d);
    if ($d === '') continue;
    $files[] = "Ruko $d $month $year.txt";
    $files[] = "Mall $d $month $year.txt";
}
$files = array_values(array_unique($files));

$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mall_portal', 'root', '');

$users = $db->query("SELECT id, unit_number FROM users WHERE unit_number IS NOT NULL AND unit_number != ''")->fetchAll(PDO::FETCH_ASSOC);
$vaMap = [];
foreach ($users as $u) {
    $vaMap[strtoupper(trim($u['unit_number']))] = $u['unit_number'];
}

function convertVaNameToUnit($vaName, $vaMap) {
    $vaName = strtoupper(trim($vaName));
    if (isset($vaMap[$vaName])) return $vaMap[$vaName];

    if (strpos($vaName, 'FT') === 0 && strlen($vaName) >= 4) {
        $ft = $vaName[0] . '-' . substr($vaName, 1, 2) . '-' . substr($vaName, 3);
        if (isset($vaMap[$ft])) return $vaMap[$ft];
    }

    if (strpos($vaName, 'KKM') === 0) {
        $withDash = substr($vaName, 0, 3) . '-' . substr($vaName, 3);
        if (isset($vaMap[$withDash])) return $vaMap[$withDash];
        return null;
    }

    $withDash = substr($vaName, 0, 2) . '-' . substr($vaName, 2);
    if (isset($vaMap[$withDash])) return $vaMap[$withDash];

    if (strlen($withDash) > 4 && $withDash[4] === 'P') {
        $converted = substr($withDash, 0, 4) . '0' . substr($withDash, 5);
        if (isset($vaMap[$converted])) return $vaMap[$converted];
    }

    $flatName = preg_replace('/[^A-Za-z0-9]/u', '', $vaName);
    if ($flatName !== '') {
        foreach ($vaMap as $unitKey => $unitVal) {
            if (preg_replace('/[^A-Za-z0-9]/u', '', $unitKey) === $flatName) return $unitVal;
        }
    }

    $numeric = preg_replace('/[^0-9]/', '', $vaName);
    if (strlen($numeric) >= 4) {
        $suffix = substr($numeric, -4);
        foreach ($vaMap as $unitKey => $unitVal) {
            $unitNum = preg_replace('/[^0-9]/', '', $unitKey);
            if (substr($unitNum, -4) === $suffix) return $unitVal;
        }
    }
    return null;
}

function parseBankFile($filepath) {
    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $txns = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(\d+)\s+(\d{13})\s+(\S+)\s+IDR\s+([\d,\.]+)\s+(\d{2}\/\d{2}\/\d{2})\s+(\d{2}:\d{2}:\d{2})\s+(\S+)/', $line, $m)) {
            $txns[] = [
                'va_number' => $m[2],
                'va_name' => $m[3],
                'amount' => (float) str_replace(',', '', $m[4]),
                'date' => $m[5],
                'time' => $m[6],
                'location' => $m[7],
            ];
        }
    }
    return $txns;
}

$billingPeriod = $period;
$allTxns = [];
$fileStats = [];
$billCache = [];

foreach ($files as $f) {
    $filepath = $baseDir . $f;
    if (!file_exists($filepath)) { echo "SKIP: $f\n"; continue; }

    $txns = parseBankFile($filepath);
    $matched = 0;

    foreach ($txns as &$t) {
        $unit = convertVaNameToUnit($t['va_name'], $vaMap);
        $t['unit_number'] = $unit ?: '???';
        $t['source_file'] = $f;
        if ($unit) $matched++;

        if ($billingPeriod !== '') {
            $key = ($unit ?: '?') . '|' . $billingPeriod;
            if (!isset($billCache[$key])) {
                $stmt = $db->prepare("SELECT amount, paid, status FROM billing WHERE unit_number = ? AND billing_period = ? LIMIT 1");
                $stmt->execute([$unit, $billingPeriod]);
                $billCache[$key] = $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
            }
            $bill = $billCache[$key];
            $t['bill_amount'] = $bill ? $bill['amount'] : '';
            $t['bill_paid'] = $bill ? $bill['paid'] : '';
            $t['bill_status'] = $bill ? $bill['status'] : 'no bill';
        } else {
            $t['bill_amount'] = '';
            $t['bill_paid'] = '';
            $t['bill_status'] = '';
        }
    }
    unset($t);

    $fileStats[] = ['file' => $f, 'txns' => count($txns), 'matched' => $matched, 'unmatched' => count($txns) - $matched];
    $allTxns = array_merge($allTxns, $txns);
}

echo "Total transactions: " . count($allTxns) . "\n";

$ss = new Spreadsheet();

$s1 = $ss->getActiveSheet();
$s1->setTitle('All Transactions');
$h1 = ['No', 'Source File', 'VA Number', 'VA Name', 'Unit Number', 'Amount', 'Txn Date', 'Time', 'Location', 'Bill Amount', 'Bill Paid', 'Bill Status', 'Matched'];
foreach ($h1 as $c => $h) { cell($s1, $c+1, 1, $h); }
$s1->getStyle('A1:M1')->getFont()->setBold(true);

$r = 2;
foreach ($allTxns as $i => $t) {
    cell($s1, 1, $r, $i+1);
    cell($s1, 2, $r, $t['source_file']);
    cell($s1, 3, $r, $t['va_number']);
    cell($s1, 4, $r, $t['va_name']);
    cell($s1, 5, $r, $t['unit_number']);
    cell($s1, 6, $r, $t['amount']);
    cell($s1, 7, $r, $t['date']);
    cell($s1, 8, $r, $t['time']);
    cell($s1, 9, $r, $t['location']);
    cell($s1, 10, $r, $t['bill_amount']);
    cell($s1, 11, $r, $t['bill_paid']);
    cell($s1, 12, $r, $t['bill_status']);
    cell($s1, 13, $r, $t['unit_number'] !== '???' ? 'YES' : 'NO');
    $r++;
}

$s2 = $ss->createSheet(null);
$s2->setTitle('Summary by File');
$h2 = ['File', 'Transactions', 'Matched', 'Unmatched'];
foreach ($h2 as $c => $h) { cell($s2, $c+1, 1, $h); }
$s2->getStyle('A1:D1')->getFont()->setBold(true);
$r = 2;
foreach ($fileStats as $fs) {
    cell($s2, 1, $r, $fs['file']);
    cell($s2, 2, $r, $fs['txns']);
    cell($s2, 3, $r, $fs['matched']);
    cell($s2, 4, $r, $fs['unmatched']);
    $r++;
}
cell($s2, 1, $r, 'TOTAL');
cell($s2, 2, $r, array_sum(array_column($fileStats, 'txns')));
cell($s2, 3, $r, array_sum(array_column($fileStats, 'matched')));
cell($s2, 4, $r, array_sum(array_column($fileStats, 'unmatched')));
$s2->getStyle('A'.$r.':D'.$r)->getFont()->setBold(true);

$s3 = $ss->createSheet(null);
$s3->setTitle('Unmatched');
$h3 = ['Source File', 'VA Number', 'VA Name', 'Amount', 'Txn Date'];
foreach ($h3 as $c => $h) { cell($s3, $c+1, 1, $h); }
$s3->getStyle('A1:E1')->getFont()->setBold(true);
$r = 2;
foreach ($allTxns as $t) {
    if ($t['unit_number'] === '???') {
        cell($s3, 1, $r, $t['source_file']);
        cell($s3, 2, $r, $t['va_number']);
        cell($s3, 3, $r, $t['va_name']);
        cell($s3, 4, $r, $t['amount']);
        cell($s3, 5, $r, $t['date']);
        $r++;
    }
}

$s4 = $ss->createSheet(null);
$s4->setTitle('Summary by Unit');
$h4 = ['Unit Number', 'Total Amount', 'Txn Count'];
foreach ($h4 as $c => $h) { cell($s4, $c+1, 1, $h); }
$s4->getStyle('A1:C1')->getFont()->setBold(true);
$unitTotals = [];
foreach ($allTxns as $t) {
    $u = $t['unit_number'];
    if (!isset($unitTotals[$u])) $unitTotals[$u] = ['amount' => 0, 'count' => 0];
    $unitTotals[$u]['amount'] += $t['amount'];
    $unitTotals[$u]['count']++;
}
uksort($unitTotals, 'strcmp');
$r = 2;
foreach ($unitTotals as $unit => $d) {
    cell($s4, 1, $r, $unit);
    cell($s4, 2, $r, $d['amount']);
    cell($s4, 3, $r, $d['count']);
    $r++;
}

foreach ([$s1, $s2, $s3, $s4] as $sh) {
    $maxCol = Coordinate::columnIndexFromString($sh->getHighestColumn());
    for ($c = 1; $c <= $maxCol; $c++) {
        $sh->getColumnDimension(col($c))->setAutoSize(true);
    }
}

$writer = new Xls($ss);
$writer->save($out);

$umCount = count(array_filter($allTxns, fn($t) => $t['unit_number'] === '???'));
echo "XLS saved: $out\n";
echo "Matched: " . (count($allTxns) - $umCount) . "\n";
echo "Unmatched: $umCount\n";