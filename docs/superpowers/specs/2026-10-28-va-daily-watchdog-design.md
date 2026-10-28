# Design — Watchdog Import VA Harian (auto-catch-up + Telegram)

Tanggal: 2026-10-28
Status: menunggu persetujuan

## Intent

Import VA harian (`va_daily.php`) sudah berjalan otomatis Senin–Sabtu pukul 09:30,
13:00, dan 17:00. Pada 28-10-2026 run 09:30 dihentikan di tengah preview
(`0xC000013A`) sehingga tidak ada booking maupun email, dan pada 27-10-2026 file
`Mall 26 Oktober 2026.txt` tidak terdeteksi sepanjang hari. Keduanya baru
tertangani saat run manual.

Tujuan perubahan ini: kegagalan seperti itu terdeteksi otomatis maksimal ~20
menit setelah jadwal, file yang tertinggal dikejar, dan admin diberi tahu lewat
email + Telegram.

## Goals

1. Mendeteksi run terjadwal yang tidak selesai (proses mati/terminate/hang).
2. Mendeteksi file laporan di share yang sudah lama ada tetapi belum tercatat
   selesai di `state.json`.
3. Menjalankan ulang `va_daily.php` sekali secara otomatis saat masalah terdeteksi.
4. Mengirim notifikasi email dan Telegram untuk: catch-up yang dijalankan,
   masalah yang masih tersisa, dan kegagalan catch-up.
5. Tidak mengirim notifikasi apa pun pada hari normal (semua run selesai, tidak
   ada file tertinggal).
6. Mencegah dua proses import berjalan bersamaan (double booking).

## Non-goals

- Mengganti email laporan harian normal (tetap seperti sekarang).
- Memperbaiki otomatis penyebab skip (`no billing`, `unmatched`, `no user`) —
  tetap tugas admin.
- Dashboard/UI baru.
- Telegram dua arah (bot hanya mengirim, tidak menerima perintah).

## Anti-goals

- Catch-up senyap: setiap catch-up harus dilaporkan.
- Spam: satu masalah yang sama tidak dikirim berulang di hari yang sama.
- Booking ganda: lock proses + dedupe yang sudah ada harus tetap berlaku.

## Approaches

1. **Watchdog terpisah + auto-catch-up (dipilih).** Task kedua memeriksa log dan
   share, menjalankan ulang import bila perlu, lalu memberi notifikasi.
   Tahan terhadap run utama yang dibunuh karena prosesnya independen.
2. **Wrapper PowerShell untuk run utama.** Lebih sedikit komponen, tetapi ikut
   mati bila seluruh task di-terminate — tidak menutup kasus 28-10-2026.
3. **Heartbeat + alert akhir hari (18:00).** Paling sederhana, tetapi alert bisa
   terlambat sampai 8 jam.

## Desain

### Penanda run selesai (`va_daily.php`)

- Tambah `register_shutdown_function` yang menulis
  `=== run selesai: <status> ===` ke log harian. Status diisi di titik keluar
  (`ok`, `no-files`, `no-results`, `booked`, `dry-run`, `no-email`, `fatal`).
- Shutdown function tidak berjalan bila proses dibunuh paksa, sehingga
  ketiadaan penanda = run tidak selesai.
- Tambah lock `work_dir/va_daily.lock` (`flock`, non-blocking). Bila lock
  dipegang proses lain, keluar dengan pesan `run lain sedang berjalan`.
- Perilaku booking/email yang ada tidak berubah.

### Watchdog (`va_daily_watchdog.php`)

Dijalankan task terpisah pada 09:50 / 13:20 / 17:20 (Senin–Sabtu).

1. Ambil lock `work_dir/watchdog.lock`; keluar bila watchdog lain berjalan.
2. Tentukan slot yang sudah lewat grace (`watchdog_grace_minutes`, default 20).
3. Baca log hari ini; slot dianggap selesai bila ada penanda
   `=== run selesai ===` setelah `=== run ===` slot tersebut.
4. Pindai share (folder induk + bulan berjalan/sebelumnya, pola nama yang sama)
   untuk file dalam `lookback_days` yang `mtime`/`size`-nya berbeda dari
   `state.json` dan umur file > `stale_file_hours` (default 2 jam).
5. Bila ada masalah dan tidak ada import yang sedang berjalan:
   jalankan `va_daily.php` sekali (subprocess, argumen sama seperti jadwal).
