# Watchdog Import VA Harian — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deteksi run import VA harian yang gagal/terhenti dan file share yang tertinggal, jalankan ulang otomatis, lalu kirim notifikasi email + Telegram.

**Architecture:** Proses watchdog terpisah (`va_daily_watchdog.php`) dijadwalkan 20 menit setelah setiap run utama. Ia membaca penanda `=== run selesai ===` di log, membandingkan file share dengan `state.json`, menjalankan `va_daily.php` sekali bila perlu, lalu mengirim ringkasan. Helper murni dipisah ke `lib/` agar dapat diuji tanpa DB, jaringan, atau share.

**Tech Stack:** PHP 8.3 (procedural), Windows Task Scheduler, ext-curl, PhpSpreadsheet (sudah ada), PowerShell untuk registrasi task.

**Spec:** `docs/superpowers/specs/2026-10-28-va-daily-watchdog-design.md`

## Global Constraints

- Tanpa dependency Composer baru. Gunakan `curl` (sudah aktif) untuk Telegram.
- Rahasia hanya di `.env` (gitignored): `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`. Jangan pernah menulis token ke kode, test, docs, atau commit.
- Timezone `Asia/Jakarta` via `APP_TIMEZONE`, sama seperti `va_daily.php`.
- Minggu bukan hari kerja; watchdog tidak memeriksa slot pada hari Minggu.
- Perilaku booking/email normal `va_daily.php` tidak boleh berubah.
- Semua helper baru harus deterministik: tanpa DB, network, atau filesystem write (kecuali fungsi lock).
- Setiap task diakhiri test yang lulus dan satu commit.

## Review Focus

- Proses dibunuh paksa (`TerminateProcess`) → shutdown marker tidak ditulis; slot harus dianggap belum selesai.
- Watchdog berjalan saat import utama masih aktif → tidak boleh catch-up dan tidak boleh double booking.
- Catch-up juga gagal → alert tetap terkirim dan exit code `1`.
- Log hari ini belum ada → semua slot yang sudah lewat dianggap belum selesai, tanpa crash.
- File `- Copy`, nama tidak valid, atau bertanggal masa depan → diabaikan, bukan dilaporkan sebagai pending.
- Masalah identik berulang pada hari yang sama → tidak dikirim ulang.
- Tepat pada batas grace (mis. 09:50:00 untuk slot 09:30 + 20 menit) → slot sudah dianggap due.

---

### Task 1: Helper murni watchdog

**Files:**
- Create: `lib/va_daily_watchdog_lib.php`
- Test: `tests/va_daily_watchdog_lib_test.php`

**Interfaces:**
- Consumes: `va_needs_processing()` dari `lib/va_daily_lib.php`.
- Produces:
  - `va_watchdog_due_slots(array $times, DateTimeImmutable $now, bool $runDay, int $graceMinutes): array` → list `['time'=>'09:30','ts'=>int]`.
  - `va_watchdog_parse_runs(string $logText): array` → list `['started_at'=>string,'start_ts'=>int,'finished'=>bool,'status'=>?string,'finished_at'=>?string]`.
  - `va_watchdog_unfinished_slots(array $slots, array $runs): array` → subset slot yang belum tertutup run sukses.
  - `va_watchdog_pending_files(array $files, array $state, int $sinceTs, int $staleTs): array`.
  - `va_watchdog_signature(array $problems): string`.
  - `va_watchdog_should_notify(?string $lastSig, ?string $lastDate, string $sig, string $today, bool $force): bool`.

- [ ] **Step 1: Write the failing test**

Buat `tests/va_daily_watchdog_lib_test.php` dengan pola `cek()` yang sama seperti `tests/va_daily_lib_test.php`, lalu assert:

