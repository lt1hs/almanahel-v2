# Accounting rules (Dar Al-Manahel)

Authoritative definitions for the finance remediation. Operational inventory/sales code must not invent a second meaning for these terms.

Current production/test code posts only through `FinancialPostingService`. Financial UI reports read the same journal (`FINANCE_LEDGER_REPORTS_ENABLED=true`).

## 1. Statement definitions (never interchangeable)

| Concept | Definition |
|---------|------------|
| **Net sales** | Sales revenue recognized in the period (invoice `sold_at`) minus **sales returns** recognized in the period (`returned_at`). |
| **Gross profit** | Net sales minus net COGS for the same period. |
| **Net profit** | Gross profit minus operating expenses minus gift expenses (period of `expense.date` / `gifted_at`). |
| **Cash balance** | Opening treasury cash plus cash receipts minus cash payments, from **ledger lines** on cash-drawer accounts. Not inventory value. |
| **Customer receivables** | Open AR from credit sales and bounced incoming checks, minus allocated payments and return credits. Cash/card/cleared-check invoices are not customer debt. |
| **Supplier payables** | Open consignment (and trade) payables from **allocation snapshots**, minus settlement allocations actually recorded. |
| **Inventory value (financial)** | Owned stock at **immutable lot unit cost**. Selling price is merchandising only. |
| **Trial balance / financial position** | Sum of journal lines per account, branch, currency. |

Purchasing owned inventory is an **asset swap** (Dr inventory, Cr cash/bank/payable), not P&L.

Paying a supplier settlement is **not** an expense (Dr supplier payable, Cr cash/bank/checks payable).

Credit and check sales create **receivables**, not cash.

Branch transfers move lot provenance only; **no revenue or expense** for the organization.

## 2. Money and currency

- Store and compute with `decimal` columns and `App\Support\Money` / BCMath. Never PHP floats for authoritative amounts.
- Toman and Dinar are always separate. Never add them in API or UI.
- Optional consolidated view only with explicit base currency, stored rate, rate date/source, and original amounts still visible.

## 3. Event dates

Do not use `created_at` as the financial date when a business date exists.

| Event | Business date | Journal `occurred_at` |
|-------|---------------|----------------------|
| Sale | `invoices.sold_at` | same |
| Customer return | `customer_returns.returned_at` | same |
| Gift | `gifts.gifted_at` | same |
| Expense | `expenses.date` | same |
| Customer payment | `customer_payments.paid_at` | same |
| Supplier settlement | `settlements.paid_at` | same |
| Incoming check clear/bounce | `checks.cleared_at` / `checks.bounced_at` | same |
| Purchase / intake | receipt/purchase date | same |

Return-period reporting uses `returned_at` and the **return journal**. It must not net `quantity_returned` into the original invoice month’s COGS.

## 4. Ledger design (one GL)

Refactor existing `journal_entries` / `journal_lines` / `ledger_accounts`. Do not create a second ledger.

### 4.1 Accounts vs dimensions

**Treasury `financial_accounts`** (user-selectable or defaulted) each point at a `ledger_accounts` row. Multiple rows per branch/currency/type are allowed. Unique **stable `code`**. At most one `is_default=true` per (`branch_id` including null, `currency`, `type`) among **active** accounts — enforced in domain service, not a naive unique `(branch, currency, type)` table constraint.

- `branch_id` nullable: corporate accounts (`branch_id` null) fund admin/global settlements that have `settlements.branch_id` null. Branch FKs use **restrictOnDelete** so archiving/deleting a branch cannot silently convert a branch treasury account into a corporate (`branch_id` null) account.
- `is_default`, `active`, optional bank metadata (`bank_name`, `account_number` / reference).
- Until Phase 5, POS/expense/settlement requests omit `financial_account_id`. Routing uses the **deterministic default** for that type/currency/branch (or corporate default when branch is null). If no valid default exists: **domain error**. Never silently create or pick an arbitrary bank.

