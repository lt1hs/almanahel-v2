# Phase 0 Baseline (2026-08-18)

Supersedes the 2026-08-15 snapshot for finance remediation. See [FINANCE_REMEDIATION_PLAN.md](FINANCE_REMEDIATION_PLAN.md).

## Git

- Branch: `main` at `7e590f5` tracking `origin/main`
- Untracked ignored for this work: `almanahel.zip`, `almanahel/`, `deployment/`

## Backend tests (2026-08-18, before Phase 1)

- `php artisan test`: **34 passed** (142 assertions)
- `php artisan migrate:status`: pending `2026_08_17_200001_make_books_author_nullable` on this local database (unrelated)

## Frontend (2026-08-18)

- `npx tsc --noEmit`: pass
- `npm run lint`: **102 errors, 16 warnings** (118 problems). Do not claim lint success.
- `NEXT_PUBLIC_API_URL=https://dar-almanahel.com/api npm run build`: pass. [`frontend/next.config.ts`](../../frontend/next.config.ts) does **not** set `eslint.ignoreDuringBuilds` or `typescript.ignoreBuildErrors`. The production build succeeded with TypeScript checking already clean; ESLint is a separate `npm run lint` baseline (102 errors, 16 warnings) and is not ignored by Next.

## Known contract quirks (characterization)

- Dashboard `inventory_value_*` is owned inventory **at cost**, not cash
- `GET /reports/iraq-profit` splits at allocation `origin_scope`; currencies separate
- `GET /reports/all-branches` has no combined `pending_credit`
- `GET /reports/top-books` groups by currency and nets returns
- Settlement preview `items[]`: `kind`, `open_qty`, `open_amount` — not `title` / `qty_sold`
- Settlement payable is stamped full unit cost
- `PUT /credits/{id}` returns **409** if asked to mark paid without a payment (`test_credit_status_endpoint_rejects_paid_without_payment_allocation`)
- Invoice create uses server list price (hardened)
- Customer return requires `invoice_item_id`; server refund
