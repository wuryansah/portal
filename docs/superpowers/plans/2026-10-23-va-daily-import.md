# Import VA Harian Otomatis — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Setiap Senin–Sabtu portal mengambil laporan VA bank terbaru dari share, membukukannya ke billing period yang sesuai, dan mengirim email berisi semua pembayaran yang dibukukan + semua skip.

**Architecture:** `va_daily.php` (CLI) memindai subfolder bulan di share, memanggil `import_va_preview.php` lalu `import_va_record.php --yes` sebagai subprocess (logika matching/booking tidak diduplikasi), lalu menyusun email HTML dari preview + skip CSV. Logika murni (nama folder bulan, periode, parsing nama file, diff baris, state) hidup di `lib/va_daily_lib.php` agar bisa diuji tanpa DB/share. Windows Task Scheduler menjalankan CLI 3× sehari, Senin–Sabtu.

**Tech Stack:** PHP 8.3 CLI, PhpSpreadsheet (sudah ada), MySQL `mall_portal` (mysqli/PDO), `lib/mailer.php` (SMTP), PowerShell `Register-ScheduledTask`.

**Spec:** `docs/superpowers/specs/2026-10-23-va-daily-import-design.md`

## Global Constraints

- PHP binary yang dipakai selalu `PHP_BINARY` (jangan hardcode `C:\laragon\bin\...`).
- Timezone: `date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta')` di setiap entry point CLI.
- Koneksi DB mengikuti pola repo: `mysql:host=127.0.0.1;port=3306;dbname=mall_portal`, user `root`, password kosong.
- Tidak menambah dependency Composer; test memakai PHP polos + assertion helper sendiri.
- `--dry-run` tidak boleh menulis apa pun ke DB (preview boleh, karena hanya SELECT).
- Jangan mengubah `import_va_preview.php` / `import_va_record.php` (dipakai juga manual).
- Jangan commit artefak keuangan: `.gitignore` harus mengabaikan `data/va_daily/`.
- Semua teks laporan dan log berbahasa Indonesia.

## Review Focus

Kondisi yang paling mungkin menggigit, dan test yang mengunci perilakunya:

1. **Berkas bulan lalu masih turun saat awal bulan** (mis. `31 Oktober` muncul 1 November) — file harus diproses dengan bulan/periode Oktober, bukan November. (Task 2)
2. **File laporan tidak ada transaksi** (hanya header/subtotal) — run tercatat di log tanpa email "0 dibukukan". (Task 3)
3. **Periode billing belum dibuat** (mis. `Nov-26`) — file **tidak** ditandai selesai, tidak ada booking, email menjelaskan "periode belum ada". (Task 3)
4. **Nominal sama, unit+periode sama** (dua transfer sah) — ter-skip `duplicate amount` dan muncul di email, bukan hilang. (Task 4)
5. **Nama unit huruf kecil / spasi di file bank** (`gf0c143`) — tetap match. (Task 2)

---

## File Structure

```text
lib/va_daily_lib.php        fungsi murni: nama bulan, periode, parsing nama file, diff, state
lib/va_daily_report.php     penyusun subjek + HTML email
va_daily.php                CLI: guard, scan, preview, record, state, log, kirim email
va_daily.config.php         share path, penerima, audit user, lookback, work dir
setup_va_daily_task.ps1     pembuat Task Scheduler (3 trigger Senin–Sabtu)
docs/va-daily-import.md     runbook operasional
tests/va_daily_lib_test.php test unit lib (tanpa DB)
tests/va_daily_report_test.php test render email
tests/va_daily_cli_test.php test integrasi ringan pakai folder fixture + DB read-only
tests/fixtures/va/          file laporan sintetis (bukan data bank asli)
```

---

### Task 1: Fungsi murni + test harness

**Files:**
- Create: `lib/va_daily_lib.php`
- Create: `tests/va_daily_lib_test.php`