Treasury types: `cash_drawer`, `bank`, `card_clearing`, `checks_receivable`, `accounts_receivable`, `supplier_payable`, `checks_payable`.

Treasury journal lines must store `financial_account_id` (restrictOnDelete). Multiple banks sharing one `sys.bank.{currency}` ledger account remain separately reportable.

Reporting dimension FKs on `journal_lines` use **restrictOnDelete** (not nullOnDelete).

**System ledger accounts** (not user-selectable treasury, codes explicit, tested):

- `owned_inventory`
- `sales_revenue`
- `sales_returns` (contra-revenue)
- `cogs`
- `operating_expense`
- `gift_expense`
- `customer_credit_liability`

`customer_credit_liability` is an **outstanding customer liability**. Journal lines for this account **must** carry `customer_id`. Anonymous / walk-in credit refunds are forbidden. There is **no redemption workflow** yet: balances expose the outstanding amount per currency but must not be treated as spendable store credit.
- `supplier_recoverable`
- `trade_payable` (unpaid owned purchases)

Do **not** post global settlements to a generic `settlement_payments` asset.

**Reporting dimensions** are stored **immutably on `journal_lines`** at post time:

- `branch_id`
- `currency` (already present)
- `supplier_id` (nullable)
- `customer_id` (nullable)
- `origin_scope` (`iraq_local` \| `qom_distributed` \| `other` \| `shared`, nullable)
- `stock_lot_id` (nullable)
- `sale_lot_allocation_id` (nullable)
- `gift_lot_allocation_id` (nullable)
- `customer_return_lot_allocation_id` (nullable)
- `settlement_allocation_id` (nullable)

Financial reports after cutover **group journal lines**. They must not re-read mutable `stock_lots.origin` or live config to explain a historical line.

### 4.2 Journal immutability

Posted **monetary** journals are append-only. Corrections: insert a reversal journal and a replacement with incremented `version`. Unique identity: `(source_type, source_id, event_type, version)`. Empty journals forbidden. One currency per journal. Source event and journal commit or roll back together. Never swallow posting exceptions.

Changing `status` from `active` to `reversed` is **lifecycle metadata only**. Model/service must refuse updates to:

- `occurred_at`, `currency`, source identity (`source_type`, `source_id`, `event_type` after insert)
- all `journal_lines` debit/credit, account, currency, and reporting dimensions

Allowed after insert: `status`, `reversed_at`, `reverses_entry_id` / `supersedes_entry_id` linkage (and equivalent metadata). Never rewrite line amounts.

`migrate:rollback` of journal versioning is **forbidden in production** once any `version > 1` row exists. The migration `down()` must detect that and throw; it must not delete or merge versions.

## 5. Owned vs consignment

### Intake

- **Owned:** Dr owned inventory, Cr cash/bank/trade payable.
- **Consignment:** memorandum lots only. **No** owned inventory asset. **No** supplier payable until sale or gift.

### Sale

Owned:

```
Dr  Cash / Bank / Card clearing / AR / Incoming checks   (payment method)
Cr  Sales revenue
Dr  COGS
Cr  Owned inventory asset
```

Consignment:

```
Dr  Cash / Bank / Card clearing / AR / Incoming checks
Cr  Sales revenue
Dr  COGS
Cr  Supplier payable
```

COGS and payable amounts come from **sale_lot_allocation snapshots**, not from client payload.

Owned COGS = `unit_cost × quantity`. Consignment COGS **must equal** the immutable `publisher_payable` credited to supplier payable (legacy 90% snapshot or full unit cost). Never debit raw lot cost while crediting a different payable.

Treasury lines require an active `financial_account` whose currency, branch (or corporate null-branch), type, and `ledger_account_id` match the event. Cash sale/expense/refund → `cash_drawer`; card → `bank` or `card_clearing`; check sale → `checks_receivable`; credit sale → `accounts_receivable`; settlement → cash or bank (or checks payable) per method. Mismatch fails closed before any journal row.

