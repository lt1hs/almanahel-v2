<?php

namespace App\Services\Stock;

use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReceiptItem;
use App\Models\ConsignmentReturnLotAllocation;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\Inventory;
use App\Models\InvoiceItem;
use App\Models\SaleLotAllocation;
use App\Models\StockAdjustment;
use App\Models\StockLot;
use App\Models\StockLotMovement;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\TransferItemLotSplit;
use App\Services\Ledger\ConsignmentReturnSplit;
use App\Services\Settlement\PayableSnapshot;
use App\Services\Suppliers\SupplierAccountResolver;
use App\Support\Catalog\LotStatus;
use App\Support\IntakePolicy;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockLotService
{
    public function resolveOrigin(Branch $branch, bool $iraqOnlyBook = false): string
    {
        if ($iraqOnlyBook) {
            return 'iraq_local';
        }
        if ((bool) ($branch->is_iraq_store ?? false) || IntakePolicy::isIraqStore($branch)) {
            return 'qom_distributed';
        }
        if ((bool) ($branch->is_intake_hub ?? false)
            || (bool) ($branch->is_central_warehouse ?? false)
            || IntakePolicy::isIntakeHub($branch)) {
            return 'qom_distributed';
        }

        return 'other';
    }

    public function ensureAggregate(int $branchId, int $bookId, array $attrs = []): Inventory
    {
        $existing = Inventory::query()
            ->where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->whereNull('superseded_by_inventory_id')
            ->lockForUpdate()
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return Inventory::create(array_merge([
            'branch_id' => $branchId,
            'book_id' => $bookId,
            'quantity' => 0,
            'type' => 'owned',
        ], $attrs));
    }

    public function syncAggregateQuantity(int $branchId, int $bookId): Inventory
    {
        $inventory = $this->ensureAggregate($branchId, $bookId);
        $sum = (int) StockLot::where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->sellable()
            ->sum('qty_available');
        $inventory->quantity = $sum;

        $lots = StockLot::where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->where('qty_available', '>', 0)
            ->sellable()
            ->get();

        if ($lots->isNotEmpty()) {
            $types = $lots->pluck('ownership_type')->unique();
            $suppliers = $lots->pluck('supplier_id')->unique()->filter();
            $currencies = $lots->pluck('currency')->unique();
            if ($types->count() === 1) {
                $inventory->type = $types->first();
            }
            if ($suppliers->count() === 1) {
                $inventory->supplier_id = $suppliers->first();
            } elseif ($suppliers->count() > 1) {
                $inventory->supplier_id = null;
            }
            if ($currencies->count() > 1) {
                // Keep sell prices; do not collapse mixed lot cost onto the aggregate.
            }
        }

        $inventory->save();

        return $inventory->fresh();
    }

    public function setSellPrices(Inventory $inventory, array $prices, string $reason = 'price_update', bool $intake = false): Inventory
    {
        return app(\App\Services\Pricing\SellingPriceService::class)->applyInventoryPrices(
            $inventory,
            $prices,
            $reason,
            Auth::user(),
            $intake
        );
    }

    public function createIntakeLot(array $data, ?Model $reference = null): StockLot
    {
        $qty = (int) $data['quantity'];
        if ($qty < 1) {
            throw new DomainException('مقدار ورود کالا باید مثبت باشد');
        }

        $status = $data['status'] ?? LotStatus::AVAILABLE;
        if (!LotStatus::isValid($status)) {
            throw new DomainException('وضعیت لات نامعتبر است');
        }

        $branchId = (int) $data['branch_id'];
        $bookId = (int) $data['book_id'];

        $lot = StockLot::create(array_merge([
            'book_id' => $bookId,
            'branch_id' => $branchId,
            'source_branch_id' => $data['source_branch_id'] ?? null,
            'consignment_receipt_item_id' => $data['consignment_receipt_item_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_account_id' => $this->stampAccountId(
                $branchId,
                isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                $data['supplier_account_id'] ?? null
            ),
            'ownership_type' => $data['ownership_type'],
            'currency' => $data['currency'],
            'unit_cost' => Money::of($data['unit_cost']),
            'qty_original' => $qty,
            'qty_available' => $qty,
            'qty_reserved' => 0,
            'origin' => $data['origin'],
            'parent_lot_id' => $data['parent_lot_id'] ?? null,
            'legacy_uncertain' => $data['legacy_uncertain'] ?? false,
            'legacy_inventory_id' => $data['legacy_inventory_id'] ?? null,
            'migration_source' => $data['migration_source'] ?? 'intake',
            'status' => $status,
            'payable_unit_cost' => $this->intakePayableUnitCost($data),
            'current_cost_revision_id' => $this->intakeCurrentCostRevisionId($data),
        ], $this->intakePayableFields($data)));

        app(\App\Services\Catalog\BranchCatalogService::class)->ensureForIntake(
            $branchId,
            $bookId,
            $data,
            $lot->supplier_account_id ? (int) $lot->supplier_account_id : null
        );

        $this->move($lot, 'intake', $qty, $reference);
        $this->syncAggregateQuantity($branchId, $bookId);

        return $lot;
    }

    public function adjust(int $branchId, int $bookId, int $delta, string $reason, ?Model $reference = null, array $meta = []): StockAdjustment
    {
        if ($delta === 0) {
            throw new DomainException('مقدار تعدیل نمی‌تواند صفر باشد');
        }

        $adjustment = StockAdjustment::create([
            'branch_id' => $branchId,
            'book_id' => $bookId,
            'user_id' => Auth::id(),
            'quantity_delta' => $delta,
            'reason' => $reason,
            'meta' => $meta ?: null,
        ]);

        if ($delta > 0) {
            $inventory = $this->ensureAggregate($branchId, $bookId);
            $branch = Branch::find($branchId);
            $currency = (!Money::isZero($inventory->cost_price_dinar ?? 0) && Money::isZero($inventory->cost_price_toman ?? 0))
                ? 'dinar'
                : 'toman';
            $this->createIntakeLot([
                'book_id' => $bookId,
                'branch_id' => $branchId,
                'ownership_type' => $inventory->type === 'consignment' ? 'consignment' : 'owned',
                'supplier_id' => $inventory->supplier_id,
                'currency' => $currency,
                'unit_cost' => $currency === 'dinar' ? ($inventory->cost_price_dinar ?? 0) : ($inventory->cost_price_toman ?? 0),
                'quantity' => $delta,
                'origin' => $branch ? $this->resolveOrigin($branch) : 'other',
                'migration_source' => 'adjustment',
            ], $adjustment);
        } else {
            $this->consumeLots($branchId, $bookId, abs($delta), 'adjustment', $reference ?? $adjustment, null);
            $this->syncAggregateQuantity($branchId, $bookId);
        }

        return $adjustment;
    }

    /** @return SaleLotAllocation[] */
    public function allocateSale(InvoiceItem $invoiceItem, int $branchId, int $quantity, string $currency): array
    {
        $this->lockBookStock($branchId, (int) $invoiceItem->book_id);
        $available = $this->availableQty($branchId, (int) $invoiceItem->book_id, $currency);
        if ($available < $quantity) {
            throw new DomainException('موجودی کافی برای تخصیص لات وجود ندارد', 422, [
                'book_id' => $invoiceItem->book_id,
            ]);
        }

        $remaining = $quantity;
        $allocations = [];
        $receiptIdsToSync = [];
        foreach ($this->fifoLots($branchId, (int) $invoiceItem->book_id, $currency) as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((int) $lot->qty_available, $remaining);
            if ($take <= 0) {
                continue;
            }
            $this->decrementAvailable($lot, $take);
            $this->move($lot, 'sale', -$take, $invoiceItem);
            $unitCost = (string) $lot->ownership_type === 'consignment'
                ? $lot->effectivePayableUnitCost()
                : Money::of($lot->unit_cost);
            $alloc = SaleLotAllocation::create(array_merge([
                'invoice_item_id' => $invoiceItem->id,
                'stock_lot_id' => $lot->id,
                'quantity' => $take,
                'unit_cost' => $unitCost,
                'currency' => $lot->currency,
                'quantity_returned' => 0,
                'supplier_account_id' => $lot->supplier_account_id,
                'consignment_cost_revision_id' => $lot->ownership_type === 'consignment'
                    ? $lot->current_cost_revision_id
                    : null,
            ], $this->allocationPayableFields($lot, $take, $unitCost)));
            $allocations[] = $alloc;
            if ($lot->ownership_type === 'consignment' && $lot->consignment_receipt_item_id) {
                ConsignmentReceiptItem::where('id', $lot->consignment_receipt_item_id)
                    ->increment('quantity_sold', $take);
                $receiptId = ConsignmentReceiptItem::query()
                    ->whereKey($lot->consignment_receipt_item_id)
                    ->value('consignment_receipt_id');
                if ($receiptId) {
                    $receiptIdsToSync[(int) $receiptId] = true;
                }
            }
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new DomainException("موجودی کافی برای تخصیص لات وجود ندارد (book {$invoiceItem->book_id})");
        }

        $this->syncAggregateQuantity($branchId, (int) $invoiceItem->book_id);

        if ($receiptIdsToSync) {
            $snapshots = app(\App\Services\Settlement\SnapshotPayable::class);
            foreach (array_keys($receiptIdsToSync) as $receiptId) {
                $receipt = ConsignmentReceipt::query()->with('items')->find($receiptId);
                if ($receipt) {
                    $snapshots->syncReceipt($receipt);
                }
            }
        }

        return $allocations;
    }

    public function reverseSaleAllocations(
        InvoiceItem $invoiceItem,
        int $quantity,
        ?\App\Models\CustomerReturn $return = null,
        ?\App\Models\CustomerReturnItem $returnItem = null
    ): void {
        $remaining = $quantity;
        $allocs = SaleLotAllocation::where('invoice_item_id', $invoiceItem->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        $alreadyReturned = (int) $allocs->sum('quantity_returned');
        $sold = (int) $allocs->sum('quantity');
        if ($alreadyReturned + $quantity > $sold) {
            throw new DomainException('مقدار مرجوعی از تخصیص فروش بیشتر است');
        }

        foreach ($allocs as $alloc) {
            if ($remaining <= 0) {
                break;
            }
            $restorable = (int) $alloc->quantity - (int) $alloc->quantity_returned;
            if ($restorable <= 0) {
                continue;
            }
            $take = min($restorable, $remaining);
            $lot = StockLot::where('id', $alloc->stock_lot_id)->lockForUpdate()->first();
            if (!$lot) {
                throw new DomainException('بازگردانی موجودی از تخصیص فروش ناقص ماند');
            }
            $lot->increment('qty_available', $take);
            $alloc->increment('quantity_returned', $take);
            $this->move($lot, 'return', $take, $invoiceItem);
            if ($lot->ownership_type === 'consignment' && $lot->consignment_receipt_item_id) {
                $item = ConsignmentReceiptItem::where('id', $lot->consignment_receipt_item_id)->lockForUpdate()->first();
                if ($item) {
                    $item->decrement('quantity_sold', min($take, (int) $item->quantity_sold));
                }
            }
            if ($return && $returnItem) {
                $row = \App\Models\CustomerReturnLotAllocation::create([
                    'customer_return_id' => $return->id,
                    'customer_return_item_id' => $returnItem->id,
                    'sale_lot_allocation_id' => $alloc->id,
                    'stock_lot_id' => $lot->id,
                    'quantity' => $take,
                    'unit_cost' => $alloc->unit_cost,
                    'currency' => $alloc->currency,
                    'ownership_type' => $lot->ownership_type,
                    'supplier_id' => $lot->supplier_id,
                    'supplier_account_id' => $lot->supplier_account_id ?? $alloc->supplier_account_id,
                    'payable_basis' => $alloc->payable_basis,
                    'payable_rate' => $alloc->payable_rate,
                    'origin_scope' => $lot->origin,
                ]);
                if ($lot->ownership_type === 'consignment' && $alloc->publisher_payable !== null) {
                    app(ConsignmentReturnSplit::class)->persist($row);
                }
            }
            $remaining -= $take;
            $this->syncAggregateQuantity((int) $lot->branch_id, (int) $lot->book_id);
        }

        if ($remaining > 0) {
            throw new DomainException('بازگردانی موجودی از تخصیص فروش ناقص ماند');
        }
    }

    public function reserveForTransfer(Transfer $transfer, array $items): void
    {
        $aggregated = [];
        foreach ($items as $item) {
            $bookId = (int) $item['book_id'];
            $aggregated[$bookId] = ($aggregated[$bookId] ?? 0) + (int) $item['quantity'];
        }

        foreach ($aggregated as $bookId => $quantity) {
            $this->lockBookStock((int) $transfer->from_branch_id, $bookId);
            if ($this->availableQty((int) $transfer->from_branch_id, $bookId) < $quantity) {
                throw new DomainException('موجودی کافی برای رزرو انتقال وجود ندارد', 422, ['book_id' => $bookId]);
            }

            $transferItem = TransferItem::create([
                'transfer_id' => $transfer->id,
                'book_id' => $bookId,
                'quantity' => $quantity,
            ]);

            $remaining = $quantity;
            foreach ($this->fifoLots((int) $transfer->from_branch_id, $bookId) as $lot) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min((int) $lot->qty_available, $remaining);
                $this->decrementAvailable($lot, $take);
                $lot->increment('qty_reserved', $take);
                $this->move($lot, 'transfer_out', -$take, $transfer);
                TransferItemLotSplit::create([
                    'transfer_item_id' => $transferItem->id,
                    'source_lot_id' => $lot->id,
                    'quantity' => $take,
                ]);
                $remaining -= $take;
            }
            if ($remaining > 0) {
                throw new DomainException('موجودی کافی برای رزرو انتقال وجود ندارد');
            }
            $this->syncAggregateQuantity((int) $transfer->from_branch_id, $bookId);
        }
    }

    public function receiveTransfer(Transfer $transfer): void
    {
        $items = TransferItem::with('lotSplits.sourceLot')->where('transfer_id', $transfer->id)->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw new DomainException('انتقال بدون رزرو لات قابل دریافت نیست');
        }

        foreach ($items as $item) {
            foreach ($item->lotSplits as $split) {
                if ($split->dest_lot_id) {
                    continue;
                }
                $source = StockLot::where('id', $split->source_lot_id)->lockForUpdate()->first();
                if (!$source) {
                    throw new DomainException('لات مبدأ انتقال یافت نشد');
                }
                $qty = (int) $split->quantity;
                $source->decrement('qty_reserved', min($qty, (int) $source->qty_reserved));
                $dest = $this->createIntakeLot([
                    'book_id' => $source->book_id,
                    'branch_id' => $transfer->to_branch_id,
                    'source_branch_id' => $transfer->from_branch_id,
                    'consignment_receipt_item_id' => $source->consignment_receipt_item_id,
                    'supplier_id' => $source->supplier_id,
                    'supplier_account_id' => $this->stampAccountId(
                        (int) $transfer->to_branch_id,
                        $source->supplier_id ? (int) $source->supplier_id : null,
                        null
                    ),
                    'ownership_type' => $source->ownership_type,
                    'currency' => $source->currency,
                    'unit_cost' => $source->unit_cost,
                    'quantity' => $qty,
                    'origin' => $source->origin,
                    'parent_lot_id' => $source->id,
                    'migration_source' => 'transfer',
                ], $transfer);
                StockLotMovement::where('stock_lot_id', $dest->id)->latest('id')->first()
                    ?->update(['type' => 'transfer_in']);
                $split->update(['dest_lot_id' => $dest->id]);
            }
            $this->syncAggregateQuantity((int) $transfer->to_branch_id, (int) $item->book_id);
        }
    }

    public function cancelTransferReservation(Transfer $transfer): void
    {
        $items = TransferItem::with('lotSplits')->where('transfer_id', $transfer->id)->lockForUpdate()->get();
        foreach ($items as $item) {
            foreach ($item->lotSplits as $split) {
                if ($split->dest_lot_id) {
                    throw new DomainException('انتقال دریافت‌شده قابل لغو از مبدأ نیست');
                }
                $lot = StockLot::where('id', $split->source_lot_id)->lockForUpdate()->first();
                if (!$lot) {
                    throw new DomainException('لات رزرو شده یافت نشد');
                }
                $qty = (int) $split->quantity;
                $lot->decrement('qty_reserved', min($qty, (int) $lot->qty_reserved));
                $lot->increment('qty_available', $qty);
                $this->move($lot, 'adjustment', $qty, $transfer, ['reason' => 'transfer_cancelled']);
            }
            $this->syncAggregateQuantity((int) $transfer->from_branch_id, (int) $item->book_id);
        }
    }

    /** @return list<array{lot: StockLot, quantity: int, cost: string, currency: string}> */
    public function allocateConsignmentReturn(int $branchId, int $supplierId, int $bookId, int $quantity, ?Model $reference = null): array
    {
        $this->lockBookStock($branchId, $bookId);
        $splits = [];
        $remaining = $quantity;
        $lots = StockLot::where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->where('supplier_id', $supplierId)
            ->where('ownership_type', 'consignment')
            ->where('qty_available', '>', 0)
            ->sellable()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $currency = null;
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            if ($currency && $lot->currency !== $currency) {
                throw new DomainException('مرجوعی امانی نمی‌تواند چند ارز را در یک قلم ترکیب کند');
            }
            $currency = $lot->currency;
            $take = min((int) $lot->qty_available, $remaining);
            $this->decrementAvailable($lot, $take);
            $this->move($lot, 'consignment_return', -$take, $reference);
            if ($lot->consignment_receipt_item_id) {
                ConsignmentReceiptItem::where('id', $lot->consignment_receipt_item_id)
                    ->increment('quantity_returned', $take);
            }
            $splits[] = [
                'lot' => $lot,
                'quantity' => $take,
                'cost' => Money::mul($lot->unit_cost, $take),
                'currency' => $lot->currency,
            ];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new DomainException('موجودی امانی قابل مرجوعی برای این ناشر کافی نیست');
        }

        $this->syncAggregateQuantity($branchId, $bookId);

        return $splits;
    }

    /**
     * Return exact consignment lots chosen by the server-resolved eligible list.
     * Client may not invent ownership, cost, currency, or payable effects.
     *
     * @param  list<array{stock_lot_id: int, quantity: int}>  $rows
     * @return list<array{lot: StockLot, quantity: int, cost: string, currency: string}>
     */
    public function allocateConsignmentReturnLots(int $branchId, int $supplierAccountId, array $rows, ?Model $reference = null): array
    {
        $splits = [];
        $touchedBooks = [];

        foreach ($rows as $row) {
            $lotId = (int) $row['stock_lot_id'];
            $qty = (int) $row['quantity'];
            if ($qty <= 0) {
                throw new DomainException('تعداد مرجوعی نامعتبر است', 422);
            }

            $lot = StockLot::query()->whereKey($lotId)->lockForUpdate()->first();
            if (!$lot
                || (int) $lot->branch_id !== $branchId
                || (int) $lot->supplier_account_id !== $supplierAccountId
                || $lot->ownership_type !== 'consignment'
            ) {
                throw new DomainException('لات امانی قابل مرجوعی یافت نشد', 422, ['stock_lot_id' => $lotId]);
            }
            if ((int) $lot->qty_available < $qty) {
                throw new DomainException('تعداد مرجوعی بیش از موجودی لات است', 422, [
                    'stock_lot_id' => $lotId,
                    'available' => (int) $lot->qty_available,
                ]);
            }

            $this->lockBookStock($branchId, (int) $lot->book_id);
            $this->decrementAvailable($lot, $qty);
            $this->move($lot, 'consignment_return', -$qty, $reference);
            if ($lot->consignment_receipt_item_id) {
                ConsignmentReceiptItem::where('id', $lot->consignment_receipt_item_id)
                    ->increment('quantity_returned', $qty);
            }

            $splits[] = [
                'lot' => $lot->fresh(),
                'quantity' => $qty,
                'cost' => Money::mul($lot->unit_cost, $qty),
                'currency' => $lot->currency,
                'book_id' => (int) $lot->book_id,
            ];
            $touchedBooks[(int) $lot->book_id] = true;
        }

        foreach (array_keys($touchedBooks) as $bookId) {
            $this->syncAggregateQuantity($branchId, $bookId);
        }

        return $splits;
    }

    /** @return GiftLotAllocation[] */
    public function allocateGift(Gift $gift): array
    {
        $branchId = (int) $gift->branch_id;
        $bookId = (int) $gift->book_id;
        $quantity = (int) $gift->quantity;
        $this->lockBookStock($branchId, $bookId);

        if ($this->availableQty($branchId, $bookId, $gift->currency) < $quantity) {
            throw new DomainException('موجودی کافی برای هدیه وجود ندارد');
        }

        $remaining = $quantity;
        $created = [];
        foreach ($this->fifoLots($branchId, $bookId, $gift->currency) as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((int) $lot->qty_available, $remaining);
            $this->decrementAvailable($lot, $take);
            $this->move($lot, 'gift', -$take, $gift);
            if ($lot->ownership_type === 'consignment' && $lot->consignment_receipt_item_id) {
                ConsignmentReceiptItem::where('id', $lot->consignment_receipt_item_id)
                    ->increment('quantity_sold', $take);
            }
            $unitCost = (string) $lot->ownership_type === 'consignment'
                ? $lot->effectivePayableUnitCost()
                : Money::of($lot->unit_cost);
            $created[] = GiftLotAllocation::create(array_merge([
                'gift_id' => $gift->id,
                'stock_lot_id' => $lot->id,
                'quantity' => $take,
                'unit_cost' => $unitCost,
                'currency' => $lot->currency,
                'ownership_type' => $lot->ownership_type,
                'supplier_id' => $lot->supplier_id,
                'supplier_account_id' => $lot->supplier_account_id,
                'consignment_cost_revision_id' => $lot->ownership_type === 'consignment'
                    ? $lot->current_cost_revision_id
                    : null,
            ], $this->allocationPayableFields($lot, $take, $unitCost)));
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new DomainException('موجودی کافی برای هدیه وجود ندارد');
        }

        $this->syncAggregateQuantity($branchId, $bookId);

        return $created;
    }

    public function persistReturnSplits(int $returnItemId, array $splits): void
    {
        foreach ($splits as $split) {
            ConsignmentReturnLotAllocation::create([
                'consignment_return_item_id' => $returnItemId,
                'stock_lot_id' => $split['lot']->id,
                'quantity' => $split['quantity'],
                'unit_cost' => $split['lot']->unit_cost,
                'currency' => $split['currency'],
                'supplier_account_id' => $split['lot']->supplier_account_id,
            ]);
        }
    }

    private function consumeLots(int $branchId, int $bookId, int $quantity, string $type, ?Model $reference, ?string $currency): void
    {
        $remaining = $quantity;
        foreach ($this->fifoLots($branchId, $bookId, $currency) as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((int) $lot->qty_available, $remaining);
            $this->decrementAvailable($lot, $take);
            $this->move($lot, $type, -$take, $reference);
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw new DomainException('موجودی کافی برای این عملیات وجود ندارد', 422, ['book_id' => $bookId]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function intakePayableFields(array $data): array
    {
        if (($data['ownership_type'] ?? '') !== 'consignment') {
            return [
                'payable_basis' => null,
                'payable_rate' => null,
                'payable_rule_source' => null,
                'payable_rule_stamped_at' => null,
            ];
        }

        if (!empty($data['parent_lot_id'])) {
            $parent = StockLot::query()->whereKey($data['parent_lot_id'])->first();
            if (!$parent) {
                throw new DomainException('لات مبدأ انتقال یافت نشد');
            }
            if ($parent->payable_basis === null || $parent->payable_rate === null) {
                throw new DomainException('لات امانی مبدأ مُهرشده نیست');
            }

            return [
                'payable_basis' => $parent->payable_basis,
                'payable_rate' => $parent->payable_rate,
                'payable_rule_source' => $parent->payable_rule_source,
                'payable_rule_stamped_at' => $parent->payable_rule_stamped_at,
            ];
        }

        $snap = (new PayableSnapshot())->forNewIntake(new StockLot([
            'unit_cost' => $data['unit_cost'],
            'ownership_type' => 'consignment',
        ]), 1);

        return [
            'payable_basis' => $snap['payable_basis'],
            'payable_rate' => $snap['payable_rate'],
            'payable_rule_source' => 'intake',
            'payable_rule_stamped_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allocationPayableFields(StockLot $lot, int $quantity, mixed $unitCost = null): array
    {
        if ((string) $lot->ownership_type !== 'consignment') {
            return [];
        }
        if ($lot->payable_basis === null || $lot->payable_rate === null) {
            throw new DomainException('لات امانی مُهرشده نیست');
        }
        $cost = $unitCost ?? $lot->effectivePayableUnitCost();
        $snap = (new PayableSnapshot())->fromStampedLot($lot, $quantity, $cost);

        return [
            'payable_basis' => $snap['payable_basis'],
            'payable_rate' => $snap['payable_rate'],
            'gross_cost' => $snap['gross_cost'],
            'publisher_payable' => $snap['publisher_payable'],
            'rule_source' => $snap['rule_source'],
            'rule_stamped_at' => $lot->payable_rule_stamped_at ?? now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intakePayableUnitCost(array $data): ?string
    {
        if (($data['ownership_type'] ?? '') !== 'consignment') {
            return null;
        }
        if (array_key_exists('payable_unit_cost', $data) && $data['payable_unit_cost'] !== null) {
            return Money::of($data['payable_unit_cost']);
        }
        if (!empty($data['parent_lot_id'])) {
            $parent = StockLot::query()->whereKey($data['parent_lot_id'])->first();
            if ($parent) {
                return $parent->effectivePayableUnitCost();
            }
        }

        return Money::of($data['unit_cost']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intakeCurrentCostRevisionId(array $data): ?int
    {
        if (($data['ownership_type'] ?? '') !== 'consignment') {
            return null;
        }
        if (!empty($data['current_cost_revision_id'])) {
            return (int) $data['current_cost_revision_id'];
        }
        if (!empty($data['parent_lot_id'])) {
            $parent = StockLot::query()->whereKey($data['parent_lot_id'])->first();

            return $parent?->current_cost_revision_id ? (int) $parent->current_cost_revision_id : null;
        }

        return null;
    }

    private function fifoLots(int $branchId, int $bookId, ?string $currency = null)
    {
        $q = StockLot::where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->where('qty_available', '>', 0)
            ->sellable()
            ->orderBy('id')
            ->lockForUpdate();
        if ($currency) {
            $q->where('currency', $currency);
        }

        return $q->get();
    }

    private function availableQty(int $branchId, int $bookId, ?string $currency = null): int
    {
        $q = StockLot::where('branch_id', $branchId)->where('book_id', $bookId)->sellable();
        if ($currency) {
            $q->where('currency', $currency);
        }

        return (int) $q->sum('qty_available');
    }

    private function lockBookStock(int $branchId, int $bookId): void
    {
        Inventory::where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->whereNull('superseded_by_inventory_id')
            ->lockForUpdate()
            ->get();
        StockLot::where('branch_id', $branchId)
            ->where('book_id', $bookId)
            ->lockForUpdate()
            ->get();
    }

    private function decrementAvailable(StockLot $lot, int $take): void
    {
        if ((int) $lot->qty_available < $take) {
            throw new DomainException('موجودی لات کافی نیست');
        }
        $lot->decrement('qty_available', $take);
        $lot->refresh();
        if ((int) $lot->qty_available < 0) {
            throw new DomainException('موجودی نمی‌تواند منفی شود');
        }
    }

    private function stampAccountId(int $branchId, ?int $supplierId, mixed $explicit = null): ?int
    {
        if ($explicit) {
            $accountId = (int) $explicit;
            app(\App\Services\Catalog\BranchCatalogService::class)
                ->assertSupplierAccountInBranch($accountId, $branchId);

            return $accountId;
        }
        if (!$supplierId) {
            return null;
        }

        return app(SupplierAccountResolver::class)->ensureForPair($branchId, $supplierId)->id;
    }

    private function move(StockLot $lot, string $type, int $quantity, ?Model $reference = null, array $meta = []): void
    {
        StockLotMovement::create([
            'stock_lot_id' => $lot->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'meta' => $meta ?: null,
        ]);
    }
}