**Interfaces:**
- Produces:
  - `va_month_names(): array` → `[1=>'Januari', …, 12=>'Desember']`
  - `va_folder_name(int $month): string` → `'10. Oktober'`
  - `va_shift_month(int $year, int $month, int $delta): array` → `['year'=>int,'month'=>int]`
  - `va_parse_report_filename(string $name): ?array` → `['scope'=>'Mall','day'=>21,'month'=>10,'year'=>2026]`; `null` untuk nama lain
  - `va_period_regex(int $month, int $year): string`
  - `va_pick_period(array $periodCounts, int $month, int $year): ?string` — `$periodCounts` = `['Oct-26'=>3798, …]`
  - `va_is_run_day(int $dow): bool` — `false` hanya untuk `0` (Minggu)
  - `va_needs_processing(?array $entry, array $sig): bool` — `$sig` = `['mtime'=>int,'size'=>int]`
  - `va_row_key(array $row): string` — dari `source_file`, `va_number`, `amount`, `txn_date`, `txn_time`
  - `va_diff_rows(array $previewRows, array $skipRows): array` — multiset difference
  - `va_skip_signature(array $skipRows): string`
  - `va_money(float $amount): string` → `'Rp17.675.675'`

- [ ] **Step 1: Tulis test yang gagal**

`tests/va_daily_lib_test.php` — harness tanpa framework:

```php
<?php
require_once __DIR__ . '/../lib/va_daily_lib.php';

$total = 0; $gagal = 0;
function cek(string $nama, $dapat, $harap): void {
    global $total, $gagal;
    $total++;
    if ($dapat === $harap) return;
    $gagal++;
    fwrite(STDERR, "GAGAL: $nama\n  harap: " . var_export($harap, true) . "\n  dapat: " . var_export($dapat, true) . "\n");
}

cek('folder Oktober', va_folder_name(10), '10. Oktober');
cek('folder Januari', va_folder_name(1), '01. Januari');
cek('geser bulan mundur', va_shift_month(2026, 1, -1), ['year' => 2025, 'month' => 12]);
cek('geser bulan maju', va_shift_month(2026, 12, 1), ['year' => 2027, 'month' => 1]);
cek('parse Mall', va_parse_report_filename('Mall 21 Oktober 2026.txt'),
    ['scope' => 'Mall', 'day' => 21, 'month' => 10, 'year' => 2026]);
cek('parse Ruko lowercase', va_parse_report_filename('ruko 3 Agustus 2026.txt'),
    ['scope' => 'Ruko', 'day' => 3, 'month' => 8, 'year' => 2026]);
cek('tolak salinan', va_parse_report_filename('Mall 21 Oktober 2026 - Copy.txt'), null);
cek('tolak bulan asing', va_parse_report_filename('Mall 21 Foo 2026.txt'), null);

$periodCounts = ['Aug-26' => 3587, 'Sep 2026' => 3633, 'Oct-26' => 3798, 'Jan-26' => 1];
cek('periode Oktober', va_pick_period($periodCounts, 10, 2026), 'Oct-26');
cek('periode September', va_pick_period($periodCounts, 9, 2026), 'Sep 2026');
cek('periode November belum ada', va_pick_period($periodCounts, 11, 2026), null);
cek('pilih yang paling banyak dipakai', va_pick_period(['Aug 2026' => 5, 'Aug-26' => 10], 8, 2026), 'Aug-26');
cek('tahun lain tidak cocok', va_pick_period(['Oct-25' => 999], 10, 2026), null);

cek('Minggu bukan hari kerja', va_is_run_day(0), false);
cek('Senin hari kerja', va_is_run_day(1), true);
cek('Sabtu hari kerja', va_is_run_day(6), true);

$sig = ['mtime' => 100, 'size' => 20];
cek('file baru', va_needs_processing(null, $sig), true);
cek('file tidak berubah', va_needs_processing($sig, $sig), false);
cek('file berubah size', va_needs_processing(['mtime' => 100, 'size' => 21], $sig), true);

$preview = [
    ['source_file' => 'Mall 21 Oktober 2026.txt', 'va_number' => '8030001000135', 'amount' => 592937.0, 'txn_date' => '21/10/26', 'txn_time' => '09:00:00'],
    ['source_file' => 'Mall 21 Oktober 2026.txt', 'va_number' => '8030001000101', 'amount' => 760366.0, 'txn_date' => '21/10/26', 'txn_time' => '09:05:00'],
];
$skips = [$preview[1] + ['reason' => 'duplicate amount']];
$booked = va_diff_rows($preview, $skips);
cek('diff menyisakan yang dibukukan', count($booked), 1);
cek('diff memilih baris pertama', $booked[0]['va_number'], '8030001000135');
cek('diff aman saat skip kosong', count(va_diff_rows($preview, [])), 2);
cek('signature skip stabil', va_skip_signature($skips), va_skip_signature([$skips[0]]));
cek('format uang', va_money(17675675.0), 'Rp17.675.675');

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/va_daily_lib_test.php`
Expected: fatal error `Failed opening required '.../lib/va_daily_lib.php'` (file belum ada).