```php
require_once __DIR__ . '/../lib/va_daily_watchdog_lib.php';

$slots = va_watchdog_due_slots(['09:30', '13:00', '17:00'], new DateTimeImmutable('2026-10-28 09:49:00'), true, 20);
cek('slot 09:30 belum due sebelum grace', count($slots), 0);
$slots = va_watchdog_due_slots(['09:30', '13:00', '17:00'], new DateTimeImmutable('2026-10-28 09:50:00'), true, 20);
cek('slot 09:30 due tepat pada grace', array_column($slots, 'time'), ['09:30']);
cek('minggu tidak ada slot', va_watchdog_due_slots(['09:30'], new DateTimeImmutable('2026-10-25 09:50:00'), false, 20), []);

$log = "[2026-10-28 09:31:09] === run ===\n"
     . "[2026-10-28 09:31:24] preview: x\n"
     . "[2026-10-28 13:00:03] === run selesai: fatal ===\n"
     . "[2026-10-28 13:00:05] === run ===\n"
     . "[2026-10-28 13:00:20] === run selesai: done ===\n";
$runs = va_watchdog_parse_runs($log);
cek('parse dua run', count($runs), 2);
cek('run pertama fatal', [$runs[0]['finished'], $runs[0]['status']], [true, 'fatal']);
cek('run kedua selesai', [$runs[1]['finished'], $runs[1]['status']], [true, 'done']);
cek('run tanpa penanda belum selesai', va_watchdog_parse_runs('[2026-10-28 09:31:09] === run ===')[0]['finished'], false);

$due = [['time' => '09:30', 'ts' => 1000], ['time' => '13:00', 'ts' => 2000]];
$runs = [
    ['start_ts' => 1100, 'finished' => false, 'status' => null],
    ['start_ts' => 2100, 'finished' => true, 'status' => 'done'],
];
cek('slot tanpa run selesai', array_column(va_watchdog_unfinished_slots($due, $runs), 'time'), ['09:30']);
cek('status fatal tidak menutup slot', array_column(va_watchdog_unfinished_slots(
    [['time' => '09:30', 'ts' => 1000]],
    [['start_ts' => 1100, 'finished' => true, 'status' => 'fatal']]
), 'time'), ['09:30']);
cek('run telat menutup slot sebelumnya', va_watchdog_unfinished_slots(
    [['time' => '09:30', 'ts' => 1000]],
    [['start_ts' => 1900, 'finished' => true, 'status' => 'done']]
), []);

$now = time();
$files = [
    ['name' => 'A.txt', 'mtime' => $now - 10800, 'size' => 10],
    ['name' => 'B.txt', 'mtime' => $now - 10800, 'size' => 10],
    ['name' => 'C.txt', 'mtime' => $now - 1800, 'size' => 10],
    ['name' => 'D.txt', 'mtime' => $now - 86400 * 40, 'size' => 10],
];
$state = ['files' => ['B.txt' => ['mtime' => $now - 10800, 'size' => 10]]];
$pending = va_watchdog_pending_files($files, $state, $now - 86400 * 10, $now - 7200);
cek('hanya file lama yang belum diproses', array_column($pending, 'name'), ['A.txt']);

$p1 = ['slots' => [['time' => '13:00'], ['time' => '09:30']], 'files' => [['name' => 'B.txt'], ['name' => 'A.txt']]];
$p2 = ['slots' => [['time' => '09:30'], ['time' => '13:00']], 'files' => [['name' => 'A.txt'], ['name' => 'B.txt']]];
cek('signature stabil terhadap urutan', va_watchdog_signature($p1), va_watchdog_signature($p2));
cek('signature berbeda untuk masalah berbeda', va_watchdog_signature($p1) === va_watchdog_signature(['slots' => [], 'files' => []]), false);
cek('notify saat force', va_watchdog_should_notify('x', '2026-10-28', 'x', '2026-10-28', true), true);
cek('tidak notify signature sama di hari sama', va_watchdog_should_notify('x', '2026-10-28', 'x', '2026-10-28', false), false);
cek('notify hari baru', va_watchdog_should_notify('x', '2026-10-27', 'x', '2026-10-28', false), true);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/va_daily_watchdog_lib_test.php`
Expected: FAIL — `Call to undefined function va_watchdog_due_slots()`.

