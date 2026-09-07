<?php

use App\Support\BranchSalesShareWindowSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('branch_sales_share_rules')) {
            return;
        }

        BranchSalesShareWindowSchema::convertTimestampColumnsToDatetime();
        app(\App\Services\BranchShare\BranchSalesShareService::class)->repairWindows();
        BranchSalesShareWindowSchema::ensureValidWindowCheck();
    }

    public function down(): void
    {
        BranchSalesShareWindowSchema::dropValidWindowCheck();
    }
};