- [ ] **Step 3: Implementasi `lib/va_daily_lib.php`**

Implementasikan fungsi sesuai Interfaces. Detail yang tidak boleh diarang:
- Alias bulan: `jan/januari/january`, `feb/februari/february`, `mar/maret/march`, `apr/april`, `mei/may`, `jun/juni/june`, `jul/juli/july`, `agu/agustus/aug/august`, `sep/september`, `okt/oktober/oct/october`, `nov/november`, `des/desember/dec/december`.
- `va_period_regex(10, 2026)` menghasilkan `'/^(?:okt|oktober|oct|october)[\s\-]?(?:26|2026)$/i'`.
- `va_pick_period` memilih nilai dengan count tertinggi di antara yang cocok; seri → `strcmp` terkecil agar deterministik.
- `va_parse_report_filename` memakai regex `'/^(ruko|mall)\s+(\d{1,2})\s+([a-z]+)\s+(\d{4})\.txt$/i'`, memvalidasi nama bulan lewat alias dan `day` 1–31.
- `va_diff_rows` menghitung multiset: setiap baris skip mengonsumsi satu baris preview dengan `va_row_key` yang sama.
- `va_row_key` memformat amount dengan `number_format((float)$amount, 2, '.', '')`.
- `va_money` memakai `number_format((float)$amount, 0, ',', '.')` dengan awalan `Rp`.

- [ ] **Step 4: Jalankan, pastikan lulus**

Run: `php tests/va_daily_lib_test.php`
Expected: `OK 25 test` dan exit 0.

- [ ] **Step 5: Commit**

```bash
git add lib/va_daily_lib.php tests/va_daily_lib_test.php
git commit -m "feat(va): pure helpers for daily VA import"
```

---

### Task 2: Config, CLI scan, dan preview (dry-run)

**Files:**
- Create: `va_daily.config.php`
- Create: `va_daily.php`
- Create: `tests/fixtures/va/Mall 21 Oktober 2026.txt`
- Create: `tests/fixtures/va/Ruko 21 Oktober 2026.txt`
- Create: `tests/va_daily_cli_test.php`
- Modify: `lib/va_daily_lib.php` (tambah `va_collect_candidates()`)

**Interfaces:**
- Consumes: semua fungsi Task 1.
- Produces:
  - `va_collect_candidates(array $files, array $state, int $sinceTs): array` → `['groups'=>[['folder'=>string,'month'=>int,'year'=>int,'days'=>int[],'files'=>string[]]], 'skippedOld'=>int]`; `$files` item = `['path'=>string,'name'=>string,'folder'=>string,'mtime'=>int,'size'=>int]`
  - `va_build_preview_command(string $php, string $xlsPath, string $baseDir, int $month, int $year, array $days, string $period): array`
  - `va_build_record_command(string $php, string $xlsPath, string $period, array $files, int $auditUserId): array`
  - `va_parse_record_output(string $stdout): array` → `['booked'=>int,'skipped'=>int,'reasons'=>array,'perFile'=>array]`
  - CLI flags: `--dry-run`, `--no-email`, `--period <p>`, `--since-days <n>`, `--base-dir <path>`, `--work-dir <path>`

**Fixture** `tests/fixtures/va/Mall 21 Oktober 2026.txt` (format fixed-width, sintetis, unit nyata `GF-0C143`):

```text
LAPORAN TRANSAKSI VIA E-BANKING & COUNTER
NO. NO.PELANGGAN/NO.TXN  NAMA PELANGGAN  NILAI TRANSAKSI  TGL. TXN  WAKTU  LOKASI  KETERANGAN
1   4030003000143  GF0C143  IDR 1,000.00  21/10/26  09:00:00  CABANG  TES
2   4030003000144  gf0c144  IDR 2,500.00  21/10/26  09:05:00  CABANG  TES
SUB TOTAL
```

**Fixture** `tests/fixtures/va/Ruko 21 Oktober 2026.txt`:

```text
LAPORAN TRANSAKSI VIA E-BANKING & COUNTER
NO. NO.PELANGGAN/NO.TXN  NAMA PELANGGAN  NILAI TRANSAKSI  TGL. TXN  WAKTU  LOKASI  KETERANGAN
1   1030000000298  BS00298  IDR 3,000.00  21/10/26  10:00:00  CABANG  TES
SUB TOTAL
```

- [ ] **Step 1: Tulis test integrasi yang gagal**

