<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function col($n) { return Coordinate::stringFromColumnIndex($n); }

function arg($args, $name, $default = null) {
    foreach ($args as $i => $a) {
        if ($a === '--' . $name && isset($args[$i + 1])) return trim($args[$i + 1]);
    }
    return $default;
}

function hasFlag($args, $name) {
    return in_array('--' . $name, $args, true);
}

$args = array_slice($argv, 1);
$xls      = arg($args, 'xls');
$period   = arg($args, 'period');
$filesArg = arg($args, 'files', '');
$userId   = (int) (arg($args, 'user', '3') ?: 3);
$yes      = hasFlag($args, 'yes');

if ($xls === null || $period === null) {
    fwrite(STDERR, "Usage: php import_va_record.php --xls <preview.xls> --period <billing period> [--files \"<File1.txt>,<File2.txt>\"] [--user <id>] [--yes]\n");
    fwrite(STDERR, "Example: php import_va_record.php --xls sep14.xls --period \"Sep 2026\" --files \"Ruko 14 September 2026.txt,Mall 14 September 2026.txt\"\n");
    fwrite(STDERR, "--yes  skip the admin review prompt and commit automatically\n");
    exit(1);
}

$periodKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $period));
$sourceFiles = $filesArg !== '' ? array_map('trim', explode(',', $filesArg)) : null;

$spreadsheet = IOFactory::load($xls);
$sheet = null;
foreach ($spreadsheet->getAllSheets() as $s) {
    if ($s->getTitle() === 'All Transactions') { $sheet = $s; break; }
}
if (!$sheet) $sheet = $spreadsheet->getActiveSheet();

$highestRow = $sheet->getHighestRow();
$highestCol = $sheet->getHighestColumn();
$colCount = Coordinate::columnIndexFromString($highestCol);

$headers = [];
for ($c = 1; $c <= $colCount; $c++) {
    $val = trim((string) $sheet->getCell(col($c) . 1)->getValue());
    $headers[$val] = $c;
}

$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mall_portal', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$users = $db->query("SELECT id, unit_number FROM users")->fetchAll(PDO::FETCH_ASSOC);
$userMap = [];
foreach ($users as $u) {
    $userMap[strtoupper(trim($u['unit_number']))] = $u['id'];
}

$dupStmt = $db->prepare("SELECT py.id FROM payments py JOIN billing b ON b.id = py.billing_id WHERE py.reference = ? AND b.billing_period = ? LIMIT 1");
$dupAmtStmt = $db->prepare("SELECT py.id FROM payments py JOIN billing b ON b.id = py.billing_id WHERE b.unit_number = ? AND b.billing_period = ? AND ABS(py.amount - ?) < 0.005 LIMIT 1");
$billStmt = $db->prepare("SELECT id, amount, paid, status FROM billing WHERE unit_number = ? AND billing_period = ? LIMIT 1");

$pending = [];
$billState = [];
$perFile = [];
$skips = [];
$seenRefs = [];
$seenAmounts = [];

for ($r = 2; $r <= $highestRow; $r++) {
    $sourceFile = trim((string) $sheet->getCell(col($headers['Source File']) . $r)->getValue());
    if ($sourceFiles !== null && !in_array($sourceFile, $sourceFiles, true)) continue;

    $unitNumber = trim((string) $sheet->getCell(col($headers['Unit Number']) . $r)->getValue());
    $vaName = isset($headers['VA Name'])
        ? trim((string) $sheet->getCell(col($headers['VA Name']) . $r)->getValue())
        : '';
    $amountRaw = $sheet->getCell(col($headers['Amount']) . $r)->getValue();
    $txnDate = trim((string) $sheet->getCell(col($headers['Txn Date']) . $r)->getValue());
    $txnTime = trim((string) $sheet->getCell(col($headers['Time']) . $r)->getValue());
    $vaNumber = trim((string) $sheet->getCell(col($headers['VA Number']) . $r)->getValue());
    $matched = trim((string) $sheet->getCell(col($headers['Matched']) . $r)->getValue());

    $amount = (float) str_replace(',', '', $amountRaw);

    if (preg_match('/(\d{2})\/(\d{2})\/(\d{2})/', $txnDate, $dm)) {
        $year = (int)$dm[3] + 2000;
        $time = preg_match('/(\d{2}:\d{2}(:\d{2})?)/', $txnTime, $tm) ? $tm[1] : '00:00:00';
        $paidAt = "$year-{$dm[2]}-{$dm[1]} $time";
    } else {
        $paidAt = date('Y-m-d H:i:s');
    }

    $reason = null;
    if ($matched !== 'YES' || empty($unitNumber) || $unitNumber === '???') {
        $reason = 'unmatched';
    } elseif (empty($userMap[strtoupper(trim($unitNumber))])) {
        $reason = 'no user';
    } elseif ($amount <= 0) {
        $reason = 'zero amount';
    } else {
        $reference = 'VA-' . $vaNumber;
        if (isset($seenRefs[$reference])) {
            $reason = 'duplicate';
        } else {
            $dupStmt->execute([$reference, $period]);
            if ($dupStmt->fetch()) {
                $reason = 'duplicate';
            }
        }
        if ($reason === null) {
            $amountKey = strtoupper(trim($unitNumber)) . '|' . $period . '|' . round($amount, 2);
            if (isset($seenAmounts[$amountKey])) {
                $reason = 'duplicate amount';
            } else {
                $dupAmtStmt->execute([trim($unitNumber), $period, $amount]);
                if ($dupAmtStmt->fetch()) {
                    $reason = 'duplicate amount';
                }
            }
        }
        if ($reason === null) {
            $key = $unitNumber . '|' . $period;
            if (!isset($billState[$key])) {
                $billStmt->execute([$unitNumber, $period]);
                $bill = $billStmt->fetch(PDO::FETCH_ASSOC);
                if (!$bill) {
                    $reason = 'no billing';
                } else {
                    $billState[$key] = [
                        'billing_id' => $bill['id'],
                        'amount' => $bill['amount'],
                        'paid' => $bill['paid'],
                        'status' => $bill['status'],
                    ];
                }
            }
        }
        if ($reason === null) {
            $seenRefs[$reference] = true;
            $seenAmounts[$amountKey] = true;
            $pending[] = [
                'billing_id' => $billState[$key]['billing_id'],
                'user_id' => $userMap[strtoupper($unitNumber)],
                'amount' => $amount,
                'reference' => $reference,
                'paid_at' => $paidAt,
                'source_file' => $sourceFile,
            ];
            $billState[$key]['paid'] += $amount;
            $billState[$key]['status'] = $billState[$key]['paid'] >= $billState[$key]['amount'] ? 'paid' : 'partial';
            $perFile[$sourceFile] = ($perFile[$sourceFile] ?? 0) + 1;
        }
    }

    if ($reason !== null) {
        $skips[] = [
            'source_file' => $sourceFile,
            'va_number' => $vaNumber,
            'va_name' => $vaName,
            'unit_number' => $unitNumber ?: '???',
            'amount' => $amountRaw,
            'txn_date' => $txnDate,
            'txn_time' => $txnTime,
            'reason' => $reason,
        ];
    }
}

