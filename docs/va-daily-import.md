# Import VA Harian (otomatis, Senin–Sabtu)

Dokumen operasional untuk `va_daily.php` — pengambil laporan Virtual Account bank,
pembukuan otomatis ke billing, dan laporan email harian.

## Ringkasan

| Item | Nilai |
|---|---|
| Script | `va_daily.php` (orchestrator), `lib/va_daily_lib.php`, `lib/va_daily_report.php` |
| Konfigurasi | `va_daily.config.php` |
| Sumber data | `\\10.10.21.222\pom\FINANCE - AR\AR & Collection\Virtual Account\Virtual Account 2026\Virtual TXT\<bulan>\*.txt` |
| Jadwal | Senin–Sabtu, 09:30 / 13:00 / 17:00 (`M2S VA Daily Import`) |
| Penerima laporan | `wuryansah@manggaduasquare.co.id` (ubah di `va_daily.config.php`) |
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
7. **Email.** Dikirim bila ada pembayaran baru **atau** daftar skip berubah pada hari itu.
   Skip identik tidak dikirim ulang (dedupe `lastSkipSignature`/`lastSkipDate` di state).
   Kegagalan keras (share/MySQL mati) dikirim sekali per pesan identik per hari.
8. **State.** `data/va_daily/state.json` menandai file selesai (nama file → mtime/size,
   periode, jumlah dibukukan). Ditulis atomik (temp + rename).

## File yang dibuat

```
data/va_daily/
├── state.json                              # progres per file + penanda dedupe email
├── preview/2026-10-23_0930_Oktober_ab12cd.xls
├── skips/2026-10-23_0930_oct26.csv         # arsip CSV review skip (juga di root repo)
└── logs/2026-10-23.log                     # log teknis semua run hari itu
```

`va_skip_review_<periodkey>.csv` di root repo adalah keluaran `import_va_record.php`.
File itu **dihapus sebelum** setiap pemanggilan record (agar tidak membaca data basi);
salinannya ada di `data/va_daily/skips/`.

## Perintah manual

```powershell
php va_daily.php                      # sama seperti jadwal (booking + email)
php va_daily.php --dry-run            # preview saja: tidak menulis DB, tidak mengubah state
php va_daily.php --no-email           # booking tanpa kirim email
php va_daily.php --period "Oct-26"    # paksa periode billing
php va_daily.php --since-days 21      # perluas lookback (backfill)
php va_daily.php --base-dir "D:\contoh folder"   # pakai folder lain (uji coba)
php va_daily.php --work-dir "D:\tmp\va"          # artefak ke folder lain
```

`--dry-run` **tidak** menulis tabel apa pun dan **tidak** mengubah `state.json`
(termasuk penanda dedupe email), jadi run sungguhan setelahnya tetap mengirim laporan.

Minggu: script langsung keluar (`Bukan hari kerja`) tanpa memproses apa pun.

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
(Get-ScheduledTask -TaskName 'M2S VA Daily Import').Triggers
Start-ScheduledTask -TaskName 'M2S VA Daily Import'
Get-Content "data\va_daily\logs\$(Get-Date -Format yyyy-MM-dd).log" -Tail 30
```

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
| Email tidak masuk | Cek `EMAIL GAGAL` di log; SMTP diatur di `.env` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_ENC`, `MAIL_FROM`). Kirim ulang manual dengan `php va_daily.php --since-days 1` setelah memperbaiki SMTP (state sudah selesai, jadi pakai `--since-days` + reset entri state bila perlu). |
| Email tidak dikirim padahal ada file | Normal bila tidak ada booking baru dan skip identik dengan email terakhir hari itu. Bukti ada di log: `email tidak dikirim: ...`. |
| Banyak skip `duplicate` | Normal untuk file yang sudah pernah dibukukan (mis. setelah backfill). Cek hanya bila ada nominal/tanggal yang janggal. |

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
