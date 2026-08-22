<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupplierAccountBackfill
{
    private const STAMP_TABLES = [
        'stock_lots',
        'consignment_receipts',
        'consignment_returns',
        'settlements',
        'gifts',
        'journal_lines',
        'sale_lot_allocations',
        'gift_lot_allocations',
        'customer_return_lot_allocations',
        'consignment_return_lot_allocations',
        'settlement_allocations',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(bool $apply): array
    {
        $pairs = $this->distinctPairs();
        $created = 0;
        $accountsPlanned = count($pairs);
        $beforeCounts = $apply ? $this->nullCounts() : [];

        if ($apply) {
            foreach ($pairs as $pair) {
                $account = $this->createAccount((int) $pair['branch_id'], (int) $pair['supplier_id']);
                if ($account->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $stampPlan = $this->stampPlan();
        $stamped = [];
        $totals = [
            'eligible' => 0,
            'missing_supplier_id' => 0,
            'missing_branch_id' => 0,
            'missing_account' => 0,
            'ambiguous_account' => 0,
            'corporate_rows' => 0,
            'expected_null_owned' => 0,
            'unresolved_pair' => 0,
        ];

        foreach ($stampPlan as $table => $plan) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($plan[$key] ?? 0);
            }
            if ($apply) {
                $stamped[$table] = [
                    'before' => $beforeCounts[$table] ?? 0,
                    'stamped' => $this->stampTable($table),
                    'after' => $this->nullCount($table),
                ];
            } else {
                $stamped[$table] = $plan;
            }
        }

        $leftover = $apply ? $this->nullCounts() : [];

        return [
            'apply' => $apply,
            'accounts_planned' => $accountsPlanned,
            'accounts_created' => $created,
            'stamped' => $stamped,
            'missing_supplier_id' => $totals['missing_supplier_id'],
            'missing_branch_id' => $totals['missing_branch_id'],
            'missing_account' => $totals['missing_account'],
            'ambiguous_account' => $totals['ambiguous_account'],
            'corporate_rows' => $totals['corporate_rows'],
            'expected_null_owned' => $totals['expected_null_owned'],
            'unresolved_pair' => $totals['unresolved_pair'],
            'leftover_null_supplier_account_id' => $leftover,
        ];
    }

    public function hasUnexpectedUnresolved(array $report): bool
    {
        foreach (['unresolved_pair', 'ambiguous_account', 'missing_account'] as $key) {
            if ((int) ($report[$key] ?? 0) > 0) {
                return true;
            }
        }
        foreach ($report['leftover_null_supplier_account_id'] ?? [] as $table => $count) {
            if ((int) $count <= 0) {
                continue;
            }
            if ($table === 'stock_lots') {
                $ownedNull = DB::table('stock_lots')
                    ->whereNull('supplier_account_id')
                    ->where('ownership_type', 'owned')
                    ->whereNull('supplier_id')
                    ->count();
                if ((int) $count === (int) $ownedNull) {
                    continue;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * @return list<array{branch_id: int, supplier_id: int}>
     */
    private function distinctPairs(): array
    {
        $seen = [];
        foreach ($this->pairSources() as $rows) {
            foreach ($rows as $row) {
                if ($row['branch_id'] === null || $row['supplier_id'] === null) {
                    continue;
                }
                $key = $row['branch_id'].':'.$row['supplier_id'];
                $seen[$key] = [
                    'branch_id' => (int) $row['branch_id'],
                    'supplier_id' => (int) $row['supplier_id'],
                ];
            }
        }

        return array_values($seen);
    }

    /** @return list<list<array{branch_id: mixed, supplier_id: mixed}>> */
    private function pairSources(): array
    {
        $sources = [];
        foreach (['stock_lots', 'consignment_receipts', 'settlements', 'gifts', 'consignment_returns'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $sources[] = DB::table($table)
                ->whereNotNull('branch_id')
                ->whereNotNull('supplier_id')
                ->select('branch_id', 'supplier_id')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['branch_id' => $r->branch_id, 'supplier_id' => $r->supplier_id])
                ->all();
        }

        if (Schema::hasTable('journal_lines')) {
            $sources[] = DB::table('journal_lines')
                ->whereNotNull('branch_id')
                ->whereNotNull('supplier_id')
                ->select('branch_id', 'supplier_id')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['branch_id' => $r->branch_id, 'supplier_id' => $r->supplier_id])
                ->all();
        }

        if (Schema::hasTable('gift_lot_allocations') && Schema::hasTable('gifts')) {
            $sources[] = DB::table('gift_lot_allocations')
                ->join('gifts', 'gifts.id', '=', 'gift_lot_allocations.gift_id')
                ->whereNotNull('gifts.branch_id')
                ->whereNotNull('gift_lot_allocations.supplier_id')
                ->select('gifts.branch_id', 'gift_lot_allocations.supplier_id')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['branch_id' => $r->branch_id, 'supplier_id' => $r->supplier_id])
                ->all();
        }

        if (Schema::hasTable('sale_lot_allocations') && Schema::hasTable('stock_lots')) {
            $sources[] = DB::table('sale_lot_allocations')
                ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
                ->whereNotNull('stock_lots.branch_id')
                ->whereNotNull('stock_lots.supplier_id')
                ->select('stock_lots.branch_id', 'stock_lots.supplier_id')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['branch_id' => $r->branch_id, 'supplier_id' => $r->supplier_id])
                ->all();
        }

        if (Schema::hasTable('customer_return_lot_allocations') && Schema::hasTable('stock_lots')) {
            $sources[] = DB::table('customer_return_lot_allocations')
                ->join('stock_lots', 'stock_lots.id', '=', 'customer_return_lot_allocations.stock_lot_id')
                ->whereNotNull('stock_lots.branch_id')
                ->whereNotNull('customer_return_lot_allocations.supplier_id')
                ->select('stock_lots.branch_id', 'customer_return_lot_allocations.supplier_id')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['branch_id' => $r->branch_id, 'supplier_id' => $r->supplier_id])
                ->all();
        }

        if (Schema::hasTable('consignment_return_lot_allocations') && Schema::hasTable('stock_lots')) {
            $sources[] = DB::table('consignment_return_lot_allocations')
                ->join('stock_lots', 'stock_lots.id', '=', 'consignment_return_lot_allocations.stock_lot_id')
                ->whereNotNull('stock_lots.branch_id')
                ->whereNotNull('stock_lots.supplier_id')
                ->select('stock_lots.branch_id', 'stock_lots.supplier_id')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['branch_id' => $r->branch_id, 'supplier_id' => $r->supplier_id])
                ->all();
        }

        if (Schema::hasTable('settlement_allocations') && Schema::hasTable('settlements')) {
            $sources[] = DB::table('settlement_allocations')
                ->join('settlements', 'settlements.id', '=', 'settlement_allocations.settlement_id')
                ->whereNotNull('settlements.branch_id')
                ->whereNotNull('settlements.supplier_id')
                ->select('settlements.branch_id', 'settlements.supplier_id')
                ->distinct()
                ->get()
                ->map(fn ($r) => ['branch_id' => $r->branch_id, 'supplier_id' => $r->supplier_id])
                ->all();
        }

        return $sources;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function stampPlan(): array
    {
        $plan = [];
        foreach (['stock_lots', 'consignment_receipts', 'consignment_returns', 'settlements', 'gifts', 'journal_lines'] as $table) {
            $plan[$table] = $this->operationalPlan($table);
        }
        $plan['sale_lot_allocations'] = $this->saleAllocPlan();
        $plan['gift_lot_allocations'] = $this->giftAllocPlan();
        $plan['customer_return_lot_allocations'] = $this->customerReturnAllocPlan();
        $plan['consignment_return_lot_allocations'] = $this->consignmentReturnAllocPlan();
        $plan['settlement_allocations'] = $this->settlementAllocPlan();

        return $plan;
    }

    /**
     * @return array<string, int>
     */
    private function emptyPlan(): array
    {
        return [
            'eligible' => 0,
            'missing_supplier_id' => 0,
            'missing_branch_id' => 0,
            'missing_account' => 0,
            'ambiguous_account' => 0,
            'corporate_rows' => 0,
            'expected_null_owned' => 0,
            'unresolved_pair' => 0,
        ];
    }

    /** @return array<string, int> */
    private function operationalPlan(string $table): array
    {
        $plan = $this->emptyPlan();
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'supplier_account_id')) {
            return $plan;
        }

        $base = DB::table($table)->whereNull('supplier_account_id');
        if ($table === 'stock_lots' && Schema::hasColumn($table, 'ownership_type')) {
            $plan['expected_null_owned'] = (clone $base)
                ->where('ownership_type', 'owned')
                ->whereNull('supplier_id')
                ->count();
        }

        if (Schema::hasColumn($table, 'supplier_id')) {
            $plan['missing_supplier_id'] = (clone $base)->whereNull('supplier_id')->count();
        }
        if (Schema::hasColumn($table, 'branch_id')) {
            $plan['missing_branch_id'] = (clone $base)
                ->whereNotNull('supplier_id')
                ->whereNull('branch_id')
                ->count();
            $plan['corporate_rows'] = (clone $base)
                ->whereNotNull('supplier_id')
                ->whereNull('branch_id')
                ->count();
        }

        if (!Schema::hasColumn($table, 'supplier_id') || !Schema::hasColumn($table, 'branch_id')) {
            return $plan;
        }

        $this->accumulatePairStats(
            $plan,
            (clone $base)->whereNotNull('supplier_id')->whereNotNull('branch_id')
                ->select('branch_id', 'supplier_id')
                ->distinct()
                ->get()
        );

        return $plan;
    }

    /** @return array<string, int> */
    private function saleAllocPlan(): array
    {
        $plan = $this->emptyPlan();
        if (!Schema::hasTable('sale_lot_allocations')
            || !Schema::hasColumn('sale_lot_allocations', 'supplier_account_id')) {
            return $plan;
        }
        $base = DB::table('sale_lot_allocations')
            ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
            ->whereNull('sale_lot_allocations.supplier_account_id');
        $plan['missing_supplier_id'] = (clone $base)->whereNull('stock_lots.supplier_id')->count();
        $plan['missing_branch_id'] = (clone $base)->whereNotNull('stock_lots.supplier_id')->whereNull('stock_lots.branch_id')->count();
        $this->accumulatePairStats(
            $plan,
            (clone $base)->whereNotNull('stock_lots.supplier_id')->whereNotNull('stock_lots.branch_id')
                ->select('stock_lots.branch_id', 'stock_lots.supplier_id')
                ->distinct()
                ->get()
        );

        return $plan;
    }

    /** @return array<string, int> */
    private function giftAllocPlan(): array
    {
        $plan = $this->emptyPlan();
        if (!Schema::hasTable('gift_lot_allocations')
            || !Schema::hasColumn('gift_lot_allocations', 'supplier_account_id')
            || !Schema::hasTable('gifts')) {
            return $plan;
        }
        $base = DB::table('gift_lot_allocations')
            ->join('gifts', 'gifts.id', '=', 'gift_lot_allocations.gift_id')
            ->whereNull('gift_lot_allocations.supplier_account_id');
        $plan['missing_supplier_id'] = (clone $base)->whereNull('gift_lot_allocations.supplier_id')->count();
        $plan['missing_branch_id'] = (clone $base)->whereNotNull('gift_lot_allocations.supplier_id')->whereNull('gifts.branch_id')->count();
        $this->accumulatePairStats(
            $plan,
            (clone $base)->whereNotNull('gift_lot_allocations.supplier_id')->whereNotNull('gifts.branch_id')
                ->select('gifts.branch_id', 'gift_lot_allocations.supplier_id')
                ->distinct()
                ->get()
        );

        return $plan;
    }

    /** @return array<string, int> */
    private function customerReturnAllocPlan(): array
    {
        $plan = $this->emptyPlan();
        if (!Schema::hasTable('customer_return_lot_allocations')
            || !Schema::hasColumn('customer_return_lot_allocations', 'supplier_account_id')
            || !Schema::hasTable('stock_lots')) {
            return $plan;
        }
        $base = DB::table('customer_return_lot_allocations')
            ->join('stock_lots', 'stock_lots.id', '=', 'customer_return_lot_allocations.stock_lot_id')
            ->whereNull('customer_return_lot_allocations.supplier_account_id');
        $plan['missing_supplier_id'] = (clone $base)->whereNull('customer_return_lot_allocations.supplier_id')->count();
        $plan['missing_branch_id'] = (clone $base)->whereNotNull('customer_return_lot_allocations.supplier_id')->whereNull('stock_lots.branch_id')->count();
        $this->accumulatePairStats(
            $plan,
            (clone $base)->whereNotNull('customer_return_lot_allocations.supplier_id')->whereNotNull('stock_lots.branch_id')
                ->select('stock_lots.branch_id', 'customer_return_lot_allocations.supplier_id')
                ->distinct()
                ->get()
        );

        return $plan;
    }

    /** @return array<string, int> */
    private function consignmentReturnAllocPlan(): array
    {
        $plan = $this->emptyPlan();
        if (!Schema::hasTable('consignment_return_lot_allocations')
            || !Schema::hasColumn('consignment_return_lot_allocations', 'supplier_account_id')
            || !Schema::hasTable('stock_lots')) {
            return $plan;
        }
        $base = DB::table('consignment_return_lot_allocations')
            ->join('stock_lots', 'stock_lots.id', '=', 'consignment_return_lot_allocations.stock_lot_id')
            ->whereNull('consignment_return_lot_allocations.supplier_account_id');
        $plan['missing_supplier_id'] = (clone $base)->whereNull('stock_lots.supplier_id')->count();
        $this->accumulatePairStats(
            $plan,
            (clone $base)->whereNotNull('stock_lots.supplier_id')->whereNotNull('stock_lots.branch_id')
                ->select('stock_lots.branch_id', 'stock_lots.supplier_id')
                ->distinct()
                ->get()
        );

        return $plan;
    }

    /** @return array<string, int> */
    private function settlementAllocPlan(): array
    {
        $plan = $this->emptyPlan();
        if (!Schema::hasTable('settlement_allocations')
            || !Schema::hasColumn('settlement_allocations', 'supplier_account_id')
            || !Schema::hasTable('settlements')) {
            return $plan;
        }
        $base = DB::table('settlement_allocations')
            ->join('settlements', 'settlements.id', '=', 'settlement_allocations.settlement_id')
            ->whereNull('settlement_allocations.supplier_account_id');
        $plan['missing_supplier_id'] = (clone $base)->whereNull('settlements.supplier_id')->count();
        $plan['missing_branch_id'] = (clone $base)->whereNotNull('settlements.supplier_id')->whereNull('settlements.branch_id')->count();
        $plan['corporate_rows'] = (clone $base)->whereNotNull('settlements.supplier_id')->whereNull('settlements.branch_id')->count();
        $this->accumulatePairStats(
            $plan,
            (clone $base)->whereNotNull('settlements.supplier_id')->whereNotNull('settlements.branch_id')
                ->select('settlements.branch_id', 'settlements.supplier_id')
                ->distinct()
                ->get()
        );

        return $plan;
    }

    /**
     * @param  array<string, int>  $plan
     * @param  iterable<object{branch_id: mixed, supplier_id: mixed}>  $pairs
     */
    private function accumulatePairStats(array &$plan, iterable $pairs): void
    {
        foreach ($pairs as $pair) {
            $resolution = $this->pairResolution((int) $pair->branch_id, (int) $pair->supplier_id);
            if ($resolution === 'eligible') {
                $plan['eligible']++;
            } elseif ($resolution === 'ambiguous') {
                $plan['ambiguous_account']++;
                $plan['unresolved_pair']++;
            } elseif ($resolution === 'missing') {
                $plan['missing_account']++;
                $plan['unresolved_pair']++;
            }
        }
    }

    private function pairResolution(int $branchId, int $supplierId): string
    {
        $count = SupplierAccount::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplierId)
            ->count();
        if ($count === 1) {
            return 'eligible';
        }
        if ($count > 1) {
            return 'ambiguous';
        }

        return 'missing';
    }

    private function stampTable(string $table): int
    {
        return match ($table) {
            'sale_lot_allocations' => $this->stampSales(),
            'gift_lot_allocations' => $this->stampGiftsAlloc(),
            'customer_return_lot_allocations' => $this->stampCustomerReturns(),
            'consignment_return_lot_allocations' => $this->stampConsignmentReturns(),
            'settlement_allocations' => $this->stampSettlementsAlloc(),
            default => $this->stampOperational($table),
        };
    }

    private function stampOperational(string $table): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'supplier_account_id')) {
            return 0;
        }
        if (!Schema::hasColumn($table, 'supplier_id') || !Schema::hasColumn($table, 'branch_id')) {
            return 0;
        }

        $count = 0;
        DB::table($table)
            ->whereNull('supplier_account_id')
            ->whereNotNull('supplier_id')
            ->whereNotNull('branch_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, &$count) {
                foreach ($rows as $row) {
                    $accountId = $this->accountId((int) $row->branch_id, (int) $row->supplier_id);
                    if (!$accountId) {
                        continue;
                    }
                    $count += DB::table($table)->where('id', $row->id)->update(['supplier_account_id' => $accountId]);
                }
            });

        return $count;
    }

    private function stampSales(): int
    {
        if (!Schema::hasTable('sale_lot_allocations')) {
            return 0;
        }
        $count = 0;
        DB::table('sale_lot_allocations')
            ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
            ->whereNull('sale_lot_allocations.supplier_account_id')
            ->whereNotNull('stock_lots.supplier_id')
            ->whereNotNull('stock_lots.branch_id')
            ->select('sale_lot_allocations.id', 'stock_lots.branch_id', 'stock_lots.supplier_id')
            ->orderBy('sale_lot_allocations.id')
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $accountId = $this->accountId((int) $row->branch_id, (int) $row->supplier_id);
                    if (!$accountId) {
                        continue;
                    }
                    $count += DB::table('sale_lot_allocations')->where('id', $row->id)->update([
                        'supplier_account_id' => $accountId,
                    ]);
                }
            }, 'sale_lot_allocations.id', 'id');

        return $count;
    }

    private function stampGiftsAlloc(): int
    {
        if (!Schema::hasTable('gift_lot_allocations')) {
            return 0;
        }
        $count = 0;
        DB::table('gift_lot_allocations')
            ->join('gifts', 'gifts.id', '=', 'gift_lot_allocations.gift_id')
            ->whereNull('gift_lot_allocations.supplier_account_id')
            ->whereNotNull('gift_lot_allocations.supplier_id')
            ->whereNotNull('gifts.branch_id')
            ->select('gift_lot_allocations.id', 'gifts.branch_id', 'gift_lot_allocations.supplier_id')
            ->orderBy('gift_lot_allocations.id')
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $accountId = $this->accountId((int) $row->branch_id, (int) $row->supplier_id);
                    if (!$accountId) {
                        continue;
                    }
                    $count += DB::table('gift_lot_allocations')->where('id', $row->id)->update([
                        'supplier_account_id' => $accountId,
                    ]);
                }
            }, 'gift_lot_allocations.id', 'id');

        return $count;
    }

    private function stampCustomerReturns(): int
    {
        if (!Schema::hasTable('customer_return_lot_allocations')) {
            return 0;
        }
        $count = 0;
        DB::table('customer_return_lot_allocations')
            ->join('stock_lots', 'stock_lots.id', '=', 'customer_return_lot_allocations.stock_lot_id')
            ->whereNull('customer_return_lot_allocations.supplier_account_id')
            ->whereNotNull('customer_return_lot_allocations.supplier_id')
            ->whereNotNull('stock_lots.branch_id')
            ->select(
                'customer_return_lot_allocations.id',
                'stock_lots.branch_id',
                'customer_return_lot_allocations.supplier_id'
            )
            ->orderBy('customer_return_lot_allocations.id')
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $accountId = $this->accountId((int) $row->branch_id, (int) $row->supplier_id);
                    if (!$accountId) {
                        continue;
                    }
                    $count += DB::table('customer_return_lot_allocations')->where('id', $row->id)->update([
                        'supplier_account_id' => $accountId,
                    ]);
                }
            }, 'customer_return_lot_allocations.id', 'id');

        return $count;
    }

    private function stampConsignmentReturns(): int
    {
        if (!Schema::hasTable('consignment_return_lot_allocations')) {
            return 0;
        }
        $count = 0;
        DB::table('consignment_return_lot_allocations')
            ->join('stock_lots', 'stock_lots.id', '=', 'consignment_return_lot_allocations.stock_lot_id')
            ->whereNull('consignment_return_lot_allocations.supplier_account_id')
            ->whereNotNull('stock_lots.supplier_id')
            ->whereNotNull('stock_lots.branch_id')
            ->select(
                'consignment_return_lot_allocations.id',
                'stock_lots.branch_id',
                'stock_lots.supplier_id'
            )
            ->orderBy('consignment_return_lot_allocations.id')
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $accountId = $this->accountId((int) $row->branch_id, (int) $row->supplier_id);
                    if (!$accountId) {
                        continue;
                    }
                    $count += DB::table('consignment_return_lot_allocations')->where('id', $row->id)->update([
                        'supplier_account_id' => $accountId,
                    ]);
                }
            }, 'consignment_return_lot_allocations.id', 'id');

        return $count;
    }

    private function stampSettlementsAlloc(): int
    {
        if (!Schema::hasTable('settlement_allocations')) {
            return 0;
        }
        $count = 0;
        DB::table('settlement_allocations')
            ->join('settlements', 'settlements.id', '=', 'settlement_allocations.settlement_id')
            ->whereNull('settlement_allocations.supplier_account_id')
            ->whereNotNull('settlements.supplier_id')
            ->whereNotNull('settlements.branch_id')
            ->select(
                'settlement_allocations.id',
                'settlements.branch_id',
                'settlements.supplier_id'
            )
            ->orderBy('settlement_allocations.id')
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $accountId = $this->accountId((int) $row->branch_id, (int) $row->supplier_id);
                    if (!$accountId) {
                        continue;
                    }
                    $count += DB::table('settlement_allocations')->where('id', $row->id)->update([
                        'supplier_account_id' => $accountId,
                    ]);
                }
            }, 'settlement_allocations.id', 'id');

        return $count;
    }

    private function createAccount(int $branchId, int $supplierId): SupplierAccount
    {
        $existing = SupplierAccount::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplierId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $supplier = Supplier::query()->whereKey($supplierId)->first();

        return SupplierAccount::create([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'display_name' => $supplier?->name ?: ('Supplier '.$supplierId),
            'type' => $supplier?->type ?: 'publisher',
            'status' => 'active',
            'phone' => $supplier?->phone,
            'email' => $supplier?->email,
            'address' => $supplier?->address,
            'city' => $supplier?->city,
        ]);
    }

    private function accountId(int $branchId, int $supplierId): ?int
    {
        $ids = SupplierAccount::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplierId)
            ->pluck('id');
        if ($ids->count() !== 1) {
            return null;
        }

        return (int) $ids->first();
    }

    /** @return array<string, int> */
    private function nullCounts(): array
    {
        $out = [];
        foreach (self::STAMP_TABLES as $table) {
            $out[$table] = $this->nullCount($table);
        }

        return $out;
    }

    private function nullCount(string $table): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'supplier_account_id')) {
            return 0;
        }

        return DB::table($table)->whereNull('supplier_account_id')->count();
    }
}