`tests/va_daily_cli_test.php`:

```php
<?php
require_once __DIR__ . '/../lib/va_daily_lib.php';

$php = PHP_BINARY;
$root = dirname(__DIR__);
$fixture = __DIR__ . '/fixtures/va';
$work = sys_get_temp_dir() . '/va_daily_test_' . getmypid();
@mkdir($work, 0777, true);

$cmd = [$php, $root . '/va_daily.php', '--base-dir', $fixture, '--work-dir', $work,
        '--dry-run', '--no-email', '--since-days', '30'];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
$code = proc_close($proc);

cek('dry-run exit 0', $code, 0);
cek('preview dibuat', glob($work . '/preview/*.xls') ? true : false, true);
cek('log dibuat', glob($work . '/logs/*.log') ? true : false, true);
cek('tidak ada booking saat dry-run', strpos($out, 'DIBUKUKAN') === false, true);
cek('laporan menyebut 3 transaksi', strpos($out, '3 baris') !== false, true);
cek('error kosong', trim($err), '');

/* kandidat: file minggu lalu diabaikan bila di luar lookback */
$old = $work . '/old';
@mkdir($old, 0777, true);
file_put_contents($old . '/Mall 01 Oktober 2026.txt', "x\n");
cek('lookback membatasi file lama',
    va_collect_candidates([['path' => $old . '/Mall 01 Oktober 2026.txt', 'name' => 'Mall 01 Oktober 2026.txt',
        'folder' => $old, 'mtime' => time() - 86400 * 40, 'size' => 2]], [], time())['skippedOld'], 1);

/* file bulan lalu (31 Oktober) yang muncul 1 November tetap periode Oktober */
$c = va_collect_candidates([
    ['path' => $old . '/Mall 31 Oktober 2026.txt', 'name' => 'Mall 31 Oktober 2026.txt',
     'folder' => $old, 'mtime' => time(), 'size' => 2],
], [], time() - 86400 * 2);
cek('bulan diambil dari nama file', [$c['groups'][0]['month'], $c['groups'][0]['year']], [10, 2026]);

/* report tanpa transaksi tidak membuat email "0 dibukukan" */
cek('deteksi file kosong', strpos($out, '0 baris'), false);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/va_daily_cli_test.php`
Expected: gagal karena `va_daily.php` belum ada (`proc_open` exit ≠ 0) dan `va_collect_candidates` belum didefinisikan.

- [ ] **Step 3: Implementasi config + `va_collect_candidates` + CLI preview**

`va_daily.config.php`:

```php
<?php
return [
    'base_dir' => '\\\\10.10.21.222\\pom\\FINANCE - AR\\AR & Collection\\Virtual Account\\Virtual Account 2026\\Virtual TXT',
    'recipients' => ['wuryansah@manggaduasquare.co.id'],
    'audit_user_id' => 3,
    'lookback_days' => 10,
    'work_dir' => __DIR__ . '/data/va_daily',
];
```

Alur `va_daily.php` (tahap Task 2, booking menyusul di Task 3):
1. Parse flag; `--base-dir`/`--work-dir` menimpa config (untuk test).
2. `require env.php`, `date_default_timezone_set(...)`, muat config, siapkan `work_dir/{preview,logs,skips}`, buka `logs/Y-m-d.log` (append).
3. Guard: `va_is_run_day((int)date('w'))`; kalau bukan, log "bukan hari kerja" lalu exit 0.
4. Pindai folder `va_folder_name(bulan ini)` dan bulan sebelumnya; ambil file `.txt` yang cocok `va_parse_report_filename`.
5. `va_collect_candidates(files, state, time() - lookback_days*86400)`; file yang lebih tua dari lookback dihitung `skippedOld` dan tetap dianggap "bukan kandidat" tanpa error.
6. Untuk setiap group: jalankan `va_build_preview_command(...)` via `proc_open`, simpan XLS di `work_dir/preview/YYYY-MM-DD_HHMM_<bulan>.xls`, log 3 baris terakhir stdout. Group dengan 0 transaksi di preview **tidak** dicetak sebagai `0 baris` (cukup log internal).
7. Cetak ringkasan ke stdout: `PREVIEW <folder> <bulan> <tahun>: <N> baris (<file count> file)`.
8. `--dry-run` berhenti di sini. State tidak diubah.

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php tests/va_daily_lib_test.php && php tests/va_daily_cli_test.php`
Expected: `OK 25 test` lalu `OK 12 test`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add va_daily.php va_daily.config.php lib/va_daily_lib.php tests/va_daily_cli_test.php tests/fixtures/va
git commit -m "feat(va): daily CLI scans month folders and builds preview"
```