- [ ] **Step 3: Implement `lib/va_daily_watchdog_lib.php`**

Aturan implementasi:
- `va_watchdog_due_slots`: abaikan time yang tidak cocok `/^(\d{1,2}):(\d{2})$/`; due bila `$now >= slot + graceMinutes`.
- `va_watchdog_parse_runs`: regex baris `^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (.*)$`; `=== run ===` membuka run baru; `=== run selesai: (.*) ===` menutup run terakhir yang belum selesai. Run yang masih terbuka tetap `finished=false`.
- `va_watchdog_unfinished_slots`: slot dianggap tertutup bila ada run dengan `start_ts` di `[slot.ts, slot+1.ts)` (slot terakhir: tanpa batas atas) dan `finished === true` dan `status !== 'fatal'`.
- `va_watchdog_pending_files`: sertakan bila `mtime >= $sinceTs`, `mtime <= $staleTs`, dan `va_needs_processing($state['files'][$name] ?? null, ['mtime'=>..,'size'=>..])`; urutkan `mtime` lalu `name`.
- `va_watchdog_signature`: normalisasi slot (`time`) dan file (`name`) dengan `sort()`, lalu `sha1(json_encode([...]))`.
- `va_watchdog_should_notify`: `$force || $lastDate !== $today || $lastSig !== $sig`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/va_daily_watchdog_lib_test.php`
Expected: `OK <n> test`

- [ ] **Step 5: Commit**

```bash
git add lib/va_daily_watchdog_lib.php tests/va_daily_watchdog_lib_test.php
git commit -m "feat(va): add watchdog helper functions"
```

---

### Task 2: Notifier Telegram

**Files:**
- Create: `lib/notifier.php`
- Test: `tests/notifier_test.php`

**Interfaces:**
- Produces:
  - `telegramConfig(): array` → `['token'=>string,'chat_id'=>string]` dari `getenv`.
  - `telegramEnabled(array $cfg): bool`.
  - `telegramTrim(string $text, int $limit = 4000): string`.
  - `telegramApiUrl(array $cfg): string`.
  - `sendTelegram(string $text): array{ok:bool,error?:string,raw?:string}`.

- [ ] **Step 1: Write the failing test**

```php
require_once __DIR__ . '/../lib/notifier.php';

