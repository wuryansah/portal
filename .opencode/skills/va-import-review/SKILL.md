---
name: va-import-review
description: Mandatory admin review of any payment or billing rows skipped during VA bank report imports. Use when running import_va_record.php / import_va_preview.php (or any payment/billing booking script) — whenever a row is skipped (duplicate, no billing, unmatched unit, no user, zero amount) you MUST show the skipped data to the admin/user, write it to the va_skip_review_*.csv review file, and get explicit confirmation before committing any payment.
---

# VA Import: Skip Review

Bank VA report imports must **never silently skip** a payment or billing row.
Anything that is not booked is data the finance admin has to see and approve.

This workflow is enforced by `import_va_record.php`. Follow it whenever you run
that script or any other import/booking routine.

## When this applies

- Any run of `import_va_record.php` (`php import_va_record.php --xls ... --period ...`).
- Any preview of report files (`import_va_preview.php`) where rows come out unmatched.
- Any future/other payment or billing booking code in this repo.

## Why skips happen (reason codes)

| Reason            | Meaning                                                            | Category |
|-------------------|--------------------------------------------------------------------|----------|
| `duplicate`       | Payment reference `VA-<va>` already exists for the same billing period | payment |
| `no billing`      | Unit has no `billing` row for the given billing period             | billing  |
| `unmatched`       | VA name could not be converted to a unit (or `Matched` != YES)     | payment  |
| `no user`         | Unit number has no `users` row / user id                           | payment  |
| `zero amount`     | Transaction amount <= 0                                            | payment  |

Duplicates are normal — the bank VA number is the tenant's reusable VA, so the
same reference legitimately reappears month after month. Dedup is scoped to
`(reference, billing_period)`, never global.

## Orchestration steps

1. **Run the record script.** It analyses the workspace first, then:
   - queues rows that can be booked,
   - collects every skipped row with its reason,
   - prints `=== SKIPPED ITEMS FOR REVIEW ===` (one line per skipped row),
   - writes `va_skip_review_<period>.csv` (same dir as the script) with columns
     `Row, Source File, VA Number, VA Name, Unit Number, Amount, Txn Date, Time, Reason`.

2. **Present the skips to the admin/user** (in chat):
   - Show the per-reason breakdown (`analysis` section),
   - Read out the skipped rows (or the CSV path when there are many),
   - Present the queued booking count and ask for explicit approval:
     `Book <N> payments and skip <M>? Accept (y) or abort (N)?`

3. **Get explicit confirmation before anything is written.** Nothing touches the
   database until the user approves. `fgets(STDIN)` prompt: answer `y`/`Y` to
   commit, anything else (including EOF / no answer) aborts with *"ABORTED -
   nothing written to the database"*.

4. **On approval**, the script runs the booking in a transaction: inserts
   `payments` (method `virtual_account`, reference `VA-<va>`, `paid_at` from the
   report txn date+time), updates `billing.paid/status` (`paid`/`partial`), and
   writes a `record_va_payments_<period>` audit row noting inserted/skipped counts
   per reason plus the review-file name.

5. **On abort**, report that no data was changed and point the admin at the
   review CSV so they can fix the skipped rows (create billing, add users,
   correct unit mapping) and re-run.

## Automation / CI

Use `--yes` to skip the interactive prompt and commit automatically. Only use it
when the admin has already reviewed the same preview file. The preview XLS
(`import_va_preview.php`) plus the `va_skip_review_*` CSV are the artifacts the
admin reviews; do not invent a `--yes` run without those having been seen.

## Never do

- Do not edit the DB to "force" a skipped row without asking.
- Do not count skipped rows as errors and pass on silently.
- Do not commit when the prompt is unanswered (abort is the safe default).