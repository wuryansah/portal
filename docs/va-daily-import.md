# Import VA Harian (otomatis, Senin–Sabtu)

Dokumen operasional untuk `va_daily.php` — pengambil laporan Virtual Account bank,
pembukuan otomatis ke billing, dan laporan email harian.

## Ringkasan

| Item | Nilai |
|---|---|
| Script | `va_daily.php` (orchestrator), `va_daily_watchdog.php` (pengawas), `lib/va_daily_lib.php`, `lib/va_daily_report.php`, `lib/va_daily_watchdog_lib.php`, `lib/notifier.php` |
| Konfigurasi | `va_daily.config.php`, `.env` (SMTP + Telegram) |
| Sumber data | `\\10.10.21.222\pom\FINANCE - AR\AR & Collection\Virtual Account\Virtual Account 2026\Virtual TXT\<bulan>\*.txt` |
| Jadwal import | Senin-Sabtu, 09:30 / 13:00 / 17:00 (`M2S VA Daily Import`) |
| Jadwal watchdog | Senin-Sabtu, 09:50 / 13:20 / 17:20 (`M2S VA Daily Watchdog`) |
| Penerima laporan | `wuryansah@manggaduasquare.co.id` (ubah di `va_daily.config.php`) |
| Kanal laporan | Email + Telegram (Telegram aktif bila `.env` diisi) |
| Artefak runtime | `data/va_daily/` (di-ignore git, berisi data keuangan) |
| Booking | Otomatis, setara `import_va_record.php --yes` |

## Cara kerja

1. **Pindai folder.** Membaca folder induk share + subfolder bulan berjalan dan bulan
   sebelumnya (`10. Oktober`, `09. September`, …). Nama file harus berpola
   `<Mall|Ruko> <tanggal> <Bulan> <tahun>.txt`; file lain (mis. `- Copy`) diabaikan.
2. **Filter.** File yang `mtime`-nya lebih tua dari `lookback_days` (default 10 hari)
   dilewati, begitu juga file bertanggal masa depan (> besok).
3. **Preview.** Menjalankan `import_va_preview.php` per kelompok (folder+bulan+tahun)
   ke `data/va_daily/preview/` untuk mencocokkan VA ke unit dan billing.
4. **Periode.** Periode billing diambil dari **nama file** (bukan tanggal sistem),
   lalu dicocokkan ke nilai `billing_period` yang benar-benar ada di tabel `billing`
   (dipilih yang paling banyak dipakai, mis. `Oct-26` untuk Oktober 2026).
5. **Booking.** Bila periode ada: `import_va_record.php --xls ... --period ... --files "..."`
   (selalu `--yes`). Bila periode belum ada di billing: **tidak** menyentuh DB,
   baris diklasifikasikan `no billing`/`unmatched`, CSV review ditulis, file **tidak**
   ditandai selesai sehingga otomatis diproses lagi setelah billing dibuat.
6. **Diff.** Baris yang benar-benar dibukukan = baris preview (matched) − baris skip CSV,
   sehingga laporan tidak pernah mengklaim baris skip sebagai "dibukukan".
7. **Email + Telegram di setiap slot.** Laporan selalu dikirim pada tiap run
   (09:30 / 13:00 / 17:00, Senin-Sabtu) lewat email lalu Telegram — termasuk saat
   tidak ada file bank baru atau tidak ada transaksi. Alasan "kosong" masuk ke
   bagian **Catatan** laporan. Kegagalan keras (share/MySQL mati) dikirim sebagai
   alert gagal, sekali per pesan identik per hari.
   Bila salah satu kanal berhasil, dedupe disimpan supaya tidak dobel tiap run.
8. **State.** `data/va_daily/state.json` menandai file selesai (nama file → mtime/size,
   periode, jumlah dibukukan). Ditulis atomik (temp + rename).

## File yang dibuat

```
data/va_daily/
├── state.json                              # progres per file + penanda dedupe email
├── watchdog.json                           # dedupe alert watchdog (signature + tanggal)
├── va_daily.lock                           # lock import (cegah dua run bersamaan)
├── watchdog.lock                           # lock watchdog
├── preview/2026-10-23_0930_Oktober_ab12cd.xls
├── skips/2026-10-23_0930_oct26.csv         # arsip CSV review skip (juga di root repo)
├── logs/2026-10-23.log                     # log teknis semua run hari itu
└── logs/watchdog-2026-10-23.log            # log keputusan watchdog
```

`va_skip_review_<periodkey>.csv` di root repo adalah keluaran `import_va_record.php`.
File itu **dihapus sebelum** setiap pemanggilan record (agar tidak membaca data basi);
salinannya ada di `data/va_daily/skips/`.

