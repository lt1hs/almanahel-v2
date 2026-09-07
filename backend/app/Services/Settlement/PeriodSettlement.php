<?php

namespace App\Services\Settlement;

use App\Exceptions\DomainException;
use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReceiptItem;
use App\Models\GiftLotAllocation;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Models\SupplierAccount;
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
        ?string $periodStart,
        ?string $periodEnd,
        ?int $branchId = null,
        ?int $supplierAccountId = null
    ): array {
        $lines = $this->attachRemainingQty(
            $this->openLines($supplierId, $currency, $periodStart, $periodEnd, $branchId, $supplierAccountId),
            $supplierId,
            $currency,
            $branchId
        );
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
        [$resolvedStart, $resolvedEnd] = $this->resolvePeriodBounds($lines, $periodStart, $periodEnd);

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
            'period_start' => $resolvedStart,
            'period_end' => $resolvedEnd,
            'currency' => $currency,
            'supplier_account_id' => $supplierAccountId,
            'branch_id' => $branchId,
        ];
    }

    /**
     * Sum branch-local previews so the all-branches total matches what settle can actually post.
     *
     * @return array{total_payable: string, lines: list<array<string, mixed>>, breakdown: array<string, string>, by_branch: list<array<string, mixed>>, commission_rate: string, period_start: string, period_end: string, currency: string}
     */
    public function previewAllBranches(
        int $supplierId,
        string $currency,
        ?string $periodStart,
        ?string $periodEnd
    ): array {
        $accounts = SupplierAccount::query()
            ->with('branch:id,name')
            ->where('supplier_id', $supplierId)
            ->orderBy('branch_id')
            ->get();

        $lines = [];
        $salesPayable = '0.00';
        $giftPayable = '0.00';
        $previouslySettled = '0.00';
        $returnReversals = '0.00';
        $total = '0.00';
        $byBranch = [];

        foreach ($accounts as $account) {
            $preview = $this->preview(
                $supplierId,
                $currency,
                $periodStart,
                $periodEnd,
                (int) $account->branch_id,
                (int) $account->id
            );
            if (Money::isZero($preview['total_payable'])) {
                continue;
            }

            $stockByBook = [];
            foreach ($preview['lines'] as $line) {
                $line['branch_id'] = (int) $account->branch_id;
                $line['branch_name'] = $account->branch?->name;
                $bookId = isset($line['book_id']) ? (int) $line['book_id'] : 0;
                if ($bookId > 0) {
                    $stockByBook[$bookId] = (int) ($line['remaining_qty'] ?? 0);
                }
                $lines[] = $line;
            }
            $salesPayable = Money::add($salesPayable, $preview['breakdown']['sales_payable'] ?? 0);
            $giftPayable = Money::add($giftPayable, $preview['breakdown']['gift_payable'] ?? 0);
            $previouslySettled = Money::add($previouslySettled, $preview['breakdown']['previously_settled'] ?? 0);
            $returnReversals = Money::add($returnReversals, $preview['breakdown']['return_reversals'] ?? 0);
            $total = Money::add($total, $preview['total_payable']);
            $byBranch[] = [
                'supplier_account_id' => (int) $account->id,
                'branch_id' => (int) $account->branch_id,
                'branch_name' => $account->branch?->name,
                'remaining_payable' => $preview['total_payable'],
                'remaining_qty' => array_sum($stockByBook),
            ];
        }

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
            'by_branch' => $byBranch,
            'commission_rate' => '1.0000',
            'period_start' => $this->resolvePeriodBounds($lines, $periodStart, $periodEnd)[0],
            'period_end' => $this->resolvePeriodBounds($lines, $periodStart, $periodEnd)[1],
            'currency' => $currency,
            'supplier_account_id' => null,
            'branch_id' => null,
        ];
    }

    public function settle(
        Settlement $settlement,
        ?string $periodStart,
        ?string $periodEnd,
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

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function attachRemainingQty(
        array $lines,
        int $supplierId,
        string $currency,
        ?int $branchId
    ): array {
        $bookIds = [];
        foreach ($lines as $line) {
            $bookId = isset($line['book_id']) ? (int) $line['book_id'] : 0;
            if ($bookId > 0) {
                $bookIds[$bookId] = $bookId;
            }
        }
        if ($bookIds === []) {
            return $lines;
        }

        $lotRemaining = $this->lotRemainingByBook($supplierId, $currency, $branchId, array_values($bookIds));
        $receiptRemaining = $this->receiptRemainingByBook($supplierId, $currency, $branchId, array_values($bookIds));

        foreach ($lines as &$line) {
            $bookId = isset($line['book_id']) ? (int) $line['book_id'] : 0;
            $fromLots = $bookId > 0 ? (int) ($lotRemaining[$bookId] ?? 0) : 0;
            $fromReceipts = $bookId > 0 ? (int) ($receiptRemaining[$bookId] ?? 0) : 0;
            $line['remaining_qty'] = $fromLots > 0 ? $fromLots : $fromReceipts;
        }
        unset($line);

        return $lines;
    }

    /**
     * Physical unsold at this scope: on-hand plus reserved (transfers still count).
     *
     * @param  list<int>  $bookIds
     * @return array<int, int>
     */
    private function lotRemainingByBook(int $supplierId, string $currency, ?int $branchId, array $bookIds): array
    {
        $query = StockLot::query()
            ->where('ownership_type', 'consignment')
            ->where('currency', $currency)
            ->whereIn('book_id', $bookIds)
            ->where(function ($builder) use ($supplierId) {
                $builder->where('supplier_id', $supplierId)
                    ->orWhereIn('consignment_receipt_item_id', function ($sub) use ($supplierId) {
                        $sub->select('consignment_receipt_items.id')
                            ->from('consignment_receipt_items')
                            ->join('consignment_receipts', 'consignment_receipts.id', '=', 'consignment_receipt_items.consignment_receipt_id')
                            ->where('consignment_receipts.supplier_id', $supplierId);
                    });
            });
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->selectRaw('book_id, COALESCE(SUM(qty_available), 0) as remaining_qty')
            ->groupBy('book_id')
            ->pluck('remaining_qty', 'book_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * Same unsold math as the consignment receipt page: received − sold − returned.
     *
     * @param  list<int>  $bookIds
     * @return array<int, int>
     */
    private function receiptRemainingByBook(int $supplierId, string $currency, ?int $branchId, array $bookIds): array
    {
        $query = ConsignmentReceiptItem::query()
            ->join('consignment_receipts', 'consignment_receipts.id', '=', 'consignment_receipt_items.consignment_receipt_id')
            ->where('consignment_receipts.supplier_id', $supplierId)
            ->where('consignment_receipts.currency', $currency)
            ->whereIn('consignment_receipt_items.book_id', $bookIds);
        if ($branchId) {
            $query->where('consignment_receipts.branch_id', $branchId);
        }

        $rows = $query->get([
            'consignment_receipt_items.book_id',
            'consignment_receipt_items.quantity_received',
            'consignment_receipt_items.quantity_sold',
            'consignment_receipt_items.quantity_returned',
        ]);

        $out = [];
        foreach ($rows as $row) {
            $bookId = (int) $row->book_id;
            $unsold = max(
                0,
                (int) $row->quantity_received - (int) $row->quantity_sold - (int) $row->quantity_returned
            );
            $out[$bookId] = ($out[$bookId] ?? 0) + $unsold;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function openLines(
        int $supplierId,
        string $currency,
        ?string $periodStart,
        ?string $periodEnd,
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
            ->lockForUpdate();
        if ($periodStart && $periodEnd) {
            $salesQuery->whereDate('invoices.sold_at', '>=', $periodStart)
                ->whereDate('invoices.sold_at', '<=', $periodEnd);
        }

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
            'books.title as book_title',
            'invoices.sold_at as occurred_on'
        )->get();

        $giftsQuery = GiftLotAllocation::query()
            ->join('gifts', 'gifts.id', '=', 'gift_lot_allocations.gift_id')
            ->join('stock_lots', 'stock_lots.id', '=', 'gift_lot_allocations.stock_lot_id')
            ->leftJoin('books', 'books.id', '=', 'gifts.book_id')
            ->where('gift_lot_allocations.ownership_type', 'consignment')
            ->where('gift_lot_allocations.currency', $currency)
            ->lockForUpdate();
        if ($periodStart && $periodEnd) {
            $giftsQuery->whereDate('gifts.gifted_at', '>=', $periodStart)
                ->whereDate('gifts.gifted_at', '<=', $periodEnd);
        }

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
            'books.title as book_title',
            'gifts.gifted_at as occurred_on'
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
                'occurred_on' => $this->occurredOnDate($alloc->occurred_on ?? null),
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
                'occurred_on' => $this->occurredOnDate($alloc->occurred_on ?? null),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: string, 1: string}
     */
    private function resolvePeriodBounds(array $lines, ?string $periodStart, ?string $periodEnd): array
    {
        if ($periodStart && $periodEnd) {
            return [$periodStart, $periodEnd];
        }

        $occurred = [];
        foreach ($lines as $line) {
            if (!empty($line['occurred_on'])) {
                $occurred[] = (string) $line['occurred_on'];
            }
        }

        return [
            $occurred !== [] ? min($occurred) : now()->toDateString(),
            now()->toDateString(),
        ];
    }

    private function occurredOnDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value)->toDateString();
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
