# Wave 1 deployment runbook — supplier accounts

Wave 1 introduces branch-scoped `supplier_accounts`, stamps operational rows with
`supplier_account_id`, and adds supplier identity provenance (`identity_origin`).
**Do not start Wave 2** from this runbook.

## Hard rule: schema before traffic

Wave 1 application code reads columns such as `suppliers.identity_origin`. If the
new code is already serving requests but these migrations are still **Pending**, API
calls like `GET /api/suppliers` will fail with SQL errors (missing column).

**Never swap frontend bundles or run `php artisan up` until every Wave 1 migration
below shows `Ran` in `php artisan migrate:status`.**

Do **not** patch controllers to tolerate a missing schema. Fix the deployment order.

## Migrations in this wave

Run in timestamp order. All are additive.

| Migration | Purpose |
|-----------|---------|
| `2026_08_22_110001_create_supplier_accounts` | Branch-scoped supplier account table |
| `2026_08_22_110002_add_supplier_account_id_to_operational_tables` | Nullable FK on operational tables |
| `2026_08_22_110003_add_supplier_account_id_to_allocation_tables` | Nullable FK on allocation tables |
| `2026_08_22_120001_add_supplier_identity_origin` | `suppliers.identity_origin`, `origin_branch_id`; `supplier_accounts.created_canonical_supplier` |

### Prerequisites (earlier finance / activity migrations)

These must already be `Ran` before Wave 1. If any are `Pending`, run them in the
same maintenance window **before** the four migrations above:

```text
2026_08_18_110001_add_journal_entry_versioning
2026_08_18_110002_add_journal_line_reporting_dimensions
2026_08_18_110003_add_payable_snapshots_to_allocations
2026_08_18_110004_create_financial_accounts
2026_08_18_110005_add_financial_event_dates
2026_08_18_110006_create_customer_return_lot_allocations
2026_08_19_110001_add_journal_reporting_indexes
2026_08_20_120001_enrich_activity_logs
```

## Standard cutover (greenfield deploy)

Use maintenance mode so **no request is served** until migrate + cache clear finish.

```bash
cd /path/to/backend

# 1. Backup (mandatory)
#    MySQL dump + compress backend/ and public_html/

# 2. Block traffic
php artisan down --render="maintenance" --retry=60

# 3. Deploy new backend files (ZIP extract). Keep existing .env, vendor can be refreshed:
composer install --no-dev --optimize-autoloader

# 4. Inspect pending work
php artisan migrate:status | grep -E '2026_08_22|Pending'
php artisan migrate --pretend   # optional human review

# 5. Apply schema (required before serving traffic)
php artisan migrate --force

# 6. Clear stale config/route/view caches
php artisan optimize:clear

# 7. Backfill — dry-run first (default)
php artisan suppliers:backfill-accounts --json | tee /tmp/wave1-backfill-dry.json

# 8. Review dry-run report (see “Backfill review” below). Then:
php artisan suppliers:backfill-accounts --apply --json | tee /tmp/wave1-backfill-apply.json

# 9. Post-deploy verification (see below)

# 10. Deploy frontend static bundle, then:
php artisan optimize
php artisan up
```

### Deployment order checklist

| Step | Gate |
|------|------|
| Backup DB + files | Abort if skipped |
| `php artisan down` | Site returns 503 |
| Backend code on disk | Migrations files present |
| `php artisan migrate --force` | All Wave 1 rows `Ran` |
| `php artisan optimize:clear` | No stale cached routes/config |
| Backfill dry-run reviewed | No unexpected `ambiguous_account` |
| Backfill `--apply` | `unresolved_pair` acceptable per review |
| API smoke tests pass | See verification |
| Frontend swap + `php artisan up` | Only after gates above |

### Pre-traffic schema gate (run before `php artisan up`)

These commands must succeed while the site is still in maintenance mode:

```bash
php artisan migrate:status | grep '2026_08_22'    # all four rows must show Ran, not Pending
php artisan migrate --force                        # idempotent when already applied
php artisan optimize:clear

# Confirm the column the runtime code requires (fail closed if missing):
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
exit(Illuminate\Support\Facades\Schema::hasColumn('suppliers', 'identity_origin') ? 0 : 1);
"
```