`FINANCE_POSTING_V2_ENABLED` is ignored. Live events use `FinancialPostingService` only. `LedgerPoster` has no runtime caller. Never dual-post.

### Gift

Owned: Dr gift expense, Cr owned inventory.

Consignment: Dr gift expense, Cr supplier payable (same snapshot rule as sales).

Manual gift “settled” flags must not clear supplier debt. Only settlement allocations do.

### Customer return (`returned_at`)

Persist **immutable** `customer_return_lot_allocations` at return time (which sale lots/qty were reversed). Return journals and return-period reports **must** use these rows. Do not later compute returned COGS from mutable `sale_lot_allocations.quantity_returned`.

Always:

```
Dr  Sales returns (contra-revenue)
Cr  Cash / AR / customer credit   (refund method)
```

Net sales = sales revenue − sales returns.

COGS reversal **per `customer_return_lot_allocations` row**:

- Owned: Dr inventory, Cr COGS at the immutable sold allocation cost.
- Consignment: Cr COGS for `publisher_payable_reversed`. Debit `supplier_payable` for `unsettled_payable_reversed` and `supplier_recoverable` for `settled_payable_reversed`.
- Invariant: `publisher_payable_reversed = unsettled_payable_reversed + settled_payable_reversed`.
- Split is taken under row locks from persisted `settlement_allocations`, never from live config.
- Return quantity and payable reversed cannot exceed the original sale allocation. Partial returns consume remaining unpaid payable first, then settled.

Do not credit raw consignment unit cost when the payable snapshot differs.

## 6. Consignment payable (Option B)

**Default for every new receipt after this code is deployed:**

- `payable_basis = full_unit_cost`
- `payable_rate = 1.0`
- Stamped at intake on the receipt and lot.
- Optional `effective_at` is **audit metadata only**. It does not switch calculation.

Implicit global 10% commission is **disabled** for new receipts and for open/unsettled restated lots.

### Allocation snapshots (required)

Each payable-producing `sale_lot_allocations` / `gift_lot_allocations` row stores:

- `payable_basis`
- `payable_rate`
- `gross_cost`
- `publisher_payable`
- `rule_source` (`intake` \| `legacy_inferred` \| `legacy_restate` \| `manual`)
- `rule_stamped_at`

Settlements and reports use `publisher_payable` (and remaining = snapshot payable − actual settlement allocation sums). They must **not** recompute from current receipt, lot, config, or cache.

`settlement_allocations` keep the **amount actually paid**. Optional snapshot copies of basis/rate are for audit; **paid amounts are never rewritten**.

### Mixed rule on one receipt

A receipt may have:

- old sale/gift allocations settled under the **legacy inferred** 90% rule (snapshot of **actual historical payable**);
- remaining unsold stock and **future** sales/gifts under **full_unit_cost** copied from the lot stamp.

Never recompute old settled allocations from the newly stamped receipt rule.

### Open (unpaid / partial) restatement

```
remaining_payable = full_unit_cost_payable(eligible_qty) − SUM(actual settlement_allocation.amount)
```

If full-cost payable is greater than already paid, the difference is an **explicit remaining delta**. Do not overwrite `settled_publisher_amount` history or settlement rows.

## 7. Payment method routing

| Flow | Method | Debit / credit treasury |
|------|--------|-------------------------|
| Sale | cash | branch cash drawer |
| Sale | card | card clearing (or selected bank) |
| Sale | bank_transfer | selected bank |
| Sale | check | incoming checks receivable |
| Sale | credit | customer AR |
| Customer payment | cash / card / bank / check | matching asset, Cr AR |
| Settlement | cash / bank / outgoing check | Cr cash / bank / checks payable |
| Expense | cash / bank / card / credit | Cr matching; credit → AP when supported |
| Purchase | cash/bank vs unpaid | Cr cash/bank or trade payable |
| Refund | cash / bank / customer credit | Cr cash/bank or customer credit liability |

