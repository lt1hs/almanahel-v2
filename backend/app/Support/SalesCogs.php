<?php

namespace App\Support;

use App\Models\SaleLotAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesCogs
{
    /**
     * Immutable COGS from sale_lot_allocations when available; falls back to inventory costs.
     * Consignment store COGS = unit_cost × qty × commission rate (store keep).
     * Publisher share is payable, not store COGS — for P&L store consignment COGS uses commission portion
     * OR full cost depending on business: plan says COGS based on sale lot allocations.
     * Assumption: store COGS for consignment = publisher share is NOT COGS; store COGS = commission×cost
     * so net matches revenue − publisher_payable − expenses. Actually existing formula used
     * qty × cost × (1 − commission) as COGS (publisher share as cost). Keep that for compatibility
     * using allocated unit_cost × qty × (1−rate) for consignment lots.
     */
    public static function forBranch(
        int $branchId,
        string $currency,
        string $dateFrom,
        string $dateTo
    ): float {
        if (Schema::hasTable('sale_lot_allocations')) {
            return self::fromAllocations($branchId, $currency, $dateFrom, $dateTo);
        }

        return self::legacyFromInventory($branchId, $currency, $dateFrom, $dateTo);
    }

    private static function fromAllocations(
        int $branchId,
        string $currency,
        string $dateFrom,
        string $dateTo
    ): float {
        $rows = SaleLotAllocation::query()
            ->join('invoice_items', 'sale_lot_allocations.invoice_item_id', '=', 'invoice_items.id')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('stock_lots', 'sale_lot_allocations.stock_lot_id', '=', 'stock_lots.id')
            ->where('invoices.branch_id', $branchId)
            ->where('sale_lot_allocations.currency', $currency)
            ->where(function ($q) {
                $q->whereNull('invoices.type')->orWhere('invoices.type', 'sale');
            })
            ->whereBetween(DB::raw('DATE(invoices.created_at)'), [$dateFrom, $dateTo])
            ->select(
                'sale_lot_allocations.quantity',
                'sale_lot_allocations.quantity_returned',
                'sale_lot_allocations.unit_cost',
                'stock_lots.ownership_type'
            )
            ->get();

        $cogs = '0.00';
        foreach ($rows as $row) {
            $qty = max(0, (int) $row->quantity - (int) $row->quantity_returned);
            $line = Money::mul($row->unit_cost, $qty);
            if (($row->ownership_type ?? '') === 'consignment') {
                $line = ConsignmentFinance::publisherShare($line);
            }
            $cogs = Money::add($cogs, $line);
        }

        return (float) $cogs;
    }

    private static function legacyFromInventory(
        int $branchId,
        string $currency,
        string $dateFrom,
        string $dateTo
    ): float {
        $costCol = $currency === 'dinar'
            ? 'inventories.cost_price_dinar'
            : 'inventories.cost_price_toman';

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->leftJoin('inventories', function ($join) {
                $join->on('inventories.book_id', '=', 'invoice_items.book_id')
                    ->on('inventories.branch_id', '=', 'invoices.branch_id');
            })
            ->where('invoices.branch_id', $branchId)
            ->where('invoices.currency', $currency)
            ->where(function ($q) {
                $q->whereNull('invoices.type')->orWhere('invoices.type', 'sale');
            })
            ->whereBetween(DB::raw('DATE(invoices.created_at)'), [$dateFrom, $dateTo])
            ->select(
                'invoice_items.quantity',
                'inventories.type as inventory_type',
                DB::raw("COALESCE({$costCol}, 0) as unit_cost")
            )
            ->get();

        $cogs = '0.00';
        foreach ($rows as $row) {
            $line = Money::mul($row->unit_cost, (int) $row->quantity);
            if (($row->inventory_type ?? '') === 'consignment') {
                $line = ConsignmentFinance::publisherShare($line);
            }
            $cogs = Money::add($cogs, $line);
        }

        return (float) $cogs;
    }

    public static function returnsAmount(int $branchId, string $currency, string $dateFrom, string $dateTo): float
    {
        return round((float) DB::table('customer_returns')
            ->where('branch_id', $branchId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->whereExists(function ($q) use ($currency) {
                $q->select(DB::raw(1))
                    ->from('invoices')
                    ->whereColumn('invoices.id', 'customer_returns.invoice_id')
                    ->where('invoices.currency', $currency);
            })
            ->sum('refund_amount'), 2);
    }
}
