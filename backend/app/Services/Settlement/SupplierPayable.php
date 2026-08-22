<?php

namespace App\Services\Settlement;

use App\Models\ConsignmentReceipt;
use App\Support\Money;
use Illuminate\Support\Collection;

class SupplierPayable
{
    public function publisherOwed(ConsignmentReceipt $receipt): string
    {
        return app(SnapshotPayable::class)->receiptGenerated($receipt);
    }

    public function outstanding(ConsignmentReceipt $receipt): string
    {
        return app(SnapshotPayable::class)->receiptOutstanding($receipt);
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