Do not post sales or expenses to a combined “cash/bank” account.

## 8. Invoice payment status

Derived only:

```
status = f(invoice net − return credits − allocated customer payments)
```

`PUT /credits/{invoice}` remains a compatibility endpoint. It must **not** mark an invoice paid without a payment allocation (enforced in Phase 3).

Do not sum Toman and Dinar customer balances.

## 9. Checks

Incoming: pending stays checks receivable; cleared Dr bank Cr checks receivable; bounce Dr AR Cr checks receivable. Invalid transitions rejected. Clearing then bounce: reverse clearing first.

Outgoing: at **issuance** Dr supplier payable, Cr **checks payable**. Do **not** credit bank at issuance. Clearing later: Dr checks payable, Cr bank. Cancel/bounce before clearing reverses/releases checks_payable. T9 must wire issuance and clear/bounce atomically; never treat check issuance as an immediate bank payment.

## 10. Iraq origin P&L

Allocate at **sale/gift allocation** (and matching journal-line `origin_scope`), not at invoice header.

- `iraq_local` / `qom_distributed` / `other`
- Shared expenses (`origin_scope=shared`) appear in **combined** profit only unless a configured allocation percentage exists.
- Never duplicate branch-level COGS/expenses into both origin buckets.
- Return separate Toman and Dinar objects.

## 11. Expenses

Exclude `archived_at IS NOT NULL` and `reversed_at IS NOT NULL` from operational fallbacks.

Each edit: reverse active journal version, post replacement. Complete reversal chain.

## 12. Cutover (posting V2 and ledger reports)

`FinancialPostingService` is the only posting runtime. `LedgerPoster` has no runtime caller. `FINANCE_POSTING_V2_ENABLED` is ignored and cannot restore legacy posting.

`FINANCE_LEDGER_REPORTS_ENABLED` defaults to **true**. All financial report endpoints read `journal_entries` + `journal_lines` through `LedgerReportService`. There is no mixed ledger/operational P&L path.

### Journal inclusion

Posted originals with `status=reversed` **remain** in report sums together with their reversal entries. Example: expense +100, reversal −100, replacement +80 → **80**. Filtering `status=active` only would incorrectly yield −20. Exclude only `draft` / `void`.

### P&L (per branch, per currency)

- `sales_revenue` = credits − debits on `sys.sales_revenue.{currency}`
- `sales_returns` = debits − credits on `sys.sales_returns.{currency}`
- `net_sales` = sales_revenue − sales_returns
- `net_cogs` = debits − credits on `sys.cogs.{currency}`
- `gross_profit` = net_sales − net_cogs
- `operating_expenses` / `gift_expenses` similarly
- `net_profit` = gross_profit − operating_expenses − gift_expenses

Sale period = journal `occurred_at` (from `sold_at`). A return in August for a July sale hits **August**. Settlements, owned purchases, and branch transfers are not P&L.

### Cash versus inventory

Treasury cash is ledger cash-drawer / bank / card-clearing balances. Owned inventory at lot cost is **not** cash. Consignment quantity is memorandum only.

### Iraq origins

Split at `journal_lines.origin_scope` (item/allocation), never whole-invoice. Untagged operating expenses are **shared** (not allocated by ratio). Combined = origin contributions minus shared expenses once. Toman and Dinar stay separate.

### Scope

Reports use branch IDs (including inactive/archived with history). Corporate financial accounts have `branch_id=null` and are visible to admin/accountant only. Warehouse staff cannot view `/api/finance/*`.

Read-only check: `php artisan finance:reports-preflight --format=json` (non-zero on reconciliation failures).

## 13. Authorization

Backend policies + `BranchAccess`. Warehouse staff: no financial reports or cash modifications. Cross-branch leakage must be tested.
