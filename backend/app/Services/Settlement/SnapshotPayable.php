<?php

namespace App\Services\Settlement;

use App\Exceptions\DomainException;
use App\Models\ConsignmentReceipt;
use App\Models\CustomerReturnLotAllocation;
use App\Models\GiftLotAllocation;
use App\Models\SaleLotAllocation;
use App\Models\SettlementAllocation;
use App\Support\Money;
use Carbon\Carbon;

class SnapshotPayable
{
    public function saleOpen(SaleLotAllocation $alloc): string
    {
        return $this->saleOpenAsOf($alloc, now());
    }

    public function saleOpenAsOf(SaleLotAllocation $alloc, Carbon $asOf): string
    {
        if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
            throw new DomainException('بدهی امانی مُهر نشده است');
        }
        if (!$this->saleOccurredAsOf($alloc, $asOf)) {
            return '0.00';
        }
        $settled = $this->effectiveSumAsOf(fn ($q) => $q->where('sale_lot_allocation_id', $alloc->id), $asOf);
        $unsettledReversed = $this->unsettledReversedAsOf($alloc, $asOf);

        return Money::max('0', Money::sub(Money::sub($alloc->publisher_payable, $settled), $unsettledReversed));
    }

    public function giftOpen(GiftLotAllocation $alloc): string
    {
        return $this->giftOpenAsOf($alloc, now());
    }

    public function giftOpenAsOf(GiftLotAllocation $alloc, Carbon $asOf): string
    {
        if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
            throw new DomainException('بدهی امانی مُهر نشده است');
        }
        if (!$this->giftOccurredAsOf($alloc, $asOf)) {
            return '0.00';
        }
        $settled = $this->effectiveSumAsOf(fn ($q) => $q->where('gift_lot_allocation_id', $alloc->id), $asOf);

        return Money::max('0', Money::sub($alloc->publisher_payable, $settled));
    }

    public function operationalSupplierOpenAsOf(int $supplierId, string $currency, ?int $branchId, Carbon $asOf): string
    {
        if ($supplierId <= 0) {
            return '0.00';
        }
        $sum = '0.00';
        $sales = SaleLotAllocation::query()
            ->with(['invoiceItem.invoice', 'lot'])
            ->whereHas('lot', function ($q) use ($supplierId, $currency, $branchId) {
                $q->where('supplier_id', $supplierId)->where('currency', $currency);
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }
            })
            ->get();
        foreach ($sales as $alloc) {
            if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
                continue;
            }
            $sum = Money::add($sum, $this->saleOpenAsOf($alloc, $asOf));
        }
        $gifts = GiftLotAllocation::query()
            ->with('gift')
            ->where('ownership_type', 'consignment')
            ->whereHas('lot', function ($q) use ($supplierId, $currency, $branchId) {
                $q->where('supplier_id', $supplierId)->where('currency', $currency);
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }
            })
            ->get();
        foreach ($gifts as $alloc) {
            if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
                continue;
            }
            $sum = Money::add($sum, $this->giftOpenAsOf($alloc, $asOf));
        }

        return $sum;
    }

    public function receiptGenerated(ConsignmentReceipt $receipt): string
    {
        $sum = '0.00';
        foreach ($this->saleAllocationsForReceipt($receipt) as $alloc) {
            $sum = Money::add($sum, $alloc->publisher_payable ?? 0);
        }
        foreach ($this->giftAllocationsForReceipt($receipt) as $alloc) {
            $sum = Money::add($sum, $alloc->publisher_payable ?? 0);
        }

        return $sum;
    }

    public function receiptEffectiveSettled(ConsignmentReceipt $receipt): string
    {
        return $this->effectiveSum(fn ($q) => $q->where('consignment_receipt_id', $receipt->id));
    }

    public function receiptOutstanding(ConsignmentReceipt $receipt): string
    {
        $open = '0.00';
        foreach ($this->saleAllocationsForReceipt($receipt) as $alloc) {
            $open = Money::add($open, $this->saleOpen($alloc));
        }
        foreach ($this->giftAllocationsForReceipt($receipt) as $alloc) {
            $open = Money::add($open, $this->giftOpen($alloc));
        }

        return $open;
    }

    public function syncReceipt(ConsignmentReceipt $receipt): void
    {
        $generated = $this->receiptGenerated($receipt);
        $settled = $this->receiptEffectiveSettled($receipt);
        $open = $this->receiptOutstanding($receipt);

        if (Money::cmp($open, '0') > 0 && Money::isZero($settled)) {
            $status = 'unsettled';
        } elseif (Money::cmp($open, '0') > 0) {
            $status = 'partially_settled';
        } elseif (Money::cmp($generated, '0') > 0) {
            $status = 'settled';
        } else {
            $status = 'unsettled';
        }

        $receipt->forceFill([
            'settled_amount' => $settled,
            'status' => $status,
        ])->save();
    }

    /** @return \Illuminate\Support\Collection<int, SaleLotAllocation> */
    public function saleAllocationsForReceipt(ConsignmentReceipt $receipt)
    {
        $itemIds = $receipt->items()->pluck('id');

        return SaleLotAllocation::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
            ->where('stock_lots.ownership_type', 'consignment')
            ->whereIn('stock_lots.consignment_receipt_item_id', $itemIds->all() ?: [0])
            ->select('sale_lot_allocations.*')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, GiftLotAllocation> */
    public function giftAllocationsForReceipt(ConsignmentReceipt $receipt)
    {
        $itemIds = $receipt->items()->pluck('id');

        return GiftLotAllocation::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'gift_lot_allocations.stock_lot_id')
            ->where('gift_lot_allocations.ownership_type', 'consignment')
            ->whereIn('stock_lots.consignment_receipt_item_id', $itemIds->all() ?: [0])
            ->select('gift_lot_allocations.*')
            ->get();
    }

    private function effectiveSum(callable $constrain): string
    {
        return $this->effectiveSumAsOf($constrain, now());
    }

    private function effectiveSumAsOf(callable $constrain, Carbon $asOf): string
    {
        $q = SettlementAllocation::query()->with('settlement');
        $constrain($q);

        $sum = '0.00';
        foreach ($q->get() as $row) {
            $settlement = $row->settlement;
            if (!$settlement || !EffectiveSettlement::isEffectiveAsOf($settlement, $asOf)) {
                continue;
            }
            $sum = Money::add($sum, $row->amount);
        }

        return $sum;
    }

    private function saleOccurredAsOf(SaleLotAllocation $alloc, Carbon $asOf): bool
    {
        $soldAt = $alloc->invoiceItem?->invoice?->sold_at;
        if ($soldAt === null && !$alloc->relationLoaded('invoiceItem')) {
            $alloc->load('invoiceItem.invoice');
            $soldAt = $alloc->invoiceItem?->invoice?->sold_at;
        }

        return $soldAt !== null && $soldAt->lte($asOf);
    }

    private function giftOccurredAsOf(GiftLotAllocation $alloc, Carbon $asOf): bool
    {
        $giftedAt = $alloc->gift?->gifted_at;
        if ($giftedAt === null && !$alloc->relationLoaded('gift')) {
            $alloc->load('gift');
            $giftedAt = $alloc->gift?->gifted_at;
        }
        if ($giftedAt === null) {
            return false;
        }

        return Carbon::parse($giftedAt)->startOfDay()->lte($asOf);
    }

    private function unsettledReversedAsOf(SaleLotAllocation $alloc, Carbon $asOf): string
    {
        $sum = '0.00';
        $rows = CustomerReturnLotAllocation::query()
            ->with('customerReturn')
            ->where('sale_lot_allocation_id', $alloc->id)
            ->get();
        foreach ($rows as $row) {
            $returnedAt = $row->customerReturn?->returned_at;
            if ($returnedAt === null || $returnedAt->gt($asOf)) {
                continue;
            }
            $sum = Money::add($sum, $row->unsettled_payable_reversed ?? 0);
        }

        return $sum;
    }
}