putenv('TELEGRAM_BOT_TOKEN=123:abc');
putenv('TELEGRAM_CHAT_ID=70891352');
$cfg = telegramConfig();
cek('token terbaca', $cfg['token'], '123:abc');
cek('chat id terbaca', $cfg['chat_id'], '70891352');
cek('telegram aktif', telegramEnabled($cfg), true);
cek('url bot api', telegramApiUrl($cfg), 'https://api.telegram.org/bot123:abc/sendMessage');
cek('nonaktif tanpa chat id', telegramEnabled(['token' => 'x', 'chat_id' => '']), false);
cek('teks pendek utuh', telegramTrim('halo'), 'halo');
cek('teks panjang dipotong', strlen(telegramTrim(str_repeat('a', 5000), 100)), 100);
putenv('TELEGRAM_BOT_TOKEN');
putenv('TELEGRAM_CHAT_ID');
cek('kirim tanpa config gagal terkendali', sendTelegram('halo'), ['ok' => false, 'error' => 'telegram not configured']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/notifier_test.php`
Expected: FAIL — `Call to undefined function telegramConfig()`.

- [ ] **Step 3: Implement `lib/notifier.php`**

- `telegramConfig()` membaca `getenv('TELEGRAM_BOT_TOKEN')` dan `getenv('TELEGRAM_CHAT_ID')`, `trim`.
- `telegramTrim` memotong ke `limit` karakter (tanpa ellipsis agar test deterministik).
- `sendTelegram`: bila tidak enabled → `['ok'=>false,'error'=>'telegram not configured']`. Kirim JSON `{chat_id,text,parse_mode:'HTML',disable_web_page_preview:true}` via curl, connect timeout 5s/total 10s, retry sekali, kembalikan `ok` dari HTTP 200 + `raw` body (potong 500 char), `error` dari body/curl.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/notifier_test.php`
Expected: `OK <n> test`

- [ ] **Step 5: Commit**

```bash
git add lib/notifier.php tests/notifier_test.php
git commit -m "feat(va): add telegram notifier"
```

---

### Task 3: Penanda selesai, lock, dan runner bersama di `va_daily.php`

**Files:**
- Modify: `lib/va_daily_lib.php`
- Modify: `va_daily.php`
- Modify: `tests/va_daily_cli_test.php`

**Interfaces:**
- Produces:
  - `va_run_process(array $cmd): array{code:int,stdout:string,stderr:string}` (dipindah dari `va_daily.php`).
  - `va_acquire_lock(string $path)` → resource|false; `va_release_lock($handle): void`.
- Consumes: tidak ada.

- [ ] **Step 1: Write the failing test**

Tambahkan di akhir blok dry-run `tests/va_daily_cli_test.php`:

```php
$logFile = glob($work . '/logs/*.log')[0] ?? '';
$logText = $logFile !== '' ? (string) file_get_contents($logFile) : '';
cek('dry-run menulis penanda selesai', strpos($logText, '=== run selesai: dry-run ===') !== false, true);
```

Dan setelah `$cmd2` (run `--no-email`):

```php
cek('run no-email menulis penanda selesai', strpos((string) file_get_contents($logFile), '=== run selesai: no-email ===') !== false, true);

/* Lock: proses lain harus gagal mengambil lock yang sama. */
$lockPath = $work . '/va_daily.lock';
$lock = va_acquire_lock($lockPath);
cek('lock pertama didapat', is_resource($lock), true);
$lockProbe = [$php, '-r', 'require $argv[1]; var_export(va_acquire_lock($argv[2]) === false);',
              $root . '/lib/va_daily_lib.php', $lockPath];
$probe = proc_open($lockProbe, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
$probeOut = trim(stream_get_contents($pp[1]));
$probeErr = trim(stream_get_contents($pp[2]));
cek('proses kedua melihat lock sibuk', [$probeOut, $probeErr], ['true', '']);
proc_close($probe);
va_release_lock($lock);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/va_daily_cli_test.php`
Expected: FAIL — penanda selesai tidak ditemukan.

- [ ] **Step 3: Implement**

Di `lib/va_daily_lib.php` tambahkan:

```php
function va_run_process(array $cmd): array { /* pindahkan isi va_run() dari va_daily.php */ }
function va_acquire_lock(string $path) {
    $fp = @fopen($path, 'c');
    if ($fp === false) return false;
    if (!@flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return false; }
    return $fp;
}
function va_release_lock($handle): void {
    if (is_resource($handle)) { @flock($handle, LOCK_UN); fclose($handle); }
}
```

Di `va_daily.php`:
- Hapus `function va_run()` lokal; ganti pemakaian `va_run(...)` menjadi `va_run_process(...)`.
- Setelah `$workDir` dan `$log` dibuat, tambahkan:
  ```php
  $runStatus = 'ok';
  register_shutdown_function(static function () use (&$runStatus, $log): void {
      $log('=== run selesai: ' . $runStatus . ' ===');
  });
  $runLock = va_acquire_lock($workDir . '/va_daily.lock');
  if ($runLock === false) {
      $log('run lain sedang berjalan, keluar');
      echo "Run lain sedang berjalan\n";
      exit(0);
  }
  ```
  Lock dilepas otomatis saat proses selesai; tidak perlu release eksplisit.
- Set `$runStatus` sebelum setiap `exit`:
  - bukan hari kerja → `not-workday`
  - share/MySQL fatal (di `$sendFatal`) → `fatal`
  - tidak ada file baru → `no-new-files`
  - tidak ada hasil → `no-results`
  - dry-run selesai → `dry-run`
  - `--no-email` → `no-email`
  - recipients kosong → `no-recipients`
  - setelah email terkirim / email tidak diperlukan → `done`

- [ ] **Step 4: Run tests**

Run: `php tests/va_daily_lib_test.php` dan `php tests/va_daily_cli_test.php`
Expected: keduanya `OK ... test`

- [ ] **Step 5: Commit**

```bash
git add lib/va_daily_lib.php va_daily.php tests/va_daily_cli_test.php
git commit -m "feat(va): mark run completion and serialize imports"
```

---

### Task 4: Penyusun pesan watchdog

**Files:**
- Modify: `lib/va_daily_report.php`
- Modify: `tests/va_daily_report_test.php`

**Interfaces:**
- Produces: `va_watchdog_build_report(array $data): array{subject:string,html:string,text:string}`.
  - `$data`: `['date'=>string,'slots'=>[['time'=>string]],'files'=>[['name'=>string,'mtime'=>int]],'catchup'=>['ran'=>bool,'ok'=>bool,'summary'=>string],'error'=>?string]`.

- [ ] **Step 1: Write the failing test**

Tambahkan ke `tests/va_daily_report_test.php`:

```php
$r = va_watchdog_build_report([
    'date' => '2026-10-28',
    'slots' => [['time' => '09:30']],
    'files' => [['name' => 'Mall 26 Oktober 2026.txt', 'mtime' => time()]],
    'catchup' => ['ran' => false, 'ok' => false, 'summary' => ''],
    'error' => null,
]);
cek('subject peringatan', strpos($r['subject'], 'PERINGATAN 28-10-2026') !== false, true);
cek('subject menyebut run', strpos($r['subject'], '09:30') !== false, true);
cek('html menyebut file', strpos($r['html'], 'Mall 26 Oktober 2026.txt') !== false, true);
cek('teks telegram menyebut file', strpos($r['text'], 'Mall 26 Oktober 2026.txt') !== false, true);

$ok = va_watchdog_build_report([
    'date' => '2026-10-28', 'slots' => [], 'files' => [],
    'catchup' => ['ran' => true, 'ok' => true, 'summary' => '38 dibukukan (Rp102.798.838), 0 skip'],
    'error' => null,
]);
cek('subject catch-up sukses', strpos($ok['subject'], 'catch-up') !== false, true);
cek('teks catch-up berisi hasil', strpos($ok['text'], '38 dibukukan') !== false, true);

$fail = va_watchdog_build_report([
    'date' => '2026-10-28', 'slots' => [], 'files' => [],
    'catchup' => ['ran' => true, 'ok' => false, 'summary' => ''],
    'error' => 'preview gagal',
]);
cek('subject gagal watchdog', strpos($fail['subject'], 'GAGAL WATCHDOG') !== false, true);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/va_daily_report_test.php`
Expected: FAIL — `Call to undefined function va_watchdog_build_report()`.

- [ ] **Step 3: Implement `va_watchdog_build_report`**

- Subject:
  - `error !== null` → `[VA Harian] GAGAL WATCHDOG <d-m-Y> — <error dipotong 120>`
  - `catchup.ran && catchup.ok && slots kosong && files kosong` → `[VA Harian] <d-m-Y> — catch-up: <summary>`
  - lainnya → `[VA Harian] PERINGATAN <d-m-Y> — <n> run belum selesai, <m> file menunggu`
- HTML memakai `va_report_styles()`: daftar slot, daftar file, hasil catch-up, atau error.
- `text` ringkas untuk Telegram: baris judul, slot, file (maks 20 nama), catch-up/error; pakai `htmlspecialchars` untuk HTML, teks polos untuk Telegram.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/va_daily_report_test.php`
Expected: `OK <n> test`

- [ ] **Step 5: Commit**

```bash
git add lib/va_daily_report.php tests/va_daily_report_test.php
git commit -m "feat(va): build watchdog alert report"
```

---

### Task 5: CLI watchdog + auto-catch-up

**Files:**
- Create: `va_daily_watchdog.php`
- Test: `tests/va_daily_watchdog_cli_test.php`

**Interfaces:**
- Consumes: semua helper Task 1, `va_run_process`/`va_acquire_lock` Task 3, `va_watchdog_build_report` Task 4, `sendTelegram` Task 2, `va_send_report` dan `va_state_read/write` yang sudah ada.
- Produces: CLI `php va_daily_watchdog.php [--dry-run] [--no-notify] [--no-catch-up] [--force] [--base-dir PATH] [--work-dir PATH] [--period P] [--now "Y-m-d H:i:s"]`.

- [ ] **Step 1: Write the failing test**

```php
$php = PHP_BINARY; $root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/va_wd_test_' . getmypid();
$baseEmpty = $work . '/base_empty';
@mkdir($work . '/logs', 0777, true);
@mkdir($baseEmpty, 0777, true);
$logPath = $work . '/logs/2026-10-28.log';
$run = static function (array $extra) use ($php, $root, $work): array {
    $cmd = array_merge([$php, $root . '/va_daily_watchdog.php', '--work-dir', $work,
        '--now', '2026-10-28 10:00:00', '--dry-run', '--no-notify'], $extra);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
};

file_put_contents($logPath, "[2026-10-28 09:31:09] === run ===\n[2026-10-28 09:31:24] preview: x\n");
[$code, $out] = $run(['--base-dir', $baseEmpty]);
cek('slot belum selesai terdeteksi', $code, 1);
cek('output menyebut slot 09:30', strpos($out, '09:30') !== false, true);
cek('output menyebut belum selesai', stripos($out, 'belum selesai') !== false, true);

file_put_contents($logPath, "[2026-10-28 09:31:09] === run ===\n[2026-10-28 09:31:30] === run selesai: done ===\n");
[$code, $out] = $run(['--base-dir', $baseEmpty]);
cek('hari normal exit 0', $code, 0);
cek('hari normal tanpa masalah', stripos($out, 'tidak ada masalah') !== false, true);

/* File pending: salin fixture ke folder sendiri, mtime dibuat 3 jam lalu. */
$basePending = $work . '/base_pending'; @mkdir($basePending, 0777, true);
foreach (glob(__DIR__ . '/fixtures/va/*.txt') as $src) {
    $dst = $basePending . '/' . basename($src);
    copy($src, $dst); touch($dst, time() - 3 * 3600);
}
[$code, $out] = $run(['--base-dir', $basePending, '--no-catch-up']);
cek('file pending exit 1', $code, 1);
cek('output menyebut file pending', strpos($out, 'Mall 21 Oktober 2026.txt') !== false, true);

/* Catch-up benar-benar dipanggil; --no-email mencegah email dari child. */
$baseCatch = $work . '/base_catch'; @mkdir($baseCatch, 0777, true);
foreach (glob(__DIR__ . '/fixtures/va/*.txt') as $src) {
    $dst = $baseCatch . '/' . basename($src); copy($src, $dst); touch($dst, time() - 3 * 3600);
}
file_put_contents($logPath, "[2026-10-28 09:31:09] === run ===\n[2026-10-28 09:31:24] preview: x\n");
$cmd = [$php, $root . '/va_daily_watchdog.php', '--base-dir', $baseCatch, '--work-dir', $work,
        '--now', '2026-10-28 10:00:00', '--no-notify', '--period', '__test_period__'];
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); proc_close($p);
cek('catch-up dijalankan', stripos($out, 'catch-up') !== false, true);
cek('catch-up menulis run baru di log', substr_count((string) file_get_contents($logPath), '=== run ==='), 2);