## Perintah manual

```powershell
php va_daily.php                      # sama seperti jadwal (booking + email)
php va_daily.php --dry-run            # preview saja: tidak menulis DB, tidak mengubah state
php va_daily.php --no-email           # booking tanpa email/Telegram
php va_daily.php --no-telegram        # kirim email saja (tanpa Telegram)
php va_daily.php --period "Oct-26"    # paksa periode billing
php va_daily.php --since-days 21      # perluas lookback (backfill)
php va_daily.php --base-dir "D:\contoh folder"   # pakai folder lain (uji coba)
php va_daily.php --work-dir "D:\tmp\va"          # artefak ke folder lain
```

`--dry-run` **tidak** menulis tabel apa pun dan **tidak** mengubah `state.json`
(termasuk penanda dedupe email), jadi run sungguhan setelahnya tetap mengirim laporan.

Minggu: script langsung keluar (`Bukan hari kerja`) tanpa memproses apa pun.

### Penanda selesai & lock

Setiap run `va_daily.php` menulis penanda `=== run selesai: <status> ===` ke log
harian lewat shutdown handler. Status yang mungkin: `ok`, `done`, `dry-run`,
`no-email`, `no-recipients`, `not-workday`, `notify-failed`, `fatal`.

- `notify-failed` dipakai saat email **dan** Telegram dua-duanya gagal terkirim.
  Slot itu **tidak** dianggap selesai, jadi watchdog memperingatkan admin pada
  pemeriksaan berikutnya (tanpa menjalankan catch-up, karena import ulang tidak
  bisa mengirim ulang laporan).
- Proses yang dibunuh paksa (`TerminateProcess`, mesin restart, task dihentikan)
  **tidak** menulis penanda → watchdog menganggap slot itu belum selesai.
- `va_daily.lock` mencegah dua import berjalan bersamaan (double booking). Bila
  lock dipegang proses lain, run baru keluar dengan pesan `Run lain sedang berjalan`.

## Watchdog (deteksi + catch-up otomatis)

`va_daily_watchdog.php` berjalan 20 menit setelah setiap run utama
(09:50 / 13:20 / 17:20, Senin-Sabtu). Ia **tidak** menggantikan laporan email +
Telegram normal; ia hanya bekerja saat ada masalah.

1. Ambil `watchdog.lock`. Bila watchdog lain berjalan, keluar (`exit 0`).
2. Hitung slot jadwal yang sudah lewat grace (`watchdog_grace_minutes`, default 20).
3. Baca `logs/<tanggal>.log`: slot dianggap selesai hanya bila ada `=== run ===`
   yang ditutup `=== run selesai: <status> ===` dengan status yang menutup slot
   (`fatal` dan `notify-failed` tidak menutup slot).
4. Pindai share (folder induk + bulan berjalan/sebelumnya) untuk file dalam
   `lookback_days` yang belum tercatat di `state.json` dan umurnya >
   `stale_file_hours` (default 2 jam).
5. Bila ada masalah **dan** tidak ada import yang sedang berjalan (cek
   `va_daily.lock`): jalankan `php va_daily.php --no-email` sekali (catch-up).
   Email untuk catch-up dikirim oleh watchdog, bukan oleh proses anak. Bila
   masalahnya hanya `notify-failed` (laporan gagal terkirim), catch-up dilewati
   karena import ulang tidak mengirim ulang laporan; watchdog langsung
   memperingatkan.
6. Periksa ulang log/state. Kirim notifikasi:
   - catch-up berhasil → subjek `[VA Harian] <tgl> — catch-up: <hasil>`;
   - masalah tersisa / catch-up gagal → subjek `[VA Harian] PERINGATAN ...` atau
     `[VA Harian] GAGAL WATCHDOG ...`.
7. Dedupe: masalah identik tidak dikirim ulang pada hari yang sama
   (`watchdog.json` → `lastSignature`/`lastDate`).
8. Exit code: `0` normal/tertangani, `1` masalah masih tersisa atau catch-up gagal.

Hari normal (semua run selesai, tidak ada file tertinggal) tidak menghasilkan
notifikasi apa pun.

### Perintah watchdog

```powershell
php va_daily_watchdog.php                        # periksa + catch-up + notifikasi
php va_daily_watchdog.php --dry-run --no-notify   # hanya periksa, tidak mengubah apa pun
php va_daily_watchdog.php --no-catch-up          # periksa + lapor, tanpa menjalankan import
php va_daily_watchdog.php --force                # abaikan dedupe (kirim ulang alert)
php va_daily_watchdog.php --base-dir "D:\contoh" --work-dir "D:\tmp\va"
php va_daily_watchdog.php --now "2026-10-28 10:00:00"   # uji dengan waktu tertentu
php va_daily_watchdog.php --period "Oct-26"      # paksa periode saat catch-up
```

