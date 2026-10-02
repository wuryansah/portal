---
name: m2s-portal
description: Guide for working with the M2S Customer Portal codebase at C:\laragon\www\portal (Mangga Dua Square). Use when editing, debugging, or extending the mall tenant portal — its PHP/MySQL API, the app.js SPA frontend, billing, WhatsApp billing delivery, CSV imports, support tickets, news, documents, or users. Covers architecture, file roles, API endpoints, auth/roles, billing data model, and key conventions. Trigger on files like index.php, api/index.php, assets/js/app.js, assets/js/models/billing-wa.ts, data/init_db.php, and on topics like billing, WhatsApp/fonnte/wablas/twilio, VA import, CSV, tickets, audit logs.
---

# M2S Customer Portal

"Customer Portal" / "Mall Portal" for **Mangga Dua Square** (M2S), a shopping
mall tenant-management web app. Built by Wuryansah (© 2026).

## Stack overview

- **Backend**: PHP (procedural, single-file API router) + MySQL (`mall_portal` DB via mysqli).
- **Frontend**: single-page app driven by `assets/js/app.js` (plain JS, ~3500 lines) with a custom router. Uses `Chart.js` (`assets/vendor/chart.umd.min.js`) and Font Awesome, Google Fonts (Inter).
- **Database schema**: defined in `data/init_db.php` (also re-seeds demo data).
- **Local env**: Laragon on Windows; MySQL at `127.0.0.1:3306` user `root` no password, database `mall_portal`.

## File map

