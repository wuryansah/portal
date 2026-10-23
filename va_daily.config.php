<?php
/**
 * Konfigurasi import VA harian. Tidak berisi password: kredensial DB mengikuti
 * konvensi repo (root tanpa password) dan SMTP dibaca dari .env oleh lib/mailer.php.
 */
return [
    // Folder induk di share bank. Subfolder bulan ("10. Oktober") dipindai otomatis.
    'base_dir' => '\\\\10.10.21.222\\pom\\FINANCE - AR\\AR & Collection\\Virtual Account\\Virtual Account 2026\\Virtual TXT',

    // Penerima laporan (booked + skip).
    'recipients' => ['wuryansah@manggaduasquare.co.id'],

    // user id untuk audit_logs & payments.created_by (uid 3 = MGT).
    'audit_user_id' => 3,

    // File bank lebih tua dari ini tidak diproses (dirilis manual bila perlu).
    'lookback_days' => 10,

    // State, preview, arsip skip, dan log. Diabaikan git.
    'work_dir' => __DIR__ . '/data/va_daily',
];
