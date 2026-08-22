<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\CustomerReturnLotAllocation;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Settlement\SnapshotPayable;
use App\Support\Authorization\BranchAccess;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SupplierCheckTransition
{
    public function __construct(
        private readonly FinancePostingGateway $gateway,
        private readonly SnapshotPayable $snapshots,
    ) {
    }

    public function apply(Settlement $settlement, string $to, User $user, ?int $financialAccountId = null): Settlement
    {
        $branchId = $settlement->branch_id ? (int) $settlement->branch_id : null;
        BranchAccess::assertCanMutateSettlement($user, $branchId);

        return DB::transaction(function () use ($settlement, $to, $financialAccountId) {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ((string) $settlement->payment_method !== 'check') {
                throw new DomainException('این تسویه با چک نیست');
            }
            $from = (string) ($settlement->check_status ?: 'pending');
            CheckLifecycle::assertSupplier($from, $to);
            if ($from === $to) {
                return $settlement->load(['supplier', 'branch', 'allocations']);
            }

            if (in_array($to, ['bounced', 'cancelled'], true) && $this->hasSettledReturnSplit($settlement)) {
                throw new DomainException(
                    'برگشت یا ابطال این چک نیازمند تعدیل صریح مرجوعی تسویه‌شده است',
                    409
                );
            }

            $updates = ['check_status' => $to];
            if ($to === 'cleared' && $settlement->cleared_at === null) {
                $updates['cleared_at'] = now();
            }
            if ($to === 'bounced' && $settlement->bounced_at === null) {
                $updates['bounced_at'] = now();
            }
            if ($to === 'cancelled' && $settlement->cancelled_at === null) {
                $updates['cancelled_at'] = now();
            }
            $settlement->update($updates);
            $settlement = $settlement->fresh();

            if ($to === 'cleared') {
                $this->gateway->supplierCheckCleared($settlement, $financialAccountId);
            }
            if ($to === 'bounced') {
                $this->gateway->supplierCheckBounced($settlement, 'supplier_check_bounced');
            }
            if ($to === 'cancelled') {
                $this->gateway->supplierCheckBounced($settlement, 'supplier_check_cancelled');
            }

            $this->syncReceipts($settlement);

            return $settlement->fresh()->load(['supplier', 'branch', 'allocations']);
        });
    }

    private function hasSettledReturnSplit(Settlement $settlement): bool
    {
        $saleIds = $settlement->allocations()->whereNotNull('sale_lot_allocation_id')->pluck('sale_lot_allocation_id');
        if ($saleIds->isEmpty()) {
            return false;
        }

        foreach (CustomerReturnLotAllocation::query()->whereIn('sale_lot_allocation_id', $saleIds->all())->get() as $row) {
            if (Money::cmp($row->settled_payable_reversed ?? 0, '0') > 0) {
                return true;
            }
        }

        return false;
    }

    private function syncReceipts(Settlement $settlement): void
    {
        $ids = $settlement->allocations()->pluck('consignment_receipt_id')->unique()->filter();
        foreach ($ids as $id) {
            $receipt = \App\Models\ConsignmentReceipt::with('items')->find($id);
            if ($receipt) {
                $this->snapshots->syncReceipt($receipt);
            }
        }
    }
}
