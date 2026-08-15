<?php

namespace App\Support;

use App\Models\ConsignmentReceiptItem;
use App\Models\Inventory;

class ConsignmentSync
{
    public static function incrementSold(Inventory $inventory, int $bookId, int $quantity): void
    {
        if ($inventory->type !== 'consignment' || !$inventory->supplier_id) {
            return;
        }

        $remaining = $quantity;
        $items = self::openReceiptItems($inventory, $bookId)->orderBy('id')->get();

        foreach ($items as $receiptItem) {
            if ($remaining <= 0) {
                break;
            }
            $unsold = $receiptItem->quantity_received - $receiptItem->quantity_sold - $receiptItem->quantity_returned;
            if ($unsold <= 0) {
                continue;
            }
            $sold = min($unsold, $remaining);
            $receiptItem->increment('quantity_sold', $sold);
            $remaining -= $sold;
        }
    }

    public static function decrementSold(Inventory $inventory, int $bookId, int $quantity): void
    {
        if ($inventory->type !== 'consignment' || !$inventory->supplier_id) {
            return;
        }

        $remaining = $quantity;
        $items = self::openReceiptItems($inventory, $bookId)->orderByDesc('id')->get();

        foreach ($items as $receiptItem) {
            if ($remaining <= 0) {
                break;
            }
            if ($receiptItem->quantity_sold <= 0) {
                continue;
            }
            $restore = min($receiptItem->quantity_sold, $remaining);
            $receiptItem->decrement('quantity_sold', $restore);
            $remaining -= $restore;
        }
    }

    private static function openReceiptItems(Inventory $inventory, int $bookId)
    {
        return ConsignmentReceiptItem::whereHas('consignmentReceipt', function ($q) use ($inventory) {
            $q->where('supplier_id', $inventory->supplier_id)
              ->where('branch_id', $inventory->branch_id)
              ->whereIn('status', ['unsettled', 'partially_settled', 'settled']);
        })->where('book_id', $bookId);
    }
}