Log keputusan: `data\va_daily\logs\watchdog-<yyyy-mm-dd>.log`.

### Konfigurasi watchdog

`va_daily.config.php`:

```php
'schedule_times' => ['09:30', '13:00', '17:00'],
'watchdog_grace_minutes' => 20,
'stale_file_hours' => 2,
'auto_catch_up' => true,
```

### Telegram (laporan harian + alert)

Email dan Telegram sama-sama dipakai: setiap laporan harian yang dikirim lewat email
juga dikirim ke Telegram sebagai ringkasan (jumlah & nominal dibukukan, ringkasan
alasan skip, unit yang butuh tindakan, file). Alert fatal dan alert watchdog juga
lewat Telegram. Telegram tidak aktif bila token/chat ID kosong — email tetap jalan.
Aturan kirimnya sama dengan email: **setiap slot** (09:30/13:00/17:00) laporan
dikirim, termasuk saat tidak ada file bank baru atau 0 dibukukan. `--no-email`
mematikan kedua kanal sekaligus; `--no-telegram` hanya mematikan Telegram.
Email dicoba ulang sekali (jeda 0,5 detik) bila gagal karena jaringan/SMTP;
Telegram juga dua percobaan. Bila **kedua kanal** tetap gagal, run ditutup
dengan status `notify-failed` supaya watchdog mengirim peringatan.

