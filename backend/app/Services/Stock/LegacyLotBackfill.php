<?php

namespace App\Services\Stock;

use App\Models\Branch;
use App\Models\ConsignmentReceiptItem;
use App\Models\Inventory;
use App\Models\StockLot;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyLotBackfill
{
    public function __construct(private StockLotService $lots) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(): array
    {
        $groups = [];
        $rows = DB::table('inventories')->orderBy('id')->get();

        foreach ($rows as $row) {
            $key = $row->branch_id . ':' . $row->book_id;
            $groups[$key] ??= [
                'branch_id' => (int) $row->branch_id,
                'book_id' => (int) $row->book_id,
                'rows' => [],
                'qty' => 0,
                'types' => [],
                'suppliers' => [],
                'currencies' => [],
            ];
            $currency = $this->inferCurrency($row);
            $groups[$key]['rows'][] = $row;
            $groups[$key]['qty'] += (int) $row->quantity;
            $groups[$key]['types'][$row->type ?? 'owned'] = true;
            if ($row->supplier_id) {
                $groups[$key]['suppliers'][$row->supplier_id] = true;
            }
            $groups[$key]['currencies'][$currency] = true;
        }

        $duplicate = 0;
        $mixedOwnership = 0;
        $multiSupplier = 0;
        $multiCurrency = 0;
        $expectedLots = 0;
        foreach ($groups as $g) {
            $n = count($g['rows']);
            $expectedLots += $n;
            if ($n > 1) {
                $duplicate++;
            }
            if (count($g['types']) > 1) {
                $mixedOwnership++;
            }
            if (count($g['suppliers']) > 1) {
                $multiSupplier++;
            }
            if (count($g['currencies']) > 1) {
                $multiCurrency++;
            }
        }

        return [
            'inventory_rows' => $rows->count(),
            'groups' => count($groups),
            'duplicate_inventory_groups' => $duplicate,
            'mixed_ownership_groups' => $mixedOwnership,
            'multi_supplier_groups' => $multiSupplier,
            'multi_currency_groups' => $multiCurrency,
            'expected_lots' => $expectedLots,
            'existing_lots' => Schema::hasTable('stock_lots') ? StockLot::count() : 0,
            'unmatched_consignment_qty' => $this->unmatchedConsignmentQty(),
        ];
    }

    public function run(bool $dryRun = false): array
    {
        $report = $this->analyze();
        $report['created_snapshots'] = 0;
        $report['created_lots'] = 0;
        $report['uncertain_lots'] = 0;
        $report['consolidated_groups'] = 0;
        $report['before'] = $this->totals();

        if ($dryRun) {
            $report['after'] = $report['before'];
            $report['dry_run'] = true;

            return $report;
        }

        DB::transaction(function () use (&$report) {
            $inventories = DB::table('inventories')->orderBy('id')->lockForUpdate()->get();
            $receiptRemain = $this->receiptRemainders();

            foreach ($inventories as $row) {
                $this->snapshotRow($row, $report);
                $this->lotFromRow($row, $receiptRemain, $report);
            }

            $report['consolidated_groups'] = $this->consolidateAggregates();
        });

        $report['after'] = $this->totals();
        $report['uncertain_lots'] = StockLot::where('legacy_uncertain', true)->count();
        $report['existing_lots'] = StockLot::count();
        $report['dry_run'] = false;

        return $report;
    }

    private function snapshotRow(object $row, array &$report): void
    {
        $exists = DB::table('legacy_inventory_snapshots')
            ->where('original_inventory_id', $row->id)
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('legacy_inventory_snapshots')->insert([
            'original_inventory_id' => $row->id,
            'branch_id' => $row->branch_id,
            'book_id' => $row->book_id,
            'ownership_type' => $row->type,
            'supplier_id' => $row->supplier_id,
            'cost_price_toman' => $row->cost_price_toman ?? null,
            'cost_price_dinar' => $row->cost_price_dinar ?? null,
            'price_toman' => $row->price_toman ?? null,
            'price_dinar' => $row->price_dinar ?? null,
            'quantity' => $row->quantity,
            'raw' => json_encode($row),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $report['created_snapshots']++;
    }

    private function lotFromRow(object $row, array &$receiptRemain, array &$report): void
    {
        if (StockLot::where('legacy_inventory_id', $row->id)->exists()) {
            return;
        }

        $qty = max(0, (int) $row->quantity);
        if ($qty === 0) {
            return;
        }

        $currency = $this->inferCurrency($row);
        $unitCost = $currency === 'dinar'
            ? Money::of($row->cost_price_dinar ?? 0)
            : Money::of($row->cost_price_toman ?? 0);
        $ownership = ($row->type === 'consignment') ? 'consignment' : 'owned';
        $receiptItemId = null;
        $uncertain = false;

        if ($ownership === 'consignment') {
            $match = $this->matchReceiptItem($row, $currency, $unitCost, $qty, $receiptRemain);
            $receiptItemId = $match['receipt_item_id'];
            $uncertain = $match['uncertain'];
            if (!$receiptItemId) {
                $uncertain = true;
            }
        }

        $branch = Branch::find($row->branch_id);
        StockLot::create([
            'book_id' => $row->book_id,
            'branch_id' => $row->branch_id,
            'consignment_receipt_item_id' => $receiptItemId,
            'supplier_id' => $row->supplier_id,
            'ownership_type' => $ownership,
            'currency' => $currency,
            'unit_cost' => $unitCost,
            'qty_original' => $qty,
            'qty_available' => $qty,
            'qty_reserved' => 0,
            'origin' => $branch ? $this->lots->resolveOrigin($branch) : 'other',
            'legacy_uncertain' => $uncertain,
            'legacy_inventory_id' => $row->id,
            'migration_source' => 'legacy_inventory',
        ]);
        $report['created_lots']++;
        if ($uncertain) {
            $report['uncertain_lots']++;
        }
    }

    /**
     * @param  array<int, int>  $receiptRemain
     * @return array{receipt_item_id: ?int, uncertain: bool}
     */
    private function matchReceiptItem(object $row, string $currency, string $unitCost, int $qty, array &$receiptRemain): array
    {
        $items = ConsignmentReceiptItem::query()
            ->where('book_id', $row->book_id)
            ->whereHas('consignmentReceipt', function ($q) use ($row, $currency) {
                $q->where('branch_id', $row->branch_id)
                    ->where('currency', $currency);
                if ($row->supplier_id) {
                    $q->where('supplier_id', $row->supplier_id);
                }
            })
            ->orderBy('id')
            ->get();

        $exact = [];
        foreach ($items as $item) {
            $remain = $receiptRemain[$item->id] ?? $this->itemOpenQty($item);
            $receiptRemain[$item->id] = $remain;
            if ($remain <= 0) {
                continue;
            }
            if (Money::cmp($item->cost_price, $unitCost) === 0 && $remain === $qty) {
                $exact[] = $item;
            }
        }

        if (count($exact) === 1) {
            $item = $exact[0];
            $receiptRemain[$item->id] = ($receiptRemain[$item->id] ?? $this->itemOpenQty($item)) - $qty;

            return ['receipt_item_id' => (int) $item->id, 'uncertain' => false];
        }

        foreach ($items as $item) {
            $remain = $receiptRemain[$item->id] ?? $this->itemOpenQty($item);
            if ($remain >= $qty && Money::cmp($item->cost_price, $unitCost) === 0) {
                $receiptRemain[$item->id] = $remain - $qty;

                return ['receipt_item_id' => (int) $item->id, 'uncertain' => count($items) > 1];
            }
        }

        return ['receipt_item_id' => null, 'uncertain' => true];
    }

    private function itemOpenQty(ConsignmentReceiptItem $item): int
    {
        return max(0, (int) $item->quantity_received - (int) $item->quantity_sold - (int) $item->quantity_returned);
    }

    /** @return array<int, int> */
    private function receiptRemainders(): array
    {
        $remain = [];
        ConsignmentReceiptItem::query()->orderBy('id')->each(function (ConsignmentReceiptItem $item) use (&$remain) {
            $remain[$item->id] = $this->itemOpenQty($item);
        });

        return $remain;
    }

    private function consolidateAggregates(): int
    {
        $groups = DB::table('inventories')
            ->select('branch_id', 'book_id', DB::raw('COUNT(*) as c'))
            ->groupBy('branch_id', 'book_id')
            ->having('c', '>', 1)
            ->get();

        $n = 0;
        foreach ($groups as $g) {
            $ids = DB::table('inventories')
                ->where('branch_id', $g->branch_id)
                ->where('book_id', $g->book_id)
                ->orderBy('id')
                ->pluck('id');
            $canonical = $ids->first();
            $sum = (int) StockLot::where('branch_id', $g->branch_id)
                ->where('book_id', $g->book_id)
                ->sum('qty_available');
            DB::table('inventories')->where('id', $canonical)->update(['quantity' => $sum]);
            DB::table('inventories')
                ->whereIn('id', $ids->skip(1)->all())
                ->update([
                    'superseded_by_inventory_id' => $canonical,
                    'quantity' => 0,
                ]);
            $n++;
        }

        foreach (DB::table('inventories')->whereNull('superseded_by_inventory_id')->get() as $inv) {
            $sum = (int) StockLot::where('branch_id', $inv->branch_id)
                ->where('book_id', $inv->book_id)
                ->sum('qty_available');
            if ((int) $inv->quantity !== $sum) {
                DB::table('inventories')->where('id', $inv->id)->update(['quantity' => $sum]);
            }
        }

        return $n;
    }

    private function inferCurrency(object $row): string
    {
        $dinar = Money::of($row->cost_price_dinar ?? 0);
        $toman = Money::of($row->cost_price_toman ?? 0);
        if (!Money::isZero($dinar) && Money::isZero($toman)) {
            return 'dinar';
        }

        return 'toman';
    }

    private function unmatchedConsignmentQty(): int
    {
        $qty = 0;
        foreach (DB::table('inventories')->where('type', 'consignment')->get() as $row) {
            $open = (int) ConsignmentReceiptItem::query()
                ->where('book_id', $row->book_id)
                ->whereHas('consignmentReceipt', function ($q) use ($row) {
                    $q->where('branch_id', $row->branch_id);
                    if ($row->supplier_id) {
                        $q->where('supplier_id', $row->supplier_id);
                    }
                })
                ->get()
                ->sum(fn ($i) => $this->itemOpenQty($i));
            $gap = (int) $row->quantity - $open;
            if ($gap > 0) {
                $qty += $gap;
            }
        }

        return $qty;
    }

    /** @return list<array<string, mixed>> */
    private function totals(): array
    {
        $rows = DB::table('inventories')
            ->select('branch_id', 'book_id', 'type', 'supplier_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('branch_id', 'book_id', 'type', 'supplier_id')
            ->get();

        return $rows->map(fn ($r) => [
            'branch_id' => (int) $r->branch_id,
            'book_id' => (int) $r->book_id,
            'type' => $r->type,
            'supplier_id' => $r->supplier_id ? (int) $r->supplier_id : null,
            'quantity' => (int) $r->qty,
        ])->all();
    }
}
