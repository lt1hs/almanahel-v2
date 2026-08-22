# Business assumptions (configurable where ambiguous)

Go-forward accounting rules: [ACCOUNTING_RULES.md](ACCOUNTING_RULES.md). Until Phase 1 posting is wired, **running code** still uses the commission behavior below.

1. **Consignment store commission (current code)** default `0.1` (10% store / 90% publisher of sold cost). Env: `ALMANAHEL_CONSIGNMENT_COMMISSION_RATE`. After Phase 1, new/open receipts stamp `full_unit_cost` / rate `1.0` on the receipt and allocation; live global rate must not change stamped payables.
2. **Lot allocation** for sales/gifts/transfers: FIFO by `stock_lots.id` ascending.
3. **Customer return allocation restore**: LIFO of that invoice’s `sale_lot_allocations`.
4. **Partial supplier settlement** allowed; allocations recorded per receipt.
5. **Bulk settle** default `atomic=true` (all-or-nothing).
6. **Lot origin**: `iraq_local` for intake at Iraq-capable branches; `qom_distributed` for intake at intake hubs / central warehouse; else `other`. Transfers preserve origin.
7. **Inventory.quantity** = sum of lot `qty_available` at branch (aggregate row kept for sell prices).
8. Legacy unused `transactions` table is **not** the ledger; journal tables are authoritative for GL.
9. **Iraq P&L expenses** are allocated to the combined Iraq-branch result only; origin buckets (iraq_local / qom_distributed) do not receive a share of cash expenses, so gifts/returns/expenses are not double-counted across origin splits.
10. **Consignment payable recognition** is on sale/gift allocation (publisher share after commission), not on intake.
11. **Customer returns** affect the return-date period (not restated silently into the original sale period).
12. **Rollback of lot migrations** drops lot tables; historical lots cannot be reconstructed without a database backup.
