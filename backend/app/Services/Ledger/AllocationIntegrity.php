<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnLotAllocation;
use App\Models\Gift;
use App\Models\GiftLotAllocation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SaleLotAllocation;
use App\Models\Settlement;
use App\Models\SettlementAllocation;
use App\Models\StockLot;
use App\Support\Money;

final class AllocationIntegrity
{
    public function assertSale(Invoice $invoice): void
    {
        $this->throwFirst($this->saleIssues($invoice, true));
    }

    public function assertGift(Gift $gift): void
    {
        $this->throwFirst($this->giftIssues($gift, true));
    }

    public function assertCustomerReturn(CustomerReturn $return): void
    {
        $this->throwFirst($this->returnIssues($return, true));
    }

    public function assertSettlement(Settlement $settlement): void
    {
        $this->throwFirst($this->settlementIssues($settlement, true));
    }

    /**
     * @return list<string>
     */
    public function saleIssues(Invoice $invoice, bool $lock = false): array
    {
        $issues = [];
        $items = InvoiceItem::query()->where('invoice_id', $invoice->id);
        if ($lock) {
            $items->lockForUpdate();
        }
        $items = $items->get();
        if ($items->isEmpty()) {
            return ['sale invoice has no items'];
        }
        foreach ($items as $item) {
            $q = SaleLotAllocation::query()->where('invoice_item_id', $item->id);
            if ($lock) {
                $q->lockForUpdate();
            }
            $allocs = $q->get();
            $sum = 0;
            foreach ($allocs as $alloc) {
                $sum += (int) $alloc->quantity;
                $issues = array_merge($issues, $this->saleAllocIssues($invoice, $item, $alloc, $lock));
            }
            if ($sum !== (int) $item->quantity) {
                $issues[] = "sale item {$item->id} quantity {$item->quantity} != allocations {$sum}";
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function giftIssues(Gift $gift, bool $lock = false): array
    {
        $issues = [];
        $q = GiftLotAllocation::query()->where('gift_id', $gift->id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $allocs = $q->get();
        $sum = 0;
        foreach ($allocs as $alloc) {
            $sum += (int) $alloc->quantity;
            $issues = array_merge($issues, $this->giftAllocIssues($gift, $alloc, $lock));
        }
        if ($sum !== (int) $gift->quantity) {
            $issues[] = "gift {$gift->id} quantity {$gift->quantity} != allocations {$sum}";
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function returnIssues(CustomerReturn $return, bool $lock = false): array
    {
        $issues = [];
        $invoice = Invoice::query()->whereKey($return->invoice_id);
        if ($lock) {
            $invoice->lockForUpdate();
        }
        $invoice = $invoice->first();
        if (!$invoice) {
            return ["customer return {$return->id} missing invoice"];
        }
        if ((int) $return->branch_id !== (int) $invoice->branch_id) {
            $issues[] = "customer return {$return->id} branch mismatch";
        }
        $items = CustomerReturnItem::query()->where('customer_return_id', $return->id);
        if ($lock) {
            $items->lockForUpdate();
        }
        $items = $items->get();
        foreach ($items as $item) {
            $q = CustomerReturnLotAllocation::query()->where('customer_return_item_id', $item->id);
            if ($lock) {
                $q->lockForUpdate();
            }
            $allocs = $q->get();
            $sum = 0;
            foreach ($allocs as $alloc) {
                $sum += (int) $alloc->quantity;
                $issues = array_merge($issues, $this->returnAllocIssues($return, $invoice, $item, $alloc, $lock));
            }
            if ($sum !== (int) $item->quantity) {
                $issues[] = "return item {$item->id} quantity {$item->quantity} != allocations {$sum}";
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function settlementIssues(Settlement $settlement, bool $lock = false): array
    {
        $issues = [];
        $q = SettlementAllocation::query()->where('settlement_id', $settlement->id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $allocs = $q->get();
        $allocated = '0.00';
        foreach ($allocs as $alloc) {
            $allocated = Money::add($allocated, $alloc->amount);
            if ((string) $alloc->currency !== (string) $settlement->currency) {
                $issues[] = "settlement allocation {$alloc->id} currency mismatch";
            }
            $issues = array_merge($issues, $this->settlementSourceIssues($settlement, $alloc, $lock));
        }
        if ($allocs->isEmpty() || Money::cmp($allocated, $settlement->amount) !== 0) {
            $issues[] = "settlement {$settlement->id} amount disagrees with allocations";
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function payableSplitIssues(bool $lock = false): array
    {
        $issues = [];
        $q = CustomerReturnLotAllocation::query()->orderBy('id');
        $q->chunkById(100, function ($rows) use (&$issues) {
            foreach ($rows as $row) {
                $sum = Money::add($row->unsettled_payable_reversed, $row->settled_payable_reversed);
                if (Money::cmp($sum, $row->publisher_payable_reversed) !== 0) {
                    $issues[] = "return allocation {$row->id} payable split invariant failed";
                }
            }
        });

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function saleAllocIssues(Invoice $invoice, InvoiceItem $item, SaleLotAllocation $alloc, bool $lock): array
    {
        $issues = [];
        if ((int) $alloc->quantity <= 0) {
            $issues[] = "sale allocation {$alloc->id} quantity is not positive";
        }
        $lot = $this->lot($alloc->stock_lot_id, $lock);
        if (!$lot) {
            return array_merge($issues, ["sale allocation {$alloc->id} missing lot"]);
        }
        if ((int) $lot->book_id !== (int) $item->book_id) {
            $issues[] = "sale allocation {$alloc->id} book/lot mismatch";
        }
        if ((int) $lot->branch_id !== (int) $invoice->branch_id) {
            $issues[] = "sale allocation {$alloc->id} lot branch mismatch";
        }
        if ((string) $alloc->currency !== (string) $invoice->currency || (string) $lot->currency !== (string) $invoice->currency) {
            $issues[] = "sale allocation {$alloc->id} currency mismatch";
        }
        $ownership = (string) $lot->ownership_type;
        if ($ownership === 'consignment') {
            if ($lot->supplier_id === null) {
                $issues[] = "sale allocation {$alloc->id} consignment lot missing supplier";
            }
            if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
                $issues[] = "sale allocation {$alloc->id} unstamped consignment payable";
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function giftAllocIssues(Gift $gift, GiftLotAllocation $alloc, bool $lock): array
    {
        $issues = [];
        if ((int) $alloc->quantity <= 0) {
            $issues[] = "gift allocation {$alloc->id} quantity is not positive";
        }
        $lot = $this->lot($alloc->stock_lot_id, $lock);
        if (!$lot) {
            return array_merge($issues, ["gift allocation {$alloc->id} missing lot"]);
        }
        if ((int) $lot->book_id !== (int) $gift->book_id) {
            $issues[] = "gift allocation {$alloc->id} book mismatch";
        }
        if ((int) $lot->branch_id !== (int) $gift->branch_id) {
            $issues[] = "gift allocation {$alloc->id} branch mismatch";
        }
        if ((string) $alloc->currency !== (string) $gift->currency || (string) $lot->currency !== (string) $gift->currency) {
            $issues[] = "gift allocation {$alloc->id} currency mismatch";
        }
        $ownership = (string) ($alloc->ownership_type ?: $lot->ownership_type);
        if (!$gift->is_consignment && $ownership === 'consignment') {
            $issues[] = "gift allocation {$alloc->id} ownership mismatch";
        }
        if ($ownership === 'consignment') {
            $supplier = $alloc->supplier_id ?: $lot->supplier_id;
            if ($gift->supplier_id && (int) $supplier !== (int) $gift->supplier_id) {
                $issues[] = "gift allocation {$alloc->id} supplier mismatch";
            }
            if ($alloc->publisher_payable === null || $alloc->payable_basis === null) {
                $issues[] = "gift allocation {$alloc->id} unstamped consignment payable";
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function returnAllocIssues(
        CustomerReturn $return,
        Invoice $invoice,
        CustomerReturnItem $item,
        CustomerReturnLotAllocation $alloc,
        bool $lock
    ): array {
        $issues = [];
        if ((int) $alloc->quantity <= 0) {
            $issues[] = "return allocation {$alloc->id} quantity is not positive";
        }
        if ((int) $alloc->customer_return_id !== (int) $return->id) {
            $issues[] = "return allocation {$alloc->id} parent mismatch";
        }
        if ((string) $alloc->currency !== (string) $invoice->currency) {
            $issues[] = "return allocation {$alloc->id} currency mismatch";
        }
        $sale = SaleLotAllocation::query()->whereKey($alloc->sale_lot_allocation_id);
        if ($lock) {
            $sale->lockForUpdate();
        }
        $sale = $sale->first();
        if (!$sale) {
            return array_merge($issues, ["return allocation {$alloc->id} missing sale allocation"]);
        }
        if ((int) $sale->invoice_item_id !== (int) $item->invoice_item_id) {
            $issues[] = "return allocation {$alloc->id} invoice item mismatch";
        }
        $invoiceItem = InvoiceItem::query()->whereKey($item->invoice_item_id)->first();
        if ($invoiceItem && (int) $invoiceItem->invoice_id !== (int) $invoice->id) {
            $issues[] = "return allocation {$alloc->id} invoice mismatch";
        }
        if ($invoiceItem && (int) $item->book_id !== (int) $invoiceItem->book_id) {
            $issues[] = "return allocation {$alloc->id} book mismatch";
        }
        $lot = $this->lot($alloc->stock_lot_id, $lock);
        if ($lot && (int) $lot->branch_id !== (int) $return->branch_id) {
            $issues[] = "return allocation {$alloc->id} branch mismatch";
        }
        if ($lot && (int) $lot->id !== (int) $sale->stock_lot_id) {
            $issues[] = "return allocation {$alloc->id} lot provenance mismatch";
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function settlementSourceIssues(Settlement $settlement, SettlementAllocation $alloc, bool $lock): array
    {
        if ($alloc->sale_lot_allocation_id) {
            $sale = SaleLotAllocation::query()->whereKey($alloc->sale_lot_allocation_id);
            if ($lock) {
                $sale->lockForUpdate();
            }
            $sale = $sale->first();
            if (!$sale) {
                return ["settlement allocation {$alloc->id} missing sale allocation"];
            }
            $lot = $this->lot($sale->stock_lot_id, $lock);
            if (!$lot) {
                return ["settlement allocation {$alloc->id} missing lot"];
            }
            if ($lot->ownership_type === 'consignment' && ($sale->publisher_payable === null || $sale->payable_basis === null)) {
                return ["settlement allocation {$alloc->id} unstamped payable source"];
            }
            if ($lot->supplier_id && (int) $lot->supplier_id !== (int) $settlement->supplier_id) {
                return ["settlement allocation {$alloc->id} supplier mismatch"];
            }
            $item = InvoiceItem::query()->whereKey($sale->invoice_item_id)->first();
            $invoice = $item ? Invoice::query()->whereKey($item->invoice_id)->first() : null;
            if ($invoice && (string) $invoice->currency !== (string) $settlement->currency) {
                return ["settlement allocation {$alloc->id} source currency mismatch"];
            }
            if ($invoice && $settlement->branch_id && (int) $invoice->branch_id !== (int) $settlement->branch_id) {
                return ["settlement allocation {$alloc->id} branch mismatch"];
            }
            $when = $invoice?->sold_at ?? $invoice?->created_at;
            if ($when && !$this->inPeriod($settlement, $when)) {
                return ["settlement allocation {$alloc->id} outside settlement period"];
            }

            return [];
        }
        if ($alloc->gift_lot_allocation_id) {
            $giftAlloc = GiftLotAllocation::query()->whereKey($alloc->gift_lot_allocation_id);
            if ($lock) {
                $giftAlloc->lockForUpdate();
            }
            $giftAlloc = $giftAlloc->first();
            if (!$giftAlloc) {
                return ["settlement allocation {$alloc->id} missing gift allocation"];
            }
            $gift = Gift::query()->whereKey($giftAlloc->gift_id)->first();
            if ($giftAlloc->publisher_payable === null || $giftAlloc->payable_basis === null) {
                return ["settlement allocation {$alloc->id} unstamped gift payable"];
            }
            if ($gift && (int) ($gift->supplier_id ?: $giftAlloc->supplier_id) !== (int) $settlement->supplier_id) {
                return ["settlement allocation {$alloc->id} gift supplier mismatch"];
            }
            if ($gift && (string) $gift->currency !== (string) $settlement->currency) {
                return ["settlement allocation {$alloc->id} gift currency mismatch"];
            }
            if ($gift && $settlement->branch_id && (int) $gift->branch_id !== (int) $settlement->branch_id) {
                return ["settlement allocation {$alloc->id} gift branch mismatch"];
            }
            if ($gift?->gifted_at && !$this->inPeriod($settlement, $gift->gifted_at)) {
                return ["settlement allocation {$alloc->id} gift outside settlement period"];
            }

            return [];
        }

        return ["settlement allocation {$alloc->id} is not a stamped payable source"];
    }

    private function inPeriod(Settlement $settlement, mixed $when): bool
    {
        if (!$settlement->period_start || !$settlement->period_end || !$when) {
            return true;
        }
        $day = \Carbon\Carbon::parse($when)->toDateString();

        return $day >= $settlement->period_start->toDateString()
            && $day <= $settlement->period_end->toDateString();
    }

    private function lot(mixed $id, bool $lock): ?StockLot
    {
        $q = StockLot::query()->whereKey($id);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    /** @param list<string> $issues */
    private function throwFirst(array $issues): void
    {
        if ($issues !== []) {
            throw new DomainException($issues[0]);
        }
    }
}