/* Catch-up gagal: base dir tidak ada -> exit 1, error dilaporkan. */
$cmd = [$php, $root . '/va_daily_watchdog.php', '--base-dir', $work . '/tidak-ada', '--work-dir', $work,
        '--now', '2026-10-28 10:00:00', '--no-notify', '--period', '__test_period__'];
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); $code = proc_close($p);
cek('catch-up gagal exit 1', $code, 1);
cek('output menyebut catch-up gagal', stripos($out, 'catch-up') !== false && stripos($out, 'gagal') !== false, true);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/va_daily_watchdog_cli_test.php`
Expected: FAIL — file `va_daily_watchdog.php` belum ada.

- [ ] **Step 3: Implement `va_daily_watchdog.php`**

Alur:
1. `require env.php`, `lib/va_daily_lib.php`, `lib/va_daily_watchdog_lib.php`, `lib/va_daily_report.php`, `lib/notifier.php`; set timezone.
2. Baca config + args (`--now` untuk test, default `date('Y-m-d H:i:s')`).
3. `$dirs` logs/skips/preview; log function dengan prefix `[watchdog]`.
4. Ambil lock `watchdog.lock`; bila gagal → log + exit 0.
5. Bila Minggu → log + exit 0.
6. `$slots = va_watchdog_due_slots(config['schedule_times'], $now, true, config['watchdog_grace_minutes'])`.
7. Baca `logs/<tanggal>.log` (boleh kosong), `va_watchdog_parse_runs`, `va_watchdog_unfinished_slots`.
8. Scan file: folder induk + `<MM>. <Bulan>` bulan berjalan dan sebelumnya (duplikasi aturan `va_daily.php`), `*.txt`, `va_parse_report_filename`, lewati mtime > besok; lalu `va_watchdog_pending_files($files, $state, now - lookback_days*86400, now - stale_file_hours*3600)`.
9. Bila ada masalah dan `auto_catch_up` dan bukan `--no-catch-up` dan bukan `--dry-run`:
   - cek lock import: `va_acquire_lock(work_dir/va_daily.lock)`; bila gagal → import sedang jalan, jangan catch-up.
   - bila berhasil, release lalu jalankan `va_run_process([PHP_BINARY, __DIR__.'/va_daily.php', '--work-dir', $workDir, '--base-dir', $baseDir, '--no-email'] + period)`. `--no-email` wajib: notifikasi catch-up dikirim oleh watchdog, bukan oleh child (mencegah email ganda dan email fatal saat `--no-notify`).
   - catat exit code, `ok = code === 0`; ringkasan = baris terakhir yang mengandung `DIBUKUKAN`/`TANPA PERIODE`/`Tidak ada file baru`, atau `stderr` bila gagal.
   - baca ulang log/state, hitung ulang slot & file.
10. Susun `problems` = `['slots'=>..., 'files'=>..., 'catchup'=>...]`; `sig = va_watchdog_signature`.
11. Tentukan notify: `catchup.ran || slots/file tersisa`; cek dedupe `watchdog.json` dengan `va_watchdog_should_notify`.
12. Bila notify dan bukan `--dry-run`/`--no-notify`: kirim email via `va_send_report` ke `recipients` dan Telegram via `sendTelegram($report['text'])` bila `telegramEnabled`; log hasil per kanal.
13. Simpan `watchdog.json` (`lastSignature`, `lastDate`, `lastNotifiedAt`) hanya bila notifikasi terkirim (atau dry-run tidak menulis).
14. Log ringkasan; exit `1` bila masih ada slot/file bermasalah atau catch-up gagal, selain itu `0`. Jika `--dry-run`, tetap hitung dan cetak, tanpa menjalankan catch-up, tanpa notifikasi, tanpa menulis `watchdog.json`.

- [ ] **Step 4: Run test**

Run: `php tests/va_daily_watchdog_cli_test.php`
Expected: `OK <n> test`

- [ ] **Step 5: Commit**

```bash
git add va_daily_watchdog.php tests/va_daily_watchdog_cli_test.php
git commit -m "feat(va): add daily VA watchdog with auto catch-up"
```

---

### Task 6: Konfigurasi, task scheduler, dan dokumentasi

**Files:**
- Modify: `va_daily.config.php`
- Modify: `.env.example`
- Modify: `.env` (lokal, tidak di-commit)
- Modify: `setup_va_daily_task.ps1`
- Modify: `docs/va-daily-import.md`

**Interfaces:**
- Consumes: `va_daily_watchdog.php` Task 5.

- [ ] **Step 1: Update config**

Tambahkan ke `va_daily.config.php`:

```php
'schedule_times' => ['09:30', '13:00', '17:00'],
'watchdog_grace_minutes' => 20,
'stale_file_hours' => 2,
'auto_catch_up' => true,
```

- [ ] **Step 2: Update `.env.example` dan `.env`**

`.env.example`:

```
# Telegram alerts (opsional; kosongkan untuk mematikan)
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

