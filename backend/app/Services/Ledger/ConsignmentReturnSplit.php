<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\CustomerReturnLotAllocation;
use App\Models\SaleLotAllocation;
use App\Models\SettlementAllocation;
use App\Services\Settlement\EffectiveSettlement;
use App\Support\Money;

final class ConsignmentReturnSplit
{
    /**
     * Persist immutable settled/unsettled splits from locked settlement rows.
     *
     * @return array{publisher_payable_reversed: string, unsettled_payable_reversed: string, settled_payable_reversed: string}
     */
    public function persist(CustomerReturnLotAllocation $returnAlloc): array
    {
        $saleAlloc = SaleLotAllocation::query()
            ->whereKey($returnAlloc->sale_lot_allocation_id)
            ->lockForUpdate()
            ->firstOrFail();

        SettlementAllocation::query()
            ->where('sale_lot_allocation_id', $saleAlloc->id)
            ->lockForUpdate()
            ->get();

        $otherReturns = CustomerReturnLotAllocation::query()
            ->where('sale_lot_allocation_id', $saleAlloc->id)
            ->where('id', '!=', $returnAlloc->id)
            ->lockForUpdate()
            ->get();

        if ($this->alreadyStamped($returnAlloc)) {
            $split = $this->read($returnAlloc);
            $this->assertInvariant($split);
            $this->assertDoesNotExceed($saleAlloc, $otherReturns, $returnAlloc, $split);

            return $split;
        }

        $split = $this->compute($saleAlloc, $otherReturns, (int) $returnAlloc->quantity);
        $returnAlloc->publisher_payable_reversed = $split['publisher_payable_reversed'];
        $returnAlloc->unsettled_payable_reversed = $split['unsettled_payable_reversed'];
        $returnAlloc->settled_payable_reversed = $split['settled_payable_reversed'];
        $returnAlloc->save();

        return $split;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CustomerReturnLotAllocation>  $otherReturns
     * @return array{publisher_payable_reversed: string, unsettled_payable_reversed: string, settled_payable_reversed: string}
     */
    public function compute(SaleLotAllocation $saleAlloc, $otherReturns, int $qty): array
    {
        if ($saleAlloc->publisher_payable === null || $saleAlloc->payable_basis === null) {
            throw new DomainException('بدهی امانی مُهر نشده است');
        }

        $soldQty = (int) $saleAlloc->quantity;
        $returnedQty = (int) $otherReturns->sum('quantity');
        $remainingQty = $soldQty - $returnedQty;
        if ($qty <= 0 || $qty > $remainingQty) {
            throw new DomainException('مقدار مرجوعی از تخصیص فروش بیشتر است');
        }

        $totalPayable = Money::of($saleAlloc->publisher_payable);
        $alreadyReversed = '0.00';
        $alreadyUnsettled = '0.00';
        $alreadySettled = '0.00';
        foreach ($otherReturns as $row) {
            $alreadyReversed = Money::add($alreadyReversed, $row->publisher_payable_reversed);
            $alreadyUnsettled = Money::add($alreadyUnsettled, $row->unsettled_payable_reversed);
            $alreadySettled = Money::add($alreadySettled, $row->settled_payable_reversed);
        }

        $remainingPayable = Money::sub($totalPayable, $alreadyReversed);
        $thisPayable = $this->portion($remainingPayable, $remainingQty, $qty);

        $totalSettledPaid = '0.00';
        $paidQuery = SettlementAllocation::query()->where('sale_lot_allocation_id', $saleAlloc->id);
        EffectiveSettlement::scopeAllocations($paidQuery);
        foreach ($paidQuery->get() as $paid) {
            $totalSettledPaid = Money::add($totalSettledPaid, $paid->amount);
        }
        if (Money::cmp($totalSettledPaid, $totalPayable) > 0) {
            throw new DomainException('مبلغ تسویه‌شده از بدهی تخصیص بیشتر است');
        }

        $remainingUnsettled = Money::sub(Money::sub($totalPayable, $totalSettledPaid), $alreadyUnsettled);
        $remainingSettled = Money::sub($totalSettledPaid, $alreadySettled);
        if (Money::isNegative($remainingUnsettled) || Money::isNegative($remainingSettled)) {
            throw new DomainException('تقسیم مرجوعی امانی با تسویه‌های ثبت‌شده ناسازگار است');
        }

        $unsettled = Money::min($thisPayable, $remainingUnsettled);
        $settled = Money::sub($thisPayable, $unsettled);
        if (Money::cmp($settled, $remainingSettled) > 0) {
            throw new DomainException('سهم تسویه‌شده مرجوعی از تخصیص اصلی بیشتر است');
        }

        $split = [
            'publisher_payable_reversed' => $thisPayable,
            'unsettled_payable_reversed' => $unsettled,
            'settled_payable_reversed' => $settled,
        ];
        $this->assertInvariant($split);

        return $split;
    }

    /**
     * @return array{publisher_payable_reversed: string, unsettled_payable_reversed: string, settled_payable_reversed: string}
     */
    public function read(CustomerReturnLotAllocation $row): array
    {
        return [
            'publisher_payable_reversed' => Money::of($row->publisher_payable_reversed),
            'unsettled_payable_reversed' => Money::of($row->unsettled_payable_reversed),
            'settled_payable_reversed' => Money::of($row->settled_payable_reversed),
        ];
    }

    /**
     * @param  array{publisher_payable_reversed: string, unsettled_payable_reversed: string, settled_payable_reversed: string}  $split
     */
    public function assertInvariant(array $split): void
    {
        $sum = Money::add($split['unsettled_payable_reversed'], $split['settled_payable_reversed']);
        if (Money::cmp($sum, $split['publisher_payable_reversed']) !== 0) {
            throw new DomainException('تقسیم مرجوعی امانی متعادل نیست');
        }
    }

    private function alreadyStamped(CustomerReturnLotAllocation $row): bool
    {
        return !Money::isZero($row->publisher_payable_reversed)
            || !Money::isZero($row->unsettled_payable_reversed)
            || !Money::isZero($row->settled_payable_reversed);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CustomerReturnLotAllocation>  $otherReturns
     * @param  array{publisher_payable_reversed: string, unsettled_payable_reversed: string, settled_payable_reversed: string}  $split
     */
    private function assertDoesNotExceed(
        SaleLotAllocation $saleAlloc,
        $otherReturns,
        CustomerReturnLotAllocation $returnAlloc,
        array $split
    ): void {
        $soldQty = (int) $saleAlloc->quantity;
        $returnedQty = (int) $otherReturns->sum('quantity') + (int) $returnAlloc->quantity;
        if ($returnedQty > $soldQty) {
            throw new DomainException('مقدار مرجوعی از تخصیص فروش بیشتر است');
        }
        $already = '0.00';
        foreach ($otherReturns as $row) {
            $already = Money::add($already, $row->publisher_payable_reversed);
        }
        $total = Money::add($already, $split['publisher_payable_reversed']);
        if (Money::cmp($total, $saleAlloc->publisher_payable) > 0) {
            throw new DomainException('مبلغ مرجوعی از بدهی تخصیص بیشتر است');
        }
    }

    private function portion(string $remainingAmount, int $remainingQty, int $qty): string
    {
        if ($qty === $remainingQty) {
            return Money::of($remainingAmount);
        }
        $unit = bcdiv(Money::of($remainingAmount), (string) $remainingQty, 2);

        return Money::mul($unit, $qty);
    }
}