---

### Task 3: Booking, diff hasil, state, arsip skip

**Files:**
- Modify: `va_daily.php`
- Modify: `lib/va_daily_lib.php` (tambah `va_state_read`, `va_state_write`)
- Modify: `tests/va_daily_cli_test.php`

**Interfaces:**
- Consumes: Task 1 + Task 2.
- Produces:
  - `va_state_read(string $path): array` → `['files'=>array,'lastSkipSignature'=>?string,'lastSkipDate'=>?string,'lastRun'=>?string]`
  - `va_state_write(string $path, array $state): void` (tulis atomik: `file_put_contents` ke `.tmp` lalu `rename`)
  - CLI menambah output: `DIBUKUKAN <n> baris (Rp<x>)` untuk group yang benar-benar dibukukan, dan `TANPA PERIODE <bulan> <tahun>` bila `va_pick_period` mengembalikan `null`.

- [ ] **Step 1: Perluas test (gagal dulu)**

Tambahan di `tests/va_daily_cli_test.php`:

```php
$statePath = $work . '/state.json';

/* periode palsu -> semua baris skip "no billing", tidak ada booking, file tidak ditandai selesai */
$cmd2 = [$php, $root . '/va_daily.php', '--base-dir', $fixture, '--work-dir', $work,
         '--no-email', '--since-days', '30', '--period', '__test_period__'];
$proc = proc_open($cmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out2 = stream_get_contents($pipes[1]);
proc_close($proc);
cek('periode tanpa billing tidak membukukan', strpos($out2, 'DIBUKUKAN') === false, true);
cek('periode tanpa billing melaporkan skip', strpos($out2, 'TANPA PERIODE') !== false, true);
$state = va_state_read($statePath);
cek('file tidak ditandai selesai tanpa periode', isset($state['files']['Mall 21 Oktober 2026.txt']), false);
cek('arsip skip ditulis', glob($work . '/skips/*.csv') ? true : false, true);

/* state atomik bolak-balik */
va_state_write($statePath, ['files' => ['a.txt' => ['mtime' => 1, 'size' => 2]], 'lastSkipSignature' => null, 'lastSkipDate' => null, 'lastRun' => null]);
cek('state bisa dibaca kembali', va_state_read($statePath)['files']['a.txt']['size'], 2);
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/va_daily_cli_test.php`
Expected: gagal pada `TANPA PERIODE` / `va_state_read` belum ada.

- [ ] **Step 3: Implementasi booking**

Tambahan alur setelah preview (hanya bila `--dry-run` tidak aktif):
1. `$period = $forcedPeriod ?? va_pick_period($periodCounts, $group['month'], $group['year'])`, dengan `$periodCounts` dari `SELECT billing_period, COUNT(*) n FROM billing GROUP BY billing_period`.
2. Bila `$period === null`: log + stdout `TANPA PERIODE <bulan> <tahun> (billing_period belum ada)`; **jangan** ubah state untuk file group ini; lanjut group berikutnya.
3. Baca baris preview dari sheet `All Transactions` (kolom: `Source File, VA Number, Amount, Txn Date, Time, Matched`), saring `Matched === 'YES'`.
4. Jalankan `va_build_record_command(...)`; ambil stdout; `va_parse_record_output($stdout)` → `booked`, `skipped`, `reasons`, `perFile`. Simpan stdout ke log.
5. Baca `va_skip_review_<periodKey>.csv` di root (dibuat/ditimpa oleh script record), salin ke `work_dir/skips/YYYY-MM-DD_HHMM_<periodKey>.csv`; bila tidak ada, anggap skip kosong.
6. `$bookedRows = va_diff_rows($previewRows, $skipRows)`; total `$bookedTotal = array_sum(array_column($bookedRows, 'amount'))`.
7. Bila `booked > 0`: stdout `DIBUKUKAN <n> baris (Rp<x>)`; simpan `$groupResult` untuk email.
8. Setelah group sukses (`proc_close === 0`), tandai setiap file group di state: `['mtime'=>..., 'size'=>..., 'period'=>$period, 'booked'=>$perFile[$file] ?? 0, 'at'=>date('c')]`.
9. `--yes` selalu dipakai ke script record; tidak ada prompt interaktif.

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php tests/va_daily_lib_test.php && php tests/va_daily_cli_test.php`
Expected: `OK 25 test` lalu `OK 18 test`, exit 0; DB `payments` tidak bertambah (dicek manual di Task 6 dengan `MAX(id)`).

- [ ] **Step 5: Commit**

```bash
git add va_daily.php lib/va_daily_lib.php tests/va_daily_cli_test.php
git commit -m "feat(va): book VA rows, diff results, persist run state"
```

---

### Task 4: Email laporan + dedupe + alert gagal

**Files:**
- Create: `lib/va_daily_report.php`
- Create: `tests/va_daily_report_test.php`
- Modify: `va_daily.php`
- Modify: `tests/va_daily_cli_test.php`

**Interfaces:**
- Consumes: hasil `$groupResult` Task 3, `va_money`, `va_skip_signature`, `sendMail()`.
- Produces:
  - `va_build_report(array $run): array` → `['subject'=>string,'html'=>string]`; `$run` = `['date'=>'2026-10-23','dryRun'=>bool,'periods'=>array,'groups'=>array,'skips'=>array,'fatal'=>?string]`
  - `va_report_styles(): string`
  - CLI: mengirim email bila `booked > 0` **atau** skip signature berbeda dari `lastSkipSignature` pada tanggal yang sama; alert fatal dikirim sekali (tidak diulang untuk error identik di hari yang sama).

- [ ] **Step 1: Tulis test email yang gagal**

`tests/va_daily_report_test.php`:

```php
<?php
require_once __DIR__ . '/../lib/va_daily_report.php';

