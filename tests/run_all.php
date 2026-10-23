<?php
/**
 * Jalankan semua test PHP polos di folder ini.
 * Pemakaian: php tests/run_all.php
 */
$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);
$failed = 0;
foreach ($files as $file) {
    fwrite(STDOUT, "=== " . basename($file) . " ===\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $code);
    if ($code !== 0) {
        $failed++;
        fwrite(STDOUT, 'GAGAL: ' . basename($file) . " (exit $code)\n");
    }
}
fwrite(STDOUT, $failed === 0 ? "SEMUA TEST LULUS (" . count($files) . " file)\n" : "ADA $failed FILE TEST GAGAL\n");
exit($failed === 0 ? 0 : 1);