$skipTotal = count($skips);
$skipByReason = [];
foreach ($skips as $s) $skipByReason[$s['reason']] = ($skipByReason[$s['reason']] ?? 0) + 1;
$pendingCount = count($pending);

echo "\n=== ANALYSIS ($period) ===\n";
echo "Queued for booking : $pendingCount payments\n";
echo "Skipped            : $skipTotal\n";
foreach ($skipByReason as $k => $v) echo "  - $k : $v\n";

$reviewCsv = null;
if ($skipTotal > 0) {
    $reviewCsv = __DIR__ . "/va_skip_review_$periodKey.csv";
    $fp = fopen($reviewCsv, 'w');
    fputcsv($fp, ['Row', 'Source File', 'VA Number', 'VA Name', 'Unit Number', 'Amount', 'Txn Date', 'Time', 'Reason']);
    $idx = 1;
    foreach ($skips as $s) {
        fputcsv($fp, [$idx++, $s['source_file'], $s['va_number'], $s['va_name'], $s['unit_number'], $s['amount'], $s['txn_date'], $s['txn_time'], $s['reason']]);
    }
    fclose($fp);

    echo "\n=== SKIPPED ITEMS FOR REVIEW ===\n";
    foreach ($skips as $s) {
        printf("  [%s] %-10s VA %s  %s  %s %s  %s\n",
            $s['reason'], $s['unit_number'], $s['va_number'], $s['amount'], $s['txn_date'], $s['txn_time'], $s['source_file']);
    }
    echo "\nReview file: $reviewCsv\n";

    if (!$yes) {
        echo "Book $pendingCount payments? [y/N]: ";
        $ans = trim(fgets(STDIN));
        if (strtolower(substr($ans, 0, 1)) !== 'y') {
            echo "\nABORTED - nothing written to the database.\n";
            exit(0);
        }
    }
}

if ($pendingCount === 0 && $skipTotal === 0) {
    echo "\nNothing to do.\n";
    exit(0);
}

$db->beginTransaction();
$ins = $db->prepare("INSERT INTO payments (billing_id, user_id, amount, method, reference, paid_at) VALUES (?, ?, ?, 'virtual_account', ?, ?)");
$upd = $db->prepare("UPDATE billing SET paid = ?, status = ? WHERE id = ?");
foreach ($pending as $p) {
    $ins->execute([$p['billing_id'], $p['user_id'], $p['amount'], $p['reference'], $p['paid_at']]);
}
foreach ($billState as $st) {
    $upd->execute([$st['paid'], $st['status'], $st['billing_id']]);
}

$action = 'record_va_payments_' . $periodKey;
$fileDetail = [];
foreach (($sourceFiles !== null ? $sourceFiles : array_keys($perFile)) as $f) {
    $n = $perFile[$f] ?? 0;
    $fileDetail[] = "$n VA payments to $period from $f";
}
if (!$fileDetail) $fileDetail[] = "0 VA payments to $period";
$auditDetail = implode(' | ', $fileDetail);
$auditDetail .= ". Skipped: $skipTotal";
foreach ($skipByReason as $k => $v) $auditDetail .= ", $k: $v";
if ($reviewCsv) $auditDetail .= ". Review: " . basename($reviewCsv);
$db->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, '127.0.0.1', NOW())")
   ->execute([$userId, $action, $auditDetail]);
$db->commit();

echo "\n=== DONE ===\n";
echo "Payments booked : $pendingCount\n";
echo "Skipped         : $skipTotal ";
foreach ($skipByReason as $k => $v) echo "($k: $v) ";
echo "\n";
foreach ($fileDetail as $d) echo $d . "\n";
if ($reviewCsv) echo "Skipped items saved for review: $reviewCsv\n";