6. Baca ulang log/state, susun ringkasan:
   - catch-up berhasil → notifikasi "terlambat, dijalankan ulang" + hasil;
   - masih bermasalah → notifikasi alert berisi slot/file yang tersisa;
   - normal → tanpa notifikasi.
7. Dedupe: simpan `lastSignature` + `lastDate` di `work_dir/watchdog.json`.
   `--force` mengabaikan dedupe.
8. Exit code: `0` normal/tertangani, `1` masalah masih tersisa.

Flag: `--dry-run` (tidak menjalankan catch-up, tidak kirim notifikasi),
`--no-notify`, `--no-catch-up`, `--force`, `--base-dir`, `--work-dir`, `--period`.

### Helper murni (`lib/va_daily_watchdog_lib.php`)

Fungsi deterministik, tanpa DB/network, agar bisa diuji:

- `va_watchdog_due_slots(array $times, DateTimeImmutable $now, bool $runDay, int $graceMin): array`
- `va_watchdog_parse_runs(string $logText, string $date): array`
- `va_watchdog_unfinished_slots(array $slots, array $runs): array`
- `va_watchdog_pending_files(array $files, array $state, int $sinceTs, int $staleTs): array`
- `va_watchdog_signature(array $problems): string`
- `va_watchdog_should_notify(?string $lastSig, ?string $lastDate, string $sig, string $today, bool $force): bool`

### Notifikasi (`lib/notifier.php`)

- `telegramConfig()` membaca `TELEGRAM_BOT_TOKEN` dan `TELEGRAM_CHAT_ID` dari
  `.env` (via `env.php`).
- `sendTelegram(string $text): array{ok:bool,error?:string}` — `curl` ke
  `https://api.telegram.org/bot<token>/sendMessage`, `parse_mode=HTML`, timeout
  10 detik, retry sekali. Dilewati bila token/chat id kosong.
- `va_watchdog_build_report(array $data): array{subject,html,text}` di
  `lib/va_daily_report.php`.
- Email memakai `va_send_report()` yang sudah ada; Telegram memakai teks ringkas
  (dipotong < 4000 karakter).

### Konfigurasi (`va_daily.config.php`, `.env.example`)

```php
'schedule_times' => ['09:30', '13:00', '17:00'],
'watchdog_grace_minutes' => 20,
'stale_file_hours' => 2,
'auto_catch_up' => true,
```

`.env.example` ditambah:

```
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

Telegram opsional: bila kosong, notifikasi tetap lewat email.

### Penjadwalan (`setup_va_daily_task.ps1`)

- Tambah task `M2S VA Daily Watchdog`, trigger `09:50`, `13:20`, `17:20`
  (Senin–Sabtu), aksi `php va_daily_watchdog.php`, working directory sama.
- Parameter `-WatchdogTimes` dan `-SkipWatchdog`; verifikasi mencetak kedua task.

### Dokumentasi (`docs/va-daily-import.md`)

- Bagian watchdog: cara kerja, flag, log, dedupe, arti exit code.
- Bagian Telegram: membuat bot via BotFather, mengambil chat ID, mengisi `.env`,
  uji kirim.

## Yang diputuskan di sini (bukan oleh user)

- Grace 20 menit dan batas file "stale" 2 jam (default, mudah diubah di config).
- Watchdog menjalankan catch-up maksimal sekali per pemanggilan; tidak ada loop.
- Notifikasi sukses catch-up tetap dikirim (agar admin tahu ada run yang telat).
- Telegram hanya untuk notifikasi masalah/catch-up, bukan laporan harian normal.

## Yang masih perlu dari user

- `TELEGRAM_BOT_TOKEN` dan `TELEGRAM_CHAT_ID`. Boleh diisi setelah implementasi;
  tanpa itu fitur Telegram tidak aktif dan email tetap jalan.

## Kriteria sukses

- Run yang dibunuh di tengah preview terdeteksi pada pemeriksaan berikutnya,
  file dikejar, dan notifikasi terkirim.
- File yang belum tercatat selesai > 2 jam muncul di notifikasi.
- Hari normal tidak menghasilkan notifikasi tambahan.
- Dua proses import tidak pernah berjalan bersamaan.
- `php tests/va_daily_watchdog_lib_test.php`, `php tests/va_daily_cli_test.php`,
  dan `php tests/va_daily_watchdog_cli_test.php` lulus.
