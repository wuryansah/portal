# Desain: Import VA Harian Otomatis (Senin–Sabtu)

Tanggal: 2026-10-23
Status: menunggu persetujuan
Ukuran: perubahan kecil (1 script baru + 1 config + task scheduler)

## Intent

Setiap hari Senin–Sabtu, portal mengambil laporan VA bank (`R-5401`) yang baru turun di
`\\10.10.21.222\pom\FINANCE - AR\AR & Collection\Virtual Account\Virtual Account 2026\Virtual TXT\`
(subfolder bulan berjalan, mis. `10. Oktober`), membukukannya ke billing period yang sesuai,
lalu mengirim laporan lengkap ke Wuryansah — semua pembayaran yang dibukukan **dan** semua baris
yang di-skip, sesuai skill `va-import-review`.

## Keputusan yang sudah diambil user

- Pembayaran **dibukukan otomatis** (setara `import_va_record.php --yes`), bukan hanya preview.
- Laporan book + skip dikirim ke **wuryansah@manggaduasquare.co.id**.
- Jalan **setiap hari kecuali Minggu**.

## Goals

- Nol sentuhan manual untuk transaksi normal; telat/terlewat tetap dikejar otomatis.
- Tidak ada baris yang hilang diam-diam: setiap skip muncul di email + CSV + log.
- Aman diulang (dedup DB sudah ada: referensi+periode, unit+periode+nominal).
- Bisa diuji tanpa menulis DB (`--dry-run`) dan tanpa kirim email (`--no-email`).
- Gagal keras (share mati, MySQL mati, periode belum ada) selalu jadi email/alert, bukan diam.

## Non-goals

- Tidak membuat billing, user, atau unit baru secara otomatis.
- Tidak mengubah parser/matcher/booking: tetap memakai `import_va_preview.php` dan
  `import_va_record.php` sebagai subprocess (satu sumber kebenaran).
- Tidak menambah halaman admin/UI baru di tahap ini.
- Tidak mengirim laporan via WhatsApp (nomor admin di DB adalah telepon kantor).

## Anti-goals

- Menandai file "selesai" padahal belum dibukukan (mis. periode billing belum ada).
- Email sukses saat booking gagal atau saat tidak ada yang ditulis.
- Menulis DB saat `--dry-run`.

## Constraints

- `\\10.10.21.222` butuh kredensial akun Windows user; mysqld Laragon harus hidup.
- Task harus jalan sebagai user yang login (bukan SYSTEM) → "run only when user is logged on".
- Shell ini bukan admin: pembuatan Task Scheduler butuh UAC sekali (atau `.ps1` dijalankan manual).
- `lib/mailer.php` hanya HTML (tanpa attachment) → tabel skip ditulis di badan email.

## Approaches

| # | Cara | Trade-off |
|---|------|-----------|
| 1 | **Script CLI `va_daily.php` + Task Scheduler + email** (dipilih) | Paling andal, memakai ulang script & SMTP yang ada, tanpa menambah permukaan API. Perlu setup task sekali. |
| 2 | Sama, tapi laporan via WhatsApp fonnte | Ringkas saja, detail panjang tidak cocok, butuh nomor WA pribadi yang belum ada. |
| 3 | Halaman admin "VA Harian" + trigger HTTP | Terlihat di portal, tapi tidak andal tanpa trafik dan menambah kode UI. |

Dipilih **1**, karena kebutuhan utamanya pembukuan terjadwal + laporan lengkap, dan SMTP sudah aktif.

## Desain

### File baru

```text
va_daily.php                     orchestrator CLI (preview -> record -> laporan)
va_daily.config.php              pengaturan: share path, penerima, jam, audit user, lookback
data/va_daily/state.json         file yang sudah diproses (nama, mtime, size) + hasil run terakhir
data/va_daily/preview/YYYY-MM-DD_HHMM.xls   preview per run
data/va_daily/skips/YYYY-MM-DD_<periode>.csv salinan skip (script asli menimpa file root)
data/va_daily/logs/YYYY-MM-DD.log           log teknis per hari
setup_va_daily_task.ps1          pembuat task (dijalankan sekali, bisa perlu UAC)
```

### Alur satu run

1. **Guard**: tolak hari Minggu; cek koneksi DB dan akses share. Gagal → email alert (`[VA Harian] GAGAL ...`) + exit 1.
2. **Kandidat file**: pindai folder bulan berjalan **dan** bulan sebelumnya (batas bulan), ambil
   `Ruko|Mall DD <Bulan> YYYY.txt` yang belum ada di state atau mtime/size-nya berubah,
   dibatasi lookback 10 hari.
3. **Kelompokkan** per (folder, bulan, tahun) → daftar `--days`; tanggal di nama file dipakai
   sebagai bulan transaksi (ditolak bila tanggal masa depan > +1 hari).
4. **Periode**: cari `billing_period` yang cocok dengan bulan/tahun itu (pola `Oct-26`,
   `Oct 2026`, `October 2026`, dll). Tidak ada → **jangan book, jangan tandai selesai**, laporkan
   "periode X belum ada di billing".
5. **Preview + record**: jalankan `import_va_preview.php` lalu `import_va_record.php --yes
   --user 3 --files "<daftar>" --period "<periode>"` (PHP binary = `PHP_BINARY`).
6. **Hitung hasil**: booked = baris preview − baris skip CSV (kunci: file + VA + nominal + tanggal + jam);
   total Rp, per file, per unit.
7. **Email HTML** ke penerima:
   - subjek `[VA Harian] 23-10-2026 — 46 dibukukan (Rp95.767.595), 3 skip`
   - ringkasan (file, periode, jumlah, nominal), tabel pembayaran dibukukan (maks 200 baris),
     tabel skip dikelompokkan per alasan + saran tindakan (`no billing` → buat billing periode X,
     `unmatched` → perbaiki mapping unit, `no user` → tambah user, `duplicate` → normal),
     path CSV/CSV arsip/log.
8. **State & log**: tandai file selesai hanya bila langkah 5 sukses; tulis log run.

### Jadwal

Task `M2S VA Daily Import`, Senin–Sabtu, trigger **09:30, 13:00, 17:00**, `StartWhenAvailable`.
File bank turun sekitar 08:49–09:11; run tambahan menangkap upload yang telat atau run pagi yang
terlewat (PC mati). Bila tidak ada file baru, script keluar tanpa email (2 detik). Alert skip yang
isinya sama tidak dikirim berulang di hari yang sama (dedupe per file+alasan per hari), tapi tetap
tercatat di log.

### Opsi CLI

```text
php va_daily.php                 # sama seperti jadwal
php va_daily.php --dry-run       # preview saja, tanpa tulis DB, email ditandai DRY RUN
php va_daily.php --no-email      # untuk uji lokal
php va_daily.php --period Oct-26 # paksa periode (mis. transaksi awal bulan)
php va_daily.php --since-days 3  # batasi lookback
```

### Backfill

Setelah setup, jalankan `php va_daily.php` sekali: file **20 dan 21 Oktober** belum ada di state
sehingga ikut dibukukan; hasilnya masuk email pertama.

### Risiko & mitigasi

| Risiko | Mitigasi |
|---|---|
| File bank belum turun / telat | 3 trigger + lookback 10 hari; tidak error, hanya dicatat |
| Periode baru (mis. Nov-26) belum dibuat | skip `no billing` dilaporkan, file tidak ditandai selesai → run berikutnya mem-book setelah billing dibuat |
| Nominal sama, unit+periode sama (2 transfer sah) | ter-skip `duplicate amount`, muncul di laporan untuk dicek |
| MySQL/Laragon mati | email `GAGAL`, run berikutnya mengejar (state belum berubah) |
| PC mati saat jadwal | `StartWhenAvailable` menjalankan saat login berikutnya |
| Email SMTP gagal | log + retry sekali; kegagalan email tidak membatalkan booking |

## Yang diserahkan ke builder

Format tabel email, penamaan log, retry SMTP, dan deteksi "file berubah" (mtime+size) —
diselesaikan saat implementasi dengan tetap mengikuti batasan di atas.