$total = 0; $gagal = 0;
function cek(string $nama, $dapat, $harap): void {
    global $total, $gagal;
    $total++;
    if ($dapat === $harap) return;
    $gagal++;
    fwrite(STDERR, "GAGAL: $nama\n  harap: " . var_export($harap, true) . "\n  dapat: " . var_export($dapat, true) . "\n");
}

$run = [
    'date' => '2026-10-23',
    'dryRun' => false,
    'groups' => [[
        'folder' => '10. Oktober', 'period' => 'Oct-26', 'periodRows' => 3798,
        'files' => ['Mall 21 Oktober 2026.txt'],
        'booked' => [['unit' => 'L3-0A135', 'amount' => 592937.0, 'reference' => 'VA-8030001000135', 'source_file' => 'Mall 21 Oktober 2026.txt', 'txn_date' => '21/10/26']],
        'skips' => [['unit_number' => 'GF-0C999', 'va_number' => '4030003000999', 'amount' => '1,000.00', 'txn_date' => '21/10/26', 'txn_time' => '09:10:00', 'source_file' => 'Mall 21 Oktober 2026.txt', 'reason' => 'no billing']],
    ]],
    'fatal' => null,
];

$mail = va_build_report($run);
cek('subjek memuat tanggal', strpos($mail['subject'], '23-10-2026') !== false, true);
cek('subjek memuat jumlah dibukukan', strpos($mail['subject'], '1 dibukukan') !== false, true);
cek('subjek memuat nominal', strpos($mail['subject'], 'Rp592.937') !== false, true);
cek('subjek memuat jumlah skip', strpos($mail['subject'], '1 skip') !== false, true);
cek('body memuat referensi', strpos($mail['html'], 'VA-8030001000135') !== false, true);
cek('body memuat alasan skip', strpos($mail['html'], 'no billing') !== false, true);
cek('body memuat saran tindakan', strpos($mail['html'], 'buat billing') !== false, true);
cek('body memuat periode', strpos($mail['html'], 'Oct-26') !== false, true);

$dry = va_build_report(['date' => '2026-10-23', 'dryRun' => true, 'groups' => [], 'fatal' => null]);
cek('dry run ditandai di subjek', strpos($dry['subject'], 'DRY RUN') !== false, true);

$fatal = va_build_report(['date' => '2026-10-23', 'dryRun' => false, 'groups' => [], 'fatal' => 'MySQL tidak bisa dihubungi']);
cek('alert gagal di subjek', strpos($fatal['subject'], 'GAGAL') !== false, true);
cek('alert gagal memuat alasan', strpos($fatal['html'], 'MySQL tidak bisa dihubungi') !== false, true);

