# Operations & rollback

## Scheduler (production)

```bash
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled:
- `alerts:generate` hourly — persist low-stock / check / credit alerts
- `receivables:mark-overdue` daily — credit invoices past due → `overdue`
- `ledger:reconcile` daily — fail if unbalanced journals

Also useful:
- `php artisan finance:reports-preflight --format=json` — ledger vs operational recon (fails non-zero)
- `stock:backfill-lots` after deploy of lots migration
- `stock:reconcile-lots` for drift reports
- `suppliers:backfill-accounts` after Wave 1 migrations (dry-run, review, then `--apply`)
- `php artisan queue:work` if queues enabled (default sync)

Wave 1 supplier-account deploy order: `backend/docs/WAVE1_DEPLOYMENT_RUNBOOK.md`.
Always `php artisan migrate --force` and `optimize:clear` before `php artisan up`.

## Migrations

All new migrations include `down()`. Prefer:

```bash
php artisan migrate
# rollback last batch only if needed
php artisan migrate:rollback --step=1
```

Never restore financial history by deleting rows; reverse with journal reversals / new settlements.

## Production cutover (lot + ledger)

Do **not** run this against production until `php artisan test` and `stock:backfill-lots --dry-run` on a restored copy succeed.

1. **Mandatory backup** of MySQL and `storage/`.
2. Put the app in maintenance / read-only if live sales are running.
3. Restore a copy of production data to a staging DB. Never `migrate:fresh` except on isolated test DBs.
4. `php artisan stock:backfill-lots --dry-run` — abort if mixed-currency/ownership groups or unmatched consignment qty look wrong without a human review list.
5. `php artisan migrate` (additive). The lot migration does **not** delete duplicate inventory rows.
6. `php artisan stock:backfill-lots` (idempotent). Re-run is safe.
7. `php artisan stock:reconcile-lots` — abort if mismatches > 0.
8. `php artisan ledger:reconcile` — abort if unbalanced journals.
9. Spot-check supplier payable preview for one Toman and one Dinar supplier.
10. **Abort thresholds:** any inventory vs lot mismatch, any unbalanced journal, dry-run unmatched consignment qty that was not expected.
11. **Rollback:** restore the MySQL dump. `migrate:rollback` of lot tables **cannot** reconstruct deleted history; this path is additive so rollback is last resort. Forward-fix with reversing journals / new settlements, never hard-delete financial rows.

If an older build of `2026_08_15_200001` already deleted duplicate inventory rows in an environment, restore from backup taken **before** that migration. Snapshots cannot recreate those rows.

## Rollback notes

- Stock lots: `down()` drops lot tables; restore from backup if production already sold against lots.
- Ledger: shadow posting; safe to disable callers if needed.
- Branch capabilities: columns dropped on down; IntakePolicy falls back to city/country.