`.env` (jangan commit): isi token dan chat ID yang diberikan user.

- [ ] **Step 3: Update `setup_va_daily_task.ps1`**

- Tambah parameter `[string[]]$WatchdogTimes = @('09:50', '13:20', '17:20')` dan `[switch]$SkipWatchdog`.
- Faktorkan pembuatan trigger/action; daftarkan task kedua `M2S VA Daily Watchdog` dengan action `php va_daily_watchdog.php`, working directory sama, settings sama.
- Verifikasi: cetak kedua task + jumlah trigger; gagal bila jumlah trigger tidak sesuai.
- Idempoten (`-Force`).

- [ ] **Step 4: Update `docs/va-daily-import.md`**

Tambahkan bagian:
- Tabel jadwal: run utama + watchdog.
- Cara kerja watchdog, flag, `watchdog.json`, exit code, arti notifikasi.
- Setup Telegram: BotFather → token, chat ID via `getUpdates`, isi `.env`, uji `sendTelegram`.
- Troubleshooting: alert tidak masuk, catch-up gagal, lock.

- [ ] **Step 5: Verifikasi konfigurasi**

Run: `php -l va_daily.config.php` dan `powershell -NoProfile -Command "Get-Command -Syntax"` (atau `Get-Help` pada script).
Expected: tidak ada syntax error.