$unsafe = va_build_report(['date' => '2026-10-23', 'dryRun' => false, 'fatal' => null, 'groups' => [[
    'folder' => '10. Oktober', 'period' => 'Oct-26', 'periodRows' => 1, 'files' => ['x.txt'], 'booked' => [],
    'skips' => [['unit_number' => '<script>alert(1)</script>', 'va_number' => '1', 'amount' => '0', 'txn_date' => '21/10/26', 'txn_time' => '00:00:00', 'source_file' => 'x.txt', 'reason' => 'unmatched']],
]]]);
cek('html di-escape', strpos($unsafe['html'], '<script>') === false, true);

echo $gagal === 0 ? "OK $total test\n" : "GAGAL $gagal dari $total test\n";
exit($gagal === 0 ? 0 : 1);
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/va_daily_report_test.php`
Expected: fatal `Failed opening required '.../lib/va_daily_report.php'`.

- [ ] **Step 3: Implementasi render + kirim**

- `va_build_report()` menyusun HTML tabel: ringkasan (tanggal, periode + jumlah billing, file, jumlah dibukukan + nominal, jumlah skip per alasan), tabel pembayaran (Unit, Referensi, Nominal, Tanggal, File; maksimal 200 baris lalu `… dan N baris lain, lihat CSV`), tabel skip (Alasan, Unit, VA, Nominal, Tgl, File) dengan peta saran: `no billing`→`buat billing periode X`, `unmatched`→`perbaiki mapping nama unit`, `no user`→`tambah user untuk unit tsb`, `duplicate`/`duplicate amount`→`normal, cek bila terasa janggal`, `zero amount`→`cek nominal di file bank`.
- Semua nilai dinamis lewat `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- `va_daily.php`: kumpulkan `$run`, panggil `va_build_report`, kirim via `sendMail` untuk setiap `recipients` bila `--no-email` tidak aktif; log hasil `ok`/`error`. Bila `sendMail` gagal, coba sekali lagi setelah 2 detik, lalu log `EMAIL GAGAL` (booking tetap dianggap sukses).
- Dedupe: `$sig = va_skip_signature($allSkips)`; kirim bila `$bookedCount > 0 || $sig !== $state['lastSkipSignature'] || $state['lastSkipDate'] !== $date`. Sesudah kirim, simpan `lastSkipSignature`/`lastSkipDate`.
- Alert fatal (`fatal` di `$run`) dikirim tanpa dedupe hari yang sama hanya bila signature error berbeda; simpan `lastFatalSignature`.

- [ ] **Step 4: Jalankan semua test**

Run: `php tests/va_daily_lib_test.php && php tests/va_daily_report_test.php && php tests/va_daily_cli_test.php`
Expected: `OK 25`, `OK 12`, `OK 18`; semua exit 0.

- [ ] **Step 5: Commit**

```bash
git add lib/va_daily_report.php tests/va_daily_report_test.php va_daily.php tests/va_daily_cli_test.php
git commit -m "feat(va): email digest for booked and skipped VA rows"
```

---

### Task 5: Task Scheduler + runbook

**Files:**
- Create: `setup_va_daily_task.ps1`
- Create: `docs/va-daily-import.md`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: `va_daily.php` (Task 2–4).
- Produces: task `M2S VA Daily Import` dengan 3 trigger (09:30, 13:00, 17:00) Senin–Sabtu.

- [ ] **Step 1: Tulis `.ps1` dan dokumentasi**

`setup_va_daily_task.ps1`:
- Param `-PhpPath` (default: hasil `Get-Command php`), `-ScriptPath` (default `$PSScriptRoot\va_daily.php`), `-User` (default `$env:USERNAME`).
- Aksi: `New-ScheduledTaskAction -Execute $PhpPath -Argument "<script path>" -WorkingDirectory $PSScriptRoot`.
- Trigger: `New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday,Tuesday,Wednesday,Thursday,Friday,Saturday -At '09:30'` + dua trigger `-At '13:00'` dan `-At '17:00'`.
- Settings: `-StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)`.
- `Register-ScheduledTask -TaskName 'M2S VA Daily Import' -Force` dengan principal user saat ini, `-RunLevel Limited`.
- Bila `Register-ScheduledTask` melempar `Access is denied`: cetak instruksi "jalankan PowerShell sebagai Administrator lalu ulangi" dan exit 1.
- Akhir: cetak `Get-ScheduledTask -TaskName ... | Format-List` + 3 trigger.

`.gitignore` tambahan:

```text
# VA daily import runtime artifacts (financial data)
data/va_daily/
```

- [ ] **Step 2: Verifikasi task**

Run: `powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1`
Expected: task terdaftar; `(Get-ScheduledTask -TaskName 'M2S VA Daily Import').Triggers.Count` = 3; semua trigger `DaysOfWeek` = Mon–Sat, jam 09:30/13:00/17:00.

