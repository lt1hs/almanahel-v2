<?php

use App\Support\BranchSalesShareWindowSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        BranchSalesShareWindowSchema::convertTimestampColumnsToDatetime();

        if (Schema::hasTable('branch_sales_share_rules')) {
            app(\App\Services\BranchShare\BranchSalesShareService::class)->repairWindows();
            BranchSalesShareWindowSchema::ensureValidWindowCheck();
        }

        $this->backfillPayableUnitCosts();
    }

    public function down(): void
    {
        // Window conversion, repair, and payable backfill are data/schema fixes; do not reverse.
    }

    private function backfillPayableUnitCosts(): void
    {
        if (!Schema::hasTable('stock_lots') || !Schema::hasColumn('stock_lots', 'payable_unit_cost')) {
            return;
        }

        DB::table('stock_lots')
            ->where('ownership_type', 'consignment')
            ->whereNull('payable_unit_cost')
            ->whereNotNull('unit_cost')
            ->update(['payable_unit_cost' => DB::raw('unit_cost')]);

        if (!Schema::hasTable('consignment_receipt_items')) {
            return;
        }

        $lots = DB::table('stock_lots')
            ->where('ownership_type', 'consignment')
            ->whereNull('payable_unit_cost')
            ->whereNotNull('consignment_receipt_item_id')
            ->get(['id', 'consignment_receipt_item_id']);

        foreach ($lots as $lot) {
            $cost = DB::table('consignment_receipt_items')
                ->where('id', $lot->consignment_receipt_item_id)
                ->value('cost_price');
            if ($cost === null || $cost === '') {
                continue;
            }
            DB::table('stock_lots')->where('id', $lot->id)->update(['payable_unit_cost' => $cost]);
        }
    }
};