- [ ] **Step 6: Commit**

```bash
git add va_daily.config.php .env.example setup_va_daily_task.ps1 docs/va-daily-import.md
git commit -m "chore(va): configure watchdog schedule and telegram alerts"
```

---

### Task 7: Verifikasi end-to-end dan registrasi task

**Files:**
- Tidak ada file baru.

- [ ] **Step 1: Jalankan seluruh test**

```bash
php tests/va_daily_lib_test.php
php tests/va_daily_report_test.php
php tests/va_daily_cli_test.php
php tests/va_daily_watchdog_lib_test.php
php tests/notifier_test.php
php tests/va_daily_watchdog_cli_test.php
```

Expected: semua `OK ... test`, exit code 0.

- [ ] **Step 2: Dry-run watchdog**

Run: `php va_daily_watchdog.php --dry-run --no-notify`
Expected: melaporkan status slot hari ini; tidak menjalankan catch-up dan tidak mengirim notifikasi.

- [ ] **Step 3: Uji kirim Telegram**

```bash
php -r "require 'env.php'; require 'lib/notifier.php'; var_export(sendTelegram('Test notifikasi VA Harian dari portal M2S.'));"
```

Expected: `['ok' => true]`.

- [ ] **Step 4: Registrasi task**

```bash
powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1
```

Expected: dua task terdaftar (`M2S VA Daily Import`, `M2S VA Daily Watchdog`). Bila akses ditolak, laporkan agar dijalankan sebagai Administrator.

- [ ] **Step 5: Verifikasi jadwal**

```bash
Get-ScheduledTask -TaskName 'M2S VA Daily Watchdog' | Select-Object TaskName, State
(Get-ScheduledTask -TaskName 'M2S VA Daily Watchdog').Triggers
```

Expected: `Ready`, tiga trigger 09:50/13:20/17:20.

- [ ] **Step 6: Commit sisa perubahan (jika ada)**

```bash
git status --short
# commit hanya file yang relevan; .env dan data/va_daily tidak boleh ikut
```
