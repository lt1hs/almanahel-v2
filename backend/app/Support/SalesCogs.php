<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class SalesCogs
{
    /**
     * Cost of goods sold for a branch/currency in a date range (inclusive dates).
     * Owned stock: qty × inventory cost.
     * Consignment: qty × cost × (1 − commission) — publisher share owed.
     */
    public static function forBranch(
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

        $keepRate = 1 - ConsignmentFinance::commissionRate();
        $cogs = 0.0;

        foreach ($rows as $row) {
            $line = (float) $row->unit_cost * (int) $row->quantity;
            if (($row->inventory_type ?? '') === 'consignment') {
                $line *= $keepRate;
            }
            $cogs += $line;
        }

        return round($cogs, 2);
    }
}
