# Design — Laporan VA Harian juga dikirim ke Telegram

Tanggal: 2026-10-29
Status: disetujui user ("ya kirim juga, juga berlaku untuk berikutnya")

## Kenapa

Laporan VA harian (`va_daily.php`) saat ini hanya dikirim lewat email. Telegram
sudah tersedia di `lib/notifier.php` tetapi hanya dipakai `va_daily_watchdog.php`
untuk alert masalah, jadi pada hari normal (tidak ada masalah) tidak ada pesan
Telegram sama sekali — termasuk untuk laporan pembukuan yang sukses.

Mulai 29-10-2026 laporan harian harus sampai di **email dan Telegram**.

## Tujuan

- Laporan VA harian (dan alert fatal) terkirim ke Telegram tepat setelah email.
- Isi Telegram = ringkasan yang bisa dibaca di ponsel, tidak memotong data penting.
- Kegagalan Telegram tidak boleh menggagalkan import/booking maupun email.
- Tidak ada spam: aturan kirim Telegram sama dengan email.

## Non-tujuan

- Tidak menambah sumbu robot Telegram dua arah (bot tidak menerima perintah).
- Tidak mengirim tabel lengkap semua baris ke Telegram (batas 4096 karakter).
- Tidak mengubah laporan watchdog yang sudah ada.

## Pendekatan yang dipertimbangkan

| Opsi | Isi | Trade-off | Keputusan |
|---|---|---|---|
| A | `va_build_report()` juga menghasilkan `text`; `va_daily.php` mengirim ke email lalu Telegram dengan aturan kirim yang sama seperti email | Ringkas, konsisten dengan watchdog, tidak ada spam | **dipilih user** |
| B | Telegram dikirim tiap slot 09:30/13:00/17:00 walau tidak ada transaksi | 3 pesan/hari meski nihil | ditolak |
| C | Kirim seluruh baris dibukukan ke Telegram | Terpotong batas 4096 karakter | ditolak |

## Keputusan implementasi

1. `lib/va_daily_report.php::va_build_report()` mengembalikan `subject`, `html`,
   dan sekarang juga `text`. `text` memakai `parse_mode=HTML` (`&`, `<`, `>` di-escape),
   berisi: subjek, tanggal, periode, file diproses, jumlah+nominal dibukukan,
   ringkasan alasan skip beserta saran tindakan, dan daftar file (dibatasi agar
   total ≤ 4000 karakter).
2. Helper baru `va_send_report_telegram(array $report): array` — satu tempat untuk
   cek konfigurasi (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`) lalu `sendTelegram()`.
   Bila belum dikonfigurasi → `['ok'=>false,'error'=>'telegram not configured']`
   tanpa melempar error.
3. `va_daily.php`:
   - setelah email, kirim Telegram (juga untuk alert fatal `$sendFatal`);
   - `--no-email` tetap berarti "jangan kirim notifikasi apa pun" (dipakai
     watchdog catch-up yang mengirim notifikasinya sendiri);
   - flag baru `--no-telegram` untuk melewati Telegram saja;
   - dedupe `lastSkipSignature`/`lastSkipDate` disimpan bila **salah satu** kanal
     berhasil, supaya tidak ada pengiriman ganda setiap run.
4. Log: `telegram terkirim`, `TELEGRAM GAGAL: <alasan>`, atau
   `telegram dilewati: token/chat id kosong` — sejajar dengan pola watchdog.

## Yang diserahkan ke builder

- Susunan kalimat `text` (ringkas, bahasa Indonesia, angka Rupiah format `va_money`).
- Batas jumlah baris ringkasan skip yang ditampilkan.
- Penyesuaian dokumentasi `docs/va-daily-import.md`.

## Perubahan lanjutan (29-10-2026, setelah review user)

Semula laporan hanya dikirim bila ada booking baru atau daftar skip berubah
(opsi A). User meminta laporan **selalu** masuk walau tidak ada transaksi, dengan
frekuensi **setiap slot** 09:30 / 13:00 / 17:00 (bukan hanya sekali sehari).

Keputusan baru:

- `va_daily.php` tidak lagi berhenti di `no-new-files`/`no-results`; ia tetap
  menyusun laporan dengan `notes` (mis. `tidak ada file bank baru pada run ini`,
  `preview gagal: <label>`, `preview 0 baris: <label>`) lalu mengirim email +
  Telegram.
- Dedupe "skip identik" tidak lagi menahan pengiriman; dedupe tetap disimpan
  hanya untuk alert fatal.
- `--dry-run` tetap tidak mengirim apa pun; `--no-email` mematikan kedua kanal.
- Efek operasional: 3 laporan/hari (Senin-Sabtu), masing-masing ±1 pesan email
  dan 1 pesan Telegram, termasuk hari tanpa transaksi.

