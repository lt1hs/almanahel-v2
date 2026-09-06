<?php

namespace App\Services\Pricing;

use App\Models\BookBranchPrice;
use App\Models\BranchCatalogItem;
use App\Models\Inventory;
use App\Models\SellingPriceRevision;
use App\Models\StockLot;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PriceBackfill
{
    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        return $this->run(false, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(bool $apply, bool $auditOnly = false): array
    {
        $conflicts = [];
        $sellingWouldCreate = 0;
        $sellingCreated = 0;
        $sellingSkippedConflict = 0;
        $sellingSkippedExisting = 0;
        $lotsWouldFill = 0;
        $lotsFilled = 0;

        $inventories = Inventory::query()
            ->whereNull('superseded_by_inventory_id')
            ->orderBy('branch_id')
            ->orderBy('book_id')
            ->get();

        foreach ($inventories as $inv) {
            $catalog = BranchCatalogItem::query()
                ->where('branch_id', $inv->branch_id)
                ->where('book_id', $inv->book_id)
                ->first();
            $bbp = BookBranchPrice::query()
                ->where('branch_id', $inv->branch_id)
                ->where('book_id', $inv->book_id)
                ->first();

            foreach (['toman' => 'price_toman', 'dinar' => 'price_dinar'] as $currency => $column) {
                $invPrice = $this->nullableMoney($inv->{$column});
                $bbpPrice = $bbp ? $this->nullableMoney($bbp->{$column}) : null;
                $catPrice = $catalog ? $this->nullableMoney($catalog->{$column}) : null;

                $present = array_filter([$invPrice, $bbpPrice, $catPrice], fn ($v) => $v !== null);
                $unique = array_unique($present);
                if (count($unique) > 1) {
                    $conflicts[] = [
                        'book_id' => (int) $inv->book_id,
                        'branch_id' => (int) $inv->branch_id,
                        'currency' => $currency,
                        'inventories' => $invPrice,
                        'book_branch_prices' => $bbpPrice,
                        'branch_catalog_items' => $catPrice,
                    ];
                    $sellingSkippedConflict++;
                    continue;
                }

                if ($auditOnly) {
                    continue;
                }

                $source = $invPrice ?? $bbpPrice;
                if ($source === null) {
                    continue;
                }

                $already = $bbp && $bbpPrice !== null && SellingPriceRevision::query()
                    ->where('book_id', $inv->book_id)
                    ->where('branch_id', $inv->branch_id)
                    ->where('currency', $currency)
                    ->where('reason', 'legacy_backfill')
                    ->exists();
                if ($already || ($bbp && $bbpPrice !== null && (int) $bbp->{$bbp->revisionColumn($currency)})) {
                    $sellingSkippedExisting++;
                    continue;
                }

                $sellingWouldCreate++;
                if (!$apply) {
                    continue;
                }

                DB::transaction(function () use ($inv, $currency, $source, $column) {
                    $row = BookBranchPrice::query()
                        ->where('book_id', $inv->book_id)
                        ->where('branch_id', $inv->branch_id)
                        ->lockForUpdate()
                        ->first();
                    if (!$row) {
                        $row = BookBranchPrice::create([
                            'book_id' => $inv->book_id,
                            'branch_id' => $inv->branch_id,
                            'price_toman_version' => 1,
                            'price_dinar_version' => 1,
                        ]);
                    }
                    $revision = SellingPriceRevision::create([
                        'price_change_batch_id' => null,
                        'book_id' => $inv->book_id,
                        'branch_id' => $inv->branch_id,
                        'currency' => $currency,
                        'old_price' => null,
                        'new_price' => $source,
                        'version' => 1,
                        'previous_revision_id' => null,
                        'effective_at' => now(),
                        'created_by' => null,
                        'reason' => 'legacy_backfill',
                    ]);
                    $row->{$column} = $source;
                    $row->{$row->versionColumn($currency)} = 1;
                    $row->{$row->revisionColumn($currency)} = $revision->id;
                    $row->save();
                    $inv->{$column} = $source;
                    $inv->save();
                });
                $sellingCreated++;
            }
        }

        $lotQuery = StockLot::query()
            ->where('ownership_type', 'consignment')
            ->whereNull('payable_unit_cost')
            ->orderBy('id');
        $lotsWouldFill = (clone $lotQuery)->count();
        if ($apply && !$auditOnly) {
            $lotQuery->chunkById(200, function ($lots) use (&$lotsFilled) {
                foreach ($lots as $lot) {
                    $lot->payable_unit_cost = $lot->unit_cost;
                    $lot->save();
                    $lotsFilled++;
                }
            });
        }

        return [
            'conflicts' => $conflicts,
            'conflict_count' => count($conflicts),
            'selling_would_create' => $sellingWouldCreate,
            'selling_created' => $sellingCreated,
            'selling_skipped_conflict' => $sellingSkippedConflict,
            'selling_skipped_existing' => $sellingSkippedExisting,
            'consignment_lots_would_fill' => $lotsWouldFill,
            'consignment_lots_filled' => $lotsFilled,
            'apply' => $apply,
            'audit_only' => $auditOnly,
        ];
    }

    private function nullableMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $money = Money::of($value);
        if (Money::isZero($money)) {
            return null;
        }

        return $money;
    }
}
