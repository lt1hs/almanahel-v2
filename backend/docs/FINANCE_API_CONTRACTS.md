# Finance API contracts

Authoritative monetary values are **decimal strings**. Toman and Dinar are never added. No FX in T10.

`FINANCE_LEDGER_REPORTS_ENABLED=true`. Every financial report is served by `LedgerReportService` (journals only). `PUT /credits/{id}` with `paid` returns **HTTP 409** and does not mark the invoice paid. Settlement preview uses stamped **full unit cost** (not 90%).

## Journal inclusion

Report queries include reversed originals **and** reversal/replacement entries. Do not filter `journal_entries.status='reversed'` out of sums. Exclude only `draft`/`void`.

## P&L formulas (per branch + currency)

```
sales_revenue     = Cr − Dr  sys.sales_revenue.{currency}
sales_returns     = Dr − Cr  sys.sales_returns.{currency}
net_sales         = sales_revenue − sales_returns
net_cogs          = Dr − Cr  sys.cogs.{currency}
gross_profit      = net_sales − net_cogs
operating_expenses= Dr − Cr  sys.operating_expense.{currency}
gift_expenses     = Dr − Cr  sys.gift_expense.{currency}
net_profit        = gross_profit − operating_expenses − gift_expenses
```

Inclusive `date_from <= date_to` on `journal_entries.occurred_at` (app timezone).

## Endpoints

### `GET /api/finance/pnl`

Query: `branch_id?`, `date_from`, `date_to`, `currency=toman|dinar`.

Returns decimal strings plus `equation`. Branch managers are forced to their own `branch_id`.

### `GET /api/reports/all-branches` and `GET /api/branches/{id}/profit`

Per-branch `currencies.toman|dinar` objects (`gross_sales`, `sales_returns`, `net_sales`, `cogs`, `gross_profit`, `operating_expenses`, `gift_expenses`, `net_profit`). Legacy aliases `revenue_*`, `cogs_*`, `expenses_*`, `gift_costs_*`, `net_profit_*`, `pending_credit_toman|dinar` (AR, not a cross-currency sum). **No** `pending_credit` combined field.

### `GET /api/finance/treasury`

Per financial account: `type`, `debit`, `credit`, `balance`, `opening_balance`, `period_debit`, `period_credit`, `closing_balance`. Corporate rows have `branch_id=null` (admin/accountant). Cash drawer, bank, and card clearing stay separate.

### `GET /api/finance/trial-balance`

Per account/branch/currency opening, period, closing. `total_debits == total_credits` per currency/scope (`balanced`).

### `GET /api/finance/financial-position`

`assets` (cash drawers, banks, card clearing, checks receivable, AR, owned inventory, supplier recoverable), `liabilities` (supplier payable, checks payable, trade payable, customer credit), `equity` (accumulated/period result). Consignment stock is not an owned asset.

### `GET /api/finance/receivables|payables|checks`

Ledger totals plus operational drill-down. `difference` is explicit when they do not match.

### `GET /api/finance/inventory-value`

Owned lots at immutable `unit_cost` vs `sys.owned_inventory` ledger. `reconciled` / `difference`. Consignment is `consignment_memorandum` quantity only.

### `GET /api/reports/dashboard`

`today_sales_*` = **net sales** from journals on `sold_at`/`occurred_at` for today. Also `today_gross_sales_*`, `today_returns_*`. `inventory_value_*` = owned inventory **at cost** (`inventory_value_basis=owned_inventory_at_cost`). Not cash.

### `GET /api/reports/monthly-trends`

`currency` required (default toman). Journal `occurred_at`. Fields: `net_sales`, `cogs`, `gross_profit`, `expenses`, `gifts`, `net_profit` (aliases `sales`, `profit`).

### `GET /api/reports/top-books`

`sold_at` sales minus `returned_at` returns in the period; grouped by `currency`; net unit = `actual_price − discount`. Return periods may show negative qty/revenue.

### `GET /api/reports/iraq-profit`

`currencies.toman|dinar` each with `iraq_local`, `qom_distributed`, `shared`, `combined`. Top-level aliases for the requested `currency`. Mixed invoices split at allocation `origin_scope`. Shared opex is untagged operating expense, subtracted once in combined.

### Settlement preview

`GET /api/consignments/settlement-preview` — `total_payable` is full stamped payable. Not 90%.

### `PUT /api/credits/{id}`

Cannot mark `paid` without a payment allocation (**409**).
