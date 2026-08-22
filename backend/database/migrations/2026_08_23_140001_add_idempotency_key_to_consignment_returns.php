<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('consignment_returns', 'idempotency_key')) {
                $table->string('idempotency_key', 80)->nullable()->after('reason');
                $table->unique(
                    ['branch_id', 'supplier_account_id', 'idempotency_key'],
                    'consignment_returns_idempotency_unique'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('consignment_returns', function (Blueprint $table) {
            if (Schema::hasColumn('consignment_returns', 'idempotency_key')) {
                $table->dropUnique('consignment_returns_idempotency_unique');
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
