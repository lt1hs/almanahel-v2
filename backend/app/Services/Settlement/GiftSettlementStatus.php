<?php

namespace App\Services\Settlement;

use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Derives consignment-gift settlement status from stamped payable + settlement allocations.
 * Owned-only gifts are not_applicable; never treat gifts.accounting_status as source of truth for AP.
 */
class GiftSettlementStatus
{
    public function __construct(
        private readonly SnapshotPayable $snapshots,
    ) {}

    /**
     * @return array{
     *   settlement_status: string,
     *   gross_gift_payable: string,
     *   settled_amount: string,
     *   remaining_payable: string,
     *   has_consignment: bool,
     *   has_owned: bool
     * }
     */
    public function forGift(Gift $gift): array
    {
        $allocs = GiftLotAllocation::query()
            ->where('gift_id', $gift->id)
            ->get();

        return $this->forAllocations($allocs);
    }

    /**
     * @param  Collection<int, GiftLotAllocation>|list<GiftLotAllocation>  $allocs
     * @return array{
     *   settlement_status: string,
     *   gross_gift_payable: string,
     *   settled_amount: string,
     *   remaining_payable: string,
     *   has_consignment: bool,
     *   has_owned: bool
     * }
     */
    public function forAllocations($allocs): array
    {
        $gross = '0.00';
        $remaining = '0.00';
        $hasConsignment = false;
        $hasOwned = false;

        foreach ($allocs as $alloc) {
            if ($alloc->ownership_type === 'consignment') {
                $hasConsignment = true;
                if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
                    continue;
                }
                $gross = Money::add($gross, $alloc->publisher_payable);
                $remaining = Money::add($remaining, $this->snapshots->giftOpen($alloc));
            } else {
                $hasOwned = true;
            }
        }

        if (!$hasConsignment) {
            return [
                'settlement_status' => 'not_applicable',
                'gross_gift_payable' => '0.00',
                'settled_amount' => '0.00',
                'remaining_payable' => '0.00',
                'has_consignment' => false,
                'has_owned' => $hasOwned,
            ];
        }

        $settled = Money::max('0', Money::sub($gross, $remaining));
        $status = match (true) {
            Money::isZero($gross) => 'not_applicable',
            Money::isZero($settled) && Money::cmp($remaining, '0') > 0 => 'unsettled',
            Money::cmp($remaining, '0') > 0 => 'partially_settled',
            default => 'settled',
        };

        return [
            'settlement_status' => $status,
            'gross_gift_payable' => $gross,
            'settled_amount' => $settled,
            'remaining_payable' => $remaining,
            'has_consignment' => true,
            'has_owned' => $hasOwned,
        ];
    }

    /**
     * Sync denormalized gifts.accounting_status from allocation-derived state.
     * pending ↔ unsettled/partially_settled; settled ↔ settled.
     */
    public function syncAccountingStatus(Gift $gift): Gift
    {
        $profile = $this->forGift($gift);
        if ($profile['settlement_status'] === 'not_applicable') {
            return $gift;
        }

        $next = $profile['settlement_status'] === 'settled' ? 'settled' : 'pending';
        if ($gift->accounting_status !== $next) {
            $gift->forceFill(['accounting_status' => $next])->save();
        }

        return $gift->fresh();
    }

    /** @param  list<int>  $giftIds */
    public function syncMany(array $giftIds): void
    {
        foreach (array_unique(array_filter($giftIds)) as $id) {
            $gift = Gift::query()->find($id);
            if ($gift) {
                $this->syncAccountingStatus($gift);
            }
        }
    }
}
