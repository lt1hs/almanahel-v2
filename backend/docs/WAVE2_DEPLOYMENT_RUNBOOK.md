# Wave 2 checkpoint — branch catalog + lot status

Wave 2 adds branch-scoped book visibility and lot lifecycle status. **Do not start Wave 3** from this runbook until Wave 2 is verified in your environment.

## Migrations (order)

1. `2026_08_23_120001_create_branch_catalog_items.php`
2. `2026_08_23_120002_add_status_to_stock_lots.php`

Run after Wave 1 migrations:

```bash
php artisan migrate --force
php artisan catalog:backfill-items          # dry run
php artisan catalog:backfill-items --apply
php artisan catalog:list-legacy-unknown     # admin reconciliation
```

## What shipped

- **`branch_catalog_items`**: unique `(branch_id, book_id)`; `source` ∈ `central|transferred|local|legacy_unknown`
- **`stock_lots.status`**: `available` (default), `quarantined`, `blocked`
- FIFO / sales / gifts / transfers / consignment returns / aggregate sync exclude non-`available` lots
- **ISBN reuse**: existing canonical `books.id` + new catalog row for the creating branch only
- **API**: `GET /branch-catalog` (branch-scoped; admin `aggregate=1` read-only)
- **Book endpoints**: list/barcode/show scoped to branch catalog for non-admins; `POST /books` accepts `branch_id` and returns `{ book, catalog_item, reused_canonical }`
- **Intake/transfers**: `StockLotService::createIntakeLot` stamps `local` or `transferred` catalog rows
- **Backfill**: never guesses `central`/`local` from branch type; unproven rows → `legacy_unknown`

## Out of scope (Wave 3+)

- No-receipt returns / quarantine workflow UX
- Purchase returns
- Gift operational_status
- Idempotency keys

## Tests

```bash
php artisan test --group=catalog
php artisan test
```

## Deploy note

After migrate, run `catalog:backfill-items --apply` before serving traffic so non-admin book search/barcode does not return empty catalogs for existing stock.
