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

    // Jadwal run utama (Senin-Sabtu). Dipakai watchdog untuk menilai run yang belum selesai.
    'schedule_times' => ['09:30', '13:00', '17:00'],

    // Menit setelah jadwal sebelum slot dianggap terlambat.
    'watchdog_grace_minutes' => 20,

    // File di share yang lebih tua dari ini dan belum tercatat di state.json dianggap tertinggal.
    'stale_file_hours' => 2,

    // Watchdog menjalankan ulang import sekali saat masalah terdeteksi.
    'auto_catch_up' => true,

    // State, preview, arsip skip, dan log. Diabaikan git.
    'work_dir' => __DIR__ . '/data/va_daily',
];
