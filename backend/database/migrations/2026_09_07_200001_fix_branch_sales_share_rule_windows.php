<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('branch_sales_share_rules')) {
            return;
        }

        DB::table('branch_sales_share_rules')
            ->whereNotNull('effective_to')
            ->whereColumn('effective_to', '<=', 'effective_from')
            ->update(['effective_to' => null]);

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $exists = collect(DB::select(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'branch_sales_share_rules'
               AND CONSTRAINT_NAME = 'bssr_valid_window'"
        ))->isNotEmpty();

        if (!$exists) {
            DB::statement(
                'ALTER TABLE branch_sales_share_rules
                 ADD CONSTRAINT bssr_valid_window
                 CHECK (effective_to IS NULL OR effective_to > effective_from)'
            );
        }
    }

    public function down(): void
    {
        if (
            !Schema::hasTable('branch_sales_share_rules')
            || Schema::getConnection()->getDriverName() !== 'mysql'
        ) {
            return;
        }

        DB::statement('ALTER TABLE branch_sales_share_rules DROP CHECK bssr_valid_window');
    }
};
