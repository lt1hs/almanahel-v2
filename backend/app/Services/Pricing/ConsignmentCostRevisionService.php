<?php

namespace App\Services\Pricing;

use App\Exceptions\DomainException;
use App\Models\ConsignmentCostRevision;
use App\Models\ConsignmentCostRevisionLot;
use App\Models\StockLot;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Money;
use Illuminate\Support\Collection;

class ConsignmentCostRevisionService
{
    /**
     * @param  list<int>  $branchIds
     * @return Collection<int, StockLot>
     */
    public function eligibleLots(
        int $bookId,
        array $branchIds,
        string $currency,
        int $supplierId,
        bool $lock = false,
    ): Collection {
        $q = StockLot::query()
            ->sellable()
            ->where('book_id', $bookId)
            ->whereIn('branch_id', $branchIds)
            ->where('currency', $currency)
            ->where('ownership_type', 'consignment')
            ->where(function ($inner) use ($supplierId) {
                $inner->where('supplier_id', $supplierId)
                    ->orWhereHas('supplierAccount', fn ($sa) => $sa->where('supplier_id', $supplierId));
            })
            ->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->get();
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     */
    public function assertNoReserved(Collection $lots): void
    {
        $reserved = $lots->first(fn (StockLot $lot) => (int) $lot->qty_reserved > 0);
        if ($reserved) {
            throw new DomainException('لات امانی در انتقال است؛ ابتدا انتقال را تکمیل یا لغو کنید', 409, [
                'error' => 'lots_reserved',
                'stock_lot_id' => $reserved->id,
                'branch_id' => $reserved->branch_id,
                'qty_reserved' => (int) $reserved->qty_reserved,
            ]);
        }
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     */
    public function applyToLots(
        Collection $lots,
        int $bookId,
        int $branchId,
        ?int $supplierAccountId,
        string $currency,
        mixed $newCost,
        string $reason,
        ?User $actor,
        ?int $batchId,
    ): ConsignmentCostRevision {
        $amount = Money::of($newCost);
        if (Money::isZero($amount) || Money::isNegative($amount)) {
            throw new DomainException('بهای امانی باید بزرگ‌تر از صفر باشد', 422);
        }

        $this->assertNoReserved($lots);

        $revision = ConsignmentCostRevision::create([
            'price_change_batch_id' => $batchId,
            'book_id' => $bookId,
            'branch_id' => $branchId,
            'supplier_account_id' => $supplierAccountId,
            'currency' => $currency,
            'new_cost' => $amount,
            'effective_at' => now(),
            'created_by' => $actor?->id,
            'reason' => $reason,
        ]);

        foreach ($lots as $lot) {
            $old = $lot->payable_unit_cost !== null && $lot->payable_unit_cost !== ''
                ? Money::of($lot->payable_unit_cost)
                : Money::of($lot->unit_cost);

            ConsignmentCostRevisionLot::create([
                'consignment_cost_revision_id' => $revision->id,
                'stock_lot_id' => $lot->id,
                'old_payable_unit_cost' => $old,
                'new_payable_unit_cost' => $amount,
                'quantity_available_at_change' => (int) $lot->qty_available,
            ]);

            $lot->payable_unit_cost = $amount;
            $lot->current_cost_revision_id = $revision->id;
            $lot->save();
        }

        ActivityLogger::record(
            'pricing',
            'consignment_cost_revised',
            "بهای امانی کتاب #{$bookId} شعبه #{$branchId} ({$currency}) {$amount}",
            $revision,
            [
                'book_id' => $bookId,
                'branch_id' => $branchId,
                'currency' => $currency,
                'new_cost' => $amount,
                'lots' => $lots->count(),
                'reason' => $reason,
                'batch_id' => $batchId,
            ],
            $branchId,
            $actor?->id,
        );

        return $revision;
    }
}