| Path | Role |
|------|------|
| `index.php` | Front controller. Routes `/api*` to `api/index.php`; otherwise serves the SPA HTML shell. |
| `api/index.php` | **Entire REST API** (auth, billing, payments, news, docs, tickets, admin, WhatsApp billing). ~1388 lines. |
| `assets/js/app.js` | SPA client: state, `api()` fetch helper, page renderers (`renderX`), navigation, forms, charts. |
| `assets/js/models/billing-wa.ts` | TypeScript models + builders for WhatsApp billing messages/payloads (reference; not compiled into app.js at build time). |
| `assets/css/style.css` | All styling, light/dark themes, responsive layout. |
| `assets/img/`, `assets/vendor/` | Images and vendored Chart.js. |
| `data/init_db.php` | Creates tables + seeds demo accounts/billing/news/docs/tickets. |
| `data/create_db.php` | Database bootstrap helper. |
| `uploads/documents/`, `uploads/news/` | Uploaded files (admin document/news uploads). |
| `import_va_sep2026_preview.php` | Ad-hoc VA bank report preview (parses daily `.txt` reports → XLS) and `import_va_sep14_to_sep26.php` (books a run's matched rows to billing). Not part of the app runtime. |

## Key conventions

- **Prepared statements everywhere.** Use the helpers in `api/index.php` rather than raw queries:
  - `fetchOne($sql, $types, ...$params)` → assoc row or `null`.
  - `fetchAll($sql, $types, ...$params)` → array of assoc rows.
  - `querySingle($sql, $types, ...$params)` → `['affected'=>, 'insert_id'=>]`.
  - After a `querySingle` INSERT, the new id is `$db->insert_id`.
- **Response helpers**: `jsonSuccess($data)` and `jsonError($msg, $code=400)`. Both `exit`. API returns JSON with `Content-Type: application/json`; global error handlers convert PHP errors into 500 JSON.
- **DB connection**: `new mysqli('127.0.0.1','root','','mall_portal',3306)`. If it fails → 500 JSON `DB connection failed`.
- **Sessions** used for auth (`$_SESSION['user_id']`); session lifetime 86400s.
- **Billing amount math**: store `paid` and `amount` as DECIMAL; compute outstanding as `amount - paid`. Statuses: `pending` / `paid` (also `overdue` possible). A bill with `status != 'paid'` is treated as unpaid/outstanding.
- **Normalizers** in `api/index.php`:
  - `normalizeVa($va)` — strips non-alnum, strips BOM/science-notation VAs, and **prefixes `00535`** to virtual account numbers.
  - `normDate($d)` — normalizes many date formats / timestamps to `Y-m-d`.
  - `normalizeWaPhone($phone, $countryCode='62')` — strips non-digits, `+`→ no prefix, leading `0`→ `62`.
- **CSV parsing**: several endpoints use a quoted-CSV-parsing closure with support for `,`/`;` separators, quoting, and BOM stripping. Headers are lowercased, whitespace/`-`/`.` → `_`, matched against alias maps.
- **Audit trail**: admin mutating actions insert into `audit_logs (user_id, action, details)`.

## Roles & auth

Roles on `users.role`: `customer`, `finance`, `admin`, `super_admin`.

- `requireAuth()` — must have session + valid user, else 401.
- `requireAdmin()` — requires `admin`/`finance`/`super_admin`, else 403.
- Frontend: admin nav (`adminNav`) shown only when role is one of those.

Demo accounts (seeded by `data/init_db.php`, re-run to reset): passwords all `password123`.
- Customer: unit `203` / `juan@example.com`, unit `105` / `maria@example.com`
- Admin: `MGT` / `admin@mall.com`
- Finance: `FIN` / `finance@mall.com`

## API surface

Routes live in `api/index.php`. The SPA calls them via `api(method, path, data)` in `app.js`, which prefixes `/api/index.php`.

- **Auth**: `POST /auth/login` (unit_number+password), `POST /auth/logout`, `GET /auth/me`, `GET|PUT /auth/profile`, `PUT /profile/password`.
- **Customer**: `GET /dashboard`, `GET /billing`, `GET /billing/{id}`, `GET /payments`, `GET /documents`, `GET /news` (public), `GET /faq` (public, static), `GET /tickets`, `POST /tickets`, `GET|POST /tickets/{id}/messages`, `POST /tickets/{id}/read`.
- **Webhook**: `POST /wa-webhook` — provider posts WhatsApp delivery status; maps status text → `sent`/`received`/`failed` and updates the matching billing row's `wa_status`/`wa_message_id`.
- **Admin** (prefix `/admin`; requireAdmin): dashboard, billing CRUD + `POST /billing/upload` (CSV), `POST /va-import`, `POST /payments/upload`, users CRUD + `POST /users/upload`, news CRUD + `POST /news/upload`, documents CRUD + `POST /documents/upload`, tickets + messages + read, `GET /logs`, `GET|PUT /wa-settings`, `POST /wa-billing/send`.
- **Server-side sort** on Manages Billing: `GET /admin/billing` accepts `sort=<field>&order=asc|desc`. Whitelisted sort fields: `id, amount, paid, due_date, billing_period, invoice_no, unit_number, user_name` (mapped to real columns; invalid keys fall back to `b.id DESC`). `app.js` `sortAdminBills(key)` toggles asc/desc, resets to page 1, and reloads from the server; `renderBillPagination()` updates the header sort arrows (fa-sort-up/down).

## Billing data model

`billing` columns: `user_id, unit_number, invoice_no, amount, paid, status, due_date, issued_date, description, category, billing_period, virtual_account, wa_status, wa_sent_at, wa_message_id`, plus breakdown amounts: `other_rev, electricity, water, gas, sinking_fund, fine, rent, service_charge`.

- `amount` is the total; breakdown columns sum up to it.
- `virtual_account` is the auto-normalized `00535`-prefixed VA shown to tenants.
- When marking a bill paid via the admin UI, code inserts a matching `payments` row (method, generated reference) if one doesn't exist; setting status back to `pending` deletes it.
- CSV billing upload (`/admin/billing/upload`) maps header aliases (e.g. `total`→amount, `invtrx_ot`+`invtrx_ut`→other_rev for `other_rev` when absent).

### Bank Virtual Account (R-5401) payment report

Bank VA payment reports are plain-text (`LAPORAN TRANSAKSI VIA E-BANKING & COUNTER`, report `R-5401`, cabang `0005910-KCP MANGGA DUA SQUARE`) stored in fixed-width `.txt` files on the finance share:

```
\\ITNAS\pom\FINANCE - AR\AR & Collection\Virtual Account\Virtual Account 2026\
  <Ruko|Mall> <DD> <Month> <YYYY>.txt
```

- File name encodes scope (Ruko/Mall) and the report date (e.g. `Mall 02 September 2026.txt`).
- Data rows are **fixed-width**, not CSV. Columns: `NO.`, `NO.PELANGGAN/NO.TXN` (bank VA), `NAMA PELANGGAN` (unit code, no dash), `NILAI TRANSAKSI` (amount, e.g. `IDR 1,891,201.00`), `TGL. TXN` (`DD/MM/YY`), `WAKTU`, `LOKASI`, `KETERANGAN1/2`. Trailing `SUB TOTAL` / `TOTAL` lines must be ignored.
- **Matching `NAMA PELANGGAN` → `unit_number`**: the bank report's `NAMA PELANGGAN` (e.g. `GF0C143`) must be converted to a DB `unit_number` (e.g. `GF-0C143`). The conversion rule depends on the prefix:

  **Standard rule (2-5 split)** — most units:
  1. Insert dash after 2nd character: `XX` + `-` + `XXXXX` → e.g. `GF0C143` → `GF-0C143`, `B1CA11` → `B1-CA11`, `LGCA22` → `LG-CA22`
  2. If the character after the dash (index 4 in the full string) is `P`, replace it with `0`: `L1AP020` → `L1-A020` → `L1-0A020`
  3. Fallback: match by last 3-4 digits of the numeric suffix against all unit numbers in DB.

  **KKM rule (3-4 split)** — units starting with `KKM`:
  1. Insert dash after 3rd character: `KKM` + `-` + `XXXX` → e.g. `KKMA003` → `KKM-A003`, `KKMB007` → `KKM-B007`

  **FT rule (1-2-1 split)** — units starting with `FT`:
  1. Insert dash after 1st char, then dash after 3rd char: `F` + `-` + `TX` + `-` + `X` → e.g. `FTXX` → `F-TX-X`

  **Alternative match via bank VA number**: the `NO.PELANGGAN` (bank VA number, 13 digits) trailing digits also match the user's `00535...`-prefixed `virtual_account` suffix. E.g. bank `4030003000156` → VA `005354030003000156` → unit `GF-0C156`. Use this as a fallback when the name-based conversion fails.
- **Aug-26 vs Sep-26 period names**: billing `billing_period` uses `Aug-26` (not `2026-08`) and `Sep 2026` (inconsistent). "Agustus 2026" = `Aug-26`. A report named *"September 2026"* nonetheless holds transactions dated 01-02 Sep 2026 that the user may want booked to the **Aug-26** billing period.
- **Generic import scripts** (work for any month/date/period — CLI args, no hardcoded names):
  - `php import_va_preview.php --base-dir <share folder> --month <Month> --year <YYYY> --days <DD[,DD,...]> [--out <file.xls>] [--period <period>]` — builds `Ruko|Mall <DD> <Month> <YYYY>.txt` from args, parses+matches, writes the 4-sheet preview XLS (All Transactions / Summary by File / Unmatched / Summary by Unit) with `Matched`=YES/NO per row.
  - `php import_va_record.php --xls <preview.xls> --period <billing_period> [--files "<File1.txt>,<File2.txt>"] [--user <id>] [--yes]` — books matched rows from the preview to the given billing period. Dedup is scoped to `(reference, billing_period)` because the `VA-<13-digit>` reference is the tenant's **reusable** bank VA. `--files` restricts which source report files to book. Default audit user 3 (finance); writes `record_va_payments_<period>` audit log rows. **Any skipped payment/billing row is printed + written to `va_skip_review_<period>.csv` and the run prompts the admin to confirm before committing** (see skill `va-import-review`; `--yes` auto-approves for reviewed runs).

## WhatsApp billing delivery

Two delivery modes (config in `wa_settings` row id=1):

- **`link` mode**: build `https://wa.me/<phone>?text=<encoded message>`; marks bill `wa_status='sent'`; no token needed.
- **`api` mode**: uses `sendWaApi($provider, $token, $from, $phone, $message, $scheduledAt, $pdfUrl)` in `api/index.php`. Supported providers:
  - `fonnte` — `https://api.fonnte.com/send` (form-encoded, `Authorization: token`, countryCode 62).
  - `silvanix` — `SILVANIX_API_URL` const, same payload shape.
  - `wablas` — `https://patp.wablas.com/api/send-message` or `.../send-document` (JSON).
  - `twilio` — token `accountSid:authToken`, uses basic auth, WhatsApp prefix.
  - `whatsapp_cloud` / `meta` — Graph API `v21.0`, `from` = Phone Number ID, Bearer token.
- Outcome always `['success'=>bool, 'message_id'=>?, 'raw'=>?, 'error'=>?]`; on success billing gets `wa_status='sent'`, `wa_sent_at=NOW()`, `wa_message_id=$mid`.
- The TS reference (`models/billing-wa.ts`) has `normalizeWaPhone`, `formatWaMoney`, `buildWaBillingMessage` (template placeholders `{name} {unit_number} {invoice_no} {amount} {due_date} {virtual_account}`, optional breakdown), `buildWaLink`, and `buildWaApiPayload`.
- Template placeholders in `wa_settings.template` are the same as the TS builder's.

## Useful commands

Start/init DB (run from `/data`): `php init_db.php` — re-creates tables and reseeds all demo data (wipes existing rows).

Local dev: serve through Laragon (Apache + PHP + MySQL) at `http://portal.test/` (or the folder's vhost). SPA entry is `index.php`; API is `api/index.php`.

## Pitfalls / gotchas

- The real DB is MySQL `mall_portal` (the legacy SQLite `data/portal.db` was removed).
- `data/init_db.php` **deletes all rows** (users, billing, payments, news, docs, tickets, logs) before reseeding — destructive.
- VA values from Excel often arrive in scientific notation (e.g. `5.35E+08`); `normalizeVa()` returns `''` for those, and CSV imports emit a warning telling the user to reformat the column as text. Don't "fix" this silently.
- Frontend state is global in `app.js` (`currentUser`, `currentPage`, `chartInstance`, `carouselInterval`). Page renderers are keyed by page name via `navigate(page)`.
- Base path is computed and used for API URLs; don't hardcode `/api/...` — use the `api()` helper.
- When adding a new admin endpoint, remember `ensureWaBillingColumns()`, `ensureTicket*` helpers are callable to lazily add columns/tables on MySQL 5.x that lacks them.
