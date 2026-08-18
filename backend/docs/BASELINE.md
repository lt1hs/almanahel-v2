# Phase 0 Baseline (2026-08-15)

## Git
- Branch: `main` tracking `origin/main`
- Untracked ignored for this work: `almanahel.zip`, `almanahel/`

## Backend tests (pre-change)
- `php artisan test`: 2 passed (Example unit + feature)

## Frontend (pre-change)
- `npm run lint`: 114 errors, 17 warnings (mostly `no-explicit-any`)
- `npx tsc --noEmit`: multiple errors (admin page handlers, inventory new, sales inventory_id, Sidebar motion, bookFormUtils nullability)
- `next.config.ts`: `eslint.ignoreDuringBuilds` + `typescript.ignoreBuildErrors` enabled
- Fonts: `next/font/google` IBM Plex Sans Arabic (requires network at build)

## Known contract quirks (characterization targets)
- `GET /reports/iraq-profit` returns `iraq_only_revenue`, `distributed_revenue`, `total_iraq_revenue` (UI expects P&L fields)
- Invoice create trusts client `unit_price` / `actual_price` / `branch_id`
- Customer return trusts client `unit_price`; `invoice_item_id` optional
- Settlement preview is period-sales based; settle FIFO across all unsettled receipts
- Inventory: no unique `(branch_id, book_id)`; transfers do not move consignment receipts