- [ ] **Step 3: Verifikasi eksekusi task**

Run: `Start-ScheduledTask -TaskName 'M2S VA Daily Import'; Start-Sleep 20; Get-Content data/va_daily/logs/(Get-Date -Format 'yyyy-MM-dd').log -Tail 20`
Expected: log run tercatat (`=== run ... ===`), tanpa error fatal.

- [ ] **Step 4: Tulis runbook `docs/va-daily-import.md`**

Isi: cara kerja, jadwal, file yang dibuat, cara jalankan manual (`php va_daily.php`, `--dry-run`, `--no-email`, `--period`, `--since-days`), cara reset state (hapus `data/va_daily/state.json`, dedup DB mencegah dobel), cara mengubah penerima (`va_daily.config.php`), troubleshooting (MySQL mati, share tidak bisa diakses, periode belum ada, email gagal), dan peringatan bahwa `import_va_record.php` tetap bisa dijalankan manual.

- [ ] **Step 5: Commit**

```bash
git add setup_va_daily_task.ps1 docs/va-daily-import.md .gitignore
git commit -m "chore(va): schedule daily VA import and document runbook"
```

---

### Task 6: Backfill 20–21 Oktober + verifikasi end-to-end

**Files:**
- Tidak ada file baru (verifikasi operasional).

**Interfaces:**
- Consumes: seluruh Task 1–5.

- [ ] **Step 1: Catat baseline DB**

Run:
```bash
php -r "require 'vendor/autoload.php'; $d=new PDO('mysql:host=127.0.0.1;port=3306;dbname=mall_portal','root',''); echo 'max_payment='.$d->query('SELECT MAX(id) FROM payments')->fetchColumn().PHP_EOL;"
```
Expected: angka `max_payment` tercatat (mis. 10547).

- [ ] **Step 2: Dry-run pada data nyata**

Run: `php va_daily.php --dry-run`
Expected: mencetak `PREVIEW ...` untuk file 20 & 21 Oktober (dan file lain yang belum masuk), menulis XLS di `data/va_daily/preview/`, **tidak** mencetak `DIBUKUKAN`, `max_payment` tidak berubah.

- [ ] **Step 3: Periksa preview**

Buka XLS terbaru; pastikan semua baris `Matched = YES` kecuali yang memang unitnya tidak dikenal, dan `Bill Status` menunjukkan billing `Oct-26` ada. Bila ada `no bill`, catat unitnya untuk dilaporkan.

- [ ] **Step 4: Run sungguhan**

Run: `php va_daily.php`
Expected: `DIBUKUKAN <n> baris (Rp<x>)`, email masuk ke `wuryansah@manggaduasquare.co.id` dengan tabel pembayaran + tabel skip, `data/va_daily/state.json` menandai file 20 & 21 Oktober selesai, audit log `record_va_payments_oct26` bertambah di `audit_logs`.

- [ ] **Step 5: Verifikasi hasil DB**

Run:
```bash
php -r "require 'vendor/autoload.php'; $d=new PDO('mysql:host=127.0.0.1;port=3306;dbname=mall_portal','root',''); echo 'max_payment='.$d->query('SELECT MAX(id) FROM payments')->fetchColumn().PHP_EOL; foreach($d->query(\"SELECT DATE(paid_at) d, COUNT(*) n, SUM(amount) s FROM payments p JOIN billing b ON b.id=p.billing_id WHERE p.method='virtual_account' AND b.billing_period='Oct-26' AND p.paid_at >= '2026-10-20' GROUP BY DATE(paid_at)\") as $r) echo $r['d'].' '.$r['n'].' '.$r['s'].PHP_EOL;"
```
Expected: `max_payment` naik sesuai jumlah yang dilaporkan email; baris untuk 20 & 21 Oktober > 0; tidak ada baris dengan tanggal transaksi yang sudah pernah dibukukan (tidak dobel).

- [ ] **Step 6: Jalankan ulang untuk membuktikan idempoten**

Run: `php va_daily.php`
Expected: tidak ada `DIBUKUKAN` (semua file sudah ditandai selesai), tidak ada email baru, log mencatat `tidak ada file baru`.

- [ ] **Step 7: Commit state/docs bila ada penyesuaian**

```bash
git add -A ':!data/va_daily'
git commit -m "chore(va): verify daily import backfill"
```
(Bila tidak ada perubahan, lewati commit ini.)