If the PHP check exits non-zero, **do not** run `php artisan up` or swap frontend.
The fix is `php artisan migrate --force`, not a controller change.

## Remediation: code already live, DB behind

If production already serves Wave 1 code but `2026_08_22_120001` (or siblings) is
still `Pending`:

```bash
cd /path/to/backend
php artisan down --retry=60
php artisan migrate:status
php artisan migrate --force
php artisan optimize:clear
php artisan suppliers:backfill-accounts --json          # review
php artisan suppliers:backfill-accounts --apply --json
# run verification, then:
php artisan optimize
php artisan up
```

No controller workaround is required once `identity_origin` exists.

## Backfill review

Command: `php artisan suppliers:backfill-accounts` (dry-run is default).

| Field | Meaning |
|-------|---------|
| `accounts_planned` | Distinct `(branch_id, supplier_id)` pairs with operational history |
| `accounts_created` | New rows written (0 on dry-run) |
| `unresolved_pair` | Rows that still need an account before stamping |
| `ambiguous_account` | **Abort** — more than one account matches a pair |
| `missing_account` | Pair has history but no account yet (expected before `--apply`) |
| `corporate_rows` | Rows intentionally left null (corporate scope) |
| `expected_null_owned` | Owned stock without supplier dimension |
| `leftover_null_supplier_account_id` | After apply: tables still missing FK (investigate if non-zero except known owned-stock orphans) |

**Apply only when:**

- `ambiguous_account` is 0
- `unresolved_pair` on dry-run is understood (typically equals rows waiting for account creation)
- After `--apply`, re-run dry-run: stamping eligible counts should be 0 and `accounts_planned` unchanged

Strict CI gate: `php artisan suppliers:backfill-accounts --strict` exits non-zero if unexpected unresolved rows remain.

Production rollback for backfill is a **pre-migrate MySQL dump**, not `migrate:rollback`.

## Post-deploy verification

Obtain an admin Sanctum token, then:

### 1. Canonical suppliers

```http
GET /api/suppliers
Authorization: Bearer {token}
```

Expect `200` and the existing canonical supplier catalog (admin-created publishers).
Response must **not** SQL-error on `identity_origin`.

### 2. Branch supplier accounts

```http
GET /api/supplier-accounts?branch_id={branch_id}
Authorization: Bearer {token}
```

Expect `200` and accounts for suppliers with operational history in that branch only.

### 3. No automatic cross-branch account fan-out

A canonical supplier listed in `/suppliers` that has **never** had consignment,
stock, settlement, or gift activity in branch B must **not** appear in
`GET /api/supplier-accounts?branch_id=B`.

Accounts are created only for `(branch_id, supplier_id)` pairs found in operational
history (backfill) or when a branch explicitly creates/links an account (runtime).

Quick SQL sanity check (replace branch id):

```sql
SELECT s.id, s.name
FROM suppliers s
WHERE (s.identity_origin != 'branch_local' OR s.identity_origin IS NULL)
  AND NOT EXISTS (
    SELECT 1 FROM supplier_accounts sa
    WHERE sa.supplier_id = s.id AND sa.branch_id = :branch_id
  );
```

Non-empty result is **expected** for canonical suppliers without history in that branch.

### 4. Regression tests (staging / CI)

```bash
php artisan test --group=suppliers
php artisan test --group=finance
```

## Rollback

- **Before backfill apply:** restore MySQL dump taken before `migrate --force`.
- **After backfill apply:** same — forward-fix preferred for financial rows; do not
  delete stamped journals or settlements.
- Do **not** rely on `migrate:rollback` once operational data references
  `supplier_accounts`.

## Related docs

- `deployment/CPANEL_DEPLOY_FA.md` — cPanel operator steps (Persian)
- `deployment/CPANEL_UPDATE_2026-08-22_WAVE1_FA.md` — Wave 1 delta for dar-almanahel.com
- `backend/docs/FINANCE_MIGRATION_RUNBOOK.md` — finance schema notes (T9/T10)