1. Buat bot lewat [@BotFather](https://t.me/BotFather) → `/newbot` → salin token.
2. Kirim satu pesan ke bot, lalu buka
   `https://api.telegram.org/bot<TOKEN>/getUpdates` → salin `chat.id`.
3. Isi `.env` (jangan commit):

   ```
   TELEGRAM_BOT_TOKEN=<token>
   TELEGRAM_CHAT_ID=<chat id>
   ```

4. Uji kirim:

   ```powershell
   php -r "require 'env.php'; require 'lib/notifier.php'; var_export(sendTelegram('Tes notifikasi VA Harian.'));"
   ```

Bila token salah / jaringan mati, email tetap terkirim dan log mencatat
`TELEGRAM GAGAL` (import/booking tidak ikut gagal).

## Pemasangan / ubah jadwal

```powershell
powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1
```

Bila muncul `Akses ditolak`, jalankan PowerShell **sebagai Administrator** lalu ulangi.
Task berjalan sebagai user yang login (`-LogonType Interactive`), jadi mesin harus
dalam keadaan login. `StartWhenAvailable` membuat run yang terlewat langsung dikejar.

Cek status & jalankan manual:

```powershell
Get-ScheduledTask -TaskName 'M2S VA Daily Import' | Format-List
Get-ScheduledTask -TaskName 'M2S VA Daily Watchdog' | Format-List
(Get-ScheduledTask -TaskName 'M2S VA Daily Import').Triggers
(Get-ScheduledTask -TaskName 'M2S VA Daily Watchdog').Triggers
Start-ScheduledTask -TaskName 'M2S VA Daily Import'
Start-ScheduledTask -TaskName 'M2S VA Daily Watchdog'
Get-Content "data\va_daily\logs\$(Get-Date -Format yyyy-MM-dd).log" -Tail 30
Get-Content "data\va_daily\logs\watchdog-$(Get-Date -Format yyyy-MM-dd).log" -Tail 30
```

Ingin hanya task import (tanpa watchdog)? Jalankan dengan `-SkipWatchdog`.
Ubah jam watchdog: `-WatchdogTimes '09:50','13:20','17:20'`.

## Mengubah penerima / lookback

Edit `va_daily.config.php`:

```php
'recipients' => ['wuryansah@manggaduasquare.co.id'],
'lookback_days' => 10,
'audit_user_id' => 3,
```

## Reset / proses ulang

- Hapus `data/va_daily/state.json` → semua file dalam lookback diproses ulang.
  **Aman**: `import_va_record.php` mendeteksi duplikat lewat referensi `VA-<va_number>`
  per periode dan lewat nominal per unit+periode, jadi tidak ada pembayaran dobel.
- Hapus satu entri file di `state.json['files']` bila hanya file itu yang ingin diulang.
- Untuk backfill tanggal lama: `php va_daily.php --since-days 30`.

## Troubleshooting

| Gejala | Penyebab / tindakan |
|---|---|
| `Share tidak bisa diakses: ...` | Share bank mati / VPN putus. Nyalakan akses, `Start-ScheduledTask` lagi. File tidak ditandai selesai → otomatis dikejar. |
| `MySQL tidak bisa dihubungi` | Laragon/MySQL belum jalan. Nyalakan MySQL, jalankan ulang. |
| `TANPA PERIODE ... (tidak ada di billing)` | `billing_period` untuk bulan itu belum dibuat. Buat billing massal periode tsb, lalu jalankan ulang; baris akan diproses (tidak ada booking yang hilang). |
| `BOOKING GAGAL ...` | Lihat `record>` di log. File tidak ditandai selesai sehingga dicoba lagi pada run berikutnya. |
| `BOOKING TIDAK DILAPORKAN ...` | `import_va_record.php` mengaku ada skip tetapi `va_skip_review_<period>.csv` tidak ada/kosong (mis. root repo tidak bisa ditulis). File tidak ditandai selesai; perbaiki izin tulis lalu jalankan ulang. Script menolak mengklaim "dibukukan" tanpa data skip. |
| Email tidak masuk | Cek log: `EMAIL GAGAL`; SMTP diatur di `.env` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_ENC`, `MAIL_FROM`). Kirim ulang manual dengan `php va_daily.php --since-days 1` setelah memperbaiki SMTP (state sudah selesai, jadi pakai `--since-days` + reset entri state bila perlu). |
| Telegram tidak masuk | Cek log: `telegram dilewati: token/chat id kosong` (isi `.env`) atau `TELEGRAM GAGAL: <alasan>` (token salah / bot belum di grup / jaringan). Uji cepat: `php -r "require 'env.php'; require 'lib/notifier.php'; var_export(sendTelegram('Tes VA Harian.'));"`. Email tetap terkirim walau Telegram gagal. |
| Email tidak masuk padahal ada booking | Cek `EMAIL GAGAL` di log, lalu uji SMTP. Sejak laporan dikirim tiap slot, tidak masuknya email berarti masalah kanal (SMTP/Telegram), bukan dedupe. |
| Laporan tidak masuk sama sekali (email + Telegram) | Log berakhir `notifikasi GAGAL di semua kanal` + `=== run selesai: notify-failed ===`. Slot tidak dianggap selesai, jadi watchdog (20 menit kemudian) mengirim `PERINGATAN ... gagal terkirim`. Cek `logs/watchdog-<tgl>.log`; bila jaringan sudah normal jalankan `php va_daily.php` untuk mengirim laporan baru. |
| Banyak skip `duplicate` | Normal untuk file yang sudah pernah dibukukan (mis. setelah backfill). Cek hanya bila ada nominal/tanggal yang janggal. |
| Alert watchdog tidak masuk padahal ada masalah | Cek `logs/watchdog-<tgl>.log`: `notifikasi dilewati` (signature sama hari itu → pakai `--force`), `EMAIL GAGAL`, `TELEGRAM GAGAL`, atau `import sedang berjalan`. |
| Watchdog terus melaporkan slot belum selesai | Log harian tidak punya `=== run selesai ===` untuk slot itu (proses mati). Jalankan `php va_daily.php`, atau biarkan catch-up bekerja. |
| Catch-up gagal | Lihat `catch-up selesai: exit <kode>` + baris `preview>`/`record>` di log harian. Penyebab umum sama seperti tabel di atas (share/MySQL mati, periode belum ada). |
| `Run lain sedang berjalan` | Lock `va_daily.lock` dipegang proses lain (run terjadwal masih jalan). Tunggu selesai; watchdog juga melewati catch-up dalam kondisi ini. |

## Kebijakan review skip

Sesuai skill `va-import-review`, **tidak ada baris yang hilang diam-diam**:

- Setiap skip masuk email (dikelompokkan per alasan + saran tindakan),
  CSV arsip `data/va_daily/skips/`, dan log.
- Alasan yang mungkin: `no billing`, `unmatched`, `no user`, `duplicate`,
  `duplicate amount`, `zero amount`.
- `no billing` / `unmatched` / `no user` perlu tindakan admin sebelum baris bisa dibukukan.

## Catatan

- `import_va_preview.php` dan `import_va_record.php` tetap bisa dijalankan manual
  seperti biasa (mis. untuk satu file tertentu). `va_daily.php` hanya memanggil keduanya.
- Jangan mengubah `va_daily.config.php` `base_dir` tanpa memastikan struktur subfolder
  bulan masih sama.
- Log dan state menyimpan nama unit/VA dan nominal: perlakukan `data/va_daily/`
  sebagai data keuangan (jangan di-commit; sudah masuk `.gitignore`).
