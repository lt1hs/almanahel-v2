# API Contracts (compatibility notes)

Additive fields are preferred. Existing keys remain unless noted.

## Auth
- `POST /api/login` → `{ token, user }`
- `GET /api/user` → user with branch

## Inventory aggregates
- Warehouse/inventory endpoints return one row per `(branch_id, book_id)`.
- After lots: same shape; optional `is_mixed`, `lots_summary`.

## Invoices
- `POST /api/invoices` items: `book_id`, `quantity`, `unit_price`, `actual_price`, `discount`
- Additive: `list_price`, `override_by`, `override_reason` on items (server-authored)

## Reports
- `/reports/all-branches`: per-currency revenue/cogs/expenses/gifts/net
- `/reports/iraq-profit`: keep legacy revenue keys; add full P&L + origin splits

## Consignment
- `/consignments/settlement-preview` and `/consignments/settle` must share payable math
- Commission from config/settings (`consignment_commission_rate`), default `0.1`
