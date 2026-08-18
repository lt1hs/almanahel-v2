<?php

namespace App\Services\Settlement;

use App\Models\ConsignmentReceipt;
use App\Support\ConsignmentFinance;
use App\Support\Money;
use Illuminate\Support\Collection;

class SupplierPayable
{
    public function soldCost(ConsignmentReceipt $receipt): string
    {
        $receipt->loadMissing('items');
        $sum = '0.00';
        foreach ($receipt->items as $item) {
            $sum = Money::add($sum, Money::mul($item->cost_price, (int) $item->quantity_sold));
        }

        return $sum;
    }

    public function publisherOwed(ConsignmentReceipt $receipt): string
    {
        return ConsignmentFinance::publisherShare($this->soldCost($receipt));
    }

    public function outstanding(ConsignmentReceipt $receipt): string
    {
        return Money::max('0', Money::sub($this->publisherOwed($receipt), $receipt->settled_amount));
    }

    public function eligibleReceipts(int $supplierId, string $currency, string $periodStart, string $periodEnd, ?int $branchId = null): Collection
    {
        $preview = app(PeriodSettlement::class)->preview($supplierId, $currency, $periodStart, $periodEnd, $branchId);
        $ids = collect($preview['lines'])->pluck('consignment_receipt_id')->filter()->unique();

        return ConsignmentReceipt::with('items')
            ->whereIn('id', $ids->all() ?: [0])
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    public function totalPayable(Collection $receipts): string
    {
        $sum = '0.00';
        foreach ($receipts as $receipt) {
            $sum = Money::add($sum, $this->outstanding($receipt));
        }

        return $sum;
    }
}
