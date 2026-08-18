# Stabilization completion report

## Phases delivered

0. Baseline docs, SQLite PHPUnit, factories, characterization tests  
1. `BranchAccess`, `EnsureRole`, invoice price integrity, return validation, route middleware  
2. `stock_lots` / movements / `sale_lot_allocations` / transfer lot splits; FIFO allocation; backfill commands  
3. Ledger accounts + journals (`LedgerPoster`) shadow posting + `ledger:reconcile`  
4. `SupplierPayable` + `settlement_allocations`; preview≡settle; bulk atomic; sold-based supplier balance  
5. Allocation-based `SalesCogs`; Iraq P&L with UI keys + origin splits + legacy aliases  
6. Branch capability flags; frontend dynamic branch helpers, low-stock field, route guards, bulk settle UI, offline fonts, tsc clean  
7. Customers/payments + `app_notifications`; `alerts:generate` / `receivables:mark-overdue` scheduled  
8. `archived_at` on masters; branch destroy archives when history exists; operations docs  

## Migrations added

- `2026_08_15_100001_add_price_integrity_fields_to_invoice_items`
- `2026_08_15_200001_create_stock_lots_and_allocations`
- `2026_08_15_300001_create_ledger_tables`
- `2026_08_15_400001_create_settlement_allocations`
- `2026_08_15_600001_add_branch_capabilities`
- `2026_08_15_700001_create_customers_and_notifications`
- `2026_08_15_800001_add_archived_at_to_masters`

## API compatibility

- Inventory still one row per branch/book (aggregate from lots)  
- Invoice items: additive `list_price`, `override_by`, `override_reason`  
- Iraq profit: keeps `iraq_only_revenue` / `distributed_revenue` / `total_iraq_revenue`; adds `revenue`, `expenses`, `net_profit`, `sales_count`, origin objects  
- Settings: adds `consignment_commission_rate`  

## Business assumptions

See [BUSINESS_ASSUMPTIONS.md](BUSINESS_ASSUMPTIONS.md). Commission default 10% configurable.

## Remaining risks

- Origin-split Iraq COGS/expenses still partially branch-level when filtering by origin  
- Existing MySQL cascade FKs on legacy tables not rewritten in-place; destroy guards + archive flags mitigate  
- Frontend ESLint may still report `any` warnings; TypeScript check is clean after Phase 6  
- Place font files under `frontend/public/fonts` per README for brand typography  
