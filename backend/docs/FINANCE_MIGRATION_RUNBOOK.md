# Finance migration runbook

## Deployment order (all waves)

**Schema before traffic.** Put the app in maintenance (`php artisan down`), run
`php artisan migrate --force`, then `php artisan optimize:clear`, then data backfills,
then smoke tests, then `php artisan up` and frontend swap.

Never serve Wave 1+ code while Wave 1 migrations are still `Pending`. Missing columns
such as `suppliers.identity_origin` break `GET /api/suppliers` at the SQL layer; fix
deployment order — do not patch controllers to hide the error.

Wave 1 supplier-account cutover: see `backend/docs/WAVE1_DEPLOYMENT_RUNBOOK.md`.

## Current

Finance migrations `2026_08_18_11000*` through `2026_08_20_120001` and Wave 1
`2026_08_22_11000*` / `2026_08_22_120001` are **undeployed on production** until an
operator runs migrate. Do not change production `.env` from this session.

V2 posting is already the only engine; `FINANCE_POSTING_V2_ENABLED` cannot restore `LedgerPoster`. Ledger reports default `FINANCE_LEDGER_REPORTS_ENABLED=true`.

When applying: `php artisan migrate --pretend` first.

## T1 unique indexes

`up()`: create `journal_source_event_version_unique` **before** dropping `journal_source_event_unique`.
`down()`: restore the old unique **before** dropping the versioned unique. Guard throws if `version > 1`.

## T4 down()

Never `DELETE ... LIKE 'sys.%'`. If journal lines or financial_accounts reference the chart, throw. Drop `financial_accounts` only when unused. Leave `sys.*` ledger rows in place.

After any journal has `version > 1`:

- **Do not** `php artisan migrate:rollback` this migration on production.
- Compensate with reversal + replacement journals.

The migration `down()` must:

- detect `version > 1` (or duplicate source/event that would violate the old unique);
- throw a clear exception;
- never delete or merge journal versions;
- restore `journal_source_event_unique` only when every row is `version = 1`.

## Restatement (T10, not yet)

```
php artisan finance:restate-consignment-payables
# dry-run, no writes

php artisan finance:restate-consignment-payables --apply
# writes only when this flag is present
```

## T9/T10 notes (not an instruction to deploy)

Posting V2 is already the only runtime. Ledger reports use `LedgerReportService` with `FINANCE_LEDGER_REPORTS_ENABLED=true` in example/config.

Read-only helpers: `php artisan finance:t9-preflight`, `php artisan finance:reports-preflight --format=json`, `php artisan finance:bootstrap-accounts`, `php artisan finance:prepare-t9`.

## Rollback

- `FINANCE_POSTING_V2_ENABLED=false` does **not** restore `LedgerPoster`.
- Once V2 journals exist, do **not** roll back journal migrations.
- If V2 events exist, use reversal / forward correction. Never delete journals.
- Never run `php artisan migrate:rollback` blindly on production.
