<?php

namespace App\Services\Settlement;

use App\Exceptions\DomainException;
use App\Models\ConsignmentReceipt;
use App\Models\GiftLotAllocation;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PeriodSettlement
{
    /**
     * @return array{total_payable: string, lines: list<array<string, mixed>>, commission_rate: string}
     */
    public function preview(
        int $supplierId,
        string $currency,
        string $periodStart,
        string $periodEnd,
        ?int $branchId = null,
        ?int $supplierAccountId = null
    ): array {
        $lines = $this->openLines($supplierId, $currency, $periodStart, $periodEnd, $branchId, $supplierAccountId);
        $salesPayable = '0.00';
        $giftPayable = '0.00';
        $previouslySettled = '0.00';
        $returnReversals = '0.00';
        foreach ($lines as $line) {
            if ($line['kind'] === 'gift') {
                $giftPayable = Money::add($giftPayable, $line['open_amount']);
            } else {
                $salesPayable = Money::add($salesPayable, $line['open_amount']);
            }
            $previouslySettled = Money::add($previouslySettled, $line['settled_amount'] ?? '0.00');
            $returnReversals = Money::add($returnReversals, $line['return_reversals'] ?? '0.00');
        }
        $total = Money::add($salesPayable, $giftPayable);

        return [
            'total_payable' => $total,
            'lines' => $lines,
            'breakdown' => [
                'sales_payable' => $salesPayable,
                'gift_payable' => $giftPayable,
                'return_reversals' => $returnReversals,
                'previously_settled' => $previouslySettled,
                'remaining_payable' => $total,
            ],
            'commission_rate' => '1.0000',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
            'supplier_account_id' => $supplierAccountId,
            'branch_id' => $branchId,
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
            $settlement->branch_id ? (int) $settlement->branch_id : null,
            $settlement->supplier_account_id ? (int) $settlement->supplier_account_id : null
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
        $giftIds = [];
        foreach ($preview['lines'] as $line) {
            if (Money::cmp($remaining, '0') <= 0) {
                break;
            }
            $pay = Money::min($remaining, $line['open_amount']);
            if (Money::isZero($pay)) {
                continue;
            }

            $qtyForAlloc = (int) $line['open_qty'];
            if (Money::cmp($pay, $line['open_amount']) < 0 && !Money::isZero($line['unit_cost'])) {
                $units = (int) bcdiv($pay, Money::of($line['unit_cost']), 0);
                $qtyForAlloc = (int) min($qtyForAlloc, max(1, $units));
            }

            if ($line['kind'] === 'sale') {
                $alloc = SaleLotAllocation::where('id', $line['id'])->lockForUpdate()->firstOrFail();
                $alloc->increment('settled_publisher_amount', $pay);
            } else {
                $alloc = GiftLotAllocation::where('id', $line['id'])->lockForUpdate()->firstOrFail();
                $alloc->increment('settled_publisher_amount', $pay);
                if ($alloc->gift_id) {
                    $giftIds[] = (int) $alloc->gift_id;
                }
            }

            $created[] = SettlementAllocation::create([
                'settlement_id' => $settlement->id,
                'consignment_receipt_id' => $line['consignment_receipt_id'],
                'consignment_receipt_item_id' => $line['consignment_receipt_item_id'],
                'sale_lot_allocation_id' => $line['kind'] === 'sale' ? $line['id'] : null,
                'gift_lot_allocation_id' => $line['kind'] === 'gift' ? $line['id'] : null,
                'amount' => $pay,
                'currency' => $settlement->currency,
                'quantity' => $qtyForAlloc,
                'unit_cost' => $line['unit_cost'],
                'supplier_account_id' => $settlement->supplier_account_id,
            ]);
            $remaining = Money::sub($remaining, $pay);
        }

        if (Money::cmp($remaining, '0') > 0) {
            throw new DomainException('مبلغ تسویه بیش از اقلام قابل تخصیص است', 422, [
                'unapplied' => $remaining,
            ]);
        }

        $this->touchReceipts($created);
        app(GiftSettlementStatus::class)->syncMany($giftIds);

        return $created;
    }

    /** @return list<array<string, mixed>> */
    public function openLines(
        int $supplierId,
        string $currency,
        string $periodStart,
        string $periodEnd,
        ?int $branchId,
        ?int $supplierAccountId = null
    ): array {
        $snapshots = app(SnapshotPayable::class);

        $salesQuery = SaleLotAllocation::query()
            ->join('stock_lots', 'stock_lots.id', '=', 'sale_lot_allocations.stock_lot_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'sale_lot_allocations.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('books', 'books.id', '=', 'invoice_items.book_id')
            ->where('stock_lots.ownership_type', 'consignment')
            ->where('sale_lot_allocations.currency', $currency)
            ->whereDate('invoices.sold_at', '>=', $periodStart)
            ->whereDate('invoices.sold_at', '<=', $periodEnd)
            ->lockForUpdate();

        if ($supplierAccountId) {
            $salesQuery->where('sale_lot_allocations.supplier_account_id', $supplierAccountId);
            if ($branchId) {
                $salesQuery->where('invoices.branch_id', $branchId);
            }
        } else {
            $salesQuery->where('stock_lots.supplier_id', $supplierId);
            if ($branchId) {
                $salesQuery->where('invoices.branch_id', $branchId);
            }
        }

        $sales = $salesQuery->select(
            'sale_lot_allocations.*',
            'stock_lots.consignment_receipt_item_id',
            'stock_lots.supplier_id',
            'invoice_items.book_id as book_id',
            'books.title as book_title'
        )->get();

        $giftsQuery = GiftLotAllocation::query()
            ->join('gifts', 'gifts.id', '=', 'gift_lot_allocations.gift_id')
            ->join('stock_lots', 'stock_lots.id', '=', 'gift_lot_allocations.stock_lot_id')
            ->leftJoin('books', 'books.id', '=', 'gifts.book_id')
            ->where('gift_lot_allocations.ownership_type', 'consignment')
            ->where('gift_lot_allocations.currency', $currency)
            ->whereDate('gifts.gifted_at', '>=', $periodStart)
            ->whereDate('gifts.gifted_at', '<=', $periodEnd)
            ->lockForUpdate();

        if ($supplierAccountId) {
            $giftsQuery->where('gift_lot_allocations.supplier_account_id', $supplierAccountId);
            if ($branchId) {
                $giftsQuery->where('gifts.branch_id', $branchId);
            }
        } else {
            $giftsQuery->where('gift_lot_allocations.supplier_id', $supplierId);
            if ($branchId) {
                $giftsQuery->where('gifts.branch_id', $branchId);
            }
        }

        $gifts = $giftsQuery->select(
            'gift_lot_allocations.*',
            'stock_lots.consignment_receipt_item_id',
            'gifts.book_id as book_id',
            'books.title as book_title'
        )->get();

        $lines = [];
        foreach ($sales as $alloc) {
            $open = $snapshots->saleOpen($alloc);
            if (Money::cmp($open, '0') <= 0) {
                continue;
            }
            $receiptItemId = $alloc->consignment_receipt_item_id;
            $receiptId = $receiptItemId
                ? DB::table('consignment_receipt_items')->where('id', $receiptItemId)->value('consignment_receipt_id')
                : null;
            if (!$receiptId) {
                throw new DomainException('تخصیص فروش امانی بدون رسید قابل تسویه نیست');
            }
            $openQty = max(0, (int) $alloc->quantity - (int) $alloc->quantity_returned);
            $bookId = $alloc->book_id ? (int) $alloc->book_id : null;
            $title = $alloc->book_title ?: ($bookId ? '#'.$bookId : '—');
            $settledAmt = Money::of($alloc->settled_publisher_amount ?? 0);
            $reversals = '0.00';
            foreach (\App\Models\CustomerReturnLotAllocation::query()
                ->where('sale_lot_allocation_id', $alloc->id)
                ->get() as $row) {
                $reversals = Money::add($reversals, $row->unsettled_payable_reversed ?? 0);
            }
            $lines[] = [
                'kind' => 'sale',
                'id' => $alloc->id,
                'book_id' => $bookId,
                'title' => $title,
                'open_qty' => $openQty,
                'open_amount' => $open,
                'settled_amount' => $settledAmt,
                'return_reversals' => $reversals,
                'unit_cost' => $alloc->unit_cost,
                'consignment_receipt_id' => (int) $receiptId,
                'consignment_receipt_item_id' => (int) $receiptItemId,
                'supplier_account_id' => $alloc->supplier_account_id,
            ];
        }
        foreach ($gifts as $alloc) {
            $open = $snapshots->giftOpen($alloc);
            if (Money::cmp($open, '0') <= 0) {
                continue;
            }
            $receiptItemId = $alloc->consignment_receipt_item_id;
            $receiptId = $receiptItemId
                ? DB::table('consignment_receipt_items')->where('id', $receiptItemId)->value('consignment_receipt_id')
                : null;
            if (!$receiptId) {
                throw new DomainException('تخصیص هدیه امانی بدون رسید قابل تسویه نیست');
            }
            $openQty = (int) $alloc->quantity;
            $bookId = $alloc->book_id ? (int) $alloc->book_id : null;
            $title = $alloc->book_title ?: ($bookId ? '#'.$bookId : '—');
            $lines[] = [
                'kind' => 'gift',
                'id' => $alloc->id,
                'book_id' => $bookId,
                'title' => $title,
                'open_qty' => $openQty,
                'open_amount' => $open,
                'settled_amount' => Money::of($alloc->settled_publisher_amount ?? 0),
                'return_reversals' => '0.00',
                'unit_cost' => $alloc->unit_cost,
                'consignment_receipt_id' => (int) $receiptId,
                'consignment_receipt_item_id' => $receiptItemId ? (int) $receiptItemId : null,
                'supplier_account_id' => $alloc->supplier_account_id,
            ];
        }

        return $lines;
    }

    private function touchReceipts(array $allocations): void
    {
        $ids = collect($allocations)->pluck('consignment_receipt_id')->unique()->filter();
        $snapshots = app(SnapshotPayable::class);
        foreach ($ids as $id) {
            $receipt = ConsignmentReceipt::with('items')->find($id);
            if ($receipt) {
                $snapshots->syncReceipt($receipt);
            }
        }
    }
}
