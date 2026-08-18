<?php

namespace App\Services\Settlement;

use App\Exceptions\DomainException;
use App\Models\GiftLotAllocation;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Support\ConsignmentFinance;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PeriodSettlement
{
    /**
     * @return array{total_payable: string, lines: list<array<string, mixed>>, commission_rate: string}
     */
    public function preview(int $supplierId, string $currency, string $periodStart, string $periodEnd, ?int $branchId = null): array
    {
        $lines = $this->openLines($supplierId, $currency, $periodStart, $periodEnd, $branchId);
        $total = '0.00';
        foreach ($lines as $line) {
            $total = Money::add($total, $line['open_amount']);
        }

        return [
            'total_payable' => $total,
            'lines' => $lines,
            'commission_rate' => ConsignmentFinance::commissionRate(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
        ];
    }

    public function settle(
        Settlement $settlement,
        string $periodStart,
        string $periodEnd,
        string $amount,
        ?string $expectedTotal = null
    ): array {
        $preview = $this->preview(
            (int) $settlement->supplier_id,
            $settlement->currency,
            $periodStart,
            $periodEnd,
            $settlement->branch_id ? (int) $settlement->branch_id : null
        );

        if ($expectedTotal !== null && Money::cmp($expectedTotal, $preview['total_payable']) !== 0) {
            throw new DomainException('مبلغ قابل پرداخت از زمان پیش‌نمایش تغییر کرده است', 409, [
                'current_payable' => $preview['total_payable'],
            ]);
        }

        if (Money::cmp($amount, $preview['total_payable']) > 0) {
            throw new DomainException('مبلغ تسویه بیشتر از بدهی قابل پرداخت است', 422, [
                'max_payable' => $preview['total_payable'],
            ]);
        }

        $remaining = Money::of($amount);
        $created = [];
        foreach ($preview['lines'] as $line) {
            if (Money::cmp($remaining, '0') <= 0) {
                break;
            }
            $pay = Money::min($remaining, $line['open_amount']);
            if (Money::isZero($pay)) {
                continue;
            }

            if ($line['kind'] === 'sale') {
                $alloc = SaleLotAllocation::where('id', $line['id'])->lockForUpdate()->firstOrFail();
                $alloc->increment('settled_publisher_amount', $pay);
            } else {
                $alloc = GiftLotAllocation::where('id', $line['id'])->lockForUpdate()->firstOrFail();
                $alloc->increment('settled_publisher_amount', $pay);
            }

            $created[] = SettlementAllocation::create([
                'settlement_id' => $settlement->id,
                'consignment_receipt_id' => $line['consignment_receipt_id'],
                'consignment_receipt_item_id' => $line['consignment_receipt_item_id'],
                'sale_lot_allocation_id' => $line['kind'] === 'sale' ? $line['id'] : null,
                'gift_lot_allocation_id' => $line['kind'] === 'gift' ? $line['id'] : null,
                'amount' => $pay,
                'currency' => $settlement->currency,
                'quantity' => $line['open_qty'],
                'unit_cost' => $line['unit_cost'],
            ]);
            $remaining = Money::sub($remaining, $pay);
        }

        if (Money::cmp($remaining, '0') > 0) {
            throw new DomainException('مبلغ تسویه بیش از اقلام قابل تخصیص است', 422, [
                'unapplied' => $remaining,
            ]);
        }

        $this->touchReceipts($created);

        return $created;
    }

    /** @return list<array<string, mixed>> */
    public function openLines(int $supplierId, string $currency, string $periodStart, string $periodEnd, ?int $branchId): array
    {
        $sales = SaleLotAllocation::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'sale_lot_allocations.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('stock_lots.ownership_type', 'consignment')
            ->where('stock_lots.supplier_id', $supplierId)
            ->where('sale_lot_allocations.currency', $currency)
            ->whereDate('invoices.created_at', '>=', $periodStart)
            ->whereDate('invoices.created_at', '<=', $periodEnd)
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->select(
                'sale_lot_allocations.*',
                'stock_lots.consignment_receipt_item_id',
                'stock_lots.supplier_id'
            )
            ->get();

        $gifts = GiftLotAllocation::query()
            ->join('gifts', 'gifts.id', '=', 'gift_lot_allocations.gift_id')
            ->join('stock_lots', 'stock_lots.id', '=', 'gift_lot_allocations.stock_lot_id')
            ->where('gift_lot_allocations.ownership_type', 'consignment')
            ->where('gift_lot_allocations.supplier_id', $supplierId)
            ->where('gift_lot_allocations.currency', $currency)
            ->whereDate('gifts.gifted_at', '>=', $periodStart)
            ->whereDate('gifts.gifted_at', '<=', $periodEnd)
            ->when($branchId, fn ($q) => $q->where('gifts.branch_id', $branchId))
            ->lockForUpdate()
            ->select('gift_lot_allocations.*', 'stock_lots.consignment_receipt_item_id')
            ->get();

        $lines = [];
        foreach ($sales as $alloc) {
            $openQty = max(0, (int) $alloc->quantity - (int) $alloc->quantity_returned);
            if ($openQty <= 0) {
                continue;
            }
            $owed = ConsignmentFinance::publisherShare(Money::mul($alloc->unit_cost, $openQty));
            $open = Money::sub($owed, $alloc->settled_publisher_amount ?? 0);
            if (Money::cmp($open, '0') <= 0) {
                continue;
            }
            $receiptItemId = $alloc->consignment_receipt_item_id;
            $receiptId = $receiptItemId
                ? DB::table('consignment_receipt_items')->where('id', $receiptItemId)->value('consignment_receipt_id')
                : null;
            if (!$receiptId) {
                $receiptId = $this->fallbackReceiptId($supplierId, $currency);
            }
            $lines[] = [
                'kind' => 'sale',
                'id' => $alloc->id,
                'open_qty' => $openQty,
                'open_amount' => $open,
                'unit_cost' => $alloc->unit_cost,
                'consignment_receipt_id' => $receiptId ? (int) $receiptId : null,
                'consignment_receipt_item_id' => $receiptItemId ? (int) $receiptItemId : null,
            ];
        }

        foreach ($gifts as $alloc) {
            $owed = ConsignmentFinance::publisherShare(Money::mul($alloc->unit_cost, $alloc->quantity));
            $open = Money::sub($owed, $alloc->settled_publisher_amount ?? 0);
            if (Money::cmp($open, '0') <= 0) {
                continue;
            }
            $receiptItemId = $alloc->consignment_receipt_item_id;
            $receiptId = $receiptItemId
                ? DB::table('consignment_receipt_items')->where('id', $receiptItemId)->value('consignment_receipt_id')
                : $this->fallbackReceiptId($supplierId, $currency);
            if (!$receiptId) {
                continue;
            }
            $lines[] = [
                'kind' => 'gift',
                'id' => $alloc->id,
                'open_qty' => (int) $alloc->quantity,
                'open_amount' => $open,
                'unit_cost' => $alloc->unit_cost,
                'consignment_receipt_id' => (int) $receiptId,
                'consignment_receipt_item_id' => $receiptItemId ? (int) $receiptItemId : null,
            ];
        }

        return $lines;
    }

    private function fallbackReceiptId(int $supplierId, string $currency): ?int
    {
        return DB::table('consignment_receipts')
            ->where('supplier_id', $supplierId)
            ->where('currency', $currency)
            ->orderBy('id')
            ->value('id');
    }

    private function touchReceipts(array $allocations): void
    {
        $ids = collect($allocations)->pluck('consignment_receipt_id')->unique()->filter();
        foreach ($ids as $id) {
            $receipt = \App\Models\ConsignmentReceipt::with('items')->find($id);
            if (!$receipt) {
                continue;
            }
            $settled = Money::of(SettlementAllocation::where('consignment_receipt_id', $id)->sum('amount'));
            $receipt->settled_amount = $settled;
            $owed = app(SupplierPayable::class)->publisherOwed($receipt);
            $receipt->status = Money::cmp($settled, $owed) >= 0 && Money::cmp($owed, '0') > 0
                ? 'settled'
                : (Money::cmp($settled, '0') > 0 ? 'partially_settled' : 'unsettled');
            $receipt->save();
        }
    }
}
